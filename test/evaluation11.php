<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '19-24';

if ($child_id == 0) {
    header("Location: children_list.php");
    exit();
}

// ดึงข้อมูลเด็ก - ตรวจสอบสิทธิ์ตาม role
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // Admin และ Staff สามารถประเมินได้ทุกคน
    $sql = "SELECT * FROM children WHERE chi_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
} else {
    // User ปกติประเมินได้เฉพาะเด็กของตัวเอง
    $sql = "SELECT * FROM children WHERE chi_id = ? AND chi_user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $child_id, $user['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการ";
    header("Location: children_list.php");
    exit();
}

// ตรวจสอบว่ามีการส่งข้อมูลการประเมินมาหรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $evaluation_data = array();
    $total_passed = 0;
    $total_failed = 0;
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 40-44)
    for ($i = 60; $i <= 64; $i++) {
        $passed = isset($_POST["q{$i}_pass"]) ? 1 : 0;
        $failed = isset($_POST["q{$i}_fail"]) ? 1 : 0;
        
        $evaluation_data["question_{$i}"] = array(
            'passed' => $passed,
            'failed' => $failed
        );
        
        if ($passed) $total_passed++;
        if ($failed) $total_failed++;
    }
    
    $evaluation_date = date('Y-m-d');
    $evaluation_time = date('Y-m-d H:i:s');
    $evaluation_json = json_encode($evaluation_data);
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // หาเวอร์ชันล่าสุดสำหรับการประเมินนี้
    $version_sql = "SELECT MAX(eva_version) as max_version FROM evaluations WHERE eva_child_id = ? AND eva_age_range = ? AND eva_evaluation_date = ?";
    $version_stmt = $conn->prepare($version_sql);
    $version_stmt->bind_param("iss", $child_id, $age_range, $evaluation_date);
    $version_stmt->execute();
    $version_result = $version_stmt->get_result()->fetch_assoc();
    $next_version = ($version_result['max_version'] ?? 0) + 1;
    $version_stmt->close();
    
    // เพิ่มข้อมูลใหม่เสมอ (ไม่แทนที่)
    $insert_sql = "INSERT INTO evaluations (eva_child_id, eva_user_id, eva_age_range, eva_responses, eva_total_score, eva_total_questions, eva_evaluation_date, eva_evaluation_time, eva_version, eva_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 60-64)
    $stmt->bind_param("iisssissis", $child_id, $user['user_id'], $age_range, $evaluation_json, $total_passed, $total_questions, $evaluation_date, $evaluation_time, $next_version, $notes);
    
    if ($stmt->execute()) {
        $evaluation_id = $conn->insert_id;
        if ($next_version > 1) {
            $_SESSION['success'] = "บันทึกผลการประเมินครั้งที่ {$next_version} เรียบร้อยแล้ว (อัพเดทจากครั้งก่อน)";
        } else {
            $_SESSION['success'] = "บันทึกผลการประเมินเรียบร้อยแล้ว";
        }
        $stmt->close();
        $conn->close();
        header("Location: child_detail.php?id={$child_id}");
        exit();
    } else {
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
    }
    $stmt->close();
}

// ดึงการประเมินล่าสุดถ้ามี (สำหรับแสดงข้อมูลเดิม)
$latest_evaluation = null;
$latest_sql = "SELECT * FROM evaluations WHERE eva_child_id = ? AND eva_age_range = ? ORDER BY eva_evaluation_date DESC, eva_version DESC LIMIT 1";
$latest_stmt = $conn->prepare($latest_sql);
$latest_stmt->bind_param("is", $child_id, $age_range);
$latest_stmt->execute();
$latest_result = $latest_stmt->get_result();
$latest_evaluation = $latest_result->fetch_assoc();
$latest_stmt->close();

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>แบบประเมิน ช่วงอายุ 19 ถึง 24 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/eva.css">
  <link rel="stylesheet" href="../css/test.css">
</head>
<body class="bg-light">
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
      <a class="navbar-brand" href="mainpage.php">DSPM System</a>
      <div class="navbar-nav ms-auto">
        <span class="navbar-text me-3">
          กำลังประเมิน: <?php echo htmlspecialchars($child['chi_child_name']); ?>
        </span>
        <a class="btn btn-outline-light btn-sm" href="child_detail.php?id=<?php echo $child['chi_id']; ?>">กลับ</a>
      </div>
    </div>
  </nav>

  <div class="container py-5">
    <h1 class="text-center mb-4">คู่มือเฝ้าระวังและส่งเสริมพัฒนาการเด็กปฐมวัย</h1>
    <h2 class="text-center mb-4" style="color: #149ee9;">
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 19 - 24 เดือน
    </h2>

    <!-- แสดงข้อมูลการประเมินก่อนหน้า -->
    <?php if ($latest_evaluation): ?>
      <div class="alert alert-info">
        <h5><i class="fas fa-history"></i> การประเมินครั้งล่าสุด</h5>
        <div class="row">
          <div class="col-md-6">
            <strong>วันที่:</strong> <?php echo date('d/m/Y', strtotime($latest_evaluation['eva_evaluation_date'])); ?><br>
            <strong>เวลา:</strong> <?php 
            $eval_datetime = $latest_evaluation['eva_evaluation_time'];
            if (strtotime($eval_datetime)) {
                echo date('H:i', strtotime($eval_datetime));
            } else {
                echo "ไม่ระบุเวลา";
            }
            ?> น.
          </div>
          <div class="col-md-6">
            <strong>ผลการประเมิน:</strong> 
            <span class="badge bg-success"><?php echo $latest_evaluation['eva_total_score']; ?> ผ่าน</span>
            <span class="badge bg-danger"><?php echo ($latest_evaluation['eva_total_questions'] - $latest_evaluation['eva_total_score']); ?> ไม่ผ่าน</span><br>
            <strong>ครั้งที่:</strong> <?php echo $latest_evaluation['eva_version']; ?>
          </div>
        </div>
        <?php if ($latest_evaluation['eva_notes']): ?>
          <div class="mt-2">
            <strong>หมายเหตุ:</strong> <?php echo htmlspecialchars($latest_evaluation['eva_notes']); ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- แสดงข้อความแจ้งเตือน -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="POST" action="">
      <!-- Desktop version -->
      <div class="table-responsive d-none d-md-block">
        <table class="table table-bordered table-striped align-middle">
          <thead class="table-color">
            <tr>
              <th><strong>อายุ</strong><br>(เดือน)</th>
              <th><strong>ข้อที่</strong></th>
              <th><strong>ทักษะ</strong></th>
              <th><strong>วิธีประเมิน เฝ้าระวัง</strong><br>โดย พ่อแม่ ผู้ปกครอง เจ้าหน้าที่ ครู และผู้ดูแลเด็ก</th>
              <th><strong>วิธีฝึกทักษะ</strong><br>โดย พ่อแม่ ผู้ปกครอง ครู และผู้ดูแลเด็ก</th>
            </tr>
          </thead>
          <tbody>
            <!-- ข้อ 60-64 สำหรับ 19-24 เดือน -->
            <tr>
              <td rowspan="5">19 - 24 เดือน</td>
              <td>60<br>
                  <input type="checkbox" id="q60_pass" name="q60_pass" value="1">
                  <label for="q60_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q60_fail" name="q60_fail" value="1">
                  <label for="q60_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เหวี่ยงขาเตะลูกบอลได้ (GM)<br><br>
                <strong>อุปกรณ์:</strong>  ลูกบอลเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร<br>
                <img src="../image/evaluation_pic/ball_15_20.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
              <td>
                1. เตะลูกบอลให้เด็กดู<br>
                2. วางลูกบอลไว้ตรงหน้าห่างจากเด็กประมาณ 15 ซม. และบอกให้เด็กเตะลูกบอล<br>
                <strong>ผ่าน:</strong>  เด็กสามารถยกขาเตะลูกบอลได้(ไม่ใช่เขี่ยบอล) โดยไม่เสียการทรงตัวและทำได้อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
               1. ชวนเด็กเล่นเตะลูกบอล โดยเตะให้เด็กดู <br>
               2. ชวนให้เด็กทำตามโดยช่วยจับมือเด็กไว้ข้างหนึ่ง บอกให้เด็กยกขาเหวี่ยงเตะลูกบอล <br>
               3. เมื่อเด็กทรงตัวได้ดี กระตุ้นให้เด็กเตะลูกบอลเอง <br>
               4. ฝึกบ่อย ๆ จนเด็กสามารถทำได้เอง <br>
               <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> วัสดุมาทำเป็นก้อนกลม ๆ เช่น ก้อนฟางลูกบอลสานด้วยใบมะพร้าว </span>
              </td>
            </tr>

            <tr>
              <td>61<br>
                  <input type="checkbox" id="q61_pass" name="q61_pass" value="1">
                  <label for="q61_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q61_fail" name="q61_fail" value="1">
                  <label for="q61_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ต่อก้อนไม้ 4 ชั้น (FM)<br><br>
                <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 8 ก้อน<br>
                <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 8 ก้อน.png" alt="Family" style="width: 120px; height: 150px;"><br></td>
              <td>
                1. วางก้อนไม้ 8 ก้อนไว้บนโต๊ะ<br>
                2. ต่อก้อนไม้เป็นหอสูง 4 ชั้น ให้เด็กดูแล้วรื้อแบบออก<br>
                3. ยื่นก้อนไม้ให้เด็ก 4 ก้อน และกระตุ้นให้เด็กต่อก้อนไม้เอง ให้โอกาสประเมิน 3 ครั้ง <br>
                <strong>ผ่าน:</strong>  เด็กสามารถต่อก้อนไม้เป็นหอสูงจำนวน 4 ก้อน โดยไม่ล้ม ได้อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ใช้วัตถุที่เป็นทรงสี่เหลี่ยมเช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู <br>
                2. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก กระตุ้นให้เด็กทำตาม <br>
                3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1 วางไปเรื่อย ๆ จนครบ 4 ชั้น <br>
                4. ให้เด็กทำซ้ำหลายครั้งและพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กลดการช่วยเหลือลง จนเด็กต่อก้อนไม้ได้เอง หากเด็กทำได้แล้วให้ชมเชย เพิ่มความภาคภูมิใจในตนเอง <br>
                5. หากเด็กต่อได้ 4 ชั้น แล้วให้เปลี่ยนเป็นต่อมากกว่า 4 ชั้น
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span>
              </td>
            </tr>

            <tr>
              <td>62<br>
                  <input type="checkbox" id="q62_pass" name="q62_pass" value="1">
                  <label for="q62_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q62_fail" name="q62_fail" value="1">
                  <label for="q62_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกวัตถุตามคำสั่ง (ตัวเลือก4 ชนิด) (RL)<br><br>
              <strong>อุปกรณ์:</strong> ของเล่นเด็ก ตุ๊กตาผ้า บอล รถ ถ้วย<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="Family" style="width: 120px; height: 90px;"><br>
              <strong>หมายเหตุ:</strong> ในกรณีที่มีข้อขัดข้องทางสังคมและวัฒนธรรมให้ใช้หนังสือที่เป็นชุดอุปกรณ์DSPM แทนได้
            </td>
              <td>
                1. วางของเล่นทั้ง 4 ชิ้นไว้ตรงหน้าเด็กในระยะที่เด็กหยิบถึง<br>
                2. ถามเด็กทีละชนิดว่า “อันไหนตุ๊กตา”“อันไหนบอล” “อันไหนรถ” “อันไหนถ้วย” ถ้าเด็กหยิบของเล่นออกมาให้ผู้ประเมินนำของเล่นกลับไปวางที่เดิม
                แล้วจึงถามชนิดต่อไป จนครบ 4 ชนิด<br>
                <strong>ผ่าน:</strong> เด็กสามารถหยิบ/ชี้ของเล่นได้ถูกต้องทั้ง 4 ชนิด
              </td>
              <td>
                1. วางของเล่นที่เด็กคุ้นเคย 2 ชิ้น กระตุ้นให้เด็กมอง แล้วบอกชื่อของเล่นทีละชิ้น <br>
                2. บอกให้เด็กหยิบของเล่นทีละชิ้น ถ้าเด็กหยิบไม่ถูกให้จับมือเด็กหยิบพร้อมกับพูดชื่อของเล่นนั้นซ้ำ<br>
                3. ฝึกจนเด็กสามารถทำตามคำสั่งได้ถูกต้องและเพิ่มของเล่นทีละชิ้นจนครบทั้ง 4 ชิ้น<br>
                4. เมื่อทำได้แล้วให้ฝึกกับวัตถุหลากหลายมากขึ้น เช่น เครื่องดนตรีหนังสือนิทาน<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
              </td>
            </tr>

            <tr>
              <td>63<br>
                  <input type="checkbox" id="q63_pass" name="q63_pass" value="1">
                  <label for="q63_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q63_fail" name="q63_fail" value="1">
                  <label for="q63_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนคำพูดที่เป็นวลีประกอบด้วยคำ 2 คำขึ้นไป (EL)<br><br>
              </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถพูด 2 คำขึ้นไป (ไม่ใช่2 พยางค์) ต่อกันได้หรือไม่ หรือขณะเล่น
                กับเด็ก พยายามให้เด็กเลียนคำพูดที่เป็นวลี 2 คำขึ้นไป เช่น อาบน้ำ ร้องเพลงอ่านหนังสือ เล่านิทาน<br>
                <strong>ผ่าน:</strong> เด็กเลียนคำพูดที่เป็นวลี 2 คำขึ้นไปได้เอง เช่น อาบน้ำ ร้องเพลงอ่านหนังสือ เล่านิทาน
              </td>
              <td>
                1. พูดคำ 2 คำ ให้เด็กฟังบ่อย ๆ และให้เด็กพูดตาม ถ้าเด็กพูดได้ทีละคำ ให้พูดขยายคำพูดเด็กเป็น 2 คำ เช่น เด็กพูด “ไป” พ่อแม่
                ผู้ปกครองหรือผู้ดูแลเด็กพูดว่า “ไปนอน” “อ่านหนังสือ”<br>
                2. ร้องเพลงเด็กที่ใช้คำพูดง่าย ๆ ให้เด็กฟังบ่อย ๆ พร้อมทำท่าทางตามเพลง เว้นวรรคให้เด็กร้องต่อ เช่น “จับ... (ปูดำ) ขยำ… (ปูนา) ”<br>
                3. พูดโต้ตอบกับเด็กบ่อย ๆ ในสิ่งที่เด็กสนใจหรือกำลังกระทำอยู่วิธีพูดให้พูดชัด ๆ ช้า ๆ มีจังหวะหยุดเพื่อให้เด็กพูดตามในระหว่าง
                ชีวิตประจำวัน เช่น ระหว่างอาบน้ำ ระหว่างทานข้าว การดูรูปภาพประกอบ อ่านหนังสือร่วมกัน
              </td>
            </tr>

            <tr>
              <td>64<br>
                  <input type="checkbox" id="q64_pass" name="q64_pass" value="1">
                  <label for="q64_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q64_fail" name="q64_fail" value="1">
                  <label for="q64_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใช้ช้อนตักอาหารกินเองได้(PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก“เด็กสามารถใช้ช้อนตักอาหารกินเองได้หรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กใช้ช้อนตักกินอาหารได้ โดยอาจหกได้เล็กน้อย 
                (ในกรณีที่เด็กรับประทานข้าวเหนียวเป็นหลัก ให้เด็กทดสอบการใช้ช้อนตักอาหาร)
              </td>
              <td>
               เริ่มจากล้างมือเด็กให้สะอาด จับมือเด็กถือช้อนและตักอาหารใส่ปากเด็ก ควรฝึกอย่างสม่ำเสมอ
               ในระหว่างการรับประทานอาหาร ค่อย ๆลดการช่วยเหลือลงจนเด็กสามารถตักอาหารใส่ปากได้เอง
              </td>
            </tr>
          </tbody>
        </table>

        <div class="d-flex justify-content-center mt-4">
          <button type="button" class="btn btn-primary btn-lg px-5 rounded-pill" data-bs-toggle="modal" data-bs-target="#confirmModal">
            ยืนยันแบบประเมิน
          </button>
        </div>
      </div>

      <!-- ช่องหมายเหตุ -->
      <div class="card mt-4">
        <div class="card-header bg-light">
          <h5 class="mb-0"><i class="fas fa-sticky-note"></i> หมายเหตุ (ไม่บังคับ)</h5>
        </div>
        <div class="card-body">
          <textarea class="form-control" name="notes" rows="3" placeholder="เพิ่มหมายเหตุสำหรับการประเมินครั้งนี้ เช่น พฤติกรรมที่สังเกต สภาพแวดล้อม หรือข้อสังเกตอื่นๆ"></textarea>
        </div>
      </div>

      <!-- Mobile version -->
      <div class="d-block d-md-none">
        <!-- Card ข้อที่ 60 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 60 - เหวี่ยงขาเตะลูกบอลได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 19 - 24 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q60_pass_mobile" name="q60_pass" value="1">
                <label class="form-check-label text-success" for="q60_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q60_fail_mobile" name="q60_fail" value="1">
                <label class="form-check-label text-danger" for="q60_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ลูกบอลเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. เตะลูกบอลให้เด็กดู<br>
              2. วางลูกบอลไว้ตรงหน้าห่างจากเด็กประมาณ 15 ซม. และบอกให้เด็กเตะลูกบอล</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยกขาเตะลูกบอลได้(ไม่ใช่เขี่ยบอล) โดยไม่เสียการทรงตัวและทำได้อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training60">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading60">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse60">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse60" class="accordion-collapse collapse" data-bs-parent="#training60">
                  <div class="accordion-body">
                    1. ชวนเด็กเล่นเตะลูกบอล โดยเตะให้เด็กดู<br>
                    2. ชวนให้เด็กทำตามโดยช่วยจับมือเด็กไว้ข้างหนึ่ง บอกให้เด็กยกขาเหวี่ยงเตะลูกบอล<br>
                    3. เมื่อเด็กทรงตัวได้ดี กระตุ้นให้เด็กเตะลูกบอลเอง<br>
                    4. ฝึกบ่อย ๆ จนเด็กสามารถทำได้เอง<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> วัสดุมาทำเป็นก้อนกลม ๆ เช่น ก้อนฟางลูกบอลสานด้วยใบมะพร้าว</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 61 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 61 - ต่อก้อนไม้ 4 ชั้น (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 19 - 24 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q61_pass_mobile" name="q61_pass" value="1">
                <label class="form-check-label text-success" for="q61_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q61_fail_mobile" name="q61_fail" value="1">
                <label class="form-check-label text-danger" for="q61_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ก้อนไม้สี่เหลี่ยมลูกบาศก์ 8 ก้อน</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางก้อนไม้ 8 ก้อนไว้บนโต๊ะ<br>
              2. ต่อก้อนไม้เป็นหอสูง 4 ชั้น ให้เด็กดูแล้วรื้อแบบออก<br>
              3. ยื่นก้อนไม้ให้เด็ก 4 ก้อน และกระตุ้นให้เด็กต่อก้อนไม้เอง ให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถต่อก้อนไม้เป็นหอสูงจำนวน 4 ก้อน โดยไม่ล้ม ได้อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training61">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading61">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse61">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse61" class="accordion-collapse collapse" data-bs-parent="#training61">
                  <div class="accordion-body">
                    1. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ใช้วัตถุที่เป็นทรงสี่เหลี่ยมเช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู<br>
                    2. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก กระตุ้นให้เด็กทำตาม<br>
                    3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1 วางไปเรื่อย ๆ จนครบ 4 ชั้น<br>
                    4. ให้เด็กทำซ้ำหลายครั้งและพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กลดการช่วยเหลือลง จนเด็กต่อก้อนไม้ได้เอง หากเด็กทำได้แล้วให้ชมเชย เพิ่มความภาคภูมิใจในตนเอง<br>
                    5. หากเด็กต่อได้ 4 ชั้น แล้วให้เปลี่ยนเป็นต่อมากกว่า 4 ชั้น<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 62 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 62 - เลือกวัตถุตามคำสั่ง (ตัวเลือก4 ชนิด) (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 19 - 24 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q62_pass_mobile" name="q62_pass" value="1">
                <label class="form-check-label text-success" for="q62_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q62_fail_mobile" name="q62_fail" value="1">
                <label class="form-check-label text-danger" for="q62_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ของเล่นเด็ก ตุ๊กตาผ้า บอล รถ ถ้วย</p>
              <p><strong>หมายเหตุ:</strong> ในกรณีที่มีข้อขัดข้องทางสังคมและวัฒนธรรมให้ใช้หนังสือที่เป็นชุดอุปกรณ์DSPM แทนได้</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางของเล่นทั้ง 4 ชิ้นไว้ตรงหน้าเด็กในระยะที่เด็กหยิบถึง<br>
              2. ถามเด็กทีละชนิดว่า "อันไหนตุ๊กตา""อันไหนบอล" "อันไหนรถ" "อันไหนถ้วย" ถ้าเด็กหยิบของเล่นออกมาให้ผู้ประเมินนำของเล่นกลับไปวางที่เดิม แล้วจึงถามชนิดต่อไป จนครบ 4 ชนิด</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถหยิบ/ชี้ของเล่นได้ถูกต้องทั้ง 4 ชนิด</p>
            </div>
            <div class="accordion" id="training62">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading62">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse62">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse62" class="accordion-collapse collapse" data-bs-parent="#training62">
                  <div class="accordion-body">
                    1. วางของเล่นที่เด็กคุ้นเคย 2 ชิ้น กระตุ้นให้เด็กมอง แล้วบอกชื่อของเล่นทีละชิ้น<br>
                    2. บอกให้เด็กหยิบของเล่นทีละชิ้น ถ้าเด็กหยิบไม่ถูกให้จับมือเด็กหยิบพร้อมกับพูดชื่อของเล่นนั้นซ้ำ<br>
                    3. ฝึกจนเด็กสามารถทำตามคำสั่งได้ถูกต้องและเพิ่มของเล่นทีละชิ้นจนครบทั้ง 4 ชิ้น<br>
                    4. เมื่อทำได้แล้วให้ฝึกกับวัตถุหลากหลายมากขึ้น เช่น เครื่องดนตรีหนังสือนิทาน<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 63 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 63 - เลียนคำพูดที่เป็นวลีประกอบด้วยคำ 2 คำขึ้นไป (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 19 - 24 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q63_pass_mobile" name="q63_pass" value="1">
                <label class="form-check-label text-success" for="q63_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q63_fail_mobile" name="q63_fail" value="1">
                <label class="form-check-label text-danger" for="q63_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถพูด 2 คำขึ้นไป (ไม่ใช่2 พยางค์) ต่อกันได้หรือไม่ หรือขณะเล่นกับเด็ก พยายามให้เด็กเลียนคำพูดที่เป็นวลี 2 คำขึ้นไป เช่น อาบน้ำ ร้องเพลงอ่านหนังสือ เล่านิทาน</p>
              <p><strong>ผ่าน:</strong> เด็กเลียนคำพูดที่เป็นวลี 2 คำขึ้นไปได้เอง เช่น อาบน้ำ ร้องเพลงอ่านหนังสือ เล่านิทาน</p>
            </div>
            <div class="accordion" id="training63">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading63">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse63">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse63" class="accordion-collapse collapse" data-bs-parent="#training63">
                  <div class="accordion-body">
                    1. พูดคำ 2 คำ ให้เด็กฟังบ่อย ๆ และให้เด็กพูดตาม ถ้าเด็กพูดได้ทีละคำ ให้พูดขยายคำพูดเด็กเป็น 2 คำ เช่น เด็กพูด "ไป" พ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กพูดว่า "ไปนอน" "อ่านหนังสือ"<br>
                    2. ร้องเพลงเด็กที่ใช้คำพูดง่าย ๆ ให้เด็กฟังบ่อย ๆ พร้อมทำท่าทางตามเพลง เว้นวรรคให้เด็กร้องต่อ เช่น "จับ... (ปูดำ) ขยำ… (ปูนา) "<br>
                    3. พูดโต้ตอบกับเด็กบ่อย ๆ ในสิ่งที่เด็กสนใจหรือกำลังกระทำอยู่วิธีพูดให้พูดชัด ๆ ช้า ๆ มีจังหวะหยุดเพื่อให้เด็กพูดตามในระหว่างชีวิตประจำวัน เช่น ระหว่างอาบน้ำ ระหว่างทานข้าว การดูรูปภาพประกอบ อ่านหนังสือร่วมกัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 64 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 64 - ใช้ช้อนตักอาหารกินเองได้ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 19 - 24 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q64_pass_mobile" name="q64_pass" value="1">
                <label class="form-check-label text-success" for="q64_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q64_fail_mobile" name="q64_fail" value="1">
                <label class="form-check-label text-danger" for="q64_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก"เด็กสามารถใช้ช้อนตักอาหารกินเองได้หรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กใช้ช้อนตักกินอาหารได้ โดยอาจหกได้เล็กน้อย (ในกรณีที่เด็กรับประทานข้าวเหนียวเป็นหลัก ให้เด็กทดสอบการใช้ช้อนตักอาหาร)</p>
            </div>
            <div class="accordion" id="training64">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading64">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse64">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse64" class="accordion-collapse collapse" data-bs-parent="#training64">
                  <div class="accordion-body">
                    เริ่มจากล้างมือเด็กให้สะอาด จับมือเด็กถือช้อนและตักอาหารใส่ปากเด็ก ควรฝึกอย่างสม่ำเสมอในระหว่างการรับประทานอาหาร ค่อย ๆลดการช่วยเหลือลงจนเด็กสามารถตักอาหารใส่ปากได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ปุ่มยืนยันสำหรับ Mobile -->
        <div class="d-grid gap-2 mt-4">
          <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#confirmModal">
            ยืนยันแบบประเมิน
          </button>
        </div>

        <!-- ช่องหมายเหตุสำหรับ Mobile -->
        <div class="card mt-3">
          <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-sticky-note"></i> หมายเหตุ (ไม่บังคับ)</h6>
          </div>
          <div class="card-body">
            <textarea class="form-control" name="notes" rows="3" placeholder="เพิ่มหมายเหตุสำหรับการประเมินครั้งนี้"></textarea>
          </div>
        </div>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title" id="confirmModalLabel">ยืนยันการส่งแบบประเมิน</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p>คุณแน่ใจหรือไม่ว่าต้องการส่งแบบประเมินของ <strong><?php echo htmlspecialchars($child['chi_child_name']); ?></strong>?</p>
              
              <?php if ($latest_evaluation): ?>
                <div class="alert alert-warning">
                  <small><i class="fas fa-info-circle"></i> 
                  <strong>หมายเหตุ:</strong> การประเมินนี้จะถูกบันทึกเป็นครั้งที่ <?php echo ($latest_evaluation['eva_version'] + 1); ?> 
                  สำหรับวันที่ <?php echo date('d/m/Y'); ?> (ข้อมูลเก่าจะยังคงอยู่)</small>
                </div>
              <?php endif; ?>
              
              <div id="evaluation-summary" class="mt-3" style="display: none;">
                <h6>สรุปผลการประเมิน:</h6>
                <div class="row">
                  <div class="col-6">
                    <span class="badge bg-success" id="passed-count">0 ผ่าน</span>
                  </div>
                  <div class="col-6">
                    <span class="badge bg-danger" id="failed-count">0 ไม่ผ่าน</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
              <button type="submit" class="btn btn-primary">ยืนยัน</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ป้องกันการเลือก checkbox ทั้งผ่านและไม่ผ่านพร้อมกัน
    document.addEventListener('DOMContentLoaded', function() {
      // Desktop version
      for (let i = 60; i <= 64; i++) {
        const passCheckbox = document.getElementById(`q${i}_pass`);
        const failCheckbox = document.getElementById(`q${i}_fail`);
        
        if (passCheckbox && failCheckbox) {
          passCheckbox.addEventListener('change', function() {
            if (this.checked) failCheckbox.checked = false;
            updateSummary();
          });
          
          failCheckbox.addEventListener('change', function() {
            if (this.checked) passCheckbox.checked = false;
            updateSummary();
          });
        }
      }

      // Mobile version
      for (let i = 60; i <= 64; i++) {
        const passCheckboxMobile = document.getElementById(`q${i}_pass_mobile`);
        const failCheckboxMobile = document.getElementById(`q${i}_fail_mobile`);
        const passCheckboxDesktop = document.getElementById(`q${i}_pass`);
        const failCheckboxDesktop = document.getElementById(`q${i}_fail`);
        
        if (passCheckboxMobile && failCheckboxMobile) {
          // Mobile checkbox events
          passCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              failCheckboxMobile.checked = false;
              // Sync with desktop
              if (passCheckboxDesktop) passCheckboxDesktop.checked = true;
              if (failCheckboxDesktop) failCheckboxDesktop.checked = false;
            }
            updateSummary();
          });
          
          failCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              passCheckboxMobile.checked = false;
              // Sync with desktop
              if (failCheckboxDesktop) failCheckboxDesktop.checked = true;
              if (passCheckboxDesktop) passCheckboxDesktop.checked = false;
            }
            updateSummary();
          });

          // Sync desktop to mobile
          if (passCheckboxDesktop) {
            passCheckboxDesktop.addEventListener('change', function() {
              if (this.checked) {
                passCheckboxMobile.checked = true;
                failCheckboxMobile.checked = false;
              }
              updateSummary();
            });
          }

          if (failCheckboxDesktop) {
            failCheckboxDesktop.addEventListener('change', function() {
              if (this.checked) {
                failCheckboxMobile.checked = true;
                passCheckboxMobile.checked = false;
              }
              updateSummary();
            });
          }
        }
      }

      // แสดงสรุปผลเมื่อเปิด Modal
      document.getElementById('confirmModal').addEventListener('show.bs.modal', function() {
        updateSummary();
        document.getElementById('evaluation-summary').style.display = 'block';
      });
    });

    function updateSummary() {
      let passedCount = 0;
      let failedCount = 0;

      for (let i = 60; i <= 64; i++) {
        const passCheckbox = document.getElementById(`q${i}_pass`);
        const failCheckbox = document.getElementById(`q${i}_fail`);
        
        if (passCheckbox && passCheckbox.checked) passedCount++;
        if (failCheckbox && failCheckbox.checked) failedCount++;
      }

      document.getElementById('passed-count').textContent = passedCount + ' ผ่าน';
      document.getElementById('failed-count').textContent = failedCount + ' ไม่ผ่าน';
    }
  </script>
</body>
</html>

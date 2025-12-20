<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '31-36';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 79-83)
    for ($i = 79; $i <= 83; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 79-83)
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
  <title>แบบประเมิน ช่วงอายุ 31 ถึง 36 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 31 - 36 เดือน
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
            <!-- ข้อ 79-83 สำหรับ 31-36 เดือน -->
            <tr>
              <td rowspan="5">31 - 36 เดือน</td>
              <td>79<br>
                  <input type="checkbox" id="q79_pass" name="q79_pass" value="1">
                  <label for="q79_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q79_fail" name="q79_fail" value="1">
                  <label for="q79_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนขาเดียว 1 วินาที (GM)<br><br>
              </td>
              <td>
                แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (อาจเปลี่ยนขาได้)<br>
                <strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 1 วินาทีอย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
               1. ยืนบนขาข้างเดียวให้เด็กดู<br>
               2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
               3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตามเมื่อเด็กยืนได้ เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
               4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
              </td>
            </tr>

            <tr>
              <td>80<br>
                  <input type="checkbox" id="q80_pass" name="q80_pass" value="1">
                  <label for="q80_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q80_fail" name="q80_fail" value="1">
                  <label for="q80_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนแบบลากเส้นเป็นวงต่อเนื่องกัน (FM)<br><br>
              <strong>อุปกรณ์:</strong>1. ดินสอ 2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;"><br>
                </td>
              <td>
                1. ลากเส้นเป็นวงต่อเนื่องให้เด็กดูพร้อมกับพูดว่า “ลากเส้นเป็นวง”<br>
                2. ยื่นดินสอและกระดาษ แล้วบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong>  เด็กสามารถลากเส้นเป็นวงต่อเนื่องกันได้<br>
                <img src="../image/evaluation_pic/เส้นวงต่อเนื่อง.png" alt="Family" style="width: 150px; height: 120px;"><br>
              </td>
              <td>
                1. นำดินสอมาลากเส้นเป็นวงต่อเนื่องกันให้เด็กดูเป็นตัวอย่าง<br>
                2. ส่งดินสอให้เด็กและพูดว่า “(ชื่อเด็ก) ลากเส้นแบบนี้”<br>
                3. ถ้าเด็กทำไม่ได้ ให้ช่วยจับมือเด็กลากเส้นเป็นวงต่อเนื่อง<br>
                4. เมื่อเด็กเริ่มทำเองได้ปล่อยให้เด็กทำเอง โดยใช้สีที่แตกต่างกัน
                เพื่อกระตุ้นความสนใจของเด็ก
              </td>
            </tr>

            <tr>
              <td>81<br>
                  <input type="checkbox" id="q81_pass" name="q81_pass" value="1">
                  <label for="q81_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q81_fail" name="q81_fail" value="1">
                  <label for="q81_fail">ไม่ผ่าน</label><br>
              </td>
              <td>นำวัตถุ 2 ชนิด ในห้องมาให้ได้ตามคำสั่ง (RL)<br><br>
              <strong>อุปกรณ์:</strong>วัตถุที่เด็กรู้จัก 6 ชนิด เช่น แปรงสีฟัน หวี ช้อน ถ้วย ตุ๊กตาผ้า บอล<br>
              <img src="../image/evaluation_pic/doll_6pc.png" alt="Family" style="width: 200px; height: 120px;"><br>
            </td>
              <td>
                1. นำวัตถุทั้ง 6 ชนิด วางไว้ในที่ต่าง ๆ ในห้องโดยให้อยู่ห่างจากตัวเด็กประมาณ 3 เมตร<br>
                2. บอกให้เด็กหยิบวัตถุ 2 ชนิด เช่น แปรงสีฟันและหวีมาให้ (ครั้งที่ 1)<br>
                3. นำวัตถุทั้ง 2 ชนิด กลับไปวางไว้ในตำแหน่งใหม่<br>
                4. ทำซ้ำในข้อ 2 และ 3 อีก 2 ครั้ง<br>
                <strong>หมายเหตุ:</strong> หากคำสั่งแรกไม่ผ่านอาจให้เด็กหยิบวัตถุชนิดอื่น แต่ให้ทดสอบซ้ำ 3 ครั้ง ในวัตถุชนิดนั้น
                <strong>ผ่าน:</strong>  เด็กสามารถนำวัตถุ 2 ชนิดมาให้ได้ถูกต้องอย่างน้อย 2 ใน 3 ครั้ง
              </td>
              <td>
                1. ฝึกเด็กในชีวิตประจำวัน โดยออกคำสั่งให้เด็กหยิบของในห้องมาให้ทีละ 2 ชนิด เช่น หยิบแปรงสีฟัน และยาสีฟัน เสื้อและกางเกง
                ถ้าเด็กหยิบไม่ถูก ให้ชี้บอกหรือจูงมือเด็กพาไปหยิบของ เมื่อเด็กทำได้แล้ว ให้เปลี่ยนคำสั่งเป็นหยิบของใช้อื่น ๆ ที่หลากหลายมากขึ้น<br>
                2. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก จัดเก็บของให้เป็นระเบียบและอยู่ที่ประจำทุกครั้ง เพื่อฝึกให้เด็กมีระเบียบ<br>
                3. เมื่อเด็กทำได้แล้วให้เด็กเตรียมของก่อนที่จะทำกิจกรรมในชีวิตประจำวัน เช่น ก่อนอาบน้ำ หยิบผ้าเช็ดตัว หยิบแปรงสีฟัน ยาสีฟัน
                (ฝึกแปรงฟันแบบถูไปมา) ใส่เสื้อผ้าก่อนไปโรงเรียน หยิบกระเป๋า รองเท้าและฝึกการเก็บของให้เป็นระเบียบเข้าที่เดิมทุกครั้งที่นำออกมาใช้ เป็นต้น<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
              </td>
            </tr>

            <tr>
              <td>82<br>
                  <input type="checkbox" id="q82_pass" name="q82_pass" value="1">
                  <label for="q82_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q82_fail" name="q82_fail" value="1">
                  <label for="q82_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดติดต่อกัน 3 - 4 คำได้อย่างน้อย 4 ความหมาย (EL)<br><br>
              </td>
              <td>
                สังเกตหรือถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถพูด 3 - 4 คำ(ไม่ใช่ 3 - 4 พยางค์) ต่อกันได้หรือไม่ เช่น<br>
                - บอกการให้ เช่น<br>
                - บอกความต้องการ<br>
                - บอกปฏิเสธ<br>
                - แสดงความคิดเห็น<br>
                <strong>ผ่าน:</strong> เด็กพูดประโยคหรือวลีที่เป็นคำ 3 - 4 คำอย่างน้อย 4 ความหมาย

              </td>
              <td>
                1. พูดคำ 3 – 4 คำ ให้เด็กฟังบ่อยๆและให้เด็กพูดตาม ถ้าเด็กพูดได้ทีละคำหรือ 2 คำ ให้พูดขยายคำพูดเด็กเป็น 3 – 4 คำ เช่น เด็กพูด
                “ไป” พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พูดว่า“ไปหาแม่” “ไปกินข้าว”เพื่อให้เด็กบอกความต้องการให้ผู้อื่นได้เข้าใจ<br>
                2. ร้องเพลงเด็กที่ใช้คำพูดง่าย ๆ ให้เด็กฟังบ่อย ๆ พร้อมทำท่าทางตามเพลง เว้นวรรคให้เด็กร้องต่อ เช่น “จับ...(ปูดำ) ขยำ…(ปูนา) ”
                อาจร้องเพลงที่เป็นคำกลอนหรือภาษาอื่นที่เหมาะสม<br>
                3. พูดโต้ตอบกับเด็กบ่อยๆในสิ่งที่เด็กสนใจหรือกำลังทำกิจกรรมอยู่วิธีพูดให้พูดช้า ๆ ชัด ๆ มีจังหวะหยุดเพื่อให้เด็กพูดตามในระหว่าง
                ชีวิตประจำวัน เช่น ระหว่างอาบน้ำ ระหว่างทานข้าว การดูรูปภาพประกอบอ่านหนังสือร่วมกัน
              </td>
            </tr>

            <tr>
              <td>83<br>
                  <input type="checkbox" id="q83_pass" name="q83_pass" value="1">
                  <label for="q83_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q83_fail" name="q83_fail" value="1">
                  <label for="q83_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใส่กางเกงได้เอง (PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถใส่กางเกงเอวยางยืดได้เองหรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กใส่กางเกงเอวยางยืดได้เองโดยไม่ต้องช่วย และไม่จำเป็นต้องถูกด้าน
              </td>
              <td>
               1. เริ่มฝึกเด็กโดยใช้กางเกงขาสั้นเอวยืด มีขั้นตอนดังนี้<br>
               2. สอนให้เด็กรู้จักด้านนอกและด้านใน ด้านหน้าและด้านหลังของกางเกง<br>
               3. จัดให้เด็กนั่ง จับมือเด็กทั้ง 2 ข้าง จับที่ขอบกางเกงและดึงขอบกางเกงออกให้กว้าง สอดขาเข้าไปในกางเกงทีละข้างจนชายกางเกงพ้นข้อเท้า<br>
               4. ให้เด็กยืนขึ้น จับมือเด็กดึงขอบกางเกงให้ถึงระดับเอว<br>
               5. ถ้าเด็กเริ่มทำได้ให้ลดการช่วยเหลือลงทีละขั้นตอนและปล่อยให้เด็กทำเอง ซึ่งการที่เด็กช่วยเหลือตนเองเบื้องต้นได้ เพิ่มความภาคภูมิใจในตนเอง
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
        <!-- Card ข้อที่ 79 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 79 - ยืนขาเดียว 1 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 31 - 36 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q79_pass_mobile" name="q79_pass" value="1">
                <label class="form-check-label text-success" for="q79_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q79_fail_mobile" name="q79_fail" value="1">
                <label class="form-check-label text-danger" for="q79_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (อาจเปลี่ยนขาได้)</p>
              <p><strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 1 วินาทีอย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training79">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading79">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse79">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse79" class="accordion-collapse collapse" data-bs-parent="#training79">
                  <div class="accordion-body">
                    1. ยืนบนขาข้างเดียวให้เด็กดู<br>
                    2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
                    3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตามเมื่อเด็กยืนได้ เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
                    4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 80 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 80 - เลียนแบบลากเส้นเป็นวงต่อเนื่องกัน (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 31 - 36 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ดินสอ 2. กระดาษ
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q80_pass_mobile" name="q80_pass" value="1">
                <label class="form-check-label text-success" for="q80_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q80_fail_mobile" name="q80_fail" value="1">
                <label class="form-check-label text-danger" for="q80_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ลากเส้นเป็นวงต่อเนื่องให้เด็กดูพร้อมกับพูดว่า "ลากเส้นเป็นวง"<br>
              2. ยื่นดินสอและกระดาษ แล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถลากเส้นเป็นวงต่อเนื่องกันได้</p>
            </div>
            <div class="accordion" id="training80">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading80">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse80">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse80" class="accordion-collapse collapse" data-bs-parent="#training80">
                  <div class="accordion-body">
                    1. นำดินสอมาลากเส้นเป็นวงต่อเนื่องกันให้เด็กดูเป็นตัวอย่าง<br>
                    2. ส่งดินสอให้เด็กและพูดว่า "(ชื่อเด็ก) ลากเส้นแบบนี้"<br>
                    3. ถ้าเด็กทำไม่ได้ ให้ช่วยจับมือเด็กลากเส้นเป็นวงต่อเนื่อง<br>
                    4. เมื่อเด็กเริ่มทำเองได้ปล่อยให้เด็กทำเอง โดยใช้สีที่แตกต่างกัน เพื่อกระตุ้นความสนใจของเด็ก
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 81 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 81 - นำวัตถุ 2 ชนิด ในห้องมาให้ได้ตามคำสั่ง (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 31 - 36 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> วัตถุที่เด็กรู้จัก 6 ชนิด เช่น แปรงสีฟัน หวี ช้อน ถ้วย ตุ๊กตาผ้า บอล
              <img src="../image/evaluation_pic/doll_6pc.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q81_pass_mobile" name="q81_pass" value="1">
                <label class="form-check-label text-success" for="q81_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q81_fail_mobile" name="q81_fail" value="1">
                <label class="form-check-label text-danger" for="q81_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. นำวัตถุทั้ง 6 ชนิด วางไว้ในที่ต่าง ๆ ในห้องโดยให้อยู่ห่างจากตัวเด็กประมาณ 3 เมตร<br>
              2. บอกให้เด็กหยิบวัตถุ 2 ชนิด เช่น แปรงสีฟันและหวีมาให้ (ครั้งที่ 1)<br>
              3. นำวัตถุทั้ง 2 ชนิด กลับไปวางไว้ในตำแหน่งใหม่<br>
              4. ทำซ้ำในข้อ 2 และ 3 อีก 2 ครั้ง</p>
              <p><strong>หมายเหตุ:</strong> หากคำสั่งแรกไม่ผ่านอาจให้เด็กหยิบวัตถุชนิดอื่น แต่ให้ทดสอบซ้ำ 3 ครั้ง ในวัตถุชนิดนั้น</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถนำวัตถุ 2 ชนิดมาให้ได้ถูกต้องอย่างน้อย 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training81">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading81">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse81">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse81" class="accordion-collapse collapse" data-bs-parent="#training81">
                  <div class="accordion-body">
                    1. ฝึกเด็กในชีวิตประจำวัน โดยออกคำสั่งให้เด็กหยิบของในห้องมาให้ทีละ 2 ชนิด เช่น หยิบแปรงสีฟัน และยาสีฟัน เสื้อและกางเกง ถ้าเด็กหยิบไม่ถูก ให้ชี้บอกหรือจูงมือเด็กพาไปหยิบของ เมื่อเด็กทำได้แล้ว ให้เปลี่ยนคำสั่งเป็นหยิบของใช้อื่น ๆ ที่หลากหลายมากขึ้น<br>
                    2. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก จัดเก็บของให้เป็นระเบียบและอยู่ที่ประจำทุกครั้ง เพื่อฝึกให้เด็กมีระเบียบ<br>
                    3. เมื่อเด็กทำได้แล้วให้เด็กเตรียมของก่อนที่จะทำกิจกรรมในชีวิตประจำวัน เช่น ก่อนอาบน้ำ หยิบผ้าเช็ดตัว หยิบแปรงสีฟัน ยาสีฟัน (ฝึกแปรงฟันแบบถูไปมา) ใส่เสื้อผ้าก่อนไปโรงเรียน หยิบกระเป๋า รองเท้าและฝึกการเก็บของให้เป็นระเบียบเข้าที่เดิมทุกครั้งที่นำออกมาใช้ เป็นต้น<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 82 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 82 - พูดติดต่อกัน 3 - 4 คำได้อย่างน้อย 4 ความหมาย (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 31 - 36 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q82_pass_mobile" name="q82_pass" value="1">
                <label class="form-check-label text-success" for="q82_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q82_fail_mobile" name="q82_fail" value="1">
                <label class="form-check-label text-danger" for="q82_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตหรือถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถพูด 3 - 4 คำ(ไม่ใช่ 3 - 4 พยางค์) ต่อกันได้หรือไม่ เช่น<br>
              - บอกการให้ เช่น<br>
              - บอกความต้องการ<br>
              - บอกปฏิเสธ<br>
              - แสดงความคิดเห็น</p>
              <p><strong>ผ่าน:</strong> เด็กพูดประโยคหรือวลีที่เป็นคำ 3 - 4 คำอย่างน้อย 4 ความหมาย</p>
            </div>
            <div class="accordion" id="training82">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading82">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse82">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse82" class="accordion-collapse collapse" data-bs-parent="#training82">
                  <div class="accordion-body">
                    1. พูดคำ 3 – 4 คำ ให้เด็กฟังบ่อยๆและให้เด็กพูดตาม ถ้าเด็กพูดได้ทีละคำหรือ 2 คำ ให้พูดขยายคำพูดเด็กเป็น 3 – 4 คำ เช่น เด็กพูด "ไป" พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พูดว่า"ไปหาแม่" "ไปกินข้าว"เพื่อให้เด็กบอกความต้องการให้ผู้อื่นได้เข้าใจ<br>
                    2. ร้องเพลงเด็กที่ใช้คำพูดง่าย ๆ ให้เด็กฟังบ่อย ๆ พร้อมทำท่าทางตามเพลง เว้นวรรคให้เด็กร้องต่อ เช่น "จับ...(ปูดำ) ขยำ…(ปูนา) " อาจร้องเพลงที่เป็นคำกลอนหรือภาษาอื่นที่เหมาะสม<br>
                    3. พูดโต้ตอบกับเด็กบ่อยๆในสิ่งที่เด็กสนใจหรือกำลังทำกิจกรรมอยู่วิธีพูดให้พูดช้า ๆ ชัด ๆ มีจังหวะหยุดเพื่อให้เด็กพูดตามในระหว่าง ชีวิตประจำวัน เช่น ระหว่างอาบน้ำ ระหว่างทานข้าว การดูรูปภาพประกอบอ่านหนังสือร่วมกัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 83 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 83 - ใส่กางเกงได้เอง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 31 - 36 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q83_pass_mobile" name="q83_pass" value="1">
                <label class="form-check-label text-success" for="q83_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q83_fail_mobile" name="q83_fail" value="1">
                <label class="form-check-label text-danger" for="q83_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กสามารถใส่กางเกงเอวยางยืดได้เองหรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กใส่กางเกงเอวยางยืดได้เองโดยไม่ต้องช่วย และไม่จำเป็นต้องถูกด้าน</p>
            </div>
            <div class="accordion" id="training83">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading83">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse83">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse83" class="accordion-collapse collapse" data-bs-parent="#training83">
                  <div class="accordion-body">
                    1. เริ่มฝึกเด็กโดยใช้กางเกงขาสั้นเอวยืด มีขั้นตอนดังนี้<br>
                    2. สอนให้เด็กรู้จักด้านนอกและด้านใน ด้านหน้าและด้านหลังของกางเกง<br>
                    3. จัดให้เด็กนั่ง จับมือเด็กทั้ง 2 ข้าง จับที่ขอบกางเกงและดึงขอบกางเกงออกให้กว้าง สอดขาเข้าไปในกางเกงทีละข้างจนชายกางเกงพ้นข้อเท้า<br>
                    4. ให้เด็กยืนขึ้น จับมือเด็กดึงขอบกางเกงให้ถึงระดับเอว<br>
                    5. ถ้าเด็กเริ่มทำได้ให้ลดการช่วยเหลือลงทีละขั้นตอนและปล่อยให้เด็กทำเอง ซึ่งการที่เด็กช่วยเหลือตนเองเบื้องต้นได้ เพิ่มความภาคภูมิใจในตนเอง
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
      for (let i = 79; i <= 83; i++) {
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
      for (let i = 79; i <= 83; i++) {
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

      for (let i = 79; i <= 83; i++) {
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

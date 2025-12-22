<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '55-59';

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
    for ($i = 112; $i <= 116; $i++) {
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
  <title>แบบประเมิน ช่วงอายุ 55 ถึง 59 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 55 - 59 เดือน
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
            <!-- ข้อ 112-116 สำหรับ 55-59 เดือน -->
            <tr>
              <td rowspan="5">55 - 59 เดือน</td>
              <td>112<br>
                  <input type="checkbox" id="q112_pass" name="q112_pass" value="1">
                  <label for="q112_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q112_fail" name="q112_fail" value="1">
                  <label for="q112_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เดินต่อส้นเท้า (GM)<br><br>
              </td>
              <td>
                ก้าวเดินโดยให้ส้นเท้าข้างหนึ่งไปต่อชิดกับปลายเท้าอีกข้างหนึ่งให้เด็กดูแล้วบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> ถ้าเด็กสามารถเดินโดยส้นเท้าต่อกับปลายเท้าได้ 4 ก้าว โดยไม่เสียการทรงตัว
                
              </td>
              <td>
               1. เดินก้าวสลับเท้าโดยให้ส้นเท้าต่อกับปลายเท้าอีกข้างให้เด็กดู<br>
               2. บอกให้เด็กทำตาม โดยช่วยพยุงและกระตุ้นให้เด็กใช้ส้นเท้าต่อกับปลายเท้าอีกข้าง เมื่อเด็กเริ่มทำได้ ลดการช่วยเหลือลง จนเด็ก
               สามารถเดินได้เอง 4 - 5 ก้าว โดยไม่เสียการทรงตัว
               
              </td>
            </tr>

            <tr>
              <td>113<br>
                  <input type="checkbox" id="q113_pass" name="q113_pass" value="1">
                  <label for="q113_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q113_fail" name="q113_fail" value="1">
                  <label for="q113_fail">ไม่ผ่าน</label><br>
              </td>
              <td>จับดินสอได้ถูกต้อง (FM)<br><br>
              <strong>อุปกรณ์:</strong>1.ดินสอ  2.กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;">
                </td>
              <td>
                ยื่นกระดาษและดินสอให้เด็ก และบอกว่า“หนูลองใช้ดินสอเขียนดูนะ”<br>
                <strong>ผ่าน:</strong>เด็กสามารถจับดินสอ โดยจับสูงกว่าปลายประมาณ 1 - 2 ซม. และดินสออยู่
                ระหว่างนิ้วหัวแม่มือ นิ้วชี้ และนิ้วกลาง
                
              </td>
              <td>
                1. จับดินสอให้เด็กดูเป็นตัวอย่าง แล้วชวนให้เด็กจับดินสอขีดเขียน<br>
                2. ถ้าเด็กทำไม่ได้ช่วยจับมือเด็กโดยให้ดินสออยู่ระหว่างส่วนปลายของนิ้วหัวแม่มือ นิ้วชี้ นิ้วกลาง และสูงกว่าปลายดินสอประมาณ
                1 - 2 ซม. จนเด็กทำได้เอง
                
              </td>
            </tr>

            <tr>
              <td>114<br>
                  <input type="checkbox" id="q114_pass" name="q114_pass" value="1">
                  <label for="q114_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q114_fail" name="q114_fail" value="1">
                  <label for="q114_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกสีได้ 8 สี ตามคำสั่ง (RL)<br><br>
              <strong>อุปกรณ์:</strong>ก้อนไม้ 10 สี 10 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้ 10 สี 10 ก้อน.png" alt="Color Blocks" style="width: 200px; height: 120px;">
            </td>
              <td>
                วางก้อนไม้ 10 ก้อน ตรงหน้าเด็ก แล้วบอกเด็กว่า “หยิบก้อนไม้สีฟ้า” ผู้ประเมินนำ
                ก้อนไม้ที่เด็กหยิบกลับไปวางที่เดิม บอกให้เด็กหยิบสีอื่น ๆ จนครบ 8 สี (สีฟ้า สีเขียว
                สีชมพู สีดำ สีขาว สีแดง สีเหลือง สีส้ม)<br>
                <strong>ผ่าน:</strong>เด็กสามารถหยิบก้อนไม้ได้ถูกต้อง8 สี ตามคำสั่งแต่ละครั้ง
                
              </td>
              <td>
                1. สอนให้เด็กรู้จักสีจากสิ่งของที่มีอยู่ในบ้าน เช่น ผัก ผลไม้ เสื้อผ้าของใช้ โดยพูดบอกเด็กในแต่ละสี แล้วให้เด็กพูดตาม<br>
                2. นำของที่มีอยู่ใกล้ตัวสีละ 1 ชิ้น โดยเริ่มต้นจาก 4 สี ได้แก่ สีแดงสีฟ้า สีเขียว สีเหลือง มาคละรวมกัน แล้วถามเด็ก “อันไหนสี...”<br>
                3. หากเด็กรู้จักสีทั้ง 4 สี แล้ว ให้เพิ่มจำนวนสีขึ้นเรื่อย ๆ จนครบทั้ง 8 สี (สีฟ้า สีเขียว สีชมพู สีดำ สีขาว สีแดง สีเหลือง สีส้ม)<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong><br>- สีเทียน/สีไม้ <br>- สิ่งของในบ้านที่มีสีสันต่าง ๆ เช่น ดอกไม้ เสื้อผ้า</span>
              </td>
            </tr>

            <tr>
              <td>115<br>
                  <input type="checkbox" id="q115_pass" name="q115_pass" value="1">
                  <label for="q115_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q115_fail" name="q115_fail" value="1">
                  <label for="q115_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ผลัดกันพูดคุยกับเพื่อนได้ในกลุ่ม (EL)<br><br>
              </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถพูดคุยกับเพื่อนได้ต่อเนื่องกันได้หรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กสามารถผลัดกันพูดโต้ตอบในกลุ่มได้
                
              </td>
              <td>
                1. กระตุ้นให้เด็กพูดคุย หรือโต้ตอบกันขณะเล่นด้วยกัน เช่นเล่นขายของ เป็นหมอกับคนไข้ ครูกับนักเรียน หรือเล่านิทาน
                ให้เด็กฟัง โดยให้เด็กมีส่วนร่วมในการเลือกนิทาน ถามคำถามเกี่ยวกับนิทานและฝึกให้เด็กยกมือขึ้นก่อนตอบคำถาม<br>
                2. ให้เด็กมีส่วนร่วมในการเสนอความคิดเห็น เช่น ถามเด็กว่า“วันนี้เราจะกินอะไรกันดี” “เดี๋ยวเราจะอ่านหนังสืออะไรดี”<br>
                3. เปิดโอกาสให้เด็กฝึกพูดคุยกันในกลุ่ม เพื่อแบ่งหน้าที่ในการทำงานร่วมกัน เช่น ขออาสาสมัครในการช่วยแจกนมให้กับเพื่อน ๆ
                ช่วยเก็บขยะ เก็บของเล่น จัดโต๊ะเก้าอี้<br>
                4. ถ้าเด็กพูดแทรกให้บอกเด็กว่า “หนูรอก่อนนะ เดี๋ยวแม่ขอพูดให้จบก่อน แล้วหนูค่อยพูดต่อ” หรือตกลงกติกาให้ทุกคนยกมือ
                ขออนุญาตก่อนพูดแทรก จนเด็กสามารถควบคุมตนเองได<br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong>ฝึกการพูดโต้ตอบ และการควบคุมอารมณ์ตนเอง
                ให้อดทนรอในเวลาที่เหมาะสม ยืดหยุ่นทางความคิด พูดในเรื่องที่เกี่ยวข้องกับเรื่องที่กลุ่มพูด และเป็นการสร้างทักษะการเข้าสังคม</span>
              </td>
            </tr>

            <tr>
              <td>116<br>
                  <input type="checkbox" id="q116_pass" name="q116_pass" value="1">
                  <label for="q116_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q116_fail" name="q116_fail" value="1">
                  <label for="q116_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เล่นเลียนแบบบทบาทของผู้ใหญ่ได้ (PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า“เด็กเคยเล่นเลียนแบบบทบาทอาชีพของ
                ผู้ใหญ่โดยเล่นกับเพื่อนได้หรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กสามารถเล่นเลียนแบบผู้ใหญ่ได้อย่างน้อย 1 บทบาท เช่น พ่อ แม่ ครู แพทย์
                พยาบาล หัวหน้ากลุ่ม โดยเลียนแบบผ่านทางน้ำเสียงท่าทางการแต่งตัวกับเพื่อนได้
                
              </td>
              <td>
               1. ร่วมเล่นบทบาทสมมติกับเด็ก เช่น เล่นเป็นครู เล่นเป็นหมอ เล่น
               เป็นพ่อค้า โดยให้เด็กเลือกเองว่าอยากเล่นเป็นใคร<br>
               2. ให้เด็กเล่นร่วมกับเด็กอื่น ช่วยหาอุปกรณ์ประกอบการเล่น เช่น
               ของใช้ในบ้าน เสื้อผ้า โดยเลือกของให้เหมาะกับบทบาทและปลอดภัย<br>
               3. สนับสนุนให้เด็กได้มีโอกาสผลัดเปลี่ยนบทบาทในการเล่นตามสถานการณ์ต่าง ๆ <br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong>ส่งเสริมให้เด็กมีจินตนาการและทักษะการเข้าสังคม
               ผ่านการเลียนแบบบทบาทสมมุติของผู้ใหญ่</span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="d-flex justify-content-center mt-4">
          <button type="button" class="btn btn-primary btn-lg px-5 rounded-pill" data-bs-toggle="modal" data-bs-target="#confirmModal">
            ยืนยันแบบประเมิน
          </button>
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
      </div>

      

      <!-- Mobile version -->
      <div class="d-block d-md-none">
        <!-- Card ข้อที่ 112 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 112 - เดินต่อส้นเท้า (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 55 - 59 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q112_pass_mobile" name="q112_pass" value="1">
                <label class="form-check-label text-success" for="q112_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q112_fail_mobile" name="q112_fail" value="1">
                <label class="form-check-label text-danger" for="q112_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ก้าวเดินโดยให้ส้นเท้าข้างหนึ่งไปต่อชิดกับปลายเท้าอีกข้างหนึ่งให้เด็กดูแล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> ถ้าเด็กสามารถเดินโดยส้นเท้าต่อกับปลายเท้าได้ 4 ก้าว โดยไม่เสียการทรงตัว</p>
            </div>
            <div class="accordion" id="training112">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading112">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse112">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse112" class="accordion-collapse collapse" data-bs-parent="#training112">
                  <div class="accordion-body">
                    1. เดินก้าวสลับเท้าโดยให้ส้นเท้าต่อกับปลายเท้าอีกข้างให้เด็กดู<br>
                    2. บอกให้เด็กทำตาม โดยช่วยพยุงและกระตุ้นให้เด็กใช้ส้นเท้าต่อกับปลายเท้าอีกข้าง เมื่อเด็กเริ่มทำได้ ลดการช่วยเหลือลง จนเด็ก
                    สามารถเดินได้เอง 4 - 5 ก้าว โดยไม่เสียการทรงตัว
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 113 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 113 - จับดินสอได้ถูกต้อง (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 55 - 59 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ดินสอ 2. กระดาษ
            <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q113_pass_mobile" name="q113_pass" value="1">
                <label class="form-check-label text-success" for="q113_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q113_fail_mobile" name="q113_fail" value="1">
                <label class="form-check-label text-danger" for="q113_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ยื่นกระดาษและดินสอให้เด็ก และบอกว่า“หนูลองใช้ดินสอเขียนดูนะ”</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถจับดินสอ โดยจับสูงกว่าปลายประมาณ 1 - 2 ซม. และดินสออยู่
                ระหว่างนิ้วหัวแม่มือ นิ้วชี้ และนิ้วกลาง</p>
            </div>
            <div class="accordion" id="training113">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading113">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse113">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse113" class="accordion-collapse collapse" data-bs-parent="#training113">
                  <div class="accordion-body">
                    1. จับดินสอให้เด็กดูเป็นตัวอย่าง แล้วชวนให้เด็กจับดินสอขีดเขียน<br>
                2. ถ้าเด็กทำไม่ได้ช่วยจับมือเด็กโดยให้ดินสออยู่ระหว่างส่วนปลายของนิ้วหัวแม่มือ นิ้วชี้ นิ้วกลาง และสูงกว่าปลายดินสอประมาณ
                1 - 2 ซม. จนเด็กทำได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 114 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 114 - เลือกสีได้ 8 สี ตามคำสั่ง (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 55 - 59 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ก้อนไม้ 10 สี 10 ก้อน
            <img src="../image/evaluation_pic/ก้อนไม้ 10 สี 10 ก้อน.png" alt="Color Blocks" style="width: 200px; height: 120px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q114_pass_mobile" name="q114_pass" value="1">
                <label class="form-check-label text-success" for="q114_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q114_fail_mobile" name="q114_fail" value="1">
                <label class="form-check-label text-danger" for="q114_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางก้อนไม้ 10 ก้อน ตรงหน้าเด็ก แล้วบอกเด็กว่า “หยิบก้อนไม้สีฟ้า” ผู้ประเมินนำ
                ก้อนไม้ที่เด็กหยิบกลับไปวางที่เดิม บอกให้เด็กหยิบสีอื่น ๆ จนครบ 8 สี (สีฟ้า สีเขียว
                สีชมพู สีดำ สีขาว สีแดง สีเหลือง สีส้ม)</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถหยิบก้อนไม้ได้ถูกต้อง8 สี ตามคำสั่งแต่ละครั้ง</p>
            </div>
            <div class="accordion" id="training114">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading114">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse114">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse114" class="accordion-collapse collapse" data-bs-parent="#training114">
                  <div class="accordion-body">
                    1. สอนให้เด็กรู้จักสีจากสิ่งของที่มีอยู่ในบ้าน เช่น ผัก ผลไม้ เสื้อผ้าของใช้ โดยพูดบอกเด็กในแต่ละสี แล้วให้เด็กพูดตาม<br>
                2. นำของที่มีอยู่ใกล้ตัวสีละ 1 ชิ้น โดยเริ่มต้นจาก 4 สี ได้แก่ สีแดงสีฟ้า สีเขียว สีเหลือง มาคละรวมกัน แล้วถามเด็ก “อันไหนสี...”<br>
                3. หากเด็กรู้จักสีทั้ง 4 สี แล้ว ให้เพิ่มจำนวนสีขึ้นเรื่อย ๆ จนครบทั้ง 8 สี (สีฟ้า สีเขียว สีชมพู สีดำ สีขาว สีแดง สีเหลือง สีส้ม)<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> <br>- สีเทียน/สีไม้ <br>- สิ่งของในบ้านที่มีสีสันต่าง ๆ เช่น ดอกไม้ เสื้อผ้า</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 115 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 115 - ผลัดกันพูดคุยกับเพื่อนได้ในกลุ่ม (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 55 - 59 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q115_pass_mobile" name="q115_pass" value="1">
                <label class="form-check-label text-success" for="q115_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q115_fail_mobile" name="q115_fail" value="1">
                <label class="form-check-label text-danger" for="q115_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถพูดคุยกับเพื่อนได้ต่อเนื่องกันได้หรือไม่”</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถผลัดกันพูดโต้ตอบในกลุ่มได้</p>
            </div>
            <div class="accordion" id="training115">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading115">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse115">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse115" class="accordion-collapse collapse" data-bs-parent="#training115">
                  <div class="accordion-body">
                    1. กระตุ้นให้เด็กพูดคุย หรือโต้ตอบกันขณะเล่นด้วยกัน เช่นเล่นขายของ เป็นหมอกับคนไข้ ครูกับนักเรียน หรือเล่านิทาน
                ให้เด็กฟัง โดยให้เด็กมีส่วนร่วมในการเลือกนิทาน ถามคำถามเกี่ยวกับนิทานและฝึกให้เด็กยกมือขึ้นก่อนตอบคำถาม<br>
                2. ให้เด็กมีส่วนร่วมในการเสนอความคิดเห็น เช่น ถามเด็กว่า“วันนี้เราจะกินอะไรกันดี” “เดี๋ยวเราจะอ่านหนังสืออะไรดี”<br>
                3. เปิดโอกาสให้เด็กฝึกพูดคุยกันในกลุ่ม เพื่อแบ่งหน้าที่ในการทำงานร่วมกัน เช่น ขออาสาสมัครในการช่วยแจกนมให้กับเพื่อน ๆ
                ช่วยเก็บขยะ เก็บของเล่น จัดโต๊ะเก้าอี้<br>
                4. ถ้าเด็กพูดแทรกให้บอกเด็กว่า “หนูรอก่อนนะ เดี๋ยวแม่ขอพูดให้จบก่อน แล้วหนูค่อยพูดต่อ” หรือตกลงกติกาให้ทุกคนยกมือ
                ขออนุญาตก่อนพูดแทรก จนเด็กสามารถควบคุมตนเองได<br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong>ฝึกการพูดโต้ตอบ และการควบคุมอารมณ์ตนเอง
                ให้อดทนรอในเวลาที่เหมาะสม ยืดหยุ่นทางความคิด พูดในเรื่องที่เกี่ยวข้องกับเรื่องที่กลุ่มพูด และเป็นการสร้างทักษะการเข้าสังคม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 116 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 116 - เล่นเลียนแบบบทบาทของผู้ใหญ่ได้ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 55 - 59 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q116_pass_mobile" name="q116_pass" value="1">
                <label class="form-check-label text-success" for="q116_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q116_fail_mobile" name="q116_fail" value="1">
                <label class="form-check-label text-danger" for="q116_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า“เด็กเคยเล่นเลียนแบบบทบาทอาชีพของ
                ผู้ใหญ่โดยเล่นกับเพื่อนได้หรือไม่”</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเล่นเลียนแบบผู้ใหญ่ได้อย่างน้อย 1 บทบาท เช่น พ่อ แม่ ครู แพทย์
                พยาบาล หัวหน้ากลุ่ม โดยเลียนแบบผ่านทางน้ำเสียงท่าทางการแต่งตัวกับเพื่อนได้</p>
            </div>
            <div class="accordion" id="training116">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading116">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse116">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse116" class="accordion-collapse collapse" data-bs-parent="#training116">
                  <div class="accordion-body">
                    1. ร่วมเล่นบทบาทสมมติกับเด็ก เช่น เล่นเป็นครู เล่นเป็นหมอ เล่น
               เป็นพ่อค้า โดยให้เด็กเลือกเองว่าอยากเล่นเป็นใคร<br>
               2. ให้เด็กเล่นร่วมกับเด็กอื่น ช่วยหาอุปกรณ์ประกอบการเล่น เช่น
               ของใช้ในบ้าน เสื้อผ้า โดยเลือกของให้เหมาะกับบทบาทและปลอดภัย<br>
               3. สนับสนุนให้เด็กได้มีโอกาสผลัดเปลี่ยนบทบาทในการเล่นตามสถานการณ์ต่าง ๆ <br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong>ส่งเสริมให้เด็กมีจินตนาการและทักษะการเข้าสังคม
               ผ่านการเลียนแบบบทบาทสมมุติของผู้ใหญ่</span>
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
      for (let i = 112; i <= 116; i++) {
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
      for (let i = 112; i <= 116; i++) {
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

      for (let i = 112; i <= 116; i++) {
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

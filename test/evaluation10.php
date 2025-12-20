<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '18';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 50-59)
    for ($i = 50; $i <= 59; $i++) {
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
    $total_questions = 10; // แบบประเมินมีทั้งหมด 10 ข้อ (ข้อ 50-59)
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
  <title>แบบประเมิน ช่วงอายุ 18 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 18 เดือน
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
            <!-- ข้อ 50-59 สำหรับ 18 เดือน -->
            <tr>
              <td rowspan="10">18 เดือน</td>
              <td>50<br>
                  <input type="checkbox" id="q50_pass" name="q50_pass" value="1">
                  <label for="q50_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q50_fail" name="q50_fail" value="1">
                  <label for="q50_fail">ไม่ผ่าน</label><br>
              </td>
              <td>วิ่งได้ (GM)<br><br>
                <strong>อุปกรณ์:</strong>  ลูกบอลเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร<br>
                <img src="../image/evaluation_pic/ball_15_20.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
              <td>
                วิ่งเล่นกับเด็ก หรืออาจกลิ้งลูกบอลแล้วกระตุ้นให้เด็กวิ่งตามลูกบอล<br>
                <strong>ผ่าน:</strong> เด็กวิ่งได้อย่างมั่นคงโดยไม่ล้ม และ ไม่ใช่การเดินเร็ว
              </td>
              <td>
               1. จับมือเด็กวิ่งเล่น หรือร่วมวิ่งกับเด็กคนอื่น ๆ เพื่อให้เด็กสนุกสนาน <br>
               2. ลดการช่วยเหลือลง เมื่อเด็กมั่นใจและเริ่มวิ่งได้ดีขึ้นจนเด็กสามารถวิ่งได้เอง <br>
               <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> วัสดุมาทำเป็นก้อนกลม ๆ เช่น ก้อนฟางลูกบอลสานด้วยใบมะพร้าว</span>
              </td>
            </tr>

            <tr>
              <td>51<br>
                  <input type="checkbox" id="q51_pass" name="q51_pass" value="1">
                  <label for="q51_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q51_fail" name="q51_fail" value="1">
                  <label for="q51_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เดินถือลูกบอลไปได้ไกล 3 เมตร (GM)<br><br>
                <strong>อุปกรณ์:</strong>  ลูกบอลเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร<br>
                <img src="../image/evaluation_pic/ball_15_20.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
              <td>
                1. จัดให้เด็กและพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กยืนหันหน้าเข้าหากันระยะห่าง 3 เมตร <br>
                2. ส่งลูกบอลให้เด็กถือ และบอกให้เด็กเดินไปหาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก<br>
                <strong>ผ่าน:</strong>  ผ่าน : เด็กสามารถเดินถือลูกบอล ไปได้ไกล 3 เมตร โดยไม่ล้ม และไม่เสียการทรงตัว
              </td>
              <td>
                1. ฝึกให้เด็กเดินโดยถือของมือเดียว<br>
                2. เมื่อเด็กทำได้แล้วให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก วางตะกร้าไว้ในระยะห่าง 3 เมตร แล้วถือของที่มีขนาดใหญ่ขึ้นด้วยสองมือ
                และเดินเอาของไปใส่ตะกร้าให้เด็กดู แล้วบอกให้เด็กทำตาม <br>
                3. ถ้าเด็กทำไม่ได้ ให้ขยับตะกร้าให้ใกล้ขึ้น และจับมือเด็กถือของช่วยพยุงหากเด็กยังทรงตัวได้ไม่ดี <br>
                4. เมื่อเด็กทรงตัวได้ดีและถือของได้ด้วยตนเองให้เพิ่มระยะทางจนถึง 3 เมตร <br>
                <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> วัสดุในบ้าน เช่น ตุ๊กตา หมอน</span>

              </td>
            </tr>

            <tr>
              <td>52<br>
                  <input type="checkbox" id="q52_pass" name="q52_pass" value="1">
                  <label for="q52_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q52_fail" name="q52_fail" value="1">
                  <label for="q52_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เปิดหน้าหนังสือที่ทำด้วยกระดาษแข็งทีละแผ่นได้เอง(FM)<br><br>
              <strong>อุปกรณ์:</strong> หนังสือรูปภาพทำด้วยกระดาษแข็ง<br>
              <img src="../image/evaluation_pic/หนังสือรูปภาพ.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
              <td>
                วางหนังสือไว้ตรงหน้าเด็ก แสดงวิธีการเปิดหนังสือให้เด็กดู และบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> : เด็กสามารถแยกหน้าและพลิกหน้าหนังสือได้ทีละแผ่นด้วยตนเองอย่างน้อย 1 แผ่น
              </td>
              <td>
                1. เปิดหน้าหนังสือทีละหน้าแล้วชี้ให้เด็กดูรูปภาพและปิดหนังสือ <br>
                2. บอกให้เด็กทำตาม<br>
                3. ถ้าเด็กทำไม่ได้ให้ช่วยจับมือเด็กพลิกหน้าหนังสือทีละหน้า<br>
                4. เล่านิทานประกอบรูปภาพ เพื่อเสริมสร้างจินตนาการของเด็ก
                <span style="color: red;"><strong>หนังสือที่ใช้แทนได้:</strong> หนังสือเด็กที่ทำด้วยพลาสติก ผ้า หรือกระดาษหนา ๆ</span>
              </td>
            </tr>

            <tr>
              <td>53<br>
                  <input type="checkbox" id="q53_pass" name="q53_pass" value="1">
                  <label for="q53_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q53_fail" name="q53_fail" value="1">
                  <label for="q53_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ต่อก้อนไม้ 2 ชั้น (FM)<br><br>
              <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยม ลูกบาศก์ 4 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 4 ก้อน.png" alt="Family" style="width: 120px; height: 90px;"><br></td>
              <td>
                1. วางก้อนไม้ 4 ก้อน ตรงหน้าเด็ก<br>
                2. ต่อก้อนไม้ 2 ชั้นให้เด็กดูแล้วรื้อแบบออก<br>
                3. กระตุ้นให้เด็กต่อก้อนไม้เองให้โอกาสประเมิน 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถต่อก้อนไม้โดยไม่ล้ม 2 ใน 3 ครั้ง
              </td>
              <td>
                1. ใช้วัตถุที่เป็นทรงสี่เหลี่ยม เช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู<br>
                2. กระตุ้นให้เด็กทำตาม<br>
                3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1<br>
                4. ทำซ้ำหลายครั้งและลดการช่วยเหลือลง จนเด็กต่อก้อนไม้ได้เองหากเด็กทำได้แล้วให้ชมเชย เพิ่มความภาคภูมิใจในตนเอง หากเด็ก
                ต่อได้ 2 ชั้น แล้วให้เปลี่ยนเป็นต่อมากกว่า 2 ชั้น <br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม </span>
              </td>
            </tr>

            <tr>
              <td>54<br>
                  <input type="checkbox" id="q54_pass" name="q54_pass" value="1">
                  <label for="q54_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q54_fail" name="q54_fail" value="1">
                  <label for="q54_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกวัตถุตามคำสั่งได้ถูกต้อง 3 ชนิด (RL)<br><br>
                <strong>อุปกรณ์:</strong> ตุ๊กตาผ้า บอล รถ<br>
                <img src="../image/evaluation_pic/doll_ball_car.png" alt="Family" style="width: 120px; height: 90px;"><br>
                <strong>หมายเหตุ:</strong> ในกรณีที่มีข้อขัดข้องทางสังคมและวัฒนธรรมให้ใช้ถ้วยหรือหนังสือที่เป็นชุดอุปกรณ์ DSPM แทนได้
              </td>
              <td>
                วางวัตถุ 3 ชนิดไว้ตรงหน้าเด็กแล้วถามว่า “…อยู่ไหน” จนครบทั้ง 3 ชนิด แล้วจึงสลับตำแหน่งที่วางวัตถุ ให้โอกาสประเมิน 3 ครั้ง <br>
                <strong>ผ่าน:</strong> เด็กสามารถชี้หรือหยิบวัตถุได้ถูกต้องทั้ง 3 ชนิด อย่างน้อย 2 ครั้ง
              </td>
              <td>
               1. เตรียมของเล่นหรือวัตถุที่เด็กคุ้นเคย 2 ชนิด และบอกให้เด็กรู้จักชื่อวัตถุทีละชนิด <br>
               2. ถามเด็ก “…อยู่ไหน” โดยให้เด็กชี้หรือหยิบ ถ้าเด็กเลือกไม่ถูกต้องให้เลื่อนของเข้าไปใกล้ และจับมือเด็กชี้หรือหยิบ <br>
               3. เมื่อเด็กสามารถเลือกได้ถูกต้อง เพิ่มของเล่นหรือวัตถุที่เด็กคุ้นเคย เป็น 3 ชนิด และถามเช่นเดิมจนเด็กชี้หรือหยิบได้ถูกต้องทั้ง 3 ชนิด <br>
               4. เพิ่มวัตถุชนิดอื่นที่เด็กสนใจชี้ให้เด็กดู แล้วพูดให้เด็กชี้ เพื่อเพิ่มการเรียนรู้ภาษาของเด็ก <br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย เช่น
                แก้วน้ำ ถ้วย ช้อน แปรง หวี ตุ๊กตาจากวัสดุอื่น หรือของเล่น</span>
              </td>
            </tr>

            <tr>
              <td>55<br>
                  <input type="checkbox" id="q55_pass" name="q55_pass" value="1">
                  <label for="q55_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q55_fail" name="q55_fail" value="1">
                  <label for="q55_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ชี้อวัยวะได้ 1 ส่วน (RL)<br><br>
              </td>
              <td>
                1. ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กก่อนว่า เด็กรู้จักอวัยวะของร่างกายส่วนไหนบ้าง<br>
                2. ถามเด็กว่า “…อยู่ไหน” โดยถามเหมือนเดิม 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถชี้อวัยวะ ได้ถูกต้อง 2 ใน 3 ครั้ง
              </td>
              <td>
               1. เริ่มฝึกจากการชี้อวัยวะของพ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็กให้เด็กดู <br>
               2. หลังจากนั้นชี้ชวนให้เด็กทำตาม โดยชี้อวัยวะของตัวเองทีละส่วน <br>
               3. ถ้าเด็กชี้ไม่ได้ให้จับมือเด็กชี้ให้ถูกต้อง และลดการช่วยเหลือลงจนเด็กสามารถชี้ได้เอง โดยอาจใช้เพลงเข้ามาประกอบในการทำกิจกรรม <br>
               4. ถ้าเด็กรู้จักอวัยวะด้วยภาษาหลัก (Mother Tongue Language)คล่องแล้ว อาจเสริมด้วยภาษาที่ 2 เช่น ภาษาอังกฤษ เป็นต้น
              </td>
            </tr>

            <tr>
              <td>56<br>
                  <input type="checkbox" id="q56_pass" name="q56_pass" value="1">
                  <label for="q56_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q56_fail" name="q56_fail" value="1">
                  <label for="q56_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดเลียนคำที่เด่น หรือคำสุดท้ายของคำพูด (EL)<br><br>
              <td>
                พูดคุยกับเด็กเป็นประโยคหรือวลีสั้น ๆไม่เกิน 3 คำ แล้วสังเกตการโต้ตอบของเด็ก<br>
                <strong>ผ่าน:</strong> เด็กเลียนคำพูดที่เด่น หรือคำสุดท้ายของคำพูด เช่น “หนูเป็นเด็กดี”เด็กเลียนคำ “เด็ก” หรือ “ดี” ได้
              </td>
              <td>
               1. พูดกับเด็กก่อนแล้วค่อยทำกริยานั้นให้เด็กดู เช่น เมื่อแต่งตัวเสร็จพูดว่า “ไปกินข้าว” แล้วออกเสียง “กิน” หรือ “ข้าว” ให้เด็กฟังแล้วจึงพาไป <br>
               2. สอนให้เด็กพูดตามความจริง เช่น
               - ขณะแต่งตัว เมื่อเด็กให้ความร่วมมือดี ให้ชมเชยว่า“หนูเป็นเด็กดี” เพื่อให้เด็กเลียนค�ำ “เด็ก” หรือ “ดี” ได้
               - เมื่อแต่งตัวเสร็จ พูดว่า “ไปกินข้าว” รอให้เด็กออกเสียง“กิน” หรือ “ข้าว” ก่อนแล้วจึงพาไป <br>
               3. ถ้าเด็กไม่ออกเสียงพูดตาม ให้ซ้ำคำเด่นหรือคำสุดท้ายนั้น จนเด็กสามารถเลียนคำพูดสุดท้ายนั้นได้ <br>
               4. เมื่อเด็กพูดได้แล้ว ให้ความสนใจและพูดโต้ตอบกับเด็กโดยเปลี่ยนใช้คำอื่น ๆ ตามสถานการณ์ต่าง ๆ
              </td>
            </tr>

            <tr>
              <td>57<br>
                  <input type="checkbox" id="q57_pass" name="q57_pass" value="1">
                  <label for="q57_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q57_fail" name="q57_fail" value="1">
                  <label for="q57_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดเป็นคำๆ ได้ 4 คำ เรียกชื่อสิ่งของหรือทักทาย (ต้องเป็นคำอื่นที่ไม่ใช่คำว่าพ่อแม่ ชื่อของคนคุ้นเคย หรือชื่อของสัตว์เลี้ยงในบ้าน) (EL)<br><br>
              </td>
              <td>
                ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กพูดเป็นคำ ๆ หรือรู้จักชื่อสิ่งของอะไรบ้าง<br>
                <strong>ผ่าน:</strong> เด็กพูดได้อย่างน้อย 4 คำ เช่นทักทาย - สวัสดี และเรียกชื่อสิ่งของต่าง ๆเช่น โต๊ะ แมว
              </td>
              <td>
               สอนให้เด็กพูดคำสั้น ๆ ตามเหตุการณ์จริง เช่น
               - เมื่อพบหน้าผู้ใหญ่ให้พูดทักทายคำว่า “สวัสดีค่ะ/ครับ” หรือใช้คำที่ทักทายในท้องถิ่น เช่น ธุจ้า ทุกครั้ง
               - ขณะรับประทานอาหาร ก่อนป้อนข้าวพูด “ข้าว” ให้เด็กพูดตาม “ข้าว”
               - ขณะกำลังดูหนังสือฝึกให้เด็กพูดคำต่าง ๆ ตามรูปภาพ เช่น“ปลา” “โต๊ะ” “แมว”
              </td>
            </tr>

            <tr>
              <td>58<br>
                  <input type="checkbox" id="q58_pass" name="q58_pass" value="1">
                  <label for="q58_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q58_fail" name="q58_fail" value="1">
                  <label for="q58_fail">ไม่ผ่าน</label><br>
              </td>
              <td>สนใจและมองตามสิ่งที่ผู้ใหญ่ชี้ที่อยู่ไกลออกไปประมาณ 3 เมตร (PS)<br><br>
                </td>
              <td>
                ชี้สิ่งที่อยู่ไกลประมาณ 3 เมตร เช่นหลอดไฟ นาฬิกา แล้วพูดชื่อสิ่งของเช่น “โน่นหลอดไฟ” “โน่นนาฬิกา” แล้วสังเกตว่าเด็กมองตามได้หรือไม่<br>
                <strong>ผ่าน:</strong> เด็กหันมาสนใจและมองตาม เมื่อชี้สิ่งที่อยู่ไกลออกไป อย่างน้อย 3 เมตร
              </td>
              <td>
               ชี้สิ่งที่อยู่ใกล้ตัวให้เด็กมองตาม หากเด็กยังไม่มองให้ประคองหน้าเด็กให้หันมองตาม แล้วค่อยชี้ของที่อยู่ไกลออกไป จนถึง 3 เมตร<br>
               <strong>หมายเหตุ:</strong> ของควรจะเป็นของชิ้นใหญ่และมีสีสดใส<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อควบคุมตนเองให้สนใจร่วมกับผู้อื่น</span>
              </td>
            </tr>

            <tr>
              <td>59<br>
                  <input type="checkbox" id="q59_pass" name="q59_pass" value="1">
                  <label for="q59_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q59_fail" name="q59_fail" value="1">
                  <label for="q59_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ดื่มน้ำจากถ้วยโดยไม่หก (PS)<br><br>
                <strong>อุปกรณ์:</strong> ถ้วยฝึกดื่มมีหูใส่น้ำ 1/4 ถ้วย <br>
              <img src="../image/evaluation_pic/ถ้วยฝึกดื่ม.png" alt="Family" style="width: 120px; height: 90px;"><br></td></td>
              <td>
                ส่งถ้วยที่มีน้ำ 1/4 ของถ้วยให้เด็กแล้วบอกเด็กให้ดื่มน้ำ สังเกตการดื่มของเด็ก หรือถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ว่า“เด็กสามารถยกถ้วยหรือขันน้ำขึ้นดื่มโดย
                ไม่หกได้หรือไม่”<br>
                <strong>ผ่าน:</strong>  เด็กยกถ้วยหรือขันน้ำขึ้นดื่มโดยไม่หก(โดยไม่ใช่การดูดจากหลอดดูด)
              </td>
              <td>
               1. ประคองมือเด็กให้ยกถ้วยน้ำขึ้นดื่ม ค่อย ๆ ลดการช่วยเหลือจนเด็กสามารถถือถ้วยน้ำยกขึ้นดื่มโดยไม่หก<br>
               2. ฝึกเด็กดื่มนมและน้ำจากถ้วย (เลิกใช้ขวดนม) <br>
               <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> ขันน้ำ</span>
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
        <!-- Card ข้อที่ 50 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 50 - วิ่งได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q50_pass_mobile" name="q50_pass" value="1">
                <label class="form-check-label text-success" for="q50_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q50_fail_mobile" name="q50_fail" value="1">
                <label class="form-check-label text-danger" for="q50_fail_mobile">
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
              <p class="text-muted">วิ่งเล่นกับเด็ก หรืออาจกลิ้งลูกบอลแล้วกระตุ้นให้เด็กวิ่งตามลูกบอล</p>
              <p><strong>ผ่าน:</strong> เด็กวิ่งได้อย่างมั่นคงโดยไม่ล้ม และ ไม่ใช่การเดินเร็ว</p>
            </div>
            <div class="accordion" id="training50">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading50">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse50">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse50" class="accordion-collapse collapse" data-bs-parent="#training50">
                  <div class="accordion-body">
                    1. จับมือเด็กวิ่งเล่น หรือร่วมวิ่งกับเด็กคนอื่น ๆ เพื่อให้เด็กสนุกสนาน<br>
                    2. ลดการช่วยเหลือลง เมื่อเด็กมั่นใจและเริ่มวิ่งได้ดีขึ้นจนเด็กสามารถวิ่งได้เอง<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> วัสดุมาทำเป็นก้อนกลม ๆ เช่น ก้อนฟางลูกบอลสานด้วยใบมะพร้าว</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 51 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 51 - เดินถือลูกบอลไปได้ไกล 3 เมตร (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q51_pass_mobile" name="q51_pass" value="1">
                <label class="form-check-label text-success" for="q51_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q51_fail_mobile" name="q51_fail" value="1">
                <label class="form-check-label text-danger" for="q51_fail_mobile">
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
              <p class="text-muted">1. จัดให้เด็กและพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กยืนหันหน้าเข้าหากันระยะห่าง 3 เมตร<br>
              2. ส่งลูกบอลให้เด็กถือ และบอกให้เด็กเดินไปหาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเดินถือลูกบอล ไปได้ไกล 3 เมตร โดยไม่ล้ม และไม่เสียการทรงตัว</p>
            </div>
            <div class="accordion" id="training51">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading51">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse51">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse51" class="accordion-collapse collapse" data-bs-parent="#training51">
                  <div class="accordion-body">
                    1. ฝึกให้เด็กเดินโดยถือของมือเดียว<br>
                    2. เมื่อเด็กทำได้แล้วให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก วางตะกร้าไว้ในระยะห่าง 3 เมตร แล้วถือของที่มีขนาดใหญ่ขึ้นด้วยสองมือและเดินเอาของไปใส่ตะกร้าให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
                    3. ถ้าเด็กทำไม่ได้ ให้ขยับตะกร้าให้ใกล้ขึ้น และจับมือเด็กถือของช่วยพยุงหากเด็กยังทรงตัวได้ไม่ดี<br>
                    4. เมื่อเด็กทรงตัวได้ดีและถือของได้ด้วยตนเองให้เพิ่มระยะทางจนถึง 3 เมตร<br>
                    <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> วัสดุในบ้าน เช่น ตุ๊กตา หมอน</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 52 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 52 - เปิดหน้าหนังสือที่ทำด้วยกระดาษแข็งทีละแผ่นได้เอง (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q52_pass_mobile" name="q52_pass" value="1">
                <label class="form-check-label text-success" for="q52_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q52_fail_mobile" name="q52_fail" value="1">
                <label class="form-check-label text-danger" for="q52_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>หนังสือรูปภาพทำด้วยกระดาษแข็ง</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางหนังสือไว้ตรงหน้าเด็ก แสดงวิธีการเปิดหนังสือให้เด็กดู และบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถแยกหน้าและพลิกหน้าหนังสือได้ทีละแผ่นด้วยตนเองอย่างน้อย 1 แผ่น</p>
            </div>
            <div class="accordion" id="training52">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading52">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse52">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse52" class="accordion-collapse collapse" data-bs-parent="#training52">
                  <div class="accordion-body">
                    1. เปิดหน้าหนังสือทีละหน้าแล้วชี้ให้เด็กดูรูปภาพและปิดหนังสือ<br>
                    2. บอกให้เด็กทำตาม<br>
                    3. ถ้าเด็กทำไม่ได้ให้ช่วยจับมือเด็กพลิกหน้าหนังสือทีละหน้า<br>
                    4. เล่านิทานประกอบรูปภาพ เพื่อเสริมสร้างจินตนาการของเด็ก<br>
                    <span style="color: red;"><strong>หนังสือที่ใช้แทนได้:</strong> หนังสือเด็กที่ทำด้วยพลาสติก ผ้า หรือกระดาษหนา ๆ</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 53 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 53 - ต่อก้อนไม้ 2 ชั้น (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q53_pass_mobile" name="q53_pass" value="1">
                <label class="form-check-label text-success" for="q53_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q53_fail_mobile" name="q53_fail" value="1">
                <label class="form-check-label text-danger" for="q53_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ก้อนไม้สี่เหลี่ยม ลูกบาศก์ 4 ก้อน</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางก้อนไม้ 4 ก้อน ตรงหน้าเด็ก<br>
              2. ต่อก้อนไม้ 2 ชั้นให้เด็กดูแล้วรื้อแบบออก<br>
              3. กระตุ้นให้เด็กต่อก้อนไม้เองให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถต่อก้อนไม้โดยไม่ล้ม 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training53">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading53">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse53">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse53" class="accordion-collapse collapse" data-bs-parent="#training53">
                  <div class="accordion-body">
                    1. ใช้วัตถุที่เป็นทรงสี่เหลี่ยม เช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู<br>
                    2. กระตุ้นให้เด็กทำตาม<br>
                    3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1<br>
                    4. ทำซ้ำหลายครั้งและลดการช่วยเหลือลง จนเด็กต่อก้อนไม้ได้เองหากเด็กทำได้แล้วให้ชมเชย เพิ่มความภาคภูมิใจในตนเอง หากเด็กต่อได้ 2 ชั้น แล้วให้เปลี่ยนเป็นต่อมากกว่า 2 ชั้น<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 54 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 54 - เลือกวัตถุตามคำสั่งได้ถูกต้อง 3 ชนิด (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q54_pass_mobile" name="q54_pass" value="1">
                <label class="form-check-label text-success" for="q54_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q54_fail_mobile" name="q54_fail" value="1">
                <label class="form-check-label text-danger" for="q54_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ตุ๊กตาผ้า บอล รถ</p>
              <p><strong>หมายเหตุ:</strong> ในกรณีที่มีข้อขัดข้องทางสังคมและวัฒนธรรมให้ใช้ถ้วยหรือหนังสือที่เป็นชุดอุปกรณ์ DSPM แทนได้</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางวัตถุ 3 ชนิดไว้ตรงหน้าเด็กแล้วถามว่า "…อยู่ไหน" จนครบทั้ง 3 ชนิด แล้วจึงสลับตำแหน่งที่วางวัตถุ ให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถชี้หรือหยิบวัตถุได้ถูกต้องทั้ง 3 ชนิด อย่างน้อย 2 ครั้ง</p>
            </div>
            <div class="accordion" id="training54">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading54">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse54">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse54" class="accordion-collapse collapse" data-bs-parent="#training54">
                  <div class="accordion-body">
                    1. เตรียมของเล่นหรือวัตถุที่เด็กคุ้นเคย 2 ชนิด และบอกให้เด็กรู้จักชื่อวัตถุทีละชนิด<br>
                    2. ถามเด็ก "…อยู่ไหน" โดยให้เด็กชี้หรือหยิบ ถ้าเด็กเลือกไม่ถูกต้องให้เลื่อนของเข้าไปใกล้ และจับมือเด็กชี้หรือหยิบ<br>
                    3. เมื่อเด็กสามารถเลือกได้ถูกต้อง เพิ่มของเล่นหรือวัตถุที่เด็กคุ้นเคย เป็น 3 ชนิด และถามเช่นเดิมจนเด็กชี้หรือหยิบได้ถูกต้องทั้ง 3 ชนิด<br>
                    4. เพิ่มวัตถุชนิดอื่นที่เด็กสนใจชี้ให้เด็กดู แล้วพูดให้เด็กชี้ เพื่อเพิ่มการเรียนรู้ภาษาของเด็ก<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย เช่น แก้วน้ำ ถ้วย ช้อน แปรง หวี ตุ๊กตาจากวัสดุอื่น หรือของเล่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 55 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 55 - ชี้อวัยวะได้ 1 ส่วน (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q55_pass_mobile" name="q55_pass" value="1">
                <label class="form-check-label text-success" for="q55_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q55_fail_mobile" name="q55_fail" value="1">
                <label class="form-check-label text-danger" for="q55_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กก่อนว่า เด็กรู้จักอวัยวะของร่างกายส่วนไหนบ้าง<br>
              2. ถามเด็กว่า "…อยู่ไหน" โดยถามเหมือนเดิม 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถชี้อวัยวะ ได้ถูกต้อง 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training55">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading55">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse55">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse55" class="accordion-collapse collapse" data-bs-parent="#training55">
                  <div class="accordion-body">
                    1. เริ่มฝึกจากการชี้อวัยวะของพ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็กให้เด็กดู<br>
                    2. หลังจากนั้นชี้ชวนให้เด็กทำตาม โดยชี้อวัยวะของตัวเองทีละส่วน<br>
                    3. ถ้าเด็กชี้ไม่ได้ให้จับมือเด็กชี้ให้ถูกต้อง และลดการช่วยเหลือลงจนเด็กสามารถชี้ได้เอง โดยอาจใช้เพลงเข้ามาประกอบในการทำกิจกรรม<br>
                    4. ถ้าเด็กรู้จักอวัยวะด้วยภาษาหลัก (Mother Tongue Language)คล่องแล้ว อาจเสริมด้วยภาษาที่ 2 เช่น ภาษาอังกฤษ เป็นต้น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 56 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 56 - พูดเลียนคำที่เด่น หรือคำสุดท้ายของคำพูด (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q56_pass_mobile" name="q56_pass" value="1">
                <label class="form-check-label text-success" for="q56_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q56_fail_mobile" name="q56_fail" value="1">
                <label class="form-check-label text-danger" for="q56_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">พูดคุยกับเด็กเป็นประโยคหรือวลีสั้น ๆไม่เกิน 3 คำ แล้วสังเกตการโต้ตอบของเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กเลียนคำพูดที่เด่น หรือคำสุดท้ายของคำพูด เช่น "หนูเป็นเด็กดี"เด็กเลียนคำ "เด็ก" หรือ "ดี" ได้</p>
            </div>
            <div class="accordion" id="training56">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading56">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse56">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse56" class="accordion-collapse collapse" data-bs-parent="#training56">
                  <div class="accordion-body">
                    1. พูดกับเด็กก่อนแล้วค่อยทำกริยานั้นให้เด็กดู เช่น เมื่อแต่งตัวเสร็จพูดว่า "ไปกินข้าว" แล้วออกเสียง "กิน" หรือ "ข้าว" ให้เด็กฟังแล้วจึงพาไป<br>
                    2. สอนให้เด็กพูดตามความจริง เช่น<br>
                    - ขณะแต่งตัว เมื่อเด็กให้ความร่วมมือดี ให้ชมเชยว่า"หนูเป็นเด็กดี" เพื่อให้เด็กเลียนคำ "เด็ก" หรือ "ดี" ได้<br>
                    - เมื่อแต่งตัวเสร็จ พูดว่า "ไปกินข้าว" รอให้เด็กออกเสียง"กิน" หรือ "ข้าว" ก่อนแล้วจึงพาไป<br>
                    3. ถ้าเด็กไม่ออกเสียงพูดตาม ให้ซ้ำคำเด่นหรือคำสุดท้ายนั้น จนเด็กสามารถเลียนคำพูดสุดท้ายนั้นได้<br>
                    4. เมื่อเด็กพูดได้แล้ว ให้ความสนใจและพูดโต้ตอบกับเด็กโดยเปลี่ยนใช้คำอื่น ๆ ตามสถานการณ์ต่าง ๆ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 57 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 57 - พูดเป็นคำๆ ได้ 4 คำ เรียกชื่อสิ่งของหรือทักทาย (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q57_pass_mobile" name="q57_pass" value="1">
                <label class="form-check-label text-success" for="q57_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q57_fail_mobile" name="q57_fail" value="1">
                <label class="form-check-label text-danger" for="q57_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กพูดเป็นคำ ๆ หรือรู้จักชื่อสิ่งของอะไรบ้าง</p>
              <p><strong>ผ่าน:</strong> เด็กพูดได้อย่างน้อย 4 คำ เช่นทักทาย - สวัสดี และเรียกชื่อสิ่งของต่าง ๆเช่น โต๊ะ แมว</p>
              <p><strong>หมายเหตุ:</strong> ต้องไม่ใช่ชื่อคนหรือชื่อสัตว์เลี้ยงในบ้าน</p>
            </div>
            <div class="accordion" id="training57">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading57">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse57">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse57" class="accordion-collapse collapse" data-bs-parent="#training57">
                  <div class="accordion-body">
                    สอนให้เด็กพูดคำสั้น ๆ ตามเหตุการณ์จริง เช่น<br>
                    - เมื่อพบหน้าผู้ใหญ่ให้พูดทักทายคำว่า "สวัสดีค่ะ/ครับ" หรือใช้คำที่ทักทายในท้องถิ่น เช่น ธุจ้า ทุกครั้ง<br>
                    - ขณะรับประทานอาหาร ก่อนป้อนข้าวพูด "ข้าว" ให้เด็กพูดตาม "ข้าว"<br>
                    - ขณะกำลังดูหนังสือฝึกให้เด็กพูดคำต่าง ๆ ตามรูปภาพ เช่น"ปลา" "โต๊ะ" "แมว"
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 58 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 58 - สนใจและมองตามสิ่งที่ผู้ใหญ่ชี้ที่อยู่ไกลออกไปประมาณ 3 เมตร (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q58_pass_mobile" name="q58_pass" value="1">
                <label class="form-check-label text-success" for="q58_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q58_fail_mobile" name="q58_fail" value="1">
                <label class="form-check-label text-danger" for="q58_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ชี้สิ่งที่อยู่ไกลประมาณ 3 เมตร เช่นหลอดไฟ นาฬิกา แล้วพูดชื่อสิ่งของเช่น "โน่นหลอดไฟ" "โน่นนาฬิกา" แล้วสังเกตว่าเด็กมองตามได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กหันมาสนใจและมองตาม เมื่อชี้สิ่งที่อยู่ไกลออกไป อย่างน้อย 3 เมตร</p>
            </div>
            <div class="accordion" id="training58">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading58">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse58">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse58" class="accordion-collapse collapse" data-bs-parent="#training58">
                  <div class="accordion-body">
                    ชี้สิ่งที่อยู่ใกล้ตัวให้เด็กมองตาม หากเด็กยังไม่มองให้ประคองหน้าเด็กให้หันมองตาม แล้วค่อยชี้ของที่อยู่ไกลออกไป จนถึง 3 เมตร<br>
                    <strong>หมายเหตุ:</strong> ของควรจะเป็นของชิ้นใหญ่และมีสีสดใส<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อควบคุมตนเองให้สนใจร่วมกับผู้อื่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 59 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 59 - ดื่มน้ำจากถ้วยโดยไม่หก (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 18 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q59_pass_mobile" name="q59_pass" value="1">
                <label class="form-check-label text-success" for="q59_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q59_fail_mobile" name="q59_fail" value="1">
                <label class="form-check-label text-danger" for="q59_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>ถ้วยฝึกดื่มมีหูใส่น้ำ 1/4 ถ้วย</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ส่งถ้วยที่มีน้ำ 1/4 ของถ้วยให้เด็กแล้วบอกเด็กให้ดื่มน้ำ สังเกตการดื่มของเด็ก หรือถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ว่า"เด็กสามารถยกถ้วยหรือขันน้ำขึ้นดื่มโดยไม่หกได้หรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กยกถ้วยหรือขันน้ำขึ้นดื่มโดยไม่หก(โดยไม่ใช่การดูดจากหลอดดูด)</p>
            </div>
            <div class="accordion" id="training59">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading59">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse59">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse59" class="accordion-collapse collapse" data-bs-parent="#training59">
                  <div class="accordion-body">
                    1. ประคองมือเด็กให้ยกถ้วยน้ำขึ้นดื่ม ค่อย ๆ ลดการช่วยเหลือจนเด็กสามารถถือถ้วยน้ำยกขึ้นดื่มโดยไม่หก<br>
                    2. ฝึกเด็กดื่มนมและน้ำจากถ้วย (เลิกใช้ขวดนม)<br>
                    <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> ขันน้ำ</span>
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
      for (let i = 50; i <= 59; i++) {
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
      for (let i = 50; i <= 59; i++) {
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

      for (let i = 50; i <= 59; i++) {
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

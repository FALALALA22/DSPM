<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '42';

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
    $sql = "SELECT * FROM children WHERE chi_id = ? AND user_id = ?";
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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 90-100)
    for ($i = 90; $i <= 100; $i++) {
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
    $version_sql = "SELECT MAX(eva_version) as max_version FROM evaluations WHERE chi_id = ? AND eva_age_range = ? AND eva_evaluation_date = ?";
    $version_stmt = $conn->prepare($version_sql);
    $version_stmt->bind_param("iss", $child_id, $age_range, $evaluation_date);
    $version_stmt->execute();
    $version_result = $version_stmt->get_result()->fetch_assoc();
    $next_version = ($version_result['max_version'] ?? 0) + 1;
    $version_stmt->close();
    
    // เพิ่มข้อมูลใหม่เสมอ (ไม่แทนที่)
    $insert_sql = "INSERT INTO evaluations (chi_id, user_id, eva_age_range, eva_responses, eva_total_score, eva_total_questions, eva_evaluation_date, eva_evaluation_time, eva_version, eva_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $total_questions = 11; // แบบประเมินมีทั้งหมด 11 ข้อ (ข้อ 90-100)
    $stmt->bind_param("iisssissis", $child_id, $user['user_id'], $age_range, $evaluation_json, $total_passed, $total_questions, $evaluation_date, $evaluation_time, $next_version, $notes);
    
    if ($stmt->execute()) {
      $evaluation_id = $conn->insert_id;
      if ($evaluation_id) {
        $upd = $conn->prepare('UPDATE evaluations SET eva_id = ? WHERE eva_id_auto = ?');
        if ($upd) {
          $upd->bind_param('ii', $evaluation_id, $evaluation_id);
          $upd->execute();
          $upd->close();
        }
      }
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
$latest_sql = "SELECT * FROM evaluations WHERE chi_id = ? AND eva_age_range = ? ORDER BY eva_evaluation_date DESC, eva_version DESC LIMIT 1";
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
  <title>แบบประเมิน ช่วงอายุ 42 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/eva.css">
  <link rel="stylesheet" href="../css/test.css">
  <style>
    /* Page-specific styles for evaluation16.php: yellow background, green text */
    .page-eva16 .table-color { background-color: #FFEB3B !important; color: #0b6623 !important; text-align: center; }
    .page-eva16 table { color: #0b6623 !important; }
    .page-eva16 .bgeva1 { background-color: #FFEB3B !important; color: #0b6623 !important; }
    .page-eva16 .card-header.bgeva1.text-white { color: #0b6623 !important; }
  </style>
</head>
<body class="bg-light page-eva16">
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 42 เดือน
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
            <!-- ข้อ 90-100 สำหรับ 42 เดือน -->
            <tr>
              <td rowspan="11">42 เดือน</td>
              <td>90<br>
                  <input type="checkbox" id="q90_pass" name="q90_pass" value="1">
                  <label for="q90_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q90_fail" name="q90_fail" value="1">
                  <label for="q90_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนขาเดียว 5 วินาที (GM)<br><br>
                </td>
              </td>
              <td>
                แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (ให้เปลี่ยนขาได้)โดยไม่ยึดเกาะ<br>
                <strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 5 วินาทีอย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
               1. ยืนบนขาข้างเดียวให้เด็กดู<br>
               2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
               3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตาม เมื่อเด็กยืนได้ให้เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
               4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
              </td>
            </tr>

            <tr>
              <td>91<br>
                  <input type="checkbox" id="q91_pass" name="q91_pass" value="1">
                  <label for="q91_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q91_fail" name="q91_fail" value="1">
                  <label for="q91_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใช้แขนรับลูกบอลได้ (GM)<br><br>
              <strong>อุปกรณ์:</strong> ลูกบอลขนาดเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร<br>
              <img src="../image/evaluation_pic/ball_15_20.png" alt="Ball" style="width: 150px; height: 150px;">
                </td>
              <td>
               1. ยืนห่างจากเด็ก 2 เมตร<br>
               2. บอกให้เด็กยื่นแขนออกมารับลูกบอลแล้วโยนลูกบอลไปที่เด็ก <br>
                <strong>ผ่าน:</strong>  เด็กสามารถยื่นแขนมารับและถือลูกบอลไว้ได้ 1 ใน 3 ครั้ง
              </td>
              <td>
                1. เล่นโยนลูกโป่งกับเด็ก โดยโยนลูกโป่งขึ้นไปแล้วบอกให้เด็กรับให้ได้ เด็กก็จะรับลูกโป่งได้ง่ายเพราะมีเวลาเตรียมตัวนานก่อนที่
                ลูกโป่งจะตกลงมาถึง ทำให้สามารถปรับตัวและปรับการทำงานของมือกับตาได้ทัน<br>
                2. จับท่ารับให้เด็กโดยหงายมือ งอข้อศอก ใช้แขนโอบรับ<br>
                3. ลดการช่วยเหลือลงจากจับมือเป็นจับแขนจนเด็กรับได้เอง<br>
                4. เมื่อรับลูกโป่งได้คล่องแล้ว ให้เปลี่ยนเป็นลูกบอล<br>
                5. เมื่อรับลูกบอลได้คล่องแล้ว เพิ่มระยะห่างที่จะรับลูกบอลเป็น1 – 2 เมตร<br>
                <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  ลูกโป่ง ผ้ามัดเป็นก้อน </span>
              </td>
            </tr>

            <tr>
              <td>92<br>
                  <input type="checkbox" id="q92_pass" name="q92_pass" value="1">
                  <label for="q92_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q92_fail" name="q92_fail" value="1">
                  <label for="q92_fail">ไม่ผ่าน</label><br>
              </td>
              <td>แยกรูปทรงเรขาคณิตได้ 3 แบบ (FM)<br><br>
              <strong>อุปกรณ์ :</strong> รูปทรงเรขาคณิตที่มีสีเดียวกันทั้งหมด ได้แก่ <br>
              1. รูปทรงกระบอกสั้น 3 ชิ้น <br>
              2. รูปทรงสามเหลี่ยม 3 ชิ้น <br>
              3. รูปทรงสี่เหลี่ยม 3 ชิ้น <br>
              <img src="../image/evaluation_pic/รูปทรงเรขาคณิต.png" alt="Shapes" style="width: 200px; height: 120px;">
            </td>
              <td>
                1. วางรูปทรง ทั้ง 9 ชิ้นคละกัน ไว้ตรงหน้าเด็ก<br>
                2. แสดงวิธีแยกรูปทรงให้เด็กดู และพูดว่า“ครูจะวางรูปทรงกระบอกสั้นไว้ด้วยกันทรงสี่เหลี่ยมไว้ด้วยกัน และทรงสามเหลี่ยมไว้ด้วยกัน” แล้วรื้อออก<br>
                3. คละรูปทรงทั้งหมดและยื่นให้เด็กและบอกเด็กว่า “ลองทำเหมือนครู ซิคะ/ครับ”<br>
                <strong>ผ่าน:</strong>  เด็กสามารถแยกรูปทรงชนิดเดียวกันเป็นกลุ่มได้ถูกต้องทั้ง 3 แบบ
              </td>
              <td>
                1. สอนให้เด็กรู้จักรูปทรง โดยหยิบรูปทรงกระบอกสั้นวางไว้ในจานที่ 1 รูปทรงสามเหลี่ยมวางไว้ในจานที่ 2 รูปทรงสี่เหลี่ยมวางไว้ในจานที่ 3<br>
                2. บอกให้เด็กหยิบรูปทรงวางไว้ในจานที่มีรูปทรงนั้น จนครบทั้งหมด<br>
                3. ลดการช่วยเหลือลงจนเด็กสามารถทำได้เอง<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> สิ่งของในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย<br>
                - รูปทรงกระบอกสั้น เช่น ฝาขวดน้ำดื่ม<br>
                - รูปทรงสี่เหลี่ยม เช่น กล่อง<br>
                - รูปทรงสามเหลี่ยม เช่น ขนมเทียน พับใบตองเป็นสามเหลี่ยม</span><br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong>  ฝึกความจำ ความเข้าใจ วิเคราะห์และแก้ปัญหาการแยกหมวดหมู่รูปทรงได้</span>
              </td>
            </tr>

            <tr>
              <td>93<br>
                  <input type="checkbox" id="q93_pass" name="q93_pass" value="1">
                  <label for="q93_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q93_fail" name="q93_fail" value="1">
                  <label for="q93_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ประกอบชิ้นส่วนของรูปภาพที่ถูกตัดออกเป็น 3 ชิ้นได้ (FM)<br><br>
              <strong>อุปกรณ์ :</strong> รูปภาพตัดออกเป็น3 ชิ้น 1 รูป<br>
              <img src="../image/evaluation_pic/รูปภาพตัดออกเป็น 3 ชิ้น.png" alt="Picture Pieces" style="width: 120px; height: 90px;">
              </td>
              <td>
                ให้เด็กดูภาพประกอบชิ้นส่วนที่สมบูรณ์จากนั้นแยกชิ้นส่วนของภาพออกจากกันวางไว้ตรงหน้าเด็ก แล้วบอกให้เด็กประกอบชิ้นส่วนของภาพเข้าด้วยกัน<br>
                <strong>ผ่าน:</strong>  เด็กสามารถต่อชิ้นส่วนของภาพเข้าด้วยกันได้เองถูกต้องอย่างน้อย 1 ใน 3 ครั้ง โดยต่อลงในกรอบ
              </td>
              <td>
                1. วางรูปที่ตัดออกเป็น 3 ชิ้น ตรงหน้าเด็ก และวางอีกภาพต้นแบบไว้ให้เด็กสังเกตรูปภาพนั้น<br>
                2. แยกรูปภาพทั้ง 3 ชิ้น ออกจากกันโดยการขยายรอยต่อให้กว้างขึ้นช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                3. เพิ่มความยากโดยคละชิ้นส่วนของภาพทั้งหมด ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม ถ้าเด็กเริ่มทำได้แล้วปล่อยให้เด็กต่อภาพด้วยตนเอง<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> รูปภาพ รูปการ์ตูน ปฏิทิน ปกสมุด ปกหนังสือที่เหมือนกันทั้ง 2 ชิ้น ตัดชิ้นส่วนเป็น 3 ชิ้น</span><br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong>  ฝึกเชาว์ปัญญา การสังเกต วิเคราะห์ และแก้ปัญหาได้</span><br>
              </td>
            </tr>

            <tr>
              <td>94<br>
                  <input type="checkbox" id="q94_pass" name="q94_pass" value="1">
                  <label for="q94_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q94_fail" name="q94_fail" value="1">
                  <label for="q94_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เขียนรูปวงกลมตามแบบได้ (FM)<br><br>
                <strong>อุปกรณ์ :</strong>  1.ดินสอ 2.กระดาษ<br>
                    3. กระดาษที่มีรูปวงกลมขนาดเส้นผ่านศูนย์กลาง 2.5 ซม.ตามแบบ (ใช้สีเส้นวงกลมหนา ๆบนกระดาษขาว)<br>
                <img src="../image/evaluation_pic/ดินสอ กระดาษ กระดาษรูปวงกลม.png" alt="Circle" style="width: 160px; height: 120px;">
                </td>
              <td>
                ยื่นกระดาษที่มีรูปวงกลมให้เด็กดูพร้อมพูดคำว่า “นี่คือรูปวงกลม หนูลองวาดรูปวงกลม (ตามตัวอย่าง) ให้ดูซิ” พร้อมกับ
                ส่งดินสอให้เด็กโดยไม่แสดงท่าทางการวาดให้เด็กดู<br>
                <strong>ผ่าน:</strong> เด็กสามารถวาดรูปวงกลมที่บรรจบกันตามแบบได้ โดยไม่มีเหลี่ยม ไม่เว้าเกยกันไม่เกิน 2 ซม. (ไม่จำกัดเส้นผ่านศูนย์กลาง)
              </td>
              <td>
               1. วาดรูปวงกลมให้เด็กดู<br>
               2. บอกให้เด็ก วาดรูปวงกลมตาม<br>
               3. ถ้าเด็กวาดไม่ได้ ให้ช่วยจับมือเด็กวาด<br>
               4. เมื่อเด็กเริ่มวาดรูปวงกลมได้แล้ว พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กวาดรูปวงกลมลงในกระดาษ โดยไม่ให้เด็กเห็น แล้วส่งกระดาษที่
               พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กวาดให้เด็กและบอกว่า “หนูลองวาดรูปวงกลมแบบนี้ซิคะ/ครับ”
              </td>
            </tr>

            <tr>
              <td>95<br>
                  <input type="checkbox" id="q95_pass" name="q95_pass" value="1">
                  <label for="q95_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q95_fail" name="q95_fail" value="1">
                  <label for="q95_fail">ไม่ผ่าน</label><br>
              </td>
              <td>วางวัตถุไว้ข้างหน้าและข้างหลังได้ตามคำสั่ง (RL)<br><br>
                <strong>อุปกรณ์ :</strong> 1. ตุ๊กตาผ้า 1 ตัว 2. ก้อนไม้สี่เหลี่ยมลูกบาศก์ 1 ก้อน<br>
                <img src="../image/evaluation_pic/ตุ๊กตาผ้า ก้อนไม้ 1 ก้อน.png" alt="Circle" style="width: 160px; height: 120px;">
                </td>
              <td>
                1. วางตุ๊กตาในท่านั่งตรงหน้าเด็ก<br>
                2. ส่งก้อนไม้ ให้เด็กแล้วพูดว่า “วางก้อนไม้ไว้ข้างหน้าตุ๊กตา” แล้วบอกต่อไปว่า“วางก้อน ไม้ไว้ข้างหลังตุ๊กตา”<br>
                3. เก็บก้อนไม้ออก ให้โอกาสประเมิน 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถวางได้ถูกตำแหน่งทั้งข้างหน้าและข้างหลังอย่างน้อย 2 ใน 3 ครั้ง
              </td>
              <td>
               1. ของเล่นหลาย ๆ ชนิด ที่สามารถวางของข้างหน้าและข้างหลังได้เช่น เก้าอี้ โต๊ะ ถ้วย ก้อนไม้ แสดงวิธีให้เด็กดูพร้อมอธิบาย และชวน
               ให้ทำตาม เช่น “ดูซิก้อนไม้อยู่ข้างหน้าโต๊ะ ก้อนไม้อยู่ข้างหลังโต๊ะ”บอกเด็กให้ “วางก้อนไม้ ไว้ข้างหน้า<br>
               2. ถ้าเด็กไม่ทำตามหรือทำตามไม่ถูก ให้แสดงให้ดูซ้ำ พร้อมทั้งช่วยจับมือเด็กทำหรือชี้ พร้อมกับพูดเน้น “ข้างหน้า” และ “ข้างหลัง”<br>
               3. ขณะเล่นกับเด็กให้วางของเล่นที่คล้าย ๆ กันไว้ข้างหน้า 1 ชิ้นและข้างหลัง 1 ชิ้น พูดบอกให้เด็กหยิบ เช่น “หยิบรถที่อยู่ข้างหลังเก้าอี้ซิคะ/ครับ”
              </td>
            </tr>

            <tr>
              <td>96<br>
                  <input type="checkbox" id="q96_pass" name="q96_pass" value="1">
                  <label for="q96_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q96_fail" name="q96_fail" value="1">
                  <label for="q96_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกจัดกลุ่มวัตถุตามประเภทภาพเสื้อผ้าได้ (RL)<br><br>
                <strong>อุปกรณ์ :</strong> ชุดจัดประเภทสิ่งของ <br>
                1. บัตรภาพสัตว์ 3 ชนิด<br>
                2. บัตรภาพอาหาร 3 ชนิด<br>
                3. บัตรภาพเสื้อผ้า 3 ชนิด <br>
                <img src="../image/evaluation_pic/บัตรภาพจัดประเภทสิ่งของ.png" alt="Picture Cards" style="width: 160px; height: 120px;">
                </td>
              <td>
                วางวัตถุทั้งหมด 3 ประเภท คละกันลงตรงหน้าเด็ก แล้วพูดว่า “เลือกอันที่เป็นเสื้อผ้าส่งให้ ผู้ประเมิน” (ให้เด็กหยิบเพียง 3 ชิ้น)<br>
                <strong>ผ่าน:</strong> เด็กสามารถเลือกภาพเสื้อผ้าได้ถูกต้อง อย่างน้อย 2 ใน 3 ชิ้น
              </td>
              <td>
               1. วางเสื้อผ้าทั้งหมดให้เด็กดูแล้วบอกให้รู้จัก เสื้อผ้าทีละชิ้น เช่น“นี่คือเสื้อผ้า มีกางเกง เสื้อ ถุงเท้า ...”<br>
               2. บอกให้เด็กเลือกเสื้อผ้าทีละชิ้นตามคำบอก จนเด็กสามารถเลือกได้ ทั้ง 3 ชิ้น<br>
               3. นำเสื้อผ้าไปรวมกับวัตถุประเภทอื่น โดยเริ่มจากให้เด็กเลือกวัตถุ2 ประเภท เช่น เสื้อผ้าและสัตว์ เมื่อเด็กเลือกได้ถูกต้อง แล้วจึงเพิ่มประเภทวัตถุเป็น 3 ประเภท<br>
               <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> - รูปเสื้อผ้า/อาหาร/สัตว์ โดยวาดหรือตัดมาจากหนังสือ นิตยสารแผ่นพับ<br>
               - สอนจากของจริง</span><br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกความจำ การแยกประเภทและนำมาประยุกต์เพื่อการเลือก และจัดหมวดหม</span>
              </td>
            </tr>

            <tr>
              <td>97<br>
                  <input type="checkbox" id="q97_pass" name="q97_pass" value="1">
                  <label for="q97_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q97_fail" name="q97_fail" value="1">
                  <label for="q97_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดถึงเหตุการณ์ที่เพิ่งผ่านไปใหม่ ๆ ได้ (EL)<br><br>
                </td>
              <td>
                ถามเด็กว่า “ก่อนมาที่นี่ หนูทำอะไรบ้างจ๊ะ”<br>
                <strong>ผ่าน:</strong> เด็กสามารถเล่าเหตุการณ์ที่เกิดขึ้นที่ผ่านมาให้ผู้ใหญ่หรือเด็กด้วยกันฟังได้อย่างน้อย 1 ครั้ง
              </td>
              <td>
               1. ถามคำถามที่เกี่ยวข้องกับกิจวัตรประจำวันที่เพิ่งผ่านไปชั่วขณะเช่น กินข้าวเสร็จ ล้างหน้า แปรงฟัน เข้าห้องน้ำ กินขนม ดื่มนมฯลฯ
               ทิ้งระยะเวลาสักครู่หนึ่งแล้วถามเด็กว่า “เมื่อกี้หนูไปทำอะไรมาคะ”<br>
               2. ถามถึงเหตุการณ์ที่ผ่านมาระยะเวลานานขึ้น และเปิดโอกาสให้เด็กเล่า ถ้าเด็กตอบไม่ได้ให้เชื่อมโยงเหตุการณ์ที่ทำร่วมกันกับพ่อแม่หรือบุคคลอื่นในครอบครัว<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กเรียบเรียงความคิดเกี่ยวกับเหตุการณ์ที่ผ่านมาและเล่าถ่ายทอดให้ผู้ใหญ่ฟังตามลำดับเหตุการณ์ที่เกิดก่อน
               หลังได้อย่างถูกต้อง เป็นการฝึกความจำขณะทำงาน</span>
              </td>
            </tr>

            <tr>
              <td>98<br>
                  <input type="checkbox" id="q98_pass" name="q98_pass" value="1">
                  <label for="q98_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q98_fail" name="q98_fail" value="1">
                  <label for="q98_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูด “ขอ” หรือ“ขอบคุณ”หรือ “ให้” ได้เอง (EL)<br><br>
                </td>
              <td>
                1. ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “ถ้าเวลาที่เด็กต้องการหรือได้รับของจากผู้อื่น เด็กจะพูด“ขอ”หรือ“ขอบคุณ”หรือ “ให้” ได้หรือไม่<br>
                2. ก่อนยื่นของให้เด็ก รอดูการตอบสนองว่าเด็กพูด “ขอ” หรือไม่<br>
                3. เมื่อเด็กได้รับของแล้ว เด็กพูดคำว่า “ขอบคุณ” หรือไม่<br>
                4. เมื่อเด็กมีของอยู่ในมือ สังเกตหรือถามจากผู้ปกครองว่าเด็กเคยพูดและให้ของคนอื่นหรือไม่<br>
                <strong>ผ่าน:</strong> เด็กสามารถพูด “ขอ” หรือ“ขอบคุณ” หรือ “ให้” ได้เอง 2 ใน 3 ความหมาย
              </td>
              <td>
               1. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กทำตัวอย่างการพูด “ขอ”“ขอบคุณ” “ให้” ให้เด็กได้ยินบ่อย ๆ เช่น<br>
               1.1 พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พูดคำว่า “ขอ.........ให้แม่/พ่อนะจ๊ะ” กับลูกก่อนเวลาที่จะเอาของเล่นหรือขนมจากมือลูกไม่เอาของจากมือเด็กก่อนเด็กยินยอม<br>
               1.2 เมื่อเด็กยื่นของที่อยู่ในมือให้แล้ว พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก พูดคำว่า “ขอบใจจ้ะ/ขอบคุณค่ะ เป็นเด็กดีมากที่รู้จักแบ่งของให้คนอื่น”<br>
               1.3 เวลาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก มีขนมต้องการแบ่งให้ลูกรับประทาน ให้พูดกับเด็กว่า “วันนี้แม่/พ่อซื้อขนมมาด้วย จะแบ่งให้หนูและพี่ ๆ กินด้วยกันนะจ๊ะ กินด้วยกันหลายคนสนุกดี”<br>
               2. สอนและกระตุ้นให้เด็กพูด “ขอ” “ขอบคุณ” “ให้” เช่น<br>
               2.1 ฝึกลูกไม่ให้แย่งของ เช่น ก่อนจะให้ของ ให้เด็กแบมือพูด “ขอ” แล้วจึงให้ของกับเด็ก<br>
               2.2 ให้เด็กพูด “ขอบคุณ” ทุกครั้ง เมื่อมีคนให้ความช่วยเหลือหรือให้ของ<br>
               2.3 ฝึกเด็กให้รู้จักการแบ่งปันและการให้ เช่น แบ่งขนม ของเล่นให้เพื่อน ๆ หรือคนรอบข้าง แล้วบอกให้เด็กพูดว่า “ให้” เวลาทำขนมหรืออาหารให้ชวนเด็กนำอาหารเหล่านี้ไปแบ่งปันเพื่อนบ้าน,
               ชวนเด็กเข้าร่วมกิจกรรมการกุศล เป็นต้น <br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> รู้จักเลือกใช้คำพูดที่เหมาะสมกับสถานการณ์ เพื่อฝึกมารยาทที่ดีทางสังคม เพิ่มความภาคภูมิใจในตนเอง</span>
              </td>
            </tr>

            <tr>
              <td>99<br>
                  <input type="checkbox" id="q99_pass" name="q99_pass" value="1">
                  <label for="q99_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q99_fail" name="q99_fail" value="1">
                  <label for="q99_fail">ไม่ผ่าน</label><br>
              </td>
              <td>บอกเพศของตนเองได้ถูกต้อง (PS)<br><br>
                </td>
              <td>
                1. ถามเด็กว่า “หนูเป็นผู้ชายหรือผู้หญิง”ใช้เพศของเด็กขึ้นต้นก่อน เพื่อหลีกเลี่ยงการที่เด็กพูดตามคำสุดท้าย<br>
                2. เมื่อเด็กตอบแล้วให้ถามกลับไปกลับมา 3 ครั้ง โดยสลับเพศทุกครั้งที่ถาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถตอบถูกต้องทั้ง 2 ใน 3 ครั้ง
              </td>
              <td>
               1. สอนให้เด็กรู้ความแตกต่างของเพศชายและหญิง จากการแต่งตัวทรงผม<br>
               2. บอกให้เด็กรู้ถึงเพศของสมาชิกในครอบครัว เช่น แม่เป็นผู้หญิงพ่อเป็นผู้ชาย หนูเป็นผู้หญิง
              </td>
            </tr>

            <tr>
              <td>100<br>
                  <input type="checkbox" id="q100_pass" name="q100_pass" value="1">
                  <label for="q100_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q100_fail" name="q100_fail" value="1">
                  <label for="q100_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใส่เสื้อผ่าหน้าได้เองโดยไม่ต้องติดกระดุม (PS)<br><br>
                </td>
              <td>
                แสดงหรือถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถใส่เสื้อผ่าหน้าได้เองหรือไม่” <br>
                <strong>ผ่าน:</strong> เด็กสามารถใส่เสื้อผ่าหน้าได้ด้วยตนเองโดยไม่ต้องติดกระดุม (เสื้อมีแขนหรือไม่มีแขนก็ได้) และไม่ใช่การใส่เสื้อให้ตุ๊กตา
              </td>
              <td>
               1. ใส่เสื้อผ่าหน้าให้เด็กดู<br>
               2. นำเสื้อผ่าหน้าคลุมไหล่ของเด็กไว้และจับมือขวาของเด็ก จับคอปกเสื้อด้านซ้ายยกขึ้น (หากเด็กถนัดขวาใส่ด้านขวาก่อนได้)<br>
               3. ให้เด็กสอดแขนซ้ายเข้าไปในแขนเสื้อด้านซ้าย<br>
               4. จับมือซ้ายของเด็ก จับคอปกเสื้อด้านขวาแล้วยกขึ้น<br>
               5. ให้เด็กสอดแขนขวาเข้าไปในแขนเสื้อด้านขวา เมื่อเด็กเริ่มทำได้ลดการช่วยเหลือลง จนเด็กสามารถ ทำได้เองทุกขั้นตอน<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกความจำ และทักษะมาใช้ในชีวิตประจำวันได้อย่างเหมาะสม เพิ่มความภาคภูมิใจในตนเอง</span>
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
        <!-- Card ข้อที่ 90 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 90 - ยืนขาเดียว 5 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q90_pass_mobile" name="q90_pass" value="1">
                <label class="form-check-label text-success" for="q90_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q90_fail_mobile" name="q90_fail" value="1">
                <label class="form-check-label text-danger" for="q90_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (ให้เปลี่ยนขาได้)โดยไม่ยึดเกาะ</p>
              <p><strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 5 วินาทีอย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training90">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading90">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse90">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse90" class="accordion-collapse collapse" data-bs-parent="#training90">
                  <div class="accordion-body">
                    1. ยืนบนขาข้างเดียวให้เด็กดู<br>
                    2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
                    3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตาม เมื่อเด็กยืนได้ให้เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
                    4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 91 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 91 - ใช้แขนรับลูกบอลได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ลูกบอลขนาดเส้นผ่านศูนย์กลาง 15 - 20 เซนติเมตร
              <img src="../image/evaluation_pic/ball_15_20.png" alt="Ball" class="img-fluid mb-2" style="max-width: 150px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q91_pass_mobile" name="q91_pass" value="1">
                <label class="form-check-label text-success" for="q91_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q91_fail_mobile" name="q91_fail" value="1">
                <label class="form-check-label text-danger" for="q91_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ยืนห่างจากเด็ก 2 เมตร<br>
              2. บอกให้เด็กยื่นแขนออกมารับลูกบอลแล้วโยนลูกบอลไปที่เด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยื่นแขนมารับและถือลูกบอลไว้ได้ 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training91">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading91">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse91">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse91" class="accordion-collapse collapse" data-bs-parent="#training91">
                  <div class="accordion-body">
                    1. เล่นโยนลูกโป่งกับเด็ก โดยโยนลูกโป่งขึ้นไปแล้วบอกให้เด็กรับให้ได้ เด็กก็จะรับลูกโป่งได้ง่ายเพราะมีเวลาเตรียมตัวนานก่อนที่ลูกโป่งจะตกลงมาถึง ทำให้สามารถปรับตัวและปรับการทำงานของมือกับตาได้ทัน<br>
                    2. จับท่ารับให้เด็กโดยหงายมือ งอข้อศอก ใช้แขนโอบรับ<br>
                    3. ลดการช่วยเหลือลงจากจับมือเป็นจับแขนจนเด็กรับได้เอง<br>
                    4. เมื่อรับลูกโป่งได้คล่องแล้ว ให้เปลี่ยนเป็นลูกบอล<br>
                    5. เมื่อรับลูกบอลได้คล่องแล้ว เพิ่มระยะห่างที่จะรับลูกบอลเป็น1 – 2 เมตร<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> ลูกโป่ง ผ้ามัดเป็นก้อน</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 92 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 92 - แยกรูปทรงเรขาคณิตได้ 3 แบบ (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> รูปทรงเรขาคณิตที่มีสีเดียวกันทั้งหมด ได้แก่<br>
              1. รูปทรงกระบอกสั้น 3 ชิ้น<br>
              2. รูปทรงสามเหลี่ยม 3 ชิ้น<br>
              3. รูปทรงสี่เหลี่ยม 3 ชิ้น<br>
              <img src="../image/evaluation_pic/รูปทรงเรขาคณิต.png" alt="Geometric Shapes" class="img-fluid mb-2" style="max-width: 200px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q92_pass_mobile" name="q92_pass" value="1">
                <label class="form-check-label text-success" for="q92_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q92_fail_mobile" name="q92_fail" value="1">
                <label class="form-check-label text-danger" for="q92_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางรูปทรง ทั้ง 9 ชิ้นคละกัน ไว้ตรงหน้าเด็ก<br>
              2. แสดงวิธีแยกรูปทรงให้เด็กดู และพูดว่า"ครูจะวางรูปทรงกระบอกสั้นไว้ด้วยกันทรงสี่เหลี่ยมไว้ด้วยกัน และทรงสามเหลี่ยมไว้ด้วยกัน" แล้วรื้อออก<br>
              3. คละรูปทรงทั้งหมดและยื่นให้เด็กและบอกเด็กว่า "ลองทำเหมือนครู ซิคะ/ครับ"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถแยกรูปทรงชนิดเดียวกันเป็นกลุ่มได้ถูกต้องทั้ง 3 แบบ</p>
            </div>
            <div class="accordion" id="training92">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading92">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse92">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse92" class="accordion-collapse collapse" data-bs-parent="#training92">
                  <div class="accordion-body">
                    1. สอนให้เด็กรู้จักรูปทรง โดยหยิบรูปทรงกระบอกสั้นวางไว้ในจานที่ 1 รูปทรงสามเหลี่ยมวางไว้ในจานที่ 2 รูปทรงสี่เหลี่ยมวางไว้ในจานที่ 3<br>
                    2. บอกให้เด็กหยิบรูปทรงวางไว้ในจานที่มีรูปทรงนั้น จนครบทั้งหมด<br>
                    3. ลดการช่วยเหลือลงจนเด็กสามารถทำได้เอง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> สิ่งของในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย<br>
                    - รูปทรงกระบอกสั้น เช่น ฝาขวดน้ำดื่ม<br>
                    - รูปทรงสี่เหลี่ยม เช่น กล่อง<br>
                    - รูปทรงสามเหลี่ยม เช่น ขนมเทียน พับใบตองเป็นสามเหลี่ยม</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกความจำ ความเข้าใจ วิเคราะห์และแก้ปัญหาการแยกหมวดหมู่รูปทรงได้</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 93 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 93 - ประกอบชิ้นส่วนของรูปภาพที่ถูกตัดออกเป็น 3 ชิ้นได้ (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> รูปภาพตัดออกเป็น3 ชิ้น 1 รูป
              <img src="../image/evaluation_pic/รูปภาพตัดออกเป็น 3 ชิ้น.png" alt="Picture Puzzle" class="img-fluid mb-2" style="max-width: 200px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q93_pass_mobile" name="q93_pass" value="1">
                <label class="form-check-label text-success" for="q93_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q93_fail_mobile" name="q93_fail" value="1">
                <label class="form-check-label text-danger" for="q93_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ให้เด็กดูภาพประกอบชิ้นส่วนที่สมบูรณ์จากนั้นแยกชิ้นส่วนของภาพออกจากกันวางไว้ตรงหน้าเด็ก แล้วบอกให้เด็กประกอบชิ้นส่วนของภาพเข้าด้วยกัน</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถต่อชิ้นส่วนของภาพเข้าด้วยกันได้เองถูกต้องอย่างน้อย 1 ใน 3 ครั้ง โดยต่อลงในกรอบ</p>
            </div>
            <div class="accordion" id="training93">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading93">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse93">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse93" class="accordion-collapse collapse" data-bs-parent="#training93">
                  <div class="accordion-body">
                    1. วางรูปที่ตัดออกเป็น 3 ชิ้น ตรงหน้าเด็ก และวางอีกภาพต้นแบบไว้ให้เด็กสังเกตรูปภาพนั้น<br>
                    2. แยกรูปภาพทั้ง 3 ชิ้น ออกจากกันโดยการขยายรอยต่อให้กว้างขึ้นช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                    3. เพิ่มความยากโดยคละชิ้นส่วนของภาพทั้งหมด ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม ถ้าเด็กเริ่มทำได้แล้วปล่อยให้เด็กต่อภาพด้วยตนเอง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> รูปภาพ รูปการ์ตูน ปฏิทิน ปกสมุด ปกหนังสือที่เหมือนกันทั้ง 2 ชิ้น ตัดชิ้นส่วนเป็น 3 ชิ้น</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกเชาว์ปัญญา การสังเกต วิเคราะห์ และแก้ปัญหาได้</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 94 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 94 - เขียนรูปวงกลมตามแบบได้ (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1.ดินสอ 2.กระดาษ<br>
              3. กระดาษที่มีรูปวงกลมขนาดเส้นผ่านศูนย์กลาง 2.5 ซม.ตามแบบ (ใช้สีเส้นวงกลมหนา ๆบนกระดาษขาว)
              <img src="../image/evaluation_pic/ดินสอ กระดาษ กระดาษรูปวงกลม.png" alt="Circle Drawing" class="img-fluid mb-2" style="max-width: 200px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q94_pass_mobile" name="q94_pass" value="1">
                <label class="form-check-label text-success" for="q94_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q94_fail_mobile" name="q94_fail" value="1">
                <label class="form-check-label text-danger" for="q94_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ยื่นกระดาษที่มีรูปวงกลมให้เด็กดูพร้อมพูดคำว่า "นี่คือรูปวงกลม หนูลองวาดรูปวงกลม (ตามตัวอย่าง) ให้ดูซิ" พร้อมกับส่งดินสอให้เด็กโดยไม่แสดงท่าทางการวาดให้เด็กดู</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวาดรูปวงกลมที่บรรจบกันตามแบบได้ โดยไม่มีเหลี่ยม ไม่เว้าเกยกันไม่เกิน 2 ซม. (ไม่จำกัดเส้นผ่านศูนย์กลาง)</p>
            </div>
            <div class="accordion" id="training94">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading94">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse94">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse94" class="accordion-collapse collapse" data-bs-parent="#training94">
                  <div class="accordion-body">
                    1. วาดรูปวงกลมให้เด็กดู<br>
                    2. บอกให้เด็ก วาดรูปวงกลมตาม<br>
                    3. ถ้าเด็กวาดไม่ได้ ให้ช่วยจับมือเด็กวาด<br>
                    4. เมื่อเด็กเริ่มวาดรูปวงกลมได้แล้ว พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กวาดรูปวงกลมลงในกระดาษ โดยไม่ให้เด็กเห็น แล้วส่งกระดาษที่พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กวาดให้เด็กและบอกว่า "หนูลองวาดรูปวงกลมแบบนี้ซิคะ/ครับ"
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 95 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 95 - วางวัตถุไว้ข้างหน้าและข้างหลังได้ตามคำสั่ง (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ตุ๊กตาผ้า 1 ตัว 2. ก้อนไม้สี่เหลี่ยมลูกบาศก์ 1 ก้อน
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า ก้อนไม้ 1 ก้อน.png" alt="Doll and Cube" class="img-fluid mb-2" style="max-width: 200px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q95_pass_mobile" name="q95_pass" value="1">
                <label class="form-check-label text-success" for="q95_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q95_fail_mobile" name="q95_fail" value="1">
                <label class="form-check-label text-danger" for="q95_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางตุ๊กตาในท่านั่งตรงหน้าเด็ก<br>
              2. ส่งก้อนไม้ ให้เด็กแล้วพูดว่า "วางก้อนไม้ไว้ข้างหน้าตุ๊กตา" แล้วบอกต่อไปว่า"วางก้อนไม้ไว้ข้างหลังตุ๊กตา"<br>
              3. เก็บก้อนไม้ออก ให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวางได้ถูกตำแหน่งทั้งข้างหน้าและข้างหลังอย่างน้อย 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training95">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading95">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse95">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse95" class="accordion-collapse collapse" data-bs-parent="#training95">
                  <div class="accordion-body">
                    1. ของเล่นหลาย ๆ ชนิด ที่สามารถวางของข้างหน้าและข้างหลังได้เช่น เก้าอี้ โต๊ะ ถ้วย ก้อนไม้ แสดงวิธีให้เด็กดูพร้อมอธิบาย และชวนให้ทำตาม เช่น "ดูซิก้อนไม้อยู่ข้างหน้าโต๊ะ ก้อนไม้อยู่ข้างหลังโต๊ะ"บอกเด็กให้ "วางก้อนไม้ไว้ข้างหน้า<br>
                    2. ถ้าเด็กไม่ทำตามหรือทำตามไม่ถูก ให้แสดงให้ดูซ้ำ พร้อมทั้งช่วยจับมือเด็กทำหรือชี้ พร้อมกับพูดเน้น "ข้างหน้า" และ "ข้างหลัง"<br>
                    3. ขณะเล่นกับเด็กให้วางของเล่นที่คล้าย ๆ กันไว้ข้างหน้า 1 ชิ้นและข้างหลัง 1 ชิ้น พูดบอกให้เด็กหยิบ เช่น "หยิบรถที่อยู่ข้างหลังเก้าอี้ซิคะ/ครับ"
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 96 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 96 - เลือกจัดกลุ่มวัตถุตามประเภทภาพเสื้อผ้าได้ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ชุดจัดประเภทสิ่งของ<br>
              1. บัตรภาพสัตว์ 3 ชนิด<br>
              2. บัตรภาพอาหาร 3 ชนิด<br>
              3. บัตรภาพเสื้อผ้า 3 ชนิด<br>
              <img src="../image/evaluation_pic/บัตรภาพจัดประเภทสิ่งของ.png" alt="Clothing Cards" class="img-fluid mb-2" style="max-width: 200px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q96_pass_mobile" name="q96_pass" value="1">
                <label class="form-check-label text-success" for="q96_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q96_fail_mobile" name="q96_fail" value="1">
                <label class="form-check-label text-danger" for="q96_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางวัตถุทั้งหมด 3 ประเภท คละกันลงตรงหน้าเด็ก แล้วพูดว่า "เลือกอันที่เป็นเสื้อผ้าส่งให้ ผู้ประเมิน" (ให้เด็กหยิบเพียง 3 ชิ้น)</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเลือกภาพเสื้อผ้าได้ถูกต้อง อย่างน้อย 2 ใน 3 ชิ้น</p>
            </div>
            <div class="accordion" id="training96">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading96">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse96">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse96" class="accordion-collapse collapse" data-bs-parent="#training96">
                  <div class="accordion-body">
                    1. วางเสื้อผ้าทั้งหมดให้เด็กดูแล้วบอกให้รู้จัก เสื้อผ้าทีละชิ้น เช่น"นี่คือเสื้อผ้า มีกางเกง เสื้อ ถุงเท้า ..."<br>
                    2. บอกให้เด็กเลือกเสื้อผ้าทีละชิ้นตามคำบอก จนเด็กสามารถเลือกได้ ทั้ง 3 ชิ้น<br>
                    3. นำเสื้อผ้าไปรวมกับวัตถุประเภทอื่น โดยเริ่มจากให้เด็กเลือกวัตถุ2 ประเภท เช่น เสื้อผ้าและสัตว์ เมื่อเด็กเลือกได้ถูกต้อง แล้วจึงเพิ่มประเภทวัตถุเป็น 3 ประเภท<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> - รูปเสื้อผ้า/อาหาร/สัตว์ โดยวาดหรือตัดมาจากหนังสือ นิตยสารแผ่นพับ<br>
                    - สอนจากของจริง</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกความจำ การแยกประเภทและนำมาประยุกต์เพื่อการเลือก และจัดหมวดหม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 97 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 97 - พูดถึงเหตุการณ์ที่เพิ่งผ่านไปใหม่ ๆ ได้ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q97_pass_mobile" name="q97_pass" value="1">
                <label class="form-check-label text-success" for="q97_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q97_fail_mobile" name="q97_fail" value="1">
                <label class="form-check-label text-danger" for="q97_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามเด็กว่า "ก่อนมาที่นี่ หนูทำอะไรบ้างจ๊ะ"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเล่าเหตุการณ์ที่เกิดขึ้นที่ผ่านมาให้ผู้ใหญ่หรือเด็กด้วยกันฟังได้อย่างน้อย 1 ครั้ง</p>
            </div>
            <div class="accordion" id="training97">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading97">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse97">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse97" class="accordion-collapse collapse" data-bs-parent="#training97">
                  <div class="accordion-body">
                    1. ถามคำถามที่เกี่ยวข้องกับกิจวัตรประจำวันที่เพิ่งผ่านไปชั่วขณะเช่น กินข้าวเสร็จ ล้างหน้า แปรงฟัน เข้าห้องน้ำ กินขนม ดื่มนมฯลฯ ทิ้งระยะเวลาสักครู่หนึ่งแล้วถามเด็กว่า "เมื่อกี้หนูไปทำอะไรมาคะ"<br>
                    2. ถามถึงเหตุการณ์ที่ผ่านมาระยะเวลานานขึ้น และเปิดโอกาสให้เด็กเล่า ถ้าเด็กตอบไม่ได้ให้เชื่อมโยงเหตุการณ์ที่ทำร่วมกันกับพ่อแม่หรือบุคคลอื่นในครอบครัว<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กเรียบเรียงความคิดเกี่ยวกับเหตุการณ์ที่ผ่านมาและเล่าถ่ายทอดให้ผู้ใหญ่ฟังตามลำดับเหตุการณ์ที่เกิดก่อนหลังได้อย่างถูกต้อง เป็นการฝึกความจำขณะทำงาน</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 98 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 98 - พูด "ขอ" หรือ"ขอบคุณ"หรือ "ให้" ได้เอง (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q98_pass_mobile" name="q98_pass" value="1">
                <label class="form-check-label text-success" for="q98_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q98_fail_mobile" name="q98_fail" value="1">
                <label class="form-check-label text-danger" for="q98_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า "ถ้าเวลาที่เด็กต้องการหรือได้รับของจากผู้อื่น เด็กจะพูด"ขอ"หรือ"ขอบคุณ"หรือ "ให้" ได้หรือไม่<br>
              2. ก่อนยื่นของให้เด็ก รอดูการตอบสนองว่าเด็กพูด "ขอ" หรือไม่<br>
              3. เมื่อเด็กได้รับของแล้ว เด็กพูดคำว่า "ขอบคุณ" หรือไม่<br>
              4. เมื่อเด็กมีของอยู่ในมือ สังเกตหรือถามจากผู้ปกครองว่าเด็กเคยพูดและให้ของคนอื่นหรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถพูด "ขอ" หรือ"ขอบคุณ" หรือ "ให้" ได้เอง 2 ใน 3 ความหมาย</p>
            </div>
            <div class="accordion" id="training98">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading98">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse98">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse98" class="accordion-collapse collapse" data-bs-parent="#training98">
                  <div class="accordion-body">
                    1. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กทำตัวอย่างการพูด "ขอ""ขอบคุณ" "ให้" ให้เด็กได้ยินบ่อย ๆ เช่น<br>
                    1.1 พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พูดคำว่า "ขอ.........ให้แม่/พ่อนะจ๊ะ" กับลูกก่อนเวลาที่จะเอาของเล่นหรือขนมจากมือลูกไม่เอาของจากมือเด็กก่อนเด็กยินยอม<br>
                    1.2 เมื่อเด็กยื่นของที่อยู่ในมือให้แล้ว พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก พูดคำว่า "ขอบใจจ้ะ/ขอบคุณค่ะ เป็นเด็กดีมากที่รู้จักแบ่งของให้คนอื่น"<br>
                    1.3 เวลาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก มีขนมต้องการแบ่งให้ลูกรับประทาน ให้พูดกับเด็กว่า "วันนี้แม่/พ่อซื้อขนมมาด้วย จะแบ่งให้หนูและพี่ ๆ กินด้วยกันนะจ๊ะ กินด้วยกันหลายคนสนุกดี"<br>
                    2. สอนและกระตุ้นให้เด็กพูด "ขอ" "ขอบคุณ" "ให้" เช่น<br>
                    2.1 ฝึกลูกไม่ให้แย่งของ เช่น ก่อนจะให้ของ ให้เด็กแบมือพูด "ขอ" แล้วจึงให้ของกับเด็ก<br>
                    2.2 ให้เด็กพูด "ขอบคุณ" ทุกครั้ง เมื่อมีคนให้ความช่วยเหลือหรือให้ของ<br>
                    2.3 ฝึกเด็กให้รู้จักการแบ่งปันและการให้ เช่น แบ่งขนม ของเล่นให้เพื่อน ๆ หรือคนรอบข้าง แล้วบอกให้เด็กพูดว่า "ให้" เวลาทำขนมหรืออาหารให้ชวนเด็กนำอาหารเหล่านี้ไปแบ่งปันเพื่อนบ้าน, ชวนเด็กเข้าร่วมกิจกรรมการกุศล เป็นต้น<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> รู้จักเลือกใช้คำพูดที่เหมาะสมกับสถานการณ์ เพื่อฝึกมารยาทที่ดีทางสังคม เพิ่มความภาคภูมิใจในตนเอง</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 99 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 99 - บอกเพศของตนเองได้ถูกต้อง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q99_pass_mobile" name="q99_pass" value="1">
                <label class="form-check-label text-success" for="q99_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q99_fail_mobile" name="q99_fail" value="1">
                <label class="form-check-label text-danger" for="q99_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามเด็กว่า "หนูเป็นผู้ชายหรือผู้หญิง"ใช้เพศของเด็กขึ้นต้นก่อน เพื่อหลีกเลี่ยงการที่เด็กพูดตามคำสุดท้าย<br>
              2. เมื่อเด็กตอบแล้วให้ถามกลับไปกลับมา 3 ครั้ง โดยสลับเพศทุกครั้งที่ถาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตอบถูกต้องทั้ง 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training99">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading99">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse99">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse99" class="accordion-collapse collapse" data-bs-parent="#training99">
                  <div class="accordion-body">
                    1. สอนให้เด็กรู้ความแตกต่างของเพศชายและหญิง จากการแต่งตัวทรงผม<br>
                    2. บอกให้เด็กรู้ถึงเพศของสมาชิกในครอบครัว เช่น แม่เป็นผู้หญิงพ่อเป็นผู้ชาย หนูเป็นผู้หญิง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 100 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 100 - ใส่เสื้อผ่าหน้าได้เองโดยไม่ต้องติดกระดุม (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 42 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q100_pass_mobile" name="q100_pass" value="1">
                <label class="form-check-label text-success" for="q100_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q100_fail_mobile" name="q100_fail" value="1">
                <label class="form-check-label text-danger" for="q100_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">แสดงหรือถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กสามารถใส่เสื้อผ่าหน้าได้เองหรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใส่เสื้อผ่าหน้าได้ด้วยตนเองโดยไม่ต้องติดกระดุม (เสื้อมีแขนหรือไม่มีแขนก็ได้) และไม่ใช่การใส่เสื้อให้ตุ๊กตา</p>
            </div>
            <div class="accordion" id="training100">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading100">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse100">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse100" class="accordion-collapse collapse" data-bs-parent="#training100">
                  <div class="accordion-body">
                    1. ใส่เสื้อผ่าหน้าให้เด็กดู<br>
                    2. นำเสื้อผ่าหน้าคลุมไหล่ของเด็กไว้และจับมือขวาของเด็ก จับคอปกเสื้อด้านซ้ายยกขึ้น (หากเด็กถนัดขวาใส่ด้านขวาก่อนได้)<br>
                    3. ให้เด็กสอดแขนซ้ายเข้าไปในแขนเสื้อด้านซ้าย<br>
                    4. จับมือซ้ายของเด็ก จับคอปกเสื้อด้านขวาแล้วยกขึ้น<br>
                    5. ให้เด็กสอดแขนขวาเข้าไปในแขนเสื้อด้านขวา เมื่อเด็กเริ่มทำได้ลดการช่วยเหลือลง จนเด็กสามารถ ทำได้เองทุกขั้นตอน<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกความจำ และทักษะมาใช้ในชีวิตประจำวันได้อย่างเหมาะสม เพิ่มความภาคภูมิใจในตนเอง</span>
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
      for (let i = 90; i <= 100; i++) {
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

      // Mobile version synchronization
      for (let i = 90; i <= 100; i++) {
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
              if (passCheckboxDesktop) {
                passCheckboxDesktop.checked = true;
                if (failCheckboxDesktop) failCheckboxDesktop.checked = false;
              }
            }
            updateSummary();
          });
          
          failCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              passCheckboxMobile.checked = false;
              // Sync with desktop
              if (failCheckboxDesktop) {
                failCheckboxDesktop.checked = true;
                if (passCheckboxDesktop) passCheckboxDesktop.checked = false;
              }
            }
            updateSummary();
          });

          // Sync desktop to mobile (only if not already added)
          if (passCheckboxDesktop && !passCheckboxDesktop.dataset.mobileSync) {
            passCheckboxDesktop.dataset.mobileSync = 'true';
            passCheckboxDesktop.addEventListener('change', function() {
              if (this.checked) {
                passCheckboxMobile.checked = true;
                failCheckboxMobile.checked = false;
              }
              updateSummary();
            });
          }

          if (failCheckboxDesktop && !failCheckboxDesktop.dataset.mobileSync) {
            failCheckboxDesktop.dataset.mobileSync = 'true';
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

      for (let i = 90; i <= 100; i++) {
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

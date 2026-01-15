<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '9';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 27-34)
    for ($i = 27; $i <= 34; $i++) {
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
    $evaluation_time = date('Y-m-d H:i:s'); // เปลี่ยนเป็น datetime format
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
    $total_questions = 8; // แบบประเมินช่วงอายุ 9 เดือน มีทั้งหมด 8 ข้อ (ข้อ 27-34)
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
  <title>แบบประเมิน ช่วงอายุ 9 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/eva.css">
  <link rel="stylesheet" href="../css/test.css">
  <style>
    /* Page-specific styles for evaluation6.php: yellow background, green text */
    .page-eva6 .table-color { background-color: #FFEB3B !important; color: #0b6623 !important; text-align: center; }
    .page-eva6 table { color: #0b6623 !important; }
    .page-eva6 .bgeva1 { background-color: #FFEB3B !important; color: #0b6623 !important; }
    .page-eva6 .card-header.bgeva1.text-white { color: #0b6623 !important; }
  </style>
</head>
<body class="bg-light page-eva6">
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 9 เดือน
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
            <tr>
              <td>9 เดือน</td>
              <td>27<br>
                  <input type="checkbox" id="q27_pass" name="q27_pass" value="1">
                  <label for="q27_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q27_fail" name="q27_fail" value="1">
                  <label for="q27_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ลุกขึ้นนั่งได้จากท่านอน (GM)<br><br>
              <strong>อุปกรณ์:</strong> สิ่งที่กระตุ้นให้เด็กสนใจอยากลุกขึ้นนั่ง เช่นลูกบอล หรือ กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/ballandgru.png" alt="Family" style="width: 150px; height: 90px;"><br></td>
            </td>
            <td>
              1. จัดให้เด็กอยู่ในท่านอนหงายหรือนอนคว่ำ<br>
              2. กระตุ้นให้เด็กลุกขึ้นนั่ง เช่น ใช้ลูกบอลกระตุ้น หรือ ตบมือ/ใช้ท่าทางเรียก
              <strong>ผ่าน:</strong> เด็กสามารถลุกขึ้นนั่งจากท่านอนได้เอง
            </td>
            <td>
              1. จัดเด็กในท่านอนคว่ำ จับเข่างอทั้ง 2 ข้าง จับมือเด็กทั้ง 2 ข้างยันพื้น<br>
              <img src="../image/evaluation_pic/9.1.png" alt="Family" style="width: 90px; height: 90px;"> <img src="../image/evaluation_pic/9.2.png" alt="Family" style="width: 90px; height: 90px;"><br>
              2. กดที่สะโพกเด็กทั้งสองข้าง เพื่อให้เด็กยันตัวลุกขึ้นมาอยู่ในท่านั่ง<br>
              <img src="../image/evaluation_pic/9.3.png" alt="Family" style="width: 90px; height: 90px;"> <img src="../image/evaluation_pic/9.4.png" alt="Family" style="width: 90px; height: 90px;"><br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น สัตว์ยางบีบของเล่นที่เขย่าแล้วมีเสียงดังคล้ายกรุ๊งกริ๊ง ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น </span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>28<br>
                  <input type="checkbox" id="q28_pass" name="q28_pass" value="1">
                  <label for="q28_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q28_fail" name="q28_fail" value="1">
                  <label for="q28_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนอยู่ได้โดยใช้มือเกาะเครื่องเรือนสูงระดับอก (GM)<br><br>
              <strong>อุปกรณ์:</strong>สิ่งที่กระตุ้นให้เด็กสนใจอยากเกาะยืน เช่นลูกบอล หรือ กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/ballandgru.png" alt="Family" style="width: 150px; height: 90px;"><br></td>
            </td>
            <td>
              จัดเด็กยืนเกาะเครื่องเรือน พร้อมทั้งวางลูกบอล หรือกรุ๊งกริ๊งไว้ให้เด็กเล่น
              <strong>ผ่าน:</strong> เด็กสามารถยืนอยู่ได้โดยใช้มือเกาะที่เครื่องเรือน ไม่ใช้หน้าอกพิง หรือแขนท้าวเพื่อพยุงตัว
            </td>
            <td>
              1. จัดเด็กให้ยืนเกาะเครื่องเรือน<br>
              2. จับที่สะโพกเด็กก่อน ต่อมาเปลี่ยนจับที่เข่า แล้วจึงจับมือเด็กเกาะที่เครื่องเรือน<br>
              <img src="../image/evaluation_pic/9.5.png" alt="Family" style="width: 320px; height: 90px;"><br>
              3. เมื่อเด็กเริ่มทำได้ ให้เด็กยืนเกาะเครื่องเรือนเอง โดยไม่ใช้หน้าอกพิง หรือแขนท้าวเพื่อพยุงตัว<br>
              4. อาจเปิดเพลงกระตุ้นให้เด็กยืนได้นานหรือเต้นตามจังหวะแต่ต้องคอยอยู่ใกล้ ๆ เพื่อระวังอันตราย<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น สัตว์ยางบีบของเล่นที่เขย่าแล้วมีเสียงดังคล้ายกรุ๊งกริ๊ง ขวดพลาสติกใส่เม็ดถั่ว/ทรายพันให้แน่น </span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>29<br>
                  <input type="checkbox" id="q29_pass" name="q29_pass" value="1">
                  <label for="q29_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q29_fail" name="q29_fail" value="1">
                  <label for="q29_fail">ไม่ผ่าน</label><br>
              </td>
              <td>หยิบก้อนไม้จากพื้นและถือไว้มือละชิ้น (FM)<br><br>
              <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 2 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 2 ก้อน.png" alt="Family" style="width: 120px; height: 90px;"><br></td>
            </td>
            <td>
              วางก้อนไม้ลงบนพื้นพร้อมกับบอกให้เด็กหยิบก้อนไม้<br>
              <strong>หมายเหตุ:</strong> : หากเด็กไม่หยิบสามารถกระตุ้นให้เด็กสนใจโดยการเคาะก้อนไม้ <br>
              <strong>ผ่าน:</strong> : เด็กสามารถหยิบก้อนไม้ทั้งสองก้อนขึ้นพร้อมกัน หรือทีละก้อนก็ได้ และถือก้อนไม้ไว้ในมือข้างละ 1 ก้อน ทั้งสองข้าง
            </td>
            <td>
              1. นำวัตถุสีสดใสขนาดประมาณ 1 นิ้ว เช่น ก้อนไม้ 2 ก้อน(ใช้วัตถุเหมือนกัน 2 ชิ้น) <br>
              2. เคาะของเล่นกับโต๊ะทีละชิ้นเพื่อกระตุ้นให้เด็กหยิบ <br>
              3. ถ้าเด็กไม่หยิบ ช่วยจับมือเด็กให้หยิบ <br>
              <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong>กล่องเล็ก ๆ หรือวัสดุที่ปลอดภัยมีขนาดพอดีมือเด็กประมาณเส้นผ่านศูนย์กลาง 1 นิ้ว เช่น ลูกปิงปอง มะนาวผลเล็ก</span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>30<br>
                  <input type="checkbox" id="q30_pass" name="q30_pass" value="1">
                  <label for="q30_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q30_fail" name="q30_fail" value="1">
                  <label for="q30_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใช้นิ้วหัวแม่มือ และนิ้วอื่น ๆหยิบของขึ้นจากพื้น (FM)<br><br>
              <strong>อุปกรณ์:</strong> วัตถุชิ้นเล็ก ขนาด 2 ซม.<br>
              <img src="../image/evaluation_pic/วัสดุขนาดเล็ก 2 ซม.png" alt="Family" style="width: 120px; height: 100px;"><br></td>
            </td>
            <td>
              1. วางวัตถุชิ้นเล็ก 1 ชิ้นบนพื้น โดยให้อยู่ในระยะที่เด็กเอื้อมมือไปหยิบได้ง่าย<br>
              2. บอกเด็กให้หยิบวัตถุ หรือแสดงให้เด็กดูก่อน<br>
              <strong>ผ่าน:</strong> เด็กหยิบวัตถุขึ้นจากพื้นได้โดยใช้นิ้วหัวแม่มือและนิ้วอื่น ๆ (ไม่ใช่หยิบขึ้นด้วยฝ่ามือ)
            </td>
            <td>
              1. นำวัตถุสีสดใส เช่น ก้อนไม้ เชือก หรืออาหารชิ้นเล็ก ๆ เช่น แตงกวา ขนมปัง วางตรงหน้าเด็ก<br>
              2. หยิบของให้เด็กดู แล้วกระตุ้นให้เด็กหยิบ<br>
              3. ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็ก ให้หยิบสิ่งของหรืออาหารชิ้นเล็กลดการช่วยเหลือลงจนเด็กสามารถทำได้เอง<br>
              4. ควรระวังไม่ให้เด็กเล่นหรือหยิบของที่เป็นอันตรายเข้าปาก เช่น กระดุม เหรียญ เม็ดยา เมล็ดถั่ว เมล็ดผลไม้ เป็นต้น<br>
              <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong>ของกินชิ้นเล็ก ที่อ่อนนุ่ม ละลายได้ในปากไม่สำลัก เช่น ถั่วกวน ฟักทองนึ่ง มันนึ่ง ลูกเกด ข้าวสุก</span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>31<br>
                  <input type="checkbox" id="q31_pass" name="q31_pass" value="1">
                  <label for="q31_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q31_fail" name="q31_fail" value="1">
                  <label for="q31_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำตามคำสั่งง่าย ๆ เมื่อใช้ท่าทางประกอบ (RL)<br><br>
            </td>
            <td>
              สบตาเด็ก แล้วบอกให้เด็กโบกมือหรือตบมือ โดยใช้ท่าทางประกอบ เช่น โบกมือ<br>
              <strong>ผ่าน:</strong> เด็กสามารถทำท่าทางตามคำสั่งง่าย ๆ ที่มีท่าทางประกอบ เช่น โบกมือบ๊ายบาย ตบมือ หรือยกมือสาธุ แม้ยังไม่สมบูรณ์
            </td>
            <td>
              1. เล่นกับเด็กโดยใช้คำสั่งง่าย ๆ เช่น โบกมือ ตบมือ พร้อมกับทำท่าทางประกอบ ฝึกบ่อย ๆ จนเด็กทำได้<br>
              2. ถ้าเด็กไม่ทำ ให้จับมือทำและค่อย ๆ ลดความช่วยเหลือลงโดยเปลี่ยนเป็นจับข้อมือ จากนั้นเปลี่ยนเป็นแตะข้อศอก เมื่อเริ่มตบมือเองได้แล้ว ลดการช่วยเหลือลง เป็นบอกให้ทำอย่างเดียว<br>
              3. เล่นทำท่าทางประกอบเพลง ฝึกการเคลื่อนไหวของนิ้วมือตามเพลงเช่น “แมงมุม” “นิ้ว...อยู่ไหน” 
            </td>
          </tr>

          <tr>
              <td></td>
              <td>32<br>
                  <input type="checkbox" id="q32_pass" name="q32_pass" value="1">
                  <label for="q32_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q32_fail" name="q32_fail" value="1">
                  <label for="q32_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กรู้จักการปฏิเสธด้วยการแสดงท่าทาง (EL)<br><br>
            </td>
            <td>
              สังเกต หรือถามว่าเด็กปฏิเสธสิ่งของอาหาร หรือการช่วยเหลือจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กได้หรือไม่ 
              <strong>ผ่าน:</strong> เด็กสามารถใช้ท่าทางเดิมในการปฏิเสธ เช่น ส่ายหน้า ใช้มือผลักออกไป หันหน้าหนี
            </td>
            <td>
              1. เมื่อคนแปลกหน้ายื่นของให้ หรือขออุ้ม ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ส่ายหน้า พร้อมกับพูดว่า “ไม่เอา” ให้เด็กเลียนแบบเพื่อให้เด็กรู้จักปฏิเสธ โดยการแสดงท่าทาง <br>
              2. เมื่อเด็กรับประทานอาหารหรือขนมอิ่มแล้ว ถามเด็กว่า “กินอีกไหม” แล้วส่ายศีรษะพร้อมกับพูดว่า “ไม่กิน” ให้เด็กเลียนแบบตามทำเช่นนี้กับสถานการณ์อื่น ๆ เพื่อให้เด็กเรียนรู้เพิ่มขึ้น
            </td>
          </tr>

          <tr>
              <td></td>
              <td>33<br>
                  <input type="checkbox" id="q33_pass" name="q33_pass" value="1">
                  <label for="q33_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q33_fail" name="q33_fail" value="1">
                  <label for="q33_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนเสียงคำพูดที่คุ้นเคยได้อย่างน้อย 1 เสียง (EL)<br><br>
            </td>
            <td>
              ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก หรือสังเกตระหว่างประเมินว่าเด็กทำเสียงเลียนเสียงพูดได้หรือไม่ 
              <strong>ผ่าน:</strong> เด็กเลียนเสียงคำพูดที่คุ้นเคยได้อย่างน้อย 1 เสียง เช่น “แม่” “ไป” “หม่ำ”“ป(ล)า ” แต่เด็กอาจจะออกเสียงยังไม่ชัด
            </td>
            <td>
              เปล่งเสียงที่เด็กเคยทำได้แล้ว เช่น ป๊ะ จ๊ะ จ๋า รอให้เด็กเลียนเสียงตามจากนั้นเปล่งเสียงที่แตกต่างจากเดิมให้เด็กเลียนเสียงตาม เช่น“แม่” “ไป” “หม่ำ” “ป(ล)า ”
            </td>
          </tr>

          <tr>
              <td></td>
              <td>34<br>
                  <input type="checkbox" id="q34_pass" name="q34_pass" value="1">
                  <label for="q34_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q34_fail" name="q34_fail" value="1">
                  <label for="q34_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใช้นิ้วหยิบอาหารกินได้ (PS)<br><br>
            </td>
            <td>
              ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กใช้นิ้วหยิบอาหารกินได้หรือไม 
              <strong>ผ่าน:</strong>  เด็กสามารถใช้นิ้วมือหยิบอาหารกินได้

            </td>
            <td>
              1. วางอาหารที่เด็กชอบและหยิบง่ายขนาด 1 คำ เช่น ขนมปังกรอบตรงหน้าเด็ก <br>
              2. จับมือเด็กหยิบอาหารใส่ปาก แล้วปล่อยให้เด็กทำเองฝึกบ่อย ๆจนสามารถหยิบอาหารกินได้เอง
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
        <!-- Card ข้อที่ 27 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 27 - ลุกขึ้นนั่งได้จากท่านอน (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q27_pass_mobile" name="q27_pass" value="1">
                <label class="form-check-label text-success" for="q27_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q27_fail_mobile" name="q27_fail" value="1">
                <label class="form-check-label text-danger" for="q27_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> สิ่งที่กระตุ้นให้เด็กสนใจอยากลุกขึ้นนั่ง เช่นลูกบอล หรือ กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/ballandgru.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนหงายหรือนอนคว่ำ<br>
              2. กระตุ้นให้เด็กลุกขึ้นนั่ง เช่น ใช้ลูกบอลกระตุ้น หรือ ตบมือ/ใช้ท่าทางเรียก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถลุกขึ้นนั่งจากท่านอนได้เอง</p>
            </div>
            <div class="accordion" id="training27">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading27">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse27">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse27" class="accordion-collapse collapse" data-bs-parent="#training27">
                  <div class="accordion-body">
                    1. จัดเด็กในท่านอนคว่ำ จับเข่างอทั้ง 2 ข้าง จับมือเด็กทั้ง 2 ข้างยันพื้น<br>
                    2. กดที่สะโพกเด็กทั้งสองข้าง เพื่อให้เด็กยันตัวลุกขึ้นมาอยู่ในท่านั่ง<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น สัตว์ยางบีบของเล่นที่เขย่าแล้วมีเสียงดังคล้ายกรุ๊งกริ๊ง ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 28 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 28 - ยืนอยู่ได้โดยใช้มือเกาะเครื่องเรือนสูงระดับอก (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q28_pass_mobile" name="q28_pass" value="1">
                <label class="form-check-label text-success" for="q28_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q28_fail_mobile" name="q28_fail" value="1">
                <label class="form-check-label text-danger" for="q28_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> สิ่งที่กระตุ้นให้เด็กสนใจอยากลุกขึ้นนั่ง เช่นลูกบอล หรือ กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/ballandgru.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดเด็กยืนเกาะเครื่องเรือน พร้อมทั้งวางลูกบอล หรือกรุ๊งกริ๊งไว้ให้เด็กเล่น</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยืนอยู่ได้โดยใช้มือเกาะที่เครื่องเรือน ไม่ใช้หน้าอกพิง หรือแขนท้าวเพื่อพยุงตัว</p>
            </div>
            <div class="accordion" id="training28">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading28">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse28">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse28" class="accordion-collapse collapse" data-bs-parent="#training28">
                  <div class="accordion-body">
                    1. จัดเด็กให้ยืนเกาะเครื่องเรือน<br>
                    2. จับที่สะโพกเด็กก่อน ต่อมาเปลี่ยนจับที่เข่า แล้วจึงจับมือเด็กเกาะที่เครื่องเรือน<br>
                    3. เมื่อเด็กเริ่มทำได้ ให้เด็กยืนเกาะเครื่องเรือนเอง โดยไม่ใช้หน้าอกพิง หรือแขนท้าวเพื่อพยุงตัว<br>
                    4. อาจเปิดเพลงกระตุ้นให้เด็กยืนได้นานหรือเต้นตามจังหวะแต่ต้องคอยอยู่ใกล้ ๆ เพื่อระวังอันตราย<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น สัตว์ยางบีบของเล่นที่เขย่าแล้วมีเสียงดังคล้ายกรุ๊งกริ๊ง ขวดพลาสติกใส่เม็ดถั่ว/ทรายพันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 29 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 29 - หยิบก้อนไม้จากพื้นและถือไว้มือละชิ้น (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q29_pass_mobile" name="q29_pass" value="1">
                <label class="form-check-label text-success" for="q29_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q29_fail_mobile" name="q29_fail" value="1">
                <label class="form-check-label text-danger" for="q29_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยม ลูกบาศก์ 2 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 2 ก้อน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางก้อนไม้ลงบนพื้นพร้อมกับบอกให้เด็กหยิบก้อนไม้<br>
              <strong>หมายเหตุ:</strong> หากเด็กไม่หยิบสามารถกระตุ้นให้เด็กสนใจโดยการเคาะก้อนไม้</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถหยิบก้อนไม้ทั้งสองก้อนขึ้นพร้อมกัน หรือทีละก้อนก็ได้ และถือก้อนไม้ไว้ในมือข้างละ 1 ก้อน ทั้งสองข้าง</p>
            </div>
            <div class="accordion" id="training29">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading29">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse29">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse29" class="accordion-collapse collapse" data-bs-parent="#training29">
                  <div class="accordion-body">
                    1. นำวัตถุสีสดใสขนาดประมาณ 1 นิ้ว เช่น ก้อนไม้ 2 ก้อน(ใช้วัตถุเหมือนกัน 2 ชิ้น)<br>
                    2. เคาะของเล่นกับโต๊ะทีละชิ้นเพื่อกระตุ้นให้เด็กหยิบ<br>
                    3. ถ้าเด็กไม่หยิบ ช่วยจับมือเด็กให้หยิบ<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ หรือวัสดุที่ปลอดภัยมีขนาดพอดีมือเด็กประมาณเส้นผ่านศูนย์กลาง 1 นิ้ว เช่น ลูกปิงปอง มะนาวผลเล็ก</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 30 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 30 - ใช้นิ้วหัวแม่มือ และนิ้วอื่น ๆหยิบของขึ้นจากพื้น (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q30_pass_mobile" name="q30_pass" value="1">
                <label class="form-check-label text-success" for="q30_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q30_fail_mobile" name="q30_fail" value="1">
                <label class="form-check-label text-danger" for="q30_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> วัตถุชิ้นเล็ก ขนาด 2 ซม.<br>
              <img src="../image/evaluation_pic/วัสดุขนาดเล็ก 2 ซม.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางวัตถุชิ้นเล็ก 1 ชิ้นบนพื้น โดยให้อยู่ในระยะที่เด็กเอื้อมมือไปหยิบได้ง่าย<br>
              2. บอกเด็กให้หยิบวัตถุ หรือแสดงให้เด็กดูก่อน</p>
              <p><strong>ผ่าน:</strong> เด็กหยิบวัตถุขึ้นจากพื้นได้โดยใช้นิ้วหัวแม่มือและนิ้วอื่น ๆ (ไม่ใช่หยิบขึ้นด้วยฝ่ามือ)</p>
            </div>
            <div class="accordion" id="training30">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading30">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse30">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse30" class="accordion-collapse collapse" data-bs-parent="#training30">
                  <div class="accordion-body">
                    1. นำวัตถุสีสดใส เช่น ก้อนไม้ เชือก หรืออาหารชิ้นเล็ก ๆ เช่น แตงกวา ขนมปัง วางตรงหน้าเด็ก<br>
                    2. หยิบของให้เด็กดู แล้วกระตุ้นให้เด็กหยิบ<br>
                    3. ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็ก ให้หยิบสิ่งของหรืออาหารชิ้นเล็กลดการช่วยเหลือลงจนเด็กสามารถทำได้เอง<br>
                    4. ควรระวังไม่ให้เด็กเล่นหรือหยิบของที่เป็นอันตรายเข้าปาก เช่น กระดุม เหรียญ เม็ดยา เมล็ดถั่ว เมล็ดผลไม้ เป็นต้น<br>
                    <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> ของกินชิ้นเล็ก ที่อ่อนนุ่ม ละลายได้ในปากไม่สำลัก เช่น ถั่วกวน ฟักทองนึ่ง มันนึ่ง ลูกเกด ข้าวสุก</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 31 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 31 - ทำตามคำสั่งง่าย ๆ เมื่อใช้ท่าทางประกอบ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q31_pass_mobile" name="q31_pass" value="1">
                <label class="form-check-label text-success" for="q31_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q31_fail_mobile" name="q31_fail" value="1">
                <label class="form-check-label text-danger" for="q31_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สบตาเด็ก แล้วบอกให้เด็กโบกมือหรือตบมือ โดยใช้ท่าทางประกอบ เช่น โบกมือ</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถทำท่าทางตามคำสั่งง่าย ๆ ที่มีท่าทางประกอบ เช่น โบกมือบ๊ายบาย ตบมือ หรือยกมือสาธุ แม้ยังไม่สมบูรณ์</p>
            </div>
            <div class="accordion" id="training31">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading31">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse31">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse31" class="accordion-collapse collapse" data-bs-parent="#training31">
                  <div class="accordion-body">
                    1. เล่นกับเด็กโดยใช้คำสั่งง่าย ๆ เช่น โบกมือ ตบมือ พร้อมกับทำท่าทางประกอบ ฝึกบ่อย ๆ จนเด็กทำได้<br>
                    2. ถ้าเด็กไม่ทำ ให้จับมือทำและค่อย ๆ ลดความช่วยเหลือลงโดยเปลี่ยนเป็นจับข้อมือ จากนั้นเปลี่ยนเป็นแตะข้อศอก เมื่อเริ่มตบมือเองได้แล้ว ลดการช่วยเหลือลง เป็นบอกให้ทำอย่างเดียว<br>
                    3. เล่นทำท่าทางประกอบเพลง ฝึกการเคลื่อนไหวของนิ้วมือตามเพลงเช่น "แมงมุม" "นิ้ว...อยู่ไหน"
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 32 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 32 - เด็กรู้จักการปฏิเสธด้วยการแสดงท่าทาง (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q32_pass_mobile" name="q32_pass" value="1">
                <label class="form-check-label text-success" for="q32_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q32_fail_mobile" name="q32_fail" value="1">
                <label class="form-check-label text-danger" for="q32_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกต หรือถามว่าเด็กปฏิเสธสิ่งของอาหาร หรือการช่วยเหลือจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใช้ท่าทางเดิมในการปฏิเสธ เช่น ส่ายหน้า ใช้มือผลักออกไป หันหน้าหนี</p>
            </div>
            <div class="accordion" id="training32">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading32">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse32">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse32" class="accordion-collapse collapse" data-bs-parent="#training32">
                  <div class="accordion-body">
                    1. เมื่อคนแปลกหน้ายื่นของให้ หรือขออุ้ม ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ส่ายหน้า พร้อมกับพูดว่า "ไม่เอา" ให้เด็กเลียนแบบเพื่อให้เด็กรู้จักปฏิเสธ โดยการแสดงท่าทาง<br>
                    2. เมื่อเด็กรับประทานอาหารหรือขนมอิ่มแล้ว ถามเด็กว่า "กินอีกไหม" แล้วส่ายศีรษะพร้อมกับพูดว่า "ไม่กิน" ให้เด็กเลียนแบบตามทำเช่นนี้กับสถานการณ์อื่น ๆ เพื่อให้เด็กเรียนรู้เพิ่มขึ้น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 33 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 33 - เลียนเสียงคำพูดที่คุ้นเคยได้อย่างน้อย 1 เสียง (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q33_pass_mobile" name="q33_pass" value="1">
                <label class="form-check-label text-success" for="q33_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q33_fail_mobile" name="q33_fail" value="1">
                <label class="form-check-label text-danger" for="q33_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก หรือสังเกตระหว่างประเมินว่าเด็กทำเสียงเลียนเสียงพูดได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กเลียนเสียงคำพูดที่คุ้นเคยได้อย่างน้อย 1 เสียง เช่น "แม่" "ไป" "หม่ำ""ป(ล)า " แต่เด็กอาจจะออกเสียงยังไม่ชัด</p>
            </div>
            <div class="accordion" id="training33">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading33">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse33">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse33" class="accordion-collapse collapse" data-bs-parent="#training33">
                  <div class="accordion-body">
                    เปล่งเสียงที่เด็กเคยทำได้แล้ว เช่น ป๊ะ จ๊ะ จ๋า รอให้เด็กเลียนเสียงตามจากนั้นเปล่งเสียงที่แตกต่างจากเดิมให้เด็กเลียนเสียงตาม เช่น"แม่" "ไป" "หม่ำ" "ป(ล)า "
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 34 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 34 - ใช้นิ้วหยิบอาหารกินได้ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 9 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q34_pass_mobile" name="q34_pass" value="1">
                <label class="form-check-label text-success" for="q34_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q34_fail_mobile" name="q34_fail" value="1">
                <label class="form-check-label text-danger" for="q34_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กใช้นิ้วหยิบอาหารกินได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใช้นิ้วมือหยิบอาหารกินได้</p>
            </div>
            <div class="accordion" id="training34">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading34">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse34">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse34" class="accordion-collapse collapse" data-bs-parent="#training34">
                  <div class="accordion-body">
                    1. วางอาหารที่เด็กชอบและหยิบง่ายขนาด 1 คำ เช่น ขนมปังกรอบตรงหน้าเด็ก<br>
                    2. จับมือเด็กหยิบอาหารใส่ปาก แล้วปล่อยให้เด็กทำเองฝึกบ่อย ๆจนสามารถหยิบอาหารกินได้เอง
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
      for (let i = 27; i <= 34; i++) {
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
      for (let i = 27; i <= 34; i++) {
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

      for (let i = 27; i <= 34; i++) {
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

<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '30';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 70-78)
    for ($i = 70; $i <= 78; $i++) {
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
    $total_questions = 9; // แบบประเมินมีทั้งหมด 9 ข้อ (ข้อ 70-78)
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
  <title>แบบประเมิน ช่วงอายุ 30 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/eva.css">
  <link rel="stylesheet" href="../css/test.css">
  <style>
    /* Page-specific styles for evaluation13.php: yellow background, green text */
    .page-eva13 .table-color { background-color: #FFEB3B !important; color: #0b6623 !important; text-align: center; }
    .page-eva13 table { color: #0b6623 !important; }
    .page-eva13 .bgeva1 { background-color: #FFEB3B !important; color: #0b6623 !important; }
    .page-eva13 .card-header.bgeva1.text-white { color: #0b6623 !important; }
  </style>
</head>
<body class="bg-light page-eva13">
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 30 เดือน
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
            <!-- ข้อ 70-78 สำหรับ 30 เดือน -->
            <tr>
              <td rowspan="9">30 เดือน</td>
              <td>70<br>
                  <input type="checkbox" id="q70_pass" name="q70_pass" value="1">
                  <label for="q70_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q70_fail" name="q70_fail" value="1">
                  <label for="q70_fail">ไม่ผ่าน</label><br>
              </td>
              <td>กระโดดข้ามเชือกบนพื้นไปข้างหน้าได้ (GM)<br><br>
              <strong>อุปกรณ์:</strong> เชือก<br>
              <img src="../image/evaluation_pic/เชือก.png" alt="Family" style="width: 150px; height: 120px;"><br></td>
                </td>
              </td>
              <td>
                1. วางเชือกเป็นเส้นตรงบนพื้นหน้าตัวเด็ก <br>
                2. กระโดดข้ามเชือกที่วางอยู่บนพื้นให้เด็กดู และบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถกระโดดข้ามเชือกได้โดยเท้าลงพื้นพร้อมกัน หรือเท้าไม่ต้องลงพื้นพร้อมกันก็ได้
              </td>
              <td>
               1. กระโดดอยู่กับที่ ให้เด็กดู <br>
               2. จับมือเด็กไว้ทั้ง 2 ข้าง แล้วฝึกกระโดดมาจากบันไดขั้นที่ติดกับพื้นหรือจากพื้นต่างระดับ<br>
               3. กระโดดข้ามเชือกให้เด็กดู<br>
               4. ยืนหันหน้าเข้าหาเด็กโดยวางเชือกคั่นกลาง และจับมือเด็กพยุงไว้ดึงมือให้เด็กกระโดดข้ามเชือก ฝึกบ่อย ๆ จนเด็กมั่นใจและสามารถ
               กระโดดข้ามเชือกได้เอง<br>
               5. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังไม่ให้เด็กมีอันตรายในระหว่างการกระโดด<br>
               <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ริบบิ้น เชือกฟาง ไม้หรือชอล์ก ขีดเส้นตรงบนพื้น</span>
              </td>
            </tr>

            <tr>
              <td>71<br>
                  <input type="checkbox" id="q71_pass" name="q71_pass" value="1">
                  <label for="q71_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q71_fail" name="q71_fail" value="1">
                  <label for="q71_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ขว้างลูกบอลขนาดเล็กได้ โดยยกมือขึ้นเหนือศีรษะ (GM)<br><br>
              <strong>อุปกรณ์:</strong> ลูกบอลยาง วัดขนาดเส้นผ่านศูนย์กลางประมาณ 7 เซนติเมตร<br>
              <img src="../image/evaluation_pic/ball_7.png" alt="Family" style="width: 120px; height: 90px;"><br>
                </td>
              <td>
                ขว้างลูกบอลยางให้เด็กดู โดยจับลูกบอลด้วยมือข้างเดียวยกขึ้นเหนือศีรษะไปทางด้านหลัง แล้วขว้างลูกบอลยางไปข้างหน้า
                และบอกให้เด็กทำตาม <br>
                <strong>ผ่าน:</strong>  เด็กสามารถขว้างลูกบอลได้โดยยกมือขึ้นเหนือศีรษะไปทางด้านหลังแล้วขว้างลูกบอลไปข้างหน้า
              </td>
              <td>
                1. ขว้างลูกบอลให้เด็กดูโดยยกมือขึ้นเหนือศีรษะไปทางด้านหลังแล้วขว้างลูกบอลไปข้างหน้า<br>
                2. จัดเด็กยืนในท่าที่มั่นคง จับมือเด็กข้างที่ถนัดถือลูกบอล แล้วยกลูกบอลขึ้นเหนือศีรษะไปทางด้านหลัง เอี้ยวตัวเล็กน้อยแล้วขว้างลูกบอลออกไป<br>
                3. เมื่อเด็กเริ่มทำได้ ลดการช่วยเหลือลง จนเด็กขว้างลูกบอลได้เอง<br>
                4. เล่นขว้างลูกบอลกับเด็กบ่อย ๆ<br>
                <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  ลูกบอลขนาดเล็กที่มีขนาดพอดีมือของเด็กชนิดอื่น ๆ เช่น ลูกเทนนิส </span>
              </td>
            </tr>

            <tr>
              <td>72<br>
                  <input type="checkbox" id="q72_pass" name="q72_pass" value="1">
                  <label for="q72_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q72_fail" name="q72_fail" value="1">
                  <label for="q72_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ต่อก้อนไม้สี่เหลี่ยมลูกบาศก์เป็นหอสูงได้ 8 ก้อน (FM)<br><br>
              <strong>อุปกรณ์ :</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 8 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 8 ก้อน v2.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                1. จัดให้เด็กอยู่ในท่านั่งที่ถนัดที่จะต่อก้อนไม้ได้<br>
                2. วางก้อนไม้ 8 ก้อน ไว้ข้างหน้าเด็กกระตุ้นให้เด็กต่อก้อนไม้ให้สูงที่สุด หรือทำให้เด็กดูก่อนได้<br>
                <strong>ผ่าน:</strong>  เด็กสามารถต่อก้อนไม้ โดยไม่ล้มจำนวน 8 ก้อน 1 ใน 3 ครั้ง
              </td>
              <td>
                1. ใช้วัตถุที่เป็นทรงสี่เหลี่ยม เช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู<br>
                2. กระตุ้นให้เด็กทำตาม<br>
                3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1 วางไปเรื่อย ๆ จนครบ 8 ชั้น<br>
                4. ทำซ้ำหลายครั้งและลดการช่วยเหลือลงจนเด็กต่อก้อนไม้ได้เองหากเด็กทำได้แล้วให้ชมเชย เพื่อเพิ่มความภาคภูมิใจในตนเอง<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span><br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong>  เด็กตั้งใจต่อก้อนไม้ตามแบบที่ยากขึ้น จนสำเร็จ</span>
              </td>
            </tr>

            <tr>
              <td>73<br>
                  <input type="checkbox" id="q73_pass" name="q73_pass" value="1">
                  <label for="q73_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q73_fail" name="q73_fail" value="1">
                  <label for="q73_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยื่นวัตถุให้ผู้ทดสอบได้ 1 ชิ้นตามคำบอก (รู้จำนวนเท่ากับ 1)(RL)<br><br>
              <strong>อุปกรณ์ :</strong> ชุดก้อนไม้สี่เหลี่ยม ลูกบาศก์ 3 ก้อน<br>
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 3 ก้อน.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                1. วางก้อนไม้สี่เหลี่ยมลูกบาศก์ 3 ก้อนตรงหน้าเด็ก<br>
                2. แบมือไปตรงหน้าเด็กแล้วพูดว่า “หยิบก้อนไม้ให้ครู 1 ก้อน” <br>
                3. นำก้อนไม้กลับไปวางที่เดิม แล้วพูดซ้ำว่า “หยิบก้อนไม้ ให้ครู 1 ก้อน”<br>
                <strong>ผ่าน:</strong>  เด็กสามารถส่งวัตถุให้ผู้ประเมิน1 ก้อน ได้ทั้ง 2 ครั้ง โดยไม่พยายามจะหยิบส่งให้อีก
              </td>
              <td>
                1. วางวัตถุชนิดเดียวกัน 3 ชิ้นตรงหน้าเด็ก เช่น ช้อน 3 คัน และพูดว่า “หยิบช้อนให้คุณพ่อ/คุณแม่ 1 คัน”<br>
                2. ถ้าเด็กหยิบให้เกิน 1 คัน ให้พูดว่า “พอแล้ว” หรือจับมือเด็กไว้เพื่อไม่ให้ส่งเพิ่ม <br>
                3. เปลี่ยนวัตถุให้หลากหลายขึ้น เช่น ใบไม้ ดอกไม้ ผลไม้ และควรสอนอย่างสม่ำเสมอ ในสถานการณ์อื่น ๆ ด้วย<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span><br>
              </td>
            </tr>

            <tr>
              <td>74<br>
                  <input type="checkbox" id="q74_pass" name="q74_pass" value="1">
                  <label for="q74_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q74_fail" name="q74_fail" value="1">
                  <label for="q74_fail">ไม่ผ่าน</label><br>
              </td>
              <td>สนใจฟังนิทานได้นาน 5 นาที(RL)<br><br>
                <strong>อุปกรณ์ :</strong> หนังสือนิทานสำหรับเด็กที่มีรูปภาพและคำอธิบายประกอบหน้าละประมาณ 20 - 30 คำ และอ่านจบใน 5 นาที<br>
                <img src="../image/evaluation_pic/หนังสือนิทาน.png" alt="Family" style="width: 90px; height: 100px;"><br></td>
              <td>
                ชวนเด็กมองที่หนังสือแล้วอ่านหรือเล่านิทานให้เด็กฟัง หรือ สอบถามจากผู้ปกครองว่าเด็กสามารถสนใจฟังนิทานได้นานถึง 5 นาที หรือไม่<br>
                <strong>ผ่าน:</strong> เด็กสามารถสนใจฟัง มองตามพูดตาม และ/หรือ พูดโต้ตอบตามเรื่องราวในหนังสือนิทานที่มีความยาว ประมาณ
                5 นาที อย่างต่อเนื่อง
              </td>
              <td>
               1. เล่าหรืออ่านนิทานให้เด็กฟังทุกวันด้วยความสนุกสนาน เช่นใช้น้ำเสียงสูงต่ำ พร้อมใช้ท่าทางประกอบ วาดรูป หรือใช้นิทาน
               คำกลอน/ร้อยกรอง เพื่อส่งเสริมความสนใจศิลปะ<br>
               2. ให้เด็กดูรูปภาพและแต่งเรื่องเล่าจากรูปภาพเพื่อให้เด็กสนใจเช่น “กระต่ายน้อยมีขนสีขาวมีหูยาว ๆ กระโดดได้ไกลและวิ่งได้เร็ว”<br>
               3. ในระยะแรกใช้นิทานสั้น ๆ ที่ใช้เวลา 2 – 3 นาทีต่อเรื่องก่อนต่อไปจึงเพิ่มความยาวของนิทานให้มากขึ้นจนใช้เวลาประมาณ 5 นาที<br>
               <span style="color: red;"><strong>หนังสือที่ใช้แทนได้:</strong> หนังสือรูปภาพ/หนังสือนิทานสำหรับเด็กเรื่องอื่น ๆ ที่มีรูปภาพและคำอธิบายสั้น ๆ</span>
              </td>
            </tr>

            <tr>
              <td>75<br>
                  <input type="checkbox" id="q75_pass" name="q75_pass" value="1">
                  <label for="q75_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q75_fail" name="q75_fail" value="1">
                  <label for="q75_fail">ไม่ผ่าน</label><br>
              </td>
              <td>วางวัตถุไว้ “ข้างบน” และ“ข้างใต้” ตามคำสั่งได้ (RL)<br><br>
                <strong>อุปกรณ์ :</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 1 ก้อน<br>
                <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 1 ก้อน.png" alt="Family" style="width: 120px; height: 90px;"><br></td>
              <td>
                ส่งก้อนไม้ให้เด็กแล้วพูดว่า “วางก้อนไม้ไว้ข้างบน...(เก้าอี้/โต๊ะ)”“วางก้อนไม้ไว้ข้างใต้....(เก้าอี้/โต๊ะ)” บอก 3 ครั้ง โดย
                สลับคำบอก ข้างบน/ข้างใต้ ทุกครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถวางก้อนไม้ไว้ข้างบนและข้างใต้ได้ถูกต้อง 2 ใน 3 ครั้ง
              </td>
              <td>
               1. วางของเล่น เช่น บอล ไว้ที่ตำแหน่ง “ข้างบน” แล้วบอกเด็กว่า“บอลอยู่ข้างบนโต๊ะ”<br>
               2. บอกให้เด็ก หยิบของเล่นอีกชิ้นหนึ่งมาวางไว้ข้างบนโต๊ะถ้าเด็กทำไม่ได้ ให้จับมือเด็กทำ<br>
               3. ทำซ้ำโดยเปลี่ยนเป็นตำแหน่ง “ข้างใต้”<br>
               4. ฝึกเพิ่มตำแหน่ง อื่น ๆ เช่น ข้าง ๆ ข้างใน ข้างนอก ข้างหน้าข้างหลัง 
               (ใช้คำที่สื่อสารในภาษาตามท้องถิ่นในบริบทที่เด็กพูดในครอบครัว)<br>
               <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span><br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> : ฝึกทักษะการเข้าใจภาษา และนำไปปฏิบัติได้</span>
              </td>
            </tr>

            <tr>
              <td>76<br>
                  <input type="checkbox" id="q76_pass" name="q76_pass" value="1">
                  <label for="q76_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q76_fail" name="q76_fail" value="1">
                  <label for="q76_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดติดต่อกัน 2 คำขึ้นไปอย่างมีความหมายโดยใช้คำกริยาได้ถูกต้องอย่างน้อย 4 กริยา (EL)<br><br>
                <strong>อุปกรณ์ :</strong> ตุ๊กตาผ้า<br>
                <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="Family" style="width: 90px; height: 120px;"><br></td>
              <td>
                จับตุ๊กตาทำกริยาต่างๆ เช่น นั่ง เดิน นอนวิ่ง แล้วถามเด็กว่า ตุ๊กตาทำอะไร หรือสังเกตขณะประเมินทักษะข้ออื่น<br>
                <strong>หมายเหตุ:</strong> ถ้ามีข้อจำกัดในการใช้ตุ๊กตาสามารถใช้ภาพแทนได้ เช่น หนังสือนิทานเรื่อง โตโต้ หรือภาพที่มีรูปคนทำกริยาต่าง ๆ<br>
                <strong>ผ่าน:</strong> เด็กสามารถตอบคำถามโดยใช้วลี2 คำ ขึ้นไปที่ใช้คำกริยาได้ถูกต้อง เช่น“ตุ๊กตา/น้อง นั่ง” “ตุ๊กตา/น้อง วิ่ง”
                “(ตุ๊ก)ตานอน” “น้องเดิน” “นอนหลับ”
              </td>
              <td>
               ฝึกให้เด็กพูดตามสถานการณ์จริง เช่น ขณะรับประทานอาหารถามเด็กว่า “หนูกำลังทำอะไร” รอให้เด็กตอบ “กินข้าว” หรือ ขณะอ่าน
               หนังสือ ถามเกี่ยวกับรูปภาพในหนังสือ เช่น ชี้ไปที่รูปแมว แล้วถามว่า“แมว ทำอะไร” รอให้เด็กตอบ เช่น “แมววิ่ง” ถ้าเด็กตอบไม่ได้
               ให้ช่วยตอบนำ และถามซ้ำ เพื่อให้เด็กตอบเองฝึกในสถานการณ์อื่น ๆโดยเด็กต้องใช้วลี 2 คำขึ้นไป ที่ใช้คำกริยาได้ถูกต้อง เช่น ให้ตอบจาก
               บัตรภาพคำกริยา ได้แก่ อาบน้ำ ล้างหน้า แปรงฟัน เป็นต้น<br>
               <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> ตุ๊กตาคนหรือตุ๊กตาสัตว์ที่มีอยู่ในบ้าน</span>
              </td>
            </tr>

            <tr>
              <td>77<br>
                  <input type="checkbox" id="q77_pass" name="q77_pass" value="1">
                  <label for="q77_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q77_fail" name="q77_fail" value="1">
                  <label for="q77_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ร้องเพลงได้บางคำหรือร้องเพลงคลอตามทำนอง (PS)<br><br>
                </td>
              <td>
                1. ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กชวนเด็กร้องเพลงที่เด็กคุ้นเคย<br>
                2. ถ้าเด็กไม่ยอมร้องเพลง ให้ถามจากพ่อแม่ ผู้ปกครองว่าเด็กสามารถร้องเพลงบางคำหรือพูดคำคล้องจองได้หรือไม่<br>
                <strong>ผ่าน:</strong> เด็กสามารถร้องเพลงตามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กได้ โดยอาจร้องชัดแค่บางคำ หรือคลอตามทำนอง
              </td>
              <td>
               1. ร้องเพลงที่เหมาะสมให้เด็กฟัง เช่น เพลงช้าง เพลงเป็ด หรือเพลงเด็กของท้องถิ่น โดยออกเสียงและทำนองที่ชัดเจน แล้วชวนให้เด็กร้องตาม พร้อมทั้งทำท่าทางประกอบ<br>
               2. ร้องเพลงเดิมซ้ำบ่อย ๆ เพื่อให้เด็กคุ้นเคยจำได้และกระตุ้นให้เด็กร้องตาม หรือเว้นเพื่อให้เด็กร้องต่อเป็นช่วง ๆ<br>
               3. เมื่อเด็กเริ่มร้องเพลงเองได้ให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็กร้องตามเด็ก เลือกเปิดเพลงที่มีเนื้อหาเหมาะสมกับเด็กและพ่อแม่
               ผู้ปกครองหรือผู้ดูแลเด็กร้องเพลงต่าง ๆ ร่วมกับเด็กพร้อมทั้งทำท่าประกอบ เช่น เพลงช้าง เพลงเป็ด หรือเป็นเพลงเด็กภาษาอังกฤษ อาจเลือกบทเพลงที่มีความคล้องจองกัน เพื่อส่งเสริมความสนใจศิลปะ
              </td>
            </tr>

            <tr>
              <td>78<br>
                  <input type="checkbox" id="q78_pass" name="q78_pass" value="1">
                  <label for="q78_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q78_fail" name="q78_fail" value="1">
                  <label for="q78_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กรู้จักรอให้ถึงรอบของตนเองในการเล่นโดยมีผู้ใหญ่คอยบอก (PS)<br><br>
                <strong>อุปกรณ์ :</strong>  1. ก้อนไม้ 4 ก้อน 
                    2. ถ้วยสำหรับใส่ก้อนไม้ 1 ใบ<br>
                <img src="../image/evaluation_pic/ถ้วยและก้อนไม้ 4 ก้อน.png" alt="Family" style="width: 160px; height: 100px;">
                </td>
              <td>
                1. ถือก้อนไม้ 2 ก้อน และยื่นก้อนไม้ให้เด็ก 2 ก้อน <br>
                2. วางถ้วยตรงหน้าเด็กและพูดว่า “เรามาใส่ก้อนไม้คนละ 1 ก้อน ให้ถือก้อนไม้ไว้ก่อน ให้ครูใส่ก่อน แล้วหนูค่อยใส่”<br>
                3. สังเกตการรอให้ถึงรอบของเด็ก<br>
                <strong>ผ่าน:</strong> เด็กรู้จักรอให้ถึงรอบของตนเองเมื่อบอกให้รอ
              </td>
              <td>
               1. ผลัดกันเล่นกับเด็กจนเด็กคุ้นเคยก่อน<br>
               2. ฝึกให้เด็กเล่นเป็นกลุ่มด้วยกัน โดยมีพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กบอกเด็ก เช่น “..(ชื่อเด็ก)..เอาห่วงใส่หลัก” “แล้วรอก่อนนะ”<br>
               3. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก บอกให้เด็กคนต่อไปเอาห่วงใส่หลัก ถ้าเด็กรอไม่ได้ ให้เตือนทุกครั้งจนเด็กรอได้เอง<br>
               4. ฝึกเล่นกิจกรรมอย่างอื่น เช่น ร้องเพลง/นับเลขพร้อมกันก่อนแล้วค่อยกินขนม หรือในสถานการณ์อย่างอื่นที่ต้องมีการรอให้ถึงรอบของตนเองกับเด็ก เช่น พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กเข้าแถว
               รอจ่าย เงินเวลาซื้อของ<br>
               <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ตะกร้าใส่ของ/กล่อง/จาน และของเล่นต่าง ๆ ที่มีในบ้าน</span><br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกการควบคุมอารมณ์ตนเองให้รอจนถึงรอบของตัวเอง มีความอดทน รู้จักให้เกียรติผู้อื่น ทำกิจกรรมร่วมกับผู้อื่นได้ตามขั้นตอน</span>
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
        <!-- Card ข้อที่ 70 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 70 - กระโดดข้ามเชือกบนพื้นไปข้างหน้าได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> เชือก
              <img src="../image/evaluation_pic/เชือก.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q70_pass_mobile" name="q70_pass" value="1">
                <label class="form-check-label text-success" for="q70_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q70_fail_mobile" name="q70_fail" value="1">
                <label class="form-check-label text-danger" for="q70_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางเชือกเป็นเส้นตรงบนพื้นหน้าตัวเด็ก<br>
              2. กระโดดข้ามเชือกที่วางอยู่บนพื้นให้เด็กดู และบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถกระโดดข้ามเชือกได้โดยเท้าลงพื้นพร้อมกัน หรือเท้าไม่ต้องลงพื้นพร้อมกันก็ได้</p>
            </div>
            <div class="accordion" id="training70">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading70">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse70">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse70" class="accordion-collapse collapse" data-bs-parent="#training70">
                  <div class="accordion-body">
                    1. กระโดดอยู่กับที่ ให้เด็กดู<br>
                    2. จับมือเด็กไว้ทั้ง 2 ข้าง แล้วฝึกกระโดดมาจากบันไดขั้นที่ติดกับพื้นหรือจากพื้นต่างระดับ<br>
                    3. กระโดดข้ามเชือกให้เด็กดู<br>
                    4. ยืนหันหน้าเข้าหาเด็กโดยวางเชือกคั่นกลาง และจับมือเด็กพยุงไว้ดึงมือให้เด็กกระโดดข้ามเชือก ฝึกบ่อย ๆ จนเด็กมั่นใจและสามารถกระโดดข้ามเชือกได้เอง<br>
                    5. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังไม่ให้เด็กมีอันตรายในระหว่างการกระโดด<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ริบบิ้น เชือกฟาง ไม้หรือชอล์ก ขีดเส้นตรงบนพื้น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 71 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 71 - ขว้างลูกบอลขนาดเล็กได้ โดยยกมือขึ้นเหนือศีรษะ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ลูกบอลยาง วัดขนาดเส้นผ่านศูนย์กลางประมาณ 7 เซนติเมตร
              <img src="../image/evaluation_pic/ball_7.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q71_pass_mobile" name="q71_pass" value="1">
                <label class="form-check-label text-success" for="q71_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q71_fail_mobile" name="q71_fail" value="1">
                <label class="form-check-label text-danger" for="q71_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ขว้างลูกบอลยางให้เด็กดู โดยจับลูกบอลด้วยมือข้างเดียวยกขึ้นเหนือศีรษะไปทางด้านหลัง แล้วขว้างลูกบอลยางไปข้างหน้า และบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถขว้างลูกบอลได้โดยยกมือขึ้นเหนือศีรษะไปทางด้านหลังแล้วขว้างลูกบอลไปข้างหน้า</p>
            </div>
            <div class="accordion" id="training71">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading71">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse71">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse71" class="accordion-collapse collapse" data-bs-parent="#training71">
                  <div class="accordion-body">
                    1. ขว้างลูกบอลให้เด็กดูโดยยกมือขึ้นเหนือศีรษะไปทางด้านหลังแล้วขว้างลูกบอลไปข้างหน้า<br>
                    2. จัดเด็กยืนในท่าที่มั่นคง จับมือเด็กข้างที่ถนัดถือลูกบอล แล้วยกลูกบอลขึ้นเหนือศีรษะไปทางด้านหลัง เอี้ยวตัวเล็กน้อยแล้วขว้างลูกบอลออกไป<br>
                    3. เมื่อเด็กเริ่มทำได้ ลดการช่วยเหลือลง จนเด็กขว้างลูกบอลได้เอง<br>
                    4. เล่นขว้างลูกบอลกับเด็กบ่อย ๆ<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> ลูกบอลขนาดเล็กที่มีขนาดพอดีมือของเด็กชนิดอื่น ๆ เช่น ลูกเทนนิส</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 72 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 72 - ต่อก้อนไม้สี่เหลี่ยมลูกบาศก์เป็นหอสูงได้ 8 ก้อน (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 8 ก้อน
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 8 ก้อน v2.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q72_pass_mobile" name="q72_pass" value="1">
                <label class="form-check-label text-success" for="q72_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q72_fail_mobile" name="q72_fail" value="1">
                <label class="form-check-label text-danger" for="q72_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านั่งที่ถนัดที่จะต่อก้อนไม้ได้<br>
              2. วางก้อนไม้ 8 ก้อน ไว้ข้างหน้าเด็กกระตุ้นให้เด็กต่อก้อนไม้ให้สูงที่สุด หรือทำให้เด็กดูก่อนได้</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถต่อก้อนไม้ โดยไม่ล้มจำนวน 8 ก้อน 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training72">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading72">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse72">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse72" class="accordion-collapse collapse" data-bs-parent="#training72">
                  <div class="accordion-body">
                    1. ใช้วัตถุที่เป็นทรงสี่เหลี่ยม เช่น ก้อนไม้ กล่องสบู่ วางต่อกันในแนวตั้งให้เด็กดู<br>
                    2. กระตุ้นให้เด็กทำตาม<br>
                    3. ถ้าเด็กทำไม่ได้ให้จับมือเด็กวางก้อนไม้ก้อนที่ 1 ที่พื้น และวางก้อนที่ 2 บนก้อนที่ 1 วางไปเรื่อย ๆ จนครบ 8 ชั้น<br>
                    4. ทำซ้ำหลายครั้งและลดการช่วยเหลือลงจนเด็กต่อก้อนไม้ได้เองหากเด็กทำได้แล้วให้ชมเชย เพื่อเพิ่มความภาคภูมิใจในตนเอง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เด็กตั้งใจต่อก้อนไม้ตามแบบที่ยากขึ้น จนสำเร็จ</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 73 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 73 - ยื่นวัตถุให้ผู้ทดสอบได้ 1 ชิ้นตามคำบอก (รู้จำนวนเท่ากับ 1)(RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ชุดก้อนไม้สี่เหลี่ยม ลูกบาศก์ 3 ก้อน
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 3 ก้อน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q73_pass_mobile" name="q73_pass" value="1">
                <label class="form-check-label text-success" for="q73_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q73_fail_mobile" name="q73_fail" value="1">
                <label class="form-check-label text-danger" for="q73_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางก้อนไม้สี่เหลี่ยมลูกบาศก์ 3 ก้อนตรงหน้าเด็ก<br>
              2. แบมือไปตรงหน้าเด็กแล้วพูดว่า "หยิบก้อนไม้ให้ครู 1 ก้อน"<br>
              3. นำก้อนไม้กลับไปวางที่เดิม แล้วพูดซ้ำว่า "หยิบก้อนไม้ ให้ครู 1 ก้อน"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถส่งวัตถุให้ผู้ประเมิน1 ก้อน ได้ทั้ง 2 ครั้ง โดยไม่พยายามจะหยิบส่งให้อีก</p>
            </div>
            <div class="accordion" id="training73">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading73">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse73">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse73" class="accordion-collapse collapse" data-bs-parent="#training73">
                  <div class="accordion-body">
                    1. วางวัตถุชนิดเดียวกัน 3 ชิ้นตรงหน้าเด็ก เช่น ช้อน 3 คัน และพูดว่า "หยิบช้อนให้คุณพ่อ/คุณแม่ 1 คัน"<br>
                    2. ถ้าเด็กหยิบให้เกิน 1 คัน ให้พูดว่า "พอแล้ว" หรือจับมือเด็กไว้เพื่อไม่ให้ส่งเพิ่ม<br>
                    3. เปลี่ยนวัตถุให้หลากหลายขึ้น เช่น ใบไม้ ดอกไม้ ผลไม้ และควรสอนอย่างสม่ำเสมอ ในสถานการณ์อื่น ๆ ด้วย<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 74 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 74 - สนใจฟังนิทานได้นาน 5 นาที(RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> หนังสือนิทานสำหรับเด็กที่มีรูปภาพและคำอธิบายประกอบหน้าละประมาณ 20 - 30 คำ และอ่านจบใน 5 นาที
              <img src="../image/evaluation_pic/หนังสือนิทาน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q74_pass_mobile" name="q74_pass" value="1">
                <label class="form-check-label text-success" for="q74_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q74_fail_mobile" name="q74_fail" value="1">
                <label class="form-check-label text-danger" for="q74_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ชวนเด็กมองที่หนังสือแล้วอ่านหรือเล่านิทานให้เด็กฟัง หรือ สอบถามจากผู้ปกครองว่าเด็กสามารถสนใจฟังนิทานได้นานถึง 5 นาที หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถสนใจฟัง มองตามพูดตาม และ/หรือ พูดโต้ตอบตามเรื่องราวในหนังสือนิทานที่มีความยาว ประมาณ 5 นาที อย่างต่อเนื่อง</p>
            </div>
            <div class="accordion" id="training74">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading74">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse74">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse74" class="accordion-collapse collapse" data-bs-parent="#training74">
                  <div class="accordion-body">
                    1. เล่าหรืออ่านนิทานให้เด็กฟังทุกวันด้วยความสนุกสนาน เช่นใช้น้ำเสียงสูงต่ำ พร้อมใช้ท่าทางประกอบ วาดรูป หรือใช้นิทานคำกลอน/ร้อยกรอง เพื่อส่งเสริมความสนใจศิลปะ<br>
                    2. ให้เด็กดูรูปภาพและแต่งเรื่องเล่าจากรูปภาพเพื่อให้เด็กสนใจเช่น "กระต่ายน้อยมีขนสีขาวมีหูยาว ๆ กระโดดได้ไกลและวิ่งได้เร็ว"<br>
                    3. ในระยะแรกใช้นิทานสั้น ๆ ที่ใช้เวลา 2 – 3 นาทีต่อเรื่องก่อนต่อไปจึงเพิ่มความยาวของนิทานให้มากขึ้นจนใช้เวลาประมาณ 5 นาที<br>
                    <span style="color: red;"><strong>หนังสือที่ใช้แทนได้:</strong> หนังสือรูปภาพ/หนังสือนิทานสำหรับเด็กเรื่องอื่น ๆ ที่มีรูปภาพและคำอธิบายสั้น ๆ</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 75 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 75 - วางวัตถุไว้ "ข้างบน" และ"ข้างใต้" ตามคำสั่งได้ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ก้อนไม้สี่เหลี่ยมลูกบาศก์ 1 ก้อน
              <img src="../image/evaluation_pic/ก้อนไม้สี่เหลี่ยมลูกบาก 1 ก้อน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q75_pass_mobile" name="q75_pass" value="1">
                <label class="form-check-label text-success" for="q75_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q75_fail_mobile" name="q75_fail" value="1">
                <label class="form-check-label text-danger" for="q75_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ส่งก้อนไม้ให้เด็กแล้วพูดว่า "วางก้อนไม้ไว้ข้างบน...(เก้าอี้/โต๊ะ)""วางก้อนไม้ไว้ข้างใต้....(เก้าอี้/โต๊ะ)" บอก 3 ครั้ง โดยสลับคำบอก ข้างบน/ข้างใต้ ทุกครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวางก้อนไม้ไว้ข้างบนและข้างใต้ได้ถูกต้อง 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training75">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading75">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse75">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse75" class="accordion-collapse collapse" data-bs-parent="#training75">
                  <div class="accordion-body">
                    1. วางของเล่น เช่น บอล ไว้ที่ตำแหน่ง "ข้างบน" แล้วบอกเด็กว่า"บอลอยู่ข้างบนโต๊ะ"<br>
                    2. บอกให้เด็ก หยิบของเล่นอีกชิ้นหนึ่งมาวางไว้ข้างบนโต๊ะถ้าเด็กทำไม่ได้ ให้จับมือเด็กทำ<br>
                    3. ทำซ้ำโดยเปลี่ยนเป็นตำแหน่ง "ข้างใต้"<br>
                    4. ฝึกเพิ่มตำแหน่ง อื่น ๆ เช่น ข้าง ๆ ข้างใน ข้างนอก ข้างหน้าข้างหลัง (ใช้คำที่สื่อสารในภาษาตามท้องถิ่นในบริบทที่เด็กพูดในครอบครัว)<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> กล่องเล็ก ๆ เช่น กล่องสบู่ กล่องนม</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกทักษะการเข้าใจภาษา และนำไปปฏิบัติได้</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 76 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 76 - พูดติดต่อกัน 2 คำขึ้นไปอย่างมีความหมายโดยใช้คำกริยาได้ถูกต้องอย่างน้อย 4 กริยา (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ตุ๊กตาผ้า
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q76_pass_mobile" name="q76_pass" value="1">
                <label class="form-check-label text-success" for="q76_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q76_fail_mobile" name="q76_fail" value="1">
                <label class="form-check-label text-danger" for="q76_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จับตุ๊กตาทำกริยาต่างๆ เช่น นั่ง เดิน นอนวิ่ง แล้วถามเด็กว่า ตุ๊กตาทำอะไร หรือสังเกตขณะประเมินทักษะข้ออื่น</p>
              <p><strong>หมายเหตุ:</strong> ถ้ามีข้อจำกัดในการใช้ตุ๊กตาสามารถใช้ภาพแทนได้ เช่น หนังสือนิทานเรื่อง โตโต้ หรือภาพที่มีรูปคนทำกริยาต่าง ๆ</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตอบคำถามโดยใช้วลี2 คำ ขึ้นไปที่ใช้คำกริยาได้ถูกต้อง เช่น"ตุ๊กตา/น้อง นั่ง" "ตุ๊กตา/น้อง วิ่ง" "(ตุ๊ก)ตานอน" "น้องเดิน" "นอนหลับ"</p>
            </div>
            <div class="accordion" id="training76">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading76">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse76">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse76" class="accordion-collapse collapse" data-bs-parent="#training76">
                  <div class="accordion-body">
                    ฝึกให้เด็กพูดตามสถานการณ์จริง เช่น ขณะรับประทานอาหารถามเด็กว่า "หนูกำลังทำอะไร" รอให้เด็กตอบ "กินข้าว" หรือ ขณะอ่านหนังสือ ถามเกี่ยวกับรูปภาพในหนังสือ เช่น ชี้ไปที่รูปแมว แล้วถามว่า"แมว ทำอะไร" รอให้เด็กตอบ เช่น "แมววิ่ง" ถ้าเด็กตอบไม่ได้ให้ช่วยตอบนำ และถามซ้ำ เพื่อให้เด็กตอบเองฝึกในสถานการณ์อื่น ๆโดยเด็กต้องใช้วลี 2 คำขึ้นไป ที่ใช้คำกริยาได้ถูกต้อง เช่น ให้ตอบจากบัตรภาพคำกริยา ได้แก่ อาบน้ำ ล้างหน้า แปรงฟัน เป็นต้น<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> ตุ๊กตาคนหรือตุ๊กตาสัตว์ที่มีอยู่ในบ้าน</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 77 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 77 - ร้องเพลงได้บางคำหรือร้องเพลงคลอตามทำนอง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q77_pass_mobile" name="q77_pass" value="1">
                <label class="form-check-label text-success" for="q77_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q77_fail_mobile" name="q77_fail" value="1">
                <label class="form-check-label text-danger" for="q77_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กชวนเด็กร้องเพลงที่เด็กคุ้นเคย<br>
              2. ถ้าเด็กไม่ยอมร้องเพลง ให้ถามจากพ่อแม่ ผู้ปกครองว่าเด็กสามารถร้องเพลงบางคำหรือพูดคำคล้องจองได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถร้องเพลงตามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กได้ โดยอาจร้องชัดแค่บางคำ หรือคลอตามทำนอง</p>
            </div>
            <div class="accordion" id="training77">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading77">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse77">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse77" class="accordion-collapse collapse" data-bs-parent="#training77">
                  <div class="accordion-body">
                    1. ร้องเพลงที่เหมาะสมให้เด็กฟัง เช่น เพลงช้าง เพลงเป็ด หรือเพลงเด็กของท้องถิ่น โดยออกเสียงและทำนองที่ชัดเจน แล้วชวนให้เด็กร้องตาม พร้อมทั้งทำท่าทางประกอบ<br>
                    2. ร้องเพลงเดิมซ้ำบ่อย ๆ เพื่อให้เด็กคุ้นเคยจำได้และกระตุ้นให้เด็กร้องตาม หรือเว้นเพื่อให้เด็กร้องต่อเป็นช่วง ๆ<br>
                    3. เมื่อเด็กเริ่มร้องเพลงเองได้ให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็กร้องตามเด็ก เลือกเปิดเพลงที่มีเนื้อหาเหมาะสมกับเด็กและพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กร้องเพลงต่าง ๆ ร่วมกับเด็กพร้อมทั้งทำท่าประกอบ เช่น เพลงช้าง เพลงเป็ด หรือเป็นเพลงเด็กภาษาอังกฤษ อาจเลือกบทเพลงที่มีความคล้องจองกัน เพื่อส่งเสริมความสนใจศิลปะ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 78 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 78 - เด็กรู้จักรอให้ถึงรอบของตนเองในการเล่นโดยมีผู้ใหญ่คอยบอก (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 30 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ก้อนไม้ 4 ก้อน 2. ถ้วยสำหรับใส่ก้อนไม้ 1 ใบ
              <img src="../image/evaluation_pic/ถ้วยและก้อนไม้ 4 ก้อน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q78_pass_mobile" name="q78_pass" value="1">
                <label class="form-check-label text-success" for="q78_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q78_fail_mobile" name="q78_fail" value="1">
                <label class="form-check-label text-danger" for="q78_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถือก้อนไม้ 2 ก้อน และยื่นก้อนไม้ให้เด็ก 2 ก้อน<br>
              2. วางถ้วยตรงหน้าเด็กและพูดว่า "เรามาใส่ก้อนไม้คนละ 1 ก้อน ให้ถือก้อนไม้ไว้ก่อน ให้ครูใส่ก่อน แล้วหนูค่อยใส่"<br>
              3. สังเกตการรอให้ถึงรอบของเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กรู้จักรอให้ถึงรอบของตนเองเมื่อบอกให้รอ</p>
            </div>
            <div class="accordion" id="training78">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading78">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse78">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse78" class="accordion-collapse collapse" data-bs-parent="#training78">
                  <div class="accordion-body">
                    1. ผลัดกันเล่นกับเด็กจนเด็กคุ้นเคยก่อน<br>
                    2. ฝึกให้เด็กเล่นเป็นกลุ่มด้วยกัน โดยมีพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กบอกเด็ก เช่น "..(ชื่อเด็ก)..เอาห่วงใส่หลัก" "แล้วรอก่อนนะ"<br>
                    3. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก บอกให้เด็กคนต่อไปเอาห่วงใส่หลัก ถ้าเด็กรอไม่ได้ ให้เตือนทุกครั้งจนเด็กรอได้เอง<br>
                    4. ฝึกเล่นกิจกรรมอย่างอื่น เช่น ร้องเพลง/นับเลขพร้อมกันก่อนแล้วค่อยกินขนม หรือในสถานการณ์อย่างอื่นที่ต้องมีการรอให้ถึงรอบของตนเองกับเด็ก เช่น พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กเข้าแถวรอจ่าย เงินเวลาซื้อของ<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ตะกร้าใส่ของ/กล่อง/จาน และของเล่นต่าง ๆ ที่มีในบ้าน</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> ฝึกการควบคุมอารมณ์ตนเองให้รอจนถึงรอบของตัวเอง มีความอดทน รู้จักให้เกียรติผู้อื่น ทำกิจกรรมร่วมกับผู้อื่นได้ตามขั้นตอน</span>
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
      for (let i = 70; i <= 78; i++) {
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
      for (let i = 70; i <= 78; i++) {
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

      for (let i = 70; i <= 78; i++) {
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

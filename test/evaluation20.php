<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '60';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 117-124)
    for ($i = 117; $i <= 124; $i++) {
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
    $total_questions = 8; // แบบประเมินมีทั้งหมด 8 ข้อ (ข้อ 117-124)
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
  <title>แบบประเมิน ช่วงอายุ 60 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 60 เดือน
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
            <!-- ข้อ 117-124 สำหรับ 60 เดือน -->
            <tr>
              <td rowspan="8">60 เดือน</td>
              <td>117<br>
                  <input type="checkbox" id="q117_pass" name="q117_pass" value="1">
                  <label for="q117_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q117_fail" name="q117_fail" value="1">
                  <label for="q117_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เดินต่อเท้าเป็นเส้นตรงไปข้างหน้าได้ (GM)<br><br>
              </td>
              <td>
               แสดงวิธีเดินต่อเท้าไปข้างหน้าให้เด็กดู ประมาณ 8 ก้าว แล้วให้เด็กทำตาม โดยเริ่มต้นให้เด็กก้าวเท้าแรก ให้ปลายเท้าต่อกับส้นเท้าอีกข้างหนึ่ง 
               และทำเช่นนี้ไปต่อเนื่องกันจนถึงเส้นตรงที่กำหนด<br>
                <strong>ผ่าน:</strong> เด็กสามารถเดินต่อเท้าไปข้างหน้าได้ 4 ก้าว โดยไม่เสียการทรงตัวหรือก้าวขาออกนอกเส้นที่กำหนด แม้เพียงเล็กน้อย
                
              </td>
              <td>
               1. เฝึกให้เด็กเดินตรงตามเส้นที่กำหนด เช่น เส้นปูนตามถนน ระยะประมาณ 8 ก้าว 
               แล้วให้เด็กวางปลายเท้าให้ชิดกับส้นเท้าอีกข้างหนึ่ง ทำเช่นนี้ไปเรื่อย ๆ จนถึงเส้นที่กำหนด<br>
               2. เมื่อเด็กเดินได้ต่อเนื่องและตรงเส้นครบ 4 ก้าวแล้ว ให้ฝึกเพิ่มจำนวนก้าวต่อไปเรื่อย ๆ 
               เพื่อช่วยพัฒนาการทรงตัวและความมั่นคงในการก้าวเดิน<br>
               3. ฝึกให้เด็กเดินต่อเท้าไปข้างหน้า โดยทำกิจกรรมร่วมกัน เช่น เดินแข่งกับเพื่อน ๆ เดินตามเส้นที่มีการกำหนดขึ้นในสนามเด็กเล่น 
               เช่น เส้นเชือก เส้นไม้บรรทัดขนาดใหญ่ หรือเส้นที่ขีดไว้ในสนาม<br>
               
              </td>
            </tr>

            <tr>
              <td>118<br>
                  <input type="checkbox" id="q118_pass" name="q118_pass" value="1">
                  <label for="q118_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q118_fail" name="q113_fail" value="1">
                  <label for="q118_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ลอกรูป (FM)<br><br>
              <strong>อุปกรณ์:</strong> รูปสี่เหลี่ยมจัตุรัสขนาด 2.5x2.5 เซนติเมตร บนกระดาษพื้นขาว(เส้นดำบนพื้นขาวความหนาเส้น 2 มิลลิเมตร)<br>
              <img src="../image/evaluation_pic/รูปสี่เหลี่ยมจัตุรัส ขนาด 2.5 x 2.5.png" alt="Rectangle" style="width: 150px; height: 110px;"><br>
                </td>
              <td>
                1. วางกระดาษที่มีรูปสี่เหลี่ยมผืนผ้าไว้ตรงหน้าเด็ก แล้วให้ดินสอแก่เด็ก จากนั้นบอกให้เด็กวาดเส้นตามรอยของรูปสี่เหลี่ยมผืนผ้า โดยไม่ให้เกินเส้นขอบของรูป<br>
                <strong>ผ่าน:</strong>  เด็กสามารถวาดเส้นตามรอยรูปสี่เหลี่ยมผืนผ้าได้ โดยไม่เกินเส้นขอบ อย่างน้อย 2 ใน 3 ครั้ง<br>
                <img src="../image/evaluation_pic/ผ่าน.png" alt="Rectangle" style="width: 150px; height: 110px;">
                <img src="../image/evaluation_pic/ไม่ผ่าน.png" alt="Rectangle" style="width: 150px; height: 110px;">
                
              </td>
              <td>
                1. เริ่มจากให้เด็กฝึกวาดเส้นตามรอยรูปทรงง่าย ๆ เช่น วงกลม สามเหลี่ยม สี่เหลี่ยมผืนผ้า บนกระดาษ โดยให้ใช้ดินสอหรือปากกาที่เหมาะสมกับมือเด็ก<br>
                2. ค่อย ๆ เพิ่มระดับความยากของรูปทรง เช่น วาดรูปดาว หัวใจ หรือรูปที่ซับซ้อนขึ้น เพื่อฝึกการควบคุมกล้ามเนื้อมือและสายตา<br>
                3. ให้เด็กฝึกวาดเส้นต่อเนื่อง เช่น เขียนเส้นตรง เส้นโค้ง เส้นหยัก หรือเส้นซิกแซก เพื่อพัฒนาความคล่องแคล่วของกล้ามเนื้อมือ<br>
              </td>
            </tr>

            <tr>
              <td>119<br>
                  <input type="checkbox" id="q119_pass" name="q119_pass" value="1">
                  <label for="q119_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q119_fail" name="q119_fail" value="1">
                  <label for="q119_fail">ไม่ผ่าน</label><br>
              </td>
              <td>วาดรูปคนได้ 6 ส่วน (FM)<br><br>
              <strong>อุปกรณ์:</strong>กระดาษและดินสอ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Paper and Pencil" style="width: 150px; height: 160px;">
            </td>
              <td>
                วางกระดาษและดินสอไว้ข้างหน้าเด็ก บอกเด็กว่า “ครูวาดคน ให้หนูวาด 1 รูปนะ” แล้วให้เด็กวาดรูปตามที่บอก (ไม่ใช้การวาดตามตัวอย่าง)<br>
                
                <strong>ผ่าน:</strong>  เด็กสามารถวาดรูปคนได้ 6 ส่วนขึ้นไป ส่วนที่นับเป็น 1 ส่วน เช่น หู ตา ขา แขน ขา เท้า (หากวาดรวมเป็น 1 ข้าง นับเป็น 1 ส่วน)<br>
                <!--<img src="../image/evaluation_pic/รูปคนมาตราส่วน.png" alt="Drawing Person" style="width: 500px; height: 180px;"> -->
                
              </td>
              <td>
                1. ชี้แนะเด็กให้วาดรูปคนเป็นส่วนประกอบของประโยค เช่น เมื่อพูดว่า “เด็กกำลังกินข้าว” เด็กต้องวาดรูปคน และเพิ่มรายละเอียดอื่น ๆ เช่น ปาก กำลังอ้ากินข้าว มีช้อน มีถ้วย<br>
                2. ให้เด็กวาดรูปตามคำบอก เช่น ครูบอกให้วาดรูปเด็กผู้หญิง เด็กต้องวาดผมยาว วาดกระโปรง และใบหน้า<br>
                3. สอนให้เด็กวาดรายละเอียดเพิ่มเติม เช่น ตา คิ้ว ปาก หู มือ เท้า เพื่อให้วาดครบ 6 ส่วนขึ้นไป<br>
                4. ควรกระตุ้นให้เด็กสังเกตร่างกายของตนเองและคนรอบข้าง เพื่อให้เข้าใจว่ามีอวัยวะอะไรบ้าง และนำมาวาดภาพตามที่สังเกตเห็น<br>
                5. ให้เด็กวาดภาพร่วมกับการเล่านิทาน เช่น เมื่อเล่านิทานหนูน้อยหมวกแดง ให้เด็กวาดรูปตัวละคร แล้วเล่าไปพร้อมกันว่า ตัวละครนั้นกำลังทำอะไร<br>
               
              </td>
            </tr>

            <tr>
              <td>120<br>
                  <input type="checkbox" id="q120_pass" name="q120_pass" value="1">
                  <label for="q120_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q120_fail" name="q120_fail" value="1">
                  <label for="q120_fail">ไม่ผ่าน</label><br>
              </td>
              <td>จับใจความเมื่อฟังนิทานหรือเรื่องเล่าได้ (RL)<br><br>
              <strong>อุปกรณ์</strong> หนังสือนิทานในสวน<br>
              <img src="../image/evaluation_pic/นิทานในสวน.png" alt="Storybook" style="width: 150px; height: 120px;">
              </td>
              <td>
                1. บอกเด็กว่า “ครูจะเล่านิทานให้ฟัง หนูตั้งใจฟังนะคะ แล้วครูจะถามให้หนูตอบ นะคะ”<br>
                2. เล่าเรื่องนิทานในสวน ให้เด็กฟัง ตามใบ นิทานจบ ใช้เวลาประมาณ 2-3 นาที พูดอย่างชัดเจน และน่าสนุก แล้วถามคำถาม ก. “หนูลองบอกครูซิว่า ในภาพนี้เกิดขึ้นกับ อะไร หรือเรื่องราวเป็นยังไงคะ” ให้เด็กตอบ 
                ข. เมื่อเพื่อน ๆ แวะมาวาดให้ไปวิ่งเล่น กับขนมปัง” ให้เด็กตอบ ถ้าเด็กยังตอบไม่ได้ ให้เล่าได้อีก 1 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถผลัดกันพูดโต้ตอบในกลุ่มได้เด็กสามารถใช้คำพูดบอกเรื่องราวที่ครอบคลุมเนื้อหา ได้ทั้งข้อ ก และข้อ ข 
                ก. อย่างน้อยได้ใจความ 1 ใน 2 ประเด็น ดังนี้กระต่ายขาว ขยัน ชอบทำสวน และขุดบ่อเก็บน้ำ เมื่อต้นฟักผัก ก็งดีขึ้นไปแบ่งให้เพื่อนอื่น ๆ กินเพื่อน ๆ ที่มีแต่ชวนไปเที่ยวเล่น ถึงเวลาน้ำแล้งก็ไม่มีผักกิน กระต่ายขาวก็ขวนขวายไปตักเอามาแบ่งให้
                ข. เด็กสามารถตอบคำถามได้ความ ประมาณนี้ “กระต่ายขาว ตั้งใจทำงานให้เสร็จ/ กระต่ายขาวขยันจริงไม่สนกับการทำสวนของตัวเอง เพื่อน ๆ จึงลำบากถึงสวนมากกว่าไปวิ่งเล่น เพราะมีประโยชน์มากกว่า (มีพืชผักไว้กิน)”
               
              </td>
              <td>
                1. กพูดคุย เล่านิทานให้เด็กฟัง ชวนเด็กพูดคุยเกี่ยวกับเรื่องที่เล่า ให้เด็กพูดถึงสิ่งที่ชอบในเรื่องนั้น โดยพ่อแม่ ผู้ปกครองสรุปใจความว่าเป็นเรื่องอะไร ใครทำอะไร ที่ไหน อย่างไร<br>
                2. ให้เด็กเล่าเรื่องนั้นให้กับคนในครอบครัวฟัง โดยผู้ใหญ่แสดงความสนใจฟัง และชวนพูดคุยขยายความต่อยอด หรือซักถามเด็กเพิ่มเติม เพื่อให้มีโอกาสแสดงความคิดและเปลี่ยนกัน<br>
                3. เลือกนิทานที่มีเนื้อหาเหมาะในหลายช่วงวัย ปลูกฝังคุณธรรม ช่วยกระตุ้นพัฒนาการทางด้านอารมณ์ และสามารถนำไปใช้ในชีวิตประจำวันได้<br>
                
              </td>
            </tr>

            <tr>
              <td>121<br>
                  <input type="checkbox" id="q121_pass" name="q121_pass" value="1">
                  <label for="q121_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q121_fail" name="q121_fail" value="1">
                  <label for="q121_fail">ไม่ผ่าน</label><br>
              </td>
              <td>นับก้อนไม้ 5 ก้อน (รู้จำนวนเท่ากับ 5) (RL)<br><br>
              <strong>อุปกรณ์</strong> ก้อนไม้ 8 ก้อนและกระดาษ 1 แผ่น<br>
              <img src="../image/evaluation_pic/ก้อนไม้ 8 ก้อน กระดาษ.png" alt="Wooden Blocks" style="width: 150px; height: 120px;">
                </td>
              <td>
                วางก้อนไม้ 8 ก้อนไว้บนโต๊ะ ข้างหน้าเด็ก วางกระดาษ 1 แผ่น ใช้ช้างก้อนไม้บอกเด็กว่า “หยิบก้อนไม้ 5 ก้อนไว้บนกระดาษ” เมื่อตักทำเสร็จ ถามเด็กว่า “บนนกระดาษมีก้อนไม้กี่ก้อน”<br>
                <strong>ผ่าน:</strong> เด็กวางก้อนไม้ 5 ก้อน และบอกจำนวนถูกต้องโดยไม่ต้องนับ 2-3-4-5 ถ้าเด็กทำไม่ผ่านในการนับให้โอกาสทำอีก 1 ครั้ง
               
              </td>
              <td>
               1. เล่นกับเด็ก สอนให้นับสิ่งของเพิ่มขึ้นทีละชิ้น<br>
               2. ฝึกเด็กให้รู้จักจำนวน โดยนำสิ่งของมากกว่า 5 ชิ้นมาวางไว้ นับให้เป็นตัวอย่าง แล้วสักให้เด็กหยิบสิ่งของมา 2 ชิ้น แล้วค่อย ๆ เพิ่มจำนวนเป็น 5 ชิ้น<br>
               3. เมื่อเด็กนับสิ่งของ 5 ชิ้นแล้ว ค่อยเพิ่มจำนวนมากขึ้น<br>
               4. สอนให้เด็กรู้จักตัวเลขจากบัตร 1-5 แล้วนำไปจับคู่ของมาเรียงตามจำนวนของตัวเลขนั้น ๆ เมื่อนักเด็กได้แล้ว เพิ่มจำนวนตัวเลขเป็น 6-10 <br>
               
              </td>
            </tr>

            <tr>
              <td>122<br>
                  <input type="checkbox" id="q122_pass" name="q122_pass" value="1">
                  <label for="q122_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q122_fail" name="q122_fail" value="1">
                  <label for="q122_fail">ไม่ผ่าน</label><br>
              </td>
              <td>อ่านออกเสียงพยัญชนะได้ถูกต้อง 5 ตัว เช่น “ก” “ง” “ด” “น” “ย” (EL)<br><br>
              <strong>อุปกรณ์</strong> แผ่นพยัญชนะ “ก” “ง” “ด” “น” “ย”<br>
              <img src="../image/evaluation_pic/แผ่นพยัญชนะ.png" alt="Alphabet Cards" style="width: 200px; height: 150px;">
                </td>
              <td>
                ภาพแผ่นพยัญชนะทั้ง 5 แผ่น วางเรียงลำดับไม่ซ้ำกัน แล้วชี้ไปที่พยัญชนะทีละตัว บอกเด็กว่า “เด็กน้อย บอกซิว่า ตัวนี้อ่านว่าอะไร” ได้แก่ตัว “ก” “ง” “ด” “น” “ย”<br>
                <strong>ผ่าน:</strong> เด็กอ่านออกเสียงพยัญชนะได้ถูกต้องทั้ง 5 ตัว เช่น ตัว "ก" เด็กอ่านได้ว่า "กอ" หรือ "กอไก่"หากเด็กทำไม่ได้ในครั้งแรก ให้โอกาสทำอีก 1 ครั้งเฉพาะพยัญชนะตัวนั้น โดยไม่ต้องอ่านซ้ำทั้ง 5 ตัว
               
              </td>
              <td>
               1. นำหนังสือหรือแผ่นภาพที่มีตัวพยัญชนะไทย ชี้ตัวพยัญชนะแล้วอ่านให้เด็กฟังทีละตัว เพื่อให้เด็กรู้จักและพูดตาม เช่น ชี้ตัว ก แล้วพูดว่า กอ ไก่ และให้เด็กพูดตาม<br>
               2. ให้เด็กชี้ตัวพยัญชนะ แล้วผู้ใหญ่อ่าน จากนั้นให้เด็กอ่านตาม<br>
               3. ให้เด็กชี้ตัวพยัญชนะและอ่านเอง<br>
               4. พูดถึงคำที่ใช้เสียงพยัญชนะนั้น ๆ เป็นเสียงต้น/พยัญชนะต้น เช่น ก ออกเสียง กอ เป็นเสียงต้น/พยัญชนะต้นของคำว่า ไก่ กบ กำ แก้ว กล้วย กิน เป็นต้น<br>
               5. ต่อไปค่อย ๆ เพิ่มให้เด็กได้เรียนรู้รูปและเสียงของพยัญชนะตัวอื่น ๆ และสระง่าย ๆ เช่น สระ อำ<br>
               
              </td>
            </tr>

             <tr>
              <td>123<br>
                  <input type="checkbox" id="q123_pass" name="q123_pass" value="1">
                  <label for="q123_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q123_fail" name="q123_fail" value="1">
                  <label for="q123_fail">ไม่ผ่าน</label><br>
              </td>
              <td>รู้จักคำพูดแบบมรเหตุผล(EL)<br><br>

                </td>
              <td>
                1. ถามพ่อ แม่ ผู้ปกครอง ครู และผู้ดูแลเด็ก ว่า เด็กมีการพูดอย่างมีเหตุผลด้วยการใช้คำว่า “ทำไม” ในการถามหรือไม่ เช่น “ทำไมต้องกินข้าว” / “ทำไมต้องนอน”<br>
                2. ถามคำถาม 3 ข้อ และให้เด็กตอบทีละข้อ ดังนี้“ทำไมหนูต้องล้างมือ”  “ทำไมหนูต้องกินผัก”  “เวลาเล่นเสร็จ ทำไมหนูต้องเก็บของเล่น<br>
                <strong>ผ่าน:</strong> เด็กทำได้ทั้งข้อ 1 และข้อ 2 พ่อ แม่ ผู้ปกครอง ครู และผู้ดูแลเด็กตอบว่า เด็กมีการใช้คำว่า “ทำไม” ในการตั้งคำถาม เช่น “ทำไมต้องกินข้าว” / “ทำไมหนูต้องนอน”
                2. เด็กตอบได้อย่างมีเหตุผล อย่างน้อย 1 ใน 3 ข้อ เช่นมือสกปรกต้องล้างมือก่อนกินข้าวกินผักทำให้ร่างกายแข็งแรง(ถ้ามีคำว่า “เพราะ” นำหน้า ถือว่าดีมาก ถูกต้องตามหลักการใช้ภาษาไทย)
              </td>
              <td>
               1. พ่อ แม่ ผู้ปกครองอธิบายถึงเหตุผลและทำเป็นตัวอย่างเกี่ยวกับการทำกิจวัตรในชีวิตประจำวัน เช่น ทำไมต้องแปรงฟัน ทำไมต้องล้างมือ ทำไมต้องรับประทานผัก<br>
               2. พ่อ แม่ ต้องไม่ให้เหตุผลผิด ๆ หรือหลอกลูก เช่น ไม่รับประทานข้าวให้หมดเดี๋ยวตำรวจจับ ตุ๊กแกกินตับ ผีหลอก แต่ควรอธิบายด้วยเหตุผลง่าย ๆ และทำเป็นตัวอย่างที่ถูกต้อง<br>
               3. ในสถานการณ์ที่เกิดขึ้นจริง ให้ลูกอธิบายถึงเหตุที่เกิดขึ้น เช่น ทำไมเวลาไอต้องปิดปาก<br>
               4. ทำกิจกรรมร่วมกับลูกหรือชวนลูกทำงานด้วยกัน แล้วให้ลูกสังเกต ชวนให้เกิดคำถามและคิดหาเหตุผล เช่น ชวนทำกับข้าว ให้สังเกตว่าไข่สุกกับไข่ดิบเป็นอย่างไร ทำขนมที่ลูกได้เห็นการแปรสภาพ เช่น ขนมครก ขนมเค้ก ขนมกล้วย การพับกระดาษเป็นรูปต่าง ๆ<br>   
              </td>
            </tr>

            <tr>
              <td>124<br>
                  <input type="checkbox" id="q124_pass" name="q124_pass" value="1">
                  <label for="q124_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q124_fail" name="q124_fail" value="1">
                  <label for="q124_fail">ไม่ผ่าน</label><br>
              </td>
              <td>แสดงควำมเห็นอกเห็นใจเมื่อเห็นเพื่อนเจ็บหรือไม่สบาย(PS)<br><br>

                </td>
              <td>
                ถามเด็กว่า “เมื่อหนูเห็นเพื่อนร้องไห้เพราะเสียใจหรือเจ็บ หนูจะทำอย่างไร”<br>
                
                <strong>ผ่าน:</strong> เด็กตอบแสดงความเห็นอกเห็นใจ เช่นหนูเข้าไปช่วยเพื่อนหนูปลอบเพื่อนหนูบอกครู/ผู้ใหญ่ให้มาช่วยเพื่อน
              </td>
              <td>
               1. สอนให้เด็กรู้จักอารมณ์ความรู้สึกของตนเองและเข้าใจอารมณ์ความรู้สึกของผู้อื่น (ให้ดูสีหน้าจริงหรือใช้รูปภาพประกอบ) สื่อ : รูปภาพที่แสดงถึงอารมณ์แบบต่าง ๆ เช่น ใบหน้ายิ้มมีความสุข ใบหน้าเศร้าเสียใจ ตื่นเต้น ตกใจ เป็นต้น<br>
               2. ทำตัวเป็นแบบอย่างในการแสดงความรู้สึกเห็นใจ และช่วยเหลือผู้อื่น<br>
               3. เล่านิทาน หรือเล่นบทบาทสมมติในเรื่องการช่วยเหลือผู้อื่น เช่น เมื่อเห็นเพื่อน ญาติ หรือคนหกล้ม<br>
               4. ส่งเสริมหรือชี้แนะให้เด็กรู้สึกเห็นใจ และแนะนำให้เด็กแสดงความห่วงใยด้วยการพูด หรือการแสดงความเห็นอกเห็นใจ เข้าไปช่วยเหลือ<br>
               5. ชมเชยและชี้ให้เห็นผลที่เกิดจากการกระทำดีของเด็ก<br> 
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
        <!-- Card ข้อที่ 117 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 117 - เดินต่อเท้าเป็นเส้นตรงไปข้างหน้าได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q117_pass_mobile" name="q117_pass" value="1">
                <label class="form-check-label text-success" for="q117_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q117_fail_mobile" name="q117_fail" value="1">
                <label class="form-check-label text-danger" for="q117_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">แสดงวิธีเดินต่อเท้าไปข้างหน้าให้เด็กดู ประมาณ 8 ก้าว แล้วให้เด็กทำตาม โดยเริ่มต้นให้เด็กก้าวเท้าแรก ให้ปลายเท้าต่อกับส้นเท้าอีกข้างหนึ่ง และทำเช่นนี้ไปต่อเนื่องกันจนถึงเส้นตรงที่กำหนด</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเดินต่อเท้าไปข้างหน้าได้ 4 ก้าว โดยไม่เสียการทรงตัวหรือก้าวขาออกนอกเส้นที่กำหนด แม้เพียงเล็กน้อย</p>
            </div>
            <div class="accordion" id="training117">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading117">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse117">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse117" class="accordion-collapse collapse" data-bs-parent="#training117">
                  <div class="accordion-body">
                    1. เฝึกให้เด็กเดินตรงตามเส้นที่กำหนด เช่น เส้นปูนตามถนน ระยะประมาณ 8 ก้าว แล้วให้เด็กวางปลายเท้าให้ชิดกับส้นเท้าอีกข้างหนึ่ง ทำเช่นนี้ไปเรื่อย ๆ จนถึงเส้นที่กำหนด<br>
                    2. เมื่อเด็กเดินได้ต่อเนื่องและตรงเส้นครบ 4 ก้าวแล้ว ให้ฝึกเพิ่มจำนวนก้าวต่อไปเรื่อย ๆ เพื่อช่วยพัฒนาการทรงตัวและความมั่นคงในการก้าวเดิน<br>
                    3. ฝึกให้เด็กเดินต่อเท้าไปข้างหน้า โดยทำกิจกรรมร่วมกัน เช่น เดินแข่งกับเพื่อน ๆ เดินตามเส้นที่มีการกำหนดขึ้นในสนามเด็กเล่น เช่น เส้นเชือก เส้นไม้บรรทัดขนาดใหญ่ หรือเส้นที่ขีดไว้ในสนาม
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 118 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 118 - ลอกรูป (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. รูปสี่เหลี่ยมผืนผ้า ขนาด 2.5 x 5.25 เซนติเมตร บนกระดาษขาวหนา (เส้นขอบหนาขนาดความกว้างเส้น 2 มิลลิเมตร) 2. ดินสอ
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q118_pass_mobile" name="q118_pass" value="1">
                <label class="form-check-label text-success" for="q118_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q118_fail_mobile" name="q118_fail" value="1">
                <label class="form-check-label text-danger" for="q118_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางกระดาษที่มีรูปสี่เหลี่ยมผืนผ้าไว้ตรงหน้าเด็ก แล้วให้ดินสอแก่เด็ก จากนั้นบอกให้เด็กวาดเส้นตามรอยของรูปสี่เหลี่ยมผืนผ้า โดยไม่ให้เกินเส้นขอบของรูป</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวาดเส้นตามรอยรูปสี่เหลี่ยมผืนผ้าได้ โดยไม่เกินเส้นขอบ อย่างน้อย 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training118">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading118">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse118">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse118" class="accordion-collapse collapse" data-bs-parent="#training118">
                  <div class="accordion-body">
                    1. เริ่มจากให้เด็กฝึกวาดเส้นตามรอยรูปทรงง่าย ๆ เช่น วงกลม สามเหลี่ยม สี่เหลี่ยมผืนผ้า บนกระดาษ โดยให้ใช้ดินสอหรือปากกาที่เหมาะสมกับมือเด็ก<br>
                    2. ค่อย ๆ เพิ่มระดับความยากของรูปทรง เช่น วาดรูปดาว หัวใจ หรือรูปที่ซับซ้อนขึ้น เพื่อฝึกการควบคุมกล้ามเนื้อมือและสายตา<br>
                    3. ให้เด็กฝึกวาดเส้นต่อเนื่อง เช่น เขียนเส้นตรง เส้นโค้ง เส้นหยัก หรือเส้นซิกแซก เพื่อพัฒนาความคล่องแคล่วของกล้ามเนื้อมือ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 119 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 119 - วาดรูปคนได้ 6 ส่วน (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> กระดาษและดินสอ
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q119_pass_mobile" name="q119_pass" value="1">
                <label class="form-check-label text-success" for="q119_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q119_fail_mobile" name="q119_fail" value="1">
                <label class="form-check-label text-danger" for="q119_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางกระดาษและดินสอไว้ข้างหน้าเด็ก บอกเด็กว่า "ครูวาดคน ให้หนูวาด 1 รูปนะ" แล้วให้เด็กวาดรูปตามที่บอก (ไม่ใช้การวาดตามตัวอย่าง)</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวาดรูปคนได้ 6 ส่วนขึ้นไป ส่วนที่นับเป็น 1 ส่วน เช่น หู ตา ขา แขน ขา เท้า (หากวาดรวมเป็น 1 ข้าง นับเป็น 1 ส่วน)</p>
            </div>
            <div class="accordion" id="training119">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading119">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse119">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse119" class="accordion-collapse collapse" data-bs-parent="#training119">
                  <div class="accordion-body">
                    1. ชี้แนะเด็กให้วาดรูปคนเป็นส่วนประกอบของประโยค เช่น เมื่อพูดว่า "เด็กกำลังกินข้าว" เด็กต้องวาดรูปคน และเพิ่มรายละเอียดอื่น ๆ เช่น ปาก กำลังอ้ากินข้าว มีช้อน มีถ้วย<br>
                    2. ให้เด็กวาดรูปตามคำบอก เช่น ครูบอกให้วาดรูปเด็กผู้หญิง เด็กต้องวาดผมยาว วาดกระโปรง และใบหน้า<br>
                    3. สอนให้เด็กวาดรายละเอียดเพิ่มเติม เช่น ตา คิ้ว ปาก หู มือ เท้า เพื่อให้วาดครบ 6 ส่วนขึ้นไป<br>
                    4. ควรกระตุ้นให้เด็กสังเกตร่างกายของตนเองและคนรอบข้าง เพื่อให้เข้าใจว่ามีอวัยวะอะไรบ้าง และนำมาวาดภาพตามที่สังเกตเห็น<br>
                    5. ให้เด็กวาดภาพร่วมกับการเล่านิทาน เช่น เมื่อเล่านิทานหนูน้อยหมวกแดง ให้เด็กวาดรูปตัวละคร แล้วเล่าไปพร้อมกันว่า ตัวละครนั้นกำลังทำอะไร
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 120 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 120 - จับใจความเมื่อฟังนิทานหรือเรื่องเล่าได้ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> หนังสือนิทานในสวน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q120_pass_mobile" name="q120_pass" value="1">
                <label class="form-check-label text-success" for="q120_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q120_fail_mobile" name="q120_fail" value="1">
                <label class="form-check-label text-danger" for="q120_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. บอกเด็กว่า "ครูจะเล่านิทานให้ฟัง หนูตั้งใจฟังนะคะ แล้วครูจะถามให้หนูตอบ นะคะ"<br>
              2. เล่าเรื่องนิทานในสวน ให้เด็กฟัง ตามใบ นิทานจบ ใช้เวลาประมาณ 2-3 นาที พูดอย่างชัดเจน และน่าสนุก แล้วถามคำถาม ก. "หนูลองบอกครูซิว่า ในภาพนี้เกิดขึ้นกับ อะไร หรือเรื่องราวเป็นยังไงคะ" ให้เด็กตอบ ข. เมื่อเพื่อน ๆ แวะมาวาดให้ไปวิ่งเล่น กับขนมปัง" ให้เด็กตอบ ถ้าเด็กยังตอบไม่ได้ ให้เล่าได้อีก 1 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใช้คำพูดบอกเรื่องราวที่ครอบคลุมเนื้อหา ได้ทั้งข้อ ก และข้อ ข ก. อย่างน้อยได้ใจความ 1 ใน 2 ประเด็น ดังนี้กระต่ายขาว ขยัน ชอบทำสวน และขุดบ่อเก็บน้ำ เมื่อต้นฟักผัก ก็งดีขึ้นไปแบ่งให้เพื่อนอื่น ๆ กินเพื่อน ๆ ที่มีแต่ชวนไปเที่ยวเล่น ถึงเวลาน้ำแล้งก็ไม่มีผักกิน กระต่ายขาวก็ขวนขวายไปตักเอามาแบ่งให้ ข. เด็กสามารถตอบคำถามได้ความ ประมาณนี้ "กระต่ายขาว ตั้งใจทำงานให้เสร็จ/ กระต่ายขาวขยันจริงไม่สนกับการทำสวนของตัวเอง เพื่อน ๆ จึงลำบากถึงสวนมากกว่าไปวิ่งเล่น เพราะมีประโยชน์มากกว่า (มีพืชผักไว้กิน)"</p>
            </div>
            <div class="accordion" id="training120">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading120">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse120">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse120" class="accordion-collapse collapse" data-bs-parent="#training120">
                  <div class="accordion-body">
                    1. กพูดคุย เล่านิทานให้เด็กฟัง ชวนเด็กพูดคุยเกี่ยวกับเรื่องที่เล่า ให้เด็กพูดถึงสิ่งที่ชอบในเรื่องนั้น โดยพ่อแม่ ผู้ปกครองสรุปใจความว่าเป็นเรื่องอะไร ใครทำอะไร ที่ไหน อย่างไร<br>
                    2. ให้เด็กเล่าเรื่องนั้นให้กับคนในครอบครัวฟัง โดยผู้ใหญ่แสดงความสนใจฟัง และชวนพูดคุยขยายความต่อยอด หรือซักถามเด็กเพิ่มเติม เพื่อให้มีโอกาสแสดงความคิดและเปลี่ยนกัน<br>
                    3. เลือกนิทานที่มีเนื้อหาเหมาะในหลายช่วงวัย ปลูกฝังคุณธรรม ช่วยกระตุ้นพัฒนาการทางด้านอารมณ์ และสามารถนำไปใช้ในชีวิตประจำวันได้
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 121 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 121 - นับก้อนไม้ 5 ก้อน (รู้จำนวนเท่ากับ 5) (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ก้อนไม้ 8 ก้อนและกระดาษ 1 แผ่น
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q121_pass_mobile" name="q121_pass" value="1">
                <label class="form-check-label text-success" for="q121_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q121_fail_mobile" name="q121_fail" value="1">
                <label class="form-check-label text-danger" for="q121_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางก้อนไม้ 8 ก้อนไว้บนโต๊ะ ข้างหน้าเด็ก วางกระดาษ 1 แผ่น ใช้ช้างก้อนไม้บอกเด็กว่า "หยิบก้อนไม้ 5 ก้อนไว้บนกระดาษ" เมื่อตักทำเสร็จ ถามเด็กว่า "บนนกระดาษมีก้อนไม้กี่ก้อน"</p>
              <p><strong>ผ่าน:</strong> เด็กวางก้อนไม้ 5 ก้อน และบอกจำนวนถูกต้องโดยไม่ต้องนับ 2-3-4-5 ถ้าเด็กทำไม่ผ่านในการนับให้โอกาสทำอีก 1 ครั้ง</p>
            </div>
            <div class="accordion" id="training121">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading121">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse121">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse121" class="accordion-collapse collapse" data-bs-parent="#training121">
                  <div class="accordion-body">
                    1. เล่นกับเด็ก สอนให้นับสิ่งของเพิ่มขึ้นทีละชิ้น<br>
                    2. ฝึกเด็กให้รู้จักจำนวน โดยนำสิ่งของมากกว่า 5 ชิ้นมาวางไว้ นับให้เป็นตัวอย่าง แล้วสักให้เด็กหยิบสิ่งของมา 2 ชิ้น แล้วค่อย ๆ เพิ่มจำนวนเป็น 5 ชิ้น<br>
                    3. เมื่อเด็กนับสิ่งของ 5 ชิ้นแล้ว ค่อยเพิ่มจำนวนมากขึ้น<br>
                    4. สอนให้เด็กรู้จักตัวเลขจากบัตร 1-5 แล้วนำไปจับคู่ของมาเรียงตามจำนวนของตัวเลขนั้น ๆ เมื่อนักเด็กได้แล้ว เพิ่มจำนวนตัวเลขเป็น 6-10
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 122 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 122 - อ่านออกเสียงพยัญชนะได้ถูกต้อง 5 ตัว เช่น "ก" "ง" "ด" "น" "ย" (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> แผ่นพยัญชนะ "ก" "ง" "ด" "น" "ย"
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q122_pass_mobile" name="q122_pass" value="1">
                <label class="form-check-label text-success" for="q122_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q122_fail_mobile" name="q122_fail" value="1">
                <label class="form-check-label text-danger" for="q122_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ภาพแผ่นพยัญชนะทั้ง 5 แผ่น วางเรียงลำดับไม่ซ้ำกัน แล้วชี้ไปที่พยัญชนะทีละตัว บอกเด็กว่า "เด็กน้อย บอกซิว่า ตัวนี้อ่านว่าอะไร" ได้แก่ตัว "ก" "ง" "ด" "น" "ย"</p>
              <p><strong>ผ่าน:</strong> เด็กอ่านออกเสียงพยัญชนะได้ถูกต้องทั้ง 5 ตัว เช่น ตัว "ก" เด็กอ่านได้ว่า "กอ" หรือ "กอไก่"หากเด็กทำไม่ได้ในครั้งแรก ให้โอกาสทำอีก 1 ครั้งเฉพาะพยัญชนะตัวนั้น โดยไม่ต้องอ่านซ้ำทั้ง 5 ตัว</p>
            </div>
            <div class="accordion" id="training122">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading122">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse122">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse122" class="accordion-collapse collapse" data-bs-parent="#training122">
                  <div class="accordion-body">
                    1. นำหนังสือหรือแผ่นภาพที่มีตัวพยัญชนะไทย ชี้ตัวพยัญชนะแล้วอ่านให้เด็กฟังทีละตัว เพื่อให้เด็กรู้จักและพูดตาม เช่น ชี้ตัว ก แล้วพูดว่า กอ ไก่ และให้เด็กพูดตาม<br>
                    2. ให้เด็กชี้ตัวพยัญชนะ แล้วผู้ใหญ่อ่าน จากนั้นให้เด็กอ่านตาม<br>
                    3. ให้เด็กชี้ตัวพยัญชนะและอ่านเอง<br>
                    4. พูดถึงคำที่ใช้เสียงพยัญชนะนั้น ๆ เป็นเสียงต้น/พยัญชนะต้น เช่น ก ออกเสียง กอ เป็นเสียงต้น/พยัญชนะต้นของคำว่า ไก่ กบ กำ แก้ว กล้วย กิน เป็นต้น<br>
                    5. ต่อไปค่อย ๆ เพิ่มให้เด็กได้เรียนรู้รูปและเสียงของพยัญชนะตัวอื่น ๆ และสระง่าย ๆ เช่น สระ อำ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 123 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 123 - รู้จักคำพูดแบบมรเหตุผล (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q123_pass_mobile" name="q123_pass" value="1">
                <label class="form-check-label text-success" for="q123_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q123_fail_mobile" name="q123_fail" value="1">
                <label class="form-check-label text-danger" for="q123_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามพ่อ แม่ ผู้ปกครอง ครู และผู้ดูแลเด็ก ว่า เด็กมีการพูดอย่างมีเหตุผลด้วยการใช้คำว่า "ทำไม" ในการถามหรือไม่ เช่น "ทำไมต้องกินข้าว" / "ทำไมต้องนอน"<br>
              2. ถามคำถาม 3 ข้อ และให้เด็กตอบทีละข้อ ดังนี้"ทำไมหนูต้องล้างมือ" "ทำไมหนูต้องกินผัก" "เวลาเล่นเสร็จ ทำไมหนูต้องเก็บของเล่น</p>
              <p><strong>ผ่าน:</strong> เด็กทำได้ทั้งข้อ 1 และข้อ 2 พ่อ แม่ ผู้ปกครอง ครู และผู้ดูแลเด็กตอบว่า เด็กมีการใช้คำว่า "ทำไม" ในการตั้งคำถาม เช่น "ทำไมต้องกินข้าว" / "ทำไมหนูต้องนอน" 2. เด็กตอบได้อย่างมีเหตุผล อย่างน้อย 1 ใน 3 ข้อ เช่นมือสกปรกต้องล้างมือก่อนกินข้าวกินผักทำให้ร่างกายแข็งแรง(ถ้ามีคำว่า "เพราะ" นำหน้า ถือว่าดีมาก ถูกต้องตามหลักการใช้ภาษาไทย)</p>
            </div>
            <div class="accordion" id="training123">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading123">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse123">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse123" class="accordion-collapse collapse" data-bs-parent="#training123">
                  <div class="accordion-body">
                    1. พ่อ แม่ ผู้ปกครองอธิบายถึงเหตุผลและทำเป็นตัวอย่างเกี่ยวกับการทำกิจวัตรในชีวิตประจำวัน เช่น ทำไมต้องแปรงฟัน ทำไมต้องล้างมือ ทำไมต้องรับประทานผัก<br>
                    2. พ่อ แม่ ต้องไม่ให้เหตุผลผิด ๆ หรือหลอกลูก เช่น ไม่รับประทานข้าวให้หมดเดี๋ยวตำรวจจับ ตุ๊กแกกินตับ ผีหลอก แต่ควรอธิบายด้วยเหตุผลง่าย ๆ และทำเป็นตัวอย่างที่ถูกต้อง<br>
                    3. ในสถานการณ์ที่เกิดขึ้นจริง ให้ลูกอธิบายถึงเหตุที่เกิดขึ้น เช่น ทำไมเวลาไอต้องปิดปาก<br>
                    4. ทำกิจกรรมร่วมกับลูกหรือชวนลูกทำงานด้วยกัน แล้วให้ลูกสังเกต ชวนให้เกิดคำถามและคิดหาเหตุผล เช่น ชวนทำกับข้าว ให้สังเกตว่าไข่สุกกับไข่ดิบเป็นอย่างไร ทำขนมที่ลูกได้เห็นการแปรสภาพ เช่น ขนมครก ขนมเค้ก ขนมกล้วย การพับกระดาษเป็นรูปต่าง ๆ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 124 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 124 - แสดงควำมเห็นอกเห็นใจเมื่อเห็นเพื่อนเจ็บหรือไม่สบาย (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 60 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q124_pass_mobile" name="q124_pass" value="1">
                <label class="form-check-label text-success" for="q124_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q124_fail_mobile" name="q124_fail" value="1">
                <label class="form-check-label text-danger" for="q124_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามเด็กว่า "เมื่อหนูเห็นเพื่อนร้องไห้เพราะเสียใจหรือเจ็บ หนูจะทำอย่างไร"</p>
              <p><strong>ผ่าน:</strong> เด็กตอบแสดงความเห็นอกเห็นใจ เช่นหนูเข้าไปช่วยเพื่อนหนูปลอบเพื่อนหนูบอกครู/ผู้ใหญ่ให้มาช่วยเพื่อน</p>
            </div>
            <div class="accordion" id="training124">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading124">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse124">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse124" class="accordion-collapse collapse" data-bs-parent="#training124">
                  <div class="accordion-body">
                    1. สอนให้เด็กรู้จักอารมณ์ความรู้สึกของตนเองและเข้าใจอารมณ์ความรู้สึกของผู้อื่น (ให้ดูสีหน้าจริงหรือใช้รูปภาพประกอบ) สื่อ : รูปภาพที่แสดงถึงอารมณ์แบบต่าง ๆ เช่น ใบหน้ายิ้มมีความสุข ใบหน้าเศร้าเสียใจ ตื่นเต้น ตกใจ เป็นต้น<br>
                    2. ทำตัวเป็นแบบอย่างในการแสดงความรู้สึกเห็นใจ และช่วยเหลือผู้อื่น<br>
                    3. เล่านิทาน หรือเล่นบทบาทสมมติในเรื่องการช่วยเหลือผู้อื่น เช่น เมื่อเห็นเพื่อน ญาติ หรือคนหกล้ม<br>
                    4. ส่งเสริมหรือชี้แนะให้เด็กรู้สึกเห็นใจ และแนะนำให้เด็กแสดงความห่วงใยด้วยการพูด หรือการแสดงความเห็นอกเห็นใจ เข้าไปช่วยเหลือ<br>
                    5. ชมเชยและชี้ให้เห็นผลที่เกิดจากการกระทำดีของเด็ก
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
      for (let i = 117; i <= 124; i++) {
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
      for (let i = 117; i <= 124; i++) {
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

      for (let i = 117; i <= 124; i++) {
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

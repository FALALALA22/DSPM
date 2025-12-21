<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '73-78';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 135-139)
    for ($i = 135; $i <= 139; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 135-139)
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
  <title>แบบประเมิน ช่วงอายุ 73 ถึง 78 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 73 - 78 เดือน
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
            <!-- ข้อ 135-139 สำหรับ 73-78 เดือน -->
            <tr>
              <td rowspan="5">73 - 78 เดือน</td>
              <td>135<br>
                  <input type="checkbox" id="q135_pass" name="q135_pass" value="1">
                  <label for="q135_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q135_fail" name="q135_fail" value="1">
                  <label for="q135_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เคลื่อนไหวร่างกายตามที่ตกลงกันให้คู่กับสัญญาณเสียงที่ผู้ใหญ่ทำขึ้น 2 ชนิดต่อกัน (GM+EF)<br><br>
              </td>
              <td>
                บอกคำสั่งดังนี้<br>
                - เคาะโต๊ะ 2 ครั้ง แปลว่ากระโดดโดยเท้าทั้ง 2 ข้างลงพร้อมกัน<br>
                - ปรบมือ 2 ครั้ง แปลว่ากระโดดกางแขนทั้ง 2 ข้าง<br>
                2. ทบทวนคำสั่งจนเด็กเข้าใจ<br>
                3. เริ่มทดสอบ ผู้ประเมินให้สัญญาณและพูดว่า “เตรียมตัว เริ่ม”<br>
                <strong>ผ่าน:</strong>   เด็กทำถูกต้องทั้ง 2 สัญญาณเสียง ถ้าครั้งแรกไม่ผ่าน ให้โอกาสทำอีก 1 ครั้ง
              </td>
              <td>
                1. ให้เด็กร้องเพลงพร้อมกับผู้ฝึกและเคลื่อนไหวร่างกายตามจังหวะเพลง<br>
                2. เล่นกับเด็กโดยกำหนดกติกา เช่น ทำเสียงหรือสัญญาณมือ หมายความว่าให้ทำท่าอะไร โดยทำทีละเสียง หรืออาจให้สัญญาณมือ และให้เด็กเป็นคนกำหนดบ้าง<br>
                3. เพิ่มจำนวนครั้งของสัญญาณเสียงให้สอดคล้องกับจำนวนครั้งที่ทำท่าทางการเคลื่อนไหว<br>
                4. ให้เด็กเป็นคนกำหนดกติกาบ้าง<br>
            </td>
            </tr>

            <tr>
              <td>136<br>
                  <input type="checkbox" id="q136_pass" name="q136_pass" value="1">
                  <label for="q136_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q136_fail" name="q136_fail" value="1">
                  <label for="q136_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เขียนชื่อตนเองได้ถูกต้อง (FM)<br><br>
              <strong>อุปกรณ์:</strong>  กระดาษ, ดินสอ
            </td>
            <td>
                บอกเด็กเขียนชื่อตัวเอง<br>
                <strong>ผ่าน:</strong> ด็กเขียนชื่อตนเองได้ถูกต้อง (ชื่อเล่นหรือชื่อจริง)
            </td>
            <td>
                1. เขียนชื่อเด็กบนกระดาษให้เด็กดู อ่านและสะกดให้เด็กฟัง แล้วให้เด็กใช้นิ้วลากตามพยัญชนะและสระของชื่อ<br>
                2. เขียนชื่อเด็กทีละตัวอักษร ให้เด็กเขียนตาม หากเด็กทำไม่ได้ ช่วยจับมือเขียนเพื่อให้เขียนได้ถูกต้องตามทิศทางของตัวอักษร จนเด็กสามารถเขียนได้เอง<br>
                3. ฝึกให้เด็กเขียนชื่อด้วยตนเอง<br>
                4. ฝึกให้เด็กเขียนนามสกุลและชื่อคนในครอบครัว<br>
            </td>
            </tr>

            <tr>
              <td>137<br>
                  <input type="checkbox" id="q137_pass" name="q137_pass" value="1">
                  <label for="q137_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q137_fail" name="q137_fail" value="1">
                  <label for="q137_fail">ไม่ผ่าน</label><br>
              </td>
              <td>อ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ และเล่าได้ว่าเป็นเรื่องอะไร (RL)<br><br>
              <strong>อุปกรณ์:</strong> หนังสือเด็กที่มีภาพประกอบ แต่ละหน้า มีคำน้อยกว่า 20 คำ มีคำซ้ำและคล้องจอง ใช้ประโยคง่าย ตัวหนังสือใหญ่ และแยกคำชัดเจน
              
            </td>
              <td>
               1. ให้เด็กอ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ<br>
               2. ให้เด็กเล่าเรื่องในหนังสือเล่มนั้นให้ผู้ประเมินฟัง<br>  
               <strong>ผ่าน:</strong> 1. เด็กอ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ<br>
               2. เด็กเล่าได้ว่าเป็นเรื่องอะไร
              </td>
              <td>
                  1. จัดมุมใดมุมหนึ่งของบ้านให้เหมาะสมกับการอ่านหนังสือ แบ่งส่วนสำหรับวางหนังสือของเด็ก และมีหนังสือให้เด็กหยิบเองและวางคืนได้ง่าย<br>
                  2. จัดให้มีช่วงเวลาอ่านหนังสือด้วยตัวเองหรืออ่านกับผู้ใหญ่ เช่น ก่อนนอน หลังอาบน้ำ เวลาว่าง วันหยุดสุดสัปดาห์ เป็นต้น<br>
                  - หนังสือแนะนำ เช่น สวนสัตว์ของป๋องแป๋ง, ป๋องแป๋งแต่งตัว, กุ๋งกิ๋งปวดฟัน, หนูนิดติดเกมส์, ยำยกะตา<br>
                  <img src="../image/evaluation_pic/หนังสือ.png" alt="Rectangle" style="width: 500px; height: 110px;"><br>
                  จากนั้นให้เด็กเล่าเรื่องที่อ่านให้กับคนในครอบครัวฟัง โดยผู้ใหญ่แสดงความสนใจฟัง และชวนพูดคุยขยายความต่อยอด หรือซักถามเด็กเพิ่มเติม
                  เพื่อให้มีโอกาสแสดงความคิดเห็นแลกเปลี่ยนกัน เสริมสร้างความสัมพันธ์ในครอบครัว<br>
                  3. ชวนเด็กอ่านคำที่เห็นตามสิ่งต่าง ๆ ในชีวิตประจำวัน เช่น ป้ายโฆษณา ป้ายประกาศ ชื่อร้าน ป้ายทางด่วน ป้ายถนน ป้ายซอย เพื่อสร้างบรรยากาศให้รู้จักรักและสนใจการอ่าน<br>
                  <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong><br> 1. เพื่อส่งเสริมทักษะการอ่านให้แก่เด็ก<br>
                  2. เพื่อให้เด็กมีสมาธิจดจ่อและสามารถควบคุมตนเองในการทำงานต่อเนื่องได้จนจบ<br>
                  3. เพื่อฝึกให้เด็กจดจำเนื้อเรื่อง จับใจความสำคัญและบอกเล่าเรื่องราวได้</span>
             </td>
            </tr>

            <tr>
              <td>138<br>
                  <input type="checkbox" id="q138_pass" name="q138_pass" value="1">
                  <label for="q138_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q138_fail" name="q138_fail" value="1">
                  <label for="q138_fail">ไม่ผ่าน</label><br>
              </td>
              <td> สามารถคิดเชิงเหตุผลและอธิบายได้ (EL)<<br><br>
               <strong>อุปกรณ์:</strong> <strong>อุปกรณ์:</strong> ชุดเหตุการณ์ 3 ชุด<br>
               - ชุดที่ 1 แม่ไก่ (แม่ไก่ฟักไข่ → ลูกไก่โผล่ออกจากไข → ลูกเจี๊ยบไก่ตัวเต็มวัย)<br>
               - ชุดที่ 2 ผีเสื้อ (ไข่ผีเสื้อ → หนอน → ดักแด้ → ผีเสื้อ)<br>
               - ชุดที่ 3 ดอกไม้ (ต้นกล้า → ต้นอ่อน → ดอกบาน → ดอกโรย)
               
              </td>
              <td>
                 นำรูปเหตุการณ์มาวางให้เด็กดูทีละชุด โดยบอกภาพแรกเป็นภาพตั้งต้นก่อน<br>
                 ชุดที่ 1 แม่ไก่ เริ่มภาพแรกคือ แม่ไก่ออกไข่<br>
                 ชุดที่ 2 ผีเสื้อ เริ่มภาพแรกคือ ไข่ผีเสื้อ<br>
                 ชุดที่ 3 ดอกไม้ เริ่มภาพแรกคือ ต้นกล้า<br> 
                 แล้วให้เด็กเรียงภาพต่อจากแผ่นแรก โดย<span style="color: green;">เรียงลำดับก่อนหลังและอธิบาย</span>สิ่งที่เกิดขึ้นตามลำดับ<br>
                <strong>ผ่าน:</strong> เด็กเรียงลำดับเหตุการณ์และอธิบายเป็นเหตุเป็นผล ได้ 2 ใน 3 ชุด

              </td>
              <td>
                 1. ชี้และอธิบายให้เด็กรู้จักสังเกตสิ่งแวดล้อมรอบ ๆ ตัวเด็กที่เปลี่ยนแปลงไปตามระยะเวลา เช่น ตำแหน่งของพระอาทิตย์
                 ที่เปลี่ยนไปในหนึ่งวัน การเจริญเติบโตของพืชที่โตเร็ว เช่น ถั่วงอกต้นหอม ผักบุ้ง เป็นต้น<br>
                 2. ผู้ใหญ่ถามเด็กด้วยคำถาม เช่น ทำไม อย่างไร เพราะอะไร ฯลฯแสดงความความสนใจคำตอบของเด็ก ชวนพูดคุย ซักถามเพื่อกระตุ้น
                 ให้เด็กตอบอย่างมีเหตุผล และช่วยขยายความให้ชัดเจนขึ้น<br>
                 <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong><br> ฝึกให้เด็กมีความคิดเชิงเหตุผล สามารถเรียงลำดับ
                 เหตุการณ์ก่อน-หลังได้ จากการจดจำ และสามารถนำไปใช้ได้<br>
                 
              </td>
            </tr>

            <tr>
              <td>139<br>
                  <input type="checkbox" id="q139_pass" name="q139_pass" value="1">
                  <label for="q139_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q139_fail" name="q139_fail" value="1">
                  <label for="q139_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำงานที่ได้รับมอบหมายจนสำเร็จด้วยตนเอง (PS)<br><br>
              
              </td>
              <td>
                ถามจากผู้ปกครอง ผู้ดูแลเด็กหรือครูประจำชั้นว่า “เด็กสามารถทำงานที่ได้รับมอบ
                หมายจนสำเร็จด้วยตนเองหรือไม่” เช่น<br>
                -<strong>การดูแลตนเองในกิจวัตรประจำวัน :</strong> แต่งตัวกินข้าว อาบน้ำ แปรงฟัน<br>
                -<strong>งานด้านการเรียนรู้ :</strong> วาดรูป อ่าน เขียนร่วมกิจกรรม<br>
                -<strong>งานด้านศิลปะ :</strong> การร้องเพลง การออกกำลังกาย<br>
                <strong >ผ่าน:</strong>ผู้ปกครองตอบว่าเด็กสามารถทำงานที่ได้รับมอบหมายจนสำเร็จด้วยตนเอง
              </td>
              <td>
                1. ผู้ใหญ่ปฏิบัติตนเป็นแบบอย่างที่ดี ในด้านการทำงานให้เสร็จตาม
                เป้าหมายที่เห็นผลลัพธ์ได้ชัดเจนให้แก่เด็ก<br>
                2. กำหนดเป้าหมายของงานหรือกิจกรรมร่วมกันกับเด็ก และสนับสนุนช่วยเหลือให้เด็กทำงานสำเร็จตามเป้าหมายนั้น โดยคำนึงถึงระดับ
                พัฒนาการและความสามารถ<br>
                3. ให้เด็กบอกเป้าหมายในกิจกรรมที่ได้รับมอบหมาย และให้โอกาสเด็กทำเองจนสำเร็จ ให้ความช่วยเหลือเมื่อเด็กต้องการ<br>
                4. แสดงความชื่นชมเมื่อเด็กพยายามทำให้สำเร็จตามเป้าหมาย เพื่อเพิ่มความภาคภูมิใจในตนเอง และพูดคุยถึงวิธีที่จะทำให้ดีขึ้นในครั้งต่อไป<br>
                <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong> เพื่อให้เด็กสามารถรับผิดชอบทำงานที่ได้รับมอบหมายด้วยตนเองได้จนสำเร็จ</span>
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
        <!-- Card ข้อที่ 135 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 135 - เคลื่อนไหวร่างกายตามที่ตกลงกันให้คู่กับสัญญาณเสียงที่ผู้ใหญ่ทำขึ้น 2 ชนิดต่อกัน (GM+EF)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 73 - 78 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q135_pass_mobile" name="q135_pass" value="1">
                <label class="form-check-label text-success" for="q135_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q135_fail_mobile" name="q135_fail" value="1">
                <label class="form-check-label text-danger" for="q135_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">บอกคำสั่งดังนี้<br>
              - เคาะโต๊ะ 2 ครั้ง แปลว่ากระโดดโดยเท้าทั้ง 2 ข้างลงพร้อมกัน<br>
              - ปรบมือ 2 ครั้ง แปลว่ากระโดดกางแขนทั้ง 2 ข้าง<br>
              2. ทบทวนคำสั่งจนเด็กเข้าใจ<br>
              3. เริ่มทดสอบ ผู้ประเมินให้สัญญาณและพูดว่า "เตรียมตัว เริ่ม"</p>
              <p><strong>ผ่าน:</strong> เด็กทำถูกต้องทั้ง 2 สัญญาณเสียง ถ้าครั้งแรกไม่ผ่าน ให้โอกาสทำอีก 1 ครั้ง</p>
            </div>
            <div class="accordion" id="training135">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading135">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse135">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse135" class="accordion-collapse collapse" data-bs-parent="#training135">
                  <div class="accordion-body">
                    1. ให้เด็กร้องเพลงพร้อมกับผู้ฝึกและเคลื่อนไหวร่างกายตามจังหวะเพลง<br>
                    2. เล่นกับเด็กโดยกำหนดกติกา เช่น ทำเสียงหรือสัญญาณมือ หมายความว่าให้ทำท่าอะไร โดยทำทีละเสียง หรืออาจให้สัญญาณมือ และให้เด็กเป็นคนกำหนดบ้าง<br>
                    3. เพิ่มจำนวนครั้งของสัญญาณเสียงให้สอดคล้องกับจำนวนครั้งที่ทำท่าทางการเคลื่อนไหว<br>
                    4. ให้เด็กเป็นคนกำหนดกติกาบ้าง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 136 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 136 - เขียนชื่อตนเองได้ถูกต้อง (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 73 - 78 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> กระดาษ, ดินสอ
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q136_pass_mobile" name="q136_pass" value="1">
                <label class="form-check-label text-success" for="q136_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q136_fail_mobile" name="q136_fail" value="1">
                <label class="form-check-label text-danger" for="q136_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">บอกเด็กเขียนชื่อตัวเอง</p>
              <p><strong>ผ่าน:</strong> เด็กเขียนชื่อตนเองได้ถูกต้อง (ชื่อเล่นหรือชื่อจริง)</p>
            </div>
            <div class="accordion" id="training136">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading136">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse136">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse136" class="accordion-collapse collapse" data-bs-parent="#training136">
                  <div class="accordion-body">
                    1. เขียนชื่อเด็กบนกระดาษให้เด็กดู อ่านและสะกดให้เด็กฟัง แล้วให้เด็กใช้นิ้วลากตามพยัญชนะและสระของชื่อ<br>
                    2. เขียนชื่อเด็กทีละตัวอักษร ให้เด็กเขียนตาม หากเด็กทำไม่ได้ ช่วยจับมือเขียนเพื่อให้เขียนได้ถูกต้องตามทิศทางของตัวอักษร จนเด็กสามารถเขียนได้เอง<br>
                    3. ฝึกให้เด็กเขียนชื่อด้วยตนเอง<br>
                    4. ฝึกให้เด็กเขียนนามสกุลและชื่อคนในครอบครัว
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 137 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 137 - อ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ และเล่าได้ว่าเป็นเรื่องอะไร (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 73 - 78 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> หนังสือเด็กที่มีภาพประกอบ แต่ละหน้า มีคำน้อยกว่า 20 คำ มีคำซ้ำและคล้องจอง ใช้ประโยคง่าย ตัวหนังสือใหญ่ และแยกคำชัดเจน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q137_pass_mobile" name="q137_pass" value="1">
                <label class="form-check-label text-success" for="q137_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q137_fail_mobile" name="q137_fail" value="1">
                <label class="form-check-label text-danger" for="q137_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ให้เด็กอ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ<br>
              2. ให้เด็กเล่าเรื่องในหนังสือเล่มนั้นให้ผู้ประเมินฟัง</p>
              <p><strong>ผ่าน:</strong> 1. เด็กอ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ<br> 2. เด็กเล่าได้ว่าเป็นเรื่องอะไร</p>
            </div>
            <div class="accordion" id="training137">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading137">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse137">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse137" class="accordion-collapse collapse" data-bs-parent="#training137">
                  <div class="accordion-body">
                    1. จัดมุมใดมุมหนึ่งของบ้านให้เหมาะสมกับการอ่านหนังสือ แบ่งส่วนสำหรับวางหนังสือของเด็ก และมีหนังสือให้เด็กหยิบเองและวางคืนได้ง่าย<br>
                  2. จัดให้มีช่วงเวลาอ่านหนังสือด้วยตัวเองหรืออ่านกับผู้ใหญ่ เช่น ก่อนนอน หลังอาบน้ำ เวลาว่าง วันหยุดสุดสัปดาห์ เป็นต้น<br>
                  - หนังสือแนะนำ เช่น สวนสัตว์ของป๋องแป๋ง, ป๋องแป๋งแต่งตัว, กุ๋งกิ๋งปวดฟัน, หนูนิดติดเกมส์, ยำยกะตา<br>
                  <img src="../image/evaluation_pic/หนังสือ.png" alt="Rectangle" style="width: 500px; height: 110px;"><br>
                  จากนั้นให้เด็กเล่าเรื่องที่อ่านให้กับคนในครอบครัวฟัง โดยผู้ใหญ่แสดงความสนใจฟัง และชวนพูดคุยขยายความต่อยอด หรือซักถามเด็กเพิ่มเติม
                  เพื่อให้มีโอกาสแสดงความคิดเห็นแลกเปลี่ยนกัน เสริมสร้างความสัมพันธ์ในครอบครัว<br>
                  3. ชวนเด็กอ่านคำที่เห็นตามสิ่งต่าง ๆ ในชีวิตประจำวัน เช่น ป้ายโฆษณา ป้ายประกาศ ชื่อร้าน ป้ายทางด่วน ป้ายถนน ป้ายซอย เพื่อสร้างบรรยากาศให้รู้จักรักและสนใจการอ่าน<br>
                  <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong><br> 1. เพื่อส่งเสริมทักษะการอ่านให้แก่เด็ก<br>
                  2. เพื่อให้เด็กมีสมาธิจดจ่อและสามารถควบคุมตนเองในการทำงานต่อเนื่องได้จนจบ<br>
                  3. เพื่อฝึกให้เด็กจดจำเนื้อเรื่อง จับใจความสำคัญและบอกเล่าเรื่องราวได้</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 138 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 138 - สามารถคิดเชิงเหตุผลและอธิบายได้ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 73 - 78 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ชุดเหตุการณ์ 3 ชุด<br>
              - ชุดที่ 1 แม่ไก่ (แม่ไก่ฟักไข่ → ลูกไก่โผล่ออกจากไข → ลูกเจี๊ยบไก่ตัวเต็มวัย)<br>
               - ชุดที่ 2 ผีเสื้อ (ไข่ผีเสื้อ → หนอน → ดักแด้ → ผีเสื้อ)<br>
               - ชุดที่ 3 ดอกไม้ (ต้นกล้า → ต้นอ่อน → ดอกบาน → ดอกโรย)
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q138_pass_mobile" name="q138_pass" value="1">
                <label class="form-check-label text-success" for="q138_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q138_fail_mobile" name="q138_fail" value="1">
                <label class="form-check-label text-danger" for="q138_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">นำรูปเหตุการณ์มาวางให้เด็กดูทีละชุด โดยบอกภาพแรกเป็นภาพตั้งต้นก่อน<br>
                 ชุดที่ 1 แม่ไก่ เริ่มภาพแรกคือ แม่ไก่ออกไข่<br>
                 ชุดที่ 2 ผีเสื้อ เริ่มภาพแรกคือ ไข่ผีเสื้อ<br>
                 ชุดที่ 3 ดอกไม้ เริ่มภาพแรกคือ ต้นกล้า<br> 
                 แล้วให้เด็กเรียงภาพต่อจากแผ่นแรก โดย<span style="color: green;">เรียงลำดับก่อนหลังและอธิบาย</span>สิ่งที่เกิดขึ้นตามลำดับ<br></p>
              <p><strong>ผ่าน:</strong> เด็กเรียงลำดับเหตุการณ์และอธิบายเป็นเหตุเป็นผล ได้ 2 ใน 3 ชุด</p>
            </div>
            <div class="accordion" id="training138">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading138">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse138">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse138" class="accordion-collapse collapse" data-bs-parent="#training138">
                  <div class="accordion-body">
                    1. ชี้และอธิบายให้เด็กรู้จักสังเกตสิ่งแวดล้อมรอบ ๆ ตัวเด็กที่เปลี่ยนแปลงไปตามระยะเวลา เช่น ตำแหน่งของพระอาทิตย์
                 ที่เปลี่ยนไปในหนึ่งวัน การเจริญเติบโตของพืชที่โตเร็ว เช่น ถั่วงอกต้นหอม ผักบุ้ง เป็นต้น<br>
                 2. ผู้ใหญ่ถามเด็กด้วยคำถาม เช่น ทำไม อย่างไร เพราะอะไร ฯลฯแสดงความความสนใจคำตอบของเด็ก ชวนพูดคุย ซักถามเพื่อกระตุ้น
                 ให้เด็กตอบอย่างมีเหตุผล และช่วยขยายความให้ชัดเจนขึ้น<br>
                 <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong><br> ฝึกให้เด็กมีความคิดเชิงเหตุผล สามารถเรียงลำดับ
                 เหตุการณ์ก่อน-หลังได้ จากการจดจำ และสามารถนำไปใช้ได้<br>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 139 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 139 - ทำงานที่ได้รับมอบหมายจนสำเร็จด้วยตนเอง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 73 - 78 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q139_pass_mobile" name="q139_pass" value="1">
                <label class="form-check-label text-success" for="q139_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q139_fail_mobile" name="q139_fail" value="1">
                <label class="form-check-label text-danger" for="q139_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากผู้ปกครอง ผู้ดูแลเด็กหรือครูประจำชั้นว่า “เด็กสามารถทำงานที่ได้รับมอบ
                หมายจนสำเร็จด้วยตนเองหรือไม่” เช่น<br>
                -<strong>การดูแลตนเองในกิจวัตรประจำวัน :</strong> แต่งตัวกินข้าว อาบน้ำ แปรงฟัน<br>
                -<strong>งานด้านการเรียนรู้ :</strong> วาดรูป อ่าน เขียนร่วมกิจกรรม<br>
                -<strong>งานด้านศิลปะ :</strong> การร้องเพลง การออกกำลังกาย</p>
              <p><strong >ผ่าน:</strong>ผู้ปกครองตอบว่าเด็กสามารถทำงานที่ได้รับมอบหมายจนสำเร็จด้วยตนเอง</p>
            </div>
            <div class="accordion" id="training139">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading139">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse139">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse139" class="accordion-collapse collapse" data-bs-parent="#training139">
                  <div class="accordion-body">
                    1. ผู้ใหญ่ปฏิบัติตนเป็นแบบอย่างที่ดี ในด้านการทำงานให้เสร็จตาม
                เป้าหมายที่เห็นผลลัพธ์ได้ชัดเจนให้แก่เด็ก<br>
                2. กำหนดเป้าหมายของงานหรือกิจกรรมร่วมกันกับเด็ก และสนับสนุนช่วยเหลือให้เด็กทำงานสำเร็จตามเป้าหมายนั้น โดยคำนึงถึงระดับ
                พัฒนาการและความสามารถ<br>
                3. ให้เด็กบอกเป้าหมายในกิจกรรมที่ได้รับมอบหมาย และให้โอกาสเด็กทำเองจนสำเร็จ ให้ความช่วยเหลือเมื่อเด็กต้องการ<br>
                4. แสดงความชื่นชมเมื่อเด็กพยายามทำให้สำเร็จตามเป้าหมาย เพื่อเพิ่มความภาคภูมิใจในตนเอง และพูดคุยถึงวิธีที่จะทำให้ดีขึ้นในครั้งต่อไป<br>
                <span style="color: green;"><strong style="color: green;">วัตถุประสงค์ :</strong> เพื่อให้เด็กสามารถรับผิดชอบทำงานที่ได้รับมอบหมายด้วยตนเองได้จนสำเร็จ</span>
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
      for (let i = 135; i <= 139; i++) {
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
      for (let i = 135; i <= 139; i++) {
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

      for (let i = 135; i <= 139; i++) {
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

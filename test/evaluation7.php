<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '10-12';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 35-39)
    for ($i = 35; $i <= 39; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 35-39)
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
  <title>แบบประเมิน ช่วงอายุ 10 ถึง 12 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 10 - 12 เดือน
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
            <!-- ข้อ 35-39 สำหรับ 10-12 เดือน -->
            <tr>
              <td rowspan="5">10 - 12 เดือน</td>
              <td>35<br>
                  <input type="checkbox" id="q35_pass" name="q35_pass" value="1">
                  <label for="q35_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q35_fail" name="q35_fail" value="1">
                  <label for="q35_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนนาน 2 วินาที (GM)<br><br>
              <td>
                จัดให้เด็กอยู่ในท่ายืนโดยไม่ต้องช่วยพยุง<br>
                <strong>ผ่าน:</strong>เด็กสามารถยืนได้เอง โดยไม่ต้องช่วยพยุงได้นาน อย่างน้อย 2 วินาท
              </td>
              <td>
                พยุงลำตัวเด็กให้ยืน เมื่อเด็กเริ่มยืนทรงตัวได้แล้ว ให้เปลี่ยนมาจับข้อมือเด็ก แล้วค่อย ๆ ปล่อยมือเพื่อให้เด็กยืนเอง
              </td>
            </tr>

            <tr>
              <td>36<br>
                  <input type="checkbox" id="q36_pass" name="q36_pass" value="1">
                  <label for="q36_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q36_fail" name="q36_fail" value="1">
                  <label for="q36_fail">ไม่ผ่าน</label><br>
              </td>
              <td>จีบนิ้วมือเพื่อหยิบของชิ้นเล็ก (FM)<br><br>
                <strong>อุปกรณ์:</strong> วัตถุขนาดเล็ก ขนาด 1 ซม.<br>
              <img src="../image/evaluation_pic/วัสดุขนาดเล็ก 1 ซม.png" alt="Family" style="width: 120px; height: 100px;"><br></td></td>
              <td>
                1. วางวัตถุชิ้นเล็กๆ ตรงหน้าเด็ก 1 ชิ้น<br>
                2. กระตุ้นความสนใจของเด็กไปที่วัตถุชิ้นเล็ก แล้วบอกให้เด็กหยิบ หรืออาจหยิบให้เด็กดู สังเกตการหยิบของเด็ก<br>
                <strong>ผ่าน:</strong> เด็กสามารถจีบนิ้วโดยใช้นิ้วหัวแม่มือและนิ้วชี้หยิบวัตถุชิ้นเล็กขึ้นมาได้ 1 ใน 3 ครั้ง
              </td>
              <td>
                1. แบ่งขนมหรืออาหารเป็นชิ้นเล็ก ๆ ประมาณ 1 ซม. ไว้ในจานแล้วหยิบอาหารหรือขนมโดยใช้นิ้วหัวแม่มือและนิ้วชี้หยิบให้เด็กดูแล้วบอกให้เด็กทำตาม<br>
                2. ถ้าเด็กทำไม่ได้ ช่วยเหลือเด็กโดยจับรวบนิ้วกลาง นิ้วนางและนิ้วก้อยเข้าหาฝ่ามือ เพื่อให้เด็กใช้นิ้วหัวแม่มือและนิ้วชี้หยิบวัตถุ<br>
                3. เล่นกิจกรรมที่เด็กต้องใช้นิ้วหัวแม่มือและนิ้วชี้แตะกันเป็นจังหวะ หรือเล่นร้องเพลงแมงมุมขยุ้มหลังคาประกอบท่าทางจีบนิ้ว<br>
                <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> ของกินชิ้นเล็ก ที่อ่อนนุ่ม ละลายได้ในปากไม่สำลักเช่น ถั่วกวน ฟักทองนึ่ง มันนึ่ง ลูกเกด ข้าวสุก</span>
              </td>
            </tr>

            <tr>
              <td>37<br>
                  <input type="checkbox" id="q37_pass" name="q37_pass" value="1">
                  <label for="q37_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q37_fail" name="q37_fail" value="1">
                  <label for="q37_fail">ไม่ผ่าน</label><br>
              </td>
              <td>โบกมือหรือตบมือตามคำสั่ง(RL)<br><br>
              <td>
                สบตาเด็กแล้วบอกให้เด็กโบกมือ หรือตบมือโดยห้ามใช้ท่าทางประกอบ<br>
                <strong>ผ่าน:</strong> เด็กสามารถทำตามคำสั่ง แม้ไม่ถูกต้องแต่พยายามยกแขนและเคลื่อนไหวมืออย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. เล่นกับเด็กโดยใช้คำสั่งง่าย ๆ เช่น โบกมือ ตบมือ พร้อมกับทำท่าทางประกอบ <br>
                2. ถ้าเด็กไม่ทำ ให้จับมือทำและค่อย ๆ ลดความช่วยเหลือลงโดยเปลี่ยนเป็นจับข้อมือ จากนั้นเปลี่ยนเป็นแตะข้อศอก เมื่อเริ่มตบมือเองได้แล้ว ลดการช่วยเหลือลงเป็นบอกหรือบอกให้ทำอย่างเดียว<br>
              </td>
            </tr>

            <tr>
              <td>38<br>
                  <input type="checkbox" id="q38_pass" name="q38_pass" value="1">
                  <label for="q38_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q38_fail" name="q38_fail" value="1">
                  <label for="q38_fail">ไม่ผ่าน</label><br>
              </td>
              <td>แสดงความต้องการ โดยทำท่าทาง หรือเปล่งเสียง (EL)</td>
              <td>
                ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเมื่อเด็กต้องการสิ่งต่าง ๆ เด็กทำอย่างไร<br>
                <strong>ผ่าน:</strong> เด็กแสดงความต้องการด้วยการทำท่าทาง เช่น ยื่นมือให้อุ้ม ชี้ ดึงเสื้อ หรือเปล่งเสียง
              </td>
              <td>
                นำของเล่น หรืออาหารที่เด็กชอบ 2 - 3 อย่าง วางไว้ด้านหน้าเด็กถามเด็กว่า “หนูเอาอันไหน” หรือถามว่า “หนูเอาไหม” รอให้เด็กแสดงความต้องการ เช่น ชี้ แล้วจึงจะให้ของ ทำเช่นนี้ทุกครั้ง
                เมื่อเด็กต้องการของเล่นหรืออาหาร เพื่อฝึกการสื่อสารความต้องการเบื้องต้น
              </td>
            </tr>

            <tr>
              <td>39<br>
                  <input type="checkbox" id="q39_pass" name="q39_pass" value="1">
                  <label for="q39_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q39_fail" name="q39_fail" value="1">
                  <label for="q39_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เล่นสิ่งของตามประโยชน์ของสิ่งของได้ (PS)<br><br>
              <strong>อุปกรณ์:</strong> ของเล่น 4 ชนิด ได้แก่หวี/ช้อน/แก้วน้ำ/แปรงสีฟัน<br>
              <img src="../image/evaluation_pic/ของเล่น 4 ชนิด หวี ช้อน แก้วน่ำ แปรงสีฟัน.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                1. ยื่นของเล่นที่เตรียมไว้ให้เด็กครั้งละ1 ชิ้น จนครบ 4 ชนิด <br>
                2. สังเกตเด็กเล่นของเล่นทั้ง 4 ชนิดว่าตรงตามประโยชน์หรือไม่ หรือถามจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก<br>
                <strong>ผ่าน:</strong>  เด็กเล่นสิ่งของตามประโยชน์ได้ถูกต้องอย่างน้อย 1 ใน 4 ชิ้น เช่น เล่นหวีผมป้อนอาหาร ดื่มน้ำ
              </td>
              <td>
               ฝึกในสถานการณ์ต่าง ๆ เช่น การหวีผม การแปรงฟัน การป้อนอาหารเด็กโดยทำให้เด็กดู และกระตุ้นให้เด็กทำตาม<br>
               <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong>  ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
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
        <!-- Card ข้อที่ 35 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 35 - ยืนนาน 2 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 10 - 12 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q35_pass_mobile" name="q35_pass" value="1">
                <label class="form-check-label text-success" for="q35_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q35_fail_mobile" name="q35_fail" value="1">
                <label class="form-check-label text-danger" for="q35_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดให้เด็กอยู่ในท่ายืนโดยไม่ต้องช่วยพยุง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยืนได้เอง โดยไม่ต้องช่วยพยุงได้นาน อย่างน้อย 2 วินาที</p>
            </div>
            <div class="accordion" id="training35">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading35">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse35">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse35" class="accordion-collapse collapse" data-bs-parent="#training35">
                  <div class="accordion-body">
                    พยุงลำตัวเด็กให้ยืน เมื่อเด็กเริ่มยืนทรงตัวได้แล้ว ให้เปลี่ยนมาจับข้อมือเด็ก แล้วค่อย ๆ ปล่อยมือเพื่อให้เด็กยืนเอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 36 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 36 - จีบนิ้วมือเพื่อหยิบของชิ้นเล็ก (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 10 - 12 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q36_pass_mobile" name="q36_pass" value="1">
                <label class="form-check-label text-success" for="q36_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q36_fail_mobile" name="q36_fail" value="1">
                <label class="form-check-label text-danger" for="q36_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> วัตถุชิ้นเล็ก ขนาด 1 ซม.<br>
              <img src="../image/evaluation_pic/วัสดุขนาดเล็ก 1 ซม.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางวัตถุชิ้นเล็กๆ ตรงหน้าเด็ก 1 ชิ้น<br>
              2. กระตุ้นความสนใจของเด็กไปที่วัตถุชิ้นเล็ก แล้วบอกให้เด็กหยิบ หรืออาจหยิบให้เด็กดู สังเกตการหยิบของเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถจีบนิ้วโดยใช้นิ้วหัวแม่มือและนิ้วชี้หยิบวัตถุชิ้นเล็กขึ้นมาได้ 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training36">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading36">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse36">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse36" class="accordion-collapse collapse" data-bs-parent="#training36">
                  <div class="accordion-body">
                    1. แบ่งขนมหรืออาหารเป็นชิ้นเล็ก ๆ ประมาณ 1 ซม. ไว้ในจานแล้วหยิบอาหารหรือขนมโดยใช้นิ้วหัวแม่มือและนิ้วชี้หยิบให้เด็กดูแล้วบอกให้เด็กทำตาม<br>
                    2. ถ้าเด็กทำไม่ได้ ช่วยเหลือเด็กโดยจับรวบนิ้วกลาง นิ้วนางและนิ้วก้อยเข้าหาฝ่ามือ เพื่อให้เด็กใช้นิ้วหัวแม่มือและนิ้วชี้หยิบวัตถุ<br>
                    3. เล่นกิจกรรมที่เด็กต้องใช้นิ้วหัวแม่มือและนิ้วชี้แตะกันเป็นจังหวะ หรือเล่นร้องเพลงแมงมุมขยุ้มหลังคาประกอบท่าทางจีบนิ้ว<br>
                    <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong> ของกินชิ้นเล็ก ที่อ่อนนุ่ม ละลายได้ในปากไม่สำลักเช่น ถั่วกวน ฟักทองนึ่ง มันนึ่ง ลูกเกด ข้าวสุก</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 37 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 37 - โบกมือหรือตบมือตามคำสั่ง (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 10 - 12 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q37_pass_mobile" name="q37_pass" value="1">
                <label class="form-check-label text-success" for="q37_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q37_fail_mobile" name="q37_fail" value="1">
                <label class="form-check-label text-danger" for="q37_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สบตาเด็กแล้วบอกให้เด็กโบกมือ หรือตบมือโดยห้ามใช้ท่าทางประกอบ</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถทำตามคำสั่ง แม้ไม่ถูกต้องแต่พยายามยกแขนและเคลื่อนไหวมืออย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training37">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading37">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse37">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse37" class="accordion-collapse collapse" data-bs-parent="#training37">
                  <div class="accordion-body">
                    1. เล่นกับเด็กโดยใช้คำสั่งง่าย ๆ เช่น โบกมือ ตบมือ พร้อมกับทำท่าทางประกอบ<br>
                    2. ถ้าเด็กไม่ทำ ให้จับมือทำและค่อย ๆ ลดความช่วยเหลือลงโดยเปลี่ยนเป็นจับข้อมือ จากนั้นเปลี่ยนเป็นแตะข้อศอก เมื่อเริ่มตบมือเองได้แล้ว ลดการช่วยเหลือลงเป็นบอกหรือบอกให้ทำอย่างเดียว
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 38 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 38 - แสดงความต้องการ โดยทำท่าทาง หรือเปล่งเสียง (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 10 - 12 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q38_pass_mobile" name="q38_pass" value="1">
                <label class="form-check-label text-success" for="q38_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q38_fail_mobile" name="q38_fail" value="1">
                <label class="form-check-label text-danger" for="q38_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเมื่อเด็กต้องการสิ่งต่าง ๆ เด็กทำอย่างไร</p>
              <p><strong>ผ่าน:</strong> เด็กแสดงความต้องการด้วยการทำท่าทาง เช่น ยื่นมือให้อุ้ม ชี้ ดึงเสื้อ หรือเปล่งเสียง</p>
            </div>
            <div class="accordion" id="training38">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading38">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse38">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse38" class="accordion-collapse collapse" data-bs-parent="#training38">
                  <div class="accordion-body">
                    นำของเล่น หรืออาหารที่เด็กชอบ 2 - 3 อย่าง วางไว้ด้านหน้าเด็กถามเด็กว่า "หนูเอาอันไหน" หรือถามว่า "หนูเอาไหม" รอให้เด็กแสดงความต้องการ เช่น ชี้ แล้วจึงจะให้ของ ทำเช่นนี้ทุกครั้งเมื่อเด็กต้องการของเล่นหรืออาหาร เพื่อฝึกการสื่อสารความต้องการเบื้องต้น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 39 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 39 - เล่นสิ่งของตามประโยชน์ของสิ่งของได้ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 10 - 12 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q39_pass_mobile" name="q39_pass" value="1">
                <label class="form-check-label text-success" for="q39_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q39_fail_mobile" name="q39_fail" value="1">
                <label class="form-check-label text-danger" for="q39_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> ของเล่น 4 ชนิด ได้แก่หวี/ช้อน/แก้วน้ำ/แปรงสีฟัน<br>
              <img src="../image/evaluation_pic/ของเล่น 4 ชนิด หวี ช้อน แก้วน่ำ แปรงสีฟัน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ยื่นของเล่นที่เตรียมไว้ให้เด็กครั้งละ1 ชิ้น จนครบ 4 ชนิด<br>
              2. สังเกตเด็กเล่นของเล่นทั้ง 4 ชนิดว่าตรงตามประโยชน์หรือไม่ หรือถามจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กเล่นสิ่งของตามประโยชน์ได้ถูกต้องอย่างน้อย 1 ใน 4 ชิ้น เช่น เล่นหวีผมป้อนอาหาร ดื่มน้ำ</p>
            </div>
            <div class="accordion" id="training39">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading39">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse39">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse39" class="accordion-collapse collapse" data-bs-parent="#training39">
                  <div class="accordion-body">
                    ฝึกในสถานการณ์ต่าง ๆ เช่น การหวีผม การแปรงฟัน การป้อนอาหารเด็กโดยทำให้เด็กดู และกระตุ้นให้เด็กทำตาม<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
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
      for (let i = 35; i <= 39; i++) {
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
      for (let i = 35; i <= 39; i++) {
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

      for (let i = 35; i <= 39; i++) {
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

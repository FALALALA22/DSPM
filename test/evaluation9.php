<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '16-17';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 40-44)
    for ($i = 45; $i <= 49; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 45-49)
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
  <title>แบบประเมิน ช่วงอายุ 16 ถึง 17 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 16 - 17 เดือน
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
            <!-- ข้อ 45-49 สำหรับ 16-17 เดือน -->
            <tr>
              <td rowspan="5">16 - 17 เดือน</td>
              <td>45<br>
                  <input type="checkbox" id="q45_pass" name="q45_pass" value="1">
                  <label for="q45_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q45_fail" name="q45_fail" value="1">
                  <label for="q45_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เดินลากของเล่น หรือสิ่งของได้ (GM)<br><br>
                <strong>อุปกรณ์:</strong>  กล่องพลาสติกผูกเชือก<br>
                <img src="../image/evaluation_pic/กล่องพลาสติกผูกเชือก.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                1. เดินลากของเล่นให้เด็กดู<br>
                2. ส่งเชือกลากของเล่นให้เด็ก และบอกให้เด็กเดินลากของเล่นไปเอง<br>
                <strong>ผ่าน:</strong>  เด็กเดินลากรถของเล่นหรือสิ่งของได้ไกล 2 เมตร โดยอาจเดินไปข้างหน้าหรือเดินถอยหลังก็ได้ พร้อมกับลากของเล่นไป
                โดยของเล่นอาจคว่ำได้
              </td>
              <td>
               1. จับมือเด็กให้ลากของเล่นเดินไปข้างหน้าด้วยกัน <br>
               2. กระตุ้นให้เด็กเดินเองต่อไป โดยทำหลาย ๆ ครั้ง จนเด็กสามารถเดินลากของเล่นไปได้เอง <br>
               <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> สิ่งของในบ้านที่สามารถลากได้ เช่น ตะกร้า รถของเล่น กล่องต่าง ๆ</span>
              </td>
            </tr>

            <tr>
              <td>46<br>
                  <input type="checkbox" id="q46_pass" name="q46_pass" value="1">
                  <label for="q46_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q46_fail" name="q46_fail" value="1">
                  <label for="q46_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ขีดเขียนได้เอง (FM)<br><br>
                <strong>อุปกรณ์:</strong> 1. ดินสอ
                2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 160px; height: 160px;"><br></td>
              <td>
                1. ส่งกระดาษและดินสอให้เด็ก<br>
                2. บอกเด็ก “หนูลองวาดรูปซิคะ”(โดยไม่สาธิตให้เด็กดู)<br>
                <strong>ผ่าน:</strong>  เด็กสามารถขีดเขียนเป็นเส้นใด ๆ บนกระดาษได้เอง
                <strong>หมายเหตุ:</strong> : ถ้าเด็กเพียงแต่เขียนจุด ๆ หรือกระแทก ดินสอกับกระดาษให้ถือว่าไม่ผ่าน
              </td>
              <td>
                1. ใช้ดินสอสีแท่งใหญ่เขียนเป็นเส้น ๆ บนกระดาษให้เด็กดู (อาจใช้ดินสอ หรือปากกา หรือสีเมจิกได้)<br>
                2. ให้เด็กลองทำเอง ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็กให้จับดินสอขีดเขียนเป็นเส้น ๆ ไปมาบนกระดาษ จนเด็กสามารถทำได้เอง
              </td>
            </tr>

            <tr>
              <td>47<br>
                  <input type="checkbox" id="q47_pass" name="q47_pass" value="1">
                  <label for="q47_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q47_fail" name="q47_fail" value="1">
                  <label for="q47_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำตามคำสั่งง่าย ๆ โดยไม่มีท่าทางประกอบ (RL)<br><br>
              <strong>อุปกรณ์:</strong> ของเล่นเด็ก เช่น ตุ๊กตาผ้า บอล รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
            </td>
              <td>
                1. วางของเล่นทุกชิ้นแล้วเล่นกับเด็ก<br>
                2. มองหน้าเด็กแล้วบอกเด็ก เช่น “กอดตุ๊กตาซิ” “ขว้างลูกบอลซิ” “ส่งรถให้ครูซิ”<br>
                <strong>ผ่าน:</strong> เด็กสามารถแสดงกริยากับสิ่งของได้อย่างน้อย 1 คำสั่ง โดยผู้ประเมินไม่ต้องแสดงท่าทางประกอบ
              </td>
              <td>
                1. ฝึกเด็ก ขณะที่เด็กกำลังถือหรือเล่นของเล่นอยู่<br>
                2. บอกเด็กว่า “ส่งของให้แม่” และมองหน้าเด็ก<br>
                3. ถ้าเด็กทำไม่ได้ ให้จับมือเด็กหยิบของแล้วส่งให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พร้อมพูดว่า “ส่งของให้แม่” ถ้าเด็กเริ่มทำได้ให้ออก
                คำสั่งเพียงอย่างเดียวและเปลี่ยนเป็นคำสั่งอื่น ๆ เพิ่ม<br>
                4. กระตุ้นให้เด็กรู้จักแบ่งปัน ของเล่น ขนม หรือสิ่งของอื่น ๆให้คนรอบข้าง เมื่อเด็กทำได้ให้ชมเชย
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
              </td>
            </tr>

            <tr>
              <td>48<br>
                  <input type="checkbox" id="q48_pass" name="q48_pass" value="1">
                  <label for="q48_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q48_fail" name="q48_fail" value="1">
                  <label for="q48_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ตอบชื่อวัตถุได้ถูกต้อง (EL)<br><br>
              <strong>อุปกรณ์:</strong> ของเล่นเด็ก เช่น ตุ๊กตาผ้า บอล รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                ชี้ไปที่ของเล่นที่เด็กคุ้นเคยแล้วถามว่า“นี่อะไร”<br>
                <strong>ผ่าน:</strong> เด็กสามารถตอบชื่อวัตถุได้ถูกต้องหรือออกเสียงได้ใกล้เคียง เช่น ตุ๊กตา – ตาได้ 1 ชนิด
              </td>
              <td>
                1. ให้ใช้สิ่งของหรือของเล่นที่เด็กคุ้นเคยและรู้จักชื่อ เช่น ตุ๊กตา บอล<br>
                2. หยิบของให้เด็กดู ถามว่า “นี่อะไร” รอให้เด็กตอบ ถ้าไม่ตอบให้บอกเด็ก และให้เด็กพูดตามแล้วถามซ้ำให้เด็กตอบเอง<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
              </td>
            </tr>

            <tr>
              <td>49<br>
                  <input type="checkbox" id="q49_pass" name="q49_pass" value="1">
                  <label for="q49_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q49_fail" name="q49_fail" value="1">
                  <label for="q49_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เล่นการใช้สิ่งของตามหน้าที่ได้มากขึ้นด้วยความสัมพันธ์ของ 2 สิ่งขึ้นไป (PS)<br><br>
                <strong>อุปกรณ์:</strong> 1. ตุ๊กตาผ้า 2. หวี 3. ถ้วย 4. ช้อนเล็ก 5. แปรงสีฟัน<br>
                
              <td>
                ยื่นของเล่นทั้งหมดให้เด็ก และสังเกตลักษณะการเล่นของเด็ก<br>
                <strong>ผ่าน:</strong> เด็กสามารถเล่นการใช้สิ่งของตามหน้าที่ เช่น ใช้ช้อนตักในถ้วย หรือใช้หวีหวีผมให้ตุ๊กตา โดยเด็กเล่นได้อย่างน้อย 3 ชนิด
              </td>
              <td>
               1. เล่นสมมติกับเด็ก เช่น แปรงฟันให้ตุ๊กตา (เด็กแสดงวิธีการแปรงฟันได้) เล่นป้อนอาหารให้ตุ๊กตา หวีผมให้ตุ๊กตา <br>
               2. ถ้าเด็กยังทำไม่ได้ ให้สาธิตให้เด็กดู และให้เด็กทำตามจนเด็กทำได้เอง <br>
               3. ถ้าเด็กทำได้แล้วให้เปิดโอกาสให้เด็กคิดหาวิธีเล่นที่หลากหลาย <br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กเลือกใช้วัตถุตามหน้าที่ได้อย่างเหมาะสม ผ่านการจดจำเรียนรู้ 
                ซึ่งสามารถต่อยอดเป็นการฝึกคิดวางแผน (Plan organize) และจัดการทำงานให้สำเร็จต่อไปได้</span>
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
        <!-- Card ข้อที่ 45 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 45 - เดินลากของเล่น หรือสิ่งของได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 16 - 17 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q45_pass_mobile" name="q45_pass" value="1">
                <label class="form-check-label text-success" for="q45_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q45_fail_mobile" name="q45_fail" value="1">
                <label class="form-check-label text-danger" for="q45_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong>กล่องพลาสติกผูกเชือก<br>
              <img src="../image/evaluation_pic/กล่องพลาสติกผูกเชือก.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. เดินลากของเล่นให้เด็กดู<br>
              2. ส่งเชือกลากของเล่นให้เด็ก และบอกให้เด็กเดินลากของเล่นไปเอง</p>
              <p><strong>ผ่าน:</strong> เด็กเดินลากรถของเล่นหรือสิ่งของได้ไกล 2 เมตร โดยอาจเดินไปข้างหน้าหรือเดินถอยหลังก็ได้ พร้อมกับลากของเล่นไป โดยของเล่นอาจคว่ำได้</p>
            </div>
            <div class="accordion" id="training45">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading45">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse45">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse45" class="accordion-collapse collapse" data-bs-parent="#training45">
                  <div class="accordion-body">
                    1. จับมือเด็กให้ลากของเล่นเดินไปข้างหน้าด้วยกัน<br>
                    2. กระตุ้นให้เด็กเดินเองต่อไป โดยทำหลาย ๆ ครั้ง จนเด็กสามารถเดินลากของเล่นไปได้เอง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> สิ่งของในบ้านที่สามารถลากได้ เช่น ตะกร้า รถของเล่น กล่องต่าง ๆ</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 46 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 46 - ขีดเขียนได้เอง (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 16 - 17 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q46_pass_mobile" name="q46_pass" value="1">
                <label class="form-check-label text-success" for="q46_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q46_fail_mobile" name="q46_fail" value="1">
                <label class="form-check-label text-danger" for="q46_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>1. ดินสอ<br>2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ส่งกระดาษและดินสอให้เด็ก<br>
              2. บอกเด็ก "หนูลองวาดรูปซิคะ"(โดยไม่สาธิตให้เด็กดู)</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถขีดเขียนเป็นเส้นใด ๆ บนกระดาษได้เอง</p>
              <p><strong>หมายเหตุ:</strong> ถ้าเด็กเพียงแต่เขียนจุด ๆ หรือกระแทก ดินสอกับกระดาษให้ถือว่าไม่ผ่าน</p>
            </div>
            <div class="accordion" id="training46">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading46">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse46">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse46" class="accordion-collapse collapse" data-bs-parent="#training46">
                  <div class="accordion-body">
                    1. ใช้ดินสอสีแท่งใหญ่เขียนเป็นเส้น ๆ บนกระดาษให้เด็กดู (อาจใช้ดินสอ หรือปากกา หรือสีเมจิกได้)<br>
                    2. ให้เด็กลองทำเอง ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็กให้จับดินสอขีดเขียนเป็นเส้น ๆ ไปมาบนกระดาษ จนเด็กสามารถทำได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 47 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 47 - ทำตามคำสั่งง่าย ๆ โดยไม่มีท่าทางประกอบ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 16 - 17 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q47_pass_mobile" name="q47_pass" value="1">
                <label class="form-check-label text-success" for="q47_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q47_fail_mobile" name="q47_fail" value="1">
                <label class="form-check-label text-danger" for="q47_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong>ของเล่นเด็ก เช่น ตุ๊กตาผ้า บอล รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางของเล่นทุกชิ้นแล้วเล่นกับเด็ก<br>
              2. มองหน้าเด็กแล้วบอกเด็ก เช่น "กอดตุ๊กตาซิ" "ขว้างลูกบอลซิ" "ส่งรถให้ครูซิ"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถแสดงกริยากับสิ่งของได้อย่างน้อย 1 คำสั่ง โดยผู้ประเมินไม่ต้องแสดงท่าทางประกอบ</p>
            </div>
            <div class="accordion" id="training47">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading47">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse47">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse47" class="accordion-collapse collapse" data-bs-parent="#training47">
                  <div class="accordion-body">
                    1. ฝึกเด็ก ขณะที่เด็กกำลังถือหรือเล่นของเล่นอยู่<br>
                    2. บอกเด็กว่า "ส่งของให้แม่" และมองหน้าเด็ก<br>
                    3. ถ้าเด็กทำไม่ได้ ให้จับมือเด็กหยิบของแล้วส่งให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก พร้อมพูดว่า "ส่งของให้แม่" ถ้าเด็กเริ่มทำได้ให้ออกคำสั่งเพียงอย่างเดียวและเปลี่ยนเป็นคำสั่งอื่น ๆ เพิ่ม<br>
                    4. กระตุ้นให้เด็กรู้จักแบ่งปัน ของเล่น ขนม หรือสิ่งของอื่น ๆให้คนรอบข้าง เมื่อเด็กทำได้ให้ชมเชย<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 48 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 48 - ตอบชื่อวัตถุได้ถูกต้อง (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 16 - 17 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q48_pass_mobile" name="q48_pass" value="1">
                <label class="form-check-label text-success" for="q48_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q48_fail_mobile" name="q48_fail" value="1">
                <label class="form-check-label text-danger" for="q48_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong>ของเล่นเด็ก เช่น ตุ๊กตาผ้า บอล รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ชี้ไปที่ของเล่นที่เด็กคุ้นเคยแล้วถามว่า"นี่อะไร"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตอบชื่อวัตถุได้ถูกต้องหรือออกเสียงได้ใกล้เคียง เช่น ตุ๊กตา – ตาได้ 1 ชนิด</p>
            </div>
            <div class="accordion" id="training48">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading48">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse48">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse48" class="accordion-collapse collapse" data-bs-parent="#training48">
                  <div class="accordion-body">
                    1. ให้ใช้สิ่งของหรือของเล่นที่เด็กคุ้นเคยและรู้จักชื่อ เช่น ตุ๊กตา บอล<br>
                    2. หยิบของให้เด็กดู ถามว่า "นี่อะไร" รอให้เด็กตอบ ถ้าไม่ตอบให้บอกเด็ก และให้เด็กพูดตามแล้วถามซ้ำให้เด็กตอบเอง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 49 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 49 - เล่นการใช้สิ่งของตามหน้าที่ได้มากขึ้นด้วยความสัมพันธ์ของ 2 สิ่งขึ้นไป (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 16 - 17 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q49_pass_mobile" name="q49_pass" value="1">
                <label class="form-check-label text-success" for="q49_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q49_fail_mobile" name="q49_fail" value="1">
                <label class="form-check-label text-danger" for="q49_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <p>1. ตุ๊กตาผ้า 2. หวี 3. ถ้วย 4. ช้อนเล็ก 5. แปรงสีฟัน</p>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ยื่นของเล่นทั้งหมดให้เด็ก และสังเกตลักษณะการเล่นของเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเล่นการใช้สิ่งของตามหน้าที่ เช่น ใช้ช้อนตักในถ้วย หรือใช้หวีหวีผมให้ตุ๊กตา โดยเด็กเล่นได้อย่างน้อย 3 ชนิด</p>
            </div>
            <div class="accordion" id="training49">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading49">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse49">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse49" class="accordion-collapse collapse" data-bs-parent="#training49">
                  <div class="accordion-body">
                    1. เล่นสมมติกับเด็ก เช่น แปรงฟันให้ตุ๊กตา (เด็กแสดงวิธีการแปรงฟันได้) เล่นป้อนอาหารให้ตุ๊กตา หวีผมให้ตุ๊กตา<br>
                    2. ถ้าเด็กยังทำไม่ได้ ให้สาธิตให้เด็กดู และให้เด็กทำตามจนเด็กทำได้เอง<br>
                    3. ถ้าเด็กทำได้แล้วให้เปิดโอกาสให้เด็กคิดหาวิธีเล่นที่หลากหลาย<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กเลือกใช้วัตถุตามหน้าที่ได้อย่างเหมาะสม ผ่านการจดจำเรียนรู้ 
                    ซึ่งสามารถต่อยอดเป็นการฝึกคิดวางแผน (Plan organize) และจัดการทำงานให้สำเร็จต่อไปได้</span>
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
      for (let i = 45; i <= 49; i++) {
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
      for (let i = 45; i <= 49; i++) {
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

      for (let i = 45; i <= 49; i++) {
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

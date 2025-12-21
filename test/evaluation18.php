<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '49-54';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 107-111)
    for ($i = 107; $i <= 111; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 107-111)
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
  <title>แบบประเมิน ช่วงอายุ 49 ถึง 54 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 49 - 54 เดือน
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
            <!-- ข้อ 107-111 สำหรับ 49-54 เดือน -->
            <tr>
              <td rowspan="5">49 - 54 เดือน</td>
              <td>107<br>
                  <input type="checkbox" id="q107_pass" name="q107_pass" value="1">
                  <label for="q107_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q107_fail" name="q107_fail" value="1">
                  <label for="q107_fail">ไม่ผ่าน</label><br>
              </td>
              <td>กระโดดสองเท้าพร้อมกันไปข้างหน้าและถอยหลังได้ (GM)<br><br>
              </td>
              <td>
                กระโดดไปด้านข้างทีละข้าง และกระโดดถอยหลัง ให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถกระโดดไปด้านข้างได้ทั้ง 2 ข้าง และกระโดดถอยหลังได้โดยเท้าทั้ง 2 ข้างไปพร้อมกัน
              </td>
              <td>
               1. กระโดดไปทางด้านซ้าย ด้านขวา ถอยหลัง ให้เด็กดู<br>
               2. ยืนตรงข้ามเด็กจับมือเด็กไว้ พร้อมกับบอกว่า “กระโดดไปทางซ้าย กระโดดไปทางขวา กระโดดถอยหลัง” พร้อมกับประคอง
               มือเด็กให้กระโดดไปในทิศทางตามที่บอก<br>
               3. ชวนเด็กเล่นกระโดดที่ไม่เป็นอันตราย<br>
               4. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังในระหว่างการกระโดด
              </td>
            </tr>

            <tr>
              <td>108<br>
                  <input type="checkbox" id="q108_pass" name="q108_pass" value="1">
                  <label for="q107_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q108_fail" name="q108_fail" value="1">
                  <label for="q108_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ประกอบชิ้นส่วนของรูปภาพที่ตัดออกเป็นส่วน ๆ 8 ชิ้นได้ (FM)<br><br>
              <strong>อุปกรณ์:</strong>รูปภาพที่มีส่วนตัดเป็น 8 ชิ้น 1 รูป<br>
              <img src="../image/evaluation_pic/รูปภาพที่มีส่วนต่อกัน 8 ชิ้น.png" alt="Picture Pieces" style="width: 120px; height: 90px;">
                </td>
              <td>
                1. วางรูปภาพที่ต่อแล้วไว้ตรงหน้าเด็กแล้วพูดกับเด็กว่า “หนูดูรูปภาพนี้”<br>
                2. นำชิ้นส่วนรูปภาพวางคละกันแล้วบอกกับเด็กว่า “ต่อให้เป็นรูปภาพนะคะ”<br>
                <strong>ผ่าน:</strong>  เด็กประกอบชิ้นส่วนรูปภาพให้ถูกต้องทั้งหมด 8 ชิ้น
              </td>
              <td>
                1. วางรูปที่ตัดออกเป็น 6 ชิ้น ตรงหน้าเด็กให้เด็กสังเกตรูปภาพนั้น<br>
                2. แยกรูปภาพทั้ง 6 ชิ้น ออกจากกันโดยการขยายรอยต่อให้กว้างขึ้น ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                3. แยกภาพที่ต่อออกจากกัน โดยการสลับตำแหน่งภาพบน - ล่างช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                4. แยกภาพที่ต่อออกจากกัน โดยการสลับตำแหน่งภาพซ้าย - ขวาช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                5. เพิ่มความยาก โดยคละชิ้นส่วนของภาพทั้งหมด ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม ถ้าเด็กเริ่มทำได้แล้วปล่อยให้เด็กต่อภาพด้วยตนเอง<br>
                6. หากเด็กต่อภาพได้คล่องแล้วให้เปลี่ยนเป็นภาพที่ตัดแบ่งเป็น8 ชิ้น<br>
                <span style="color: red;"><strong>วัสดุใช้แทนได้:</strong> รูปภาพ รูปการ์ตูนอื่น ๆ ตัดออกเป็น 8 ชิ้น</span>
              </td>
            </tr>

            <tr>
              <td>109<br>
                  <input type="checkbox" id="q109_pass" name="q109_pass" value="1">
                  <label for="q109_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q109_fail" name="q109_fail" value="1">
                  <label for="q109_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกรูปภาพที่แสดงเวลากลางวัน กลางคืน (RL)<br><br>
              <strong>อุปกรณ์:</strong>1. เวลากลางวัน 3 รูป 2. เวลากลางคืน 3 รูป<br>
              <img src="../image/evaluation_pic/รูปภาพ กลางวัน กลางคืน.png" alt="Day and Night" style="width: 150px; height: 120px;">
            </td>
              <td>
                1. วางรูปกลางวัน 1 รูป กลางคืน 1 รูปตรงหน้าเด็กแล้วบอกเด็กว่า “ชี้รูปที่เป็นเวลากลางวัน” “ชี้รูปที่เป็นเวลากลางคืน”<br>
                2. ให้เด็กชี้ภาพทีละชุด จนครบ 3 ชุดทำทีละชุดโดยวางภาพสลับที่กัน<br>
                <strong>ผ่าน:</strong>  เด็กสามารถชี้ภาพกลางวันและกลางคืนได้ถูกต้องอย่างน้อย 2 ใน 3 ชุด
              </td>
              <td>
                1. นำรูปภาพที่แสดงเวลากลางวัน และกลางคืนอย่างละ 1 รูปให้เด็กดูพร้อมกับอธิบายรูปกลางวัน กลางคืนทีละรูป<br>
                2. บอกให้เด็กชี้ทีละภาพ ถ้าเด็กชี้ไม่ถูกต้อง ให้จับมือเด็กชี้พร้อมกับย้ำชื่อรูปภาพแต่ละภาพจนเด็กชี้ภาพได้ถูกต้อง ให้ลดการช่วยเหลือลง<br>
                3. ฝึกให้เด็กรู้จักกลางวัน กลางคืน ในสถานการณ์จริง<br>
                <span style="color: red;"><strong>ของที่ใช้แทนได้:</strong><br> - สภาพแวดล้อมหรือสถานการณ์จริง <br>
              - รูปภาพกลางวัน กลางคืนทั่วไป  </span>
              </td>
            </tr>

            <tr>
              <td>110<br>
                  <input type="checkbox" id="q110_pass" name="q110_pass" value="1">
                  <label for="q110_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q110_fail" name="q110_fail" value="1">
                  <label for="q110_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ตอบคำถามได้ถูกต้องเมื่อถามว่า “ถ้ารู้สึกร้อน” “ไม่สบาย” “หิว” จะทำอย่างไร (EL)<br><br>
              </td>
              <td>
                ถามเด็กว่า “ถ้าหนูรู้สึกร้อนจะทำอย่างไร” “ถ้าหนูไม่สบายจะทำอย่างไร” “ถ้าหนูหิวจะทำอย่างไร”<br>
                <strong>ผ่าน:</strong> เด็กสามารถตอบถูก 2 ใน 3 คำถาม <br>
                เช่น<br>
                - ร้อน ตอบว่า ไปอาบน้ำ เปิดพัดลม<br>
                - ไม่สบาย ตอบว่า ไปนอน ไปหาหมอ กินยา<br>
                - หิว ตอบว่า กินข้าว กินขนม

              </td>
              <td>
                1. ฝึกเด็กในชีวิตประจำวัน เมื่อเด็กมาบอกความต้องการ เช่น หิว ร้อน ปวดหัว ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ถามเด็กว่า “แล้วหนู
                จะทำอย่างไร” เพื่อกระตุ้นให้เด็กคิดก่อน<br>
                2. ถ้าเด็กตอบไม่ได้ ให้พูดอธิบาย เช่น ถ้าหนูหิวน้ำต้องไปดื่มน้ำถ้าหนูร้อนหนูต้องไปอาบน้ำหรือเปิดพัดลม ถ้าหนูปวดหัวหนูก็ต้อง
                ทานยาหรือไปหาหมอ<br>
                3. ชวนเด็กพูดคุยและกระตุ้นให้ตอบคำถามในสถานการณ์อื่น ๆเพิ่มเติม เช่น เก็บของผู้อื่นได้จะทำอย่างไร เห็นเพื่อนหกล้มจะทำ
                อย่างไร เห็นขยะตกที่พื้นจะทำอย่างไร ถ้าเด็กตอบไม่ได้ ให้สอนวิธีที่ถูกต้องเหมาะสมให้เด็ก<br>
              </td>
            </tr>

            <tr>
              <td>111<br>
                  <input type="checkbox" id="q111_pass" name="q111_pass" value="1">
                  <label for="q111_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q111_fail" name="q111_fail" value="1">
                  <label for="q111_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำความสะอาดตนเองหลังจากอุจจาระได้ (PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองว่า“เมื่อเด็กอุจจาระแล้วเด็กสามารถทำความสะอาดตนเองได้โดยการล้างก้นและเช็ดได้หรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กสามารถทำความสะอาดตนเองได้โดยการล้างก้น ล้างมือ หลังจากที่ขับถ่ายอุจจาระได้
              </td>
              <td>
               1. ฝึกเด็กล้างก้น โดยจับมือข้างที่ถนัดของเด็กให้ถือสายชำระฉีดน้ำหรือใช้ขันน้ำราดน้ำที่ก้นของเด็กพร้อมกับจับมืออีกข้างของเด็ก
               ให้ถูก้นจนสะอาด<br>
               2. ตักน้ำราดโถส้วมหรือกดชักโครกทำความสะอาดส้วม<br>
               3. พาเด็กไปเช็ดก้นให้แห้ง และล้างมือให้สะอาด โดยจับมือเด็กทำทุกขั้นตอน จนเด็กสามารถทำได้เอง<br>
               
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
        <!-- Card ข้อที่ 107 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 107 - กระโดดสองเท้าพร้อมกันไปข้างหน้าและถอยหลังได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 49 - 54 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q107_pass_mobile" name="q107_pass" value="1">
                <label class="form-check-label text-success" for="q107_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q107_fail_mobile" name="q107_fail" value="1">
                <label class="form-check-label text-danger" for="q107_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">กระโดดไปด้านข้างทีละข้าง และกระโดดถอยหลัง ให้เด็กดู แล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถกระโดดไปด้านข้างได้ทั้ง 2 ข้าง และกระโดดถอยหลังได้โดยเท้าทั้ง 2 ข้างไปพร้อมกัน</p>
            </div>
            <div class="accordion" id="training107">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading107">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse107">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse107" class="accordion-collapse collapse" data-bs-parent="#training107">
                  <div class="accordion-body">
                    1. กระโดดไปทางด้านซ้าย ด้านขวา ถอยหลัง ให้เด็กดู<br>
                    2. ยืนตรงข้ามเด็กจับมือเด็กไว้ พร้อมกับบอกว่า “กระโดดไปทางซ้าย กระโดดไปทางขวา กระโดดถอยหลัง” พร้อมกับประคอง
                    มือเด็กให้กระโดดไปในทิศทางตามที่บอก<br>
                    3. ชวนเด็กเล่นกระโดดที่ไม่เป็นอันตราย<br>
                    4. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังในระหว่างการกระโดด
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 108 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 108 - ประกอบชิ้นส่วนของรูปภาพที่ตัดออกเป็นส่วน ๆ 8 ชิ้นได้ (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 49 - 54 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> รูปภาพที่มีส่วนตัดเป็น 8 ชิ้น 1 รูป<br>
              <img src="../image/evaluation_pic/รูปภาพที่มีส่วนต่อกัน 8 ชิ้น.png" alt="Picture Pieces" style="width: 120px; height: 90px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q108_pass_mobile" name="q108_pass" value="1">
                <label class="form-check-label text-success" for="q108_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q108_fail_mobile" name="q108_fail" value="1">
                <label class="form-check-label text-danger" for="q108_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางรูปภาพที่ต่อแล้วไว้ตรงหน้าเด็กแล้วพูดกับเด็กว่า “หนูดูรูปภาพนี้”<br>
              2. นำชิ้นส่วนรูปภาพวางคละกันแล้วบอกกับเด็กว่า “ต่อให้เป็นรูปภาพนะคะ”</p>
              <p><strong>ผ่าน:</strong> เด็กประกอบชิ้นส่วนรูปภาพให้ถูกต้องทั้งหมด 8 ชิ้น</p>
            </div>
            <div class="accordion" id="training108">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading108">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse108">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse108" class="accordion-collapse collapse" data-bs-parent="#training108">
                  <div class="accordion-body">
                    1. วางรูปที่ตัดออกเป็น 6 ชิ้น ตรงหน้าเด็กให้เด็กสังเกตรูปภาพนั้น<br>
                2. แยกรูปภาพทั้ง 6 ชิ้น ออกจากกันโดยการขยายรอยต่อให้กว้างขึ้น ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                3. แยกภาพที่ต่อออกจากกัน โดยการสลับตำแหน่งภาพบน - ล่างช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                4. แยกภาพที่ต่อออกจากกัน โดยการสลับตำแหน่งภาพซ้าย - ขวาช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม<br>
                5. เพิ่มความยาก โดยคละชิ้นส่วนของภาพทั้งหมด ช่วยกันกับเด็กต่อเป็นภาพเหมือนเดิม ถ้าเด็กเริ่มทำได้แล้วปล่อยให้เด็กต่อภาพด้วยตนเอง<br>
                6. หากเด็กต่อภาพได้คล่องแล้วให้เปลี่ยนเป็นภาพที่ตัดแบ่งเป็น8 ชิ้น<br>
                <span style="color: red;"><strong>วัสดุใช้แทนได้:</strong> รูปภาพ รูปการ์ตูนอื่น ๆ ตัดออกเป็น 8 ชิ้น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 109 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 109 - เลือกรูปภาพที่แสดงเวลากลางวัน กลางคืน (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 49 - 54 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> รูปภาพ <br>1. เวลากลางวัน 3 รูป 2. เวลากลางคืน 3 รูป<br>
              <img src="../image/evaluation_pic/รูปภาพ กลางวัน กลางคืน.png" alt="Day and Night" style="width: 150px; height: 120px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q109_pass_mobile" name="q109_pass" value="1">
                <label class="form-check-label text-success" for="q109_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q109_fail_mobile" name="q109_fail" value="1">
                <label class="form-check-label text-danger" for="q109_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางรูปกลางวัน 1 รูป กลางคืน 1 รูปตรงหน้าเด็กแล้วบอกเด็กว่า “ชี้รูปที่เป็นเวลากลางวัน” “ชี้รูปที่เป็นเวลากลางคืน”<br>
                2. ให้เด็กชี้ภาพทีละชุด จนครบ 3 ชุดทำทีละชุดโดยวางภาพสลับที่กัน<br>
              <p><strong>ผ่าน:</strong> เด็กสามารถชี้ภาพกลางวันและกลางคืนได้ถูกต้องอย่างน้อย 2 ใน 3 ชุด</p>
            </div>
            <div class="accordion" id="training109">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading109">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse109">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse109" class="accordion-collapse collapse" data-bs-parent="#training109">
                  <div class="accordion-body">
                    1. นำรูปภาพที่แสดงเวลากลางวัน และกลางคืนอย่างละ 1 รูปให้เด็กดูพร้อมกับอธิบายรูปกลางวัน กลางคืนทีละรูป<br>
                    2. บอกให้เด็กชี้ทีละภาพ ถ้าเด็กชี้ไม่ถูกต้อง ให้จับมือเด็กชี้พร้อมกับย้ำชื่อรูปภาพแต่ละภาพจนเด็กชี้ภาพได้ถูกต้อง ให้ลดการช่วยเหลือลง<br>
                    3. ฝึกให้เด็กรู้จักกลางวัน กลางคืน ในสถานการณ์จริง<br>
                    <strong>ของที่ใช้แทนได้:</strong><br>
                    <list>
                      <li>สภาพแวดล้อมหรือสถานการณ์จริง</li>
                      <li>รูปภาพกลางวัน กลางคืน</li>
                    </list>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 110 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 110 - สร้างคำใหม่ได้ด้วยการผสมคำ เช่น ลิงโลด ครอบบิน กบทอง เป็นต้น (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 49 - 54 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q110_pass_mobile" name="q110_pass" value="1">
                <label class="form-check-label text-success" for="q110_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q110_fail_mobile" name="q110_fail" value="1">
                <label class="form-check-label text-danger" for="q110_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามเด็กว่า “ถ้าหนูรู้สึกร้อนจะทำอย่างไร” “ถ้าหนูไม่สบายจะทำอย่างไร” “ถ้าหนูหิวจะทำอย่างไร”</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตอบถูก 2 ใน 3 คำถาม <br>
                เช่น<br>
                - ร้อน ตอบว่า ไปอาบน้ำ เปิดพัดลม<br>
                - ไม่สบาย ตอบว่า ไปนอน ไปหาหมอ กินยา<br>
                - หิว ตอบว่า กินข้าว กินขนม</p>
            </div>
            <div class="accordion" id="training110">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading110">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse110">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse110" class="accordion-collapse collapse" data-bs-parent="#training110">
                  <div class="accordion-body">
                    1. ฝึกเด็กในชีวิตประจำวัน เมื่อเด็กมาบอกความต้องการ เช่น หิว ร้อน ปวดหัว ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ถามเด็กว่า “แล้วหนู
                จะทำอย่างไร” เพื่อกระตุ้นให้เด็กคิดก่อน<br>
                2. ถ้าเด็กตอบไม่ได้ ให้พูดอธิบาย เช่น ถ้าหนูหิวน้ำต้องไปดื่มน้ำถ้าหนูร้อนหนูต้องไปอาบน้ำหรือเปิดพัดลม ถ้าหนูปวดหัวหนูก็ต้อง
                ทานยาหรือไปหาหมอ<br>
                3. ชวนเด็กพูดคุยและกระตุ้นให้ตอบคำถามในสถานการณ์อื่น ๆเพิ่มเติม เช่น เก็บของผู้อื่นได้จะทำอย่างไร เห็นเพื่อนหกล้มจะทำ
                อย่างไร เห็นขยะตกที่พื้นจะทำอย่างไร ถ้าเด็กตอบไม่ได้ ให้สอนวิธีที่ถูกต้องเหมาะสมให้เด็ก<br>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 111 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 111 - ทำความสะอาดตนเองหลังจากอุจจาระได้ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 49 - 54 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q111_pass_mobile" name="q111_pass" value="1">
                <label class="form-check-label text-success" for="q111_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q111_fail_mobile" name="q111_fail" value="1">
                <label class="form-check-label text-danger" for="q111_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองว่า “เมื่อเด็กอุจจาระแล้วเด็กสามารถทำความสะอาด
                ตนเองได้โดยการล้างก้นและล้างมือได้หรือไม่”</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถทำความสะอาดตนเองโดยการล้างก้น ล้างมือ หลังจากขับถ่าย
              อุจจาระได้</p>
            </div>
            <div class="accordion" id="training111">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading111">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse111">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse111" class="accordion-collapse collapse" data-bs-parent="#training111">
                  <div class="accordion-body">
                    1. ฝึกเด็กล้างก้น โดยจับมือข้างที่ถนัดของเด็กให้ถือสายชำระฉีดน้ำหรือใช้ขันน้ำราดน้ำที่ก้นของเด็กพร้อมกับจับมืออีกข้างของเด็ก
               ให้ถูก้นจนสะอาด<br>
               2. ตักน้ำราดโถส้วมหรือกดชักโครกทำความสะอาดส้วม<br>
               3. พาเด็กไปเช็ดก้นให้แห้ง และล้างมือให้สะอาด โดยจับมือเด็กทำทุกขั้นตอน จนเด็กสามารถทำได้เอง<br>
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
      for (let i = 107; i <= 111; i++) {
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
      for (let i = 107; i <= 111; i++) {
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

      for (let i = 107; i <= 111; i++) {
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

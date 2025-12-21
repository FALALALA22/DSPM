<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '0-1';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม
    for ($i = 1; $i <= 5; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ
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
  <title>แบบประเมิน ช่วงอายุ แรกเกิดถึง 1 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: แรกเกิด - 1 เดือน
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
              <td>แรกเกิด - 1 เดือน</td>
              <td>1<br>
                  <input type="checkbox" id="q1_pass" name="q1_pass" value="1">
                  <label for="q1_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q1_fail" name="q1_fail" value="1">
                  <label for="q1_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ท่านอนคว่ำยกศีรษะและหันไปข้างใดข้างหนึ่งได้ (GM)</td>
              <td>
                จัดให้เด็กอยู่ในท่านอนคว่ำบนเบาะนอน แขนทั้งสองข้างอยู่หน้าไหล่<br>
                <strong>ผ่าน:</strong> เด็กสามารถยกศีรษะและหันไปข้างใดข้างหนึ่งได้
              </td>
              <td>
                จัดให้เด็กอยู่ในท่านอนคว่ำ เขย่าของเล่นที่มีเสียงตรงหน้าเด็ก ระยะห่างประมาณ 30 ซม. <br>
                เมื่อเด็กมองที่ของเล่นแล้วค่อย ๆ เคลื่อนของเล่นมาทางด้านซ้ายหรือขวา <br>
                เพื่อให้เด็กหันศีรษะมองตาม ถ้าเด็กทำไม่ได้ให้ประคองศีรษะเด็กให้หันตาม
              </td>
            </tr>

            <tr>
              <td></td>
              <td>2<br>
                  <input type="checkbox" id="q2_pass" name="q2_pass" value="1">
                  <label for="q2_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q2_fail" name="q2_fail" value="1">
                  <label for="q2_fail">ไม่ผ่าน</label><br>
              </td>
              <td>มองตามถึงกึ่งกลางลำตัว (FM)<br>
               <img src="../image/Screenshot 2025-07-06 114737.png" alt="Family" style="width: 90px; height: 90px;"><br>
               อุปกรณ์: ลูกบอลผ้าสีแดง<br>
               <img src="../image/Screenshot 2025-07-06 114822.png" alt="Family" style="width: 90px; height: 90px;"><br>   
              </td>
              <td>
                จัดให้เด็กอยู่ในท่านอนหงาย ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็กประมาณ 20 ซม. ขยับลูกบอลผ้าสีแดงเพื่อกระตุ้นให้เด็กสนใจ แล้วเคลื่อนลูกบอลผ้าสีแดงช้า ๆ ไปทางด้านข้างลำตัวเด็กข้างใดข้างหนึ่งเคลื่อนลูกบอลผ้าสีแดงกลับมาที่จุดกึ่งกลางลำตัวเด็ก
  <br>
                <strong>ผ่าน:</strong> เด็กมองตามลูกบอลผ้าสีแดง จาก
  ด้านข้างถึงระยะกึ่งกลางลำตัวได้
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย ก้มหน้าให้อยู่ใกล้ ๆ เด็ก ห่างจากหน้าเด็กประมาณ 20 ซม.<br>
  2. เรียกให้เด็กสนใจ โดยเรียกชื่อเด็ก เมื่อเด็กสนใจมองให้เคลื่อนหรือเอียงหน้าไปทางด้านข้างลำตัวเด็กอย่างช้าๆ เพื่อให้เด็กมองตาม<br>
  3. ถ้าเด็กไม่มองให้ช่วยเหลือโดยการประคองหน้าเด็กให้มองตาม<br>
  4. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและ
  มองตาม<br>
                <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>อุปกรณ์ที่มีสีสดใส เส้นผ่านศูนย์กลางประมาณ
  10 ซม. เช่น ผ้า/ลูกบอลผ้า พู่ไหมพรม</span>
              </td>
            </tr>

            <tr>
              <td></td>
              <td>3<br>
                  <input type="checkbox" id="q3_pass" name="q3_pass" value="1">
                  <label for="q3_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q3_fail" name="q3_fail" value="1">
                  <label for="q3_fail">ไม่ผ่าน</label><br>
              </td>
              <td>สะดุ้งหรือเคลื่อนไหวร่างกายเมื่อได้ยินเสียงพูดระดับปกติ(RL)</td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย<br>
                2. อยู่ห่างจากเด็กประมาณ 60 ซม. เรียกชื่อเด็กจากด้านข้างทีละข้างทั้งซ้ายและขวา โดยพูดเสียงดังปกติ<br>
                <strong>ผ่าน:</strong> เด็กแสดงการรับรู้ด้วยการกะพริบตา สะดุ้ง หรือเคลื่อนไหวร่างกาย
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย เรียกชื่อหรือพูดคุยกับเด็กจากด้านข้างทีละข้างทั้งข้างซ้ายและขวาโดยพูดเสียงดังกว่าปกติ <br>
                2. หากเด็กสะดุ้งหรือขยับตัวให้ยิ้มและสัมผัสตัวเด็ก ลดเสียงพูดคุยลงเรื่อย ๆ จนให้อยู่ในระดับปกติ<br>
              </td>
            </tr>

            <tr>
              <td></td>
              <td>4<br>
                  <input type="checkbox" id="q4_pass" name="q4_pass" value="1">
                  <label for="q4_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q4_fail" name="q4_fail" value="1">
                  <label for="q4_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ส่งเสียงอ้อแอ้ (EL)</td>
              <td>
                สังเกตว่าเด็กส่งเสียงอ้อแอ้ในระหว่างทำการประเมิน หรือถามจากพ่อแม่ผู้ปกครอง<br>
                <strong>ผ่าน:</strong> เด็กทำเสียงอ้อแอ้ได้
              </td>
              <td>
                อุ้มหรือสัมผัสตัวเด็กเบา ๆ มองสบตา แล้วพูดคุยกับเด็กด้วยเสียงสูง ๆ ต่ำ ๆ เพื่อให้เด็กสนใจและหยุดรอให้เด็กส่งเสียงอ้อแอ้ตอบ
              </td>
            </tr>

            <tr>
              <td></td>
              <td>5<br>
                  <input type="checkbox" id="q5_pass" name="q5_pass" value="1">
                  <label for="q5_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q5_fail" name="q5_fail" value="1">
                  <label for="q5_fail">ไม่ผ่าน</label><br>
              </td>
              <td>มองจ้องหน้าได้นาน 1 - 2 วินาที(PS)</td>
              <td>
                อุ้มเด็กให้ห่างจากหน้าพ่อแม่ ผู้ปกครองหรือผู้ประเมินประมาณ 30 ซม. ยิ้มและพูดคุยกับเด็ก<br>
                <strong>ผ่าน:</strong> เด็กสามารถมองจ้องหน้าได้อย่างน้อย 1 วินาที
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก <br>
                2. สบตา พูดคุย ส่งเสียง ยิ้ม หรือทำตาลักษณะต่าง ๆ เช่น ตาโตกะพริบตา เพื่อให้เด็กสนใจมอง เป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล โดยทำให้เด็กมีอารมณ์ดี<br>
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
        <!-- Card ข้อที่ 1 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 1 - ท่านอนคว่ำยกศีรษะและหันไปข้างใดข้างหนึ่งได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> แรกเกิด - 1 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q1_pass_mobile" name="q1_pass" value="1">
                <label class="form-check-label text-success" for="q1_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q1_fail_mobile" name="q1_fail" value="1">
                <label class="form-check-label text-danger" for="q1_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดให้เด็กอยู่ในท่านอนคว่ำบนเบาะนอน แขนทั้งสองข้างอยู่หน้าไหล่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยกศีรษะและหันไปข้างใดข้างหนึ่งได้</p>
            </div>
            <div class="accordion" id="training1">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading1">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse1" class="accordion-collapse collapse" data-bs-parent="#training1">
                  <div class="accordion-body">
                    จัดให้เด็กอยู่ในท่านอนคว่ำ เขย่าของเล่นที่มีเสียงตรงหน้าเด็ก ระยะห่างประมาณ 30 ซม. เมื่อเด็กมองที่ของเล่นแล้วค่อย ๆ เคลื่อนของเล่นมาทางด้านซ้ายหรือขวา เพื่อให้เด็กหันศีรษะมองตาม ถ้าเด็กทำไม่ได้ให้ประคองศีรษะเด็กให้หันตาม
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 2 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 2 - มองตามถึงกึ่งกลางลำตัว (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> แรกเกิด - 1 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q2_pass_mobile" name="q2_pass" value="1">
                <label class="form-check-label text-success" for="q2_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q2_fail_mobile" name="q2_fail" value="1">
                <label class="form-check-label text-danger" for="q2_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong><br>
              <img src="../image/Screenshot 2025-07-06 114737.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
              <p>ลูกบอลผ้าสีแดง</p>
              <img src="../image/Screenshot 2025-07-06 114822.png" alt="อุปกรณ์" class="img-fluid" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดให้เด็กอยู่ในท่านอนหงาย ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็กประมาณ 20 ซม. ขยับลูกบอลผ้าสีแดงเพื่อกระตุ้นให้เด็กสนใจ แล้วเคลื่อนลูกบอลผ้าสีแดงช้า ๆ ไปทางด้านข้างลำตัวเด็กข้างใดข้างหนึ่งเคลื่อนลูกบอลผ้าสีแดงกลับมาที่จุดกึ่งกลางลำตัวเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กมองตามลูกบอลผ้าสีแดง จากด้านข้างถึงระยะกึ่งกลางลำตัวได้</p>
            </div>
            <div class="accordion" id="training2">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading2">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#training2">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนหงาย ก้มหน้าให้อยู่ใกล้ ๆ เด็ก ห่างจากหน้าเด็กประมาณ 20 ซม.<br>
                    2. เรียกให้เด็กสนใจ โดยเรียกชื่อเด็ก เมื่อเด็กสนใจมองให้เคลื่อนหรือเอียงหน้าไปทางด้านข้างลำตัวเด็กอย่างช้าๆ เพื่อให้เด็กมองตาม<br>
                    3. ถ้าเด็กไม่มองให้ช่วยเหลือโดยการประคองหน้าเด็กให้มองตาม<br>
                    4. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีสดใส เส้นผ่านศูนย์กลางประมาณ 10 ซม. เช่น ผ้า/ลูกบอลผ้า พู่ไหมพรม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 3 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 3 - สะดุ้งหรือเคลื่อนไหวร่างกายเมื่อได้ยินเสียงพูดระดับปกติ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> แรกเกิด - 1 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q3_pass_mobile" name="q3_pass" value="1">
                <label class="form-check-label text-success" for="q3_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q3_fail_mobile" name="q3_fail" value="1">
                <label class="form-check-label text-danger" for="q3_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนหงาย<br>
              2. อยู่ห่างจากเด็กประมาณ 60 ซม. เรียกชื่อเด็กจากด้านข้างทีละข้างทั้งซ้ายและขวา โดยพูดเสียงดังปกติ</p>
              <p><strong>ผ่าน:</strong> เด็กแสดงการรับรู้ด้วยการกะพริบตา สะดุ้ง หรือเคลื่อนไหวร่างกาย</p>
            </div>
            <div class="accordion" id="training3">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading3">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#training3">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนหงาย เรียกชื่อหรือพูดคุยกับเด็กจากด้านข้างทีละข้างทั้งข้างซ้ายและขวาโดยพูดเสียงดังกว่าปกติ<br>
                    2. หากเด็กสะดุ้งหรือขยับตัวให้ยิ้มและสัมผัสตัวเด็ก ลดเสียงพูดคุยลงเรื่อย ๆ จนให้อยู่ในระดับปกติ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 4 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 4 - ส่งเสียงอ้อแอ้ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> แรกเกิด - 1 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q4_pass_mobile" name="q4_pass" value="1">
                <label class="form-check-label text-success" for="q4_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q4_fail_mobile" name="q4_fail" value="1">
                <label class="form-check-label text-danger" for="q4_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตว่าเด็กส่งเสียงอ้อแอ้ในระหว่างทำการประเมิน หรือถามจากพ่อแม่ผู้ปกครอง</p>
              <p><strong>ผ่าน:</strong> เด็กทำเสียงอ้อแอ้ได้</p>
            </div>
            <div class="accordion" id="training4">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading4">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#training4">
                  <div class="accordion-body">
                    อุ้มหรือสัมผัสตัวเด็กเบา ๆ มองสบตา แล้วพูดคุยกับเด็กด้วยเสียงสูง ๆ ต่ำ ๆ เพื่อให้เด็กสนใจและหยุดรอให้เด็กส่งเสียงอ้อแอ้ตอบ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 5 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 5 - มองจ้องหน้าได้นาน 1 - 2 วินาที (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> แรกเกิด - 1 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q5_pass_mobile" name="q5_pass" value="1">
                <label class="form-check-label text-success" for="q5_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q5_fail_mobile" name="q5_fail" value="1">
                <label class="form-check-label text-danger" for="q5_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">อุ้มเด็กให้ห่างจากหน้าพ่อแม่ ผู้ปกครองหรือผู้ประเมินประมาณ 30 ซม. ยิ้มและพูดคุยกับเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถมองจ้องหน้าได้อย่างน้อย 1 วินาที</p>
            </div>
            <div class="accordion" id="training5">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading5">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse5">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse5" class="accordion-collapse collapse" data-bs-parent="#training5">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก<br>
                    2. สบตา พูดคุย ส่งเสียง ยิ้ม หรือทำตาลักษณะต่าง ๆ เช่น ตาโตกะพริบตา เพื่อให้เด็กสนใจมอง เป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล โดยทำให้เด็กมีอารมณ์ดี
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
      for (let i = 1; i <= 5; i++) {
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
      for (let i = 1; i <= 5; i++) {
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

      for (let i = 1; i <= 5; i++) {
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

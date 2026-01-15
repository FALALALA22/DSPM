<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '13-15';

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
    for ($i = 40; $i <= 44; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 40-44)
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
  <title>แบบประเมิน ช่วงอายุ 13 ถึง 15 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 13 - 15 เดือน
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
            <!-- ข้อ 40-44 สำหรับ 10-12 เดือน -->
            <tr>
              <td rowspan="5">13 - 15 เดือน</td>
              <td>40<br>
                  <input type="checkbox" id="q40_pass" name="q40_pass" value="1">
                  <label for="q40_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q40_fail" name="q40_fail" value="1">
                  <label for="q40_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนอยู่ตามลำพังได้นานอย่างน้อย10 วินาที (GM)<br><br>
              <td>
                จัดเด็กอยู่ในท่ายืนโดยไม่ต้องช่วยพยุง<br>
                <strong>ผ่าน:</strong> เด็กสามารถยืน โดยไม่ต้องช่วยพยุงได้นาน อย่างน้อย 10 วินาที
              </td>
              <td>
               พยุงตัวเด็กให้ยืน เมื่อเด็กยืนได้แล้วให้เปลี่ยนมาจับข้อมือเด็กแล้วค่อย ๆ ปล่อยมือเพื่อให้เด็กยืนเอง และค่อย ๆ เพิ่มเวลาขึ้นจนเด็กยืนได้เอง นาน 10 วินาที
              </td>
            </tr>

            <tr>
              <td>41<br>
                  <input type="checkbox" id="q41_pass" name="q41_pass" value="1">
                  <label for="q41_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q41_fail" name="q41_fail" value="1">
                  <label for="q41_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ขีดเขียน (เป็นเส้น)บนกระดาษได้ (FM)<br><br>
                <strong>อุปกรณ์:</strong> 1. ดินสอ
                2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 160px; height: 160px;"><br></td>
              <td>
                1. แสดงวิธีการขีดเขียนบนกระดาษด้วยดินสอให้เด็กด<br>
                2. ส่งดินสอให้เด็ก และพูดว่า “ลองวาดซิ”<br>
                <strong>ผ่าน:</strong>  เด็กสามารถขีดเขียนเป็นเส้นใด ๆก็ได้บนกระดาษ
              </td>
              <td>
                1. ใช้ดินสอสีแท่งใหญ่เขียนเป็นเส้น ๆ บนกระดาษให้เด็กดู (อาจใช้ดินสอ หรือปากกา หรือสีเมจิก ได้)<br>
                2. ให้เด็กลองทำเอง ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็กให้จับดินสอขีดเขียนเป็นเส้น ๆ ไปมาบนกระดาษ จนเด็กสามารถทำได้เอง
              </td>
            </tr>

            <tr>
              <td>42<br>
                  <input type="checkbox" id="q42_pass" name="q42_pass" value="1">
                  <label for="q42_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q42_fail" name="q42_fail" value="1">
                  <label for="q42_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกวัตถุตามคำสั่งได้ถูกต้อง 2 ชนิด (RL)<br><br>
              <strong>อุปกรณ์:</strong> ชุดทดสอบการเลือกสิ่งของ เช่น บอล ตุ๊กตาผ้า ถ้วย รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="Family" style="width: 160px; height: 100px;"><br></td>
              <td>
                วางวัตถุ 2 ชนิด ไว้ตรงหน้าเด็ก แล้วถามว่า “...อยู่ไหน” จนครบทั้ง 2 ชนิดแล้วจึงสลับตำแหน่งที่วางวัตถุ ให้โอกาสประเมิน 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถชี้หรือหยิบวัตถุได้ถูกต้องทั้ง 2 ชนิด ชนิดละ 1 ครั้ง
              </td>
              <td>
                1. เตรียมวัตถุที่เด็กคุ้นเคย 2 ชนิด นั่งตรงหน้าเด็ก เรียกชื่อเด็กให้เด็กมองหน้า แล้วจึงให้เด็กดูของเล่น พร้อมกับบอกชื่อวัตถุทีละชิ้น <br>
                2. เก็บวัตถุให้พ้นสายตาเด็ก สักครู่หยิบวัตถุทั้ง 2 ชิ้นให้ดู แล้วบอกชื่อของ หลังจากนั้นบอกชื่อวัตถุทีละชิ้น แล้วให้เด็กชี้ ถ้าชี้ได้ถูกต้อง
                ให้พูดชมเชย ถ้าไม่ทำให้จับมือเด็กชี้ พร้อมกับเลื่อนของไปใกล้และย้ำชื่อของแต่ละชิ้น<br>
                3. ถ้าเด็กชี้ไม่ถูกต้อง ให้หยิบของชิ้นนั้นออก และเลื่อนของชิ้นที่ถูกต้อง ไปใกล้ ถ้าเด็กหยิบของนั้นให้ชมเชย
                4. เมื่อเด็กทำได้ 4 ใน 5 ครั้ง ให้เปลี่ยนของเล่นคู่ต่อไป
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
              </td>
            </tr>

            <tr>
              <td>43<br>
                  <input type="checkbox" id="q43_pass" name="q43_pass" value="1">
                  <label for="q43_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q43_fail" name="q43_fail" value="1">
                  <label for="q43_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดคำพยางค์เดียว (คำโดด)ได้ 2 คำ (EL)</td>
              <td>
                ถามผู้ปกครองว่า “เด็กพูดเป็นคำอะไรได้บ้าง” หรือสังเกตเด็ก<br>
                <strong>ผ่าน:</strong> เด็กสามารถพูดคำพยางค์เดียว(คำโดด) ได้อย่างน้อย 2 คำ ถึงแม้จะยังไม่ชัด
                <strong>หมายเหตุ</strong> ต้องไม่ใช่ชื่อคนหรือชื่อสัตว์เลี้ยงในบ้าน
              </td>
              <td>
                1. สอนให้เด็กพูดคำสั้น ๆ ตามเหตุการณ์จริง เช่น ในเวลารับประทานอาหาร ก่อนป้อนข้าวพูด “หม่ำ” ให้เด็กพูดตาม “หม่ำ”<br>
                2. เมื่อแต่งตัวเสร็จ ให้พูด “ไป” ให้เด็กพูดตาม “ไป” ก่อน แล้วพาเดินออกจากห้อง<br>
                3. เมื่อเปิดหนังสือนิทานให้พูดคำว่า “อ่าน” หรือ “ดู” ให้เด็กพูดตาม แล้วแสดงให้เด็กเข้าใจโดยอ่านหรือดู
              </td>
            </tr>

            <tr>
              <td>44<br>
                  <input type="checkbox" id="q44_pass" name="q44_pass" value="1">
                  <label for="q44_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q44_fail" name="q44_fail" value="1">
                  <label for="q44_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนแบบท่าทางการทำงานบ้าน (PS)<br><br>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กเคยเล่นเลียนแบบการทำงานบ้านบ้างหรือไม่ เช่น กวาดบ้าน ถูบ้าน เช็ดโต๊ะ<br>
                <strong>ผ่าน:</strong>   เด็กเลียนแบบท่าทางการทำงานบ้านได้อย่างน้อย 1 อย่าง
              </td>
              <td>
               ขณะทำงานบ้าน จัดหาอุปกรณ์ที่เหมาะกับเด็ก และกระตุ้นให้เด็กมีส่วนร่วมในการทำงานบ้าน เช่น เช็ดโต๊ะ กวาดบ้าน ถูบ้าน เก็บเสื้อผ้า
               เป็นต้น โดยทำงานให้เด็กดูเป็นตัวอย่าง หากเด็กทำได้ควรจะชมเชยเพื่อให้เด็กอยากจะทำได้ด้วยตนเอง
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
        <!-- Card ข้อที่ 40 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 40 - ยืนอยู่ตามลำพังได้นานอย่างน้อย10 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 13 - 15 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q40_pass_mobile" name="q40_pass" value="1">
                <label class="form-check-label text-success" for="q40_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q40_fail_mobile" name="q40_fail" value="1">
                <label class="form-check-label text-danger" for="q40_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดเด็กอยู่ในท่ายืนโดยไม่ต้องช่วยพยุง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยืน โดยไม่ต้องช่วยพยุงได้นาน อย่างน้อย 10 วินาที</p>
            </div>
            <div class="accordion" id="training40">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading40">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse40">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse40" class="accordion-collapse collapse" data-bs-parent="#training40">
                  <div class="accordion-body">
                    พยุงตัวเด็กให้ยืน เมื่อเด็กยืนได้แล้วให้เปลี่ยนมาจับข้อมือเด็กแล้วค่อย ๆ ปล่อยมือเพื่อให้เด็กยืนเอง และค่อย ๆ เพิ่มเวลาขึ้นจนเด็กยืนได้เอง นาน 10 วินาที
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 41 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 41 - ขีดเขียน (เป็นเส้น)บนกระดาษได้ (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 13 - 15 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q41_pass_mobile" name="q41_pass" value="1">
                <label class="form-check-label text-success" for="q41_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q41_fail_mobile" name="q41_fail" value="1">
                <label class="form-check-label text-danger" for="q41_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> 1. ดินสอ<br>2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. แสดงวิธีการขีดเขียนบนกระดาษด้วยดินสอให้เด็กดู<br>
              2. ส่งดินสอให้เด็ก และพูดว่า "ลองวาดซิ"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถขีดเขียนเป็นเส้นใด ๆก็ได้บนกระดาษ</p>
            </div>
            <div class="accordion" id="training41">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading41">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse41">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse41" class="accordion-collapse collapse" data-bs-parent="#training41">
                  <div class="accordion-body">
                    1. ใช้ดินสอสีแท่งใหญ่เขียนเป็นเส้น ๆ บนกระดาษให้เด็กดู (อาจใช้ดินสอ หรือปากกา หรือสีเมจิก ได้)<br>
                    2. ให้เด็กลองทำเอง ถ้าเด็กทำไม่ได้ ช่วยจับมือเด็กให้จับดินสอขีดเขียนเป็นเส้น ๆ ไปมาบนกระดาษ จนเด็กสามารถทำได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 42 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 42 - เลือกวัตถุตามคำสั่งได้ถูกต้อง 2 ชนิด (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 13 - 15 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q42_pass_mobile" name="q42_pass" value="1">
                <label class="form-check-label text-success" for="q42_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q42_fail_mobile" name="q42_fail" value="1">
                <label class="form-check-label text-danger" for="q42_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong>ชุดทดสอบการเลือกสิ่งของ เช่น บอล ตุ๊กตาผ้า ถ้วย รถ<br>
              <img src="../image/evaluation_pic/ชุดทดสอบการเลือก.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางวัตถุ 2 ชนิด ไว้ตรงหน้าเด็ก แล้วถามว่า "...อยู่ไหน" จนครบทั้ง 2 ชนิดแล้วจึงสลับตำแหน่งที่วางวัตถุ ให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถชี้หรือหยิบวัตถุได้ถูกต้องทั้ง 2 ชนิด ชนิดละ 1 ครั้ง</p>
            </div>
            <div class="accordion" id="training42">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading42">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse42">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse42" class="accordion-collapse collapse" data-bs-parent="#training42">
                  <div class="accordion-body">
                    1. เตรียมวัตถุที่เด็กคุ้นเคย 2 ชนิด นั่งตรงหน้าเด็ก เรียกชื่อเด็กให้เด็กมองหน้า แล้วจึงให้เด็กดูของเล่น พร้อมกับบอกชื่อวัตถุทีละชิ้น<br>
                    2. เก็บวัตถุให้พ้นสายตาเด็ก สักครู่หยิบวัตถุทั้ง 2 ชิ้นให้ดู แล้วบอกชื่อของ หลังจากนั้นบอกชื่อวัตถุทีละชิ้น แล้วให้เด็กชี้ ถ้าชี้ได้ถูกต้องให้พูดชมเชย ถ้าไม่ทำให้จับมือเด็กชี้ พร้อมกับเลื่อนของไปใกล้และย้ำชื่อของแต่ละชิ้น<br>
                    3. ถ้าเด็กชี้ไม่ถูกต้อง ให้หยิบของชิ้นนั้นออก และเลื่อนของชิ้นที่ถูกต้อง ไปใกล้ ถ้าเด็กหยิบของนั้นให้ชมเชย<br>
                    4. เมื่อเด็กทำได้ 4 ใน 5 ครั้ง ให้เปลี่ยนของเล่นคู่ต่อไป<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 43 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 43 - พูดคำพยางค์เดียว (คำโดด)ได้ 2 คำ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 13 - 15 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q43_pass_mobile" name="q43_pass" value="1">
                <label class="form-check-label text-success" for="q43_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q43_fail_mobile" name="q43_fail" value="1">
                <label class="form-check-label text-danger" for="q43_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามผู้ปกครองว่า "เด็กพูดเป็นคำอะไรได้บ้าง" หรือสังเกตเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถพูดคำพยางค์เดียว(คำโดด) ได้อย่างน้อย 2 คำ ถึงแม้จะยังไม่ชัด</p>
              <p><strong>หมายเหตุ:</strong> ต้องไม่ใช่ชื่อคนหรือชื่อสัตว์เลี้ยงในบ้าน</p>
            </div>
            <div class="accordion" id="training43">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading43">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse43">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse43" class="accordion-collapse collapse" data-bs-parent="#training43">
                  <div class="accordion-body">
                    1. สอนให้เด็กพูดคำสั้น ๆ ตามเหตุการณ์จริง เช่น ในเวลารับประทานอาหาร ก่อนป้อนข้าวพูด "หม่ำ" ให้เด็กพูดตาม "หม่ำ"<br>
                    2. เมื่อแต่งตัวเสร็จ ให้พูด "ไป" ให้เด็กพูดตาม "ไป" ก่อน แล้วพาเดินออกจากห้อง<br>
                    3. เมื่อเปิดหนังสือนิทานให้พูดคำว่า "อ่าน" หรือ "ดู" ให้เด็กพูดตาม แล้วแสดงให้เด็กเข้าใจโดยอ่านหรือดู
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 44 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 44 - เลียนแบบท่าทางการทำงานบ้าน (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 13 - 15 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q44_pass_mobile" name="q44_pass" value="1">
                <label class="form-check-label text-success" for="q44_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q44_fail_mobile" name="q44_fail" value="1">
                <label class="form-check-label text-danger" for="q44_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กเคยเล่นเลียนแบบการทำงานบ้านบ้างหรือไม่ เช่น กวาดบ้าน ถูบ้าน เช็ดโต๊ะ</p>
              <p><strong>ผ่าน:</strong> เด็กเลียนแบบท่าทางการทำงานบ้านได้อย่างน้อย 1 อย่าง</p>
            </div>
            <div class="accordion" id="training44">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading44">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse44">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse44" class="accordion-collapse collapse" data-bs-parent="#training44">
                  <div class="accordion-body">
                    ขณะทำงานบ้าน จัดหาอุปกรณ์ที่เหมาะกับเด็ก และกระตุ้นให้เด็กมีส่วนร่วมในการทำงานบ้าน เช่น เช็ดโต๊ะ กวาดบ้าน ถูบ้าน เก็บเสื้อผ้า เป็นต้น โดยทำงานให้เด็กดูเป็นตัวอย่าง หากเด็กทำได้ควรจะชมเชยเพื่อให้เด็กอยากจะทำได้ด้วยตนเอง
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
      for (let i = 40; i <= 44; i++) {
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
      for (let i = 40; i <= 44; i++) {
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

      for (let i = 40; i <= 44; i++) {
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

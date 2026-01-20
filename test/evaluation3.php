<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '2-3';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม
    for ($i = 11; $i <= 15; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 11-15)
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
  <title>แบบประเมิน ช่วงอายุ 3 ถึง 4 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 3 - 4 เดือน
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

    <form method="POST" action="" autocomplete="off">
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
            <!-- ข้อ 11-15 สำหรับ 3-4 เดือน -->
            <tr>
              <td rowspan="5">3 - 4 เดือน</td>
              <td>11<br>
                  <input type="checkbox" id="q11_pass" name="q11_pass" value="1">
                  <label for="q11_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q11_fail" name="q11_fail" value="1">
                  <label for="q11_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ท่านอนคว่ำยกศีรษะและอกพ้นพื้น (GM)<br><br>
                <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>

              <td>
                1. จัดให้เด็กอยู่ในท่านอนคว่ำบนพื้นราบ<br>
                2. เขย่ากรุ๊งกริ๊งด้านหน้าเด็กเพื่อให้เด็กสนใจ แล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม<br>
                <strong>ผ่าน:</strong> เด็กยกศีรษะและอกโดยใช้แขนยันกับพื้นพยุงตัวไว้อย่างน้อย 5 วินาที
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนคว่ำ ข้อศอกงอ<br>
                2. ใช้หน้าและเสียงของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กพูดคุยกับเด็กตรงหน้าเด็ก เมื่อเด็กมองตามค่อย ๆ เคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กขึ้นด้านบนเพื่อให้เด็กสนใจยกศีรษะ โดยมือยันพื้นไว้แขนเหยียดตรงและหน้าอกพ้นพื้น<br>
                3. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                <strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น
              </td>
            </tr>

            <tr>
              <td>12<br>
                  <input type="checkbox" id="q12_pass" name="q12_pass" value="1">
                  <label for="q12_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q12_fail" name="q12_fail" value="1">
                  <label for="q12_fail">ไม่ผ่าน</label><br>
              </td>
              <td>มองตามสิ่งของที่เคลื่อนที่ได้เป็นมุม 180 องศา (FM)<br><br>
                <strong>อุปกรณ์:</strong> ลูกบอลผ้าสีแดง
                <img src="../image/Screenshot 2025-07-06 114822.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย<br>
                2. ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็กประมาณ 30 ซม.<br>
                3. กระตุ้นให้เด็กมองที่ลูกบอลผ้าสีแดง<br>
                4. เคลื่อนลูกบอลผ้าสีแดงเป็นแนวโค้งไปทางด้านขวาหรือด้านซ้ายของเด็กอย่างช้า ๆ แล้วเคลื่อนกลับมาทางด้านตรงข้ามให้โอกาสประเมิน 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กมองตามลูกบอลผ้าสีแดงได้ 180 องศา อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. จัดเด็กอยู่ในท่านอนหงายโดยศีรษะเด็กอยู่ในแนวกึ่งกลางลำตัว<br>
                2. ก้มหน้าให้อยู่ใกล้ ๆ เด็ก ห่างจากหน้าเด็กประมาณ 30 ซม.(1 ไม้บรรทัด)<br>
                3. เรียกชื่อเด็กเพื่อกระตุ้นเด็กให้สนใจจ้องมอง จากนั้นเคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กอย่างช้า ๆ เป็นแนวโค้งไปทางด้านซ้าย<br>
                4. ทำซ้ำโดยเปลี่ยนเป็นเคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กจากทางด้านซ้ายไปด้านขวา<br>
                5. ถ้าเด็กยังไม่มองตาม ให้ช่วยประคองหน้าเด็กเพื่อให้หันหน้ามามองตาม<br>
                6. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                <strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีสดใส เส้นผ่านศูนย์กลางประมาณ 10 ซม. เช่น ผ้า/ลูกบอลผ้า พู่ไหมพรม
              </td>
            </tr>

            <tr>
              <td>13<br>
                  <input type="checkbox" id="q13_pass" name="q13_pass" value="1">
                  <label for="q13_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q13_fail" name="q13_fail" value="1">
                  <label for="q13_fail">ไม่ผ่าน</label><br>
              </td>
              <td>หันตามเสียงได้ (RL)<br><br>
                <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง
                <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก หันหน้าออกจากผู้ประเมิน<br>
                2. เขย่ากรุ๊งกริ๊งด้านข้างหูข้างหนึ่งประมาณ 60 ซม. และไม่ให้เด็กเห็น<br>
                <strong>ผ่าน:</strong> เด็กหันตามเสียงและมองหาที่กรุ๊งกริ๊งได้
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย หรืออุ้มเด็กนั่งบนตักโดยหันหน้าออกจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก <br>
                2. เขย่าของเล่นให้เด็กดู<br>
                3. เขย่าของเล่นทางด้านข้าง ห่างจากเด็กประมาณ 30 – 45 ซม. และรอให้เด็กหันมาทางของเล่นที่มีเสียง<br>
                4. จากนั้นให้พูดคุยและยิ้มกับเด็ก ถ้าเด็กไม่หันมามองของเล่นให้ประคองหน้าเด็กเพื่อให้หันตามเสียงค่อย ๆ เพิ่มระยะห่างจนถึง 60 ซม.<br>
                <strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น
              </td>
            </tr>

            <tr>
              <td>14<br>
                  <input type="checkbox" id="q14_pass" name="q14_pass" value="1">
                  <label for="q14_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q14_fail" name="q14_fail" value="1">
                  <label for="q14_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำเสียงสูง ๆ ต่ำ ๆ เพื่อแสดงความรู้สึก (EL)</td>
              <td>
                ในระหว่างที่ประเมิน สังเกตว่าเด็กส่งเสียงสูง ๆ ต่ำ ๆ ได้หรือไม่ หรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก<br>
                <strong>ผ่าน:</strong> เด็กส่งเสียงสูง ๆ ต่ำ ๆ เพื่อแสดงความรู้สึกอย่างน้อย 2 ความรู้สึก
              </td>
              <td>
                มองสบตาเด็ก และพูดด้วยเสียงสูง ๆ ต่ำ ๆ เล่นหัวเราะกับเด็ก หรือสัมผัสจุดต่าง ๆ ของร่างกายเด็ก เช่น ใช้นิ้วสัมผัสเบา ๆ ที่ฝ่าเท้า ท้อง
                เอว หรือใช้จมูกสัมผัสหน้าผาก แก้ม จมูก ปากและท้องเด็ก โดยการสัมผัสแต่ละครั้งควรมีจังหวะหนักเบาแตกต่างกันไป
              </td>
            </tr>

            <tr>
              <td>15<br>
                  <input type="checkbox" id="q15_pass" name="q15_pass" value="1">
                  <label for="q15_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q15_fail" name="q15_fail" value="1">
                  <label for="q15_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยิ้มทักคนที่คุ้นเคย (PS)</td>
              <td>
                สังเกตขณะอยู่กับเด็กหรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กยิ้มทักคนที่คุ้นเคยก่อนได้หรือไม่"<br>
                <strong>ผ่าน:</strong> เด็กยิ้มทักพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก หรือคนที่คุ้นเคยก่อนได้
              </td>
              <td>
                1. ยิ้มและพูดคุยกับเด็กเมื่อทำกิจกรรมต่าง ๆ ให้เด็กทุกครั้งเป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล<br>
                2. อุ้มเด็กไปหาคนที่คุ้นเคย เช่น ปู่ ย่า ตา ยาย พ่อแม่ ผู้ปกครองยิ้มทักคนที่คุ้นเคยให้เด็กดู<br>
                3. พูดกระตุ้นให้เด็กทำตาม เช่น "ยิ้มให้คุณพ่อซิลูก" "ยิ้มให้.....ซิลูก"
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
        <!-- Card ข้อที่ 11 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 11 - ท่านอนคว่ำยกศีรษะและอกพ้นพื้น (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 3 - 4 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q11_pass_mobile" name="q11_pass" value="1">
                <label class="form-check-label text-success" for="q11_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q11_fail_mobile" name="q11_fail" value="1">
                <label class="form-check-label text-danger" for="q11_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนคว่ำบนพื้นราบ<br>
              2. เขย่ากรุ๊งกริ๊งด้านหน้าเด็กเพื่อให้เด็กสนใจ แล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม<br>
              <strong>ผ่าน:</strong> เด็กยกศีรษะและอกโดยใช้แขนยันกับพื้นพยุงตัวไว้อย่างน้อย 5 วินาที</p>
            </div>
            <div class="accordion" id="training11">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading11">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse11">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse11" class="accordion-collapse collapse" data-bs-parent="#training11">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนคว่ำ ข้อศอกงอ<br>
                    2. ใช้หน้าและเสียงของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กพูดคุยกับเด็กตรงหน้าเด็ก เมื่อเด็กมองตามค่อย ๆ เคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กขึ้นด้านบนเพื่อให้เด็กสนใจยกศีรษะ โดยมือยันพื้นไว้แขนเหยียดตรงและหน้าอกพ้นพื้น<br>
                    3. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 12 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 12 - มองตามสิ่งของที่เคลื่อนที่ได้เป็นมุม 180 องศา (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 2 - 3 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q12_pass_mobile" name="q12_pass" value="1">
                <label class="form-check-label text-success" for="q12_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q12_fail_mobile" name="q12_fail" value="1">
                <label class="form-check-label text-danger" for="q12_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> ลูกบอลผ้าสีแดง
              <img src="../image/Screenshot 2025-07-06 114822.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนหงาย<br>
              2. ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็กประมาณ 30 ซม.<br>
              3. กระตุ้นให้เด็กมองที่ลูกบอลผ้าสีแดง<br>
              4. เคลื่อนลูกบอลผ้าสีแดงเป็นแนวโค้งไปทางด้านขวาหรือด้านซ้ายของเด็กอย่างช้า ๆ แล้วเคลื่อนกลับมาทางด้านตรงข้ามให้โอกาสประเมิน 3 ครั้ง<br>
              <strong>ผ่าน:</strong> เด็กมองตามลูกบอลผ้าสีแดงได้ 180 องศา อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training12">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading12">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse12">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse12" class="accordion-collapse collapse" data-bs-parent="#training12">
                  <div class="accordion-body">
                    1. จัดเด็กอยู่ในท่านอนหงายโดยศีรษะเด็กอยู่ในแนวกึ่งกลางลำตัว<br>
                    2. ก้มหน้าให้อยู่ใกล้ ๆ เด็ก ห่างจากหน้าเด็กประมาณ 30 ซม.(1 ไม้บรรทัด)<br>
                    3. เรียกชื่อเด็กเพื่อกระตุ้นเด็กให้สนใจจ้องมอง จากนั้นเคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กอย่างช้า ๆ เป็นแนวโค้งไปทางด้านซ้าย<br>
                    4. ทำซ้ำโดยเปลี่ยนเป็นเคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กจากทางด้านซ้ายไปด้านขวา<br>
                    5. ถ้าเด็กยังไม่มองตาม ให้ช่วยประคองหน้าเด็กเพื่อให้หันหน้ามามองตาม<br>
                    6. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีสดใส เส้นผ่านศูนย์กลางประมาณ 10 ซม. เช่น ผ้า/ลูกบอลผ้า พู่ไหมพรม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 13 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 13 - หันตามเสียงได้ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 2 - 3 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q13_pass_mobile" name="q13_pass" value="1">
                <label class="form-check-label text-success" for="q13_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q13_fail_mobile" name="q13_fail" value="1">
                <label class="form-check-label text-danger" for="q13_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก หันหน้าออกจากผู้ประเมิน<br>
              2. เขย่ากรุ๊งกริ๊งด้านข้างหูข้างหนึ่งประมาณ 60 ซม. และไม่ให้เด็กเห็น<br>
              <strong>ผ่าน:</strong> เด็กหันตามเสียงและมองหาที่กรุ๊งกริ๊งได้</p>
            </div>
            <div class="accordion" id="training13">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading13">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse13">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse13" class="accordion-collapse collapse" data-bs-parent="#training13">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนหงาย หรืออุ้มเด็กนั่งบนตักโดยหันหน้าออกจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก <br>
                    2. เขย่าของเล่นให้เด็กดู<br>
                    3. เขย่าของเล่นทางด้านข้าง ห่างจากเด็กประมาณ 30 – 45 ซม. และรอให้เด็กหันมาทางของเล่นที่มีเสียง<br>
                    4. จากนั้นให้พูดคุยและยิ้มกับเด็ก ถ้าเด็กไม่หันมามองของเล่นให้ประคองหน้าเด็กเพื่อให้หันตามเสียงค่อย ๆ เพิ่มระยะห่างจนถึง 60 ซม.<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งท�ำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 14 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 14 - ทำเสียงสูง ๆ ต่ำ ๆ เพื่อแสดงความรู้สึก (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 2 - 3 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q14_pass_mobile" name="q14_pass" value="1">
                <label class="form-check-label text-success" for="q14_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q14_fail_mobile" name="q14_fail" value="1">
                <label class="form-check-label text-danger" for="q14_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ในระหว่างที่ประเมิน สังเกตว่าเด็กส่งเสียงสูง ๆ ต่ำ ๆ ได้หรือไม่ หรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก<br>
              <strong>ผ่าน:</strong> เด็กส่งเสียงสูง ๆ ต่ำ ๆ เพื่อแสดงความรู้สึกอย่างน้อย 2 ความรู้สึก</p>
            </div>
            <div class="accordion" id="training14">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading14">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse14">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse14" class="accordion-collapse collapse" data-bs-parent="#training14">
                  <div class="accordion-body">
                    มองสบตาเด็ก และพูดด้วยเสียงสูง ๆ ต่ำ ๆ เล่นหัวเราะกับเด็ก หรือสัมผัสจุดต่าง ๆ ของร่างกายเด็ก เช่น ใช้นิ้วสัมผัสเบา ๆ ที่ฝ่าเท้า ท้อง
                    เอว หรือใช้จมูกสัมผัสหน้าผาก แก้ม จมูก ปากและท้องเด็ก โดยการสัมผัสแต่ละครั้งควรมีจังหวะหนักเบาแตกต่างกันไป
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 15 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 15 - ยิ้มทักคนที่คุ้นเคย (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 2 - 3 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q15_pass_mobile" name="q15_pass" value="1">
                <label class="form-check-label text-success" for="q15_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q15_fail_mobile" name="q15_fail" value="1">
                <label class="form-check-label text-danger" for="q15_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตขณะอยู่กับเด็กหรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กยิ้มทักคนที่คุ้นเคยก่อนได้หรือไม่"<br>
              <strong>ผ่าน:</strong> เด็กยิ้มทักพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก หรือคนที่คุ้นเคยก่อนได้</p>
            </div>
            <div class="accordion" id="training15">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading15">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse15">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse15" class="accordion-collapse collapse" data-bs-parent="#training15">
                  <div class="accordion-body">
                    1. ยิ้มและพูดคุยกับเด็กเมื่อทำกิจกรรมต่าง ๆ ให้เด็กทุกครั้งเป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล<br>
                    2. อุ้มเด็กไปหาคนที่คุ้นเคย เช่น ปู่ ย่า ตา ยาย พ่อแม่ ผู้ปกครองยิ้มทักคนที่คุ้นเคยให้เด็กดู<br>
                    3. พูดกระตุ้นให้เด็กทำตาม เช่น "ยิ้มให้คุณพ่อซิลูก" "ยิ้มให้.....ซิลูก"
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
              <div id="modal-alert" class="alert alert-danger mt-3" style="display: none;"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
              <button type="button" id="confirmSubmitBtn" class="btn btn-primary">ยืนยัน</button>
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
      // ล้างสถานะ checkbox ที่เบราว์เซอร์อาจจะจำไว้ก่อนหน้า
      for (let i = 11; i <= 15; i++) {
        ['q' + i + '_pass', 'q' + i + '_fail', 'q' + i + '_pass_mobile', 'q' + i + '_fail_mobile'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.checked = false;
        });
      }

      // Desktop version (two-way sync with mobile; handle check and uncheck)
      for (let i = 11; i <= 15; i++) {
        const passCheckbox = document.getElementById(`q${i}_pass`);
        const failCheckbox = document.getElementById(`q${i}_fail`);
        const passCheckboxMobile = document.getElementById(`q${i}_pass_mobile`);
        const failCheckboxMobile = document.getElementById(`q${i}_fail_mobile`);

        if (passCheckbox && failCheckbox) {
          passCheckbox.addEventListener('change', function() {
            if (this.checked) {
              failCheckbox.checked = false;
              if (passCheckboxMobile) passCheckboxMobile.checked = true;
              if (failCheckboxMobile) failCheckboxMobile.checked = false;
            } else {
              if (passCheckboxMobile) passCheckboxMobile.checked = false;
            }
          });

          failCheckbox.addEventListener('change', function() {
            if (this.checked) {
              passCheckbox.checked = false;
              if (failCheckboxMobile) failCheckboxMobile.checked = true;
              if (passCheckboxMobile) passCheckboxMobile.checked = false;
            } else {
              if (failCheckboxMobile) failCheckboxMobile.checked = false;
            }
          });
        }
      }

      // Mobile version (two-way sync)
      for (let i = 11; i <= 15; i++) {
        const passCheckboxMobile = document.getElementById(`q${i}_pass_mobile`);
        const failCheckboxMobile = document.getElementById(`q${i}_fail_mobile`);
        const passCheckboxDesktop = document.getElementById(`q${i}_pass`);
        const failCheckboxDesktop = document.getElementById(`q${i}_fail`);

        if (passCheckboxMobile && failCheckboxMobile) {
          passCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              failCheckboxMobile.checked = false;
              if (passCheckboxDesktop) passCheckboxDesktop.checked = true;
              if (failCheckboxDesktop) failCheckboxDesktop.checked = false;
            } else {
              if (passCheckboxDesktop) passCheckboxDesktop.checked = false;
            }
          });

          failCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              passCheckboxMobile.checked = false;
              if (failCheckboxDesktop) failCheckboxDesktop.checked = true;
              if (passCheckboxDesktop) passCheckboxDesktop.checked = false;
            } else {
              if (failCheckboxDesktop) failCheckboxDesktop.checked = false;
            }
          });

          if (passCheckboxDesktop) {
            passCheckboxDesktop.addEventListener('change', function() {
              if (!this.checked && passCheckboxMobile) passCheckboxMobile.checked = false;
            });
          }

          if (failCheckboxDesktop) {
            failCheckboxDesktop.addEventListener('change', function() {
              if (!this.checked && failCheckboxMobile) failCheckboxMobile.checked = false;
            });
          }
        }
      }

      // แสดงสรุปผลเมื่อเปิด Modal (สรุปแค่ตอนกดยืนยัน)
      document.getElementById('confirmModal').addEventListener('show.bs.modal', function() {
        updateSummary();
        document.getElementById('evaluation-summary').style.display = 'block';
      });

      // If page is restored from bfcache, clear any persisted checkbox values
      function clearCheckboxes() {
        for (let i = 11; i <= 15; i++) {
          ['q' + i + '_pass', 'q' + i + '_fail', 'q' + i + '_pass_mobile', 'q' + i + '_fail_mobile'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = false;
          });
        }
      }

      window.addEventListener('pageshow', function(e) {
        clearCheckboxes();
      });

      // Modal alert and submit handling
      document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
        const missing = [];
        for (let i = 11; i <= 15; i++) {
          const passDesktop = document.getElementById(`q${i}_pass`);
          const failDesktop = document.getElementById(`q${i}_fail`);
          const passMobile = document.getElementById(`q${i}_pass_mobile`);
          const failMobile = document.getElementById(`q${i}_fail_mobile`);

          const hasAnswer = (passDesktop && passDesktop.checked) || (failDesktop && failDesktop.checked) || (passMobile && passMobile.checked) || (failMobile && failMobile.checked);
          if (!hasAnswer) missing.push(i);
        }

        const alertBox = document.getElementById('modal-alert');
        if (missing.length > 0) {
          alertBox.style.display = 'block';
          alertBox.textContent = 'กรุณาเลือก ผลการประเมิน (ผ่าน/ไม่ผ่าน) ให้ครบทุกข้อ ก่อนยืนยัน แบบยังไม่ครบ: ข้อที่ ' + missing.join(', ');
          document.getElementById('evaluation-summary').style.display = 'block';
          return;
        }

        alertBox.style.display = 'none';
        const frm = document.querySelector('form');
        if (frm) frm.submit();
      });
    });

    function updateSummary() {
      let passedCount = 0;
      let failedCount = 0;

      for (let i = 11; i <= 15; i++) {
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

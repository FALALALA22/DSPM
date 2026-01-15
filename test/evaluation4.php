<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '5-6';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 16-20)
    for ($i = 16; $i <= 20; $i++) {
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
    $total_questions = 5; // แบบประเมินช่วงอายุ 5-6 เดือน มีทั้งหมด 5 ข้อ (ข้อ 16-20)
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
  <title>แบบประเมิน ช่วงอายุ 5 ถึง 6 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 5 - 6 เดือน
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
              <td>5 - 6 เดือน</td>
              <td>16<br>
                  <input type="checkbox" id="q16_pass" name="q16_pass" value="1">
                  <label for="q16_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q16_fail" name="q16_fail" value="1">
                  <label for="q16_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยันตัวขึ้นจากท่านอนคว่ำโดยเหยียดแขนตรงทั้งสองข้างได้(GM)<br><br>
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              จัดให้เด็กอยู่ในท่านอนคว่ำ เขย่ากรุ๊งกริ๊งด้านหน้าเด็ก กระตุ้นให้เด็กมองสนใจแล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม<br>
              <strong>ผ่าน:</strong>  เด็กสามารถใช้ฝ่ามือทั้งสองข้างยันตัวขึ้นจนข้อศอกเหยียดตรง ท้องและหน้าอกต้องยกขึ้นพ้นพื้น
            </td>
            <td>
              1. จัดให้เด็กอยู่ในท่านอนคว่ำ <br>
              2. ถือของเล่นไว้ด้านหน้าเหนือศีรษะเด็ก<br>
              3. เรียกชื่อเด็กให้มองดูของเล่นแล้วเคลื่อนของเล่นให้สูงขึ้นเหนือศีรษะอย่างช้า ๆ เพื่อให้เด็กยกศีรษะตาม โดยมือยันพื้นไว้ แขนเหยียดตรงจนหน้าอกและท้องพ้นพื้น<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>17<br>
                  <input type="checkbox" id="q17_pass" name="q17_pass" value="1">
                  <label for="q17_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q17_fail" name="q17_fail" value="1">
                  <label for="q17_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เอื้อมมือหยิบ และถือวัตถุไว้ขณะอยู่ในท่านอนหงาย (FM)<br><br>
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              จัดให้เด็กอยู่ในท่านอนหงาย ถือกรุ๊งกริ๊งให้ห่างจากตัวเด็กประมาณ 20 - 30 ซม.ที่จุดกึ่งกลางลำตัวอาจกระตุ้นให้เด็กสนใจและหยิบกรุ๊งกริ๊งได้<br>
              <strong>ผ่าน:</strong>  เด็กสามารถเอื้อมมือข้างใดข้างหนึ่งไปหยิบและถือกรุ๊งกริ๊งได้ (อาจจะมีการเคลื่อนไหวแบบสะเปะสะปะเพื่อหยิบกรุ๊งกริ๊ง)
            </td>
            <td>
              1. จัดให้เด็กอยู่ในท่านอนหงาย เขย่าของเล่นให้ห่างจากตัวเด็กในระยะที่เด็กเอื้อมถึง<br>
              2. ถ้าเด็กไม่เอื้อมมือออกมาคว้าของเล่น ให้ใช้ของเล่นแตะเบา ๆที่หลังมือเด็ก หรือจับมือเด็กให้เอื้อมมาหยิบของเล่น ทำซ้ำจนเด็กสามารถเอื้อมมือมาหยิบของเล่นได้เอง<br>
              3. อาจแขวนโมบายในระยะที่เด็กเอื้อมถึง เพื่อให้เด็กสนใจคว้าหยิบ<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>18<br>
                  <input type="checkbox" id="q18_pass" name="q18_pass" value="1">
                  <label for="q18_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q18_fail" name="q18_fail" value="1">
                  <label for="q18_fail">ไม่ผ่าน</label><br>
              </td>
              <td>หันตามเสียงเรียก (RL)<br><br>
            </td>
            <td>
              อยู่ข้างหลังเด็กเยื้องไปทางซ้ายหรือขวาห่างจากเด็กประมาณ 20 - 30 ซม. ใช้มือป้องปากแล้วพูดคุยและเรียกเด็กหลาย ๆครั้ง และทำซ้ำโดยเยื้องไปอีกด้านตรงข้าม<br>
              <strong>ผ่าน:</strong>  เด็กหันตามเสียงเรียกหรือเสียงพูดคุย
            </td>
            <td>
              1. ให้เด็กนั่งบนเบาะนอน<br>
              2. พยุงอยู่ด้านหลังระยะห่างตัวเด็ก 30 ซม. พูดคุยกับเด็กด้วยเสียงปกติ รอให้เด็กหันมาทางทิศของเสียง ให้ยิ้มและเล่นกับเด็ก (ขณะฝึกอย่าให้มีเสียงอื่นรบกวน)<br>
              3. ถ้าเด็กไม่มองให้ประคองหน้าเด็กหันตามเสียงจนเด็กสามารถหันตามเสียงได้เอง ถ้าเด็กยังไม่หันให้พูดเสียงดังขึ้น เมื่อหันมองลดระดับเสียงลงจนเสียงปกติ<br>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>19<br>
                  <input type="checkbox" id="q19_pass" name="q19_pass" value="1">
                  <label for="q19_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q19_fail" name="q19_fail" value="1">
                  <label for="q19_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนแบบการเล่นทำเสียงได้(EL)<br><br>
            </td>
            <td>
              ใช้ปากทำเสียงให้เด็กดู เช่น จุ๊บ หรือ ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถขยับปากทำเสียง จุ๊บ ได้หรือไม่ <br>
              <strong>ผ่าน:</strong> เด็กสามารถเลียนแบบการเล่นทำเสียงตามได้
            </td>
            <td>
              1. สบตาและพูดคุยกับเด็ก ใช้ริมฝีปากทำเสียง เช่น “จุ๊บจุ๊บ” เดาะลิ้นหรือจับมือเด็กมาไว้ที่ปากแล้วขยับตีปากเบา ๆ กระตุ้นให้ออกเสียง“วา..วา” 
              ให้เด็กดูหลาย ๆ ครั้ง แล้วรอให้เด็กทำตาม จนเด็กสามารถเลียนแบบได้<br>
              2. ร้องเพลงง่าย ๆ ที่มีเสียงสูง ๆ ต่ำ ๆ ให้เด็กฟัง เช่น เพลงช้างเพลงเป็ด เป็นต้น
            </td>
          </tr>

            <tr>
              <td></td>
              <td>20<br>
                  <input type="checkbox" id="q20_pass" name="q20_pass" value="1">
                  <label for="q20_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q20_fail" name="q20_fail" value="1">
                  <label for="q20_fail">ไม่ผ่าน</label><br>
              </td>
              <td>สนใจฟังคนพูดและสามารถมองไปที่ของเล่นที่ผู้ทดสอบเล่นกับเด็ก (PS)<br><br>
                <strong>อุปกรณ์:</strong>ตุ๊กตาผ้า<br>
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              นั่งหันหน้าเข้าหาเด็กแล้วเรียกชื่อเด็กเมื่อเด็กมองแล้วหยิบตุ๊กตาผ้าให้เด็กเห็นในระดับสายตาเด็ก กระตุ้นให้เด็กสนใจตุ๊กตาผ้าด้วยคำพูด 
              ถ้าเด็กทำไม่ได้ในครั้งแรก ให้ทำซ้ำได้รวมไม่เกิน 3 ครั้ง<br>
              <strong>ผ่าน:</strong> เด็กสบตากับผู้ประเมิน และมองที่ตุ๊กตาผ้าได้นาน 5 วินาที อย่างน้อย 1 ใน 3 ครั้ง
            </td>
            <td>
              1. จัดเด็กนั่งหันหน้าเข้าหาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก<br>
              2. เรียกชื่อและสบตา พูดคุยกับเด็กเมื่อเด็กมองสบตาแล้ว นำของเล่นมาอยู่ในระดับสายตาเด็ก 
              พูดคุยกับเด็กเกี่ยวกับของเล่น เช่น“วันนี้แม่มีพี่ตุ๊กตามาเล่นกับหนู พี่ตุ๊กตามีผมสีน้ำตาลใส่ชุดสีเขียว”
                <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  ของเล่นใด ๆ ก็ได้ที่เด็กสนใจหรือคุ้นเคยที่มีในบ้าน เช่น ลูกบอล รถ หนังสือภาพ ตุ๊กตาอื่น ๆ </span>
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
        <!-- Card ข้อที่ 16 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 16 - ยันตัวขึ้นจากท่านอนคว่ำโดยเหยียดแขนตรงทั้งสองข้างได้(GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 5 - 6 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q16_pass_mobile" name="q16_pass" value="1">
                <label class="form-check-label text-success" for="q16_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q16_fail_mobile" name="q16_fail" value="1">
                <label class="form-check-label text-danger" for="q16_fail_mobile">
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
              <p class="text-muted">จัดให้เด็กอยู่ในท่านอนคว่ำ เขย่ากรุ๊งกริ๊งด้านหน้าเด็ก กระตุ้นให้เด็กมองสนใจแล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใช้ฝ่ามือทั้งสองข้างยันตัวขึ้นจนข้อศอกเหยียดตรง ท้องและหน้าอกต้องยกขึ้นพ้นพื้น</p>
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
                    1. จัดให้เด็กอยู่ในท่านอนคว่ำ <br>
              2. ถือของเล่นไว้ด้านหน้าเหนือศีรษะเด็ก<br>
              3. เรียกชื่อเด็กให้มองดูของเล่นแล้วเคลื่อนของเล่นให้สูงขึ้นเหนือศีรษะอย่างช้า ๆ เพื่อให้เด็กยกศีรษะตาม โดยมือยันพื้นไว้ แขนเหยียดตรงจนหน้าอกและท้องพ้นพื้น<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 17 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 17 - เอื้อมมือหยิบ และถือวัตถุไว้ขณะอยู่ในท่านอนหงาย (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 5 - 6 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q17_pass_mobile" name="q17_pass" value="1">
                <label class="form-check-label text-success" for="q17_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q17_fail_mobile" name="q17_fail" value="1">
                <label class="form-check-label text-danger" for="q17_fail_mobile">
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
              <p class="text-muted">จัดให้เด็กอยู่ในท่านอนหงาย ถือกรุ๊งกริ๊งให้ห่างจากตัวเด็กประมาณ 20 - 30 ซม.ที่จุดกึ่งกลางลำตัวอาจกระตุ้นให้เด็กสนใจและหยิบกรุ๊งกริ๊งได้</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเอื้อมมือข้างใดข้างหนึ่งไปหยิบและถือกรุ๊งกริ๊งได้ (อาจจะมีการเคลื่อนไหวแบบสะเปะสะปะเพื่อหยิบกรุ๊งกริ๊ง)</p>
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
                    1. จัดให้เด็กอยู่ในท่านอนหงาย เขย่าของเล่นให้ห่างจากตัวเด็กในระยะที่เด็กเอื้อมถึง<br>
                    2. ถ้าเด็กไม่เอื้อมมือออกมาคว้าของเล่น ให้ใช้ของเล่นแตะเบา ๆที่หลังมือเด็ก หรือจับมือเด็กให้เอื้อมมาหยิบของเล่น ทำซ้ำจนเด็กสามารถเอื้อมมือมาหยิบของเล่นได้เอง<br>
                    3. อาจแขวนโมบายในระยะที่เด็กเอื้อมถึง เพื่อให้เด็กสนใจคว้าหยิบ<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 18 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 18 - หันตามเสียงเรียก (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 5 - 6 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q18_pass_mobile" name="q18_pass" value="1">
                <label class="form-check-label text-success" for="q18_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q18_fail_mobile" name="q18_fail" value="1">
                <label class="form-check-label text-danger" for="q18_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">อยู่ข้างหลังเด็กเยื้องไปทางซ้ายหรือขวาห่างจากเด็กประมาณ 20 - 30 ซม. ใช้มือป้องปากแล้วพูดคุยและเรียกเด็กหลาย ๆครั้ง และทำซ้ำโดยเยื้องไปอีกด้านตรงข้าม</p>
              <p><strong>ผ่าน:</strong> เด็กหันตามเสียงเรียกหรือเสียงพูดคุย</p>
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
              1. ให้เด็กนั่งบนเบาะนอน<br>
              2. พยุงอยู่ด้านหลังระยะห่างตัวเด็ก 30 ซม. พูดคุยกับเด็กด้วยเสียงปกติ รอให้เด็กหันมาทางทิศของเสียง ให้ยิ้มและเล่นกับเด็ก (ขณะฝึกอย่าให้มีเสียงอื่นรบกวน)<br>
              3. ถ้าเด็กไม่มองให้ประคองหน้าเด็กหันตามเสียงจนเด็กสามารถหันตามเสียงได้เอง ถ้าเด็กยังไม่หันให้พูดเสียงดังขึ้น เมื่อหันมองลดระดับเสียงลงจนเสียงปกติ<br>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 19 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 19 - เลียนแบบการเล่นทำเสียงได้(EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 5 - 6 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q19_pass_mobile" name="q19_pass" value="1">
                <label class="form-check-label text-success" for="q19_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q19_fail_mobile" name="q19_fail" value="1">
                <label class="form-check-label text-danger" for="q19_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ใช้ปากทำเสียงให้เด็กดู เช่น จุ๊บ หรือ ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถขยับปากทำเสียง จุ๊บ ได้หรือไม่ </p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเลียนแบบการเล่นทำเสียงตามได้</p>
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
                    1. สบตาและพูดคุยกับเด็ก ใช้ริมฝีปากทำเสียง เช่น “จุ๊บจุ๊บ” เดาะลิ้นหรือจับมือเด็กมาไว้ที่ปากแล้วขยับตีปากเบา ๆ กระตุ้นให้ออกเสียง“วา..วา” 
                    ให้เด็กดูหลาย ๆ ครั้ง แล้วรอให้เด็กทำตาม จนเด็กสามารถเลียนแบบได้<br>
                    2. ร้องเพลงง่าย ๆ ที่มีเสียงสูง ๆ ต่ำ ๆ ให้เด็กฟัง เช่น เพลงช้างเพลงเป็ด เป็นต้น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 20 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 20 - สนใจฟังคนพูดและสามารถมองไปที่ของเล่นที่ผู้ทดสอบเล่นกับเด็ก (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 5 - 6 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q20_pass_mobile" name="q20_pass" value="1">
                <label class="form-check-label text-success" for="q20_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q20_fail_mobile" name="q20_fail" value="1">
                <label class="form-check-label text-danger" for="q20_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> ตุ๊กตาผ้า
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">นั่งหันหน้าเข้าหาเด็กแล้วเรียกชื่อเด็กเมื่อเด็กมองแล้วหยิบตุ๊กตาผ้าให้เด็กเห็นในระดับสายตาเด็ก กระตุ้นให้เด็กสนใจตุ๊กตาผ้าด้วยคำพูด 
                ถ้าเด็กทำไม่ได้ในครั้งแรก ให้ทำซ้ำได้รวมไม่เกิน 3 ครั้ง</p>
              <p class="text-muted"><strong>อุปกรณ์:</strong>ตุ๊กตาผ้า</p>
              <p><strong>ผ่าน:</strong> เด็กสบตากับผู้ประเมิน และมองที่ตุ๊กตาผ้าได้นาน 5 วินาที อย่างน้อย 1 ใน 3 ครั้ง</p>
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
                    1. จัดเด็กนั่งหันหน้าเข้าหาพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก<br>
                    2. เรียกชื่อและสบตา พูดคุยกับเด็กเมื่อเด็กมองสบตาแล้ว นำของเล่นมาอยู่ในระดับสายตาเด็ก 
                    พูดคุยกับเด็กเกี่ยวกับของเล่น เช่น“วันนี้แม่มีพี่ตุ๊กตามาเล่นกับหนู พี่ตุ๊กตามีผมสีน้ำตาลใส่ชุดสีเขียว”
                <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong>  ของเล่นใด ๆ ก็ได้ที่เด็กสนใจหรือคุ้นเคยที่มีในบ้าน เช่น ลูกบอล รถ หนังสือภาพ ตุ๊กตาอื่น ๆ </span>
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
      for (let i = 16; i <= 20; i++) {
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
      for (let i = 16; i <= 20; i++) {
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

      for (let i = 16; i <= 20; i++) {
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

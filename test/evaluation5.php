<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '7-8';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 21-26)
    for ($i = 21; $i <= 26; $i++) {
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
    $total_questions = 6; // แบบประเมินช่วงอายุ 3-4 เดือน มีทั้งหมด 6 ข้อ (ข้อ 21-26)
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
  <title>แบบประเมิน ช่วงอายุ 7 ถึง 8 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 7 - 8 เดือน
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
            <tr>
              <td>7 - 8 เดือน</td>
              <td>21<br>
                  <input type="checkbox" id="q21_pass" name="q21_pass" value="1">
                  <label for="q21_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q21_fail" name="q21_fail" value="1">
                  <label for="q21_fail">ไม่ผ่าน</label><br>
              </td>
              <td>นั่งได้มั่นคง และเอี้ยวตัวใช้มือเล่นได้อย่างอิสระ (sit stable)(GM)<br><br>
              <strong>อุปกรณ์:</strong> กรุ้งกริ้ง<br>
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              1. จัดเด็กอยู่ในท่านั่ง<br>
              2. วางกรุ๊งกริ๊งไว้ด้านข้างเยื้องไปทางด้านหลัง กระตุ้นให้เด็กสนใจหยิบกรุ๊งกริ๊ง<br>
              <strong>ผ่าน:</strong> เด็กสามารถนั่งได้มั่นคง และเอี้ยวตัวหรือหมุนตัวไปหยิบกรุ๊งกริ๊งแล้วกลับมานั่งตัวตรงอีก
            </td>
            <td>
              1. จัดเด็กอยู่ในท่านั่ง วางของเล่นไว้ที่พื้นทางด้านข้างเยื้องไปด้านหลังของเด็กในระยะที่เด็กเอื้อมถึง<br>
              2. เรียกชื่อเด็กให้สนใจของเล่นเพื่อจะได้เอี้ยวตัวไปหยิบของเล่นถ้าเด็กทำไม่ได้ให้เลื่อนของเล่นใกล้ตัวเด็กอีกเล็กน้อย แล้วช่วยจับแขนเด็กให้เอี้ยวตัวไปหยิบของเล่นนั้น ทำอีกข้างสลับกันไป จนเด็กหยิบได้เอง<br>
              <span style="color: red;"><strong>อุปกรณ์ที่ใช้ได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น </span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>22<br>
                  <input type="checkbox" id="q22_pass" name="q22_pass" value="1">
                  <label for="q22_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q22_fail" name="q22_fail" value="1">
                  <label for="q22_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนเกาะ เครื่องเรือนสูงระดับอกได้ (GM)<br><br>
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง<br>
              <img src="../image/evaluation_pic/6.กรุ้งกริ้ง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              1. วางกรุ๊งกริ๊งไว้บนเครื่องเรือน เช่น โต๊ะหรือเก้าอี้<br>
              2. จัดเด็กยืนเกาะเครื่องเรือน<br>
              3. กระตุ้นให้เด็กสนใจที่กรุ๊งกริ๊ง<br>
              <strong>ผ่าน:</strong> เด็กสามารถยืนเกาะเครื่องเรือนได้เอง อย่างน้อย 10 วินาที โดยใช้แขนพยุงตัวไว้และสามารถขยับขาได้
            </td>
            <td>
              1. จัดเด็กให้ยืนเกาะพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก/เครื่องเรือน<br>
              2. จับที่สะโพกเด็กก่อน ต่อมาเปลี่ยนจับที่เข่า แล้วจึงจับมือเด็กเกาะที่เครื่องเรือน<br>
              3. เมื่อเด็กเริ่มทำได้ ให้เด็กยืนเกาะเครื่องเรือนเอง โดยไม่ใช้หน้าอกพิง หรือท้าวแขนเพื่อพยุงตัว<br>
              4. คอยอยู่ใกล้ ๆ เด็ก อาจเปิดเพลงแล้ว กระตุ้นให้เต้นตามจังหวะเพลง<br>
              <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น </span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>23<br>
                  <input type="checkbox" id="q23_pass" name="q23_pass" value="1">
                  <label for="q23_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q23_fail" name="q23_fail" value="1">
                  <label for="q23_fail">ไม่ผ่าน</label><br>
              </td>
              <td>จ้องมองไปที่หนังสือพร้อมกับผู้ใหญ่นาน 2 - 3 วินาที (FM)<br><br>
              <strong>อุปกรณ์:</strong> หนังสือรูปภาพ<br>
              <img src="../image/evaluation_pic/หนังสือรูปภาพ.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              จัดเด็กนั่งบนตัก ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กเปิดหนังสือ ชี้ชวนและพูดกับเด็กเกี่ยวกับรูปภาพนั้น ๆ<br>
              <strong>ผ่าน:</strong> : เด็กจ้องมองที่รูปภาพขณะที่พ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กพูดด้วยเป็นเวลา 2 - 3 วินาที
            </td>
            <td>
              1. อุ้มเด็กนั่งบนตัก เปิดหนังสือพร้อมกับพูดคุย ชี้ชวนให้เด็กดูรูปภาพในหนังสือ เพื่อกระตุ้นพัฒนาการทางอารมณ์โดยการอ่านหนังสือร่วมกันกับเด็ก<br>
              2. หากเด็กยังไม่มอง ให้ประคองหน้าเด็กให้มองที่รูปภาพในหนังสือ <br>
              <span style="color: red;"><strong>หนังสือที่ใช้:</strong> ควรเป็นหนังสือเด็กที่มีรูปภาพชัดเจน และมีเรื่องราวที่ส่งเสริมคุณธรรม</span>
            </td>
          </tr>

            <tr>
              <td></td>
              <td>24<br>
                  <input type="checkbox" id="q24_pass" name="q24_pass" value="1">
                  <label for="q24_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q24_fail" name="q24_fail" value="1">
                  <label for="q24_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กหันตามเสียงเรียกชื่อ (RL)<br><br>
            </td>
            <td>
              1. ให้เด็กเล่นอย่างอิสระ ผู้ประเมินอยู่ห่างจากเด็ก<br>
              2. ประมาณ 120 ซม. แล้วเรียกชื่อเด็กด้วยเสียงปกติ<br>
              <strong>ผ่าน:</strong> เด็กสามารถตอบสนองโดยหันมามองผู้ประเมิน
            </td>
            <td>
              เรียกชื่อเด็กด้วยน้ำเสียงปกติบ่อย ๆ ในระยะห่างประมาณ 120 ซม.
              ควรเป็นชื่อที่ใช้เรียกเด็กเป็นประจำ ถ้าเด็กไม่หัน เมื่อเรียกชื่อแล้วให้ประคองหน้าเด็กให้หันมามองจนเด็กสามารถทำได้เอง
            </td>
          </tr>

            <tr>
              <td></td>
              <td>25<br>
                  <input type="checkbox" id="q25_pass" name="q25_pass" value="1">
                  <label for="q25_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q25_fail" name="q25_fail" value="1">
                  <label for="q25_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนเสียงพูดคุย (EL)<br><br>
            </td>
            <td>
              ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กหรือสังเกตระหว่างประเมินว่าเด็กทำเสียงเลียนเสียงพูดหรือไม่<br>
              <strong>ผ่าน:</strong> เด็กเลียนเสียงที่แตกต่างกันอย่างน้อย 2 เสียง เช่น จา มา ปา ดา อู ตา
            </td>
            <td>
              พูดคุย เล่นกับเด็ก และออกเสียงใหม่ ๆ ให้เด็กเลียนเสียงตาม เช่นจา มา ปา ดา อู ตา หรือออกเสียงตามทำนองเพลง หรือร้องเพลงเช่น เพลงจับปูดำ 
            </td>
          </tr>

          <tr>
              <td></td>
              <td>26<br>
                  <input type="checkbox" id="q26_pass" name="q26_pass" value="1">
                  <label for="q26_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q26_fail" name="q26_fail" value="1">
                  <label for="q26_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กเล่นจ๊ะเอ๋ได้และมองหาหน้าของผู้เล่นได้ถูกทิศทาง (PS)<br><br>
              <strong>อุปกรณ์:</strong> ผ้าขนาด 30x30 ซม.
              มีรูอยู่ตรงกลาง<br>
              <img src="../image/evaluation_pic/ผ้ามีรูอยู่ตรงกลาง.png" alt="Family" style="width: 90px; height: 90px;"><br></td>
            </td>
            <td>
              1. ให้เด็กมองผู้ประเมิน<br>
              2. ใช้ผ้าที่เตรียมไว้บังหน้าตนเอง<br>
              3. โผล่หน้าด้านเดียวกัน 2 ครั้งพร้อมกับพูด “จ๊ะเอ๋” ครั้งที่ 3 ไม่โผล่หน้าแต่ให้พูดคำว่า “จ๊ะเอ๋” แล้วให้ผู้ประเมินมองผ่านรูผ้าว่าเด็กจ้องมองด้านที่ผู้ประเมินเคยโผล่หน้าได้หรือไม่<br>
              <strong>ผ่าน:</strong> เด็กจ้องมองตรงที่ผู้ประเมินโผล่หน้าออกไป หรือเด็กรู้จักซ่อนหน้าและเล่นจ๊ะเอ๋กับผู้ประเมิน
            </td>
            <td>
              1. เล่น “จ๊ะเอ๋” กับเด็กบ่อยๆ โดยใช้มือปิดหน้าหลังจากนั้นเปลี่ยนเป็นใช้ผ้าปิดหน้าเล่น “จ๊ะเอ๋” กับเด็ก โดยการเล่นร่วมกันกับเด็กเป็นการเสริมสร้างความสัมพันธ์และการกระตุ้นพัฒนาการทางอารมณ์<br>
              2. ให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก ใช้ผ้าเช็ดหน้าหรือผ้าผืนเล็ก ๆบังหน้าไว้ โผล่หน้าออกมาจากผ้าเช็ดหน้าด้านใดด้านหนึ่งพร้อมกับพูดว่า “จ๊ะเอ๋” หยุดรอจังหวะเพื่อให้เด็กหันมามองหรือยิ้มเล่นโต้ตอบ
              ฝึกบ่อย ๆ จนกระทั่งเด็กสามารถยื่นโผล่หน้าร่วมเล่นจ๊ะเอ๋ได้<br>
              3. ซ่อนของเล่นไว้ใต้ผืนผ้าแล้วให้เด็กหาโดยเริ่มจากซ่อนไว้บางส่วนแล้วค่อยซ่อนทั้งชิ้น เพื่อฝึกทักษะการสังเกตและความจำ<br>
              <span style="color: red;"><strong>สิ่งที่ใช้แทนได้:</strong> ผ้าขนหนู ผ้าเช็ดตัว กระดาษ</span><br>
              <span style="color: green;"><strong>วัตถุประสงค์:</strong>  เพื่อฝึกการรอคอย ฝึกความจำ</span>
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
        <!-- Card ข้อที่ 21 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 21 - นั่งได้มั่นคง และเอี้ยวตัวใช้มือเล่นได้อย่างอิสระ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q21_pass_mobile" name="q21_pass" value="1">
                <label class="form-check-label text-success" for="q21_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q21_fail_mobile" name="q21_fail" value="1">
                <label class="form-check-label text-danger" for="q21_fail_mobile">
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
              <p class="text-muted">1. จัดเด็กอยู่ในท่านั่ง<br>
              2. วางกรุ๊งกริ๊งไว้ด้านข้างเยื้องไปทางด้านหลัง กระตุ้นให้เด็กสนใจหยิบกรุ๊งกริ๊ง</p><br>
              <p><strong>ผ่าน:</strong> เด็กสามารถนั่งได้มั่นคง และเอี้ยวตัวหรือหมุนตัวไปหยิบกรุ๊งกริ๊งแล้วกลับมานั่งตัวตรงอีก</p>
            </div>
            <div class="accordion" id="training21">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading21">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse21">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse21" class="accordion-collapse collapse" data-bs-parent="#training21">
                  <div class="accordion-body">
                    1. จัดเด็กอยู่ในท่านั่ง วางของเล่นไว้ที่พื้นทางด้านข้างเยื้องไปด้านหลังของเด็กในระยะที่เด็กเอื้อมถึง<br>
                    2. เรียกชื่อเด็กให้สนใจของเล่นเพื่อจะได้เอี้ยวตัวไปหยิบของเล่นถ้าเด็กทำไม่ได้ให้เลื่อนของเล่นใกล้ตัวเด็กอีกเล็กน้อย แล้วช่วยจับแขนเด็กให้เอี้ยวตัวไปหยิบของเล่นนั้น ทำอีกข้างสลับกันไป จนเด็กหยิบได้เอง<br>
                    <span style="color: red;"><strong>อุปกรณ์ที่ใช้ได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 22 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 22 - ยืนเกาะ เครื่องเรือนสูงระดับอกได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q22_pass_mobile" name="q22_pass" value="1">
                <label class="form-check-label text-success" for="q22_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q22_fail_mobile" name="q22_fail" value="1">
                <label class="form-check-label text-danger" for="q22_fail_mobile">
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
              <p class="text-muted">1. วางกรุ๊งกริ๊งไว้บนเครื่องเรือน เช่น โต๊ะหรือเก้าอี้<br>
              2. จัดเด็กยืนเกาะเครื่องเรือน<br>
              3. กระตุ้นให้เด็กสนใจที่กรุ๊งกริ๊ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถยืนเกาะเครื่องเรือนได้เอง อย่างน้อย 10 วินาที โดยใช้แขนพยุงตัวไว้และสามารถขยับขาได้</p>
            </div>
            <div class="accordion" id="training22">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading22">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse22">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse22" class="accordion-collapse collapse" data-bs-parent="#training22">
                  <div class="accordion-body">
                    1. จัดเด็กให้ยืนเกาะพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก/เครื่องเรือน<br>
                    2. จับที่สะโพกเด็กก่อน ต่อมาเปลี่ยนจับที่เข่า แล้วจึงจับมือเด็กเกาะที่เครื่องเรือน<br>
                    3. เมื่อเด็กเริ่มทำได้ ให้เด็กยืนเกาะเครื่องเรือนเอง โดยไม่ใช้หน้าอกพิง หรือท้าวแขนเพื่อพยุงตัว<br>
                    4. คอยอยู่ใกล้ ๆ เด็ก อาจเปิดเพลงแล้ว กระตุ้นให้เต้นตามจังหวะเพลง<br>
                    <span style="color: red;"><strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 23 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 23 - จ้องมองไปที่หนังสือพร้อมกับผู้ใหญ่นาน 2 - 3 วินาที (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q23_pass_mobile" name="q23_pass" value="1">
                <label class="form-check-label text-success" for="q23_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q23_fail_mobile" name="q23_fail" value="1">
                <label class="form-check-label text-danger" for="q23_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> หนังสือรูปภาพ
              <img src="../image/evaluation_pic/หนังสือรูปภาพ.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">จัดเด็กนั่งบนตัก ให้พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กเปิดหนังสือ ชี้ชวนและพูดกับเด็กเกี่ยวกับรูปภาพนั้น ๆ</p>
              <p><strong>ผ่าน:</strong> เด็กจ้องมองที่รูปภาพขณะที่พ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กพูดด้วยเป็นเวลา 2 - 3 วินาที</p>
            </div>
            <div class="accordion" id="training23">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading23">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse23">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse23" class="accordion-collapse collapse" data-bs-parent="#training23">
                  <div class="accordion-body">
                    1. อุ้มเด็กนั่งบนตัก เปิดหนังสือพร้อมกับพูดคุย ชี้ชวนให้เด็กดูรูปภาพในหนังสือ เพื่อกระตุ้นพัฒนาการทางอารมณ์โดยการอ่านหนังสือร่วมกันกับเด็ก<br>
                    2. หากเด็กยังไม่มอง ให้ประคองหน้าเด็กให้มองที่รูปภาพในหนังสือ<br>
                    <span style="color: red;"><strong>หนังสือที่ใช้:</strong> ควรเป็นหนังสือเด็กที่มีรูปภาพชัดเจน และมีเรื่องราวที่ส่งเสริมคุณธรรม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 24 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 24 - เด็กหันตามเสียงเรียกชื่อ (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q24_pass_mobile" name="q24_pass" value="1">
                <label class="form-check-label text-success" for="q24_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q24_fail_mobile" name="q24_fail" value="1">
                <label class="form-check-label text-danger" for="q24_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ให้เด็กเล่นอย่างอิสระ ผู้ประเมินอยู่ห่างจากเด็ก<br>
              2. ประมาณ 120 ซม. แล้วเรียกชื่อเด็กด้วยเสียงปกติ</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตอบสนองโดยหันมามองผู้ประเมิน</p>
            </div>
            <div class="accordion" id="training24">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading24">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse24">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse24" class="accordion-collapse collapse" data-bs-parent="#training24">
                  <div class="accordion-body">
                    เรียกชื่อเด็กด้วยน้ำเสียงปกติบ่อย ๆ ในระยะห่างประมาณ 120 ซม.
                    ควรเป็นชื่อที่ใช้เรียกเด็กเป็นประจำ ถ้าเด็กไม่หัน เมื่อเรียกชื่อแล้วให้ประคองหน้าเด็กให้หันมามองจนเด็กสามารถทำได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 25 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 25 - เลียนเสียงพูดคุย (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q25_pass_mobile" name="q25_pass" value="1">
                <label class="form-check-label text-success" for="q25_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q25_fail_mobile" name="q25_fail" value="1">
                <label class="form-check-label text-danger" for="q25_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กหรือสังเกตระหว่างประเมินว่าเด็กทำเสียงเลียนเสียงพูดหรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กเลียนเสียงที่แตกต่างกันอย่างน้อย 2 เสียง เช่น จา มา ปา ดา อู ตา</p>
            </div>
            <div class="accordion" id="training25">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading25">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse25">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse25" class="accordion-collapse collapse" data-bs-parent="#training25">
                  <div class="accordion-body">
                    พูดคุย เล่นกับเด็ก และออกเสียงใหม่ ๆ ให้เด็กเลียนเสียงตาม เช่นจา มา ปา ดา อู ตา หรือออกเสียงตามทำนองเพลง หรือร้องเพลงเช่น เพลงจับปูดำ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 26 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 26 - เด็กเล่นจ๊ะเอ๋ได้และมองหาหน้าของผู้เล่นได้ถูกทิศทาง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 7 - 8 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q26_pass_mobile" name="q26_pass" value="1">
                <label class="form-check-label text-success" for="q26_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q26_fail_mobile" name="q26_fail" value="1">
                <label class="form-check-label text-danger" for="q26_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> ผ้าขนาด 30x30 ซม. มีรูอยู่ตรงกลาง
              <img src="../image/evaluation_pic/ผ้ามีรูอยู่ตรงกลาง.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ให้เด็กมองผู้ประเมิน<br>
              2. ใช้ผ้าที่เตรียมไว้บังหน้าตนเอง<br>
              3. โผล่หน้าด้านเดียวกัน 2 ครั้งพร้อมกับพูด "จ๊ะเอ๋" ครั้งที่ 3 ไม่โผล่หน้าแต่ให้พูดคำว่า "จ๊ะเอ๋" แล้วให้ผู้ประเมินมองผ่านรูผ้าว่าเด็กจ้องมองด้านที่ผู้ประเมินเคยโผล่หน้าได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กจ้องมองตรงที่ผู้ประเมินโผล่หน้าออกไป หรือเด็กรู้จักซ่อนหน้าและเล่นจ๊ะเอ๋กับผู้ประเมิน</p>
            </div>
            <div class="accordion" id="training26">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading26">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse26">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse26" class="accordion-collapse collapse" data-bs-parent="#training26">
                  <div class="accordion-body">
                    1. เล่น "จ๊ะเอ๋" กับเด็กบ่อยๆ โดยใช้มือปิดหน้าหลังจากนั้นเปลี่ยนเป็นใช้ผ้าปิดหน้าเล่น "จ๊ะเอ๋" กับเด็ก โดยการเล่นร่วมกันกับเด็กเป็นการเสริมสร้างความสัมพันธ์และการกระตุ้นพัฒนาการทางอารมณ์<br>
                    2. ให้พ่อแม่ ผู้ปกครอง หรือผู้ดูแลเด็ก ใช้ผ้าเช็ดหน้าหรือผ้าผืนเล็ก ๆบังหน้าไว้ โผล่หน้าออกมาจากผ้าเช็ดหน้าด้านใดด้านหนึ่งพร้อมกับพูดว่า "จ๊ะเอ๋" หยุดรอจังหวะเพื่อให้เด็กหันมามองหรือยิ้มเล่นโต้ตอบ
                    ฝึกบ่อย ๆ จนกระทั่งเด็กสามารถยื่นโผล่หน้าร่วมเล่นจ๊ะเอ๋ได้<br>
                    3. ซ่อนของเล่นไว้ใต้ผืนผ้าแล้วให้เด็กหาโดยเริ่มจากซ่อนไว้บางส่วนแล้วค่อยซ่อนทั้งชิ้น เพื่อฝึกทักษะการสังเกตและความจำ<br>
                    <span style="color: red;"><strong>สิ่งที่ใช้แทนได้:</strong> ผ้าขนหนู ผ้าเช็ดตัว กระดาษ</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกการรอคอย ฝึกความจำ</span>
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
      for (let i = 21; i <= 26; i++) {
        ['q' + i + '_pass', 'q' + i + '_fail', 'q' + i + '_pass_mobile', 'q' + i + '_fail_mobile'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.checked = false;
        });
      }

      for (let i = 21; i <= 26; i++) {
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

      for (let i = 21; i <= 26; i++) {
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

      document.getElementById('confirmModal').addEventListener('show.bs.modal', function() {
        updateSummary();
        document.getElementById('evaluation-summary').style.display = 'block';
      });

      function clearCheckboxes() {
        for (let i = 21; i <= 26; i++) {
          ['q' + i + '_pass', 'q' + i + '_fail', 'q' + i + '_pass_mobile', 'q' + i + '_fail_mobile'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = false;
          });
        }
      }

      window.addEventListener('pageshow', function(e) {
        clearCheckboxes();
      });

      document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
        const missing = [];
        for (let i = 21; i <= 26; i++) {
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

      for (let i = 21; i <= 26; i++) {
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

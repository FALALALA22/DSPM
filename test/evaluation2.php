<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '1-2';

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
    for ($i = 6; $i <= 10; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 6-10)
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
  <title>แบบประเมิน ช่วงอายุ 1 ถึง 2 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 1 - 2 เดือน
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
            <!-- ข้อ 6-10 สำหรับ 1-2 เดือน -->
            <tr>
              <td rowspan="5">1 - 2 เดือน</td>
              <td>6<br>
                  <input type="checkbox" id="q6_pass" name="q6_pass" value="1">
                  <label for="q6_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q6_fail" name="q6_fail" value="1">
                  <label for="q6_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ท่านอนคว่ำ ยกศีรษะตั้งขึ้นได้ 45 องศา นาน 3 วินาที (GM)<br>
              <strong>อุปกรณ์:</strong>กรุ๊งกริ๊ง</td>
              <td>
                จัดให้เด็กอยู่ในท่านอนคว่ำ ข้อศอกงอ มือทั้งสองข้างวางที่พื้น <br>
                เขย่ากรุ๊งกริ๊งด้านหน้าเด็ก แล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม<br>
                เพื่อให้เด็กหันศีรษะมองตาม ถ้าเด็กทำไม่ได้ให้ประคองศีรษะเด็กให้หันตาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถยกศีรษะขึ้นได้ 45 องศา นาน 3 วินาที อย่างน้อย 2 ครั้ง
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนคว่ำ ข้อศอกงอ <br>
                2. ใช้หน้าและเสียงของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กพูดคุยกับเด็กตรงหน้าเด็ก เมื่อเด็กมองตามค่อย ๆ เคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กขึ้นด้านบนเพื่อให้เด็กเงยหน้าจนศีรษะยกขึ้น นับ 1, 2<br>
                3. เคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กให้สูงขึ้น จนเด็กยกศีรษะตามได้ในแนว 45 องศา และนับ 1, 2, 3<br>
                4. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตา<br>
                <strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทรายพันให้แน่น
              </td>
            </tr>

            <tr>
              <td>7<br>
                  <input type="checkbox" id="q7_pass" name="q7_pass" value="1">
                  <label for="q7_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q7_fail" name="q7_fail" value="1">
                  <label for="q7_fail">ไม่ผ่าน</label><br>
              </td>
              <td>มองตามผ่านกึ่งกลางลำตัว(FM)<br>
               <img src="../image/Screenshot 2025-07-14 165848.png" alt="Family" style="width: 90px; height: 90px;"><br>
               อุปกรณ์: ลูกบอลผ้าสีแดง<br>
               <img src="../image/Screenshot 2025-07-06 114822.png" alt="Family" style="width: 90px; height: 90px;"><br>   
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็ก 30 ซม. โดยให้อยู่เยื้องจุดกึ่งกลางลำตัวเด็กเล็กน้อย<br>
                2. กระตุ้นให้เด็กจ้องมองที่ลูกบอลผ้าสีแดง<br>
                3. เคลื่อนลูกบอลผ้าสีแดงผ่านจุดกึ่งกลางลำตัวเด็กไปอีกด้านหนึ่งอย่างช้า ๆ<br>
                <strong>ผ่าน:</strong> เด็กมองตามลูกบอลผ้าสีแดง ผ่านจุดกึ่งกลางลำตัวได้ตลอด โดยไม่หันไปมองทางอื่น
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงาย ยื่นใบหน้าห่างจากหน้าเด็กประมาณ20 ซม. กระตุ้นให้เด็กมองหน้าและเคลื่อนใบหน้าผ่านกึ่งกลางลำตัวเพื่อให้เด็กมองตาม<br>
                2. ถ้าเด็กไม่มองให้ประคองหน้าเด็กให้หันมองตาม หลังจากที่เด็กสามารถมองตามใบหน้าได้แล้ว ให้ถือของเล่นที่มีสีสดใสห่างจากหน้าเด็กประมาณ 20 ซม. กระตุ้นให้เด็กมองที่ของเล่น และเคลื่อนของเล่นผ่านกึ่งกลางลำตัวเพื่อให้เด็กมองตาม<br>
                3. เมื่อเด็กมองตามได้ดีแล้ว ควรเพิ่มระยะห่างของใบหน้า หรือของเล่นจนถึงระยะ 30 ซม.<br>
                <strong>ของเล่นที่ใช้แทนได้:</strong>อุปกรณ์ที่มีสีสดใส เส้นผ่านศูนย์กลางประมาณ10 ซม. เช่น ผ้า/ลูกบอลผ้า พู่ไหมพรม
              </td>
            </tr>

            <tr>
              <td>8<br>
                  <input type="checkbox" id="q8_pass" name="q8_pass" value="1">
                  <label for="q8_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q8_fail" name="q8_fail" value="1">
                  <label for="q8_fail">ไม่ผ่าน</label><br>
              </td>
              <td>มองหน้าผู้พูดคุย ได้นาน 5 วินาที (RL)</td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็กให้ใบหน้าผู้ประเมินห่างจากเด็กประมาณ 30 ซม.<br>
                2. พูดคุยกับเด็กในขณะที่เด็กไม่ได้มองหน้าผู้ประเมิน<br>
                <strong>ผ่าน:</strong> เด็กหันมามองหน้าผู้พูดได้อย่างน้อย 5 วินาที
              </td>
              <td>
                1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก ให้หน้าห่างจากเด็กประมาณ 30 ซม.<br>
                2. สบตาและพูดคุยหรือทำท่าทางให้เด็กสนใจ เช่น ทำตาโตขยับ ริมฝีปาก ยิ้ม หัวเราะ<br>
                3. หยิบของเล่นสีสดใสมาใกล้ ๆ หน้า กระตุ้นให้เด็กมองของเล่นและมองหน้า เมื่อเด็กมองแล้วให้นำของเล่นออก
              </td>
            </tr>

            <tr>
              <td>9<br>
                  <input type="checkbox" id="q9_pass" name="q9_pass" value="1">
                  <label for="q9_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q9_fail" name="q9_fail" value="1">
                  <label for="q9_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำเสียงในลำคอ (เสียง "อู" หรือ "อา" หรือ "อือ") อย่างชัดเจน (EL)</td>
              <td>
                ฟังเสียงเด็กขณะประเมิน โดยอยู่ห่างจากเด็กประมาณ 60 ซม. หรือถามจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก ว่าเด็กทำเสียง"อู" หรือ "อือ" หรือ "อา" ได้หรือไม่<br>
                <strong>ผ่าน:</strong> เด็กทำเสียงอู หรือ อือ หรือ อา อย่างชัดเจน
              </td>
              <td>
                1. จัดเด็กอยู่ในท่านอนหงาย หรืออุ้มเด็ก<br>
                2. ยื่นหน้าเข้าไปหาเด็ก สบตาและพูดคุยให้เด็กสนใจ แล้วทำเสียง อู หรือ อือ หรือ อา ในลำคอให้เด็กได้ยิน หยุดฟังเพื่อรอจังหวะให้เด็กส่งเสียงตาม<br>
                3. เมื่อเด็กออกเสียงได้ ให้ยื่นหน้าห่างจากเด็กเพิ่มขึ้นจนถึงประมาณ 60 ซม
              </td>
            </tr>

            <tr>
              <td>10<br>
                  <input type="checkbox" id="q10_pass" name="q10_pass" value="1">
                  <label for="q10_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q10_fail" name="q10_fail" value="1">
                  <label for="q10_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยิ้มตอบหรือส่งเสียงตอบได้เมื่อพ่อแม่ ผู้ปกครองหรือผู้ประเมินยิ้มและพูดคุยด้วย(PS)</td>
              <td>
                จัดเด็กอยู่ในท่านอนหงายพร้อมก้มหน้าไปใกล้เด็ก ยิ้มและพูดคุยกับเด็ก โดยไม่แตะต้องตัวเด็ก<br>
                <strong>ผ่าน:</strong> เด็กสามารถยิ้มตอบหรือส่งเสียงตอบได้
              </td>
              <td>
                อุ้มเด็กอยู่ในท่านอนหงาย มองตาเด็กและสัมผัสเบา ๆ พร้อมกับพูดคุยกับเด็กเป็นคำพูดสั้น ๆ ซ้ำ ๆ ช้า ๆ เช่น "ว่าไงจ๊ะ.. (ชื่อลูก)..คนเก่ง" "ยิ้มซิ" "เด็กดี" ".. (ชื่อลูก)..ลูกรัก" "แม่รักลูกนะ" หยุดฟังเพื่อรอจังหวะให้เด็กยิ้มหรือส่งเสียงตอบ เป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล เพื่อกระตุ้นพัฒนาการทางอารมณ์
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
        <!-- Card ข้อที่ 6 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 6 - ท่านอนคว่ำ ยกศีรษะตั้งขึ้นได้ 45 องศา นาน 3 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 1 - 2 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q6_pass_mobile" name="q6_pass" value="1">
                <label class="form-check-label text-success" for="q6_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q6_fail_mobile" name="q6_fail" value="1">
                <label class="form-check-label text-danger" for="q6_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3 text-center">
              <strong>อุปกรณ์:</strong> กรุ๊งกริ๊ง
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. จัดให้เด็กอยู่ในท่านอนคว่ำบนพื้นราบ<br>
              2. เขย่ากรุ๊งกริ๊งด้านหน้าเด็กเพื่อให้เด็กสนใจ แล้วเคลื่อนขึ้นด้านบน กระตุ้นให้เด็กมองตาม<br>
              <strong>ผ่าน:</strong> เด็กยกศีรษะและอกโดยใช้แขนยันกับพื้นพยุงตัวไว้อย่างน้อย 5 วินาที</p>
            </div>
            <div class="accordion" id="training6">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading6">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse6">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse6" class="accordion-collapse collapse" data-bs-parent="#training6">
                  <div class="accordion-body">
                    1. จัดให้เด็กอยู่ในท่านอนคว่ำ ข้อศอกงอ<br>
                    2. ใช้หน้าและเสียงของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กพูดคุยกับเด็กตรงหน้าเด็ก เมื่อเด็กมองตามค่อย ๆ เคลื่อนหน้าพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กขึ้นด้านบนเพื่อให้เด็กสนใจยกศีรษะ โดยมือยันพื้นไว้แขนเหยียดตรงและหน้าอกพ้นพื้น<br>
                    3. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม<br>
                    <strong>ของเล่นที่ใช้แทนได้:</strong> อุปกรณ์ที่มีสีและเสียง เช่น กรุ๊งกริ๊งทำด้วยพลาสติก/ผ้า ลูกบอลยางบีบ/สัตว์ยางบีบ ขวดพลาสติกใส่เม็ดถั่ว/ทราย พันให้แน่น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 7 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 7 - ส่งเสียงเพื่อแสดงความต้องการ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 1 - 2 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q7_pass_mobile" name="q7_pass" value="1">
                <label class="form-check-label text-success" for="q7_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q7_fail_mobile" name="q7_fail" value="1">
                <label class="form-check-label text-danger" for="q7_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ในระหว่างที่ประเมิน สังเกตว่าเด็กส่งเสียงเพื่อแสดงความต้องการได้หรือไม่ หรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็ก</p>
              <p><strong>ผ่าน:</strong> เด็กส่งเสียงเพื่อแสดงความต้องการได้</p>
            </div>
            <div class="accordion" id="training7">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading7">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse7">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse7" class="accordion-collapse collapse" data-bs-parent="#training7">
                  <div class="accordion-body">
                    เมื่อเด็กมีเสียงส่ง ผู้ดูแลเด็กต้องตอบสนองโดยการดูแลปรับสิ่งที่เด็กต้องการให้ทันทีตามสถานการณ์ เช่น หิวนม ปัสสาวะอุจจาระ นอนอึดอัด หนาว ร้อน เป็นต้น และพูดให้เด็กฟัง เช่น "แม่รู้ว่าลูกหิวนม" "แม่รู้ว่าลูกอึดอัด" เป็นต้น
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 8 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 8 - ยิ้มให้คนที่สนใจ (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 1 - 2 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q8_pass_mobile" name="q8_pass" value="1">
                <label class="form-check-label text-success" for="q8_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q8_fail_mobile" name="q8_fail" value="1">
                <label class="form-check-label text-danger" for="q8_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตขณะอยู่กับเด็กหรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กยิ้มให้คนที่สนใจได้หรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กยิ้มให้คนที่สนใจได้</p>
            </div>
            <div class="accordion" id="training8">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading8">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse8">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse8" class="accordion-collapse collapse" data-bs-parent="#training8">
                  <div class="accordion-body">
                    1. ยิ้มและพูดคุยกับเด็กเมื่อทำกิจกรรมต่าง ๆ ให้เด็กทุกครั้งเป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล<br>
                    2. อุ้มเด็กไปพบคนต่าง ๆ ในครอบครัว เพื่อให้เด็กมีโอกาสได้เห็นหน้าและยิ้มให้คนต่าง ๆ<br>
                    3. พูดกระตุ้นให้เด็กทำตาม เช่น "ยิ้มให้คุณพ่อซิลูก" "ยิ้มให้.....ซิลูก"
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 9 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 9 - ชอบให้คนถือ อุ้ม (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 1 - 2 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q9_pass_mobile" name="q9_pass" value="1">
                <label class="form-check-label text-success" for="q9_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q9_fail_mobile" name="q9_fail" value="1">
                <label class="form-check-label text-danger" for="q9_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตขณะอยู่กับเด็กหรือถามพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กชอบให้คนถือ อุ้มหรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กชอบให้คนถือ อุ้ม</p>
            </div>
            <div class="accordion" id="training9">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading9">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse9">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse9" class="accordion-collapse collapse" data-bs-parent="#training9">
                  <div class="accordion-body">
                    อุ้มเด็กเมื่อเด็กร้องหรือเด็กต้องการ ไม่ควรปล่อยให้เด็กร้องนาน เพราะเด็กจะไม่มีความไว้วางใจ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 10 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 10 - นิ่งฟังเมื่อได้ยินเสียงเพลง (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 1 - 2 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q10_pass_mobile" name="q10_pass" value="1">
                <label class="form-check-label text-success" for="q10_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q10_fail_mobile" name="q10_fail" value="1">
                <label class="form-check-label text-danger" for="q10_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">เปิดเสียงเพลงให้เด็กฟัง หรือร้องเพลงให้เด็กฟัง โดยให้เด็กอยู่ในท่านอนหงาย</p>
              <p><strong>ผ่าน:</strong> เด็กนิ่งฟังเมื่อได้ยินเสียงเพลง</p>
            </div>
            <div class="accordion" id="training10">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading10">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse10">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse10" class="accordion-collapse collapse" data-bs-parent="#training10">
                  <div class="accordion-body">
                    เปิดเสียงเพลงให้เด็กฟังทุกวัน เปิดวันละหลายครั้ง และร้องเพลงให้เด็กฟัง
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
      for (let i = 6; i <= 10; i++) {
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
      for (let i = 6; i <= 10; i++) {
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

      for (let i = 6; i <= 10; i++) {
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

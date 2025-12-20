<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '37-41';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 84-89)
    for ($i = 84; $i <= 89; $i++) {
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
    $total_questions = 6; // แบบประเมินมีทั้งหมด 6 ข้อ (ข้อ 84-89)
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
  <title>แบบประเมิน ช่วงอายุ 37 ถึง 41 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 37 - 41 เดือน
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
            <!-- ข้อ 84-89 สำหรับ 37-41 เดือน -->
            <tr>
              <td rowspan="6">37 - 41 เดือน</td>
              <td>84<br>
                  <input type="checkbox" id="q84_pass" name="q84_pass" value="1">
                  <label for="q84_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q84_fail" name="q84_fail" value="1">
                  <label for="q84_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ยืนขาเดียว 3 วินาที (GM)<br><br>
              </td>
              <td>
                แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (อาจเปลี่ยนขาได้)<br>
                <strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 3 วินาทีอย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
               1. ยืนบนขาข้างเดียวให้เด็กดู<br>
               2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
               3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตาม เมื่อเด็กยืนได้เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
               4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
              </td>
            </tr>

            <tr>
              <td>85<br>
                  <input type="checkbox" id="q85_pass" name="q85_pass" value="1">
                  <label for="q85_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q85_fail" name="q85_fail" value="1">
                  <label for="q85_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนแบบวาดรูปวงกลม (FM)<br><br>
              <strong>อุปกรณ์:</strong>1. ดินสอ 2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;"><br>
                </td>
              <td>
                วาดรูปวงกลมขนาดเส้นผ่านศูนย์กลางประมาณ 5 ซม. ให้เด็กดูและพูดว่า“ครูวาดรูปวงกลม” และให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong>  เด็กสามารถวาดรูปวงกลมโดยไม่มีเหลี่ยม ไม่เว้า และเส้นที่มาเชื่อมต่อซ้อนกันไม่เกิน 2 ซม. ได้อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. สอนให้เด็กวาดวงกลมโดยเริ่มจากจุดที่กำหนดให้และกลับมาสิ้นสุดที่จุดกำหนดเดิม พร้อมออกเสียง โดยวาดเป็นวงกลมจากช้าไป
                เร็วพร้อมออกเสียง “วงกลม..หยุด”ยกมือขึ้นเพื่อให้เด็กเข้าใจคำว่า หยุด<br>
                2. จับมือเด็กวาดวงกลมและยกมือเด็กขึ้นเมื่อออกเสียง “วงกลม....หยุด” โดยยกมือขึ้น ให้ตรงกับคำว่า หยุด<br>
                3. เมื่อเด็กทำได้ดีขึ้นค่อย ๆ ลดการช่วยเหลือลง จนสามารถหยุดได้เองเมื่อลากเส้นมาถึงจุดสิ้นสุด
              </td>
            </tr>

            <tr>
              <td>86<br>
                  <input type="checkbox" id="q86_pass" name="q86_pass" value="1">
                  <label for="q86_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q86_fail" name="q86_fail" value="1">
                  <label for="q86_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำตามคำสั่งต่อเนื่องได้ 2 กริยากับวัตถุ 2 ชนิด (RL)<br><br>
              <strong>อุปกรณ์:</strong>ของเล่น เช่น หวี ตุ๊กตาผ้า บอล ช้อน<br>
              <img src="../image/evaluation_pic/หวี ตุ๊กตา บอล ช้อน.png" alt="Family" style="width: 150px; height: 100px;"><br>
            </td>
              <td>
                1. วางวัตถุ 4 ชนิด ตรงหน้าเด็กในระยะที่เด็กหยิบได้แล้วพูดกับเด็ก เช่น “หยิบหวีให้ครูแล้วชี้ตุ๊กตาซิ”<br>
                2. วางสลับวัตถุทั้ง 4 ชนิดใหม่<br>
                3. กรณีที่เด็กไม่ผ่านคำสั่งแรกให้เปลี่ยนเป็นคำสั่งอื่นได้อีก 2 คำสั่ง โดยอาจเปลี่ยนกริยาหรือวัตถุได้<br>
                <strong>ผ่าน:</strong>  เด็กสามารถทำตามคำสั่งต่อเนื่องได้อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. ฝึกเด็กในชีวิตประจำวัน โดยออกคำสั่ง เน้นคำที่เป็นชื่อสิ่งของและการกระทำ เช่น ขณะอาบน้ำ “เอาเสื้อใส่ตะกร้าแล้วหยิบ
                ผ้าเช็ดตัวมา” /ขณะแต่งตัว “ใส่กางเกงแล้วไปหวีผม” /ขณะรับประทานอาหาร “เก็บจานแล้วเอาผ้าไปเช็ดโต๊ะ”<br>
                2. ถ้าเด็กทำได้เพียงคำสั่งเดียว ให้ชี้ไปที่สิ่งของที่ไม่ได้ทำแล้วบอกซ้ำหรือพูดกระตุ้นเตือนความจำเด็ก เช่น “ต่อไปทำอะไรอีกนะ?” หรือ
                “เมื่อกี้บอกอะไรอีกนะ?” ฝึกซ้ำ ๆ จนเด็กสามารถทำตามคำสั่งได้ถูกต้อง<br>
                <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span><br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กทำตามสั่งได้อย่างถูกต้องเหมาะสมโดยอาศัยความเข้าใจผ่านการจดจำเรียนรู้</span>
              </td>
            </tr>

            <tr>
              <td>87<br>
                  <input type="checkbox" id="q87_pass" name="q87_pass" value="1">
                  <label for="q87_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q87_fail" name="q87_fail" value="1">
                  <label for="q87_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ถามคำถามได้ 4 แบบ เช่น ใคร อะไร ที่ไหน ทำไม (EL)<br><br>
              </td>
              <td>
                1. สังเกตขณะเด็กเล่นของเล่นหรือประเมินทักษะอื่น<br>
                2. ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า เด็กเคยถามคำถาม “ใคร” “อะไร” “ที่ไหน” “ทำไม” หรือไม่<br>
                <strong>ผ่าน:</strong> เด็กสามารถใช้คำถาม ถามต่างกัน 4 แบบ

              </td>
              <td>
                1. เล่านิทานให้เด็กฟัง ตั้งคำถามจากเนื้อเรื่องในนิทานให้เด็กตอบและกระตุ้นให้เด็กเป็นผู้ถาม พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก
                เป็นผู้ตอบบ้าง<br>
                2. ตั้งคำถามจากชีวิตประจำวันให้เด็กตอบบ่อย ๆ เช่น “ใครสอนหนังสือ” “ร้องเพลงอะไร”“หนังสืออยู่ที่ไหน” “ทำไมต้องไป”
                ในชีวิตประจำวัน<br>
                3. เมื่อเด็กถาม ให้ตอบเด็กทุกครั้งด้วยความเอาใจใส่ และพูดคุยกับเด็กในเรื่องที่เด็กถาม<br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกความจำคำศัพท์และสามารถเลือกใช้สื่อความหมายด้วยการพูดอย่างเหมาะสมกับสถานการณ์ ยิ่งเด็ก
                สามารถตั้งคำถามได้มาก ยิ่งแสดงออกถึงความใฝ่รู้เป็นอย่างดี</span><br>
              </td>
            </tr>

            <tr>
              <td>88<br>
                  <input type="checkbox" id="q88_pass" name="q88_pass" value="1">
                  <label for="q88_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q88_fail" name="q88_fail" value="1">
                  <label for="q88_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ทำตามกฎ กติกา ในการเล่นเป็นกลุ่มได้โดยมีผู้ใหญ่แนะนำ(PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถเล่นด้วยกันเป็นกลุ่มเล็ก ๆได้หรือไม่ <br>
                <strong>ผ่าน:</strong>  เด็กสามารถเล่นในกลุ่มตามกฎ กติกาโดยไม่ต้องแนะนำเป็นรายบุคคล
              </td>
              <td>
               1. ร่วมเล่นกิจกรรมง่าย ๆ กับเด็กเริ่มจากกลุ่มเล็ก ๆ เช่น เล่นซ่อนหาต่อบล็อก สร้างบ้าน เล่นขายของ เป็นต้น โดยตั้งกฎ กติกา ร่วมกัน
               และส่งเสริมให้เด็กเล่นกับเพื่อน โดยคอยดูแลขณะกำลังเล่น<br>
               2. ถ้าเด็กยังไม่สามารถเล่นตามกฎ กติกา ได้ ให้คอยกำกับเด็กจนเล่นตามกฎ กติกา ได้เอง<br>
               3. ฝึกเด็กให้รู้จักแพ้ชนะในการเล่นกิจกรรมต่าง ๆ เช่น เล่นซ่อนหา เป็นต้น<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกการปฏิบัติตามกฎ กติกา การเล่นร่วมกับผู้อื่นรวมทั้งเป็นการฝึกการควบคุมอารมณ์ของเด็ก</span>
              </td>
            </tr>

            <tr>
              <td>89<br>
                  <input type="checkbox" id="q89_pass" name="q89_pass" value="1">
                  <label for="q89_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q89_fail" name="q89_fail" value="1">
                  <label for="q89_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ช่วยทำงานขั้นตอนเดียวได้เอง (PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถช่วยทำงานง่าย ๆ เช่นยกของ เก็บของ ได้เอง โดยผู้ใหญ่ไม่ต้องช่วยได้หรือไม่” <br>
                <strong>ผ่าน:</strong> เด็กสามารถช่วยทำงานขั้นตอนเดียวได้เอง
              </td>
              <td>
               1. ชวนให้เด็กทำงานบ้านด้วยกัน เช่น เก็บของเล่น เก็บเสื้อผ้าของตนเอง ช่วยล้างจาน กวาดบ้าน หยิบของ ฝึกทุกครั้งที่มีโอกาส
               เพื่อให้เด็กสามารถทำงานบ้านง่าย ๆ ได้<br>
               2. ฝึกให้เด็กมีหน้าที่รับผิดชอบโดยเริ่มจากส่วนของตนเอง เช่นเก็บของเล่นด้วยตนเองหลังจากเล่นเสร็จ เก็บเสื้อผ้าของตนเองใส่ตะกร้า<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อให้เด็กรู้จักรับผิดชอบตัวเองและช่วยเหลือผู้อื่นในงานง่าย ๆ ได้ ส่งเสริมความภาคภูมิใจในตนเอง</span>
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
        <!-- Card ข้อที่ 84 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 84 - ยืนขาเดียว 3 วินาที (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q84_pass_mobile" name="q84_pass" value="1">
                <label class="form-check-label text-success" for="q84_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q84_fail_mobile" name="q84_fail" value="1">
                <label class="form-check-label text-danger" for="q84_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">แสดงวิธียืนขาเดียวให้เด็กดู แล้วบอกให้เด็กยืนขาเดียวให้นานที่สุดเท่าที่จะนานได้ให้โอกาสประเมิน 3 ครั้ง (อาจเปลี่ยนขาได้)</p>
              <p><strong>ผ่าน:</strong> เด็กยืนขาเดียวได้นาน 3 วินาทีอย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training84">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading84">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse84">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse84" class="accordion-collapse collapse" data-bs-parent="#training84">
                  <div class="accordion-body">
                    1. ยืนบนขาข้างเดียวให้เด็กดู<br>
                    2. ยืนหันหน้าเข้าหากัน และจับมือเด็กไว้ทั้งสองข้าง<br>
                    3. ยกขาข้างหนึ่งขึ้นแล้วบอกให้เด็กทำตาม เมื่อเด็กยืนได้เปลี่ยนเป็นจับมือเด็กข้างเดียว<br>
                    4. เมื่อเด็กสามารถยืนด้วยขาข้างเดียวได้ค่อย ๆ ปล่อยมือให้เด็กยืนทรงตัวได้ด้วยตนเอง เปลี่ยนเป็นยกขาอีกข้างหนึ่งโดยทำซ้ำเช่นเดียวกัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 85 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 85 - เลียนแบบวาดรูปวงกลม (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ดินสอ 2. กระดาษ
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q85_pass_mobile" name="q85_pass" value="1">
                <label class="form-check-label text-success" for="q85_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q85_fail_mobile" name="q85_fail" value="1">
                <label class="form-check-label text-danger" for="q85_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วาดรูปวงกลมขนาดเส้นผ่านศูนย์กลางประมาณ 5 ซม. ให้เด็กดูและพูดว่า"ครูวาดรูปวงกลม" และให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวาดรูปวงกลมโดยไม่มีเหลี่ยม ไม่เว้า และเส้นที่มาเชื่อมต่อซ้อนกันไม่เกิน 2 ซม. ได้อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training85">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading85">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse85">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse85" class="accordion-collapse collapse" data-bs-parent="#training85">
                  <div class="accordion-body">
                    1. สอนให้เด็กวาดวงกลมโดยเริ่มจากจุดที่กำหนดให้และกลับมาสิ้นสุดที่จุดกำหนดเดิม พร้อมออกเสียง โดยวาดเป็นวงกลมจากช้าไปเร็วพร้อมออกเสียง "วงกลม..หยุด"ยกมือขึ้นเพื่อให้เด็กเข้าใจคำว่า หยุด<br>
                    2. จับมือเด็กวาดวงกลมและยกมือเด็กขึ้นเมื่อออกเสียง "วงกลม....หยุด" โดยยกมือขึ้น ให้ตรงกับคำว่า หยุด<br>
                    3. เมื่อเด็กทำได้ดีขึ้นค่อย ๆ ลดการช่วยเหลือลง จนสามารถหยุดได้เองเมื่อลากเส้นมาถึงจุดสิ้นสุด
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 86 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 86 - ทำตามคำสั่งต่อเนื่องได้ 2 กริยากับวัตถุ 2 ชนิด (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ของเล่น เช่น หวี ตุ๊กตาผ้า บอล ช้อน
              <img src="../image/evaluation_pic/หวี ตุ๊กตา บอล ช้อน.png" alt="อุปกรณ์" class="img-fluid mb-2" style="max-width: 100px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q86_pass_mobile" name="q86_pass" value="1">
                <label class="form-check-label text-success" for="q86_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q86_fail_mobile" name="q86_fail" value="1">
                <label class="form-check-label text-danger" for="q86_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางวัตถุ 4 ชนิด ตรงหน้าเด็กในระยะที่เด็กหยิบได้แล้วพูดกับเด็ก เช่น "หยิบหวีให้ครูแล้วชี้ตุ๊กตาซิ"<br>
              2. วางสลับวัตถุทั้ง 4 ชนิดใหม่<br>
              3. กรณีที่เด็กไม่ผ่านคำสั่งแรกให้เปลี่ยนเป็นคำสั่งอื่นได้อีก 2 คำสั่ง โดยอาจเปลี่ยนกริยาหรือวัตถุได้</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถทำตามคำสั่งต่อเนื่องได้อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training86">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading86">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse86">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse86" class="accordion-collapse collapse" data-bs-parent="#training86">
                  <div class="accordion-body">
                    1. ฝึกเด็กในชีวิตประจำวัน โดยออกคำสั่ง เน้นคำที่เป็นชื่อสิ่งของและการกระทำ เช่น ขณะอาบน้ำ "เอาเสื้อใส่ตะกร้าแล้วหยิบผ้าเช็ดตัวมา" /ขณะแต่งตัว "ใส่กางเกงแล้วไปหวีผม" /ขณะรับประทานอาหาร "เก็บจานแล้วเอาผ้าไปเช็ดโต๊ะ"<br>
                    2. ถ้าเด็กทำได้เพียงคำสั่งเดียว ให้ชี้ไปที่สิ่งของที่ไม่ได้ทำแล้วบอกซ้ำหรือพูดกระตุ้นเตือนความจำเด็ก เช่น "ต่อไปทำอะไรอีกนะ?" หรือ "เมื่อกี้บอกอะไรอีกนะ?" ฝึกซ้ำ ๆ จนเด็กสามารถทำตามคำสั่งได้ถูกต้อง<br>
                    <span style="color: red;"><strong>วัสดุที่ใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย</span><br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกให้เด็กทำตามสั่งได้อย่างถูกต้องเหมาะสมโดยอาศัยความเข้าใจผ่านการจดจำเรียนรู้</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 87 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 87 - ถามคำถามได้ 4 แบบ เช่น ใคร อะไร ที่ไหน ทำไม (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q87_pass_mobile" name="q87_pass" value="1">
                <label class="form-check-label text-success" for="q87_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q87_fail_mobile" name="q87_fail" value="1">
                <label class="form-check-label text-danger" for="q87_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. สังเกตขณะเด็กเล่นของเล่นหรือประเมินทักษะอื่น<br>
              2. ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า เด็กเคยถามคำถาม "ใคร" "อะไร" "ที่ไหน" "ทำไม" หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใช้คำถาม ถามต่างกัน 4 แบบ</p>
            </div>
            <div class="accordion" id="training87">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading87">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse87">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse87" class="accordion-collapse collapse" data-bs-parent="#training87">
                  <div class="accordion-body">
                    1. เล่านิทานให้เด็กฟัง ตั้งคำถามจากเนื้อเรื่องในนิทานให้เด็กตอบและกระตุ้นให้เด็กเป็นผู้ถาม พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กเป็นผู้ตอบบ้าง<br>
                    2. ตั้งคำถามจากชีวิตประจำวันให้เด็กตอบบ่อย ๆ เช่น "ใครสอนหนังสือ" "ร้องเพลงอะไร""หนังสืออยู่ที่ไหน" "ทำไมต้องไป" ในชีวิตประจำวัน<br>
                    3. เมื่อเด็กถาม ให้ตอบเด็กทุกครั้งด้วยความเอาใจใส่ และพูดคุยกับเด็กในเรื่องที่เด็กถาม<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกความจำคำศัพท์และสามารถเลือกใช้สื่อความหมายด้วยการพูดอย่างเหมาะสมกับสถานการณ์ ยิ่งเด็กสามารถตั้งคำถามได้มาก ยิ่งแสดงออกถึงความใฝ่รู้เป็นอย่างดี</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 88 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 88 - ทำตามกฎ กติกา ในการเล่นเป็นกลุ่มได้โดยมีผู้ใหญ่แนะนำ(PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q88_pass_mobile" name="q88_pass" value="1">
                <label class="form-check-label text-success" for="q88_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q88_fail_mobile" name="q88_fail" value="1">
                <label class="form-check-label text-danger" for="q88_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่าเด็กสามารถเล่นด้วยกันเป็นกลุ่มเล็ก ๆได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเล่นในกลุ่มตามกฎ กติกาโดยไม่ต้องแนะนำเป็นรายบุคคล</p>
            </div>
            <div class="accordion" id="training88">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading88">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse88">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse88" class="accordion-collapse collapse" data-bs-parent="#training88">
                  <div class="accordion-body">
                    1. ร่วมเล่นกิจกรรมง่าย ๆ กับเด็กเริ่มจากกลุ่มเล็ก ๆ เช่น เล่นซ่อนหาต่อบล็อก สร้างบ้าน เล่นขายของ เป็นต้น โดยตั้งกฎ กติกา ร่วมกัน และส่งเสริมให้เด็กเล่นกับเพื่อน โดยคอยดูแลขณะกำลังเล่น<br>
                    2. ถ้าเด็กยังไม่สามารถเล่นตามกฎ กติกา ได้ ให้คอยกำกับเด็กจนเล่นตามกฎ กติกา ได้เอง<br>
                    3. ฝึกเด็กให้รู้จักแพ้ชนะในการเล่นกิจกรรมต่าง ๆ เช่น เล่นซ่อนหา เป็นต้น<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกการปฏิบัติตามกฎ กติกา การเล่นร่วมกับผู้อื่นรวมทั้งเป็นการฝึกการควบคุมอารมณ์ของเด็ก</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 89 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 89 - ช่วยทำงานขั้นตอนเดียวได้เอง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 37 - 41 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q89_pass_mobile" name="q89_pass" value="1">
                <label class="form-check-label text-success" for="q89_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q89_fail_mobile" name="q89_fail" value="1">
                <label class="form-check-label text-danger" for="q89_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กสามารถช่วยทำงานง่าย ๆ เช่นยกของ เก็บของ ได้เอง โดยผู้ใหญ่ไม่ต้องช่วยได้หรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถช่วยทำงานขั้นตอนเดียวได้เอง</p>
            </div>
            <div class="accordion" id="training89">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading89">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse89">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse89" class="accordion-collapse collapse" data-bs-parent="#training89">
                  <div class="accordion-body">
                    1. ชวนให้เด็กทำงานบ้านด้วยกัน เช่น เก็บของเล่น เก็บเสื้อผ้าของตนเอง ช่วยล้างจาน กวาดบ้าน หยิบของ ฝึกทุกครั้งที่มีโอกาส เพื่อให้เด็กสามารถทำงานบ้านง่าย ๆ ได้<br>
                    2. ฝึกให้เด็กมีหน้าที่รับผิดชอบโดยเริ่มจากส่วนของตนเอง เช่นเก็บของเล่นด้วยตนเองหลังจากเล่นเสร็จ เก็บเสื้อผ้าของตนเองใส่ตะกร้า<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อให้เด็กรู้จักรับผิดชอบตัวเองและช่วยเหลือผู้อื่นในงานง่าย ๆ ได้ ส่งเสริมความภาคภูมิใจในตนเอง</span>
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
      for (let i = 84; i <= 89; i++) {
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
      for (let i = 84; i <= 89; i++) {
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

      for (let i = 84; i <= 89; i++) {
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

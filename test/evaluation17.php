<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '43-48';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 101-106)
    for ($i = 101; $i <= 106; $i++) {
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
    $total_questions = 6; // แบบประเมินมีทั้งหมด 6 ข้อ (ข้อ 101-106)
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
  <title>แบบประเมิน ช่วงอายุ 43 ถึง 48 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 43 - 48 เดือน
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
            <!-- ข้อ 101-106 สำหรับ 43-48 เดือน -->
            <tr>
              <td rowspan="6">43 - 48 เดือน</td>
              <td>101<br>
                  <input type="checkbox" id="q101_pass" name="q101_pass" value="1">
                  <label for="q101_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q101_fail" name="q101_fail" value="1">
                  <label for="q101_fail">ไม่ผ่าน</label><br>
              </td>
              <td>กระโดดขาเดียวได้ อย่างน้อย2 ครั้ง (GM)<br><br>
              </td>
              <td>
                บอกให้เด็กกระโดดขาเดียวไปข้างหน้าหรือกระโดดให้เด็กดูก่อนแล้วบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถกระโดดขาเดียวไปข้างหน้าต่อเนื่องได้อย่างน้อย 2 ครั้ง
              </td>
              <td>
               1. กระโดดขาเดียวให้เด็กดู แล้วบอกให้ทำตาม<br>
               2. ถ้าเด็กทำไม่ได้ให้จับมือเด็ก แล้วบอกให้เด็กยืนทรงตัวบนขาข้างหนึ่ง และให้เด็กกระโดด เมื่อเด็กกระโดดได้แล้ว ให้ปล่อยมือและบอกให้เด็กกระโดดอยู่กับที่<br>
               3. ขยับห่างจากตัวเด็ก 1 ก้าว แล้วบอกให้เด็กกระโดดมาหาเมื่อทำได้แล้วให้เพิ่มระยะห่างเป็น 2 ก้าว<br>
               4. ชวนเด็กเล่นกระโดดขาเดียว ตามการละเล่นในแต่ละภาค เช่นเล่นตั้งเต เป็นต้น<br>
               5. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังในระหว่างการกระโดด
              </td>
            </tr>

            <tr>
              <td>102<br>
                  <input type="checkbox" id="q102_pass" name="q102_pass" value="1">
                  <label for="q102_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q102_fail" name="q102_fail" value="1">
                  <label for="q102_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ตัดกระดาษรูปสี่เหลี่ยมจัตุรัสขนาด 10 ซม. ออกเป็น 2 ชิ้น(โดยใช้กรรไกรปลายมน) (FM)<br><br>
              <strong>อุปกรณ์:</strong>1. กรรไกรปลายมนสำหรับเด็ก<br>
              2. กระดาษสี่เหลี่ยมจัตุรัสขนาด 10 ซม.<br>
              <img src="../image/evaluation_pic/กรรไกร กระดาษ 10 ซม.png" alt="Shapes" style="width: 200px; height: 120px;">
                </td>
              <td>
                1. แสดงวิธีตัดกระดาษรูปสี่เหลี่ยมจัตุรัสออกจากกันเป็น 2 ชิ้น ให้เด็กดู โดยตัดจากด้านหนึ่งไปยังอีกด้านหนึ่งที่อยู่ตรงข้าม<br>
                2. ยื่นกรรไกรให้กับมือข้างที่ถนัดของเด็กและยื่นกระดาษให้กับมืออีกข้างหนึ่งพูดกับเด็กว่า “หนูลองตัดกระดาษนี่ซิ”ให้โอกาสประเมิน 3 ครั้ง<br>
                <strong>ผ่าน:</strong>  เด็กใช้กรรไกรตัดกระดาษออกเป็น2 ชิ้น โดยตัดจากด้านหนึ่งไปยังอีกด้านหนึ่งที่อยู่ตรงข้าม อย่างน้อย 1 ใน 3 ครั้ง
              </td>
              <td>
                1. ใช้กรรไกรตัดกระดาษให้เด็กดูแล้วบอกให้เด็กทำตาม<br>
                2. ถ้าเด็กไม่สามารถทำได้ สอนเด็กให้ใช้กรรไกรมือเดียวโดยสอดนิ้วให้ถูกต้องและฝึกขยับนิ้ว<br>
                3. เมื่อเด็กขยับนิ้วเป็นแล้วให้เด็กฝึกตัดกระดาษขนาด 5 ซม. เมื่อเด็กทำได้ให้เพิ่มขนาดกระดาษเป็น 10 ซม.<br>
                4. ชวนเด็กเล่นตัดกระดาษเป็นรูปต่าง ๆ

              </td>
            </tr>

            <tr>
              <td>103<br>
                  <input type="checkbox" id="q103_pass" name="q103_pass" value="1">
                  <label for="q103_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q103_fail" name="q103_fail" value="1">
                  <label for="q103_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลียนแบบวาดรูป + (เครื่องหมายบวก) (FM)<br><br>
              <strong>อุปกรณ์:</strong>1. ดินสอ<br>
              2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;">
            </td>
              <td>
                วางกระดาษตรงหน้าเด็ก แล้ววาดรูป +(เครื่องหมายบวก) ขนาดอย่างน้อย 4 ซม.ส่งดินสอให้เด็กและพูดว่า “หนูลองทำซิ”
                ให้เด็กวาด 3 ครั้ง โดยแต่ละครั้งให้แสดงการวาดรูป + (เครื่องหมายบวก) ให้เด็กดูก่อนทุกครั้ง<br>
                <strong>ผ่าน:</strong>  เด็กสามารถวาดรูปโดยมีเส้นแนวดิ่งตัดกับเส้นแนวนอนได้เอง อย่างน้อย 1 ใน 3 ครั้ง (ไม่จำเป็นต้องมีขนาดเท่ากับแบบ)
              </td>
              <td>
                1. วาดรูป + (เครื่องหมายบวก) ให้เด็กดูเป็นตัวอย่าง บอกให้เด็กทำตาม<br>
                2. ถ้าเด็กทำไม่ได้ให้ทำเส้นประเป็นรูป + (เครื่องหมายบวก)แล้วจับมือเด็กลากเส้นตามแนว<br>
                3. เมื่อเด็กเริ่มทำได้ให้ลากเส้นตามเส้นประด้วยตัวเอง จากนั้นให้วาดโดยไม่มีมีเส้นประ จนเด็กสามารถวาดรูป + (เครื่องหมายบวก)ได้เอง<br>
                4. ชวนเด็กเล่นลากเส้นต่อจุดเป็นรูปต่าง ๆ
              </td>
            </tr>

            <tr>
              <td>104<br>
                  <input type="checkbox" id="q104_pass" name="q104_pass" value="1">
                  <label for="q104_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q104_fail" name="q104_fail" value="1">
                  <label for="q104_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เลือกวัตถุที่มีขนาดใหญ่กว่าและเล็กกว่า (RL)<br><br>
              <strong>อุปกรณ์:</strong> ชุดวัตถุ 3 ขนาด สีเดียวกัน จำนวน 3 ชุด (ทรงกระบอกสั้น สี่เหลี่ยม และสามเหลี่ยม)<br>
              <img src="../image/evaluation_pic/รูปทรงเรขาคณิต.png" alt="Shapes" style="width: 200px; height: 120px;">
              </td>
              <td>
                1. วางวัตถุบนโต๊ะทีละชุด โดยไม่ต้องเรียงขนาด<br>
                2. ชี้วัตถุชิ้นที่มีขนาดกลาง แล้วถามว่า“อันไหนใหญ่กว่าอันนี้” “อันไหนเล็กกว่าอันนี้” ทำให้ครบทั้ง 3 ชุดโดยเริ่มจากทรงกระบอกสั้น สี่เหลี่ยมและสามเหลี่ยม<br>
                <strong>ผ่าน:</strong> เด็กสามารถเลือกวัตถุที่มีขนาดใหญ่กว่า และเล็กกว่าได้ถูกต้อง 2 ใน 3 ชุด
              </td>
              <td>
                1. ฝึกเด็กในชีวิตประจำวัน เช่น ขณะรับประทานอาหาร สอนเด็กให้รู้จักถ้วยใบใหญ่ ถ้วยใบกลาง ถ้วยใบเล็ก<br>
                2. ชี้ที่ถ้วยใบกลาง พร้อมกับชี้ที่ถ้วยใบใหญ่แล้วบอกเด็กว่า“ถ้วยนี้ใหญ่กว่าอันกลาง” และชี้ไปที่ถ้วยใบเล็ก แล้วบอกว่า ถ้วยนี้เล็กกว่าอันกลาง”<br>
                3. ทดสอบความเข้าใจ โดยชี้ไปที่ถ้วยใบกลาง แล้วถามเด็กว่า“อันไหนใหญ่กว่าอันนี้” “อันไหนเล็กกว่าอันนี้” ฝึกเด็กบ่อยๆโดยเปลี่ยนอุปกรณ์ให้หลากหลายมากขึ้น<br>
                <span style="color: red;"><strong>วัสดุใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย โดยเป็นชนิดเดียวกันแต่มีขนาดต่างกัน เช่น ถ้วย จาน ช้อน</span>
              </td>
            </tr>

            <tr>
              <td>105<br>
                  <input type="checkbox" id="q105_pass" name="q105_pass" value="1">
                  <label for="q105_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q105_fail" name="q105_fail" value="1">
                  <label for="q105_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดเป็นประโยค ติดต่อกันโดยมีความหมาย และเหมาะสมกับโอกาสได้ (EL)<br><br>
                </td>
              <td>
                สังเกตขณะทดสอบ หรือถามจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่าสามารถพูดเป็นประโยคที่เหมาะสมกับสถานการณ์ได้
                เช่น หนูหิวข้าว หนูกินขนม หนูไปห้องน้ำ หนูเล่นกับเพื่อน หนูนอนบนเตียง เป็นต้น <br>
                <strong>ผ่าน:</strong>  เด็กสามารถพูดเป็นประโยคได้โดยมีความหมาย และเหมาะสมกับโอกาสได้อย่างน้อย 5 ประโยค
              </td>
              <td>
               1. ฝึกการใช้ประโยคตามสถานการณ์และกิจวัตรประจำวัน ตัวอย่าง<br>
                - เวลารับประทานอาหาร เช่น หนูกินข้าว หนูตักข้าว หนูกินผัก หนูดื่มน้ำ เป็นต้น<br>
                - เวลาอาบน้ำ เช่น หนูถูสบู่ หนูตักน้ำ หนูล้างมือ หนูแปรงฟัน (วันละ 2 ครั้ง เช้าและก่อนนอน)<br>
                - เวลาแต่งตัว เช่น หนูใส่เสื้อ พ่อหยิบกางเกง หนูหวีผม หนูทาแป้ง เป็นต้น<br>
                - เวลาช่วยงานบ้าน เช่น หนูเก็บของเล่น หนูกวาดพื้น หนูปิดพัดลม พ่อล้างรถ แม่ถูบ้าน เป็นต้น<br>
                - เวลาทำกิจวัตรประจำวัน เช่น แม่อ่านนิทาน หนูปั่นจักรยาน<br>
              2. กระตุ้นการพูดในเด็ก
              </td>
            </tr>

            <tr>
              <td>106<br>
                  <input type="checkbox" id="q106_pass" name="q106_pass" value="1">
                  <label for="q106_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q106_fail" name="q106_fail" value="1">
                  <label for="q106_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ใส่กระดุมขนาดใหญ่อย่างน้อย 2 ซม. ได้เอง 3 เม็ด (PS)<br><br>
                <strong>อุปกรณ์:</strong> ตุ๊กตาผ้าที่มีกระดุมขนาดใหญ่ อย่างน้อย 2 ซม.<br>
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="Doll with Buttons" style="width: 100px; height: 160px;">
                </td>
              <td>
                ใส่กระดุมให้เด็กดู และถอดกระดุมออกแล้วบอกให้เด็กทำตาม <br>
                <strong>ผ่าน:</strong> เด็กสามารถใส่กระดุมได้เอง 3 เม็ด
              </td>
              <td>
               1. แสดงวิธีใส่กระดุมให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
               2. ถ้าเด็กทำไม่ได้ให้จับมือทำโดยใช้นิ้วหัวแม่มือและนิ้วชี้ของมือข้างหนึ่งจับสาบเสื้อที่ด้านที่มีรังดุม ดึงรังดุมให้กว้าง และใช้มืออีกข้างหนึ่งจับกระดุมตะแคงลง ดันใส่รังดุมครึ่งเม็ด<br>
               3. เปลี่ยนมือที่จับสาบเสื้อมาดึงกระดุมให้หลุดจากรังดุมทั้งเม็ด<br>
               4. ค่อย ๆ ลดการช่วยเหลือลง จนสามารถทำได้เองทุกขั้นตอนเมื่อเด็กทำได้ดีแล้ว ฝึกให้เด็กใส่เสื้อและติดกระดุมด้วยตนเอง<br>
               <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกการช่วยเหลือตนเองในชีวิตประจำวันผ่านการจดจำเรียนรู้ เช่น ใส่เสื้อติดกระดุมได้อย่างถูกต้องด้วยตนเองเพิ่มความภาคภูมิใจในตนเอง</span>
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
        <!-- Card ข้อที่ 101 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 101 - กระโดดขาเดียวได้ อย่างน้อย2 ครั้ง (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q101_pass_mobile" name="q101_pass" value="1">
                <label class="form-check-label text-success" for="q101_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q101_fail_mobile" name="q101_fail" value="1">
                <label class="form-check-label text-danger" for="q101_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">บอกให้เด็กกระโดดขาเดียวไปข้างหน้าหรือกระโดดให้เด็กดูก่อนแล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถกระโดดขาเดียวไปข้างหน้าต่อเนื่องได้อย่างน้อย 2 ครั้ง</p>
            </div>
            <div class="accordion" id="training101">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading101">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse101">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse101" class="accordion-collapse collapse" data-bs-parent="#training101">
                  <div class="accordion-body">
                    1. กระโดดขาเดียวให้เด็กดู แล้วบอกให้ทำตาม<br>
                    2. ถ้าเด็กทำไม่ได้ให้จับมือเด็ก แล้วบอกให้เด็กยืนทรงตัวบนขาข้างหนึ่ง และให้เด็กกระโดด เมื่อเด็กกระโดดได้แล้ว ให้ปล่อยมือและบอกให้เด็กกระโดดอยู่กับที่<br>
                    3. ขยับห่างจากตัวเด็ก 1 ก้าว แล้วบอกให้เด็กกระโดดมาหาเมื่อทำได้แล้วให้เพิ่มระยะห่างเป็น 2 ก้าว<br>
                    4. ชวนเด็กเล่นกระโดดขาเดียว ตามการละเล่นในแต่ละภาค เช่นเล่นตั้งเต เป็นต้น<br>
                    5. พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ควรระมัดระวังในระหว่างการกระโดด
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 102 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 102 - ตัดกระดาษรูปสี่เหลี่ยมจัตุรัสขนาด 10 ซม. ออกเป็น 2 ชิ้น(โดยใช้กรรไกรปลายมน) (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. กรรไกรปลายมนสำหรับเด็ก<br>
              2. กระดาษสี่เหลี่ยมจัตุรัสขนาด 10 ซม.<br>
              <img src="../image/evaluation_pic/กรรไกร กระดาษ 10 ซม.png" alt="Shapes" style="width: 200px; height: 120px;">

            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q102_pass_mobile" name="q102_pass" value="1">
                <label class="form-check-label text-success" for="q102_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q102_fail_mobile" name="q102_fail" value="1">
                <label class="form-check-label text-danger" for="q102_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. แสดงวิธีตัดกระดาษรูปสี่เหลี่ยมจัตุรัสออกจากกันเป็น 2 ชิ้น ให้เด็กดู โดยตัดจากด้านหนึ่งไปยังอีกด้านหนึ่งที่อยู่ตรงข้าม<br>
              2. ยื่นกรรไกรให้กับมือข้างที่ถนัดของเด็กและยื่นกระดาษให้กับมืออีกข้างหนึ่งพูดกับเด็กว่า "หนูลองตัดกระดาษนี่ซิ"ให้โอกาสประเมิน 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กใช้กรรไกรตัดกระดาษออกเป็น2 ชิ้น โดยตัดจากด้านหนึ่งไปยังอีกด้านหนึ่งที่อยู่ตรงข้าม อย่างน้อย 1 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training102">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading102">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse102">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse102" class="accordion-collapse collapse" data-bs-parent="#training102">
                  <div class="accordion-body">
                    1. ใช้กรรไกรตัดกระดาษให้เด็กดูแล้วบอกให้เด็กทำตาม<br>
                    2. ถ้าเด็กไม่สามารถทำได้ สอนเด็กให้ใช้กรรไกรมือเดียวโดยสอดนิ้วให้ถูกต้องและฝึกขยับนิ้ว<br>
                    3. เมื่อเด็กขยับนิ้วเป็นแล้วให้เด็กฝึกตัดกระดาษขนาด 5 ซม. เมื่อเด็กทำได้ให้เพิ่มขนาดกระดาษเป็น 10 ซม.<br>
                    4. ชวนเด็กเล่นตัดกระดาษเป็นรูปต่าง ๆ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 103 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 103 - เลียนแบบวาดรูป + (เครื่องหมายบวก) (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. ดินสอ<br>
              2. กระดาษ<br>
              <img src="../image/evaluation_pic/ดินสอ กระดาษ.png" alt="Family" style="width: 150px; height: 160px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q103_pass_mobile" name="q103_pass" value="1">
                <label class="form-check-label text-success" for="q103_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q103_fail_mobile" name="q103_fail" value="1">
                <label class="form-check-label text-danger" for="q103_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางกระดาษตรงหน้าเด็ก แล้ววาดรูป +(เครื่องหมายบวก) ขนาดอย่างน้อย 4 ซม.ส่งดินสอให้เด็กและพูดว่า "หนูลองทำซิ" ให้เด็กวาด 3 ครั้ง โดยแต่ละครั้งให้แสดงการวาดรูป + (เครื่องหมายบวก) ให้เด็กดูก่อนทุกครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถวาดรูปโดยมีเส้นแนวดิ่งตัดกับเส้นแนวนอนได้เอง อย่างน้อย 1 ใน 3 ครั้ง (ไม่จำเป็นต้องมีขนาดเท่ากับแบบ)</p>
            </div>
            <div class="accordion" id="training103">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading103">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse103">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse103" class="accordion-collapse collapse" data-bs-parent="#training103">
                  <div class="accordion-body">
                    1. วาดรูป + (เครื่องหมายบวก) ให้เด็กดูเป็นตัวอย่าง บอกให้เด็กทำตาม<br>
                    2. ถ้าเด็กทำไม่ได้ให้ทำเส้นประเป็นรูป + (เครื่องหมายบวก)แล้วจับมือเด็กลากเส้นตามแนว<br>
                    3. เมื่อเด็กเริ่มทำได้ให้ลากเส้นตามเส้นประด้วยตัวเอง จากนั้นให้วาดโดยไม่มีมีเส้นประ จนเด็กสามารถวาดรูป + (เครื่องหมายบวก)ได้เอง<br>
                    4. ชวนเด็กเล่นลากเส้นต่อจุดเป็นรูปต่าง ๆ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 104 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 104 - เลือกวัตถุที่มีขนาดใหญ่กว่าและเล็กกว่า (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ชุดวัตถุ 3 ขนาด สีเดียวกัน จำนวน 3 ชุด (ทรงกระบอกสั้น สี่เหลี่ยม และสามเหลี่ยม)<br>
              <img src="../image/evaluation_pic/รูปทรงเรขาคณิต.png" alt="Shapes" style="width: 200px; height: 120px;">

            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q104_pass_mobile" name="q104_pass" value="1">
                <label class="form-check-label text-success" for="q104_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q104_fail_mobile" name="q104_fail" value="1">
                <label class="form-check-label text-danger" for="q104_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. วางวัตถุบนโต๊ะทีละชุด โดยไม่ต้องเรียงขนาด<br>
              2. ชี้วัตถุชิ้นที่มีขนาดกลาง แล้วถามว่า"อันไหนใหญ่กว่าอันนี้" "อันไหนเล็กกว่าอันนี้" ทำให้ครบทั้ง 3 ชุดโดยเริ่มจากทรงกระบอกสั้น สี่เหลี่ยมและสามเหลี่ยม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเลือกวัตถุที่มีขนาดใหญ่กว่า และเล็กกว่าได้ถูกต้อง 2 ใน 3 ชุด</p>
            </div>
            <div class="accordion" id="training104">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading104">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse104">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse104" class="accordion-collapse collapse" data-bs-parent="#training104">
                  <div class="accordion-body">
                    1. ฝึกเด็กในชีวิตประจำวัน เช่น ขณะรับประทานอาหาร สอนเด็กให้รู้จักถ้วยใบใหญ่ ถ้วยใบกลาง ถ้วยใบเล็ก<br>
                    2. ชี้ที่ถ้วยใบกลาง พร้อมกับชี้ที่ถ้วยใบใหญ่แล้วบอกเด็กว่า"ถ้วยนี้ใหญ่กว่าอันกลาง" และชี้ไปที่ถ้วยใบเล็ก แล้วบอกว่า ถ้วยนี้เล็กกว่าอันกลาง"<br>
                    3. ทดสอบความเข้าใจ โดยชี้ไปที่ถ้วยใบกลาง แล้วถามเด็กว่า"อันไหนใหญ่กว่าอันนี้" "อันไหนเล็กกว่าอันนี้" ฝึกเด็กบ่อยๆโดยเปลี่ยนอุปกรณ์ให้หลากหลายมากขึ้น<br>
                    <span style="color: red;"><strong>วัสดุใช้แทนได้:</strong> ของใช้ในบ้านชนิดอื่น ๆ ที่ไม่เป็นอันตราย โดยเป็นชนิดเดียวกันแต่มีขนาดต่างกัน เช่น ถ้วย จาน ช้อน</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 105 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 105 - พูดเป็นประโยค ติดต่อกันโดยมีความหมาย และเหมาะสมกับโอกาสได้ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q105_pass_mobile" name="q105_pass" value="1">
                <label class="form-check-label text-success" for="q105_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q105_fail_mobile" name="q105_fail" value="1">
                <label class="form-check-label text-danger" for="q105_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">สังเกตขณะทดสอบ หรือถามจากพ่อแม่ผู้ปกครองหรือผู้ดูแลเด็กว่าสามารถพูดเป็นประโยคที่เหมาะสมกับสถานการณ์ได้ เช่น หนูหิวข้าว หนูกินขนม หนูไปห้องน้ำ หนูเล่นกับเพื่อน หนูนอนบนเตียง เป็นต้น</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถพูดเป็นประโยคได้โดยมีความหมาย และเหมาะสมกับโอกาสได้อย่างน้อย 5 ประโยค</p>
            </div>
            <div class="accordion" id="training105">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading105">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse105">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse105" class="accordion-collapse collapse" data-bs-parent="#training105">
                  <div class="accordion-body">
                    1. ฝึกการใช้ประโยคตามสถานการณ์และกิจวัตรประจำวัน ตัวอย่าง<br>
                    - เวลารับประทานอาหาร เช่น หนูกินข้าว หนูตักข้าว หนูกินผัก หนูดื่มน้ำ เป็นต้น<br>
                    - เวลาอาบน้ำ เช่น หนูถูสบู่ หนูตักน้ำ หนูล้างมือ หนูแปรงฟัน (วันละ 2 ครั้ง เช้าและก่อนนอน)<br>
                    - เวลาแต่งตัว เช่น หนูใส่เสื้อ พ่อหยิบกางเกง หนูหวีผม หนูทาแป้ง เป็นต้น<br>
                    - เวลาช่วยงานบ้าน เช่น หนูเก็บของเล่น หนูกวาดพื้น หนูปิดพัดลม พ่อล้างรถ แม่ถูบ้าน เป็นต้น<br>
                    - เวลาทำกิจวัตรประจำวัน เช่น แม่อ่านนิทาน หนูปั่นจักรยาน<br>
                    2. กระตุ้นการพูดในเด็ก
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 106 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 106 - ใส่กระดุมขนาดใหญ่อย่างน้อย 2 ซม. ได้เอง 3 เม็ด (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 43 - 48 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ตุ๊กตาผ้าที่มีกระดุมขนาดใหญ่ อย่างน้อย 2 ซม.<br>
              <img src="../image/evaluation_pic/ตุ๊กตาผ้า.png" alt="Doll with Buttons" style="width: 100px; height: 160px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q106_pass_mobile" name="q106_pass" value="1">
                <label class="form-check-label text-success" for="q106_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q106_fail_mobile" name="q106_fail" value="1">
                <label class="form-check-label text-danger" for="q106_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ใส่กระดุมให้เด็กดู และถอดกระดุมออกแล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถใส่กระดุมได้เอง 3 เม็ด</p>
            </div>
            <div class="accordion" id="training106">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading106">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse106">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse106" class="accordion-collapse collapse" data-bs-parent="#training106">
                  <div class="accordion-body">
                    1. แสดงวิธีใส่กระดุมให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
                    2. ถ้าเด็กทำไม่ได้ให้จับมือทำโดยใช้นิ้วหัวแม่มือและนิ้วชี้ของมือข้างหนึ่งจับสาบเสื้อที่ด้านที่มีรังดุม ดึงรังดุมให้กว้าง และใช้มืออีกข้างหนึ่งจับกระดุมตะแคงลง ดันใส่รังดุมครึ่งเม็ด<br>
                    3. เปลี่ยนมือที่จับสาบเสื้อมาดึงกระดุมให้หลุดจากรังดุมทั้งเม็ด<br>
                    4. ค่อย ๆ ลดการช่วยเหลือลง จนสามารถทำได้เองทุกขั้นตอนเมื่อเด็กทำได้ดีแล้ว ฝึกให้เด็กใส่เสื้อและติดกระดุมด้วยตนเอง<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกการช่วยเหลือตนเองในชีวิตประจำวันผ่านการจดจำเรียนรู้ เช่น ใส่เสื้อติดกระดุมได้อย่างถูกต้องด้วยตนเองเพิ่มความภาคภูมิใจในตนเอง</span>
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
      for (let i = 101; i <= 106; i++) {
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

      // Mobile version synchronization
      for (let i = 101; i <= 106; i++) {
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
              if (passCheckboxDesktop) {
                passCheckboxDesktop.checked = true;
                if (failCheckboxDesktop) failCheckboxDesktop.checked = false;
              }
            }
            updateSummary();
          });
          
          failCheckboxMobile.addEventListener('change', function() {
            if (this.checked) {
              passCheckboxMobile.checked = false;
              // Sync with desktop
              if (failCheckboxDesktop) {
                failCheckboxDesktop.checked = true;
                if (passCheckboxDesktop) passCheckboxDesktop.checked = false;
              }
            }
            updateSummary();
          });

          // Sync desktop to mobile (only if not already added)
          if (passCheckboxDesktop && !passCheckboxDesktop.dataset.mobileSync) {
            passCheckboxDesktop.dataset.mobileSync = 'true';
            passCheckboxDesktop.addEventListener('change', function() {
              if (this.checked) {
                passCheckboxMobile.checked = true;
                failCheckboxMobile.checked = false;
              }
              updateSummary();
            });
          }

          if (failCheckboxDesktop && !failCheckboxDesktop.dataset.mobileSync) {
            failCheckboxDesktop.dataset.mobileSync = 'true';
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

      for (let i = 101; i <= 106; i++) {
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

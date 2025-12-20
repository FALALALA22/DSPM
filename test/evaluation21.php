<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '61-66';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 125-129)
    for ($i = 125; $i <= 129; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 79-83)
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
  <title>แบบประเมิน ช่วงอายุ 61 ถึง 66 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 61 - 66 เดือน
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
            <!-- ข้อ 125-129 สำหรับ 61-66 เดือน -->
            <tr>
              <td rowspan="5">61 - 66 เดือน</td>
              <td>125<br>
                  <input type="checkbox" id="q125_pass" name="q125_pass" value="1">
                  <label for="q125_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q125_fail" name="q125_fail" value="1">
                  <label for="q125_fail">ไม่ผ่าน</label><br>
              </td>
              <td>กระโดดขำเดียวไปข้างหน้ำ 4 ครั้งทีละข้าง (GM)<br><br>
              </td>
              <td>
                บอกเด็กว่า “กระโดดขาเดียวต่อเนื่อง 4 ครั้ง ไปข้างหน้า ทำทีละข้าง” หรือกระโดดให้เด็กดูก่อน แล้วบอกให้เด็กทำตาม<br>
                <strong>ผ่าน:</strong> เด็กสามารถกระโดดขาเดียวไปข้างหน้าต่อเนื่อง 4 ครั้ง ได้ทั้ง 2 ข้าง (ซ้ายและขวา) หากเด็กทำไม่ได้ในครั้งแรก ให้โอกาสอีก 1 ครั้ง
                
              </td>
              <td>
               1. จับมือเด็ก แล้วบอกให้เด็กยืนทรงตัวบนขาข้างเดียว เมื่อมั่นใจแล้วจึงปล่อยมือเด็ก เพื่อให้เด็กยืนขาเดียวเองทีละข้าง<br>
               2. กระโดดขาเดียวให้เด็กดู และบอกให้ทำตาม โดยเริ่มกระโดดขาเดียวอยู่กับที่ก่อน<br>
               3. กระโดดขาเดียวไปข้างหน้าให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
               4. ชวนเด็กเล่นกระโดดขาเดียว (กระต่ายขาเดียว) ตามการละเล่นในแต่ละพื้นที่<br>
               5. เมื่อเด็กทำได้ จะเป็นพื้นฐานของการออกกำลังกายอย่างอื่นต่อไป เช่น กระโดดเชือก ฟุตบอล บาสเกตบอล ยิมนาสติก เต้นรำ หรือรำไทย<br>
               
              </td>
            </tr>

            <tr>
              <td>126<br>
                  <input type="checkbox" id="q126_pass" name="q126_pass" value="1">
                  <label for="q126_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q126_fail" name="q126_fail" value="1">
                  <label for="q126_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ตัดกระดาษตามเส้นตรงต่อเนื่อง ยาว 15 ซม.(FM)<br><br>
              <strong>อุปกรณ์:</strong>1. กระดาษที่มีเส้นตรงยาว 15 ซม.จากขอบ 2.กรรไกรปลายมนสำหรับเด็ก<br>
              <img src="../image/evaluation_pic/กระดาษ เส้นตรงยาว กรรไกรปลายมน.png" alt="Child-safe Scissors" style="width: 150px; height: 110px;">
                </td>
              <td>
                บอกให้เด็กใช้กรรไกรตัดกระดาษตามเส้นตรง ยาว 15 ซม. ต่อเนื่อง<br>
                <strong>ผ่าน:</strong>  เด็กสามารถตัดกระดาษตามเส้นตรงได้อย่างต่อเนื่อง ยาว 15 ซม.
                
              </td>
              <td>
                1. ชวนเด็กช่วยทำงานบ้านที่ออกกำลังมือและนิ้วมือ เน้นการใช้หัวแม่มือกับนิ้วชี้ เช่น ช่วยบิดผ้า ช่วยหยิบของใช้ เด็ดผัก ใช้ไม้หนีบใช้ตะเกียบ ใช้คีมคีบสิ่งของ ใช้ช้อนส้อม จับไม้พาย ทำกาว ทำแยมหรือทำงานบ้านที่เน้นการใช้สองมือประสานกัน เช่นจัดโต๊ะอาหารเด็ดผัก เตรียมอาหารสอนให้ใช้สองมือทำงานร่วมกัน เช่น ผูกและแก้ปมเชือกรองเท้า กลัดกระดุมเสื้อ ใส่เข็มขัด<br>
                2. ชวนทำกิจกรรมที่ใช้เครื่องเขียนและอุปกรณ์ศิลปะหลายประเภท เพื่อช่วยพัฒนากล้ามเนื้อมัดเล็ก เช่น สีเทียน สีไม้ สีเมจิก (ไร้สารพิษ) การทำศิลปะโดยใช้วัสดุตามธรรมชาติและการผสมสีให้เป็นสีต่าง ๆ<br>
                3. ชวนเด็กทำกิจกรรมที่ต้องใช้กรรไกร และสอนวิธีการใช้อย่างปลอดภัย (ใช้กรรไกรปลายมนสำหรับเด็ก) เริ่มจากการตัดเป็นเส้นตรง จากนั้นค่อยฝึกตัดเป็นรูปต่าง ๆ<br>
                
              </td>
            </tr>

            <tr>
              <td>127<br>
                  <input type="checkbox" id="q127_pass" name="q127_pass" value="1">
                  <label for="q127_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q127_fail" name="q127_fail" value="1">
                  <label for="q127_fail">ไม่ผ่าน</label><br>
              </td>
              <td>บวกเลขเบื้องต้น ผลลัพธ์ไม่เกิน 10 (RL)<br><br>
              
            </td>
              <td>
                ถามคำถามบวกเลข เช่น 1 + 2 เท่ากับเท่าไร 5 + 5 เท่ากับเท่าไร<br>
                <strong>ผ่าน:</strong>  หากเด็กสามารถรวมสิ่งของหรือนับนิ้วรวมกันโดยใช้จำนวน 1–5 และตอบถูกทั้ง 2 คำถาม ถือว่า ผ่าน โดยให้โอกาสตอบ 2 ครั้ง
                
              </td>
              <td>
                1. ชวนเด็กนับสิ่งของต่าง ๆ ที่อยู่รอบตัวในชีวิตประจำวัน เช่น ไม้หนีบผ้า โต๊ะ เก้าอี้ จาน ชามหรือนับของที่แม่ซื้อมาจากตลาด<br>
                2. สอนให้เด็กรู้จักการบวก โดยนำสิ่งของชนิดเดียวกัน 2 จำนวนมารวมกัน เช่น มีไม้หนีบสีฟ้า 3 อัน และสีเหลือง 4 อัน รวมมีไม้หนีบทั้งหมดกี่อัน<br>   
            </tr>

            <tr>
              <td>128<br>
                  <input type="checkbox" id="q128_pass" name="q128_pass" value="1">
                  <label for="q128_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q128_fail" name="q128_fail" value="1">
                  <label for="q128_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กสามารถอธิบายหน้าที่หรือคุณสมบัติของสิ่งของได้อย่างน้อย 6 ชนิด (EL)<br><br>
              </td>
              <td>
                ถามเด็กด้วยคำถามปลายเปิด เช่น “...เป็นอย่างไร” ให้เด็กอธิบายถึงสิ่งของ 8 ชนิด ได้แก่ บ้าน บอล เก้าอี้ ส้ม จาน แมว ทะเล/คลอง หลังคำ/เพดาน โดยให้เด็กอธิบายตามคุณสมบัติอย่างน้อย 1 อย่างต่อสิ่งของแต่ละชนิด ได้แก่ การใช้งาน รูปร่าง ส่วนประกอบ และจัดอยู่ในประเภทอะไร<br>
                <strong>ผ่าน:</strong> เด็กสามารถอธิบายคุณสมบัติของสิ่งของได้ถูกต้องอย่างน้อย 6 ชนิด
                
              </td>
              <td>
                1. พูดคุยกับเด็กเกี่ยวกับสิ่งต่าง ๆ รอบตัว ชี้ให้สังเกตรูปร่าง ลักษณะ การใช้งาน และส่วนประกอบของสิ่งของ<br>
                2. อธิบายหน้าที่และคุณสมบัติของสิ่งต่าง ๆ ที่อยู่รอบตัวจากของจริงหรือรูปภาพให้เด็กฟังบ่อย ๆ เช่น “ลูกบอลกลม ๆ เอาไว้เตะ” “แมวเป็นสัตว์เลี้ยง มี 4 ขา ร้องเหมียว ๆ”<br>
                3. กระตุ้นให้เด็กตอบคำถามเกี่ยวกับหน้าที่หรือคุณสมบัติของสิ่งของต่าง ๆ จนเด็กสามารถตอบได้เอง<br>                
              </td>
            </tr>

            <tr>
              <td>129<br>
                  <input type="checkbox" id="q129_pass" name="q129_pass" value="1">
                  <label for="q129_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q129_fail" name="q129_fail" value="1">
                  <label for="q129_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ช่วยงนบ้าน(PS)<br><br>
                </td>
              <td>
               ถามพ่อ แม่ ผู้ปกครอง หรือครู/ผู้ดูแลเด็กว่า เด็กช่วยงานบ้านง่าย ๆ ได้หรือไม่ เช่น เก็บที่นอน/พับผ้าห่ม ช่วยตากผ้า/เก็บผ้า เก็บของเล่น/ของใช้เข้าที่<br>
                <strong>ผ่าน:</strong> พ่อ แม่ ผู้ปกครอง หรือครู/ผู้ดูแลเด็ก ตอบได้ว่าเด็กช่วยเหลืองานบ้านได้อย่างน้อย 2 อย่าง
                
              </td>
              <td>
               1. ฝึกเด็กทำงานบ้านในสถานการณ์จริง โดยทำเป็นตัวอย่างและชักชวนเด็กทำ พร้อมทั้งจัดหาอุปกรณ์และงานที่เหมาะสมกับเด็ก เช่น ไม้กวาด ที่ตักผง ผ้าเช็ดพื้น เป็นต้น ให้เด็กกวาดบ้าน ถูบ้าน เช็ดเก้าอี้<br>
               2. มอบหมายงานบ้านง่าย ๆ ที่เหมาะสมตามวัยให้แก่เด็ก โดยมีผู้ปกครองดูแลและช่วยเหลือเมื่อจำเป็นน<br> 
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
        <!-- Card ข้อที่ 125 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 125 - กระโดดขำเดียวไปข้างหน้ำ 4 ครั้งทีละข้าง (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 61 - 66 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q125_pass_mobile" name="q125_pass" value="1">
                <label class="form-check-label text-success" for="q125_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q125_fail_mobile" name="q125_fail" value="1">
                <label class="form-check-label text-danger" for="q125_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">บอกเด็กว่า "กระโดดขาเดียวต่อเนื่อง 4 ครั้ง ไปข้างหน้า ทำทีละข้าง" หรือกระโดดให้เด็กดูก่อน แล้วบอกให้เด็กทำตาม</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถกระโดดขาเดียวไปข้างหน้าต่อเนื่อง 4 ครั้ง ได้ทั้ง 2 ข้าง (ซ้ายและขวา) หากเด็กทำไม่ได้ในครั้งแรก ให้โอกาสอีก 1 ครั้ง</p>
            </div>
            <div class="accordion" id="training125">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading125">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse125">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse125" class="accordion-collapse collapse" data-bs-parent="#training125">
                  <div class="accordion-body">
                    1. จับมือเด็ก แล้วบอกให้เด็กยืนทรงตัวบนขาข้างเดียว เมื่อมั่นใจแล้วจึงปล่อยมือเด็ก เพื่อให้เด็กยืนขาเดียวเองทีละข้าง<br>
                    2. กระโดดขาเดียวให้เด็กดู และบอกให้ทำตาม โดยเริ่มกระโดดขาเดียวอยู่กับที่ก่อน<br>
                    3. กระโดดขาเดียวไปข้างหน้าให้เด็กดู แล้วบอกให้เด็กทำตาม<br>
                    4. ชวนเด็กเล่นกระโดดขาเดียว (กระต่ายขาเดียว) ตามการละเล่นในแต่ละพื้นที่<br>
                    5. เมื่อเด็กทำได้ จะเป็นพื้นฐานของการออกกำลังกายอย่างอื่นต่อไป เช่น กระโดดเชือก ฟุตบอล บาสเกตบอล ยิมนาสติก เต้นรำ หรือรำไทย
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 126 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 126 - ตัดกระดาษตามเส้นตรงต่อเนื่อง ยาว 15 ซม.(FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 61 - 66 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> 1. กระดาษที่มีเส้นตรงยาว 15 ซม.จากขอบ 2.กรรไกรปลายมนสำหรับเด็ก<br>
              <img src="../image/evaluation_pic/กระดาษ เส้นตรงยาว กรรไกรปลายมน.png" alt="Child-safe Scissors" style="width: 150px; height: 110px;">
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q126_pass_mobile" name="q126_pass" value="1">
                <label class="form-check-label text-success" for="q126_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q126_fail_mobile" name="q126_fail" value="1">
                <label class="form-check-label text-danger" for="q126_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">บอกให้เด็กใช้กรรไกรตัดกระดาษตามเส้นตรง ยาว 15 ซม. ต่อเนื่อง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถตัดกระดาษตามเส้นตรงได้อย่างต่อเนื่อง ยาว 15 ซม.</p>
            </div>
            <div class="accordion" id="training126">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading126">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse126">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse126" class="accordion-collapse collapse" data-bs-parent="#training126">
                  <div class="accordion-body">
                    1. ชวนเด็กช่วยทำงานบ้านที่ออกกำลังมือและนิ้วมือ เน้นการใช้หัวแม่มือกับนิ้วชี้ เช่น ช่วยบิดผ้า ช่วยหยิบของใช้ เด็ดผัก ใช้ไม้หนีบใช้ตะเกียบ ใช้คีมคีบสิ่งของ ใช้ช้อนส้อม จับไม้พาย ทำกาว ทำแยมหรือทำงานบ้านที่เน้นการใช้สองมือประสานกัน เช่นจัดโต๊ะอาหารเด็ดผัก เตรียมอาหารสอนให้ใช้สองมือทำงานร่วมกัน เช่น ผูกและแก้ปมเชือกรองเท้า กลัดกระดุมเสื้อ ใส่เข็มขัด<br>
                    2. ชวนทำกิจกรรมที่ใช้เครื่องเขียนและอุปกรณ์ศิลปะหลายประเภท เพื่อช่วยพัฒนากล้ามเนื้อมัดเล็ก เช่น สีเทียน สีไม้ สีเมจิก (ไร้สารพิษ) การทำศิลปะโดยใช้วัสดุตามธรรมชาติและการผสมสีให้เป็นสีต่าง ๆ<br>
                    3. ชวนเด็กทำกิจกรรมที่ต้องใช้กรรไกร และสอนวิธีการใช้อย่างปลอดภัย (ใช้กรรไกรปลายมนสำหรับเด็ก) เริ่มจากการตัดเป็นเส้นตรง จากนั้นค่อยฝึกตัดเป็นรูปต่าง ๆ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 127 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 127 - บวกเลขเบื้องต้น ผลลัพธ์ไม่เกิน 10 (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 61 - 66 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q127_pass_mobile" name="q127_pass" value="1">
                <label class="form-check-label text-success" for="q127_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q127_fail_mobile" name="q127_fail" value="1">
                <label class="form-check-label text-danger" for="q127_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามคำถามบวกเลข เช่น 1 + 2 เท่ากับเท่าไร 5 + 5 เท่ากับเท่าไร</p>
              <p><strong>ผ่าน:</strong> หากเด็กสามารถรวมสิ่งของหรือนับนิ้วรวมกันโดยใช้จำนวน 1–5 และตอบถูกทั้ง 2 คำถาม ถือว่า ผ่าน โดยให้โอกาสตอบ 2 ครั้ง</p>
            </div>
            <div class="accordion" id="training127">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading127">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse127">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse127" class="accordion-collapse collapse" data-bs-parent="#training127">
                  <div class="accordion-body">
                    1. ชวนเด็กนับสิ่งของต่าง ๆ ที่อยู่รอบตัวในชีวิตประจำวัน เช่น ไม้หนีบผ้า โต๊ะ เก้าอี้ จาน ชามหรือนับของที่แม่ซื้อมาจากตลาด<br>
                    2. สอนให้เด็กรู้จักการบวก โดยนำสิ่งของชนิดเดียวกัน 2 จำนวนมารวมกัน เช่น มีไม้หนีบสีฟ้า 3 อัน และสีเหลือง 4 อัน รวมมีไม้หนีบทั้งหมดกี่อัน
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 128 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 128 - เด็กสามารถอธิบายหน้าที่หรือคุณสมบัติของสิ่งของได้อย่างน้อย 6 ชนิด (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 61 - 66 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q128_pass_mobile" name="q128_pass" value="1">
                <label class="form-check-label text-success" for="q128_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q128_fail_mobile" name="q128_fail" value="1">
                <label class="form-check-label text-danger" for="q128_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามเด็กด้วยคำถามปลายเปิด เช่น "...เป็นอย่างไร" ให้เด็กอธิบายถึงสิ่งของ 8 ชนิด ได้แก่ บ้าน บอล เก้าอี้ ส้ม จาน แมว ทะเล/คลอง หลังคำ/เพดาน โดยให้เด็กอธิบายตามคุณสมบัติอย่างน้อย 1 อย่างต่อสิ่งของแต่ละชนิด ได้แก่ การใช้งาน รูปร่าง ส่วนประกอบ และจัดอยู่ในประเภทอะไร</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถอธิบายคุณสมบัติของสิ่งของได้ถูกต้องอย่างน้อย 6 ชนิด</p>
            </div>
            <div class="accordion" id="training128">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading128">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse128">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse128" class="accordion-collapse collapse" data-bs-parent="#training128">
                  <div class="accordion-body">
                    1. พูดคุยกับเด็กเกี่ยวกับสิ่งต่าง ๆ รอบตัว ชี้ให้สังเกตรูปร่าง ลักษณะ การใช้งาน และส่วนประกอบของสิ่งของ<br>
                    2. อธิบายหน้าที่และคุณสมบัติของสิ่งต่าง ๆ ที่อยู่รอบตัวจากของจริงหรือรูปภาพให้เด็กฟังบ่อย ๆ เช่น "ลูกบอลกลม ๆ เอาไว้เตะ" "แมวเป็นสัตว์เลี้ยง มี 4 ขา ร้องเหมียว ๆ"<br>
                    3. กระตุ้นให้เด็กตอบคำถามเกี่ยวกับหน้าที่หรือคุณสมบัติของสิ่งของต่าง ๆ จนเด็กสามารถตอบได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 129 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 129 - ช่วยงานบ้าน(PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 61 - 66 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q129_pass_mobile" name="q129_pass" value="1">
                <label class="form-check-label text-success" for="q129_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q129_fail_mobile" name="q129_fail" value="1">
                <label class="form-check-label text-danger" for="q129_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามพ่อ แม่ ผู้ปกครอง หรือครู/ผู้ดูแลเด็กว่า เด็กช่วยงานบ้านง่าย ๆ ได้หรือไม่ เช่น เก็บที่นอน/พับผ้าห่ม ช่วยตากผ้า/เก็บผ้า เก็บของเล่น/ของใช้เข้าที่</p>
              <p><strong>ผ่าน:</strong> พ่อ แม่ ผู้ปกครอง หรือครู/ผู้ดูแลเด็ก ตอบได้ว่าเด็กช่วยเหลืองานบ้านได้อย่างน้อย 2 อย่าง</p>
            </div>
            <div class="accordion" id="training129">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading129">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse129">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse129" class="accordion-collapse collapse" data-bs-parent="#training129">
                  <div class="accordion-body">
                    1. ฝึกเด็กทำงานบ้านในสถานการณ์จริง โดยทำเป็นตัวอย่างและชักชวนเด็กทำ พร้อมทั้งจัดหาอุปกรณ์และงานที่เหมาะสมกับเด็ก เช่น ไม้กวาด ที่ตักผง ผ้าเช็ดพื้น เป็นต้น ให้เด็กกวาดบ้าน ถูบ้าน เช็ดเก้าอี้<br>
                    2. มอบหมายงานบ้านง่าย ๆ ที่เหมาะสมตามวัยให้แก่เด็ก โดยมีผู้ปกครองดูแลและช่วยเหลือเมื่อจำเป็น
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
      for (let i = 125; i <= 129; i++) {
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
      for (let i = 125; i <= 129; i++) {
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

      for (let i = 125; i <= 129; i++) {
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

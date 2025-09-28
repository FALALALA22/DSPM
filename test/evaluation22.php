<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '67-72';

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

    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 130-134)
    for ($i = 130; $i <= 134; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 130-134)
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
  <title>แบบประเมิน ช่วงอายุ 67 ถึง 72 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 67 - 72 เดือน
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
            <!-- ข้อ 130-134 สำหรับ 67-72 เดือน -->
            <tr>
              <td rowspan="5">64 - 72 เดือน</td>
              <td>130<br>
                  <input type="checkbox" id="q130_pass" name="q130_pass" value="1">
                  <label for="q130_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q130_fail" name="q130_fail" value="1">
                  <label for="q130_fail">ไม่ผ่าน</label><br>
              </td>
              <td>วิ่งหลบหลีกสิ่งกีดขวางได้ (GM)<br><br>
              <strong>อุปกรณ์</strong>  สิ่งกีดขวำง (เช่น เก้าอี้ กรวย กล่อง) ตั้งระยะห่าง 1 เมตร จำนวน 3 จุด
              </td>
              <td>
                ตั้งสิ่งกีดขวาง เช่น เก้าอี้ กรวย กล่อง ระยะห่าง 1 เมตร จำนวน 3 จุด ให้เด็กวิ่งหลบหลีกสิ่งกีดขวางแล้วกลับมาที่จุดเดิม<br>
                <strong>ผ่าน:</strong>  เด็กวิ่งหลบหลีกสิ่งกีดขวางได้โดยไม่หกล้มหรือชนสิ่งกีดขวาง
              </td>
              <td>
                1. ตั้งสิ่งกีดขวางในแนวตรง ระยะห่าง 1 เมตร จำนวน 3 จุด โดยบอกเด็กว่า “เรามาเล่นวิ่งอ้อมสิ่งกีดขวางกันเถอะ”<br>
                2. สาธิตวิธีวิ่งหลบหลีกสิ่งกีดขวางให้เด็กดู<br>
                3. จัดให้เด็กยืนบนพื้นราบและบอกให้วิ่งหลบหลีกสิ่งกีดขวาง แล้ววิ่งกลับมาที่จุดเดิม<br>
                4. ฝึกวิ่งซิกแซ็กหลบสิ่งกีดขวางกับเด็ก ชี้ให้เด็กสังเกตเส้นทาง<br>
                5. วิ่งไปพร้อมกับเด็ก<br>
                6. บอกเด็กให้รู้จักจังหวะในการวิ่ง เช่น พูดว่า “หยุด” พร้อมพาเด็กหยุดวิ่ง และพูดว่า “วิ่งอ้อม” พร้อมพาเด็กวิ่งอ้อมสิ่งกีดขวาง ทำซ้ำจนเด็กวิ่งได้เอง<br>
                7. เมื่อเด็กทำได้ ให้เพิ่มจำนวนสิ่งกีดขวางตามความเหมาะสม<br>
            </td>
            </tr>

            <tr>
              <td>131<br>
                  <input type="checkbox" id="q131_pass" name="q131_pass" value="1">
                  <label for="q131_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q131_fail" name="q131_fail" value="1">
                  <label for="q131_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ลอกรูปสามเหลี่ยม (FM)<br><br>
              <strong>อุปกรณ์:</strong> กระดาษแข็งที่มีภาพสามเหลี่ยมด้านเท่า ขนาด 1 นิ้ว, กระดาษ, ดินสอ
            </td>
            <td>
                วางกระดาษและดินสอข้างหน้าตัวเด็ก ให้เด็กดูรูปสามเหลี่ยม ∆ ชี้และบอกเด็กว่า “เขียนให้เหมือนรูปนี้” (ห้ามพูดคำว่า “สามเหลี่ยม” และไม่ต้องใช้นิ้วเขียนเป็น ∆) ให้โอกาสเด็กทำ 3 ครั้ง<br>
                <strong>ผ่าน:</strong> เด็กสามารถเขียนรูปสามเหลี่ยม ∆ ได้ โดยทำได้อย่างน้อย 2 ใน 3 ครั้ง
            </td>
            <td>
                1. ชี้และชวนเด็กดูสิ่งรอบตัวที่เป็นรูปสามเหลี่ยม เช่น หลังคาบ้าน, กระดาษสี่เหลี่ยมพับครึ่งทะแยงมุมเป็นสามเหลี่ยม, ผ้าพันคอ, ด้านข้างหมอน, ธงรูปสามเหลี่ยม<br>
                2. วาดรูปสี่เหลี่ยมแล้วขีดเส้นทะแยงมุมให้เป็นสามเหลี่ยม 2 อัน ให้เด็กดูแล้วให้เด็กวาดตาม<br>
                3. เขียนจุดสามจุดแล้วให้เด็กโยงเส้นระหว่างจุดเป็นรูปสามเหลี่ยม แล้วให้เด็กวาดเองโดยไม่ต้องจุดให้ก่อน<br>
                4. ชวนเด็กวาดรูปสามเหลี่ยมประกอบเป็นรูปต่าง ๆ และนำมาต่อเติมกับภาพที่เด็กวาด เช่น รูปดาว, ใบเรือ, หลังคาบ้าน, ผีเสื้อ, โบว์<br>
            </td>
            </tr>

            <tr>
              <td>132<br>
                  <input type="checkbox" id="q132_pass" name="q132_pass" value="1">
                  <label for="q132_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q132_fail" name="q132_fail" value="1">
                  <label for="q132_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ลบเลข (RL)<br><br>
              
            </td>
              <td>
               “แม่มีไข่ 5 ฟอง ทอดไข่ให้ลูกกินไปแล้ว 2 ฟอง เหลือไข่กี่ฟอง<br>  
               <strong>ผ่าน:</strong>  เด็กสามารถหักลบโดยนับนิ้วหรือสิ่งของออกจากจำนวนไม่เกิน 5 ได้ ให้โอกาสตอบ 2 ครั้ง
              </td>
              <td>
                 1. ชวนเด็กนับสิ่งของต่าง ๆ รอบตัวในชีวิตประจำวัน เช่น ของใช้ในบ้าน: ไม้หนีบผ้า, โต๊ะ, เก้าอี้, จาน, ชาม หรือของที่แม่ซื้อจากตลาด<br>
                 2. คุยและชี้ชวนให้เด็กรู้ว่า ของอะไรนับจำนวนได้ กับนับไม่ได้ เช่น เก้าอี้นับได้ ทรายนับไม่ได้ แต่ถ้าจะนับต้องใส่ในภาชนะ เช่น ใส่ทรายในถุง ใส่ข้าวสารในถุง แล้วนับเป็นจำนวนถุง<br>
                 3. ชวนเด็กเล่นเกมเกี่ยวกับตัวเลข เช่น เกมจับคู่ภาพกับจำนวน, เกมหาตัวเลขคู่, เกมหาตัวเลขคี่ และสังเกตเลขทะเบียนรถหรือสัญญาณไฟที่แสดงตัวเลขนับถอยหลัง<br>
                 4. สอนให้เด็กรู้จักการลบ โดยนำสิ่งของชนิดเดียวกันสองจำนวนมาหักลบกัน เช่น มีส้ม 5 ลูก กินไป 3 ลูก เหลือส้มกี่ลูก<br>
                 5. เล่นเกมที่ต้องมีการนับจำนวน เช่น นับคะแนนการบวก การลบ นับเดินหน้าและถอยหลัง<br>
                 6. เล่านิทานหรืออ่านหนังสือที่มีเนื้อหาเกี่ยวกับตัวเลข<br>
                 7. ชวนเด็กเปรียบเทียบจำนวนคน พืช สัตว์ หรือสิ่งของ<br>
             </td>
            </tr>

            <tr>
              <td>133<br>
                  <input type="checkbox" id="q133_pass" name="q133_pass" value="1">
                  <label for="q133_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q133_fail" name="q133_fail" value="1">
                  <label for="q133_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กสามารถบอกชื่อสิ่งของได้ 3 หมวด ได้แก่ สัตว์, เสื้อผ้า, อาหาร (EL)<br><br>
               <strong>อุปกรณ์:</strong> ชุดภาพสิ่งของ 3 หมวด
               <ul>
                <li>หมวดสัตว์: วัว, ไก่, ช้าง</li>
                <li>หมวดเสื้อผ้า: เสื้อยืด, กระโปรง, กางเกง</li>
                <li>หมวดอาหาร: ปลาทู, ไข่ดาว, ผัดผัก</li>
            </ul>
              </td>
              <td>
                 1. ผู้ทดสอบยกตัวอย่างให้เด็กดู เช่น จำนวนชาม ถ้วย เรียกว่าภาชนะ (ของใส่อาหารไว้กิน)<br>
                 2. ผู้ทดสอบเอารูปทั้ง 3 ชุดให้เด็กดูทีละชุด แล้วถามทีละชุดว่า “รูปทั้งหมดนี้คืออะไร”<br>
                <strong>ผ่าน:</strong> เด็กสามารถบอกประเภทสิ่งของได้ถูกต้อง ได้แก่ สัตว์, เสื้อผ้า, อาหาร (ให้โอกาสเด็กตอบชุดละ 1 ครั้ง)

              </td>
              <td>
                 1. ผู้ปกครองสอนให้เด็กรู้จักหมวดสิ่งของต่าง ๆ เช่น หมวดเสื้อผ้าคืออะไรบ้าง, หมวดสัตว์คืออะไรบ้าง, หมวดอาหารคืออะไรบ้าง<br>
                 2. เมื่อเด็กตอบได้ทั้ง 3 หมวดแล้ว สอนเด็กให้รู้จักหมวดอื่น ๆ รอบตัว เช่น หมวดอุปกรณ์การเรียน (เครื่องเขียน), หมวดเครื่องครัว (อุปกรณ์ทำอาหาร), หมวดเครื่องเรือน (เฟอร์นิเจอร์), หมวดเครื่องนอน (ของใช้สำหรับนอน), หมวดอุปกรณ์ทำความสะอาดบ้าน เป็นต้น<br>
                 3. ถามเด็กถึงเหตุผลในการจัดหมวดหมู่สิ่งของนั้น ๆ<br>
                 4. ฝึกบ่อย ๆ จนเด็กสามารถแยกประเภทสิ่งของได้เอง<br>
              </td>
            </tr>

            <tr>
              <td>134<br>
                  <input type="checkbox" id="q134_pass" name="q134_pass" value="1">
                  <label for="q134_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q134_fail" name="q134_fail" value="1">
                  <label for="q134_fail">ไม่ผ่าน</label><br>
              </td>
              <td>เด็กแปรงฟันได้ทั่วทั้งปาก (PS)<br><br>
              <strong>อุปกรณ์</strong> แปรงสีฟันส่วนตัวของเด็ก และยาสีฟัน
              </td>
              <td>
                ถามเด็กว่า 1. “เวลาแปรงฟัน หนูใช้อะไรบ้างคะ” 2. บีบยาสีฟันให้เด็กขนาดตามความกว้างของแปรง แล้วให้เด็กแปรงฟันให้ดู<br>
                <strong>ผ่าน:</strong> เด็กทำได้ทั้งข้อ 1 และข้อ 2 เด็กรู้จักอุปกรณ์ที่ใช้ในการแปรงฟัน ได้แก่ แปรงสีฟันและยาสีฟัน 
                2.เด็กสามารถแปรงฟันโดยขยับแปรงหน้า–หลังสั้น ๆ (Scrub) ครบทุกซี่ทุกด้าน เป็นเวลาอย่างน้อย 2 นาที
              </td>
              <td>
                1. พูดคุยและเล่านิทานเกี่ยวกับการแปรงฟัน ประโยชน์ของฟันสะอาด<br>
                2. ผู้ปกครองและเด็กช่วยกันจัดเตรียมอุปกรณ์แปรงฟัน<br>
                3. ผู้ปกครองบีบยาสีฟันผสมฟลูออไรด์ (1400–1500 ppm สำหรับเด็ก ≥3 ปี, 1000 ppm สำหรับเด็ก 0–3 ปี) ขนาดตามความยาว/ความกว้างของแปรง<br>
                4. ฝึกเด็กขยับแปรงหน้า–หลังสั้น ๆ ครบทุกซี่ทุกด้าน และแปรงลิ้น อย่างน้อย 2 นาที<br>
                5. ฝึกเด็กบ้วนฟองยาสีฟันออกครั้งเดียว หรือบ้วนปากด้วยน้ำเปล่า 1 ครั้ง<br>
                6. ผู้ปกครองตรวจความสะอาดฟันด้วยหลอดดูดน้ำตัดปลายมน หากไม่สะอาดให้แปรงซ้ำ<br>
                7. ผู้ปกครองเป็นแบบอย่างและดูแลให้เด็กแปรงฟันสม่ำเสมอ อย่างน้อย 2 ครั้งต่อวัน<br>
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
        <!-- Card ข้อที่ 130 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 130 - วิ่งหลบหลีกสิ่งกีดขวางได้ (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 67 - 72 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> สิ่งกีดขวำง (เช่น เก้าอี้ กรวย กล่อง) ตั้งระยะห่าง 1 เมตร จำนวน 3 จุด
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q130_pass_mobile" name="q130_pass" value="1">
                <label class="form-check-label text-success" for="q130_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q130_fail_mobile" name="q130_fail" value="1">
                <label class="form-check-label text-danger" for="q130_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ตั้งสิ่งกีดขวาง เช่น เก้าอี้ กรวย กล่อง ระยะห่าง 1 เมตร จำนวน 3 จุด ให้เด็กวิ่งหลบหลีกสิ่งกีดขวางแล้วกลับมาที่จุดเดิม</p>
              <p><strong>ผ่าน:</strong> เด็กวิ่งหลบหลีกสิ่งกีดขวางได้โดยไม่หกล้มหรือชนสิ่งกีดขวาง</p>
            </div>
            <div class="accordion" id="training130">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading130">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse130">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse130" class="accordion-collapse collapse" data-bs-parent="#training130">
                  <div class="accordion-body">
                    1. ตั้งสิ่งกีดขวางในแนวตรง ระยะห่าง 1 เมตร จำนวน 3 จุด โดยบอกเด็กว่า "เรามาเล่นวิ่งอ้อมสิ่งกีดขวางกันเถอะ"<br>
                    2. สาธิตวิธีวิ่งหลบหลีกสิ่งกีดขวางให้เด็กดู<br>
                    3. จัดให้เด็กยืนบนพื้นราบและบอกให้วิ่งหลบหลีกสิ่งกีดขวาง แล้ววิ่งกลับมาที่จุดเดิม<br>
                    4. ฝึกวิ่งซิกแซ็กหลบสิ่งกีดขวางกับเด็ก ชี้ให้เด็กสังเกตเส้นทาง<br>
                    5. วิ่งไปพร้อมกับเด็ก<br>
                    6. บอกเด็กให้รู้จักจังหวะในการวิ่ง เช่น พูดว่า "หยุด" พร้อมพาเด็กหยุดวิ่ง และพูดว่า "วิ่งอ้อม" พร้อมพาเด็กวิ่งอ้อมสิ่งกีดขวาง ทำซ้ำจนเด็กวิ่งได้เอง<br>
                    7. เมื่อเด็กทำได้ ให้เพิ่มจำนวนสิ่งกีดขวางตามความเหมาะสม
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 131 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 131 - ลอกรูปสามเหลี่ยม (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 67 - 72 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> กระดาษแข็งที่มีภาพสามเหลี่ยมด้านเท่า ขนาด 1 นิ้ว, กระดาษ, ดินสอ
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q131_pass_mobile" name="q131_pass" value="1">
                <label class="form-check-label text-success" for="q131_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q131_fail_mobile" name="q131_fail" value="1">
                <label class="form-check-label text-danger" for="q131_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">วางกระดาษและดินสอข้างหน้าตัวเด็ก ให้เด็กดูรูปสามเหลี่ยม ∆ ชี้และบอกเด็กว่า "เขียนให้เหมือนรูปนี้" (ห้ามพูดคำว่า "สามเหลี่ยม" และไม่ต้องใช้นิ้วเขียนเป็น ∆) ให้โอกาสเด็กทำ 3 ครั้ง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถเขียนรูปสามเหลี่ยม ∆ ได้ โดยทำได้อย่างน้อย 2 ใน 3 ครั้ง</p>
            </div>
            <div class="accordion" id="training131">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading131">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse131">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse131" class="accordion-collapse collapse" data-bs-parent="#training131">
                  <div class="accordion-body">
                    1. ชี้และชวนเด็กดูสิ่งรอบตัวที่เป็นรูปสามเหลี่ยม เช่น หลังคาบ้าน, กระดาษสี่เหลี่ยมพับครึ่งทะแยงมุมเป็นสามเหลี่ยม, ผ้าพันคอ, ด้านข้างหมอน, ธงรูปสามเหลี่ยม<br>
                    2. วาดรูปสี่เหลี่ยมแล้วขีดเส้นทะแยงมุมให้เป็นสามเหลี่ยม 2 อัน ให้เด็กดูแล้วให้เด็กวาดตาม<br>
                    3. เขียนจุดสามจุดแล้วให้เด็กโยงเส้นระหว่างจุดเป็นรูปสามเหลี่ยม แล้วให้เด็กวาดเองโดยไม่ต้องจุดให้ก่อน<br>
                    4. ชวนเด็กวาดรูปสามเหลี่ยมประกอบเป็นรูปต่าง ๆ และนำมาต่อเติมกับภาพที่เด็กวาด เช่น รูปดาว, ใบเรือ, หลังคาบ้าน, ผีเสื้อ, โบว์
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 132 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 132 - ลบเลข (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 67 - 72 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q132_pass_mobile" name="q132_pass" value="1">
                <label class="form-check-label text-success" for="q132_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q132_fail_mobile" name="q132_fail" value="1">
                <label class="form-check-label text-danger" for="q132_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">"แม่มีไข่ 5 ฟอง ทอดไข่ให้ลูกกินไปแล้ว 2 ฟอง เหลือไข่กี่ฟอง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถหักลบโดยนับนิ้วหรือสิ่งของออกจากจำนวนไม่เกิน 5 ได้ ให้โอกาสตอบ 2 ครั้ง</p>
            </div>
            <div class="accordion" id="training132">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading132">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse132">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse132" class="accordion-collapse collapse" data-bs-parent="#training132">
                  <div class="accordion-body">
                    1. ชวนเด็กนับสิ่งของต่าง ๆ รอบตัวในชีวิตประจำวัน เช่น ของใช้ในบ้าน: ไม้หนีบผ้า, โต๊ะ, เก้าอี้, จาน, ชาม หรือของที่แม่ซื้อจากตลาด<br>
                    2. คุยและชี้ชวนให้เด็กรู้ว่า ของอะไรนับจำนวนได้ กับนับไม่ได้ เช่น เก้าอี้นับได้ ทรายนับไม่ได้ แต่ถ้าจะนับต้องใส่ในภาชนะ เช่น ใส่ทรายในถุง ใส่ข้าวสารในถุง แล้วนับเป็นจำนวนถุง<br>
                    3. ชวนเด็กเล่นเกมเกี่ยวกับตัวเลข เช่น เกมจับคู่ภาพกับจำนวน, เกมหาตัวเลขคู่, เกมหาตัวเลขคี่ และสังเกตเลขทะเบียนรถหรือสัญญาณไฟที่แสดงตัวเลขนับถอยหลัง<br>
                    4. สอนให้เด็กรู้จักการลบ โดยนำสิ่งของชนิดเดียวกันสองจำนวนมาหักลบกัน เช่น มีส้ม 5 ลูก กินไป 3 ลูก เหลือส้มกี่ลูก<br>
                    5. เล่นเกมที่ต้องมีการนับจำนวน เช่น นับคะแนนการบวก การลบ นับเดินหน้าและถอยหลัง<br>
                    6. เล่านิทานหรืออ่านหนังสือที่มีเนื้อหาเกี่ยวกับตัวเลข<br>
                    7. ชวนเด็กเปรียบเทียบจำนวนคน พืช สัตว์ หรือสิ่งของ
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 133 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 133 - เด็กสามารถบอกชื่อสิ่งของได้ 3 หมวด ได้แก่ สัตว์, เสื้อผ้า, อาหาร (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 67 - 72 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> ชุดภาพสิ่งของ 3 หมวด
              <ul>
                <li>หมวดสัตว์: วัว, ไก่, ช้าง</li>
                <li>หมวดเสื้อผ้า: เสื้อยืด, กระโปรง, กางเกง</li>
                <li>หมวดอาหาร: ปลาทู, ไข่ดาว, ผัดผัก</li>
              </ul>
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q133_pass_mobile" name="q133_pass" value="1">
                <label class="form-check-label text-success" for="q133_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q133_fail_mobile" name="q133_fail" value="1">
                <label class="form-check-label text-danger" for="q133_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ผู้ทดสอบยกตัวอย่างให้เด็กดู เช่น จำนวนชาม ถ้วย เรียกว่าภาชนะ (ของใส่อาหารไว้กิน)<br>
              2. ผู้ทดสอบเอารูปทั้ง 3 ชุดให้เด็กดูทีละชุด แล้วถามทีละชุดว่า "รูปทั้งหมดนี้คืออะไร"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถบอกประเภทสิ่งของได้ถูกต้อง ได้แก่ สัตว์, เสื้อผ้า, อาหาร (ให้โอกาสเด็กตอบชุดละ 1 ครั้ง)</p>
            </div>
            <div class="accordion" id="training133">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading133">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse133">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse133" class="accordion-collapse collapse" data-bs-parent="#training133">
                  <div class="accordion-body">
                    1. ผู้ปกครองสอนให้เด็กรู้จักหมวดสิ่งของต่าง ๆ เช่น หมวดเสื้อผ้าคืออะไรบ้าง, หมวดสัตว์คืออะไรบ้าง, หมวดอาหารคืออะไรบ้าง<br>
                    2. เมื่อเด็กตอบได้ทั้ง 3 หมวดแล้ว สอนเด็กให้รู้จักหมวดอื่น ๆ รอบตัว เช่น หมวดอุปกรณ์การเรียน (เครื่องเขียน), หมวดเครื่องครัว (อุปกรณ์ทำอาหาร), หมวดเครื่องเรือน (เฟอร์นิเจอร์), หมวดเครื่องนอน (ของใช้สำหรับนอน), หมวดอุปกรณ์ทำความสะอาดบ้าน เป็นต้น<br>
                    3. ถามเด็กถึงเหตุผลในการจัดหมวดหมู่สิ่งของนั้น ๆ<br>
                    4. ฝึกบ่อย ๆ จนเด็กสามารถแยกประเภทสิ่งของได้เอง
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 134 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 134 - เด็กแปรงฟันได้ทั่วทั้งปาก (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 67 - 72 เดือน
            </div>
            <div class="mb-3">
              <strong>อุปกรณ์:</strong> แปรงสีฟันส่วนตัวของเด็ก และยาสีฟัน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q134_pass_mobile" name="q134_pass" value="1">
                <label class="form-check-label text-success" for="q134_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q134_fail_mobile" name="q134_fail" value="1">
                <label class="form-check-label text-danger" for="q134_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามเด็กว่า 1. "เวลาแปรงฟัน หนูใช้อะไรบ้างคะ" 2. บีบยาสีฟันให้เด็กขนาดตามความกว้างของแปรง แล้วให้เด็กแปรงฟันให้ดู</p>
              <p><strong>ผ่าน:</strong> เด็กทำได้ทั้งข้อ 1 และข้อ 2 เด็กรู้จักอุปกรณ์ที่ใช้ในการแปรงฟัน ได้แก่ แปรงสีฟันและยาสีฟัน 2.เด็กสามารถแปรงฟันโดยขยับแปรงหน้า–หลังสั้น ๆ (Scrub) ครบทุกซี่ทุกด้าน เป็นเวลาอย่างน้อย 2 นาที</p>
            </div>
            <div class="accordion" id="training134">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading134">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse134">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse134" class="accordion-collapse collapse" data-bs-parent="#training134">
                  <div class="accordion-body">
                    1. พูดคุยและเล่านิทานเกี่ยวกับการแปรงฟัน ประโยชน์ของฟันสะอาด<br>
                    2. ผู้ปกครองและเด็กช่วยกันจัดเตรียมอุปกรณ์แปรงฟัน<br>
                    3. ผู้ปกครองบีบยาสีฟันผสมฟลูออไรด์ (1400–1500 ppm สำหรับเด็ก ≥3 ปี, 1000 ppm สำหรับเด็ก 0–3 ปี) ขนาดตามความยาว/ความกว้างของแปรง<br>
                    4. ฝึกเด็กขยับแปรงหน้า–หลังสั้น ๆ ครบทุกซี่ทุกด้าน และแปรงลิ้น อย่างน้อย 2 นาที<br>
                    5. ฝึกเด็กบ้วนฟองยาสีฟันออกครั้งเดียว หรือบ้วนปากด้วยน้ำเปล่า 1 ครั้ง<br>
                    6. ผู้ปกครองตรวจความสะอาดฟันด้วยหลอดดูดน้ำตัดปลายมน หากไม่สะอาดให้แปรงซ้ำ<br>
                    7. ผู้ปกครองเป็นแบบอย่างและดูแลให้เด็กแปรงฟันสม่ำเสมอ อย่างน้อย 2 ครั้งต่อวัน
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
      for (let i = 130; i <= 134; i++) {
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
      for (let i = 130; i <= 134; i++) {
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

      for (let i = 130; i <= 134; i++) {
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

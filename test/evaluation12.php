<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับข้อมูลจาก URL
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$age_range = isset($_GET['age_range']) ? $_GET['age_range'] : '25-29';

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
    
    // รับข้อมูลการประเมินจากฟอร์ม (ข้อ 65-69)
    for ($i = 65; $i <= 69; $i++) {
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
    $total_questions = 5; // แบบประเมินมีทั้งหมด 5 ข้อ (ข้อ 65-69)
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
  <title>แบบประเมิน ช่วงอายุ 25 ถึง 29 เดือน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
      เด็ก: <?php echo htmlspecialchars($child['chi_child_name']); ?> | ช่วงอายุ: 25 - 29 เดือน
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
            <!-- ข้อ 65-69 สำหรับ 25-29 เดือน -->
            <tr>
              <td rowspan="5">25 - 29 เดือน</td>
              <td>65<br>
                  <input type="checkbox" id="q65_pass" name="q65_pass" value="1">
                  <label for="q65_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q65_fail" name="q65_fail" value="1">
                  <label for="q65_fail">ไม่ผ่าน</label><br>
              </td>
              <td>กระโดดเท้าพ้นพื้นทั้ง 2 ข้าง (GM)<br><br>
              </td>
              <td>
                กระโดดให้เด็กดู แล้วบอกให้เด็กทำตามโดยอาจช่วยจับมือเด็กทั้ง 2 ข้าง<br>
                <strong>ผ่าน:</strong> เด็กสามารถกระโดดได้เอง อาจไม่ต้องยกเท้าพ้นพื้นพร้อมกันทั้ง 2 ข้าง
              </td>
              <td>
               1. จับมือเด็กไว้ทั้ง 2 ข้าง แล้วฝึกกระโดดลงจากบันไดขั้นที่ติดกับพื้นหรือจากพื้นต่างระดับ <br>
               2. หลังจากนั้นให้เริ่มฝึกกระโดดที่พื้นโดยการจับมือทั้ง 2 ข้างของเด็กไว้ ย่อตัวลงพร้อมกับเด็กแล้วบอกให้เด็กกระโดด ฝึกหลาย ๆ ครั้ง
               จนเด็กมั่นใจและสนุก จึงปล่อยให้กระโดดเล่นเอง<br>
               3. ควรระมัดระวังเรื่องความปลอดภัยในระหว่างการกระโดด
              </td>
            </tr>

            <tr>
              <td>66<br>
                  <input type="checkbox" id="q66_pass" name="q66_pass" value="1">
                  <label for="q66_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q66_fail" name="q66_fail" value="1">
                  <label for="q66_fail">ไม่ผ่าน</label><br>
              </td>
              <td>แก้ปัญหาง่าย ๆ โดยใช้เครื่องมือด้วยตัวเอง (FM)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ว่าเด็กสามารถแก้ปัญหาง่าย ๆ ด้วยตนเองโดยใช้เครื่องมือได้หรือไม่ เช่น เวลาเด็ก
                หยิบของไม่ถึงเด็กทำอย่างไร ใช้ไม้เขี่ยใช้เก้าอี้ปีนไปหยิบของ เป็นต้น <br>
                <strong>ผ่าน:</strong>  เด็กสามารถแก้ปัญหาง่าย ๆ ด้วยตัวเอง โดยใช้เครื่องมือ
              </td>
              <td>
                1. ให้โอกาสเด็กแก้ปัญหาอื่น ๆ ด้วยตนเอง เช่น นำไม้เขี่ยของใต้เตียงใต้โต๊ะออกมา หรือเล่นกิจกรรมอื่น ๆ ที่ฝึกการแก้ไขปัญหา 
                หรือเอาเก้าอี้มาต่อเพื่อหยิบของที่อยู่สูง<br>
                2. ถ้าเด็กทำไม่ได้ ให้วางสิ่งของที่สามารถใช้แก้ปัญหาได้ไว้ใกล้เด็ก และกระตุ้นให้เด็กคิดแก้ปัญหา เช่น เด็กหยิบของใต้โต๊ะออกมาไม่ได้ ผู้ปกครอง
                วางไม้บรรทัดไว้ใกล้เด็กและถามเด็กว่า “มีไม้บรรทัด จะทำอย่างไรดี”<br>
                3. ถ้าเด็กทำไม่ได้ ทำให้เด็กดูในสถานการณ์ต่าง ๆ เช่น ผู้ปกครองนำไม้บรรทัดเขี่ยของให้เด็กดู<br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> รู้จักดัดแปลงการแก้ปัญหาที่หลากหลาย ผ่านการเรียนรู้จากผู้ใหญ่ ช่วยเพิ่มความภาคภูมิใจในตนเอง</span>
              </td>
            </tr>

            <tr>
              <td>67<br>
                  <input type="checkbox" id="q67_pass" name="q67_pass" value="1">
                  <label for="q67_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q67_fail" name="q67_fail" value="1">
                  <label for="q67_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ชี้อวัยวะ 7 ส่วน (RL)<br><br>
            </td>
              <td>
                1. ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กก่อนว่า เด็กรู้จักอวัยวะของร่างกายส่วนไหนบ้าง<br>
                2. ถามเด็กว่า “…อยู่ไหน” โดยถาม 8 ส่วน<br>
                <strong>ผ่าน:</strong> เด็กสามารถชี้อวัยวะได้ถูกต้อง 7 ใน 8 ส่วน
              </td>
              <td>
                1. เริ่มฝึกจากการชี้อวัยวะของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กให้เด็กดู<br>
                2. หลังจากนั้นชี้ชวนให้เด็กทำตาม โดยชี้อวัยวะของตัวเอง<br>
                3. ถ้าเด็กชี้ไม่ได้ให้จับมือเด็กชี้ให้ถูกต้อง และลดการช่วยเหลือลงจนเด็กสามารถชี้ได้เองโดยอาจใช้เพลงเข้ามาประกอบในการทำกิจกรรม
              </td>
            </tr>

            <tr>
              <td>68<br>
                  <input type="checkbox" id="q68_pass" name="q68_pass" value="1">
                  <label for="q68_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q68_fail" name="q68_fail" value="1">
                  <label for="q68_fail">ไม่ผ่าน</label><br>
              </td>
              <td>พูดตอบรับและปฏิเสธได้ (EL)<br><br>
              </td>
              <td>
                1. ถามคำถามเพื่อให้เด็กตอบรับหรือปฏิเสธ เช่น ดื่มนมไหม ฟังนิทานไหม หรือเอาของเล่นไหม (คะ/ครับ) <br>
                2. ถามเด็ก 3 - 4 คำถาม จนแน่ใจว่าเด็กรู้จักความแตกต่างของคำตอบรับและปฏิเสธ หรือถ้าเด็กไม่ยอมตอบ ให้ถามจาก
                พ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า เด็กสามารถพูดตอบรับและปฏิเสธได้หรือไม่<br>
                <strong>ผ่าน:</strong>  เด็กสามารถพูดตอบรับและปฏิเสธได้เช่น ดื่ม ฟัง เอา ไม่ (ค่ะ/ครับ)
              </td>
              <td>
                1. พูดคุย เล่าเรื่องเกี่ยวกับการตอบรับหรือปฏิเสธร่วมกับเด็กเพื่อให้เด็กเข้าใจ เช่น หากเด็กไม่ต้องการ ให้ตอบว่า ไม่ครับ ไม่เอาค่ะ<br>
                2. ถามคำถามเพื่อให้เด็กตอบรับหรือปฏิเสธ เช่น ดื่มนมไหม เล่นรถไหม อ่านหนังสือไหม กินข้าวไหม ร้องเพลงไหม กระตุ้นให้เด็กตอบ
                รับหรือปฏิเสธคำชวนต่างๆข้างต้น รอจนแน่ใจว่าเด็กตอบรับหรือปฏิเสธคำชวนต่างๆ จึงตอบสนองสิ่งที่เด็กต้องการ ถ้าเด็กตอบไม่ได้
                ให้ตอบนำและถามเด็กซ้ำ เพื่อช่วยให้เด็กบอกความต้องการได้ <br>
                <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกเลือกสิ่งที่จำได้และทักษะที่มีอยู่ มาใช้สื่อความหมายได้อย่างเหมาะสม</span>
              </td>
            </tr>

            <tr>
              <td>69<br>
                  <input type="checkbox" id="q69_pass" name="q69_pass" value="1">
                  <label for="q69_pass">ผ่าน</label><br>
                  <input type="checkbox" id="q69_fail" name="q69_fail" value="1">
                  <label for="q69_fail">ไม่ผ่าน</label><br>
              </td>
              <td>ล้างและเช็ดมือได้เอง (PS)<br><br>
                </td>
              <td>
                ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า “เด็กสามารถล้างมือและเช็ดมือได้เองหรือไม่”<br>
                <strong>ผ่าน:</strong> เด็กสามารถล้างมือและเช็ดมือได้เอง 
                (โดยผู้ใหญ่อาจจะช่วยหยิบอุปกรณ์เปิดก๊อกน้ำ หรือราดน้ำให้)
              </td>
              <td>
               พาเด็กล้างมือก่อนรับประทานอาหารทุกครั้ง โดยทำให้ดูเป็นตัวอย่างแล้วช่วยจับมือเด็กทำตามขั้นตอนต่อไปนี้ เปิดก๊อกน้ำหรือตักน้ำใส่ขัน แล้วหยิบสบู่ เอาน้ำราดที่มือและสบู่ ฟอกสบู่ให้เกิดฟอง
               แล้ววางสบู่ไว้ที่เดิม ถูมือที่ฟอกสบู่ให้ทั่วแล้วล้างมือด้วยน้ำเปล่าจนสะอาด นำผ้าเช็ดมือมาเช็ดมือให้แห้ง ลดการช่วยเหลือลงทีละ
               ขั้นตอนจนเด็กล้างและเช็ดมือได้เอง
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
        <!-- Card ข้อที่ 65 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 65 - กระโดดเท้าพ้นพื้นทั้ง 2 ข้าง (GM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 25 - 29 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q65_pass_mobile" name="q65_pass" value="1">
                <label class="form-check-label text-success" for="q65_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q65_fail_mobile" name="q65_fail" value="1">
                <label class="form-check-label text-danger" for="q65_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">กระโดดให้เด็กดู แล้วบอกให้เด็กทำตามโดยอาจช่วยจับมือเด็กทั้ง 2 ข้าง</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถกระโดดได้เอง อาจไม่ต้องยกเท้าพ้นพื้นพร้อมกันทั้ง 2 ข้าง</p>
            </div>
            <div class="accordion" id="training65">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading65">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse65">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse65" class="accordion-collapse collapse" data-bs-parent="#training65">
                  <div class="accordion-body">
                    1. จับมือเด็กไว้ทั้ง 2 ข้าง แล้วฝึกกระโดดลงจากบันไดขั้นที่ติดกับพื้นหรือจากพื้นต่างระดับ<br>
                    2. หลังจากนั้นให้เริ่มฝึกกระโดดที่พื้นโดยการจับมือทั้ง 2 ข้างของเด็กไว้ ย่อตัวลงพร้อมกับเด็กแล้วบอกให้เด็กกระโดด ฝึกหลาย ๆ ครั้ง จนเด็กมั่นใจและสนุก จึงปล่อยให้กระโดดเล่นเอง<br>
                    3. ควรระมัดระวังเรื่องความปลอดภัยในระหว่างการกระโดด
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 66 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 66 - แก้ปัญหาง่าย ๆ โดยใช้เครื่องมือด้วยตัวเอง (FM)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 25 - 29 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q66_pass_mobile" name="q66_pass" value="1">
                <label class="form-check-label text-success" for="q66_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q66_fail_mobile" name="q66_fail" value="1">
                <label class="form-check-label text-danger" for="q66_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็ก ว่าเด็กสามารถแก้ปัญหาง่าย ๆ 
                ด้วยตนเองโดยใช้เครื่องมือได้หรือไม่ เช่น เวลาเด็กหยิบของไม่ถึงเด็กทำอย่างไร ใช้ไม้เขี่ยใช้เก้าอี้ปีนไปหยิบของ เป็นต้น</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถแก้ปัญหาง่าย ๆ ด้วยตัวเอง โดยใช้เครื่องมือ</p>
            </div>
            <div class="accordion" id="training66">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading66">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse66">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse66" class="accordion-collapse collapse" data-bs-parent="#training66">
                  <div class="accordion-body">
                    1. ให้โอกาสเด็กแก้ปัญหาอื่น ๆ ด้วยตนเอง เช่น นำไม้เขี่ยของใต้เตียงใต้โต๊ะออกมา หรือเล่นกิจกรรมอื่น ๆ ที่ฝึกการแก้ไขปัญหา หรือเอาเก้าอี้มาต่อเพื่อหยิบของที่อยู่สูง<br>
                    2. ถ้าเด็กทำไม่ได้ ให้วางสิ่งของที่สามารถใช้แก้ปัญหาได้ไว้ใกล้เด็ก และกระตุ้นให้เด็กคิดแก้ปัญหา เช่น เด็กหยิบของใต้โต๊ะออกมาไม่ได้ ผู้ปกครองวางไม้บรรทัดไว้ใกล้เด็กและถามเด็กว่า "มีไม้บรรทัด จะทำอย่างไรดี"<br>
                    3. ถ้าเด็กทำไม่ได้ ทำให้เด็กดูในสถานการณ์ต่าง ๆ เช่น ผู้ปกครองนำไม้บรรทัดเขี่ยของให้เด็กดู<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> รู้จักดัดแปลงการแก้ปัญหาที่หลากหลาย ผ่านการเรียนรู้จากผู้ใหญ่ ช่วยเพิ่มความภาคภูมิใจในตนเอง</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 67 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 67 - ชี้อวัยวะ 7 ส่วน (RL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 25 - 29 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q67_pass_mobile" name="q67_pass" value="1">
                <label class="form-check-label text-success" for="q67_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q67_fail_mobile" name="q67_fail" value="1">
                <label class="form-check-label text-danger" for="q67_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กก่อนว่า เด็กรู้จักอวัยวะของร่างกายส่วนไหนบ้าง<br>
              2. ถามเด็กว่า "…อยู่ไหน" โดยถาม 8 ส่วน</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถชี้อวัยวะได้ถูกต้อง 7 ใน 8 ส่วน</p>
            </div>
            <div class="accordion" id="training67">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading67">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse67">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse67" class="accordion-collapse collapse" data-bs-parent="#training67">
                  <div class="accordion-body">
                    1. เริ่มฝึกจากการชี้อวัยวะของพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กให้เด็กดู<br>
                    2. หลังจากนั้นชี้ชวนให้เด็กทำตาม โดยชี้อวัยวะของตัวเอง<br>
                    3. ถ้าเด็กชี้ไม่ได้ให้จับมือเด็กชี้ให้ถูกต้อง และลดการช่วยเหลือลงจนเด็กสามารถชี้ได้เองโดยอาจใช้เพลงเข้ามาประกอบในการทำกิจกรรม
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 68 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 68 - พูดตอบรับและปฏิเสธได้ (EL)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 25 - 29 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q68_pass_mobile" name="q68_pass" value="1">
                <label class="form-check-label text-success" for="q68_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q68_fail_mobile" name="q68_fail" value="1">
                <label class="form-check-label text-danger" for="q68_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">1. ถามคำถามเพื่อให้เด็กตอบรับหรือปฏิเสธ เช่น ดื่มนมไหม ฟังนิทานไหม หรือเอาของเล่นไหม (คะ/ครับ)<br>
              2. ถามเด็ก 3 - 4 คำถาม จนแน่ใจว่าเด็กรู้จักความแตกต่างของคำตอบรับและปฏิเสธ หรือถ้าเด็กไม่ยอมตอบ ให้ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า เด็กสามารถพูดตอบรับและปฏิเสธได้หรือไม่</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถพูดตอบรับและปฏิเสธได้เช่น ดื่ม ฟัง เอา ไม่ (ค่ะ/ครับ)</p>
            </div>
            <div class="accordion" id="training68">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading68">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse68">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse68" class="accordion-collapse collapse" data-bs-parent="#training68">
                  <div class="accordion-body">
                    1. พูดคุย เล่าเรื่องเกี่ยวกับการตอบรับหรือปฏิเสธร่วมกับเด็กเพื่อให้เด็กเข้าใจ เช่น หากเด็กไม่ต้องการ ให้ตอบว่า ไม่ครับ ไม่เอาค่ะ<br>
                    2. ถามคำถามเพื่อให้เด็กตอบรับหรือปฏิเสธ เช่น ดื่มนมไหม เล่นรถไหม อ่านหนังสือไหม กินข้าวไหม ร้องเพลงไหม กระตุ้นให้เด็กตอบรับหรือปฏิเสธคำชวนต่างๆข้างต้น รอจนแน่ใจว่าเด็กตอบรับหรือปฏิเสธคำชวนต่างๆ จึงตอบสนองสิ่งที่เด็กต้องการ ถ้าเด็กตอบไม่ได้ให้ตอบนำและถามเด็กซ้ำ เพื่อช่วยให้เด็กบอกความต้องการได้<br>
                    <span style="color: green;"><strong>วัตถุประสงค์:</strong> เพื่อฝึกเลือกสิ่งที่จำได้และทักษะที่มีอยู่ มาใช้สื่อความหมายได้อย่างเหมาะสม</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card ข้อที่ 69 -->
        <div class="card mb-4 shadow-sm">
          <div class="card-header bgeva1 text-white">
            <h5 class="mb-0">ข้อที่ 69 - ล้างและเช็ดมือได้เอง (PS)</h5>
          </div>
          <div class="card-body bg-white">
            <div class="mb-3">
              <strong>อายุ:</strong> 25 - 29 เดือน
            </div>
            <div class="mb-3">
              <strong>ผลการประเมิน:</strong><br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q69_pass_mobile" name="q69_pass" value="1">
                <label class="form-check-label text-success" for="q69_pass_mobile">
                  <strong>ผ่าน</strong>
                </label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="q69_fail_mobile" name="q69_fail" value="1">
                <label class="form-check-label text-danger" for="q69_fail_mobile">
                  <strong>ไม่ผ่าน</strong>
                </label>
              </div>
            </div>
            <div class="mb-3">
              <strong>วิธีประเมิน:</strong><br>
              <p class="text-muted">ถามจากพ่อแม่ ผู้ปกครองหรือผู้ดูแลเด็กว่า "เด็กสามารถล้างมือและเช็ดมือได้เองหรือไม่"</p>
              <p><strong>ผ่าน:</strong> เด็กสามารถล้างมือและเช็ดมือได้เอง (โดยผู้ใหญ่อาจจะช่วยหยิบอุปกรณ์เปิดก๊อกน้ำ หรือราดน้ำให้)</p>
            </div>
            <div class="accordion" id="training69">
              <div class="accordion-item">
                <h2 class="accordion-header" id="heading69">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse69">
                    <strong>วิธีฝึกทักษะ</strong>
                  </button>
                </h2>
                <div id="collapse69" class="accordion-collapse collapse" data-bs-parent="#training69">
                  <div class="accordion-body">
                    พาเด็กล้างมือก่อนรับประทานอาหารทุกครั้ง โดยทำให้ดูเป็นตัวอย่างแล้วช่วยจับมือเด็กทำตามขั้นตอนต่อไปนี้ เปิดก๊อกน้ำหรือตักน้ำใส่ขัน แล้วหยิบสบู่ เอาน้ำราดที่มือและสบู่ ฟอกสบู่ให้เกิดฟอง แล้ววางสบู่ไว้ที่เดิม ถูมือที่ฟอกสบู่ให้ทั่วแล้วล้างมือด้วยน้ำเปล่าจนสะอาด นำผ้าเช็ดมือมาเช็ดมือให้แห้ง ลดการช่วยเหลือลงทีละขั้นตอนจนเด็กล้างและเช็ดมือได้เอง
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
      for (let i = 65; i <= 69; i++) {
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
      for (let i = 65; i <= 69; i++) {
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

      for (let i = 65; i <= 69; i++) {
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

<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// ดึงรายการโรงพยาบาลเพื่อแสดงในฟอร์ม
$hospitals = array();
$hsql = "SELECT hosp_shph_id, hosp_name FROM hospitals ORDER BY hosp_name";
$hres = $conn->query($hsql);
if ($hres) {
  while ($hr = $hres->fetch_assoc()) {
    $hospitals[] = $hr;
  }
}

// หากเป็น staff ให้ดึงรายการผู้ปกครองเพื่อให้เลือกผูกกับเด็ก
$users = array();
if ($user['user_role'] === 'staff') {
  $usql = "SELECT user_id, user_fname, user_lname FROM users WHERE user_role = 'user' AND hosp_shph_id = ? ORDER BY user_fname, user_lname";
  $ustmt = $conn->prepare($usql);
  if ($ustmt) {
    $ustmt->bind_param('i', $user['hosp_shph_id']);
    $ustmt->execute();
    $ures = $ustmt->get_result();
    if ($ures) {
      while ($ur = $ures->fetch_assoc()) {
        $users[] = $ur;
      }
    }
    $ustmt->close();
  }
}

// ถ้ามีโรงพยาบาลของผู้ใช้ ให้ดึงสาขาเริ่มต้น
$user_hosp = $user['hosp_shph_id'] ?? null;

// ถ้ามีการส่งข้อมูลมาแบบ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $child_name = trim($_POST['child_name']);
    $date_of_birth = $_POST['date_of_birth'];
  // อายุจะคำนวณจากวันเกิด (chi_date_of_birth เป็น source-of-truth)
  $age_years = 0;
  $age_months = 0;
    
    $errors = array();
    $chi_hospital = isset($_POST['hosp_shph_id']) && $_POST['hosp_shph_id'] !== '' ? intval($_POST['hosp_shph_id']) : null;

    // ถ้าผู้ใช้ไม่ได้เลือกโรงพยาบาล ให้ใช้โรงพยาบาลของผู้ปกครองเป็นค่าเริ่มต้น
    if (is_null($chi_hospital) && !empty($user_hosp)) {
      $chi_hospital = intval($user_hosp);
    }

    // กำหนดผู้เป็นเจ้าของข้อมูลเด็ก (user_id) - โดยปกติเป็นผู้ใช้ปัจจุบัน
    $owner_user_id = $user['user_id'];
    if ($user['user_role'] === 'staff') {
      $owner_user_id = isset($_POST['owner_user_id']) && $_POST['owner_user_id'] !== '' ? intval($_POST['owner_user_id']) : null;
      if (is_null($owner_user_id)) {
        $errors[] = "กรุณาเลือกผู้ปกครองสำหรับเด็ก";
      }
    }
    
    // ตรวจสอบความถูกต้องของข้อมูล
    if (empty($child_name)) {
        $errors[] = "กรุณากรอกชื่อและนามสกุลเด็ก";
    }
    if (empty($date_of_birth)) {
        $errors[] = "กรุณากรอกวันเกิด";
    }
    // ไม่ใช้ค่าที่ผู้ใช้ป้อนสำหรับอายุเพื่อป้องกันความไม่สอดคล้อง
    
    // จัดการไฟล์รูปภาพ
    $photo_path = null;
    if (isset($_FILES['child_photo']) && $_FILES['child_photo']['error'] === UPLOAD_ERR_OK) {
      // ไดเรกทอรีบนไฟล์ซิสเต็ม
      $upload_dir_fs = __DIR__ . '/../uploads/children/';
      if (!is_dir($upload_dir_fs)) {
        mkdir($upload_dir_fs, 0755, true);
      }
        $file_extension = strtolower(pathinfo($_FILES['child_photo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif');
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "ไฟล์รูปภาพต้องเป็น JPG, JPEG, PNG หรือ GIF เท่านั้น";
        } else {
            // สร้างชื่อไฟล์ใหม่เพื่อป้องกันชื่อซ้ำ
            $new_filename = 'child_' . $user['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path_fs = $upload_dir_fs . $new_filename;
            
            if (move_uploaded_file($_FILES['child_photo']['tmp_name'], $upload_path_fs)) {
              $photo_path = 'uploads/children/' . $new_filename;
            } else {
                $errors[] = "เกิดข้อผิดพลาดในการอัพโหลดรูปภาพ";
            }
        }
    }
    
    // ถ้าไม่มีข้อผิดพลาด ทำการบันทึกข้อมูล
    if (empty($errors)) {
          // คำนวณอายุจากวันเกิด (ใช้เป็น source-of-truth)
          require_once __DIR__ . '/../includes/utils.php';
          $age = calculateAgeFromDOB($date_of_birth);
          $age_years = $age['years'];
          $age_months = $age['months'];

          // หากไม่มีรูป ให้เป็นค่าว่างเพื่อ bind
          $photo_path = $photo_path ?: '';

          $insert_sql = "INSERT INTO children (user_id, chi_child_name, chi_date_of_birth, chi_age_years, chi_age_months, chi_photo, hosp_shph_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
          $stmt = $conn->prepare($insert_sql);
          $stmt->bind_param("issiisi", $owner_user_id, $child_name, $date_of_birth, $age_years, $age_months, $photo_path, $chi_hospital);
        
        if ($stmt->execute()) {
          // Ensure children.chi_id matches the generated auto-increment id (chi_id_auto or insert_id)
          $newId = $conn->insert_id;
          if ($newId) {
            $upd = $conn->prepare('UPDATE children SET chi_id = ? WHERE chi_id_auto = ?');
            if ($upd) {
              $upd->bind_param('ii', $newId, $newId);
              $upd->execute();
              $upd->close();
            }
          }

          $_SESSION['success'] = "บันทึกข้อมูลเด็กเรียบร้อยแล้ว";
          $stmt->close();
          $conn->close();
          header("Location: children_list.php");
          exit();
        } else {
          if ($conn->errno === 1062) {
            $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล (ค่าซ้ำ) กรุณาตรวจสอบหรือแจ้งผู้ดูแล";
          } else {
            $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง";
          }
        }
        $stmt->close();
    }
    
    // เก็บข้อผิดพลาดใน session
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ลงทะเบียนข้อมูลเด็ก</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    .circle-image-wrapper {
      width: 150px;
      height: 150px;
      border-radius: 50%;
      overflow: hidden;
      background-color: #f0f0f0;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: auto;
    }

    .circle-image-wrapper img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    #imageInput {
      display: none;
    }

    .upload-label {
      cursor: pointer;
      display: inline-block;
      margin-top: 10px;
      color: #0d6efd;
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
      <a class="navbar-brand" href="mainpage.php">DSPM System</a>
      <div class="navbar-nav ms-auto">
        <span class="navbar-text me-3">
          สวัสดี, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?>
        </span>
        <a class="btn btn-outline-light btn-sm me-2" href="children_list.php">รายชื่อเด็ก</a>
        <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container d-flex align-items-center justify-content-center min-vh-100">
    <div class="col-12 col-sm-10 col-md-6 col-lg-4">
      <h1 class="text-center mb-4" style="color: #149ee9;">ลงทะเบียนข้อมูลเด็ก</h1>
      
      <!-- แสดงข้อความแจ้งเตือน -->
      <?php if (isset($_SESSION['errors'])): ?>
          <div class="alert alert-danger" role="alert">
              <ul class="mb-0">
                  <?php foreach($_SESSION['errors'] as $error): ?>
                      <li><?php echo htmlspecialchars($error); ?></li>
                  <?php endforeach; ?>
              </ul>
          </div>
          <?php unset($_SESSION['errors']); ?>
      <?php endif; ?>
      
      <form method="POST" action="kidinfo.php" enctype="multipart/form-data">
        <!-- รูปภาพวงกลม -->
        <div class="mb-3 text-center">
          <div class="circle-image-wrapper mb-2" id="previewContainer">
            <img id="previewImage" src="../image/kid_preview.png" alt="รูปตัวอย่างเด็ก" />
          </div>
          <label for="imageInput" class="upload-label">เพิ่มรูปภาพ</label>
          <input type="file" id="imageInput" name="child_photo" accept="image/*">
        </div>

        <!-- ฟอร์ม -->
        <div class="mb-3">
          <label for="child_name" class="form-label">ชื่อและนามสกุล</label>
          <input type="text" class="form-control" id="child_name" name="child_name" 
                 placeholder="กรุณากรอกชื่อและนามสกุลเด็ก" 
                 value="<?php echo isset($_SESSION['form_data']['child_name']) ? htmlspecialchars($_SESSION['form_data']['child_name']) : ''; ?>" 
                 required>
        </div>
        <div class="mb-3">
          <label for="date_of_birth" class="form-label">วันเกิด</label>
          <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                 value="<?php echo isset($_SESSION['form_data']['date_of_birth']) ? htmlspecialchars($_SESSION['form_data']['date_of_birth']) : ''; ?>" 
                 required>
        </div>

        <?php if ($user['user_role'] === 'staff'): ?>
        <div class="mb-3">
          <label for="owner_user_id" class="form-label">ผูกกับผู้ปกครอง</label>
          <select class="form-select" id="owner_user_id" name="owner_user_id" required>
            <option value="">-- เลือกผู้ปกครอง --</option>
            <?php foreach ($users as $u): ?>
              <option value="<?php echo $u['user_id']; ?>" <?php echo (isset($_SESSION['form_data']['owner_user_id']) && $_SESSION['form_data']['owner_user_id']==$u['user_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['user_fname'].' '.$u['user_lname']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!--<div class="mb-3">
          <label for="chi_hospital" class="form-label">สถานที่โรงพยาบาล</label>
          <select class="form-select" id="chi_hospital" name="chi_hospital">
            <option value="">-- เลือกสถานที่โรงพยาบาล --</option>
            <?php foreach ($hospitals as $h): ?>
              <option value="<?php echo $h['hosp_shph_id']; ?>" <?php echo (isset($user_hosp) && $user_hosp == $h['hosp_shph_id']) ? 'selected' : (isset($_SESSION['form_data']['chi_hospital']) && $_SESSION['form_data']['chi_hospital']==$h['hosp_shph_id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($h['hosp_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>-->

        <!-- สาขา ถูกตัดออก — เก็บไว้แค่โรงพยาบาล -->
        <div class="row">
          <div class="col-6">
            <div class="mb-3">
              <label for="age_years" class="form-label">อายุ (ปี)</label>
              <input type="number" class="form-control" id="age_years" name="age_years" 
                     placeholder="ปี" min="0" max="18" 
                     value="<?php echo isset($_SESSION['form_data']['age_years']) ? htmlspecialchars($_SESSION['form_data']['age_years']) : ''; ?>" 
                     required>
            </div>
          </div>
          <div class="col-6">
            <div class="mb-3">
              <label for="age_months" class="form-label">อายุ (เดือน)</label>
              <input type="number" class="form-control" id="age_months" name="age_months" 
                     placeholder="เดือน" min="0" max="11" 
                     value="<?php echo isset($_SESSION['form_data']['age_months']) ? htmlspecialchars($_SESSION['form_data']['age_months']) : ''; ?>">
            </div>
          </div>
        </div>
        
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-success btn-lg">บันทึกข้อมูล</button>
          <a href="mainpage.php" class="btn btn-secondary">กลับหน้าหลัก</a>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const imageInput = document.getElementById('imageInput');
    const previewImage = document.getElementById('previewImage');

    imageInput.addEventListener('change', function () {
      const file = this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function (e) {
          previewImage.src = e.target.result;
        }
        reader.readAsDataURL(file);
      }
    });

    // คำนวณอายุอัตโนมัติจากวันเกิด
    document.getElementById('date_of_birth').addEventListener('change', function() {
      const birthDate = new Date(this.value);
      const today = new Date();
      
      if (birthDate <= today) {
        let ageYears = today.getFullYear() - birthDate.getFullYear();
        let ageMonths = today.getMonth() - birthDate.getMonth();
        
        if (ageMonths < 0) {
          ageYears--;
          ageMonths += 12;
        }
        
        document.getElementById('age_years').value = ageYears;
        document.getElementById('age_months').value = ageMonths;
      }
    });

    // สาขาถูกย้ายออก — ไม่มีการโหลดสาขาแบบไดนามิกอีกต่อไป
  </script>
</body>
</html>

<?php
// ลบข้อมูลฟอร์มออกจาก session หลังจากแสดงผลแล้ว
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>

<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// ถ้ามีการส่งข้อมูลมาแบบ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $child_name = trim($_POST['child_name']);
    $date_of_birth = $_POST['date_of_birth'];
    $age_years = (int)$_POST['age_years'];
    $age_months = (int)$_POST['age_months'];
    
    $errors = array();
    
    // ตรวจสอบความถูกต้องของข้อมูล
    if (empty($child_name)) {
        $errors[] = "กรุณากรอกชื่อและนามสกุลเด็ก";
    }
    if (empty($date_of_birth)) {
        $errors[] = "กรุณากรอกวันเกิด";
    }
    if ($age_years < 0 || $age_years > 6) {
        $errors[] = "อายุต้องอยู่ระหว่าง 0-6 ปี";
    }
    if ($age_months < 0 || $age_months > 11) {
        $errors[] = "เดือนต้องอยู่ระหว่าง 0-11 เดือน";
    }
    
    // จัดการไฟล์รูปภาพ
    $photo_path = null;
    if (isset($_FILES['child_photo']) && $_FILES['child_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/children/';
        $file_extension = strtolower(pathinfo($_FILES['child_photo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif');
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "ไฟล์รูปภาพต้องเป็น JPG, JPEG, PNG หรือ GIF เท่านั้น";
        } else {
            // สร้างชื่อไฟล์ใหม่เพื่อป้องกันชื่อซ้ำ
            $new_filename = 'child_' . $user['id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['child_photo']['tmp_name'], $upload_path)) {
                $photo_path = 'uploads/children/' . $new_filename;
            } else {
                $errors[] = "เกิดข้อผิดพลาดในการอัพโหลดรูปภาพ";
            }
        }
    }
    
    // ถ้าไม่มีข้อผิดพลาด ทำการบันทึกข้อมูล
    if (empty($errors)) {
        $insert_sql = "INSERT INTO children (user_id, child_name, date_of_birth, age_years, age_months, photo_path) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("isssis", $user['id'], $child_name, $date_of_birth, $age_years, $age_months, $photo_path);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "บันทึกข้อมูลเด็กเรียบร้อยแล้ว";
            $stmt->close();
            $conn->close();
            header("Location: children_list.php");
            exit();
        } else {
            $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง";
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
  <title>กรอกข้อมูลเด็ก</title>
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
      <h1 class="text-center mb-4" style="color: #149ee9;">กรอกข้อมูลเด็ก</h1>
      
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
            <img id="previewImage" src="https://via.placeholder.com/150" alt="Preview Image" />
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
  </script>
</body>
</html>

<?php
// ลบข้อมูลฟอร์มออกจาก session หลังจากแสดงผลแล้ว
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>

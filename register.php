<?php
session_start();
require_once 'db_conn.php';

// ถ้ามีการส่งข้อมูลมาแบบ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // รับข้อมูลจากฟอร์ม
    $username = trim($_POST['usrname']);
    $password = $_POST['password'];
    $re_password = $_POST['re_password'];
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $phone = trim($_POST['phone']);
    
    // ตรวจสอบความถูกต้องของข้อมูล
    $errors = array();
    
    // ตรวจสอบว่าไม่มีช่องว่าง
    if (empty($username)) {
        $errors[] = "กรุณากรอกชื่อผู้ใช้";
    }
    if (empty($password)) {
        $errors[] = "กรุณากรอกรหัสผ่าน";
    }
    if (empty($re_password)) {
        $errors[] = "กรุณายืนยันรหัสผ่าน";
    }
    if (empty($fname)) {
        $errors[] = "กรุณากรอกชื่อ";
    }
    if (empty($lname)) {
        $errors[] = "กรุณากรอกนามสกุล";
    }
    if (empty($phone)) {
        $errors[] = "กรุณากรอกเบอร์โทรศัพท์";
    }
    
    // ตรวจสอบความยาวชื่อผู้ใช้
    if (strlen($username) < 4) {
        $errors[] = "ชื่อผู้ใช้ต้องมีอย่างน้อย 4 ตัวอักษร";
    }
    
    // ตรวจสอบความยาวรหัสผ่าน
    if (strlen($password) < 6) {
        $errors[] = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";
    }
    
    // ตรวจสอบรหัสผ่านตรงกันหรือไม่
    if ($password !== $re_password) {
        $errors[] = "รหัสผ่านไม่ตรงกัน";
    }
    
    // ตรวจสอบรูปแบบเบอร์โทรศัพท์
    if (!preg_match("/^[0-9]{10}$/", $phone)) {
        $errors[] = "เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก";
    }
    
    // ตรวจสอบว่าชื่อผู้ใช้ซ้ำหรือไม่
    if (empty($errors)) {
        $check_user = "SELECT id FROM users WHERE username = ?";
        $stmt = $conn->prepare($check_user);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "ชื่อผู้ใช้นี้มีอยู่แล้ว กรุณาเลือกชื่อผู้ใช้อื่น";
        }
        $stmt->close();
    }
    
    // ถ้าไม่มีข้อผิดพลาด ทำการบันทึกข้อมูล
    if (empty($errors)) {
        // เข้ารหัสรหัสผ่าน
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // บันทึกข้อมูลลงฐานข้อมูล
        $insert_sql = "INSERT INTO users (username, password, fname, lname, phone, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("sssss", $username, $hashed_password, $fname, $lname, $phone);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "ลงทะเบียนสำเร็จ! กรุณาเข้าสู่ระบบ";
            $stmt->close();
            $conn->close();
            header("Location: login.php");
            exit();
        } else {
            $errors[] = "เกิดข้อผิดพลาดในการลงทะเบียน กรุณาลองใหม่อีกครั้ง";
        }
        $stmt->close();
    }
    
    // ถ้ามีข้อผิดพลาด เก็บไว้ใน session เพื่อแสดงผล
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
    }
}

$conn->close();
?>

<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="css/test.css">
    <title>ลงทะเบียน DSPM</title>
  </head>
  <body>
    <div class="container d-flex align-items-center justify-content-center min-vh-100">
      <div class="col-12 col-sm-10 col-md-6 col-lg-4">
        <h1 class="text-center mb-4" style="color: #149ee9;">ลงทะเบียน</h1>
        
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
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="container">
            <form method="POST" action="register.php">
                <div class="mb-3">
                    <label for="usrname" class="form-label">ชื่อผู้ใช้</label>
                    <input type="text" class="form-control" id="usrname" name="usrname" 
                           placeholder="กรุณากรอกชื่อผู้ใช้ที่ต้องการ" 
                           value="<?php echo isset($_SESSION['form_data']['usrname']) ? htmlspecialchars($_SESSION['form_data']['usrname']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">รหัสผ่าน</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="กรุณากรอกรหัสผ่าน" required>
                </div>
                <div class="mb-3">
                    <label for="re_password" class="form-label">ยืนยันรหัสผ่าน</label>
                    <input type="password" class="form-control" id="re_password" name="re_password" 
                           placeholder="กรุณากรอกรหัสผ่านอีกครั้ง" required>
                </div>
                <div class="mb-3">
                    <label for="fname" class="form-label">ชื่อ</label>
                    <input type="text" class="form-control" id="fname" name="fname" 
                           placeholder="กรุณากรอกชื่อจริง" 
                           value="<?php echo isset($_SESSION['form_data']['fname']) ? htmlspecialchars($_SESSION['form_data']['fname']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label for="lname" class="form-label">นามสกุล</label>
                    <input type="text" class="form-control" id="lname" name="lname" 
                           placeholder="กรุณากรอกนามสกุล" 
                           value="<?php echo isset($_SESSION['form_data']['lname']) ? htmlspecialchars($_SESSION['form_data']['lname']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-control" id="phone" name="phone" 
                           placeholder="กรุณากรอกเบอร์มือถือของคุณ" 
                           value="<?php echo isset($_SESSION['form_data']['phone']) ? htmlspecialchars($_SESSION['form_data']['phone']) : ''; ?>" required>
                </div>
                <div style="display: flex; justify-content: center; gap: 20px;">
                    <button type="submit" class="btn btn-success">ลงทะเบียน</button>
                    <a href="login.php" class="btn btn-primary">เข้าสู่ระบบ</a>
                </div>
            </form>
        </div>
      </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
  </body>
</html>

<?php
// ลบข้อมูลฟอร์มออกจาก session หลังจากแสดงผลแล้ว
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>

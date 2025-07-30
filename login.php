<?php
session_start();
require_once 'db_conn.php';

// ตรวจสอบว่าผู้ใช้ล็อกอินแล้วหรือยัง ถ้าล็อกอินแล้วให้ไปหน้าหลัก
if (isset($_SESSION['user_id'])) {
    header("Location: test/mainpage.php");
    exit();
}

// ถ้ามีการส่งข้อมูลมาแบบ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['user_username']);
    $password = $_POST['user_password'];
    
    $errors = array();
    
    // ตรวจสอบว่าไม่มีช่องว่าง
    if (empty($username)) {
        $errors[] = "กรุณากรอกชื่อผู้ใช้";
    }
    if (empty($password)) {
        $errors[] = "กรุณากรอกรหัสผ่าน";
    }
    
    // ถ้าไม่มีข้อผิดพลาด ทำการตรวจสอบข้อมูลในฐานข้อมูล
    if (empty($errors)) {
        $sql = "SELECT user_id, user_username, user_password, user_fname, user_lname, user_role FROM users WHERE user_username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // ตรวจสอบรหัสผ่าน
            if (password_verify($password, $user['user_password'])) {
                // ล็อกอินสำเร็จ - เก็บข้อมูลใน session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['user_username'];
                $_SESSION['fname'] = $user['user_fname'];
                $_SESSION['lname'] = $user['user_lname'];
                $_SESSION['user_role'] = $user['user_role'];
                $_SESSION['login_time'] = date('Y-m-d H:i:s');
                
                // อัพเดทเวลาล็อกอินล่าสุด (ถ้าต้องการ)
                $update_sql = "UPDATE users SET user_updated_at = NOW() WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $user['user_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                $stmt->close();
                $conn->close();
                
                // เปลี่ยนเส้นทางไปหน้าหลัก
                header("Location: test/mainpage.php");
                exit();
            } else {
                $errors[] = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
            }
        } else {
            $errors[] = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
        $stmt->close();
    }
    
    // ถ้ามีข้อผิดพลาด เก็บไว้ใน session
    if (!empty($errors)) {
        $_SESSION['login_errors'] = $errors;
        $_SESSION['login_username'] = $username;
    }
}

$conn->close();
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ DSPM</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/test.css">
  </head>
  <body>
    <div class="container d-flex align-items-center justify-content-center min-vh-100">
      <div class="col-12 col-sm-10 col-md-6 col-lg-4">
        <h1 class="text-center mb-4" style="color: #149ee9;">เข้าสู่ระบบ</h1>
        
        <!-- แสดงข้อความจาก register หากมี -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <!-- แสดงข้อความแจ้งเตือนข้อผิดพลาด -->
        <?php if (isset($_SESSION['login_errors'])): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    <?php foreach($_SESSION['login_errors'] as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['login_errors']); ?>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
          <div class="mb-3">
            <label for="user_username" class="form-label">ชื่อผู้ใช้</label>
            <input type="text" class="form-control" id="user_username" name="user_username" 
                   placeholder="กรุณากรอกชื่อผู้ใช้" 
                   value="<?php echo isset($_SESSION['login_username']) ? htmlspecialchars($_SESSION['login_username']) : ''; ?>" 
                   required>
          </div>
          <div class="mb-3">
            <label for="user_password" class="form-label">รหัสผ่าน</label>
            <input type="password" class="form-control" id="user_password" name="user_password" 
                   placeholder="กรุณากรอกรหัสผ่าน" required>
          </div>
          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">เข้าสู่ระบบ</button>
          </div>
          <div class="text-center">
            <p>ยังไม่มีบัญชี? <a href="register.php" class="text-decoration-none">ลงทะเบียนที่นี่</a></p>
          </div>
        </form>
      </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  </body>
</html>

<?php
// ลบข้อมูลที่ไม่ใช้แล้วออกจาก session
if (isset($_SESSION['login_username'])) {
    unset($_SESSION['login_username']);
}
?>

<?php
require_once '../check_session.php';
checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง

$user = getUserInfo();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลัก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/DSPMProj/css/test.css" />
    <link rel="stylesheet" href="/DSPMProj/css/btncircle.css" />
</head>
<body class="bg-light">

  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
      <a class="navbar-brand" href="#">DSPM System</a>
      <div class="navbar-nav ms-auto">
        <span class="navbar-text me-3">
          สวัสดี, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?>
        </span>
        <a class="btn btn-outline-light btn-sm me-2" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">ข้อมูลส่วนตัว</a>
        <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container text-center py-5">
    <h1 class="mb-4">
      <span style="color: #005DFF; display: block;">เว็บเฝ้าระวังและส่งเสริมพัฒนาการเด็กประถมวัย</span>
      <span style="color: #FF00D4; display: block;">Developmental Surveillance and Promotion Manual</span>
      <span style="color: #1FFD01; display: block;">(DSPM)</span>
    </h1>

    <div class="mb-4 d-flex justify-content-center">
      <img src="../image/baby-33253_1280.png" alt="Family" class="img-fluid rounded shadow" style="max-width: 500px;" />
    </div>
    
    <h3>กรุณาคลิกเลือกเมนูที่ต้องการด้านล่างเพื่อเข้าใช้งานระบบ</h3><br>

    <div class="d-flex flex-column flex-sm-row justify-content-center gap-4">
      <a href="kidinfo.php" class="btn btn-primary circle-button">
        <img src="../image/baby-310259_1280.png" alt="Kid Info" /><h3>กรอกข้อมูลเด็ก</h3>
      </a>
      <a href="children_list.php" class="btn btn-success circle-button">
        <h3>รายชื่อเด็ก</h3><img src="../image/babies-2028267_1280.png" alt="Kid List" />
      </a>
    </div>
  </div>

  <!-- Modal สำหรับแสดงข้อมูลส่วนตัว -->
  <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="profileModalLabel">ข้อมูลส่วนตัว</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row mb-3">
            <div class="col-4"><strong>ชื่อผู้ใช้:</strong></div>
            <div class="col-8"><?php echo htmlspecialchars($user['username']); ?></div>
          </div>
          <div class="row mb-3">
            <div class="col-4"><strong>ชื่อ:</strong></div>
            <div class="col-8"><?php echo htmlspecialchars($user['fname']); ?></div>
          </div>
          <div class="row mb-3">
            <div class="col-4"><strong>นามสกุล:</strong></div>
            <div class="col-8"><?php echo htmlspecialchars($user['lname']); ?></div>
          </div>
          <div class="row mb-3">
            <div class="col-4"><strong>เวลาเข้าสู่ระบบ:</strong></div>
            <div class="col-8"><?php echo htmlspecialchars($user['login_time']); ?></div>
          </div>
          <?php
          // ดึงข้อมูลเพิ่มเติมจากฐานข้อมูล
          require_once '../db_conn.php';
          $sql = "SELECT phone, created_at FROM users WHERE id = ?";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("i", $user['id']);
          $stmt->execute();
          $result = $stmt->get_result();
          $additional_info = $result->fetch_assoc();
          $stmt->close();
          $conn->close();
          ?>
          <div class="row mb-3">
            <div class="col-4"><strong>เบอร์โทรศัพท์:</strong></div>
            <div class="col-8"><?php echo htmlspecialchars($additional_info['phone']); ?></div>
          </div>
          <div class="row mb-3">
            <div class="col-4"><strong>สมาชิกเมื่อ:</strong></div>
            <div class="col-8"><?php echo date('d/m/Y H:i', strtotime($additional_info['created_at'])); ?></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
          <button type="button" class="btn btn-primary" onclick="editProfile()">แก้ไขข้อมูล</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  
  <script>
    function editProfile() {
      // ฟังก์ชันสำหรับแก้ไขข้อมูลส่วนตัว (สามารถพัฒนาต่อได้)
      alert('ฟีเจอร์แก้ไขข้อมูลส่วนตัวจะเปิดให้ใช้งานในอนาคต');
    }
  </script>
</body>
</html>

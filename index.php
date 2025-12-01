<?php
// หน้าแรก (Landing Page) ของโปรเจค DSPM
// แสดงข้อมูลสั้น ๆ และปุ่มไปที่หน้าล็อกอิน
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DSPM - ยินดีต้อนรับ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { max-width:820px; width:100%; border-radius:12px; box-shadow:0 6px 20px rgba(0,0,0,0.08); }
        .brand { color:#149ee9; font-weight:700; }
        .hero { padding:40px; }
        .btn-login { min-width:160px; }
        .small-note { color:#6c757d; }
    </style>
</head>
<body>
    <div class="card">
        <div class="hero d-flex flex-column flex-md-row align-items-center">
            <div class="me-md-4 text-center text-md-start" style="flex:1">
                <h1 class="brand">DSPM System</h1>
                <p class="lead">ระบบติดตามพัฒนาการเด็ก (Developmental Surveillance and Promotion Manual)</p>
                <p class="small-note">เพื่อความปลอดภัย กรุณาเข้าสู่ระบบก่อนใช้งาน</p>
                <div class="mt-3">
                    <a href="login.php" class="btn btn-primary btn-login">เข้าสู่ระบบ</a>
                    <a href="register.php" class="btn btn-outline-secondary ms-2">ลงทะเบียน</a>
                </div>
            </div>
            <div style="flex:1;text-align:center">
                <img src="image/baby-33253_1280.png" alt="DSPM" style="max-width:320px;opacity:0.95">
            </div>
        </div>
        <div class="p-3 border-top text-center small">
            <span>หากต้องการใช้สำหรับทดสอบ ให้ลงชื่อเข้าใช้ด้วยบัญชีผู้ดูแลหรือเจ้าหน้าที่</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

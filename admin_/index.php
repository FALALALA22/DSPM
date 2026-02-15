<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../db_conn.php';

checkLogin();
$user = getUserInfo();
if ($user['user_role'] !== 'admin') {
    header('Location: ../mainpage.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Admin Panel - DSPM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
      .admin-hero { background: linear-gradient(135deg,#149ee9,#6dd5ed); color: #fff; border-radius: 12px; padding: 28px; }
      .admin-card { transition: transform .15s ease; }
      .admin-card:hover { transform: translateY(-6px); }
    </style>
</head>
<body class="bg-light">
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
      <a class="navbar-brand" href="../admin_/index.php">DSPM Admin</a>
      <div class="navbar-nav ms-auto">
        <span class="navbar-text me-3">สวัสดี, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></span>
        <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container mt-5">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-10">
        <div class="admin-hero mb-4 d-flex align-items-center justify-content-between">
          <div>
            <h1 class="h2 mb-1">แผงควบคุมผู้ดูแลระบบ</h1>
            <div class="small-opacity">จัดการโรงพยาบาลและพนักงานของระบบ</div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <a href="hospitals.php" class="text-decoration-none text-dark">
              <div class="card admin-card shadow-sm">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="display-6 text-primary"><i class="bi bi-hospital"></i></div>
                  <div>
                    <h5 class="mb-0">จัดการโรงพยาบาล</h5>
                    <div class="small text-muted">เพิ่ม ลบ และดูรายการโรงพยาบาล</div>
                  </div>
                </div>
              </div>
            </a>
          </div>
          <div class="col-md-6">
            <a href="staff.php" class="text-decoration-none text-dark">
              <div class="card admin-card shadow-sm">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="display-6 text-success"><i class="bi bi-people"></i></div>
                  <div>
                    <h5 class="mb-0">จัดการพนักงาน</h5>
                    <div class="small text-muted">เพิ่ม ลบ และกรองพนักงานตามโรงพยาบาล</div>
                  </div>
                </div>
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

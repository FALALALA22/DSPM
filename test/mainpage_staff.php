<?php
require_once '../check_session.php';
checkLogin();

$user = getUserInfo();

?>
<?php
// fetch phone for prefill
require_once '../db_conn.php';
$additional_info = [];
$user_hospital_name = '';
$stmt = $conn->prepare("SELECT user_phone, hosp_shph_id FROM users WHERE user_id = ?");
if ($stmt) {
  $stmt->bind_param('i', $user['user_id']);
  $stmt->execute();
  $res = $stmt->get_result();
  $additional_info = $res->fetch_assoc() ?: [];
  $hosp_id = !empty($additional_info['hosp_shph_id']) ? (int)$additional_info['hosp_shph_id'] : 0;
  $stmt->close();

  if ($hosp_id) {
    $hstmt = $conn->prepare('SELECT hosp_name FROM hospitals WHERE hosp_shph_id = ?');
    if ($hstmt) {
      $hstmt->bind_param('i', $hosp_id);
      $hstmt->execute();
      $hres = $hstmt->get_result();
      $hrow = $hres->fetch_assoc();
      $user_hospital_name = $hrow['hosp_name'] ?? '';
      $hstmt->close();
    }
  }
}

// fetch totals for display (scoped to staff hospital if available)
$total_children = 0;
$total_parents = 0;
$hosp_id = isset($user['hosp_shph_id']) ? (int)$user['hosp_shph_id'] : 0;
if ($conn) {
  if ($hosp_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM children WHERE hosp_shph_id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $hosp_id);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $total_children = (int)($row['cnt'] ?? 0);
      $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE user_role = 'user' AND hosp_shph_id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $hosp_id);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $total_parents = (int)($row['cnt'] ?? 0);
      $stmt->close();
    }
  } else {
    // fallback: show system-wide totals
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM children");
    if ($r) { $row = $r->fetch_assoc(); $total_children = (int)($row['cnt'] ?? 0); }
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE user_role = 'user'");
    if ($r) { $row = $r->fetch_assoc(); $total_parents = (int)($row['cnt'] ?? 0); }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>หน้าหลัก (Staff)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/DSPMProj/css/test.css" />
    <style>
      .button-container { display:flex; gap:1rem; flex-wrap:wrap; justify-content:center; }
      .staff-card { max-width:1000px; margin:0 auto; }
      .circle-button { width:200px; height:200px; border-radius:50%; display:flex; flex-direction:column; align-items:center; justify-content:center; text-decoration:none; color:#fff }

      /* Improved card styles */
      .info-card { display:block; padding:1rem; border-radius:0.6rem; box-shadow:0 6px 18px rgba(20,158,233,0.08); background:#ffffff; border:1px solid rgba(20,158,233,0.06); }
      .info-card .meta { line-height:1.3; }
      .info-card .meta .muted { color:#6c757d; font-size:0.9rem; }

      .stat-card { padding:0.8rem 1rem; border-radius:0.6rem; box-shadow:0 6px 18px rgba(108,117,125,0.06); background: #fff; border:1px solid rgba(0,0,0,0.04); }
      .stat-sub { color:#6c757d; font-size:0.85rem; }
      .stat-number { font-size:1.6rem; font-weight:700; color:#149ee9; margin:0; }

      @media (max-width:780px) { .info-card { width:100%; } .stat-card { width:48%; } }
    </style>
</head>
<body class="bg-light">
  <nav class="navbar navbar-dark bg-primary">
    <div class="container">
      <a class="navbar-brand" href="#">DSPM - Staff</a>
      <div class="navbar-text text-white">สวัสดี, <?php echo htmlspecialchars($user['fname'].' '.$user['lname']); ?> <span class="badge bg-warning text-dark ms-2">Staff</span></div>
      <div class="ms-auto">
        <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container mt-4 staff-card">
    <div class="text-center mb-4">
      <h1 style="color:#149ee9">แดชบอร์ดสำหรับ พนักงาน รพสต.</h1>
      <p class="text-muted">เมนูที่รวดเร็วสำหรับการทำงานที่โรงพยาบาล</p>
    </div>

    <div class="d-flex justify-content-center gap-3 mb-3 flex-wrap">
      <div class="info-card" style="min-width:340px;">
        <div class="meta">
          <h5 class="mb-1">ข้อมูลส่วนตัว</h5>
          <p class="mb-1"><strong>ชื่อ:</strong> <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></p>
          <p class="mb-1"><strong>เบอร์โทร:</strong> <?php echo htmlspecialchars($additional_info['user_phone'] ?? '-'); ?></p>
          <p class="mb-1"><strong>สังกัด:</strong> <?php echo htmlspecialchars($user_hospital_name ?: '-'); ?></p>
          <div class="mt-2"><button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#profileModal">แก้ไขข้อมูล</button></div>
        </div>
      </div>

      <div class="stat-card text-center" style="min-width:180px;">
        <div>
          <div class="stat-sub">จำนวนเด็กทั้งหมด</div>
          <div class="stat-number"><?php echo number_format($total_children); ?></div>
        </div>
      </div>

      <div class="stat-card text-center" style="min-width:180px;">
        <div>
          <div class="stat-sub">จำนวนผู้ปกครอง</div>
          <div class="stat-number"><?php echo number_format($total_parents); ?></div>
        </div>
      </div>
    </div>

    <div class="button-container mb-4">
      <a href="children_list.php" class="btn btn-success circle-button">
        <div><i class="fas fa-users fa-2x"></i></div>
        <div class="mt-2">รายชื่อเด็ก</div>
      </a>

      <a href="kidinfo.php" class="btn btn-primary circle-button">
        <div><i class="fas fa-user-plus fa-2x"></i></div>
        <div class="mt-2">ลงทะเบียนเด็ก</div>
      </a>

    </div>

    
  

  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  
  <!-- Profile Edit Modal -->
  <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form id="profileForm">
          <div class="modal-header">
            <h5 class="modal-title" id="profileModalLabel">แก้ไขข้อมูลส่วนตัว</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="profileAlert"></div>
            <div class="mb-3">
              <label class="form-label">ชื่อ</label>
              <input type="text" name="fname" id="pf-fname" class="form-control" required value="<?php echo htmlspecialchars($user['fname']); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">นามสกุล</label>
              <input type="text" name="lname" id="pf-lname" class="form-control" required value="<?php echo htmlspecialchars($user['lname']); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">เบอร์โทร</label>
              <input type="text" name="phone" id="pf-phone" class="form-control" required value="<?php echo htmlspecialchars($additional_info['user_phone'] ?? ''); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">รหัสผ่าน (เว้นว่างถ้าไม่เปลี่ยน)</label>
              <input type="password" name="password" id="pf-password" class="form-control">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary">บันทึก</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('profileForm');
      const alertDiv = document.getElementById('profileAlert');

      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        alertDiv.innerHTML = '';
        const fd = new FormData(form);
        try {
          const res = await fetch('../update_profile.php', { method: 'POST', body: fd });
          const json = await res.json();
          if (json.success) {
            alertDiv.innerHTML = '<div class="alert alert-success">' + (json.message || 'อัปเดตเรียบร้อย') + '</div>';
            setTimeout(() => { location.reload(); }, 900);
          } else {
            alertDiv.innerHTML = '<div class="alert alert-danger">' + (json.message || 'เกิดข้อผิดพลาด') + '</div>';
          }
        } catch (err) {
          alertDiv.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาดเครือข่าย</div>';
        }
      });
    });
  </script>
</body>
</html>

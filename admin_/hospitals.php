<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../db_conn.php';

checkLogin();
$user = getUserInfo();
if ($user['user_role'] !== 'admin') {
    header('Location: ../mainpage.php');
    exit();
}

$errors = [];
// Add hospital
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_hospital') {
  $name = trim($_POST['hosp_name'] ?? '');
  $shph = trim($_POST['hosp_shph_id'] ?? '');
  if ($name === '') {
    $errors[] = 'กรุณากรอกชื่อโรงพยาบาล';
  } else {
    $ins = $conn->prepare('INSERT INTO hospitals (hosp_name, hosp_shph_id) VALUES (?, ?)');
    $ins->bind_param('ss', $name, $shph);
    if (!$ins->execute()) {
      $errors[] = 'ไม่สามารถเพิ่มโรงพยาบาลได้';
    }
    $ins->close();
  }
}

// Delete hospital
if (isset($_GET['delete_hospital'])) {
    $hid = (int)$_GET['delete_hospital'];
    $del = $conn->prepare('DELETE FROM hospitals WHERE hosp_shph_id = ?');
    $del->bind_param('i', $hid);
    $del->execute();
    $del->close();
    header('Location: hospitals.php');
    exit();
}

// Fetch hospitals
$hospitals = [];
$res = $conn->query('SELECT hosp_name, hosp_shph_id FROM hospitals ORDER BY hosp_name');
if ($res) {
    while ($r = $res->fetch_assoc()) $hospitals[] = $r;
}

$conn->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จัดการโรงพยาบาล</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    .page-header { display:flex; justify-content:space-between; align-items:center; gap:12px; }
    .table-actions .btn { margin-right:6px; }
  </style>
  </head>
<body class="bg-light">
  <div class="container mt-4">
    <div class="page-header mb-3">
      <div>
        <h2 class="mb-0">จัดการโรงพยาบาล</h2>
        <div class="small text-muted">เพิ่มหรือลบข้อมูลโรงพยาบาลในระบบ</div>
      </div>
      <div>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับ</a>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
      <div class="card-body">
        <form method="POST" action="hospitals.php" class="row g-2 align-items-center">
          <input type="hidden" name="action" value="add_hospital">
          <div class="col-md-9">
            <input type="text" name="hosp_name" class="form-control" placeholder="ชื่อโรงพยาบาล">
            <input type="text" name="hosp_shph_id" class="form-control mt-2" placeholder="รหัสโรงพยาบาล">
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-circle"></i> เพิ่มโรงพยาบาล</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">รายการโรงพยาบาล <span class="badge bg-info ms-2"><?php echo count($hospitals); ?></span></h5>
        </div>
        <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>ชื่อ</th><th>รหัส</th><th class="text-end">การกระทำ</th></tr></thead>
          <tbody>
            <?php foreach ($hospitals as $h): ?>
              <tr>
                <td><?php echo htmlspecialchars($h['hosp_name']); ?></td>
                <td><?php echo htmlspecialchars($h['hosp_shph_id'] ?? ''); ?></td>
                <td class="text-end table-actions">
                  <a href="staff.php?hospital_id=<?php echo $h['hosp_shph_id']; ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-people"></i> ดูพนักงาน</a>
                  <a href="hospitals.php?delete_hospital=<?php echo $h['hosp_shph_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบโรงพยาบาลนี้? จะลบความสัมพันธ์กับพนักงานด้วย');"><i class="bi bi-trash"></i> ลบ</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

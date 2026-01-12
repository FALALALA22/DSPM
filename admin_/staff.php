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
// Add staff (create user with role 'staff')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $username = trim($_POST['user_username'] ?? '');
    $password = $_POST['user_password'] ?? '';
    $fname = trim($_POST['user_fname'] ?? '');
    $lname = trim($_POST['user_lname'] ?? '');
    $phone = trim($_POST['user_phone'] ?? '');
    $user_hospital = isset($_POST['user_hospital']) ? intval($_POST['user_hospital']) : null;

    if ($username === '' || $password === '' || $fname === '' || $lname === '') {
        $errors[] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    }

    if (empty($errors)) {
        $check = $conn->prepare('SELECT user_id FROM users WHERE user_username = ?');
        $check->bind_param('s', $username);
        $check->execute();
        $cr = $check->get_result();
        if ($cr && $cr->num_rows > 0) {
            $errors[] = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
        }
        $check->close();
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare('INSERT INTO users (user_username, user_password, user_fname, user_lname, user_phone, user_hospital, user_role, user_created_at) VALUES (?, ?, ?, ?, ?, ?, "staff", NOW())');
        $ins->bind_param('sssssi', $username, $hash, $fname, $lname, $phone, $user_hospital);
        if (!$ins->execute()) {
            $errors[] = 'ไม่สามารถเพิ่มพนักงานได้';
        }
        $ins->close();
    }
}

// Delete staff
if (isset($_GET['delete_user'])) {
    $uid = (int)$_GET['delete_user'];
    $del = $conn->prepare('DELETE FROM users WHERE user_id = ? AND user_role = "staff"');
    $del->bind_param('i', $uid);
    $del->execute();
    $del->close();
    header('Location: staff.php');
    exit();
}

// Fetch hospitals for select
// Fetch hospitals for select and for name lookup
$hospitals = [];
$hres = $conn->query('SELECT hosp_id, hosp_name FROM hospitals ORDER BY hosp_name');
if ($hres) while ($r = $hres->fetch_assoc()) $hospitals[] = $r;

// Build hospital id -> name map for display
$hmap = [];
foreach ($hospitals as $hh) $hmap[$hh['hosp_id']] = $hh['hosp_name'];

// Fetch staff grouped by hospital (or filter by hospital_id)
$filter_hospital = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$staff = [];
if ($filter_hospital) {
    $s = $conn->prepare('SELECT user_id, user_username, user_fname, user_lname, user_phone, user_hospital FROM users WHERE user_role = "staff" AND user_hospital = ? ORDER BY user_fname');
    $s->bind_param('i', $filter_hospital);
} else {
    $s = $conn->prepare('SELECT user_id, user_username, user_fname, user_lname, user_phone, user_hospital FROM users WHERE user_role = "staff" ORDER BY user_hospital, user_fname');
}
$s->execute();
$sr = $s->get_result();
while ($row = $sr->fetch_assoc()) $staff[] = $row;
$s->close();

$conn->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จัดการพนักงาน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    .form-grid .form-control, .form-grid .form-select { min-height: 44px; }
    .staff-badge { font-size: .85rem; }
  </style>
</head>
<body class="bg-light">
  <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h2 class="mb-0">จัดการพนักงาน (Staff)</h2>
        <div class="small text-muted">เพิ่ม/ลบพนักงาน และกรองตามโรงพยาบาล</div>
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
        <form method="POST" action="staff.php" class="row g-2 form-grid align-items-center">
          <input type="hidden" name="action" value="add_staff">
          <div class="col-md-3"><input name="user_username" class="form-control" placeholder="ชื่อผู้ใช้"></div>
          <div class="col-md-3"><input name="user_password" type="password" class="form-control" placeholder="รหัสผ่าน"></div>
          <div class="col-md-3">
            <select name="user_hospital" class="form-select">
              <option value="">-- เลือกโรงพยาบาล --</option>
              <?php foreach ($hospitals as $h): ?>
                <option value="<?php echo $h['hosp_id']; ?>"><?php echo htmlspecialchars($h['hosp_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-person-plus"></i> เพิ่มพนักงาน</button></div>

          <div class="col-md-3"><input name="user_fname" class="form-control" placeholder="ชื่อ"></div>
          <div class="col-md-3"><input name="user_lname" class="form-control" placeholder="นามสกุล"></div>
          <div class="col-md-3"><input name="user_phone" class="form-control" placeholder="เบอร์โทร"></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <form method="GET" action="staff.php" class="row g-2 mb-3">
          <div class="col-md-8">
            <select name="hospital_id" class="form-select">
              <option value="">-- แสดงพนักงานทั้งหมด --</option>
              <?php foreach ($hospitals as $h): ?>
                <option value="<?php echo $h['hosp_id']; ?>" <?php echo ($filter_hospital == $h['hosp_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($h['hosp_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4"><button class="btn btn-secondary w-100" type="submit"><i class="bi bi-funnel"></i> กรอง</button></div>
        </form>

        <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>ชื่อ-นามสกุล</th><th>ยูสเซอร์</th><th>เบอร์</th><th>โรงพยาบาล</th><th class="text-end">การกระทำ</th></tr></thead>
          <tbody>
            <?php foreach ($staff as $s): ?>
              <tr>
                <td><?php echo htmlspecialchars($s['user_fname'] . ' ' . $s['user_lname']); ?></td>
                <td><?php echo htmlspecialchars($s['user_username']); ?></td>
                <td><?php echo htmlspecialchars($s['user_phone']); ?></td>
                <td><?php echo htmlspecialchars(!empty($s['user_hospital']) && isset($hmap[$s['user_hospital']]) ? $hmap[$s['user_hospital']] : '-'); ?></td>
                <td class="text-end">
                  <a href="staff.php?delete_user=<?php echo $s['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบพนักงานนี้?')"><i class="bi bi-trash"></i> ลบ</a>
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

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
    // keep hosp_shph_id as string to preserve leading zeros
    $hosp_shph_id = isset($_POST['hosp_shph_id']) && $_POST['hosp_shph_id'] !== '' ? (string)$_POST['hosp_shph_id'] : '';

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
        $ins = $conn->prepare('INSERT INTO users (user_username, user_password, user_fname, user_lname, user_phone, hosp_shph_id, user_role, user_created_at) VALUES (?, ?, ?, ?, ?, ?, "staff", NOW())');
        // bind hospital id as string to preserve leading zeros
        $ins->bind_param('ssssss', $username, $hash, $fname, $lname, $phone, $hosp_shph_id);
        if ($ins->execute()) {
          // Ensure users.user_id matches the generated auto-increment id (user_id_auto or insert_id)
          $newId = $conn->insert_id; // this is the AUTO_INCREMENT value
          if ($newId) {
            $upd = $conn->prepare('UPDATE users SET user_id = ? WHERE user_id_auto = ?');
            if ($upd) {
              $upd->bind_param('ii', $newId, $newId);
              $upd->execute();
              $upd->close();
            }
          }
        } else {
          // Provide clearer error for duplicate key on user_id
          if ($conn->errno === 1062) {
            $errors[] = 'ไม่สามารถเพิ่มเจ้าหน้าที่ได้ (ค่าซ้ำใน user_id) - โปรดปรับโครงสร้างฐานข้อมูลก่อน';
          } else {
            $errors[] = 'ไม่สามารถเพิ่มเจ้าหน้าที่ได้: ' . $conn->error;
          }
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
$hres = $conn->query('SELECT hosp_shph_id, hosp_name FROM hospitals ORDER BY hosp_name');
if ($hres) while ($r = $hres->fetch_assoc()) $hospitals[] = $r;

// Build hospital id -> name map for display
// Create both string-key and numeric-key maps so lookup works even if
// users.hosp_shph_id was stored without leading zeros (e.g. 06808 -> 6808)
$hmap = [];
$hmap_num = [];
foreach ($hospitals as $hh) {
  $k = $hh['hosp_shph_id'];
  $hmap[(string)$k] = $hh['hosp_name'];
  $hmap_num[(int)$k] = $hh['hosp_name'];
}

$filter_hospital = isset($_GET['hospital_id']) && $_GET['hospital_id'] !== '' ? (string)$_GET['hospital_id'] : '';
$staff = [];
if ($filter_hospital !== '') {
  $s = $conn->prepare('SELECT user_id, user_username, user_fname, user_lname, user_phone, hosp_shph_id FROM users WHERE user_role = "staff" AND hosp_shph_id = ? ORDER BY user_fname');
  $s->bind_param('s', $filter_hospital);
} else {
  $s = $conn->prepare('SELECT user_id, user_username, user_fname, user_lname, user_phone, hosp_shph_id FROM users WHERE user_role = "staff" ORDER BY hosp_shph_id, user_fname');
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
  <title>จัดการเจ้าหน้าที่</title>
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
        <h2 class="mb-0">จัดการเจ้าหน้าที่ (Staff)</h2>
        <div class="small text-muted">เพิ่ม/ลบเจ้าหน้าที่ และกรองตามโรงพยาบาล</div>
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
            <select name="hosp_shph_id" class="form-select">
              <option value="">-- เลือกหน่วยบริการ --</option>
              <?php foreach ($hospitals as $h): ?>
                <option value="<?php echo $h['hosp_shph_id']; ?>"><?php echo htmlspecialchars($h['hosp_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-person-plus"></i> เพิ่มเจ้าหน้าที่</button></div>

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
              <option value="">-- แสดงเจ้าหน้าที่ทั้งหมด --</option>
              <?php foreach ($hospitals as $h): ?>
                <option value="<?php echo $h['hosp_shph_id']; ?>" <?php echo ($filter_hospital == $h['hosp_shph_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($h['hosp_name']); ?></option>
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
                <?php
                  $hk = isset($s['hosp_shph_id']) ? $s['hosp_shph_id'] : '';
                  $hname = '-';
                  if ($hk !== '' && isset($hmap[(string)$hk])) {
                      $hname = $hmap[(string)$hk];
                  } elseif ($hk !== '' && isset($hmap_num[(int)$hk])) {
                      $hname = $hmap_num[(int)$hk];
                  }
                ?>
                <td><?php echo htmlspecialchars($hname); ?></td>
                <td class="text-end">
                  <a href="staff.php?delete_user=<?php echo $s['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบเจ้าหน้าที่นี้?')"><i class="bi bi-trash"></i> ลบ</a>
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

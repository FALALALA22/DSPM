<?php
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin();
$user = getUserInfo();

// รับ id
$child_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($child_id <= 0) {
    header('Location: children_list.php');
    exit();
}

// ดึงข้อมูลเด็ก
$stmt = $conn->prepare("SELECT * FROM children WHERE chi_id = ? LIMIT 1");
$stmt->bind_param('i', $child_id);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();
$stmt->close();

if (!$child) {
    $_SESSION['errors'] = ["ไม่พบข้อมูลเด็กที่ต้องการแก้ไข"];
    header('Location: children_list.php');
    exit();
}

// ตรวจสอบสิทธิ์: user ปกติแก้ได้เฉพาะของตัวเอง
if ($user['user_role'] === 'user' && $child['user_id'] != $user['user_id']) {
    $_SESSION['errors'] = ["คุณไม่มีสิทธิ์แก้ไขข้อมูลเด็กนี้"];
    header('Location: children_list.php');
    exit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['child_name']) ? trim($_POST['child_name']) : '';
    $dob = isset($_POST['child_dob']) ? trim($_POST['child_dob']) : '';

    if ($name === '') {
        $errors[] = 'กรุณากรอกชื่อเด็ก';
    }
    if ($dob === '' || !strtotime($dob)) {
        $errors[] = 'กรุณากรอกวันเกิดที่ถูกต้อง';
    }

    // ประมวลผลรูปถ้ามีการอัพโหลด
    $newPhotoPath = '';
    if (isset($_FILES['child_photo']) && $_FILES['child_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['child_photo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['child_photo']['tmp_name'];
            $info = getimagesize($tmp);
            if ($info === false) {
                $errors[] = 'ไฟล์ที่อัปโหลดไม่ใช่รูปภาพที่ถูกต้อง';
            } else {
                $allowed_types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
                if (!isset($allowed_types[$info[2]])) {
                    $errors[] = 'รองรับเฉพาะไฟล์รูปภาพ JPG, PNG, GIF';
                } else {
                    $ext = $allowed_types[$info[2]];
                    $uploadDir = __DIR__ . '/../uploads/children/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $uploadDir . $newName;
                    if (move_uploaded_file($tmp, $dest)) {
                        // เก็บ path แบบ relative
                        $newPhotoPath = 'uploads/children/' . $newName;
                        // ลบรูปเดิมถ้ามีและไฟล์จริง
                        if (!empty($child['chi_photo']) && file_exists(__DIR__ . '/../' . $child['chi_photo'])) {
                            @unlink(__DIR__ . '/../' . $child['chi_photo']);
                        }
                    } else {
                        $errors[] = 'ไม่สามารถย้ายไฟล์รูปไปยังโฟลเดอร์ปลายทางได้';
                    }
                }
            }
        } else {
            $errors[] = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์';
        }
    }

    if (empty($errors)) {
        // คำนวณอายุปี/เดือน
        $dob_dt = new DateTime($dob);
        $now = new DateTime();
        $diff = $dob_dt->diff($now);
        $years = $diff->y;
        $months = $diff->m;

        // สร้าง SQL แบบ dynamic หากไม่มีรูปใหม่ ให้ไม่อัปเดตรูป
        if ($newPhotoPath !== '') {
            $update_sql = "UPDATE children SET chi_child_name = ?, chi_date_of_birth = ?, chi_age_years = ?, chi_age_months = ?, chi_photo = ? WHERE chi_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param('ssissi', $name, $dob, $years, $months, $newPhotoPath, $child_id);
        } else {
            $update_sql = "UPDATE children SET chi_child_name = ?, chi_date_of_birth = ?, chi_age_years = ?, chi_age_months = ? WHERE chi_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param('ssiii', $name, $dob, $years, $months, $child_id);
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = 'อัปเดตข้อมูลเด็กเรียบร้อยแล้ว';
            $stmt->close();
            $conn->close();
            header('Location: children_list.php');
            exit();
        } else {
            $errors[] = 'เกิดข้อผิดพลาดขณะบันทึกข้อมูล';
            $stmt->close();
        }
    }
}

$conn->close();
?>

<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แก้ไขข้อมูลเด็ก</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="card mx-auto" style="max-width:720px;">
        <div class="card-body">
            <h4 class="card-title">แก้ไขข้อมูลเด็ก</h4>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">ชื่อเด็ก</label>
                    <input type="text" name="child_name" class="form-control" value="<?php echo htmlspecialchars($child['chi_child_name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">วันเกิด</label>
                    <input type="date" name="child_dob" class="form-control" value="<?php echo htmlspecialchars($child['chi_date_of_birth']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">รูปภาพ (ถ้าต้องการเปลี่ยน)</label>
                    <?php if (!empty($child['chi_photo']) && file_exists(__DIR__ . '/../' . $child['chi_photo'])): ?>
                        <div class="mb-2">
                            <img src="../<?php echo htmlspecialchars($child['chi_photo']); ?>" alt="photo" style="max-width:150px; max-height:150px; object-fit:cover; border-radius:6px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="child_photo" accept="image/*" class="form-control">
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">บันทึกการเปลี่ยนแปลง</button>
                    <a href="children_list.php" class="btn btn-secondary">ยกเลิก</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

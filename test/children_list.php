<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับค่าการค้นหาและฟิลเตอร์
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : '';

// สร้าง WHERE clause สำหรับการค้นหาและฟิลเตอร์
$whereConditions = [];
$params = [];
$paramTypes = '';

// ถ้าเป็น user ปกติ ให้ดูเฉพาะของตัวเอง
if ($user['user_role'] === 'user') {
    $whereConditions[] = "c.user_id = ?";
    $params[] = $user['user_id'];
    $paramTypes .= 'i';
}

// ถ้าเป็น staff ให้ดูเฉพาะของโรงพยาบาลของตัวเอง
if ($user['user_role'] === 'staff') {
    if (!empty($user['hosp_shph_id'])) {
        $whereConditions[] = "c.hosp_shph_id = ?";
        // use string hospital id (preserve leading zeros)
        $params[] = (string)$user['hosp_shph_id'];
        $paramTypes .= 's';
    }
}

// เพิ่มเงื่อนไขการค้นหาชื่อ
if (!empty($search)) {
    $whereConditions[] = "c.chi_child_name LIKE ?";
    $params[] = '%' . $search . '%';
    $paramTypes .= 's';
}

// เพิ่มฟิลเตอร์ user_id (เฉพาะ admin และ staff)
if (!empty($filter_user) && ($user['user_role'] === 'admin' || $user['user_role'] === 'staff')) {
    $whereConditions[] = "c.user_id = ?";
    $params[] = $filter_user;
    $paramTypes .= 'i';
}

// สร้าง SQL query
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // Admin และ Staff ดูได้ทุกคน พร้อมข้อมูลผู้ปกครอง
        $sql = "SELECT c.*, u.user_fname, u.user_lname, u.user_phone, h.hosp_name AS hospital_name
            FROM children c
            JOIN users u ON c.user_id = u.user_id
            LEFT JOIN hospitals h ON c.hosp_shph_id = h.hosp_shph_id";
    
    if (!empty($whereConditions)) {
        $sql .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    $sql .= " ORDER BY c.chi_created_at DESC";
} else {
    // User ปกติดูได้เฉพาะของตัวเอง
    $sql = "SELECT c.*, h.hosp_name AS hospital_name FROM children c LEFT JOIN hospitals h ON c.hosp_shph_id = h.hosp_shph_id WHERE " . implode(" AND ", $whereConditions);
    $sql .= " ORDER BY c.chi_created_at DESC";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$children = $result->fetch_all(MYSQLI_ASSOC);

// ดึงรายชื่อผู้ใช้ทั้งหมดสำหรับฟิลเตอร์ (เฉพาะ admin และ staff)
$users_list = [];
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // หากเป็น staff ให้จำกัดเฉพาะผู้ปกครองที่อยู่ในโรงพยาบาลเดียวกัน
    if ($user['user_role'] === 'staff' && !empty($user['hosp_shph_id'])) {
        // จำกัดเป็นผู้ปกครองที่มีเด็กในโรงพยาบาลเดียวกับ staff
        $users_sql = "SELECT DISTINCT u.user_id, u.user_fname, u.user_lname
                      FROM users u
                      JOIN children c ON c.user_id = u.user_id
                      WHERE u.user_role = 'user' AND c.hosp_shph_id = ?
                      ORDER BY u.user_fname, u.user_lname";
        $users_stmt = $conn->prepare($users_sql);
        // bind hospital id as string
        $uh = (string)$user['hosp_shph_id'];
        $users_stmt->bind_param('s', $uh);
    } else {
        $users_sql = "SELECT user_id, user_fname, user_lname FROM users WHERE user_role = 'user' ORDER BY user_fname, user_lname";
        $users_stmt = $conn->prepare($users_sql);
    }
    $users_stmt->execute();
    $users_result = $users_stmt->get_result();
    $users_list = $users_result->fetch_all(MYSQLI_ASSOC);
    $users_stmt->close();
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายชื่อเด็ก - DSPM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        .child-card {
            transition: transform 0.2s;
            position: relative;
            overflow: hidden;
        }
        .child-card:hover {
            transform: translateY(-5px);
        }
        .child-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
        }
        .no-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 12px;
        }
        .edit-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 1050;
            width: 40px;
            height: 40px;
            padding: 0;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1;
            background: rgba(255,255,255,0.98);
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
        }
        .edit-icon svg { display: block; width: 18px; height: 18px; }

        /* Responsive tweaks for small screens */
        @media (max-width: 576px) {
            .edit-icon {
                width: 36px;
                height: 36px;
                padding: 0;
                font-size: 12px;
                top: 8px;
                right: 8px;
            }
            .edit-icon svg { width: 16px; height: 16px; }
            .child-photo {
                width: 64px;
                height: 64px;
            }
            .no-photo {
                width: 64px;
                height: 64px;
                font-size: 11px;
            }
            /* Ensure the absolute button doesn't cover the card content */
            .card-body {
                padding-top: 40px;
            }
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="mainpage.php">DSPM System</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    สวัสดี, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?>
                    <?php if ($user['user_role'] === 'admin'): ?>
                        <span class="badge bg-danger ms-1">Admin</span>
                    <?php elseif ($user['user_role'] === 'staff'): ?>
                        <span class="badge bg-warning text-dark ms-1">Staff</span>
                    <?php endif; ?>
                </span>
                <?php if ($user['user_role'] === 'user'): ?>
                    <a class="btn btn-outline-light btn-sm me-2" href="kidinfo.php">เพิ่มข้อมูลเด็ก</a>
                <?php endif; ?>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                        <h1 style="color: #149ee9;">รายชื่อเด็กทั้งหมดในระบบ</h1>
                        <div>
                            <span class="badge bg-info me-2">ทั้งหมด: <?php echo count($children); ?> คน</span>
                            <?php if ($user['user_role'] === 'admin'): ?>
                                <a href="user_management.php" class="btn btn-warning me-2">จัดการผู้ใช้</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <h1 style="color: #149ee9;">รายชื่อเด็ก</h1>
                        <a href="kidinfo.php" class="btn btn-success">
                            <i class="fas fa-plus"></i> เพิ่มข้อมูลเด็ก
                        </a>
                    <?php endif; ?>
                </div>

                <!-- แสดงข้อความสำเร็จ -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success']); ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <!-- ฟอร์มค้นหาและฟิลเตอร์ -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-6">
                                <label for="search" class="form-label">ค้นหาชื่อเด็ก</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="กรอกชื่อเด็กที่ต้องการค้นหา">
                            </div>
                            <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                            <div class="col-md-4">
                                <label for="filter_user" class="form-label">ฟิลเตอร์ตามผู้ปกครอง</label>
                                <select class="form-select" id="filter_user" name="filter_user">
                                    <option value="">-- แสดงทั้งหมด --</option>
                                    <?php foreach ($users_list as $usr): ?>
                                        <option value="<?php echo $usr['user_id']; ?>" 
                                                <?php echo ($filter_user == $usr['user_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($usr['user_fname'] . ' ' . $usr['user_lname']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                            <?php else: ?>
                            <div class="col-md-6">
                            <?php endif; ?>
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> ค้นหา
                                    </button>
                                    <a href="children_list.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> ล้าง
                                    </a>
                                </div>
                            </div>
                        </form>
                        
                        <!-- แสดงผลการค้นหา -->
                        <?php if (!empty($search) || !empty($filter_user)): ?>
                            <div class="mt-3">
                                <div class="alert alert-info mb-0">
                                    <strong>ผลการค้นหา:</strong>
                                    <?php if (!empty($search)): ?>
                                        ค้นหา "<strong><?php echo htmlspecialchars($search); ?></strong>"
                                    <?php endif; ?>
                                    <?php if (!empty($filter_user)): ?>
                                        <?php
                                        $selected_user = array_filter($users_list, function($u) use ($filter_user) {
                                            return $u['user_id'] == $filter_user;
                                        });
                                        if (!empty($selected_user)) {
                                            $selected_user = reset($selected_user);
                                            echo (!empty($search) ? ' และ' : '') . ' ฟิลเตอร์ผู้ปกครอง "<strong>' . htmlspecialchars($selected_user['user_fname'] . ' ' . $selected_user['user_lname']) . '</strong>"';
                                        }
                                        ?>
                                    <?php endif; ?>
                                    | พบ <strong><?php echo count($children); ?></strong> รายการ
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($children)): ?>
                    <!-- ถ้าไม่มีข้อมูลเด็ก -->
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <img src="../image/baby-33253_1280.png" alt="No children" style="max-width: 200px; opacity: 0.5;">
                        </div>
                        <?php if (!empty($search) || !empty($filter_user)): ?>
                            <h3 class="text-muted">ไม่พบข้อมูลที่ค้นหา</h3>
                            <p class="text-muted">ลองเปลี่ยนคำค้นหาหรือฟิลเตอร์ใหม่</p>
                            <a href="children_list.php" class="btn btn-secondary">ดูทั้งหมด</a>
                        <?php elseif ($user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                            <h3 class="text-muted">ยังไม่มีข้อมูลเด็กในระบบ</h3>
                            <p class="text-muted">รอให้ผู้ใช้ลงทะเบียนข้อมูลเด็ก</p>
                        <?php else: ?>
                            <h3 class="text-muted">ยังไม่มีข้อมูลเด็ก</h3>
                            <p class="text-muted">คลิกปุ่มด้านล่างเพื่อเพิ่มข้อมูลเด็กคนแรก</p>
                            <a href="kidinfo.php" class="btn btn-primary btn-lg">เพิ่มข้อมูลเด็ก</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- แสดงรายการเด็ก -->
                    <div class="row">
                        <?php foreach ($children as $child): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card child-card h-100 shadow-sm">
                                    <?php if ($user['user_role'] === 'user' || $user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                                        <a href="edit_child.php?id=<?php echo $child['chi_id']; ?>" 
                                           class="btn btn-sm btn-outline-warning edit-icon" 
                                           title="แก้ไขข้อมูลเด็ก">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                              <path d="M12.146.854a.5.5 0 0 1 .708 0l2.292 2.292a.5.5 0 0 1 0 .708l-9.793 9.793a.5.5 0 0 1-.168.11l-4 1.5a.5.5 0 0 1-.65-.65l1.5-4a.5.5 0 0 1 .11-.168L12.146.854zM11.207 2L3 10.207V13h2.793L14 4.793 11.207 2z"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                    <div class="card-body text-center">
                                        <!-- รูปภาพเด็ก -->
                                        <div class="mb-3">
                                            <?php if ($child['chi_photo'] && file_exists('../' . $child['chi_photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($child['chi_photo']); ?>" 
                                                     alt="รูปภาพของ <?php echo htmlspecialchars($child['chi_child_name']); ?>" 
                                                     class="child-photo">
                                            <?php else: ?>
                                                <div class="no-photo">
                                                    ไม่มีรูป
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ข้อมูลเด็ก -->
                                        <h5 class="card-title"><?php echo htmlspecialchars($child['chi_child_name']); ?></h5>
                                        <p class="card-text">
                                            <strong>วันเกิด:</strong> <?php echo date('d/m/Y', strtotime($child['chi_date_of_birth'])); ?><br>
                                            <strong>อายุ:</strong> <?php echo $child['chi_age_years']; ?> ปี <?php echo $child['chi_age_months']; ?> เดือน<br>
                                            <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                                                <strong>ผู้ปกครอง:</strong> <?php echo htmlspecialchars($child['user_fname'] . ' ' . $child['user_lname']); ?><br>
                                                <strong>เบอร์โทร:</strong> <?php echo htmlspecialchars($child['user_phone']); ?><br>
                                                <strong>โรงพยาบาล:</strong> <?php echo htmlspecialchars(!empty($child['hospital_name']) ? $child['hospital_name'] : $child['hosp_shph_id']); ?><br>
                                            <?php endif; ?>
                                            <small class="text-muted">เพิ่มเมื่อ: <?php echo date('d/m/Y H:i', strtotime($child['chi_created_at'])); ?></small>
                                        </p>

                                        <!-- ปุ่มจัดการ -->
                                        <div class="btn-group" role="group">
                                            <a href="child_detail.php?id=<?php echo $child['chi_id']; ?>" class="btn btn-primary btn-sm">ดูรายละเอียด</a>
                                            <?php if ($user['user_role'] === 'user' || $user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                                                <!--<a href="edit_child.php?id=<?php //echo $child['chi_id']; ?>" class="btn btn-warning btn-sm">แก้ไข</a>-->
                                                <a href="delete_child.php?id=<?php echo $child['chi_id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลเด็กคนนี้?')">ลบ</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- สถิติ -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <strong>สถิติ:</strong> 
                                <?php if (!empty($search) || !empty($filter_user)): ?>
                                    แสดงผลการค้นหา <?php echo count($children); ?> รายการ
                                <?php else: ?>
                                    <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff'): ?>
                                        มีข้อมูลเด็กทั้งหมดในระบบ <?php echo count($children); ?> คน
                                    <?php else: ?>
                                        คุณมีข้อมูลเด็กทั้งหมด <?php echo count($children); ?> คน
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ปุ่มกลับ -->
                <div class="text-center mt-4">
                    <a href="mainpage.php" class="btn btn-secondary">กลับหน้าหลัก</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

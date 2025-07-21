<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// ดึงข้อมูลเด็กทั้งหมดของผู้ใช้
$sql = "SELECT * FROM children WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$children = $result->fetch_all(MYSQLI_ASSOC);
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
                </span>
                <a class="btn btn-outline-light btn-sm me-2" href="kidinfo.php">เพิ่มข้อมูลเด็ก</a>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 style="color: #149ee9;">รายชื่อเด็ก</h1>
                    <a href="kidinfo.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> เพิ่มข้อมูลเด็ก
                    </a>
                </div>

                <!-- แสดงข้อความสำเร็จ -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success']); ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                    <!-- ถ้าไม่มีข้อมูลเด็ก -->
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <img src="../image/baby-33253_1280.png" alt="No children" style="max-width: 200px; opacity: 0.5;">
                        </div>
                        <h3 class="text-muted">ยังไม่มีข้อมูลเด็ก</h3>
                        <p class="text-muted">คลิกปุ่มด้านล่างเพื่อเพิ่มข้อมูลเด็กคนแรก</p>
                        <a href="kidinfo.php" class="btn btn-primary btn-lg">เพิ่มข้อมูลเด็ก</a>
                    </div>
                <?php else: ?>
                    <!-- แสดงรายการเด็ก -->
                    <div class="row">
                        <?php foreach ($children as $child): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card child-card h-100 shadow-sm">
                                    <div class="card-body text-center">
                                        <!-- รูปภาพเด็ก -->
                                        <div class="mb-3">
                                            <?php if ($child['photo_path'] && file_exists('../' . $child['photo_path'])): ?>
                                                <img src="../<?php echo htmlspecialchars($child['photo_path']); ?>" 
                                                     alt="รูปภาพของ <?php echo htmlspecialchars($child['child_name']); ?>" 
                                                     class="child-photo">
                                            <?php else: ?>
                                                <div class="no-photo">
                                                    ไม่มีรูป
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ข้อมูลเด็ก -->
                                        <h5 class="card-title"><?php echo htmlspecialchars($child['child_name']); ?></h5>
                                        <p class="card-text">
                                            <strong>วันเกิด:</strong> <?php echo date('d/m/Y', strtotime($child['date_of_birth'])); ?><br>
                                            <strong>อายุ:</strong> <?php echo $child['age_years']; ?> ปี <?php echo $child['age_months']; ?> เดือน<br>
                                            <small class="text-muted">เพิ่มเมื่อ: <?php echo date('d/m/Y H:i', strtotime($child['created_at'])); ?></small>
                                        </p>

                                        <!-- ปุ่มจัดการ -->
                                        <div class="btn-group" role="group">
                                            <a href="child_detail.php?id=<?php echo $child['id']; ?>" class="btn btn-primary btn-sm">ดูรายละเอียด</a>
                                            <a href="edit_child.php?id=<?php echo $child['id']; ?>" class="btn btn-warning btn-sm">แก้ไข</a>
                                            <a href="delete_child.php?id=<?php echo $child['id']; ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลเด็กคนนี้?')">ลบ</a>
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
                                <strong>สถิติ:</strong> คุณมีข้อมูลเด็กทั้งหมด <?php echo count($children); ?> คน
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

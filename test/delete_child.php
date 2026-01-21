<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับ ID ของเด็กที่ต้องการลบ
$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($child_id == 0) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการลบ";
    header("Location: children_list.php");
    exit();
}

// ตรวจสอบสิทธิ์การลบ
// - User สามารถลบเฉพาะเด็กของตัวเองได้
// - Admin และ Staff สามารถลบได้ทุกคน

// ดึงข้อมูลเด็กเพื่อตรวจสอบ (Admin/Staff ดูได้ทุกคน, User เฉพาะของตัวเอง)
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    $sql = "SELECT c.*, u.user_fname, u.user_lname 
            FROM children c 
            JOIN users u ON c.user_id = u.user_id 
            WHERE c.chi_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
} else {
    $sql = "SELECT * FROM children WHERE chi_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $child_id, $user['user_id']);
}

$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();
$stmt->close();

if (!$child) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการลบ หรือคุณไม่มีสิทธิ์ในการลบข้อมูลนี้";
    header("Location: children_list.php");
    exit();
}

// ถ้ามีการส่งฟอร์มยืนยันการลบ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_delete'])) {
    // เริ่ม transaction
    $conn->begin_transaction();
    
    try {
        // ลบข้อมูลการประเมินก่อน (foreign key constraint)
        $delete_evaluations_sql = "DELETE FROM evaluations WHERE eva_id = ?";
        $delete_eval_stmt = $conn->prepare($delete_evaluations_sql);
        $delete_eval_stmt->bind_param("i", $child_id);
        $delete_eval_stmt->execute();
        $delete_eval_stmt->close();
        
        // ลบรูปภาพถ้ามี
        if ($child['chi_photo'] && file_exists('../' . $child['chi_photo'])) {
            unlink('../' . $child['chi_photo']);
        }
        
        // ลบข้อมูลเด็ก
        $delete_child_sql = "DELETE FROM children WHERE chi_id = ?";
        $delete_child_stmt = $conn->prepare($delete_child_sql);
        $delete_child_stmt->bind_param("i", $child_id);
        $delete_child_stmt->execute();
        $delete_child_stmt->close();
        
        // ยืนยัน transaction
        $conn->commit();
        
        $_SESSION['success'] = "ลบข้อมูลเด็ก " . htmlspecialchars($child['chi_child_name']) . " เรียบร้อยแล้ว";
        header("Location: children_list.php");
        exit();
        
    } catch (Exception $e) {
        // ยกเลิก transaction หากมีข้อผิดพลาด
        $conn->rollback();
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        header("Location: children_list.php");
        exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลบข้อมูลเด็ก - DSPM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        .child-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
        }
        .no-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 14px;
        }
        .warning-card {
            border-left: 5px solid #dc3545;
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
                    <?php endif; ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="children_list.php">กลับรายชื่อเด็ก</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white text-center">
                        <h4><i class="fas fa-exclamation-triangle"></i> ยืนยันการลบข้อมูล</h4>
                    </div>
                    <div class="card-body">
                        <!-- ข้อมูลเด็ก -->
                        <div class="text-center mb-4">
                            <?php if ($child['chi_photo'] && file_exists('../' . $child['chi_photo'])): ?>
                                <img src="../<?php echo htmlspecialchars($child['chi_photo']); ?>" 
                                     alt="รูปภาพของ <?php echo htmlspecialchars($child['chi_child_name']); ?>" 
                                     class="child-photo mb-3">
                            <?php else: ?>
                                <div class="no-photo mb-3 mx-auto">
                                    ไม่มีรูป
                                </div>
                            <?php endif; ?>
                            <h5><?php echo htmlspecialchars($child['chi_child_name']); ?></h5>
                        </div>

                        <!-- รายละเอียดเด็ก -->
                        <div class="card warning-card mb-4">
                            <div class="card-body">
                                <h6 class="card-title text-danger">ข้อมูลที่จะถูกลบ:</h6>
                                <div class="row">
                                    <div class="col-sm-4"><strong>ชื่อเด็ก:</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($child['chi_child_name']); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>วันเกิด:</strong></div>
                                    <div class="col-sm-8"><?php echo date('d/m/Y', strtotime($child['chi_date_of_birth'])); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>อายุ:</strong></div>
                                    <div class="col-sm-8"><?php echo $child['chi_age_years']; ?> ปี <?php echo $child['chi_age_months']; ?> เดือน</div>
                                </div>
                                <?php if ($user['user_role'] === 'admin' && isset($child['user_fname'])): ?>
                                    <div class="row">
                                        <div class="col-sm-4"><strong>ผู้ปกครอง:</strong></div>
                                        <div class="col-sm-8"><?php echo htmlspecialchars($child['user_fname'] . ' ' . $child['user_lname']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="row">
                                    <div class="col-sm-4"><strong>เพิ่มเมื่อ:</strong></div>
                                    <div class="col-sm-8"><?php echo date('d/m/Y H:i', strtotime($child['chi_created_at'])); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- คำเตือน -->
                        <div class="alert alert-danger" role="alert">
                            <h6 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> คำเตือนสำคัญ!</h6>
                            <p class="mb-2">การดำเนินการนี้จะลบข้อมูลต่อไปนี้อย่างถาวร:</p>
                            <ul class="mb-2">
                                <li>ข้อมูลส่วนตัวของเด็ก</li>
                                <li>ประวัติการประเมินพัฒนาการทั้งหมด</li>
                                <li>รูปภาพของเด็ก (หากมี)</li>
                                <li>ข้อมูลที่เกี่ยวข้องทั้งหมด</li>
                            </ul>
                            <hr>
                            <p class="mb-0"><strong>การดำเนินการนี้ไม่สามารถยกเลิกได้!</strong></p>
                        </div>

                        <!-- ฟอร์มยืนยัน -->
                        <form method="POST" action="">
                            <div class="d-grid gap-2">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <a href="children_list.php" class="btn btn-secondary w-100">
                                            <i class="fas fa-arrow-left"></i> ยกเลิก
                                        </a>
                                    </div>
                                    <div class="col-sm-6">
                                        <button type="submit" name="confirm_delete" class="btn btn-danger w-100" 
                                                onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลเด็กคนนี้? การกระทำนี้ไม่สามารถยกเลิกได้!')">
                                            <i class="fas fa-trash"></i> ยืนยันการลบ
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ข้อมูลเพิ่มเติม -->
                <div class="card mt-4">
                    <div class="card-body text-muted text-center">
                        <small>
                            <i class="fas fa-info-circle"></i> 
                            หากคุณต้องการเก็บข้อมูลไว้ แนะนำให้พิมพ์หรือบันทึกข้อมูลก่อนการลบ
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://kit.fontawesome.com/your-fontawesome-kit.js" crossorigin="anonymous"></script>
</body>
</html>
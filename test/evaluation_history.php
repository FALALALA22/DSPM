<?php
session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับ ID ของเด็ก
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

if ($child_id == 0) {
    header("Location: children_list.php");
    exit();
}

// ดึงข้อมูลเด็ก
$sql = "SELECT * FROM children WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $child_id, $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการ";
    header("Location: children_list.php");
    exit();
}

// ดึงผลการประเมินทั้งหมดของเด็กคนนี้
$sql = "SELECT * FROM evaluations WHERE child_id = ? ORDER BY evaluation_date DESC, age_range";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$evaluations = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลการประเมินย้อนหลัง - <?php echo htmlspecialchars($child['child_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        .evaluation-card {
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .evaluation-card:hover {
            transform: translateY(-3px);
        }
        .score-badge {
            font-size: 1.2em;
            padding: 8px 15px;
        }
        .passed { background-color: #28a745; }
        .failed { background-color: #dc3545; }
        .partial { background-color: #ffc107; color: #212529; }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="mainpage.php">DSPM System</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    ผลการประเมิน: <?php echo htmlspecialchars($child['child_name']); ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="child_detail.php?id=<?php echo $child['id']; ?>">กลับ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- หัวข้อ -->
        <div class="text-center mb-4">
            <h1 style="color: #149ee9;">ผลการประเมินย้อนหลัง</h1>
            <h3><?php echo htmlspecialchars($child['child_name']); ?></h3>
        </div>

        <!-- แสดงข้อความแจ้งเตือน -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (empty($evaluations)): ?>
            <!-- ถ้าไม่มีผลการประเมิน -->
            <div class="text-center py-5">
                <div class="mb-4">
                    <img src="../image/baby-33253_1280.png" alt="No evaluations" style="max-width: 200px; opacity: 0.5;">
                </div>
                <h3 class="text-muted">ยังไม่มีผลการประเมิน</h3>
                <p class="text-muted">เริ่มประเมินพัฒนาการของ <?php echo htmlspecialchars($child['child_name']); ?> กันเลย!</p>
                <a href="child_detail.php?id=<?php echo $child['id']; ?>" class="btn btn-primary btn-lg">เริ่มประเมิน</a>
            </div>
        <?php else: ?>
            <!-- แสดงผลการประเมิน -->
            <div class="row">
                <?php foreach ($evaluations as $evaluation): ?>
                    <?php
                    $total_questions = 5; // สำหรับช่วงอายุ 0-1 เดือน
                    $percentage = ($evaluation['total_passed'] / $total_questions) * 100;
                    $badge_class = '';
                    if ($percentage >= 80) $badge_class = 'passed';
                    elseif ($percentage >= 50) $badge_class = 'partial';
                    else $badge_class = 'failed';
                    ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card evaluation-card h-100 shadow-sm">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">ช่วงอายุ: <?php echo htmlspecialchars($evaluation['age_range']); ?> เดือน</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <span class="badge score-badge <?php echo $badge_class; ?>">
                                        <?php echo $evaluation['total_passed']; ?>/<?php echo $total_questions; ?>
                                    </span>
                                    <div class="mt-2">
                                        <small class="text-muted"><?php echo round($percentage, 1); ?>% ผ่าน</small>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>ผลการประเมิน:</strong><br>
                                    <span class="text-success">✓ ผ่าน: <?php echo $evaluation['total_passed']; ?> ข้อ</span><br>
                                    <span class="text-danger">✗ ไม่ผ่าน: <?php echo $evaluation['total_failed']; ?> ข้อ</span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>วันที่ประเมิน:</strong><br>
                                    <?php echo date('d/m/Y', strtotime($evaluation['evaluation_date'])); ?>
                                </div>
                                
                                <div class="btn-group w-100" role="group">
                                    <a href="view_evaluation_detail.php?id=<?php echo $evaluation['id']; ?>" 
                                       class="btn btn-primary btn-sm">ดูรายละเอียด</a>
                                    <a href="evaluation1.php?child_id=<?php echo $child['id']; ?>&age_range=<?php echo $evaluation['age_range']; ?>" 
                                       class="btn btn-warning btn-sm">ประเมินใหม่</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- สรุปผลรวม -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">สรุปผลการประเมินทั้งหมด</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h3 class="text-primary"><?php echo count($evaluations); ?></h3>
                                    <p>ครั้งที่ประเมิน</p>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-success"><?php echo array_sum(array_column($evaluations, 'total_passed')); ?></h3>
                                    <p>ข้อที่ผ่าน</p>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-danger"><?php echo array_sum(array_column($evaluations, 'total_failed')); ?></h3>
                                    <p>ข้อที่ไม่ผ่าน</p>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-info"><?php echo date('d/m/Y', strtotime($evaluations[0]['evaluation_date'])); ?></h3>
                                    <p>ประเมินล่าสุด</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ปุ่มจัดการ -->
        <div class="text-center mt-4">
            <a href="child_detail.php?id=<?php echo $child['id']; ?>" class="btn btn-secondary me-2">กลับข้อมูลเด็ก</a>
            <a href="children_list.php" class="btn btn-primary me-2">รายชื่อเด็ก</a>
            <a href="mainpage.php" class="btn btn-success">หน้าหลัก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

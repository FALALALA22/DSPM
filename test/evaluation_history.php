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

// ดึงข้อมูลเด็ก - ตรวจสอบสิทธิ์ตาม role
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // Admin และ Staff ดูได้ทุกคน
    $sql = "SELECT * FROM children WHERE chi_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
} else {
    // User ปกติดูได้เฉพาะของตัวเอง
    $sql = "SELECT * FROM children WHERE chi_id = ? AND chi_user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $child_id, $user['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();
$child = $result->fetch_assoc();

if (!$child) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการ";
    header("Location: children_list.php");
    exit();
}

// ดึงผลการประเมินทั้งหมดของเด็กคนนี้
$sql = "SELECT *, DATE(eva_evaluation_date) as eval_date FROM evaluations WHERE eva_child_id = ? ORDER BY eva_evaluation_date DESC, eva_version DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$evaluations = $result->fetch_all(MYSQLI_ASSOC);

// จัดกลุ่มตามวันที่และช่วงอายุ
$grouped_evaluations = array();
foreach ($evaluations as $evaluation) {
    $key = $evaluation['eval_date'] . '_' . $evaluation['eva_age_range'];
    if (!isset($grouped_evaluations[$key])) {
        $grouped_evaluations[$key] = array(
            'date' => $evaluation['eval_date'],
            'age_range' => $evaluation['eva_age_range'],
            'versions' => array()
        );
    }
    $grouped_evaluations[$key]['versions'][] = $evaluation;
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลการประเมินย้อนหลัง - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
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
                    ผลการประเมิน: <?php echo htmlspecialchars($child['chi_child_name']); ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="child_detail.php?id=<?php echo $child['chi_id']; ?>">กลับ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- หัวข้อ -->
        <div class="text-center mb-4">
            <h1 style="color: #149ee9;">ผลการประเมินย้อนหลัง</h1>
            <h3><?php echo htmlspecialchars($child['chi_child_name']); ?></h3>
        </div>

        <!-- แสดงข้อความแจ้งเตือน -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (empty($grouped_evaluations)): ?>
            <!-- ถ้าไม่มีผลการประเมิน -->
            <div class="text-center py-5">
                <div class="mb-4">
                    <img src="../image/baby-33253_1280.png" alt="No evaluations" style="max-width: 200px; opacity: 0.5;">
                </div>
                <h3 class="text-muted">ยังไม่มีผลการประเมิน</h3>
                <p class="text-muted">เริ่มประเมินพัฒนาการของ <?php echo htmlspecialchars($child['chi_child_name']); ?> กันเลย!</p>
                <a href="child_detail.php?id=<?php echo $child['chi_id']; ?>" class="btn btn-primary btn-lg">เริ่มประเมิน</a>
            </div>
        <?php else: ?>
            <!-- แสดงผลการประเมิน -->
            <?php foreach ($grouped_evaluations as $group): ?>
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">ช่วงอายุ: <?php echo htmlspecialchars($group['age_range']); ?> เดือน</h5>
                            <span class="badge bg-light text-dark">
                                <?php echo date('d/m/Y', strtotime($group['date'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($group['versions']) > 1): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-history"></i> มีการประเมินซ้ำ <?php echo count($group['versions']); ?> ครั้งในวันนี้
                            </div>
                        <?php endif; ?>
                        
                        <!-- แสดงแต่ละ version -->
                        <?php foreach ($group['versions'] as $index => $evaluation): ?>
                            <?php
                            $total_questions = 5; // สำหรับช่วงอายุ 0-1 เดือน
                            $percentage = ($evaluation['eva_total_score'] / $total_questions) * 100;
                            $badge_class = '';
                            if ($percentage >= 80) $badge_class = 'passed';
                            elseif ($percentage >= 50) $badge_class = 'partial';
                            else $badge_class = 'failed';
                            
                            $version_label = count($group['versions']) > 1 ? 
                                (count($group['versions']) - $index == 1 ? 'ล่าสุด' : 'ครั้งที่ ' . $evaluation['eva_version']) 
                                : '';
                            ?>
                            
                            <div class="row mb-3 <?php echo $index > 0 ? 'border-top pt-3' : ''; ?>">
                                <div class="col-md-8">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="badge score-badge <?php echo $badge_class; ?> me-2">
                                            <?php echo $evaluation['eva_total_score']; ?>/<?php echo $total_questions; ?>
                                        </span>
                                        <?php if ($version_label): ?>
                                            <span class="badge bg-secondary me-2"><?php echo $version_label; ?></span>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <?php 
                                            // แสดงเวลาที่ถูกต้อง
                                            $eval_datetime = $evaluation['eva_evaluation_time'];
                                            if (strtotime($eval_datetime)) {
                                                echo date('H:i', strtotime($eval_datetime));
                                            } else {
                                                echo "ไม่ระบุเวลา";
                                            }
                                            ?> น.
                                        </small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <span class="text-success">✓ ผ่าน: <?php echo $evaluation['eva_total_score']; ?> ข้อ</span> |
                                        <span class="text-danger">✗ ไม่ผ่าน: <?php echo ($evaluation['eva_total_questions'] - $evaluation['eva_total_score']); ?> ข้อ</span> |
                                        <span class="text-info"><?php echo round($percentage, 1); ?>% ผ่าน</span>
                                    </div>
                                    
                                    <?php if ($evaluation['eva_notes']): ?>
                                        <div class="alert alert-light p-2">
                                            <small><strong>หมายเหตุ:</strong> <?php echo htmlspecialchars($evaluation['eva_notes']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="btn-group-vertical" role="group">
                                        <a href="view_evaluation_detail.php?id=<?php echo $evaluation['eva_id']; ?>" 
                                           class="btn btn-primary btn-sm">ดูรายละเอียด</a>
                                        <?php if ($index == 0): // แสดงปุ่มประเมินใหม่เฉพาะ version ล่าสุด ?>
                                            <a href="evaluation1.php?child_id=<?php echo $child['chi_id']; ?>&age_range=<?php echo $evaluation['eva_age_range']; ?>" 
                                               class="btn btn-warning btn-sm">ประเมินใหม่</a>
                                        <?php endif; ?>
                                        <button class="btn btn-danger btn-sm" 
                                                onclick="deleteEvaluation(<?php echo $evaluation['eva_id']; ?>)">ลบ</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

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
                                    <h3 class="text-success"><?php echo array_sum(array_column($evaluations, 'eva_total_score')); ?></h3>
                                    <p>ข้อที่ผ่านรวม</p>
                                </div>
                                <div class="col-md-3">
                                    <?php 
                                    $total_failed = 0;
                                    foreach($evaluations as $eval) {
                                        $total_failed += ($eval['eva_total_questions'] - $eval['eva_total_score']);
                                    }
                                    ?>
                                    <h3 class="text-danger"><?php echo $total_failed; ?></h3>
                                    <p>ข้อที่ไม่ผ่านรวม</p>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-info"><?php echo date('d/m/Y', strtotime($evaluations[0]['eva_evaluation_date'])); ?></h3>
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
            <a href="child_detail.php?id=<?php echo $child['chi_id']; ?>" class="btn btn-secondary me-2">กลับข้อมูลเด็ก</a>
            <a href="children_list.php" class="btn btn-primary me-2">รายชื่อเด็ก</a>
            <a href="mainpage.php" class="btn btn-success">หน้าหลัก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteEvaluation(evaluationId) {
            if (confirm('คุณแน่ใจหรือไม่ที่จะลบผลการประเมินนี้?\n\nการลบจะไม่สามารถกู้คืนได้')) {
                // ส่งไปยังหน้าลบ
                window.location.href = 'delete_evaluation.php?id=' + evaluationId + '&child_id=<?php echo $child['chi_id']; ?>';
            }
        }
    </script>
</body>
</html>

<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

// รับ ID ของเด็ก
$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($child_id == 0) {
    header("Location: children_list.php");
    exit();
}

// ดึงข้อมูลเด็ก - ตรวจสอบสิทธิ์ตาม role
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // Admin และ Staff ดูได้ทุกคน พร้อมข้อมูลผู้ปกครอง
    $sql = "SELECT c.*, u.user_fname, u.user_lname, u.user_phone 
            FROM children c 
            JOIN users u ON c.chi_user_id = u.user_id 
            WHERE c.chi_id = ?";
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

$stmt->close();


// คำนวณอายุปัจจุบันของเด็ก
$birth_date = new DateTime($child['chi_date_of_birth']);
$current_date = new DateTime();
$age_diff = $birth_date->diff($current_date);
$current_age_months = ($age_diff->y * 12) + $age_diff->m;

// ดึงรายการช่วงอายุที่มีการประเมินแล้วสำหรับเด็กคนนี้
$completed_ranges = [];
$stmt2 = $conn->prepare("SELECT DISTINCT eva_age_range FROM evaluations WHERE eva_child_id = ?");
if ($stmt2) {
    $stmt2->bind_param('i', $child_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) {
        $completed_ranges[] = $r['eva_age_range'];
    }
    $stmt2->close();
}
    $latest_eval = null;
    // ดึงสรุปผลการประเมินล่าสุดสำหรับเด็กคนนี้ (ถ้าเป็น admin หรือ staff)
    if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
        $stmt3 = $conn->prepare("SELECT e.*, u.user_fname, u.user_lname FROM evaluations e LEFT JOIN users u ON e.eva_user_id = u.user_id WHERE e.eva_child_id = ? ORDER BY e.eva_evaluation_date DESC, e.eva_evaluation_time DESC, e.eva_version DESC LIMIT 1");
        if ($stmt3) {
            $stmt3->bind_param('i', $child_id);
            $stmt3->execute();
            $res3 = $stmt3->get_result();
            $latest_eval = $res3->fetch_assoc();
            $stmt3->close();
        }
    }
    $conn->close();

    // ดึงสถิติโดยรวมของการประเมินสำหรับเด็กคนนี้ (สำหรับการแสดงสรุป)
    $summary = null;
    $conn2 = new mysqli('localhost', 'root', '', 'dspm_db');
    if ($conn2->connect_errno === 0) {
        $s = $conn2->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(eva_total_score),0) AS total_score, COALESCE(SUM(eva_total_questions),0) AS total_questions, MAX(eva_evaluation_date) AS latest_date FROM evaluations WHERE eva_child_id = ?");
        if ($s) {
            $s->bind_param('i', $child_id);
            $s->execute();
            $res = $s->get_result();
            $summary = $res->fetch_assoc();
            $s->close();
        }
        $conn2->close();
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลเด็ก - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        .age-button {
            width: 120px;
            height: 80px;
            margin: 10px;
            border: 2px solid #ccc;
            border-radius: 10px;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .age-button:hover {
            background-color: #007bff;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,123,255,0.3);
        }
        
        .age-button.available {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        /* กำหนดสไตล์สำหรับช่วงอายุที่ประเมินแล้ว (สีเขียว) */
        .age-button.completed {
            background-color: #28a745;
            color: white;
            border-color: #28a745;
        }
        
        .child-profile {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .child-photo-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .no-photo-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            border: 5px solid white;
        }
        
        .section-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .collapse-icon {
            transition: transform 0.3s ease;
        }
        
        [data-bs-toggle="collapse"]:not(.collapsed) .collapse-icon {
            transform: rotate(180deg);
        }
        /* Blue gradient headers for age-range cards */
        .age-range-header {
            cursor: pointer;
            color: #fff;
        }
        .age-range-header.blue-1 { background: #0d6efd; }
        .age-range-header.blue-2 { background: #0b5ed7; }
        .age-range-header.blue-3 { background: #0a58ca; }
        .age-range-header.blue-4 { background: #1c7ed6; }
        .age-range-header.blue-5 { background: #3fa9ff; }
        .age-range-header.blue-6 { background: #9fd8ff; color: #000; }
        /* History button styling to match page theme */
        .history-btn {
            background-color: #149ee9;
            border-color: #149ee9;
            color: #fff;
        }
        .history-btn:hover {
            background-color: #0f8bd0;
            border-color: #0f8bd0;
            color: #fff;
            text-decoration: none;
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
                <a class="btn btn-outline-light btn-sm me-2" href="children_list.php">รายชื่อเด็ก</a>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- หัวข้อหลัก -->
        <div class="text-center mb-4">
            <h1 style="color: #149ee9;">แบบประเมินพัฒนาการเด็ก</h1>
        </div>
        <!-- ข้อมูลเด็ก -->
        <div class="child-profile">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <?php if ($child['chi_photo'] && file_exists('../' . $child['chi_photo'])): ?>
                        <img src="../<?php echo htmlspecialchars($child['chi_photo']); ?>" 
                             alt="รูปภาพของ <?php echo htmlspecialchars($child['chi_child_name']); ?>" 
                             class="child-photo-large">
                    <?php else: ?>
                        <div class="no-photo-large">
                            ไม่มีรูป
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-9">
                    <h2><?php echo htmlspecialchars($child['chi_child_name']); ?></h2>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p><strong>วันเกิด:</strong> <?php echo date('d/m/Y', strtotime($child['chi_date_of_birth'])); ?></p>
                            <p><strong>อายุ:</strong> <?php echo $child['chi_age_years']; ?> ปี <?php echo $child['chi_age_months']; ?> เดือน</p>
                            <?php if (($user['user_role'] === 'admin' || $user['user_role'] === 'staff') && isset($child['user_fname'])): ?>
                                <p><strong>ผู้ปกครอง:</strong> <?php echo htmlspecialchars($child['user_fname'] . ' ' . $child['user_lname']); ?></p>
                                <p><strong>เบอร์โทร:</strong> <?php echo htmlspecialchars($child['user_phone']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <p><strong>อายุปัจจุบัน:</strong> <?php echo floor($current_age_months / 12); ?> ปี <?php echo $current_age_months % 12; ?> เดือน</p>
                            <p><strong>เพิ่มข้อมูลเมื่อ:</strong> <?php echo date('d/m/Y H:i', strtotime($child['chi_created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- สรุปผลการประเมินล่าสุด (สำหรับ admin/staff) -->
        <?php if (($user['user_role'] === 'admin' || $user['user_role'] === 'staff')): ?>
            <div class="mb-4">
                <?php if ($summary && $summary['cnt'] > 0):
                    $total_evals = (int)$summary['cnt'];
                    $total_score = (int)$summary['total_score'];
                    $total_questions = (int)$summary['total_questions'];
                    $total_failed = $total_questions - $total_score;
                    $overall_pct = $total_questions > 0 ? round(($total_score / $total_questions) * 100, 1) : 0;
                    $latest_dt = !empty($summary['latest_date']) ? date('d/m/Y', strtotime($summary['latest_date'])) : '';
                ?>
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">สรุปผลการประเมินทั้งหมด</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-2">
                                            <h3 class="text-primary"><?php echo $total_evals; ?></h3>
                                            <p>ครั้งที่ประเมิน</p>
                                        </div>
                                        <div class="col-md-2">
                                            <h3 class="text-success"><?php echo $total_score; ?></h3>
                                            <p>ข้อที่ผ่านรวม</p>
                                        </div>
                                        <div class="col-md-2">
                                            <h3 class="text-danger"><?php echo $total_failed; ?></h3>
                                            <p>ข้อที่ไม่ผ่านรวม</p>
                                        </div>
                                        <div class="col-md-2">
                                            <h3 class="text-info"><?php echo $total_questions; ?></h3>
                                            <p>ข้อทั้งหมด</p>
                                        </div>
                                        <div class="col-md-2">
                                            <h3 class="text-warning"><?php echo $overall_pct; ?>%</h3>
                                            <p>เปอร์เซ็นต์รวม</p>
                                        </div>
                                        <div class="col-md-2">
                                            <h3 class="text-muted"><?php echo $latest_dt; ?></h3>
                                            <p>ประเมินล่าสุด</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary">ยังไม่มีการประเมินสำหรับเด็กคนนี้</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ปุ่มเรียกดูผลย้อนหลัง (ย้ายขึ้นมาเป็นปุ่มด้านบน) -->
        <div class="mb-4 text-center">
            <h3 class="text-center mb-3" style="color: #008cffff;">เรียกดูผลการประเมินที่ทำไปแล้ว</h3>
            <a href="evaluation_history.php?child_id=<?php echo $child['chi_id']; ?>" class="btn history-btn btn-lg">
                <i class="fas fa-history"></i> ผลการประเมินย้อนหลัง
            </a>
        </div>

        <!-- ส่วนเลือกช่วงอายุ -->
        <div class="section-header">
            <h3 class="text-center mb-3" style="color: #007bff;">เลือกช่วงอายุสำหรับการประเมิน</h3>
            <div class="text-center mb-3">
                <small class="text-muted">
                    
                </small>
            </div>
        </div>
        <!-- ช่วงอายุ 0-12 เดือน -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-1 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange0-12" aria-expanded="true" aria-controls="ageRange0-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">แรกเกิด-1ปี (0-12 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange0-12" class="collapse show">
                <div class="card-body">
                <div class="row justify-content-center">
                    <!-- แถวที่ 1 -->
                    <div class="col-auto">
                        <a href="evaluation1.php?child_id=<?php echo $child['chi_id']; ?>&age_range=0-1" 
                           class="age-button <?php echo in_array('0-1', $completed_ranges) ? 'completed' : ''; ?>">
                            0-1
                        </a>
                    </div>
                    <div class="col-auto">
                        <a href="evaluation2.php?child_id=<?php echo $child['chi_id']; ?>&age_range=1-2" 
                           class="age-button <?php echo in_array('1-2', $completed_ranges) ? 'completed' : ''; ?>">
                            1-2
                        </a>
                    </div>
                    <div class="col-auto">
                        <a href="evaluation3.php?child_id=<?php echo $child['chi_id']; ?>&age_range=3-4" 
                           class="age-button <?php echo in_array('3-4', $completed_ranges) ? 'completed' : ''; ?>">
                            3-4
                        </a>
                    </div>
                </div>
                
                <div class="row justify-content-center">
                    <!-- แถวที่ 2 -->
                     <div class="col-auto">
                        <a href="evaluation4.php?child_id=<?php echo $child['chi_id']; ?>&age_range=5-6" 
                           class="age-button <?php echo in_array('5-6', $completed_ranges) ? 'completed' : ''; ?>">
                            5-6
                        </a>
                    </div>
                    <div class="col-auto">
                        <a href="evaluation5.php?child_id=<?php echo $child['chi_id']; ?>&age_range=7-8" 
                           class="age-button <?php echo in_array('7-8', $completed_ranges) ? 'completed' : ''; ?>">
                            7-8
                        </a>
                    </div>
                    <div class="col-auto">
                        <a href="evaluation6.php?child_id=<?php echo $child['chi_id']; ?>&age_range=9" 
                           class="age-button <?php echo in_array('9', $completed_ranges) ? 'completed' : ''; ?>">
                            9
                        </a>
                    </div>
                    
                </div>
                <div class="row justify-content-center">
                    <div class="col-auto">
                        <a href="evaluation7.php?child_id=<?php echo $child['chi_id']; ?>&age_range=10-12" 
                           class="age-button <?php echo in_array('10-12', $completed_ranges) ? 'completed' : ''; ?>">
                            10-12
                        </a>
                    </div>
                </div>                
            </div>
        </div>
        </div>

        <!-- ช่วงอายุ 1-2 ปี -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-2 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange1-2years" aria-expanded="false" aria-controls="ageRange1-2years">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">1-2 ปี (12-24 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange1-2years" class="collapse">
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-auto">
                               <a href="evaluation8.php?child_id=<?php echo $child['chi_id']; ?>&age_range=13-15" 
                                   class="age-button <?php echo in_array('13-15', $completed_ranges) ? 'completed' : ''; ?>">
                                13-15
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation9.php?child_id=<?php echo $child['chi_id']; ?>&age_range=16-17" 
                                         class="age-button <?php echo in_array('16-17', $completed_ranges) ? 'completed' : ''; ?>">
                                16-17
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation10.php?child_id=<?php echo $child['chi_id']; ?>&age_range=18" 
                                         class="age-button <?php echo in_array('18', $completed_ranges) ? 'completed' : ''; ?>">
                                18
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation11.php?child_id=<?php echo $child['chi_id']; ?>&age_range=19-24" 
                                         class="age-button <?php echo in_array('19-24', $completed_ranges) ? 'completed' : ''; ?>">
                                19-24
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ช่วงอายุ 2-3 ปี -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-3 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange2-3years" aria-expanded="false" aria-controls="#ageRange2-3years">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">2-3 ปี (24-36 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange2-3years" class="collapse">
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-auto">
                                     <a href="evaluation12.php?child_id=<?php echo $child['chi_id']; ?>&age_range=25-29" 
                                         class="age-button <?php echo in_array('25-29', $completed_ranges) ? 'completed' : ''; ?>">
                                25-29
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation13.php?child_id=<?php echo $child['chi_id']; ?>&age_range=30" 
                                         class="age-button <?php echo in_array('30', $completed_ranges) ? 'completed' : ''; ?>">
                                30
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation14.php?child_id=<?php echo $child['chi_id']; ?>&age_range=31-36" 
                                         class="age-button <?php echo in_array('31-36', $completed_ranges) ? 'completed' : ''; ?>">
                                31-36
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ช่วงอายุ 3-4 ปี -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-4 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange3-4years" aria-expanded="false" aria-controls="#ageRange3-4years">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">3-4 ปี (37-48 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange3-4years" class="collapse">
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-auto">
                                     <a href="evaluation15.php?child_id=<?php echo $child['chi_id']; ?>&age_range=37-41" 
                                         class="age-button <?php echo in_array('37-41', $completed_ranges) ? 'completed' : ''; ?>">
                                37-41
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation16.php?child_id=<?php echo $child['chi_id']; ?>&age_range=42" 
                                         class="age-button <?php echo in_array('42', $completed_ranges) ? 'completed' : ''; ?>">
                                42
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation17.php?child_id=<?php echo $child['chi_id']; ?>&age_range=43-48" 
                                         class="age-button <?php echo in_array('43-48', $completed_ranges) ? 'completed' : ''; ?>">
                                43-48
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ช่วงอายุ 4-5 ปี -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-5 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange4-5years" aria-expanded="false" aria-controls="#ageRange4-5years">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">4-5 ปี (49-60 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange4-5years" class="collapse">
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-auto">
                                     <a href="evaluation18.php?child_id=<?php echo $child['chi_id']; ?>&age_range=49-54" 
                                         class="age-button <?php echo in_array('49-54', $completed_ranges) ? 'completed' : ''; ?>">
                                49-54
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation19.php?child_id=<?php echo $child['chi_id']; ?>&age_range=55-59" 
                                         class="age-button <?php echo in_array('55-59', $completed_ranges) ? 'completed' : ''; ?>">
                                55-59
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation20.php?child_id=<?php echo $child['chi_id']; ?>&age_range=60" 
                                         class="age-button <?php echo in_array('60', $completed_ranges) ? 'completed' : ''; ?>">
                                60
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ช่วงอายุ 5-6 ปี -->
        <div class="card mb-4">
            <div class="card-header age-range-header blue-6 text-white" data-bs-toggle="collapse" data-bs-target="#ageRange5-6years" aria-expanded="false" aria-controls="#ageRange5-6years">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">5-6ปี 6เดือน (61-78 เดือน)</h4>
                    <i class="fas fa-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div id="ageRange5-6years" class="collapse">
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-auto">
                                     <a href="evaluation21.php?child_id=<?php echo $child['chi_id']; ?>&age_range=61-66" 
                                         class="age-button <?php echo in_array('61-66', $completed_ranges) ? 'completed' : ''; ?>">
                                61-66
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation22.php?child_id=<?php echo $child['chi_id']; ?>&age_range=67-72" 
                                         class="age-button <?php echo in_array('67-72', $completed_ranges) ? 'completed' : ''; ?>">
                                67-72
                            </a>
                        </div>
                        <div class="col-auto">
                                     <a href="evaluation23.php?child_id=<?php echo $child['chi_id']; ?>&age_range=73-78" 
                                         class="age-button <?php echo in_array('73-78', $completed_ranges) ? 'completed' : ''; ?>">
                                73-78
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        

        <!-- คำแนะนำ -->
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle"></i> คำแนะนำ:</h5>
            <ul class="mb-0">
                <li><span class="badge bg-success">สีเขียว</span> = แบบประเมินที่ทำไปแล้ว</li>
                <li><span class="badge bg-primary">สีน้ำเงิน</span> = ช่วงอายุที่สามารถประเมินได้ (ยังไม่ทำ)</li>
                <li>คลิกที่หัวข้อช่วงอายุเพื่อพับ/กางการแสดงผล</li>
                <li>คลิกที่ช่วงอายุเพื่อเข้าสู่แบบประเมิน</li>
            </ul>
        </div>

        <!-- ปุ่มจัดการ -->
        <div class="text-center mt-4">
            <a href="children_list.php" class="btn btn-secondary me-2">กลับรายชื่อเด็ก</a>
            <!--<a href="edit_child.php?id=<?php //echo $child['chi_id']; ?>" class="btn btn-warning me-2">แก้ไขข้อมูล</a> -->
            <a href="mainpage.php" class="btn btn-primary">กลับหน้าหลัก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
//session_start();
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
    $sql = "SELECT * FROM children WHERE chi_id = ? AND user_id = ?";
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

$sql = "SELECT e.*, DATE(e.eva_evaluation_date) as eval_date, u.user_fname, u.user_lname, u.user_role as evaluator_role
    FROM evaluations e
    LEFT JOIN users u ON e.user_id = u.user_id
    WHERE e.chi_id = ?
    ORDER BY e.eva_evaluation_date DESC, e.eva_version DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $child_id);
$stmt->execute();
$result = $stmt->get_result();
$evaluations = $result->fetch_all(MYSQLI_ASSOC);

// จัดกลุ่มตามช่วงอายุ (eva_age_range)
$grouped_evaluations = array();
foreach ($evaluations as $evaluation) {
    $age = $evaluation['eva_age_range'];
    if (!isset($grouped_evaluations[$age])) {
        $grouped_evaluations[$age] = array(
            'age_range' => $age,
            'versions' => array(),
            'latest' => 0
        );
    }
    $grouped_evaluations[$age]['versions'][] = $evaluation;
}

// สำหรับแต่ละกลุ่ม: เรียงเวอร์ชันภายในกลุ่มจากใหม่ไปเก่า และหาวันที่ล่าสุดของกลุ่ม
$grouped_list = array_values($grouped_evaluations);
foreach ($grouped_list as &$g) {
    usort($g['versions'], function($a, $b) {
        $tA = strtotime($a['eva_evaluation_date']);
        $tB = strtotime($b['eva_evaluation_date']);
        if ($tA === $tB) {
            return ($b['eva_version'] ?? 0) <=> ($a['eva_version'] ?? 0);
        }
        return $tB <=> $tA; // ใหม่ก่อน
    });
    $g['latest'] = isset($g['versions'][0]) ? strtotime($g['versions'][0]['eva_evaluation_date']) : 0;
}
unset($g);

// เรียงกลุ่มตามช่วงอายุเป็นหลัก (ใช้เลขเริ่มต้นของช่วง เช่น '10-12' -> 10)
// ถ้าอายุเริ่มต้นเท่ากัน ให้เรียงตามวันที่ล่าสุดภายในกลุ่ม (ใหม่ก่อน)
function _age_start_key($age) {
    if ($age === null || $age === '') return PHP_INT_MAX;
    // หาตัวเลขตัวแรกในสตริง
    if (preg_match('/^(\d{1,3})/', $age, $m)) return (int)$m[1];
    if (preg_match('/(\d{1,3})/', $age, $m2)) return (int)$m2[1];
    return PHP_INT_MAX;
}

usort($grouped_list, function($a, $b) {
    $ka = _age_start_key($a['age_range']);
    $kb = _age_start_key($b['age_range']);
    if ($ka === $kb) {
        return $b['latest'] <=> $a['latest'];
    }
    return $ka <=> $kb; // อายุเริ่มต้นน้อยก่อน
});

$stmt->close();
$conn->close();

// เตรียมสรุปโดยใช้เฉพาะเวอร์ชันล่าสุดของแต่ละช่วงอายุ
$latest_evaluations = array();
foreach ($grouped_list as $g) {
    if (!empty($g['versions'][0])) {
        $latest_evaluations[] = $g['versions'][0];
    }
}

$summary_count = count($evaluations); // นับทั้งหมด ไม่ใช่เฉพาะตัวล่าสุดต่อช่วงอายุ
$summary_passed = $summary_count ? array_sum(array_column($latest_evaluations, 'eva_total_score')) : 0;
$summary_questions = $summary_count ? array_sum(array_column($latest_evaluations, 'eva_total_questions')) : 0;
$summary_failed = $summary_questions - $summary_passed;
$summary_percentage = $summary_questions > 0 ? round(($summary_passed / $summary_questions) * 100, 1) : 0;
$summary_latest_ts = 0;
foreach ($latest_evaluations as $le) {
    $t = strtotime($le['eva_evaluation_date']);
    if ($t && $t > $summary_latest_ts) $summary_latest_ts = $t;
}

// คำนวณจำนวนข้อทั้งหมดในระบบ: ใช้ MAX(eva_total_questions) ต่อช่วงอายุจากตาราง evaluations
$system_total_questions = 0;
$mysqli_sys = new mysqli('localhost', 'zteypszw_dspm', 'DSPMDB123321', 'zteypszw_dspm_db');
if (!$mysqli_sys->connect_error) {
    $q = "SELECT eva_age_range, MAX(eva_total_questions) AS max_q FROM evaluations GROUP BY eva_age_range";
    if ($res = $mysqli_sys->query($q)) {
        while ($r = $res->fetch_assoc()) {
            $system_total_questions += (int)$r['max_q'];
        }
        $res->free();
    }
    $mysqli_sys->close();
}

// หากยังได้ 0 ให้ fallback เป็นการรวมจาก latest templates ที่มีในข้อมูลเด็กนี้
if ($system_total_questions === 0) {
    $system_total_questions = array_sum(array_column($latest_evaluations, 'eva_total_questions'));
}

// เปอร์เซ็นต์เทียบกับจำนวนข้อทั้งหมดในระบบ
$system_percentage = $system_total_questions > 0 ? round(($summary_passed / $system_total_questions) * 100, 1) : 0;

// แสดงเปอร์เซ็นต์หลักเป็นเปอร์เซ็นต์ตามระบบทั้งหมด
$summary_percentage = $system_percentage;
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
            <!-- สรุปผลรวม -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">สรุปผลการประเมินทั้งหมด</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-2">
                                    <h3 class="text-primary"><?php echo $summary_count; ?></h3>
                                    <p>ครั้งที่ประเมิน (ล่าสุดต่อช่วงอายุ)</p>
                                </div>
                                <div class="col-md-2">
                                    <h3 class="text-success"><?php echo $summary_passed; ?></h3>
                                    <p>ข้อที่ผ่านรวม (ล่าสุด)</p>
                                </div>
                                <div class="col-md-2">
                                    <h3 class="text-danger"><?php echo $summary_failed; ?></h3>
                                    <p>ข้อที่ไม่ผ่านรวม (ล่าสุด)</p>
                                </div>
                                <div class="col-md-2">
                                    <h3 class="text-info"><?php echo $summary_questions; ?></h3>
                                    <p>ข้อทั้งหมด (รวมแบบประเมินล่าสุด)</p>
                                </div>
                                <!--<div class="col-md-2">
                                    <h3 class="text-warning"><?php echo $summary_percentage; ?>%</h3>
                                    <p>เปอร์เซ็นต์รวม (ล่าสุด)</p>
                                </div>-->
                                <div class="col-md-2">
                                    <h3 class="text-muted"><?php echo $summary_latest_ts ? date('d/m/Y', $summary_latest_ts) : '-'; ?></h3>
                                    <p>ประเมินล่าสุด</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <hr>
            <!-- แสดงผลการประเมิน (เรียงจากใหม่ไปเก่า) -->
            <?php foreach ($grouped_list as $group): ?>
                <div class="card mb-4 shadow-sm" id="group-<?php echo md5($group['age_range']); ?>">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">ช่วงอายุ: <?php echo htmlspecialchars($group['age_range']); ?> เดือน</h5>
                            <span class="badge bg-light text-dark">
                                <?php echo !empty($group['latest']) ? date('d/m/Y', $group['latest']) : ''; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($group['versions']) > 1): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-history"></i> มีการประเมินซ้ำ <?php echo count($group['versions']); ?> ครั้งในวันนี้
                            </div>
                        <?php endif; ?>
                        
                        <?php
                        // จัดกลุ่มตามวันที่ (Y-m-d) ภายในช่วงอายุ
                        $by_date = [];
                        foreach ($group['versions'] as $v) {
                            $d = strtotime($v['eva_evaluation_date']);
                            $key = $d ? date('Y-m-d', $d) : 'unknown';
                            if (!isset($by_date[$key])) $by_date[$key] = [];
                            $by_date[$key][] = $v;
                        }
                        // เรียงวันที่จากใหม่ไปเก่า
                        uksort($by_date, function($a, $b) {
                            if ($a === 'unknown') return 1;
                            if ($b === 'unknown') return -1;
                            return strtotime($b) <=> strtotime($a);
                        });

                        // แสดงตามวันที่
                        foreach ($by_date as $date => $items):
                            $display_date = $date === 'unknown' ? 'ไม่ระบุวันที่' : date('d/m/Y', strtotime($date));
                        ?>
                            <div class="mb-3">
                                <h6 class="mb-2 text-secondary"><?php echo $display_date; ?></h6>
                                <?php foreach ($items as $idx => $evaluation): ?>
                                    <?php
                                    $total_questions = $evaluation['eva_total_questions'];
                                    $percentage = $total_questions > 0 ? ($evaluation['eva_total_score'] / $total_questions) * 100 : 0;
                                    $badge_class = '';
                                    if ($percentage >= 80) $badge_class = 'passed';
                                    elseif ($percentage >= 50) $badge_class = 'partial';
                                    else $badge_class = 'failed';
                                    $is_latest = isset($group['versions'][0]) && $evaluation['eva_id'] == $group['versions'][0]['eva_id'];
                                    $version_label = $is_latest ? 'ล่าสุด' : ('ครั้งที่ ' . ($evaluation['eva_version'] ?? '-'));
                                    ?>
                                    <div class="row mb-3 <?php echo $is_latest ? '' : 'border-top pt-3'; ?>">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge score-badge <?php echo $badge_class; ?> me-2">
                                                    <?php echo $evaluation['eva_total_score']; ?>/<?php echo $evaluation['eva_total_questions']; ?>
                                                </span>
                                                <span class="badge bg-secondary me-2"><?php echo $version_label; ?></span>
                                                <small class="text-muted">
                                                    <?php 
                                                    $eval_datetime = $evaluation['eva_evaluation_time'];
                                                    if (strtotime($eval_datetime)) {
                                                        echo date('H:i', strtotime($eval_datetime));
                                                    } else {
                                                        echo "ไม่ระบุเวลา";
                                                    }
                                                    ?> น.
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    ผู้ประเมิน: <?php echo htmlspecialchars(trim(($evaluation['user_fname'] ?? '') . ' ' . ($evaluation['user_lname'] ?? '')) ?: 'ไม่ระบุ'); ?>
                                                    <?php if (!empty($evaluation['evaluator_role'])): ?>
                                                        (<?php echo htmlspecialchars($evaluation['evaluator_role']); ?>)
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <span class="text-success">✓ ผ่าน: <?php echo $evaluation['eva_total_score']; ?> ข้อ</span> |
                                                <span class="text-danger">✗ ไม่ผ่าน: <?php echo ($evaluation['eva_total_questions'] - $evaluation['eva_total_score']); ?> ข้อ</span> |
                                                <span class="text-info"><?php echo round($percentage, 1); ?>%</span> |
                                                <span class="text-muted">รวม: <?php echo $evaluation['eva_total_questions']; ?> ข้อ</span>
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
                                                                    class="btn btn-primary btn-sm" onclick="saveScroll();">ดูรายละเอียด</a>
                                                <?php if ($is_latest): // แสดงปุ่มประเมินใหม่เฉพาะ version ล่าสุด ?>
                                                    <?php 
                                                    $evaluation_file = 'evaluation1.php'; // default
                                                    switch ($evaluation['eva_age_range']) {
                                                        case '1-2': $evaluation_file = 'evaluation2.php'; break;
                                                        case '3-4': $evaluation_file = 'evaluation3.php'; break;
                                                        case '5-6': $evaluation_file = 'evaluation4.php'; break;
                                                        case '7-8': $evaluation_file = 'evaluation5.php'; break;
                                                        case '9': $evaluation_file = 'evaluation6.php'; break;
                                                        case '10-12': $evaluation_file = 'evaluation7.php'; break;
                                                        case '13-15': $evaluation_file = 'evaluation8.php'; break;
                                                        case '16-17': $evaluation_file = 'evaluation9.php'; break;
                                                        case '18': $evaluation_file = 'evaluation10.php'; break;
                                                        case '19-24': $evaluation_file = 'evaluation11.php'; break;
                                                        case '25-29': $evaluation_file = 'evaluation12.php'; break;
                                                        case '30': $evaluation_file = 'evaluation13.php'; break;
                                                        case '31-36': $evaluation_file = 'evaluation14.php'; break;
                                                        case '37-41': $evaluation_file = 'evaluation15.php'; break;
                                                        case '42': $evaluation_file = 'evaluation16.php'; break;
                                                        case '43-48': $evaluation_file = 'evaluation17.php'; break;
                                                        case '49-54': $evaluation_file = 'evaluation18.php'; break;
                                                        case '55-59': $evaluation_file = 'evaluation19.php'; break;
                                                        case '60': $evaluation_file = 'evaluation20.php'; break;
                                                        case '61-66': $evaluation_file = 'evaluation21.php'; break;
                                                        case '67-72': $evaluation_file = 'evaluation22.php'; break;
                                                        case '73-78': $evaluation_file = 'evaluation23.php'; break;
                                                        default: $evaluation_file = 'evaluation1.php'; break;
                                                    }
                                                    ?>
                                                                     <a href="<?php echo $evaluation_file; ?>?child_id=<?php echo $child['chi_id']; ?>&age_range=<?php echo $evaluation['eva_age_range']; ?>" 
                                                                         class="btn btn-warning btn-sm" onclick="saveScroll();">ประเมินใหม่</a>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm" onclick="deleteEvaluation(<?php echo $evaluation['eva_id']; ?>)">ลบ</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            
        <?php endif; ?>

        <!-- ปุ่มจัดการ -->
        <div class="text-center mt-4">
            <a href="child_detail.php?id=<?php echo $child['chi_id']; ?>" class="btn btn-secondary me-2">กลับข้อมูลเด็ก</a>
            <a href="children_list.php" class="btn btn-primary me-2">รายชื่อเด็ก</a>
            <a href="report.php?child_id=<?php echo $child['chi_id']; ?>" class="btn btn-info me-2">รายงานผล</a>
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

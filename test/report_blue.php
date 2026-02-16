<?php
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin();
$user = getUserInfo();

$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
if ($child_id == 0) {
    header('Location: children_list.php');
    exit();
}

// ดึงข้อมูลเด็ก
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    $sql = "SELECT * FROM children WHERE chi_id = ?";
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
if (!$child) {
    $_SESSION['error'] = "ไม่พบข้อมูลเด็กที่ต้องการ";
    header('Location: children_list.php');
    exit();
}

// ฟังก์ชันดึงคำถามตามช่วงอายุ (เหมือนกันกับ report.php)
function getQuestionsByAgeRange($age_range) {
    // (reuse the same question lists as in report.php)
    // For brevity we will include the same arrays as the original report.php
    // Copy the question arrays from report.php
    // ข้อคำถามสำหรับ 9 เดือน (ข้อ 27-34)
    $questions_9 = [
        27 => ['skill' => 'ลุกขึ้นนั่งได้จากท่านอน (GM)'],
        28 => ['skill' => 'ยืนอยู่ได้โดยใช้มือเกาะเครื่องเรือนสูงระดับอก (GM)'],
        29 => ['skill' => 'หยิบก้อนไม้จากพื้นและถือไว้มือละชิ้น (FM)'],
        30 => ['skill' => 'ใช้นิ้วหัวแม่มือ และนิ้วอื่น ๆหยิบของขึ้นจากพื้น (FM)'],
        31 => ['skill' => 'ทำตามคำสั่งง่าย ๆ เมื่อใช้ท่าทางประกอบ (RL)'],
        32 => ['skill' => 'เด็กรู้จักการปฏิเสธด้วยการแสดงท่าทาง (EL)'],
        33 => ['skill' => 'เลียนเสียงคำพูดที่คุ้นเคยได้อย่างน้อย 1 เสียง (EL)'],
        34 => ['skill' => 'ใช้นิ้วหยิบอาหารกินได้ (PS)'],
    ];

    // ข้อคำถามสำหรับ 18 เดือน (ข้อ 50-59)
    $questions_18 = [
        50 => ['skill' => 'วิ่งได้ (GM)'],
        51 => ['skill' => 'เดินถือลูกบอลไปได้ไกล3 เมตร (GM)'],
        52 => ['skill' => 'เปิดหน้าหนังสือที่ทำด้วยกระดาษแข็งทีละแผ่นได้เอง(FM)'],
        53 => ['skill' => 'ต่อก้อนไม้ 2 ชั้น (FM)'],
        54 => ['skill' => 'เลือกวัตถุตามคำสั่งได้ถูกต้อง3 ชนิด (RL)'],
        55 => ['skill' => 'ชี้อวัยวะได้ 1 ส่วน (RL)'],
        56 => ['skill' => 'พูดเลียนคำที่เด่น หรือคำสุดท้ายของคำพูด (EL)'],
        57 => ['skill' => 'พูดเป็นคำๆ ได้ 4 คำ เรียกชื่อสิ่งของหรือทักทาย (ต้องเป็นคำอื่นที่ไม่ใช่คำว่าพ่อแม่ ชื่อของคนคุ้นเคย หรือชื่อของสัตว์เลี้ยงในบ้าน) (EL)'],
        58 => ['skill' => 'สนใจและมองตามสิ่งที่ผู้ใหญ่ชี้ที่อยู่ไกลออกไปประมาณ 3 เมตร (PS)'],
        59 => ['skill' => 'ดื่มน้ำจากถ้วยโดยไม่หก (PS)'],

    ];

    // ข้อคำถามสำหรับ 30 เดือน (ข้อ 70-78)
    $questions_30 = [
        70=>['skill'=>'กระโดดข้ามเชือกบนพื้นไปข้างหน้าได้ (GM)'],
        71=>['skill'=>'ขว้างลูกบอลขนาดเล็กได้ โดยยกมือขึ้นเหนือศีรษะ (GM)'],
        72=>['skill'=>'ต่อก้อนไม้สี่เหลี่ยมลูกบาศก์เป็นหอสูงได้ 8 ก้อน (FM)'],
        73=>['skill'=>'ยื่นวัตถุให้ผู้ทดสอบได้ 1 ชิ้นตามคำบอก (รู้จำนวนเท่ากับ 1)(RL)'],
        74=>['skill'=>'สนใจฟังนิทานได้นาน 5 นาที(RL)'],
        75=>['skill'=>'วางวัตถุไว้ “ข้างบน” และ“ข้างใต้” ตามคำสั่งได้ (RL)'],
        76=>['skill'=>'พูดติดต่อกัน 2 คำขึ้นไปอย่างมีความหมายโดยใช้คำกริยาได้ถูกต้องอย่างน้อย 4 กริยา (EL)'],
        77=>['skill'=>'ร้องเพลงได้บางคำหรือร้องเพลงคลอตามทำนอง (PS)'],
        78=>['skill'=>'เด็กรู้จักรอให้ถึงรอบของตนเองในการเล่นโดยมีผู้ใหญ่คอยบอก (PS)'],
    ];

    // ข้อคำถามสำหรับ 42 เดือน (ข้อ 90-100)
    $questions_42 = [
        90=>['skill'=>'ยืนขาเดียว 5 วินาที (GM)'],
        91=>['skill'=>'ใช้แขนรับลูกบอลได้ (GM)'],
        92=>['skill'=>'แยกรูปทรงเรขาคณิตได้ 3 แบบ (FM)'],
        93=>['skill'=>'ประกอบชิ้นส่วนของรูปภาพที่ถูกตัดออกเป็น 3 ชิ้นได้ (FM)'],
        94=>['skill'=>'เขียนรูปวงกลมตามแบบได้ (FM)'],
        95=>['skill'=>'วางวัตถุไว้ข้างหน้าและข้างหลังได้ตามคำสั่ง (RL)'],
        96=>['skill'=>'เลือกจัดกลุ่มวัตถุตามประเภทภาพเสื้อผ้าได้ (RL)'],
        97=>['skill'=>'พูดถึงเหตุการณ์ที่เพิ่งผ่านไปใหม่ ๆ ได้ (EL)'],
        98=>['skill'=>'พูด “ขอ” หรือ“ขอบคุณ”หรือ “ให้” ได้เอง (EL)'],
        99=>['skill'=>'บอกเพศของตนเองได้ถูกต้อง (PS)'],
        100=>['skill'=>'ใส่เสื้อผ่าหน้าได้เองโดยไม่ต้องติดกระดุม (PS)'],
    ];

    // ข้อคำถามสำหรับ 60 เดือน (ข้อ 117-124)
    $questions_60 = [
        117=>['skill'=>'เดินต่อเท้าเป็นเส้นตรงไปข้างหน้าได้ (GM)'],
        118=>['skill'=>'ลอกรูป  (FM)'],
        119=>['skill'=>'วาดรูปคนได้ 6 ส่วน (FM)'],
        120=>['skill'=>'จับใจความเมื่อฟังนิทานหรือเรื่องเล่า (RL)'],
        121=>['skill'=>'นับก้อนไม้ 5 ก้อน (รู้จำนวนเท่ากับ 5) (RL)'],
        122=>['skill'=>'อ่านออกเสียงพยัญชนะได้ถูกต้อง 5 ตัว ดังนี้ “ก” “ง” “ด” “น” “ย” (EL)'],
        123=>['skill'=>'รู้จักพูดอย่างมีเหตุผล(EL)'],
        124=>['skill'=>'แสดงความเห็นอกเห็นใจเมื่อเห็นเพื่อนเจ็บหรือไม่สบาย(PS)'],
    ];

    switch ($age_range) {
        case '9': return $questions_9;
        case '18': return $questions_18;
        case '30': return $questions_30;
        case '42': return $questions_42;
        case '60': return $questions_60;
        default: return $questions_9;
    }
}

// กำหนดลำดับช่วงอายุที่จะโชว์
$age_ranges = ['9','18','30','42','60'];
$age_labels = [
    '9' => '9 เดือน',
    '18' => '18 เดือน',
    '30' => '30 เดือน',
    '42' => '42 เดือน',
    '60' => '60 เดือน',
];

// ดึงผลการประเมินล่าสุดต่อช่วงอายุสำหรับเด็กคนนี้
$age_reports = [];
foreach ($age_ranges as $ar) {
    $latest_sql = "SELECT e.*, u.user_fname, u.user_lname FROM evaluations e LEFT JOIN users u ON e.user_id = u.user_id WHERE e.chi_id = ? AND e.eva_age_range = ? ORDER BY e.eva_evaluation_date DESC, e.eva_version DESC LIMIT 1";
    $latest_stmt = $conn->prepare($latest_sql);
    $latest_stmt->bind_param("is", $child_id, $ar);
    $latest_stmt->execute();
    $latest_res = $latest_stmt->get_result();
    $latest = $latest_res->fetch_assoc();
    $latest_stmt->close();

    $responses = [];
    if ($latest && !empty($latest['eva_responses'])) {
        $responses = json_decode($latest['eva_responses'], true) ?: [];
    }

    $questions = getQuestionsByAgeRange($ar);
    $age_reports[$ar] = [
        'questions' => $questions,
        'latest' => $latest,
        'responses' => $responses
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการคัดกรอง (สาธารณสุข) - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        table th, table td { vertical-align: middle; }
        .form-header th {
            background-color: #e7f3ff;
            color: #0b4770;
            text-align: center;
            vertical-align: middle;
            font-weight: 600;
        }
        .domain-header { background: linear-gradient(#2b9df7,#0d6efd); color: #fff; text-align:center; font-weight:700 }
        .age-left { background: linear-gradient(#0d6efd,#0a58ca); color:#fff; text-align:center; width:140px }
        .age-left .v { writing-mode: vertical-rl; transform: rotate(180deg); display:inline-block; padding:8px 0 }
        .q-item { margin-bottom: .6rem; }
        .q-item small { display:block; color:#666; }
        .q-checkbox { margin-right:6px; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-info">
        <div class="container">
            <a class="navbar-brand" href="mainpage.php">DSPM System</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">รายงาน: <?php echo htmlspecialchars($child['chi_child_name']); ?></span>
                <a class="btn btn-outline-light btn-sm" href="evaluation_history.php?child_id=<?php echo $child['chi_id']; ?>">กลับ</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="text-center mb-4">
            <h1 style="color: #0d6efd;">แบบบันทึกการคัดกรองและส่งเสริมพัฒนาการเด็กปฐมวัย ตามช่วงอายุ</h1>
            <h1 style="color: #0d6efd;">โดย เจ้าหน้าที่สาธารณสุข</h1>
            <h2><?php echo htmlspecialchars($child['chi_child_name']); ?></h2>
        </div>

        <!-- The rest of the layout matches report.php but uses blue styles -->
        <div class="card mb-3">
            <div class="card-body">
                <?php
                $total_evals = 0;
                $sum_passed = 0;
                $sum_questions = 0;
                foreach ($age_reports as $r) {
                    if (!empty($r['latest'])) {
                        $total_evals++;
                        $sum_passed += (int)$r['latest']['eva_total_score'];
                        $sum_questions += (int)$r['latest']['eva_total_questions'];
                    }
                }
                $sum_failed = $sum_questions - $sum_passed;
                $overall_percent = $sum_questions > 0 ? round(($sum_passed / $sum_questions) * 100, 1) : 0;
                ?>
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5><?php echo $total_evals; ?></h5>
                        <p>จำนวนช่วงอายุที่มีการประเมินล่าสุด</p>
                    </div>
                    <div class="col-md-3">
                        <h5><?php echo $sum_passed; ?></h5>
                        <p>จำนวนข้อผ่าน (รวมล่าสุด)</p>
                    </div>
                    <div class="col-md-3">
                        <h5><?php echo $sum_failed; ?></h5>
                        <p>จำนวนข้อไม่ผ่าน (รวมล่าสุด)</p>
                    </div>
                    <div class="col-md-3">
                        <h5><?php echo $overall_percent; ?>%</h5>
                        <p>เปอร์เซ็นต์รวม (เทียบกับข้อรวมล่าสุด)</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive desktop-table d-none d-md-block">
                    <!-- reuse same table structure as report.php -->
                    <?php // To keep file concise we reuse the same rendering logic as report.php ?>
                    <?php
                    $domains = ['GM','FM','RL','EL','PS'];
                    ?>
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th class="age-left"><div class="v">ด้าน / อายุ</div></th>
                                <th class="domain-header">ด้านการเคลื่อนไหว<br>Gross Motor (GM)</th>
                                <th class="domain-header">ด้านการใช้กล้ามเนื้อมัดเล็กและสติปัญญา<br>Fine Motor (FM)</th>
                                <th class="domain-header">ด้านการเข้าใจภาษา<br>Receptive Language (RL)</th>
                                <th class="domain-header">ด้านการใช้ภาษา<br>Expressive Language (EL)</th>
                                <th class="domain-header">ด้านการช่วยเหลือตัวเองและสังคม<br>Personal and Social (PS)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $any = false;
                        foreach ($age_reports as $age => $data):
                            $questions = $data['questions'];
                            if (empty($questions)) continue;
                            $any = true;
                            $by_domain = [ 'GM'=>[], 'FM'=>[], 'RL'=>[], 'EL'=>[], 'PS'=>[] ];
                            foreach ($questions as $qid => $q) {
                                $tag = null;
                                if (preg_match('/\((GM|FM|RL|EL|PS)\)/i', $q['skill'] ?? '', $m)) {
                                    $tag = strtoupper($m[1]);
                                }
                                if ($tag && isset($by_domain[$tag])) $by_domain[$tag][$qid] = $q;
                            }
                            $maxRows = 0; foreach ($by_domain as $d) $maxRows = max($maxRows, count($d));
                            if ($maxRows == 0) $maxRows = 1;
                            $resp = $data['responses'] ?? [];
                            $latest = $data['latest'];
                            for ($row=0; $row < $maxRows; $row++):
                        ?>
                        <tr>
                            <?php if ($row === 0): ?>
                                <td rowspan="<?php echo $maxRows; ?>"><strong><?php echo htmlspecialchars($age_labels[$age] ?? $age); ?></strong>
                                    <?php if (!empty($latest)): ?>
                                        <br><small class="text-muted">ล่าสุด: <?php echo date('d/m/Y', strtotime($latest['eva_evaluation_date'])); ?></small>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php foreach ($domains as $dom):
                                $items = array_values($by_domain[$dom]);
                                $qid = isset(array_keys($by_domain[$dom])[$row]) ? array_keys($by_domain[$dom])[$row] : null;
                                $item = $qid ? $by_domain[$dom][$qid] : null;
                            ?>
                                <td style="vertical-align:top">
                                    <?php if ($item):
                                        $key = 'question_' . $qid;
                                        $passed = isset($resp[$key]['passed']) && $resp[$key]['passed'];
                                        $failed = isset($resp[$key]['failed']) && $resp[$key]['failed'];
                                    ?>
                                        <div class="q-item">
                                            <strong><?php echo $qid; ?>.</strong> <?php echo htmlspecialchars(preg_replace('/\s*\((?:GM|FM|RL|EL|PS)\)\s*/i','',$item['skill'])); ?>
                                            <small>
                                                <label class="me-2"><input class="q-checkbox" type="checkbox" disabled <?php echo $passed ? 'checked' : ''; ?>> ผ่าน</label>
                                                <label><input class="q-checkbox" type="checkbox" disabled <?php echo $failed ? 'checked' : ''; ?>> ไม่ผ่าน</label>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        &nbsp;
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endfor; endforeach; if (!$any): ?>
                            <tr><td colspan="8" class="text-center">ยังไม่มีผลการประเมิน</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile: stacked card view -->
                <div class="mobile-cards d-block d-md-none">
                    <?php foreach ($age_reports as $age => $data):
                        $questions = $data['questions'];
                        if (empty($questions)) continue;
                        $by_domain = [ 'GM'=>[], 'FM'=>[], 'RL'=>[], 'EL'=>[], 'PS'=>[] ];
                        foreach ($questions as $qid => $q) {
                            if (preg_match('/\((GM|FM|RL|EL|PS)\)/i', $q['skill'] ?? '', $m)) {
                                $tag = strtoupper($m[1]);
                                if (isset($by_domain[$tag])) $by_domain[$tag][$qid] = $q;
                            }
                        }
                        $resp = $data['responses'] ?? [];
                        $latest = $data['latest'];
                    ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($age_labels[$age] ?? $age); ?>
                                <?php if (!empty($latest)): ?>
                                    <small class="text-muted"> - <?php echo date('d/m/Y', strtotime($latest['eva_evaluation_date'])); ?></small>
                                <?php endif; ?>
                            </h5>
                            <?php foreach (['GM','FM','RL','EL','PS'] as $dom): ?>
                                <div class="mb-2">
                                    <strong><?php echo $dom; ?></strong>
                                    <?php if (!empty($by_domain[$dom])): ?>
                                        <ul class="list-unstyled mt-1 mb-0">
                                        <?php foreach ($by_domain[$dom] as $qid => $item):
                                            $key = 'question_' . $qid;
                                            $passed = isset($resp[$key]['passed']) && $resp[$key]['passed'];
                                            $failed = isset($resp[$key]['failed']) && $resp[$key]['failed'];
                                        ?>
                                            <li class="small">
                                                <strong><?php echo $qid; ?>.</strong> <?php echo htmlspecialchars(preg_replace('/\s*\((?:GM|FM|RL|EL|PS)\)\s*/i','',$item['skill'])); ?>
                                                <div><label class="me-2"><input type="checkbox" disabled <?php echo $passed ? 'checked' : ''; ?>> ผ่าน</label>
                                                <label><input type="checkbox" disabled <?php echo $failed ? 'checked' : ''; ?>> ไม่ผ่าน</label></div>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <div class="small text-muted">-</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="text-center mt-3">
            <a href="evaluation_history.php?child_id=<?php echo $child['chi_id']; ?>" class="btn btn-secondary me-2">กลับ</a>
            <a href="children_list.php" class="btn btn-primary">รายชื่อเด็ก</a>
            <a href="report.php?child_id=<?php echo $child['chi_id']; ?>" class="btn btn-primary">รายงานผล</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

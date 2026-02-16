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

// ฟังก์ชันดึงคำถามตามช่วงอายุ (คัดลอกจาก view_evaluation_detail.php)
function getQuestionsByAgeRange($age_range) {
    // ข้อคำถามสำหรับ 0-1 เดือน
    $questions_0_1 = [
        1 => ['skill' => 'ท่านอนคว่ำยกศีรษะและหันไปข้างใดข้างหนึ่งได้ (GM)'],
        2 => ['skill' => 'มองตามถึงกึ่งกลางลำตัว (FM)'],
        3 => ['skill' => 'สะดุ้งหรือเคลื่อนไหวร่างกายเมื่อได้ยินเสียงพูดระดับปกติ(RL)'],
        4 => ['skill' => 'ส่งเสียงอ้อแอ้ (EL)'],
        5 => ['skill' => 'มองจ้องหน้าได้นาน 1 - 2 วินาที(PS)']
    ];

    // คำถามสำหรับ 1-2 เดือน (ข้อ 6-10)
    $questions_1_2 = [
        6 => ['skill' => 'ท่านอนคว่ำ ยกศีรษะตั้งขึ้นได้ 45 องศา นาน 3 วินาที (GM)'],
        7 => ['skill' => 'มองตามกึ่งกลางลำตัว (FM)'],
        8 => ['skill' => 'มองหน้าผู้พูดคุย ได้นาน 5 วินาที (RL)'],
        9 => ['skill' => 'ทำเสียงในลำคอ (เสียง “อู” หรือ “อา” หรือ “อือ”) อย่างชัดเจน (EL)'],
        10 => ['skill' => 'ยิ้มตอบหรือส่งเสียงตอบได้เมื่อพ่อแม่ ผู้ปกครองหรือผู้ประเมินยิ้มและพูดคุยด้วย(PS)']
    ];

    // คำถามสำหรับ 3-4 เดือน (ข้อ 11-15)
    $questions_3_4 = [
        11=>['skill'=>'ท่านอนคว่ำยกศีรษะและอกพ้นพื้น (GM)'],
        12=>['skill'=>'มองตามได้ 180 องศา (FM)'],
        13=>['skill'=>'หันตามเสียงได้ (RL)'],
        14=>['skill'=>'ทำเสียงสูง ๆ ต่ำ ๆ เพื่อแสดงความรู้สึก (EL)'],
        15=>['skill'=>'ยิ้มทักคนที่คุ้นเคย (PS)'],
    ];

    // คำถามสำหรับ 5-6 เดือน (ข้อ 16-20)
    $questions_5_6 = [
        16=>['skill'=>'ยันตัวขึ้นจากท่านอนคว่ำโดยเหยียดแขนตรงทั้งสองข้างได้(GM)'],
        17=>['skill'=>'เอื้อมมือหยิบ และถือวัตถุไว้ขณะอยู่ในท่านอนหงาย (FM)'],
        18=>['skill'=>'หันตามเสียงเรียก (RL)'],
        19=>['skill'=>'เลียนแบบการเล่นทำเสียงได้(EL)'],
        20=>['skill'=>'สนใจฟังคนพูดและสามารถมองไปที่ของเล่นที่ผู้ทดสอบเล่นกับเด็ก (PS)'],
    ];

    // คำถามสำหรับ 7-8 เดือน (ข้อ 21-26)
    $questions_7_8 = [
        21=>['skill'=>'นั่งได้มั่นคง และเอี้ยวตัวใช้มือเล่นได้อย่างอิสระ (GM)'],
        22=>['skill'=>'ยืนเกาะ เครื่องเรือนสูงระดับอกได้ (GM)'],
        23=>['skill'=>'จ้องมองไปที่หนังสือพร้อมกับผู้ใหญ่นาน 2 - 3 วินาที (FM)'],
        24=>['skill'=>'เด็กหันตามเสียงเรียกชื่อ (RL)'],
        25=>['skill'=>'เลียนเสียงพูดคุย (EL)'],
        26=>['skill'=>'เด็กเล่นจ๊ะเอ๋ได้และมองหาหน้าของผู้เล่นได้ถูกทิศทาง (PS)'],
    ];

    // คำถามสำหรับ 10-12 เดือน (ข้อ 35-39)
    $questions_10_12 = [
        35=>['skill'=>'ยืนนาน 2 วินาที (GM)'],
        36=>['skill'=>'จีบนิ้วมือเพื่อหยิบของชิ้นเล็ก (FM)'],
        37=>['skill'=>'โบกมือหรือตบมือตามคำสั่ง (RL)'],
        38=>['skill'=>'แสดงความต้องการ โดยทำท่าทาง หรือเปล่งเสียง (EL)'],
        39=>['skill'=>'เล่นสิ่งของตามประโยชน์ของสิ่งของได้ (PS)'],
    ];

    // คำถามสำหรับ 13-15 เดือน (ข้อ 40-44)
    $questions_13_15 = [
        40=>['skill'=>'ยืนอยู่ตามลำพังได้นานอย่างน้อย10 วินาที (GM)'],
        41=>['skill'=>'ขีดเขียน (เป็นเส้น) บนกระดาษได้ (FM)'],
        42=>['skill'=>'เลือกวัตถุตามคำสั่งได้ถูกต้อง2 ชนิด (RL)'],
        43=>['skill'=>'พูดคำพยางค์เดียว (คำโดด)ได้ 2 คำ (EL)'],
        44=>['skill'=>'เลียนแบบท่าทางการทำงานบ้าน (PS)'],
    ];

    // คำถามสำหรับ 16-17 เดือน (ข้อ 45-49)
    $questions_16_17 = [
        45=>['skill'=>'เดินลากของเล่น หรือสิ่งของได้ (GM)'],
        46=>['skill'=>'ขีดเขียนได้เอง (FM)'],
        47=>['skill'=>'ทำตามคำสั่งง่าย ๆ โดยไม่มีท่าทางประกอบ (RL)'],
        48=>['skill'=>'ตอบชื่อวัตถุได้ถูกต้อง (EL)'],
        49=>['skill'=>'เล่นการใช้สิ่งของตามหน้าที่ได้มากขึ้นด้วยความสัมพันธ์ของ 2 สิ่งขึ้นไป (PS)'],
    ];

    // คำถามสำหรับ 19-24 เดือน (ข้อ 60-64)
    $questions_19_24 = [
        60=>['skill'=>'เหวี่ยงขาเตะลูกบอลได้ (GM)'],
        61=>['skill'=>'ต่อก้อนไม้ 4 ชั้น (FM)'],
        62=>['skill'=>'เลือกวัตถุตามคำสั่ง (ตัวเลือก4 ชนิด) (RL)'],
        63=>['skill'=>'เลียนคำพูดที่เป็นวลีประกอบด้วยคำ 2 คำขึ้นไป (EL)'],
        64=>['skill'=>'ใช้ช้อนตักอาหารกินเองได้ (PS)'],
    ];

    // คำถามสำหรับ 25-29 เดือน (ข้อ 65-69)
    $questions_25_29 = [
        65=>['skill'=>'กระโดดเท้าพ้นพื้นทั้ง 2 ข้าง (GM)'],
        66=>['skill'=>'แก้ปัญหาง่าย ๆ โดยใช้เครื่องมือด้วยตัวเอง (FM)'],
        67=>['skill'=>'ชี้อวัยวะ 7 ส่วน (RL)'],
        68=>['skill'=>'พูดตอบรับและปฏิเสธได้ (EL)'],
        69=>['skill'=>'ล้างและเช็ดมือได้เอง (PS)'],
    ];

    // คำถามสำหรับ 31-36 เดือน (ข้อ 79-83)
    $questions_31_36 = [
        79=>['skill'=>'ยืนขาเดียว 1 วินาที (GM)'],
        80=>['skill'=>'เลียนแบบลากเส้นเป็นวงต่อเนื่องกัน (FM)'],
        81=>['skill'=>'นำวัตถุ 2 ชนิด ในห้องมาให้ได้ตามคำสั่ง (RL)'],
        82=>['skill'=>'พูดติดต่อกัน 3 - 4 คำได้อย่างน้อย 4 ความหมาย (EL)'],
        83=>['skill'=>'ใส่กางเกงได้เอง (PS)'],
    ];

    // คำถามสำหรับ 37-41 เดือน (ข้อ 84-89)
    $questions_37_41 = [
        84=>['skill'=>'ยืนขาเดียว 3 วินาที (GM)'],
        85=>['skill'=>'เลียนแบบวาดรูปวงกลม (FM)'],
        86=>['skill'=>'ทำตามคำสั่งต่อเนื่องได้ 2 กริยากับวัตถุ 2 ชนิด (RL)'],
        87=>['skill'=>'ถามคำถามได้ 4 แบบ เช่น ใคร อะไร ที่ไหน ทำไม (EL)'],
        88=>['skill'=>'ทำตามกฎ กติกา ในการเล่นเป็นกลุ่มได้โดยมีผู้ใหญ่แนะนำ(PS)'],
        89=>['skill'=>'ช่วยทำงานขั้นตอนเดียวได้เอง (PS)']
    ];

    // คำถามสำหรับ 43-48 เดือน (ข้อ 101-106)
    $questions_43_48 = [
        101=>['skill'=>'กระโดดขาเดียวได้ อย่างน้อย2 ครั้ง (GM)'],
        102=>['skill'=>'ตัดกระดาษรูปสี่เหลี่ยมจัตุรัสขนาด 10 ซม. ออกเป็น 2 ชิ้น(โดยใช้กรรไกรปลายมน) (FM)'],
        103=>['skill'=>'เลียนแบบวาดรูป + (เครื่องหมายบวก) (FM)'],
        104=>['skill'=>'เลือกวัตถุที่มีขนาดใหญ่กว่าและเล็กกว่า (RL)'],
        105=>['skill'=>'พูดเป็นประโยค ติดต่อกันโดยมีความหมาย และเหมาะสมกับโอกาสได้ (EL)'],
        106=>['skill'=>'ใส่กระดุมขนาดใหญ่อย่างน้อย 2 ซม. ได้เอง 3 เม็ด (PS)'],
    ];

    // คำถามสำหรับ 49-54 เดือน (ข้อ 107-111)
    $questions_49_54 = [
        107=>['skill'=>'กระโดดสองเท้าพร้อมกันไปด้านข้างและถอยหลังได้ (GM)'],
        108=>['skill'=>'ประกอบชิ้นส่วนของรูปภาพที่ตัดออกเป็นส่วน ๆ 8 ชิ้นได้ (FM)'],
        109=>['skill'=>'เลือกรูปภาพที่แสดงเวลากลางวัน กลางคืน (RL)'],
        110=>['skill'=>'ตอบคำถามได้ถูกต้องเมื่อถามว่า “ถ้ารู้สึกร้อน” “ไม่สบาย” “หิว” จะทำอย่างไร (EL)'],
        111=>['skill'=>'ทำความสะอาดตนเองหลังจากอุจจาระได้ (PS)'],
    ];

    // คำถามสำหรับ 55-59 เดือน (ข้อ 112-116)
    $questions_55_59 = [
        112=>['skill'=>'เดินต่อส้นเท้า (GM)'],
        113=>['skill'=>'จับดินสอได้ถูกต้อง (FM)'],
        114=>['skill'=>'เลือกสีได้ 8 สี ตามคำสั่ง (RL)'],
        115=>['skill'=>'ผลัดกันพูดคุยกับเพื่อนในกลุ่ม (EL)'],
        116=>['skill'=>'เล่นเลียนแบบบทบาทของผู้ใหญ่ได้ (PS)'],
    ];

    // คำถามสำหรับ 61-66 เดือน (ข้อ 125-129)
    $questions_61_66 = [
        125=>['skill'=>'กระโดดขาเดียวไปข้างหน้า 4 ครั้ง ทีละข้าง (GM)'],
        126=>['skill'=>'ตัดกระดาษตามเส้นตรงต่อเนื่อง ยาว 15 ซม. (FM)'],
        127=>['skill'=>'บวกเลขเบื้องต้น ผลลัพธ์ไม่เกิน 10 (RL)'],
        128=>['skill'=>'เด็กสามารถอธิบายหน้าที่หรือคุณสมบัติของสิ่งของได้อย่างน้อย 6 ชนิด (EL)'],
        129=>['skill'=>'ช่วยงานบ้าน (PS)'],
    ];

    // คำถามสำหรับ 67-72 เดือน (ข้อ 130-134)
    $questions_67_72 = [
        130=>['skill'=>'วิ่งหลบหลีกสิ่งกีดขวางได้ (GM)'],
        131=>['skill'=>'ลอกรูปสามเหลี่ยม (FM)'],
        132=>['skill'=>'ลบเลข (RL)'],
        133=>['skill'=>'เด็กสามารถบอกชื่อสิ่งของได้ 3 หมวด ได้แก่ สัตว์, เสื้อผ้า, อาหาร (EL)'],
        134=>['skill'=>'เด็กแปรงฟันได้ทั่วทั้งปาก (PS)'],
    ];

    // คำถามสำหรับ 73-78 เดือน (ข้อ 135-139)
    $questions_73_78 = [
        135=>['skill'=>'เคลื่อนไหวร่างกายตามที่ตกลงกันให้คู่กับสัญญาณเสียงที่ผู้ใหญ่ทำขึ้น 2 ชนิดต่อกัน (GM)'],
        136=>['skill'=>'เขียนชื่อตนเองได้ถูกต้อง (FM)'],
        137=>['skill'=>'อ่านหนังสือที่มีภาพอย่างต่อเนื่องจนจบ และเล่าได้ว่าเป็นเรื่องอะไร (RL)'],
        138=>['skill'=>'สามารถคิดเชิงเหตุผล และอธิบายได้ (EL)'],
        139=>['skill'=>'ทำงานที่ได้รับมอบหมายจนสำเร็จด้วยตนเอง (PS)'],
    ];
    

    switch ($age_range) {
        case '0-1': return $questions_0_1;
        case '1-2': return $questions_1_2;
        case '3-4': return $questions_3_4;
        case '5-6': return $questions_5_6;
        case '7-8': return $questions_7_8;
        case '10-12': return $questions_10_12;
        case '13-15': return $questions_13_15;
        case '16-17': return $questions_16_17;
        case '19-24': return $questions_19_24;
        case '25-29': return $questions_25_29;
        case '31-36': return $questions_31_36;
        case '37-41': return $questions_37_41;
        case '43-48': return $questions_43_48;
        case '49-54': return $questions_49_54;
        case '55-59': return $questions_55_59;
        case '61-66': return $questions_61_66;
        case '67-72': return $questions_67_72;
        case '73-78': return $questions_73_78;
        default: return $questions_0_1;
    }
}

// กำหนดลำดับช่วงอายุที่จะโชว์ (เรียงตามรูปตัวอย่าง)
$age_ranges = ['0-1','1-2','3-4','5-6','7-8','10-12','13-15','16-17',
'19-24','25-29','31-36','37-41','43-48','49-54','55-59','61-66','67-72','73-78'];
$age_labels = [
    '0-1' => 'แรกเกิด',
    '1-2' => '1 - 2 เดือน',
    '3-4' => '3 - 4 เดือน',
    '5-6' => '5 - 6 เดือน',
    '7-8' => '7 - 8 เดือน',
    '10-12' => '10 - 12 เดือน',
    '13-15' => '13 - 15 เดือน',
    '16-17' => '16 - 17 เดือน',
    '19-24' => '19 - 24 เดือน',
    '25-29' => '25 - 29 เดือน',
    '31-36' => '31 - 36 เดือน',
    '37-41' => '37 - 41 เดือน',
    '43-48' => '43 - 48 เดือน',
    '49-54' => '49 - 54 เดือน',
    '55-59' => '55 - 59 เดือน',
    '61-66' => '61 - 66 เดือน',
    '67-72' => '67 - 72 เดือน',
    '73-78' => '73 - 78 เดือน',
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

// ปิดการเชื่อมต่อเมื่อเตรียมข้อมูลเสร็จ
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการประเมิน - <?php echo htmlspecialchars($child['chi_child_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/test.css" />
    <style>
        table th, table td { vertical-align: middle; }
        .form-header th {
            background-color: #eaf6ee;
            color: #0b472f;
            text-align: center;
            vertical-align: middle;
            font-weight: 600;
        }
        .domain-header { background: linear-gradient(#3b8b3b,#2e7d32); color: #fff; text-align:center; font-weight:700 }
        .age-left { background: linear-gradient(#2e7d32,#1b5e20); color:#fff; text-align:center; width:140px }
        .age-left .v { writing-mode: vertical-rl; transform: rotate(180deg); display:inline-block; padding:8px 0 }
        .q-item { margin-bottom: .6rem; }
        .q-item small { display:block; color:#666; }
        .q-checkbox { margin-right:6px; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="mainpage.php">DSPM System</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">รายงาน: <?php echo htmlspecialchars($child['chi_child_name']); ?></span>
                <a class="btn btn-outline-light btn-sm" href="evaluation_history.php?child_id=<?php echo $child['chi_id']; ?>">กลับ</a>
                <a class="btn btn-outline-light btn-sm ms-2" href="report_blue.php?child_id=<?php echo $child['chi_id']; ?>">รายงาน โดย เจ้าหน้าที่สาธารณสุข</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="text-center mb-4">
            <h1 style="color: #149ee9;">แบบบันทึกการเฝ้าระวังและส่งเสริมพัฒนาการเด็กปฐมวัย ตามช่วงอายุ โดยพ่อแม่ ผู้ปกครอง ครู ผู้ดูแลเด็ก และเจ้าหน้าที่สาธารณสุข</h1>
            <h2><?php echo htmlspecialchars($child['chi_child_name']); ?></h2>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <?php
                // คำนวณสรุปจากการประเมินล่าสุดต่อช่วงอายุ
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
                            $domains = ['GM','FM','RL','EL','PS'];
                            foreach ($age_reports as $age => $data):
                                $questions = $data['questions'];
                                if (empty($questions)) continue;
                                $any = true;
                                // group questions by domain
                                $by_domain = [ 'GM'=>[], 'FM'=>[], 'RL'=>[], 'EL'=>[], 'PS'=>[] ];
                                foreach ($questions as $qid => $q) {
                                    $tag = null;
                                    if (preg_match('/\((GM|FM|RL|EL|PS)\)/i', $q['skill'] ?? '', $m)) {
                                        $tag = strtoupper($m[1]);
                                    }
                                    if ($tag && isset($by_domain[$tag])) $by_domain[$tag][$qid] = $q;
                                }
                                // find max rows needed for this age (max items in a domain)
                                $maxRows = 0; foreach ($by_domain as $d) $maxRows = max($maxRows, count($d));
                                if ($maxRows == 0) $maxRows = 1;
                                // prepare responses map
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
            <a href="report_blue.php?child_id=<?php echo $child['chi_id']; ?>" class="btn btn-primary">รายงาน โดย เจ้าหน้าที่สาธารณสุข</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

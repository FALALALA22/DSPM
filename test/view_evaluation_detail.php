<?php
//session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

$evaluation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($evaluation_id == 0) {
    $_SESSION['error'] = "ไม่พบข้อมูลการประเมิน";
    header("Location: children_list.php");
    exit();
}

// ดึงข้อมูลการประเมิน - ตรวจสอบสิทธิ์ตาม role
if ($user['user_role'] === 'admin' || $user['user_role'] === 'staff') {
    // Admin และ Staff ดูได้ทุกการประเมิน
    $sql = "SELECT e.*, c.chi_child_name as child_name, c.chi_photo as photo 
            FROM evaluations e 
            JOIN children c ON e.eva_child_id = c.chi_id 
            WHERE e.eva_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $evaluation_id);
} else {
    // User ปกติดูได้เฉพาะของตัวเอง
    $sql = "SELECT e.*, c.chi_child_name as child_name, c.chi_photo as photo 
            FROM evaluations e 
            JOIN children c ON e.eva_child_id = c.chi_id 
            WHERE e.eva_id = ? AND e.eva_user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $evaluation_id, $user['user_id']);
}
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "ไม่พบข้อมูลการประเมินที่ต้องการดู";
    header("Location: children_list.php");
    exit();
}

$evaluation = $result->fetch_assoc();
$responses = json_decode($evaluation['eva_responses'], true);

$stmt->close();
$conn->close();

// ข้อคำถามประเมินพัฒนาการ 0-1 เดือน (ตรงกับ evaluation1.php)
$questions = [
    1 => [
        'skill' => 'ท่านอนคว่ำยกศีรษะและหันไปข้างใดข้างหนึ่งได้ (GM)',
        'method' => 'จัดให้เด็กอยู่ในท่านอนคว่ำบนเบาะนอน แขนทั้งสองข้างอยู่หน้าไหล่',
        'pass_criteria' => 'เด็กสามารถยกศีรษะและหันไปข้างใดข้างหนึ่งได้',
        'training' => 'จัดให้เด็กอยู่ในท่านอนคว่ำ เขย่าของเล่นที่มีเสียงตรงหน้าเด็ก ระยะห่างประมาณ 30 ซม. เมื่อเด็กมองที่ของเล่นแล้วค่อย ๆ เคลื่อนของเล่นมาทางด้านซ้ายหรือขวา เพื่อให้เด็กหันศีรษะมองตาม ถ้าเด็กทำไม่ได้ให้ประคองศีรษะเด็กให้หันตาม'
    ],
    2 => [
        'skill' => 'มองตามถึงกึ่งกลางลำตัว (FM)',
        'method' => 'จัดให้เด็กอยู่ในท่านอนหงาย ถือลูกบอลผ้าสีแดงห่างจากหน้าเด็กประมาณ 20 ซม. ขยับลูกบอลผ้าสีแดงเพื่อกระตุ้นให้เด็กสนใจ แล้วเคลื่อนลูกบอลผ้าสีแดงช้า ๆ ไปทางด้านข้างลำตัวเด็กข้างใดข้างหนึ่งเคลื่อนลูกบอลผ้าสีแดงกลับมาที่จุดกึ่งกลางลำตัวเด็ก',
        'pass_criteria' => 'เด็กมองตามลูกบอลผ้าสีแดง จากด้านข้างถึงระยะกึ่งกลางลำตัวได้',
        'training' => '1. จัดให้เด็กอยู่ในท่านอนหงาย ก้มหน้าให้อยู่ใกล้ ๆ เด็ก ห่างจากหน้าเด็กประมาณ 20 ซม. 2. เรียกให้เด็กสนใจ โดยเรียกชื่อเด็ก เมื่อเด็กสนใจมองให้เคลื่อนหรือเอียงหน้าไปทางด้านข้างลำตัวเด็กอย่างช้าๆ เพื่อให้เด็กมองตาม 3. ถ้าเด็กไม่มองให้ช่วยเหลือโดยการประคองหน้าเด็กให้มองตาม 4. ฝึกเพิ่มเติมโดยใช้ของเล่นที่มีสีสันสดใสกระตุ้นให้เด็กสนใจและมองตาม'
    ],
    3 => [
        'skill' => 'สะดุ้งหรือเคลื่อนไหวร่างกายเมื่อได้ยินเสียงพูดระดับปกติ(RL)',
        'method' => '1. จัดให้เด็กอยู่ในท่านอนหงาย 2. อยู่ห่างจากเด็กประมาณ 60 ซม. เรียกชื่อเด็กจากด้านข้างทีละข้างทั้งซ้ายและขวา โดยพูดเสียงดังปกติ',
        'pass_criteria' => 'เด็กแสดงการรับรู้ด้วยการกะพริบตา สะดุ้ง หรือเคลื่อนไหวร่างกาย',
        'training' => '1. จัดให้เด็กอยู่ในท่านอนหงาย เรียกชื่อหรือพูดคุยกับเด็กจากด้านข้างทีละข้างทั้งข้างซ้ายและขวาโดยพูดเสียงดังกว่าปกติ 2. หากเด็กสะดุ้งหรือขยับตัวให้ยิ้มและสัมผัสตัวเด็ก ลดเสียงพูดคุยลงเรื่อย ๆ จนให้อยู่ในระดับปกติ'
    ],
    4 => [
        'skill' => 'ส่งเสียงอ้อแอ้ (EL)',
        'method' => 'สังเกตว่าเด็กส่งเสียงอ้อแอ้ในระหว่างทำการประเมิน หรือถามจากพ่อแม่ผู้ปกครอง',
        'pass_criteria' => 'เด็กทำเสียงอ้อแอ้ได้',
        'training' => 'อุ้มหรือสัมผัสตัวเด็กเบา ๆ มองสบตา แล้วพูดคุยกับเด็กด้วยเสียงสูง ๆ ต่ำ ๆ เพื่อให้เด็กสนใจและหยุดรอให้เด็กส่งเสียงอ้อแอ้ตอบ'
    ],
    5 => [
        'skill' => 'มองจ้องหน้าได้นาน 1 - 2 วินาที(PS)',
        'method' => 'อุ้มเด็กให้ห่างจากหน้าพ่อแม่ ผู้ปกครองหรือผู้ประเมินประมาณ 30 ซม. ยิ้มและพูดคุยกับเด็ก',
        'pass_criteria' => 'เด็กสามารถมองจ้องหน้าได้อย่างน้อย 1 วินาที',
        'training' => '1. จัดให้เด็กอยู่ในท่านอนหงายหรืออุ้มเด็ก 2. สบตา พูดคุย ส่งเสียง ยิ้ม หรือทำตาลักษณะต่าง ๆ เช่น ตาโตกะพริบตา เพื่อให้เด็กสนใจมอง เป็นการเสริมสร้างความสัมพันธ์ระหว่างเด็กกับผู้ดูแล โดยทำให้เด็กมีอารมณ์ดี'
    ]
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดการประเมิน - DSPM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            padding-top: 20px;
            padding-bottom: 50px;
        }
        .card {
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border-radius: 20px;
            border: none;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0 !important;
        }
        .btn-purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            color: white;
            padding: 10px 25px;
            transition: all 0.3s ease;
        }
        .btn-purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .child-photo {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid white;
        }
        .category-card {
            border-left: 5px solid #667eea;
            margin-bottom: 20px;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 8px 15px;
        }
        .checked-item {
            color: #28a745;
        }
        .unchecked-item {
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="mb-0">
                            <i class="fas fa-chart-line text-primary me-2"></i>
                            รายละเอียดการประเมินพัฒนาการ
                        </h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Child Info & Evaluation Details -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-user me-2"></i>
                            ข้อมูลการประเมิน
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-3 text-center mb-3 mb-md-0">
                                <?php if ($evaluation['photo']): ?>
                                    <img src="../uploads/children/<?= htmlspecialchars($evaluation['photo']) ?>" 
                                         alt="รูปเด็ก" class="child-photo">
                                <?php else: ?>
                                    <div class="child-photo bg-light d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user fa-2x text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-9">
                                <h4 class="text-primary mb-2"><?= htmlspecialchars($evaluation['child_name']) ?></h4>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <strong>ช่วงอายุ:</strong> <?= htmlspecialchars($evaluation['eva_age_range']) ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>เวอร์ชัน:</strong> <?= $evaluation['eva_version'] ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>วันที่ประเมิน:</strong> 
                                        <?= date('d/m/Y H:i', strtotime($evaluation['eva_evaluation_time'])) ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>ผลการประเมิน:</strong>
                                        <span class="badge bg-success"><?= $evaluation['eva_total_score'] ?> ผ่าน</span>
                                        <span class="badge bg-danger"><?= ($evaluation['eva_total_questions'] - $evaluation['eva_total_score']) ?> ไม่ผ่าน</span>
                                    </div>
                                        <strong>คะแนนรวม:</strong> 
                                        <span class="badge bg-primary status-badge">
                                            <?= $evaluation['eva_total_score'] ?>/<?= $evaluation['eva_total_questions'] ?>
                                        </span>
                                    </div>
                                    <?php if ($evaluation['eva_notes']): ?>
                                        <div class="col-12 mt-2">
                                            <strong>หมายเหตุ:</strong>
                                            <p class="mt-1 p-2 bg-light rounded">
                                                <?= nl2br(htmlspecialchars($evaluation['notes'])) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Evaluation Details -->
        <div class="row">
            <?php foreach ($questions as $question_id => $data): ?>
                <div class="col-md-6 mb-4">
                    <div class="card question-card">
                        <div class="card-header">
                            <h6 class="mb-0">ข้อ <?= $question_id ?>: <?= htmlspecialchars($data['skill']) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>ทักษะที่ประเมิน:</strong>
                                    <p class="small text-muted"><?= htmlspecialchars($data['skill']) ?></p>
                                </div>
                                <div class="col-md-4">
                                    <strong>วิธีการประเมิน:</strong>
                                    <p class="small text-muted"><?= htmlspecialchars($data['method']) ?></p>
                                </div>
                                <div class="col-md-4">
                                    <strong>การส่งเสริมพัฒนาการ:</strong>
                                    <p class="small text-muted"><?= htmlspecialchars($data['training']) ?></p>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="text-center">
                                <strong>ผลการประเมิน:</strong>
                                <?php 
                                $question_key = "question_" . $question_id;
                                $is_passed = isset($responses[$question_key]) && $responses[$question_key]['passed'] == 1;
                                ?>
                                <div class="mt-2">
                                    <span class="badge fs-6 <?= $is_passed ? 'bg-success' : 'bg-danger' ?> px-3 py-2">
                                        <i class="fas fa-<?= $is_passed ? 'check-circle' : 'times-circle' ?> me-2"></i>
                                        <?= $is_passed ? 'ผ่าน' : 'ไม่ผ่าน' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="evaluation_history.php?child_id=<?= $evaluation['eva_child_id'] ?>" 
                   class="btn btn-purple me-2">
                    <i class="fas fa-arrow-left me-2"></i>กลับไปดูประวัติ
                </a>
                <a href="children_list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-home me-2"></i>กลับหน้าหลัก
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

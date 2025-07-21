<?php
session_start();
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

// ดึงข้อมูลการประเมิน
$sql = "SELECT e.*, c.name as child_name, c.photo 
        FROM evaluations e 
        JOIN children c ON e.child_id = c.id 
        WHERE e.id = ? AND e.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $evaluation_id, $user['id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "ไม่พบข้อมูลการประเมินที่ต้องการดู";
    header("Location: children_list.php");
    exit();
}

$evaluation = $result->fetch_assoc();
$responses = json_decode($evaluation['responses'], true);

$stmt->close();
$conn->close();

// ข้อคำถามประเมินพัฒนาการ 0-1 เดือน
$questions = [
    'feeding' => [
        'title' => 'การรับประทานอาหาร',
        'items' => [
            'ดูดนมแม่หรือนมขวดได้',
            'กลืนได้',
            'ดูดได้อย่างประสานสัมพันธ์กับการหายใจ'
        ]
    ],
    'sensory' => [
        'title' => 'ประสาทสัมผัส',
        'items' => [
            'สะดุ้งเมื่อมีเสียงดัง',
            'หลับตาเมื่อมีแสงแรง',
            'มีปฏิกิริยาตอบสนองต่อกลิ่น'
        ]
    ],
    'movement' => [
        'title' => 'การเคลื่อนไหว',
        'items' => [
            'ขยับแขนขา',
            'หมุนหัวได้',
            'เมื่อจับมือจะกำมือเป็นหมัด'
        ]
    ],
    'communication' => [
        'title' => 'การสื่อสาร',
        'items' => [
            'ร้องไห้เมื่อหิว',
            'ร้องไห้เมื่อเปียก',
            'มีเสียงนอกจากการร้องไห้'
        ]
    ],
    'social' => [
        'title' => 'สังคม',
        'items' => [
            'สงบลงเมื่อได้ยินเสียงคุ้นเคย',
            'มองหาเสียงที่คุ้นเคย',
            'มีท่าทางแสดงอารมณ์'
        ]
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
                                        <strong>ช่วงอายุ:</strong> <?= htmlspecialchars($evaluation['age_range']) ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>เวอร์ชัน:</strong> <?= $evaluation['version'] ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>วันที่ประเมิน:</strong> 
                                        <?= date('d/m/Y H:i', strtotime($evaluation['evaluation_time'])) ?>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <strong>คะแนนรวม:</strong> 
                                        <span class="badge bg-primary status-badge">
                                            <?= $evaluation['total_score'] ?>/<?= $evaluation['total_questions'] ?>
                                        </span>
                                    </div>
                                    <?php if ($evaluation['notes']): ?>
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
            <?php foreach ($questions as $category => $data): ?>
                <div class="col-md-6 mb-4">
                    <div class="card category-card">
                        <div class="card-header">
                            <h6 class="mb-0"><?= $data['title'] ?></h6>
                        </div>
                        <div class="card-body">
                            <?php 
                            $category_score = 0;
                            foreach ($data['items'] as $index => $item): 
                                $key = $category . '_' . $index;
                                $is_checked = isset($responses[$key]) && $responses[$key] == '1';
                                if ($is_checked) $category_score++;
                            ?>
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-<?= $is_checked ? 'check-circle checked-item' : 'times-circle unchecked-item' ?> me-2"></i>
                                    <span class="<?= $is_checked ? 'checked-item' : 'unchecked-item' ?>">
                                        <?= htmlspecialchars($item) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="text-end">
                                <span class="badge bg-<?= $category_score == count($data['items']) ? 'success' : ($category_score > 0 ? 'warning' : 'secondary') ?>">
                                    คะแนน: <?= $category_score ?>/<?= count($data['items']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="evaluation_history.php?child_id=<?= $evaluation['child_id'] ?>" 
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

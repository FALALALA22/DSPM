<?php
session_start();
require_once 'db_conn.php';

header('Content-Type: application/json');

// เพิ่ม debug information
error_log("Update profile called with POST data: " . print_r($_POST, true));

// ตรวจสอบว่าผู้ใช้ล็อกอินแล้วหรือยัง
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    $errors = [];
    
    // ตรวจสอบข้อมูล
    if (empty($fname)) {
        $errors[] = 'กรุณากรอกชื่อ';
    }
    if (empty($lname)) {
        $errors[] = 'กรุณากรอกนามสกุล';
    }
    if (empty($phone)) {
        $errors[] = 'กรุณากรอกเบอร์โทรศัพท์';
    } elseif (!preg_match("/^[0-9]{10}$/", $phone)) {
        $errors[] = 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit();
    }
    
    // อัปเดตข้อมูล
    if (!empty($password)) {
        // อัปเดตรวมรหัสผ่าน
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET user_fname = ?, user_lname = ?, user_phone = ?, user_password = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $fname, $lname, $phone, $hashed_password, $user_id);
    } else {
        // อัปเดตไม่รวมรหัสผ่าน
        $sql = "UPDATE users SET user_fname = ?, user_lname = ?, user_phone = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $fname, $lname, $phone, $user_id);
    }
    
    if ($stmt->execute()) {
        // อัปเดต session (อัปเดตทั้งคีย์ที่โค้ดอื่นๆ คาดหวังไว้)
        $_SESSION['fname'] = $fname;
        $_SESSION['lname'] = $lname;
        // เก็บไว้ด้วยชื่อคีย์เดิมถ้ามีที่อื่นใช้งาน
        $_SESSION['user_fname'] = $fname;
        $_SESSION['user_lname'] = $lname;
        
        echo json_encode([
            'success' => true, 
            'message' => 'อัปเดตข้อมูลเรียบร้อย',
            'data' => [
                'fname' => $fname,
                'lname' => $lname,
                'phone' => $phone
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>
<?php
session_start();
require_once '../check_session.php';
require_once '../db_conn.php';

checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง
$user = getUserInfo();

$evaluation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

if ($evaluation_id == 0 || $child_id == 0) {
    $_SESSION['error'] = "ข้อมูลไม่ถูกต้อง";
    header("Location: children_list.php");
    exit();
}

// ตรวจสอบว่าการประเมินนี้เป็นของผู้ใช้คนนี้จริง
$check_sql = "SELECT id FROM evaluations WHERE id = ? AND user_id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("ii", $evaluation_id, $user['id']);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "ไม่พบข้อมูลการประเมินที่ต้องการลบ";
    header("Location: children_list.php");
    exit();
}

// ลบข้อมูลการประเมิน
$delete_sql = "DELETE FROM evaluations WHERE id = ? AND user_id = ?";
$delete_stmt = $conn->prepare($delete_sql);
$delete_stmt->bind_param("ii", $evaluation_id, $user['id']);

if ($delete_stmt->execute()) {
    $_SESSION['success'] = "ลบผลการประเมินเรียบร้อยแล้ว";
} else {
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบข้อมูล";
}

$check_stmt->close();
$delete_stmt->close();
$conn->close();

header("Location: evaluation_history.php?child_id={$child_id}");
exit();
?>

<?php
session_start();

// ลบข้อมูลทั้งหมดใน session
session_unset();

// ทำลาย session
session_destroy();

// เปลี่ยนเส้นทางกลับไปหน้า login พร้อมข้อความ
session_start();
$_SESSION['success'] = "ออกจากระบบเรียบร้อยแล้ว";
header("Location: login.php");
exit();
?>

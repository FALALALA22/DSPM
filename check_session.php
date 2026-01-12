<?php
// ไฟล์สำหรับตรวจสอบสถานะการล็อกอิน
// ใช้ include ไฟล์นี้ในหน้าที่ต้องการให้ผู้ใช้ล็อกอินก่อน

session_start();

function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        // ถ้ายังไม่ล็อกอิน ให้ไปหน้า login
        header("Location: ../login.php");
        exit();
    }
}

function getUserInfo() {
    if (isset($_SESSION['user_id'])) {
        return array(
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'fname' => $_SESSION['fname'],
            'lname' => $_SESSION['lname'],
            'user_role' => $_SESSION['user_role'] ?? 'user',
            'user_hospital' => $_SESSION['user_hospital'] ?? null,
            'login_time' => $_SESSION['login_time']
        );
    }
    return null;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
?>

<?php
// ตั้งค่า timezone ให้เป็นเวลาไทย
date_default_timezone_set('Asia/Bangkok');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "dspm_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้งค่า timezone สำหรับ MySQL
$conn->query("SET time_zone = '+07:00'");

// Set charset to utf8
$conn->set_charset("utf8");

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");
?>

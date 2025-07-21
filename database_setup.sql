-- สร้างฐานข้อมูล dspm_db
CREATE DATABASE IF NOT EXISTS dspm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ใช้ฐานข้อมูล dspm_db
USE dspm_db;

-- สร้างตาราง users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fname VARCHAR(100) NOT NULL,
    lname VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_username ON users(username);
CREATE INDEX idx_phone ON users(phone);

-- สร้างตาราง children สำหรับเก็บข้อมูลเด็ก
CREATE TABLE IF NOT EXISTS children (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    child_name VARCHAR(200) NOT NULL,
    date_of_birth DATE NOT NULL,
    age_years INT NOT NULL,
    age_months INT DEFAULT 0,
    photo_path VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_user_id ON children(user_id);
CREATE INDEX idx_child_name ON children(child_name);

-- สร้างตาราง evaluations สำหรับเก็บผลการประเมิน
CREATE TABLE IF NOT EXISTS evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_id INT NOT NULL,
    user_id INT NOT NULL,
    age_range VARCHAR(10) NOT NULL,
    evaluation_data JSON NOT NULL,
    total_passed INT DEFAULT 0,
    total_failed INT DEFAULT 0,
    evaluation_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_evaluation (child_id, age_range, evaluation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_child_evaluation ON evaluations(child_id, age_range);
CREATE INDEX idx_evaluation_date ON evaluations(evaluation_date);

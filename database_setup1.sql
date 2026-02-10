-- สร้างฐานข้อมูล dspm_db
CREATE DATABASE IF NOT EXISTS testdspm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ใช้ฐานข้อมูล dspm_db
USE testdspm_db;

-- สร้างตาราง users
CREATE TABLE IF NOT EXISTS users (
    user_id_auto INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    hosp_shph_id VARCHAR(20) NOT NULL,
    user_username VARCHAR(50) UNIQUE NOT NULL,
    user_password VARCHAR(255) NOT NULL,
    user_fname VARCHAR(100) NOT NULL,
    user_lname VARCHAR(100) NOT NULL,
    user_phone VARCHAR(15) NOT NULL,
    user_role ENUM('user', 'admin', 'staff') DEFAULT 'user',
    user_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_user_username ON users(user_username);
CREATE INDEX idx_user_phone ON users(user_phone);

-- สร้างตาราง children สำหรับเก็บข้อมูลเด็ก
CREATE TABLE IF NOT EXISTS children (
    chi_id_auto INT AUTO_INCREMENT PRIMARY KEY,
    chi_id INT UNIQUE NOT NULL,
    user_id INT NOT NULL,
    hosp_shph_id VARCHAR(20) NOT NULL,
    chi_child_name VARCHAR(200) NOT NULL,
    chi_date_of_birth DATE NOT NULL,
    chi_age_years INT NOT NULL,
    chi_age_months INT DEFAULT 0,
    chi_weight DECIMAL(5,2) DEFAULT NULL,
    chi_height DECIMAL(5,2) DEFAULT NULL,
    chi_photo VARCHAR(255) DEFAULT NULL,
    chi_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    chi_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_chi_user_id ON children(user_id);
CREATE INDEX idx_chi_child_name ON children(chi_child_name);

-- สร้างตาราง evaluations สำหรับเก็บผลการประเมิน
CREATE TABLE IF NOT EXISTS evaluations (
    eva_id_auto INT AUTO_INCREMENT PRIMARY KEY,ฃ
    eva_id INT NOT NULL,
    chi_id INT NOT NULL,
    user_id INT NOT NULL,
    eva_age_range VARCHAR(10) NOT NULL,
    eva_responses JSON NOT NULL,
    eva_total_score INT DEFAULT 0,
    eva_total_questions INT DEFAULT 0,
    eva_evaluation_date DATE NOT NULL,
    eva_evaluation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    eva_version INT DEFAULT 1,
    eva_notes TEXT DEFAULT NULL,
    eva_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    eva_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_eva_child_evaluation ON evaluations(chi_id, eva_age_range);
CREATE INDEX idx_eva_evaluation_date ON evaluations(eva_evaluation_date);
CREATE INDEX idx_eva_evaluation_version ON evaluations(chi_id, eva_age_range, eva_evaluation_date, eva_version);

-- สร้างตาราง hospitals สำหรับเก็บผลการประเมิน
CREATE TABLE IF NOT EXISTS hospitals (
    hosp_id_auto INT AUTO_INCREMENT PRIMARY KEY,
    hosp_shph_id VARCHAR(20) NOT NULL,
    hosp_name VARCHAR(255) NOT NULL,
    hosp_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    hosp_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_hosp_shph_id ON hospitals(hosp_shph_id);



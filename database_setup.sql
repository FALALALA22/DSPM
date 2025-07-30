-- สร้างฐานข้อมูล dspm_db
CREATE DATABASE IF NOT EXISTS dspm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ใช้ฐานข้อมูล dspm_db
USE dspm_db;

-- สร้างตาราง users
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
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
    chi_id INT AUTO_INCREMENT PRIMARY KEY,
    chi_user_id INT NOT NULL,
    chi_child_name VARCHAR(200) NOT NULL,
    chi_date_of_birth DATE NOT NULL,
    chi_age_years INT NOT NULL,
    chi_age_months INT DEFAULT 0,
    chi_weight DECIMAL(5,2) DEFAULT NULL,
    chi_height DECIMAL(5,2) DEFAULT NULL,
    chi_photo VARCHAR(255) DEFAULT NULL,
    chi_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    chi_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (chi_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_chi_user_id ON children(chi_user_id);
CREATE INDEX idx_chi_child_name ON children(chi_child_name);

-- สร้างตาราง evaluations สำหรับเก็บผลการประเมิน
CREATE TABLE IF NOT EXISTS evaluations (
    eva_id INT AUTO_INCREMENT PRIMARY KEY,
    eva_child_id INT NOT NULL,
    eva_user_id INT NOT NULL,
    eva_age_range VARCHAR(10) NOT NULL,
    eva_responses JSON NOT NULL,
    eva_total_score INT DEFAULT 0,
    eva_total_questions INT DEFAULT 0,
    eva_evaluation_date DATE NOT NULL,
    eva_evaluation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    eva_version INT DEFAULT 1,
    eva_notes TEXT DEFAULT NULL,
    eva_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    eva_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (eva_child_id) REFERENCES children(chi_id) ON DELETE CASCADE,
    FOREIGN KEY (eva_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่ม index สำหรับการค้นหา
CREATE INDEX idx_eva_child_evaluation ON evaluations(eva_child_id, eva_age_range);
CREATE INDEX idx_eva_evaluation_date ON evaluations(eva_evaluation_date);
CREATE INDEX idx_eva_evaluation_version ON evaluations(eva_child_id, eva_age_range, eva_evaluation_date, eva_version);

-- เพิ่มข้อมูล Admin และ Staff ตัวอย่าง (password = "password123")
INSERT IGNORE INTO users (user_username, user_password, user_fname, user_lname, user_phone, user_role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', 'DSPM', '0800000000', 'admin'),
('staff001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่', 'คนที่ 1', '0800000001', 'staff'),
('staff002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่', 'คนที่ 2', '0800000002', 'staff');

-- Migration Script: เปลี่ยนชื่อ columns ให้มี prefix
-- รันสคริปต์นี้เพื่ออัพเดตฐานข้อมูลที่มีอยู่

USE dsmp_db;

-- สำรองข้อมูลก่อน (สร้างตารางสำรอง)
CREATE TABLE users_backup AS SELECT * FROM users;
CREATE TABLE children_backup AS SELECT * FROM children;
CREATE TABLE evaluations_backup AS SELECT * FROM evaluations;

-- ลบตารางเดิม
DROP TABLE IF EXISTS evaluations;
DROP TABLE IF EXISTS children;
DROP TABLE IF EXISTS users;

-- สร้างตารางใหม่ด้วย column names ที่มี prefix
-- สร้างตาราง users ใหม่
CREATE TABLE users (
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

-- สร้างตาราง children ใหม่
CREATE TABLE children (
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

-- สร้างตาราง evaluations ใหม่
CREATE TABLE evaluations (
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

-- คัดลอกข้อมูลจากตารางสำรองมาใส่ตารางใหม่
INSERT INTO users (user_id, user_username, user_password, user_fname, user_lname, user_phone, user_role, user_created_at, user_updated_at)
SELECT id, username, password, fname, lname, phone, COALESCE(role, 'user'), created_at, updated_at 
FROM users_backup;

INSERT INTO children (chi_id, chi_user_id, chi_child_name, chi_date_of_birth, chi_age_years, chi_age_months, chi_weight, chi_height, chi_photo, chi_created_at, chi_updated_at)
SELECT id, user_id, child_name, date_of_birth, age_years, age_months, weight, height, 
       COALESCE(photo, photo_path), created_at, updated_at
FROM children_backup;

INSERT INTO evaluations (eva_id, eva_child_id, eva_user_id, eva_age_range, eva_responses, eva_total_score, eva_total_questions, eva_evaluation_date, eva_evaluation_time, eva_version, eva_notes, eva_created_at, eva_updated_at)
SELECT id, child_id, user_id, age_range, 
       COALESCE(evaluation_data, responses), 
       COALESCE(total_passed, total_score), 
       COALESCE(total_failed, total_questions),
       evaluation_date, 
       COALESCE(evaluation_time, created_at),
       version, notes, created_at, updated_at
FROM evaluations_backup;

-- เพิ่ม indexes
CREATE INDEX idx_user_username ON users(user_username);
CREATE INDEX idx_user_phone ON users(user_phone);
CREATE INDEX idx_chi_user_id ON children(chi_user_id);
CREATE INDEX idx_chi_child_name ON children(chi_child_name);
CREATE INDEX idx_eva_child_evaluation ON evaluations(eva_child_id, eva_age_range);
CREATE INDEX idx_eva_evaluation_date ON evaluations(eva_evaluation_date);
CREATE INDEX idx_eva_evaluation_version ON evaluations(eva_child_id, eva_age_range, eva_evaluation_date, eva_version);

-- เพิ่มข้อมูล Admin และ Staff (ถ้ายังไม่มี)
INSERT IGNORE INTO users (user_username, user_password, user_fname, user_lname, user_phone, user_role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', 'DSPM', '0800000000', 'admin'),
('staff001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่', 'คนที่ 1', '0800000001', 'staff'),
('staff002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'เจ้าหน้าที่', 'คนที่ 2', '0800000002', 'staff');

-- ลบตารางสำรอง (เอาออกหากต้องการเก็บไว้)
-- DROP TABLE users_backup;
-- DROP TABLE children_backup;
-- DROP TABLE evaluations_backup;

SELECT 'Migration completed successfully!' as result;

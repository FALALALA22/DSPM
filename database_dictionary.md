# DSPM Database Dictionary

## ภาพรวมฐานข้อมูล
ฐานข้อมูล: `dspm_db`
Character Set: `utf8mb4`
Collation: `utf8mb4_unicode_ci`

---

## ตาราง: users
**จุดประสงค์:** เก็บข้อมูลผู้ใช้งานระบบ (ผู้ปกครอง, เจ้าหน้าที่, ผู้ดูแลระบบ)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| user_id | INT | PRIMARY KEY, AUTO_INCREMENT | รหัสผู้ใช้ (Primary Key) |
| user_username | VARCHAR(50) | UNIQUE, NOT NULL | ชื่อผู้ใช้สำหรับเข้าสู่ระบบ |
| user_password | VARCHAR(255) | NOT NULL | รหัสผ่าน (Hashed) |
| user_fname | VARCHAR(100) | NOT NULL | ชื่อจริง |
| user_lname | VARCHAR(100) | NOT NULL | นามสกุล |
| user_phone | VARCHAR(15) | NOT NULL | หมายเลขโทรศัพท์ |
| user_role | ENUM('user','admin','staff') | DEFAULT 'user' | บทบาทในระบบ |
| user_created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | วันเวลาที่สร้างบัญชี |
| user_updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | วันเวลาที่อัปเดตล่าสุด |

**Indexes:**
- `idx_user_username` ON user_username
- `idx_user_phone` ON user_phone

**Business Rules:**
- user_username ต้องไม่ซ้ำกัน
- user_role: 'user' = ผู้ปกครอง, 'staff' = เจ้าหน้าที่, 'admin' = ผู้ดูแลระบบ

---

## ตาราง: children
**จุดประสงค์:** เก็บข้อมูลเด็กที่ลงทะเบียนในระบบ

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| chi_id | INT | PRIMARY KEY, AUTO_INCREMENT | รหัสเด็ก (Primary Key) |
| chi_user_id | INT | NOT NULL, FOREIGN KEY | รหัสผู้ปกครอง (FK to users.user_id) |
| chi_child_name | VARCHAR(200) | NOT NULL | ชื่อเด็ก |
| chi_date_of_birth | DATE | NOT NULL | วันเกิด |
| chi_age_years | INT | NOT NULL | อายุ (ปี) |
| chi_age_months | INT | DEFAULT 0 | อายุ (เดือน) |
| chi_weight | DECIMAL(5,2) | NULL | น้ำหนัก (กิโลกรัม) |
| chi_height | DECIMAL(5,2) | NULL | ส่วนสูง (เซนติเมตร) |
| chi_photo | VARCHAR(255) | NULL | ชื่อไฟล์รูปภาพ |
| chi_created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | วันเวลาที่ลงทะเบียน |
| chi_updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | วันเวลาที่อัปเดตล่าสุด |

**Indexes:**
- `idx_chi_user_id` ON chi_user_id
- `idx_chi_child_name` ON chi_child_name

**Foreign Keys:**
- chi_user_id REFERENCES users(user_id) ON DELETE CASCADE

**Business Rules:**
- เด็กหนึ่งคนต้องมีผู้ปกครองอย่างน้อย 1 คน
- ไฟล์รูปภาพเก็บใน directory: uploads/children/

---

## ตาราง: evaluations
**จุดประสงค์:** เก็บผลการประเมินพัฒนาการเด็ก

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| eva_id | INT | PRIMARY KEY, AUTO_INCREMENT | รหัสการประเมิน (Primary Key) |
| eva_child_id | INT | NOT NULL, FOREIGN KEY | รหัสเด็ก (FK to children.chi_id) |
| eva_user_id | INT | NOT NULL, FOREIGN KEY | รหัสผู้ประเมิน (FK to users.user_id) |
| eva_age_range | VARCHAR(10) | NOT NULL | ช่วงอายุที่ประเมิน (เช่น "0-1", "1-2") |
| eva_responses | JSON | NOT NULL | คำตอบการประเมิน (JSON format) |
| eva_total_score | INT | DEFAULT 0 | คะแนนรวม/จำนวนข้อที่ผ่าน |
| eva_total_questions | INT | DEFAULT 0 | จำนวนข้อคำถามทั้งหมด |
| eva_evaluation_date | DATE | NOT NULL | วันที่ประเมิน |
| eva_evaluation_time | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | เวลาที่ประเมิน |
| eva_version | INT | DEFAULT 1 | เวอร์ชันการประเมิน (สำหรับการประเมินซ้ำ) |
| eva_notes | TEXT | NULL | หมายเหตุเพิ่มเติม |
| eva_created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | วันเวลาที่บันทึก |
| eva_updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP | วันเวลาที่อัปเดตล่าสุด |

**Indexes:**
- `idx_eva_child_evaluation` ON (eva_child_id, eva_age_range)
- `idx_eva_evaluation_date` ON eva_evaluation_date
- `idx_eva_evaluation_version` ON (eva_child_id, eva_age_range, eva_evaluation_date, eva_version)

**Foreign Keys:**
- eva_child_id REFERENCES children(chi_id) ON DELETE CASCADE
- eva_user_id REFERENCES users(user_id) ON DELETE CASCADE

**Business Rules:**
- eva_responses เก็บเป็น JSON format: {"question_1": {"passed": 1, "failed": 0}, ...}
- eva_version เพิ่มขึ้นเมื่อประเมินซ้ำในวันเดียวกัน
- eva_age_range format: "X-Y" เช่น "0-1", "1-2", "2-3"

---

## ความสัมพันธ์ (Relationships)

```
users (1) ----< children (M)
  |                |
  |                |
  +-------< evaluations (M)
              |
              +----< children (1)
```

1. ผู้ใช้หนึ่งคน (users) สามารถมีเด็กได้หลายคน (children) - One to Many
2. ผู้ใช้หนึ่งคน (users) สามารถทำการประเมินได้หลายครั้ง (evaluations) - One to Many  
3. เด็กหนึ่งคน (children) สามารถมีการประเมินได้หลายครั้ง (evaluations) - One to Many

---

## ข้อมูลตัวอย่าง (Sample Data)

### Users
```sql
INSERT INTO users VALUES 
(1, 'admin', '[hashed_password]', 'ผู้ดูแลระบบ', 'DSMP', '0800000000', 'admin', NOW(), NOW()),
(2, 'staff001', '[hashed_password]', 'เจ้าหน้าที่', 'คนที่ 1', '0800000001', 'staff', NOW(), NOW()),
(3, 'parent001', '[hashed_password]', 'สมชาย', 'ใจดี', '0812345678', 'user', NOW(), NOW());
```

### Children
```sql
INSERT INTO children VALUES 
(1, 3, 'เด็กหญิงสมหวัง', '2024-01-15', 0, 6, 7.5, 65.0, 'child1.jpg', NOW(), NOW());
```

### Evaluations
```sql
INSERT INTO evaluations VALUES 
(1, 1, 3, '0-1', '{"question_1":{"passed":1,"failed":0},"question_2":{"passed":0,"failed":1}}', 3, 5, '2024-07-30', NOW(), 1, 'เด็กมีพัฒนาการปกติ', NOW(), NOW());
```

---

## การใช้งาน (Usage Examples)

### ค้นหาเด็กทั้งหมดของผู้ปกครอง
```sql
SELECT * FROM children WHERE chi_user_id = ?
```

### ค้นหาการประเมินล่าสุดของเด็ก
```sql
SELECT * FROM evaluations 
WHERE eva_child_id = ? 
ORDER BY eva_evaluation_date DESC, eva_version DESC 
LIMIT 1
```

### ค้นหาเด็กทั้งหมดพร้อมผู้ปกครอง (สำหรับ Admin/Staff)
```sql
SELECT c.*, u.user_fname, u.user_lname, u.user_phone
FROM children c
JOIN users u ON c.chi_user_id = u.user_id
ORDER BY c.chi_created_at DESC
```

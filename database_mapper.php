<?php
/**
 * Database Configuration & Helper Functions
 * ไฟล์สำหรับการตั้งค่าและฟังก์ชันช่วยเหลือฐานข้อมูล
 * ใช้ column names ใหม่ที่มี prefix แล้ว
 */

class DatabaseHelper {
    
    /**
     * สร้าง SELECT query สำหรับตาราง users
     */
    public static function getUsersQuery($conditions = [], $columns = ['*']) {
        $select_columns = is_array($columns) ? implode(', ', $columns) : $columns;
        $sql = "SELECT {$select_columns} FROM users";
        
        if (!empty($conditions)) {
            $where_clauses = [];
            foreach ($conditions as $column => $value) {
                $where_clauses[] = "{$column} = ?";
            }
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        return $sql;
    }
    
    /**
     * สร้าง SELECT query สำหรับตาราง children
     */
    public static function getChildrenQuery($conditions = [], $columns = ['*'], $join_users = false) {
        $select_columns = is_array($columns) ? implode(', ', $columns) : $columns;
        $sql = "SELECT {$select_columns} FROM children c";
        
        if ($join_users) {
            $sql .= " JOIN users u ON c.chi_user_id = u.user_id";
        }
        
        if (!empty($conditions)) {
            $where_clauses = [];
            foreach ($conditions as $column => $value) {
                $where_clauses[] = "{$column} = ?";
            }
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        return $sql;
    }
    
    /**
     * สร้าง SELECT query สำหรับตาราง evaluations
     */
    public static function getEvaluationsQuery($conditions = [], $columns = ['*'], $join_children = false, $join_users = false) {
        $select_columns = is_array($columns) ? implode(', ', $columns) : $columns;
        $sql = "SELECT {$select_columns} FROM evaluations e";
        
        if ($join_children) {
            $sql .= " JOIN children c ON e.eva_child_id = c.chi_id";
        }
        
        if ($join_users) {
            $sql .= " JOIN users u ON e.eva_user_id = u.user_id";
        }
        
        if (!empty($conditions)) {
            $where_clauses = [];
            foreach ($conditions as $column => $value) {
                $where_clauses[] = "{$column} = ?";
            }
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        return $sql;
    }
    
    /**
     * Column names สำหรับใช้ในโค้ด (เพื่อป้องกันการพิมพ์ผิด)
     */
    
    // Users table columns
    const USER_ID = 'user_id';
    const USER_USERNAME = 'user_username';
    const USER_PASSWORD = 'user_password';
    const USER_FNAME = 'user_fname';
    const USER_LNAME = 'user_lname';
    const USER_PHONE = 'user_phone';
    const USER_ROLE = 'user_role';
    const USER_CREATED_AT = 'user_created_at';
    const USER_UPDATED_AT = 'user_updated_at';
    
    // Children table columns
    const CHI_ID = 'chi_id';
    const CHI_USER_ID = 'chi_user_id';
    const CHI_CHILD_NAME = 'chi_child_name';
    const CHI_DATE_OF_BIRTH = 'chi_date_of_birth';
    const CHI_AGE_YEARS = 'chi_age_years';
    const CHI_AGE_MONTHS = 'chi_age_months';
    const CHI_WEIGHT = 'chi_weight';
    const CHI_HEIGHT = 'chi_height';
    const CHI_PHOTO = 'chi_photo';
    const CHI_CREATED_AT = 'chi_created_at';
    const CHI_UPDATED_AT = 'chi_updated_at';
    
    // Evaluations table columns
    const EVA_ID = 'eva_id';
    const EVA_CHILD_ID = 'eva_child_id';
    const EVA_USER_ID = 'eva_user_id';
    const EVA_AGE_RANGE = 'eva_age_range';
    const EVA_RESPONSES = 'eva_responses';
    const EVA_TOTAL_SCORE = 'eva_total_score';
    const EVA_TOTAL_QUESTIONS = 'eva_total_questions';
    const EVA_EVALUATION_DATE = 'eva_evaluation_date';
    const EVA_EVALUATION_TIME = 'eva_evaluation_time';
    const EVA_VERSION = 'eva_version';
    const EVA_NOTES = 'eva_notes';
    const EVA_CREATED_AT = 'eva_created_at';
    const EVA_UPDATED_AT = 'eva_updated_at';
    
    /**
     * ตัวอย่างการใช้งาน
     */
    public static function getExamples() {
        return [
            'get_user_by_username' => self::getUsersQuery([self::USER_USERNAME => '?']),
            'get_children_by_user' => self::getChildrenQuery([self::CHI_USER_ID => '?']),
            'get_latest_evaluation' => self::getEvaluationsQuery(
                [self::EVA_CHILD_ID => '?', self::EVA_AGE_RANGE => '?'],
                ['*'],
                false,
                false
            ) . " ORDER BY " . self::EVA_EVALUATION_DATE . " DESC, " . self::EVA_VERSION . " DESC LIMIT 1"
        ];
    }
}

// ตัวอย่างการใช้งาน:
/*
// ค้นหาผู้ใช้จาก username
$sql = DatabaseHelper::getUsersQuery([DatabaseHelper::USER_USERNAME => '?']);
// ผลลัพธ์: SELECT * FROM users WHERE user_username = ?

// ค้นหาเด็กของผู้ใช้พร้อมข้อมูลผู้ปกครอง
$sql = DatabaseHelper::getChildrenQuery(
    [DatabaseHelper::CHI_USER_ID => '?'], 
    ['c.*', 'u.user_fname', 'u.user_lname'], 
    true
);
// ผลลัพธ์: SELECT c.*, u.user_fname, u.user_lname FROM children c JOIN users u ON c.chi_user_id = u.user_id WHERE chi_user_id = ?
*/
?>

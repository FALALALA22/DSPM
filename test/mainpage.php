<?php
require_once '../check_session.php';
checkLogin(); // ตรวจสอบว่าล็อกอินแล้วหรือยัง

$user = getUserInfo();

// หากเป็น staff ให้ไปยังหน้า mainpage ของ staff โดยเฉพาะ
if (isset($user['user_role']) && $user['user_role'] === 'staff') {
  header('Location: mainpage_staff.php');
  exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลัก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="/DSPMProj/css/test.css" />
    <link rel="stylesheet" href="/DSPMProj/css/btncircle.css" />
    
    <style>
      /* Circle button styles - mobile first */
      .circle-button {
        width: 280px;
        height: 280px;
        border-radius: 50% !important;
        transition: all 0.3s ease;
        text-decoration: none !important;
        display: flex !important;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
        margin: 0 auto;
      }
      
      .circle-button:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.25) !important;
      }
      
      .circle-button img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
        margin-bottom: 12px;
      }
      
      .circle-button h3 {
        font-size: 1.2rem;
        margin: 0;
        text-align: center;
        line-height: 1.2;
        padding: 0 15px;
      }
      
      /* Mobile styles (≤576px) */
      @media (max-width: 576px) {
        .navbar-brand {
          font-size: 1.1rem;
        }
        
        .circle-button {
          width: 200px;
          height: 200px;
          margin-bottom: 0;
        }
        
        .circle-button img {
          width: 120px;
          height: 120px;
        }
        
        .circle-button h3 {
          font-size: 0.9rem;
        }
        
        .modal-dialog {
          margin: 1rem;
        }
      }
      
      /* Small tablet styles (577px - 768px) */
      @media (min-width: 577px) and (max-width: 768px) {
        .circle-button {
          width: 240px;
          height: 240px;
        }
        
        .circle-button img {
          width: 120px;
          height: 120px;
        }
        
        .circle-button h3 {
          font-size: 1rem;
        }
      }
      
      /* Large tablet/desktop styles (≥769px) */
      @media (min-width: 769px) {
        .circle-button {
          width: 300px;
          height: 300px;
        }
        
        .circle-button img {
          width: 200px;
          height: 200px;
        }
        
        .circle-button h3 {
          font-size: 1.3rem;
        }
      }
      
      /* Enhanced modal styles */
      .modal-content {
        border-radius: 15px;
      }
      
      .modal-header {
        border-radius: 15px 15px 0 0;
      }
      
      /* Improved text responsiveness */
      @media (max-width: 576px) {
        .fs-3 { font-size: 1.3rem !important; }
        .fs-4 { font-size: 1.1rem !important; }
        .fs-5 { font-size: 0.9rem !important; }
      }
      
      @media (min-width: 577px) and (max-width: 768px) {
        .fs-3 { font-size: 1.6rem !important; }
        .fs-4 { font-size: 1.3rem !important; }
        .fs-5 { font-size: 1rem !important; }
      }
      
      /* Button container */
      .button-container {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.2;
      }
      
      @media (max-width: 576px) {
        .button-container {
          flex-direction: column;
          gap: 0.2;
        }
      }
      
      /* Remove margins for tight spacing */
      .circle-button {
        margin: 0.2 !important;
      }
    </style>
    
    <script>
    // Declare functions in global scope early
    function openProfile() {
      console.log('Opening profile modal...');
      const modal = document.getElementById('profileModal');
      if (modal) {
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'modal-backdrop';
        document.body.appendChild(backdrop);
      }
    }
    
    function closeProfile() {
      console.log('Closing profile modal...');
      const modal = document.getElementById('profileModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        
        // Remove backdrop
        const backdrop = document.getElementById('modal-backdrop');
        if (backdrop) {
          backdrop.remove();
        }
      }
    }
    
    function editProfile() {
      // ซ่อน modal ข้อมูลส่วนตัว
      closeProfile();
      
      // เปิด modal แก้ไขข้อมูล
      setTimeout(() => {
        openEditModal();
      }, 300);
    }
    
    function openEditModal() {
      const modal = document.getElementById('editProfileModal');
      if (modal) {
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'edit-modal-backdrop';
        document.body.appendChild(backdrop);
      }
    }
    
    function closeEditModal() {
      const modal = document.getElementById('editProfileModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        
        const backdrop = document.getElementById('edit-modal-backdrop');
        if (backdrop) {
          backdrop.remove();
        }
      }
    }
    
    function saveProfile() {
      const form = document.getElementById('editProfileForm');
      const formData = new FormData(form);
      
      // ปิดการใช้งานปุ่ม
      const saveBtn = document.querySelector('#editProfileModal .btn-primary');
      const originalText = saveBtn.innerHTML;
      saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
      saveBtn.disabled = true;
      
      console.log('Sending data to update_profile.php');
      
      fetch('../update_profile.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
      })
      .then(text => {
        console.log('Raw response:', text);
        try {
          const data = JSON.parse(text);
          if (data.success) {
            // อัปเดตข้อมูลในหน้า
            updateProfileDisplay(data.data);
            closeEditModal();
            alert('บันทึกข้อมูลเรียบร้อยแล้ว');
            location.reload(); // รีเฟรชหน้าเพื่อแสดงข้อมูลใหม่
          } else {
            alert('เกิดข้อผิดพลาด: ' + data.message);
          }
        } catch (e) {
          console.error('JSON parse error:', e);
          alert('ข้อผิดพลาดในการประมวลผลข้อมูล: ' + text);
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message);
      })
      .finally(() => {
        // คืนค่าปุ่ม
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
      });
    }
    
    function updateProfileDisplay(data) {
      // อัปเดตข้อมูลใน navigation bar
      const navbarText = document.querySelector('.navbar-text');
      if (navbarText) {
        navbarText.textContent = `สวัสดี, ${data.fname} ${data.lname}`;
      }
    }
    
    console.log('Functions declared in head - openProfile type:', typeof openProfile);
    </script>
</head>
<body class="bg-light">

  <!-- Navigation Bar -->
  <nav class="navbar navbar-expand-md navbar-dark bg-primary">
    <div class="container-fluid px-3">
      <a class="navbar-brand fw-bold" href="#">DSPM System</a>
      
      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      
      <div class="collapse navbar-collapse" id="navbarNav">
        <div class="navbar-nav ms-auto">
          <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2">
            <span class="navbar-text text-light mb-2 mb-md-0 me-md-3 fs-6">
              สวัสดี, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?>
            </span>
            <div class="d-flex flex-column flex-md-row gap-2">
              <button class="btn btn-outline-light btn-sm" onclick="openProfile()">
                <i class="fas fa-user me-1"></i>ข้อมูลส่วนตัว
              </button>
              <a class="btn btn-outline-light btn-sm" href="../logout.php">
                <i class="fas fa-sign-out-alt me-1"></i>ออกจากระบบ
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <div class="container-fluid px-3 py-4">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-10 text-center">
        
        <!-- Title Section -->
        <div class="mb-4">
          <h1 class="mb-4 fw-bold">
            <span class="d-block fs-3 fs-md-2 fs-lg-1" style="color: #005DFF;">เว็บเฝ้าระวังและส่งเสริมพัฒนาการเด็กประถมวัย</span>
            <span class="d-block fs-4 fs-md-3 fs-lg-2" style="color: #FF00D4;">Developmental Surveillance and Promotion Manual</span>
            <span class="d-block fs-2 fs-md-1 fw-bolder" style="color: #1FFD01;">(DSPM)</span>
          </h1>
        </div>

        <!-- Hero Image -->
        <div class="mb-4 d-flex justify-content-center">
          <img src="../image/family-non1.png" alt="Family" 
               class="img-fluid rounded shadow-lg" 
               style="max-width: min(500px, 90vw); height: auto;" />
        </div>
        
        <!-- Instruction Text -->
        <div class="mb-4">
          <h3 class="fs-4 fs-md-3 text-primary fw-medium px-2">
            กรุณาคลิกเลือกเมนูที่ต้องการด้านล่างเพื่อเข้าใช้งานระบบ
          </h3>
        </div>

        <!-- Menu Buttons -->
        <div class="button-container">
          <a href="kidinfo.php" class="btn btn-primary circle-button border-0 shadow">
            <img src="../image/regis.png" alt="Kid Info" />
            <h3 class="fw-bold text-white">ลงทะเบียนข้อมูลเด็ก</h3>
          </a>
          
          <a href="children_list.php" class="btn btn-success circle-button border-0 shadow">
            <img src="../image/kids.png" alt="Kid List" />
            <h3 class="fw-bold text-white">รายชื่อเด็ก</h3>
          </a>
        </div>
        
      </div>
    </div>
  </div>

  <!-- Modal สำหรับแสดงข้อมูลส่วนตัว -->
  <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title fw-bold" id="profileModalLabel">
            <i class="fas fa-user-circle me-2"></i>ข้อมูลส่วนตัว
          </h5>
          <button type="button" class="btn-close btn-close-white" onclick="closeProfile()" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">ชื่อผู้ใช้:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($user['username']); ?></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">ชื่อ:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($user['fname']); ?></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">นามสกุล:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($user['lname']); ?></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">เวลาเข้าสู่ระบบ:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($user['login_time']); ?></div>
          </div>
          <?php
          // ดึงข้อมูลเพิ่มเติมจากฐานข้อมูล
          require_once '../db_conn.php';
          $sql = "SELECT user_phone, user_created_at FROM users WHERE user_id = ?";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("i", $user['user_id']);
          $stmt->execute();
          $result = $stmt->get_result();
          $additional_info = $result->fetch_assoc();
          $stmt->close();
            // หากเป็น staff ให้ดึงชื่อโรงพยาบาล (ใช้ hosp_shph_id จาก session)
            $user_hospital_name = '';
            if (!empty($user['hosp_shph_id'])) {
              $hsql = "SELECT hosp_name FROM hospitals WHERE hosp_shph_id = ?";
              $hstmt = $conn->prepare($hsql);
              if ($hstmt) {
                $hstmt->bind_param('i', $user['hosp_shph_id']);
                $hstmt->execute();
                $hres = $hstmt->get_result();
                $hrow = $hres->fetch_assoc();
                $user_hospital_name = $hrow['hosp_name'] ?? '';
                $hstmt->close();
              }
            }
          ?>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">เบอร์โทรศัพท์:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($additional_info['user_phone']); ?></div>
          </div>
          <?php if ($user['user_role'] === 'staff'): ?>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">สังกัดโรงพยาบาล:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo htmlspecialchars($user_hospital_name ?: '-'); ?></div>
          </div>
          <?php endif; ?>
          <div class="row mb-3 align-items-center">
            <div class="col-12 col-sm-4 col-md-3"><strong class="text-primary">สมาชิกเมื่อ:</strong></div>
            <div class="col-12 col-sm-8 col-md-9 text-sm-start text-center"><?php echo date('d/m/Y H:i', strtotime($additional_info['user_created_at'])); ?></div>
          </div>
        </div>
        <div class="modal-footer bg-light d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-secondary w-100 w-sm-auto" onclick="closeProfile()">
            <i class="fas fa-times me-1"></i>ปิด
          </button>
          <button type="button" class="btn btn-primary w-100 w-sm-auto" onclick="editProfile()">
            <i class="fas fa-edit me-1"></i>แก้ไขข้อมูล
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal สำหรับแก้ไขข้อมูลส่วนตัว -->
  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title fw-bold" id="editProfileModalLabel">
            <i class="fas fa-edit me-2"></i>แก้ไขข้อมูลส่วนตัว
          </h5>
          <button type="button" class="btn-close btn-close-white" onclick="closeEditModal()" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <form id="editProfileForm">
            <div class="row mb-3">
              <label for="edit_fname" class="col-sm-3 col-form-label fw-bold text-success">ชื่อ:</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="edit_fname" name="fname" 
                       value="<?php echo htmlspecialchars($user['fname']); ?>" required>
              </div>
            </div>
            <div class="row mb-3">
              <label for="edit_lname" class="col-sm-3 col-form-label fw-bold text-success">นามสกุล:</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="edit_lname" name="lname" 
                       value="<?php echo htmlspecialchars($user['lname']); ?>" required>
              </div>
            </div>
            <?php
            // ใช้ connection เดียวกันที่เปิดไว้แล้ว
            $sql_edit = "SELECT user_phone FROM users WHERE user_id = ?";
            $stmt_edit = $conn->prepare($sql_edit);
            $stmt_edit->bind_param("i", $user['user_id']);
            $stmt_edit->execute();
            $result_edit = $stmt_edit->get_result();
            $edit_info = $result_edit->fetch_assoc();
            $stmt_edit->close();
            $conn->close(); // ปิด connection ครั้งเดียวที่ท้าย
            ?>
            <div class="row mb-3">
              <label for="edit_phone" class="col-sm-3 col-form-label fw-bold text-success">เบอร์โทรศัพท์:</label>
              <div class="col-sm-9">
                <input type="tel" class="form-control" id="edit_phone" name="phone" 
                       value="<?php echo htmlspecialchars($edit_info['user_phone']); ?>" 
                       pattern="[0-9]{10}" maxlength="10" required>
                <div class="form-text">กรุณากรอกเบอร์โทรศัพท์ 10 หลัก</div>
              </div>
            </div>
            <div class="row mb-3">
              <label for="edit_password" class="col-sm-3 col-form-label fw-bold text-success">รหัสผ่านใหม่:</label>
              <div class="col-sm-9">
                <input type="password" class="form-control" id="edit_password" name="password" 
                       placeholder="ไม่ต้องกรอกหากไม่ต้องการเปลี่ยนรหัสผ่าน">
                <div class="form-text">เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน</div>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer bg-light d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-secondary w-100 w-sm-auto" onclick="closeEditModal()">
            <i class="fas fa-times me-1"></i>ยกเลิก
          </button>
          <button type="button" class="btn btn-primary w-100 w-sm-auto" onclick="saveProfile()">
            <i class="fas fa-save me-1"></i>บันทึกข้อมูล
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

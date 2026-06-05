<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['thaid_register_profile'])) {
    header("Location: login.php");
    exit;
}

$profile = $_SESSION['thaid_register_profile'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $department = trim($_POST['department'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
    } else {
        try {
            // Check username duplicate
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = "ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $status = (defined('THAID_REQUIRE_ADMIN_APPROVAL') && THAID_REQUIRE_ADMIN_APPROVAL) ? 'pending_approval' : 'active';
                
                $sql = "INSERT INTO users (username, password, name, lastname, full_name, department, thaid_sub, thaid_pid, auth_provider, account_status, thaid_linked_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'thaid', ?, NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $username,
                    $hashedPassword,
                    $profile['name'],
                    $profile['lastname'],
                    $profile['full_name'],
                    $department,
                    $profile['sub'],
                    $profile['pid'],
                    $status
                ]);

                unset($_SESSION['thaid_register_profile']);
                
                if ($status === 'active') {
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['role'] = 'staff';
                    $_SESSION['full_name'] = $profile['full_name'];
                    header("Location: index.php");
                } else {
                    header("Location: login.php?msg=thaid_registered");
                }
                exit;
            }
        } catch (PDOException $e) {
            $error = "เกิดข้อผิดพลาดของฐานข้อมูล: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ลงทะเบียนบัญชีใหม่ (ThaiD) - <?= defined('APP_NAME') ? APP_NAME : 'TFS' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: 'Inter', 'Sarabun', sans-serif;
        }
        .register-container {
            background: #fff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 450px;
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-control[readonly] {
            background: #edf2f7;
            cursor: not-allowed;
        }
        .btn-primary {
            width: 100%;
            padding: 0.75rem;
            font-size: 1rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 0.875rem;
        }
        .thaid-badge {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="header">
        <h2>สร้างบัญชีผู้ใช้ใหม่</h2>
        <p style="color:var(--text-muted); font-size:0.9rem;">กรุณาตั้งค่าชื่อผู้ใช้และรหัสผ่านเพื่อใช้ควบคู่กับ ThaiD</p>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group" style="text-align: center;">
            <span class="thaid-badge">✓ ยืนยันตัวตนผ่าน ThaiD สำเร็จ</span>
            <div style="margin-top: 0.5rem; font-size: 0.9rem; color: #64748b;">
                เลขประจำตัวประชาชน: <strong><?= htmlspecialchars($profile['pid_masked'] ?? '') ?></strong>
            </div>
        </div>

        <div class="form-group">
            <label>ชื่อ-นามสกุล (ดึงจาก ThaiD)</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['full_name']) ?>" readonly>
        </div>

        <div class="form-group">
            <label>ชื่อผู้ใช้ (Username) <span style="color:red">*</span></label>
            <input type="text" name="username" class="form-control" placeholder="สำหรับเข้าสู่ระบบแบบปกติ" required autofocus>
        </div>

        <div class="form-group">
            <label>รหัสผ่าน (Password) <span style="color:red">*</span></label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="form-group">
            <label>หน่วยงาน / ฝ่าย (ตัวเลือก)</label>
            <input type="text" name="department" class="form-control" placeholder="เช่น ฝ่ายบริหารงานทั่วไป">
        </div>

        <button type="submit" class="btn btn-primary">ลงทะเบียน</button>
    </form>
</div>

</body>
</html>

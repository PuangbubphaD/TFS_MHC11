<?php
// Set secure session cookie parameters before session_start
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

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$error   = '';
$success = '';

// Check if requester is Director
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    checkCsrfOrDie();

    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    
    // Force role to staff unless registerer is logged-in admin
    $role      = ($is_admin) ? ($_POST['role'] ?? 'staff') : 'staff';
    $dept      = trim($_POST['department'] ?? '');

    if (!$username || !$password || !$full_name) {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านไม่ตรงกัน';
    } elseif (strlen($password) < 6) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $error = 'ชื่อผู้ใช้นี้ถูกใช้แล้ว';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username,password,full_name,role,department) VALUES (?,?,?,?,?)");
            $stmt->execute([$username, $hash, $full_name, $role, $dept]);
            $success = 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TFS - สมัครสมาชิก</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-card fade-in" style="max-width:480px">
        <div class="auth-logo" style="margin-bottom: 2.25rem;">
            <img src="assets/images/logo.png" alt="TFS Logo" style="max-width: 360px; width: 100%; height: auto; border-radius: 12px; background: #fff; padding: 0.5rem;">
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?> <a href="login.php">คลิกที่นี่</a></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>ชื่อ-สกุล <span class="required">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="ชื่อเต็ม" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>แผนก/กลุ่มงาน</label>
                    <input type="text" name="department" class="form-control" placeholder="ระบุแผนก" value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>ชื่อผู้ใช้ <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>บทบาท</label>
                    <?php if ($is_admin): ?>
                    <select name="role" class="form-control">
                        <option value="staff">เจ้าหน้าที่ (Staff)</option>
                        <option value="head">หัวหน้างาน (Head)</option>
                        <option value="director">ผู้อำนวยการ (Director)</option>
                        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                    <?php else: ?>
                    <input type="text" class="form-control" value="เจ้าหน้าที่ (Staff)" readonly style="background:#e2e8f0;cursor:not-allowed">
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>รหัสผ่าน <span class="required">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="อย่างน้อย 6 ตัว" required>
                </div>
                <div class="form-group">
                    <label>ยืนยันรหัสผ่าน <span class="required">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="กรอกอีกครั้ง" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center">
                ✅ สมัครสมาชิก
            </button>
        </form>
        <p style="text-align:center;margin-top:1.25rem;font-size:0.85rem;color:var(--text-muted)">
            มีบัญชีแล้ว? <a href="login.php" style="color:var(--primary);font-weight:600">เข้าสู่ระบบ</a>
        </p>
    </div>
</div>
</body>
</html>

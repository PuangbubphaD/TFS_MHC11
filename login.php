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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    checkCsrfOrDie();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Prevent Session Fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TFS - เข้าสู่ระบบ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-card fade-in">
        <div class="auth-logo" style="margin-bottom: 2.25rem;">
            <img src="assets/images/logo.png" alt="TFS Logo" style="max-width: 360px; width: 100%; height: auto; border-radius: 12px; background: #fff; padding: 0.5rem;">
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div class="form-group">
                <label for="username">ชื่อผู้ใช้</label>
                <input type="text" id="username" name="username" class="form-control"
                       placeholder="กรอกชื่อผู้ใช้" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">รหัสผ่าน</label>
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="กรอกรหัสผ่าน" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;margin-top:0.5rem">
                🔑 เข้าสู่ระบบ
            </button>
        </form>

        <p style="text-align:center;margin-top:1.5rem;font-size:0.85rem;color:var(--text-muted)">
            ยังไม่มีบัญชี? <a href="register.php" style="color:var(--primary);font-weight:600">สมัครสมาชิก</a>
        </p>

        <!-- Footer Info -->
        <div style="margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid var(--border); text-align: center;">
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0 0 0.4rem 0; line-height: 1.6;">
                &copy; 2026 ศูนย์สุขภาพจิตที่ 11. All rights reserved.
            </p>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0 0 0.75rem 0; line-height: 1.6;">
                ระบบนี้มีการจัดเก็บข้อมูลตาม <span style="color: var(--primary); font-weight: 600;">นโยบาย PDPA</span>
            </p>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0; line-height: 1.6;">
                🆘 <strong>แจ้งปัญหา:</strong> พบปัญหาการใช้งานติดต่อ : <span style="color: var(--primary); font-weight: 600;">งานสารสนเทศ</span>
            </p>
        </div>
        
        <div onclick="document.getElementById('version-modal').style.display='flex'" style="text-align: center; margin-top: 1.5rem; font-size: 0.75rem; color: var(--text-muted); cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">
            <?= defined('APP_NAME') ? APP_NAME : 'Task Flow System' ?> - Version <?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?>
        </div>


    </div>
</div>

<div id="version-modal" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem;" onclick="if(event.target==this) this.style.display='none'">
    <div style="background:#fff; border-radius:12px; padding:2rem; max-width:500px; width:100%; text-align:left; display:flex; flex-direction:column; position:relative; animation:modalFadeIn 0.3s ease-out;">
        <button onclick="document.getElementById('version-modal').style.display='none'" style="position:absolute; top:1rem; right:1rem; background:#edf2f7; border:none; width:32px; height:32px; border-radius:50%; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s; color:#000;" onmouseover="this.style.background='var(--status-red)'; this.style.color='#fff'" onmouseout="this.style.background='#edf2f7'; this.style.color='#000'">&times;</button>
        <h2 style="color:var(--primary); margin-top:0; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.5rem;">
            <span style="font-size:1.5rem;">✨</span> <?= defined('APP_NAME') ? APP_NAME : 'Task Flow System' ?>
        </h2>
        <div style="background:rgba(111,53,165,0.1); color:var(--primary); padding:0.25rem 0.75rem; border-radius:50px; display:inline-block; font-weight:700; font-size:0.85rem; margin-bottom:1.5rem; align-self:flex-start;">
            Version <?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?>
        </div>
        
        <h4 style="border-bottom:2px solid var(--border); padding-bottom:0.5rem; margin-bottom:1rem; color:var(--text-main);">รายละเอียดระบบ</h4>
        <ul style="color:var(--text-muted); line-height:1.6; padding-left:1.2rem; margin-bottom:1.5rem;">
            <li>ระบบจัดการโครงการและกิจกรรม (Project & Activity Management)</li>
            <li>แดชบอร์ดสรุปผลแบบเรียลไทม์ (Real-time Dashboard)</li>
            <li>ระบบเบิกจ่ายงบประมาณ (Budget Management)</li>
            <li>การจัดการไฟล์แนบและแกลลอรี่รูปภาพ (File & Gallery System)</li>
            <li>ระบบจัดการผู้ใช้งานและสิทธิ์การเข้าถึง (Role-based Access Control)</li>
        </ul>
        
        <div style="font-size:0.8rem; color:var(--text-muted); text-align:center; border-top:1px solid var(--border); padding-top:1rem;">
            &copy; <script>document.write(new Date().getFullYear())</script> <?= defined('APP_NAME') ? APP_NAME : 'Task Flow System' ?>. All rights reserved.
        </div>
    </div>
</div>
<style>
@keyframes modalFadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
</style>
</body>
</html>

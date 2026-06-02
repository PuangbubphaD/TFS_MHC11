<?php
require_once __DIR__ . '/includes/header.php';

$error = $success = '';
$user_id = $_SESSION['user_id'];

// Fetch current user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

if (!$u) {
    header('Location: logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $action_type = $_POST['action_type'] ?? '';

    if ($action_type === 'update_profile') {
        $full_name  = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');
        $discord_webhook_url = trim($_POST['discord_webhook_url'] ?? '');
        $line_notify_token = trim($_POST['line_notify_token'] ?? '');

        if (!$full_name) {
            $error = 'กรุณากรอกชื่อ-นามสกุล';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, department = ?, telegram_chat_id = ?, discord_webhook_url = ?, line_notify_token = ? WHERE id = ?");
                $stmt->execute([$full_name, $department, $telegram_chat_id ?: null, $discord_webhook_url ?: null, $line_notify_token ?: null, $user_id]);

                // Update session
                $_SESSION['user_name'] = $full_name;
                $success = 'อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว';
                
                logActivity($pdo, 'UPDATE_PROFILE', 'แก้ไขข้อมูลส่วนตัว');

                // Refresh data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $u = $stmt->fetch();
                $name = $u['full_name'];
                $initials = mb_substr($name, 0, 1, 'UTF-8');
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    } elseif ($action_type === 'change_password') {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd     = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';

        if (!$current_pwd || !$new_pwd || !$confirm_pwd) {
            $error = 'กรุณากรอกข้อมูลรหัสผ่านให้ครบถ้วน';
        } elseif ($new_pwd !== $confirm_pwd) {
            $error = 'รหัสผ่านใหม่ไม่ตรงกัน';
        } elseif (strlen($new_pwd) < 6) {
            $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif (!password_verify($current_pwd, $u['password'])) {
            $error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } else {
            try {
                $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $user_id]);
                
                $success = 'เปลี่ยนรหัสผ่านใหม่เรียบร้อยแล้ว';
                logActivity($pdo, 'CHANGE_PASSWORD', 'เปลี่ยนรหัสผ่านส่วนตัว');
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>👤 ข้อมูลส่วนตัว</h2>
            <div class="topbar-breadcrumb">ตั้งค่าโปรไฟล์และรหัสผ่านส่วนตัว</div>
        </div>
    </div>
</div>

<div class="page-content">
    <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-2" style="align-items:start">
        <!-- Profile Form -->
        <div class="card fade-in">
            <div class="card-header">
                <h3>📝 แก้ไขข้อมูลโปรไฟล์</h3>
            </div>
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="action_type" value="update_profile">
                
                <div class="form-group">
                    <label>ชื่อผู้ใช้ (Username)</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($u['username']) ?>" readonly style="background:#e2e8f0;cursor:not-allowed">
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล <span class="required">*</span></label>
                    <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($u['full_name']) ?>">
                </div>
                <div class="form-group">
                    <label>แผนก/กลุ่มงาน</label>
                    <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($u['department'] ?? '') ?>">
                </div>
                
                <h4 style="margin-top: 1.5rem; margin-bottom: 0.75rem; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 0.3rem; font-size: 1rem; font-weight: 700;">🔔 ตั้งค่าการแจ้งเตือนส่วนบุคคล</h4>
                <div class="form-group">
                    <label>Telegram Chat ID</label>
                    <input type="text" name="telegram_chat_id" class="form-control" value="<?= htmlspecialchars($u['telegram_chat_id'] ?? '') ?>" placeholder="ตัวเลข Chat ID (เช่น 123456789)">
                    <small style="color: var(--text-muted); display: block; margin-top: 0.25rem; font-size: 0.75rem;">ทักแชทบอทของระบบเพื่อรับ Chat ID แล้วนำมาป้อนที่นี่เพื่อรับแจ้งเตือนส่วนตัว</small>
                </div>
                <div class="form-group">
                    <label>Discord Webhook URL</label>
                    <input type="text" name="discord_webhook_url" class="form-control" value="<?= htmlspecialchars($u['discord_webhook_url'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                    <small style="color: var(--text-muted); display: block; margin-top: 0.25rem; font-size: 0.75rem;">สร้าง Webhook ใน Discord Channel ส่วนตัวเพื่อรับข้อมูลแจ้งเตือนตรง</small>
                </div>
                <div class="form-group">
                    <label>LINE Notify Token (เตรียมความพร้อมในอนาคต)</label>
                    <input type="text" name="line_notify_token" class="form-control" value="<?= htmlspecialchars($u['line_notify_token'] ?? '') ?>" placeholder="LINE Notify Token">
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top:1rem">💾 บันทึกการเปลี่ยนแปลง</button>
            </form>
        </div>

        <!-- Change Password Form -->
        <div class="card fade-in">
            <div class="card-header">
                <h3>🔑 เปลี่ยนรหัสผ่านใหม่</h3>
            </div>
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="action_type" value="change_password">
                
                <div class="form-group">
                    <label>รหัสผ่านปัจจุบัน <span class="required">*</span></label>
                    <input type="password" name="current_password" class="form-control" placeholder="ระบุรหัสปัจจุบัน" required>
                </div>
                <div class="form-group">
                    <label>รหัสผ่านใหม่ <span class="required">*</span></label>
                    <input type="password" name="new_password" class="form-control" placeholder="อย่างน้อย 6 ตัวอักษร" required>
                </div>
                <div class="form-group">
                    <label>ยืนยันรหัสผ่านใหม่ <span class="required">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" required>
                </div>
                
                <button type="submit" class="btn btn-accent" style="margin-top:1rem">🔑 อัปเดตรหัสผ่าน</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

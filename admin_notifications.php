<?php
require_once __DIR__ . '/includes/header.php';

// Access check: Only Admin can manage notification settings
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">⚠️ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    
    $bot_token = trim($_POST['telegram_bot_token'] ?? '');
    $group_id  = trim($_POST['telegram_group_chat_id'] ?? '');
    $webhook   = trim($_POST['discord_group_webhook'] ?? '');
    
    try {
        $keys = [
            'telegram_bot_token' => $bot_token,
            'telegram_group_chat_id' => $group_id,
            'discord_group_webhook' => $webhook
        ];
        
        foreach ($keys as $k => $v) {
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$v, $k]);
        }
        
        $success = 'บันทึกการตั้งค่าระบบแจ้งเตือนเรียบร้อยแล้ว';
        logActivity($pdo, 'UPDATE_NOTIFICATION_SETTINGS', 'แก้ไขการตั้งค่าแจ้งเตือนกลุ่มส่วนกลาง');
    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Fetch current settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = 'ดึงข้อมูลล้มเหลว: ' . $e->getMessage();
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>🔔 ตั้งค่าระบบแจ้งเตือน</h2>
            <div class="topbar-breadcrumb">จัดการ Token และห้องแชทสำหรับการส่งแจ้งเตือนกลุ่มกลาง</div>
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
        <div class="card fade-in">
            <div class="card-header">
                <h3>📢 คอนฟิกกลุ่มแจ้งเตือนกลาง (Group Channels)</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                
                <h4 style="color:var(--primary);margin-bottom:0.75rem">Telegram Bot & Group</h4>
                <div class="form-group">
                    <label>Telegram Bot Token</label>
                    <input type="text" name="telegram_bot_token" class="form-control" 
                           value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>" placeholder="ตัวอย่าง: 123456:ABC-DEF...">
                </div>
                <div class="form-group">
                    <label>Telegram Group Chat ID</label>
                    <input type="text" name="telegram_group_chat_id" class="form-control" 
                           value="<?= htmlspecialchars($settings['telegram_group_chat_id'] ?? '') ?>" placeholder="ตัวอย่าง: -1001234567890">
                </div>

                <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

                <h4 style="color:var(--primary);margin-bottom:0.75rem">Discord Webhook</h4>
                <div class="form-group">
                    <label>Discord Group Webhook URL</label>
                    <input type="text" name="discord_group_webhook" class="form-control" 
                           value="<?= htmlspecialchars($settings['discord_group_webhook'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top:1rem">💾 บันทึกการตั้งค่า</button>
            </form>
        </div>
        
        <div class="card fade-in" style="background:#f7fafc">
            <h3 style="margin-bottom:0.75rem">💡 วิธีการตั้งค่ารับการเตือน</h3>
            <div style="font-size:0.875rem;line-height:1.7;color:var(--text-muted)">
                <p><strong>1. Telegram:</strong></p>
                <ul style="padding-left:1.25rem;margin-bottom:1rem">
                    <li>สร้าง Bot ใหม่ผ่าน <a href="https://t.me/BotFather" target="_blank">@BotFather</a> เพื่อรับ <strong>Bot Token</strong></li>
                    <li>เชิญ Bot เข้าไปในกลุ่มห้องปฏิบัติงาน</li>
                    <li>หาไอดีกลุ่ม (Group ID) เช่น ส่งข้อความหาบอทแล้วเปิด <code>https://api.telegram.org/bot&lt;Token&gt;/getUpdates</code> เพื่อดู <code>"chat":{"id": -XXXXXXXXX}</code> แล้วนำมากรอกด้านซ้าย</li>
                </ul>
                <p><strong>2. Discord:</strong></p>
                <ul style="padding-left:1.25rem">
                    <li>ไปที่ช่องแชท (Channel) ใน Discord Server ของท่าน -> Edit Channel -> Integrations -> Create Webhook</li>
                    <li>คัดลอก URL ของ Webhook มากรอกที่ช่อง <strong>Discord Group Webhook URL</strong></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

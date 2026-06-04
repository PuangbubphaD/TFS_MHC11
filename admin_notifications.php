<?php
require_once __DIR__ . '/includes/header.php';

// Access check: Only Admin can manage notification settings
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">⚠️ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$error = $success = '';

// Fetch admin's personal settings for testing personal alerts
$user_id = $_SESSION['user_id'];
$my_telegram_chat_id = '';
$my_discord_webhook_url = '';
try {
    $stmt = $pdo->prepare("SELECT telegram_chat_id, discord_webhook_url FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $me = $stmt->fetch();
    $my_telegram_chat_id = $me['telegram_chat_id'] ?? '';
    $my_discord_webhook_url = $me['discord_webhook_url'] ?? '';
} catch (Exception $e) {
    // ignore
}

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
                    <input type="text" id="telegram_bot_token" name="telegram_bot_token" class="form-control" 
                           value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>" placeholder="ตัวอย่าง: 123456:ABC-DEF...">
                </div>
                <div class="form-group">
                    <label>Telegram Group Chat ID</label>
                    <input type="text" id="telegram_group_chat_id" name="telegram_group_chat_id" class="form-control" 
                           value="<?= htmlspecialchars($settings['telegram_group_chat_id'] ?? '') ?>" placeholder="ตัวอย่าง: -1001234567890">
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="testNotification('telegram')">🧪 ทดสอบเชื่อมต่อ Telegram</button>

                <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

                <h4 style="color:var(--primary);margin-bottom:0.75rem">Discord Webhook</h4>
                <div class="form-group">
                    <label>Discord Group Webhook URL</label>
                    <input type="text" id="discord_group_webhook" name="discord_group_webhook" class="form-control" 
                           value="<?= htmlspecialchars($settings['discord_group_webhook'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="testNotification('discord')">🧪 ทดสอบเชื่อมต่อ Discord</button>
                
                <div style="margin-top:2rem; display:flex; gap:1rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกการตั้งค่า</button>
                </div>
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
        
        <!-- Simulated Event Testing Card -->
        <div class="card fade-in" style="margin-top:2rem; grid-column: span 2;">
            <div class="card-header">
                <h3>🧪 ทดลองส่งข้อความจำลองแต่ละเหตุการณ์ (Simulate Notification Events)</h3>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.5rem">
                คุณสามารถจำลองการส่งข้อความแจ้งเตือนตามเหตุการณ์จริง (เตือนล่วงหน้า 7 วัน / ครบกำหนดวันนี้ / เกินกำหนดส่ง) 
                เพื่อทดสอบการแสดงผลทางรูปแบบตัวอักษรและการเน้นคำในแต่ละช่องทางได้ที่นี่
            </p>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem">
                <!-- Telegram Test Panel -->
                <div style="background:#f8fafc; padding:1.25rem; border-radius:8px; border:1px solid var(--border)">
                    <h4 style="color:var(--primary); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem">
                        <span>💬</span> Telegram Event Simulation
                    </h4>
                    <div class="form-group">
                        <label>เลือกเหตุการณ์ที่จะส่ง</label>
                        <select id="sim_tg_event" class="form-control">
                            <option value="reminder">⏳ เตือนล่วงหน้า 7 วันทำการ</option>
                            <option value="due_today">⚠️ ครบกำหนดส่งวันนี้</option>
                            <option value="overdue">🔴 เลยกำหนดส่ง (Overdue)</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:0.5rem; margin-top:1.2rem">
                        <button type="button" class="btn btn-outline btn-sm" onclick="simulateNotification('telegram', 'personal')">ส่งหาแชทส่วนตัวแอดมิน</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="simulateNotification('telegram', 'group')">ส่งเข้าแชทกลุ่มหลัก</button>
                    </div>
                </div>

                <!-- Discord Test Panel -->
                <div style="background:#f8fafc; padding:1.25rem; border-radius:8px; border:1px solid var(--border)">
                    <h4 style="color:var(--primary); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem">
                        <span>💬</span> Discord Event Simulation
                    </h4>
                    <div class="form-group">
                        <label>เลือกเหตุการณ์ที่จะส่ง</label>
                        <select id="sim_dc_event" class="form-control">
                            <option value="reminder">⏳ เตือนล่วงหน้า 7 วันทำการ</option>
                            <option value="due_today">⚠️ ครบกำหนดส่งวันนี้</option>
                            <option value="overdue">🔴 เลยกำหนดส่ง (Overdue)</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:0.5rem; margin-top:1.2rem">
                        <button type="button" class="btn btn-outline btn-sm" onclick="simulateNotification('discord', 'personal')">ส่งหาแชทส่วนตัวแอดมิน</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="simulateNotification('discord', 'group')">ส่งเข้าแชทกลุ่มหลัก</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testNotification(platform) {
    const csrfToken = "<?= $_SESSION['csrf_token'] ?? '' ?>";
    const data = new FormData();
    data.append('csrf_token', csrfToken);
    data.append('type', platform);
    data.append('target', 'group');
    
    if (platform === 'telegram') {
        data.append('telegram_bot_token', document.getElementById('telegram_bot_token').value);
        data.append('telegram_chat_id', document.getElementById('telegram_group_chat_id').value);
    } else if (platform === 'discord') {
        data.append('discord_webhook_url', document.getElementById('discord_group_webhook').value);
    }
    
    const originalBtnText = event.target.innerText;
    const btn = event.target;
    btn.innerText = "⏳ กำลังทดสอบ...";
    btn.disabled = true;
    
    fetch('test_notification.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(result => {
        alert(result.message);
    })
    .catch(error => {
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
        console.error(error);
    })
    .finally(() => {
        btn.innerText = originalBtnText;
        btn.disabled = false;
    });
}

function simulateNotification(platform, target) {
    const csrfToken = "<?= $_SESSION['csrf_token'] ?? '' ?>";
    const data = new FormData();
    data.append('csrf_token', csrfToken);
    data.append('type', platform);
    data.append('target', target);
    
    let eventName = '';
    if (platform === 'telegram') {
        eventName = document.getElementById('sim_tg_event').value;
    } else {
        eventName = document.getElementById('sim_dc_event').value;
    }
    data.append('event', eventName);
    
    // Check credentials depending on target
    if (platform === 'telegram') {
        const botToken = document.getElementById('telegram_bot_token').value;
        if (!botToken) {
            alert('กรุณากรอก Telegram Bot Token ก่อนทดสอบจำลองเหตุการณ์');
            return;
        }
        data.append('telegram_bot_token', botToken);
        
        if (target === 'group') {
            const groupChatId = document.getElementById('telegram_group_chat_id').value;
            if (!groupChatId) {
                alert('กรุณากรอก Telegram Group Chat ID ก่อนทดสอบส่งเข้ากลุ่ม');
                return;
            }
            data.append('telegram_chat_id', groupChatId);
        } else {
            // personal
            const myChatId = "<?= htmlspecialchars($my_telegram_chat_id) ?>";
            if (!myChatId) {
                alert('กรุณาตั้งค่า Telegram Chat ID ส่วนตัวของคุณในหน้า "ข้อมูลส่วนตัว" ก่อนเพื่อรับข้อความทดสอบส่วนบุคคล');
                return;
            }
            data.append('telegram_chat_id', myChatId);
        }
    } else if (platform === 'discord') {
        if (target === 'group') {
            const groupWebhook = document.getElementById('discord_group_webhook').value;
            if (!groupWebhook) {
                alert('กรุณากรอก Discord Group Webhook URL ก่อนทดสอบส่งเข้ากลุ่ม');
                return;
            }
            data.append('discord_webhook_url', groupWebhook);
        } else {
            // personal
            const myWebhook = "<?= htmlspecialchars($my_discord_webhook_url) ?>";
            if (!myWebhook) {
                alert('กรุณาตั้งค่า Discord Webhook ส่วนตัวของคุณในหน้า "ข้อมูลส่วนตัว" ก่อนเพื่อรับข้อความทดสอบส่วนบุคคล');
                return;
            }
            data.append('discord_webhook_url', myWebhook);
        }
    }
    
    const originalBtnText = event.target.innerText;
    const btn = event.target;
    btn.innerText = "⏳ กำลังส่งจำลอง...";
    btn.disabled = true;
    
    fetch('test_notification.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(result => {
        alert(result.message);
    })
    .catch(error => {
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
        console.error(error);
    })
    .finally(() => {
        btn.innerText = originalBtnText;
        btn.disabled = false;
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

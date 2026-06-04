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
$my_discord_user_id = '';
try {
    $stmt = $pdo->prepare("SELECT telegram_chat_id, discord_webhook_url, discord_user_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $me = $stmt->fetch();
    $my_telegram_chat_id = $me['telegram_chat_id'] ?? '';
    $my_discord_webhook_url = $me['discord_webhook_url'] ?? '';
    $my_discord_user_id = $me['discord_user_id'] ?? '';
} catch (Exception $e) {
    // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    
    $bot_token = trim($_POST['telegram_bot_token'] ?? '');
    $group_id  = trim($_POST['telegram_group_chat_id'] ?? '');
    $webhook   = trim($_POST['discord_group_webhook'] ?? '');
    $discord_bot_token = trim($_POST['discord_bot_token'] ?? '');
    $notify_run_time = trim($_POST['notify_run_time'] ?? '08:00');
    
    try {
        $keys = [
            'telegram_bot_token' => $bot_token,
            'telegram_group_chat_id' => $group_id,
            'discord_group_webhook' => $webhook,
            'discord_bot_token' => $discord_bot_token,
            'notify_run_time' => $notify_run_time
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

                <h4 style="color:var(--primary);margin-bottom:0.75rem">Discord Bot & Webhook</h4>
                <div class="form-group">
                    <label>Discord Bot Token <span style="font-size:0.75rem;font-weight:normal;color:#666;">(สำหรับส่ง DM หาส่วนตัว)</span></label>
                    <input type="text" id="discord_bot_token" name="discord_bot_token" class="form-control" 
                           value="<?= htmlspecialchars($settings['discord_bot_token'] ?? '') ?>" placeholder="ตัวอย่าง: MTIzNDU2... (จาก Discord Developer Portal)">
                </div>
                <div class="form-group">
                    <label>Discord Group Webhook URL <span style="font-size:0.75rem;font-weight:normal;color:#666;">(สำหรับส่งเข้าแชนเนลกลุ่ม)</span></label>
                    <input type="text" id="discord_group_webhook" name="discord_group_webhook" class="form-control" 
                           value="<?= htmlspecialchars($settings['discord_group_webhook'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="testNotification('discord')">🧪 ทดสอบเชื่อมต่อ Discord</button>
                
                <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">
                
                <h4 style="color:var(--primary);margin-bottom:0.75rem">ตั้งเวลาแจ้งเตือนรายวัน (Cron Job)</h4>
                <div class="form-group">
                    <label>เวลาที่ต้องการให้รันส่งแจ้งเตือน (HH:MM)</label>
                    <input type="time" name="notify_run_time" class="form-control" style="width: 150px;"
                           value="<?= htmlspecialchars($settings['notify_run_time'] ?? '08:00') ?>">
                    <small style="color:var(--text-muted); display:block; margin-top:0.25rem;">เวลาที่ระบบจะตรวจสอบงานและส่งข้อความอัตโนมัติ (ขึ้นอยู่กับการตั้งค่า Server Docker ให้รัน check_and_notify.php ทุกนาทีด้วย)</small>
                </div>

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
                    <li><strong>สำหรับกลุ่ม (Webhook):</strong> ไปที่ช่องแชท (Channel) ใน Discord Server ของท่าน -> Edit Channel -> Integrations -> Create Webhook แล้วนำ URL มาใส่ด้านซ้าย</li>
                    <li><strong>สำหรับ DM ส่วนตัว (Bot):</strong> ต้องสร้าง Bot ใน <a href="https://discord.com/developers/applications" target="_blank">Discord Developer Portal</a> นำ <strong>Bot Token</strong> มากรอกที่ด้านซ้าย และสมาชิกต้องอยู่ใน Server เดียวกับ Bot พร้อมทั้งกรอก <strong>Discord User ID</strong> ในหน้าตั้งค่าส่วนตัว</li>
                </ul>
            </div>
        </div>
            </div>
        </div>
        
        <!-- ===== SIMULATION PANEL: 8 Phases × 3 Events ===== -->
        <div class="card fade-in" style="margin-top:2rem;">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
                <h3>🧪 ทดสอบการแจ้งเตือน — ทุกขั้นตอน × ทุกเหตุการณ์</h3>
                <div style="display:flex; gap:0.5rem; align-items:center; font-size:0.8rem; color:var(--text-muted)">
                    <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;">💬 TG = Telegram</span>
                    <span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:4px;">🎮 DC = Discord</span>
                    <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;">👤 = ส่วนตัว</span>
                    <span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:4px;">📢 = กลุ่มกลาง</span>
                </div>
            </div>
            <p style="font-size:0.83rem; color:var(--text-muted); margin:0 0 1.5rem 0; padding:0 1.5rem;">
                กดปุ่มเพื่อจำลองส่งข้อความแต่ละเหตุการณ์ของแต่ละขั้นตอน ระบบจะส่งไปยัง Telegram / Discord ตาม Token และ Chat ID / Webhook ที่กรอกไว้ด้านบน
            </p>

            <!-- Channel selector -->
            <div style="padding:0 1.5rem 1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                <strong style="font-size:0.875rem;">ส่งไปยัง:</strong>
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem;">
                    <input type="radio" name="sim_target" value="personal" id="sim_target_personal" checked>
                    <span>👤 แชทส่วนตัว (Admin)</span>
                </label>
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem;">
                    <input type="radio" name="sim_target" value="group" id="sim_target_group">
                    <span>📢 กลุ่มกลาง</span>
                </label>
            </div>

            <div style="overflow-x:auto; padding:0 1.5rem 1.5rem;">
            <table style="width:100%; border-collapse:collapse; font-size:0.825rem; min-width:700px;">
                <thead>
                    <tr style="background:var(--primary); color:#fff;">
                        <th style="padding:10px 14px; text-align:left; border-radius:6px 0 0 0; width:40%">ขั้นตอน</th>
                        <th style="padding:10px 8px; text-align:center;">⏳ ล่วงหน้า 7 วัน</th>
                        <th style="padding:10px 8px; text-align:center;">⚠️ ครบกำหนดวันนี้</th>
                        <th style="padding:10px 8px; text-align:center; border-radius:0 6px 0 0">🔴 เลยกำหนด</th>
                    </tr>
                </thead>
                <tbody id="phaseTestTableBody">
                <?php
                $phases = [
                    1 => 'ขออนุมัติจัดกิจกรรม',
                    2 => 'ขออนุมัติงบประมาณและพัสดุ',
                    3 => 'ขออนุมัติบุคลากรและเชิญผู้ร่วม',
                    4 => 'ดำเนินการจัดกิจกรรม',
                    5 => 'การจัดทำ onepage',
                    6 => 'เบิกจ่ายงบประมาณ',
                    7 => 'สรุปกิจกรรม',
                    8 => 'ผู้อำนวยการลงนามอนุมัติสรุป',
                ];
                $events = [
                    'reminder'  => ['icon' => '⏳', 'label' => 'ล่วงหน้า'],
                    'due_today' => ['icon' => '⚠️', 'label' => 'วันนี้'],
                    'overdue'   => ['icon' => '🔴', 'label' => 'เลยกำหนด'],
                ];
                foreach ($phases as $num => $name):
                    $rowBg = ($num % 2 === 0) ? '#f8fafc' : '#fff';
                ?>
                <tr style="background:<?= $rowBg ?>; border-bottom:1px solid #e5e7eb;">
                    <td style="padding:10px 14px; font-weight:600;">
                        <span style="display:inline-block;background:var(--primary);color:#fff;border-radius:50%;width:22px;height:22px;line-height:22px;text-align:center;font-size:0.75rem;margin-right:6px;"><?= $num ?></span>
                        <?= htmlspecialchars($name) ?>
                        <?php if ($num == 5): ?>
                            <span style="font-size:0.7rem;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;margin-left:4px;">ไม่มีเตือนล่วงหน้า</span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($events as $evKey => $evInfo): ?>
                    <td style="padding:8px; text-align:center;">
                        <?php if ($evKey === 'reminder' && $num == 5): ?>
                            <span style="color:#cbd5e1;font-size:0.75rem;">—</span>
                        <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                            <button type="button"
                                onclick="simPhase('telegram', <?= $num ?>, '<?= $evKey ?>', this)"
                                style="background:#dbeafe;color:#1e40af;border:none;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.75rem;transition:opacity .15s;"
                                title="ทดสอบผ่าน Telegram">
                                💬 TG
                            </button>
                            <button type="button"
                                onclick="simPhase('discord', <?= $num ?>, '<?= $evKey ?>', this)"
                                style="background:#ede9fe;color:#6d28d9;border:none;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.75rem;transition:opacity .15s;"
                                title="ทดสอบผ่าน Discord">
                                🎮 DC
                            </button>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Status bar -->
            <div id="simStatusBar" style="display:none; margin:0 1.5rem 1.5rem; padding:12px 16px; border-radius:6px; font-size:0.875rem; font-weight:500;"></div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = "<?= $_SESSION['csrf_token'] ?? '' ?>";
const MY_TG_CHAT  = "<?= htmlspecialchars($my_telegram_chat_id) ?>";
const MY_DC_WH    = "<?= htmlspecialchars($my_discord_webhook_url) ?>";
const MY_DC_USER  = "<?= htmlspecialchars($my_discord_user_id) ?>";

function showSimStatus(success, msg) {
    const bar = document.getElementById('simStatusBar');
    bar.style.display = 'block';
    bar.style.background = success ? '#dcfce7' : '#fee2e2';
    bar.style.color       = success ? '#166534' : '#991b1b';
    bar.textContent = (success ? '✅ ' : '❌ ') + msg;
    setTimeout(() => { bar.style.display = 'none'; }, 5000);
}

function simPhase(platform, phaseNum, eventKey, btn) {
    const target = document.querySelector('input[name="sim_target"]:checked').value;
    const data = new FormData();
    data.append('csrf_token', CSRF_TOKEN);
    data.append('type', platform);
    data.append('target', target);
    data.append('event', eventKey);
    data.append('phase', phaseNum);

    if (platform === 'telegram') {
        const botToken = document.getElementById('telegram_bot_token').value.trim();
        if (!botToken) { showSimStatus(false, 'กรุณากรอก Telegram Bot Token ก่อนทดสอบ'); return; }
        data.append('telegram_bot_token', botToken);

        if (target === 'group') {
            const gid = document.getElementById('telegram_group_chat_id').value.trim();
            if (!gid) { showSimStatus(false, 'กรุณากรอก Telegram Group Chat ID ก่อนทดสอบ'); return; }
            data.append('telegram_chat_id', gid);
        } else {
            if (!MY_TG_CHAT) { showSimStatus(false, 'กรุณาตั้งค่า Telegram Chat ID ส่วนตัวในหน้าข้อมูลส่วนตัวก่อน'); return; }
            data.append('telegram_chat_id', MY_TG_CHAT);
        }
    } else {
        if (target === 'group') {
            const wh = document.getElementById('discord_group_webhook').value.trim();
            if (!wh) { showSimStatus(false, 'กรุณากรอก Discord Webhook URL ก่อนทดสอบ'); return; }
            data.append('discord_webhook_url', wh);
        } else {
            const dcBotToken = document.getElementById('discord_bot_token').value.trim();
            if (!dcBotToken) { showSimStatus(false, 'กรุณากรอก Discord Bot Token ก่อนทดสอบส่ง DM'); return; }
            data.append('discord_bot_token', dcBotToken);
            
            // Check if admin has discord_user_id (We fetch it from my_discord_user_id variable which we need to define)
            if (typeof MY_DC_USER === 'undefined' || !MY_DC_USER) { showSimStatus(false, 'กรุณาตั้งค่า Discord User ID ส่วนตัวในหน้าข้อมูลส่วนตัวก่อน'); return; }
            data.append('discord_user_id', MY_DC_USER);
        }
    }

    const orig = btn.textContent;
    btn.textContent = '⏳';
    btn.disabled = true;

    fetch('test_notification.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => showSimStatus(res.success, res.message))
        .catch(() => showSimStatus(false, 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์'))
        .finally(() => { btn.textContent = orig; btn.disabled = false; });
}

// Keep original simple test function for the top buttons
function testNotification(platform) {
    const data = new FormData();
    data.append('csrf_token', CSRF_TOKEN);
    data.append('type', platform);
    data.append('target', 'group');
    
    if (platform === 'telegram') {
        data.append('telegram_bot_token', document.getElementById('telegram_bot_token').value);
        data.append('telegram_chat_id', document.getElementById('telegram_group_chat_id').value);
    } else {
        data.append('discord_webhook_url', document.getElementById('discord_group_webhook').value);
    }
    
    const btn = event.target;
    const orig = btn.innerText;
    btn.innerText = '⏳ กำลังทดสอบ...';
    btn.disabled = true;

    fetch('test_notification.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => alert(res.message))
        .catch(() => alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์'))
        .finally(() => { btn.innerText = orig; btn.disabled = false; });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


<?php
ob_start();

// Set secure session cookie parameters to prevent XSS session stealing and CSRF
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

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

// Load public holidays globally
$global_holidays = [];
try {
    $stmt = $pdo->query("SELECT holiday_date FROM holidays");
    $global_holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $global_holidays = [];
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$role = $_SESSION['role'];
$name = $_SESSION['user_name'];
$initials = mb_substr($name, 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TFS - Task Flow System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="assets/js/main.js?v=<?= time() ?>" defer></script>
    <style>
        #lightbox { display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center; cursor:default; padding:2rem; }
        .modal-box { background:#fff; width:90%; height:90%; border-radius:12px; display:flex; flex-direction:column; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden; animation:modalUp 0.3s ease-out; }
        @keyframes modalUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
        .modal-header { padding:1rem 1.5rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; }
        .modal-header h3 { margin:0; font-size:1.1rem; color:var(--primary); }
        .modal-body { flex:1; display:flex; align-items:center; justify-content:center; background:#f1f5f9; padding:1rem; overflow:auto; }
        #lightbox img { max-width:100%; max-height:100%; border-radius:4px; box-shadow:0 5px 15px rgba(0,0,0,0.1); object-fit:contain; background:#fff; }
        #lightbox iframe { width:100%; height:100%; border:none; background:#fff; }
        .close-btn { background:#edf2f7; border:none; width:32px; height:32px; border-radius:50%; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s; }
        .close-btn:hover { background:var(--status-red); color:#fff; }
        .img-preview { width:50px; height:50px; object-fit:cover; border-radius:6px; cursor:pointer; border:1px solid var(--border); transition:0.2s; margin-right:8px; vertical-align:middle; }
        .img-preview:hover { transform:scale(1.1); border-color:var(--primary); }
    </style>
    <script>
        function openPopup(src, type) {
            const lb = document.getElementById('lightbox');
            const body = document.getElementById('modal-body');
            const title = document.getElementById('modal-title');
            
            const fileName = src.split('/').pop();
            title.innerText = fileName;
            body.innerHTML = '';
            
            if (type === 'image') {
                body.innerHTML = `<img src="${src}" alt="Preview">`;
            } else if (type === 'pdf') {
                body.innerHTML = `<iframe src="${src}"></iframe>`;
            } else {
                body.innerHTML = `<div style="text-align:center;padding:2rem;background:#fff;border-radius:12px">
                    <div style="font-size:3rem;margin-bottom:1rem">📎</div>
                    <h3>${fileName}</h3>
                    <p style="color:#64748b;margin-bottom:1.5rem">ไฟล์นี้ไม่สามารถแสดงตัวอย่างได้ กรุณาดาวน์โหลดเพื่อเปิดดู</p>
                    <a href="${src}" class="btn btn-primary" download>📥 ดาวน์โหลดไฟล์</a>
                </div>`;
            }
            lb.style.display = 'flex';
        }
        function showVersionPopup() {
            const lb = document.getElementById('lightbox');
            const body = document.getElementById('modal-body');
            const title = document.getElementById('modal-title');
            
            title.innerText = 'รายละเอียดเวอร์ชัน';
            body.innerHTML = `
                <div style="background:#fff; border-radius:12px; padding:2rem; max-width:600px; width:100%; text-align:left; display:flex; flex-direction:column;">
                    <h2 style="color:var(--primary); margin-top:0; display:flex; align-items:center; gap:0.5rem;">
                        <span style="font-size:1.5rem;">✨</span> <?= defined('APP_NAME') ? APP_NAME : 'Task Flow System' ?>
                    </h2>
                    <div style="background:rgba(111,53,165,0.1); color:var(--primary); padding:0.25rem 0.75rem; border-radius:50px; display:inline-block; font-weight:700; font-size:0.85rem; margin-bottom:1.5rem; align-self:flex-start;">
                        Version <?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?>
                    </div>
                    
                    <h4 style="border-bottom:2px solid var(--border); padding-bottom:0.5rem; margin-bottom:1rem; color:var(--text-main);">รายละเอียดระบบ Version 1.0.0</h4>
                    <ul style="color:var(--text-muted); line-height:1.8; padding-left:1.2rem; margin-bottom:1.5rem; font-size:0.9rem;">
                        <li>📋 ระบบจัดการโครงการและกิจกรรม (Project &amp; Activity Management)</li>
                        <li>📊 แดชบอร์ดสรุปผลแบบเรียลไทม์ (Real-time Dashboard)</li>
                        <li>💰 ระบบติดตามงบประมาณและการเบิกจ่าย (Budget Tracking)</li>
                        <li>📅 ระบบปีงบประมาณไทย พร้อม Filter ทุกหน้า (Fiscal Year System)</li>
                        <li>🖼️ การจัดการไฟล์แนบและแกลลอรี่รูปภาพ (File &amp; Gallery)</li>
                        <li>👥 ระบบจัดการผู้ใช้งานและสิทธิ์การเข้าถึง (Role-based Access)</li>
                        <li>🔔 ระบบแจ้งเตือนอัตโนมัติ Telegram &amp; Discord (Auto Notifications)</li>
                        <li>📅 รองรับปฏิทินวันหยุดราชการไทย (Thai Public Holidays)</li>
                        <li>📄 รายงานสรุปโครงการแบบพิมพ์ได้ (Printable Reports)</li>
                        <li>📌 ระบบขั้นตอนกิจกรรม 8 ขั้นตอน พร้อมกำหนดการ (Phase Tracking)</li>
                    </ul>
                    
                    <div style="font-size:0.8rem; color:var(--text-muted); text-align:center; border-top:1px solid var(--border); padding-top:1rem; margin-top:auto;">
                        &copy; ${new Date().getFullYear()} <?= defined('APP_NAME') ? APP_NAME : 'Task Flow System' ?>. All rights reserved.
                    </div>
                </div>
            `;
            lb.style.display = 'flex';
        }
        function closePopup() {
            document.getElementById('lightbox').style.display = 'none';
        }
    </script>
</head>
<body>
    <div id="lightbox" onclick="if(event.target==this) closePopup()">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modal-title">File Preview</h3>
                <button class="close-btn" onclick="closePopup()">&times;</button>
            </div>
            <div class="modal-body" id="modal-body"></div>
        </div>
    </div>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo" style="padding: 1rem 1.25rem; display: flex; justify-content: center; align-items: center; background: rgba(255,255,255,0.03);">
        <img src="assets/images/logo-light.png" alt="TFS Logo" style="max-width: 100%; max-height: 75px; border-radius: 8px; object-fit: contain;">
    </div>
    <div class="sidebar-user">
        <div class="user-avatar"><?= $initials ?></div>
        <div class="sidebar-user-info">
            <div class="name"><?= htmlspecialchars($name) ?></div>
            <div class="role-badge">
                <?php
                $roleLabels = ['staff'=>'เจ้าหน้าที่','head'=>'หัวหน้างาน','director'=>'ผู้อำนวยการ','admin'=>'ผู้ดูแลระบบ (Admin)'];
                echo $roleLabels[$role] ?? $role;
                ?>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">เมนูหลัก</div>
        <?php if ($role !== 'director'): ?>
        <a href="index.php" class="<?= $current_page === 'index' ? 'active' : '' ?>">
            <span class="icon">🏠</span> โครงการของฉัน
        </a>
        <?php endif; ?>
        <a href="activities.php" class="<?= $current_page === 'activities' ? 'active' : '' ?>">
            <span class="icon">📌</span> กิจกรรม
        </a>
        <a href="gallery.php" class="<?= $current_page === 'gallery' ? 'active' : '' ?>">
            <span class="icon">🖼️</span> ประมวลภาพ
        </a>
        <?php if ($role !== 'director'): ?>
        <a href="add_project.php" class="<?= $current_page === 'add_project' ? 'active' : '' ?>">
            <span class="icon">➕</span> เพิ่มโครงการใหม่
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['head', 'director', 'admin'])): ?>
        <div class="nav-section">ผู้บริหาร</div>
        <a href="dashboard.php" class="<?= $current_page === 'dashboard' ? 'active' : '' ?>">
            <span class="icon">📊</span> Dashboard ภาพรวม
        </a>
        <a href="all_projects.php" class="<?= $current_page === 'all_projects' ? 'active' : '' ?>">
            <span class="icon">📋</span> โครงการทั้งหมด
        </a>
        <?php if ($role === 'admin'): ?>
        <a href="users.php" class="<?= $current_page === 'users' ? 'active' : '' ?>">
            <span class="icon">👥</span> จัดการสมาชิก
        </a>
        <a href="admin_notifications.php" class="<?= $current_page === 'admin_notifications' ? 'active' : '' ?>">
            <span class="icon">🔔</span> ตั้งค่าแจ้งเตือนระบบ
        </a>
        <a href="sync_holidays.php" class="<?= $current_page === 'sync_holidays' ? 'active' : '' ?>">
            <span class="icon">📅</span> จัดการวันหยุดและ API
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <div class="nav-section">ตั้งค่า</div>
        <a href="profile.php" class="<?= $current_page === 'profile' ? 'active' : '' ?>">
            <span class="icon">👤</span> ข้อมูลส่วนตัว
        </a>
        <?php if ($role === 'admin'): ?>
        <a href="logs.php" class="<?= $current_page === 'logs' ? 'active' : '' ?>">
            <span class="icon">📜</span> ประวัติการใช้งาน
        </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">🚪 ออกจากระบบ</a>
        <div class="sidebar-version" onclick="showVersionPopup()" style="cursor: pointer; font-size: 0.75rem; color: rgba(255,255,255,0.5); text-align: center; margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1rem; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.5)'">
            <?= defined('APP_NAME') ? APP_NAME : 'TrackPro' ?><br>
            Version <?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main-content">

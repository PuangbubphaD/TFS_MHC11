<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['thaid_profile_confirm'])) {
    header("Location: login.php");
    exit;
}

$confirmData = $_SESSION['thaid_profile_confirm'];

// Check expiry
if (time() > $confirmData['expires_at']) {
    unset($_SESSION['thaid_profile_confirm']);
    header("Location: login.php?error=thaid_invalid_request");
    exit;
}

$proposal = $confirmData['proposal'];
$userInfo = $confirmData['userInfo'];
$user_id = $confirmData['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // Fetch fresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            header("Location: login.php?error=thaid_no_account");
            exit;
        }

        if ($action === 'accept') {
            // Layer 3: Overwrite name and sync other fields
            thaid_apply_name_overwrite($pdo, $user, $userInfo);
        } elseif ($action === 'decline') {
            // Layer 2: Sync other fields but keep existing name
            thaid_sync_profile($pdo, $user, $userInfo);
        } else {
            throw new Exception("Invalid action");
        }
        
        // Refresh user data after sync
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        // Login
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_name'] = thaid_get_display_name($user);
        $_SESSION['full_name'] = thaid_get_display_name($user);
        $_SESSION['department'] = $user['department'];

        $logStmt = $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'login', ?, ?)");
        $logStmt->execute([
            $user['id'], 
            'Logged in via ThaiD (Name Overwrite: ' . $action . ')', 
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        unset($_SESSION['thaid_profile_confirm']);
        header("Location: index.php");
        exit;
        
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ยืนยันข้อมูลโปรไฟล์ (ThaiD) - <?= defined('APP_NAME') ? APP_NAME : 'TFS' ?></title>
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
        .confirm-container {
            background: #fff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 600px;
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 0.875rem;
        }
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        .comparison-table th, .comparison-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        .comparison-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }
        .highlight {
            color: #b91c1c;
            font-weight: 600;
        }
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .btn {
            flex: 1;
            padding: 0.75rem;
            font-size: 1rem;
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

<div class="confirm-container">
    <div class="header">
        <div style="text-align: center;">
            <span class="thaid-badge">✓ พบข้อมูลตรงกับบัญชีเดิม (<?= htmlspecialchars($proposal['pid_masked']) ?>)</span>
        </div>
        <h2>ยืนยันการเปลี่ยนแปลงชื่อ-นามสกุล</h2>
        <p style="color:var(--text-muted); font-size:0.95rem;">
            ระบบพบว่าชื่อของคุณในระบบ <strong>ไม่ตรงกับข้อมูลในระบบทะเบียนราษฎร์ (ThaiD)</strong>
            <br>คุณต้องการอัปเดตชื่อในระบบให้ตรงกับ ThaiD หรือไม่?
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <table class="comparison-table">
        <thead>
            <tr>
                <th>ข้อมูล</th>
                <th>ชื่อเดิมในระบบ</th>
                <th>ชื่อใหม่จาก ThaiD</th>
            </tr>
        </thead>
        <tbody>
            <?php if (in_array('name', $proposal['fields'])): ?>
            <tr>
                <td><strong>ชื่อ</strong></td>
                <td><?= htmlspecialchars($proposal['current']['name']) ?: '<span style="color:#94a3b8">ไม่ระบุ</span>' ?></td>
                <td class="highlight"><?= htmlspecialchars($proposal['proposed']['name']) ?></td>
            </tr>
            <?php endif; ?>
            
            <?php if (in_array('lastname', $proposal['fields'])): ?>
            <tr>
                <td><strong>นามสกุล</strong></td>
                <td><?= htmlspecialchars($proposal['current']['lastname']) ?: '<span style="color:#94a3b8">ไม่ระบุ</span>' ?></td>
                <td class="highlight"><?= htmlspecialchars($proposal['proposed']['lastname']) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <form method="POST" action="">
        <div class="actions">
            <button type="submit" name="action" value="decline" class="btn btn-outline" style="border:1px solid #cbd5e1; color:#475569; background:#fff">
                ❌ ใช้ชื่อเดิม
            </button>
            <button type="submit" name="action" value="accept" class="btn btn-primary" style="background:#1e3a8a">
                ✅ อัปเดตตาม ThaiD
            </button>
        </div>
    </form>
</div>

</body>
</html>

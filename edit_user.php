<?php
require_once __DIR__ . '/includes/header.php';

// Access check: Only Admin can manage users
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">⚠️ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = intval($_GET['id'] ?? 0);
if (!$user_id) { header('Location: users.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

if (!$u) { header('Location: users.php'); exit; }

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $full_name  = trim($_POST['full_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $role       = $_POST['role'] ?? 'staff';
    $department = trim($_POST['department'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (!$full_name || !$username) {
        $error = 'กรุณากรอกชื่อและชื่อผู้ใช้';
    } else {
        try {
            // Update basic info
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, department=? WHERE id=?");
            $stmt->execute([$full_name, $username, $role, $department, $user_id]);

            // Update password if provided
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $user_id]);
            }

            $success = 'อัปเดตข้อมูลสมาชิกเรียบร้อยแล้ว';
            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $u = $stmt->fetch();
        } catch (Exception $e) {
            $error = 'ไม่สามารถอัปเดตได้: ' . $e->getMessage();
        }
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>✏️ แก้ไขข้อมูลสมาชิก</h2>
        <div class="topbar-breadcrumb"><a href="users.php" style="color:var(--text-muted)">จัดการสมาชิก</a> / <?= htmlspecialchars($u['full_name']) ?></div>
    </div>
    </div>
    <a href="users.php" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <?php if ($error): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card fade-in" style="max-width:600px">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div class="form-group">
                <label>ชื่อ-นามสกุล <span class="required">*</span></label>
                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($u['full_name']) ?>">
            </div>
            <div class="form-group">
                <label>ชื่อผู้ใช้ (Username) <span class="required">*</span></label>
                <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($u['username']) ?>">
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>ตำแหน่ง/สิทธิ์การใช้งาน</label>
                    <select name="role" class="form-control">
                        <option value="staff" <?= $u['role'] === 'staff' ? 'selected' : '' ?>>เจ้าหน้าที่ (Staff)</option>
                        <option value="head" <?= $u['role'] === 'head' ? 'selected' : '' ?>>หัวหน้างาน (Head)</option>
                        <option value="director" <?= $u['role'] === 'director' ? 'selected' : '' ?>>ผู้อำนวยการ (Director)</option>
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>กลุ่มงาน</label>
                    <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($u['department']) ?>">
                </div>
            </div>
            <div class="form-group" style="background:#fff5f5;padding:1rem;border-radius:8px;border:1px solid #feb2b2">
                <label style="color:#c53030">🔑 เปลี่ยนรหัสผ่านใหม่ (เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</label>
                <input type="password" name="password" class="form-control" placeholder="ระบุรหัสผ่านใหม่ที่นี่">
            </div>
            <div style="margin-top:1.5rem">
                <button type="submit" class="btn btn-primary">💾 บันทึกการเปลี่ยนแปลง</button>
                <a href="users.php" class="btn btn-outline">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

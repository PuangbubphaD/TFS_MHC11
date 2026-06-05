<?php
require_once __DIR__ . '/includes/header.php';

// Access check: Only Admin can manage users
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">⚠️ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Handle User Approval
if (isset($_POST['action']) && isset($_POST['user_id'])) {
    checkCsrfOrDie();
    $uid = (int)$_POST['user_id'];
    $action = $_POST['action'];

    if ($action === 'approve') {
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([$uid]);
        $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'approve_user', CONCAT('Approved user ID ', ?), ?)")
            ->execute([$_SESSION['user_id'], $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
        header("Location: users.php?msg=approved"); exit;

    } elseif ($action === 'suspend') {
        $pdo->prepare("UPDATE users SET account_status = 'suspended' WHERE id = ?")->execute([$uid]);
        $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'suspend_user', CONCAT('Suspended user ID ', ?), ?)")
            ->execute([$_SESSION['user_id'], $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
        header("Location: users.php?msg=suspended"); exit;

    } elseif ($action === 'unsuspend') {
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([$uid]);
        $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'unsuspend_user', CONCAT('Unsuspended user ID ', ?), ?)")
            ->execute([$_SESSION['user_id'], $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
        header("Location: users.php?msg=unsuspended"); exit;

    } elseif ($action === 'reject') {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND account_status = 'pending_approval'")->execute([$uid]);
        $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'reject_user', CONCAT('Rejected & deleted user ID ', ?), ?)")
            ->execute([$_SESSION['user_id'], $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
        header("Location: users.php?msg=rejected"); exit;

    } elseif ($action === 'delete') {
        // Cannot delete yourself
        if ($uid !== (int)$_SESSION['user_id']) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'delete_user', CONCAT('Deleted user ID ', ?), ?)")
                ->execute([$_SESSION['user_id'], $uid, $_SERVER['REMOTE_ADDR'] ?? '']);
        }
        header("Location: users.php?msg=deleted"); exit;
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY account_status ASC, role DESC, full_name ASC");
$users = $stmt->fetchAll();

// Message
$msg = '';
if (isset($_GET['msg'])) {
    $msgs = [
        'approved'   => ['type' => 'success', 'text' => '✅ อนุมัติบัญชีผู้ใช้งานเรียบร้อยแล้ว'],
        'suspended'  => ['type' => 'warning', 'text' => '🚫 ระงับการใช้งานบัญชีเรียบร้อยแล้ว'],
        'unsuspended'=> ['type' => 'success', 'text' => '✅ เปิดใช้งานบัญชีเรียบร้อยแล้ว'],
        'rejected'   => ['type' => 'danger',  'text' => '🗑️ ปฏิเสธและลบบัญชีคำขอสมัครแล้ว'],
        'deleted'    => ['type' => 'danger',  'text' => '🗑️ ลบบัญชีผู้ใช้งานออกจากระบบแล้ว'],
    ];
    $msg = $msgs[$_GET['msg']] ?? null;
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>👥 จัดการสมาชิก</h2>
        <div class="topbar-breadcrumb">ระบบจัดการผู้ใช้งานและสิทธิ์การเข้าถึง</div>
    </div>
    </div>
    <a href="register.php" class="btn btn-primary">➕ เพิ่มสมาชิกใหม่</a>
</div>

<div class="page-content">
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom:1rem;padding:1rem;border-radius:8px;
        <?= $msg['type']==='success' ? 'background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;' : '' ?>
        <?= $msg['type']==='warning' ? 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;' : '' ?>
        <?= $msg['type']==='danger'  ? 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;' : '' ?>">
        <?= $msg['text'] ?>
    </div>
    <?php endif; ?>
    <div class="card fade-in">
        <div class="card-header">
            <h3>รายชื่อสมาชิกทั้งหมด (<?= count($users) ?> คน)</h3>
        </div>
        <div class="table-wrap table-responsive-cards">
            <table class="table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>ชื่อผู้ใช้</th>
                        <th>ตำแหน่ง/สิทธิ์</th>
                        <th>กลุ่มงาน</th>
                        <th>สถานะบัญชี</th>
                        <th>เข้าสู่ระบบด้วย</th>
                        <th>วันที่เพิ่ม</th>
                        <th style="text-align:center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $roleColors = ['admin'=>'#805ad5', 'director'=>'#e53e3e', 'head'=>'#3182ce', 'staff'=>'#718096'];
                    $roleLabels = ['admin'=>'ผู้ดูแลระบบ (Admin)', 'director'=>'ผู้อำนวยการ', 'head'=>'หัวหน้างาน', 'staff'=>'เจ้าหน้าที่'];
                    foreach ($users as $idx => $u): 
                    ?>
                    <tr>
                        <td data-label="ลำดับ"><?= $idx + 1 ?></td>
                        <td data-label="ชื่อ-นามสกุล"><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                        <td data-label="ชื่อผู้ใช้"><code><?= htmlspecialchars($u['username']) ?></code></td>
                        <td data-label="ตำแหน่ง/สิทธิ์">
                            <span class="badge" style="background:<?= $roleColors[$u['role']] ?>;color:#fff">
                                <?= $roleLabels[$u['role']] ?>
                            </span>
                        </td>
                        <td data-label="กลุ่มงาน"><?= htmlspecialchars($u['department'] ?: '-') ?></td>
                        <td data-label="สถานะบัญชี">
                            <?php if (isset($u['account_status']) && $u['account_status'] === 'pending_approval'): ?>
                                <span style="color:#d97706;font-weight:600;font-size:0.85rem;">⏳ รออนุมัติ</span>
                            <?php elseif (isset($u['account_status']) && $u['account_status'] === 'suspended'): ?>
                                <span style="color:#dc2626;font-weight:600;font-size:0.85rem;">🚫 ระงับการใช้งาน</span>
                            <?php else: ?>
                                <span style="color:#059669;font-weight:600;font-size:0.85rem;">✅ ใช้งานได้ปกติ</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="เข้าสู่ระบบด้วย">
                            <?php if (isset($u['auth_provider']) && $u['auth_provider'] === 'thaid'): ?>
                                <span class="badge" style="background:#1e3a8a;color:#fff;">ThaiD</span>
                            <?php else: ?>
                                <span class="badge" style="background:#718096;color:#fff;">รหัสผ่าน</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="วันที่เพิ่ม" style="font-size:0.85rem;color:var(--text-muted)"><?= thaiDate($u['created_at']) ?></td>
                        <td data-label="จัดการ" style="text-align:center">
                            <div style="display:flex;gap:0.4rem;justify-content:center;flex-wrap:wrap">
                                <?php
                                $status = $u['account_status'] ?? 'active';
                                $isSelf = ($u['id'] == $_SESSION['user_id']);
                                ?>

                                <?php if ($status === 'pending_approval'): ?>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('อนุมัติผู้ใช้รายนี้?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;font-size:0.78rem">✅ อนุมัติ</button>
                                    </form>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('ปฏิเสธและลบคำขอสมัครรายนี้?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;font-size:0.78rem">🗑️ ปฏิเสธ</button>
                                    </form>

                                <?php elseif ($status === 'active' && !$isSelf): ?>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('ระงับการใช้งานบัญชีนี้?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#d97706;color:#fff;font-size:0.78rem">🚫 ระงับ</button>
                                    </form>

                                <?php elseif ($status === 'suspended'): ?>
                                    <form method="POST" style="margin:0" onsubmit="return confirm('เปิดใช้งานบัญชีนี้อีกครั้ง?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="unsuspend">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;font-size:0.78rem">✅ เปิดใช้</button>
                                    </form>
                                <?php endif; ?>

                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-outline btn-sm" style="font-size:0.78rem">✏️ แก้ไข</a>

                                <?php if (!$isSelf): ?>
                                <form method="POST" style="margin:0" onsubmit="return confirm('⚠️ ลบบัญชี \"<?= htmlspecialchars($u[\'full_name\'], ENT_QUOTES) ?>\" ออกจากระบบถาวร? ข้อมูลทั้งหมดจะหายไปเลย')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background:#7f1d1d;color:#fff;font-size:0.78rem">🗑️ ลบ</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

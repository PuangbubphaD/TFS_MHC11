<?php
require_once __DIR__ . '/includes/header.php';

// Access check: Only Admin can manage users
if ($_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">⚠️ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY role DESC, full_name ASC");
$users = $stmt->fetchAll();
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
                        <td data-label="วันที่เพิ่ม" style="font-size:0.85rem;color:var(--text-muted)"><?= thaiDate($u['created_at']) ?></td>
                        <td data-label="จัดการ" style="text-align:center">
                            <div style="display:flex;gap:0.5rem;justify-content:center">
                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-outline btn-sm">✏️ แก้ไข</a>
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

<?php
require_once __DIR__ . '/includes/header.php';

// Restricted to Admin only
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
        <h3>❌ Access Denied</h3>
        <p>หน้านี้จำกัดสิทธิ์เฉพาะผู้ดูแลระบบเท่านั้น</p>
        <p><a href="index.php">กลับสู่หน้าหลัก</a></p>
    </div>');
}

// Filter and Search parameters
$search_action = trim($_GET['action_q'] ?? '');
$filter_user   = intval($_GET['user_id'] ?? 0);

// Build WHERE SQL
$where = ['1=1'];
$params = [];

if ($search_action) {
    $where[] = 'l.action LIKE ?';
    $params[] = "%$search_action%";
}
if ($filter_user) {
    $where[] = 'l.user_id = ?';
    $params[] = $filter_user;
}

$whereSQL = implode(' AND ', $where);

// Fetch logs (Limit 150)
$stmt = $pdo->prepare("
    SELECT l.*, u.full_name, u.username
    FROM audit_logs l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE $whereSQL
    ORDER BY l.created_at DESC
    LIMIT 150
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch users for filtering
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>📜 ประวัติการใช้งานระบบ</h2>
            <div class="topbar-breadcrumb">บันทึกประวัติการทำรายการสำคัญย้อนหลัง (สูงสุด 150 รายการล่าสุด)</div>
        </div>
    </div>
</div>

<div class="page-content">
    <!-- Filters Card -->
    <div class="card mb-3 fade-in">
        <form method="GET" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end">
            <div style="flex:2; min-width:180px">
                <label style="font-size:0.8rem; font-weight:600; color:var(--text-muted); display:block; margin-bottom:0.3rem">🔍 ค้นหากิจกรรม/คำสั่ง</label>
                <input type="text" name="action_q" class="form-control" placeholder="เช่น LOGIN, UPDATE, DELETE..." value="<?= htmlspecialchars($search_action) ?>">
            </div>
            <div style="flex:2; min-width:180px">
                <label style="font-size:0.8rem; font-weight:600; color:var(--text-muted); display:block; margin-bottom:0.3rem">เลือกตามผู้ใช้</label>
                <select name="user_id" class="form-control">
                    <option value="">-- ผู้ใช้ทั้งหมด --</option>
                    <?php foreach ($users as $us): ?>
                        <option value="<?= $us['id'] ?>" <?= $filter_user === intval($us['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($us['full_name']) ?> (<?= htmlspecialchars($us['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; gap:0.5rem">
                <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                <a href="logs.php" class="btn btn-outline">รีเซ็ต</a>
            </div>
        </form>
    </div>

    <!-- Logs Table Card -->
    <div class="card fade-in">
        <div class="card-header">
            <h3>📋 ตารางประวัติกิจกรรมระบบ</h3>
        </div>
        
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <p>ไม่พบรายการบันทึกประวัติการใช้งานที่ค้นหา</p>
            </div>
        <?php else: ?>
            <div class="table-wrap table-responsive-cards">
                <table>
                    <thead>
                        <tr>
                            <th>📅 วันเวลาที่บันทึก</th>
                            <th>👤 ผู้ใช้งาน</th>
                            <th>⚡ กิจกรรม/คำสั่ง</th>
                            <th>📝 รายละเอียด</th>
                            <th>🖥️ หมายเลข IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td data-label="เวลาที่บันทึก" style="font-size:0.82rem; white-space:nowrap"><?= thaiDateTime($log['created_at']) ?></td>
                                <td data-label="ผู้ใช้งาน">
                                    <?php if ($log['user_id']): ?>
                                        <strong><?= htmlspecialchars($log['full_name']) ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-muted)">@<?= htmlspecialchars($log['username']) ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted)">- (ไม่ได้เข้าใช้งาน) -</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="กิจกรรม">
                                    <span style="background:var(--primary); color:#fff; padding:0.2rem 0.5rem; border-radius:4px; font-family:monospace; font-size:0.75rem; font-weight:700">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td data-label="รายละเอียด" style="font-size:0.875rem"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                <td data-label="IP Address" style="font-family:monospace; font-size:0.82rem; color:var(--text-muted)"><?= htmlspecialchars($log['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

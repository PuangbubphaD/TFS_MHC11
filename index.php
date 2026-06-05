<?php
require_once __DIR__ . '/includes/header.php';

$uid  = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'director') {
    header('Location: dashboard.php');
    exit;
}

// Fiscal Year Filter
$fy_filter = $_GET['fy'] ?? '';
if ($fy_filter === '') $fy_filter = getCurrentFiscalYear();

$where = ['p.deleted_at IS NULL'];
$params = [];

if (in_array($role, ['staff', 'head'])) {
    $where[] = 'p.user_id = ?';
    $params[] = $uid;
}

if ($fy_filter && $fy_filter !== 'all') {
    $where[] = 'p.fiscal_year = ?';
    $params[] = $fy_filter;
}

$whereSQL = implode(' AND ', $where);

// For staff & head: show only own projects. For admin: show all.
$stmt = $pdo->prepare("
    SELECT p.*,
           u.full_name AS owner_name,
           COUNT(DISTINCT a.id) AS activity_count,
           COALESCE(SUM(ar.budget_spent),0) AS total_spent
    FROM projects p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN activities a ON (a.project_id = p.id AND a.deleted_at IS NULL)
    LEFT JOIN activity_reports ar ON ar.activity_id = a.id
    WHERE $whereSQL
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Stats
$total    = count($projects);
$active   = count(array_filter($projects, fn($p) => $p['status'] === 'active'));
$done     = count(array_filter($projects, fn($p) => $p['status'] === 'completed'));
$budget   = array_sum(array_column($projects, 'budget_total'));
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>🏠 โครงการของฉัน</h2>
            <div class="topbar-breadcrumb">ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION['user_name']) ?></div>
        </div>
    </div>
    <div style="display: flex; gap: 1rem; align-items: center;">
        <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
            <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">ปีงบประมาณ:</label>
            <select name="fy" class="form-control" style="width: auto; padding-right: 2rem;" onchange="this.form.submit()">
                <?= renderFiscalYearOptions($fy_filter) ?>
            </select>
        </form>
        <a href="add_project.php" class="btn btn-primary">➕ เพิ่มโครงการใหม่</a>
    </div>
</div>

<div class="page-content">

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card fade-in" style="border-color:var(--status-blue)">
            <div class="stat-icon" style="background:rgba(49,130,206,0.1)">📋</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-blue)"><?= $total ?></div>
                <div class="label">โครงการทั้งหมด</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-yellow)">
            <div class="stat-icon" style="background:rgba(214,158,46,0.1)">⏳</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-yellow)"><?= $active ?></div>
                <div class="label">กำลังดำเนินการ</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-green)">
            <div class="stat-icon" style="background:rgba(56,161,105,0.1)">✅</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-green)"><?= $done ?></div>
                <div class="label">เสร็จสิ้นแล้ว</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--primary)">
            <div class="stat-icon" style="background:rgba(26,35,126,0.1)">💰</div>
            <div class="stat-info">
                <div class="value" style="color:var(--primary);font-size:1.3rem"><?= formatThaiAmount($budget) ?></div>
                <div class="label">งบประมาณรวมทุกโครงการ</div>
            </div>
        </div>
    </div>

    <!-- Project Grid -->
    <?php if (empty($projects)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="icon">📂</div>
            <h3>ยังไม่มีโครงการ</h3>
            <p>เริ่มต้นโดยการเพิ่มโครงการแรกของคุณ</p>
            <a href="add_project.php" class="btn btn-primary mt-2">➕ เพิ่มโครงการใหม่</a>
        </div>
    </div>
    <?php else: ?>
    <div class="grid grid-2">
        <?php foreach ($projects as $p):
            $pct = budgetPercent($p['total_spent'], $p['budget_total']);
            $barColor = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
            $statusColors = ['active'=>'var(--status-blue)','completed'=>'var(--status-green)','cancelled'=>'#999','draft'=>'#aaa'];
            $borderColor  = $statusColors[$p['status']] ?? '#ccc';
        ?>
        <a href="view_project.php?id=<?= $p['id'] ?>" class="project-card fade-in">
            <div class="project-card-header">
                <div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.3rem">
                        📅 <?= thaiDate($p['start_date']) ?> <?= $p['end_date'] ? '→ ' . thaiDate($p['end_date']) : '' ?>
                    </div>
                    <h3 style="font-size:1rem;font-weight:700;color:var(--text-main)"><?= htmlspecialchars($p['title']) ?></h3>
                    <?php if ($role === 'admin'): ?>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:0.2rem">👤 <?= htmlspecialchars($p['owner_name']) ?></div>
                    <?php endif; ?>
                </div>
                <?= getStatusBadge($p['status']) ?>
            </div>
            <div class="project-card-body">
                <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1rem;line-height:1.5">
                    <?= nl2br(htmlspecialchars(mb_substr($p['description'] ?? '', 0, 100))) ?>
                    <?= mb_strlen($p['description'] ?? '') > 100 ? '...' : '' ?>
                </p>
                <!-- Budget -->
                <div class="budget-meter">
                    <div class="budget-labels">
                        <span>เบิกจ่ายแล้ว: <?= formatThaiAmount($p['total_spent']) ?></span>
                        <span><?= $pct ?>%</span>
                    </div>
                    <div class="progress-wrap">
                        <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem;text-align:right">
                        งบที่ได้รับจัดสรร: <?= formatThaiAmount($p['budget_total']) ?>
                    </div>
                </div>
            </div>
            <div class="project-card-footer">
                <span>📌 <?= $p['activity_count'] ?> กิจกรรม</span>
                <span>ดูรายละเอียด →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

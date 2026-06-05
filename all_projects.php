<?php
require_once __DIR__ . '/includes/header.php';

// Only head/director/admin can view all
if (!in_array($_SESSION['role'], ['head','director','admin'])) {
    header('Location: index.php'); exit;
}


// Filter params
$status_filter = $_GET['status'] ?? '';
$owner_filter  = $_GET['user_id'] ?? '';
$search        = trim($_GET['q'] ?? '');
$fy_filter     = $_GET['fy'] ?? '';
if ($fy_filter === '') $fy_filter = getCurrentFiscalYear();

// Build WHERE
$where  = ['p.deleted_at IS NULL'];
$params = [];
if ($status_filter) { $where[] = 'p.status = ?'; $params[] = $status_filter; }
if ($owner_filter)  { $where[] = 'p.user_id = ?'; $params[] = $owner_filter; }
if ($search)        { $where[] = 'p.title LIKE ?'; $params[] = "%$search%"; }
if ($fy_filter && $fy_filter !== 'all') { $where[] = 'p.fiscal_year = ?'; $params[] = $fy_filter; }

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS owner,
           (SELECT COUNT(*) FROM activities WHERE project_id = p.id AND deleted_at IS NULL) AS activity_count,
           (SELECT COALESCE(SUM(budget_spent), 0) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL) AS spent,
           (SELECT COALESCE(SUM(participants), 0) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL) AS total_participants,
           (SELECT COUNT(*) FROM project_phases WHERE project_id = p.id AND status = 'completed') AS admin_phases_done,
           (SELECT 
                CASE WHEN COUNT(a.id) = 0 THEN 0 
                ELSE (SELECT COUNT(*) FROM activity_phases ap JOIN activities act ON ap.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL AND ap.status='completed') / (COUNT(a.id) * 8) * 100 
                END 
            FROM activities a WHERE a.project_id = p.id AND a.deleted_at IS NULL) AS activity_progress
    FROM projects p
    JOIN users u ON p.user_id = u.id
    WHERE $whereSQL
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Owners for filter
$owners = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>📋 โครงการทั้งหมด</h2>
        <div class="topbar-breadcrumb">ภาพรวมโครงการทุกโครงการในระบบ</div>
    </div>
    </div>
    <a href="dashboard.php" class="btn btn-outline">📊 Dashboard</a>
</div>

<div class="page-content">
    <!-- Filters -->
    <div class="card fade-in mb-3">
        <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:150px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">ปีงบประมาณ</label>
                <select name="fy" class="form-control">
                    <?= renderFiscalYearOptions($fy_filter) ?>
                </select>
            </div>
            <div style="flex:2;min-width:200px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">🔍 ค้นหา</label>
                <input type="text" name="q" class="form-control" placeholder="ชื่อโครงการ..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div style="flex:1;min-width:150px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">สถานะ</label>
                <select name="status" class="form-control">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="active"    <?= $status_filter==='active'?'selected':'' ?>>กำลังดำเนินการ</option>
                    <option value="completed" <?= $status_filter==='completed'?'selected':'' ?>>เสร็จสิ้น</option>
                    <option value="draft"     <?= $status_filter==='draft'?'selected':'' ?>>ร่าง</option>
                    <option value="cancelled" <?= $status_filter==='cancelled'?'selected':'' ?>>ยกเลิก</option>
                </select>
            </div>
            <div style="flex:1;min-width:150px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">ผู้รับผิดชอบ</label>
                <select name="user_id" class="form-control">
                    <option value="">-- ทุกคน --</option>
                    <?php foreach ($owners as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= $owner_filter==$o['id']?'selected':'' ?>><?= htmlspecialchars($o['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem">
                <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                <a href="all_projects.php" class="btn btn-outline">รีเซ็ต</a>
            </div>
        </form>
    </div>

    <!-- Results count -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <span style="color:var(--text-muted);font-size:0.875rem">พบ <strong><?= count($projects) ?></strong> โครงการ</span>
    </div>

    <!-- Projects Grid -->
    <?php if (empty($projects)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="icon">📭</div>
            <h3>ไม่พบโครงการ</h3>
            <p>ลองเปลี่ยนเงื่อนไขการค้นหา</p>
        </div>
    </div>
    <?php else: ?>
    <div class="grid grid-2">
        <?php foreach ($projects as $p):
            $pct = budgetPercent($p['spent'], $p['budget_total']);
            $barColor = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
            $act_pct = round($p['activity_progress']);
        ?>
        <a href="view_project.php?id=<?= $p['id'] ?>" class="project-card fade-in">
            <div class="project-card-header">
                <div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.25rem">
                        👤 <?= htmlspecialchars($p['owner']) ?>
                    </div>
                    <h3 style="font-size:1rem;font-weight:700"><?= htmlspecialchars($p['title']) ?></h3>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.15rem">
                        📅 <?= $p['start_date'] ? thaiDate($p['start_date']) : 'ไม่ระบุวัน' ?>
                    </div>
                </div>
                <?= getStatusBadge($p['status']) ?>
            </div>
            <div class="project-card-body">
                <div class="budget-meter">
                    <div class="budget-labels">
                        <span>เบิกจ่ายแล้ว <?= formatThaiAmount($p['spent']) ?></span>
                        <span><?= $pct ?>%</span>
                    </div>
                    <div class="progress-wrap">
                        <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem;text-align:right">
                        งบที่ได้รับจัดสรร <?= formatThaiAmount($p['budget_total']) ?>
                    </div>
                </div>
                <!-- Phase progress -->
                <div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center">
                    <div style="font-size:0.75rem">
                        <span style="color:var(--text-muted)">% เบิกจ่ายโครงการ:</span> <strong><?= $p['admin_phases_done'] ?>/2</strong>
                    </div>
                    <div style="font-size:0.75rem">
                        <span style="color:var(--text-muted)">% เบิกจ่ายกิจกรรม:</span> <strong style="color:var(--primary)"><?= round($p['activity_progress']) ?>%</strong>
                    </div>
                </div>
                <div class="progress-wrap" style="height:6px;margin-top:0.3rem">
                    <div class="progress-bar" style="width:<?= $act_pct ?>%"></div>
                </div>
            </div>
            <div class="project-card-footer">
                <span>📌 <?= $p['activity_count'] ?> กิจกรรม | 👥 <?= number_format($p['total_participants']) ?> คน</span>
                <span>ดูรายละเอียด →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/header.php';

// Activities overview - all activities the current user can see
$role = $_SESSION['role'];
$uid  = $_SESSION['user_id'];

// Filters
$status_filter   = $_GET['status'] ?? '';
$project_filter  = $_GET['project_id'] ?? '';
$search          = trim($_GET['q'] ?? '');
$fy_filter       = $_GET['fy'] ?? '';
if ($fy_filter === '') $fy_filter = getCurrentFiscalYear();

// Build WHERE
$where  = ['a.deleted_at IS NULL', 'p.deleted_at IS NULL'];
$params = [];

if ($role === 'staff') {
    $where[] = 'p.user_id = ?'; $params[] = $uid;
}
if ($fy_filter && $fy_filter !== 'all') { $where[] = 'p.fiscal_year = ?'; $params[] = $fy_filter; }
if ($status_filter)  { $where[] = 'a.status = ?';      $params[] = $status_filter; }
if ($project_filter) { $where[] = 'a.project_id = ?';  $params[] = $project_filter; }
if ($search)         { $where[] = 'a.activity_name LIKE ?'; $params[] = "%$search%"; }

$whereSQL = implode(' AND ', $where);

$activities = $pdo->prepare("
    SELECT a.*,
           p.title AS project_title,
           p.id AS project_id,
           u.full_name AS owner,
           COUNT(ar.id) AS report_count,
           COALESCE(SUM(ar.budget_spent),0) AS spent,
           COALESCE(SUM(ar.participants),0) AS total_participants
    FROM activities a
    JOIN projects p ON a.project_id = p.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN activity_reports ar ON ar.activity_id = a.id
    WHERE $whereSQL
    GROUP BY a.id
    ORDER BY a.planned_start DESC, a.created_at DESC
");
$activities->execute($params);
$activities = $activities->fetchAll();

// Projects for filter
$projQuery = $role === 'staff'
    ? $pdo->prepare("SELECT id, title FROM projects WHERE user_id=? AND deleted_at IS NULL ORDER BY title")
    : $pdo->query("SELECT id, title FROM projects WHERE deleted_at IS NULL ORDER BY title");
if ($role === 'staff') $projQuery->execute([$uid]);
$projects = $projQuery->fetchAll();

// Stats
$totalActs  = count($activities);
$ongoing    = count(array_filter($activities, fn($a) => $a['status'] === 'ongoing'));
$completed  = count(array_filter($activities, fn($a) => $a['status'] === 'completed'));
$totalSpent = array_sum(array_column($activities, 'spent'));
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>📌 กิจกรรมทั้งหมด</h2>
        <div class="topbar-breadcrumb">ภาพรวมกิจกรรมจากทุกโครงการ</div>
    </div>
    </div>
    <?php if ($role !== 'staff'): ?>
    <a href="dashboard.php" class="btn btn-outline">📊 Dashboard</a>
    <?php endif; ?>
</div>

<div class="page-content">
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card fade-in" style="border-color:var(--primary)">
            <div class="stat-icon" style="background:rgba(26,35,126,0.1)">📌</div>
            <div class="stat-info">
                <div class="value" style="color:var(--primary)"><?= $totalActs ?></div>
                <div class="label">กิจกรรมทั้งหมด</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-blue)">
            <div class="stat-icon" style="background:rgba(49,130,206,0.1)">🔄</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-blue)"><?= $ongoing ?></div>
                <div class="label">กำลังดำเนินการ</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-green)">
            <div class="stat-icon" style="background:rgba(56,161,105,0.1)">✅</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-green)"><?= $completed ?></div>
                <div class="label">เสร็จสิ้น</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-red)">
            <div class="stat-icon" style="background:rgba(229,62,62,0.1)">💰</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-red);font-size:1.2rem"><?= '฿' . number_format($totalSpent/1000, 0) ?>K</div>
                <div class="label">งบใช้ไปแล้วรวม</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card fade-in mb-3">
        <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:130px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">ปีงบประมาณ</label>
                <select name="fy" class="form-control">
                    <?= renderFiscalYearOptions($fy_filter) ?>
                </select>
            </div>
            <div style="flex:2;min-width:180px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">🔍 ค้นหา</label>
                <input type="text" name="q" class="form-control" placeholder="ชื่อกิจกรรม..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div style="flex:2;min-width:180px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">โครงการ</label>
                <select name="project_id" class="form-control">
                    <option value="">-- ทุกโครงการ --</option>
                    <?php foreach ($projects as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $project_filter==$p['id']?'selected':'' ?>><?= htmlspecialchars(mb_substr($p['title'],0,40,'UTF-8')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:130px">
                <label style="font-size:0.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:0.3rem">สถานะ</label>
                <select name="status" class="form-control">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="planned"   <?= $status_filter==='planned'  ?'selected':'' ?>>วางแผนแล้ว</option>
                    <option value="ongoing"   <?= $status_filter==='ongoing'  ?'selected':'' ?>>กำลังดำเนิน</option>
                    <option value="completed" <?= $status_filter==='completed'?'selected':'' ?>>เสร็จสิ้น</option>
                    <option value="cancelled" <?= $status_filter==='cancelled'?'selected':'' ?>>ยกเลิก</option>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem">
                <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                <a href="activities.php" class="btn btn-outline">รีเซ็ต</a>
            </div>
        </form>
    </div>

    <!-- Activity List -->
    <div class="card fade-in">
        <div class="card-header">
            <h3>📋 รายการกิจกรรม (<?= count($activities) ?>)</h3>
        </div>

        <?php if (empty($activities)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <h3>ไม่พบกิจกรรม</h3>
            <p>ลองเปลี่ยนเงื่อนไขการค้นหา หรือเพิ่มกิจกรรมใหม่</p>
        </div>
        <?php else: ?>

        <div class="table-wrap table-responsive-cards">
            <table>
                <thead>
                    <tr>
                        <th>กิจกรรม</th>
                        <th>โครงการ</th>
                        <th>📍 สถานที่</th>
                        <th>📅 ช่วงเวลา</th>
                        <th>👥 ผู้เข้าร่วม</th>
                        <th>📊 รายงาน</th>
                        <th>💰 ใช้จ่าย</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activities as $act):
                    $pct = budgetPercent($act['spent'], $act['planned_budget']);
                    $barC = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--accent)');
                ?>
                <tr style="cursor:pointer" onclick="window.location='view_activity.php?id=<?= $act['id'] ?>'">
                    <td data-label="กิจกรรม">
                        <div style="font-weight:600;color:var(--primary)"><?= htmlspecialchars($act['activity_name']) ?></div>
                        <?php if ($act['description']): ?>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.15rem">
                            <?= htmlspecialchars(mb_substr($act['description'],0,50,'UTF-8')) ?>...
                        </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="โครงการ">
                        <a href="view_project.php?id=<?= $act['project_id'] ?>" style="color:var(--text-main);font-size:0.85rem" onclick="event.stopPropagation()">
                            <?= htmlspecialchars(mb_substr($act['project_title'],0,30,'UTF-8')) ?>
                        </a>
                        <?php if ($role !== 'staff'): ?>
                        <div style="font-size:0.72rem;color:var(--text-muted)"><?= htmlspecialchars($act['owner']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="สถานที่" style="font-size:0.82rem"><?= htmlspecialchars($act['location'] ?? '-') ?></td>
                    <td data-label="ช่วงเวลา" style="font-size:0.8rem;white-space:nowrap">
                        <?= thaiDate($act['planned_start']) ?><br>
                        <span style="color:var(--text-muted)">→ <?= thaiDate($act['planned_end']) ?></span>
                    </td>
                    <td data-label="ผู้เข้าร่วม" style="text-align:center">
                        <div style="font-size:0.85rem"><?= number_format($act['total_participants']) ?></div>
                        <div style="font-size:0.72rem;color:var(--text-muted)">(วางแผน: <?= number_format($act['planned_participants']) ?>)</div>
                    </td>
                    <td data-label="รายงาน" style="text-align:center">
                        <span style="font-size:1rem;font-weight:700;color:var(--primary)"><?= $act['report_count'] ?></span>
                        <div style="font-size:0.72rem;color:var(--text-muted)">ครั้ง</div>
                    </td>
                    <td data-label="ใช้จ่าย">
                        <div style="font-size:0.85rem;font-weight:600;color:<?= $barC ?>"><?= formatBaht($act['spent']) ?></div>
                        <div class="progress-wrap" style="width:80px;margin-top:0.3rem">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barC ?>"></div>
                        </div>
                        <div style="font-size:0.7rem;color:var(--text-muted)"><?= $pct ?>% ของ <?= formatBaht($act['planned_budget']) ?></div>
                    </td>
                    <td data-label="สถานะ"><?= getStatusBadge($act['status']) ?></td>
                    <td data-label="การจัดการ" onclick="event.stopPropagation()">
                        <div style="display:flex;flex-direction:column;gap:0.3rem">
                            <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm">📊 ดู</a>
                            <a href="add_activity_report.php?activity_id=<?= $act['id'] ?>" class="btn btn-accent btn-sm">➕ รายงาน</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

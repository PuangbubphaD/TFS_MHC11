<?php
require_once __DIR__ . '/includes/header.php';

// Only head/director/admin can access
if (!in_array($_SESSION['role'], ['head','director','admin'])) {
    header('Location: index.php'); exit;
}

// =================== DATA QUERIES ===================

// Overall Stats (Fixed to prevent multiplication from joins)
$totals = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL) AS total_projects,
        (SELECT COUNT(*) FROM projects WHERE status='active' AND deleted_at IS NULL) AS active_projects,
        (SELECT COUNT(*) FROM projects WHERE status='completed' AND deleted_at IS NULL) AS done_projects,
        (SELECT COALESCE(SUM(budget_total), 0) FROM projects WHERE deleted_at IS NULL) AS total_budget,
        (SELECT COUNT(*) FROM activities WHERE deleted_at IS NULL) AS total_activities,
        (SELECT COALESCE(SUM(ar2.budget_spent), 0) FROM activity_reports ar2 JOIN activities act2 ON ar2.activity_id = act2.id JOIN projects prj2 ON act2.project_id = prj2.id WHERE act2.deleted_at IS NULL AND prj2.deleted_at IS NULL) AS total_spent,
        (SELECT COALESCE(SUM(planned_participants), 0) FROM activities WHERE deleted_at IS NULL) AS total_planned_participants,
        (SELECT COALESCE(SUM(ar3.participants), 0) FROM activity_reports ar3 JOIN activities act3 ON ar3.activity_id = act3.id JOIN projects prj3 ON act3.project_id = prj3.id WHERE act3.deleted_at IS NULL AND prj3.deleted_at IS NULL) AS total_actual_participants
")->fetch();

// 1. Top 5 Overdue/Urgent Phases
$overdueUrgentPhases = $pdo->query("
    SELECT ap.*, a.activity_name, p.title AS project_title, u.full_name AS owner_name,
           DATEDIFF(CURDATE(), ap.deadline_date) AS days_overdue
    FROM activity_phases ap
    JOIN activities a ON ap.activity_id = a.id
    JOIN projects p ON a.project_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE (ap.status = 'overdue' OR (ap.deadline_date < CURDATE() AND ap.status != 'completed'))
      AND a.deleted_at IS NULL 
      AND p.deleted_at IS NULL
    ORDER BY ap.deadline_date ASC
    LIMIT 5
")->fetchAll();

// 2. Top 5 Ongoing/Upcoming Activities of the Week
$ongoingUpcomingActivities = $pdo->query("
    SELECT a.*, p.title AS project_title, u.full_name AS owner_name,
           (SELECT COUNT(*) FROM activity_phases WHERE activity_id = a.id AND status = 'completed') AS completed_phases
    FROM activities a
    JOIN projects p ON a.project_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE a.deleted_at IS NULL AND p.deleted_at IS NULL
      AND (a.status = 'ongoing' OR (a.planned_start BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
    ORDER BY a.planned_start ASC
    LIMIT 5
")->fetchAll();

// 3. Top 5 Projects with Highest Remaining Budget
$remainingBudgetRanking = $pdo->query("
    SELECT p.id, p.title, p.budget_total, u.full_name AS owner_name,
           (SELECT COALESCE(SUM(ar.budget_spent), 0) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL) AS spent
    FROM projects p
    JOIN users u ON p.user_id = u.id
    WHERE p.deleted_at IS NULL
    ORDER BY (p.budget_total - (SELECT COALESCE(SUM(ar.budget_spent), 0) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL)) DESC
    LIMIT 5
")->fetchAll();

// Budget by project (for bar chart)
$budgetByProject = $pdo->query("
    SELECT p.title,
           p.budget_total,
           COALESCE(SUM(ar.budget_spent),0) AS spent
    FROM projects p
    LEFT JOIN activities a ON (a.project_id=p.id AND a.deleted_at IS NULL)
    LEFT JOIN activity_reports ar ON ar.activity_id=a.id
    WHERE p.deleted_at IS NULL
    GROUP BY p.id
    ORDER BY p.budget_total DESC
    LIMIT 10
")->fetchAll();

// Phase status distribution (Combined from Project and Activity levels)
$phaseStats = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM (
        SELECT ph.status FROM project_phases ph JOIN projects p ON ph.project_id = p.id WHERE p.deleted_at IS NULL
        UNION ALL
        SELECT ap.status FROM activity_phases ap JOIN activities a ON ap.activity_id = a.id JOIN projects p ON a.project_id = p.id WHERE a.deleted_at IS NULL AND p.deleted_at IS NULL
    ) AS all_phases
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Activity reports recent
$recentReports = $pdo->query("
    SELECT ar.*, a.activity_name, p.title AS project_title, p.id AS project_id,
           a.id AS activity_id, u.full_name AS reporter
    FROM activity_reports ar
    JOIN activities a ON ar.activity_id = a.id
    JOIN projects p ON a.project_id = p.id
    LEFT JOIN users u ON ar.reported_by = u.id
    WHERE a.deleted_at IS NULL AND p.deleted_at IS NULL
    ORDER BY ar.report_date DESC
    LIMIT 50
")->fetchAll();

// All projects with detail (Fixed subqueries to prevent row multiplication)
$allProjects = $pdo->query("
    SELECT p.*, u.full_name AS owner,
           (SELECT COUNT(*) FROM activities WHERE project_id = p.id AND deleted_at IS NULL) AS activity_count,
           (SELECT COUNT(*) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL) AS report_count,
           (SELECT COALESCE(SUM(budget_spent), 0) FROM activity_reports ar JOIN activities act ON ar.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL) AS spent,
           (SELECT COUNT(*) FROM project_phases WHERE project_id = p.id AND status = 'completed') AS admin_phases_done,
           (SELECT 
                CASE WHEN COUNT(a.id) = 0 THEN 0 
                ELSE (SELECT COUNT(*) FROM activity_phases ap JOIN activities act ON ap.activity_id = act.id WHERE act.project_id = p.id AND act.deleted_at IS NULL AND ap.status='completed') / (COUNT(a.id) * 8) * 100 
                END 
            FROM activities a WHERE a.project_id = p.id AND a.deleted_at IS NULL) AS activity_progress
    FROM projects p
    JOIN users u ON p.user_id = u.id
    WHERE p.deleted_at IS NULL
    ORDER BY p.created_at DESC
")->fetchAll();

// Calculate project counts by status
$allCount = count($allProjects);
$activeCount = 0;
$completedCount = 0;
$pendingCount = 0;
foreach ($allProjects as $p) {
    if ($p['status'] === 'active') {
        $activeCount++;
    } elseif ($p['status'] === 'completed') {
        $completedCount++;
    } elseif ($p['status'] === 'pending') {
        $pendingCount++;
    }
}

// Fetch all activities with their budget spent and phases completed, and group them by project_id
$activitiesQuery = $pdo->query("
    SELECT a.*,
           (SELECT COUNT(*) FROM activity_phases WHERE activity_id=a.id AND status='completed') AS completed_phases,
           (SELECT COALESCE(SUM(budget_spent), 0) FROM activity_reports WHERE activity_id=a.id) AS spent
    FROM activities a
    WHERE a.deleted_at IS NULL
    ORDER BY a.planned_start ASC
");
$projectActivities = [];
while ($act = $activitiesQuery->fetch()) {
    $projectActivities[$act['project_id']][] = $act;
}

// Monthly spending (last 6 months)
$monthlySpend = $pdo->query("
    SELECT DATE_FORMAT(ar.report_date,'%Y-%m') AS ym,
           DATE_FORMAT(ar.report_date,'%b %Y') AS label,
           SUM(ar.budget_spent) AS total
    FROM activity_reports ar
    JOIN activities act ON ar.activity_id = act.id
    JOIN projects prj ON act.project_id = prj.id
    WHERE ar.report_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
      AND act.deleted_at IS NULL
      AND prj.deleted_at IS NULL
    GROUP BY ym, label
    ORDER BY ym
")->fetchAll();
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>📊 Dashboard ภาพรวม</h2>
        <div class="topbar-breadcrumb">Real-time | อัปเดต: <?= date('d/m/Y H:i') ?></div>
    </div>
    </div>
    <a href="index.php" class="btn btn-outline">🏠 โครงการของฉัน</a>
</div>

<div class="page-content">

    <!-- STATS CARDS -->
    <div class="stats-grid">
        <div class="stat-card fade-in" style="border-color:var(--primary)">
            <div class="stat-icon" style="background:rgba(26,35,126,0.1);font-size:1.8rem">📋</div>
            <div class="stat-info">
                <div class="value" style="color:var(--primary)"><?= $totals['total_projects'] ?></div>
                <div class="label">โครงการทั้งหมด</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-blue)">
            <div class="stat-icon" style="background:rgba(49,130,206,0.1);font-size:1.8rem">⏳</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-blue)"><?= $totals['active_projects'] ?></div>
                <div class="label">กำลังดำเนินการ</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-green)">
            <div class="stat-icon" style="background:rgba(56,161,105,0.1);font-size:1.8rem">✅</div>
            <div class="stat-info">
                <div class="value" style="color:var(--status-green)"><?= $totals['done_projects'] ?></div>
                <div class="label">เสร็จสิ้นแล้ว</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--accent)">
            <div class="stat-icon" style="background:rgba(0,172,193,0.1);font-size:1.8rem">📌</div>
            <div class="stat-info">
                <div class="value" style="color:var(--accent)"><?= $totals['total_activities'] ?></div>
                <div class="label">กิจกรรมทั้งหมด</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--secondary)">
            <div class="stat-icon" style="background:rgba(255,111,0,0.1);font-size:1.8rem">💰</div>
            <div class="stat-info" style="overflow:hidden; width:100%; container-type: inline-size;">
                <div class="value" style="color:var(--secondary);display:flex;flex-wrap:wrap;align-items:baseline;gap:0.25rem;letter-spacing:-0.3px;line-height:1.2;">
                    <span style="font-size: clamp(0.85rem, 16cqi, 1.15rem); font-weight: 800;"><?= number_format($totals['total_budget']) ?></span>
                    <span style="font-size:0.85rem;letter-spacing:0;font-weight:bold;">บาท</span>
                </div>
                <div class="label" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:0.2rem;">งบที่ได้รับจัดสรร</div>
            </div>
        </div>
        <div class="stat-card fade-in" style="border-color:var(--status-red)">
            <div class="stat-icon" style="background:rgba(229,62,62,0.1);font-size:1.8rem">📤</div>
            <div class="stat-info" style="overflow:hidden; width:100%; container-type: inline-size;">
                <div class="value" style="color:var(--status-red);display:flex;flex-wrap:wrap;align-items:baseline;gap:0.25rem;letter-spacing:-0.3px;line-height:1.2;">
                    <span style="font-size: clamp(0.85rem, 16cqi, 1.15rem); font-weight: 800;"><?= number_format($totals['total_spent']) ?></span>
                    <span style="font-size:0.85rem;letter-spacing:0;font-weight:bold;">บาท</span>
                </div>
                <div class="label" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:0.2rem;">เบิกจ่ายแล้ว (<?= budgetPercent($totals['total_spent'], $totals['total_budget']) ?>%)</div>
            </div>
        </div>
        <!-- 7th Card: Cumulative Participants Reach -->
        <div class="stat-card fade-in" style="border-color:var(--primary-light)">
            <div class="stat-icon" style="background:rgba(107,70,193,0.1);font-size:1.8rem">👥</div>
            <div class="stat-info" style="width: 100%;overflow:hidden; container-type: inline-size;">
                <div class="value" style="color:var(--primary);display:flex;flex-wrap:wrap;align-items:baseline;gap:0.25rem;letter-spacing:-0.3px;line-height:1.2;">
                    <span style="font-size: clamp(0.85rem, 16cqi, 1.25rem); font-weight: 800;"><?= number_format($totals['total_actual_participants']) ?></span>
                    <span style="font-size:0.85rem;letter-spacing:0;font-weight:bold;">คน</span>
                </div>
                <div class="label" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:0.2rem;">ผู้เข้าร่วมสะสม (Reach)</div>
                <div style="margin-top:0.4rem; width:100%;">
                    <?php 
                    $reach_pct = $totals['total_planned_participants'] > 0 ? round(($totals['total_actual_participants'] / $totals['total_planned_participants']) * 100) : 0;
                    ?>
                    <div style="display:flex; justify-content:space-between; font-size:0.68rem; color:var(--text-muted); margin-bottom:0.15rem;">
                        <span>เป้าหมาย: <?= number_format($totals['total_planned_participants']) ?> คน</span>
                        <span><?= $reach_pct ?>%</span>
                    </div>
                    <div class="progress-wrap" style="height:5px; margin:0; background:#edf2f7;">
                        <div class="progress-bar" style="width:<?= min($reach_pct, 100) ?>%; background:linear-gradient(to right, #6b46c1, #00acc1);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="grid grid-2 mb-3">
        <!-- Budget Bar Chart -->
        <div class="card fade-in">
            <div class="card-header"><h3>💰 งบประมาณ vs ใช้จ่ายจริง (รายโครงการ)</h3></div>
            <div style="position: relative; width: 100%;"><canvas id="budgetChart" height="220"></canvas></div>
        </div>
        <!-- Phase Pie + Monthly Spend -->
        <div class="card fade-in">
            <div class="card-header"><h3>📊 สถานะขั้นตอนทั้งหมด</h3></div>
            <div style="position: relative; width: 100%;"><canvas id="phaseChart" height="220"></canvas></div>
        </div>
    </div>

    <!-- Monthly Spending Chart -->
    <div class="card fade-in mb-3">
        <div class="card-header"><h3>📈 การใช้จ่ายงบประมาณรายเดือน (6 เดือนย้อนหลัง)</h3></div>
        <div style="position: relative; width: 100%;"><canvas id="monthlyChart" height="100"></canvas></div>
    </div>

    <!-- WIDGETS ROW: Overdue Tasks & Ongoing Activities -->
    <div class="grid grid-2 mb-3">
        <!-- Overdue Tasks Widget -->
        <div class="card fade-in">
            <div class="card-header" style="border-bottom: 1.5px solid var(--border); padding-bottom: 0.75rem;">
                <h3 style="color:var(--status-red); display:flex; align-items:center; gap:0.5rem; margin:0;">
                    <span>⚠️ ขั้นตอนที่ล่าช้า / เร่งด่วนที่สุด</span>
                </h3>
            </div>
            <div style="padding: 1rem; display:flex; flex-direction:column; gap:0.75rem;">
                <?php if (empty($overdueUrgentPhases)): ?>
                    <div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.9rem;">
                        🎉 ยอดเยี่ยม! ไม่มีขั้นตอนที่ล่าช้าในขณะนี้
                    </div>
                <?php else: ?>
                    <?php foreach ($overdueUrgentPhases as $ph): 
                        $days_overdue = getWorkingDays($ph['deadline_date'], date('Y-m-d'), $global_holidays);
                    ?>
                        <div class="overdue-pill-card" style="background:#fff; border:1.5px solid var(--border); border-left:4px solid var(--status-red); border-radius:10px; padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; box-shadow:0 2px 5px rgba(229,62,62,0.02); transition:all 0.2s;" onmouseover="this.style.borderColor='var(--status-red)'; this.style.transform='translateY(-1px)'" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none'">
                            <div style="flex:1;">
                                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.25rem;">
                                    <span style="font-weight:700; font-size:0.85rem; color:var(--text-main);">
                                        ขั้นที่ <?= $ph['phase_number'] ?>: <?= htmlspecialchars($ph['phase_name']) ?>
                                    </span>
                                    <span style="background:#fee2e2; color:var(--status-red); font-size:0.7rem; font-weight:700; padding:0.15rem 0.40rem; border-radius:6px; white-space:nowrap;">
                                        ล่าช้า <?= max(0, $days_overdue) ?> วันทำการ
                                    </span>
                                </div>
                                <div style="font-size:0.78rem; color:var(--primary); font-weight:600; margin-bottom:0.15rem;">
                                    🎯 กิจกรรม: <?= htmlspecialchars($ph['activity_name']) ?>
                                </div>
                                <div style="font-size:0.72rem; color:var(--text-muted);">
                                    📁 โครงการ: <?= htmlspecialchars($ph['project_title']) ?> • 👤 โดย: <?= htmlspecialchars($ph['owner_name']) ?>
                                </div>
                            </div>
                            <div>
                                <a href="update_phase.php?id=<?= $ph['id'] ?>&type=activity" class="btn btn-outline btn-sm" style="padding:0.4rem 0.75rem; font-size:0.75rem; border-radius:6px; font-weight:700; white-space:nowrap; border-color:var(--status-red); color:var(--status-red); background:#fff; min-height:40px; display:inline-flex; align-items:center; justify-content:center;" onmouseover="this.style.background='var(--status-red)'; this.style.color='#fff'" onmouseout="this.style.background='#fff'; this.style.color='var(--status-red)'">
                                    อัปเดต ⚡
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ongoing / Upcoming Activities Widget -->
        <div class="card fade-in">
            <div class="card-header" style="border-bottom: 1.5px solid var(--border); padding-bottom: 0.75rem;">
                <h3 style="color:var(--status-blue); display:flex; align-items:center; gap:0.5rem; margin:0;">
                    <span>⚡ กิจกรรมในสัปดาห์นี้ / กำลังดำเนินการ</span>
                </h3>
            </div>
            <div style="padding: 1rem; display:flex; flex-direction:column; gap:0.75rem;">
                <?php if (empty($ongoingUpcomingActivities)): ?>
                    <div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.9rem;">
                        📅 ไม่มีกิจกรรมใหม่ที่จะเริ่มต้นในสัปดาห์นี้
                    </div>
                <?php else: ?>
                    <?php foreach ($ongoingUpcomingActivities as $act): 
                        $progress = round(($act['completed_phases'] / 8) * 100);
                    ?>
                        <div class="ongoing-pill-card" style="background:#fff; border:1.5px solid var(--border); border-left:4px solid var(--status-blue); border-radius:10px; padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; box-shadow:0 2px 5px rgba(49,130,206,0.02); transition:all 0.2s;" onmouseover="this.style.borderColor='var(--status-blue)'; this.style.transform='translateY(-1px)'" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none'">
                            <div style="flex:1;">
                                <div style="font-weight:700; font-size:0.88rem; color:var(--text-main); margin-bottom:0.25rem;">
                                    <?= htmlspecialchars($act['activity_name']) ?>
                                </div>
                                <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">
                                    📅 <?= thaiDate($act['planned_start']) ?> - <?= thaiDate($act['planned_end']) ?> • 👤 โดย: <?= htmlspecialchars($act['owner_name']) ?>
                                </div>
                                <div style="display:flex; align-items:center; gap:0.5rem; width:100%;">
                                    <div class="progress-wrap" style="height:5px; margin:0; flex:1; background:#edf2f7;">
                                        <div class="progress-bar" style="width:<?= $progress ?>%; background:var(--status-blue);"></div>
                                    </div>
                                    <span style="font-size:0.72rem; font-weight:700; color:var(--status-blue); min-width:25px; text-align:right;">
                                        <?= $progress ?>%
                                    </span>
                                </div>
                            </div>
                            <div>
                                <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.4rem 0.75rem; font-size:0.75rem; border-radius:6px; font-weight:700; white-space:nowrap; border-color:var(--status-blue); color:var(--status-blue); background:#fff; min-height:40px; display:inline-flex; align-items:center; justify-content:center;" onmouseover="this.style.background='var(--status-blue)'; this.style.color='#fff'" onmouseout="this.style.background='#fff'; this.style.color='var(--status-blue)'">
                                    เปิดดู 👀
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Remaining Budget Ranking List -->
    <div class="card fade-in mb-3">
        <div class="card-header" style="border-bottom: 1.5px solid var(--border); padding-bottom: 0.75rem;">
            <h3 style="color:var(--primary-dark); display:flex; align-items:center; gap:0.5rem; margin:0;">
                <span>💰 5 อันดับโครงการงบประมาณคงเหลือสูงสุด (Remaining Budget)</span>
            </h3>
        </div>
        <div style="padding: 1.25rem 1.5rem; display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
            <?php foreach ($remainingBudgetRanking as $index => $proj): 
                $rem = $proj['budget_total'] - $proj['spent'];
                $rem_pct = $proj['budget_total'] > 0 ? round(($rem / $proj['budget_total']) * 100) : 0;
                
                // Color levels (green to blue gradient)
                $gradient_start = '#38a169'; // green
                $gradient_end = '#3182ce'; // blue
            ?>
                <div class="budget-rank-card" style="background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:1.25rem; box-shadow:0 3px 8px rgba(111,53,165,0.03); display:flex; flex-direction:column; justify-content:between; min-height:140px; transition:all 0.2s; cursor:pointer;" onclick="window.location='view_project.php?id=<?= $proj['id'] ?>'" onmouseover="this.style.borderColor='var(--primary)'; this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none'">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
                            <span style="font-weight:800; font-size:1.1rem; color:var(--primary); background:rgba(111,53,165,0.1); width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <?= $index + 1 ?>
                            </span>
                            <span style="font-size:0.7rem; font-weight:700; color:var(--text-muted); background:#f7fafc; padding:0.15rem 0.4rem; border-radius:6px; max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                👤 <?= htmlspecialchars($proj['owner_name']) ?>
                            </span>
                        </div>
                        <h4 style="font-weight:700; font-size:0.85rem; margin:0 0 0.5rem 0; color:var(--text-main); line-height:1.3; height:2.6em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                            <?= htmlspecialchars($proj['title']) ?>
                        </h4>
                    </div>
                    <div style="margin-top:auto;">
                        <div style="display:flex; justify-content:space-between; font-size:0.75rem; font-weight:700; color:var(--text-main); margin-bottom:0.25rem;">
                            <span>คงเหลือ: <?= number_format($rem, 0) ?> บาท</span>
                            <span style="color:#38a169;"><?= $rem_pct ?>%</span>
                        </div>
                        <div class="progress-wrap" style="height:6px; margin:0; background:#edf2f7;">
                            <div class="progress-bar" style="width:<?= $rem_pct ?>%; background:linear-gradient(to right, <?= $gradient_start ?>, <?= $gradient_end ?>);"></div>
                        </div>
                        <div style="font-size:0.65rem; color:var(--text-muted); margin-top:0.25rem; text-align:right;">
                            จากงบจัดสรร: <?= number_format($proj['budget_total'], 0) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ALL PROJECTS TABLE -->
    <div class="card fade-in mb-3" style="overflow: visible;">
        <div class="card-header" style="border-bottom: none; padding-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <h3 style="margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem; color: var(--primary-dark);">
                <span>📋 รายชื่อโครงการทั้งหมด</span>
            </h3>
        </div>
        
        <style>
            .filter-section {
                padding: 1.25rem 1.5rem;
                border-bottom: 1.5px solid var(--border);
                background: linear-gradient(180deg, #FAF8FF 0%, #F5F1FF 100%);
                border-top-left-radius: 0;
                border-top-right-radius: 0;
            }
            .status-tab {
                padding: 0.45rem 1.15rem;
                border: none;
                border-radius: 8px;
                font-size: 0.825rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                background: transparent;
                color: var(--text-main);
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
            }
            .status-tab:hover {
                background: rgba(111, 53, 165, 0.08);
                color: var(--primary);
            }
            .status-tab.active {
                background: var(--primary) !important;
                color: #fff !important;
                font-weight: 700;
                box-shadow: 0 4px 10px rgba(111, 53, 165, 0.2);
            }
            .btn-toggle-activities:hover {
                background: #e2e8f0 !important;
                color: var(--primary-dark) !important;
            }
            .btn-toggle-activities.expanded {
                transform: rotate(90deg);
            }
            #projectsTable thead th {
                background: #F9F6FF;
                color: var(--primary-dark);
                font-weight: 700;
                font-size: 0.8rem;
                padding: 1rem 0.75rem;
                border-bottom: 2px solid var(--border);
                white-space: nowrap;
            }
            #projectsTable tbody tr.project-row {
                transition: all 0.2s ease;
            }
            #projectsTable tbody tr.project-row:hover {
                background-color: #FCFAFF;
            }
            .form-control-custom:focus {
                border-color: var(--primary) !important;
                box-shadow: 0 0 0 3px rgba(111, 53, 165, 0.15) !important;
                outline: none;
            }
        </style>

        <div class="filter-section">
            <!-- Tabs & Reset Button -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div class="status-tabs" style="display: flex; gap: 0.35rem; background: rgba(111, 53, 165, 0.06); padding: 0.3rem; border-radius: 10px; border: 1px solid var(--border); flex-wrap: wrap;">
                    <button class="status-tab active" data-status="all">
                        🌐 ทั้งหมด (<?= $allCount ?>)
                    </button>
                    <button class="status-tab" data-status="active">
                        ⚡ ดำเนินการอยู่ (<?= $activeCount ?>)
                    </button>
                    <button class="status-tab" data-status="completed">
                        ✅ เสร็จสิ้น (<?= $completedCount ?>)
                    </button>
                    <?php if ($pendingCount > 0): ?>
                    <button class="status-tab" data-status="pending">
                        ⏳ รอดำเนินการ (<?= $pendingCount ?>)
                    </button>
                    <?php endif; ?>
                </div>
                
                <button id="resetFilters" class="btn btn-outline" style="padding: 0.45rem 1rem; font-size: 0.825rem; border-radius: 8px; margin: 0; font-weight: 700; display: flex; align-items: center; gap: 0.35rem; height: 38px; cursor: pointer; border-color: var(--border); background: #fff; box-shadow: 0 2px 5px rgba(111,53,165,0.05); color: var(--primary);" title="ล้างตัวกรองทั้งหมด">
                    🔄 ล้างตัวกรองทั้งหมด
                </button>
            </div>

            <!-- Text Search and Select Dropdowns Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.75rem; align-items: center;">
                <!-- Text Search -->
                <div style="position: relative; display: flex; align-items: center; width: 100%;">
                    <span style="position: absolute; left: 0.9rem; color: var(--text-muted); font-size: 0.95rem; pointer-events: none;">🔍</span>
                    <input type="text" id="projectSearch" class="form-control form-control-custom" style="margin: 0; padding-left: 2.3rem; padding-right: 0.9rem; width: 100%; border-radius: 8px; background: #fff; border: 1.5px solid var(--border); box-shadow: 0 2px 4px rgba(111,53,165,0.02); height: 42px; font-size: 0.85rem;" placeholder="พิมพ์ชื่อโครงการ หรือชื่อผู้รับผิดชอบ...">
                </div>
                
                <!-- Project Dropdown -->
                <div style="position: relative; display: flex; align-items: center; width: 100%;">
                    <span style="position: absolute; left: 0.9rem; color: var(--primary); font-size: 0.95rem; pointer-events: none; z-index: 5;">📁</span>
                    <select id="projectSelectFilter" class="form-control form-control-custom" style="margin: 0; padding-left: 2.3rem; padding-right: 1.75rem; width: 100%; border-radius: 8px; background: #fff; border: 1.5px solid var(--border); box-shadow: 0 2px 4px rgba(111,53,165,0.02); height: 42px; cursor: pointer; font-weight: 600; font-size: 0.85rem; color: var(--text-main);">
                        <option value="">เลือกโครงการทั้งหมด</option>
                        <?php 
                        $projectTitles = array_unique(array_column($allProjects, 'title'));
                        sort($projectTitles);
                        foreach ($projectTitles as $title): 
                        ?>
                        <option value="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Owner Dropdown -->
                <div style="position: relative; display: flex; align-items: center; width: 100%;">
                    <span style="position: absolute; left: 0.9rem; color: var(--primary); font-size: 0.95rem; pointer-events: none; z-index: 5;">👤</span>
                    <select id="ownerSelectFilter" class="form-control form-control-custom" style="margin: 0; padding-left: 2.3rem; padding-right: 1.75rem; width: 100%; border-radius: 8px; background: #fff; border: 1.5px solid var(--border); box-shadow: 0 2px 4px rgba(111,53,165,0.02); height: 42px; cursor: pointer; font-weight: 600; font-size: 0.85rem; color: var(--text-main);">
                        <option value="">เลือกผู้รับผิดชอบทั้งหมด</option>
                        <?php 
                        $projectOwners = array_unique(array_column($allProjects, 'owner'));
                        sort($projectOwners);
                        foreach ($projectOwners as $owner): 
                        ?>
                        <option value="<?= htmlspecialchars($owner) ?>"><?= htmlspecialchars($owner) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Desktop Projects Table -->
        <div class="table-wrap desktop-only">
            <table id="projectsTable">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">#</th>
                        <th style="text-align: left;">ชื่อโครงการ</th>
                        <th style="text-align: left; width: 150px;">ผู้รับผิดชอบ</th>
                        <th style="text-align: right; width: 140px;">งบประมาณจัดสรร</th>
                        <th style="text-align: right; width: 140px;">เบิกจ่ายแล้ว</th>
                        <th style="width: 120px;">% เบิกจ่าย</th>
                        <th style="text-align: center; width: 80px;">กิจกรรม</th>
                        <th style="text-align: center; width: 180px;">ความสำเร็จ</th>
                        <th style="text-align: center; width: 100px;">สถานะ</th>
                        <th style="text-align: center; width: 100px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allProjects as $i => $p):
                    $pct = budgetPercent($p['spent'], $p['budget_total']);
                    $barC = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
                ?>
                <tr class="project-row" data-status="<?= htmlspecialchars($p['status']) ?>" data-activities-row="activities-<?= $p['id'] ?>">
                    <td style="text-align:center; color:var(--text-muted)"><?= $i+1 ?></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:0.5rem">
                            <?php if ($p['activity_count'] > 0): ?>
                            <button class="btn-toggle-activities" data-target="activities-<?= $p['id'] ?>" style="background:none; border:none; padding:0.2rem; cursor:pointer; font-size:0.7rem; color:var(--primary); transition: transform 0.2s ease; display:flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; background:#f1f5f9; flex-shrink:0;" title="แสดงกิจกรรม">
                                ▶
                            </button>
                            <?php else: ?>
                            <div style="width:20px; flex-shrink:0;"></div>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($p['title']) ?></strong>
                        </div>
                    </td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($p['owner']) ?></td>
                    <td style="text-align:right; font-weight:600; font-variant-numeric:tabular-nums;"><?= number_format($p['budget_total'], 0) ?> บาท</td>
                    <td style="text-align:right; color:<?= $barC ?>; font-weight:700; font-variant-numeric:tabular-nums;"><?= number_format($p['spent'], 0) ?> บาท</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.5rem">
                            <div class="progress-wrap" style="width:60px">
                                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barC ?>"></div>
                            </div>
                            <span style="font-size:0.78rem"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td style="text-align:center"><?= $p['activity_count'] ?></td>
                    <td style="text-align:center">
                        <div style="font-size:0.75rem;font-weight:700">ความคืบหน้าโครงการ: <?= $p['admin_phases_done'] ?>/2</div>
                        <div style="font-size:0.75rem;color:var(--primary);font-weight:700">ความคืบหน้ากิจกรรม: <?= round($p['activity_progress']) ?>%</div>
                    </td>
                    <td style="text-align:center"><?= getStatusBadge($p['status']) ?></td>
                    <td style="text-align:center"><a href="view_project.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; border-radius: 6px;">📋 เปิดดู</a></td>
                </tr>
                <?php if ($p['activity_count'] > 0): ?>
                <tr class="activities-row" id="activities-<?= $p['id'] ?>" style="display: none; background: #f8fafc;">
                    <td colspan="10" style="padding: 0; border-bottom: 1px solid var(--border);">
                        <div style="padding: 1.25rem 2rem; background: linear-gradient(to right, #f8fafc, #f1f5f9);">
                            <div style="font-weight: 700; color: var(--primary); font-size: 0.85rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                <span>🎯 รายการกิจกรรมย่อยในโครงการ (<?= count($projectActivities[$p['id']] ?? []) ?> กิจกรรม)</span>
                            </div>
                            <div class="table-wrap" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-radius: 8px; border: 1px solid var(--border); background: #fff; overflow: hidden; margin-bottom: 0.5rem;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem; margin: 0;">
                                    <thead>
                                        <tr style="background: #f1f5f9; border-bottom: 1px solid var(--border);">
                                            <th style="padding: 0.5rem 0.75rem; text-align: left; font-weight: 700; color: var(--text-muted); width: 40%;">ชื่อกิจกรรมย่อย</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: left; font-weight: 700; color: var(--text-muted); width: 20%;">วันที่จัดกิจกรรม</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: right; font-weight: 700; color: var(--text-muted); width: 12%;">งบประมาณแผน</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: right; font-weight: 700; color: var(--text-muted); width: 12%;">เบิกจ่ายจริง</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: center; font-weight: 700; color: var(--text-muted); width: 16%;">ความสำเร็จ (8 ขั้นตอน)</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: center; font-weight: 700; color: var(--text-muted); width: 10%;">สถานะ</th>
                                            <th style="padding: 0.5rem 0.75rem; text-align: center; font-weight: 700; color: var(--text-muted); width: 10%;">รายละเอียด</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $acts = $projectActivities[$p['id']] ?? [];
                                        foreach ($acts as $act): 
                                            $act_pct = round(($act['completed_phases'] / 8) * 100);
                                            $act_spent = $act['spent'];
                                            $spent_pct = budgetPercent($act_spent, $act['planned_budget']);
                                            $spent_color = $spent_pct >= 90 ? 'var(--status-red)' : ($spent_pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
                                        ?>
                                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='none'">
                                            <td style="padding: 0.6rem 0.75rem; font-weight: 600; color: var(--primary);">
                                                <?= htmlspecialchars($act['activity_name']) ?>
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; white-space: nowrap; color: var(--text-muted);">
                                                📅 <?= thaiDate($act['planned_start']) ?> - <?= thaiDate($act['planned_end']) ?>
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600;">
                                                <?= number_format($act['planned_budget'], 0) ?>
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600; color: <?= $spent_color ?>;">
                                                <?= number_format($act_spent, 0) ?> (<?= $spent_pct ?>%)
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; text-align: center;">
                                                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                                    <div class="progress-wrap" style="width: 70px; height: 6px; margin: 0;">
                                                        <div class="progress-bar" style="width: <?= $act_pct ?>%; background: var(--status-blue);"></div>
                                                    </div>
                                                    <span style="font-weight: 700; font-size: 0.75rem; min-width: 25px; text-align: right;"><?= $act_pct ?>%</span>
                                                </div>
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; text-align: center;">
                                                <?= getStatusBadge($act['status']) ?>
                                            </td>
                                            <td style="padding: 0.6rem 0.75rem; text-align: center;">
                                                <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm" style="padding: 0.15rem 0.4rem; font-size: 0.75rem;">👀 ดู</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Projects Card List (With Collapsible Activities) -->
        <div class="mobile-only" style="padding: 0 1.25rem 1.25rem 1.25rem;">
            <?php foreach ($allProjects as $index => $p):
                $pct = budgetPercent($p['spent'], $p['budget_total']);
                $barC = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
            ?>
            <div class="mobile-project-card" data-status="<?= htmlspecialchars($p['status']) ?>" style="background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 3px 8px rgba(111,53,165,0.03); transition:all 0.2s;">
                <!-- Header Status & Owner -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);">
                        👤 ผู้รับผิดชอบ: <?= htmlspecialchars($p['owner']) ?>
                    </span>
                    <?= getStatusBadge($p['status']) ?>
                </div>

                <!-- Project Title -->
                <h3 style="font-weight:700; font-size:1.05rem; color:var(--primary-dark); margin:0 0 0.75rem 0; line-height:1.4;">
                    <strong><?= htmlspecialchars($p['title']) ?></strong>
                </h3>

                <!-- Budget & Spends -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; background:#f8fafc; padding:0.75rem; border-radius:8px; margin-bottom:0.75rem; font-size:0.8rem;">
                    <div>
                        <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:0.15rem;">งบประมาณโครงการ</div>
                        <div style="font-weight:700; color:var(--text-main);"><?= number_format($p['budget_total'], 0) ?> บาท</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:0.15rem;">เบิกจ่ายแล้ว</div>
                        <div style="font-weight:700; color:<?= $barC ?>;"><?= number_format($p['spent'], 0) ?> บาท (<?= $pct ?>%)</div>
                    </div>
                </div>

                <!-- Budget Progress -->
                <div style="margin-bottom:1rem;">
                    <div class="progress-wrap" style="height:6px; margin:0; background:#edf2f7;">
                        <div class="progress-bar" style="width:<?= $pct ?>%; background:<?= $barC ?>;"></div>
                    </div>
                </div>

                <!-- Achievements summary -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; font-size:0.75rem; margin-bottom:1rem; border-top:1px solid var(--border); padding-top:0.75rem;">
                    <div>
                        <span style="font-weight:700;">เบิกจ่ายโครงการ:</span> 
                        <span style="font-weight:800; color:var(--primary);"><?= $p['admin_phases_done'] ?>/2 ขั้นตอน</span>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-weight:700;">เบิกจ่ายกิจกรรม:</span> 
                        <span style="font-weight:800; color:var(--primary);"><?= round($p['activity_progress']) ?>%</span>
                    </div>
                </div>

                <!-- Collapsible Sub-Activities Accordion for Mobile -->
                <?php if ($p['activity_count'] > 0): ?>
                    <div style="margin-bottom:0.75rem;">
                        <button class="btn btn-outline btn-sm btn-mobile-toggle-activities" data-target="mobile-act-<?= $p['id'] ?>" style="width:100%; justify-content:center; font-size:0.78rem; font-weight:700; padding:0.45rem 1rem; border-radius:8px; display:inline-flex; align-items:center; gap:0.35rem; min-height:40px; background:#fff; border-color:var(--border);">
                            <span>🎯 แสดงกิจกรรมย่อย (<?= $p['activity_count'] ?>)</span>
                            <span class="arrow-indicator" style="transition:transform 0.2s;">▼</span>
                        </button>
                        
                        <div id="mobile-act-<?= $p['id'] ?>" class="mobile-sub-activities-container" style="display:none; margin-top:0.75rem; border:1px solid var(--border); border-radius:10px; background:#f8fafc; overflow:hidden; padding:0.5rem;">
                            <?php 
                            $acts = $projectActivities[$p['id']] ?? [];
                            foreach ($acts as $act): 
                                $act_pct = round(($act['completed_phases'] / 8) * 100);
                                $act_spent = $act['spent'];
                                $act_spent_pct = budgetPercent($act_spent, $act['planned_budget']);
                                $act_spent_color = $act_spent_pct >= 90 ? 'var(--status-red)' : ($act_spent_pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
                            ?>
                            <div style="padding:0.6rem 0.5rem; border-bottom:1px solid var(--border); font-size:0.78rem;" class="mobile-sub-act-item">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.25rem;">
                                    <strong style="color:var(--primary); font-size:0.8rem;"><?= htmlspecialchars($act['activity_name']) ?></strong>
                                    <?= getStatusBadge($act['status']) ?>
                                </div>
                                <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:0.25rem;">
                                    📅 <?= thaiDate($act['planned_start']) ?> - <?= thaiDate($act['planned_end']) ?>
                                </div>
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.35rem;">
                                    <span>งบแผน: <?= number_format($act['planned_budget'], 0) ?></span>
                                    <span style="font-weight:700; color:<?= $act_spent_color ?>;">ใช้จ่าย: <?= number_format($act_spent, 0) ?> (<?= $act_spent_pct ?>%)</span>
                                </div>
                                <div style="display:flex; align-items:center; gap:0.5rem;">
                                    <div class="progress-wrap" style="height:5px; margin:0; flex:1; background:#e2e8f0;">
                                        <div class="progress-bar" style="width:<?= $act_pct ?>%; background:var(--status-blue);"></div>
                                    </div>
                                    <span style="font-weight:700; font-size:0.7rem; min-width:25px; text-align:right;"><?= $act_pct ?>%</span>
                                </div>
                                <div style="text-align:right; margin-top:0.35rem;">
                                    <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem; min-height:30px; display:inline-flex; align-items:center; justify-content:center;">👀 ดูของกิจกรรม</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Button -->
                <div style="display:flex; justify-content:flex-end; border-top:1px solid var(--border); padding-top:0.75rem;">
                    <a href="view_project.php?id=<?= $p['id'] ?>" class="btn btn-primary" style="padding:0.45rem 1rem; font-size:0.8rem; border-radius:6px; width:100%; text-align:center; min-height:40px; display:inline-flex; align-items:center; justify-content:center;">
                        📋 เปิดดูรายละเอียดโครงการ
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- RECENT ACTIVITY REPORTS -->
    <div class="card fade-in">
        <div class="card-header">
            <h3>📊 รายงานกิจกรรมทั้งหมด (<?= count($recentReports) ?> รายการ)</h3>
        </div>
        <div class="table-wrap table-responsive-cards" style="max-height:500px; overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>กิจกรรม</th>
                        <th>โครงการ</th>
                        <th>📍 สถานที่</th>
                        <th>👥 ผู้เข้าร่วม</th>
                        <th>💰 งบที่ใช้</th>
                        <th>รายงานโดย</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentReports as $rep): ?>
                <tr style="cursor:pointer" onclick="window.location='view_activity.php?id=<?= $rep['activity_id'] ?>'">
                    <td data-label="วันที่" style="white-space:nowrap"><?= thaiDate($rep['report_date']) ?></td>
                    <td data-label="กิจกรรม"><strong><?= htmlspecialchars($rep['activity_name']) ?></strong></td>
                    <td data-label="โครงการ" style="color:var(--primary)"><?= htmlspecialchars($rep['project_title']) ?></td>
                    <td data-label="สถานที่"><?= htmlspecialchars($rep['location'] ?? '-') ?></td>
                    <td data-label="ผู้เข้าร่วม" style="text-align:center"><?= number_format($rep['participants']) ?></td>
                    <td data-label="งบที่ใช้" style="font-weight:600;color:var(--status-red)"><?= formatBaht($rep['budget_spent']) ?></td>
                    <td data-label="รายงานโดย" style="font-size:0.8rem;color:var(--text-muted)"><?= htmlspecialchars($rep['reporter'] ?? '-') ?></td>
                    <td><span style="color:var(--accent);font-size:0.75rem">ดูรายละเอียด →</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentReports)): ?>
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">ยังไม่มีรายงาน</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Budget Bar Chart
const budgetLabels = <?= json_encode(array_map(fn($r) => mb_substr($r['title'],0,15,'UTF-8').'...', $budgetByProject)) ?>;
const budgetData   = <?= json_encode(array_column($budgetByProject, 'budget_total')) ?>;
const spentData    = <?= json_encode(array_column($budgetByProject, 'spent')) ?>;

new Chart(document.getElementById('budgetChart'), {
    type: 'bar',
    data: {
        labels: budgetLabels,
        datasets: [
            { label: 'งบประมาณ', data: budgetData, backgroundColor: 'rgba(26,35,126,0.6)', borderRadius: 6 },
            { label: 'ใช้จ่ายจริง', data: spentData, backgroundColor: 'rgba(229,62,62,0.7)', borderRadius: 6 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
});

// Phase Pie Chart
const phaseStats = <?= json_encode($phaseStats) ?>;
new Chart(document.getElementById('phaseChart'), {
    type: 'doughnut',
    data: {
        labels: ['รอดำเนินการ','กำลังดำเนิน','เสร็จสิ้น','เกินกำหนด'],
        datasets: [{
            data: [
                phaseStats['pending'] || 0,
                phaseStats['in_progress'] || 0,
                phaseStats['completed'] || 0,
                phaseStats['overdue'] || 0
            ],
            backgroundColor: ['#cbd5e0','#3182ce','#38a169','#e53e3e'],
            borderWidth: 2, borderColor: '#fff'
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// Monthly Spending
const monthlyLabels = <?= json_encode(array_column($monthlySpend, 'label')) ?>;
const monthlyData   = <?= json_encode(array_column($monthlySpend, 'total')) ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'การใช้จ่าย (บาท)',
            data: monthlyData,
            borderColor: '#00acc1', backgroundColor: 'rgba(0,172,193,0.1)',
            borderWidth: 3, fill: true, tension: 0.4,
            pointBackgroundColor: '#00acc1', pointRadius: 6
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

// Toggle activities row (Desktop)
document.querySelectorAll('.btn-toggle-activities').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const targetId = this.dataset.target;
        const subRow = document.getElementById(targetId);
        if (!subRow) return;
        
        const isCollapsed = subRow.style.display === 'none';
        if (isCollapsed) {
            subRow.style.display = '';
            this.classList.add('expanded');
            this.title = 'ซ่อนกิจกรรม';
        } else {
            subRow.style.display = 'none';
            this.classList.remove('expanded');
            this.title = 'แสดงกิจกรรม';
        }
    });
});

// Toggle activities row (Mobile Cards)
document.querySelectorAll('.btn-mobile-toggle-activities').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const targetId = this.dataset.target;
        const container = document.getElementById(targetId);
        if (!container) return;
        
        const isCollapsed = container.style.display === 'none';
        const arrow = this.querySelector('.arrow-indicator');
        if (isCollapsed) {
            container.style.display = 'block';
            if (arrow) arrow.style.transform = 'rotate(180deg)';
        } else {
            container.style.display = 'none';
            if (arrow) arrow.style.transform = 'none';
        }
    });
});

// Current status filter (all, active, completed, pending)
let currentStatusFilter = 'all';

// Combined project filter (Search Text + Project Dropdown + Owner Dropdown + Status Tab)
function filterProjects() {
    const searchVal = document.getElementById('projectSearch').value.toLowerCase();
    const projectVal = document.getElementById('projectSelectFilter').value.toLowerCase();
    const ownerVal = document.getElementById('ownerSelectFilter').value.toLowerCase();

    // 1. Filter Desktop Table rows
    document.querySelectorAll('#projectsTable tbody tr.project-row').forEach(row => {
        const titleEl = row.querySelector('td:nth-child(2) strong');
        const ownerEl = row.querySelector('td:nth-child(3)');
        
        const titleText = titleEl ? titleEl.textContent.trim().toLowerCase() : '';
        const ownerText = ownerEl ? ownerEl.textContent.trim().toLowerCase() : '';
        const projectStatus = row.dataset.status ? row.dataset.status.toLowerCase() : '';
        
        const matchesSearch = row.textContent.toLowerCase().includes(searchVal);
        const matchesProject = !projectVal || titleText.includes(projectVal);
        const matchesOwner = !ownerVal || ownerText.includes(ownerVal);
        const matchesStatus = currentStatusFilter === 'all' || projectStatus === currentStatusFilter;

        const isVisible = matchesSearch && matchesProject && matchesOwner && matchesStatus;
        row.style.display = isVisible ? '' : 'none';

        // Synced collapse row visibility
        const targetId = row.dataset.activitiesRow;
        if (targetId) {
            const subRow = document.getElementById(targetId);
            if (subRow) {
                if (!isVisible) {
                    subRow.style.display = 'none';
                } else {
                    const btn = row.querySelector('.btn-toggle-activities');
                    const isExpanded = btn && btn.classList.contains('expanded');
                    subRow.style.display = isExpanded ? '' : 'none';
                }
            }
        }
    });

    // 2. Filter Mobile Cards
    document.querySelectorAll('.mobile-project-card').forEach(card => {
        const titleEl = card.querySelector('h3 strong');
        const ownerEl = card.querySelector('span'); // 👤 ผู้รับผิดชอบ: xxx
        
        const titleText = titleEl ? titleEl.textContent.trim().toLowerCase() : '';
        const ownerText = ownerEl ? ownerEl.textContent.replace('👤 ผู้รับผิดชอบ:', '').trim().toLowerCase() : '';
        const projectStatus = card.dataset.status ? card.dataset.status.toLowerCase() : '';
        
        const matchesSearch = card.textContent.toLowerCase().includes(searchVal);
        const matchesProject = !projectVal || titleText.includes(projectVal);
        const matchesOwner = !ownerVal || ownerText.includes(ownerVal);
        const matchesStatus = currentStatusFilter === 'all' || projectStatus === currentStatusFilter;

        const isVisible = matchesSearch && matchesProject && matchesOwner && matchesStatus;
        card.style.display = isVisible ? '' : 'none';
    });
}

// Add event listeners for dropdowns and search inputs
document.getElementById('projectSearch').addEventListener('input', filterProjects);
document.getElementById('projectSelectFilter').addEventListener('change', filterProjects);
document.getElementById('ownerSelectFilter').addEventListener('change', filterProjects);

// Add event listeners for status tabs
document.querySelectorAll('.status-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.status-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentStatusFilter = this.dataset.status;
        filterProjects();
    });
});

// Reset all filters
document.getElementById('resetFilters').addEventListener('click', function() {
    document.getElementById('projectSearch').value = '';
    document.getElementById('projectSelectFilter').value = '';
    document.getElementById('ownerSelectFilter').value = '';
    
    // Reset status tabs
    currentStatusFilter = 'all';
    document.querySelectorAll('.status-tab').forEach(tab => {
        if (tab.dataset.status === 'all') {
            tab.classList.add('active');
        } else {
            tab.classList.remove('active');
        }
    });
    
    filterProjects();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

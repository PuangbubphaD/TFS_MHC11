<?php
require_once __DIR__ . '/includes/header.php';

$project_id = intval($_GET['id'] ?? 0);
if (!$project_id) { header('Location: index.php'); exit; }

// Fetch project
$stmt = $pdo->prepare("SELECT p.*, u.full_name AS owner_name FROM projects p JOIN users u ON p.user_id=u.id WHERE p.id=? AND p.deleted_at IS NULL");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { header('Location: index.php'); exit; }

// Access check
if ($_SESSION['role'] === 'staff' && $project['user_id'] != $_SESSION['user_id']) {
    header('Location: index.php'); exit;
}

// Fetch 2 project phases
$phases = $pdo->prepare("SELECT * FROM project_phases WHERE project_id=? ORDER BY phase_number");
$phases->execute([$project_id]);
$phases = $phases->fetchAll();

// Fetch activities with their 6-phase summary
$activities = $pdo->prepare("
    SELECT a.*,
           (SELECT COUNT(*) FROM activity_phases WHERE activity_id=a.id AND status='completed') AS completed_phases,
           (SELECT COALESCE(SUM(budget_spent),0) FROM activity_reports WHERE activity_id=a.id) AS spent
    FROM activities a
    WHERE a.project_id = ? AND a.deleted_at IS NULL
    ORDER BY a.planned_start
");
$activities->execute([$project_id]);
$activities = $activities->fetchAll();

// Overall budget
$totalSpent = array_sum(array_column($activities, 'spent'));
$pct = budgetPercent($totalSpent, $project['budget_total']);
$barColor = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');

$statusTH = ['pending'=>'รอดำเนินการ','in_progress'=>'กำลังดำเนินการ','completed'=>'เสร็จสิ้น','overdue'=>'เกินกำหนด','planned'=>'วางแผนแล้ว','ongoing'=>'กำลังดำเนิน','cancelled'=>'ยกเลิก'];
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>📋 <?= htmlspecialchars($project['title']) ?></h2>
        <div class="topbar-breadcrumb">
            <a href="index.php" style="color:var(--text-muted)">โครงการ</a> / <?= htmlspecialchars($project['title']) ?>
        </div>
    </div>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
        <?php if ($project['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'],['head','director','admin'])): ?>
        <a href="add_activity.php?project_id=<?= $project_id ?>" class="btn btn-accent">➕ เพิ่มกิจกรรม</a>
        <a href="edit_project.php?id=<?= $project_id ?>" class="btn btn-outline">✏️ แก้ไขโครงการ</a>
        <?php endif; ?>
        <a href="project_report.php?id=<?= $project_id ?>" class="btn btn-outline" target="_blank">🖨️ พิมพ์รายงาน</a>
        <a href="index.php" class="btn btn-outline">← กลับ</a>
    </div>
</div>

<div class="page-content">
    <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">✅ สร้างโครงการสำเร็จ! ระบบสร้างขั้นตอนขออนุมัติและสรุปให้อัตโนมัติแล้ว</div>
    <?php endif; ?>

    <div class="grid grid-2 mb-3">
        <!-- 2 Project Phases -->
        <div class="card fade-in">
            <div class="card-header"><h3>🔄 ขั้นตอนบริหารโครงการ</h3></div>
            <div class="phase-timeline-simple" style="margin-top:1rem">
                <?php foreach ($phases as $ph): 
                    $ph_status = $ph['status'];
                    $daysLeft = null;
                    if ($ph['deadline_date'] && in_array($ph_status, ['pending', 'in_progress'])) {
                        $daysLeft = getWorkingDays(date('Y-m-d'), $ph['deadline_date'], $global_holidays);
                        if ($daysLeft < 0) {
                            $ph_status = 'overdue';
                        }
                    }
                    $sc = ['pending'=>'#ccc','in_progress'=>'var(--status-blue)','completed'=>'var(--status-green)','overdue'=>'var(--status-red)'][$ph_status] ?? '#ccc';
                ?>
                <div class="phase-item-horizontal" style="margin-bottom:1rem;display:flex;gap:1rem;align-items:center">
                    <div class="phase-num" style="background:<?= $sc ?>;flex-shrink:0;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">
                        <?= $ph['status'] === 'completed' ? '✓' : $ph['phase_number'] ?>
                    </div>
                    <div style="flex:1">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <span style="font-weight:700"><?= htmlspecialchars($ph['phase_name']) ?></span>
                            <div style="display:flex;gap:0.5rem">
                                <?= getStatusBadge($ph['status'], $ph['deadline_date'], $global_holidays) ?>
                                <?php if ($project['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'],['head','director','admin'])): ?>
                                <a href="update_phase.php?id=<?= $ph['id'] ?>&type=project" class="btn btn-outline btn-sm">อัปเดต</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($ph['deadline_date']): 
                            $deadlineColor = getDeadlineColor($ph['deadline_date'], $global_holidays);
                            if ($daysLeft === null && in_array($ph['status'], ['pending', 'in_progress'])) {
                                $daysLeft = getWorkingDays(date('Y-m-d'), $ph['deadline_date'], $global_holidays);
                            }
                        ?>
                        <div style="font-size:0.75rem;color:<?= $deadlineColor ?>;font-weight:600">
                            กำหนด: <?= thaiDate($ph['deadline_date']) ?>
                            <?php if ($ph['status'] !== 'completed'): ?>
                                (<?= $daysLeft >= 0 ? "เหลือ $daysLeft วันทำการ" : 'เกินกำหนด ' . abs($daysLeft) . ' วันทำการ' ?>)
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card fade-in">
            <div class="card-header"><h3>💰 สรุปงบประมาณโครงการ</h3></div>
            <div style="text-align:center;padding:0.5rem 0">
                <div style="font-size:2rem;font-weight:800;color:<?= $barColor ?>"><?= $pct ?>%</div>
                <div style="font-size:0.8rem;color:var(--text-muted)">ใช้จ่ายแล้วรวมทุกกิจกรรม</div>
            </div>
            <div class="progress-wrap" style="height:10px;margin-bottom:1rem">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
            </div>
            <div class="grid grid-2" style="text-align:center">
                <div style="background:#f7fafc;padding:0.5rem;border-radius:8px">
                    <div style="font-size:1rem;font-weight:700"><?= formatThaiAmount($project['budget_total']) ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted)">งบที่ได้รับจัดสรร</div>
                </div>
                <div style="background:#f7fafc;padding:0.5rem;border-radius:8px">
                    <div style="font-size:1rem;font-weight:700;color:<?= $barColor ?>"><?= formatThaiAmount($totalSpent) ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted)">เบิกจ่ายแล้ว</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activities Table (Matrix Tracking) -->
    <div class="card fade-in">
        <div class="card-header">
            <h3>📌 กิจกรรมและขั้นตอนการดำเนินงาน (<?= count($activities) ?>)</h3>
            <?php if ($project['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'],['head','director','admin'])): ?>
            <a href="add_activity.php?project_id=<?= $project_id ?>" class="btn btn-accent btn-sm">➕ เพิ่มกิจกรรม</a>
            <?php endif; ?>
        </div>

        <?php if (empty($activities)): ?>
        <div class="empty-state">
            <div class="icon">📋</div>
            <p>ยังไม่มีกิจกรรมในโครงการนี้</p>
        </div>
        <?php else: ?>
        <div class="table-wrap desktop-only">
            <table class="activity-matrix">
                <thead>
                    <tr>
                        <th style="width:250px">ชื่อกิจกรรม</th>
                        <th style="text-align:center">ความคืบหน้า</th>
                        <th style="text-align:center;font-size:0.65rem">1.ขออนุมัติจัด</th>
                        <th style="text-align:center;font-size:0.65rem">2.ยืมเงิน/พัสดุ</th>
                        <th style="text-align:center;font-size:0.65rem">3.คน/เชิญ</th>
                        <th style="text-align:center;font-size:0.65rem">4.จัดกิจกรรม</th>
                        <th style="text-align:center;font-size:0.65rem">5.จัดทำ onepage</th>
                        <th style="text-align:center;font-size:0.65rem">6.เบิกจ่าย</th>
                        <th style="text-align:center;font-size:0.65rem">7.สรุปกิจกรรม</th>
                        <th style="text-align:center;font-size:0.65rem">8.ผอ.อนุมัติสรุป</th>
                        <th style="text-align:right">เบิกจ่าย</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $act): 
                        $act_pct = round(($act['completed_phases'] / 8) * 100);
                        // Fetch the 8 phases status for this activity
                        $act_ph = $pdo->prepare("SELECT id, status FROM activity_phases WHERE activity_id=? ORDER BY phase_number");
                        $act_ph->execute([$act['id']]);
                        $act_phases_list = $act_ph->fetchAll();
                    ?>
                    <tr>
                        <td>
                            <a href="view_activity.php?id=<?= $act['id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
                                <?= htmlspecialchars($act['activity_name']) ?>
                            </a>
                            <div style="font-size:0.72rem;color:var(--text-muted)">📅 <?= thaiDate($act['planned_start']) ?></div>
                        </td>
                        <td style="text-align:center">
                            <div style="font-size:0.85rem;font-weight:700"><?= $act_pct ?>%</div>
                            <div class="progress-wrap" style="height:4px;width:60px;margin:0 auto">
                                <div class="progress-bar" style="width:<?= $act_pct ?>%"></div>
                            </div>
                        </td>
                        <?php 
                        $phase_names = ['1.ขออนุมัติจัดกิจกรรม','2.ยืมเงิน/พัสดุ','3.ขออนุมัติคน/เชิญ','4.ดำเนินการจัดกิจกรรม','5.การจัดทำ onepage','6.เบิกจ่ายงบประมาณ','7.สรุปกิจกรรม','8.ผู้อำนวยการลงนามอนุมัติสรุป'];
                        for ($i=0; $i<8; $i++): 
                            $ph_item = $act_phases_list[$i] ?? null;
                            $st = $ph_item['status'] ?? 'pending';
                            $dotColor = ['pending'=>'#e2e8f0','in_progress'=>'#3182ce','completed'=>'#38a169','overdue'=>'#e53e3e'][$st];
                            $ph_link = $ph_item ? "update_phase.php?id={$ph_item['id']}&type=activity" : "#";
                            $pname = $phase_names[$i];
                        ?>
                        <td style="text-align:center;padding:0.25rem">
                            <a href="<?= $ph_link ?>" title="<?= $pname ?> (<?= $statusTH[$st] ?? $st ?>)" style="text-decoration:none">
                                <div style="width:12px;height:12px;border-radius:50%;background:<?= $dotColor ?>;margin:0 auto;border:1px solid rgba(0,0,0,0.1)"></div>
                            </a>
                        </td>
                        <?php endfor; ?>
                        <td style="text-align:right">
                            <div style="font-weight:700"><?= formatThaiAmount($act['spent']) ?></div>
                            <div style="font-size:0.7rem;color:var(--text-muted)">/ <?= formatThaiAmount($act['planned_budget']) ?></div>
                        </td>
                        <td style="text-align:center">
                            <?php if ($project['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'],['head','director','admin'])): ?>
                            <a href="edit_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.4rem;font-size:0.75rem" onclick="event.stopPropagation()">✏️</a>
                            <?php endif; ?>
                            <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.4rem;font-size:0.75rem" onclick="event.stopPropagation()">👀</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card List for Activities -->
        <div class="mobile-only" style="margin-top: 1rem;">
            <?php foreach ($activities as $act): 
                $act_pct = round(($act['completed_phases'] / 8) * 100);
                // Fetch the 8 phases status for this activity
                $act_ph = $pdo->prepare("SELECT id, status FROM activity_phases WHERE activity_id=? ORDER BY phase_number");
                $act_ph->execute([$act['id']]);
                $act_phases_list = $act_ph->fetchAll();
                
                $spent_pct = budgetPercent($act['spent'], $act['planned_budget']);
                $spent_color = $spent_pct >= 90 ? 'var(--status-red)' : ($spent_pct >= 70 ? 'var(--status-yellow)' : 'var(--status-green)');
            ?>
            <div class="mobile-activity-card" style="background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(111,53,165,0.03);">
                <!-- Header -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem; gap:0.5rem;">
                    <div>
                        <a href="view_activity.php?id=<?= $act['id'] ?>" style="font-weight:700; color:var(--primary); text-decoration:none; font-size:1rem; display:block;">
                            <?= htmlspecialchars($act['activity_name']) ?>
                        </a>
                        <span style="font-size:0.75rem; color:var(--text-muted); display:block; margin-top:0.2rem;">
                            📅 <?= thaiDate($act['planned_start']) ?>
                        </span>
                    </div>
                    <div>
                        <?= getStatusBadge($act['status']) ?>
                    </div>
                </div>

                <!-- Budget Details -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; background:#f8fafc; padding:0.75rem; border-radius:8px; margin-bottom:0.75rem; font-size:0.8rem;">
                    <div>
                        <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:0.15rem;">งบประมาณแผน</div>
                        <div style="font-weight:700; color:var(--text-main);"><?= number_format($act['planned_budget'], 0) ?> บาท</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="color:var(--text-muted); font-size:0.7rem; margin-bottom:0.15rem;">เบิกจ่ายจริง</div>
                        <div style="font-weight:700; color:<?= $spent_color ?>;"><?= number_format($act['spent'], 0) ?> บาท (<?= $spent_pct ?>%)</div>
                    </div>
                </div>

                <!-- Overall Progress Bar -->
                <div style="margin-bottom:1rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.78rem; font-weight:700; margin-bottom:0.25rem;">
                        <span>ความคืบหน้าภาพรวม</span>
                        <span style="color:var(--status-blue);"><?= $act_pct ?>%</span>
                    </div>
                    <div class="progress-wrap" style="height:6px; margin:0;">
                        <div class="progress-bar" style="width:<?= $act_pct ?>%; background:var(--status-blue);"></div>
                    </div>
                </div>

                <!-- 8-Phase 4x2 Grid Matrix -->
                <div style="margin-bottom:1rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); margin-bottom:0.5rem; text-transform:uppercase;">ขั้นตอนการดำเนินงาน (8 ขั้นตอน)</div>
                    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:0.5rem; text-align:center;">
                        <?php 
                        $phase_names = [
                            '1.ขออนุมัติจัดกิจกรรม',
                            '2.ยืมเงิน/พัสดุ',
                            '3.ขออนุมัติคน/เชิญ',
                            '4.ดำเนินการจัดกิจกรรม',
                            '5.การจัดทำ onepage',
                            '6.เบิกจ่ายงบประมาณ',
                            '7.สรุปกิจกรรม',
                            '8.ผู้อำนวยการลงนามอนุมัติสรุป'
                        ];
                        for ($i=0; $i<8; $i++): 
                            $ph_item = $act_phases_list[$i] ?? null;
                            $st = $ph_item['status'] ?? 'pending';
                            $dotColor = ['pending'=>'#e2e8f0','in_progress'=>'#3182ce','completed'=>'#38a169','overdue'=>'#e53e3e'][$st];
                            $textColor = ($st === 'pending') ? 'var(--text-muted)' : '#fff';
                            $ph_link = $ph_item ? "update_phase.php?id={$ph_item['id']}&type=activity" : "#";
                            $pname = $phase_names[$i];
                        ?>
                        <a href="<?= $ph_link ?>" title="<?= $pname ?> (<?= $statusTH[$st] ?? $st ?>)" style="text-decoration:none; display:flex; flex-direction:column; align-items:center; justify-content:center; background:<?= $dotColor ?>; border-radius:8px; padding:0.4rem 0.25rem; min-height:42px; border:1px solid rgba(0,0,0,0.05); transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                            <span style="font-size:0.8rem; font-weight:800; color:<?= $textColor ?>;"><?= $i+1 ?></span>
                            <span style="font-size:0.55rem; font-weight:700; color:<?= $textColor ?>; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%; max-width:60px;">
                                <?= $st === 'completed' ? '✓' : ($st === 'in_progress' ? '⚡' : ($st === 'overdue' ? '⏳' : '-')) ?>
                            </span>
                        </a>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Action Buttons (Minimum Touch Target 40px) -->
                <div style="display:flex; justify-content:flex-end; gap:0.5rem; border-top:1px solid var(--border); padding-top:0.75rem;">
                    <?php if ($project['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'],['head','director','admin'])): ?>
                    <a href="edit_activity.php?id=<?= $act['id'] ?>" class="btn btn-outline" style="padding:0.45rem 1rem; font-size:0.8rem; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; min-height:40px;">✏️ แก้ไข</a>
                    <?php endif; ?>
                    <a href="view_activity.php?id=<?= $act['id'] ?>" class="btn btn-primary" style="padding:0.45rem 1rem; font-size:0.8rem; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; min-height:40px;">👀 ดูรายละเอียด</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:1rem;display:flex;gap:1.5rem;font-size:0.72rem;color:var(--text-muted);justify-content:center;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:0.3rem"><div style="width:10px;height:10px;border-radius:50%;background:#e2e8f0"></div> รอดำเนินการ</div>
            <div style="display:flex;align-items:center;gap:0.3rem"><div style="width:10px;height:10px;border-radius:50%;background:#3182ce"></div> กำลังดำเนินการ</div>
            <div style="display:flex;align-items:center;gap:0.3rem"><div style="width:10px;height:10px;border-radius:50%;background:#38a169"></div> เสร็จสิ้น</div>
            <div style="display:flex;align-items:center;gap:0.3rem"><div style="width:10px;height:10px;border-radius:50%;background:#e53e3e"></div> เกินกำหนด</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.activity-matrix { width:100%; border-collapse:collapse; margin-top:0.5rem; }
.activity-matrix th { background:#f8fafc; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; padding:0.75rem; border-bottom:2px solid var(--border); }
.activity-matrix td { padding:0.75rem; border-bottom:1px solid var(--border); font-size:0.875rem; vertical-align:middle; }
.activity-matrix tr:hover { background:#f1f5f9; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/header.php';

$activity_id = intval($_GET['id'] ?? 0);
if (!$activity_id) { header('Location: index.php'); exit; }

// Fetch activity with project info
$stmt = $pdo->prepare("
    SELECT a.*, p.title AS project_title, p.id AS project_id, p.user_id AS project_owner
    FROM activities a
    JOIN projects p ON p.id = a.project_id
    WHERE a.id = ? AND a.deleted_at IS NULL AND p.deleted_at IS NULL
");
$stmt->execute([$activity_id]);
$activity = $stmt->fetch();
if (!$activity) { header('Location: index.php'); exit; }

// Access check: Staff cannot view other people's activities
if ($_SESSION['role'] === 'staff' && $activity['project_owner'] != $_SESSION['user_id']) {
    header('Location: index.php');
    exit;
}

// Fetch 7 activity phases
$stmt = $pdo->prepare("SELECT * FROM activity_phases WHERE activity_id=? ORDER BY phase_number ASC");
$stmt->execute([$activity_id]);
$phases = $stmt->fetchAll();

// Fetch all reports for this activity
$reports = $pdo->prepare("
    SELECT ar.*, u.full_name AS reporter_name
    FROM activity_reports ar
    LEFT JOIN users u ON ar.reported_by = u.id
    WHERE ar.activity_id = ?
    ORDER BY ar.report_date DESC
");
$reports->execute([$activity_id]);
$reports = $reports->fetchAll();

// Stats
$totalSpent       = array_sum(array_column($reports, 'budget_spent'));
$totalParticipants = array_sum(array_column($reports, 'participants'));
$reportCount      = count($reports);
$pct = budgetPercent($totalSpent, $activity['planned_budget']);
$barColor = $pct >= 90 ? 'var(--status-red)' : ($pct >= 70 ? 'var(--status-yellow)' : 'var(--accent)');

$canEdit = $activity['project_owner'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin';

$phaseColors = ['var(--accent)','#f6c90e','#ff6b6b','#51cf66','#00adb5','#ff922b','#cc5de8','#20c997'];
$statusColors = ['pending'=>'#ccc','in_progress'=>'var(--status-blue)','completed'=>'var(--status-green)','overdue'=>'var(--status-red)'];
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>📌 <?= htmlspecialchars($activity['activity_name']) ?></h2>
        <div class="topbar-breadcrumb">
            <a href="index.php" style="color:var(--text-muted)">โครงการ</a> /
            <a href="view_project.php?id=<?= $activity['project_id'] ?>" style="color:var(--text-muted)"><?= htmlspecialchars($activity['project_title']) ?></a> /
            <?= htmlspecialchars($activity['activity_name']) ?>
        </div>
    </div>
    </div>
    <div style="display:flex;gap:0.75rem">
        <?php if ($canEdit): ?>
        <a href="edit_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">✏️ แก้ไขกิจกรรม</a>
        <a href="add_activity_report.php?activity_id=<?= $activity_id ?>" class="btn btn-accent">➕ เพิ่มรายงานผล</a>
        <?php endif; ?>
        <a href="view_project.php?id=<?= $activity['project_id'] ?>" class="btn btn-outline">← กลับ</a>
    </div>
</div>

<div class="page-content">
    <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">✅ เพิ่มกิจกรรมสำเร็จ! ระบบสร้าง 8 ขั้นตอนปฏิบัติงานให้อัตโนมัติแล้ว</div>
    <?php endif; ?>
    <?php if (isset($_GET['report_edited'])): ?>
    <div class="alert alert-success">✅ แก้ไขรายงานผลการจัดกิจกรรมสำเร็จ!</div>
    <?php endif; ?>
    <?php if (isset($_GET['report_deleted'])): ?>
    <div class="alert alert-success">✅ ลบรายงานผลการจัดกิจกรรมเรียบร้อยแล้ว!</div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-2 mb-3">
        <div class="card fade-in">
            <div class="card-header">
                <h3>📋 ข้อมูลกิจกรรม</h3>
                <?= getStatusBadge($activity['status']) ?>
            </div>
            <div class="table-wrap">
<table style="width:100%">
                <tr><td style="padding:0.4rem 0;color:var(--text-muted);font-size:0.85rem;width:40%">📍 สถานที่วางแผน</td>
                    <td style="font-size:0.875rem"><?= htmlspecialchars($activity['location'] ?: '-') ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--text-muted);font-size:0.85rem">📅 วันที่จัดจริง</td>
                    <td style="font-size:0.875rem"><?= thaiDate($activity['planned_start']) ?> → <?= thaiDate($activity['planned_end']) ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--text-muted);font-size:0.85rem">💰 งบที่ได้รับจัดสรร</td>
                    <td style="font-size:0.875rem;font-weight:700"><?= formatThaiAmount($activity['planned_budget']) ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--text-muted);font-size:0.85rem">👥 ผู้เข้าร่วมสะสม</td>
                    <td style="font-size:0.875rem;font-weight:700"><?= number_format($totalParticipants) ?> / <?= number_format($activity['planned_participants']) ?> คน</td></tr>
            </table>
</div>
        </div>

        <div class="card fade-in">
            <div class="card-header"><h3>💰 งบประมาณที่ใช้จริง</h3></div>
            <div style="text-align:center;padding:0.5rem 0">
                <div style="font-size:2rem;font-weight:800;color:<?= $barColor ?>"><?= $pct ?>%</div>
                <div style="font-size:0.8rem;color:var(--text-muted)">เบิกจ่ายจริงเทียบกับงบกิจกรรม</div>
            </div>
            <div class="progress-wrap" style="height:10px;margin-bottom:1rem">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:0.85rem">
                <span>เบิกจ่ายแล้ว: <strong><?= formatThaiAmount($totalSpent) ?></strong></span>
                <span>คงเหลือ: <strong><?= formatThaiAmount($activity['planned_budget'] - $totalSpent) ?></strong></span>
            </div>
        </div>
    </div>

    <!-- 7-Phase Activity Timeline -->
    <div class="card fade-in mb-3">
        <div class="card-header">
            <h3>🔄 ขั้นตอนการดำเนินกิจกรรม (8 ขั้นตอน)</h3>
        </div>
        <div class="phase-timeline">
            <?php foreach ($phases as $i => $ph):
                $color   = $phaseColors[$i] ?? '#999';
                $daysLeft = $ph['deadline_date'] ? getWorkingDays(date('Y-m-d'), $ph['deadline_date'], $global_holidays) : null;
            ?>
            <div class="phase-item fade-in">
                <div class="phase-number" style="background:<?= $color ?>">
                    <?= $ph['status'] === 'completed' ? '✓' : $ph['phase_number'] ?>
                </div>
                <div class="phase-body">
                    <div class="phase-card" style="border-left:4px solid <?= $color ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.5rem">
                            <div>
                                <h4 style="margin:0;font-size:1rem;"><?= htmlspecialchars($ph['phase_name']) ?></h4>
                                <?php if (!empty($ph['description'])): ?>
                                <p style="margin:0.25rem 0 0 0;font-size:0.8rem;color:var(--text-muted);"><?= nl2br(htmlspecialchars($ph['description'])) ?></p>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:0.5rem">
                                <?= getStatusBadge($ph['status'], $ph['deadline_date'], $global_holidays, $ph['completed_date']) ?>
                                <?php if ($canEdit): ?>
                                <a href="update_phase.php?id=<?= $ph['id'] ?>&type=activity" class="btn btn-outline btn-sm">✏️ อัปเดต</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="phase-meta">
                            <?php if ($ph['deadline_date']): ?>
                            <span style="color:<?= getDeadlineColor($ph['deadline_date'], $global_holidays) ?>;font-weight:600">
                                📅 กำหนด: <?= thaiDate($ph['deadline_date']) ?>
                                <?php if ($daysLeft !== null && $ph['status'] !== 'completed'): ?>
                                    (<?= $daysLeft >= 0 ? "เหลือ $daysLeft วันทำการ" : 'เกินกำหนด ' . abs($daysLeft) . ' วันทำการ' ?>)
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($ph['completed_date']): 
                                $isLate = false;
                                $daysLateCount = 0;
                                if ($ph['status'] === 'completed' && $ph['deadline_date'] && $ph['completed_date'] > $ph['deadline_date']) {
                                    $daysLateCount = getWorkingDays($ph['deadline_date'], $ph['completed_date'], $global_holidays);
                                    $isLate = $daysLateCount > 0;
                                }
                            ?>
                            <span style="color:<?= $isLate ? '#b7791f' : ($ph['status'] === 'completed' ? 'var(--status-green)' : 'var(--status-blue)') ?>;font-weight:600;margin-left:0.5rem">
                                <?php if ($ph['status'] === 'completed'): ?>
                                    ✅ เสร็จสิ้นเมื่อ: <?= thaiDate($ph['completed_date']) ?>
                                    <?php if ($isLate): ?>
                                        <span style="background:var(--status-yellow);color:#fff;padding:0.1rem 0.4rem;border-radius:4px;font-size:0.75rem;margin-left:0.3rem">(ล่าช้า <?= $daysLateCount ?> วัน)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    🗓️ อัปเดตล่าสุด: <?= thaiDate($ph['completed_date']) ?>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($ph['notes']): ?>
                        <div style="margin-top:0.5rem;padding:0.5rem;background:#f7fafc;border-radius:6px;font-size:0.82rem">
                            📝 <?= nl2br(htmlspecialchars($ph['notes'])) ?>
                        </div>
                        <?php endif; ?>

                        <!-- Attachments for this phase -->
                        <?php
                        $fa = $pdo->prepare("SELECT * FROM attachments WHERE entity_type='activity_phase' AND entity_id=?");
                        $fa->execute([$ph['id']]);
                        $files = $fa->fetchAll();
                        if ($files):
                        ?>
                        <div class="file-list" style="margin-top:0.5rem">
                            <?php foreach ($files as $f): 
                                $fPath = "uploads/" . htmlspecialchars($f['file_path']);
                                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                                $type = isImage($f['file_name']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other');
                            ?>
                            <div class="file-item">
                                <?php if ($type === 'image'): ?>
                                    <img src="<?= $fPath ?>" class="img-preview" onclick="openPopup('<?= $fPath ?>', 'image')" alt="Preview">
                                <?php else: ?>
                                    <span style="cursor:pointer" onclick="openPopup('<?= $fPath ?>', '<?= $type ?>')"><?= getFileIcon($f['file_name']) ?></span>
                                <?php endif; ?>
                                <div style="display:flex;align-items:center;gap:0.5rem">
                                    <a href="javascript:void(0)" onclick="openPopup('<?= $fPath ?>', '<?= $type ?>')" style="font-size:0.82rem">
                                        <?= htmlspecialchars($f['file_name']) ?>
                                    </a>
                                    <?php if ($canEdit): ?>
                                    <a href="edit_attachment.php?id=<?= $f['id'] ?>" style="color:var(--text-muted);font-size:0.75rem;text-decoration:none" title="จัดการไฟล์">⚙️</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Reports List -->
    <div class="card fade-in">
        <div class="card-header">
            <h3>📊 รายงานผลการจัดกิจกรรม (<?= $reportCount ?> ครั้ง)</h3>
        </div>

        <?php if (empty($reports)): ?>
        <div class="empty-state">
            <p>ยังไม่มีรายงานผลการจัดกิจกรรม</p>
        </div>
        <?php else: ?>
        <?php foreach ($reports as $idx => $rep): ?>
        <div style="border:1.5px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1rem;background:#fff" class="fade-in">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem">
                <div>
                    <span style="background:var(--primary);color:#fff;border-radius:6px;padding:0.2rem 0.6rem;font-size:0.8rem;font-weight:700">
                        รายงานครั้งที่ <?= $reportCount - $idx ?>
                    </span>
                    <strong style="margin-left:0.75rem"><?= thaiDate($rep['report_date']) ?></strong>
                </div>
                <div style="display:flex;gap:0.75rem;font-size:0.85rem;align-items:center;flex-wrap:wrap">
                    <span>📍 <?= htmlspecialchars($rep['location'] ?: 'ไม่ได้ระบุ') ?></span>
                    <span>👥 <?= number_format($rep['participants']) ?> คน</span>
                    <span style="color:var(--status-red);font-weight:600"><?= formatThaiAmount($rep['budget_spent']) ?></span>
                    <?php if ($canEdit): ?>
                    <span style="color:var(--border)">|</span>
                    <a href="edit_activity_report.php?id=<?= $rep['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem;font-size:0.75rem;line-height:1;min-height:28px;display:inline-flex;align-items:center;" onclick="event.stopPropagation();">✏️ แก้ไข</a>
                    <a href="delete_activity_report.php?id=<?= $rep['id'] ?>&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem;font-size:0.75rem;line-height:1;min-height:28px;display:inline-flex;align-items:center;color:var(--status-red);border-color:var(--status-red)" onclick="event.stopPropagation(); return confirm('⚠️ ต้องการลบรายงานผลครั้งนี้ใช่หรือไม่? การลบจะลบไฟล์แนบและประวัติงบประมาณทั้งหมดของรายงานนี้และไม่สามารถกู้คืนได้');">❌ ลบ</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($rep['summary']): ?>
            <div style="font-size:0.875rem;line-height:1.6;color:var(--text-main);margin-bottom:0.5rem">
                <?= nl2br(htmlspecialchars($rep['summary'])) ?>
            </div>
            <?php endif; ?>
            <!-- Attachments -->
            <?php
            $fa = $pdo->prepare("SELECT * FROM attachments WHERE entity_type='activity_report' AND entity_id=?");
            $fa->execute([$rep['id']]);
            $files = $fa->fetchAll();
            if ($files):
            ?>
            <div class="file-list" style="margin-top:0.75rem">
                <?php foreach ($files as $f): 
                    $fPath = "uploads/" . htmlspecialchars($f['file_path']);
                    $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                    $type = isImage($f['file_name']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other');
                ?>
                <div class="file-item">
                    <?php if ($type === 'image'): ?>
                        <img src="<?= $fPath ?>" class="img-preview" onclick="openPopup('<?= $fPath ?>', 'image')" alt="Preview">
                    <?php else: ?>
                        <span style="cursor:pointer" onclick="openPopup('<?= $fPath ?>', '<?= $type ?>')"><?= getFileIcon($f['file_name']) ?></span>
                    <?php endif; ?>
                    <div style="display:flex;align-items:center;gap:0.5rem">
                        <a href="javascript:void(0)" onclick="openPopup('<?= $fPath ?>', '<?= $type ?>')">
                            <?= htmlspecialchars($f['file_name']) ?>
                        </a>
                        <?php if ($canEdit): ?>
                        <a href="edit_attachment.php?id=<?= $f['id'] ?>" style="color:var(--text-muted);font-size:0.75rem;text-decoration:none" title="จัดการไฟล์">⚙️</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

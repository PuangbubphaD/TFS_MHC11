<?php
// Print-friendly project progress report (Updated for 2-level structure)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$project_id = intval($_GET['id'] ?? 0);
if (!$project_id) { header('Location: index.php'); exit; }

// Fetch project
$stmt = $pdo->prepare("SELECT p.*, u.full_name AS owner, u.department FROM projects p JOIN users u ON p.user_id=u.id WHERE p.id=?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { header('Location: index.php'); exit; }

// Access check
if ($_SESSION['role'] === 'staff' && $project['user_id'] != $_SESSION['user_id']) { header('Location: index.php'); exit; }

// Fetch 2 project phases
$phases = $pdo->prepare("SELECT * FROM project_phases WHERE project_id=? ORDER BY phase_number");
$phases->execute([$project_id]);
$phases = $phases->fetchAll();

// Fetch activities with progress
$acts = $pdo->prepare("
    SELECT a.*, 
           (SELECT COUNT(*) FROM activity_phases WHERE activity_id=a.id AND status='completed') AS completed_phases,
           (SELECT COALESCE(SUM(budget_spent),0) FROM activity_reports WHERE activity_id=a.id) AS spent,
           (SELECT COALESCE(SUM(participants),0) FROM activity_reports WHERE activity_id=a.id) AS total_participants
    FROM activities a
    WHERE a.project_id=? ORDER BY a.planned_start
");
$acts->execute([$project_id]);
$activities = $acts->fetchAll();

// Budget Summary
$totalSpent = array_sum(array_column($activities, 'spent'));
$pct        = budgetPercent($totalSpent, $project['budget_total']);

$statusTH = ['pending'=>'รอดำเนินการ','in_progress'=>'กำลังดำเนินการ','completed'=>'เสร็จสิ้น','overdue'=>'เกินกำหนด','planned'=>'วางแผนแล้ว','ongoing'=>'กำลังดำเนิน','cancelled'=>'ยกเลิก'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานโครงการ: <?= htmlspecialchars($project['title']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Sarabun',sans-serif; font-size:14px; color:#1a1a1a; background:#fff; }
        .container { max-width:900px; margin:0 auto; padding:2rem; }
        .report-header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:1.5rem; border-bottom:3px solid #6f35a5; margin-bottom:2rem; }
        .report-logo { display:flex; align-items:center; gap:0.75rem; }
        .logo-box { width:48px; height:48px; background:#6f35a5; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; }
        .logo-text h1 { font-size:1.5rem; font-weight:800; color:#6f35a5; }
        .logo-text p  { font-size:0.75rem; color:#666; }
        .report-meta { text-align:right; font-size:0.8rem; color:#666; }
        .report-meta strong { color:#1a1a1a; }
        .project-title-box { background:linear-gradient(135deg,#6f35a5,#8a44d6); color:#fff; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem; }
        .project-title-box h2 { font-size:1.4rem; font-weight:800; margin-bottom:0.75rem; }
        .section { margin-bottom:1.75rem; }
        .section-title { font-size:1rem; font-weight:800; color:#6f35a5; border-left:4px solid #6f35a5; padding-left:0.75rem; margin-bottom:1rem; }
        .info-table { width:100%; border-collapse:collapse; font-size:0.875rem; }
        .info-table tr td { padding:0.5rem 0.75rem; border-bottom:1px solid #e2e8f0; }
        .info-table tr td:first-child { color:#666; width:35%; font-weight:600; }
        .budget-box { background:#f7fafc; border-radius:10px; padding:1rem; }
        .phase-table { width:100%; border-collapse:collapse; font-size:0.85rem; margin-bottom:1rem; }
        .phase-table th { padding:0.6rem; text-align:left; background:#f0f4f8; border-bottom:2px solid #e2e8f0; }
        .phase-table td { padding:0.6rem; border-bottom:1px solid #e2e8f0; vertical-align:top; }
        .status-tag { display:inline-block; padding:0.15rem 0.5rem; border-radius:999px; font-size:0.7rem; font-weight:700; color:#fff; }
        .act-block { border:1.5px solid #e2e8f0; border-radius:10px; padding:1rem; margin-bottom:1rem; page-break-inside: avoid; }
        .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; border:1px solid rgba(0,0,0,0.1); flex-shrink:0; }
        .signature-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:2rem; margin-top:3rem; }
        .sig-box { text-align:center; }
        .sig-line { border-top:1px solid #1a1a1a; margin:0.5rem 0; }
        .no-print { margin-bottom:1.5rem; display:flex; gap:0.75rem; }
        .btn-print { background:#1a237e; color:#fff; border:none; padding:0.6rem 1.2rem; border-radius:8px; cursor:pointer; }
        @media print { 
            .no-print { display:none; } 
            body, th, td, p, div, span, strong, h1, h2, h3, h4, h5, h6 { color: #000 !important; }
            .status-tag { color: #fff !important; }
            .project-title-box { background: none !important; color: #000 !important; border: 1px solid #000 !important; padding: 0.5rem !important; margin-bottom: 0.5rem !important; border-radius:6px; }
            .section-title { color: #000 !important; border-left-color: #000 !important; margin-bottom: 0.4rem !important; font-size: 0.95rem; }
            .dot { border-color: #000 !important; }
            .act-block, .info-table tr td, .phase-table th, .phase-table td { border-color: #000 !important; }
            .report-header { padding-bottom: 0.25rem !important; margin-bottom: 0.5rem !important; border-bottom-color: #000 !important; }
            .section { margin-bottom: 0.5rem !important; }
            .act-block { padding: 0.4rem 0.5rem !important; margin-bottom: 0.3rem !important; }
            .info-table tr td { padding: 0.2rem 0.5rem !important; }
            .phase-table th, .phase-table td { padding: 0.25rem 0.5rem !important; }
            .budget-box { padding: 0.5rem !important; margin-bottom: 0.5rem !important; }
            .signature-row { margin-top: 0.75rem !important; gap: 1rem !important; }
            body { font-size: 13px; }
            @page {
                size: A4;
                margin: 10mm 10mm 10mm 10mm;
                @top-right {
                    content: "หน้า " counter(page) " / " counter(pages);
                    font-size: 10px;
                }
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ พิมพ์รายงาน</button>
        <a href="view_project.php?id=<?= $project_id ?>" style="text-decoration:none;color:#666">← กลับ</a>
    </div>

    <div class="report-header">
        <div class="report-logo">
            <img src="assets/images/logo_report.png" alt="DMH Logo" style="max-height: 80px; width: auto; object-fit: contain;">
        </div>
        <div class="report-meta">
            <div style="font-size:0.95rem; color:#6f35a5; font-weight:800; margin-bottom:0.25rem;" class="system-name">ระบบรายงานและติดตามการดำเนินงาน</div>
            <div><strong>รายงานสรุปผลการดำเนินโครงการ</strong></div>
            <div>วันที่พิมพ์: <?= thaiDate(date('Y-m-d')) ?></div>
        </div>
    </div>

    <div class="project-title-box">
        <h2><?= htmlspecialchars($project['title']) ?></h2>
        <p style="font-size:0.85rem">ผู้รับผิดชอบ: <?= htmlspecialchars($project['owner']) ?> (<?= htmlspecialchars($project['department'] ?? '-') ?>)</p>
    </div>

    <div class="section">
        <div class="section-title">📋 สถานะการบริหารโครงการ (2 ขั้นตอนหลัก)</div>
        <table class="phase-table">
            <thead>
                <tr>
                    <th>ขั้นตอน</th>
                    <th>สถานะ</th>
                    <th>กำหนดเสร็จ</th>
                    <th>วันที่เสร็จจริง</th>
                    <th>หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($phases as $ph): 
                    $sc = ['pending'=>'#718096','in_progress'=>'#3182ce','completed'=>'#38a169','overdue'=>'#e53e3e'][$ph['status']] ?? '#718096';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ph['phase_name']) ?></strong></td>
                    <td><span class="status-tag" style="background:<?= $sc ?>"><?= $statusTH[$ph['status']] ?></span></td>
                    <td><?= $ph['deadline_date'] ? thaiDate($ph['deadline_date']) : '-' ?></td>
                    <td><?= $ph['completed_date'] ? thaiDate($ph['completed_date']) : '-' ?></td>
                    <td style="font-size:0.75rem"><?= htmlspecialchars($ph['notes'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">💰 สรุปงบประมาณและกิจกรรมภาพรวม</div>
        <div class="budget-box">
            <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
                <span>งบประมาณตั้งไว้: <strong><?= formatThaiAmount($project['budget_total']) ?></strong></span>
                <span>เบิกจ่ายแล้ว: <strong style="color:#c62828"><?= formatThaiAmount($totalSpent) ?></strong> (<?= $pct ?>%)</span>
            </div>
            <div style="background:#e2e8f0;height:10px;border-radius:5px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:#6f35a5"></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">📌 รายละเอียดความคืบหน้ากิจกรรมย่อย (8 ขั้นตอนปฏิบัติ)</div>
        <?php foreach ($activities as $idx => $act): 
            $act_pct = round(($act['completed_phases'] / 8) * 100);
            $act_ph = $pdo->prepare("SELECT phase_name, status FROM activity_phases WHERE activity_id=? ORDER BY phase_number");
            $act_ph->execute([$act['id']]);
            $aphases = $act_ph->fetchAll();
        ?>
        <div class="act-block" <?= ($idx > 0 && $idx % 2 == 0) ? 'style="break-before: page; page-break-before: always;"' : '' ?>>
            <div style="display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding-bottom:0.5rem;margin-bottom:0.75rem">
                <strong><?= $idx+1 ?>. <?= htmlspecialchars($act['activity_name']) ?></strong>
                <span style="font-weight:700;color:#6f35a5"><?= $act_pct ?>%</span>
            </div>
            <div style="display:grid;grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.75rem; line-height: 1.4;">
                <?php foreach ($aphases as $i => $ap): 
                    $dotC = ['pending'=>'#cbd5e0','in_progress'=>'#3182ce','completed'=>'#38a169','overdue'=>'#e53e3e'][$ap['status']];
                ?>
                <div style="display:flex;align-items:center">
                    <span class="dot" style="background:<?= $dotC ?>"></span>
                    <span style="color:<?= $ap['status']==='pending'?'#999':'#333' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($ap['phase_name']) ?>">
                        ขั้นที่ <?= $i+1 ?>: <?= htmlspecialchars($ap['phase_name']) ?> <span style="font-size:0.7rem;color:#777">(<?= $statusTH[$ap['status']] ?>)</span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:0.75rem;font-size:0.75rem;color:#666">
                งบที่ได้รับจัดสรร: <?= formatThaiAmount($act['planned_budget']) ?> | เบิกจ่ายแล้ว: <?= formatThaiAmount($act['spent']) ?> | ผู้เข้าร่วม: <?= number_format($act['total_participants']) ?> คน
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="signature-row">
        <div class="sig-box"><div style="height:40px"></div><div class="sig-line"></div><div>ผู้รับผิดชอบโครงการ</div></div>
        <div class="sig-box"><div style="height:40px"></div><div class="sig-line"></div><div>หัวหน้ากุล่มงานวิชาการสุขภาพจิต</div></div>
        <div class="sig-box"><div style="height:40px"></div><div class="sig-line"></div><div>ผู้อำนวยการศูนย์สุขภาพจิตที่ 11</div></div>
    </div>
</div>
</body>
</html>

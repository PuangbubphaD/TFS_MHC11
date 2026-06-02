<?php
require_once __DIR__ . '/includes/header.php';

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id=?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { header('Location: index.php'); exit; }

// Access check: Only owner or admin can add activity
if ($project['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $name        = trim($_POST['activity_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $plan_start  = $_POST['planned_start'] ?? '';
    $plan_end    = $_POST['planned_end'] ?? '';
    $plan_part   = intval($_POST['planned_participants'] ?? 0);
    $plan_budget = floatval($_POST['planned_budget'] ?? 0);

    if (!$name) {
        $error = 'กรุณากรอกชื่อกิจกรรม';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO activities (project_id, activity_name, description, location, planned_start, planned_end, planned_participants, planned_budget, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'planned')
        ");
        $stmt->execute([$project_id, $name, $description, $location, $plan_start ?: null, $plan_end ?: null, $plan_part, $plan_budget]);
        $aid = $pdo->lastInsertId();

        $act_phases = [
            [1, 'ขออนุมัติจัดกิจกรรม', 'ยื่นขออนุมัติและให้ผู้อำนวยการลงนาม ไม่น้อยกว่า 35 วันทำการก่อนจัดกิจกรรม', 35, 'before'],
            [2, 'ขออนุมัติงบประมาณและพัสดุ', 'ยืมเงินโครงการและจัดซื้อ/จัดจ้างพัสดุ ไม่น้อยกว่า 28 วันทำการก่อนจัดกิจกรรม', 28, 'before'],
            [3, 'ขออนุมัติบุคลากรและเชิญผู้ร่วม', 'เชิญผู้เข้าร่วมโครงการไม่น้อยกว่า 14 วันทำการก่อนจัดกิจกรรม และเตรียมคณะทำงานไม่น้อยกว่า 7 วันทำการก่อนจัดกิจกรรม', 14, 'before'],
            [4, 'ดำเนินการจัดกิจกรรม', 'จัดกิจกรรมและกำกับดูแลให้เป็นไปตามแผน', 0, 'on'],
            [5, 'การจัดทำ onepage', 'จัดทำเอกสารสรุปกิจกรรมหน้าเดียว (One Page Summary)', 1, 'after'],
            [6, 'เบิกจ่ายงบประมาณ', 'ยื่นเอกสารเบิกจ่ายต่องานการเงิน', 7, 'after'],
            [7, 'สรุปกิจกรรม', 'จัดทำรายงานสรุปผลการจัดกิจกรรม', 10, 'after'],
            [8, 'ผู้อำนวยการลงนามอนุมัติสรุป', 'ผู้อำนวยการลงนามในรายงานสรุปผลกิจกรรม', 14, 'after'],
        ];

        foreach ($act_phases as [$num, $pname, $pdesc, $pdays, $ptype]) {
            $deadline = null;
            if (!empty($plan_start)) {
                $deadline = getWorkingDayDeadline($pdo, $plan_start, $pdays, $ptype);
            }
            $pdo->prepare("INSERT INTO activity_phases (activity_id, phase_number, phase_name, description, deadline_date, status) VALUES (?, ?, ?, ?, ?, 'pending')")
                ->execute([$aid, $num, $pname, $pdesc, $deadline]);
        }

        header("Location: view_activity.php?id=$aid&created=1");
        exit;
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>➕ เพิ่มกิจกรรมใหม่</h2>
        <div class="topbar-breadcrumb">
            <a href="index.php" style="color:var(--text-muted)">โครงการ</a> /
            <a href="view_project.php?id=<?= $project_id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($project['title']) ?></a> /
            เพิ่มกิจกรรม
        </div>
    </div>
    </div>
    <a href="view_project.php?id=<?= $project_id ?>" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <?php if ($error): ?>
    <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2" style="align-items:start">
        <div class="card fade-in">
            <div class="card-header"><h3>📌 ข้อมูลกิจกรรม</h3></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-group">
                    <label>ชื่อกิจกรรม <span class="required">*</span></label>
                    <input type="text" name="activity_name" class="form-control"
                           placeholder="เช่น อบรมเชิงปฏิบัติการ, สัมมนา, ประชุมเชิงปฏิบัติการ"
                           required value="<?= htmlspecialchars($_POST['activity_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>รายละเอียดกิจกรรม</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="อธิบายวัตถุประสงค์และรูปแบบกิจกรรม"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>📍 สถานที่จัด</label>
                    <input type="text" name="location" class="form-control"
                           placeholder="เช่น ห้องประชุมใหญ่, โรงแรม ABC, ออนไลน์ (Zoom)"
                           value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>วันที่วางแผนเริ่ม</label>
                        <input type="date" name="planned_start" class="form-control"
                               value="<?= htmlspecialchars($_POST['planned_start'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>วันที่วางแผนสิ้นสุด</label>
                        <input type="date" name="planned_end" class="form-control"
                               value="<?= htmlspecialchars($_POST['planned_end'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>👥 จำนวนผู้เข้าร่วม (วางแผน)</label>
                        <input type="number" name="planned_participants" class="form-control"
                               placeholder="0" min="0"
                               value="<?= htmlspecialchars($_POST['planned_participants'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>💰 งบประมาณที่ตั้งไว้ (บาท)</label>
                        <input type="number" name="planned_budget" class="form-control"
                               placeholder="0.00" step="0.01" min="0"
                               value="<?= htmlspecialchars($_POST['planned_budget'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-hint" style="margin-bottom:1rem">
                    💡 หมายเหตุ: กิจกรรมนี้สามารถมีรายงานได้หลายครั้ง (หลายวัน/หลายสถานที่) ผ่านปุ่ม "เพิ่มรายงานผล"
                </div>
                <div style="display:flex;gap:0.75rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกกิจกรรม</button>
                    <a href="view_project.php?id=<?= $project_id ?>" class="btn btn-outline">ยกเลิก</a>
                </div>
            </form>
        </div>

        <div class="card fade-in" style="background:#f0f9ff;border:2px solid var(--accent)">
            <h3 style="margin-bottom:1rem">💡 คำแนะนำ</h3>
            <div style="font-size:0.875rem;line-height:1.7;color:var(--text-main)">
                <p><strong>โครงการ → กิจกรรม → รายงานผล</strong></p>
                <ul style="margin-top:0.75rem;padding-left:1.25rem;color:var(--text-muted)">
                    <li>กิจกรรมคือหน่วยย่อยของโครงการ</li>
                    <li>กิจกรรม 1 รายการ สามารถจัดได้ <strong>หลายครั้ง</strong></li>
                    <li>แต่ละครั้งที่จัดให้เพิ่ม <strong>"รายงานผล"</strong> เพื่อบันทึก:
                        <ul style="margin-top:0.3rem">
                            <li>วันที่จัดจริง</li>
                            <li>สถานที่จัดจริง</li>
                            <li>จำนวนผู้เข้าร่วมจริง</li>
                            <li>งบประมาณที่ใช้จริง</li>
                            <li>ภาพและเอกสารประกอบ</li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

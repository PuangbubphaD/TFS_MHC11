<?php
require_once __DIR__ . '/includes/header.php';

$activity_id = intval($_GET['id'] ?? 0);
if (!$activity_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT a.*, p.title AS project_title, p.id AS project_id, p.user_id AS project_owner
    FROM activities a JOIN projects p ON p.id=a.project_id WHERE a.id=? AND a.deleted_at IS NULL AND p.deleted_at IS NULL
");
$stmt->execute([$activity_id]);
$activity = $stmt->fetch();
if (!$activity) { header('Location: index.php'); exit; }

// Access check: Only owner or admin can edit
if ($activity['project_owner'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $pdo->prepare("UPDATE activities SET deleted_at = NOW() WHERE id = ?")->execute([$activity_id]);
        logActivity($pdo, 'DELETE_ACTIVITY', "ลบกิจกรรมแบบ Soft Delete: " . $activity['activity_name']);
        header('Location: view_project.php?id=' . $activity['project_id']);
        exit;
    }

    $name        = trim($_POST['activity_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $plan_start  = $_POST['planned_start'] ?? '';
    $plan_end    = $_POST['planned_end'] ?? '';
    $plan_part   = intval($_POST['planned_participants'] ?? 0);
    $plan_budget = floatval($_POST['planned_budget'] ?? 0);
    $status      = $_POST['status'] ?? $activity['status'];

    if (!$name) {
        $error = 'กรุณากรอกชื่อกิจกรรม';
    } else {
        $old_start = $activity['planned_start'];
        $pdo->prepare("UPDATE activities SET activity_name=?,description=?,location=?,planned_start=?,planned_end=?,planned_participants=?,planned_budget=?,status=? WHERE id=?")
            ->execute([$name,$description,$location,$plan_start?:null,$plan_end?:null,$plan_part,$plan_budget,$status,$activity_id]);

        // Recalculate deadlines if date changed and is not empty
        if (!empty($plan_start) && $plan_start !== $old_start) {
            $start_dt = new DateTime($plan_start);
            $rules = [
                1 => ['days' => 35, 'type' => 'before'],
                2 => ['days' => 28, 'type' => 'before'],
                3 => ['days' => 14, 'type' => 'before'],
                4 => ['days' => 0,  'type' => 'on'],
                5 => ['days' => 7,  'type' => 'after'],
                6 => ['days' => 10, 'type' => 'after'],
            ];

            foreach ($rules as $num => $rule) {
                $deadline = clone $start_dt;
                if ($rule['type'] === 'before') $deadline->modify("-{$rule['days']} days");
                elseif ($rule['type'] === 'after') $deadline->modify("+{$rule['days']} days");
                
                $pdo->prepare("UPDATE activity_phases SET deadline_date=? WHERE activity_id=? AND phase_number=?")
                    ->execute([$deadline->format('Y-m-d'), $activity_id, $num]);
            }
        }

        header("Location: view_activity.php?id=$activity_id");
        exit;
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>✏️ แก้ไขกิจกรรม</h2>
        <div class="topbar-breadcrumb">
            <a href="view_project.php?id=<?= $activity['project_id'] ?>" style="color:var(--text-muted)"><?= htmlspecialchars($activity['project_title']) ?></a> /
            <a href="view_activity.php?id=<?= $activity_id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($activity['activity_name']) ?></a> / แก้ไข
        </div>
    </div>
    </div>
    <a href="view_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <?php if ($error): ?>
    <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card fade-in" style="max-width:700px">
        <div class="card-header"><h3>📝 แก้ไขข้อมูลกิจกรรม</h3></div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <div class="form-group">
                <label>ชื่อกิจกรรม <span class="required">*</span></label>
                <input type="text" name="activity_name" class="form-control" required
                       value="<?= htmlspecialchars($_POST['activity_name'] ?? $activity['activity_name']) ?>">
            </div>
            <div class="form-group">
                <label>รายละเอียด</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($_POST['description'] ?? $activity['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>📍 สถานที่</label>
                <input type="text" name="location" class="form-control"
                       value="<?= htmlspecialchars($_POST['location'] ?? $activity['location'] ?? '') ?>">
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>วันที่เริ่ม</label>
                    <input type="date" name="planned_start" class="form-control"
                           value="<?= $_POST['planned_start'] ?? $activity['planned_start'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label>วันที่สิ้นสุด</label>
                    <input type="date" name="planned_end" class="form-control"
                           value="<?= $_POST['planned_end'] ?? $activity['planned_end'] ?? '' ?>">
                </div>
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label>👥 ผู้เข้าร่วม (วางแผน)</label>
                    <input type="number" name="planned_participants" class="form-control" min="0"
                           value="<?= $_POST['planned_participants'] ?? $activity['planned_participants'] ?>">
                </div>
                <div class="form-group">
                    <label>💰 งบประมาณ (บาท)</label>
                    <input type="number" name="planned_budget" class="form-control" step="0.01" min="0"
                           value="<?= $_POST['planned_budget'] ?? $activity['planned_budget'] ?>">
                </div>
            </div>
            <div class="form-group">
                <label>สถานะ</label>
                <select name="status" class="form-control">
                    <option value="planned"   <?= ($activity['status']==='planned')?'selected':'' ?>>วางแผนแล้ว</option>
                    <option value="ongoing"   <?= ($activity['status']==='ongoing')?'selected':'' ?>>กำลังดำเนินการ</option>
                    <option value="completed" <?= ($activity['status']==='completed')?'selected':'' ?>>เสร็จสิ้น</option>
                    <option value="cancelled" <?= ($activity['status']==='cancelled')?'selected':'' ?>>ยกเลิก</option>
                </select>
            </div>
            <div style="display:flex;gap:0.75rem">
                <button type="submit" class="btn btn-primary">💾 บันทึก</button>
                <a href="view_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">ยกเลิก</a>
            </div>
        </form>
    </div>

    <div class="card fade-in" style="max-width:700px; margin-top:1.5rem; background:#fff5f5; border:2px solid #fed7d7">
        <h3 style="margin-bottom:0.5rem; color:var(--status-red)">🗑️ ลบกิจกรรม</h3>
        <p style="font-size:0.875rem; line-height:1.7; color:var(--text-muted); margin-bottom:0.75rem">
            หากต้องการลบกิจกรรมนี้ ข้อมูลสรุปและรายงานผลลัพธ์ทั้งหมดจะถูกซ่อนออกจากระบบการติดตาม
        </p>
        <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบกิจกรรมนี้?');">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:0.5rem">🗑️ ลบกิจกรรมนี้</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/header.php';

$project_id = intval($_GET['id'] ?? 0);
if (!$project_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id=? AND deleted_at IS NULL");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) { header('Location: index.php'); exit; }

// Only owner or admin can edit
if ($project['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); exit;
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $pdo->prepare("UPDATE projects SET deleted_at = NOW() WHERE id = ?")->execute([$project_id]);
        $pdo->prepare("UPDATE activities SET deleted_at = NOW() WHERE project_id = ?")->execute([$project_id]);
        logActivity($pdo, 'DELETE_PROJECT', "ลบโครงการแบบ Soft Delete: " . $project['title']);
        header('Location: index.php');
        exit;
    }

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $budget      = floatval($_POST['budget_total'] ?? 0);
    $start_date  = $_POST['start_date'] ?? '';
    $end_date    = $_POST['end_date'] ?? '';
    $status      = $_POST['status'] ?? $project['status'];

    if (!$title) {
        $error = 'กรุณากรอกชื่อโครงการ';
    } else {
        $old_start = $project['start_date'];
        
        $pdo->prepare("UPDATE projects SET title=?,description=?,budget_total=?,start_date=?,end_date=?,status=? WHERE id=?")
            ->execute([$title, $description, $budget, $start_date ?: null, $end_date ?: null, $status, $project_id]);

        // If start_date was updated and is now set, recalculate all phase deadlines
        if (!empty($start_date) && $start_date !== $old_start) {
            $start_dt = new DateTime($start_date);
            $phase_rules = [1=>0, 2=>15]; // Note: - means after
            
            foreach ($phase_rules as $num => $days) {
                $deadline = clone $start_dt;
                if ($days > 0) {
                    $deadline->modify("-{$days} days");
                } elseif ($days < 0) {
                    $deadline->modify("+" . abs($days) . " days");
                }
                
                $pdo->prepare("UPDATE project_phases SET deadline_date=? WHERE project_id=? AND phase_number=?")
                    ->execute([$deadline->format('Y-m-d'), $project_id, $num]);
            }
        }

        // If status changed to completed, mark remaining phases
        if ($status === 'completed') {
            $pdo->prepare("UPDATE project_phases SET status='completed', completed_date=CURDATE() WHERE project_id=? AND status NOT IN ('completed')")
                ->execute([$project_id]);
        }

        header("Location: view_project.php?id=$project_id");
        exit;
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>✏️ แก้ไขโครงการ</h2>
        <div class="topbar-breadcrumb">
            <a href="index.php" style="color:var(--text-muted)">โครงการ</a> /
            <a href="view_project.php?id=<?= $project_id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($project['title']) ?></a> / แก้ไข
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
            <div class="card-header"><h3>📝 แก้ไขข้อมูลโครงการ</h3></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-group">
                    <label>ชื่อโครงการ <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           value="<?= htmlspecialchars($_POST['title'] ?? $project['title']) ?>">
                </div>
                <div class="form-group">
                    <label>รายละเอียดโครงการ</label>
                    <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($_POST['description'] ?? $project['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>งบประมาณโครงการ (บาท)</label>
                    <input type="number" name="budget_total" class="form-control" step="0.01" min="0"
                           value="<?= htmlspecialchars($_POST['budget_total'] ?? $project['budget_total']) ?>">
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>วันที่เริ่ม</label>
                        <input type="date" name="start_date" class="form-control"
                               value="<?= $_POST['start_date'] ?? $project['start_date'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label>วันที่สิ้นสุด</label>
                        <input type="date" name="end_date" class="form-control"
                               value="<?= $_POST['end_date'] ?? $project['end_date'] ?? '' ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>สถานะโครงการ</label>
                    <select name="status" class="form-control">
                        <option value="draft"     <?= ($project['status']==='draft')?'selected':'' ?>>📄 ร่าง</option>
                        <option value="active"    <?= ($project['status']==='active')?'selected':'' ?>>▶️ กำลังดำเนินการ</option>
                        <option value="completed" <?= ($project['status']==='completed')?'selected':'' ?>>✅ เสร็จสิ้น</option>
                        <option value="cancelled" <?= ($project['status']==='cancelled')?'selected':'' ?>>❌ ยกเลิก</option>
                    </select>
                </div>
                <div style="display:flex;gap:0.75rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกการแก้ไข</button>
                    <a href="view_project.php?id=<?= $project_id ?>" class="btn btn-outline">ยกเลิก</a>
                </div>
            </form>
        </div>

        <div class="card fade-in" style="background:#fff5f5;border:2px solid #fed7d7">
            <h3 style="margin-bottom:0.75rem;color:var(--status-red)">⚠️ คำเตือน</h3>
            <p style="font-size:0.875rem;line-height:1.7;color:var(--text-muted)">
                การเปลี่ยนสถานะเป็น <strong>"เสร็จสิ้น"</strong> จะทำให้ขั้นตอนที่ยังค้างอยู่ทั้งหมด
                ถูกมาร์คว่าเสร็จโดยอัตโนมัติ
            </p>
            <hr style="border:none;border-top:1px solid #fed7d7;margin:0.75rem 0">
            <p style="font-size:0.875rem;line-height:1.7;color:var(--text-muted)">
                หากต้องการปรับ <strong>กำหนดการแต่ละขั้นตอน</strong> ให้ไปที่หน้าโครงการ
                แล้วกด <strong>"✏️ อัปเดต"</strong> ที่ขั้นตอนนั้นๆ
            </p>
            <hr style="border:none;border-top:1px solid #fed7d7;margin:0.75rem 0">
            <h4 style="color:var(--status-red);margin-bottom:0.5rem">🗑️ ลบโครงการ</h4>
            <p style="font-size:0.875rem;line-height:1.7;color:var(--text-muted);margin-bottom:0.75rem">
                หากลบโครงการนี้ กิจกรรมและสรุปรายงานที่เกี่ยวข้องจะถูกซ่อนออกจากระบบ
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบโครงการนี้? กิจกรรมที่เกี่ยวข้องทั้งหมดจะถูกลบออกไปด้วย');">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center;display:flex;align-items:center;gap:0.5rem">🗑️ ลบโครงการนี้</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

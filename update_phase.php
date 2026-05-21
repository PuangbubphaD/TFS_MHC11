<?php
require_once __DIR__ . '/includes/header.php';

$phase_id = intval($_GET['id'] ?? 0);
$type     = $_GET['type'] ?? 'project'; // 'project' or 'activity'
if (!$phase_id) { header('Location: index.php'); exit; }

if ($type === 'activity') {
    $stmt = $pdo->prepare("
        SELECT ph.*, a.activity_name AS parent_title, a.id AS activity_id, a.project_id, p.user_id AS project_owner
        FROM activity_phases ph
        JOIN activities a ON a.id = ph.activity_id
        JOIN projects p ON p.id = a.project_id
        WHERE ph.id = ?
    ");
    $back_url = "view_activity.php?id=";
} else {
    $stmt = $pdo->prepare("
        SELECT ph.*, p.title AS parent_title, p.id AS project_id, p.user_id AS project_owner
        FROM project_phases ph
        JOIN projects p ON p.id = ph.project_id
        WHERE ph.id = ?
    ");
    $back_url = "view_project.php?id=";
}

$stmt->execute([$phase_id]);
$phase = $stmt->fetch();
if (!$phase) { header('Location: index.php'); exit; }

// Access check
if ($_SESSION['role'] === 'staff' && $phase['project_owner'] != $_SESSION['user_id']) {
    header('Location: index.php'); exit;
}

$error = '';
$back_target = ($type === 'activity') ? $back_url . $phase['activity_id'] : $back_url . $phase['project_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $status         = $_POST['status'] ?? $phase['status'];
    $notes          = trim($_POST['notes'] ?? '');
    $completed_date = ($status === 'completed') ? (date('Y-m-d')) : null;

    $table = ($type === 'activity') ? 'activity_phases' : 'project_phases';
    $stmt = $pdo->prepare("UPDATE $table SET status=?, notes=?, completed_date=? WHERE id=?");
    $stmt->execute([$status, $notes, $completed_date, $phase_id]);

    // Handle file uploads
    $attach_type = ($type === 'activity') ? 'activity_phase' : 'phase';
    if (!empty($_FILES['attachments']['name'][0])) {
        foreach ($_FILES['attachments']['name'] as $i => $fname) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if (!isAllowedFile($fname)) continue;

            $safeName = time() . '_' . $i . '_' . preg_replace('/[^a-z0-9._-]/i', '_', $fname);
            $destPath = __DIR__ . '/uploads/' . $safeName;

            if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $destPath)) {
                $fs = $pdo->prepare("
                    INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $fs->execute([$attach_type, $phase_id, $fname, $safeName, $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i], $_SESSION['user_id']]);
            }
        }
    }

    header("Location: $back_target#phase-$phase_id");
    exit;
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>✏️ อัปเดต<?= $type === 'activity' ? 'กิจกรรม' : 'โครงการ' ?> ขั้นตอนที่ <?= $phase['phase_number'] ?></h2>
        <div class="topbar-breadcrumb">
            <a href="<?= $back_target ?>" style="color:var(--text-muted)"><?= htmlspecialchars($phase['parent_title']) ?></a> /
            <?= htmlspecialchars($phase['phase_name']) ?>
        </div>
    </div>
    </div>
    <a href="<?= $back_target ?>" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <div class="grid grid-2" style="align-items:start">
        <div class="card fade-in">
            <div class="card-header">
                <h3>🔄 อัปเดตสถานะ</h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-group">
                    <label>ขั้นตอนที่ <?= $phase['phase_number'] ?>: <?= htmlspecialchars($phase['phase_name']) ?></label>
                    <div style="background:#f7fafc;padding:0.75rem;border-radius:8px;font-size:0.85rem;color:var(--text-muted)">
                        <?= htmlspecialchars($phase['description']) ?>
                    </div>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>สถานะ</label>
                        <select name="status" class="form-control">
                            <option value="pending" <?= $phase['status']==='pending'?'selected':'' ?>>⏳ รอดำเนินการ</option>
                            <option value="in_progress" <?= $phase['status']==='in_progress'?'selected':'' ?>>🔄 กำลังดำเนินการ</option>
                            <option value="completed" <?= $phase['status']==='completed'?'selected':'' ?>>✅ เสร็จสิ้น</option>
                            <option value="overdue" <?= $phase['status']==='overdue'?'selected':'' ?>>🔴 เกินกำหนด</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>กำหนดการ</label>
                        <input type="text" class="form-control" readonly
                               value="<?= $phase['deadline_date'] ? thaiDate($phase['deadline_date']) : 'ไม่ได้ระบุ' ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>📝 บันทึก/หมายเหตุ</label>
                    <textarea name="notes" class="form-control" rows="4"
                               placeholder="บันทึกความคืบหน้า ปัญหาที่พบ หรือขั้นตอนที่ดำเนินการ"><?= htmlspecialchars($phase['notes'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>📎 แนบไฟล์/เอกสาร</label>
                    <div class="file-upload-area" onclick="document.getElementById('phaseFile').click()">
                        <input type="file" id="phaseFile" name="attachments[]" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
                        <div style="font-size:1.5rem;margin-bottom:0.3rem">📁</div>
                        <div style="font-weight:600;color:var(--primary);font-size:0.9rem">เลือกไฟล์แนบ</div>
                        <div style="font-size:0.78rem;color:var(--text-muted)">รูปภาพ, PDF, Word, Excel</div>
                    </div>
                    <div id="phaseFilePreview" class="file-list"></div>
                </div>

                <div style="display:flex;gap:0.75rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกการอัปเดต</button>
                    <a href="<?= $back_target ?>" class="btn btn-outline">ยกเลิก</a>
                </div>
            </form>
        </div>

        <div class="card fade-in">
            <h3 style="margin-bottom:1rem">📋 ไฟล์แนบที่มีอยู่</h3>
            <?php
            $attach_type = ($type === 'activity') ? 'activity_phase' : 'phase';
            $fa = $pdo->prepare("SELECT * FROM attachments WHERE entity_type=? AND entity_id=?");
            $fa->execute([$attach_type, $phase_id]);
            $files = $fa->fetchAll();
            ?>
            <?php if (empty($files)): ?>
            <div class="empty-state" style="padding:1.5rem">
                <div class="icon" style="font-size:2rem">📎</div>
                <p>ยังไม่มีไฟล์แนบ</p>
            </div>
            <?php else: ?>
            <?php foreach ($files as $f): ?>
            <div class="file-item">
                <?= getFileIcon($f['file_name']) ?>
                <a href="uploads/<?= htmlspecialchars($f['file_path']) ?>" target="_blank">
                    <?= htmlspecialchars($f['file_name']) ?>
                </a>
                <span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted)">
                    <?= number_format($f['file_size']/1024, 0) ?> KB
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('phaseFile').addEventListener('change', function() {
    const preview = document.getElementById('phaseFilePreview');
    preview.innerHTML = '';
    Array.from(this.files).forEach(f => {
        const item = document.createElement('div');
        item.className = 'file-item';
        item.innerHTML = `<span>📎</span><span>${f.name}</span><span style="margin-left:auto;color:var(--text-muted);font-size:0.75rem">${(f.size/1024).toFixed(0)} KB</span>`;
        preview.appendChild(item);
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

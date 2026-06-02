<?php
require_once __DIR__ . '/includes/header.php';

$report_id = intval($_GET['id'] ?? 0);
if (!$report_id) { header('Location: index.php'); exit; }

// Fetch report with activity & project details to verify permissions
$stmt = $pdo->prepare("
    SELECT ar.*, a.activity_name, a.planned_start, a.planned_end, a.planned_participants, a.planned_budget, a.location AS activity_location,
           p.title AS project_title, p.id AS project_id, p.user_id AS project_owner
    FROM activity_reports ar
    JOIN activities a ON ar.activity_id = a.id
    JOIN projects p ON a.project_id = p.id
    WHERE ar.id = ?
");
$stmt->execute([$report_id]);
$report = $stmt->fetch();
if (!$report) { header('Location: index.php'); exit; }

$activity_id = $report['activity_id'];
$canEdit = $report['project_owner'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin';

// Access check
if (!$canEdit) {
    header('Location: index.php'); exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $report_date  = $_POST['report_date'] ?? '';
    $location     = trim($_POST['location'] ?? '');
    $participants = intval($_POST['participants'] ?? 0);
    $budget_spent = floatval($_POST['budget_spent'] ?? 0);
    $summary      = trim($_POST['summary'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    if (!$report_date) {
        $error = 'กรุณาระบุวันที่จัด';
    } else {
        // Update report record
        $stmt = $pdo->prepare("
            UPDATE activity_reports
            SET report_date = ?, location = ?, participants = ?, budget_spent = ?, summary = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$report_date, $location ?: null, $participants, $budget_spent, $summary, $notes, $report_id]);

        // Handle additional file uploads if any
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $i => $fname) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if (!isAllowedFile($fname)) continue;

                $ext      = pathinfo($fname, PATHINFO_EXTENSION);
                $safeName = time() . '_' . $i . '_' . preg_replace('/[^a-z0-9._-]/i', '_', $fname);
                $destDir  = __DIR__ . '/uploads/';
                $destPath = $destDir . $safeName;

                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $destPath)) {
                    $fs = $pdo->prepare("
                        INSERT INTO attachments (entity_type, entity_id, file_name, file_path, file_type, file_size, uploaded_by)
                        VALUES ('activity_report', ?, ?, ?, ?, ?, ?)
                    ");
                    $fs->execute([$report_id, $fname, $safeName, $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i], $_SESSION['user_id']]);
                }
            }
        }

        // Log action in audit logs
        logActivity($pdo, 'UPDATE_ACTIVITY_REPORT', "แก้ไขรายงานผลกิจกรรม ID: $report_id ในกิจกรรม: " . $report['activity_name']);

        header("Location: view_activity.php?id=$activity_id&report_edited=1");
        exit;
    }
}

// Fetch current attachments for this report
$fa = $pdo->prepare("SELECT * FROM attachments WHERE entity_type='activity_report' AND entity_id=?");
$fa->execute([$report_id]);
$current_files = $fa->fetchAll();
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>✏️ แก้ไขรายงานผลกิจกรรม</h2>
            <div class="topbar-breadcrumb">
                <a href="view_project.php?id=<?= $report['project_id'] ?>" style="color:var(--text-muted)"><?= htmlspecialchars($report['project_title']) ?></a> /
                <a href="view_activity.php?id=<?= $activity_id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($report['activity_name']) ?></a> /
                แก้ไขรายงาน
            </div>
        </div>
    </div>
    <a href="view_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <?php if ($error): ?>
    <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2" style="align-items:start">
        <div class="card fade-in">
            <div class="card-header">
                <h3>📋 แบบฟอร์มแก้ไขข้อมูลการจัดกิจกรรม</h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>📅 วันที่จัดจริง <span class="required">*</span></label>
                        <input type="date" name="report_date" class="form-control" required
                               value="<?= htmlspecialchars($report['report_date']) ?>">
                    </div>
                    <div class="form-group">
                        <label>📍 สถานที่จัดจริง</label>
                        <input type="text" name="location" class="form-control"
                               placeholder="ระบุสถานที่"
                               value="<?= htmlspecialchars($report['location'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>👥 ผู้เข้าร่วมจริง (คน)</label>
                        <input type="number" name="participants" class="form-control"
                               placeholder="0" min="0"
                               value="<?= htmlspecialchars($report['participants']) ?>">
                    </div>
                    <div class="form-group">
                        <label>💰 งบที่ใช้จริง (บาท)</label>
                        <input type="number" name="budget_spent" class="form-control"
                               placeholder="0.00" step="0.01" min="0"
                               value="<?= htmlspecialchars($report['budget_spent']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>📝 สรุปผลการดำเนินงาน</label>
                    <textarea name="summary" class="form-control" rows="4"
                              placeholder="อธิบายผลการดำเนินงาน ผลลัพธ์ที่ได้ และสิ่งที่เกิดขึ้นในการจัดกิจกรรม"><?= htmlspecialchars($report['summary'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>📋 หมายเหตุเพิ่มเติม</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="ปัญหา อุปสรรค หรือข้อเสนอแนะ"><?= htmlspecialchars($report['notes'] ?? '') ?></textarea>
                </div>

                <!-- Multi-file upload -->
                <div class="form-group">
                    <label>📎 แนบไฟล์/รูปภาพเพิ่มเติม</label>
                    <div class="file-upload-area" id="dropZone" onclick="document.getElementById('fileInput').click()">
                        <input type="file" id="fileInput" name="attachments[]" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                        <div style="font-size:2rem;margin-bottom:0.5rem">📁</div>
                        <div style="font-weight:600;color:var(--primary)">คลิกเพื่อเลือกไฟล์เพิ่ม</div>
                        <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.25rem">
                            หรือลากไฟล์มาวางที่นี่ | รูปภาพ, PDF, Word, Excel, PowerPoint (สูงสุด 20MB/ไฟล์)
                        </div>
                    </div>
                    <div id="filePreview" class="file-list"></div>
                </div>

                <div style="display:flex;gap:0.75rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกการแก้ไข</button>
                    <a href="view_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">ยกเลิก</a>
                </div>
            </form>
        </div>

        <div>
            <!-- Current Attachments Control -->
            <div class="card fade-in mb-3">
                <div class="card-header">
                    <h3>📎 ไฟล์แนบปัจจุบันของรายงานนี้ (<?= count($current_files) ?> ไฟล์)</h3>
                </div>
                <?php if (empty($current_files)): ?>
                    <p style="color:var(--text-muted);font-size:0.85rem">ยังไม่มีไฟล์แนบในรายงานนี้</p>
                <?php else: ?>
                    <div class="file-list">
                        <?php foreach ($current_files as $f): 
                            $fPath = "uploads/" . htmlspecialchars($f['file_path']);
                            $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                            $type = isImage($f['file_name']) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other');
                        ?>
                        <div class="file-item" style="border: 1px solid var(--border); border-radius: 8px; padding: 0.6rem; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:0.5rem">
                                <?php if ($type === 'image'): ?>
                                    <img src="<?= $fPath ?>" class="img-preview" style="width:30px; height:30px; object-fit:cover; border-radius:4px" alt="Preview">
                                <?php else: ?>
                                    <span style="font-size:1.2rem"><?= getFileIcon($f['file_name']) ?></span>
                                <?php endif; ?>
                                <span style="font-size:0.82rem; font-weight:600; color:var(--text-main); text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:180px;">
                                    <?= htmlspecialchars($f['file_name']) ?>
                                </span>
                            </div>
                            <a href="edit_attachment.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.72rem; min-height:26px; display:inline-flex; align-items:center; gap:0.25rem;">
                                ⚙️ จัดการไฟล์
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Activity info sidebar -->
            <div class="card fade-in" style="background:#f7fafc">
                <h3 style="margin-bottom:0.75rem">📌 ข้อมูลเปรียบเทียบแผนงาน</h3>
                <div style="font-size:0.875rem;line-height:1.8">
                    <div><strong><?= htmlspecialchars($report['activity_name']) ?></strong></div>
                    <div style="color:var(--text-muted)"><?= htmlspecialchars($report['project_title']) ?></div>
                    <hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0">
                    <div>📅 ช่วงวางแผน: <?= thaiDate($report['planned_start']) ?> → <?= thaiDate($report['planned_end']) ?></div>
                    <div>👥 วางแผน: <?= number_format($report['planned_participants']) ?> คน</div>
                    <div>💰 งบที่ตั้งไว้: <?= formatThaiAmount($report['planned_budget']) ?></div>
                    <?php if ($report['activity_location']): ?>
                    <div>📍 สถานที่วางแผน: <?= htmlspecialchars($report['activity_location']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const filePreview = document.getElementById('filePreview');

fileInput.addEventListener('change', updatePreview);

['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.add('drag-over');
}));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => {
    ev.preventDefault(); dropZone.classList.remove('drag-over');
    if (e === 'drop') {
        fileInput.files = ev.dataTransfer.files;
        updatePreview();
    }
}));

function updatePreview() {
    filePreview.innerHTML = '';
    Array.from(fileInput.files).forEach(f => {
        const kb = (f.size/1024).toFixed(0);
        const item = document.createElement('div');
        item.className = 'file-item';
        item.innerHTML = `<span>📎</span><span>${f.name}</span><span style="margin-left:auto;color:var(--text-muted);font-size:0.75rem">${kb} KB</span>`;
        filePreview.appendChild(item);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

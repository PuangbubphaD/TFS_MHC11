<?php
require_once __DIR__ . '/includes/header.php';

$activity_id = intval($_GET['activity_id'] ?? 0);
if (!$activity_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT a.*, p.title AS project_title, p.id AS project_id, p.user_id AS project_owner
    FROM activities a
    JOIN projects p ON p.id = a.project_id
    WHERE a.id = ?
");
$stmt->execute([$activity_id]);
$activity = $stmt->fetch();
if (!$activity) { header('Location: index.php'); exit; }

// Access check
if ($_SESSION['role'] === 'staff' && $activity['project_owner'] != $_SESSION['user_id']) {
    header('Location: index.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $report_date = $_POST['report_date'] ?? '';
    $location    = trim($_POST['location'] ?? '');
    $participants = intval($_POST['participants'] ?? 0);
    $budget_spent = floatval($_POST['budget_spent'] ?? 0);
    $summary     = trim($_POST['summary'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if (!$report_date) {
        $error = 'กรุณาระบุวันที่จัด';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO activity_reports (activity_id, report_date, location, participants, budget_spent, summary, notes, reported_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$activity_id, $report_date, $location ?: null, $participants, $budget_spent, $summary, $notes, $_SESSION['user_id']]);
        $report_id = $pdo->lastInsertId();

        // Handle file uploads
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

        // Auto-update activity status to ongoing
        $pdo->prepare("UPDATE activities SET status='ongoing' WHERE id=? AND status='planned'")->execute([$activity_id]);

        header("Location: view_activity.php?id=$activity_id&reported=1");
        exit;
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>➕ เพิ่มรายงานผลกิจกรรม</h2>
        <div class="topbar-breadcrumb">
            <a href="view_project.php?id=<?= $activity['project_id'] ?>" style="color:var(--text-muted)"><?= htmlspecialchars($activity['project_title']) ?></a> /
            <a href="view_activity.php?id=<?= $activity_id ?>" style="color:var(--text-muted)"><?= htmlspecialchars($activity['activity_name']) ?></a> /
            เพิ่มรายงาน
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
                <h3>📊 รายงานผลการจัด</h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>📅 วันที่จัดจริง <span class="required">*</span></label>
                        <input type="date" name="report_date" class="form-control" required
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>📍 สถานที่จัดจริง</label>
                        <input type="text" name="location" class="form-control"
                               placeholder="ระบุสถานที่"
                               value="<?= htmlspecialchars($activity['location'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>👥 ผู้เข้าร่วมจริง (คน)</label>
                        <input type="number" name="participants" class="form-control"
                               placeholder="0" min="0"
                               value="<?= $activity['planned_participants'] ?>">
                    </div>
                    <div class="form-group">
                        <label>💰 งบที่ใช้จริง (บาท)</label>
                        <input type="number" name="budget_spent" class="form-control"
                               placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>📝 สรุปผลการดำเนินงาน</label>
                    <textarea name="summary" class="form-control" rows="4"
                              placeholder="อธิบายผลการดำเนินงาน ผลลัพธ์ที่ได้ และสิ่งที่เกิดขึ้นในการจัดกิจกรรม"></textarea>
                </div>
                <div class="form-group">
                    <label>📋 หมายเหตุเพิ่มเติม</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="ปัญหา อุปสรรค หรือข้อเสนอแนะ"></textarea>
                </div>

                <!-- Multi-file upload -->
                <div class="form-group">
                    <label>📎 แนบไฟล์/รูปภาพ</label>
                    <div class="file-upload-area" id="dropZone" onclick="document.getElementById('fileInput').click()">
                        <input type="file" id="fileInput" name="attachments[]" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                        <div style="font-size:2rem;margin-bottom:0.5rem">📁</div>
                        <div style="font-weight:600;color:var(--primary)">คลิกเพื่อเลือกไฟล์</div>
                        <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.25rem">
                            หรือลากไฟล์มาวางที่นี่ | รูปภาพ, PDF, Word, Excel, PowerPoint (สูงสุด 20MB/ไฟล์)
                        </div>
                    </div>
                    <div id="filePreview" class="file-list"></div>
                </div>

                <div style="display:flex;gap:0.75rem">
                    <button type="submit" class="btn btn-primary">💾 บันทึกรายงาน</button>
                    <a href="view_activity.php?id=<?= $activity_id ?>" class="btn btn-outline">ยกเลิก</a>
                </div>
            </form>
        </div>

        <!-- Activity info sidebar -->
        <div class="card fade-in" style="background:#f7fafc">
            <h3 style="margin-bottom:0.75rem">📌 ข้อมูลกิจกรรม</h3>
            <div style="font-size:0.875rem;line-height:1.8">
                <div><strong><?= htmlspecialchars($activity['activity_name']) ?></strong></div>
                <div style="color:var(--text-muted)"><?= htmlspecialchars($activity['project_title']) ?></div>
                <hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0">
                <div>📅 ช่วงวางแผน: <?= thaiDate($activity['planned_start']) ?> → <?= thaiDate($activity['planned_end']) ?></div>
                <div>👥 วางแผน: <?= number_format($activity['planned_participants']) ?> คน</div>
                <div>💰 งบที่ตั้งไว้: <?= formatThaiAmount($activity['planned_budget']) ?></div>
                <?php if ($activity['location']): ?>
                <div>📍 สถานที่วางแผน: <?= htmlspecialchars($activity['location']) ?></div>
                <?php endif; ?>
            </div>

            <div style="margin-top:1.25rem;padding:1rem;background:#fff3cd;border-radius:10px;font-size:0.82rem;color:#856404">
                💡 <strong>คุณสามารถเพิ่มรายงานได้หลายครั้ง</strong><br>
                แต่ละครั้งที่มีการจัดกิจกรรม ให้กลับมาเพิ่มรายงานใหม่ เพื่อบันทึกวันที่ สถานที่ และงบประมาณแยกกัน
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

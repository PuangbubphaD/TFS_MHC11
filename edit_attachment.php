<?php
require_once __DIR__ . '/includes/header.php';

$attachment_id = intval($_GET['id'] ?? 0);
if (!$attachment_id) { header('Location: gallery.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$attachment_id]);
$attachment = $stmt->fetch();
if (!$attachment) { header('Location: gallery.php'); exit; }

// Access control check: Check if user has permission to edit this attachment
$canEdit = false;
$project_id = 0;
$activity_id = 0;

if ($attachment['entity_type'] === 'phase') {
    // Project phase
    $ps = $pdo->prepare("SELECT p.user_id, p.id FROM project_phases ph JOIN projects p ON p.id = ph.project_id WHERE ph.id = ?");
    $ps->execute([$attachment['entity_id']]);
    $res = $ps->fetch();
    if ($res) {
        $project_id = $res['id'];
        $canEdit = ($res['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'], ['head','director','admin']));
    }
} elseif ($attachment['entity_type'] === 'activity_phase') {
    $ps = $pdo->prepare("SELECT p.user_id, a.id as activity_id, p.id as project_id FROM activity_phases ph JOIN activities a ON a.id = ph.activity_id JOIN projects p ON p.id = a.project_id WHERE ph.id = ?");
    $ps->execute([$attachment['entity_id']]);
    $res = $ps->fetch();
    if ($res) {
        $project_id = $res['project_id'];
        $activity_id = $res['activity_id'];
        $canEdit = ($res['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'], ['head','director','admin']));
    }
} elseif ($attachment['entity_type'] === 'activity_report') {
    $ps = $pdo->prepare("SELECT p.user_id, a.id as activity_id, p.id as project_id FROM activity_reports ar JOIN activities a ON a.id = ar.activity_id JOIN projects p ON p.id = a.project_id WHERE ar.id = ?");
    $ps->execute([$attachment['entity_id']]);
    $res = $ps->fetch();
    if ($res) {
        $project_id = $res['project_id'];
        $activity_id = $res['activity_id'];
        $canEdit = ($res['user_id'] == $_SESSION['user_id'] || in_array($_SESSION['role'], ['head','director','admin']));
    }
}

if (!$canEdit) {
    header('Location: index.php'); exit;
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();

    $action = $_POST['action'] ?? '';
    
    if ($action === 'edit') {
        $new_name = trim($_POST['file_name'] ?? '');
        if ($new_name) {
            $pdo->prepare("UPDATE attachments SET file_name = ? WHERE id = ?")->execute([$new_name, $attachment_id]);
            logActivity($pdo, 'UPDATE_ATTACHMENT', "เปลี่ยนชื่อไฟล์ ID: $attachment_id เป็น $new_name");
            $success = "เปลี่ยนชื่อไฟล์สำเร็จ";
            $attachment['file_name'] = $new_name; // Update local for display
        }
    } elseif ($action === 'delete') {
        // Delete file from disk
        $filePath = __DIR__ . '/uploads/' . $attachment['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$attachment_id]);
        logActivity($pdo, 'DELETE_ATTACHMENT', "ลบไฟล์: " . $attachment['file_name']);
        
        $redirect = 'gallery.php';
        if ($activity_id) $redirect = "view_activity.php?id=$activity_id";
        elseif ($project_id) $redirect = "view_project.php?id=$project_id";
        
        header("Location: $redirect");
        exit;
    }
}

// Prepare back link
$back_url = "gallery.php";
if ($activity_id) $back_url = "view_activity.php?id=$activity_id";
elseif ($project_id) $back_url = "view_project.php?id=$project_id";

$is_img = isImage($attachment['file_name']);
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
            <h2>⚙️ จัดการไฟล์/รูปภาพ</h2>
            <div class="topbar-breadcrumb">แก้ไขชื่อหรือลบไฟล์แนบที่อัปโหลดไว้แล้ว</div>
        </div>
    </div>
    <a href="<?= $back_url ?>" class="btn btn-outline">← กลับ</a>
</div>

<div class="page-content">
    <?php if ($error): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="grid grid-2">
        <div class="card fade-in">
            <div class="card-header">
                <h3>🖼️ ข้อมูลไฟล์แนบ</h3>
            </div>
            
            <div style="margin-bottom:1rem;text-align:center;background:#f7fafc;padding:1rem;border-radius:8px;border:1px dashed #cbd5e0">
                <?php if ($is_img): ?>
                    <img src="uploads/<?= htmlspecialchars($attachment['file_path']) ?>" style="max-width:100%;max-height:300px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
                <?php else: ?>
                    <div style="font-size:3rem"><?= getFileIcon($attachment['file_name']) ?></div>
                    <div style="margin-top:0.5rem;font-weight:600;color:var(--text-muted)">ไม่ใช่ไฟล์รูปภาพ</div>
                <?php endif; ?>
            </div>
            
            <ul style="list-style:none;padding:0;font-size:0.9rem;color:var(--text-main)">
                <li style="margin-bottom:0.5rem"><strong>ชื่อไฟล์ที่แสดงผล:</strong> <?= htmlspecialchars($attachment['file_name']) ?></li>
                <li style="margin-bottom:0.5rem"><strong>ประเภทไฟล์:</strong> <?= htmlspecialchars($attachment['file_type'] ?? '-') ?></li>
                <li style="margin-bottom:0.5rem"><strong>ขนาดไฟล์:</strong> <?= number_format(($attachment['file_size'] ?? 0) / 1024, 2) ?> KB</li>
                <li style="margin-bottom:0.5rem"><strong>วันที่อัปโหลด:</strong> <?= date('d/m/Y H:i:s', strtotime($attachment['uploaded_at'])) ?></li>
            </ul>
        </div>

        <div>
            <div class="card fade-in" style="margin-bottom:1rem">
                <div class="card-header">
                    <h3>✏️ แก้ไขชื่อที่แสดงผล</h3>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <input type="hidden" name="action" value="edit">
                    <div class="form-group">
                        <label>ชื่อไฟล์</label>
                        <input type="text" name="file_name" class="form-control" value="<?= htmlspecialchars($attachment['file_name']) ?>" required>
                        <small style="color:var(--text-muted);display:block;margin-top:0.3rem">*กรุณาระบุนามสกุลไฟล์ให้ถูกต้องด้วย เช่น .jpg, .pdf</small>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 บันทึกชื่อใหม่</button>
                </form>
            </div>
            
            <div class="card fade-in" style="border:1px solid var(--status-red);background:#fff5f5">
                <div class="card-header">
                    <h3 style="color:var(--status-red)">🗑️ ลบไฟล์แนบ (Danger Zone)</h3>
                </div>
                <p style="font-size:0.85rem;margin-bottom:1rem;color:#742a2a">การลบไฟล์นี้จะเป็นการลบอย่างถาวรจากระบบ ไม่สามารถกู้คืนได้ (ไฟล์จะถูกลบออกจากเซิร์ฟเวอร์ด้วย)</p>
                <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบไฟล์นี้ถาวร? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn" style="background:var(--status-red);color:#fff">🗑️ ลบไฟล์นี้ทันที</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

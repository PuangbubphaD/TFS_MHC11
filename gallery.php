<?php
require_once __DIR__ . '/includes/header.php';

// Filter by project and activity
$project_id  = intval($_GET['project_id'] ?? 0);
$activity_id = intval($_GET['activity_id'] ?? 0);

// Base query
$sql = "
    SELECT a.*, act.activity_name, p.title as project_title, p.id as project_id, p.user_id as project_owner, act.id as activity_id
    FROM attachments a
    LEFT JOIN activity_reports ar ON (a.entity_type = 'activity_report' AND a.entity_id = ar.id)
    LEFT JOIN activities act ON (
        (a.entity_type = 'activity_phase' AND a.entity_id IN (SELECT id FROM activity_phases WHERE activity_id = act.id))
        OR (a.entity_type = 'activity_report' AND ar.activity_id = act.id)
    )
    LEFT JOIN projects p ON act.project_id = p.id
    WHERE (a.file_name LIKE '%.jpg' OR a.file_name LIKE '%.jpeg' OR a.file_name LIKE '%.png' OR a.file_name LIKE '%.webp' OR a.file_name LIKE '%.gif')
";

if ($project_id)  $sql .= " AND p.id = $project_id";
if ($activity_id) $sql .= " AND act.id = $activity_id";

$sql .= " ORDER BY p.id DESC, act.id DESC, a.uploaded_at DESC";
$images = $pdo->query($sql)->fetchAll();

// Get list of projects for filter
$projects = $pdo->query("SELECT id, title FROM projects ORDER BY title ASC")->fetchAll();

// Get list of activities for filter (if project selected)
$activities = [];
if ($project_id) {
    $stmt = $pdo->prepare("SELECT id, activity_name FROM activities WHERE project_id = ? ORDER BY activity_name ASC");
    $stmt->execute([$project_id]);
    $activities = $stmt->fetchAll();
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>🖼️ ประมวลภาพโครงการ</h2>
        <div class="topbar-breadcrumb">ภาพกิจกรรมแยกตามโครงการและกิจกรรมย่อย</div>
    </div>
    </div>
    <div class="topbar-actions">
        <form method="GET" style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <select name="project_id" class="form-control" style="width:220px" onchange="this.form.activity_id.value=0;this.form.submit()">
                <option value="0">--- ทุกโครงการ ---</option>
                <?php foreach ($projects as $pj): ?>
                <option value="<?= $pj['id'] ?>" <?= $project_id == $pj['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pj['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="activity_id" class="form-control" style="width:220px" onchange="this.form.submit()" <?= empty($activities) ? 'disabled' : '' ?>>
                <option value="0">--- ทุกกิจกรรม ---</option>
                <?php foreach ($activities as $ac): ?>
                <option value="<?= $ac['id'] ?>" <?= $activity_id == $ac['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ac['activity_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <?php if ($project_id || $activity_id): ?>
            <a href="gallery.php" class="btn btn-outline" title="ล้างการค้นหา">🔄</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="page-content">
    <?php if (empty($images)): ?>
    <div class="card fade-in">
        <div class="empty-state">
            <div style="font-size:3rem">📸</div>
            <h3>ยังไม่มีรูปภาพในระบบ</h3>
            <p>รูปภาพจะปรากฏที่นี่เมื่อมีการอัปโหลดหลักฐานในขั้นตอนกิจกรรมหรือรายงานผล</p>
        </div>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:1.5rem">
        <?php foreach ($images as $img): 
            $path = "uploads/" . $img['file_path'];
        ?>
        <div class="card fade-in" style="padding:0;overflow:hidden;border:none;box-shadow:0 10px 20px rgba(0,0,0,0.05)">
            <div style="height:200px;overflow:hidden;cursor:pointer" onclick="openPopup('<?= $path ?>', 'image')">
                <img src="<?= $path ?>" style="width:100%;height:100%;object-fit:cover;transition:0.3s" 
                     onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
            </div>
            <div style="padding:1rem">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div style="font-size:0.75rem;color:var(--primary);font-weight:700;margin-bottom:0.25rem">
                        <?= htmlspecialchars($img['project_title']) ?>
                    </div>
                    <?php if ($img['project_owner'] == $_SESSION['user_id'] || in_array($_SESSION['role'], ['head','director','admin'])): ?>
                    <a href="edit_attachment.php?id=<?= $img['id'] ?>" style="color:var(--text-muted);font-size:0.85rem;text-decoration:none" title="จัดการไฟล์">⚙️</a>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.9rem;font-weight:600;margin-bottom:0.5rem;line-height:1.4">
                    <?= htmlspecialchars($img['activity_name'] ?: 'ภาพประกอบโครงการ') ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);display:flex;justify-content:space-between">
                    <span>📅 <?= thaiDate($img['uploaded_at']) ?></span>
                    <a href="view_activity.php?id=<?= $img['activity_id'] ?>" style="color:var(--primary);text-decoration:none">ดูที่มา →</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

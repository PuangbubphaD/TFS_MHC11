<?php
require_once __DIR__ . '/includes/header.php';

// Validate CSRF token (GET method)
$csrf = $_GET['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($sessionToken) || empty($csrf) || !hash_equals($sessionToken, $csrf)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
        <h3>❌ Security Error (CSRF Token Invalid)</h3>
        <p>การดำเนินการถูกปฏิเสธเนื่องจากความปลอดภัยของเซสชันหมดอายุ กรุณาย้อนกลับไปที่หน้ารายการหลักและลองใหม่อีกครั้ง</p>
        <p><a href="index.php" style="color:#c53030;font-weight:700">กลับสู่หน้าหลัก</a></p>
    </div>');
}

$report_id = intval($_GET['id'] ?? 0);
if (!$report_id) {
    header('Location: index.php');
    exit;
}

// Fetch report with activity & project details to verify permissions
$stmt = $pdo->prepare("
    SELECT ar.*, a.activity_name, a.status AS activity_status, p.title AS project_title, p.id AS project_id, p.user_id AS project_owner
    FROM activity_reports ar
    JOIN activities a ON ar.activity_id = a.id
    JOIN projects p ON a.project_id = p.id
    WHERE ar.id = ?
");
$stmt->execute([$report_id]);
$report = $stmt->fetch();
if (!$report) {
    header('Location: index.php');
    exit;
}

$activity_id = $report['activity_id'];
$canEdit = $report['project_owner'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin';

// Access check
if (!$canEdit) {
    header('Location: index.php');
    exit;
}

// 1. Fetch all associated attachments
$fa = $pdo->prepare("SELECT * FROM attachments WHERE entity_type='activity_report' AND entity_id = ?");
$fa->execute([$report_id]);
$attachments = $fa->fetchAll();

// 2. Unlink physical files on disk
foreach ($attachments as $file) {
    $filePath = __DIR__ . '/uploads/' . $file['file_path'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

// 3. Delete attachments in DB
$pdo->prepare("DELETE FROM attachments WHERE entity_type='activity_report' AND entity_id = ?")->execute([$report_id]);

// 4. Delete the activity report itself
$pdo->prepare("DELETE FROM activity_reports WHERE id = ?")->execute([$report_id]);

// 5. Audit Log for report deletion
logActivity($pdo, 'DELETE_ACTIVITY_REPORT', "ลบรายงานผลกิจกรรม ID: $report_id ในกิจกรรม: " . $report['activity_name']);

// 6. Check if any other reports remain for this activity
$check_reports = $pdo->prepare("SELECT COUNT(*) FROM activity_reports WHERE activity_id = ?");
$check_reports->execute([$activity_id]);
$reports_left = intval($check_reports->fetchColumn());

if ($reports_left === 0) {
    // If no reports left and the activity is currently ongoing, revert back to planned
    if ($report['activity_status'] === 'ongoing') {
        $pdo->prepare("UPDATE activities SET status = 'planned' WHERE id = ?")->execute([$activity_id]);
        logActivity($pdo, 'UPDATE_ACTIVITY_STATUS', "ปรับสถานะกิจกรรม ID: $activity_id กลับเป็น planned เนื่องจากรายงานผลทั้งหมดถูกลบ");
    }
}

// 7. Redirect back to view activity with report_deleted parameter
header("Location: view_activity.php?id=$activity_id&report_deleted=1");
exit;

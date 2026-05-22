<?php
// Quick activity status update handler
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Banned if not logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    checkCsrfOrDie();

    $activity_id = intval($_POST['activity_id'] ?? 0);
    $status      = $_POST['status'] ?? '';
    $redirect    = $_POST['redirect'] ?? 'index.php';

    // Access check: Fetch project owner for this activity
    $stmt = $pdo->prepare("
        SELECT p.user_id AS project_owner 
        FROM activities a 
        JOIN projects p ON p.id = a.project_id 
        WHERE a.id = ?
    ");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch();

    if (!$activity) {
        header("Location: index.php");
        exit;
    }

    // Role-based access check
    if ($_SESSION['role'] === 'staff' && $activity['project_owner'] != $_SESSION['user_id']) {
        http_response_code(403);
        die('Forbidden: You do not have permission to update this activity.');
    }

    $allowed = ['planned','ongoing','completed','cancelled'];
    if ($activity_id && in_array($status, $allowed)) {
        $pdo->prepare("UPDATE activities SET status=? WHERE id=?")->execute([$status, $activity_id]);
    }
    header("Location: $redirect");
    exit;
}
header('Location: index.php');

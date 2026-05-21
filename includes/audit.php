<?php
/**
 * TFS - Audit Log Helper
 */

if (!function_exists('logActivity')) {
    function logActivity(PDO $pdo, string $action, ?string $details = null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        
        // Get IP Address safely
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, details, ip_address)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $action, $details, $ip]);
        } catch (Exception $e) {
            // Silently fail or ignore log failures so system remains stable
        }
    }
}

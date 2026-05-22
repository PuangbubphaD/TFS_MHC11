<?php
require_once __DIR__ . '/includes/db.php';

if (!defined('ALLOW_ADMIN_TOOLS') || ALLOW_ADMIN_TOOLS !== true) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
        <h3>❌ Access Denied</h3>
        <p>เพื่อความปลอดภัย เครื่องมือผู้ดูแลระบบนี้ถูกปิดใช้งานในไฟล์กำหนดค่าหลัก</p>
    </div>');
}

$new_password = password_hash('password', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username IN ('admin','head1','staff1','staff2')");
$stmt->execute([$new_password]);

echo '<div style="font-family:sans-serif;padding:2rem;background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;margin:2rem">';
echo '<h3>✅ รีเซ็ตรหัสผ่านสำเร็จ!</h3>';
echo '<p>ผู้ใช้ทั้งหมด (admin, head1, staff1, staff2) ตอนนี้ใช้รหัสผ่าน: <strong>password</strong></p>';
echo '<a href="login.php" style="color:#276749">→ ไปหน้า Login</a>';
echo '</div>';

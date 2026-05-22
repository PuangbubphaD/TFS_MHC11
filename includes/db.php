<?php
$host    = getenv('DB_HOST')     ?: 'localhost';   // Docker: ชื่อ container DB | Local: localhost
$db      = getenv('DB_NAME')     ?: 'tfs_db';
$user    = getenv('DB_USER')     ?: 'root';
$pass    = getenv('DB_PASSWORD') ?: '';
$charset = 'utf8mb4';

define('ALLOW_ADMIN_TOOLS', false); // Set to true only for local setup or seeding, keep false in production
define('BOT_API_KEY', ''); // Add your Bank of Thailand API Key here if needed

define('APP_VERSION', '1.0.0');
define('APP_NAME', 'Task Flow System');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
        <h3>❌ Database Connection Error</h3>
        <p>กรุณาตรวจสอบ: XAMPP MySQL กำลังทำงาน และ Database <strong>tfs_db</strong> ถูกสร้างแล้ว</p>
        <p><a href="phpmyadmin" target="_blank">เปิด phpMyAdmin</a> แล้ว import ไฟล์ <strong>schema.sql</strong></p>
        <small>' . $e->getMessage() . '</small>
    </div>');
}

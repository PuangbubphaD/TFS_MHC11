<?php
// ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
$host    = 'mhc11-tfs-db';
$db      = 'tfs_db';
$user    = 'tfs_user';
$pass    = 'PPLAIeznNRpT';
$charset = 'utf8mb4';

// หากมีไฟล์ตั้งค่าส่วนตัวเครื่อง (Local XAMPP) ให้ใช้ค่านั้นแทน
if (file_exists(__DIR__ . '/db.local.php')) {
    require_once __DIR__ . '/db.local.php';
}

define('ALLOW_ADMIN_TOOLS', false); // Set to true only for local setup or seeding, keep false in production
define('BOT_API_KEY', ''); // Add your Bank of Thailand API Key here if needed

define('APP_VERSION', '1.0.0');
define('APP_NAME', 'Task Flow System');

// ThaiD Configuration
define('THAID_ENABLED', true);
define('THAID_CLIENT_ID', 'ZnhRYW1ydXdvTmJhNU50aE5BWnhIRTFtMHZRV1NPZ1I'); // Change this in production or db.local.php
define('THAID_CLIENT_SECRET', 'SmN6RFZRcFFGVmlrWXAxOTN3WXlSRE42TkVDVTE2eEk1SGV5RjZmNg'); // Change this
define('THAID_REDIRECT_URI', 'http://localhost/TFS/thaid_callback.php'); // Update to production URL when deploying
define('THAID_SCOPE', 'openid pid title given_name family_name');
define('THAID_AUTO_REGISTER', true);
define('THAID_REQUIRE_ADMIN_APPROVAL', true);
define('THAID_USE_SANDBOX', false); // Set false for production
define('THAID_SYNC_PROFILE', true);            // Sync ชื่อจาก ThaiD เข้า DB (เฉพาะช่องว่าง)
define('THAID_CONFIRM_NAME_OVERWRITE', true);   // ต้องยืนยันก่อนทับชื่อ (เมื่อ pid ตรงแต่ชื่อไม่ตรง)
define('THAID_LINK_BY_NAME', true);             // จับคู่บัญชีด้วยชื่อ-นามสกุล (สำหรับ user เก่า)

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

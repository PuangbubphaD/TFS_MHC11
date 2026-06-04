<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Validate CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    echo json_encode(['success' => false, 'message' => 'CSRF Token Invalid']);
    exit;
}

$type   = $_POST['type'] ?? ''; // 'telegram' or 'discord'
$target = $_POST['target'] ?? ''; // 'personal' or 'group'

// Telegram params
$botToken = $_POST['telegram_bot_token'] ?? '';
$chatId   = $_POST['telegram_chat_id'] ?? '';

// Discord params
$webhookUrl = $_POST['discord_webhook_url'] ?? '';

$message = "🔔 *[ข้อความทดสอบจากระบบ TFS]*\nยินดีด้วย! ระบบแจ้งเตือนของคุณเชื่อมต่อและทำงานได้อย่างถูกต้องเรียบร้อยแล้วค่ะ 🎉";

if ($type === 'telegram') {
    if (empty($botToken) || empty($chatId)) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ Bot Token และ Chat ID สำหรับการส่งข้อความ']);
        exit;
    }
    
    $res = NotificationService::sendToTelegram($message, $botToken, $chatId);
    $resDecoded = json_decode($res, true);
    
    if ($resDecoded && isset($resDecoded['ok']) && $resDecoded['ok'] === true) {
        echo json_encode(['success' => true, 'message' => 'ส่งข้อความทดสอบไปยัง Telegram สำเร็จ!']);
    } else {
        $errDetail = $resDecoded['description'] ?? 'ไม่สามารถเชื่อมต่อ API ของ Telegram ได้ (โปรดตรวจสอบ Token และ ID)';
        echo json_encode(['success' => false, 'message' => 'ส่งข้อความทดสอบล้มเหลว: ' . $errDetail]);
    }
    exit;
}

if ($type === 'discord') {
    if (empty($webhookUrl)) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ Webhook URL สำหรับการส่งข้อความ']);
        exit;
    }
    
    $res = NotificationService::sendToDiscord(str_replace('*', '**', $message), $webhookUrl);
    
    // Discord Webhook returns empty string on success (204 No Content)
    if ($res === '' || $res === false || empty($res)) {
        echo json_encode(['success' => true, 'message' => 'ส่งข้อความทดสอบไปยัง Discord สำเร็จ!']);
    } else {
        $resDecoded = json_decode($res, true);
        if (isset($resDecoded['message'])) {
            echo json_encode(['success' => false, 'message' => 'ส่งข้อความทดสอบล้มเหลว: ' . $resDecoded['message']]);
        } else {
            echo json_encode(['success' => true, 'message' => 'ส่งข้อความทดสอบไปยัง Discord สำเร็จ!']);
        }
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);

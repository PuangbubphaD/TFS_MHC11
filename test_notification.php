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

$type   = $_POST['type']   ?? ''; // 'telegram' or 'discord'
$target = $_POST['target'] ?? ''; // 'personal' or 'group'

// Telegram params
$botToken = $_POST['telegram_bot_token'] ?? '';
$chatId   = $_POST['telegram_chat_id']   ?? '';

// Discord params
$webhookUrl = $_POST['discord_webhook_url'] ?? '';
$discordBotToken = $_POST['discord_bot_token'] ?? '';
$discordUserId   = $_POST['discord_user_id'] ?? '';

$event = $_POST['event'] ?? ''; // 'reminder', 'due_today', 'overdue'
$phase = intval($_POST['phase'] ?? 1); // 1-8

// Phase name map
$phaseNames = [
    1 => 'ขออนุมัติจัดกิจกรรม',
    2 => 'รายงานค่าใช้จ่าย',
    3 => 'รายงานผลการดำเนินงาน',
    4 => 'แนบไฟล์หลักฐาน',
    5 => 'จัดทำ One-page',
    6 => 'ส่งข้อมูลเข้าระบบกรม',
    7 => 'ติดตามผลการประเมิน',
    8 => 'สรุปและปิดโครงการ',
];
$phaseNum  = ($phase >= 1 && $phase <= 8) ? $phase : 1;
$phaseName = $phaseNames[$phaseNum];

$message = "🔔 <b>[ข้อความทดสอบจากระบบ TFS]</b>\nยินดีด้วย! ระบบแจ้งเตือนของคุณเชื่อมต่อและทำงานได้อย่างถูกต้องเรียบร้อยแล้วค่ะ 🎉";

if (!empty($event)) {
    if ($target === 'personal') {
        if ($event === 'reminder') {
            $message = "⏳ <b>[แจ้งเตือนเตรียมงาน - ล่วงหน้า 7 วันทำการ]</b>\n"
                     . "-----------------------------------------------\n"
                     . "📌 <b>โครงการ:</b> โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 <b>กิจกรรม:</b> กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 <b>ขั้นตอนย่อย:</b> ขั้นตอนที่ $phaseNum - $phaseName\n"
                     . "👤 <b>ผู้รับผิดชอบ:</b> คุณสมศักดิ์ รักเรียน\n\n"
                     . "⚠️ <b>สถานะขณะนี้:</b> ยังไม่ได้ดำเนินการส่งงานในระบบ\n"
                     . "📅 <b>วันครบกำหนดส่ง (Deadline):</b> " . date('d/m/Y', strtotime('+7 days')) . " (เหลือเวลาอีก 7 วันทำการ)\n\n"
                     . "💡 โปรดเตรียมเอกสาร/หลักฐาน และเข้ามาบันทึกผลการดำเนินงานในระบบ TFS เมื่อดำเนินการเสร็จสิ้นด้วยนะคะ\n"
                     . "🔗 <b>ลิงก์ระบบ:</b> https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        } elseif ($event === 'due_today') {
            $message = "⚠️ <b>[ด่วนที่สุด! ครบกำหนดส่งงานวันนี้]</b>\n"
                     . "-----------------------------------------------\n"
                     . "📌 <b>โครงการ:</b> โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 <b>กิจกรรม:</b> กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 <b>ขั้นตอนย่อย:</b> ขั้นตอนที่ $phaseNum - $phaseName\n"
                     . "👤 <b>ผู้รับผิดชอบ:</b> คุณสมศักดิ์ รักเรียน\n\n"
                     . "🚨 <b>สถานะ:</b> ครบกำหนดวันนี้และยังไม่บันทึกความสำเร็จ!\n"
                     . "📅 <b>กำหนดส่ง:</b> วันนี้ (" . date('d/m/Y') . ")\n\n"
                     . "💡 ขอความกรุณาเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS ภายในวันนี้ด้วยนะคะ\n"
                     . "🔗 <b>ลิงก์ระบบ:</b> https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        } elseif ($event === 'overdue') {
            $message = "🔴 <b>[แจ้งเตือนเลยกำหนดส่ง]</b>\n"
                     . "-----------------------------------------------\n"
                     . "📌 <b>โครงการ:</b> โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 <b>กิจกรรม:</b> กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 <b>ขั้นตอนย่อย:</b> ขั้นตอนที่ $phaseNum - $phaseName\n"
                     . "👤 <b>ผู้รับผิดชอบ:</b> คุณสมศักดิ์ รักเรียน\n\n"
                     . "🚨 <b>สถานะ:</b> เกินกำหนดส่งมาแล้ว 3 วันทำการ!\n"
                     . "📅 <b>กำหนดส่งเดิม:</b> " . date('d/m/Y', strtotime('-3 days')) . "\n\n"
                     . "💡 โปรดเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS โดยด่วนที่สุดค่ะ\n"
                     . "🔗 <b>ลิงก์ระบบ:</b> https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        }
    } else { // group
        if ($event === 'reminder') {
            $message = "⏳ <b>[จำลองข้อความทดสอบ - ล่วงหน้า 7 วันทำการ]</b>\n"
                     . "ส่งรายการล่วงหน้าเฉพาะบุคคล (ไม่ส่งเข้ากลุ่มกลาง)\n"
                     . "• ขั้นตอนที่ $phaseNum ($phaseName) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]";
        } elseif ($event === 'due_today') {
            $message = "📢 <b>[สรุปรายการงานครบกำหนดส่งประจำวันที่ " . date('d/m/Y') . "]</b>\n"
                     . "-----------------------------------------------------------\n"
                     . "มีงานที่ครบกำหนดส่งวันนี้ ทั้งหมด 1 รายการ ดังนี้:\n\n"
                     . "• ขั้นตอนที่ $phaseNum ($phaseName) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]\n"
                     . "-----------------------------------------------------------\n"
                     . "💡 ฝากหัวหน้างานและผู้รับผิดชอบโครงการช่วยตรวจสอบและเร่งบันทึกความก้าวหน้าในระบบ TFS ด้วยนะคะ\n"
                     . "🔗 <b>เข้าใช้งานระบบ:</b> https://mhc11.dmh.go.th/mhc11-tfs/";
        } elseif ($event === 'overdue') {
            $message = "🔴 <b>[แจ้งเตือนด่วน! รายการงานเกินกำหนดส่ง (Overdue)]</b>\n"
                     . "-----------------------------------------------------------\n"
                     . "⚠️ พบงานที่ค้างส่งและเลยกำหนดเวลาในระบบ TFS ทั้งหมด 1 รายการ:\n\n"
                     . "• 🔴 เกินกำหนด 3 วัน: ขั้นตอนที่ $phaseNum ($phaseName) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]\n"
                     . "-----------------------------------------------------------\n"
                     . "🔥 รายการข้างต้นเลยกำหนดส่งงานแล้ว โปรดเร่งรัดดำเนินการและบันทึกข้อมูลในระบบ TFS โดยด่วนที่สุดค่ะ\n"
                     . "🔗 <b>ตรวจสอบงานค้างทั้งหมดได้ที่:</b> https://mhc11.dmh.go.th/mhc11-tfs/all_projects.php";
        }
    }
}

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
    $strippedMessage = strip_tags($message);
    
    if ($target === 'personal' && !empty($discordBotToken) && !empty($discordUserId)) {
        // Send via DM
        $res = NotificationService::sendToDiscordDM($strippedMessage, $discordBotToken, $discordUserId);
    } else {
        // Send via Webhook
        if (empty($webhookUrl)) {
            echo json_encode(['success' => false, 'message' => 'กรุณาระบุ Webhook URL สำหรับการส่งข้อความ']);
            exit;
        }
        $res = NotificationService::sendToDiscord($strippedMessage, $webhookUrl);
    }
    
    // Discord Webhook returns empty string on success (204 No Content)
    // Discord DM returns a JSON object on success
    if ($res === '' || $res === false || empty($res)) {
        echo json_encode(['success' => true, 'message' => 'ส่งข้อความทดสอบไปยัง Discord สำเร็จ!']);
    } else {
        $resDecoded = json_decode($res, true);
        // Checking if we got an error object from discord api
        if (isset($resDecoded['message']) && !isset($resDecoded['id'])) {
            echo json_encode(['success' => false, 'message' => 'ส่งข้อความทดสอบล้มเหลว: ' . $resDecoded['message']]);
        } else {
            echo json_encode(['success' => true, 'message' => 'ส่งข้อความทดสอบไปยัง Discord สำเร็จ!']);
        }
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);

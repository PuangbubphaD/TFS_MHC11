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

$event  = $_POST['event'] ?? ''; // '', 'reminder', 'due_today', 'overdue'

$message = "🔔 *[ข้อความทดสอบจากระบบ TFS]*\nยินดีด้วย! ระบบแจ้งเตือนของคุณเชื่อมต่อและทำงานได้อย่างถูกต้องเรียบร้อยแล้วค่ะ 🎉";

if (!empty($event)) {
    if ($target === 'personal') {
        if ($event === 'reminder') {
            $message = "⏳ *[แจ้งเตือนเตรียมงาน - ล่วงหน้า 7 วันทำการ]*\n"
                     . "-----------------------------------------------\n"
                     . "📌 *โครงการ:* โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 *กิจกรรม:* กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ 1 - ขออนุมัติจัดกิจกรรม\n"
                     . "👤 *ผู้รับผิดชอบ:* คุณสมศักดิ์ รักเรียน\n\n"
                     . "⚠️ *สถานะขณะนี้:* ยังไม่ได้ดำเนินการส่งงานในระบบ\n"
                     . "📅 *วันครบกำหนดส่ง (Deadline):* " . date('d/m/Y', strtotime('+7 days')) . " (เหลือเวลาอีก 7 วันทำการ)\n\n"
                     . "💡 โปรดเตรียมเอกสาร/หลักฐาน และเข้ามาบันทึกผลการดำเนินงานในระบบ TFS เมื่อดำเนินการเสร็จสิ้นด้วยนะคะ\n"
                     . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        } elseif ($event === 'due_today') {
            $message = "⚠️ *[ด่วนที่สุด! ครบกำหนดส่งงานวันนี้]*\n"
                     . "-----------------------------------------------\n"
                     . "📌 *โครงการ:* โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 *กิจกรรม:* กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ 1 - ขออนุมัติจัดกิจกรรม\n"
                     . "👤 *ผู้รับผิดชอบ:* คุณสมศักดิ์ รักเรียน\n\n"
                     . "🚨 *สถานะ:* ครบกำหนดวันนี้และยังไม่บันทึกความสำเร็จ!\n"
                     . "📅 *กำหนดส่ง:* วันนี้ (" . date('d/m/Y') . ")\n\n"
                     . "💡 ขอความกรุณาเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS ภายในวันนี้ด้วยนะคะ\n"
                     . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        } elseif ($event === 'overdue') {
            $message = "🔴 *[แจ้งเตือนเลยกำหนดส่ง]*\n"
                     . "-----------------------------------------------\n"
                     . "📌 *โครงการ:* โครงการอบรมพัฒนาจิตวิทยาสามัญ\n"
                     . "💼 *กิจกรรม:* กิจกรรมสัมมนาบุคลากรจิตวิทยา\n"
                     . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ 1 - ขออนุมัติจัดกิจกรรม\n"
                     . "👤 *ผู้รับผิดชอบ:* คุณสมศักดิ์ รักเรียน\n\n"
                     . "🚨 *สถานะ:* เกินกำหนดส่งมาแล้ว 3 วันทำการ!\n"
                     . "📅 *กำหนดส่งเดิม:* " . date('d/m/Y', strtotime('-3 days')) . "\n\n"
                     . "💡 โปรดเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS โดยด่วนที่สุดค่ะ\n"
                     . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=999";
        }
    } else { // group
        if ($event === 'reminder') {
            $message = "⏳ *[จำลองข้อความทดสอบ - ล่วงหน้า 7 วันทำการ]*\n"
                     . "ส่งรายการล่วงหน้าเฉพาะบุคคล (ไม่ส่งเข้ากลุ่มกลาง)\n"
                     . "• ขั้นตอนที่ 1 (ขออนุมัติจัดกิจกรรม) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]";
        } elseif ($event === 'due_today') {
            $message = "📢 *[สรุปรายการงานครบกำหนดส่งประจำวันที่ " . date('d/m/Y') . "]*\n"
                     . "-----------------------------------------------------------\n"
                     . "มีงานที่ครบกำหนดส่งวันนี้ ทั้งหมด 1 รายการ ดังนี้:\n\n"
                     . "• ขั้นตอนที่ 1 (ขออนุมัติจัดกิจกรรม) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]\n"
                     . "-----------------------------------------------------------\n"
                     . "💡 ฝากหัวหน้างานและผู้รับผิดชอบโครงการช่วยตรวจสอบและเร่งบันทึกความก้าวหน้าในระบบ TFS ด้วยนะคะ\n"
                     . "🔗 *เข้าใช้งานระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/";
        } elseif ($event === 'overdue') {
            $message = "🔴 *[แจ้งเตือนด่วน! รายการงานเกินกำหนดส่ง (Overdue)]*\n"
                     . "-----------------------------------------------------------\n"
                     . "⚠️ พบงานที่ค้างส่งและเลยกำหนดเวลาในระบบ TFS ทั้งหมด 1 รายการ:\n\n"
                     . "• 🔴 เกินกำหนด 3 วัน: ขั้นตอนที่ 1 (ขออนุมัติจัดกิจกรรม) - กิจกรรม: กิจกรรมสัมมนาบุคลากรจิตวิทยา [ผู้รับผิดชอบ: คุณสมศักดิ์ รักเรียน]\n"
                     . "-----------------------------------------------------------\n"
                     . "🔥 รายการข้างต้นเลยกำหนดส่งงานแล้ว โปรดเร่งรัดดำเนินการและบันทึกข้อมูลในระบบ TFS โดยด่วนที่สุดค่ะ\n"
                     . "🔗 *ตรวจสอบงานค้างทั้งหมดได้ที่:* https://mhc11.dmh.go.th/mhc11-tfs/all_projects.php";
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

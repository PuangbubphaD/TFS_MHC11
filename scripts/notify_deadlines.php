<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/NotificationService.php';

echo "=== Processing Daily Deadline Notifications ===\n";

// Fetch settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    echo "Error fetching settings: " . $e->getMessage() . "\n";
}

$botToken = $settings['telegram_bot_token'] ?? '';
$telegramGroupId = $settings['telegram_group_chat_id'] ?? '';
$discordGroupWebhook = $settings['discord_group_webhook'] ?? '';

// Fetch public holidays
$global_holidays = [];
try {
    $stmt = $pdo->query("SELECT holiday_date FROM holidays");
    $global_holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $global_holidays = [];
}

// Fetch all active phases not completed
$phases = [];
try {
    $stmt = $pdo->query("
        SELECT ap.*, a.activity_name, a.planned_start, a.planned_end, 
               p.title AS project_title, u.full_name AS owner_name, 
               u.telegram_chat_id, u.discord_webhook_url
        FROM activity_phases ap
        JOIN activities a ON a.id = ap.activity_id
        JOIN projects p ON p.id = a.project_id
        JOIN users u ON u.id = p.user_id
        WHERE ap.status != 'completed' AND a.deleted_at IS NULL AND p.deleted_at IS NULL
        ORDER BY ap.id ASC
    ");
    $phases = $stmt->fetchAll();
} catch (Exception $e) {
    echo "Error fetching activity phases: " . $e->getMessage() . "\n";
}

$today = date('Y-m-d');
$due_today_tasks = [];
$overdue_tasks = [];

foreach ($phases as $ph) {
    if (empty($ph['deadline_date'])) {
        continue;
    }
    
    $deadline = $ph['deadline_date'];
    $phaseNum = $ph['phase_number'];
    $phaseName = $ph['phase_name'];
    $activityName = $ph['activity_name'];
    $projectTitle = $ph['project_title'];
    $ownerName = $ph['owner_name'];
    $activityId = $ph['activity_id'];
    
    // Check if overdue or due today or 7 days before
    if ($today === $deadline) {
        // Due today
        $due_today_tasks[] = $ph;
        
        // Personal notification
        $msg = "⚠️ *[ด่วนที่สุด! ครบกำหนดส่งงานวันนี้]*\n"
             . "-----------------------------------------------\n"
             . "📌 *โครงการ:* $projectTitle\n"
             . "💼 *กิจกรรม:* $activityName\n"
             . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ $phaseNum - $phaseName\n"
             . "👤 *ผู้รับผิดชอบ:* $ownerName\n\n"
             . "🚨 *สถานะ:* ครบกำหนดวันนี้และยังไม่บันทึกความสำเร็จ!\n"
             . "📅 *กำหนดส่ง:* วันนี้ (" . thaiDate($deadline) . ")\n\n"
             . "💡 ขอความกรุณาเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS ภายในวันนี้ด้วยนะคะ\n"
             . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=$activityId";
             
        if (!empty($ph['telegram_chat_id'])) {
            NotificationService::sendToTelegram($msg, $botToken, $ph['telegram_chat_id']);
        }
        if (!empty($ph['discord_webhook_url'])) {
            NotificationService::sendToDiscord(str_replace('*', '**', $msg), $ph['discord_webhook_url']);
        }
    } elseif ($today > $deadline) {
        // Overdue
        $daysOverdue = getWorkingDays($deadline, $today, $global_holidays);
        $ph['days_overdue'] = $daysOverdue;
        $overdue_tasks[] = $ph;
        
        // Personal notification
        $msg = "🔴 *[แจ้งเตือนเลยกำหนดส่ง]*\n"
             . "-----------------------------------------------\n"
             . "📌 *โครงการ:* $projectTitle\n"
             . "💼 *กิจกรรม:* $activityName\n"
             . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ $phaseNum - $phaseName\n"
             . "👤 *ผู้รับผิดชอบ:* $ownerName\n\n"
             . "🚨 *สถานะ:* เกินกำหนดส่งมาแล้ว $daysOverdue วันทำการ!\n"
             . "📅 *กำหนดส่งเดิม:* " . thaiDate($deadline) . "\n\n"
             . "💡 โปรดเร่งบันทึกข้อมูลและแนบไฟล์หลักฐานลงในระบบ TFS โดยด่วนที่สุดค่ะ\n"
             . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=$activityId";
             
        if (!empty($ph['telegram_chat_id'])) {
            NotificationService::sendToTelegram($msg, $botToken, $ph['telegram_chat_id']);
        }
        if (!empty($ph['discord_webhook_url'])) {
            NotificationService::sendToDiscord(str_replace('*', '**', $msg), $ph['discord_webhook_url']);
        }
    } else {
        // Future deadline
        $daysLeft = getWorkingDays($today, $deadline, $global_holidays);
        
        // Advance warning 7 days (Skip for phase 5 - Onepage)
        if ($daysLeft === 7 && $phaseNum != 5) {
            $msg = "⏳ *[แจ้งเตือนเตรียมงาน - ล่วงหน้า 7 วันทำการ]*\n"
                 . "-----------------------------------------------\n"
                 . "📌 *โครงการ:* $projectTitle\n"
                 . "💼 *กิจกรรม:* $activityName\n"
                 . "🔄 *ขั้นตอนย่อย:* ขั้นตอนที่ $phaseNum - $phaseName\n"
                 . "👤 *ผู้รับผิดชอบ:* $ownerName\n\n"
                 . "⚠️ *สถานะขณะนี้:* ยังไม่ได้ดำเนินการส่งงานในระบบ\n"
                 . "📅 *วันครบกำหนดส่ง (Deadline):* " . thaiDate($deadline) . " (เหลือเวลาอีก 7 วันทำการ)\n\n"
                 . "💡 โปรดเตรียมเอกสาร/หลักฐาน และเข้ามาบันทึกผลการดำเนินงานในระบบ TFS เมื่อดำเนินการเสร็จสิ้นด้วยนะคะ\n"
                 . "🔗 *ลิงก์ระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/view_activity.php?id=$activityId";
                 
            if (!empty($ph['telegram_chat_id'])) {
                NotificationService::sendToTelegram($msg, $botToken, $ph['telegram_chat_id']);
            }
            if (!empty($ph['discord_webhook_url'])) {
                NotificationService::sendToDiscord(str_replace('*', '**', $msg), $ph['discord_webhook_url']);
            }
        }
    }
}

// Group notification for Due Today
if (!empty($due_today_tasks)) {
    $count = count($due_today_tasks);
    $listStr = "";
    foreach ($due_today_tasks as $t) {
        $listStr .= "• ขั้นตอนที่ {$t['phase_number']} ({$t['phase_name']}) - กิจกรรม: {$t['activity_name']} [ผู้รับผิดชอบ: {$t['owner_name']}]\n";
    }
    
    $groupMsg = "📢 *[สรุปรายการงานครบกำหนดส่งประจำวันที่ " . thaiDate($today) . "]*\n"
              . "-----------------------------------------------------------\n"
              . "มีงานที่ครบกำหนดส่งวันนี้ ทั้งหมด $count รายการ ดังนี้:\n\n"
              . $listStr
              . "-----------------------------------------------------------\n"
              . "💡 ฝากหัวหน้างานและผู้รับผิดชอบโครงการช่วยตรวจสอบและเร่งบันทึกความก้าวหน้าในระบบ TFS ด้วยนะคะ\n"
              . "🔗 *เข้าใช้งานระบบ:* https://mhc11.dmh.go.th/mhc11-tfs/";
              
    if (!empty($telegramGroupId)) {
        NotificationService::sendToTelegram($groupMsg, $botToken, $telegramGroupId);
    }
    if (!empty($discordGroupWebhook)) {
        NotificationService::sendToDiscord(str_replace('*', '**', $groupMsg), $discordGroupWebhook);
    }
}

// Group notification for Overdue
if (!empty($overdue_tasks)) {
    $count = count($overdue_tasks);
    $listStr = "";
    foreach ($overdue_tasks as $t) {
        $listStr .= "• 🔴 เกินกำหนด {$t['days_overdue']} วัน: ขั้นตอนที่ {$t['phase_number']} ({$t['phase_name']}) - กิจกรรม: {$t['activity_name']} [ผู้รับผิดชอบ: {$t['owner_name']}]\n";
    }
    
    $groupMsg = "🔴 *[แจ้งเตือนด่วน! รายการงานเกินกำหนดส่ง (Overdue)]*\n"
              . "-----------------------------------------------------------\n"
              . "⚠️ พบงานที่ค้างส่งและเลยกำหนดเวลาในระบบ TFS ทั้งหมด $count รายการ:\n\n"
              . $listStr
              . "-----------------------------------------------------------\n"
              . "🔥 รายการข้างต้นเลยกำหนดส่งงานแล้ว โปรดเร่งรัดดำเนินการและบันทึกข้อมูลในระบบ TFS โดยด่วนที่สุดค่ะ\n"
              . "🔗 *ตรวจสอบงานค้างทั้งหมดได้ที่:* https://mhc11.dmh.go.th/mhc11-tfs/all_projects.php";
              
    if (!empty($telegramGroupId)) {
        NotificationService::sendToTelegram($groupMsg, $botToken, $telegramGroupId);
    }
    if (!empty($discordGroupWebhook)) {
        NotificationService::sendToDiscord(str_replace('*', '**', $groupMsg), $discordGroupWebhook);
    }
}

echo "Staged today due: " . count($due_today_tasks) . ", overdue: " . count($overdue_tasks) . "\n";
echo "=== Processing Completed ===\n";

<?php
require_once __DIR__ . '/../includes/db.php';

// Fetch settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('notify_run_time', 'last_notify_run_date')");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    echo "Error fetching settings: " . $e->getMessage() . "\n";
    exit;
}

$runTime = $settings['notify_run_time'] ?? '08:00';
$lastRunDate = $settings['last_notify_run_date'] ?? '';

date_default_timezone_set('Asia/Bangkok'); // Ensure timezone is correct
$currentDate = date('Y-m-d');
$currentTime = date('H:i');

echo "Current Date: $currentDate, Current Time: $currentTime\n";
echo "Configured Run Time: $runTime, Last Run Date: $lastRunDate\n";

// Check if today is a weekend (Saturday = 6, Sunday = 7)
$dayOfWeek = (int)date('N');
if ($dayOfWeek >= 6) {
    echo "Today is a weekend (Day $dayOfWeek). Skipping notification.\n";
    exit;
}

// Check if today is a public holiday
$isHoliday = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM thai_holidays WHERE holiday_date = ?");
    $stmt->execute([$currentDate]);
    if ($stmt->fetchColumn() > 0) {
        $isHoliday = true;
    }
} catch (Exception $e) {
    // Ignore and fallback
}

if (!$isHoliday) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ?");
        $stmt->execute([$currentDate]);
        if ($stmt->fetchColumn() > 0) {
            $isHoliday = true;
        }
    } catch (Exception $e) {
        // Ignore
    }
}

if ($isHoliday) {
    echo "Today is a public holiday. Skipping notification.\n";
    exit;
}


if ($currentDate === $lastRunDate) {
    echo "Notification script already ran today. Skipping.\n";
    exit;
}

if ($currentTime >= $runTime) {
    echo "Time condition met. Executing notify_deadlines.php...\n";
    
    // Execute the main notification script
    ob_start();
    require __DIR__ . '/notify_deadlines.php';
    $output = ob_get_clean();
    echo $output;
    
    // Update last run date in database
    try {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'last_notify_run_date'");
        $stmt->execute([$currentDate]);
        echo "Updated last_notify_run_date to $currentDate.\n";
    } catch (Exception $e) {
        echo "Error updating last_notify_run_date: " . $e->getMessage() . "\n";
    }
} else {
    echo "Time condition not met yet ($currentTime < $runTime). Waiting for next minute.\n";
}

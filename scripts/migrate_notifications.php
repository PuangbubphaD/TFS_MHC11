<?php
require_once __DIR__ . '/../includes/db.php';

echo "=== Starting DB Migration for Notifications ===\n";

try {
    // 1. Add columns to users table
    $alterQueries = [
        "ALTER TABLE `users` ADD COLUMN `telegram_chat_id` VARCHAR(100) NULL AFTER `department`",
        "ALTER TABLE `users` ADD COLUMN `discord_webhook_url` VARCHAR(255) NULL AFTER `telegram_chat_id`",
        "ALTER TABLE `users` ADD COLUMN `line_notify_token` VARCHAR(255) NULL AFTER `discord_webhook_url`"
    ];

    foreach ($alterQueries as $q) {
        // Extract column name to check if exists
        preg_match('/`([^`]+)`\s+VARCHAR/', $q, $matches);
        $colName = $matches[1] ?? '';
        
        if ($colName) {
            $check = $pdo->query("SHOW COLUMNS FROM `users` LIKE '$colName'")->fetch();
            if (!$check) {
                $pdo->exec($q);
                echo "Column `$colName` added successfully.\n";
            } else {
                echo "Column `$colName` already exists.\n";
            }
        }
    }

    // 2. Add keys to settings table
    $defaultSettings = [
        'telegram_bot_token' => '',
        'telegram_group_chat_id' => '',
        'discord_group_webhook' => ''
    ];

    foreach ($defaultSettings as $key => $val) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `settings` WHERE `setting_key` = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            $ins->execute([$key, $val]);
            echo "Setting `$key` initialized.\n";
        } else {
            echo "Setting `$key` already exists.\n";
        }
    }

    echo "=== DB Migration Completed Successfully ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

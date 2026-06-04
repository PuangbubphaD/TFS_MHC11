<?php
require_once __DIR__ . '/../includes/db.php';

try {
    // Check and add columns safely
    $columnsToAdd = [
        'telegram_chat_id' => "VARCHAR(50) DEFAULT NULL",
        'discord_webhook_url' => "VARCHAR(255) DEFAULT NULL",
        'discord_user_id' => "VARCHAR(50) DEFAULT NULL",
        'line_notify_token' => "VARCHAR(100) DEFAULT NULL"
    ];

    foreach ($columnsToAdd as $col => $def) {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
            echo "Added $col to users table.\n";
        } else {
            echo "$col already exists in users table.\n";
        }
    }

    // Check if discord_bot_token setting exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'discord_bot_token'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('discord_bot_token', '')");
        echo "Added discord_bot_token to settings table.\n";
    } else {
        echo "discord_bot_token already exists in settings table.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

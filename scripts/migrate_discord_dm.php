<?php
require_once __DIR__ . '/../includes/db.php';

try {
    // Check if discord_user_id exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'discord_user_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN discord_user_id VARCHAR(50) DEFAULT NULL AFTER discord_webhook_url");
        echo "Added discord_user_id to users table.\n";
    } else {
        echo "discord_user_id already exists in users table.\n";
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

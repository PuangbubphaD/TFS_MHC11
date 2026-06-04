<?php
require_once __DIR__ . '/../includes/db.php';

try {
    $settingsToAdd = [
        'notify_run_time' => '08:00',
        'last_notify_run_date' => ''
    ];

    foreach ($settingsToAdd as $key => $defaultVal) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $defaultVal]);
            echo "Added $key to settings.\n";
        } else {
            echo "$key already exists in settings.\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

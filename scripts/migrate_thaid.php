<?php
require_once __DIR__ . '/../includes/db.php';

echo "=== TFS ThaiD Login Migration ===\n";

try {
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'thaid_sub'");
    $exists = $stmt->fetch();

    if ($exists) {
        echo "ThaiD columns already exist. Skipping.\n";
    } else {
        // Add new columns to users table
        $sql = "
            ALTER TABLE users 
            ADD COLUMN thaid_sub VARCHAR(255) NULL UNIQUE AFTER department,
            ADD COLUMN thaid_pid VARCHAR(13) NULL UNIQUE AFTER thaid_sub,
            ADD COLUMN auth_provider ENUM('local', 'thaid') NOT NULL DEFAULT 'local' AFTER thaid_pid,
            ADD COLUMN thaid_linked_at TIMESTAMP NULL DEFAULT NULL AFTER auth_provider,
            ADD COLUMN account_status ENUM('active', 'pending_approval', 'suspended') NOT NULL DEFAULT 'active' AFTER thaid_linked_at
        ";
        
        $pdo->exec($sql);
        echo "Successfully added ThaiD columns to `users` table.\n";
    }

    // Create logs table if not exists
    $sqlLogs = "
        CREATE TABLE IF NOT EXISTS `logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) DEFAULT NULL,
          `action` varchar(255) NOT NULL,
          `details` text DEFAULT NULL,
          `ip_address` varchar(45) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlLogs);
    echo "Successfully ensured `logs` table exists.\n";

} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}

echo "Migration completed.\n";

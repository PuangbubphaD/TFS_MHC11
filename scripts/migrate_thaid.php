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

} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}

echo "Migration completed.\n";

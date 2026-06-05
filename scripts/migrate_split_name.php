<?php
require_once __DIR__ . '/../includes/db.php';

echo "=== TFS: Split full_name → name + lastname ===\n";

try {
    // 1. Check if 'name' column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
    if ($stmt->fetch()) {
        echo "Columns 'name' and 'lastname' already exist. Skipping schema change.\n";
    } else {
        // Add name and lastname columns after full_name
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN `name` varchar(100) DEFAULT NULL AFTER `full_name`,
            ADD COLUMN `lastname` varchar(100) DEFAULT NULL AFTER `name`
        ");
        echo "Added 'name' and 'lastname' columns.\n";
    }

    // 2. Migrate existing full_name data into name + lastname
    $users = $pdo->query("SELECT id, full_name FROM users WHERE full_name IS NOT NULL AND full_name != ''")->fetchAll();
    
    $updated = 0;
    foreach ($users as $u) {
        $fullName = trim($u['full_name']);
        
        // Remove Thai title prefixes before splitting
        $titles = ['ว่าที่ ร.ต.', 'ว่าที่ ร.ท.', 'ว่าที่ ร.อ.', 'ว่าที่', 'น.ส.', 'นางสาว', 'นาง', 'นาย', 'ดร.'];
        $cleanName = $fullName;
        foreach ($titles as $t) {
            if (mb_strpos($cleanName, $t, 0, 'UTF-8') === 0) {
                $cleanName = mb_substr($cleanName, mb_strlen($t, 'UTF-8'), null, 'UTF-8');
                $cleanName = ltrim($cleanName);
                break;
            }
        }

        // Split: last word = lastname, rest = name
        $parts = preg_split('/\s+/', trim($cleanName));
        if (count($parts) >= 2) {
            $lastname = array_pop($parts);
            $name = implode(' ', $parts);
        } else {
            $name = $cleanName;
            $lastname = '';
        }
        
        $stmt = $pdo->prepare("UPDATE users SET name = ?, lastname = ? WHERE id = ? AND (name IS NULL OR name = '')");
        $stmt->execute([$name, $lastname, $u['id']]);
        if ($stmt->rowCount() > 0) {
            $updated++;
            echo "  [{$u['id']}] '{$fullName}' → name='{$name}', lastname='{$lastname}'\n";
        }
    }

    echo "Migrated {$updated} users.\n";
    echo "Migration completed successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

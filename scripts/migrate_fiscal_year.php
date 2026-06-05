<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    // 1. Add column if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'fiscal_year'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN fiscal_year INT(4) DEFAULT NULL AFTER description");
        echo "Added fiscal_year to projects table.\n";
    } else {
        echo "fiscal_year already exists in projects table.\n";
    }

    // 2. Backfill existing projects
    $projects = $pdo->query("SELECT id, start_date, created_at FROM projects WHERE fiscal_year IS NULL")->fetchAll();
    
    $updatedCount = 0;
    foreach ($projects as $p) {
        // Use start_date if available, otherwise fallback to created_at date
        $dateToUse = !empty($p['start_date']) ? $p['start_date'] : date('Y-m-d', strtotime($p['created_at']));
        $fy = calculateFiscalYear($dateToUse);
        
        $updateStmt = $pdo->prepare("UPDATE projects SET fiscal_year = ? WHERE id = ?");
        $updateStmt->execute([$fy, $p['id']]);
        $updatedCount++;
    }
    
    if ($updatedCount > 0) {
        echo "Successfully updated fiscal_year for $updatedCount existing projects.\n";
    } else {
        echo "No projects needed updating.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

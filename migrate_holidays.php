<?php
// migrate_holidays.php - Create holidays table and import 2026 Thai public holidays from JSON
require_once __DIR__ . '/includes/db.php';

echo "=== STARTING THAI HOLIDAY MIGRATION (2026) ===\n";

try {
    // 1. Create holidays table
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS holidays (
            holiday_date DATE PRIMARY KEY,
            name_th VARCHAR(255) NOT NULL,
            name_en VARCHAR(255),
            type VARCHAR(100)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ";
    $pdo->exec($createTableSQL);
    echo "✓ Table 'holidays' checked/created successfully.\n";

    // 2. Fetch JSON data from URL
    $url = "https://raw.githubusercontent.com/ppraserts/thailand-open-data/main/data/thai-public-holidays/2026.json";
    echo "Fetching holiday data from: $url ...\n";
    
    // Set HTTP context options to handle SSL validation or user agents
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: PHP-TFS-HolidayMigration/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $jsonContent = file_get_contents($url, false, $context);
    
    if ($jsonContent === false) {
        throw new Exception("Failed to fetch content from URL.");
    }
    
    // 3. Parse JSON data
    $data = json_decode($jsonContent, true);
    if ($data === null || !isset($data['holidays'])) {
        throw new Exception("Failed to parse JSON content or 'holidays' key is missing.");
    }
    
    echo "✓ JSON parsed successfully. Found " . count($data['holidays']) . " holidays.\n";

    // 4. Seed database
    $stmt = $pdo->prepare("
        INSERT INTO holidays (holiday_date, name_th, name_en, type)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), type = VALUES(type);
    ");

    $importedCount = 0;
    foreach ($data['holidays'] as $h) {
        $date = $h['date'];
        $nameTh = $h['name_th'];
        $nameEn = $h['name_en'] ?? null;
        $type = $h['type'] ?? 'public_holiday';
        
        $stmt->execute([$date, $nameTh, $nameEn, $type]);
        $importedCount++;
        echo "  -> Imported: $date | $nameTh ($nameEn)\n";
    }

    echo "✓ Successfully imported $importedCount holidays into database!\n";
    echo "=== MIGRATION COMPLETE ===\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

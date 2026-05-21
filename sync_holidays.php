<?php
// sync_holidays.php - Dynamic tool to fetch and sync Thai public holidays for any year

// 1. Detect environment (CLI vs Browser)
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    // If browser, require authentication
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    // Only allow Admin to run this sync script and edit settings
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
            <h3>❌ Access Denied (ปฏิเสธการเข้าถึง)</h3>
            <p>เฉพาะผู้ดูแลระบบ (Admin) เท่านั้นที่มีสิทธิ์เข้าถึงเครื่องมือนี้และแก้ไขการตั้งค่า</p>
            <p><a href="index.php">กลับสู่หน้าหลัก</a></p>
        </div>');
    }
    require_once __DIR__ . '/includes/header.php';
} else {
    // If CLI, load db.php and functions.php directly
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/functions.php';
}

// 2. Ensure Settings Table & Default API Url exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");
    $defaultUrl = "https://raw.githubusercontent.com/ppraserts/thailand-open-data/main/data/thai-public-holidays/{year}.json";
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('holiday_api_url', ?)");
    $stmt->execute([$defaultUrl]);
} catch (Exception $e) {
    // Handled gracefully
}

// 3. Handle POST settings save request (only in Browser mode)
$saveSuccess = null;
$saveError = null;
if (!$isCLI && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    // Validate CSRF
    checkCsrfOrDie();
    
    $newUrl = trim($_POST['holiday_api_url'] ?? '');
    if (empty($newUrl)) {
        $saveError = "กรุณาระบุ URL แหล่งข้อมูล API";
    } elseif (strpos($newUrl, 'http://') !== 0 && strpos($newUrl, 'https://') !== 0) {
        $saveError = "URL ต้องขึ้นต้นด้วย http:// หรือ https://";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('holiday_api_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$newUrl, $newUrl]);
            $saveSuccess = "บันทึกแหล่งข้อมูล API สำเร็จเรียบร้อย!";
            
            // Audit Log
            logActivity($pdo, 'UPDATE_HOLIDAY_API_URL', "เปลี่ยน URL แหล่งข้อมูล API วันหยุดเป็น: $newUrl");
        } catch (Exception $e) {
            $saveError = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
        }
    }
}

// 4. Retrieve current API URL from database
$apiUrl = "https://raw.githubusercontent.com/ppraserts/thailand-open-data/main/data/thai-public-holidays/{year}.json"; // default
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'holiday_api_url'");
    $stmt->execute();
    $dbUrl = $stmt->fetchColumn();
    if ($dbUrl) {
        $apiUrl = $dbUrl;
    }
} catch (Exception $e) {
    // Keep default
}

// 5. Determine target year
$targetYear = intval(date('Y'));
$runSync = false;

if ($isCLI) {
    $runSync = true;
    if (isset($argv[1])) {
        $targetYear = intval($argv[1]);
    }
} else {
    if (isset($_GET['action']) && $_GET['action'] === 'sync') {
        $runSync = true;
        if (isset($_GET['year'])) {
            $targetYear = intval($_GET['year']);
        }
    }
}

$success = false;
$outputLog = [];

if ($runSync) {
    $outputLog[] = "=== กำลังเริ่มอัปเดตข้อมูลวันหยุดนักขัตฤกษ์ประจำปี พ.ศ. " . ($targetYear + 543) . " (ค.ศ. $targetYear) ===";
    
    // Validation
    if ($targetYear < 2020 || $targetYear > 2040) {
        $msg = "Error: Invalid year. Please specify a year between 2020 and 2040.";
        $outputLog[] = "❌ $msg";
    } else {
        try {
            // Ensure holidays table exists
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS holidays (
                    holiday_date DATE PRIMARY KEY,
                    name_th VARCHAR(255) NOT NULL,
                    name_en VARCHAR(255),
                    type VARCHAR(100)
                ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            ";
            $pdo->exec($createTableSQL);
            $outputLog[] = "✓ ตรวจสอบโครงสร้างตาราง 'holidays' สำเร็จ";

            // Prepare URL (replace {year} placeholder)
            $url = str_replace('{year}', $targetYear, $apiUrl);
            $outputLog[] = "กำลังดึงข้อมูลจากแหล่งข้อมูล API: $url ...";

            // Fetch content
            $opts = [
                "http" => [
                    "method" => "GET",
                    "header" => "User-Agent: PHP-TFS-HolidaySync/1.0\r\n"
                ]
            ];
            $context = stream_context_create($opts);
            $jsonContent = @file_get_contents($url, false, $context);

            if ($jsonContent === false) {
                throw new Exception("ไม่พบไฟล์ข้อมูลวันหยุดบน API หรือไม่สามารถเชื่อมต่ออินเทอร์เน็ตได้");
            }

            // Parse
            $data = json_decode($jsonContent, true);
            if ($data === null || !isset($data['holidays'])) {
                throw new Exception("รูปแบบโครงสร้าง JSON ข้อมูลวันหยุดไม่ถูกต้อง (ต้องมีคีย์หลัก 'holidays')");
            }

            $outputLog[] = "✓ ดึงข้อมูลสำเร็จ พบวันหยุดทั้งหมด " . count($data['holidays']) . " วัน";

            // Database Sync
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
                $outputLog[] = "  [บันทึกแล้ว] $date : $nameTh" . ($nameEn ? " ($nameEn)" : "");
            }

            $success = true;
            $outputLog[] = "✓ เสร็จสิ้น! นำเข้าและซิงค์ข้อมูลวันหยุดสำเร็จรวม $importedCount รายการ";
            
            // Log audit trail
            if (!$isCLI) {
                logActivity($pdo, 'SYNC_HOLIDAYS', "ซิงค์ข้อมูลวันหยุดปี $targetYear สำเร็จรวม $importedCount วัน ผ่าน API: $url");
            }

        } catch (Exception $e) {
            $success = false;
            $outputLog[] = "❌ ข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// 6. Render Output
if ($isCLI) {
    foreach ($outputLog as $line) {
        echo "$line\n";
    }
    exit($success ? 0 : 1);
} else {
    ?>
    <div class="topbar">
        <div>
            <h2>⚙️ จัดการข้อมูลวันหยุดนักขัตฤกษ์ & แหล่งข้อมูล API</h2>
            <div class="topbar-breadcrumb">
                <a href="index.php" style="color:var(--text-muted)">ตั้งค่าระบบ</a> / จัดการวันหยุด
            </div>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-outline">← กลับแดชบอร์ด</a>
        </div>
    </div>

    <div class="page-content">
        <?php if ($saveSuccess): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($saveSuccess) ?></div>
        <?php endif; ?>
        <?php if ($saveError): ?>
            <div class="alert alert-danger">❌ <?= htmlspecialchars($saveError) ?></div>
        <?php endif; ?>

        <div class="grid grid-2 mb-3">
            <!-- 1. Edit API Source URL Card -->
            <div class="card fade-in" style="display:flex;flex-direction:column">
                <div class="card-header">
                    <h3>🌐 แหล่งข้อมูล API วันหยุด</h3>
                </div>
                <div style="display:flex;flex-direction:column;flex-grow:1;justify-content:space-between">
                    <p style="font-size:0.85rem;color:var(--text-muted);margin:0.5rem 0 1rem 0;">
                        ระบุปลายทาง (Endpoint) ที่จะใช้ดึงไฟล์ JSON วันหยุด โดยสามารถใส่สัญลักษณ์ placeholder <code>{year}</code> เพื่อให้ระบบแทนที่ด้วยปีที่เลือกแบบไดนามิก
                    </p>
                    
                    <form method="POST" action="sync_holidays.php" style="display:flex;flex-direction:column;flex-grow:1;justify-content:space-between">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div style="margin-bottom:1.5rem">
                            <label class="form-label" style="font-weight:700">API URL Source:</label>
                            <input type="text" name="holiday_api_url" class="form-control" 
                                   value="<?= htmlspecialchars($apiUrl) ?>" 
                                   placeholder="เช่น https://domain.com/holidays/{year}.json" 
                                   required style="margin-top:0.4rem;font-family:monospace;font-size:0.82rem">
                            <span style="font-size:0.75rem;color:var(--text-muted);display:block;margin-top:0.4rem">
                                💡 แหล่งข้อมูลเริ่มต้น: <code style="word-break: break-all; white-space: normal; display: inline-block; max-width: 100%;">https://raw.githubusercontent.com/ppraserts/thailand-open-data/main/data/thai-public-holidays/{year}.json</code>
                            </span>
                        </div>
                        
                        <div style="display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-primary" style="display:flex;align-items:center;gap:0.5rem">
                                💾 บันทึกแหล่งข้อมูล
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 2. Sync Holidays Action Card -->
            <div class="card fade-in" style="display:flex;flex-direction:column">
                <div class="card-header">
                    <h3>🔄 ซิงค์ข้อมูลวันหยุดนักขัตฤกษ์</h3>
                </div>
                <div style="display:flex;flex-direction:column;flex-grow:1;justify-content:space-between">
                    <p style="font-size:0.85rem;color:var(--text-muted);margin:0.5rem 0 1rem 0;">
                        เลือกปีที่ต้องการเพื่อเริ่มดึงข้อมูลวันหยุดจาก API และทำการบันทึกเพื่ออัปเดตระบบวันกำหนดส่ง (Deadlines) ของกิจกรรมย่อย
                    </p>
                    
                    <form method="GET" action="sync_holidays.php" style="display:flex;flex-direction:column;flex-grow:1;justify-content:space-between">
                        <input type="hidden" name="action" value="sync">
                        
                        <div style="margin-bottom:1.5rem">
                            <label class="form-label" style="font-weight:700">เลือกปีที่ต้องการซิงค์:</label>
                            <select name="year" class="form-control" style="margin-top:0.4rem">
                                <?php 
                                $currentYear = intval(date('Y'));
                                for ($y = $currentYear - 2; $y <= $currentYear + 5; $y++): 
                                ?>
                                    <option value="<?= $y ?>" <?= $y === $targetYear ? 'selected' : '' ?>>
                                        ปี พ.ศ. <?= $y + 543 ?> (ค.ศ. <?= $y ?>)
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div style="display:flex;justify-content:flex-end">
                            <button type="submit" class="btn btn-accent" style="display:flex;align-items:center;gap:0.5rem">
                                ⚡ เริ่มกระบวนการซิงค์ข้อมูล
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 3. Terminal-Style Run Log Panel -->
        <?php if ($runSync): ?>
            <div class="card fade-in">
                <div class="card-header">
                    <h3>📜 ผลลัพธ์กระบวนการซิงค์ข้อมูล (Execution Log)</h3>
                </div>
                
                <div style="background:#1a202c;color:#a0aec0;font-family:monospace;padding:1.5rem;border-radius:8px;line-height:1.6;font-size:0.875rem;overflow-x:auto;max-height:400px;overflow-y:auto;border:1px solid #2d3748;margin-top:1rem;">
                    <?php foreach ($outputLog as $line): ?>
                        <div style="<?= strpos($line, '❌') !== false ? 'color:#fc8181;' : (strpos($line, '✓') !== false ? 'color:#68d391;' : '') ?>">
                            <?= htmlspecialchars($line) ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success" style="margin-top:1.5rem;margin-bottom:0">
                        🎉 <strong>ซิงค์ข้อมูลสำเร็จ!</strong> ระบบได้นำเข้าและอัปเดตวันหยุดของปี พ.ศ. <?= $targetYear + 543 ?> เรียบร้อยแล้ว ระบบจะหักวันทำการและคำนวณเดดไลน์ใหม่ตามนี้ทันที
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin-top:1.5rem;margin-bottom:0">
                        ⚠️ <strong>ซิงค์ล้มเหลว:</strong> โปรดตรวจสอบว่า URL แหล่งข้อมูล API ด้านบนรองรับปีที่เลือก หรือตรวจสอบการเชื่อมต่ออินเทอร์เน็ตของเซิร์ฟเวอร์
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
}

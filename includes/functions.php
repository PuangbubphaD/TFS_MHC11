<?php
/**
 * Calculate working days between two dates (excluding weekends and public holidays)
 */
function getWorkingDays(string $startDate, string $endDate, array $holidays = []): int {
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);
    
    $isNegative = false;
    if ($start > $end) {
        $temp = $start;
        $start = $end;
        $end = $temp;
        $isNegative = true;
    }
    
    $workingDays = 0;
    
    $interval = new DateInterval('P1D');
    $period   = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    foreach ($period as $date) {
        // Skip Saturday (6) and Sunday (7)
        $dayOfWeek = (int)$date->format('N');
        if ($dayOfWeek >= 6) {
            continue;
        }
        // Skip public holidays
        $formattedDate = $date->format('Y-m-d');
        if (in_array($formattedDate, $holidays)) {
            continue;
        }
        $workingDays++;
    }
    
    // Decrement 1 day to represent "remaining working days after today" if we are calculating future days
    if ($workingDays > 0) {
        $workingDays--;
    }
    
    return $isNegative ? -$workingDays : $workingDays;
}

/**
 * Get status badge HTML based on deadline and current status (excludes weekends & public holidays)
 */
function getStatusBadge(string $status, ?string $deadline = null, array $holidays = [], ?string $completed_date = null): string {
    $labels = [
        'pending'     => 'รอดำเนินการ',
        'in_progress' => 'กำลังดำเนินการ',
        'completed'   => 'เสร็จสิ้น',
        'overdue'     => 'เกินกำหนด',
        'planned'     => 'วางแผนแล้ว',
        'ongoing'     => 'กำลังดำเนินการ',
        'cancelled'   => 'ยกเลิก',
        'active'      => 'ดำเนินการอยู่',
        'draft'       => 'ร่าง',
    ];

    $colorMap = [
        'pending'     => 'var(--status-yellow)',
        'in_progress' => 'var(--status-blue)',
        'completed'   => 'var(--status-green)',
        'overdue'     => 'var(--status-red)',
        'planned'     => 'var(--status-yellow)',
        'ongoing'     => 'var(--status-blue)',
        'cancelled'   => '#95a5a6',
        'active'      => 'var(--status-blue)',
        'draft'       => '#95a5a6',
    ];

    // Auto-detect overdue using working days logic
    if ($deadline && in_array($status, ['pending', 'in_progress', 'planned', 'ongoing'])) {
        $daysLeft = getWorkingDays(date('Y-m-d'), $deadline, $holidays);
        if ($daysLeft < 0) {
            $status = 'overdue';
        }
    }

    // Check for late completion (Option 1)
    if ($status === 'completed' && $deadline && $completed_date) {
        if ($completed_date > $deadline) {
            $daysLate = getWorkingDays($deadline, $completed_date, $holidays);
            if ($daysLate > 0) {
                $label = "เสร็จสิ้น (ล่าช้า $daysLate วัน)";
                $color = 'var(--status-yellow)';
                // Use white text for yellow background as requested
                return "<span class=\"badge\" style=\"background:$color; color:#fff;\">$label</span>";
            }
        }
    }

    $label = $labels[$status] ?? $status;
    $color = $colorMap[$status] ?? '#999';

    return "<span class=\"badge\" style=\"background:$color\">$label</span>";
}

/**
 * Get deadline alert color (green/yellow/red) for a given deadline using working days
 */
function getDeadlineColor(?string $deadline, array $holidays = []): string {
    if (!$deadline) return 'var(--text-muted)';
    $daysLeft = getWorkingDays(date('Y-m-d'), $deadline, $holidays);
    if ($daysLeft < 0)  return 'var(--status-red)';
    if ($daysLeft <= 5) return 'var(--status-red)';    // 5 working days remaining (critical)
    if ($daysLeft <= 10) return 'var(--status-yellow)'; // 10 working days remaining (warning)
    return 'var(--status-green)';
}

/**
 * Format Thai date
 */
function thaiDate(?string $date): string {
    if (!$date) return '-';
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
               'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d = date('j', strtotime($date));
    $m = $months[(int)date('n', strtotime($date))];
    $y = (int)date('Y', strtotime($date)) + 543;
    return "$d $m $y";
}

/**
 * Format money in Thai Baht
 */
function formatBaht(float $amount): string {
    return '฿' . number_format($amount, 2);
}

/**
 * Calculate budget percentage
 */
function budgetPercent(float $spent, float $total): float {
    if ($total <= 0) return 0;
    return min(100, round(($spent / $total) * 100, 1));
}

/**
 * Get allowed file extensions
 */
function isAllowedFile(string $filename): bool {
    $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Check if extension is in the allowed list
    if (!in_array($ext, $allowed)) {
        return false;
    }
    
    // Defense in depth: check for double extension attacks or execution triggers
    $lower_name = strtolower($filename);
    $blocked = ['.php', '.phtml', '.php3', '.php4', '.php5', '.php7', '.phps', '.pl', '.py', '.jsp', '.asp', '.sh', '.cgi'];
    foreach ($blocked as $b) {
        if (strpos($lower_name, $b) !== false) {
            return false;
        }
    }
    
    // Null byte injection protection
    if (strpos($filename, "\0") !== false) {
        return false;
    }
    
    return true;
}

/**
 * Validate CSRF token for POST requests
 */
function validateCsrfToken(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken) || empty($token)) {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

/**
 * Require CSRF token validation or terminate the request
 */
function checkCsrfOrDie() {
    if (!validateCsrfToken()) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
            <h3>❌ Security Error (CSRF Token Invalid)</h3>
            <p>การดำเนินการถูกปฏิเสธเนื่องจากความปลอดภัยของเซสชันหมดอายุ กรุณาย้อนกลับไปที่หน้ารายการหลักและลองใหม่อีกครั้ง</p>
            <p><a href="index.php" style="color:#c53030;font-weight:700">กลับสู่หน้าหลัก</a></p>
        </div>');
    }
}

/**
 * Check if a file is an image
 */
function isImage(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
}

/**
 * Get icon for file type
 */
function getFileIcon(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf'  => '📄',
        'doc'  => '📝', 'docx' => '📝',
        'xls'  => '📊', 'xlsx' => '📊',
        'ppt'  => '📋', 'pptx' => '📋',
        'jpg'  => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️', 'webp' => '🖼️',
        'zip'  => '🗜️',
        'txt'  => '📃',
    ];
    return $icons[$ext] ?? '📎';
}

/**
 * Format currency in full numeric Thai format
 */
function formatThaiAmount($amount) {
    return number_format($amount, 2) . ' บาท';
}

/**
 * Sync Thai public holidays from Bank of Thailand (BOT) API or fallback to Nager.Date or static list
 */
function syncThaiHolidays(PDO $pdo, int $year) {
    // 1. Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS thai_holidays (
        holiday_date DATE PRIMARY KEY,
        name_th VARCHAR(255) NOT NULL,
        name_en VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Check if already seeded for this year
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM thai_holidays WHERE YEAR(holiday_date) = ?");
    $stmt->execute([$year]);
    if ($stmt->fetchColumn() > 0) {
        return; // Already cached
    }

    $holidays = [];
    
    // 3. Try BOT API if BOT_API_KEY is configured
    if (defined('BOT_API_KEY') && BOT_API_KEY !== '') {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://gateway.api.bot.or.th/financial-institutions-holidays?year=" . $year);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "x-ibm-client-id: " . BOT_API_KEY,
                "accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['result']['api_data'])) {
                    foreach ($data['result']['api_data'] as $item) {
                        $holidays[] = [
                            'date' => $item['Date'],
                            'name_th' => $item['HolidayNameTH'],
                            'name_en' => $item['HolidayNameEN']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Silence & fallback
        }
    }

    // 4. Fallback: Free Nager.Date API
    if (empty($holidays)) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://date.nager.at/api/v3/PublicHolidays/" . $year . "/TH");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $holidays[] = [
                            'date' => $item['date'],
                            'name_th' => $item['localName'] ?: $item['name'],
                            'name_en' => $item['name']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback to static
        }
    }

    // 5. Hardcoded offline fallback for common Thai holidays
    if (empty($holidays)) {
        $holidays = [
            ['date' => "$year-01-01", 'name_th' => 'วันขึ้นปีใหม่', 'name_en' => "New Year's Day"],
            ['date' => "$year-04-06", 'name_th' => 'วันจักรี', 'name_en' => 'Chakri Memorial Day'],
            ['date' => "$year-04-13", 'name_th' => 'วันสงกรานต์', 'name_en' => 'Songkran Festival'],
            ['date' => "$year-04-14", 'name_th' => 'วันสงกรานต์', 'name_en' => 'Songkran Festival'],
            ['date' => "$year-04-15", 'name_th' => 'วันสงกรานต์', 'name_en' => 'Songkran Festival'],
            ['date' => "$year-05-01", 'name_th' => 'วันแรงงานแห่งชาติ', 'name_en' => 'National Labour Day'],
            ['date' => "$year-05-04", 'name_th' => 'วันฉัตรมงคล', 'name_en' => 'Coronation Day'],
            ['date' => "$year-06-03", 'name_th' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ พระบรมราชินี', 'name_en' => "Queen Suthida's Birthday"],
            ['date' => "$year-07-28", 'name_th' => 'วันเฉลิมพระชนมพรรษาพระบาทสมเด็จพระเจ้าอยู่หัว', 'name_en' => "King Vajiralongkorn's Birthday"],
            ['date' => "$year-08-12", 'name_th' => 'วันแม่แห่งชาติ', 'name_en' => "Mother's Day"],
            ['date' => "$year-10-13", 'name_th' => 'วันคล้ายวันสวรรคต ร.9', 'name_en' => "King Bhumibol Memorial Day"],
            ['date' => "$year-10-23", 'name_th' => 'วันปิยมหาราช', 'name_en' => 'Chulalongkorn Day'],
            ['date' => "$year-12-05", 'name_th' => 'วันพ่อแห่งชาติ', 'name_en' => "Father's Day"],
            ['date' => "$year-12-10", 'name_th' => 'วันรัฐธรรมนูญ', 'name_en' => 'Constitution Day'],
            ['date' => "$year-12-31", 'name_th' => 'วันสิ้นปี', 'name_en' => "New Year's Eve"],
        ];
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO thai_holidays (holiday_date, name_th, name_en) VALUES (?, ?, ?)");
    foreach ($holidays as $h) {
        $stmt->execute([$h['date'], $h['name_th'], $h['name_en']]);
    }
}

/**
 * Calculate working day deadline by skipping weekends and Thai public holidays
 */
function getWorkingDayDeadline(PDO $pdo, string $startDateStr, int $days, string $type = 'before'): string {
    $date = new DateTime($startDateStr);
    if ($days === 0) {
        return $date->format('Y-m-d');
    }

    $year = (int)$date->format('Y');
    
    // Sync holidays for this year, previous year, and next year to cover all possibilities
    syncThaiHolidays($pdo, $year);
    syncThaiHolidays($pdo, $year - 1);
    syncThaiHolidays($pdo, $year + 1);

    // Fetch cached holidays
    $stmt = $pdo->query("SELECT holiday_date FROM thai_holidays");
    $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $daysCounted = 0;
    while ($daysCounted < $days) {
        if ($type === 'before') {
            $date->modify('-1 day');
        } else {
            $date->modify('+1 day');
        }

        $dayOfWeek = (int)$date->format('N'); // 1 = Mon, 6 = Sat, 7 = Sun
        $currentDateStr = $date->format('Y-m-d');

        if ($dayOfWeek < 6 && !in_array($currentDateStr, $holidays)) {
            $daysCounted++;
        }
    }

    return $date->format('Y-m-d');
}

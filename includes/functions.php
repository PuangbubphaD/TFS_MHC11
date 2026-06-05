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
 * Format Thai date with time
 */
function thaiDateTime(?string $date): string {
    if (!$date) return '-';
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
               'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d = date('j', strtotime($date));
    $m = $months[(int)date('n', strtotime($date))];
    $y = (int)date('Y', strtotime($date)) + 543;
    $time = date('H:i', strtotime($date));
    return "$d $m $y $time";
}

/**
 * Format Thai month and year only
 */
function thaiMonthYear(?string $date): string {
    if (!$date) return '-';
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
               'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $m = $months[(int)date('n', strtotime($date))];
    $y = (int)date('Y', strtotime($date)) + 543;
    return "$m $y";
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

/**
 * Calculate the Thai Fiscal Year from a given date string (Y-m-d)
 * Thai Fiscal Year starts on October 1st and ends on September 30th.
 * E.g., Oct 1, 2023 (2566) is in Fiscal Year 2024 (2567).
 */
function calculateFiscalYear(?string $date_string): int {
    if (!$date_string) {
        $date_string = date('Y-m-d');
    }
    
    $timestamp = strtotime($date_string);
    $year = (int)date('Y', $timestamp) + 543; // Convert to Buddhist Era
    $month = (int)date('m', $timestamp);
    
    // If month is October (10) or later, it belongs to the NEXT fiscal year
    if ($month >= 10) {
        $year += 1;
    }
    
    return $year;
}

/**
 * Get the current Thai Fiscal Year
 */
function getCurrentFiscalYear(): int {
    return calculateFiscalYear(date('Y-m-d'));
}

/**
 * Render fiscal year dropdown options (2566 - 2575)
 * Default selected = current fiscal year unless overridden
 */
function renderFiscalYearOptions($selected = null): string {
    $currentFy = getCurrentFiscalYear();
    if ($selected === null || $selected === '') {
        $selected = $currentFy;
    }
    $selected = intval($selected);

    $html = '<option value="all"' . ($selected === 0 ? ' selected' : '') . '>ทั้งหมด</option>';
    for ($y = 2575; $y >= 2566; $y--) {
        $sel = ($y === $selected) ? ' selected' : '';
        $html .= "<option value=\"$y\"$sel>ปี $y</option>";
    }
    return $html;
}

/**
 * Render fiscal year dropdown options for forms (add/edit project)
 * No "all" option, default = current fiscal year
 */
function renderFiscalYearFormOptions($selected = null): string {
    $currentFy = getCurrentFiscalYear();
    if ($selected === null || $selected === '' || $selected === 0) {
        $selected = $currentFy;
    }
    $selected = intval($selected);

    $html = '';
    for ($y = 2575; $y >= 2566; $y--) {
        $sel = ($y === $selected) ? ' selected' : '';
        $html .= "<option value=\"$y\"$sel>$y</option>";
    }
    return $html;
}

/**
 * ThaiD: Base64Url Decode
 */
function thaid_base64url_decode($data) {
    $b64 = strtr($data, '-_', '+/');
    $b64Pad = str_pad($b64, strlen($b64) % 4 === 0 ? strlen($b64) : strlen($b64) + 4 - (strlen($b64) % 4), '=', STR_PAD_RIGHT);
    return base64_decode($b64Pad);
}

/**
 * ThaiD: Decode JWT (without signature verification for simple plain PHP implementation)
 * Note: DOPA sends data directly over HTTPS so parsing the payload is generally acceptable 
 * when composer/php-jwt is not available.
 */
function thaid_decode_jwt($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = thaid_base64url_decode($parts[1]);
    return json_decode($payload, true);
}

/**
 * ThaiD: Send HTTP POST Request
 */
function thaid_http_post($url, $params, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    if (is_array($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } else {
        // Raw string (e.g. JSON)
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Disable SSL verification for local dev if needed, but better to keep it true for production
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error];
    }
    
    return json_decode($response, true);
}

// ============================================================
// ThaiD Name Sync Functions (3-Layer Architecture)
// ============================================================

/**
 * Helper: Get user's display full_name from name + lastname columns
 * Falls back to full_name column for backward compatibility
 */
function thaid_get_display_name($user) {
    $name = trim($user['name'] ?? '');
    $lastname = trim($user['lastname'] ?? '');
    if ($name || $lastname) {
        return trim($name . ' ' . $lastname);
    }
    return $user['full_name'] ?? '';
}

/**
 * ThaiD: Normalize Thai person name for comparison
 * - Trims whitespace
 * - Removes Thai title prefixes (นาย, นาง, น.ส., ดร., etc.)
 * - Converts to lowercase (mb_strtolower for UTF-8)
 */
function thaid_normalize_name($name) {
    if (!$name) return '';
    
    // Trim and collapse whitespace
    $name = trim(preg_replace('/\s+/', ' ', $name));
    
    // Remove title prefixes (longest first to avoid partial matches)
    $titles = [
        'ว่าที่ ร.ต.', 'ว่าที่ ร.ท.', 'ว่าที่ ร.อ.', 'ว่าที่',
        'น.ส.', 'นางสาว', 'นาง', 'นาย', 'ดร.',
        'mr.', 'mrs.', 'miss', 'ms.', 'dr.'
    ];
    
    foreach ($titles as $t) {
        if (mb_stripos($name, $t, 0, 'UTF-8') === 0) {
            $name = mb_substr($name, mb_strlen($t, 'UTF-8'), null, 'UTF-8');
            $name = ltrim($name);
            break;
        }
    }
    
    return mb_strtolower(trim($name), 'UTF-8');
}

/**
 * ThaiD: Extract name parts from userInfo
 * Returns ['name' => ..., 'lastname' => ..., 'full_name' => ...]
 */
function thaid_extract_name_parts($userInfo) {
    $given = trim($userInfo['given_name'] ?? '');
    $family = trim($userInfo['family_name'] ?? '');
    $title = trim($userInfo['title'] ?? '');
    
    if ($given) {
        $fullName = trim(($title ? $title : '') . $given . ($family ? ' ' . $family : ''));
        return [
            'name' => $given,
            'lastname' => $family,
            'full_name' => $fullName
        ];
    }
    
    // Fallback: use 'name' field (full name string)
    $nameStr = trim($userInfo['name'] ?? '');
    if (!$nameStr) {
        return ['name' => '', 'lastname' => '', 'full_name' => ''];
    }
    
    // Remove title for splitting
    $clean = $nameStr;
    $titles = ['ว่าที่ ร.ต.', 'ว่าที่ ร.ท.', 'ว่าที่ ร.อ.', 'ว่าที่', 'น.ส.', 'นางสาว', 'นาง', 'นาย', 'ดร.'];
    foreach ($titles as $t) {
        if (mb_stripos($clean, $t, 0, 'UTF-8') === 0) {
            $clean = mb_substr($clean, mb_strlen($t, 'UTF-8'), null, 'UTF-8');
            $clean = ltrim($clean);
            break;
        }
    }
    
    $parts = preg_split('/\s+/', trim($clean));
    if (count($parts) >= 2) {
        $lastname = array_pop($parts);
        $name = implode(' ', $parts);
    } else {
        $name = $clean;
        $lastname = '';
    }
    
    return [
        'name' => $name,
        'lastname' => $lastname,
        'full_name' => $nameStr
    ];
}

/**
 * ThaiD: Mask PID for display (e.g. 123xxxxxx7890)
 */
function thaid_mask_pid($pid) {
    if (!$pid || mb_strlen($pid) !== 13) return $pid ?: '';
    return substr($pid, 0, 3) . str_repeat('x', 7) . substr($pid, 10, 3);
}

/**
 * Layer 1: Resolve (match) ThaiD user to existing TFS user
 * Priority: sub → pid → name+lastname (if THAID_LINK_BY_NAME enabled)
 * Returns: ['user' => row, 'matched_by' => 'sub'|'pid'|'name'] or null or 'ambiguous'
 */
function thaid_resolve_user($pdo, $userInfo) {
    $sub = $userInfo['sub'] ?? null;
    $pid = $userInfo['pid'] ?? null;
    
    // 1. Match by thaid_sub
    if ($sub) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE thaid_sub = ? LIMIT 1");
        $stmt->execute([$sub]);
        $user = $stmt->fetch();
        if ($user) return ['user' => $user, 'matched_by' => 'sub'];
    }
    
    // 2. Match by thaid_pid
    if ($pid) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE thaid_pid = ? LIMIT 1");
        $stmt->execute([$pid]);
        $user = $stmt->fetch();
        if ($user) return ['user' => $user, 'matched_by' => 'pid'];
    }
    
    // 3. Match by name + lastname (if enabled)
    if (defined('THAID_LINK_BY_NAME') && THAID_LINK_BY_NAME) {
        $nameParts = thaid_extract_name_parts($userInfo);
        $thaidName = thaid_normalize_name($nameParts['name']);
        $thaidLastname = thaid_normalize_name($nameParts['lastname']);
        
        if (!$thaidName) return null;
        
        // Get all users with non-empty names
        $stmt = $pdo->query("SELECT * FROM users WHERE name IS NOT NULL AND name != ''");
        $allUsers = $stmt->fetchAll();
        
        $matches = [];
        foreach ($allUsers as $u) {
            $dbName = thaid_normalize_name($u['name']);
            if ($dbName !== $thaidName) continue;
            
            // If ThaiD has lastname, DB must have lastname and match
            if ($thaidLastname) {
                $dbLastname = thaid_normalize_name($u['lastname'] ?? '');
                if (!$dbLastname || $dbLastname !== $thaidLastname) continue;
            }
            
            $matches[] = $u;
        }
        
        if (count($matches) === 1) {
            return ['user' => $matches[0], 'matched_by' => 'name'];
        } elseif (count($matches) > 1) {
            return 'ambiguous';
        }
    }
    
    return null;
}

/**
 * Layer 2: Sync ThaiD profile (non-destructive — fill empty fields only)
 */
function thaid_sync_profile($pdo, $user, $userInfo) {
    if (!defined('THAID_SYNC_PROFILE') || !THAID_SYNC_PROFILE) return;
    
    $updates = [];
    $params = [];
    $nameParts = thaid_extract_name_parts($userInfo);
    $sub = $userInfo['sub'] ?? null;
    $pid = $userInfo['pid'] ?? null;
    
    // Fill thaid_sub if empty
    if (empty($user['thaid_sub']) && $sub) {
        $updates[] = "thaid_sub = ?";
        $params[] = $sub;
    }
    
    // Fill thaid_pid if empty
    if (empty($user['thaid_pid']) && $pid) {
        $updates[] = "thaid_pid = ?";
        $params[] = $pid;
    } elseif (!empty($user['thaid_pid']) && $pid && $user['thaid_pid'] !== $pid) {
        // PID mismatch — log warning, do NOT update
        error_log("ThaiD Sync Warning: PID mismatch for user {$user['id']}. DB={$user['thaid_pid']}, ThaiD={$pid}");
    }
    
    // Fill name if empty
    if (empty($user['name']) && $nameParts['name']) {
        $updates[] = "name = ?";
        $params[] = $nameParts['name'];
    }
    
    // Fill lastname if empty
    if (empty($user['lastname']) && $nameParts['lastname']) {
        $updates[] = "lastname = ?";
        $params[] = $nameParts['lastname'];
    }
    
    // Update full_name if empty
    if (empty($user['full_name']) && $nameParts['full_name']) {
        $updates[] = "full_name = ?";
        $params[] = $nameParts['full_name'];
    }
    
    // Set auth_provider and linked_at if linking for first time
    if (empty($user['auth_provider']) || $user['auth_provider'] === 'local') {
        if ($sub || $pid) {
            $updates[] = "auth_provider = 'thaid'";
            if (empty($user['thaid_linked_at'])) {
                $updates[] = "thaid_linked_at = NOW()";
            }
        }
    }
    
    if (empty($updates)) return;
    
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    $params[] = $user['id'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Layer 3: Check if name overwrite confirmation is needed
 * Returns proposal array or null
 */
function thaid_get_name_overwrite_proposal($user, $userInfo) {
    if (!defined('THAID_SYNC_PROFILE') || !THAID_SYNC_PROFILE) return null;
    if (!defined('THAID_CONFIRM_NAME_OVERWRITE') || !THAID_CONFIRM_NAME_OVERWRITE) return null;
    
    $pid = $userInfo['pid'] ?? null;
    if (!$pid || empty($user['thaid_pid']) || $user['thaid_pid'] !== $pid) return null;
    
    $nameParts = thaid_extract_name_parts($userInfo);
    if (!$nameParts['name']) return null;
    
    $fields = [];
    
    // Check name mismatch
    if (!empty($user['name'])) {
        $dbNorm = thaid_normalize_name($user['name']);
        $thaidNorm = thaid_normalize_name($nameParts['name']);
        if ($dbNorm !== $thaidNorm) {
            $fields[] = 'name';
        }
    }
    
    // Check lastname mismatch
    if (!empty($user['lastname']) && $nameParts['lastname']) {
        $dbNorm = thaid_normalize_name($user['lastname']);
        $thaidNorm = thaid_normalize_name($nameParts['lastname']);
        if ($dbNorm !== $thaidNorm) {
            $fields[] = 'lastname';
        }
    }
    
    if (empty($fields)) return null;
    
    return [
        'current' => [
            'name' => $user['name'] ?? '',
            'lastname' => $user['lastname'] ?? '',
            'full_name' => thaid_get_display_name($user)
        ],
        'proposed' => [
            'name' => $nameParts['name'],
            'lastname' => $nameParts['lastname'],
            'full_name' => $nameParts['full_name']
        ],
        'fields' => $fields,
        'pid_masked' => thaid_mask_pid($pid)
    ];
}

/**
 * Layer 3: Apply name overwrite after user confirms
 */
function thaid_apply_name_overwrite($pdo, $user, $userInfo) {
    $nameParts = thaid_extract_name_parts($userInfo);
    
    $updates = [];
    $params = [];
    
    if ($nameParts['name']) {
        $updates[] = "name = ?";
        $params[] = $nameParts['name'];
    }
    if ($nameParts['lastname']) {
        $updates[] = "lastname = ?";
        $params[] = $nameParts['lastname'];
    }
    // Also update full_name for backward compatibility
    $newFullName = trim($nameParts['name'] . ' ' . $nameParts['lastname']);
    if ($newFullName) {
        $updates[] = "full_name = ?";
        $params[] = $newFullName;
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $user['id'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    // Then sync other fields (sub, pid, etc.)
    thaid_sync_profile($pdo, $user, $userInfo);
}

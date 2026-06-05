<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!defined('THAID_ENABLED') || !THAID_ENABLED) {
    die("ThaiD Login is disabled.");
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state) {
    header("Location: login.php?error=thaid_invalid_request");
    exit;
}

if (!isset($_SESSION['thaid_state']) || $state !== $_SESSION['thaid_state']) {
    header("Location: login.php?error=thaid_state_mismatch");
    exit;
}
unset($_SESSION['thaid_state']); // Prevent reuse

// ============================================================
// Step 1: Exchange Code for Token
// ============================================================
$token_url = defined('THAID_USE_SANDBOX') && THAID_USE_SANDBOX 
    ? 'https://imauthsbx.bora.dopa.go.th/api/v2/oauth2/token/'
    : 'https://imauth.bora.dopa.go.th/api/v2/oauth2/token/';

$token_params = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => THAID_CLIENT_ID,
    'client_secret' => THAID_CLIENT_SECRET,
    'redirect_uri'  => THAID_REDIRECT_URI
];

$auth_header = 'Basic ' . base64_encode(THAID_CLIENT_ID . ':' . THAID_CLIENT_SECRET);

$response = thaid_http_post($token_url, $token_params, [
    'Authorization: ' . $auth_header,
    'Content-Type: application/x-www-form-urlencoded'
]);

if (isset($response['error']) || !isset($response['access_token'])) {
    error_log("ThaiD Token Error: " . json_encode($response));
    header("Location: login.php?error=thaid_token_failed");
    exit;
}

// ============================================================
// Step 2: Extract Profile (resolveUserInfo)
// ============================================================
$userInfo = [];

// Direct fields from token response
$userInfo['pid'] = $response['pid'] ?? null;
$userInfo['given_name'] = $response['given_name'] ?? '';
$userInfo['family_name'] = $response['family_name'] ?? '';
$userInfo['title'] = $response['title'] ?? '';

// Fallback: decode id_token 
$id_token = $response['id_token'] ?? null;
if ($id_token) {
    $jwt_payload = thaid_decode_jwt($id_token);
    if ($jwt_payload) {
        $userInfo['sub'] = $jwt_payload['sub'] ?? null;
        $userInfo['pid'] = $userInfo['pid'] ?: ($jwt_payload['pid'] ?? null);
        $userInfo['given_name'] = $userInfo['given_name'] ?: ($jwt_payload['given_name'] ?? '');
        $userInfo['family_name'] = $userInfo['family_name'] ?: ($jwt_payload['family_name'] ?? '');
        $userInfo['title'] = $userInfo['title'] ?: ($jwt_payload['title'] ?? '');
    }
}

if (!$userInfo['pid']) {
    error_log("ThaiD Profile Error: PID not found in token");
    header("Location: login.php?error=thaid_profile_failed");
    exit;
}

// ============================================================
// Step 3: Layer 1 — Resolve (match) user
// ============================================================
try {
    $result = thaid_resolve_user($pdo, $userInfo);
    
    // Handle ambiguous name match
    if ($result === 'ambiguous') {
        header("Location: login.php?error=thaid_name_ambiguous");
        exit;
    }
    
    if ($result) {
        // ====================================================
        // User found — check status & sync
        // ====================================================
        $user = $result['user'];
        $matchedBy = $result['matched_by'];
        
        // Check account status
        if (isset($user['account_status']) && $user['account_status'] === 'pending_approval') {
            header("Location: login.php?error=thaid_pending_approval");
            exit;
        } elseif (isset($user['account_status']) && $user['account_status'] === 'suspended') {
            header("Location: login.php?error=thaid_suspended");
            exit;
        }
        
        // ====================================================
        // Layer 3: Check if name overwrite confirmation needed
        // ====================================================
        $proposal = thaid_get_name_overwrite_proposal($user, $userInfo);
        
        if ($proposal) {
            // Store in session for confirm page (expires in 30 min)
            $_SESSION['thaid_profile_confirm'] = [
                'user_id' => $user['id'],
                'userInfo' => $userInfo,
                'proposal' => $proposal,
                'expires_at' => time() + 1800 // 30 minutes
            ];
            header("Location: thaid_confirm_profile.php");
            exit;
        }
        
        // ====================================================
        // Layer 2: Sync profile (non-destructive)
        // ====================================================
        thaid_sync_profile($pdo, $user, $userInfo);
        
        // Refresh user data after sync
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch();
        
        // Login
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_name'] = thaid_get_display_name($user);
        $_SESSION['full_name'] = thaid_get_display_name($user);
        $_SESSION['department'] = $user['department'];

        // Log
        $logStmt = $pdo->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'login', ?, ?)");
        $logStmt->execute([
            $user['id'], 
            'Logged in via ThaiD (matched by ' . $matchedBy . ')', 
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        header("Location: index.php");
        exit;
        
    } else {
        // ====================================================
        // User not found — Auto Register
        // ====================================================
        if (defined('THAID_AUTO_REGISTER') && THAID_AUTO_REGISTER) {
            $nameParts = thaid_extract_name_parts($userInfo);
            
            $_SESSION['thaid_register_profile'] = [
                'pid' => $userInfo['pid'],
                'sub' => $userInfo['sub'] ?? null,
                'name' => $nameParts['name'],
                'lastname' => $nameParts['lastname'],
                'full_name' => $nameParts['full_name'],
                'pid_masked' => thaid_mask_pid($userInfo['pid'])
            ];
            header("Location: thaid_register.php");
            exit;
        } else {
            header("Location: login.php?error=thaid_no_account");
            exit;
        }
    }
} catch (PDOException $e) {
    error_log("Database Error in ThaiD Callback: " . $e->getMessage());
    header("Location: login.php?error=db_error");
    exit;
}

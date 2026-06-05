<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!defined('THAID_ENABLED') || !THAID_ENABLED) {
    die("ThaiD Login is disabled.");
}

// Check sandbox or production
$base_url = defined('THAID_USE_SANDBOX') && THAID_USE_SANDBOX 
    ? 'https://imauthsbx.bora.dopa.go.th/api/v2/oauth2/auth/'
    : 'https://imauth.bora.dopa.go.th/api/v2/oauth2/auth/';

// Generate a random state to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['thaid_state'] = $state;

// Build query parameters
$query = http_build_query([
    'response_type' => 'code',
    'client_id'     => THAID_CLIENT_ID,
    'redirect_uri'  => THAID_REDIRECT_URI,
    'scope'         => THAID_SCOPE,
    'state'         => $state
]);

// Redirect to DOPA
header("Location: " . $base_url . "?" . $query);
exit;

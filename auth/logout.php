<?php
// Load authentication and session functions.
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in users can log out.
requireLogin();

// Logout must be submitted using POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify the CSRF token.
$csrfToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    exit("Invalid logout request.");
}

// Remove all session data.
$_SESSION = [];

// Remove the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session.
session_destroy();

// Return to the login page.
header("Location: login.php?logged_out=1");
exit;
?>

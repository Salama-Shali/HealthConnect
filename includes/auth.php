<?php
// Start the session if one is not already active.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check whether a user is logged in.
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

// Get the ID of the currently logged-in user.
function currentUserId(): ?int
{
    return isset($_SESSION['user_id'])
        ? (int) $_SESSION['user_id']
        : null;
}

// Get the role of the currently logged-in user.
function currentUserRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

// Prevent guests from accessing protected pages.
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: /HealthConnect/auth/login.php");
        exit;
    }
}

// Restrict access to specified user roles.
function requireRole($allowedRoles): void
{
    requireLogin();

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    if (!in_array(currentUserRole(), $allowedRoles, true)) {
        http_response_code(403);
        exit("Access denied. You do not have permission to view this page.");
    }
}
?>

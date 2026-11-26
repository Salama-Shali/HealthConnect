<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = $pageTitle ?? 'HealthConnect';
$activeNav = $activeNav ?? '';
$role      = $_SESSION['role'] ?? 'guest';
$userName  = $_SESSION['full_name'] ?? '';
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | HealthConnect</title>
    <link rel="stylesheet" href="/HealthConnect/assets/css/style.css">
</head>
<body>

<nav class="navbar">
    <a href="/HealthConnect/index.php" class="navbar-brand">
        HealthConnect
        <?php if ($role !== 'guest'): ?>
            <span class="role-pill"><?= htmlspecialchars(str_replace('_', ' ', $role)) ?></span>
        <?php endif; ?>
    </a>

    <ul class="nav-links">
        <?php if ($role === 'clinic_staff'): ?>
            <li><a href="/HealthConnect/staff/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Daily Schedule</a></li>
            <li><a href="/HealthConnect/staff/manage_slots.php" class="<?= $activeNav === 'slots' ? 'active' : '' ?>">Manage Slots</a></li>
            <li><a href="/HealthConnect/staff/walkin_booking.php" class="<?= $activeNav === 'walkin' ? 'active' : '' ?>">Register Walk-In</a></li>
            <li><a href="/HealthConnect/staff/reports.php" class="<?= $activeNav === 'reports' ? 'active' : '' ?>">Reports</a></li>
            <li>
                <form method="POST" action="/HealthConnect/auth/logout.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" style="background:none; border:none; color:inherit; font:inherit; cursor:pointer; padding:0.4rem 0.75rem;">
                        Logout<?= $userName ? ' (' . htmlspecialchars($userName) . ')' : '' ?>
                    </button>
                </form>
            </li>

        <?php elseif ($role === 'patient'): ?>
            <li><a href="/HealthConnect/patient/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/HealthConnect/patient/clinics.php" class="<?= $activeNav === 'clinics' ? 'active' : '' ?>">Browse Clinics</a></li>
            <li><a href="/HealthConnect/patient/appointments.php" class="<?= $activeNav === 'appointments' ? 'active' : '' ?>">My Appointments</a></li>
            <li>
                <form method="POST" action="/HealthConnect/auth/logout.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" style="background:none; border:none; color:inherit; font:inherit; cursor:pointer; padding:0.4rem 0.75rem;">
                        Logout<?= $userName ? ' (' . htmlspecialchars($userName) . ')' : '' ?>
                    </button>
                </form>
            </li>

        <?php elseif ($role === 'admin'): ?>
            <li><a href="/HealthConnect/admin/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/HealthConnect/admin/clinics.php" class="<?= $activeNav === 'clinics' ? 'active' : '' ?>">Verify Clinics</a></li>
            <li><a href="/HealthConnect/admin/users.php" class="<?= $activeNav === 'users' ? 'active' : '' ?>">Manage Users</a></li>
            <li><a href="/HealthConnect/admin/reports.php" class="<?= $activeNav === 'reports' ? 'active' : '' ?>">System Reports</a></li>
            <li><a href="/HealthConnect/admin/sms_log.php" class="<?= $activeNav === 'sms' ? 'active' : '' ?>">SMS Log</a></li>
            <li>
                <form method="POST" action="/HealthConnect/auth/logout.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" style="background:none; border:none; color:inherit; font:inherit; cursor:pointer; padding:0.4rem 0.75rem;">
                        Logout<?= $userName ? ' (' . htmlspecialchars($userName) . ')' : '' ?>
                    </button>
                </form>
            </li>

        <?php else: ?>
            <li><a href="/HealthConnect/auth/login.php" class="<?= $activeNav === 'login' ? 'active' : '' ?>">Login</a></li>
            <li><a href="/HealthConnect/auth/register.php" class="<?= $activeNav === 'register' ? 'active' : '' ?>">Register</a></li>
        <?php endif; ?>
    </ul>
</nav>

<main class="container">
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
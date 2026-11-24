<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = $pageTitle ?? 'Patient Portal';
$activeNav = $activeNav ?? '';

$patientName = $_SESSION['full_name'] ?? 'Patient';
$firstInitial = strtoupper(substr(trim($patientName), 0, 1));
$csrfToken = $_SESSION['csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle) ?> | HealthConnect</title>

    <!-- Part B's existing shared stylesheet -->
    <link rel="stylesheet" href="/HealthConnect/assets/css/style.css">

    <!-- Patient portal layout additions -->
    <link rel="stylesheet" href="/HealthConnect/assets/css/patient-portal.css">
</head>

<body class="patient-page">

<div class="patient-app-shell">

    <!-- SIDEBAR -->
    <aside class="patient-sidebar">

        <a href="/HealthConnect/patient/dashboard.php" class="patient-brand">

            <span class="patient-brand-symbol" aria-hidden="true">✚</span>

            <span>
                HealthConnect
                <small>HEALTHCARE PORTAL</small>
            </span>

        </a>

        <p class="patient-sidebar-label">PATIENT MENU</p>

        <nav class="patient-sidebar-nav">

            <a
                href="/HealthConnect/patient/dashboard.php"
                class="patient-nav-link <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>"
            >
                <span class="patient-nav-icon">⌂</span>
                Dashboard
            </a>

            <a
                href="/HealthConnect/patient/clinics.php"
                class="patient-nav-link <?= $activeNav === 'clinics' ? 'is-active' : '' ?>"
            >
                <span class="patient-nav-icon" aria-hidden="true">⌕</span>
                Browse Clinics
            </a>

            <a
                href="/HealthConnect/patient/appointments.php"
                class="patient-nav-link <?= $activeNav === 'appointments' ? 'is-active' : '' ?>"
            >
                <span class="patient-nav-icon">▣</span>
                My Appointments
            </a>

        </nav>

        <div class="patient-sidebar-spacer"></div>

        <div class="patient-sidebar-note">
            <strong>Your health matters.</strong>
            <p>Use HealthConnect to find clinics and manage your appointments.</p>
        </div>

        <form
            action="/HealthConnect/auth/logout.php"
            method="POST"
            class="patient-logout-form"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
            >

            <button type="submit" class="patient-logout-button">
                ↪ &nbsp; Logout
            </button>
        </form>

    </aside>

    <!-- MAIN AREA -->
    <div class="patient-main">

        <header class="patient-topbar">

            <p class="patient-breadcrumb">
                Patient Portal / <?= htmlspecialchars($pageTitle) ?>
            </p>

            <div class="patient-user-pill">

                <div class="patient-avatar">
                    <?= htmlspecialchars($firstInitial, ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div>
                    <strong>
                        <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>
                    </strong>

                    <small>Patient Account</small>
                </div>

            </div>

        </header>

        <main class="patient-content">

            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['flash_success']) ?>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
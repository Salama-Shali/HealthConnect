<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect already-logged-in users straight to their portal
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'clinic_staff':
            header("Location: /HealthConnect/staff/dashboard.php");
            exit;
        case 'patient':
            header("Location: /HealthConnect/patient/dashboard.php");
            exit;
        case 'admin':
            header("Location: /HealthConnect/admin/clinics.php");
            exit;
    }
}

$pageTitle = "Welcome";
$activeNav = "";
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="card" style="text-align: center; padding: 2.5rem 1.5rem; margin-top: 1rem;">
    <h1 style="font-size: 2rem; margin-bottom: 0.75rem; color: var(--primary);">
        Welcome to HealthConnect
    </h1>
    <p class="page-subtitle" style="max-width: 620px; margin: 0 auto 1.75rem; font-size: 1.05rem;">
        SMS-Enabled Clinic Appointment &amp; Scheduling System. Book verified clinic appointments,
        receive timely SMS reminders, and reduce outpatient waiting times.
    </p>

    <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
        <a href="/HealthConnect/auth/login.php" class="btn btn-primary">Login to Your Account</a>
        <a href="/HealthConnect/auth/register.php" class="btn btn-secondary">Register as a Patient</a>
    </div>
</div>

<div class="stats-grid" style="margin-top: 1.5rem;">
    <div class="card">
        <h3 style="margin-bottom: 0.5rem; color: var(--primary);">For Patients</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Browse verified clinics, view real-time open slots, book or reschedule appointments online, and get automated SMS confirmations.
        </p>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 0.5rem; color: var(--primary);">For Clinic Staff</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Publish available time slots, manage daily schedules, register walk-in patients into the live queue, and track attendance.
        </p>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 0.5rem; color: var(--primary);">For Administrators</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Verify registered health facilities, manage user access across roles, and monitor system-wide clinic utilization.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
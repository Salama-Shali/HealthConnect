<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

// Headline numbers for the admin overview.
$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM clinics WHERE verification_status = 'pending')   AS pending_clinics,
        (SELECT COUNT(*) FROM clinics WHERE verification_status = 'verified')  AS verified_clinics,
        (SELECT COUNT(*) FROM clinics WHERE verification_status = 'suspended') AS suspended_clinics,
        (SELECT COUNT(*) FROM users WHERE role = 'patient'      AND is_active = 1) AS active_patients,
        (SELECT COUNT(*) FROM users WHERE role = 'clinic_staff' AND is_active = 1) AS active_staff,
        (SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'booked') AS today_booked,
        (SELECT COUNT(*) FROM sms_logs WHERE status = 'pending') AS sms_pending,
        (SELECT COUNT(*) FROM sms_logs WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS sms_failed_week"
)->fetch_assoc();

$pageTitle = "Admin Dashboard";
$activeNav = "dashboard";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Admin Dashboard</h1>
        <p class="page-subtitle">Welcome, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator') ?>. Here is what needs attention.</p>
    </div>
</div>

<?php if ((int) $stats['pending_clinics'] > 0): ?>
    <div class="alert alert-warning">
        <?= (int) $stats['pending_clinics'] ?> clinic(s) are waiting for verification.
        <a href="/HealthConnect/admin/clinics.php?status=pending"><strong>Review now</strong></a>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="card stat-card">
        <h3>Verified Clinics</h3>
        <div class="stat-value"><?= (int) $stats['verified_clinics'] ?></div>
        <p class="page-subtitle"><?= (int) $stats['pending_clinics'] ?> pending &bull; <?= (int) $stats['suspended_clinics'] ?> suspended</p>
    </div>
    <div class="card stat-card">
        <h3>Active Users</h3>
        <div class="stat-value"><?= (int) $stats['active_patients'] + (int) $stats['active_staff'] ?></div>
        <p class="page-subtitle"><?= (int) $stats['active_patients'] ?> patients &bull; <?= (int) $stats['active_staff'] ?> staff</p>
    </div>
    <div class="card stat-card">
        <h3>Booked Today</h3>
        <div class="stat-value"><?= (int) $stats['today_booked'] ?></div>
        <p class="page-subtitle">Appointments still to attend</p>
    </div>
    <div class="card stat-card">
        <h3>SMS Failures (7 days)</h3>
        <div class="stat-value" style="color: var(--danger-text);"><?= (int) $stats['sms_failed_week'] ?></div>
        <p class="page-subtitle"><?= (int) $stats['sms_pending'] ?> waiting to send</p>
    </div>
</div>

<div class="card">
    <h2 style="margin-bottom: 1rem; font-size: 1.15rem;">Quick Links</h2>
    <p>
        <a class="btn btn-primary" href="/HealthConnect/admin/clinics.php">Verify Clinics</a>
        <a class="btn btn-secondary" href="/HealthConnect/admin/users.php">Manage Users</a>
        <a class="btn btn-secondary" href="/HealthConnect/admin/reports.php">System Reports</a>
        <a class="btn btn-secondary" href="/HealthConnect/admin/sms_log.php">SMS Log</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>

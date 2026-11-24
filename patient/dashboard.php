<?php
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in patients can access this page.
requireRole('patient');

$patientName = $_SESSION['full_name'] ?? 'Patient';

// Ensure a CSRF token exists for the logout form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = "Patient Dashboard";
$activeNav = "dashboard";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title">Welcome, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>!</h1>
        <p class="page-subtitle">Manage your clinic appointments from your patient dashboard.</p>
    </div>
    <form action="../auth/logout.php" method="POST" style="margin: 0;">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        >
        <button type="submit" class="btn btn-secondary">Logout</button>
    </form>
</div>

<h2 style="margin-bottom: 1rem; font-size: 1.25rem;">Patient Services</h2>

<div class="stats-grid">
    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <h3 style="margin-bottom: 0.5rem; color: var(--primary);">Find a Clinic</h3>
            <p style="color: var(--text-muted); margin-bottom: 1.25rem;">
                Browse verified healthcare facilities.
            </p>
        </div>
        <div>
            <a href="clinics.php" class="btn btn-primary">Browse Clinics</a>
        </div>
    </div>

    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <h3 style="margin-bottom: 0.5rem; color: var(--primary);">My Appointments</h3>
            <p style="color: var(--text-muted); margin-bottom: 1.25rem;">
                View your bookings, check appointment details, cancel or reschedule appointments.
            </p>
        </div>
        <div>
            <a href="appointments.php" class="btn btn-primary">View My Appointments</a>
        </div>
    </div>

    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <h3 style="margin-bottom: 0.5rem; color: var(--primary);">Book an Appointment</h3>
            <p style="color: var(--text-muted); margin-bottom: 1.25rem;">
                Choose a clinic and select an available appointment time.
            </p>
        </div>
        <div>
            <a href="clinics.php" class="btn btn-primary">Find an Appointment</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
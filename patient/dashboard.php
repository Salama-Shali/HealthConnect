<?php
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in patients can access this page.
requireRole('patient');

$patientName = $_SESSION['full_name'] ?? 'Patient';

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

require_once __DIR__ . '/../includes/patient_layout_header.php';
?>

<!-- PAGE HEADING -->
<div class="patient-page-heading">

    <p class="patient-eyebrow">PATIENT OVERVIEW</p>

    <h1>
        Welcome to HealthConnect,
        <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>!
    </h1>

    <p>
        Your healthcare journey starts here. Find a clinic, explore
        available appointment times and manage your appointments with ease.
    </p>

</div>

<!-- WELCOME PANEL -->
<section class="patient-welcome-panel">

    <div>

        <span class="patient-hero-kicker">
            YOUR HEALTHCARE, MADE SIMPLE
        </span>

        <h2 class="patient-hero-title">
            Find the care and appointment time that works for you.
        </h2>

        <p class="patient-hero-text">
            Explore healthcare facilities, choose an appointment
            time and keep your bookings organised in one place.
        </p>

        <a href="clinics.php" class="patient-hero-button">
            Find a Clinic &nbsp; →
        </a>

    </div>

    <div class="patient-hero-side">

        <span class="patient-hero-number">01</span>

        <strong>Find your clinic</strong>

        <p>
            Start by exploring the healthcare facilities
            available on HealthConnect.
        </p>

    </div>

</section>

<!-- PATIENT SERVICES -->
<div class="patient-section-heading">

    <h2>Your Services</h2>

    <p>Choose a service to continue.</p>

</div>

<div class="patient-action-grid">

    <section class="card patient-action-card">

        <div class="patient-card-icon">✚</div>

        <h3>Find a Clinic</h3>

        <p>
            Browse verified healthcare facilities and view
            their information.
        </p>

        <a href="clinics.php" class="patient-card-link">
            Browse Clinics →
        </a>

    </section>

    <section class="card patient-action-card">

        <div class="patient-card-icon">▣</div>

        <h3>Book an Appointment</h3>

        <p>
            Choose a clinic and select an available
            appointment time.
        </p>

        <a href="clinics.php" class="patient-card-link">
            Book Now →
        </a>

    </section>

    <section class="card patient-action-card">

        <div class="patient-card-icon">◷</div>

        <h3>My Appointments</h3>

        <p>
            View your appointment history, cancel or
            reschedule existing bookings.
        </p>

        <a href="appointments.php" class="patient-card-link">
            View Appointments →
        </a>

    </section>

</div>

<!-- HOW IT WORKS -->
<section class="card patient-steps-panel">

    <div class="patient-section-heading">
        <h2>How HealthConnect Works</h2>
    </div>

    <div class="patient-steps-grid">

        <div>
            <span class="patient-step-number">STEP 01</span>

            <h3>Browse Clinics</h3>

            <p>
                Explore the healthcare facilities available
                on HealthConnect.
            </p>
        </div>

        <div>
            <span class="patient-step-number">STEP 02</span>

            <h3>Choose a Time</h3>

            <p>
                View available appointment slots and select
                a suitable time.
            </p>
        </div>

        <div>
            <span class="patient-step-number">STEP 03</span>

            <h3>Manage Bookings</h3>

            <p>
                View your appointments and manage
                your existing bookings.
            </p>
        </div>

    </div>

</section>

<?php require_once __DIR__ . '/../includes/patient_layout_footer.php'; ?>
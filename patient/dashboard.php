<?php
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in patients can access this page.
requireRole('patient');

$patientName = $_SESSION['full_name'] ?? 'Patient';

// Ensure a CSRF token exists for the logout form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>

    <h2>
        Welcome, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>!
    </h2>

    <p>Manage your clinic appointments from your patient dashboard.</p>

    <h3>Patient Services</h3>

    <section>
        <h4>Find a Clinic</h4>
        <p>Browse verified healthcare facilities.</p>
        <a href="clinics.php">Browse Clinics</a>
    </section>

    <hr>

    <section>
        <h4>My Appointments</h4>
        <p>View your bookings, check appointment details, cancel or reschedule appointments.</p>
        <a href="appointments.php">View My Appointments</a>
    </section>

    <hr>

    <section>
        <h4>Book an Appointment</h4>
        <p>Choose a clinic and select an available appointment time.</p>
        <a href="clinics.php">Find an Appointment</a>
    </section>

    <hr>

    <form action="../auth/logout.php" method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <button type="submit">Logout</button>

    </form>

</body>
</html>

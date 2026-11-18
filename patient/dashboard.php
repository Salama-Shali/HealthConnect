<?php
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in patients can access this page.
requireRole('patient');

$patientName = $_SESSION['full_name'] ?? 'Patient';
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

    <h2>Welcome, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>!</h2>

    <p>You are successfully logged in to your patient account.</p>

    <h3>Patient Dashboard</h3>

    <p>From here, you will be able to:</p>

    <ul>
        <li>Browse verified clinics.</li>
        <li>View available appointment slots.</li>
        <li>Book appointments.</li>
        <li>Reschedule or cancel appointments.</li>
        <li>View your appointment history.</li>
    </ul>

    <p>These features will be implemented in the next development stages.</p>

</body>
</html>

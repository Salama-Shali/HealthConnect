<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can view their appointment history.
requireRole('patient');

$patientId = currentUserId();
$error = "";
$appointments = null;

// Fetch appointments belonging to the logged-in patient.
$stmt = $conn->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.visit_category,
        a.status,
        a.booked_at,
        c.name AS clinic_name,
        c.county,
        c.address,
        s.start_time,
        s.end_time
    FROM appointments a
    INNER JOIN clinics c
        ON a.clinic_id = c.clinic_id
    LEFT JOIN slots s
        ON a.slot_id = s.slot_id
        AND a.clinic_id = s.clinic_id
    WHERE a.patient_id = ?
    ORDER BY
        a.appointment_date DESC,
        s.start_time DESC,
        a.booked_at DESC"
);

$stmt->bind_param("i", $patientId);
$stmt->execute();

$appointments = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>

    <h2>My Appointments</h2>

    <p>View your appointment history and current bookings.</p>

    <?php if ($appointments->num_rows > 0): ?>

        <?php while ($appointment = $appointments->fetch_assoc()): ?>

            <section>

                <h3>
                    Appointment #<?= (int) $appointment['appointment_id'] ?>
                </h3>

                <p>
                    <strong>Clinic:</strong>
                    <?= htmlspecialchars($appointment['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Location:</strong>
                    <?= htmlspecialchars($appointment['county'], ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars($appointment['address'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Date:</strong>
                    <?= date('l, d M Y', strtotime($appointment['appointment_date'])) ?>
                </p>

                <p>
                    <strong>Time:</strong>

                    <?php if ($appointment['start_time'] && $appointment['end_time']): ?>

                        <?= date('g:i A', strtotime($appointment['start_time'])) ?>
                        -
                        <?= date('g:i A', strtotime($appointment['end_time'])) ?>

                    <?php else: ?>

                        Not assigned

                    <?php endif; ?>
                </p>

                <p>
                    <strong>Visit Category:</strong>
                    <?= htmlspecialchars(
                        ucfirst(str_replace('_', ' ', $appointment['visit_category'])),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

                <p>
                    <strong>Status:</strong>
                    <?= htmlspecialchars(ucfirst($appointment['status']), ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Booked On:</strong>
                    <?= date('d M Y, g:i A', strtotime($appointment['booked_at'])) ?>
                </p>

            </section>

            <hr>

        <?php endwhile; ?>

    <?php else: ?>

        <p>You have not booked any appointments yet.</p>

        <a href="clinics.php">Browse Verified Clinics</a>

    <?php endif; ?>

    <?php $stmt->close(); ?>

    <p><a href="clinics.php">Browse Clinics</a></p>
    <p><a href="dashboard.php">Back to Dashboard</a></p>

</body>
</html>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can view appointment slots.
requireRole('patient');

// Get the selected clinic ID from the URL.
$clinicId = filter_input(INPUT_GET, 'clinic_id', FILTER_VALIDATE_INT);

$clinic = null;
$slots = null;
$error = null;

if (!$clinicId || $clinicId < 1) {
    $error = "Invalid clinic selected.";
} else {

    // Fetch the clinic, but only if it is verified.
    $clinicStmt = $conn->prepare(
        "SELECT clinic_id, name, county, address
         FROM clinics
         WHERE clinic_id = ?
         AND verification_status = 'verified'
         LIMIT 1"
    );

    $clinicStmt->bind_param("i", $clinicId);
    $clinicStmt->execute();

    $clinic = $clinicStmt->get_result()->fetch_assoc();
    $clinicStmt->close();

    if (!$clinic) {
        $error = "The selected clinic could not be found.";
    } else {

        // Fetch future available slots that have not been booked.
        $slotStmt = $conn->prepare(
            "SELECT s.slot_id, s.slot_date, s.start_time, s.end_time
             FROM slots s
             WHERE s.clinic_id = ?
             AND s.status = 'available'
             AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
             AND NOT EXISTS (
                 SELECT 1
                 FROM appointments a
                 WHERE a.slot_id = s.slot_id
                 AND a.status = 'booked'
             )
             ORDER BY s.slot_date ASC, s.start_time ASC"
        );

        $slotStmt->bind_param("i", $clinicId);
        $slotStmt->execute();

        $slots = $slotStmt->get_result();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Slots | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>

    <?php if ($error): ?>

        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>

    <?php else: ?>

        <h2>Available Appointment Slots</h2>

        <h3>
            <?= htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8') ?>
        </h3>

        <p>
            <?= htmlspecialchars($clinic['county'], ENT_QUOTES, 'UTF-8') ?> |
            <?= htmlspecialchars($clinic['address'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        

        <?php if ($slots->num_rows > 0): ?>

            <?php while ($slot = $slots->fetch_assoc()): ?>

                <section>
                    <h4>
                        <?= date('l, d M Y', strtotime($slot['slot_date'])) ?>
                    </h4>

                    <p>
                        <?= date('g:i A', strtotime($slot['start_time'])) ?>
                        -
                        <?= date('g:i A', strtotime($slot['end_time'])) ?>
                    </p>

                    <p>Status: Available</p>

<a href="book.php?slot_id=<?= (int) $slot['slot_id'] ?>">
    Book This Slot
</a>
                </section>

                <hr>

            <?php endwhile; ?>

        <?php else: ?>

            <p>No future appointment slots are currently available at this clinic.</p>

        <?php endif; ?>

        <?php $slotStmt->close(); ?>

    <?php endif; ?>

    <p><a href="clinics.php">Back to Clinics</a></p>
    <p><a href="dashboard.php">Back to Dashboard</a></p>

</body>
</html>

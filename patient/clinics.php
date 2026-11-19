<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can browse clinics.
requireRole('patient');

// Fetch verified clinics from the database.
$sql = "SELECT clinic_id, name, county, address,
               contact_phone, operating_hours
        FROM clinics
        WHERE verification_status = 'verified'
        ORDER BY name ASC";

$result = $conn->query($sql);

if (!$result) {
    error_log("Clinic query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Clinics | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>

    <h2>Verified Clinics</h2>

    <p>Browse clinics that have been verified on HealthConnect.</p>

    <?php if (!$result): ?>

        <p>We could not load the clinics at the moment. Please try again.</p>

    <?php elseif ($result->num_rows > 0): ?>

        <?php while ($clinic = $result->fetch_assoc()): ?>

            <section>
                <h3>
                    <?= htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8') ?>
                </h3>

                <p>
                    <strong>County:</strong>
                    <?= htmlspecialchars($clinic['county'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Address:</strong>
                    <?= htmlspecialchars($clinic['address'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <strong>Operating Hours:</strong>
                    <?= htmlspecialchars($clinic['operating_hours'] ?? 'Not provided', ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php if (!empty($clinic['contact_phone'])): ?>
                    <p>
                        <strong>Contact:</strong>
                        <?= htmlspecialchars($clinic['contact_phone'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <a href="slots.php?clinic_id=<?= (int) $clinic['clinic_id'] ?>">
                    View Available Slots
                </a>
            </section>

            <hr>

        <?php endwhile; ?>

    <?php else: ?>

        <p>No verified clinics are currently available.</p>

    <?php endif; ?>

    <p><a href="dashboard.php">Back to Dashboard</a></p>

</body>
</html>

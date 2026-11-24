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

$pageTitle = "Available Slots";
$activeNav = "clinics";
require_once __DIR__ . '/../includes/patient_layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title">Available Appointment Slots</h1>
        <?php if ($clinic): ?>
            <p class="page-subtitle">
                <strong><?= htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                <?= htmlspecialchars($clinic['county'], ENT_QUOTES, 'UTF-8') ?>,
                <?= htmlspecialchars($clinic['address'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="clinics.php" class="btn btn-secondary">Back to Clinics</a>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>
    <?php if ($slots->num_rows > 0): ?>
        <div class="stats-grid">
            <?php while ($slot = $slots->fetch_assoc()): ?>
                <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <h3 style="margin: 0; font-size: 1.05rem; color: var(--primary);">
                                <?= date('l, d M Y', strtotime($slot['slot_date'])) ?>
                            </h3>
                            <span class="badge badge-success">Available</span>
                        </div>
                        <p style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">
                            <?= date('g:i A', strtotime($slot['start_time'])) ?> &ndash;
                            <?= date('g:i A', strtotime($slot['end_time'])) ?>
                        </p>
                    </div>
                    <div>
                        <a href="book.php?slot_id=<?= (int) $slot['slot_id'] ?>" class="btn btn-primary">
                            Book This Slot
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card" style="text-align: center; padding: 2rem;">
            <p style="color: var(--text-muted); margin-bottom: 1rem;">
                No future appointment slots are currently available at this clinic.
            </p>
            <a href="clinics.php" class="btn btn-primary">Browse Other Clinics</a>
        </div>
    <?php endif; ?>

    <?php $slotStmt->close(); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/patient_layout_footer.php'; ?>
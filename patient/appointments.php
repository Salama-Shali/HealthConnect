<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can view their appointment history.
requireRole('patient');

$patientId = currentUserId();
$error = "";
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
        c.verification_status,
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

$pageTitle = "My Appointments";
$activeNav = "appointments";
require_once __DIR__ . '/../includes/patient_layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title">My Appointments</h1>
        <p class="page-subtitle">View, reschedule, or cancel your scheduled clinic visits.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="clinics.php" class="btn btn-primary">+ Book New Appointment</a>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<?php if (($_GET['cancelled'] ?? '') === '1'): ?>
    <div class="alert alert-success">
        Appointment cancelled successfully. The slot is available again.
    </div>
<?php elseif (($_GET['cancel_error'] ?? '') === '1'): ?>
    <div class="alert alert-error">
        We could not cancel this appointment. It may already have been cancelled or is no longer active.
    </div>
<?php elseif (($_GET['rescheduled'] ?? '') === '1'): ?>
    <div class="alert alert-success">
        Appointment rescheduled successfully. Your new appointment details are shown below.
    </div>
<?php endif; ?>

<?php if ($appointments->num_rows > 0): ?>
    <?php while ($appointment = $appointments->fetch_assoc()): ?>
        <?php
        $status = $appointment['status'];
        $badgeClass = match ($status) {
            'booked'    => 'badge-info',
            'attended'  => 'badge-success',
            'cancelled' => 'badge-danger',
            'missed'    => 'badge-warning',
            default     => 'badge-secondary',
        };
        ?>
        <div class="card" style="margin-bottom: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                <div>
                    <h3 style="margin: 0; color: var(--primary);">
                        <?= htmlspecialchars($appointment['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <small style="color: var(--text-muted);">
                        Appointment #<?= (int) $appointment['appointment_id'] ?> &bull;
                        <?= htmlspecialchars($appointment['county'], ENT_QUOTES, 'UTF-8') ?>,
                        <?= htmlspecialchars($appointment['address'], ENT_QUOTES, 'UTF-8') ?>
                    </small>
                </div>
                <span class="badge <?= $badgeClass ?>">
                    <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; font-size: 0.95rem;">
                <div>
                    <strong>Date:</strong><br>
                    <?= date('l, d M Y', strtotime($appointment['appointment_date'])) ?>
                </div>
                <div>
                    <strong>Time:</strong><br>
                    <?php if ($appointment['start_time'] && $appointment['end_time']): ?>
                        <?= date('g:i A', strtotime($appointment['start_time'])) ?> &ndash;
                        <?= date('g:i A', strtotime($appointment['end_time'])) ?>
                    <?php else: ?>
                        Not assigned
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Visit Category:</strong><br>
                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $appointment['visit_category'])), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                    <strong>Booked On:</strong><br>
                    <?= date('d M Y, g:i A', strtotime($appointment['booked_at'])) ?>
                </div>
            </div>

            <?php if ($appointment['status'] === 'booked'): ?>
                <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 0.85rem;">
                    <?php if (
                        $appointment['visit_category'] === 'ordinary' &&
                        $appointment['verification_status'] === 'verified'
                    ): ?>
                        <a href="reschedule.php?appointment_id=<?= (int) $appointment['appointment_id'] ?>" class="btn btn-secondary">
                            Reschedule Appointment
                        </a>
                    <?php endif; ?>

                    <form
                        action="cancel.php"
                        method="POST"
                        style="display: inline; margin: 0;"
                        onsubmit="return confirm('Are you sure you want to cancel this appointment?');"
                    >
                        <input
                            type="hidden"
                            name="appointment_id"
                            value="<?= (int) $appointment['appointment_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button type="submit" class="btn btn-danger">Cancel Appointment</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p style="color: var(--text-muted); margin-bottom: 1rem;">You have not booked any appointments yet.</p>
        <a href="clinics.php" class="btn btn-primary">Browse Verified Clinics</a>
    </div>
<?php endif; ?>

<?php
$stmt->close();
require_once __DIR__ . '/../includes/patient_layout_footer.php';
?>
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can reschedule appointments.
requireRole('patient');

$patientId = currentUserId();
$error = "";
$appointment = null;
$availableSlots = null;

// Generate a CSRF token if one does not exist.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get the appointment ID from the submitted form or URL.
$appointmentId = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['appointment_id'] ?? '')
        : ($_GET['appointment_id'] ?? ''),
    FILTER_VALIDATE_INT
);

// Process the rescheduling form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';
    $newSlotId = filter_var(
        $_POST['new_slot_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $error = "Invalid form submission. Please try again.";

    } elseif (!$appointmentId || $appointmentId < 1) {
        $error = "Invalid appointment selected.";

    } elseif (!$newSlotId || $newSlotId < 1) {
        $error = "Please select a new appointment slot.";

    } else {

        $transactionStarted = false;

        try {
            // Start a transaction to protect both appointments and slots.
            $conn->begin_transaction();
            $transactionStarted = true;

            // Lock the patient's existing appointment.
            $appointmentStmt = $conn->prepare(
                "SELECT a.appointment_id, a.clinic_id, a.slot_id
                 FROM appointments a
                 INNER JOIN slots old_slot
                    ON a.slot_id = old_slot.slot_id
                    AND a.clinic_id = old_slot.clinic_id
                 WHERE a.appointment_id = ?
                 AND a.patient_id = ?
                 AND a.status = 'booked'
                 AND a.visit_category = 'ordinary'
                 AND TIMESTAMP(old_slot.slot_date, old_slot.start_time) > NOW()
                 FOR UPDATE"
            );

            $appointmentStmt->bind_param(
                "ii",
                $appointmentId,
                $patientId
            );

            $appointmentStmt->execute();

            $currentAppointment =
                $appointmentStmt->get_result()->fetch_assoc();

            $appointmentStmt->close();

            if (!$currentAppointment) {

                $conn->rollback();
                $transactionStarted = false;

                $error = "This appointment cannot be rescheduled. It may no longer be active.";

            } elseif ((int) $currentAppointment['slot_id'] === $newSlotId) {

                $conn->rollback();
                $transactionStarted = false;

                $error = "Please choose a different appointment slot.";

            } else {

                // Lock the new slot and confirm it belongs to
                // the same verified clinic.
                $slotStmt = $conn->prepare(
                    "SELECT s.slot_id, s.clinic_id, s.slot_date
                     FROM slots s
                     INNER JOIN clinics c
                        ON s.clinic_id = c.clinic_id
                     WHERE s.slot_id = ?
                     AND s.clinic_id = ?
                     AND s.status = 'available'
                     AND c.verification_status = 'verified'
                     AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
                     FOR UPDATE"
                );

                $slotStmt->bind_param(
                    "ii",
                    $newSlotId,
                    $currentAppointment['clinic_id']
                );

                $slotStmt->execute();

                $newSlot = $slotStmt->get_result()->fetch_assoc();

                $slotStmt->close();

                if (!$newSlot) {

                    $conn->rollback();
                    $transactionStarted = false;

                    $error = "The selected slot is unavailable. Please choose another.";

                } else {

                    // Check whether another appointment occupies the slot.
                    $checkStmt = $conn->prepare(
                        "SELECT appointment_id
                         FROM appointments
                         WHERE slot_id = ?
                         AND status = 'booked'
                         AND appointment_id <> ?
                         LIMIT 1"
                    );

                    $checkStmt->bind_param(
                        "ii",
                        $newSlotId,
                        $appointmentId
                    );

                    $checkStmt->execute();

                    $existingBooking =
                        $checkStmt->get_result()->fetch_assoc();

                    $checkStmt->close();

                    if ($existingBooking) {

                        $conn->rollback();
                        $transactionStarted = false;

                        $error = "Sorry, that slot has already been booked.";

                    } else {

                        // Update the existing appointment.
                        // Its original booking remains if this transaction fails.
                        $updateStmt = $conn->prepare(
                            "UPDATE appointments
                             SET slot_id = ?,
                                 appointment_date = ?
                             WHERE appointment_id = ?
                             AND patient_id = ?
                             AND status = 'booked'"
                        );

                        $updateStmt->bind_param(
                            "isii",
                            $newSlotId,
                            $newSlot['slot_date'],
                            $appointmentId,
                            $patientId
                        );

                        $updateStmt->execute();

                        $updated = $updateStmt->affected_rows === 1;
                        $updateStmt->close();

                        if (!$updated) {

                            $conn->rollback();
                            $transactionStarted = false;

                            $error = "The appointment could not be updated. Please try again.";

                        } else {

                            $conn->commit();
                            $transactionStarted = false;

                            // Return to appointment history after success.
                            header("Location: appointments.php?rescheduled=1");
                            exit;
                        }
                    }
                }
            }

        } catch (mysqli_sql_exception $e) {

            if ($transactionStarted) {
                $conn->rollback();
            }

            if ($e->getCode() === 1062) {
                $error = "Sorry, that slot has just been booked. Please choose another.";
            } else {
                error_log("Appointment rescheduling error: " . $e->getMessage());
                $error = "Something went wrong. Please try again.";
            }
        }
    }
}

// Fetch the patient's existing appointment for display.
if ($appointmentId && $appointmentId > 0) {

    $stmt = $conn->prepare(
        "SELECT
            a.appointment_id,
            a.clinic_id,
            a.slot_id,
            a.appointment_date,
            c.name AS clinic_name,
            old_slot.start_time,
            old_slot.end_time
         FROM appointments a
         INNER JOIN clinics c
            ON a.clinic_id = c.clinic_id
         INNER JOIN slots old_slot
            ON a.slot_id = old_slot.slot_id
            AND a.clinic_id = old_slot.clinic_id
         WHERE a.appointment_id = ?
         AND a.patient_id = ?
         AND a.status = 'booked'
         AND a.visit_category = 'ordinary'
         AND TIMESTAMP(old_slot.slot_date, old_slot.start_time) > NOW()
         LIMIT 1"
    );

    $stmt->bind_param("ii", $appointmentId, $patientId);
    $stmt->execute();

    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($appointment) {

        // Find other future available slots at the same clinic.
        $slotStmt = $conn->prepare(
            "SELECT
                s.slot_id,
                s.slot_date,
                s.start_time,
                s.end_time
             FROM slots s
             WHERE s.clinic_id = ?
             AND s.status = 'available'
             AND s.slot_id <> ?
             AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
             AND NOT EXISTS (
                 SELECT 1
                 FROM appointments a
                 WHERE a.slot_id = s.slot_id
                 AND a.status = 'booked'
             )
             ORDER BY s.slot_date ASC, s.start_time ASC"
        );

        $slotStmt->bind_param(
            "ii",
            $appointment['clinic_id'],
            $appointment['slot_id']
        );

        $slotStmt->execute();
        $availableSlots = $slotStmt->get_result();

    } elseif ($error === "") {

        $error = "This appointment cannot be rescheduled. Check that it is an active future appointment.";
    }

} elseif ($error === "") {

    $error = "Invalid appointment selected.";
}

$pageTitle = "Reschedule Appointment";
$activeNav = "appointments";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title">Reschedule Appointment</h1>
        <p class="page-subtitle">Select a new available time slot for your clinic visit.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="appointments.php" class="btn btn-secondary">Back to My Appointments</a>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<div class="card" style="max-width: 580px; margin: 0 auto;">
    <?php if ($error !== ""): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($appointment): ?>
        <h3 style="margin-bottom: 0.85rem; color: var(--primary);">Current Appointment</h3>

        <div style="display: grid; gap: 0.55rem; margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--border); font-size: 0.95rem;">
            <div>
                <strong>Clinic:</strong>
                <?= htmlspecialchars($appointment['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div>
                <strong>Current Date:</strong>
                <?= date('l, d M Y', strtotime($appointment['appointment_date'])) ?>
            </div>
            <div>
                <strong>Current Time:</strong>
                <?= date('g:i A', strtotime($appointment['start_time'])) ?> &ndash;
                <?= date('g:i A', strtotime($appointment['end_time'])) ?>
            </div>
        </div>

        <h3 style="margin-bottom: 0.85rem; color: var(--primary);">Choose a New Appointment Time</h3>

        <?php if ($availableSlots && $availableSlots->num_rows > 0): ?>
            <form method="POST" action="reschedule.php">
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

                <div class="form-group">
                    <label for="new_slot_id">Available Slots</label>
                    <select id="new_slot_id" name="new_slot_id" class="form-control" required>
                        <option value="">Select a new slot</option>
                        <?php while ($newSlot = $availableSlots->fetch_assoc()): ?>
                            <option value="<?= (int) $newSlot['slot_id'] ?>">
                                <?= date('d M Y', strtotime($newSlot['slot_date'])) ?> &mdash;
                                <?= date('g:i A', strtotime($newSlot['start_time'])) ?> to
                                <?= date('g:i A', strtotime($newSlot['end_time'])) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.25rem;">
                    <button type="submit" class="btn btn-primary">Confirm Rescheduling</button>
                    <a href="appointments.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <p style="color: var(--text-muted);">
                No other future appointment slots are currently available at this clinic.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
if ($availableSlots) {
    $slotStmt->close();
}
require_once __DIR__ . '/../includes/layout_footer.php';
?>
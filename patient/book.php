<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can book appointments.
requireRole('patient');

$patientId = currentUserId();
$error = "";
$slot = null;
$confirmation = null;

// Generate a CSRF token if one does not exist.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Display the successful booking after redirecting.
if (
    ($_GET['success'] ?? '') === '1' &&
    isset($_SESSION['booking_confirmation'])
) {
    $confirmation = $_SESSION['booking_confirmation'];
    unset($_SESSION['booking_confirmation']);
}

// Process the booking confirmation form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $slotId = filter_var(
        $_POST['slot_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!$slotId || $slotId < 1) {
        $error = "Invalid appointment slot selected.";
    } else {

        try {
            // Start a transaction to protect the selected slot.
            $conn->begin_transaction();

            // Lock the slot while checking its availability.
            $stmt = $conn->prepare(
                "SELECT s.slot_id, s.clinic_id, s.slot_date,
                        s.start_time, s.end_time,
                        c.name AS clinic_name
                 FROM slots s
                 INNER JOIN clinics c
                    ON s.clinic_id = c.clinic_id
                 WHERE s.slot_id = ?
                 AND s.status = 'available'
                 AND c.verification_status = 'verified'
                 AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
                 FOR UPDATE"
            );

            $stmt->bind_param("i", $slotId);
            $stmt->execute();

            $selectedSlot = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$selectedSlot) {

                $conn->rollback();
                $error = "This slot is no longer available.";

            } else {

                // Check whether another patient has booked this slot.
                $checkStmt = $conn->prepare(
                    "SELECT appointment_id
                     FROM appointments
                     WHERE slot_id = ?
                     AND status = 'booked'
                     LIMIT 1"
                );

                $checkStmt->bind_param("i", $slotId);
                $checkStmt->execute();

                $existingBooking = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($existingBooking) {

                    $conn->rollback();
                    $error = "Sorry, this appointment slot has already been booked.";

                } else {

                    // Save the appointment.
                    $insertStmt = $conn->prepare(
                        "INSERT INTO appointments
                         (patient_id, clinic_id, slot_id,
                          appointment_date, visit_category, booked_by)
                         VALUES (?, ?, ?, ?, 'ordinary', ?)"
                    );

                    $insertStmt->bind_param(
                        "iiisi",
                        $patientId,
                        $selectedSlot['clinic_id'],
                        $slotId,
                        $selectedSlot['slot_date'],
                        $patientId
                    );

                    $insertStmt->execute();

                    $appointmentId = $conn->insert_id;

                    $insertStmt->close();

                    // Complete the transaction.
                    $conn->commit();

                    // Save confirmation details for the next page request.
                    $_SESSION['booking_confirmation'] = [
                        'appointment_id' => $appointmentId,
                        'clinic_name' => $selectedSlot['clinic_name'],
                        'slot_date' => $selectedSlot['slot_date'],
                        'start_time' => $selectedSlot['start_time'],
                        'end_time' => $selectedSlot['end_time']
                    ];

                    // Redirect to avoid submitting the form twice.
                    header("Location: book.php?success=1");
                    exit;
                }
            }

        } catch (mysqli_sql_exception $e) {

            $conn->rollback();

            if ($e->getCode() === 1062) {
                $error = "Sorry, this slot has just been booked by another patient.";
            } else {
                error_log("Appointment booking error: " . $e->getMessage());
                $error = "Something went wrong while booking. Please try again.";
            }
        }
    }
}

// Fetch the selected slot for the confirmation page.
if (!$confirmation && $slotId = filter_var(
    $_GET['slot_id'] ?? ($slotId ?? ''),
    FILTER_VALIDATE_INT
)) {

    $stmt = $conn->prepare(
        "SELECT s.slot_id, s.slot_date, s.start_time,
                s.end_time, c.name AS clinic_name,
                c.county, c.address
         FROM slots s
         INNER JOIN clinics c
            ON s.clinic_id = c.clinic_id
         WHERE s.slot_id = ?
         AND s.status = 'available'
         AND c.verification_status = 'verified'
         AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
         AND NOT EXISTS (
             SELECT 1
             FROM appointments a
             WHERE a.slot_id = s.slot_id
             AND a.status = 'booked'
         )
         LIMIT 1"
    );

    $stmt->bind_param("i", $slotId);
    $stmt->execute();

    $slot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pageTitle = "Book Appointment";
$activeNav = "clinics";
require_once __DIR__ . '/../includes/patient_layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title"><?= $confirmation ? 'Appointment Booked!' : 'Confirm Your Appointment' ?></h1>
        <p class="page-subtitle">
            <?= $confirmation ? 'Your clinic visit has been scheduled.' : 'Review the slot details below before confirming your booking.' ?>
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="clinics.php" class="btn btn-secondary">Browse Clinics</a>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<?php if ($confirmation): ?>
    <div class="card" style="max-width: 580px; margin: 0 auto;">
        <div class="alert alert-success">
            Appointment booked successfully! Your appointment has been recorded.
        </div>

        <div style="display: grid; gap: 0.65rem; margin: 1.25rem 0; font-size: 0.95rem;">
            <div>
                <strong>Appointment Number:</strong>
                #<?= (int) $confirmation['appointment_id'] ?>
            </div>
            <div>
                <strong>Clinic:</strong>
                <?= htmlspecialchars($confirmation['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div>
                <strong>Date:</strong>
                <?= date('l, d M Y', strtotime($confirmation['slot_date'])) ?>
            </div>
            <div>
                <strong>Time:</strong>
                <?= date('g:i A', strtotime($confirmation['start_time'])) ?> &ndash;
                <?= date('g:i A', strtotime($confirmation['end_time'])) ?>
            </div>
            <div>
                <strong>Status:</strong>
                <span class="badge badge-info">Booked</span>
            </div>
        </div>

        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem;">
            SMS confirmation will be handled through the SMS integration.
        </p>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="appointments.php" class="btn btn-primary">View My Appointments</a>
            <a href="clinics.php" class="btn btn-secondary">Browse More Clinics</a>
        </div>
    </div>
<?php else: ?>
    <div class="card" style="max-width: 580px; margin: 0 auto;">
        <?php if ($error !== ""): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($slot): ?>
            <h3 style="margin-bottom: 1rem; color: var(--primary);">Appointment Details</h3>

            <div style="display: grid; gap: 0.65rem; margin-bottom: 1.5rem; font-size: 0.95rem;">
                <div>
                    <strong>Clinic:</strong>
                    <?= htmlspecialchars($slot['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                    <strong>Location:</strong>
                    <?= htmlspecialchars($slot['county'], ENT_QUOTES, 'UTF-8') ?>,
                    <?= htmlspecialchars($slot['address'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                    <strong>Date:</strong>
                    <?= date('l, d M Y', strtotime($slot['slot_date'])) ?>
                </div>
                <div>
                    <strong>Time:</strong>
                    <?= date('g:i A', strtotime($slot['start_time'])) ?> &ndash;
                    <?= date('g:i A', strtotime($slot['end_time'])) ?>
                </div>
            </div>

            <form method="POST" action="book.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <input
                    type="hidden"
                    name="slot_id"
                    value="<?= (int) $slot['slot_id'] ?>"
                >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit" class="btn btn-primary">Confirm Booking</button>
                <a href="clinics.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php else: ?>
            <?php if ($error === ""): ?>
                <div class="alert alert-error">
                    This appointment slot is unavailable or no longer exists.
                </div>
            <?php endif; ?>
            <a href="clinics.php" class="btn btn-primary">Return to Clinics</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/patient_layout_footer.php'; ?>
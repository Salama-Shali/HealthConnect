
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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>

    <?php if ($confirmation): ?>

        <h2>Appointment Booked Successfully!</h2>

        <p>Your appointment has been recorded.</p>

        <p>
            <strong>Appointment Number:</strong>
            <?= (int) $confirmation['appointment_id'] ?>
        </p>

        <p>
            <strong>Clinic:</strong>
            <?= htmlspecialchars($confirmation['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <p>
            <strong>Date:</strong>
            <?= date('l, d M Y', strtotime($confirmation['slot_date'])) ?>
        </p>

        <p>
            <strong>Time:</strong>
            <?= date('g:i A', strtotime($confirmation['start_time'])) ?>
            -
            <?= date('g:i A', strtotime($confirmation['end_time'])) ?>
        </p>

        <p><strong>Status:</strong> Booked</p>

        <p>
            SMS confirmation will be handled through the SMS integration.
        </p>

        <a href="clinics.php">Browse More Clinics</a>

    <?php else: ?>

        <h2>Confirm Your Appointment</h2>

        <?php if ($error !== ""): ?>
            <p style="color: red;">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if ($slot): ?>

            <h3>Appointment Details</h3>

            <p>
                <strong>Clinic:</strong>
                <?= htmlspecialchars($slot['clinic_name'], ENT_QUOTES, 'UTF-8') ?>
            </p>

            <p>
                <strong>Location:</strong>
                <?= htmlspecialchars($slot['county'], ENT_QUOTES, 'UTF-8') ?>,
                <?= htmlspecialchars($slot['address'], ENT_QUOTES, 'UTF-8') ?>
            </p>

            <p>
                <strong>Date:</strong>
                <?= date('l, d M Y', strtotime($slot['slot_date'])) ?>
            </p>

            <p>
                <strong>Time:</strong>
                <?= date('g:i A', strtotime($slot['start_time'])) ?>
                -
                <?= date('g:i A', strtotime($slot['end_time'])) ?>
            </p>

            <form method="POST" action="book.php">

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

                <button type="submit">Confirm Booking</button>

            </form>

        <?php else: ?>

            <?php if ($error === ""): ?>
                <p>This appointment slot is unavailable or no longer exists.</p>
            <?php endif; ?>

            <p><a href="clinics.php">Return to Clinics</a></p>

        <?php endif; ?>

    <?php endif; ?>

    <p><a href="dashboard.php">Back to Dashboard</a></p>

</body>
</html>

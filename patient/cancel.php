<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can cancel appointments.
requireRole('patient');

// Cancellation must be submitted through POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify the CSRF token.
$csrfToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    http_response_code(403);
    exit("Invalid form submission. Please try again.");
}

// Validate the appointment ID.
$appointmentId = filter_var(
    $_POST['appointment_id'] ?? '',
    FILTER_VALIDATE_INT
);

if (!$appointmentId || $appointmentId < 1) {
    header("Location: appointments.php?cancel_error=1");
    exit;
}

$patientId = currentUserId();

try {
    // Cancel only a booked appointment belonging to this patient.
    $stmt = $conn->prepare(
        "UPDATE appointments
         SET status = 'cancelled'
         WHERE appointment_id = ?
         AND patient_id = ?
         AND status = 'booked'"
    );

    $stmt->bind_param("ii", $appointmentId, $patientId);
    $stmt->execute();

    $cancelled = $stmt->affected_rows === 1;
    $stmt->close();

    if ($cancelled) {
        // The database releases the slot when status becomes cancelled.
        header("Location: appointments.php?cancelled=1");
    } else {
        header("Location: appointments.php?cancel_error=1");
    }

    exit;

} catch (mysqli_sql_exception $e) {

    error_log("Appointment cancellation error: " . $e->getMessage());

    header("Location: appointments.php?cancel_error=1");
    exit;
}
?>

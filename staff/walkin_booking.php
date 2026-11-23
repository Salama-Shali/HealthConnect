<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('clinic_staff');

$userId = currentUserId();

// 1. Resolve the staff member's assigned clinic (BR-07)
$stmt = $conn->prepare("
    SELECT cs.clinic_id, c.name AS clinic_name, c.verification_status
    FROM clinic_staff cs
    INNER JOIN clinics c ON cs.clinic_id = c.clinic_id
    WHERE cs.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$staffClinic = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staffClinic) {
    http_response_code(403);
    exit("Your staff account is not linked to any clinic. Please contact an administrator.");
}

$clinicId           = (int) $staffClinic['clinic_id'];
$clinicName         = $staffClinic['clinic_name'];
$verificationStatus = $staffClinic['verification_status'];

// 2. Handle Walk-In Registration POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_walkin') {
    $fullName    = trim($_POST['full_name'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $slotIdRaw   = trim($_POST['slot_id'] ?? '');
    $slotId      = ($slotIdRaw !== '') ? (int) $slotIdRaw : null;
    $today       = date('Y-m-d');

    if ($fullName === '' || $phoneNumber === '') {
        $_SESSION['flash_error'] = "Patient full name and Kenyan phone number are required.";
        header("Location: /HealthConnect/staff/walkin_booking.php");
        exit;
    }

    // Basic Kenyan mobile format normalization/check (SRS 3.4.3)
    if (!preg_match('/^(?:\+254|254|0)[17]\d{8}$/', $phoneNumber)) {
        $_SESSION['flash_error'] = "Please enter a valid Kenyan mobile number (e.g., 0712345678 or +254712345678).";
        header("Location: /HealthConnect/staff/walkin_booking.php");
        exit;
    }

    $conn->begin_transaction();

    try {
        // A. Look up existing user by phone number
        $findUser = $conn->prepare("SELECT user_id, role FROM users WHERE phone_number = ? LIMIT 1");
        $findUser->bind_param("s", $phoneNumber);
        $findUser->execute();
        $existingUser = $findUser->get_result()->fetch_assoc();
        $findUser->close();

        if ($existingUser) {
            $patientId = (int) $existingUser['user_id'];
        } else {
            // Create a new patient account for walk-in visitor
            if ($email === '') {
                $sanitizedPhone = preg_replace('/\D+/', '', $phoneNumber);
                $email = "walkin_{$sanitizedPhone}@healthconnect.local";
            }

            // Ensure email is not already taken
            $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();
            if ($checkEmail->get_result()->num_rows > 0) {
                throw new Exception("That email address is already registered to another account.");
            }
            $checkEmail->close();

            $tempPasswordHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            $createPatient = $conn->prepare("
                INSERT INTO users (full_name, phone_number, email, password_hash, role)
                VALUES (?, ?, ?, ?, 'patient')
            ");
            $createPatient->bind_param("ssss", $fullName, $phoneNumber, $email, $tempPasswordHash);
            if (!$createPatient->execute()) {
                throw new Exception("Could not register patient record.");
            }
            $patientId = (int) $conn->insert_id;
            $createPatient->close();
        }

        // B. If an optional slot was selected, verify it belongs to this clinic and is free (FR-05, BR-02)
        if ($slotId !== null) {
            $slotCheck = $conn->prepare("
                SELECT s.slot_id
                FROM slots s
                LEFT JOIN appointments a ON s.slot_id = a.slot_id AND a.status = 'booked'
                WHERE s.slot_id = ?
                  AND s.clinic_id = ?
                  AND s.slot_date = ?
                  AND s.status = 'available'
                  AND a.appointment_id IS NULL
                FOR UPDATE
            ");
            $slotCheck->bind_param("iis", $slotId, $clinicId, $today);
            $slotCheck->execute();
            $validSlot = $slotCheck->get_result()->num_rows > 0;
            $slotCheck->close();

            if (!$validSlot) {
                throw new Exception("The selected time slot is no longer available.");
            }
        }

        // C. Compute next daily queue number safely for this clinic (uq_clinic_daily_queue)
        $qStmt = $conn->prepare("
            SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue
            FROM appointments
            WHERE clinic_id = ? AND appointment_date = ?
            FOR UPDATE
        ");
        $qStmt->bind_param("is", $clinicId, $today);
        $qStmt->execute();
        $nextQueue = (int) $qStmt->get_result()->fetch_assoc()['next_queue'];
        $qStmt->close();

        // D. Insert walk-in appointment record
        $insAppt = $conn->prepare("
            INSERT INTO appointments (
                patient_id, clinic_id, slot_id, appointment_date,
                visit_category, queue_number, status, booked_by
            )
            VALUES (?, ?, ?, ?, 'walk_in', ?, 'booked', ?)
        ");
        $insAppt->bind_param("iiisii", $patientId, $clinicId, $slotId, $today, $nextQueue, $userId);
        if (!$insAppt->execute()) {
            throw new Exception("Failed to create walk-in appointment.");
        }
        $insAppt->close();

        $conn->commit();
        $_SESSION['flash_success'] = "Walk-in registered for {$fullName} (Queue #{$nextQueue}).";
        header("Location: /HealthConnect/staff/dashboard.php");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_error'] = $e->getMessage();
        header("Location: /HealthConnect/staff/walkin_booking.php");
        exit;
    }
}

// 3. Fetch today's open slots (optional assignment for walk-ins)
$openSlotsStmt = $conn->prepare("
    SELECT s.slot_id, s.start_time, s.end_time
    FROM slots s
    LEFT JOIN appointments a ON s.slot_id = a.slot_id AND a.status = 'booked'
    WHERE s.clinic_id = ?
      AND s.slot_date = CURDATE()
      AND s.status = 'available'
      AND a.appointment_id IS NULL
    ORDER BY s.start_time ASC
");
$openSlotsStmt->bind_param("i", $clinicId);
$openSlotsStmt->execute();
$openSlots = $openSlotsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$openSlotsStmt->close();

$pageTitle = "Register Walk-In Patient";
$activeNav = "walkin";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Register Walk-In Patient</h1>
        <p class="page-subtitle">Clinic: <strong><?= htmlspecialchars($clinicName) ?></strong> &bull; Date: <strong><?= date('Y-m-d') ?></strong></p>
    </div>
    <a href="/HealthConnect/staff/dashboard.php" class="btn btn-secondary">Back to Daily Schedule</a>
</div>

<div class="help-banner">
    Enter the patient's phone number and name. If the patient already has a HealthConnect account, their profile will be linked automatically; otherwise, a walk-in patient profile will be created.
</div>

<div class="card">
    <form method="POST" action="/HealthConnect/staff/walkin_booking.php">
        <input type="hidden" name="action" value="register_walkin">

        <div class="form-grid">
            <div class="form-group">
                <label for="phone_number">Phone Number (Kenyan Mobile) *</label>
                <input type="tel" id="phone_number" name="phone_number" class="form-control"
                       placeholder="e.g. 0712345678" required>
            </div>

            <div class="form-group">
                <label for="full_name">Patient Full Name *</label>
                <input type="text" id="full_name" name="full_name" class="form-control"
                       placeholder="e.g. Jane Wanjiku" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address (Optional for New Walk-Ins)</label>
                <input type="email" id="email" name="email" class="form-control"
                       placeholder="Optional if patient has no email">
            </div>

            <div class="form-group">
                <label for="slot_id">Assign Open Slot Today (Optional)</label>
                <select id="slot_id" name="slot_id" class="form-control">
                    <option value="">-- Unscheduled Walk-In Queue --</option>
                    <?php foreach ($openSlots as $slot): ?>
                        <option value="<?= (int) $slot['slot_id'] ?>">
                            <?= htmlspecialchars(substr($slot['start_time'], 0, 5) . ' - ' . substr($slot['end_time'], 0, 5)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Add to Today's Queue</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('clinic_staff');

$userId = currentUserId();

// 1. Resolve the staff member's assigned clinic and verification status (BR-01, BR-07)
$stmt = $conn->prepare("
    SELECT cs.clinic_id, cs.position, c.name AS clinic_name, c.verification_status
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
$isVerified         = ($verificationStatus === 'verified');

// 2. Handle POST actions: Create, Toggle Status, or Delete Slot
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$isVerified && $action === 'create') {
        $_SESSION['flash_error'] = "Only verified clinics can publish appointment slots (current status: {$verificationStatus}).";
        header("Location: /HealthConnect/staff/manage_slots.php");
        exit;
    }

    // --- CREATE SLOT ---
    if ($action === 'create') {
        $slotDate  = trim($_POST['slot_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');

        $today = date('Y-m-d');
        $now   = date('H:i');

        if ($slotDate === '' || $startTime === '' || $endTime === '') {
            $_SESSION['flash_error'] = "Please fill in the date, start time, and end time.";
        } elseif ($slotDate < $today || ($slotDate === $today && $startTime <= $now)) {
            $_SESSION['flash_error'] = "Slots cannot be created in the past.";
        } elseif ($endTime <= $startTime) {
            $_SESSION['flash_error'] = "End time must be later than start time.";
        } else {
            // Check for overlapping slots at the same clinic (SRS 3.4.3)
            $overlapStmt = $conn->prepare("
                SELECT slot_id
                FROM slots
                WHERE clinic_id = ?
                  AND slot_date = ?
                  AND start_time < ?
                  AND end_time > ?
                LIMIT 1
            ");
            $overlapStmt->bind_param("isss", $clinicId, $slotDate, $endTime, $startTime);
            $overlapStmt->execute();
            $hasOverlap = $overlapStmt->get_result()->num_rows > 0;
            $overlapStmt->close();

            if ($hasOverlap) {
                $_SESSION['flash_error'] = "This time range overlaps with an existing slot on {$slotDate}.";
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO slots (clinic_id, slot_date, start_time, end_time, status, created_by)
                    VALUES (?, ?, ?, ?, 'available', ?)
                ");
                $insertStmt->bind_param("isssi", $clinicId, $slotDate, $startTime, $endTime, $userId);

                if ($insertStmt->execute()) {
                    $_SESSION['flash_success'] = "Appointment slot created successfully.";
                } else {
                    $_SESSION['flash_error'] = "Could not create slot. Ensure the start time is unique for this date.";
                }
                $insertStmt->close();
            }
        }

        header("Location: /HealthConnect/staff/manage_slots.php");
        exit;
    }

    // --- TOGGLE SLOT STATUS (available <-> unavailable) ---
    if ($action === 'toggle_status') {
        $slotId    = (int) ($_POST['slot_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'unavailable' ? 'unavailable' : 'available';

        // Ensure slot belongs to this clinic and check for active booking
        $checkStmt = $conn->prepare("
            SELECT s.slot_id,
                   (SELECT COUNT(*) FROM appointments a WHERE a.slot_id = s.slot_id AND a.status = 'booked') AS active_bookings
            FROM slots s
            WHERE s.slot_id = ? AND s.clinic_id = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("ii", $slotId, $clinicId);
        $checkStmt->execute();
        $slotRow = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$slotRow) {
            $_SESSION['flash_error'] = "Slot not found for your clinic.";
        } elseif ((int) $slotRow['active_bookings'] > 0 && $newStatus === 'unavailable') {
            $_SESSION['flash_error'] = "Cannot mark a slot unavailable while it has an active patient booking.";
        } else {
            $updStmt = $conn->prepare("UPDATE slots SET status = ? WHERE slot_id = ? AND clinic_id = ?");
            $updStmt->bind_param("sii", $newStatus, $slotId, $clinicId);
            $updStmt->execute();
            $updStmt->close();
            $_SESSION['flash_success'] = "Slot status updated to {$newStatus}.";
        }

        header("Location: /HealthConnect/staff/manage_slots.php");
        exit;
    }

    // --- DELETE SLOT (BR-07: Blocked if active appointment exists) ---
    if ($action === 'delete') {
        $slotId = (int) ($_POST['slot_id'] ?? 0);

        $checkStmt = $conn->prepare("
            SELECT s.slot_id,
                   (SELECT COUNT(*) FROM appointments a WHERE a.slot_id = s.slot_id AND a.status = 'booked') AS active_bookings,
                   (SELECT COUNT(*) FROM appointments a WHERE a.slot_id = s.slot_id) AS total_history
            FROM slots s
            WHERE s.slot_id = ? AND s.clinic_id = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("ii", $slotId, $clinicId);
        $checkStmt->execute();
        $slotRow = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$slotRow) {
            $_SESSION['flash_error'] = "Slot not found for your clinic.";
        } elseif ((int) $slotRow['active_bookings'] > 0) {
            $_SESSION['flash_error'] = "Cannot delete this slot because a patient has an active booking on it (BR-07).";
        } elseif ((int) $slotRow['total_history'] > 0) {
            // Foreign key fk_appointment_slot_clinic is ON DELETE RESTRICT
            $_SESSION['flash_error'] = "This slot has past/cancelled appointment records and cannot be deleted. Mark it as unavailable instead.";
        } else {
            $delStmt = $conn->prepare("DELETE FROM slots WHERE slot_id = ? AND clinic_id = ?");
            $delStmt->bind_param("ii", $slotId, $clinicId);
            $delStmt->execute();
            $delStmt->close();
            $_SESSION['flash_success'] = "Slot deleted successfully.";
        }

        header("Location: /HealthConnect/staff/manage_slots.php");
        exit;
    }
}

// 3. Fetch slots for this clinic (upcoming and recent)
$filterDate = trim($_GET['filter_date'] ?? '');

if ($filterDate !== '') {
    $listStmt = $conn->prepare("
        SELECT s.slot_id, s.slot_date, s.start_time, s.end_time, s.status,
               a.appointment_id, u.full_name AS patient_name
        FROM slots s
        LEFT JOIN appointments a ON s.slot_id = a.slot_id AND a.status = 'booked'
        LEFT JOIN users u ON a.patient_id = u.user_id
        WHERE s.clinic_id = ? AND s.slot_date = ?
        ORDER BY s.slot_date ASC, s.start_time ASC
    ");
    $listStmt->bind_param("is", $clinicId, $filterDate);
} else {
    $listStmt = $conn->prepare("
        SELECT s.slot_id, s.slot_date, s.start_time, s.end_time, s.status,
               a.appointment_id, u.full_name AS patient_name
        FROM slots s
        LEFT JOIN appointments a ON s.slot_id = a.slot_id AND a.status = 'booked'
        LEFT JOIN users u ON a.patient_id = u.user_id
        WHERE s.clinic_id = ? AND s.slot_date >= CURDATE()
        ORDER BY s.slot_date ASC, s.start_time ASC
    ");
    $listStmt->bind_param("i", $clinicId);
}

$listStmt->execute();
$slots = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$pageTitle = "Manage Appointment Slots";
$activeNav = "slots";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Manage Appointment Slots</h1>
        <p class="page-subtitle">Clinic: <strong><?= htmlspecialchars($clinicName) ?></strong></p>
    </div>
</div>

<?php if (!$isVerified): ?>
    <div class="alert alert-warning">
        Your clinic is currently <strong><?= htmlspecialchars($verificationStatus) ?></strong>. Slots cannot be published to patients until an administrator verifies your clinic.
    </div>
<?php else: ?>
    <div class="help-banner">
        Create open appointment time blocks below. Published slots become immediately visible to patients for booking. Slots with active bookings cannot be deleted.
    </div>
<?php endif; ?>

<!-- Create Slot Form -->
<div class="card">
    <h2 style="margin-bottom: 1rem; font-size: 1.15rem;">Add New Time Slot</h2>
    <form id="slotForm" method="POST" action="/HealthConnect/staff/manage_slots.php">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group">
                <label for="slot_date">Date</label>
                <input type="date" id="slot_date" name="slot_date" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required <?= !$isVerified ? 'disabled' : '' ?>>
            </div>
            <div class="form-group">
                <label for="start_time">Start Time</label>
                <input type="time" id="start_time" name="start_time" class="form-control" required <?= !$isVerified ? 'disabled' : '' ?>>
            </div>
            <div class="form-group">
                <label for="end_time">End Time</label>
                <input type="time" id="end_time" name="end_time" class="form-control" required <?= !$isVerified ? 'disabled' : '' ?>>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" <?= !$isVerified ? 'disabled' : '' ?>>Publish Slot</button>
    </form>
</div>

<!-- Existing Slots Table -->
<div class="card">
    <div class="page-header" style="margin-bottom: 1rem;">
        <h2 style="font-size: 1.15rem;">Clinic Slots</h2>
        <form method="GET" action="/HealthConnect/staff/manage_slots.php" style="display: flex; gap: 0.5rem; align-items: center;">
            <input type="date" name="filter_date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($filterDate !== ''): ?>
                <a href="/HealthConnect/staff/manage_slots.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($slots)): ?>
        <p style="color: var(--text-muted);">No slots found for the selected view.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time Window</th>
                        <th>Slot Status</th>
                        <th>Booking Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slots as $slot): ?>
                        <?php $isBooked = !empty($slot['appointment_id']); ?>
                        <tr>
                            <td><?= htmlspecialchars($slot['slot_date']) ?></td>
                            <td><?= htmlspecialchars(substr($slot['start_time'], 0, 5) . ' - ' . substr($slot['end_time'], 0, 5)) ?></td>
                            <td>
                                <?php if ($isBooked): ?>
                                    <span class="badge badge-booked">Booked</span>
                                <?php elseif ($slot['status'] === 'available'): ?>
                                    <span class="badge badge-attended">Available</span>
                                <?php else: ?>
                                    <span class="badge badge-missed">Unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $isBooked ? 'Patient: ' . htmlspecialchars($slot['patient_name']) : 'Open' ?>
                            </td>
                            <td style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                <?php if (!$isBooked): ?>
                                    <form method="POST" action="/HealthConnect/staff/manage_slots.php" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="slot_id" value="<?= (int) $slot['slot_id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $slot['status'] === 'available' ? 'unavailable' : 'available' ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <?= $slot['status'] === 'available' ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>

                                    <form method="POST" action="/HealthConnect/staff/manage_slots.php" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="slot_id" value="<?= (int) $slot['slot_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                data-confirm="Delete this slot on <?= htmlspecialchars($slot['slot_date']) ?>?">
                                            Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.82rem; color: var(--text-muted);">Locked (Active Booking)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
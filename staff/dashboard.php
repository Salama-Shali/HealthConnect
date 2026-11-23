<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('clinic_staff');

$userId = currentUserId();

// 1. Resolve the staff member's assigned clinic (BR-07)
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

// Selected schedule date (defaults to today)
$scheduleDate = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
    $scheduleDate = date('Y-m-d');
}

// 2. Handle Attendance Marking POST Action (FR-07, BR-08)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_attendance') {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $newStatus     = $_POST['status'] ?? '';
    $redirectDate  = trim($_POST['schedule_date'] ?? $scheduleDate);

    if (!in_array($newStatus, ['attended', 'missed'], true)) {
        $_SESSION['flash_error'] = "Invalid attendance status selected.";
    } else {
        // Verify appointment belongs to this clinic and is currently booked
        $checkStmt = $conn->prepare("
            SELECT appointment_id, status
            FROM appointments
            WHERE appointment_id = ? AND clinic_id = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("ii", $appointmentId, $clinicId);
        $checkStmt->execute();
        $appt = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$appt) {
            $_SESSION['flash_error'] = "Appointment not found for your clinic.";
        } elseif ($appt['status'] === 'cancelled') {
            $_SESSION['flash_error'] = "Cancelled appointments cannot be marked as attended or missed.";
        } else {
            $updStmt = $conn->prepare("
                UPDATE appointments
                SET status = ?
                WHERE appointment_id = ? AND clinic_id = ?
            ");
            $updStmt->bind_param("sii", $newStatus, $appointmentId, $clinicId);

            if ($updStmt->execute()) {
                $_SESSION['flash_success'] = "Appointment marked as " . ucfirst($newStatus) . ".";
            } else {
                $_SESSION['flash_error'] = "Failed to update attendance. Please try again.";
            }
            $updStmt->close();
        }
    }

    header("Location: /HealthConnect/staff/dashboard.php?date=" . urlencode($redirectDate));
    exit;
}

// 3. Fetch Daily Schedule & Queue for this clinic (FR-07, BR-07)
$schedStmt = $conn->prepare("
    SELECT a.appointment_id,
           a.appointment_date,
           a.visit_category,
           a.queue_number,
           a.status,
           a.booked_at,
           u.full_name AS patient_name,
           u.phone_number AS patient_phone,
           s.start_time,
           s.end_time
    FROM appointments a
    INNER JOIN users u ON a.patient_id = u.user_id
    LEFT JOIN slots s ON a.slot_id = s.slot_id
    WHERE a.clinic_id = ? AND a.appointment_date = ?
    ORDER BY
        CASE WHEN s.start_time IS NULL THEN 1 ELSE 0 END ASC,
        s.start_time ASC,
        a.queue_number ASC,
        a.booked_at ASC
");
$schedStmt->bind_param("is", $clinicId, $scheduleDate);
$schedStmt->execute();
$appointments = $schedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$schedStmt->close();

// 4. Calculate Quick Daily Summary Counts
$totalCount     = count($appointments);
$bookedCount    = 0;
$attendedCount  = 0;
$missedCount    = 0;
$cancelledCount = 0;

foreach ($appointments as $row) {
    if ($row['status'] === 'booked')    $bookedCount++;
    if ($row['status'] === 'attended')  $attendedCount++;
    if ($row['status'] === 'missed')    $missedCount++;
    if ($row['status'] === 'cancelled') $cancelledCount++;
}

$pageTitle = "Daily Schedule & Queue";
$activeNav = "dashboard";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Daily Schedule &amp; Queue</h1>
        <p class="page-subtitle">
            Clinic: <strong><?= htmlspecialchars($clinicName) ?></strong> &bull;
            Date: <strong><?= htmlspecialchars($scheduleDate) ?></strong>
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="/HealthConnect/staff/walkin_booking.php" class="btn btn-primary">+ Register Walk-In</a>
        <a href="/HealthConnect/staff/manage_slots.php" class="btn btn-secondary">Manage Slots</a>
    </div>
</div>

<?php if ($verificationStatus !== 'verified'): ?>
    <div class="alert alert-warning">
        Your clinic status is <strong><?= htmlspecialchars($verificationStatus) ?></strong>. Public bookings remain hidden until an administrator verifies your clinic[cite: 4].
    </div>
<?php endif; ?>

<!-- Summary Stat Cards -->
<div class="stats-grid">
    <div class="card stat-card">
        <h3>Total Scheduled</h3>
        <div class="stat-value"><?= $totalCount ?></div>
    </div>
    <div class="card stat-card">
        <h3>Waiting / Booked</h3>
        <div class="stat-value"><?= $bookedCount ?></div>
    </div>
    <div class="card stat-card">
        <h3>Attended</h3>
        <div class="stat-value" style="color: var(--success-text);"><?= $attendedCount ?></div>
    </div>
    <div class="card stat-card">
        <h3>Missed / Cancelled</h3>
        <div class="stat-value" style="color: var(--warning-text);"><?= $missedCount + $cancelledCount ?></div>
    </div>
</div>

<!-- Date Filter & Schedule Table -->
<div class="card">
    <div class="page-header" style="margin-bottom: 1rem;">
        <h2 style="font-size: 1.15rem;">Patient Queue (<?= htmlspecialchars($scheduleDate) ?>)</h2>
        <form method="GET" action="/HealthConnect/staff/dashboard.php" style="display: flex; gap: 0.5rem; align-items: center;">
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($scheduleDate) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">View Date</button>
            <?php if ($scheduleDate !== date('Y-m-d')): ?>
                <a href="/HealthConnect/staff/dashboard.php" class="btn btn-secondary btn-sm">Today</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($appointments)): ?>
        <p style="color: var(--text-muted);">No appointments or walk-ins recorded for <?= htmlspecialchars($scheduleDate) ?>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time / Queue</th>
                        <th>Patient Name</th>
                        <th>Phone Number</th>
                        <th>Visit Type</th>
                        <th>Status</th>
                        <th>Mark Attendance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $appt): ?>
                        <tr>
                            <td>
                                <?php if (!empty($appt['start_time'])): ?>
                                    <strong><?= htmlspecialchars(substr($appt['start_time'], 0, 5) . ' - ' . substr($appt['end_time'], 0, 5)) ?></strong>
                                    <?php if (!empty($appt['queue_number'])): ?>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);">Queue #<?= (int) $appt['queue_number'] ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong>Walk-In Queue #<?= (int) ($appt['queue_number'] ?? 0) ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">Unscheduled</div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($appt['patient_name']) ?></td>
                            <td><?= htmlspecialchars($appt['patient_phone']) ?></td>
                            <td>
                                <?php if ($appt['visit_category'] === 'walk_in'): ?>
                                    <span class="badge badge-walkin">Walk-In</span>
                                <?php else: ?>
                                    <span class="badge badge-booked">Ordinary</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($appt['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($appt['status'])) ?>
                                </span>
                            </td>
                            <td style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                <?php if ($appt['status'] !== 'cancelled'): ?>
                                    <form method="POST" action="/HealthConnect/staff/dashboard.php?date=<?= urlencode($scheduleDate) ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="mark_attendance">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $appt['appointment_id'] ?>">
                                        <input type="hidden" name="schedule_date" value="<?= htmlspecialchars($scheduleDate) ?>">
                                        <input type="hidden" name="status" value="attended">
                                        <button type="submit" class="btn btn-success btn-sm" <?= $appt['status'] === 'attended' ? 'disabled' : '' ?>>
                                            Attended
                                        </button>
                                    </form>

                                    <form method="POST" action="/HealthConnect/staff/dashboard.php?date=<?= urlencode($scheduleDate) ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="mark_attendance">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $appt['appointment_id'] ?>">
                                        <input type="hidden" name="schedule_date" value="<?= htmlspecialchars($scheduleDate) ?>">
                                        <input type="hidden" name="status" value="missed">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                data-confirm="Mark <?= htmlspecialchars($appt['patient_name']) ?> as missed?"
                                                <?= $appt['status'] === 'missed' ? 'disabled' : '' ?>>
                                            Missed
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.82rem; color: var(--text-muted);">Cancelled</span>
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
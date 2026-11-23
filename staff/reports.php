<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('clinic_staff');

$userId = currentUserId();

// 1. Resolve the staff member's assigned clinic (BR-07)
$stmt = $conn->prepare("
    SELECT cs.clinic_id, c.name AS clinic_name
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

$clinicId   = (int) $staffClinic['clinic_id'];
$clinicName = $staffClinic['clinic_name'];

// 2. Date range filter (defaults to last 30 days through today)
$startDate = trim($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}
if ($endDate < $startDate) {
    $temp      = $startDate;
    $startDate = $endDate;
    $endDate   = $temp;
}

// 3. Appointment Summary Metrics (FR-09, BR-08)
$apptStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_appointments,
        SUM(CASE WHEN status = 'attended' THEN 1 ELSE 0 END) AS attended_count,
        SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) AS missed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) AS pending_booked_count,
        SUM(CASE WHEN visit_category = 'walk_in' THEN 1 ELSE 0 END) AS walkin_count,
        SUM(CASE WHEN visit_category = 'ordinary' THEN 1 ELSE 0 END) AS ordinary_count
    FROM appointments
    WHERE clinic_id = ?
      AND appointment_date BETWEEN ? AND ?
");
$apptStmt->bind_param("iss", $clinicId, $startDate, $endDate);
$apptStmt->execute();
$apptStats = $apptStmt->get_result()->fetch_assoc();
$apptStmt->close();

$totalAppointments  = (int) ($apptStats['total_appointments'] ?? 0);
$attendedCount      = (int) ($apptStats['attended_count'] ?? 0);
$missedCount        = (int) ($apptStats['missed_count'] ?? 0);
$cancelledCount     = (int) ($apptStats['cancelled_count'] ?? 0);
$pendingBookedCount = (int) ($apptStats['pending_booked_count'] ?? 0);
$walkinCount        = (int) ($apptStats['walkin_count'] ?? 0);
$ordinaryCount      = (int) ($apptStats['ordinary_count'] ?? 0);

// BR-08: Attendance rate calculated strictly from recorded attended vs missed outcomes
$recordedOutcomes = $attendedCount + $missedCount;
$attendanceRate   = $recordedOutcomes > 0 ? round(($attendedCount / $recordedOutcomes) * 100, 1) : 0.0;
$cancellationRate = $totalAppointments > 0 ? round(($cancelledCount / $totalAppointments) * 100, 1) : 0.0;

// 4. Slot Utilization Metrics (FR-09)
$slotStmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT s.slot_id) AS total_slots,
        COUNT(DISTINCT CASE WHEN a.status IN ('booked', 'attended', 'missed') THEN s.slot_id END) AS utilized_slots
    FROM slots s
    LEFT JOIN appointments a ON s.slot_id = a.slot_id
    WHERE s.clinic_id = ?
      AND s.slot_date BETWEEN ? AND ?
");
$slotStmt->bind_param("iss", $clinicId, $startDate, $endDate);
$slotStmt->execute();
$slotStats = $slotStmt->get_result()->fetch_assoc();
$slotStmt->close();

$totalSlots      = (int) ($slotStats['total_slots'] ?? 0);
$utilizedSlots   = (int) ($slotStats['utilized_slots'] ?? 0);
$utilizationRate = $totalSlots > 0 ? round(($utilizedSlots / $totalSlots) * 100, 1) : 0.0;

// 5. Daily Breakdown Table within the Date Range
$dailyStmt = $conn->prepare("
    SELECT
        appointment_date,
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'attended' THEN 1 ELSE 0 END) AS attended,
        SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) AS missed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) AS booked,
        SUM(CASE WHEN visit_category = 'walk_in' THEN 1 ELSE 0 END) AS walk_ins
    FROM appointments
    WHERE clinic_id = ?
      AND appointment_date BETWEEN ? AND ?
    GROUP BY appointment_date
    ORDER BY appointment_date DESC
");
$dailyStmt->bind_param("iss", $clinicId, $startDate, $endDate);
$dailyStmt->execute();
$dailyRows = $dailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dailyStmt->close();

$pageTitle = "Clinic Performance Reports";
$activeNav = "reports";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Clinic Reports</h1>
        <p class="page-subtitle">
            Clinic: <strong><?= htmlspecialchars($clinicName) ?></strong> &bull;
            Period: <strong><?= htmlspecialchars($startDate) ?></strong> to <strong><?= htmlspecialchars($endDate) ?></strong>
        </p>
    </div>
    <button type="button" onclick="window.print()" class="btn btn-secondary">Print Report</button>
</div>

<div class="help-banner">
    Attendance rates are calculated strictly from appointments marked as <strong>Attended</strong> or <strong>Missed</strong>. Slot utilization reflects the percentage of published slots filled by non-cancelled bookings.
</div>

<!-- Date Range Filter -->
<div class="card">
    <form method="GET" action="/HealthConnect/staff/reports.php" class="form-grid" style="align-items: end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="start_date">From Date</label>
            <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>" required>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="end_date">To Date</label>
            <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>" required>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary">Generate Report</button>
        </div>
    </form>
</div>

<!-- Key Performance Metrics -->
<div class="stats-grid">
    <div class="card stat-card">
        <h3>Attendance Rate</h3>
        <div class="stat-value" style="color: var(--success-text);"><?= $attendanceRate ?>%</div>
        <p class="page-subtitle"><?= $attendedCount ?> attended / <?= $recordedOutcomes ?> completed</p>
    </div>

    <div class="card stat-card">
        <h3>Cancellation Rate</h3>
        <div class="stat-value" style="color: var(--danger-text);"><?= $cancellationRate ?>%</div>
        <p class="page-subtitle"><?= $cancelledCount ?> cancelled of <?= $totalAppointments ?> total</p>
    </div>

    <div class="card stat-card">
        <h3>Slot Utilization</h3>
        <div class="stat-value"><?= $utilizationRate ?>%</div>
        <p class="page-subtitle"><?= $utilizedSlots ?> filled of <?= $totalSlots ?> published slots</p>
    </div>

    <div class="card stat-card">
        <h3>Visit Mix</h3>
        <div class="stat-value"><?= $totalAppointments ?></div>
        <p class="page-subtitle"><?= $ordinaryCount ?> scheduled &bull; <?= $walkinCount ?> walk-ins</p>
    </div>
</div>

<!-- Daily Breakdown Table -->
<div class="card">
    <h2 style="margin-bottom: 1rem; font-size: 1.15rem;">Daily Breakdown</h2>

    <?php if (empty($dailyRows)): ?>
        <p style="color: var(--text-muted);">No appointment records found in the selected date range.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Visits</th>
                        <th>Walk-Ins</th>
                        <th>Attended</th>
                        <th>Missed</th>
                        <th>Cancelled</th>
                        <th>Still Booked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyRows as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['appointment_date']) ?></strong></td>
                            <td><?= (int) $row['total'] ?></td>
                            <td><?= (int) $row['walk_ins'] ?></td>
                            <td><span class="badge badge-attended"><?= (int) $row['attended'] ?></span></td>
                            <td><span class="badge badge-missed"><?= (int) $row['missed'] ?></span></td>
                            <td><span class="badge badge-cancelled"><?= (int) $row['cancelled'] ?></span></td>
                            <td><span class="badge badge-booked"><?= (int) $row['booked'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
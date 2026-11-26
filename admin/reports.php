<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

// Date range (defaults to last 30 days)
$startDate = trim($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}
if ($endDate < $startDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

// 1. Users and clinics (current totals)
$people = $conn->query(
    "SELECT
        SUM(role = 'patient')      AS patients,
        SUM(role = 'clinic_staff') AS staff,
        SUM(role = 'admin')        AS admins,
        SUM(is_active = 0)         AS suspended_users
     FROM users"
)->fetch_assoc();

$clinicStats = $conn->query(
    "SELECT
        SUM(verification_status = 'verified')  AS verified,
        SUM(verification_status = 'pending')   AS pending,
        SUM(verification_status = 'suspended') AS suspended,
        SUM(verification_status = 'rejected')  AS rejected
     FROM clinics"
)->fetch_assoc();

// 2. Appointments in the period
$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'attended')  AS attended,
        SUM(status = 'missed')    AS missed,
        SUM(status = 'cancelled') AS cancelled,
        SUM(status = 'booked')    AS booked,
        SUM(visit_category = 'walk_in') AS walk_ins
     FROM appointments
     WHERE appointment_date BETWEEN ? AND ?"
);
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total     = (int) $appt['total'];
$attended  = (int) $appt['attended'];
$missed    = (int) $appt['missed'];
$cancelled = (int) $appt['cancelled'];
$recorded  = $attended + $missed;
$attendanceRate   = $recorded > 0 ? round($attended / $recorded * 100, 1) : 0.0;
$cancellationRate = $total > 0 ? round($cancelled / $total * 100, 1) : 0.0;

// 3. Per-clinic breakdown
$stmt = $conn->prepare(
    "SELECT c.name, c.verification_status,
            COUNT(a.appointment_id) AS total,
            SUM(a.status = 'attended')  AS attended,
            SUM(a.status = 'missed')    AS missed,
            SUM(a.status = 'cancelled') AS cancelled,
            SUM(a.visit_category = 'walk_in') AS walk_ins
     FROM clinics c
     LEFT JOIN appointments a
            ON a.clinic_id = c.clinic_id
           AND a.appointment_date BETWEEN ? AND ?
     GROUP BY c.clinic_id, c.name, c.verification_status
     ORDER BY total DESC, c.name"
);
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$perClinic = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4. SMS performance in the period (retries are separate attempts, so count messages by final outcome)
$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS attempts,
        SUM(status = 'sent')    AS sent,
        SUM(status = 'failed')  AS failed,
        SUM(status = 'pending') AS pending,
        SUM(attempt_number > 1) AS retries
     FROM sms_logs
     WHERE DATE(created_at) BETWEEN ? AND ?"
);
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$sms = $stmt->get_result()->fetch_assoc();
$stmt->close();

$smsAttempts = (int) $sms['attempts'];
$smsSent     = (int) $sms['sent'];
$smsFailed   = (int) $sms['failed'];
$smsDelivery = ($smsSent + $smsFailed) > 0 ? round($smsSent / ($smsSent + $smsFailed) * 100, 1) : 0.0;

$pageTitle = "System Reports";
$activeNav = "reports";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>System Reports</h1>
        <p class="page-subtitle">
            Period: <strong><?= htmlspecialchars($startDate) ?></strong> to <strong><?= htmlspecialchars($endDate) ?></strong>
        </p>
    </div>
    <button type="button" onclick="window.print()" class="btn btn-secondary">Print Report</button>
</div>

<div class="card">
    <form method="GET" action="/HealthConnect/admin/reports.php" class="form-grid" style="align-items: end;">
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

<div class="stats-grid">
    <div class="card stat-card">
        <h3>Users</h3>
        <div class="stat-value"><?= (int) $people['patients'] + (int) $people['staff'] + (int) $people['admins'] ?></div>
        <p class="page-subtitle"><?= (int) $people['patients'] ?> patients &bull; <?= (int) $people['staff'] ?> staff &bull; <?= (int) $people['admins'] ?> admins (<?= (int) $people['suspended_users'] ?> suspended)</p>
    </div>
    <div class="card stat-card">
        <h3>Clinics</h3>
        <div class="stat-value"><?= (int) $clinicStats['verified'] ?> verified</div>
        <p class="page-subtitle"><?= (int) $clinicStats['pending'] ?> pending &bull; <?= (int) $clinicStats['suspended'] ?> suspended &bull; <?= (int) $clinicStats['rejected'] ?> rejected</p>
    </div>
    <div class="card stat-card">
        <h3>Appointments</h3>
        <div class="stat-value"><?= $total ?></div>
        <p class="page-subtitle"><?= (int) $appt['walk_ins'] ?> walk-ins &bull; <?= (int) $appt['booked'] ?> still booked</p>
    </div>
    <div class="card stat-card">
        <h3>Attendance Rate</h3>
        <div class="stat-value" style="color: var(--success-text);"><?= $attendanceRate ?>%</div>
        <p class="page-subtitle"><?= $attended ?> attended / <?= $recorded ?> recorded outcomes</p>
    </div>
    <div class="card stat-card">
        <h3>Cancellation Rate</h3>
        <div class="stat-value" style="color: var(--danger-text);"><?= $cancellationRate ?>%</div>
        <p class="page-subtitle"><?= $cancelled ?> cancelled of <?= $total ?></p>
    </div>
    <div class="card stat-card">
        <h3>SMS Delivery</h3>
        <div class="stat-value"><?= $smsDelivery ?>%</div>
        <p class="page-subtitle"><?= $smsSent ?> sent &bull; <?= $smsFailed ?> failed attempts &bull; <?= (int) $sms['pending'] ?> pending (<?= (int) $sms['retries'] ?> retries)</p>
    </div>
</div>

<div class="card">
    <h2 style="margin-bottom: 1rem; font-size: 1.15rem;">Activity by Clinic</h2>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Clinic</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Walk-Ins</th>
                    <th>Attended</th>
                    <th>Missed</th>
                    <th>Cancelled</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($perClinic as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                    <td><?= htmlspecialchars(ucfirst($row['verification_status'])) ?></td>
                    <td><?= (int) $row['total'] ?></td>
                    <td><?= (int) $row['walk_ins'] ?></td>
                    <td><span class="badge badge-attended"><?= (int) $row['attended'] ?></span></td>
                    <td><span class="badge badge-missed"><?= (int) $row['missed'] ?></span></td>
                    <td><span class="badge badge-cancelled"><?= (int) $row['cancelled'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>

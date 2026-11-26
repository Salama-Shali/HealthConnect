<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = currentUserId();

// Allowed status changes: action => [new status, statuses it may start from]
$transitions = [
    'verify'    => ['verified',  ['pending', 'rejected', 'suspended']],
    'reject'    => ['rejected',  ['pending']],
    'suspend'   => ['suspended', ['verified']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $clinicId = filter_var($_POST['clinic_id'] ?? '', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $returnStatus = $_POST['return_status'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash_error'] = "Invalid form submission. Please try again.";
    } elseif (!$clinicId || !isset($transitions[$action])) {
        $_SESSION['flash_error'] = "Invalid request.";
    } else {
        [$newStatus, $allowedFrom] = $transitions[$action];
        $placeholders = implode(',', array_fill(0, count($allowedFrom), '?'));

        // Only change the status if the clinic is currently in an allowed state.
        $sql = "UPDATE clinics
                SET verification_status = ?, verified_by = ?, verified_at = NOW()
                WHERE clinic_id = ? AND verification_status IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $types = "sii" . str_repeat("s", count($allowedFrom));
        $params = array_merge([$newStatus, $adminId, $clinicId], $allowedFrom);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        if ($stmt->affected_rows === 1) {
            $_SESSION['flash_success'] = "Clinic status changed to " . $newStatus . ".";
        } else {
            $_SESSION['flash_error'] = "That change is not allowed for the clinic's current status.";
        }
        $stmt->close();
    }

    $query = in_array($returnStatus, ['pending', 'verified', 'rejected', 'suspended'], true)
        ? '?status=' . $returnStatus : '';
    header("Location: /HealthConnect/admin/clinics.php" . $query);
    exit;
}

// Filter by status.
$validStatuses = ['pending', 'verified', 'rejected', 'suspended'];
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$sql = "SELECT c.clinic_id, c.name, c.license_number, c.county, c.address, c.contact_phone,
               c.verification_status, c.verified_at, c.created_at,
               u.full_name AS verified_by_name,
               (SELECT COUNT(*) FROM clinic_staff cs WHERE cs.clinic_id = c.clinic_id) AS staff_count,
               (SELECT COUNT(*) FROM appointments a
                 WHERE a.clinic_id = c.clinic_id AND a.status = 'booked'
                   AND a.appointment_date >= CURDATE()) AS upcoming_bookings
        FROM clinics c
        LEFT JOIN users u ON u.user_id = c.verified_by";
if ($statusFilter !== '') {
    $sql .= " WHERE c.verification_status = ?";
}
$sql .= " ORDER BY FIELD(c.verification_status, 'pending', 'verified', 'suspended', 'rejected'), c.name";

$stmt = $conn->prepare($sql);
if ($statusFilter !== '') {
    $stmt->bind_param("s", $statusFilter);
}
$stmt->execute();
$clinics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusBadge = [
    'pending'   => 'badge-booked',
    'verified'  => 'badge-attended',
    'suspended' => 'badge-missed',
    'rejected'  => 'badge-cancelled',
];

$pageTitle = "Verify Clinics";
$activeNav = "clinics";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Clinic Verification</h1>
        <p class="page-subtitle">Only verified clinics appear to patients and can publish slots.</p>
    </div>
</div>

<div class="card">
    <form method="GET" action="/HealthConnect/admin/clinics.php" class="form-grid" style="align-items: end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="status">Show clinics</label>
            <select id="status" name="status" class="form-control">
                <option value="">All statuses</option>
                <?php foreach ($validStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if (empty($clinics)): ?>
        <p style="color: var(--text-muted);">No clinics found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Clinic</th>
                        <th>Location</th>
                        <th>Licence</th>
                        <th>Status</th>
                        <th>Staff</th>
                        <th>Upcoming</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clinics as $c): ?>
                    <?php $st = $c['verification_status']; ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($c['name']) ?></strong><br>
                            <small style="color: var(--text-muted);"><?= htmlspecialchars($c['contact_phone'] ?? '') ?></small>
                        </td>
                        <td><?= htmlspecialchars($c['county']) ?><br><small style="color: var(--text-muted);"><?= htmlspecialchars($c['address']) ?></small></td>
                        <td><?= htmlspecialchars($c['license_number'] ?? 'Not provided') ?></td>
                        <td>
                            <span class="badge <?= $statusBadge[$st] ?>"><?= ucfirst($st) ?></span>
                            <?php if ($c['verified_at']): ?>
                                <br><small style="color: var(--text-muted);"><?= htmlspecialchars(date('d M Y', strtotime($c['verified_at']))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $c['staff_count'] ?></td>
                        <td><?= (int) $c['upcoming_bookings'] ?></td>
                        <td>
                            <?php foreach ($transitions as $action => [$newStatus, $from]): ?>
                                <?php if (!in_array($st, $from, true)) continue; ?>
                                <?php
                                    $label = [
                                        'verify'  => ($st === 'pending' ? 'Verify' : 'Reinstate'),
                                        'reject'  => 'Reject',
                                        'suspend' => 'Suspend',
                                    ][$action];
                                    $btn = ['verify' => 'btn-success', 'reject' => 'btn-danger', 'suspend' => 'btn-danger'][$action];
                                    $confirm = $action === 'suspend'
                                        ? "Suspend this clinic? Patients will no longer be able to book it. " . (int) $c['upcoming_bookings'] . " upcoming booking(s) stay in the system."
                                        : "Change this clinic's status to " . $newStatus . "?";
                                ?>
                                <form method="POST" action="/HealthConnect/admin/clinics.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="clinic_id" value="<?= (int) $c['clinic_id'] ?>">
                                    <input type="hidden" name="action" value="<?= $action ?>">
                                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                                    <button type="submit" class="btn btn-sm <?= $btn ?>" data-confirm="<?= htmlspecialchars($confirm) ?>"><?= $label ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>

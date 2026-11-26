<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sms.php';

requireRole('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// "Run queue now": queue due reminders and send everything that is due (also retries).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = "Invalid form submission. Please try again.";
    } else {
        $queued = queueDueReminders($conn);
        $result = processDueSms($conn);
        $_SESSION['flash_success'] = "Queue processed: $queued reminder(s) queued, {$result['sent']} sent, {$result['failed']} failed.";
    }
    header("Location: /HealthConnect/admin/sms_log.php");
    exit;
}

// Filters and paging
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['pending', 'sent', 'failed'], true)) {
    $statusFilter = '';
}
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['booking_confirmation', 'appointment_reminder', 'cancellation_confirmation'], true)) {
    $typeFilter = '';
}

$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = [];
$types = '';
$params = [];
if ($statusFilter !== '') { $where[] = "l.status = ?"; $types .= 's'; $params[] = $statusFilter; }
if ($typeFilter !== '')   { $where[] = "l.message_type = ?"; $types .= 's'; $params[] = $typeFilter; }
$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

$countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM sms_logs l" . $whereSql);
if ($params) { $countStmt->bind_param($types, ...$params); }
$countStmt->execute();
$totalRows = (int) $countStmt->get_result()->fetch_assoc()['n'];
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$sql = "SELECT l.*, u.full_name AS patient_name
        FROM sms_logs l
        INNER JOIN appointments a ON a.appointment_id = l.appointment_id
        INNER JOIN users u ON u.user_id = a.patient_id"
     . $whereSql . " ORDER BY l.created_at DESC, l.sms_id DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$typeLabels = [
    'booking_confirmation'      => 'Booking confirmation',
    'appointment_reminder'      => 'Reminder',
    'cancellation_confirmation' => 'Cancellation',
];
$statusBadge = ['sent' => 'badge-attended', 'failed' => 'badge-cancelled', 'pending' => 'badge-booked'];

function smsPageLink(int $p, string $status, string $type): string
{
    return '?' . http_build_query(['page' => $p, 'status' => $status, 'type' => $type]);
}

$pageTitle = "SMS Log";
$activeNav = "sms";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>SMS Log</h1>
        <p class="page-subtitle">Every SMS attempt, including retries (up to 3 retries after the first attempt).</p>
    </div>
    <form method="POST" action="/HealthConnect/admin/sms_log.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <button type="submit" class="btn btn-primary">Send due SMS now</button>
    </form>
</div>

<div class="card">
    <form method="GET" action="/HealthConnect/admin/sms_log.php" class="form-grid" style="align-items: end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="status">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="">All</option>
                <?php foreach (['pending', 'sent', 'failed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="type">Type</label>
            <select id="type" name="type" class="form-control">
                <option value="">All</option>
                <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if (empty($rows)): ?>
        <p style="color: var(--text-muted);">No SMS records found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>Type</th>
                        <th>Attempt</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['sent_at'] ?? $r['scheduled_for']))) ?></td>
                        <td><?= htmlspecialchars($r['patient_name']) ?><br><small style="color: var(--text-muted);">Appt #<?= (int) $r['appointment_id'] ?></small></td>
                        <td><?= htmlspecialchars($r['recipient_phone']) ?></td>
                        <td><?= htmlspecialchars($typeLabels[$r['message_type']] ?? $r['message_type']) ?></td>
                        <td><?= (int) $r['attempt_number'] ?> of 4</td>
                        <td><span class="badge <?= $statusBadge[$r['status']] ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td style="max-width: 280px;">
                            <?php if ($r['status'] === 'failed' && $r['error_message']): ?>
                                <small style="color: var(--danger-text);"><?= htmlspecialchars($r['error_message']) ?></small>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <small style="color: var(--text-muted);">Due <?= htmlspecialchars(date('d M H:i', strtotime($r['scheduled_for']))) ?></small>
                            <?php elseif ($r['provider_message_id']): ?>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($r['provider_message_id']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="margin-top: 1rem;">
            Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)
            <?php if ($page > 1): ?>
                &nbsp;<a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(smsPageLink($page - 1, $statusFilter, $typeFilter)) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                &nbsp;<a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(smsPageLink($page + 1, $statusFilter, $typeFilter)) ?>">Next</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>

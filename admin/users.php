<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sms.php'; // for normalizeKenyanPhone()

requireRole('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $userId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash_error'] = "Invalid form submission. Please try again.";

    } elseif ($action === 'create_staff') {

        // Create a new clinic staff login and link it to a clinic in one step.
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = normalizeKenyanPhone(trim($_POST['phone_number'] ?? ''));
        $password = $_POST['password'] ?? '';
        $clinicId = filter_var($_POST['clinic_id'] ?? '', FILTER_VALIDATE_INT);
        $position = trim($_POST['position'] ?? '');
        $position = $position !== '' ? $position : null;

        $error = null;
        if ($fullName === '' || $email === '' || $password === '' || !$clinicId) {
            $error = "Please fill in name, email, password and clinic.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!$phone) {
            $error = "Please enter a valid Kenyan mobile number, e.g. 0712345678.";
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = "Password must contain at least 8 characters, including letters and numbers.";
        } else {
            $chk = $conn->prepare("SELECT 1 FROM clinics WHERE clinic_id = ? LIMIT 1");
            $chk->bind_param("i", $clinicId);
            $chk->execute();
            if (!$chk->get_result()->fetch_row()) {
                $error = "The selected clinic does not exist.";
            }
            $chk->close();
        }

        if ($error !== null) {
            $_SESSION['flash_error'] = $error;
        } else {
            try {
                $conn->begin_transaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare(
                    "INSERT INTO users (full_name, phone_number, email, password_hash, role)
                     VALUES (?, ?, ?, ?, 'clinic_staff')"
                );
                $ins->bind_param("ssss", $fullName, $phone, $email, $hash);
                $ins->execute();
                $newUserId = (int) $conn->insert_id;
                $ins->close();

                $link = $conn->prepare("INSERT INTO clinic_staff (user_id, clinic_id, position) VALUES (?, ?, ?)");
                $link->bind_param("iis", $newUserId, $clinicId, $position);
                $link->execute();
                $link->close();

                $conn->commit();
                $_SESSION['flash_success'] = "Staff account created for " . $fullName . ". Share the email and password with them securely.";
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() === 1062) {
                    $_SESSION['flash_error'] = "That email address or phone number is already registered.";
                } else {
                    error_log("Create staff error: " . $e->getMessage());
                    $_SESSION['flash_error'] = "Could not create the staff account. Please try again.";
                }
            }
        }

    } elseif (!$userId) {
        $_SESSION['flash_error'] = "Invalid user.";

    } elseif (in_array($action, ['suspend', 'reactivate'], true)) {

        if ($action === 'suspend' && $userId === $adminId) {
            $_SESSION['flash_error'] = "You cannot suspend your own account.";
        } else {
            $newState = $action === 'reactivate' ? 1 : 0;
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ? AND is_active <> ?");
            $stmt->bind_param("iii", $newState, $userId, $newState);
            $stmt->execute();
            $_SESSION['flash_success'] = $stmt->affected_rows === 1
                ? ($newState ? "User reactivated." : "User suspended. They can no longer log in.")
                : "No change was made.";
            $stmt->close();
        }

    } elseif ($action === 'assign_clinic') {

        // Link a clinic staff account to a clinic (staff pages need this to work).
        $clinicId = filter_var($_POST['clinic_id'] ?? '', FILTER_VALIDATE_INT);
        $position = trim($_POST['position'] ?? '');

        $check = $conn->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
        $check->bind_param("i", $userId);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$clinicId || !$target || $target['role'] !== 'clinic_staff') {
            $_SESSION['flash_error'] = "Only clinic staff accounts can be assigned to a clinic.";
        } else {
            try {
                // A staff account belongs to one clinic: replace any existing link.
                $conn->begin_transaction();
                $del = $conn->prepare("DELETE FROM clinic_staff WHERE user_id = ?");
                $del->bind_param("i", $userId);
                $del->execute();
                $del->close();

                $ins = $conn->prepare("INSERT INTO clinic_staff (user_id, clinic_id, position) VALUES (?, ?, ?)");
                $pos = $position !== '' ? $position : null;
                $ins->bind_param("iis", $userId, $clinicId, $pos);
                $ins->execute();
                $ins->close();
                $conn->commit();

                $_SESSION['flash_success'] = "Staff member assigned to clinic.";
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log("Assign staff error: " . $e->getMessage());
                $_SESSION['flash_error'] = "Could not assign the staff member. Check that the clinic exists.";
            }
        }

    } elseif ($action === 'set_role') {

        // Promote a registered user to clinic staff (or return them to patient).
        $role = $_POST['role'] ?? '';
        if ($userId === $adminId) {
            $_SESSION['flash_error'] = "You cannot change your own role.";
        } elseif (!in_array($role, ['patient', 'clinic_staff'], true)) {
            $_SESSION['flash_error'] = "Invalid role.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ? AND role <> 'admin' AND role <> ?");
            $stmt->bind_param("sis", $role, $userId, $role);
            $stmt->execute();
            $_SESSION['flash_success'] = $stmt->affected_rows === 1 ? "Role updated." : "No change was made.";
            $stmt->close();
        }
    }

    header("Location: /HealthConnect/admin/users.php?" . http_build_query([
        'role' => $_POST['return_role'] ?? '',
        'q'    => $_POST['return_q'] ?? '',
    ]));
    exit;
}

// Filters
$roleFilter = $_GET['role'] ?? '';
if (!in_array($roleFilter, ['patient', 'clinic_staff', 'admin'], true)) {
    $roleFilter = '';
}
$search = trim($_GET['q'] ?? '');

$where = [];
$types = '';
$params = [];
if ($roleFilter !== '') {
    $where[] = "u.role = ?";
    $types .= 's';
    $params[] = $roleFilter;
}
if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
    $types .= 'sss';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$sql = "SELECT u.user_id, u.full_name, u.email, u.phone_number, u.role, u.is_active, u.created_at,
               c.clinic_id, c.name AS clinic_name
        FROM users u
        LEFT JOIN clinic_staff cs ON cs.user_id = u.user_id
        LEFT JOIN clinics c ON c.clinic_id = cs.clinic_id"
     . ($where ? " WHERE " . implode(" AND ", $where) : "")
     . " ORDER BY u.created_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Clinics for the "assign to clinic" dropdown.
$clinicList = $conn->query("SELECT clinic_id, name FROM clinics ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Manage Users";
$activeNav = "users";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1>Manage Users</h1>
        <p class="page-subtitle">Suspend or reactivate accounts, and link clinic staff to their clinic. Showing up to 200 users.</p>
    </div>
</div>

<div class="card">
    <details>
        <summary style="cursor: pointer; font-weight: 600;">+ Add clinic staff</summary>
        <form method="POST" action="/HealthConnect/admin/users.php" class="form-grid" style="margin-top: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create_staff">

            <div class="form-group">
                <label for="new_full_name">Full Name</label>
                <input type="text" id="new_full_name" name="full_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="new_email">Email Address</label>
                <input type="email" id="new_email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="new_phone">Phone Number</label>
                <input type="tel" id="new_phone" name="phone_number" class="form-control" placeholder="0712345678" required>
            </div>
            <div class="form-group">
                <label for="new_password">Temporary Password</label>
                <input type="password" id="new_password" name="password" class="form-control" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="new_clinic">Clinic</label>
                <select id="new_clinic" name="clinic_id" class="form-control" required>
                    <option value="">Select clinic...</option>
                    <?php foreach ($clinicList as $cl): ?>
                        <option value="<?= (int) $cl['clinic_id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="new_position">Position (optional)</label>
                <input type="text" id="new_position" name="position" class="form-control" placeholder="Receptionist">
            </div>
            <div class="form-group" style="align-self: end;">
                <button type="submit" class="btn btn-primary">Create Staff Account</button>
            </div>
        </form>
    </details>
</div>

<div class="card">
    <form method="GET" action="/HealthConnect/admin/users.php" class="form-grid" style="align-items: end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" class="form-control" placeholder="Name, email or phone" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="role">Role</label>
            <select id="role" name="role" class="form-control">
                <option value="">All roles</option>
                <option value="patient" <?= $roleFilter === 'patient' ? 'selected' : '' ?>>Patient</option>
                <option value="clinic_staff" <?= $roleFilter === 'clinic_staff' ? 'selected' : '' ?>>Clinic staff</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if (empty($users)): ?>
        <p style="color: var(--text-muted);">No users found.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Clinic</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $isSelf = (int) $u['user_id'] === $adminId; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['full_name']) ?></strong><?= $isSelf ? ' <small>(you)</small>' : '' ?></td>
                        <td><?= htmlspecialchars($u['email']) ?><br><small style="color: var(--text-muted);"><?= htmlspecialchars($u['phone_number']) ?></small></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?></td>
                        <td><?= $u['clinic_name'] ? htmlspecialchars($u['clinic_name']) : '<span style="color: var(--text-muted);">&mdash;</span>' ?></td>
                        <td>
                            <?php if ((int) $u['is_active'] === 1): ?>
                                <span class="badge badge-attended">Active</span>
                            <?php else: ?>
                                <span class="badge badge-cancelled">Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isSelf): ?>
                                <form method="POST" action="/HealthConnect/admin/users.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                    <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                                    <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                    <?php if ((int) $u['is_active'] === 1): ?>
                                        <input type="hidden" name="action" value="suspend">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Suspend <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>? They will not be able to log in.">Suspend</button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <button type="submit" class="btn btn-sm btn-success">Reactivate</button>
                                    <?php endif; ?>
                                </form>

                                <?php if ($u['role'] === 'patient'): ?>
                                    <form method="POST" action="/HealthConnect/admin/users.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                        <input type="hidden" name="action" value="set_role">
                                        <input type="hidden" name="role" value="clinic_staff">
                                        <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                                        <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" data-confirm="Make this user clinic staff?">Make staff</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($u['role'] === 'clinic_staff'): ?>
                                <form method="POST" action="/HealthConnect/admin/users.php" style="display:inline-flex; gap:0.35rem; margin-top:0.35rem;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="assign_clinic">
                                    <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
                                    <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                    <select name="clinic_id" class="form-control" style="padding:0.3rem;" required>
                                        <option value="">Assign clinic...</option>
                                        <?php foreach ($clinicList as $cl): ?>
                                            <option value="<?= (int) $cl['clinic_id'] ?>" <?= (int) ($u['clinic_id'] ?? 0) === (int) $cl['clinic_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cl['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </form>
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
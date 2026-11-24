<?php
// Connect to the database and start the session.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$error = "";
$success = "";

$fullName = "";
$email = "";
$phoneInput = "";

// Generate a CSRF token to protect the registration form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process the form when the patient submits it.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phoneInput = trim($_POST['phone_number'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify the form token.
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = "Invalid form submission. Please try again.";
    }

    // Check that all required fields are provided.
    elseif (
        $fullName === '' ||
        $email === '' ||
        $phoneInput === '' ||
        $password === '' ||
        $confirmPassword === ''
    ) {
        $error = "Please fill in all the required fields.";
    }

    // Validate the email address.
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    }

    // Normalize and validate Kenyan mobile numbers.
    else {
        $phoneNumber = preg_replace('/[\s-]/', '', $phoneInput);

        if (preg_match('/^0[17]\d{8}$/', $phoneNumber)) {
            $phoneNumber = '+254' . substr($phoneNumber, 1);
        } elseif (preg_match('/^254[17]\d{8}$/', $phoneNumber)) {
            $phoneNumber = '+' . $phoneNumber;
        } elseif (!preg_match('/^\+254[17]\d{8}$/', $phoneNumber)) {
            $error = "Please enter a 10-digit mobile number.";
        }
    }

    // Check password requirements.
    if ($error === "" && (
        strlen($password) < 8 ||
        !preg_match('/[A-Za-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    )) {
        $error = "Password must contain at least 8 characters, including letters and numbers.";
    }

    // Confirm that both passwords match.
    if ($error === "" && $password !== $confirmPassword) {
        $error = "The passwords do not match.";
    }

    // Save the patient account.
    if ($error === "") {

        try {
            // Hash the password before storing it.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, phone_number, email, password_hash, role)
                VALUES (?, ?, ?, ?, 'patient')"
            );

            $stmt->bind_param(
                "ssss",
                $fullName,
                $phoneNumber,
                $email,
                $passwordHash
            );

            $stmt->execute();
            $stmt->close();

            $success = "Registration successful! Your patient account has been created.";

            // Refresh the token after successful submission.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Clear the form values.
            $fullName = "";
            $email = "";
            $phoneInput = "";

        } catch (mysqli_sql_exception $e) {

            if ($e->getCode() === 1062) {
                $error = "This email address or phone number is already registered.";
            } else {
                error_log("Registration error: " . $e->getMessage());
                $error = "Something went wrong. Please try again.";
            }
        }
    }
}

$pageTitle = "Patient Registration";
$activeNav = "register";
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card" style="max-width: 520px; margin: 1.5rem auto;">
    <h1 class="page-title" style="margin-bottom: 0.35rem;">Create Patient Account</h1>
    <p class="page-subtitle" style="margin-bottom: 1.25rem;">
        Register to book and manage your clinic appointments.
    </p>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <a href="login.php" style="font-weight: 600; margin-left: 0.35rem;">Log in now &rarr;</a>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <div class="form-group">
            <label for="full_name">Full Name</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                class="form-control"
                value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-control"
                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="phone_number">Phone Number</label>
            <input
                type="tel"
                id="phone_number"
                name="phone_number"
                class="form-control"
                placeholder="0712345678"
                value="<?= htmlspecialchars($phoneInput, ENT_QUOTES, 'UTF-8') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control"
                minlength="8"
                required
            >
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                class="form-control"
                minlength="8"
                required
            >
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1.25rem;">
            <button type="submit" class="btn btn-primary">Create Account</button>
            <a href="/HealthConnect/index.php" class="btn btn-secondary">&larr; Back to Home</a>
        </div>
    </form>

    <p style="margin-top: 1.25rem; color: var(--text-muted); font-size: 0.95rem;">
        Already have an account? <a href="login.php">Log in</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
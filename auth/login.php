<?php
// Connect to the database and start the session.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$error = "";
$email = "";

// Generate a CSRF token for the login form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process the login form.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Verify the CSRF token.
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = "Invalid form submission. Please try again.";
    }

    // Check that the fields are not empty.
    elseif ($email === '' || $password === '') {
        $error = "Please enter your email and password.";
    }

    // Check whether the email has a valid format.
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    }

    else {

        // Find the user using their email address.
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, email, password_hash, role, is_active
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        $stmt->close();

        // Verify the password and account status.
        if (
            $user &&
            password_verify($password, $user['password_hash']) &&
            (int) $user['is_active'] === 1
        ) {

            // Generate a new session ID after successful login.
            session_regenerate_id(true);

            // Store the authenticated user's information.
            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // Redirect users according to their roles.
            if ($user['role'] === 'patient') {
                header("Location: ../patient/dashboard.php");
            } elseif ($user['role'] === 'clinic_staff') {
                header("Location: ../staff/dashboard.php");
            } elseif ($user['role'] === 'admin') {
                header("Location: ../admin/dashboard.php");
            } else {
                $error = "Your account role is not recognized.";
            }

            exit;

        } else {
            $error = "Incorrect email or password, or your account is inactive.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | HealthConnect</title>
</head>

<body>

    <h1>HealthConnect</h1>
    <h2>Login to Your Account</h2>

    <p>Welcome back! Please enter your login details.</p>

    <?php if ($error !== ""): ?>
        <p style="color: red;">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <label for="email">Email Address</label><br>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            required
        ><br><br>

        <label for="password">Password</label><br>
        <input
            type="password"
            id="password"
            name="password"
            required
        ><br><br>

        <button type="submit">Login</button>

    </form>

    <p>Don't have an account?
        <a href="register.php">Register here</a>
    </p>

</body>
</html>

<?php
// Creates (or resets) an administrator account. Run once from the command line:
//   php database/create_admin.php "Full Name" admin@example.com 0712345678 "StrongPassword1"

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.");
}

require_once __DIR__ . '/../config/database.php';

if ($argc < 5) {
    exit("Usage: php database/create_admin.php \"Full Name\" email phone password\n");
}

[, $name, $email, $phone, $password] = $argv;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid email address.\n");
}
if (strlen($password) < 8) {
    exit("Password must be at least 8 characters.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (full_name, phone_number, email, password_hash, role)
     VALUES (?, ?, ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', is_active = 1"
);
$stmt->bind_param("ssss", $name, $phone, $email, $hash);
$stmt->execute();

echo "Admin account ready: $email\n";

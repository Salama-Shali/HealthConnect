
<?php
// HealthConnect database connection

$host = "localhost";
$username = "root";
$password = "";
$database = "healthconnect_db";
$port = 3306;

// Establish connection to MySQL
try {
    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    // Set character encoding
    $conn->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please check the configuration.");
}
?>

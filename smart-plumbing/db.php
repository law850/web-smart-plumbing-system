<?php
/**
 * db.php — Database connection
 * Every PHP file in this project includes this file first.
 */

// Start a session so we can remember who is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tell the browser we always return JSON (except for HTML pages)
header("Content-Type: application/json; charset=utf-8");

// ---------- Load .env file ----------
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

// ---------- Database credentials (from .env) ----------
$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'smart_plumbing';
$DB_USER = $_ENV['DB_USER'] ?? 'root';
$DB_PASS = $_ENV['DB_PASS'] ?? '';

// ---------- Connect via PDO ----------
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}

/**
 * respond() — Standard JSON response helper.
 * Every API endpoint calls this to return data + stop execution.
 */
function respond(array $data): void {
    echo json_encode($data);
    exit;
}

/**
 * require_login() — Blocks access if the user isn't logged in.
 * Optional $role parameter locks the endpoint to a specific role.
 */
function require_login(?string $role = null): void {
    if (!isset($_SESSION['user_id'])) {
        respond(["success" => false, "message" => "Not logged in"]);
    }
    if ($role !== null && ($_SESSION['role'] ?? '') !== $role) {
        respond(["success" => false, "message" => "Access denied — $role only"]);
    }
}
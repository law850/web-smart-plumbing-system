<?php
/**
 * auth.php — Register, Login, Logout, Session check, Password reset
 *
 * Endpoints:
 *   POST /auth.php?action=register        → create a new normal user
 *   POST /auth.php?action=login           → log in (any role)
 *   POST /auth.php?action=logout          → destroy session
 *   GET  /auth.php?action=me              → get current logged-in user
 *   POST /auth.php?action=check_email     → verify email is registered
 *   POST /auth.php?action=reset_password  → update password
 */

require_once 'db.php';

$action = $_GET['action'] ?? '';

/* =====================================================
   REGISTER — only regular users can self-register
   ===================================================== */
if ($action === 'register') {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pass  = $_POST['password'] ?? '';

    // -------- Validation --------
    if ($name === '' || $email === '' || $phone === '' || $pass === '') {
        respond(["success" => false, "message" => "All fields are required"]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(["success" => false, "message" => "Invalid email address"]);
    }
    if (strlen($pass) < 6) {
        respond(["success" => false, "message" => "Password must be at least 6 characters"]);
    }
    if (!preg_match('/^[0-9]{10,13}$/', $phone)) {
        respond(["success" => false, "message" => "Phone must be 10–13 digits"]);
    }

    // -------- Check for duplicate email --------
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        respond(["success" => false, "message" => "Email already registered"]);
    }

    // -------- Insert (role is HARDCODED as 'user') --------
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (name, email, phone, password_hash, role)
         VALUES (?, ?, ?, ?, 'user')"
    );
    $stmt->execute([$name, $email, $phone, $hash]);

    respond([
        "success" => true,
        "message" => "Registration successful. You can now log in.",
        "user_id" => $pdo->lastInsertId()
    ]);
}

/* =====================================================
   LOGIN — works for user, plumber, and admin
   ===================================================== */
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        respond(["success" => false, "message" => "Email and password required"]);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        respond(["success" => false, "message" => "Invalid email or password"]);
    }

    // -------- Save session --------
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];

    // -------- If plumber, attach plumber_id --------
    $plumber_id = null;
    if ($user['role'] === 'plumber') {
        $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
        $p->execute([$user['id']]);
        $plumber_id = $p->fetchColumn() ?: null;
        $_SESSION['plumber_id'] = $plumber_id;
    }

    respond([
        "success"    => true,
        "message"    => "Login successful",
        "user_id"    => (int)$user['id'],
        "name"       => $user['name'],
        "email"      => $user['email'],
        "role"       => $user['role'],
        "plumber_id" => $plumber_id
    ]);
}

/* =====================================================
   LOGOUT
   ===================================================== */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    respond(["success" => true, "message" => "Logged out"]);
}

/* =====================================================
   ME — returns current session user (for dashboard load)
   ===================================================== */
if ($action === 'me') {
    if (!isset($_SESSION['user_id'])) {
        respond(["success" => false, "message" => "Not logged in"]);
    }
    respond([
        "success"    => true,
        "user_id"    => $_SESSION['user_id'],
        "name"       => $_SESSION['name'],
        "email"      => $_SESSION['email'] ?? null,
        "role"       => $_SESSION['role'],
        "plumber_id" => $_SESSION['plumber_id'] ?? null
    ]);
}

/* =====================================================
   CHECK EMAIL — used by forgot.html step 1
   ===================================================== */
if ($action === 'check_email') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        respond(["success" => false, "message" => "Email is required"]);
    }

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(["success" => false, "message" => "No account found with that email address"]);
    }

    respond([
        "success" => true,
        "message" => "Account found",
        "name"    => $user['name']
    ]);
}

/* =====================================================
   RESET PASSWORD — used by forgot.html step 2
   ===================================================== */
if ($action === 'reset_password') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($email === '' || $password === '') {
        respond(["success" => false, "message" => "All fields are required"]);
    }
    if (strlen($password) < 6) {
        respond(["success" => false, "message" => "Password must be at least 6 characters"]);
    }
    if ($password !== $confirm) {
        respond(["success" => false, "message" => "Passwords do not match"]);
    }

    // Verify account exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(["success" => false, "message" => "No account found with that email address"]);
    }

    // Update password
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([$hash, $user['id']]);

    respond([
        "success" => true,
        "message" => "Password reset successfully! You can now log in."
    ]);
}

/* =====================================================
   Unknown action
   ===================================================== */
respond(["success" => false, "message" => "Unknown action: $action"]);
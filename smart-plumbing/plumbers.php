<?php
/**
 * plumbers.php — Admin-only API for managing plumber accounts
 *
 * Endpoints:
 *   GET  ?action=list     → list all plumbers
 *   POST ?action=create   → create a plumber (user + plumbers row)
 *   POST ?action=update   → edit a plumber
 *   POST ?action=delete   → delete a plumber (and their user row)
 */

require_once 'db.php';

// -------- Admin-only guard --------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    respond(["success" => false, "message" => "Admin access only"]);
}

$action = $_GET['action'] ?? '';

/* =====================================================
   LIST — all plumbers with their user details
   ===================================================== */
if ($action === 'list') {
    $sql = "SELECT
                p.id,
                p.user_id,
                p.level,
                p.specialization,
                p.availability,
                p.rating_avg,
                p.wallet_balance,
                p.status,
                u.name,
                u.email,
                u.phone,
                u.created_at
            FROM plumbers p
            JOIN users u ON u.id = p.user_id
            ORDER BY p.id DESC";

    $rows = $pdo->query($sql)->fetchAll();
    respond(["success" => true, "plumbers" => $rows]);
}

/* =====================================================
   CREATE — admin adds a new plumber
   ===================================================== */
if ($action === 'create') {
    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $password       = $_POST['password'] ?? 'plumber123';
    $level          = $_POST['level'] ?? 'beginner';
    $specialization = trim($_POST['specialization'] ?? 'general');

    // -------- Validation --------
    if ($name === '' || $email === '' || $phone === '') {
        respond(["success" => false, "message" => "Name, email and phone are required"]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(["success" => false, "message" => "Invalid email address"]);
    }
    if (!in_array($level, ['beginner', 'intermediate', 'expert'], true)) {
        respond(["success" => false, "message" => "Level must be beginner, intermediate or expert"]);
    }
    if (strlen($password) < 6) {
        respond(["success" => false, "message" => "Password must be at least 6 characters"]);
    }

    // -------- Unique email check --------
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        respond(["success" => false, "message" => "Email already registered"]);
    }

    // -------- Insert user + plumber in a transaction --------
    try {
        $pdo->beginTransaction();

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO users (name, email, phone, password_hash, role)
             VALUES (?, ?, ?, ?, 'plumber')"
        );
        $stmt->execute([$name, $email, $phone, $hash]);
        $user_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO plumbers (user_id, level, specialization, availability, status)
             VALUES (?, ?, ?, 1, 'active')"
        );
        $stmt->execute([$user_id, $level, $specialization]);

        $plumber_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        respond([
            "success"          => true,
            "message"          => "Plumber added successfully",
            "plumber_id"       => $plumber_id,
            "user_id"          => $user_id,
            "default_password" => $password
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Failed to add plumber: " . $e->getMessage()]);
    }
}

/* =====================================================
   UPDATE — edit an existing plumber
   ===================================================== */
if ($action === 'update') {
    $id             = (int)($_POST['id'] ?? 0);
    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $level          = $_POST['level'] ?? 'beginner';
    $specialization = trim($_POST['specialization'] ?? 'general');
    $availability   = isset($_POST['availability']) ? (int)$_POST['availability'] : 1;

    if (!$id || $name === '' || $email === '' || $phone === '') {
        respond(["success" => false, "message" => "Missing required fields"]);
    }
    if (!in_array($level, ['beginner', 'intermediate', 'expert'], true)) {
        respond(["success" => false, "message" => "Invalid level"]);
    }

    // Find plumber's user_id
    $stmt = $pdo->prepare("SELECT user_id FROM plumbers WHERE id = ?");
    $stmt->execute([$id]);
    $plumber = $stmt->fetch();
    if (!$plumber) {
        respond(["success" => false, "message" => "Plumber not found"]);
    }
    $user_id = (int)$plumber['user_id'];

    // Ensure email isn't taken by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        respond(["success" => false, "message" => "Email already used by another account"]);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?"
        );
        $stmt->execute([$name, $email, $phone, $user_id]);

        $stmt = $pdo->prepare(
            "UPDATE plumbers
             SET level = ?, specialization = ?, availability = ?
             WHERE id = ?"
        );
        $stmt->execute([$level, $specialization, $availability, $id]);

        $pdo->commit();
        respond(["success" => true, "message" => "Plumber updated successfully"]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Update failed: " . $e->getMessage()]);
    }
}

/* =====================================================
   DELETE — remove plumber + user
   ===================================================== */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        respond(["success" => false, "message" => "Missing plumber ID"]);
    }

    $stmt = $pdo->prepare("SELECT user_id FROM plumbers WHERE id = ?");
    $stmt->execute([$id]);
    $plumber = $stmt->fetch();
    if (!$plumber) {
        respond(["success" => false, "message" => "Plumber not found"]);
    }
    $user_id = (int)$plumber['user_id'];

    try {
        $pdo->beginTransaction();

        // Deleting the user cascades to plumbers (ON DELETE CASCADE)
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

        $pdo->commit();
        respond(["success" => true, "message" => "Plumber deleted"]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Delete failed: " . $e->getMessage()]);
    }
}

respond(["success" => false, "message" => "Unknown action: $action"]);
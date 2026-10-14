<?php
/**
 * jobs.php — Full job lifecycle API
 *
 * Endpoints:
 *   GET  ?action=list        → role-aware list (user / plumber / admin)
 *   POST ?action=create      → user creates a job (AI matches a plumber)
 *   POST ?action=accept      → plumber accepts their assigned job
 *   POST ?action=decline     → plumber declines (re-match to someone else)
 *   POST ?action=complete    → plumber marks done (only if paid)
 *   GET  ?action=one&id=N    → fetch one job
 */

require_once 'db.php';
require_once 'ai_match.php';

if (!isset($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not logged in"]);
}

$action = $_GET['action'] ?? '';
$role   = $_SESSION['role'];
$uid    = (int)$_SESSION['user_id'];

/* =====================================================
   LIST — role-aware
   ===================================================== */
if ($action === 'list') {

    // ---------- USER: their own jobs ----------
    if ($role === 'user') {
        $stmt = $pdo->prepare(
            "SELECT j.id, j.description, j.location, j.ai_category, j.status, j.created_at,
                    p.id AS plumber_id,
                    u.name AS plumber_name,
                    p.level AS plumber_level,
                    u.phone AS plumber_phone,
                    (SELECT COUNT(*) FROM reviews r WHERE r.job_id = j.id) AS reviewed
             FROM jobs j
             LEFT JOIN plumbers p ON j.plumber_id = p.id
             LEFT JOIN users u    ON p.user_id    = u.id
             WHERE j.user_id = ?
             ORDER BY j.created_at DESC"
        );
        $stmt->execute([$uid]);
        respond(["success" => true, "jobs" => $stmt->fetchAll()]);
    }

    // ---------- PLUMBER: jobs assigned to them ----------
    if ($role === 'plumber') {
        $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
        $p->execute([$uid]);
        $plumber_id = $p->fetchColumn();

        if (!$plumber_id) {
            respond(["success" => false, "message" => "Plumber profile not found"]);
        }

        $stmt = $pdo->prepare(
            "SELECT j.id, j.description, j.location, j.ai_category, j.status, j.created_at,
                    u.name AS user_name, u.phone AS user_phone
             FROM jobs j
             JOIN users u ON j.user_id = u.id
             WHERE j.plumber_id = ?
             ORDER BY j.created_at DESC"
        );
        $stmt->execute([$plumber_id]);
        respond(["success" => true, "jobs" => $stmt->fetchAll()]);
    }

    // ---------- ADMIN: all jobs ----------
    if ($role === 'admin') {
        $rows = $pdo->query(
            "SELECT j.id, j.description, j.location, j.ai_category, j.status, j.created_at,
                    u.name  AS user_name,
                    pu.name AS plumber_name
             FROM jobs j
             JOIN users u       ON j.user_id = u.id
             LEFT JOIN plumbers p ON j.plumber_id = p.id
             LEFT JOIN users pu   ON p.user_id = pu.id
             ORDER BY j.created_at DESC"
        )->fetchAll();
        respond(["success" => true, "jobs" => $rows]);
    }
}

/* =====================================================
   CREATE — user submits an issue
   ===================================================== */
if ($action === 'create') {
    if ($role !== 'user') {
        respond(["success" => false, "message" => "Only users can create jobs"]);
    }

    $description = trim($_POST['description'] ?? '');
    $location    = trim($_POST['location'] ?? '');

    if ($location === '') {
        respond(["success" => false, "message" => "Please provide your location"]);
    }
    if (strlen($description) < 10) {
        respond(["success" => false, "message" => "Please describe your issue in more detail (at least 10 characters)"]);
    }

    // AI match
    $match = classify_and_match($pdo, $description);

    if (!$match['plumber_id']) {
        respond(["success" => false, "message" => "No plumber available right now. Please try again later."]);
    }

    // Insert the job
    $stmt = $pdo->prepare(
        "INSERT INTO jobs (user_id, plumber_id, description, location, ai_category, status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->execute([$uid, $match['plumber_id'], $description, $location, $match['category']]);

    respond([
        "success"      => true,
        "message"      => "Request sent to " . $match['plumber_name'],
        "job_id"       => (int)$pdo->lastInsertId(),
        "category"     => $match['category'],
        "plumber_name" => $match['plumber_name']
    ]);
}

/* =====================================================
   ACCEPT — plumber accepts their assigned job
   ===================================================== */
if ($action === 'accept') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    $job_id = (int)($_POST['job_id'] ?? 0);
    if (!$job_id) respond(["success" => false, "message" => "Missing job id"]);

    $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
    $p->execute([$uid]);
    $plumber_id = $p->fetchColumn();

    $stmt = $pdo->prepare(
        "UPDATE jobs SET status = 'accepted'
         WHERE id = ? AND plumber_id = ? AND status = 'pending'"
    );
    $stmt->execute([$job_id, $plumber_id]);

    if ($stmt->rowCount() === 0) {
        respond(["success" => false, "message" => "Job not found or already handled"]);
    }

    respond(["success" => true, "message" => "Job accepted. Waiting for user payment."]);
}

/* =====================================================
   DECLINE — plumber declines; auto re-match
   ===================================================== */
if ($action === 'decline') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    $job_id = (int)($_POST['job_id'] ?? 0);
    if (!$job_id) respond(["success" => false, "message" => "Missing job id"]);

    $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
    $p->execute([$uid]);
    $plumber_id = $p->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND plumber_id = ? AND status = 'pending'");
    $stmt->execute([$job_id, $plumber_id]);
    $job = $stmt->fetch();

    if (!$job) {
        respond(["success" => false, "message" => "Job not found or already handled"]);
    }

    $match = classify_and_match($pdo, $job['description'], $plumber_id);

    if ($match['plumber_id']) {
        $u = $pdo->prepare("UPDATE jobs SET plumber_id = ?, status = 'pending' WHERE id = ?");
        $u->execute([$match['plumber_id'], $job_id]);
        respond(["success" => true, "message" => "Job reassigned to another plumber"]);
    } else {
        $u = $pdo->prepare("UPDATE jobs SET status = 'declined', plumber_id = NULL WHERE id = ?");
        $u->execute([$job_id]);
        respond(["success" => true, "message" => "No other plumber available"]);
    }
}

/* =====================================================
   COMPLETE — plumber marks done (after payment)
   ===================================================== */
if ($action === 'complete') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    $job_id = (int)($_POST['job_id'] ?? 0);
    if (!$job_id) respond(["success" => false, "message" => "Missing job id"]);

    $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
    $p->execute([$uid]);
    $plumber_id = $p->fetchColumn();

    $stmt = $pdo->prepare(
        "UPDATE jobs SET status = 'completed'
         WHERE id = ? AND plumber_id = ? AND status = 'paid'"
    );
    $stmt->execute([$job_id, $plumber_id]);

    if ($stmt->rowCount() === 0) {
        respond(["success" => false, "message" => "Cannot complete — the user hasn't paid yet"]);
    }

    respond(["success" => true, "message" => "Job marked as completed"]);
}

/* =====================================================
   ONE — fetch a single job
   ===================================================== */
if ($action === 'one') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(["success" => false, "message" => "Missing id"]);

    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) respond(["success" => false, "message" => "Job not found"]);
    respond(["success" => true, "job" => $job]);
}

respond(["success" => false, "message" => "Unknown action: $action"]);
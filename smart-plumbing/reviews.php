<?php
/**
 * reviews.php — Ratings & comments
 *
 * Endpoints:
 *   POST ?action=create  → user submits a review for a completed job
 *   GET  ?action=list    → admin lists all reviews
 *   GET  ?action=mine    → plumber sees reviews about themselves
 */

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not logged in"]);
}

$action = $_GET['action'] ?? '';
$role   = $_SESSION['role'];
$uid    = (int)$_SESSION['user_id'];

/* =====================================================
   USER — submit a review for a completed job
   ===================================================== */
if ($action === 'create') {
    if ($role !== 'user') {
        respond(["success" => false, "message" => "Users only"]);
    }

    $job_id  = (int)($_POST['job_id'] ?? 0);
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if (!$job_id || $rating < 1 || $rating > 5) {
        respond(["success" => false, "message" => "Invalid rating"]);
    }

    // Job must belong to this user AND be completed
    $j = $pdo->prepare(
        "SELECT * FROM jobs WHERE id = ? AND user_id = ? AND status = 'completed'"
    );
    $j->execute([$job_id, $uid]);
    $job = $j->fetch();

    if (!$job) {
        respond(["success" => false, "message" => "Job not found or not completed"]);
    }
    if (!$job['plumber_id']) {
        respond(["success" => false, "message" => "No plumber to review for this job"]);
    }

    // One review per job
    $check = $pdo->prepare("SELECT id FROM reviews WHERE job_id = ?");
    $check->execute([$job_id]);
    if ($check->fetch()) {
        respond(["success" => false, "message" => "You already reviewed this job"]);
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO reviews (job_id, user_id, plumber_id, rating, comment)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$job_id, $uid, $job['plumber_id'], $rating, $comment]);

        // Recompute plumber's average rating
        $avg = $pdo->prepare("SELECT AVG(rating) FROM reviews WHERE plumber_id = ?");
        $avg->execute([$job['plumber_id']]);
        $newAvg = round((float)$avg->fetchColumn(), 2);

        $pdo->prepare("UPDATE plumbers SET rating_avg = ? WHERE id = ?")
            ->execute([$newAvg, $job['plumber_id']]);

        $pdo->commit();

        // =====================================================
        // FETCH FULL RECEIPT DATA
        // =====================================================
        $receiptStmt = $pdo->prepare(
            "SELECT
                j.id             AS job_id,
                j.description    AS description,
                j.location       AS location,
                j.ai_category    AS ai_category,
                j.created_at     AS created_at,
                u.name           AS customer_name,
                u.email          AS customer_email,
                u.phone          AS customer_phone,
                pu.name          AS plumber_name,
                pu.email         AS plumber_email,
                p.level          AS plumber_level,
                p.specialization AS plumber_specialization,
                pay.mpesa_receipt AS mpesa_receipt,
                pay.amount        AS amount,
                pay.plumber_share AS plumber_share,
                pay.admin_share   AS admin_share,
                pay.status        AS payment_status,
                pay.created_at    AS paid_at
             FROM jobs j
             JOIN users u        ON j.user_id = u.id
             JOIN plumbers p     ON j.plumber_id = p.id
             JOIN users pu       ON p.user_id = pu.id
             LEFT JOIN payments pay ON pay.job_id = j.id AND pay.status = 'success'
             WHERE j.id = ?
             ORDER BY pay.id DESC
             LIMIT 1"
        );
        $receiptStmt->execute([$job_id]);
        $receiptData = $receiptStmt->fetch();

        if ($receiptData) {
            // Add computed fields
            $receiptData['receipt_number'] = 'RCP-' . date('Y') . '-' . str_pad($job_id, 5, '0', STR_PAD_LEFT);
            $receiptData['rating']         = $rating;
            $receiptData['comment']        = $comment;
            $receiptData['new_avg']        = $newAvg;
            $receiptData['reviewed_at']    = date('Y-m-d H:i:s');
        }

        respond([
            "success" => true,
            "message" => "Review submitted — thank you!",
            "new_avg" => $newAvg,
            "receipt" => $receiptData
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Review failed: " . $e->getMessage()]);
    }
}

/* =====================================================
   ADMIN — list all reviews
   ===================================================== */
if ($action === 'list') {
    if ($role !== 'admin') {
        respond(["success" => false, "message" => "Admin only"]);
    }

    $rows = $pdo->query(
        "SELECT
            r.id, r.rating, r.comment, r.created_at,
            u.name  AS user_name,
            pu.name AS plumber_name
         FROM reviews r
         JOIN users u    ON r.user_id    = u.id
         JOIN plumbers p ON r.plumber_id = p.id
         JOIN users pu   ON p.user_id    = pu.id
         ORDER BY r.created_at DESC"
    )->fetchAll();

    respond(["success" => true, "reviews" => $rows]);
}

/* =====================================================
   PLUMBER — own reviews
   ===================================================== */
if ($action === 'mine') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    // Find plumber's plumber_id
    $p = $pdo->prepare("SELECT id FROM plumbers WHERE user_id = ?");
    $p->execute([$uid]);
    $plumber_id = $p->fetchColumn();

    if (!$plumber_id) {
        respond(["success" => false, "message" => "Plumber profile not found"]);
    }

    $stmt = $pdo->prepare(
        "SELECT
            r.id, r.rating, r.comment, r.created_at,
            u.name AS user_name
         FROM reviews r
         JOIN users u ON r.user_id = u.id
         WHERE r.plumber_id = ?
         ORDER BY r.created_at DESC"
    );
    $stmt->execute([$plumber_id]);

    respond(["success" => true, "reviews" => $stmt->fetchAll()]);
}

respond(["success" => false, "message" => "Unknown action: $action"]);
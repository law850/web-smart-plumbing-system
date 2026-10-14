<?php
/**
 * withdrawals.php — Payout management
 *
 * Endpoints:
 *   GET  ?action=list            → admin: list all withdrawals
 *   POST ?action=admin_payout    → admin pays a plumber from their wallet
 *   POST ?action=request         → plumber requests withdrawal (own wallet)
 *   GET  ?action=mine            → plumber: own withdrawal history + balance
 *   POST ?action=update          → admin: approve / reject / mark paid
 */

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Not logged in"]);
}

$action = $_GET['action'] ?? '';
$role   = $_SESSION['role'];

/* =====================================================
   ADMIN — list all withdrawals
   ===================================================== */
if ($action === 'list') {
    if ($role !== 'admin') {
        respond(["success" => false, "message" => "Admin only"]);
    }

    $rows = $pdo->query(
        "SELECT w.id, w.amount, w.status, w.requested_at,
                p.id AS plumber_id,
                u.name AS plumber_name,
                u.phone AS plumber_phone
         FROM withdrawals w
         JOIN plumbers p ON w.plumber_id = p.id
         JOIN users u    ON p.user_id    = u.id
         ORDER BY w.requested_at DESC"
    )->fetchAll();

    respond(["success" => true, "withdrawals" => $rows]);
}

/* =====================================================
   ADMIN — pay a plumber from their wallet
   ===================================================== */
if ($action === 'admin_payout') {
    if ($role !== 'admin') {
        respond(["success" => false, "message" => "Admin only"]);
    }

    $plumber_id = (int)($_POST['plumber_id'] ?? 0);
    $amount     = (float)($_POST['amount'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if (!$plumber_id || $amount <= 0) {
        respond(["success" => false, "message" => "Plumber and amount are required"]);
    }

    // Fetch plumber balance
    $stmt = $pdo->prepare("SELECT id, wallet_balance FROM plumbers WHERE id = ?");
    $stmt->execute([$plumber_id]);
    $plumber = $stmt->fetch();

    if (!$plumber) {
        respond(["success" => false, "message" => "Plumber not found"]);
    }
    if ($amount > (float)$plumber['wallet_balance']) {
        respond(["success" => false, "message" => "Amount exceeds wallet balance"]);
    }

    try {
        $pdo->beginTransaction();

        // Deduct from plumber wallet
        $pdo->prepare(
            "UPDATE plumbers SET wallet_balance = wallet_balance - ? WHERE id = ?"
        )->execute([$amount, $plumber_id]);

        // Record the withdrawal as already paid
        $pdo->prepare(
            "INSERT INTO withdrawals (plumber_id, amount, status)
             VALUES (?, ?, 'paid')"
        )->execute([$plumber_id, $amount]);

        $pdo->commit();

        respond([
            "success" => true,
            "message" => "Paid KSh " . number_format($amount) . " to plumber" . ($note ? " — $note" : "")
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Payout failed: " . $e->getMessage()]);
    }
}

/* =====================================================
   PLUMBER — request withdrawal from own wallet
   ===================================================== */
if ($action === 'request') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    $amount = (float)($_POST['amount'] ?? 0);
    if ($amount <= 0) {
        respond(["success" => false, "message" => "Invalid amount"]);
    }

    // Get this plumber's id + balance
    $stmt = $pdo->prepare("SELECT id, wallet_balance FROM plumbers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $plumber = $stmt->fetch();

    if (!$plumber) {
        respond(["success" => false, "message" => "Plumber profile not found"]);
    }
    if ($amount > (float)$plumber['wallet_balance']) {
        respond(["success" => false, "message" => "Amount exceeds your wallet balance"]);
    }

    $pdo->prepare(
        "INSERT INTO withdrawals (plumber_id, amount, status)
         VALUES (?, ?, 'requested')"
    )->execute([$plumber['id'], $amount]);

    respond(["success" => true, "message" => "Withdrawal requested — waiting for admin approval"]);
}

/* =====================================================
   PLUMBER — own history + balance
   ===================================================== */
if ($action === 'mine') {
    if ($role !== 'plumber') {
        respond(["success" => false, "message" => "Plumbers only"]);
    }

    $stmt = $pdo->prepare("SELECT id, wallet_balance FROM plumbers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $plumber = $stmt->fetch();

    if (!$plumber) {
        respond(["success" => false, "message" => "Plumber profile not found"]);
    }

    $rows = $pdo->prepare(
        "SELECT id, amount, status, requested_at
         FROM withdrawals
         WHERE plumber_id = ?
         ORDER BY requested_at DESC"
    );
    $rows->execute([$plumber['id']]);

    respond([
        "success"     => true,
        "balance"     => (float)$plumber['wallet_balance'],
        "withdrawals" => $rows->fetchAll()
    ]);
}

/* =====================================================
   ADMIN — update status (approve / reject / mark paid)
   ===================================================== */
if ($action === 'update') {
    if ($role !== 'admin') {
        respond(["success" => false, "message" => "Admin only"]);
    }

    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!$id || !in_array($status, ['approved', 'rejected', 'paid'], true)) {
        respond(["success" => false, "message" => "Invalid request"]);
    }

    // Fetch the withdrawal row
    $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
    $stmt->execute([$id]);
    $w = $stmt->fetch();

    if (!$w) {
        respond(["success" => false, "message" => "Withdrawal not found"]);
    }

    try {
        $pdo->beginTransaction();

        // If marking paid, deduct from plumber wallet (only if it wasn't already deducted)
        if ($status === 'paid' && $w['status'] !== 'paid') {
            $pdo->prepare(
                "UPDATE plumbers SET wallet_balance = wallet_balance - ? WHERE id = ?"
            )->execute([$w['amount'], $w['plumber_id']]);
        }

        $pdo->prepare("UPDATE withdrawals SET status = ? WHERE id = ?")
            ->execute([$status, $id]);

        $pdo->commit();
        respond(["success" => true, "message" => "Withdrawal $status"]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Update failed: " . $e->getMessage()]);
    }
}
/* =====================================================
   ADMIN — own wallet: list + withdraw
   ===================================================== */
if ($action === 'admin_list') {
    if ($role !== 'admin') respond(["success" => false, "message" => "Admin only"]);

    $rows = $pdo->query(
        "SELECT id, amount, status, requested_at FROM withdrawals
         WHERE plumber_id IS NULL
         ORDER BY requested_at DESC"
    )->fetchAll();

    respond(["success" => true, "withdrawals" => $rows]);
}

if ($action === 'admin_withdraw') {
    if ($role !== 'admin') respond(["success" => false, "message" => "Admin only"]);

    $amount = (float)($_POST['amount'] ?? 0);
    if ($amount <= 0) respond(["success" => false, "message" => "Invalid amount"]);

    $balance = (float)$pdo->query("SELECT total_earned FROM admin_wallet WHERE id = 1")->fetchColumn();
    if ($amount > $balance) {
        respond(["success" => false, "message" => "Amount exceeds balance (KSh " . number_format($balance, 2) . ")"]);
    }

    try {
        $pdo->beginTransaction();

        // Deduct from admin wallet
        $pdo->prepare("UPDATE admin_wallet SET total_earned = total_earned - ? WHERE id = 1")
            ->execute([$amount]);

        // Record as an admin withdrawal (plumber_id = NULL)
        $pdo->prepare(
            "INSERT INTO withdrawals (plumber_id, amount, status)
             VALUES (NULL, ?, 'paid')"
        )->execute([$amount]);

        $pdo->commit();
        respond(["success" => true, "message" => "Withdrew KSh " . number_format($amount, 2)]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Failed: " . $e->getMessage()]);
    }
}
respond(["success" => false, "message" => "Unknown action: $action"]);
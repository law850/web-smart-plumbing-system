<?php
/**
 * mpesa_callback.php — Receives payment confirmation from Safaricom
 * This endpoint is called by Safaricom after the customer enters their PIN.
 */

require_once 'db.php';

// ---------- Load .env ----------
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

// ---------- Log raw callback for debugging ----------
$raw = file_get_contents('php://input');
@file_put_contents(__DIR__ . '/mpesa_log.txt',
    date('Y-m-d H:i:s') . " | " . $raw . "\n",
    FILE_APPEND);

// ---------- Parse the callback ----------
$data = json_decode($raw, true);

$resultCode = $data['Body']['stkCallback']['ResultCode']      ?? null;
$checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
$receipt    = $data['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'] ?? null;

// ---------- If payment succeeded ----------
if ($resultCode === 0 && $checkoutId) {

    // Find the pending payment record
    $stmt = $pdo->prepare(
        "SELECT * FROM payments WHERE mpesa_receipt = ? AND status = 'pending'"
    );
    $stmt->execute([$checkoutId]);
    $payment = $stmt->fetch();

    if ($payment) {
        $job_id = (int)$payment['job_id'];

        try {
            $pdo->beginTransaction();

            // 1. Mark payment successful
            $pdo->prepare(
                "UPDATE payments SET status = 'success', mpesa_receipt = ? WHERE id = ?"
            )->execute([$receipt ?: $checkoutId, $payment['id']]);

            // 2. Mark job as paid
            $pdo->prepare(
                "UPDATE jobs SET status = 'paid' WHERE id = ?"
            )->execute([$job_id]);

            // 3. Get plumber id
            $j = $pdo->prepare("SELECT plumber_id FROM jobs WHERE id = ?");
            $j->execute([$job_id]);
            $plumber_id = $j->fetchColumn();

            // 4. Credit plumber wallet
            if ($plumber_id) {
                $pdo->prepare(
                    "UPDATE plumbers SET wallet_balance = wallet_balance + 350 WHERE id = ?"
                )->execute([$plumber_id]);
            }

            // 5. Credit admin wallet
            $pdo->exec(
                "UPDATE admin_wallet SET total_earned = total_earned + 650 WHERE id = 1"
            );

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
        }
    }
}

// ---------- Always respond 200 OK ----------
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Accepted"]);
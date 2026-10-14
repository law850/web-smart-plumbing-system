<?php
/**
 * payments.php — M-Pesa STK Push initiator
 *
 * Endpoint:
 *   POST ?action=stkpush → send STK push to user's phone for a specific job
 *
 * After Safaricom processes the payment, it calls mpesa_callback.php
 */

require_once 'db.php';
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

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    respond(["success" => false, "message" => "Users only"]);
}

$action = $_GET['action'] ?? '';
$uid    = (int)$_SESSION['user_id'];

/* =====================================================
   STK PUSH — send payment request to phone
   ===================================================== */
if ($action === 'stkpush') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    $phone  = trim($_POST['phone'] ?? '');

    if (!$job_id || $phone === '') {
        respond(["success" => false, "message" => "Job ID and phone are required"]);
    }

    // Normalize phone: 07XXXXXXXX → 2547XXXXXXXX
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = '254' . substr($phone, 1);
    } elseif (strlen($phone) === 9) {
        $phone = '254' . $phone;
    } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
        // already correct
    } else {
        respond(["success" => false, "message" => "Invalid phone number"]);
    }

    // Verify the job belongs to this user and is in 'accepted' state
    $stmt = $pdo->prepare(
        "SELECT * FROM jobs WHERE id = ? AND user_id = ? AND status = 'accepted'"
    );
    $stmt->execute([$job_id, $uid]);
    $job = $stmt->fetch();

    if (!$job) {
        respond(["success" => false, "message" => "Job not found or not ready for payment"]);
    }

    // ---------- Daraja credentials ----------
    // ⚠️ REPLACE WITH YOUR OWN FROM developer.safaricom.co.ke
       // ---------- Daraja credentials (loaded from .env) ----------
    $consumerKey    = $_ENV['MPESA_CONSUMER_KEY']    ?? '';
    $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? '';
    $shortcode      = $_ENV['MPESA_SHORTCODE']       ?? '174379';
    $passkey        = $_ENV['MPESA_PASSKEY']         ?? '';
    $callbackUrl    = $_ENV['MPESA_CALLBACK_URL']    ?? '';
    $amount         = 10;

    // ---------- 1. Get OAuth access token ----------
    $ch = curl_init("https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$consumerKey:$consumerSecret",
        CURLOPT_HTTPHEADER     => ["Accept: application/json"],
        CURLOPT_TIMEOUT        => 20
    ]);
    $tokenResp = curl_exec($ch);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        respond(["success" => false, "message" => "M-Pesa auth failed: $curlErr"]);
    }

    $tokenData = json_decode($tokenResp, true);
    $accessToken = $tokenData['access_token'] ?? null;

    if (!$accessToken) {
        respond([
            "success" => false,
            "message" => "Could not authenticate with M-Pesa. Check your Daraja credentials.",
            "debug"   => $tokenResp
        ]);
    }

    // ---------- 2. Build and send STK push ----------
    $timestamp = date("YmdHis");
    $password  = base64_encode($shortcode . $passkey . $timestamp);

    $payload = [
        "BusinessShortCode" => $shortcode,
        "Password"          => $password,
        "Timestamp"         => $timestamp,
        "TransactionType"   => "CustomerPayBillOnline",
        "Amount"            => $amount,
        "PartyA"            => $phone,
        "PartyB"            => $shortcode,
        "PhoneNumber"       => $phone,
        "CallBackURL"       => $callbackUrl,
        "AccountReference"  => "JOB-" . $job_id,
        "TransactionDesc"   => "Plumbing service payment"
    ];

    $ch = curl_init("https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT        => 30
    ]);
    $resp    = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        respond(["success" => false, "message" => "STK push failed: $curlErr"]);
    }

    $result = json_decode($resp, true);
    
    if (!isset($result['CheckoutRequestID'])) {
        respond([
            "success" => false,
            "message" => $result['errorMessage'] ?? "STK push rejected by Safaricom",
            "debug"   => $result
        ]);
    }

    // ---------- 3. Save pending payment ----------
    $pdo->prepare(
        "INSERT INTO payments (job_id, mpesa_receipt, amount, plumber_share, admin_share, status)
         VALUES (?, ?, 1000.00, 350.00, 650.00, 'pending')"
    )->execute([$job_id, $result['CheckoutRequestID']]);

    respond([
        "success"         => true,
        "message"         => "STK push sent — enter your M-Pesa PIN on your phone",
        "checkout_id"     => $result['CheckoutRequestID'],
        "merchant_request_id" => $result['MerchantRequestID'] ?? null
    ]);
}
/* =====================================================
   SIMULATE — demo-only: mark job as paid + split money
   ===================================================== */
if ($action === 'simulate') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    if (!$job_id) respond(["success" => false, "message" => "Job ID required"]);

    // Job must belong to this user and be accepted
    $stmt = $pdo->prepare(
        "SELECT * FROM jobs WHERE id = ? AND user_id = ? AND status = 'accepted'"
    );
    $stmt->execute([$job_id, $uid]);
    $job = $stmt->fetch();
    if (!$job) respond(["success" => false, "message" => "Job not found or already paid"]);

    try {
        $pdo->beginTransaction();

        // Mark job as paid
        $pdo->prepare("UPDATE jobs SET status = 'paid' WHERE id = ?")->execute([$job_id]);

        // Insert payment record
        $receipt = 'SIM-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare(
            "INSERT INTO payments (job_id, mpesa_receipt, amount, plumber_share, admin_share, status)
             VALUES (?, ?, 1000.00, 350.00, 650.00, 'success')"
        )->execute([$job_id, $receipt]);

        // Credit plumber wallet KSh 3.50
        if ($job['plumber_id']) {
            $pdo->prepare("UPDATE plumbers SET wallet_balance = wallet_balance + 350.00 WHERE id = ?")
                ->execute([$job['plumber_id']]);
        }

        // Credit admin wallet KSh 6.50
        $pdo->exec("UPDATE admin_wallet SET total_earned = total_earned + 650.00 WHERE id = 1");

        $pdo->commit();

        respond([
            "success" => true,
            "message" => "Payment recorded — KSh 1000 split 35% / 65%",
            "receipt" => $receipt
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond(["success" => false, "message" => "Simulation failed: " . $e->getMessage()]);
    }
}
respond(["success" => false, "message" => "Unknown action: $action"]);
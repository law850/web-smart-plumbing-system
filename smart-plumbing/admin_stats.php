<?php
/**
 * admin_stats.php — Dashboard numbers + all users list
 */

require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    respond(["success" => false, "message" => "Admin access only"]);
}

$action = $_GET['action'] ?? 'stats';

/* ---------- STATS ---------- */
if ($action === 'stats') {
    $totalUsers      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
    $totalPlumbers   = (int)$pdo->query("SELECT COUNT(*) FROM plumbers")->fetchColumn();
    $totalJobs       = (int)$pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
    $completedJobs   = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'completed'")->fetchColumn();
    $totalRevenue    = (float)$pdo->query("SELECT total_earned FROM admin_wallet WHERE id = 1")->fetchColumn();
    $pendingWithdraw = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'requested'")->fetchColumn();

    respond([
        "success" => true,
        "stats" => [
            "users"               => $totalUsers,
            "plumbers"            => $totalPlumbers,
            "jobs"                => $totalJobs,
            "completed_jobs"      => $completedJobs,
            "revenue"             => $totalRevenue,
            "pending_withdrawals" => $pendingWithdraw
        ]
    ]);
}

/* ---------- USERS ---------- */
if ($action === 'users') {
    $rows = $pdo->query(
        "SELECT id, name, email, phone, role, created_at FROM users ORDER BY id DESC"
    )->fetchAll();
    respond(["success" => true, "users" => $rows]);
}

respond(["success" => false, "message" => "Unknown action: $action"]);
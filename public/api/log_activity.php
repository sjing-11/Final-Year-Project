<?php
// public/api/log_activity.php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

/* --- Load dependencies --- */
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php';
require_once $root . '/app/Auth.php';
require_once $root . '/app/ActivityLogger.php'; 

/* --- Authorization Check --- */
// Ensures only logged-in staff can send activity logs
Auth::check_staff();

/* --- Get JSON Input --- */
$data = json_decode(file_get_contents('php://input'), true);
$action_type = $data['action_type'] ?? null;
$module = $data['module'] ?? null;
$description = $data['description'] ?? null;

if (!$action_type || !$module || !$description) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

/* --- Use the ActivityLogger class --- */
try {
    // This logs user_id, session_id, ip_address, and inserts into DB
    ActivityLogger::log($pdo, $action_type, $module, $description);

    echo json_encode(['status' => 'success', 'message' => 'Activity logged']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to log activity: ' . $e->getMessage()]);
}
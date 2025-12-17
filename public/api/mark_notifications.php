<?php
// public/api/mark_notifications.php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

/* --- Load DB and Auth --- */
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php';
require_once $root . '/app/Auth.php';

// Get current user
$u = Auth::user();
if (!$u) {
    http_response_code(401); // Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
$current_user_id = $u['user_id'] ?? 0;
if ($current_user_id === 0) {
    http_response_code(401); // Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']);
    exit;
}

$action = $_GET['action'] ?? null;
$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    if ($action === 'mark_all_read') {
        // Action 1: Mark all as read for this user 
        $sql = "UPDATE notification SET is_read = 1 WHERE user_id = :id AND is_read = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $current_user_id]);
        $response = ['status' => 'success', 'message' => 'All marked as read'];

    } elseif ($action === 'mark_read') {
        // Action 2: Mark a single notification as read 
        $notif_id = (int)($_GET['id'] ?? 0);
        if ($notif_id > 0) {
            $sql = "UPDATE notification SET is_read = 1 
                    WHERE notification_id = :notif_id AND user_id = :user_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':notif_id' => $notif_id,
                ':user_id'  => $current_user_id
            ]);
            $response = ['status' => 'success', 'message' => 'Notification marked as read'];
        } else {
            http_response_code(400); // Bad Request
            $response = ['status' => 'error', 'message' => 'Invalid notification ID'];
        }
    } else {
        http_response_code(400); // Bad Request
    }

} catch (Throwable $e) {
    http_response_code(500); // Internal Server Error
    error_log("Mark notification API error: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A database error occurred.'];
}

echo json_encode($response);
<?php
// public/api/update_user.php
declare(strict_types=1);
session_start(); 

/* --- Load dependencies --- */
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php';
require_once $root . '/app/Auth.php'; 
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/InternalNotify.php';
require_once $root . '/app/UserNotify.php'; 
require_once $root . '/app/ActivityLogger.php';

/* --- Get Current User --- */
$currentUser = Auth::user();
if (!$currentUser) {
    header('Location: /index.php?page=login'); // Must be logged in
    exit();
}
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';

/* --- Authorization Check --- */
Auth::check_staff(['manage_users']);

// POST request only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=users&status=error');
    exit();
}

/* --- Get POST data --- */
$user_id = (int)($_POST['user_id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$role = trim($_POST['role'] ?? '');
$status = trim($_POST['status'] ?? '');
$password = $_POST['password'] ?? ''; // New password, optional

// Basic validation
if ($user_id === 0 || empty($username) || empty($email) || empty($role) || empty($status)) {
    header('Location: /index.php?page=user_details&id=' . $user_id . '&status=error');
    exit();
}

try {
    $pdo->beginTransaction();

    $set_clauses = [
        'username = :username', 'email = :email', 'phone = :phone',
        'role = :role', 'status = :status'
    ];
    $params = [
        ':username' => $username, ':email' => $email, ':phone' => $phone,
        ':role' => $role, ':status' => $status, ':user_id' => $user_id
    ];

    // Check for password update
    if (!empty($password)) {
        // Hash password (sha256 to match login)
        $hashed_password = hash('sha256', $password);
        // 'password' column matches schema
        $set_clauses[] = 'password = :password'; 
        $params[':password'] = $hashed_password;
    }

    $sql = "UPDATE user SET " . implode(', ', $set_clauses) . " WHERE user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log this action
    ActivityLogger::log($pdo, 'Update', 'User', "Updated user '$username' (ID: $user_id)");
    
    // Send In-App Notification
    InternalNotify::send(
        $pdo, "User Profile Updated", "User Updated: $username",
        "/index.php?page=user_details&id=$user_id", null, "Admin"
    );
    
    $pdo->commit(); 

    // Send SNS Email (after commit)
    try {
        user_notify_updated(
            (string)$user_id,
            $username,
            $currentUserEmail,
            $currentUserRole
        );
    } catch (Throwable $e) {
        error_log("SNS Notification failed for update_user: " . $e->getMessage());
    }
   
    header('Location: /index.php?page=user_details&id=' . $user_id . '&status=updated');
    exit(); 

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error updating user (ID: $user_id): " . $e->getMessage());
    header('Location: /index.php?page=user_details&id=' . $user_id . '&status=error');
    exit();
}
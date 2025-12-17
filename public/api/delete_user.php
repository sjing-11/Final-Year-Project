<?php
// public/api/delete_user.php
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

/* --- Authorization Check --- */
// Must have 'manage_users' capability
Auth::check_staff(['manage_users']);

/* --- Get Current User --- */
$currentUser = Auth::user();
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';

/* --- Get User ID --- */
$user_id = (int)($_GET['id'] ?? 0);
if ($user_id === 0) {
  header('Location: /index.php?page=users&status=delete_error');
  exit;
}

/* --- Database Transaction --- */
try {
  $pdo->beginTransaction(); 

  // Get username for logging
  $username = 'Unknown';
  $stmt_get = $pdo->prepare("SELECT username FROM user WHERE user_id = :id");
  $stmt_get->execute([':id' => $user_id]);
  $username_result = $stmt_get->fetchColumn();
  if ($username_result) {
      $username = $username_result;
  }

  // Log this action
  ActivityLogger::log($pdo, 'Delete', 'User', "Deleted user '$username' (ID: $user_id)");
  
  // Send In-App Notification
  InternalNotify::send(
      $pdo,
      "User Deleted",
      "User Deleted: $username (ID: $user_id)",
      "/index.php?page=users", 
      null, 
      "Admin" 
  );

  // Delete user
  $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = :id");
  $stmt->execute([':id' => $user_id]);

  $pdo->commit(); 

  // Send SNS Email
  try {
      user_notify_deleted(
          (string)$user_id,
          $username,
          $currentUserEmail,
          $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for delete_user: " . $e->getMessage());
  }
  
  header('Location: /index.php?page=users&status=deleted');
  exit;

} catch (Throwable $ex) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  error_log("Delete User Error: " . $ex->getMessage());
  header('Location: /index.php?page=users&status=delete_error');
  exit;
}
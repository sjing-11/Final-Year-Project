<?php
// public/api/add_user.php
declare(strict_types=1);
ob_start(); // For clean JSON response
header('Content-Type: application/json');
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
if (!$currentUser) {
    jerr('You must be logged in to perform this action.', 401);
}
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';

/* --- Reusable error function --- */
function jerr(string $msg, int $code = 400) {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']->inTransaction()) {
      $GLOBALS['pdo']->rollBack();
  }
  ob_end_clean();
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $msg]);
  exit;
}

// Helper to get trimmed POST data
function post(string $key): ?string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : null;
}

/* --- Get POST data --- */
$username = post('username');
$email    = post('email');
$password = post('password'); 
$phone    = post('phone');
$role     = post('role');
$status   = post('status');

/* --- Server-side validation --- */
$errors = [];
if (!$username) $errors[] = 'Username is required.';
if (!$email) $errors[] = 'Email is required.';
if (!$password) $errors[] = 'Password is required.';
if (!$role) $errors[] = 'Role is required.';
if (!$status) $errors[] = 'Status is required.';
if ($errors) jerr(implode(' ', $errors));

/* --- Database Transaction --- */
try {
  // Check for duplicates
  $dup = $pdo->prepare('SELECT user_id FROM user WHERE email = :email OR username = :username LIMIT 1');
  $dup->execute([':email' => $email, ':username' => $username]);
  if ($dup->fetch()) {
    jerr('Username or email is already in use.', 409); 
  }

  // Hash password (sha256 to match login)
  $password_hash = hash('sha256', $password);

  $pdo->beginTransaction(); 

  // 'password' column matches schema
  $sql = 'INSERT INTO user
      (username, email, password, phone, role, status)
      VALUES
      (:username, :email, :password, :phone, :role, :status)';

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':username'      => $username,
    ':email'         => $email,
    ':password'      => $password_hash, 
    ':phone'         => $phone,
    ':role'          => $role,
    ':status'        => $status,
  ]);

  $id = (int)$pdo->lastInsertId();

  // Send In-App Notification
  InternalNotify::send(
      $pdo,
      "New User Created",
      "A new user '$username' (Role: $role) was created.",
      "/index.php?page=user_details&id=$id",
      null, 
      "Admin" 
  );

  // Log this action
  ActivityLogger::log($pdo, 'Add', 'User', "Created new user '$username' (Role: $role, ID: $id)");

  $pdo->commit(); 

  // Send SNS Email
  try {
      user_notify_created(
          (string)$id,
          $username,
          $currentUserEmail,
          $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for add_user: " . $e->getMessage());
  }

  ob_end_clean();
  echo json_encode([
    'status' => 'success',
    'message' => 'User created successfully.',
    'user_id' => $id,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  ob_end_clean();
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'A database error occurred.',
    'error'   => $e->getMessage(), 
  ]);
}
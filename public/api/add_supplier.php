<?php
// api/add_supplier.php
declare(strict_types=1);
ob_start();
header('Content-Type: application/json');
session_start();

$root = dirname(__DIR__, 2);

// Load DB
$loadedPdo = false;
foreach ([
  $root . '/app/db.php',
  $root . '/config/db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}

// Load Auth & Notify
require_once $root . '/app/Auth.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/SupplierNotify.php'; 
require_once $root . '/app/InternalNotify.php'; 
require_once $root . '/app/ActivityLogger.php';

/* --- Authorization Check --- */
// Must have 'manage_suppliers' capability
Auth::check_staff(['manage_suppliers']);

// Check DB connection
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Database connection not available.']);
  exit;
}

/* --- Get Current User --- */
$currentUser = Auth::user();
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

/* --- Validate inputs --- */
$company_name   = post('company_name');
$contact_person = post('contact_person');
$email          = post('email');
$phone          = post('phone');
$fax            = post('fax');
$country        = post('country');
$state          = post('state');
$city           = post('city');
$postcode       = post('postcode');
$street_address = post('street_address');
$password       = post('password'); 

$errors = [];
if (!$company_name) $errors[] = 'Company name is required.';
if (!$email) $errors[] = 'Email is required.';
if (!$password) $errors[] = 'Password is required.';
// (Rest of your validation...)
if ($errors) jerr(implode(' ', $errors));

/* --- Database Transaction --- */
try {
  // Check for duplicate email
  $dup = $pdo->prepare('SELECT supplier_id FROM supplier WHERE email = :email LIMIT 1');
  $dup->execute([':email' => $email]);
  if ($dup->fetch()) jerr('Email is already used by another supplier.', 409);

  // Hash password
  // Match login_handler.php (sha256)
  $hashed_password = hash('sha256', $password);

  $pdo->beginTransaction(); 

  $sql = 'INSERT INTO supplier
          (company_name, contact_person, email, phone, fax, country, state, city, postcode, street_address, password)
          VALUES
          (:company_name, :contact_person, :email, :phone, :fax, :country, :state, :city, :postcode, :street_address, :password)';

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':company_name'   => $company_name,
    ':contact_person' => $contact_person,
    ':email'          => $email,
    ':phone'          => $phone,
    ':fax'            => $fax,
    ':country'        => $country,
    ':state'          => $state,
    ':city'           => $city,
    ':postcode'       => $postcode,
    ':street_address' => $street_address,
    ':password'       => $hashed_password,
  ]);

  $id = (int)$pdo->lastInsertId();

  // Send In-App Notification
  if ($id > 0) {
      InternalNotify::send(
          $pdo,
          "New Supplier Created",
          "Supplier '$company_name' was created by $currentUsername.",
          "/index.php?page=supplier_details&id=$id",
          null,   
          "Admin" 
      );
  }

  // Log this action
  ActivityLogger::log($pdo, 'Add', 'Supplier', "Created new supplier '$company_name' (ID: $id)");
  
  $pdo->commit(); 

  // Send SNS Email
  if ($id > 0) {
      try {
          supplier_notify_created(
              (string)$id, $company_name, $currentUserEmail, $currentUserRole
          );
      } catch (Throwable $e) {
          error_log("SNS Notification failed for add_supplier: " . $e->getMessage());
      }
  }

  ob_end_clean(); // Clear buffer
  echo json_encode([
    'status' => 'success',
    'message' => 'Supplier created.',
    'supplier_id' => $id,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  ob_end_clean();
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'Insert failed.',
    'error'   => $e->getMessage(),
  ]);
}
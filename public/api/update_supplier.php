<?php
// api/update_supplier.php
declare(strict_types=1);
session_start();

/* --- Load dependencies --- */
$root = dirname(__DIR__, 2); 
$loadedPdo = false;
foreach ([
  $root . '/app/db.php',
  $root . '/config/db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  error_log("Failed to load db.php or \$pdo in api/update_supplier.php");
  header('Location: /index.php?page=suppliers&status=error');
  exit();
}
require_once $root . '/app/Auth.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/SupplierNotify.php'; 
require_once $root . '/app/InternalNotify.php'; 
require_once $root . '/app/ActivityLogger.php';

/* --- Authorization Check --- */
// Must have 'manage_suppliers' capability
Auth::check_staff(['manage_suppliers']);

/* --- Get Current User --- */
$currentUser = Auth::user();
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System'; 

// POST request only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php?page=suppliers&status=error');
    exit();
}

$id = $_POST['supplier_id'] ?? null;
$redirect_url = '/index.php?page=supplier_details&id=' . $id;

try {
  if (!$id) throw new Exception('Missing supplier_id');

  $supplier_name = $_POST['supplier_name'] ?? '';
  $new_password = $_POST['password'] ?? null; 

  $errors = [];
  if (empty($supplier_name)) $errors[] = 'Company name is required';
  // (Rest of your validation...)
  if ($errors) throw new Exception(implode(', ', $errors));
  
  $pdo->beginTransaction(); 

  // Build query dynamically
  $sql_parts = [
    "company_name = :name",
    "contact_person = :person",
    "email = :email",
    "phone = :phone",
    "fax = :fax",
    "street_address = :street",
    "postcode = :postcode",
    "city = :city",
    "state = :state",
    "country = :country"
  ];
  $params = [
    ':name'   => $supplier_name,
    ':person' => $_POST['contact_person'] ?? '',
    ':email'  => $_POST['email'] ?? '',
    ':phone'  => $_POST['phone'] ?? '',
    ':fax'    => $_POST['fax'] ?? '',
    ':street' => $_POST['street_address'] ?? '',
    ':postcode' => $_POST['postcode'] ?? '',
    ':city'   => $_POST['city'] ?? '',
    ':state'  => $_POST['state'] ?? '',
    ':country'=> $_POST['country'] ?? '',
    ':id'     => $id,
  ];
  
  // Check for password update
  if (!empty($new_password)) {
    // Match login_handler.php (sha256)
    $hashed_password = hash('sha256', $new_password);
    $sql_parts[] = "password = :password";
    $params[':password'] = $hashed_password;
  }
  
  $sql = "UPDATE supplier SET " . implode(', ', $sql_parts) . " WHERE supplier_id = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  // Log this action
  ActivityLogger::log($pdo, 'Update', 'Supplier', "Updated supplier '$supplier_name' (ID: $id)");

  // Send In-App Notification
  InternalNotify::send(
      $pdo,
      "Supplier Updated",
      "Supplier '$supplier_name' (ID: $id) was updated by $currentUsername.",
      "/index.php?page=supplier_details&id=$id",
      null,   
      "Admin" 
  );

  $pdo->commit(); 

  // Send SNS Email
  try {
      supplier_notify_updated(
          (string)$id, $supplier_name, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for update_supplier: " . $e->getMessage());
  }
 
  header('Location: ' . $redirect_url . '&status=updated');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  error_log("Error updating supplier (ID: $id): " . $e->getMessage());
  http_response_code(500); 
  header('Location: ' . $redirect_url . '&status=error');
  exit;
}
<?php
// public/api/delete_supplier.php
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
  header('Location: /index.php?page=suppliers&status=delete_error');
  exit;
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

/* --- Get Supplier ID --- */
$supplier_id = (int)($_GET['id'] ?? 0);
if ($supplier_id === 0) {
  header('Location: /index.php?page=suppliers&status=delete_error');
  exit;
}

/* --- Delete Supplier --- */
try {
  $pdo->beginTransaction(); 

  // Get name for logging
  $stmt_get = $pdo->prepare("SELECT company_name FROM supplier WHERE supplier_id = :id");
  $stmt_get->execute([':id' => $supplier_id]);
  $supplierName = $stmt_get->fetchColumn();
  if (!$supplierName) $supplierName = 'Unknown Supplier (ID: ' . $supplier_id . ')';
  
  // Log this action
  ActivityLogger::log($pdo, 'Delete', 'Supplier', "Deleted supplier '$supplierName' (ID: $supplier_id)");
  
  // Send In-App Notification
  InternalNotify::send(
      $pdo,
      "Supplier Deleted",
      "Supplier '$supplierName' (ID: $supplier_id) was deleted by $currentUsername.",
      "/index.php?page=suppliers", // Link back to supplier list
      null,   
      "Admin" 
  );

  // Delete supplier
  $stmt = $pdo->prepare("DELETE FROM supplier WHERE supplier_id = :id");
  $stmt->execute([':id' => $supplier_id]);

  $pdo->commit(); 

  // Send SNS Email
  try {
      supplier_notify_deleted(
          (string)$supplier_id, (string)$supplierName, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for delete_supplier: " . $e->getMessage());
  }

  header('Location: /index.php?page=suppliers&status=deleted');
  exit;

} catch (Throwable $ex) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  error_log("Delete Supplier Error: " . $ex->getMessage());
  header('Location: /index.php?page=suppliers&status=delete_error');
  exit;
}
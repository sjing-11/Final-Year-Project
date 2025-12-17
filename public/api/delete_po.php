<?php
// public/api/delete_po.php
declare(strict_types=1);
session_start(); // Start session to get user data

/* --- Load dependencies --- */
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php';
require_once $root . '/app/Auth.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/PoNotify.php';
require_once $root . '/app/InternalNotify.php';
require_once $root . '/app/ActivityLogger.php'; 

/* --- Authorization Check --- */
// Users must have 'delete_po' capability
Auth::check_staff(['delete_po']);

/* --- Get Current User Info for Notifications --- */
$currentUser = Auth::user(); // Get user data from session
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System'; 

/* --- Get PO ID from the URL --- */
$po_id = (int)($_GET['id'] ?? 0);
if ($po_id === 0) {
  header('Location: /index.php?page=po&status=delete_error');
  exit;
}

/* --- Main Deletion Logic --- */
try {
  // 1. Check the current status of the PO
  $stmt_check = $pdo->prepare("SELECT status FROM purchase_order WHERE po_id = :id");
  $stmt_check->execute([':id' => $po_id]);
  $po = $stmt_check->fetch(PDO::FETCH_ASSOC);

  if (!$po) {
    throw new Exception('Purchase Order not found.');
  }

  // 2. Enforce business rule: Only 'Created' or 'Pending' can be deleted
  if ($po['status'] !== 'Created' && $po['status'] !== 'Pending') {
    header('Location: /index.php?page=po_details&id=' . $po_id . '&status=delete_restricted');
    exit;
  }
  
  $pdo->beginTransaction(); 

  // 3. Delete the PO
  $stmt_delete = $pdo->prepare("DELETE FROM purchase_order WHERE po_id = :id");
  $stmt_delete->execute([':id' => $po_id]);
  
  // 4. Log this action
  ActivityLogger::log($pdo, 'Delete', 'PurchaseOrder', "Deleted Purchase Order #$po_id");

  // 5. Send In-App Notification
  $notif_msg = "PO #$po_id was deleted by $currentUsername.";
  $notif_link = "/index.php?page=po";
  InternalNotify::send($pdo, "PO Deleted", $notif_msg, $notif_link, null, "Admin");

  
  $pdo->commit(); 

  // 6. Send SNS Email Notification
  try {
      po_notify_deleted(
          (string)$po_id,
          $currentUserEmail,
          $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for delete_po: " . $e->getMessage());
  }
  
  // 7. Redirect to list page on success
  header('Location: /index.php?page=po&status=deleted');
  exit;

} catch (Throwable $ex) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  error_log($ex->getMessage());
  header('Location: /index.php?page=po_details&id=' . $po_id . '&status=delete_error');
  exit;
}
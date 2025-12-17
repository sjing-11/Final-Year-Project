<?php
// public/api/add_po.php
declare(strict_types=1);
header('Content-Type: application/json');
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
// Users must have 'create_po' capability
Auth::check_staff(['create_po']);

/* --- Get Current User Info for Notifications --- */
$currentUser = Auth::user(); // Get user data from session
$created_by_user_id = $currentUser['user_id'] ?? 1; 
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';

/* --- Reusable error function --- */
function jerr(string $msg, int $code = 400) {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']->inTransaction()) { // <-- ADDED Rollback
      $GLOBALS['pdo']->rollBack();
  }
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $msg]);
  exit;
}

/* --- Get inputs from form --- */
$supplier_id  = (int)($_POST['supplier_id'] ?? 0);
$expected_date = (string)($_POST['expected_date'] ?? ''); 

// Read 'items_json' and decode it
$items_json = $_POST['items_json'] ?? '[]';
$items = json_decode($items_json, true);

$po_status  = 'Created';
$issue_date = date('Y-m-d'); // Set issue date to today

/* --- Server-side validation --- */
$errors = [];
if ($supplier_id <= 0) {
  $errors[] = 'A valid supplier must be selected.';
}
if (empty($expected_date)) {
  $errors[] = 'Expected Delivery Date is required.';
}

if (!is_array($items) || empty($items)) {
  $errors[] = 'You must add at least one item to the purchase order.';
} else {
  // Validate item details
  foreach ($items as $item_data) {
    $item_id = $item_data['item_id'] ?? 'N/A';
    if (empty($item_data['qty']) || (int)$item_data['qty'] <= 0) {
      $errors[] = "Item ID $item_id has an invalid quantity.";
    }
    if (!isset($item_data['price']) || !is_numeric($item_data['price'])) {
      $errors[] = "Item ID $item_id has an invalid price.";
    }
  }
}

if ($errors) {
  jerr(implode(' ', $errors));
}

/* --- Database Transaction --- */
try {
  $pdo->beginTransaction();

  // 1. Insert the main PO record
  $sql_po = 'INSERT INTO purchase_order
      (created_by_user_id, supplier_id, issue_date, expected_date, status)
      VALUES
      (:user_id, :supplier_id, :issue_date, :expected_date, :status)';
  
  $stmt_po = $pdo->prepare($sql_po);
  $stmt_po->execute([
    ':user_id'      => $created_by_user_id,
    ':supplier_id'  => $supplier_id,
    ':issue_date'   => $issue_date,
    ':expected_date' => $expected_date,
    ':status'       => $po_status
  ]);

  // Get the new PO ID
  $po_id = (int)$pdo->lastInsertId();

  // 2. Line item insert statement
  $sql_pod = 'INSERT INTO purchase_order_details
      (po_id, item_id, quantity, unit_price, purchase_cost)
      VALUES
      (:po_id, :item_id, :quantity, :unit_price, :purchase_cost)';
  
  $stmt_pod = $pdo->prepare($sql_pod);

  // 3. Insert all line items
  foreach ($items as $item_data) {
    $item_id       = (int)$item_data['item_id'];
    $quantity      = (int)$item_data['qty'];
    $unit_price    = (float)$item_data['price'];
    $purchase_cost = $quantity * $unit_price;

    $stmt_pod->execute([
      ':po_id'         => $po_id,
      ':item_id'       => $item_id,
      ':quantity'      => $quantity,
      ':unit_price'    => $unit_price,
      ':purchase_cost' => $purchase_cost
    ]);
  }
  
  // 4. Send In-App Notifications
  $notif_msg = "PO #$po_id was created by $currentUsername.";
  $notif_link = "/index.php?page=po_details&id=$po_id";
  InternalNotify::send($pdo, "New PO Created", $notif_msg, $notif_link, null, "Admin");
  InternalNotify::send($pdo, "New PO Created", $notif_msg, $notif_link, null, "Manager"); 
  
  // 5. Log this action
  ActivityLogger::log($pdo, 'Add', 'PurchaseOrder', "Created new Purchase Order #$po_id");

  // 6. Commit the transaction
  $pdo->commit();
  
  // 7. Send SNS Email Notification 
  try {
      po_notify_created(
          (string)$po_id,
          $currentUserEmail,
          $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for add_po: " . $e->getMessage());
  }

  // 8. Send success response
  echo json_encode([
    'status' => 'success',
    'message' => 'Purchase Order created successfully.',
    'po_id' => $po_id
  ]);

} catch (Throwable $e) {
  // Roll back all database changes on failure
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  // Send a JSON error
  jerr('A database error occurred: '. $e->getMessage(), 500);
}
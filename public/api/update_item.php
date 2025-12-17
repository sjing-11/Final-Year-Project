<?php
// public/api/update_item.php
declare(strict_types=1);
session_start(); // <-- ADDED

/* --- Load PDO from db.php --- */
$root = dirname(__DIR__, 2);
$loadedPdo = false;
foreach ([
  $root . '/db.php',
  $root . '/app/db.php',
  $root . '/config/db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}
// --- ADDED: Load All Notify Files & Auth ---
require_once $root . '/app/Auth.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/InternalNotify.php';
require_once $root . '/app/ItemNotify.php';
require_once $root . '/app/ActivityLogger.php';
// --- END ADDED ---

if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['status' => 'error', 'message' => 'Database connection error.']);
  exit;
}

Auth::check_staff();

// Logged-in staff only (not supplier)
if (!Auth::user() || isset($_SESSION['supplier_id'])) {
  header('Location: /index.php?page=login');
  exit;
}


/* --- ADDED: Get Current User Info --- */
$currentUser = Auth::user();
if (!$currentUser) {
    header('Location: /index.php?page=login'); // Must be logged in
    exit();
}
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';
/* --- END ADDED --- */

/* --- Get POST data --- */
$item_id            = (int)($_POST['item_id'] ?? 0);
$item_name          = trim((string)($_POST['item_name'] ?? ''));
$item_code          = trim((string)($_POST['item_code'] ?? ''));
$brand              = trim((string)($_POST['brand'] ?? ''));
$category_id        = (int)($_POST['category_id'] ?? 0); 
$measurement        = trim((string)($_POST['measurement'] ?? ''));
$uom                = trim((string)($_POST['uom'] ?? ''));
$expiry_date        = (string)($_POST['expiry_date'] ?? null);
$unit_cost          = (float)($_POST['unit_cost'] ?? 0.00);
$selling_price      = (float)($_POST['selling_price'] ?? 0.00);
$threshold_quantity = (int)($_POST['threshold_quantity'] ?? 0);
$supplier_id        = (int)($_POST['supplier_id'] ?? 0);

/* --- Validation --- */
if ($item_id <= 0 || empty($item_name) || empty($item_code) || $category_id <= 0 || $unit_cost < 0 || $selling_price < 0 || $threshold_quantity < 0 || $supplier_id <= 0) {
  header('Location: /index.php?page=item_details&id=' . $item_id . '&status=error');
  exit;
}

// --- ADDED: Flags for SNS notifications ---
$low_stock_alert_triggered = false;
$out_of_stock_alert_triggered = false;
$new_stock = 0; // Will be fetched after update
// --- END ADDED ---

/* --- Update the Item in the database --- */
try {
  $pdo->beginTransaction(); // <-- ADDED

  $sql = "
    UPDATE item
    SET 
      item_name = :item_name,
      item_code = :item_code,
      brand = :brand,
      category_id = :category_id, 
      measurement = :measurement,
      uom = :uom, 
      expiry_date = :expiry_date,
      unit_cost = :unit_cost,
      selling_price = :selling_price,
      threshold_quantity = :threshold_quantity,
      supplier_id = :supplier_id
    WHERE 
      item_id = :item_id
  ";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':item_name'          => $item_name,
    ':item_code'          => $item_code,
    ':brand'              => $brand,
    ':category_id'        => $category_id, 
    ':measurement'        => $measurement,
    ':uom'                => $uom, 
    ':expiry_date'        => empty($expiry_date) ? null : $expiry_date,
    ':unit_cost'          => $unit_cost,
    ':selling_price'      => $selling_price,
    ':threshold_quantity' => $threshold_quantity,
    ':supplier_id'        => $supplier_id,
    ':item_id'            => $item_id
  ]);

  // --- LOG THE ACTIVITY ---
  ActivityLogger::log($pdo, 'Update', 'Item', "Updated item '$item_name' (Code: $item_code, ID: $item_id)");
  // --- END LOG ---

  // --- ADDED: Send IN-APP Notification (inside transaction) ---
  $notif_msg = "Item '$item_name' (Code: $item_code) was updated by $currentUsername.";
  $notif_link = "/index.php?page=item_details&id=$item_id";
  InternalNotify::send($pdo, "Item Updated", $notif_msg, $notif_link, null, "Admin");
  InternalNotify::send($pdo, "Item Updated", $notif_msg, $notif_link, null, "Manager");
  // --- END ADDED ---

  // ===================================================================
  // --- NEW: Check and update stock alerts based on (potentially) new threshold ---
  // ===================================================================
  
  // 1. We need the current stock quantity (it wasn't changed by this form)
  $stmt_check = $pdo->prepare("SELECT stock_quantity FROM item WHERE item_id = :item_id");
  $stmt_check->execute([':item_id' => $item_id]);
  $current_stock = (int)$stmt_check->fetchColumn();
  
  // 2. Assign variables for the logic block
  $new_stock = $current_stock; // For logic compatibility
  $threshold = $threshold_quantity; // Use the new threshold from the form

  // 3. Prepare all alert management statements (copied from adjust_item.php)
  $stmt_resolve_low = $pdo->prepare("UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Low Stock'");
  $stmt_resolve_out = $pdo->prepare("UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Out of Stock'");
  
  $stmt_check_low = $pdo->prepare("SELECT 1 FROM stock_alert WHERE item_id = :item_id AND alert_type = 'Low Stock' AND resolved = 0");
  $stmt_check_out = $pdo->prepare("SELECT 1 FROM stock_alert WHERE item_id = :item_id AND alert_type = 'Out of Stock' AND resolved = 0");
  
  $stmt_create_low = $pdo->prepare("INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Low Stock', 0)");
  $stmt_create_out = $pdo->prepare("INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Out of Stock', 0)");

  // 4. Apply the alert logic
  if ($new_stock > $threshold) {
      // 4a. Stock is ABOVE threshold, RESOLVE all open alerts
      $stmt_resolve_low->execute([':item_id' => $item_id]);
      $stmt_resolve_out->execute([':item_id' => $item_id]);

  } else if ($new_stock == 0) {
      // 4b. Stock is EXACTLY 0 (Out of Stock)
      $stmt_resolve_low->execute([':item_id' => $item_id]); // Resolve 'Low Stock'

      $stmt_check_out->execute([':item_id' => $item_id]);
      if (!$stmt_check_out->fetch()) {
          $stmt_create_out->execute([':item_id' => $item_id]);
          $out_of_stock_alert_triggered = true; // Set flag
          
          // Send IN-APP "Out of Stock" notification
          $notif_msg = "Item $item_code ($item_name) is OUT OF STOCK (Qty: 0).";
          $notif_link = "/index.php?page=item_details&id=$item_id";
          InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg, $notif_link, null, "Admin");
          InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg, $notif_link, null, "Manager");
      }

  } else if ($new_stock > 0 && $new_stock <= $threshold) {
      // 4c. Stock is LOW (but not 0)
      $stmt_resolve_out->execute([':item_id' => $item_id]); // Resolve 'Out of Stock'
      
      $stmt_check_low->execute([':item_id' => $item_id]);
      if (!$stmt_check_low->fetch()) {
          $stmt_create_low->execute([':item_id' => $item_id]);
          $low_stock_alert_triggered = true; // Set flag
          
          // Send IN-APP "Low Stock" notification
          $notif_msg = "Item $item_code ($item_name) is running low (Qty: $new_stock).";
          $notif_link = "/index.php?page=item_details&id=$item_id";
          InternalNotify::send($pdo, "Low Stock Alert", $notif_msg, $notif_link, null, "Admin");
          InternalNotify::send($pdo, "Low Stock Alert", $notif_msg, $notif_link, null, "Manager");
      }
  }
  // --- END NEW LOGIC ---
  // ===================================================================

  $pdo->commit(); // <-- ADDED

  // --- ADDED: SEND SNS EMAIL NOTIFICATION (AFTER COMMIT) ---
  try {
      item_notify_updated(
          (string)$item_id, $item_name, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for update_item: " . $e->getMessage());
  }
  // --- END ADDED ---

  // --- NEW: Send SNS email for any alerts triggered ---
  if ($low_stock_alert_triggered) {
      try {
          item_notify_low_stock(
              (string)$item_id, $item_name, $item_code, $new_stock
          );
      } catch (Throwable $e) {
          error_log("SNS Low Stock Notification failed for update_item: " . $e->getMessage());
      }
  }
  
  if ($out_of_stock_alert_triggered) {
      try {
          item_notify_out_of_stock(
              (string)$item_id, $item_name, $item_code
          );
      } catch (Throwable $e) {
          error_log("SNS Out of Stock Notification failed for update_item: " . $e->getMessage());
      }
  }
  // --- END NEW SNS LOGIC ---

  header('Location: /index.php?page=item_details&id=' . $item_id . '&status=updated');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack(); // <-- ADDED
  error_log("Item Update Error: " . $e->getMessage());
  header('Location: /index.php?page=item_details&id=' . $item_id . '&status=error');
  exit;
}
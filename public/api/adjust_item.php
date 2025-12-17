<?php
// public/api/adjust_item.php
declare(strict_types=1);
session_start(); // <-- ADDED

/* --- Load PDO from db.php and ensure user is logged in for logging --- */
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
  echo json_encode(['status' => 'error', 'message' => 'Database connection error.']);
  exit;
}

Auth::check_staff();

// Staff only (not supplier)
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
$current_user_id = $currentUser['user_id'] ?? 0; // for activity_log
/* --- END ADDED --- */

/* --- Get POST data --- */
$item_id        = (int)($_POST['item_id'] ?? 0);
$adjustment_qty = (int)($_POST['adjustment_qty'] ?? 0);
$reason         = trim((string)($_POST['reason'] ?? ''));

$redirect_url = "/index.php?page=item_details&id=$item_id"; // <-- ADDED redirect var

/* --- Validation --- */
if ($item_id <= 0 || $adjustment_qty === 0 || empty($reason)) {
  header("Location: $redirect_url&status=error"); // <-- MODIFIED redirect
  exit;
}

// --- NEW VALIDATION: Prevent Negative Stock ---
$stmt_curr = $pdo->prepare("SELECT stock_quantity FROM item WHERE item_id = :id");
$stmt_curr->execute([':id' => $item_id]);
$current_stock = (int)$stmt_curr->fetchColumn();

if ($current_stock + $adjustment_qty < 0) {
    header("Location: $redirect_url&status=error&msg=insufficient_stock");
    exit;
}

// --- MODIFIED: Flags for notifications ---
$low_stock_alert_triggered = false; 
$out_of_stock_alert_triggered = false; // <-- NEW FLAG
$item_name = 'Unknown Item';
$item_code = 'N/A';
$new_stock = 0;
// --- END MODIFIED ---

/* --- Process Stock Adjustment --- */
try {
  $pdo->beginTransaction();

  // 1. Update the stock quantity in the 'item' table
  $sql_update_stock = "UPDATE item SET stock_quantity = stock_quantity + :adjustment_qty WHERE item_id = :item_id";
  $stmt_update = $pdo->prepare($sql_update_stock);
  $stmt_update->execute([
    ':adjustment_qty' => $adjustment_qty,
    ':item_id'        => $item_id
  ]);
  
  // 2. Get the item's new quantity, threshold, name, and code
  $stmt_check = $pdo->prepare("SELECT item_name, item_code, stock_quantity, threshold_quantity FROM item WHERE item_id = :item_id");
  $stmt_check->execute([':item_id' => $item_id]);
  $item_status = $stmt_check->fetch(PDO::FETCH_ASSOC);

  if ($item_status) {
    $new_stock = (int)$item_status['stock_quantity'];
    $threshold = (int)$item_status['threshold_quantity'];
    $item_name = (string)$item_status['item_name']; 
    $item_code = (string)$item_status['item_code'];

    // --- NEW LOGIC: Differentiate between Low Stock and Out of Stock ---

    if ($new_stock > $threshold) {
        // 3a. New quantity is ABOVE threshold, RESOLVE all open alerts
        $sql_resolve = "UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type IN ('Low Stock', 'Out of Stock')";
        $pdo->prepare($sql_resolve)->execute([':item_id' => $item_id]);

    } else if ($new_stock == 0) {
        // 3b. New quantity is EXACTLY 0 (Out of Stock)
        $sql_resolve_low = "UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Low Stock'";
        $pdo->prepare($sql_resolve_low)->execute([':item_id' => $item_id]);

        $sql_check_alert = "SELECT 1 FROM stock_alert WHERE item_id = :item_id AND alert_type = 'Out of Stock' AND resolved = 0";
        $stmt_check_alert = $pdo->prepare($sql_check_alert);
        $stmt_check_alert->execute([':item_id' => $item_id]);

        if (!$stmt_check_alert->fetch()) {
            $sql_create_alert = "INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Out of Stock', 0)";
            $pdo->prepare($sql_create_alert)->execute([':item_id' => $item_id]);
            
            $out_of_stock_alert_triggered = true; // <-- SET NEW FLAG
            
            // Send IN-APP "Out of Stock" notification
            $notif_msg = "Item $item_code ($item_name) is now OUT OF STOCK (Qty: 0).";
            $notif_link = "/index.php?page=item_details&id=$item_id";
            InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg, $notif_link, null, "Admin");
            InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg, $notif_link, null, "Manager");
        }

    } else if ($new_stock > 0 && $new_stock <= $threshold) {
        // 3c. New quantity is LOW (but not 0)
        $sql_resolve_out = "UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Out of Stock'";
        $pdo->prepare($sql_resolve_out)->execute([':item_id' => $item_id]);
        
        $sql_check_alert = "SELECT 1 FROM stock_alert WHERE item_id = :item_id AND alert_type = 'Low Stock' AND resolved = 0";
        $stmt_check_alert = $pdo->prepare($sql_check_alert);
        $stmt_check_alert->execute([':item_id' => $item_id]);

        if (!$stmt_check_alert->fetch()) {
            $sql_create_alert = "INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Low Stock', 0)";
            $pdo->prepare($sql_create_alert)->execute([':item_id' => $item_id]);
            
            $low_stock_alert_triggered = true; // <-- SET OLD FLAG
            
            // Send IN-APP "Low Stock" notification
            $notif_msg = "Item $item_code ($item_name) is running low (Qty: $new_stock).";
            $notif_link = "/index.php?page=item_details&id=$item_id";
            InternalNotify::send($pdo, "Low Stock Alert", $notif_msg, $notif_link, null, "Admin");
            InternalNotify::send($pdo, "Low Stock Alert", $notif_msg, $notif_link, null, "Manager");
        }
    }
    // --- END NEW LOGIC ---
  }
  
    // 4. Log the change using ActivityLogger
  $action_type = $adjustment_qty > 0 ? 'Stock Increase' : 'Stock Decrease';
  $log_description = sprintf(
    "Adjusted stock for Item ID %d (%s) by %d units. New Qty: %d. Reason: %s",
    $item_id,
    $item_name,
    $adjustment_qty, // Keep the sign (e.g., -5 or +10)
    $new_stock,
    $reason
  );
  
  ActivityLogger::log($pdo, $action_type, 'Item', $log_description);

  // 5. Send IN-APP Notification for the ADJUSTMENT action
  $notif_msg_adj = "Stock for '$item_name' was adjusted by $adjustment_qty by $currentUsername. Reason: $reason";
  $notif_link_adj = "/index.php?page=item_details&id=$item_id";
  InternalNotify::send($pdo, "Stock Adjusted", $notif_msg_adj, $notif_link_adj, null, "Admin");
  InternalNotify::send($pdo, "Stock Adjusted", $notif_msg_adj, $notif_link_adj, null, "Manager");

  $pdo->commit();

  // 6. SEND SNS EMAIL NOTIFICATION (AFTER COMMIT)
  try {
      item_notify_adjusted(
          (string)$item_id, $item_name, $adjustment_qty, $reason, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for adjust_item: " . $e->getMessage());
  }
  
  // Send "Low Stock" email if triggered
  if ($low_stock_alert_triggered) { 
      try {
          item_notify_low_stock(
              (string)$item_id, $item_name, $item_code, $new_stock
          );
      } catch (Throwable $e) {
          error_log("SNS Low Stock Notification failed for adjust_item: " . $e->getMessage());
      }
  }

  // --- NEW: Send "Out of Stock" email if triggered ---
  if ($out_of_stock_alert_triggered) { 
      try {
          item_notify_out_of_stock(
              (string)$item_id, $item_name, $item_code
          );
      } catch (Throwable $e) {
          error_log("SNS Out of Stock Notification failed for adjust_item: " . $e->getMessage());
      }
  }
  // --- END NEW ---

  header("Location: $redirect_url&status=stock_adjusted");
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("Stock Adjustment Error: " . $e->getMessage());
  header("Location: $redirect_url&status=error");
  exit;
}
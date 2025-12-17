<?php
// public/api/add_item.php
declare(strict_types=1);
ob_start(); // <-- ADDED for clean JSON response
header('Content-Type: application/json');
session_start(); // <-- ADDED

// --- Load PDO from db.php (same search order as list.php) ---
$root = dirname(__DIR__, 2); // <-- MODIFIED path logic
$loadedPdo = false;
foreach ([
  $root . '/db.php', // <-- MODIFIED path logic
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
require_once $root . '/app/Auth.php';
if (!isset($_SESSION)) { session_start(); }
Auth::check_staff();

// --- END ADDED ---

if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Database connection not available.']);
  exit;
}

/* --- ADDED: Get Current User Info --- */
$currentUser = Auth::user();
if (!$currentUser) {
    jerr('You must be logged in to perform this action.', 401);
}
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System';
/* --- END ADDED --- */


// --- small helpers ---
function jerr(string $msg, int $code = 400) {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']->inTransaction()) { // <-- ADDED Rollback
      $GLOBALS['pdo']->rollBack();
  }
  ob_end_clean(); // <-- ADDED
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $msg]);
  exit;
}
function post(string $key): ?string {
  $value = filter_input(INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? (isset($_POST[$key]) ? $_POST[$key] : null);
  return $value !== null ? trim((string)$value) : null;
}
function post_float(string $key): ?float {
    $val = post($key);
    return is_numeric($val) ? (float)$val : null;
}
function post_int(string $key): ?int {
    $val = post($key);
    return is_numeric($val) ? (int)$val : null;
}

// --- validate inputs ---
$item_name           = post('item_name');
$item_code           = post('item_code');
$category_id         = post_int('category_id'); 
$brand               = post('brand');
$measurement         = post('measurement');
$uom                 = post('uom'); 
$expiry_date         = post('expiry_date');
$unit_cost           = post_float('unit_cost');
$selling_price       = post_float('selling_price');
$stock_quantity      = post_int('stock_quantity');
$threshold_quantity  = post_int('threshold_quantity');
$supplier_id         = post_int('supplier_id');


// --- SERVER-SIDE VALIDATION ---
$errors = [];
if (!$item_name)   $errors[] = 'Product Name is required.';
if (!$item_code)   $errors[] = 'Product Code is required.';
if ($category_id === null || $category_id <= 0) $errors[] = 'Category is required.'; 
if (!$measurement) $errors[] = 'Measurement is required.'; 
if (!$uom)         $errors[] = 'UOM is required.';
if ($unit_cost === null || $unit_cost < 0) $errors[] = 'Buying Price must be a valid positive number.';
if ($selling_price === null || $selling_price < 0) $errors[] = 'Selling Price must be a valid positive number.';
if ($stock_quantity === null || $stock_quantity < 0) $errors[] = 'Quantity must be a valid non-negative integer.';
if ($threshold_quantity === null || $threshold_quantity < 0) $errors[] = 'Threshold Quantity must be a valid non-negative integer.';
if ($supplier_id === null || $supplier_id <= 0) $errors[] = 'Supplier is required.';

if ($errors) {
  jerr(implode(' ', $errors)); 
}
// --- END VALIDATION ---


// OPTIONAL: quick duplicate check on item_code
try {
  $dup = $pdo->prepare('SELECT item_id FROM item WHERE item_code = :code LIMIT 1');
  $dup->execute([':code' => $item_code]);
  if ($dup->fetch()) jerr('Item Code is already in use.', 409);
} catch (Throwable $e) {
  // not fatal — continue
}

// --- ADDED: Flags for notifications ---
$low_stock_alert_triggered = false;
$out_of_stock_alert_triggered = false;
// --- END ADDED ---

// --- insert ---
try {
  $pdo->beginTransaction(); 

  $sql = 'INSERT INTO item
          (item_code, item_name, category_id, brand, measurement, uom, unit_cost, selling_price, 
           stock_quantity, threshold_quantity, expiry_date, supplier_id)
          VALUES
          (:item_code, :item_name, :category_id, :brand, :measurement, :uom, :unit_cost, :selling_price, 
           :stock_quantity, :threshold_quantity, :expiry_date, :supplier_id)';

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':item_code'          => $item_code,
    ':item_name'          => $item_name,
    ':category_id'        => $category_id, 
    ':brand'              => $brand,
    ':measurement'        => $measurement, 
    ':uom'                => $uom,
    ':unit_cost'          => $unit_cost,
    ':selling_price'      => $selling_price,
    ':stock_quantity'     => $stock_quantity,
    ':threshold_quantity' => $threshold_quantity,
    ':expiry_date'        => empty($expiry_date) ? NULL : $expiry_date,
    ':supplier_id'        => $supplier_id,
  ]);

  $id = (int)$pdo->lastInsertId();

  // --- LOG THE ACTIVITY ---
  ActivityLogger::log($pdo, 'Add', 'Item', "Created new item '$item_name' (Code: $item_code, ID: $id)");
  // --- END LOG ---

  // 1. Send "New Item Created" notification (always)
  $notif_msg = "Item '$item_name' (Code: $item_code) was created by $currentUsername.";
  $notif_link = "/index.php?page=item_details&id=$id";
  InternalNotify::send($pdo, "New Item Created", $notif_msg, $notif_link, null, "Admin");
  InternalNotify::send($pdo, "New Item Created", $notif_msg, $notif_link, null, "Manager");


  // --- NEW: Check stock level and send a second notification if needed ---
  if ($stock_quantity == 0) {
      // 2a. Send "Out of Stock" notification
      $sql_create_alert = "INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Out of Stock', 0)";
      $pdo->prepare($sql_create_alert)->execute([':item_id' => $id]);
      
      $out_of_stock_alert_triggered = true; // Set flag for email
      
      $notif_msg_stock = "Item $item_code ($item_name) was created but is OUT OF STOCK (Qty: 0).";
      InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg_stock, $notif_link, null, "Admin");
      InternalNotify::send($pdo, "Out of Stock Alert", $notif_msg_stock, $notif_link, null, "Manager");

  } else if ($stock_quantity > 0 && $stock_quantity <= $threshold_quantity) {
      // 2b. Send "Low Stock" notification
      $sql_create_alert = "INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Low Stock', 0)";
      $pdo->prepare($sql_create_alert)->execute([':item_id' => $id]);
      
      $low_stock_alert_triggered = true; // Set flag for email
      
      $notif_msg_stock = "Item $item_code ($item_name) was created with LOW STOCK (Qty: $stock_quantity).";
      InternalNotify::send($pdo, "Low Stock Alert", $notif_msg_stock, $notif_link, null, "Admin");
      InternalNotify::send($pdo, "Low Stock Alert", $notif_msg_stock, $notif_link, null, "Manager");
  }
  // --- END NEW ---

  $pdo->commit(); 

  // --- SEND SNS EMAIL NOTIFICATION (AFTER COMMIT) ---
  
  // 1. Send "New Item Created" email (always)
  try {
      item_notify_created(
          (string)$id, $item_name, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for add_item: " . $e->getMessage());
  }

  // 2a. Send "Low Stock" email if triggered
  if ($low_stock_alert_triggered) { 
      try {
          item_notify_low_stock(
              (string)$id, $item_name, $item_code, $stock_quantity
          );
      } catch (Throwable $e) {
          error_log("SNS Low Stock Notification failed for add_item: " . $e->getMessage());
      }
  }

  // 2b. Send "Out of Stock" email if triggered
  if ($out_of_stock_alert_triggered) { 
      try {
          item_notify_out_of_stock(
              (string)$id, $item_name, $item_code
          );
      } catch (Throwable $e) {
          error_log("SNS Out of Stock Notification failed for add_item: " . $e->getMessage());
      }
  }
  // --- END SNS ---

  ob_end_clean(); 
  echo json_encode([
    'status' => 'success',
    'message' => 'Product created.',
    'item_id' => $id,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack(); 
  ob_end_clean(); 
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'Insert failed. Check database logs.',
    'error'   => $e->getMessage(), 
  ]);
}
?>
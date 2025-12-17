<?php
// public/api/delete_item.php
declare(strict_types=1);
session_start(); // <-- ADDED

/* --- Load PDO from db.php --- */
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
// --- END ADDED ---

if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  header('Location: /index.php?page=items&status=delete_error');
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


/* --- Get Item ID --- */
$item_id = (int)($_GET['id'] ?? 0);
if ($item_id === 0) {
  header('Location: /index.php?page=items&status=delete_error');
  exit;
}

/* --- Check for dependencies before deleting --- */
try {
    $stmt_pod = $pdo->prepare("SELECT 1 FROM purchase_order_details WHERE item_id = :id LIMIT 1");
    $stmt_pod->execute([':id' => $item_id]);
    if ($stmt_pod->fetch()) {
        header('Location: /index.php?page=items&status=delete_restricted_po');
        exit;
    }
    $stmt_grd = $pdo->prepare("SELECT 1 FROM goods_receipt_details WHERE item_id = :id LIMIT 1");
    $stmt_grd->execute([':id' => $item_id]);
    if ($stmt_grd->fetch()) {
        header('Location: /index.php?page=items&status=delete_restricted_gr');
        exit;
    }
} catch (Throwable $e) {
    error_log("Item delete dependency check error: " . $e->getMessage()); // <-- ADDED error log
    header('Location: /index.php?page=items&status=delete_error');
    exit;
}


/* --- Delete Item --- */
try {
  $pdo->beginTransaction(); // <-- ADDED

  // --- ADDED: GET NAME BEFORE DELETING ---
  $stmt_get = $pdo->prepare("SELECT item_name, item_code FROM item WHERE item_id = :id");
  $stmt_get->execute([':id' => $item_id]);
  $itemInfo = $stmt_get->fetch(PDO::FETCH_ASSOC);
  $itemName = $itemInfo['item_name'] ?? 'Unknown Item';
  $itemCode = $itemInfo['item_code'] ?? 'N/A';
  // --- END ADDED ---

  // --- LOG THE ACTIVITY ---
  ActivityLogger::log($pdo, 'Delete', 'Item', "Deleted item '$itemName' (Code: $itemCode, ID: $item_id)");
  // --- END LOG ---

  // --- ADDED: Send IN-APP Notification (inside transaction) ---
  $notif_msg = "Item '$itemName' (Code: $itemCode) was deleted by $currentUsername.";
  $notif_link = "/index.php?page=items";
  InternalNotify::send($pdo, "Item Deleted", $notif_msg, $notif_link, null, "Admin");
  InternalNotify::send($pdo, "Item Deleted", $notif_msg, $notif_link, null, "Manager");
  // --- END ADDED ---

  $stmt_alert = $pdo->prepare("DELETE FROM stock_alert WHERE item_id = :id"); // <-- ADDED: Clear alerts
  $stmt_alert->execute([':id' => $item_id]);

  $stmt = $pdo->prepare("DELETE FROM item WHERE item_id = :id");
  $stmt->execute([':id' => $item_id]);

  $pdo->commit(); // <-- ADDED

  // --- ADDED: SEND SNS EMAIL NOTIFICATION (AFTER COMMIT) ---
  try {
      item_notify_deleted(
          (string)$item_id, $itemName, $currentUserEmail, $currentUserRole
      );
  } catch (Throwable $e) {
      error_log("SNS Notification failed for delete_item: " . $e->getMessage());
  }
  // --- END ADDED ---

  header('Location: /index.php?page=items&status=deleted');
  exit;

} catch (Throwable $ex) {
  if ($pdo->inTransaction()) $pdo->rollBack(); // <-- ADDED
  error_log("Item delete error: " . $ex->getMessage()); // <-- ADDED error log
  header('Location: /index.php?page=items&status=delete_error');
  exit;
}
?>
<?php
// public/api/update_po.php
declare(strict_types=1);

session_start(); 

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
// Check for basic staff login (Admin, Manager, or Staff)
Auth::check_staff();

/* --- Helper function for auth redirects --- */
function jerr_redirect(string $message_key, int $po_id) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']->inTransaction()) {
        $GLOBALS['pdo']->rollBack();
    }
    header('Location: /index.php?page=po_details&id=' . $po_id . '&status=' . $message_key);
    exit;
}

/* --- Get Current User Info --- */
$currentUser = Auth::user(); 
$user_id = $currentUser['user_id'] ?? null;
$username = $currentUser['username'] ?? 'System'; // This is used by the GR trigger
$currentUserEmail = $currentUser['email'] ?? 'system@example.com';
$currentUserRole = $currentUser['role'] ?? 'SYSTEM';
$currentUsername = $currentUser['username'] ?? 'System'; 


/* --- Get POST data --- */
$po_id        = (int)($_POST['po_id'] ?? 0);
$new_status   = (string)($_POST['status'] ?? '');
$expected_date = (string)($_POST['expected_date'] ?? ''); 
$items_json   = (string)($_POST['items_json'] ?? '[]');
$items        = json_decode($items_json, true);

/* --- Validation --- */
$po_statuses = ['Created', 'Pending', 'Approved', 'Completed', 'Rejected', 'Delayed', 'Confirmed', 'Received', 'Issue', 'Shipped']; 

if ($po_id <= 0) {
  header('Location: /index.php?page=po&status=error');
  exit;
}
if (!in_array($new_status, $po_statuses)) {
  header('Location: /index.php?page=po_details&id=' . $po_id . '&status=error_status');
  exit;
}
if (!is_array($items)) {
  $items = [];
}

/* --- Update the PO in the database --- */
try {
  // 1. Check the current status of the PO
  $stmt_check = $pdo->prepare("SELECT status, expected_date FROM purchase_order WHERE po_id = :id");
  $stmt_check->execute([':id' => $po_id]);
  $po = $stmt_check->fetch(PDO::FETCH_ASSOC);

  if (!$po) {
    throw new Exception('Purchase Order not found.');
  }

  $current_status = $po['status'];
  $current_expected_date = $po['expected_date']; // Get current date for comparison
  $is_fully_editable = in_array($current_status, ['Created']);

  // Authorization Checks
  if ($is_fully_editable) {
      // User is trying a full edit (items, date, or status)
      if (!Auth::can('manage_po_status_all')) {
          jerr_redirect('auth_error', $po_id);
      }
  } else if ($new_status !== $current_status) {
      // User is trying to change the status on a locked PO
      if (Auth::can('manage_po_status_all')) {
          // Allow (Manager/Admin)
      } else if (Auth::can('manage_po_status_basic')) {
          // Staff check
          $staff_allowed_statuses = ['Received', 'Issue', 'Completed'];
          if (!in_array($new_status, $staff_allowed_statuses, true)) {
              // Staff is trying to set a status they are not allowed to
              jerr_redirect('auth_error', $po_id);
          }
      } else {
          // User has no status-management perms at all
          jerr_redirect('auth_error', $po_id);
      }
  } else if ($new_status === $current_status && $expected_date !== $current_expected_date) {
      // User is only changing the date on a locked PO.
      // This is not allowed. Only 'create_po' users can change dates, and only on 'Created' POs.
      jerr_redirect('auth_error', $po_id);
  }


  // Begin transaction
  $pdo->beginTransaction();

  // 2. Enforce the new business logic
  if ($is_fully_editable) {
    // --- CASE 1: PO is 'Created' (Full Update) ---
    
    if (empty($expected_date)) {
        jerr_redirect('error_date', $po_id);
    }
    if (empty($items)) {
        jerr_redirect('no_items', $po_id);
    }
    $sql_po = "
      UPDATE purchase_order
      SET 
        status = :status,
        expected_date = :expected_date
      WHERE 
        po_id = :po_id
    ";
    $stmt_po = $pdo->prepare($sql_po);
    $stmt_po->execute([
      ':status'       => $new_status,
      ':expected_date' => $expected_date,
      ':po_id'        => $po_id
    ]);
    $stmt_del = $pdo->prepare("DELETE FROM purchase_order_details WHERE po_id = :po_id");
    $stmt_del->execute([':po_id' => $po_id]);
    $sql_pod = 'INSERT INTO purchase_order_details
      (po_id, item_id, quantity, unit_price, purchase_cost)
      VALUES
      (:po_id, :item_id, :quantity, :unit_price, :purchase_cost)';
    $stmt_pod = $pdo->prepare($sql_pod);
    foreach ($items as $item_data) {
        $quantity = (int)$item_data['qty'];
        $unit_price = (float)$item_data['price'];
        $purchase_cost = $quantity * $unit_price;
        $stmt_pod->execute([
          ':po_id'         => $po_id,
          ':item_id'       => (int)$item_data['item_id'],
          ':quantity'      => $quantity,
          ':unit_price'    => $unit_price,
          ':purchase_cost' => $purchase_cost
        ]);
    }

  } else {
    // --- CASE 2: PO is NOT fully editable (Status-Only Update) ---

    if (in_array($new_status, ['Created'])) { 
        jerr_redirect('cant_revert', $po_id);
    }

    if ($current_status === 'Shipped' && $new_status === 'Received') {
        //TRIGGER 1: AUTO-CREATE GOODS RECEIPT (Shipped -> Received)
        
        $pdo->prepare("
          UPDATE purchase_order 
          SET status = 'Received', receive_date = IFNULL(receive_date, CURDATE())
          WHERE po_id = :id
        ")->execute([':id' => $po_id]);

        $gr_sql = "INSERT INTO goods_receipt (po_id, receipt_no, receive_date, status, sent_by, receiver_name) 
                   SELECT :po_id, :receipt_no, CURDATE(), 'Confirmed', IFNULL(s.company_name, 'Unknown Supplier'), :receiver
                   FROM purchase_order po
                   LEFT JOIN supplier s ON po.supplier_id = s.supplier_id
                   WHERE po.po_id = :po_id_join";
        $pdo->prepare($gr_sql)->execute([
            ':po_id' => $po_id,
            ':receipt_no' => 'GRN-' . str_pad((string)$po_id, 5, '0', STR_PAD_LEFT),
            ':receiver' => $username, 
            ':po_id_join' => $po_id
        ]);
        $receipt_id = $pdo->lastInsertId();

        $pod_sql = "SELECT item_id, quantity FROM purchase_order_details WHERE po_id = :id";
        $stmt_items = $pdo->prepare($pod_sql);
        $stmt_items->execute([':id' => $po_id]);
        $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $grd_sql = "INSERT INTO goods_receipt_details (receipt_id, item_id, quantity, uom, warehouse) 
                    VALUES (:receipt_id, :item_id, :quantity, 'PC', 'Main-01')"; 
        $stmt_grd = $pdo->prepare($grd_sql);
        
        foreach ($po_items as $item) {
            if (empty($item['item_id']) || empty($item['quantity'])) continue;
            $stmt_grd->execute([
                ':receipt_id' => $receipt_id,
                ':item_id'    => (int)$item['item_id'],
                ':quantity'   => (int)$item['quantity']
            ]);
        }
        
    } else if ($current_status === 'Received' && $new_status === 'Completed') {
        // TRIGGER 2: UPDATE INVENTORY (Received -> Completed)
        
        $sql_complete = "
          UPDATE purchase_order 
          SET 
            status = 'Completed', 
            approved_by_user_id = :user_id,
            completion_date = CURDATE()
          WHERE po_id = :id
        ";
        $pdo->prepare($sql_complete)->execute([
            ':user_id' => $user_id,
            ':id' => $po_id
        ]);

        $gr_check_sql = "SELECT receipt_id FROM goods_receipt WHERE po_id = :po_id";
        $stmt_gr_check = $pdo->prepare($gr_check_sql);
        $stmt_gr_check->execute([':po_id' => $po_id]);
        $existing_receipt_id = $stmt_gr_check->fetchColumn();

        if (!$existing_receipt_id) {
            // This is the fallback logic in case GR wasn't created
            $pdo->prepare("UPDATE purchase_order SET receive_date = IFNULL(receive_date, CURDATE()) WHERE po_id = :id")
                ->execute([':id' => $po_id]);

            $gr_sql = "INSERT INTO goods_receipt (po_id, receipt_no, receive_date, status, sent_by, receiver_name) 
                       SELECT :po_id, :receipt_no, CURDATE(), 'Confirmed', IFNULL(s.company_name, 'Unknown Supplier'), :receiver
                       FROM purchase_order po
                       LEFT JOIN supplier s ON po.supplier_id = s.supplier_id
                       WHERE po.po_id = :po_id_join";
            $pdo->prepare($gr_sql)->execute([
                ':po_id' => $po_id,
                ':receipt_no' => 'GRN-' . str_pad((string)$po_id, 5, '0', STR_PAD_LEFT),
                ':receiver' => $username,
                ':po_id_join' => $po_id
            ]);
            $receipt_id = $pdo->lastInsertId();

            $pod_sql = "SELECT item_id, quantity FROM purchase_order_details WHERE po_id = :id";
            $stmt_items = $pdo->prepare($pod_sql);
            $stmt_items->execute([':id' => $po_id]);
            $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            $grd_sql = "INSERT INTO goods_receipt_details (receipt_id, item_id, quantity, uom, warehouse) 
                        VALUES (:receipt_id, :item_id, :quantity, 'PC', 'Main-01')";
            $stmt_grd = $pdo->prepare($grd_sql);
            
            foreach ($po_items as $item) {
                 if (empty($item['item_id']) || empty($item['quantity'])) continue;
                 $stmt_grd->execute([
                    ':receipt_id' => $receipt_id,
                    ':item_id'    => (int)$item['item_id'],
                    ':quantity'   => (int)$item['quantity']
                ]);
            }
        }
        
        $grd_sql = "SELECT grd.item_id, grd.quantity 
                    FROM goods_receipt_details grd
                    JOIN goods_receipt gr ON grd.receipt_id = gr.receipt_id
                    WHERE gr.po_id = :po_id";
        $stmt_items = $pdo->prepare($grd_sql);
        $stmt_items->execute([':po_id' => $po_id]);
        $items_to_add = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items_to_add)) {
            // No items on PO, just mark as complete.
        } else {
            $item_update_sql = "UPDATE item SET stock_quantity = stock_quantity + :quantity WHERE item_id = :item_id";
            $stmt_update_item = $pdo->prepare($item_update_sql);

            // ALL alert management statements 
            $stmt_check = $pdo->prepare("SELECT item_name, item_code, stock_quantity, threshold_quantity FROM item WHERE item_id = :item_id");
            
            $stmt_resolve_low = $pdo->prepare("UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Low Stock'");
            $stmt_resolve_out = $pdo->prepare("UPDATE stock_alert SET resolved = 1 WHERE item_id = :item_id AND resolved = 0 AND alert_type = 'Out of Stock'");
            
            $stmt_check_low = $pdo->prepare("SELECT 1 FROM stock_alert WHERE item_id = :item_id AND alert_type = 'Low Stock' AND resolved = 0");
            $stmt_create_low = $pdo->prepare("INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (:item_id, 'Low Stock', 0)");

            foreach ($items_to_add as $item) {
                $current_item_id = (int)$item['item_id'];
                $added_quantity = (int)$item['quantity'];

                if ($added_quantity <= 0 || $current_item_id <= 0) {
                    continue; 
                }

                $stmt_update_item->execute([
                    ':quantity' => $added_quantity,
                    ':item_id'  => $current_item_id
                ]);

                // Full alert logic (from adjust_item.php) 
                $stmt_check->execute([':item_id' => $current_item_id]);
                $item_status = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($item_status) {
                    $new_stock = (int)$item_status['stock_quantity'];
                    $threshold = (int)$item_status['threshold_quantity'];

                    if ($new_stock > $threshold) {
                        // 1. New quantity is ABOVE threshold, RESOLVE all open alerts
                        $stmt_resolve_low->execute([':item_id' => $current_item_id]);
                        $stmt_resolve_out->execute([':item_id' => $current_item_id]);

                    } else if ($new_stock > 0 && $new_stock <= $threshold) {
                        // 2. New quantity is LOW (but not 0)
                        $stmt_resolve_out->execute([':item_id' => $current_item_id]);
                        
                        $stmt_check_low->execute([':item_id' => $current_item_id]);
                        if (!$stmt_check_low->fetch()) {
                            // Create a new 'Low Stock' alert if one doesn't exist
                            $stmt_create_low->execute([':item_id' => $current_item_id]);
                            
                        }
                    }
                }
            }
        }
        
    } else {
        // DEFAULT: Update the status
        // This runs if the status changed but didn't trigger the special cases
        // e.g., 'Received' -> 'Issue'
        $sql = "UPDATE purchase_order SET status = :status WHERE po_id = :po_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':status' => $new_status,
          ':po_id'  => $po_id
        ]);
    }
  }

  // Send In-App Notifications if status changed 
  if ($new_status !== $current_status) {
      $notif_msg = "PO #$po_id status was updated to '$new_status' by $currentUsername.";
      $notif_link = "/index.php?page=po_details&id=$po_id";
      
      // Log this action
      ActivityLogger::log($pdo, 'Update', 'PurchaseOrder', "Updated PO #$po_id status from '$current_status' to '$new_status'");
  
      // Send to Admin 
      InternalNotify::send($pdo, "PO Status Updated", $notif_msg, $notif_link, null, "Admin");
      
      // Send to Manager
      InternalNotify::send($pdo, "PO Status Updated", $notif_msg, $notif_link, null, "Manager");
      
      // Send to Staff (conditionally)
      $staff_statuses = ['Received', 'Issue', 'Completed', 'Delayed', 'Shipped'];
      if (in_array($new_status, $staff_statuses, true)) {
          InternalNotify::send($pdo, "PO Status Updated", $notif_msg, $notif_link, null, "Staff");
      }
  }

  // Commit Transaction 
  $pdo->commit();
  
  // Send SNS Email Notification (AFTER commit)
  if ($new_status !== $current_status) {
      
      // Get the supplier's email for this PO
      $supplierEmail = null;
      try {
          $sql_email = "
              SELECT s.email 
              FROM supplier s
              JOIN purchase_order po ON s.supplier_Did = po.supplier_id
              WHERE po.po_id = :po_id
          ";
          $stmt_email = $pdo->prepare($sql_email);
          $stmt_email->execute([':po_id' => $po_id]);
          $supplierEmail = $stmt_email->fetchColumn();
      } catch (Throwable $e) {
          error_log("Could not fetch supplier email for PO $po_id: " . $e->getMessage());
      }

      // Send notification, passing supplier email
      po_notify_status_change(
          (string)$po_id,
          $new_status,
          $currentUserEmail,
          $currentUserRole,
          $supplierEmail 
      );
  }

  // Redirect on Success 
  header('Location: /index.php?page=po_details&id=' . $po_id . '&status=updated');
  exit;

} catch (Throwable $e) {
  // ERROR: Rollback and Redirect
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("PO Update Error: " . $e->getMessage());
  header('Location: /index.php?page=po_details&id=' . $po_id . '&status=error');
  exit;
}
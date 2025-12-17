<?php
// supplier_portal/supplier_update_po.php
declare(strict_types=1);
session_start();

// Authentication check
if (!isset($_SESSION['supplier_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit();
}

$supplier_id = $_SESSION['supplier_id'];
$supplier_name = $_SESSION['supplier_name'] ?? 'Supplier'; // Get supplier name from session

/* --- Load DB and ALL Notify files --- */
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php'; 
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/PoNotify.php';
require_once $root . '/app/InternalNotify.php'; 
require_once $root . '/app/ActivityLogger.php'; 


// Only allow POST requests 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

// Get Form Data 
$po_id = (int)($_POST['po_id'] ?? 0);
$new_status = (string)($_POST['status'] ?? '');
$old_status = (string)($_POST['old_status'] ?? ''); 
$new_expected_date = (string)($_POST['expected_date'] ?? '');

$redirect_url = "/index.php?page=supplier_po_details&id=$po_id";

// Validation
if ($po_id === 0) {
    header("Location: $redirect_url&status=error");
    exit();
}
if ($old_status === 'Pending' && empty($new_expected_date)) {
    header("Location: $redirect_url&status=error_date");
    exit();
}

// Flow Validation 
$supplier_flow_map = [
    'Pending'   => ['Approved', 'Rejected'],
    'Confirmed' => ['Shipped'],
];

if (array_key_exists($old_status, $supplier_flow_map)) {
    $allowed_next_steps = $supplier_flow_map[$old_status];
    if (!in_array($new_status, $allowed_next_steps)) {
        header("Location: $redirect_url&status=error_invalid_status_change");
        exit();
    }
} else {
    if ($new_status !== $old_status) {
        header("Location: $redirect_url&status=error_locked");
        exit();
    }
}


try {
    $pdo->beginTransaction(); 

    // SECURITY CHECK & GET SUPPLIER EMAIL 
    $check_sql = "
      SELECT po.supplier_id, po.status, s.email 
      FROM purchase_order po
      JOIN supplier s ON po.supplier_id = s.supplier_id
      WHERE po.po_id = :po_id
    ";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([':po_id' => $po_id]);
    $po = $check_stmt->fetch(PDO::FETCH_ASSOC);

    $supplierEmail = $po['email'] ?? 'supplier@unknown.com';

    if (!$po || $po['supplier_id'] != $supplier_id) {
        $pdo->rollBack(); 
        http_response_code(403);
        header("Location: $redirect_url&status=error_permission");
        exit();
    }
    
    if ($po['status'] !== $old_status) {
        $pdo->rollBack(); 
        header("Location: $redirect_url&status=error_sync");
        exit();
    }
    
    // IF SECURITY CHECK PASSES, UPDATE THE PO 
    $update_expected_date_clause = '';
    $update_params = [
        ':status' => $new_status,
        ':po_id' => $po_id,
        ':supplier_id' => $supplier_id 
    ];

    if ($old_status === 'Pending') {
        $update_expected_date_clause = ', expected_date = :new_expected_date';
        $update_params[':new_expected_date'] = $new_expected_date;
    }
    
    $update_sql = "
        UPDATE purchase_order SET
            status = :status
            {$update_expected_date_clause}
        WHERE
            po_id = :po_id AND supplier_id = :supplier_id
    ";
    
    $update_stmt = $pdo->prepare($update_sql);
    $success = $update_stmt->execute($update_params);

    if ($success && $new_status !== $old_status) {
        // Log Activity
        ActivityLogger::log($pdo, 'Update', 'PurchaseOrder (Portal)', "Supplier '$supplier_name' updated PO #$po_id from '$old_status' to '$new_status'");

        // Send In-App Notification
        $notif_msg = "Supplier '$supplier_name' updated PO #$po_id to '$new_status'.";
        $notif_link = "/index.php?page=po_details&id=$po_id";
        
        // 1. Send to Admin (always)
        InternalNotify::send($pdo, "PO Updated by Supplier", $notif_msg, $notif_link, null, "Admin");
        
        // 2. Send to Manager (always, as per policy)
        InternalNotify::send($pdo, "PO Updated by Supplier", $notif_msg, $notif_link, null, "Manager");
        
        // 3. Send to Staff
        // Send notification to staff if the new status is 'Shipped'.
        if ($new_status === 'Shipped') {
             InternalNotify::send($pdo, "PO Updated by Supplier", $notif_msg, $notif_link, null, "Staff");
        }
    }
    
    $pdo->commit(); 

    if ($success) {
        // Send SNS Email Notification
        if ($new_status !== $old_status) {
            try {
                po_notify_status_change(
                    (string)$po_id, $new_status, $supplierEmail, 'SUPPLIER'
                );
            } catch (Throwable $e) {
                error_log("SNS Notification failed for supplier_update_po: " . $e->getMessage());
            }
        }

        header("Location: $redirect_url&status=updated");
        exit();
    } else {
        header("Location: $redirect_url&status=error");
        exit();
    }

} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack(); 
    error_log("Supplier PO Update Error: " . $ex->getMessage());
    header("Location: $redirect_url&status=error");
    exit();
}
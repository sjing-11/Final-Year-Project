<?php
// views/supplier_portal/details.php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php'; 

// Authentication check
if (!isset($_SESSION['supplier_id'])) {
    header('Location: /index.php?page=login');
    exit();
}

$po_id = (int)($_GET['id'] ?? 0);
$supplier_id = $_SESSION['supplier_id'];

if ($po_id === 0) {
  die('No Purchase Order ID provided.');
}

/* --- Handle form submission status --- */
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Purchase Order has been updated.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update purchase order. Please try again.'];
}

$sst_rate = 0.08; // Default SST rate

try {
    $stmt_sst = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'sst_rate'");
    $stmt_sst->execute();
    $sst_result = $stmt_sst->fetch(PDO::FETCH_ASSOC);
    
    if ($sst_result && $sst_result['setting_value']) {
        // Value exists, let's parse it
        $raw_sst_rate = (string)$sst_result['setting_value'];
        $cleaned_rate = trim(str_replace('%', '', $raw_sst_rate)); // Remove '%' and spaces

        if (is_numeric($cleaned_rate)) {
            $numeric_rate = (float)$cleaned_rate;
            
            // Check if user entered '10' (meaning 10%) or '0.10'
            if ($numeric_rate >= 1.0) {
                // Assume it's a percentage, like 8, 10, etc. Convert to decimal.
                $sst_rate = $numeric_rate / 100.0;
            } else {
                // Assume it's already a decimal, like 0.08, 0.10, etc.
                $sst_rate = $numeric_rate;
            }
        }
        // If not numeric (e.g., "abc"), $sst_rate remains 0.08
    }
} catch (Throwable $e) {
    // Non-critical error, just log it and use default
    error_log("Could not fetch SST rate: " . $e->getMessage());
}


$po = null;
$po_items = [];
$errorMsg = null;

try {
  // 1. Fetch main PO data
  $sql_po = "
    SELECT 
      po.*, 
      po.expected_date,
      u.username AS creator_username
    FROM purchase_order po
    LEFT JOIN user u ON po.created_by_user_id = u.user_id
    WHERE po.po_id = :po_id AND po.supplier_id = :supplier_id
  ";
  $stmt_po = $pdo->prepare($sql_po);
  $stmt_po->execute([':po_id' => $po_id, ':supplier_id' => $supplier_id]);
  $po = $stmt_po->fetch(PDO::FETCH_ASSOC);

  if (!$po) {
    throw new Exception("Purchase Order not found or you do not have permission to view it.");
  }
  
  // Status Flow Logic
  $real_status = $po['status'];
  $current_status_for_display = $real_status; 

  // 1. Lock logic: Locked if status is NOT 'Pending'
  $is_date_locked = ($real_status !== 'Pending');

  // 2. Define the available status options based on the current status.
  $all_status_options = [];
  $is_status_locked = false; 

  switch ($real_status) {
      case 'Pending':
          // Supplier can approve or reject.
          $all_status_options = ['Pending', 'Approved', 'Rejected'];
          break;
      
      case 'Approved':
          // Supplier has approved. Waiting for Admin confirmation.
          $all_status_options = ['Approved'];
          $is_status_locked = true; // Lock the dropdown
          break;

      case 'Confirmed':
          // Admin has confirmed, supplier can now ship.
          $all_status_options = ['Confirmed', 'Shipped'];
          break;

      case 'Rejected':
      case 'Shipped':
      case 'Received':
      case 'Completed':
          // Final states for supplier. Locked.
          $all_status_options = [$real_status];
          $is_status_locked = true;
          break;

      default:
          // Fallback for any other unexpected or final status
          $all_status_options = [$real_status];
          $is_status_locked = true;
  }

  // 3. Auto-trigger 'Delayed' status for display
  $today = new DateTime();
  // FIX: Use the correct column name (expected_date)
  $expected_date_str = $po['expected_date']; 
  
  $final_statuses = ['Received', 'Completed', 'Rejected', 'Issue', 'Shipped']; 

  if ($expected_date_str && !in_array($real_status, $final_statuses)) {
      try {
          $expected_date_obj = new DateTime($expected_date_str);
          $today_date_only = new DateTime($today->format('Y-m-d'));
          
          if ($today_date_only > $expected_date_obj) {
              $current_status_for_display = 'Delayed'; 
              if (!in_array('Delayed', $all_status_options)) {
                    $all_status_options[] = 'Delayed';
              }
          }
      } catch (Exception $e) { 
          /* Ignore invalid date format */ 
      }
  }

  // 2. Fetch items *on* this PO
  $sql_items = "
    SELECT 
      pod.quantity, pod.unit_price,
      i.item_code, i.item_name, i.measurement
    FROM purchase_order_details pod
    LEFT JOIN item i ON pod.item_id = i.item_id
    WHERE pod.po_id = :po_id
    ORDER BY i.item_name
  ";
  $stmt_items = $pdo->prepare($sql_items);
  $stmt_items->execute([':po_id' => $po_id]);
  $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
  $errorMsg = "Error fetching PO details: " . $ex->getMessage();
}

?>

<script>
    function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
</script>

<section class="page po-details-page">

    <?php if ($statusMsg): ?>
    <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
        <span><?= e($statusMsg[1]) ?></span>
        <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <?php endif; ?>

    <div class="card card-soft">
        <div class="page-head">
            <h1>Purchase Order Details (PO-<?= e($po_id) ?>)</h1>
            <div class="actions">
                <a href="/index.php?page=supplier_dashboard" class="btn btn-secondary">
                    &larr; Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($errorMsg): ?>
            <div class="alert error" style="margin:12px 0;"><?= e($errorMsg) ?></div>
        <?php elseif ($po): ?>
            
            <form id="poSupplierEditForm" class="supplier-form" method="post" action="/index.php?page=supplier_update_handler" autocomplete="off">
                <input type="hidden" name="po_id" value="<?= e($po['po_id']) ?>">
                
                <!-- Grid for top details -->
                <div class="po-details-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <label>Issue Date
                        <input type="text" value="<?= e(date('d/m/Y', strtotime($po['issue_date']))) ?>" readonly style="background-color: #f3f4f6;">
                    </label>
                    <label>Created By (Staff)
                        <input type="text" value="<?= e($po['creator_username'] ?? 'N/A') ?>" readonly style="background-color: #f3f4f6;">
                    </label>
                    <label>Your PO Status
                        <select name="status" id="poStatusSelect" <?= $is_status_locked ? 'disabled style="background-color: #f3f4f6;"' : '' ?>>
                            <?php foreach (array_unique($all_status_options) as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= ($current_status_for_display === $opt) ? 'selected' : '' ?>>
                                    <?= e($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <?php if ($is_status_locked): ?>
                            <!-- If locked, the value is just sent for reference, but won't be "updated" -->
                            <input type="hidden" name="status" value="<?= e($real_status) ?>" />
                        <?php else: ?>
                            <!-- Send the *old* status so the handler can verify the change is valid -->
                            <input type="hidden" name="old_status" value="<?= e($real_status) ?>" />
                        <?php endif; ?>
                    </label>
                </div>

                <div class="po-details-grid" style="display: grid; grid-template-columns: 1fr; gap: 14px; align-items: end;">
                    <label>Update Expected Delivery Date
                        <!-- FIX: Name attribute changed to expected_date -->
                        <input type="date" name="expected_date" id="expectedDateInput" value="<?= e($po['expected_date']) ?>" <?= $is_date_locked ? 'disabled style="background-color: #f3f4f6;"' : '' ?>>
                    </label>
                    <?php if ($is_date_locked): ?>
                        <input type="hidden" name="expected_date" value="<?= e($po['expected_date']) ?>" />
                    <?php endif; ?>
                </div>

                <h2 style="margin-top: 24px; margin-bottom: 8px; border-top: 1px solid #e5eaf2; padding-top: 16px;">Items in this Order</h2>
                
                <div class="table table-po-items">
                    <div class="t-head">
                        <div>Item</div>
                        <div>Qty</div>
                        <div>Unit Price</div>
                        <div>Total</div>
                    </div>
                    <?php 
                    $subtotal = 0;
                    foreach ($po_items as $item): 
                        $total = (float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0);
                        $subtotal += $total;
                    ?>
                        <div class="t-row">
                            <div>
                                <span class="item-name-display"><?= e($item['item_name']) ?></span>
                                <span class="item-meta-display">
                                    Code: <?= e($item['item_code'] ?? 'N/A') ?><br>
                                    Unit: <?= e($item['measurement'] ?? 'N/A') ?>
                                </span>
                            </div>
                            <script>
                              document.write(`
                                <div style="text-align: right; justify-content: flex-end;"><?= e($item['quantity']) ?></div>
                                <div style="text-align: right; justify-content: flex-end;">${money(<?= (float)($item['unit_price'] ?? 0) ?>)}</div>
                                <div style="text-align: right; justify-content: flex-end;">${money(<?= (float)($total ?? 0) ?>)}</div>
                              `);
                            </script>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php
                    $tax = $subtotal * $sst_rate; 
                    $grandTotal = $subtotal + $tax;
                ?>
                <div class="po-summary" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5eaf2;">
                    <script>
                        document.write(`
                            <div class="po-summary-row"><span>Subtotal</span><span>${money(<?= $subtotal ?>)}</span></div>
                            <div class="po-summary-row"><span>SST (<?= e($sst_rate * 100) ?>%)</span><span>${money(<?= $tax ?>)}</span></div>
                            <div class="po-summary-row po-summary-total"><span>Grand Total</span><span>${money(<?= $grandTotal ?>)}</span></div>
                        `);
                    </script>
                </div>

                <!-- Action Buttons -->
                <div class="btn-row detail-actions" style="margin-top: 20px; justify-content: flex-end; gap: 8px; border-top: 1px solid #e5eaf2; padding-top: 16px;">
                    
                    <?php if (!$is_status_locked): // Only show Save Changes button if status is NOT locked ?>
                        <button type="submit" form="poSupplierEditForm" id="saveChangesBtn" class="btn btn-primary">
                            Save Changes
                        </button>
                    <?php endif; ?>

                </div>
            </form>

        <?php endif; ?>
    </div>
</section>

<!-- Toast JS -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const toast = document.getElementById('toastPopup');
  if (toast) {
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      const cleanUrl = window.location.href.split('[?')[0] + window.location.hash;
      window.history.replaceState(null, '', cleanUrl);
    }
  }
});
</script>

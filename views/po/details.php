<?php
// views/po/details.php
declare(strict_types=1);

// Load PDO from db.php
$root = dirname(__DIR__, 2); 
require_once $root . '/app/db.php'; 

if (!isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error.');
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money_format($val): string {
    return '$' . number_format((float)$val, 2);
}

// Get PO ID
$po_id = (int)($_GET['id'] ?? 0);
if ($po_id === 0) {
  die('No Purchase Order ID provided.');
}

// Load Auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $root . '/app/Auth.php';
// Check login and permission
Auth::check_staff(['view_po_details']);

// Handle redirect status messages
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Purchase Order has been updated.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update purchase order. Please try again.'];
} elseif ($status === 'cant_revert') {
  $statusMsg = ['error', 'A processed order cannot be reverted to Created or Pending.'];
} elseif ($status === 'no_items') {
    $statusMsg = ['error', 'You must add at least one item to the purchase order.'];
} elseif ($status === 'error_date') {
    $statusMsg = ['error', 'Please select an expected delivery date.'];
}
elseif ($status === 'auth_error') { 
    $statusMsg = ['error', 'You do not have permission to perform that action.'];
}


// Fetch All PO Data
$po = null;
$po_items = [];
$supplier_items = []; // For the 'Add Item' dropdown
$errorMsg = null;
$is_fully_editable = false; // Main logic switch
$sst_rate = 0.08; // Default SST rate

try {
  // 1. Fetch and Parse SST Rate
  $stmt_sst = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key = 'sst_rate'");
  $stmt_sst->execute();
  $sst_result = $stmt_sst->fetch(PDO::FETCH_ASSOC);
  
  if ($sst_result && $sst_result['setting_value']) {
      $raw_sst_rate = (string)$sst_result['setting_value'];
      $cleaned_rate = trim(str_replace('%', '', $raw_sst_rate));

      if (is_numeric($cleaned_rate)) {
          $numeric_rate = (float)$cleaned_rate;
          if ($numeric_rate >= 1.0) {
              $sst_rate = $numeric_rate / 100.0;
          } else {
              $sst_rate = $numeric_rate;
          }
      }
  }

  // 2. Fetch main PO data
  $sql_po = "
    SELECT 
      po.*, 
      po.expected_date, po.completion_date, po.receive_date,
      s.company_name, s.email, s.phone,
      u.username AS creator_username
    FROM purchase_order po
    LEFT JOIN supplier s ON po.supplier_id = s.supplier_id
    LEFT JOIN user u ON po.created_by_user_id = u.user_id
    WHERE po.po_id = :id
  ";
  $stmt_po = $pdo->prepare($sql_po);
  $stmt_po->execute([':id' => $po_id]);
  $po = $stmt_po->fetch(PDO::FETCH_ASSOC);

  if (!$po) {
    throw new Exception("Purchase Order not found.");
  }

  // Strict Status Flow Logic
  $real_status = $po['status']; 
  $staff_flow_map = [
      'Created'   => ['Pending'],
      'Pending'   => [], // Handled by supplier (Approved/Rejected)
      'Approved'  => ['Confirmed'], // Admin confirms supplier approval
      'Rejected'  => [], // Dead end
      'Confirmed' => [], 
      'Shipped'   => ['Received'],
      'Received'  => ['Issue', 'Completed'], 
      'Issue'     => ['Received', 'Completed'], 
      'Completed'  => [],
      'Delayed'   => ['Received', 'Issue'], // Delayed can be received or marked as an issue
  ];
  $current_po_status = $real_status;
  $next_steps = $staff_flow_map[$real_status] ?? [];
  $options_for_dropdown = [$real_status];
  $options_for_dropdown = array_merge($options_for_dropdown, $next_steps);

  // Auth for Status Dropdown
  $can_manage_all_status = Auth::can('manage_po_status_all');
  $can_manage_basic_status = Auth::can('manage_po_status_basic');
  $can_change_status_at_all = $can_manage_all_status || $can_manage_basic_status;

  if (!$can_manage_all_status && $can_manage_basic_status) {
      // --- User is Staff ---
      // Filter dropdown to only what 'Staff' can do
      $staff_allowed_statuses = ['Received', 'Issue', 'Completed'];
      // ALWAYS include the PO's current status in the list
      $staff_allowed_statuses[] = $current_po_status;
      
      // Filter the list to only show what Staff can select
      $options_for_dropdown = array_intersect($options_for_dropdown, $staff_allowed_statuses);
      
  } else if (!$can_change_status_at_all) {
      // --- User is View-Only ---
      // User has view-only, cannot change status at all.
      $options_for_dropdown = [$current_po_status]; // Only show the current status
  
  } else {
      // --- User is Admin/Manager ---
      // No filter is applied. They see all options from $staff_flow_map.
      // We just let $options_for_dropdown pass through.
  }

  // Auto-trigger 'Delayed' status display
  $today = new DateTime();
  $expected_date_str = $po['expected_date'];
  
  // Only Confirmed or Shipped can be delayed.
  $statuses_that_can_be_delayed = ['Confirmed', 'Shipped']; 
  
  if ($expected_date_str && in_array($real_status, $statuses_that_can_be_delayed)) {
      try {
          $expected_date_obj = new DateTime($expected_date_str);
          $today_date_only = new DateTime($today->format('Y-m-d'));
          
          if ($today_date_only > $expected_date_obj) {
              $current_po_status = 'Delayed'; // VISUAL override
              if (!in_array('Delayed', $options_for_dropdown)) {
                    // Add Delayed as an option ONLY if the PO is actually late
                    $options_for_dropdown[] = 'Delayed';
              }
          }
      } catch (Exception $e) {  
      }
  }


  // 3. Fetch items on this PO
  $sql_items = "
    SELECT 
      pod.item_id, pod.quantity, pod.unit_price,
      i.item_code, i.item_name, i.measurement
    FROM purchase_order_details pod
    LEFT JOIN item i ON pod.item_id = i.item_id
    WHERE pod.po_id = :id
    ORDER BY i.item_name
  ";
  $stmt_items = $pdo->prepare($sql_items);
  $stmt_items->execute([':id' => $po_id]);
  $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

  // 4. Set lock status
  $is_fully_editable = ($real_status === 'Created');
  
  // 5. If editable, fetch all items for this supplier
  $item_sql = "
    SELECT item_id, item_code, item_name, unit_cost, measurement 
    FROM item 
    WHERE supplier_id = :supplier_id
    ORDER BY item_name
  ";
  $item_stmt = $pdo->prepare($item_sql);
  $item_stmt->execute([':supplier_id' => $po['supplier_id']]);
  $supplier_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
  

} catch (Throwable $ex) {
  $errorMsg = "Error fetching PO details: " . $ex->getMessage();
}

$is_completed = ($real_status === 'Completed');
$all_status_options = array_unique($options_for_dropdown); 
$unselectable_statuses = ['Created', 'Pending', 'Approved', 'Rejected'];

// Re-format po_items for JS
$js_po_items = [];
foreach ($po_items as $item) {
    $js_po_items[] = [
        'item_id' => $item['item_id'],
        'item_code' => $item['item_code'],
        'name' => $item['item_name'],
        'measurement' => $item['measurement'],
        'qty' => $item['quantity'],
        'price' => $item['unit_price']
    ];
}

?>


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
        
        <div id="viewModeButtons" style="display: flex; gap: 8px;">
            <?php if ($is_fully_editable && Auth::can('manage_po_status_all')): ?>
                <button type="button" id="editPoBtn" class="btn btn-primary">Edit PO</button>
            <?php endif; ?>

            <?php if (Auth::can('export_po')): ?>
              <a href="/api/export_po.php?id=<?= e($po['po_id']) ?>" 
                 class="btn btn-secondary" 
                 target="_blank">
                  Export to PDF
              </a>
            <?php endif; ?>
            

            <a href="/index.php?page=po" class="btn btn-secondary">
              &larr; Back to List
            </a>
        </div>
        
        <div id="editModeButtons" class="edit-controls" style="display: flex; gap: 8px;">
             <button type="button" id="cancelEditBtn" class="btn btn-secondary">Cancel</button>
        </div>

      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php elseif ($po): ?>
      
      <!-- Edit Form: Status, Date, Items -->
      <form id="poEditForm" class="supplier-form" method="post" action="/api/update_po.php" autocomplete="off">
        <input type="hidden" name="po_id" value="<?= e($po['po_id']) ?>">
        
        <!-- Grid for top details -->
        <div class="po-details-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
            <label>Supplier
                <input type="text" value="<?= e($po['company_name'] ?? 'N/A') ?>" readonly style="background-color: #f3f4f6;">
            </label>
            <label>Supplier Email
                <input type="text" value="<?= e($po['email'] ?? 'N/A') ?>" readonly style="background-color: #f3f4f6;">
            </label>
            <label>Supplier Phone
                <input type="text" value="<?= e($po['phone'] ?? 'N/A') ?>" readonly style="background-color: #f3f4f6;">
            </label>
            <label>Issue Date
                <input type="text" value="<?= e(date('d/m/Y', strtotime($po['issue_date']))) ?>" readonly style="background-color: #f3f4f6;">
            </label>
            <label>Expected Delivery Date
                <input type="date" name="expected_date" id="receiveDateInput" value="<?= e($po['expected_date']) ?>" disabled>
            </label>
            <label>Created By
                <input type="text" value="<?= e($po['creator_username'] ?? 'N/A') ?>" readonly style="background-color: #f3f4f6;">
            </label>
        </div>

        <!-- Grid for status -->
        <div class="po-details-grid" style="display: grid; grid-template-columns: 1fr; gap: 14px; align-items: end;">
            <label>Current Status
                <select name="status" id="poStatusSelect" <?= ($is_fully_editable) ? 'disabled' : '' ?>>
                    <?php 
                    foreach ($all_status_options as $opt):
                    ?>
                        <option value="<?= e($opt) ?>" 
                                <?= ($current_po_status === $opt) ? 'selected' : '' ?>
                                >
                            <?= e($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>


        <div class="edit-controls" id="itemEditSection">
          <h3 style="margin-top: 20px; margin-bottom: 8px; border-top: 1px solid #e5eaf2; padding-top: 12px;">Add Items to PO</h3>
          
          <div class="po-item-add-row" style="display: grid; grid-template-columns: 1fr 80px auto auto; gap: 14px; align-items: end; margin-bottom: 14px; margin-right: 0;">
              <label>Item
                  <select id="poModalItemSelect">
                      <option value="">Select an item</option>
                      <!-- Options added by JS -->
                  </select>
              </label>
              <label>Quantity
                  <input type="number" id="poModalQtyInput" value="1" min="1" placeholder="1">
              </label>
              
              <div style="text-align: right; padding-bottom: 5px;">
                  <div style="font-size: 12px; color: #667085;">Total</div>
                  <div id="poModalItemTotal" style="font-size: 16px; font-weight: 600; color: #101828;">$0.00</div>
              </div>

              <button type="button" id="poModalAddItemBtn" class="btn btn-primary" style="height: 40px;">Add Item</button>
          </div>
        
        </div>


        <h2 style="margin-top: 24px; margin-bottom: 8px; border-top: 1px solid #e5eaf2; padding-top: 16px;">Items in this Order</h2>
        
        <div class="table table-po-items" id="poItemReviewTable" style="margin-top: 4px;">
            <div class="t-head" id="poItemReviewTableHead">
                <div>Item Name</div>
                <div>Qty</div>
                <div>Unit Price</div>
                <div>Total</div>
            </div>
            <div class="t-row" id="poItemEmptyRow" style="display:none;">
                <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
                    No items added yet.
                </div>
            </div>
        </div>
        
        <!-- Summary Section -->
        <div class="po-summary" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5eaf2; margin-right: 0;">
            <div class="po-summary-row">
                <span>Subtotal</span>
                <span id="poModalSubtotal">$0.00</span>
            </div>
            
            <div class="po-summary-row">
                <span>SST (<?= e($sst_rate * 100) ?>%)</span>
                <span id="poModalTax">$0.00</span>
            </div>
            
            <div class="po-summary-row po-summary-measurement">
                <span>Total Items by Unit</span>
                <span id="poModalMeasurementSummary"></span>
            </div>
            <div class="po-summary-row po-summary-total">
                <span>Grand Total</span>
                <span id="poModalGrandTotal">$0.00</span>
            </div>
        </div>
        
        <input type="hidden" name="items_json" id="poModalItemsJson" value="[]">

        <!-- Hidden data block for JS -->
        <script id="poModalData" type="application/json">
            {
                "supplierItems": <?= json_encode($supplier_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
                "currentItems": <?= json_encode($js_po_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
                "isCompleted": <?= json_encode($is_completed) ?>,
                "isFullyEditable": <?= json_encode($is_fully_editable) ?>, 
                "canChangeStatus": <?= json_encode($can_change_status_at_all) ?>,
                "sstRate": <?= json_encode($sst_rate) ?> 
            }
        </script>
        

        <!-- Action Buttons -->
        <div class="btn-row detail-actions" style="margin-top: 20px; justify-content: space-between; gap: 8px; border-top: 1px solid #e5eaf2; padding-top: 16px;">
            <div>
              <?php if ($is_fully_editable && Auth::can('delete_po')): // <-- MODIFIED check ?>
                  <a href="api/delete_po.php?id=<?= e($po['po_id']) ?>" 
                     id="deletePoBtn"
                     class="btn btn-danger" 
                     onclick="return confirm('Are you sure you want to delete this Purchase Order? This action cannot be undone.');">
                      Delete PO
                  </a>
              <?php endif; ?>
            </div>
            
            <button type="submit" form="poEditForm" id="saveChangesBtn" class="btn btn-primary"
                    style="display: none;"> <!-- Controlled by JS -->
                Save Changes
            </button>
        </div>
      </form>

    <?php endif; ?>
  </div>
</section>

<!-- JS FOR ITEM EDITING & MODE CONTROL -->
<script>
function e(str) {
  if (str === null || typeof str === 'undefined') return '';
  const s = String(str);
  return s.replace(/[&<>"']/g, function(m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
  });
}
function money(n) { return '$' + (Number(n) || 0).toFixed(2); }

const poForm         = document.getElementById('poEditForm');
const itemSelect     = document.getElementById('poModalItemSelect');
const qtyInput       = document.getElementById('poModalQtyInput');
const addItemBtn     = document.getElementById('poModalAddItemBtn');
const itemReviewTable = document.getElementById('poItemReviewTable');
const itemReviewTableHead = document.getElementById('poItemReviewTableHead');
const itemEmptyRow   = document.getElementById('poItemEmptyRow');
const itemsJsonInput = document.getElementById('poModalItemsJson');
const poModalItemTotal = document.getElementById('poModalItemTotal');
const poModalSubtotal = document.getElementById('poModalSubtotal');
const poModalTax = document.getElementById('poModalTax');
const poModalGrandTotal = document.getElementById('poModalGrandTotal');
const poModalMeasurementSummary = document.getElementById('poModalMeasurementSummary');
const pageLayout = document.querySelector('.po-details-page');
const editPoBtn = document.getElementById('editPoBtn');
const cancelEditBtn = document.getElementById('cancelEditBtn');
const viewModeButtons = document.getElementById('viewModeButtons');
const editModeButtons = document.getElementById('editModeButtons');
const saveChangesBtn = document.getElementById('saveChangesBtn');
const deletePoBtn = document.getElementById('deletePoBtn');
const receiveDateInput = document.getElementById('receiveDateInput');
const poStatusSelect = document.getElementById('poStatusSelect');

const poData = JSON.parse(document.getElementById('poModalData').textContent || '{}');
const IS_FULLY_EDITABLE = poData.isFullyEditable || false; // Correctly get the flag
let pageMode = 'view';

const allSupplierItems = poData.supplierItems || [];
let currentItemList = JSON.parse(JSON.stringify(poData.currentItems || []));
const originalItemList = JSON.parse(JSON.stringify(poData.currentItems || []));
const originalStatus = poStatusSelect.value;
const originalDate = receiveDateInput.value;
const IS_COMPLETED = poData.isCompleted || false;
// Removed IS_STATUS_LOCKED since we are managing this dynamically
const SST_RATE = poData.sstRate || 0.08;

function updateLiveTotal() {
    if (pageMode !== 'edit') return;
    const selectedOption = itemSelect.selectedOptions[0];
    const qty = parseInt(qtyInput.value, 10) || 0;
    let price = 0;
    if (selectedOption && selectedOption.value) {
        price = parseFloat(selectedOption.dataset.price) || 0;
    }
    poModalItemTotal.textContent = money(qty * price);
}

function populateItemSelect() {
    if (pageMode !== 'edit') return;
    itemSelect.innerHTML = '';
    if (allSupplierItems.length === 0) {
        itemSelect.innerHTML = '<option value="">No items for this supplier</option>';
        itemSelect.disabled = true;
        return;
    }
    itemSelect.innerHTML = '<option value="">Select an item</option>';
    allSupplierItems.forEach(item => {
        if (!currentItemList.find(li => li.item_id == item.item_id)) {
            const opt = document.createElement('option');
            opt.value = item.item_id;
            opt.textContent = `${item.item_name} (${item.item_code}) - ${money(item.unit_cost)} / ${item.measurement}`;
            opt.dataset.name = item.item_name;
            opt.dataset.price = item.unit_cost;
            opt.dataset.itemCode = item.item_code;
            opt.dataset.measurement = item.measurement;
            itemSelect.appendChild(opt);
        }
    });
    itemSelect.disabled = false;
    updateLiveTotal();
}

// Update the visual table of added items
function updateItemReviewTable() {
    itemReviewTable.querySelectorAll('.t-row').forEach(row => row.remove());
    
    // Determine table mode
    if (pageMode === 'edit' && IS_FULLY_EDITABLE) {
        itemReviewTableHead.innerHTML = `
            <div>Item Name</div>
            <div>Qty</div>
            <div>Unit Price</div>
            <div>Total</div>
            <div>Action</div>
        `;
        itemReviewTable.classList.add('edit-mode');
    } else {
        itemReviewTableHead.innerHTML = `
            <div>Item Name</div>
            <div>Qty</div>
            <div>Unit Price</div>
            <div>Total</div>
        `;
        itemReviewTable.classList.remove('edit-mode');
    }

    if (currentItemList.length === 0) {
        itemReviewTable.appendChild(itemEmptyRow);
        itemEmptyRow.style.display = 'grid';
    } else {
        itemEmptyRow.style.display = 'none';
        currentItemList.forEach((item, index) => {
            const row = document.createElement('div');
            row.className = 't-row';
            const itemNameHtml = `
                <div>
                    <span class="item-name-display">${e(item.name)}</span>
                    <span class="item-meta-display">
                        Code: ${e(item.item_code || 'N/A')}<br>
                        Unit: ${e(item.measurement || 'N/A')}
                    </span>
                </div>
            `;

            if (pageMode === 'edit' && IS_FULLY_EDITABLE) {
                row.innerHTML = `
                    ${itemNameHtml}
                    <div style="text-align: right; justify-content: flex-end;">
                        <input type="number" class="po-item-qty-input" value="${e(item.qty)}" data-index="${index}" min="1" style="width: 60px;">
                    </div>
                    <div style="text-align: right; justify-content: flex-end;">${money(item.price)}</div>
                    <div style="text-align: right; justify-content: flex-end;">${money(item.qty * item.price)}</div>
                    <div style="text-align: right; justify-content: flex-end;">
                        <button type="button" class="btn btn-danger" data-index="${index}" style="padding: 4px 10px; font-size: 13px;">Remove</button>
                    </div>
                `;
            } else {
                row.innerHTML = `
                    ${itemNameHtml}
                    <div style="text-align: right; justify-content: flex-end;">${e(item.qty)}</div>
                    <div style="text-align: right; justify-content: flex-end;">${money(item.price)}</div>
                    <div style="text-align: right; justify-content: flex-end;">${money(item.qty * item.price)}</div>
                `;
            }
            itemReviewTable.appendChild(row);
        });
    }
    
    itemsJsonInput.value = JSON.stringify(currentItemList.map(item => ({
        item_id: item.item_id,
        item_code: item.item_code,
        name: item.name,
        measurement: item.measurement,
        qty: item.qty,
        price: item.price
    })));

    // Calculate and display totals
    let subtotal = 0;
    const measurementSummary = {};
    let totalQty = 0;

    currentItemList.forEach(item => {
        const qty = parseInt(item.qty, 10) || 0;
        subtotal += qty * (item.price || 0);
        totalQty += qty;
        const key = item.measurement || 'N/A';
        measurementSummary[key] = (measurementSummary[key] || 0) + qty;
    });
    
    const tax = subtotal * SST_RATE;
    const grandTotal = subtotal + tax;

    poModalSubtotal.textContent = money(subtotal);
    poModalTax.textContent = money(tax);
    poModalGrandTotal.textContent = money(grandTotal);

    const summaryHtml = Object.entries(measurementSummary)
        .map(([key, value]) => `<b>${e(value)}</b> ${e(key)}`)
        .join(', ');
    
    poModalMeasurementSummary.innerHTML = `<div class="po-summary-measurement-items">${summaryHtml || '0 items'}</div>`;
}


// Mode-Switching Functions
function setViewMode() {
    pageMode = 'view';
    document.querySelectorAll('.edit-controls').forEach(el => el.style.display = 'none');
    
    viewModeButtons.style.display = 'flex';
    editModeButtons.style.display = 'none';
    
    // Items and date are never editable in View Mode
    receiveDateInput.disabled = true;

    if (IS_FULLY_EDITABLE) {
        // If it's a 'Created' PO, cancel resets values and locks everything for view
        poStatusSelect.disabled = true; 
        receiveDateInput.value = originalDate;
        poStatusSelect.value = originalStatus;
        currentItemList = JSON.parse(JSON.stringify(originalItemList));
        saveChangesBtn.style.display = 'none';
        if (deletePoBtn) deletePoBtn.style.display = 'inline-flex';
    } else if (IS_COMPLETED) {
        // If it's 'Complete', status is locked and save is hidden
        poStatusSelect.disabled = true;
        saveChangesBtn.style.display = 'none';
        if (deletePoBtn) deletePoBtn.style.display = 'none';
    } else {
        // For all other statuses (Pending, Approved, Confirmed, Shipped, Received, Issue, Delayed):
        // Status dropdown is active and Save button is shown for status change.
        poStatusSelect.disabled = false; 
        saveChangesBtn.style.display = 'inline-flex';
        if (deletePoBtn) deletePoBtn.style.display = 'none';
    }
    
    updateItemReviewTable();
}

function setEditMode() {
    // This mode is only relevant for 'Created' status (IS_FULLY_EDITABLE = true)
    pageMode = 'edit';
    document.querySelectorAll('.edit-controls').forEach(el => el.style.display = 'block');
    
    viewModeButtons.style.display = 'none';
    editModeButtons.style.display = 'flex';
    saveChangesBtn.style.display = 'inline-flex';

    if (deletePoBtn) deletePoBtn.style.display = 'none';

    receiveDateInput.disabled = false;
    poStatusSelect.disabled = false;
    
    populateItemSelect();
    updateItemReviewTable();
}

if (IS_FULLY_EDITABLE) {
    // Only 'Created' POs have the 'Edit PO' button

    if (editPoBtn) {
        editPoBtn.addEventListener('click', setEditMode);
    }

    cancelEditBtn.addEventListener('click', setViewMode);

    addItemBtn.addEventListener('click', () => {
        const selectedOption = itemSelect.selectedOptions[0];
        const itemId = selectedOption.value;
        const qty = parseInt(qtyInput.value, 10);
        
        if (!itemId) { 
          // Replaced alert with console message (as alerts are blocked in the environment)
          console.error('Please select an item.'); 
          return; 
        }
        if (!qty || qty < 1) { 
          console.error('Please enter a valid quantity.'); 
          return; 
        }

        currentItemList.push({
            item_id: itemId,
            item_code: selectedOption.dataset.itemCode,
            name: selectedOption.dataset.name,
            measurement: selectedOption.dataset.measurement,
            qty: qty,
            price: parseFloat(selectedOption.dataset.price)
        });

        qtyInput.value = '1';
        updateItemReviewTable();
        populateItemSelect();
        updateLiveTotal();
    });

    itemReviewTable.addEventListener('click', (e) => {
        if (e.target.classList.contains('btn-danger')) {
            const indexToRemove = parseInt(e.target.dataset.index, 10);
            currentItemList.splice(indexToRemove, 1);
            updateItemReviewTable();
            populateItemSelect();
            updateLiveTotal();
        }
    });
    
    itemReviewTable.addEventListener('input', (e) => {
        if (e.target.classList.contains('po-item-qty-input')) {
            const indexToUpdate = parseInt(e.target.dataset.index, 10);
            const newQty = parseInt(e.target.value, 10) || 1;
            
            if (currentItemList[indexToUpdate]) {
                currentItemList[indexToUpdate].qty = newQty;
                updateItemReviewTable();
            }
        }
    });

    itemSelect.addEventListener('change', updateLiveTotal);
    qtyInput.addEventListener('input', updateLiveTotal);
}

setViewMode();

</script>

<!-- Toast JS -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const toast = document.getElementById('toastPopup');
  if (toast) {
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      const cleanUrl = window.location.href.split('?')[0] + window.location.hash;
      window.history.replaceState(null, '', cleanUrl);
    }
  }
});
</script>
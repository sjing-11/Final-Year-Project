<?php
// views/suppliers/details.php
declare(strict_types=1);

/* --- Load PDO from db.php --- */
$root = dirname(__DIR__, 1);
$loadedPdo = false;
foreach ([
  $root . '/../db.php',
  $root . '/../app/db.php',
  $root . '/../config/db.php',
  $root . '/../../db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error.'); // Simple error for this page
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* --- Load Auth --- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
Auth::check_staff();


// Helper to format money 
if (!function_exists('money_format')) {
  function money_format($val): string {
      // Use number_format for consistency, assuming no locale-specific currency symbol needed here
      return 'RM ' . number_format((float)$val, 2);
  }
}

/* --- Get Supplier ID --- */
$supplier_id = (int)($_GET['id'] ?? 0);
if ($supplier_id === 0) {
  die('No supplier ID provided.');
}

/* --- Fetch Supplier Data --- */
$supplier = null;
$errorMsg = null;
try {
  // SECURITY: Do not select password hash to display on the page
  $stmt = $pdo->prepare("SELECT supplier_id, company_name, contact_person, email, phone, fax, country, state, city, postcode, street_address FROM supplier WHERE supplier_id = :id");
  $stmt->execute([':id' => $supplier_id]);
  $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$supplier) {
    $errorMsg = "Supplier not found.";
  }
} catch (Throwable $ex) {
  $errorMsg = "Error fetching supplier: " . $ex->getMessage();
}

/* --- Fetch PO Data for this Supplier --- */
$po_ongoing = [];
$po_history = [];
$history_statuses = ['Received', 'Completed', 'Rejected']; // Statuses for "History"

if ($supplier) {
  try {
    $sql = "
      SELECT 
        po.po_id,
        po.issue_date,
        po.receive_date,
        po.expected_date,
        po.completion_date,
        po.status,
        SUM(pod.purchase_cost) AS total_order_value,
        COUNT(pod.po_detail_id) AS item_count
      FROM purchase_order po
      LEFT JOIN purchase_order_details pod ON po.po_id = pod.po_id
      WHERE po.supplier_id = :id
      GROUP BY po.po_id
      ORDER BY po.issue_date DESC
    ";
    $stmt_po = $pdo->prepare($sql);
    $stmt_po->execute([':id' => $supplier_id]);
    
    while ($row = $stmt_po->fetch(PDO::FETCH_ASSOC)) {
      if (in_array($row['status'], $history_statuses)) {
        $po_history[] = $row;
      } else {
        $po_ongoing[] = $row;
      }
    }

  } catch (Throwable $ex) {
    // Don't kill the page, just show an error
    $errorMsg = "Error fetching purchase orders: " . $ex->getMessage();
  }
}

/* --- Fetch Associated Products --- */
$products = [];
if ($supplier) {
    try {
        $sql_products = "
            SELECT 
                i.item_id, i.item_code, i.item_name, i.stock_quantity, 
                i.unit_cost, i.selling_price, c.category_name
            FROM item i
            LEFT JOIN category c ON i.category_id = c.category_id
            WHERE i.supplier_id = :id
            ORDER BY i.item_name
        ";
        $stmt_products = $pdo->prepare($sql_products);
        $stmt_products->execute([':id' => $supplier_id]);
        $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        // Don't kill the page, just show an error for products
        $errorMsg = ($errorMsg ? $errorMsg . ' | ' : '') . "Error fetching associated products: " . $ex->getMessage();
    }
}


// Handle form submission status (from a redirect)
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Supplier details have been updated successfully.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update supplier. Please try again.'];
}

?>

<section class="page suppliers-details-page">

  <!-- Toast Message -->
  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
    <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
  </div>
  <?php endif; ?>
  
  <!-- Supplier Details -->
  <div class="card card-soft">
    <div class="page-head">
      <h1>Supplier Details</h1>
      <div class="actions">
        <!-- NEW: View Mode Buttons -->
        <div id="viewModeButtons" style="display: flex; gap: 8px;">

          <?php if (Auth::can('manage_suppliers')): ?>
            <button type="button" id="editSupplierBtn" class="btn btn-primary">Edit Supplier</button>
          <?php endif; ?>

            <a href="/index.php?page=suppliers" class="btn btn-secondary">
              &larr; Back to List
            </a>
        </div>
        
        <!-- Edit Mode Buttons -->
        <div id="editModeButtons" class="edit-controls" style="display: flex; gap: 8px;">
             <button type="button" id="cancelEditBtn" class="btn btn-secondary">Cancel</button>
        </div>
      </div>
    </div>

    <?php if ($errorMsg && !$supplier): // Only show fatal error ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php elseif ($supplier): ?>
      
      <!-- Non-fatal PO / Product error -->
      <?php if ($errorMsg && $supplier): ?>
         <div class="alert error" style="margin:12px 0;">
          <?= e($errorMsg) ?>
        </div>
      <?php endif; ?>

      <!-- Edit Form -->
      <!-- action updated, data-mode added -->
      <form id="supplierEditForm" class="supplier-form" method="post" action="/api/update_supplier.php" autocomplete="off" data-mode="view">
        
        <input type="hidden" name="supplier_id" value="<?= e($supplier['supplier_id']) ?>">
        
        <label>Company Name
          <input type="text" name="supplier_name" value="<?= e($supplier['company_name']) ?>" required disabled>
        </label>
        
        <label>Contact Person
          <input type="text" name="contact_person" value="<?= e($supplier['contact_person']) ?>" disabled>
        </label>
        
        <label>Email
          <input type="email" name="email" value="<?= e($supplier['email']) ?>" disabled>
        </label>
        
        <label>Contact Number
          <input type="text" name="phone" value="<?= e($supplier['phone']) ?>" disabled>
        </label>
        
        <label>Fax
          <input type="text" name="fax" value="<?= e($supplier['fax']) ?>" disabled>
        </label>
        
        <label>Password
          <div style="position:relative;">
            <input type="password" id="passwordField" name="password" placeholder="Leave blank to keep current" autocomplete="new-password" style="padding-right: 44px; width: 100%;" disabled>
            <button type="button" id="togglePassword" aria-label="Show password"
              style="position:absolute; right: 0; top: 50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; width: 40px; height: 40px; display: grid; place-items: center; color: #667085;">
              <!-- Assuming icons are in /images/ folder -->
              <img src="/images/visible.svg" alt="Show password" id="toggleIcon" style="width: 20px; height: 20px;">
            </button>
          </div>
        </label>
        
        <label>Street Address
          <input type="text" name="street_address" value="<?= e($supplier['street_address']) ?>" disabled>
        </label>
        
        <div class="grid-3">
          <label>City
            <input type="text" name="city" value="<?= e($supplier['city']) ?>" disabled>
          </label>
          <label>State
            <input type="text" name="state" value="<?= e($supplier['state']) ?>" disabled>
          </label>
          <label>Postcode
            <input type="text" name="postcode" value="<?= e($supplier['postcode']) ?>" disabled>
          </label>
        </div>
        
        <label>Country
          <input type="text" name="country" value="<?= e($supplier['country']) ?>" disabled>
        </label>

        <div class="btn-row detail-actions" style="margin-top: 20px; justify-content: space-between; gap: 8px;">
          <div>
            <?php if (Auth::can('manage_suppliers')): ?>
              <a href="/api/delete_supplier.php?id=<?= e($supplier['supplier_id']) ?>" 
                id="deleteSupplierBtn"
                class="btn btn-danger" 
                onclick="return confirm('Are you sure you want to delete this supplier? This action cannot be undone.');">
                Delete Supplier
              </a>
            <?php endif; ?>
          </div>

          <button type="submit" form="supplierEditForm" id="saveChangesBtn" class="btn btn-primary edit-controls">
            Save Changes
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>


  <?php if ($supplier): // Only show PO tables if supplier exists ?>

  <!-- Ongoing Purchase Orders -->
  <div class="card">
    <h2 class="table-title">Ongoing Purchase Orders</h2>
    
    <div class="table table-po-supplier-page" id="poOngoingTable">
      <div class="t-head">
        <div>Purchase Order ID</div>
        <div>Order Value</div>
        <div>Item Count</div>
        <div>Expected Delivery Date</div>
        <div>Status</div>
        <div>Actions</div>
      </div>

      <?php
      if ($po_ongoing):
        foreach ($po_ongoing as $r):
          $status = $r['status'] ?? 'Pending';
          $cls = [
            'Delayed'=>'st-delayed',
            'Confirmed'=>'st-confirmed',
            'Approved' => 'st-confirmed',
            'Pending'=>'st-pending',
            'Created' => 'st-pending',
            'Rejected'=>'st-rejected',
            'Received'=>'st-received',
            'Completed' => 'st-received',
          ][$status] ?? 'st-pending';
      ?>
      <div class="t-row" data-po-id="<?= e($r['po_id']) ?>">
        <div>PO-<?= e($r['po_id']) ?></div>
        <div><?= e(money_format($r['total_order_value'] ?? 0)) ?></div>
        <div><?= e($r['item_count'] ?? 0) ?></div>
        <div><?= e(date('d/m/Y', strtotime($r['expected_date']))) ?></div>
        <div><span class="po-status <?= $cls ?>"><?= e($status) ?></span></div>
        <div>
          <a href="/index.php?page=po_details&id=<?= e($r['po_id']) ?>" class="btn btn-secondary slim">
            More Details
          </a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
        <div class="t-row">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
            No ongoing purchase orders found for this supplier.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>


  <!-- Purchase Order History -->
  <div class="card">
    <h2 class="table-title">Purchase Order History</h2>
    
    <div class="table table-po-supplier-page" id="poHistoryTable">
      <div class="t-head">
        <div>Purchase Order ID</div>
        <div>Order Value</div>
        <div>Item Count</div>
        <div>Received On</div>
        <div>Status</div>
        <div>Actions</div>
      </div>

      <?php
      if ($po_history):
        foreach ($po_history as $r):
          $status = $r['status'] ?? 'Pending';
          $cls = [
            'Delayed'=>'st-delayed',
            'Confirmed'=>'st-confirmed',
            'Approved' => 'st-confirmed',
            'Pending'=>'st-pending',
            'Created' => 'st-pending',
            'Rejected'=>'st-rejected',
            'Received'=>'st-received',
            'Completed' => 'st-received',
          ][$status] ?? 'st-pending';
          
          $date_to_show = in_array($r['status'], ['Received', 'Completed']) 
            ? ($r['receive_date'] ?? $r['expected_date']) 
            : ($r['expected_date'] ?? $r['issue_date']);
      ?>
      <div class="t-row" data-po-id="<?= e($r['po_id']) ?>">
        <div>PO-<?= e($r['po_id']) ?></div>
        <div><?= e(money_format($r['total_order_value'] ?? 0)) ?></div>
        <div><?= e($r['item_count'] ?? 0) ?></div>
        <div>
            <!-- Show the actual received date if set -->
            <?= e(date('d/m/Y', strtotime($r['receive_date'] ?? $r['expected_date']))) ?>
        </div>
        <div><span class="po-status <?= $cls ?>"><?= e($status) ?></span></div>
        <div>
          <a href="/index.php?page=po_details&id=<?= e($r['po_id']) ?>" class="btn btn-secondary slim">
            More Details
          </a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
        <div class="t-row">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
            No purchase order history found for this supplier.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- Associated Products  -->
  <div class="card">
    <h2 class="table-title">Associated Products</h2>
    
    <div class="table table-associated-products" id="productTable">
      <div class="t-head">
        <div>Item Code</div>
        <div>Item Name</div>
        <div>Category</div>
        <div>Stock Qty</div>
        <div>Unit Cost</div>
        <div>Actions</div>
      </div>

      <?php if ($products): ?>
        <?php foreach ($products as $p): ?>
        <div class="t-row" data-item-id="<?= e($p['item_id']) ?>">
          <div><?= e($p['item_code']) ?></div>
          <div><?= e($p['item_name']) ?></div>
          <div><?= e($p['category_name'] ?? 'N/A') ?></div>
          <div><?= e($p['stock_quantity'] ?? 0) ?></div>
          <div><?= e(money_format($p['unit_cost'] ?? 0)) ?></div>
          <div>
            <a href="/index.php?page=item_details&id=<?= e($p['item_id']) ?>" class="btn btn-secondary slim">
              View Item
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="t-row">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
            No associated products found for this supplier.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  
  <?php endif; // End if($supplier) ?>

</section>

<!-- VIEW/EDIT MODE & PASSWORD SCRIPT -->
<script>
// --- Password visibility toggle ---
const passwordField = document.getElementById('passwordField');
const togglePassword = document.getElementById('togglePassword');
const toggleIcon = document.getElementById('toggleIcon');
// Define the paths to your SVG icons (relative to the /public root)
const iconVisible = '/images/visible.svg';
const iconInvisible = '/images/invisible.svg';

togglePassword?.addEventListener('click', () => {
  const isPassword = passwordField.type === 'password';
  
  if (isPassword) {
    // Change to TEXT (make visible)
    passwordField.type = 'text';
    toggleIcon.src = iconInvisible; // Show 'invisible' (eye-slashed) icon
    toggleIcon.alt = 'Hide password';
  } else {
    // Change to PASSWORD (make invisible)
    passwordField.type = 'password';
    toggleIcon.src = iconVisible; // Show 'visible' (eye) icon
    toggleIcon.alt = 'Show password';
  }
});

// --- View/Edit Mode ---
document.addEventListener('DOMContentLoaded', () => {
  // --- Get all elements ---
  const supplierForm = document.getElementById('supplierEditForm');
  if (!supplierForm) return; // Exit if form not found

  const editSupplierBtn = document.getElementById('editSupplierBtn');
  const cancelEditBtn = document.getElementById('cancelEditBtn');
  const viewModeButtons = document.getElementById('viewModeButtons');
  const editModeButtons = document.getElementById('editModeButtons');
  const saveChangesBtn = document.getElementById('saveChangesBtn');
  const deleteSupplierBtn = document.getElementById('deleteSupplierBtn');
  const formInputs = supplierForm.querySelectorAll('input, select');

  // --- Functions ---
  function setViewMode() {
    supplierForm.dataset.mode = 'view'; // Set data-mode for CSS styling
    
    // Hide edit buttons, show view buttons
    viewModeButtons.style.display = 'flex';
    editModeButtons.style.display = 'none';
    saveChangesBtn.style.display = 'none';
    if (deleteSupplierBtn) deleteSupplierBtn.style.display = 'inline-flex';

    // Disable all fields
    formInputs.forEach(input => {
      // *** Keep the hidden supplier_id enabled ***
      if (input.type === 'hidden' && input.name === 'supplier_id') {
          input.disabled = false;
      } else {
          input.disabled = true;
      }
    });

    // Reset form to original values loaded by PHP
    supplierForm.reset();
    
    // Specific fix for password field 
    if (passwordField) {
      passwordField.type = 'password';
      passwordField.placeholder = 'Leave blank to keep current';
      if (toggleIcon) {
        toggleIcon.src = iconVisible;
        toggleIcon.alt = 'Show password';
      }
    }
  }

  function setEditMode() {
    supplierForm.dataset.mode = 'edit'; 

    // Show edit buttons, hide view buttons
    viewModeButtons.style.display = 'none';
    editModeButtons.style.display = 'flex';
    saveChangesBtn.style.display = 'inline-flex';
    if (deleteSupplierBtn) deleteSupplierBtn.style.display = 'none';

    // Enable all fields
    formInputs.forEach(input => {
      input.disabled = false;
    });
    
    // Focus the first input
    supplierForm.querySelector('input[name="supplier_name"]')?.focus();
  }

  // --- Attach Listeners ---
  editSupplierBtn?.addEventListener('click', setEditMode);
  cancelEditBtn?.addEventListener('click', setViewMode);
  
  // --- Initial Page Load ---
  setViewMode();

  // --- Toast Popup Autoclose ---
  const toast = document.getElementById('toastPopup');
  if (toast) {
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      // Clean the URL of ?status=...
      const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?id=' + <?= (int)$supplier_id ?>;
      window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
  }
});
</script>
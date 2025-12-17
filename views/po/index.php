<?php
// views/po/index.php
declare(strict_types=1);

// Load PDO from db.php
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
  die('<div style="padding:16px;color:#b00020;background:#fff0f1;border:1px solid #ffd5da;border-radius:8px;">
        Could not load <code>db.php</code> or <code>$pdo</code>.
      </div>');
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Load Auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
Auth::check_staff(['view_po_list']);

// Helper to format money
function money_format($val): string {
    return '$' . number_format((float)$val, 2);
}

// Handle form submission status (from a redirect)
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'added') {
  $statusMsg = ['ok', 'Purchase Order created successfully.'];
} elseif ($status === 'deleted') {
  $statusMsg = ['ok', 'Purchase Order deleted successfully.'];
} elseif ($status === 'delete_restricted') {
  $statusMsg = ['error', 'That order cannot be deleted, it may already be processed.'];
} elseif ($status === 'delete_error') {
  $statusMsg = ['error', 'Could not delete the purchase order.'];
}

// 1. Fetch and Parse SST Rate
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


// 2. Fetch KPI Data
$kpis = [
  'total_orders' => 0,
  'total_received' => 0,
  'total_returned' => 0, // 'Rejected'
  'on_the_way' => 0, // 'Created', 'Approved', 'Confirmed', 'Delayed'
];
try {
  // This is a more efficient way to get all counts in one query
  $kpi_sql = "
    SELECT
      COUNT(*) AS total_orders,
      SUM(CASE WHEN status IN ('Received', 'Completed') THEN 1 ELSE 0 END) AS total_received,
      SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS total_returned,
      SUM(CASE WHEN status IN ('Created', 'Pending', 'Approved', 'Confirmed', 'Delayed', 'Shipped') THEN 1 ELSE 0 END) AS on_the_way
    FROM purchase_order
  ";
  $kpi_stmt = $pdo->query($kpi_sql);
  if ($kpi_stmt) {
    $kpi_data = $kpi_stmt->fetch(PDO::FETCH_ASSOC);
    if ($kpi_data) {
      $kpis['total_orders'] = $kpi_data['total_orders'] ?? 0;
      $kpis['total_received'] = $kpi_data['total_received'] ?? 0;
      $kpis['total_returned'] = $kpi_data['total_returned'] ?? 0;
      $kpis['on_the_way'] = $kpi_data['on_the_way'] ?? 0;
    }
  }
} catch (Throwable $e) {
  // KPIs failing isn't critical, but we can log it
  error_log("KPI Query Failed: " . $e->getMessage());
}

// Auto update 'Delayed' status for late POs
// This script runs every time the PO list is loaded
try {
    // Define which statuses are considered "in-progress"
    $active_statuses = ['Approved', 'Confirmed', 'Shipped'];

    $sql_delay = "
        UPDATE purchase_order
        SET status = 'Delayed'
        WHERE status IN (?, ?, ?)
          AND expected_date < CURDATE();
    ";
    
    $stmt_delay = $pdo->prepare($sql_delay);
    $stmt_delay->execute($active_statuses);

} catch (Throwable $e) {
    // This is not critical. Don't crash the page, just log the error.
    error_log("Auto-delay trigger failed: " . $e->getMessage());
}

// 3. Fetch PO List Data (Main Table)
$errorMsg = null;
$rows = [];
try {
  // This query now groups by PO, gets total value, item count, and supplier name
  $sql = "
    SELECT 
      po.po_id,
      po.issue_date,
      po.receive_date,
      po.expected_date,
      po.status,
      po.supplier_id, 
      s.company_name AS supplier_name,
      SUM(pod.purchase_cost) AS total_order_value,
      COUNT(pod.po_detail_id) AS item_count
    FROM purchase_order po
    LEFT JOIN purchase_order_details pod ON po.po_id = pod.po_id
    LEFT JOIN supplier s ON po.supplier_id = s.supplier_id
    GROUP BY po.po_id
    ORDER BY po.issue_date DESC, po.po_id DESC
  ";
  $stmt = $pdo->query($sql);
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

} catch (Throwable $ex) {
  $errorMsg = $ex->getMessage();
}

// 4. Fetch Data for Modal Dropdowns and Filters
$suppliers = [];
$items_by_supplier = [];
$filter_suppliers = []; // For the filter dropdown
try {
    // 1. Get all suppliers
    $supplier_stmt = $pdo->query("SELECT supplier_id, company_name FROM supplier ORDER BY company_name");
    while ($s = $supplier_stmt->fetch(PDO::FETCH_ASSOC)) {
        $suppliers[] = $s; // For modal
        $filter_suppliers[$s['supplier_id']] = $s['company_name']; // For filter
    }

    // 2. Get all items, grouped by supplier_id
    $item_sql = "
      SELECT supplier_id, item_id, item_name, unit_cost, measurement 
      FROM item 
      WHERE supplier_id IS NOT NULL 
      ORDER BY item_name
    ";
    $item_stmt = $pdo->query($item_sql);
    while ($item = $item_stmt->fetch(PDO::FETCH_ASSOC)) {
        $items_by_supplier[$item['supplier_id']][] = $item;
    }

} catch (Throwable $e) {
    $errorMsg = "Failed to load data for modal: " . $e->getMessage();
}

// 5. Define filter options
$all_status_options = ['Created', 'Pending', 'Approved', 'Confirmed', 'Delayed', 'Shipped', 'Received', 'Rejected', 'Completed'];
$history_statuses = ['Received', 'Completed', 'Rejected'];

?>
<section class="page po-page">

  <!-- Toast Popup -->
  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
    <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
  </div>
  <?php endif; ?>

  <!-- Overall Purchase Orders -->
  <div class="card kpi-card">
    <h2 class="kpi-title">Overall Purchase Orders</h2>
    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-head kpi-blue">Total Orders</div>
        <div class="kpi-num"><?= e($kpis['total_orders']) ?></div>
        <div class="kpi-sub">All time</div>
      </div>

      <div class="kpi">
        <div class="kpi-head kpi-orange">Total Received</div>
        <div class="kpi-num"><?= e($kpis['total_received']) ?></div>
        <div class="kpi-sub"><span class="muted">Completed</span></div>
      </div>

      <div class="kpi">
        <div class="kpi-head kpi-purple">Total Returned</div>
        <div class="kpi-num"><?= e($kpis['total_returned']) ?></div>
        <div class="kpi-sub"><span class="muted">Rejected</span></div>
      </div>

      <div class="kpi">
        <div class="kpi-head kpi-red">On the way</div>
        <div class="kpi-num"><?= e($kpis['on_the_way']) ?></div>
        <div class="kpi-sub"><span class="muted">Processing</span></div>
      </div>
    </div>
  </div>

  <!-- Orders table -->
  <div class="card">
    <div class="page-head">
      <h2 class="table-title">Orders</h2>
      <div class="actions">
        <?php if (Auth::can('create_po')): ?>
          <a href="#" id="openPOModalBtn" class="btn btn-primary">New Purchase Order</a>
        <?php endif; ?>
        
        <!-- Filter Dropdown -->
        <div class="filter-dropdown">
          <button class="btn btn-secondary"><span class="btn-ico">≡</span> <span id="filterButtonText">Filters</span></button>
          <div class="filter-dropdown-content">
            
            <a class="filter-option" data-filter-by="all" data-filter-value="">Show All</a>

            <div class="filter-header">By Category</div>
            <a class="filter-option" data-filter-by="category" data-filter-value="ongoing">Ongoing</a>
            <a class="filter-option" data-filter-by="category" data-filter-value="history">History</a>
            
            <div class="filter-header">By Status</div>
            <?php foreach ($all_status_options as $stat): ?>
              <a class="filter-option" data-filter-by="status" data-filter-value="<?= e(strtolower($stat)) ?>">
                <?= e($stat) ?>
              </a>
            <?php endforeach; ?>

            <?php if (!empty($filter_suppliers)): ?>
              <div class="filter-header">By Supplier</div>
              <?php foreach ($filter_suppliers as $id => $name): ?>
                <a class="filter-option" data-filter-by="supplier" data-filter-value="<?= e($id) ?>">
                  <?= e($name) ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

          </div>
        </div>

      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <strong>Database Error:</strong> <?= e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <div class="table table-po" id="poTable">
      <div class="t-head">
        <div>Purchase Order ID</div>
        <div>Supplier</div>
        <div>Order Value</div>
        <div>Item Count</div>
        <div>Expected Delivery Date</div>
        <div>Status</div>
        <div>Actions</div>
      </div>

      <?php
      if ($rows):
        foreach ($rows as $r):
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
          
          // Add data for filtering
          $category = in_array($status, $history_statuses) ? 'history' : 'ongoing';
      ?>
      <!-- Added data- attributes for JS filtering -->
      <div class="t-row" 
           data-po-id="<?= e($r['po_id']) ?>"
           data-status="<?= e(strtolower($status)) ?>"
           data-supplier="<?= e($r['supplier_id']) ?>"
           data-category="<?= e($category) ?>"
           >
        <div>PO-<?= e($r['po_id']) ?></div>
        <div><?= e($r['supplier_name'] ?? 'N/A') ?></div>
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
        <div class="t-row" id="noResultsRow">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
            No purchase orders found.
          </div>
        </div>
      <?php endif; ?>

      <!-- Row for when filters find no results -->
      <div class="t-row" id="filterNoResultsRow" style="display: none;">
        <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
          No purchase orders match the current filter.
        </div>
      </div>

    </div>

    <!-- Pager -->
    <div class="pager-rail">
      <div class="left"><button class="btn btn-secondary" id="prevPageBtn" disabled>Previous</button></div>
      <div class="mid"><span class="page-note" id="pageNote">Page 1 of 1</span></div>
      <div class="right"><button class="btn btn-secondary" id="nextPageBtn" disabled>Next</button></div>
    </div>
  </div>
</section>

<!-- ===== New Purchase Order Modal (hidden by default) ===== -->
<!-- (Modal HTML unchanged) -->
<div class="overlay" id="poModal" aria-modal="true" role="dialog" aria-hidden="true">
  <div class="modal po-modal" role="document" aria-labelledby="poModalTitle">
    <!-- Sticky Header -->
    <div class="modal-head">
      <h2 id="poModalTitle">New Purchase Order</h2>
      <button class="modal-x" id="closePOModal" aria-label="Close">×</button>
    </div>

    <!-- Scrollable Body -->
    <div class="modal-body">
      <!-- Status message for AJAX errors -->
      <div id="poModalStatus" class="alert error" style="display:none;" aria-live="polite"></div>

      <form id="poForm" class="supplier-form" method="post" action="api/add_po.php" autocomplete="off">
        
        <div class="po-details-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 14px;">
            <label>Supplier
                <select name="supplier_id" id="poModalSupplierSelect" required>
                    <option value="">Select a supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= e($s['supplier_id']) ?>"><?= e($s['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Expected Delivery Date
                <input type="date" name="expected_date" id="poModalDeliveryDate" required>
            </label>
        </div>

        <h3 style="margin-top: 20px; margin-bottom: 8px; border-top: 1px solid #e5eaf2; padding-top: 12px;">Add Items to PO</h3>
        
        <!-- Row to add new items -->
        <div class="po-item-add-row" style="display: grid; grid-template-columns: 1fr 80px auto auto; gap: 14px; align-items: end; margin-bottom: 14px;">
            <label>Item
                <select id="poModalItemSelect">
                    <option value="">Select a supplier first</option>
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

        <h4 style="margin-top: 16px; margin-bottom: 4px;">Order Items</h4>
        <!-- Table to review added items -->
        <div class="table edit-mode" id="poItemReviewTable" style="margin-top: 4px;">
            <div class="t-head">
                <div>Item Name</div>
                <div>Qty</div>
                <div>Unit Price</div>
                <div>Total</div>
                <div>Action</div>
            </div>
            <!-- Rows will be added here by JS -->
            <div class="t-row" id="poItemEmptyRow">
                <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
                    No items added yet.
                </div>
            </div>
        </div>
        
        <div class="po-summary" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5eaf2;">
            <div class="po-summary-row">
                <span>Subtotal</span>
                <span id="poModalSubtotal">$0.00</span>
            </div>
            <!-- Make SST label dynamic -->
            <div class="po-summary-row">
                <span>SST (<?= e($sst_rate * 100) ?>%)</span>
                <span id="poModalTax">$0.00</span>
            </div>
            <div class="po-summary-row po-summary-total">
                <span>Grand Total</span>
                <span id="poModalGrandTotal">$0.00</span>
            </div>
        </div>
        
        <!-- Hidden input to pass all item data to the server -->
        <input type="hidden" name="items_json" id="poModalItemsJson" value="[]">

        <!-- Pass data as a structured object -->
        <script id="poModalData" type="application/json">
            {
                "items": <?= json_encode($items_by_supplier, JSON_UNESCAPED_UNICODE) ?>,
                "sstRate": <?= json_encode($sst_rate) ?>
            }
        </script>
      </form>
    </div>

    <!-- Sticky Footer -->
    <div class="modal-foot">
      <div class="btn-row">
        <button type="button" class="btn btn-secondary" id="cancelPOBtn">Discard</button>
        <button type="submit" form="poForm" class="btn btn-primary" id="submitPOBtn">Create Purchase Order</button>
      </div>
    </div>
  </div>
</div>

<script>
// --- PAGINATION, FILTER, & TOAST SCRIPT ---
document.addEventListener('DOMContentLoaded', () => {

  // --- Toast Popup Autoclose ---
  const toast = document.getElementById('toastPopup');
  if (toast) {
    // Auto-hide after 4 seconds
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    // Auto-clear the '?status=' from URL
    if (window.history.replaceState) {
      const cleanUrl = window.location.href.split('?')[0] + window.location.hash;
      window.history.replaceState(null, '', cleanUrl);
    }
  }

  // Configuration 
  const ITEMS_PER_PAGE = 5; 

  let currentPage = 1;
  let currentFilterBy = 'all';
  let currentFilterValue = '';

  // Cache DOM elements
  const allTableRows = Array.from(document.querySelectorAll('#poTable .t-row[data-po-id]'));
  const noResultsRow = document.getElementById('noResultsRow'); // The "No POs" row
  const filterNoResultsRow = document.getElementById('filterNoResultsRow'); // The "Filter" no results row
  const pageNote = document.getElementById('pageNote');
  const prevPageBtn = document.getElementById('prevPageBtn');
  const nextPageBtn = document.getElementById('nextPageBtn');
  const filterButtonText = document.getElementById('filterButtonText');
  const filterLinks = document.querySelectorAll('.filter-option');
  const filterDropdown = document.querySelector('.filter-dropdown');

  function updateTableDisplay() {
    // 1. Get all rows that match the filter
    const visibleRows = allTableRows.filter(row => {
      if (currentFilterBy === 'all') return true;
      if (!row.dataset[currentFilterBy]) return false;
      return row.dataset[currentFilterBy].toLowerCase() === currentFilterValue;
    });

    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1; 

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    // Hide all rows
    allTableRows.forEach(row => row.style.display = 'none');
    if (noResultsRow) noResultsRow.style.display = 'none'; // Hide the PHP "no results" row
    if (filterNoResultsRow) filterNoResultsRow.style.display = 'none'; // Hide the JS "no results" row

    // 2. Decide which "no results" row to show
    if (allTableRows.length === 0) {
        // If PHP rendered no rows at all
        if (noResultsRow) noResultsRow.style.display = 'grid'; 
    } else if (totalItems === 0) {
        // If PHP rendered rows, but the filter found none
        if (filterNoResultsRow) filterNoResultsRow.style.display = 'grid';
    } else {
      // 3. Show the paginated rows
      const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
      const endIndex = startIndex + ITEMS_PER_PAGE;
      const rowsToShow = visibleRows.slice(startIndex, endIndex);
      rowsToShow.forEach(row => row.style.display = 'grid');
    }

    // 4. Update pagination controls
    if (pageNote) pageNote.textContent = `Page ${currentPage} of ${totalPages}`;
    if (prevPageBtn) prevPageBtn.disabled = (currentPage === 1);
    if (nextPageBtn) nextPageBtn.disabled = (currentPage === totalPages);
  }

  // Attach Event Listeners 
  filterLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      currentFilterBy = link.dataset.filterBy;
      currentFilterValue = link.dataset.filterValue.toLowerCase();
      currentPage = 1; 
      const filterText = link.textContent.trim();
      if (filterButtonText) {
        filterButtonText.textContent = (currentFilterBy === 'all') ? 'Filters' : filterText;
      }
      updateTableDisplay();
      if (filterDropdown) filterDropdown.blur(); // Close the dropdown
    });
  });

  prevPageBtn?.addEventListener('click', () => {
    if (currentPage > 1) {
      currentPage--;
      updateTableDisplay();
    }
  });

  nextPageBtn?.addEventListener('click', () => {
    // Re-calculate totalPages in case it changed
    const visibleRows = allTableRows.filter(row => {
      if (currentFilterBy === 'all') return true;
      if (!row.dataset[currentFilterBy]) return false;
      return row.dataset[currentFilterBy].toLowerCase() === currentFilterValue;
    });
    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1; 

    if (currentPage < totalPages) {
      currentPage++;
      updateTableDisplay();
    }
  });

  // 3. Initial table load
  updateTableDisplay();
});


// --- NEW PURCHASE ORDER MODAL SCRIPT ---
function e(str) {
  if (str === null || typeof str === 'undefined') return '';
  const s = String(str); // Convert numbers, etc., to string
  return s.replace(/[&<>"']/g, function(m) {
    return {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[m];
  });
}

// --- Get all modal elements ---
const openPOModalBtn = document.getElementById('openPOModalBtn');
const poOverlay      = document.getElementById('poModal');
const closePOModal   = document.getElementById('closePOModal');
const cancelPOBtn    = document.getElementById('cancelPOBtn');
const poForm         = document.getElementById('poForm');
const submitPOBtn    = document.getElementById('submitPOBtn');
const poModalStatus  = document.getElementById('poModalStatus');

// --- Form elements ---
const supplierSelect = document.getElementById('poModalSupplierSelect');
const itemSelect     = document.getElementById('poModalItemSelect');
const qtyInput       = document.getElementById('poModalQtyInput');
const addItemBtn     = document.getElementById('poModalAddItemBtn');
const itemReviewTable = document.getElementById('poItemReviewTable');
const itemEmptyRow   = document.getElementById('poItemEmptyRow');
const itemsJsonInput = document.getElementById('poModalItemsJson');
// *** FIX 1: Rename variable to reflect the input ID ***
const expectedDateInput = document.getElementById('poModalDeliveryDate');
const poModalItemTotal = document.getElementById('poModalItemTotal');

// --- Get new summary elements ---
const poModalSubtotal = document.getElementById('poModalSubtotal');
const poModalTax = document.getElementById('poModalTax');
const poModalGrandTotal = document.getElementById('poModalGrandTotal');

// --- Load data ---
// --- Read structured data from JSON ---
const poModalData = JSON.parse(document.getElementById('poModalData').textContent || '{}');
const allItemsBySupplier = poModalData.items || {};
const SST_RATE = poModalData.sstRate || 0.08; // Get SST rate from JSON
let currentItemList = []; // This array will hold { item_id, name, qty, price }

// --- Helper functions ---
function money(n) { return '$' + (Number(n) || 0).toFixed(2); }
function showModalError(msg) {
    if(poModalStatus) {
        poModalStatus.textContent = msg;
        poModalStatus.style.display = 'block';
    }
}
function hideModalError() {
    if(poModalStatus) poModalStatus.style.display = 'none';
}

// --- Modal Open/Close Logic ---
openPOModalBtn.addEventListener('click', (e)=> {
  e.preventDefault();
  poOverlay.classList.add('visible');
  poOverlay.removeAttribute('aria-hidden');
  // Set default date to 7 days from now
  const nextWeek = new Date();
  nextWeek.setDate(nextWeek.getDate() + 7);
  // Use the correctly renamed variable 
  expectedDateInput.value = nextWeek.toISOString().split('T')[0];
});
function closePOModalFn(){
  poOverlay.classList.remove('visible');
  poOverlay.setAttribute('aria-hidden','true');
  // Reset form on close
  poForm.reset();
  currentItemList = [];
  updateItemReviewTable(); // This will also re-enable the supplierSelect
  populateItemSelect();
  hideModalError();
  // Explicitly unlock supplier select visuals
  supplierSelect.style.pointerEvents = 'auto';
  supplierSelect.style.backgroundColor = '#fff';
  // Reset totals
  poModalSubtotal.textContent = '$0.00';
  poModalTax.textContent = '$0.00';
  poModalGrandTotal.textContent = '$0.00';
}
closePOModal.addEventListener('click', closePOModalFn);
cancelPOBtn.addEventListener('click', closePOModalFn);
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closePOModalFn(); });
poOverlay.addEventListener('click', (e)=>{ if(e.target===poOverlay) closePOModalFn(); });
poOverlay.querySelector('.modal').addEventListener('click', (e)=> e.stopPropagation());

// --- Core Modal Logic ---

// Update live total
function updateLiveTotal() {
    const selectedOption = itemSelect.selectedOptions[0];
    const qty = parseInt(qtyInput.value, 10) || 0;
    let price = 0;
    if (selectedOption && selectedOption.value) {
        price = parseFloat(selectedOption.dataset.price) || 0;
    }
    poModalItemTotal.textContent = money(qty * price);
}

// 1. When supplier changes, update the item dropdown
function populateItemSelect() {
    const supplierId = supplierSelect.value;
    const items = allItemsBySupplier[supplierId] || [];
    
    itemSelect.innerHTML = ''; // Clear old items
    if (!supplierId) {
        itemSelect.innerHTML = '<option value="">Select a supplier first</option>';
        itemSelect.disabled = true;
        return;
    }
    
    if (items.length === 0) {
        itemSelect.innerHTML = '<option value="">No items for this supplier</option>';
        itemSelect.disabled = true;
        return;
    }

    itemSelect.innerHTML = '<option value="">Select an item</option>';
    items.forEach(item => {
        // Only add item if it's NOT already in the list
        if (!currentItemList.find(li => li.item_id == item.item_id)) {
            const opt = document.createElement('option');
            opt.value = item.item_id;
            opt.textContent = `${item.item_name} (${money(item.unit_cost)} / ${item.measurement})`;
            opt.dataset.name = item.item_name;
            opt.dataset.price = item.unit_cost;
            itemSelect.appendChild(opt);
        }
    });
    itemSelect.disabled = false;
    updateLiveTotal(); // Update total when items are populated
}

// 2. When "Add Item" is clicked
addItemBtn.addEventListener('click', () => {
    const selectedOption = itemSelect.selectedOptions[0];
    const itemId = selectedOption.value;
    const qty = parseInt(qtyInput.value, 10);

    if (!itemId) { alert('Please select an item.'); return; }
    if (!qty || qty < 1) { alert('Please enter a valid quantity.'); return; }

    // Add to our list
    currentItemList.push({
        item_id: itemId,
        name: selectedOption.dataset.name,
        qty: qty,
        price: parseFloat(selectedOption.dataset.price)
    });

    // Reset inputs
    qtyInput.value = '1';
    
    // Refresh table and item dropdown (to remove the item we just added)
    updateItemReviewTable();
    populateItemSelect();
    updateLiveTotal(); // Reset live total
});

// 3. Update the visual table of added items
function updateItemReviewTable() {
    // Clear all but the header
    itemReviewTable.querySelectorAll('.t-row').forEach(row => row.remove());
    
    if (currentItemList.length === 0) {
        itemReviewTable.appendChild(itemEmptyRow);
    } else {
        currentItemList.forEach((item, index) => {
            const row = document.createElement('div');
            row.className = 't-row';
            row.innerHTML = `
                <div>${e(item.name)}</div>
                <div>${e(item.qty)}</div>
                <div>${money(item.price)}</div>
                <div>${money(item.qty * item.price)}</div>
                <div>
                    <button type="button" class="btn btn-danger" data-index="${index}">Remove</button>
                </div>
            `;
            itemReviewTable.appendChild(row);
        });
    }
    // Update the hidden JSON input
    itemsJsonInput.value = JSON.stringify(currentItemList.map(item => ({
        item_id: item.item_id,
        qty: item.qty,
        price: item.price
    })));

    // --- Calculate and display totals ---
    let subtotal = 0;
    currentItemList.forEach(item => {
        subtotal += item.qty * item.price;
    });
    
    // --- Read from global const ---
    const tax = subtotal * SST_RATE; 
    const grandTotal = subtotal + tax;

    poModalSubtotal.textContent = money(subtotal);
    poModalTax.textContent = money(tax);
    poModalGrandTotal.textContent = money(grandTotal);


    // LOCK/UNLOCK SUPPLIER SELECT 
    if (currentItemList.length > 0) {
        supplierSelect.style.pointerEvents = 'none';
        supplierSelect.style.backgroundColor = '#f3f4f6';
    } else {
        supplierSelect.style.pointerEvents = 'auto';
        supplierSelect.style.backgroundColor = '#fff';
    }
}

// 4. Handle "Remove" button clicks in the review table
itemReviewTable.addEventListener('click', (e) => {
    if (e.target.classList.contains('btn-danger')) {
        const indexToRemove = parseInt(e.target.dataset.index, 10);
        currentItemList.splice(indexToRemove, 1); // Remove from array
        updateItemReviewTable();
        populateItemSelect(); // Re-populate dropdown, as an item is now available
        updateLiveTotal();
    }
});

// 5. Handle Form Submission
poForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideModalError();

    // --- Validation ---
    if (!supplierSelect.value) {
        showModalError('Please select a supplier.'); return;
    }
    // Use the correctly renamed variable in validation 
    if (!expectedDateInput.value) {
        showModalError('Please select an expected delivery date.'); return;
    }
    
    // Check the hidden input's value instead of the array
    // This was the source of the bug
    if (itemsJsonInput.value === '[]') {
        showModalError('Please add at least one item to the order.'); return;
    }

    // --- Submit ---
    submitPOBtn.disabled = true;
    submitPOBtn.textContent = 'Creating...';

    try {
        // --- Build FormData manually ---
        const formData = new FormData();
        formData.append('supplier_id', supplierSelect.value);
        // Use the correct variable name in submission 
        formData.append('expected_date', expectedDateInput.value); 
        formData.append('items_json', itemsJsonInput.value); // This reads the CURRENT property

        const res = await fetch(poForm.action, {
            method: 'POST',
            body: formData
        });
        // --- End ---\r\n
        const json = await res.json().catch(() => ({}));

        if (res.ok && json.status === 'success') {
            // Success! Reload the page with a status
            window.location.href = '/index.php?page=po&status=added';
        } else {
            // Show server-side error
            showModalError(json.message || 'An unknown error occurred.');
        }

    } catch (err) {
        console.error('Submission Error:', err);
        showModalError('A network error occurred. Please try again.');
    } finally {
        submitPOBtn.disabled = false;
        submitPOBtn.textContent = 'Create Purchase Order';
    }
});


// --- Initial setup ---
supplierSelect.addEventListener('change', () => {
    populateItemSelect();
    updateLiveTotal();
});
itemSelect.addEventListener('change', updateLiveTotal);
qtyInput.addEventListener('input', updateLiveTotal);

// Set default date
const nextWeek = new Date();
nextWeek.setDate(nextWeek.getDate() + 7);
// *** FIX 5: Use the correct variable name for setting the default date ***
expectedDateInput.valueAsDate = nextWeek;
// Initial call
populateItemSelect();

</script>

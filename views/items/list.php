<?php
// views/items/list.php
declare(strict_types=1);

/* --- Load PDO from db.php*/
$PROJECT_ROOT = dirname(__DIR__, 2);
$loadedPdo = false;

// Search for the db.php file
foreach (
    [
        $PROJECT_ROOT . '/db.php',
        $PROJECT_ROOT . '/app/db.php',
        $PROJECT_ROOT . '/config/db.php',
    ] as $maybe
) {
    if (is_file($maybe)) {
        require_once $maybe;
        $loadedPdo = true;
        break;
    }
}

if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="padding:16px;color:#b00020;background:#fff0f1;border:1px solid #ffd5da;border-radius:8px;">
        Could not load <code>db.php</code> or <code>$pdo</code>.
    </div>');
}


if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

require_once $PROJECT_ROOT . '/app/Auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
Auth::check_staff();

/* --------------------------------------
    --- Fetch Item & KPI Data from DB ---
    -------------------------------------- */
$errorMsg = null;
$rows = []; // Item list data
$kpis = []; // Key Performance Indicators data
$categories_data = []; // Stores Category ID and Name for modal/filters

try {
    // 1. Fetch Item Data (MODIFIED: Use i.category_id and JOIN to get category_name)
    $sql_items = "
    SELECT 
      i.item_id, i.item_code, i.item_name, i.unit_cost, i.selling_price, 
      i.stock_quantity, i.threshold_quantity, i.expiry_date, i.measurement, i.uom, i.brand,
      c.category_name AS category, /* Fetch category name for display */
      s.company_name AS supplier_name
    FROM item i
    LEFT JOIN category c ON c.category_id = i.category_id /* Join category table */
    LEFT JOIN supplier s ON s.supplier_id = i.supplier_id
    ORDER BY i.item_name ASC
  ";
    $stmt_items = $pdo->query($sql_items);
    $rows = $stmt_items ? $stmt_items->fetchAll(PDO::FETCH_ASSOC) : [];


    // 2. Fetch Inventory KPI Data (summary statistics from ITEM table)
    $sql_kpis = "
    SELECT 
      COUNT(DISTINCT i.category_id) AS total_categories, /* Check distinct category IDs */
      COUNT(item_id) AS total_products,
      SUM(stock_quantity * unit_cost) AS total_cost_value,
      SUM(stock_quantity * selling_price) AS total_selling_value,
      SUM(stock_quantity) AS total_stock_units,
      (SELECT COUNT(alert_id) FROM stock_alert WHERE alert_type = 'Low Stock' AND resolved = 0) AS low_stock_alerts
    FROM item i;
  ";
    $kpi_data = $pdo->query($sql_kpis)->fetch(PDO::FETCH_ASSOC);

    // 3. Fetch Purchase Order KPI Data (NEW QUERY for Incoming Orders KPI)
    $sql_po_totals = "
    SELECT 
      SUM(pod.purchase_cost) AS total_po_cost,
      SUM(pod.quantity) AS total_po_items
    FROM purchase_order_details pod
    JOIN purchase_order po ON po.po_id = pod.po_id
    WHERE po.status IN ('Confirmed', 'Shipped', 'Delayed');
    ";
    $po_data = $pdo->query($sql_po_totals)->fetch(PDO::FETCH_ASSOC);


    // 4. Get categories from the category table for the filter/modal (MODIFIED)
    $sql_categories = "SELECT category_id, category_name FROM category ORDER BY category_name";
    $categories_data = $pdo->query($sql_categories)->fetchAll(PDO::FETCH_ASSOC);


    // 5. Fetch all suppliers for the item modal
    $sql_suppliers = "SELECT supplier_id, company_name FROM supplier ORDER BY company_name ASC";
    $suppliers = $pdo->query($sql_suppliers)->fetchAll(PDO::FETCH_ASSOC);

    // 6. Map DB data to KPI array structure
    $low_stock_count = $kpi_data['low_stock_alerts'] ?? 0;
    $total_products = (int)($kpi_data['total_products'] ?? 0);
    $total_categories = (int)($kpi_data['total_categories'] ?? 0);

    // NEW METRICS
    $total_selling_value = (float)($kpi_data['total_selling_value'] ?? 0.00);
    $total_po_cost = (float)($po_data['total_po_cost'] ?? 0.00);
    $total_po_items = (int)($po_data['total_po_items'] ?? 0);

    // Low Stock Correction (already fixed)
    $out_of_stock_count = (int)count(array_filter($rows, fn($r) => (int)$r['stock_quantity'] === 0));


    $kpis = [
        // 1. Categories
        [
            'title' => 'Categories',
            'left_value' => e($total_categories),
            'left_note' => 'Last 7 days',
            'class' => 'kpi-blue'
        ],

        // 2. Total Products:
        [
            'title' => 'Total Products',
            'left_value' => e($total_products),
            'left_note' => 'Last 7 days',
            'right_value' => 'RM' . number_format($total_selling_value, 2),
            'right_note' => 'Total Selling Value',
            'class' => 'kpi-orange'
        ],

        // 3. Incoming Orders
        [
            'title' => 'Incoming Orders',
            'left_value' => e($total_po_items),
            'left_note' => 'Items to Receive',
            'right_value' => 'RM' . number_format($total_po_cost, 2),
            'right_note' => 'Pending PO Cost',
            'class' => 'kpi-purple'
        ],

        // 4. Low Stocks
        [
            'title' => 'Low Stocks',
            'left_value' => e($low_stock_count),
            'left_note' => 'Need to Ordered',
            'right_value' => e($out_of_stock_count),
            'right_note' => 'Not in stock',
            'class' => 'kpi-red'
        ],
    ];
} catch (Throwable $ex) {
    $errorMsg = $ex->getMessage();
}


// --- Helper function to determine stock status and return a styled chip ---
function get_stock_status(int $qty, int $threshold): string
{
    if ($qty > $threshold) {
        return 'in';
    } elseif ($qty > 0 && $qty <= $threshold) {
        return 'low';
    }
    return 'out';
}

function avail_chip(string $st): string
{
    $st = strtolower($st);
    if ($st === 'in')    return '<span class="status ok">In-stock</span>';
    if ($st === 'out') return '<span class="status bad">Out of stock</span>';
    return '<span class="st-delayed" style="font-weight:600;">Low stock</span>';
}

// --- Helper function for formatting money (optional) ---
function money_format($val): string
{
    // Keeping 'RM' as the currency symbol used in the calculation is RM (Ringgit Malaysia)
    return 'RM' . number_format((float)$val, 2);
}

/* --- Check for status message from URL --- */
$status = $_GET['status'] ?? null;
$statusMsg = null;

if ($status === 'deleted') {
    $statusMsg = ['ok', 'Item successfully deleted.'];
}
if ($status === 'added') {
    $statusMsg = ['ok', 'New item successfully added.'];
}
if ($status === 'delete_restricted_po') {
    $statusMsg = ['error', 'Deletion failed: Item is referenced in a Purchase Order.'];
}
if ($status === 'delete_restricted_gr') {
    $statusMsg = ['error', 'Deletion failed: Item is referenced in a Goods Receipt.'];
}
// Add other error messages as needed (e.g., delete_error)
?>

<section class="page items-page">

    <?php if ($errorMsg): ?>
        <div class="alert error" style="margin:12px 0;">
            <strong>Database Error:</strong> <?= e($errorMsg) ?>
        </div>
    <?php endif; ?>

    <div class="card kpi-card items-kpi">
        <div class="kpi-title">Overall Inventory</div>
        <div class="kpi-row items-kpi-row">
            <?php foreach ($kpis as $i => $k): ?>
                <div class="kpi items-kpi-cell<?= $i > 0 ? ' with-divider' : '' ?>">
                    <div class="kpi-head <?= e($k['class'] ?? '') ?>"><?= e($k['title']) ?></div>

                    <?php if (!empty($k['right_value'])): ?>
                        <div class="split">
                            <div class="col">
                                <div class="val"><?= e($k['left_value']) ?></div>
                                <div class="note"><?= e($k['left_note']) ?></div>
                            </div>
                            <div class="col right">
                                <div class="val"><?= e($k['right_value']) ?></div>
                                <div class="note"><?= e($k['right_note']) ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="val"><?= e($k['left_value']) ?></div>
                        <div class="note"><?= e($k['left_note']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card" style="margin-top:14px;">
        <div class="page-head">
            <h2>Products</h2>
            <div class="actions">
                <a href="#" id="openItemModalBtn" class="btn btn-primary">Add Product</a>
                <button id="deleteStockBtn" class="btn btn-danger" disabled>Delete Stock</button>

                <div class="filter-dropdown">
                    <button id="filterBtn" class="btn btn-secondary"><span class="btn-ico">≡</span> Filters</button>
                    <div class="filter-dropdown-content" id="categoryFilterDropdown">
                        <div class="filter-header" style="border-top: 1px solid #e5e7eb; margin-top: 8px; padding-top: 8px;">Sort by Name</div>
                        <a href="#" class="filter-option" data-filter-type="sort" data-filter-value="asc">A to Z</a>
                        <a href="#" class="filter-option" data-filter-type="sort" data-filter-value="desc">Z to A</a>
                        <div class="filter-header">Category</div>
                        <a href="#" class="filter-option" data-filter-type="category" data-filter-value="all">All Categories</a>
                        <?php foreach ($categories_data as $cat): ?>
                            <a href="#" class="filter-option" data-filter-type="category" data-filter-value="<?= e($cat['category_name']) ?>"><?= e($cat['category_name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="table table-items">
            <div class="t-head">
                <div><input type="checkbox" id="selectAllItems"></div>
                <div>Products</div>
                <div>Buying Price</div>
                <div>Quantity</div>
                <div>Threshold Value</div>
                <div>Expiry Date</div>
                <div>Availability</div>
                <div>Actions</div>
            </div>

            <?php if ($rows): ?>
                <?php foreach ($rows as $r):
                    $status_key = get_stock_status((int)$r['stock_quantity'], (int)$r['threshold_quantity']);
                    $uom = trim(e($r['uom'] ?? ''));
                    $uom_display = $uom ? '&nbsp;' . $uom : '';

                    // === Expiry date highlight logic ===
                    $expiryClass = '';
                    if (!empty($r['expiry_date'])) {
                        $today = new DateTime();
                        $expDate = new DateTime($r['expiry_date']);
                        $diffDays = (int)$today->diff($expDate)->format("%r%a");

                        if ($diffDays < 0) {
                            $expiryClass = 'expired-date'; // already expired
                        } elseif ($diffDays <= 30) {
                            $expiryClass = 'expiring-soon'; // within 15 days
                        }
                    }

                ?>
                    <div class="t-row"
                        data-row-id="<?= e($r['item_id']) ?>"
                        data-category="<?= e($r['category']) ?>"
                        data-item-name="<?= e($r['item_name']) ?>">
                        <div>
                            <input type="checkbox"
                                class="row-check"
                                data-row-id="<?= e($r['item_id']) ?>"
                                data-name="<?= e($r['item_name']) ?>">
                        </div>
                        <div>
                            <strong><?= e($r['item_name']) ?></strong><br>
                        </div>
                        <div><?= money_format($r['unit_cost']) ?></div>

                        <div><?= e($r['stock_quantity']) . $uom_display ?></div>

                        <div><?= e($r['threshold_quantity']) . $uom_display ?></div>

                        <div class="<?= $expiryClass ?>">
                            <?= $r['expiry_date'] ? e(date('d/m/y', strtotime($r['expiry_date']))) : 'N/A' ?>
                        </div>

                        <div><?= avail_chip($status_key) ?></div>
                        <div class="actions-cell">
                            <a href="/index.php?page=item_details&id=<?= e($r['item_id']) ?>" class="btn btn-secondary slim">More Details</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="t-row">
                    <div style="grid-column: 1 / -1; color:#667085; padding:12px 0;">No items found in the database.</div>
                </div>
            <?php endif; ?>
        </div>


        <div class="pager-rail">
            <div class="left"><a class="btn btn-secondary slim" href="#">Previous</a></div>
            <div class="mid"><span class="page-note">Page 1 of 1</span></div>
            <div class="right"><a class="btn btn-secondary slim" href="#">Next</a></div>
        </div>
    </div>
</section>

<div class="overlay" id="itemModal" aria-modal="true" role="dialog" aria-hidden="true">
    <div class="modal item-modal" role="document" aria-labelledby="itemModalTitle">
        <div class="modal-head">
            <h2 id="itemModalTitle">New Product</h2>
            <button class="modal-x" id="closeItemModal" aria-label="Close">×</button>
        </div>

        <div class="modal-body">

            <form id="itemForm" class="item-form" method="post" action="api/add_item.php" enctype="multipart/form-data" autocomplete="off">

                <div class="row">
                    <label>Product Name</label>
                    <input type="text" name="item_name" placeholder="Enter product name" required>
                </div>

                <div class="row">
                    <label>Product Code</label>
                    <input type="text" name="item_code" placeholder="Enter product code (e.g., ITM-001)" required>
                </div>

                <div class="row">
                    <label>Brand</label>
                    <input type="text" name="brand" placeholder="Enter brand name">
                </div>

                <div class="row">
                    <label>Category</label>
                    <select name="category_id" required>
                        <option value="">Select product category</option>
                        <?php foreach ($categories_data as $cat): ?>
                            <option value="<?= e($cat['category_id']) ?>"><?= e($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row">
                    <label>Measurement</label>
                    <input type="text" name="measurement" placeholder="e.g., 50 x 30ml">
                </div>

                <div class="row">
                    <label>Unit of Measure</label>
                    <input type="text" name="uom" placeholder="e.g., Packet, PC, Box" required>
                </div>

                <div class="row">
                    <label>Expiry Date</label>
                    <input type="date" name="expiry_date">
                </div>

                <h3 style="margin-top:20px; margin-bottom:10px; font-size:16px;">Pricing & Stock</h3>

                <div class="row">
                    <label>Buying Price</label>
                    <input type="number" name="unit_cost" placeholder="Enter unit cost" min="0" step="0.01" required>
                </div>

                <div class="row">
                    <label>Selling Price</label>
                    <input type="number" name="selling_price" placeholder="Enter retail price" min="0" step="0.01" required>
                </div>

                <div class="row">
                    <label>Quantity</label>
                    <input type="number" name="stock_quantity" placeholder="Enter current stock quantity" min="0" required>
                </div>

                <div class="row">
                    <label>Threshold Value</label>
                    <input type="number" name="threshold_quantity" placeholder="Enter low stock alert quantity" min="0" required>
                </div>

                <h3 style="margin-top:20px; margin-bottom:10px; font-size:16px;">Supplier Details</h3>

                <div class="row">
                    <label>Supplier</label>
                    <select name="supplier_id" required>
                        <option value="">Select a supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= e($s['supplier_id']) ?>"><?= e($s['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </form>
        </div>

        <div class="modal-foot">
            <div class="btn-row">
                <button type="button" class="btn btn-secondary" id="cancelItemBtn">Discard</button>
                <button type="submit" form="itemForm" class="btn btn-primary">Add Product</button>
            </div>
        </div>
    </div>
</div>

<div class="overlay" id="deleteModal" aria-modal="true" role="dialog" aria-hidden="true">
    <div class="modal" role="document" aria-labelledby="deleteModalTitle">
        <div class="modal-head">
            <h2 id="deleteModalTitle">Delete Stock</h2>
            <button class="modal-x" id="closeDeleteModal" aria-label="Close">×</button>
        </div>

        <div class="modal-body">
            <p style="margin:0 0 8px; color:#475467;">You are about to delete the following items:</p>
            <ul id="deleteList" style="margin:0 0 12px 18px; padding:0; color:#101828;"></ul>
            <p id="deleteCount" style="margin:0; color:#667085;"></p>
        </div>

        <div class="modal-foot">
            <div class="btn-row">
                <button type="button" class="btn btn-secondary" id="cancelDeleteBtn">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>


<script>
    // === Element Declarations ===
    const selectAll = document.getElementById('selectAllItems');
    const rowChecks = () => Array.from(document.querySelectorAll('.row-check'));
    const deleteBtn = document.getElementById('deleteStockBtn');
    const tableBody = document.querySelector('.table-items'); // Assuming table-items contains the rows
    const filterDropdown = document.getElementById('categoryFilterDropdown');
    const filterOptions = Array.from(document.querySelectorAll('.filter-option'));


    const deleteOverlay = document.getElementById('deleteModal');
    const closeDeleteBtn = document.getElementById('closeDeleteModal');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const deleteList = document.getElementById('deleteList');
    const deleteCount = document.getElementById('deleteCount');

    const openItemBtn = document.getElementById('openItemModalBtn');
    const itemOverlay = document.getElementById('itemModal');
    const closeItemBtn = document.getElementById('closeItemModal');
    const cancelItemBtn = document.getElementById('cancelItemBtn');
    const itemForm = document.getElementById('itemForm');
    const addItemBtn = itemOverlay ? itemOverlay.querySelector('.modal-foot .btn-primary') : null;


    // === Helper Functions ===
    function getSelectedRows() {
        return rowChecks().filter(c => c.checked);
    }

    function updateDeleteState() {
        const hasSel = getSelectedRows().length > 0;
        if (deleteBtn) deleteBtn.disabled = !hasSel;
    }

    function openItemModal(e) {
        e && e.preventDefault();
        itemOverlay.classList.add('visible');
        itemOverlay.removeAttribute('aria-hidden');
    }

    function closeItemModal() {
        if (!itemOverlay) return;
        itemOverlay.classList.remove('visible');
        itemOverlay.setAttribute('aria-hidden', 'true');
        // Reset the form on close
        if (itemForm) itemForm.reset();
    }

    function openDeleteModal() {
        if (!deleteOverlay) return;
        const sels = getSelectedRows();
        if (deleteList) deleteList.innerHTML = '';
        sels.forEach(c => {
            const li = document.createElement('li');
            li.textContent = c.dataset.name || c.dataset.rowId;
            if (deleteList) deleteList.appendChild(li);
        });
        if (deleteCount) deleteCount.textContent = `${sels.length} item(s) selected`;
        deleteOverlay.classList.add('visible');
        deleteOverlay.removeAttribute('aria-hidden');
    }

    function closeDeleteModal() {
        if (!deleteOverlay) return;
        deleteOverlay.classList.remove('visible');
        deleteOverlay.setAttribute('aria-hidden', 'true');
    }

    // NEW: Function to filter and/or sort table rows
    let currentCategoryFilter = 'all'; // Keep track of the active category filter

    function filterAndSortTable(filterType, filterValue) {
        const rows = Array.from(tableBody.querySelectorAll('.t-row:not(.t-head)'));

        // 1. Update state based on filter type
        if (filterType === 'category') {
            currentCategoryFilter = filterValue;
        }

        // 2. Apply Category Filter (Show/Hide rows)
        const filteredRows = rows.filter(row => {
            // Note: row.dataset.category contains the category NAME (from the SQL JOIN)
            const rowCategory = row.dataset.category;
            const isVisible = currentCategoryFilter === 'all' || rowCategory === currentCategoryFilter;
            row.style.display = isVisible ? 'grid' : 'none';
            return isVisible;
        });

        // 3. Apply Sorting (only if sort is triggered, and only to visible rows)
        if (filterType === 'sort') {
            filteredRows.sort((a, b) => {
                const nameA = (a.dataset.itemName || '').toUpperCase(); // use the new data attribute
                const nameB = (b.dataset.itemName || '').toUpperCase();

                if (nameA < nameB) return filterValue === 'asc' ? -1 : 1;
                if (nameA > nameB) return filterValue === 'asc' ? 1 : -1;
                return 0;
            });

            // Re-append rows to the tableBody in the new sorted order
            // This physically re-orders them in the DOM
            filteredRows.forEach(row => tableBody.appendChild(row));
        }
    }


    // === General Event Bindings (Open/Close Modals & Checkboxes) ===

    openItemBtn?.addEventListener('click', openItemModal);
    closeItemBtn?.addEventListener('click', closeItemModal);
    cancelItemBtn?.addEventListener('click', closeItemModal);

    itemOverlay?.addEventListener('click', e => {
        if (e.target === itemOverlay) closeItemModal();
    });
    itemOverlay?.querySelector('.modal')?.addEventListener('click', e => e.stopPropagation());

    closeDeleteBtn?.addEventListener('click', closeDeleteModal);
    cancelDeleteBtn?.addEventListener('click', closeDeleteModal);
    deleteOverlay?.addEventListener('click', e => {
        if (e.target === deleteOverlay) closeDeleteModal();
    });
    deleteOverlay?.querySelector('.modal')?.addEventListener('click', e => e.stopPropagation());

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeItemModal();
            closeDeleteModal();
        }
    });

    // NEW: Filter/Sort Event Listener (Replaces old filter listener)
    filterOptions.forEach(option => {
        option.addEventListener('click', (e) => {
            e.preventDefault();
            const filterType = e.target.dataset.filterType;
            const filterValue = e.target.dataset.filterValue;

            filterAndSortTable(filterType, filterValue);

            // OPTIONAL: Update filter button text
            let filterText = 'Filters';
            if (filterType === 'category' && filterValue !== 'all') {
                filterText = filterValue;
            } else if (filterType === 'sort') {
                filterText = filterValue === 'asc' ? 'A to Z' : 'Z to A';
            }
            document.getElementById('filterBtn').innerHTML = `<span class="btn-ico">≡</span> ${filterText}`;
        });
    });


    // Checkbox and Delete Button State
    selectAll?.addEventListener('change', e => {
        const on = e.target.checked;
        rowChecks().forEach(c => c.checked = on);
        updateDeleteState();
    });
    document.addEventListener('change', e => {
        if (e.target.classList.contains('row-check')) {
            if (!e.target.checked && selectAll) selectAll.checked = false;
            if (selectAll) {
                const allChecked = rowChecks().every(c => c.checked);
                selectAll.checked = allChecked;
            }
            updateDeleteState();
        }
    });

    deleteBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (getSelectedRows().length === 0) return;
        openDeleteModal();
    });

    // === 1. Add Product Submission (API Integration) ===
    itemForm?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (addItemBtn) {
            addItemBtn.disabled = true;
            const oldText = addItemBtn.textContent;
            addItemBtn.textContent = 'Saving...';
        }

        try {
            const formData = new FormData(itemForm);
            // Submit to the API endpoint
            const res = await fetch('api/add_item.php', {
                method: 'POST',
                body: formData
            });
            const json = await res.json();

            if (res.ok && json.status === 'success') {
                // Success: Redirect to refresh the list with a status message
                // Assuming your item list is at /index.php?page=items
                window.location.href = window.location.pathname + '?page=items&status=added';
                return;
            }

            // API returned an error (e.g., validation or duplicate item_code)
            const msg = json.message || 'Failed to add item.';
            // In a real application, display this error in the modal, not an alert
            alert('Error: ' + msg);

        } catch (error) {
            console.error('Network or Parse Error:', error);
            alert('An unexpected error occurred. Check the console.');
        } finally {
            if (addItemBtn) {
                addItemBtn.disabled = false;
                addItemBtn.textContent = oldText;
            }
        }
    });


    // === 2. Delete Stock Submission (API Integration) ===
    confirmDeleteBtn?.addEventListener('click', async () => {
        const selectedItems = getSelectedRows();
        if (selectedItems.length === 0) return;

        confirmDeleteBtn.disabled = true;
        const oldText = confirmDeleteBtn.textContent;
        confirmDeleteBtn.textContent = 'Deleting...';

        try {
            // Loop through each selected item ID and call the delete API
            for (const item of selectedItems) {
                const itemId = item.dataset.rowId;
                // Calls public/api/delete_item.php?id=XXX
                const res = await fetch(`api/delete_item.php?id=${itemId}`);

                if (res.redirected) {
                    // delete_item.php redirects on success OR deletion restriction
                    // Stop the loop and let the browser navigate to the status URL
                    window.location.href = res.url;
                    return;
                }

                // Handle non-redirecting API failure (e.g., if delete_item.php returns JSON error)
                if (!res.ok) {
                    // Try to parse error, if available
                    const json = await res.json().catch(() => ({}));
                    throw new Error(json.message || `Failed to delete item ID ${itemId} (HTTP ${res.status}).`);
                }
            }

            // If the loop finished without redirection (unlikely for a working DELETE API but safe to include)
            window.location.href = window.location.pathname + '?page=items&status=deleted';

        } catch (error) {
            console.error('Deletion Error:', error);
            alert('Deletion failed. It might be linked to orders or receipts.');
            closeDeleteModal();
        } finally {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = oldText;
        }
    });
</script>
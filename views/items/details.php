<?php
// views/items/details.php
declare(strict_types=1);

/* --- Load PDO from db.php --- */
$PROJECT_ROOT = dirname(__DIR__, 2);
$loadedPdo = false;
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
  die('Database connection error.');
}

/* --- Helpers --- */
if (!function_exists('e')) {
  function e($v): string
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}
function money_format($val): string
{
  // RM currency format
  return 'RM' . number_format((float)$val, 2);
}

/* --- Auth --- */
require_once $PROJECT_ROOT . '/app/Auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
Auth::check_staff();

/* --- Get Item ID --- */
$item_id = (int)($_GET['id'] ?? ($_POST['item_id'] ?? 0));
if ($item_id === 0) {
  die('No Item ID provided.');
}

/* --- Handle form submission status --- */
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Product details successfully updated.'];
} elseif ($status === 'stock_adjusted') {
  $statusMsg = ['ok', 'Stock quantity successfully adjusted.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update product. Please try again.'];
}


/* --- Fetch All Item Data --- */
$item = null;
$on_the_way_qty = 0;
$errorMsg = null;
$all_categories = [];
$suppliers = [];

try {
  // 1. Fetch item and supplier data (MODIFIED: Join category table)
  $sql_item = "
    SELECT 
      i.*, 
      s.company_name AS supplier_name, 
      s.phone AS supplier_phone,
      s.contact_person AS supplier_contact_person,
      c.category_name AS category_name /* Fetch category name */
    FROM item i
    LEFT JOIN supplier s ON i.supplier_id = s.supplier_id
    LEFT JOIN category c ON i.category_id = c.category_id /* Join category table */
    WHERE i.item_id = :id
  ";
  $stmt_item = $pdo->prepare($sql_item);
  $stmt_item->execute([':id' => $item_id]);
  $item = $stmt_item->fetch(PDO::FETCH_ASSOC);

  if (!$item) {
    throw new Exception("Item not found.");
  }

  // 2. Calculate 'On The Way' quantity (Items in non-received POs)
  $sql_on_the_way = "
    SELECT 
      SUM(pod.quantity) AS on_the_way_qty
    FROM purchase_order_details pod
    JOIN purchase_order po ON po.po_id = pod.po_id
    WHERE 
      pod.item_id = :id AND
      po.status IN ('Confirmed', 'Shipped', 'Delayed');
  ";
  $stmt_otw = $pdo->prepare($sql_on_the_way);
  $stmt_otw->execute([':id' => $item_id]);
  $otw_result = $stmt_otw->fetch(PDO::FETCH_ASSOC);
  $on_the_way_qty = (int)($otw_result['on_the_way_qty'] ?? 0);

  // 3. Fetch all categories for dropdown (MODIFIED: Fetch ID and Name from category table)
  $sql_categories = "SELECT category_id, category_name FROM category ORDER BY category_name";
  $all_categories = $pdo->query($sql_categories)->fetchAll(PDO::FETCH_ASSOC);

  // 4. Fetch all suppliers for dropdown
  $sql_suppliers = "SELECT supplier_id, company_name FROM supplier ORDER BY company_name ASC";
  $suppliers = $pdo->query($sql_suppliers)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
  $errorMsg = "Error fetching item details: " . $ex->getMessage();
}

// Stock Adjustment reasons
$adjustment_reasons = [
  'Damaged or spoiled goods',
  'Internal use',
  'Inventory shrinkage',
  'Physical count correction',
];

?>

<section class="page product-details-page">
  <div class="card card-soft">
    <div class="page-head">
      <h1>Product Details (<?= e($item['item_code'] ?? 'N/A') ?>)</h1>
      <div class="actions">
        <a href="api/delete_item.php?id=<?= e($item_id) ?>"
          class="btn btn-danger"
          onclick="return confirm('Are you sure you want to delete this Item? This action cannot be undone and may be restricted if linked to orders/receipts.');">
          Delete Item
        </a>
        <a href="/index.php?page=items" class="btn btn-secondary">
          &larr; Back to List
        </a>
      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php elseif ($item): ?>

      <?php if ($statusMsg): ?>
        <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
          <span><?= e($statusMsg[1]) ?></span>
          <span class="toast-close">&times;</span>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['msg']) && $_GET['msg'] === 'insufficient_stock'): ?>
        <div id="toastPopupError" class="toast-popup error">
          <span>Cannot reduce stock. Quantity cannot be lower than 0.</span>
          <span class="toast-close">&times;</span>
        </div>
      <?php endif; ?>


      <form id="itemUpdateForm" class="supplier-form" method="post" action="api/update_item.php" autocomplete="off">


        <form id="itemUpdateForm" class="supplier-form" method="post" action="api/update_item.php" autocomplete="off">
          <input type="hidden" name="item_id" value="<?= e($item['item_id']) ?>">

          <h2 class="sub-heading">Primary Details</h2>
          <div class="grid-3 item-details-grid">
            <label>Product Name
              <input type="text" name="item_name" value="<?= e($item['item_name']) ?>" required>
            </label>
            <label>Product Code
              <input type="text" name="item_code" value="<?= e($item['item_code']) ?>" required>
            </label>
            <label>Brand
              <input type="text" name="brand" value="<?= e($item['brand']) ?>">
            </label>
            <label>Category
              <select name="category_id" required> /* MODIFIED: Use category_id */
                <?php foreach ($all_categories as $cat): ?>
                  <option value="<?= e($cat['category_id']) ?>"
                    <?= ((int)($item['category_id'] ?? 0) === (int)$cat['category_id']) ? 'selected' : '' ?>>
                    <?= e($cat['category_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Measurement
              <input type="text" name="measurement" value="<?= e($item['measurement']) ?>">
            </label>
            <label>Unit of Measure
              <input type="text" name="uom" value="<?= e($item['uom']) ?>">
            </label>
            <label>Expiry Date
              <input type="date" name="expiry_date" value="<?= e($item['expiry_date']) ?>">
            </label>
            <label>Threshold Value (Alert)
              <input type="number" name="threshold_quantity" value="<?= e($item['threshold_quantity']) ?>" min="0" required>
            </label>
          </div>

          <h2 class="sub-heading" style="margin-top: 24px;">Pricing & Current Stock</h2>
          <div class="grid-3 item-details-grid">
            <label>Buying Price (Cost)
              <input type="number" name="unit_cost" value="<?= e($item['unit_cost']) ?>" step="0.01" min="0" required>
            </label>
            <label>Selling Price
              <input type="number" name="selling_price" value="<?= e($item['selling_price']) ?>" step="0.01" min="0" required>
            </label>
            <label>Current Quantity
              <input type="text" value="<?= e($item['stock_quantity']) ?>" readonly style="background: #f9fafb;">
            </label>
            <label>On The Way (Incoming)
              <input type="text" value="<?= e($on_the_way_qty) ?>" readonly style="background: #f9fafb;">
            </label>
            <label>Stock Value (Cost)
              <input type="text" value="<?= e(money_format($item['stock_quantity'] * $item['unit_cost'])) ?>" readonly style="background: #f9fafb;">
            </label>
          </div>

          <h2 class="sub-heading" style="margin-top: 24px;">Supplier Details</h2>
          <div class="grid-2 item-details-grid">
            <label>Supplier (Edit)
              <select name="supplier_id" required>
                <option value="">Select a supplier</option>
                <?php foreach ($suppliers as $s): ?>
                  <option value="<?= e($s['supplier_id']) ?>"
                    <?= ((int)($item['supplier_id'] ?? 0) === (int)$s['supplier_id']) ? 'selected' : '' ?>>
                    <?= e($s['company_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Supplier Contact
              <input type="text" value="<?= e($item['supplier_phone'] ?? 'N/A') ?>" readonly style="background: #f9fafb;">
            </label>
            <label>Supplier Contact Person
              <input type="text" value="<?= e($item['supplier_contact_person'] ?? 'N/A') ?>" readonly style="background: #f9fafb;">
            </label>
          </div>

          <div class="btn-row detail-actions" style="margin-top: 20px; justify-content: flex-end; gap: 8px; border-top: 1px solid #e5eaf2; padding-top: 16px;">
            <button type="submit" form="itemUpdateForm" class="btn btn-primary">
              Save Product Details
            </button>
          </div>
        </form>
        <div style="border-top: 1px solid #e5eaf2; margin-top: 30px;"></div>

        <form id="stockAdjustForm" class="supplier-form" method="post" action="api/adjust_item.php" autocomplete="off">
          <input type="hidden" name="item_id" value="<?= e($item['item_id']) ?>">

          <h2 class="sub-heading" style="margin-top: 16px;">Stock Adjustment</h2>
          <p style="color: #667085; font-size: 14px; margin-top: -8px;">Use this to manually increase (e.g., <span style="font-weight: 600;">5</span>) or decrease (e.g., <span style="font-weight: 600;">-5</span>) the current stock quantity.</p>

          <div class="grid-3" style="margin-bottom: 14px; grid-template-columns: 1fr 1.5fr 1fr;">
            <label>Adjust Quantity
              <input type="number" name="adjustment_qty" placeholder="e.g., -5 or 10" required>
            </label>
            <label>Reason for Change
              <select name="reason" required>
                <option value="">Select a reason</option>
                <?php foreach ($adjustment_reasons as $reason): ?>
                  <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div style="display: flex; align-items: flex-end;">
              <button type="submit" form="stockAdjustForm" class="btn btn-secondary" style="width: 100%; height: 40px;">
                Adjust Stock
              </button>
            </div>
          </div>
        </form>
      <?php endif; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  ['toastPopup', 'toastPopupError'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;

    const closeBtn = el.querySelector('.toast-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => el.style.display = 'none');
    }
  });
});
</script>

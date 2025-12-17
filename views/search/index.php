<?php
// views/search/index.php
declare(strict_types=1);

// Load PDO from db.php
$root = dirname(__DIR__, 2); // Path is now 2 levels up from views/search/
$loadedPdo = false;
foreach ([
  $root . '/db.php',
  $root . '/app/db.php',
  $root . '/config/db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error.');
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Get Search Query
$query = trim($_GET['query'] ?? '');
$search_term = '%' . $query . '%';

$results_products = [];
$results_suppliers = [];
$results_pos = [];
$errorMsg = null;

$status_classes = [
  'Delayed'     => 'st-delayed',
  'Confirmed'   => 'st-confirmed',
  'Approved'    => 'st-confirmed',
  'Pending'     => 'st-pending',
  'Created'     => 'st-pending',
  'Rejected'    => 'st-rejected',
  'Received'    => 'st-received',
  'Completed'   => 'st-received',
];


if (!empty($query)) {
    try {
        // 1. Search Products (Items)
        $sql_products = "SELECT item_id, item_code, item_name, stock_quantity 
                         FROM item 
                         WHERE item_name LIKE :q OR item_code LIKE :q 
                         LIMIT 10";
        $stmt_products = $pdo->prepare($sql_products);
        $stmt_products->execute([':q' => $search_term]);
        $results_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

        // 2. Search Suppliers
        $sql_suppliers = "SELECT supplier_id, company_name, contact_person, email 
                          FROM supplier 
                          WHERE company_name LIKE :q OR contact_person LIKE :q OR email LIKE :q 
                          LIMIT 10";
        $stmt_suppliers = $pdo->prepare($sql_suppliers);
        $stmt_suppliers->execute([':q' => $search_term]);
        $results_suppliers = $stmt_suppliers->fetchAll(PDO::FETCH_ASSOC);

        // 3. Search Purchase Orders (by ID)
        // Clean query to get just the number (e.g., "PO-123" -> "123")
        $po_id_query = $query;
        if (stripos($po_id_query, 'po-') === 0) {
            $po_id_query = substr($po_id_query, 3);
        }
        
        if (is_numeric($po_id_query)) {
            $sql_pos = "SELECT po_id, supplier_id, status, issue_date 
                        FROM purchase_order 
                        WHERE po_id = :id";
            $stmt_pos = $pdo->prepare($sql_pos);
            $stmt_pos->execute([':id' => (int)$po_id_query]);
            $results_pos = $stmt_pos->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Throwable $ex) {
        $errorMsg = "Database search error: " . $ex->getMessage();
    }
}
?>

<section class="page search-results-page">
  <div class="card card-soft">
    <div class="page-head">
      <?php if (!empty($query)): ?>
        <h1>Search results for "<?= e($query) ?>"</h1>
      <?php else: ?>
        <h1>Search</h1>
      <?php endif; ?>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:0 0 16px 0;">
        <strong>Error:</strong> <?= e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <?php
    // Check if all results are empty
    $no_results = empty($results_products) && empty($results_suppliers) && empty($results_pos);
    ?>

    <?php if (!empty($query) && $no_results && !$errorMsg): ?>
      <p style="text-align: center; color: #667085; margin: 16px 0;">
        No results found for "<?= e($query) ?>".
      </p>
    <?php elseif (empty($query)): ?>
      <p style="text-align: center; color: #667085; margin: 16px 0;">
        Please enter a search term in the topbar.
      </p>
    <?php endif; ?>


    <!-- Products Results -->
    <?php if (!empty($results_products)): ?>
      <h2 class="table-title">Products</h2>
      <div class="table table-clean" id="searchProductsTable">
        <div class="t-head">
          <div>Item Code</div>
          <div>Item Name</div>
          <div>Stock</div>
          <div>Actions</div>
        </div>
        <?php foreach ($results_products as $r): ?>
          <div class="t-row">
            <div><?= e($r['item_code']) ?></div>
            <div><?= e($r['item_name']) ?></div>
            <div><?= e($r['stock_quantity']) ?></div>
            <div>
              <a href="/index.php?page=item_details&id=<?= e($r['item_id']) ?>" class="btn btn-primary slim">
                More Details
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>


    <!-- Suppliers Results -->
    <?php if (!empty($results_suppliers)): ?>
      <!-- Removed individual card wrapper -->
      <h2 class="table-title" <?php if (!empty($results_products)) echo 'style="margin-top: 24px;"'; ?>>
        Suppliers
      </h2>
      <div class="table table-clean" id="searchSuppliersTable">
        <div class="t-head">
          <div>Company Name</div>
          <div>Contact Person</div>
          <div>Email</div>
          <div>Actions</div>
        </div>
        <?php foreach ($results_suppliers as $r): ?>
          <div class="t-row">
            <div><?= e($r['company_name']) ?></div>
            <div><?= e($r['contact_person']) ?></div>
            <div><?= e($r['email']) ?></div>
            <div>
              <a href="/index.php?page=supplier_details&id=<?= e($r['supplier_id']) ?>" class="btn btn-primary slim">
                More Details
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>


    <!-- Purchase Order Results -->
    <?php if (!empty($results_pos)): ?>
      <!-- Removed individual card wrapper -->
      <h2 class="table-title" <?php if (!empty($results_products) || !empty($results_suppliers)) echo 'style="margin-top: 24px;"'; ?>>
        Purchase Orders
      </h2>
      <div class="table table-clean" id="searchPoTable">
        <div class="t-head">
          <div>PO ID</div>
          <div>Status</div>
          <div>Issue Date</div>
          <div>Actions</div>
        </div>
        <?php foreach ($results_pos as $r): ?>
          <?php
            // Logic to get status class
            $status = $r['status'] ?? 'Pending';
            $cls = $status_classes[$status] ?? 'st-pending';
          ?>
          <div class="t-row">
            <div>PO-<?= e($r['po_id']) ?></div>
            <div><span class="po-status <?= $cls ?>"><?= e($status) ?></span></div>
            <div><?= e(date('d/m/Y', strtotime($r['issue_date']))) ?></div>
            <div>
              <a href="/index.php?page=po_details&id=<?= e($r['po_id']) ?>" class="btn btn-primary slim">
                More Details
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div> 
</section>
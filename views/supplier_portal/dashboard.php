<?php
// views/supplier_portal/dashboard.php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php'; 

// Authentication check
if (!isset($_SESSION['supplier_id'])) {
    header('Location: /index.php?page=login');
    exit();
}

$supplier_id = $_SESSION['supplier_id'];
$supplier_name = $_SESSION['supplier_name'] ?? 'Supplier';
$errorMsg = null;
$rows = [];

// Fetch KPI Data for this supplier 
$kpis = [
  'total_orders' => 0,
  'total_received' => 0,
  'total_returned' => 0, // 'Rejected'
  'on_the_way' => 0, // 'Created', 'Pending', 'Approved', 'Confirmed', 'Delayed'
];
try {
  $kpi_sql = "
    SELECT
      COUNT(*) AS total_orders,
      SUM(CASE WHEN status IN ('Received', 'Completed') THEN 1 ELSE 0 END) AS total_received,
      SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS total_returned,
      SUM(CASE WHEN status IN ('Created', 'Pending', 'Approved', 'Confirmed', 'Delayed') THEN 1 ELSE 0 END) AS on_the_way
    FROM purchase_order
    WHERE supplier_id = :supplier_id
  ";
  $kpi_stmt = $pdo->prepare($kpi_sql);
  $kpi_stmt->execute([':supplier_id' => $supplier_id]);
  
  $kpi_data = $kpi_stmt->fetch(PDO::FETCH_ASSOC);
  if ($kpi_data) {
    $kpis['total_orders'] = $kpi_data['total_orders'] ?? 0;
    $kpis['total_received'] = $kpi_data['total_received'] ?? 0;
    $kpis['total_returned'] = $kpi_data['total_returned'] ?? 0;
    $kpis['on_the_way'] = $kpi_data['on_the_way'] ?? 0;
  }
  
} catch (Throwable $e) {
  error_log("Supplier KPI Query Failed: " . $e->getMessage());
}


try {
    // Fetch POs for the logged-in supplier
    $sql = "
        SELECT 
          po.po_id,
          po.issue_date,
          po.expected_date,    /* <-- FETCH NEW COLUMN */
          po.status,
          SUM(pod.purchase_cost) AS total_order_value,
          COUNT(pod.po_detail_id) AS item_count
        FROM purchase_order po
        LEFT JOIN purchase_order_details pod ON po.po_id = pod.po_id
        WHERE po.supplier_id = :supplier_id
        GROUP BY po.po_id
        ORDER BY po.issue_date DESC, po.po_id DESC
    "; 
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':supplier_id' => $supplier_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    $errorMsg = "Error fetching orders: " . $ex->getMessage();
}

// Function to determine status class for styling
function getStatusClass(string $status): string {
    return [
        'Delayed'=>'st-delayed', 'Confirmed'=>'st-confirmed', 'Approved' => 'st-confirmed',
        'Pending'=>'st-pending', 'Created' => 'st-pending', 'Rejected'=>'st-rejected',
        'Received'=>'st-received', 'Completed' => 'st-received',
    ][$status] ?? 'st-pending';
}
?>

<div class="card kpi-card" style="margin-bottom: 14px;">
    <h2 class="kpi-title">Your Purchase Orders (<?= e($supplier_name) ?>)</h2>
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


<?php if ($errorMsg): ?>
    <div class="alert error" style="margin:12px 0;"><?= e($errorMsg) ?></div>
<?php endif; ?>

<!-- Main PO Table -->
<div class="card">
    <div class="page-head">
      <h2 class="table-title">Order List</h2>
    </div>

    <div class="table table-po">
        <div class="t-head">
            <div>Purchase Order ID</div>
            <div>Issue Date</div>
            <div>Expected Delivery Date</div> <!-- UPDATED HEADER -->
            <div>Order Value</div>
            <div>Item Count</div>
            <div>Status</div>
            <div>Actions</div>
        </div>

        <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $r): ?>
                <div class="t-row">
                    <div>PO-<?= e($r['po_id']) ?></div>
                    <div><?= e(date('d/m/Y', strtotime($r['issue_date']))) ?></div>
                    
                    <div><?= e(date('d/m/Y', strtotime($r['expected_date']))) ?></div>
                    
                    <div><?= '$' . number_format((float)($r['total_order_value'] ?? 0), 2) ?></div>
                    
                    <div><?= e($r['item_count'] ?? 0) ?></div>
                    <div><span class="po-status <?= getStatusClass($r['status']) ?>"><?= e($r['status']) ?></span></div>
                    <div>
                        <a href="/index.php?page=supplier_po_details&id=<?= e($r['po_id']) ?>" class="btn btn-secondary slim">
                            View Details
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="t-row">
                <div style="grid-column: 1 / -1; color:#667085; padding:12px 0; text-align: center;">
                    No purchase orders found for your account.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
// views/reports/index.php
declare(strict_types=1);

/* --- Find project root and load Composer autoloader --- */
$root = is_dir('/var/www/html/vendor')
  ? '/var/www/html'                        // EC2
  : realpath(__DIR__ . '/../..');          // local dev (Windows/XAMPP), adjusts from views/reports/

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
  require_once $autoload;
}

require_once $root . '/app/s3_client.php';
require_once $root . '/app/Auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}   // <-- add this
Auth::check_staff(['view_reports']);

/* === AWS SDK imports MUST be top-level, after autoload === */

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/* Optional: fallback to single-file fpdf.php if you also keep a copy in /libraries */

if (!class_exists('FPDF')) {
  $fallback = $root . '/libraries/fpdf/fpdf.php';
  if (is_file($fallback)) {
    require_once $fallback;
  }
}

/* Final guard for FPDF */
if (!class_exists('FPDF')) {
  error_log('FPDF not loaded. Tried ' . $autoload . ' and (optional) ' . $fallback . ' | __FILE__=' . __FILE__);
  die('<div style="padding:12px;background:#fffbe6;border:1px solid #ffe58f;border-radius:8px">
        <b>FPDF not loaded.</b> Ensure <code>' . $autoload . '</code> exists (Composer) or put <code>libraries/fpdf/fpdf.php</code>.
      </div>');
}

/* ---------- Load PDO (robust) ---------- */
$loadedPdo = false;
$pdoCandidates = [
  $root . '/db.php',
  $root . '/app/db.php',
  $root . '/config/db.php',
  dirname($root, 1) . '/db.php',
  dirname($root, 1) . '/app/db.php',
  dirname($root, 1) . '/config/db.php',
  '/var/www/html/db.php',
  '/var/www/html/app/db.php',
  '/var/www/html/config/db.php'
];
foreach ($pdoCandidates as $maybe) {
  if (is_file($maybe)) {
    require_once $maybe;
    $loadedPdo = true;
    break;
  }
}
if (!$loadedPdo) {
  die('<div style="padding:16px;color:#b00020;background:#fff0f1;border:1px solid #ffd5da;border-radius:8px;">
        Could not load <code>db.php</code>. Please ensure it exists under <code>/var/www/html</code>.
      </div>');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('<div style="padding:16px;color:#b00020;background:#fff0f1;border:1px solid #ffd5da;border-radius:8px;">
        <code>$pdo</code> is not valid. Ensure db.php creates <code>$pdo = new PDO(...)</code>.
      </div>');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Helpers ---------- */
if (!function_exists('e')) {
  function e($v): string
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('moneyMY')) {
  function moneyMY($n): string
  {
    return 'RM' . number_format((float)$n, 2);
  }
}
function perfLabel(float $rate): string
{
  if ($rate >= 85) return 'Excellent';
  if ($rate >= 60) return 'Average';
  return 'Poor';
}

/* ---------- DOWNLOAD (PDF generation) ---------- */
if (isset($_GET['download'])) {
  $type = $_GET['download'];

  $pdf_txt = function ($s): string {
    if ($s === null) return '';
    $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string)$s);
    return $t !== false ? $t : preg_replace('/[^\x20-\x7E]/', '', (string)$s);
  };

  if (!class_exists('FPDF')) {
    die('<div style="padding:16px;background:#fffbe6;border:1px solid #ffe58f;border-radius:8px;">
          <strong>FPDF not loaded.</strong> Run <code>composer require setasign/fpdf</code> again.
        </div>');
  }

  $pdf = new FPDF('P', 'mm', 'A4');

  /* ---------- Query data ---------- */
  if ($type === 'bestcat') {
    $stmt = $pdo->query("
      SELECT c.category_name AS category,
             COALESCE(SUM(i.unit_cost * i.stock_quantity),0) AS stock_value,
             COALESCE(COUNT(DISTINCT po.po_id),0)          AS restock_freq
      FROM category c
      LEFT JOIN item i ON i.category_id = c.category_id
      LEFT JOIN purchase_order_details pod ON pod.item_id = i.item_id
      LEFT JOIN purchase_order po ON po.po_id = pod.po_id
      GROUP BY c.category_id, c.category_name
      ORDER BY stock_value DESC
    ");
    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $title = 'Best Performing Category';
    $cols  = ['Category', 'Stock Value', 'Restock Freq'];
    $w     = [98, 36, 32];
    $align = ['L', 'R', 'C'];
  } else {
    $stmt = $pdo->prepare("
      SELECT s.company_name AS supplier,
             COUNT(po.po_id) AS total_pos,
             ROUND(AVG(CASE WHEN po.receive_date IS NOT NULL THEN DATEDIFF(po.receive_date, po.issue_date) END),1) AS avg_cycle_days, 
             ROUND(
               SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL AND po.receive_date <= po.expected_date THEN 1 ELSE 0 END)
               / NULLIF(SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END),0) * 100,1) AS on_time_rate
      FROM purchase_order po
      JOIN supplier s ON s.supplier_id = po.supplier_id
      WHERE po.expected_date IS NOT NULL
      GROUP BY s.supplier_id,s.company_name
      ORDER BY on_time_rate DESC, avg_cycle_days ASC
    ");
    $stmt->execute(); 
    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $title = 'Purchase Order Performance';
    $cols  = ['Supplier', 'Total POs', 'Avg Cycle (Days)', 'On-Time %'];
    $w     = [100, 26, 40, 24];
    $align = ['L', 'C', 'C', 'R'];
  }

  /* ---------- Build PDF ---------- */
  $pdf->AddPage();
  $pdf->SetTitle($pdf_txt($title), true);
  $pdf->SetFont('Arial', 'B', 15);
  $pdf->Cell(0, 10, $pdf_txt($title), 0, 1, 'L');
  $pdf->SetFont('Arial', '', 9);
  $pdf->SetTextColor(90, 90, 90);
  $pdf->Cell(0, 6, $pdf_txt('Generated at ' . date('Y-m-d H:i')), 0, 1, 'L');
  $pdf->Ln(2);
  $pdf->SetFont('Arial', 'B', 10);
  $pdf->SetFillColor(248, 250, 252);
  $pdf->SetTextColor(51, 65, 85);

  foreach ($cols as $i => $c) $pdf->Cell($w[$i], 8, $pdf_txt($c), 1, 0, 'L', true);
  $pdf->Ln();

  $pdf->SetFont('Arial', '', 10);
  $pdf->SetTextColor(0, 0, 0);

  foreach ($rows as $r) {
    $cells = ($type === 'bestcat') ? [
      $pdf_txt((string)$r['category']),
      $pdf_txt(moneyMY($r['stock_value'])),
      $pdf_txt((int)$r['restock_freq'] . ' PO'),
    ] : [
      $pdf_txt((string)$r['supplier']),
      $pdf_txt((string)(int)$r['total_pos']),
      $pdf_txt((string)($r['avg_cycle_days'] ?? '—')),
      $pdf_txt(number_format((float)($r['on_time_rate'] ?? 0), 1) . '%')
    ];
    foreach ($cells as $i => $txt) $pdf->Cell($w[$i], 7.2, $txt, 1, 0, $align[$i]);
    $pdf->Ln();
  }

  $fname    = ($type === 'bestcat' ? 'best_categories' : 'po_performance') . '_' . date('Ymd_His') . '.pdf';
  $pdfBytes = $pdf->Output('S');

  // Local archive (optional)
  $archiveDir = $root . '/archives';
  if (!is_dir($archiveDir)) @mkdir($archiveDir, 0775, true);
  @file_put_contents($archiveDir . '/' . $fname, $pdfBytes);

  // --- Upload to S3 via helper ---
  // --- Upload to S3 via helper ---
  $s3_error = null;
  $uploaded = false;
  $presignedUrl = null;   // for success path
  $key = null;            // will be set if upload ok

  try {
    $s3     = s3_client();   // from app/s3_client.php
    $bucket = s3_bucket();

    $key = sprintf('reports/%s/%s/%s', $type, date('Y'), $fname);

    error_log("[S3-UPLOAD] about to put: bucket=$bucket key=$key bytes=" . strlen($pdfBytes));

    $put = $s3->putObject([
      'Bucket'      => $bucket,
      'Key'         => $key,
      'Body'        => $pdfBytes,
      'ContentType' => 'application/pdf',
      'ACL'         => 'private',
    ]);

    error_log("[S3-UPLOAD] success: ETag=" . ($put['ETag'] ?? 'n/a'));
    $uploaded = true;

    // record history
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $userArr = Auth::user();                     // <-- use Auth helper
    $userId  = $userArr['user_id'] ?? null;      // <-- correct id

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $userId = $_SESSION['user_id'] ?? null;
    $ins = $pdo->prepare("
    INSERT INTO export_history (user_id, export_type, module_exported, file_name, file_path)
    VALUES (:uid, :type, :module, :fname, :path)
  ");
    $ins->execute([
      ':uid'    => $userId,
      ':type'   => 'pdf',
      ':module' => $type,
      ':fname'  => $fname,
      ':path'   => $key,
    ]);

    // Build presigned HTTPS URL so browser downloads from S3 directly
    $cmd = $s3->getCommand('GetObject', [
      'Bucket' => $bucket,
      'Key'    => $key,
      'ResponseContentType'        => 'application/pdf',
      'ResponseContentDisposition' => 'attachment; filename="' . basename($fname) . '"',
    ]);
    $req = $s3->createPresignedRequest($cmd, '+10 minutes');
    $presignedUrl = (string) $req->getUri();
  } catch (\Throwable $e) {
    $s3_error = $e->getMessage();
    error_log("[S3-UPLOAD] fail: " . $s3_error);
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['flash_error'] = 'S3 upload failed: ' . $s3_error;
  }

  // If upload ok → redirect to S3 (HTTPS). Else → stream from EC2 as fallback.
  while (ob_get_level()) {
    ob_end_clean();
  }

  if ($uploaded && $presignedUrl) {
    header('Location: ' . $presignedUrl);
    exit;
  }

  // Fallback stream (prevents “nothing happens” if S3 fails)
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . basename($fname) . '"');
  header('Content-Length: ' . strlen($pdfBytes));
  echo $pdfBytes;
  exit;
}

/* ---------- FILTERS ---------- */
$monthsEN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$selYear  = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selMonth = (int)date('n'); // month filter removed → always current month for KPI cards

// Build year options from data
$years = [];
$yrsStmt = $pdo->query("
  SELECT y FROM (
    SELECT DISTINCT YEAR(issue_date) AS y FROM purchase_order WHERE issue_date IS NOT NULL
    UNION
    SELECT DISTINCT YEAR(receive_date) AS y FROM goods_receipt WHERE receive_date IS NOT NULL
  ) t ORDER BY y DESC
");
$years = $yrsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [$selYear];

/* ---------- DATA for visible page ---------- */
$categoriesCount = (int)$pdo->query("SELECT COUNT(*) FROM category")->fetchColumn();
$totalItems      = (int)$pdo->query("SELECT COUNT(*) FROM item")->fetchColumn();
$totalStockValue = (float)$pdo->query("SELECT COALESCE(SUM(unit_cost * stock_quantity),0) FROM item")->fetchColumn();
$totalPOs        = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order")->fetchColumn();

$receivedGoodsValue = (float)$pdo->query("
  SELECT COALESCE(SUM(pod.landed_cost),0)
  FROM purchase_order_details pod
  JOIN purchase_order po ON po.po_id = pod.po_id
  WHERE po.receive_date IS NOT NULL
")->fetchColumn();

/* Month-on-month metrics (current month vs previous month) Montly Restock Trend */
$curMonthQtyStmt = $pdo->prepare("
  SELECT COALESCE(SUM(pod.quantity),0) qty
  FROM purchase_order_details pod
  JOIN purchase_order po ON po.po_id = pod.po_id
  WHERE po.issue_date IS NOT NULL
    AND YEAR(po.issue_date) = :y AND MONTH(po.issue_date) = :m
");
$curMonthQtyStmt->execute([':y' => $selYear, ':m' => $selMonth]);
$curMonthQty = (int)$curMonthQtyStmt->fetchColumn();

$prevY = $selYear;
$prevM = $selMonth - 1;
if ($prevM === 0) {
  $prevM = 12;
  $prevY--;
}
$prevMonthQtyStmt = $pdo->prepare("
  SELECT COALESCE(SUM(pod.quantity),0) qty
  FROM purchase_order_details pod
  JOIN purchase_order po ON po.po_id = pod.po_id
  WHERE po.issue_date IS NOT NULL
    AND YEAR(po.issue_date) = :y AND MONTH(po.issue_date) = :m
");
$prevMonthQtyStmt->execute([':y' => $prevY, ':m' => $prevM]);
$prevMonthQty = (int)$prevMonthQtyStmt->fetchColumn();
$momTrend = ($prevMonthQty > 0) ? round((($curMonthQty - $prevMonthQty) / $prevMonthQty) * 100, 1) : 0.0;

/* Best Category (top 6) */
$bestCategoryStmt = $pdo->query("
  SELECT c.category_name AS category,
         COALESCE(SUM(i.unit_cost * i.stock_quantity),0) AS stock_value,
         COALESCE(COUNT(DISTINCT po.po_id),0)          AS restock_freq
  FROM category c
  LEFT JOIN item i ON i.category_id = c.category_id
  LEFT JOIN purchase_order_details pod ON pod.item_id = i.item_id
  LEFT JOIN purchase_order po ON po.po_id = pod.po_id
  GROUP BY c.category_id, c.category_name
  ORDER BY stock_value DESC
");
$bestCategories = $bestCategoryStmt->fetchAll(PDO::FETCH_ASSOC);

/* Order Summary (Jan–Dec for selected year) */
$labels = array_values($monthsEN);
$orderedSeries   = array_fill(0, 12, null);
$deliveredSeries = array_fill(0, 12, null);

$orderedStmt = $pdo->prepare("
  SELECT MONTH(po.issue_date) m, SUM(pod.quantity * pod.unit_price) ordered_rm
  FROM purchase_order_details pod
  JOIN purchase_order po ON po.po_id = pod.po_id
  WHERE po.issue_date IS NOT NULL AND YEAR(po.issue_date) = :y
  GROUP BY MONTH(po.issue_date)
");
$orderedStmt->execute([':y' => $selYear]);
foreach ($orderedStmt as $r) {
  $orderedSeries[(int)$r['m'] - 1] = round((float)$r['ordered_rm'], 2);
}

$deliveredStmt = $pdo->prepare("
  SELECT MONTH(gr.receive_date) m, SUM(grd.quantity * pod.unit_price) delivered_rm
  FROM goods_receipt_details grd
  JOIN goods_receipt gr ON gr.receipt_id = grd.receipt_id
  JOIN purchase_order_details pod ON pod.po_id = gr.po_id AND pod.item_id = grd.item_id
  WHERE gr.receive_date IS NOT NULL AND YEAR(gr.receive_date) = :y
  GROUP BY MONTH(gr.receive_date)
");
$deliveredStmt->execute([':y' => $selYear]);
foreach ($deliveredStmt as $r) {
  $deliveredSeries[(int)$r['m'] - 1] = round((float)$r['delivered_rm'], 2);
}

/* PO Performance table */
$perfStmt = $pdo->prepare("
  SELECT s.company_name AS supplier,
             COUNT(po.po_id) AS total_pos,
             ROUND(AVG(CASE WHEN po.receive_date IS NOT NULL THEN DATEDIFF(po.receive_date, po.issue_date) END),1) AS avg_cycle_days,
             ROUND(
               SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL AND po.receive_date <= po.expected_date THEN 1 ELSE 0 END)
               / NULLIF(SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END),0) * 100,1) AS on_time_rate
      FROM purchase_order po
      JOIN supplier s ON s.supplier_id = po.supplier_id
      WHERE po.expected_date IS NOT NULL
      GROUP BY s.supplier_id,s.company_name
      ORDER BY on_time_rate DESC, avg_cycle_days ASC
");
$perfStmt->execute(); 
$poPerf = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Reports · Inventory Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/theme.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .chart-wrap {
      height: 340px;
      overflow: visible;
      padding: 8px 8px 24px 8px;
    }

    .top-actions form {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .select-pill {
      height: 34px;
    }
  </style>
</head>

<?php
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['flash_error'])) {
  echo '<div style="margin:12px 16px;padding:10px 12px;background:#fff6f6;border:1px solid #ffdada;border-radius:8px;color:#b00020">'
    . htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES)
    . '</div>';
  unset($_SESSION['flash_error']);
}
?>

<body class="reports-page">
  <div class="content">
    <div class="dashboard-wrap">

      <!-- Row 1 (Overview + Best Category) -->
      <div class="dashboard-row-1">
        <div class="dash-card">
          <div class="dash-card-header">
            <h3 class="dash-card-title">Overview</h3>
          </div>
          <div class="dash-icon-metrics" style="grid-template-columns:repeat(3,1fr);gap:16px;">
            <div class="dash-icon-metric">
              <div class="dash-icon blue-bg">📦</div>
              <div>
                <div class="dash-icon-value"><?= e(number_format($categoriesCount)) ?></div>
                <div class="dash-icon-label">Stock Categories</div>
              </div>
            </div>
            <div class="dash-icon-metric">
              <div class="dash-icon orange-bg">🧾</div>
              <div>
                <div class="dash-icon-value"><?= e(number_format($totalItems)) ?></div>
                <div class="dash-icon-label">Total Item</div>
              </div>
            </div>
            <div class="dash-icon-metric">
              <div class="dash-icon purple-bg">💰</div>
              <div>
                <div class="dash-icon-value"><?= e(moneyMY($totalStockValue)) ?></div>
                <div class="dash-icon-label">Total Stock Value</div>
              </div>
            </div>
          </div>
          <div class="dash-metrics-grid purchase-grid">
            <div class="dash-metric">
              <div class="dash-metric-value"><?= e(number_format($totalPOs)) ?></div>
              <div class="dash-metric-label">Total PO</div>
            </div>
            <div class="dash-metric">
              <div class="dash-metric-value"><?= e(moneyMY($receivedGoodsValue)) ?></div>
              <div class="dash-metric-label">Received Goods Value</div>
            </div>
            <div class="dash-metric">
              <div class="dash-metric-value"><?= e(($momTrend >= 0 ? '+' : '') . $momTrend . '%') ?></div>
              <div class="dash-metric-label">Monthly Restock Trend</div>
            </div>
          </div>
        </div>

        <div class="dash-card report-bestcat">
          <div class="dash-card-header" style="gap:12px;">
            <h3 class="dash-card-title">Best Performing Category</h3>
            <div class="dash-actions">
              <div><a class="btn btn-secondary slim" href="?page=reports&download=bestcat">Download All</a>
                <a class="btn btn-secondary slim" href="?page=reports&view=bestcat">See All</a>
              </div>
            </div>
          </div>
          <div class="table table-clean">
            <div class="t-head" style="grid-template-columns:1.6fr 1.2fr 1fr;">
              <div>Category</div>
              <div>Stock Value</div>
              <div>Restock Frequency</div>
            </div>
            <?php foreach ($bestCategories as $row): ?>
              <div class="t-row" style="grid-template-columns:1.6fr 1.2fr 1fr;">
                <div><?= e($row['category'] ?: '—') ?></div>
                <div><?= e(moneyMY($row['stock_value'])) ?></div>
                <div><?= e((int)$row['restock_freq']) ?> PO</div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Row 2 (Order Summary) -->
      <div class="dashboard-row-3">
        <div class="dash-card">
          <div class="dash-card-header" style="align-items:flex-start;">
            <div>
              <h3 class="dash-card-title">Order Summary</h3>
            </div>
            <div class="top-actions">
              <form method="get" action="" id="yearForm">
                <input type="hidden" name="page" value="reports">
                <select name="year" class="select-pill" aria-label="Year" onchange="document.getElementById('yearForm').submit()">
                  <?php foreach ($years as $y): ?>
                    <option value="<?= (int)$y ?>" <?= ((int)$y) === $selYear ? 'selected' : '' ?>><?= (int)$y ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>
          </div>

          <div class="chart-wrap"><canvas id="orderChart"></canvas></div>
          <div class="chart-legend" style="text-align:center;padding:8px 0 2px;">
            <span class="legend-item">
              <span class="legend-color blue" style="display:inline-block;width:10px;height:10px;border-radius:999px;"></span>Ordered</span>
             </span>
              <span style="display:inline-block;width:18px;"></span>
            <span class="legend-item">
              <span class="legend-color pink" style="display:inline-block;width:10px;height:10px;border-radius:999px;"></span>Delivered</span>
          </span>
          </div>
        </div>
      </div>

      <!-- Row 3 (PO Performance) -->
      <div class="dashboard-row-4">
        <div class="dash-card">
          <div class="dash-card-header">
            <h3 class="dash-card-title">Purchase Order Performance</h3>
            <div class="dash-actions">
              <div><a class="btn btn-secondary slim" href="?page=reports&download=po">Download All</a>
                <a class="btn btn-secondary slim" href="?page=reports&view=po">See All</a>
              </div>
            </div>
          </div>
          <div class="table table-clean">
            <div class="t-head" style="grid-template-columns:2fr .8fr 1.1fr 1fr .9fr;">
              <div>Supplier</div>
              <div>Total POs</div>
              <div>Avg Cycle (Days)</div>
              <div>On-Time %</div>
              <div>Performance</div>
            </div>
            <?php foreach ($poPerf as $r):
              $rate  = (float)($r['on_time_rate'] ?? 0);
              $label = perfLabel($rate);
              $color = ($label === 'Excellent' ? '#10b981' : ($label === 'Average' ? '#f59e0b' : '#ef4444'));
            ?>
              <div class="t-row" style="grid-template-columns:2fr .8fr 1.1fr 1fr .9fr;">
                <div><?= e($r['supplier']) ?></div>
                <div><?= e((int)$r['total_pos']) ?></div>
                <div><?= e($r['avg_cycle_days'] ?? '—') ?></div>
                <div><?= e($rate) ?>%</div>
                <div style="color:<?= $color ?>;font-weight:600;"><?= e($label) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- See All modal -->
  <?php if (isset($_GET['view'])):
    echo '<div class="overlay visible"><div class="modal" style="width:860px;max-width:96vw">';
    echo '<div class="modal-head"><strong>' . ($_GET['view'] === 'bestcat' ? 'All Categories' : 'All Suppliers Performance') . '</strong>';
    echo '<button class="modal-x" onclick="location.href=location.pathname+\'?page=reports\'">✕</button></div><div class="modal-body">';
    if ($_GET['view'] === 'bestcat') {
      $all = $pdo->query("SELECT c.category_name AS category,
                                 COALESCE(SUM(i.unit_cost * i.stock_quantity),0) AS stock_value,
                                 COUNT(DISTINCT po.po_id) AS restock_freq
                          FROM category c
                          LEFT JOIN item i ON i.category_id=c.category_id
                          LEFT JOIN purchase_order_details pod ON pod.item_id=i.item_id
                          LEFT JOIN purchase_order po ON po.po_id=pod.po_id
                          GROUP BY c.category_id,c.category_name
                          ORDER BY stock_value DESC")->fetchAll(PDO::FETCH_ASSOC);
      echo '<div class="table table-clean"><div class="t-head" style="grid-template-columns:1.6fr 1.2fr 1fr;"><div>Category</div><div>Stock Value</div><div>Restock Frequency</div></div>';
      foreach ($all as $r) {
        echo '<div class="t-row" style="grid-template-columns:1.6fr 1.2fr 1fr;">
                <div>' . e($r['category']) . '</div><div>' . e(moneyMY($r['stock_value'])) . '</div><div>' . (int)$r['restock_freq'] . ' PO</div>
              </div>';
      }
      echo '</div>';
    } else {
      $stmt = $pdo->prepare("SELECT s.company_name AS supplier, COUNT(po.po_id) total_pos,
                                    ROUND(AVG(CASE WHEN po.receive_date IS NOT NULL THEN DATEDIFF(po.receive_date, po.issue_date) END),1) avg_cycle_days,
                                    ROUND(SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL AND po.receive_date <= po.expected_date THEN 1 ELSE 0 END)
                                          / NULLIF(SUM(CASE WHEN po.receive_date IS NOT NULL AND po.expected_date IS NOT NULL THEN 1 ELSE 0 END),0) * 100,1) on_time_rate
                             FROM purchase_order po
                             JOIN supplier s ON s.supplier_id = po.supplier_id
                             WHERE po.expected_date IS NOT NULL
                             GROUP BY s.supplier_id,s.company_name
                             ORDER BY on_time_rate DESC, avg_cycle_days ASC");
      $stmt->execute();   
      $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
      echo '<div class="table table-clean"><div class="t-head" style="grid-template-columns:2fr .8fr 1.1fr 1fr;">
              <div>Supplier</div><div>Total POs</div><div>Avg Cycle (Days)</div><div>On-Time %</div></div>';
      foreach ($all as $r) {
        echo '<div class="t-row" style="grid-template-columns:2fr .8fr 1.1fr 1fr;">
                <div>' . e($r['supplier']) . '</div><div>' . (int)$r['total_pos'] . '</div><div>' . e($r['avg_cycle_days'] ?? "—") . '</div><div>' . e($r['on_time_rate'] ?? 0) . '%</div>
              </div>';
      }
      echo '</div>';
    }
    echo '</div></div>';
  endif; ?>

  <script>
    const labels = <?= json_encode($labels) ?>;
    const ordered = <?= json_encode($orderedSeries) ?>;
    const delivered = <?= json_encode($deliveredSeries) ?>;

    const values = [...ordered, ...delivered].filter(v => v !== null && isFinite(v));
    const yMin = values.length ? Math.max(0, Math.floor(Math.min(...values) * 0.85)) : 0;
    const yMax = values.length ? Math.ceil(Math.max(...values) * 1.15) : 10;

    const hoverLinePlugin = {
      id: 'hoverLine',
      afterDatasetsDraw(chart) {
        const {
          ctx,
          tooltip,
          chartArea: {
            top,
            bottom
          }
        } = chart;
        if (!tooltip || !tooltip.getActiveElements().length) return;
        const x = tooltip.getActiveElements()[0].element.x;
        ctx.save();
        ctx.beginPath();
        ctx.setLineDash([4, 4]);
        ctx.moveTo(x, top);
        ctx.lineTo(x, bottom);
        ctx.lineWidth = 1;
        ctx.strokeStyle = '#cbd5e1';
        ctx.stroke();
        ctx.restore();
      }
    };

    const ctx = document.getElementById('orderChart');
    if (ctx) {
      const nf = new Intl.NumberFormat('en-MY');

      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
              label: 'Ordered',
              data: ordered,
              tension: 0.35,
              cubicInterpolationMode: 'monotone',
              pointRadius: 3,
              pointHoverRadius: 5,
              spanGaps: false,
              fill: false
            },
            {
              label: 'Delivered',
              data: delivered,
              tension: 0.35,
              cubicInterpolationMode: 'monotone',
              pointRadius: 3,
              pointHoverRadius: 5,
              spanGaps: false,
              fill: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          layout: {
            padding: {
              top: 8,
              right: 8,
              bottom: 8,
              left: 8
            }
          },
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              padding: 10,
              backgroundColor: '#fff',
              borderColor: '#e5e7eb',
              borderWidth: 1,
              displayColors: false,
              titleColor: '#94a3b8',
              titleFont: {
                weight: '600',
                size: 10
              },
              bodyColor: '#0f172a',
              bodyFont: {
                weight: '700',
                size: 13
              },
              callbacks: {
                title: (items) => labels[items[0].dataIndex],
                label: (c) => `${c.dataset.label}: ${nf.format(c.parsed.y ?? 0)}`
              }
            }
          },
          scales: {
            x: {
              grid: {
                color: '#f1f5f9'
              },
              ticks: {
                color: '#6b7280'
              }
            },
            y: {
              suggestedMin: yMin,
              suggestedMax: yMax,
              grid: {
                color: '#f1f5f9'
              },
              ticks: {
                color: '#6b7280',
                callback: (v) => nf.format(v)
              }
            }
          }
        },
        plugins: [hoverLinePlugin]
      });
    }
  </script>
</body>

</html>
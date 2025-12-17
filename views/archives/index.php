<?php
// views/archives/index.php
declare(strict_types=1);

$PROJECT_ROOT = dirname(__DIR__, 2); // from /views/archives → project root
require_once $PROJECT_ROOT . '/app/s3_client.php';
require_once $PROJECT_ROOT . '/app/db.php'; 
require_once $PROJECT_ROOT . '/app/Auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
Auth::check_staff(['view_archives']);


if (!isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="padding:16px;color:#b00020;">Could not load DB. Make sure app/db.php creates $pdo = new PDO(...)</div>');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$s3     = s3_client();
$bucket = s3_bucket();

/* --- Filters --- */
$type   = isset($_GET['type'])   ? trim((string)$_GET['type'])   : '';
$module = isset($_GET['module']) ? trim((string)$_GET['module']) : '';
$year   = isset($_GET['year'])   ? (int)$_GET['year']            : 0;

$where = [];
$bind  = [];
if ($type !== '')   { $where[] = 'export_type = :t';        $bind[':t'] = $type; }
if ($module !== '') { $where[] = 'module_exported = :m';    $bind[':m'] = $module; }
if ($year)          { $where[] = 'YEAR(export_time) = :y';  $bind[':y'] = $year; }

$sql = "SELECT export_id, user_id, export_type, module_exported, file_name, file_path, export_time
        FROM export_history ";
if ($where) $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
$sql .= 'ORDER BY export_time DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* --- Options for selects --- */
$optTypes   = $pdo->query("SELECT DISTINCT export_type FROM export_history ORDER BY export_type")->fetchAll(PDO::FETCH_COLUMN);
$optModules = $pdo->query("SELECT DISTINCT module_exported FROM export_history ORDER BY module_exported")->fetchAll(PDO::FETCH_COLUMN);
$optYears   = $pdo->query("SELECT DISTINCT YEAR(export_time) y FROM export_history ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

/* --- Presigned URL helper with disposition & filename --- */
if (!function_exists('presigned_url')) {
  function presigned_url($s3, $bucket, $key, int $minutes = 10, string $disposition = 'inline', string $filename = 'file.pdf'): string
  {
      $params = [
          'Bucket' => $bucket,
          'Key'    => $key,
          'ResponseContentDisposition' => sprintf('%s; filename="%s"', $disposition, $filename),
          'ResponseContentType'        => 'application/pdf',
      ];
      $cmd = $s3->getCommand('GetObject', $params);
      $req = $s3->createPresignedRequest($cmd, "+{$minutes} minutes");
      return (string)$req->getUri();
  }
}

/* --- Safe echo helper (only if not already defined globally) --- */
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Archived Exports</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
  <div class="content">
    <div class="card">
      <div class="page-head">
        <h2>Archived Exports</h2>
        <form class="filters"
              method="get"
              action="<?= e(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>">
          <input type="hidden" name="page" value="<?= isset($_GET['page']) ? e($_GET['page']) : 'archives' ?>">

          <select name="type" class="select-pill">
            <option value="">All Types</option>
            <?php foreach ($optTypes as $t): ?>
              <option value="<?= e($t) ?>" <?= $t === $type ? 'selected' : '' ?>><?= strtoupper(e($t)) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="module" class="select-pill">
            <option value="">All Modules</option>
            <?php foreach ($optModules as $m): ?>
              <option value="<?= e($m) ?>" <?= $m === $module ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="year" class="select-pill">
            <option value="">All Years</option>
            <?php foreach ($optYears as $y): ?>
              <option value="<?= (int)$y ?>" <?= ((int)$y) === $year ? 'selected' : '' ?>><?= (int)$y ?></option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-secondary slim" type="submit">Filter</button>
        </form>
      </div>

      <div class="table table-clean">
        <div class="t-head" style="grid-template-columns:1.6fr .9fr .9fr 1.2fr .9fr;">
          <div>File</div>
          <div>Type</div>
          <div>Module</div>
          <div>Exported</div>
          <div>Actions</div>
        </div>

        <?php if (!$rows): ?>
          <div class="t-row" style="grid-template-columns:1.6fr .9fr .9fr 1.2fr .9fr;">
            <div>No exports found.</div>
            <div>—</div>
            <div>—</div>
            <div>—</div>
            <div>—</div>
          </div>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
            $viewUrl = '';
            $dlUrl   = '';
            $error   = null;
            try {
              $viewUrl = presigned_url($s3, $bucket, $r['file_path'], 10, 'inline',     $r['file_name']);
              $dlUrl   = presigned_url($s3, $bucket, $r['file_path'], 10, 'attachment', $r['file_name']);
            } catch (Throwable $e) {
              $error = $e->getMessage();
            }
            ?>
            <div class="t-row" style="grid-template-columns:1.6fr .9fr .9fr 1.2fr .9fr;">
              <div><?= e($r['file_name']) ?></div>
              <div><?= strtoupper(e($r['export_type'])) ?></div>
              <div><?= e($r['module_exported']) ?></div>
              <div><?= e($r['export_time']) ?></div>
              <div>
                <span class="row-actions">
                  <?php if ($viewUrl && $dlUrl): ?>
                    <a class="btn btn-ghost slim"     href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">Open</a>
                    <a class="btn btn-secondary slim" href="<?= e($dlUrl)   ?>" download>Download</a>
                  <?php else: ?>
                    <span style="color:#b00020;font-weight:600">Creds expired</span>
                  <?php endif; ?>
                </span>
              </div>
            </div>
            <?php if (!empty($error)): ?>
              <div style="grid-column:1/-1;color:#b00020;padding:6px 0 10px 0;">
                <?= e($error) ?><br>
                → Update <code>config/aws.php</code> with a fresh <b>Session Token</b> and reload.
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>

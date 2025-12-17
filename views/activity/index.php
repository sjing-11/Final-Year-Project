<?php
// views/activity/index.php
declare(strict_types=1);

/* --- Load PDO --- */
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
// Fallback for 'e()' if helpers.php wasn't loaded
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* --- Load Auth --- */
// session_start() needed for Auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
// Check capability
Auth::check_staff(['view_logs']);

/* --- time_ago helper --- */
if (!function_exists('time_ago')) {
    function time_ago($datetime, $full = false): string {
        // Use a default timezone
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $now = new DateTime('now', $tz);
        
        try {
            $ago = new DateTime($datetime, $tz);
        } catch (Exception $e) {
            return 'Invalid date'; // Handle null or invalid datetime
        }

        $diff = $now->diff($ago);

        // Calculate weeks separately
        $weeks = floor($diff->d / 7);
        // Subtract the days that are part of the weeks
        $diff->d -= $weeks * 7; 
        
        $string = array(
            'y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day',
            'h' => 'hour', 'i' => 'minute', 's' => 'second',
        );

        foreach ($string as $k => &$v) {
            // Special check for 'w' (weeks)
            if ($k === 'w') {
                if ($weeks > 0) {
                    $v = $weeks . ' ' . $v . ($weeks > 1 ? 's' : '');
                } else {
                    unset($string[$k]);
                }
            // Check standard DateInterval properties
            } elseif ($diff->$k) { 
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}


/* --- Pagination Logic --- */
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$items_per_page = 10; // Logs per page
$offset = ($page - 1) * $items_per_page;

$total_items = 0;
$total_pages = 1;
$logs = [];
$errorMsg = null;

try {
  // 1. Get total count
  $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log");
  $total_stmt->execute();
  $total_items = (int)$total_stmt->fetchColumn();
  $total_pages = (int)ceil($total_items / $items_per_page);
  
  // Cap page number
  if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $items_per_page;
  }

  // 2. Fetch paginated logs (join with user table)
  $sql = "SELECT a.*, u.username 
          FROM activity_log a
          LEFT JOIN user u ON a.user_id = u.user_id
          ORDER BY a.timestamp DESC
          LIMIT :limit OFFSET :offset";
  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':limit', $items_per_page, PDO::PARAM_INT);
  $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  // Catch database error
  $errorMsg = "Database Error: " . $e->getMessage();
  error_log($errorMsg); // Log to server logs
}

?>
<section class="page notifications-page">
  <div class="card">
    <div class="page-head">
      <h2 class="table-title">Activity Log</h2>
      <div class="actions">
      </div>
    </div>

    <!-- Show DB Error -->
    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <div class="notif-list">
      <!-- Empty state -->
      <?php if (empty($logs) && !$errorMsg): ?>
        <div class="notif-empty-state" style="padding: 24px 16px; text-align: center; color: #667085;">
          There is no activity to show.
        </div>
      <?php else: ?>
        <!-- Log list -->
        <?php foreach ($logs as $log): ?>
          <article class="notif-item">
            <div class="notif-main">
              <h3 class="notif-title">
                [<?= e($log['module']) ?>] by <?= e($log['username'] ?? 'System') ?>
              </h3>
              <p class="notif-text"><?= e($log['description']) ?></p>
              <div class="notif-time">
                <?= e(time_ago($log['timestamp'])) ?> --
                (IP: <?= e($log['ip_address']) ?>)
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
      
    </div>

    <!-- Pagination -->
    <div class="pager-rail">
      <div class="left">
        <a href="?page=activity&p=<?= e($page - 1) ?>" 
           class="btn btn-secondary <?= ($page <= 1) ? 'disabled' : '' ?>"
           <?= ($page <= 1) ? 'aria-disabled="true" onclick="event.preventDefault()"' : '' ?>>
          Previous
        </a>
      </div>
      <div class="mid">
        <span class="page-note">Page <?= e($page) ?> of <?= e($total_pages) ?></span>
      </div>
      <div class="right">
        <a href="?page=activity&p=<?= e($page + 1) ?>"
           class="btn btn-secondary <?= ($page >= $total_pages) ? 'disabled' : '' ?>"
           <?= ($page >= $total_pages) ? 'aria-disabled="true" onclick="event.preventDefault()"' : '' ?>>
          Next
        </a>
      </div>
    </div>
  </div>
</section>
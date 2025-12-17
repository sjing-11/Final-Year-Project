<?php
// views/notifications/index.php
declare(strict_types=1);

// Requires $pdo and $u (user) to be in scope
if (!isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error in notifications page.');
}
if (!isset($u) || !is_array($u)) {
  $u = ['user_id' => 0, 'name' => 'Guest'];
}

// Fallback for security helper
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$current_user_id = $u['user_id'] ?? 0;
$notifications = [];
$errorMsg = null;

// Date/Time helper
if (!function_exists('format_datetime')) {
    function format_datetime($datetime) {
        if (empty($datetime)) return 'N/A';
        try {
            $date = new DateTime($datetime);
            // Use consistent timezone
            $date->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
            return $date->format('d M Y, h:i A');
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }
}

// Pagination logic
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$items_per_page = 10; // Number of notifications per page
$offset = ($page - 1) * $items_per_page;

$total_items = 0;
$total_pages = 1;

// Fetch paginated notifications for this user
if ($current_user_id > 0) {
    try {
        // Get total count
        $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE user_id = :id");
        $total_stmt->execute([':id' => $current_user_id]);
        $total_items = (int)$total_stmt->fetchColumn();
        $total_pages = (int)ceil($total_items / $items_per_page);
        
        // Cap page number
        if ($page > $total_pages && $total_pages > 0) {
          $page = $total_pages;
          $offset = ($page - 1) * $items_per_page;
        }

        // Fetch current page's notifications
        $sql = "SELECT * FROM notification 
                WHERE user_id = :id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $current_user_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $items_per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        $errorMsg = "Notification page fetch error: " . $e->getMessage();
        error_log($errorMsg);
    }
}

?>
<section class="page notifications-page">
  <div class="card">
    <div class="page-head">
      <h2 class="table-title">Notifications</h2>
      <div class="actions">
        </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <div class="notif-list">
      <?php if (empty($notifications) && !$errorMsg): ?>
        <div class="notif-empty-state" style="padding: 24px 16px; text-align: center; color: #667085;">
          You have no notifications.
        </div>
      <?php else: ?>
        <?php foreach ($notifications as $notif): ?>
          <article class="notif-item <?php echo $notif['is_read'] ? 'is-read' : 'is-unread'; ?>">
            <div class="notif-main">
              <h3 class="notif-title">
                <?php if (!$notif['is_read']): ?>
                  <span class="unread-dot" title="Unread"></span>
                <?php endif; ?>
                <?php echo e($notif['title']); ?>
              </h3>
              <p class="notif-text"><?php echo e($notif['message']); ?></p>
              <div class="notif-time"><?php echo format_datetime($notif['created_at']); ?></div>
            </div>
            <div class="notif-actions">
              <?php if (!empty($notif['link'])): ?>
                <a href="<?php echo e($notif['link']); ?>" class="btn btn-secondary slim notif-mark-read" data-id="<?php echo $notif['notification_id']; ?>">View</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination Nav -->
    <?php if ($total_pages > 1): ?>
      <div class="pager-rail"> 
        <div class="left">
          <a href="?page=notifications&p=<?= e($page - 1) ?>" 
             class="btn btn-secondary <?= ($page <= 1) ? 'disabled' : '' ?>"
             <?= ($page <= 1) ? 'aria-disabled="true" onclick="event.preventDefault()"' : '' ?>>
            Previous
          </a>
        </div>
        <div class="mid">
          <span class="page-note">Page <?= e($page) ?> of <?= e($total_pages) ?></span>
        </div>
        <div class="right">
          <a href="?page=notifications&p=<?= e($page + 1) ?>"
             class="btn btn-secondary <?= ($page >= $total_pages) ? 'disabled' : '' ?>"
             <?= ($page >= $total_pages) ? 'aria-disabled="true" onclick="event.preventDefault()"' : '' ?>>
            Next
          </a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>


<script>
// Mark single item as read on click
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.notif-mark-read').forEach(button => {
        button.addEventListener('click', function(e) {
            const item = button.closest('.notif-item');
            if (item && item.classList.contains('is-read')) {
                return; // Already read, just navigate
            }
            
            e.preventDefault(); // Stop navigation to mark as read first
            const notifId = button.dataset.id;
            const href = button.href;

            fetch(`/index.php?page=mark_notifications&action=mark_read&id=${notifId}`, { method: 'POST' })
                .then(res => res.json())
                .finally(() => {
                    // Whether API succeeded or failed, navigate anyway
                    window.location.href = href; 
                });
        });
    });

    // Listen for the 'notifications-marked-all-read' event broadcast from topbar.php
    document.addEventListener('notifications-marked-all-read', function() {
        // Find all unread items on this page and update their styles
        document.querySelectorAll('.notif-item.is-unread').forEach(item => {
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            
            // Remove the unread dot
            const unreadDot = item.querySelector('.unread-dot');
            if (unreadDot) {
                unreadDot.remove();
            }
        });
    });
});
</script>
<?php
// views/partials/topbar.php

// Load Auth to check permissions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';

// If $u (user) not set by index.php, fetch it
if (!isset($u) || !is_array($u)) {
    $u = Auth::user(); // Get user from session
    if ($u === null) {
        // Fallback for logged-out state
        $u = ['user_id' => 0, 'username' => 'Guest'];
    }
}


// Requires $pdo from public/index.php
if (!isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error in topbar. ($pdo is not set)');
}

$current_user_id = $u['user_id'] ?? 0;
$unread_count = 0;
$notifications = [];

if ($current_user_id > 0) {
    try {
        // 1. Get unread notification count
        $sql_count = "SELECT COUNT(*) FROM notification WHERE user_id = :id AND is_read = 0";
        $stmt_count = $pdo->prepare($sql_count);
        $stmt_count->execute([':id' => $current_user_id]);
        $unread_count = (int)$stmt_count->fetchColumn();

        // 2. Get recent 5 notifications for dropdown
        $sql_list = "SELECT * FROM notification 
                     WHERE user_id = :id 
                     ORDER BY created_at DESC 
                     LIMIT 5";
        $stmt_list = $pdo->prepare($sql_list);
        $stmt_list->execute([':id' => $current_user_id]);
        $notifications = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log("Topbar notification fetch error: " . $e->getMessage());
    }
}

// Helper for "time ago" format
if (!function_exists('time_ago')) {
    function time_ago($datetime, $full = false): string {
        // Force consistent timezone
        $tz = new DateTimeZone('Asia/Kuala_Lumpur');
        $now = new DateTime("now", $tz);
        
        try {
            // Assume DB time string is also in same timezone
            $ago = new DateTime($datetime, $tz);
        } catch (Exception $e) {
            return 'just now'; // Fallback for invalid date
        }
        $diff = $now->diff($ago);

        $weeks = floor($diff->d / 7);
        $days = $diff->d - ($weeks * 7);

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week', 
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );

        $diff_values = [
            'y' => $diff->y,
            'm' => $diff->m,
            'w' => $weeks,
            'd' => $days,
            'h' => $diff->h,
            'i' => $diff->i,
            's' => $diff->s,
        ];

        foreach ($string as $k => &$v) {
            if ($diff_values[$k]) {
                $v = $diff_values[$k] . ' ' . $v . ($diff_values[$k] > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

// Fallback for security helper
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>


<header class="topbar">
  <!-- Standard HTML form submit -->
  <form class="searchbar" action="/index.php" method="get">
    <input type="text" class="input" name="query" placeholder="Search product, supplier, order" required>
    <input type="hidden" name="page" value="search">
    <button class="icon-btn" type="submit" aria-label="Search"><span>🔍</span></button>
  </form>

  <div class="top-actions">
    
    <a id="bellBtn" class="bell-btn <?php echo ($unread_count > 0) ? 'has-unread' : ''; ?>" 
       href="?page=notifications" 
       title="Notifications" 
       aria-label="Notifications"
       onclick="toggleNotifications(event)">
       🔔
    </a>

    <div id="notifDropdown" class="notif-dropdown">
        <div class="notif-header">
            <h3>Notifications</h3>
            <?php if ($unread_count > 0): ?>
                <a href="#" id="markAllReadBtn" class="mark-read-btn">Mark all as read</a>
            <?php endif; ?>
        </div>
        
        <div class="notif-body">
            <?php if (empty($notifications)): ?>
                <div class="notif-empty-state">You have no notifications.</div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <a href="<?php echo e($notif['link'] ?? '?page=notifications'); ?>" 
                       class="notif-list-item <?php echo $notif['is_read'] ? 'is-read' : ''; ?>"
                       data-id="<?php echo $notif['notification_id']; ?>">
                        
                        <div class="icon"></div>
                        <div class="content">
                            <span class="title"><?php echo e($notif['title']); ?></span>
                            <p class="message"><?php echo e($notif['message']); ?></p>
                            <span class="time"><?php echo time_ago($notif['created_at']); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="notif-footer"> 
            <a href="?page=notifications">View all notifications</a>
        </div>
    </div>

    <div class="avatar" title="<?php echo e(($u['username'] ?? 'Guest')); ?>">
      <?php echo isset($u['username']) ? strtoupper(substr($u['username'], 0, 1)) : 'G'; ?>
    </div>
    </div>
</header>

<script>
function toggleNotifications(event) {
    // Check if user clicked the bell icon directly
    if (event.currentTarget.id === 'bellBtn') {
        event.preventDefault(); // Stop link from navigating
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown) dropdown.classList.toggle('active');
    }
}

// Mark as Read Logic
document.addEventListener('DOMContentLoaded', function() {
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    const bellBtn = document.getElementById('bellBtn');
    
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Stop dropdown from closing
            
            // Call API to mark all as read
            fetch('/index.php?page=mark_notifications&action=mark_all_read', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Update UI
                        document.querySelectorAll('.notif-list-item').forEach(item => {
                            item.classList.add('is-read');
                        });
                        if (bellBtn) bellBtn.classList.remove('has-unread');
                        if (markAllReadBtn) markAllReadBtn.style.display = 'none';

                        // Broadcast an event to the rest of the page (e.g., to the main notifications page)
                        document.dispatchEvent(new CustomEvent('notifications-marked-all-read'));

                    } else {
                        console.error('Failed to mark all as read');
                    }
                })
                .catch(err => console.error('Fetch error:', err));
        });
    }

    // Mark single item as read
    document.querySelectorAll('.notif-list-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (item.classList.contains('is-read')) {
                return; // Already read, just navigate
            }
            
            e.preventDefault(); // Stop navigation to mark as read first
            const notifId = item.dataset.id;
            const href = item.href;

            fetch(`/index.php?page=mark_notifications&action=mark_read&id=${notifId}`, { method: 'POST' })
                .then(res => res.json())
                .finally(() => {
                    // Navigate anyway, even if API fails
                    window.location.href = href; 
                });
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notifDropdown');
        const bell = document.getElementById('bellBtn');
        if (dropdown && bell && !dropdown.contains(event.target) && !bell.contains(event.target)) {
            dropdown.classList.remove('active');
        }
    });
});
</script>
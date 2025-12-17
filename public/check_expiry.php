<?php
// public/check_expiry.php
// FINAL PRODUCTION VERSION
declare(strict_types=1);
ob_start();
header('Content-Type: application/json');

// --- 1. Define Root Path ---
$root = dirname(__DIR__); 
if (!file_exists($root . '/vendor/autoload.php')) {
    $root = __DIR__;
}

// --- 2. Load Database & Libraries ---
// Load DB
$loaded = false;
foreach (['/db.php', '/app/db.php', '/config/db.php'] as $path) {
    if (file_exists($root . $path)) { require_once $root . $path; $loaded = true; break; }
}
if (!$loaded) { die(json_encode(['status' => 'error', 'message' => 'DB not found'])); }

// LOAD AWS LIBRARY (The Critical Fix)
require_once $root . '/vendor/autoload.php'; 

// Load App Classes
require_once $root . '/app/Auth.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/InternalNotify.php';
require_once $root . '/app/ItemNotify.php';

// --- 3. Production Logic ---
try {
    // A. Find items expired <= Today that we haven't alerted yet
    $sql = "
        SELECT i.* FROM item i
        LEFT JOIN stock_alert sa 
            ON i.item_id = sa.item_id 
            AND sa.alert_type = 'Expired' 
            AND sa.resolved = 0
        WHERE i.expiry_date IS NOT NULL 
          AND i.expiry_date <= CURDATE() 
          AND i.stock_quantity > 0
          AND sa.alert_id IS NULL
    ";

    $stmt = $pdo->query($sql);
    $expiredItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;

    foreach ($expiredItems as $item) {
        $id = $item['item_id'];
        $name = $item['item_name'];
        $code = $item['item_code'];
        $expiry = $item['expiry_date'];
        $qty = (int)$item['stock_quantity'];

        // B. Mark as alerted in DB (Prevent Spam)
        $pdo->prepare("INSERT INTO stock_alert (item_id, alert_type, resolved) VALUES (?, 'Expired', 0)")->execute([$id]);

        // C. Send In-App Notification
        $notifMsg = "Item $code ($name) has EXPIRED on $expiry. Qty wasted: $qty.";
        $link = "/index.php?page=item_details&id=$id";
        InternalNotify::send($pdo, "Expiry Alert", $notifMsg, $link, null, "Admin");
        InternalNotify::send($pdo, "Expiry Alert", $notifMsg, $link, null, "Manager");

        // D. Send AWS SNS Email
        try {
            item_notify_expired(
                (string)$id, 
                $name, 
                $code, 
                (string)$expiry, 
                $qty
            );
        } catch (Throwable $e) {
            error_log("SNS Error for item $id: " . $e->getMessage());
        }
        $count++;
    }

    echo json_encode([
        'status' => 'success', 
        'message' => "Expiry check complete. $count new items flagged."
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
ob_end_flush();
?>
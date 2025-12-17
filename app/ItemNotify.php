<?php
// app/ItemNotify.php
declare(strict_types=1);

require_once __DIR__ . '/Notify.php';

/**
 * Notify when new item is created
 * EVENT: ITEM_MANAGEMENT
 */
function item_notify_created(string $itemId, string $itemName, string $actorEmail, string $actorRole): bool {
    $subject = "New Item Created: $itemName";
    $message = "A new item has been added to the system by an administrator.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Added By:  $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'ITEM_MANAGEMENT', 
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info',
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item details are updated
 * EVENT: ITEM_MANAGEMENT
 */
function item_notify_updated(string $itemId, string $itemName, string $actorEmail, string $actorRole): bool {
    $subject = "Item Updated: $itemName";
    $message = "An item's details were updated by an administrator.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Updated By: $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'ITEM_MANAGEMENT', 
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info',
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item is deleted
 * EVENT: ITEM_MANAGEMENT
 */
function item_notify_deleted(string $itemId, string $itemName, string $actorEmail, string $actorRole): bool {
    $subject = "Item DELETED: $itemName";
    $message = "An item has been deleted from the system by an administrator.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Deleted By: $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'ITEM_MANAGEMENT', 
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'warning',
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item stock is manually adjusted
 * EVENT: ITEM_MANAGEMENT
 */
function item_notify_adjusted(string $itemId, string $itemName, int $adjustmentQty, string $reason, string $actorEmail, string $actorRole): bool {
    $action = $adjustmentQty > 0 ? "Increased" : "Decreased";
    $subject = "Stock Adjusted: $itemName";
    $message = "An item's stock was manually adjusted by an administrator.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Action:    Stock $action by " . abs($adjustmentQty) . "\n"
             . "Reason:    $reason\n"
             . "Adjusted By: $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'ITEM_MANAGEMENT', 
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info',
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item is low on stock
 * EVENT: LOW_STOCK
 */
function item_notify_low_stock(string $itemId, string $itemName, string $itemCode, int $newStock): bool {
    $subject = "Low Stock Alert: $itemName ($itemCode)";
    $message = "An item is running low on stock and may require reordering.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Item Code: $itemCode\n"
             . "Current Stock: $newStock\n";

    return sns_notify($subject, $message, [
        'event'       => 'LOW_STOCK', 
        'severity'    => 'warning',
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item is OUT of stock
 * EVENT: OUT_OF_STOCK
 */
function item_notify_out_of_stock(string $itemId, string $itemName, string $itemCode): bool {
    $subject = "Out of Stock Alert: $itemName ($itemCode)";
    $message = "An item is now OUT OF STOCK.\n\n"
             . "Item ID:   #$itemId\n"
             . "Item Name: $itemName\n"
             . "Item Code: $itemCode\n"
             . "Current Stock: 0\n";

    return sns_notify($subject, $message, [
        'event'       => 'OUT_OF_STOCK', 
        'severity'    => 'critical', 
        'itemId'      => $itemId
    ]);
}

/**
 * Notify when item has EXPIRED
 * EVENT: EXPIRED
 */
function item_notify_expired(string $itemId, string $itemName, string $itemCode, string $expiryDate, int $stockQty): bool {
    $subject = "EXPIRY ALERT: $itemName ($itemCode)";
    $message = "CRITICAL: An item in inventory has reached its expiry date.\n\n"
             . "Item ID:      #$itemId\n"
             . "Item Name:    $itemName\n"
             . "Item Code:    $itemCode\n"
             . "Expiry Date:  $expiryDate\n"
             . "Stock Wasted: $stockQty units\n\n"
             . "Please dispose of this stock immediately and update inventory.";

    return sns_notify($subject, $message, [
        'event'       => 'EXPIRED', 
        'severity'    => 'critical', 
        'itemId'      => $itemId
    ]);
}
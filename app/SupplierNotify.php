<?php
// app/SupplierNotify.php
require_once __DIR__ . '/Notify.php';

/**
 * Notify when new supplier is created by Admin
 */
function supplier_notify_created(string $supplierId, string $supplierName, string $actorEmail, string $actorRole): bool {
    $subject = "New Supplier Created: $supplierName";
    
    $message = "A new supplier has been added to the system by an administrator.\n\n"
             . "Supplier ID:   #$supplierId\n"
             . "Supplier Name: $supplierName\n"
             . "Added By:      $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'SUPPLIER_CREATED',
        'supplierId'  => $supplierId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info'
    ]);
}

/**
 * Notify when supplier is deleted by Admin
 */
function supplier_notify_deleted(string $supplierId, string $supplierName, string $actorEmail, string $actorRole): bool {
    $subject = "Supplier DELETED: $supplierName";
    
    $message = "A supplier has been deleted from the system by an administrator.\n\n"
             . "Supplier ID:   #$supplierId\n"
             . "Supplier Name: $supplierName\n"
             . "Deleted By:    $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'SUPPLIER_DELETED',
        'supplierId'  => $supplierId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'warning'
    ]);
}

/**
 * Notify when supplier details are updated by Admin
 */
function supplier_notify_updated(string $supplierId, string $supplierName, string $actorEmail, string $actorRole): bool {
    $subject = "Supplier Updated: $supplierName";
    
    $message = "A supplier's details were updated by an administrator.\n\n"
             . "Supplier ID:   #$supplierId\n"
             . "Supplier Name: $supplierName\n"
             . "Updated By:    $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'SUPPLIER_UPDATED',
        'supplierId'  => $supplierId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info'
    ]);
}

/**
 * Notify when supplier updates their profile
 */
function supplier_notify_profile_updated(string $supplierId, string $supplierName, string $supplierEmail): bool {
    $subject = "Supplier Profile Updated: $supplierName";
    
    $message = "A supplier has updated their own profile details via the portal.\n\n"
             . "Supplier ID:   #$supplierId\n"
             . "Supplier Name: $supplierName\n"
             . "Supplier Email: $supplierEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'SUPPLIER_PROFILE_UPDATED',
        'supplierId'  => $supplierId,
        'actorRole'   => 'SUPPLIER', 
        'severity'    => 'info'
    ]);
}

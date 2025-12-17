<?php
// app/PoNotify.php
require_once __DIR__ . '/Notify.php';

/**
 * Standard all-caps version 
 *
 * @param string $raw The raw status string
 * @return string|null The normalized status or null if not found
 */
function po_normalize_status(string $raw): ?string {
  $r = strtolower(trim($raw));
  $map = [
    'created'          => 'CREATED',
    'pending'          => 'PENDING_APPROVAL',
    'approved'         => 'APPROVED',
    'rejected'         => 'REJECTED',
    'shipped'          => 'SHIPPED',
    'delayed'          => 'DELAYED',
    'received'         => 'RECEIVED',
    'issue'            => 'ISSUE',
    'completed'        => 'COMPLETED',
    'confirmed'        => 'CONFIRMED',
  ];
  return $map[$r] ?? null;
}

function po_notify_created(string $poId, string $creatorEmail, string $actorRole): bool {
  $subject = "New Purchase Order Created (PO #$poId)";
  
  $message = "A new Purchase Order has been created and requires attention.\n\n"
           . "PO Number:  #$poId\n"
           . "Created By: $creatorEmail\n"
           . "Status:     CREATED\n";

  return sns_notify($subject, $message, [
    'event'=>'PO_CREATED',
    'status'=>'CREATED',
    'poId'=>$poId,
    'actorRole'=>strtoupper($actorRole),
    'severity'=>'info'
  ]);
}

function po_notify_status_change(
    string $poId, 
    string $rawStatus, 
    string $actorEmail, 
    string $actorRole,
    ?string $supplierEmail = null 
): bool {
  // 1. Normalize the raw status
  $status = po_normalize_status($rawStatus);
  
  if ($status === null) {
    error_log("[SNS] Unknown status '$rawStatus' for PO $poId");
    return false;
  }
  
  $actorRole = strtoupper($actorRole); 

  // 2. Adjust status for supplier-specific events
  if ($actorRole === 'SUPPLIER') {
    if ($status === 'APPROVED') {
        $status = 'APPROVED_BY_SUPPLIER';
    } elseif ($status === 'REJECTED') {
        $status = 'REJECTED_BY_SUPPLIER';
    }
  }

  $subject = "PO #$poId Status Updated: $status";
  
  $message = "The status of a Purchase Order has been updated.\n\n"
           . "PO Number:  #$poId\n"
           . "New Status: $status\n"
           . "Updated By: $actorEmail\n"
           . "Actor Role: $actorRole\n";

  
  // 3. Build the attributes array
  $attributes = [
    'event'=>'PO_STATUS_CHANGED',
    'status'=>$status, 
    'poId'=>$poId,
    'actorRole'=>$actorRole
  ];

  // 4. Define supplier-facing statuses
  $supplierStatuses = [
    'PENDING_APPROVAL',
    'APPROVED_BY_SUPPLIER',
    'REJECTED_BY_SUPPLIER',
    'CONFIRMED',
    'SHIPPED'
  ];

  // 5. If we have a supplier email AND it's a supplier-facing status, add the attribute
  if (!empty($supplierEmail) && in_array($status, $supplierStatuses)) {
      $attributes['supplierEmail'] = $supplierEmail;
  }

  // 6. Send the status to SNS
  return sns_notify($subject, $message, $attributes);
}

function po_notify_deleted(string $poId, string $actorEmail, string $actorRole): bool {
  $subject = "Purchase Order DELETED (PO #$poId)";

  $message = "A Purchase Order has been deleted from the system.\n\n"
           . "PO Number:  #$poId\n"
           . "Deleted By: $actorEmail\n"
           . "Actor Role: $actorRole\n";
           
  return sns_notify($subject, $message, [
    'event'=>'PO_DELETED',
    'status'=>'DELETED',
    'poId'=>$poId,
    'actorRole'=>strtoupper($actorRole),
    'severity'=>'warning'
  ]);
}
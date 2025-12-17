<?php
// views/supplier_portal/profile_handler.php
declare(strict_types=1);
session_start();

// Authentication & security check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['supplier_id'])) {
    header('Location: /index.php?page=login');
    exit();
}

// Load PDO from db.php
$root = dirname(__DIR__, 2);
require_once $root . '/app/db.php'; 
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Notify.php';
require_once $root . '/app/SupplierNotify.php'; 
require_once $root . '/app/InternalNotify.php'; 
require_once $root . '/app/ActivityLogger.php';

// Get data from the form
$supplier_id    = $_POST['supplier_id'] ?? 0;
$contact_person = $_POST['contact_person'] ?? '';
$phone          = $_POST['phone'] ?? '';
$street_address = $_POST['street_address'] ?? '';
$city           = $_POST['city'] ?? '';
$postcode       = $_POST['postcode'] ?? '';
$state          = $_POST['state'] ?? '';

// Security check
if ((int)$supplier_id !== (int)$_SESSION['supplier_id']) {
    header('Location: /index.php?page=supplier_profile&status=error');
    exit();
}

// Update the database
try {
    $pdo->beginTransaction(); 

    //  GET NAME/EMAIL BEFORE UPDATING 
    $stmt_get = $pdo->prepare("SELECT company_name, email FROM supplier WHERE supplier_id = :id");
    $stmt_get->execute([':id' => $supplier_id]);
    $supplierInfo = $stmt_get->fetch(PDO::FETCH_ASSOC);
    $supplierName = $supplierInfo['company_name'] ?? 'Unknown Supplier (ID: ' . $supplier_id . ')';
    $supplierEmail = $supplierInfo['email'] ?? 'unknown@supplier.com';

    $sql = "UPDATE supplier SET 
                contact_person = :contact_person,
                phone = :phone,
                street_address = :street_address,
                city = :city,
                postcode = :postcode,
                state = :state
            WHERE supplier_id = :supplier_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':contact_person' => $contact_person,
        ':phone' => $phone,
        ':street_address' => $street_address,
        ':city' => $city,
        ':postcode' => $postcode,
        ':state' => $state,
        ':supplier_id' => $supplier_id
    ]);

    // Log Activity
    ActivityLogger::log($pdo, 'Update', 'Supplier (Portal)', "Supplier '$supplierName' (ID: $supplier_id) updated their own profile.");

    // --- Send IN-APP Notification (inside transaction) ---
    InternalNotify::send(
        $pdo,
        "Supplier Profile Updated",
        "Supplier '$supplierName' (ID: $supplier_id) updated their own profile.",
        "/index.php?page=supplier_details&id=$supplier_id", // Admin link
        null,   
        "Admin" 
    );
    
    $pdo->commit(); 

    // Send SNS Notification
    try {
        supplier_notify_profile_updated(
            (string)$supplier_id, $supplierName, $supplierEmail
        );
    } catch (Throwable $e) {
        error_log("SNS Notification failed for profile_handler: " . $e->getMessage());
    }

    header('Location: /index.php?page=supplier_profile&status=updated');
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); 
    error_log("Supplier Profile Update Error: " . $e->getMessage());
    header('Location: /index.php?page=supplier_profile&status=error');
    exit();
}
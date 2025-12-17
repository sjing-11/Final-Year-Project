<?php
// public/api/settings_handler.php
declare(strict_types=1);
session_start();

// Required for the AWS SDK (in Notify.php)
$root = dirname(__DIR__, 2);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

// Load DB and Auth
require_once dirname(__DIR__, 2) . '/app/db.php';
require_once dirname(__DIR__, 2) . '/app/Auth.php';

// Load Notification Classes
require_once dirname(__DIR__, 2) . '/app/InternalNotify.php';
require_once dirname(__DIR__, 2) . '/app/Notify.php'; 
require_once dirname(__DIR__, 2) . '/app/ActivityLogger.php';

// Ensure only Admin can access
Auth::check_staff(['manage_settings']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // POST requests only
    header('Location: /index.php?page=settings&status=error');
    exit;
}

// Get all data from the form
$settings_data = [
    'company_name'          => (string)($_POST['company_name'] ?? ''),
    'company_address_line1' => (string)($_POST['company_address_line1'] ?? ''),
    'company_address_line2' => (string)($_POST['company_address_line2'] ?? ''),
    'company_email'         => (string)($_POST['company_email'] ?? ''),
    'company_phone'         => (string)($_POST['company_phone'] ?? ''),
    'sst_rate'              => (string)($_POST['sst_rate'] ?? '0.08'),
];

try {
    // Begin transaction
    $pdo->beginTransaction();

    // Prepare the update query
    $sql = "UPDATE company_settings SET setting_value = :setting_value WHERE setting_key = :setting_key";
    $stmt = $pdo->prepare($sql);

    // Loop and save each setting
    foreach ($settings_data as $key => $value) {
        $stmt->execute([
            ':setting_value' => $value,
            ':setting_key'   => $key
        ]);
    }

    // Log this action
    ActivityLogger::log($pdo, 'Update', 'Settings', 'Updated company settings');
    
    $pdo->commit();

    // Redirect to success page BEFORE sending notifications
    header('Location: /index.php?page=settings&status=updated');

    // Close session and send response to user immediately
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    
    // Try to send notifications (after redirect) 
    $notify_title = "Settings Updated";
    $notify_msg = "Company settings were updated by an administrator.";

    // Send in-app notification
    try {
        InternalNotify::send(
            $pdo,
            $notify_title,
            $notify_msg,
            "/index.php?page=settings",
            null, // No specific user ID
            "Admin" // Send to all users with 'Admin' role
        );
    } catch (Throwable $e) {
        error_log("InternalNotify failed in settings_handler: " . $e->getMessage());
    }

    // Send email notification
    try {
        sns_notify($notify_title, $notify_msg);
    } catch (Throwable $e) {
        error_log("sns_notify failed in settings_handler: " . $e->getMessage());
    }

    exit; // Exit after trying notifications

} catch (Throwable $e) {
    // Catch errors from the database transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Settings Update Error: " . $e->getMessage());
    header('Location: /index.php?page=settings&status=error');
    exit;
}
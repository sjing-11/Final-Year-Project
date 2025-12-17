<?php
// app/ActivityLogger.php
declare(strict_types=1);

// Make sure the Auth file is included
// see who is doing the action.
if (!class_exists('Auth')) {
    require_once __DIR__ . '/Auth.php';
}

class ActivityLogger {

    /**
     * Saves a record of an action to the database
     *
     * @param PDO $pdo The database connection.
     * @param string $action_type e.g., 'Add', 'Update', 'Delete'
     * @param string $module e.g., 'Item', 'PurchaseOrder'
     * @param string $description A detailed message of what happened.
     */
    public static function log(PDO $pdo, string $action_type, string $module, string $description): void {
        try {
            // Get info about the current user
            $currentUser = Auth::user();
            $user_id = $currentUser['user_id'] ?? null;
            $session_id = session_id(); // Get the current session ID
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            $sql = "INSERT INTO activity_log (user_id, action_type, module, description, ip_address, session_id)
                    VALUES (:user_id, :action_type, :module, :description, :ip_address, :session_id)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':action_type' => $action_type,
                ':module' => $module,
                ':description' => $description,
                ':ip_address' => $ip_address,
                ':session_id' => $session_id
            ]);

        } catch (Throwable $e) {
            // If logging fails, just write it to the server's error log
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
}
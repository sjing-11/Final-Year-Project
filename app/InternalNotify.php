<?php
// app/InternalNotify.php
declare(strict_types=1);

// Helper class for in-app user notifications
 
class InternalNotify
{
    /**
     * Sends notification to a user or role
     *
     * @param PDO $pdo DB connection
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string|null $link Optional link (e.g., /index.php?page=po_details&id=123)
     * @param int|null $userId Specific user ID to notify
     * @param string|null $role Role (e.g., "Admin") to notify
     * @return bool True on success, false on failure
     */
    public static function send(
        PDO $pdo,
        string $title,
        string $message,
        ?string $link = null,
        ?int $userId = null,
        ?string $role = null
    ): bool {
        
        if ($userId === null && $role === null) {
            error_log("InternalNotify::send() error: Must provide either a userId or a role.");
            return false;
        }

        $userIds = [];

        try {
            if ($userId !== null) {
                // 1. Notify a specific user
                $userIds[] = $userId;
            } else {
                // 2. Notify all users in a role
                $sql_role = "SELECT user_id FROM user WHERE role = :role AND status = 'Active'";
                $stmt_role = $pdo->prepare($sql_role);
                $stmt_role->execute([':role' => $role]);
                $userIds = $stmt_role->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($userIds)) {
                // No one to notify, not an error
                return true; 
            }

            // 3. Insert notification for each user
            $sql_insert = "
                INSERT INTO notification 
                    (user_id, title, message, link)
                VALUES 
                    (:user_id, :title, :message, :link)
            ";
            $stmt_insert = $pdo->prepare($sql_insert);


            foreach ($userIds as $uid) {
                $stmt_insert->execute([
                    ':user_id' => $uid,
                    ':title'   => $title,
                    ':message' => $message,
                    ':link'    => $link
                ]);
            }
            return true;

        } catch (Throwable $e) {
            error_log("InternalNotify::send() database error: " . $e->getMessage());
            // Re-throw so the parent transaction can roll back
            throw $e;
        }
    }
}
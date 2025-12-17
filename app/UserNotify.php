<?php
// app/UserNotify.php
declare(strict_types=1);

require_once __DIR__ . '/Notify.php';

/**
 * Notify when new user is created by Admin
 */
function user_notify_created(string $userId, string $username, string $actorEmail, string $actorRole): bool {
    $subject = "New User Created: $username";
    
    $message = "A new user account has been created by an administrator.\n\n"
             . "User ID:   #$userId\n"
             . "Username:  $username\n"
             . "Added By:  $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'USER_CREATED',
        'userId'      => $userId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info'
    ]);
}

/**
 * Notify when user is deleted by Admin
 */
function user_notify_deleted(string $userId, string $username, string $actorEmail, string $actorRole): bool {
    $subject = "User DELETED: $username";
    
    $message = "A user account has been deleted from the system by an administrator.\n\n"
             . "User ID:   #$userId\n"
             . "Username:  $username\n"
             . "Deleted By: $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'USER_DELETED',
        'userId'      => $userId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'warning'
    ]);
}

/**
 * Notify when user details are updated by Admin
 */
function user_notify_updated(string $userId, string $username, string $actorEmail, string $actorRole): bool {
    $subject = "User Profile Updated: $username";
    
    $message = "A user's profile details were updated by an administrator.\n\n"
             . "User ID:   #$userId\n"
             . "Username:  $username\n"
             . "Updated By: $actorEmail\n";

    return sns_notify($subject, $message, [
        'event'       => 'USER_UPDATED',
        'userId'      => $userId,
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info'
    ]);
}

/**
 * Notify when company settings are updated by Admin
 */
function user_notify_settings_updated(string $actorEmail, string $actorRole): bool {
    $subject = "Company Settings Updated";
    
    $message = "The company settings were updated by an administrator.\n\n"
             . "Updated By: $actorEmail\n"
             . "Actor Role: $actorRole\n";

    return sns_notify($subject, $message, [
        'event'       => 'SETTINGS_UPDATED',
        'actorRole'   => strtoupper($actorRole),
        'severity'    => 'info'
    ]);
}
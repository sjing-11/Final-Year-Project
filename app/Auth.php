<?php
// app/Auth.php
declare(strict_types=1);

/**
 * This class handles all the user login 
 * for both Staff and Suppliers
 */
class Auth
{
    // --- Staff login methods ---

    /**
     * Get the logged-in staff member's info 
     */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Gets the logged-in staff user's role.
     */
    public static function role(): string
    {
        return $_SESSION['user']['role'] ?? 'Guest';
    }

    /**
     * Checks if the logged-in staff user has a specific capability.
     */
    public static function can(string $capability): bool
    {
        $role = self::role();
        
        // List of permissions for each role
        $matrix = [
            'Admin'   => [
                'manage_suppliers',
                'manage_po',
                'view_po_list',
                'view_po_details',
                'create_po',
                'manage_po_status_all', 
                'delete_po',
                'export_po',
                'manage_users',
                'view_logs',
                'view_users_list',    
                'view_users_details', 
                'view_notifications',
                'view_dashboard',
                'manage_settings', 
                'view_reports',
                'view_archives',
                'view_items',
                'edit_items',
                'adjust_stock',
                'delete_items'
            ],
            'Manager' => [
                'manage_suppliers',
                'manage_po',
                'view_po_list',
                'view_po_details',
                'create_po',
                'manage_po_status_all', 
                'export_po',
                'view_logs',          
                'view_users_list',    
                'view_users_details',
                'view_notifications',
                'view_dashboard',
                'view_reports',
                'view_archives',
                'view_items',
                'edit_items',
                'adjust_stock',
                'delete_items'
            ],
            'Staff'   => [
                'view_users_list',
                'view_notifications',
                'view_dashboard',
                'view_items',
                'edit_items',
                'adjust_stock',
                'delete_items',
                'view_po_list',
                'view_po_details',
                'create_po',
                'manage_po_status_basic', 
                'export_po'
            ],
        ];
        
        // Check if the role's permission list includes the capability
        return in_array($capability, $matrix[$role] ?? [], true);
    }

    /**
     * This function protects staff-only pages
     * It checks if a user is logged in AND has the right permissions for the page
     *
     * @param array $capabilities A list of capabilities needed. e.g., ['manage_settings']
     */
    public static function check_staff(array $capabilities = []): void
    {
        // 1. Check if a staff user is logged in
        if (!isset($_SESSION['user']) || isset($_SESSION['supplier_id'])) {
            // Not a staff member, redirect to login
            header('Location: /index.php?page=login');
            exit;
        }

        // 2. If capabilities are required, check them
        if (!empty($capabilities)) {
            foreach ($capabilities as $cap) {
                if (!self::can($cap)) {
                    // User is logged in, but not authorized.
                    
                    // Check if headers (HTML) have already been sent
                    if (headers_sent()) {
                        // If it has, we can't redirect. Just show a simple error message
                        echo '<div style="padding: 20px; font-family: sans-serif; background-color: #fffbe6; border: 1px solid #ffe58f; border-radius: 8px; margin: 20px;">';
                        echo '<h2>Access Denied</h2>';
                        echo '<p>You do not have the required permissions to view this page. Please contact your administrator.</p>';
                        echo '</div>';
                        
                        // Stop the rest of the page from loading
                        exit; 
                    } else {
                        // The page hasn't started loading, so we can safely send them back to the dashboard
                        header('Location: /index.php?page=dashboard&auth_error=1');
                        exit;
                    }
                }
            }
        }
        // 3. User is logged in and has all required capabilities.
    }

    // --- Supplier Portal Methods ---

    /**
     * This function protects supplier-only pages.
     * It just checks if a supplier is logged in.
     */
    public static function check_supplier(): void
    {
        // 1. Check if a supplier is logged in
        if (!isset($_SESSION['supplier_id']) || isset($_SESSION['user'])) {
            // Not a supplier, redirect to login
            header('Location: /index.php?page=login');
            exit;
        }
        // 2. User is a valid supplier.
    }
}
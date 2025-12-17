<?php
declare(strict_types=1);
session_start();

// Show errors during development
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Include app core
require_once __DIR__ . '/../app/db.php'; 
require_once __DIR__ . '/../app/Router.php';
require_once __DIR__ . '/../app/Auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Notify.php';
require_once __DIR__ . '/../app/PoNotify.php';
require_once __DIR__ . '/../app/SupplierNotify.php';
require_once __DIR__ . '/../app/ItemNotify.php';
require_once __DIR__ . '/../app/InternalNotify.php'; 


// Router map: add all pages here
$router = new Router([
  // Dashboard
  'dashboard'        => __DIR__ . '/../views/dashboard.php',
  'login'            => __DIR__ . '/../views/login.php',
  'login_handler'    => __DIR__ . '/../views/login_handler.php',
  'logout'           => __DIR__ . '/../views/logout.php',
   
  // --- Supplier Portal Routes ---
  'supplier_dashboard'      => __DIR__ . '/../views/supplier_portal/dashboard.php',
  'supplier_po_details'   => __DIR__ . '/../views/supplier_portal/details.php',
  'supplier_profile'        => __DIR__ . '/../views/supplier_portal/profile.php',
  'supplier_profile_handler'=> __DIR__ . '/../views/supplier_portal/profile_handler.php',
  'supplier_update_handler' => __DIR__ . '/../views/supplier_portal/supplier_update_po.php',


  // ===== Inventory module (Stock Monitoring) =====
  'items'            => __DIR__ . '/../views/items/list.php',
  'item_add'         => __DIR__ . '/api/add_item.php',
  'item_details'        => __DIR__ . '/../views/items/details.php',
  'item_delete'        => __DIR__ . '/api/delete_item.php',
  'item_update'        => __DIR__ . '/api/update_item.php',
  'item_adjust'        => __DIR__ . '/api/adjust_item.php',


  // ===== Alerts & Reporting =====
  'alerts'           => __DIR__ . '/../views/alerts/index.php',
  'reports'          => __DIR__ . '/../views/reports/index.php',
  'archives'         => __DIR__ . '/../views/archives/index.php',

  // Suppliers module
  'suppliers'        => __DIR__ . '/../views/suppliers/list.php',
  'supplier_details' => __DIR__ . '/../views/suppliers/details.php', 
  'supplier_add'     => __DIR__ . '/api/add_supplier.php',           
  'supplier_edit'    => __DIR__ . '/api/update_supplier.php',      
  'supplier_delete'  => __DIR__ . '/api/delete_supplier.php',

  // Purchase orders module
  'po'               => __DIR__ . '/../views/po/index.php',
  'po_details'       => __DIR__ . '/../views/po/details.php',
  'po_create'        => __DIR__ . '/api/add_po.php', 
  'po_edit'          => __DIR__ . '/api/update_po.php',
  'po_delete'        => __DIR__ . '/api/delete_po.php',

  // Users module
  'users'            => __DIR__ . '/../views/users/list.php',
  'user_details'     => __DIR__ . '/../views/users/details.php', 
  'user_add'         => __DIR__ . '/api/add_user.php',   
  'user_edit'        => __DIR__ . '/api/update_user.php',     
  'user_delete'      => __DIR__ . '/api/delete_user.php',

  // Notifications & Logs
  'notifications'      => __DIR__ . '/../views/notifications/index.php',
  'mark_notifications' => __DIR__ . '/api/mark_notifications.php',
  'activity'           => __DIR__ . '/../views/activity/index.php',
  'search'             => __DIR__ . '/../views/search/index.php', 

  // Settings
  'settings'         => __DIR__ . '/../views/settings/index.php',
  'settings_handler' => __DIR__ . '/api/settings_handler.php',
]);

// Get current page
$page = $_GET['page'] ?? 'login'; // Default to login

// If logged in, redirect from login page to correct dashboard
if ($page === 'login' && (isset($_SESSION['user']) || isset($_SESSION['supplier_id']))) {
    if (isset($_SESSION['supplier_id'])) {
        header('Location: /index.php?page=supplier_dashboard');
        exit;
    } else {
        header('Location: /index.php?page=dashboard');
        exit;
    }
}

// If no page and not logged in, force login
if (empty($_GET['page']) && !isset($_SESSION['user']) && !isset($_SESSION['supplier_id'])) {
     header('Location: /index.php?page=login');
     exit;
}

// If no page BUT logged in, send to correct dashboard
if (empty($_GET['page']) && (isset($_SESSION['user']) || isset($_SESSION['supplier_id']))) {
    if (isset($_SESSION['supplier_id'])) {
        $page = 'supplier_dashboard';
    } else {
        $page = 'dashboard';
    }
}


// Find view or show 404
/** @var Router $router */ 
$view = $router->resolve($page); 
if (!$view) {
  http_response_code(404);
  $view = __DIR__ . '/../views/404.php';
  $page = '404';
}

// Pass page name to global scope for nav and title
$GLOBALS['page'] = $page;
$GLOBALS['page_title'] = title_from_page($page); // Set the page title globally

// Handle API requests (no layout)
$api_pages = [
    'login_handler', 'supplier_profile_handler', 'supplier_update_handler',
    'item_add', 'item_edit', 'item_get', 'item_delete', 'item_adjust', // Added item_adjust
    'supplier_add', 'supplier_edit', 'supplier_delete', 'supplier_get',
    'po_create', 'po_edit', 'po_delete', 
    'user_add', 'user_edit', 'user_delete', 'user_get',
    'settings_handler',
    'get_notifications', 'get_notification_count.php', 'mark_notifications_read.php',
    'mark_notifications' 
];

if (in_array($page, $api_pages)) {
  require $view;
  exit;
}

// Handle standalone pages (no layout)
$standalone_pages = ['login', 'logout'];
if (in_array($page, $standalone_pages)) {
  require $view;
  exit;
}

// Check auth for all other protected pages
if (!isset($_SESSION['user']) && !isset($_SESSION['supplier_id'])) {
    header('Location: /index.php?page=login');
    exit;
}

/*
 * View rendering function
 * Called from layout files to display content
 */
function render_page_content(string $view_path): void {
    // --- FIX: Make $pdo and $u available to all view files ---
    global $pdo, $u; 
    // --- End Fix ---
    require $view_path;
}

// --- Layout Switcher ---
// Checks which session is active and loads the correct layout
if (isset($_SESSION['supplier_id'])) {
    // It's a supplier. Load SUPPLIER layout.
    // Block them from admin pages.
    $supplier_allowed_pages = [
        'supplier_dashboard', 
        'supplier_po_details', 
        'supplier_profile',
        'supplier_update_handler',
        'supplier_profile_handler',
    ];
    if (!in_array($page, $supplier_allowed_pages)) {
        // Supplier trying to access an admin page
        header('Location: /index.php?page=supplier_dashboard'); // Redirect to their dashboard
        exit;
    }
    
    // Set $u as a local variable for the topbar
    $u = [ 
        'user_id' => 0, // Suppliers don't have a user_id, set to 0
        'username'  => $_SESSION['supplier_name'] ?? 'Supplier',
        'email' => '', // You can add email to session on login if you want
        'role'  => 'Supplier'
    ];
    
    include __DIR__ . '/../views/supplier_portal/layout.php';
} else {
    // It's Staff/Admin. Load ADMIN layout.
    // Block them from supplier pages.
     $supplier_pages = [
        'supplier_dashboard', 
        'supplier_po_details', 
        'supplier_profile',
        'supplier_update_handler',
        'supplier_profile_handler',
    ];
    if (in_array($page, $supplier_pages)) {
        // Admin is trying to access a supplier page
        header('Location: /index.php?page=dashboard'); // Redirect to their dashboard
        exit;
    }

    // Set $u as a local variable for the topbar
    $u = $_SESSION['user'] ?? null;
    
    include __DIR__ . '/../views/layout.php';
}
// --- End Layout Switcher ---

/**
 * Generates a human-readable title from the page slug.
 * @param string $page The page slug from the URL.
 * @return string The formatted page title.
 */
function title_from_page(string $page): string {
  return match ($page) {
    'dashboard'       => 'Dashboard',
    'items'           => 'Item List',
    'item_add'        => 'Add Item',
    'item_details'    => 'Item Details',
    'item_delete'     => 'Delete Item',
    'item_update'     => 'Update Item',
    'item_adjust'     => 'Adjust Item',
    'alerts'          => 'Alerts',
    'reports'         => 'Reports',
    'archives'        => 'Archives',
    'suppliers'       => 'Suppliers',
    'supplier_details'=> 'Supplier Details', 
    'supplier_add'    => 'Add Supplier',
    'supplier_edit'   => 'Edit Supplier',
    'supplier_delete' => 'Delete Supplier',
    'po'              => 'Purchase Orders',
    'po_details'      => 'PO Details',
    'po_create'       => 'Create PO',
    'po_edit'         => 'Edit PO',
    'po_delete'       => 'Delete PO',
    'users'           => 'Users',
    'user_details'    => 'User Details', 
    'user_add'        => 'Add User',
    'user_edit'       => 'Edit User',
    'user_delete'     => 'Delete User',
    'notifications'   => 'Notifications',
    'activity'        => 'Activity Log',
    'search'          => 'Search Results', 
    'login'           => 'Login',
    'logout'          => 'Logout',
    'login_handler'   => 'Logging in...',

    // Supplier Portal Titles
    'supplier_dashboard' => 'Supplier Dashboard',
    'supplier_po_details' => 'Purchase Order Details',
    'supplier_profile'   => 'My Profile',
    'supplier_profile_handler' => 'Updating Profile...',
    'supplier_update_handler' => 'Updating PO...', 

    // Settings Title
    'settings'        => 'Company Settings',
    'mark_notifications' => 'Updating Notifications...', 

    '404'             => 'Page Not Found', 

    default           => ucfirst(str_replace('_', ' ', $page)),
  };
}
<?php
// views/login_handler.php
session_start();

$root = dirname(__DIR__); 
require_once $root . '/app/db.php'; 

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

$hashed_password = hash('sha256', $password);

// --- Step 1: Check if it's a regular user ---
$stmt_user = $pdo->prepare("SELECT user_id, username, role, email FROM user WHERE email = :email AND password = :password AND status = 'Active'");
$stmt_user->execute([':email' => $email, ':password' => $hashed_password]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // It's a user, set user session and redirect to the main dashboard
    
    $_SESSION['user'] = [
        'user_id' => $user['user_id'],
        'username'=> $user['username'],
        'role'    => $user['role'],
        'email'   => $user['email']
    ];

    header('Location: /index.php?page=dashboard');
    exit;
}

// --- Step 2: If not a user, check if it's a supplier ---
$stmt_supplier = $pdo->prepare("SELECT supplier_id, company_name, email FROM supplier WHERE email = :email AND password = :password");
$stmt_supplier->execute([':email' => $email, ':password' => $hashed_password]);
$supplier = $stmt_supplier->fetch(PDO::FETCH_ASSOC);

if ($supplier) {
    // It's a supplier, set supplier session and redirect to the supplier portal
    $_SESSION['supplier_id'] = $supplier['supplier_id'];
    $_SESSION['supplier_name'] = $supplier['company_name'];
    header('Location: /index.php?page=supplier_dashboard');
    exit;
}

// --- Step 3: If neither, login fails ---
// Redirect back to login with an error message
header('Location: /index.php?page=login&error=1');
exit;
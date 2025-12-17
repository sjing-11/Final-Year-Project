<?php
// app/db.php
declare(strict_types=1);

// Database details
$DB_HOST = 'database-1.cxjo8lexejtp.us-east-1.rds.amazonaws.com';
$DB_NAME = 'inventory_db';
$DB_USER = 'admin';
$DB_PASS = '12345678';
$DB_PORT = 3306;

// Connection string (DSN)
$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

// Options for database connection (PDO) behave
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'"
];

try {
  // Try to create the database connection object
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
  // If it fails, stop the script and show an error message
  http_response_code(500);
  die("Database connection failed: " . $e->getMessage());
}

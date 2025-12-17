<?php
// views/supplier_portal/layout.php

$supplier_name = $_SESSION['supplier_name'] ?? 'Supplier';
$supplier_id = $_SESSION['supplier_id'] ?? 0;

if ($supplier_id === 0) {
    // If a non-supplier lands here, send them to login
    header('Location: /index.php?page=login');
    exit;
}

// Global $page is set by the router (public/index.php)
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Supplier Portal') ?> · Inventor</title>
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
  <div class="app">
    <?php
    // Include the supplier-specific sidebar
    include __DIR__ . '/sidebar.php';
    ?>

    <!-- Main Content -->
    <main class="main-area">
      <?php
      // Include the supplier-specific topbar
      include __DIR__ . '/topbar.php';
      ?>
      <div class="content">
        <?php
        // This is where the dashboard or details page content will be injected
        if (isset($view) && file_exists($view)) {
            require $view;
        } else {
            echo "<p>Error: View '$view' not found.</p>";
        }
        ?>
      </div>
      <?php
      // Use the existing footer from the partials folder
      include dirname(__DIR__) . '/partials/footer.php';
      ?>
    </main>
  </div>
</body>
</html>
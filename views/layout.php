<?php
if (isset($_GET['download'])) {
    
    require('reports/index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- FIX: Use the globally set page title, which index.php calculates -->
  <title><?= e($GLOBALS['page_title'] ?? 'Loading...') ?></title>
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>

  <div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main-area">
      <!-- Topbar -->
      <?php include __DIR__ . '/partials/topbar.php'; ?>

      <!-- Page Content -->
      <main class="content">
        <!-- FIX: Call the wrapper function defined in index.php to display the view -->
        <?php render_page_content($view); ?>
      </main>
    </div>
  </div>

</body>
</html>
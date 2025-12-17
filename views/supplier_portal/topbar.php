<?php
// views/supplier_portal/topbar.php
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
// Get the name from the session, which was set by the layout
$supplier_name = $_SESSION['supplier_name'] ?? 'Supplier';
?>
<header class="topbar">
  <!-- Leave a spacer to align content to the right -->
  <div class="spacer"></div>

  <!-- Right actions -->
  <div class="top-actions">
    <div class="avatar" title="<?= e($supplier_name) ?>">
      <!-- Show first letter of supplier name -->
      <?= isset($supplier_name) ? strtoupper($supplier_name[0]) : 'S' ?>
    </div>
  </div>
</header>
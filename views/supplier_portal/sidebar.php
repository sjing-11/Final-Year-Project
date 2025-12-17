<?php
// views/supplier_portal/sidebar.php
$pg = $GLOBALS['page'] ?? ($_GET['page'] ?? 'supplier_dashboard');
$active = fn(string $k) => (strpos($pg, $k) === 0) ? ' active' : '';
?>
<aside class="sidebar">
  <!-- Brand -->
  <a href="?page=supplier_dashboard" class="brand-row">
    <img src="images/logo.png" alt="" class="brand-logo">
    <div class="brand-text">SUPPLIER PORTAL</div>
  </a>

  <!-- Nav -->
  <nav class="nav">
    <a class="item<?= $active('supplier_dashboard') ?>" href="?page=supplier_dashboard">
      <span class="ico">📦</span><span>Purchase Orders</span>
    </a>
    <a class="item<?= $active('supplier_profile') ?>" href="?page=supplier_profile">
      <span class="ico">🧑‍🤝‍🧑</span><span>My Profile</span>
    </a>
  </nav>

  <!-- Bottom -->
  <div class="nav-bottom">
    <a class="item" href="?page=logout"><span class="ico">↩️</span><span>Log Out</span></a>
  </div>
</aside>
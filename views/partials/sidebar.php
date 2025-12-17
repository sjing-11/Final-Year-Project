<?php
// views/partials/sidebar.php

// Load Auth to check permissions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';

// Get current page
$pg = $GLOBALS['page'] ?? ($_GET['page'] ?? 'dashboard');
$active = fn(string $k) => (strpos($pg, $k) === 0) ? ' active' : '';
?>
<aside class="sidebar">
  <!-- Brand -->
  <a href="?page=dashboard" class="brand-row">
    <img src="images/logo.png" alt="" class="brand-logo">
    <div class="brand-text">INVENTOR</div>
  </a>

  <!-- Nav -->
  <nav class="nav">
    <a class="item<?= $active('dashboard') ?>" href="?page=dashboard">
      <span class="ico">🏠</span><span>Dashboard</span>
    </a>

  <!-- Inventory module set -->
    <a class="item<?= $active('items') ?>" href="?page=items">
      <span class="ico">🧾</span><span>Inventory</span>
    </a>
    <a class="item<?= $active('reports') ?>" href="?page=reports">
      <span class="ico">📊</span><span>Reports</span>
    </a>
    <a class="item<?= $active('archives') ?>" href="?page=archives">
      <span class="ico">🗂️</span><span>Archives</span>
    </a>
  
  <!-- Ops & System -->
    <a class="item<?= $active('suppliers') ?>" href="?page=suppliers">
      <span class="ico">🧑‍🤝‍🧑</span><span>Suppliers</span>
    </a>
    <a class="item<?= $active('po') ?>" href="?page=po">
      <span class="ico">📦</span><span>Purchase Orders</span>
    </a>
    <a class="item<?= $active('users') ?>" href="?page=users">
      <span class="ico">👥</span><span>Users</span>
    </a>
  </nav>

  <!-- Bottom -->
  <div class="nav-bottom">
    <?php if (Auth::can('manage_settings')): ?>
    <a class="item<?= $active('settings') ?>" href="?page=settings">
      <span class="ico">⚙️</span><span>Settings</span>
    </a>
    <?php endif; ?>
    
    <a class="item" href="?page=logout"><span class="ico">↩️</span><span>Log Out</span></a>
  </div>
</aside>
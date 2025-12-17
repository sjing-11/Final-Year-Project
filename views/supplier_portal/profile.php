<?php
// views/supplier_portal/profile.php
declare(strict_types=1);

// Authentication check
if (!isset($_SESSION['supplier_id'])) {
    header('Location: /index.php?page=login');
    exit();
}

$supplier_id = $_SESSION['supplier_id'];
$statusMsg = null;

// Handle status messages from a redirect
$status = $_GET['status'] ?? null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Profile has been updated.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update profile. Please try again.'];
}

// Load supplier's current details
try {
    $root = dirname(__DIR__, 2);
    require_once $root . '/app/db.php';

    $stmt = $pdo->prepare("SELECT * FROM supplier WHERE supplier_id = :id");
    $stmt->execute([':id' => $supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        throw new Exception("Supplier account not found.");
    }
} catch (Exception $e) {
    $statusMsg = ['error', $e->getMessage()];
    $supplier = []; 
}
?>

<section class="page">

  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
  </div>
  <?php endif; ?>

  <div class="card card-soft">
    <div class="page-head">
      <h1>My Supplier Profile</h1>
    </div>

    <?php if (!empty($supplier)): ?>
    <form class="supplier-form" method="post" action="/index.php?page=supplier_profile_handler">
      <input type="hidden" name="supplier_id" value="<?= e($supplier['supplier_id']) ?>">

      <div class="grid-2">
        <label>Company Name
          <input type="text" value="<?= e($supplier['company_name']) ?>" readonly style="background-color: #f3f4f6;">
          <small class="form-helper-text visible">Company name cannot be changed. Please contact admin.</small>
        </label>
        <label>Email Address
          <input type="email" value="<?= e($supplier['email']) ?>" readonly style="background-color: #f3f4f6;">
          <small class="form-helper-text visible">Email cannot be changed. Please contact admin.</small>
        </label>
      </div>

      <div class="grid-2">
        <label>Contact Person
          <input type="text" name="contact_person" value="<?= e($supplier['contact_person']) ?>">
        </label>
        <label>Contact Phone
          <input type="tel" name="phone" value="<?= e($supplier['phone']) ?>">
        </label>
      </div>

      <label>Street Address
        <input type="text" name="street_address" value="<?= e($supplier['street_address']) ?>">
      </label>

      <div class="grid-3">
        <label>City
          <input type="text" name="city" value="<?= e($supplier['city']) ?>">
        </label>
        <label>Postcode
          <input type="text" name="postcode" value="<?= e($supplier['postcode']) ?>">
        </label>
        <label>State
          <input type="text" name="state" value="<?= e($supplier['state']) ?>">
        </label>
      </div>
      
      <div class="btn-row" style="margin-top: 20px;">
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
    <?php else: ?>
        <p>Could not load supplier profile.</p>
    <?php endif; ?>

  </div>
</section>
<?php
// views/settings/index.php
declare(strict_types=1);

// Only Admin can access this page
Auth::check_staff(['manage_settings']);

// Load DB connection
require_once dirname(__DIR__, 2) . '/app/db.php';

// Handle status messages
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Company settings have been updated.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update settings. Please try again.'];
}

// Fetch all current settings
try {
    $settings_sql = "SELECT setting_key, setting_value FROM company_settings";
    $settings_stmt = $pdo->query($settings_sql);
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $errorMsg = "Error fetching settings: " . $e->getMessage();
    $settings = []; // Ensure $settings is an array
}

// Helper function to get a setting value or a default
$get_setting = function($key, $default = '') use ($settings) {
    return $settings[$key] ?? $default;
};

?>

<section class="page settings-page">

    <?php if ($statusMsg): ?>
    <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
        <span><?= e($statusMsg[1]) ?></span>
        <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <?php endif; ?>

    <?php if (isset($errorMsg)): ?>
    <div class="alert error" style="margin:12px 0;"><?= e($errorMsg) ?></div>
    <?php else: ?>

    <div class="card card-soft">
        <div class="page-head">
            <h2 style="font-size: 1.5rem; font-weight: 700;">Company Information</h2>
            <div class="actions">
                <button type="submit" form="settingsForm" class="btn btn-primary">
                    Save Changes
                </button>
            </div>
        </div>

        <form id="settingsForm" class="supplier-form" method="post" action="api/settings_handler.php" autocomplete="off">

            <label>Company Name
                <input type="text" name="company_name" value="<?= e($get_setting('company_name')) ?>" placeholder="Your Company Name Here">
            </label>
            
            <div class="grid-2">
                <label>Company Email
                    <input type="email" name="company_email" value="<?= e($get_setting('company_email')) ?>" placeholder="your-email@company.com">
                </label>
                <label>Company Phone
                    <input type="text" name="company_phone" value="<?= e($get_setting('company_phone')) ?>" placeholder="+60 3-1234 5678">
                </label>
            </div>

            <label>Address Line 1
                <input type="text" name="company_address_line1" value="<?= e($get_setting('company_address_line1')) ?>" placeholder="123 Main Street">
            </label>
            <label>Address Line 2 (City, Postcode)
                <input type="text" name="company_address_line2" value="<?= e($get_setting('company_address_line2')) ?>" placeholder="Kuala Lumpur, 50000">
            </label>
            
            <h2 style="font-size: 1.5rem; font-weight: 700; margin-top: 2rem; margin-bottom: 0; border-top: 1px solid #e5eaf2; padding-top: 1.5rem;">Financial Settings</h2>
            
            <label>SST Rate (e.g., 0.08 for 8%)
                <input type="number" name="sst_rate" value="<?= e($get_setting('sst_rate', '0.08')) ?>" step="0.01" min="0" max="1">
            </label>

            <div class="btn-row" style="margin-top: 20px; border-top: 1px solid #e5eaf2; padding-top: 16px;">
                <button type="submit" class="btn btn-primary">
                    Save Changes
                </button>
            </div>

        </form>
    </div>
    <?php endif; ?>
</section>

<!-- Toast JS -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const toast = document.getElementById('toastPopup');
  if (toast) {
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      // Use a regex to remove ?status=... from the URL
      const cleanUrl = window.location.href.replace(/[\?&]status=[^&]+/, '');
      window.history.replaceState(null, '', cleanUrl);
    }
  }
});
</script>
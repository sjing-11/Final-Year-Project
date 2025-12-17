<?php
// views/users/details.php
declare(strict_types=1);

/* --- Load PDO from db.php --- */
$root = dirname(__DIR__, 1);
$loadedPdo = false;
foreach ([
  $root . '/../db.php',
  $root . '/../app/db.php',
  $root . '/../config/db.php',
  $root . '/../../db.php',
] as $maybe) {
  if (is_file($maybe)) { require_once $maybe; $loadedPdo = true; break; }
}
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
  die('Database connection error.');
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}


/* --- Load Auth --- */
// session_start() is required for Auth to work
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
// This check ensures only Admin/Manager can see this page
Auth::check_staff(['view_users_details']);

/* --- Get User ID --- */
$user_id = (int)($_GET['id'] ?? 0);
if ($user_id === 0) {
  die('No user ID provided.');
}

/* --- Fetch User Data --- */
$user = null;
$errorMsg = null;
try {
  $stmt = $pdo->prepare("SELECT user_id, username, email, phone, role, status FROM user WHERE user_id = :id");
  $stmt->execute([':id' => $user_id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$user) {
    $errorMsg = "User not found.";
  }
} catch (Throwable $ex) {
  $errorMsg = "Error fetching user: ". $ex->getMessage();
}

// Handle form submission status (from a redirect)
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'User details have been updated successfully.'];
} elseif ($status === 'error') {
  $statusMsg = ['error', 'Could not update user. Please check your inputs.'];
}

// Possible values for dropdowns
$roles = ['Admin', 'Manager', 'Staff']; 
$statuses = ['Active', 'Inactive'];
?>


<section class="page users-details-page">
  
  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
    <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
  </div>
  <?php endif; ?>

  <div class="card card-soft">
    <div class="page-head">
      <h1>User Details</h1>
      <div class="actions">
        <div id="viewModeButtons" style="display: flex; gap: 8px;">
            <?php if (Auth::can('manage_users')): ?>
              <button type="button" id="editUserBtn" class="btn btn-primary">Edit User</button>
            <?php endif; ?>
            
            <a href="/index.php?page=users" class="btn btn-secondary">
              &larr; Back to List
            </a>
        </div>
        
        <div id="editModeButtons" class="edit-controls" style="display: flex; gap: 8px;">
             <button type="button" id="cancelEditBtn" class="btn btn-secondary">Cancel</button>
        </div>
      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <?= e($errorMsg) ?>
      </div>
    <?php elseif ($user): ?>

      <!-- Edit Form -->
      <form id="userEditForm" class="supplier-form" method="post" action="/api/update_user.php" autocomplete="off" data-mode="view">
        
        <!-- Hidden ID -->
        <input type="hidden" name="user_id" value="<?= e($user['user_id']) ?>">
        
        <label>Username
          <input type="text" name="username" value="<?= e($user['username']) ?>" required disabled>
        </label>
        
        <label>Email
          <input type="email" name="email" value="<?= e($user['email']) ?>" required disabled>
        </label>
        
        <label>Contact Number
          <input type="text" name="phone" value="<?= e($user['phone']) ?>" disabled>
        </label>
        
        <div class="grid-2">
          <label>Role
            <select name="role" required disabled>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" <?= $user['role']===$r ? 'selected' : '' ?>>
                  <?= e($r) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <label>Status
            <select name="status" required disabled>
              <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $user['status']===$s ? 'selected' : '' ?>>
                  <?= e($s) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        
        <label>Password
          <div style="position:relative;">
            <input type="password" id="passwordField" name="password" placeholder="Leave blank to keep current" autocomplete="new-password" style="padding-right: 44px; width: 100%;" disabled>
            <button type="button" id="togglePassword" aria-label="Show password"
              style="position:absolute; right: 0; top: 50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; width: 40px; height: 40px; display: grid; place-items: center; color: #667085;">
              <img src="/images/visible.svg" alt="Show password" id="toggleIcon" style="width: 20px; height: 20px;">
            </button>
          </div>
        </label>

        <!-- Form Actions -->
        <div class="btn-row detail-actions" style="margin-top: 20px; justify-content: space-between; gap: 8px;">
          
          <div>
            <?php if (Auth::can('manage_users')): ?>
              <a href="/api/delete_user.php?id=<?= e($user['user_id']) ?>" 
                id="deleteUserBtn"
                class="btn btn-danger" 
                onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                Delete User
              </a>
            <?php endif; ?>
          </div>

          <button type="submit" form="userEditForm" id="saveChangesBtn" class="btn btn-primary edit-controls">
            Save Changes
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>

<!-- ===== Password visibility toggle ===== -->
<script>
const passwordField = document.getElementById('passwordField');
const togglePassword = document.getElementById('togglePassword');
const toggleIcon = document.getElementById('toggleIcon');

// Define the paths to your SVG icons (relative to the /public root)
const iconVisible = '/images/visible.svg';
const iconInvisible = '/images/invisible.svg';

togglePassword?.addEventListener('click', () => {
  const isPassword = passwordField.type === 'password';
  
  if (isPassword) {
    // Change to TEXT (make visible)
    passwordField.type = 'text';
    toggleIcon.src = iconInvisible; // Show 'invisible' (eye-slashed) icon
    toggleIcon.alt = 'Hide password';
  } else {
    // Change to PASSWORD (make invisible)
    passwordField.type = 'password';
    toggleIcon.src = iconVisible; // Show 'visible' (eye) icon
    toggleIcon.alt = 'Show password';
  }
});
</script>

<!-- ===== NEW: VIEW/EDIT MODE SCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  // --- Get all elements ---
  const userForm = document.getElementById('userEditForm');
  if (!userForm) return; // Exit if form not found

  const editUserBtn = document.getElementById('editUserBtn');
  const cancelEditBtn = document.getElementById('cancelEditBtn');
  const viewModeButtons = document.getElementById('viewModeButtons');
  const editModeButtons = document.getElementById('editModeButtons');
  const saveChangesBtn = document.getElementById('saveChangesBtn');
  const deleteUserBtn = document.getElementById('deleteUserBtn');
  const formInputs = userForm.querySelectorAll('input, select');

  // --- Functions ---
  function setViewMode() {
    userForm.dataset.mode = 'view'; // Set data-mode for CSS styling
    
    // Hide edit buttons, show view buttons
    viewModeButtons.style.display = 'flex';
    editModeButtons.style.display = 'none';
    saveChangesBtn.style.display = 'none';
    if (deleteUserBtn) deleteUserBtn.style.display = 'inline-flex';

    // Disable all fields
    formInputs.forEach(input => {
      // Keep the hidden user_id enabled 
      if (input.type === 'hidden' && input.name === 'user_id') {
          input.disabled = false;
      } else {
          input.disabled = true;
      }
    });

    // Reset form to original values loaded by PHP
    userForm.reset();
    
    // Specific fix for password field
    if (passwordField) {
      passwordField.type = 'password';
      passwordField.placeholder = 'Leave blank to keep current';
      if (toggleIcon) {
        toggleIcon.src = iconVisible;
        toggleIcon.alt = 'Show password';
      }
    }
  }

  function setEditMode() {
    userForm.dataset.mode = 'edit'; 

    // Show edit buttons, hide view buttons
    viewModeButtons.style.display = 'none';
    editModeButtons.style.display = 'flex';
    saveChangesBtn.style.display = 'inline-flex';
    if (deleteUserBtn) deleteUserBtn.style.display = 'none';

    // Enable all fields
    formInputs.forEach(input => {
      input.disabled = false;
    });
    
    // Focus the first input
    userForm.querySelector('input[name="username"]')?.focus();
  }

  // --- Attach Listeners ---
  editUserBtn?.addEventListener('click', setEditMode);
  cancelEditBtn?.addEventListener('click', setViewMode);
  
  // --- Initial Page Load ---
  // The page loads in view mode by default (fields are 'disabled' in HTML)
  // We just need to ensure the buttons are set correctly
  setViewMode();

  // --- Toast Popup Autoclose ---
  const toast = document.getElementById('toastPopup');
  if (toast) {
    const autoHideTimer = setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      const cleanUrl = window.location.href.split('?')[0] + window.location.hash;
      window.history.replaceState(null, '', cleanUrl);
    }
  }
});
</script>
<?php 
// views/users/list.php
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
  die('<div style="padding:16px;color:#b00020;background:#fff0f1;border:1px solid #ffd5da;border-radius:8px;">
        Could not load <code>db.php</code> or <code>$pdo</code>.
      </div>');
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* --- Load Auth --- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
Auth::check_staff(['view_users_list']);

/* --- Check for status message from URL --- */
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'User successfully modified.'];
}
if ($status === 'added') {
  $statusMsg = ['ok', 'New user successfully added.'];
}
if ($status === 'deleted') {
  $statusMsg = ['ok', 'User successfully deleted.'];
}

/* --- Fetch user data and filters --- */
$errorMsg = null;
$rows = [];
$filter_roles = [];
try {
  $sql = "
    SELECT 
      user_id, username, phone, email, role, status
    FROM user
    ORDER BY username ASC
  ";
  $stmt = $pdo->query($sql);
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  
  $filter_roles = $pdo->query("SELECT DISTINCT role FROM user WHERE role IS NOT NULL AND role != '' ORDER BY role ASC")->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $ex) {
  $errorMsg = $ex->getMessage();
}
?>

<section class="page users-page">

  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
    <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
  </div>
  <?php endif; ?>


  <div class="card card-soft">
    <div class="page-head">
      <h1>Users</h1>
      <div class="actions">
        
        <?php if (Auth::can('view_logs')): ?>
          <a href="/index.php?page=activity" class="btn btn-primary"> View Activity Log</a>
        <?php endif; ?>

        <?php if (Auth::can('manage_users')): ?>
          <a href="/index.php?page=user_add" class="btn btn-primary">New User</a>
        <?php endif; ?>
        
        <div class="filter-dropdown">
          <button class="btn btn-secondary"><span class="btn-ico">≡</span> <span id="filterButtonText">Filters</span></button>
          <div class="filter-dropdown-content">
            
            <a class="filter-option" data-filter-by="all" data-filter-value="">Show All</a>

            <div class="filter-header">Sort by Name</div>
            <a class="filter-option" data-filter-by="sort" data-filter-value="asc">Sort A-Z</a>
            <a class="filter-option" data-filter-by="sort" data-filter-value="desc">Sort Z-A</a>

            <?php if (!empty($filter_roles)): ?>
              <div class="filter-header">By Role</div>
              <?php foreach ($filter_roles as $role): ?>
                <a class="filter-option" data-filter-by="role" data-filter-value="<?= e(strtolower($role)) ?>">
                  <?= e($role) ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

          </div>
        </div>

      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="alert error" style="margin:12px 0;">
        <strong>Database Error:</strong> <?= e($errorMsg) ?>
      </div>
    <?php endif; ?>

    <div class="table table-clean table-users" id="userTable">
      <div class="t-head">
        <div>Username</div>
        <div>Email</div>
        <div>Contact Number</div>
        <div>Role</div>
        <div>Status</div>
        <div>Actions</div>
      </div>

      <?php if ($rows): ?>
        <?php foreach ($rows as $r): 
          $status_cls = strtolower($r['status'] ?? '') === 'active' ? 'ok' : 'bad';
        ?>
        
        <div class="t-row" 
             data-role="<?= e(strtolower($r['role'] ?? '')) ?>"
             data-username="<?= e(strtolower($r['username'] ?? '')) ?>">
          
          <div><?= e($r['username']) ?></div>
          <div><?= e($r['email']) ?></div>
          <div><?= e($r['phone'] ?? '—') ?></div>
          <div><?= e($r['role']) ?></div>
          <div>
            <span class="status <?= $status_cls ?>"><?= e($r['status']) ?></span>
          </div>
          <!-- Button is now inside the 6th cell -->
          <div>
            <?php if (Auth::can('view_users_details')): ?>
              <a href="/index.php?page=user_details&id=<?= e($r['user_id']) ?>" class="btn btn-secondary slim">
                More Details
              </a>
            <?php else: ?>
              <button class="btn btn-secondary slim" disabled>More Details</button>
            <?php endif; ?>
          </div>
        </div>

        <?php endforeach; ?>
      <?php else: ?>
        <div class="t-row">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0;">No users found.</div>
        </div>
      <?php endif; ?>
      
      <div class="t-row" id="noResultsRow" style="display: none;">
        <div style="grid-column: 1 / -1; color:#667085; padding:12px 0;">
          No users match the current filter.
        </div>
      </div>
    </div>

    <div class="pager-rail">
      <div class="left"><button class="btn btn-secondary" id="prevPageBtn" disabled>Previous</button></div>
      <div class="mid"><span class="page-note" id="pageNote">Page 1 of 1</span></div>
      <div class="right"><button class="btn btn-secondary" id="nextPageBtn" disabled>Next</button></div>
    </div>
  </div>
</section>


<!-- MODAL FOR ADDING A NEW USER -->
<div class="overlay" id="userModal" aria-modal="true" role="dialog" aria-hidden="true">
  <div class="modal" role="document" aria-labelledby="userModalTitle">
    <div class="modal-head">
      <h2 id="userModalTitle">New User</h2>
      <button class="modal-x" id="closeUserModal" aria-label="Close">×</button>
    </div>
    <div class="modal-body">
      <div id="addStatus" class="alert" style="display:none;" aria-live="polite"></div>

      <form id="userForm" class="supplier-form" method="post" action="/api/add_user.php" autocomplete="off" novalide>
        
        <label>Username
          <input type="text" name="username" required maxlength="100">
          <small class="form-helper-text" id="help-username">Username is required.</small>
        </label>
        <label>Email
          <input type="email" name="email" required maxlength="100">
          <small class="form-helper-text" id="help-email">Must be a valid email format.</small>
        </label>
        <label>Password
          <input type="password" name="password" required>
          <small class="form-helper-text" id="help-password">Password must be at least 8 characters long.</small>
        </label>
        <label>Contact Number
          <input type="text" name="phone" maxlength="20" placeholder="e.g. +60 12-345 6789">
          <small class="form-helper-text" id="help-phone">Allowed characters: numbers, spaces, +, -, ( ).</small>
        </label>
        
        <div class="grid-2">
          <label>Role
            <select name="role" required>
                <option value="">-- Select a role --</option>
                <option value="Admin">Admin</option>
                <option value="Manager">Manager</option>
                <option value="Staff">Staff</option>
            </select>
            <small class="form-helper-text" id="help-role">Please select a role.</small>
          </label>
          <label>Status
            <select name="status" required>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
            <small class="form-helper-text" id="help-status">Status is required.</small>
          </label>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <div class="btn-row">
        <button type="button" class="btn btn-secondary" id="cancelUserBtn">Discard</button>
        <button type="submit" form="userForm" class="btn btn-primary" id="addUserBtn">Add User</button>
      </div>
    </div>
  </div>
</div>
<script>
/* =========================
   ADD USER MODAL (OPEN/CLOSE)
   ========================= */
const openUserBtn   = document.querySelector('a[href="/index.php?page=user_add"]'); 
const userOverlay   = document.getElementById('userModal');
const closeUserBtn  = document.getElementById('closeUserModal');
const cancelUserBtn = document.getElementById('cancelUserBtn');
const addStatus     = document.getElementById('addStatus');

if (userOverlay) {
  function openAddModal(){
    userOverlay.classList.add('visible');
    userOverlay.removeAttribute('aria-hidden');
    document.getElementById('userForm')?.reset(); // Reset form on open
  }
  function closeAddModal(){
    userOverlay.classList.remove('visible');
    userOverlay.setAttribute('aria-hidden','true');
    // Clear errors when closing
    if (addStatus){ addStatus.style.display='none'; addStatus.textContent=''; }
    document.querySelectorAll('.form-input-error').forEach(el => el.classList.remove('form-input-error'));
    document.querySelectorAll('.form-helper-text').forEach(el => {
      el.classList.remove('visible', 'error');
    });
  }

  openUserBtn?.addEventListener('click', (e)=>{ 
    e.preventDefault(); 
    openAddModal(); 
  });
  closeUserBtn?.addEventListener('click', closeAddModal);
  cancelUserBtn?.addEventListener('click', closeAddModal);
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeAddModal(); });
  userOverlay.addEventListener('click', (e)=>{ if(e.target===userOverlay) closeAddModal(); });
  userOverlay.querySelector('.modal')?.addEventListener('click', (e)=> e.stopPropagation());
}


/* ==========================================================
   ADD USER: VALIDATION & SUBMISSION
   ========================================================== */
const addUserForm = document.getElementById('userForm');
const addUserBtn  = document.getElementById('addUserBtn');

function showAddStatus(msg, type='error'){
  if (!addStatus) return;
  addStatus.innerHTML = `<div>${msg}</div>`;
  addStatus.className = 'alert ' + (type==='error' ? 'ok' : 'error');
  addStatus.style.display = 'block';
}

function toggleErrorField(inputEl, helperId, show = false, message = '') {
  const helperEl = document.getElementById(helperId);
  if (show) {
    inputEl?.classList.add('form-input-error');
    if (helperEl) {
      helperEl.textContent = message;
      helperEl.classList.add('visible', 'error');
    }
  } else {
    inputEl?.classList.remove('form-input-error');
    if (helperEl) {
      helperEl.classList.remove('visible', 'error');
    }
  }
}

addUserForm?.addEventListener('submit', async (e)=>{
  if (!window.fetch) return;
  e.preventDefault();

  let isValid = true;
  document.querySelectorAll('.form-input-error').forEach(el => el.classList.remove('form-input-error'));
  document.querySelectorAll('.form-helper-text.visible').forEach(el => el.classList.remove('visible', 'error'));
  if(addStatus) addStatus.style.display = 'none';

  const fieldsToValidate = [
    {
      input: addUserForm.username, helperId: 'help-username',
      rules: [ { test: (v) => v.trim() !== '', message: 'Username is required.' } ]
    },
    {
      input: addUserForm.email, helperId: 'help-email',
      rules: [
        { test: (v) => v.trim() !== '', message: 'Email is required.' },
        { test: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), message: 'Must be a valid email format.' }
      ]
    },
    {
      input: addUserForm.password, helperId: 'help-password',
      rules: [
        { test: (v) => v !== '', message: 'Password is required.' },
        { test: (v) => v.length >= 8, message: 'Password must be at least 8 characters long.' }
      ]
    },
     {
      input: addUserForm.role, helperId: 'help-role',
      rules: [ { test: (v) => v.trim() !== '', message: 'Please select a role.' } ]
    },
    {
      input: addUserForm.phone, helperId: 'help-phone',
      rules: [ { test: (v) => v === '' || /^[0-9\s+\-()]*$/.test(v), message: 'Invalid characters in phone number.' } ]
    }
  ];

  for (const field of fieldsToValidate) {
    let fieldIsValid = true;
    for (const rule of field.rules) {
      if (!rule.test(field.input.value)) {
        isValid = false;
        fieldIsValid = false;
        toggleErrorField(field.input, field.helperId, true, rule.message);
        break; 
      }
    }
    if (fieldIsValid) toggleErrorField(field.input, field.helperId, false);
  }

  if (!isValid) {
    showAddStatus("Please correct the highlighted fields.", 'error');
    return;
  }
  
  addUserBtn.disabled = true;
  const oldBtnText = addUserBtn.textContent; 
  addUserBtn.textContent = 'Saving…';
  
  try {
    const formData = new FormData(addUserForm);
    const res = await fetch(addUserForm.action, { method:'POST', body: formData });
    const json = await res.json();
    
    if (res.ok && json.status === 'success') {
      window.location.href = '/index.php?page=users&status=added';
      return;
    }
    showAddStatus(json.message || 'Failed to add user.', 'error');
  } catch (err) {
    console.error(err);
    showAddStatus('An unexpected error occurred.', 'error');
  } finally {
    addUserBtn.disabled = false;
    addUserBtn.textContent = oldBtnText;
  }
});


/* =================================
   PAGINATION, FILTER, SORT & TOAST
   ================================= */
document.addEventListener('DOMContentLoaded', () => {

  const toast = document.getElementById('toastPopup');
  if (toast) {
    setTimeout(() => toast.remove(), 4000); 
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('status');
        window.history.replaceState(null, '', url.toString());
    }
  }

  const ITEMS_PER_PAGE = 5; 
  let currentPage = 1;
  let currentFilterBy = 'all';
  let currentFilterValue = '';
  let currentSort = 'asc'; // 'asc' or 'desc'

  const allTableRows = Array.from(document.querySelectorAll('#userTable .t-row[data-role]'));
  const noResultsRow = document.getElementById('noResultsRow');
  const pageNote = document.getElementById('pageNote');
  const prevPageBtn = document.getElementById('prevPageBtn');
  const nextPageBtn = document.getElementById('nextPageBtn');
  const filterButtonText = document.getElementById('filterButtonText');
  const filterLinks = document.querySelectorAll('.filter-option');
  const filterDropdown = document.querySelector('.filter-dropdown');

  function updateTableDisplay() {
    let visibleRows = [...allTableRows];

    if (currentFilterBy === 'role') {
      visibleRows = visibleRows.filter(row => row.dataset.role === currentFilterValue);
    }
    
    visibleRows.sort((a, b) => {
        const nameA = a.dataset.username;
        const nameB = b.dataset.username;
        return currentSort === 'desc' ? nameB.localeCompare(nameA) : nameA.localeCompare(nameB);
    });

    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1; 

    currentPage = Math.max(1, Math.min(currentPage, totalPages));

    allTableRows.forEach(row => row.style.display = 'none');
    noResultsRow.style.display = 'none';

    if (totalItems === 0) {
      noResultsRow.style.display = 'grid'; 
    } else {
      const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
      const rowsToShow = visibleRows.slice(startIndex, startIndex + ITEMS_PER_PAGE);
      rowsToShow.forEach(row => row.style.display = 'grid');
    }

    pageNote.textContent = `Page ${currentPage} of ${totalPages}`;
    prevPageBtn.disabled = (currentPage === 1);
    nextPageBtn.disabled = (currentPage === totalPages);
  }

  filterLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const filterBy = link.dataset.filterBy;
      const filterValue = link.dataset.filterValue.toLowerCase();
      
      if (filterBy === 'all') {
        currentFilterBy = 'all';
        filterButtonText.textContent = "Filters";
      } else if (filterBy === 'role') {
        currentFilterBy = 'role';
        currentFilterValue = filterValue;
        filterButtonText.textContent = link.textContent.trim();
      } else if (filterBy === 'sort') {
        currentSort = filterValue;
      }
      currentPage = 1; 
      updateTableDisplay();
    });
  });

  prevPageBtn?.addEventListener('click', () => {
    if (currentPage > 1) {
      currentPage--;
      updateTableDisplay();
    }
  });

  nextPageBtn?.addEventListener('click', () => {
    currentPage++;
    updateTableDisplay();
  });

  updateTableDisplay();
});

</script>
<?php 
// views/suppliers/list.php
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

/* --- Load Auth --- */
// session_start() is required for Auth to work
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__, 2) . '/app/Auth.php';
Auth::check_staff();

if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* --- Check for status message from URL --- */
$status = $_GET['status'] ?? null;
$statusMsg = null;
if ($status === 'updated') {
  $statusMsg = ['ok', 'Supplier successfully modified.'];
}
if ($status === 'added') {
  $statusMsg = ['ok', 'New supplier successfully added.'];
}
if ($status === 'deleted') {
  $statusMsg = ['ok', 'Supplier successfully deleted.'];
}


/* --- Fetch supplier data --- */
$errorMsg = null;
$rows = [];
try {
  $sql = "
    SELECT 
      s.*, 
      COALESCE(po.po_count, 0) AS po_count
    FROM supplier s
    LEFT JOIN (
      SELECT supplier_id, COUNT(*) AS po_count
      FROM purchase_order
      GROUP BY supplier_id
    ) po ON po.supplier_id = s.supplier_id
    ORDER BY s.company_name ASC
  ";
  $stmt = $pdo->query($sql);
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $ex) {
  $errorMsg = $ex->getMessage();
}

/* --- Get unique filter values from the data --- */
$filter_cities = array_unique(array_column($rows, 'city'));
$filter_states = array_unique(array_column($rows, 'state'));
$filter_countries = array_unique(array_column($rows, 'country'));
sort($filter_cities);
sort($filter_states);
sort($filter_countries);
?>

<section class="page suppliers-page">
  <?php if ($statusMsg): ?>
  <div id="toastPopup" class="toast-popup <?= e($statusMsg[0]) ?>">
    <span><?= e($statusMsg[1]) ?></span>
    <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
  </div>
  <?php endif; ?>


  <div class="card card-soft">
    <div class="page-head">
      <h1>Suppliers</h1>
      <div class="actions">
        <?php if (Auth::can('manage_suppliers')): ?>
          <a href="#" class="btn btn-primary" id="openSupplierModalBtn">New Supplier</a>
        <?php endif; ?>

        <div class="filter-dropdown">
          <button class="btn btn-secondary"><span class="btn-ico">≡</span> <span id="filterButtonText">Filters</span></button>
          <div class="filter-dropdown-content">
            
            <a class="filter-option" data-filter-by="all" data-filter-value="">Show All</a>

            <?php if (!empty(array_filter($filter_countries))): ?>
              <div class="filter-header">By Country</div>
              <?php foreach ($filter_countries as $country): if(empty($country)) continue; ?>
                <a class="filter-option" data-filter-by="country" data-filter-value="<?= e($country) ?>">
                  <?= e($country) ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty(array_filter($filter_states))): ?>
              <div class="filter-header">By State</div>
              <?php foreach ($filter_states as $state): if(empty($state)) continue; ?>
                <a class="filter-option" data-filter-by="state" data-filter-value="<?= e($state) ?>">
                  <?= e($state) ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty(array_filter($filter_cities))): ?>
              <div class="filter-header">By City</div>
              <?php foreach ($filter_cities as $city): if(empty($city)) continue; ?>
                <a class="filter-option" data-filter-by="city" data-filter-value="<?= e($city) ?>">
                  <?= e($city) ?>
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

    <div class="table table-clean" id="supplierTable">
      <div class="t-head">
        <div>Supplier Name</div>
        <div>Contact Person</div>
        <div>Contact Number</div>
        <div>Email</div>
        <div>Total POs</div>
        <div>Actions</div>
      </div>

      <?php if ($rows): ?>
        <?php foreach ($rows as $r): 
          $name    = $r['company_name'] ?? '—';
          $person  = $r['contact_person'] ?? '—';
          $phone   = $r['phone'] ?? '—';
          $email   = $r['email'] ?? '—';
        ?>
        <div class="t-row" 
             data-city="<?= e(strtolower($r['city'] ?? '')) ?>" 
             data-state="<?= e(strtolower($r['state'] ?? '')) ?>" 
             data-country="<?= e(strtolower($r['country'] ?? '')) ?>">
          
          <div><?= e($name) ?></div>
          <div><?= e($person) ?></div>
          <div><?= e($phone) ?></div>
          <div><?= e($email) ?></div>

          <div><?= e($r['po_count']) ?></div>

          <div>
            <a href="/index.php?page=supplier_details&id=<?= e($r['supplier_id']) ?>" class="btn btn-secondary slim">
              More Details
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="t-row">
          <div style="grid-column: 1 / -1; color:#667085; padding:12px 0;">No suppliers found.</div>
        </div>
      <?php endif; ?>
      
      <div class="t-row" id="noResultsRow" style="display: none;">
        <div style="grid-column: 1 / -1; color:#667085; padding:12px 0;">
          No suppliers match the current filter.
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


<div class="overlay" id="supplierModal" aria-modal="true" role="dialog" aria-hidden="true">
  <div class="modal" role="document" aria-labelledby="supplierModalTitle">
    <div class="modal-head">
      <h2 id="supplierModalTitle">New Supplier</h2>
      <button class="modal-x" id="closeSupplierModal" aria-label="Close">×</button>
    </div>
    <div class="modal-body">
      <div id="addStatus" class="alert" style="display:none;" aria-live="polite"></div>


      <form id="supplierForm" class="supplier-form" method="post" action="/api/add_supplier.php" autocomplete="off" novalidate>

        <label>Company Name
          <input type="text" name="company_name" required maxlength="100">
          <small class="form-helper-text" id="help-company_name">Company name is required.</small>
        </label>
        <label>Contact Person
          <input type="text" name="contact_person" maxlength="100">
          <small class="form-helper-text" id="help-contact_person">Contact Person is required.</small>
        </label>
        <label>Email
          <input type="email" name="email" required maxlength="100">
          <small class="form-helper-text" id="help-email">Must be a valid email format (e.g., user@example.com).</small>
        </label>
        <label>Contact Number
          <input type="text" name="phone" maxlength="20" placeholder="e.g. +60 12-345 6789">
          <small class="form-helper-text" id="help-phone">Allowed characters: numbers, spaces, +, -, ( ).</small>
        </label>
        <label>Fax
          <input type="text" name="fax" maxlength="20">
          <!-- NO helper text, as this field is optional -->
        </label>

        <label>Password
          <input type="password" name="password" required autocomplete="new-password">
          <small class="form-helper-text" id="help-password">Password is required (minimum 8 characters).</small>
        </label>

        <label>Street Address
          <input type="text" name="street_address" maxlength="150">
          <!-- NEW: Added helper text -->
          <small class="form-helper-text" id="help-street_address">Street Address is required.</small>
        </label>
        <div class="grid-2">
          <label>Postcode
            <input type="text" name="postcode" maxlength="10">
            <small class="form-helper-text" id="help-postcode">Allowed characters: letters, numbers, spaces, -.</small>
          </label>
          <label>City
            <input type="text" name="city" maxlength="50">
            <!-- NEW: Added helper text -->
            <small class="form-helper-text" id="help-city">City is required.</small>
          </label>
        </div>
        <div class="grid-2">
          <label>State
            <input type="text" name="state" maxlength="50">
            <!-- NEW: Added helper text -->
            <small class="form-helper-text" id="help-state">State is required.</small>
          </label>
          <label>Country
            <input type="text" name="country" maxlength="50">
            <!-- NEW: Added helper text -->
            <small class="form-helper-text" id="help-country">Country is required.</small>
          </label>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <div class="btn-row">
        <button type="button" class="btn btn-secondary" id="cancelSupplierBtn">Discard</button>
        <button type="submit" form="supplierForm" class="btn btn-primary" id="addSupplierBtn">Add Supplier</button>
      </div>
    </div>
  </div>
</div>
<script>
/* =========================
   ADD SUPPLIER (OPEN/CLOSE)
   ========================= */
const openSupplierBtn   = document.getElementById('openSupplierModalBtn'); 
const supplierOverlay   = document.getElementById('supplierModal');
const closeSupplierBtn  = document.getElementById('closeSupplierModal');
const cancelSupplierBtn = document.getElementById('cancelSupplierBtn');
const addStatus         = document.getElementById('addStatus');
const addForm = document.getElementById('supplierForm'); // Get the form

// Check if the overlay element exists before adding listeners
if (supplierOverlay) {
  function openAddModal(){
    supplierOverlay.classList.add('visible');
    supplierOverlay.removeAttribute('aria-hidden');
  }
  
  // This function is for "X" button or clicking outside
  function closeAddModal(){
    supplierOverlay.classList.remove('visible');
    supplierOverlay.setAttribute('aria-hidden','true');
    // Clear errors when closing
    if (addStatus){ addStatus.style.display='none'; addStatus.textContent=''; }
    document.querySelectorAll('.form-input-error').forEach(el => el.classList.remove('form-input-error'));
    document.querySelectorAll('.form-helper-text').forEach(el => {
      el.classList.remove('visible', 'error');
    });
    // NOTE: We DO NOT reset the form here, preserving the data
  }
  
  // This function is ONLY for the "Discard" button
  function discardAndCloseModal() {
    addForm?.reset(); // Clear all form fields
    closeAddModal(); // Run the normal close logic
  }

  openSupplierBtn?.addEventListener('click', (e)=>{ 
    e.preventDefault(); 
    openAddModal(); 
  });
  
  closeSupplierBtn?.addEventListener('click', closeAddModal);
  cancelSupplierBtn?.addEventListener('click', discardAndCloseModal); // "Discard" now resets the form
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeAddModal(); });
  supplierOverlay.addEventListener('click', (e)=>{ if(e.target===supplierOverlay) closeAddModal(); });
  supplierOverlay.querySelector('.modal')?.addEventListener('click', (e)=> e.stopPropagation());
}



const addBtn  = document.getElementById('addSupplierBtn');

/**
 * Shows a status message at the top of the modal.
 * @param {string|string[]} msg The message(s) to show.
 * @param {string} [type='error'] 'error' or 'ok'
 */
function showAddStatus(msg, type='error'){
  if (!addStatus) return;
  const messages = Array.isArray(msg) ? msg : [msg];
  addStatus.innerHTML = messages.map(m => `<div>${m}</div>`).join('');
  addStatus.className = 'alert ' + (type==='error' ? 'error' : 'ok');
  addStatus.style.display = 'block';
}

/**
 * Toggles the error state for a form field.
 * @param {HTMLInputElement} inputEl The input element.
 * @param {string} helperId The ID of the <small> tag.
 * @param {boolean} [show=false] True to show error, false to hide.
 * @param {string} [message] The error message to show.
 */
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

  addForm?.addEventListener('submit', async (e)=>{
  if (!window.fetch) return;
  e.preventDefault();

  // 1. Clear all previous errors
  let isValid = true;
  const errors = [];
  document.querySelectorAll('.form-input-error').forEach(el => el.classList.remove('form-input-error'));
  document.querySelectorAll('.form-helper-text.visible').forEach(el => el.classList.remove('visible', 'error'));
  if(addStatus) addStatus.style.display = 'none';

  // 2. Define validation rules
  const fieldsToValidate = [
    {
      input: addForm.company_name,
      helperId: 'help-company_name',
      rules: [ { test: (val) => val.trim() !== '', message: 'Company name is required.' } ]
    },
    // Added Contact Person
    {
      input: addForm.contact_person,
      helperId: 'help-contact_person',
      rules: [ { test: (val) => val.trim() !== '', message: 'Contact Person is required.' } ]
    },
    {
      input: addForm.email,
      helperId: 'help-email',
      rules: [
        { test: (val) => val.trim() !== '', message: 'Email is required.' },
        { test: (val) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val), message: 'Must be a valid email format (e.g., user@example.com).' }
      ]
    },
    {
      input: addForm.phone,
      helperId: 'help-phone',
      rules: [ 
        // Added required rule
        { test: (val) => val.trim() !== '', message: 'Contact Number is required.' },
        { test: (val) => /^[0-9\s+\-()]*$/.test(val), message: 'Allowed characters: numbers, spaces, +, -, ( ).' } 
      ]
    },

    {
      input: addForm.password,
      helperId: 'help-password',
      rules: [
        { test: (val) => val.trim() !== '', message: 'Password is required.' },
        { test: (val) => val.length >= 8, message: 'Password must be at least 8 characters.' }
      ]
    },
    // Added Street Address
    {
      input: addForm.street_address,
      helperId: 'help-street_address',
      rules: [ { test: (val) => val.trim() !== '', message: 'Street Address is required.' } ]
    },
    {
      input: addForm.postcode,
      helperId: 'help-postcode',
      rules: [ 
        // Added required rule
        { test: (val) => val.trim() !== '', message: 'Postcode is required.' },
        { test: (val) => /^[a-zA-Z0-9\s-]*$/.test(val), message: 'Allowed characters: letters, numbers, spaces, -.' } 
      ]
    },
    // Added City
    {
      input: addForm.city,
      helperId: 'help-city',
      rules: [ { test: (val) => val.trim() !== '', message: 'City is required.' } ]
    },
    // Added State
    {
      input: addForm.state,
      helperId: 'help-state',
      rules: [ { test: (val) => val.trim() !== '', message: 'State is required.' } ]
    },
    // Added Country
    {
      input: addForm.country,
      helperId: 'help-country',
      rules: [ { test: (val) => val.trim() !== '', message: 'Country is required.' } ]
    }
  ];

  // 3. Run validation loop
  for (const field of fieldsToValidate) {
    const value = field.input.value;
    let fieldIsValid = true;
    for (const rule of field.rules) {
      if (!rule.test(value)) {
        isValid = false;
        fieldIsValid = false;
        errors.push(rule.message);
        toggleErrorField(field.input, field.helperId, true, rule.message);
        break; 
      }
    }
    if (fieldIsValid) {
      toggleErrorField(field.input, field.helperId, false);
    }
  }

  // 4. Show summary error or submit
  if (!isValid) {
    showAddStatus("Please correct the highlighted fields.", 'error');
    return;
  }
  
  // --- If valid, proceed to submit ---
  addBtn.disabled = true;
  const old = addBtn.textContent; addBtn.textContent = 'Saving…';
  
  try {
    const formData = new FormData(addForm);
    const res = await fetch(addForm.action, { method:'POST', body: formData });
    let json; try { json = await res.json(); } catch(_){}
    
    if (res.ok && json && (json.status === 'success' || json.ok === true)) {
      // SUCCESS
      window.location.href = '/index.php?page=suppliers&status=added';
      return;
    }
    // SERVER-SIDE ERROR
    const msg = (json && (json.message || json.error)) ? (json.message || json.error) : 'Failed to add supplier.';
    showAddStatus(msg, 'error');
  } catch (err) {
    console.error(err);
    showAddStatus('Something went wrong while adding the supplier.', 'error');
  } finally {
    addBtn.disabled = false;
    addBtn.textContent = old;
  }
});


/* =================================
   PAGINATION, FILTER, & TOAST SCRIPT
   ================================= */
document.addEventListener('DOMContentLoaded', () => {

  // --- Toast Popup Autoclose ---
  const toast = document.getElementById('toastPopup');
  if (toast) {
    setTimeout(() => {
      if (toast) toast.remove();
    }, 4000); 

    if (window.history.replaceState) {
      const cleanUrl = window.location.href.split('?')[0] + window.location.hash;
      window.history.replaceState(null, '', cleanUrl);
    }
  }

  // --- Configuration ---
  const ITEMS_PER_PAGE = 5; 

  let currentPage = 1;
  let currentFilterBy = 'all';
  let currentFilterValue = '';

  // Cache DOM elements
  const allTableRows = Array.from(document.querySelectorAll('#supplierTable .t-row[data-city]'));
  const noResultsRow = document.getElementById('noResultsRow');
  const pageNote = document.getElementById('pageNote');
  const prevPageBtn = document.getElementById('prevPageBtn');
  const nextPageBtn = document.getElementById('nextPageBtn');
  const filterButtonText = document.getElementById('filterButtonText');
  const filterLinks = document.querySelectorAll('.filter-option');
  const filterDropdown = document.querySelector('.filter-dropdown');

  function updateTableDisplay() {
    const visibleRows = allTableRows.filter(row => {
      if (currentFilterBy === 'all') return true;
      return row.dataset[currentFilterBy] === currentFilterValue;
    });

    const totalItems = visibleRows.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1; 

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    allTableRows.forEach(row => row.style.display = 'none');
    if (noResultsRow) noResultsRow.style.display = 'none';

    if (totalItems === 0) {
      if (noResultsRow) noResultsRow.style.display = 'grid'; 
    } else {
      const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
      const endIndex = startIndex + ITEMS_PER_PAGE;
      const rowsToShow = visibleRows.slice(startIndex, endIndex);
      rowsToShow.forEach(row => row.style.display = 'grid');
    }

    if (pageNote) pageNote.textContent = `Page ${currentPage} of ${totalPages}`;
    if (prevPageBtn) prevPageBtn.disabled = (currentPage === 1);
    if (nextPageBtn) nextPageBtn.disabled = (currentPage === totalPages);
  }

  // --- Attach Event Listeners ---
  filterLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      currentFilterBy = link.dataset.filterBy;
      currentFilterValue = link.dataset.filterValue.toLowerCase();
      currentPage = 1; 
      const filterText = link.textContent.trim();
      if (filterButtonText) {
        filterButtonText.textContent = (currentFilterBy === 'all') ? 'Filters' : filterText;
      }
      updateTableDisplay();
      if (filterDropdown) filterDropdown.blur(); 
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

  // 3. Initial table load
  updateTableDisplay();
});

</script>
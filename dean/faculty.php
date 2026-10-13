<?php
$pageTitle  = 'Faculty Management';
$activePage = 'dean-faculty';
require_once __DIR__ . '/../includes/header.php';
requireRole('dean', 'admin');

$db = getDB();

// Check for success message from redirect
$successMsg = isset($_GET['msg']) ? $_GET['msg'] : null;

// Count total faculty (active only)
$countStmt = $db->prepare("
    SELECT COUNT(*) FROM users u
    WHERE u.assigned_dean_id = ? AND u.role = 'faculty' AND u.is_active = 1
");
$countStmt->execute([$currentUserId]);
$totalRecords = (int)$countStmt->fetchColumn();

// Count total IPCRs across all faculty
$totalIpcrsStmt = $db->prepare("
    SELECT COUNT(*) FROM ipcr_forms 
    WHERE user_id IN (SELECT id FROM users WHERE assigned_dean_id = ? AND role = 'faculty' AND is_active = 1)
");
$totalIpcrsStmt->execute([$currentUserId]);
$totalIpcrs = (int)$totalIpcrsStmt->fetchColumn();

// Get ALL faculty under this dean (no pagination - accordion handles grouping)
$faculty = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM ipcr_forms WHERE user_id=u.id) as total_ipcrs,
           (SELECT COUNT(*) FROM ipcr_forms WHERE user_id=u.id AND status='finalized') as completed_ipcrs
    FROM users u
    WHERE u.assigned_dean_id = ? AND u.role = 'faculty' AND u.is_active = 1
    ORDER BY u.last_name, u.first_name
");
$faculty->execute([$currentUserId]);
$faculty = $faculty->fetchAll();

// Get departments from departments table (fallback to users if empty)
$departments = $db->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// If no departments in table, get from users as fallback
if (empty($departments)) {
    $departments = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
}
?>

<?php if ($successMsg): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
  <i class="bi bi-check-circle me-2"></i>
  <?= h($successMsg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-people me-2"></i>Faculty Management</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">View and manage faculty members under your supervision</p>
    <div class="breadcrumb-bar"><a href="<?= BASE_URL ?>/dashboard.php">Home</a> › Faculty</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
    <i class="bi bi-plus-lg me-2"></i>Add Faculty
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-primary mx-auto"><i class="bi bi-people-fill"></i></div>
      <div class="metric-value"><?= $totalRecords ?></div>
      <div class="metric-label">Total Faculty</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-check-circle"></i></div>
      <div class="metric-value"><?= $totalRecords ?></div>
      <div class="metric-label">Active</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-info mx-auto"><i class="bi bi-file-text"></i></div>
      <div class="metric-value"><?= $totalIpcrs ?></div>
      <div class="metric-label">Total IPCRs</div>
    </div>
  </div>
</div>

<?php
// Group faculty by department
$facultyByDept = [];
foreach ($faculty as $f) {
    $dept = $f['department'] ?: 'Unassigned';
    if (!isset($facultyByDept[$dept])) {
        $facultyByDept[$dept] = [];
    }
    $facultyByDept[$dept][] = $f;
}
ksort($facultyByDept);
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5><i class="bi bi-list-ul me-2"></i>Faculty List by Department</h5>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="expandAll()">
        <i class="bi bi-chevron-down"></i> Expand All
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="collapseAll()">
        <i class="bi bi-chevron-up"></i> Collapse All
      </button>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$faculty): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>No faculty assigned yet.
    </div>
    <?php else: ?>
    
    <!-- Department Accordions -->
    <div class="accordion" id="facultyAccordion">
      <?php foreach ($facultyByDept as $dept => $deptFaculty): 
        $deptId = 'dept-' . md5($dept);
        $deptCount = count($deptFaculty);
        $deptIpcrTotal = array_sum(array_column($deptFaculty, 'total_ipcrs'));
      ?>
      <div class="accordion-item mb-2" style="border:1px solid var(--gray-300);border-radius:8px;overflow:hidden">
        <h2 class="accordion-header">
          <button class="accordion-button collapsed" type="button" 
                  data-bs-toggle="collapse" data-bs-target="#<?= $deptId ?>" 
                  style="background:var(--gray-50);padding:14px 20px;font-weight:600;color:var(--gray-800)">
            <div class="d-flex align-items-center justify-content-between w-100 pe-3">
              <div>
                <i class="bi bi-building me-2" style="color:var(--primary)"></i>
                <?= h($dept) ?>
              </div>
              <div class="d-flex gap-3 align-items-center" style="font-size:.85rem;font-weight:500">
                <span class="badge bg-primary" style="padding:4px 10px">
                  <?= $deptCount ?> faculty
                </span>
                <span class="badge bg-info" style="padding:4px 10px">
                  <?= $deptIpcrTotal ?> IPCRs
                </span>
              </div>
            </div>
          </button>
        </h2>
        <div id="<?= $deptId ?>" class="accordion-collapse collapse" data-bs-parent="#facultyAccordion">
          <div class="accordion-body" style="padding:0">
            <div class="table-responsive">
              <table class="table table-hover mb-0" style="font-size:.88rem">
                <thead style="background:var(--gray-100);border-bottom:2px solid var(--gray-300)">
                  <tr>
                    <th style="padding:10px 16px">Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Position</th>
                    <th>IPCRs</th>
                    <th>Status</th>
                    <th style="width:200px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($deptFaculty as $f): ?>
                  <tr>
                    <td style="padding:12px 16px"><strong><?= h($f['employee_id']) ?></strong></td>
                    <td>
                      <div style="font-weight:600"><?= h($f['first_name'] . ' ' . $f['last_name']) ?></div>
                    </td>
                    <td style="color:var(--gray-600);font-size:.82rem"><?= h($f['email']) ?></td>
                    <td style="color:var(--gray-600);font-size:.82rem"><?= h($f['position']) ?></td>
                    <td>
                      <div class="d-flex gap-1">
                        <?php if ($f['total_ipcrs'] > 0): ?>
                          <span class="badge bg-primary" title="Total IPCRs"><?= $f['total_ipcrs'] ?> total</span>
                          <?php if ($f['completed_ipcrs'] > 0): ?>
                            <span class="badge bg-success" title="Finalized IPCRs"><?= $f['completed_ipcrs'] ?> finalized</span>
                          <?php else: ?>
                            <span class="badge bg-warning text-dark" title="Pending">In Progress</span>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge bg-secondary">No IPCRs</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($f['is_active']): ?>
                      <span class="badge bg-success">Active</span>
                      <?php else: ?>
                      <span class="badge bg-secondary">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary" onclick="editFaculty(<?= $f['id'] ?>)" title="Edit">
                          <i class="bi bi-pencil"></i>
                        </button>
                        <a href="<?= BASE_URL ?>/faculty/history.php?user_id=<?= $f['id'] ?>" 
                           class="btn btn-sm btn-outline-secondary" title="IPCR History">
                          <i class="bi bi-clock-history"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-info" onclick="viewProfile(<?= $f['id'] ?>)" title="View Profile">
                          <i class="bi bi-eye"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    
    <?php endif; ?>
  </div>
</div>

<script>
function expandAll() {
  document.querySelectorAll('#facultyAccordion .accordion-collapse').forEach(el => {
    new bootstrap.Collapse(el, {show: true});
  });
}

function collapseAll() {
  document.querySelectorAll('#facultyAccordion .accordion-collapse').forEach(el => {
    new bootstrap.Collapse(el, {hide: true});
  });
}

function viewProfile(userId) {
  // TODO: Implement profile view modal
  Toast.info('Profile view coming soon');
}
</script>

<!-- Unified Add/Edit User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form id="addUserForm" class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" style="color: white !important;"><i class="bi bi-person-plus me-2"></i>Add New Faculty</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Error Alert (hidden by default) -->
        <div class="alert alert-danger d-none" id="formErrorAlert" role="alert">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <span id="formErrorMessage"></span>
        </div>
        
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Employee ID <span class="required-star">*</span></label>
            <input type="text" class="form-control" name="employee_id" id="f_employee_id" required placeholder="e.g. FAC004">
          </div>
          <div class="col-md-6">
            <label class="form-label">Employment Status</label>
            <select class="form-select" name="employment_status" id="f_employment_status">
              <option value="permanent">Permanent</option>
              <option value="temporary">Temporary</option>
              <option value="contractual">Contractual</option>
              <option value="part-time">Part-time</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">First Name <span class="required-star">*</span></label>
            <input type="text" class="form-control" name="first_name" id="f_first_name" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-control" name="middle_name" id="f_middle_name">
          </div>
          <div class="col-md-3">
            <label class="form-label">Last Name <span class="required-star">*</span></label>
            <input type="text" class="form-control" name="last_name" id="f_last_name" required>
          </div>
          <div class="col-md-1">
            <label class="form-label">Suffix</label>
            <input type="text" class="form-control" name="suffix" id="f_suffix" placeholder="Jr.">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" id="f_email">
          </div>
          <div class="col-md-6">
            <label class="form-label">Position</label>
            <select class="form-select" name="position" id="f_position">
              <option value="">— Select Position —</option>
              <?php foreach ($FACULTY_POSITIONS as $pos): ?>
              <option value="<?= h($pos) ?>"><?= h($pos) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12">
            <label class="form-label">College / Department</label>
            <select class="form-select" name="department" id="f_department">
              <option value="">— Select Department —</option>
              <?php foreach ($departments as $dept): ?>
              <option value="<?= h($dept) ?>"><?= h($dept) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12">
            <label class="form-label">Assigned Evaluator (Dean)</label>
            <input type="text" class="form-control" value="<?= h(fullName($currentUser)) ?> (You)" readonly style="background-color: #f8f9fa;">
            <div class="form-text">Faculty will be automatically assigned to you as their evaluator.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Temporary Password <span class="required-star">*</span></label>
            <input type="text" class="form-control" name="password" id="f_password" value="password123" placeholder="Min 6 characters">
            <div class="form-text">Faculty can change this after first login.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSaveUser">
          <i class="bi bi-save me-1"></i>Add Faculty
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Handle Add User Form Submission with AJAX
document.getElementById('addUserForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const btn = document.getElementById('btnSaveUser');
  const errorAlert = document.getElementById('formErrorAlert');
  const errorMessage = document.getElementById('formErrorMessage');
  
  // Hide previous errors
  errorAlert.classList.add('d-none');
  
  // Get form data
  const formData = new FormData();
  formData.append('action', 'create');
  formData.append('role', 'faculty');
  formData.append('assigned_dean_id', '<?= $currentUserId ?>');
  formData.append('employee_id', document.getElementById('f_employee_id').value.trim());
  formData.append('first_name', document.getElementById('f_first_name').value.trim());
  formData.append('middle_name', document.getElementById('f_middle_name').value.trim());
  formData.append('last_name', document.getElementById('f_last_name').value.trim());
  formData.append('suffix', document.getElementById('f_suffix').value.trim());
  formData.append('email', document.getElementById('f_email').value.trim());
  formData.append('position', document.getElementById('f_position').value);
  formData.append('department', document.getElementById('f_department').value);
  formData.append('employment_status', document.getElementById('f_employment_status').value);
  formData.append('password', document.getElementById('f_password').value);
  
  // Disable button
  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
  
  try {
    const response = await fetch('<?= BASE_URL ?>/admin/users.php', {
      method: 'POST',
      body: formData
    });
    
    const text = await response.text();
    
    // Check if response contains error message
    if (text.includes('already exists') || text.includes('required') || text.includes('Error')) {
      // Extract error message from response
      const parser = new DOMParser();
      const doc = parser.parseFromString(text, 'text/html');
      const alertDiv = doc.querySelector('.alert-danger');
      const errorMsg = alertDiv ? alertDiv.textContent.trim() : 'An error occurred. Please check your input.';
      
      // Show error in modal
      errorMessage.textContent = errorMsg;
      errorAlert.classList.remove('d-none');
      
      // Re-enable button
      btn.disabled = false;
      btn.innerHTML = originalText;
      
      // Scroll to error
      errorAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
      // Success - close modal and reload
      bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
      
      // Show success toast
      if (typeof Toast !== 'undefined') {
        Toast.success('Faculty added successfully!');
      } else {
        alert('Faculty added successfully!');
      }
      
      // Reload page after short delay
      setTimeout(() => location.reload(), 500);
    }
  } catch (error) {
    // Show error
    errorMessage.textContent = 'Network error. Please try again.';
    errorAlert.classList.remove('d-none');
    
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
});

// Reset form when modal is closed
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
  document.getElementById('addUserForm').reset();
  document.getElementById('formErrorAlert').classList.add('d-none');
});
</script>

<!-- Edit Faculty Modal -->
<div class="modal fade" id="editFacultyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Faculty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-faculty-id">
        <form id="editFacultyForm">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">First Name <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="edit-first-name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="edit-last-name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Employee ID <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="edit-employee-id" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <span class="required-star">*</span></label>
              <input type="email" class="form-control" id="edit-email" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Department</label>
              <div class="input-group">
                <select class="form-select" id="edit-department">
                  <option value="">— Select Department —</option>
                  <?php foreach ($departments as $dept): ?>
                  <option value="<?= h($dept) ?>"><?= h($dept) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="quickAddDepartment()" title="Add new department">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Position</label>
              <select class="form-select" id="edit-position">
                <option value="">— Select Position —</option>
                <?php foreach ($FACULTY_POSITIONS as $pos): ?>
                <option value="<?= h($pos) ?>"><?= h($pos) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select class="form-select" id="edit-is-active">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btn-update-faculty">
          <i class="bi bi-save me-1"></i>Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Quick Add Department
async function quickAddDepartment() {
  const deptName = prompt('Enter new department/program name:', '');
  
  if (!deptName || !deptName.trim()) {
    return;
  }
  
  const deptAbbr = prompt('Enter abbreviation (optional):', '');
  
  try {
    const response = await fetch('<?= BASE_URL ?>/api/dean-add-department.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        name: deptName.trim(),
        abbreviation: deptAbbr ? deptAbbr.trim() : ''
      })
    });
    
    const data = await response.json();
    
    if (data.success) {
      Toast.success('Department added successfully');
      
      // Add to both dropdowns
      const addSelect = document.getElementById('add-department');
      const editSelect = document.getElementById('edit-department');
      
      if (addSelect) {
        const option = document.createElement('option');
        option.value = deptName.trim();
        option.textContent = deptName.trim();
        addSelect.appendChild(option);
        addSelect.value = deptName.trim();
      }
      
      if (editSelect) {
        const option = document.createElement('option');
        option.value = deptName.trim();
        option.textContent = deptName.trim();
        editSelect.appendChild(option);
        editSelect.value = deptName.trim();
      }
    } else {
      Toast.error(data.message || 'Failed to add department');
    }
  } catch (error) {
    Toast.error('Failed to add department');
    console.error(error);
  }
}

// Edit Faculty
async function editFaculty(userId) {
  try {
    const response = await fetch('<?= BASE_URL ?>/api/get-user.php?id=' + userId);
    const data = await response.json();
    
    if (data.success) {
      const user = data.user;
      document.getElementById('edit-faculty-id').value = user.id;
      document.getElementById('edit-first-name').value = user.first_name;
      document.getElementById('edit-last-name').value = user.last_name;
      document.getElementById('edit-employee-id').value = user.employee_id;
      document.getElementById('edit-email').value = user.email;
      document.getElementById('edit-department').value = user.department || '';
      document.getElementById('edit-position').value = user.position || '';
      document.getElementById('edit-is-active').value = user.is_active;
      
      new bootstrap.Modal(document.getElementById('editFacultyModal')).show();
    } else {
      Toast.error('Failed to load faculty data');
    }
  } catch (error) {
    Toast.error('Failed to load faculty data');
  }
}

document.getElementById('btn-update-faculty').addEventListener('click', async function() {
  const userId = document.getElementById('edit-faculty-id').value;
  const formData = {
    id: parseInt(userId),
    first_name: document.getElementById('edit-first-name').value.trim(),
    last_name: document.getElementById('edit-last-name').value.trim(),
    employee_id: document.getElementById('edit-employee-id').value.trim(),
    email: document.getElementById('edit-email').value.trim(),
    department: document.getElementById('edit-department').value.trim(),
    position: document.getElementById('edit-position').value.trim(),
    is_active: parseInt(document.getElementById('edit-is-active').value)
  };
  
  try {
    const response = await fetch('<?= BASE_URL ?>/api/dean-update-user.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(formData)
    });
    
    const data = await response.json();
    
    if (data.success) {
      Toast.success('Faculty updated successfully');
      setTimeout(() => location.reload(), 1000);
    } else {
      Toast.error(data.message);
    }
  } catch (error) {
    Toast.error('Failed to update faculty');
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

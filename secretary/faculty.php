<?php
$pageTitle  = 'Faculty Management';
$activePage = 'secretary-faculty';
require_once __DIR__ . '/../includes/header.php';
requireRole('secretary', 'dean', 'admin');

$db = getDB();

// Get secretary's assigned dean
$assignedDeanId = $currentRole === 'secretary' ? $currentUser['assigned_dean_id'] : $currentUserId;

if (!$assignedDeanId && $currentRole === 'secretary') {
    echo '<div class="alert alert-danger">You are not assigned to any dean. Contact administrator.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get faculty under this dean
$faculty = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM ipcr_forms WHERE user_id=u.id) as total_ipcrs,
           (SELECT COUNT(*) FROM ipcr_forms WHERE user_id=u.id AND status='finalized') as completed_ipcrs
    FROM users u
    WHERE u.assigned_dean_id = ? AND u.role = 'faculty' AND u.is_active = 1
    ORDER BY u.last_name, u.first_name
");
$faculty->execute([$assignedDeanId]);
$faculty = $faculty->fetchAll();

// Get departments
$departments = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-people me-2"></i>Faculty Management</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Manage faculty information and update their profiles</p>
  </div>
  <?php if ($currentRole === 'secretary'): ?>
  <div class="alert alert-warning mb-0" style="font-size:.85rem;padding:8px 12px">
    <i class="bi bi-exclamation-triangle me-1"></i>Your edits will be submitted for dean approval before being applied.
  </div>
  <?php else: ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
    <i class="bi bi-plus-lg me-2"></i>Add Faculty
  </button>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-primary mx-auto"><i class="bi bi-people-fill"></i></div>
      <div class="metric-value"><?= count($faculty) ?></div>
      <div class="metric-label">Total Faculty</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-check-circle"></i></div>
      <div class="metric-value"><?= count(array_filter($faculty, fn($f) => $f['is_active'])) ?></div>
      <div class="metric-label">Active</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-info mx-auto"><i class="bi bi-file-text"></i></div>
      <div class="metric-value"><?= array_sum(array_column($faculty, 'total_ipcrs')) ?></div>
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
      <i class="bi bi-info-circle me-2"></i>No faculty found.
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
                  <?= $deptCount ?> Faculty
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
                    <th style="width:180px">Actions</th>
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
                        <span class="badge bg-info"><?= $f['total_ipcrs'] ?></span>
                        <span class="badge bg-success"><?= $f['completed_ipcrs'] ?> done</span>
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
                        <button class="btn btn-sm btn-outline-primary" onclick="<?= $currentRole === 'secretary' ? 'requestEdit' : 'editFaculty' ?>(<?= $f['id'] ?>)" title="Edit">
                          <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="viewDetails(<?= $f['id'] ?>)" title="View">
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
</script>

<!-- Edit/Request Edit Modal (Unified) -->
<div class="modal fade" id="editFacultyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-pencil me-2"></i>
          <?php if ($currentRole === 'secretary'): ?>
          Edit Faculty (Requires Approval)
          <?php else: ?>
          Edit Faculty
          <?php endif; ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($currentRole === 'secretary'): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>Note:</strong> Your changes will be submitted for dean approval. The dean will review and approve/reject your request.
        </div>
        <?php endif; ?>
        
        <input type="hidden" id="edit-faculty-id">
        
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input type="text" class="form-control" id="edit-first-name">
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input type="text" class="form-control" id="edit-last-name">
          </div>
          <div class="col-md-6">
            <label class="form-label">Employee ID</label>
            <input type="text" class="form-control" id="edit-employee-id">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" id="edit-email">
          </div>
          <div class="col-md-6">
            <label class="form-label">Department</label>
            <input type="text" class="form-control" id="edit-department" list="dept-list">
            <datalist id="dept-list">
              <?php foreach ($departments as $dept): ?>
              <option value="<?= h($dept) ?>">
              <?php endforeach; ?>
            </datalist>
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
          <?php if ($currentRole === 'secretary'): ?>
          <div class="col-12">
            <label class="form-label">Remarks (Why are you requesting this change?) <span class="required-star">*</span></label>
            <textarea class="form-control" id="edit-remarks" rows="3" placeholder="Explain the reason for this change..." required></textarea>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btn-save-faculty">
          <i class="bi bi-save me-1"></i>
          <?php if ($currentRole === 'secretary'): ?>
          Submit for Approval
          <?php else: ?>
          Save Changes
          <?php endif; ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Single function for both dean direct edit and secretary request edit
async function requestEdit(userId) {
  try {
    const response = await fetch('<?= BASE_URL ?>/api/get-user.php?id=' + userId);
    
    const data = await response.json();
    
    if (data.success) {
      const user = data.user;
      document.getElementById('edit-faculty-id').value = user.id;
      document.getElementById('edit-first-name').value = user.first_name || '';
      document.getElementById('edit-last-name').value = user.last_name || '';
      document.getElementById('edit-employee-id').value = user.employee_id || '';
      document.getElementById('edit-email').value = user.email || '';
      document.getElementById('edit-department').value = user.department || '';
      document.getElementById('edit-position').value = user.position || '';
      document.getElementById('edit-is-active').value = user.is_active || 1;
      
      <?php if ($currentRole === 'secretary'): ?>
      document.getElementById('edit-remarks').value = '';
      <?php endif; ?>
      
      new bootstrap.Modal(document.getElementById('editFacultyModal')).show();
    } else {
      console.error('API returned error:', data.message);
      Toast.error(data.message || 'Failed to load faculty data');
    }
  } catch (error) {
    console.error('Exception in requestEdit:', error);
    Toast.error('Failed to load faculty data: ' + error.message);
  }
}

// For dean - same function name, different behavior
<?php if ($currentRole !== 'secretary'): ?>
function editFaculty(userId) {
  requestEdit(userId); // Same UI, pero iba ang save handler
}
<?php endif; ?>

document.getElementById('btn-save-faculty').addEventListener('click', async function() {
  const userId = document.getElementById('edit-faculty-id').value;
  
  <?php if ($currentRole === 'secretary'): ?>
  // SECRETARY: Submit as edit request
  const remarks = document.getElementById('edit-remarks').value.trim();
  
  if (!remarks) {
    Toast.error('Please provide remarks for this change request');
    return;
  }
  
  const requestData = {
    first_name: document.getElementById('edit-first-name').value.trim(),
    last_name: document.getElementById('edit-last-name').value.trim(),
    employee_id: document.getElementById('edit-employee-id').value.trim(),
    email: document.getElementById('edit-email').value.trim(),
    department: document.getElementById('edit-department').value.trim(),
    position: document.getElementById('edit-position').value.trim(),
    is_active: parseInt(document.getElementById('edit-is-active').value)
  };
  
  try {
    const response = await fetch('<?= BASE_URL ?>/api/submit-edit-request.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        request_type: 'user',
        target_id: parseInt(userId),
        request_data: requestData,
        remarks: remarks
      })
    });
    
    const data = await response.json();
    
    if (data.success) {
      Toast.success('Edit request submitted! Waiting for dean approval.');
      bootstrap.Modal.getInstance(document.getElementById('editFacultyModal')).hide();
      setTimeout(() => location.reload(), 1500);
    } else {
      Toast.error(data.message);
    }
  } catch (error) {
    Toast.error('Failed to submit request');
  }
  
  <?php else: ?>
  // DEAN: Direct save (for future dean implementation)
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
  <?php endif; ?>
});

function viewDetails(userId) {
  window.location.href = '<?= BASE_URL ?>/faculty/history.php?user_id=' + userId;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

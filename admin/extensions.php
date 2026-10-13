<?php
$pageTitle = 'Submission Extensions';
$activePage = 'extensions';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin', 'dean');

$db = getDB();

// Get closed periods that might need extensions
$periods = $db->query("
    SELECT * FROM rating_periods 
    WHERE status IN ('open', 'closed')
    ORDER BY created_at DESC
")->fetchAll();

// Get active extensions
$extensions = $db->query("
    SELECT se.*, 
           u.first_name, u.last_name, u.employee_id, u.email,
           p.name as period_name, p.status as period_status,
           gb.first_name as granted_by_fname, gb.last_name as granted_by_lname
    FROM submission_extensions se
    JOIN users u ON se.user_id = u.id
    JOIN rating_periods p ON se.period_id = p.id
    LEFT JOIN users gb ON se.granted_by = gb.id
    WHERE se.is_used = 0
    ORDER BY se.extended_deadline ASC
")->fetchAll();

// Get faculty who haven't submitted for closed periods
$notSubmitted = $db->query("
    SELECT p.id as period_id, p.name as period_name, p.status,
           u.id as user_id, u.first_name, u.last_name, u.employee_id, u.email,
           u.department
    FROM rating_periods p
    CROSS JOIN users u
    LEFT JOIN ipcr_forms f ON f.period_id = p.id AND f.user_id = u.id
    WHERE p.status IN ('open', 'closed')
    AND u.role = 'faculty' AND u.is_active = 1
    AND u.assigned_dean_id IS NOT NULL
    AND (f.id IS NULL OR f.status IN ('draft', 'returned'))
    ORDER BY p.created_at DESC, u.last_name ASC
")->fetchAll();

// Group by period
$notSubmittedByPeriod = [];
foreach ($notSubmitted as $item) {
    $notSubmittedByPeriod[$item['period_id']][] = $item;
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-calendar-plus me-2"></i>Submission Extensions</h1>
    <p class="text-muted-sm">Grant deadline extensions to faculty members after period closure</p>
  </div>
</div>

<!-- Active Extensions -->
<?php if (!empty($extensions)): ?>
<div class="card mb-4">
  <div class="card-header">
    <h6><i class="bi bi-clock-history me-2"></i>Active Extensions (<?= count($extensions) ?>)</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>Faculty</th>
            <th>Period</th>
            <th>Extended Deadline</th>
            <th>Time Remaining</th>
            <th>Granted By</th>
            <th>Reason</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($extensions as $ext): 
            $timeRemaining = strtotime($ext['extended_deadline']) - time();
            $daysRemaining = ceil($timeRemaining / 86400);
            $isExpired = $timeRemaining < 0;
            $isUrgent = $daysRemaining <= 2 && !$isExpired;
          ?>
          <tr class="<?= $isExpired ? 'table-danger' : ($isUrgent ? 'table-warning' : '') ?>">
            <td>
              <div class="fw-bold"><?= h($ext['first_name'] . ' ' . $ext['last_name']) ?></div>
              <div class="text-muted-sm"><?= h($ext['employee_id']) ?></div>
            </td>
            <td>
              <div><?= h($ext['period_name']) ?></div>
              <div><?= statusBadge($ext['period_status']) ?></div>
            </td>
            <td>
              <strong><?= date('M d, Y g:i A', strtotime($ext['extended_deadline'])) ?></strong>
            </td>
            <td>
              <?php if ($isExpired): ?>
                <span class="badge bg-danger">⚠️ EXPIRED</span>
              <?php elseif ($isUrgent): ?>
                <span class="badge bg-warning text-dark">⏰ <?= $daysRemaining ?> day(s)</span>
              <?php else: ?>
                <span class="badge bg-success"><?= $daysRemaining ?> day(s)</span>
              <?php endif; ?>
            </td>
            <td class="text-muted-sm">
              <?= h($ext['granted_by_fname'] . ' ' . $ext['granted_by_lname']) ?><br>
              <small><?= date('M d, Y', strtotime($ext['granted_at'])) ?></small>
            </td>
            <td class="text-muted-sm" style="max-width: 200px;">
              <?= $ext['reason'] ? h(mb_substr($ext['reason'], 0, 80)) . (strlen($ext['reason']) > 80 ? '...' : '') : '<em>No reason provided</em>' ?>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-danger btn-revoke-extension" 
                      data-extension-id="<?= $ext['id'] ?>"
                      data-faculty-name="<?= h($ext['first_name'] . ' ' . $ext['last_name']) ?>">
                <i class="bi bi-x-circle"></i> Revoke
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Grant New Extensions -->
<div class="card">
  <div class="card-header">
    <h6><i class="bi bi-plus-circle me-2"></i>Grant New Extensions</h6>
  </div>
  <div class="card-body">
    
    <?php if (empty($notSubmittedByPeriod)): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      All faculty have submitted their IPCRs for all active periods. No extensions needed.
    </div>
    <?php else: ?>
    
    <form id="extension-form">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Select Period <span class="required-star">*</span></label>
          <select class="form-select" id="select-period" name="period_id" required>
            <option value="">-- Select Rating Period --</option>
            <?php foreach ($notSubmittedByPeriod as $periodId => $items): ?>
              <option value="<?= $periodId ?>">
                <?= h($items[0]['period_name']) ?> 
                (<?= count($items) ?> not submitted - <?= ucfirst($items[0]['status']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="col-md-6">
          <label class="form-label fw-semibold">Extended Deadline <span class="required-star">*</span></label>
          <input type="datetime-local" class="form-control" name="extended_deadline" 
                 id="extended-deadline" required
                 min="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
          <div class="form-text">New deadline for selected faculty to submit</div>
        </div>
        
        <div class="col-12">
          <label class="form-label fw-semibold">Reason for Extension</label>
          <textarea class="form-control" name="reason" rows="2" 
                    placeholder="Optional: Why is this extension being granted? (e.g., medical emergency, technical issues, etc.)"></textarea>
        </div>
        
        <div class="col-12">
          <label class="form-label fw-semibold">Select Faculty Members <span class="required-star">*</span></label>
          <div id="faculty-list" class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
            <p class="text-muted text-center">Please select a period first</p>
          </div>
        </div>
        
        <div class="col-12">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-calendar-plus me-1"></i> Grant Extension
          </button>
          <button type="reset" class="btn btn-outline-secondary">
            <i class="bi bi-x me-1"></i> Clear
          </button>
        </div>
      </div>
    </form>
    
    <?php endif; ?>
  </div>
</div>

<script>
const notSubmittedData = <?= json_encode($notSubmittedByPeriod) ?>;

document.getElementById('select-period')?.addEventListener('change', function() {
  const periodId = this.value;
  const facultyList = document.getElementById('faculty-list');
  
  if (!periodId || !notSubmittedData[periodId]) {
    facultyList.innerHTML = '<p class="text-muted text-center">Please select a period first</p>';
    return;
  }
  
  const faculty = notSubmittedData[periodId];
  let html = `
    <div class="mb-2">
      <label class="form-check">
        <input type="checkbox" class="form-check-input" id="select-all-faculty">
        <span class="form-check-label fw-bold">Select All (${faculty.length})</span>
      </label>
    </div>
    <hr>
  `;
  
  faculty.forEach(f => {
    html += `
      <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input faculty-checkbox" 
               value="${f.user_id}" name="user_ids[]" id="faculty-${f.user_id}">
        <label class="form-check-label" for="faculty-${f.user_id}">
          <strong>${f.first_name} ${f.last_name}</strong>
          <span class="text-muted-sm">(${f.employee_id}) - ${f.department || 'No Dept'}</span>
        </label>
      </div>
    `;
  });
  
  facultyList.innerHTML = html;
  
  // Select all functionality
  document.getElementById('select-all-faculty').addEventListener('change', function() {
    document.querySelectorAll('.faculty-checkbox').forEach(cb => {
      cb.checked = this.checked;
    });
  });
});

// Form submission
document.getElementById('extension-form')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const selectedFaculty = Array.from(formData.getAll('user_ids[]')).map(Number);
  
  if (selectedFaculty.length === 0) {
    Toast.error('Please select at least one faculty member.');
    return;
  }
  
  if (!confirm(`Grant extension to ${selectedFaculty.length} faculty member(s)?`)) {
    return;
  }
  
  const submitBtn = this.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Granting...';
  
  try {
    const response = await fetch(window.BASE_URL + '/api/grant-extension.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        period_id: parseInt(formData.get('period_id')),
        user_ids: selectedFaculty,
        extended_deadline: formData.get('extended_deadline'),
        reason: formData.get('reason') || ''
      })
    });
    
    const data = await response.json();
    
    if (data.success) {
      Toast.success(data.message);
      setTimeout(() => location.reload(), 1500);
    } else {
      Toast.error(data.message || 'Failed to grant extension.');
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="bi bi-calendar-plus me-1"></i> Grant Extension';
    }
  } catch (error) {
    Toast.error('Error granting extension.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-calendar-plus me-1"></i> Grant Extension';
  }
});

// Revoke extension
document.querySelectorAll('.btn-revoke-extension').forEach(btn => {
  btn.addEventListener('click', async function() {
    const extensionId = this.dataset.extensionId;
    const facultyName = this.dataset.facultyName;
    
    if (!confirm(`Revoke extension for ${facultyName}?`)) return;
    
    try {
      const response = await fetch(window.BASE_URL + '/api/revoke-extension.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({extension_id: parseInt(extensionId)})
      });
      
      const data = await response.json();
      
      if (data.success) {
        Toast.success('Extension revoked.');
        setTimeout(() => location.reload(), 1000);
      } else {
        Toast.error(data.message || 'Failed to revoke extension.');
      }
    } catch (error) {
      Toast.error('Error revoking extension.');
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

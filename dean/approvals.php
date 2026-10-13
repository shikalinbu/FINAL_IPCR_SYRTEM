<?php
$pageTitle  = 'IPCR Edit Request Approvals';
$activePage = 'dean-approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole('dean');

$db = getDB();

// Get all pending requests for this dean
$requests = $db->prepare("
    SELECT er.*, 
           CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
           u.employee_id as requester_employee_id
    FROM edit_requests er
    JOIN users u ON er.requested_by = u.id
    WHERE er.dean_id = ? AND er.status = 'pending'
    ORDER BY er.created_at DESC
");
$requests->execute([$currentUserId]);
$requests = $requests->fetchAll();

// Get processed requests (last 30 days)
$processed = $db->prepare("
    SELECT er.*, 
           CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
           u.employee_id as requester_employee_id,
           CONCAT(r.first_name, ' ', r.last_name) as reviewed_by_name
    FROM edit_requests er
    JOIN users u ON er.requested_by = u.id
    LEFT JOIN users r ON er.reviewed_by = r.id
    WHERE er.dean_id = ? AND er.status IN ('approved', 'rejected')
          AND er.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY er.reviewed_at DESC
    LIMIT 50
");
$processed->execute([$currentUserId]);
$processed = $processed->fetchAll();

function getRequestTypeBadge($type) {
    $badges = [
        'user' => '<span class="badge bg-primary">User</span>',
        'ipcr' => '<span class="badge bg-success">IPCR</span>',
        'department' => '<span class="badge bg-info">Department</span>',
        'template' => '<span class="badge bg-warning text-dark">Template</span>',
        'period' => '<span class="badge bg-secondary">Period</span>'
    ];
    return $badges[$type] ?? '<span class="badge bg-light text-dark">' . h($type) . '</span>';
}

function formatRequestChanges($requestData, $originalData) {
    if (!$requestData || !$originalData) return 'New entry';
    
    $changes = [];
    foreach ($requestData as $key => $newValue) {
        $oldValue = $originalData[$key] ?? null;
        if ($oldValue != $newValue) {
            $changes[] = "<strong>" . h(ucwords(str_replace('_', ' ', $key))) . ":</strong> " 
                       . "<span class='text-danger'>" . h($oldValue) . "</span> → " 
                       . "<span class='text-success'>" . h($newValue) . "</span>";
        }
    }
    return $changes ? implode('<br>', $changes) : 'No changes detected';
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-clipboard-check me-2"></i>IPCR Edit Request Approvals</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Review and approve faculty requests to edit their submitted IPCR forms</p>
  </div>
  <div class="d-flex gap-2">
    <span class="badge bg-danger" style="font-size:1.1rem;padding:8px 16px">
      <?= count($requests) ?> Pending
    </span>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-warning mx-auto"><i class="bi bi-clock-history"></i></div>
      <div class="metric-value"><?= count($requests) ?></div>
      <div class="metric-label">Pending</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-check2-all"></i></div>
      <div class="metric-value"><?= count(array_filter($processed, fn($r) => $r['status'] === 'approved')) ?></div>
      <div class="metric-label">Approved (30d)</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-danger mx-auto"><i class="bi bi-x-circle"></i></div>
      <div class="metric-value"><?= count(array_filter($processed, fn($r) => $r['status'] === 'rejected')) ?></div>
      <div class="metric-label">Rejected (30d)</div>
    </div>
  </div>
</div>

<!-- Pending Requests -->
<div class="card mb-4">
  <div class="card-header">
    <h5><i class="bi bi-hourglass-split me-2"></i>Pending Edit Requests</h5>
  </div>
  <div class="card-body">
    <?php if (!$requests): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>No pending requests. All caught up!
    </div>
    <?php else: ?>
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Type</th>
            <th>Requested By</th>
            <th>Changes</th>
            <th>Remarks</th>
            <th>Date</th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): 
            $requestData = json_decode($req['request_data'], true);
            $originalData = json_decode($req['original_data'], true);
          ?>
          <tr>
            <td><strong>#<?= $req['id'] ?></strong></td>
            <td><?= getRequestTypeBadge($req['request_type']) ?></td>
            <td>
              <strong><?= h($req['requested_by_name']) ?></strong>
              <div class="text-muted-sm"><?= h($req['requester_employee_id']) ?></div>
            </td>
            <td style="font-size:0.85rem">
              <?= formatRequestChanges($requestData, $originalData) ?>
            </td>
            <td><?= h($req['remarks'] ?: '—') ?></td>
            <td class="text-muted-sm"><?= h(date('M d, Y g:ia', strtotime($req['created_at']))) ?></td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" onclick="reviewRequest(<?= $req['id'] ?>, 'approve')">
                  <i class="bi bi-check-lg"></i> Approve
                </button>
                <button class="btn btn-sm btn-danger" onclick="reviewRequest(<?= $req['id'] ?>, 'reject')">
                  <i class="bi bi-x-lg"></i> Reject
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="viewDetails(<?= $req['id'] ?>)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Recent Processed -->
<div class="card">
  <div class="card-header">
    <h6><i class="bi bi-clock-history me-2"></i>Recently Processed Edit Requests (Last 30 Days)</h6>
  </div>
  <div class="card-body">
    <?php if (!$processed): ?>
    <div class="text-muted text-center py-3">No processed requests in the last 30 days.</div>
    <?php else: ?>
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Requested By</th>
            <th>Status</th>
            <th>Dean Remarks</th>
            <th>Reviewed Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($processed as $req): ?>
          <tr>
            <td><strong>#<?= $req['id'] ?></strong></td>
            <td><?= getRequestTypeBadge($req['request_type']) ?></td>
            <td><?= h($req['requested_by_name']) ?></td>
            <td>
              <?php if ($req['status'] === 'approved'): ?>
              <span class="badge bg-success">Approved</span>
              <?php else: ?>
              <span class="badge bg-danger">Rejected</span>
              <?php endif; ?>
            </td>
            <td><?= h($req['dean_remarks'] ?: '—') ?></td>
            <td class="text-muted-sm"><?= h(date('M d, Y g:ia', strtotime($req['reviewed_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reviewModalTitle">Review Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="review-request-id">
        <input type="hidden" id="review-action">
        
        <div class="alert alert-info" id="review-action-msg"></div>
        
        <label class="form-label fw-semibold">Your Remarks (Optional)</label>
        <textarea class="form-control" id="dean-remarks" rows="3" placeholder="Add comments..."></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btn-confirm-review">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
function reviewRequest(requestId, action) {
  document.getElementById('review-request-id').value = requestId;
  document.getElementById('review-action').value = action;
  document.getElementById('dean-remarks').value = '';
  
  const actionMsg = action === 'approve' 
    ? '<i class="bi bi-check-circle me-2"></i>You are about to <strong>APPROVE</strong> this request. Changes will be applied immediately.'
    : '<i class="bi bi-x-circle me-2"></i>You are about to <strong>REJECT</strong> this request. No changes will be made.';
  
  document.getElementById('review-action-msg').innerHTML = actionMsg;
  document.getElementById('review-action-msg').className = 'alert ' + (action === 'approve' ? 'alert-success' : 'alert-warning');
  document.getElementById('reviewModalTitle').textContent = (action === 'approve' ? 'Approve' : 'Reject') + ' Request #' + requestId;
  
  new bootstrap.Modal(document.getElementById('reviewModal')).show();
}

document.getElementById('btn-confirm-review').addEventListener('click', async function() {
  const requestId = document.getElementById('review-request-id').value;
  const action = document.getElementById('review-action').value;
  const remarks = document.getElementById('dean-remarks').value.trim();
  
  try {
    const response = await fetch('<?= BASE_URL ?>/api/approve-edit-request.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        request_id: parseInt(requestId),
        action: action,
        dean_remarks: remarks
      })
    });
    
    const data = await response.json();
    
    if (data.success) {
      Toast.success(data.message);
      setTimeout(() => location.reload(), 1000);
    } else {
      Toast.error(data.message);
    }
  } catch (error) {
    Toast.error('Failed to process request');
  }
});

function viewDetails(requestId) {
  // TODO: Implement detailed view modal
  Toast.info('Detailed view coming soon');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
$pageTitle  = 'My Edit Requests';
$activePage = 'secretary-requests';
require_once __DIR__ . '/../includes/header.php';
requireRole('secretary');

$db = getDB();

// Get all requests by this secretary
$requests = $db->prepare("
    SELECT er.*, 
           CONCAT(d.first_name, ' ', d.last_name) as dean_name,
           CONCAT(r.first_name, ' ', r.last_name) as reviewed_by_name
    FROM edit_requests er
    JOIN users d ON er.dean_id = d.id
    LEFT JOIN users r ON er.reviewed_by = r.id
    WHERE er.requested_by = ?
    ORDER BY 
        FIELD(er.status, 'pending', 'approved', 'rejected'),
        er.created_at DESC
");
$requests->execute([$currentUserId]);
$requests = $requests->fetchAll();

$pendingCount = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$approvedCount = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
$rejectedCount = count(array_filter($requests, fn($r) => $r['status'] === 'rejected'));

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
                       . "<span class='text-muted'>" . h($oldValue) . "</span> → " 
                       . "<span class='text-primary fw-bold'>" . h($newValue) . "</span>";
        }
    }
    return $changes ? implode('<br>', $changes) : 'No changes detected';
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-clock-history me-2"></i>My Edit Requests</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Track the status of IPCR edit requests you've submitted to deans</p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-warning mx-auto"><i class="bi bi-hourglass-split"></i></div>
      <div class="metric-value"><?= $pendingCount ?></div>
      <div class="metric-label">Pending</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-check2-all"></i></div>
      <div class="metric-value"><?= $approvedCount ?></div>
      <div class="metric-label">Approved</div>
    </div>
  </div>
  <div class="col-4">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-danger mx-auto"><i class="bi bi-x-circle"></i></div>
      <div class="metric-value"><?= $rejectedCount ?></div>
      <div class="metric-label">Rejected</div>
    </div>
  </div>
</div>

<?php if (!$requests): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-inbox" style="font-size:4rem;color:var(--gray-300)"></i>
    <h5 class="mt-3">No Requests Yet</h5>
    <p class="text-muted">You haven't submitted any edit requests. When you request changes, they will appear here.</p>
    <a href="<?= BASE_URL ?>/secretary/faculty.php" class="btn btn-primary">
      <i class="bi bi-people me-2"></i>Manage Faculty
    </a>
  </div>
</div>
<?php else: ?>

<!-- Pending Requests -->
<?php 
$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');
if ($pending): 
?>
<div class="card mb-4">
  <div class="card-header bg-warning text-dark">
    <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>Pending Requests (<?= count($pending) ?>)</h5>
  </div>
  <div class="card-body">
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Type</th>
            <th>Changes Requested</th>
            <th>Your Remarks</th>
            <th>Assigned Dean</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $req): 
            $requestData = json_decode($req['request_data'], true);
            $originalData = json_decode($req['original_data'], true);
          ?>
          <tr>
            <td><strong>#<?= $req['id'] ?></strong></td>
            <td><?= getRequestTypeBadge($req['request_type']) ?></td>
            <td style="font-size:0.85rem">
              <?= formatRequestChanges($requestData, $originalData) ?>
            </td>
            <td><?= h($req['remarks'] ?: '—') ?></td>
            <td><?= h($req['dean_name']) ?></td>
            <td class="text-muted-sm"><?= h(date('M d, Y g:ia', strtotime($req['created_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Approved Requests -->
<?php 
$approved = array_filter($requests, fn($r) => $r['status'] === 'approved');
if ($approved): 
?>
<div class="card mb-4">
  <div class="card-header bg-success text-white">
    <h5 class="mb-0"><i class="bi bi-check2-all me-2"></i>Approved Requests (<?= count($approved) ?>)</h5>
  </div>
  <div class="card-body">
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Type</th>
            <th>Changes</th>
            <th>Dean Remarks</th>
            <th>Reviewed By</th>
            <th>Approved Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($approved as $req): 
            $requestData = json_decode($req['request_data'], true);
            $originalData = json_decode($req['original_data'], true);
          ?>
          <tr>
            <td><strong>#<?= $req['id'] ?></strong></td>
            <td><?= getRequestTypeBadge($req['request_type']) ?></td>
            <td style="font-size:0.85rem">
              <?= formatRequestChanges($requestData, $originalData) ?>
            </td>
            <td><?= h($req['dean_remarks'] ?: '—') ?></td>
            <td><?= h($req['reviewed_by_name']) ?></td>
            <td class="text-muted-sm"><?= h(date('M d, Y g:ia', strtotime($req['reviewed_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Rejected Requests -->
<?php 
$rejected = array_filter($requests, fn($r) => $r['status'] === 'rejected');
if ($rejected): 
?>
<div class="card mb-4">
  <div class="card-header bg-danger text-white">
    <h5 class="mb-0"><i class="bi bi-x-circle me-2"></i>Rejected Requests (<?= count($rejected) ?>)</h5>
  </div>
  <div class="card-body">
    <div class="table-container">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Type</th>
            <th>Changes</th>
            <th>Dean Remarks (Reason)</th>
            <th>Reviewed By</th>
            <th>Rejected Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rejected as $req): 
            $requestData = json_decode($req['request_data'], true);
            $originalData = json_decode($req['original_data'], true);
          ?>
          <tr>
            <td><strong>#<?= $req['id'] ?></strong></td>
            <td><?= getRequestTypeBadge($req['request_type']) ?></td>
            <td style="font-size:0.85rem">
              <?= formatRequestChanges($requestData, $originalData) ?>
            </td>
            <td><?= h($req['dean_remarks'] ?: 'No reason provided') ?></td>
            <td><?= h($req['reviewed_by_name']) ?></td>
            <td class="text-muted-sm"><?= h(date('M d, Y g:ia', strtotime($req['reviewed_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db = getDB();

// Define these early — header.php also sets them but we need them before that include
$currentUser   = getCurrentUser();
$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Secretary and Dean both use the admin-like dashboard
$isAdminLike = in_array($currentRole, ['admin', 'secretary', 'dean']);

// Get active period
$period = $db->query("SELECT * FROM rating_periods WHERE status IN ('open','rating') ORDER BY id DESC LIMIT 1")->fetch();

if ($isAdminLike) {
    // Admin/Secretary/Dean stats — full system view
    $totalUsers   = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
    $totalFaculty = $db->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND is_active=1")->fetchColumn();
    $totalDeans   = $db->query("SELECT COUNT(*) FROM users WHERE role='dean' AND is_active=1")->fetchColumn();

    $statusCounts = $db->query("
        SELECT status, COUNT(*) as cnt 
        FROM ipcr_forms 
        " . ($period ? "WHERE period_id = {$period['id']}" : "") . "
        GROUP BY status
    ")->fetchAll();
    $scMap = array_column($statusCounts, 'cnt', 'status');
    
    // Calculate total successfully submitted (for the SUBMITTED card display)
    $scMap['total_submitted'] = ($scMap['submitted'] ?? 0) + 
                                 ($scMap['under_review'] ?? 0) + 
                                 ($scMap['reviewed'] ?? 0) + 
                                 ($scMap['approved'] ?? 0) + 
                                 ($scMap['finalized'] ?? 0);
    
    // Submission tracking statistics for active period
    $submissionStats = [
        'on_time' => 0,
        'late' => 0,
        'not_submitted' => 0
    ];
    
    if ($period) {
        // Count on-time submissions (submitted and not late)
        $submissionStats['on_time'] = $db->query("
            SELECT COUNT(*) FROM ipcr_forms 
            WHERE period_id = {$period['id']} AND is_late = 0 
            AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        ")->fetchColumn();
        
        // Count late submissions
        $submissionStats['late'] = $db->query("
            SELECT COUNT(*) FROM ipcr_forms 
            WHERE period_id = {$period['id']} AND is_late = 1
            AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        ")->fetchColumn();
        
        // Count not submitted (active faculty without successfully submitted form for this period)
        $submittedUserIds = $db->query("
            SELECT DISTINCT user_id FROM ipcr_forms 
            WHERE period_id = {$period['id']} 
            AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        $whereNotSubmitted = "role='faculty' AND is_active=1";
        if (!empty($submittedUserIds)) {
            $idList = implode(',', array_map('intval', $submittedUserIds));
            $whereNotSubmitted .= " AND id NOT IN ({$idList})";
        }
        
        // If dean, only count their assigned faculty
        if ($currentRole === 'dean') {
            $whereNotSubmitted .= " AND assigned_dean_id = {$currentUserId}";
        }
        
        $submissionStats['not_submitted'] = $db->query("
            SELECT COUNT(*) FROM users WHERE {$whereNotSubmitted}
        ")->fetchColumn();
    }

    $recentAudit = $db->query("
        SELECT a.*, CONCAT(u.first_name,' ',u.last_name) as actor_name
        FROM audit_log a
        LEFT JOIN users u ON a.performed_by = u.id
        ORDER BY a.performed_at DESC LIMIT 10
    ")->fetchAll();

    // If Dean, also load their review queue and pending approvals
    if ($currentRole === 'dean') {
        $myFaculty = $db->prepare("SELECT * FROM users WHERE assigned_dean_id = ? AND is_active=1 ORDER BY last_name");
        $myFaculty->execute([$currentUserId]);
        $myFacultyList = $myFaculty->fetchAll();

        $facultyIds = array_column($myFacultyList, 'id');
        $reviewForms = [];
        if ($facultyIds && $period) {
            $inList = implode(',', array_map('intval', $facultyIds));
            $reviewForms = $db->query("
                SELECT f.*, CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                       u.department, u.employee_id,
                       (SELECT COUNT(*) FROM ipcr_si_entries e2 WHERE e2.form_id=f.id AND e2.q_rating IS NOT NULL) as rated_count,
                       (SELECT COUNT(*) FROM success_indicators si2
                        JOIN kras k2 ON si2.kra_id=k2.id
                        JOIN mfos m2 ON k2.mfo_id=m2.id
                        WHERE m2.template_id=f.template_id AND si2.is_required=1) as total_si
                FROM ipcr_forms f
                JOIN users u ON f.user_id=u.id
                WHERE f.user_id IN ({$inList}) AND f.period_id = {$period['id']}
                  AND f.status IN ('submitted','under_review','reviewed')
                ORDER BY f.updated_at DESC LIMIT 5
            ")->fetchAll();
        }
        
        // Get pending approval requests
        $pendingApprovals = $db->prepare("
            SELECT er.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as requested_by_name
            FROM edit_requests er
            JOIN users u ON er.requested_by = u.id
            WHERE er.dean_id = ? AND er.status = 'pending'
            ORDER BY er.created_at DESC
            LIMIT 5
        ");
        $pendingApprovals->execute([$currentUserId]);
        $pendingApprovals = $pendingApprovals->fetchAll();
    }
    
    // If Secretary, load their pending requests
    if ($currentRole === 'secretary') {
        $myRequests = $db->prepare("
            SELECT er.*, 
                   CONCAT(d.first_name, ' ', d.last_name) as dean_name
            FROM edit_requests er
            JOIN users d ON er.dean_id = d.id
            WHERE er.requested_by = ?
            ORDER BY 
                FIELD(er.status, 'pending', 'approved', 'rejected'),
                er.created_at DESC
            LIMIT 5
        ");
        $myRequests->execute([$currentUserId]);
        $myRequests = $myRequests->fetchAll();
    }

} else {
    // Faculty
    $myForm = null;
    if ($period) {
        $mf = $db->prepare("SELECT * FROM ipcr_forms WHERE user_id=? AND period_id=?");
        $mf->execute([$currentUserId, $period['id']]);
        $myForm = $mf->fetch();
    }
    $myHistory = $db->prepare("
        SELECT f.*, p.name as period_name
        FROM ipcr_forms f JOIN rating_periods p ON f.period_id=p.id
        WHERE f.user_id=? ORDER BY f.created_at DESC
    ");
    $myHistory->execute([$currentUserId]);
    $myHistory = $myHistory->fetchAll();

    $dean = null;
    if ($currentUser['assigned_dean_id']) {
        $ds = $db->prepare("SELECT * FROM users WHERE id=?");
        $ds->execute([$currentUser['assigned_dean_id']]);
        $dean = $ds->fetch();
    }

    $deadlineDays = null;
    $hasExtension = false;
    $extensionDeadline = null;
    $extensionDaysRemaining = null;
    
    // Check for active extension
    if ($period) {
        $extStmt = $db->prepare("
            SELECT extended_deadline FROM submission_extensions 
            WHERE period_id = ? AND user_id = ? AND is_used = 0
            ORDER BY extended_deadline DESC LIMIT 1
        ");
        $extStmt->execute([$period['id'], $currentUserId]);
        $extension = $extStmt->fetch();
        
        if ($extension) {
            $hasExtension = true;
            $extensionDeadline = $extension['extended_deadline'];
            $extensionDaysRemaining = (int)ceil((strtotime($extensionDeadline) - time()) / 86400);
        }
    }
    
    if ($period && $period['submission_deadline']) {
        $deadlineDays = (int)ceil((strtotime($period['submission_deadline'].' 23:59:59') - time()) / 86400);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($isAdminLike): ?>
<!-- ── ADMIN DASHBOARD ── -->
<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-house-door me-2"></i>Administrator Dashboard</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Overview of system activity, user statistics, and recent submissions</p>
    <div class="text-muted small mt-1">
      <?= $period ? 'Active Period: <strong>'.h($period['name']).'</strong> — <span class="badge bg-success">'.h($period['status']).'</span>' : 'No active evaluation period.' ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>/admin/users.php" class="text-decoration-none">
      <div class="metric-card" style="cursor:pointer">
        <div class="metric-icon metric-icon-primary"><i class="bi bi-people-fill"></i></div>
        <div class="metric-value"><?= $totalUsers ?></div>
        <div class="metric-label">Active Users</div>
        <div class="metric-sub"><?= $totalFaculty ?> Faculty · <?= $totalDeans ?> Deans</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>/admin/ipcr-list.php?status=submitted" class="text-decoration-none">
      <div class="metric-card" style="cursor:pointer">
        <div class="metric-icon metric-icon-warning"><i class="bi bi-hourglass"></i></div>
        <div class="metric-value"><?= ($scMap['submitted']??0) + ($scMap['under_review']??0) ?></div>
        <div class="metric-label">Pending Evaluation</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>/admin/ipcr-list.php?status=reviewed" class="text-decoration-none">
      <div class="metric-card" style="cursor:pointer">
        <div class="metric-icon metric-icon-info"><i class="bi bi-check-circle"></i></div>
        <div class="metric-value"><?= $scMap['reviewed']??0 ?></div>
        <div class="metric-label">Awaiting Approval</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>/admin/ipcr-list.php?status=finalized" class="text-decoration-none">
      <div class="metric-card" style="cursor:pointer">
        <div class="metric-icon metric-icon-success"><i class="bi bi-lock-fill"></i></div>
        <div class="metric-value"><?= $scMap['finalized']??0 ?></div>
        <div class="metric-label">Finalized</div>
      </div>
    </a>
  </div>
</div>

<?php if ($period): ?>
<!-- Submission Tracking Card -->
<div class="card mb-4">
  <div class="card-header">
    <h6><i class="bi bi-calendar-check me-2"></i>Submission Tracking - <?= h($period['name']) ?></h6>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <a href="<?= BASE_URL ?>/admin/submission-tracking.php?filter=on_time" class="text-decoration-none">
          <div class="submission-stat-card clickable" style="border-left: 4px solid #16a34a; background: #f0fdf4;">
            <div class="submission-stat-icon" style="color: #16a34a;">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="submission-stat-value" style="color: #16a34a;"><?= $submissionStats['on_time'] ?></div>
            <div class="submission-stat-label">On-Time Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-4">
        <a href="<?= BASE_URL ?>/admin/submission-tracking.php?filter=late" class="text-decoration-none">
          <div class="submission-stat-card clickable" style="border-left: 4px solid #d97706; background: #fffbeb;">
            <div class="submission-stat-icon" style="color: #d97706;">
              <i class="bi bi-clock-history"></i>
            </div>
            <div class="submission-stat-value" style="color: #d97706;"><?= $submissionStats['late'] ?></div>
            <div class="submission-stat-label">Late Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-4">
        <a href="<?= BASE_URL ?>/admin/submission-tracking.php?filter=not_submitted" class="text-decoration-none">
          <div class="submission-stat-card clickable" style="border-left: 4px solid #dc2626; background: #fef2f2;">
            <div class="submission-stat-icon" style="color: #dc2626;">
              <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="submission-stat-value" style="color: #dc2626;"><?= $submissionStats['not_submitted'] ?></div>
            <div class="submission-stat-label">Not Submitted</div>
          </div>
        </a>
      </div>
    </div>
    <?php if ($period['submission_deadline']): ?>
    <div class="mt-3 text-center text-muted small">
      <i class="bi bi-info-circle me-1"></i>
      Submission Deadline: <strong><?= h(date('F d, Y', strtotime($period['submission_deadline']))) ?></strong>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php
  $statusMeta = [
    'draft'        => ['label'=>'Draft',        'icon'=>'bi-file-earmark',        'bg'=>'#f1f5f9','color'=>'#475569','border'=>'#cbd5e1'],
    'submitted'    => ['label'=>'Submitted',    'icon'=>'bi-send-fill',           'bg'=>'#eff6ff','color'=>'#2563eb','border'=>'#93c5fd', 'use_total'=>true, 'link_status'=>'all_submitted'],
    'under_review' => ['label'=>'Under Review', 'icon'=>'bi-eye-fill',            'bg'=>'#ecfeff','color'=>'#0891b2','border'=>'#67e8f9'],
    'reviewed'     => ['label'=>'Reviewed',     'icon'=>'bi-check-circle-fill',   'bg'=>'#fffbeb','color'=>'#d97706','border'=>'#fcd34d'],
    'returned'     => ['label'=>'Returned',     'icon'=>'bi-arrow-return-left',   'bg'=>'#fff1f2','color'=>'#e11d48','border'=>'#fda4af'],
    'approved'     => ['label'=>'Approved',     'icon'=>'bi-patch-check-fill',    'bg'=>'#f0fdf4','color'=>'#16a34a','border'=>'#86efac'],
    'finalized'    => ['label'=>'Finalized',    'icon'=>'bi-lock-fill',           'bg'=>'#f8fafc','color'=>'#1e293b','border'=>'#94a3b8'],
  ];
  foreach($statusMeta as $s => $m):
    $count = ($m['use_total'] ?? false) ? ($scMap['total_submitted'] ?? 0) : ($scMap[$s] ?? 0);
    $linkStatus = $m['link_status'] ?? $s; // Use custom link status if provided
  ?>
  <div class="col">
    <a href="<?= BASE_URL ?>/admin/ipcr-list.php?status=<?= $linkStatus ?>" class="text-decoration-none">
      <div class="status-count-card" style="border-color:<?= $m['border'] ?>;background:<?= $m['bg'] ?>;cursor:pointer">
        <div class="status-count-icon" style="color:<?= $m['color'] ?>">
          <i class="bi <?= $m['icon'] ?>"></i>
        </div>
        <div class="status-count-num" style="color:<?= $m['color'] ?>"><?= $count ?></div>
        <div class="status-count-label"><?= $m['label'] ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <h6><i class="bi bi-journal-text me-2"></i>Recent Activity</h6>
        <a href="<?= BASE_URL ?>/admin/audit.php" class="btn btn-sm btn-outline-secondary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="ipcr-table">
          <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Remarks</th></tr></thead>
          <tbody>
            <?php foreach($recentAudit as $e): ?>
            <tr>
              <td class="text-muted-sm"><?= h(date('M d, Y H:i', strtotime($e['performed_at']))) ?></td>
              <td><strong><?= h($e['actor_name'] ?? 'System') ?></strong></td>
              <td><code><?= h($e['action']) ?></code></td>
              <td><?= h(mb_substr($e['remarks']??'',0,60)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$recentAudit): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No activity yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><h6><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h6></div>
      <div class="card-body p-2">
        <div class="row g-2">
          <div class="col-6">
            <a href="<?= BASE_URL ?>/admin/users.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#e8f5e9;color:#1a5c2a"><i class="bi bi-person-plus-fill"></i></div>
              <div class="qat-label">Add User</div>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= BASE_URL ?>/admin/periods.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#e3f2fd;color:#0369a1"><i class="bi bi-calendar3-fill"></i></div>
              <div class="qat-label">Periods</div>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= BASE_URL ?>/admin/templates.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#f3e8ff;color:#7c3aed"><i class="bi bi-layout-text-window-fill"></i></div>
              <div class="qat-label">Templates</div>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= BASE_URL ?>/admin/ipcr-list.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#fff8e1;color:#d97706"><i class="bi bi-list-task"></i></div>
              <div class="qat-label">IPCR Forms</div>
            </a>
          </div>
          <div class="col-6">
            <a href="<?= BASE_URL ?>/admin/reports.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#e8f5e9;color:#16a34a"><i class="bi bi-bar-chart-fill"></i></div>
              <div class="qat-label">Reports</div>
            </a>
          </div>
          <div class="col-6">
            <?php if($currentRole === 'admin'): ?>
            <a href="<?= BASE_URL ?>/admin/settings.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#fce4ec;color:#dc2626"><i class="bi bi-gear-fill"></i></div>
              <div class="qat-label">Settings</div>
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/dean/review-queue.php" class="quick-action-tile">
              <div class="qat-icon" style="background:#fff3e0;color:#e65100"><i class="bi bi-card-checklist"></i></div>
              <div class="qat-label">Review Queue</div>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if($currentRole === 'dean' && !empty($reviewForms)): ?>
<!-- Dean Review Queue widget -->
<div class="card mt-4">
  <div class="card-header">
    <h6><i class="bi bi-card-checklist me-2"></i>My Review Queue</h6>
    <a href="<?= BASE_URL ?>/dean/review-queue.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="ipcr-table">
      <thead><tr><th>Faculty</th><th>Department</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($reviewForms as $f): ?>
        <tr>
          <td>
            <strong><?= h($f['faculty_name']) ?></strong>
            <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
          </td>
          <td><?= h($f['department']) ?></td>
          <td><?= statusBadge($f['status']) ?></td>
          <td>
            <?php if($f['status']==='submitted'): ?>
              <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-primary">Start Review</a>
            <?php elseif($f['status']==='under_review'): ?>
              <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-warning">Continue</a>
            <?php else: ?>
              <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if($currentRole === 'dean' && !empty($pendingApprovals)): ?>
<!-- Dean Pending Approvals widget -->
<div class="card mt-4">
  <div class="card-header bg-warning text-dark">
    <h6><i class="bi bi-exclamation-circle me-2"></i>Pending Approvals (<?= count($pendingApprovals) ?>)</h6>
    <a href="<?= BASE_URL ?>/dean/approvals.php" class="btn btn-sm btn-dark">View All</a>
  </div>
  <div class="table-responsive">
    <table class="ipcr-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Requested By</th>
          <th>Remarks</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($pendingApprovals as $req): ?>
        <tr>
          <td>
            <?php
            $badges = [
                'user' => '<span class="badge bg-primary">User Edit</span>',
                'ipcr' => '<span class="badge bg-success">IPCR</span>',
                'department' => '<span class="badge bg-info">Department</span>'
            ];
            echo $badges[$req['request_type']] ?? '<span class="badge bg-secondary">'.h($req['request_type']).'</span>';
            ?>
          </td>
          <td><strong><?= h($req['requested_by_name']) ?></strong></td>
          <td><?= h(mb_substr($req['remarks'], 0, 40)) ?><?= mb_strlen($req['remarks']) > 40 ? '...' : '' ?></td>
          <td class="text-muted-sm"><?= h(date('M d, Y', strtotime($req['created_at']))) ?></td>
          <td>
            <a href="<?= BASE_URL ?>/dean/approvals.php" class="btn btn-sm btn-warning">
              <i class="bi bi-eye"></i> Review
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if($currentRole === 'secretary' && !empty($myRequests)): ?>
<!-- Secretary Requests widget -->
<div class="card mt-4">
  <div class="card-header">
    <h6><i class="bi bi-clock-history me-2"></i>My Recent Edit Requests</h6>
    <a href="<?= BASE_URL ?>/secretary/requests.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="ipcr-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Status</th>
          <th>Dean</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($myRequests as $req): ?>
        <tr>
          <td>
            <?php
            $badges = [
                'user' => '<span class="badge bg-primary">User Edit</span>',
                'ipcr' => '<span class="badge bg-success">IPCR</span>',
                'department' => '<span class="badge bg-info">Department</span>'
            ];
            echo $badges[$req['request_type']] ?? '<span class="badge bg-secondary">'.h($req['request_type']).'</span>';
            ?>
          </td>
          <td>
            <?php if($req['status'] === 'pending'): ?>
              <span class="badge bg-warning text-dark">Pending</span>
            <?php elseif($req['status'] === 'approved'): ?>
              <span class="badge bg-success">Approved</span>
            <?php else: ?>
              <span class="badge bg-danger">Rejected</span>
            <?php endif; ?>
          </td>
          <td><?= h($req['dean_name']) ?></td>
          <td class="text-muted-sm"><?= h(date('M d, Y', strtotime($req['created_at']))) ?></td>
          <td>
            <a href="<?= BASE_URL ?>/secretary/requests.php" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-eye"></i> View
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ── FACULTY DASHBOARD ── -->
<div class="page-header">
  <div class="page-header-left">
    <h1>Good <?= date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening') ?>, <?= h($currentUser['first_name']) ?>!<?php if($currentUser['department']): ?> <span style="font-size: 0.75em; color: #6c757d; font-weight: 400;">(<?= h($currentUser['department']) ?>)</span><?php endif; ?></h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Welcome to your IPCR dashboard - manage your forms and track your evaluations</p>
    <p class="text-muted small mt-1"><?= $period ? 'Current Period: <strong>'.h($period['name']).'</strong>' : 'No active period.' ?></p>
  </div>
</div>

<?php if($deadlineDays !== null && $period && $period['status']==='open'): ?>
<div class="alert-deadline mb-4">
  <i class="bi bi-clock me-2"></i>
  <strong>Submission Deadline:</strong> <?= h(date('F d, Y', strtotime($period['submission_deadline']))) ?>
  — <?= $deadlineDays > 0 ? "<strong>{$deadlineDays} day(s) remaining</strong>" : '<strong class="text-danger">DEADLINE PASSED</strong>' ?>
</div>
<?php endif; ?>

<?php if($hasExtension && $extensionDeadline): ?>
<div class="alert alert-success mb-4" style="border-left: 4px solid #16a34a;">
  <div class="d-flex align-items-center">
    <i class="bi bi-calendar-check-fill me-3" style="font-size: 2rem; color: #16a34a;"></i>
    <div>
      <h6 class="mb-1" style="color: #1a5c2a;">✅ Extension Granted!</h6>
      <p class="mb-1">
        You have been granted a special extension to submit your IPCR.
      </p>
      <p class="mb-0">
        <strong style="color: #16a34a; font-size: 1.1rem;">
          New Deadline: <?= date('F d, Y g:i A', strtotime($extensionDeadline)) ?>
        </strong>
        <?php if($extensionDaysRemaining > 0): ?>
          — <span class="badge bg-success"><?= $extensionDaysRemaining ?> day(s) remaining</span>
        <?php else: ?>
          — <span class="badge bg-danger">⚠️ Extension expired</span>
        <?php endif; ?>
      </p>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h6><i class="bi bi-file-earmark-person me-2"></i>Current IPCR Status</h6></div>
      <div class="card-body">
        <?php if(!$period): ?>
          <div class="alert alert-secondary mb-0"><i class="bi bi-calendar-x me-2"></i>No active evaluation period.</div>
        <?php elseif(!$myForm): ?>
          <div class="text-center py-3">
            <i class="bi bi-file-earmark-plus" style="font-size:3rem;color:var(--primary-light)"></i>
            <h6 class="mt-2 mb-1">No IPCR Form Yet</h6>
            <p class="text-muted small mb-3">Create your IPCR for <strong><?= h($period['name']) ?></strong></p>
            <?= $period['status']==='open'
              ? '<a href="'.BASE_URL.'/faculty/ipcr-form.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>Create My IPCR</a>'
              : '<div class="alert alert-warning">Submission period is closed.</div>' ?>
          </div>
        <?php else: ?>
          <div class="mb-3"><?= statusBadge($myForm['status']) ?></div>
          <?php if($myForm['status']==='returned' && $myForm['return_remarks']): ?>
          <div class="alert-return mb-3">
            <strong><i class="bi bi-arrow-return-left me-2"></i>Return Remarks:</strong>
            <p class="mb-0 mt-1"><?= h($myForm['return_remarks']) ?></p>
          </div>
          <?php endif; ?>,
          <?php if($myForm['status']==='finalized'): ?>
          <div class="mb-2">
            <div class="fs-4 fw-bold text-primary"><?= h($myForm['composite_rating']) ?></div>
            <div><?= h($myForm['adjectival_rating']) ?></div>
          </div>
          <?php endif; ?>
          <?php
          $actionMap = [
            'draft'        => '<a href="'.BASE_URL.'/faculty/ipcr-form.php" class="btn btn-warning"><i class="bi bi-pencil me-2"></i>Continue Editing</a>',
            'submitted'    => '<a href="'.BASE_URL.'/faculty/ipcr-view.php?id='.$myForm['id'].'" class="btn btn-outline-primary"><i class="bi bi-eye me-2"></i>View Submitted IPCR</a>',
            'under_review' => '<a href="'.BASE_URL.'/faculty/ipcr-view.php?id='.$myForm['id'].'" class="btn btn-outline-info"><i class="bi bi-eye me-2"></i>Under Dean\'s Review</a>',
            'reviewed'     => '<a href="'.BASE_URL.'/faculty/ipcr-view.php?id='.$myForm['id'].'" class="btn btn-outline-success"><i class="bi bi-eye me-2"></i>View Ratings</a>',
            'returned'     => '<a href="'.BASE_URL.'/faculty/ipcr-form.php" class="btn btn-danger"><i class="bi bi-arrow-return-left me-2"></i>Revise &amp; Resubmit</a>',
            'approved'     => '<a href="'.BASE_URL.'/faculty/ipcr-view.php?id='.$myForm['id'].'" class="btn btn-outline-success"><i class="bi bi-check2-all me-2"></i>View Approved IPCR</a>',
            'finalized'    => '<a href="'.BASE_URL.'/faculty/ipcr-view.php?id='.$myForm['id'].'" class="btn btn-dark"><i class="bi bi-lock me-2"></i>View Final Rating</a>',
          ];
          echo $actionMap[$myForm['status']] ?? '';
          ?>
        <?php endif; ?>
      </div>
      <?php if($dean): ?>
      <div class="card-footer">
        <span class="text-muted-sm"><i class="bi bi-person-check me-1"></i>Evaluator: <strong><?= h(fullName($dean)) ?></strong></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h6><i class="bi bi-clock-history me-2"></i>Evaluation History</h6></div>
      <div class="table-responsive">
        <table class="ipcr-table">
          <thead><tr><th>Period</th><th>Status</th><th>Rating</th><th>Adjectival</th><th></th></tr></thead>
          <tbody>
            <?php foreach($myHistory??[] as $hf): ?>
            <tr>
              <td><?= h($hf['period_name']) ?></td>
              <td><?= statusBadge($hf['status']) ?></td>
              <td><?= $hf['composite_rating'] ? h(number_format($hf['composite_rating'], 2)) : '—' ?></td>
              <td><?= h($hf['adjectival_rating']??'—') ?></td>
              <td><a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $hf['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($myHistory)): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">No IPCR history yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

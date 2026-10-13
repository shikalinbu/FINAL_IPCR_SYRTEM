<?php
$pageTitle  = 'Submission Tracking';
$activePage = 'submission-tracking';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole('admin','secretary','dean');

$db = getDB();

// Pagination settings
$recordsPerPage = 20;
$currentPage = (int)($_GET['page'] ?? 1);

// Get all periods
$periods = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();

// Default to active period
$filterPeriod = (int)($_GET['period'] ?? ($db->query("SELECT id FROM rating_periods WHERE status IN ('open','rating') LIMIT 1")->fetchColumn() ?: 0));
$viewType = $_GET['view'] ?? $_GET['filter'] ?? 'all'; // Support both 'view' and 'filter' params
// Normalize view type (handle both underscore and hyphen)
$viewType = str_replace('_', '-', $viewType); // Convert on_time to on-time, not_submitted to not-submitted

// Get period details
$activePeriod = null;
if ($filterPeriod) {
    $stmt = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
    $stmt->execute([$filterPeriod]);
    $activePeriod = $stmt->fetch();
}

// For deans: restrict to their faculty
$facultyFilter = '';
$whereFilter = '';
if ($currentRole === 'dean') {
    $fac = $db->prepare("SELECT id FROM users WHERE assigned_dean_id=? AND is_active=1");
    $fac->execute([$currentUserId]);
    $facIds = array_column($fac->fetchAll(), 'id');
    if ($facIds) {
        $idList = implode(',', array_map('intval', $facIds));
        $facultyFilter = " AND u.id IN ({$idList})";
        $whereFilter = " AND f.user_id IN ({$idList})";
    } else {
        $facultyFilter = " AND 1=0";
        $whereFilter = " AND 1=0";
    }
}

// Initialize arrays
$onTimeSubmissions = [];
$lateSubmissions = [];
$notSubmittedFaculty = [];
$allSubmissions = [];
$pagination = null;

if ($filterPeriod) {
    // Count statistics BEFORE pagination
    $onTimeCount = (int)$db->query("
        SELECT COUNT(*) FROM ipcr_forms f 
        WHERE f.period_id={$filterPeriod} 
        AND f.is_late=0 
        AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized') 
        {$whereFilter}
    ")->fetchColumn();
    
    $lateCount = (int)$db->query("
        SELECT COUNT(*) FROM ipcr_forms f 
        WHERE f.period_id={$filterPeriod} 
        AND f.is_late=1 
        AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized') 
        {$whereFilter}
    ")->fetchColumn();
    
    // Count not submitted faculty
    $submittedUserIds = $db->query("
        SELECT DISTINCT user_id FROM ipcr_forms 
        WHERE period_id={$filterPeriod} 
        AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    $whereNotSubmittedCount = "u.role='faculty' AND u.is_active=1 {$facultyFilter}";
    if (!empty($submittedUserIds)) {
        $idList = implode(',', array_map('intval', $submittedUserIds));
        $whereNotSubmittedCount .= " AND u.id NOT IN ({$idList})";
    }
    
    $notSubmittedCount = (int)$db->query("
        SELECT COUNT(*) FROM users u WHERE {$whereNotSubmittedCount}
    ")->fetchColumn();
    
    // Count total records for pagination (based on view type)
    $totalRecords = 0;
    $countQuery = "";
    
    if ($viewType === 'on-time') {
        $countQuery = "SELECT COUNT(*) FROM ipcr_forms f WHERE f.period_id={$filterPeriod} AND f.is_late=0 AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized') {$whereFilter}";
    } elseif ($viewType === 'late') {
        $countQuery = "SELECT COUNT(*) FROM ipcr_forms f WHERE f.period_id={$filterPeriod} AND f.is_late=1 AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized') {$whereFilter}";
    } elseif ($viewType === 'not-submitted') {
        $submittedUserIds = $db->query("SELECT DISTINCT user_id FROM ipcr_forms WHERE period_id={$filterPeriod} AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')")->fetchAll(PDO::FETCH_COLUMN);
        $whereNotSubmitted = "u.role='faculty' AND u.is_active=1 {$facultyFilter}";
        if (!empty($submittedUserIds)) {
            $idList = implode(',', array_map('intval', $submittedUserIds));
            $whereNotSubmitted .= " AND u.id NOT IN ({$idList})";
        }
        $countQuery = "SELECT COUNT(*) FROM users u WHERE {$whereNotSubmitted}";
    } else {
        $countQuery = "SELECT COUNT(*) FROM ipcr_forms f WHERE f.period_id={$filterPeriod} AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized') {$whereFilter}";
    }
    
    $totalRecords = (int)$db->query($countQuery)->fetchColumn();
    $pagination = calculatePagination($totalRecords, $recordsPerPage, $currentPage);
    
    // On-time submissions (submitted AND not late) - with pagination based on view
    if ($viewType === 'on-time') {
        $onTimeSubmissions = $db->query("
            SELECT f.*, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   p.name as period_name, p.submission_deadline,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM ipcr_forms f
            JOIN users u ON f.user_id=u.id
            JOIN rating_periods p ON f.period_id=p.id
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE f.period_id={$filterPeriod} 
            AND f.is_late=0 
            AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
            {$whereFilter}
            ORDER BY f.submitted_at ASC
            LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
        ")->fetchAll();
    } else {
        // For summary cards - fetch limited data
        $onTimeSubmissions = $db->query("
            SELECT f.*, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   p.name as period_name, p.submission_deadline,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM ipcr_forms f
            JOIN users u ON f.user_id=u.id
            JOIN rating_periods p ON f.period_id=p.id
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE f.period_id={$filterPeriod} 
            AND f.is_late=0 
            AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
            {$whereFilter}
            ORDER BY f.submitted_at ASC
        ")->fetchAll();
    }
    
    // Late submissions - with pagination based on view
    if ($viewType === 'late') {
        $lateSubmissions = $db->query("
            SELECT f.*, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   p.name as period_name, p.submission_deadline,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM ipcr_forms f
            JOIN users u ON f.user_id=u.id
            JOIN rating_periods p ON f.period_id=p.id
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE f.period_id={$filterPeriod} 
            AND f.is_late=1
            AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
            {$whereFilter}
            ORDER BY f.submitted_at ASC
            LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
        ")->fetchAll();
    } else {
        // For summary cards - fetch limited data
        $lateSubmissions = $db->query("
            SELECT f.*, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   p.name as period_name, p.submission_deadline,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM ipcr_forms f
            JOIN users u ON f.user_id=u.id
            JOIN rating_periods p ON f.period_id=p.id
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE f.period_id={$filterPeriod} 
            AND f.is_late=1
            AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
            {$whereFilter}
            ORDER BY f.submitted_at ASC
        ")->fetchAll();
    }
    
    // All submissions (for 'all' view) - with pagination
    $allSubmissions = $db->query("
        SELECT f.*, 
               CONCAT(u.first_name,' ',u.last_name) as faculty_name,
               u.employee_id, u.department, u.position,
               p.name as period_name, p.submission_deadline,
               CONCAT(d.first_name,' ',d.last_name) as dean_name,
               se.extended_deadline, se.is_used as extension_used
        FROM ipcr_forms f
        JOIN users u ON f.user_id=u.id
        JOIN rating_periods p ON f.period_id=p.id
        LEFT JOIN users d ON u.assigned_dean_id=d.id
        LEFT JOIN submission_extensions se ON se.period_id=f.period_id AND se.user_id=f.user_id
        WHERE f.period_id={$filterPeriod} 
        AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        {$whereFilter}
        ORDER BY f.is_late ASC, f.submitted_at ASC
        LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
    ")->fetchAll();
    
    // Not submitted faculty (including those with only draft or returned forms) - with pagination based on view
    $submittedUserIds = $db->query("
        SELECT DISTINCT user_id FROM ipcr_forms 
        WHERE period_id={$filterPeriod} 
        AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    $whereNotSubmitted = "u.role='faculty' AND u.is_active=1 {$facultyFilter}";
    if (!empty($submittedUserIds)) {
        $idList = implode(',', array_map('intval', $submittedUserIds));
        $whereNotSubmitted .= " AND u.id NOT IN ({$idList})";
    }
    
    if ($viewType === 'not-submitted') {
        // Paginated query when viewing Not Submitted
        $notSubmittedFaculty = $db->query("
            SELECT u.id, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM users u
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE {$whereNotSubmitted}
            ORDER BY u.last_name, u.first_name
            LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
        ")->fetchAll();
    } else {
        // For summary cards - fetch limited or all data
        $notSubmittedFaculty = $db->query("
            SELECT u.id, 
                   CONCAT(u.first_name,' ',u.last_name) as faculty_name,
                   u.employee_id, u.department, u.position,
                   CONCAT(d.first_name,' ',d.last_name) as dean_name
            FROM users u
            LEFT JOIN users d ON u.assigned_dean_id=d.id
            WHERE {$whereNotSubmitted}
            ORDER BY u.last_name, u.first_name
        ")->fetchAll();
    }
}

// Calculate status breakdown counts
$statusCounts = [
    'draft' => 0,
    'submitted' => 0,
    'under_review' => 0,
    'reviewed' => 0,
    'returned' => 0,
    'approved' => 0,
    'finalized' => 0
];

if ($filterPeriod) {
    $allFormsQuery = $db->query("
        SELECT f.status, COUNT(*) as count
        FROM ipcr_forms f
        JOIN users u ON f.user_id = u.id
        WHERE f.period_id={$filterPeriod}
        {$whereFilter}
        GROUP BY f.status
    ");
    
    foreach ($allFormsQuery->fetchAll() as $row) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }
    }
}

$submissionStats = [
    'on_time' => $onTimeCount ?? 0,
    'late' => $lateCount ?? 0,
    'not_submitted' => $notSubmittedCount ?? 0,
    'total' => ($onTimeCount ?? 0) + ($lateCount ?? 0),
    'total_submitted' => $statusCounts['submitted'] + $statusCounts['under_review'] + $statusCounts['reviewed'] + $statusCounts['approved'] + $statusCounts['finalized']
];
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-clipboard-check me-2"></i>Submission Tracking</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Monitor IPCR submission status and send reminders to faculty who haven't submitted</p>
    <div class="breadcrumb-bar">
      <a href="<?= BASE_URL ?>/dashboard.php">Home</a> › Submission Tracking
    </div>
  </div>
  <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn btn-outline-primary no-print">
      <i class="bi bi-printer me-2"></i>Print Report
    </button>
  </div>
</div>

<!-- Period Filter -->
<div class="filter-bar mb-4 no-print">
  <form method="GET" class="d-flex gap-3 align-items-end flex-wrap">
    <div>
      <label class="form-label small">Select Period</label>
      <select name="period" class="form-select" style="min-width:280px" onchange="this.form.submit()">
        <option value="">-- Select Period --</option>
        <?php foreach($periods as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $filterPeriod==$p['id']?'selected':'' ?>>
          <?= h($p['name']) ?> 
          <?php if($p['status'] === 'open'): ?>
            <span class="badge bg-success">OPEN</span>
          <?php elseif($p['status'] === 'rating'): ?>
            <span class="badge bg-warning">RATING</span>
          <?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small">View</label>
      <select name="view" class="form-select" style="min-width:180px" onchange="this.form.submit()">
        <option value="all" <?= $viewType==='all'?'selected':'' ?>>All Submissions</option>
        <option value="on-time" <?= $viewType==='on-time'?'selected':'' ?>>On-Time Only</option>
        <option value="late" <?= $viewType==='late'?'selected':'' ?>>Late Only</option>
        <option value="not-submitted" <?= $viewType==='not-submitted'?'selected':'' ?>>Not Submitted</option>
      </select>
    </div>
  </form>
</div>

<?php if (!$filterPeriod): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Please select a rating period to view submission tracking.
</div>
<?php else: ?>

<!-- Submission Stats Cards -->
<div class="card mb-4">
  <div class="card-header">
    <h6 style="color: inherit !important;"><i class="bi bi-bar-chart-fill me-2"></i>Submission Summary - <?= h($activePeriod['name']) ?></h6>
    <?php if ($activePeriod['submission_deadline']): ?>
    <div class="text-muted small mt-1">
      <i class="bi bi-calendar-event me-1"></i>
      Deadline: <strong><?= h(date('F d, Y', strtotime($activePeriod['submission_deadline']))) ?></strong>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <a href="?period=<?= $filterPeriod ?>&view=on-time" class="text-decoration-none">
          <div class="submission-stat-card" style="border-left: 4px solid #16a34a; background: #f0fdf4; cursor: pointer;">
            <div class="submission-stat-icon" style="color: #16a34a;">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="submission-stat-value" style="color: #16a34a;"><?= $submissionStats['on_time'] ?></div>
            <div class="submission-stat-label">On-Time Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-3">
        <a href="?period=<?= $filterPeriod ?>&view=late" class="text-decoration-none">
          <div class="submission-stat-card" style="border-left: 4px solid #d97706; background: #fffbeb; cursor: pointer;">
            <div class="submission-stat-icon" style="color: #d97706;">
              <i class="bi bi-clock-history"></i>
            </div>
            <div class="submission-stat-value" style="color: #d97706;"><?= $submissionStats['late'] ?></div>
            <div class="submission-stat-label">Late Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-3">
        <a href="?period=<?= $filterPeriod ?>&view=not-submitted" class="text-decoration-none">
          <div class="submission-stat-card" style="border-left: 4px solid #dc2626; background: #fef2f2; cursor: pointer;">
            <div class="submission-stat-icon" style="color: #dc2626;">
              <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="submission-stat-value" style="color: #dc2626;"><?= $submissionStats['not_submitted'] ?></div>
            <div class="submission-stat-label">Not Submitted</div>
          </div>
        </a>
      </div>
    </div>

    <hr class="my-3">
    
    <h6 class="mb-3" style="color: inherit !important;"><i class="bi bi-diagram-3 me-2"></i>Status Breakdown</h6>
    <div class="row g-2">
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #6b7280; background: #f9fafb; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark" style="color: #6b7280; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #6b7280; font-size: 1.25rem;"><?= $statusCounts['draft'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">DRAFT</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #2563eb; background: #eff6ff; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-send-fill" style="color: #2563eb; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #2563eb; font-size: 1.25rem;"><?= $submissionStats['total_submitted'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">SUBMITTED</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #0891b2; background: #ecfeff; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-eye-fill" style="color: #0891b2; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #0891b2; font-size: 1.25rem;"><?= $statusCounts['under_review'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">UNDER REVIEW</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #d97706; background: #fffbeb; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check2-circle" style="color: #d97706; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #d97706; font-size: 1.25rem;"><?= $statusCounts['reviewed'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">REVIEWED</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #dc2626; background: #fef2f2; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-arrow-return-left" style="color: #dc2626; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #dc2626; font-size: 1.25rem;"><?= $statusCounts['returned'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">RETURNED</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #059669; background: #f0fdf4; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-patch-check-fill" style="color: #059669; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #059669; font-size: 1.25rem;"><?= $statusCounts['approved'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">APPROVED</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg">
        <div class="submission-stat-card" style="border-left: 4px solid #1b5e35; background: #f0fdf4; padding: 0.75rem;">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-lock-fill" style="color: #1b5e35; font-size: 1.5rem;"></i>
            <div>
              <div class="submission-stat-value" style="color: #1b5e35; font-size: 1.25rem;"><?= $statusCounts['finalized'] ?></div>
              <div class="submission-stat-label" style="font-size: 0.75rem;">FINALIZED</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Data Table -->
<?php if ($viewType === 'not-submitted'): ?>
  <!-- NOT SUBMITTED LIST -->
  <div class="card">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
      <h6 class="mb-0" style="color: white !important;">
        <i class="bi bi-x-circle-fill me-2"></i>
        Faculty Who Have NOT Submitted (<?= $notSubmittedCount ?? 0 ?>)
      </h6>
      <?php if (count($notSubmittedFaculty) > 0): ?>
      <button onclick="notifyNotSubmitted()" class="btn btn-light btn-sm no-print">
        <i class="bi bi-envelope-fill me-1"></i>
        Send Email Reminders
      </button>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Faculty Name</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Position</th>
            <th>Assigned Dean</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($notSubmittedFaculty)): ?>
          <tr>
            <td colspan="6" class="text-center text-success py-4">
              <i class="bi bi-check-circle fs-3 d-block mb-2"></i>
              <strong>All faculty have submitted their IPCRs!</strong>
            </td>
          </tr>
          <?php else: ?>
            <?php foreach($notSubmittedFaculty as $i => $faculty): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= h($faculty['faculty_name']) ?></strong></td>
              <td><?= h($faculty['employee_id']) ?></td>
              <td><?= h($faculty['department']) ?></td>
              <td><?= h($faculty['position']) ?></td>
              <td><?= h($faculty['dean_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <?php if ($pagination && $pagination['total_pages'] > 1): ?>
      <?= renderPagination($pagination) ?>
    <?php endif; ?>
    
  </div>

<?php elseif ($viewType === 'late'): ?>
  <!-- LATE SUBMISSIONS LIST -->
  <div class="card">
    <div class="card-header bg-warning text-dark">
      <h6 class="mb-0" style="color: #1f2937 !important;">
        <i class="bi bi-clock-history me-2"></i>
        Late Submissions (<?= $lateCount ?? 0 ?>)
      </h6>
    </div>
    <div class="table-responsive">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Faculty Name</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Submitted At</th>
            <th>Status</th>
            <th>Assigned Dean</th>
            <th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($lateSubmissions)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No late submissions for this period.</td>
          </tr>
          <?php else: ?>
            <?php foreach($lateSubmissions as $i => $form): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <strong><?= h($form['faculty_name']) ?></strong>
                <span class="late-badge">LATE</span>
              </td>
              <td><?= h($form['employee_id']) ?></td>
              <td><?= h($form['department']) ?></td>
              <td class="text-muted-sm">
                <?= h(date('M d, Y', strtotime($form['submitted_at']))) ?>
                <div class="text-danger small">
                  <?php
                  $deadline = strtotime($form['submission_deadline']);
                  $submitted = strtotime($form['submitted_at']);
                  $daysLate = ceil(($submitted - $deadline) / 86400);
                  echo "({$daysLate} day" . ($daysLate > 1 ? 's' : '') . " late)";
                  ?>
                </div>
              </td>
              <td><?= statusBadge($form['status']) ?></td>
              <td><?= h($form['dean_name'] ?? '—') ?></td>
              <td class="no-print">
                <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $form['id'] ?>" 
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <?php if ($pagination && $pagination['total_pages'] > 1): ?>
      <?= renderPagination($pagination) ?>
    <?php endif; ?>
    
  </div>

<?php elseif ($viewType === 'on-time'): ?>
  <!-- ON-TIME SUBMISSIONS LIST -->
  <div class="card">
    <div class="card-header bg-success text-white">
      <h6 class="mb-0" style="color: white !important;">
        <i class="bi bi-check-circle-fill me-2"></i>
        On-Time Submissions (<?= $onTimeCount ?? 0 ?>)
      </h6>
    </div>
    <div class="table-responsive">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Faculty Name</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Submitted At</th>
            <th>Status</th>
            <th>Assigned Dean</th>
            <th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($onTimeSubmissions)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No on-time submissions for this period.</td>
          </tr>
          <?php else: ?>
            <?php foreach($onTimeSubmissions as $i => $form): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= h($form['faculty_name']) ?></strong></td>
              <td><?= h($form['employee_id']) ?></td>
              <td><?= h($form['department']) ?></td>
              <td class="text-muted-sm"><?= h(date('M d, Y h:i A', strtotime($form['submitted_at']))) ?></td>
              <td><?= statusBadge($form['status']) ?></td>
              <td><?= h($form['dean_name'] ?? '—') ?></td>
              <td class="no-print">
                <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $form['id'] ?>" 
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <?php if ($pagination && $pagination['total_pages'] > 1): ?>
      <?= renderPagination($pagination) ?>
    <?php endif; ?>
    
  </div>

<?php else: ?>
  <!-- ALL SUBMISSIONS LIST -->
  <div class="card">
    <div class="card-header bg-primary text-white">
      <h6 class="mb-0" style="color: white !important;">
        <i class="bi bi-list-check me-2"></i>
        All Submissions (<?= ($onTimeCount ?? 0) + ($lateCount ?? 0) ?>)
      </h6>
    </div>
    <div class="table-responsive">
      <table class="ipcr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Faculty Name</th>
            <th>Employee ID</th>
            <th>Department</th>
            <th>Submitted At</th>
            <th>Status</th>
            <th>Assigned Dean</th>
            <th class="no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($allSubmissions)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No submissions for this period.</td>
          </tr>
          <?php else: ?>
            <?php foreach($allSubmissions as $i => $form): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <strong><?= h($form['faculty_name']) ?></strong>
                <?php if($form['is_late']): ?>
                  <span class="late-badge">LATE</span>
                <?php endif; ?>
                <?php if($form['has_extension'] && $form['extension_used']): ?>
                  <span class="badge bg-success" style="font-size:.65rem" title="Submitted using extension">
                    <i class="bi bi-calendar-plus"></i> Extension
                  </span>
                <?php endif; ?>
              </td>
              <td><?= h($form['employee_id']) ?></td>
              <td><?= h($form['department']) ?></td>
              <td class="text-muted-sm">
                <?= h(date('M d, Y h:i A', strtotime($form['submitted_at']))) ?>
                <?php if($form['is_late']): ?>
                  <div class="text-danger small">
                    <?php
                    $deadline = strtotime($form['submission_deadline']);
                    $submitted = strtotime($form['submitted_at']);
                    $daysLate = ceil(($submitted - $deadline) / 86400);
                    echo "({$daysLate} day" . ($daysLate > 1 ? 's' : '') . " late)";
                    ?>
                  </div>
                <?php endif; ?>
                <?php if($form['has_extension'] && $form['extended_deadline']): ?>
                  <div class="text-success small">
                    <i class="bi bi-calendar-check"></i> Extension: <?= date('M d, Y', strtotime($form['extended_deadline'])) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= statusBadge($form['status']) ?></td>
              <td><?= h($form['dean_name'] ?? '—') ?></td>
              <td class="no-print">
                <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $form['id'] ?>" 
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <?php if ($pagination && $pagination['total_pages'] > 1): ?>
      <?= renderPagination($pagination) ?>
    <?php endif; ?>
    
  </div>
<?php endif; ?>

<?php endif; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const currentPeriodId = <?= $filterPeriod ?: 0 ?>;

function notifyNotSubmitted() {
    if (!currentPeriodId) {
        Toast.warning('Please select a period first.');
        return;
    }
    
    const notSubmittedCount = <?= count($notSubmittedFaculty) ?>;
    
    if (notSubmittedCount === 0) {
        Toast.info('All faculty have submitted!');
        return;
    }
    
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Sending ${notSubmittedCount} emails...`;
    
    // Show initial toast
    Toast.info(`Sending reminder emails to ${notSubmittedCount} faculty...`, 'Sending Reminders');
    
    const startTime = Date.now();
    
    // Set a client-side timeout (30 seconds for safety)
    const timeoutId = setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        Toast.error('Email sending timed out. The process may still be running in the background.', 'Timeout');
    }, 30000);
    
    fetch(BASE_URL + '/api/notify-not-submitted.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period_id: currentPeriodId }),
        signal: AbortSignal.timeout(30000) // Modern browsers support this
    })
    .then(res => {
        clearTimeout(timeoutId);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        return res.json();
    })
    .then(data => {
        const duration = ((Date.now() - startTime) / 1000).toFixed(1);
        
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            let message = `Successfully sent ${data.sent_count} email reminder${data.sent_count > 1 ? 's' : ''} in ${duration}s`;
            
            if (data.fail_count > 0) {
                message += ` (${data.fail_count} failed)`;
            }
            
            if (data.is_localhost) {
                Toast.warning('TEST MODE: Emails simulated (localhost). Check PHP log for content.', 'Localhost Mode');
            } else {
                Toast.success(message, 'Emails Sent');
            }
            
            if (data.errors && data.errors.length > 0) {
                console.warn('Email errors:', data.errors);
                if (data.fail_count > 0) {
                    setTimeout(() => {
                        Toast.warning(`${data.fail_count} email${data.fail_count > 1 ? 's' : ''} failed to send. Check console for details.`, 'Some Failures');
                    }, 2000);
                }
            }
        } else {
            Toast.error(data.message || 'Failed to send email reminders', 'Send Failed');
        }
    })
    .catch(err => {
        clearTimeout(timeoutId);
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (err.name === 'AbortError' || err.message.includes('timeout')) {
            Toast.error('Request timed out. Please check your internet connection or try with fewer recipients.', 'Timeout Error');
        } else if (err.message.includes('HTTP 500')) {
            Toast.error('Server error. Please check PHP error logs for details.', 'Server Error');
        } else {
            Toast.error('Error sending notifications. Please try again.', 'Connection Error');
        }
        
        console.error('Email error:', err);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
$pageTitle  = 'Reports';
$activePage = 'reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole('admin','dean');

$db = getDB();

// Pagination
$recordsPerPage = 25;
$currentPage = (int)($_GET['page'] ?? 1);

$periods = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();
$filterPeriod = (int)($_GET['period'] ?? ($db->query("SELECT id FROM rating_periods WHERE status IN ('open','rating') LIMIT 1")->fetchColumn() ?: 0));
$reportType = $_GET['type'] ?? 'summary'; // summary, late, on-time, not-submitted

$where = $filterPeriod ? "AND f.period_id={$filterPeriod}" : '';

// Get period details
$activePeriod = null;
if ($filterPeriod) {
    $activePeriod = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
    $activePeriod->execute([$filterPeriod]);
    $activePeriod = $activePeriod->fetch();
}

// For deans: restrict to their faculty
$facultyFilter = '';
if ($currentRole === 'dean') {
    $fac = $db->prepare("SELECT id FROM users WHERE assigned_dean_id=? AND is_active=1");
    $fac->execute([$currentUserId]);
    $facIds = array_column($fac->fetchAll(), 'id');
    if ($facIds) {
        $facultyFilter = " AND u.id IN (".implode(',',array_map('intval',$facIds)).")";
        $where .= " AND f.user_id IN (".implode(',',array_map('intval',$facIds)).")";
    } else {
        $facultyFilter = " AND 1=0";
        $where .= " AND 1=0";
    }
}

// Count total records
$totalRecords = (int)$db->query("
    SELECT COUNT(*) FROM ipcr_forms f
    JOIN users u ON f.user_id=u.id
    WHERE f.status IN ('submitted','under_review','reviewed','approved','finalized') {$where}
")->fetchColumn();

$pagination = calculatePagination($totalRecords, $recordsPerPage, $currentPage);

$forms = $db->query("
    SELECT f.*, u.first_name, u.last_name, u.employee_id, u.department, u.position,
           p.name as period_name,
           CONCAT(d.first_name,' ',d.last_name) as dean_name
    FROM ipcr_forms f
    JOIN users u ON f.user_id=u.id
    JOIN rating_periods p ON f.period_id=p.id
    LEFT JOIN users d ON f.reviewed_by=d.id
    WHERE f.status IN ('submitted','under_review','reviewed','approved','finalized') {$where}
    ORDER BY f.composite_rating DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
")->fetchAll();

// Count totals (without pagination for summary stats)
$totalStats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status='finalized' THEN 1 ELSE 0 END) as finalized
    FROM ipcr_forms f
    WHERE f.status IN ('submitted','under_review','reviewed','approved','finalized') {$where}
")->fetch();

$total      = $totalStats['total'];
$finalized  = $totalStats['finalized'];
$submitted  = count(array_filter($forms, fn($f)=>in_array($f['status'],['submitted','under_review','reviewed','approved','finalized'])));

// Submission tracking
$onTimeSubmissions = [];
$lateSubmissions = [];
$notSubmittedFaculty = [];

if ($filterPeriod) {
    // On-time submissions
    $onTimeSubmissions = $db->query("
        SELECT f.*, u.first_name, u.last_name, u.employee_id, u.department,
               p.name as period_name,
               CONCAT(d.first_name,' ',d.last_name) as dean_name
        FROM ipcr_forms f
        JOIN users u ON f.user_id=u.id
        JOIN rating_periods p ON f.period_id=p.id
        LEFT JOIN users d ON f.reviewed_by=d.id
        WHERE f.period_id={$filterPeriod} 
        AND f.is_late=0 
        AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        {$where}
        ORDER BY f.submitted_at ASC
    ")->fetchAll();
    
    // Late submissions
    $lateSubmissions = $db->query("
        SELECT f.*, u.first_name, u.last_name, u.employee_id, u.department,
               p.name as period_name,
               CONCAT(d.first_name,' ',d.last_name) as dean_name
        FROM ipcr_forms f
        JOIN users u ON f.user_id=u.id
        JOIN rating_periods p ON f.period_id=p.id
        LEFT JOIN users d ON f.reviewed_by=d.id
        WHERE f.period_id={$filterPeriod} 
        AND f.is_late=1
        AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
        {$where}
        ORDER BY f.submitted_at ASC
    ")->fetchAll();
    
    // Not submitted faculty (including those with only draft or returned forms)
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
    
    $notSubmittedFaculty = $db->query("
        SELECT u.id, u.first_name, u.last_name, u.employee_id, u.department, u.position,
               CONCAT(d.first_name,' ',d.last_name) as dean_name
        FROM users u
        LEFT JOIN users d ON u.assigned_dean_id=d.id
        WHERE {$whereNotSubmitted}
        ORDER BY u.last_name, u.first_name
    ")->fetchAll();
}

$submissionStats = [
    'on_time' => count($onTimeSubmissions),
    'late' => count($lateSubmissions),
    'not_submitted' => count($notSubmittedFaculty)
];

// Rating distribution
$dist = [];
foreach($forms as $f) {
    if($f['adjectival_rating']) $dist[$f['adjectival_rating']] = ($dist[$f['adjectival_rating']]??0)+1;
}
arsort($dist);
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-bar-chart me-2"></i>Reports</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Generate comprehensive reports on IPCR submissions and faculty performance</p>
  </div>
  <button onclick="window.print()" class="btn btn-outline-primary no-print">
    <i class="bi bi-printer me-2"></i>Print Report
  </button>
</div>

<div class="filter-bar mb-4 no-print">
  <form method="GET" class="d-flex gap-3 align-items-end flex-wrap">
    <div>
      <label class="form-label small">Period</label>
      <select name="period" class="form-select" style="min-width:250px" onchange="this.form.submit()">
        <option value="">All Periods</option>
        <?php foreach($periods as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $filterPeriod==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small">Report Type</label>
      <select name="type" class="form-select" style="min-width:200px" onchange="this.form.submit()">
        <option value="summary" <?= $reportType==='summary'?'selected':'' ?>>Summary & Ratings</option>
        <option value="on-time" <?= $reportType==='on-time'?'selected':'' ?>>On-Time Submissions</option>
        <option value="late" <?= $reportType==='late'?'selected':'' ?>>Late Submissions</option>
        <option value="not-submitted" <?= $reportType==='not-submitted'?'selected':'' ?>>Not Submitted</option>
      </select>
    </div>
  </form>
</div>

<?php if ($filterPeriod && $activePeriod): ?>
<!-- Submission Tracking Overview -->
<div class="card mb-4 no-print">
  <div class="card-header">
    <h6><i class="bi bi-clipboard-data me-2"></i>Submission Tracking - <?= h($activePeriod['name']) ?></h6>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <a href="?period=<?= $filterPeriod ?>&type=on-time" class="text-decoration-none">
          <div class="submission-stat-card" style="border-left: 4px solid #16a34a; background: #f0fdf4; cursor: pointer;">
            <div class="submission-stat-icon" style="color: #16a34a;">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="submission-stat-value" style="color: #16a34a;"><?= $submissionStats['on_time'] ?></div>
            <div class="submission-stat-label">On-Time Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-4">
        <a href="?period=<?= $filterPeriod ?>&type=late" class="text-decoration-none">
          <div class="submission-stat-card" style="border-left: 4px solid #d97706; background: #fffbeb; cursor: pointer;">
            <div class="submission-stat-icon" style="color: #d97706;">
              <i class="bi bi-clock-history"></i>
            </div>
            <div class="submission-stat-value" style="color: #d97706;"><?= $submissionStats['late'] ?></div>
            <div class="submission-stat-label">Late Submissions</div>
          </div>
        </a>
      </div>
      <div class="col-md-4">
        <a href="?period=<?= $filterPeriod ?>&type=not-submitted" class="text-decoration-none">
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
    <?php if ($activePeriod['submission_deadline']): ?>
    <div class="mt-3 text-center text-muted small">
      <i class="bi bi-info-circle me-1"></i>
      Submission Deadline: <strong><?= h(date('F d, Y', strtotime($activePeriod['submission_deadline']))) ?></strong>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="metric-card text-center">
    <div class="metric-value"><?= $total ?></div><div class="metric-label">Total Submitted</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="metric-card text-center">
    <div class="metric-value"><?= $finalized ?></div><div class="metric-label">Finalized</div>
  </div></div>
  <?php foreach($dist as $adj => $cnt): ?>
  <div class="col-6 col-md-3"><div class="metric-card text-center">
    <div class="metric-value"><?= $cnt ?></div><div class="metric-label"><?= h($adj) ?></div>
  </div></div>
  <?php endforeach; ?>
</div>

<?php if ($reportType === 'on-time'): ?>
<!-- ON-TIME SUBMISSIONS REPORT -->
<h5 class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i>On-Time Submissions (<?= count($onTimeSubmissions) ?>)</h5>
<div class="table-container">
  <table class="ipcr-table">
    <thead><tr>
      <th>#</th><th>Faculty</th><th>Department</th><th>Status</th>
      <th>Submitted</th><th>Evaluator</th><th>Composite</th><th class="no-print">Actions</th>
    </tr></thead>
    <tbody>
      <?php if(!$onTimeSubmissions): ?><tr><td colspan="8" class="text-center text-muted py-4">No on-time submissions found.</td></tr><?php endif; ?>
      <?php foreach($onTimeSubmissions as $i => $f): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <strong><?= h($f['first_name'].' '.$f['last_name']) ?></strong>
          <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
        </td>
        <td><?= h($f['department']) ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td class="text-muted-sm"><?= h(date('M d, Y h:i A', strtotime($f['submitted_at']))) ?></td>
        <td><?= h($f['dean_name'] ?? '—') ?></td>
        <td class="text-center"><strong><?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?></strong></td>
        <td class="no-print">
          <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($reportType === 'late'): ?>
<!-- LATE SUBMISSIONS REPORT -->
<h5 class="mb-3"><i class="bi bi-clock-history text-warning me-2"></i>Late Submissions (<?= count($lateSubmissions) ?>)</h5>
<div class="table-container">
  <table class="ipcr-table">
    <thead><tr>
      <th>#</th><th>Faculty</th><th>Department</th><th>Status</th>
      <th>Submitted</th><th>Evaluator</th><th>Composite</th><th class="no-print">Actions</th>
    </tr></thead>
    <tbody>
      <?php if(!$lateSubmissions): ?><tr><td colspan="8" class="text-center text-muted py-4">No late submissions found.</td></tr><?php endif; ?>
      <?php foreach($lateSubmissions as $i => $f): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <strong><?= h($f['first_name'].' '.$f['last_name']) ?></strong>
          <span class="late-badge">Late</span>
          <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
        </td>
        <td><?= h($f['department']) ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td class="text-muted-sm"><?= h(date('M d, Y h:i A', strtotime($f['submitted_at']))) ?></td>
        <td><?= h($f['dean_name'] ?? '—') ?></td>
        <td class="text-center"><strong><?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?></strong></td>
        <td class="no-print">
          <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($reportType === 'not-submitted'): ?>
<!-- NOT SUBMITTED REPORT -->
<h5 class="mb-3"><i class="bi bi-x-circle-fill text-danger me-2"></i>Faculty Who Haven't Submitted (<?= count($notSubmittedFaculty) ?>)</h5>
<div class="table-container">
  <table class="ipcr-table">
    <thead><tr>
      <th>#</th><th>Faculty</th><th>Department</th><th>Position</th><th>Evaluator</th>
    </tr></thead>
    <tbody>
      <?php if(!$notSubmittedFaculty): ?><tr><td colspan="5" class="text-center text-success py-4"><i class="bi bi-check-circle me-2"></i>All faculty have submitted their IPCRs!</td></tr><?php endif; ?>
      <?php foreach($notSubmittedFaculty as $i => $u): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <strong><?= h($u['first_name'].' '.$u['last_name']) ?></strong>
          <div class="text-muted-sm"><?= h($u['employee_id']) ?></div>
        </td>
        <td><?= h($u['department']) ?></td>
        <td><?= h($u['position']) ?></td>
        <td><?= h($u['dean_name'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>
<!-- SUMMARY REPORT -->

<div class="table-container">
  <table class="ipcr-table">
    <thead><tr>
      <th>#</th><th>Faculty</th><th>Department</th><th>Period</th>
      <th>Evaluator</th><th>Status</th><th>Composite Rating</th><th>Adjectival Rating</th><th class="no-print">Actions</th>
    </tr></thead>
    <tbody>
      <?php if(!$forms): ?><tr><td colspan="9" class="text-center text-muted py-4">No records found.</td></tr><?php endif; ?>
      <?php foreach($forms as $i => $f): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <strong><?= h($f['first_name'].' '.$f['last_name']) ?></strong>
          <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
        </td>
        <td><?= h($f['department']) ?></td>
        <td><?= h($f['period_name']) ?></td>
        <td><?= h($f['dean_name'] ?? '—') ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td class="text-center"><strong><?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?></strong></td>
        <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
        <td class="no-print" style="white-space:nowrap">
          <div class="d-flex align-items-center gap-1">
            <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-eye"></i> View
            </a>
            <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-printer"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  
  <?php if ($pagination && $pagination['total_pages'] > 1): ?>
    <?= renderPagination($pagination) ?>
  <?php endif; ?>
  
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

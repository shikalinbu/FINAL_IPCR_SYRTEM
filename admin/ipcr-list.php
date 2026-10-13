<?php
$pageTitle  = 'All IPCR Forms';
$activePage = 'ipcr-list';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin','secretary','dean');

$db = getDB();

$filterName   = trim($_GET['name']   ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterPeriod = (int)($_GET['period'] ?? 0);
$filterDept   = trim($_GET['dept']   ?? '');

$periods = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();
$depts   = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$where = "1=1";
if ($filterPeriod) $where .= " AND f.period_id=" . (int)$filterPeriod;

// Handle special status filters
if ($filterStatus) {
    if ($filterStatus === 'all_submitted') {
        // Show all successfully submitted forms (not draft or returned)
        $where .= " AND f.status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')";
    } else {
        // Exact status match
        $where .= " AND f.status=" . $db->quote($filterStatus);
    }
}

if ($filterDept)   $where .= " AND u.department=" . $db->quote($filterDept);
if ($filterName)   $where .= " AND (u.first_name LIKE " . $db->quote('%'.$filterName.'%') . " OR u.last_name LIKE " . $db->quote('%'.$filterName.'%') . " OR u.employee_id LIKE " . $db->quote('%'.$filterName.'%') . ")";

$forms = $db->query("
    SELECT f.*,
           CONCAT(u.first_name,' ',u.last_name) as faculty_name,
           u.employee_id, u.department, u.position,
           p.name as period_name,
           CONCAT(d.first_name,' ',d.last_name) as dean_name
    FROM ipcr_forms f
    JOIN users u ON f.user_id=u.id
    JOIN rating_periods p ON f.period_id=p.id
    LEFT JOIN users d ON f.reviewed_by=d.id
    WHERE {$where}
    ORDER BY FIELD(f.status,'reviewed','submitted','under_review','returned','approved','finalized','draft'), f.updated_at DESC
")->fetchAll();
?>

<div class="page-header mb-3">
  <div class="page-header-left">
    <h1><i class="bi bi-archive me-2"></i>IPCR Archive</h1>
    <p class="text-muted mb-0" style="font-size: 0.9rem;">Browse and manage all IPCR submissions by academic year and semester</p>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3" style="border: 1px solid #e5e7eb; box-shadow: none;">
  <div class="card-body" style="padding: 1.25rem;">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-medium mb-1" style="color: #6b7280;">Search Faculty</label>
        <input type="text" name="name" class="form-control" placeholder="Name or Employee ID..." value="<?= h($filterName) ?>" style="font-size: 0.9rem;">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-medium mb-1" style="color: #6b7280;">Status</label>
        <select name="status" class="form-select" style="font-size: 0.9rem;">
          <option value="">All Statuses</option>
          <option value="all_submitted" <?= $filterStatus==='all_submitted'?'selected':'' ?>>✓ All Submitted</option>
          <?php foreach(['draft','submitted','under_review','reviewed','returned','approved','finalized'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$s))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-medium mb-1" style="color: #6b7280;">Department</label>
        <select name="dept" class="form-select" style="font-size: 0.9rem;">
          <option value="">All Departments</option>
          <?php foreach($depts as $d): ?>
          <option value="<?= h($d) ?>" <?= $filterDept===$d?'selected':'' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
        <?php if($filterStatus || $filterName || $filterDept): ?>
        <a href="?" class="btn btn-outline-secondary">
          <i class="bi bi-x-circle me-1"></i>Clear
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php
// Group periods by academic year for folder structure
$periodsByYear = [];

foreach($periods as $p) {
    $year = $p['academic_year'] ?? 'Unknown';
    
    if (!isset($periodsByYear[$year])) {
        $periodsByYear[$year] = [];
    }
    $periodsByYear[$year][] = $p;
}
krsort($periodsByYear); // Most recent year first
?>

<style>
.period-accordion {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin-bottom: 1rem;
  overflow: hidden;
  background: white;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.period-year-header {
  background: linear-gradient(135deg, #1a5c2a 0%, #1e6a30 100%);
  color: white;
  padding: 16px 20px;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  transition: all 0.2s ease;
}
.period-year-header:hover {
  background: linear-gradient(135deg, #145022 0%, #196328 100%);
}
.period-year-header .icon {
  transition: transform 0.3s ease;
  font-size: 1.2rem;
  opacity: 0.9;
}
.period-year-header.collapsed .icon {
  transform: rotate(-90deg);
}
.year-body {
  display: block;
  overflow: hidden;
  transition: max-height 0.3s ease-out, opacity 0.2s ease;
  max-height: 5000px;
  opacity: 1;
}
.year-body.collapsed {
  max-height: 0;
  opacity: 0;
}
.semester-folder {
  border-bottom: 1px solid #f3f4f6;
}
.semester-folder:last-child {
  border-bottom: none;
}
.semester-header {
  background: #fafbfc;
  padding: 14px 20px;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  transition: background 0.15s ease;
}
.semester-header:hover {
  background: #f3f4f6;
}
.semester-header .left-section {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  min-width: 0;
}
.semester-header .period-name-block {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.semester-header .period-main-name {
  font-size: 0.95rem;
  font-weight: 600;
  color: #1f2937;
  display: flex;
  align-items: center;
  gap: 8px;
}
.semester-header .period-deadline {
  font-size: 0.75rem;
  color: #6b7280;
  font-weight: normal;
}
.semester-header .right-section {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.semester-header .chevron-icon {
  transition: transform 0.25s ease;
  color: #9ca3af;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.semester-header.collapsed .chevron-icon {
  transform: rotate(-90deg);
}
.semester-content {
  display: block;
  overflow: hidden;
  transition: max-height 0.3s ease-out;
  max-height: 3000px;
  background: #ffffff;
}
.semester-content.collapsed {
  max-height: 0;
}
.ipcr-table-wrapper {
  padding: 0;
}
.ipcr-table-wrapper table {
  margin: 0;
  border: none;
}
.ipcr-table-wrapper table thead {
  background: #f9fafb;
  border-top: 1px solid #f3f4f6;
}
.ipcr-table-wrapper table th {
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.025em;
  color: #6b7280;
  padding: 12px 16px;
}
.ipcr-table-wrapper table td {
  padding: 14px 16px;
  font-size: 0.9rem;
}
.no-forms-msg {
  padding: 40px;
  text-align: center;
  color: #9ca3af;
  font-size: 0.9rem;
}
.notify-not-submitted-btn {
  font-size: 0.75rem;
  padding: 4px 10px;
  border-radius: 4px;
  transition: all 0.2s ease;
}
.notify-not-submitted-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<div id="archive-accordion">
<?php foreach($periodsByYear as $year => $yearPeriods): 
  // Group periods by name + deadline to merge duplicates
  $mergedPeriods = [];
  foreach($yearPeriods as $p) {
    $key = $p['name'] . '|' . ($p['submission_deadline'] ?? '');
    if (!isset($mergedPeriods[$key])) {
      $mergedPeriods[$key] = $p;
      $mergedPeriods[$key]['period_ids'] = [$p['id']]; // Track all IDs
    } else {
      $mergedPeriods[$key]['period_ids'][] = $p['id']; // Add additional ID
    }
  }
  
  $yearFormCount = 0;
  foreach($mergedPeriods as $p) {
    // Count forms across all merged period IDs
    foreach($p['period_ids'] as $pid) {
      foreach($forms as $f) {
        if ($f['period_id'] == $pid) {
          $yearFormCount++;
        }
      }
    }
  }
?>
  <div class="period-accordion">
    <div class="period-year-header collapsed" onclick="toggleYear(this)">
      <div class="d-flex align-items-center gap-3">
        <i class="bi bi-calendar-fill" style="font-size: 1.1rem;"></i>
        <strong style="font-size: 1.05rem;">Academic Year <?= h($year) ?></strong>
        <span class="badge bg-light text-dark border" style="font-size:0.75rem;padding:4px 10px;">
          <?= $yearFormCount ?> <?= $yearFormCount === 1 ? 'form' : 'forms' ?>
        </span>
      </div>
      <i class="bi bi-chevron-down icon"></i>
    </div>
    <div class="year-body collapsed">
      <?php 
      // Sort merged periods by semester (2nd first, then 1st)
      uasort($mergedPeriods, function($a, $b) {
        return strcmp($b['semester'] ?? '', $a['semester'] ?? '');
      });
      
      foreach($mergedPeriods as $p):
        // Collect all forms from all merged period IDs
        $periodForms = [];
        foreach($p['period_ids'] as $pid) {
          foreach($forms as $f) {
            if ($f['period_id'] == $pid) {
              $periodForms[] = $f;
            }
          }
        }
        
        // Calculate statistics across all forms
        $finalized = count(array_filter($periodForms, fn($f) => $f['status'] === 'finalized'));
        $approved = count(array_filter($periodForms, fn($f) => $f['status'] === 'approved'));
        $pending = count(array_filter($periodForms, fn($f) => in_array($f['status'], ['submitted','under_review'])));
        $reviewed = count(array_filter($periodForms, fn($f) => $f['status'] === 'reviewed'));
      ?>
      <div class="semester-folder">
        <div class="semester-header collapsed" onclick="toggleSemester(this)">
          <div class="left-section">
            <i class="bi bi-folder2" style="color: #9ca3af; font-size: 1.1rem;"></i>
            <div class="period-name-block">
              <div class="period-main-name">
                <?= h($p['name']) ?>
                <?php if ($p['submission_deadline']): ?>
                  <span style="font-size: 0.8rem; color: #6b7280; font-weight: 500; margin-left: 8px;">
                    — Due <?= date('M d, Y', strtotime($p['submission_deadline'])) ?>
                  </span>
                <?php endif; ?>
                <?php if ($p['status']): ?>
                  <span class="badge <?= $p['status']==='open'?'bg-success':($p['status']==='rating'?'bg-warning text-dark':($p['status']==='closed'?'bg-secondary':'bg-dark')) ?>" style="font-size:0.7rem;padding:3px 8px;margin-left:8px;">
                    <?= h(ucfirst($p['status'])) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="right-section">
            <span class="badge bg-light text-dark border" style="font-size:0.75rem;padding:4px 10px;">
              <?= count($periodForms) ?> total
            </span>
            <?php if ($reviewed > 0): ?>
              <span class="badge bg-info text-white" style="font-size:0.7rem;padding:4px 8px;"><?= $reviewed ?> Reviewed</span>
            <?php endif; ?>
            <?php if ($approved > 0): ?>
              <span class="badge bg-success" style="font-size:0.7rem;padding:4px 8px;"><?= $approved ?> Approved</span>
            <?php endif; ?>
            <?php if ($finalized > 0): ?>
              <span class="badge bg-dark" style="font-size:0.7rem;padding:4px 8px;"><?= $finalized ?> Finalized</span>
            <?php endif; ?>
            <?php if ($pending > 0): ?>
              <span class="badge bg-warning text-dark" style="font-size:0.7rem;padding:4px 8px;"><?= $pending ?> Pending</span>
            <?php endif; ?>
            
            <?php 
            // Get faculty who haven't submitted for this period
            $submittedUserIds = [];
            foreach($periodForms as $pf) {
              if (in_array($pf['status'], ['submitted', 'under_review', 'reviewed', 'approved', 'finalized'])) {
                $submittedUserIds[] = $pf['user_id'];
              }
            }
            
            // Count total active faculty (simplified - just show button if there are any non-submitted)
            $notSubmittedCount = count(array_unique(array_filter($periodForms, fn($f) => !in_array($f['status'], ['submitted', 'under_review', 'reviewed', 'approved', 'finalized']))));
            
            if ($notSubmittedCount > 0): ?>
              <button class="btn btn-sm btn-outline-primary notify-not-submitted-btn" 
                      data-period-ids="<?= h(json_encode($p['period_ids'])) ?>"
                      data-period-name="<?= h($p['name']) ?>"
                      title="Send reminder emails"
                      onclick="event.stopPropagation(); openNotificationModal(<?= h(json_encode($p['period_ids'])) ?>, '<?= h($p['name']) ?>')">
                <i class="bi bi-envelope me-1"></i>Notify (<?= $notSubmittedCount ?>)
              </button>
            <?php endif; ?>
            
            <i class="bi bi-chevron-down chevron-icon"></i>
          </div>
        </div>
        <div class="semester-content collapsed">
          <?php if(empty($periodForms)): ?>
            <div class="no-forms-msg">
              <i class="bi bi-inbox me-2"></i>
              <?php if($filterStatus || $filterName || $filterDept): ?>
                No IPCR forms match your current filters for this period.
              <?php else: ?>
                No IPCR forms for this period yet.
              <?php endif; ?>
            </div>
          <?php else: 
            $formsPerPage = 10; // Show 10 forms initially
            $totalForms = count($periodForms);
          ?>
          <div class="ipcr-table-wrapper">
            <table class="ipcr-table">
              <thead><tr>
                <th class="text-white">Faculty</th><th>Department</th><th>Evaluator</th>
                <th>Status</th><th>Composite</th><th>Adjectival</th><th>Submitted</th><th>Actions</th>
              </tr></thead>
              <tbody class="table-body-paginated" data-period-key="<?= md5($p['name'] . $p['submission_deadline']) ?>">
                <?php 
                $visibleForms = array_slice($periodForms, 0, $formsPerPage);
                $hiddenForms = array_slice($periodForms, $formsPerPage);
                
                foreach($visibleForms as $f): ?>
                <tr>
                  <td>
                    <strong><?= h($f['faculty_name']) ?></strong>
                    <?= $f['is_late'] ? '<span class="late-badge">Late</span>' : '' ?>
                    <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
                  </td>
                  <td><?= h($f['department']) ?></td>
                  <td><?= h($f['dean_name'] ?? '—') ?></td>
                  <td><?= statusBadge($f['status']) ?></td>
                  <td class="text-center">
                    <?= $f['composite_rating'] ? '<strong>'.number_format($f['composite_rating'], 2).'</strong>' : '—' ?>
                  </td>
                  <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
                  <td class="text-muted-sm"><?= $f['submitted_at'] ? h(date('M d, Y', strtotime($f['submitted_at']))) : '—' ?></td>
                  <td class="actions" style="white-space:nowrap;min-width:160px">
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                      <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>"
                         class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                      </a>
                      <?php if($f['status'] === 'reviewed' && $currentRole === 'admin'): ?>
                      <button class="btn btn-sm btn-success" data-action="approve" data-form-id="<?= $f['id'] ?>" title="Approve">
                        <i class="bi bi-check2-all me-1"></i>Approve
                      </button>
                      <button class="btn btn-sm btn-outline-danger" data-action="admin-return" data-form-id="<?= $f['id'] ?>" title="Return">
                        <i class="bi bi-arrow-return-left"></i>
                      </button>
                      <?php elseif($f['status'] === 'approved' && $currentRole === 'admin'): ?>
                      <button class="btn btn-sm btn-dark" data-action="finalize" data-form-id="<?= $f['id'] ?>" title="Finalize">
                        <i class="bi bi-lock me-1"></i>Finalize
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                
                <?php foreach($hiddenForms as $f): ?>
                <tr class="hidden-row" style="display: none;">
                  <td>
                    <strong><?= h($f['faculty_name']) ?></strong>
                    <?= $f['is_late'] ? '<span class="late-badge">Late</span>' : '' ?>
                    <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
                  </td>
                  <td><?= h($f['department']) ?></td>
                  <td><?= h($f['dean_name'] ?? '—') ?></td>
                  <td><?= statusBadge($f['status']) ?></td>
                  <td class="text-center">
                    <?= $f['composite_rating'] ? '<strong>'.number_format($f['composite_rating'], 2).'</strong>' : '—' ?>
                  </td>
                  <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
                  <td class="text-muted-sm"><?= $f['submitted_at'] ? h(date('M d, Y', strtotime($f['submitted_at']))) : '—' ?></td>
                  <td class="actions" style="white-space:nowrap;min-width:160px">
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                      <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>"
                         class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="bi bi-eye"></i>
                      </a>
                      <?php if($f['status'] === 'reviewed' && $currentRole === 'admin'): ?>
                      <button class="btn btn-sm btn-success" data-action="approve" data-form-id="<?= $f['id'] ?>" title="Approve">
                        <i class="bi bi-check2-all me-1"></i>Approve
                      </button>
                      <button class="btn btn-sm btn-outline-danger" data-action="admin-return" data-form-id="<?= $f['id'] ?>" title="Return">
                        <i class="bi bi-arrow-return-left"></i>
                      </button>
                      <?php elseif($f['status'] === 'approved' && $currentRole === 'admin'): ?>
                      <button class="btn btn-sm btn-dark" data-action="finalize" data-form-id="<?= $f['id'] ?>" title="Finalize">
                        <i class="bi bi-lock me-1"></i>Finalize
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if($totalForms > $formsPerPage): ?>
            <div class="pagination-controls" style="padding: 16px; text-align: center; border-top: 1px solid #f3f4f6;">
              <button class="btn btn-sm btn-outline-primary" onclick="showMoreRows('<?= md5($p['name'] . $p['submission_deadline']) ?>')">
                <i class="bi bi-arrow-down-circle me-1"></i>
                Show More (<?= $totalForms - $formsPerPage ?> remaining)
              </button>
              <button class="btn btn-sm btn-outline-secondary" onclick="showLessRows('<?= md5($p['name'] . $p['submission_deadline']) ?>')" style="display: none;">
                <i class="bi bi-arrow-up-circle me-1"></i>
                Show Less
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
function toggleYear(header) {
  header.classList.toggle('collapsed');
  const body = header.nextElementSibling;
  body.classList.toggle('collapsed');
}

function toggleSemester(header) {
  header.classList.toggle('collapsed');
  const content = header.nextElementSibling;
  content.classList.toggle('collapsed');
}

function showMoreRows(periodKey) {
  const tbody = document.querySelector(`.table-body-paginated[data-period-key="${periodKey}"]`);
  const hiddenRows = tbody.querySelectorAll('.hidden-row');
  const paginationDiv = tbody.closest('.ipcr-table-wrapper').querySelector('.pagination-controls');
  const showMoreBtn = paginationDiv.querySelector('.btn-outline-primary');
  const showLessBtn = paginationDiv.querySelector('.btn-outline-secondary');
  
  hiddenRows.forEach(row => {
    row.style.display = '';
    row.classList.remove('hidden-row');
  });
  
  showMoreBtn.style.display = 'none';
  showLessBtn.style.display = 'inline-block';
}

function showLessRows(periodKey) {
  const tbody = document.querySelector(`.table-body-paginated[data-period-key="${periodKey}"]`);
  const allRows = tbody.querySelectorAll('tr');
  const paginationDiv = tbody.closest('.ipcr-table-wrapper').querySelector('.pagination-controls');
  const showMoreBtn = paginationDiv.querySelector('.btn-outline-primary');
  const showLessBtn = paginationDiv.querySelector('.btn-outline-secondary');
  
  // Hide rows after the first 10
  allRows.forEach((row, index) => {
    if (index >= 10) {
      row.style.display = 'none';
      row.classList.add('hidden-row');
    }
  });
  
  // Update button text
  const hiddenCount = allRows.length - 10;
  showMoreBtn.innerHTML = `<i class="bi bi-arrow-down-circle me-1"></i>Show More (${hiddenCount} remaining)`;
  
  showMoreBtn.style.display = 'inline-block';
  showLessBtn.style.display = 'none';
  
  // Scroll to top of the table
  tbody.closest('.semester-content').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="bi bi-envelope me-2"></i>Send Email Reminders
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="notification-period-info" class="alert alert-info mb-3">
          <strong>Period:</strong> <span id="notif-period-name"></span>
        </div>
        
        <div id="notification-faculty-list" class="mb-3">
          <p class="text-muted mb-2"><i class="bi bi-info-circle me-1"></i>Loading faculty who haven't submitted...</p>
        </div>
        
        <div id="notification-progress" style="display: none;">
          <div class="progress mb-2" style="height: 25px;">
            <div id="notification-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                 role="progressbar" style="width: 0%">0%</div>
          </div>
          <p id="notification-status" class="text-muted small mb-0"></p>
        </div>
        
        <div id="notification-results" style="display: none;" class="mt-3">
          <div class="alert alert-success" id="notification-success-msg"></div>
          <div class="alert alert-warning" id="notification-warning-msg" style="display: none;"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="send-notifications-btn" onclick="startSendingNotifications()">
          <i class="bi bi-send me-1"></i>Send All Emails
        </button>
      </div>
    </div>
  </div>
</div>

<script>
window.BASE_URL='<?= BASE_URL ?>';

let currentPeriodIds = [];
let currentPeriodName = '';
let facultyToNotify = [];
let currentlySending = false;

function openNotificationModal(periodIds, periodName) {
  currentPeriodIds = periodIds;
  currentPeriodName = periodName;
  
  document.getElementById('notif-period-name').textContent = periodName;
  document.getElementById('notification-faculty-list').innerHTML = '<p class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Loading...</p>';
  document.getElementById('notification-progress').style.display = 'none';
  document.getElementById('notification-results').style.display = 'none';
  document.getElementById('send-notifications-btn').disabled = false;
  
  const modal = new bootstrap.Modal(document.getElementById('notificationModal'));
  modal.show();
  
  // Load faculty list
  loadFacultyToNotify(periodIds);
}

async function loadFacultyToNotify(periodIds) {
  try {
    const response = await fetch('<?= BASE_URL ?>/api/get-not-submitted-faculty.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ period_ids: periodIds })
    });
    
    const data = await response.json();
    
    if (data.success) {
      facultyToNotify = data.faculty;
      
      if (facultyToNotify.length === 0) {
        document.getElementById('notification-faculty-list').innerHTML = 
          '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>All faculty have submitted!</div>';
        document.getElementById('send-notifications-btn').disabled = true;
        return;
      }
      
      let html = `
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <strong>${facultyToNotify.length}</strong> ${facultyToNotify.length === 1 ? 'faculty member has' : 'faculty members have'} not submitted yet.
        </div>
        <div style="max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px;">
          <table class="table table-sm mb-0">
            <thead style="position: sticky; top: 0; background: #f9fafb; z-index: 1;">
              <tr>
                <th>Faculty Name</th>
                <th>Employee ID</th>
                <th>Email</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
      `;
      
      facultyToNotify.forEach(f => {
        const hasEmail = f.email && f.email.trim() !== '';
        html += `
          <tr>
            <td><strong>${escapeHtml(f.name)}</strong></td>
            <td><span class="text-muted">${escapeHtml(f.employee_id)}</span></td>
            <td>${hasEmail ? escapeHtml(f.email) : '<span class="text-danger">No email</span>'}</td>
            <td><span class="badge ${hasEmail ? 'bg-success' : 'bg-danger'}">${hasEmail ? 'Ready' : 'Skip'}</span></td>
          </tr>
        `;
      });
      
      html += `
            </tbody>
          </table>
        </div>
      `;
      
      document.getElementById('notification-faculty-list').innerHTML = html;
    } else {
      document.getElementById('notification-faculty-list').innerHTML = 
        `<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>${escapeHtml(data.message)}</div>`;
      document.getElementById('send-notifications-btn').disabled = true;
    }
  } catch (error) {
    console.error('Error loading faculty:', error);
    document.getElementById('notification-faculty-list').innerHTML = 
      '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>Error loading faculty list</div>';
    document.getElementById('send-notifications-btn').disabled = true;
  }
}

async function startSendingNotifications() {
  if (currentlySending) return;
  if (facultyToNotify.length === 0) return;
  
  currentlySending = true;
  document.getElementById('send-notifications-btn').disabled = true;
  document.getElementById('notification-progress').style.display = 'block';
  document.getElementById('notification-results').style.display = 'none';
  
  const progressBar = document.getElementById('notification-progress-bar');
  const statusText = document.getElementById('notification-status');
  
  let sent = 0;
  let failed = 0;
  let skipped = 0;
  
  // Filter faculty with emails
  const facultyWithEmails = facultyToNotify.filter(f => f.email && f.email.trim() !== '');
  const total = facultyWithEmails.length;
  
  if (total === 0) {
    alert('No faculty members with valid email addresses!');
    currentlySending = false;
    return;
  }
  
  for (let i = 0; i < facultyWithEmails.length; i++) {
    const faculty = facultyWithEmails[i];
    const progress = Math.round(((i + 1) / total) * 100);
    
    progressBar.style.width = progress + '%';
    progressBar.textContent = progress + '%';
    statusText.textContent = `Sending to ${faculty.name} (${i + 1} of ${total})...`;
    
    try {
      const response = await fetch('<?= BASE_URL ?>/api/send-individual-notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          user_id: faculty.user_id,
          period_ids: currentPeriodIds,
          period_name: currentPeriodName
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        sent++;
      } else {
        failed++;
        console.error(`Failed to send to ${faculty.name}:`, data.message);
      }
    } catch (error) {
      failed++;
      console.error(`Error sending to ${faculty.name}:`, error);
    }
    
    // Small delay between emails (250ms)
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  
  // Show results
  progressBar.classList.remove('progress-bar-animated');
  statusText.textContent = 'Completed!';
  
  document.getElementById('notification-results').style.display = 'block';
  document.getElementById('notification-success-msg').innerHTML = 
    `<i class="bi bi-check-circle me-2"></i><strong>✓ Sent ${sent} email${sent !== 1 ? 's' : ''} successfully!</strong>`;
  
  if (failed > 0) {
    document.getElementById('notification-warning-msg').style.display = 'block';
    document.getElementById('notification-warning-msg').innerHTML = 
      `<i class="bi bi-exclamation-triangle me-2"></i>${failed} email${failed !== 1 ? 's' : ''} failed to send.`;
  }
  
  if (skipped > 0) {
    document.getElementById('notification-warning-msg').style.display = 'block';
    document.getElementById('notification-warning-msg').innerHTML += 
      `<br><i class="bi bi-info-circle me-2"></i>${skipped} ${skipped !== 1 ? 'users' : 'user'} skipped (no email).`;
  }
  
  currentlySending = false;
  
  // Change button text
  document.getElementById('send-notifications-btn').innerHTML = '<i class="bi bi-check2 me-1"></i>Done';
  document.getElementById('send-notifications-btn').disabled = true;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

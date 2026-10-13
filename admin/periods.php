<?php
$pageTitle  = 'Rating Periods';
$activePage = 'periods';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/email.php';
requireRole('admin','secretary','dean');

$db  = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($currentRole, ['admin', 'dean'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $periodName = trim($_POST['period_name']      ?? ''); // Custom period name
        $ay      = trim($_POST['academic_year']       ?? '');
        $subDL   = $_POST['submission_deadline']      ?? '';
        $revDL   = $_POST['review_deadline']          ?? '';
        $tmplId  = (int)($_POST['template_id']        ?? 0) ?: null;
        $pid     = (int)($_POST['period_id']          ?? 0);

        // Use custom period name
        $name = $periodName;
        
        // Set start/end dates to null (no longer used - period name describes the range)
        $start = null;
        $end = null;
        
        // Set semester to null (no longer used in new structure)
        $sem = null;

        if (!$name) { $msg = 'Period Name is required.'; $msgType = 'danger'; }
        elseif (!$subDL) { $msg = 'Submission Deadline is required.'; $msgType = 'danger'; }
        elseif ($revDL && $subDL && strtotime($subDL) >= strtotime($revDL)) {
            // Validation: Submission must be BEFORE Review
            $msg = 'Submission Deadline must be earlier than Review Deadline. (Submit: ' . $subDL . ', Review: ' . $revDL . ')';
            $msgType = 'danger';
        }
        else {
            if ($action === 'create') {
                $db->prepare("INSERT INTO rating_periods (name,academic_year,semester,start_date,end_date,submission_deadline,review_deadline,status,template_id,created_by) VALUES (?,?,?,?,?,?,?,'draft',?,?)")
                   ->execute([$name,$ay,$sem,$start,$end,$subDL,$revDL,$tmplId,$currentUserId]);
                $newId = $db->lastInsertId();
                auditLog('created','period',$newId,null,['name'=>$name],'Period created');
                $msg = "Period '{$name}' created.";
            } else {
                $db->prepare("UPDATE rating_periods SET name=?,academic_year=?,semester=?,start_date=?,end_date=?,submission_deadline=?,review_deadline=?,template_id=?,updated_at=NOW() WHERE id=?")
                   ->execute([$name,$ay,$sem,$start,$end,$subDL,$revDL,$tmplId,$pid]);
                auditLog('updated','period',$pid,null,['name'=>$name],'Period updated');
                $msg = "Period updated.";
            }
        }
    }

    if ($action === 'delete') {
        $pid = (int)($_POST['period_id'] ?? 0);
        
        if (!$pid) {
            $msg = 'Invalid period ID.'; $msgType = 'danger';
        } else {
            // Check if period has IPCR forms
            $formCount = $db->prepare("SELECT COUNT(*) FROM ipcr_forms WHERE period_id=?");
            $formCount->execute([$pid]);
            $count = $formCount->fetchColumn();
            
            if ($count > 0) {
                $msg = "Cannot delete: This period has {$count} IPCR form(s). Delete or reassign them first."; 
                $msgType = 'danger';
            } else {
                // Safe to delete
                $periodName = $db->prepare("SELECT name FROM rating_periods WHERE id=?");
                $periodName->execute([$pid]);
                $name = $periodName->fetchColumn();
                
                $db->prepare("DELETE FROM rating_periods WHERE id=?")->execute([$pid]);
                auditLog('deleted', 'period', $pid, $name, null, "Period deleted: {$name}");
                $msg = "Period '{$name}' deleted successfully.";
                $msgType = 'success';
            }
        }
    }

    if ($action === 'advance') {
        $pid     = (int)($_POST['period_id'] ?? 0);
        $current = trim($_POST['current_status'] ?? '');
        $target  = trim($_POST['target_status'] ?? ''); // New: direct target status
        
        // If target specified, go directly to that status
        if ($target && in_array($target, ['open','rating','closed','archived'])) {
            $next = $target;
        } else {
            // Otherwise, advance to next status
            $nextMap = ['draft'=>'open','open'=>'rating','rating'=>'closed','closed'=>'archived'];
            $next    = $nextMap[$current] ?? null;
        }
        
        if ($next && in_array($next, ['open','rating'])) {
            $conflict = $db->query("SELECT COUNT(*) FROM rating_periods WHERE status IN ('open','rating') AND id<>{$pid}")->fetchColumn();
            if ($conflict) { $msg = "Another period is already active. Close it first."; $msgType = 'danger'; }
            else {
                $db->prepare("UPDATE rating_periods SET status=?,updated_at=NOW() WHERE id=?")->execute([$next,$pid]);
                auditLog('status_change','period',$pid,$current,$next,"Period advanced to {$next}");
                
                // Send email notification to all active faculty when period opens
                if ($next === 'open') {
                    $periodInfo = $db->prepare("SELECT name, submission_deadline FROM rating_periods WHERE id=?");
                    $periodInfo->execute([$pid]);
                    $period = $periodInfo->fetch();
                    
                    // Get all active faculty members
                    $facultyList = $db->query("
                        SELECT first_name, last_name, email, personal_email, employee_id
                        FROM users
                        WHERE role = 'faculty' AND is_active = 1 AND assigned_dean_id IS NOT NULL
                    ")->fetchAll();
                    
                    foreach ($facultyList as $faculty) {
                        $facultyEmail = $faculty['personal_email'] ?: $faculty['email'];
                        $facultyName = "{$faculty['first_name']} {$faculty['last_name']}";
                        
                        $subject = "ISU IPCR System - New Evaluation Period is Now Open";
                        
                        $deadlineText = $period['submission_deadline'] 
                            ? date('F d, Y', strtotime($period['submission_deadline']))
                            : 'To be announced';
                        
                        $message = "
Hello {$facultyName},

A new IPCR evaluation period has been opened:

PERIOD: {$period['name']}
SUBMISSION DEADLINE: {$deadlineText}

ACTION REQUIRED:
1. Login to the IPCR System: " . BASE_URL . "
2. Complete your IPCR form
3. Upload required evidence documents
4. Submit before the deadline

Your IPCR form has been automatically created and is ready for you to fill out.

Please don't wait until the last minute - start working on your IPCR as soon as possible to avoid any technical issues near the deadline.

If you have any questions, please contact your dean or the administrator.

---
This is an automated notification from ISU IPCR Management System.
Isabela State University - Ilagan Campus
";
                        
                        // Send email (non-blocking - errors won't stop the process)
                        @sendEmail($facultyEmail, $subject, $message);
                    }
                }
                
                $msg = "Period status updated to: {$next}";
            }
        } elseif ($next) {
            $db->prepare("UPDATE rating_periods SET status=?,updated_at=NOW() WHERE id=?")->execute([$next,$pid]);
            auditLog('status_change','period',$pid,$current,$next,"Period advanced to {$next}");
            $msg = "Period status updated to: {$next}";
        }
    }
}

$periods   = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();
$templates = $db->query("SELECT * FROM ipcr_templates ORDER BY name")->fetchAll();
$latestTemplate = $db->query("SELECT id FROM ipcr_templates ORDER BY created_at DESC LIMIT 1")->fetchColumn();

$nextStatus = ['draft'=>'Open','open'=>'Rating','rating'=>'Closed','closed'=>'Archived'];
$statusColors = ['draft'=>'secondary','open'=>'success','rating'=>'warning','closed'=>'dark','archived'=>'secondary'];
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-calendar3 me-2"></i>Rating Periods</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Create and manage IPCR submission and evaluation periods for each semester</p>
  </div>
  <?php if ($currentRole === 'secretary'): ?>
  <div class="alert alert-warning mb-0" style="font-size:.85rem;padding:8px 12px">
    <i class="bi bi-exclamation-triangle me-1"></i>Your edits will be submitted for dean approval before being applied.
  </div>
  <?php else: ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#periodModal" onclick="resetPeriodForm()">
    <i class="bi bi-calendar-plus me-2"></i>New Period
  </button>
  <?php endif; ?>
</div>

<?php if($msg): ?><div class="alert alert-<?= $msgType ?> mb-4"><?= h($msg) ?></div><?php endif; ?>

<div class="table-container">
  <table class="ipcr-table">
    <thead><tr><th>Period</th><th>Academic Year</th><th>Deadlines</th><th>Template</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if(!$periods): ?><tr><td colspan="6" class="text-center text-muted py-4">No periods yet.</td></tr><?php endif; ?>
      <?php foreach($periods as $p):
        $tmpl = null;
        if($p['template_id']){ $ts=$db->prepare("SELECT name FROM ipcr_templates WHERE id=?"); $ts->execute([$p['template_id']]); $tmpl=$ts->fetchColumn(); }
      ?>
      <tr>
        <td><strong><?= h($p['name']) ?></strong></td>
        <td><?= $p['academic_year'] ? h($p['academic_year']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted-sm">
          <div><strong>Submit:</strong> <?= $p['submission_deadline'] ? h(date('M d, Y',strtotime($p['submission_deadline']))) : '<span class="text-muted">—</span>' ?></div>
          <div><strong>Review:</strong> <?= $p['review_deadline'] ? h(date('M d, Y',strtotime($p['review_deadline']))) : '<span class="text-muted">—</span>' ?></div>
        </td>
        <td><?= $tmpl ? h($tmpl) : '<span class="text-danger small">None</span>' ?></td>
        <td><span class="badge bg-<?= $statusColors[$p['status']] ?? 'secondary' ?>"><?= h(ucfirst($p['status'])) ?></span></td>
        <td class="actions">
          <div class="d-flex gap-1 flex-wrap">
            <?php if(!in_array($p['status'],['closed','archived'])): ?>
              <?php if($currentRole === 'secretary'): ?>
              <!-- Secretary: Request Edit Button -->
              <button class="btn btn-sm btn-outline-warning" onclick="requestPeriodEdit(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)" title="Request Edit (Dean Approval Required)">
                <i class="bi bi-pencil-square"></i>
              </button>
              <?php else: ?>
              <!-- Admin/Dean: Direct Edit Button -->
              <button class="btn btn-sm btn-outline-primary" onclick="editPeriod(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)" title="Edit">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
            <?php endif; ?>
            
            <?php if($p['status'] === 'draft'): ?>
            <!-- Open Period -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="advance">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="current_status" value="draft">
              <input type="hidden" name="target_status" value="open">
              <button type="submit" class="btn btn-sm btn-success" title="Open Period for Submissions and Rating"
                onclick="return confirm('Open this period? Faculty can submit IPCRs and deans can rate them.')">
                <i class="bi bi-unlock me-1"></i>Open
              </button>
            </form>
            <?php elseif($p['status'] === 'open'): ?>
            <!-- Close Period -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="advance">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="current_status" value="open">
              <input type="hidden" name="target_status" value="closed">
              <button type="submit" class="btn btn-sm btn-danger" title="Close Period"
                onclick="return confirm('Close this period? No more submissions will be accepted.')">
                <i class="bi bi-lock me-1"></i>Close Period
              </button>
            </form>
            <?php elseif($p['status'] === 'rating'): ?>
            <!-- Close Period (for existing 'rating' status periods) -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="advance">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="current_status" value="rating">
              <input type="hidden" name="target_status" value="closed">
              <button type="submit" class="btn btn-sm btn-danger" title="Close Period"
                onclick="return confirm('Close this period? Ratings will be finalized.')">
                <i class="bi bi-lock me-1"></i>Close
              </button>
            </form>
            <?php elseif($p['status'] === 'closed'): ?>
            <!-- Archive -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="advance">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="current_status" value="closed">
              <input type="hidden" name="target_status" value="archived">
              <button type="submit" class="btn btn-sm btn-outline-secondary" title="Archive Period"
                onclick="return confirm('Archive this period?')">
                <i class="bi bi-archive me-1"></i>Archive
              </button>
            </form>
            <?php endif; ?>
            
            <!-- Delete Button (only for draft/closed/archived periods with no IPCR forms) -->
            <?php if (in_array($p['status'], ['draft', 'closed', 'archived'])): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger" title="Delete Period"
                onclick="return confirm('⚠️ DELETE this period permanently?\n\nPeriod: <?= addslashes($p['name']) ?>\n\nThis cannot be undone!')">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="periodModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="period-modal-title">New Rating Period</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="action" id="period-action" value="create">
          <input type="hidden" name="period_id" id="period-id">
          
          <!-- Quick Fill Buttons -->
          <div class="alert alert-info mb-3">
            <div class="d-flex align-items-center justify-content-between">
              <strong><i class="bi bi-lightning-fill me-2"></i>Quick Fill:</strong>
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary" onclick="quickFill1stSem()">
                  <i class="bi bi-1-circle me-1"></i>1st Sem
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="quickFill2ndSem()">
                  <i class="bi bi-2-circle me-1"></i>2nd Sem
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="quickFillSummer()">
                  <i class="bi bi-sun me-1"></i>Summer
                </button>
              </div>
            </div>
            <small class="text-muted d-block mt-1">Auto-fill dates based on typical semester schedules</small>
          </div>
          
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Period Name <span class="required-star">*</span></label>
              <input class="form-control" name="period_name" id="p-name" required 
                     placeholder="e.g., January - June 2026 (1st Semester)">
              <small class="text-muted">
                <i class="bi bi-lightbulb me-1"></i>
                <strong>Examples:</strong> 
                "January - June 2026 (1st Semester)", 
                "July - December 2026 (2nd Semester)", 
                "Summer 2026"
              </small>
            </div>
            <div class="col-12">
              <label class="form-label">Academic Year</label>
              <input class="form-control" name="academic_year" id="p-ay" placeholder="e.g. 2025-2026 (optional)">
              <small class="text-muted">Optional - for reference only</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Submission Deadline <span class="required-star">*</span></label>
              <input class="form-control" type="date" name="submission_deadline" id="p-subdl" required>
              <small class="text-muted">When faculty must submit IPCRs</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Review Deadline</label>
              <input class="form-control" type="date" name="review_deadline" id="p-revdl">
              <small class="text-muted">When deans must finish rating</small>
            </div>
            <div class="col-12">
              <label class="form-label">IPCR Template</label>
              <select class="form-select" name="template_id" id="p-tmpl">
                <option value="">— Not assigned —</option>
                <?php foreach($templates as $t): ?>
                <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Period</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
const latestTemplateId = <?= json_encode($latestTemplate ?: '') ?>;

function resetPeriodForm(){
  document.getElementById('period-modal-title').textContent='New Rating Period';
  document.getElementById('period-action').value='create';
  document.getElementById('period-id').value='';
  document.getElementById('p-name').value='';
  document.getElementById('p-ay').value='';
  document.getElementById('p-subdl').value='';
  document.getElementById('p-revdl').value='';
  // Set to latest template by default
  document.getElementById('p-tmpl').value = latestTemplateId;
}

function editPeriod(p){
  document.getElementById('period-modal-title').textContent='Edit Period';
  document.getElementById('period-action').value='edit';
  document.getElementById('period-id').value=p.id;
  document.getElementById('p-name').value=p.name||'';
  document.getElementById('p-ay').value=p.academic_year||'';
  document.getElementById('p-subdl').value=p.submission_deadline||'';
  document.getElementById('p-revdl').value=p.review_deadline||'';
  document.getElementById('p-tmpl').value=p.template_id||'';
  new bootstrap.Modal(document.getElementById('periodModal')).show();
}

// Quick Fill Functions
function quickFill1stSem() {
  const year = new Date().getFullYear();
  const nextYear = year + 1;
  
  document.getElementById('p-name').value = `January - June ${year} (1st Semester)`;
  document.getElementById('p-ay').value = `${year}-${nextYear}`;
  document.getElementById('p-subdl').value = `${year}-06-15`;
  document.getElementById('p-revdl').value = `${year}-06-25`;
  
  Toast.success('✓ 1st Semester filled! Adjust dates as needed.');
}

function quickFill2ndSem() {
  const year = new Date().getFullYear();
  const nextYear = year + 1;
  
  document.getElementById('p-name').value = `July - December ${year} (2nd Semester)`;
  document.getElementById('p-ay').value = `${year}-${nextYear}`;
  document.getElementById('p-subdl').value = `${year}-12-15`;
  document.getElementById('p-revdl').value = `${year}-12-25`;
  
  Toast.success('✓ 2nd Semester filled! Adjust dates as needed.');
}

function quickFillSummer() {
  const year = new Date().getFullYear();
  const nextYear = year + 1;
  
  document.getElementById('p-name').value = `Summer ${year}`;
  document.getElementById('p-ay').value = `${year}-${nextYear}`;
  document.getElementById('p-subdl').value = `${year}-05-20`;
  document.getElementById('p-revdl').value = `${year}-05-28`;
  
  Toast.success('✓ Summer period filled! Adjust dates as needed.');
}


// Secretary Request Edit
async function requestPeriodEdit(p) {
  const { value: formValues } = await Swal.fire({
    title: 'Request Period Edit',
    html: `
      <div class="alert alert-warning text-start mb-3" style="font-size:.88rem">
        <i class="bi bi-info-circle me-1"></i> Your changes will be submitted to the dean for approval.
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Period Name</label>
        <input id="req-name" class="swal2-input" style="width:95%;margin:0" value="${p.name}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Academic Year</label>
        <input id="req-ay" class="swal2-input" style="width:95%;margin:0" value="${p.academic_year}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Semester</label>
        <select id="req-sem" class="swal2-input" style="width:95%;margin:0">
          <option value="1st" ${p.semester==='1st'?'selected':''}>1st Semester</option>
          <option value="2nd" ${p.semester==='2nd'?'selected':''}>2nd Semester</option>
          <option value="summer" ${p.semester==='summer'?'selected':''}>Summer</option>
          <option value="annual" ${p.semester==='annual'?'selected':''}>Annual</option>
        </select>
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Start Date</label>
        <input type="date" id="req-start" class="swal2-input" style="width:95%;margin:0" value="${p.start_date||''}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">End Date</label>
        <input type="date" id="req-end" class="swal2-input" style="width:95%;margin:0" value="${p.end_date||''}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Submission Deadline</label>
        <input type="date" id="req-subdl" class="swal2-input" style="width:95%;margin:0" value="${p.submission_deadline||''}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Review Deadline</label>
        <input type="date" id="req-revdl" class="swal2-input" style="width:95%;margin:0" value="${p.review_deadline||''}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Template ID</label>
        <input id="req-tmpl" class="swal2-input" style="width:95%;margin:0" value="${p.template_id||''}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold text-danger">Remarks (Required) *</label>
        <textarea id="req-remarks" class="swal2-textarea" style="width:95%;margin:0" placeholder="Why are you requesting this change?"></textarea>
      </div>
    `,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: 'Submit Request',
    cancelButtonText: 'Cancel',
    width: '600px',
    preConfirm: () => {
      const remarks = document.getElementById('req-remarks').value.trim();
      if (!remarks) {
        Swal.showValidationMessage('Remarks are required');
        return false;
      }
      return {
        name: document.getElementById('req-name').value.trim(),
        academic_year: document.getElementById('req-ay').value.trim(),
        semester: document.getElementById('req-sem').value,
        start_date: document.getElementById('req-start').value,
        end_date: document.getElementById('req-end').value,
        submission_deadline: document.getElementById('req-subdl').value,
        review_deadline: document.getElementById('req-revdl').value,
        template_id: document.getElementById('req-tmpl').value || null,
        remarks: remarks
      };
    }
  });

  if (formValues) {
    try {
      const response = await fetch('<?= BASE_URL ?>/api/submit-edit-request.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          request_type: 'period',
          target_id: parseInt(p.id),
          request_data: {
            name: formValues.name,
            academic_year: formValues.academic_year,
            semester: formValues.semester,
            start_date: formValues.start_date,
            end_date: formValues.end_date,
            submission_deadline: formValues.submission_deadline,
            review_deadline: formValues.review_deadline,
            template_id: formValues.template_id
          },
          remarks: formValues.remarks
        })
      });

      const data = await response.json();
      
      if (data.success) {
        Toast.success('Edit request submitted for dean approval!');
        setTimeout(() => location.reload(), 1500);
      } else {
        Toast.error(data.message || 'Failed to submit request');
      }
    } catch (error) {
      Toast.error('Failed to submit request: ' + error.message);
    }
  }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

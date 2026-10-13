<?php
// Check and create form BEFORE including header (to avoid "headers already sent" error)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();
$currentUserId = $_SESSION['user_id'];
$currentUser = getCurrentUser();

$period = $db->query("SELECT * FROM rating_periods WHERE status IN ('open','rating') ORDER BY id DESC LIMIT 1")->fetch();

// Check if form exists, if not create it (before any output)
if ($period) {
    $formRow = $db->prepare("SELECT * FROM ipcr_forms WHERE user_id=? AND period_id=?");
    $formRow->execute([$currentUserId, $period['id']]);
    $form = $formRow->fetch();

    if (!$form && $period['status'] === 'open' && $period['template_id'] && $currentUser['assigned_dean_id']) {
        $db->prepare("INSERT INTO ipcr_forms (period_id, user_id, template_id) VALUES (?,?,?)")
           ->execute([$period['id'], $currentUserId, $period['template_id']]);
        $newId = $db->lastInsertId();
        $db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
           ->execute([$newId, 'created', $currentUserId, 'IPCR form created.']);
        auditLog('created', 'ipcr_form', $newId, null, 'draft', 'IPCR form created');
        header("Location: {$_SERVER['REQUEST_URI']}"); 
        exit;
    }
}

// NOW include header (after redirect check)
$pageTitle  = 'My IPCR Form';
$activePage = 'ipcr-form';
require_once __DIR__ . '/../includes/header.php';

// Re-fetch form after header include
if ($period) {
    $formRow = $db->prepare("SELECT * FROM ipcr_forms WHERE user_id=? AND period_id=?");
    $formRow->execute([$currentUserId, $period['id']]);
    $form = $formRow->fetch();
}

if (!$period) {
    echo '<div class="alert alert-info d-flex align-items-center gap-3 p-4"><i class="bi bi-calendar-x fs-4"></i><div><strong>No Active Evaluation Period</strong><br><span class="text-muted">There is currently no open evaluation period. Please check back later or contact the Administrator.</span></div></div>';
    require_once __DIR__ . '/../includes/footer.php'; exit;
}

if (!$form) {
    // Show appropriate error message
    if ($period['status'] !== 'open') {
        echo '<div class="alert alert-warning d-flex align-items-center gap-3 p-4">
            <i class="bi bi-lock fs-4"></i>
            <div>
                <strong>Submission Period Closed</strong><br>
                <span class="text-muted">The submission period has ended. You cannot create a new IPCR form at this time. If you previously submitted an IPCR, you can view it in your <a href="'.BASE_URL.'/faculty/history.php">Evaluation History</a>.</span>
            </div>
        </div>';
        require_once __DIR__ . '/../includes/footer.php'; exit;
    }
    if (!$period['template_id']) {
        echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>No IPCR template assigned. Contact Administrator.</div>';
        require_once __DIR__ . '/../includes/footer.php'; exit;
    }
    if (!$currentUser['assigned_dean_id']) {
        echo '<div class="alert alert-warning"><i class="bi bi-person-x me-2"></i>No evaluator assigned to your account. Contact HR Administrator.</div>';
        require_once __DIR__ . '/../includes/footer.php'; exit;
    }
}

$isEditable = in_array($form['status'], ['draft','returned']);

$rows = $db->prepare("
    SELECT m.id mfo_id, m.code mfo_code, m.title mfo_title, m.weight mfo_weight,
           k.id kra_id, k.code kra_code, k.title kra_title, k.weight kra_weight,
           s.id si_id, s.code si_code, s.title si_title,
           s.quality_target, s.efficiency_target, s.timeliness_target,
           s.si_weight, s.is_required, s.sort_order si_order,
           e.planned_magnitude, e.planned_budget, e.planned_date,
           e.actual_magnitude, e.actual_budget, e.actual_date, e.actual_output,
           e.q_rating, e.e_rating, e.t_rating, e.average_rating,
           e.dean_remarks, e.dean_verify_status, e.is_na
    FROM mfos m
    JOIN kras k ON k.mfo_id=m.id
    JOIN success_indicators s ON s.kra_id=k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id=s.id AND e.form_id=?
    WHERE m.template_id=?
    ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$rows->execute([$form['id'], $form['template_id']]);
$allRows = $rows->fetchAll();

$mfos = [];
foreach ($allRows as $row) {
    $mid=$row['mfo_id']; $kid=$row['kra_id'];
    if (!isset($mfos[$mid])) $mfos[$mid]=['code'=>$row['mfo_code'],'title'=>$row['mfo_title'],'weight'=>$row['mfo_weight'],'kras'=>[]];
    if (!isset($mfos[$mid]['kras'][$kid])) $mfos[$mid]['kras'][$kid]=['code'=>$row['kra_code'],'title'=>$row['kra_title'],'weight'=>$row['kra_weight'],'sis'=>[]];
    $mfos[$mid]['kras'][$kid]['sis'][]=$row;
}

$evidenceMap = [];
$evRows = $db->prepare("SELECT * FROM evidence_files WHERE form_id=? ORDER BY uploaded_at ASC");
$evRows->execute([$form['id']]);
foreach ($evRows->fetchAll() as $ev) $evidenceMap[$ev['si_id']][] = $ev;

$proofTypes = [];
$ptRows = $db->prepare("SELECT rp.* FROM required_proof_types rp JOIN success_indicators s ON rp.si_id=s.id JOIN kras k ON s.kra_id=k.id JOIN mfos m ON k.mfo_id=m.id WHERE m.template_id=?");
$ptRows->execute([$form['template_id']]);
foreach ($ptRows->fetchAll() as $pt) $proofTypes[$pt['si_id']][] = $pt;

$dean = null;
if ($currentUser['assigned_dean_id']) {
    $ds=$db->prepare("SELECT * FROM users WHERE id=?"); $ds->execute([$currentUser['assigned_dean_id']]); $dean=$ds->fetch();
}

$qW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn()?:40);
$eW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn()?:40);
$tW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn()?:20);

// Deadline alert
$deadlineDays = null;
if ($period['submission_deadline']) {
    $deadlineDays = (int)ceil((strtotime($period['submission_deadline'].' 23:59:59') - time()) / 86400);
}

// Count filled SIs (check actual_output, actual_magnitude, OR evidence files)
// Count ALL SIs (both required and optional)
$totalSI = 0; $filledSI = 0;
$debugInfo = []; // For debugging
foreach ($allRows as $r) {
    // Count all SIs, not just required ones
    $totalSI++;
    
    // Count as filled if:
    // 1. Has actual output/narrative text, OR
    // 2. Has actual magnitude, OR  
    // 3. Has evidence files attached
    $hasEvidence = isset($evidenceMap[$r['si_id']]) && count($evidenceMap[$r['si_id']]) > 0;
    $hasText = !empty($r['actual_output']) || !empty($r['actual_magnitude']);
    
    if ($hasText || $hasEvidence) {
        $filledSI++;
        $debugInfo[] = "SI {$r['si_code']}: text=" . ($hasText ? 'yes' : 'no') . ", files=" . ($hasEvidence ? count($evidenceMap[$r['si_id']]) : '0');
    }
}
$progress = $totalSI > 0 ? round($filledSI/$totalSI*100) : 0;

// Debug output - TEMPORARY (remove after checking)
if ($filledSI === 0 && !empty($evidenceMap)) {
    echo "<!-- DEBUG: Total SI={$totalSI}, Filled={$filledSI}, Evidence count=".count($evidenceMap)." -->";
    echo "<!-- DEBUG Info: " . json_encode($debugInfo) . " -->";
}

function evIcon(string $ext): array {
    return match(strtolower($ext)) {
        'pdf'        => ['bi-file-earmark-pdf-fill',  'ev-file-pdf'],
        'doc','docx' => ['bi-file-earmark-word-fill', 'ev-file-doc'],
        'xls','xlsx' => ['bi-file-earmark-excel-fill','ev-file-xls'],
        'jpg','jpeg','png','gif','webp' => ['bi-file-earmark-image-fill','ev-file-img'],
        default      => ['bi-file-earmark-fill',       'ev-file-other'],
    };
}
?>
<style>
/* ── IPCR FORM PAGE STYLES ── */
.ipcr-page-header {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
  border-radius: var(--border-r);
  padding: 20px 24px;
  color: #fff;
  margin-bottom: 20px;
  border-left: 5px solid var(--accent);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.ipcr-page-header h2 { font-size: 1.1rem; font-weight: 800; margin: 0 0 6px; }

.ipcr-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: .78rem; opacity: .9; }
.ipcr-meta-item {
  display: flex; align-items: center; gap: 5px;
  background: rgba(255,255,255,.12);
  padding: 3px 10px; border-radius: 20px;
}

.progress-ring { display: flex; align-items: center; gap: 10px; color: #fff; }
.progress-ring .num { font-size: 1.5rem; font-weight: 800; line-height: 1; }
.progress-ring .lbl { font-size: .72rem; opacity: .75; }

/* ── DEADLINE BANNER ── */
.deadline-banner {
  background: linear-gradient(90deg, #fff8e1, #fffde7);
  border: 1px solid #ffe082;
  border-left: 4px solid var(--accent);
  border-radius: var(--border-r);
  padding: 10px 16px;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: .85rem;
  color: #7c5200;
}

/* ── MFO SECTION ── */
.mfo-section {
  background: #fff;
  border: 1.5px solid var(--gray-200);
  border-radius: var(--border-r);
  margin-bottom: 16px;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.mfo-section-header {
  background: linear-gradient(90deg, var(--primary-dark), var(--primary));
  color: #fff;
  padding: 12px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  user-select: none;
  border-left: 4px solid var(--accent);
}

.mfo-section-header:hover { filter: brightness(1.08); }

.mfo-section-header h5 {
  margin: 0;
  font-size: .92rem;
  font-weight: 700;
  display: flex; align-items: center; gap: 8px;
}

.mfo-badge {
  background: rgba(255,255,255,.18);
  color: #fff;
  font-size: .7rem;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.25);
}

/* ── KRA HEADER ── */
.kra-section-header {
  background: linear-gradient(90deg, #edf7f1, #f8fdf9);
  border-bottom: 1px solid var(--gray-200);
  border-left: 3px solid var(--primary-light);
  padding: 8px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.kra-section-header h6 {
  margin: 0;
  font-size: .82rem;
  font-weight: 700;
  color: var(--primary);
  display: flex; align-items: center; gap: 7px;
}

/* ── SI CARD ── */
.si-card {
  border-bottom: 1px solid var(--gray-100);
  padding: 0;
  transition: background .1s ease;
}

.si-card:last-child { border-bottom: none; }

.si-card-header {
  padding: 10px 20px 8px;
  border-bottom: 1px dashed var(--gray-200);
  background: #fafcfa;
}

.si-number {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px; height: 22px;
  background: var(--primary);
  color: #fff;
  border-radius: 50%;
  font-size: .65rem;
  font-weight: 700;
  flex-shrink: 0;
}

.si-title-text {
  font-size: .88rem;
  font-weight: 600;
  color: var(--gray-800);
  line-height: 1.3;
}

.si-weight-badge {
  background: var(--primary-xlight);
  color: var(--primary);
  border: 1px solid #b8d9c4;
  font-size: .68rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
}

.si-targets-row {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 6px;
}

.target-chip {
  background: #f0f7f3;
  border: 1px solid #c0dcc8;
  border-radius: 6px;
  padding: 2px 8px;
  font-size: .7rem;
  color: var(--primary);
  display: flex; align-items: center; gap: 4px;
}

.target-chip strong { color: var(--primary-dark); }

/* ── SI BODY ── */
.si-card-body { padding: 14px 20px; }

.section-label {
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--gray-500);
  margin-bottom: 8px;
  padding-bottom: 4px;
  border-bottom: 1.5px solid var(--gray-200);
  display: flex; align-items: center; gap: 6px;
}

.section-label i { font-size: .75rem; }

/* Input grid */
.input-grid-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
}

.input-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.field-group label {
  font-size: .75rem;
  font-weight: 600;
  color: var(--gray-600);
  margin-bottom: 4px;
  display: block;
}

.field-group input, .field-group textarea {
  font-size: .85rem;
}

.plain-val {
  font-size: .85rem;
  color: var(--gray-800);
  padding: 6px 0;
  font-weight: 500;
  min-height: 32px;
}

.plain-val.highlight { color: var(--primary); font-weight: 700; }

/* ── EVIDENCE SECTION ── */
.evidence-section {
  margin-top: 14px;
  background: var(--gray-50);
  border: 1.5px dashed var(--gray-300);
  border-radius: var(--border-r);
  padding: 12px 16px;
  transition: border-color .2s ease;
}

.evidence-section:hover { border-color: var(--primary-light); }

.evidence-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.evidence-title {
  font-size: .78rem;
  font-weight: 700;
  color: var(--gray-700);
  display: flex; align-items: center; gap: 6px;
}

.btn-attach {
  background: var(--primary);
  color: #fff;
  border: none;
  padding: 5px 14px;
  border-radius: 6px;
  font-size: .76rem;
  font-weight: 600;
  cursor: pointer;
  display: flex; align-items: center; gap: 5px;
  transition: var(--transition);
}

.btn-attach:hover { background: var(--primary-light); }

.required-proofs {
  display: flex; flex-wrap: wrap; gap: 5px;
  margin-bottom: 10px;
}

.proof-chip {
  font-size: .68rem;
  padding: 2px 8px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid var(--gray-300);
  color: var(--gray-600);
  display: flex; align-items: center; gap: 4px;
}

.proof-chip.required { border-color: #fca5a5; background: #fff5f5; color: #b91c1c; }

.ev-file-list { display: flex; flex-direction: column; gap: 6px; }

.ev-file-row {
  background: #fff;
  border: 1px solid var(--gray-200);
  border-radius: var(--border-r-sm);
  padding: 8px 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: var(--transition);
  position: relative;
}

.ev-file-row:hover { border-color: #b8d9c4; box-shadow: 0 1px 6px rgba(27,94,53,.07); }

.ev-file-icon-sm {
  width: 32px; height: 32px;
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}

.ev-file-info-sm { flex: 1; min-width: 0; }
.ev-fname { font-size: .8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ev-fmeta { font-size: .7rem; color: var(--gray-500); }

.ev-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

.btn-dl {
  background: none; border: 1px solid var(--gray-300); color: var(--gray-600);
  padding: 3px 8px; border-radius: 5px; font-size: .72rem; cursor: pointer;
  display: flex; align-items: center; gap: 3px; text-decoration: none;
  transition: var(--transition);
}
.btn-dl:hover { border-color: var(--primary); color: var(--primary); }

.btn-rm {
  background: none; border: none; color: #fca5a5; cursor: pointer;
  padding: 3px 5px; border-radius: 5px; font-size: .85rem; line-height: 1;
  transition: var(--transition);
}
.btn-rm:hover { background: #fff5f5; color: var(--danger); }

.empty-ev {
  text-align: center; padding: 14px;
  color: var(--gray-400); font-size: .8rem;
}

.empty-ev i { font-size: 1.5rem; display: block; margin-bottom: 4px; }

/* ── DEAN FEEDBACK ── */
.dean-feedback {
  margin-top: 12px;
  background: linear-gradient(90deg, #edf7f1, #f8fdf9);
  border: 1px solid #b8d9c4;
  border-left: 3px solid var(--success);
  border-radius: var(--border-r);
  padding: 10px 14px;
}

.dean-feedback-title {
  font-size: .72rem;
  font-weight: 700;
  color: var(--success);
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 8px;
  display: flex; align-items: center; gap: 5px;
}

.rating-pills { display: flex; gap: 8px; flex-wrap: wrap; }

.r-pill {
  background: #fff;
  border: 1.5px solid var(--gray-200);
  border-radius: 8px;
  padding: 4px 12px;
  font-size: .8rem;
  font-weight: 700;
  color: var(--primary);
  display: flex; align-items: center; gap: 4px;
}

.r-pill .lbl { font-size: .65rem; font-weight: 400; color: var(--gray-500); }

/* ── STICKY BOTTOM BAR ── */
.form-bottom-bar {
  position: sticky;
  bottom: 0;
  background: #fff;
  border-top: 2px solid var(--gray-200);
  padding: 12px 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  box-shadow: 0 -4px 20px rgba(0,0,0,.08);
  z-index: 100;
  margin: 0 -32px -28px;
}

.save-status {
  display: flex; align-items: center; gap: 6px;
  font-size: .78rem; color: var(--gray-500);
}

.save-status i { color: var(--success); }

.progress-bar-wrap { flex: 1; max-width: 180px; }
.progress-bar-wrap .label { font-size: .7rem; color: var(--gray-500); margin-bottom: 3px; }
</style>

<input type="hidden" id="qet-q-weight" value="<?= $qW ?>">
<input type="hidden" id="qet-e-weight" value="<?= $eW ?>">
<input type="hidden" id="qet-t-weight" value="<?= $tW ?>">

<!-- ── IPCR HEADER ── -->
<div class="ipcr-page-header">
  <div>
    <h2><i class="bi bi-file-earmark-person me-2"></i>IPCR Form</h2>
    <div class="ipcr-meta">
      <span class="ipcr-meta-item"><i class="bi bi-person"></i><?= h(fullName($currentUser)) ?></span>
      <span class="ipcr-meta-item"><i class="bi bi-building"></i><?= h($currentUser['department']??'—') ?></span>
      <span class="ipcr-meta-item"><i class="bi bi-briefcase"></i><?= h($currentUser['position']??'—') ?></span>
      <span class="ipcr-meta-item"><i class="bi bi-calendar3"></i><?= h($period['name']) ?></span>
      <?php if($dean): ?>
      <span class="ipcr-meta-item"><i class="bi bi-person-check"></i>Evaluator: <?= h(fullName($dean)) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex align-items-center gap-3">
    <?= statusBadge($form['status']) ?>
    <div class="progress-ring text-end">
      <div>
        <div class="num"><?= $filledSI ?>/<?= $totalSI ?></div>
        <div class="lbl">SI Filled</div>
      </div>
      <div style="width:42px;height:42px;position:relative;flex-shrink:0">
        <svg viewBox="0 0 42 42" style="width:42px;height:42px;transform:rotate(-90deg)">
          <circle cx="21" cy="21" r="16" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="4"/>
          <circle cx="21" cy="21" r="16" fill="none" stroke="<?= $progress>=100?'#f0c940':'rgba(255,255,255,.8)' ?>"
            stroke-width="4" stroke-dasharray="<?= round($progress*1.005) ?> 100.5"
            stroke-linecap="round"/>
        </svg>
      </div>
    </div>
  </div>
</div>

<?php if($form['status']==='returned' && $form['return_remarks']): ?>
<div class="alert d-flex gap-3 mb-4" style="background:#fff8ec;border:1px solid #ffe082;border-left:4px solid var(--accent);border-radius:var(--border-r);padding:14px 18px">
  <i class="bi bi-arrow-return-left text-warning fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <div class="fw-bold mb-1">Returned for Revision</div>
    <div class="text-muted" style="font-size:.87rem"><?= h($form['return_remarks']) ?></div>
  </div>
</div>
<?php endif; ?>

<?php if(!$isEditable): ?>
<div class="alert d-flex align-items-center gap-3 mb-4" style="background:var(--primary-xlight);border:1px solid #b8d9c4;border-radius:var(--border-r);padding:12px 18px;color:var(--primary)">
  <i class="bi bi-lock-fill fs-5"></i>
  <div>This IPCR is <strong><?= h(ucfirst($form['status'])) ?></strong> and cannot be edited.
  <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $form['id'] ?>" class="fw-bold ms-2">View Full IPCR →</a></div>
</div>
<?php endif; ?>

<?php if($deadlineDays !== null && $period['status']==='open' && $isEditable): ?>
<div class="deadline-banner">
  <i class="bi bi-clock-history"></i>
  <span>
    <strong>Submission Deadline:</strong> <?= h(date('F d, Y', strtotime($period['submission_deadline']))) ?>
    — <?= $deadlineDays > 0
        ? "<strong>{$deadlineDays} day(s) remaining</strong>"
        : '<strong style="color:#dc2626">DEADLINE HAS PASSED</strong>' ?>
  </span>
</div>
<?php endif; ?>

<!-- ── MFO SECTIONS ── -->
<?php foreach($mfos as $mfoId => $mfo): ?>
<div class="mfo-section">

  <div class="mfo-section-header" onclick="toggleMFO(<?= $mfoId ?>)">
    <h5>
      <i class="bi bi-chevron-down" id="mfo-icon-<?= $mfoId ?>"></i>
      <?= h($mfo['code']) ?>: <?= h($mfo['title']) ?>
    </h5>
    <span class="mfo-badge"><?= $mfo['weight'] ?>% of Total</span>
  </div>

  <div id="mfo-body-<?= $mfoId ?>">
  <?php foreach($mfo['kras'] as $kraId => $kra): ?>

    <div class="kra-section-header">
      <h6>
        <i class="bi bi-layers"></i>
        <?= h($kra['code']) ?>: <?= h($kra['title']) ?>
      </h6>
      <span class="badge" style="background:var(--primary-xlight);color:var(--primary);border:1px solid #b8d9c4;font-size:.7rem">
        KRA Weight: <?= $kra['weight'] ?>%
      </span>
    </div>

    <?php foreach($kra['sis'] as $idx => $si):
      $siEvidence = $evidenceMap[$si['si_id']] ?? [];
      $siProofs   = $proofTypes[$si['si_id']] ?? [];
      $reqCount   = count(array_filter($siProofs, fn($p)=>$p['is_required']));
      $evCount    = count($siEvidence);
      $hasDeanFeedback = $si['q_rating'] !== null;
    ?>
    <div class="si-card">

      <!-- SI Header -->
      <div class="si-card-header">
        <div class="d-flex align-items-start gap-2">
          <span class="si-number mt-1"><?= $idx+1 ?></span>
          <div style="flex:1">
            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
              <div class="si-title-text">
                <?= h($si['si_title']) ?>
                <?php if(!$si['is_required']): ?>
                <span class="badge bg-secondary ms-1" style="font-size:.6rem;vertical-align:middle">Optional</span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <?php if($si['si_weight']>0): ?>
                <span class="si-weight-badge">Wt: <?= $si['si_weight'] ?>%</span>
                <?php endif; ?>
                <?php if($si['is_required']): ?>
                <span style="color:var(--danger);font-size:.8rem">*</span>
                <?php endif; ?>
              </div>
            </div>
            <!-- Target chips -->
            <?php if($si['quality_target']||$si['efficiency_target']||$si['timeliness_target']): ?>
            <div class="si-targets-row">
              <?php if($si['quality_target']): ?>
              <span class="target-chip"><strong>Target:</strong> <?= h($si['quality_target']) ?></span>
              <?php endif; ?>
              <?php if($si['efficiency_target']): ?>
              <span class="target-chip"><strong>Efficiency:</strong> <?= h($si['efficiency_target']) ?></span>
              <?php endif; ?>
              <?php if($si['timeliness_target']): ?>
              <span class="target-chip"><i class="bi bi-clock"></i> <?= h($si['timeliness_target']) ?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- SI Body -->
      <div class="si-card-body">
        <div class="row g-3">

          <!-- LEFT: Planned & Actual inputs -->
          <div class="col-lg-7">

            <!-- Planned -->
            <div class="section-label"><i class="bi bi-calendar2-check"></i> Planned</div>
            <div class="input-grid-3 mb-3">
              <div class="field-group">
                <label>Magnitude</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm planned-magnitude"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['planned_magnitude']??'') ?>"
                  placeholder="e.g. 21">
                <?php else: ?>
                <div class="plain-val"><?= h($si['planned_magnitude']??'—') ?></div>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label>Budget (₱)</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm planned-budget"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['planned_budget']??'') ?>"
                  placeholder="Optional">
                <?php else: ?>
                <div class="plain-val"><?= h($si['planned_budget']??'—') ?></div>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label>Date</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm planned-date"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['planned_date']??'') ?>"
                  placeholder="e.g. Jan–Jun 2026">
                <?php else: ?>
                <div class="plain-val"><?= h($si['planned_date']??'—') ?></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Actual -->
            <div class="section-label"><i class="bi bi-check2-circle"></i> Actual Accomplishment</div>
            <div class="input-grid-3 mb-3">
              <div class="field-group">
                <label>Magnitude</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm actual-magnitude"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['actual_magnitude']??'') ?>"
                  placeholder="Actual count">
                <?php else: ?>
                <div class="plain-val highlight"><?= h($si['actual_magnitude']??'—') ?></div>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label>Budget (₱)</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm actual-budget"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['actual_budget']??'') ?>"
                  placeholder="Actual">
                <?php else: ?>
                <div class="plain-val"><?= h($si['actual_budget']??'—') ?></div>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label>Date</label>
                <?php if($isEditable): ?>
                <input type="text" class="form-control form-control-sm actual-date"
                  data-si-id="<?= $si['si_id'] ?>"
                  value="<?= h($si['actual_date']??'') ?>"
                  placeholder="Actual date">
                <?php else: ?>
                <div class="plain-val"><?= h($si['actual_date']??'—') ?></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Output narrative -->
            <div class="field-group">
              <label>
                Narrative / Accomplishment Description
                <?php if($si['is_required']): ?><span style="color:var(--danger)">*</span><?php endif; ?>
              </label>
              <?php if($isEditable): ?>
              <textarea class="form-control actual-output"
                rows="2" data-si-id="<?= $si['si_id'] ?>"
                placeholder="Briefly describe what you actually accomplished..."
                style="font-size:.85rem;resize:vertical"><?= h($si['actual_output']??'') ?></textarea>
              <?php else: ?>
              <div class="p-2 rounded border" style="background:var(--gray-50);min-height:36px;font-size:.85rem">
                <?= $si['actual_output'] ? h($si['actual_output']) : '<em class="text-muted">Not provided</em>' ?>
              </div>
              <?php endif; ?>
            </div>

            <!-- Dean feedback (if rated) -->
            <?php if($hasDeanFeedback): ?>
            <div class="dean-feedback mt-3">
              <div class="dean-feedback-title"><i class="bi bi-person-check-fill"></i> Dean's Evaluation</div>
              <div class="rating-pills">
                <div class="r-pill"><span class="lbl">Quality</span> <?= h($si['q_rating']) ?></div>
                <div class="r-pill"><span class="lbl">Efficiency</span> <?= h($si['e_rating']) ?></div>
                <div class="r-pill"><span class="lbl">Timeliness</span> <?= h($si['t_rating']) ?></div>
                <div class="r-pill" style="background:var(--primary-xlight)">
                  <span class="lbl">Average</span>
                  <strong><?= $si['average_rating'] ? number_format($si['average_rating'], 2) : '—' ?></strong>
                </div>
                <?= evBadge($si['dean_verify_status']??'pending') ?>
              </div>
              <?php if($si['dean_remarks']): ?>
              <div class="mt-2 text-muted" style="font-size:.8rem">
                <i class="bi bi-chat-left-text me-1"></i><?= h($si['dean_remarks']) ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>

          <!-- RIGHT: Evidence Upload -->
          <div class="col-lg-5">
            <div class="evidence-section">
              <div class="evidence-section-header">
                <div class="evidence-title">
                  <i class="bi bi-paperclip"></i>
                  Supporting Evidence
                  <?php if($evCount>0): ?>
                  <span class="badge" style="background:var(--success);color:#fff;font-size:.65rem"><?= $evCount ?> file(s)</span>
                  <?php endif; ?>
                </div>
                <?php if($isEditable): ?>
                <label class="btn-attach" for="ev-upload-<?= $si['si_id'] ?>">
                  <i class="bi bi-upload"></i> Attach
                </label>
                <input type="file" id="ev-upload-<?= $si['si_id'] ?>"
                  class="d-none ev-file-input"
                  accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp"
                  data-si-id="<?= $si['si_id'] ?>"
                  data-form-id="<?= $form['id'] ?>"
                  multiple>
                <?php endif; ?>
              </div>

              <!-- Suggested evidence types (informational only) -->
              <?php if($siProofs): ?>
              <div class="required-proofs">
                <?php foreach($siProofs as $pt): ?>
                <span class="proof-chip">
                  <i class="bi bi-paperclip"></i>
                  <?= h($pt['label']) ?>
                </span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <div style="font-size:.7rem;color:var(--gray-500);margin-bottom:6px">
                <i class="bi bi-info-circle me-1"></i>
                Tip: Upload as <strong>PDF</strong> for instant in-browser preview.
              </div>

              <!-- File list -->
              <div class="ev-file-list" id="ev-list-<?= $si['si_id'] ?>">
                <?php if(empty($siEvidence)): ?>
                <div class="empty-ev">
                  <i class="bi bi-folder2-open"></i>
                  No files attached yet
                </div>
                <?php else: ?>
                <?php foreach($siEvidence as $ev):
                  $ext = strtolower(pathinfo($ev['original_name'], PATHINFO_EXTENSION));
                  [$ico, $cls] = evIcon($ext);
                ?>
                <div class="ev-file-row" id="ev-item-<?= $ev['id'] ?>">
                  <div class="ev-file-icon-sm <?= $cls ?>"><i class="bi <?= $ico ?>"></i></div>
                  <div class="ev-file-info-sm">
                    <div class="ev-fname" title="<?= h($ev['original_name']) ?>"><?= h($ev['original_name']) ?></div>
                    <div class="ev-fmeta">
                      <?= $ev['file_size'] ? formatFileSize($ev['file_size']).' · ' : '' ?>
                      <?= h(date('M d, Y', strtotime($ev['uploaded_at']))) ?>
                    </div>
                  </div>
                  <div class="ev-actions">
                    <button onclick="previewFile(<?= $ev['id'] ?>, '<?= addslashes(h($ev['original_name'])) ?>', '<?= addslashes($ev['file_type']??'') ?>')"
                       class="btn-dl">
                      <i class="bi bi-eye"></i> View
                    </button>
                    <?php if($isEditable): ?>
                    <button class="ai-verify-btn btn-dl"
                      data-file-id="<?= $ev['id'] ?>"
                      data-si-id="<?= $si['si_id'] ?>"
                      data-filename="<?= h(addslashes($ev['original_name'])) ?>"
                      style="border-color:#7c3aed;color:#7c3aed">
                      <i class="bi bi-stars"></i> AI
                    </button>
                    <button class="btn-rm"
                      onclick="removeEvidence(<?= $ev['id'] ?>, '<?= h(addslashes($ev['original_name'])) ?>')"
                      title="Remove">
                      <i class="bi bi-x-lg"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>

            </div><!-- /evidence-section -->
          </div>

        </div><!-- /row -->
      </div><!-- /si-card-body -->
    </div><!-- /si-card -->
    <?php endforeach; ?>

  <?php endforeach; ?>
  </div><!-- /mfo-body -->
</div><!-- /mfo-section -->
<?php endforeach; ?>

<!-- ── BOTTOM ACTION BAR ── -->
<?php if($isEditable): ?>
<div class="form-bottom-bar">
  <div class="d-flex align-items-center gap-4">
    <div class="save-status">
      <i class="bi bi-cloud-check"></i>
      <span id="autosave-txt">Changes auto-save every 30s</span>
    </div>
    <div class="progress-bar-wrap">
      <div class="label"><?= $filledSI ?>/<?= $totalSI ?> indicators filled (<?= $progress ?>%)</div>
      <div class="progress" style="height:6px">
        <div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $form['id'] ?>" target="_blank"
       class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Print
    </a>
    <button class="btn btn-sm btn-outline-primary" id="btn-save-draft">
      <i class="bi bi-save me-1"></i>Save Draft
    </button>
    <button class="btn btn-primary" id="btn-submit-ipcr">
      <i class="bi bi-send me-1"></i>Submit IPCR
    </button>
  </div>
</div>
<?php else: ?>
<div class="d-flex gap-2 mt-4 flex-wrap pb-4">
  <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">← Dashboard</a>
  <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $form['id'] ?>" class="btn btn-outline-primary">
    <i class="bi bi-eye me-1"></i>View Full IPCR
  </a>
  <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $form['id'] ?>" target="_blank"
     class="btn btn-primary">
    <i class="bi bi-printer me-1"></i>Print / Download PDF
  </a>
</div>
<?php endif; ?>

<script>
// Version 2.0 - Fixed AI advisory mode blocking issue (2026-10-13)
window.FORM_ID  = <?= $form['id'] ?>;
window.BASE_URL = '<?= BASE_URL ?>';
window.RATING_SCALE = <?= $db->query("SELECT config_value FROM system_config WHERE config_key='rating_scale'")->fetchColumn() ?: '[]' ?>;

function toggleMFO(id) {
  const body = document.getElementById('mfo-body-'+id);
  const icon = document.getElementById('mfo-icon-'+id);
  if (!body) return;
  const hidden = body.style.display === 'none';
  body.style.display = hidden ? '' : 'none';
  icon.className = hidden ? 'bi bi-chevron-down' : 'bi bi-chevron-right';
}

function removeEvidence(fileId, name) {
  if (!confirm('Remove "' + name + '"?')) return;
  fetch(BASE_URL + '/api/delete-evidence.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({file_id: fileId})
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      document.getElementById('ev-item-'+fileId)?.remove();
      Toast.success('File removed.');
    } else {
      Toast.error(d.message || 'Could not remove file.');
    }
  });
}

// ============================================================
// FILE UPLOAD HANDLER
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
  // Track upload in progress to prevent duplicates
  const uploadInProgress = new Set();
  
  // Listen to all file input changes
  document.querySelectorAll('.ev-file-input').forEach(input => {
    input.addEventListener('change', async function(e) {
      const siId = this.dataset.siId;
      const file = e.target.files[0];
      
      if (!file) return;
      
      // Prevent duplicate upload of same file
      const uploadKey = `${siId}-${file.name}-${file.size}`;
      if (uploadInProgress.has(uploadKey)) {
        return;
      }
      uploadInProgress.add(uploadKey);
      
      // Validate file size (max 10MB)
      const maxSize = 10 * 1024 * 1024; // 10MB
      if (file.size > maxSize) {
        Toast.error('File too large. Maximum size is 10MB.');
        this.value = ''; // Clear input
        uploadInProgress.delete(uploadKey);
        return;
      }
      
      // Show uploading toast
      Toast.info('Analyzing file with AI...');
      
      // ── Upload with AI Check (single API call) ──────────────────────────
      const formData = new FormData();
      formData.append('evidence', file);
      formData.append('form_id', window.FORM_ID);
      formData.append('si_id', siId);
      formData.append('description', '');
      
      try {
        const res = await fetch(BASE_URL + '/api/upload-evidence.php', {
          method: 'POST',
          body: formData
        });
        
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          const text = await res.text();
          console.error('Server response (HTML):', text);
          Toast.error('Server error - check browser console');
          this.value = '';
          return;
        }
        
        const data = await res.json();
        
        // Handle AI blocked upload
        // ONLY block if backend explicitly blocks (strict mode only)
        // In advisory/disabled mode, backend never sends ai_blocked=true
        if (!data.success && data.ai_blocked === true) {
          Swal.fire({
            icon: 'error',
            title: '❌ Upload Blocked by AI Verification',
            html: `
              <div style="text-align:left; max-width:550px">
                <div class="alert alert-danger mb-3" style="font-size:1.1rem">
                  <i class="bi bi-x-circle-fill me-2"></i>
                  <strong>INVALID Evidence (${data.confidence || data.ai_confidence || 0}% confidence)</strong>
                </div>
                
                <div class="mb-3 p-3" style="background:#fff3cd; border-left:4px solid #ffc107; border-radius:6px">
                  <strong style="color:#856404">❌ Wrong File Type Detected</strong><br>
                  <span style="color:#856404; font-size:0.95rem">The file you uploaded does not match the required evidence type.</span>
                </div>
                
                <p><strong>📄 Your File:</strong> <code>${file.name}</code></p>
                <p><strong>🔍 AI Detected:</strong> ${data.document_type || 'Unknown/Unrelated document'}</p>
                <p><strong>⚠️ Reason:</strong><br>${data.reason || data.ai_reason || 'This file does not match the required evidence type for this Success Indicator.'}</p>
                
                ${data.suggestions || data.ai_suggestions ? `
                  <div class="alert alert-info mt-3" style="background:#e7f3ff">
                    <strong>💡 What you should upload instead:</strong><br>
                    ${data.suggestions || data.ai_suggestions}
                  </div>
                ` : ''}
                
                <div class="mt-3 p-2" style="background:#f8f9fa; border-radius:6px; font-size:0.9rem">
                  <strong>ℹ️ Note:</strong> AI verification is in <strong>strict mode</strong>. Contact your administrator to change to advisory mode if you believe this is a valid file.
                </div>
              </div>
            `,
            confirmButtonText: 'OK, I\'ll Upload Correct File',
            confirmButtonColor: '#dc3545',
            width: '650px'
          });
          this.value = '';
          uploadInProgress.delete(uploadKey);
          return;
        }
        
        // ── REMOVED: Low confidence warning (now handled by block above) ──
        
        // Success - upload accepted
        if (data.success) {
          const confidence = data.ai_confidence || 0;
          const verdict = data.ai_verdict || 'pending';
          
          if (verdict === 'valid' && confidence >= 80) {
            Toast.success(`✅ AI Verified (${confidence}%): ${file.name} uploaded!`, 'Valid Evidence');
          } else if (verdict === 'uncertain' || (confidence > 0 && confidence < 80)) {
            Toast.warning(`⚠️ Uploaded (${confidence}% confidence): ${file.name} - AI suggests manual review`, 'Uncertain Evidence');
          } else if (confidence === 0) {
            Toast.success(`✅ File uploaded: ${file.name}`, 'Upload Complete (AI verification disabled)');
          } else {
            Toast.success(`✅ File uploaded: ${file.name}`, 'Upload Complete');
          }
          
          uploadInProgress.delete(uploadKey);
          // Reload to show new file
          setTimeout(() => window.location.reload(), 1500);
        } else {
          // Generic error (not AI blocked)
          Toast.error(data.message || 'Upload failed. Check file size and type.', 'Upload Error');
          console.error('Upload error:', data);
          this.value = '';
          uploadInProgress.delete(uploadKey);
        }
        
      } catch (error) {
        console.error('Upload error:', error);
        Toast.error('Upload failed. Please try again.', 'Network Error');
        this.value = '';
        uploadInProgress.delete(uploadKey);
      }
    });
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

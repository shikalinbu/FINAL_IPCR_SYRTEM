<?php
$pageTitle  = 'Evaluate IPCR';
$activePage = 'review-queue';

// Include required files FIRST before any logic
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// Pre-flight checks BEFORE including header
$db = getDB();
$formId = (int)($_GET['id'] ?? 0);
if (!$formId) { 
    header('Location: '.BASE_URL.'/dean/review-queue.php'); 
    exit; 
}

// Now include header which starts HTML output
require_once __DIR__ . '/../includes/header.php';
requireRole('dean'); // Dean ONLY — admin cannot rate

$form = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$form->execute([$formId]);
$form = $form->fetch();
if (!$form) { echo '<div class="alert alert-danger">Form not found.</div>'; require_once __DIR__.'/../includes/footer.php'; exit; }

// Auth: dean can only rate their assigned faculty
if ($currentRole === 'dean') {
    $faculty = $db->prepare("SELECT * FROM users WHERE id=?");
    $faculty->execute([$form['user_id']]);
    $faculty = $faculty->fetch();
    if (!$faculty || $faculty['assigned_dean_id'] != $currentUserId) {
        echo '<div class="alert alert-danger">Not authorized to evaluate this IPCR.</div>';
        require_once __DIR__.'/../includes/footer.php'; exit;
    }
} else {
    $faculty = $db->prepare("SELECT * FROM users WHERE id=?");
    $faculty->execute([$form['user_id']]);
    $faculty = $faculty->fetch();
}

// Transition submitted → under_review
if ($form['status'] === 'submitted') {
    $db->prepare("UPDATE ipcr_forms SET status='under_review', reviewed_by=?, updated_at=NOW() WHERE id=?")
       ->execute([$currentUserId, $formId]);
    $db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
       ->execute([$formId, 'under_review', $currentUserId, 'Dean started review.']);
    auditLog('status_change','ipcr_form',$formId,'submitted','under_review','Dean started review');
    $form['status'] = 'under_review';
    $form['reviewed_by'] = $currentUserId;
}

// Allow rating for submitted, under_review, and reviewed status (including late submissions)
if (!in_array($form['status'], ['submitted','under_review','reviewed']) && $currentRole !== 'admin') {
    echo '<div class="alert alert-warning">Cannot rate in current status: '.h($form['status']).'</div>';
    require_once __DIR__.'/../includes/footer.php'; exit;
}

// Show late submission warning
if ($form['is_late']) {
    echo '<div class="alert alert-warning"><i class="bi bi-clock-history me-2"></i><strong>Late Submission:</strong> This IPCR was submitted after the deadline.</div>';
}

$period = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
$period->execute([$form['period_id']]);
$period = $period->fetch();

// Load structure with entries
$rows = $db->prepare("
    SELECT m.id as mfo_id, m.code as mfo_code, m.title as mfo_title, m.weight as mfo_weight,
           k.id as kra_id, k.code as kra_code, k.title as kra_title, k.weight as kra_weight,
           s.id as si_id, s.code as si_code, s.title as si_title,
           s.quality_target, s.efficiency_target, s.timeliness_target,
           s.si_weight, s.is_required, s.sort_order as si_order,
           e.id as entry_id, e.planned_magnitude, e.planned_budget, e.planned_date,
           e.actual_magnitude, e.actual_budget, e.actual_date, e.actual_output,
           e.q_rating, e.e_rating, e.t_rating, e.average_rating, e.dean_remarks,
           e.dean_verify_status, e.is_na
    FROM mfos m JOIN kras k ON k.mfo_id=m.id JOIN success_indicators s ON s.kra_id=k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id=s.id AND e.form_id=?
    WHERE m.template_id=?
    ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$rows->execute([$formId, $form['template_id']]);
$allRows = $rows->fetchAll();

$mfos = [];
foreach ($allRows as $row) {
    $mid = $row['mfo_id'];
    $kid = $row['kra_id'];
    if (!isset($mfos[$mid])) $mfos[$mid] = ['id'=>$mid,'code'=>$row['mfo_code'],'title'=>$row['mfo_title'],'weight'=>$row['mfo_weight'],'kras'=>[]];
    if (!isset($mfos[$mid]['kras'][$kid])) $mfos[$mid]['kras'][$kid] = ['id'=>$kid,'code'=>$row['kra_code'],'title'=>$row['kra_title'],'weight'=>$row['kra_weight'],'sis'=>[]];
    $mfos[$mid]['kras'][$kid]['sis'][] = $row;
}

$evidenceMap = [];
$evRows = $db->prepare("SELECT * FROM evidence_files WHERE form_id=? ORDER BY uploaded_at ASC");
$evRows->execute([$formId]);
foreach ($evRows->fetchAll() as $ev) $evidenceMap[$ev['si_id']][] = $ev;

$proofTypes = [];
$ptRows = $db->prepare("SELECT rp.* FROM required_proof_types rp JOIN success_indicators s ON rp.si_id=s.id JOIN kras k ON s.kra_id=k.id JOIN mfos m ON k.mfo_id=m.id WHERE m.template_id=?");
$ptRows->execute([$form['template_id']]);
foreach ($ptRows->fetchAll() as $pt) $proofTypes[$pt['si_id']][] = $pt;

$qW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn() ?: 40);
$eW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn() ?: 40);
$tW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn() ?: 20);
$ratingScale = $db->query("SELECT config_value FROM system_config WHERE config_key='rating_scale'")->fetchColumn() ?: '[]';

// Count progress
$totalRequired = 0; $ratedCount = 0; $missingEvCount = 0;
foreach ($allRows as $r) {
    if (!$r['is_required'] || $r['is_na']) continue;
    $totalRequired++;
    if ($r['q_rating'] !== null && $r['e_rating'] !== null && $r['t_rating'] !== null) $ratedCount++;
    if (empty($evidenceMap[$r['si_id']])) $missingEvCount++;
}

// Build MFO_DATA for JS
$mfoDataJs = [];
foreach ($mfos as $mfoId => $mfo) {
    $kraArr = [];
    foreach ($mfo['kras'] as $kraId => $kra) {
        $siArr = [];
        foreach ($kra['sis'] as $si) {
            $siArr[] = [
                'id' => (int)$si['si_id'],
                'weight' => (float)$si['si_weight']
            ];
        }
        $kraArr[] = [
            'id' => (int)$kraId,
            'weight' => (float)$kra['weight'],
            'sis' => $siArr
        ];
    }
    $mfoDataJs[] = [
        'id' => (int)$mfoId,
        'weight' => (float)$mfo['weight'],
        'kras' => $kraArr
    ];
}

function evFileIcon2(string $ext): array {
    return match(strtolower($ext)) {
        'pdf'  => ['bi-file-earmark-pdf-fill',  'ev-file-pdf'],
        'doc','docx' => ['bi-file-earmark-word-fill','ev-file-doc'],
        'xls','xlsx' => ['bi-file-earmark-excel-fill','ev-file-xls'],
        'jpg','jpeg','png','gif','webp' => ['bi-file-earmark-image-fill','ev-file-img'],
        default => ['bi-file-earmark-fill','ev-file-other'],
    };
}
?>

<input type="hidden" id="qet-q-weight" value="<?= $qW ?>">
<input type="hidden" id="qet-e-weight" value="<?= $eW ?>">
<input type="hidden" id="qet-t-weight" value="<?= $tW ?>">

<div class="page-header no-print">
  <div class="page-header-left">
    <h1><i class="bi bi-card-checklist me-2"></i>Evaluate IPCR</h1>
    <div class="breadcrumb-bar">
      <a href="<?= BASE_URL ?>/dean/review-queue.php">Review Queue</a> › <?= h(fullName($faculty)) ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $formId ?>" target="_blank"
       class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
    <a href="<?= BASE_URL ?>/exports/export-excel.php?id=<?= $formId ?>"
       class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
  </div>
</div>

<div class="ipcr-form-header mb-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
    <div>
      <h2><?= h(fullName($faculty)) ?></h2>
      <div class="meta">
        <span><i class="bi bi-building"></i><?= h($faculty['department']??'—') ?></span>
        <span><i class="bi bi-briefcase"></i><?= h($faculty['position']??'—') ?></span>
        <span><i class="bi bi-calendar3"></i><?= h($period['name']??'—') ?></span>
        <?php if($form['submitted_at']): ?><span><i class="bi bi-send"></i>Submitted: <?= h(date('M d, Y', strtotime($form['submitted_at']))) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <?= statusBadge($form['status']) ?>
      <div class="text-white small mt-1">Progress: <?= $ratedCount ?>/<?= $totalRequired ?></div>
      <?php if($missingEvCount > 0): ?><div class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= $missingEvCount ?> missing evidence</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Rating Form — full width since ratings are inline -->
  <div class="col-12">

    <?php foreach($mfos as $mfoId => $mfo): ?>
    <div class="mfo-accordion">
      <div class="mfo-header">
        <h6><i class="bi bi-chevron-down me-2"></i><?= h($mfo['code']) ?>: <?= h($mfo['title']) ?></h6>
        <span class="badge bg-light text-dark">Weight: <?= h($mfo['weight']) ?>%</span>
      </div>
      <div class="mfo-body">
        <?php foreach($mfo['kras'] as $kraId => $kra): ?>
        <div class="kra-block">
          <div class="kra-header">
            <h6><i class="bi bi-diagram-3 me-2"></i><?= h($kra['code']) ?>: <?= h($kra['title']) ?></h6>
            <div class="d-flex gap-2 align-items-center">
              <span class="badge bg-secondary">Weight: <?= h($kra['weight']) ?>%</span>
              <span class="text-muted-sm">KRA Avg: <strong id="kra-avg-<?= $kraId ?>">—</strong></span>
            </div>
          </div>

          <?php foreach($kra['sis'] as $si):
            $siEvidence = $evidenceMap[$si['si_id']] ?? [];
            $siProofs   = $proofTypes[$si['si_id']] ?? [];
            $reqCount   = count(array_filter($siProofs, fn($p) => $p['is_required']));
            $evCount    = count($siEvidence);
            $verifyStatus = $si['dean_verify_status'] ?? 'pending';
          ?>
          <div class="ev-review-card ev-<?= h($verifyStatus) ?>-card" id="si-card-<?= $si['si_id'] ?>">
            <!-- SI Header -->
            <div class="ev-review-header">
              <div style="flex:1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="si-code fw-bold" style="font-size:.82rem;color:var(--primary)"><?= h($si['si_code']) ?></span>
                  <?php if($si['si_weight'] > 0): ?><span class="badge" style="background:var(--primary-xlight);color:var(--primary);border:1px solid #b8d9c4;font-size:.68rem">Wt: <?= h($si['si_weight']) ?>%</span><?php endif; ?>
                  <?= $si['is_required'] ? '' : '<span class="badge bg-secondary" style="font-size:.65rem">Optional</span>' ?>
                </div>
                <div class="fw-bold mt-1" style="font-size:.88rem"><?= h($si['si_title']) ?></div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                  <?php if($si['quality_target']): ?><span class="si-target-pill"><strong>Target:</strong> <?= h($si['quality_target']) ?></span><?php endif; ?>
                  <?php if($si['efficiency_target']): ?><span class="si-target-pill"><strong>Efficiency:</strong> <?= h($si['efficiency_target']) ?></span><?php endif; ?>
                  <?php if($si['timeliness_target']): ?><span class="si-target-pill"><i class="bi bi-clock me-1"></i><?= h($si['timeliness_target']) ?></span><?php endif; ?>
                </div>
              </div>
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input na-toggle" type="checkbox" id="na-<?= $si['si_id'] ?>"
                    data-si-id="<?= $si['si_id'] ?>" <?= $si['is_na'] ? 'checked' : '' ?>>
                  <label class="form-check-label small text-muted" for="na-<?= $si['si_id'] ?>">N/A</label>
                </div>
              </div>
            </div>

            <div class="ev-review-body <?= $si['is_na'] ? 'opacity-50 pe-none' : '' ?>" id="si-body-<?= $si['si_id'] ?>">

              <!-- Warnings -->
              <?php if(!$si['is_na'] && $evCount === 0): ?>
              <div class="alert alert-danger py-2 mb-3" style="font-size:.8rem">
                <i class="bi bi-exclamation-octagon-fill me-1"></i><strong>No evidence uploaded.</strong> Verify carefully before rating.
              </div>
              <?php elseif(!$si['is_na'] && $reqCount > 0 && $evCount < $reqCount): ?>
              <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem">
                <i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Incomplete evidence.</strong> <?= $evCount ?>/<?= $reqCount ?> required proof(s) uploaded.
              </div>
              <?php endif; ?>

              <!-- TOP ROW: Faculty Data + Evidence side by side -->
              <div class="row g-3 mb-3">
                <!-- Faculty claimed data -->
                <div class="col-md-6">
                  <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:8px;padding:12px">
                    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-500);margin-bottom:8px">Faculty's Claim</div>
                    <div class="row g-2 mb-2">
                      <div class="col-6">
                        <div style="font-size:.68rem;color:var(--gray-500)">Planned</div>
                        <div style="font-size:.82rem;font-weight:600"><?= h($si['planned_magnitude']??'—') ?></div>
                      </div>
                      <div class="col-6">
                        <div style="font-size:.68rem;color:var(--gray-500)">Actual</div>
                        <div style="font-size:.9rem;font-weight:700;color:var(--primary)"><?= h($si['actual_magnitude']??'—') ?></div>
                      </div>
                      <?php if($si['planned_date']||$si['actual_date']): ?>
                      <div class="col-6">
                        <div style="font-size:.68rem;color:var(--gray-500)">Planned Date</div>
                        <div style="font-size:.78rem"><?= h($si['planned_date']??'—') ?></div>
                      </div>
                      <div class="col-6">
                        <div style="font-size:.68rem;color:var(--gray-500)">Actual Date</div>
                        <div style="font-size:.78rem"><?= h($si['actual_date']??'—') ?></div>
                      </div>
                      <?php endif; ?>
                    </div>
                    <?php if($si['actual_output']): ?>
                    <div style="font-size:.68rem;color:var(--gray-500);margin-bottom:3px">Accomplishment</div>
                    <div style="font-size:.8rem;background:#fff;border:1px solid var(--gray-200);border-radius:6px;padding:6px 10px;line-height:1.4">
                      <?= h(mb_substr($si['actual_output'],0,150)) ?><?= strlen($si['actual_output'])>150?'…':'' ?>
                    </div>
                    <?php else: ?>
                    <div style="font-size:.78rem;color:#fca5a5;font-style:italic"><i class="bi bi-exclamation-circle me-1"></i>No accomplishment provided</div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Evidence -->
                <div class="col-md-6">
                  <div style="background:#fafcfa;border:1.5px dashed var(--gray-300);border-radius:8px;padding:12px;height:100%">
                    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-500);margin-bottom:8px">
                      Submitted Evidence
                      <?php if($evCount>0): ?><span class="badge ms-1" style="background:var(--success);color:#fff;font-size:.62rem"><?= $evCount ?></span><?php endif; ?>
                    </div>
                    <?php if(empty($siEvidence)): ?>
                    <div style="text-align:center;padding:14px 0;color:var(--gray-400);font-size:.78rem">
                      <i class="bi bi-folder2-open d-block mb-1" style="font-size:1.3rem"></i>None submitted
                    </div>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:5px">
                      <?php foreach($siEvidence as $ev):
                        $ext = strtolower(pathinfo($ev['original_name'], PATHINFO_EXTENSION));
                        [$ico, $cls] = evFileIcon2($ext);
                      ?>
                      <div style="background:#fff;border:1px solid var(--gray-200);border-radius:6px;padding:6px 10px;display:flex;align-items:center;gap:8px">
                        <div class="ev-file-icon <?= $cls ?>" style="width:28px;height:28px;border-radius:6px;font-size:.85rem;flex-shrink:0">
                          <i class="bi <?= $ico ?>"></i>
                        </div>
                        <div style="flex:1;min-width:0">
                          <div style="font-size:.76rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($ev['original_name']) ?></div>
                          <div style="font-size:.68rem;color:var(--gray-500)"><?= h(date('M d, Y', strtotime($ev['uploaded_at']))) ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:4px;flex-shrink:0">
                          <button onclick="previewFile(<?= $ev['id'] ?>, '<?= addslashes(h($ev['original_name'])) ?>', '<?= addslashes($ev['file_type']??'') ?>')"
                             style="font-size:.7rem;padding:2px 8px;border-radius:4px;border:1px solid var(--primary);color:var(--primary);background:#fff;cursor:pointer;white-space:nowrap">
                            <i class="bi bi-eye"></i> View
                          </button>
                          <select style="font-size:.68rem;padding:2px 4px;border-radius:4px;border:1px solid var(--gray-300);color:var(--gray-700);max-width:90px"
                            onchange="fetch('<?= BASE_URL ?>/api/update-evidence-verify.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({file_id:<?= $ev['id'] ?>,status:this.value})}).then(r=>r.json()).then(d=>d.success?Toast.info('Saved'):Toast.error(d.message))">
                            <option value="pending"        <?= ($ev['verify_status']??'pending')==='pending'?'selected':'' ?>>🟡 Pending</option>
                            <option value="verified"       <?= ($ev['verify_status']??'')==='verified'?'selected':'' ?>>🟢 Verified</option>
                            <option value="needs_revision" <?= ($ev['verify_status']??'')==='needs_revision'?'selected':'' ?>>🟠 Revise</option>
                            <option value="insufficient"   <?= ($ev['verify_status']??'')==='insufficient'?'selected':'' ?>>🔴 Insuff.</option>
                            <option value="rejected"       <?= ($ev['verify_status']??'')==='rejected'?'selected':'' ?>>⚫ Rejected</option>
                          </select>
                        </div>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- RATING ROW — always full width, always visible -->
              <div style="background:linear-gradient(90deg,#f0faf4,#fafffe);border:2px solid #b8d9c4;border-radius:10px;padding:14px 16px">
                <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);margin-bottom:12px;display:flex;align-items:center;gap:6px">
                  <i class="bi bi-star-fill"></i> Dean's Rating
                  <?= $si['is_required'] ? '<span style="color:var(--danger)">*</span>' : '<span style="color:var(--gray-400);font-size:.7rem">(Optional)</span>' ?>
                </div>

                <div class="row g-2 align-items-end">
                  <!-- Q E T inputs -->
                  <div class="col-lg-4 col-md-5">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
                      <div>
                        <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px;text-align:center">Q <span style="color:var(--gray-400);font-weight:400"><?= $qW ?>%</span></label>
                        <input type="number" class="form-control form-control-sm rating-input q-rating-input"
                          id="q-<?= $si['si_id'] ?>" min="1" max="5" step="1"
                          value="<?= h($si['q_rating']??'') ?>" placeholder="1-5"
                          data-si-id="<?= $si['si_id'] ?>" data-required="<?= $si['is_required'] ?>"
                          style="text-align:center;font-size:1.1rem;font-weight:800;border:2px solid #b8d9c4;border-radius:8px;padding:6px 4px">
                      </div>
                      <div>
                        <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px;text-align:center">E <span style="color:var(--gray-400);font-weight:400"><?= $eW ?>%</span></label>
                        <input type="number" class="form-control form-control-sm rating-input e-rating-input"
                          id="e-<?= $si['si_id'] ?>" min="1" max="5" step="1"
                          value="<?= h($si['e_rating']??'') ?>" placeholder="1-5"
                          data-si-id="<?= $si['si_id'] ?>"
                          style="text-align:center;font-size:1.1rem;font-weight:800;border:2px solid #b8d9c4;border-radius:8px;padding:6px 4px">
                      </div>
                      <div>
                        <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px;text-align:center">T <span style="color:var(--gray-400);font-weight:400"><?= $tW ?>%</span></label>
                        <input type="number" class="form-control form-control-sm rating-input t-rating-input"
                          id="t-<?= $si['si_id'] ?>" min="1" max="5" step="1"
                          value="<?= h($si['t_rating']??'') ?>" placeholder="1-5"
                          data-si-id="<?= $si['si_id'] ?>"
                          style="text-align:center;font-size:1.1rem;font-weight:800;border:2px solid #b8d9c4;border-radius:8px;padding:6px 4px">
                      </div>
                    </div>
                  </div>

                  <!-- Average -->
                  <div class="col-lg-2 col-md-2 text-center">
                    <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px">Average</label>
                    <div class="rating-avg-display" id="avg-<?= $si['si_id'] ?>" style="font-size:1.2rem;font-weight:900;padding:6px 10px;min-width:70px">
                      <?php if($si['q_rating']&&$si['e_rating']&&$si['t_rating']): ?>
                        <?= number_format(($si['q_rating']*$qW+$si['e_rating']*$eW+$si['t_rating']*$tW)/($qW+$eW+$tW), 2) ?>
                      <?php else: ?>—<?php endif; ?>
                    </div>
                  </div>

                  <!-- Evidence Status -->
                  <div class="col-lg-2 col-md-3">
                    <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px">Evidence Status</label>
                    <select class="form-select form-select-sm verify-status-select" id="verify-<?= $si['si_id'] ?>" data-si-id="<?= $si['si_id'] ?>" style="font-size:.78rem">
                      <option value="pending"        <?= $verifyStatus==='pending'?'selected':'' ?>>🟡 Pending</option>
                      <option value="verified"       <?= $verifyStatus==='verified'?'selected':'' ?>>🟢 Verified</option>
                      <option value="needs_revision" <?= $verifyStatus==='needs_revision'?'selected':'' ?>>🟠 Needs Revision</option>
                      <option value="insufficient"   <?= $verifyStatus==='insufficient'?'selected':'' ?>>🔴 Insufficient</option>
                      <option value="rejected"       <?= $verifyStatus==='rejected'?'selected':'' ?>>⚫ Rejected</option>
                    </select>
                  </div>

                  <!-- Remarks -->
                  <div class="col-lg-4 col-md-12">
                    <label style="font-size:.7rem;font-weight:700;color:var(--gray-600);display:block;margin-bottom:3px">Comments <span style="font-weight:400;color:var(--gray-400)">(optional)</span></label>
                    <textarea class="form-control form-control-sm" rows="2"
                      id="remarks-<?= $si['si_id'] ?>" data-si-id="<?= $si['si_id'] ?>"
                      placeholder="Add comments or justification..."
                      style="font-size:.8rem;resize:none;border:1.5px solid #b8d9c4"><?= h($si['dean_remarks']??'') ?></textarea>
                  </div>
                </div>
              </div>

            </div><!-- /ev-review-body -->
          </div><!-- /ev-review-card -->
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Overall Remarks -->
    <div class="card mt-4">
      <div class="card-header"><h6><i class="bi bi-chat-text me-2"></i>Rater's Overall Comments and Recommendations</h6></div>
      <div class="card-body">
        <textarea id="dean-overall-remarks" class="form-control" rows="4"
          placeholder="Add overall evaluation comments, commendations, and areas for improvement..."><?= h($form['dean_remarks']??'') ?></textarea>
      </div>
    </div>

    <!-- Live Score Summary (inline) -->
    <div class="card mt-4" style="border:2px solid var(--primary)">
      <div class="card-header" style="background:var(--primary)">
        <h6 style="color:#fff;margin:0"><i class="bi bi-calculator me-2"></i>Live Computed Score</h6>
        <div class="autosave-indicator"><i class="bi bi-cloud-check"></i><span>Auto-saves every 30s</span></div>
      </div>
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-3 text-center">
            <div style="font-size:.7rem;color:var(--gray-500);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Composite Rating</div>
            <div id="lsp-composite" style="font-size:3rem;font-weight:900;color:var(--primary);line-height:1;letter-spacing:-.04em">—</div>
            <div id="lsp-adjectival" style="font-size:.95rem;font-weight:700;color:var(--accent);margin-top:4px">—</div>
          </div>
          <div class="col-md-6">
            <table style="width:100%;font-size:.82rem">
              <?php foreach($mfos as $mfoId => $mfo): ?>
              <tr>
                <td style="padding:4px 8px 4px 0;color:var(--gray-600)"><?= h($mfo['code']) ?>: <?= h(mb_substr($mfo['title'],0,28)) ?> <span style="color:var(--gray-400)">(<?= $mfo['weight'] ?>%)</span></td>
                <td style="text-align:right;font-weight:700;color:var(--primary);white-space:nowrap">
                  <span id="lsp-mfo-<?= $mfoId ?>">—</span>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>
          <div class="col-md-3 text-center">
            <div style="font-size:.72rem;color:var(--gray-500);margin-bottom:6px">
              Q:<?= $qW ?>% + E:<?= $eW ?>% + T:<?= $tW ?>%
            </div>
            <div style="font-size:.8rem;color:var(--gray-600)">
              Rated: <strong id="lsp-rated"><?= $ratedCount ?>/<?= $totalRequired ?></strong>
            </div>
            <?php if($missingEvCount > 0): ?>
            <div style="font-size:.78rem;color:var(--warning);margin-top:4px">
              <i class="bi bi-exclamation-triangle me-1"></i><?= $missingEvCount ?> missing evidence
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mt-3 mb-4">
      <!-- Save Progress moved to sticky bottom bar -->
    </div>
  </div>
</div>

<!-- Sticky Bottom Bar -->
<div class="form-bottom-bar">
  <div class="d-flex align-items-center gap-3">
    <div class="save-status">
      <i class="bi bi-cloud-check"></i>
      <span id="autosave-txt">Auto-saves every 30s</span>
    </div>
    <div style="font-size:.78rem;color:var(--gray-500)">
      Rated: <strong id="lsp-rated-bar"><?= $ratedCount ?>/<?= $totalRequired ?></strong>
      &nbsp;·&nbsp;
      Score: <strong id="lsp-composite-bar" style="color:var(--primary)">—</strong>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#returnModal">
      <i class="bi bi-arrow-return-left me-1"></i>Return to Faculty
    </button>
    <button class="btn btn-outline-secondary" id="btn-save-progress">
      <i class="bi bi-save me-1"></i>Save Progress
    </button>
    <button class="btn btn-success fw-bold" id="btn-submit-evaluation">
      <i class="bi bi-check2-all me-1"></i>Submit Evaluation
    </button>
  </div>
</div>
<!-- Return Modal — properly wrapped -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-return-left me-2"></i>Return IPCR to Faculty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label fw-semibold">Return Remarks <span class="required-star">*</span></label>
        <textarea class="form-control" id="return-remarks" rows="4"
          placeholder="Describe what the faculty needs to revise, add, or correct..."></textarea>
        <div class="form-text mt-1">This message will be shown to the faculty when they log in.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>Cancel</button>
        <button class="btn btn-danger" id="btn-confirm-return">
          <i class="bi bi-arrow-return-left me-1"></i>Return to Faculty
        </button>
      </div>
    </div>
  </div>
</div>

<script>
window.FORM_ID      = <?= $formId ?>;
window.BASE_URL     = '<?= BASE_URL ?>';
window.MFO_DATA     = <?= json_encode(array_values($mfoDataJs)) ?>;
window.RATING_SCALE = <?= $ratingScale ?>;

const qWeight = <?= $qW ?>;
const eWeight = <?= $eW ?>;
const tWeight = <?= $tW ?>;
const totalWeight = qWeight + eWeight + tWeight;

// ============================================================
// COMPUTATION: Q/E/T → Average Score
// ============================================================
function computeQETAvg(q, e, t) {
  if (q == null || e == null || t == null) return null;
  return (q * qWeight + e * eWeight + t * tWeight) / totalWeight;
}

function updateSIAverage(siId) {
  const qInput = document.getElementById(`q-${siId}`);
  const eInput = document.getElementById(`e-${siId}`);
  const tInput = document.getElementById(`t-${siId}`);
  const avgDisplay = document.getElementById(`avg-${siId}`);
  
  if (!qInput || !eInput || !tInput || !avgDisplay) return;
  
  const q = qInput.value ? parseFloat(qInput.value) : null;
  const e = eInput.value ? parseFloat(eInput.value) : null;
  const t = tInput.value ? parseFloat(tInput.value) : null;
  
  const avg = computeQETAvg(q, e, t);
  
  if (avg !== null) {
    avgDisplay.textContent = avg.toFixed(2);
    avgDisplay.style.color = 'var(--primary)';
  } else {
    avgDisplay.textContent = '—';
    avgDisplay.style.color = 'var(--gray-400)';
  }
  
  // Trigger live score update
  updateLiveScore();
}

function updateLiveScore() {
  let totalRated = 0;
  let totalRequired = <?= $totalRequired ?>;
  const mfoScores = {};
  
  // Collect all ratings per MFO
  window.MFO_DATA.forEach(mfo => {
    let mfoWeightedSum = 0;
    let mfoValidWeightTotal = 0;
    
    mfo.kras.forEach(kra => {
      kra.sis.forEach(si => {
        const siId = si.id;
        const qInput = document.getElementById(`q-${siId}`);
        const eInput = document.getElementById(`e-${siId}`);
        const tInput = document.getElementById(`t-${siId}`);
        const naToggle = document.getElementById(`na-${siId}`);
        
        if (naToggle && naToggle.checked) return; // Skip N/A
        
        const q = qInput && qInput.value ? parseFloat(qInput.value) : null;
        const e = eInput && eInput.value ? parseFloat(eInput.value) : null;
        const t = tInput && tInput.value ? parseFloat(tInput.value) : null;
        
        if (q !== null && e !== null && t !== null) {
          const avg = computeQETAvg(q, e, t);
          const siWeight = parseFloat(si.weight || 0);
          
          if (siWeight > 0) {
            mfoWeightedSum += avg * (siWeight / 100);
            mfoValidWeightTotal += siWeight;
          }
          
          totalRated++;
        }
      });
    });
    
    const mfoAvg = mfoValidWeightTotal > 0 
      ? mfoWeightedSum / (mfoValidWeightTotal / 100) 
      : 0;
    
    mfoScores[mfo.id] = mfoAvg;
    
    // Update MFO display
    const mfoDisplay = document.getElementById(`lsp-mfo-${mfo.id}`);
    if (mfoDisplay) {
      mfoDisplay.textContent = mfoAvg > 0 ? mfoAvg.toFixed(2) : '—';
    }
  });
  
  // Compute composite rating
  let compositeRating = 0;
  window.MFO_DATA.forEach(mfo => {
    compositeRating += mfoScores[mfo.id] * (mfo.weight / 100);
  });
  
  // Update composite display
  const compDisplay = document.getElementById('lsp-composite');
  const compBarDisplay = document.getElementById('lsp-composite-bar');
  const adjDisplay = document.getElementById('lsp-adjectival');
  const ratedDisplay = document.getElementById('lsp-rated');
  const ratedBarDisplay = document.getElementById('lsp-rated-bar');
  
  if (compDisplay) {
    compDisplay.textContent = compositeRating > 0 ? compositeRating.toFixed(3) : '—';
  }
  if (compBarDisplay) {
    compBarDisplay.textContent = compositeRating > 0 ? compositeRating.toFixed(3) : '—';
  }
  if (ratedDisplay) {
    ratedDisplay.textContent = totalRated;
  }
  if (ratedBarDisplay) {
    ratedBarDisplay.textContent = `${totalRated}/${totalRequired}`;
  }
  
  // Update adjectival rating
  if (adjDisplay && compositeRating > 0) {
    const adj = getAdjectivalRating(compositeRating);
    adjDisplay.textContent = adj.label;
    adjDisplay.style.color = adj.color;
  }
  
  // Dispatch event for bottom bar sync
  document.dispatchEvent(new CustomEvent('liveScoreUpdated', {
    detail: { composite: compositeRating > 0 ? compositeRating.toFixed(3) : '—' }
  }));
}

function getAdjectivalRating(score) {
  const scale = window.RATING_SCALE || [];
  const sorted = scale.sort((a, b) => b.minScore - a.minScore);
  const level = sorted.find(l => score >= l.minScore);
  
  if (level) return { label: level.label, color: level.color || '#198754' };
  
  // Default fallback
  if (score >= 4.500) return { label: 'Outstanding', color: '#198754' };
  if (score >= 3.500) return { label: 'Very Satisfactory', color: '#0d6efd' };
  if (score >= 2.500) return { label: 'Satisfactory', color: '#ffc107' };
  if (score >= 1.500) return { label: 'Unsatisfactory', color: '#fd7e14' };
  return { label: 'Poor', color: '#dc3545' };
}

// ============================================================
// EVENT LISTENERS: Rating Input Changes
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
  // Listen to all Q/E/T rating inputs
  document.querySelectorAll('.rating-input').forEach(input => {
    input.addEventListener('input', function() {
      const siId = this.dataset.siId;
      
      // Validate range
      const val = parseFloat(this.value);
      if (this.value && (val < 1 || val > 5)) {
        this.classList.add('is-invalid');
        return;
      }
      this.classList.remove('is-invalid');
      
      // Update SI average
      updateSIAverage(siId);
    });
  });
  
  // Listen to N/A toggles
  document.querySelectorAll('.na-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
      const siId = this.dataset.siId;
      const siBody = document.getElementById(`si-body-${siId}`);
      
      if (siBody) {
        if (this.checked) {
          siBody.classList.add('opacity-50', 'pe-none');
        } else {
          siBody.classList.remove('opacity-50', 'pe-none');
        }
      }
      
      updateLiveScore();
    });
  });
  
  // Initial computation on page load
  updateLiveScore();
});

// Sync bottom bar composite display
document.addEventListener('liveScoreUpdated', function(e) {
  const bar = document.getElementById('lsp-composite-bar');
  if (bar && e.detail?.composite) bar.textContent = e.detail.composite;
  const rbar = document.getElementById('lsp-rated-bar');
  const rMain = document.getElementById('lsp-rated');
  if (rbar && rMain) rbar.textContent = rMain.textContent;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

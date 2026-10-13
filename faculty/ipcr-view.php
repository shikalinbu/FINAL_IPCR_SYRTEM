<?php
// Check form and permissions BEFORE including header (to avoid "headers already sent" error)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();
$currentUserId = $_SESSION['user_id'];
$currentRole = $_SESSION['role'];

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) { 
    header('Location: '.BASE_URL.'/dashboard.php'); 
    exit; 
}

$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?"); 
$stmt->execute([$formId]); 
$form = $stmt->fetch();

if (!$form) { 
    // Will show error after header is included
    $formNotFound = true;
} else {
    $formNotFound = false;
    $isOwner = ($form['user_id'] == $currentUserId);
    $isDean  = ($currentRole === 'dean');
    $isAdmin = ($currentRole === 'admin');

    // If dean is viewing a rateable faculty form, redirect to rate.php directly (BEFORE any output)
    if ($isDean && !$isOwner && in_array($form['status'], ['submitted','under_review'])) {
        header('Location: '.BASE_URL.'/dean/rate.php?id='.$formId);
        exit;
    }
}

// NOW include header (after redirect checks)
$pageTitle  = 'View IPCR';
$activePage = 'ipcr-form';
require_once __DIR__ . '/../includes/header.php';

// Handle errors after header is included
if ($formNotFound) {
    echo '<div class="alert alert-danger">IPCR form not found.</div>'; 
    require_once __DIR__.'/../includes/footer.php'; 
    exit;
}if (!$isOwner && !$isAdmin) {
    if ($isDean) {
        $f2 = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?"); $f2->execute([$form['user_id']]); $f2 = $f2->fetch();
        if (!$f2 || $f2['assigned_dean_id'] != $currentUserId) {
            echo '<div class="alert alert-danger">Access denied.</div>'; require_once __DIR__.'/../includes/footer.php'; exit;
        }
    } else { echo '<div class="alert alert-danger">Access denied.</div>'; require_once __DIR__.'/../includes/footer.php'; exit; }
}

$user   = $db->prepare("SELECT * FROM users WHERE id=?"); $user->execute([$form['user_id']]); $user = $user->fetch();
$period = $db->prepare("SELECT * FROM rating_periods WHERE id=?"); $period->execute([$form['period_id']]); $period = $period->fetch();
$dean   = null; if($form['reviewed_by']){ $d=$db->prepare("SELECT * FROM users WHERE id=?"); $d->execute([$form['reviewed_by']]); $dean=$d->fetch(); }
$adm    = null; if($form['approved_by']){ $a=$db->prepare("SELECT * FROM users WHERE id=?"); $a->execute([$form['approved_by']]); $adm=$a->fetch(); }

$pageTitle  = 'IPCR — '.fullName($user);
$canRate = ($isDean && !$isOwner && in_array($form['status'], ['submitted','under_review']));

$rows = $db->prepare("
    SELECT m.id mfo_id, m.code mfo_code, m.title mfo_title, m.weight mfo_weight,
           k.id kra_id, k.code kra_code, k.title kra_title, k.weight kra_weight,
           s.id si_id, s.code si_code, s.title si_title,
           s.quality_target, s.efficiency_target, s.timeliness_target,
           s.si_weight, s.is_required,
           e.planned_magnitude, e.planned_budget, e.planned_date,
           e.actual_magnitude, e.actual_budget, e.actual_date, e.actual_output,
           e.q_rating, e.e_rating, e.t_rating, e.average_rating, e.weighted_rating,
           e.dean_remarks, e.dean_verify_status, e.is_na
    FROM mfos m JOIN kras k ON k.mfo_id=m.id JOIN success_indicators s ON s.kra_id=k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id=s.id AND e.form_id=?
    WHERE m.template_id=? ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$rows->execute([$formId, $form['template_id']]); $allRows = $rows->fetchAll();

$evMap = [];
$evRows = $db->prepare("SELECT * FROM evidence_files WHERE form_id=? ORDER BY uploaded_at");
$evRows->execute([$formId]);
foreach ($evRows->fetchAll() as $ev) $evMap[$ev['si_id']][] = $ev;

$history = $db->prepare("SELECT h.*, CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) actor FROM form_history h LEFT JOIN users u ON h.done_by=u.id WHERE h.form_id=? ORDER BY h.done_at ASC");
$history->execute([$formId]); $history = $history->fetchAll();

$showRatings = in_array($form['status'], ['reviewed','approved','finalized']);
$qW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn()?:40);
$eW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn()?:40);
$tW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn()?:20);

// Group rows
$mfos=[];
foreach($allRows as $row){
    $mid=$row['mfo_id']; $kid=$row['kra_id'];
    if(!isset($mfos[$mid])) $mfos[$mid]=['code'=>$row['mfo_code'],'title'=>$row['mfo_title'],'weight'=>$row['mfo_weight'],'kras'=>[]];
    if(!isset($mfos[$mid]['kras'][$kid])) $mfos[$mid]['kras'][$kid]=['code'=>$row['kra_code'],'title'=>$row['kra_title'],'weight'=>$row['kra_weight'],'sis'=>[]];
    $mfos[$mid]['kras'][$kid]['sis'][]=$row;
}

// Compute MFO totals
$mfoTotals=[];
foreach($mfos as $mid=>$mfo){
    $t=0; foreach($mfo['kras'] as $kra) foreach($kra['sis'] as $si)
        if(!$si['is_na']&&$si['average_rating']!==null&&$si['si_weight']>0)
            $t+=floatval($si['average_rating'])*(floatval($si['si_weight'])/100);
    $mfoTotals[$mid]=$t;
}

function evIcon2(string $ext): array {
    return match(strtolower($ext)){
        'pdf'=>['bi-file-earmark-pdf-fill','ev-file-pdf'],
        'doc','docx'=>['bi-file-earmark-word-fill','ev-file-doc'],
        'xls','xlsx'=>['bi-file-earmark-excel-fill','ev-file-xls'],
        'jpg','jpeg','png','gif','webp'=>['bi-file-earmark-image-fill','ev-file-img'],
        default=>['bi-file-earmark-fill','ev-file-other'],
    };
}

$statusColors=['draft'=>'#6b7280','submitted'=>'#2563eb','under_review'=>'#0891b2','reviewed'=>'#d97706','returned'=>'#dc2626','approved'=>'#059669','finalized'=>'#1b5e35'];
$sc=$statusColors[$form['status']]??'#6b7280';
?>
<style>
/* ── VIEW PAGE ── */
.view-hero{
  background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary) 100%);
  border-radius:var(--border-r);padding:22px 26px;color:#fff;
  margin-bottom:20px;border-left:5px solid var(--accent);
  display:flex;align-items:flex-start;justify-content:space-between;
  flex-wrap:wrap;gap:14px;
}
.view-hero h2{font-size:1.2rem;font-weight:800;margin:0 0 8px;letter-spacing:-.02em}
.hero-meta{display:flex;flex-wrap:wrap;gap:8px}
.hero-chip{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);
  border-radius:20px;padding:3px 12px;font-size:.76rem;display:flex;align-items:center;gap:5px}
.hero-status{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.status-pill{padding:5px 16px;border-radius:20px;font-weight:700;font-size:.82rem;
  background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);color:#fff}

/* ── RATING SUMMARY CARD ── */
.rating-card{border-radius:var(--border-r);overflow:hidden;box-shadow:var(--shadow-md);margin-bottom:20px}
.rating-card-header{background:linear-gradient(90deg,var(--primary-dark),var(--primary));
  color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;
  border-bottom:3px solid var(--accent)}
.rating-card-header h6{margin:0;font-size:.9rem;font-weight:700;color:#fff}
.rating-card-body{background:#fff;padding:0}

.mfo-rating-row{
  display:grid;grid-template-columns:1fr auto;
  padding:10px 22px;border-bottom:1px solid var(--gray-100);
  align-items:center;gap:12px;transition:background .1s;
}
.mfo-rating-row:hover{background:var(--primary-xlight)}
.mfo-rating-row:last-child{border-bottom:none}
.mfo-rating-label{font-size:.85rem;font-weight:600;color:var(--gray-800)}
.mfo-rating-label span{font-size:.75rem;font-weight:400;color:var(--gray-500);margin-left:4px}
.mfo-score{font-size:1rem;font-weight:800;color:var(--primary)}

.composite-banner{
  background:linear-gradient(90deg,var(--primary-dark),var(--primary));
  color:#fff;padding:14px 22px;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
}
.composite-num{font-size:2.2rem;font-weight:900;letter-spacing:-.04em;line-height:1}
.composite-adj{font-size:1rem;font-weight:700;color:var(--accent-light);margin-top:2px}
.composite-label{font-size:.7rem;opacity:.65;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}

/* ── MFO SECTION ── */
.view-mfo-section{
  background:#fff;border:1.5px solid var(--gray-200);
  border-radius:var(--border-r);margin-bottom:14px;
  overflow:hidden;box-shadow:var(--shadow-sm);
}
.view-mfo-header{
  background:linear-gradient(90deg,var(--primary-dark),var(--primary));
  color:#fff;padding:11px 20px;
  display:flex;align-items:center;justify-content:space-between;
  border-left:4px solid var(--accent);
}
.view-mfo-header h5{margin:0;font-size:.9rem;font-weight:700}
.mfo-wt-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
  border-radius:12px;padding:2px 10px;font-size:.7rem;font-weight:700;color:#fff}

.view-kra-header{
  background:linear-gradient(90deg,#edf7f1,#fff);
  border-bottom:1px solid var(--gray-200);border-left:3px solid var(--primary-light);
  padding:7px 20px;display:flex;align-items:center;justify-content:space-between;
}
.view-kra-header h6{margin:0;font-size:.8rem;font-weight:700;color:var(--primary)}

/* ── SI VIEW ROW ── */
.view-si{border-bottom:1px solid var(--gray-100)}
.view-si:last-child{border-bottom:none}

.view-si-header{
  padding:10px 20px 8px;background:#fafcfa;
  border-bottom:1px solid var(--gray-100);
  display:flex;align-items:flex-start;gap:10px;
}
.si-num{width:22px;height:22px;border-radius:50%;background:var(--primary);
  color:#fff;display:flex;align-items:center;justify-content:center;
  font-size:.62rem;font-weight:700;flex-shrink:0;margin-top:2px}
.si-info{flex:1}
.si-name{font-size:.87rem;font-weight:600;color:var(--gray-800);line-height:1.3;margin-bottom:4px}
.si-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
.si-chip{font-size:.68rem;padding:2px 8px;border-radius:6px;
  background:var(--primary-xlight);border:1px solid #b8d9c4;color:var(--primary)}
.si-chip.accent{background:#fdf9e8;border-color:#e8c84a;color:#7c5200}
.si-wt{font-size:.68rem;font-weight:700;background:var(--primary-xlight);
  color:var(--primary);border:1px solid #b8d9c4;border-radius:10px;
  padding:2px 8px;flex-shrink:0}

.view-si-body{padding:14px 20px}

/* Planned/Actual grid */
.pa-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--gray-200);border-radius:8px;overflow:hidden;margin-bottom:12px}
.pa-half{padding:10px 14px}
.pa-half:first-child{border-right:1px solid var(--gray-200);background:#fafcfa}
.pa-half:last-child{background:#f0faf4}
.pa-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--gray-500);margin-bottom:8px;display:flex;align-items:center;gap:5px}
.pa-label.actual{color:var(--primary)}
.pa-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.pa-field-label{font-size:.68rem;color:var(--gray-500);margin-bottom:2px}
.pa-field-val{font-size:.83rem;font-weight:600;color:var(--gray-800)}
.pa-field-val.hl{color:var(--primary);font-weight:700}
.pa-field-val.empty{color:var(--gray-300);font-style:italic;font-weight:400}

/* Accomplishment */
.accomplishment-box{
  background:var(--gray-50);border:1px solid var(--gray-200);
  border-radius:8px;padding:10px 14px;margin-bottom:12px;
  font-size:.85rem;color:var(--gray-800);line-height:1.5;
}
.accomplishment-box.empty{color:var(--gray-400);font-style:italic}
.acc-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:var(--gray-500);margin-bottom:6px}

/* Evidence list */
.ev-compact-list{display:flex;flex-direction:column;gap:5px}
.ev-compact-row{
  background:#fff;border:1px solid var(--gray-200);border-radius:7px;
  padding:7px 12px;display:flex;align-items:center;gap:9px;
  transition:var(--transition);
}
.ev-compact-row:hover{border-color:#b8d9c4;background:var(--primary-xlight)}
.ev-ico-sm{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;
  justify-content:center;font-size:.9rem;flex-shrink:0}
.ev-info-sm{flex:1;min-width:0}
.ev-name-sm{font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ev-date-sm{font-size:.68rem;color:var(--gray-500)}
.ev-dl-btn{font-size:.72rem;padding:3px 10px;border-radius:5px;
  background:none;border:1px solid var(--gray-300);color:var(--gray-600);
  text-decoration:none;display:flex;align-items:center;gap:3px;white-space:nowrap;flex-shrink:0}
.ev-dl-btn:hover{border-color:var(--primary);color:var(--primary)}
.ev-empty{text-align:center;padding:12px;color:var(--gray-400);font-size:.78rem}
.ev-empty i{display:block;font-size:1.2rem;margin-bottom:3px}

/* Dean eval panel */
.dean-eval{
  background:linear-gradient(90deg,#f0faf4,#fafffe);
  border:1px solid #b8d9c4;border-left:3px solid var(--success);
  border-radius:8px;padding:10px 14px;margin-top:8px;
}
.dean-eval-title{font-size:.68rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;color:var(--success);margin-bottom:8px;display:flex;align-items:center;gap:5px}
.qet-pills{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:6px}
.qet-pill{background:#fff;border:1.5px solid var(--gray-200);border-radius:8px;
  padding:4px 12px;font-size:.78rem;display:flex;align-items:center;gap:5px}
.qet-pill .ql{font-size:.62rem;color:var(--gray-500)}
.qet-pill .qv{font-weight:700;color:var(--primary)}
.qet-pill.avg{background:var(--primary-xlight);border-color:#b8d9c4}
.qet-pill.avg .qv{font-size:.95rem}

/* ── HISTORY TIMELINE ── */
.timeline{position:relative;padding-left:28px}
.timeline::before{content:'';position:absolute;left:9px;top:6px;bottom:6px;width:2px;background:var(--gray-200)}
.tl-item{position:relative;margin-bottom:14px}
.tl-dot{width:20px;height:20px;border-radius:50%;background:var(--primary);
  border:3px solid #fff;box-shadow:0 0 0 2px var(--primary);
  position:absolute;left:-25px;top:3px}
.tl-content{background:var(--gray-50);border:1px solid var(--gray-200);border-radius:8px;padding:9px 14px}
.tl-action{font-weight:700;font-size:.83rem;color:var(--gray-800)}
.tl-meta{font-size:.72rem;color:var(--gray-500);margin-top:2px;display:flex;align-items:center;gap:8px}
.tl-remark{font-size:.78rem;color:var(--gray-600);margin-top:4px;
  padding-top:4px;border-top:1px solid var(--gray-200)}

/* ── RETURN ALERT ── */
.return-alert{
  background:#fff8ec;border:1px solid #ffe082;border-left:4px solid var(--accent);
  border-radius:var(--border-r);padding:14px 18px;margin-bottom:18px;
  display:flex;gap:12px;align-items:flex-start;
}
</style>

<!-- ── PAGE HEADER ── -->
<div class="page-header no-print">
  <div class="page-header-left">
    <h1><i class="bi bi-file-earmark-person me-2"></i>View IPCR</h1>
    <div class="breadcrumb-bar">
      <a href="<?= BASE_URL ?>/dashboard.php">Home</a> ›
      <?php if($isDean||$isAdmin): ?><a href="<?= BASE_URL ?>/<?= $isDean?'dean':'admin' ?>/review-queue.php">Review Queue</a> › <?php endif; ?>
      <?= h(fullName($user)) ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $formId ?>" target="_blank"
       class="btn btn-primary"><i class="bi bi-printer me-1"></i>Print / Download PDF</a>
    <a href="<?= BASE_URL ?>/exports/export-excel.php?id=<?= $formId ?>"
       class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export CSV</a>
    <?php if($isDean && !$isOwner && in_array($form['status'], ['submitted','under_review','reviewed'])): ?>
    <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $formId ?>"
       class="btn btn-warning fw-bold">
      <i class="bi bi-star-fill me-1"></i>
      <?= $form['status']==='reviewed' ? 'Edit Ratings' : 'Rate This IPCR' ?>
    </a>
    <?php endif; ?>
    <a href="javascript:history.back()" class="btn btn-outline-secondary">← Back</a>
  </div>
</div>

<!-- ── HERO BANNER ── -->
<div class="view-hero">
  <div>
    <h2><?= h(fullName($user)) ?></h2>
    <div class="hero-meta">
      <span class="hero-chip"><i class="bi bi-person-badge"></i><?= h($user['employee_id']) ?></span>
      <span class="hero-chip"><i class="bi bi-building"></i><?= h($user['department']??'—') ?></span>
      <span class="hero-chip"><i class="bi bi-briefcase"></i><?= h($user['position']??'—') ?></span>
      <span class="hero-chip"><i class="bi bi-calendar3"></i><?= h($period['name']??'—') ?></span>
      <?php if($form['submitted_at']): ?>
      <span class="hero-chip"><i class="bi bi-send"></i>Submitted: <?= h(date('M d, Y', strtotime($form['submitted_at']))) ?></span>
      <?php endif; ?>
      <?php if($dean): ?>
      <span class="hero-chip"><i class="bi bi-person-check"></i>Evaluator: <?= h(fullName($dean)) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-status">
    <span class="status-pill"><?= h(ucwords(str_replace('_',' ',$form['status']))) ?></span>
    <?php if($form['is_late']): ?>
    <span class="late-badge" style="font-size:.72rem">Late Submission</span>
    <?php endif; ?>
    <?php if($form['composite_rating']): ?>
    <div style="text-align:right">
      <div style="font-size:.65rem;opacity:.65;text-transform:uppercase;letter-spacing:.08em">Final Rating</div>
      <div style="font-size:1.6rem;font-weight:900;line-height:1;color:var(--accent-light)"><?= number_format($form['composite_rating'], 2) ?></div>
      <div style="font-size:.78rem;font-weight:600;color:rgba(255,255,255,.85)"><?= h($form['adjectival_rating']??'') ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── RATE NOW BANNER (Dean/Admin) ── -->
<?php if($canRate): ?>
<div style="background:linear-gradient(90deg,#fff8e1,#fffde7);border:2px solid var(--accent);border-radius:var(--border-r);padding:14px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-star-fill" style="color:var(--accent);font-size:1.3rem"></i>
    <div>
      <div style="font-weight:700;color:#7c5200;font-size:.92rem">This IPCR is ready for evaluation</div>
      <div style="font-size:.78rem;color:#92400e">Click the button to start rating each success indicator</div>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $formId ?>"
     style="background:var(--accent);color:#fff;border:none;padding:10px 24px;border-radius:8px;font-weight:700;font-size:.9rem;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap">
    <i class="bi bi-star-fill"></i>
    <?= $form['status']==='reviewed' ? 'Edit Ratings' : 'Rate This IPCR' ?>
  </a>
</div>
<?php endif; ?>

<!-- ── RETURN REMARKS ── -->
<?php if($form['return_remarks']): ?>
<div class="return-alert">
  <i class="bi bi-arrow-return-left text-warning fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <div class="fw-bold mb-1" style="color:#92400e">Returned for Revision</div>
    <div style="font-size:.85rem;color:#7c5200"><?= h($form['return_remarks']) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- ── RATING SUMMARY (if rated) ── -->
<?php if($showRatings): ?>
<div class="rating-card">
  <div class="rating-card-header">
    <h6><i class="bi bi-calculator me-2"></i>Rating Summary</h6>
    <span style="font-size:.72rem;opacity:.75">Q×<?=$qW?>% + E×<?=$eW?>% + T×<?=$tW?>% = Average</span>
  </div>
  <div class="rating-card-body">
    <?php foreach($mfos as $mid=>$mfo): ?>
    <div class="mfo-rating-row">
      <div class="mfo-rating-label">
        <?= h($mfo['code']) ?>: <?= h($mfo['title']) ?>
        <span>(<?= $mfo['weight'] ?>% weight)</span>
      </div>
      <div class="mfo-score"><?= isset($mfoTotals[$mid])&&$mfoTotals[$mid]>0?number_format($mfoTotals[$mid],4):'—' ?></div>
    </div>
    <?php endforeach; ?>
    <div class="composite-banner">
      <div>
        <div class="composite-label">Composite / Total Weighted Rating</div>
        <div class="composite-num"><?= $form['composite_rating']?number_format($form['composite_rating'], 2):'—' ?></div>
        <div class="composite-adj"><?= h($form['adjectival_rating']??'') ?></div>
      </div>
      <?php if($form['dean_remarks']||$form['admin_remarks']): ?>
      <div style="max-width:380px;font-size:.78rem;opacity:.88">
        <?php if($form['dean_remarks']): ?>
        <div><strong>Rater's Comments:</strong> <?= h($form['dean_remarks']) ?></div>
        <?php endif; ?>
        <?php if($form['admin_remarks']): ?>
        <div class="mt-1"><strong>Admin Remarks:</strong> <?= h($form['admin_remarks']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── MFO SECTIONS ── -->
<?php foreach($mfos as $mfoId=>$mfo): ?>
<div class="view-mfo-section">
  <div class="view-mfo-header">
    <h5><?= h($mfo['code']) ?>: <?= h($mfo['title']) ?></h5>
    <span class="mfo-wt-badge"><?= $mfo['weight'] ?>% of Total</span>
  </div>

  <?php foreach($mfo['kras'] as $kraId=>$kra): ?>
  <div class="view-kra-header">
    <h6><i class="bi bi-layers me-2"></i><?= h($kra['code']) ?>: <?= h($kra['title']) ?></h6>
    <span class="badge" style="background:var(--primary-xlight);color:var(--primary);border:1px solid #b8d9c4;font-size:.68rem">KRA Wt: <?= $kra['weight'] ?>%</span>
  </div>

  <?php foreach($kra['sis'] as $idx=>$si):
    $siEv = $evMap[$si['si_id']] ?? [];
    $evCount = count($siEv);
    $hasRating = $si['q_rating'] !== null;
  ?>
  <div class="view-si">

    <!-- SI Header -->
    <div class="view-si-header">
      <span class="si-num"><?= $idx+1 ?></span>
      <div class="si-info">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div class="si-name">
            <?= h($si['si_title']) ?>
            <?php if(!$si['is_required']): ?>
            <span class="badge bg-secondary ms-1" style="font-size:.6rem;vertical-align:middle">Optional</span>
            <?php endif; ?>
          </div>
          <?php if($si['si_weight']>0): ?><span class="si-wt">Wt: <?= $si['si_weight'] ?>%</span><?php endif; ?>
        </div>
        <div class="si-chips">
          <?php if($si['quality_target']): ?>
          <span class="si-chip"><strong>Target:</strong> <?= h($si['quality_target']) ?></span>
          <?php endif; ?>
          <?php if($si['efficiency_target']): ?>
          <span class="si-chip"><strong>Efficiency:</strong> <?= h($si['efficiency_target']) ?></span>
          <?php endif; ?>
          <?php if($si['timeliness_target']): ?>
          <span class="si-chip accent"><i class="bi bi-clock"></i> <?= h($si['timeliness_target']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- SI Body -->
    <div class="view-si-body">
      <div class="row g-3">

        <!-- LEFT: Planned / Actual / Accomplishment -->
        <div class="col-lg-7">

          <!-- Planned vs Actual grid -->
          <div class="pa-grid">
            <div class="pa-half">
              <div class="pa-label"><i class="bi bi-calendar2-check"></i>Planned</div>
              <div class="pa-fields">
                <div>
                  <div class="pa-field-label">Magnitude</div>
                  <div class="pa-field-val <?= !$si['planned_magnitude']?'empty':'' ?>"><?= $si['planned_magnitude']?h($si['planned_magnitude']):'—' ?></div>
                </div>
                <div>
                  <div class="pa-field-label">Budget</div>
                  <div class="pa-field-val <?= !$si['planned_budget']?'empty':'' ?>"><?= $si['planned_budget']?h($si['planned_budget']):'—' ?></div>
                </div>
                <div>
                  <div class="pa-field-label">Date</div>
                  <div class="pa-field-val <?= !$si['planned_date']?'empty':'' ?>"><?= $si['planned_date']?h($si['planned_date']):'—' ?></div>
                </div>
              </div>
            </div>
            <div class="pa-half">
              <div class="pa-label actual"><i class="bi bi-check2-circle"></i>Actual</div>
              <div class="pa-fields">
                <div>
                  <div class="pa-field-label">Magnitude</div>
                  <div class="pa-field-val hl <?= !$si['actual_magnitude']?'empty':'' ?>"><?= $si['actual_magnitude']?h($si['actual_magnitude']):'—' ?></div>
                </div>
                <div>
                  <div class="pa-field-label">Budget</div>
                  <div class="pa-field-val <?= !$si['actual_budget']?'empty':'' ?>"><?= $si['actual_budget']?h($si['actual_budget']):'—' ?></div>
                </div>
                <div>
                  <div class="pa-field-label">Date</div>
                  <div class="pa-field-val <?= !$si['actual_date']?'empty':'' ?>"><?= $si['actual_date']?h($si['actual_date']):'—' ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Accomplishment -->
          <div>
            <div class="acc-label">Narrative / Accomplishment</div>
            <div class="accomplishment-box <?= !$si['actual_output']?'empty':'' ?>">
              <?= $si['actual_output']?h($si['actual_output']):'Not provided' ?>
            </div>
          </div>

          <!-- Dean Evaluation -->
          <?php if($hasRating): ?>
          <div class="dean-eval">
            <div class="dean-eval-title"><i class="bi bi-person-check-fill"></i>Dean's Evaluation</div>
            <div class="qet-pills">
              <div class="qet-pill"><span class="ql">Quality</span><span class="qv"><?= h($si['q_rating']) ?></span></div>
              <div class="qet-pill"><span class="ql">Efficiency</span><span class="qv"><?= h($si['e_rating']) ?></span></div>
              <div class="qet-pill"><span class="ql">Timeliness</span><span class="qv"><?= h($si['t_rating']) ?></span></div>
              <div class="qet-pill avg"><span class="ql">Average</span><span class="qv"><?= $si['average_rating']?number_format($si['average_rating'], 2):'—' ?></span></div>
              <?= evBadge($si['dean_verify_status']??'pending') ?>
            </div>
            <?php if($si['dean_remarks']): ?>
            <div style="font-size:.78rem;color:var(--gray-600);margin-top:4px">
              <i class="bi bi-chat-left-text me-1"></i><?= h($si['dean_remarks']) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div>

        <!-- RIGHT: Evidence -->
        <div class="col-lg-5">
          <div class="acc-label"><i class="bi bi-paperclip me-1"></i>Supporting Evidence
            <?php if($evCount>0): ?><span class="badge ms-1" style="background:var(--success);color:#fff;font-size:.62rem"><?= $evCount ?> file(s)</span><?php endif; ?>
          </div>
          <div class="ev-compact-list">
            <?php if(empty($siEv)): ?>
            <div class="ev-empty"><i class="bi bi-folder2-open"></i>No evidence attached</div>
            <?php else: ?>
            <?php foreach($siEv as $ev):
              $ext=strtolower(pathinfo($ev['original_name'],PATHINFO_EXTENSION));
              [$ico,$cls]=evIcon2($ext);
            ?>
            <div class="ev-compact-row">
              <div class="ev-ico-sm <?= $cls ?>"><i class="bi <?= $ico ?>"></i></div>
              <div class="ev-info-sm">
                <div class="ev-name-sm" title="<?= h($ev['original_name']) ?>"><?= h($ev['original_name']) ?></div>
                <div class="ev-date-sm">
                  <?= $ev['file_size']?formatFileSize($ev['file_size']).' · ':'' ?>
                  <?= h(date('M d, Y', strtotime($ev['uploaded_at']))) ?>
                </div>
              </div>
              <?= evBadge($ev['verify_status']??'pending') ?>
              <button onclick="previewFile(<?= $ev['id'] ?>, '<?= addslashes(h($ev['original_name'])) ?>', '<?= addslashes($ev['file_type']??'') ?>')"
                 class="ev-dl-btn">
                <i class="bi bi-eye"></i> View
              </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>

</div>
<?php endforeach; ?>

<!-- ── WORKFLOW HISTORY ── -->
<?php if($history): ?>
<div class="card mt-4 no-print">
  <div class="card-header"><h6><i class="bi bi-clock-history me-2"></i>Workflow History</h6></div>
  <div class="card-body">
    <div class="timeline">
      <?php foreach($history as $ht): ?>
      <div class="tl-item">
        <div class="tl-dot"></div>
        <div class="tl-content">
          <div class="tl-action"><?= h(ucwords(str_replace('_',' ',$ht['action']))) ?></div>
          <div class="tl-meta">
            <span><i class="bi bi-person me-1"></i><?= h(trim($ht['actor'])?:' System') ?></span>
            <span><i class="bi bi-clock me-1"></i><?= h(date('M d, Y H:i', strtotime($ht['done_at']))) ?></span>
          </div>
          <?php if($ht['remarks']): ?>
          <div class="tl-remark"><?= h($ht['remarks']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div style="height:24px"></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

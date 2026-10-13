<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];
$currentUser   = getCurrentUser();

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) { http_response_code(404); exit('Form not found'); }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?"); $stmt->execute([$formId]); $form = $stmt->fetch();
if (!$form) { http_response_code(404); exit('Form not found'); }

$isOwner = $form['user_id'] == $currentUserId;
if (!$isOwner && $currentRole === 'dean') {
    $f2 = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?"); $f2->execute([$form['user_id']]); $f2 = $f2->fetch();
    if (!$f2 || $f2['assigned_dean_id'] != $currentUserId) { http_response_code(403); exit('Access denied'); }
} elseif (!$isOwner && $currentRole !== 'admin') { http_response_code(403); exit('Access denied'); }

$u = $db->prepare("SELECT * FROM users WHERE id=?"); $u->execute([$form['user_id']]); $user = $u->fetch();
$p = $db->prepare("SELECT * FROM rating_periods WHERE id=?"); $p->execute([$form['period_id']]); $period = $p->fetch();

$dean = null;
if ($form['reviewed_by']) { $d=$db->prepare("SELECT * FROM users WHERE id=?"); $d->execute([$form['reviewed_by']]); $dean=$d->fetch(); }
if (!$dean && $user['assigned_dean_id']) { $d=$db->prepare("SELECT * FROM users WHERE id=?"); $d->execute([$user['assigned_dean_id']]); $dean=$d->fetch(); }
$approv = null;
if ($form['approved_by']) { $a=$db->prepare("SELECT * FROM users WHERE id=?"); $a->execute([$form['approved_by']]); $approv=$a->fetch(); }

$instName = $db->query("SELECT config_value FROM system_config WHERE config_key='institution_name'")->fetchColumn() ?: 'Isabela State University';
$qW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn()?:40);
$eW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn()?:40);
$tW=(int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn()?:20);

// Fixed ISU adjectival rating scale
$scaleRows = [
    ['value'=>5,'label'=>'Outstanding (O)',       'range'=>'4.51–5.00'],
    ['value'=>4,'label'=>'Very Satisfactory (VS)','range'=>'3.51–4.50'],
    ['value'=>3,'label'=>'Satisfactory (S)',      'range'=>'2.51–3.50'],
    ['value'=>2,'label'=>'Unsatisfactory (US)',   'range'=>'1.51–2.50'],
    ['value'=>1,'label'=>'Poor (P)',              'range'=>'1.00–1.50'],
];

$rows = $db->prepare("
    SELECT m.id mfo_id, m.code mfo_code, m.title mfo_title, m.weight mfo_weight,
           k.id kra_id, k.code kra_code, k.title kra_title, k.weight kra_weight,
           s.id si_id, s.code si_code, s.title si_title,
           s.quality_target, s.efficiency_target, s.timeliness_target, s.si_weight, s.is_required,
           e.planned_magnitude, e.planned_budget, e.planned_date,
           e.actual_magnitude, e.actual_budget, e.actual_date, e.actual_output,
           e.q_rating, e.e_rating, e.t_rating, e.average_rating,
           e.dean_remarks, e.is_na
    FROM mfos m
    JOIN kras k ON k.mfo_id=m.id
    JOIN success_indicators s ON s.kra_id=k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id=s.id AND e.form_id=?
    WHERE m.template_id=?
    ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$rows->execute([$formId, $form['template_id']]);
$allRows = $rows->fetchAll();

$mfos=[];
foreach($allRows as $row){
    $mid=$row['mfo_id']; $kid=$row['kra_id'];
    if(!isset($mfos[$mid])) $mfos[$mid]=['code'=>$row['mfo_code'],'title'=>$row['mfo_title'],'weight'=>$row['mfo_weight'],'kras'=>[]];
    if(!isset($mfos[$mid]['kras'][$kid])) $mfos[$mid]['kras'][$kid]=['code'=>$row['kra_code'],'title'=>$row['kra_title'],'weight'=>$row['kra_weight'],'sis'=>[]];
    $mfos[$mid]['kras'][$kid]['sis'][]=$row;
}

$mfoTotals=[];
foreach($mfos as $mid=>$mfo){
    $t=0;
    foreach($mfo['kras'] as $kra)
        foreach($kra['sis'] as $si)
            if(!$si['is_na'] && $si['average_rating']!==null && $si['si_weight']>0)
                $t += floatval($si['average_rating']) * (floatval($si['si_weight']) / 100);
    $mfoTotals[$mid] = $t;
}

$composite  = $form['composite_rating'] ? number_format($form['composite_rating'], 2) : '—';
$adjectival = $form['adjectival_rating'] ?? '—';

$periodStart = $period['start_date'] ? strtoupper(date('F Y', strtotime($period['start_date']))) : '';
$periodEnd   = $period['end_date']   ? strtoupper(date('F Y', strtotime($period['end_date'])))   : '';
$periodRange = $periodStart && $periodEnd ? "$periodStart – $periodEnd" : strtoupper($period['name'] ?? '');

function e($v){ return htmlspecialchars((string)($v??''), ENT_QUOTES, 'UTF-8'); }
function rt($v){ return ($v!==null&&$v!=='') ? number_format((float)$v, 0) : ''; }
function rd($v, $d=2){ return ($v!==null&&$v!=='') ? number_format((float)$v, $d) : ''; }

auditLog('ipcr_printed','ipcr_form',$formId,null,'print','IPCR printed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPCR — <?=e(fullName($user))?></title>
<style>
*{ box-sizing:border-box; margin:0; padding:0; }
body{ font-family:Arial,Helvetica,sans-serif; font-size:6pt; background:#888; color:#000; }

.toolbar{
  background:#1a5c2a; color:#fff; padding:8px 18px;
  display:flex; align-items:center; justify-content:space-between;
  font-family:'Segoe UI',sans-serif; font-size:12px;
  position:sticky; top:0; z-index:100;
}
.btn-print{ background:#fff; color:#1a5c2a; border:none; padding:6px 18px; border-radius:5px; font-weight:700; cursor:pointer; font-size:12px; }
.btn-close{ background:transparent; color:#fff; border:1px solid rgba(255,255,255,.4); padding:6px 14px; border-radius:5px; cursor:pointer; font-size:11px; }

.print-note{
  background:#fff3cd; border-left:4px solid #ffc107; padding:12px 15px; margin-bottom:10px;
  font-family:'Segoe UI',sans-serif; font-size:13px; border-radius:4px;
}
.print-note strong{ color:#856404; }

.page{
  width:297mm;
  min-height:210mm;
  margin:8px auto;
  background:#fff;
  padding:3mm 4mm;
  box-shadow:0 2px 16px rgba(0,0,0,.35);
}

/* All table cells */
table{ border-collapse:collapse; width:100%; }
td, th{
  border:0.5px solid #444;
  padding:0.5px 1.5px;
  vertical-align:middle;
  font-size:5.5pt;
  line-height:1.05;
}

/* ── HEADER ── */
.logo-cell{ width:15mm; text-align:center; vertical-align:middle; }
.logo-cell img{ width:13mm; height:13mm; object-fit:contain; }
.inst-cell{
  width:28mm; text-align:center; font-weight:700; font-size:6pt;
  vertical-align:middle; line-height:1.2;
}
.inst-sub{ font-size:5.5pt; font-weight:400; }
.commitment-cell{ font-size:5.8pt; line-height:1.2; vertical-align:top; padding:1px 2px; }

/* Adjectival table in header */
.adj-tbl{ width:100%; border-collapse:collapse; font-size:5pt; }
.adj-tbl th{ background:#ccc; border:0.5px solid #444; padding:0.5px 1px; font-size:5pt; text-align:center; }
.adj-tbl td{ border:0.5px solid #444; padding:0.5px 1px; font-size:5pt; }

/* Prepared by box */
.prepby-cell{ font-size:5.8pt; vertical-align:top; padding:1px 2px; width:34mm; }

/* ── MAIN TABLE HEADERS ── */
.th-main{ background:#fff; text-align:center; font-weight:700; font-size:4.8pt; vertical-align:middle; line-height:1.05; }
.th-grp { background:#bbb; text-align:center; font-weight:700; font-size:4.8pt; vertical-align:middle; line-height:1.05; }
.th-sub { background:#ddd; text-align:center; font-weight:700; font-size:4.5pt; vertical-align:middle; line-height:1.05; }

/* ── DATA ROWS ── */
td.c   { text-align:center; }
td.r   { text-align:right; }
td.num { text-align:center; font-weight:700; font-size:5.5pt; }
td.avg { text-align:center; font-weight:700; font-size:5.5pt; background:#fffbe6; }
td.wtr { text-align:center; font-weight:700; font-size:5.5pt; background:#e6f4ea; }

/* MFO header row */
.mfo-row td{ background:#c6dfc6; font-weight:700; font-size:6pt; padding:0.5px 1.5px; }
/* KRA header row */
.kra-row td{ background:#eaf3ea; font-weight:700; font-style:italic; font-size:5.5pt; padding:0.5px 1.5px; }
/* Subtotal row */
.sub-row td{ background:#d0e8d0; font-weight:700; font-size:5.8pt; padding:0.5px 1.5px; }

/* ── BOTTOM ── */
.rater-comments{ font-size:5.8pt; }
.accept-line{ font-size:5.8pt; margin:1px 0; display:flex; align-items:center; gap:2px; }
.chk{ width:8px; height:8px; border:0.5px solid #333; display:inline-block; flex-shrink:0; }
.chk-on{ background:#333; }

/* Final summary table */
.fs-hdr td{ background:#1a5c2a; color:#fff; font-weight:700; font-size:6.5pt; padding:1px 2px; }
.fs-row td{ font-size:5.8pt; padding:0.5px 2px; }
.fs-total td{ background:#c6e6c6; font-weight:700; font-size:8pt; padding:1px 2px; }
.fs-final td{ background:#1a5c2a; color:#fff; font-weight:900; font-size:10pt; padding:1px 2px; }

/* Signature lines */
.sig-line{ border-top:0.5px solid #000; margin-top:8px; padding-top:0.5px; text-align:center; font-weight:700; font-size:6pt; }
.sig-sub{ text-align:center; font-size:5.5pt; }

@media print{
  .toolbar{ display:none!important; }
  body{ background:#fff; margin:0; padding:0; }
  .page{ 
    width:100%; 
    height:100%;
    margin:0; 
    padding:2mm 3mm; 
    box-shadow:none;
    page-break-after:avoid;
  }
  @page{ 
    size:A4 landscape; 
    margin:3mm;
  }
}

</style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar">
  <span style="font-weight:700">📄 IPCR — <?=e(fullName($user))?> · <?=e($period['name']??'')?></span>
  <div>
    <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
  </div>
</div>

<script>
// Auto-set landscape orientation on print
window.onbeforeprint = function() {
  // This is a hint to the browser - user can still override
  document.body.style.size = 'A4 landscape';
};
</script>

<div class="page">

<!-- ═══ FORM TITLE ═══════════════════════════════════════════ -->
<div style="text-align:center;font-weight:700;font-size:7pt;text-transform:uppercase;
     border:1px solid #000;padding:1.5px;letter-spacing:.03em;margin-bottom:0">
  INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW (IPCR)
  <?php if($adjectival!=='—'): ?>
  <span style="color:#b91c1c"> (<?=e(strtoupper($adjectival))?>)</span>
  <?php endif; ?>
</div>

<!-- ═══ HEADER TABLE ══════════════════════════════════════════ -->
<table>
  <!-- ROW 1: Logo | Institution | Commitment (wide) | Adj table | Prepared by -->
  <tr>
    <!-- LOGO -->
    <td class="logo-cell" rowspan="3">
      <img src="<?=BASE_URL?>/assets/images/r.png" alt="ISU"
           onerror="this.style.visibility='hidden'">
    </td>
    <!-- INSTITUTION NAME -->
    <td class="inst-cell" rowspan="3">
      Isabela State University<br>
      <span class="inst-sub">Ilagan Campus</span><br>
      <span class="inst-sub">Ilagan City, Isabela</span>
    </td>
    <!-- COMMITMENT TEXT -->
    <td class="commitment-cell" colspan="2">
      I, <strong><?=e(fullName($user))?></strong>,
      <?=e($user['position']??'')?> of the
      <?=e($user['department']??$user['college']??'')?>,
      commit to deliver and agree to be rated on the attainment of the following targets
      in accordance with the indicated measures for the period
      <strong><?=e($periodRange)?></strong>.
    </td>
    <!-- ADJECTIVAL SCALE TABLE -->
    <td style="width:48mm;vertical-align:top;padding:1px 2px" rowspan="1">
      <div style="font-weight:700;font-size:6pt;text-align:center;margin-bottom:0.5px">Adjectival Ratings</div>
      <table class="adj-tbl">
        <thead>
          <tr><th>Rating</th><th>Range</th><th>Adjectival</th></tr>
        </thead>
        <tbody>
          <?php foreach($scaleRows as $sr): ?>
          <tr>
            <td style="text-align:center"><?=$sr['value']?></td>
            <td style="text-align:center"><?=e($sr['range'])?></td>
            <td><?=e($sr['label'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </td>
    <!-- PREPARED BY -->
    <td class="prepby-cell" rowspan="1">
      <div style="font-weight:700;margin-bottom:0.5px;font-size:5.8pt">Prepared by:</div>
      <div style="margin-bottom:8px">&nbsp;</div>
      <div style="border-top:0.5px solid #000;text-align:center;font-weight:700;padding-top:0.5px;font-size:5.8pt">
        <?=e(fullName($user))?>
      </div>
      <div style="text-align:center;font-size:5.5pt"><?=e($user['position']??'FACULTY')?></div>
      <div style="margin-top:1px;font-size:5.5pt">Date: <?=$form['submitted_at']?date('M d, Y',strtotime($form['submitted_at'])):'__________'?></div>
    </td>
  </tr>
  <!-- ROW 2: Approved by | Signature -->
  <tr>
    <td style="padding:1px 2px">
      <span style="font-weight:700;font-size:5.8pt">Approved by: </span>
      <span style="border-bottom:0.5px solid #333;display:inline-block;min-width:50mm;font-size:5.8pt">
        <?=$dean ? e(fullName($dean)) : '' ?>
      </span>
    </td>
    <td style="padding:1px 2px">
      <span style="font-weight:700;font-size:5.8pt">Signature: </span>
      <span style="display:inline-block;min-width:30mm">&nbsp;</span>
    </td>
    <td style="vertical-align:top;padding:1px 2px" rowspan="2">
      <div style="font-weight:700;font-size:5.8pt">Signature: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
      <div style="margin-top:3px"><strong style="font-size:5.8pt"><?=e(fullName($user))?></strong></div>
      <div style="font-size:5.5pt"><?=e($user['position']??'Faculty')?></div>
      <div style="font-size:5.5pt">Date: <?=$form['submitted_at']?date('M d, Y',strtotime($form['submitted_at'])):'__________'?></div>
    </td>
    <td style="vertical-align:top;padding:1px 2px" rowspan="2">&nbsp;</td>
  </tr>
  <!-- ROW 3: Name | Position -->
  <tr>
    <td style="padding:1px 2px">
      <span style="font-weight:700;font-size:5.8pt">Name: </span>
      <strong style="font-size:5.8pt"><?=$dean ? e(fullName($dean)) : str_repeat('_',20)?></strong>
    </td>
    <td style="padding:1px 2px">
      <span style="font-weight:700;font-size:5.8pt">Position: </span>
      <span style="font-size:5.8pt"><?=$dean ? e($dean['position']??'Program Chair') : str_repeat('_',16)?></span>
    </td>
  </tr>
</table>

<!-- ═══ MAIN IPCR TABLE ═══════════════════════════════════════
  COLUMNS (matching original exactly):
  1. % Distrib of Fxns
  2. Wt.%
  3. MAJOR FINAL OUTPUTS
  ── SUCCESS INDICATORS ──
  4. Code
  5. Descriptions
  6. Planned Magnitude
  7. Planned Budget
  8. Planned Date
  9. Actual Magnitude
  10. Actual Budget
  11. Actual Date
  ── ACCOMPLISHMENTS ──
  12. Actual Output/Accomplishment
  13. Actual Magnitude  (2nd actual magnitude from accomplishments section)
  14. Actual Budget
  15. Actual Date
  ── RATINGS ──
  16. Q
  17. E
  18. T
  19. A (Average)
  20. Wt.
  21. REMARKS
═══════════════════════════════════════════════════════════════ -->
<table style="margin-top:0">
  <colgroup>
    <col style="width:2.5%">  <!-- % Distrib -->
    <col style="width:2%">    <!-- Wt.% -->
    <col style="width:10%">   <!-- MAJOR FINAL OUTPUTS -->
    <col style="width:2.5%">  <!-- Code -->
    <col style="width:10%">   <!-- Descriptions -->
    <col style="width:3.8%">  <!-- Planned Mag -->
    <col style="width:3%">    <!-- Planned Bud -->
    <col style="width:3.8%">  <!-- Planned Date -->
    <col style="width:3.8%">  <!-- Actual Mag (SI section) -->
    <col style="width:3%">    <!-- Actual Bud (SI section) -->
    <col style="width:3.8%">  <!-- Actual Date (SI section) -->
    <col style="width:6.5%">  <!-- Actual Output/Accomp -->
    <col style="width:3.5%">  <!-- Actual Mag (Accomp section) -->
    <col style="width:2.5%">  <!-- Actual Bud (Accomp) -->
    <col style="width:3.5%">  <!-- Actual Date (Accomp) -->
    <col style="width:2.5%">  <!-- Q -->
    <col style="width:2.5%">  <!-- E -->
    <col style="width:2.5%">  <!-- T -->
    <col style="width:3%">    <!-- A (avg) -->
    <col style="width:2.5%">  <!-- Wt. -->
    <col style="width:7%">    <!-- REMARKS -->
  </colgroup>
  <thead>
    <!-- Row 1 -->
    <tr>
      <th class="th-main" rowspan="3" style="width:3%">%<br>Distrib.<br>of<br>Fxns</th>
      <th class="th-main" rowspan="3" style="width:2.5%">Wt.<br>%</th>
      <th class="th-main" rowspan="3" style="width:12%">MAJOR FINAL OUTPUTS<br><span style="font-weight:400;font-size:5pt">(Add more rows if necessary)</span></th>
      <th class="th-grp" colspan="8">SUCCESS INDICATORS</th>
      <th class="th-grp" colspan="4">ACCOMPLISHMENTS</th>
      <th class="th-grp" colspan="5">Ratings</th>
      <th class="th-main" rowspan="3">REMARKS</th>
    </tr>
    <!-- Row 2 -->
    <tr>
      <th class="th-sub" rowspan="2">Code</th>
      <th class="th-sub" rowspan="2">Descriptions</th>
      <th class="th-grp" colspan="3">Planned</th>
      <th class="th-grp" colspan="3">Actual</th>
      <th class="th-sub" rowspan="2">Actual<br>Output/<br>Accomp.</th>
      <th class="th-sub" rowspan="2">Actual<br>Magnitude</th>
      <th class="th-sub" rowspan="2">Actual<br>Budget</th>
      <th class="th-sub" rowspan="2">Actual<br>Date</th>
      <th class="th-sub" rowspan="2">Q<br><?=$qW?>%</th>
      <th class="th-sub" rowspan="2">E<br><?=$eW?>%</th>
      <th class="th-sub" rowspan="2">T<br><?=$tW?>%</th>
      <th class="th-sub" rowspan="2">A<br>(Avg)</th>
      <th class="th-sub" rowspan="2">Wt.</th>
    </tr>
    <!-- Row 3 -->
    <tr>
      <th class="th-sub">Planned<br>Magnitude</th>
      <th class="th-sub">Planned<br>Budget</th>
      <th class="th-sub">Planned<br>Date</th>
      <th class="th-sub">Actual<br>Magnitude</th>
      <th class="th-sub">Actual<br>Budget</th>
      <th class="th-sub">Actual<br>Date</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($mfos as $mid=>$mfo):
    $mfoWtTotal = 0;
  ?>

    <!-- MFO ROW -->
    <tr class="mfo-row">
      <td class="c"><?=e($mfo['weight'])?>%</td>
      <td>&nbsp;</td>
      <td colspan="19" style="font-size:6.5pt;padding-left:2px">
        <strong><?=e($mfo['code'])?>: <?=e($mfo['title'])?></strong>
      </td>
    </tr>

    <?php foreach($mfo['kras'] as $kid=>$kra): ?>

    <!-- KRA ROW -->
    <tr class="kra-row">
      <td class="c" style="font-size:5.5pt"><?=e($kra['weight'])?>%</td>
      <td>&nbsp;</td>
      <td colspan="19" style="padding-left:3px">
        <?=e($kra['code'])?>: <?=e($kra['title'])?>
      </td>
    </tr>

    <?php foreach($kra['sis'] as $si):
      $avg  = $si['average_rating'];
      $siWt = floatval($si['si_weight']);
      $wtr  = ($avg !== null && $siWt > 0) ? round(floatval($avg) * $siWt / 100, 2) : null;
      if ($wtr !== null) $mfoWtTotal += $wtr;
    ?>

    <!-- SI DATA ROW -->
    <tr>
      <td class="c" style="font-size:5.5pt"><?=$siWt>0 ? $siWt.'%' : ''?></td>
      <td class="c" style="font-size:5.5pt"><?=$siWt>0 ? $siWt : ''?></td>
      <td style="font-size:5.8pt;padding-left:1.5px"><?=e($si['si_title']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['si_code']??'')?></td>
      <td style="font-size:5.5pt">
        <?php if($si['quality_target']): ?><?=e($si['quality_target'])?><?php endif; ?>
        <?php if($si['efficiency_target']): ?><br><?=e($si['efficiency_target'])?><?php endif; ?>
        <?php if($si['timeliness_target']): ?><br><?=e($si['timeliness_target'])?><?php endif; ?>
      </td>
      <td class="c" style="font-size:5.5pt"><?=e($si['planned_magnitude']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['planned_budget']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['planned_date']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_magnitude']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_budget']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_date']??'')?></td>
      <td style="font-size:5.5pt"><?=e($si['actual_output']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_magnitude']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_budget']??'')?></td>
      <td class="c" style="font-size:5.5pt"><?=e($si['actual_date']??'')?></td>
      <td class="num"><?=rt($si['q_rating'])?></td>
      <td class="num"><?=rt($si['e_rating'])?></td>
      <td class="num"><?=rt($si['t_rating'])?></td>
      <td class="avg"><?=rd($avg,2)?></td>
      <td class="wtr"><?=$wtr!==null ? number_format($wtr,2) : ''?></td>
      <td style="font-size:5.5pt"><?=e($si['dean_remarks']??'')?></td>
    </tr>

    <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- MFO SUBTOTAL ROW -->
    <tr class="sub-row">
      <td colspan="19" style="text-align:right;font-size:6.5pt;padding-right:2px;color:#0d3d1e">
        Total for <?=e($mfo['code'])?>: <?=e($mfo['title'])?>:
      </td>
      <td class="c" style="background:#a8d8a8;font-weight:700;font-size:7pt">
        <?=$mfoWtTotal > 0 ? number_format($mfoWtTotal,2) : '—'?>
      </td>
      <td>&nbsp;</td>
    </tr>

  <?php endforeach; ?>
  </tbody>
</table>

<!-- ═══ BOTTOM SECTION ════════════════════════════════════════ -->
<table style="margin-top:1px">
  <tr>
    <!-- LEFT: Rater comments + Accept/Reject -->
    <td style="width:42%;vertical-align:top;padding:2px 3px">
      <div class="rater-comments" style="font-weight:700;margin-bottom:1px">
        Rater's Comments and Recommendations for development purposes, rewards/promotions:
      </div>
      <div style="border-bottom:0.5px solid #aaa;min-height:12px;margin-bottom:2px;font-size:5.8pt">
        <?=e($form['dean_remarks']??'')?>
      </div>
      <div class="accept-line">
        <span class="chk <?= $form['status'] !== 'returned' ? 'chk-on' : '' ?>"></span>
        I accept the rating given me herein.
      </div>
      <div class="accept-line">
        <span class="chk <?= $form['status'] === 'returned' ? 'chk-on' : '' ?>"></span>
        I reject the rating given me herein.
      </div>
      <?php if($form['return_remarks']??''): ?>
      <div style="font-size:5.8pt;margin-top:2px">Comments: <?=e($form['return_remarks'])?></div>
      <?php endif; ?>
    </td>
    <!-- RIGHT: Final Ratings Summary -->
    <td style="width:58%;padding:0;vertical-align:top">
      <table>
        <tr class="fs-hdr">
          <td colspan="2" style="text-align:center;font-size:7pt;letter-spacing:.03em">
            FINAL RATINGS SUMMARY
          </td>
        </tr>
        <?php foreach($mfos as $mid=>$mfo): ?>
        <tr class="fs-row">
          <td><?=e($mfo['code'])?>: <?=e($mfo['title'])?> (<?=e($mfo['weight'])?>%)</td>
          <td style="text-align:right;font-weight:700;width:20%;white-space:nowrap">
            <?=isset($mfoTotals[$mid]) && $mfoTotals[$mid]>0 ? number_format($mfoTotals[$mid],2) : '—'?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr class="fs-total">
          <td style="border-top:1px solid #1a5c2a">Total Rating:</td>
          <td style="border-top:1px solid #1a5c2a;text-align:right;font-size:9pt;white-space:nowrap"><?=$composite?></td>
        </tr>
        <tr class="fs-final">
          <td>Final Average Rating:</td>
          <td style="text-align:right;font-size:11pt;white-space:nowrap"><?=$composite?></td>
        </tr>
        <tr>
          <td style="font-weight:700;font-size:6.5pt;padding:1px 2px">Adjectival Rating:</td>
          <td style="text-align:right;font-weight:900;font-size:8.5pt;color:#1a5c2a;padding:1px 2px"><?=e($adjectival)?></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ═══ SIGNATURE SECTION ════════════════════════════════════= -->
<table style="margin-top:2px">
  <tr>
    <!-- Col 1: Rater / Dean -->
    <td style="width:33.33%;padding:2px 4px 4px;vertical-align:bottom">
      <div style="font-size:5.8pt;margin-bottom:10px">
        I hereby certify that this performance has been verified and evaluated.
      </div>
      <div class="sig-line"><?=$dean ? e(fullName($dean)) : str_repeat('&nbsp;',24)?></div>
      <div class="sig-sub"><?=$dean ? e($dean['position']??'Program Chair / Dean') : 'Rater / Immediate Supervisor'?></div>
      <div class="sig-sub" style="margin-top:2px">Date: __________________</div>
    </td>
    <!-- Col 2: Faculty / Ratee -->
    <td style="width:33.33%;padding:2px 4px 4px;vertical-align:bottom">
      <div style="font-size:5.8pt;margin-bottom:10px">
        I hereby certify that the above information is true and correct.
      </div>
      <div class="sig-line"><?=e(fullName($user))?></div>
      <div class="sig-sub"><?=e($user['position']??'FACULTY')?></div>
      <div class="sig-sub" style="margin-top:2px">Date: __________________</div>
    </td>
    <!-- Col 3: Reviewed and approved -->
    <td style="width:33.33%;padding:2px 4px 4px;vertical-align:bottom">
      <div style="font-size:5.8pt;margin-bottom:10px">Reviewed and approved by:</div>
      <div class="sig-line"><?=$approv ? e(fullName($approv)) : ($dean ? e(fullName($dean)) : str_repeat('&nbsp;',24))?></div>
      <div class="sig-sub"><?=$approv ? e($approv['position']??'Dean') : ($dean ? e($dean['position']??'Dean') : 'Dean / HRMO')?></div>
      <div class="sig-sub" style="margin-top:2px">Date: __________________</div>
    </td>
  </tr>
</table>

</div><!-- /page -->
</body>
</html>

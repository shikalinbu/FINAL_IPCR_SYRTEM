<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('dean'); // Dean ONLY — admin approves, dean rates

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$data        = json_decode(file_get_contents('php://input'), true);
$formId      = (int)($data['form_id']     ?? 0);
$entries     = $data['entries']           ?? [];
$deanRemarks = trim($data['dean_remarks'] ?? '');

if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$stmt->execute([$formId]);
$form = $stmt->fetch();
if (!$form) { echo json_encode(['success'=>false,'message'=>'Form not found']); exit; }

// Auth check
if ($currentRole === 'dean') {
    $fac = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
    $fac->execute([$form['user_id']]);
    $fac = $fac->fetch();
    if (!$fac || $fac['assigned_dean_id'] != $currentUserId) {
        echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
    }
}

// Q/E/T weights
$qW     = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn() ?: 40);
$eW     = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn() ?: 40);
$tW     = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn() ?: 20);
$totalW = $qW + $eW + $tW;

// ── STEP 1: Save all submitted ratings ──────────────────────
$upsert = $db->prepare("
    INSERT INTO ipcr_si_entries
        (form_id, si_id, q_rating, e_rating, t_rating, average_rating, dean_remarks, dean_verify_status, is_na)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        q_rating=VALUES(q_rating),
        e_rating=VALUES(e_rating),
        t_rating=VALUES(t_rating),
        average_rating=VALUES(average_rating),
        dean_remarks=VALUES(dean_remarks),
        dean_verify_status=VALUES(dean_verify_status),
        is_na=VALUES(is_na)
");

foreach ($entries as $siId => $e) {
    $q   = ($e['q']   !== '' && $e['q']   !== null) ? (float)$e['q']   : null;
    $ev  = ($e['e']   !== '' && $e['e']   !== null) ? (float)$e['e']   : null;
    $t   = ($e['t']   !== '' && $e['t']   !== null) ? (float)$e['t']   : null;
    $isNa = (int)($e['is_na'] ?? 0);
    $avg = ($q !== null && $ev !== null && $t !== null && $totalW > 0)
        ? round(($q * $qW + $ev * $eW + $t * $tW) / $totalW, 4)
        : null;
    $upsert->execute([
        $formId, (int)$siId,
        $q, $ev, $t, $avg,
        $e['remarks']       ?? '',
        $e['verify_status'] ?? 'pending',
        $isNa
    ]);
}

// ── STEP 2: Validate — check at least 1 SI is rated ────────
$ratedCount = $db->prepare("
    SELECT COUNT(*) FROM ipcr_si_entries
    WHERE form_id = ?
      AND is_na = 0
      AND q_rating IS NOT NULL
      AND e_rating IS NOT NULL
      AND t_rating IS NOT NULL
");
$ratedCount->execute([$formId]);
$ratedCount = (int)$ratedCount->fetchColumn();

if ($ratedCount === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Please rate at least one indicator before submitting.'
    ]);
    exit;
}

// ── STEP 2B: Validate — check ALL REQUIRED SIs are rated ───
$requiredSIs = $db->prepare("
    SELECT COUNT(*) FROM success_indicators s
    JOIN kras k ON s.kra_id = k.id
    JOIN mfos m ON k.mfo_id = m.id
    WHERE m.template_id = ? AND s.is_required = 1
");
$requiredSIs->execute([$form['template_id']]);
$totalRequired = (int)$requiredSIs->fetchColumn();

$ratedRequired = $db->prepare("
    SELECT COUNT(*) FROM ipcr_si_entries e
    JOIN success_indicators s ON e.si_id = s.id
    JOIN kras k ON s.kra_id = k.id
    JOIN mfos m ON k.mfo_id = m.id
    WHERE e.form_id = ?
      AND m.template_id = ?
      AND s.is_required = 1
      AND e.is_na = 0
      AND e.q_rating IS NOT NULL
      AND e.e_rating IS NOT NULL
      AND e.t_rating IS NOT NULL
");
$ratedRequired->execute([$formId, $form['template_id']]);
$ratedRequired = (int)$ratedRequired->fetchColumn();

if ($ratedRequired < $totalRequired) {
    $missing = $totalRequired - $ratedRequired;
    echo json_encode([
        'success' => false,
        'message' => "Cannot submit evaluation: {$missing} required indicator(s) not yet rated. Please rate all required indicators before submitting."
    ]);
    exit;
}

// ── STEP 3: Compute composite rating ────────────────────────
// Formula: For each MFO → compute weighted SI scores → MFO avg → composite
$mfoData = $db->prepare("
    SELECT m.id, m.weight,
           s.id si_id, s.si_weight, s.is_required,
           e.average_rating, e.is_na
    FROM mfos m
    JOIN kras k ON k.mfo_id = m.id
    JOIN success_indicators s ON s.kra_id = k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id = s.id AND e.form_id = ?
    WHERE m.template_id = ?
    ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$mfoData->execute([$formId, $form['template_id']]);
$allSIRows = $mfoData->fetchAll();

// Group by MFO
$mfoMap = [];
foreach ($allSIRows as $row) {
    $mid = $row['id'];
    if (!isset($mfoMap[$mid])) {
        $mfoMap[$mid] = ['weight' => (float)$row['weight'], 'sis' => []];
    }
    $mfoMap[$mid]['sis'][] = $row;
}

$compositeTotal = 0.0;

foreach ($mfoMap as $mfoId => $mfo) {
    // Compute weighted sum of rated SIs in this MFO
    $siWeightedSum  = 0.0;
    $totalSIWeight  = 0.0; // Total weight of ALL SIs in this MFO
    $ratedSIWeight  = 0.0; // Weight of SIs that were actually rated

    foreach ($mfo['sis'] as $si) {
        $siWt = floatval($si['si_weight']);
        
        // Count total weight of all required SIs
        if ($si['is_required']) {
            $totalSIWeight += $siWt;
        }
        
        // Skip N/A and unrated
        if ($si['is_na'] || $si['average_rating'] === null) continue;
        
        // Add to weighted sum if rated
        if ($siWt > 0) {
            $siWeightedSum += floatval($si['average_rating']) * ($siWt / 100);
            $ratedSIWeight += $siWt;
        }
    }

    // If no SIs were rated for this MFO, skip it
    if ($ratedSIWeight <= 0) continue;

    // IMPORTANT: Do NOT normalize if not all required SIs are rated
    // Use the actual rated weight as percentage of total possible weight
    // This means partial completion = partial score (not inflated)
    $mfoScore = $siWeightedSum / ($totalSIWeight / 100);

    // Add to composite weighted by MFO weight
    $compositeTotal += $mfoScore * ($mfo['weight'] / 100);
}

$compositeRating = round($compositeTotal, 2);

// ── STEP 4: Get adjectival rating ───────────────────────────
$scaleJson  = $db->query("SELECT config_value FROM system_config WHERE config_key='rating_scale'")->fetchColumn();
$scale      = $scaleJson ? json_decode($scaleJson, true) : [];
usort($scale, fn($a,$b) => $b['min_score'] <=> $a['min_score']);

$adjectival = 'Poor';
foreach ($scale as $level) {
    if ($compositeRating >= floatval($level['min_score'])) {
        $adjectival = $level['label'];
        break;
    }
}

// ── STEP 5: Save final evaluation ───────────────────────────
$db->prepare("
    UPDATE ipcr_forms
    SET status='reviewed',
        composite_rating=?,
        adjectival_rating=?,
        reviewed_at=NOW(),
        reviewed_by=?,
        dean_remarks=?,
        updated_at=NOW()
    WHERE id=?
")->execute([$compositeRating, $adjectival, $currentUserId, $deanRemarks, $formId]);

$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([$formId, 'reviewed', $currentUserId,
       "Evaluation submitted. Composite: {$compositeRating} — {$adjectival}"]);

auditLog('ipcr_evaluated', 'ipcr_form', $formId, 'under_review', 'reviewed',
    "Composite: {$compositeRating} ({$adjectival})");

echo json_encode([
    'success'          => true,
    'composite_rating' => $compositeRating,
    'adjectival_rating'=> $adjectival,
]);

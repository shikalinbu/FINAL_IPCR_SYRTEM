<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$formId = (int)($data['form_id'] ?? 0);
if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form || $form['user_id'] != $currentUserId) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

if (!in_array($form['status'], ['draft','returned'])) {
    echo json_encode(['success'=>false,'message'=>'Cannot submit: status is '.$form['status']]); exit;
}

// ========== AI EVIDENCE VALIDATION ==========
// Check for invalid AI-verified evidence
$invalidEvidence = $db->prepare("
    SELECT ef.*, si.code as si_code, si.title as si_title
    FROM evidence_files ef
    JOIN success_indicators si ON ef.si_id = si.id
    WHERE ef.form_id = ? 
    AND ef.description LIKE '%[AI:%'
    AND ef.description LIKE '%\"verdict\":\"invalid\"%'
");
$invalidEvidence->execute([$formId]);
$invalidFiles = $invalidEvidence->fetchAll();

if (count($invalidFiles) > 0) {
    $fileList = array_map(function($f) {
        return "• {$f['si_code']}: {$f['original_name']}";
    }, $invalidFiles);
    
    echo json_encode([
        'success' => false,
        'message' => "Cannot submit IPCR: " . count($invalidFiles) . " file(s) marked as INVALID by AI verification.\n\nPlease remove or replace these files:\n" . implode("\n", $fileList) . "\n\nThe AI determined these files are not relevant to their respective Success Indicators.",
        'invalid_files' => $invalidFiles
    ]);
    exit;
}

// Warn about uncertain evidence (but allow submission)
$uncertainEvidence = $db->prepare("
    SELECT COUNT(*) as count
    FROM evidence_files ef
    WHERE ef.form_id = ? 
    AND ef.description LIKE '%[AI:%'
    AND ef.description LIKE '%\"verdict\":\"uncertain\"%'
");
$uncertainEvidence->execute([$formId]);
$uncertainCount = $uncertainEvidence->fetchColumn();

$period = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
$period->execute([$form['period_id']]);
$period = $period->fetch();

// Check if period is closed AND user doesn't have an extension
if ($period && $period['status'] === 'closed') {
    // Check for active extension
    $extStmt = $db->prepare("
        SELECT extended_deadline FROM submission_extensions 
        WHERE period_id = ? AND user_id = ? AND is_used = 0
        AND extended_deadline >= NOW()
        ORDER BY extended_deadline DESC LIMIT 1
    ");
    $extStmt->execute([$form['period_id'], $currentUserId]);
    $extension = $extStmt->fetch();
    
    if (!$extension) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot submit: This rating period is closed. Contact your administrator if you need an extension.'
        ]);
        exit;
    }
}

$isLate = false;
$hasExtension = false;
$extensionDeadline = null;

// Check for extension first
$extCheck = $db->prepare("
    SELECT id, extended_deadline FROM submission_extensions 
    WHERE period_id = ? AND user_id = ? AND is_used = 0
    ORDER BY extended_deadline DESC LIMIT 1
");
$extCheck->execute([$form['period_id'], $currentUserId]);
$activeExtension = $extCheck->fetch();

if ($activeExtension) {
    $hasExtension = true;
    $extensionDeadline = $activeExtension['extended_deadline'];
    
    // Check if late against extension deadline
    if (strtotime($extensionDeadline) < time()) {
        $isLate = true;
    }
} else if ($period && $period['submission_deadline']) {
    // Check against period deadline
    $isLate = strtotime($period['submission_deadline'].' 23:59:59') < time();
}

$db->prepare("UPDATE ipcr_forms SET status='submitted', submitted_at=NOW(), is_late=?, has_extension=?, extension_used_at=?, updated_at=NOW() WHERE id=?")
   ->execute([$isLate ? 1 : 0, $hasExtension ? 1 : 0, $hasExtension ? date('Y-m-d H:i:s') : null, $formId]);

// Mark extension as used
if ($activeExtension) {
    $db->prepare("UPDATE submission_extensions SET is_used = 1, used_at = NOW() WHERE id = ?")
       ->execute([$activeExtension['id']]);
}

$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([
       $formId, 
       'submitted', 
       $currentUserId, 
       $hasExtension 
           ? 'IPCR submitted using extension (deadline: ' . date('M d, Y', strtotime($extensionDeadline)) . ')' 
           : ($isLate ? 'IPCR submitted (LATE)' : 'IPCR submitted for evaluation.')
   ]);

auditLog(
    'ipcr_submitted', 
    'ipcr_form', 
    $formId, 
    'draft', 
    'submitted', 
    $hasExtension 
        ? 'Extension used - deadline: ' . date('M d, Y', strtotime($extensionDeadline)) 
        : ($isLate ? 'Late submission' : 'Submitted')
);

echo json_encode([
    'success' => true, 
    'is_late' => $isLate,
    'has_extension' => $hasExtension,
    'uncertain_count' => $uncertainCount ?? 0,
    'warning' => $uncertainCount > 0 ? "Note: {$uncertainCount} file(s) have uncertain AI verification. Evaluator may question these." : null
]);

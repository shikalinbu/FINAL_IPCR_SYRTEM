<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('dean'); // Dean ONLY

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');
$data    = json_decode(file_get_contents('php://input'), true);
$formId  = (int)($data['form_id'] ?? 0);
$entries = $data['entries'] ?? [];

if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$form = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$form->execute([$formId]);
$form = $form->fetch();
if (!$form) { echo json_encode(['success'=>false,'message'=>'Form not found']); exit; }

// Auth check for dean
if ($currentRole === 'dean') {
    $fac = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
    $fac->execute([$form['user_id']]);
    $fac = $fac->fetch();
    if (!$fac || $fac['assigned_dean_id'] != $currentUserId) {
        echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
    }
}

$qW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn() ?: 40);
$eW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn() ?: 40);
$tW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn() ?: 20);
$totalW = $qW + $eW + $tW;

$stmt = $db->prepare("
    INSERT INTO ipcr_si_entries (form_id, si_id, q_rating, e_rating, t_rating, average_rating, dean_remarks, dean_verify_status, is_na)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        q_rating=VALUES(q_rating), e_rating=VALUES(e_rating), t_rating=VALUES(t_rating),
        average_rating=VALUES(average_rating), dean_remarks=VALUES(dean_remarks),
        dean_verify_status=VALUES(dean_verify_status), is_na=VALUES(is_na)
");

foreach ($entries as $siId => $e) {
    $q = $e['q'] !== '' ? (float)$e['q'] : null;
    $ev = $e['e'] !== '' ? (float)$e['e'] : null;
    $t = $e['t'] !== '' ? (float)$e['t'] : null;
    $avg = ($q !== null && $ev !== null && $t !== null && $totalW > 0)
        ? round(($q * $qW + $ev * $eW + $t * $tW) / $totalW, 4)
        : null;
    $stmt->execute([
        $formId, (int)$siId,
        $q, $ev, $t, $avg,
        $e['remarks'] ?? '',
        $e['verify_status'] ?? 'pending',
        (int)($e['is_na'] ?? 0),
    ]);
    auditLog('rating_changed', 'ipcr_form', $formId, null, "SI {$siId}: Q={$q} E={$ev} T={$t}", 'Dean saved ratings');
}

$db->prepare("UPDATE ipcr_forms SET updated_at=NOW() WHERE id=?")->execute([$formId]);
echo json_encode(['success'=>true]);

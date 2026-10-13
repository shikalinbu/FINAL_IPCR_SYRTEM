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
$entries = $data['entries'] ?? [];

if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form || ($form['user_id'] != $currentUserId && !in_array($currentRole, ['admin']))) {
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}

if (!in_array($form['status'], ['draft','returned'])) {
    echo json_encode(['success'=>false,'message'=>'Form is not editable.']); exit;
}

$upsert = $db->prepare("
    INSERT INTO ipcr_si_entries (form_id, si_id, planned_magnitude, planned_budget, planned_date, actual_magnitude, actual_budget, actual_date, actual_output)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        planned_magnitude=VALUES(planned_magnitude),
        planned_budget=VALUES(planned_budget),
        planned_date=VALUES(planned_date),
        actual_magnitude=VALUES(actual_magnitude),
        actual_budget=VALUES(actual_budget),
        actual_date=VALUES(actual_date),
        actual_output=VALUES(actual_output)
");

foreach ($entries as $siId => $e) {
    $siId = (int)$siId;
    if (!$siId) continue;
    $upsert->execute([
        $formId, $siId,
        $e['planned_magnitude'] ?? '',
        $e['planned_budget']    ?? '',
        $e['planned_date']      ?? '',
        $e['actual_magnitude']  ?? '',
        $e['actual_budget']     ?? '',
        $e['actual_date']       ?? '',
        $e['actual_output']     ?? '',
    ]);
}

$db->prepare("UPDATE ipcr_forms SET updated_at=NOW() WHERE id=?")->execute([$formId]);

echo json_encode(['success'=>true,'saved_at'=>date('Y-m-d H:i:s')]);

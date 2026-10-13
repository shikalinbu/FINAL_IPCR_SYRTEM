<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('admin');

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');
$data   = json_decode(file_get_contents('php://input'), true);
$formId = (int)($data['form_id'] ?? 0);
if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$form = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$form->execute([$formId]);
$form = $form->fetch();
if (!$form || $form['status'] !== 'approved') {
    echo json_encode(['success'=>false,'message'=>'Only approved forms can be finalized']); exit;
}

$db->prepare("UPDATE ipcr_forms SET status='finalized', finalized_at=NOW(), updated_at=NOW() WHERE id=?")
   ->execute([$formId]);
$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([$formId, 'finalized', $currentUserId, 'IPCR finalized and locked by Admin.']);
auditLog('status_change','ipcr_form',$formId,'approved','finalized','IPCR finalized');

echo json_encode(['success'=>true]);

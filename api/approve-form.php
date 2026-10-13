<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('admin');

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');
$data    = json_decode(file_get_contents('php://input'), true);
$formId  = (int)($data['form_id']       ?? 0);
$remarks = trim($data['admin_remarks']  ?? '');

if (!$formId) { echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit; }

$db   = getDB();
$form = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$form->execute([$formId]);
$form = $form->fetch();
if (!$form || $form['status'] !== 'reviewed') {
    echo json_encode(['success'=>false,'message'=>'Only reviewed forms can be approved']); exit;
}

$db->prepare("UPDATE ipcr_forms SET status='approved', admin_remarks=?, approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
   ->execute([$remarks, $currentUserId, $formId]);
$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([$formId, 'approved', $currentUserId, $remarks ?: 'Approved by Admin']);
auditLog('ipcr_approved','ipcr_form',$formId,'reviewed','approved','Admin approved');

echo json_encode(['success'=>true]);

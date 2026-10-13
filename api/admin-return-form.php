<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('admin','secretary'); // Both admin and secretary can return forms

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$data    = json_decode(file_get_contents('php://input'), true);
$formId  = (int)($data['form_id'] ?? 0);
$remarks = trim($data['remarks']  ?? '');

if (!$formId || !$remarks) {
    echo json_encode(['success'=>false,'message'=>'Form ID and remarks are required.']); exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) { echo json_encode(['success'=>false,'message'=>'Form not found']); exit; }

$db->prepare("UPDATE ipcr_forms SET status='returned', return_remarks=?, updated_at=NOW() WHERE id=?")
   ->execute([$remarks, $formId]);

$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([$formId, 'returned', $currentUserId, 'Returned by Admin: '.$remarks]);

auditLog('ipcr_returned','ipcr_form',$formId,$form['status'],'returned','Admin returned: '.$remarks);

echo json_encode(['success'=>true]);

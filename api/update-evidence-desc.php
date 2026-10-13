<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$fileId = (int)($data['file_id']     ?? 0);
$desc   = trim($data['description']  ?? '');

if (!$fileId) { echo json_encode(['success'=>false,'message'=>'Missing file_id']); exit; }

$db = getDB();
$ev = $db->prepare("SELECT ef.*, f.user_id FROM evidence_files ef JOIN ipcr_forms f ON ef.form_id=f.id WHERE ef.id=?");
$ev->execute([$fileId]);
$ev = $ev->fetch();

if (!$ev) { echo json_encode(['success'=>false,'message'=>'File not found']); exit; }

if ($ev['user_id'] != $currentUserId && !in_array($currentRole, ['admin'])) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

$db->prepare("UPDATE evidence_files SET description=? WHERE id=?")->execute([$desc, $fileId]);

echo json_encode(['success'=>true]);

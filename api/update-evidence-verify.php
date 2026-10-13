<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole('dean','admin');

header('Content-Type: application/json');
$data   = json_decode(file_get_contents('php://input'), true);
$fileId = (int)($data['file_id'] ?? 0);
$status = $data['status'] ?? '';
$allowed = ['pending','verified','needs_revision','insufficient','rejected'];
if (!$fileId || !in_array($status, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
}
$db = getDB();
$db->prepare("UPDATE evidence_files SET verify_status=? WHERE id=?")->execute([$status, $fileId]);
echo json_encode(['success'=>true]);

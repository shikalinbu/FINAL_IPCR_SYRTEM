<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

$fileId = (int)($_GET['id'] ?? 0);
if (!$fileId) { http_response_code(400); exit('Missing file id'); }

$db = getDB();
$q  = $db->prepare("
    SELECT ef.*, f.user_id, f.reviewed_by
    FROM evidence_files ef
    JOIN ipcr_forms f ON ef.form_id = f.id
    WHERE ef.id = ?
");
$q->execute([$fileId]);
$ev = $q->fetch();

if (!$ev) { http_response_code(404); exit('File not found'); }

// Access control
$canAccess = ($ev['user_id'] == $currentUserId)
    || ($currentRole === 'admin')
    || ($currentRole === 'dean');

if (!$canAccess && $currentRole === 'dean') {
    $fac = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
    $fac->execute([$ev['user_id']]);
    $fac = $fac->fetch();
    $canAccess = ($fac && $fac['assigned_dean_id'] == $currentUserId);
}

if (!$canAccess) { http_response_code(403); exit('Access denied'); }

$path = UPLOAD_DIR . $ev['form_id'] . DIRECTORY_SEPARATOR . $ev['si_id'] . DIRECTORY_SEPARATOR . $ev['stored_name'];

if (!file_exists($path)) { http_response_code(404); exit('File not found on server'); }

$mime = $ev['file_type'] ?: mime_content_type($path) ?: 'application/octet-stream';
$name = $ev['original_name'];

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($name) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;

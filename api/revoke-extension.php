<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('admin', 'dean');

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$extensionId = (int)($data['extension_id'] ?? 0);

if (!$extensionId) {
    echo json_encode(['success' => false, 'message' => 'Extension ID is required.']);
    exit;
}

$db = getDB();

// Delete the extension
$stmt = $db->prepare("DELETE FROM submission_extensions WHERE id = ? AND is_used = 0");
$stmt->execute([$extensionId]);

if ($stmt->rowCount() > 0) {
    auditLog('extension_revoked', 'submission_extension', $extensionId, null, null, 'Extension revoked by admin');
    echo json_encode(['success' => true, 'message' => 'Extension revoked successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Extension not found or already used.']);
}

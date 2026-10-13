<?php
// API endpoint for secretary to submit edit requests
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

ob_start();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Manual auth check for API
    if (!isLoggedIn()) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $currentRole = $_SESSION['role'] ?? '';
    $currentUserId = $_SESSION['user_id'] ?? 0;

    if (!in_array($currentRole, ['secretary', 'dean'])) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $db = getDB();
    $input = json_decode(file_get_contents('php://input'), true);

    $requestType = $input['request_type'] ?? '';
    $targetId = (int)($input['target_id'] ?? 0);
    $requestData = $input['request_data'] ?? [];
    $remarks = trim($input['remarks'] ?? '');

    if (!in_array($requestType, ['user', 'ipcr', 'department', 'template', 'period'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request type']);
        exit;
    }

    if (!$targetId && $requestType !== 'department') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Target ID required']);
        exit;
    }

    // Get the dean ID based on role
    if ($currentRole === 'secretary') {
        // Secretary submits to their assigned dean
        $secretary = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
        $secretary->execute([$currentUserId]);
        $deanId = $secretary->fetchColumn();
        
        if (!$deanId) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'No assigned dean found. Contact administrator.']);
            exit;
        }
    } else {
        // Dean submitting for themselves (edge case - usually dean edits directly)
        // But this allows dean to use the request system if needed
        $deanId = $currentUserId;
    }

    // Get original data for comparison
    $originalData = null;
    switch ($requestType) {
        case 'user':
            $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$targetId]);
            $originalData = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'ipcr':
            $stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
            $stmt->execute([$targetId]);
            $originalData = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'department':
            if ($targetId > 0) {
                $stmt = $db->prepare("SELECT * FROM departments WHERE id=?");
                $stmt->execute([$targetId]);
                $originalData = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            break;
        case 'period':
            $stmt = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
            $stmt->execute([$targetId]);
            $originalData = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'template':
            $stmt = $db->prepare("SELECT * FROM ipcr_templates WHERE id=?");
            $stmt->execute([$targetId]);
            $originalData = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
    }

    $db->beginTransaction();
    
    // Insert edit request
    $stmt = $db->prepare("
        INSERT INTO edit_requests 
        (request_type, target_id, requested_by, dean_id, request_data, original_data, remarks, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $requestType,
        $targetId,
        $currentUserId,
        $deanId,
        json_encode($requestData),
        json_encode($originalData),
        $remarks
    ]);
    
    $requestId = $db->lastInsertId();
    
    // Update dean's pending approval count
    $db->prepare("UPDATE users SET pending_approvals = pending_approvals + 1 WHERE id=?")
       ->execute([$deanId]);
    
    // Audit log
    auditLog(
        'edit_request_submitted',
        $requestType,
        $targetId,
        $currentUserId,
        $deanId,
        "Edit request #$requestId submitted"
    );
    
    $db->commit();
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Edit request submitted for dean approval',
        'request_id' => $requestId
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_end_clean();
    error_log("Submit edit request error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
}

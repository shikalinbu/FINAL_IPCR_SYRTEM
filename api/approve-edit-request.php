<?php
// API endpoint for dean to approve/reject edit requests
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

    if (!in_array($currentRole, ['dean', 'admin'])) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $db = getDB();
    $input = json_decode(file_get_contents('php://input'), true);

    $requestId = (int)($input['request_id'] ?? 0);
    $action = $input['action'] ?? ''; // 'approve' or 'reject'
    $deanRemarks = trim($input['dean_remarks'] ?? '');

    if (!$requestId || !in_array($action, ['approve', 'reject'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    // Get request details
    $request = $db->prepare("SELECT * FROM edit_requests WHERE id=?");
    $request->execute([$requestId]);
    $request = $request->fetch();

    if (!$request) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }

    // Verify dean owns this request
    if ($currentRole === 'dean' && $request['dean_id'] != $currentUserId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($request['status'] !== 'pending') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Request already processed']);
        exit;
    }

    $db->beginTransaction();
    
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    
    // Update request status
    $stmt = $db->prepare("
        UPDATE edit_requests 
        SET status=?, dean_remarks=?, reviewed_at=NOW(), reviewed_by=?
        WHERE id=?
    ");
    $stmt->execute([$newStatus, $deanRemarks, $currentUserId, $requestId]);
    
    // If approved, apply the changes
    if ($action === 'approve') {
        $requestData = json_decode($request['request_data'], true);
        
        switch ($request['request_type']) {
            case 'user':
                // Build UPDATE query dynamically
                $fields = [];
                $values = [];
                foreach ($requestData as $key => $value) {
                    if (in_array($key, ['first_name', 'last_name', 'email', 'employee_id', 'department', 'position', 'is_active'])) {
                        $fields[] = "$key=?";
                        $values[] = $value;
                    }
                }
                if ($fields) {
                    $values[] = $request['target_id'];
                    $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id=?";
                    $db->prepare($sql)->execute($values);
                    
                    auditLog('user_updated', 'user', $request['target_id'], 
                            $request['requested_by'], $currentUserId, 
                            "Dean approved secretary edit request #$requestId");
                }
                break;
                
            case 'ipcr':
                // Update IPCR form data
                $fields = [];
                $values = [];
                foreach ($requestData as $key => $value) {
                    if (in_array($key, ['status', 'dean_remarks', 'composite_rating', 'adjectival_rating'])) {
                        $fields[] = "$key=?";
                        $values[] = $value;
                    }
                }
                if ($fields) {
                    $values[] = $request['target_id'];
                    $sql = "UPDATE ipcr_forms SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id=?";
                    $db->prepare($sql)->execute($values);
                    
                    auditLog('ipcr_updated', 'ipcr_form', $request['target_id'],
                            $request['requested_by'], $currentUserId,
                            "Dean approved secretary edit request #$requestId");
                }
                break;
                
            case 'department':
                if ($request['target_id'] > 0) {
                    // Update existing department
                    $db->prepare("UPDATE departments SET name=?, code=?, description=? WHERE id=?")
                       ->execute([
                           $requestData['name'] ?? '',
                           $requestData['code'] ?? '',
                           $requestData['description'] ?? '',
                           $request['target_id']
                       ]);
                } else {
                    // Create new department
                    $db->prepare("INSERT INTO departments (name, code, description) VALUES (?, ?, ?)")
                       ->execute([
                           $requestData['name'] ?? '',
                           $requestData['code'] ?? '',
                           $requestData['description'] ?? ''
                       ]);
                }
                auditLog('department_updated', 'department', $request['target_id'],
                        $request['requested_by'], $currentUserId,
                        "Dean approved secretary edit request #$requestId");
                break;
                
            case 'period':
                // Update rating period
                $fields = [];
                $values = [];
                $allowedFields = ['name', 'academic_year', 'semester', 'start_date', 'end_date', 
                                 'submission_deadline', 'review_deadline', 'template_id'];
                
                foreach ($requestData as $key => $value) {
                    if (in_array($key, $allowedFields)) {
                        $fields[] = "$key=?";
                        $values[] = $value;
                    }
                }
                
                if ($fields) {
                    $values[] = $request['target_id'];
                    $sql = "UPDATE rating_periods SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id=?";
                    $db->prepare($sql)->execute($values);
                    
                    auditLog('period_updated', 'period', $request['target_id'],
                            $request['requested_by'], $currentUserId,
                            "Dean approved secretary edit request #$requestId for rating period");
                }
                break;
                
            case 'template':
                // Update IPCR template
                $fields = [];
                $values = [];
                $allowedFields = ['name', 'period_id', 'is_default'];
                
                foreach ($requestData as $key => $value) {
                    if (in_array($key, $allowedFields)) {
                        $fields[] = "$key=?";
                        $values[] = $value;
                    }
                }
                
                if ($fields) {
                    $values[] = $request['target_id'];
                    $sql = "UPDATE ipcr_templates SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id=?";
                    $db->prepare($sql)->execute($values);
                    
                    auditLog('template_updated', 'template', $request['target_id'],
                            $request['requested_by'], $currentUserId,
                            "Dean approved secretary edit request #$requestId for IPCR template");
                }
                break;
        }
    }
    
    // Update dean's pending approval count
    $db->prepare("UPDATE users SET pending_approvals = GREATEST(0, pending_approvals - 1) WHERE id=?")
       ->execute([$request['dean_id']]);
    
    // Audit the approval/rejection
    auditLog(
        "edit_request_$newStatus",
        $request['request_type'],
        $request['target_id'],
        $currentUserId,
        $request['requested_by'],
        "Dean $newStatus edit request #$requestId" . ($deanRemarks ? ": $deanRemarks" : "")
    );
    
    $db->commit();
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Request ' . $newStatus,
        'action' => $action
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    ob_end_clean();
    error_log("Approve edit request error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process request: ' . $e->getMessage()]);
}

<?php
// ============================================================================
// Dean Add Department API
// Allows deans to add new departments to the system
// ============================================================================

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = getDB();
    $currentUserId = $_SESSION['user_id'];
    $currentRole = $_SESSION['role'] ?? '';
    
    // Only admin and dean can add departments
    if (!in_array($currentRole, ['admin', 'dean'])) {
        throw new Exception('Only administrators and deans can add departments');
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request data');
    }
    
    $name = trim($input['name'] ?? '');
    $abbreviation = trim($input['abbreviation'] ?? '');
    
    // Validation
    if (empty($name)) {
        throw new Exception('Department name is required');
    }
    
    // Check if department already exists
    $stmt = $db->prepare("SELECT id FROM departments WHERE name = ?");
    $stmt->execute([$name]);
    
    if ($stmt->fetch()) {
        throw new Exception('Department already exists');
    }
    
    // Insert new department
    $stmt = $db->prepare("
        INSERT INTO departments (name, abbreviation, created_at) 
        VALUES (?, ?, NOW())
    ");
    
    $stmt->execute([$name, $abbreviation]);
    $departmentId = $db->lastInsertId();
    
    // Audit log
    auditLog('department_created', 'department', $departmentId, null, $name, 'Department created by ' . $currentRole);
    
    echo json_encode([
        'success' => true,
        'message' => "Department '{$name}' added successfully",
        'department' => [
            'id' => $departmentId,
            'name' => $name,
            'abbreviation' => $abbreviation
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

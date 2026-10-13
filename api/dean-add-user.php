<?php
// API for dean to directly add users (faculty)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireRole('dean', 'admin');

$db = getDB();
$currentUserId = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$firstName = trim($input['first_name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$employeeId = trim($input['employee_id'] ?? '');
$email = trim($input['email'] ?? '');
$department = trim($input['department'] ?? '');
$position = trim($input['position'] ?? '');
$password = $input['password'] ?? 'password123';
$role = $input['role'] ?? 'faculty';
$assignedDeanId = (int)($input['assigned_dean_id'] ?? $currentUserId);

if (!$firstName || !$lastName || !$employeeId || !$email) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Check if employee ID or email already exists
$check = $db->prepare("SELECT id FROM users WHERE employee_id=? OR email=?");
$check->execute([$employeeId, $email]);
if ($check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Employee ID or email already exists']);
    exit;
}

try {
    $db->beginTransaction();
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("
        INSERT INTO users (
            employee_id, email, password_hash, first_name, last_name, 
            department, position, role, assigned_dean_id, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $employeeId,
        $email,
        $hashedPassword,
        $firstName,
        $lastName,
        $department,
        $position,
        $role,
        $assignedDeanId
    ]);
    
    $userId = $db->lastInsertId();
    
    auditLog('user_created', 'user', $userId, null, null, 
             "Dean created new $role: $firstName $lastName ($employeeId)", $currentUserId);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => ucfirst($role) . ' added successfully',
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Dean add user error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to add user: ' . $e->getMessage()
    ]);
}

<?php
// API for dean to directly update users (faculty)

// Start output buffering to prevent any HTML/PHP errors from breaking JSON
ob_start();

// Disable error display, log errors instead
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Clean any output that may have been generated
ob_end_clean();

// Now set JSON header
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check authentication and get current user info
try {
    requireRole('dean', 'admin');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Get current user from session
$currentUserId = $_SESSION['user_id'] ?? null;
$currentRole = $_SESSION['role'] ?? null;

if (!$currentUserId || !$currentRole) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

$db = getDB();
$input = json_decode(file_get_contents('php://input'), true);

$userId = (int)($input['id'] ?? 0);
$firstName = trim($input['first_name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$employeeId = trim($input['employee_id'] ?? '');
$email = trim($input['email'] ?? '');
$department = trim($input['department'] ?? '');
$position = trim($input['position'] ?? '');
$isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

if (!$userId || !$firstName || !$lastName || !$employeeId || !$email) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Check if user exists and belongs to this dean
$user = $db->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$userId]);
$user = $user->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// If dean role, verify they own this faculty member
if ($currentRole === 'dean' && $user['assigned_dean_id'] != $currentUserId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized to edit this user']);
    exit;
}

// Check if employee ID or email already exists for another user
$check = $db->prepare("SELECT id FROM users WHERE (employee_id=? OR email=?) AND id!=?");
$check->execute([$employeeId, $email, $userId]);
if ($check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Employee ID or email already exists']);
    exit;
}

try {
    $db->beginTransaction();
    
    $stmt = $db->prepare("
        UPDATE users 
        SET first_name=?, last_name=?, employee_id=?, email=?, 
            department=?, position=?, is_active=?, updated_at=NOW()
        WHERE id=?
    ");
    
    $result = $stmt->execute([
        $firstName,
        $lastName,
        $employeeId,
        $email,
        $department,
        $position,
        $isActive,
        $userId
    ]);
    
    if (!$result) {
        throw new Exception('Failed to execute update query');
    }
    
    // Check if any rows were affected
    if ($stmt->rowCount() === 0) {
        // No rows updated - but not necessarily an error (data might be the same)
        error_log("Dean update: No rows affected for user ID $userId");
    }
    
    auditLog('user_updated', 'user', $userId, $currentUserId, null,
             "Dean updated user: $firstName $lastName ($employeeId)");
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully'
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    $errorMsg = $e->getMessage();
    error_log("Dean update user error for user ID $userId: " . $errorMsg);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update user: ' . $errorMsg,
        'debug' => [
            'user_id' => $userId,
            'error' => $errorMsg
        ]
    ]);
}

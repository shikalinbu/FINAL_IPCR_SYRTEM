<?php
// API to get user details
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Start output buffering to catch any stray output
ob_start();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Manual role check for API (don't use requireRole which redirects)
    if (!isLoggedIn()) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $currentRole = $_SESSION['role'] ?? '';
    $currentUserId = $_SESSION['user_id'] ?? 0;

    if (!in_array($currentRole, ['dean', 'admin', 'secretary'])) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $db = getDB();
    $userId = (int)($_GET['id'] ?? 0);

    if (!$userId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Remove sensitive data
    unset($user['password_hash']);
    unset($user['password']); // Just in case old field exists

    // Authorization check for dean and secretary
    if ($currentRole === 'dean' || $currentRole === 'secretary') {
        // Get the dean ID we need to check against
        if ($currentRole === 'dean') {
            // Dean checking their own faculty
            $allowedDeanId = $currentUserId;
        } else {
            // Secretary checking their assigned dean's faculty
            $currentUserData = getCurrentUser();
            $allowedDeanId = $currentUserData['assigned_dean_id'] ?? null;
            
            if (!$allowedDeanId) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'You are not assigned to any dean']);
                exit;
            }
        }
        
        // Check if the user being accessed is either:
        // 1. Faculty assigned to the allowed dean
        // 2. The current user themselves (dean/secretary viewing own profile)
        if ($user['assigned_dean_id'] != $allowedDeanId && $user['id'] != $currentUserId) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Unauthorized - You can only access users assigned to your dean']);
            exit;
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("GET USER API ERROR: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Server error occurred'
    ]);
}

<?php
/**
 * Get list of faculty who have NOT submitted their IPCR for given periods
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

// Check authentication
try {
    requireRole('admin', 'secretary', 'dean');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$periodIds = $data['period_ids'] ?? [];

if (empty($periodIds) || !is_array($periodIds)) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid period IDs']);
    exit;
}

// Sanitize period IDs
$periodIds = array_map('intval', $periodIds);
$periodIdsStr = implode(',', $periodIds);

$db = getDB();

// For deans: restrict to their faculty
$facultyFilter = '';
if ($currentRole === 'dean') {
    $fac = $db->prepare("SELECT id FROM users WHERE assigned_dean_id=? AND is_active=1");
    $fac->execute([$currentUserId]);
    $facIds = array_column($fac->fetchAll(), 'id');
    if ($facIds) {
        $facultyFilter = " AND u.id IN (".implode(',',array_map('intval',$facIds)).")";
    } else {
        $facultyFilter = " AND 1=0";
    }
}

// Get faculty who have submitted
$submittedUserIds = $db->query("
    SELECT DISTINCT user_id FROM ipcr_forms 
    WHERE period_id IN ({$periodIdsStr})
    AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
")->fetchAll(PDO::FETCH_COLUMN);

// Build WHERE clause for NOT submitted
$whereNotSubmitted = "u.role='faculty' AND u.is_active=1 {$facultyFilter}";
if (!empty($submittedUserIds)) {
    $idList = implode(',', array_map('intval', $submittedUserIds));
    $whereNotSubmitted .= " AND u.id NOT IN ({$idList})";
}

// Get faculty who have NOT submitted
$notSubmittedFaculty = $db->query("
    SELECT 
        u.id as user_id,
        u.email,
        CONCAT(u.first_name, ' ', u.last_name) as name,
        u.employee_id,
        u.department
    FROM users u
    WHERE {$whereNotSubmitted}
    ORDER BY u.last_name, u.first_name
")->fetchAll();

echo json_encode([
    'success' => true,
    'faculty' => $notSubmittedFaculty,
    'count' => count($notSubmittedFaculty)
]);

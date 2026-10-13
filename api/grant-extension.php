<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/email.php';

requireRole('admin', 'dean');

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$periodId = (int)($data['period_id'] ?? 0);
$userIds = $data['user_ids'] ?? []; // Array of user IDs
$extendedDeadline = trim($data['extended_deadline'] ?? '');
$reason = trim($data['reason'] ?? '');

// Validation
if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'Period ID is required.']);
    exit;
}

if (empty($userIds) || !is_array($userIds)) {
    echo json_encode(['success' => false, 'message' => 'At least one faculty member must be selected.']);
    exit;
}

if (!$extendedDeadline) {
    echo json_encode(['success' => false, 'message' => 'Extension deadline is required.']);
    exit;
}

// Validate deadline is in the future
if (strtotime($extendedDeadline) <= time()) {
    echo json_encode(['success' => false, 'message' => 'Extension deadline must be in the future.']);
    exit;
}

$db = getDB();
$currentUserId = $_SESSION['user_id'];

// Get period info
$periodStmt = $db->prepare("SELECT * FROM rating_periods WHERE id = ?");
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Period not found.']);
    exit;
}

// Grant extensions to selected users
$granted = 0;
$failed = [];
$notifications = [];

foreach ($userIds as $userId) {
    $userId = (int)$userId;
    
    // Check if user already has an active extension
    $existingExt = $db->prepare("
        SELECT id FROM submission_extensions 
        WHERE period_id = ? AND user_id = ? AND is_used = 0
    ");
    $existingExt->execute([$periodId, $userId]);
    
    if ($existingExt->fetch()) {
        // Update existing extension
        $updateStmt = $db->prepare("
            UPDATE submission_extensions 
            SET extended_deadline = ?, reason = ?, granted_by = ?, granted_at = NOW()
            WHERE period_id = ? AND user_id = ? AND is_used = 0
        ");
        $updateStmt->execute([$extendedDeadline, $reason, $currentUserId, $periodId, $userId]);
        $granted++;
    } else {
        // Create new extension
        $insertStmt = $db->prepare("
            INSERT INTO submission_extensions 
            (period_id, user_id, extended_deadline, reason, granted_by, granted_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$periodId, $userId, $extendedDeadline, $reason, $currentUserId]);
        $granted++;
    }
    
    // Get faculty info for email notification
    $facultyStmt = $db->prepare("
        SELECT first_name, last_name, email, personal_email, employee_id 
        FROM users WHERE id = ?
    ");
    $facultyStmt->execute([$userId]);
    $faculty = $facultyStmt->fetch();
    
    if ($faculty) {
        $notifications[] = $faculty;
    }
    
    // Audit log
    auditLog(
        'extension_granted',
        'submission_extension',
        $userId,
        null,
        json_encode(['period' => $period['name'], 'deadline' => $extendedDeadline]),
        "Extension granted until " . date('M d, Y', strtotime($extendedDeadline))
    );
}

// Send email notifications
foreach ($notifications as $faculty) {
    $facultyEmail = $faculty['personal_email'] ?: $faculty['email'];
    $facultyName = trim($faculty['first_name'] . ' ' . $faculty['last_name']);
    
    $subject = "IPCR Submission Extension Granted - " . $period['name'];
    
    $emailBody = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #1a5c2a 0%, #2d8f47 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
            <h1 style='color: #ffffff; margin: 0; font-size: 24px;'>✅ Extension Granted</h1>
        </div>
        
        <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;'>
            <p style='font-size: 16px; color: #1e2d22; margin-bottom: 20px;'>
                Dear <strong>{$facultyName}</strong>,
            </p>
            
            <div style='background: #f0faf4; border-left: 4px solid #16a34a; padding: 15px; margin: 20px 0; border-radius: 6px;'>
                <p style='margin: 0; font-size: 15px; color: #1a5c2a;'>
                    <strong>Good news!</strong> You have been granted an extension to submit your IPCR.
                </p>
            </div>
            
            <table style='width: 100%; margin: 20px 0; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb;'><strong>Period:</strong></td>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb;'>{$period['name']}</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb;'><strong>Employee ID:</strong></td>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb;'>{$faculty['employee_id']}</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb;'><strong>New Deadline:</strong></td>
                    <td style='padding: 10px; border-bottom: 1px solid #e5e7eb; color: #16a34a; font-weight: bold; font-size: 16px;'>
                        " . date('F d, Y g:i A', strtotime($extendedDeadline)) . "
                    </td>
                </tr>
                " . ($reason ? "
                <tr>
                    <td style='padding: 10px; vertical-align: top;'><strong>Reason:</strong></td>
                    <td style='padding: 10px; color: #6b7280;'>" . nl2br(htmlspecialchars($reason)) . "</td>
                </tr>
                " : "") . "
            </table>
            
            <div style='background: #fff7ed; border: 1px solid #fb923c; border-radius: 6px; padding: 15px; margin: 20px 0;'>
                <p style='margin: 0; font-size: 14px; color: #9a3412;'>
                    ⚠️ <strong>Important:</strong> This is a special extension. Please submit your IPCR before the new deadline.
                </p>
            </div>
            
            <div style='text-align: center; margin-top: 30px;'>
                <a href='" . BASE_URL . "/dashboard.php' 
                   style='display: inline-block; background: #1a5c2a; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;'>
                    📝 Go to Dashboard
                </a>
            </div>
            
            <p style='margin-top: 30px; font-size: 14px; color: #6b7280; text-align: center;'>
                If you have questions, please contact your Dean or the IPCR Administrator.
            </p>
        </div>
        
        <div style='text-align: center; margin-top: 20px; padding: 20px; color: #9ca3af; font-size: 12px;'>
            <p>This is an automated notification from the IPCR System.</p>
        </div>
    </div>
    ";
    
    sendEmail($facultyEmail, $subject, $emailBody);
}

echo json_encode([
    'success' => true,
    'message' => "Extension granted to {$granted} faculty member(s).",
    'granted_count' => $granted,
    'notifications_sent' => count($notifications)
]);

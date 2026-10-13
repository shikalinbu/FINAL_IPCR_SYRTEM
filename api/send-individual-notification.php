<?php
/**
 * Send email notification to a single faculty member who has NOT submitted their IPCR
 * This endpoint is designed for one-by-one sending to avoid timeouts and crashes
 */

header('Content-Type: application/json');

// Prevent timeout
set_time_limit(30);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/email.php';

// Check authentication
try {
    requireRole('admin', 'secretary', 'dean');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = (int)($data['user_id'] ?? 0);
$periodIds = $data['period_ids'] ?? [];
$periodName = $data['period_name'] ?? '';

if (!$userId || empty($periodIds) || !$periodName) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Sanitize period IDs
$periodIds = array_map('intval', $periodIds);
$periodIdsStr = implode(',', $periodIds);

$db = getDB();

// Get faculty details
$faculty = $db->prepare("
    SELECT id, email, first_name, last_name, employee_id, department
    FROM users
    WHERE id = ? AND role = 'faculty' AND is_active = 1
");
$faculty->execute([$userId]);
$faculty = $faculty->fetch();

if (!$faculty) {
    echo json_encode(['success' => false, 'message' => 'Faculty not found or inactive']);
    exit;
}

if (!$faculty['email']) {
    echo json_encode(['success' => false, 'message' => 'Faculty has no email address']);
    exit;
}

// Check if dean is authorized (if dean role)
if ($currentRole === 'dean') {
    $check = $db->prepare("SELECT id FROM users WHERE id=? AND assigned_dean_id=?");
    $check->execute([$userId, $currentUserId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Not your faculty']);
        exit;
    }
}

// Get period details (use first period ID for deadline info)
$period = $db->prepare("SELECT * FROM rating_periods WHERE id = ?");
$period->execute([$periodIds[0]]);
$period = $period->fetch();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Period not found']);
    exit;
}

// Calculate deadline info
$isOverdue = false;
$daysUntilDeadline = null;
$deadlineText = 'No deadline set';
$urgency = 'REMINDER';
$urgencyStyle = 'color: #0369a1;';

if ($period['submission_deadline']) {
    $deadline = strtotime($period['submission_deadline'] . ' 23:59:59');
    $now = time();
    $diff = $deadline - $now;
    $daysUntilDeadline = ceil($diff / 86400);
    $isOverdue = $daysUntilDeadline < 0;
    
    if ($isOverdue) {
        $urgency = "OVERDUE";
        $deadlineText = "The deadline was " . date('F d, Y', strtotime($period['submission_deadline'])) . ". Please submit immediately!";
        $urgencyStyle = "color: #dc2626; font-weight: bold;";
    } elseif ($daysUntilDeadline <= 1) {
        $urgency = "URGENT";
        $deadlineText = $daysUntilDeadline == 0 ? "Deadline is TODAY!" : "Deadline: Tomorrow - " . date('F d, Y', strtotime($period['submission_deadline']));
        $urgencyStyle = "color: #d97706; font-weight: bold;";
    } else {
        $urgency = "REMINDER";
        $deadlineText = "Deadline: " . date('F d, Y', strtotime($period['submission_deadline'])) . " ({$daysUntilDeadline} days remaining)";
        $urgencyStyle = "color: #0369a1;";
    }
}

// Prepare email
$facultyName = "{$faculty['first_name']} {$faculty['last_name']}";
$subject = "[{$urgency}] IPCR Submission Reminder - {$periodName}";

$message = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #1a5c2a 0%, #207832 100%); color: white; padding: 30px 20px; border-radius: 10px 10px 0 0; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f9fafb; padding: 30px 20px; border-left: 1px solid #ddd; border-right: 1px solid #ddd; }
        .urgency-box { background: #fff; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .details table { width: 100%; }
        .details td { padding: 8px 0; }
        .details td:first-child { font-weight: bold; width: 150px; color: #666; }
        .button { display: inline-block; background: #1a5c2a; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; border: 1px solid #ddd; border-top: none; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔔 IPCR Submission Reminder</h1>
        </div>
        <div class='content'>
            <p>Dear <strong>{$facultyName}</strong>,</p>
            
            <div class='urgency-box'>
                <p style='margin: 0; {$urgencyStyle}'>{$urgency}: You have NOT yet submitted your IPCR for this period.</p>
            </div>
            
            <div class='details'>
                <table>
                    <tr>
                        <td>Rating Period:</td>
                        <td><strong>{$periodName}</strong></td>
                    </tr>
                    <tr>
                        <td>Your Employee ID:</td>
                        <td>{$faculty['employee_id']}</td>
                    </tr>
                    <tr>
                        <td>Department:</td>
                        <td>{$faculty['department']}</td>
                    </tr>
                    <tr>
                        <td>Deadline:</td>
                        <td><strong style='color: " . ($isOverdue ? '#dc2626' : '#d97706') . ";'>{$deadlineText}</strong></td>
                    </tr>
                    <tr>
                        <td>Status:</td>
                        <td><span style='color: #dc2626; font-weight: bold;'>NOT SUBMITTED</span></td>
                    </tr>
                </table>
            </div>
            
            <p><strong>Action Required:</strong></p>
            <p>Please log in to the IPCR system and submit your Individual Performance Commitment and Review (IPCR) form as soon as possible.</p>
            
            <div style='text-align: center;'>
                <a href='" . BASE_URL . "' class='button'>→ Submit My IPCR Now</a>
            </div>
            
            <p style='margin-top: 30px; font-size: 14px; color: #666;'>
                <strong>Need Help?</strong><br>
                If you have any questions or need assistance, please contact your evaluator or the HR Administrator.
            </p>
        </div>
        <div class='footer'>
            <p><strong>ISU Ilagan - IPCR Management System</strong></p>
            <p>This is an automated reminder. Please do not reply to this email.</p>
            <p style='margin-top: 10px; font-size: 11px; color: #9ca3af;'>
                Sent on " . date('F d, Y \a\t h:i A') . "
            </p>
        </div>
    </div>
</body>
</html>
";

// Send email
try {
    $sent = sendEmail($faculty['email'], $subject, $message, ['is_html' => true]);
    
    if ($sent) {
        // Log the notification
        try {
            auditLog(
                'individual_notification_sent',
                'user',
                $faculty['id'],
                $facultyName,
                null,
                "Individual IPCR reminder sent for period: {$periodName} (sent by {$currentUser['first_name']} {$currentUser['last_name']})"
            );
        } catch (Exception $e) {
            // Silently fail audit log
            error_log("Audit log error: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Email sent successfully to {$facultyName}",
            'recipient' => $faculty['email']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Failed to send email to {$facultyName}",
            'recipient' => $faculty['email']
        ]);
    }
} catch (Exception $e) {
    error_log("Email sending error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => "Error sending email: " . $e->getMessage()
    ]);
}

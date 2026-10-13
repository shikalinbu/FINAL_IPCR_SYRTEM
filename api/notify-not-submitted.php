<?php
/**
 * Send email notifications to faculty who have NOT submitted their IPCR
 */

// Start output buffering to prevent any HTML/PHP errors from breaking JSON
ob_start();

// Disable error display, log errors instead
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set max execution time early
set_time_limit(120);
ini_set('max_execution_time', '120');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/constants.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../config/email.php';
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Configuration error: ' . $e->getMessage()
    ]);
    exit;
}

// Clean any output that may have been generated
ob_end_clean();

// Now set JSON header
header('Content-Type: application/json');

// Check authentication
try {
    requireRole('admin', 'secretary', 'dean');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$periodId = (int)($data['period_id'] ?? 0);

if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'Missing period ID']);
    exit;
}

$db = getDB();

// Get period details
$period = $db->prepare("SELECT * FROM rating_periods WHERE id=?");
$period->execute([$periodId]);
$period = $period->fetch();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Period not found']);
    exit;
}

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

// Get faculty who have NOT submitted
$submittedUserIds = $db->query("
    SELECT DISTINCT user_id FROM ipcr_forms 
    WHERE period_id={$periodId} 
    AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
")->fetchAll(PDO::FETCH_COLUMN);

$whereNotSubmitted = "u.role='faculty' AND u.is_active=1 {$facultyFilter}";
if (!empty($submittedUserIds)) {
    $idList = implode(',', array_map('intval', $submittedUserIds));
    $whereNotSubmitted .= " AND u.id NOT IN ({$idList})";
}

$notSubmittedFaculty = $db->query("
    SELECT u.id, u.email, u.first_name, u.last_name, u.employee_id,
           CONCAT(d.first_name, ' ', d.last_name) as dean_name
    FROM users u
    LEFT JOIN users d ON u.assigned_dean_id = d.id
    WHERE {$whereNotSubmitted}
    ORDER BY u.last_name, u.first_name
")->fetchAll();

if (empty($notSubmittedFaculty)) {
    echo json_encode(['success' => false, 'message' => 'All faculty have submitted!']);
    exit;
}

// Calculate days until deadline
$daysUntilDeadline = null;
$isOverdue = false;
if ($period['submission_deadline']) {
    $deadline = strtotime($period['submission_deadline'] . ' 23:59:59');
    $now = time();
    $diff = $deadline - $now;
    $daysUntilDeadline = ceil($diff / 86400);
    $isOverdue = $daysUntilDeadline < 0;
}

// Send emails with chunked progress reporting
$successCount = 0;
$failCount = 0;
$errors = [];
$totalToSend = count($notSubmittedFaculty);

// Check if email system is configured
if (!function_exists('sendEmail')) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email system not configured. Please check config/email.php'
    ]);
    exit;
}

// If there are many emails, set longer timeout
if ($totalToSend > 20) {
    set_time_limit(300); // 5 minutes
    ini_set('max_execution_time', '300');
} else {
    set_time_limit(120); // 2 minutes for smaller batches
    ini_set('max_execution_time', '120');
}

// Calculate urgency and deadline text ONCE before processing
if ($isOverdue) {
    $urgency = "OVERDUE";
    $deadlineText = "The deadline was " . date('F d, Y', strtotime($period['submission_deadline'])) . ". Please submit immediately.";
    $urgencyStyle = "color: #dc2626; font-weight: bold;";
} else {
    $urgency = $daysUntilDeadline <= 3 ? "URGENT" : "REMINDER";
    $deadlineText = "Deadline: " . date('F d, Y', strtotime($period['submission_deadline'])) . " (" . abs($daysUntilDeadline) . " day" . (abs($daysUntilDeadline) > 1 ? 's' : '') . " remaining)";
    $urgencyStyle = $daysUntilDeadline <= 3 ? "color: #d97706; font-weight: bold;" : "color: #0369a1;";
}

// Batch process emails in chunks for better performance
$chunkSize = 10; // Increased back to 10 since we'll optimize SMTP
$chunks = array_chunk($notSubmittedFaculty, $chunkSize);

// Optimize: Prepare email template once
$emailTemplate = prepareEmailTemplate($period, $isOverdue, $daysUntilDeadline, $deadlineText, $urgencyStyle, $urgency);

// OPTIMIZATION: Reuse PHPMailer instance with keepalive connection
$mail = null;
$usingOptimizedMailer = false;

// Check if we can use SMTP (constants are defined in email.php)
if (file_exists(__DIR__ . '/../vendor/autoload.php') && function_exists('sendEmail')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP settings with performance optimizations
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        
        // PERFORMANCE: Keep connection alive for batch sending
        $mail->SMTPKeepAlive = true;
        
        // PERFORMANCE: Disable SSL verification for speed (localhost only)
        if (IS_LOCALHOST) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
        }
        
        // PERFORMANCE: Set shorter timeout
        $mail->Timeout = 10; // 10 seconds instead of default 30
        
        // Set sender once
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        
        $usingOptimizedMailer = true;
        
    } catch (Exception $e) {
        error_log("PHPMailer initialization failed: " . $e->getMessage());
        $mail = null;
        $usingOptimizedMailer = false;
    }
}

foreach ($chunks as $chunkIndex => $chunk) {
    foreach ($chunk as $faculty) {
        if (!$faculty['email']) {
            $failCount++;
            $errors[] = "{$faculty['first_name']} {$faculty['last_name']} has no email address";
            continue;
        }

        // Prepare personalized email content
        $facultyName = "{$faculty['first_name']} {$faculty['last_name']}";
        $subject = "IPCR Submission Reminder - {$period['name']}";
        
        // Replace placeholders in template
        $message = str_replace(
            ['{{FACULTY_NAME}}', '{{EMPLOYEE_ID}}'],
            [$facultyName, $faculty['employee_id']],
            $emailTemplate
        );

        // Send email with error handling
        try {
            $sent = false;
            
            // Use optimized PHPMailer if available
            if ($usingOptimizedMailer && $mail !== null) {
                try {
                    // Clear previous recipient
                    $mail->clearAddresses();
                    $mail->addAddress($faculty['email']);
                    
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags($message);
                    
                    $sent = $mail->send();
                } catch (Exception $e) {
                    error_log("PHPMailer send error for {$facultyName}: " . $e->getMessage());
                    $sent = false;
                }
            } else {
                // Fallback to regular sendEmail function
                if (function_exists('sendEmail')) {
                    $sent = sendEmail($faculty['email'], $subject, $message, ['html' => true]);
                } else {
                    error_log("sendEmail function not found");
                    $sent = false;
                }
            }
            
            if ($sent) {
                $successCount++;
                
                // Log the notification (async - don't wait)
                try {
                    auditLog(
                        'notification_sent',
                        'user',
                        $faculty['id'],
                        $facultyName,
                        null,
                        "IPCR submission reminder sent for period: {$period['name']}"
                    );
                } catch (Exception $e) {
                    // Silently fail audit log to not block email sending
                    error_log("Audit log error: " . $e->getMessage());
                }
            } else {
                $failCount++;
                $errors[] = "Failed to send to {$facultyName}";
            }
        } catch (Exception $e) {
            $failCount++;
            $errors[] = "Error sending to {$facultyName}";
            error_log("Email error for {$facultyName}: " . $e->getMessage());
        }
    }
    
    // NO DELAY between chunks - removed usleep() for speed
}

// Close SMTP connection if it was opened
if ($usingOptimizedMailer && $mail !== null) {
    try {
        $mail->smtpClose();
    } catch (Exception $e) {
        // Ignore close errors
        error_log("SMTP close error: " . $e->getMessage());
    }
}

// Helper function to prepare email template once
function prepareEmailTemplate($period, $isOverdue, $daysUntilDeadline, $deadlineText, $urgencyStyle, $urgency) {
    return "
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
            <p>Dear <strong>{{FACULTY_NAME}}</strong>,</p>
            
            <div class='urgency-box'>
                <p style='margin: 0; {$urgencyStyle}'>{$urgency}: You have NOT yet submitted your IPCR for this period.</p>
            </div>
            
            <div class='details'>
                <table>
                    <tr>
                        <td>Rating Period:</td>
                        <td><strong>{$period['name']}</strong></td>
                    </tr>
                    <tr>
                        <td>Your Employee ID:</td>
                        <td>{{EMPLOYEE_ID}}</td>
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
        </div>
    </div>
</body>
</html>
    ";
}

// Return results
$message = "Notification process completed!";
$details = [];

if ($successCount > 0) {
    $details[] = "{$successCount} email(s) sent successfully";
}
if ($failCount > 0) {
    $details[] = "{$failCount} email(s) failed";
    $message = "Some emails failed to send. Check details below.";
}

// If on localhost, add note
if (IS_LOCALHOST && $successCount > 0) {
    $message = "✓ Emails simulated successfully (localhost mode)";
    $details[] = "Email sending is in TEST MODE (localhost)";
    $details[] = "Check PHP error log for email content";
}

$response = [
    'success' => $successCount > 0 || ($failCount === 0 && count($notSubmittedFaculty) === 0),
    'message' => $message,
    'details' => implode(', ', $details),
    'sent_count' => $successCount,
    'fail_count' => $failCount,
    'total_faculty' => count($notSubmittedFaculty),
    'is_localhost' => IS_LOCALHOST,
    'used_optimized_mailer' => $usingOptimizedMailer
];

// Only include errors if there are any
if (!empty($errors)) {
    $response['errors'] = $errors;
}

echo json_encode($response);

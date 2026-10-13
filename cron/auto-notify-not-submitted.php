<?php
/**
 * AUTOMATIC NOTIFICATION SCRIPT
 * 
 * This script automatically sends email reminders to faculty who have NOT submitted their IPCR.
 * 
 * HOW TO USE:
 * 
 * Option 1: Windows Task Scheduler
 * - Open Task Scheduler
 * - Create Basic Task
 * - Schedule: Daily at 9:00 AM
 * - Action: Start a program
 * - Program: C:\xampp\php\php.exe
 * - Arguments: "C:\xampp\htdocs\New folder\ipcr-system\cron\auto-notify-not-submitted.php"
 * 
 * Option 2: Manual Run
 * - Run in browser: http://localhost/New folder/ipcr-system/cron/auto-notify-not-submitted.php
 * - Or run in terminal: php auto-notify-not-submitted.php
 */

// Prevent running from browser (optional - remove if you want to run via browser)
// if (php_sapi_name() !== 'cli') {
//     die('This script can only be run from command line.');
// }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/email.php';

echo "=== AUTOMATIC IPCR SUBMISSION REMINDER ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$db = getDB();

// Get all active periods (open or rating status)
$periods = $db->query("
    SELECT * FROM rating_periods 
    WHERE status IN ('open', 'rating') 
    AND submission_deadline IS NOT NULL
    ORDER BY submission_deadline ASC
")->fetchAll();

if (empty($periods)) {
    echo "❌ No active periods with deadlines found.\n";
    exit;
}

$totalSent = 0;
$totalFailed = 0;
$totalPeriods = 0;

foreach ($periods as $period) {
    echo "\n📅 Processing Period: {$period['name']}\n";
    echo "   Deadline: " . date('F d, Y', strtotime($period['submission_deadline'])) . "\n";
    
    // Calculate days until deadline
    $deadline = strtotime($period['submission_deadline'] . ' 23:59:59');
    $now = time();
    $daysUntil = ceil(($deadline - $now) / 86400);
    
    echo "   Days until deadline: {$daysUntil}\n";
    
    // Only send notifications if:
    // - 7 days before deadline
    // - 3 days before deadline
    // - 1 day before deadline
    // - On deadline day
    // - 1-7 days overdue
    $shouldSend = false;
    $reminderType = '';
    
    if ($daysUntil == 7) {
        $shouldSend = true;
        $reminderType = '1 WEEK REMINDER';
    } elseif ($daysUntil == 3) {
        $shouldSend = true;
        $reminderType = '3 DAYS REMINDER';
    } elseif ($daysUntil == 1) {
        $shouldSend = true;
        $reminderType = 'FINAL REMINDER';
    } elseif ($daysUntil == 0) {
        $shouldSend = true;
        $reminderType = 'DEADLINE TODAY';
    } elseif ($daysUntil < 0 && $daysUntil >= -7) {
        $shouldSend = true;
        $reminderType = 'OVERDUE NOTICE';
    }
    
    if (!$shouldSend) {
        echo "   ⏭️ Skipping - not a reminder day\n";
        continue;
    }
    
    echo "   📧 Sending: {$reminderType}\n";
    
    // Get faculty who have NOT submitted
    $submittedUserIds = $db->query("
        SELECT DISTINCT user_id FROM ipcr_forms 
        WHERE period_id={$period['id']} 
        AND status IN ('submitted', 'under_review', 'reviewed', 'approved', 'finalized')
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    $whereNotSubmitted = "u.role='faculty' AND u.is_active=1";
    if (!empty($submittedUserIds)) {
        $idList = implode(',', array_map('intval', $submittedUserIds));
        $whereNotSubmitted .= " AND u.id NOT IN ({$idList})";
    }
    
    $notSubmittedFaculty = $db->query("
        SELECT u.id, u.email, u.first_name, u.last_name, u.employee_id
        FROM users u
        WHERE {$whereNotSubmitted}
        ORDER BY u.last_name, u.first_name
    ")->fetchAll();
    
    echo "   👥 Faculty not submitted: " . count($notSubmittedFaculty) . "\n";
    
    if (empty($notSubmittedFaculty)) {
        echo "   ✅ All faculty have submitted!\n";
        continue;
    }
    
    $periodSent = 0;
    $periodFailed = 0;
    
    foreach ($notSubmittedFaculty as $faculty) {
        if (!$faculty['email']) {
            echo "   ⚠️ Skipping {$faculty['first_name']} {$faculty['last_name']} - no email\n";
            $periodFailed++;
            continue;
        }
        
        $facultyName = "{$faculty['first_name']} {$faculty['last_name']}";
        $isOverdue = $daysUntil < 0;
        
        if ($isOverdue) {
            $urgency = "OVERDUE";
            $deadlineText = "The deadline was " . date('F d, Y', strtotime($period['submission_deadline'])) . ". Please submit immediately!";
            $urgencyStyle = "color: #dc2626; font-weight: bold;";
        } elseif ($daysUntil <= 1) {
            $urgency = "URGENT";
            $deadlineText = $daysUntil == 0 ? "Deadline is TODAY!" : "Deadline: Tomorrow - " . date('F d, Y', strtotime($period['submission_deadline']));
            $urgencyStyle = "color: #d97706; font-weight: bold;";
        } else {
            $urgency = "REMINDER";
            $deadlineText = "Deadline: " . date('F d, Y', strtotime($period['submission_deadline'])) . " ({$daysUntil} days remaining)";
            $urgencyStyle = "color: #0369a1;";
        }
        
        $subject = "[{$reminderType}] IPCR Submission - {$period['name']}";
        
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
        .button { display: inline-block; background: #1a5c2a; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; border: 1px solid #ddd; border-top: none; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔔 {$reminderType}</h1>
        </div>
        <div class='content'>
            <p>Dear <strong>{$facultyName}</strong>,</p>
            
            <div class='urgency-box'>
                <p style='margin: 0; {$urgencyStyle}'>{$urgency}: You have NOT yet submitted your IPCR for this period.</p>
            </div>
            
            <div class='details'>
                <p><strong>Rating Period:</strong> {$period['name']}</p>
                <p><strong>Your Employee ID:</strong> {$faculty['employee_id']}</p>
                <p><strong>{$deadlineText}</strong></p>
                <p style='color: #dc2626; font-weight: bold;'>Status: NOT SUBMITTED</p>
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
        
        $sent = sendEmail($faculty['email'], $subject, $message, ['is_html' => true]);
        
        if ($sent) {
            echo "   ✅ Sent to: {$facultyName} ({$faculty['email']})\n";
            $periodSent++;
            
            // Log the notification
            try {
                $db->prepare("
                    INSERT INTO audit_log (action, entity_type, entity_id, old_value, new_value, remarks, performed_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    'auto_notification_sent',
                    'user',
                    $faculty['id'],
                    $facultyName,
                    null,
                    "Auto reminder sent for period: {$period['name']} ({$reminderType})",
                    1 // System user
                ]);
            } catch (Exception $e) {
                // Silent fail on logging
            }
        } else {
            echo "   ❌ Failed: {$facultyName} ({$faculty['email']})\n";
            $periodFailed++;
        }
        
        // Small delay to avoid spam detection
        usleep(100000); // 0.1 second
    }
    
    echo "   📊 Results: {$periodSent} sent, {$periodFailed} failed\n";
    
    $totalSent += $periodSent;
    $totalFailed += $periodFailed;
    $totalPeriods++;
}

echo "\n=== SUMMARY ===\n";
echo "Periods processed: {$totalPeriods}\n";
echo "✅ Total emails sent: {$totalSent}\n";
echo "❌ Total failed: {$totalFailed}\n";
echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";

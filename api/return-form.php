<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/email.php';
requireRole('dean'); // Dean only

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$data    = json_decode(file_get_contents('php://input'), true);
$formId  = (int)($data['form_id'] ?? 0);
$remarks = trim($data['remarks']  ?? '');

if (!$formId) {
    echo json_encode(['success'=>false,'message'=>'Missing form_id']); exit;
}
if (!$remarks) {
    echo json_encode(['success'=>false,'message'=>'Please enter return remarks.']); exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) {
    echo json_encode(['success'=>false,'message'=>'Form not found']); exit;
}

// Dean can only return forms assigned to their faculty
$fac = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
$fac->execute([$form['user_id']]);
$fac = $fac->fetch();
if (!$fac || $fac['assigned_dean_id'] != $currentUserId) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

// Can only return if submitted or under_review
if (!in_array($form['status'], ['submitted','under_review','reviewed'])) {
    echo json_encode(['success'=>false,'message'=>'Cannot return form in status: '.$form['status']]); exit;
}

$db->prepare("
    UPDATE ipcr_forms
    SET status='returned', return_remarks=?, updated_at=NOW()
    WHERE id=?
")->execute([$remarks, $formId]);

$db->prepare("INSERT INTO form_history (form_id, action, done_by, remarks) VALUES (?,?,?,?)")
   ->execute([$formId, 'returned', $currentUserId, $remarks]);

auditLog('ipcr_returned', 'ipcr_form', $formId, $form['status'], 'returned', $remarks);

// Send email notification to faculty
$facultyStmt = $db->prepare("
    SELECT u.first_name, u.last_name, u.email, u.personal_email, u.employee_id,
           p.name as period_name
    FROM users u
    LEFT JOIN ipcr_forms f ON f.user_id = u.id
    LEFT JOIN rating_periods p ON p.id = f.period_id
    WHERE u.id = ?
");
$facultyStmt->execute([$form['user_id']]);
$faculty = $facultyStmt->fetch();

if ($faculty) {
    $deanStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $deanStmt->execute([$currentUserId]);
    $dean = $deanStmt->fetch();
    $deanName = $dean ? "{$dean['first_name']} {$dean['last_name']}" : "Your Dean";
    
    $facultyEmail = $faculty['personal_email'] ?: $faculty['email'];
    $facultyName = "{$faculty['first_name']} {$faculty['last_name']}";
    
    $subject = "ISU IPCR System - Your IPCR has been Returned";
    
    $message = "
Hello {$facultyName},

Your IPCR form for {$faculty['period_name']} has been returned by {$deanName} for revisions.

RETURN REMARKS:
{$remarks}

NEXT STEPS:
1. Login to the IPCR System: " . BASE_URL . "
2. Review the remarks from your dean
3. Make the necessary corrections
4. Resubmit your IPCR form

If you have any questions about the required changes, please contact your dean directly.

---
This is an automated notification from ISU IPCR Management System.
Isabela State University - Ilagan Campus
";
    
    // Send the email
    sendEmail($facultyEmail, $subject, $message);
}

echo json_encode(['success'=>true]);

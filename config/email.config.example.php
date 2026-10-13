<?php
/**
 * ============================================================================
 * EMAIL CONFIGURATION TEMPLATE
 * ============================================================================
 * 
 * INSTRUCTIONS:
 * 1. Copy this file and rename to: email.php
 * 2. Choose ONE option below (Gmail or ISU Email)
 * 3. Fill in your details
 * 4. Save the file
 * 
 * NOTE: SA LOCALHOST (XAMPP), HINDI KAILANGAN I-CONFIGURE!
 *       Email sending is automatically disabled on localhost.
 * ============================================================================
 */

// ============================================================================
// OPTION 1: USING GMAIL (EASIEST)
// ============================================================================
// 
// STEP 1: Get Gmail App Password
//    → Go to: https://myaccount.google.com/apppasswords
//    → Create app password for "Mail"
//    → Copy the 16-character password
//
// STEP 2: Fill in the details below:

define('EMAIL_METHOD', 'smtp');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'ipcr.system@gmail.com');    // ← CHANGE THIS: Your Gmail address
define('SMTP_PASSWORD', 'abcd efgh ijkl mnop');      // ← CHANGE THIS: Your App Password (remove spaces)
define('SMTP_ENCRYPTION', 'tls');

// ============================================================================
// OPTION 2: USING ISU EMAIL SERVER
// ============================================================================
// 
// STEP 1: Contact ISU IT Department and ask for:
//    - SMTP Server Address
//    - SMTP Port
//    - Email Account Credentials
//
// STEP 2: Uncomment and fill in the details below:

/*
define('EMAIL_METHOD', 'smtp');
define('SMTP_HOST', 'mail.isu.edu.ph');              // ← CHANGE THIS: ISU SMTP server
define('SMTP_PORT', 587);                            // ← CHANGE THIS: Port from IT (587 or 465)
define('SMTP_USERNAME', 'ipcr@isu.edu.ph');         // ← CHANGE THIS: ISU email account
define('SMTP_PASSWORD', 'your-isu-password');        // ← CHANGE THIS: Email password
define('SMTP_ENCRYPTION', 'tls');                    // ← CHANGE THIS: tls or ssl
*/

// ============================================================================
// OPTION 3: USING SERVER'S BUILT-IN MAIL (FOR BASIC SERVERS)
// ============================================================================
// 
// If your server has PHP mail() configured, uncomment this:

/*
define('EMAIL_METHOD', 'php_mail');
*/

// ============================================================================
// EMAIL SENDER SETTINGS (WHAT USERS WILL SEE)
// ============================================================================

define('EMAIL_FROM_ADDRESS', 'noreply@isu.edu.ph');
define('EMAIL_FROM_NAME', 'ISU IPCR System');
define('EMAIL_REPLY_TO', 'support@isu.edu.ph');

// ============================================================================
// AUTOMATIC LOCALHOST DETECTION (NO CHANGES NEEDED)
// ============================================================================

define('IS_LOCALHOST', in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1']));

// ============================================================================
// EMAIL SENDING FUNCTIONS (DO NOT EDIT BELOW THIS LINE)
// ============================================================================

require_once __DIR__ . '/../config/constants.php';

function sendEmail($to, $subject, $message, $options = []) {
    if (IS_LOCALHOST) {
        error_log("[LOCALHOST] Email would be sent to: $to");
        error_log("[LOCALHOST] Subject: $subject");
        return false;
    }
    
    if (EMAIL_METHOD === 'smtp') {
        return sendEmailSMTP($to, $subject, $message, $options);
    }
    
    return sendEmailPHPMail($to, $subject, $message, $options);
}

function sendEmailSMTP($to, $subject, $message, $options = []) {
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        error_log('PHPMailer not installed. Run: composer require phpmailer/phpmailer');
        return false;
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addReplyTo(EMAIL_REPLY_TO);
        $mail->addAddress($to);
        
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->CharSet = 'UTF-8';
        
        if (isset($options['html']) && $options['html']) {
            $mail->isHTML(true);
        }
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

function sendEmailPHPMail($to, $subject, $message, $options = []) {
    $headers = [];
    $headers[] = 'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM_ADDRESS . '>';
    $headers[] = 'Reply-To: ' . EMAIL_REPLY_TO;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = 'MIME-Version: 1.0';
    
    if (isset($options['html']) && $options['html']) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    
    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function sendCredentialsEmail($user, $tempPassword) {
    $sendToEmail = $user['personal_email'] ?: $user['email'];
    
    $subject = 'ISU IPCR System - Your Login Credentials';
    $message = "
Hello {$user['first_name']} {$user['last_name']},

Your login credentials for the ISU IPCR Management System:

Employee ID: {$user['employee_id']}
Temporary Password: {$tempPassword}

Role: " . ucfirst($user['role']) . "

Login at: " . BASE_URL . "

IMPORTANT: Please change your password after logging in.

---
This is an automated message from ISU IPCR System.
If you did not request this, please contact your administrator immediately.
";
    
    $sent = sendEmail($sendToEmail, $subject, $message);
    
    if ($sent) {
        return [
            'success' => true,
            'message' => "✅ Your credentials have been sent to {$sendToEmail}. Please check your inbox."
        ];
    } else {
        return [
            'success' => false,
            'message' => "⚠️ Email sending is not configured on this server. Here are your credentials:<br><br>
                    <strong>Employee ID:</strong> " . htmlspecialchars($user['employee_id']) . "<br>
                    <strong>Temporary Password:</strong> " . htmlspecialchars($tempPassword) . "<br><br>
                    Please write these down and change your password after logging in.",
            'credentials' => [
                'employee_id' => $user['employee_id'],
                'temp_password' => $tempPassword
            ]
        ];
    }
}

function sendWelcomeEmail($user, $password) {
    $sendToEmail = $user['personal_email'] ?: $user['email'];
    
    $subject = 'Welcome to ISU IPCR System';
    $message = "
Hello {$user['first_name']} {$user['last_name']},

Welcome to the ISU IPCR Management System!

Your account has been created with the following credentials:

Employee ID: {$user['employee_id']}
Initial Password: {$password}
Role: " . ucfirst($user['role']) . "
Department: {$user['department']}

Login at: " . BASE_URL . "

IMPORTANT: 
1. Please login and change your password immediately
2. Keep your credentials secure
3. Contact IT Support if you have any issues

---
ISU IPCR Management System
Isabela State University - Ilagan Campus
";
    
    return sendEmail($sendToEmail, $subject, $message);
}

// ============================================================================
// CONFIGURATION COMPLETE!
// ============================================================================
// 
// NEXT STEPS:
// 1. Save this file as: config/email.php
// 2. Install PHPMailer: composer require phpmailer/phpmailer
// 3. Test using: install_email.php or test_email.php
// 
// For more help, read: PAANO_MAG_SETUP_EMAIL.md
// ============================================================================

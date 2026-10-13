<?php
/**
 * Email Configuration
 * 
 * IMPORTANT: This file contains email sending configuration.
 * Update these settings when deploying to production server.
 */

// Email sending method
// Options: 'smtp' (recommended for production) or 'php_mail' (for basic servers)
define('EMAIL_METHOD', 'smtp');

// SMTP Configuration (for Gmail, Outlook, or ISU email server)
define('SMTP_HOST', 'smtp.gmail.com');  // Gmail SMTP
define('SMTP_PORT', 587);                // 587 for TLS, 465 for SSL
define('SMTP_USERNAME', 'jhonercilla11@gmail.com');  // CHANGE THIS to your Gmail
define('SMTP_PASSWORD', 'oxmm ujty dvtk rhbw');  // PASTE the 16-char password from Google
define('SMTP_ENCRYPTION', 'tls');        // 'tls' or 'ssl'

// From Address (what users will see)
define('EMAIL_FROM_ADDRESS', 'jhonercilla11@gmail.com');  // CHANGE THIS to your Gmail
define('EMAIL_FROM_NAME', 'ISU IPCR System');

// Reply-To Address
define('EMAIL_REPLY_TO', 'support@isu.edu.ph');

// Environment check
define('IS_LOCALHOST', in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1']));

/**
 * Send email using configured method
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email body (plain text or HTML)
 * @param array $options Additional options: ['html' => true] for HTML emails
 * @return bool Success status
 */
function sendEmail($to, $subject, $message, $options = []) {
    // LOCALHOST MODE: Enable email testing on localhost
    // Comment out the block below if you want to test emails locally
    if (IS_LOCALHOST) {
        error_log("[LOCALHOST EMAIL TEST] To: $to | Subject: $subject");
        // Return true to simulate success for testing
        // Change to false if you want to see credentials on screen
        // return false;
    }
    
    // Production: Use SMTP
    if (EMAIL_METHOD === 'smtp') {
        return sendEmailSMTP($to, $subject, $message, $options);
    }
    
    // Fallback: Use PHP mail() function
    return sendEmailPHPMail($to, $subject, $message, $options);
}

/**
 * Send email using SMTP (PHPMailer)
 */
function sendEmailSMTP($to, $subject, $message, $options = []) {
    // Check if PHPMailer is installed
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        error_log('PHPMailer not installed. Run: composer require phpmailer/phpmailer');
        return false;
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        
        // Disable SSL verification for localhost/testing (remove in production)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Optional: Enable debug output (comment out in production)
        // $mail->SMTPDebug = 2; // 0 = off, 1 = client, 2 = client and server
        // $mail->Debugoutput = 'error_log';
        
        // Sender
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addReplyTo(EMAIL_REPLY_TO);
        
        // Recipient
        $mail->addAddress($to);
        
        // Content
        $mail->Subject = $subject;
        $mail->CharSet = 'UTF-8';
        
        // Check if HTML or plain text
        if (isset($options['html']) && $options['html']) {
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = strip_tags($message); // Plain text fallback
        } else {
            $mail->isHTML(false);
            $mail->Body = $message;
        }
        
        // Send
        $result = $mail->send();
        
        if ($result) {
            error_log("✓ Email sent successfully to: $to");
        }
        
        return $result;
        
    } catch (\Exception $e) {
        $errorMsg = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        error_log("✗ Email sending failed to $to: $errorMsg");
        return false;
    }
}

/**
 * Send email using PHP mail() function (fallback)
 */
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

/**
 * Send credentials email to user
 * 
 * @param array $user User data
 * @param string $tempPassword Temporary password
 * @return array ['success' => bool, 'message' => string]
 */
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
        // On localhost or if email fails, return credentials to display on screen
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

/**
 * Send welcome email when admin creates a new user
 * 
 * @param array $user User data
 * @param string $password Initial password
 * @return bool Success status
 */
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

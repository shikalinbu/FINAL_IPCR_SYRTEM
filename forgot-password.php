<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/email.php';

$pageTitle = 'Get My Credentials';
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
        $msgType = 'danger';
    } else {
        $db = getDB();
        
        // Check if email exists in system (ISU email OR personal email)
        $stmt = $db->prepare("SELECT id, employee_id, first_name, last_name, email, personal_email, role FROM users WHERE (email = ? OR personal_email = ?) AND is_active = 1");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $msg = 'Email not found in the system. Please contact your administrator.';
            $msgType = 'danger';
        } else {
            // Generate temporary password
            $tempPassword = 'ISU' . rand(1000, 9999) . '!';
            $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);
            
            // Update user with temporary password
            $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$passwordHash, $user['id']]);
            
            // Send credentials email using new email config
            $result = sendCredentialsEmail($user, $tempPassword);
            
            if ($result['success']) {
                $msg = $result['message'];
                $msgType = 'success';
            } else {
                $msg = $result['message'];
                $msgType = 'warning';
            }
            
            // Log the credential request
            error_log("Credential request for {$user['employee_id']} ({$email}) - Temp password generated");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — ISU IPCR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/main.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="text-center mb-4">
      <div class="login-logo-wrap">
        <img src="<?= BASE_URL ?>/assets/images/R.png"
             onerror="this.src='<?= BASE_URL ?>/assets/images/isu-logo.svg'"
             class="login-isu-logo" alt="ISU Ilagan Logo">
        <div class="login-title">Isabela State University<br>Ilagan Campus</div>
        <div class="login-subtitle">
          Get My Credentials<br>
          <small style="font-size: 0.9rem; opacity: 0.9;">Enter your email to receive your login credentials</small>
        </div>
      </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>" role="alert">
      <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : ($msgType === 'warning' ? 'exclamation-triangle' : 'x-circle') ?>-fill me-2"></i><?= h($msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <div class="mb-4">
        <label for="email" class="form-label">Email Address <span class="required-star">*</span></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" id="email" name="email" class="form-control"
            placeholder="your.personal.email@gmail.com"
            value="<?= h($_POST['email'] ?? '') ?>"
            required autofocus>
        </div>
        <small class="text-white-50 d-block mt-2">
          <i class="bi bi-info-circle me-1"></i>
          Use your personal email (Gmail, Yahoo, etc.) or ISU email
        </small>
      </div>

      <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="bi bi-send me-2"></i>Get My Credentials
      </button>
    </form>

    <div class="text-center mt-3">
      <a href="<?= BASE_URL ?>/index.php" class="text-decoration-none" style="color: #ffffff !important; font-weight: 600; font-size: 0.95rem; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(0, 0, 0, 0.3);">
        <i class="bi bi-arrow-left me-2"></i>Back to Login
      </a>
    </div>

    <div class="mt-3 p-3" style="background: rgba(255, 255, 255, 0.95); border-radius: 10px; font-size: 0.8rem;">
      <strong style="color: #1a5c2a;"><i class="bi bi-shield-check me-1"></i>How it works:</strong>
      <ol class="mb-0 mt-1 ps-3" style="color: #334a38; line-height: 1.4;">
        <li>Enter your email (Gmail, Yahoo, or ISU email)</li>
        <li>System verifies your account</li>
        <li>You'll receive Employee ID and temporary password</li>
        <li>Login and change password immediately</li>
      </ol>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empId = trim($_POST['employee_id'] ?? '');
    $pw    = $_POST['password'] ?? '';

    if (!$empId || !$pw) {
        $error = 'Please enter both Employee ID and password.';
    } else {
        $result = loginUser($empId, $pw);
        if ($result['success']) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// Load config for display
$instName = 'IPCR Management System';
$acronym  = 'IMS';
try {
    $rows = getDB()->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('institution_name','institution_acronym')")->fetchAll();
    foreach ($rows as $r) {
        if ($r['config_key'] === 'institution_name')    $instName = $r['config_value'];
        if ($r['config_key'] === 'institution_acronym') $acronym  = $r['config_value'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — IPCR System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>/assets/css/main.css" rel="stylesheet">
  <style>
    /* Fix: Make eye icon always visible */
    #toggle-pw {
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      min-width: 42px !important;
      background-color: #e9ecef !important;
      border: 1px solid #ced4da !important;
      cursor: pointer !important;
    }
    #toggle-pw i {
      color: #6c757d !important;
      opacity: 1 !important;
      font-size: 1.1rem !important;
      display: inline-block !important;
    }
    #toggle-pw:hover {
      background-color: #dee2e6 !important;
    }
    #toggle-pw:hover i {
      color: #495057 !important;
    }
    
    /* Developer Credits Footer - Clean and Minimal */
    .login-footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: transparent;
      padding: 12px 20px;
      text-align: center;
      z-index: 1000;
    }
    
    .login-footer p {
      color: white;
      font-size: 0.85rem;
      margin: 0;
      line-height: 1.6;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(0, 0, 0, 0.3);
      font-weight: 500;
    }
    
    .login-footer strong {
      color: #f0c940;
      font-weight: 600;
    }
    
    .login-footer i {
      color: #f0c940;
    }
    
    /* Reduce bottom padding on login page */
    .login-page {
      padding-bottom: 50px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .login-footer {
        font-size: 0.75rem;
        padding: 10px 16px;
      }
      
      .login-footer .ms-3 {
        display: block;
        margin-left: 0 !important;
        margin-top: 4px;
      }
    }
  </style>
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
          Individual Performance Commitment &amp; Review<br>Management System
        </div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <div class="mb-3">
        <label for="employee_id" class="form-label">Employee ID <span class="required-star">*</span></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
          <input type="text" id="employee_id" name="employee_id" class="form-control"
            placeholder="e.g. FAC001" value="<?= h($_POST['employee_id'] ?? '') ?>"
            autocomplete="username" required autofocus>
        </div>
      </div>
      <div class="mb-4">
        <label for="password" class="form-label">Password <span class="required-star">*</span></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" id="password" name="password" class="form-control"
            placeholder="Enter your password" autocomplete="current-password" required>
          <button class="btn btn-outline-secondary" type="button" id="toggle-pw" tabindex="-1" style="border-color: #6c757d;">
            <i class="bi bi-eye" id="pw-eye" style="color: #6c757d; font-size: 1.1rem;"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
      </button>
    </form>

    <div class="text-center mt-3">
      <a href="<?= BASE_URL ?>/forgot-password.php" class="text-decoration-none" style="color: #ffffff !important; font-weight: 600; font-size: 0.95rem; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(0, 0, 0, 0.3);">
        <i class="bi bi-key me-2"></i>Forgot your credentials? Get them via email
      </a>
    </div>
  </div>
  
  <!-- Developer Credits Footer -->
  <div class="login-footer">
    <p class="mb-0">
      <i class="bi bi-code-slash me-2"></i><strong>Developed by:</strong>
      Andrei Sam P. Pambid • Darryl N. Tamayo • Arjay Balmores • Jhoncarl Ercilla • Jose Russel Aquino
      <span class="ms-3">© <?= date('Y') ?> ISU Ilagan</span>
    </p>
  </div>
  
</div><!-- /login-page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('toggle-pw').addEventListener('click',()=>{
  const pw=document.getElementById('password');
  const eye=document.getElementById('pw-eye');
  pw.type=pw.type==='password'?'text':'password';
  eye.className=pw.type==='password'?'bi bi-eye':'bi bi-eye-slash';
});
</script>
</body>
</html>

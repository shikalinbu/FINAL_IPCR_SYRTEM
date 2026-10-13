<?php
/**
 * Manual Autoloader for PHPMailer
 * This file loads PHPMailer classes without using Composer
 */

// PHPMailer core files
require_once __DIR__ . '/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/phpmailer/src/SMTP.php';

// Optional: OAuth support (if needed in future)
// require_once __DIR__ . '/phpmailer/phpmailer/src/OAuth.php';
// require_once __DIR__ . '/phpmailer/phpmailer/src/POP3.php';

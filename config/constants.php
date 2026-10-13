<?php
/**
 * Auto-detect BASE_URL based on server environment
 * Works on both localhost and online servers
 */
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$basePath = rtrim($scriptName, '/');

// Remove nested paths to get the root folder
$pathParts = explode('/', trim($basePath, '/'));
if (count($pathParts) > 0) {
    // If we're in a subfolder like /ipcr-system/config, go up to /ipcr-system
    $rootFolder = '/' . $pathParts[0];
} else {
    $rootFolder = '';
}

define('BASE_URL', $protocol . $host . $rootFolder);

// Alternative: If the above doesn't work, use manual configuration
// define('BASE_URL', 'http://apps.isu-ilagan.edu.ph:8888/ipcr-system');  // For online
// define('BASE_URL', 'http://localhost/ipcr-system');  // For localhost

define('UPLOAD_DIR',    __DIR__ . '/../uploads/');
define('UPLOAD_URL',    BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB

define('ROLE_FACULTY',    'faculty');
define('ROLE_DEAN',       'dean');
define('ROLE_ADMIN',      'admin');
define('ROLE_SECRETARY',  'secretary');

define('STATUS_DRAFT',        'draft');
define('STATUS_SUBMITTED',    'submitted');
define('STATUS_UNDER_REVIEW', 'under_review');
define('STATUS_REVIEWED',     'reviewed');
define('STATUS_RETURNED',     'returned');
define('STATUS_APPROVED',     'approved');
define('STATUS_FINALIZED',    'finalized');

define('EV_PENDING',        'pending');
define('EV_VERIFIED',       'verified');
define('EV_NEEDS_REVISION', 'needs_revision');
define('EV_INSUFFICIENT',   'insufficient');
define('EV_REJECTED',       'rejected');

define('ALLOWED_EXTENSIONS', ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','webp']);
define('ALLOWED_MIMES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png','image/gif','image/webp',
]);

$STATUS_LABELS = [
    'draft'        => 'Draft',
    'submitted'    => 'Submitted',
    'under_review' => 'Under Review',
    'reviewed'     => 'Reviewed',
    'returned'     => 'Returned',
    'approved'     => 'Approved',
    'finalized'    => 'Finalized',
];

$STATUS_COLORS = [
    'draft'        => 'secondary',
    'submitted'    => 'primary',
    'under_review' => 'info',
    'reviewed'     => 'warning',
    'returned'     => 'danger',
    'approved'     => 'success',
    'finalized'    => 'dark',
];

$EV_META = [
    'pending'        => ['label'=>'Pending Review',         'dot'=>'🟡', 'cls'=>'warning'],
    'verified'       => ['label'=>'Verified',               'dot'=>'🟢', 'cls'=>'success'],
    'needs_revision' => ['label'=>'Needs Revision',         'dot'=>'🟠', 'cls'=>'warning'],
    'insufficient'   => ['label'=>'Insufficient Evidence',  'dot'=>'🔴', 'cls'=>'danger'],
    'rejected'       => ['label'=>'Rejected',               'dot'=>'⚫', 'cls'=>'danger'],
];

// Faculty Positions
$FACULTY_POSITIONS = [
    'Instructor I',
    'Instructor II',
    'Instructor III',
    'Assistant Professor I',
    'Assistant Professor II',
    'Assistant Professor III',
    'Assistant Professor IV',
    'Associate Professor I',
    'Associate Professor II',
    'Associate Professor III',
    'Associate Professor IV',
    'Associate Professor V',
    'Professor I',
    'Professor II',
    'Professor III',
    'Professor IV',
    'Professor V',
    'Professor VI',
    'University Professor',
    'College Professor',
];

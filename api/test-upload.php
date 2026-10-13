<?php
// Simple upload test to debug the issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'Test API is working',
    'php_version' => PHP_VERSION,
    'post_data' => $_POST,
    'files_data' => isset($_FILES['evidence']) ? [
        'name' => $_FILES['evidence']['name'],
        'size' => $_FILES['evidence']['size'],
        'type' => $_FILES['evidence']['type'],
        'error' => $_FILES['evidence']['error']
    ] : 'No file received',
    'session_active' => session_status() === PHP_SESSION_ACTIVE,
    'session_data' => isset($_SESSION) ? [
        'user_id' => $_SESSION['user_id'] ?? 'not set',
        'role' => $_SESSION['role'] ?? 'not set'
    ] : 'No session'
]);

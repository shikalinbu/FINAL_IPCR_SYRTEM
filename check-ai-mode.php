<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $result = $db->query("SELECT config_value FROM system_config WHERE config_key='ai_verification_mode'")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'ai_mode' => $result ?: 'NOT SET (defaults to advisory)',
        'raw_value' => $result,
        'is_disabled' => $result === 'disabled',
        'message' => $result === 'disabled' 
            ? '✅ AI is DISABLED - uploads should work' 
            : ($result === 'advisory' 
                ? '⚠️ AI is ADVISORY - uploads allowed but AI checks' 
                : '❌ AI is STRICT or not set - may block uploads')
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

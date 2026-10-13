<?php
/**
 * Fix AI Verification Mode
 * Run this once to set AI mode to "advisory" (recommended)
 */

require_once __DIR__ . '/config/database.php';

try {
    $db = getDB();
    
    echo "<style>body{font-family:system-ui,sans-serif;padding:20px;max-width:900px;margin:0 auto}table{border-collapse:collapse;width:100%;margin:20px 0}th,td{border:1px solid #ddd;padding:12px;text-align:left}th{background:#1a5c2a;color:white}tr:nth-child(even){background:#f2f2f2}.success{color:#16a34a;font-weight:bold;padding:12px;background:#f0fdf4;border-left:4px solid #16a34a;margin:20px 0}.info{color:#0369a1;font-weight:bold;padding:12px;background:#f0f9ff;border-left:4px solid #0369a1;margin:20px 0}.warning{color:#ea580c;font-weight:bold;padding:12px;background:#fff7ed;border-left:4px solid #ea580c;margin:20px 0}</style>";
    
    echo "<h1>🔧 Fix AI Verification Mode</h1>";
    echo "<p>This script will set AI mode to <strong>'advisory'</strong> so uploads won't be blocked.</p>";
    echo "<hr>";
    
    echo "<h2>📊 Current AI Settings</h2>";
    
    // Check current setting
    $stmt = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('ai_verification_mode', 'gemini_api_key')");
    $settings = $stmt->fetchAll();
    
    echo "<table>";
    echo "<tr><th>Config Key</th><th>Current Value</th><th>Status</th></tr>";
    
    $hasMode = false;
    $currentMode = null;
    $hasApiKey = false;
    
    foreach ($settings as $s) {
        if ($s['config_key'] === 'ai_verification_mode') {
            $hasMode = true;
            $currentMode = $s['config_value'];
            $value = $s['config_value'] ?: '<em>(empty string)</em>';
            $status = $s['config_value'] === 'advisory' ? '✅ Correct' : '⚠️ Needs Fix';
        } else {
            $hasApiKey = strlen($s['config_value']) > 0;
            $value = $hasApiKey ? '[SET - ' . strlen($s['config_value']) . ' chars]' : '<em>[NOT SET]</em>';
            $status = $hasApiKey ? '✅ Configured' : 'ℹ️ Optional';
        }
        echo "<tr><td><strong>{$s['config_key']}</strong></td><td>{$value}</td><td>{$status}</td></tr>";
    }
    
    if (!$hasMode) {
        echo "<tr><td><strong>ai_verification_mode</strong></td><td><em style='color:red'>[MISSING]</em></td><td>❌ Not Found</td></tr>";
    }
    
    echo "</table>";
    
    // Check if ai_verification_mode exists
    echo "<hr>";
    echo "<h2>🔧 Fix Action</h2>";
    
    if (!$hasMode) {
        // Insert new setting
        $db->prepare("INSERT INTO system_config (config_key, config_value, updated_at) VALUES (?, ?, NOW())")
           ->execute(['ai_verification_mode', 'advisory']);
        echo "<div class='success'>✅ SUCCESS: Added ai_verification_mode = 'advisory'</div>";
        echo "<p>The setting was <strong>missing</strong> from the database. It has been created with the recommended value.</p>";
    } elseif ($currentMode === '') {
        // Fix empty string
        $db->prepare("UPDATE system_config SET config_value = ?, updated_at = NOW() WHERE config_key = ?")
           ->execute(['advisory', 'ai_verification_mode']);
        echo "<div class='success'>✅ SUCCESS: Fixed empty ai_verification_mode to 'advisory'</div>";
        echo "<p>The setting was set to an <strong>empty string</strong>. It has been corrected.</p>";
    } elseif ($currentMode !== 'advisory') {
        // Update existing setting
        $db->prepare("UPDATE system_config SET config_value = ?, updated_at = NOW() WHERE config_key = ?")
           ->execute(['advisory', 'ai_verification_mode']);
        echo "<div class='success'>✅ SUCCESS: Changed ai_verification_mode from '{$currentMode}' to 'advisory'</div>";
        echo "<p>The mode has been changed to the recommended setting.</p>";
    } else {
        echo "<div class='info'>ℹ️ INFO: ai_verification_mode is already set to 'advisory' (correct)</div>";
        echo "<p>No changes needed - already configured correctly!</p>";
    }
    
    echo "<hr>";
    echo "<h2>📊 Updated Settings</h2>";
    
    // Show updated settings
    $stmt = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('ai_verification_mode', 'gemini_api_key')");
    $settings = $stmt->fetchAll();
    
    echo "<table>";
    echo "<tr><th>Config Key</th><th>New Value</th><th>Status</th></tr>";
    foreach ($settings as $s) {
        if ($s['config_key'] === 'ai_verification_mode') {
            $value = $s['config_value'];
            $status = $s['config_value'] === 'advisory' ? '✅ Correct' : '⚠️ Unexpected';
        } else {
            $hasKey = strlen($s['config_value']) > 0;
            $value = $hasKey ? '[SET - ' . strlen($s['config_value']) . ' chars]' : '[NOT SET]';
            $status = $hasKey ? '✅ Configured' : 'ℹ️ Optional';
        }
        echo "<tr><td><strong>{$s['config_key']}</strong></td><td>{$value}</td><td>{$status}</td></tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h2>📖 What This Means</h2>";
    echo "<ul style='line-height:1.8;font-size:16px'>";
    echo "<li><strong>ai_verification_mode = 'advisory'</strong> means:</li>";
    echo "<ul><li>AI will check uploaded files</li>";
    echo "<li>AI will show confidence scores (e.g., 85%, 60%)</li>";
    echo "<li><strong>AI will NEVER block uploads</strong> - all files are allowed</li>";
    echo "<li>Faculty can upload syllabi, teaching loads, etc. without errors</li></ul>";
    echo "<li>You can still see AI feedback for manual review</li>";
    echo "<li>This is the <strong>recommended setting</strong> for production use</li>";
    echo "</ul>";
    
    echo "<hr>";
    echo "<div class='success' style='font-size:18px;text-align:center'>";
    echo "<strong>✅ AI Mode Fixed Successfully!</strong><br>";
    echo "You can now upload evidence files without being blocked.";
    echo "</div>";
    
    echo "<div style='margin:30px 0;padding:20px;background:#f9fafb;border-radius:8px;text-align:center'>";
    echo "<h3 style='margin-top:0'>🎯 Next Steps</h3>";
    echo "<div style='display:flex;gap:15px;justify-content:center;flex-wrap:wrap;margin-top:20px'>";
    echo "<a href='faculty/ipcr-form.php' style='background:#1a5c2a;color:white;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold'>→ Go to IPCR Form</a>";
    echo "<a href='admin/settings.php' style='background:#0369a1;color:white;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold'>→ View Settings</a>";
    echo "</div>";
    echo "</div>";
    
    echo "<hr>";
    echo "<div class='warning'>";
    echo "<strong>⚠️ Security Note:</strong> Delete this file (fix-ai-mode.php) after running it to prevent unauthorized access.";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='color:red;font-weight:bold;padding:20px;background:#fee;border:2px solid red;border-radius:8px'>";
    echo "❌ ERROR: " . htmlspecialchars($e->getMessage());
    echo "</div>";
    echo "<p>Make sure:</p>";
    echo "<ul>";
    echo "<li>XAMPP MySQL is running</li>";
    echo "<li>Database credentials in config/database.php are correct</li>";
    echo "<li>Database 'ipcr_system' exists</li>";
    echo "</ul>";
}
?>

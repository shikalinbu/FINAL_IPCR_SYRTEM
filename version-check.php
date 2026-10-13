<?php
// Quick version check - shows if files are updated
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Version Check</title>
    <style>
        body { font-family: system-ui; padding: 20px; max-width: 800px; margin: 0 auto; }
        .ok { color: #16a34a; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .warning { color: #ea580c; font-weight: bold; }
        pre { background: #f3f4f6; padding: 10px; border-radius: 5px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #1a5c2a; color: white; }
    </style>
</head>
<body>
    <h1>🔍 IPCR System Version Check</h1>
    <p>This checks if updated files are deployed on the server.</p>
    <hr>
    
    <h2>📂 File Version Check</h2>
    <table>
        <tr>
            <th>File</th>
            <th>Status</th>
            <th>Details</th>
        </tr>
        
        <?php
        $files = [
            'faculty/ipcr-form.php' => [
                'search' => 'Version 2.0',
                'critical' => true,
                'description' => 'Fixed AI blocking bug'
            ],
            'api/upload-evidence.php' => [
                'search' => 'AI CHECK (',
                'critical' => false,
                'description' => 'Debug logging added'
            ],
            'admin/settings.php' => [
                'search' => 'ai_verification_mode',
                'critical' => false,
                'description' => 'AI mode settings'
            ]
        ];
        
        foreach ($files as $file => $check) {
            $path = __DIR__ . '/' . $file;
            $exists = file_exists($path);
            
            if (!$exists) {
                echo "<tr>";
                echo "<td><strong>{$file}</strong></td>";
                echo "<td class='error'>❌ NOT FOUND</td>";
                echo "<td>File does not exist!</td>";
                echo "</tr>";
                continue;
            }
            
            $content = file_get_contents($path);
            $hasMarker = strpos($content, $check['search']) !== false;
            $fileTime = date('Y-m-d H:i:s', filemtime($path));
            
            echo "<tr>";
            echo "<td><strong>{$file}</strong>";
            if ($check['critical']) echo " <span style='color:#dc2626'>(CRITICAL)</span>";
            echo "</td>";
            
            if ($hasMarker) {
                echo "<td class='ok'>✅ UPDATED</td>";
                echo "<td>Last modified: {$fileTime}<br>{$check['description']}</td>";
            } else {
                echo "<td class='warning'>⚠️ OLD VERSION</td>";
                echo "<td>Last modified: {$fileTime}<br><strong>Needs update!</strong></td>";
            }
            echo "</tr>";
        }
        ?>
    </table>
    
    <hr>
    <h2>⚙️ Database Check</h2>
    <table>
        <tr>
            <th>Setting</th>
            <th>Value</th>
            <th>Status</th>
        </tr>
        
        <?php
        try {
            require_once __DIR__ . '/config/database.php';
            $db = getDB();
            
            $mode = $db->query("SELECT config_value FROM system_config WHERE config_key='ai_verification_mode'")->fetchColumn();
            $apiKey = $db->query("SELECT config_value FROM system_config WHERE config_key='gemini_api_key'")->fetchColumn();
            
            echo "<tr>";
            echo "<td><strong>AI Verification Mode</strong></td>";
            echo "<td>" . ($mode ?: '<em>Not Set (defaults to advisory)</em>') . "</td>";
            echo "<td class='" . ($mode === 'advisory' ? 'ok' : 'warning') . "'>";
            echo $mode === 'advisory' ? '✅ Recommended' : ($mode === 'disabled' ? '⚠️ Disabled' : '⚠️ Strict Mode');
            echo "</td>";
            echo "</tr>";
            
            echo "<tr>";
            echo "<td><strong>Gemini API Key</strong></td>";
            echo "<td>" . ($apiKey ? '[SET - ' . strlen($apiKey) . ' chars]' : '<em>Not Set</em>') . "</td>";
            echo "<td class='" . ($apiKey ? 'ok' : 'warning') . "'>";
            echo $apiKey ? '✅ Configured' : 'ℹ️ Optional';
            echo "</td>";
            echo "</tr>";
            
        } catch (Exception $e) {
            echo "<tr><td colspan='3' class='error'>❌ Database Error: " . $e->getMessage() . "</td></tr>";
        }
        ?>
    </table>
    
    <hr>
    <h2>📊 Summary</h2>
    <?php
    $allUpdated = true;
    foreach ($files as $file => $check) {
        if ($check['critical']) {
            $content = @file_get_contents(__DIR__ . '/' . $file);
            if (!$content || strpos($content, $check['search']) === false) {
                $allUpdated = false;
                break;
            }
        }
    }
    
    if ($allUpdated) {
        echo "<div style='background:#f0fdf4;border:2px solid #16a34a;padding:20px;border-radius:8px;text-align:center'>";
        echo "<h3 class='ok' style='margin:0'>✅ All Critical Files Updated!</h3>";
        echo "<p>System is ready. Advisory mode should work correctly now.</p>";
        echo "</div>";
    } else {
        echo "<div style='background:#fff7ed;border:2px solid #ea580c;padding:20px;border-radius:8px;text-align:center'>";
        echo "<h3 class='warning' style='margin:0'>⚠️ Update Required</h3>";
        echo "<p>Some critical files need to be updated. Upload the latest versions.</p>";
        echo "</div>";
    }
    ?>
    
    <hr>
    <p><strong>Next Steps:</strong></p>
    <ol>
        <li>If files show OLD VERSION: Upload updated files via FTP/cPanel</li>
        <li>After upload, hard refresh browser: <kbd>Ctrl + Shift + R</kbd></li>
        <li>Test upload again on IPCR form page</li>
        <li>Delete this file (version-check.php) after verification</li>
    </ol>
    
    <p><a href="faculty/ipcr-form.php">→ Go to IPCR Form</a> | <a href="admin/settings.php">→ Settings</a></p>
</body>
</html>

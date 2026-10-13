<?php
// Prevent any output before JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Start session first before checking
startSecureSession();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}

requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$formId = (int)($_POST['form_id'] ?? 0);
$siId   = (int)($_POST['si_id']   ?? 0);
$desc   = trim($_POST['description'] ?? '');
$skipAICheck = isset($_POST['skip_ai_check']) && $_POST['skip_ai_check'] === 'true';

if (!$formId || !$siId) {
    echo json_encode(['success'=>false,'message'=>'Missing form_id or si_id']); 
    exit;
}

try {
    $db = getDB();
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get form + period info (for semester classification)
$stmt = $db->prepare("
    SELECT f.*, p.name as period_name, p.academic_year, p.semester
    FROM ipcr_forms f
    JOIN rating_periods p ON f.period_id = p.id
    WHERE f.id = ?
");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) { echo json_encode(['success'=>false,'message'=>'Form not found']); exit; }

// Access check
if ($form['user_id'] != $currentUserId && !in_array($currentRole, ['admin','dean'])) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

if (!in_array($form['status'], ['draft','returned'])) {
    echo json_encode(['success'=>false,'message'=>'Form is not editable (status: '.$form['status'].')']); exit;
}

if (!isset($_FILES['evidence'])) {
    echo json_encode(['success'=>false,'message'=>'No file received']); 
    exit;
}

// Ensure uploads directory exists
$baseUploadDir = __DIR__ . '/../uploads/';
if (!is_dir($baseUploadDir)) {
    if (!@mkdir($baseUploadDir, 0755, true)) {
        echo json_encode(['success'=>false,'message'=>'Upload directory does not exist and could not be created']);
        exit;
    }
}

if (!is_writable($baseUploadDir)) {
    echo json_encode(['success'=>false,'message'=>'Upload directory is not writable']);
    exit;
}

$file = $_FILES['evidence'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [1=>'File too large',2=>'File too large',3=>'Partial upload',4=>'No file',6=>'No tmp dir',7=>'Write failed'];
    echo json_encode(['success'=>false,'message'=>$errors[$file['error']] ?? 'Upload error']); exit;
}

if ($file['size'] > MAX_FILE_SIZE) {
    echo json_encode(['success'=>false,'message'=>'File exceeds 10MB limit']); exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS)) {
    echo json_encode(['success'=>false,'message'=>'File type not allowed. Use: '.implode(', ', ALLOWED_EXTENSIONS)]); exit;
}

// ── Get SI info for AI context (with full MFO/KRA details) ────────────────────────────────
$siStmt = $db->prepare("
    SELECT 
        s.*,
        k.code as kra_code,
        k.title as kra_title,
        m.code as mfo_code,
        m.title as mfo_title,
        GROUP_CONCAT(r.label ORDER BY r.sort_order SEPARATOR ', ') as proof_labels
    FROM success_indicators s
    INNER JOIN kras k ON s.kra_id = k.id
    INNER JOIN mfos m ON k.mfo_id = m.id
    LEFT JOIN required_proof_types r ON r.si_id = s.id
    WHERE s.id = ? 
    GROUP BY s.id
");
$siStmt->execute([$siId]);
$si = $siStmt->fetch();

// ── AI Verification DURING Upload ──────────────────────────────────────────
$aiVerdict = 'pending';
$aiConfidence = 0;
$aiReason = '';
$aiDocType = '';
$aiSummary = '';
$aiSuggestions = '';

// Get AI verification mode setting
$aiModeResult = $db->query("SELECT config_value FROM system_config WHERE config_key='ai_verification_mode'")->fetchColumn();
$aiMode = $aiModeResult && trim($aiModeResult) !== '' ? trim($aiModeResult) : 'advisory';

// Debug log to see what mode is being used
error_log("AI Verification Mode: [{$aiMode}] (raw: [" . var_export($aiModeResult, true) . "])");

// Get API Key
$apiKey = trim($db->query("SELECT config_value FROM system_config WHERE config_key='gemini_api_key'")->fetchColumn());

// Only run AI check if mode is not 'disabled' AND we have an API key
if ($aiMode !== 'disabled' && $apiKey) {
    $isOpenRouter = strpos($apiKey, 'sk-or-') === 0;
    
    // Build AI prompt
    $periodContext = $form['period_name'] ?? '';
    $semContext = ($form['academic_year']??'') . ' ' . ($form['semester']??'');
    
    $textPrompt = "Verify evidence file for IPCR:\n\n";
    $textPrompt .= "File: {$file['name']}\n";
    $textPrompt .= "Period: {$periodContext} ({$semContext})\n";
    $textPrompt .= "For: {$si['code']} - {$si['title']}\n";
    $textPrompt .= "Expected Evidence: " . ($si['proof_labels'] ?: 'Any relevant document') . "\n\n";
    $textPrompt .= "VERIFICATION RULES:\n";
    $textPrompt .= "- If filename keywords MATCH requirements (e.g., 'Syllabus' in filename for 'Syllabus' requirement) = 85% confidence (VALID)\n";
    $textPrompt .= "- If filename is RELATED but not exact match = 60-75% confidence (UNCERTAIN - allow with warning)\n";
    $textPrompt .= "- If filename is COMPLETELY WRONG or unrelated = <40% confidence (INVALID - block upload)\n";
    $textPrompt .= "- Be REASONABLE: Give benefit of doubt if there's any logical connection\n";
    $textPrompt .= "- Only BLOCK (<50%) if truly wrong document type\n\n";
    $textPrompt .= 'Respond ONLY in JSON: {"verdict":"valid|uncertain|invalid","confidence":0-100,"document_type":"type","content_summary":"summary","reason":"explanation","suggestions":"recommendations"}';
    
    if ($isOpenRouter) {
        $url = "https://openrouter.ai/api/v1/chat/completions";
        $payload = [
            'model' => 'openrouter/auto',
            'messages' => [
                ['role' => 'system', 'content' => 'You verify evidence. Respond ONLY in JSON format.'],
                ['role' => 'user', 'content' => $textPrompt]
            ],
            'temperature' => 0.1,
            'max_tokens' => 512
        ];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost',
            'X-Title: IPCR Upload Verification'
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $text = $result['choices'][0]['message']['content'] ?? '';
            $text = preg_replace('/```json\s*/i', '', $text);
            $text = preg_replace('/```\s*/i', '', trim($text));
            $aiResult = json_decode($text, true);
            
            if ($aiResult && isset($aiResult['verdict'])) {
                $aiVerdict = $aiResult['verdict'];
                $aiConfidence = (int)($aiResult['confidence'] ?? 50);
                $aiReason = $aiResult['reason'] ?? '';
                $aiDocType = $aiResult['document_type'] ?? '';
                $aiSummary = $aiResult['content_summary'] ?? '';
                $aiSuggestions = $aiResult['suggestions'] ?? '';
                
                // ONLY BLOCK if mode is 'strict' AND (invalid OR confidence < 50%)
                if ($aiMode === 'strict' && ($aiVerdict === 'invalid' || $aiConfidence < 50)) {
                    // Log for debugging
                    error_log("AI BLOCKED upload (strict mode): {$file['name']} | Verdict: {$aiVerdict} | Confidence: {$aiConfidence}%");
                    
                    echo json_encode([
                        'success' => false,
                        'ai_blocked' => true,
                        'verdict' => $aiVerdict,
                        'confidence' => $aiConfidence,
                        'document_type' => $aiDocType,
                        'reason' => $aiReason ?: 'This file does not match the required evidence type.',
                        'suggestions' => $aiSuggestions,
                        'message' => 'Upload blocked by AI verification (strict mode)'
                    ]);
                    exit;
                } else {
                    // Log AI check result (advisory mode or passed strict check)
                    error_log("AI CHECK ({$aiMode} mode): {$file['name']} | Verdict: {$aiVerdict} | Confidence: {$aiConfidence}% | Upload ALLOWED");
                }
            }
        }
    }
}

// ── Direct File Save ──────────────────────────────────────────
$uploadDir = UPLOAD_DIR . $formId . DIRECTORY_SEPARATOR . $siId . DIRECTORY_SEPARATOR;
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success'=>false,'message'=>'Cannot create upload directory.']); 
        exit;
    }
}

$storedName = uniqid('ev_', true) . '.' . $ext;
$targetPath = $uploadDir . $storedName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success'=>false,'message'=>'Failed to save file. Check directory permissions.']); 
    exit;
}

// ── Save to database ──────────────────────────────────────────
// verify_status: if AI said valid, mark as verified; uncertain = pending
$dbVerifyStatus = ($aiVerdict === 'valid') ? 'verified' : 'pending';
$aiNote = $aiReason ? "[AI {$aiVerdict} {$aiConfidence}%]: {$aiReason}" : '';

$ins = $db->prepare("
    INSERT INTO evidence_files
        (form_id, si_id, original_name, stored_name, file_type, file_size, description, verify_status, uploaded_by)
    VALUES (?,?,?,?,?,?,?,?,?)
");
$ins->execute([
    $formId, $siId,
    $file['name'], $storedName,
    $file['type'], $file['size'],
    $aiNote ?: $desc,
    $dbVerifyStatus,
    $currentUserId
]);
$newId = $db->lastInsertId();

auditLog('evidence_added','ipcr_form',$formId,null,$file['name'],
    "Uploaded for SI {$siId} | Period: {$form['period_name']} | AI: {$aiVerdict}");

echo json_encode([
    'success'       => true,
    'ai_verdict'    => $aiVerdict,
    'ai_confidence' => $aiConfidence,
    'ai_reason'     => $aiReason,
    'document_type' => $aiDocType,
    'content_summary' => $aiSummary,
    'suggestions'   => $aiSuggestions,
    'file' => [
        'id'            => (int)$newId,
        'original_name' => $file['name'],
        'stored_name'   => $storedName,
        'file_size'     => (int)$file['size'],
        'uploaded_at'   => date('Y-m-d H:i:s'),
        'verify_status' => $dbVerifyStatus,
    ]
]);

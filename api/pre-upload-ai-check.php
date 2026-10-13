<?php
/**
 * Pre-Upload AI Evidence Verification
 * This endpoint checks the file BEFORE uploading to server
 * If confidence < 50%, upload is blocked
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

startSecureSession();

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

if (!$formId || !$siId) {
    echo json_encode(['success'=>false,'message'=>'Missing form_id or si_id']); 
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success'=>false,'message'=>'No file received']); 
    exit;
}

try {
    $db = getDB();
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Database connection failed']);
    exit;
}

// Get form info
$stmt = $db->prepare("
    SELECT f.*, p.name as period_name, p.academic_year, p.semester
    FROM ipcr_forms f
    JOIN rating_periods p ON f.period_id = p.id
    WHERE f.id = ?
");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) {
    echo json_encode(['success'=>false,'message'=>'Form not found']); 
    exit;
}

// Access check
if ($form['user_id'] != $currentUserId && !in_array($currentRole, ['admin','dean'])) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); 
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'File upload error']); 
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS)) {
    echo json_encode(['success'=>false,'message'=>'File type not allowed']); 
    exit;
}

if ($file['size'] > MAX_FILE_SIZE) {
    echo json_encode(['success'=>false,'message'=>'File exceeds 10MB limit']); 
    exit;
}

// ── Get SI info for AI context ────────────────────────────────
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

if (!$si) {
    echo json_encode(['success'=>false,'message'=>'Success indicator not found']); 
    exit;
}

// ── Get API Key ─────────────────────────────────────────────
$apiKey = trim($db->query("SELECT config_value FROM system_config WHERE config_key='gemini_api_key'")->fetchColumn());

if (!$apiKey) {
    // No API key - allow upload without AI check
    echo json_encode([
        'success'    => true,
        'verdict'    => 'pending',
        'confidence' => 0,
        'document_type' => 'Unknown',
        'reason'     => 'AI verification not configured',
        'suggestions'=> ''
    ]);
    exit;
}

$isGroq = strpos($apiKey, 'gsk_') === 0;
$isOpenRouter = strpos($apiKey, 'sk-or-') === 0;

// ── Save file to temp location for AI processing ───────────────
$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ai_precheck_') . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    echo json_encode(['success'=>false,'message'=>'Failed to process file']); 
    exit;
}

// ── Build AI Prompt ──────────────────────────────────────────
$periodContext = $form['period_name'] ?? '';
$semContext    = ($form['academic_year']??'') . ' ' . ($form['semester']??'');

$prompt = "You are verifying evidence for an IPCR (Individual Performance Commitment and Review) system at Isabela State University, Philippines.

CONTEXT - Faculty is uploading evidence for this specific performance indicator:

Rating Period: {$periodContext} ({$semContext})

MFO (Major Final Output):
- Code: {$si['mfo_code']}
- Title: {$si['mfo_title']}

KRA (Key Result Area):
- Code: {$si['kra_code']}
- Title: {$si['kra_title']}

SUCCESS INDICATOR:
- Code: {$si['code']}
- Title: {$si['title']}
- Quality Target: {$si['quality_target']}
- Efficiency Target: {$si['efficiency_target']}
- Timeliness Target: {$si['timeliness_target']}
- Expected Evidence Types: " . ($si['proof_labels'] ?: 'Any relevant supporting document') . "

UPLOADED FILE: {$file['name']}

INTELLIGENT VERIFICATION RULES:
1. MATCH KEYWORDS: If the filename contains keywords that match the expected evidence type, mark as VALID
   - Example: 'Syllabus' filename + 'Syllabus Copy' requirement = VALID (80%+)
   - Example: 'Teaching_Load' filename + 'Teaching Load Form' requirement = VALID (80%+)
   
2. SMART MATCHING: Check if filename or document type directly relates to the SI title
   - Clear keyword match = VALID with high confidence (80%+)
   - Semantic relationship = VALID with moderate confidence (60-79%)

3. Mark as INVALID (<50% confidence) only if:
   - Filename is completely unrelated to requirement
   - Document type contradicts the requirement
   - Clear mismatch between expected and uploaded type

4. BE HELPFUL - if there's reasonable keyword match, mark as VALID with appropriate confidence level.

CRITICAL: Your confidence score determines if upload proceeds:
- 50% or above = Upload allowed
- Below 50% = Upload BLOCKED

";

$imgExts = ['jpg','jpeg','png','gif','webp','bmp'];
$pdfExts = ['pdf'];

if (in_array($ext, $imgExts)) {
    $imgData  = base64_encode(file_get_contents($tmpPath));
    $mimeType = $file['type'] ?: 'image/jpeg';
    $prompt  .= "Examine this image carefully. Determine:
1. What type of document/content is shown?
2. Does it match the MFO/KRA/SI requirements?
3. Assign appropriate confidence score (50%+ to allow upload, <50% to block)

Respond in JSON ONLY:
{
  \"verdict\":\"valid|uncertain|invalid\",
  \"confidence\":0-100,
  \"document_type\":\"what type of document\",
  \"content_summary\":\"brief description\",
  \"reason\":\"detailed explanation\",
  \"suggestions\":\"what proper evidence should be uploaded if confidence < 50%\"
}";
    $payload = [
        'contents' => [['parts' => [
            ['text' => $prompt],
            ['inline_data' => ['mime_type'=>$mimeType,'data'=>$imgData]]
        ]]],
        'generationConfig' => ['temperature'=>0.1,'maxOutputTokens'=>300]
    ];
} elseif (in_array($ext, $pdfExts)) {
    $pdfData  = base64_encode(file_get_contents($tmpPath));
    $prompt  .= "Examine this PDF document carefully. Determine:
1. What type of document is this?
2. Does it match the MFO/KRA/SI requirements?
3. Assign appropriate confidence score (50%+ to allow upload, <50% to block)

Respond in JSON ONLY:
{
  \"verdict\":\"valid|uncertain|invalid\",
  \"confidence\":0-100,
  \"document_type\":\"what type of document\",
  \"content_summary\":\"brief description\",
  \"reason\":\"detailed explanation\",
  \"suggestions\":\"what proper evidence should be uploaded if confidence < 50%\"
}";
    $payload = [
        'contents' => [['parts' => [
            ['text' => $prompt],
            ['inline_data' => ['mime_type'=>'application/pdf','data'=>$pdfData]]
        ]]],
        'generationConfig' => ['temperature'=>0.1,'maxOutputTokens'=>300]
    ];
} else {
    // Word/Excel — filename-based only
    $prompt  .= "Only filename available ('{$file['name']}'). Based on filename and requirements:
- Does filename suggest relevance?
- Assign appropriate confidence score (50%+ to allow, <50% to block)

Respond in JSON ONLY:
{
  \"verdict\":\"uncertain\",
  \"confidence\":30-70,
  \"document_type\":\"Based on filename\",
  \"content_summary\":\"Cannot read content - Word/Excel file\",
  \"reason\":\"Filename-based analysis\",
  \"suggestions\":\"Convert to PDF for better verification\"
}";
    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature'=>0.1,'maxOutputTokens'=>200]
    ];
}

// ── Call AI API ─────────────────────────────────────────────
if ($isOpenRouter) {
    $textPrompt = "Verify evidence:\n\n";
    $textPrompt .= "File: {$file['name']}\n";
    $textPrompt .= "For: {$si['code']} - {$si['title']}\n";
    $textPrompt .= "Expected: " . ($si['proof_labels'] ?: 'Any relevant document') . "\n\n";
    $textPrompt .= "IMPORTANT: Confidence 50%+ = allow upload, <50% = block upload.\n";
    $textPrompt .= "If filename keywords match requirements = 80%+ confidence.\n\n";
    $textPrompt .= 'Respond ONLY in JSON: {"verdict":"valid|uncertain|invalid","confidence":0-100,"document_type":"type","content_summary":"summary","reason":"explanation","suggestions":"recommendations"}';
    
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
        'X-Title: IPCR Pre-Upload Check'
    ];
} elseif ($isGroq) {
    $textPrompt = "Filename: {$file['name']}\nFor: {$si['code']} - {$si['title']}\nExpected: " . ($si['proof_labels'] ?: 'Any') . "\n\n";
    $textPrompt .= "Confidence 50%+ allows upload, <50% blocks. Keyword match = 80%+.\n\n";
    $textPrompt .= 'Respond in JSON: {"verdict":"uncertain","confidence":50,"reason":"Analysis","suggestions":"Advice"}';
    
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        'model' => 'llama-3.1-8b-instant',
        'messages' => [
            ['role' => 'system', 'content' => 'Verify evidence. Respond ONLY in JSON.'],
            ['role' => 'user', 'content' => $textPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 200
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];
} else {
    // Gemini API
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
    $headers = [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ];
}

$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Clean up temp file
@unlink($tmpPath);

if ($httpCode !== 200) {
    // AI API error - allow upload but mark as pending
    echo json_encode([
        'success'    => true,
        'verdict'    => 'pending',
        'confidence' => 0,
        'document_type' => 'Unknown',
        'reason'     => 'AI check unavailable',
        'suggestions'=> ''
    ]);
    exit;
}

$result = json_decode($response, true);

// Extract text based on API type
if ($isOpenRouter || $isGroq) {
    $text = $result['choices'][0]['message']['content'] ?? '';
} else {
    $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

$text   = preg_replace('/```json\s*/i', '', $text);
$text   = preg_replace('/```\s*/i', '', trim($text));
$aiResult = json_decode($text, true);

if (!$aiResult || !isset($aiResult['verdict'])) {
    // Parsing failed - allow upload with pending status
    echo json_encode([
        'success'    => true,
        'verdict'    => 'pending',
        'confidence' => 0,
        'document_type' => 'Unknown',
        'reason'     => 'AI response parsing failed',
        'suggestions'=> ''
    ]);
    exit;
}

// ── Return AI Result ──────────────────────────────────────────
// Frontend will decide whether to proceed based on confidence score
echo json_encode([
    'success'        => true,
    'verdict'        => $aiResult['verdict'],
    'confidence'     => $aiResult['confidence'] ?? 50,
    'document_type'  => $aiResult['document_type'] ?? 'Unknown',
    'content_summary'=> $aiResult['content_summary'] ?? '',
    'reason'         => $aiResult['reason'] ?? '',
    'suggestions'    => $aiResult['suggestions'] ?? ''
]);

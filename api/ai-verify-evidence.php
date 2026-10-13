<?php
/**
 * AI Evidence Verification using Google Gemini API
 * Checks if uploaded file matches the expected evidence type for an SI
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$fileId = (int)($data['file_id'] ?? 0);
$siId   = (int)($data['si_id']   ?? 0);

if (!$fileId || !$siId) {
    echo json_encode(['success'=>false,'message'=>'Missing file_id or si_id']); exit;
}

// Get API key from config (supports both Gemini and Groq)
$db     = getDB();
$apiKey = trim($db->query("SELECT config_value FROM system_config WHERE config_key='gemini_api_key'")->fetchColumn());

if (!$apiKey) {
    echo json_encode(['success'=>false,'message'=>'AI verification not configured. Admin needs to add API key in Settings.']); exit;
}

// Detect API type
$isGroq = strpos($apiKey, 'gsk_') === 0;
$isHuggingFace = strpos($apiKey, 'hf_') === 0;
$isOpenRouter = strpos($apiKey, 'sk-or-') === 0;

// Get file info
$ev = $db->prepare("SELECT * FROM evidence_files WHERE id=?");
$ev->execute([$fileId]);
$ev = $ev->fetch();
if (!$ev) { echo json_encode(['success'=>false,'message'=>'File not found']); exit; }

// Get SI info with full context (MFO, KRA, targets)
$si = $db->prepare("
    SELECT 
        s.*,
        k.code as kra_code,
        k.title as kra_title,
        m.code as mfo_code,
        m.title as mfo_title,
        GROUP_CONCAT(r.label SEPARATOR ', ') as proof_labels
    FROM success_indicators s
    INNER JOIN kras k ON s.kra_id = k.id
    INNER JOIN mfos m ON k.mfo_id = m.id
    LEFT JOIN required_proof_types r ON r.si_id=s.id
    WHERE s.id=? 
    GROUP BY s.id
");
$si->execute([$siId]);
$si = $si->fetch();
if (!$si) { echo json_encode(['success'=>false,'message'=>'SI not found']); exit; }

// Build file path
$filePath = UPLOAD_DIR . $ev['form_id'] . DIRECTORY_SEPARATOR . $ev['si_id'] . DIRECTORY_SEPARATOR . $ev['stored_name'];
if (!file_exists($filePath)) {
    echo json_encode(['success'=>false,'message'=>'File not found on server']); exit;
}

$ext      = strtolower(pathinfo($ev['original_name'], PATHINFO_EXTENSION));
$imgExts  = ['jpg','jpeg','png','gif','webp','bmp'];
$pdfExt   = ['pdf'];

// Build prompt
$prompt = "You are an academic document verifier for an IPCR (Individual Performance Commitment and Review) system at Isabela State University.

CONTEXT - The faculty uploaded evidence for this specific performance indicator:

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
- Expected Evidence Types: {$si['proof_labels']}

UPLOADED FILE: {$ev['original_name']}

INTELLIGENT VERIFICATION RULES:
1. KEYWORD MATCHING: If filename contains keywords matching expected evidence, mark as VALID
   - 'Syllabus' in filename + 'Syllabus' in requirement = VALID (high confidence)
   - 'Teaching_Load' in filename + 'Teaching Load Form' in requirement = VALID
   - 'Schedule' in filename + 'Class Schedule' in requirement = VALID

2. SEMANTIC MATCHING: Check if filename semantically matches SI title and expected evidence
   - Strong keyword overlap between filename and requirement = VALID
   - Clear document type match = VALID with high confidence

3. Mark as INVALID only when:
   - Filename is completely unrelated (e.g., 'random_file.pdf' for specific evidence)
   - Clear contradiction (e.g., 'Syllabus' for 'Teaching Load Form' requirement)
   - Wrong document category entirely

4. Mark as UNCERTAIN only when:
   - Vague/generic filename without clear keywords
   - Partial match needing human review
   - Cannot determine from filename alone

BE INTELLIGENT - reasonable keyword match should result in VALID with high confidence (80%+).

";

if (in_array($ext, $imgExts)) {
    $prompt .= "Please examine this image carefully and determine:
1. What type of document/content is shown in this image?
2. Does the filename or content match the expected evidence keywords?
3. Strong keyword match = VALID with high confidence (80%+)

BE SMART in your assessment. If filename clearly matches expected evidence type, mark as VALID.

Respond in JSON format ONLY:
{
  \"verdict\": \"valid|uncertain|invalid\",
  \"confidence\": 0-100,
  \"document_type\": \"what type of document is this\",
  \"content_summary\": \"brief description of what you see in the document\",
  \"relevance_check\": \"does the content match the MFO/KRA/SI requirements? explain\",
  \"reason\": \"detailed explanation of your verdict\",
  \"suggestions\": \"what to improve or what proper evidence should be submitted\"
}";

    // Encode image to base64
    $imageData    = base64_encode(file_get_contents($filePath));
    $mimeType     = $ev['file_type'] ?: 'image/jpeg';

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => $imageData,
                    ]
                ]
            ]
        ]],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 512,
        ]
    ];
    $model = 'gemini-1.5-flash';

} elseif (in_array($ext, $pdfExt)) {
    // For PDFs, encode as base64
    $pdfData = base64_encode(file_get_contents($filePath));

    $prompt .= "Please examine this PDF document and determine:
1. What type of document is this based on filename and content?
2. Does the filename keywords match the expected evidence type?
3. Strong keyword match = VALID with high confidence

BE SMART - if 'Syllabus' filename matches 'Syllabus' requirement, mark as VALID (80%+ confidence).

Respond in JSON format ONLY:
{
  \"verdict\": \"valid|uncertain|invalid\",
  \"confidence\": 0-100,
  \"document_type\": \"what type of document is this\",
  \"content_summary\": \"brief description of the document content\",
  \"relevance_check\": \"does the content match the MFO/KRA/SI requirements? explain\",
  \"reason\": \"detailed explanation of your verdict\",
  \"suggestions\": \"what to improve or what proper evidence should be submitted\"
}";

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data'      => $pdfData,
                    ]
                ]
            ]
        ]],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 512,
        ]
    ];
    $model = 'gemini-1.5-flash';

} else {
    // For Word/Excel — use filename-based analysis only
    $prompt .= "Only the filename is available ('{$ev['original_name']}'). Based on the filename and the MFO/KRA/SI requirements above, give your best assessment.

Note: Without reading the actual content, you can only make an uncertain assessment. Consider:
- Does the filename suggest relevance to the MFO/KRA/SI?
- Does it match the expected evidence type?

Respond in JSON format ONLY:
{
  \"verdict\": \"uncertain\",
  \"confidence\": 30-50,
  \"document_type\": \"Based on filename: [your guess]\",
  \"content_summary\": \"Cannot read content - Word/Excel file\",
  \"relevance_check\": \"Filename-based analysis only. User should upload as PDF for proper verification.\",
  \"reason\": \"Cannot verify content relevance without reading the file. Filename suggests: [your analysis]\",
  \"suggestions\": \"Please convert to PDF and re-upload for proper AI verification of content relevance\"
}";

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 256],
    ];
    $model = 'gemini-1.5-flash';
}

// Call AI API (OpenRouter, Hugging Face, Groq, or Gemini)
if ($isOpenRouter) {
    // OpenRouter: Use free models with OpenAI-compatible API
    // For images/PDFs, we'll use text-based analysis (most free models don't support vision)
    $textPrompt = "You are an academic document verifier for an IPCR system at Isabela State University.\n\n";
    $textPrompt .= "CONTEXT:\n";
    $textPrompt .= "MFO: {$si['mfo_code']} - {$si['mfo_title']}\n";
    $textPrompt .= "KRA: {$si['kra_code']} - {$si['kra_title']}\n";
    $textPrompt .= "Success Indicator: {$si['code']} - {$si['title']}\n";
    $textPrompt .= "Quality Target: {$si['quality_target']}\n";
    $textPrompt .= "Efficiency Target: {$si['efficiency_target']}\n";
    $textPrompt .= "Timeliness Target: {$si['timeliness_target']}\n";
    $textPrompt .= "Expected Evidence: {$si['proof_labels']}\n\n";
    $textPrompt .= "UPLOADED FILE: {$ev['original_name']}\n\n";
    $textPrompt .= "SMART VERIFICATION:\n";
    $textPrompt .= "- If filename keywords match expected evidence = VALID (80%+ confidence)\n";
    $textPrompt .= "- Example: 'Syllabus' in filename + 'Syllabus' in requirement = VALID\n";
    $textPrompt .= "- Only mark INVALID if clearly unrelated or wrong document type\n\n";
    $textPrompt .= "Respond ONLY in this exact JSON format:\n";
    $textPrompt .= '{"verdict":"valid|uncertain|invalid","confidence":0-100,"document_type":"type","content_summary":"summary","relevance_check":"does it match?","reason":"explanation","suggestions":"recommendations"}';
    
    $url = "https://openrouter.ai/api/v1/chat/completions";
    $payload = [
        'model' => 'openrouter/auto', // Auto-router picks best available free model
        'messages' => [
            ['role' => 'system', 'content' => 'You are an evidence verification assistant. Analyze if uploaded files match described performance indicators. Respond ONLY in valid JSON format.'],
            ['role' => 'user', 'content' => $textPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 512
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: http://localhost', // Required by OpenRouter
        'X-Title: IPCR Evidence Verification' // Optional but recommended
    ];
} elseif ($isHuggingFace) {
    // Hugging Face: Use Mistral model (FREE & STABLE!)
    $textPrompt = "Analyze this evidence:\n";
    $textPrompt .= "Filename: {$ev['original_name']}\n";
    $textPrompt .= "For: {$si['success_indicator']}\n";
    $textPrompt .= "Target: {$si['target_output']}\n";
    $textPrompt .= "Expected: {$si['evidence_type']}\n\n";
    $textPrompt .= "Respond in JSON: {\"verdict\":\"valid|uncertain|invalid\",\"confidence\":0-100,\"reason\":\"explanation\"}";
    
    $url = "https://api-inference.huggingface.co/models/mistralai/Mistral-7B-Instruct-v0.2";
    $payload = [
        'inputs' => $textPrompt,
        'parameters' => [
            'max_new_tokens' => 300,
            'temperature' => 0.1,
            'return_full_text' => false
        ]
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];
} elseif ($isGroq) {
    // Groq doesn't support image/PDF input - use text-only verification
    if (in_array($ext, array_merge($imgExt, $pdfExt))) {
        // For files, provide filename-based analysis
        $textPrompt = "Based on this filename '{$ev['original_name']}' for evidence of:\n\n";
        $textPrompt .= "MFO: {$si['mfo_name']}\n";
        $textPrompt .= "KRA: {$si['kra_name']}\n";
        $textPrompt .= "Success Indicator: {$si['success_indicator']}\n";
        $textPrompt .= "Target: {$si['target_output']}\n\n";
        $textPrompt .= "Expected evidence type: {$si['evidence_type']}\n\n";
        $textPrompt .= "Assess if the filename suggests appropriate evidence. Respond in JSON:\n";
        $textPrompt .= '{"verdict":"uncertain","confidence":40,"document_type":"[guess]","reason":"[analysis]","suggestions":"Upload PDF for full verification"}';
    } else {
        $textPrompt = $prompt; // Use the prepared prompt
    }
    
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        'model' => 'llama-3.1-8b-instant',
        'messages' => [
            ['role' => 'system', 'content' => 'You are an evidence verification assistant. Analyze if uploaded files match the described performance indicators. Respond ONLY in valid JSON format.'],
            ['role' => 'user', 'content' => $textPrompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 512
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];
} else {
    // Gemini API
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent";
    // $payload already prepared above
    $headers = [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey
    ];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success'=>false,'message'=>"Connection error: $curlError"]); exit;
}

if (!$response || $httpCode !== 200) {
    $err = json_decode($response, true);
    $apiType = $isOpenRouter ? 'OpenRouter' : ($isHuggingFace ? 'Hugging Face' : ($isGroq ? 'Groq' : 'Gemini'));
    $msg = "AI API error ($apiType, HTTP $httpCode): ";
    $msg .= $err['error']['message'] ?? $err['error'] ?? substr($response, 0, 200);
    echo json_encode(['success'=>false,'message'=>$msg]); exit;
}

$result = json_decode($response, true);

// Extract text based on API type
if ($isOpenRouter || $isGroq) {
    $text = $result['choices'][0]['message']['content'] ?? '';
} elseif ($isHuggingFace) {
    // Hugging Face returns array with generated_text
    $text = is_array($result) && isset($result[0]['generated_text']) ? $result[0]['generated_text'] : '';
} else {
    $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

// Extract JSON from response (Gemini sometimes wraps in markdown)
$text = preg_replace('/```json\s*/i', '', $text);
$text = preg_replace('/```\s*/i', '', $text);
$text = trim($text);

$aiResult = json_decode($text, true);

if (!$aiResult || !isset($aiResult['verdict'])) {
    // Fallback if JSON parsing fails
    $aiResult = [
        'verdict'         => 'uncertain',
        'confidence'      => 50,
        'document_type'   => 'Unknown',
        'content_summary' => 'AI could not parse response',
        'relevance_check' => 'Verification failed',
        'reason'          => 'AI could not determine document relevance.',
        'suggestions'     => 'Please verify manually or try uploading again.',
    ];
}

// Store AI result in DB with more detailed information
$aiSummary = json_encode([
    'verdict'   => $aiResult['verdict'],
    'confidence'=> $aiResult['confidence'] ?? 50,
    'type'      => $aiResult['document_type'] ?? 'Unknown',
    'relevance' => $aiResult['relevance_check'] ?? '',
], JSON_UNESCAPED_UNICODE);

$db->prepare("UPDATE evidence_files SET description = CONCAT(IFNULL(description,''), ' [AI: ', ?, ']') WHERE id=?")
   ->execute([$aiSummary, $fileId]);

auditLog('ai_verification','evidence',$fileId,null,$aiResult['verdict'],"AI verified: {$aiResult['reason']}");

echo json_encode([
    'success'        => true,
    'verdict'        => $aiResult['verdict'],       // valid | uncertain | invalid
    'confidence'     => $aiResult['confidence'] ?? 50,
    'document_type'  => $aiResult['document_type'] ?? '',
    'content_summary'=> $aiResult['content_summary'] ?? '',
    'relevance_check'=> $aiResult['relevance_check'] ?? '',
    'reason'         => $aiResult['reason'] ?? '',
    'suggestions'    => $aiResult['suggestions'] ?? '',
]);

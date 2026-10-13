<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];

header('Content-Type: application/json');

$siId   = (int)($_GET['si_id']   ?? 0);
$formId = (int)($_GET['form_id'] ?? 0);

if (!$siId || !$formId) {
    echo json_encode(['success'=>false,'message'=>'Missing params']); exit;
}

$db   = getDB();
$rows = $db->prepare("SELECT * FROM evidence_files WHERE form_id=? AND si_id=? ORDER BY uploaded_at ASC");
$rows->execute([$formId, $siId]);
$files = $rows->fetchAll();

$extIconMap = [
    'pdf'  => ['bi-file-earmark-pdf-fill',  'ev-file-pdf'],
    'doc'  => ['bi-file-earmark-word-fill',  'ev-file-doc'],
    'docx' => ['bi-file-earmark-word-fill',  'ev-file-doc'],
    'xls'  => ['bi-file-earmark-excel-fill', 'ev-file-xls'],
    'xlsx' => ['bi-file-earmark-excel-fill', 'ev-file-xls'],
    'jpg'  => ['bi-file-earmark-image-fill', 'ev-file-img'],
    'jpeg' => ['bi-file-earmark-image-fill', 'ev-file-img'],
    'png'  => ['bi-file-earmark-image-fill', 'ev-file-img'],
    'gif'  => ['bi-file-earmark-image-fill', 'ev-file-img'],
    'webp' => ['bi-file-earmark-image-fill', 'ev-file-img'],
];

$baseUrl = defined('BASE_URL') ? BASE_URL : '';

$html = '';
if (empty($files)) {
    $html = '<div class="empty-ev"><i class="bi bi-folder2-open"></i>No files attached yet</div>';
} else {
    foreach ($files as $ev) {
        $ext  = strtolower(pathinfo($ev['original_name'], PATHINFO_EXTENSION));
        $ico  = $extIconMap[$ext][0] ?? 'bi-file-earmark-fill';
        $cls  = $extIconMap[$ext][1] ?? 'ev-file-other';
        $size = $ev['file_size'] ? round($ev['file_size']/1024, 1).'KB' : '';
        $date = date('M d, Y', strtotime($ev['uploaded_at']));
        $name = htmlspecialchars($ev['original_name'], ENT_QUOTES);
        $id   = $ev['id'];

        $html .= <<<HTML
<div class="ev-file-row" id="ev-item-{$id}">
  <div class="ev-ico-sm {$cls}"><i class="bi {$ico}"></i></div>
  <div class="ev-info-sm">
    <div class="ev-fname" title="{$name}">{$name}</div>
    <div class="ev-fmeta">{$size} · {$date}</div>
  </div>
  <div class="ev-actions" style="display:flex;gap:5px;flex-shrink:0;align-items:center">
    <button onclick="previewFile({$id}, '{$name}', '')" class="btn-dl">
      <i class="bi bi-eye"></i> View
    </button>
    <button class="ai-verify-btn btn-dl" style="border-color:#7c3aed;color:#7c3aed"
      data-file-id="{$id}" data-si-id="{$siId}" data-filename="{$name}">
      <i class="bi bi-stars"></i> AI
    </button>
    <button class="btn-rm" onclick="removeEvidence({$id}, '{$name}')" title="Remove">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
</div>
HTML;
    }
}

echo json_encode(['success'=>true, 'html'=>$html, 'count'=>count($files)]);

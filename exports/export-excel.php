<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUserId = $_SESSION['user_id'];
$currentRole   = $_SESSION['role'];
$currentUser   = getCurrentUser();

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) { http_response_code(404); exit('Form not found'); }

$db   = getDB();
$form = $db->prepare("SELECT * FROM ipcr_forms WHERE id=?");
$form->execute([$formId]);
$form = $form->fetch();
if (!$form) { http_response_code(404); exit('Form not found'); }

// Access check
$isOwner = $form['user_id'] == $currentUserId;
if (!$isOwner && $currentRole === 'dean') {
    $fac = $db->prepare("SELECT assigned_dean_id FROM users WHERE id=?");
    $fac->execute([$form['user_id']]);
    $fac = $fac->fetch();
    if (!$fac || $fac['assigned_dean_id'] != $currentUserId) { http_response_code(403); exit('Access denied'); }
} elseif (!$isOwner && $currentRole !== 'admin') {
    http_response_code(403); exit('Access denied');
}

$user    = $db->prepare("SELECT * FROM users WHERE id=?"); $user->execute([$form['user_id']]); $user = $user->fetch();
$period  = $db->prepare("SELECT * FROM rating_periods WHERE id=?"); $period->execute([$form['period_id']]); $period = $period->fetch();
$instName = $db->query("SELECT config_value FROM system_config WHERE config_key='institution_name'")->fetchColumn() ?: '';
$qW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_q_weight'")->fetchColumn() ?: 40);
$eW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_e_weight'")->fetchColumn() ?: 40);
$tW = (int)($db->query("SELECT config_value FROM system_config WHERE config_key='qet_t_weight'")->fetchColumn() ?: 20);

$rows = $db->prepare("
    SELECT m.code as mfo_code, m.title as mfo_title, m.weight as mfo_weight,
           k.code as kra_code, k.title as kra_title,
           s.code as si_code, s.title as si_title, s.quality_target, s.si_weight,
           e.planned_magnitude, e.planned_budget, e.planned_date,
           e.actual_magnitude, e.actual_budget, e.actual_date, e.actual_output,
           e.q_rating, e.e_rating, e.t_rating, e.average_rating, e.dean_remarks
    FROM mfos m JOIN kras k ON k.mfo_id=m.id JOIN success_indicators s ON s.kra_id=k.id
    LEFT JOIN ipcr_si_entries e ON e.si_id=s.id AND e.form_id=?
    WHERE m.template_id=? ORDER BY m.sort_order, k.sort_order, s.sort_order
");
$rows->execute([$formId, $form['template_id']]);
$allRows = $rows->fetchAll();

auditLog('ipcr_downloaded','ipcr_form',$formId,null,'excel','IPCR exported to Excel/CSV');

$filename = 'IPCR_' . preg_replace('/[^A-Za-z0-9_]/', '_', $user['employee_id']) . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

fputcsv($out, ['INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW (IPCR)']);
fputcsv($out, [$instName]);
fputcsv($out, []);
fputcsv($out, ['Name:', fullName($user), 'Employee ID:', $user['employee_id']]);
fputcsv($out, ['Position:', $user['position']??'', 'Department:', $user['department']??'']);
fputcsv($out, ['Period:', $period['name']??'', 'Status:', $form['status']]);
fputcsv($out, []);
fputcsv($out, ['MFO/KRA','Code','Success Indicator','Target',
               'Planned Magnitude','Planned Budget','Planned Date',
               'Actual Magnitude','Actual Budget','Actual Date',
               "Q Rating ({$qW}%)","E Rating ({$eW}%)","T Rating ({$tW}%)",
               'Average Rating','SI Weight (%)','Weighted Rating','Dean Remarks']);

$lastMFO = null; $lastKRA = null;
foreach ($allRows as $r) {
    if ($r['mfo_code'] !== $lastMFO) {
        fputcsv($out, [$r['mfo_code'].': '.$r['mfo_title'].' (Weight: '.$r['mfo_weight'].'%)']);
        $lastMFO = $r['mfo_code'];
    }
    $weighted = ($r['average_rating'] !== null && $r['si_weight'] > 0)
        ? round($r['average_rating'] * $r['si_weight'] / 100, 4) : '';
    fputcsv($out, [
        $r['mfo_code'].' → '.$r['kra_code'],
        $r['si_code'], $r['si_title'], $r['quality_target']??'',
        $r['planned_magnitude']??'', $r['planned_budget']??'', $r['planned_date']??'',
        $r['actual_magnitude']??'', $r['actual_budget']??'', $r['actual_date']??'',
        $r['q_rating']??'', $r['e_rating']??'', $r['t_rating']??'',
        $r['average_rating'] !== null ? number_format($r['average_rating'], 2) : '',
        $r['si_weight']??'', $weighted,
        $r['dean_remarks']??'',
    ]);
}

fputcsv($out, []);
fputcsv($out, ['Total Weighted Rating:', $form['composite_rating'] ? number_format($form['composite_rating'], 2) : '']);
fputcsv($out, ['Final Adjectival Rating:', $form['adjectival_rating']??'']);
fputcsv($out, ["Rater's Comments:", $form['dean_remarks']??'']);
fputcsv($out, ['Admin Remarks:', $form['admin_remarks']??'']);
fclose($out);

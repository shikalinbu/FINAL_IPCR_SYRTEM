<?php
$pageTitle  = 'Audit Log';
$activePage = 'audit';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole('admin','secretary','dean');

$db = getDB();

// Pagination
$recordsPerPage = 50;
$currentPage = (int)($_GET['page'] ?? 1);

$filterAction = trim($_GET['action'] ?? '');
$filterEntity = trim($_GET['entity'] ?? '');
$filterUser   = trim($_GET['user']   ?? '');
$filterDate   = trim($_GET['date']   ?? '');

$where = "1=1";
if ($filterAction) $where .= " AND a.action=" . $db->quote($filterAction);
if ($filterEntity) $where .= " AND a.entity_type=" . $db->quote($filterEntity);
if ($filterUser)   $where .= " AND (u.first_name LIKE " . $db->quote('%'.$filterUser.'%') . " OR u.last_name LIKE " . $db->quote('%'.$filterUser.'%') . ")";
if ($filterDate)   $where .= " AND DATE(a.performed_at)=" . $db->quote($filterDate);

// Count total records
$totalRecords = (int)$db->query("
    SELECT COUNT(*) FROM audit_log a
    LEFT JOIN users u ON a.performed_by=u.id
    WHERE {$where}
")->fetchColumn();

$pagination = calculatePagination($totalRecords, $recordsPerPage, $currentPage);

$log = $db->query("
    SELECT a.*, CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) as actor_name
    FROM audit_log a
    LEFT JOIN users u ON a.performed_by=u.id
    WHERE {$where}
    ORDER BY a.performed_at DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
")->fetchAll();

$actions  = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $db->query("SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-journal-text me-2"></i>Audit Log</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">View system activity history and user actions for security monitoring</p>
  </div>
</div>

<form method="GET" class="filter-bar mb-4">
  <div class="flex-grow-1" style="max-width:200px">
    <input type="text" name="user" class="form-control" placeholder="Search actor..." value="<?= h($filterUser) ?>">
  </div>
  <select name="action" class="form-select" style="min-width:160px">
    <option value="">All Actions</option>
    <?php foreach($actions as $a): ?><option value="<?= h($a) ?>" <?= $filterAction===$a?'selected':'' ?>><?= h($a) ?></option><?php endforeach; ?>
  </select>
  <select name="entity" class="form-select" style="min-width:150px">
    <option value="">All Entities</option>
    <?php foreach($entities as $e): ?><option value="<?= h($e) ?>" <?= $filterEntity===$e?'selected':'' ?>><?= h($e) ?></option><?php endforeach; ?>
  </select>
  <input type="date" name="date" class="form-control" style="max-width:160px" value="<?= h($filterDate) ?>">
  <button type="submit" class="btn btn-primary">Filter</button>
  <a href="?" class="btn btn-outline-secondary">Reset</a>
</form>

<div class="table-container">
  <table class="ipcr-table">
    <thead><tr><th>Date/Time</th><th>Actor</th><th>Action</th><th>Entity Type</th><th>Entity ID</th><th>Old Value</th><th>New Value</th><th>Remarks</th></tr></thead>
    <tbody>
      <?php if(!$log): ?><tr><td colspan="8" class="text-center text-muted py-4">No audit entries found.</td></tr><?php endif; ?>
      <?php foreach($log as $e): ?>
      <tr>
        <td class="text-muted-sm"><?= h(date('M d, Y H:i:s', strtotime($e['performed_at']))) ?></td>
        <td><strong><?= h(trim($e['actor_name']) ?: 'System') ?></strong></td>
        <td><code><?= h($e['action']) ?></code></td>
        <td><code><?= h($e['entity_type']) ?></code></td>
        <td class="text-muted-sm"><?= h($e['entity_id']) ?></td>
        <td class="text-muted-sm" style="max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= h(mb_substr($e['old_value']??'',0,60)) ?></td>
        <td class="text-muted-sm" style="max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= h(mb_substr($e['new_value']??'',0,60)) ?></td>
        <td><?= h(mb_substr($e['remarks']??'',0,80)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  
  <?php if ($pagination && $pagination['total_pages'] > 1): ?>
    <?= renderPagination($pagination) ?>
  <?php endif; ?>
  
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

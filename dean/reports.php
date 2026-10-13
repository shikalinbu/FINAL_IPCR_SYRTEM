<?php
$pageTitle  = 'Dean Reports';
$activePage = 'dean-reports';
require_once __DIR__ . '/../includes/header.php';
requireRole('dean', 'admin');

$db = getDB();

// Faculty under this dean
$facRows = $db->prepare("SELECT id FROM users WHERE assigned_dean_id=? AND is_active=1");
$facRows->execute([$currentUserId]);
$facIds = array_column($facRows->fetchAll(), 'id');

$periods = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();
$filterPeriod = (int)($_GET['period'] ?? ($db->query("SELECT id FROM rating_periods WHERE status IN ('open','rating','closed') LIMIT 1")->fetchColumn() ?: 0));

$forms = [];
if ($facIds) {
    $inList = implode(',', array_map('intval', $facIds));
    $pidWhere = $filterPeriod ? "AND f.period_id={$filterPeriod}" : '';
    $forms = $db->query("
        SELECT f.*,
               CONCAT(u.first_name,' ',u.last_name) as faculty_name,
               u.employee_id, u.department, u.position,
               p.name as period_name
        FROM ipcr_forms f
        JOIN users u ON f.user_id=u.id
        JOIN rating_periods p ON f.period_id=p.id
        WHERE f.user_id IN ({$inList})
          AND f.status IN ('reviewed','approved','finalized')
          {$pidWhere}
        ORDER BY f.composite_rating DESC
    ")->fetchAll();
}

$total     = count($forms);
$dist      = [];
$avgRating = 0;
foreach ($forms as $f) {
    if ($f['adjectival_rating']) $dist[$f['adjectival_rating']] = ($dist[$f['adjectival_rating']] ?? 0) + 1;
    if ($f['composite_rating'])  $avgRating += (float)$f['composite_rating'];
}
$avgRating = $total > 0 ? round($avgRating / $total, 3) : 0;
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-bar-chart me-2"></i>Faculty Performance Reports</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">View performance analytics and reports for faculty under your supervision</p>
  </div>
  <button onclick="window.print()" class="btn btn-outline-primary no-print">
    <i class="bi bi-printer me-2"></i>Print Report
  </button>
</div>

<form method="GET" class="filter-bar no-print mb-4">
  <div>
    <label class="form-label small">Period</label>
    <select name="period" class="form-select" style="min-width:250px" onchange="this.form.submit()">
      <option value="">All Periods</option>
      <?php foreach ($periods as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $filterPeriod == $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-primary mx-auto"><i class="bi bi-people"></i></div>
      <div class="metric-value"><?= $total ?></div>
      <div class="metric-label">Evaluated</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-calculator"></i></div>
      <div class="metric-value"><?= $avgRating > 0 ? number_format($avgRating, 2) : '—' ?></div>
      <div class="metric-label">Average Rating</div>
    </div>
  </div>
  <?php foreach ($dist as $adj => $cnt): ?>
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-value"><?= $cnt ?></div>
      <div class="metric-label"><?= h($adj) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Table -->
<div class="table-container">
  <table class="ipcr-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Faculty</th>
        <th>Department</th>
        <th>Period</th>
        <th>Status</th>
        <th>Composite Rating</th>
        <th>Adjectival Rating</th>
        <th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$forms): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">No evaluated IPCRs found for this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($forms as $i => $f): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td>
          <strong><?= h($f['faculty_name']) ?></strong>
          <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
        </td>
        <td><?= h($f['department']) ?></td>
        <td><?= h($f['period_name']) ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td class="text-center">
          <strong class="text-primary"><?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?></strong>
        </td>
        <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
        <td class="no-print" style="white-space:nowrap">
          <div class="d-flex align-items-center gap-1">
            <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-eye"></i> View
            </a>
            <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-printer"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

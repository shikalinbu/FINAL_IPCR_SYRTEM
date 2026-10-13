<?php
$pageTitle  = 'Review Queue';
$activePage = 'review-queue';
require_once __DIR__ . '/../includes/header.php';
requireRole('dean','admin');

$db = getDB();

// Build faculty IDs this dean can review
if ($currentRole === 'dean') {
    $facRows = $db->prepare("SELECT id FROM users WHERE assigned_dean_id=? AND is_active=1");
    $facRows->execute([$currentUserId]);
    $facIds = array_column($facRows->fetchAll(), 'id');
} else {
    $facIds = array_column($db->query("SELECT id FROM users WHERE role IN ('faculty','dean') AND is_active=1")->fetchAll(), 'id');
}

$periods = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();
$activePeriod = $db->query("SELECT * FROM rating_periods WHERE status IN ('open','rating') LIMIT 1")->fetch();
$isOverdue = $activePeriod && $activePeriod['review_deadline'] && strtotime($activePeriod['review_deadline']) < time();

// Filters
$filterName   = trim($_GET['name']   ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterPeriod = (int)($_GET['period'] ?? ($activePeriod['id'] ?? 0));

$forms = [];
if ($facIds) {
    $inList = implode(',', array_map('intval', $facIds));
    $where  = "f.user_id IN ({$inList})";
    if ($filterPeriod) $where .= " AND f.period_id={$filterPeriod}";
    if ($filterStatus) $where .= " AND f.status=".getDB()->quote($filterStatus);
    if ($filterName)   $where .= " AND (u.first_name LIKE ".getDB()->quote('%'.$filterName.'%')." OR u.last_name LIKE ".getDB()->quote('%'.$filterName.'%')." OR u.employee_id LIKE ".getDB()->quote('%'.$filterName.'%').")";

    $forms = $db->query("
        SELECT f.*,
               CONCAT(u.first_name,' ',u.last_name) as faculty_name,
               u.employee_id, u.department, u.position,
               p.name as period_name, p.review_deadline,
               (SELECT COUNT(*) FROM ipcr_si_entries e2 WHERE e2.form_id=f.id AND e2.q_rating IS NOT NULL AND e2.e_rating IS NOT NULL AND e2.t_rating IS NOT NULL) as rated_count,
               (SELECT COUNT(*) FROM success_indicators si2 JOIN kras k2 ON si2.kra_id=k2.id JOIN mfos m2 ON k2.mfo_id=m2.id WHERE m2.template_id=f.template_id AND si2.is_required=1) as total_si,
               (SELECT COUNT(*) FROM evidence_files ef WHERE ef.form_id=f.id) as ev_count
        FROM ipcr_forms f
        JOIN users u ON f.user_id=u.id
        JOIN rating_periods p ON f.period_id=p.id
        WHERE {$where}
        ORDER BY FIELD(f.status,'submitted','under_review','returned','reviewed','approved','finalized','draft'), f.updated_at DESC
    ")->fetchAll();
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-card-checklist me-2"></i>Review Queue</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Review and evaluate submitted IPCR forms from your faculty members</p>
  </div>
</div>

<?php
$pending  = count(array_filter($forms, fn($f)=>$f['status']==='submitted'));
$inReview = count(array_filter($forms, fn($f)=>$f['status']==='under_review'));
$reviewed = count(array_filter($forms, fn($f)=>in_array($f['status'],['reviewed','approved','finalized'])));
?>
<div class="row g-3 mb-4">
  <div class="col-4"><div class="metric-card text-center">
    <div class="metric-icon metric-icon-warning mx-auto"><i class="bi bi-hourglass-split"></i></div>
    <div class="metric-value"><?= $pending ?></div><div class="metric-label">Pending</div>
  </div></div>
  <div class="col-4"><div class="metric-card text-center">
    <div class="metric-icon metric-icon-info mx-auto"><i class="bi bi-eye"></i></div>
    <div class="metric-value"><?= $inReview ?></div><div class="metric-label">In Progress</div>
  </div></div>
  <div class="col-4"><div class="metric-card text-center">
    <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-check2-all"></i></div>
    <div class="metric-value"><?= $reviewed ?></div><div class="metric-label">Reviewed</div>
  </div></div>
</div>

<?php if($isOverdue): ?>
<div class="alert alert-warning mb-4">
  <i class="bi bi-exclamation-triangle me-2"></i>
  Review deadline has passed (<?= h(date('F d, Y', strtotime($activePeriod['review_deadline']))) ?>). Highlighted rows are overdue.
</div>
<?php endif; ?>

<form method="GET" class="filter-bar mb-4">
  <div class="flex-grow-1" style="max-width:280px">
    <label class="form-label small">Search Faculty</label>
    <input type="text" name="name" class="form-control" placeholder="Name or Employee ID..." value="<?= h($filterName) ?>">
  </div>
  <div>
    <label class="form-label small">Status</label>
    <select name="status" class="form-select" style="min-width:160px">
      <option value="">All Statuses</option>
      <?php foreach(['submitted','under_review','reviewed','returned','approved','finalized'] as $s): ?>
      <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$s))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label small">Period</label>
    <select name="period" class="form-select" style="min-width:200px">
      <option value="">All Periods</option>
      <?php foreach($periods as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $filterPeriod==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="d-flex gap-2 align-items-end">
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="?" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<div class="table-container">
  <table class="ipcr-table">
    <thead><tr>
      <th>Faculty</th><th>Department</th><th>Period</th><th>Status</th>
      <th>Progress</th><th>Evidence</th><th>Submitted</th><th>Rating</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php if(!$forms): ?>
      <tr><td colspan="9" class="text-center text-muted py-4">No IPCR forms found.</td></tr>
      <?php endif; ?>
      <?php foreach($forms as $f):
        $overdue = $isOverdue && $f['status']==='submitted';
      ?>
      <tr class="<?= $overdue ? 'overdue-row' : '' ?>">
        <td>
          <strong><?= h($f['faculty_name']) ?></strong>
          <?= $f['is_late'] ? '<span class="late-badge">Late</span>' : '' ?>
          <?= $overdue ? '<span class="late-badge">Overdue</span>' : '' ?>
          <div class="text-muted-sm"><?= h($f['employee_id']) ?></div>
        </td>
        <td><?= h($f['department']) ?></td>
        <td><?= h($f['period_name']) ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td>
          <div class="text-muted-sm"><?= $f['rated_count'] ?>/<?= $f['total_si'] ?> rated</div>
          <?php if($f['total_si']>0): ?>
          <div class="progress mt-1" style="height:5px">
            <div class="progress-bar bg-success" style="width:<?= round($f['rated_count']/$f['total_si']*100) ?>%"></div>
          </div>
          <?php endif; ?>
        </td>
        <td>
          <?php
          $noEv = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM success_indicators s JOIN kras k ON s.kra_id=k.id JOIN mfos m ON k.mfo_id=m.id WHERE m.template_id=? AND s.is_required=1 AND s.id NOT IN (SELECT si_id FROM evidence_files WHERE form_id=?)");
          $noEv->execute([$f['template_id'], $f['id']]);
          $noEvCount = (int)$noEv->fetchColumn();
          ?>
          <span class="badge <?= $noEvCount > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
            <?= $f['ev_count'] ?> uploaded
          </span>
          <?php if($noEvCount > 0): ?>
          <div class="text-danger" style="font-size:.75rem"><i class="bi bi-exclamation-triangle me-1"></i><?= $noEvCount ?> missing</div>
          <?php endif; ?>
        </td>
        <td class="text-muted-sm"><?= $f['submitted_at'] ? h(date('M d, Y', strtotime($f['submitted_at']))) : '—' ?></td>
        <td>
          <?php if($f['composite_rating']): ?>
          <strong><?= number_format($f['composite_rating'], 2) ?></strong><br>
          <small><?= h($f['adjectival_rating']??'') ?></small>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="actions">
          <?php if($f['status']==='submitted' && $currentRole==='dean'): ?>
          <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-play-fill me-1"></i>Start Review
          </a>
          <?php elseif($f['status']==='under_review' && $currentRole==='dean'): ?>
          <a href="<?= BASE_URL ?>/dean/rate.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-warning">
            <i class="bi bi-pencil me-1"></i>Continue
          </a>
          <?php else: ?>
          <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-eye me-1"></i>View
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

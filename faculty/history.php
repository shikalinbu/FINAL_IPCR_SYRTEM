<?php
$pageTitle  = 'Evaluation History';
$activePage = 'history';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// Allow dean/admin to view other users' history via user_id parameter
$viewUserId = $currentUserId; // Default to current user
$viewUser = $currentUser;

if (isset($_GET['user_id']) && in_array($currentRole, ['admin', 'dean'])) {
    $viewUserId = (int)$_GET['user_id'];
    $viewUserStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $viewUserStmt->execute([$viewUserId]);
    $viewUser = $viewUserStmt->fetch();
    
    if (!$viewUser) {
        echo '<div class="alert alert-danger">User not found.</div>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
    
    // If dean, verify this user is assigned to them
    if ($currentRole === 'dean' && $viewUser['assigned_dean_id'] != $currentUserId) {
        echo '<div class="alert alert-danger">Access denied. This faculty is not under your supervision.</div>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
}

// Debug: Check if user has any forms at all
$debugCount = $db->prepare("SELECT COUNT(*) FROM ipcr_forms WHERE user_id = ?");
$debugCount->execute([$viewUserId]);
$totalFormsCount = $debugCount->fetchColumn();

$myForms = $db->prepare("
    SELECT f.*, 
           COALESCE(p.name, 'Unknown Period') as period_name, 
           p.academic_year, 
           p.semester
    FROM ipcr_forms f
    LEFT JOIN rating_periods p ON f.period_id = p.id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
");
$myForms->execute([$viewUserId]);
$myForms = $myForms->fetchAll();

$dean = null;
if ($viewUser['assigned_dean_id']) {
    $ds = $db->prepare("SELECT * FROM users WHERE id = ?");
    $ds->execute([$viewUser['assigned_dean_id']]);
    $dean = $ds->fetch();
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-clock-history me-2"></i>Evaluation History<?php if ($viewUserId !== $currentUserId): ?> - <?= h(fullName($viewUser)) ?><?php endif; ?></h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">
      <?php if ($viewUserId !== $currentUserId): ?>
        View IPCR submissions and performance evaluations for <?= h($viewUser['first_name']) ?>
      <?php else: ?>
        View your past IPCR submissions and performance evaluations
      <?php endif; ?>
    </p>
  </div>
  <?php if ($viewUserId !== $currentUserId): ?>
  <a href="<?= BASE_URL ?>/dean/faculty.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-2"></i>Back to Faculty List
  </a>
  <?php endif; ?>
</div>

<?php if (!$myForms): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  No IPCR records found. Your submitted IPCRs will appear here.
  <?php if ($totalFormsCount > 0): ?>
    <br><br>
    <strong>Debug Info:</strong> Found <?= $totalFormsCount ?> form(s) in database but none matched the display criteria. 
    This might be a period join issue. Please contact administrator.
  <?php endif; ?>
</div>
<?php else: ?>

<!-- Summary -->
<?php
$finalized = array_filter($myForms, fn($f) => $f['status'] === 'finalized');
$avgRating = 0;
if ($finalized) {
    $sum = array_sum(array_map(fn($f) => (float)$f['composite_rating'], array_filter($finalized, fn($f) => $f['composite_rating'])));
    $cnt = count(array_filter($finalized, fn($f) => $f['composite_rating']));
    $avgRating = $cnt > 0 ? round($sum / $cnt, 3) : 0;
}
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-primary mx-auto"><i class="bi bi-file-earmark-text"></i></div>
      <div class="metric-value"><?= count($myForms) ?></div>
      <div class="metric-label">Total Submissions</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-success mx-auto"><i class="bi bi-lock-fill"></i></div>
      <div class="metric-value"><?= count($finalized) ?></div>
      <div class="metric-label">Finalized</div>
    </div>
  </div>
  <?php if ($avgRating > 0): ?>
  <div class="col-6 col-md-3">
    <div class="metric-card text-center">
      <div class="metric-icon metric-icon-info mx-auto"><i class="bi bi-star-fill"></i></div>
      <div class="metric-value"><?= number_format($avgRating, 2) ?></div>
      <div class="metric-label">Average Rating</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="table-container">
  <table class="ipcr-table">
    <thead>
      <tr>
        <th>Period</th>
        <th>Academic Year</th>
        <th>Status</th>
        <th>Submitted</th>
        <th>Composite Rating</th>
        <th>Adjectival Rating</th>
        <th>Finalized</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($myForms as $f): ?>
      <tr>
        <td><strong><?= h($f['period_name']) ?></strong></td>
        <td><?= h($f['academic_year']) ?> — <?= h(ucfirst($f['semester'])) ?></td>
        <td><?= statusBadge($f['status']) ?></td>
        <td class="text-muted-sm">
          <?= $f['submitted_at'] ? h(date('M d, Y', strtotime($f['submitted_at']))) : '—' ?>
          <?= $f['is_late'] ? '<span class="late-badge">Late</span>' : '' ?>
        </td>
        <td class="text-center fw-bold">
          <?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?>
        </td>
        <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
        <td class="text-muted-sm">
          <?= $f['finalized_at'] ? h(date('M d, Y', strtotime($f['finalized_at']))) : '—' ?>
        </td>
        <td>
          <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
            <i class="bi bi-eye"></i> View
          </a>
          <?php if (in_array($f['status'], ['reviewed','approved','finalized'])): ?>
          <a href="<?= BASE_URL ?>/exports/print-ipcr.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
$pageTitle  = 'My Profile';
$activePage = 'profile';
require_once __DIR__ . '/includes/header.php';

$db  = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $curPw  = $_POST['current_password'] ?? '';
    $newPw  = $_POST['new_password']     ?? '';
    $confPw = $_POST['confirm_password'] ?? '';

    if (!$curPw || !$newPw || !$confPw) {
        $msg = 'All password fields are required.'; $msgType = 'danger';
    } elseif (strlen($newPw) < 6) {
        $msg = 'New password must be at least 6 characters.'; $msgType = 'danger';
    } elseif ($newPw !== $confPw) {
        $msg = 'New passwords do not match.'; $msgType = 'danger';
    } else {
        if (!password_verify($curPw, $currentUser['password_hash'])) {
            $msg = 'Current password is incorrect.'; $msgType = 'danger';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?")->execute([$hash,$currentUserId]);
            auditLog('password_reset','user',$currentUserId,null,null,'Password changed by user');
            $msg = 'Password changed successfully.';
        }
    }
}

$dean = null;
if ($currentUser['assigned_dean_id']) {
    $ds = $db->prepare("SELECT * FROM users WHERE id=?");
    $ds->execute([$currentUser['assigned_dean_id']]);
    $dean = $ds->fetch();
}

$myForms = $db->prepare("
    SELECT f.*, p.name as period_name
    FROM ipcr_forms f JOIN rating_periods p ON f.period_id=p.id
    WHERE f.user_id=? ORDER BY f.created_at DESC
");
$myForms->execute([$currentUserId]);
$myForms = $myForms->fetchAll();
?>

<div class="page-header">
  <h1><i class="bi bi-person-circle me-2"></i>My Profile</h1>
  <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">View and update your personal information and account settings</p>
</div>

<?php if($msg): ?><div class="alert alert-<?= $msgType ?> mb-4"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body text-center">
        <div class="topbar-avatar mx-auto mb-3" style="width:72px;height:72px;font-size:1.6rem">
          <?= h(getInitials($_SESSION['name'])) ?>
        </div>
        <h5 class="mb-0"><?= h(fullName($currentUser)) ?></h5>
        <div class="text-muted-sm mb-2"><?= h($currentUser['position'] ?? '') ?></div>
        <span class="badge bg-primary"><?= h(ucfirst($currentRole)) ?></span>
        <hr>
        <div class="text-start" style="font-size:.88rem">
          <div class="mb-2"><strong>Employee ID:</strong> <?= h($currentUser['employee_id']) ?></div>
          <div class="mb-2"><strong>Email:</strong> <?= h($currentUser['email'] ?? '—') ?></div>
          <div class="mb-2"><strong>Department:</strong> <?= h($currentUser['department'] ?? '—') ?></div>
          <div class="mb-2"><strong>Employment:</strong> <?= h(ucfirst($currentUser['employment_status'] ?? '—')) ?></div>
          <?php if($dean): ?>
          <div class="mb-2"><strong>Evaluator:</strong> <?= h(fullName($dean)) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h6><i class="bi bi-key me-2"></i>Change Password</h6></div>
      <div class="card-body">
        <form method="POST">
          <div class="mb-3">
            <label class="form-label">Current Password <span class="required-star">*</span></label>
            <input type="password" class="form-control" name="current_password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">New Password <span class="required-star">*</span></label>
            <input type="password" class="form-control" name="new_password" required minlength="6" placeholder="Min 6 characters">
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password <span class="required-star">*</span></label>
            <input type="password" class="form-control" name="confirm_password" required>
          </div>
          <button class="btn btn-primary w-100">Change Password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h6><i class="bi bi-clock-history me-2"></i>IPCR Evaluation History</h6></div>
      <div class="table-responsive">
        <table class="ipcr-table">
          <thead><tr><th>Period</th><th>Status</th><th>Composite Rating</th><th>Adjectival</th><th>Finalized</th><th></th></tr></thead>
          <tbody>
            <?php if(!$myForms): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No IPCR history yet.</td></tr>
            <?php endif; ?>
            <?php foreach($myForms as $f): ?>
            <tr>
              <td><?= h($f['period_name']) ?></td>
              <td><?= statusBadge($f['status']) ?></td>
              <td class="text-center fw-bold"><?= $f['composite_rating'] ? number_format($f['composite_rating'], 2) : '—' ?></td>
              <td><?= h($f['adjectival_rating'] ?? '—') ?></td>
              <td class="text-muted-sm"><?= $f['finalized_at'] ? h(date('M d, Y', strtotime($f['finalized_at']))) : '—' ?></td>
              <td>
                <a href="<?= BASE_URL ?>/faculty/ipcr-view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

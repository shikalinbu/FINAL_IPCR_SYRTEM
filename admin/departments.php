<?php
$pageTitle  = 'Departments';
$activePage = 'departments';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin','secretary','dean');

$db  = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($currentRole, ['admin', 'dean'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name  = trim($_POST['name']         ?? '');
        $abbr  = trim($_POST['abbreviation'] ?? '');
        $deptId = (int)($_POST['dept_id']    ?? 0);

        if (!$name) {
            $msg = 'Department name is required.'; $msgType = 'danger';
        } else {
            if ($action === 'create') {
                $exists = $db->prepare("SELECT id FROM departments WHERE name=?");
                $exists->execute([$name]);
                if ($exists->fetch()) {
                    $msg = 'Department already exists.'; $msgType = 'danger';
                } else {
                    $db->prepare("INSERT INTO departments (name, abbreviation) VALUES (?,?)")
                       ->execute([$name, $abbr]);
                    auditLog('created', 'department', $db->lastInsertId(), null, ['name'=>$name], 'Department created');
                    $msg = "Department '{$name}' created.";
                }
            } else {
                $db->prepare("UPDATE departments SET name=?, abbreviation=? WHERE id=?")
                   ->execute([$name, $abbr, $deptId]);
                auditLog('updated', 'department', $deptId, null, ['name'=>$name], 'Department updated');
                $msg = 'Department updated.';
            }
        }
    }

    if ($action === 'delete') {
        $deptId = (int)($_POST['dept_id'] ?? 0);
        // Check if any users reference this dept name
        $dept = $db->prepare("SELECT name FROM departments WHERE id=?");
        $dept->execute([$deptId]);
        $deptName = $dept->fetchColumn();
        $inUse = $db->prepare("SELECT COUNT(*) FROM users WHERE department=?");
        $inUse->execute([$deptName]);
        if ($inUse->fetchColumn() > 0) {
            $msg = 'Cannot delete: department is assigned to one or more users.'; $msgType = 'danger';
        } else {
            $db->prepare("DELETE FROM departments WHERE id=?")->execute([$deptId]);
            auditLog('deleted', 'department', $deptId, $deptName, null, 'Department deleted');
            $msg = 'Department deleted.';
        }
    }
}

$departments = $db->query("
    SELECT d.*, COUNT(u.id) as user_count
    FROM departments d
    LEFT JOIN users u ON u.department = d.name AND u.is_active = 1
    GROUP BY d.id
    ORDER BY d.name
")->fetchAll();
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-building me-2"></i>Departments</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Manage college departments and organizational units</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal"
    onclick="document.getElementById('dept-modal-title').textContent='Add Department';
             document.getElementById('dept-action').value='create';
             document.getElementById('dept-id').value='';
             document.getElementById('dept-form').reset();">
    <i class="bi bi-plus-circle me-2"></i>Add Department
  </button>
</div>

<?php if($msg): ?><div class="alert alert-<?= $msgType ?> mb-4"><?= h($msg) ?></div><?php endif; ?>

<div class="table-container">
  <table class="ipcr-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Department / College</th>
        <th>Abbreviation</th>
        <th>Active Users</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$departments): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">No departments found.</td></tr>
      <?php endif; ?>
      <?php foreach ($departments as $i => $d): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= h($d['name']) ?></strong></td>
        <td><?= $d['abbreviation'] ? '<code>'.h($d['abbreviation']).'</code>' : '<span class="text-muted">—</span>' ?></td>
        <td>
          <span class="badge <?= $d['user_count'] > 0 ? 'bg-primary' : 'bg-secondary' ?>">
            <?= $d['user_count'] ?> user(s)
          </span>
        </td>
        <td class="text-muted-sm"><?= h(date('M d, Y', strtotime($d['created_at']))) ?></td>
        <td class="actions">
          <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
            onclick="editDept(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if ($d['user_count'] == 0): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
              onclick="return confirm('Delete department \'<?= h(addslashes($d['name'])) ?>\'?')" title="Delete">
              <i class="bi bi-trash"></i>
            </button>
          </form>
          <?php else: ?>
          <button class="btn btn-sm btn-outline-danger" disabled title="In use by users">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Department Modal -->
<div class="modal fade" id="deptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dept-modal-title">Add Department</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="dept-form">
        <div class="modal-body">
          <input type="hidden" name="action" id="dept-action" value="create">
          <input type="hidden" name="dept_id" id="dept-id">
          <div class="mb-3">
            <label class="form-label">Department / College Name <span class="required-star">*</span></label>
            <input class="form-control" name="name" id="dept-name" required
              placeholder="e.g. College of Engineering">
          </div>
          <div class="mb-3">
            <label class="form-label">Abbreviation</label>
            <input class="form-control" name="abbreviation" id="dept-abbr"
              placeholder="e.g. COE">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editDept(d) {
  document.getElementById('dept-modal-title').textContent = 'Edit Department';
  document.getElementById('dept-action').value = 'edit';
  document.getElementById('dept-id').value     = d.id;
  document.getElementById('dept-name').value   = d.name;
  document.getElementById('dept-abbr').value   = d.abbreviation || '';
  new bootstrap.Modal(document.getElementById('deptModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

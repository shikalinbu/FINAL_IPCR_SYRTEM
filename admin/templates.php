<?php
$pageTitle  = 'IPCR Templates';
$activePage = 'templates';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin','secretary','dean');

$db  = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($currentRole, ['admin', 'dean'])) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_template') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { $msg = 'Template name is required.'; $msgType = 'danger'; }
        else {
            $db->prepare("INSERT INTO ipcr_templates (name, period_id, is_default, created_by) VALUES (?,?,0,?)")
               ->execute([$name, $_POST['period_id'] ?: null, $currentUserId]);
            $msg = "Template '{$name}' created. Now add MFOs below.";
        }
    }

    if ($action === 'add_mfo') {
        $tmplId = (int)$_POST['template_id'];
        $code   = trim($_POST['mfo_code'] ?? '');
        $title  = trim($_POST['mfo_title'] ?? '');
        $weight = (float)($_POST['mfo_weight'] ?? 0);
        $order  = (int)$db->query("SELECT COUNT(*)+1 FROM mfos WHERE template_id={$tmplId}")->fetchColumn();
        $db->prepare("INSERT INTO mfos (template_id,code,title,weight,sort_order) VALUES (?,?,?,?,?)")
           ->execute([$tmplId,$code,$title,$weight,$order]);
        $msg = 'MFO added.';
    }

    if ($action === 'add_kra') {
        $mfoId  = (int)$_POST['mfo_id'];
        $code   = trim($_POST['kra_code'] ?? '');
        $title  = trim($_POST['kra_title'] ?? '');
        $weight = (float)($_POST['kra_weight'] ?? 0);
        $order  = (int)$db->query("SELECT COUNT(*)+1 FROM kras WHERE mfo_id={$mfoId}")->fetchColumn();
        $db->prepare("INSERT INTO kras (mfo_id,code,title,weight,sort_order) VALUES (?,?,?,?,?)")
           ->execute([$mfoId,$code,$title,$weight,$order]);
        $msg = 'KRA added.';
    }

    if ($action === 'add_si') {
        $kraId  = (int)$_POST['kra_id'];
        $code   = trim($_POST['si_code']        ?? '');
        $title  = trim($_POST['si_title']       ?? '');
        $qt     = trim($_POST['quality_target'] ?? '');
        $et     = trim($_POST['efficiency_target']  ?? '');
        $tt     = trim($_POST['timeliness_target']  ?? '');
        $sw     = (float)($_POST['si_weight']   ?? 0);
        $req    = isset($_POST['is_required']) ? 1 : 0;
        $order  = (int)$db->query("SELECT COUNT(*)+1 FROM success_indicators WHERE kra_id={$kraId}")->fetchColumn();
        $db->prepare("INSERT INTO success_indicators (kra_id,code,title,quality_target,efficiency_target,timeliness_target,si_weight,is_required,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$kraId,$code,$title,$qt,$et,$tt,$sw,$req,$order]);
        $db->prepare("INSERT IGNORE INTO required_proof_types (si_id, label, is_required) VALUES (LAST_INSERT_ID(), ?, 1)")
           ->execute(["Evidence/supporting document"]);
        $msg = 'Success Indicator added.';
    }

    if ($action === 'delete_si')   { $db->prepare("DELETE FROM success_indicators WHERE id=?")->execute([(int)$_POST['id']]); $msg='SI deleted.'; }
    if ($action === 'delete_kra')  { $db->prepare("DELETE FROM kras WHERE id=?")->execute([(int)$_POST['id']]); $msg='KRA deleted.'; }
    if ($action === 'delete_mfo')  { $db->prepare("DELETE FROM mfos WHERE id=?")->execute([(int)$_POST['id']]); $msg='MFO deleted.'; }
}

$templates = $db->query("SELECT t.*, p.name as period_name FROM ipcr_templates t LEFT JOIN rating_periods p ON t.period_id=p.id ORDER BY t.created_at DESC")->fetchAll();
$periods   = $db->query("SELECT * FROM rating_periods ORDER BY created_at DESC")->fetchAll();

$viewTmplId = (int)($_GET['tmpl'] ?? ($templates[0]['id'] ?? 0));
$viewTmpl   = null;
$mfos       = [];
if ($viewTmplId) {
    $vt = $db->prepare("SELECT * FROM ipcr_templates WHERE id=?"); $vt->execute([$viewTmplId]); $viewTmpl = $vt->fetch();
    $mfoRows = $db->prepare("SELECT * FROM mfos WHERE template_id=? ORDER BY sort_order"); $mfoRows->execute([$viewTmplId]); $mfoRows = $mfoRows->fetchAll();
    foreach ($mfoRows as $mfo) {
        $kraRows = $db->prepare("SELECT * FROM kras WHERE mfo_id=? ORDER BY sort_order"); $kraRows->execute([$mfo['id']]); $kraRows = $kraRows->fetchAll();
        foreach ($kraRows as &$kra) {
            $siRows = $db->prepare("SELECT s.*, GROUP_CONCAT(r.label ORDER BY r.sort_order SEPARATOR '|') as proof_types FROM success_indicators s LEFT JOIN required_proof_types r ON r.si_id=s.id WHERE s.kra_id=? GROUP BY s.id ORDER BY s.sort_order");
            $siRows->execute([$kra['id']]);
            $kra['sis'] = $siRows->fetchAll();
        }
        $mfo['kras'] = $kraRows;
        $mfos[] = $mfo;
    }
}
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><i class="bi bi-layout-text-window me-2"></i>IPCR Templates</h1>
    <p class="text-muted mb-0 mt-1" style="font-size: 0.9rem;">Configure IPCR form templates with KRAs, MFOs, and Success Indicators</p>
  </div>
  <?php if ($currentRole === 'secretary'): ?>
  <div class="alert alert-warning mb-0" style="font-size:.85rem;padding:8px 12px">
    <i class="bi bi-exclamation-triangle me-1"></i>Your edits will be submitted for dean approval before being applied.
  </div>
  <?php else: ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTmplModal">
    <i class="bi bi-plus-circle me-2"></i>New Template
  </button>
  <?php endif; ?>
</div>

<?php if($msg): ?><div class="alert alert-<?= $msgType ?> mb-4"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-4">
  <!-- Template List -->
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header"><h6>Templates</h6></div>
      <div class="list-group list-group-flush">
        <?php foreach($templates as $t): ?>
        <a href="?tmpl=<?= $t['id'] ?>" class="list-group-item list-group-item-action <?= $viewTmplId==$t['id']?'active':'' ?>">
          <div class="fw-bold"><?= h($t['name']) ?></div>
          <div class="text-muted-sm"><?= h($t['period_name'] ?? 'Global') ?></div>
        </a>
        <?php endforeach; ?>
        <?php if(!$templates): ?>
        <div class="list-group-item text-muted small">No templates yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Template Editor -->
  <div class="col-lg-9">
    <?php if($viewTmpl): ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h6><?= h($viewTmpl['name']) ?></h6>
          <?php
          $mfoSum = array_sum(array_column($mfos,'weight'));
          ?>
          <span class="badge <?= abs($mfoSum-100)<0.01?'bg-success':'bg-danger' ?>">MFO Total: <?= $mfoSum ?>%</span>
        </div>
        <?php if ($currentRole === 'secretary'): ?>
        <button class="btn btn-sm btn-outline-warning" onclick="requestTemplateEdit(<?= $viewTmplId ?>, '<?= addslashes($viewTmpl['name']) ?>', <?= $viewTmpl['period_id'] ?? 'null' ?>)">
          <i class="bi bi-pencil-square me-1"></i>Request Edit
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <!-- Add MFO form -->
        <form method="POST" class="mb-4 p-3 bg-light rounded">
          <input type="hidden" name="action" value="add_mfo">
          <input type="hidden" name="template_id" value="<?= $viewTmplId ?>">
          <div class="fw-bold mb-2">Add MFO</div>
          <div class="row g-2 align-items-end">
            <div class="col-md-2"><input class="form-control form-control-sm" name="mfo_code" placeholder="Code e.g. MFO5"></div>
            <div class="col-md-5"><input class="form-control form-control-sm" name="mfo_title" placeholder="MFO Title" required></div>
            <div class="col-md-2"><div class="input-group input-group-sm"><input type="number" class="form-control" name="mfo_weight" placeholder="Weight" min="0" max="100"><span class="input-group-text">%</span></div></div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Add MFO</button></div>
          </div>
        </form>

        <!-- MFOs -->
        <?php foreach($mfos as $mfo): ?>
        <div class="mfo-accordion mb-3">
          <div class="mfo-header">
            <h6><?= h($mfo['code']) ?>: <?= h($mfo['title']) ?></h6>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-light text-dark"><?= $mfo['weight'] ?>%</span>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_mfo">
                <input type="hidden" name="id" value="<?= $mfo['id'] ?>">
                <input type="hidden" name="<?= 'tmpl' ?>" value="<?= $viewTmplId ?>">
                <button type="submit" class="btn btn-sm btn-outline-light" onclick="return confirm('Delete this MFO and all its KRAs/SIs?')">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </div>
          <div class="mfo-body">
            <?php foreach($mfo['kras'] as $kra): ?>
            <div class="kra-block">
              <div class="kra-header">
                <h6><?= h($kra['code']) ?>: <?= h($kra['title']) ?> (<?= $kra['weight'] ?>%)</h6>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="delete_kra">
                  <input type="hidden" name="id" value="<?= $kra['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:1px 6px;font-size:.75rem" onclick="return confirm('Delete this KRA?')"><i class="bi bi-trash"></i></button>
                </form>
              </div>
              <div class="p-3">
                <table class="ipcr-table mb-2" style="font-size:.8rem">
                  <thead><tr><th>Code</th><th>Indicator</th><th>Required</th><th>Weight</th><th>Required Proofs</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach($kra['sis'] as $si): ?>
                    <tr>
                      <td><?= h($si['code']) ?></td>
                      <td><?= h($si['title']) ?></td>
                      <td><?= $si['is_required'] ? '<span class="badge bg-danger">Required</span>' : '<span class="badge bg-secondary">Optional</span>' ?></td>
                      <td><?= h($si['si_weight']) ?>%</td>
                      <td><small><?= h(str_replace('|',', ',$si['proof_types']??'')) ?></small></td>
                      <td>
                        <form method="POST" style="display:inline">
                          <input type="hidden" name="action" value="delete_si">
                          <input type="hidden" name="id" value="<?= $si['id'] ?>">
                          <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:1px 5px;font-size:.72rem" onclick="return confirm('Delete?')"><i class="bi bi-x"></i></button>
                        </form>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <!-- Add SI -->
                <form method="POST" class="p-2 bg-light rounded">
                  <input type="hidden" name="action" value="add_si">
                  <input type="hidden" name="kra_id" value="<?= $kra['id'] ?>">
                  <div class="row g-1 mb-1">
                    <div class="col-2"><input class="form-control form-control-sm" name="si_code" placeholder="Code"></div>
                    <div class="col-6"><input class="form-control form-control-sm" name="si_title" placeholder="Indicator Title" required></div>
                    <div class="col-2"><input type="number" class="form-control form-control-sm" name="si_weight" placeholder="Wt%" min="0"></div>
                    <div class="col-2">
                      <div class="form-check mt-1">
                        <input type="checkbox" class="form-check-input" name="is_required" id="req-<?= $kra['id'] ?>" checked>
                        <label class="form-check-label small" for="req-<?= $kra['id'] ?>">Required</label>
                      </div>
                    </div>
                  </div>
                  <div class="row g-1 mb-1">
                    <div class="col-4"><input class="form-control form-control-sm" name="quality_target" placeholder="Quality target"></div>
                    <div class="col-4"><input class="form-control form-control-sm" name="efficiency_target" placeholder="Efficiency target"></div>
                    <div class="col-4"><input class="form-control form-control-sm" name="timeliness_target" placeholder="Timeliness target"></div>
                  </div>
                  <button class="btn btn-xs btn-outline-primary" style="font-size:.75rem;padding:2px 8px"><i class="bi bi-plus me-1"></i>Add Indicator</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- Add KRA form -->
            <div class="p-3 bg-light border-top">
              <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="add_kra">
                <input type="hidden" name="mfo_id" value="<?= $mfo['id'] ?>">
                <div class="col-2"><input class="form-control form-control-sm" name="kra_code" placeholder="KRA Code"></div>
                <div class="col-5"><input class="form-control form-control-sm" name="kra_title" placeholder="KRA Title" required></div>
                <div class="col-2"><div class="input-group input-group-sm"><input type="number" class="form-control" name="kra_weight" placeholder="Wt%" min="0" max="100"><span class="input-group-text">%</span></div></div>
                <div class="col-3"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-circle me-1"></i>Add KRA</button></div>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(!$mfos): ?>
        <div class="alert alert-info">No MFOs yet. Add the first MFO above.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info">Select a template from the list to edit it, or create a new one.</div>
    <?php endif; ?>
  </div>
</div>

<!-- New Template Modal -->
<div class="modal fade" id="newTmplModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">New IPCR Template</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="action" value="create_template">
          <div class="mb-3"><label class="form-label">Template Name <span class="required-star">*</span></label>
            <input class="form-control" name="name" required placeholder="e.g. Faculty IPCR AY 2025-2026"></div>
          <div class="mb-3"><label class="form-label">Associated Period</label>
            <select class="form-select" name="period_id">
              <option value="">— Global / Unassigned —</option>
              <?php foreach($periods as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Template</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
// Secretary Request Template Edit
async function requestTemplateEdit(templateId, currentName, currentPeriodId) {
  const periodsOptions = <?= json_encode(array_map(function($p) {
    return ['id' => $p['id'], 'name' => $p['name']];
  }, $periods)) ?>;
  
  let periodOptionsHtml = '<option value="">— Global / Unassigned —</option>';
  periodsOptions.forEach(p => {
    const selected = (currentPeriodId == p.id) ? 'selected' : '';
    periodOptionsHtml += `<option value="${p.id}" ${selected}>${p.name}</option>`;
  });

  const { value: formValues } = await Swal.fire({
    title: 'Request Template Edit',
    html: `
      <div class="alert alert-warning text-start mb-3" style="font-size:.88rem">
        <i class="bi bi-info-circle me-1"></i> Your changes will be submitted to the dean for approval.
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Template Name</label>
        <input id="req-tmpl-name" class="swal2-input" style="width:95%;margin:0" value="${currentName}">
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold">Associated Period</label>
        <select id="req-tmpl-period" class="swal2-input" style="width:95%;margin:0">
          ${periodOptionsHtml}
        </select>
      </div>
      <div class="mb-3 text-start">
        <label class="form-label fw-semibold text-danger">Remarks (Required) *</label>
        <textarea id="req-tmpl-remarks" class="swal2-textarea" style="width:95%;margin:0" placeholder="Why are you requesting this change?"></textarea>
      </div>
    `,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: 'Submit Request',
    cancelButtonText: 'Cancel',
    width: '600px',
    preConfirm: () => {
      const remarks = document.getElementById('req-tmpl-remarks').value.trim();
      if (!remarks) {
        Swal.showValidationMessage('Remarks are required');
        return false;
      }
      return {
        name: document.getElementById('req-tmpl-name').value.trim(),
        period_id: document.getElementById('req-tmpl-period').value || null,
        remarks: remarks
      };
    }
  });

  if (formValues) {
    try {
      const response = await fetch('<?= BASE_URL ?>/api/submit-edit-request.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          request_type: 'template',
          target_id: parseInt(templateId),
          request_data: {
            name: formValues.name,
            period_id: formValues.period_id
          },
          remarks: formValues.remarks
        })
      });

      const data = await response.json();
      
      if (data.success) {
        Toast.success('Edit request submitted for dean approval!');
        setTimeout(() => location.reload(), 1500);
      } else {
        Toast.error(data.message || 'Failed to submit request');
      }
    } catch (error) {
      Toast.error('Failed to submit request: ' + error.message);
    }
  }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

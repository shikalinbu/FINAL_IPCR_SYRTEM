<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$currentUser   = getCurrentUser();
$currentRole   = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

function getConfig(string $key, string $default = ''): string {
    static $cache = [];
    if (empty($cache)) {
        try {
            $rows = getDB()->query("SELECT config_key, config_value FROM system_config")->fetchAll();
            foreach ($rows as $r) $cache[$r['config_key']] = $r['config_value'];
        } catch (Exception $e) {}
    }
    return $cache[$key] ?? $default;
}

$instName    = getConfig('institution_name',    'Isabela State University');
$instAcronym = getConfig('institution_acronym', 'ISU');
$pageTitle   = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title><?= h($pageTitle) ?> — IPCR System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>/assets/css/main.css" rel="stylesheet">
  <style>
  /* ══════════════════════════════════════════
     NEW LAYOUT — White topbar + Clean sidebar
     ══════════════════════════════════════════ */

  /* Reset old topbar/sidebar vars for new design */
  :root {
    --topbar-h:   64px;
    --sidebar-w:  260px;
  }

  /* ── TOPBAR ── */
  #topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: var(--topbar-h);
    background: #fff;
    border-bottom: 1.5px solid #e5e9e6;
    display: flex;
    align-items: center;
    padding: 0 20px 0 16px;
    gap: 14px;
    z-index: 1100;
    box-shadow: 0 1px 8px rgba(0,0,0,.07);
  }

  .topbar-hamburger {
    width: 38px; height: 38px;
    background: var(--primary);
    border: none; border-radius: 10px;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s ease;
  }
  .topbar-hamburger:hover { background: var(--primary-light); }

  .topbar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
  }

  .topbar-brand-logo {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid var(--primary-xlight);
    flex-shrink: 0;
    object-fit: contain;
    padding: 2px;
  }

  .topbar-brand-text { min-width: 0; }

  .topbar-brand-name {
    font-size: .95rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -.01em;
  }

  .topbar-brand-sub {
    font-size: .7rem;
    color: var(--gray-500);
    white-space: nowrap;
  }

  .topbar-spacer { flex: 1; }

  .topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .topbar-logout {
    display: flex; align-items: center; gap: 6px;
    background: #fff1f1;
    border: 1.5px solid #fca5a5;
    color: #dc2626;
    font-weight: 600;
    border-radius: 8px;
    padding: 6px 14px;
    font-size: .78rem;
    text-decoration: none;
    transition: background .15s;
    white-space: nowrap;
  }
  .topbar-logout:hover { background: #fee2e2; color: #b91c1c; }

  /* ── SIDEBAR ── */
  #sidebar {
    position: fixed;
    top: var(--topbar-h);
    left: 0;
    bottom: 0;
    width: var(--sidebar-w);
    background: #fff;
    border-right: 1.5px solid #e5e9e6;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform .22s ease, width .22s ease;
    scroll-behavior: auto !important; /* Disable smooth scroll for instant position restore */
  }

  #sidebar::-webkit-scrollbar { width: 3px; }
  #sidebar::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 3px; }

  /* ── USER CARD (top of sidebar) ── */
  .sidebar-user-card {
    padding: 14px 16px;
    border-bottom: 1px solid #e5e9e6;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--primary-xlight);
    flex-shrink: 0;
  }

  .sidebar-user-avatar {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    flex-shrink: 0;
    letter-spacing: -.5px;
  }

  .sidebar-user-name {
    font-size: .84rem;
    font-weight: 700;
    color: var(--gray-800);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .sidebar-user-role {
    font-size: .7rem;
    color: var(--gray-500);
    margin-top: 1px;
  }

  /* ── NAV ── */
  .sidebar-nav { flex: 1; padding: 8px 0 16px; }

  .sidebar-section-label {
    font-size: .63rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--gray-400);
    padding: 14px 18px 4px;
  }

  .sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px 9px 16px;
    margin: 1px 8px;
    color: var(--gray-600);
    font-size: .855rem;
    font-weight: 500;
    transition: all .15s ease;
    border-radius: 8px;
    text-decoration: none;
    position: relative;
  }

  .sidebar-nav a i {
    font-size: .95rem;
    width: 18px;
    text-align: center;
    flex-shrink: 0;
    color: var(--gray-400);
    transition: color .15s;
  }

  .sidebar-nav a:hover {
    background: var(--primary-xlight);
    color: var(--primary);
  }
  .sidebar-nav a:hover i { color: var(--primary); }

  .sidebar-nav a.active {
    background: var(--primary);
    color: #fff;
    font-weight: 600;
  }
  .sidebar-nav a.active i { color: rgba(255,255,255,.85); }

  .sidebar-footer {
    padding: 12px 18px;
    border-top: 1px solid #e5e9e6;
    font-size: .7rem;
    color: var(--gray-400);
    background: var(--gray-50);
  }

  /* ── MAIN WRAPPER ── */
  #app-shell { display: flex; min-height: 100vh; }

  #main-wrapper {
    margin-left: var(--sidebar-w);
    margin-top: var(--topbar-h);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - var(--topbar-h));
    transition: margin-left .22s ease;
    background: var(--gray-100);
  }

  #page-content {
    flex: 1;
    padding: 26px 30px;
  }

  /* ── PAGE HEADER with left green bar ── */
  .page-header h1 {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--primary);
    margin: 0 0 4px;
    padding-left: 12px;
    border-left: 4px solid var(--primary);
    letter-spacing: -.03em;
  }

  /* ── SIDEBAR COLLAPSED ── */
  @media (min-width: 768px) {
    body.sidebar-collapsed #sidebar { width: 60px; }
    body.sidebar-collapsed #main-wrapper { margin-left: 60px; }
    body.sidebar-collapsed .sidebar-user-card,
    body.sidebar-collapsed .sidebar-nav a span,
    body.sidebar-collapsed .sidebar-section-label,
    body.sidebar-collapsed .sidebar-footer,
    body.sidebar-collapsed .sidebar-brand-text { display: none; }
    body.sidebar-collapsed .sidebar-nav a {
      padding: 10px;
      justify-content: center;
      margin: 2px 6px;
    }
    body.sidebar-collapsed .sidebar-nav a i { width: auto; }
    body.sidebar-collapsed .sidebar-nav a.active::before { display: none; }
  }

  /* ── MOBILE ── */
  @media (max-width: 767px) {
    #sidebar {
      transform: translateX(-100%);
      top: var(--topbar-h);
      box-shadow: none;
      border-right: none;
    }
    body.sidebar-open #sidebar {
      transform: translateX(0);
      box-shadow: 4px 0 24px rgba(0,0,0,.15);
    }
    body.sidebar-open::after {
      content: '';
      position: fixed; inset: 0;
      top: var(--topbar-h);
      background: rgba(0,0,0,.4);
      z-index: 999;
    }
    #main-wrapper {
      margin-left: 0 !important;
      margin-top: var(--topbar-h);
    }
    #page-content { padding: 16px; }
    .topbar-brand-name { font-size: .82rem; }
    .topbar-brand-sub  { display: none; }
    .sticky-actions  { margin: 0 -16px -16px; padding: 10px 16px; }
    .form-bottom-bar { margin: 0 -16px -16px; padding: 10px 16px; }
  }

  @media (max-width: 480px) {
    .topbar-logout span { display: none; }
    .topbar-logout { padding: 6px 10px; }
    #page-content { padding: 12px; }
  }

  /* Sticky actions bottom margin fix */
  .sticky-actions  { margin: 0 -30px -26px; }
  .form-bottom-bar { margin: 0 -30px -26px; }

  @media (max-width: 1199px) {
    .sticky-actions  { margin: 0 -30px -26px; }
    .form-bottom-bar { margin: 0 -30px -26px; }
  }
  </style>
</head>
<body>
<div id="app-shell">

<!-- ══ TOPBAR ══════════════════════════════════════════════ -->
<header id="topbar">
  <!-- Hamburger -->
  <button class="topbar-hamburger" id="sidebar-toggle" aria-label="Toggle menu">
    <i class="bi bi-list"></i>
  </button>

  <!-- Brand -->
  <div class="topbar-brand">
    <img src="<?= BASE_URL ?>/assets/images/r.png"
         class="topbar-brand-logo" alt="ISU Logo"
         onerror="this.style.display='none'">
    <div class="topbar-brand-text">
      <div class="topbar-brand-name">ISU IPCR Management System</div>
      <div class="topbar-brand-sub">Ilagan Campus</div>
    </div>
  </div>

  <div class="topbar-spacer"></div>

  <!-- Right actions -->
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>/profile.php"
       class="topbar-logout"
       style="background:var(--primary-xlight);border-color:#b8d9c4;color:var(--primary)"
       title="My Profile">
      <i class="bi bi-person-circle"></i>
      <span><?= h($_SESSION['name']) ?></span>
    </a>
    <a href="<?= BASE_URL ?>/logout.php"
       class="topbar-logout"
       onclick="return confirm('Sign out of IPCR System?')"
       title="Logout">
      <i class="bi bi-box-arrow-right"></i>
      <span>Logout</span>
    </a>
  </div>
</header>

<!-- ══ SIDEBAR ═════════════════════════════════════════════ -->
<nav id="sidebar">

  <!-- User Card -->
  <div class="sidebar-user-card">
    <div class="sidebar-user-avatar"><?= h(getInitials($_SESSION['name'])) ?></div>
    <div style="min-width:0">
      <div class="sidebar-user-name"><?= h($_SESSION['name']) ?></div>
      <div class="sidebar-user-role"><?= h(ucfirst($currentRole)) ?></div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="sidebar-nav">
    <?php if ($currentRole === 'admin'): ?>
      <div class="sidebar-section-label">Overview</div>
      <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $activePage==='dashboard'?'active':'' ?>">
        <i class="bi bi-house-door"></i><span>Dashboard</span>
      </a>

      <div class="sidebar-section-label">Management</div>
      <a href="<?= BASE_URL ?>/admin/users.php" class="<?= $activePage==='users'?'active':'' ?>">
        <i class="bi bi-people"></i><span>Users</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/departments.php" class="<?= $activePage==='departments'?'active':'' ?>">
        <i class="bi bi-building"></i><span>Departments</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/periods.php" class="<?= $activePage==='periods'?'active':'' ?>">
        <i class="bi bi-calendar3"></i><span>Rating Periods</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/templates.php" class="<?= $activePage==='templates'?'active':'' ?>">
        <i class="bi bi-layout-text-window"></i><span>IPCR Templates</span>
      </a>

      <div class="sidebar-section-label">IPCR Records</div>
      <a href="<?= BASE_URL ?>/admin/ipcr-list.php" class="<?= $activePage==='ipcr-list'?'active':'' ?>">
        <i class="bi bi-list-task"></i><span>All IPCR Forms</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/submission-tracking.php" class="<?= $activePage==='submission-tracking'?'active':'' ?>">
        <i class="bi bi-clipboard-check"></i><span>Submission Tracking</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/extensions.php" class="<?= $activePage==='extensions'?'active':'' ?>">
        <i class="bi bi-calendar-plus"></i><span>Extensions</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/reports.php" class="<?= $activePage==='reports'?'active':'' ?>">
        <i class="bi bi-bar-chart-fill"></i><span>Reports</span>
      </a>

      <div class="sidebar-section-label">System</div>
      <a href="<?= BASE_URL ?>/admin/settings.php" class="<?= $activePage==='settings'?'active':'' ?>">
        <i class="bi bi-gear"></i><span>Settings</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/audit.php" class="<?= $activePage==='audit'?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Audit Log</span>
      </a>

    <?php elseif ($currentRole === 'secretary'): ?>
      <?php
      // Get pending request count
      try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM edit_requests WHERE requested_by = ? AND status = 'pending'");
        $stmt->execute([$currentUserId]);
        $pendingCount = $stmt->fetchColumn();
      } catch (Exception $e) {
        $pendingCount = 0;
      }
      ?>
      <div class="sidebar-section-label">Overview</div>
      <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $activePage==='dashboard'?'active':'' ?>">
        <i class="bi bi-house-door"></i><span>Dashboard</span>
      </a>

      <div class="sidebar-section-label">Management</div>
      <a href="<?= BASE_URL ?>/admin/users.php" class="<?= $activePage==='users'?'active':'' ?>">
        <i class="bi bi-people"></i><span>Users</span>
      </a>
      <a href="<?= BASE_URL ?>/secretary/faculty.php" class="<?= $activePage==='secretary-faculty'?'active':'' ?>">
        <i class="bi bi-person-badge"></i><span>My Faculty</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/departments.php" class="<?= $activePage==='departments'?'active':'' ?>">
        <i class="bi bi-building"></i><span>Departments</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/periods.php" class="<?= $activePage==='periods'?'active':'' ?>">
        <i class="bi bi-calendar3"></i><span>Rating Periods</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/templates.php" class="<?= $activePage==='templates'?'active':'' ?>">
        <i class="bi bi-layout-text-window"></i><span>IPCR Templates</span>
      </a>

      <div class="sidebar-section-label">My Requests</div>
      <a href="<?= BASE_URL ?>/secretary/requests.php" class="<?= $activePage==='secretary-requests'?'active':'' ?>">
        <i class="bi bi-clock-history"></i><span>Edit Requests</span>
        <?php if ($pendingCount > 0): ?>
          <span class="badge bg-warning text-dark" style="margin-left:auto;font-size:.7rem;padding:2px 6px"><?= $pendingCount ?> pending</span>
        <?php endif; ?>
      </a>

      <div class="sidebar-section-label">IPCR Records</div>
      <a href="<?= BASE_URL ?>/admin/ipcr-list.php" class="<?= $activePage==='ipcr-list'?'active':'' ?>">
        <i class="bi bi-list-task"></i><span>All IPCR Forms</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/submission-tracking.php" class="<?= $activePage==='submission-tracking'?'active':'' ?>">
        <i class="bi bi-clipboard-check"></i><span>Submission Tracking</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/reports.php" class="<?= $activePage==='reports'?'active':'' ?>">
        <i class="bi bi-bar-chart-fill"></i><span>Reports</span>
      </a>

      <div class="sidebar-section-label">System</div>
      <a href="<?= BASE_URL ?>/admin/audit.php" class="<?= $activePage==='audit'?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Audit Log</span>
      </a>

    <?php elseif ($currentRole === 'dean'): ?>
      <?php
      // Get pending approval count for badge
      try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM edit_requests WHERE dean_id = ? AND status = 'pending'");
        $stmt->execute([$currentUserId]);
        $pendingCount = $stmt->fetchColumn();
      } catch (Exception $e) {
        $pendingCount = 0;
      }
      ?>
      <div class="sidebar-section-label">Overview</div>
      <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $activePage==='dashboard'?'active':'' ?>">
        <i class="bi bi-house-door"></i><span>Dashboard</span>
      </a>

      <div class="sidebar-section-label">Management</div>
      <a href="<?= BASE_URL ?>/admin/users.php" class="<?= $activePage==='users'?'active':'' ?>">
        <i class="bi bi-people"></i><span>Users</span>
      </a>
      <a href="<?= BASE_URL ?>/dean/faculty.php" class="<?= $activePage==='dean-faculty'?'active':'' ?>">
        <i class="bi bi-person-badge"></i><span>My Faculty</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/departments.php" class="<?= $activePage==='departments'?'active':'' ?>">
        <i class="bi bi-building"></i><span>Departments</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/periods.php" class="<?= $activePage==='periods'?'active':'' ?>">
        <i class="bi bi-calendar3"></i><span>Rating Periods</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/templates.php" class="<?= $activePage==='templates'?'active':'' ?>">
        <i class="bi bi-layout-text-window"></i><span>IPCR Templates</span>
      </a>
      <a href="<?= BASE_URL ?>/dean/approvals.php" class="<?= $activePage==='dean-approvals'?'active':'' ?>">
        <i class="bi bi-clipboard-check"></i><span>Edit Requests</span>
        <?php if ($pendingCount > 0): ?>
          <span class="badge bg-danger" style="margin-left:auto;font-size:.7rem;padding:2px 6px"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>

      <div class="sidebar-section-label">IPCR Records</div>
      <a href="<?= BASE_URL ?>/admin/ipcr-list.php" class="<?= $activePage==='ipcr-list'?'active':'' ?>">
        <i class="bi bi-list-task"></i><span>All IPCR Forms</span>
      </a>
      <a href="<?= BASE_URL ?>/admin/submission-tracking.php" class="<?= $activePage==='submission-tracking'?'active':'' ?>">
        <i class="bi bi-clipboard-check"></i><span>Submission Tracking</span>
      </a>

      <div class="sidebar-section-label">My IPCR</div>
      <a href="<?= BASE_URL ?>/faculty/ipcr-form.php" class="<?= $activePage==='ipcr-form'?'active':'' ?>">
        <i class="bi bi-file-earmark-person"></i><span>My IPCR Form</span>
      </a>

      <div class="sidebar-section-label">Evaluation</div>
      <a href="<?= BASE_URL ?>/dean/review-queue.php" class="<?= $activePage==='review-queue'?'active':'' ?>">
        <i class="bi bi-card-checklist"></i><span>Review Queue</span>
      </a>
      <a href="<?= BASE_URL ?>/dean/reports.php" class="<?= $activePage==='dean-reports'?'active':'' ?>">
        <i class="bi bi-bar-chart"></i><span>Reports</span>
      </a>

      <div class="sidebar-section-label">System</div>
      <a href="<?= BASE_URL ?>/admin/audit.php" class="<?= $activePage==='audit'?'active':'' ?>">
        <i class="bi bi-journal-text"></i><span>Audit Log</span>
      </a>

    <?php else: ?>
      <div class="sidebar-section-label">My IPCR</div>
      <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $activePage==='dashboard'?'active':'' ?>">
        <i class="bi bi-house-door"></i><span>Dashboard</span>
      </a>
      <a href="<?= BASE_URL ?>/faculty/ipcr-form.php" class="<?= $activePage==='ipcr-form'?'active':'' ?>">
        <i class="bi bi-file-earmark-person"></i><span>My IPCR Form</span>
      </a>
      <a href="<?= BASE_URL ?>/faculty/history.php" class="<?= $activePage==='history'?'active':'' ?>">
        <i class="bi bi-clock-history"></i><span>Evaluation History</span>
      </a>
    <?php endif; ?>

    <div class="sidebar-section-label">Account</div>
    <a href="<?= BASE_URL ?>/profile.php" class="<?= $activePage==='profile'?'active':'' ?>">
      <i class="bi bi-person-circle"></i><span>My Profile</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div style="font-weight:600;color:var(--gray-600);font-size:.72rem">© <?= date('Y') ?> ISU — Ilagan Campus</div>
    <div style="margin-top:2px;font-size:.63rem">University for People, Nature,<br>Entrepreneurship &amp; Innovation</div>
  </div>
</nav>

<!-- ══ MAIN ════════════════════════════════════════════════ -->
<div id="main-wrapper">
  <main id="page-content">
<?php // Page content starts here ?>

<?php
require_once '../config/database.php';
require_once '../config/auth.php';

// Admin or Secretary only
if (!isLoggedIn() || !in_array($_SESSION['user']['role'], ['admin', 'secretary'])) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$msg = '';
$msgType = '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email'])) {
    $userId = $_POST['user_id'];
    $personalEmail = trim($_POST['personal_email']);
    
    if (!empty($personalEmail) && filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $db->prepare("UPDATE users SET personal_email = ? WHERE id = ?");
            $stmt->execute([$personalEmail, $userId]);
            $msg = "✅ Personal email updated successfully!";
            $msgType = "success";
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
            $msgType = "danger";
        }
    } else {
        $msg = "❌ Please enter a valid email address";
        $msgType = "danger";
    }
}

// Handle bulk clear
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
    try {
        $db->exec("UPDATE users SET personal_email = NULL");
        $msg = "✅ All personal emails cleared!";
        $msgType = "success";
    } catch (Exception $e) {
        $msg = "❌ Error: " . $e->getMessage();
        $msgType = "danger";
    }
}

// Get users
$users = $db->query("
    SELECT 
        id,
        employee_id,
        CONCAT(first_name, ' ', last_name) as full_name,
        email,
        personal_email,
        department,
        position,
        role
    FROM users
    WHERE role IN ('dean', 'secretary', 'faculty')
    ORDER BY 
        CASE role
            WHEN 'dean' THEN 1
            WHEN 'secretary' THEN 2
            WHEN 'faculty' THEN 3
        END,
        department,
        last_name
")->fetchAll();

// Statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN personal_email IS NOT NULL THEN 1 ELSE 0 END) as with_email,
        SUM(CASE WHEN personal_email IS NULL THEN 1 ELSE 0 END) as without_email
    FROM users
    WHERE role IN ('dean', 'secretary', 'faculty')
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Personal Emails - ISU IPCR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .user-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.3s;
        }
        .user-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .user-card.has-email {
            border-left-color: #198754;
        }
        .user-card.no-email {
            border-left-color: #dc3545;
        }
        .email-input {
            font-size: 0.9rem;
        }
        .stat-card {
            border-radius: 10px;
            padding: 1.5rem;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="users.php">
                <i class="bi bi-arrow-left me-2"></i>Back to Users
            </a>
            <span class="text-white">
                <i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($_SESSION['user']['name']) ?>
            </span>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="bi bi-envelope-at me-2"></i>Update Personal Emails</h2>
                <p class="text-muted">Add personal emails (Gmail, Yahoo, etc.) for sending login credentials</p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card bg-primary text-white shadow-sm">
                    <h3><?= $stats['total'] ?></h3>
                    <p class="mb-0"><i class="bi bi-people me-2"></i>Total Users</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-success text-white shadow-sm">
                    <h3><?= $stats['with_email'] ?></h3>
                    <p class="mb-0"><i class="bi bi-check-circle me-2"></i>With Personal Email</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-danger text-white shadow-sm">
                    <h3><?= $stats['without_email'] ?></h3>
                    <p class="mb-0"><i class="bi bi-x-circle me-2"></i>Without Personal Email</p>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-lightning me-2"></i>Bulk Actions</h5>
                <form method="POST" onsubmit="return confirm('Clear all personal emails? This cannot be undone.')">
                    <button type="submit" name="clear_all" class="btn btn-outline-danger">
                        <i class="bi bi-trash me-2"></i>Clear All Personal Emails
                    </button>
                </form>
            </div>
        </div>

        <!-- User List -->
        <?php 
        $currentDept = '';
        foreach ($users as $user): 
            // Department header
            if ($currentDept !== $user['department']) {
                if ($currentDept !== '') echo '</div>'; // Close previous section
                $currentDept = $user['department'];
                echo '<div class="mb-4">';
                echo '<h4 class="border-bottom pb-2 mb-3">' . htmlspecialchars($currentDept ?: 'Administration') . '</h4>';
            }
        ?>
            <div class="card user-card <?= $user['personal_email'] ? 'has-email' : 'no-email' ?> mb-3">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <h6 class="mb-1"><?= htmlspecialchars($user['full_name']) ?></h6>
                            <small class="text-muted">
                                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($user['employee_id']) ?>
                            </small><br>
                            <small class="text-muted"><?= htmlspecialchars($user['position']) ?></small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">ISU Email</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-building"></i></span>
                                <input type="text" class="form-control email-input" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <div class="col-md-8">
                                    <label class="form-label small mb-1">Personal Email (Gmail, Yahoo, etc.)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-envelope-at"></i></span>
                                        <input 
                                            type="email" 
                                            name="personal_email" 
                                            class="form-control email-input" 
                                            value="<?= htmlspecialchars($user['personal_email'] ?? '') ?>"
                                            placeholder="personal@gmail.com"
                                        >
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" name="update_email" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-save me-1"></i>Update
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div> <!-- Close last section -->

        <!-- Instructions -->
        <div class="card bg-light mt-4">
            <div class="card-body">
                <h5><i class="bi bi-info-circle me-2"></i>How It Works</h5>
                <ol class="mb-0">
                    <li><strong>Add personal emails</strong> for each user (Gmail, Yahoo, Outlook, etc.)</li>
                    <li>When users use <strong>Forgot Password</strong>, credentials will be sent to their personal email</li>
                    <li>If no personal email is set, credentials will be sent to their ISU email</li>
                    <li>Personal emails are <strong>private</strong> and only visible to admins/secretaries</li>
                </ol>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

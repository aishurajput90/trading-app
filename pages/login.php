<?php
// ============================================================
// Login Page — standalone layout (no sidebar)
// ============================================================
require_once '../config/db.php';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error    = '';
$success  = '';
$redirect = $_GET['redirect'] ?? '../index.php';

// Show success message after password reset or registration
if (isset($_GET['reset'])) $success = 'Password updated successfully! Please log in.';
if (isset($_GET['logged_out'])) $success = 'You have been logged out.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = getDB()->prepare("SELECT id, name, password, currency FROM users WHERE email = ?");
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password'])) {
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['user_id']       = (int)$row['id'];
            $_SESSION['user_name']     = $row['name'];
            $_SESSION['user_currency'] = $row['currency'] ?? 'USD';
            // Track login
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            getDB()->prepare("UPDATE users SET last_login_at = NOW(), last_active_at = NOW(), login_count = login_count + 1 WHERE id = ?")
                ->execute([$row['id']]);
            // Redirect to intended page (sanitize to relative paths only)
            $dest = $redirect;
            if (!str_starts_with($dest, '/') && !str_starts_with($dest, '../') && !str_starts_with($dest, 'http')) {
                $dest = '../index.php';
            }
            header('Location: ' . $dest);
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css?v=1.0.2" rel="stylesheet">
    <script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-body); }
        .auth-card { width: 100%; max-width: 420px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem; box-shadow: 0 8px 40px rgba(0,0,0,.15); }
        .auth-brand { text-align: center; margin-bottom: 2rem; }
        .auth-brand .brand-icon { width: 52px; height: 52px; background: linear-gradient(135deg,var(--accent),#7c3aed); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .auth-brand .brand-icon svg { width: 26px; height: 26px; stroke: #fff; }
        .auth-brand h4 { font-weight: 700; font-size: 1.4rem; margin: 0 0 4px; color: var(--text-primary); }
        .auth-brand p { color: var(--text-muted); font-size: .85rem; margin: 0; }
        .auth-card .form-label { font-weight: 600; font-size: .82rem; color: var(--text-secondary); }
        .auth-card .form-control { background: var(--bg-hover); border-color: var(--border); color: var(--text-primary); }
        .auth-card .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.15); background: var(--bg-hover); color: var(--text-primary); }
        .auth-btn { width: 100%; padding: .7rem; font-weight: 600; font-size: .95rem; border-radius: 10px; background: linear-gradient(135deg,var(--accent),#7c3aed); border: none; color: #fff; margin-top: .5rem; }
        .auth-btn:hover { opacity: .9; }
        .auth-links { text-align: center; margin-top: 1.25rem; font-size: .85rem; color: var(--text-muted); }
        .auth-links a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .auth-links a:hover { text-decoration: underline; }
        .theme-btn { position: fixed; top: 16px; right: 16px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; cursor: pointer; color: var(--text-secondary); font-size: .8rem; }
    </style>
</head>
<body>
<button class="theme-btn" id="themeBtn" onclick="toggleTheme()"><i class="fas fa-moon" id="themeIco"></i></button>

<div class="auth-card">
    <div class="auth-brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                <polyline points="16 7 22 7 22 13"></polyline>
            </svg>
        </div>
        <h4><?= APP_NAME ?></h4>
        <p>Risk-first trading journal</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-exclamation me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-check me-1"></i><?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        </div>

        <div class="mb-3">
            <label class="form-label d-flex justify-content-between">
                Password
                <a href="forgot_password.php" style="font-weight:500;color:var(--accent);font-size:.8rem">Forgot password?</a>
            </label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>

        <button type="submit" class="auth-btn">
            <i class="fas fa-right-to-bracket me-2"></i>Sign In
        </button>
    </form>

    <div class="auth-links">
        Don't have an account? <a href="register.php">Create one free</a>
    </div>
</div>

<script>
function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-theme');
    var next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('dos_theme', next);
    document.getElementById('themeIco').className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
// Set correct icon on load
(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.getElementById('themeIco').className = t==='dark'?'fas fa-sun':'fas fa-moon'; })();
</script>
</body>
</html>

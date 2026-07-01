<?php
// ============================================================
// Register Page — standalone layout (no sidebar)
// ============================================================
require_once '../config/db.php';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error  = '';
$errors = [];
$data   = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();

    $data['name']    = trim($_POST['name'] ?? '');
    $data['email']   = strtolower(trim($_POST['email'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // --- Validation ---
    if (!$data['name'] || strlen($data['name']) < 2) {
        $errors['name'] = 'Name must be at least 2 characters.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    // --- Check email uniqueness ---
    if (empty($errors['email'])) {
        $chk = getDB()->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $chk->execute([$data['email']]);
        if ((int)$chk->fetchColumn() > 0) {
            $errors['email'] = 'This email is already registered. Please log in.';
        }
    }

    // --- Create account (initial_balance defaults to 10000 via DB) ---
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins  = getDB()->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $ins->execute([$data['name'], $data['email'], $hash]);
        $newId = (int)getDB()->lastInsertId();

        // Track signup IP + first login
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        getDB()->prepare("UPDATE users SET signup_ip = ?, last_login_at = NOW(), last_active_at = NOW(), login_count = 1 WHERE id = ?")
            ->execute([$ip, $newId]);

        // Auto-login
        session_regenerate_id(true);
        $_SESSION['user_id']   = $newId;
        $_SESSION['user_name'] = $data['name'];
        header('Location: ../index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Create Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css?v=1.0.2" rel="stylesheet">
    <script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-body); padding: 1rem; }
        .auth-card { width: 100%; max-width: 460px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem; box-shadow: 0 8px 40px rgba(0,0,0,.15); }
        .auth-brand { text-align: center; margin-bottom: 2rem; }
        .auth-brand .brand-icon { width: 52px; height: 52px; background: linear-gradient(135deg,var(--accent),#7c3aed); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .auth-brand .brand-icon svg { width: 26px; height: 26px; stroke: #fff; }
        .auth-brand h4 { font-weight: 700; font-size: 1.4rem; margin: 0 0 4px; color: var(--text-primary); }
        .auth-brand p { color: var(--text-muted); font-size: .85rem; margin: 0; }
        .auth-card .form-label { font-weight: 600; font-size: .82rem; color: var(--text-secondary); }
        .auth-card .form-control { background: var(--bg-hover); border-color: var(--border); color: var(--text-primary); }
        .auth-card .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.15); background: var(--bg-hover); color: var(--text-primary); }
        .auth-card .form-control.is-invalid { border-color: #ef4444; }
        .invalid-feedback { font-size: .78rem; }
        .auth-btn { width: 100%; padding: .7rem; font-weight: 600; font-size: .95rem; border-radius: 10px; background: linear-gradient(135deg,var(--accent),#7c3aed); border: none; color: #fff; margin-top: .5rem; }
        .auth-btn:hover { opacity: .9; }
        .auth-links { text-align: center; margin-top: 1.25rem; font-size: .85rem; color: var(--text-muted); }
        .auth-links a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .auth-links a:hover { text-decoration: underline; }
        .theme-btn { position: fixed; top: 16px; right: 16px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; cursor: pointer; color: var(--text-secondary); font-size: .8rem; }
        .input-hint { font-size: .75rem; color: var(--text-muted); margin-top: 3px; }
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
        <h4>Create your account</h4>
        <p>Start tracking your trades with <?= APP_NAME ?></p>
    </div>

    <form method="POST" novalidate>
        <?= csrfField() ?>

        <div class="mb-3">
            <label class="form-label">Your Name</label>
            <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   placeholder="e.g. Rahul Sharma"
                   value="<?= htmlspecialchars($data['name']) ?>" required autofocus>
            <?php if (isset($errors['name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($data['email']) ?>" required>
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                   placeholder="Min. 8 characters" required>
            <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="password_confirm" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                   placeholder="Repeat your password" required>
            <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
            <?php endif; ?>
        </div>

        <button type="submit" class="auth-btn">
            <i class="fas fa-user-plus me-2"></i>Create Account
        </button>
    </form>

    <div class="auth-links">
        Already have an account? <a href="login.php">Sign in</a>
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
(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.getElementById('themeIco').className = t==='dark'?'fas fa-sun':'fas fa-moon'; })();
</script>
</body>
</html>

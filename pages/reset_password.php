<?php
// ============================================================
// Reset Password — validate token + set new password
// ============================================================
require_once '../config/db.php';

if (!empty($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;

// Validate token
$tokenRow = null;
if ($token) {
    $stmt = getDB()->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();
}

if (!$tokenRow && $token) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();

    $postToken  = trim($_POST['token'] ?? '');
    $password   = $_POST['password'] ?? '';
    $passConf   = $_POST['password_confirm'] ?? '';

    // Re-validate token from POST
    $stmt2 = getDB()->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()");
    $stmt2->execute([$postToken]);
    $tokenRow2 = $stmt2->fetch();

    if (!$tokenRow2) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passConf) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hash = password_hash($password, PASSWORD_BCRYPT);
        getDB()->prepare("UPDATE users SET password = ? WHERE email = ?")
            ->execute([$hash, $tokenRow2['email']]);

        // Mark token as used
        getDB()->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
            ->execute([$postToken]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Reset Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css?v=1.0.2" rel="stylesheet">
    <script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg-body); }
        .auth-card { width: 100%; max-width: 420px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem; box-shadow: 0 8px 40px rgba(0,0,0,.15); }
        .auth-brand { text-align: center; margin-bottom: 2rem; }
        .auth-brand .brand-icon { width: 52px; height: 52px; background: linear-gradient(135deg,var(--accent),#7c3aed); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .auth-brand .brand-icon i { font-size: 22px; color: #fff; }
        .auth-brand h4 { font-weight: 700; font-size: 1.3rem; margin: 0 0 4px; color: var(--text-primary); }
        .auth-card .form-label { font-weight: 600; font-size: .82rem; color: var(--text-secondary); }
        .auth-card .form-control { background: var(--bg-hover); border-color: var(--border); color: var(--text-primary); }
        .auth-card .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.15); background: var(--bg-hover); color: var(--text-primary); }
        .auth-btn { width: 100%; padding: .7rem; font-weight: 600; font-size: .95rem; border-radius: 10px; background: linear-gradient(135deg,var(--accent),#7c3aed); border: none; color: #fff; margin-top: .5rem; }
        .auth-links { text-align: center; margin-top: 1.25rem; font-size: .85rem; color: var(--text-muted); }
        .auth-links a { color: var(--accent); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-brand">
        <div class="brand-icon"><i class="fas fa-key"></i></div>
        <h4>Set New Password</h4>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-check me-1"></i>
        Password updated successfully!
    </div>
    <div class="auth-links"><a href="login.php?reset=1"><i class="fas fa-right-to-bracket me-1"></i>Sign in with new password</a></div>

    <?php elseif ($error && !$tokenRow): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-exclamation me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <div class="auth-links"><a href="forgot_password.php">Request a new reset link</a></div>

    <?php else: ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-exclamation me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="password" class="form-control"
                   placeholder="Min. 8 characters" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="password_confirm" class="form-control"
                   placeholder="Repeat password" required>
        </div>
        <button type="submit" class="auth-btn">
            <i class="fas fa-lock me-2"></i>Update Password
        </button>
    </form>
    <?php endif; ?>
</div>
<script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
</body>
</html>

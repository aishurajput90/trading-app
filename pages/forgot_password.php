<?php
// ============================================================
// Forgot Password — request a reset link
// ============================================================
require_once '../config/db.php';

if (!empty($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();

    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Always show the same message to prevent user enumeration
        $submitted = true;

        // Check if user exists
        $stmt = getDB()->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Delete any existing unused tokens for this email
            getDB()->prepare("DELETE FROM password_resets WHERE email = ? AND used = 0")
                ->execute([$email]);

            // Generate secure token
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            getDB()->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$email, $token, $expires]);

            $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . dirname($_SERVER['REQUEST_URI']) . '/reset_password.php?token=' . $token;

            if (defined('APP_DEV') && APP_DEV) {
                // Dev mode: show link on screen
                $devLink = $resetUrl;
            } else {
                // Production: send email
                $subject = APP_NAME . ' — Password Reset';
                $body    = "Hello,\n\nClick the link below to reset your password (valid for 1 hour):\n\n$resetUrl\n\nIf you did not request this, you can ignore this email.\n\n— " . APP_NAME;
                mail($email, $subject, $body, 'From: noreply@' . $_SERVER['HTTP_HOST']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Forgot Password</title>
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
        .auth-brand p { color: var(--text-muted); font-size: .85rem; margin: 0; }
        .auth-card .form-label { font-weight: 600; font-size: .82rem; color: var(--text-secondary); }
        .auth-card .form-control { background: var(--bg-hover); border-color: var(--border); color: var(--text-primary); }
        .auth-card .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.15); background: var(--bg-hover); color: var(--text-primary); }
        .auth-btn { width: 100%; padding: .7rem; font-weight: 600; font-size: .95rem; border-radius: 10px; background: linear-gradient(135deg,var(--accent),#7c3aed); border: none; color: #fff; margin-top: .5rem; }
        .auth-links { text-align: center; margin-top: 1.25rem; font-size: .85rem; color: var(--text-muted); }
        .auth-links a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .dev-box { background: rgba(234,179,8,.08); border: 1px solid rgba(234,179,8,.3); border-radius: 10px; padding: 1rem; margin-top: 1rem; font-size: .82rem; }
        .dev-box code { word-break: break-all; color: var(--accent); font-size: .78rem; }
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-brand">
        <div class="brand-icon"><i class="fas fa-lock"></i></div>
        <h4>Forgot your password?</h4>
        <p>Enter your email and we'll send you a reset link</p>
    </div>

    <?php if ($submitted): ?>
    <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-check me-1"></i>
        If that email is registered, a reset link has been sent. Check your inbox.
    </div>

    <?php if (isset($devLink)): ?>
    <div class="dev-box">
        <strong><i class="fas fa-code me-1" style="color:#eab308"></i>Dev Mode — Reset Link:</strong><br>
        <a href="<?= htmlspecialchars($devLink) ?>" style="color:var(--accent)">
            <code><?= htmlspecialchars($devLink) ?></code>
        </a>
        <div style="margin-top:.5rem;font-size:.75rem;color:var(--text-muted)">This is shown because <code>APP_DEV = true</code> in config/db.php. Set it to <code>false</code> in production.</div>
    </div>
    <?php endif; ?>

    <?php elseif ($error): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.87rem;border-radius:8px;">
        <i class="fas fa-circle-exclamation me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (!$submitted): ?>
    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control"
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        </div>
        <button type="submit" class="auth-btn">
            <i class="fas fa-paper-plane me-2"></i>Send Reset Link
        </button>
    </form>
    <?php endif; ?>

    <div class="auth-links">
        <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to login</a>
    </div>
</div>
<script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
</body>
</html>

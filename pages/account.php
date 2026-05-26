<?php
// ============================================================
// Account Settings — profile, password change
// ============================================================
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();

$user    = getLoggedInUser();
$msg     = '';
$msgType = '';
$errors  = [];

// ── Handle Profile Update ────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'update_profile') {
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!$name || strlen($name) < 2)               $errors['name']  = 'Name must be at least 2 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email address.';

    // Check email uniqueness (excluding current user)
    if (empty($errors['email'])) {
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $userId]);
        if ((int)$chk->fetchColumn() > 0) $errors['email'] = 'That email is already taken.';
    }

    if (empty($errors)) {
        $db->prepare("UPDATE users SET name=?, email=? WHERE id=?")
            ->execute([$name, $email, $userId]);
        // Refresh session name
        $_SESSION['user_name'] = $name;
        // Bust the static cache in getLoggedInUser()
        $user = array_merge($user, ['name' => $name, 'email' => $email]);
        $msg  = 'Profile updated successfully.';
        $msgType = 'success';
    } else {
        $msg     = 'Please fix the errors below.';
        $msgType = 'danger';
    }
}

// ── Handle Password Change ───────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'change_password') {
    $currentPw  = $_POST['current_password'] ?? '';
    $newPw      = $_POST['new_password'] ?? '';
    $newPwConf  = $_POST['new_password_confirm'] ?? '';

    // Load current hash
    $pwRow = $db->prepare("SELECT password FROM users WHERE id = ?");
    $pwRow->execute([$userId]);
    $hash  = $pwRow->fetchColumn();

    if (!$hash || !password_verify($currentPw, $hash)) {
        $errors['current_password'] = 'Current password is incorrect.';
    }
    if (strlen($newPw) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters.';
    }
    if ($newPw !== $newPwConf) {
        $errors['new_password_confirm'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($newPw, PASSWORD_BCRYPT), $userId]);
        $msg     = 'Password changed successfully.';
        $msgType = 'success';
    } else {
        $msg     = 'Please fix the errors below.';
        $msgType = 'danger';
    }
}

// ── Trade stats for the account summary ─────────────────────────────────────
$statsRow = $db->prepare("SELECT COUNT(*) as tc, COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl FROM trades WHERE user_id=?");
$statsRow->execute([$userId]);
$stats = $statsRow->fetch();

$pageTitle = 'Account Settings';
$rootPath  = '../';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h2 class="page-heading"><i class="fas fa-user-gear me-2" style="color:var(--accent)"></i>Account Settings</h2>
        <p class="page-subheading">Manage your profile and security</p>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-1"></i>
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Profile Info ───────────────────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-user me-2" style="color:var(--accent)"></i>Profile Information
            </div>
            <div style="padding:1.5rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem">Full Name</label>
                        <input type="text" name="name"
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem">Email Address</label>
                        <input type="email" name="email"
                               class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary" style="border-radius:8px;font-weight:600">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Change Password ───────────────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-lock me-2" style="color:var(--accent)"></i>Change Password
            </div>
            <div style="padding:1.5rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem">Current Password</label>
                        <input type="password" name="current_password"
                               class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>"
                               placeholder="••••••••" required>
                        <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['current_password']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem">New Password</label>
                        <input type="password" name="new_password"
                               class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                               placeholder="Min. 8 characters" required>
                        <?php if (isset($errors['new_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['new_password']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem">Confirm New Password</label>
                        <input type="password" name="new_password_confirm"
                               class="form-control <?= isset($errors['new_password_confirm']) ? 'is-invalid' : '' ?>"
                               placeholder="Repeat new password" required>
                        <?php if (isset($errors['new_password_confirm'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['new_password_confirm']) ?></div><?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-warning" style="border-radius:8px;font-weight:600">
                        <i class="fas fa-key me-1"></i>Change Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Account Summary -->
        <div class="card-custom mt-4">
            <div class="card-header-custom">
                <i class="fas fa-chart-bar me-2" style="color:var(--accent)"></i>Account Summary
            </div>
            <div style="padding:1.25rem">
                <div class="row g-3">
                    <div class="col-6">
                        <div style="background:var(--bg-hover);border-radius:10px;padding:.8rem 1rem;text-align:center">
                            <div style="font-size:1.4rem;font-weight:700;color:var(--text-primary)"><?= number_format($stats['tc']) ?></div>
                            <div style="font-size:.75rem;color:var(--text-muted)">Total Trades</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background:var(--bg-hover);border-radius:10px;padding:.8rem 1rem;text-align:center">
                            <?php $netPl = (float)$stats['net_pl']; ?>
                            <div style="font-size:1.4rem;font-weight:700;color:<?= $netPl >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                                <?= formatPL($netPl) ?>
                            </div>
                            <div style="font-size:.75rem;color:var(--text-muted)">Net P/L</div>
                        </div>
                    </div>
                </div>
                <div style="margin-top:1rem;font-size:.8rem;color:var(--text-muted)">
                    <i class="fas fa-calendar me-1"></i>Member since <?= date('d M Y', strtotime($user['created_at'] ?? 'now')) ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>

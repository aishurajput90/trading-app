<?php
// ============================================================
// Authentication & Session Middleware
// Included automatically via config/db.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,         // session cookie — expires on browser close
        'path'     => '/',
        'secure'   => false,     // set true in production (HTTPS)
        'httponly' => true,      // JS cannot read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Redirect to login if the user is not authenticated.
 * Preserves the intended URL as ?redirect= so the user lands back after login.
 *
 * @param string $rootPath  Relative path prefix from the current page to the app root.
 *                          Use '' for index.php (root level), '../' for pages/ (default).
 */
function requireLogin(string $rootPath = '../'): void {
    if (empty($_SESSION['user_id'])) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $rootPath . 'pages/login.php' . ($redirect ? '?redirect=' . $redirect : ''));
        exit;
    }
    // Update last_active_at at most once per 5 minutes (avoid DB hit every page load)
    $lastPing = $_SESSION['_last_active_ping'] ?? 0;
    if (time() - $lastPing > 300) {
        getDB()->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
        $_SESSION['_last_active_ping'] = time();
    }
}

/**
 * Get the currently authenticated user's ID.
 */
function getCurrentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Get the full row of the logged-in user (cached per request).
 * Returns empty array if not logged in.
 */
function getLoggedInUser(): array {
    static $user = null;
    if ($user === null && !empty($_SESSION['user_id'])) {
        $stmt = getDB()->prepare("SELECT id, name, email, initial_balance, created_at, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: [];
    }
    return $user ?? [];
}

/**
 * Returns true if the currently logged-in user is a super admin.
 */
function isAdmin(): bool {
    $user = getLoggedInUser();
    return ($user['role'] ?? '') === 'admin';
}

// ---- CSRF Protection ----

/**
 * Generate (or retrieve) the per-session CSRF token.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token using timing-safe comparison.
 */
function verifyCsrfToken(string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a hidden CSRF input field — echo inside any POST form.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate the CSRF token on a POST request. Terminates with 403 on failure.
 */
function validateCsrfOrDie(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        die('<div style="font-family:monospace;background:#0a0e1a;color:#ef4444;padding:30px;margin:20px;border-radius:12px;border:1px solid #ef4444;"><h2>⚠ Security Error</h2><p>CSRF token validation failed. Please go back and try again.</p><a href="javascript:history.back()" style="color:#60a5fa">← Go Back</a></div>');
    }
}

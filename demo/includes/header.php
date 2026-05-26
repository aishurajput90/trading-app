<?php
if (!defined('DB_NAME')) require_once dirname(__DIR__).'/config/db.php';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$demoAcc     = getDemoAccount();
$demoBalance = (float)($demoAcc['current_balance'] ?? 0);
$startBal    = (float)($demoAcc['starting_balance'] ?? 10000);
$totalPnL    = $demoBalance - $startBal;
$allAccounts = getDB()->prepare("SELECT * FROM demo_accounts WHERE user_id=? ORDER BY id");
$allAccounts->execute([DEMO_USER_ID]);
$allAccounts = $allAccounts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= DEMO_APP_NAME ?> — <?= $pageTitle ?? ucfirst($currentPage) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?= $rootPath ?? '' ?>assets/css/style.css" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('dos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon" style="background:linear-gradient(135deg,#7c3aed,#2563eb)">
            <i class="fas fa-flask-vial" style="font-size:16px;color:#fff"></i>
        </div>
        <div class="brand-text">
            <span class="brand-name">Demo Trading</span>
            <span class="brand-sub">Practice Mode</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= $rootPath??'' ?>index.php" class="nav-item <?= $currentPage==='index'?'active':'' ?>">
            <i class="fas fa-gauge-high"></i><span>Dashboard</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/trade.php" class="nav-item <?= $currentPage==='trade'?'active':'' ?>">
            <i class="fas fa-plus-circle"></i><span>New Trade</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/open.php" class="nav-item <?= $currentPage==='open'?'active':'' ?>">
            <i class="fas fa-door-open"></i><span>Open Positions</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/journal.php" class="nav-item <?= $currentPage==='journal'?'active':'' ?>">
            <i class="fas fa-book-open"></i><span>Trade Journal</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/review.php" class="nav-item <?= $currentPage==='review'?'active':'' ?>">
            <i class="fas fa-magnifying-glass-chart"></i><span>Performance Review</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/rules.php" class="nav-item <?= $currentPage==='rules'?'active':'' ?>">
            <i class="fas fa-list-check"></i><span>Trading Rules</span>
        </a>
        <a href="<?= $rootPath??'' ?>pages/accounts.php" class="nav-item <?= $currentPage==='accounts'?'active':'' ?>">
            <i class="fas fa-layer-group"></i><span>Accounts</span>
        </a>
        <div style="margin:12px 16px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <a href="<?= $rootPath??'' ?>../index.php" class="nav-item" style="opacity:.6">
            <i class="fas fa-globe"></i><span>Live Forex Panel</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="balance-widget">
            <div class="bw-label">Demo Balance</div>
            <div class="bw-amount" style="color:<?= $totalPnL >= 0 ? 'var(--profit)' : 'var(--loss)' ?>"><?= fmtD($demoBalance) ?></div>
        </div>
        <div style="font-size:11px;padding:6px 12px;color:<?= $totalPnL >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
            <?= fmtDPL($totalPnL) ?> since start
        </div>
        <div style="font-size:10px;padding:0 12px 8px;color:var(--text-muted)">
            <?= htmlspecialchars($demoAcc['name'] ?? 'No account') ?>
        </div>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>

<div class="main-wrapper" id="mainWrapper">
    <header class="top-bar">
        <div class="topbar-left">
            <button class="btn-icon" id="desktopToggle"><i class="fas fa-bars"></i></button>
            <h4 class="page-title">
                <?php $titles=['index'=>'Dashboard','trade'=>'New Trade','open'=>'Open Positions',
                    'journal'=>'Trade Journal','review'=>'Performance Review','rules'=>'Trading Rules','accounts'=>'Accounts'];
                echo $titles[$currentPage] ?? 'Demo Trading'; ?>
            </h4>
        </div>
        <div class="topbar-right">
            <span class="date-badge" style="background:rgba(124,58,237,.1);color:var(--accent-purple);border:1px solid rgba(124,58,237,.3)">
                <i class="fas fa-flask-vial" style="font-size:10px"></i> DEMO MODE
            </span>

            <!-- Account switcher -->
            <?php if (count($allAccounts) > 1): ?>
            <select id="accountSwitcher" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:5px 10px;font-size:12px;cursor:pointer"
                onchange="window.location.href='<?= $rootPath??'' ?>pages/accounts.php?switch='+this.value">
                <?php foreach ($allAccounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id']==getDemoAccountId()?'selected':'' ?>>
                    <?= htmlspecialchars($a['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <div class="theme-toggle" id="themeToggle">
                <i class="fas fa-sun" id="themeIconSun"></i>
                <div class="toggle-track"><div class="toggle-thumb" id="toggleThumb"></div></div>
                <i class="fas fa-moon" id="themeIconMoon"></i>
            </div>
            <div class="user-chip">
                <div class="user-avatar" style="background:linear-gradient(135deg,#7c3aed,#2563eb)">DT</div>
                <span>Demo Trader</span>
            </div>
        </div>
    </header>
    <div class="page-content">

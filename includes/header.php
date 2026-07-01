<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — <?= isset($pageTitle) ? $pageTitle : ucfirst($currentPage) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?= $rootPath ?? '' ?>assets/css/style.css?v=1.0.2" rel="stylesheet">
    <script>
        // Apply theme before paint to avoid flash
        (function(){
            var t = localStorage.getItem('dos_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
        window.APP_CS = <?= json_encode(getActiveCurrency()['symbol']) ?>;
    </script>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= APP_NAME ?></span>
            <span class="brand-sub">Risk Manager</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= $rootPath ?? '' ?>index.php" class="nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
            <i class="fas fa-gauge-high"></i><span>Dashboard</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/journal.php" class="nav-item <?= $currentPage === 'journal' ? 'active' : '' ?>">
            <i class="fas fa-book-open"></i><span>Trade Journal</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/calendar.php" class="nav-item <?= $currentPage === 'calendar' ? 'active' : '' ?>">
            <i class="fas fa-calendar-days"></i><span>Calendar</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/funds.php" class="nav-item <?= $currentPage === 'funds' ? 'active' : '' ?>">
            <i class="fas fa-wallet"></i><span>Fund Manager</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/reports.php" class="nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i><span>Reports</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/analysis.php" class="nav-item <?= $currentPage === 'analysis' ? 'active' : '' ?>">
            <i class="fas fa-magnifying-glass-chart"></i><span>Analyzer</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/patterns.php" class="nav-item <?= $currentPage === 'patterns' ? 'active' : '' ?>">
            <i class="fas fa-fingerprint"></i><span>Pattern Analysis</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/cycles.php" class="nav-item <?= $currentPage === 'cycles' ? 'active' : '' ?>">
            <i class="fas fa-rotate"></i><span>Capital Cycles</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/import.php" class="nav-item <?= $currentPage === 'import' ? 'active' : '' ?>">
            <i class="fas fa-file-import"></i><span>CSV Import</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/broker_mapper.php" class="nav-item <?= $currentPage === 'broker_mapper' ? 'active' : '' ?>">
            <i class="fas fa-sliders"></i><span>Broker Profiles</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/zerodha_import.php" class="nav-item <?= $currentPage === 'zerodha_import' ? 'active' : '' ?>">
            <i class="fas fa-z"></i><span>Zerodha Import</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/brokerage.php" class="nav-item <?= $currentPage === 'brokerage' ? 'active' : '' ?>">
            <i class="fas fa-hand-holding-dollar"></i><span>Brokerage</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/risk_settings.php" class="nav-item <?= $currentPage === 'risk_settings' ? 'active' : '' ?>">
            <i class="fas fa-shield-halved"></i><span>Risk Settings</span>
        </a>
        <div style="margin:12px 16px 4px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;padding:4px 20px 2px">Coaching</div>
        <a href="<?= $rootPath ?? '' ?>pages/coach.php" class="nav-item <?= $currentPage === 'coach' ? 'active' : '' ?>" style="<?= $currentPage === 'coach' ? '' : 'opacity:.92' ?>">
            <i class="fas fa-brain"></i><span>Coach Dashboard</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/pre_checklist.php" class="nav-item <?= $currentPage === 'pre_checklist' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-check"></i><span>Pre-Trade Check</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/post_analysis.php" class="nav-item <?= $currentPage === 'post_analysis' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i><span>Post-Trade Review</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/coaching_guide.php" class="nav-item <?= $currentPage === 'coaching_guide' ? 'active' : '' ?>">
            <i class="fas fa-book-open-reader"></i><span>Improvement Guide</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/pov.php" class="nav-item <?= $currentPage === 'pov' ? 'active' : '' ?>">
            <i class="fas fa-crosshairs"></i><span>POV Tracker</span>
        </a>
        <div style="margin:12px 16px 4px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;padding:4px 20px 2px">Challenge</div>
        <a href="<?= $rootPath ?? '' ?>pages/challenge.php" class="nav-item <?= in_array($currentPage, ['challenge','challenge_day','challenge_report']) ? 'active' : '' ?>">
            <i class="fas fa-trophy"></i><span>Discipline Challenge</span>
        </a>
        <div style="margin:12px 16px 4px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;padding:4px 20px 2px">Psychology</div>
        <a href="<?= $rootPath ?? '' ?>pages/psych_tracker.php" class="nav-item <?= in_array($currentPage, ['psych_tracker']) ? 'active' : '' ?>">
            <i class="fas fa-head-side-brain"></i><span>Habit Tracker</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/psych_daily.php" class="nav-item <?= $currentPage === 'psych_daily' ? 'active' : '' ?>">
            <i class="fas fa-file-pen"></i><span>Daily Entry</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/psych_analytics.php" class="nav-item <?= $currentPage === 'psych_analytics' ? 'active' : '' ?>">
            <i class="fas fa-brain"></i><span>Psych Analytics</span>
        </a>
        <div style="margin:12px 16px 4px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;padding:4px 20px 2px">Goals</div>
        <a href="<?= $rootPath ?? '' ?>pages/targets.php" class="nav-item <?= $currentPage === 'targets' ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i><span>Targets</span>
        </a>
        <div style="margin:12px 16px 4px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;padding:4px 20px 2px">Market Intel</div>
        <a href="<?= $rootPath ?? '' ?>pages/smartmoney.php" class="nav-item <?= $currentPage === 'smartmoney' ? 'active' : '' ?>">
            <i class="fas fa-building-columns"></i><span>Smart Money Guide</span>
        </a>
        <?php if (isAdmin()): ?>
        <div style="margin:12px 16px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.08em;color:rgba(250,204,21,.7);text-transform:uppercase;padding:4px 20px 2px">
            <i class="fas fa-shield-halved me-1" style="color:rgba(250,204,21,.7)"></i>Admin Only
        </div>
        <a href="<?= $rootPath ?? '' ?>pages/admin.php" class="nav-item <?= $currentPage === 'admin' ? 'active' : '' ?>" style="margin:2px 12px;border-radius:8px">
            <i class="fas fa-chart-line" style="color:rgba(250,204,21,.8)"></i><span style="font-weight:700">User Analytics</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>demo/index.php" class="nav-item" style="background:linear-gradient(135deg,rgba(124,58,237,.12),rgba(37,99,235,.12));border:1px solid rgba(124,58,237,.25);margin:4px 12px;border-radius:8px;padding:10px 14px">
            <i class="fas fa-flask-vial" style="color:var(--accent-purple)"></i><span style="font-weight:700">Demo Trading</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>india/index.php" class="nav-item" style="background:linear-gradient(135deg,rgba(255,153,51,.12),rgba(19,136,8,.12));border:1px solid rgba(255,153,51,.25);margin:4px 12px;border-radius:8px;padding:10px 14px">
            <span style="font-size:16px;margin-right:2px">🇮🇳</span><span style="font-weight:700">India Market</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <?php
        $balance  = getCurrentBalance();
        $weeklyDD = getWeeklyDrawdown();
        $ddClass  = $weeklyDD >= 6 ? 'danger' : ($weeklyDD >= 4 ? 'warning' : 'success');
        ?>
        <div class="balance-widget">
            <div class="bw-label">Portfolio Balance</div>
            <div class="bw-amount"><?= formatUSD($balance) ?></div>
        </div>
        <div class="dd-widget">
            <div class="dd-header">
                <span>Weekly Drawdown</span>
                <span class="badge bg-<?= $ddClass ?>"><?= $weeklyDD ?>%</span>
            </div>
            <div class="progress mt-1" style="height:5px;background:var(--border);">
                <div class="progress-bar bg-<?= $ddClass ?>" style="width:<?= min($weeklyDD/6*100, 100) ?>%"></div>
            </div>
            <small class="text-muted">Limit: 6%</small>
        </div>
        <?php if ($weeklyDD >= 6): ?>
        <div class="alert-danger-widget">
            <i class="fas fa-triangle-exclamation"></i> Weekly limit reached! Stop trading.
        </div>
        <?php endif; ?>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Mobile Toggle -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Main Content Wrapper -->
<div class="main-wrapper" id="mainWrapper">
    <header class="top-bar">
        <div class="topbar-left">
            <button class="btn-icon" id="desktopToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4 class="page-title">
                <?php
                $titles = [
                    'index'         => 'Dashboard',
                    'journal'       => 'Trade Journal',
                    'calendar'      => 'Calendar View',
                    'funds'         => 'Fund Manager',
                    'reports'       => 'Analytics & Reports',
                    'import'        => 'CSV Import',
                    'broker_mapper'   => 'Broker Profiles',
                    'zerodha_import'  => 'Zerodha Import',
                    'analysis'      => 'Trade Analyzer',
                    'patterns'      => 'Pattern Analysis',
                    'cycles'        => 'Capital Cycles',
                    'coach'          => 'Coach Dashboard',
                    'pre_checklist'  => 'Pre-Trade Checklist',
                    'post_analysis'  => 'Post-Trade Review',
                    'coaching_guide' => 'Improvement Guide',
                    'pov'            => 'POV Tracker',
                    'challenge'        => 'Discipline Challenge',
                    'challenge_day'    => 'Daily Challenge Entry',
                    'challenge_report' => 'Challenge Report',
                    'psych_tracker'    => 'Psychology Tracker',
                    'psych_daily'      => 'Daily Discipline Entry',
                    'psych_analytics'  => 'Psychology Analytics',
                    'targets'          => 'Trading Targets',
                    'risk_settings'    => 'Risk Settings',
                ];
                echo $titles[$currentPage] ?? 'Dashboard';
                ?>
            </h4>
        </div>
        <div class="topbar-right">
            <span class="date-badge">
                <i class="far fa-calendar"></i> <?= date('D, d M Y') ?>
            </span>

            <!-- Dark Mode Toggle -->
            <div class="theme-toggle" id="themeToggle" title="Toggle Dark / Light Mode">
                <i class="fas fa-sun" id="themeIconSun"></i>
                <div class="toggle-track">
                    <div class="toggle-thumb" id="toggleThumb"></div>
                </div>
                <i class="fas fa-moon" id="themeIconMoon"></i>
            </div>

            <?php
            $__loggedUser = getLoggedInUser();
            $__uname      = $__loggedUser['name'] ?? ($_SESSION['user_name'] ?? 'User');
            $__words      = array_filter(explode(' ', $__uname));
            $__initials   = strtoupper(implode('', array_map(fn($w) => $w[0], $__words)));
            $__initials   = substr($__initials, 0, 2);
            ?>
            <div class="user-chip" style="cursor:pointer" title="Account Settings"
                 onclick="window.location='<?= $rootPath ?? '' ?>pages/account.php'">
                <div class="user-avatar"><?= htmlspecialchars($__initials) ?></div>
                <span><?= htmlspecialchars($__uname) ?></span>
            </div>
            <a href="<?= $rootPath ?? '' ?>pages/logout.php"
               class="btn-icon ms-1" title="Logout"
               onclick="return confirm('Log out of <?= APP_NAME ?>?')">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <div class="page-content">

<?php
require_once dirname(__DIR__) . '/config/db.php';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// India balance helper
function getIndiaBalance($userId = INDIA_DEFAULT_USER): float {
    $db = getDB();
    $dep = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM india_transactions WHERE user_id=? AND type='deposit'");
    $dep->execute([$userId]); $deposits = (float)$dep->fetch()['t'];
    $wd = $db->prepare("SELECT COALESCE(SUM(amount),0) as t FROM india_transactions WHERE user_id=? AND type='withdraw'");
    $wd->execute([$userId]); $withdrawals = (float)$wd->fetch()['t'];
    $pl = $db->prepare("SELECT COALESCE(SUM(net_pl),0) as t FROM india_trades WHERE user_id=?");
    $pl->execute([$userId]); $netPL = (float)$pl->fetch()['t'];
    return $deposits + $netPL - $withdrawals;
}
$indiaBalance = getIndiaBalance();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= INDIA_APP_NAME ?> — <?= isset($pageTitle) ? $pageTitle : ucfirst($currentPage) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?= $rootPath ?? '' ?>assets/css/style.css" rel="stylesheet">
    <script>(function(){ var t=localStorage.getItem('dos_theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();</script>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon" style="background:linear-gradient(135deg,#FF9933,#138808)">
            <span style="font-size:16px">🇮🇳</span>
        </div>
        <div class="brand-text">
            <span class="brand-name">DisciplineOS</span>
            <span class="brand-sub">India Market</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= $rootPath ?? '' ?>index.php" class="nav-item <?= $currentPage==='index'?'active':'' ?>">
            <i class="fas fa-gauge-high"></i><span>Dashboard</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/journal.php" class="nav-item <?= $currentPage==='journal'?'active':'' ?>">
            <i class="fas fa-book-open"></i><span>Trade Journal</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/calendar.php" class="nav-item <?= $currentPage==='calendar'?'active':'' ?>">
            <i class="fas fa-calendar-days"></i><span>Calendar</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/import.php" class="nav-item <?= $currentPage==='import'?'active':'' ?>">
            <i class="fas fa-file-import"></i><span>Dhan CSV Import</span>
        </a>
        <a href="<?= $rootPath ?? '' ?>pages/analysis.php" class="nav-item <?= $currentPage==='analysis'?'active':'' ?>">
            <i class="fas fa-magnifying-glass-chart"></i><span>Analyzer</span>
        </a>
        <div style="margin:12px 16px;border-top:1px solid rgba(255,255,255,.07)"></div>
        <a href="<?= $rootPath ?? '' ?>../index.php" class="nav-item" style="opacity:.6">
            <i class="fas fa-globe"></i><span>Forex Panel</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="balance-widget">
            <div class="bw-label">India Portfolio</div>
            <div class="bw-amount"><?= formatINR($indiaBalance) ?></div>
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
                <?php
                $titles=['index'=>'Dashboard','journal'=>'Trade Journal','calendar'=>'Calendar',
                         'import'=>'Dhan CSV Import','analysis'=>'Trade Analyzer','day'=>'Day Detail'];
                echo $titles[$currentPage] ?? ucfirst($currentPage);
                ?>
            </h4>
        </div>
        <div class="topbar-right">
            <span class="date-badge" style="background:rgba(255,153,51,.1);color:#FF9933;border:1px solid rgba(255,153,51,.3)">
                🇮🇳 IST &nbsp; <?= date('D, d M Y') ?>
            </span>
            <div class="theme-toggle" id="themeToggle">
                <i class="fas fa-sun" id="themeIconSun"></i>
                <div class="toggle-track"><div class="toggle-thumb" id="toggleThumb"></div></div>
                <i class="fas fa-moon" id="themeIconMoon"></i>
            </div>
            <div class="user-chip">
                <div class="user-avatar" style="background:linear-gradient(135deg,#FF9933,#138808)">IN</div>
                <span>India Trader</span>
            </div>
        </div>
    </header>
    <div class="page-content">

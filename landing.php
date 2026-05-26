<?php
// Landing page — public, no auth required
require_once 'config/db.php';
// If already logged in, go straight to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Risk-First Trading Journal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --accent:   #3b82f6;
        --accent2:  #8b5cf6;
        --accent3:  #06b6d4;
        --profit:   #22c55e;
        --loss:     #f87171;
        --bg:       #060b18;
        --bg2:      #0d1526;
        --bg3:      #111e35;
        --card:     #131f38;
        --border:   rgba(59,130,246,.15);
        --text:     #f1f5f9;
        --muted:    #64748b;
        --sub:      #94a3b8;
        --font:     'DM Sans', sans-serif;
        --mono:     'DM Mono', monospace;
    }

    html { scroll-behavior: smooth; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); overflow-x: hidden; }

    /* ── NAVBAR ─────────────────────────────────────────────── */
    .nav-wrap {
        position: fixed; top: 0; left: 0; right: 0; z-index: 999;
        padding: 0 2rem;
        background: rgba(6,11,24,.85);
        backdrop-filter: blur(16px);
        border-bottom: 1px solid rgba(59,130,246,.1);
        height: 68px;
        display: flex; align-items: center; justify-content: space-between;
    }
    .nav-brand {
        display: flex; align-items: center; gap: 10px;
        text-decoration: none;
    }
    .nav-brand .logo-box {
        width: 38px; height: 38px;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .nav-brand .logo-box svg { width: 20px; height: 20px; stroke: #fff; }
    .nav-brand .brand-name { font-weight: 700; font-size: 1.1rem; color: var(--text); letter-spacing: -.02em; }
    .nav-brand .brand-tag { font-size: .7rem; color: var(--muted); font-weight: 500; margin-top: -2px; }
    .nav-links { display: flex; align-items: center; gap: .75rem; }
    .btn-nav-ghost {
        padding: .45rem 1.2rem; border-radius: 8px; font-weight: 600; font-size: .88rem;
        border: 1px solid rgba(255,255,255,.12); color: var(--sub); background: transparent;
        text-decoration: none; transition: all .2s;
    }
    .btn-nav-ghost:hover { border-color: var(--accent); color: var(--accent); background: rgba(59,130,246,.08); }
    .btn-nav-primary {
        padding: .45rem 1.3rem; border-radius: 8px; font-weight: 700; font-size: .88rem;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        color: #fff; text-decoration: none; border: none;
        transition: opacity .2s; box-shadow: 0 4px 18px rgba(59,130,246,.35);
    }
    .btn-nav-primary:hover { opacity: .88; color: #fff; }

    /* ── HERO ────────────────────────────────────────────────── */
    .hero {
        min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
        position: relative; overflow: hidden;
        padding: 120px 1.5rem 80px;
    }
    /* animated gradient blobs */
    .hero::before {
        content: '';
        position: absolute; top: -200px; left: 50%; transform: translateX(-50%);
        width: 900px; height: 600px;
        background: radial-gradient(ellipse at 30% 50%, rgba(59,130,246,.18) 0%, transparent 60%),
                    radial-gradient(ellipse at 70% 40%, rgba(139,92,246,.14) 0%, transparent 60%);
        pointer-events: none;
    }
    .hero::after {
        content: '';
        position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(59,130,246,.3), rgba(139,92,246,.3), transparent);
    }
    .hero-content { text-align: center; max-width: 820px; position: relative; z-index: 2; }
    .hero-badge {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.25);
        border-radius: 100px; padding: .4rem 1rem;
        font-size: .78rem; font-weight: 600; color: var(--accent);
        letter-spacing: .04em; text-transform: uppercase;
        margin-bottom: 1.75rem;
    }
    .hero-badge .dot { width: 6px; height: 6px; background: var(--accent); border-radius: 50%; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }

    .hero h1 {
        font-size: clamp(2.4rem, 6vw, 4.2rem);
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -.04em;
        color: var(--text);
        margin-bottom: 1.5rem;
    }
    .hero h1 .grad {
        background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 50%, var(--accent3) 100%);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .hero-sub {
        font-size: clamp(1rem, 2.5vw, 1.2rem);
        color: var(--sub); line-height: 1.7;
        max-width: 600px; margin: 0 auto 2.5rem;
    }
    .hero-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-bottom: 3.5rem; }
    .btn-hero-primary {
        padding: .85rem 2.2rem; border-radius: 12px; font-weight: 700; font-size: 1rem;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        color: #fff; text-decoration: none; border: none;
        box-shadow: 0 6px 28px rgba(59,130,246,.4);
        transition: transform .2s, box-shadow .2s; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 36px rgba(59,130,246,.5); color: #fff; }
    .btn-hero-ghost {
        padding: .85rem 2rem; border-radius: 12px; font-weight: 600; font-size: 1rem;
        border: 1px solid rgba(255,255,255,.15); color: var(--sub); background: rgba(255,255,255,.04);
        text-decoration: none; transition: all .2s; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-hero-ghost:hover { border-color: var(--accent); color: var(--accent); background: rgba(59,130,246,.08); }

    /* trust row */
    .hero-trust {
        display: flex; align-items: center; justify-content: center;
        gap: 2rem; flex-wrap: wrap;
        font-size: .82rem; color: var(--muted);
    }
    .hero-trust .trust-item { display: flex; align-items: center; gap: 6px; }
    .hero-trust .trust-item i { color: var(--profit); font-size: .8rem; }

    /* dashboard mockup */
    .hero-mockup {
        margin-top: 5rem; position: relative; z-index: 2;
    }
    .mockup-frame {
        background: var(--card);
        border: 1px solid rgba(59,130,246,.2);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 30px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(59,130,246,.08);
        max-width: 960px; margin: 0 auto;
    }
    .mockup-bar {
        background: #0d1526; padding: .6rem 1rem;
        display: flex; align-items: center; gap: 8px;
        border-bottom: 1px solid rgba(59,130,246,.1);
    }
    .mockup-bar .dot { width: 10px; height: 10px; border-radius: 50%; }
    .mockup-bar .d1 { background: #f87171; }
    .mockup-bar .d2 { background: #fbbf24; }
    .mockup-bar .d3 { background: #22c55e; }
    .mockup-bar .url {
        margin: 0 auto; background: rgba(255,255,255,.06); border-radius: 6px;
        padding: .2rem .8rem; font-family: var(--mono); font-size: .72rem; color: var(--muted);
    }
    .mockup-inner {
        display: grid; grid-template-columns: 200px 1fr;
        min-height: 340px;
    }
    .mock-sidebar {
        background: #0d1a2e; padding: 1rem .75rem;
        border-right: 1px solid rgba(59,130,246,.1);
        display: flex; flex-direction: column; gap: .25rem;
    }
    .mock-nav-item {
        display: flex; align-items: center; gap: 8px;
        padding: .45rem .7rem; border-radius: 7px;
        font-size: .72rem; color: rgba(148,163,184,.7);
    }
    .mock-nav-item.active { background: rgba(59,130,246,.15); color: var(--accent); }
    .mock-nav-item i { font-size: .65rem; width: 14px; text-align: center; }
    .mock-content { padding: 1.25rem; display: grid; gap: .75rem; }
    .mock-stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: .6rem; }
    .mock-stat {
        background: rgba(255,255,255,.04); border: 1px solid rgba(59,130,246,.1);
        border-radius: 9px; padding: .6rem .75rem;
    }
    .mock-stat .ms-label { font-size: .6rem; color: var(--muted); margin-bottom: 4px; font-family: var(--mono); }
    .mock-stat .ms-value { font-size: .95rem; font-weight: 700; font-family: var(--mono); }
    .mock-stat .ms-value.up { color: var(--profit); }
    .mock-stat .ms-value.down { color: var(--loss); }
    .mock-stat .ms-value.blue { color: var(--accent); }
    .mock-chart {
        background: rgba(255,255,255,.03); border: 1px solid rgba(59,130,246,.1);
        border-radius: 9px; padding: .75rem; height: 120px;
        display: flex; align-items: flex-end; gap: 4px; overflow: hidden;
    }
    .mock-bar { flex: 1; border-radius: 4px 4px 0 0; min-width: 0; }
    .mock-table {
        background: rgba(255,255,255,.03); border: 1px solid rgba(59,130,246,.1);
        border-radius: 9px; overflow: hidden;
    }
    .mock-th { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; padding: .4rem .7rem; border-bottom: 1px solid rgba(59,130,246,.1); }
    .mock-th span { font-size: .58rem; color: var(--muted); font-family: var(--mono); text-transform: uppercase; }
    .mock-tr { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; padding: .35rem .7rem; border-bottom: 1px solid rgba(255,255,255,.03); }
    .mock-tr span { font-size: .65rem; color: var(--sub); font-family: var(--mono); }
    .mock-tr span.g { color: var(--profit); }
    .mock-tr span.r { color: var(--loss); }

    /* ── SECTION STYLES ──────────────────────────────────────── */
    section { padding: 6rem 1.5rem; }
    .section-label {
        display: inline-block;
        font-size: .72rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
        color: var(--accent); margin-bottom: 1rem;
    }
    .section-title {
        font-size: clamp(1.8rem, 4vw, 2.8rem);
        font-weight: 800; letter-spacing: -.03em;
        color: var(--text); line-height: 1.15; margin-bottom: 1rem;
    }
    .section-sub { font-size: 1.05rem; color: var(--sub); line-height: 1.7; max-width: 520px; }

    /* divider line */
    .section-divider {
        border: none; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(59,130,246,.2), rgba(139,92,246,.2), transparent);
        margin: 0;
    }

    /* ── FEATURES ────────────────────────────────────────────── */
    .features-section { background: var(--bg2); }
    .feature-card {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 16px; padding: 1.75rem;
        transition: border-color .3s, transform .3s, box-shadow .3s;
        height: 100%;
    }
    .feature-card:hover {
        border-color: rgba(59,130,246,.4);
        transform: translateY(-4px);
        box-shadow: 0 16px 40px rgba(0,0,0,.3), 0 0 0 1px rgba(59,130,246,.1);
    }
    .feat-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; margin-bottom: 1.1rem;
    }
    .feat-title { font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: .5rem; }
    .feat-desc { font-size: .875rem; color: var(--sub); line-height: 1.65; }

    /* ── STATS ───────────────────────────────────────────────── */
    .stats-section { background: var(--bg); }
    .stat-card {
        text-align: center; padding: 2.5rem 1.5rem;
        background: var(--card); border: 1px solid var(--border); border-radius: 16px;
    }
    .stat-card .stat-num {
        font-size: 3rem; font-weight: 800; font-family: var(--mono);
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        line-height: 1; margin-bottom: .4rem;
    }
    .stat-card .stat-label { font-size: .9rem; color: var(--sub); font-weight: 500; }

    /* ── HOW IT WORKS ────────────────────────────────────────── */
    .steps-section { background: var(--bg3); }
    .step-num {
        width: 44px; height: 44px; border-radius: 50%;
        background: linear-gradient(135deg, var(--accent), var(--accent2));
        display: flex; align-items: center; justify-content: center;
        font-size: .95rem; font-weight: 800; color: #fff;
        flex-shrink: 0; box-shadow: 0 4px 16px rgba(59,130,246,.35);
    }
    .step-connector {
        width: 2px; height: 40px; background: linear-gradient(var(--accent), var(--accent2));
        margin: .4rem auto; opacity: .3;
    }
    .step-card {
        display: flex; gap: 1.25rem; align-items: flex-start;
        background: var(--card); border: 1px solid var(--border);
        border-radius: 14px; padding: 1.5rem;
        transition: border-color .3s;
    }
    .step-card:hover { border-color: rgba(59,130,246,.35); }
    .step-card .step-title { font-size: .95rem; font-weight: 700; color: var(--text); margin-bottom: .3rem; }
    .step-card .step-desc { font-size: .84rem; color: var(--sub); line-height: 1.6; }

    /* ── CTA SECTION ─────────────────────────────────────────── */
    .cta-section {
        background: var(--bg2);
        text-align: center;
        position: relative; overflow: hidden;
    }
    .cta-section::before {
        content: '';
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        width: 700px; height: 400px;
        background: radial-gradient(ellipse, rgba(59,130,246,.12) 0%, transparent 70%);
        pointer-events: none;
    }
    .cta-section h2 {
        font-size: clamp(1.8rem, 4vw, 2.8rem);
        font-weight: 800; letter-spacing: -.03em;
        color: var(--text); margin-bottom: 1rem; position: relative;
    }
    .cta-section p { font-size: 1.05rem; color: var(--sub); margin-bottom: 2.5rem; position: relative; }
    .cta-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; position: relative; }

    /* ── FOOTER ──────────────────────────────────────────────── */
    footer {
        background: var(--bg); border-top: 1px solid rgba(255,255,255,.05);
        padding: 2.5rem 2rem; text-align: center;
        font-size: .82rem; color: var(--muted);
    }
    footer .foot-brand { font-weight: 700; color: var(--sub); margin-bottom: .4rem; }
    footer a { color: var(--muted); text-decoration: none; }
    footer a:hover { color: var(--accent); }

    /* ── RESPONSIVE ──────────────────────────────────────────── */
    @media (max-width: 768px) {
        .nav-brand .brand-tag { display: none; }
        .mockup-inner { grid-template-columns: 1fr; }
        .mock-sidebar { display: none; }
        .mock-stat-row { grid-template-columns: repeat(2,1fr); }
        .hero-trust { gap: 1rem; }
    }
    @media (max-width: 576px) {
        .btn-nav-ghost { display: none; }
        .hero-actions { flex-direction: column; align-items: center; }
    }
    </style>
</head>
<body>

<!-- ── NAVBAR ───────────────────────────────────────────────────────── -->
<nav class="nav-wrap">
    <a href="landing.php" class="nav-brand">
        <div class="logo-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                <polyline points="16 7 22 7 22 13"></polyline>
            </svg>
        </div>
        <div>
            <div class="brand-name"><?= APP_NAME ?></div>
            <div class="brand-tag">Risk-First Trading</div>
        </div>
    </a>
    <div class="nav-links">
        <a href="pages/login.php" class="btn-nav-ghost">Sign In</a>
        <a href="pages/register.php" class="btn-nav-primary">Get Started Free</a>
    </div>
</nav>


<!-- ── HERO ─────────────────────────────────────────────────────────── -->
<section class="hero">
    <div style="width:100%;max-width:1100px">
        <div class="hero-content">
            <div class="hero-badge">
                <span class="dot"></span>
                Built for Disciplined Traders
            </div>
            <h1>
                Trade Smarter.<br>
                Risk <span class="grad">Less.</span><br>
                Grow <span class="grad">Consistently.</span>
            </h1>
            <p class="hero-sub">
                <?= APP_NAME ?> is your all-in-one trading journal with real-time risk management,
                psychology tracking, and deep performance analytics — built to protect your capital first.
            </p>
            <div class="hero-actions">
                <a href="pages/register.php" class="btn-hero-primary">
                    <i class="fas fa-rocket"></i> Start Tracking Free
                </a>
                <a href="pages/login.php" class="btn-hero-ghost">
                    <i class="fas fa-right-to-bracket"></i> Sign In
                </a>
            </div>
            <div class="hero-trust">
                <div class="trust-item"><i class="fas fa-check-circle"></i> No credit card required</div>
                <div class="trust-item"><i class="fas fa-check-circle"></i> Data isolated per account</div>
                <div class="trust-item"><i class="fas fa-check-circle"></i> CSRF & session secured</div>
            </div>
        </div>

        <!-- Dashboard mockup -->
        <div class="hero-mockup">
            <div class="mockup-frame">
                <div class="mockup-bar">
                    <div class="dot d1"></div><div class="dot d2"></div><div class="dot d3"></div>
                    <div class="url">localhost/trading-app/ — Dashboard</div>
                </div>
                <div class="mockup-inner">
                    <!-- Fake sidebar -->
                    <div class="mock-sidebar">
                        <div class="mock-nav-item active"><i class="fas fa-gauge-high"></i> Dashboard</div>
                        <div class="mock-nav-item"><i class="fas fa-book-open"></i> Trade Journal</div>
                        <div class="mock-nav-item"><i class="fas fa-calendar-days"></i> Calendar</div>
                        <div class="mock-nav-item"><i class="fas fa-wallet"></i> Fund Manager</div>
                        <div class="mock-nav-item"><i class="fas fa-chart-bar"></i> Reports</div>
                        <div class="mock-nav-item"><i class="fas fa-brain"></i> Coach</div>
                        <div class="mock-nav-item"><i class="fas fa-trophy"></i> Challenge</div>
                        <div class="mock-nav-item"><i class="fas fa-head-side-brain"></i> Psychology</div>
                        <div class="mock-nav-item"><i class="fas fa-bullseye"></i> Targets</div>
                    </div>
                    <!-- Fake content -->
                    <div class="mock-content">
                        <div class="mock-stat-row">
                            <div class="mock-stat">
                                <div class="ms-label">BALANCE</div>
                                <div class="ms-value blue">$12,480</div>
                            </div>
                            <div class="mock-stat">
                                <div class="ms-label">NET P/L TODAY</div>
                                <div class="ms-value up">+$340</div>
                            </div>
                            <div class="mock-stat">
                                <div class="ms-label">DAILY RISK USED</div>
                                <div class="ms-value" style="color:#fbbf24">42%</div>
                            </div>
                            <div class="mock-stat">
                                <div class="ms-label">WIN RATE</div>
                                <div class="ms-value up">67%</div>
                            </div>
                        </div>
                        <div class="mock-chart">
                            <?php
                            $bars = [35,55,40,70,45,80,60,90,55,75,50,85,65,95,70,80,60,88,72,92];
                            $colors = ['#22c55e','#22c55e','#f87171','#22c55e','#f87171','#22c55e','#22c55e','#3b82f6','#22c55e','#22c55e',
                                       '#f87171','#22c55e','#22c55e','#3b82f6','#22c55e','#22c55e','#f87171','#22c55e','#22c55e','#3b82f6'];
                            foreach ($bars as $i => $h):
                            ?>
                            <div class="mock-bar" style="height:<?= $h ?>%;background:<?= $colors[$i] ?>;opacity:.75"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mock-table">
                            <div class="mock-th">
                                <span>SYMBOL</span><span>TYPE</span><span>P/L</span><span>TIME</span>
                            </div>
                            <div class="mock-tr"><span>EURUSD</span><span>BUY</span><span class="g">+$120</span><span>09:32</span></div>
                            <div class="mock-tr"><span>GBPUSD</span><span>SELL</span><span class="r">-$45</span><span>10:15</span></div>
                            <div class="mock-tr"><span>XAUUSD</span><span>BUY</span><span class="g">+$265</span><span>11:48</span></div>
                            <div class="mock-tr"><span>NASDAQ</span><span>SELL</span><span class="g">+$88</span><span>13:20</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<hr class="section-divider">


<!-- ── FEATURES ─────────────────────────────────────────────────────── -->
<section class="features-section">
    <div class="container" style="max-width:1100px">
        <div class="text-center mb-5">
            <span class="section-label">Everything You Need</span>
            <h2 class="section-title">One platform. Full control.</h2>
            <p class="section-sub mx-auto">From daily risk limits to psychology analysis — every tool a serious trader needs, in one place.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(59,130,246,.15)">
                        <i class="fas fa-shield-halved" style="color:#3b82f6"></i>
                    </div>
                    <div class="feat-title">Risk Engine</div>
                    <div class="feat-desc">Real-time daily & weekly loss limits with automatic breach detection. Profit lock mode keeps your gains safe.</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(34,197,94,.12)">
                        <i class="fas fa-book-open" style="color:#22c55e"></i>
                    </div>
                    <div class="feat-title">Trade Journal</div>
                    <div class="feat-desc">Log every trade with entry/exit times, symbols, P/L, brokerage, and swap. Filter and review with ease.</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(139,92,246,.15)">
                        <i class="fas fa-brain" style="color:#8b5cf6"></i>
                    </div>
                    <div class="feat-title">Psychology Tracker</div>
                    <div class="feat-desc">Daily emotion journal, habit scoring, pre-trade checklists, and post-trade analysis to master your mindset.</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(6,182,212,.12)">
                        <i class="fas fa-chart-bar" style="color:#06b6d4"></i>
                    </div>
                    <div class="feat-title">Deep Analytics</div>
                    <div class="feat-desc">Win rate, expectancy, profit factor, drawdown curves, calendar heat maps and more — visualised clearly.</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(251,191,36,.1)">
                        <i class="fas fa-trophy" style="color:#fbbf24"></i>
                    </div>
                    <div class="feat-title">Discipline Challenge</div>
                    <div class="feat-desc">30-day structured challenge with XP, badges, streaks and daily scoring to build consistent trading habits.</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feat-icon" style="background:rgba(248,113,113,.1)">
                        <i class="fas fa-file-import" style="color:#f87171"></i>
                    </div>
                    <div class="feat-title">CSV Import</div>
                    <div class="feat-desc">Import trade history directly from your broker's CSV export. Auto-converts UTC to local time, deduplicates tickets.</div>
                </div>
            </div>
        </div>
    </div>
</section>
<hr class="section-divider">


<!-- ── STATS ─────────────────────────────────────────────────────────── -->
<section class="stats-section">
    <div class="container" style="max-width:900px">
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-num">23+</div>
                    <div class="stat-label">Tracking modules</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-num">100%</div>
                    <div class="stat-label">Data isolated</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-num">0ms</div>
                    <div class="stat-label">Build tools needed</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-num">∞</div>
                    <div class="stat-label">Trades you can log</div>
                </div>
            </div>
        </div>
    </div>
</section>
<hr class="section-divider">


<!-- ── HOW IT WORKS ──────────────────────────────────────────────────── -->
<section class="steps-section">
    <div class="container" style="max-width:700px">
        <div class="text-center mb-5">
            <span class="section-label">Quick Start</span>
            <h2 class="section-title">Up and running in minutes</h2>
        </div>
        <div class="d-flex flex-column gap-3">
            <div class="step-card">
                <div class="step-num">1</div>
                <div>
                    <div class="step-title">Create your free account</div>
                    <div class="step-desc">Sign up with your name and email. No payment, no credit card — just start tracking.</div>
                </div>
            </div>
            <div class="step-card">
                <div class="step-num">2</div>
                <div>
                    <div class="step-title">Add your first trade or import via CSV</div>
                    <div class="step-desc">Log trades manually using the quick-add modal, or bulk-import your broker's CSV history in one click.</div>
                </div>
            </div>
            <div class="step-card">
                <div class="step-num">3</div>
                <div>
                    <div class="step-title">Set your risk limits</div>
                    <div class="step-desc">Daily and weekly loss guards are configured out of the box. The risk engine watches every trade automatically.</div>
                </div>
            </div>
            <div class="step-card">
                <div class="step-num">4</div>
                <div>
                    <div class="step-title">Review, reflect, improve</div>
                    <div class="step-desc">Use the Coach Dashboard, Psychology Tracker, and POV Accuracy tools to identify patterns and grow as a trader.</div>
                </div>
            </div>
        </div>
    </div>
</section>
<hr class="section-divider">


<!-- ── CTA ───────────────────────────────────────────────────────────── -->
<section class="cta-section">
    <div class="container" style="max-width:700px; position:relative; z-index:2">
        <h2>Ready to trade with discipline?</h2>
        <p>Join traders who protect their capital first and let the profits follow.</p>
        <div class="cta-actions">
            <a href="pages/register.php" class="btn-hero-primary">
                <i class="fas fa-user-plus"></i> Create Free Account
            </a>
            <a href="pages/login.php" class="btn-hero-ghost">
                <i class="fas fa-right-to-bracket"></i> Already have an account
            </a>
        </div>
    </div>
</section>


<!-- ── FOOTER ─────────────────────────────────────────────────────────── -->
<footer>
    <div class="foot-brand">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:6px;opacity:.7">
            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
            <polyline points="16 7 22 7 22 13"></polyline>
        </svg>
        <?= APP_NAME ?>
    </div>
    <div style="margin-top:.4rem">
        &copy; <?= date('Y') ?> <?= APP_NAME ?>. Built for disciplined traders.
        &nbsp;·&nbsp; <a href="pages/login.php">Sign In</a>
        &nbsp;·&nbsp; <a href="pages/register.php">Register</a>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

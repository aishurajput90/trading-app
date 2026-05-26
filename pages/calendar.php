<?php
require_once '../config/db.php';
$db     = getDB();
requireLogin();
$userId = getCurrentUserId();

$month = intval($_GET['month'] ?? date('n'));
$year  = intval($_GET['year']  ?? date('Y'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$today  = (int)date('j');
$todayM = (int)date('n');
$todayY = (int)date('Y');

$view = ($_GET['view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$isCurrentYear = ($year == $todayY);

// Yearly data (only queried when $view === 'yearly')
$yearDayData = []; $yearNet = $yearGross = $yearBrok = $yearSwap = 0.0;
$yearTradingDays = $yearProfitDays = $yearLossDays = 0; $maxAbsNetYear = 1.0;

if ($view === 'yearly') {
    $yrStmt = $db->prepare("
        SELECT DATE(trade_datetime) AS day, COUNT(*) AS trade_count,
               COALESCE(SUM(profit_loss),0) AS gross_pl,
               COALESCE(SUM(brokerage),0)   AS total_brok,
               COALESCE(SUM(swap),0)         AS total_swap,
               COALESCE(SUM(profit_loss - brokerage + swap),0) AS net_pl
        FROM trades WHERE user_id = ? AND YEAR(trade_datetime) = ?
        GROUP BY DATE(trade_datetime) ORDER BY DATE(trade_datetime)
    ");
    $yrStmt->execute([$userId, $year]);
    foreach ($yrStmt->fetchAll() as $r) {
        $yearDayData[$r['day']] = $r;
        $yearNet   += (float)$r['net_pl'];
        $yearGross += (float)$r['gross_pl'];
        $yearBrok  += (float)$r['total_brok'];
        $yearSwap  += (float)$r['total_swap'];
        $yearTradingDays++;
        if ((float)$r['net_pl'] > 0) $yearProfitDays++;
        elseif ((float)$r['net_pl'] < 0) $yearLossDays++;
        $a = abs((float)$r['net_pl']);
        if ($a > $maxAbsNetYear) $maxAbsNetYear = $a;
    }
}

$prevMonth = $month - 1 ?: 12;
$prevYear  = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear  = $month === 12 ? $year + 1 : $year;
$firstDay  = mktime(0,0,0,$month,1,$year);
$daysInMonth = (int)date('t',$firstDay);
$startDow  = (int)date('N',$firstDay);

// ── Daily aggregates for this month ──────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        DATE(trade_datetime)          AS day,
        COUNT(*)                      AS trade_count,
        COALESCE(SUM(profit_loss),0)  AS gross_pl,
        COALESCE(SUM(brokerage),0)    AS total_brok,
        COALESCE(SUM(swap),0)         AS total_swap,
        COALESCE(SUM(profit_loss - brokerage + swap),0) AS net_pl
    FROM trades
    WHERE user_id = ?
      AND MONTH(trade_datetime) = ?
      AND YEAR(trade_datetime)  = ?
    GROUP BY DATE(trade_datetime)
    ORDER BY DATE(trade_datetime)
");
$stmt->execute([$userId, $month, $year]);
$rawDays = $stmt->fetchAll();

$dayData = [];
foreach ($rawDays as $r) {
    $d = (int)date('j', strtotime($r['day']));
    $dayData[$d] = $r;
}

// ── Monthly summary ───────────────────────────────────────────────────────
$monthGross   = array_sum(array_column($rawDays, 'gross_pl'));
$monthBrok    = array_sum(array_column($rawDays, 'total_brok'));
$monthSwap    = array_sum(array_column($rawDays, 'total_swap'));
$monthNet     = array_sum(array_column($rawDays, 'net_pl'));
$profitDays   = count(array_filter($rawDays, fn($r) => $r['net_pl'] > 0));
$lossDays     = count(array_filter($rawDays, fn($r) => $r['net_pl'] < 0));
$tradingDays  = count($rawDays);

// Best day this month
$bestDay = null; $bestNet = null;
foreach ($rawDays as $r) {
    if ($bestNet === null || $r['net_pl'] > $bestNet) {
        $bestNet = $r['net_pl'];
        $bestDay = $r;
    }
}

// ── All-time best day ─────────────────────────────────────────────────────
$atStmt = $db->prepare("
    SELECT DATE(trade_datetime) AS day,
           COALESCE(SUM(profit_loss - brokerage + swap),0) AS net_pl,
           COALESCE(SUM(profit_loss),0) AS gross_pl
    FROM trades WHERE user_id = ?
    GROUP BY DATE(trade_datetime)
    ORDER BY net_pl DESC LIMIT 1
");
$atStmt->execute([$userId]);
$allTimeBest = $atStmt->fetch();

// Heat-map intensity anchor
$maxAbsNet = 0;
foreach ($rawDays as $r) {
    $a = abs((float)$r['net_pl']);
    if ($a > $maxAbsNet) $maxAbsNet = $a;
}
$isCurrentMonth = ($month == $todayM && $year == $todayY);

$pageTitle = 'Calendar';
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
/* ── Nav ── */
.cal-nav { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; }
.cal-nav-btn {
    width:40px; height:40px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-sm);
    color:var(--text-primary); text-decoration:none; transition:all .15s;
}
.cal-nav-btn:hover { border-color:var(--accent); color:var(--accent); background:rgba(37,99,235,.05); }
.cal-today-btn {
    background:var(--accent); color:#fff; border:none;
    border-radius:var(--radius-sm); padding:7px 16px;
    font-size:12px; font-weight:600; text-decoration:none; cursor:pointer; transition:background .15s;
}
.cal-today-btn:hover { background:var(--accent-hover); color:#fff; }

/* ── Summary panel ── */
.cal-summary-panel {
    background:var(--bg-surface); border:1px solid var(--border);
    border-radius:var(--radius); padding:20px; margin-bottom:16px;
}
.cal-stat-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr));
    gap:0; margin-bottom:16px;
}
.cal-stat {
    text-align:center; padding:0 12px;
    border-right:1px solid var(--border-light);
}
.cal-stat:last-child { border-right:none; }
@media(max-width:640px) { .cal-stat { border-right:none; padding:8px 0; border-bottom:1px solid var(--border-light); }
    .cal-stat:last-child { border-bottom:none; } }
.cal-stat-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.9px; color:var(--text-muted); margin-bottom:5px; }
.cal-stat-val { font-family:var(--font-mono); font-size:17px; font-weight:700; line-height:1.1; }
.cal-stat-val.big { font-size:22px; }
.cal-stat-sub { font-size:10px; color:var(--text-muted); margin-top:3px; }
.pos { color:var(--profit); }
.neg { color:var(--loss); }
.wrn { color:var(--warning); }

/* day ratio bar */
.day-ratio-bar { border-top:1px solid var(--border-light); padding-top:14px; margin-top:4px; }
.day-ratio-labels { display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-bottom:6px; }
.day-ratio-track { height:7px; border-radius:99px; background:var(--bg-elevated); overflow:hidden; display:flex; }
.day-ratio-profit { background:var(--profit); border-radius:99px 0 0 99px; }
.day-ratio-loss   { background:var(--loss);   border-radius:0 99px 99px 0; }

/* ── Best day cards ── */
.cal-best-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
@media(max-width:600px) { .cal-best-row { grid-template-columns:1fr; } }
.cal-best {
    background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius);
    padding:18px 20px; border-left:4px solid var(--profit); position:relative; overflow:hidden;
}
.cal-best::after {
    content:''; position:absolute; top:-24px; right:-24px;
    width:90px; height:90px; border-radius:50%;
    background:rgba(22,163,74,.05); pointer-events:none;
}
.cal-best-lbl  { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:8px; }
.cal-best-date { font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:6px; }
.cal-best-net  { font-family:var(--font-mono); font-size:28px; font-weight:700; color:var(--profit); margin-bottom:6px; line-height:1; }
.cal-best-meta { font-size:11px; color:var(--text-muted); }

/* ── Calendar grid ── */
.cal-wrap { background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.cal-hdr  { display:grid; grid-template-columns:repeat(7,1fr); }
.cal-hdr-cell {
    text-align:center; font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:1px; color:var(--text-muted); padding:14px 4px; border-bottom:1px solid var(--border);
}
.cal-hdr-cell.wknd { opacity:.5; }
.cal-body { display:grid; grid-template-columns:repeat(7,1fr); }

.cal-cell {
    min-height:130px; padding:10px; border-right:1px solid var(--border-light);
    border-bottom:1px solid var(--border-light); position:relative; transition:filter .15s;
}
.cal-cell:nth-child(7n) { border-right:none; }
.cal-cell.empty  { background:var(--bg-base); }
.cal-cell.wknd-cell:not(.has-data) { background:var(--bg-base); opacity:.65; }
.cal-cell.today  { box-shadow:inset 0 0 0 2px var(--accent); }
.cal-cell.has-data { cursor:pointer; }
.cal-cell.has-data:hover { filter:brightness(1.06); }

.cal-day-num {
    font-size:13px; font-weight:700;
    width:26px; height:26px; border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    color:var(--text-muted); margin-bottom:5px; line-height:1;
}
.cal-cell.today .cal-day-num { background:var(--accent); color:#fff; }

/* Cell data rows */
.cal-row-lbl { font-family:var(--font-body); font-size:8px; font-weight:500;
               color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-left:2px; }
.cal-gross   { font-family:var(--font-mono); font-size:11px; font-weight:600; line-height:1.3; margin-bottom:1px; }
.cal-brok-row { font-family:var(--font-mono); font-size:11px; font-weight:600;
                color:var(--warning); line-height:1.3; margin-bottom:1px;
                display:flex; align-items:center; gap:3px; }
.cal-brok-row.hi { color:var(--loss); }
.cal-brok-row.hi .cal-brok-warn { display:inline; }
.cal-brok-warn { display:none; font-size:9px; }
.cal-cell-sep { border-top:1px solid var(--border-light); margin:4px 0; }
.cal-net-pl   { font-family:var(--font-mono); font-size:13px; font-weight:700; line-height:1.3; margin-bottom:4px; }
.cal-trade-badge {
    display:inline-flex; align-items:center; gap:3px;
    background:var(--bg-elevated); border:1px solid var(--border-light);
    border-radius:99px; font-size:9px; font-weight:600; color:var(--text-muted); padding:2px 7px;
}
.cal-star { position:absolute; top:7px; right:8px; font-size:11px; }
.pl-positive { color:var(--profit); }
.pl-negative { color:var(--loss); }

/* ── Hover tooltip ── */
.cal-cell[data-tip]:hover::after {
    content:attr(data-tip);
    position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%);
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-sm);
    padding:9px 13px; font-size:11px; line-height:1.8; color:var(--text-primary);
    white-space:pre-line; z-index:200; box-shadow:var(--shadow-md);
    pointer-events:none; min-width:160px;
}
.cal-cell[data-tip]:hover::before {
    content:''; position:absolute; bottom:calc(100% + 3px); left:50%; transform:translateX(-50%);
    border:5px solid transparent; border-top-color:var(--border); z-index:201; pointer-events:none;
}

/* ── Legend ── */
.cal-legend {
    display:flex; flex-wrap:wrap; gap:16px; padding:12px 16px;
    font-size:11px; color:var(--text-muted); border-top:1px solid var(--border);
}
.cal-leg-item { display:flex; align-items:center; gap:6px; }
.leg-dot { width:12px; height:12px; border-radius:3px; flex-shrink:0; }

/* ── View toggle tabs ── */
.view-tab {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 18px; border-radius:var(--radius-sm); font-size:12px; font-weight:600;
    text-decoration:none; border:1px solid var(--border); color:var(--text-muted);
    background:var(--bg-surface); transition:all .15s;
}
.view-tab:hover { border-color:var(--accent); color:var(--accent); }
.view-tab.active { background:var(--accent); color:#fff; border-color:var(--accent); pointer-events:none; }

/* ── Yearly mini-month grids ── */
.mini-wrap {
    background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius);
    height:100%;
}
.mini-month-lbl {
    display:flex; justify-content:space-between; align-items:center;
    padding:8px 10px; font-size:12px; font-weight:700; color:var(--text-secondary);
    border-bottom:1px solid var(--border-light);
}
.mini-dow-hdr { display:grid; grid-template-columns:repeat(7,1fr); padding:4px 5px 0; }
.mini-dow-cell {
    text-align:center; font-size:7px; font-weight:700; text-transform:uppercase;
    color:var(--text-muted); padding:2px 0;
}
.mini-wknd { opacity:.45; }
.mini-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; padding:4px 5px 6px; }
.mini-cell {
    aspect-ratio:1; border-radius:3px; display:flex; align-items:center; justify-content:center;
    background:var(--bg-elevated); position:relative; cursor:default; transition:filter .12s;
}
.mini-cell.has-data { cursor:pointer; }
.mini-cell.has-data:hover { filter:brightness(1.2); z-index:10; }
.mini-cell.empty-cell { background:transparent; }
.mini-cell.mini-today { box-shadow:inset 0 0 0 2px var(--accent); }
.mini-day-num { font-size:8px; font-weight:600; color:rgba(0,0,0,.35); line-height:1; pointer-events:none; }
[data-theme="dark"] .mini-day-num { color:rgba(255,255,255,.4); }
.mini-cell.mini-today .mini-day-num { color:var(--accent); font-weight:800; }
.mini-footer {
    display:flex; gap:8px; align-items:center;
    padding:2px 10px 7px; font-size:9px; font-weight:600; color:var(--text-muted);
}
.mini-footer span:last-child { margin-left:auto; }

/* Mini-cell tooltips are handled by JS (#miniTip) to avoid overflow clipping */
</style>

<?php if (!empty($_GET['deleted'])): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert" id="calFlash">
    <i class="fas fa-check-circle me-2"></i>All trades for that day have been deleted.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<script>setTimeout(function(){ var e=document.getElementById('calFlash'); if(e) e.classList.remove('show'); }, 3500);</script>
<?php endif; ?>

<!-- View Toggle + Navigation -->
<div class="d-flex gap-2 mb-3">
    <a href="?month=<?= $month ?>&year=<?= $year ?>&view=monthly" class="view-tab <?= $view === 'monthly' ? 'active' : '' ?>">
        <i class="fas fa-calendar"></i> Monthly
    </a>
    <a href="?year=<?= $year ?>&view=yearly" class="view-tab <?= $view === 'yearly' ? 'active' : '' ?>">
        <i class="fas fa-calendar-days"></i> Yearly
    </a>
</div>

<div class="cal-nav">
    <?php if ($view === 'monthly'): ?>
    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>&view=monthly" class="cal-nav-btn" title="Previous month">
        <i class="fas fa-chevron-left"></i>
    </a>
    <div style="text-align:center;flex:1">
        <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;color:var(--text-primary)">
            <?= date('F', $firstDay) ?>
            <span style="color:var(--text-muted);font-weight:500"><?= $year ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
            <?= $tradingDays ?> trading day<?= $tradingDays !== 1 ? 's' : '' ?>
            <?php if ($tradingDays > 0): ?>
            &nbsp;·&nbsp;<span class="pos"><?= $profitDays ?>W</span> / <span class="neg"><?= $lossDays ?>L</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$isCurrentMonth): ?>
    <a href="?month=<?= $todayM ?>&year=<?= $todayY ?>&view=monthly" class="cal-today-btn" style="margin-right:8px">Today</a>
    <?php endif; ?>
    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>&view=monthly" class="cal-nav-btn" title="Next month">
        <i class="fas fa-chevron-right"></i>
    </a>
    <?php else: ?>
    <a href="?year=<?= $year - 1 ?>&view=yearly" class="cal-nav-btn" title="Previous year">
        <i class="fas fa-chevron-left"></i>
    </a>
    <div style="text-align:center;flex:1">
        <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;color:var(--text-primary)"><?= $year ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
            <?= $yearTradingDays ?> trading day<?= $yearTradingDays !== 1 ? 's' : '' ?>
            <?php if ($yearTradingDays > 0): ?>
            &nbsp;·&nbsp;<span class="pos"><?= $yearProfitDays ?>W</span> / <span class="neg"><?= $yearLossDays ?>L</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$isCurrentYear): ?>
    <a href="?year=<?= $todayY ?>&view=yearly" class="cal-today-btn" style="margin-right:8px">This Year</a>
    <?php endif; ?>
    <a href="?year=<?= $year + 1 ?>&view=yearly" class="cal-nav-btn" title="Next year">
        <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>
</div>

<?php if ($view === 'monthly'): ?>

<!-- Monthly Summary Panel -->
<div class="cal-summary-panel">
    <div class="cal-stat-grid">
        <div class="cal-stat">
            <div class="cal-stat-lbl">Net P&amp;L</div>
            <div class="cal-stat-val big <?= $monthNet >= 0 ? 'pos' : 'neg' ?>">
                <?= ($monthNet >= 0 ? '+' : '') ?>$<?= number_format($monthNet, 2) ?>
            </div>
            <div class="cal-stat-sub">After all charges</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Gross P&amp;L</div>
            <div class="cal-stat-val <?= $monthGross >= 0 ? 'pos' : 'neg' ?>">
                <?= ($monthGross >= 0 ? '+' : '') ?>$<?= number_format($monthGross, 2) ?>
            </div>
            <div class="cal-stat-sub">Before charges</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Brokerage</div>
            <div class="cal-stat-val neg">-$<?= number_format($monthBrok, 2) ?></div>
            <div class="cal-stat-sub">Commission paid</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Swap</div>
            <div class="cal-stat-val <?= $monthSwap >= 0 ? 'pos' : 'neg' ?>">
                <?= ($monthSwap >= 0 ? '+' : '') ?>$<?= number_format($monthSwap, 2) ?>
            </div>
            <div class="cal-stat-sub">Overnight</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Day Win Rate</div>
            <div class="cal-stat-val <?= $tradingDays > 0 && $profitDays / $tradingDays >= 0.5 ? 'pos' : 'wrn' ?>">
                <?= $tradingDays > 0 ? round($profitDays / $tradingDays * 100, 1) : 0 ?>%
            </div>
            <div class="cal-stat-sub"><?= $profitDays ?>W / <?= $lossDays ?>L</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Trading Days</div>
            <div class="cal-stat-val"><?= $tradingDays ?></div>
            <div class="cal-stat-sub">of <?= $daysInMonth ?> total</div>
        </div>
    </div>
    <?php if ($tradingDays > 0): ?>
    <div class="day-ratio-bar">
        <div class="day-ratio-labels">
            <span class="pos">&#9679; <?= $profitDays ?> profit day<?= $profitDays !== 1 ? 's' : '' ?></span>
            <span class="neg"><?= $lossDays ?> loss day<?= $lossDays !== 1 ? 's' : '' ?> &#9679;</span>
        </div>
        <div class="day-ratio-track">
            <?php $pPct = round($profitDays / $tradingDays * 100); ?>
            <div class="day-ratio-profit" style="width:<?= $pPct ?>%"></div>
            <div class="day-ratio-loss"   style="width:<?= 100 - $pPct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Best Day Cards -->
<?php if ($bestDay || $allTimeBest): ?>
<div class="cal-best-row">
    <?php if ($bestDay): ?>
    <div class="cal-best">
        <div class="cal-best-lbl"><i class="fas fa-star" style="color:var(--warning)"></i> Best Day This Month</div>
        <div class="cal-best-date"><?= date('l, d M Y', strtotime($bestDay['day'])) ?></div>
        <div class="cal-best-net">+$<?= number_format($bestDay['net_pl'], 2) ?></div>
        <div class="cal-best-meta">
            Gross +$<?= number_format($bestDay['gross_pl'], 2) ?>
            &nbsp;·&nbsp; Brok -$<?= number_format($bestDay['total_brok'], 2) ?>
            &nbsp;·&nbsp; <?= $bestDay['trade_count'] ?> trade<?= $bestDay['trade_count'] != 1 ? 's' : '' ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($allTimeBest): ?>
    <div class="cal-best" style="border-left-color:var(--accent-purple)">
        <div class="cal-best-lbl"><i class="fas fa-trophy" style="color:var(--accent-purple)"></i> Best Day All Time</div>
        <div class="cal-best-date"><?= date('l, d M Y', strtotime($allTimeBest['day'])) ?></div>
        <div class="cal-best-net" style="color:var(--accent-purple)">+$<?= number_format($allTimeBest['net_pl'], 2) ?></div>
        <div class="cal-best-meta">Gross +$<?= number_format($allTimeBest['gross_pl'], 2) ?></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Calendar Grid -->
<div class="cal-wrap">
    <div class="cal-hdr">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
        <div class="cal-hdr-cell <?= $i >= 5 ? 'wknd' : '' ?>"><?= $d ?></div>
        <?php endforeach; ?>
    </div>

    <div class="cal-body">
        <?php for ($i = 1; $i < $startDow; $i++): ?>
        <div class="cal-cell empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dow       = (int)date('N', mktime(0,0,0,$month,$day,$year));
            $isWeekend = $dow >= 6;
            $isToday   = ($day == $today && $month == $todayM && $year == $todayY);
            $hasData   = isset($dayData[$day]);
            $isBest    = $bestDay && $hasData && $dayData[$day]['day'] === $bestDay['day'];
            $gross     = $hasData ? (float)$dayData[$day]['gross_pl']   : 0;
            $brok      = $hasData ? (float)$dayData[$day]['total_brok'] : 0;
            $swap      = $hasData ? (float)$dayData[$day]['total_swap'] : 0;
            $net       = $hasData ? (float)$dayData[$day]['net_pl']     : 0;
            $cnt       = $hasData ? (int)  $dayData[$day]['trade_count']: 0;
            $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // Heat-map: alpha scales with magnitude relative to best day
            $alpha  = 0.08;
            $cellBg = '';
            if ($hasData && $maxAbsNet > 0) {
                $intensity = min(1, abs($net) / $maxAbsNet);
                $alpha     = round(0.09 + $intensity * 0.33, 3);
            }
            if ($isBest)            $cellBg = 'background:rgba(22,163,74,.42)';
            elseif ($hasData && $net > 0) $cellBg = "background:rgba(22,163,74,{$alpha})";
            elseif ($hasData && $net < 0) $cellBg = "background:rgba(220,38,38,{$alpha})";

            $cls = 'cal-cell';
            if ($isToday)   $cls .= ' today';
            if ($isWeekend) $cls .= ' wknd-cell';
            if ($hasData)   $cls .= ' has-data';

            // High-brokerage flag: brok ate >25% of gross, or flipped a gross-profit into net-loss
            $brokHigh = $hasData && $brok > 0 && $gross > 0 && ($brok / $gross) > 0.25;
            $brokHigh = $brokHigh || ($hasData && $gross > 0 && $net < 0 && $brok > 0);

            // Tooltip shows swap + brok% (the parts not visible in the cell)
            $brokPct = ($hasData && $gross > 0) ? round($brok / $gross * 100, 1) : 0;
            $tip = $hasData
                ? "Swap:    " . ($swap >= 0 ? '+' : '') . '$' . number_format($swap, 2)
                . "\nBrok:    " . $brokPct . "% of gross"
                . ($brokHigh ? "\n⚠ High brokerage day" : "")
                . "\nTrades:  " . $cnt . "  ·  click for full detail"
                : '';
        ?>
        <div class="<?= $cls ?>" style="<?= $cellBg ?>"
             <?= $hasData ? "onclick=\"window.location='day.php?date={$dateStr}'\"" : '' ?>
             <?= $tip ? 'data-tip="' . htmlspecialchars($tip) . '"' : '' ?>>
            <div class="cal-day-num"><?= $day ?></div>
            <?php if ($isBest): ?><div class="cal-star" title="Best day this month">&#11088;</div><?php endif; ?>
            <?php if ($hasData): ?>
                <div class="cal-gross <?= $gross >= 0 ? 'pl-positive' : 'pl-negative' ?>">
                    <?= ($gross >= 0 ? '+' : '') ?>$<?= number_format($gross, 2) ?>
                    <span class="cal-row-lbl">gross</span>
                </div>
                <?php if ($brok > 0): ?>
                <div class="cal-brok-row <?= $brokHigh ? 'hi' : '' ?>">
                    -$<?= number_format($brok, 2) ?>
                    <span class="cal-row-lbl">brok</span>
                    <i class="fas fa-triangle-exclamation cal-brok-warn" title="High brokerage"></i>
                </div>
                <?php endif; ?>
                <div class="cal-cell-sep"></div>
                <div class="cal-net-pl <?= $net >= 0 ? 'pl-positive' : 'pl-negative' ?>">
                    <?= ($net >= 0 ? '+' : '') ?>$<?= number_format($net, 2) ?>
                    <span class="cal-row-lbl">net</span>
                </div>
                <span class="cal-trade-badge">
                    <i class="fas fa-arrow-right-arrow-left" style="font-size:7px"></i>
                    <?= $cnt ?> trade<?= $cnt != 1 ? 's' : '' ?>
                </span>
            <?php endif; ?>
        </div>
        <?php endfor; ?>

        <?php
        $lastDow = (int)date('N', mktime(0,0,0,$month,$daysInMonth,$year));
        for ($i = $lastDow; $i < 7; $i++) echo '<div class="cal-cell empty"></div>';
        ?>
    </div>

    <div class="cal-legend">
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(22,163,74,.25);border:1px solid rgba(22,163,74,.4)"></div> Profit day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(220,38,38,.25);border:1px solid rgba(220,38,38,.4)"></div> Loss day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:var(--accent);border-radius:50%"></div> Today</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(22,163,74,.45);border:1px solid rgba(22,163,74,.6)"></div> &#11088; Best day</div>
        <div class="cal-leg-item"><span style="color:var(--warning);font-size:11px">■</span> Brokerage cost</div>
        <div class="cal-leg-item"><i class="fas fa-triangle-exclamation" style="color:var(--loss);font-size:10px"></i> High brokerage (&gt;25% of gross)</div>
        <div class="cal-leg-item" style="margin-left:auto;color:var(--text-muted)">
            <i class="fas fa-hand-pointer" style="font-size:11px"></i>&nbsp; Click for day detail &nbsp;·&nbsp; Hover for swap &amp; brok%
        </div>
    </div>
</div>

<?php else: // yearly view ?>

<!-- Year Summary Panel -->
<div class="cal-summary-panel">
    <div class="cal-stat-grid">
        <div class="cal-stat">
            <div class="cal-stat-lbl">Net P&amp;L <?= $year ?></div>
            <div class="cal-stat-val big <?= $yearNet >= 0 ? 'pos' : 'neg' ?>">
                <?= ($yearNet >= 0 ? '+' : '') ?>$<?= number_format($yearNet, 2) ?>
            </div>
            <div class="cal-stat-sub">After all charges</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Gross P&amp;L</div>
            <div class="cal-stat-val <?= $yearGross >= 0 ? 'pos' : 'neg' ?>">
                <?= ($yearGross >= 0 ? '+' : '') ?>$<?= number_format($yearGross, 2) ?>
            </div>
            <div class="cal-stat-sub">Before charges</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Brokerage</div>
            <div class="cal-stat-val neg">-$<?= number_format($yearBrok, 2) ?></div>
            <div class="cal-stat-sub">Commission paid</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Swap</div>
            <div class="cal-stat-val <?= $yearSwap >= 0 ? 'pos' : 'neg' ?>">
                <?= ($yearSwap >= 0 ? '+' : '') ?>$<?= number_format($yearSwap, 2) ?>
            </div>
            <div class="cal-stat-sub">Overnight</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Day Win Rate</div>
            <div class="cal-stat-val <?= $yearTradingDays > 0 && $yearProfitDays / $yearTradingDays >= .5 ? 'pos' : 'wrn' ?>">
                <?= $yearTradingDays > 0 ? round($yearProfitDays / $yearTradingDays * 100, 1) : 0 ?>%
            </div>
            <div class="cal-stat-sub"><?= $yearProfitDays ?>W / <?= $yearLossDays ?>L</div>
        </div>
        <div class="cal-stat">
            <div class="cal-stat-lbl">Trading Days</div>
            <div class="cal-stat-val"><?= $yearTradingDays ?></div>
            <div class="cal-stat-sub">across 12 months</div>
        </div>
    </div>
    <?php if ($yearTradingDays > 0): ?>
    <div class="day-ratio-bar">
        <div class="day-ratio-labels">
            <span class="pos">&#9679; <?= $yearProfitDays ?> profit day<?= $yearProfitDays !== 1 ? 's' : '' ?></span>
            <span class="neg"><?= $yearLossDays ?> loss day<?= $yearLossDays !== 1 ? 's' : '' ?> &#9679;</span>
        </div>
        <div class="day-ratio-track">
            <?php $yPct = $yearTradingDays > 0 ? round($yearProfitDays / $yearTradingDays * 100) : 0; ?>
            <div class="day-ratio-profit" style="width:<?= $yPct ?>%"></div>
            <div class="day-ratio-loss"   style="width:<?= 100 - $yPct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 12 Mini-Month Grids -->
<div class="row g-3 mb-3">
    <?php for ($m = 1; $m <= 12; $m++):
        $mFirst  = mktime(0,0,0,$m,1,$year);
        $mDays   = (int)date('t', $mFirst);
        $mStart  = (int)date('N', $mFirst);
        $mNet = $mGross = $mBrok = 0.0;
        $mTrades = $mProfitDays = $mLossDays = 0;
        for ($d2 = 1; $d2 <= $mDays; $d2++) {
            $ds2 = sprintf('%04d-%02d-%02d', $year, $m, $d2);
            if (isset($yearDayData[$ds2])) {
                $r2 = $yearDayData[$ds2];
                $mNet   += (float)$r2['net_pl'];
                $mGross += (float)$r2['gross_pl'];
                $mBrok  += (float)$r2['total_brok'];
                $mTrades += (int)$r2['trade_count'];
                if ((float)$r2['net_pl'] > 0) $mProfitDays++;
                elseif ((float)$r2['net_pl'] < 0) $mLossDays++;
            }
        }
    ?>
    <div class="col-6 col-lg-4 col-xl-3">
        <div class="mini-wrap">
            <div class="mini-month-lbl">
                <span><?= date('M', $mFirst) ?></span>
                <?php if ($mTrades > 0): ?>
                <span class="<?= $mNet >= 0 ? 'pos' : 'neg' ?>" style="font-family:var(--font-mono);font-size:11px;font-weight:700">
                    <?= ($mNet >= 0 ? '+' : '') ?>$<?= number_format($mNet, 2) ?>
                </span>
                <?php else: ?>
                <span style="font-size:9px;color:var(--text-muted)">no trades</span>
                <?php endif; ?>
            </div>
            <div class="mini-dow-hdr">
                <?php foreach (['M','T','W','T','F','S','S'] as $di => $dl): ?>
                <div class="mini-dow-cell <?= $di >= 5 ? 'mini-wknd' : '' ?>"><?= $dl ?></div>
                <?php endforeach; ?>
            </div>
            <div class="mini-grid">
                <?php for ($i2 = 1; $i2 < $mStart; $i2++): ?>
                <div class="mini-cell empty-cell"></div>
                <?php endfor; ?>

                <?php for ($d = 1; $d <= $mDays; $d++):
                    $ds      = sprintf('%04d-%02d-%02d', $year, $m, $d);
                    $dow     = (int)date('N', mktime(0,0,0,$m,$d,$year));
                    $isWknd2  = $dow >= 6;
                    $isToday2 = ($d == $today && $m == $todayM && $year == $todayY);
                    $hasData2 = isset($yearDayData[$ds]);
                    $cNet    = $hasData2 ? (float)$yearDayData[$ds]['net_pl']    : 0;
                    $cGross  = $hasData2 ? (float)$yearDayData[$ds]['gross_pl']  : 0;
                    $cBrok   = $hasData2 ? (float)$yearDayData[$ds]['total_brok']: 0;
                    $cCnt    = $hasData2 ? (int)  $yearDayData[$ds]['trade_count']: 0;

                    $cAlpha  = 0.10;
                    $cCellBg = $isWknd2 && !$hasData2 ? 'background:var(--bg-base)' : '';
                    if ($hasData2 && $maxAbsNetYear > 0) {
                        $intensity2 = min(1, abs($cNet) / $maxAbsNetYear);
                        $cAlpha     = round(0.12 + $intensity2 * 0.50, 3);
                        if ($cNet > 0)      $cCellBg = "background:rgba(22,163,74,{$cAlpha})";
                        elseif ($cNet < 0)  $cCellBg = "background:rgba(220,38,38,{$cAlpha})";
                    }

                    $cCls = 'mini-cell';
                    if ($isToday2) $cCls .= ' mini-today';
                    if ($hasData2) $cCls .= ' has-data';

                    $cBrokPct = ($hasData2 && $cGross > 0) ? round($cBrok / $cGross * 100, 1) : 0;
                    $cTip = $hasData2
                        ? date('d M', strtotime($ds))
                        . "\nNet:    " . ($cNet >= 0 ? '+' : '') . '$' . number_format($cNet, 2)
                        . "\nGross:  " . ($cGross >= 0 ? '+' : '') . '$' . number_format($cGross, 2)
                        . "\nBrok:   -\$" . number_format($cBrok, 2) . " ({$cBrokPct}%)"
                        . "\nTrades: " . $cCnt
                        : '';
                ?>
                <div class="<?= $cCls ?>" style="<?= $cCellBg ?>"
                     <?= $hasData2 ? "onclick=\"window.location='day.php?date={$ds}'\"" : '' ?>
                     <?= $cTip ? 'data-tip="' . htmlspecialchars($cTip) . '"' : '' ?>>
                    <span class="mini-day-num"><?= $d ?></span>
                </div>
                <?php endfor; ?>

                <?php
                $mLastDow = (int)date('N', mktime(0,0,0,$m,$mDays,$year));
                for ($i3 = $mLastDow; $i3 < 7; $i3++) echo '<div class="mini-cell empty-cell"></div>';
                ?>
            </div>
            <?php if ($mTrades > 0): ?>
            <div class="mini-footer">
                <span class="pos"><?= $mProfitDays ?>W</span>
                <span class="neg"><?= $mLossDays ?>L</span>
                <span><?= $mTrades ?> trade<?= $mTrades !== 1 ? 's' : '' ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endfor; ?>
</div>

<!-- Year Legend -->
<div class="cal-wrap">
    <div class="cal-legend">
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(22,163,74,.35);border:1px solid rgba(22,163,74,.5)"></div> Profit day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(220,38,38,.35);border:1px solid rgba(220,38,38,.5)"></div> Loss day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:var(--bg-elevated);border:2px solid var(--accent)"></div> Today</div>
        <div class="cal-leg-item" style="margin-left:auto">
            <i class="fas fa-hand-pointer" style="font-size:11px"></i>&nbsp; Click for day detail &nbsp;·&nbsp; Hover for P&amp;L breakdown
        </div>
    </div>
</div>

<?php endif; // end view ?>

<!-- Floating tooltip for yearly mini-cells (fixed-position = never clipped) -->
<div id="miniTip" style="
    position:fixed;z-index:99999;display:none;pointer-events:none;
    background:var(--bg-card);border:1px solid var(--border);
    border-radius:var(--radius-sm);padding:9px 13px;
    font-size:10px;line-height:1.9;color:var(--text-primary);
    white-space:pre-line;box-shadow:var(--shadow-md);min-width:148px;
"></div>
<script>
(function(){
    var tip = document.getElementById('miniTip');
    document.querySelectorAll('.mini-cell[data-tip]').forEach(function(cell){
        cell.addEventListener('mouseenter', function(){
            tip.innerText = this.dataset.tip;
            tip.style.display = 'block';
        });
        cell.addEventListener('mousemove', function(e){
            var tw = tip.offsetWidth, th = tip.offsetHeight;
            var x = e.clientX + 14, y = e.clientY - th - 14;
            if (x + tw > window.innerWidth  - 10) x = e.clientX - tw - 14;
            if (y < 10)                            y = e.clientY + 22;
            tip.style.left = x + 'px';
            tip.style.top  = y + 'px';
        });
        cell.addEventListener('mouseleave', function(){
            tip.style.display = 'none';
        });
    });
})();
</script>

<?php include '../includes/footer.php'; ?>

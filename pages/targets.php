<?php
require_once '../config/db.php';
$pageTitle = 'Trading Targets';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();
$db        = getDB();
$today     = date('Y-m-d');

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS user_targets (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL DEFAULT 1,
    daily_pl_target   DECIMAL(10,2) NOT NULL DEFAULT 0,
    weekly_pl_target  DECIMAL(10,2) NOT NULL DEFAULT 0,
    monthly_pl_target DECIMAL(10,2) NOT NULL DEFAULT 0,
    win_rate_target   DECIMAL(5,2)  NOT NULL DEFAULT 60,
    max_daily_trades  INT           NOT NULL DEFAULT 5,
    min_rr_ratio      DECIMAL(4,2)  NOT NULL DEFAULT 2.00,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_target_user (user_id)
)");

// POST: save targets
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
    $stmt = $db->prepare(
        "INSERT INTO user_targets (user_id, daily_pl_target, weekly_pl_target, monthly_pl_target,
          win_rate_target, max_daily_trades, min_rr_ratio)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
          daily_pl_target=VALUES(daily_pl_target), weekly_pl_target=VALUES(weekly_pl_target),
          monthly_pl_target=VALUES(monthly_pl_target), win_rate_target=VALUES(win_rate_target),
          max_daily_trades=VALUES(max_daily_trades), min_rr_ratio=VALUES(min_rr_ratio)"
    );
    $stmt->execute([
        $userId,
        (float)($_POST['daily_pl_target']   ?? 0),
        (float)($_POST['weekly_pl_target']  ?? 0),
        (float)($_POST['monthly_pl_target'] ?? 0),
        max(0, min(100, (float)($_POST['win_rate_target']  ?? 60))),
        max(1,          (int)($_POST['max_daily_trades']   ?? 5)),
        max(0.1,        (float)($_POST['min_rr_ratio']     ?? 2.0)),
    ]);
    header('Location: targets.php?saved=1');
    exit;
}

// Load targets
$tStmt = $db->prepare("SELECT * FROM user_targets WHERE user_id=?");
$tStmt->execute([$userId]);
$targets = $tStmt->fetch() ?: [
    'daily_pl_target' => 0, 'weekly_pl_target' => 0, 'monthly_pl_target' => 0,
    'win_rate_target' => 60, 'max_daily_trades' => 5, 'min_rr_ratio' => 2.0,
];
$hasTargets = (float)$targets['daily_pl_target'] > 0 || (float)$targets['monthly_pl_target'] > 0;

// Date range
$range    = in_array($_GET['range'] ?? '', ['7','30','90']) ? (int)$_GET['range'] : 30;
$fromDate = date('Y-m-d', strtotime("-{$range} days"));
$dailyTarget = (float)$targets['daily_pl_target'];

// Daily data for range
$stmt = $db->prepare("
    SELECT DATE(trade_datetime) as d,
           COUNT(*) as cnt,
           COALESCE(SUM(profit_loss - brokerage + swap),  0) as net_pl,
           COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) as wins
    FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?
    GROUP BY d ORDER BY d ASC
");
$stmt->execute([$userId, $fromDate, $today]);
$dailyRows = $stmt->fetchAll();

// Build chart arrays
$chartDates     = [];
$chartDailyPL   = [];
$chartCumActual = [];
$chartCumTarget = [];
$chartBarColors = [];
$chartBarBorder = [];
$cumActual = 0.0; $cumTarget = 0.0;

foreach ($dailyRows as $r) {
    $pl = (float)$r['net_pl'];
    $cumActual += $pl;
    $cumTarget += $dailyTarget;
    $chartDates[]     = date('d M', strtotime($r['d']));
    $chartDailyPL[]   = round($pl, 2);
    $chartCumActual[] = round($cumActual, 2);
    $chartCumTarget[] = round($cumTarget, 2);
    if ($dailyTarget > 0 && $pl >= $dailyTarget) {
        $chartBarColors[] = 'rgba(34,197,94,.78)';   $chartBarBorder[] = '#16a34a';
    } elseif ($pl > 0) {
        $chartBarColors[] = 'rgba(34,197,94,.4)';    $chartBarBorder[] = '#16a34a';
    } elseif ($pl < 0) {
        $chartBarColors[] = 'rgba(239,68,68,.78)';   $chartBarBorder[] = '#dc2626';
    } else {
        $chartBarColors[] = 'rgba(148,163,184,.3)';  $chartBarBorder[] = '#94a3b8';
    }
}

// This month stats
$monthStart = date('Y-m-01');
$stmt = $db->prepare("
    SELECT COALESCE(SUM(profit_loss - brokerage + swap), 0) as net_pl,
           COUNT(DISTINCT DATE(trade_datetime)) as trading_days,
           COUNT(*) as total_trades,
           COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) as wins
    FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?
");
$stmt->execute([$userId, $monthStart, $today]);
$mStats       = $stmt->fetch();
$monthActual  = (float)$mStats['net_pl'];
$monthTDays   = (int)$mStats['trading_days'];
$monthTarget  = (float)$targets['monthly_pl_target'];
$monthWinRate = $mStats['total_trades'] > 0 ? round($mStats['wins'] / $mStats['total_trades'] * 100, 1) : 0;
$monthPct     = $monthTarget > 0 ? round($monthActual / $monthTarget * 100, 1) : null;

// This week
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$stmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl FROM trades WHERE user_id=? AND DATE(trade_datetime) >= ?");
$stmt->execute([$userId, $weekStart]);
$weekActual = (float)$stmt->fetch()['net_pl'];
$weekTarget = (float)$targets['weekly_pl_target'];
$weekPct    = $weekTarget > 0 ? round($weekActual / $weekTarget * 100, 1) : null;

// Streak + days-hit-target this month
$stmt = $db->prepare("
    SELECT DATE(trade_datetime) as d,
           COALESCE(SUM(profit_loss - brokerage + swap), 0) as net_pl
    FROM trades WHERE user_id=? AND DATE(trade_datetime) <= ?
    GROUP BY d ORDER BY d DESC LIMIT 60
");
$stmt->execute([$userId, $today]);
$recentDays = $stmt->fetchAll();

$hitStreak = 0; $streakOn = true; $daysHitMonth = 0; $monthDaysTraded = 0;
foreach ($recentDays as $rd) {
    $rdPL  = (float)$rd['net_pl'];
    $isHit = $dailyTarget > 0 ? $rdPL >= $dailyTarget : $rdPL > 0;
    if ($rd['d'] >= $monthStart) { $monthDaysTraded++; if ($isHit) $daysHitMonth++; }
    if ($streakOn) { if ($isHit) $hitStreak++; else $streakOn = false; }
}

// Best day, avg day
$allPLs  = array_column($dailyRows, 'net_pl');
$bestDay  = count($allPLs) ? max(array_map('floatval', $allPLs)) : 0;
$avgDay   = count($allPLs) ? array_sum(array_map('floatval', $allPLs)) / count($allPLs) : 0;

// Win rate target
$wrTarget = (float)$targets['win_rate_target'];
$wrPct    = $wrTarget > 0 ? round($monthWinRate / $wrTarget * 100, 1) : null;

$saved = !empty($_GET['saved']);

include '../includes/header.php';
?>

<style>
/* ── Targets page — Premium UI ── */
.tg-page-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 12px;
}
.tg-icon-box {
    width: 48px; height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(245,158,11,.22), rgba(234,88,12,.15));
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* KPI Cards */
.tg-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
@media(max-width:900px) { .tg-kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:520px) { .tg-kpi-grid { grid-template-columns: 1fr; } }

.tg-kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
}
.tg-kpi-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,.13); }
.tg-kpi-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: 18px 18px 0 0;
}
.tg-kpi-card.amber::before { background: linear-gradient(90deg,#f59e0b,#fb923c); }
.tg-kpi-card.blue::before  { background: linear-gradient(90deg,#2563eb,#60a5fa); }
.tg-kpi-card.green::before { background: linear-gradient(90deg,#16a34a,#22c55e); }
.tg-kpi-card.purple::before{ background: linear-gradient(90deg,#7c3aed,#a78bfa); }

.tg-kpi-ring-wrap { display: flex; align-items: center; gap: 16px; }
.tg-ring-svg { flex-shrink: 0; }
.tg-ring-info { flex: 1; min-width: 0; }
.tg-ring-val  { font-size: 22px; font-weight: 800; font-family: 'DM Mono', monospace; line-height: 1.1; }
.tg-ring-label{ font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-bottom: 2px; }
.tg-ring-sub  { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
.tg-pct-pill  {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; border-radius: 20px;
    padding: 2px 9px; margin-top: 5px;
}

/* Section label */
.tg-section-lbl {
    font-size: 10px; font-weight: 800; letter-spacing: .12em;
    text-transform: uppercase; color: var(--text-muted);
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 14px;
}
.tg-section-lbl::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Chart cards */
.tg-chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 20px;
}
.tg-chart-hdr {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 18px; flex-wrap: wrap; gap: 8px;
}
.tg-chart-title {
    font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 8px;
}
.tg-chart-title i { font-size: 14px; }

/* Range buttons */
.tg-range-btn {
    font-size: 11px; font-weight: 700; padding: 5px 14px; border-radius: 20px;
    border: 1px solid var(--border); background: var(--bg-base);
    color: var(--text-muted); text-decoration: none; transition: .15s;
}
.tg-range-btn:hover { border-color: var(--accent); color: var(--accent); }
.tg-range-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 3px 12px rgba(37,99,235,.3); }

/* Legend dots */
.tg-legend { display: flex; gap: 16px; flex-wrap: wrap; }
.tg-leg-item { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-muted); }
.tg-leg-dot  { width: 10px; height: 3px; border-radius: 2px; flex-shrink: 0; }
.tg-leg-dot.dashed { background: repeating-linear-gradient(90deg,#f59e0b 0,#f59e0b 5px,transparent 5px,transparent 9px); }

/* Daily table */
.tg-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.tg-table th {
    padding: 9px 12px; font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: var(--text-muted); border-bottom: 1px solid var(--border);
    text-align: left;
}
.tg-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.tg-table tr:last-child td { border-bottom: none; }
.tg-table tr:hover td { background: var(--bg-base); }
.tg-status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700; border-radius: 20px; padding: 3px 9px;
}
.tg-status-hit    { background: rgba(34,197,94,.15);  color: #16a34a; }
.tg-status-partial{ background: rgba(245,158,11,.15); color: #d97706; }
.tg-status-miss   { background: rgba(239,68,68,.15);  color: #dc2626; }
.tg-status-none   { background: var(--border);        color: var(--text-muted); }

/* Target item in set-targets form */
.tg-input-group {
    background: var(--bg-base);
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
    transition: border-color .2s;
}
.tg-input-group:focus-within { border-color: var(--accent); }
.tg-input-lbl { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 6px; }
.tg-input-desc { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

/* No-target banner */
.tg-empty {
    text-align: center;
    padding: 48px 24px;
    background: var(--bg-card);
    border: 1px dashed var(--border);
    border-radius: 18px;
    margin-bottom: 20px;
}
</style>

<?php if ($saved): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert" id="tgFlash" style="border-radius:12px">
    <i class="fas fa-check-circle me-2"></i>Targets saved successfully!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<script>setTimeout(function(){ var e=document.getElementById('tgFlash'); if(e) e.classList.remove('show'); }, 3000);</script>
<?php endif; ?>

<!-- Page Header -->
<div class="tg-page-hdr">
    <div class="d-flex align-items-center gap-3">
        <div class="tg-icon-box">
            <i class="fas fa-bullseye" style="font-size:20px;color:#f59e0b"></i>
        </div>
        <div>
            <h5 style="font-size:1.1rem;font-weight:800;margin:0 0 2px">Trading Targets</h5>
            <div style="font-size:12px;color:var(--text-muted)">
                Track your progress against daily, weekly &amp; monthly goals
            </div>
        </div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#setTargetsModal"
            style="border-radius:12px;font-weight:700;padding:9px 20px">
        <i class="fas fa-sliders me-2"></i>Set Targets
    </button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- KPI Cards -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tg-kpi-grid">

    <!-- Monthly P&L -->
    <div class="tg-kpi-card amber">
        <div class="tg-ring-label" style="margin-bottom:12px"><i class="fas fa-calendar-days me-1"></i>Monthly P&L</div>
        <div class="tg-kpi-ring-wrap">
            <?php
            $mRingPct  = $monthTarget > 0 ? min(100, max(0, $monthActual / $monthTarget * 100)) : 0;
            $mCirc     = 2 * M_PI * 34;
            $mOffset   = $mCirc * (1 - $mRingPct / 100);
            $mColor    = $monthActual >= $monthTarget && $monthTarget > 0 ? '#16a34a' : ($monthActual > 0 ? '#f59e0b' : '#ef4444');
            ?>
            <svg class="tg-ring-svg" width="80" height="80" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border)" stroke-width="6"/>
                <circle cx="40" cy="40" r="34" fill="none" stroke="<?= $mColor ?>" stroke-width="6"
                        stroke-dasharray="<?= round($mCirc,2) ?>" stroke-dashoffset="<?= round($mOffset,2) ?>"
                        stroke-linecap="round" transform="rotate(-90 40 40)"
                        style="transition:stroke-dashoffset 1s ease"/>
                <text x="40" y="44" text-anchor="middle" font-size="13" font-weight="800" fill="<?= $mColor ?>"
                      font-family="'DM Mono',monospace"><?= $monthPct !== null ? (int)$mRingPct . '%' : '—' ?></text>
            </svg>
            <div class="tg-ring-info">
                <div class="tg-ring-val" style="color:<?= $monthActual >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                    <?= ($monthActual >= 0 ? '+' : '') ?>$<?= number_format($monthActual, 0) ?>
                </div>
                <div class="tg-ring-sub">
                    Target: $<?= number_format($monthTarget, 0) ?>
                </div>
                <?php if ($monthPct !== null): ?>
                <div class="tg-pct-pill" style="background:<?= $mRingPct >= 100 ? 'rgba(34,197,94,.15)' : 'rgba(245,158,11,.15)' ?>;color:<?= $mRingPct >= 100 ? '#16a34a' : '#d97706' ?>">
                    <i class="fas fa-arrow-<?= $mRingPct >= 100 ? 'up' : 'right' ?>" style="font-size:9px"></i>
                    <?= $monthPct ?>% of goal
                </div>
                <?php else: ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:5px">No target set</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Weekly P&L -->
    <div class="tg-kpi-card blue">
        <div class="tg-ring-label" style="margin-bottom:12px"><i class="fas fa-calendar-week me-1"></i>This Week</div>
        <div class="tg-kpi-ring-wrap">
            <?php
            $wRingPct = $weekTarget > 0 ? min(100, max(0, $weekActual / $weekTarget * 100)) : 0;
            $wOffset  = $mCirc * (1 - $wRingPct / 100);
            $wColor   = $weekActual >= $weekTarget && $weekTarget > 0 ? '#16a34a' : ($weekActual > 0 ? '#2563eb' : '#ef4444');
            ?>
            <svg class="tg-ring-svg" width="80" height="80" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border)" stroke-width="6"/>
                <circle cx="40" cy="40" r="34" fill="none" stroke="<?= $wColor ?>" stroke-width="6"
                        stroke-dasharray="<?= round($mCirc,2) ?>" stroke-dashoffset="<?= round($wOffset,2) ?>"
                        stroke-linecap="round" transform="rotate(-90 40 40)"
                        style="transition:stroke-dashoffset 1s ease"/>
                <text x="40" y="44" text-anchor="middle" font-size="13" font-weight="800" fill="<?= $wColor ?>"
                      font-family="'DM Mono',monospace"><?= $weekPct !== null ? (int)$wRingPct . '%' : '—' ?></text>
            </svg>
            <div class="tg-ring-info">
                <div class="tg-ring-val" style="color:<?= $weekActual >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                    <?= ($weekActual >= 0 ? '+' : '') ?>$<?= number_format($weekActual, 0) ?>
                </div>
                <div class="tg-ring-sub">Target: $<?= number_format($weekTarget, 0) ?></div>
                <?php if ($weekPct !== null): ?>
                <div class="tg-pct-pill" style="background:<?= $wRingPct >= 100 ? 'rgba(34,197,94,.15)' : 'rgba(37,99,235,.12)' ?>;color:<?= $wRingPct >= 100 ? '#16a34a' : '#2563eb' ?>">
                    <i class="fas fa-arrow-<?= $wRingPct >= 100 ? 'up' : 'right' ?>" style="font-size:9px"></i>
                    <?= $weekPct ?>% of goal
                </div>
                <?php else: ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:5px">No target set</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Win Rate -->
    <div class="tg-kpi-card green">
        <div class="tg-ring-label" style="margin-bottom:12px"><i class="fas fa-trophy me-1"></i>Win Rate</div>
        <div class="tg-kpi-ring-wrap">
            <?php
            $wrRingPct = $wrTarget > 0 ? min(100, max(0, $monthWinRate / $wrTarget * 100)) : 0;
            $wrOffset  = $mCirc * (1 - $wrRingPct / 100);
            $wrColor   = $monthWinRate >= $wrTarget ? '#16a34a' : ($monthWinRate >= $wrTarget * 0.8 ? '#f59e0b' : '#ef4444');
            ?>
            <svg class="tg-ring-svg" width="80" height="80" viewBox="0 0 80 80">
                <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border)" stroke-width="6"/>
                <circle cx="40" cy="40" r="34" fill="none" stroke="<?= $wrColor ?>" stroke-width="6"
                        stroke-dasharray="<?= round($mCirc,2) ?>" stroke-dashoffset="<?= round($wrOffset,2) ?>"
                        stroke-linecap="round" transform="rotate(-90 40 40)"
                        style="transition:stroke-dashoffset 1s ease"/>
                <text x="40" y="44" text-anchor="middle" font-size="12" font-weight="800" fill="<?= $wrColor ?>"
                      font-family="'DM Mono',monospace"><?= $monthWinRate ?>%</text>
            </svg>
            <div class="tg-ring-info">
                <div class="tg-ring-val" style="color:<?= $wrColor ?>"><?= $monthWinRate ?>%</div>
                <div class="tg-ring-sub">Target: <?= $wrTarget ?>%</div>
                <div class="tg-pct-pill" style="background:<?= $monthWinRate >= $wrTarget ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.12)' ?>;color:<?= $monthWinRate >= $wrTarget ? '#16a34a' : '#dc2626' ?>">
                    <?= $monthWinRate >= $wrTarget ? '✓ On target' : round($wrTarget - $monthWinRate, 1) . '% gap' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Streak -->
    <div class="tg-kpi-card purple">
        <div class="tg-ring-label" style="margin-bottom:12px"><i class="fas fa-fire me-1"></i>Target Streak</div>
        <div style="display:flex;align-items:flex-end;gap:8px;margin-bottom:10px">
            <div style="font-size:52px;font-weight:900;font-family:'DM Mono',monospace;line-height:1;color:<?= $hitStreak >= 5 ? '#f59e0b' : ($hitStreak >= 1 ? 'var(--profit)' : 'var(--text-muted)') ?>">
                <?= $hitStreak ?>
            </div>
            <div style="font-size:14px;font-weight:700;color:var(--text-muted);padding-bottom:6px">days</div>
        </div>
        <div style="font-size:11px;color:var(--text-muted)">
            <?php if ($hitStreak >= 5): ?>
                <span style="color:#f59e0b"><i class="fas fa-fire me-1"></i>On fire! Keep going!</span>
            <?php elseif ($hitStreak >= 1): ?>
                <span style="color:var(--profit)"><i class="fas fa-check me-1"></i>Consecutive days hitting target</span>
            <?php else: ?>
                <span>Hit your daily target to start a streak</span>
            <?php endif; ?>
        </div>
        <div style="margin-top:10px;font-size:11px;color:var(--text-muted)">
            <i class="fas fa-calendar-check me-1" style="color:var(--profit)"></i>
            <?= $daysHitMonth ?> / <?= $monthDaysTraded ?> days hit this month
        </div>
    </div>

</div>

<?php if (!$hasTargets): ?>
<!-- Empty state nudge -->
<div class="tg-empty mb-4">
    <i class="fas fa-bullseye" style="font-size:48px;color:var(--text-muted);opacity:.3;display:block;margin-bottom:16px"></i>
    <div style="font-size:16px;font-weight:700;margin-bottom:6px">No targets set yet</div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px">
        Set your daily, weekly and monthly goals to unlock the performance curve.
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#setTargetsModal" style="border-radius:12px;font-weight:700">
        <i class="fas fa-sliders me-2"></i>Set My Targets
    </button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Main: Cumulative Curve -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (count($dailyRows) > 0): ?>

<div class="tg-chart-card">
    <div class="tg-chart-hdr">
        <div class="tg-chart-title">
            <div style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,rgba(245,158,11,.2),rgba(234,88,12,.15));display:flex;align-items:center;justify-content:center">
                <i class="fas fa-chart-line" style="color:#f59e0b;font-size:13px"></i>
            </div>
            Cumulative Performance vs Target Curve
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="tg-legend">
                <div class="tg-leg-item">
                    <div class="tg-leg-dot" style="background:#2563eb;height:3px;width:18px;border-radius:2px"></div>
                    Actual P&L
                </div>
                <div class="tg-leg-item">
                    <div class="tg-leg-dot dashed" style="width:18px;height:3px"></div>
                    Target
                </div>
            </div>
            <div class="d-flex gap-1">
                <?php foreach ([7, 30, 90] as $r): ?>
                <a href="?range=<?= $r ?>" class="tg-range-btn <?= $range === $r ? 'active' : '' ?>"><?= $r ?>d</a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div style="position:relative;height:280px">
        <canvas id="curveChart"></canvas>
    </div>
    <?php if (count($chartCumActual)):
        $finalActual = end($chartCumActual);
        $finalTarget = end($chartCumTarget);
        $gap = $finalActual - $finalTarget;
    ?>
    <div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap">
        <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:12px;padding:10px 16px;display:flex;align-items:center;gap:10px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= $finalActual >= 0 ? '#16a34a' : '#ef4444' ?>"></div>
            <div>
                <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em">Cumulative Actual</div>
                <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:800;color:<?= $finalActual >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                    <?= ($finalActual >= 0 ? '+' : '') ?>$<?= number_format($finalActual, 2) ?>
                </div>
            </div>
        </div>
        <?php if ($hasTargets): ?>
        <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:12px;padding:10px 16px;display:flex;align-items:center;gap:10px">
            <div style="width:8px;height:8px;border-radius:50%;background:#f59e0b"></div>
            <div>
                <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em">Cumulative Target</div>
                <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:800;color:#d97706">
                    +$<?= number_format($finalTarget, 2) ?>
                </div>
            </div>
        </div>
        <div style="background:<?= $gap >= 0 ? 'rgba(34,197,94,.08)' : 'rgba(239,68,68,.08)' ?>;border:1px solid <?= $gap >= 0 ? 'rgba(34,197,94,.3)' : 'rgba(239,68,68,.3)' ?>;border-radius:12px;padding:10px 16px;display:flex;align-items:center;gap:10px">
            <i class="fas fa-arrow-<?= $gap >= 0 ? 'up' : 'down' ?>" style="color:<?= $gap >= 0 ? '#16a34a' : '#dc2626' ?>;font-size:14px"></i>
            <div>
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">
                    <?= $gap >= 0 ? 'Ahead of target' : 'Behind target' ?>
                </div>
                <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:800;color:<?= $gap >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                    <?= ($gap >= 0 ? '+' : '') ?>$<?= number_format($gap, 2) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:12px;padding:10px 16px;display:flex;align-items:center;gap:10px">
            <i class="fas fa-chart-bar" style="color:var(--accent);font-size:14px"></i>
            <div>
                <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em">Avg / Trading Day</div>
                <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:800;color:<?= $avgDay >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                    <?= ($avgDay >= 0 ? '+' : '') ?>$<?= number_format($avgDay, 2) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Daily Bar Chart + Recent Table -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <!-- Daily P&L bars -->
    <div class="col-12 col-lg-7">
        <div class="tg-chart-card" style="margin-bottom:0;height:100%">
            <div class="tg-chart-hdr">
                <div class="tg-chart-title">
                    <div style="width:32px;height:32px;border-radius:9px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-chart-column" style="color:var(--accent);font-size:13px"></i>
                    </div>
                    Daily P&L vs Target
                </div>
                <?php if ($hasTargets): ?>
                <div style="font-size:11px;color:var(--text-muted)">
                    <span style="display:inline-block;width:10px;height:10px;background:rgba(34,197,94,.8);border-radius:2px;margin-right:3px"></span>Hit
                    <span style="display:inline-block;width:10px;height:10px;background:rgba(239,68,68,.8);border-radius:2px;margin-left:8px;margin-right:3px"></span>Miss
                </div>
                <?php endif; ?>
            </div>
            <div style="position:relative;height:240px">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent days table -->
    <div class="col-12 col-lg-5">
        <div class="tg-chart-card" style="margin-bottom:0;height:100%;padding:0">
            <div style="padding:18px 20px;border-bottom:1px solid var(--border)">
                <div class="tg-chart-title">
                    <div style="width:32px;height:32px;border-radius:9px;background:rgba(99,102,241,.1);display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-list-check" style="color:#6366f1;font-size:13px"></i>
                    </div>
                    Recent Days
                </div>
            </div>
            <div style="overflow-y:auto;max-height:288px">
                <table class="tg-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Net P&L</th>
                            <th>Trades</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $tableRows = array_slice(array_reverse($dailyRows), 0, 20);
                    foreach ($tableRows as $r):
                        $rPL  = (float)$r['net_pl'];
                        $rWR  = (int)$r['cnt'] > 0 ? round((float)$r['wins'] / (int)$r['cnt'] * 100) : 0;
                        if ($dailyTarget > 0 && $rPL >= $dailyTarget)      { $stClass='tg-status-hit';     $stLabel='✓ Hit'; }
                        elseif ($rPL > 0 && $dailyTarget > 0)              { $stClass='tg-status-partial'; $stLabel='↑ Partial'; }
                        elseif ($rPL > 0)                                   { $stClass='tg-status-hit';     $stLabel='✓ Profit'; }
                        elseif ($rPL < 0)                                   { $stClass='tg-status-miss';    $stLabel='✗ Loss'; }
                        else                                                { $stClass='tg-status-none';    $stLabel='— Flat'; }
                    ?>
                    <tr>
                        <td style="font-weight:600;font-size:12px">
                            <?= date('d M', strtotime($r['d'])) ?>
                            <div style="font-size:10px;color:var(--text-muted)"><?= date('D', strtotime($r['d'])) ?></div>
                        </td>
                        <td>
                            <span style="font-family:'DM Mono',monospace;font-weight:700;color:<?= $rPL >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
                                <?= ($rPL >= 0 ? '+' : '') ?>$<?= number_format($rPL, 2) ?>
                            </span>
                            <?php if ($dailyTarget > 0): ?>
                            <div style="font-size:10px;color:var(--text-muted)">
                                <?= round($rPL / $dailyTarget * 100) ?>% of $<?= number_format($dailyTarget, 0) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted);font-size:12px">
                            <?= $r['cnt'] ?> trades
                            <div style="font-size:10px"><?= $rWR ?>% WR</div>
                        </td>
                        <td><span class="tg-status-pill <?= $stClass ?>"><?= $stLabel ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- No trade data -->
<div class="tg-empty">
    <i class="fas fa-chart-mixed" style="font-size:48px;color:var(--text-muted);opacity:.3;display:block;margin-bottom:16px"></i>
    <div style="font-size:15px;font-weight:700;margin-bottom:6px">No trade data in the last <?= $range ?> days</div>
    <div style="font-size:13px;color:var(--text-muted)">Trade data will appear here once you log trades.</div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Set Targets Modal -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="setTargetsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:20px;border:1px solid var(--border);background:var(--bg-card)">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:20px 24px">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,rgba(245,158,11,.22),rgba(234,88,12,.15));display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-sliders" style="color:#f59e0b"></i>
                    </div>
                    <div>
                        <div style="font-size:15px;font-weight:800">Set Your Trading Targets</div>
                        <div style="font-size:12px;color:var(--text-muted)">Define your goals to track performance against them</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="margin-left:auto"></button>
            </div>
            <div class="modal-body" style="padding:24px">
                <form method="POST" action="targets.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_targets">

                    <!-- P&L Targets -->
                    <div style="font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:12px">
                        <i class="fas fa-dollar-sign me-1"></i> P&L Goals
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-sun me-1" style="color:#f59e0b"></i>Daily Target</div>
                                <div class="input-group mt-1">
                                    <span class="input-group-text" style="background:var(--bg-card);border-color:transparent;color:var(--text-muted);padding:0 8px">$</span>
                                    <input type="number" name="daily_pl_target" class="form-control" step="0.01" min="0"
                                           value="<?= htmlspecialchars($targets['daily_pl_target']) ?>"
                                           placeholder="0.00"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px;padding-left:0">
                                </div>
                                <div class="tg-input-desc">Minimum P&L per trading day</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-calendar-week me-1" style="color:#2563eb"></i>Weekly Target</div>
                                <div class="input-group mt-1">
                                    <span class="input-group-text" style="background:var(--bg-card);border-color:transparent;color:var(--text-muted);padding:0 8px">$</span>
                                    <input type="number" name="weekly_pl_target" class="form-control" step="0.01" min="0"
                                           value="<?= htmlspecialchars($targets['weekly_pl_target']) ?>"
                                           placeholder="0.00"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px;padding-left:0">
                                </div>
                                <div class="tg-input-desc">Minimum P&L per week</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-calendar-days me-1" style="color:#f59e0b"></i>Monthly Target</div>
                                <div class="input-group mt-1">
                                    <span class="input-group-text" style="background:var(--bg-card);border-color:transparent;color:var(--text-muted);padding:0 8px">$</span>
                                    <input type="number" name="monthly_pl_target" class="form-control" step="0.01" min="0"
                                           value="<?= htmlspecialchars($targets['monthly_pl_target']) ?>"
                                           placeholder="0.00"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px;padding-left:0">
                                </div>
                                <div class="tg-input-desc">Minimum P&L per month</div>
                            </div>
                        </div>
                    </div>

                    <!-- Quality Targets -->
                    <div style="font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:12px">
                        <i class="fas fa-star me-1"></i> Quality Goals
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-trophy me-1" style="color:#16a34a"></i>Win Rate Target</div>
                                <div class="input-group mt-1">
                                    <input type="number" name="win_rate_target" class="form-control" step="0.1" min="0" max="100"
                                           value="<?= htmlspecialchars($targets['win_rate_target']) ?>"
                                           placeholder="60"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px">
                                    <span class="input-group-text" style="background:var(--bg-card);border-color:transparent;color:var(--text-muted)">%</span>
                                </div>
                                <div class="tg-input-desc">% of trades that must win</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-layer-group me-1" style="color:#dc2626"></i>Max Daily Trades</div>
                                <div class="input-group mt-1">
                                    <input type="number" name="max_daily_trades" class="form-control" step="1" min="1"
                                           value="<?= htmlspecialchars($targets['max_daily_trades']) ?>"
                                           placeholder="5"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px">
                                </div>
                                <div class="tg-input-desc">Maximum trades allowed per day</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tg-input-group">
                                <div class="tg-input-lbl"><i class="fas fa-scale-balanced me-1" style="color:#6366f1"></i>Min R:R Ratio</div>
                                <div class="input-group mt-1">
                                    <span class="input-group-text" style="background:var(--bg-card);border-color:transparent;color:var(--text-muted);padding:0 8px">1:</span>
                                    <input type="number" name="min_rr_ratio" class="form-control" step="0.1" min="0.1"
                                           value="<?= htmlspecialchars($targets['min_rr_ratio']) ?>"
                                           placeholder="2.0"
                                           style="border-color:transparent;background:transparent;font-weight:700;font-size:18px;padding-left:4px">
                                </div>
                                <div class="tg-input-desc">Minimum risk:reward per trade</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius:12px" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="border-radius:12px;font-weight:700;padding:10px 28px">
                            <i class="fas fa-bullseye me-2"></i>Save Targets
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridC  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const textC  = isDark ? '#94a3b8' : '#64748b';

    const dates      = <?= json_encode($chartDates) ?>;
    const cumActual  = <?= json_encode($chartCumActual) ?>;
    const cumTarget  = <?= json_encode($chartCumTarget) ?>;
    const dailyPL    = <?= json_encode($chartDailyPL) ?>;
    const barBg      = <?= json_encode($chartBarColors) ?>;
    const barBorder  = <?= json_encode($chartBarBorder) ?>;
    const dailyTgt   = <?= json_encode($dailyTarget) ?>;

    // ── Cumulative Curve Chart ────────────────────────────────────────────────
    const cvCtx = document.getElementById('curveChart');
    if (cvCtx && dates.length > 0) {
        const lastVal = cumActual[cumActual.length - 1] ?? 0;
        const lineColor = lastVal >= 0 ? '#2563eb' : '#ef4444';

        const curveDatasets = [{
            label: 'Actual',
            data: cumActual,
            borderColor: lineColor,
            backgroundColor: function(ctx) {
                const chart = ctx.chart;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return lineColor + '20';
                const grad = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                grad.addColorStop(0, lineColor + '40');
                grad.addColorStop(1, lineColor + '05');
                return grad;
            },
            fill: true,
            borderWidth: 2.5,
            tension: 0.4,
            pointRadius: dates.length > 20 ? 0 : 4,
            pointHoverRadius: 6,
            pointBackgroundColor: lineColor,
        }];

        if (cumTarget.some(v => v > 0)) {
            curveDatasets.push({
                label: 'Target',
                data: cumTarget,
                borderColor: '#f59e0b',
                borderWidth: 2,
                borderDash: [6, 4],
                fill: false,
                tension: 0,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointBackgroundColor: '#f59e0b',
            });
        }

        new Chart(cvCtx, {
            type: 'line',
            data: { labels: dates, datasets: curveDatasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1,
                        titleColor: textC,
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        padding: 12,
                        callbacks: {
                            label: ctx => {
                                const lbl = ctx.dataset.label;
                                const v   = ctx.parsed.y;
                                return ' ' + lbl + ': ' + (v >= 0 ? '+' : '') + '$' + v.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid:{color:gridC}, ticks:{color:textC,font:{size:10},maxTicksLimit:12,maxRotation:30} },
                    y: {
                        grid:{color:gridC},
                        ticks:{color:textC,font:{size:10},callback:v=>'$'+v},
                        afterDataLimits(axis) {
                            const pad = (axis.max - axis.min) * 0.08 || 10;
                            axis.min -= pad; axis.max += pad;
                        }
                    }
                }
            }
        });
    }

    // ── Daily Bar Chart ───────────────────────────────────────────────────────
    const barCtx = document.getElementById('barChart');
    if (barCtx && dates.length > 0) {
        const datasets = [{
            label: 'Net P&L',
            data: dailyPL,
            backgroundColor: barBg,
            borderColor: barBorder,
            borderWidth: 1,
            borderRadius: 4,
            borderSkipped: false,
        }];

        // Daily target reference line
        if (dailyTgt > 0) {
            datasets.push({
                label: 'Daily Target',
                data: Array(dates.length).fill(dailyTgt),
                type: 'line',
                borderColor: '#f59e0b',
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 0,
                fill: false,
            });
        }

        new Chart(barCtx, {
            type: 'bar',
            data: { labels: dates, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1,
                        titleColor: textC,
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        padding: 10,
                        callbacks: {
                            label: ctx => {
                                const v = ctx.parsed.y;
                                if (ctx.dataset.label === 'Daily Target') return ' Target: $' + v.toFixed(2);
                                return ' Net P&L: ' + (v >= 0 ? '+' : '') + '$' + v.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid:{color:gridC}, ticks:{color:textC,font:{size:10},maxTicksLimit:14,maxRotation:45} },
                    y: {
                        grid:{color:gridC},
                        ticks:{color:textC,font:{size:10},callback:v=>'$'+v},
                    }
                }
            }
        });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../config/db.php';
$db     = getDB();
$userId = DEFAULT_USER_ID;

// ── Validate date param ───────────────────────────────────────────────────
$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    header('Location: calendar.php'); exit;
}

// ── DELETE all trades for this date ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_day') {
    $del = $db->prepare("DELETE FROM trades WHERE user_id = ? AND DATE(trade_datetime) = ?");
    $del->execute([$userId, $date]);
    $backM = date('n', strtotime($date));
    $backY = date('Y', strtotime($date));
    header("Location: calendar.php?month={$backM}&year={$backY}&deleted=1");
    exit;
}

$ts        = strtotime($date);
$prevDate  = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate  = date('Y-m-d', strtotime($date . ' +1 day'));
$backMonth = date('n', $ts);
$backYear  = date('Y', $ts);

// ── All trades for this day ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT *
    FROM trades
    WHERE user_id = ? AND DATE(trade_datetime) = ?
    ORDER BY trade_datetime ASC
");
$stmt->execute([$userId, $date]);
$trades = $stmt->fetchAll();

if (empty($trades)) {
    // No trades — bounce back to calendar
    header("Location: calendar.php?month={$backMonth}&year={$backYear}");
    exit;
}

// ── Day aggregates ────────────────────────────────────────────────────────
$grossPL    = array_sum(array_column($trades, 'profit_loss'));
$totalBrok  = array_sum(array_column($trades, 'brokerage'));
$totalSwap  = array_sum(array_column($trades, 'swap'));
$netPL      = $grossPL - $totalBrok + $totalSwap;
$total      = count($trades);
$wins       = count(array_filter(array_column($trades, 'profit_loss'), fn($v) => $v > 0));
$losses     = $total - $wins;
$winRate    = $total > 0 ? round($wins / $total * 100, 1) : 0;
$avgWin     = $wins   > 0 ? round(array_sum(array_filter(array_column($trades,'profit_loss'),fn($v)=>$v>0)) / $wins, 2) : 0;
$worstLoss  = $losses > 0 ? min(array_column($trades, 'profit_loss')) : 0;
$bestTrade  = max(array_column($trades, 'profit_loss'));

// Duration stats (uses open_time vs trade_datetime)
$durations = [];
foreach ($trades as $t) {
    if ($t['open_time'] && $t['trade_datetime']) {
        $d = max(0, strtotime($t['trade_datetime']) - strtotime($t['open_time']));
        $durations[] = $d;
    }
}
$avgDurSec = count($durations) > 0 ? array_sum($durations) / count($durations) : null;

function fmtDur($secs) {
    $h = floor($secs/3600); $m = floor(($secs%3600)/60); $s = $secs % 60;
    return ($h > 0 ? $h.'h ' : '') . ($m > 0 ? $m.'m ' : '') . $s.'s';
}

// SL/TP/Manual breakdown
$slC = $tpC = $manC = $soC = 0;
foreach ($trades as $t) {
    switch (strtolower($t['close_reason'] ?? '')) {
        case 'sl': $slC++; break; case 'tp': $tpC++; break;
        case 'user': $manC++; break; case 'so': $soC++; break;
    }
}

// Symbol breakdown
$symMap = [];
foreach ($trades as $t) {
    $s = strtoupper($t['symbol']);
    if (!isset($symMap[$s])) $symMap[$s] = ['c'=>0,'pl'=>0.0,'brok'=>0.0,'w'=>0];
    $symMap[$s]['c']++;
    $symMap[$s]['pl']  += (float)$t['profit_loss'];
    $symMap[$s]['brok']+= (float)$t['brokerage'];
    if ($t['profit_loss'] > 0) $symMap[$s]['w']++;
}

// Running equity during the day (for mini chart)
$runningEq = []; $running = 0.0;
$chartTimes = []; $chartVals = [];
foreach ($trades as $t) {
    $running += (float)$t['profit_loss'] - (float)$t['brokerage'] + (float)$t['swap'];
    $chartTimes[] = date('H:i', strtotime($t['trade_datetime']));
    $chartVals[]  = round($running, 2);
    $runningEq[]  = $running;
}

// Candlestick data — one floating bar per trade (equity before → equity after)
$csLabels = []; $csBarData = []; $csBgColors = []; $csBorderColors = []; $csTooltips = [];
$csEq = 0.0;
foreach ($trades as $t) {
    $o = round($csEq, 2);
    $csEq += (float)$t['profit_loss'] - (float)$t['brokerage'] + (float)$t['swap'];
    $c   = round($csEq, 2);
    $win = (float)$t['profit_loss'] >= 0;
    $csLabels[]       = date('H:i:s', strtotime($t['trade_datetime']));
    $csBarData[]      = [$o, $c];
    $csBgColors[]     = $win ? 'rgba(34,197,94,.78)' : 'rgba(239,68,68,.78)';
    $csBorderColors[] = $win ? '#16a34a' : '#dc2626';
    $csTooltips[]     = strtoupper($t['symbol']) . ' ' . strtoupper($t['trade_type'] ?? 'BUY')
                        . '  |  ' . ($t['profit_loss'] >= 0 ? '+' : '') . '$' . number_format($t['profit_loss'], 2)
                        . '  |  Net Δ ' . ($c >= $o ? '+' : '') . '$' . number_format($c - $o, 2);
}

$reasonLabels = ['sl'=>'SL','tp'=>'TP','user'=>'Manual','so'=>'SO'];
$reasonColors = ['sl'=>'var(--loss)','tp'=>'var(--profit)','user'=>'var(--text-muted)','so'=>'var(--warning)'];

// ── Capital snapshot: mirror getCurrentBalance() logic anchored to day open ─
// Find the most recent stop-out that occurred BEFORE this date (same logic as getCurrentBalance)
$soStmt = $db->prepare("
    SELECT date FROM transactions
    WHERE user_id=? AND type='stop_out' AND date < ?
    ORDER BY date DESC, created_at DESC LIMIT 1
");
$soStmt->execute([$userId, $date]);
$lastSOBefore = $soStmt->fetch();

if ($lastSOBefore) {
    // There was a stop-out before this date — only count deposits/P/L after that stop-out and before this date
    $sinceDate = $lastSOBefore['date'];
    $txStmt2 = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='deposit'  THEN amount ELSE 0 END),0) AS deps,
               COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END),0) AS wds
        FROM transactions
        WHERE user_id=? AND type IN ('deposit','withdraw') AND date > ? AND date < ?
    ");
    $txStmt2->execute([$userId, $sinceDate, $date]);
    $txRow2 = $txStmt2->fetch();
    $plStmt2 = $db->prepare("
        SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) AS net_pl
        FROM trades WHERE user_id=? AND DATE(trade_datetime) > ? AND DATE(trade_datetime) < ?
    ");
    $plStmt2->execute([$userId, $sinceDate, $date]);
    $balAtStart = (float)$txRow2['deps'] - (float)$txRow2['wds'] + (float)$plStmt2->fetch()['net_pl'];
} else {
    // No prior stop-out — sum everything before this date
    $txStmt2 = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='deposit'  THEN amount ELSE 0 END),0) AS deps,
               COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END),0) AS wds
        FROM transactions
        WHERE user_id=? AND type IN ('deposit','withdraw') AND date < ?
    ");
    $txStmt2->execute([$userId, $date]);
    $txRow2 = $txStmt2->fetch();
    $plStmt2 = $db->prepare("
        SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) AS net_pl
        FROM trades WHERE user_id=? AND DATE(trade_datetime) < ?
    ");
    $plStmt2->execute([$userId, $date]);
    $balAtStart = (float)$txRow2['deps'] - (float)$txRow2['wds'] + (float)$plStmt2->fetch()['net_pl'];
}

$peakCapital   = empty($runningEq) ? $balAtStart : ($balAtStart + max($runningEq));
$troughCapital = empty($runningEq) ? $balAtStart : ($balAtStart + min($runningEq));
$closeCapital  = $balAtStart + $netPL;

$pageTitle = 'Day Detail — ' . date('d M Y', $ts);
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
.day-nav     { display:flex;align-items:center;justify-content:space-between;margin-bottom:20px; }
.day-nav-btn { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);
               padding:7px 14px;color:var(--text-primary);cursor:pointer;text-decoration:none;
               font-size:13px;transition:border-color .15s; }
.day-nav-btn:hover { border-color:var(--accent);color:var(--accent); }
.day-nav-back{ display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);
               text-decoration:none;padding:7px 14px;border:1px solid var(--border);
               border-radius:var(--radius-sm);background:var(--bg-surface);transition:color .15s,border-color .15s; }
.day-nav-back:hover{ color:var(--accent);border-color:var(--accent); }

.day-kpis    { display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px; }
.day-kpi     { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px; }
.day-kpi-lbl { font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:4px; }
.day-kpi-val { font-family:var(--font-mono);font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.1; }
.day-kpi-sub { font-size:11px;color:var(--text-muted);margin-top:3px; }

.panel       { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px; }
.panel-hdr   { padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;
               display:flex;align-items:center;gap:8px;color:var(--text-secondary); }
.panel-body  { padding:16px 18px; }

.charge-box  { display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;margin-bottom:0; }
.charge-item { padding:12px 16px;text-align:center; }
.charge-item+.charge-item { border-left:1px solid var(--border); }
.charge-lbl  { font-size:11px;color:var(--text-muted);margin-bottom:4px; }
.charge-val  { font-family:var(--font-mono);font-size:18px;font-weight:700; }

.tr-table    { width:100%;border-collapse:collapse;font-size:12px; }
.tr-table th { padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
               color:var(--text-muted);border-bottom:1px solid var(--border);text-align:left; }
.tr-table td { padding:9px 10px;border-bottom:1px solid var(--border-light);vertical-align:middle; }
.tr-table tr:last-child td { border-bottom:none; }
.tr-table tr:hover td { background:var(--bg-elevated); }

.pl-positive { color:var(--profit);font-weight:700; }
.pl-negative { color:var(--loss);font-weight:700;  }
.symbol-badge{ display:inline-block;background:rgba(37,99,235,.1);color:var(--accent);
               font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;
               font-family:var(--font-mono);letter-spacing:.5px; }
.type-buy    { background:rgba(22,163,74,.12);color:var(--profit);font-size:10px;font-weight:700;
               padding:2px 8px;border-radius:4px;display:inline-block; }
.type-sell   { background:rgba(220,38,38,.12);color:var(--loss);font-size:10px;font-weight:700;
               padding:2px 8px;border-radius:4px;display:inline-block; }

.stat-row    { display:flex;justify-content:space-between;align-items:center;padding:8px 0;
               border-bottom:1px solid var(--border-light);font-size:13px; }
.stat-row:last-child { border-bottom:none; }
.stat-row-lbl{ color:var(--text-muted); }
.stat-row-val{ font-weight:600;font-family:var(--font-mono); }

.pos { color:var(--profit); } .neg { color:var(--loss); } .wrn { color:var(--warning); }
</style>

<!-- Navigation -->
<div class="day-nav">
    <a href="day.php?date=<?= $prevDate ?>" class="day-nav-btn">
        <i class="fas fa-chevron-left"></i> <?= date('d M', strtotime($prevDate)) ?>
    </a>
    <a href="calendar.php?month=<?= $backMonth ?>&year=<?= $backYear ?>" class="day-nav-back">
        <i class="fas fa-calendar-days"></i> Back to <?= date('F Y', $ts) ?>
    </a>
    <button type="button" class="day-nav-btn"
            style="border-color:rgba(220,38,38,.4);color:var(--loss);background:rgba(220,38,38,.06)"
            data-bs-toggle="modal" data-bs-target="#deleteDayModal">
        <i class="fas fa-trash-can me-1"></i> Delete Day
    </button>
    <a href="day.php?date=<?= $nextDate ?>" class="day-nav-btn">
        <?= date('d M', strtotime($nextDate)) ?> <i class="fas fa-chevron-right"></i>
    </a>
</div>

<!-- Delete Day Confirmation Modal -->
<div class="modal fade" id="deleteDayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:1px solid rgba(220,38,38,.3);background:var(--bg-card)">
            <div class="modal-body p-4 text-center">
                <div style="width:56px;height:56px;border-radius:50%;background:rgba(220,38,38,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                    <i class="fas fa-triangle-exclamation" style="font-size:22px;color:var(--loss)"></i>
                </div>
                <h5 style="font-weight:800;margin-bottom:8px">Delete All Orders?</h5>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:4px">
                    This will permanently delete all <strong><?= $total ?> trade<?= $total !== 1 ? 's' : '' ?></strong> on
                </p>
                <p style="font-size:15px;font-weight:700;margin-bottom:20px">
                    <?= date('l, d F Y', $ts) ?>
                </p>
                <div style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);border-radius:10px;padding:10px 14px;font-size:12px;color:var(--loss);margin-bottom:24px;text-align:left">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    This action cannot be undone. All P&amp;L, brokerage, and swap data for this day will be erased.
                </div>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" style="border-radius:10px;min-width:100px" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <form method="POST" action="day.php" style="margin:0">
                        <input type="hidden" name="action" value="delete_day">
                        <input type="hidden" name="date"   value="<?= htmlspecialchars($date) ?>">
                        <button type="submit" class="btn btn-danger" style="border-radius:10px;min-width:140px;font-weight:700">
                            <i class="fas fa-trash-can me-1"></i> Yes, Delete All
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Day title -->
<div style="margin-bottom:16px">
    <h5 style="font-weight:700;font-size:20px;margin:0"><?= date('l, d F Y', $ts) ?></h5>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= $total ?> trades &nbsp;·&nbsp; <?= $wins ?>W / <?= $losses ?>L &nbsp;·&nbsp; <?= $winRate ?>% win rate</div>
</div>

<!-- KPI Row -->
<div class="day-kpis">
    <div class="day-kpi">
        <div class="day-kpi-lbl">Gross P&amp;L</div>
        <div class="day-kpi-val <?= $grossPL >= 0 ? 'pos' : 'neg' ?>">
            <?= ($grossPL >= 0 ? '+' : '') ?>$<?= number_format($grossPL, 2) ?>
        </div>
        <div class="day-kpi-sub">Before charges</div>
    </div>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Brokerage</div>
        <div class="day-kpi-val neg">-$<?= number_format($totalBrok, 2) ?></div>
        <div class="day-kpi-sub">Commission</div>
    </div>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Swap</div>
        <div class="day-kpi-val <?= $totalSwap >= 0 ? 'pos' : 'neg' ?>">
            <?= ($totalSwap >= 0 ? '+' : '') ?>$<?= number_format($totalSwap, 2) ?>
        </div>
        <div class="day-kpi-sub">Overnight</div>
    </div>
    <div class="day-kpi" style="border-color:<?= $netPL >= 0 ? 'rgba(22,163,74,.4)' : 'rgba(220,38,38,.4)' ?>">
        <div class="day-kpi-lbl">Net P&amp;L</div>
        <div class="day-kpi-val <?= $netPL >= 0 ? 'pos' : 'neg' ?>" style="font-size:22px">
            <?= ($netPL >= 0 ? '+' : '') ?>$<?= number_format($netPL, 2) ?>
        </div>
        <div class="day-kpi-sub">After all charges</div>
    </div>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Best Trade</div>
        <div class="day-kpi-val <?= $bestTrade >= 0 ? 'pos' : 'neg' ?>">
            <?= ($bestTrade >= 0 ? '+' : '') ?>$<?= number_format($bestTrade, 2) ?>
        </div>
        <div class="day-kpi-sub">Single best</div>
    </div>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Worst Trade</div>
        <div class="day-kpi-val neg">$<?= number_format($worstLoss, 2) ?></div>
        <div class="day-kpi-sub">Single worst</div>
    </div>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Avg Win</div>
        <div class="day-kpi-val pos">+$<?= $avgWin ?></div>
        <div class="day-kpi-sub"><?= $wins ?> winning trades</div>
    </div>
    <div class="day-kpi" style="border-color:rgba(22,163,74,.4);background:rgba(22,163,74,.03)">
        <div class="day-kpi-lbl" style="color:var(--profit)"><i class="fas fa-arrow-trend-up"></i> Peak Capital</div>
        <div class="day-kpi-val pos">$<?= number_format($peakCapital, 2) ?></div>
        <div class="day-kpi-sub">+$<?= number_format($peakCapital - $balAtStart, 2) ?> from open</div>
    </div>
    <?php if ($avgDurSec !== null): ?>
    <div class="day-kpi">
        <div class="day-kpi-lbl">Avg Duration</div>
        <div class="day-kpi-val wrn"><?= fmtDur((int)round($avgDurSec)) ?></div>
        <div class="day-kpi-sub">Per trade</div>
    </div>
    <?php endif; ?>
</div>

<!-- Capital Journey -->
<?php
$allCaps   = [$balAtStart, $peakCapital, $troughCapital, $closeCapital];
$rangeMin  = min($allCaps);
$rangeMax  = max($allCaps);
$rangeSpan = max(1, $rangeMax - $rangeMin);
$openPct   = round(($balAtStart    - $rangeMin) / $rangeSpan * 100);
$peakPct   = round(($peakCapital   - $rangeMin) / $rangeSpan * 100);
$troughPct = round(($troughCapital - $rangeMin) / $rangeSpan * 100);
$closePct  = round(($closeCapital  - $rangeMin) / $rangeSpan * 100);
$closeColor = $closeCapital >= $balAtStart ? 'var(--profit)' : 'var(--loss)';
?>
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-arrow-trend-up" style="color:var(--accent)"></i> Capital Journey</div>
    <!-- Four milestones -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);text-align:center">
        <div style="padding:16px 12px;border-right:1px solid var(--border-light)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:6px">
                <i class="fas fa-circle" style="color:var(--accent);font-size:8px"></i> Day Open
            </div>
            <div style="font-family:var(--font-mono);font-size:17px;font-weight:700;color:var(--text-primary)">
                $<?= number_format($balAtStart, 2) ?>
            </div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">Before first trade</div>
        </div>
        <div style="padding:16px 12px;border-right:1px solid var(--border-light);background:rgba(22,163,74,.05)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--profit);margin-bottom:6px">
                <i class="fas fa-arrow-up" style="font-size:8px"></i> Highest Reached
            </div>
            <div style="font-family:var(--font-mono);font-size:17px;font-weight:800;color:var(--profit)">
                $<?= number_format($peakCapital, 2) ?>
            </div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">
                +$<?= number_format($peakCapital - $balAtStart, 2) ?> above open
            </div>
        </div>
        <div style="padding:16px 12px;border-right:1px solid var(--border-light);background:rgba(220,38,38,.04)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--loss);margin-bottom:6px">
                <i class="fas fa-arrow-down" style="font-size:8px"></i> Lowest Reached
            </div>
            <div style="font-family:var(--font-mono);font-size:17px;font-weight:700;color:var(--loss)">
                $<?= number_format($troughCapital, 2) ?>
            </div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">
                <?= $troughCapital < $balAtStart
                    ? '-$' . number_format($balAtStart - $troughCapital, 2) . ' below open'
                    : '+$' . number_format($troughCapital - $balAtStart, 2) . ' above open' ?>
            </div>
        </div>
        <div style="padding:16px 12px;background:<?= $closeCapital >= $balAtStart ? 'rgba(22,163,74,.05)' : 'rgba(220,38,38,.04)' ?>">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:6px">
                <i class="fas fa-flag-checkered" style="font-size:8px"></i> Day Close
            </div>
            <div style="font-family:var(--font-mono);font-size:17px;font-weight:800;color:<?= $closeColor ?>">
                $<?= number_format($closeCapital, 2) ?>
            </div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">
                <?= $closeCapital >= $balAtStart ? '+' : '-' ?>$<?= number_format(abs($closeCapital - $balAtStart), 2) ?> net
            </div>
        </div>
    </div>

    <!-- Range bar -->
    <div style="padding:16px 20px;border-top:1px solid var(--border-light)">
        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px">
            Capital Range — $<?= number_format($rangeMin, 2) ?> to $<?= number_format($rangeMax, 2) ?>
            <span style="color:var(--text-muted);font-weight:400">(span: $<?= number_format($rangeSpan, 2) ?>)</span>
        </div>
        <div style="position:relative;height:8px;background:var(--bg-elevated);border-radius:99px;margin:10px 8px 22px">
            <!-- trough-to-peak filled band -->
            <div style="position:absolute;
                        left:<?= $troughPct ?>%;
                        width:<?= max(2, $peakPct - $troughPct) ?>%;
                        height:100%;
                        background:linear-gradient(90deg, rgba(220,38,38,.35), rgba(22,163,74,.35));
                        border-radius:99px"></div>
            <!-- trough dot -->
            <div style="position:absolute;left:<?= $troughPct ?>%;top:50%;transform:translate(-50%,-50%);
                        width:14px;height:14px;border-radius:50%;
                        background:var(--loss);border:2px solid var(--bg-surface)"
                 title="Trough $<?= number_format($troughCapital,2) ?>"></div>
            <!-- open dot (blue) -->
            <div style="position:absolute;left:<?= $openPct ?>%;top:50%;transform:translate(-50%,-50%);
                        width:14px;height:14px;border-radius:50%;
                        background:var(--accent);border:2px solid var(--bg-surface)"
                 title="Open $<?= number_format($balAtStart,2) ?>"></div>
            <!-- close dot (dashed border) -->
            <div style="position:absolute;left:<?= $closePct ?>%;top:50%;transform:translate(-50%,-50%);
                        width:14px;height:14px;border-radius:50%;
                        background:<?= $closeColor ?>;border:3px dashed var(--bg-surface);
                        outline:2px solid <?= $closeColor ?>"
                 title="Close $<?= number_format($closeCapital,2) ?>"></div>
            <!-- peak dot -->
            <div style="position:absolute;left:<?= $peakPct ?>%;top:50%;transform:translate(-50%,-50%);
                        width:16px;height:16px;border-radius:50%;
                        background:var(--profit);border:2px solid var(--bg-surface)"
                 title="Peak $<?= number_format($peakCapital,2) ?>"></div>
            <!-- labels under dots -->
            <div style="position:absolute;left:<?= $openPct ?>%;top:16px;transform:translateX(-50%);
                        font-size:9px;color:var(--accent);white-space:nowrap;font-weight:600">Open</div>
            <div style="position:absolute;left:<?= $peakPct ?>%;top:16px;transform:translateX(-50%);
                        font-size:9px;color:var(--profit);white-space:nowrap;font-weight:600">Peak</div>
            <div style="position:absolute;left:<?= $closePct ?>%;top:16px;transform:translateX(-50%);
                        font-size:9px;color:<?= $closeColor ?>;white-space:nowrap;font-weight:600">Close</div>
        </div>
    </div>
</div>

<!-- Equity Chart + Day Stats -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">
    <!-- Equity curve for the day -->
    <div class="panel" style="margin-bottom:0">
        <div class="panel-hdr" style="justify-content:space-between">
            <span><i class="fas fa-chart-line" style="color:var(--accent)"></i> Intraday Equity Curve</span>
            <div style="display:flex;gap:4px">
                <button id="btnLine" onclick="switchChart('line')"
                        style="font-size:11px;font-weight:700;padding:4px 13px;border-radius:20px;
                               border:1px solid var(--accent);background:var(--accent);color:#fff;
                               cursor:pointer;transition:.15s;outline:none">
                    <i class="fas fa-chart-line" style="font-size:10px"></i> Line
                </button>
                <button id="btnCandle" onclick="switchChart('candle')"
                        style="font-size:11px;font-weight:700;padding:4px 13px;border-radius:20px;
                               border:1px solid var(--border);background:var(--bg-surface);
                               color:var(--text-muted);cursor:pointer;transition:.15s;outline:none">
                    <i class="fas fa-chart-column" style="font-size:10px"></i> Candle
                </button>
            </div>
        </div>
        <div class="panel-body">
            <div id="lineWrap" style="position:relative;height:220px">
                <canvas id="eqChart" role="img" aria-label="Intraday equity curve"></canvas>
            </div>
            <div id="candleWrap" style="position:relative;height:220px;display:none">
                <canvas id="csChart" role="img" aria-label="Per-trade candlestick equity"></canvas>
            </div>
        </div>
    </div>

    <!-- Day Stats -->
    <div class="panel" style="margin-bottom:0">
        <div class="panel-hdr"><i class="fas fa-list-check" style="color:var(--accent-cyan)"></i> Day Stats</div>
        <div class="panel-body">
            <div class="stat-row">
                <span class="stat-row-lbl">Total trades</span>
                <span class="stat-row-val"><?= $total ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-row-lbl">Win rate</span>
                <span class="stat-row-val <?= $winRate >= 50 ? 'pos' : 'wrn' ?>"><?= $winRate ?>%</span>
            </div>
            <div class="stat-row">
                <span class="stat-row-lbl">SL hits</span>
                <span class="stat-row-val neg"><?= $slC ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-row-lbl">TP hits</span>
                <span class="stat-row-val pos"><?= $tpC ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-row-lbl">Manual close</span>
                <span class="stat-row-val"><?= $manC ?></span>
            </div>
            <?php if ($soC > 0): ?>
            <div class="stat-row">
                <span class="stat-row-lbl">Stop-outs</span>
                <span class="stat-row-val neg"><?= $soC ?></span>
            </div>
            <?php endif; ?>
            <?php if ($avgDurSec !== null): ?>
            <div class="stat-row">
                <span class="stat-row-lbl">Avg duration</span>
                <span class="stat-row-val wrn"><?= fmtDur((int)round($avgDurSec)) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Charge Breakdown -->
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-receipt" style="color:var(--warning)"></i> Charge Breakdown</div>
    <div class="charge-box">
        <div class="charge-item">
            <div class="charge-lbl">Gross P&amp;L</div>
            <div class="charge-val <?= $grossPL >= 0 ? 'pos' : 'neg' ?>">
                <?= ($grossPL >= 0 ? '+' : '') ?>$<?= number_format($grossPL,2) ?>
            </div>
        </div>
        <div class="charge-item">
            <div class="charge-lbl">Brokerage + Swap</div>
            <div class="charge-val neg">
                -$<?= number_format($totalBrok - $totalSwap, 2) ?>
            </div>
        </div>
        <div class="charge-item">
            <div class="charge-lbl">Net P&amp;L</div>
            <div class="charge-val <?= $netPL >= 0 ? 'pos' : 'neg' ?>" style="font-size:22px">
                <?= ($netPL >= 0 ? '+' : '') ?>$<?= number_format($netPL,2) ?>
            </div>
        </div>
    </div>
</div>

<!-- Per-Symbol Summary -->
<?php if (count($symMap) > 1): ?>
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-coins" style="color:var(--warning)"></i> Symbol Breakdown</div>
    <div class="panel-body" style="padding:0">
        <table class="tr-table">
            <thead>
                <tr>
                    <th>Symbol</th><th>Trades</th><th>Win Rate</th>
                    <th>Gross P&amp;L</th><th>Brokerage</th><th>Net P&amp;L</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($symMap as $sym => $s):
                $sNet = $s['pl'] - $s['brok'];
                $sWR  = $s['c'] > 0 ? round($s['w']/$s['c']*100,1) : 0;
            ?>
            <tr>
                <td><span class="symbol-badge"><?= htmlspecialchars($sym) ?></span></td>
                <td><?= $s['c'] ?></td>
                <td class="<?= $sWR >= 50 ? 'pos' : 'wrn' ?>"><?= $sWR ?>%</td>
                <td class="<?= $s['pl'] >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= ($s['pl']>=0?'+':'').'$'.number_format($s['pl'],2) ?></td>
                <td class="neg">-$<?= number_format($s['brok'],2) ?></td>
                <td class="<?= $sNet >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= ($sNet>=0?'+':'').'$'.number_format($sNet,2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- All Trades Table -->
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-table-list"></i> All Trades — <?= date('d M Y', $ts) ?></div>
    <div style="overflow-x:auto">
        <table class="tr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Open Time</th>
                    <th>Close Time</th>
                    <th>Duration</th>
                    <th>Symbol</th>
                    <th>Type</th>
                    <th>Lots</th>
                    <th>Entry</th>
                    <th>Exit</th>
                    <th>Close</th>
                    <th>Gross P&amp;L</th>
                    <th>Brokerage</th>
                    <th>Swap</th>
                    <th>Net P&amp;L</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($trades as $i => $t):
                $net = (float)$t['profit_loss'] - (float)$t['brokerage'] + (float)$t['swap'];
                $reason = strtolower($t['close_reason'] ?? '');
                $rColor = $reasonColors[$reason] ?? 'var(--text-muted)';
                $rLabel = $reasonLabels[$reason] ?? strtoupper($reason);

                // Duration
                $durStr = '—';
                if ($t['open_time'] && $t['trade_datetime']) {
                    $d = max(0, strtotime($t['trade_datetime']) - strtotime($t['open_time']));
                    $durStr = fmtDur($d);
                }
            ?>
            <tr>
                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                <td style="color:var(--text-muted);font-size:11px;font-family:var(--font-mono)">
                    <?= $t['open_time'] ? date('H:i:s', strtotime($t['open_time'])) : '—' ?>
                </td>
                <td style="font-size:11px;font-family:var(--font-mono)">
                    <?= date('H:i:s', strtotime($t['trade_datetime'])) ?>
                </td>
                <td style="color:var(--accent-cyan);font-size:11px"><?= $durStr ?></td>
                <td><span class="symbol-badge"><?= htmlspecialchars($t['symbol']) ?></span></td>
                <td><span class="type-<?= $t['trade_type'] ?? 'buy' ?>"><?= strtoupper($t['trade_type'] ?? 'BUY') ?></span></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= $t['quantity'] ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= number_format($t['entry_price'],4) ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= number_format($t['exit_price'],4) ?></td>
                <td>
                    <?php if ($reason): ?>
                    <span style="font-size:10px;font-weight:700;color:<?= $rColor ?>;
                                 background:<?= $rColor ?>1a;padding:2px 7px;border-radius:4px">
                        <?= $rLabel ?>
                    </span>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td class="<?= $t['profit_loss'] >= 0 ? 'pl-positive' : 'pl-negative' ?>">
                    <?= ($t['profit_loss']>=0?'+':'').'$'.number_format($t['profit_loss'],2) ?>
                </td>
                <td class="neg" style="font-size:11px">
                    <?= $t['brokerage'] > 0 ? '-$'.number_format($t['brokerage'],2) : '<span style="color:var(--text-muted)">—</span>' ?>
                </td>
                <td class="<?= $t['swap'] >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-size:11px">
                    <?= $t['swap'] != 0 ? ($t['swap']>=0?'+':'').'$'.number_format($t['swap'],2) : '<span style="color:var(--text-muted)">—</span>' ?>
                </td>
                <td class="<?= $net >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-weight:700">
                    <?= ($net>=0?'+':'').'$'.number_format($net,2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridC  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const textC  = isDark ? '#94a3b8' : '#64748b';

    // ── Line chart ────────────────────────────────────────────────────────────
    const vals   = <?= json_encode($chartVals) ?>;
    const labels = <?= json_encode($chartTimes) ?>;
    const last   = vals[vals.length-1] ?? 0;
    const color  = last >= 0 ? '#22c55e' : '#ef4444';

    new Chart(document.getElementById('eqChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data: vals,
                borderColor: color,
                backgroundColor: color + '18',
                borderWidth: 2,
                fill: true,
                pointRadius: vals.length > 30 ? 0 : 4,
                pointBackgroundColor: vals.map(v => v >= 0 ? '#22c55e' : '#ef4444'),
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' Cumulative Net: $' + ctx.parsed.y.toFixed(2) } }
            },
            scales: {
                x: { grid:{color:gridC}, ticks:{color:textC,font:{size:10},maxTicksLimit:10,maxRotation:30} },
                y: { grid:{color:gridC}, ticks:{color:textC,font:{size:10},callback:v=>'$'+v} }
            }
        }
    });

    // ── Candlestick (floating bar) data from PHP ──────────────────────────────
    const csLabels  = <?= json_encode($csLabels) ?>;
    const csBarData = <?= json_encode($csBarData) ?>;
    const csBg      = <?= json_encode($csBgColors) ?>;
    const csBorder  = <?= json_encode($csBorderColors) ?>;
    const csTips    = <?= json_encode($csTooltips) ?>;

    let csChartInst = null;

    function initCandleChart() {
        const canvas = document.getElementById('csChart');
        if (!canvas) return;
        csChartInst = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: csLabels,
                datasets: [{
                    label: 'Trade',
                    data: csBarData,           // floating bars: [equity_before, equity_after]
                    backgroundColor: csBg,
                    borderColor: csBorder,
                    borderWidth: 1.5,
                    borderRadius: 3,
                    borderSkipped: false,      // draws border on all sides (body outline)
                    barPercentage: 0.55,
                    categoryPercentage: 0.85,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: ctx => 'Trade #' + (ctx[0].dataIndex + 1) + '  ·  ' + csLabels[ctx[0].dataIndex],
                            label: ctx => {
                                const bar = csBarData[ctx.dataIndex];
                                const pl  = bar[1] - bar[0];
                                return [
                                    csTips[ctx.dataIndex],
                                    'Equity: $' + bar[0].toFixed(2) + ' → $' + bar[1].toFixed(2),
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridC },
                        ticks: { color: textC, font:{size:10}, maxRotation:45, autoSkip:true, maxTicksLimit:12 }
                    },
                    y: {
                        grid: { color: gridC },
                        ticks: { color: textC, font:{size:10}, callback: v => '$' + v }
                    }
                }
            }
        });
    }

    // ── Toggle handler ────────────────────────────────────────────────────────
    window.switchChart = function(type) {
        const lineWrap   = document.getElementById('lineWrap');
        const candleWrap = document.getElementById('candleWrap');
        const btnLine    = document.getElementById('btnLine');
        const btnCandle  = document.getElementById('btnCandle');

        if (type === 'line') {
            lineWrap.style.display   = '';
            candleWrap.style.display = 'none';
            btnLine.style.cssText   += ';background:var(--accent);color:#fff;border-color:var(--accent)';
            btnCandle.style.cssText += ';background:var(--bg-surface);color:var(--text-muted);border-color:var(--border)';
        } else {
            lineWrap.style.display   = 'none';
            candleWrap.style.display = '';
            btnCandle.style.cssText += ';background:var(--accent);color:#fff;border-color:var(--accent)';
            btnLine.style.cssText   += ';background:var(--bg-surface);color:var(--text-muted);border-color:var(--border)';
            if (!csChartInst) initCandleChart();
        }
    };
})();
</script>

<?php include '../includes/footer.php'; ?>

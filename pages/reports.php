<?php
require_once '../config/db.php';
$db     = getDB();
requireLogin();
$userId = getCurrentUserId();

$period = $_GET['period'] ?? 'monthly';

// ---- Date ranges ----
$today     = date('Y-m-d');
$thisYear  = date('Y');
$thisMonth = date('n');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));

switch ($period) {
    case 'weekly':
        $from  = $weekStart;
        $to    = $weekEnd;
        $label = 'This Week (' . date('d M', strtotime($weekStart)) . ' – ' . date('d M', strtotime($weekEnd)) . ')';
        break;
    case 'yearly':
        $from  = "$thisYear-01-01";
        $to    = "$thisYear-12-31";
        $label = "Year $thisYear";
        break;
    case 'custom':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
        $label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        break;
    default: // monthly
        $period = 'monthly';
        $from   = date('Y-m-01');
        $to     = date('Y-m-t');
        $label  = date('F Y');
}

// ---- Period stats ----
$stmt = $db->prepare("
    SELECT COUNT(*) as total,
           SUM(profit_loss) as total_pl,
           SUM(profit_loss - brokerage + swap) as net_pl,
           SUM(CASE WHEN (profit_loss - brokerage + swap) > 0 THEN 1 ELSE 0 END) as wins,
           SUM(CASE WHEN (profit_loss - brokerage + swap) < 0 THEN 1 ELSE 0 END) as losses,
           MAX(profit_loss - brokerage + swap) as best_trade,
           MIN(profit_loss - brokerage + swap) as worst_trade,
           AVG(profit_loss - brokerage + swap) as avg_pl
    FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?
");
$stmt->execute([$userId, $from, $to]);
$stats = $stmt->fetch();

$total     = (int)$stats['total'];
$totalPL   = floatval($stats['total_pl']);
$netPL     = floatval($stats['net_pl']);
$brokerage = getTotalBrokerage($userId, $from, $to);
$wins      = (int)$stats['wins'];
$losses    = (int)$stats['losses'];
$winRate   = $total > 0 ? round($wins / $total * 100, 1) : 0;

// Drawdown: peak balance - minimum running balance in period
$balance = getCurrentBalance($userId);

// Chart: daily net P/L grouped
$chartStmt = $db->prepare("
    SELECT DATE(trade_datetime) as day, SUM(profit_loss - brokerage + swap) as pl
    FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?
    GROUP BY DATE(trade_datetime) ORDER BY day
");
$chartStmt->execute([$userId, $from, $to]);
$chartRows   = $chartStmt->fetchAll();
$chartLabels = array_map(fn($r) => date('d M', strtotime($r['day'])), $chartRows);
$chartPLs    = array_map(fn($r) => round($r['pl'], 2), $chartRows);

// Cumulative equity curve
$running = 0;
$equityData = [];
foreach ($chartPLs as $v) {
    $running += $v;
    $equityData[] = round($running, 2);
}
$chartDates    = array_map(fn($r) => $r['day'], $chartRows); // ISO YYYY-MM-DD for LW Charts
$startingEquity = round($balance - $netPL, 2);               // account balance at period start

$pageTitle = 'Reports';
$rootPath  = '../';
include '../includes/header.php';
?>

<!-- Period Tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <?php foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'custom' => 'Custom Range'] as $key => $lbl): ?>
    <a href="?period=<?= $key ?>" class="report-tab <?= $period === $key ? 'active' : '' ?>">
        <?= $lbl ?>
    </a>
    <?php endforeach; ?>
    <div style="margin-left:auto;font-size:13px;color:var(--text-muted);align-self:center">
        <i class="far fa-calendar"></i> <?= $label ?>
    </div>
</div>

<?php if ($period === 'custom'): ?>
<form method="get" class="d-flex gap-2 align-items-center mb-4 flex-wrap">
    <input type="hidden" name="period" value="custom">
    <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($from) ?>">
    <span style="color:var(--text-muted);font-size:13px">to</span>
    <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($to) ?>">
    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 12px">Apply</button>
    <a href="?period=monthly" style="font-size:12px;color:var(--text-muted)">✕ Clear</a>
</form>
<?php else: ?>
<div class="mb-1"></div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $totalPL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $totalPL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value <?= $totalPL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($totalPL) ?></div>
            <div class="stat-label">Total P&amp;L</div>
            <div class="stat-sub">Gross before charges</div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card loss">
            <div class="stat-icon loss"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="stat-value negative">-<?= formatUSD($brokerage) ?></div>
            <div class="stat-label">Brokerage</div>
            <div class="stat-sub">Charges &amp; fees</div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $netPL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $netPL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-sack-dollar"></i></div>
            <div class="stat-value <?= $netPL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($netPL) ?></div>
            <div class="stat-label">Net P&amp;L</div>
            <div class="stat-sub">After all charges</div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-bullseye"></i></div>
            <div class="stat-value"><?= $winRate ?>%</div>
            <div class="stat-label">Win Rate</div>
            <div class="stat-sub"><?= $wins ?>W / <?= $losses ?>L</div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= floatval($stats['best_trade']) >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= floatval($stats['best_trade']) >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-trophy"></i></div>
            <div class="stat-value <?= floatval($stats['best_trade']) >= 0 ? 'positive' : 'negative' ?>"><?= $total > 0 ? formatPL(floatval($stats['best_trade'])) : formatUSD(0) ?></div>
            <div class="stat-label">Best Trade</div>
            <div class="stat-sub"><?= $total ?> trade<?= $total !== 1 ? 's' : '' ?> total</div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= floatval($stats['worst_trade']) >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= floatval($stats['worst_trade']) >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-arrow-trend-down"></i></div>
            <div class="stat-value <?= floatval($stats['worst_trade']) >= 0 ? 'positive' : 'negative' ?>"><?= $total > 0 ? formatPL(floatval($stats['worst_trade'])) : formatUSD(0) ?></div>
            <div class="stat-label">Worst Trade</div>
            <div class="stat-sub">Avg: <?= $total > 0 ? formatPL(floatval($stats['avg_pl'])) : formatUSD(0) ?></div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-bar"></i> Daily P&amp;L</div>
                <a href="#" class="panel-link" id="expandDaily" title="Full view"><i class="fas fa-expand-alt"></i></a>
            </div>
            <div class="panel-body">
                <div class="chart-container" style="height:320px">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-line"></i> Cum. P&amp;L</div>
                <a href="#" class="panel-link" id="expandEquity" title="Full view"><i class="fas fa-expand-alt"></i></a>
            </div>
            <div class="panel-body">
                <div class="chart-container" style="height:320px">
                    <canvas id="equityChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>


<style>
/* ---- P&L scrollbar ---- */
#modalScrollWrapper::-webkit-scrollbar { height: 6px; }
#modalScrollWrapper::-webkit-scrollbar-track { background: transparent; }
#modalScrollWrapper::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
#modalScrollWrapper::-webkit-scrollbar-thumb:hover { background: var(--accent); }

/* ---- Period pills (Daily P&L panel) ---- */
.period-btn {
    padding: 5px 13px; font-size: 12px; font-weight: 600;
    border: 1px solid var(--border); border-radius: 20px;
    background: transparent; color: var(--text-muted);
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.period-btn:hover { background: var(--bg-elevated); color: var(--text-primary); }
.period-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ---- Timeframe pills (LW panel) ---- */
.tf-btn {
    padding: 5px 12px; font-size: 11px; font-weight: 700;
    border: 1px solid var(--border); border-radius: 5px;
    background: transparent; color: var(--text-muted);
    cursor: pointer; transition: all .15s; letter-spacing: .3px;
}
.tf-btn:hover { background: var(--bg-card); color: var(--text-primary); }
.tf-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ---- Drawing toolbar ---- */
#lwToolbar {
    width: 44px; display: flex; flex-direction: column;
    align-items: center; gap: 2px; padding: 12px 6px;
    background: var(--bg-card); border-right: 1px solid var(--border);
    flex-shrink: 0; overflow-y: auto;
}
.dt-btn {
    width: 32px; height: 32px; border-radius: 6px;
    border: 1px solid transparent; background: transparent;
    color: var(--text-muted); cursor: pointer; font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.dt-btn:hover { background: var(--bg-elevated); color: var(--text-primary); }
.dt-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.dt-sep { width: 26px; height: 1px; background: var(--border); margin: 4px 0; flex-shrink: 0; }

/* ---- Toggle buttons ---- */
.lw-toggle {
    display: flex; align-items: center; gap: 5px;
    padding: 4px 10px; font-size: 11px; font-weight: 600;
    border: 1px solid var(--border); border-radius: 5px;
    background: transparent; color: var(--text-muted);
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.lw-toggle:hover { color: var(--text-primary); }
.lw-toggle.on { border-color: currentColor; }
.lw-toggle .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

/* ---- Floating tooltip ---- */
#lwTooltip {
    position: absolute; display: none; z-index: 20;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 10px; padding: 11px 15px; min-width: 185px;
    box-shadow: 0 8px 32px rgba(0,0,0,.22); pointer-events: none;
}
.tt-date { font-size: 10px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .6px; margin-bottom: 9px; }
.tt-row { display: flex; justify-content: space-between; align-items: center; padding: 2px 0; }
.tt-lbl { font-size: 11px; color: var(--text-muted); }
.tt-val { font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }

/* ---- Stats bar ---- */
.lw-stat { display: flex; flex-direction: column; gap: 1px; }
.lw-stat-lbl { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; }
.lw-stat-val { font-size: 13px; font-weight: 700; }
</style>

<!-- Chart Full Screen Modal -->
<div class="modal fade" id="chartFullModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content" style="border-radius:0;border:none">

            <!-- SHARED HEADER -->
            <div class="modal-header" style="padding:10px 18px;gap:12px;min-height:0">
                <div class="modal-title" style="display:flex;align-items:center;gap:9px;flex:1;min-width:0">
                    <i id="chartFullModalIcon" class="fas fa-chart-bar" style="color:var(--accent);font-size:14px;flex-shrink:0"></i>
                    <span id="chartFullModalLabel" style="font-size:14px;font-weight:700"></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                    <button class="btn-icon" id="btnExportPNG" title="Export PNG" style="width:30px;height:30px;border-radius:var(--radius-sm);font-size:12px;display:none">
                        <i class="fas fa-download"></i>
                    </button>
                    <button type="button" class="btn-icon" data-bs-dismiss="modal" title="Exit fullscreen" style="width:30px;height:30px;border-radius:var(--radius-sm);font-size:13px">
                        <i class="fas fa-compress-alt"></i>
                    </button>
                </div>
            </div>

            <!-- BODY -->
            <div class="modal-body" style="padding:0;display:flex;flex-direction:column;overflow:hidden">

                <!-- ═══ PANEL A: Daily P&L (Chart.js) ═══ -->
                <div id="panelPL" style="display:none;flex:1;flex-direction:column;overflow:hidden;padding:16px 22px 22px">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-shrink:0;flex-wrap:wrap">
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="period-btn active" data-days="0">All</button>
                            <button class="period-btn" data-days="365">1Y</button>
                            <button class="period-btn" data-days="180">6M</button>
                            <button class="period-btn" data-days="90">3M</button>
                            <button class="period-btn" data-days="30">1M</button>
                            <button class="period-btn" data-days="7">1W</button>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span id="modalRangeLabel" style="font-size:12px;color:var(--text-muted)"></span>
                            <button class="btn-icon" id="modalScrollLeft" style="width:28px;height:28px;border-radius:var(--radius-sm);font-size:12px"><i class="fas fa-chevron-left"></i></button>
                            <button class="btn-icon" id="modalScrollRight" style="width:28px;height:28px;border-radius:var(--radius-sm);font-size:12px"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div style="flex:1;display:flex;flex-direction:column;min-height:0;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px 8px">
                        <div id="modalScrollWrapper" style="flex:1;overflow-x:auto;overflow-y:hidden;min-height:0;scroll-behavior:smooth;padding-bottom:8px">
                            <div id="modalChartInner" style="min-width:100%;height:100%">
                                <canvas id="modalChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ PANEL B: Equity Curve (LW Charts) ═══ -->
                <div id="panelLW" style="display:none;flex:1;flex-direction:column;overflow:hidden">

                    <!-- Stats bar -->
                    <div id="lwStatsBar" style="display:flex;gap:28px;padding:10px 22px;border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;align-items:center"></div>

                    <!-- Main row: drawing toolbar + chart area -->
                    <div style="flex:1;display:flex;min-height:0">

                        <!-- Drawing tools sidebar -->
                        <div id="lwToolbar">
                            <button class="dt-btn active" data-tool="cursor" title="Select (Esc)"><i class="fas fa-mouse-pointer"></i></button>
                            <div class="dt-sep"></div>
                            <button class="dt-btn" data-tool="hline" title="Horizontal Line (H)"><i class="fas fa-grip-lines"></i></button>
                            <button class="dt-btn" data-tool="trendline" title="Trend Line (T)"><i class="fas fa-arrow-trend-up"></i></button>
                            <button class="dt-btn" data-tool="text" title="Text Note (N)"><i class="fas fa-font"></i></button>
                            <div class="dt-sep"></div>
                            <button class="dt-btn" data-tool="eraser" title="Eraser (Del)"><i class="fas fa-eraser"></i></button>
                            <div class="dt-sep" style="margin-top:auto"></div>
                            <button class="dt-btn" id="btnClearAll" title="Clear all drawings"><i class="fas fa-trash-can" style="font-size:11px"></i></button>
                        </div>

                        <!-- Chart column -->
                        <div style="flex:1;display:flex;flex-direction:column;min-width:0;padding:14px 20px 18px">

                            <!-- Timeframe + overlays row -->
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-shrink:0;flex-wrap:wrap">
                                <div style="display:flex;gap:3px">
                                    <button class="tf-btn active" data-tf="0">ALL</button>
                                    <button class="tf-btn" data-tf="365">1Y</button>
                                    <button class="tf-btn" data-tf="180">6M</button>
                                    <button class="tf-btn" data-tf="90">3M</button>
                                    <button class="tf-btn" data-tf="30">1M</button>
                                    <button class="tf-btn" data-tf="7">1W</button>
                                </div>
                                <div style="width:1px;height:18px;background:var(--border);margin:0 4px"></div>
                                <button class="lw-toggle on" id="btnMA" style="color:#3b82f6">
                                    <span class="dot" style="background:#3b82f6"></span>MA(20)
                                </button>
                                <button class="lw-toggle on" id="btnNewHighs" style="color:#f59e0b">
                                    <span class="dot" style="background:#f59e0b"></span>New Highs
                                </button>
                                <button class="lw-toggle on" id="btnDrawdown" style="color:#f87171">
                                    <span class="dot" style="background:#f87171"></span>Drawdown
                                </button>
                            </div>

                            <!-- Chart container -->
                            <div style="flex:1;position:relative;min-height:0;border-radius:var(--radius);overflow:hidden">
                                <div id="lwChartContainer" style="position:absolute;inset:0"></div>
                                <canvas id="drawOverlay" style="position:absolute;inset:0;pointer-events:none;z-index:5"></canvas>
                                <!-- Floating tooltip -->
                                <div id="lwTooltip">
                                    <div class="tt-date" id="ttDate"></div>
                                    <div class="tt-row"><span class="tt-lbl">Equity</span><span class="tt-val" id="ttEquity"></span></div>
                                    <div class="tt-row"><span class="tt-lbl">Cum. P&L</span><span class="tt-val" id="ttCumPL"></span></div>
                                    <div class="tt-row"><span class="tt-lbl">Daily P&L</span><span class="tt-val" id="ttDailyPL"></span></div>
                                    <div class="tt-row"><span class="tt-lbl">Drawdown</span><span class="tt-val" id="ttDrawdown" style="color:#f87171"></span></div>
                                    <div class="tt-row"><span class="tt-lbl">Total Gain</span><span class="tt-val" id="ttGain"></span></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->
        </div>
    </div>
</div>

<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
var CS = <?= json_encode(getActiveCurrency()['symbol']) ?>;
document.addEventListener('DOMContentLoaded', function() {

    // ── Inline panel charts ──────────────────────────────────────────────────
    createLineChart('dailyChart', <?= json_encode($chartLabels) ?>, <?= json_encode($chartPLs) ?>, 'Daily P&L');
    createAreaChart('equityChart', <?= json_encode($chartLabels) ?>, <?= json_encode($equityData) ?>, 'Cumulative P&L');

    // ── Data ────────────────────────────────────────────────────────────────
    var _dates   = <?= json_encode($chartDates) ?>;    // YYYY-MM-DD
    var _labels  = <?= json_encode($chartLabels) ?>;   // "dd MMM"
    var _pls     = <?= json_encode($chartPLs) ?>;
    var _equity  = <?= json_encode($equityData) ?>;    // cumulative P&L from period start
    var _startEq = <?= json_encode($startingEquity) ?>; // account balance at period start

    var modalEl  = document.getElementById('chartFullModal');
    var bsModal  = new bootstrap.Modal(modalEl);
    var currentType = 'bar';

    // ════════════════════════════════════════════════════════════════════════
    // PANEL A — Daily P&L  (Chart.js + scroll)
    // ════════════════════════════════════════════════════════════════════════
    var plChart = null;
    var PX_PER_PT = 56;

    var xhPlugin = {
        id: 'xh',
        afterDraw: function(c) {
            var a = c.tooltip._active;
            if (!a || !a.length) return;
            var ctx = c.ctx, x = a[0].element.x;
            ctx.save();
            ctx.beginPath(); ctx.moveTo(x, c.scales.y.top); ctx.lineTo(x, c.scales.y.bottom);
            ctx.lineWidth = 1; ctx.strokeStyle = getChartThemeColors().grid;
            ctx.setLineDash([4,3]); ctx.stroke(); ctx.restore();
        }
    };

    function destroyPLChart() { if (plChart) { plChart.destroy(); plChart = null; } }

    function renderPLChart(days) {
        document.querySelectorAll('.period-btn').forEach(function(b) {
            b.classList.toggle('active', parseInt(b.dataset.days) === days);
        });
        var n = (!days || _labels.length <= days) ? _labels.length : days;
        var names = {7:'Last 7 days',30:'Last month',90:'Last 3 months',180:'Last 6 months',365:'Last year'};
        document.getElementById('modalRangeLabel').textContent = (names[days] || 'All time') + ' · ' + n + ' pts';

        var lbl = _labels.slice(-n), pls = _pls.slice(-n);
        var wrapper = document.getElementById('modalScrollWrapper');
        var inner   = document.getElementById('modalChartInner');
        var wW = wrapper.clientWidth, wH = wrapper.clientHeight - 10;
        var totalW = Math.max(wW, n * PX_PER_PT);
        inner.style.width = totalW + 'px';
        inner.style.height = wH + 'px';

        destroyPLChart();
        var cl = getChartThemeColors();
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        plChart = new Chart(document.getElementById('modalChart'), {
            plugins: [xhPlugin],
            type: 'bar',
            data: {
                labels: lbl,
                datasets: [{ label:'Daily P&L', data: pls,
                    backgroundColor: pls.map(function(v){return v>=0?cl.profit+'aa':cl.loss+'aa';}),
                    borderColor:     pls.map(function(v){return v>=0?cl.profit:cl.loss;}),
                    borderWidth: 2, borderRadius: 4, borderSkipped: false }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode:'index', intersect:false },
                plugins: {
                    legend: { display:false },
                    tooltip: {
                        backgroundColor: dark?'#1e2a40':'#fff',
                        borderColor: cl.grid, borderWidth:1,
                        titleColor: cl.text, bodyColor: cl.text,
                        padding:10, displayColors:false,
                        callbacks: { label: function(c){ var v=c.raw; return (v>=0?'+':'-')+CS+Math.abs(v).toFixed(2); } }
                    }
                },
                scales: {
                    x: { grid:{color:cl.grid}, ticks:{color:cl.text,font:{size:11},maxRotation:45} },
                    y: { grid:{color:cl.grid}, ticks:{color:cl.text,font:{size:11},
                         callback:function(v){return (v>=0?'+':'-')+CS+Math.abs(v);}} }
                }
            }
        });
        if (totalW > wW) requestAnimationFrame(function(){ wrapper.scrollLeft = wrapper.scrollWidth; });
    }

    document.querySelectorAll('.period-btn').forEach(function(b) {
        b.addEventListener('click', function() { if (currentType==='bar') renderPLChart(parseInt(this.dataset.days)); });
    });
    document.getElementById('modalScrollLeft').addEventListener('click', function() {
        var w=document.getElementById('modalScrollWrapper'); w.scrollBy({left:-Math.round(w.clientWidth*.4),behavior:'smooth'});
    });
    document.getElementById('modalScrollRight').addEventListener('click', function() {
        var w=document.getElementById('modalScrollWrapper'); w.scrollBy({left:Math.round(w.clientWidth*.4),behavior:'smooth'});
    });

    // ════════════════════════════════════════════════════════════════════════
    // PANEL B — Equity Curve  (TradingView Lightweight Charts)
    // ════════════════════════════════════════════════════════════════════════
    var lwChart = null, lwSeries = null, lwMASeries = null, lwDDSeries = null;
    var lwRO = null;          // ResizeObserver
    var lwTF = 0;             // current timeframe (days)
    var showMA = true, showHighs = true, showDD = true;

    function lwColors() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            grid:      dark ? '#1e2d4a' : '#eaf0f6',
            text:      dark ? '#8fa3bc' : '#64748b',
            eq:        dark ? '#22c55e' : '#16a34a',
            eqFill1:   dark ? 'rgba(34,197,94,.18)' : 'rgba(22,163,74,.13)',
            eqFill2:   dark ? 'rgba(34,197,94,0)'   : 'rgba(22,163,74,0)',
            ma:        '#3b82f6',
            dd:        dark ? 'rgba(248,113,113,.22)' : 'rgba(220,38,38,.12)',
            ddLine:    dark ? 'rgba(248,113,113,.5)'  : 'rgba(220,38,38,.4)',
            xhair:     dark ? '#4b5563' : '#9ca3af',
            xLabel:    dark ? '#2d3748' : '#f8fafc'
        };
    }

    function calcMA(arr, n) {
        return arr.map(function(_, i) {
            if (i < n-1) return null;
            var s=0; for(var j=i-n+1;j<=i;j++) s+=arr[j];
            return +(s/n).toFixed(2);
        });
    }

    function calcDD(arr) {
        var peak = arr[0] || 0;
        return arr.map(function(v) {
            if (v > peak) peak = v;
            if (peak <= 0) return 0;
            return +((v - peak) / Math.abs(peak) * 100).toFixed(3);
        });
    }

    function sliceLW(days) {
        var n = (!days || _dates.length <= days) ? _dates.length : days;
        return { n: n, dates: _dates.slice(-n), eq: _equity.slice(-n), pls: _pls.slice(-n) };
    }

    function fmtMoney(v) { return CS+Math.abs(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function fmtDate(s) {
        var p=s.split('-'), m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return m[+p[1]-1]+' '+parseInt(p[2])+', '+p[0];
    }

    function buildStatsBar(d) {
        var abs = d.eq.map(function(v){ return _startEq+v; });
        var cur = abs[abs.length-1] || _startEq;
        var gain = _startEq ? (cur-_startEq)/Math.abs(_startEq)*100 : 0;
        var dd = calcDD(abs); var maxDD = Math.min.apply(null, dd.length?dd:[0]);
        var items = [
            { l:'Start Equity', v: fmtMoney(_startEq), c:'' },
            { l:'Current',  v: fmtMoney(cur),  c: cur>=_startEq?'#22c55e':'#f87171' },
            { l:'Total P&L', v: (d.eq[d.eq.length-1]>=0?'+':'')+fmtMoney(d.eq[d.eq.length-1]),
              c: d.eq[d.eq.length-1]>=0?'#22c55e':'#f87171' },
            { l:'Gain',  v: (gain>=0?'+':'')+gain.toFixed(2)+'%', c: gain>=0?'#22c55e':'#f87171' },
            { l:'Max DD', v: maxDD.toFixed(2)+'%', c: '#f87171' },
            { l:'Pts', v: d.n, c:'' }
        ];
        document.getElementById('lwStatsBar').innerHTML = items.map(function(it){
            return '<div class="lw-stat"><span class="lw-stat-lbl">'+it.l+'</span>'+
                   '<span class="lw-stat-val"'+(it.c?' style="color:'+it.c+'"':'')+'>'+ it.v +'</span></div>';
        }).join('');
    }

    function destroyLW() {
        if (lwRO) { lwRO.disconnect(); lwRO=null; }
        if (lwChart) { lwChart.remove(); lwChart=null; }
        lwSeries=lwMASeries=lwDDSeries=null;
    }

    function initLW(days) {
        lwTF = days;
        document.querySelectorAll('.tf-btn').forEach(function(b){
            b.classList.toggle('active', parseInt(b.dataset.tf)===days);
        });

        var d = sliceLW(days);
        buildStatsBar(d);
        var absEq = d.eq.map(function(v){ return _startEq+v; });
        var ma20  = calcMA(absEq, 20);
        var ddArr = calcDD(absEq);

        // New equity high markers
        var markers = [], peak = -Infinity;
        absEq.forEach(function(v,i) {
            if (v>peak) { peak=v; if(i>0) markers.push({time:d.dates[i],position:'aboveBar',color:'#f59e0b',shape:'arrowDown',size:1}); }
        });

        var cont = document.getElementById('lwChartContainer');
        var cl   = lwColors();
        destroyLW();

        lwChart = LightweightCharts.createChart(cont, {
            width: cont.clientWidth, height: cont.clientHeight,
            layout: { background:{type:'solid',color:'transparent'}, textColor:cl.text,
                      fontFamily:"'Inter','Segoe UI',system-ui,sans-serif", fontSize:11 },
            grid: { vertLines:{color:cl.grid}, horzLines:{color:cl.grid} },
            crosshair: {
                mode: 1, // Magnet
                vertLine: { width:1, color:cl.xhair, style:2, labelBackgroundColor:cl.xLabel },
                horzLine: { width:1, color:cl.xhair, style:2, labelBackgroundColor:cl.xLabel }
            },
            rightPriceScale: { borderColor:cl.grid },
            timeScale: { borderColor:cl.grid, timeVisible:false, fixRightEdge:true, fixLeftEdge:false },
            handleScale: { mouseWheel:true, pinch:true },
            handleScroll: { mouseWheel:true, pressedMouseMove:true, horzTouchDrag:true, vertTouchDrag:false }
        });

        // Drawdown area series (price scale hidden, right axis)
        lwDDSeries = lwChart.addAreaSeries({
            priceScaleId: 'dd',
            lineColor: cl.ddLine, topColor: cl.dd, bottomColor: 'rgba(0,0,0,0)',
            lineWidth: 1, visible: showDD, priceLineVisible:false, lastValueVisible:false
        });
        lwChart.priceScale('dd').applyOptions({ visible:false, scaleMargins:{top:0.8,bottom:0} });
        lwDDSeries.setData(d.dates.map(function(dt,i){ return {time:dt,value:ddArr[i]}; }));

        // Main equity area
        lwSeries = lwChart.addAreaSeries({
            lineColor: cl.eq, topColor: cl.eqFill1, bottomColor: cl.eqFill2,
            lineWidth: 2.5, priceLineVisible:true, lastValueVisible:true,
            crosshairMarkerVisible:true, crosshairMarkerRadius:5,
            crosshairMarkerBorderColor:'#fff', crosshairMarkerBackgroundColor:cl.eq
        });
        lwSeries.setData(d.dates.map(function(dt,i){ return {time:dt,value:absEq[i]}; }));
        if (showHighs) lwSeries.setMarkers(markers);

        // MA(20) line
        lwMASeries = lwChart.addLineSeries({
            color:cl.ma, lineWidth:1, lineStyle:2,
            priceLineVisible:false, lastValueVisible:false, visible:showMA
        });
        lwMASeries.setData(d.dates.map(function(dt,i){
            return ma20[i]!==null ? {time:dt,value:ma20[i]} : null;
        }).filter(Boolean));

        lwChart.timeScale().fitContent();

        // Double-click → reset zoom
        cont.addEventListener('dblclick', function(){ if(lwChart) lwChart.timeScale().fitContent(); });

        // Responsive resize
        lwRO = new ResizeObserver(function(){
            if (!lwChart) return;
            var c=document.getElementById('lwChartContainer');
            lwChart.resize(c.clientWidth, c.clientHeight);
            syncOverlaySize();
            renderDrawings();
        });
        lwRO.observe(cont);

        // ── Floating tooltip ──────────────────────────────────────────────
        var tt = document.getElementById('lwTooltip');
        lwChart.subscribeCrosshairMove(function(param) {
            if (!param.point || !param.time) { tt.style.display='none'; return; }
            var idx = d.dates.indexOf(param.time);
            if (idx<0) { tt.style.display='none'; return; }

            var eq=absEq[idx], cp=d.eq[idx], dp=d.pls[idx], dd=ddArr[idx];
            var g = _startEq ? (eq-_startEq)/Math.abs(_startEq)*100 : 0;

            document.getElementById('ttDate').textContent    = fmtDate(param.time);
            var eqEl=document.getElementById('ttEquity');
            eqEl.textContent=fmtMoney(eq); eqEl.style.color=eq>=_startEq?'#22c55e':'#f87171';
            var cpEl=document.getElementById('ttCumPL');
            cpEl.textContent=(cp>=0?'+':'')+fmtMoney(cp)*(cp<0?-1:1);
            cpEl.textContent=(cp>=0?'+':'-')+CS+Math.abs(cp).toFixed(2);
            cpEl.style.color=cp>=0?'#22c55e':'#f87171';
            var dpEl=document.getElementById('ttDailyPL');
            dpEl.textContent=(dp>=0?'+':'-')+CS+Math.abs(dp).toFixed(2);
            dpEl.style.color=dp>=0?'#22c55e':'#f87171';
            document.getElementById('ttDrawdown').textContent=dd.toFixed(2)+'%';
            var gEl=document.getElementById('ttGain');
            gEl.textContent=(g>=0?'+':'')+g.toFixed(2)+'%'; gEl.style.color=g>=0?'#22c55e':'#f87171';

            // Position tooltip
            var x=param.point.x, y=param.point.y;
            var tw=tt.offsetWidth||200, th=tt.offsetHeight||145;
            var left=x+18, top=y-th/2;
            if (left+tw > cont.clientWidth-8) left=x-tw-18;
            if (top<8) top=8;
            if (top+th > cont.clientHeight-8) top=cont.clientHeight-th-8;
            tt.style.left=left+'px'; tt.style.top=top+'px'; tt.style.display='block';

            renderDrawings(); // keep overlay in sync during crosshair move
        });

        // ── Drawing overlay (re-render on pan/zoom) ───────────────────────
        lwChart.timeScale().subscribeVisibleTimeRangeChange(function(){ renderDrawings(); });
        setupDrawOverlay();
    }

    // ── Timeframe / overlay toggles ──────────────────────────────────────
    document.querySelectorAll('.tf-btn').forEach(function(b){
        b.addEventListener('click', function(){ if(lwChart) initLW(parseInt(this.dataset.tf)); });
    });
    document.getElementById('btnMA').addEventListener('click', function(){
        showMA=!showMA; this.classList.toggle('on',showMA);
        if(lwMASeries) lwMASeries.applyOptions({visible:showMA});
    });
    document.getElementById('btnNewHighs').addEventListener('click', function(){
        showHighs=!showHighs; this.classList.toggle('on',showHighs);
        if(lwSeries) { if(showHighs) initLW(lwTF); else lwSeries.setMarkers([]); }
    });
    document.getElementById('btnDrawdown').addEventListener('click', function(){
        showDD=!showDD; this.classList.toggle('on',showDD);
        if(lwDDSeries) lwDDSeries.applyOptions({visible:showDD});
    });

    // ── Export PNG ────────────────────────────────────────────────────────
    document.getElementById('btnExportPNG').addEventListener('click', function(){
        var canvas = document.querySelector('#lwChartContainer canvas');
        if (!canvas) return;
        var a=document.createElement('a'); a.download='equity-curve.png';
        a.href=canvas.toDataURL('image/png'); a.click();
    });

    // ════════════════════════════════════════════════════════════════════════
    // DRAWING TOOLS
    // ════════════════════════════════════════════════════════════════════════
    var activeTool = 'cursor';
    var drawings   = [];       // persisted drawing objects
    var drawInProg = false;    // trendline in progress
    var drawStart  = null;     // { x, y, time, price }
    var oCanvas = null, oCtx = null;

    function setTool(tool) {
        activeTool = tool;
        document.querySelectorAll('.dt-btn').forEach(function(b){ b.classList.toggle('active',b.dataset.tool===tool); });
        var ov = document.getElementById('drawOverlay');
        var needsEvents = (tool==='hline'||tool==='trendline'||tool==='text'||tool==='eraser');
        ov.style.pointerEvents = needsEvents ? 'all' : 'none';
        ov.style.cursor = (tool==='eraser') ? 'crosshair' : (needsEvents ? 'crosshair' : 'default');
    }
    document.querySelectorAll('.dt-btn[data-tool]').forEach(function(b){
        b.addEventListener('click', function(){ setTool(this.dataset.tool); });
    });
    document.getElementById('btnClearAll').addEventListener('click', function(){
        drawings=[]; saveDrawings(); renderDrawings();
        // remove any LW price lines
        if (lwSeries) { try{ lwSeries.setMarkers(showHighs?[]:[]); }catch(e){} }
        initLW(lwTF); // reinit to clear price lines
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e){
        if (!document.getElementById('panelLW').style.display||document.getElementById('panelLW').style.display==='none') return;
        if (e.key==='Escape') setTool('cursor');
        if (e.key==='h'||e.key==='H') setTool('hline');
        if (e.key==='t'||e.key==='T') setTool('trendline');
        if (e.key==='n'||e.key==='N') setTool('text');
        if (e.key==='Delete'||e.key==='Backspace') setTool('eraser');
    });

    function syncOverlaySize() {
        var cont=document.getElementById('lwChartContainer');
        var ov=document.getElementById('drawOverlay');
        if(!cont||!ov) return;
        ov.width=cont.clientWidth; ov.height=cont.clientHeight;
        oCanvas=ov; oCtx=ov.getContext('2d');
    }

    function toCanvasCoords(time, price) {
        if (!lwChart||!lwSeries) return null;
        var x=lwChart.timeScale().timeToCoordinate(time);
        var y=lwSeries.priceToCoordinate(price);
        if (x===null||y===null) return null;
        return {x:x,y:y};
    }

    function renderDrawings() {
        if (!oCtx||!oCanvas) return;
        oCtx.clearRect(0,0,oCanvas.width,oCanvas.height);
        var cl=lwColors();
        drawings.forEach(function(d){
            if (d.type==='trendline') {
                var a=toCanvasCoords(d.t1,d.p1), b=toCanvasCoords(d.t2,d.p2);
                if (!a||!b) return;
                oCtx.save();
                oCtx.beginPath(); oCtx.moveTo(a.x,a.y); oCtx.lineTo(b.x,b.y);
                oCtx.strokeStyle=d.color||cl.ma; oCtx.lineWidth=1.5;
                oCtx.setLineDash([]); oCtx.stroke();
                [a,b].forEach(function(pt){
                    oCtx.beginPath(); oCtx.arc(pt.x,pt.y,4,0,Math.PI*2);
                    oCtx.fillStyle=d.color||cl.ma; oCtx.fill();
                });
                oCtx.restore();
            } else if (d.type==='text') {
                var pt=toCanvasCoords(d.t,d.p);
                if (!pt) return;
                oCtx.save();
                oCtx.font='600 12px Inter,system-ui,sans-serif';
                oCtx.fillStyle=d.color||'#f59e0b';
                var m=oCtx.measureText(d.text);
                oCtx.fillStyle='rgba(0,0,0,.45)';
                oCtx.fillRect(pt.x,pt.y-16,m.width+10,18);
                oCtx.fillStyle=d.color||'#f59e0b';
                oCtx.fillText(d.text,pt.x+5,pt.y-3);
                oCtx.restore();
            }
        });
    }

    function setupDrawOverlay() {
        syncOverlaySize();
        renderDrawings();
        var ov=document.getElementById('drawOverlay');

        ov.addEventListener('mousedown', function(e){
            if (!lwChart||!lwSeries) return;
            var r=ov.getBoundingClientRect();
            var mx=e.clientX-r.left, my=e.clientY-r.top;

            if (activeTool==='hline') {
                var price=lwSeries.coordinateToPrice(my);
                if (price===null) return;
                var cl=lwColors();
                lwSeries.createPriceLine({price:price,color:cl.ma,lineWidth:1,lineStyle:2,axisLabelVisible:true,title:''});
                drawings.push({type:'hline',price:price});
                saveDrawings(); setTool('cursor'); return;
            }
            if (activeTool==='trendline') {
                var t=lwChart.timeScale().coordinateToTime(mx);
                var p=lwSeries.coordinateToPrice(my);
                if (!t||p===null) return;
                drawInProg=true; drawStart={x:mx,y:my,t:t,p:p}; return;
            }
            if (activeTool==='text') {
                var label=prompt('Annotation text:');
                if (!label) return;
                var tt=lwChart.timeScale().coordinateToTime(mx);
                var tp=lwSeries.coordinateToPrice(my);
                if (!tt||tp===null) return;
                drawings.push({type:'text',t:tt,p:tp,text:label});
                renderDrawings(); saveDrawings(); setTool('cursor'); return;
            }
            if (activeTool==='eraser') {
                // Remove nearest drawing
                var best=-1, bestD=12;
                drawings.forEach(function(dr,i){
                    var dist=Infinity;
                    if (dr.type==='trendline') {
                        var a=toCanvasCoords(dr.t1,dr.p1),b=toCanvasCoords(dr.t2,dr.p2);
                        if(a&&b) dist=ptSegDist(mx,my,a.x,a.y,b.x,b.y);
                    } else if (dr.type==='text') {
                        var pt=toCanvasCoords(dr.t,dr.p);
                        if(pt) dist=Math.hypot(mx-pt.x,my-pt.y);
                    } else if (dr.type==='hline') {
                        var py=lwSeries.priceToCoordinate(dr.price);
                        if(py!==null) dist=Math.abs(my-py);
                    }
                    if(dist<bestD){bestD=dist;best=i;}
                });
                if (best>=0) {
                    // If hline, try to remove the price line from series (reinit chart)
                    if (drawings[best].type==='hline') { drawings.splice(best,1); saveDrawings(); initLW(lwTF); return; }
                    drawings.splice(best,1); renderDrawings(); saveDrawings();
                }
            }
        });

        ov.addEventListener('mousemove', function(e){
            if (!drawInProg||activeTool!=='trendline'||!drawStart) return;
            var r=ov.getBoundingClientRect();
            var mx=e.clientX-r.left,my=e.clientY-r.top;
            renderDrawings();
            oCtx.save();
            oCtx.beginPath(); oCtx.moveTo(drawStart.x,drawStart.y); oCtx.lineTo(mx,my);
            oCtx.strokeStyle=lwColors().ma; oCtx.lineWidth=1.5;
            oCtx.setLineDash([5,3]); oCtx.stroke();
            oCtx.restore();
        });

        ov.addEventListener('mouseup', function(e){
            if (!drawInProg||activeTool!=='trendline') return;
            drawInProg=false;
            var r=ov.getBoundingClientRect();
            var mx=e.clientX-r.left,my=e.clientY-r.top;
            var et=lwChart.timeScale().coordinateToTime(mx);
            var ep=lwSeries.coordinateToPrice(my);
            if (drawStart&&et&&ep!==null) {
                drawings.push({type:'trendline',t1:drawStart.t,p1:drawStart.p,t2:et,p2:ep});
                renderDrawings(); saveDrawings();
            }
            drawStart=null; setTool('cursor');
        });
    }

    function ptSegDist(px,py,x1,y1,x2,y2) {
        var dx=x2-x1,dy=y2-y1,l2=dx*dx+dy*dy;
        if(l2===0) return Math.hypot(px-x1,py-y1);
        var t=Math.max(0,Math.min(1,((px-x1)*dx+(py-y1)*dy)/l2));
        return Math.hypot(px-(x1+t*dx),py-(y1+t*dy));
    }

    function saveDrawings() {
        var s=drawings.filter(function(d){return d.type!=='hline';});
        try{ localStorage.setItem('tl_eq_drawings',JSON.stringify(s)); }catch(e){}
    }
    function loadDrawings() {
        try{ return JSON.parse(localStorage.getItem('tl_eq_drawings')||'[]'); }catch(e){ return []; }
    }

    // ════════════════════════════════════════════════════════════════════════
    // MODAL OPEN / CLOSE
    // ════════════════════════════════════════════════════════════════════════
    function openModal(title, icon, type) {
        document.getElementById('chartFullModalLabel').textContent = title;
        document.getElementById('chartFullModalIcon').className = 'fas '+icon;
        currentType = type;
        document.getElementById('panelPL').style.display = type==='bar' ? 'flex' : 'none';
        document.getElementById('panelLW').style.display = type==='area' ? 'flex' : 'none';
        document.getElementById('btnExportPNG').style.display = type==='area' ? '' : 'none';
        if (type==='bar') {
            document.querySelectorAll('.period-btn').forEach(function(b){ b.classList.toggle('active',parseInt(b.dataset.days)===0); });
        } else {
            document.querySelectorAll('.tf-btn').forEach(function(b){ b.classList.toggle('active',parseInt(b.dataset.tf)===0); });
        }
        bsModal.show();
    }

    modalEl.addEventListener('shown.bs.modal', function() {
        if (currentType==='bar') {
            renderPLChart(0);
        } else {
            drawings = loadDrawings();
            initLW(0);
        }
    });
    modalEl.addEventListener('hidden.bs.modal', function() {
        destroyPLChart(); destroyLW();
        setTool('cursor'); drawInProg=false; drawStart=null;
        document.getElementById('lwTooltip').style.display='none';
    });

    document.getElementById('expandDaily').addEventListener('click', function(e) {
        e.preventDefault(); openModal('Daily P&L','fa-chart-bar','bar');
    });
    document.getElementById('expandEquity').addEventListener('click', function(e) {
        e.preventDefault(); openModal('Cum. P&L','fa-chart-line','area');
    });
});
</script>

<?php include '../includes/footer.php'; ?>

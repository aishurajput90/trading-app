<?php
require_once 'config/db.php';
$db   = getDB();
$uid  = DEMO_USER_ID;
$acid = getDemoAccountId();
$acc  = getDemoAccount();
$today= date('Y-m-d');

syncDemoBalance($acid);
$acc     = getDemoAccount(); // reload after sync
$balance = (float)$acc['current_balance'];
$startBal= (float)$acc['starting_balance'];
$totalPnL= $balance - $startBal;
$totalPct= $startBal > 0 ? round($totalPnL/$startBal*100,2) : 0;

// Stats
$allClosed = $db->prepare("SELECT COUNT(*) as total,
    COALESCE(SUM(profit_loss),0) as gross,
    COALESCE(SUM(commission),0) as comm,
    COALESCE(SUM(net_pl),0) as net,
    COALESCE(SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END),0) as wins,
    COALESCE(MAX(net_pl),0) as best,
    COALESCE(MIN(net_pl),0) as worst,
    COALESCE(AVG(net_pl),0) as avg_pl
    FROM demo_trades WHERE account_id=? AND status='closed'");
$allClosed->execute([$acid]); $stats = $allClosed->fetch();
$winRate = $stats['total'] > 0 ? round($stats['wins']/$stats['total']*100,1) : 0;

// Open trades
$openStmt = $db->prepare("SELECT COUNT(*) as cnt FROM demo_trades WHERE account_id=? AND status='open'");
$openStmt->execute([$acid]); $openCount = $openStmt->fetch()['cnt'];

// Today
$todayStmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(net_pl),0) as net
    FROM demo_trades WHERE account_id=? AND DATE(COALESCE(close_time,open_time))=? AND status='closed'");
$todayStmt->execute([$acid,$today]); $todayStats = $todayStmt->fetch();

// This week
$wStart = date('Y-m-d',strtotime('monday this week'));
$wEnd   = date('Y-m-d',strtotime('sunday this week'));
$weekStmt = $db->prepare("SELECT COALESCE(SUM(net_pl),0) as net FROM demo_trades
    WHERE account_id=? AND status='closed' AND DATE(close_time) BETWEEN ? AND ?");
$weekStmt->execute([$acid,$wStart,$wEnd]); $weekNet = $weekStmt->fetch()['net'];

// 30-day equity curve
$eqStmt = $db->prepare("SELECT DATE(close_time) as d, SUM(net_pl) as net
    FROM demo_trades WHERE account_id=? AND status='closed' AND close_time >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
    GROUP BY DATE(close_time) ORDER BY d");
$eqStmt->execute([$acid]); $eqRows = $eqStmt->fetchAll();
$eqDates=[]; $eqVals=[]; $run=0;
foreach($eqRows as $r){ $run+=$r['net']; $eqDates[]=date('d M',strtotime($r['d'])); $eqVals[]=round($run,2); }

// Recent closed trades
$recentStmt = $db->prepare("SELECT * FROM demo_trades WHERE account_id=? AND status='closed' ORDER BY close_time DESC LIMIT 8");
$recentStmt->execute([$acid]); $recentTrades = $recentStmt->fetchAll();

// Rules compliance (last 20 trades)
$ruleStmt = $db->prepare("SELECT rc.followed, COUNT(*) as cnt FROM demo_rule_checks rc
    JOIN demo_trades dt ON rc.trade_id=dt.id WHERE dt.account_id=? GROUP BY rc.followed");
$ruleStmt->execute([$acid]); $ruleData = $ruleStmt->fetchAll();
$ruleFollowed = 0; $ruleBroken = 0;
foreach($ruleData as $r){ if($r['followed']) $ruleFollowed+=$r['cnt']; else $ruleBroken+=$r['cnt']; }
$ruleTotal = $ruleFollowed + $ruleBroken;
$rulePct   = $ruleTotal > 0 ? round($ruleFollowed/$ruleTotal*100) : 0;

// Strategy breakdown
$stratStmt = $db->prepare("SELECT strategy, COUNT(*) as cnt,
    SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins, COALESCE(SUM(net_pl),0) as net
    FROM demo_trades WHERE account_id=? AND status='closed' AND strategy IS NOT NULL AND strategy!=''
    GROUP BY strategy ORDER BY cnt DESC LIMIT 5");
$stratStmt->execute([$acid]); $strategies = $stratStmt->fetchAll();

$pageTitle = 'Dashboard'; $rootPath = '';
include 'includes/header.php';
?>

<style>
.kpi-grid   { display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px; }
.kpi-card   { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;position:relative;overflow:hidden; }
.kpi-card::before{ content:'';position:absolute;top:0;left:0;right:0;height:3px; }
.kpi-card.green::before{ background:var(--profit); }
.kpi-card.red::before  { background:var(--loss); }
.kpi-card.blue::before { background:var(--accent); }
.kpi-card.purple::before{ background:var(--accent-purple); }
.kpi-card.cyan::before { background:var(--accent-cyan); }
.kpi-lbl    { font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:6px; }
.kpi-val    { font-family:var(--font-mono);font-size:22px;font-weight:700;line-height:1;color:var(--text-primary); }
.kpi-sub    { font-size:11px;color:var(--text-muted);margin-top:4px; }
.kpi-val.pos{ color:var(--profit); } .kpi-val.neg{ color:var(--loss); } .kpi-val.acc{ color:var(--accent); } .kpi-val.pur{ color:var(--accent-purple); }

.g2 { display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px; }
.g3 { display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px; }
@media(max-width:900px){ .g2,.g3{ grid-template-columns:1fr; } }

.dp { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden; }
.dp-hdr{ padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;
         color:var(--text-secondary);display:flex;align-items:center;gap:8px; }
.dp-body{ padding:16px 18px; }
.dp-link{ margin-left:auto;font-size:12px;color:var(--accent);text-decoration:none; }
.dp-link:hover{ text-decoration:underline; }

.mr { display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light);font-size:13px; }
.mr:last-child{ border-bottom:none; }
.mr-lbl{ color:var(--text-muted); }
.mr-val{ font-weight:600;font-family:var(--font-mono); }
.pos{ color:var(--profit); } .neg{ color:var(--loss); } .acc{ color:var(--accent); }

.rule-bar    { height:10px;background:var(--bg-elevated);border-radius:5px;overflow:hidden;margin:8px 0; }
.rule-fill   { height:100%;border-radius:5px;background:var(--profit);transition:width .6s; }

.dt { width:100%;border-collapse:collapse;font-size:12px; }
.dt th{ padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);text-align:left; }
.dt td{ padding:8px 10px;border-bottom:1px solid var(--border-light);vertical-align:middle; }
.dt tr:last-child td{ border-bottom:none; }
.dt tr:hover td{ background:var(--bg-elevated); }
.pl-pos{ color:var(--profit);font-weight:700; } .pl-neg{ color:var(--loss);font-weight:700; }
.badge-buy { background:rgba(22,163,74,.12);color:var(--profit);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px; }
.badge-sell{ background:rgba(220,38,38,.12);color:var(--loss);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px; }
.sym-b     { background:rgba(37,99,235,.1);color:var(--accent);font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;font-family:var(--font-mono); }
</style>

<!-- Account banner if no trades yet -->
<?php if ($stats['total'] == 0 && $openCount == 0): ?>
<div style="background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.3);border-radius:var(--radius);padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px">
    <i class="fas fa-flask-vial" style="font-size:32px;color:var(--accent-purple)"></i>
    <div>
        <div style="font-size:16px;font-weight:700;margin-bottom:4px">Welcome to Demo Trading! 🎯</div>
        <div style="font-size:13px;color:var(--text-muted)">Virtual balance: <strong style="color:var(--profit)"><?= fmtD($balance) ?></strong> — Start practicing without any real money risk.</div>
    </div>
    <a href="pages/trade.php" class="btn-primary-custom" style="margin-left:auto;white-space:nowrap">
        <i class="fas fa-plus"></i> Place First Trade
    </a>
</div>
<?php endif; ?>

<!-- KPI Grid -->
<div class="kpi-grid">
    <div class="kpi-card <?= $totalPnL>=0?'green':'red' ?>">
        <div class="kpi-lbl">Demo Balance</div>
        <div class="kpi-val <?= $totalPnL>=0?'pos':'neg' ?>"><?= fmtD($balance) ?></div>
        <div class="kpi-sub">Started: <?= fmtD($startBal) ?></div>
    </div>
    <div class="kpi-card <?= $totalPnL>=0?'green':'red' ?>">
        <div class="kpi-lbl">Total P&amp;L</div>
        <div class="kpi-val <?= $totalPnL>=0?'pos':'neg' ?>"><?= fmtDPL($totalPnL) ?></div>
        <div class="kpi-sub"><?= $totalPct >= 0 ? '+' : '' ?><?= $totalPct ?>% return</div>
    </div>
    <div class="kpi-card blue">
        <div class="kpi-lbl">Win Rate</div>
        <div class="kpi-val acc"><?= $winRate ?>%</div>
        <div class="kpi-sub"><?= $stats['wins'] ?>W / <?= $stats['total']-$stats['wins'] ?>L</div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-lbl">Total Trades</div>
        <div class="kpi-val pur"><?= number_format($stats['total']) ?></div>
        <div class="kpi-sub"><?= $openCount ?> open now</div>
    </div>
    <div class="kpi-card <?= $todayStats['net']>=0?'green':'red' ?>">
        <div class="kpi-lbl">Today P&amp;L</div>
        <div class="kpi-val <?= $todayStats['net']>=0?'pos':'neg' ?>"><?= fmtDPL($todayStats['net']) ?></div>
        <div class="kpi-sub"><?= $todayStats['cnt'] ?> trades</div>
    </div>
    <div class="kpi-card <?= $weekNet>=0?'green':'red' ?>">
        <div class="kpi-lbl">This Week</div>
        <div class="kpi-val <?= $weekNet>=0?'pos':'neg' ?>"><?= fmtDPL($weekNet) ?></div>
        <div class="kpi-sub"><?= date('d M',strtotime($wStart)) ?> – <?= date('d M',strtotime($wEnd)) ?></div>
    </div>
    <div class="kpi-card green">
        <div class="kpi-lbl">Best Trade</div>
        <div class="kpi-val pos"><?= fmtDPL($stats['best']) ?></div>
    </div>
    <div class="kpi-card red">
        <div class="kpi-lbl">Worst Trade</div>
        <div class="kpi-val neg"><?= fmtDPL($stats['worst']) ?></div>
    </div>
</div>

<!-- Quick Actions -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">
    <a href="pages/trade.php" class="btn-primary-custom"><i class="fas fa-plus"></i> New Trade</a>
    <a href="pages/open.php" class="btn-secondary-custom"><i class="fas fa-door-open"></i> Open Positions (<?= $openCount ?>)</a>
    <a href="pages/review.php" class="btn-secondary-custom"><i class="fas fa-chart-line"></i> Performance Review</a>
    <a href="pages/rules.php" class="btn-secondary-custom"><i class="fas fa-list-check"></i> My Rules</a>
</div>

<!-- Equity Curve + Stats -->
<div class="g3" style="margin-bottom:16px">
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-chart-area" style="color:var(--accent)"></i> Equity Curve (30 days) <a href="pages/review.php" class="dp-link">Full Review →</a></div>
        <div class="dp-body">
            <div style="position:relative;height:220px"><canvas id="eqChart" role="img" aria-label="Equity curve"></canvas></div>
        </div>
    </div>
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-calculator" style="color:var(--accent-purple)"></i> Account Stats</div>
        <div class="dp-body">
            <div class="mr"><span class="mr-lbl">Avg Trade P&L</span><span class="mr-val <?= $stats['avg_pl']>=0?'pos':'neg' ?>"><?= fmtDPL($stats['avg_pl']) ?></span></div>
            <div class="mr"><span class="mr-lbl">Avg Win</span><span class="mr-val pos">
                <?php
                $avgWinStmt=$db->prepare("SELECT COALESCE(AVG(net_pl),0) as a FROM demo_trades WHERE account_id=? AND status='closed' AND profit_loss>0");
                $avgWinStmt->execute([$acid]); echo fmtDPL($avgWinStmt->fetch()['a']); ?>
            </span></div>
            <div class="mr"><span class="mr-lbl">Avg Loss</span><span class="mr-val neg">
                <?php
                $avgLossStmt=$db->prepare("SELECT COALESCE(AVG(net_pl),0) as a FROM demo_trades WHERE account_id=? AND status='closed' AND profit_loss<0");
                $avgLossStmt->execute([$acid]); echo fmtDPL($avgLossStmt->fetch()['a']); ?>
            </span></div>
            <div class="mr"><span class="mr-lbl">Total Commission</span><span class="mr-val neg">-<?= fmtD($stats['comm']) ?></span></div>
            <div class="mr"><span class="mr-lbl">Rule Compliance</span><span class="mr-val acc"><?= $rulePct ?>%</span></div>
            <div class="rule-bar"><div class="rule-fill" style="width:<?= $rulePct ?>%"></div></div>
            <div style="font-size:10px;color:var(--text-muted)"><?= $ruleFollowed ?> followed · <?= $ruleBroken ?> broken</div>
        </div>
    </div>
</div>

<!-- Strategy Breakdown + Recent Trades -->
<div class="g2">
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-chess" style="color:var(--accent-cyan)"></i> Strategy Performance <a href="pages/review.php" class="dp-link">Details →</a></div>
        <div class="dp-body" style="padding:0">
            <?php if(empty($strategies)): ?>
            <div style="padding:30px;text-align:center;color:var(--text-muted)">Tag strategies when placing trades to see breakdown</div>
            <?php else: ?>
            <table class="dt">
                <thead><tr><th>Strategy</th><th>Trades</th><th>Win%</th><th>Net P&L</th></tr></thead>
                <tbody>
                <?php foreach($strategies as $s):
                    $swr=$s['cnt']>0?round($s['wins']/$s['cnt']*100,1):0; ?>
                <tr>
                    <td><?= htmlspecialchars($s['strategy']) ?></td>
                    <td><?= $s['cnt'] ?></td>
                    <td class="<?= $swr>=50?'pos':'neg' ?>"><?= $swr ?>%</td>
                    <td class="<?= $s['net']>=0?'pl-pos':'pl-neg' ?>"><?= fmtDPL($s['net']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-clock-rotate-left"></i> Recent Closed Trades <a href="pages/journal.php" class="dp-link">All →</a></div>
        <div style="overflow-x:auto">
            <table class="dt">
                <thead><tr><th>Symbol</th><th>Type</th><th>Net P&L</th></tr></thead>
                <tbody>
                <?php foreach($recentTrades as $t): ?>
                <tr>
                    <td><span class="sym-b"><?= htmlspecialchars($t['symbol']) ?></span></td>
                    <td><span class="badge-<?= $t['trade_type'] ?>"><?= strtoupper($t['trade_type']) ?></span></td>
                    <td class="<?= $t['net_pl']>=0?'pl-pos':'pl-neg' ?>"><?= fmtDPL($t['net_pl']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($recentTrades)): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:20px">No trades yet</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark=document.documentElement.getAttribute('data-theme')==='dark';
    const gridC=isDark?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.06)';
    const textC=isDark?'#94a3b8':'#64748b';
    const dates=<?= json_encode($eqDates) ?>,vals=<?= json_encode($eqVals) ?>;
    if(!dates.length){return;}
    const col=vals[vals.length-1]>=0?'#22c55e':'#ef4444';
    new Chart(document.getElementById('eqChart'),{type:'line',data:{labels:dates,datasets:[{data:vals,borderColor:col,backgroundColor:col+'18',borderWidth:2,fill:true,pointRadius:dates.length>20?0:4,tension:0.35}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' $'+ctx.parsed.y.toFixed(2)}}},scales:{x:{grid:{color:gridC},ticks:{color:textC,font:{size:10},maxTicksLimit:8,maxRotation:30}},y:{grid:{color:gridC},ticks:{color:textC,font:{size:10},callback:v=>'$'+v}}}}});
})();
</script>
<?php include 'includes/footer.php'; ?>

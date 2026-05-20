<?php
require_once 'config/db.php';
$db     = getDB();
$userId = INDIA_DEFAULT_USER;
$today  = date('Y-m-d');

// Today stats
$todayStmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(profit_loss),0) as gross,
    COALESCE(SUM(brokerage),0) as brok, COALESCE(SUM(net_pl),0) as net
    FROM india_trades WHERE user_id=? AND trade_date=?");
$todayStmt->execute([$userId, $today]);
$todayStats = $todayStmt->fetch();

// This week
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$weekStmt  = $db->prepare("SELECT COALESCE(SUM(net_pl),0) as net, COUNT(*) as cnt
    FROM india_trades WHERE user_id=? AND trade_date BETWEEN ? AND ?");
$weekStmt->execute([$userId, $weekStart, $weekEnd]);
$weekStats = $weekStmt->fetch();

// This month
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$monthStmt  = $db->prepare("SELECT COALESCE(SUM(profit_loss),0) as gross,
    COALESCE(SUM(brokerage),0) as brok, COALESCE(SUM(net_pl),0) as net,
    COUNT(*) as cnt FROM india_trades WHERE user_id=? AND trade_date BETWEEN ? AND ?");
$monthStmt->execute([$userId, $monthStart, $monthEnd]);
$monthStats = $monthStmt->fetch();

// All time
$allStmt = $db->prepare("SELECT COUNT(*) as total,
    COALESCE(SUM(net_pl),0) as net,
    COALESCE(SUM(brokerage),0) as brok,
    COALESCE(SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END),0) as wins
    FROM india_trades WHERE user_id=?");
$allStmt->execute([$userId]);
$allStats = $allStmt->fetch();
$winRate = $allStats['total'] > 0 ? round($allStats['wins']/$allStats['total']*100,1) : 0;

// 14 day chart
$chartStmt = $db->prepare("SELECT trade_date, SUM(net_pl) as net
    FROM india_trades WHERE user_id=? AND trade_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY)
    GROUP BY trade_date ORDER BY trade_date");
$chartStmt->execute([$userId]);
$chartRows   = $chartStmt->fetchAll();
$chartLabels = array_map(fn($r) => date('d M', strtotime($r['trade_date'])), $chartRows);
$chartPLs    = array_map(fn($r) => round($r['net'],2), $chartRows);

// Recent 10 trades (each trade = a matched pair, so these are completed rounds)
$recentStmt = $db->prepare("SELECT * FROM india_trades WHERE user_id=? ORDER BY close_time DESC LIMIT 10");
$recentStmt->execute([$userId]);
$recentTrades = $recentStmt->fetchAll();

// Top instruments this month
$topStmt = $db->prepare("SELECT base_instrument, COUNT(*) as cnt, SUM(net_pl) as net
    FROM india_trades WHERE user_id=? AND trade_date BETWEEN ? AND ?
    GROUP BY base_instrument ORDER BY net DESC LIMIT 5");
$topStmt->execute([$userId, $monthStart, $monthEnd]);
$topInstruments = $topStmt->fetchAll();

// Best day ever
$bestStmt = $db->prepare("SELECT trade_date, SUM(net_pl) as net FROM india_trades
    WHERE user_id=? GROUP BY trade_date ORDER BY net DESC LIMIT 1");
$bestStmt->execute([$userId]);
$bestDay = $bestStmt->fetch();

$pageTitle = 'Dashboard';
$rootPath  = '';
include 'includes/header.php';
?>

<!-- Today Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-arrows-left-right"></i></div>
            <div class="stat-value"><?= $todayStats['cnt'] ?></div>
            <div class="stat-label">Today's Trades</div>
            <div class="stat-sub"><?= date('l, d M') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $todayStats['net'] >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $todayStats['net'] >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-indian-rupee-sign"></i></div>
            <div class="stat-value <?= $todayStats['net'] >= 0 ? 'positive' : 'negative' ?>">
                <?= formatINR_PL($todayStats['net']) ?>
            </div>
            <div class="stat-label">Today Net P&amp;L</div>
            <div class="stat-sub">After brokerage</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $monthStats['net'] >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $monthStats['net'] >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-calendar"></i></div>
            <div class="stat-value <?= $monthStats['net'] >= 0 ? 'positive' : 'negative' ?>">
                <?= formatINR_PL($monthStats['net']) ?>
            </div>
            <div class="stat-label">Month Net P&amp;L</div>
            <div class="stat-sub"><?= date('F Y') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card cyan">
            <div class="stat-icon cyan"><i class="fas fa-trophy"></i></div>
            <div class="stat-value" style="color:var(--accent-cyan)"><?= $winRate ?>%</div>
            <div class="stat-label">Win Rate</div>
            <div class="stat-sub"><?= number_format($allStats['total']) ?> total trades</div>
        </div>
    </div>
</div>

<!-- Row 2 -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-wallet"></i> Portfolio Overview</div></div>
            <div class="panel-body">
                <div class="text-center mb-3">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Total Net P&amp;L</div>
                    <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:<?= $allStats['net']>=0?'var(--profit)':'var(--loss)' ?>">
                        <?= formatINR_PL($allStats['net']) ?>
                    </div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Total Brokerage Paid</span><span class="metric-value" style="color:var(--loss)">-<?= formatINR($allStats['brok']) ?></span></div>
                <div class="metric-row"><span class="metric-label">This Week Net</span><span class="metric-value <?= $weekStats['net']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($weekStats['net']) ?></span></div>
                <div class="metric-row"><span class="metric-label">This Month Gross</span><span class="metric-value <?= $monthStats['gross']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($monthStats['gross']) ?></span></div>
                <?php if ($bestDay): ?>
                <div class="metric-row"><span class="metric-label">Best Day Ever</span>
                    <span class="metric-value pl-positive" title="<?= date('d M Y',strtotime($bestDay['trade_date'])) ?>"><?= formatINR_PL($bestDay['net']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-coins"></i> Top Instruments — <?= date('M Y') ?></div></div>
            <div class="panel-body">
                <?php if (empty($topInstruments)): ?>
                <div style="text-align:center;color:var(--text-muted);padding:20px">No trades this month</div>
                <?php else: ?>
                <?php foreach ($topInstruments as $ins): ?>
                <div class="metric-row">
                    <span class="metric-label">
                        <span class="symbol-badge"><?= htmlspecialchars($ins['base_instrument']) ?></span>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:6px"><?= $ins['cnt'] ?> trades</span>
                    </span>
                    <span class="metric-value <?= $ins['net']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($ins['net']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-indian-rupee-sign"></i> Charges Summary</div></div>
            <div class="panel-body">
                <div class="text-center mb-3">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Brokerage Paid (All Time)</div>
                    <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--loss)">
                        -<?= formatINR($allStats['brok']) ?>
                    </div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">This Month Brokerage</span><span class="metric-value" style="color:var(--loss)">-<?= formatINR($monthStats['brok']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Month Gross P&amp;L</span><span class="metric-value <?= $monthStats['gross']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($monthStats['gross']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Month Net P&amp;L</span><span class="metric-value <?= $monthStats['net']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($monthStats['net']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Today Brokerage</span><span class="metric-value" style="color:var(--loss)">-<?= formatINR($todayStats['brok']) ?></span></div>
            </div>
        </div>
    </div>
</div>

<!-- 14-Day Chart + Recent -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-bar"></i> 14-Day Net P&amp;L</div>
                <a href="pages/analysis.php" class="panel-link">Analyze →</a>
            </div>
            <div class="panel-body">
                <div class="chart-container" style="height:220px"><canvas id="plChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent Trades</div>
                <a href="pages/journal.php" class="panel-link">View All →</a>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Instrument</th><th>Gross</th><th>Brok</th><th>Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTrades as $t): ?>
                    <tr>
                        <td style="font-size:12px;color:var(--text-muted)"><?= date('d M', strtotime($t['trade_date'])) ?></td>
                        <td><span class="symbol-badge" style="font-size:10px"><?= htmlspecialchars($t['base_instrument']) ?></span></td>
                        <td class="<?= $t['profit_loss']>=0?'pl-positive':'pl-negative' ?>" style="font-size:12px"><?= formatINR_PL($t['profit_loss']) ?></td>
                        <td style="color:var(--loss);font-size:11px">-<?= formatINR($t['brokerage']) ?></td>
                        <td class="<?= $t['net_pl']>=0?'pl-positive':'pl-negative' ?>" style="font-weight:700;font-size:12px"><?= formatINR_PL($t['net_pl']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentTrades)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:30px">
                        No trades yet — <a href="pages/import.php">Import Dhan CSV</a>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
createLineChart('plChart', <?= json_encode($chartLabels) ?>, <?= json_encode($chartPLs) ?>, 'Net P&L (₹)');
</script>

<?php include 'includes/footer.php'; ?>

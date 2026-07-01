<?php
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();

// ── Date range ───────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'all';

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));

switch ($period) {
    case 'weekly':
        $from  = $weekStart;
        $to    = $weekEnd;
        $label = 'This Week (' . date('d M', strtotime($weekStart)) . ' – ' . date('d M', strtotime($weekEnd)) . ')';
        break;
    case 'monthly':
        $from  = date('Y-m-01');
        $to    = date('Y-m-t');
        $label = date('F Y');
        break;
    case 'yearly':
        $from  = date('Y') . '-01-01';
        $to    = date('Y') . '-12-31';
        $label = 'Year ' . date('Y');
        break;
    case 'custom':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
        $label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        break;
    default: // all time
        $period = 'all';
        $s = $db->prepare("SELECT MIN(DATE(trade_datetime)) as mn, MAX(DATE(trade_datetime)) as mx FROM trades WHERE user_id=?");
        $s->execute([$userId]); $rng = $s->fetch();
        $from  = $rng['mn'] ?? date('Y-01-01');
        $to    = $rng['mx'] ?? date('Y-m-d');
        $label = 'All Time';
}

// Helper: build WHERE clause with optional date filter
// All data queries use: user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?
$dArgs = [$userId, $from, $to];

// ── Data queries ─────────────────────────────────────────────────────────────

// Overall
$s = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(profit_loss-brokerage+swap),0) as net, COALESCE(SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END),0) as wins, COALESCE(AVG(profit_loss-brokerage+swap),0) as avg_pl, COALESCE(AVG(CASE WHEN profit_loss>0 THEN profit_loss-brokerage+swap END),0) as avg_win, COALESCE(ABS(AVG(CASE WHEN profit_loss<0 THEN profit_loss-brokerage+swap END)),1) as avg_loss FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?");
$s->execute($dArgs); $overall = $s->fetch();
$overallWR = $overall['total'] > 0 ? round($overall['wins'] / $overall['total'] * 100, 1) : 0;
$overallRR = $overall['avg_loss'] > 0 ? round($overall['avg_win'] / $overall['avg_loss'], 2) : 0;

// By day of week
$s = $db->prepare("SELECT DAYNAME(trade_datetime) as day, DAYOFWEEK(trade_datetime) as dow, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? GROUP BY dow,day ORDER BY dow");
$s->execute($dArgs); $byDay = $s->fetchAll();

// By hour
$s = $db->prepare("SELECT HOUR(trade_datetime) as h, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? GROUP BY h ORDER BY h");
$s->execute($dArgs); $byHour = $s->fetchAll();

// By symbol
$s = $db->prepare("SELECT symbol, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? GROUP BY symbol HAVING trades>=2 ORDER BY net DESC");
$s->execute($dArgs); $bySymbol = $s->fetchAll();

// By trade duration
$s = $db->prepare("SELECT CASE WHEN TIMESTAMPDIFF(MINUTE,open_time,trade_datetime)<5 THEN 'Under 5 min' WHEN TIMESTAMPDIFF(MINUTE,open_time,trade_datetime)<15 THEN '5–15 min' WHEN TIMESTAMPDIFF(MINUTE,open_time,trade_datetime)<60 THEN '15–60 min' WHEN TIMESTAMPDIFF(MINUTE,open_time,trade_datetime)<240 THEN '1–4 hr' ELSE '4 hr+' END as dur, COUNT(*) as trades, AVG(profit_loss-brokerage+swap) as avg_pl, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? AND open_time IS NOT NULL AND open_time!=trade_datetime GROUP BY dur ORDER BY avg_pl DESC");
$s->execute($dArgs); $byDuration = $s->fetchAll();

// By trade direction
$s = $db->prepare("SELECT trade_type, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? GROUP BY trade_type");
$s->execute($dArgs); $byDirection = $s->fetchAll();

// Trades-per-day sweet spot
$s = $db->prepare("SELECT cnt, COUNT(*) as days, AVG(day_pl) as avg_pl, SUM(CASE WHEN day_pl>0 THEN 1 ELSE 0 END) as green FROM (SELECT DATE(trade_datetime) as d, COUNT(*) as cnt, SUM(profit_loss-brokerage+swap) as day_pl FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? GROUP BY d) t GROUP BY cnt ORDER BY cnt");
$s->execute($dArgs); $byTradeCount = $s->fetchAll();

// After-loss behaviour (filter on t1 being in range)
$s = $db->prepare("SELECT t2.profit_loss-t2.brokerage+t2.swap as next_pl FROM trades t1 JOIN trades t2 ON t2.user_id=t1.user_id AND t2.id=(SELECT MIN(id) FROM trades WHERE user_id=t1.user_id AND id>t1.id) WHERE t1.user_id=? AND DATE(t1.trade_datetime) BETWEEN ? AND ? AND t1.profit_loss<0");
$s->execute($dArgs); $afterLoss = $s->fetchAll();
$alTotal = count($afterLoss); $alWins = 0; $alSum = 0;
foreach ($afterLoss as $r) { if ($r['next_pl'] > 0) $alWins++; $alSum += $r['next_pl']; }
$alWR  = $alTotal > 0 ? round($alWins / $alTotal * 100, 1) : 0;
$alAvg = $alTotal > 0 ? round($alSum / $alTotal, 2) : 0;

// After-win behaviour
$s = $db->prepare("SELECT t2.profit_loss-t2.brokerage+t2.swap as next_pl FROM trades t1 JOIN trades t2 ON t2.user_id=t1.user_id AND t2.id=(SELECT MIN(id) FROM trades WHERE user_id=t1.user_id AND id>t1.id) WHERE t1.user_id=? AND DATE(t1.trade_datetime) BETWEEN ? AND ? AND t1.profit_loss>0");
$s->execute($dArgs); $afterWin = $s->fetchAll();
$awTotal = count($afterWin); $awWins = 0; $awSum = 0;
foreach ($afterWin as $r) { if ($r['next_pl'] > 0) $awWins++; $awSum += $r['next_pl']; }
$awWR  = $awTotal > 0 ? round($awWins / $awTotal * 100, 1) : 0;
$awAvg = $awTotal > 0 ? round($awSum / $awTotal, 2) : 0;

// Monthly trend — always show last 12 months regardless of period filter
$s = $db->prepare("SELECT DATE_FORMAT(trade_datetime,'%Y-%m') as mon, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? GROUP BY mon ORDER BY mon DESC LIMIT 12");
$s->execute([$userId]); $byMonth = array_reverse($s->fetchAll());

// Losing streak (within selected period)
$s = $db->prepare("SELECT CASE WHEN profit_loss>0 THEN 'W' ELSE 'L' END as res FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ? ORDER BY trade_datetime ASC");
$s->execute($dArgs); $seq = array_column($s->fetchAll(), 'res');
$maxStreak = 0; $curStreak = 0; $streakBuckets = [];
foreach ($seq as $v) {
    if ($v === 'L') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
    else { if ($curStreak > 0) $streakBuckets[$curStreak] = ($streakBuckets[$curStreak] ?? 0) + 1; $curStreak = 0; }
}
arsort($streakBuckets);

// ── Chart data prep ──────────────────────────────────────────────────────────
$hourLabels = []; $hourNet = []; $hourWR = [];
for ($h = 0; $h <= 23; $h++) {
    $found = null;
    foreach ($byHour as $r) { if ((int)$r['h'] === $h) { $found = $r; break; } }
    $hourLabels[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
    $hourNet[]    = $found ? round($found['net'], 2) : null;
    $hourWR[]     = ($found && $found['trades'] >= 3) ? round($found['wins'] / $found['trades'] * 100, 1) : null;
}

$dayLabels = []; $dayNet = []; $dayWR = [];
$dayOrder  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
foreach ($dayOrder as $d) {
    $found = null;
    foreach ($byDay as $r) { if ($r['day'] === $d) { $found = $r; break; } }
    if (!$found) continue;
    $dayLabels[] = substr($d, 0, 3);
    $dayNet[]    = round($found['net'], 2);
    $dayWR[]     = round($found['wins'] / $found['trades'] * 100, 1);
}

$monthLabels = array_column($byMonth, 'mon');
$monthNet    = array_map(fn($r) => round($r['net'], 2), $byMonth);
$monthWR     = array_map(fn($r) => $r['trades'] > 0 ? round($r['wins'] / $r['trades'] * 100, 1) : 0, $byMonth);

$tpdBuckets = ['1-3'=>['pl'=>0,'days'=>0,'green'=>0],'4-6'=>['pl'=>0,'days'=>0,'green'=>0],'7-10'=>['pl'=>0,'days'=>0,'green'=>0],'11-15'=>['pl'=>0,'days'=>0,'green'=>0],'16-20'=>['pl'=>0,'days'=>0,'green'=>0],'21-30'=>['pl'=>0,'days'=>0,'green'=>0],'31+'=>['pl'=>0,'days'=>0,'green'=>0]];
foreach ($byTradeCount as $r) {
    $cnt = (int)$r['cnt'];
    $key = $cnt <= 3 ? '1-3' : ($cnt <= 6 ? '4-6' : ($cnt <= 10 ? '7-10' : ($cnt <= 15 ? '11-15' : ($cnt <= 20 ? '16-20' : ($cnt <= 30 ? '21-30' : '31+')))));
    $tpdBuckets[$key]['pl']    += $r['avg_pl'] * $r['days'];
    $tpdBuckets[$key]['days']  += $r['days'];
    $tpdBuckets[$key]['green'] += $r['green'];
}
$tpdLabels = []; $tpdAvg = [];
foreach ($tpdBuckets as $lbl => $b) {
    if ($b['days'] == 0) continue;
    $tpdLabels[] = $lbl;
    $tpdAvg[]    = round($b['pl'] / $b['days'], 2);
}

$pageTitle = 'Pattern Analysis';
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
.pat-grid  { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px }
.pat-grid3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px }
.pat-card  { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px }
.pat-title { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary);margin-bottom:14px;display:flex;align-items:center;gap:7px }
.pat-chart { position:relative }
.stat-pill { display:flex;align-items:center;justify-content:space-between;padding:9px 12px;background:var(--bg-base);border-radius:10px;margin-bottom:7px;font-size:12px }
.stat-pill:last-child { margin-bottom:0 }
.finding   { padding:10px 12px;border-radius:10px;font-size:11.5px;line-height:1.55;margin-bottom:8px;display:flex;gap:9px;align-items:flex-start }
.finding:last-child { margin-bottom:0 }
.finding-icon { font-size:14px;flex-shrink:0;margin-top:1px }
.red   { background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2) }
.green { background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2) }
.amber { background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2) }
.blue  { background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2) }
.pat-tab { padding:6px 14px;border-radius:8px;border:1px solid var(--border);font-size:12px;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all .15s;cursor:pointer;background:var(--bg-surface) }
.pat-tab:hover { border-color:var(--accent);color:var(--accent) }
.pat-tab.active { background:var(--accent);color:#fff;border-color:var(--accent) }
@media(max-width:860px){ .pat-grid,.pat-grid3 { grid-template-columns:1fr } }
</style>

<!-- ── Period tabs ───────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <?php foreach (['all'=>'All Time','weekly'=>'This Week','monthly'=>'This Month','yearly'=>'This Year','custom'=>'Custom'] as $k=>$lbl): ?>
    <a href="?period=<?= $k ?>" class="pat-tab <?= $period===$k?'active':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <div style="margin-left:auto;font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:5px">
        <i class="far fa-calendar"></i> <strong><?= htmlspecialchars($label) ?></strong>
    </div>
</div>

<?php if ($period === 'custom'): ?>
<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
    <input type="hidden" name="period" value="custom">
    <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($from) ?>">
    <span style="color:var(--text-muted);font-size:13px">→</span>
    <input type="date" name="to"   class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($to) ?>">
    <button type="submit" class="btn btn-sm btn-primary" style="padding:5px 14px">Apply</button>
    <a href="?period=all" style="font-size:12px;color:var(--text-muted)">✕ Clear</a>
</form>
<?php endif; ?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div>
        <h1 style="font-size:20px;font-weight:800;margin:0">Pattern Analysis</h1>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px"><?= number_format($overall['total']) ?> trades in selected period</div>
    </div>
    <?php if ($overall['total'] > 0): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <div style="padding:6px 14px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;font-size:12px;color:var(--text-muted)">
            WR <strong style="color:<?= $overallWR>=50?'#22c55e':'#ef4444' ?>"><?= $overallWR ?>%</strong>
        </div>
        <div style="padding:6px 14px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;font-size:12px;color:var(--text-muted)">
            RR <strong style="color:var(--text-primary)"><?= $overallRR ?></strong>
        </div>
        <div style="padding:6px 14px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;font-size:12px;color:var(--text-muted)">
            Net <strong style="color:<?= $overall['net']>=0?'#22c55e':'#ef4444' ?>"><?= formatPL($overall['net']) ?></strong>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($overall['total'] == 0): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
    <i class="fas fa-chart-line" style="font-size:40px;margin-bottom:12px;opacity:.3"></i>
    <div style="font-size:16px;font-weight:600">No trades in this period</div>
    <div style="font-size:13px;margin-top:6px">Try selecting a wider date range</div>
</div>
<?php else: ?>

<!-- ── Key Findings ─────────────────────────────────────────────────────────── -->
<div class="pat-card" style="margin-bottom:14px">
    <div class="pat-title"><i class="fas fa-lightbulb" style="color:#f59e0b"></i> Key Findings — <?= htmlspecialchars($label) ?></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <?php
        $findings = [];

        $under5 = null; $over15 = null;
        foreach ($byDuration as $d) {
            if ($d['dur'] === 'Under 5 min') $under5 = $d;
            if ($d['dur'] === '15–60 min')   $over15 = $d;
        }
        if ($under5 && $under5['trades'] >= 5) {
            $pct = round($under5['trades'] / $overall['total'] * 100);
            $cs_p = getActiveCurrency()['symbol'];
            $findings[] = ['type'=>'red','icon'=>'⚡','text'=>"<strong>$pct% of trades are under 5 min</strong> — avg <strong>{$cs_p}".round($under5['avg_pl'],2)."</strong> each. Trades held 15–60 min avg <strong>+{$cs_p}".($over15?round($over15['avg_pl'],2):'n/a')."</strong>. Hold longer."];
        }
        if ($alTotal > 10 && $alWR < $overallWR - 2) {
            $findings[] = ['type'=>'red','icon'=>'🔴','text'=>"<strong>Revenge trading pattern:</strong> after a loss your WR drops to <strong>$alWR%</strong> vs $overallWR% overall, avg P/L = <strong>$alAvg</strong>. Pause after every loss."];
        }
        $worstH = array_filter($byHour, fn($r)=>$r['net']<-50 && $r['trades']>=5);
        usort($worstH, fn($a,$b)=>$a['net']<=>$b['net']);
        if ($worstH) {
            $wh = array_slice($worstH,0,3); $whLoss = round(array_sum(array_column($wh,'net')),2);
            $whStr = implode(', ',array_map(fn($r)=>str_pad($r['h'],2,'0',STR_PAD_LEFT).':00',$wh));
            $cs_wh = getActiveCurrency()['symbol'];
            $findings[] = ['type'=>'red','icon'=>'🕐','text'=>"Hours <strong>$whStr</strong> cost you <strong>{$cs_wh}".number_format(abs($whLoss),2)."</strong> in this period. Avoid trading then."];
        }
        $bestH = array_filter($byHour, fn($r)=>$r['net']>20 && $r['trades']>=5);
        usort($bestH, fn($a,$b)=>$b['net']<=>$a['net']);
        if ($bestH) {
            $bh = array_slice($bestH,0,3); $bhGain = round(array_sum(array_column($bh,'net')),2);
            $bhStr = implode(', ',array_map(fn($r)=>str_pad($r['h'],2,'0',STR_PAD_LEFT).':00',$bh));
            $cs_bh = getActiveCurrency()['symbol'];
            $findings[] = ['type'=>'green','icon'=>'✅','text'=>"Best hours: <strong>$bhStr</strong> — total <strong>+{$cs_bh}".number_format($bhGain,2)."</strong>. Focus your sessions here."];
        }
        if (count($byDay) >= 2) {
            $sorted = $byDay; usort($sorted, fn($a,$b)=>$b['net']<=>$a['net']);
            $best = $sorted[0]; $worst = end($sorted);
            $cs_pat = getActiveCurrency()['symbol'];
            $findings[] = ['type'=>'amber','icon'=>'📅','text'=>"<strong>{$best['day']}</strong> is best (".($best['net']>=0?'+':'')."{$cs_pat}".number_format($best['net'],2)."), <strong>{$worst['day']}</strong> is worst ({$cs_pat}".number_format($worst['net'],2).") in this period."];
        }
        foreach ($byDirection as $dir) {
            if (strtolower($dir['trade_type'])==='sell' && $dir['net']<-200 && $dir['trades']>=10) {
                $cs_dir = getActiveCurrency()['symbol'];
                $findings[] = ['type'=>'red','icon'=>'📉','text'=>"<strong>SELL trades</strong> lost <strong>{$cs_dir}".number_format(abs($dir['net']),2)."</strong> ({$dir['trades']} trades, WR ".round($dir['wins']/$dir['trades']*100,1)."%). Be more selective with short entries."];
            }
        }
        $heavyDays = array_filter($byTradeCount, fn($r)=>(int)$r['cnt']>=20 && $r['avg_pl']<-10);
        if ($heavyDays) {
            $findings[] = ['type'=>'red','icon'=>'🔁','text'=>"<strong>Overtrading detected:</strong> days with 20+ trades average ".round(array_sum(array_column($heavyDays,'avg_pl'))/count($heavyDays),2)." P/L. Your 7–15 trade days perform better."];
        }
        if ($maxStreak >= 5) {
            $findings[] = ['type'=>'amber','icon'=>'⚠️','text'=>"Longest losing streak in this period: <strong>$maxStreak</strong> consecutive losses. Consider a daily stop rule after 4 losses."];
        }
        if (empty($findings)) {
            $findings[] = ['type'=>'blue','icon'=>'📊','text'=>"Not enough data in this period to generate specific findings. Try widening the date range."];
        }
        foreach ($findings as $f): ?>
        <div class="finding <?= $f['type'] ?>">
            <span class="finding-icon"><?= $f['icon'] ?></span>
            <span><?= $f['text'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Row 1: Hour + Day ─────────────────────────────────────────────────────── -->
<div class="pat-grid">
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-clock" style="color:var(--accent-cyan)"></i> Performance by Hour</div>
        <div class="pat-chart" style="height:220px"><canvas id="hourChart"></canvas></div>
    </div>
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-calendar-week" style="color:var(--accent-purple)"></i> Performance by Day of Week</div>
        <div class="pat-chart" style="height:220px"><canvas id="dowChart"></canvas></div>
    </div>
</div>

<!-- ── Row 2: Duration + Direction / Revenge ─────────────────────────────────── -->
<div class="pat-grid">
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-stopwatch" style="color:#f59e0b"></i> Trade Duration vs Performance</div>
        <?php if ($byDuration): foreach ($byDuration as $d):
            $wr  = $d['trades'] > 0 ? round($d['wins'] / $d['trades'] * 100, 1) : 0;
            $avg = round($d['avg_pl'], 2);
            $clr = $avg >= 0 ? '#22c55e' : '#ef4444';
            $pct = $overall['total'] > 0 ? round($d['trades'] / $overall['total'] * 100, 1) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:80px;flex-shrink:0;font-size:11px;font-weight:600;color:var(--text-secondary)"><?= $d['dur'] ?></div>
            <div style="flex:1">
                <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-bottom:3px">
                    <span><?= $d['trades'] ?> trades (<?= $pct ?>%)</span>
                    <span style="font-weight:700;color:<?= $clr ?>"><?= formatPL($avg) ?> avg</span>
                </div>
                <div style="height:8px;background:var(--bg-base);border-radius:99px;overflow:hidden">
                    <div style="height:100%;width:<?= min(100,$pct*2) ?>%;background:<?= $clr ?>;border-radius:99px;opacity:.8"></div>
                </div>
            </div>
            <div style="width:42px;text-align:right;font-size:11px;font-weight:700;color:<?= $wr>=50?'#22c55e':'#ef4444' ?>"><?= $wr ?>%</div>
        </div>
        <?php endforeach; else: ?>
        <div style="color:var(--text-muted);font-size:12px;padding:20px 0">No duration data (open_time not recorded)</div>
        <?php endif; ?>
    </div>

    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-arrows-left-right" style="color:var(--accent)"></i> Buy vs Sell</div>
        <?php foreach ($byDirection as $d):
            $wr  = $d['trades'] > 0 ? round($d['wins'] / $d['trades'] * 100, 1) : 0;
            $net = round($d['net'], 2);
            $avg = $d['trades'] > 0 ? round($d['net'] / $d['trades'], 2) : 0;
            $clr = $net >= 0 ? '#22c55e' : '#ef4444';
            $icon = strtolower($d['trade_type']) === 'buy' ? '↑' : '↓';
        ?>
        <div class="stat-pill" style="margin-bottom:10px">
            <div>
                <div style="font-size:13px;font-weight:700"><?= $icon ?> <?= strtoupper($d['trade_type']) ?></div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= $d['trades'] ?> trades</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:13px;font-weight:800;color:<?= $clr ?>"><?= formatPL($net) ?></div>
                <div style="font-size:10px;color:var(--text-muted)">WR <?= $wr ?>% · Avg <?= formatPL($avg) ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:16px">
            <div class="pat-title" style="margin-bottom:10px"><i class="fas fa-repeat" style="color:#ef4444"></i> After-Trade Behaviour</div>
            <div class="stat-pill">
                <span>WR after a <span style="color:#ef4444;font-weight:700">loss</span></span>
                <strong style="color:<?= $alWR>=$overallWR?'#22c55e':'#ef4444' ?>"><?= $alWR ?>%</strong>
            </div>
            <div class="stat-pill">
                <span>Avg P/L after a <span style="color:#ef4444;font-weight:700">loss</span></span>
                <strong style="color:<?= $alAvg>=0?'#22c55e':'#ef4444' ?>"><?= formatPL($alAvg) ?></strong>
            </div>
            <div class="stat-pill">
                <span>WR after a <span style="color:#22c55e;font-weight:700">win</span></span>
                <strong style="color:<?= $awWR>=$overallWR?'#22c55e':'#ef4444' ?>"><?= $awWR ?>%</strong>
            </div>
            <div class="stat-pill">
                <span>Avg P/L after a <span style="color:#22c55e;font-weight:700">win</span></span>
                <strong style="color:<?= $awAvg>=0?'#22c55e':'#ef4444' ?>"><?= formatPL($awAvg) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- ── Row 3: Trades-per-day + Monthly ──────────────────────────────────────── -->
<div class="pat-grid">
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-layer-group" style="color:#f59e0b"></i> Trades Per Day — Sweet Spot</div>
        <div class="pat-chart" style="height:220px"><canvas id="tpdChart"></canvas></div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:8px;text-align:center">Avg day P/L by trade count</div>
    </div>
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-calendar" style="color:var(--accent)"></i> Monthly Trend (last 12 months)</div>
        <div class="pat-chart" style="height:220px"><canvas id="monthChart"></canvas></div>
    </div>
</div>

<!-- ── Row 4: Symbol + Streak ───────────────────────────────────────────────── -->
<div class="pat-grid">
    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-coins" style="color:#f59e0b"></i> Symbol Performance</div>
        <?php if ($bySymbol): $maxAbs = max(array_map(fn($r)=>abs($r['net']),$bySymbol)); foreach ($bySymbol as $sym):
            $wr  = $sym['trades']>0 ? round($sym['wins']/$sym['trades']*100,1) : 0;
            $net = round($sym['net'],2); $avg = $sym['trades']>0 ? round($sym['net']/$sym['trades'],2) : 0;
            $clr = $net>=0?'#22c55e':'#ef4444';
            $barW = $maxAbs>0 ? round(abs($net)/$maxAbs*100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:130px;min-width:130px;font-size:11px;font-weight:700;color:var(--text-secondary);flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($sym['symbol']) ?>"><?= htmlspecialchars($sym['symbol']) ?></div>
            <div style="flex:1">
                <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px">
                    <span style="color:var(--text-muted)"><?= $sym['trades'] ?> trades</span>
                    <span style="font-weight:700;color:<?= $clr ?>"><?= formatPL($net) ?> · avg <?= formatPL($avg) ?></span>
                </div>
                <div style="height:8px;background:var(--bg-base);border-radius:99px;overflow:hidden">
                    <div style="height:100%;width:<?= $barW ?>%;background:<?= $clr ?>;border-radius:99px"></div>
                </div>
            </div>
            <div style="width:42px;text-align:right;font-size:11px;font-weight:700;color:<?= $wr>=50?'#22c55e':'#ef4444' ?>"><?= $wr ?>%</div>
        </div>
        <?php endforeach; else: ?>
        <div style="color:var(--text-muted);font-size:12px;padding:20px 0">No symbols with 2+ trades in this period</div>
        <?php endif; ?>
    </div>

    <div class="pat-card">
        <div class="pat-title"><i class="fas fa-skull" style="color:#ef4444"></i> Losing Streak Analysis</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
            <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:14px;text-align:center">
                <div style="font-size:28px;font-weight:900;color:#ef4444"><?= $maxStreak ?></div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Longest streak</div>
            </div>
            <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center">
                <div style="font-size:28px;font-weight:900;color:var(--text-primary)"><?= array_sum(array_values($streakBuckets)) ?></div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Total streaks</div>
            </div>
        </div>
        <?php if ($streakBuckets): $maxCnt = max(array_values($streakBuckets)); ?>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Frequency</div>
        <?php foreach (array_slice($streakBuckets,0,7,true) as $len=>$cnt):
            $barW = round($cnt/$maxCnt*100);
            $clr  = $len>=5?'#ef4444':($len>=3?'#f59e0b':'#94a3b8');
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <div style="width:28px;font-size:11px;font-weight:700;color:<?= $clr ?>;flex-shrink:0"><?= $len ?>L</div>
            <div style="flex:1;height:7px;background:var(--bg-base);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:<?= $barW ?>%;background:<?= $clr ?>;border-radius:99px"></div>
            </div>
            <div style="width:32px;font-size:10px;color:var(--text-muted);text-align:right"><?= $cnt ?>×</div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;padding:10px 12px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:10px;font-size:11px">
            <strong>Suggestion:</strong> Stop trading after <?= min(4, max(2, $maxStreak > 8 ? 3 : 4)) ?> consecutive losses in a session.
        </div>
        <?php else: ?>
        <div style="color:var(--text-muted);font-size:12px;padding:10px 0">No streak data for this period</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // total > 0 ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const CS    = <?= json_encode(getActiveCurrency()['symbol']) ?>;
    const dark  = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridC = dark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const textC = dark ? '#94a3b8' : '#64748b';
    const base  = {
        x:{ grid:{color:gridC}, ticks:{color:textC,font:{size:10}} },
        y:{ grid:{color:gridC}, ticks:{color:textC,font:{size:10}} }
    };
    const y2 = { position:'right', min:0, max:100, grid:{display:false}, ticks:{color:textC,font:{size:9},callback:v=>v+'%'} };

    function barChart(id, labels, net, wr) {
        const el = document.getElementById(id); if (!el) return;
        new Chart(el, { type:'bar', data:{ labels,
            datasets:[
                { label:'Net P/L', data:net, backgroundColor:net.map(v=>v===null?'transparent':v>=0?'rgba(34,197,94,.7)':'rgba(239,68,68,.7)'), borderRadius:4, order:2 },
                { label:'WR %', data:wr, type:'line', borderColor:'rgba(99,102,241,.8)', backgroundColor:'transparent', borderWidth:2, pointRadius:3, pointBackgroundColor:wr.map(v=>v===null?'transparent':v>=50?'#22c55e':'#ef4444'), tension:.35, yAxisID:'y2', order:1 }
            ]
        }, options:{ responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{ callbacks:{
                label: c => c.dataset.label==='WR %' ? (c.parsed.y!==null?' WR: '+c.parsed.y+'%':'') : ' Net: $'+(c.parsed.y??0).toFixed(2)
            }}},
            scales:{ x:{...base.x,ticks:{...base.x.ticks,maxRotation:45}}, y:{...base.y,ticks:{...base.y.ticks,callback:v=>CS+v}}, y2 }
        }});
    }

    barChart('hourChart', <?= json_encode($hourLabels) ?>, <?= json_encode($hourNet) ?>, <?= json_encode($hourWR) ?>);
    barChart('dowChart',  <?= json_encode($dayLabels) ?>,  <?= json_encode($dayNet) ?>,  <?= json_encode($dayWR) ?>);
    barChart('monthChart',<?= json_encode($monthLabels) ?>,<?= json_encode($monthNet) ?>,<?= json_encode($monthWR) ?>);

    // Trades-per-day
    const tpdEl = document.getElementById('tpdChart');
    if (tpdEl) {
        const tpdAvg = <?= json_encode($tpdAvg) ?>;
        new Chart(tpdEl, { type:'bar', data:{ labels:<?= json_encode($tpdLabels) ?>,
            datasets:[{ label:'Avg Day P/L', data:tpdAvg,
                backgroundColor:tpdAvg.map(v=>v>=0?'rgba(34,197,94,.75)':'rgba(239,68,68,.75)'), borderRadius:6 }]
        }, options:{ responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:c=>' Avg P/L: $'+c.parsed.y.toFixed(2) }}},
            scales:{ x:{...base.x,title:{display:true,text:'Trades per day',color:textC,font:{size:10}}}, y:{...base.y,ticks:{...base.y.ticks,callback:v=>CS+v}} }
        }});
    }
})();
</script>

<?php include '../includes/footer.php'; ?>

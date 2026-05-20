<?php
require_once '../config/db.php';
$db=getDB(); $userId=INDIA_DEFAULT_USER;
$date=$_GET['date']??date('Y-m-d');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){header('Location:calendar.php');exit;}

$ts=strtotime($date);
$prevDate=date('Y-m-d',strtotime($date.' -1 day'));
$nextDate=date('Y-m-d',strtotime($date.' +1 day'));
$backMonth=date('n',$ts); $backYear=date('Y',$ts);

$stmt=$db->prepare("SELECT * FROM india_trades WHERE user_id=? AND trade_date=? ORDER BY close_time ASC");
$stmt->execute([$userId,$date]);
$trades=$stmt->fetchAll();

if(empty($trades)){header("Location:calendar.php?month={$backMonth}&year={$backYear}");exit;}

$grossPL=array_sum(array_column($trades,'profit_loss'));
$totalBrok=array_sum(array_column($trades,'brokerage'));
$netPL=array_sum(array_column($trades,'net_pl'));
$total=count($trades);
$wins=count(array_filter(array_column($trades,'profit_loss'),fn($v)=>$v>0));
$winRate=$total>0?round($wins/$total*100,1):0;
$bestTrade=max(array_column($trades,'profit_loss'));
$worstLoss=min(array_column($trades,'profit_loss'));

// Intraday equity curve
$chartTimes=[]; $chartVals=[]; $running=0.0;
foreach($trades as $t){ $running+=(float)$t['net_pl']; $chartTimes[]=date('H:i',strtotime($t['close_time'])); $chartVals[]=round($running,2); }

// Instrument breakdown
$insMap=[];
foreach($trades as $t){
    $b=$t['base_instrument'];
    if(!isset($insMap[$b])) $insMap[$b]=['c'=>0,'gross'=>0,'brok'=>0,'net'=>0,'w'=>0];
    $insMap[$b]['c']++; $insMap[$b]['gross']+=(float)$t['profit_loss'];
    $insMap[$b]['brok']+=(float)$t['brokerage']; $insMap[$b]['net']+=(float)$t['net_pl'];
    if($t['profit_loss']>0) $insMap[$b]['w']++;
}

$pageTitle='Day — '.date('d M Y',$ts); $rootPath='../';
include '../includes/header.php';
?>
<style>
.day-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.day-nav-btn{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:7px 14px;color:var(--text-primary);cursor:pointer;text-decoration:none;font-size:13px;transition:border-color .15s;}
.day-nav-btn:hover{border-color:var(--accent);color:var(--accent);}
.day-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px;}
.day-kpi{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;}
.day-kpi-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:4px;}
.day-kpi-val{font-family:var(--font-mono);font-size:18px;font-weight:700;line-height:1.1;}
.panel{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;}
.panel-hdr{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;color:var(--text-secondary);}
.panel-body{padding:16px 18px;}
.charge-box{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;}
.charge-item{padding:14px 16px;text-align:center;}
.charge-item+.charge-item{border-left:1px solid var(--border);}
.tr-table{width:100%;border-collapse:collapse;font-size:12px;}
.tr-table th{padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);text-align:left;}
.tr-table td{padding:9px 10px;border-bottom:1px solid var(--border-light);vertical-align:middle;}
.tr-table tr:last-child td{border-bottom:none;}
.tr-table tr:hover td{background:var(--bg-elevated);}
.pl-positive{color:var(--profit);font-weight:700;} .pl-negative{color:var(--loss);font-weight:700;}
.pos{color:var(--profit);} .neg{color:var(--loss);} .wrn{color:var(--warning);}
.symbol-badge{display:inline-block;background:rgba(37,99,235,.1);color:var(--accent);font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;font-family:var(--font-mono);}
</style>

<div class="day-nav">
    <a href="day.php?date=<?= $prevDate ?>" class="day-nav-btn"><i class="fas fa-chevron-left"></i> <?= date('d M',strtotime($prevDate)) ?></a>
    <a href="calendar.php?month=<?= $backMonth ?>&year=<?= $backYear ?>" class="day-nav-btn"><i class="fas fa-calendar-days"></i> <?= date('F Y',$ts) ?></a>
    <a href="day.php?date=<?= $nextDate ?>" class="day-nav-btn"><?= date('d M',strtotime($nextDate)) ?> <i class="fas fa-chevron-right"></i></a>
</div>

<div style="margin-bottom:16px">
    <h5 style="font-weight:700;font-size:20px;margin:0"><?= date('l, d F Y',$ts) ?></h5>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= $total ?> trades &nbsp;·&nbsp; <?= $wins ?>W / <?= $total-$wins ?>L &nbsp;·&nbsp; <?= $winRate ?>% win rate</div>
</div>

<div class="day-kpis">
    <div class="day-kpi"><div class="day-kpi-lbl">Gross P&amp;L</div><div class="day-kpi-val <?= $grossPL>=0?'pos':'neg' ?>"><?= formatINR_PL($grossPL) ?></div><div style="font-size:11px;color:var(--text-muted)">Before charges</div></div>
    <div class="day-kpi"><div class="day-kpi-lbl">Brokerage</div><div class="day-kpi-val neg">-<?= formatINR($totalBrok) ?></div></div>
    <div class="day-kpi" style="border-color:<?= $netPL>=0?'rgba(22,163,74,.4)':'rgba(220,38,38,.4)' ?>"><div class="day-kpi-lbl">Net P&amp;L</div><div class="day-kpi-val <?= $netPL>=0?'pos':'neg' ?>" style="font-size:22px"><?= formatINR_PL($netPL) ?></div><div style="font-size:11px;color:var(--text-muted)">After all charges</div></div>
    <div class="day-kpi"><div class="day-kpi-lbl">Best Trade</div><div class="day-kpi-val pos"><?= formatINR_PL($bestTrade) ?></div></div>
    <div class="day-kpi"><div class="day-kpi-lbl">Worst Trade</div><div class="day-kpi-val neg"><?= formatINR_PL($worstLoss) ?></div></div>
    <div class="day-kpi"><div class="day-kpi-lbl">Win Rate</div><div class="day-kpi-val <?= $winRate>=50?'pos':'wrn' ?>"><?= $winRate ?>%</div></div>
    <div class="day-kpi"><div class="day-kpi-lbl">Total Trades</div><div class="day-kpi-val"><?= $total ?></div></div>
</div>

<!-- Chart + Instrument breakdown -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">
    <div class="panel" style="margin-bottom:0">
        <div class="panel-hdr"><i class="fas fa-chart-line" style="color:var(--accent)"></i> Intraday Equity Curve</div>
        <div class="panel-body"><div style="position:relative;height:200px"><canvas id="eqChart" role="img" aria-label="Intraday equity curve"></canvas></div></div>
    </div>
    <div class="panel" style="margin-bottom:0">
        <div class="panel-hdr"><i class="fas fa-coins" style="color:var(--warning)"></i> By Instrument</div>
        <div class="panel-body" style="padding:0">
            <table class="tr-table">
                <thead><tr><th>Instrument</th><th>Net P&amp;L</th></tr></thead>
                <tbody>
                <?php foreach($insMap as $b=>$s): $snet=$s['net']; ?>
                <tr>
                    <td><span class="symbol-badge"><?= htmlspecialchars($b) ?></span><br><span style="font-size:10px;color:var(--text-muted)"><?= $s['c'] ?> trades · <?= $s['c']>0?round($s['w']/$s['c']*100,1):0 ?>% WR</span></td>
                    <td class="<?= $snet>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($snet) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Charge breakdown -->
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-receipt" style="color:var(--warning)"></i> Charge Breakdown</div>
    <div class="charge-box">
        <div class="charge-item"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Gross P&amp;L</div><div style="font-family:var(--font-mono);font-size:20px;font-weight:700" class="<?= $grossPL>=0?'pos':'neg' ?>"><?= formatINR_PL($grossPL) ?></div></div>
        <div class="charge-item"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Brokerage</div><div style="font-family:var(--font-mono);font-size:20px;font-weight:700;color:var(--loss)">-<?= formatINR($totalBrok) ?></div></div>
        <div class="charge-item"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Net P&amp;L</div><div style="font-family:var(--font-mono);font-size:24px;font-weight:700" class="<?= $netPL>=0?'pos':'neg' ?>"><?= formatINR_PL($netPL) ?></div></div>
    </div>
</div>

<!-- Full trades table -->
<div class="panel">
    <div class="panel-hdr"><i class="fas fa-table-list"></i> All Trades — <?= date('d M Y',$ts) ?></div>
    <div style="overflow-x:auto">
        <table class="tr-table">
            <thead><tr>
                <th>#</th><th>Open</th><th>Close</th><th>Instrument</th><th>Exch</th>
                <th>Qty</th><th>Buy ₹</th><th>Sell ₹</th>
                <th>Gross P&amp;L</th><th>Brokerage</th><th>Net P&amp;L</th>
            </tr></thead>
            <tbody>
            <?php foreach($trades as $i=>$t): $net=(float)$t['net_pl']; ?>
            <tr>
                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                <td style="font-family:var(--font-mono);font-size:11px;color:var(--text-muted)"><?= $t['open_time']?date('H:i:s',strtotime($t['open_time'])):'—' ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= date('H:i:s',strtotime($t['close_time'])) ?></td>
                <td>
                    <span class="symbol-badge" style="font-size:9px"><?= htmlspecialchars($t['base_instrument']) ?></span>
                    <span style="font-size:10px;color:var(--text-muted);display:block"><?= htmlspecialchars(implode(' ',array_slice(explode(' ',$t['instrument']),1))) ?></span>
                </td>
                <td style="font-size:11px"><?= $t['exchange'] ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= $t['quantity'] ?></td>
                <td style="font-family:var(--font-mono);font-size:11px">₹<?= number_format($t['buy_value'],2) ?></td>
                <td style="font-family:var(--font-mono);font-size:11px">₹<?= number_format($t['sell_value'],2) ?></td>
                <td class="<?= $t['profit_loss']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($t['profit_loss']) ?></td>
                <td style="color:var(--loss);font-size:11px">-<?= formatINR($t['brokerage']) ?></td>
                <td class="<?= $net>=0?'pl-positive':'pl-negative' ?>" style="font-weight:700"><?= formatINR_PL($net) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark=document.documentElement.getAttribute('data-theme')==='dark';
    const gridC=isDark?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.06)';
    const textC=isDark?'#94a3b8':'#64748b';
    const vals=<?= json_encode($chartVals) ?>;
    const labels=<?= json_encode($chartTimes) ?>;
    const color=vals[vals.length-1]>=0?'#22c55e':'#ef4444';
    new Chart(document.getElementById('eqChart'),{type:'line',data:{labels,datasets:[{data:vals,borderColor:color,backgroundColor:color+'18',borderWidth:2,fill:true,pointRadius:vals.length>30?0:4,tension:0.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' Net: ₹'+ctx.parsed.y.toFixed(2)}}},scales:{x:{grid:{color:gridC},ticks:{color:textC,font:{size:10},maxTicksLimit:10}},y:{grid:{color:gridC},ticks:{color:textC,font:{size:10},callback:v=>'₹'+v}}}}});
})();
</script>
<?php include '../includes/footer.php'; ?>

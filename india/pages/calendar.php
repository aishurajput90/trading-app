<?php
require_once '../config/db.php';
$db=$db=getDB(); $userId=INDIA_DEFAULT_USER;

$month=intval($_GET['month']??date('n')); $year=intval($_GET['year']??date('Y'));
if($month<1){$month=12;$year--;} if($month>12){$month=1;$year++;}
$prevMonth=$month-1?:12; $prevYear=$month===1?$year-1:$year;
$nextMonth=$month===12?1:$month+1; $nextYear=$month===12?$year+1:$year;
$firstDay=mktime(0,0,0,$month,1,$year);
$daysInMonth=(int)date('t',$firstDay);
$startDow=(int)date('N',$firstDay);
$today=(int)date('j'); $todayM=(int)date('n'); $todayY=(int)date('Y');

// Daily aggregates
$stmt=$db->prepare("SELECT trade_date,
    COUNT(*) as cnt,
    COALESCE(SUM(profit_loss),0) as gross,
    COALESCE(SUM(brokerage),0) as brok,
    COALESCE(SUM(net_pl),0) as net
    FROM india_trades WHERE user_id=? AND MONTH(trade_date)=? AND YEAR(trade_date)=?
    GROUP BY trade_date ORDER BY trade_date");
$stmt->execute([$userId,$month,$year]);
$rawDays=$stmt->fetchAll();

$dayData=[];
foreach($rawDays as $r){
    $d=(int)date('j',strtotime($r['trade_date']));
    $dayData[$d]=$r;
}

$monthGross=array_sum(array_column($rawDays,'gross'));
$monthBrok=array_sum(array_column($rawDays,'brok'));
$monthNet=array_sum(array_column($rawDays,'net'));
$profitDays=count(array_filter($rawDays,fn($r)=>$r['net']>0));
$lossDays=count(array_filter($rawDays,fn($r)=>$r['net']<0));
$tradingDays=count($rawDays);

// Best day this month
$bestDay=null; $bestNet=null;
foreach($rawDays as $r){ if($bestNet===null||$r['net']>$bestNet){$bestNet=$r['net'];$bestDay=$r;} }

// All-time best day
$atStmt=$db->prepare("SELECT trade_date,COALESCE(SUM(net_pl),0) as net FROM india_trades
    WHERE user_id=? GROUP BY trade_date ORDER BY net DESC LIMIT 1");
$atStmt->execute([$userId]); $allTimeBest=$atStmt->fetch();

$pageTitle='Calendar'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.cal-nav-btn{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);
    padding:8px 14px;color:var(--text-primary);cursor:pointer;text-decoration:none;transition:border-color .15s;}
.cal-nav-btn:hover{border-color:var(--accent);color:var(--accent);}
.cal-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px;}
.cal-stat{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;}
.cal-stat-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:4px;}
.cal-stat-val{font-family:var(--font-mono);font-size:18px;font-weight:700;}
.cal-best-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;}
.cal-best{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;border-left:4px solid var(--profit);}
.cal-wrap{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.cal-hdr{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-hdr-cell{text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:12px 4px;border-bottom:1px solid var(--border);}
.cal-body{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-cell{min-height:90px;padding:8px;border-right:1px solid var(--border-light);border-bottom:1px solid var(--border-light);position:relative;transition:background .15s;}
.cal-cell:nth-child(7n){border-right:none;}
.cal-cell.empty{background:var(--bg-base);}
.cal-cell.profit-day{background:rgba(22,163,74,.07);}
.cal-cell.loss-day{background:rgba(220,38,38,.07);}
.cal-cell.today{outline:2px solid var(--accent);outline-offset:-2px;}
.cal-cell.best-month{background:rgba(22,163,74,.18);border:1px solid rgba(22,163,74,.4);}
.cal-cell.clickable{cursor:pointer;}
.cal-cell.clickable:hover{background:rgba(37,99,235,.08);}
.cal-day-num{font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:4px;}
.cal-cell.today .cal-day-num{color:var(--accent);}
.cal-pl{font-family:var(--font-mono);font-size:11px;font-weight:700;line-height:1.3;}
.cal-brok{font-size:9px;color:var(--loss);}
.cal-net{font-family:var(--font-mono);font-size:11px;line-height:1.3;}
.cal-count{font-size:10px;color:var(--text-muted);margin-top:2px;}
.cal-star{position:absolute;top:4px;right:5px;font-size:10px;}
.pl-positive{color:var(--profit);} .pl-negative{color:var(--loss);}
.pos{color:var(--profit);} .neg{color:var(--loss);} .wrn{color:var(--warning);}
.cal-legend{display:flex;flex-wrap:wrap;gap:16px;padding:12px 16px;font-size:11px;color:var(--text-muted);border-top:1px solid var(--border);}
.cal-leg-item{display:flex;align-items:center;gap:5px;}
.leg-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;}
@media(max-width:600px){.cal-best-row{grid-template-columns:1fr;}}
</style>

<div class="cal-nav">
    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="cal-nav-btn"><i class="fas fa-chevron-left"></i></a>
    <div style="text-align:center">
        <h5 style="font-weight:700;margin:0;font-size:20px"><?= date('F Y',$firstDay) ?></h5>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= $tradingDays ?> trading days</div>
    </div>
    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="cal-nav-btn"><i class="fas fa-chevron-right"></i></a>
</div>

<div class="cal-summary">
    <div class="cal-stat"><div class="cal-stat-lbl">Gross P&amp;L</div><div class="cal-stat-val <?= $monthGross>=0?'pos':'neg' ?>"><?= formatINR_PL($monthGross) ?></div><div style="font-size:11px;color:var(--text-muted)">Before charges</div></div>
    <div class="cal-stat"><div class="cal-stat-lbl">Brokerage</div><div class="cal-stat-val neg">-<?= formatINR($monthBrok) ?></div><div style="font-size:11px;color:var(--text-muted)">Commission</div></div>
    <div class="cal-stat"><div class="cal-stat-lbl">Net P&amp;L</div><div class="cal-stat-val <?= $monthNet>=0?'pos':'neg' ?>" style="font-size:22px"><?= formatINR_PL($monthNet) ?></div><div style="font-size:11px;color:var(--text-muted)">After charges</div></div>
    <div class="cal-stat"><div class="cal-stat-lbl">Profit Days</div><div class="cal-stat-val pos"><?= $profitDays ?></div><div style="font-size:11px;color:var(--text-muted)">of <?= $tradingDays ?></div></div>
    <div class="cal-stat"><div class="cal-stat-lbl">Loss Days</div><div class="cal-stat-val neg"><?= $lossDays ?></div><div style="font-size:11px;color:var(--text-muted)">of <?= $tradingDays ?></div></div>
    <div class="cal-stat"><div class="cal-stat-lbl">Day Win Rate</div><div class="cal-stat-val <?= $tradingDays>0&&$profitDays/$tradingDays>=0.5?'pos':'wrn' ?>"><?= $tradingDays>0?round($profitDays/$tradingDays*100,1):0 ?>%</div></div>
</div>

<div class="cal-best-row">
    <?php if($bestDay): ?>
    <div class="cal-best">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:6px"><i class="fas fa-star" style="color:var(--warning)"></i> Best Day This Month</div>
        <div style="font-size:15px;font-weight:700"><?= date('l, d M Y',strtotime($bestDay['trade_date'])) ?></div>
        <div style="font-family:var(--font-mono);font-size:22px;font-weight:700;color:var(--profit)"><?= formatINR_PL($bestDay['net']) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Gross: <?= formatINR_PL($bestDay['gross']) ?> &nbsp;|&nbsp; Brok: -<?= formatINR($bestDay['brok']) ?> &nbsp;|&nbsp; <?= $bestDay['cnt'] ?> trades</div>
    </div>
    <?php endif; ?>
    <?php if($allTimeBest): ?>
    <div class="cal-best" style="border-left-color:var(--accent-purple)">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:6px"><i class="fas fa-trophy" style="color:var(--accent-purple)"></i> Best Day All Time</div>
        <div style="font-size:15px;font-weight:700"><?= date('l, d M Y',strtotime($allTimeBest['trade_date'])) ?></div>
        <div style="font-family:var(--font-mono);font-size:22px;font-weight:700;color:var(--accent-purple)"><?= formatINR_PL($allTimeBest['net']) ?></div>
    </div>
    <?php endif; ?>
</div>

<div class="cal-wrap">
    <div class="cal-hdr">
        <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
        <div class="cal-hdr-cell"><?= $d ?></div>
        <?php endforeach; ?>
    </div>
    <div class="cal-body">
        <?php for($i=1;$i<$startDow;$i++) echo '<div class="cal-cell empty"></div>'; ?>
        <?php for($day=1;$day<=$daysInMonth;$day++):
            $isToday=($day==$today&&$month==$todayM&&$year==$todayY);
            $has=isset($dayData[$day]);
            $isBest=$bestDay&&$has&&$dayData[$day]['trade_date']===$bestDay['trade_date'];
            $gross=$has?(float)$dayData[$day]['gross']:0;
            $brok=$has?(float)$dayData[$day]['brok']:0;
            $net=$has?(float)$dayData[$day]['net']:0;
            $cnt=$has?(int)$dayData[$day]['cnt']:0;
            $dateStr=sprintf('%04d-%02d-%02d',$year,$month,$day);
            $cls='cal-cell';
            if($isToday) $cls.=' today';
            if($isBest) $cls.=' best-month';
            elseif($has&&$net>0) $cls.=' profit-day';
            elseif($has&&$net<0) $cls.=' loss-day';
            if($has) $cls.=' clickable';
        ?>
        <div class="<?= $cls ?>" <?= $has?"onclick=\"window.location='day.php?date={$dateStr}'\"":'' ?>>
            <div class="cal-day-num"><?= $day ?></div>
            <?php if($isBest): ?><div class="cal-star">⭐</div><?php endif; ?>
            <?php if($has): ?>
            <div class="cal-pl <?= $gross>=0?'pl-positive':'pl-negative' ?>"><?= ($gross>=0?'+':'').'₹'.number_format($gross,2) ?></div>
            <?php if($brok>0): ?><div class="cal-brok">-₹<?= number_format($brok,2) ?> brok</div><?php endif; ?>
            <div class="cal-net <?= $net>=0?'pl-positive':'pl-negative' ?>">net <?= ($net>=0?'+':'').'₹'.number_format($net,2) ?></div>
            <div class="cal-count"><?= $cnt ?> trade<?= $cnt!=1?'s':'' ?></div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
        <?php $lastDow=(int)date('N',mktime(0,0,0,$month,$daysInMonth,$year));
        for($i=$lastDow;$i<7;$i++) echo '<div class="cal-cell empty"></div>'; ?>
    </div>
    <div class="cal-legend">
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(22,163,74,.18);border:1px solid rgba(22,163,74,.4)"></div> Profit day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(220,38,38,.18);border:1px solid rgba(220,38,38,.4)"></div> Loss day</div>
        <div class="cal-leg-item"><div class="leg-dot" style="outline:2px solid var(--accent)"></div> Today</div>
        <div class="cal-leg-item"><div class="leg-dot" style="background:rgba(22,163,74,.3)"></div> ⭐ Best day</div>
        <div class="cal-leg-item" style="margin-left:auto;color:var(--accent)"><i class="fas fa-hand-pointer" style="font-size:11px"></i> Click for details</div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>

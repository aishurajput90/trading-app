<?php
require_once '../config/db.php';
$db=getDB(); $userId=INDIA_DEFAULT_USER;

$from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
if(!$from) $from=date('Y-m-d',strtotime('-90 days'));
if(!$to)   $to=date('Y-m-d');

$hasData=false; $insights=[];
$chartDatesJson=$chartEquityJson='[]';
$durLabels=$durWR=$durAvg=[];
$hourLabels=$hourWRdata=[];
$total=$wins=$losses=0;
$grossProfit=$grossLoss=$totalBrok=$netPLTotal=0.0;
$winRate=$avgWin=$avgLoss=$rrRatio=$brokPct=0.0;
$dur=['under1'=>['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'Under 1 min'],
      '1to5'  =>['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'1 – 5 mins'],
      '5to30' =>['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'5 – 30 mins'],
      'over30'=>['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'Over 30 mins']];
$symbolMap=$dailyPL=[]; $hourlyWR=array_fill(0,24,['w'=>0,'l'=>0]);

$stmt=$db->prepare("SELECT trade_date,open_time,close_time,base_instrument,profit_loss,brokerage,net_pl,quantity FROM india_trades WHERE user_id=? AND trade_date BETWEEN ? AND ? ORDER BY close_time ASC");
$stmt->execute([$userId,$from,$to]);
$trades=$stmt->fetchAll();

if(!empty($trades)){
    $hasData=true; $total=count($trades);
    foreach($trades as $t){
        $pl=(float)$t['profit_loss']; $brok=(float)$t['brokerage'];
        $sym=strtoupper($t['base_instrument']);
        $closeTs=strtotime($t['close_time']); $openTs=$t['open_time']?strtotime($t['open_time']):null;
        $durSec=($openTs!==null)?max(0,$closeTs-$openTs):null;
        $date=date('Y-m-d',$closeTs); $hour=(int)date('G',$closeTs);

        $netPLTotal+=(float)$t['net_pl']; $totalBrok+=$brok;
        if($pl>0){$wins++;$grossProfit+=$pl;}else{$losses++;$grossLoss+=$pl;}

        if($durSec!==null){
            $key=$durSec<60?'under1':($durSec<300?'1to5':($durSec<1800?'5to30':'over30'));
            $dur[$key]['count']++; $dur[$key]['pl']+=$pl;
            if($pl>0) $dur[$key]['wins']++;
        }
        if(!isset($symbolMap[$sym])) $symbolMap[$sym]=['c'=>0,'w'=>0,'pl'=>0.0,'b'=>0.0];
        $symbolMap[$sym]['c']++; $symbolMap[$sym]['pl']+=$pl; $symbolMap[$sym]['b']+=$brok;
        if($pl>0) $symbolMap[$sym]['w']++;
        if(!isset($dailyPL[$date])) $dailyPL[$date]=0.0;
        $dailyPL[$date]+=(float)$t['net_pl'];
        if($pl>0) $hourlyWR[$hour]['w']++; else $hourlyWR[$hour]['l']++;
    }
    $winRate=$total>0?round($wins/$total*100,1):0;
    $brokPct=$grossProfit>0?round($totalBrok/$grossProfit*100,1):0;
    $avgWin=$wins>0?round($grossProfit/$wins,2):0;
    $avgLoss=$losses>0?round($grossLoss/$losses,2):0;
    $rrRatio=$avgLoss!=0?round($avgWin/abs($avgLoss),2):0;
    foreach($dur as $k=>&$d){ $d['avgPL']=$d['count']>0?round($d['pl']/$d['count'],2):0; $d['wr']=$d['count']>0?round($d['wins']/$d['count']*100,1):0; } unset($d);
    uasort($symbolMap,fn($a,$b)=>$b['c']-$a['c']);
    ksort($dailyPL); $dates=[]; $equity=[]; $running=0.0;
    foreach($dailyPL as $dt=>$pl){ $running+=$pl; $dates[]=date('d M',strtotime($dt)); $equity[]=round($running,2); }
    $chartDatesJson=json_encode($dates); $chartEquityJson=json_encode($equity);
    foreach($dur as $d){ if($d['count']>0){$durLabels[]=$d['label'];$durWR[]=$d['wr'];$durAvg[]=$d['avgPL'];} }
    for($h=0;$h<24;$h++){ $w=$hourlyWR[$h]['w'];$l=$hourlyWR[$h]['l']; if($w+$l>=3){$hourLabels[]=sprintf('%02d:00',$h);$hourWRdata[]=round($w/($w+$l)*100,1);} }

    // Insights (same logic adapted for ₹)
    if($dur['under1']['count']>5&&$dur['under1']['wr']<45){
        $pct=round($dur['under1']['count']/$total*100);
        $insights[]=['rank'=>1,'color'=>'red','title'=>'Bahut jaldi trade karte ho — impulsive entry','body'=>"{$dur['under1']['count']} trades ({$pct}%) ek minute se kam mein close hue. Sirf {$dur['under1']['wr']}% win rate mila. Confirmation ka wait nahi karte.",'pills'=>[['l'=>$dur['under1']['count'].' trades <1 min','c'=>'red'],['l'=>"WR: {$dur['under1']['wr']}%",'c'=>'red'],['l'=>'Avg: ₹'.$dur['under1']['avgPL'],'c'=>'red']],'fix'=>'Entry se pehle 3 candles confirm hone do.'];
    }
    if($brokPct>15){
        $insights[]=['rank'=>2,'color'=>'amber','title'=>"Brokerage aapka {$brokPct}% gross profit kha raha hai",'body'=>"Gross P&L ".($grossProfit-abs($grossLoss)>=0?'+':'').'₹'.number_format($grossProfit+$grossLoss,2)." tha. Lekin ₹".number_format($totalBrok,2)." brokerage ne net return ko reduce kar diya.",'pills'=>[['l'=>'Gross: ₹'.number_format($grossProfit+$grossLoss,2),'c'=>($grossProfit+$grossLoss>=0?'green':'red')],['l'=>'Brok: ₹'.number_format($totalBrok,2),'c'=>'red'],['l'=>"Brok={$brokPct}%",'c'=>'red']],'fix'=>'Sirf high-probability setups trade karo.'];
    }
    if($winRate<40){
        $insights[]=['rank'=>3,'color'=>'red','title'=>"Win rate sirf {$winRate}% — confirmation ke bina entry",'body'=>"R:R ratio {$rrRatio} (avg win ₹{$avgWin}, avg loss ₹".abs($avgLoss)."). Lekin win rate {$winRate}% bahut low hai.",'pills'=>[['l'=>"WR: {$winRate}%",'c'=>'red'],['l'=>"Avg win: ₹{$avgWin}",'c'=>'green'],['l'=>"R:R: {$rrRatio}",'c'=>'amber']],'fix'=>'Minimum 2 confirmations lo entry se pehle.'];
    }
    if($dur['5to30']['count']>=5&&$dur['5to30']['wr']>$dur['under1']['wr']+15){
        $insights[]=['rank'=>4,'color'=>'blue','title'=>'Jitna zyada hold karo — utna behtar result','body'=>"Under 1 min: {$dur['under1']['wr']}% WR (avg ₹{$dur['under1']['avgPL']}). 5–30 mins: {$dur['5to30']['wr']}% WR (avg ₹{$dur['5to30']['avgPL']}).",'pills'=>[['l'=>'<1 min avg: ₹'.$dur['under1']['avgPL'],'c'=>'red'],['l'=>'5-30 min avg: ₹'.$dur['5to30']['avgPL'],'c'=>'green']],'fix'=>'Trailing stop use karo — manually mat nikalo.'];
    }
}

function pillClass($c){ return match($c){'red'=>'background:#FCEBEB;color:#A32D2D','green'=>'background:#EAF3DE;color:#3B6D11','amber'=>'background:#FAEEDA;color:#854F0B','blue'=>'background:#E6F1FB;color:#185FA5',default=>'background:#F1EFE8;color:#5F5E5A'}; }
$durLabelsJson=json_encode($durLabels);$durWRJson=json_encode($durWR);$durAvgJson=json_encode($durAvg);
$hourLabelsJson=json_encode($hourLabels);$hourWRJson=json_encode($hourWRdata);

$pageTitle='Trade Analyzer'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.az-wrap{max-width:1200px;}
.az-date-bar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:24px;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.az-date-bar label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);display:block;margin-bottom:6px;}
.az-date-bar input[type=date]{background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:8px 12px;font-size:13px;font-family:var(--font-body);outline:none;}
.az-date-bar input[type=date]:focus{border-color:var(--accent);}
.az-btn-go{background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;}
.az-shortcut{font-size:11px;padding:5px 10px;border:1px solid var(--border);border-radius:20px;color:var(--text-muted);cursor:pointer;background:var(--bg-elevated);white-space:nowrap;text-decoration:none;display:inline-block;}
.az-shortcut:hover{border-color:var(--accent);color:var(--accent);}
.az-kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:24px;}
.az-kpi{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;}
.az-kpi-lbl{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;}
.az-kpi-val{font-family:var(--font-mono);font-size:20px;font-weight:700;line-height:1.1;}
.az-kpi-sub{font-size:11px;color:var(--text-muted);margin-top:3px;}
.az-kpi-val.pos{color:var(--profit);} .az-kpi-val.neg{color:var(--loss);} .az-kpi-val.warn{color:var(--warning);}
.az-section-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin:28px 0 12px;display:flex;align-items:center;gap:8px;}
.az-section-lbl span{flex:1;height:1px;background:var(--border);}
.az-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.az-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.az-card-title{font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.az-mistake{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:12px;border-left:4px solid transparent;}
.az-mistake.red{border-left-color:var(--loss);} .az-mistake.amber{border-left-color:var(--warning);} .az-mistake.blue{border-left-color:var(--accent);}
.az-m-header{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.az-m-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.az-m-num.red{background:rgba(220,38,38,.12);color:var(--loss);} .az-m-num.amber{background:rgba(217,119,6,.12);color:var(--warning);} .az-m-num.blue{background:rgba(37,99,235,.12);color:var(--accent);}
.az-m-title{font-size:14px;font-weight:600;color:var(--text-primary);}
.az-m-body{font-size:13px;color:var(--text-secondary);line-height:1.65;margin-bottom:10px;}
.az-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
.az-pill{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;}
.az-fix{font-size:12px;background:var(--bg-elevated);border-radius:var(--radius-sm);padding:8px 12px;color:var(--text-secondary);border-left:3px solid var(--profit);}
.az-fix strong{color:var(--profit);}
.az-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.az-bar-lbl{font-size:11px;color:var(--text-muted);width:100px;flex-shrink:0;text-align:right;}
.az-bar-track{flex:1;height:7px;background:var(--bg-elevated);border-radius:4px;overflow:hidden;}
.az-bar-fill{height:100%;border-radius:4px;}
.az-bar-val{font-size:11px;font-weight:700;width:60px;flex-shrink:0;}
.az-bar-val.pos{color:var(--profit);} .az-bar-val.neg{color:var(--loss);} .az-bar-val.warn{color:var(--warning);}
.az-sym-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border-light);font-size:12px;}
.az-sym-row:last-child{border-bottom:none;}
@media(max-width:768px){.az-grid2{grid-template-columns:1fr;}}
</style>

<div class="az-wrap">
<form method="GET" class="az-date-bar">
    <div><label>From Date</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div><label>To Date</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <button type="submit" class="az-btn-go"><i class="fas fa-chart-line"></i> Analyze</button>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;padding-bottom:2px">
        <a class="az-shortcut" href="?from=<?= date('Y-m-d',strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>">Last 7 days</a>
        <a class="az-shortcut" href="?from=<?= date('Y-m-d',strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>">Last 30 days</a>
        <a class="az-shortcut" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">This Month</a>
        <a class="az-shortcut" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">This Year</a>
    </div>
</form>

<?php if(!$hasData): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
    <i class="fas fa-chart-bar" style="font-size:36px;margin-bottom:12px;display:block"></i>
    <strong>Koi trades nahi mile</strong><br><?= date('d M Y',strtotime($from)) ?> se <?= date('d M Y',strtotime($to)) ?> ke beech.
</div>
<?php else: ?>

<div class="az-section-lbl"><?= date('d M Y',strtotime($from)) ?> — <?= date('d M Y',strtotime($to)) ?> <span></span></div>
<div class="az-kpi-row">
    <div class="az-kpi"><div class="az-kpi-lbl">Total Trades</div><div class="az-kpi-val"><?= $total ?></div><div class="az-kpi-sub"><?= $wins ?>W / <?= $losses ?>L</div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Win Rate</div><div class="az-kpi-val <?= $winRate>=50?'pos':($winRate>=40?'warn':'neg') ?>"><?= $winRate ?>%</div><div class="az-kpi-sub">R:R <?= $rrRatio ?></div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Gross P&amp;L</div><div class="az-kpi-val <?= ($grossProfit+$grossLoss)>=0?'pos':'neg' ?>">₹<?= number_format($grossProfit+$grossLoss,2) ?></div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Brokerage</div><div class="az-kpi-val neg">-₹<?= number_format($totalBrok,2) ?></div><div class="az-kpi-sub"><?= $brokPct ?>% of gross</div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Net P&amp;L</div><div class="az-kpi-val <?= $netPLTotal>=0?'pos':'neg' ?>"><?= ($netPLTotal>=0?'+':'') ?>₹<?= number_format($netPLTotal,2) ?></div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Avg Win</div><div class="az-kpi-val pos">+₹<?= $avgWin ?></div></div>
    <div class="az-kpi"><div class="az-kpi-lbl">Avg Loss</div><div class="az-kpi-val neg">₹<?= abs($avgLoss) ?></div></div>
</div>

<div class="az-grid2">
    <div class="az-card"><div class="az-card-title"><i class="fas fa-chart-area" style="color:var(--accent)"></i> Equity Curve (Net ₹)</div><div style="position:relative;height:200px"><canvas id="eqChart" role="img" aria-label="Equity curve"></canvas></div></div>
    <div class="az-card"><div class="az-card-title"><i class="fas fa-clock" style="color:var(--accent-cyan)"></i> Win Rate by Duration</div><div style="position:relative;height:200px"><canvas id="durChart" role="img" aria-label="Duration chart"></canvas></div></div>
</div>

<?php if(!empty($hourLabels)): ?>
<div class="az-grid2" style="margin-bottom:16px">
    <div class="az-card"><div class="az-card-title"><i class="fas fa-sun" style="color:var(--warning)"></i> Win Rate by Hour (IST)</div><div style="position:relative;height:180px"><canvas id="hourChart" role="img" aria-label="Hourly chart"></canvas></div></div>
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-coins" style="color:var(--warning)"></i> Top Instruments</div>
        <?php foreach(array_slice($symbolMap,0,5) as $sym=>$s):
            $snet=round($s['pl']-$s['b'],2); $swr=$s['c']>0?round($s['w']/$s['c']*100,1):0;
        ?>
        <div class="az-sym-row">
            <span><span class="symbol-badge"><?= htmlspecialchars($sym) ?></span> <span style="font-size:11px;color:var(--text-muted);margin-left:4px"><?= $s['c'] ?> trades · <?= $swr ?>%</span></span>
            <span style="font-weight:700;color:<?= $snet>=0?'var(--profit)':'var(--loss)' ?>"><?= ($snet>=0?'+':'').'₹'.number_format($snet,2) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="az-section-lbl"><i class="fas fa-triangle-exclamation" style="color:var(--loss)"></i> Galtiyan — <?= count($insights) ?> issues <span></span></div>
<?php if(empty($insights)): ?>
<div class="az-card" style="text-align:center;padding:30px"><i class="fas fa-medal" style="color:var(--profit);font-size:28px;display:block;margin-bottom:8px"></i><strong style="color:var(--profit)">Koi badi galti nahi mili!</strong></div>
<?php else: ?>
<?php foreach($insights as $ins): ?>
<div class="az-mistake <?= $ins['color'] ?>">
    <div class="az-m-header"><div class="az-m-num <?= $ins['color'] ?>"><?= $ins['rank'] ?></div><div class="az-m-title"><?= htmlspecialchars($ins['title']) ?></div></div>
    <div class="az-m-body"><?= htmlspecialchars($ins['body']) ?></div>
    <div class="az-pills"><?php foreach($ins['pills'] as $p): ?><span class="az-pill" style="<?= pillClass($p['c']) ?>"><?= htmlspecialchars($p['l']) ?></span><?php endforeach; ?></div>
    <div class="az-fix"><strong>Fix:</strong> <?= htmlspecialchars($ins['fix']) ?></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="az-section-lbl"><i class="fas fa-chart-bar" style="color:var(--accent)"></i> Duration Analysis <span></span></div>
<div class="az-grid2">
    <div class="az-card"><div class="az-card-title">Trade Duration — Win Rate</div>
        <?php foreach($dur as $k=>$d): if($d['count']==0) continue; $wr=$d['wr']; $bc=$wr>=55?'var(--profit)':($wr>=40?'var(--warning)':'var(--loss)'); ?>
        <div class="az-bar-row"><div class="az-bar-lbl"><?= $d['label'] ?></div><div class="az-bar-track"><div class="az-bar-fill" style="width:<?= $wr ?>%;background:<?= $bc ?>"></div></div><div class="az-bar-val <?= $wr>=50?'pos':($wr>=40?'warn':'neg') ?>"><?= $wr ?>%</div></div>
        <div style="padding-left:110px;margin-top:-4px;margin-bottom:10px;font-size:11px;color:var(--text-muted)"><?= $d['count'] ?> trades | avg ₹<?= $d['avgPL'] ?></div>
        <?php endforeach; ?>
    </div>
    <div class="az-card"><div class="az-card-title">Instrument Performance</div>
        <?php foreach(array_slice($symbolMap,0,6) as $sym=>$s):
            $snet=round($s['pl']-$s['b'],2); $swr=$s['c']>0?round($s['w']/$s['c']*100,1):0;
        ?>
        <div class="az-sym-row">
            <span><span class="symbol-badge"><?= htmlspecialchars($sym) ?></span> <span style="font-size:10px;color:var(--text-muted)"><?= $s['c'] ?> · <?= $swr ?>%</span></span>
            <span style="font-weight:700;color:<?= $snet>=0?'var(--profit)':'var(--loss)' ?>"><?= ($snet>=0?'+':'').'₹'.number_format($snet,2) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark=document.documentElement.getAttribute('data-theme')==='dark';
    const gridC=isDark?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.06)';
    const textC=isDark?'#94a3b8':'#64748b';
    const baseOpts={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:gridC},ticks:{color:textC,font:{size:10}}},y:{grid:{color:gridC},ticks:{color:textC,font:{size:10}}}}};
    const eqDates=<?= $chartDatesJson ?>,eqVals=<?= $chartEquityJson ?>;
    if(eqDates.length>0){const ec=document.getElementById('eqChart'),col=eqVals[eqVals.length-1]>=0?'#22c55e':'#ef4444';new Chart(ec,{type:'line',data:{labels:eqDates,datasets:[{data:eqVals,borderColor:col,backgroundColor:col+'18',borderWidth:2,fill:true,pointRadius:eqDates.length>40?0:3,tension:0.35}]},options:{...baseOpts,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' ₹'+ctx.parsed.y.toFixed(2)}}},scales:{x:{...baseOpts.scales.x,ticks:{...baseOpts.scales.x.ticks,maxTicksLimit:8,maxRotation:30}},y:{...baseOpts.scales.y,ticks:{...baseOpts.scales.y.ticks,callback:v=>'₹'+v}}}}});}
    const durL=<?= $durLabelsJson ?>,durW=<?= $durWRJson ?>,durA=<?= $durAvgJson ?>;
    if(durL.length>0){new Chart(document.getElementById('durChart'),{type:'bar',data:{labels:durL,datasets:[{data:durW,backgroundColor:durW.map(v=>v>=55?'#22c55e':v>=40?'#f59e0b':'#ef4444'),borderRadius:5}]},options:{...baseOpts,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' WR: '+ctx.parsed.y+'%',afterLabel:ctx=>' Avg: ₹'+durA[ctx.dataIndex]}}},scales:{x:{...baseOpts.scales.x},y:{...baseOpts.scales.y,min:0,max:100,ticks:{...baseOpts.scales.y.ticks,callback:v=>v+'%'}}}}});}
    const hourL=<?= $hourLabelsJson ?>,hourW=<?= $hourWRJson ?>;
    if(hourL.length>0&&document.getElementById('hourChart')){new Chart(document.getElementById('hourChart'),{type:'bar',data:{labels:hourL,datasets:[{data:hourW,backgroundColor:hourW.map(v=>v>=55?'#22c55e':v>=40?'#f59e0b':'#ef4444'),borderRadius:3}]},options:{...baseOpts,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' WR: '+ctx.parsed.y+'%'}}},scales:{x:{...baseOpts.scales.x,ticks:{...baseOpts.scales.x.ticks,maxRotation:45,font:{size:9}}},y:{...baseOpts.scales.y,min:0,max:100,ticks:{...baseOpts.scales.y.ticks,callback:v=>v+'%'}}}}});}
})();
</script>
<?php include '../includes/footer.php'; ?>

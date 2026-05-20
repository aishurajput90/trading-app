<?php
require_once '../config/db.php';
$db=getDB(); $uid=DEMO_USER_ID; $acid=getDemoAccountId(); $acc=getDemoAccount();

$from=$_GET['from']??date('Y-m-d',strtotime('-30 days'));
$to=$_GET['to']??date('Y-m-d');

// All closed trades in period
$stmt=$db->prepare("SELECT * FROM demo_trades WHERE account_id=? AND status='closed' AND DATE(close_time) BETWEEN ? AND ? ORDER BY close_time");
$stmt->execute([$acid,$from,$to]); $trades=$stmt->fetchAll();

$total=count($trades); $wins=0; $losses=0;
$grossPL=0; $totalComm=0; $netPL=0;
$maxConsecWin=0; $maxConsecLoss=0; $curWin=0; $curLoss=0;
$byEmotion=[]; $bySetup=[]; $byStrategy=[]; $byTF=[]; $byHour=array_fill(0,24,['w'=>0,'l'=>0,'pl'=>0]);
$dailyPL=[]; $bestStreak=0; $worstStreak=0;

foreach($trades as $t){
    $pl=(float)$t['profit_loss']; $net=(float)$t['net_pl']; $comm=(float)$t['commission'];
    $grossPL+=$pl; $totalComm+=$comm; $netPL+=$net;
    $hour=(int)date('G',strtotime($t['close_time']));
    $date=date('Y-m-d',strtotime($t['close_time']));
    if(!isset($dailyPL[$date])) $dailyPL[$date]=0; $dailyPL[$date]+=$net;
    $byHour[$hour]['pl']+=$net;

    if($pl>0){ $wins++; $curWin++; $curLoss=0; $maxConsecWin=max($maxConsecWin,$curWin); $byHour[$hour]['w']++; }
    else{ $losses++; $curLoss++; $curWin=0; $maxConsecLoss=max($maxConsecLoss,$curLoss); $byHour[$hour]['l']++; }

    $em=strtolower($t['emotion']??'unknown');
    if(!isset($byEmotion[$em])) $byEmotion[$em]=['c'=>0,'w'=>0,'pl'=>0];
    $byEmotion[$em]['c']++; $byEmotion[$em]['pl']+=$net;
    if($pl>0) $byEmotion[$em]['w']++;

    $su=trim($t['setup']??'Unknown'); if(!$su)$su='Unknown';
    if(!isset($bySetup[$su])) $bySetup[$su]=['c'=>0,'w'=>0,'pl'=>0];
    $bySetup[$su]['c']++; $bySetup[$su]['pl']+=$net; if($pl>0)$bySetup[$su]['w']++;

    $st=trim($t['strategy']??'Untagged'); if(!$st)$st='Untagged';
    if(!isset($byStrategy[$st])) $byStrategy[$st]=['c'=>0,'w'=>0,'pl'=>0];
    $byStrategy[$st]['c']++; $byStrategy[$st]['pl']+=$net; if($pl>0)$byStrategy[$st]['w']++;

    $tf=$t['timeframe']??'—'; if(!$tf)$tf='—';
    if(!isset($byTF[$tf])) $byTF[$tf]=['c'=>0,'w'=>0,'pl'=>0];
    $byTF[$tf]['c']++; $byTF[$tf]['pl']+=$net; if($pl>0)$byTF[$tf]['w']++;
}

$winRate=$total>0?round($wins/$total*100,1):0;
$avgWin=$wins>0?round(array_sum(array_map(fn($t)=>$t['profit_loss']>0?(float)$t['net_pl']:0,$trades))/$wins,2):0;
$avgLoss=$losses>0?round(array_sum(array_map(fn($t)=>$t['profit_loss']<0?(float)$t['net_pl']:0,$trades))/$losses,2):0;
$rr=$avgLoss!=0?round($avgWin/abs($avgLoss),2):0;
$profitFactor=array_sum(array_map(fn($t)=>$t['profit_loss']>0?(float)$t['net_pl']:0,$trades));
$lossFactor=abs(array_sum(array_map(fn($t)=>$t['profit_loss']<0?(float)$t['net_pl']:0,$trades)));
$pf=$lossFactor>0?round($profitFactor/$lossFactor,2):0;

// Equity curve
ksort($dailyPL); $eqDates=[]; $eqVals=[]; $run=0;
foreach($dailyPL as $dt=>$pl){ $run+=$pl; $eqDates[]=date('d M',strtotime($dt)); $eqVals[]=round($run,2); }

// Rule compliance
$rcStmt=$db->prepare("SELECT rc.followed,COUNT(*) as cnt FROM demo_rule_checks rc JOIN demo_trades dt ON rc.trade_id=dt.id WHERE dt.account_id=? AND DATE(dt.close_time) BETWEEN ? AND ? GROUP BY rc.followed");
$rcStmt->execute([$acid,$from,$to]); $rcData=$rcStmt->fetchAll();
$rcFollowed=$rcBroken=0;
foreach($rcData as $r){ if($r['followed'])$rcFollowed+=$r['cnt']; else $rcBroken+=$r['cnt']; }
$rcTotal=$rcFollowed+$rcBroken; $rcPct=$rcTotal>0?round($rcFollowed/$rcTotal*100):0;

// Compare to previous period for improvement
$days=max(1,(strtotime($to)-strtotime($from))/86400);
$prevFrom=date('Y-m-d',strtotime($from)-$days*86400);
$prevStmt=$db->prepare("SELECT COUNT(*) as t, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as w, COALESCE(SUM(net_pl),0) as net FROM demo_trades WHERE account_id=? AND status='closed' AND DATE(close_time) BETWEEN ? AND ?");
$prevStmt->execute([$acid,$prevFrom,$from]); $prev=$prevStmt->fetch();
$prevWR=$prev['t']>0?round($prev['w']/$prev['t']*100,1):0;
$wrImprove=round($winRate-$prevWR,1);
$plImprove=round($netPL-$prev['net'],2);

$pageTitle='Performance Review'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.rv-date-bar{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:20px;}
.rv-date-bar label{font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);display:block;margin-bottom:4px;}
.fc{background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:8px 12px;font-size:13px;outline:none;}
.rv-btn{background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 20px;font-size:13px;cursor:pointer;font-weight:600;}
.sc{font-size:11px;padding:5px 10px;border:1px solid var(--border);border-radius:20px;color:var(--text-muted);cursor:pointer;background:var(--bg-elevated);text-decoration:none;white-space:nowrap;display:inline-block;}
.sc:hover{border-color:var(--accent);color:var(--accent);}

.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.kpi{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;}
.kpi::before{content:'';display:block;height:3px;border-radius:2px;margin-bottom:10px;}
.kpi.g::before{background:var(--profit);} .kpi.r::before{background:var(--loss);} .kpi.b::before{background:var(--accent);} .kpi.p::before{background:var(--accent-purple);} .kpi.c::before{background:var(--accent-cyan);}
.kpi-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:6px;}
.kpi-val{font-family:var(--font-mono);font-size:20px;font-weight:700;line-height:1;}
.kpi-sub{font-size:11px;color:var(--text-muted);margin-top:4px;}
.kpi-improve{font-size:11px;margin-top:4px;}
.pos{color:var(--profit);} .neg{color:var(--loss);} .acc{color:var(--accent);}

.dp{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;}
.dp-hdr{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;color:var(--text-secondary);display:flex;align-items:center;gap:8px;}
.dp-body{padding:16px 18px;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
@media(max-width:900px){.g2,.g3{grid-template-columns:1fr;}}

.st-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);font-size:12px;}
.st-row:last-child{border-bottom:none;}
.bar-wrap{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.bar-lbl{font-size:11px;color:var(--text-muted);width:80px;flex-shrink:0;text-align:right;}
.bar-track{flex:1;height:7px;background:var(--bg-elevated);border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;}
.bar-val{font-size:11px;font-weight:700;width:50px;flex-shrink:0;}

.insight-card{background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:8px;border-left:4px solid transparent;}
.insight-card.good{border-left-color:var(--profit);}
.insight-card.bad{border-left-color:var(--loss);}
.insight-card.info{border-left-color:var(--accent);}
</style>

<!-- Date Range -->
<form method="GET" class="rv-date-bar">
    <div><label>From</label><input type="date" class="fc" name="from" value="<?= $from ?>"></div>
    <div><label>To</label><input type="date" class="fc" name="to" value="<?= $to ?>"></div>
    <button type="submit" class="rv-btn"><i class="fas fa-chart-line"></i> Analyze</button>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
        <a class="sc" href="?from=<?= date('Y-m-d',strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>">7 days</a>
        <a class="sc" href="?from=<?= date('Y-m-d',strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>">30 days</a>
        <a class="sc" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">This Month</a>
        <a class="sc" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">This Year</a>
        <a class="sc" href="?from=2020-01-01&to=<?= date('Y-m-d') ?>">All Time</a>
    </div>
</form>

<?php if($total === 0): ?>
<div style="text-align:center;padding:60px;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-muted)">
    <i class="fas fa-chart-bar" style="font-size:36px;display:block;margin-bottom:12px"></i>
    No closed trades in this period.
</div>
<?php else: ?>

<!-- KPI Grid -->
<div class="kpi-grid">
    <div class="kpi <?= $netPL>=0?'g':'r' ?>"><div class="kpi-lbl">Net P&L</div><div class="kpi-val <?= $netPL>=0?'pos':'neg' ?>"><?= fmtDPL($netPL) ?></div>
        <div class="kpi-improve" style="color:<?= $plImprove>=0?'var(--profit)':'var(--loss)' ?>"><?= $plImprove>=0?'▲':'▼' ?> <?= fmtDPL(abs($plImprove)) ?> vs prev.</div></div>
    <div class="kpi <?= $winRate>=50?'g':'r' ?>"><div class="kpi-lbl">Win Rate</div><div class="kpi-val <?= $winRate>=50?'pos':'neg' ?>"><?= $winRate ?>%</div>
        <div class="kpi-improve" style="color:<?= $wrImprove>=0?'var(--profit)':'var(--loss)' ?>"><?= $wrImprove>=0?'▲':'▼' ?> <?= abs($wrImprove) ?>% vs prev.</div></div>
    <div class="kpi b"><div class="kpi-lbl">Total Trades</div><div class="kpi-val acc"><?= $total ?></div><div class="kpi-sub"><?= $wins ?>W / <?= $losses ?>L</div></div>
    <div class="kpi p"><div class="kpi-lbl">Profit Factor</div><div class="kpi-val <?= $pf>=1?'pos':'neg' ?>"><?= $pf ?></div><div class="kpi-sub">&gt;1.5 is good</div></div>
    <div class="kpi g"><div class="kpi-lbl">Avg Win</div><div class="kpi-val pos"><?= fmtDPL($avgWin) ?></div></div>
    <div class="kpi r"><div class="kpi-lbl">Avg Loss</div><div class="kpi-val neg"><?= fmtDPL($avgLoss) ?></div></div>
    <div class="kpi b"><div class="kpi-lbl">R:R Ratio</div><div class="kpi-val <?= $rr>=1.5?'pos':'neg' ?>"><?= $rr ?></div><div class="kpi-sub">Target: 2+</div></div>
    <div class="kpi c"><div class="kpi-lbl">Rule Compliance</div><div class="kpi-val acc"><?= $rcPct ?>%</div><div class="kpi-sub"><?= $rcFollowed ?> of <?= $rcTotal ?></div></div>
    <div class="kpi g"><div class="kpi-lbl">Best Streak</div><div class="kpi-val pos"><?= $maxConsecWin ?>W</div></div>
    <div class="kpi r"><div class="kpi-lbl">Worst Streak</div><div class="kpi-val neg"><?= $maxConsecLoss ?>L</div></div>
</div>

<!-- Auto-generated Insights -->
<div class="dp">
    <div class="dp-hdr"><i class="fas fa-brain" style="color:var(--accent-purple)"></i> AI Insights — What to Improve</div>
    <div class="dp-body">
        <?php
        // Best emotion
        $bestEmo=null; $bestEmoNet=-PHP_INT_MAX;
        foreach($byEmotion as $e=>$d){ if($d['c']>=2&&$d['pl']/$d['c']>$bestEmoNet){$bestEmoNet=$d['pl']/$d['c'];$bestEmo=$e;} }
        $worstEmo=null; $worstEmoNet=PHP_INT_MAX;
        foreach($byEmotion as $e=>$d){ if($d['c']>=2&&$d['pl']/$d['c']<$worstEmoNet){$worstEmoNet=$d['pl']/$d['c'];$worstEmo=$e;} }
        if($bestEmo): ?>
        <div class="insight-card good"><i class="fas fa-face-smile" style="color:var(--profit)"></i> <strong>Best Mental State:</strong> When feeling <strong><?= ucfirst($bestEmo) ?></strong>, avg trade: <?= fmtDPL($bestEmoNet) ?>. Trade more in this state.</div>
        <?php endif; if($worstEmo && $worstEmo!==$bestEmo): ?>
        <div class="insight-card bad"><i class="fas fa-face-tired" style="color:var(--loss)"></i> <strong>Worst Mental State:</strong> When feeling <strong><?= ucfirst($worstEmo) ?></strong>, avg trade: <?= fmtDPL($worstEmoNet) ?>. Avoid trading in this state.</div>
        <?php endif;
        if($winRate<45): ?>
        <div class="insight-card bad"><i class="fas fa-triangle-exclamation" style="color:var(--loss)"></i> <strong>Win rate too low (<?= $winRate ?>%).</strong> Focus on quality setups — wait for 2+ confirmations before entering.</div>
        <?php endif;
        if($rr<1.5): ?>
        <div class="insight-card bad"><i class="fas fa-scale-unbalanced" style="color:var(--warning)"></i> <strong>R:R ratio (<?= $rr ?>) is below 1:2 target.</strong> Set your TP at least 2x further than your SL, or tighten your SL.</div>
        <?php endif;
        if($rcPct<70 && $rcTotal>5): ?>
        <div class="insight-card bad"><i class="fas fa-list-check" style="color:var(--loss)"></i> <strong>Low rule compliance (<?= $rcPct ?>%).</strong> You broke trading rules <?= $rcBroken ?> times. Discipline builds long-term profitability.</div>
        <?php endif;
        // Best strategy
        $bestStrat=null; $bestStratPL=-PHP_INT_MAX;
        foreach($byStrategy as $s=>$d){ if($d['c']>=2&&$d['pl']>$bestStratPL){$bestStratPL=$d['pl'];$bestStrat=$s;} }
        if($bestStrat && $bestStrat!=='Untagged'): ?>
        <div class="insight-card good"><i class="fas fa-chess" style="color:var(--profit)"></i> <strong>Best strategy: <?= htmlspecialchars($bestStrat) ?></strong> — net <?= fmtDPL($bestStratPL) ?> with <?= $byStrategy[$bestStrat]['c'] ?> trades. Focus more on this.</div>
        <?php endif;
        if($winRate>=55&&$rr>=2): ?>
        <div class="insight-card good"><i class="fas fa-trophy" style="color:var(--warning)"></i> <strong>Excellent metrics!</strong> Win rate <?= $winRate ?>% + R:R <?= $rr ?> = strong edge. Now increase lot size gradually.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Charts Row -->
<div class="g2">
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-chart-area" style="color:var(--accent)"></i> Equity Curve</div>
        <div class="dp-body"><div style="position:relative;height:220px"><canvas id="eqChart" role="img" aria-label="Equity curve"></canvas></div></div>
    </div>
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-face-smile" style="color:var(--accent-purple)"></i> P&L by Emotion</div>
        <div class="dp-body">
            <?php uasort($byEmotion,fn($a,$b)=>$b['pl']-$a['pl']);
            $emColors=['calm'=>'#22c55e','confident'=>'#3b82f6','neutral'=>'#94a3b8','anxious'=>'#f59e0b','fomo'=>'#ef4444','greedy'=>'#ef4444','revenge'=>'#dc2626','excited'=>'#8b5cf6','unknown'=>'#94a3b8'];
            foreach($byEmotion as $em=>$d): $wr=$d['c']>0?round($d['w']/$d['c']*100):0; $col=$emColors[$em]??'#94a3b8'; ?>
            <div class="st-row">
                <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $col ?>;margin-right:6px"></span><?= ucfirst($em) ?> (<?= $d['c'] ?> trades)</span>
                <span style="font-weight:700;color:<?= $d['pl']>=0?'var(--profit)':'var(--loss)' ?>"><?= fmtDPL($d['pl']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="g3">
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-chess" style="color:var(--accent-cyan)"></i> By Strategy</div>
        <div class="dp-body">
            <?php uasort($byStrategy,fn($a,$b)=>$b['pl']-$a['pl']);
            foreach($byStrategy as $st=>$d): $wr=$d['c']>0?round($d['w']/$d['c']*100):0; ?>
            <div class="bar-wrap">
                <div class="bar-lbl"><?= htmlspecialchars(mb_strimwidth($st,0,10,'…')) ?></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $wr ?>%;background:<?= $wr>=50?'var(--profit)':'var(--loss)' ?>"></div></div>
                <div class="bar-val" style="color:<?= $d['pl']>=0?'var(--profit)':'var(--loss)' ?>"><?= fmtDPL($d['pl']) ?></div>
            </div>
            <div style="padding-left:88px;margin-top:-4px;margin-bottom:8px;font-size:10px;color:var(--text-muted)"><?= $d['c'] ?> trades · <?= $wr ?>% WR</div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-chart-bar" style="color:var(--warning)"></i> By Setup</div>
        <div class="dp-body">
            <?php uasort($bySetup,fn($a,$b)=>$b['pl']-$a['pl']);
            foreach($bySetup as $su=>$d): $wr=$d['c']>0?round($d['w']/$d['c']*100):0; ?>
            <div class="st-row">
                <span style="font-size:12px"><?= htmlspecialchars($su) ?> <span style="color:var(--text-muted)">(<?= $d['c'] ?>)</span></span>
                <span style="font-weight:700;font-size:12px;color:<?= $d['pl']>=0?'var(--profit)':'var(--loss)' ?>"><?= fmtDPL($d['pl']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="dp">
        <div class="dp-hdr"><i class="fas fa-clock" style="color:var(--accent)"></i> By Timeframe</div>
        <div class="dp-body">
            <?php uasort($byTF,fn($a,$b)=>$b['pl']-$a['pl']);
            foreach($byTF as $tf=>$d): $wr=$d['c']>0?round($d['w']/$d['c']*100):0; ?>
            <div class="st-row">
                <span><strong><?= $tf ?></strong> <span style="color:var(--text-muted);font-size:11px"><?= $d['c'] ?> trades · <?= $wr ?>% WR</span></span>
                <span style="font-weight:700;font-size:12px;color:<?= $d['pl']>=0?'var(--profit)':'var(--loss)' ?>"><?= fmtDPL($d['pl']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const isDark=document.documentElement.getAttribute('data-theme')==='dark';
    const gridC=isDark?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.06)';
    const textC=isDark?'#94a3b8':'#64748b';
    const dates=<?= json_encode($eqDates) ?>,vals=<?= json_encode($eqVals) ?>;
    if(dates.length&&document.getElementById('eqChart')){
        const col=vals[vals.length-1]>=0?'#22c55e':'#ef4444';
        new Chart(document.getElementById('eqChart'),{type:'line',data:{labels:dates,datasets:[{data:vals,borderColor:col,backgroundColor:col+'18',borderWidth:2,fill:true,pointRadius:dates.length>25?0:4,tension:0.35}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' $'+ctx.parsed.y.toFixed(2)}}},scales:{x:{grid:{color:gridC},ticks:{color:textC,font:{size:10},maxTicksLimit:8,maxRotation:30}},y:{grid:{color:gridC},ticks:{color:textC,font:{size:10},callback:v=>'$'+v}}}}});
    }
})();
</script>
<?php include '../includes/footer.php'; ?>

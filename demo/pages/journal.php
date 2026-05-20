<?php
require_once '../config/db.php';
$db=getDB(); $uid=DEMO_USER_ID; $acid=getDemoAccountId();

if(isset($_GET['delete'])){ $db->prepare("DELETE FROM demo_trades WHERE id=? AND account_id=?")->execute([intval($_GET['delete']),$acid]); syncDemoBalance($acid); }

$fSym=trim($_GET['symbol']??''); $fFrom=$_GET['from']??''; $fTo=$_GET['to']??'';
$fStrat=trim($_GET['strategy']??''); $fResult=$_GET['result']??'';

$where="WHERE account_id=? AND status='closed'"; $params=[$acid];
if($fSym){ $where.=" AND symbol LIKE ?"; $params[]="%$fSym%"; }
if($fFrom){ $where.=" AND DATE(close_time)>=?"; $params[]=$fFrom; }
if($fTo){ $where.=" AND DATE(close_time)<=?"; $params[]=$fTo; }
if($fStrat){ $where.=" AND strategy=?"; $params[]=$fStrat; }
if($fResult==='win'){ $where.=" AND profit_loss>0"; }
if($fResult==='loss'){ $where.=" AND profit_loss<0"; }

$trades=$db->prepare("SELECT dt.*, (SELECT COUNT(*) FROM demo_rule_checks rc WHERE rc.trade_id=dt.id AND rc.followed=0) as rules_broken FROM demo_trades dt $where ORDER BY close_time DESC");
$trades->execute($params); $tradesData=$trades->fetchAll();

$totalGross=array_sum(array_column($tradesData,'profit_loss'));
$totalNet=array_sum(array_column($tradesData,'net_pl'));
$totalComm=array_sum(array_column($tradesData,'commission'));
$wins=count(array_filter(array_column($tradesData,'profit_loss'),fn($v)=>$v>0));
$total=count($tradesData); $winRate=$total>0?round($wins/$total*100,1):0;

$strats=$db->prepare("SELECT DISTINCT strategy FROM demo_trades WHERE account_id=? AND strategy!='' AND strategy IS NOT NULL ORDER BY strategy");
$strats->execute([$acid]); $stratList=$strats->fetchAll(PDO::FETCH_COLUMN);

$reasonColors=['sl'=>'var(--loss)','tp'=>'var(--profit)','manual'=>'var(--text-muted)','so'=>'var(--warning)'];
$reasonLabels=['sl'=>'SL ❌','tp'=>'TP ✅','manual'=>'Manual','so'=>'SO ⛔'];
$emotionColors=['calm'=>'#22c55e','confident'=>'#3b82f6','neutral'=>'#94a3b8','anxious'=>'#f59e0b','fomo'=>'#ef4444','greedy'=>'#ef4444','revenge'=>'#dc2626','excited'=>'#8b5cf6'];

$pageTitle='Trade Journal'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.stat-bar{ display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px; }
.sb{ background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px; }
.sb-lbl{ font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:4px; }
.sb-val{ font-family:var(--font-mono);font-size:17px;font-weight:700; }
.pos{ color:var(--profit); } .neg{ color:var(--loss); }
.filt{ background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px; }
.filt-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;align-items:end; }
.form-label{ font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px; }
.fc{ width:100%;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:7px 10px;font-size:12px;outline:none; }
.dt{ width:100%;border-collapse:collapse;font-size:12px; }
.dt th{ padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);border-bottom:1px solid var(--border);text-align:left; }
.dt td{ padding:8px 10px;border-bottom:1px solid var(--border-light);vertical-align:middle; }
.dt tr:last-child td{ border-bottom:none; } .dt tr:hover td{ background:var(--bg-elevated); }
.pl-pos{ color:var(--profit);font-weight:700; } .pl-neg{ color:var(--loss);font-weight:700; }
.sym-b{ background:rgba(37,99,235,.1);color:var(--accent);font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;font-family:var(--font-mono); }
.badge-buy{ background:rgba(22,163,74,.12);color:var(--profit);font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px; }
.badge-sell{ background:rgba(220,38,38,.12);color:var(--loss);font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px; }
.rb-dot{ display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px; }
</style>

<div class="stat-bar">
    <div class="sb"><div class="sb-lbl">Net P&L</div><div class="sb-val <?= $totalNet>=0?'pos':'neg' ?>"><?= fmtDPL($totalNet) ?></div></div>
    <div class="sb"><div class="sb-lbl">Gross P&L</div><div class="sb-val <?= $totalGross>=0?'pos':'neg' ?>"><?= fmtDPL($totalGross) ?></div></div>
    <div class="sb"><div class="sb-lbl">Commission</div><div class="sb-val neg">-<?= fmtD($totalComm) ?></div></div>
    <div class="sb"><div class="sb-lbl">Win Rate</div><div class="sb-val <?= $winRate>=50?'pos':'neg' ?>"><?= $winRate ?>%</div></div>
    <div class="sb"><div class="sb-lbl">Trades</div><div class="sb-val"><?= $total ?></div></div>
    <div class="sb" style="display:flex;align-items:center">
        <a href="../pages/trade.php" class="btn-primary-custom" style="width:100%;justify-content:center;font-size:12px">
            <i class="fas fa-plus"></i> New Trade
        </a>
    </div>
</div>

<div class="filt">
    <form method="GET" class="filt-grid">
        <div><label class="form-label">Symbol</label><input type="text" class="fc" name="symbol" value="<?= htmlspecialchars($fSym) ?>" placeholder="EURUSD..."></div>
        <div><label class="form-label">Strategy</label>
            <select class="fc" name="strategy">
                <option value="">All</option>
                <?php foreach($stratList as $s): ?><option value="<?= $s ?>" <?= $fStrat===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label">Result</label>
            <select class="fc" name="result">
                <option value="">All</option>
                <option value="win" <?= $fResult==='win'?'selected':'' ?>>Wins only</option>
                <option value="loss" <?= $fResult==='loss'?'selected':'' ?>>Losses only</option>
            </select>
        </div>
        <div><label class="form-label">From</label><input type="date" class="fc" name="from" value="<?= $fFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" class="fc" name="to" value="<?= $fTo ?>"></div>
        <div style="display:flex;gap:6px"><button type="submit" class="btn-primary-custom" style="flex:1;justify-content:center;padding:7px"><i class="fas fa-search"></i></button>
        <a href="journal.php" class="btn-secondary-custom" style="flex:1;text-align:center;line-height:2">✕</a></div>
    </form>
</div>

<div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius)">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;color:var(--text-secondary);display:flex;align-items:center;gap:8px">
        <i class="fas fa-book-open"></i> Closed Trades (<?= $total ?>)
    </div>
    <div style="overflow-x:auto">
        <table class="dt">
            <thead><tr>
                <th>#</th><th>Date</th><th>Symbol</th><th>Type</th><th>Lots</th>
                <th>Entry</th><th>Exit</th><th>SL</th><th>TP</th>
                <th>Gross</th><th>Comm.</th><th>Net P&L</th>
                <th>Close</th><th>Strategy</th><th>Emotion</th><th>Rules</th><th>Notes</th><th></th>
            </tr></thead>
            <tbody>
            <?php if(empty($tradesData)): ?>
            <tr><td colspan="18" style="text-align:center;color:var(--text-muted);padding:40px">No closed trades yet.</td></tr>
            <?php else: ?>
            <?php foreach($tradesData as $i=>$t):
                $rc=$t['close_reason']??'';
                $em=strtolower($t['emotion']??'');
            ?>
            <tr>
                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                <td style="font-size:11px">
                    <?= date('d M Y',strtotime($t['close_time'])) ?>
                    <span style="display:block;color:var(--text-muted);font-size:10px;font-family:var(--font-mono)"><?= date('H:i',strtotime($t['close_time'])) ?></span>
                </td>
                <td><span class="sym-b"><?= htmlspecialchars($t['symbol']) ?></span></td>
                <td><span class="badge-<?= $t['trade_type'] ?>"><?= strtoupper($t['trade_type']) ?></span></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= $t['lots'] ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= number_format($t['entry_price'],5) ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= number_format($t['exit_price'],5) ?></td>
                <td style="font-family:var(--font-mono);font-size:10px;color:var(--loss)"><?= $t['stop_loss']?number_format($t['stop_loss'],5):'—' ?></td>
                <td style="font-family:var(--font-mono);font-size:10px;color:var(--profit)"><?= $t['take_profit']?number_format($t['take_profit'],5):'—' ?></td>
                <td class="<?= $t['profit_loss']>=0?'pl-pos':'pl-neg' ?>"><?= fmtDPL($t['profit_loss']) ?></td>
                <td style="color:var(--loss);font-size:11px">-<?= fmtD($t['commission']) ?></td>
                <td class="<?= $t['net_pl']>=0?'pl-pos':'pl-neg' ?>" style="font-size:14px"><?= fmtDPL($t['net_pl']) ?></td>
                <td>
                    <?php if($rc): ?>
                    <span style="font-size:10px;font-weight:700;color:<?= $reasonColors[$rc]??'var(--text-muted)' ?>"><?= $reasonLabels[$rc]??strtoupper($rc) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="font-size:11px;color:var(--text-muted)"><?= $t['strategy']?htmlspecialchars($t['strategy']):'—' ?></td>
                <td>
                    <?php if($t['emotion']): ?>
                    <span style="font-size:10px;color:<?= $emotionColors[$em]??'var(--text-muted)' ?>"><?= ucfirst($t['emotion']) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="text-align:center">
                    <?php if($t['rules_broken']>0): ?>
                    <span style="font-size:10px;color:var(--loss);font-weight:700"><?= $t['rules_broken'] ?> broke</span>
                    <?php else: ?>
                    <span style="font-size:10px;color:var(--profit)">✓</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:120px;font-size:11px;color:var(--text-muted)"><?= $t['notes']?htmlspecialchars(mb_strimwidth($t['notes'],0,40,'...')):'—' ?></td>
                <td><button class="btn-action delete" onclick="if(confirm('Delete?'))window.location.href='journal.php?delete=<?= $t['id'] ?>'"><i class="fas fa-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>

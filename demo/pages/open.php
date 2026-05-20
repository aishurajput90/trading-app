<?php
require_once '../config/db.php';
$db=$db=getDB(); $uid=DEMO_USER_ID; $acid=getDemoAccountId(); $acc=getDemoAccount();
$msg=''; $msgType='';

// Close a trade
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='close') {
    $tid       = intval($_POST['trade_id']);
    $exitPrice = floatval($_POST['exit_price']);
    $closeReason = $_POST['close_reason']??'manual';
    $notes     = trim($_POST['close_notes']??'');
    $closeTime = ($_POST['close_date']??date('Y-m-d')).' '.($_POST['close_time']??date('H:i')).':00';

    $trade = $db->prepare("SELECT * FROM demo_trades WHERE id=? AND account_id=? AND status='open'")->execute([$tid,$acid]) ? null : null;
    $s = $db->prepare("SELECT * FROM demo_trades WHERE id=? AND account_id=? AND status='open'");
    $s->execute([$tid,$acid]); $trade = $s->fetch();

    if ($trade) {
        $pl     = calcPL($trade['symbol'],$trade['trade_type'],(float)$trade['lots'],(float)$trade['entry_price'],$exitPrice);
        $netPL  = $pl - (float)$trade['commission'];
        $db->prepare("UPDATE demo_trades SET close_time=?,exit_price=?,profit_loss=?,net_pl=?,status='closed',close_reason=?,notes=CONCAT(COALESCE(notes,''),' | Close: ',?) WHERE id=?")
           ->execute([$closeTime,$exitPrice,$pl,$netPL,$closeReason,$notes,$tid]);
        syncDemoBalance($acid);
        $msg="Trade #{$tid} closed. P&L: ".fmtDPL($pl)." | Net: ".fmtDPL($netPL);
        $msgType=$pl>=0?'success':'error';
    }
}

$openStmt=$db->prepare("SELECT * FROM demo_trades WHERE account_id=? AND status='open' ORDER BY open_time DESC");
$openStmt->execute([$acid]); $openTrades=$openStmt->fetchAll();

$pageTitle='Open Positions'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.pos-card{ background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;overflow:hidden; }
.pos-card-hdr{ padding:14px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border); }
.pos-card-body{ padding:16px 18px; }
.pos-grid{ display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:14px; }
.pos-stat{ font-size:11px;color:var(--text-muted);margin-bottom:3px; }
.pos-val{ font-family:var(--font-mono);font-size:15px;font-weight:700;color:var(--text-primary); }
.pos-val.pos{ color:var(--profit); } .pos-val.neg{ color:var(--loss); }
.close-form{ background:var(--bg-elevated);border-radius:var(--radius-sm);padding:14px;margin-top:12px;display:none; }
.close-form.open{ display:block; }
.form-control{ width:100%;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:8px 12px;font-size:13px;outline:none; }
.form-control:focus{ border-color:var(--accent); }
.g3{ display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px; }
.g2{ display:grid;grid-template-columns:1fr 1fr;gap:10px; }
.badge-buy{ background:rgba(22,163,74,.12);color:var(--profit);font-size:11px;font-weight:700;padding:3px 10px;border-radius:4px; }
.badge-sell{ background:rgba(220,38,38,.12);color:var(--loss);font-size:11px;font-weight:700;padding:3px 10px;border-radius:4px; }
.sym-b{ background:rgba(37,99,235,.1);color:var(--accent);font-size:13px;font-weight:700;padding:4px 10px;border-radius:4px;font-family:var(--font-mono); }
</style>

<?php if($msg): ?>
<div class="alert-custom alert-<?= $msgType==='success'?'success':'error' ?> mb-4">
    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-xmark' ?>"></i> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div>
        <h5 style="margin:0;font-weight:700"><?= count($openTrades) ?> Open Position<?= count($openTrades)!=1?'s':'' ?></h5>
        <div style="font-size:12px;color:var(--text-muted)">Demo Account: <?= htmlspecialchars($acc['name']??'') ?></div>
    </div>
    <a href="trade.php" class="btn-primary-custom"><i class="fas fa-plus"></i> New Trade</a>
</div>

<?php if(empty($openTrades)): ?>
<div style="text-align:center;padding:60px 20px;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius)">
    <i class="fas fa-inbox" style="font-size:36px;color:var(--border);display:block;margin-bottom:12px"></i>
    <strong style="font-size:16px">No open positions</strong><br>
    <div style="color:var(--text-muted);margin:8px 0 20px">All trades are closed.</div>
    <a href="trade.php" class="btn-primary-custom"><i class="fas fa-plus"></i> Open First Trade</a>
</div>
<?php else: ?>
<?php foreach($openTrades as $t):
    $dur = floor((time()-strtotime($t['open_time']))/60);
    $h = floor($dur/60); $m = $dur%60;
    $durStr = $h>0?"{$h}h {$m}m":"{$m}m";
?>
<div class="pos-card">
    <div class="pos-card-hdr">
        <span class="sym-b"><?= htmlspecialchars($t['symbol']) ?></span>
        <span class="badge-<?= $t['trade_type'] ?>"><?= strtoupper($t['trade_type']) ?></span>
        <span style="color:var(--text-muted);font-size:12px"><?= $t['lots'] ?> lots</span>
        <span style="color:var(--text-muted);font-size:11px;margin-left:auto">Open: <?= date('d M H:i',strtotime($t['open_time'])) ?> · Running: <?= $durStr ?></span>
        <button class="btn-secondary-custom" style="padding:5px 12px;font-size:12px" onclick="toggleClose(<?= $t['id'] ?>)">
            <i class="fas fa-xmark"></i> Close
        </button>
    </div>
    <div class="pos-card-body">
        <div class="pos-grid">
            <div><div class="pos-stat">Entry</div><div class="pos-val"><?= number_format($t['entry_price'],5) ?></div></div>
            <?php if($t['stop_loss']): ?><div><div class="pos-stat">Stop Loss</div><div class="pos-val neg"><?= number_format($t['stop_loss'],5) ?></div></div><?php endif; ?>
            <?php if($t['take_profit']): ?><div><div class="pos-stat">Take Profit</div><div class="pos-val pos"><?= number_format($t['take_profit'],5) ?></div></div><?php endif; ?>
            <div><div class="pos-stat">Lots</div><div class="pos-val"><?= $t['lots'] ?></div></div>
            <div><div class="pos-stat">Commission</div><div class="pos-val neg">-<?= fmtD($t['commission']) ?></div></div>
            <?php if($t['strategy']): ?><div><div class="pos-stat">Strategy</div><div class="pos-val" style="font-size:12px"><?= htmlspecialchars($t['strategy']) ?></div></div><?php endif; ?>
            <?php if($t['setup']): ?><div><div class="pos-stat">Setup</div><div class="pos-val" style="font-size:12px"><?= htmlspecialchars($t['setup']) ?></div></div><?php endif; ?>
            <?php if($t['timeframe']): ?><div><div class="pos-stat">Timeframe</div><div class="pos-val"><?= $t['timeframe'] ?></div></div><?php endif; ?>
        </div>
        <?php if($t['notes']): ?>
        <div style="font-size:12px;color:var(--text-muted);background:var(--bg-elevated);padding:8px 12px;border-radius:var(--radius-sm)">
            <i class="fas fa-note-sticky"></i> <?= htmlspecialchars($t['notes']) ?>
        </div>
        <?php endif; ?>

        <!-- Close Form -->
        <div class="close-form" id="closeForm<?= $t['id'] ?>">
            <div style="font-size:13px;font-weight:600;margin-bottom:12px;color:var(--text-primary)">Close Position</div>
            <form method="POST">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                <div class="g3" style="margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Exit Price</label>
                        <input type="number" class="form-control" name="exit_price" step="0.00001" placeholder="0.00000" required>
                    </div>
                    <div>
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Close Date</label>
                        <input type="date" class="form-control" name="close_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Close Time</label>
                        <input type="time" class="form-control" name="close_time" value="<?= date('H:i') ?>">
                    </div>
                </div>
                <div class="g2" style="margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Close Reason</label>
                        <select class="form-control" name="close_reason">
                            <option value="manual">Manual</option>
                            <option value="tp">Take Profit ✅</option>
                            <option value="sl">Stop Loss ❌</option>
                            <option value="so">Stop Out ⛔</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Close Notes</label>
                        <input type="text" class="form-control" name="close_notes" placeholder="Why closing now?">
                    </div>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn-primary-custom" style="flex:1;justify-content:center">
                        <i class="fas fa-check"></i> Confirm Close
                    </button>
                    <button type="button" class="btn-secondary-custom" onclick="toggleClose(<?= $t['id'] ?>)">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function toggleClose(id) {
    const f=document.getElementById('closeForm'+id);
    f.classList.toggle('open');
}
</script>
<?php include '../includes/footer.php'; ?>

<?php
require_once '../config/db.php';
$db=getDB(); $uid=DEMO_USER_ID;
$msg=''; $msgType='';

// Switch account
if(isset($_GET['switch'])){ $_SESSION['demo_account_id']=(int)$_GET['switch']; header('Location:../index.php'); exit; }

// Add account
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='add'){
    $name=trim($_POST['name']??'New Account');
    $bal=max(100,floatval($_POST['balance']??10000));
    $desc=trim($_POST['description']??'');
    $db->prepare("INSERT INTO demo_accounts (user_id,name,starting_balance,current_balance,description) VALUES (?,?,?,?,?)")
       ->execute([$uid,$name,$bal,$bal,$desc]);
    $msg='Account created!'; $msgType='success';
}

// Reset account
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='reset'){
    $acid=intval($_POST['account_id']);
    $bal=floatval($_POST['reset_balance']??10000);
    $db->prepare("DELETE FROM demo_rule_checks WHERE trade_id IN (SELECT id FROM demo_trades WHERE account_id=?)")->execute([$acid]);
    $db->prepare("DELETE FROM demo_trades WHERE account_id=?")->execute([$acid]);
    $db->prepare("UPDATE demo_accounts SET starting_balance=?,current_balance=? WHERE id=? AND user_id=?")->execute([$bal,$bal,$acid,$uid]);
    $msg='Account reset!'; $msgType='success';
}

// Delete account
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete'){
    $acid=intval($_POST['account_id']);
    if($acid===1){$msg='Cannot delete the default account.';$msgType='error';}
    else{
        $db->prepare("DELETE FROM demo_rule_checks WHERE trade_id IN (SELECT id FROM demo_trades WHERE account_id=?)")->execute([$acid]);
        $db->prepare("DELETE FROM demo_trades WHERE account_id=?")->execute([$acid]);
        $db->prepare("DELETE FROM demo_accounts WHERE id=? AND user_id=?")->execute([$acid,$uid]);
        if(getDemoAccountId()===$acid) $_SESSION['demo_account_id']=1;
        $msg='Account deleted.'; $msgType='error';
    }
}

$accounts=$db->prepare("SELECT da.*, (SELECT COUNT(*) FROM demo_trades dt WHERE dt.account_id=da.id AND dt.status='closed') as trades_cnt,
    (SELECT COUNT(*) FROM demo_trades dt WHERE dt.account_id=da.id AND dt.status='open') as open_cnt
    FROM demo_accounts da WHERE da.user_id=? ORDER BY da.id");
$accounts->execute([$uid]); $accountsData=$accounts->fetchAll();

$pageTitle='Demo Accounts'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.acc-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:14px;position:relative;}
.acc-card.active-acc{border-color:var(--accent-purple);box-shadow:0 0 0 2px rgba(124,58,237,.15);}
.acc-active-badge{position:absolute;top:14px;right:14px;background:rgba(124,58,237,.12);color:var(--accent-purple);font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;}
.acc-name{font-size:16px;font-weight:700;margin-bottom:4px;}
.acc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0;}
.as{font-size:11px;color:var(--text-muted);margin-bottom:3px;}
.av{font-family:var(--font-mono);font-size:15px;font-weight:700;}
.pos{color:var(--profit);} .neg{color:var(--loss);} .acc{color:var(--accent);}
.form-label{font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px;}
.fc{width:100%;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:8px 12px;font-size:13px;outline:none;}
.add-form{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
</style>

<?php if($msg): ?>
<div class="alert-custom alert-<?= $msgType==='success'?'success':'error' ?> mb-4">
    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-xmark' ?>"></i> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">
<div>
    <h5 style="font-weight:700;margin-bottom:16px">Your Demo Accounts</h5>
    <?php foreach($accountsData as $a):
        $isActive=$a['id']===getDemoAccountId();
        $pnl=(float)$a['current_balance']-(float)$a['starting_balance'];
        $pct=$a['starting_balance']>0?round($pnl/$a['starting_balance']*100,2):0;
    ?>
    <div class="acc-card <?= $isActive?'active-acc':'' ?>">
        <?php if($isActive): ?><div class="acc-active-badge">✓ ACTIVE</div><?php endif; ?>
        <div class="acc-name"><?= htmlspecialchars($a['name']) ?></div>
        <?php if($a['description']): ?><div style="font-size:12px;color:var(--text-muted);margin-bottom:10px"><?= htmlspecialchars($a['description']) ?></div><?php endif; ?>
        <div class="acc-stats">
            <div><div class="as">Balance</div><div class="av <?= $pnl>=0?'pos':'neg' ?>"><?= fmtD($a['current_balance']) ?></div></div>
            <div><div class="as">P&L</div><div class="av <?= $pnl>=0?'pos':'neg' ?>"><?= fmtDPL($pnl) ?> (<?= $pct ?>%)</div></div>
            <div><div class="as">Closed Trades</div><div class="av acc"><?= $a['trades_cnt'] ?></div></div>
            <div><div class="as">Open</div><div class="av"><?= $a['open_cnt'] ?></div></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if(!$isActive): ?>
            <a href="accounts.php?switch=<?= $a['id'] ?>" class="btn-primary-custom" style="font-size:12px;padding:7px 14px"><i class="fas fa-check"></i> Switch to This</a>
            <?php else: ?>
            <span class="btn-secondary-custom" style="font-size:12px;padding:7px 14px;opacity:.5;cursor:default"><i class="fas fa-check"></i> Currently Active</span>
            <?php endif; ?>

            <!-- Reset form -->
            <form method="POST" style="margin:0" onsubmit="return confirm('Reset ALL trades in this account? Cannot undo!')">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="reset_balance" value="<?= $a['starting_balance'] ?>">
                <button type="submit" class="btn-secondary-custom" style="font-size:12px;padding:7px 14px;color:var(--warning);border-color:var(--warning)">
                    <i class="fas fa-rotate"></i> Reset
                </button>
            </form>

            <?php if($a['id']!==1): ?>
            <form method="POST" style="margin:0" onsubmit="return confirm('Delete this account and ALL its trades?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn-secondary-custom" style="font-size:12px;padding:7px 14px;color:var(--loss);border-color:var(--loss)">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add Account -->
<div class="add-form">
    <div style="font-size:13px;font-weight:700;margin-bottom:14px"><i class="fas fa-plus" style="color:var(--accent-purple)"></i> Create New Account</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Create separate accounts to test different strategies, risk levels, or timeframes independently.</p>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div style="margin-bottom:12px"><label class="form-label">Account Name</label><input type="text" class="fc" name="name" placeholder="e.g. Scalping Practice" required></div>
        <div style="margin-bottom:12px"><label class="form-label">Starting Balance ($)</label><input type="number" class="fc" name="balance" value="10000" min="100" step="100"></div>
        <div style="margin-bottom:14px"><label class="form-label">Description (optional)</label><textarea class="fc" name="description" rows="2" placeholder="What strategy will you test here?"></textarea></div>
        <button type="submit" class="btn-primary-custom" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Create Account</button>
    </form>
    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
    <div style="font-size:11px;color:var(--text-muted);line-height:1.8">
        <strong style="color:var(--text-primary)">Ideas:</strong><br>
        • <em>Conservative</em> — Risk 0.5%, only 1:3 RR<br>
        • <em>Scalping</em> — 5M chart, 3-5 pips SL<br>
        • <em>Swing</em> — 4H chart, 1-2 trades/week<br>
        • <em>News Trading</em> — High-impact news only
    </div>
</div>
</div>
<?php include '../includes/footer.php'; ?>

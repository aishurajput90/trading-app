<?php
require_once '../config/db.php';
$db=getDB(); $uid=DEMO_USER_ID;
$msg=''; $msgType='';

if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if($action==='add'){
        $text=trim($_POST['rule_text']??''); $cat=$_POST['category']??'risk';
        if($text){ $db->prepare("INSERT INTO demo_rules (user_id,rule_text,category,sort_order) VALUES (?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM demo_rules dr2 WHERE user_id=?))")->execute([$uid,$text,$cat,$uid]); $msg='Rule added!'; $msgType='success'; }
    }
    if($action==='delete'){ $db->prepare("DELETE FROM demo_rules WHERE id=? AND user_id=?")->execute([intval($_POST['rule_id']),$uid]); $msg='Rule deleted.'; $msgType='error'; }
    if($action==='toggle'){ $db->prepare("UPDATE demo_rules SET is_active=1-is_active WHERE id=? AND user_id=?")->execute([intval($_POST['rule_id']),$uid]); }
    if($action==='edit'){
        $db->prepare("UPDATE demo_rules SET rule_text=?,category=? WHERE id=? AND user_id=?")
           ->execute([trim($_POST['rule_text']??''),$_POST['category']??'risk',intval($_POST['rule_id']),$uid]);
        $msg='Rule updated!'; $msgType='success';
    }
}

$rules=$db->prepare("SELECT dr.*, (SELECT COUNT(*) FROM demo_rule_checks rc JOIN demo_trades dt ON rc.trade_id=dt.id WHERE rc.rule_id=dr.id AND dt.user_id=?) as total_checks, (SELECT COUNT(*) FROM demo_rule_checks rc JOIN demo_trades dt ON rc.trade_id=dt.id WHERE rc.rule_id=dr.id AND rc.followed=1 AND dt.user_id=?) as followed_checks FROM demo_rules dr WHERE dr.user_id=? ORDER BY dr.sort_order");
$rules->execute([$uid,$uid,$uid]); $rulesData=$rules->fetchAll();

$cats=['risk'=>['color'=>'var(--loss)','bg'=>'rgba(220,38,38,.1)','label'=>'Risk Management'],
       'entry'=>['color'=>'var(--profit)','bg'=>'rgba(22,163,74,.1)','label'=>'Entry Rules'],
       'exit'=>['color'=>'var(--accent-cyan)','bg'=>'rgba(8,145,178,.1)','label'=>'Exit Rules'],
       'mindset'=>['color'=>'var(--accent-purple)','bg'=>'rgba(124,58,237,.1)','label'=>'Mindset'],
       'strategy'=>['color'=>'var(--accent)','bg'=>'rgba(37,99,235,.1)','label'=>'Strategy']];

$pageTitle='Trading Rules'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.rule-item{ background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px;transition:opacity .2s; }
.rule-item.inactive{ opacity:.5; }
.rule-num{ width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;background:var(--bg-elevated);color:var(--text-muted); }
.rule-text{ flex:1;font-size:13px;color:var(--text-primary); }
.rule-comp{ font-size:11px;font-family:var(--font-mono);min-width:40px;text-align:center; }
.cat-badge{ font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px; }
.comp-bar{ width:60px;height:5px;background:var(--bg-elevated);border-radius:3px;overflow:hidden;margin-top:3px; }
.comp-fill{ height:100%;border-radius:3px; }
.add-form{ background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px; }
.fc{ width:100%;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:8px 12px;font-size:13px;outline:none; }
.fc:focus{ border-color:var(--accent); }
</style>

<?php if($msg): ?>
<div class="alert-custom alert-<?= $msgType==='success'?'success':'error' ?> mb-4">
    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-xmark' ?>"></i> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

<!-- Rules List -->
<div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h5 style="margin:0;font-weight:700"><?= count($rulesData) ?> Trading Rules</h5>
        <div style="font-size:12px;color:var(--text-muted)">Shown in pre-trade checklist</div>
    </div>
    <?php if(empty($rulesData)): ?>
    <div style="text-align:center;padding:40px;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-muted)">
        No rules yet. Add your first trading rule →
    </div>
    <?php else: ?>
    <?php foreach($rulesData as $i=>$r):
        $ci=$cats[$r['category']]??$cats['risk'];
        $comp=$r['total_checks']>0?round($r['followed_checks']/$r['total_checks']*100):null;
        $compColor=$comp===null?'var(--text-muted)':($comp>=80?'var(--profit)':($comp>=50?'var(--warning)':'var(--loss)'));
    ?>
    <div class="rule-item <?= !$r['is_active']?'inactive':'' ?>">
        <div class="rule-num"><?= $i+1 ?></div>
        <div class="rule-text">
            <?= htmlspecialchars($r['rule_text']) ?>
            <span class="cat-badge" style="background:<?= $ci['bg'] ?>;color:<?= $ci['color'] ?>"><?= $ci['label'] ?></span>
        </div>
        <?php if($comp !== null): ?>
        <div style="text-align:center">
            <div class="rule-comp" style="color:<?= $compColor ?>"><?= $comp ?>%</div>
            <div class="comp-bar"><div class="comp-fill" style="width:<?= $comp ?>%;background:<?= $compColor ?>"></div></div>
            <div style="font-size:9px;color:var(--text-muted)">compliance</div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:6px">
            <form method="POST" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-action" title="<?= $r['is_active']?'Disable':'Enable' ?>">
                <i class="fas fa-<?= $r['is_active']?'eye-slash':'eye' ?>"></i></button></form>
            <form method="POST" style="margin:0" onsubmit="return confirm('Delete this rule?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-action delete"><i class="fas fa-trash"></i></button></form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Rule + Tips -->
<div>
    <div class="add-form mb-4">
        <div style="font-size:13px;font-weight:700;margin-bottom:14px;color:var(--text-primary)"><i class="fas fa-plus" style="color:var(--accent)"></i> Add New Rule</div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div style="margin-bottom:10px">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Rule</label>
                <input type="text" class="fc" name="rule_text" placeholder="e.g. Always set SL before entering" required>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Category</label>
                <select class="fc" name="category">
                    <?php foreach($cats as $k=>$c): ?>
                    <option value="<?= $k ?>"><?= $c['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary-custom" style="width:100%;justify-content:center"><i class="fas fa-plus"></i> Add Rule</button>
        </form>
    </div>

    <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px">
        <div style="font-size:13px;font-weight:700;margin-bottom:12px"><i class="fas fa-lightbulb" style="color:var(--warning)"></i> Suggested Rules</div>
        <?php $suggested=[
            ['Risk max 1% per trade','risk'],['Always set SL before entry','risk'],
            ['Min 1:2 RR ratio','risk'],['Only trade 2+ confirmation setups','entry'],
            ['No trading during news','entry'],['No revenge trading','mindset'],
            ['Max 3 trades per day','mindset'],['Stop after 2 consecutive losses','mindset'],
            ['Record notes for every trade','strategy'],['Review trades weekly','strategy'],
        ]; ?>
        <?php foreach($suggested as $sg): ?>
        <form method="POST" style="margin:0 0 6px">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="rule_text" value="<?= htmlspecialchars($sg[0]) ?>">
            <input type="hidden" name="category" value="<?= $sg[1] ?>">
            <button type="submit" style="width:100%;text-align:left;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:7px 12px;color:var(--text-secondary);font-size:12px;cursor:pointer;transition:background .15s"
                onmouseover="this.style.background='var(--bg-base)'" onmouseout="this.style.background='var(--bg-elevated)'">
                <i class="fas fa-plus" style="color:var(--accent);margin-right:6px;font-size:10px"></i>
                <?= htmlspecialchars($sg[0]) ?>
                <span style="float:right;font-size:10px;color:var(--text-muted)"><?= $sg[1] ?></span>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
</div>
<?php include '../includes/footer.php'; ?>

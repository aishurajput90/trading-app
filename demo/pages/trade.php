<?php
require_once '../config/db.php';
$db   = getDB();
$uid  = DEMO_USER_ID;
$acid = getDemoAccountId();
$acc  = getDemoAccount();
$msg  = ''; $msgType = '';

// Common forex pairs
$pairs = ['EURUSD','GBPUSD','USDJPY','USDCHF','AUDUSD','NZDUSD','USDCAD',
          'EURJPY','GBPJPY','EURGBP','XAUUSD','XAGUSD','BTCUSD','ETHUSD',
          'US30','US500','NAS100','GER40'];

// Fetch rules for pre-trade checklist
$rulesStmt = $db->prepare("SELECT * FROM demo_rules WHERE user_id=? AND is_active=1 ORDER BY sort_order");
$rulesStmt->execute([$uid]); $rules = $rulesStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'open') {
    $symbol    = strtoupper(trim($_POST['symbol']??''));
    $type      = $_POST['trade_type']??'buy';
    $lots      = floatval($_POST['lots']??0.01);
    $entry     = floatval($_POST['entry_price']??0);
    $sl        = floatval($_POST['stop_loss']??0) ?: null;
    $tp        = floatval($_POST['take_profit']??0) ?: null;
    $strategy  = trim($_POST['strategy']??'');
    $setup     = trim($_POST['setup']??'');
    $tf        = $_POST['timeframe']??'';
    $conf      = intval($_POST['confidence']??3);
    $emotion   = $_POST['emotion']??'calm';
    $notes     = trim($_POST['notes']??'');
    $shotNote  = trim($_POST['screenshot_note']??'');
    $openTime  = ($_POST['open_date']??date('Y-m-d')).' '.($_POST['open_time']??date('H:i')).':00';

    // Commission: $7 round trip per standard lot
    $commission = round($lots * 7, 2);

    $ins = $db->prepare("INSERT INTO demo_trades
        (account_id,user_id,open_time,symbol,trade_type,lots,entry_price,stop_loss,take_profit,
         commission,status,strategy,setup,timeframe,confidence,emotion,notes,screenshot_note)
        VALUES (?,?,?,?,?,?,?,?,?,?,'open',?,?,?,?,?,?,?)");
    $ins->execute([$acid,$uid,$openTime,$symbol,$type,$lots,$entry,$sl,$tp,
                   $commission,$strategy,$setup,$tf,$conf,$emotion,$notes,$shotNote]);
    $tradeId = $db->lastInsertId();

    // Save rule checks
    foreach ($rules as $r) {
        $followed = isset($_POST['rule_'.$r['id']]) ? 1 : 0;
        $db->prepare("INSERT INTO demo_rule_checks (trade_id,rule_id,followed) VALUES (?,?,?)")
           ->execute([$tradeId,$r['id'],$followed]);
    }

    $msg = "Trade opened! #{$tradeId} — {$symbol} {$type} {$lots} lots @ \${$entry}";
    $msgType = 'success';
}

$pageTitle='New Trade'; $rootPath='../';
include '../includes/header.php';
?>
<style>
.tform { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius); }
.tform-hdr{ padding:16px 20px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px; }
.tform-body{ padding:20px; }
.tform-section { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--border); }
.tform-section:first-child{ margin-top:0; }
.g2{ display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.g3{ display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px; }
@media(max-width:700px){ .g2,.g3{ grid-template-columns:1fr; } }
.form-label{ font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;display:block; }
.form-control{ width:100%;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);padding:9px 12px;font-size:13px;font-family:var(--font-body);outline:none;transition:border-color .15s; }
.form-control:focus{ border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.type-selector{ display:grid;grid-template-columns:1fr 1fr;gap:8px; }
.type-btn{ padding:12px;border:2px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;text-align:center;font-weight:700;font-size:14px;transition:all .15s;background:var(--bg-elevated); }
.type-btn.buy-active{ border-color:var(--profit);background:rgba(22,163,74,.1);color:var(--profit); }
.type-btn.sell-active{ border-color:var(--loss);background:rgba(220,38,38,.1);color:var(--loss); }
.type-btn:not(.buy-active):not(.sell-active){ color:var(--text-muted); }
.conf-stars{ display:flex;gap:6px; }
.conf-star{ width:36px;height:36px;border:1px solid var(--border);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;background:var(--bg-elevated);transition:all .15s; }
.conf-star.active{ border-color:var(--warning);background:rgba(217,119,6,.15); }
.rule-check{ display:flex;align-items:flex-start;gap:10px;padding:8px 12px;border-radius:var(--radius-sm);background:var(--bg-elevated);margin-bottom:6px;cursor:pointer; }
.rule-check:hover{ background:var(--bg-base); }
.rule-check input[type=checkbox]{ width:16px;height:16px;margin-top:2px;accent-color:var(--profit);flex-shrink:0; }
.rule-check label{ font-size:13px;color:var(--text-secondary);cursor:pointer;line-height:1.4; }
.cat-badge{ font-size:9px;font-weight:700;padding:1px 6px;border-radius:3px;margin-left:6px;vertical-align:middle; }
.cat-risk{ background:rgba(220,38,38,.12);color:var(--loss); }
.cat-entry{ background:rgba(22,163,74,.12);color:var(--profit); }
.cat-exit{ background:rgba(8,145,178,.12);color:var(--accent-cyan); }
.cat-mindset{ background:rgba(124,58,237,.12);color:var(--accent-purple); }
.cat-strategy{ background:rgba(37,99,235,.12);color:var(--accent); }
.pl-preview-box{ background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;margin-top:12px;display:none; }
.pl-preview-row{ display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px; }
</style>

<?php if($msg): ?>
<div class="alert-custom alert-<?= $msgType==='success'?'success':'error' ?> mb-4">
    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-xmark' ?>"></i> <?= htmlspecialchars($msg) ?>
    <?php if($msgType==='success'): ?>
    &nbsp; <a href="open.php" style="color:var(--profit);font-weight:700">View Open Positions →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:20px;align-items:start">
<form method="POST">
<input type="hidden" name="action" value="open">
<div class="tform">
    <div class="tform-hdr"><i class="fas fa-plus-circle" style="color:var(--accent-purple)"></i> Open Demo Trade</div>
    <div class="tform-body">

        <div class="tform-section">Market & Direction</div>
        <div class="g2">
            <div>
                <label class="form-label">Symbol</label>
                <select class="form-control" name="symbol" id="symSel" required>
                    <?php foreach($pairs as $p): ?><option><?= $p ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Trade Type</label>
                <div class="type-selector">
                    <div class="type-btn buy-active" id="btnBuy" onclick="setType('buy')">▲ BUY</div>
                    <div class="type-btn" id="btnSell" onclick="setType('sell')">▼ SELL</div>
                </div>
                <input type="hidden" name="trade_type" id="tradeType" value="buy">
            </div>
        </div>

        <div class="tform-section">Entry Details</div>
        <div class="g3">
            <div>
                <label class="form-label">Entry Price</label>
                <input type="number" class="form-control" name="entry_price" id="entryPrice" step="0.00001" placeholder="0.00000" required oninput="calcRisk()">
            </div>
            <div>
                <label class="form-label">Stop Loss</label>
                <input type="number" class="form-control" name="stop_loss" id="stopLoss" step="0.00001" placeholder="0.00000" oninput="calcRisk()">
            </div>
            <div>
                <label class="form-label">Take Profit</label>
                <input type="number" class="form-control" name="take_profit" id="takeProfit" step="0.00001" placeholder="0.00000" oninput="calcRisk()">
            </div>
        </div>
        <div class="g2">
            <div>
                <label class="form-label">Lot Size</label>
                <input type="number" class="form-control" name="lots" id="lots" step="0.01" min="0.01" value="0.01" oninput="calcRisk()" required>
            </div>
            <div>
                <label class="form-label">Account Balance: <strong><?= fmtD($acc['current_balance']) ?></strong></label>
                <input type="number" class="form-control" id="riskPct" step="0.1" min="0.1" max="5" value="1" placeholder="Risk %" oninput="calcLots()">
                <div style="font-size:10px;color:var(--text-muted);margin-top:3px">Enter % to auto-calc lot size</div>
            </div>
        </div>

        <!-- Risk Preview -->
        <div class="pl-preview-box" id="riskBox">
            <div class="pl-preview-row"><span style="color:var(--text-muted)">Risk (SL hit)</span><span id="riskAmt" style="color:var(--loss);font-weight:700"></span></div>
            <div class="pl-preview-row"><span style="color:var(--text-muted)">Reward (TP hit)</span><span id="rewardAmt" style="color:var(--profit);font-weight:700"></span></div>
            <div class="pl-preview-row"><span style="color:var(--text-muted)">R:R Ratio</span><span id="rrRatio" style="font-weight:700"></span></div>
            <div class="pl-preview-row"><span style="color:var(--text-muted)">Commission</span><span id="commAmt" style="color:var(--loss)"></span></div>
        </div>

        <div class="tform-section">Open Time</div>
        <div class="g2">
            <div><label class="form-label">Date</label><input type="date" class="form-control" name="open_date" id="openDate" required></div>
            <div><label class="form-label">Time</label><input type="time" class="form-control" name="open_time" id="openTime" required></div>
        </div>

        <div class="tform-section">Trade Context</div>
        <div class="g2">
            <div>
                <label class="form-label">Strategy</label>
                <input type="text" class="form-control" name="strategy" list="stratList" placeholder="e.g. Breakout, Reversal, Trend">
                <datalist id="stratList">
                    <?php
                    $sL=$db->prepare("SELECT DISTINCT strategy FROM demo_trades WHERE account_id=? AND strategy!='' AND strategy IS NOT NULL");
                    $sL->execute([$acid]);
                    foreach($sL->fetchAll(PDO::FETCH_COLUMN) as $s) echo "<option value='{$s}'>";
                    ?>
                </datalist>
            </div>
            <div>
                <label class="form-label">Setup Type</label>
                <select class="form-control" name="setup">
                    <option value="">— Select —</option>
                    <option>Breakout</option><option>Reversal</option><option>Trend Follow</option>
                    <option>Range</option><option>News Play</option><option>Support/Resistance</option>
                    <option>Pattern (H&S, Flag etc)</option><option>Other</option>
                </select>
            </div>
        </div>
        <div class="g2">
            <div>
                <label class="form-label">Timeframe</label>
                <select class="form-control" name="timeframe">
                    <option value="">— Select —</option>
                    <?php foreach(['1M','5M','15M','30M','1H','4H','1D','1W'] as $tf) echo "<option>{$tf}</option>"; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Emotion Before Entry</label>
                <select class="form-control" name="emotion">
                    <?php foreach(['Calm','Confident','Neutral','Anxious','FOMO','Greedy','Revenge','Excited'] as $e) echo "<option value='".strtolower($e)."'>{$e}</option>"; ?>
                </select>
            </div>
        </div>

        <div>
            <label class="form-label">Confidence Level (1–5)</label>
            <div class="conf-stars" id="confStars">
                <?php for($i=1;$i<=5;$i++): ?>
                <div class="conf-star <?= $i<=3?'active':'' ?>" data-v="<?= $i ?>" onclick="setConf(<?= $i ?>)">⭐</div>
                <?php endfor; ?>
            </div>
            <input type="hidden" name="confidence" id="confVal" value="3">
        </div>

        <div style="margin-top:14px">
            <label class="form-label">Trade Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Why are you taking this trade? What does price action show?"></textarea>
        </div>
        <div style="margin-top:12px">
            <label class="form-label">Chart Observation / Screenshot Notes</label>
            <textarea class="form-control" name="screenshot_note" rows="2" placeholder="Describe what you see on the chart: levels, patterns, indicators..."></textarea>
        </div>
        <button type="submit" class="btn-primary-custom w-100 mt-3" style="width:100%;justify-content:center;padding:12px">
            <i class="fas fa-door-open"></i> Open Demo Trade
        </button>
    </div>
</div>
</form>

<!-- Pre-trade Rule Checklist -->
<div>
    <div class="tform">
        <div class="tform-hdr"><i class="fas fa-list-check" style="color:var(--profit)"></i> Pre-Trade Rule Checklist</div>
        <div class="tform-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Tick each rule you are following for this trade. This builds your discipline score.</p>
            <?php if(empty($rules)): ?>
            <div style="text-align:center;color:var(--text-muted);padding:20px"><a href="rules.php">Add your trading rules →</a></div>
            <?php else: ?>
            <?php $cats=['risk'=>'cat-risk','entry'=>'cat-entry','exit'=>'cat-exit','mindset'=>'cat-mindset','strategy'=>'cat-strategy']; ?>
            <?php foreach($rules as $r): ?>
            <label class="rule-check" for="rule_<?= $r['id'] ?>">
                <input type="checkbox" name="rule_<?= $r['id'] ?>" id="rule_<?= $r['id'] ?>" value="1" checked>
                <span><?= htmlspecialchars($r['rule_text']) ?> <span class="cat-badge <?= $cats[$r['category']]??'' ?>"><?= strtoupper($r['category']) ?></span></span>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Risk Calculator Card -->
    <div class="tform" style="margin-top:16px">
        <div class="tform-hdr"><i class="fas fa-shield-halved" style="color:var(--accent-cyan)"></i> Risk Management Guide</div>
        <div class="tform-body">
            <div style="font-size:12px;color:var(--text-muted);line-height:1.8">
                <div>💰 Balance: <strong style="color:var(--text-primary)"><?= fmtD($acc['current_balance']) ?></strong></div>
                <div>⚠️ 1% Risk = <strong style="color:var(--loss)"><?= fmtD($acc['current_balance']*0.01) ?></strong></div>
                <div>⚠️ 2% Risk = <strong style="color:var(--loss)"><?= fmtD($acc['current_balance']*0.02) ?></strong></div>
                <div>🎯 Min R:R should be 1:2</div>
                <div>📏 Max 3 trades per day</div>
                <div>🛑 Stop after 2 losses</div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
function setType(t) {
    document.getElementById('tradeType').value = t;
    document.getElementById('btnBuy').className  = 'type-btn'+(t==='buy'?' buy-active':'');
    document.getElementById('btnSell').className = 'type-btn'+(t==='sell'?' sell-active':'');
    calcRisk();
}
function setConf(v) {
    document.getElementById('confVal').value = v;
    document.querySelectorAll('.conf-star').forEach((s,i)=>{ s.classList.toggle('active', i<v); });
}
function calcRisk() {
    const entry = parseFloat(document.getElementById('entryPrice').value)||0;
    const sl    = parseFloat(document.getElementById('stopLoss').value)||0;
    const tp    = parseFloat(document.getElementById('takeProfit').value)||0;
    const lots  = parseFloat(document.getElementById('lots').value)||0.01;
    const type  = document.getElementById('tradeType').value;
    const box   = document.getElementById('riskBox');
    if (!entry) { box.style.display='none'; return; }
    box.style.display = 'block';
    const pip = 0.0001;
    const pv  = 10 * lots; // $ per pip per std lot
    let risk=0, reward=0;
    if (sl)  risk   = Math.abs(((type==='buy'?entry-sl:sl-entry)/pip) * pv);
    if (tp)  reward = Math.abs(((type==='buy'?tp-entry:entry-tp)/pip) * pv);
    const rr = risk > 0 ? (reward/risk).toFixed(2) : '—';
    const comm = lots * 7;
    document.getElementById('riskAmt').textContent   = risk   ? '-$'+risk.toFixed(2)   : '—';
    document.getElementById('rewardAmt').textContent = reward ? '+$'+reward.toFixed(2) : '—';
    document.getElementById('rrRatio').textContent   = rr !== '—' ? '1:'+rr : '—';
    document.getElementById('rrRatio').style.color   = parseFloat(rr)>=2?'var(--profit)':'var(--warning)';
    document.getElementById('commAmt').textContent   = '-$'+comm.toFixed(2);
}
function calcLots() {
    const bal   = <?= (float)$acc['current_balance'] ?>;
    const pct   = parseFloat(document.getElementById('riskPct').value)||1;
    const entry = parseFloat(document.getElementById('entryPrice').value)||0;
    const sl    = parseFloat(document.getElementById('stopLoss').value)||0;
    if (!entry || !sl) return;
    const riskAmt = bal * pct / 100;
    const pip   = 0.0001;
    const slPips= Math.abs(entry-sl)/pip;
    if (slPips <= 0) return;
    const lots = Math.min(Math.round((riskAmt/(slPips*10))*100)/100, 10);
    document.getElementById('lots').value = lots;
    calcRisk();
}
// Init date/time
const now = new Date();
document.getElementById('openDate').value = now.toISOString().split('T')[0];
document.getElementById('openTime').value = String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
</script>
<?php include '../includes/footer.php'; ?>

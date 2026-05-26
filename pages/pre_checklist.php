<?php
require_once '../config/db.php';
$pageTitle = 'Pre-Trade Checklist';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
$today     = date('Y-m-d');
$db        = getDB();

// Handle form submission
$saved   = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $sleptWell      = isset($_POST['slept_well'])       ? (int)$_POST['slept_well']       : null;
    $emotionallyCalm = isset($_POST['emotionally_calm']) ? (int)$_POST['emotionally_calm'] : null;
    $session        = trim($_POST['trading_session'] ?? '');
    $plan           = trim($_POST['trading_plan']    ?? '');
    $maxLoss        = strlen(trim($_POST['max_loss_today'] ?? '')) > 0 ? (float)$_POST['max_loss_today'] : null;
    $setups         = trim($_POST['setups_waiting']  ?? '');
    $patience       = in_array($_POST['patience_level'] ?? '', ['patience','neutral','urgency'])
                        ? $_POST['patience_level'] : null;

    $cleared = ($sleptWell === 1 && $emotionallyCalm === 1 && $patience !== 'urgency') ? 1 : 0;

    $stmt = $db->prepare("INSERT INTO pre_trade_checklist
        (user_id, checklist_date, slept_well, emotionally_calm, trading_session,
         trading_plan, max_loss_today, setups_waiting, patience_level, cleared_to_trade)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         slept_well=VALUES(slept_well), emotionally_calm=VALUES(emotionally_calm),
         trading_session=VALUES(trading_session), trading_plan=VALUES(trading_plan),
         max_loss_today=VALUES(max_loss_today), setups_waiting=VALUES(setups_waiting),
         patience_level=VALUES(patience_level), cleared_to_trade=VALUES(cleared_to_trade)");
    $stmt->execute([$userId, $today, $sleptWell, $emotionallyCalm,
                    $session ?: null, $plan ?: null, $maxLoss,
                    $setups ?: null, $patience, $cleared]);
    $saved = true;
}

// Fetch today's checklist if it exists
$stmt = $db->prepare("SELECT * FROM pre_trade_checklist WHERE user_id=? AND checklist_date=?");
$stmt->execute([$userId, $today]);
$checklist = $stmt->fetch();

include '../includes/header.php';
?>
<style>
.check-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:28px 32px;margin-bottom:20px}
.check-label{font-weight:600;font-size:.93rem;color:var(--text-primary);margin-bottom:10px;display:block}
.check-sublabel{font-size:.82rem;color:var(--text-muted);margin-bottom:10px;display:block}
.radio-group{display:flex;gap:10px;flex-wrap:wrap}
.radio-pill input{display:none}
.radio-pill label{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:50px;border:1.5px solid var(--border);cursor:pointer;font-size:.87rem;font-weight:500;transition:.15s;color:var(--text-secondary)}
.radio-pill input:checked + label{border-color:var(--accent);background:rgba(var(--accent-rgb,37,99,235),.12);color:var(--accent)}
.radio-pill.yes input:checked + label{border-color:#22c55e;background:rgba(34,197,94,.12);color:#22c55e}
.radio-pill.no  input:checked + label{border-color:#ef4444;background:rgba(239,68,68,.12);color:#ef4444}
.radio-pill.urgency input:checked + label{border-color:#ef4444;background:rgba(239,68,68,.12);color:#ef4444}
.radio-pill.patience input:checked + label{border-color:#22c55e;background:rgba(34,197,94,.12);color:#22c55e}
.cleared-banner{border-radius:14px;padding:22px 28px;display:flex;align-items:center;gap:16px;margin-bottom:24px}
.cleared-banner.pass{background:rgba(34,197,94,.1);border:1.5px solid #22c55e}
.cleared-banner.fail{background:rgba(239,68,68,.1);border:1.5px solid #ef4444}
.cleared-icon{font-size:2.4rem}
.cleared-title{font-size:1.25rem;font-weight:700}
.cleared-msg{font-size:.88rem;color:var(--text-secondary);margin-top:2px}
.answer-chip{display:inline-block;padding:4px 14px;border-radius:20px;font-size:.82rem;font-weight:600;margin-bottom:4px}
.chip-yes{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)}
.chip-no {background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3)}
.chip-neutral{background:rgba(234,179,8,.12);color:#ca8a04;border:1px solid rgba(234,179,8,.3)}
.answer-row{display:flex;align-items:flex-start;gap:12px;padding:14px 0;border-bottom:1px solid var(--border)}
.answer-row:last-child{border-bottom:none}
.answer-q{font-size:.83rem;font-weight:600;color:var(--text-muted);min-width:220px;padding-top:2px}
.answer-a{font-size:.9rem;color:var(--text-primary);flex:1}
</style>

<div class="container-fluid px-4 py-4" style="max-width:860px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 style="font-weight:700;font-size:1.5rem;margin:0">Pre-Trade Checklist</h2>
            <div style="font-size:.85rem;color:var(--text-muted);margin-top:2px"><?= date('l, d M Y') ?> — Complete before you open any trade today</div>
        </div>
        <?php if ($checklist): ?>
        <a href="?reset=1" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Re-fill today\'s checklist?')">
            <i class="fas fa-redo me-1"></i>Edit Answers
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['reset'])): ?>
        <?php $checklist = null; ?>
    <?php endif; ?>

    <?php if ($checklist): ?>
        <!-- Show submitted checklist -->
        <?php $cleared = (bool)$checklist['cleared_to_trade']; ?>
        <div class="cleared-banner <?= $cleared ? 'pass' : 'fail' ?>">
            <div class="cleared-icon"><?= $cleared ? '✅' : '🚫' ?></div>
            <div>
                <div class="cleared-title" style="color:<?= $cleared ? '#22c55e' : '#ef4444' ?>">
                    <?= $cleared ? 'CLEARED TO TRADE' : 'NOT CLEARED — Review Your Mental State' ?>
                </div>
                <div class="cleared-msg">
                    <?= $cleared
                        ? 'You have passed all pre-trade conditions. Trade only your planned setups. Respect your max loss and max trade limits.'
                        : 'One or more conditions failed. Trading today is HIGH RISK. If you choose to trade anyway, you are overriding this system — own that decision.' ?>
                </div>
            </div>
        </div>

        <div class="check-card">
            <div style="font-weight:700;font-size:1rem;margin-bottom:16px">Your Answers for Today</div>
            <div class="answer-row">
                <div class="answer-q">Did you sleep properly?</div>
                <div class="answer-a">
                    <?php if ($checklist['slept_well'] === null): ?>
                        <span class="answer-chip chip-neutral">Not answered</span>
                    <?php elseif ($checklist['slept_well']): ?>
                        <span class="answer-chip chip-yes">Yes</span>
                    <?php else: ?>
                        <span class="answer-chip chip-no">No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Are you emotionally calm?</div>
                <div class="answer-a">
                    <?php if ($checklist['emotionally_calm'] === null): ?>
                        <span class="answer-chip chip-neutral">Not answered</span>
                    <?php elseif ($checklist['emotionally_calm']): ?>
                        <span class="answer-chip chip-yes">Yes</span>
                    <?php else: ?>
                        <span class="answer-chip chip-no">No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Which session are you trading?</div>
                <div class="answer-a"><?= htmlspecialchars($checklist['trading_session'] ?? '—') ?></div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Today's trading plan</div>
                <div class="answer-a" style="white-space:pre-line"><?= nl2br(htmlspecialchars($checklist['trading_plan'] ?? '—')) ?></div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Max loss today</div>
                <div class="answer-a">
                    <?= $checklist['max_loss_today'] !== null
                        ? formatUSD($checklist['max_loss_today'])
                        : formatUSD(MAX_DAILY_LOSS_DOLLAR) . ' (default)' ?>
                </div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Setups I am waiting for</div>
                <div class="answer-a" style="white-space:pre-line"><?= nl2br(htmlspecialchars($checklist['setups_waiting'] ?? '—')) ?></div>
            </div>
            <div class="answer-row">
                <div class="answer-q">Trading with patience or urgency?</div>
                <div class="answer-a">
                    <?php
                    $pl = $checklist['patience_level'];
                    $plClass = $pl === 'patience' ? 'chip-yes' : ($pl === 'urgency' ? 'chip-no' : 'chip-neutral');
                    ?>
                    <span class="answer-chip <?= $plClass ?>"><?= $pl ? ucfirst($pl) : '—' ?></span>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3">
            <a href="post_analysis.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-chart-line me-1"></i>Post-Trade Review
            </a>
            <a href="coach.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-brain me-1"></i>Coach Dashboard
            </a>
        </div>

    <?php else: ?>
        <!-- Show form -->
        <div class="check-card mb-3" style="background:rgba(234,179,8,.06);border-color:rgba(234,179,8,.3)">
            <div style="font-size:.88rem;color:#ca8a04;font-weight:600">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Answer all 7 questions honestly before you enter any trade today.
                Your discipline starts here — not at the chart.
            </div>
        </div>

        <form method="POST">
                    <?= csrfField() ?>
            <!-- Q1 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q1.</span> Did you sleep properly last night?</span>
                <span class="check-sublabel">Sleep deprivation causes impulsive decisions and missed signals.</span>
                <div class="radio-group">
                    <span class="radio-pill yes">
                        <input type="radio" name="slept_well" id="sw1" value="1" required>
                        <label for="sw1"><i class="fas fa-check"></i> Yes, well rested</label>
                    </span>
                    <span class="radio-pill no">
                        <input type="radio" name="slept_well" id="sw0" value="0">
                        <label for="sw0"><i class="fas fa-xmark"></i> No, tired</label>
                    </span>
                </div>
            </div>

            <!-- Q2 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q2.</span> Are you emotionally calm right now?</span>
                <span class="check-sublabel">Anger, stress, or excitement from outside life will bleed into your trades.</span>
                <div class="radio-group">
                    <span class="radio-pill yes">
                        <input type="radio" name="emotionally_calm" id="ec1" value="1" required>
                        <label for="ec1"><i class="fas fa-check"></i> Yes, calm and focused</label>
                    </span>
                    <span class="radio-pill no">
                        <input type="radio" name="emotionally_calm" id="ec0" value="0">
                        <label for="ec0"><i class="fas fa-xmark"></i> No, emotionally off</label>
                    </span>
                </div>
            </div>

            <!-- Q3 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q3.</span> Which session are you trading?</span>
                <div class="radio-group">
                    <?php foreach (['London','New York','Asian','London+NY Overlap','Other'] as $s): ?>
                    <span class="radio-pill">
                        <input type="radio" name="trading_session" id="ts_<?= str_replace([' ','+'],'_',$s) ?>" value="<?= $s ?>" required>
                        <label for="ts_<?= str_replace([' ','+'],'_',$s) ?>"><?= $s ?></label>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Q4 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q4.</span> What is your trading plan for today?</span>
                <span class="check-sublabel">Write out your bias, key levels, and what you are looking for. No plan = no trade.</span>
                <textarea name="trading_plan" class="form-control" rows="3"
                    placeholder="e.g. XAUUSD — bullish above 2320. Waiting for pullback to 2310 support. Long only." required
                    style="background:var(--bg-base);border-color:var(--border);color:var(--text-primary);resize:vertical"></textarea>
            </div>

            <!-- Q5 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q5.</span> What is your max loss limit for today?</span>
                <span class="check-sublabel">Stop trading the moment you hit this number. No exceptions.</span>
                <div class="input-group" style="max-width:220px">
                    <span class="input-group-text" style="background:var(--bg-base);border-color:var(--border);color:var(--text-muted)">$</span>
                    <input type="number" name="max_loss_today" class="form-control"
                        value="<?= MAX_DAILY_LOSS_DOLLAR ?>" min="1" step="0.01"
                        style="background:var(--bg-base);border-color:var(--border);color:var(--text-primary)">
                </div>
            </div>

            <!-- Q6 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q6.</span> What specific setups are you waiting for?</span>
                <span class="check-sublabel">Vague answers mean random entries. Be specific.</span>
                <textarea name="setups_waiting" class="form-control" rows="2"
                    placeholder="e.g. Breakout retest on 15M with wick rejection, confluence with 4H level" required
                    style="background:var(--bg-base);border-color:var(--border);color:var(--text-primary);resize:vertical"></textarea>
            </div>

            <!-- Q7 -->
            <div class="check-card">
                <span class="check-label"><span style="color:var(--accent)">Q7.</span> Are you trading with patience or urgency today?</span>
                <span class="check-sublabel">Urgency = FOMO = bad trades. If you feel urgent — do not trade.</span>
                <div class="radio-group">
                    <span class="radio-pill patience">
                        <input type="radio" name="patience_level" id="pl_p" value="patience" required>
                        <label for="pl_p"><i class="fas fa-check"></i> Patience — I will wait for my setup</label>
                    </span>
                    <span class="radio-pill">
                        <input type="radio" name="patience_level" id="pl_n" value="neutral">
                        <label for="pl_n">Neutral</label>
                    </span>
                    <span class="radio-pill urgency">
                        <input type="radio" name="patience_level" id="pl_u" value="urgency">
                        <label for="pl_u"><i class="fas fa-xmark"></i> Urgency — I feel FOMO</label>
                    </span>
                </div>
            </div>

            <div class="d-flex gap-3 mt-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-check-circle me-2"></i>Submit Checklist
                </button>
                <a href="coach.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

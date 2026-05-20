<?php
require_once '../config/db.php';
require_once '../includes/challenge_helpers.php';

$pageTitle = 'Daily Challenge Entry';
$rootPath  = '../';
$userId    = DEFAULT_USER_ID;
$db        = getDB();
$today     = date('Y-m-d');
$msg       = '';
$msgType   = '';

$rawDate     = $_GET['date']         ?? $today;
$challengeId = (int)($_GET['challenge_id'] ?? 0);
$targetDate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) ? $rawDate : $today;

// Validate challenge
$stmt = $db->prepare("SELECT * FROM challenges WHERE id=? AND user_id=?");
$stmt->execute([$challengeId, $userId]);
$challenge = $stmt->fetch();
if (!$challenge) { header('Location: challenge.php'); exit; }

$dayNumber = max(1, (int)(floor((strtotime($targetDate) - strtotime($challenge['start_date'])) / 86400) + 1));

// Fetch existing record
$existStmt = $db->prepare("SELECT * FROM challenge_days WHERE challenge_id=? AND day_date=?");
$existStmt->execute([$challengeId, $targetDate]);
$existing = $existStmt->fetch();

// Fetch all days for XP calculation
$allDaysStmt = $db->prepare("SELECT day_date, result FROM challenge_days WHERE challenge_id=? ORDER BY day_date ASC");
$allDaysStmt->execute([$challengeId]);
$allDaysRaw = $allDaysStmt->fetchAll();
$dayMap = [];
foreach ($allDaysRaw as $d) $dayMap[$d['day_date']] = $d;

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkFields = ['check_higher_tf','check_key_levels','check_confirmation','check_risk_mgmt','check_no_revenge','check_setup_only','check_stop_loss','check_calm'];
    $checks = [];
    foreach ($checkFields as $f) $checks[$f] = isset($_POST[$f]) ? 1 : 0;

    $result     = in_array($_POST['result'] ?? '', ['followed','broke','no_trade']) ? $_POST['result'] : null;
    $tradesCount = (int)($_POST['trades_count'] ?? 0);
    $dailyPL     = (float)($_POST['daily_pl'] ?? 0);
    $equityEnd   = strlen(trim($_POST['equity_end'] ?? '')) > 0 ? (float)$_POST['equity_end'] : null;
    $wins        = (int)($_POST['wins'] ?? 0);
    $losses      = (int)($_POST['losses'] ?? 0);
    $rawEmotions = $_POST['emotions'] ?? [];
    $validEmos   = ['Fear','FOMO','Confidence','Revenge','Calm','Overtrading','Patience','Greed','Neutral'];
    $emotions    = json_encode(array_values(array_intersect($rawEmotions, $validEmos)));
    $wentWell    = trim($_POST['went_well']  ?? '') ?: null;
    $mistakes    = trim($_POST['mistakes']   ?? '') ?: null;
    $lessons     = trim($_POST['lessons']    ?? '') ?: null;

    $score = calculateDisciplineScore($checks, $result, json_decode($emotions, true) ?: []);

    // Build dayMap including the current day's result for XP streak calc
    $dayMapForXP = $dayMap;
    $dayMapForXP[$targetDate] = ['day_date' => $targetDate, 'result' => $result];
    $xp = calculateXP($result, $score, $dayMapForXP, $targetDate);

    $db->prepare("INSERT INTO challenge_days
        (challenge_id, user_id, day_date, day_number,
         check_higher_tf, check_key_levels, check_confirmation, check_risk_mgmt,
         check_no_revenge, check_setup_only, check_stop_loss, check_calm,
         checklist_submitted, result, trades_count, daily_pl, equity_end,
         wins, losses, emotions, went_well, mistakes, lessons, discipline_score, xp_earned)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         check_higher_tf=VALUES(check_higher_tf), check_key_levels=VALUES(check_key_levels),
         check_confirmation=VALUES(check_confirmation), check_risk_mgmt=VALUES(check_risk_mgmt),
         check_no_revenge=VALUES(check_no_revenge), check_setup_only=VALUES(check_setup_only),
         check_stop_loss=VALUES(check_stop_loss), check_calm=VALUES(check_calm),
         checklist_submitted=1, result=VALUES(result), trades_count=VALUES(trades_count),
         daily_pl=VALUES(daily_pl), equity_end=VALUES(equity_end), wins=VALUES(wins),
         losses=VALUES(losses), emotions=VALUES(emotions), went_well=VALUES(went_well),
         mistakes=VALUES(mistakes), lessons=VALUES(lessons),
         discipline_score=VALUES(discipline_score), xp_earned=VALUES(xp_earned), updated_at=NOW()")
    ->execute([$challengeId, $userId, $targetDate, $dayNumber,
               $checks['check_higher_tf'], $checks['check_key_levels'], $checks['check_confirmation'],
               $checks['check_risk_mgmt'], $checks['check_no_revenge'], $checks['check_setup_only'],
               $checks['check_stop_loss'], $checks['check_calm'],
               1, $result, $tradesCount, $dailyPL, $equityEnd, $wins, $losses,
               $emotions, $wentWell, $mistakes, $lessons, $score, $xp]);

    // Recalculate total XP
    $xpStmt = $db->prepare("SELECT COALESCE(SUM(xp_earned),0) as total FROM challenge_days WHERE challenge_id=?");
    $xpStmt->execute([$challengeId]);
    $totalXP   = (int)$xpStmt->fetch()['total'];
    $levelData = getLevelFromXP($totalXP);
    $db->prepare("UPDATE challenges SET total_xp=?, level=? WHERE id=?")->execute([$totalXP, $levelData['level'], $challengeId]);

    // Mark complete if last day
    if ($targetDate >= $challenge['end_date'] && $challenge['status'] === 'active') {
        $db->prepare("UPDATE challenges SET status='completed' WHERE id=?")->execute([$challengeId]);
    }

    $msg     = 'Day saved! +' . $xp . ' XP · Score: ' . $score . '/100';
    $msgType = 'success';

    // Reload existing
    $existStmt->execute([$challengeId, $targetDate]);
    $existing = $existStmt->fetch();
}

$savedEmotions = $existing ? (json_decode($existing['emotions'] ?? '[]', true) ?: []) : [];

include '../includes/header.php';
?>
<style>
.chal-section{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:22px 26px;margin-bottom:20px}
.chal-section-title{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--text-muted);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.check-row{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:9px;border:1.5px solid var(--border);cursor:pointer;transition:.15s;margin-bottom:8px;user-select:none}
.check-row:hover{border-color:var(--accent);background:rgba(59,130,246,.04)}
.check-row.checked{border-color:#22c55e;background:rgba(34,197,94,.07)}
.check-box{width:22px;height:22px;border-radius:6px;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.15s;font-size:.8rem}
.check-row.checked .check-box{background:#22c55e;border-color:#22c55e;color:#fff}
.check-label{font-size:.9rem;font-weight:600;color:var(--text-secondary)}
.check-row.checked .check-label{color:var(--text-primary)}
.result-row{display:flex;gap:12px;flex-wrap:wrap}
.result-btn{flex:1;min-width:130px;padding:20px 14px;border-radius:13px;border:2px solid var(--border);background:var(--bg-base);cursor:pointer;text-align:center;transition:.15s;font-weight:700;font-size:.9rem;color:var(--text-secondary)}
.result-btn:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.12)}
.result-btn.result-active-followed{border-color:#22c55e;background:rgba(34,197,94,.1);color:#22c55e}
.result-btn.result-active-broke{border-color:#f87171;background:rgba(248,113,113,.1);color:#f87171}
.result-btn.result-active-no_trade{border-color:#94a3b8;background:rgba(148,163,184,.1);color:#94a3b8}
.emo-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;border:1.5px solid var(--border);cursor:pointer;font-size:.84rem;font-weight:600;transition:.15s;color:var(--text-secondary);user-select:none;margin:4px}
.emo-chip:hover{border-color:var(--accent);color:var(--text-primary)}
.emo-chip.emo-selected{border-color:var(--accent);background:rgba(59,130,246,.12);color:var(--accent)}
.emo-chip.emo-selected.emo-neg{border-color:#f87171;background:rgba(248,113,113,.12);color:#f87171}
.score-meter-wrap{background:var(--bg-base);border-radius:20px;height:13px;overflow:hidden;margin-top:8px}
.score-meter-fill{height:100%;border-radius:20px;transition:width .3s ease,background .3s ease}
.smart-warn{display:none;border-radius:10px;padding:12px 16px;font-size:.84rem;font-weight:600;margin-top:12px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35);color:#f87171}
.form-ctrl{background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-primary);border-radius:9px;padding:9px 13px;width:100%;outline:none;transition:.15s;font-size:.9rem}
.form-ctrl:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.form-label-sm{font-size:.73rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--text-muted);margin-bottom:5px;display:block}
.breadcrumb-link{color:var(--accent);text-decoration:none;font-size:.84rem}
.breadcrumb-link:hover{text-decoration:underline}
.xp-preview{display:inline-flex;align-items:center;gap:6px;padding:3px 12px;border-radius:20px;font-size:.78rem;font-weight:800;font-family:'DM Mono',monospace;background:rgba(139,92,246,.12);color:#8b5cf6;border:1px solid rgba(139,92,246,.3)}
</style>

<!-- Breadcrumb -->
<div class="mb-3 px-1" style="font-size:.84rem;color:var(--text-muted)">
    <a href="challenge.php" class="breadcrumb-link"><?= htmlspecialchars($challenge['title']) ?></a>
    <span class="mx-2">›</span>
    <span>Day <?= $dayNumber ?> of <?= $challenge['duration_days'] ?></span>
    <span class="mx-2">·</span>
    <span style="font-family:'DM Mono',monospace"><?= date('D, M j, Y', strtotime($targetDate)) ?></span>
</div>

<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<form method="POST" id="dayForm">

<!-- Section 1: Pre-trade checklist -->
<div class="chal-section">
    <div class="chal-section-title"><i class="fas fa-clipboard-list" style="color:var(--accent)"></i>Pre-Trade Checklist</div>
    <?php
    $checkItems = [
        'check_higher_tf'    => 'I analyzed the higher timeframe before any trade',
        'check_key_levels'   => 'I marked all key support and resistance levels',
        'check_confirmation' => 'I waited for full confirmation before entering',
        'check_risk_mgmt'    => 'I respected my risk management rules all day',
        'check_no_revenge'   => 'I did not revenge trade after any loss',
        'check_setup_only'   => 'I only traded my defined setup — no random entries',
        'check_stop_loss'    => 'I used a Stop Loss on every trade',
        'check_calm'         => 'I stayed calm, disciplined, and in control all day',
    ];
    foreach ($checkItems as $field => $label):
        $isChecked = $existing ? (bool)$existing[$field] : false;
    ?>
    <div class="check-row <?= $isChecked ? 'checked' : '' ?>" onclick="toggleCheck(this)" data-field="<?= $field ?>">
        <input type="checkbox" name="<?= $field ?>" id="<?= $field ?>" <?= $isChecked ? 'checked' : '' ?> style="display:none">
        <div class="check-box"><?= $isChecked ? '✓' : '' ?></div>
        <span class="check-label"><?= htmlspecialchars($label) ?></span>
    </div>
    <?php endforeach; ?>
    <div style="font-size:.78rem;color:var(--text-muted);margin-top:8px"><i class="fas fa-info-circle me-1"></i>Each checked item adds 5 points to your discipline score.</div>
</div>

<!-- Section 2: Daily result -->
<div class="chal-section">
    <div class="chal-section-title"><i class="fas fa-flag-checkered" style="color:#f97316"></i>Daily Result</div>
    <input type="hidden" name="result" id="resultInput" value="<?= htmlspecialchars($existing['result'] ?? '') ?>">
    <div class="result-row">
        <div class="result-btn <?= ($existing['result']??'')==='followed'?'result-active-followed':'' ?>" onclick="selectResult('followed')">
            <div style="font-size:2rem;margin-bottom:8px">✅</div>
            <div style="font-weight:800">Rules Followed</div>
            <div style="font-size:.76rem;margin-top:4px;opacity:.7">+60 score pts</div>
        </div>
        <div class="result-btn <?= ($existing['result']??'')==='broke'?'result-active-broke':'' ?>" onclick="selectResult('broke')">
            <div style="font-size:2rem;margin-bottom:8px">❌</div>
            <div style="font-weight:800">Broke Rules</div>
            <div style="font-size:.76rem;margin-top:4px;opacity:.7">No score bonus</div>
        </div>
        <div class="result-btn <?= ($existing['result']??'')==='no_trade'?'result-active-no_trade':'' ?>" onclick="selectResult('no_trade')">
            <div style="font-size:2rem;margin-bottom:8px">😴</div>
            <div style="font-weight:800">No Trade</div>
            <div style="font-size:.76rem;margin-top:4px;opacity:.7">+40 score pts</div>
        </div>
    </div>
</div>

<!-- Section 3: Trading stats -->
<div class="chal-section">
    <div class="chal-section-title"><i class="fas fa-chart-simple" style="color:var(--accent-cyan,#06b6d4)"></i>Trading Stats</div>
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <label class="form-label-sm">Trades Taken</label>
            <input type="number" name="trades_count" class="form-ctrl" min="0" value="<?= $existing['trades_count'] ?? 0 ?>" onchange="updateLiveScore()">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label-sm">Day P/L ($)</label>
            <input type="number" name="daily_pl" class="form-ctrl" step="0.01" value="<?= $existing ? number_format($existing['daily_pl'],2,'.','') : '0.00' ?>" oninput="updatePLPreview(this.value)" onchange="updateLiveScore()">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label-sm">Wins</label>
            <input type="number" name="wins" class="form-ctrl" min="0" value="<?= $existing['wins'] ?? 0 ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label-sm">Losses</label>
            <input type="number" name="losses" class="form-ctrl" min="0" value="<?= $existing['losses'] ?? 0 ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label-sm">Ending Equity ($) <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem">optional</span></label>
            <div class="input-group">
                <span class="input-group-text" style="background:var(--bg-elevated);border:1px solid var(--border);border-right:none;color:var(--text-muted)">$</span>
                <input type="number" name="equity_end" class="form-ctrl" step="0.01" placeholder="Account equity at end of day" value="<?= $existing && $existing['equity_end'] !== null ? number_format($existing['equity_end'],2,'.','') : '' ?>" style="border-radius:0 9px 9px 0;border-left:none">
            </div>
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <div id="plPreview" style="font-family:'DM Mono',monospace;font-size:1.5rem;font-weight:800;padding-bottom:4px">
                <?php $pl = $existing ? (float)$existing['daily_pl'] : 0;
                echo ($pl >= 0 ? '<span style="color:var(--profit)">+$' : '<span style="color:var(--loss)">-$') . number_format(abs($pl),2) . '</span>'; ?>
            </div>
        </div>
    </div>
    <?php
    $maxTrades = (int)$challenge['max_trades_per_day'];
    $tc = $existing ? (int)$existing['trades_count'] : 0;
    if ($tc > 0 && $tc > $maxTrades): ?>
    <div class="smart-warn" style="display:block;margin-top:12px"><i class="fas fa-triangle-exclamation me-2"></i>You took <?= $tc ?> trades — your limit is <?= $maxTrades ?>. <strong>You are overtrading today.</strong></div>
    <?php endif; ?>
</div>

<!-- Section 4: Emotions -->
<div class="chal-section">
    <div class="chal-section-title"><i class="fas fa-heart-pulse" style="color:#f87171"></i>How Did You Feel Today?</div>
    <?php
    $emoList = [
        'Fear'        => ['emoji' => '😨', 'neg' => true],
        'FOMO'        => ['emoji' => '😰', 'neg' => true],
        'Confidence'  => ['emoji' => '💪', 'neg' => false],
        'Revenge'     => ['emoji' => '😡', 'neg' => true],
        'Calm'        => ['emoji' => '🧘', 'neg' => false],
        'Overtrading' => ['emoji' => '🔄', 'neg' => true],
        'Patience'    => ['emoji' => '⏳', 'neg' => false],
        'Greed'       => ['emoji' => '💸', 'neg' => true],
        'Neutral'     => ['emoji' => '😐', 'neg' => false],
    ];
    foreach ($emoList as $emo => $meta):
        $sel = in_array($emo, $savedEmotions);
    ?>
    <span class="emo-chip <?= $sel ? 'emo-selected' : '' ?> <?= ($sel && $meta['neg']) ? 'emo-neg' : '' ?>"
          data-emotion="<?= $emo ?>" data-neg="<?= $meta['neg'] ? '1' : '0' ?>"
          onclick="toggleEmotion(this)">
        <?= $meta['emoji'] ?> <?= $emo ?>
    </span>
    <?php endforeach; ?>

    <!-- Hidden checkboxes for form submission -->
    <div id="emoHiddenContainer">
        <?php foreach ($savedEmotions as $e): ?>
        <input type="checkbox" name="emotions[]" value="<?= htmlspecialchars($e) ?>" checked style="display:none">
        <?php endforeach; ?>
    </div>

    <div class="smart-warn" id="emoWarning">
        <i class="fas fa-triangle-exclamation me-2"></i>
        <strong>Dangerous emotion detected.</strong> Revenge, FOMO, Greed, and Overtrading destroy accounts. <em>Discipline is more important than profit.</em>
    </div>
    <div style="font-size:.76rem;color:var(--text-muted);margin-top:10px">Positive emotions (Calm, Confidence, Patience) add +5 points each. Negative emotions subtract -5 each (floor protected).</div>
</div>

<!-- Section 5: Journal -->
<div class="chal-section">
    <div class="chal-section-title"><i class="fas fa-pen-to-square" style="color:#8b5cf6"></i>Daily Journal</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label-sm">✅ What Went Well</label>
            <textarea name="went_well" class="form-ctrl" rows="4" placeholder="What did you do right today? Good executions, patient waits, good decisions..."><?= htmlspecialchars($existing['went_well'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label-sm">❌ Mistakes Made</label>
            <textarea name="mistakes" class="form-ctrl" rows="4" placeholder="What went wrong? Rule breaks, emotional decisions, poor exits..."><?= htmlspecialchars($existing['mistakes'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label-sm">💡 Lessons Learned</label>
            <textarea name="lessons" class="form-ctrl" rows="4" placeholder="What will you do differently? What insight did today give you?"><?= htmlspecialchars($existing['lessons'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- Live score + submit -->
<div class="chal-section" style="border-color:rgba(59,130,246,.25);background:linear-gradient(135deg,rgba(59,130,246,.04),rgba(124,58,237,.04))">
    <div class="row align-items-center g-3">
        <div class="col-md-6">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--text-muted);margin-bottom:6px">Discipline Score (live preview)</div>
            <div class="d-flex align-items-center gap-3">
                <div style="font-family:'DM Mono',monospace;font-size:2.5rem;font-weight:900" id="liveScoreDisplay"><?= $existing ? $existing['discipline_score'] : 0 ?></div>
                <div style="flex:1">
                    <div class="score-meter-wrap"><div class="score-meter-fill" id="scoreBarFill" style="width:<?= $existing ? $existing['discipline_score'] : 0 ?>%"></div></div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">out of 100</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--text-muted);margin-bottom:6px">Estimated XP</div>
            <div class="xp-preview" id="xpPreview">+<?= $existing ? $existing['xp_earned'] : 0 ?> XP</div>
        </div>
        <div class="col-md-3 text-end">
            <button type="submit" class="btn btn-primary w-100" style="padding:13px;border-radius:10px;font-weight:700;font-size:1rem">
                <i class="fas fa-floppy-disk me-2"></i><?= $existing ? 'Update Day' : 'Save Day' ?>
            </button>
            <a href="challenge.php" class="btn btn-outline-secondary w-100 mt-2" style="font-size:.85rem">← Back to Challenge</a>
        </div>
    </div>
</div>

<!-- Philosophy reminder -->
<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:.85rem;font-style:italic">
    "Your job is not to predict the market. Your job is to manage risk and execute consistently."
</div>

</form>

<?php include '../includes/footer.php'; ?>

<script>
const CHECKS = ['check_higher_tf','check_key_levels','check_confirmation','check_risk_mgmt','check_no_revenge','check_setup_only','check_stop_loss','check_calm'];
const POS_EMOS = ['Calm','Confidence','Patience'];
const NEG_EMOS = ['Revenge','FOMO','Overtrading','Greed','Fear'];

function toggleCheck(row) {
    const field = row.dataset.field;
    const cb    = document.getElementById(field);
    const box   = row.querySelector('.check-box');
    const isNow = !row.classList.contains('checked');
    row.classList.toggle('checked', isNow);
    cb.checked  = isNow;
    box.textContent = isNow ? '✓' : '';
    updateLiveScore();
}

function selectResult(val) {
    document.getElementById('resultInput').value = val;
    document.querySelectorAll('.result-btn').forEach(function(b) {
        b.className = 'result-btn';
    });
    event.currentTarget.className = 'result-btn result-active-' + val;
    updateLiveScore();
}

function toggleEmotion(chip) {
    const emo = chip.dataset.emotion;
    const neg = chip.dataset.neg === '1';
    const sel = !chip.classList.contains('emo-selected');
    chip.classList.toggle('emo-selected', sel);
    chip.classList.toggle('emo-neg', sel && neg);

    // Sync hidden inputs
    var container = document.getElementById('emoHiddenContainer');
    var existing  = container.querySelector('input[value="' + emo + '"]');
    if (sel) {
        if (!existing) {
            var inp = document.createElement('input');
            inp.type = 'checkbox'; inp.name = 'emotions[]';
            inp.value = emo; inp.checked = true; inp.style.display = 'none';
            container.appendChild(inp);
        }
    } else {
        if (existing) existing.remove();
    }

    // Warning
    var hasNeg = Array.from(document.querySelectorAll('.emo-chip.emo-selected')).some(function(c){ return c.dataset.neg === '1'; });
    document.getElementById('emoWarning').style.display = hasNeg ? 'block' : 'none';
    updateLiveScore();
}

function updateLiveScore() {
    var checkScore = 0;
    CHECKS.forEach(function(f) { if (document.getElementById(f) && document.getElementById(f).checked) checkScore += 5; });

    var result = document.getElementById('resultInput').value;
    var resultScore = result === 'followed' ? 60 : (result === 'no_trade' ? 40 : 0);
    var base = checkScore + resultScore;

    var selectedEmos = Array.from(document.querySelectorAll('.emo-chip.emo-selected')).map(function(c){ return c.dataset.emotion; });
    var posCount = selectedEmos.filter(function(e){ return POS_EMOS.includes(e); }).length;
    var negCount = selectedEmos.filter(function(e){ return NEG_EMOS.includes(e); }).length;
    var adjusted = base + Math.min(15, posCount * 5) - negCount * 5;
    var score = Math.min(100, Math.max(base, Math.max(0, adjusted)));

    document.getElementById('liveScoreDisplay').textContent = score;
    var fill = document.getElementById('scoreBarFill');
    fill.style.width = score + '%';
    fill.style.background = score >= 70 ? '#22c55e' : score >= 40 ? '#fbbf24' : '#f87171';

    // XP estimate
    var xp = 10;
    if (result === 'followed')  xp += 50;
    else if (result === 'no_trade') xp += 25;
    else if (result === 'broke') xp -= 20;
    if (score >= 90) xp += 30; else if (score >= 70) xp += 15;
    xp = Math.max(0, xp);
    document.getElementById('xpPreview').textContent = (xp >= 0 ? '+' : '') + xp + ' XP';
}

function updatePLPreview(val) {
    var v = parseFloat(val) || 0;
    var el = document.getElementById('plPreview');
    el.innerHTML = v >= 0
        ? '<span style="color:var(--profit)">+$' + Math.abs(v).toFixed(2) + '</span>'
        : '<span style="color:var(--loss)">-$' + Math.abs(v).toFixed(2) + '</span>';
}

// Init warnings
document.addEventListener('DOMContentLoaded', function() {
    var hasNeg = Array.from(document.querySelectorAll('.emo-chip.emo-selected')).some(function(c){ return c.dataset.neg === '1'; });
    document.getElementById('emoWarning').style.display = hasNeg ? 'block' : 'none';
    updateLiveScore();
});
</script>

<?php
require_once '../config/db.php';
$pageTitle = 'Post-Trade Review';
$rootPath  = '../';
$userId    = DEFAULT_USER_ID;
$today     = date('Y-m-d');
$db        = getDB();

// Determine which date we're reviewing (default = today)
$rawDate    = $_GET['date'] ?? $_POST['target_date'] ?? $today;
$targetDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && $rawDate <= $today)
              ? $rawDate : $today;
$isToday    = ($targetDate === $today);
$dateLabel  = date('l, d M Y', strtotime($targetDate));

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare("DELETE FROM discipline_calendar WHERE user_id=? AND cal_date=?");
    $stmt->execute([$userId, $targetDate]);
    header('Location: coach.php');
    exit;
}

// Handle SAVE
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    $discScore   = max(0, min(10, (int)($_POST['discipline_score'] ?? 0)));
    $psychScore  = max(0, min(10, (int)($_POST['psychology_score'] ?? 0)));
    $riskScore   = max(0, min(10, (int)($_POST['risk_score'] ?? 0)));
    $dayMark     = in_array($_POST['day_mark'] ?? '', ['green','yellow','red','stop','star'])
                    ? $_POST['day_mark'] : 'yellow';
    $ruleBreaks  = trim($_POST['rule_breaks']        ?? '');
    $emoMistakes = trim($_POST['emotional_mistakes'] ?? '');
    $bestTrade   = trim($_POST['best_trade_note']    ?? '');
    $worstTrade  = trim($_POST['worst_trade_note']   ?? '');
    $wentWell    = trim($_POST['what_went_well']     ?? '');
    $improve     = trim($_POST['what_to_improve']    ?? '');
    $notes       = trim($_POST['notes']              ?? '');

    $tStmt = $db->prepare("SELECT
        COUNT(*) as tc,
        COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END),0) as wins,
        COALESCE(SUM(CASE WHEN profit_loss <= 0 THEN 1 ELSE 0 END),0) as losses,
        COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl
        FROM trades WHERE user_id=? AND DATE(trade_datetime)=?");
    $tStmt->execute([$userId, $targetDate]);
    $ts = $tStmt->fetch();

    $stmt = $db->prepare("INSERT INTO discipline_calendar
        (user_id, cal_date, day_mark, discipline_score, psychology_score, risk_score,
         total_trades, wins, losses, net_pl,
         rule_breaks, emotional_mistakes, best_trade_note, worst_trade_note,
         what_went_well, what_to_improve, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
         day_mark=VALUES(day_mark), discipline_score=VALUES(discipline_score),
         psychology_score=VALUES(psychology_score), risk_score=VALUES(risk_score),
         total_trades=VALUES(total_trades), wins=VALUES(wins), losses=VALUES(losses),
         net_pl=VALUES(net_pl), rule_breaks=VALUES(rule_breaks),
         emotional_mistakes=VALUES(emotional_mistakes), best_trade_note=VALUES(best_trade_note),
         worst_trade_note=VALUES(worst_trade_note), what_went_well=VALUES(what_went_well),
         what_to_improve=VALUES(what_to_improve), notes=VALUES(notes)");
    $stmt->execute([
        $userId, $targetDate, $dayMark, $discScore, $psychScore, $riskScore,
        (int)$ts['tc'], (int)$ts['wins'], (int)$ts['losses'], (float)$ts['net_pl'],
        $ruleBreaks ?: null, $emoMistakes ?: null, $bestTrade ?: null, $worstTrade ?: null,
        $wentWell ?: null, $improve ?: null, $notes ?: null
    ]);
    $saved = true;
}

// Fetch record for this date
$stmt = $db->prepare("SELECT * FROM discipline_calendar WHERE user_id=? AND cal_date=?");
$stmt->execute([$userId, $targetDate]);
$record = $stmt->fetch();

// Trades for this date
$stmt = $db->prepare("SELECT * FROM trades WHERE user_id=? AND DATE(trade_datetime)=? ORDER BY trade_datetime ASC");
$stmt->execute([$userId, $targetDate]);
$dateTrades = $stmt->fetchAll();

$dateStats = ['tc'=>0,'wins'=>0,'losses'=>0,'net_pl'=>0];
foreach ($dateTrades as $t) {
    $dateStats['tc']++;
    if ($t['profit_loss'] > 0) $dateStats['wins']++;
    else $dateStats['losses']++;
    $dateStats['net_pl'] += $t['profit_loss'] - $t['brokerage'] + $t['swap'];
}

include '../includes/header.php';

$markColors = ['green'=>'#22c55e','yellow'=>'#eab308','red'=>'#ef4444','stop'=>'#7f1d1d','star'=>'#f59e0b'];
$markLabels = ['green'=>'Rules Followed','yellow'=>'Minor Mistakes','red'=>'Major Rule Breaks','stop'=>'Revenge / Emotional Breakdown','star'=>'Perfect Discipline Day'];
$markEmojis = ['green'=>'🟢','yellow'=>'🟡','red'=>'🔴','stop'=>'⛔','star'=>'⭐'];
$showForm   = !$record || isset($_GET['reset']);
?>
<style>
.pa-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:24px 28px;margin-bottom:18px}
.score-section{display:flex;gap:24px;flex-wrap:wrap}
.score-item{flex:1;min-width:160px}
.score-label{font-size:.82rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.score-slider{width:100%;accent-color:var(--accent)}
.score-display{font-size:1.8rem;font-weight:800;color:var(--accent);text-align:center;margin-top:4px}
.mark-options{display:flex;gap:10px;flex-wrap:wrap}
.mark-opt input{display:none}
.mark-opt label{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;border:2px solid var(--border);cursor:pointer;font-size:.88rem;font-weight:600;transition:.15s;color:var(--text-secondary)}
.mark-opt input:checked + label{border-color:var(--mc);background:rgba(var(--mc-rgb),.1);color:var(--mc)}
.trade-mini{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:.87rem}
.trade-mini:last-child{border-bottom:none}
.saved-banner{background:rgba(34,197,94,.1);border:1.5px solid #22c55e;border-radius:12px;padding:18px 24px;margin-bottom:22px;display:flex;align-items:center;gap:14px}
.stat-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.85rem;font-weight:600;margin-right:8px}
.textarea-dark{background:var(--bg-base)!important;border-color:var(--border)!important;color:var(--text-primary)!important;resize:vertical}
.section-title{font-weight:700;font-size:.95rem;margin-bottom:14px;color:var(--text-primary)}
.past-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:6px;font-size:.78rem;font-weight:700;background:rgba(234,179,8,.12);color:#ca8a04;border:1px solid rgba(234,179,8,.3);margin-left:10px}
</style>

<div class="container-fluid px-4 py-4" style="max-width:900px">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <h2 style="font-weight:700;font-size:1.5rem;margin:0">Post-Trade Review</h2>
                <?php if (!$isToday): ?>
                <span class="past-badge"><i class="fas fa-clock-rotate-left"></i>Past Day</span>
                <?php endif; ?>
            </div>
            <div style="font-size:.85rem;color:var(--text-muted);margin-top:2px"><?= $dateLabel ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($record): ?>
                <?php if (!isset($_GET['reset'])): ?>
                <a href="?date=<?= $targetDate ?>&reset=1" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-pen me-1"></i>Edit
                </a>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this day\'s record permanently?')">
                    <input type="hidden" name="action"      value="delete">
                    <input type="hidden" name="target_date" value="<?= $targetDate ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
            <?php endif; ?>
            <a href="coach.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Calendar
            </a>
        </div>
    </div>

    <?php if ($saved): ?>
    <div class="saved-banner">
        <span style="font-size:1.8rem">✅</span>
        <div>
            <div style="font-weight:700;color:#22c55e;font-size:1.1rem">Saved — <?= $dateLabel ?></div>
            <div style="font-size:.87rem;color:var(--text-secondary)">Check the <a href="coach.php" style="color:var(--accent)">Coach Dashboard</a> to see your calendar.</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Trade summary for this date -->
    <div class="pa-card">
        <div class="section-title">Trades — <?= date('d M Y', strtotime($targetDate)) ?></div>
        <?php if (empty($dateTrades)): ?>
            <div style="color:var(--text-muted);font-size:.9rem">No trades recorded in the journal for this day.</div>
        <?php else: ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <span class="stat-chip" style="background:rgba(99,102,241,.1);color:#818cf8">
                <i class="fas fa-list"></i><?= $dateStats['tc'] ?> trades
            </span>
            <span class="stat-chip" style="background:rgba(34,197,94,.1);color:#22c55e">
                <i class="fas fa-check"></i><?= $dateStats['wins'] ?> wins
            </span>
            <span class="stat-chip" style="background:rgba(239,68,68,.1);color:#ef4444">
                <i class="fas fa-xmark"></i><?= $dateStats['losses'] ?> losses
            </span>
            <span class="stat-chip" style="background:<?= $dateStats['net_pl']>=0?'rgba(34,197,94,.1)':'rgba(239,68,68,.1)' ?>;color:<?= $dateStats['net_pl']>=0?'#22c55e':'#ef4444' ?>">
                <i class="fas fa-dollar-sign"></i><?= formatPL($dateStats['net_pl']) ?>
            </span>
            <?php if ($dateStats['tc'] > MAX_TRADES_PER_DAY): ?>
            <span class="stat-chip" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.4)">
                <i class="fas fa-exclamation-triangle"></i>OVERTRADED (max <?= MAX_TRADES_PER_DAY ?>)
            </span>
            <?php endif; ?>
        </div>
        <?php foreach ($dateTrades as $t): ?>
        <div class="trade-mini">
            <div style="font-weight:600"><?= htmlspecialchars($t['symbol']) ?></div>
            <div style="color:var(--text-muted)"><?= strtoupper($t['trade_type']) ?></div>
            <div style="color:var(--text-muted)"><?= date('H:i', strtotime($t['trade_datetime'])) ?></div>
            <div style="color:<?= $t['profit_loss']>=0?'#22c55e':'#ef4444' ?>;font-weight:700"><?= formatPL($t['profit_loss']) ?></div>
            <div style="font-size:.8rem;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($t['notes'] ?? '') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!$showForm): ?>
    <!-- View existing record -->
    <div class="pa-card">
        <div class="section-title">Discipline Record</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px">
            <?php foreach (['discipline_score'=>'Discipline','psychology_score'=>'Psychology','risk_score'=>'Risk Mgmt'] as $col=>$label): ?>
            <div style="text-align:center;min-width:110px">
                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px"><?= $label ?></div>
                <?php $sc = (int)$record[$col]; $sc_color = $sc>=8?'#22c55e':($sc>=5?'#eab308':'#ef4444'); ?>
                <div style="font-size:2.2rem;font-weight:800;color:<?= $sc_color ?>"><?= $sc ?><span style="font-size:1rem;color:var(--text-muted)">/10</span></div>
            </div>
            <?php endforeach; ?>
            <div style="text-align:center;min-width:110px">
                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px">Day Mark</div>
                <div style="font-size:2rem"><?= $markEmojis[$record['day_mark']] ?? '—' ?></div>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= $markLabels[$record['day_mark']] ?? '' ?></div>
            </div>
        </div>
        <?php foreach ([
            'rule_breaks'=>'Rule Breaks','emotional_mistakes'=>'Emotional Mistakes',
            'best_trade_note'=>'Best Trade','worst_trade_note'=>'Worst Trade',
            'what_went_well'=>'What Went Well','what_to_improve'=>'What to Improve',
            'notes'=>'Notes',
        ] as $col => $label): ?>
            <?php if ($record[$col]): ?>
            <div style="margin-bottom:12px">
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:4px"><?= $label ?></div>
                <div style="font-size:.9rem;color:var(--text-primary);white-space:pre-line"><?= nl2br(htmlspecialchars($record[$col])) ?></div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- Form (new or edit) -->
    <form method="POST">
        <input type="hidden" name="target_date" value="<?= $targetDate ?>">

        <!-- Scores -->
        <div class="pa-card">
            <div class="section-title">Score This Day (0 – 10)</div>
            <div class="score-section">
                <?php foreach ([
                    ['discipline_score','Discipline Score','Did you follow your rules?'],
                    ['psychology_score', 'Psychology Score','Were you emotionally in control?'],
                    ['risk_score',       'Risk Mgmt Score', 'Did you size positions correctly?'],
                ] as [$name,$label,$hint]):
                    $existingVal = $record ? (int)$record[$name] : 5;
                ?>
                <div class="score-item">
                    <div class="score-label"><?= $label ?></div>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:8px"><?= $hint ?></div>
                    <input type="range" class="score-slider" name="<?= $name ?>" min="0" max="10"
                        value="<?= $existingVal ?>"
                        oninput="this.nextElementSibling.textContent=this.value">
                    <div class="score-display"><?= $existingVal ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Day Mark -->
        <div class="pa-card">
            <div class="section-title">Mark This Day</div>
            <div class="mark-options">
                <?php foreach ([
                    ['green', '#22c55e','34,197,94',  '🟢 Rules Followed'],
                    ['yellow','#eab308','234,179,8',  '🟡 Minor Mistakes'],
                    ['red',   '#ef4444','239,68,68',  '🔴 Major Rule Breaks'],
                    ['stop',  '#7f1d1d','127,29,29',  '⛔ Revenge / Breakdown'],
                    ['star',  '#f59e0b','245,158,11', '⭐ Perfect Day'],
                ] as [$val,$mc,$mcRgb,$lbl]):
                    $checked = ($record && $record['day_mark']===$val) ? 'checked' : ($val==='yellow'&&!$record?'checked':'');
                ?>
                <span class="mark-opt" style="--mc:<?=$mc?>;--mc-rgb:<?=$mcRgb?>">
                    <input type="radio" name="day_mark" id="dm_<?=$val?>" value="<?=$val?>" <?=$checked?>>
                    <label for="dm_<?=$val?>"><?=$lbl?></label>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Rule Breaks & Emotional Mistakes -->
        <div class="pa-card">
            <div class="section-title">Honest Self-Assessment</div>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;font-size:.88rem">Which rules did you break?</label>
                <textarea name="rule_breaks" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Moved stop loss, entered without a TP, exceeded max trades..."><?= htmlspecialchars($record['rule_breaks'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;font-size:.88rem">What emotional mistakes did you make?</label>
                <textarea name="emotional_mistakes" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Revenge traded after 2nd loss, felt FOMO..."><?= htmlspecialchars($record['emotional_mistakes'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Best & Worst Trade -->
        <div class="pa-card">
            <div class="section-title">Trade Highlights</div>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;font-size:.88rem">Best Trade — Why was it good?</label>
                <textarea name="best_trade_note" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Waited for retest, had proper SL/TP, held to target..."><?= htmlspecialchars($record['best_trade_note'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;font-size:.88rem">Worst Trade — Why was it bad?</label>
                <textarea name="worst_trade_note" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Entered on impulse, no clear setup, moved SL..."><?= htmlspecialchars($record['worst_trade_note'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Improvement -->
        <div class="pa-card">
            <div class="section-title">Learning & Next Steps</div>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;font-size:.88rem">What did I do well?</label>
                <textarea name="what_went_well" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Stayed patient, only took 2 trades, respected my SL..."><?= htmlspecialchars($record['what_went_well'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;font-size:.88rem">What must I improve?</label>
                <textarea name="what_to_improve" class="form-control textarea-dark" rows="2"
                    placeholder="e.g. Stop entering during news, do not move stop loss..."><?= htmlspecialchars($record['what_to_improve'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;font-size:.88rem">General Notes</label>
                <textarea name="notes" class="form-control textarea-dark" rows="2"
                    placeholder="Any other observations..."><?= htmlspecialchars($record['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="d-flex gap-3 mt-2 pb-4 flex-wrap">
            <button type="submit" class="btn btn-primary px-5">
                <i class="fas fa-save me-2"></i>Save Review
            </button>
            <a href="coach.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>

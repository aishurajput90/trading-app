<?php
require_once '../config/db.php';
require_once '../includes/psych_helpers.php';
$pageTitle = 'Daily Discipline Entry';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
$today     = date('Y-m-d');
$db        = getDB();

// ── Resolve entry date (supports ?date= for past entries) ────────────────────
$entryDateParam = $_GET['date'] ?? $_POST['entry_date'] ?? $today;
// Validate: must be a real date, not in the future
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDateParam) || $entryDateParam > $today) {
    $entryDateParam = $today;
}
$entryDate    = $entryDateParam;
$isEditingPast = $entryDate !== $today;

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_daily') {
        $entryDate  = (isset($_POST['entry_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date']) && $_POST['entry_date'] <= $today)
                      ? $_POST['entry_date'] : $today;
        $preEmotion = in_array($_POST['pre_emotion'] ?? '', ['calm','fear','greedy','angry','confident','revenge','fomo'])
                      ? $_POST['pre_emotion'] : null;

        $habitCodes   = array_keys(getHabitDefs());
        $triggered    = [];
        $severityMap  = [];
        foreach ($habitCodes as $code) {
            if (!empty($_POST['habit_' . $code])) {
                $triggered[] = $code;
                $sev = (int)($_POST['severity_' . $code] ?? 2);
                $severityMap[$code] = max(1, min(3, $sev));
            }
        }

        $reflections = [
            'followed_plan'   => !empty($_POST['followed_plan'])   ? 1 : 0,
            'emotional_entry' => !empty($_POST['emotional_entry']) ? 1 : 0,
            'emotional_exit'  => !empty($_POST['emotional_exit'])  ? 1 : 0,
            'forced_trade'    => !empty($_POST['forced_trade'])    ? 1 : 0,
            'entered_early'   => !empty($_POST['entered_early'])   ? 1 : 0,
            'had_patience'    => !empty($_POST['had_patience'])    ? 1 : 0,
            'followed_rules'  => !empty($_POST['followed_rules'])  ? 1 : 0,
        ];

        $boolReflections = array_map('boolval', $reflections);
        $discScore  = calcDisciplineScore($triggered, $severityMap, $boolReflections);
        $psychScore = calcPsychologyScore($preEmotion, $boolReflections);
        $emoStab    = calcEmotionalStability($preEmotion, $triggered, $boolReflections);
        $feedback   = generateCoachFeedback($triggered, $preEmotion, $boolReflections);
        $notes      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES);

        $stmt = $db->prepare(
            "INSERT INTO psych_daily
             (user_id, entry_date, pre_emotion, habits_triggered, habit_severity,
              followed_plan, emotional_entry, emotional_exit, forced_trade, entered_early,
              had_patience, followed_rules, discipline_score, psychology_score,
              emotional_stability, coach_feedback, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
              pre_emotion=VALUES(pre_emotion), habits_triggered=VALUES(habits_triggered),
              habit_severity=VALUES(habit_severity), followed_plan=VALUES(followed_plan),
              emotional_entry=VALUES(emotional_entry), emotional_exit=VALUES(emotional_exit),
              forced_trade=VALUES(forced_trade), entered_early=VALUES(entered_early),
              had_patience=VALUES(had_patience), followed_rules=VALUES(followed_rules),
              discipline_score=VALUES(discipline_score), psychology_score=VALUES(psychology_score),
              emotional_stability=VALUES(emotional_stability), coach_feedback=VALUES(coach_feedback),
              notes=VALUES(notes)"
        );
        $stmt->execute([
            $userId, $entryDate, $preEmotion,
            json_encode($triggered), json_encode($severityMap),
            $reflections['followed_plan'], $reflections['emotional_entry'],
            $reflections['emotional_exit'], $reflections['forced_trade'],
            $reflections['entered_early'], $reflections['had_patience'],
            $reflections['followed_rules'], $discScore, $psychScore, $emoStab,
            $feedback, $notes,
        ]);

        // Sync discipline_calendar scores if a row exists for today
        $syncStmt = $db->prepare(
            "UPDATE discipline_calendar SET discipline_score=?, psychology_score=?
             WHERE user_id=? AND cal_date=?"
        );
        $syncStmt->execute([(int)round($discScore / 10), (int)round($psychScore / 10), $userId, $today]);

        header('Location: psych_tracker.php?msg=' . urlencode('Psychology entry saved successfully!') . '&type=success');
        exit;
    }

    if ($action === 'save_trade_quality') {
        $criteria = [
            'setup_quality'     => max(1, min(10, (int)($_POST['setup_quality']     ?? 5))),
            'emotional_control' => max(1, min(10, (int)($_POST['emotional_control'] ?? 5))),
            'risk_management'   => max(1, min(10, (int)($_POST['risk_management']   ?? 5))),
            'patience'          => max(1, min(10, (int)($_POST['patience']          ?? 5))),
            'rr_quality'        => max(1, min(10, (int)($_POST['rr_quality']        ?? 5))),
            'rule_following'    => max(1, min(10, (int)($_POST['rule_following']    ?? 5))),
            'sl_discipline'     => max(1, min(10, (int)($_POST['sl_discipline']     ?? 5))),
        ];
        $overallScore  = calcTradeQualityScore($criteria);
        $symbol        = htmlspecialchars(trim($_POST['tq_symbol'] ?? ''));
        $tradeId       = !empty($_POST['tq_trade_id']) ? (int)$_POST['tq_trade_id'] : null;
        $tqPreEmotion  = in_array($_POST['tq_pre_emotion'] ?? '', ['calm','fear','greedy','angry','confident','revenge','fomo'])
                         ? $_POST['tq_pre_emotion'] : null;
        $tqNotes       = htmlspecialchars(trim($_POST['tq_notes'] ?? ''), ENT_QUOTES);

        $stmt = $db->prepare(
            "INSERT INTO psych_trade_quality
             (user_id, trade_id, entry_date, symbol, pre_emotion, setup_quality,
              emotional_control, risk_management, patience, rr_quality, rule_following,
              sl_discipline, overall_score, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $tqDate = (isset($_POST['entry_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date'])) ? $_POST['entry_date'] : $today;
        $stmt->execute([
            $userId, $tradeId, $tqDate, $symbol, $tqPreEmotion,
            $criteria['setup_quality'], $criteria['emotional_control'],
            $criteria['risk_management'], $criteria['patience'],
            $criteria['rr_quality'], $criteria['rule_following'],
            $criteria['sl_discipline'], $overallScore, $tqNotes,
        ]);

        header('Location: psych_daily.php?msg=' . urlencode("Trade quality saved — score: {$overallScore}/100") . '&type=success');
        exit;
    }
}

// ── Fetch existing entry (pre-populate form, supports past dates) ─────────────
$stmt = $db->prepare("SELECT * FROM psych_daily WHERE user_id=? AND entry_date=?");
$stmt->execute([$userId, $entryDate]);
$existing = $stmt->fetch();

$exHabits   = $existing ? (json_decode($existing['habits_triggered'] ?? '[]', true) ?: []) : [];
$exSeverity = $existing ? (json_decode($existing['habit_severity']   ?? '{}', true) ?: []) : [];

// ── Trades for the entry date (for trade quality selector) ────────────────────
$stmt = $db->prepare("SELECT id, symbol, profit_loss FROM trades WHERE user_id=? AND DATE(trade_datetime)=? ORDER BY trade_datetime ASC");
$stmt->execute([$userId, $entryDate]);
$todayTrades = $stmt->fetchAll();

// ── Habit streaks ─────────────────────────────────────────────────────────────
$habitDefs = getHabitDefs();
$habitStreak = [];
foreach (array_keys($habitDefs) as $code) {
    $habitStreak[$code] = getPsychStreak($userId, $code, $db);
}

// ── Flash message ─────────────────────────────────────────────────────────────
$flashMsg  = $_GET['msg']  ?? '';
$flashType = $_GET['type'] ?? 'success';

include '../includes/header.php';
?>

<style>
/* ── Daily Entry — Premium UI ── */
.pd-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 20px;
}
.pd-section-hdr {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border);
}
.pd-section-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.pd-section-icon.blue  { background: rgba(37,99,235,.12); color: #2563eb; }
.pd-section-icon.amber { background: rgba(217,119,6,.12);  color: #d97706; }
.pd-section-icon.green { background: rgba(22,163,74,.12);  color: #16a34a; }
.pd-section-icon.purple{ background: rgba(99,102,241,.12); color: #6366f1; }
.pd-section-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px; height: 22px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    flex-shrink: 0;
}
.pd-section-title { font-size: 14px; font-weight: 700; }
.pd-section-sub { font-size: 11px; color: var(--text-muted); }

/* Emotion cards */
.emotion-cards { display: flex; flex-wrap: wrap; gap: 10px; }
.emotion-option { display: none; }
.emotion-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 90px;
    height: 84px;
    border: 2px solid var(--border);
    border-radius: 14px;
    cursor: pointer;
    transition: border-color .2s, background .2s, transform .15s, box-shadow .2s;
    font-size: 11px;
    font-weight: 600;
    text-align: center;
    gap: 5px;
    padding: 10px 6px;
    background: var(--bg-base);
}
.emotion-label .emo-emoji { font-size: 28px; line-height: 1; }
.emotion-option:checked + .emotion-label {
    border-color: var(--accent);
    background: rgba(37,99,235,.1);
    transform: scale(1.06);
    box-shadow: 0 4px 16px rgba(37,99,235,.2);
}

/* Habit entry cards */
.habit-entry-card {
    background: var(--bg-base);
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
.habit-entry-card.active {
    border-color: #ef4444;
    background: rgba(239,68,68,.04);
    box-shadow: 0 0 0 3px rgba(239,68,68,.08);
}
.severity-select { display: none; margin-top: 12px; }
.severity-select.visible { display: flex; gap: 6px; }
.sev-btn {
    flex: 1;
    padding: 6px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    background: var(--bg-card);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
    transition: .15s;
    letter-spacing: .03em;
}
.sev-btn.selected-mild     { background: rgba(34,197,94,.15); border-color: rgba(34,197,94,.5); color: #16a34a; }
.sev-btn.selected-moderate { background: rgba(234,179,8,.15);  border-color: rgba(234,179,8,.5);  color: #ca8a04; }
.sev-btn.selected-severe   { background: rgba(239,68,68,.15);  border-color: rgba(239,68,68,.5);  color: #dc2626; }

/* Reflection cards */
.reflection-card {
    background: var(--bg-base);
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: border-color .2s;
}
.reflection-card .rc-label { font-size: 13px; font-weight: 600; flex: 1; }
.reflection-card .rc-desc  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
.form-check-input:checked { background-color: var(--accent); border-color: var(--accent); }

/* Score preview rings */
.score-preview-ring { position: relative; width: 96px; height: 96px; margin: 0 auto; }
.score-preview-ring canvas { display: block; }
.score-preview-ring .spr-val {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
    font-size: 20px; font-weight: 800; font-family: 'DM Mono', monospace;
    text-align: center; line-height: 1;
}

/* Slider */
.slider-wrap label { font-size: 12px; font-weight: 700; color: var(--text-muted); display: flex; justify-content: space-between; margin-bottom: 4px; }
.slider-wrap input[type=range] { width: 100%; accent-color: var(--accent); }
</style>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show mb-3" role="alert" id="flashMsg">
    <?= htmlspecialchars($flashMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<script>setTimeout(function(){ var e=document.getElementById('flashMsg'); if(e) e.classList.remove('show'); }, 3500);</script>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
        <div style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,rgba(37,99,235,.2),rgba(99,102,241,.2));display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-file-pen" style="font-size:18px;color:var(--accent)"></i>
        </div>
        <div>
            <h5 style="font-size:1.05rem;font-weight:800;margin:0 0 2px">
                <?= $isEditingPast ? 'Edit Entry — ' . date('d F Y', strtotime($entryDate)) : 'Daily Discipline Entry' ?>
            </h5>
            <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:8px">
                <i class="fas fa-calendar-day" style="font-size:10px"></i>
                <?= date('l, d F Y', strtotime($entryDate)) ?>
                <?php if ($isEditingPast): ?>
                    <span style="background:rgba(245,158,11,.15);color:#d97706;border:1px solid rgba(245,158,11,.3);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.04em">PAST ENTRY</span>
                <?php else: ?>
                    <span style="background:rgba(37,99,235,.12);color:var(--accent);border:1px solid rgba(37,99,235,.2);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.04em">TODAY</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <a href="psych_tracker.php" class="btn btn-sm btn-outline-secondary" style="border-radius:10px">
        <i class="fas fa-arrow-left me-1"></i>Back to Tracker
    </a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Main Psychology Form -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<form method="POST" id="psychForm">
                    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_daily">
    <input type="hidden" name="entry_date" value="<?= htmlspecialchars($entryDate) ?>">

    <!-- Section 1: Pre-Session Emotion -->
    <div class="pd-section">
        <div class="pd-section-hdr">
            <div class="pd-section-icon blue"><i class="fas fa-face-smile-beam"></i></div>
            <div>
                <div class="pd-section-title">Pre-Session Emotion</div>
                <div class="pd-section-sub">How did you feel when you sat down to trade on <?= $isEditingPast ? date('d M Y', strtotime($entryDate)) : 'today' ?>?</div>
            </div>
            <span class="pd-section-num ms-auto">1</span>
        </div>
            <div class="emotion-cards">
                <?php
                $emotions = [
                    'calm'      => ['🧘', 'Calm'],
                    'confident' => ['💪', 'Confident'],
                    'fear'      => ['😨', 'Fear'],
                    'greedy'    => ['🤑', 'Greedy'],
                    'angry'     => ['😤', 'Angry'],
                    'fomo'      => ['🏃', 'FOMO'],
                    'revenge'   => ['😡', 'Revenge'],
                ];
                $selectedEmo = $existing['pre_emotion'] ?? '';
                foreach ($emotions as $val => [$emoji, $label]):
                ?>
                <div>
                    <input type="radio" name="pre_emotion" id="emo_<?= $val ?>" value="<?= $val ?>"
                           class="emotion-option" <?= $selectedEmo === $val ? 'checked' : '' ?>>
                    <label for="emo_<?= $val ?>" class="emotion-label">
                        <span class="emo-emoji"><?= $emoji ?></span>
                        <span><?= $label ?></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3">
                <label class="form-label" style="font-size:12px;font-weight:600;color:var(--text-muted)">Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="What's on your mind before trading today?"
                    style="font-size:13px;border-radius:10px"><?= htmlspecialchars($existing['notes'] ?? '') ?></textarea>
            </div>
    </div>

    <!-- Section 2: Bad Habit Tracker -->
    <div class="pd-section">
        <div class="pd-section-hdr">
            <div class="pd-section-icon amber"><i class="fas fa-heart-pulse"></i></div>
            <div>
                <div class="pd-section-title">Bad Habit Tracker</div>
                <div class="pd-section-sub">Did any of these bad habits occur during today's session?</div>
            </div>
            <span class="pd-section-num ms-auto">2</span>
        </div>
            <div class="row g-3">
                <?php foreach ($habitDefs as $code => $def):
                    $isChecked   = in_array($code, $exHabits);
                    $curSev      = (int)($exSeverity[$code] ?? 2);
                    $streak      = $habitStreak[$code] ?? 0;
                ?>
                <div class="col-12 col-md-6">
                    <div class="habit-entry-card <?= $isChecked ? 'active' : '' ?>" id="hcard_<?= $code ?>">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;border-radius:9px;background:<?= $isChecked ? 'rgba(220,38,38,.12)' : 'rgba(37,99,235,.08)' ?>;display:flex;align-items:center;justify-content:center;color:<?= $isChecked ? 'var(--loss)' : 'var(--accent)' ?>">
                                    <i class="<?= $def['icon'] ?>"></i>
                                </div>
                                <div>
                                    <div style="font-size:13px;font-weight:600"><?= $def['label'] ?></div>
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        <?= $streak ?> clean day<?= $streak !== 1 ? 's' : '' ?> streak
                                    </div>
                                </div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input habit-toggle" type="checkbox"
                                       name="habit_<?= $code ?>" id="habit_<?= $code ?>"
                                       data-code="<?= $code ?>"
                                       <?= $isChecked ? 'checked' : '' ?>
                                       style="width:40px;height:20px;cursor:pointer">
                            </div>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:6px"><?= $def['desc'] ?></div>
                        <!-- Severity selector -->
                        <div class="severity-select <?= $isChecked ? 'visible' : '' ?>" id="sev_<?= $code ?>">
                            <span style="font-size:11px;color:var(--text-muted);align-self:center;white-space:nowrap">Severity:</span>
                            <?php foreach ([1 => 'Mild', 2 => 'Moderate', 3 => 'Severe'] as $sevVal => $sevLabel): ?>
                            <button type="button" class="sev-btn <?= $isChecked && $curSev === $sevVal ? 'selected-' . strtolower($sevLabel) : '' ?>"
                                    data-code="<?= $code ?>" data-sev="<?= $sevVal ?>">
                                <?= $sevLabel ?>
                            </button>
                            <?php endforeach; ?>
                            <input type="hidden" name="severity_<?= $code ?>" id="sev_val_<?= $code ?>" value="<?= $curSev ?>">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
    </div>

    <!-- Section 3: After-Session Reflection -->
    <div class="pd-section">
        <div class="pd-section-hdr">
            <div class="pd-section-icon green"><i class="fas fa-magnifying-glass"></i></div>
            <div>
                <div class="pd-section-title">After-Session Reflection</div>
                <div class="pd-section-sub">Honest self-assessment after your session.</div>
            </div>
            <span class="pd-section-num ms-auto">3</span>
        </div>
            <div class="row g-3">
                <?php
                $reflectionItems = [
                    // [name, label, description, goodIsChecked, icon]
                    ['followed_plan',   'Followed Trading Plan',    'I traded only setups from my plan',                 true,  'fas fa-map'],
                    ['emotional_entry', 'Emotional Entry?',         'I entered because of emotion, not analysis',        false, 'fas fa-heart-crack'],
                    ['emotional_exit',  'Emotional Exit?',          'I exited early or late due to emotion',             false, 'fas fa-door-open'],
                    ['forced_trade',    'Forced Trade?',            'I took a trade when there was no clear setup',      false, 'fas fa-bolt'],
                    ['entered_early',   'Entered Early?',           'I entered before the setup was confirmed',          false, 'fas fa-forward-fast'],
                    ['had_patience',    'Had Patience',             'I waited for high-quality setups and did not rush', true,  'fas fa-hourglass-half'],
                    ['followed_rules',  'Followed All Rules',       'I followed all my trading rules today',             true,  'fas fa-list-check'],
                ];
                foreach ($reflectionItems as [$name, $label, $desc, $goodIsChecked, $icon]):
                    $checked = isset($existing[$name]) ? (bool)$existing[$name] : ($goodIsChecked ? false : false);
                    $isGood  = $checked === $goodIsChecked;
                ?>
                <div class="col-12 col-md-6">
                    <div class="reflection-card">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.08);display:flex;align-items:center;justify-content:center;color:var(--accent);flex-shrink:0">
                            <i class="<?= $icon ?>" style="font-size:14px"></i>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="rc-label"><?= $label ?></div>
                            <div style="font-size:11px;color:var(--text-muted)"><?= $desc ?></div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox"
                                   name="<?= $name ?>" id="ref_<?= $name ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   style="width:40px;height:20px;cursor:pointer">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
    </div>

    <!-- Live Score Preview -->
    <div class="pd-section" style="background:linear-gradient(135deg,var(--bg-card),rgba(37,99,235,.04))">
        <div class="pd-section-hdr">
            <div class="pd-section-icon purple"><i class="fas fa-gauge-high"></i></div>
            <div>
                <div class="pd-section-title">Live Score Preview</div>
                <div class="pd-section-sub">Updates as you fill in the form above</div>
            </div>
        </div>
            <div class="row g-3 text-center">
                <?php foreach (['Discipline' => 'prev_disc', 'Psychology' => 'prev_psych', 'Stability' => 'prev_emo'] as $lbl => $cid): ?>
                <div class="col-4">
                    <div class="score-preview-ring">
                        <canvas id="<?= $cid ?>" width="96" height="96"></canvas>
                        <div class="spr-val" id="<?= $cid ?>_txt" style="color:var(--text-muted)">—</div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-top:8px;text-transform:uppercase;letter-spacing:.06em"><?= $lbl ?></div>
                </div>
                <?php endforeach; ?>
            </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mb-4" style="border-radius:14px;font-weight:800;font-size:15px;padding:14px;letter-spacing:.02em">
        <i class="fas fa-floppy-disk me-2"></i>Save Discipline Entry
    </button>
</form>

<!-- Trade Quality Section (optional) -->
<div class="pd-section mb-4">
    <div class="pd-section-hdr" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#tqSection">
        <div class="pd-section-icon" style="background:rgba(234,179,8,.12);color:#d97706"><i class="fas fa-star"></i></div>
        <div>
            <div class="pd-section-title">Trade Quality Score <span style="font-size:10px;font-weight:600;color:var(--text-muted);letter-spacing:.04em;margin-left:6px">OPTIONAL</span></div>
            <div class="pd-section-sub">Rate the quality of a specific trade today across 7 criteria.</div>
        </div>
        <span class="pd-section-num ms-auto">4</span>
        <i class="fas fa-chevron-down ms-2" style="font-size:12px;color:var(--text-muted);transition:.2s"></i>
    </div>
        <div class="collapse" id="tqSection">
            <form method="POST" id="tqForm">
                    <?= csrfField() ?>
                <input type="hidden" name="action" value="save_trade_quality">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:12px;font-weight:600">Symbol</label>
                        <input type="text" name="tq_symbol" class="form-control" placeholder="e.g. EURUSD" style="font-size:13px">
                    </div>
                    <?php if ($todayTrades): ?>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:12px;font-weight:600">Link to Trade (optional)</label>
                        <select name="tq_trade_id" class="form-select" style="font-size:13px">
                            <option value="">— None —</option>
                            <?php foreach ($todayTrades as $t): ?>
                            <option value="<?= $t['id'] ?>">#<?= $t['id'] ?> <?= htmlspecialchars($t['symbol']) ?> <?= formatPL($t['profit_loss']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:12px;font-weight:600">Emotion at Entry</label>
                        <select name="tq_pre_emotion" class="form-select" style="font-size:13px">
                            <option value="">— Select —</option>
                            <?php foreach (['calm','confident','fear','greedy','angry','fomo','revenge'] as $e): ?>
                            <option value="<?= $e ?>"><?= getEmotionEmoji($e) ?> <?= ucfirst($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php
                $sliders = [
                    ['setup_quality',     'Setup Quality',     'Was the setup clear, structured, and high probability?'],
                    ['emotional_control', 'Emotional Control', 'Did emotions influence entry/exit decisions?'],
                    ['risk_management',   'Risk Management',   'Was position sizing and risk % correct?'],
                    ['patience',          'Patience',          'Did you wait for full confirmation before entering?'],
                    ['rr_quality',        'RR Quality',        'Did the trade have a minimum 1:2 risk:reward?'],
                    ['rule_following',    'Rule Following',    'Did you follow all rules during this trade?'],
                    ['sl_discipline',     'Stop Loss Discipline', 'Was the stop loss placed correctly and not moved?'],
                ];
                ?>
                <div class="row g-3 mb-3">
                    <?php foreach ($sliders as [$sname, $slabel, $sdesc]): ?>
                    <div class="col-12 col-md-6">
                        <div class="slider-wrap">
                            <label for="sl_<?= $sname ?>">
                                <span><?= $slabel ?></span>
                                <span id="sl_<?= $sname ?>_val" style="color:var(--accent);font-family:'DM Mono',monospace">5</span>
                            </label>
                            <input type="range" id="sl_<?= $sname ?>" name="<?= $sname ?>" min="1" max="10" value="5"
                                   oninput="updateSlider('<?= $sname ?>', this.value)" class="mt-1">
                            <div style="font-size:10px;color:var(--text-muted)"><?= $sdesc ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex align-items-center gap-4 mb-3">
                    <div style="font-size:14px;font-weight:700">
                        Trade Quality Score: <span id="tq_score_display" style="color:var(--accent);font-family:'DM Mono',monospace;font-size:20px">50</span>/100
                    </div>
                    <div style="flex:1;background:var(--border);border-radius:99px;height:6px;overflow:hidden">
                        <div id="tq_score_bar" style="height:100%;background:var(--accent);width:50%;border-radius:99px;transition:.3s"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Trade Notes</label>
                    <textarea name="tq_notes" class="form-control" rows="2" placeholder="What did you do well? What could improve?" style="font-size:13px"></textarea>
                </div>
                <button type="submit" class="btn btn-success" style="border-radius:12px;font-weight:700;padding:10px 24px">
                    <i class="fas fa-star me-2"></i>Save Trade Quality Score
                </button>
            </form>
        </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Habit toggle logic ────────────────────────────────────────────────────────
document.querySelectorAll('.habit-toggle').forEach(function(tog) {
    tog.addEventListener('change', function() {
        var code  = this.dataset.code;
        var card  = document.getElementById('hcard_' + code);
        var sevEl = document.getElementById('sev_' + code);
        if (this.checked) {
            card.classList.add('active');
            sevEl.classList.add('visible');
            // Default severity = 2 if not set
            if (!document.getElementById('sev_val_' + code).value) {
                document.getElementById('sev_val_' + code).value = 2;
                sevEl.querySelectorAll('.sev-btn').forEach(function(b){ if(b.dataset.sev=='2'){ selectSev(b, code, 2); }});
            }
        } else {
            card.classList.remove('active');
            sevEl.classList.remove('visible');
        }
        computePreviews();
    });
});

// ── Severity button logic ─────────────────────────────────────────────────────
document.querySelectorAll('.sev-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var code = this.dataset.code;
        var sev  = parseInt(this.dataset.sev);
        selectSev(this, code, sev);
        computePreviews();
    });
});

function selectSev(btn, code, sev) {
    var container = document.getElementById('sev_' + code);
    container.querySelectorAll('.sev-btn').forEach(function(b) {
        b.classList.remove('selected-mild','selected-moderate','selected-severe');
    });
    var cls = sev === 1 ? 'selected-mild' : (sev === 3 ? 'selected-severe' : 'selected-moderate');
    btn.classList.add(cls);
    document.getElementById('sev_val_' + code).value = sev;
}

// ── Live score preview rings ──────────────────────────────────────────────────
var previewCharts = {};

function initPreviewRing(id, isDark) {
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var trackColor = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)';
    previewCharts[id] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [0, 100],
                backgroundColor: [trackColor, trackColor],
                borderWidth: 0,
                hoverOffset: 0,
            }]
        },
        options: {
            cutout: '70%',
            animation: { duration: 400 },
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            events: [],
        }
    });
}

function updatePreviewRing(id, score, color) {
    var chart = previewCharts[id];
    if (!chart) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var trackColor = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)';
    chart.data.datasets[0].data = [score, 100 - score];
    chart.data.datasets[0].backgroundColor = [color, trackColor];
    chart.update();
    var txt = document.getElementById(id + '_txt');
    if (txt) { txt.textContent = score; txt.style.color = color; }
}

function getScoreColor(s) {
    if (s >= 70) return '#16a34a';
    if (s >= 50) return '#d97706';
    return '#dc2626';
}

function computePreviews() {
    // Gather habits
    var triggered = [];
    var severity  = {};
    document.querySelectorAll('.habit-toggle').forEach(function(tog) {
        if (tog.checked) {
            var code = tog.dataset.code;
            triggered.push(code);
            var sev = parseInt(document.getElementById('sev_val_' + code).value) || 2;
            severity[code] = sev;
        }
    });

    // Gather emotion
    var emoEl = document.querySelector('input[name="pre_emotion"]:checked');
    var emo = emoEl ? emoEl.value : null;

    // Gather reflections
    var refl = {
        followed_plan:   document.getElementById('ref_followed_plan')   ? document.getElementById('ref_followed_plan').checked   : false,
        emotional_entry: document.getElementById('ref_emotional_entry') ? document.getElementById('ref_emotional_entry').checked : false,
        emotional_exit:  document.getElementById('ref_emotional_exit')  ? document.getElementById('ref_emotional_exit').checked  : false,
        forced_trade:    document.getElementById('ref_forced_trade')    ? document.getElementById('ref_forced_trade').checked    : false,
        entered_early:   document.getElementById('ref_entered_early')   ? document.getElementById('ref_entered_early').checked   : false,
        had_patience:    document.getElementById('ref_had_patience')    ? document.getElementById('ref_had_patience').checked    : false,
        followed_rules:  document.getElementById('ref_followed_rules')  ? document.getElementById('ref_followed_rules').checked  : false,
    };

    var disc = calcDisciplineJS(triggered, severity, refl);
    var psych = calcPsychologyJS(emo, refl);
    var emo_s = calcStabilityJS(emo, triggered, refl);

    updatePreviewRing('prev_disc',  disc,  getScoreColor(disc));
    updatePreviewRing('prev_psych', psych, getScoreColor(psych));
    updatePreviewRing('prev_emo',   emo_s, getScoreColor(emo_s));
}

function calcDisciplineJS(triggered, severity, refl) {
    var s = 100;
    triggered.forEach(function(code) {
        var sev = severity[code] || 2;
        s -= sev === 1 ? 5 : sev === 3 ? 20 : 10;
    });
    if (!refl.followed_plan)   s -= 10;
    if (refl.emotional_entry)  s -= 10;
    if (refl.emotional_exit)   s -= 10;
    if (refl.forced_trade)     s -= 15;
    if (!refl.had_patience)    s -= 5;
    if (!refl.followed_rules)  s -= 15;
    return Math.max(0, s);
}

function calcPsychologyJS(emo, refl) {
    var bases = {calm:100,confident:90,fear:50,greedy:50,angry:40,fomo:30,revenge:20};
    var s = emo ? (bases[emo] || 70) : 70;
    if (refl.had_patience)   s += 10;
    if (refl.followed_plan)  s += 10;
    if (refl.emotional_entry) s -= 15;
    if (refl.emotional_exit)  s -= 15;
    if (refl.forced_trade)    s -= 20;
    if (refl.entered_early)   s -= 10;
    return Math.max(0, Math.min(100, s));
}

function calcStabilityJS(emo, triggered, refl) {
    var emoMod = {calm:0,confident:0,fear:-15,greedy:-15,angry:-25,fomo:-30,revenge:-30};
    var s = 100 + (emo ? (emoMod[emo] || -5) : 0);
    if (triggered.indexOf('revenge_trading') !== -1)    s -= 25;
    if (triggered.indexOf('fear_of_levels')  !== -1)    s -= 15;
    if (triggered.indexOf('hope_trading')    !== -1)    s -= 10;
    if (triggered.indexOf('trading_after_loss') !== -1) s -= 20;
    if (refl.emotional_entry) s -= 15;
    if (refl.emotional_exit)  s -= 15;
    if (refl.entered_early)   s -= 10;
    if (refl.forced_trade)    s -= 10;
    if (refl.had_patience)    s += 10;
    return Math.max(0, Math.min(100, s));
}

// ── Trade quality slider ──────────────────────────────────────────────────────
function updateSlider(name, val) {
    var display = document.getElementById('sl_' + name + '_val');
    if (display) display.textContent = val;
    computeTQScore();
}

function computeTQScore() {
    var weights = {
        setup_quality: 2.0, risk_management: 2.0, rr_quality: 1.5,
        rule_following: 1.5, emotional_control: 1.5, patience: 1.0, sl_discipline: 0.5
    };
    var score = 0;
    for (var k in weights) {
        var el = document.getElementById('sl_' + k);
        if (el) score += (parseInt(el.value) / 10) * (weights[k] * 10);
    }
    score = Math.round(Math.min(100, Math.max(0, score)));
    var disp = document.getElementById('tq_score_display');
    var bar  = document.getElementById('tq_score_bar');
    if (disp) { disp.textContent = score; disp.style.color = getScoreColor(score); }
    if (bar)  { bar.style.width = score + '%'; bar.style.background = getScoreColor(score); }
}

// ── Reflection cards listen for changes ──────────────────────────────────────
document.querySelectorAll('.reflection-card input[type=checkbox]').forEach(function(cb) {
    cb.addEventListener('change', computePreviews);
});
document.querySelectorAll('input[name="pre_emotion"]').forEach(function(r) {
    r.addEventListener('change', computePreviews);
});

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    initPreviewRing('prev_disc',  isDark);
    initPreviewRing('prev_psych', isDark);
    initPreviewRing('prev_emo',   isDark);
    computePreviews();
    computeTQScore();
});
</script>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../config/db.php';
require_once '../includes/psych_helpers.php';
$pageTitle = 'Psychology Tracker';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
$today     = date('Y-m-d');
$db        = getDB();

// ── Today's psychology entry ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM psych_daily WHERE user_id=? AND entry_date=?");
$stmt->execute([$userId, $today]);
$todayEntry = $stmt->fetch();

$habitsTriggered = $todayEntry ? (json_decode($todayEntry['habits_triggered'] ?? '[]', true) ?: []) : [];
$habitSeverity   = $todayEntry ? (json_decode($todayEntry['habit_severity']   ?? '{}', true) ?: []) : [];

$discScore  = $todayEntry['discipline_score']    ?? null;
$psychScore = $todayEntry['psychology_score']    ?? null;
$emoStab    = $todayEntry['emotional_stability'] ?? null;

// ── Today's trade quality avg ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT AVG(overall_score) as avg_tq, COUNT(*) as cnt FROM psych_trade_quality WHERE user_id=? AND entry_date=?");
$stmt->execute([$userId, $today]);
$tqRow = $stmt->fetch();
$tradeQuality = ($tqRow['cnt'] > 0) ? (int)round($tqRow['avg_tq']) : null;

// ── Live rule validation from trades ─────────────────────────────────────────
$balance = getCurrentBalance($userId);

$stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl
    FROM trades WHERE user_id=? AND DATE(trade_datetime)=?");
$stmt->execute([$userId, $today]);
$todayTrades = $stmt->fetch();
$todayTradeCount = (int)$todayTrades['cnt'];
$todayNetPL      = (float)$todayTrades['net_pl'];

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM trades WHERE user_id=? AND DATE(trade_datetime)=?
    AND (sl_amount IS NULL OR sl_amount = 0)");
$stmt->execute([$userId, $today]);
$noSlCount = (int)$stmt->fetch()['cnt'];

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM trades WHERE user_id=? AND DATE(trade_datetime)=?
    AND sl_amount > 0 AND tp_amount > 0 AND (tp_amount / sl_amount) < 2");
$stmt->execute([$userId, $today]);
$rrViolationCount = (int)$stmt->fetch()['cnt'];

$dailyLossPct = ($balance > 0 && $todayNetPL < 0) ? round(abs($todayNetPL) / $balance * 100, 2) : 0;

$ruleAlerts = [];
if ($todayTradeCount > MAX_TRADES_PER_DAY)
    $ruleAlerts[] = ['type' => 'warning', 'icon' => 'fas fa-layer-group',
        'msg' => "Over Trading: {$todayTradeCount} trades today (limit: " . MAX_TRADES_PER_DAY . ")"];
if ($dailyLossPct >= 4.0)
    $ruleAlerts[] = ['type' => 'critical', 'icon' => 'fas fa-skull-crossbones',
        'msg' => "STOP TRADING NOW — Daily loss {$dailyLossPct}% exceeds the 4% maximum"];
elseif ($dailyLossPct >= 3.0)
    $ruleAlerts[] = ['type' => 'warning', 'icon' => 'fas fa-triangle-exclamation',
        'msg' => "Daily loss {$dailyLossPct}% — approaching the 4% maximum limit"];
if ($noSlCount > 0)
    $ruleAlerts[] = ['type' => 'warning', 'icon' => 'fas fa-shield-halved',
        'msg' => "Stop Loss Violation: {$noSlCount} trade(s) entered without a stop loss"];
if ($rrViolationCount > 0)
    $ruleAlerts[] = ['type' => 'warning', 'icon' => 'fas fa-scale-balanced',
        'msg' => "RR Violation: {$rrViolationCount} trade(s) with reward:risk below 1:2"];

// ── Risk engine for trading lock ─────────────────────────────────────────────
$risk = getRiskMetrics($userId);
$tradingLocked = $risk['breach_daily'] || $dailyLossPct >= 4.0;

// ── Habit clean streaks (last 30 days) ───────────────────────────────────────
$habitDefs  = getHabitDefs();
$habitStreak = [];
foreach (array_keys($habitDefs) as $code) {
    $habitStreak[$code] = getPsychStreak($userId, $code, $db);
}

// ── Weekly averages ──────────────────────────────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$stmt = $db->prepare("SELECT AVG(discipline_score) as avg_d, AVG(psychology_score) as avg_p,
    AVG(emotional_stability) as avg_e, COUNT(*) as cnt
    FROM psych_daily WHERE user_id=? AND entry_date >= ?");
$stmt->execute([$userId, $weekStart]);
$weekAvg = $stmt->fetch();

// ── Coach feedback ────────────────────────────────────────────────────────────
$reflections = $todayEntry ? [
    'followed_plan'   => (bool)$todayEntry['followed_plan'],
    'emotional_entry' => (bool)$todayEntry['emotional_entry'],
    'emotional_exit'  => (bool)$todayEntry['emotional_exit'],
    'forced_trade'    => (bool)$todayEntry['forced_trade'],
    'entered_early'   => (bool)$todayEntry['entered_early'],
    'had_patience'    => (bool)$todayEntry['had_patience'],
    'followed_rules'  => (bool)$todayEntry['followed_rules'],
] : [];

$ruleStatsForFeedback = [
    'over_trade_count'   => max(0, $todayTradeCount - MAX_TRADES_PER_DAY),
    'no_sl_count'        => $noSlCount,
    'rr_violation_count' => $rrViolationCount,
];

$coachMsg = $todayEntry['coach_feedback'] ??
    generateCoachFeedback($habitsTriggered, $todayEntry['pre_emotion'] ?? null, $reflections, $ruleStatsForFeedback);

// ── Calendar — month data ─────────────────────────────────────────────────────
$monthYear  = isset($_GET['m']) ? $_GET['m'] : date('Y-m');
[$calYr, $calMo] = explode('-', $monthYear);
$calYr = (int)$calYr; $calMo = (int)$calMo;
$monthStart = sprintf('%04d-%02d-01', $calYr, $calMo);
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$prevM = date('Y-m', mktime(0,0,0,$calMo-1,1,$calYr));
$nextM = date('Y-m', mktime(0,0,0,$calMo+1,1,$calYr));

$stmt = $db->prepare(
    "SELECT entry_date, discipline_score, psychology_score, emotional_stability,
            habits_triggered, pre_emotion, followed_plan, followed_rules
     FROM psych_daily WHERE user_id=? AND entry_date BETWEEN ? AND ?
     ORDER BY entry_date ASC"
);
$stmt->execute([$userId, $monthStart, $monthEnd]);
$calRows = $stmt->fetchAll();
$calMap  = [];
foreach ($calRows as $r) $calMap[$r['entry_date']] = $r;

// ── Trade stats per day for the calendar month ────────────────────────────────
$stmt = $db->prepare(
    "SELECT DATE(trade_datetime) as trade_date,
            COUNT(*) as trade_count,
            COALESCE(SUM(profit_loss - brokerage + swap), 0) as net_pl,
            COALESCE(SUM(brokerage), 0) as total_brokerage,
            COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) as wins,
            COALESCE(SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END), 0) as losses
     FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?
     GROUP BY DATE(trade_datetime)"
);
$stmt->execute([$userId, $monthStart, $monthEnd]);
$tradeMap = [];
foreach ($stmt->fetchAll() as $tr) $tradeMap[$tr['trade_date']] = $tr;

// Month trade totals for summary bar
$monthTotalTrades    = array_sum(array_column($tradeMap, 'trade_count'));
$monthTotalNetPL     = array_sum(array_column($tradeMap, 'net_pl'));
$monthTotalBrokerage = array_sum(array_column($tradeMap, 'total_brokerage'));

// Month summary stats
$calGreenStreak = 0; $calRedStreak = 0; $calGreenDays = 0; $calTotalDays = 0;
$calGreenStreakCount = true; $calRedStreakCount = true;
// Use last 60 days for streaks (not just this month)
$stmt = $db->prepare(
    "SELECT entry_date, discipline_score, habits_triggered
     FROM psych_daily WHERE user_id=? AND entry_date <= ? ORDER BY entry_date DESC LIMIT 60"
);
$stmt->execute([$userId, $today]);
$recentPsych = $stmt->fetchAll();
foreach ($recentPsych as $rp) {
    $rScore   = (int)($rp['discipline_score'] ?? 0);
    $rHabits  = json_decode($rp['habits_triggered'] ?? '[]', true) ?: [];
    $rMark    = getPsychDayMark($rp);
    $calTotalDays++;
    if (in_array($rMark, ['green','star'])) {
        $calGreenDays++;
        if ($calGreenStreakCount) $calGreenStreak++;
        $calRedStreakCount = true;
    } elseif (in_array($rMark, ['red','stop'])) {
        if ($calRedStreakCount) $calRedStreak++;
        $calGreenStreakCount = false;
    } else {
        $calGreenStreakCount = false;
        $calRedStreakCount   = false;
    }
}
$calDisciplinePct = $calTotalDays > 0 ? round($calGreenDays / $calTotalDays * 100) : 0;

// ── Flash message ─────────────────────────────────────────────────────────────
$flashMsg  = $_GET['msg']  ?? '';
$flashType = $_GET['type'] ?? 'success';

include '../includes/header.php';

// Pre-compute month stats (used in HTML)
$monthEntries = count($calRows);
$monthGreen   = count(array_filter($calRows, fn($r) => in_array(getPsychDayMark($r), ['green','star'])));
$monthRed     = count(array_filter($calRows, fn($r) => in_array(getPsychDayMark($r), ['red','stop'])));
$monthYellow  = count(array_filter($calRows, fn($r) => getPsychDayMark($r) === 'yellow'));
$monthAvgDisc = $monthEntries > 0 ? round(array_sum(array_column($calRows, 'discipline_score')) / $monthEntries) : 0;
?>

<style>
/* ═══════════════════════════════════════════════════
   Psychology Tracker — Premium UI
═══════════════════════════════════════════════════ */

/* Gradient section label */
.pt-section-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 14px;
}
.pt-section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ── Score Ring Cards ── */
.score-ring-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px 14px 16px;
    text-align: center;
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
}
.score-ring-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0,0,0,.15);
}
.score-ring-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 18px 18px 0 0;
}
.score-ring-card.score-great::before  { background: linear-gradient(90deg,#16a34a,#22c55e); }
.score-ring-card.score-good::before   { background: linear-gradient(90deg,#2563eb,#60a5fa); }
.score-ring-card.score-fair::before   { background: linear-gradient(90deg,#d97706,#fbbf24); }
.score-ring-card.score-poor::before   { background: linear-gradient(90deg,#dc2626,#f87171); }
.score-ring-card.score-none::before   { background: var(--border); }

.ring-wrap { position:relative; width:100px; height:100px; margin: 0 auto 10px; }
.ring-wrap canvas { display:block; }
.ring-center {
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%);
    text-align:center; line-height:1.15;
}
.ring-val  { font-size:20px; font-weight:800; font-family:'DM Mono',monospace; }
.ring-lbl2 { font-size:9px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.08em; }
.ring-title { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:2px; }
.ring-sub2  { font-size:10px; color:var(--text-muted); }

/* ── Hero Today Banner ── */
.today-hero {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px 24px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.today-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(37,99,235,.04) 0%, rgba(99,102,241,.03) 100%);
    pointer-events: none;
}
.hero-stat {
    text-align: center;
    padding: 12px 16px;
    border-radius: 12px;
    background: var(--bg-base);
    border: 1px solid var(--border);
    flex: 1;
    min-width: 90px;
}
.hero-stat .hs-val {
    font-size: 20px;
    font-weight: 800;
    font-family: 'DM Mono', monospace;
    line-height: 1.1;
    margin-bottom: 3px;
}
.hero-stat .hs-lbl {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .07em;
    font-weight: 600;
}
.hero-stat .hs-sub {
    font-size: 9px;
    color: var(--text-muted);
    margin-top: 2px;
}

/* ── Rule Alerts ── */
.alert-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 13px 18px;
    border-radius: 12px;
    margin-bottom: 8px;
    border-left: 4px solid transparent;
}
.alert-row.is-warning {
    background: rgba(217,119,6,.08);
    border-left-color: #d97706;
}
.alert-row.is-critical {
    background: rgba(220,38,38,.1);
    border-left-color: #ef4444;
    animation: pulse-border 1.8s ease-in-out infinite;
}
.alert-row.is-ok {
    background: rgba(22,163,74,.07);
    border-left-color: #22c55e;
}
@keyframes pulse-border {
    0%,100% { border-left-color: #ef4444; }
    50%      { border-left-color: #fca5a5; }
}
.alert-icon-box {
    width: 34px; height: 34px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

/* ── Habit Cards ── */
.habit-tile {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: transform .18s, box-shadow .18s, border-color .18s;
    cursor: default;
    position: relative;
    overflow: hidden;
}
.habit-tile::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    border-radius: 14px 0 0 14px;
}
.habit-tile.ht-clean   { border-color: rgba(22,163,74,.3); }
.habit-tile.ht-clean::before   { background: #22c55e; }
.habit-tile.ht-triggered { border-color: rgba(220,38,38,.4); background: rgba(220,38,38,.03); }
.habit-tile.ht-triggered::before { background: #ef4444; }
.habit-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }

.ht-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.ht-info  { flex: 1; min-width: 0; }
.ht-name  { font-size: 12px; font-weight: 700; margin-bottom: 3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ht-meta  { font-size: 11px; color: var(--text-muted); display:flex; align-items:center; gap:5px; }
.streak-bar {
    height: 3px;
    border-radius: 99px;
    background: var(--border);
    margin-top: 5px;
    overflow: hidden;
}
.streak-bar-fill { height: 100%; border-radius: 99px; transition: width .6s ease; }
.ht-badge {
    font-size: 10px; font-weight: 800;
    padding: 3px 9px; border-radius: 20px;
    flex-shrink: 0; white-space: nowrap;
}

/* ── Coach Card ── */
.coach-premium {
    background: var(--bg-card);
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 18px;
    padding: 22px 24px;
    position: relative;
    overflow: hidden;
}
.coach-premium::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(37,99,235,.05), rgba(99,102,241,.04));
    pointer-events: none;
}
.coach-premium .quote-mark {
    font-size: 52px;
    line-height: 1;
    color: rgba(99,102,241,.15);
    font-family: Georgia, serif;
    position: absolute;
    top: 8px; left: 16px;
    pointer-events: none;
}
.coach-avatar2 {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(37,99,235,.35);
}
.coach-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 800; letter-spacing: .08em;
    text-transform: uppercase;
    color: #818cf8;
    background: rgba(99,102,241,.1);
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 20px;
    padding: 3px 10px;
    margin-bottom: 8px;
}
.coach-msg {
    font-size: 14px;
    line-height: 1.8;
    color: var(--text-primary);
    font-style: italic;
}

/* ── Week Stats Card ── */
.week-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px;
    height: 100%;
}
.ws-metric {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
}
.ws-metric:last-child { border-bottom: none; }
.ws-icon-box {
    width: 34px; height: 34px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.ws-metric .ws-num {
    font-size: 18px; font-weight: 800;
    font-family: 'DM Mono', monospace;
    line-height: 1;
}
.ws-metric .ws-name { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

/* ── Calendar ── */
.cal-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 24px;
}
.streak-pill {
    display: inline-flex; align-items: center; gap: 7px;
    border-radius: 20px; padding: 6px 14px;
    font-size: 12px; font-weight: 700;
}
.cal-dow {
    text-align: center;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .08em;
    color: var(--text-muted);
    padding: 6px 0;
}
.cal-cell-link {
    border-radius: 10px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: flex-start;
    padding: 6px 4px;
    text-decoration: none;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
    position: relative;
    min-height: 78px;
}
.cal-cell-link:hover {
    transform: scale(1.07);
    z-index: 20;
    box-shadow: 0 8px 24px rgba(0,0,0,.18);
}
.cal-day-num {
    font-size: 11px; font-weight: 800;
    line-height: 1;
}
.cal-mark-emoji { font-size: 12px; line-height: 1; }
.cal-trade-info { width: 100%; text-align: center; margin-top: 2px; }
.cal-trades-count { font-size: 9px; font-weight: 700; color: var(--text-muted); line-height:1; }
.cal-pl { font-size: 9px; font-weight: 800; line-height:1; margin-top: 1px; }
.cal-brok { font-size: 8px; color: var(--text-muted); line-height:1; margin-top:1px; }

/* ── Summary stat chips ── */
.month-stat-chip {
    display: flex; flex-direction: column; align-items: center;
    background: var(--bg-base);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 18px;
    min-width: 100px;
    text-align: center;
    flex: 1;
}
.month-stat-chip .msc-val {
    font-size: 20px; font-weight: 800;
    font-family: 'DM Mono', monospace;
    line-height: 1.1;
    margin-bottom: 3px;
}
.month-stat-chip .msc-lbl {
    font-size: 9px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .07em; font-weight: 700;
}

/* ── Lock Overlay ── */
.lock-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(7, 10, 20, 0.97);
    display: flex; align-items: center; justify-content: center;
    flex-direction: column; text-align: center; padding: 40px;
}
.lock-pulse-ring {
    width: 120px; height: 120px;
    border-radius: 50%;
    background: rgba(239,68,68,.12);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 28px;
    animation: lock-ring-pulse 2s ease-in-out infinite;
    position: relative;
}
.lock-pulse-ring::before {
    content: '';
    position: absolute; inset: -8px;
    border-radius: 50%;
    border: 2px solid rgba(239,68,68,.3);
    animation: lock-ring-pulse 2s ease-in-out infinite .3s;
}
@keyframes lock-ring-pulse {
    0%,100% { transform: scale(1); opacity: 1; }
    50%      { transform: scale(1.05); opacity: .7; }
}
</style>

<?php if ($tradingLocked): ?>
<!-- ═══════ Trading Lock Overlay ═══════ -->
<div class="lock-overlay" id="lockOverlay">
    <div class="lock-pulse-ring">
        <i class="fas fa-lock" style="font-size:48px;color:#ef4444"></i>
    </div>
    <div style="font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#f87171;margin-bottom:12px">
        Capital Protection Mode
    </div>
    <h1 style="color:#fff;font-size:2rem;font-weight:800;margin-bottom:14px;line-height:1.2">
        Trading Session Locked
    </h1>
    <p style="color:#94a3b8;font-size:1rem;max-width:480px;line-height:1.8;margin-bottom:6px">
        You have reached your maximum daily loss limit.
        <strong style="color:#fca5a5">Protect capital first.</strong>
    </p>
    <p style="color:#64748b;font-size:.875rem;margin-bottom:30px">
        The session resets at midnight. Use this time to review and reset mentally.
    </p>
    <div style="font-size:3rem;font-family:'DM Mono',monospace;color:#f87171;margin-bottom:30px;font-weight:800;letter-spacing:.05em" id="lockCountdown"></div>
    <button class="btn btn-outline-secondary px-5" onclick="document.getElementById('lockOverlay').style.display='none'" style="border-radius:10px;font-weight:700">
        <i class="fas fa-eye-slash me-2"></i>Acknowledge & Review
    </button>
</div>
<script>
(function(){
    function tick(){
        var now=new Date(), mid=new Date(); mid.setHours(24,0,0,0);
        var d=Math.max(0,Math.floor((mid-now)/1000));
        var el=document.getElementById('lockCountdown');
        if(el) el.textContent=String(Math.floor(d/3600)).padStart(2,'0')+':'+String(Math.floor(d%3600/60)).padStart(2,'0')+':'+String(d%60).padStart(2,'0');
    }
    tick(); setInterval(tick,1000);
})();
</script>
<?php endif; ?>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success'?'success':'danger' ?> alert-dismissible fade show mb-3" role="alert" id="flashMsg">
    <?= htmlspecialchars($flashMsg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<script>setTimeout(function(){var e=document.getElementById('flashMsg');if(e)e.classList.remove('show');},3500);</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
════════════════════════════════════════════════════════ -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-3">
    <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
            <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#6366f1);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(37,99,235,.35)">
                <i class="fas fa-head-side-brain" style="color:#fff;font-size:16px"></i>
            </div>
            <h4 style="font-size:1.25rem;font-weight:800;margin:0;letter-spacing:-.01em">
                Psychology & Discipline
            </h4>
        </div>
        <div style="font-size:12px;color:var(--text-muted);padding-left:46px">
            <?= date('l, d F Y') ?> &nbsp;·&nbsp;
            <?php if ($todayEntry): ?>
                <span style="color:#22c55e"><i class="fas fa-circle-check me-1"></i>Entry logged for today</span>
            <?php else: ?>
                <span style="color:#f59e0b"><i class="fas fa-circle me-1"></i>No entry yet today</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="psych_daily.php" class="btn btn-primary" style="border-radius:10px;font-weight:700;padding:8px 18px">
            <i class="fas fa-file-pen me-2"></i>Log Today
        </a>
        <a href="psych_analytics.php" class="btn btn-outline-secondary" style="border-radius:10px;font-weight:600;padding:8px 16px">
            <i class="fas fa-chart-mixed me-2"></i>Analytics
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     TODAY'S HERO BANNER
════════════════════════════════════════════════════════ -->
<div class="today-hero mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <!-- Emotion badge -->
        <div style="display:flex;align-items:center;gap:12px">
            <div style="font-size:36px;line-height:1">
                <?= getEmotionEmoji($todayEntry['pre_emotion'] ?? null) ?>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted)">Today's Mindset</div>
                <div style="font-size:17px;font-weight:800;margin-top:1px">
                    <?= $todayEntry ? ucfirst($todayEntry['pre_emotion'] ?? 'Not logged') : 'Not logged' ?>
                </div>
                <?php if ($tradingLocked): ?>
                <div style="font-size:11px;color:#ef4444;font-weight:700;margin-top:3px"><i class="fas fa-lock me-1"></i>Session Locked</div>
                <?php elseif (!empty($ruleAlerts)): ?>
                <div style="font-size:11px;color:#f59e0b;font-weight:700;margin-top:3px"><i class="fas fa-triangle-exclamation me-1"></i><?= count($ruleAlerts) ?> alert<?= count($ruleAlerts)>1?'s':'' ?> today</div>
                <?php else: ?>
                <div style="font-size:11px;color:#22c55e;font-weight:700;margin-top:3px"><i class="fas fa-circle-check me-1"></i>Clean session</div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Live stats -->
        <div class="d-flex gap-2 flex-wrap" style="flex:1;justify-content:flex-end">
            <div class="hero-stat">
                <div class="hs-val" style="color:<?= $todayTradeCount > MAX_TRADES_PER_DAY ? 'var(--loss)':'var(--text-primary)' ?>"><?= $todayTradeCount ?></div>
                <div class="hs-lbl">Trades</div>
                <div class="hs-sub">Limit <?= MAX_TRADES_PER_DAY ?></div>
            </div>
            <div class="hero-stat">
                <div class="hs-val" style="color:<?= $todayNetPL>=0?'var(--profit)':'var(--loss)' ?>"><?= formatPL($todayNetPL) ?></div>
                <div class="hs-lbl">Net P&amp;L</div>
                <div class="hs-sub"><?= $dailyLossPct ?>% loss used</div>
            </div>
            <div class="hero-stat">
                <div class="hs-val" style="color:<?= $noSlCount>0?'var(--loss)':'var(--profit)' ?>"><?= $noSlCount ?></div>
                <div class="hs-lbl">No SL Trades</div>
                <div class="hs-sub"><?= $noSlCount>0?'⚠ Fix this':'✓ All good' ?></div>
            </div>
            <div class="hero-stat">
                <div class="hs-val" style="color:<?= $rrViolationCount>0?'var(--warning)':'var(--profit)' ?>"><?= $rrViolationCount ?></div>
                <div class="hs-lbl">RR Breaks</div>
                <div class="hs-sub">Below 1:2</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SCORE RINGS
════════════════════════════════════════════════════════ -->
<?php
$kpis = [
    ['disc_ring',  'Discipline',          $discScore,    'Habit adherence',       'fas fa-shield-halved'],
    ['psych_ring', 'Psychology',          $psychScore,   'Mental state quality',  'fas fa-brain'],
    ['emo_ring',   'Emotional Stability', $emoStab,      'Emotional control',     'fas fa-heart-pulse'],
    ['tq_ring',    'Trade Quality',       $tradeQuality, 'Avg quality score',     'fas fa-star'],
];
?>
<div class="pt-section-label"><i class="fas fa-gauge-high"></i> Today's Scores</div>
<div class="row g-3 mb-4">
    <?php foreach ($kpis as [$rid, $label, $val, $sub, $icon]):
        $sc   = $val !== null ? getScoreColor($val) : 'var(--text-muted)';
        $slbl = $val !== null ? getScoreLabel($val) : 'No Entry';
        $cls  = $val === null ? 'score-none' : ($val >= 70 ? 'score-great' : ($val >= 50 ? 'score-good' : ($val >= 30 ? 'score-fair' : 'score-poor')));
    ?>
    <div class="col-6 col-lg-3">
        <div class="score-ring-card <?= $cls ?>">
            <div class="ring-wrap">
                <canvas id="<?= $rid ?>" width="100" height="100"></canvas>
                <div class="ring-center">
                    <?php if ($val !== null): ?>
                        <div class="ring-val" style="color:<?= $sc ?>"><?= $val ?></div>
                        <div class="ring-lbl2"><?= $slbl ?></div>
                    <?php else: ?>
                        <div style="font-size:22px;color:var(--text-muted);font-weight:700;font-family:'DM Mono',monospace">—</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ring-title"><?= $label ?></div>
            <div class="ring-sub2"><i class="<?= $icon ?> me-1"></i><?= $sub ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     RULE ALERTS
════════════════════════════════════════════════════════ -->
<div class="pt-section-label"><i class="fas fa-shield-halved"></i> Rule Validation — Today</div>
<div class="mb-4">
    <?php if (!empty($ruleAlerts)):
        foreach ($ruleAlerts as $al):
            $isCrit = $al['type'] === 'critical';
            $iconBg  = $isCrit ? 'rgba(220,38,38,.15)' : 'rgba(217,119,6,.15)';
            $iconClr = $isCrit ? '#ef4444' : '#d97706';
    ?>
    <div class="alert-row <?= $isCrit ? 'is-critical' : 'is-warning' ?>">
        <div class="alert-icon-box" style="background:<?= $iconBg ?>">
            <i class="<?= $al['icon'] ?>" style="color:<?= $iconClr ?>"></i>
        </div>
        <div style="flex:1">
            <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($al['msg']) ?></div>
        </div>
        <span style="font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;background:<?= $iconBg ?>;color:<?= $iconClr ?>;text-transform:uppercase;letter-spacing:.07em">
            <?= $isCrit ? 'Critical' : 'Warning' ?>
        </span>
    </div>
    <?php endforeach;
    else: ?>
    <div class="alert-row is-ok">
        <div class="alert-icon-box" style="background:rgba(22,163,74,.12)">
            <i class="fas fa-circle-check" style="color:#22c55e"></i>
        </div>
        <div>
            <div style="font-size:13px;font-weight:600;color:var(--text-primary)">All rules followed — no violations detected today</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Stay disciplined and keep up the great work</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     CALENDAR
════════════════════════════════════════════════════════ -->
<div class="pt-section-label"><i class="fas fa-calendar-days"></i> Monthly Discipline Calendar</div>
<div class="cal-card">

    <!-- Cal header: nav + streaks -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="?m=<?= $prevM ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-chevron-left" style="font-size:11px"></i>
            </a>
            <span style="font-size:15px;font-weight:800;min-width:120px;text-align:center"><?= date('F Y', strtotime($monthStart)) ?></span>
            <?php if ($nextM <= date('Y-m')): ?>
            <a href="?m=<?= $nextM ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-chevron-right" style="font-size:11px"></i>
            </a>
            <?php else: ?>
            <span class="btn btn-sm btn-outline-secondary disabled" style="border-radius:8px;width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center;opacity:.3">
                <i class="fas fa-chevron-right" style="font-size:11px"></i>
            </span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="streak-pill" style="background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.3);color:#22c55e">
                <i class="fas fa-fire-flame-curved"></i><?= $calGreenStreak ?>d streak
            </span>
            <?php if ($calRedStreak > 0): ?>
            <span class="streak-pill" style="background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.3);color:#ef4444">
                <i class="fas fa-triangle-exclamation"></i><?= $calRedStreak ?>d breaks
            </span>
            <?php endif; ?>
            <span class="streak-pill" style="background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.25);color:var(--accent)">
                <i class="fas fa-bullseye"></i><?= $calDisciplinePct ?>% disciplined
            </span>
        </div>
    </div>

    <!-- Legend -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <?php foreach ([
            ['#f59e0b','⭐','Perfect'],
            ['#22c55e','✅','Rules OK'],
            ['#eab308','⚠️','Minor'],
            ['#ef4444','❌','Broke'],
            ['#7c3aed','⛔','Breakdown'],
        ] as [$lc, $le, $ll]): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted)">
            <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?= $lc ?>;flex-shrink:0"></span>
            <?= $le ?> <?= $ll ?>
        </span>
        <?php endforeach; ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted)">
            <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:var(--border);flex-shrink:0"></span>
            No entry
        </span>
    </div>

    <!-- Day headers -->
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:4px">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?>
        <div class="cal-dow"><?= $dn ?></div>
        <?php endforeach; ?>
    </div>

    <!-- Calendar cells -->
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">
        <?php
        $markCfg = [
            'star'  => ['bg'=>'rgba(245,158,11,.18)','border'=>'#f59e0b','txt'=>'#f59e0b','emoji'=>'⭐','lbl'=>'Perfect'],
            'green' => ['bg'=>'rgba(34,197,94,.14)', 'border'=>'#22c55e','txt'=>'#16a34a','emoji'=>'✅','lbl'=>'Rules OK'],
            'yellow'=> ['bg'=>'rgba(234,179,8,.14)', 'border'=>'#eab308','txt'=>'#ca8a04','emoji'=>'⚠️','lbl'=>'Minor'],
            'red'   => ['bg'=>'rgba(239,68,68,.14)', 'border'=>'#ef4444','txt'=>'#dc2626','emoji'=>'❌','lbl'=>'Broke'],
            'stop'  => ['bg'=>'rgba(124,58,237,.14)','border'=>'#7c3aed','txt'=>'#7c3aed','emoji'=>'⛔','lbl'=>'Breakdown'],
        ];

        $firstDow    = (int)date('N', strtotime($monthStart));
        $daysInMonth = (int)date('t', strtotime($monthStart));
        for ($i = 1; $i < $firstDow; $i++) echo '<div></div>';

        for ($d = 1; $d <= $daysInMonth; $d++):
            $ds      = sprintf('%04d-%02d-%02d', $calYr, $calMo, $d);
            $isToday = $ds === $today;
            $isFut   = $ds > $today;
            $rec     = $calMap[$ds] ?? null;
            $mark    = $rec ? getPsychDayMark($rec) : 'none';
            $cfg     = $markCfg[$mark] ?? null;
            $td      = $tradeMap[$ds] ?? null;

            $tCnt   = $td ? (int)$td['trade_count'] : 0;
            $tPL    = $td ? (float)$td['net_pl'] : 0.0;
            $tBrok  = $td ? (float)$td['total_brokerage'] : 0.0;
            $plClr  = $tPL > 0 ? '#16a34a' : ($tPL < 0 ? '#dc2626' : 'var(--text-muted)');
            $plSign = $tPL > 0 ? '+' : '';

            $tip = date('d M Y', strtotime($ds));
            if ($cfg) $tip .= ' — ' . $cfg['lbl'] . ' (D:' . ($rec['discipline_score']??'—') . ')';
            $cs_psy = getActiveCurrency()['symbol'];
            if ($tCnt > 0) { $tip .= "\n" . $tCnt . ' trade' . ($tCnt>1?'s':'') . ' · P/L: ' . $plSign . $cs_psy . number_format(abs($tPL),2); }
            if ($tBrok > 0) $tip .= ' · Brok: ' . $cs_psy . number_format($tBrok,2);
            if ($rec && $rec['pre_emotion']) $tip .= "\nEmotion: " . ucfirst($rec['pre_emotion']);
        ?>

        <?php if ($isFut): ?>
            <div style="min-height:78px;border-radius:10px;border:1px solid transparent;display:flex;align-items:center;justify-content:center;opacity:.18;font-size:11px;font-weight:700;color:var(--text-muted)"><?= $d ?></div>
        <?php else: ?>
            <a href="psych_daily.php?date=<?= $ds ?>" title="<?= htmlspecialchars($tip) ?>"
               class="cal-cell-link"
               style="background:<?= $cfg?$cfg['bg']:'var(--bg-base)' ?>;
                      border:<?= $cfg?'1.5px solid '.$cfg['border']:'1px solid var(--border)' ?>;
                      <?= $isToday?'outline:2px solid var(--accent);outline-offset:2px;':'' ?>">

                <!-- Top row: emoji + day number -->
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;padding:0 2px">
                    <span class="cal-mark-emoji"><?= $cfg ? $cfg['emoji'] : '' ?></span>
                    <span class="cal-day-num" style="color:<?= $cfg?$cfg['txt']:'var(--text-muted)' ?>"><?= $d ?></span>
                </div>

                <?php if ($tCnt > 0): ?>
                <div class="cal-trade-info">
                    <div class="cal-trades-count"><?= $tCnt ?>T</div>
                    <div class="cal-pl" style="color:<?= $plClr ?>"><?= $plSign ?><?= $cs_psy.number_format(abs($tPL),0) ?></div>
                    <?php if ($tBrok > 0): ?>
                    <div class="cal-brok">B:<?= $cs_psy ?><?= number_format($tBrok,0) ?></div>
                    <?php endif; ?>
                </div>
                <?php elseif (!$cfg): ?>
                <div style="font-size:8px;color:var(--border);margin-top:auto;padding-bottom:4px"><?= $isToday?'●':'+add' ?></div>
                <?php endif; ?>

            </a>
        <?php endif; ?>
        <?php endfor; ?>
    </div>

    <!-- Month summary -->
    <div class="mt-4 pt-3" style="border-top:1px solid var(--border)">
        <!-- Discipline row -->
        <?php if ($monthEntries > 0): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span style="font-size:11px;color:var(--text-muted);font-weight:600"><?= $monthEntries ?> entries</span>
            <span style="width:1px;height:14px;background:var(--border)"></span>
            <span style="font-size:11px;font-weight:700;color:#22c55e"><?= $monthGreen ?> ✅ followed</span>
            <span style="font-size:11px;font-weight:700;color:#eab308"><?= $monthYellow ?> ⚠️ minor</span>
            <span style="font-size:11px;font-weight:700;color:#ef4444"><?= $monthRed ?> ❌ broke</span>
            <span style="width:1px;height:14px;background:var(--border)"></span>
            <span style="font-size:11px;color:var(--text-muted)">Avg discipline: <strong style="color:<?= getScoreColor($monthAvgDisc) ?>"><?= $monthAvgDisc ?></strong></span>
        </div>
        <?php endif; ?>
        <!-- Trade stats chips -->
        <?php if ($monthTotalTrades > 0):
            $tradingDays = count($tradeMap);
        ?>
        <div class="d-flex flex-wrap gap-2">
            <div class="month-stat-chip">
                <div class="msc-val"><?= $monthTotalTrades ?></div>
                <div class="msc-lbl">Total Trades</div>
            </div>
            <div class="month-stat-chip">
                <div class="msc-val" style="color:<?= $monthTotalNetPL>=0?'#16a34a':'#dc2626' ?>"><?= formatPL($monthTotalNetPL) ?></div>
                <div class="msc-lbl">Net P&amp;L</div>
            </div>
            <div class="month-stat-chip">
                <div class="msc-val" style="color:var(--warning)">-<?= formatUSD($monthTotalBrokerage) ?></div>
                <div class="msc-lbl">Brokerage</div>
            </div>
            <div class="month-stat-chip">
                <div class="msc-val"><?= $tradingDays ?></div>
                <div class="msc-lbl">Trading Days</div>
            </div>
            <div class="month-stat-chip">
                <div class="msc-val"><?= $tradingDays>0?round($monthTotalTrades/$tradingDays,1):0 ?></div>
                <div class="msc-lbl">Avg Trades/Day</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════
     BAD HABIT TRACKER
════════════════════════════════════════════════════════ -->
<?php $triggeredCount = count($habitsTriggered); ?>
<div class="pt-section-label">
    <i class="fas fa-heart-pulse"></i> Bad Habit Tracker
    <span style="font-size:10px;font-weight:700;padding:2px 10px;border-radius:20px;margin-left:4px;background:<?= $triggeredCount>0?'rgba(220,38,38,.12)':'rgba(22,163,74,.12)' ?>;color:<?= $triggeredCount>0?'var(--loss)':'var(--profit)' ?>">
        <?= $triggeredCount ?> triggered today
    </span>
</div>
<div class="row g-2 mb-4">
    <?php foreach ($habitDefs as $code => $def):
        $isTriggered = in_array($code, $habitsTriggered);
        $sev         = (int)($habitSeverity[$code] ?? 0);
        $sevLabel    = match($sev) { 1=>'Mild', 2=>'Moderate', 3=>'Severe', default=>'' };
        $sevColor    = match($sev) { 1=>'#22c55e', 2=>'#f59e0b', 3=>'#ef4444', default=>'var(--text-muted)' };
        $streak      = $habitStreak[$code] ?? 0;
        $streakPct   = min(100, $streak * 3);
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="habit-tile <?= $isTriggered ? 'ht-triggered' : 'ht-clean' ?>">
            <div class="ht-icon" style="background:<?= $isTriggered?'rgba(220,38,38,.1)':'rgba(22,163,74,.1)' ?>;color:<?= $isTriggered?'var(--loss)':'var(--profit)' ?>">
                <i class="<?= $def['icon'] ?>"></i>
            </div>
            <div class="ht-info">
                <div class="ht-name" title="<?= htmlspecialchars($def['desc']) ?>"><?= $def['label'] ?></div>
                <div class="ht-meta">
                    <?php if ($isTriggered): ?>
                        <i class="fas fa-circle-xmark" style="color:var(--loss);font-size:10px"></i>
                        <span>Triggered</span>
                        <?php if ($sev): ?><span style="color:<?= $sevColor ?>;font-weight:700">· <?= $sevLabel ?></span><?php endif; ?>
                    <?php else: ?>
                        <i class="fas fa-fire-flame-curved" style="color:#f59e0b;font-size:10px"></i>
                        <span><?= $streak ?> clean day<?= $streak!==1?'s':'' ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$isTriggered): ?>
                <div class="streak-bar">
                    <div class="streak-bar-fill" style="width:<?= $streakPct ?>%;background:<?= $streak>=20?'#22c55e':($streak>=7?'#f59e0b':'#94a3b8') ?>"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($isTriggered): ?>
                <span class="ht-badge" style="background:rgba(220,38,38,.12);color:var(--loss)"><?= $sevLabel?:'Active' ?></span>
            <?php else: ?>
                <span class="ht-badge" style="background:rgba(22,163,74,.1);color:var(--profit)">✓ Clean</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     COACH FEEDBACK + WEEK STATS
════════════════════════════════════════════════════════ -->
<div class="pt-section-label"><i class="fas fa-comment-dots"></i> AI Coach & This Week</div>
<div class="row g-3 mb-4">

    <!-- Coach -->
    <div class="col-12 col-lg-8">
        <div class="coach-premium h-100">
            <span class="quote-mark">"</span>
            <div class="d-flex align-items-start gap-14" style="gap:14px;position:relative">
                <div class="coach-avatar2">🧠</div>
                <div style="flex:1">
                    <span class="coach-badge"><i class="fas fa-sparkles"></i> AI Coach</span>
                    <p class="coach-msg"><?= nl2br(htmlspecialchars($coachMsg)) ?></p>
                    <?php if ($todayEntry && $todayEntry['notes']): ?>
                    <div style="margin-top:12px;padding:10px 14px;background:var(--bg-base);border-radius:8px;font-size:12px;color:var(--text-muted);border-left:3px solid var(--border)">
                        <i class="fas fa-sticky-note me-1"></i><?= nl2br(htmlspecialchars($todayEntry['notes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- This week -->
    <div class="col-12 col-lg-4">
        <div class="week-card">
            <div style="font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:14px">
                <i class="fas fa-calendar-week me-1"></i> This Week
            </div>
            <?php if ($weekAvg['cnt'] > 0):
                $wMetrics = [
                    ['fas fa-shield-halved','rgba(37,99,235,.1)','var(--accent)', 'Discipline', round($weekAvg['avg_d'])],
                    ['fas fa-brain',        'rgba(99,102,241,.1)','#818cf8',      'Psychology',  round($weekAvg['avg_p'])],
                    ['fas fa-heart-pulse',  'rgba(34,197,94,.1)', '#22c55e',      'Stability',   round($weekAvg['avg_e'])],
                    ['fas fa-calendar-check','rgba(245,158,11,.1)','#f59e0b',     'Entries',     $weekAvg['cnt']],
                ];
                foreach ($wMetrics as [$wi, $wb, $wc, $wn, $wv]):
            ?>
            <div class="ws-metric">
                <div class="ws-icon-box" style="background:<?= $wb ?>">
                    <i class="<?= $wi ?>" style="color:<?= $wc ?>"></i>
                </div>
                <div style="flex:1">
                    <div class="ws-name"><?= $wn ?></div>
                </div>
                <div class="ws-num" style="color:<?= $wn==='Entries'?'var(--text-primary)':getScoreColor((int)$wv) ?>"><?= $wv ?><?= $wn==='Entries'?'':'<span style="font-size:11px;font-weight:500;color:var(--text-muted)">/100</span>' ?></div>
            </div>
            <?php endforeach;
            else: ?>
            <div style="text-align:center;padding:30px 0;color:var(--text-muted)">
                <i class="fas fa-calendar-xmark" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3"></i>
                <div style="font-size:13px">No entries this week yet</div>
                <a href="psych_daily.php" class="btn btn-sm btn-primary mt-3" style="border-radius:8px">Log Today</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var track  = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)';

    function ring(id, score, color) {
        var c = document.getElementById(id);
        if (!c) return;
        new Chart(c.getContext('2d'), {
            type: 'doughnut',
            data: { datasets: [{ data: score!==null?[score,100-score]:[0,100], backgroundColor: score!==null?[color,track]:[track,track], borderWidth:0 }] },
            options: { cutout:'74%', animation:{animateRotate:true,duration:1100}, plugins:{legend:{display:false},tooltip:{enabled:false}}, events:[] }
        });
    }

    <?php foreach ($kpis as [$rid, , $val, , ]):
        $col = $val !== null ? getScoreColor($val) : '#94a3b8';
        $jsv = $val !== null ? $val : 'null';
    ?>
    ring('<?= $rid ?>', <?= $jsv ?>, '<?= $col ?>');
    <?php endforeach; ?>
})();
</script>

<?php include '../includes/footer.php'; ?>

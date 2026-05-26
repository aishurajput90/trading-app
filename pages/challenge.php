<?php
require_once '../config/db.php';
require_once '../includes/challenge_helpers.php';

$pageTitle = 'Discipline Challenge';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();
$db        = getDB();
$today     = date('Y-m-d');
$msg       = '';
$msgType   = '';

// POST: Create challenge
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_challenge') {
    $duration  = max(1, (int)($_POST['duration_days'] ?? 30));
    $startDate = $today;
    $endDate   = date('Y-m-d', strtotime("+{$duration} days -1 day", strtotime($startDate)));
    $startCap  = (float)($_POST['starting_capital'] ?? getCurrentBalance($userId));
    $maxRisk   = (float)($_POST['max_risk_per_trade_pct'] ?? 2.0);
    $maxDailyL = (float)($_POST['max_daily_loss_pct'] ?? 5.0);
    $maxTrades = max(1, (int)($_POST['max_trades_per_day'] ?? 3));
    $minRR     = (float)($_POST['min_risk_reward'] ?? 2.0);
    $sessStart = trim($_POST['session_start'] ?? '') ?: null;
    $sessEnd   = trim($_POST['session_end']   ?? '') ?: null;
    $title     = trim($_POST['challenge_title'] ?? '') ?: 'My Discipline Challenge';

    $db->prepare("UPDATE challenges SET status='abandoned' WHERE user_id=? AND status='active'")->execute([$userId]);
    $db->prepare("INSERT INTO challenges (user_id, title, duration_days, start_date, end_date, starting_capital, max_risk_per_trade_pct, max_daily_loss_pct, max_trades_per_day, min_risk_reward, session_start, session_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$userId, $title, $duration, $startDate, $endDate, $startCap, $maxRisk, $maxDailyL, $maxTrades, $minRR, $sessStart, $sessEnd]);
    header('Location: challenge.php');
    exit;
}

// POST: Abandon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'abandon_challenge') {
    $cid = (int)$_POST['challenge_id'];
    $db->prepare("UPDATE challenges SET status='abandoned' WHERE id=? AND user_id=?")->execute([$cid, $userId]);
    header('Location: challenge.php');
    exit;
}

// Fetch active challenge
$stmt = $db->prepare("SELECT * FROM challenges WHERE user_id=? AND status='active' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId]);
$active = $stmt->fetch();

// All challenges for history
$allStmt = $db->prepare("SELECT c.*, COALESCE(AVG(cd.discipline_score),0) as avg_score, COUNT(cd.id) as days_logged FROM challenges c LEFT JOIN challenge_days cd ON cd.challenge_id=c.id AND cd.result IS NOT NULL WHERE c.user_id=? GROUP BY c.id ORDER BY c.created_at DESC");
$allStmt->execute([$userId]);
$allChallenges = $allStmt->fetchAll();

$viewMode = $_GET['view'] ?? 'default';

// Dashboard data when active challenge exists
$days = []; $dayMap = [];
$streak = 0; $completionPct = 0; $avgScore = 0;
$chartLabels = $chartEquity = $chartScore = $chartPL = [];
$emoTotals = array_fill_keys(['Fear','FOMO','Confidence','Revenge','Calm','Overtrading','Patience','Greed','Neutral'], 0);
$badges = []; $levelData = ['level'=>1,'name'=>'Beginner','pct'=>0,'next_xp'=>500,'xp_in_level'=>0,'xp_needed'=>500];
$recentBroke = 0; $todayRec = null;

if ($active) {
    $dStmt = $db->prepare("SELECT * FROM challenge_days WHERE challenge_id=? ORDER BY day_date ASC");
    $dStmt->execute([$active['id']]);
    $days = $dStmt->fetchAll();
    foreach ($days as $d) $dayMap[$d['day_date']] = $d;

    $startTs     = strtotime($active['start_date']);
    $todayTs     = strtotime($today);
    $elapsedDays = min((int)floor(($todayTs - $startTs) / 86400) + 1, $active['duration_days']);
    $submitted   = array_filter($days, fn($d) => $d['result'] !== null);
    $completionPct = $elapsedDays > 0 ? round(count($submitted) / $elapsedDays * 100) : 0;

    // Streak
    $checkDate = $today;
    while (true) {
        $rec = $dayMap[$checkDate] ?? null;
        if ($rec && $rec['result'] === 'followed') { $streak++; $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day')); }
        else break;
    }

    // Avg score
    $avgScore = count($submitted) > 0 ? round(array_sum(array_column(array_values($submitted), 'discipline_score')) / count($submitted)) : 0;

    // Today's record
    $todayRec = $dayMap[$today] ?? null;

    // Chart data
    $equity = (float)$active['starting_capital'];
    foreach ($days as $d) {
        if ($d['result'] === null) continue;
        $equity += $d['daily_pl'];
        $chartLabels[] = 'D' . $d['day_number'];
        $chartEquity[] = round($equity, 2);
        $chartScore[]  = $d['discipline_score'];
        $chartPL[]     = (float)$d['daily_pl'];
    }

    // Emotion totals
    foreach ($days as $d) {
        foreach (json_decode($d['emotions'] ?? '[]', true) ?: [] as $e) {
            if (isset($emoTotals[$e])) $emoTotals[$e]++;
        }
    }

    // Recent broke streak
    foreach (array_reverse($days) as $d) {
        if ($d['result'] === 'broke') $recentBroke++;
        else break;
    }

    $badges    = computeBadges($days, $active);
    $levelData = getLevelFromXP((int)$active['total_xp']);

    // Check if challenge ended
    if ($today > $active['end_date'] && $active['status'] === 'active') {
        $db->prepare("UPDATE challenges SET status='completed' WHERE id=?")->execute([$active['id']]);
        $active['status'] = 'completed';
    }
}

$badgeDefs = getBadgeDefs();

$quotes = [
    "Focus on execution, not outcome.",
    "Small losses keep you alive.",
    "Consistency beats intensity.",
    "One good trade at a time.",
    "Your job is not to predict. Your job is to manage risk and execute consistently.",
    "The best traders are not the most intelligent — they are the most disciplined.",
    "A loss accepted gracefully is a lesson learned. A loss denied is capital destroyed.",
    "Your edge is not your setup. It is your consistency in executing it.",
    "Protect capital first. Profits follow discipline.",
    "No trade is better than a bad trade.",
    "Patience is the most profitable position.",
    "Every green day is built on a thousand small decisions.",
    "Fear of missing out is just fear wearing a disguise.",
    "The market does not owe you money. Your system does — if you follow it.",
    "Professional traders do not predict. They react and manage.",
    "Drawdowns are the tuition fee. Lessons are the return.",
];
$quote = $quotes[date('z') % count($quotes)];

include '../includes/header.php';
?>
<style>
.chal-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:24px 28px;transition:border-color .2s}
.chal-card.glass{background:linear-gradient(135deg,rgba(59,130,246,.07),rgba(124,58,237,.07));border-color:rgba(59,130,246,.25);backdrop-filter:blur(10px)}
.chal-setup-hero{background:linear-gradient(135deg,rgba(59,130,246,.08) 0%,rgba(124,58,237,.08) 100%);border:1px solid rgba(59,130,246,.2);border-radius:20px;padding:40px;text-align:center}
.chal-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;margin-top:10px}
.chal-cal-cell{aspect-ratio:1;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;border:1.5px solid var(--border);color:var(--text-muted);text-decoration:none;transition:transform .15s,border-color .15s;font-family:'DM Mono',monospace}
a.chal-cal-cell:hover{transform:scale(1.12);border-color:var(--accent)}
.chal-cal-cell.followed{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.5);color:#22c55e}
.chal-cal-cell.broke{background:rgba(248,113,113,.15);border-color:rgba(248,113,113,.5);color:#f87171}
.chal-cal-cell.no_trade{background:rgba(148,163,184,.1);border-color:rgba(148,163,184,.3);color:#94a3b8}
.chal-cal-cell.today-cell{border-color:var(--accent) !important;box-shadow:0 0 0 2px rgba(59,130,246,.25)}
.chal-cal-cell.future{opacity:.22;pointer-events:none}
.chal-cal-cell.pending-link{background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.3);color:var(--accent)}
.badge-grid{display:flex;flex-wrap:wrap;gap:10px}
.badge-item{display:flex;align-items:center;gap:10px;padding:11px 15px;border-radius:12px;border:1.5px solid var(--border);background:var(--bg-base);min-width:200px;flex:1}
.badge-item.earned{border-color:#fbbf24;background:rgba(251,191,36,.06)}
.badge-item.unearned{opacity:.45}
.badge-icon{font-size:1.4rem;min-width:28px;text-align:center}
.level-bar-wrap{background:var(--bg-base);border-radius:10px;height:9px;overflow:hidden;margin-top:8px}
.level-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),#8b5cf6);border-radius:10px;transition:width .4s ease}
.xp-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:800;font-family:'DM Mono',monospace;background:rgba(139,92,246,.12);color:#8b5cf6;border:1px solid rgba(139,92,246,.3)}
.quote-box{background:linear-gradient(135deg,rgba(59,130,246,.06),rgba(124,58,237,.06));border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:18px 22px}
.warn-banner{border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:10px;font-size:.88rem;font-weight:600;margin-bottom:10px}
.warn-banner.danger{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35);color:#f87171}
.warn-banner.caution{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35);color:#fbbf24}
.progress-challenge{height:10px;background:var(--bg-base);border-radius:10px;overflow:hidden;margin:8px 0}
.progress-challenge-fill{height:100%;background:linear-gradient(90deg,var(--accent),#22c55e);border-radius:10px;transition:width .5s ease}
.today-card{background:var(--bg-card);border:1.5px solid var(--border);border-radius:14px;padding:20px;text-align:center}
.today-card.done-followed{border-color:#22c55e;background:rgba(34,197,94,.06)}
.today-card.done-broke{border-color:#f87171;background:rgba(248,113,113,.06)}
.today-card.done-no_trade{border-color:#94a3b8;background:rgba(148,163,184,.06)}
.dur-radio{display:none}
.dur-label{display:inline-flex;align-items:center;justify-content:center;padding:10px 20px;border-radius:10px;border:2px solid var(--border);cursor:pointer;font-weight:700;font-size:.9rem;transition:.15s;color:var(--text-secondary);min-width:70px}
.dur-radio:checked+.dur-label{border-color:var(--accent);background:rgba(59,130,246,.1);color:var(--accent)}
.history-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.hb-active{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)}
.hb-completed{background:rgba(59,130,246,.12);color:var(--accent);border:1px solid rgba(59,130,246,.3)}
.hb-abandoned{background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.25)}
.chal-stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
.chal-stat-value{font-family:'DM Mono',monospace;font-size:1.8rem;font-weight:700;line-height:1}
.chal-stat-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-top:4px;font-weight:600}
</style>

<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="container-fluid px-3 px-md-4 py-4">

<?php if ($viewMode === 'history'): ?>
<!-- ═══════════════ HISTORY VIEW ═══════════════ -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold mb-0" style="color:var(--text-primary)"><i class="fas fa-trophy me-2" style="color:var(--accent)"></i>Challenge History</h5>
        <div style="font-size:.82rem;color:var(--text-muted);margin-top:2px">All discipline challenges</div>
    </div>
    <a href="challenge.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?php if (empty($allChallenges)): ?>
<div class="chal-card text-center py-5">
    <div style="font-size:3rem;margin-bottom:12px">🏆</div>
    <div style="font-size:1.1rem;font-weight:700;color:var(--text-primary)">No challenges yet</div>
    <div style="color:var(--text-muted);margin-top:6px"><a href="challenge.php">Start your first challenge</a></div>
</div>
<?php else: ?>
<div class="chal-card p-0" style="overflow:hidden">
<table class="table table-hover mb-0" style="font-size:.88rem">
    <thead><tr style="background:var(--bg-base);border-bottom:1px solid var(--border)">
        <th class="px-4 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Challenge</th>
        <th class="px-3 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Duration</th>
        <th class="px-3 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Dates</th>
        <th class="px-3 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Avg Score</th>
        <th class="px-3 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Days Logged</th>
        <th class="px-3 py-3" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Status</th>
        <th class="px-3 py-3"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($allChallenges as $ch): ?>
    <tr style="border-bottom:1px solid var(--border)">
        <td class="px-4 py-3" style="font-weight:700;color:var(--text-primary)"><?= htmlspecialchars($ch['title']) ?></td>
        <td class="px-3 py-3" style="color:var(--text-secondary)"><?= $ch['duration_days'] ?> days</td>
        <td class="px-3 py-3" style="color:var(--text-muted);font-size:.8rem;font-family:'DM Mono',monospace"><?= date('M j', strtotime($ch['start_date'])) ?> – <?= date('M j, Y', strtotime($ch['end_date'])) ?></td>
        <td class="px-3 py-3"><span style="font-family:'DM Mono',monospace;font-weight:700;color:<?= $ch['avg_score']>=70?'var(--profit)':($ch['avg_score']>=40?'var(--warning)':'var(--loss)') ?>"><?= round($ch['avg_score']) ?></span></td>
        <td class="px-3 py-3" style="color:var(--text-secondary)"><?= $ch['days_logged'] ?> / <?= $ch['duration_days'] ?></td>
        <td class="px-3 py-3"><span class="history-badge hb-<?= $ch['status'] ?>"><?= ucfirst($ch['status']) ?></span></td>
        <td class="px-3 py-3 text-end"><a href="challenge_report.php?challenge_id=<?= $ch['id'] ?>" class="btn btn-xs btn-outline-secondary" style="font-size:.78rem;padding:4px 12px">Report</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php elseif (!$active): ?>
<!-- ═══════════════ SETUP FORM ═══════════════ -->
<div class="row justify-content-center">
<div class="col-xl-8 col-lg-10">

<div class="chal-setup-hero mb-4">
    <div style="font-size:3.5rem;margin-bottom:12px">🏆</div>
    <h2 style="font-size:1.8rem;font-weight:800;color:var(--text-primary);margin-bottom:8px">Trading Discipline Challenge</h2>
    <p style="color:var(--text-secondary);font-size:1rem;max-width:500px;margin:0 auto 20px">
        Commit to a structured challenge. Follow your rules every day. Build the discipline that separates professionals from gamblers.
    </p>
    <div style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:14px 20px;display:inline-block;font-size:.88rem;color:var(--text-secondary);font-style:italic">
        "Your job is not to predict the market. Your job is to manage risk and execute consistently."
    </div>
</div>

<div class="chal-card">
    <div style="font-size:1.1rem;font-weight:800;color:var(--text-primary);margin-bottom:20px"><i class="fas fa-flag me-2" style="color:var(--accent)"></i>Start a New Challenge</div>
    <form method="POST">
                    <?= csrfField() ?>
        <input type="hidden" name="action" value="create_challenge">

        <div class="mb-4">
            <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:8px">Challenge Title</label>
            <input type="text" name="challenge_title" class="form-control" placeholder="e.g. My 30-Day Discipline Run" value="My Discipline Challenge" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-primary);border-radius:9px">
        </div>

        <div class="mb-4">
            <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:10px">Challenge Duration</label>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <?php foreach ([7,14,30,60] as $d): ?>
                <input type="radio" name="duration_days" id="dur_<?= $d ?>" value="<?= $d ?>" class="dur-radio" <?= $d===30?'checked':'' ?>>
                <label for="dur_<?= $d ?>" class="dur-label"><?= $d ?> Days</label>
                <?php endforeach; ?>
                <input type="radio" name="duration_days" id="dur_custom" value="" class="dur-radio" id="dur_custom_radio">
                <label for="dur_custom_radio" class="dur-label" style="cursor:pointer" onclick="document.getElementById('customDaysInput').style.display='inline-block';document.getElementById('dur_custom_radio').value=document.getElementById('customDaysVal').value||'30'">Custom</label>
                <input type="number" id="customDaysVal" min="1" max="365" placeholder="days" style="display:none;width:90px;padding:9px 12px;background:var(--bg-elevated);border:1.5px solid var(--accent);border-radius:10px;color:var(--text-primary);font-weight:700;font-size:.9rem" id="customDaysInput" oninput="document.getElementById('dur_custom_radio').value=this.value">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Starting Capital ($)</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-muted)">$</span>
                    <input type="number" name="starting_capital" class="form-control" step="0.01" value="<?= number_format(getCurrentBalance($userId), 2, '.', '') ?>" style="background:var(--bg-elevated);border:1px solid var(--border);border-left:none;color:var(--text-primary)">
                </div>
            </div>
            <div class="col-md-6">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Max Risk / Trade (%)</label>
                <div class="input-group">
                    <input type="number" name="max_risk_per_trade_pct" class="form-control" step="0.1" min="0.1" max="100" value="2.0" style="background:var(--bg-elevated);border:1px solid var(--border);border-right:none;color:var(--text-primary)">
                    <span class="input-group-text" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-muted)">%</span>
                </div>
            </div>
            <div class="col-md-6">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Max Daily Loss (%)</label>
                <div class="input-group">
                    <input type="number" name="max_daily_loss_pct" class="form-control" step="0.1" min="0.1" max="100" value="5.0" style="background:var(--bg-elevated);border:1px solid var(--border);border-right:none;color:var(--text-primary)">
                    <span class="input-group-text" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-muted)">%</span>
                </div>
            </div>
            <div class="col-md-6">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Max Trades / Day</label>
                <input type="number" name="max_trades_per_day" class="form-control" min="1" max="50" value="3" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-primary)">
            </div>
            <div class="col-md-6">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Min Risk:Reward</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-muted)">1:</span>
                    <input type="number" name="min_risk_reward" class="form-control" step="0.1" min="0.1" value="2.0" style="background:var(--bg-elevated);border:1px solid var(--border);border-left:none;color:var(--text-primary)">
                </div>
            </div>
            <div class="col-md-3">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Session Start</label>
                <input type="time" name="session_start" class="form-control" value="09:00" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-primary)">
            </div>
            <div class="col-md-3">
                <label style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;display:block;margin-bottom:6px">Session End</label>
                <input type="time" name="session_end" class="form-control" value="17:00" style="background:var(--bg-elevated);border:1px solid var(--border);color:var(--text-primary)">
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-between align-items-center mt-4 pt-3" style="border-top:1px solid var(--border)">
            <?php if (!empty($allChallenges)): ?>
            <a href="?view=history" style="font-size:.85rem;color:var(--text-muted)"><i class="fas fa-clock-rotate-left me-1"></i>View History</a>
            <?php else: ?><span></span><?php endif; ?>
            <button type="submit" class="btn btn-primary px-5" style="border-radius:10px;font-weight:700;padding:12px 32px;font-size:.95rem">
                <i class="fas fa-rocket me-2"></i>Start Challenge
            </button>
        </div>
    </form>
</div>

<div class="mt-4 chal-card" style="background:var(--bg-base);border-style:dashed">
    <div style="font-size:.78rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;margin-bottom:12px">What you'll track each day</div>
    <div class="row g-2">
        <?php foreach (['Pre-trade checklist (8 rules)','Daily result: Follow / Break / No Trade','Emotional state tracking','Journal: what worked, what didn\'t','Discipline score (0-100)','XP points & level progression','Streak tracking','Badges & milestones'] as $item): ?>
        <div class="col-md-6" style="font-size:.85rem;color:var(--text-secondary)"><i class="fas fa-check me-2" style="color:var(--accent)"></i><?= $item ?></div>
        <?php endforeach; ?>
    </div>
</div>

</div></div>

<?php else: ?>
<!-- ═══════════════ DASHBOARD ═══════════════ -->

<!-- Page header -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0" style="color:var(--text-primary)"><?= htmlspecialchars($active['title']) ?></h5>
        <div style="font-size:.82rem;color:var(--text-muted);margin-top:3px">
            <span style="font-family:'DM Mono',monospace"><?= date('M j', strtotime($active['start_date'])) ?></span>
            <span class="mx-1">→</span>
            <span style="font-family:'DM Mono',monospace"><?= date('M j, Y', strtotime($active['end_date'])) ?></span>
            <span class="mx-2">·</span><?= $active['duration_days'] ?> days
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="?view=history" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clock-rotate-left me-1"></i>History</a>
        <a href="challenge_report.php?challenge_id=<?= $active['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-lines me-1"></i>Report</a>
        <form method="POST" class="d-inline" onsubmit="return confirm('Abandon this challenge? This cannot be undone.')">
                    <?= csrfField() ?>
            <input type="hidden" name="action" value="abandon_challenge">
            <input type="hidden" name="challenge_id" value="<?= $active['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-xmark me-1"></i>Abandon</button>
        </form>
    </div>
</div>

<!-- Smart warnings -->
<?php if ($recentBroke >= 2): ?>
<div class="warn-banner danger mb-3"><i class="fas fa-triangle-exclamation fa-lg"></i><div><strong>Rule streak broken <?= $recentBroke ?> days in a row.</strong> Take a step back. Review what rule you keep breaking before trading again.</div></div>
<?php endif; ?>
<?php if (!empty($active) && getCurrentBalance($userId) < $active['starting_capital'] * 0.90): ?>
<div class="warn-banner caution mb-3"><i class="fas fa-shield-halved fa-lg"></i><div><strong>Equity is down more than 10% from your starting capital.</strong> Protect capital first. Consider reducing position sizes or taking a break.</div></div>
<?php endif; ?>
<?php if ($active['status'] === 'completed'): ?>
<div class="warn-banner" style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#22c55e;margin-bottom:12px"><i class="fas fa-trophy fa-lg"></i><div><strong>Challenge complete!</strong> <a href="challenge_report.php?challenge_id=<?= $active['id'] ?>" style="color:#22c55e">View your final report</a> to see your performance analysis and badges earned.</div></div>
<?php endif; ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="chal-stat-card" style="border-top:3px solid #f97316">
            <div class="chal-stat-value" style="color:#f97316"><?= $streak ?> 🔥</div>
            <div class="chal-stat-label">Current Streak</div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">consecutive followed days</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="chal-stat-card" style="border-top:3px solid var(--accent)">
            <div class="chal-stat-value" style="color:var(--accent)"><?= $completionPct ?>%</div>
            <div class="chal-stat-label">Completion</div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px"><?= count(array_filter($days, fn($d) => $d['result'] !== null)) ?> / <?= $active['duration_days'] ?> days logged</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="chal-stat-card" style="border-top:3px solid <?= $avgScore>=70?'var(--profit)':($avgScore>=40?'var(--warning)':'var(--loss)') ?>">
            <div class="chal-stat-value" style="color:<?= $avgScore>=70?'var(--profit)':($avgScore>=40?'var(--warning)':'var(--loss)') ?>"><?= $avgScore ?></div>
            <div class="chal-stat-label">Avg Discipline Score</div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">out of 100</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="chal-stat-card" style="border-top:3px solid #8b5cf6">
            <div class="d-flex align-items-baseline gap-2">
                <div class="chal-stat-value" style="color:#8b5cf6">Lv<?= $levelData['level'] ?></div>
                <span class="xp-badge"><?= $active['total_xp'] ?> XP</span>
            </div>
            <div class="chal-stat-label"><?= $levelData['name'] ?></div>
            <div class="level-bar-wrap mt-2"><div class="level-bar-fill" style="width:<?= $levelData['pct'] ?>%"></div></div>
            <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px"><?= $levelData['xp_needed'] ?> XP to next level</div>
        </div>
    </div>
</div>

<!-- Progress + Today + Quote -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="chal-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div style="font-weight:700;color:var(--text-primary)"><i class="fas fa-calendar-days me-2" style="color:var(--accent)"></i>Challenge Calendar</div>
                <div style="font-size:.8rem;color:var(--text-muted)"><?= date('M j', strtotime($active['start_date'])) ?> – <?= date('M j, Y', strtotime($active['end_date'])) ?></div>
            </div>
            <!-- Overall progress bar -->
            <?php $doneCount = count(array_filter($days, fn($d)=>$d['result']!==null)); $overallPct = round($doneCount/$active['duration_days']*100); ?>
            <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;color:var(--text-muted)">
                <span><?= $doneCount ?> days logged</span>
                <span><?= $overallPct ?>% complete</span>
            </div>
            <div class="progress-challenge"><div class="progress-challenge-fill" style="width:<?= $overallPct ?>%"></div></div>

            <!-- Calendar grid -->
            <div style="display:flex;gap:6px;margin-bottom:6px;margin-top:10px;font-size:.68rem;color:var(--text-muted);font-weight:700">
                <?php foreach (['MON','TUE','WED','THU','FRI','SAT','SUN'] as $wd): ?>
                <div style="flex:1;text-align:center"><?= $wd ?></div>
                <?php endforeach; ?>
            </div>
            <?php
            $calStart = strtotime($active['start_date']);
            $startDow = (int)date('N', $calStart);
            $totalDays = $active['duration_days'];
            $calCells = $startDow - 1; // empty cells before start
            ?>
            <div class="chal-cal-grid">
                <?php for ($i = 0; $i < $calCells; $i++): ?>
                <div class="chal-cal-cell future"></div>
                <?php endfor; ?>
                <?php for ($i = 0; $i < $totalDays; $i++):
                    $dateStr = date('Y-m-d', strtotime($active['start_date'] . " +{$i} days"));
                    $isFuture = $dateStr > $today;
                    $isToday  = $dateStr === $today;
                    $rec      = $dayMap[$dateStr] ?? null;
                    $dayNum   = $i + 1;
                    $resultClass = $rec ? $rec['result'] : '';
                    $cellClass   = $isFuture ? 'future' : ($resultClass ? $resultClass : ($isToday ? 'pending-link' : 'pending-link'));
                    $label = $dayNum;
                    if ($rec && $rec['result'] === 'followed') $label = '✓';
                    elseif ($rec && $rec['result'] === 'broke') $label = '✗';
                    elseif ($rec && $rec['result'] === 'no_trade') $label = '–';
                    if ($isFuture): ?>
                    <div class="chal-cal-cell future" title="Day <?= $dayNum ?> — <?= date('M j', strtotime($dateStr)) ?>"><?= $dayNum ?></div>
                    <?php else: ?>
                    <a href="challenge_day.php?date=<?= $dateStr ?>&challenge_id=<?= $active['id'] ?>" class="chal-cal-cell <?= $cellClass ?> <?= $isToday?'today-cell':'' ?>" title="Day <?= $dayNum ?> — <?= date('M j', strtotime($dateStr)) ?>"><?= $label ?></a>
                    <?php endif; endfor; ?>
            </div>
            <!-- Legend -->
            <div class="d-flex gap-3 mt-3 flex-wrap" style="font-size:.75rem;color:var(--text-muted)">
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(34,197,94,.4);margin-right:4px"></span>Followed</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(248,113,113,.4);margin-right:4px"></span>Broke Rules</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(148,163,184,.25);margin-right:4px"></span>No Trade</span>
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(59,130,246,.2);border:1px solid rgba(59,130,246,.4);margin-right:4px"></span>Pending</span>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-3 h-100">
            <!-- Today's card -->
            <div class="today-card <?= $todayRec ? 'done-'.($todayRec['result']??'') : '' ?>">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--text-muted);margin-bottom:8px">Today · <?= date('D, M j') ?></div>
                <?php if ($todayRec && $todayRec['result']): ?>
                    <?php $icons = ['followed'=>'✅','broke'=>'❌','no_trade'=>'😴']; $labels=['followed'=>'Rules Followed','broke'=>'Broke Rules','no_trade'=>'No Trade']; ?>
                    <div style="font-size:2rem;margin-bottom:6px"><?= $icons[$todayRec['result']] ?></div>
                    <div style="font-weight:700;font-size:.95rem;color:var(--text-primary)"><?= $labels[$todayRec['result']] ?></div>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-top:4px">Score: <strong style="font-family:'DM Mono',monospace;color:var(--text-primary)"><?= $todayRec['discipline_score'] ?></strong>/100 · <?= $todayRec['xp_earned'] > 0 ? '+' : '' ?><?= $todayRec['xp_earned'] ?> XP</div>
                    <a href="challenge_day.php?date=<?= $today ?>&challenge_id=<?= $active['id'] ?>" class="btn btn-sm btn-outline-secondary mt-3 w-100" style="font-size:.82rem">Edit Today</a>
                <?php else: ?>
                    <div style="font-size:2rem;margin-bottom:8px">📋</div>
                    <div style="font-weight:700;color:var(--text-primary);margin-bottom:6px">Log Today's Entry</div>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-bottom:16px">Record your checklist, result, and journal.</div>
                    <a href="challenge_day.php?date=<?= $today ?>&challenge_id=<?= $active['id'] ?>" class="btn btn-primary w-100" style="border-radius:9px;font-weight:700"><i class="fas fa-pen me-1"></i>Start Day <?= min((int)floor((strtotime($today)-strtotime($active['start_date']))/86400)+1, $active['duration_days']) ?></a>
                <?php endif; ?>
            </div>
            <!-- Motivational quote -->
            <div class="quote-box flex-grow-1 d-flex flex-column justify-content-center">
                <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);font-weight:700;margin-bottom:8px"><i class="fas fa-quote-left me-1"></i>Today's Thought</div>
                <div style="font-size:.92rem;font-weight:600;color:var(--text-primary);line-height:1.5;font-style:italic">"<?= htmlspecialchars($quote) ?>"</div>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<?php if (!empty($chartLabels)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-chart-area me-2" style="color:var(--accent)"></i>Equity Curve</div></div>
            <div class="panel-body"><canvas id="equityChart" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-brain me-2" style="color:#8b5cf6"></i>Discipline Score Trend</div></div>
            <div class="panel-body"><canvas id="scoreChart" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-chart-bar me-2" style="color:var(--accent-cyan)"></i>Daily P/L</div></div>
            <div class="panel-body"><canvas id="plChart" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-face-smile me-2" style="color:#fbbf24"></i>Emotional Distribution</div></div>
            <div class="panel-body"><canvas id="emoChart" height="200"></canvas></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Badges -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="chal-card">
            <div style="font-weight:700;color:var(--text-primary);margin-bottom:16px"><i class="fas fa-medal me-2" style="color:#fbbf24"></i>Badges</div>
            <div class="badge-grid">
                <?php foreach ($badgeDefs as $key => $b):
                    $earned = in_array($key, $badges); ?>
                <div class="badge-item <?= $earned ? 'earned' : 'unearned' ?>">
                    <span class="badge-icon"><?= $earned ? $b['icon'] : '🔒' ?></span>
                    <div>
                        <div style="font-weight:700;font-size:.85rem;color:var(--text-primary)"><?= $b['name'] ?></div>
                        <div style="font-size:.74rem;color:var(--text-muted)"><?= $b['desc'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chal-card h-100">
            <div style="font-weight:700;color:var(--text-primary);margin-bottom:16px"><i class="fas fa-star me-2" style="color:#8b5cf6"></i>Level Progress</div>
            <div style="text-align:center;margin-bottom:16px">
                <div style="font-size:2.5rem;font-weight:900;color:#8b5cf6;font-family:'DM Mono',monospace">Lv<?= $levelData['level'] ?></div>
                <div style="font-weight:700;color:var(--text-primary)"><?= $levelData['name'] ?></div>
                <div class="xp-badge mt-2"><?= $active['total_xp'] ?> XP</div>
            </div>
            <div class="level-bar-wrap" style="height:12px"><div class="level-bar-fill" style="width:<?= $levelData['pct'] ?>%"></div></div>
            <div class="d-flex justify-content-between mt-2" style="font-size:.74rem;color:var(--text-muted)">
                <span><?= $levelData['xp_in_level'] ?> XP in level</span>
                <span><?= $levelData['xp_needed'] ?> XP to next</span>
            </div>
            <div style="margin-top:20px;font-size:.78rem;color:var(--text-muted)">
                <?php $lvls = ['Beginner','Apprentice','Consistent Trader','Disciplined Trader','Master Trader'];
                $thresholds = [0,500,1500,3500,7000];
                foreach ($lvls as $i => $lvlName): $active_lvl = $levelData['level']-1 >= $i; ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-circle-check" style="color:<?= $active_lvl?'#22c55e':'var(--border)' ?>;font-size:.7rem"></i>
                    <span style="color:<?= $active_lvl?'var(--text-secondary)':'var(--text-muted)' ?>"><?= $lvlName ?></span>
                    <span style="margin-left:auto;font-family:'DM Mono',monospace"><?= $thresholds[$i] ?>+</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Challenge settings summary -->
<div class="chal-card" style="background:var(--bg-base)">
    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700;margin-bottom:10px">Challenge Rules</div>
    <div class="d-flex flex-wrap gap-3" style="font-size:.83rem;color:var(--text-secondary)">
        <span><i class="fas fa-percent me-1" style="color:var(--accent)"></i>Max risk/trade: <strong><?= $active['max_risk_per_trade_pct'] ?>%</strong></span>
        <span><i class="fas fa-arrow-trend-down me-1" style="color:var(--loss)"></i>Max daily loss: <strong><?= $active['max_daily_loss_pct'] ?>%</strong></span>
        <span><i class="fas fa-list-ol me-1" style="color:var(--warning)"></i>Max trades/day: <strong><?= $active['max_trades_per_day'] ?></strong></span>
        <span><i class="fas fa-scale-balanced me-1" style="color:var(--profit)"></i>Min R:R: <strong>1:<?= $active['min_risk_reward'] ?></strong></span>
        <?php if ($active['session_start']): ?>
        <span><i class="fas fa-clock me-1" style="color:#8b5cf6"></i>Session: <strong><?= date('g:ia', strtotime($active['session_start'])) ?> – <?= date('g:ia', strtotime($active['session_end'])) ?></strong></span>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

</div><!-- /container -->

<?php include '../includes/footer.php'; ?>

<?php if ($active && !empty($chartLabels)):
$chartLabelsJson = json_encode($chartLabels);
$chartEquityJson = json_encode($chartEquity);
$chartScoreJson  = json_encode($chartScore);
$chartPLJson     = json_encode($chartPL);
$emoLabels = json_encode(array_keys($emoTotals));
$emoData   = json_encode(array_values($emoTotals));
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    createAreaChart('equityChart', <?= $chartLabelsJson ?>, <?= $chartEquityJson ?>, 'Equity ($)');
    createAreaChart('scoreChart',  <?= $chartLabelsJson ?>, <?= $chartScoreJson ?>,  'Discipline Score');
    createLineChart('plChart',     <?= $chartLabelsJson ?>, <?= $chartPLJson ?>,     'Daily P/L ($)');

    const emoCtx = document.getElementById('emoChart');
    if (emoCtx) {
        const tc = getChartThemeColors();
        const emoColors = ['#f87171cc','#fbbf24cc','#22c55ecc','#ef4444cc','#06b6d4cc','#f97316cc','#8b5cf6cc','#d97706cc','#94a3b8cc'];
        const c = new Chart(emoCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $emoLabels ?>,
                datasets: [{ data: <?= $emoData ?>, backgroundColor: emoColors, borderWidth: 2, hoverOffset: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '58%',
                plugins: { legend: { position: 'right', labels: { color: tc.text, font: { size: 11 }, padding: 8 }}}
            }
        });
        window._charts.push(c);
    }
});
</script>
<?php endif; ?>

<script>
// Custom days input
document.querySelectorAll('input[name="duration_days"]').forEach(function(r) {
    r.addEventListener('change', function() {
        var customIn = document.getElementById('customDaysVal');
        if (customIn) customIn.style.display = this.id === 'dur_custom_radio' ? 'inline-block' : 'none';
    });
});
</script>

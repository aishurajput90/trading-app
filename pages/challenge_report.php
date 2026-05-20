<?php
require_once '../config/db.php';
require_once '../includes/challenge_helpers.php';

$pageTitle = 'Challenge Report';
$rootPath  = '../';
$userId    = DEFAULT_USER_ID;
$db        = getDB();

$challengeId = (int)($_GET['challenge_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM challenges WHERE id=? AND user_id=?");
$stmt->execute([$challengeId, $userId]);
$challenge = $stmt->fetch();
if (!$challenge) { header('Location: challenge.php'); exit; }

$dStmt = $db->prepare("SELECT * FROM challenge_days WHERE challenge_id=? ORDER BY day_date ASC");
$dStmt->execute([$challengeId]);
$days = $dStmt->fetchAll();

$submitted  = array_filter($days, fn($d) => $d['result'] !== null);
$followed   = count(array_filter($days, fn($d) => $d['result'] === 'followed'));
$broke      = count(array_filter($days, fn($d) => $d['result'] === 'broke'));
$noTrade    = count(array_filter($days, fn($d) => $d['result'] === 'no_trade'));
$totalLogged = count($submitted);
$avgScore   = $totalLogged > 0 ? round(array_sum(array_column(array_values($submitted), 'discipline_score')) / $totalLogged) : 0;
$totalPL    = array_sum(array_column($days, 'daily_pl'));
$startCap   = (float)$challenge['starting_capital'];
$equityGrowthPct = $startCap > 0 ? round($totalPL / $startCap * 100, 2) : 0;
$totalWins  = array_sum(array_column($days, 'wins'));
$totalLosses = array_sum(array_column($days, 'losses'));
$totalTrades = array_sum(array_column($days, 'trades_count'));
$winRate    = ($totalWins + $totalLosses) > 0 ? round($totalWins / ($totalWins + $totalLosses) * 100) : 0;

$grade = match(true) {
    $avgScore >= 95 => ['letter'=>'A+','color'=>'#22c55e','label'=>'Outstanding'],
    $avgScore >= 85 => ['letter'=>'A', 'color'=>'#22c55e','label'=>'Excellent'],
    $avgScore >= 75 => ['letter'=>'B', 'color'=>'#3b82f6','label'=>'Good'],
    $avgScore >= 65 => ['letter'=>'C', 'color'=>'#fbbf24','label'=>'Average'],
    $avgScore >= 50 => ['letter'=>'D', 'color'=>'#f97316','label'=>'Needs Work'],
    default         => ['letter'=>'F', 'color'=>'#f87171','label'=>'Critical'],
};

// Max streak
$maxStreak = 0; $curStreak = 0;
foreach ($days as $d) {
    if ($d['result'] === 'followed') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
    else $curStreak = 0;
}

// Equity curve
$equityLabels = []; $equityValues = [];
$equity = $startCap;
foreach ($days as $d) {
    if ($d['result'] === null) continue;
    $equity += $d['daily_pl'];
    $equityLabels[] = 'D' . $d['day_number'];
    $equityValues[] = round($equity, 2);
}

// Discipline score trend
$scoreLabels = $equityLabels;
$scoreValues = array_column(array_values(array_filter($days, fn($d) => $d['result'] !== null)), 'discipline_score');

// Emotion totals
$emoTotals = array_fill_keys(['Fear','FOMO','Confidence','Revenge','Calm','Overtrading','Patience','Greed','Neutral'], 0);
foreach ($days as $d) {
    foreach (json_decode($d['emotions'] ?? '[]', true) ?: [] as $e) {
        if (isset($emoTotals[$e])) $emoTotals[$e]++;
    }
}

// Most broken rule
$checkCols = [
    'check_higher_tf'   => 'Higher TF Analysis',
    'check_key_levels'  => 'Marking Key Levels',
    'check_confirmation'=> 'Waiting for Confirmation',
    'check_risk_mgmt'   => 'Risk Management',
    'check_no_revenge'  => 'No Revenge Trading',
    'check_setup_only'  => 'Setup-Only Trading',
    'check_stop_loss'   => 'Using Stop Loss',
    'check_calm'        => 'Staying Calm & Disciplined',
];
$colBreaks = array_fill_keys(array_keys($checkCols), 0);
foreach ($days as $d) {
    if (!$d['checklist_submitted']) continue;
    foreach ($checkCols as $col => $label) {
        if (!$d[$col]) $colBreaks[$col]++;
    }
}
arsort($colBreaks);
$mostBroken     = array_key_first($colBreaks);
$mostBrokenCount = $colBreaks[$mostBroken] ?? 0;

// Badges
$badges    = computeBadges($days, $challenge);
$badgeDefs = getBadgeDefs();

// Recommendations
$recs = [];
if ($broke > $followed && $totalLogged > 0) $recs[] = "You broke your rules more often than you followed them. Focus on just one rule at a time in your next challenge.";
if ($mostBrokenCount >= 3) $recs[] = "Your weakest area is <strong>" . htmlspecialchars($checkCols[$mostBroken]) . "</strong> — skipped {$mostBrokenCount} times. Make this your singular focus next challenge.";
if ($avgScore < 50 && $totalLogged > 0) $recs[] = "Your average score of {$avgScore} suggests habits are still forming. Start with a shorter 7-day challenge to build momentum.";
if ($totalPL < 0) $recs[] = "Your account declined during this challenge. Remember: discipline and P/L are separate. A disciplined loss is still a win for your development.";
if ($maxStreak >= 7) $recs[] = "Your best streak of {$maxStreak} consecutive disciplined days is your real edge — use that as your baseline next challenge.";
if ($broke === 0 && $totalLogged > 5) $recs[] = "Perfect rule compliance! Next challenge, try increasing your duration to make discipline a permanent habit.";
if (empty($recs)) $recs[] = "Keep focusing on the process. Every disciplined session compounds into long-term success.";

$levelData = getLevelFromXP((int)$challenge['total_xp']);

include '../includes/header.php';
?>
<style>
.rpt-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:20px 24px}
.rpt-stat{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;text-align:center}
.rpt-stat-val{font-family:'DM Mono',monospace;font-size:1.6rem;font-weight:800;line-height:1}
.rpt-stat-lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:600;margin-top:4px}
.grade-block{text-align:center;padding:30px;border-radius:20px;border:3px solid;margin-bottom:0}
.badge-grid{display:flex;flex-wrap:wrap;gap:9px}
.badge-item{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:11px;border:1.5px solid var(--border);background:var(--bg-base);flex:1;min-width:185px}
.badge-item.earned{border-color:#fbbf24;background:rgba(251,191,36,.06)}
.badge-item.unearned{opacity:.35}
.day-table-row-followed td{background:rgba(34,197,94,.04)}
.day-table-row-broke td{background:rgba(248,113,113,.04)}
.result-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.73rem;font-weight:700}
.rp-followed{background:rgba(34,197,94,.15);color:#22c55e}
.rp-broke{background:rgba(248,113,113,.15);color:#f87171}
.rp-no_trade{background:rgba(148,163,184,.12);color:#94a3b8}
.philosophy-box{background:linear-gradient(135deg,rgba(59,130,246,.06),rgba(124,58,237,.06));border:1px solid rgba(59,130,246,.2);border-radius:14px;padding:28px;text-align:center}
.breadcrumb-link{color:var(--accent);text-decoration:none;font-size:.84rem}
</style>

<!-- Breadcrumb + actions -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div style="font-size:.84rem;color:var(--text-muted)">
        <a href="challenge.php" class="breadcrumb-link">Discipline Challenge</a>
        <span class="mx-2">›</span>
        <span style="font-weight:600;color:var(--text-secondary)"><?= htmlspecialchars($challenge['title']) ?> — Final Report</span>
    </div>
    <div class="d-flex gap-2">
        <a href="challenge.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Dashboard</a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Report header -->
<div class="rpt-card mb-4" style="background:linear-gradient(135deg,rgba(59,130,246,.06),rgba(124,58,237,.06));border-color:rgba(59,130,246,.2)">
    <div class="row align-items-center g-3">
        <div class="col-md-8">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);font-weight:700;margin-bottom:6px">Challenge Report</div>
            <h4 style="font-weight:800;color:var(--text-primary);margin-bottom:4px"><?= htmlspecialchars($challenge['title']) ?></h4>
            <div style="color:var(--text-muted);font-size:.84rem">
                <span style="font-family:'DM Mono',monospace"><?= date('M j, Y', strtotime($challenge['start_date'])) ?></span>
                <span class="mx-2">→</span>
                <span style="font-family:'DM Mono',monospace"><?= date('M j, Y', strtotime($challenge['end_date'])) ?></span>
                <span class="mx-3">·</span><?= $challenge['duration_days'] ?> days
                <span class="mx-3">·</span><?= $totalLogged ?> days logged
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <?php $sClass = ['active'=>'hb-active','completed'=>'hb-completed','abandoned'=>'hb-abandoned'][$challenge['status']] ?? ''; ?>
            <span style="display:inline-block;padding:4px 14px;border-radius:20px;font-size:.8rem;font-weight:700;
                <?= $challenge['status']==='completed' ? 'background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)' : ($challenge['status']==='active'?'background:rgba(59,130,246,.12);color:var(--accent);border:1px solid rgba(59,130,246,.3)':'background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.25)') ?>"><?= ucfirst($challenge['status']) ?></span>
            <div style="font-size:.8rem;color:var(--text-muted);margin-top:8px">Capital: <strong style="font-family:'DM Mono',monospace"><?= formatUSD($startCap) ?></strong></div>
        </div>
    </div>
</div>

<!-- Grade + 8 stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="grade-block" style="border-color:<?= $grade['color'] ?>;background:rgba(<?= $grade['color']==='#22c55e'?'34,197,94':($grade['color']==='#3b82f6'?'59,130,246':($grade['color']==='#fbbf24'?'251,191,36':($grade['color']==='#f97316'?'249,115,22':'248,113,113'))) ?>,.08)">
            <div style="font-size:5rem;font-weight:900;color:<?= $grade['color'] ?>;line-height:1;font-family:'DM Mono',monospace"><?= $grade['letter'] ?></div>
            <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-top:6px"><?= $grade['label'] ?></div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px">Avg score: <?= $avgScore ?>/100</div>
        </div>
    </div>
    <div class="col-md-9">
        <div class="row g-2">
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:var(--accent)"><?= $totalLogged ?></div><div class="rpt-stat-lbl">Days Logged</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:var(--profit)"><?= $followed ?></div><div class="rpt-stat-lbl">Followed</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:var(--loss)"><?= $broke ?></div><div class="rpt-stat-lbl">Broke Rules</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:#94a3b8"><?= $noTrade ?></div><div class="rpt-stat-lbl">No Trade</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:<?= $totalPL>=0?'var(--profit)':'var(--loss)' ?>"><?= formatPL($totalPL) ?></div><div class="rpt-stat-lbl">Total P/L</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:<?= $equityGrowthPct>=0?'var(--profit)':'var(--loss)' ?>"><?= ($equityGrowthPct>=0?'+':'') . $equityGrowthPct ?>%</div><div class="rpt-stat-lbl">Equity Growth</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:#f97316"><?= $maxStreak ?>🔥</div><div class="rpt-stat-lbl">Best Streak</div></div></div>
            <div class="col-6 col-md-3"><div class="rpt-stat"><div class="rpt-stat-val" style="color:#8b5cf6"><?= $challenge['total_xp'] ?> XP</div><div class="rpt-stat-lbl">Total XP · Lv<?= $levelData['level'] ?></div></div></div>
        </div>
    </div>
</div>

<!-- Charts -->
<?php if (!empty($equityLabels)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="panel"><div class="panel-header"><div class="panel-title"><i class="fas fa-chart-area me-2" style="color:var(--accent)"></i>Equity Curve</div></div>
        <div class="panel-body"><canvas id="rptEquityChart" height="200"></canvas></div></div>
    </div>
    <div class="col-md-6">
        <div class="panel"><div class="panel-header"><div class="panel-title"><i class="fas fa-brain me-2" style="color:#8b5cf6"></i>Discipline Score Trend</div></div>
        <div class="panel-body"><canvas id="rptScoreChart" height="200"></canvas></div></div>
    </div>
    <div class="col-md-6">
        <div class="panel"><div class="panel-header"><div class="panel-title"><i class="fas fa-face-smile me-2" style="color:#fbbf24"></i>Emotional Distribution</div></div>
        <div class="panel-body"><canvas id="rptEmoChart" height="200"></canvas></div></div>
    </div>
    <div class="col-md-6">
        <div class="panel"><div class="panel-header"><div class="panel-title"><i class="fas fa-chart-pie me-2" style="color:var(--accent-cyan,#06b6d4)"></i>Daily Results</div></div>
        <div class="panel-body"><canvas id="rptResultChart" height="200"></canvas></div></div>
    </div>
</div>
<?php endif; ?>

<!-- Badges -->
<div class="rpt-card mb-4">
    <div style="font-weight:700;color:var(--text-primary);margin-bottom:14px"><i class="fas fa-medal me-2" style="color:#fbbf24"></i>Badges Earned</div>
    <div class="badge-grid">
        <?php foreach ($badgeDefs as $key => $b):
            $earned = in_array($key, $badges); ?>
        <div class="badge-item <?= $earned?'earned':'unearned' ?>">
            <span style="font-size:1.3rem;min-width:26px;text-align:center"><?= $earned ? $b['icon'] : '🔒' ?></span>
            <div>
                <div style="font-weight:700;font-size:.83rem;color:var(--text-primary)"><?= $b['name'] ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= $b['desc'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Most broken rule -->
<?php if ($mostBrokenCount > 0): ?>
<div class="rpt-card mb-4" style="border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.04)">
    <div style="font-weight:700;color:var(--text-primary);margin-bottom:12px"><i class="fas fa-triangle-exclamation me-2" style="color:#f87171"></i>Most Broken Rule</div>
    <div style="font-size:1.1rem;font-weight:800;color:#f87171;margin-bottom:6px"><?= htmlspecialchars($checkCols[$mostBroken]) ?></div>
    <div style="color:var(--text-secondary);font-size:.88rem">Skipped <?= $mostBrokenCount ?> time<?= $mostBrokenCount !== 1 ? 's' : '' ?> across <?= $totalLogged ?> logged days.</div>
    <div style="margin-top:12px;font-size:.85rem;color:var(--text-muted)">
        <?php foreach ($colBreaks as $col => $count):
            if ($count === 0) continue;
            $pct = $totalLogged > 0 ? round($count / $totalLogged * 100) : 0; ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:160px;font-size:.78rem;color:var(--text-secondary)"><?= htmlspecialchars($checkCols[$col]) ?></div>
            <div style="flex:1;background:var(--bg-base);border-radius:8px;height:7px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col===$mostBroken?'#f87171':'var(--warning)' ?>;border-radius:8px"></div>
            </div>
            <div style="width:50px;text-align:right;font-family:'DM Mono',monospace;font-size:.75rem;color:var(--text-muted)"><?= $count ?>×</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Day-by-day table -->
<div class="rpt-card mb-4 p-0" style="overflow:hidden">
    <div style="padding:18px 22px;border-bottom:1px solid var(--border)">
        <div style="font-weight:700;color:var(--text-primary)"><i class="fas fa-table-list me-2" style="color:var(--accent)"></i>Day-by-Day Breakdown</div>
    </div>
    <div style="overflow-x:auto">
    <table class="table table-hover mb-0" style="font-size:.82rem">
        <thead><tr style="background:var(--bg-base)">
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">#</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Date</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Result</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Checklist</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Score</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">P/L</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Trades</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">Emotions</th>
            <th class="px-3 py-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);font-weight:700">XP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($days as $d):
            if ($d['result'] === null) continue;
            $checkCount = ($d['check_higher_tf']+$d['check_key_levels']+$d['check_confirmation']+$d['check_risk_mgmt']+$d['check_no_revenge']+$d['check_setup_only']+$d['check_stop_loss']+$d['check_calm']);
            $emos = json_decode($d['emotions'] ?? '[]', true) ?: [];
            $rClass = 'day-table-row-'.$d['result'];
        ?>
        <tr class="<?= $rClass ?>" style="border-bottom:1px solid var(--border)">
            <td class="px-3 py-2" style="font-family:'DM Mono',monospace;color:var(--text-muted)"><?= $d['day_number'] ?></td>
            <td class="px-3 py-2" style="font-family:'DM Mono',monospace;color:var(--text-secondary)"><?= date('M j', strtotime($d['day_date'])) ?></td>
            <td class="px-3 py-2"><span class="result-pill rp-<?= $d['result'] ?>"><?= $d['result']==='followed'?'✓ Followed':($d['result']==='broke'?'✗ Broke':'– No Trade') ?></span></td>
            <td class="px-3 py-2" style="font-family:'DM Mono',monospace"><?= $checkCount ?>/8</td>
            <td class="px-3 py-2"><span style="font-family:'DM Mono',monospace;font-weight:700;color:<?= $d['discipline_score']>=70?'var(--profit)':($d['discipline_score']>=40?'var(--warning)':'var(--loss)') ?>"><?= $d['discipline_score'] ?></span></td>
            <td class="px-3 py-2"><span style="font-family:'DM Mono',monospace;color:<?= $d['daily_pl']>=0?'var(--profit)':'var(--loss)' ?>"><?= formatPL($d['daily_pl']) ?></span></td>
            <td class="px-3 py-2" style="color:var(--text-secondary)"><?= $d['trades_count'] ?></td>
            <td class="px-3 py-2" style="color:var(--text-muted);font-size:.75rem"><?= implode(', ', array_slice($emos, 0, 3)) ?><?= count($emos)>3 ? ' +'.( count($emos)-3) : '' ?></td>
            <td class="px-3 py-2"><span style="font-family:'DM Mono',monospace;color:#8b5cf6;font-size:.8rem"><?= $d['xp_earned'] >= 0 ? '+' : '' ?><?= $d['xp_earned'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Recommendations -->
<div class="rpt-card mb-4">
    <div style="font-weight:700;color:var(--text-primary);margin-bottom:14px"><i class="fas fa-lightbulb me-2" style="color:#fbbf24"></i>Analysis & Recommendations</div>
    <?php foreach ($recs as $rec): ?>
    <div class="d-flex gap-3 mb-3">
        <i class="fas fa-arrow-right mt-1" style="color:var(--accent);flex-shrink:0"></i>
        <div style="color:var(--text-secondary);font-size:.9rem"><?= $rec ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Philosophy -->
<div class="philosophy-box mb-4">
    <div style="font-size:2rem;margin-bottom:12px">🎯</div>
    <div style="font-size:1.15rem;font-weight:700;color:var(--text-primary);margin-bottom:8px;font-style:italic">
        "Your job is not to predict the market.<br>Your job is to manage risk and execute consistently."
    </div>
    <div style="color:var(--text-muted);font-size:.85rem;max-width:500px;margin:0 auto">
        This challenge is not about profit. It is about building the discipline that creates consistent traders. Results follow execution. Execution follows discipline.
    </div>
</div>

<!-- Print action -->
<div class="text-center mb-4">
    <button onclick="window.print()" class="btn btn-outline-secondary px-5" style="border-radius:10px"><i class="fas fa-print me-2"></i>Print Report</button>
    <a href="challenge.php" class="btn btn-primary px-5 ms-3" style="border-radius:10px"><i class="fas fa-plus me-2"></i>Start New Challenge</a>
</div>

<?php include '../includes/footer.php'; ?>

<?php if (!empty($equityLabels)):
$eLabJson   = json_encode($equityLabels);
$eValJson   = json_encode($equityValues);
$sValJson   = json_encode($scoreValues);
$emoLbJson  = json_encode(array_keys($emoTotals));
$emoDtJson  = json_encode(array_values($emoTotals));
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    createAreaChart('rptEquityChart',  <?= $eLabJson ?>, <?= $eValJson ?>, 'Equity ($)');
    createAreaChart('rptScoreChart',   <?= $eLabJson ?>, <?= $sValJson ?>, 'Discipline Score');

    const tc = getChartThemeColors();
    const emoColors = ['#f87171cc','#fbbf24cc','#22c55ecc','#ef4444cc','#06b6d4cc','#f97316cc','#8b5cf6cc','#d97706cc','#94a3b8cc'];

    const emoCtx = document.getElementById('rptEmoChart');
    if (emoCtx) {
        window._charts.push(new Chart(emoCtx, {
            type: 'doughnut',
            data: { labels: <?= $emoLbJson ?>, datasets: [{ data: <?= $emoDtJson ?>, backgroundColor: emoColors, borderWidth: 2 }] },
            options: { responsive:true, maintainAspectRatio:false, cutout:'58%',
                plugins:{ legend:{ position:'right', labels:{ color:tc.text, font:{size:11}, padding:8 }}}}
        }));
    }

    const resCtx = document.getElementById('rptResultChart');
    if (resCtx) {
        window._charts.push(new Chart(resCtx, {
            type: 'doughnut',
            data: {
                labels: ['Rules Followed', 'Broke Rules', 'No Trade'],
                datasets: [{ data: [<?= $followed ?>, <?= $broke ?>, <?= $noTrade ?>],
                    backgroundColor: [tc.profit+'cc', tc.loss+'cc', '#94a3b8cc'],
                    borderColor: [tc.profit, tc.loss, '#94a3b8'], borderWidth: 2 }]
            },
            options: { responsive:true, maintainAspectRatio:false, cutout:'60%',
                plugins:{ legend:{ position:'right', labels:{ color:tc.text, font:{size:11}, padding:8 }}}}
        }));
    }
});
</script>
<?php endif; ?>

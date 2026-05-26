<?php
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
$msg    = ''; $msgType = '';
if (!empty($_POST)) validateCsrfOrDie();

// ── Scoring Engine ────────────────────────────────────────────────────────────
function calculatePOVScore(array $entry, array $outcome): array {
    $predicted = $entry['market_bias'];
    $actual    = $outcome['actual_direction'];
    $tpHit     = (bool)$outcome['tp_hit'];
    $slHit     = (bool)$outcome['sl_hit'];
    $move      = floatval($outcome['actual_move_points'] ?? 0);
    $adverse   = floatval($outcome['max_move_against'] ?? 0);
    $psych     = $entry['psychology_state'];

    // TP distance for "strong move" threshold
    $tpDist = 0;
    if ($entry['take_profit'] && $entry['entry_price']) {
        $tpDist = abs($entry['take_profit'] - $entry['entry_price']);
    }
    $strongMove = $tpDist > 0 ? ($move >= $tpDist * 0.5) : ($move > 0);

    // Direction score
    if ($predicted === 'neutral') {
        $dirScore = 50;
    } elseif ($predicted === $actual) {
        if ($tpHit)          $dirScore = 100;
        elseif ($strongMove) $dirScore = 80;
        else                 $dirScore = 60;
    } else {
        if ($slHit)          $dirScore = 0;
        else                 $dirScore = 20;
    }

    // Timing score
    if ($tpHit)       $timScore = 100;
    elseif ($slHit)   $timScore = 20;
    elseif ($move > 0) $timScore = 70;
    else               $timScore = 40;

    $overall = (int)round($dirScore * 0.65 + $timScore * 0.35);

    // Category
    if (in_array($psych, ['fomo','revenge'])) {
        $cat = 'emotional';
    } elseif ($dirScore >= 70 && $tpHit)  { $cat = 'good_analysis_good_exec'; }
    elseif ($dirScore >= 70 && $slHit)    { $cat = 'good_analysis_bad_exec'; }
    elseif ($dirScore < 40 && $tpHit)     { $cat = 'bad_analysis_good_exec'; }
    elseif ($dirScore < 40 && $slHit)     { $cat = 'bad_analysis'; }
    else                                   { $cat = 'random'; }

    return [
        'direction_score'   => $dirScore,
        'timing_score'      => $timScore,
        'overall_pov_score' => $overall,
        'trade_category'    => $cat,
    ];
}

// ── Handle New POV Entry ──────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'new_pov') {
    $symbol   = strtoupper(trim($_POST['symbol'] ?? ''));
    $bias     = $_POST['market_bias'] ?? 'neutral';
    $conf     = max(1, min(100, intval($_POST['confidence_level'] ?? 50)));
    $session  = $_POST['session'] ?? 'other';
    $htf      = $_POST['higher_timeframe'] ?? '1D';
    $ltf      = $_POST['lower_timeframe']  ?? '15M';
    $htfBias  = $_POST['higher_tf_bias'] ?? null;
    $aligned  = isset($_POST['trend_aligned']) ? 1 : 0;
    $psych    = $_POST['psychology_state'] ?? 'neutral';
    $entry    = $_POST['entry_price'] !== '' ? floatval($_POST['entry_price']) : null;
    $sl       = $_POST['stop_loss']   !== '' ? floatval($_POST['stop_loss'])   : null;
    $tp       = $_POST['take_profit'] !== '' ? floatval($_POST['take_profit']) : null;
    $reason   = trim($_POST['reasoning'] ?? '');

    if ($symbol && in_array($bias, ['bullish','bearish','neutral'])) {
        $stmt = $db->prepare("INSERT INTO pov_entries
            (user_id, symbol, session, higher_timeframe, lower_timeframe, market_bias,
             entry_price, stop_loss, take_profit, reasoning, confidence_level,
             higher_tf_bias, trend_aligned, psychology_state)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$userId, $symbol, $session, $htf, $ltf, $bias,
                        $entry, $sl, $tp, $reason ?: null, $conf,
                        $htfBias ?: null, $aligned, $psych]);
        $msg = 'POV recorded for ' . htmlspecialchars($symbol) . '.';
        $msgType = 'success';
    } else {
        $msg = 'Symbol and Bias are required.'; $msgType = 'error';
    }
}

// ── Handle Outcome Analysis ───────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'analyze_pov') {
    $povId    = intval($_POST['pov_id'] ?? 0);
    $actDir   = $_POST['actual_direction'] ?? 'neutral';
    $moveAmt  = $_POST['actual_move_points'] !== '' ? floatval($_POST['actual_move_points']) : null;
    $adverse  = $_POST['max_move_against']   !== '' ? floatval($_POST['max_move_against'])   : null;
    $tpHit    = isset($_POST['tp_hit']) ? 1 : 0;
    $slHit    = isset($_POST['sl_hit']) ? 1 : 0;
    $notes    = trim($_POST['outcome_notes'] ?? '');

    // Fetch entry for scoring
    $eStmt = $db->prepare("SELECT * FROM pov_entries WHERE id=? AND user_id=?");
    $eStmt->execute([$povId, $userId]);
    $entryRow = $eStmt->fetch(PDO::FETCH_ASSOC);

    if ($entryRow && in_array($actDir, ['bullish','bearish','neutral'])) {
        $outcomeData = [
            'actual_direction'   => $actDir,
            'actual_move_points' => $moveAmt,
            'max_move_against'   => $adverse,
            'tp_hit'             => $tpHit,
            'sl_hit'             => $slHit,
        ];
        $scores = calculatePOVScore($entryRow, $outcomeData);

        $db->prepare("INSERT INTO pov_outcomes
            (pov_id, actual_direction, actual_move_points, max_move_against,
             tp_hit, sl_hit, direction_score, timing_score, overall_pov_score,
             trade_category, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$povId, $actDir, $moveAmt, $adverse, $tpHit, $slHit,
                      $scores['direction_score'], $scores['timing_score'],
                      $scores['overall_pov_score'], $scores['trade_category'],
                      $notes ?: null]);

        $db->prepare("UPDATE pov_entries SET status='analyzed' WHERE id=?")
           ->execute([$povId]);

        $msg = 'Outcome analyzed. POV Score: ' . $scores['overall_pov_score'] . '/100';
        $msgType = 'success';
    } else {
        $msg = 'Invalid POV or direction.'; $msgType = 'error';
    }
}

// ── Delete POV ────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete_pov') {
    $povId = intval($_POST['pov_id'] ?? 0);
    $db->prepare("DELETE FROM pov_entries WHERE id=? AND user_id=?")->execute([$povId, $userId]);
    $msg = 'POV deleted.'; $msgType = 'error';
}

// ── Stats Queries ─────────────────────────────────────────────────────────────
$overallStmt = $db->prepare("SELECT
    COUNT(o.id) as analyzed,
    COALESCE(AVG(o.overall_pov_score),0) as avg_score,
    COALESCE(AVG(o.direction_score),0) as avg_dir,
    COALESCE(AVG(o.timing_score),0) as avg_timing,
    SUM(CASE WHEN e.market_bias=o.actual_direction THEN 1 ELSE 0 END) as correct_dir,
    SUM(CASE WHEN e.market_bias='bullish' THEN 1 ELSE 0 END) as bull_cnt,
    SUM(CASE WHEN e.market_bias='bearish' THEN 1 ELSE 0 END) as bear_cnt,
    SUM(CASE WHEN e.market_bias='bullish' AND o.actual_direction='bullish' THEN 1 ELSE 0 END) as bull_correct,
    SUM(CASE WHEN e.market_bias='bearish' AND o.actual_direction='bearish' THEN 1 ELSE 0 END) as bear_correct,
    SUM(CASE WHEN o.tp_hit=1 THEN 1 ELSE 0 END) as tp_hits,
    SUM(CASE WHEN o.sl_hit=1 THEN 1 ELSE 0 END) as sl_hits
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id WHERE e.user_id=?");
$overallStmt->execute([$userId]);
$stats = $overallStmt->fetch(PDO::FETCH_ASSOC);

$totalPovsStmt = $db->prepare("SELECT COUNT(*) as cnt FROM pov_entries WHERE user_id=?");
$totalPovsStmt->execute([$userId]);
$totalPovs = $totalPovsStmt->fetch()['cnt'];

// This week analyzed
$weekStmt = $db->prepare("SELECT COUNT(*) as cnt FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? AND YEARWEEK(o.analyzed_at,1)=YEARWEEK(NOW(),1)");
$weekStmt->execute([$userId]);
$weekAnalyzed = $weekStmt->fetch()['cnt'];

// Best session
$sessionStmt = $db->prepare("SELECT e.session, AVG(o.overall_pov_score) as avg_s, COUNT(*) as cnt
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? GROUP BY e.session ORDER BY avg_s DESC LIMIT 1");
$sessionStmt->execute([$userId]);
$bestSession = $sessionStmt->fetch(PDO::FETCH_ASSOC);

// Weekly trend (last 8 weeks)
$weeklyTrend = $db->prepare("SELECT DATE_FORMAT(MIN(o.analyzed_at),'%d %b') as wk_label,
    ROUND(AVG(o.overall_pov_score),1) as avg_score
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? GROUP BY YEARWEEK(o.analyzed_at,1) ORDER BY YEARWEEK(o.analyzed_at,1) DESC LIMIT 8");
$weeklyTrend->execute([$userId]);
$weeklyData = array_reverse($weeklyTrend->fetchAll(PDO::FETCH_ASSOC));

// Category breakdown
$catStmt = $db->prepare("SELECT trade_category, COUNT(*) as cnt FROM pov_outcomes o
    JOIN pov_entries e ON o.pov_id=e.id WHERE e.user_id=? GROUP BY trade_category");
$catStmt->execute([$userId]);
$catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);
$catMap = array_column($catRows, 'cnt', 'trade_category');

// Session accuracy breakdown
$sessBreakStmt = $db->prepare("SELECT e.session, ROUND(AVG(o.overall_pov_score),1) as avg_s, COUNT(*) as cnt
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? GROUP BY e.session ORDER BY avg_s DESC");
$sessBreakStmt->execute([$userId]);
$sessBreak = $sessBreakStmt->fetchAll(PDO::FETCH_ASSOC);

// Confidence vs score (scatter data)
$scatterStmt = $db->prepare("SELECT e.confidence_level as x, o.overall_pov_score as y
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? ORDER BY e.created_at DESC LIMIT 60");
$scatterStmt->execute([$userId]);
$scatterData = $scatterStmt->fetchAll(PDO::FETCH_ASSOC);

// Heatmap: last 84 days (12 weeks)
$heatStmt = $db->prepare("SELECT DATE(o.analyzed_at) as day, ROUND(AVG(o.overall_pov_score)) as avg_s
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? AND o.analyzed_at >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)
    GROUP BY DATE(o.analyzed_at)");
$heatStmt->execute([$userId]);
$heatRaw = $heatStmt->fetchAll(PDO::FETCH_ASSOC);
$heatMap = array_column($heatRaw, 'avg_s', 'day');

// Recent POV list (last 15)
$listStmt = $db->prepare("SELECT e.*, o.overall_pov_score, o.direction_score,
    o.actual_direction, o.tp_hit, o.sl_hit, o.trade_category, o.analyzed_at
    FROM pov_entries e LEFT JOIN pov_outcomes o ON o.pov_id=e.id
    WHERE e.user_id=? ORDER BY e.created_at DESC LIMIT 15");
$listStmt->execute([$userId]);
$povList = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Symbol accuracy
$symStmt = $db->prepare("SELECT e.symbol, ROUND(AVG(o.overall_pov_score),1) as avg_s, COUNT(*) as cnt
    FROM pov_outcomes o JOIN pov_entries e ON o.pov_id=e.id
    WHERE e.user_id=? GROUP BY e.symbol ORDER BY avg_s DESC LIMIT 5");
$symStmt->execute([$userId]);
$symStats = $symStmt->fetchAll(PDO::FETCH_ASSOC);

// AI Insights
$analyzedCount = intval($stats['analyzed']);
$insights = [];
if ($analyzedCount >= 3) {
    $bullAcc  = $stats['bull_cnt']  > 0 ? round($stats['bull_correct']  / $stats['bull_cnt']  * 100) : 0;
    $bearAcc  = $stats['bear_cnt']  > 0 ? round($stats['bear_correct']  / $stats['bear_cnt']  * 100) : 0;
    $avgScore = round($stats['avg_score']);
    $dirPct   = $stats['analyzed'] > 0 ? round($stats['correct_dir'] / $stats['analyzed'] * 100) : 0;

    if ($bullAcc > $bearAcc + 15)
        $insights[] = ['icon'=>'arrow-trend-up','color'=>'profit',
            'text'=>"Bullish POVs score {$bullAcc}% accuracy vs {$bearAcc}% Bearish — your bullish reads are stronger. Trust them more."];
    elseif ($bearAcc > $bullAcc + 15)
        $insights[] = ['icon'=>'arrow-trend-down','color'=>'profit',
            'text'=>"Bearish POVs score {$bearAcc}% vs {$bullAcc}% Bullish — your bearish analysis is more reliable."];

    if ($avgScore >= 75)
        $insights[] = ['icon'=>'trophy','color'=>'profit',
            'text'=>"Excellent! Your average POV score is {$avgScore}/100 — your market reading is highly accurate."];
    elseif ($avgScore < 45)
        $insights[] = ['icon'=>'triangle-exclamation','color'=>'loss',
            'text'=>"Your average POV score is {$avgScore}/100. Focus on higher timeframe bias alignment before entry."];

    if ($dirPct >= 70)
        $insights[] = ['icon'=>'check-circle','color'=>'profit',
            'text'=>"Your direction accuracy is {$dirPct}% — you read market direction well. Work on refining entry timing."];
    elseif ($dirPct < 50)
        $insights[] = ['icon'=>'ban','color'=>'loss',
            'text'=>"Direction accuracy only {$dirPct}%. Consider waiting for higher-timeframe confirmation before forming a bias."];

    $emotCount = intval($catMap['emotional'] ?? 0);
    if ($emotCount >= 2)
        $insights[] = ['icon'=>'brain','color'=>'warning',
            'text'=>"{$emotCount} emotional POVs detected (FOMO/Revenge). These drag your score — pause before entering impulsive trades."];

    $tpRate = $stats['analyzed'] > 0 ? round($stats['tp_hits'] / $stats['analyzed'] * 100) : 0;
    $slRate = $stats['analyzed'] > 0 ? round($stats['sl_hits'] / $stats['analyzed'] * 100) : 0;
    if ($slRate > 60)
        $insights[] = ['icon'=>'xmark-circle','color'=>'loss',
            'text'=>"SL is hit in {$slRate}% of your POVs. Your analysis may be correct but entries are too early — wait for confirmation."];
    elseif ($tpRate >= 50)
        $insights[] = ['icon'=>'star','color'=>'profit',
            'text'=>"TP hit in {$tpRate}% of POVs — excellent execution and patience!"];

    if ($bestSession)
        $insights[] = ['icon'=>'clock','color'=>'blue',
            'text'=>"Your strongest session is " . strtoupper(str_replace('_',' ',$bestSession['session']))
                   . " with avg score " . round($bestSession['avg_s']) . "/100 — prioritize this window."];

    $goodAnalysis = intval($catMap['good_analysis_bad_exec'] ?? 0);
    if ($goodAnalysis >= 3)
        $insights[] = ['icon'=>'bullseye','color'=>'warning',
            'text'=>"{$goodAnalysis} POVs show good analysis but bad execution — your direction read is right, but entries need work."];
}
if (empty($insights)) {
    $insights[] = ['icon'=>'chart-line','color'=>'blue',
        'text'=>'Record and analyze at least 3 POVs to unlock AI insights about your trading psychology and market reading accuracy.'];
}

$pageTitle = 'POV Tracker';
$rootPath  = '../';
include '../includes/header.php';
?>
<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ── Stat Cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-crosshairs"></i></div>
            <div class="stat-value"><?= $analyzedCount > 0 ? round($stats['avg_score']) . '%' : '—' ?></div>
            <div class="stat-label">Overall POV Accuracy</div>
            <div class="stat-sub">avg of <?= $analyzedCount ?> analyzed</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card cyan">
            <div class="stat-icon cyan"><i class="fas fa-list-check"></i></div>
            <div class="stat-value"><?= $totalPovs ?></div>
            <div class="stat-label">Total POVs</div>
            <div class="stat-sub"><?= $totalPovs - $analyzedCount ?> pending analysis</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php $dirPct = $analyzedCount > 0 ? round($stats['correct_dir'] / $analyzedCount * 100) : 0; ?>
        <div class="stat-card <?= $dirPct >= 60 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $dirPct >= 60 ? 'profit' : 'loss' ?>"><i class="fas fa-compass"></i></div>
            <div class="stat-value"><?= $analyzedCount > 0 ? $dirPct . '%' : '—' ?></div>
            <div class="stat-label">Direction Accuracy</div>
            <div class="stat-sub"><?= $stats['correct_dir'] ?? 0 ?> / <?= $analyzedCount ?> correct</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card purple">
            <div class="stat-icon purple"><i class="fas fa-calendar-week"></i></div>
            <div class="stat-value"><?= $weekAnalyzed ?></div>
            <div class="stat-label">Analyzed This Week</div>
            <div class="stat-sub"><?= $bestSession ? strtoupper(str_replace('_',' ',$bestSession['session'])) . ' best' : 'no data yet' ?></div>
        </div>
    </div>
</div>

<!-- ── Entry Form + Recent POVs ──────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- LEFT: New POV Form -->
    <div class="col-lg-5">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-plus-circle"></i> New POV Entry</div>
            </div>
            <div class="panel-body">
                <form method="POST" id="povForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="new_pov">

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-sm">Symbol *</label>
                            <input type="text" name="symbol" class="form-control form-control-sm" placeholder="XAUUSD, BTC…" required style="text-transform:uppercase">
                        </div>
                        <div class="col-6">
                            <label class="form-label-sm">Session</label>
                            <select name="session" class="form-select form-select-sm">
                                <option value="asian">Asian</option>
                                <option value="london" selected>London</option>
                                <option value="new_york">New York</option>
                                <option value="london_ny_overlap">London/NY Overlap</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Market Bias -->
                    <div class="mb-3">
                        <label class="form-label-sm">Market Bias *</label>
                        <div class="bias-btn-group">
                            <label class="bias-btn bullish-btn">
                                <input type="radio" name="market_bias" value="bullish" required>
                                <i class="fas fa-arrow-trend-up"></i> Bullish
                            </label>
                            <label class="bias-btn bearish-btn">
                                <input type="radio" name="market_bias" value="bearish">
                                <i class="fas fa-arrow-trend-down"></i> Bearish
                            </label>
                            <label class="bias-btn neutral-btn">
                                <input type="radio" name="market_bias" value="neutral">
                                <i class="fas fa-minus"></i> Neutral
                            </label>
                        </div>
                    </div>

                    <!-- Timeframes -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-sm">Higher TF</label>
                            <select name="higher_timeframe" class="form-select form-select-sm">
                                <option value="1M">Monthly</option>
                                <option value="1W">Weekly</option>
                                <option value="1D" selected>Daily</option>
                                <option value="4H">4H</option>
                                <option value="1H">1H</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label-sm">Execution TF</label>
                            <select name="lower_timeframe" class="form-select form-select-sm">
                                <option value="4H">4H</option>
                                <option value="1H">1H</option>
                                <option value="30M">30M</option>
                                <option value="15M" selected>15M</option>
                                <option value="5M">5M</option>
                                <option value="1M">1M</option>
                            </select>
                        </div>
                    </div>

                    <!-- Higher TF Bias + Trend Aligned -->
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label-sm">Higher TF Bias</label>
                            <select name="higher_tf_bias" class="form-select form-select-sm">
                                <option value="">— Select —</option>
                                <option value="bullish">Bullish</option>
                                <option value="bearish">Bearish</option>
                                <option value="neutral">Neutral</option>
                            </select>
                        </div>
                        <div class="col-5 d-flex align-items-end">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="trend_aligned" id="trendAligned" value="1">
                                <label class="form-check-label" for="trendAligned" style="font-size:12px">Trend Aligned</label>
                            </div>
                        </div>
                    </div>

                    <!-- Entry / SL / TP -->
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label-sm">Entry Price</label>
                            <input type="number" name="entry_price" class="form-control form-control-sm" step="0.00001" placeholder="0.00000">
                        </div>
                        <div class="col-4">
                            <label class="form-label-sm">Stop Loss</label>
                            <input type="number" name="stop_loss" class="form-control form-control-sm" step="0.00001" placeholder="0.00000">
                        </div>
                        <div class="col-4">
                            <label class="form-label-sm">Take Profit</label>
                            <input type="number" name="take_profit" class="form-control form-control-sm" step="0.00001" placeholder="0.00000">
                        </div>
                    </div>

                    <!-- Confidence -->
                    <div class="mb-3">
                        <label class="form-label-sm">Confidence Level: <strong id="confVal">50%</strong></label>
                        <input type="range" name="confidence_level" id="confSlider" min="1" max="100" value="50" class="pov-slider">
                        <div class="conf-labels"><span>Low</span><span>Medium</span><span>High</span></div>
                    </div>

                    <!-- Psychology -->
                    <div class="mb-3">
                        <label class="form-label-sm">Psychology State</label>
                        <select name="psychology_state" class="form-select form-select-sm">
                            <option value="calm">😌 Calm &amp; Focused</option>
                            <option value="neutral" selected>😐 Neutral</option>
                            <option value="fearful">😨 Fearful</option>
                            <option value="overconfident">😤 Overconfident</option>
                            <option value="fomo">😰 FOMO</option>
                            <option value="revenge">😡 Revenge</option>
                        </select>
                    </div>

                    <!-- Reasoning -->
                    <div class="mb-3">
                        <label class="form-label-sm">Why this direction? (reasoning)</label>
                        <textarea name="reasoning" class="form-control form-control-sm" rows="3" placeholder="Key levels, structure, confluence…"></textarea>
                    </div>

                    <button type="submit" class="btn-primary-custom w-100">
                        <i class="fas fa-crosshairs"></i> Record POV
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT: Recent POVs -->
    <div class="col-lg-7">
        <div class="panel h-100">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-history"></i> Recent POVs</div>
                <span class="panel-link" style="color:var(--text-muted);font-size:11px"><?= $totalPovs ?> total</span>
            </div>
            <div class="panel-body" style="padding:0;max-height:600px;overflow-y:auto">
                <?php if (empty($povList)): ?>
                <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
                    <i class="fas fa-crosshairs" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3"></i>
                    No POVs recorded yet. Add your first market prediction!
                </div>
                <?php else: foreach ($povList as $pov):
                    $analyzed   = $pov['status'] === 'analyzed';
                    $biasColor  = $pov['market_bias'] === 'bullish' ? 'var(--profit)' : ($pov['market_bias'] === 'bearish' ? 'var(--loss)' : 'var(--text-muted)');
                    $biasIcon   = $pov['market_bias'] === 'bullish' ? 'arrow-trend-up' : ($pov['market_bias'] === 'bearish' ? 'arrow-trend-down' : 'minus');
                    $score      = $pov['overall_pov_score'] ?? null;
                    $scoreColor = $score === null ? 'var(--text-muted)' : ($score >= 75 ? 'var(--profit)' : ($score >= 50 ? 'var(--warning)' : 'var(--loss)'));
                ?>
                <div class="pov-card">
                    <div class="pov-card-top">
                        <div class="pov-card-left">
                            <span class="symbol-badge"><?= htmlspecialchars($pov['symbol']) ?></span>
                            <span class="pov-bias-badge" style="color:<?= $biasColor ?>">
                                <i class="fas fa-<?= $biasIcon ?>"></i> <?= ucfirst($pov['market_bias']) ?>
                            </span>
                            <span class="pov-meta"><?= $pov['higher_timeframe'] ?> / <?= $pov['lower_timeframe'] ?></span>
                            <span class="pov-meta"><?= strtoupper(str_replace('_',' ',$pov['session'])) ?></span>
                        </div>
                        <div class="pov-card-right">
                            <?php if ($analyzed && $score !== null): ?>
                            <span class="pov-score-badge" style="background:<?= $scoreColor ?>20;color:<?= $scoreColor ?>;border:1px solid <?= $scoreColor ?>40">
                                <?= $score ?>/100
                            </span>
                            <?php endif; ?>
                            <span class="pov-status-badge pov-status-<?= $pov['status'] ?>"><?= ucfirst($pov['status']) ?></span>
                        </div>
                    </div>
                    <div class="pov-card-mid">
                        <span class="pov-conf">Confidence: <strong><?= $pov['confidence_level'] ?>%</strong></span>
                        <div class="pov-conf-bar"><div style="width:<?= $pov['confidence_level'] ?>%;background:var(--accent)"></div></div>
                        <?php $psychColors = ['calm'=>'profit','neutral'=>'text-muted','fearful'=>'warning','overconfident'=>'warning','fomo'=>'loss','revenge'=>'loss']; ?>
                        <span class="pov-psych pov-psych-<?= $psychColors[$pov['psychology_state']] ?? 'text-muted' ?>"><?= ucfirst($pov['psychology_state']) ?></span>
                    </div>
                    <?php if ($pov['reasoning']): ?>
                    <div class="pov-reasoning"><?= htmlspecialchars(mb_strimwidth($pov['reasoning'], 0, 100, '…')) ?></div>
                    <?php endif; ?>
                    <?php if ($analyzed): ?>
                    <div class="pov-outcome-row">
                        <span>Actual: <strong style="color:<?= $pov['actual_direction']==='bullish'?'var(--profit)':'var(--loss)' ?>"><?= ucfirst($pov['actual_direction'] ?? '—') ?></strong></span>
                        <?php if ($pov['tp_hit']): ?><span class="badge-mini badge-profit">TP Hit</span><?php endif; ?>
                        <?php if ($pov['sl_hit']): ?><span class="badge-mini badge-loss">SL Hit</span><?php endif; ?>
                        <?php $catLabels = ['good_analysis_good_exec'=>'Good Analysis + Execution','good_analysis_bad_exec'=>'Good Analysis, Bad Exec','bad_analysis_good_exec'=>'Lucky Trade','bad_analysis'=>'Bad Analysis','emotional'=>'Emotional','random'=>'Random']; ?>
                        <span class="pov-cat-tag"><?= $catLabels[$pov['trade_category'] ?? 'random'] ?? '' ?></span>
                    </div>
                    <?php else: ?>
                    <div class="pov-action-row">
                        <button class="btn-analyze" onclick="openAnalyzeModal(<?= htmlspecialchars(json_encode($pov)) ?>)">
                            <i class="fas fa-flask"></i> Analyze Outcome
                        </button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this POV?')">
                    <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_pov">
                            <input type="hidden" name="pov_id" value="<?= $pov['id'] ?>">
                            <button type="submit" class="btn-del-pov"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Analytics Dashboard ────────────────────────────────────────────────── -->
<?php if ($analyzedCount >= 1): ?>
<div class="row g-4 mb-4">
    <!-- Metrics Panel -->
    <div class="col-lg-3">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-chart-pie"></i> Analytics</div></div>
            <div class="panel-body">
                <?php
                $bullAcc = ($stats['bull_cnt'] > 0) ? round($stats['bull_correct'] / $stats['bull_cnt'] * 100) : 0;
                $bearAcc = ($stats['bear_cnt'] > 0) ? round($stats['bear_correct'] / $stats['bear_cnt'] * 100) : 0;
                $avgDir  = round($stats['avg_dir']);
                $avgTim  = round($stats['avg_timing']);
                ?>
                <div class="metric-row"><span class="metric-label">Total Analyzed</span><span class="metric-value"><?= $analyzedCount ?></span></div>
                <div class="metric-row"><span class="metric-label">Avg POV Score</span><span class="metric-value" style="color:<?= round($stats['avg_score'])>=60?'var(--profit)':'var(--loss)' ?>"><?= round($stats['avg_score']) ?>/100</span></div>
                <div class="metric-row"><span class="metric-label">Direction Score</span><span class="metric-value"><?= $avgDir ?>/100</span></div>
                <div class="metric-row"><span class="metric-label">Timing Score</span><span class="metric-value"><?= $avgTim ?>/100</span></div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Bullish Accuracy</span><span class="metric-value text-profit"><?= $bullAcc ?>%</span></div>
                <div class="metric-row"><span class="metric-label">Bearish Accuracy</span><span class="metric-value text-loss"><?= $bearAcc ?>%</span></div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">TP Hit Rate</span><span class="metric-value text-profit"><?= $stats['analyzed'] > 0 ? round($stats['tp_hits']/$stats['analyzed']*100) : 0 ?>%</span></div>
                <div class="metric-row"><span class="metric-label">SL Hit Rate</span><span class="metric-value text-loss"><?= $stats['analyzed'] > 0 ? round($stats['sl_hits']/$stats['analyzed']*100) : 0 ?>%</span></div>
                <?php if (!empty($symStats)): ?>
                <hr class="divider">
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Top Symbols</div>
                <?php foreach ($symStats as $s): ?>
                <div class="metric-row">
                    <span class="metric-label"><span class="symbol-badge" style="font-size:9px"><?= htmlspecialchars($s['symbol']) ?></span></span>
                    <span class="metric-value" style="font-size:12px"><?= $s['avg_s'] ?>% <span style="color:var(--text-muted);font-size:10px">(<?= $s['cnt'] ?>)</span></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Weekly Accuracy Chart -->
    <div class="col-lg-5">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-chart-area"></i> Weekly Accuracy Trend</div></div>
            <div class="panel-body">
                <?php if (count($weeklyData) >= 2): ?>
                <canvas id="weeklyChart" height="200"></canvas>
                <?php else: ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted)">Analyze more POVs to see trend</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Category Donut -->
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-circle-half-stroke"></i> POV Categories</div></div>
            <div class="panel-body">
                <canvas id="catChart" height="180"></canvas>
                <div style="margin-top:12px">
                    <?php $catDefs = [
                        'good_analysis_good_exec' => ['Good Analysis + Exec','#22c55e'],
                        'good_analysis_bad_exec'  => ['Good Analysis, Bad Exec','#f59e0b'],
                        'bad_analysis_good_exec'  => ['Lucky Trade','#a78bfa'],
                        'bad_analysis'            => ['Bad Analysis','#ef4444'],
                        'emotional'               => ['Emotional','#f97316'],
                        'random'                  => ['Random','#94a3b8'],
                    ];
                    foreach ($catDefs as $key => [$label, $color]):
                        $cnt = $catMap[$key] ?? 0;
                        if ($cnt === 0) continue;
                    ?>
                    <div class="metric-row" style="padding:2px 0">
                        <span class="metric-label" style="font-size:11px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $color ?>;margin-right:5px"></span><?= $label ?></span>
                        <span class="metric-value" style="font-size:11px"><?= $cnt ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Bottom Row: Heatmap + Scatter + Session ────────────────────────────── -->
<div class="row g-4 mb-4">
    <!-- Accuracy Heatmap -->
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-calendar-days"></i> Accuracy Heatmap (12 weeks)</div>
                <div style="display:flex;gap:8px;align-items:center;font-size:10px;color:var(--text-muted)">
                    <span style="display:flex;align-items:center;gap:3px"><span class="heat-dot" style="background:#ef4444"></span>Low</span>
                    <span style="display:flex;align-items:center;gap:3px"><span class="heat-dot" style="background:#f59e0b"></span>Mid</span>
                    <span style="display:flex;align-items:center;gap:3px"><span class="heat-dot" style="background:#22c55e"></span>High</span>
                </div>
            </div>
            <div class="panel-body">
                <div class="heatmap-grid">
                <?php
                $today = new DateTime();
                $start = (clone $today)->modify('-83 days');
                $start->modify('monday this week');
                $cur = clone $start;
                while ($cur <= $today):
                    $dateStr = $cur->format('Y-m-d');
                    $s = $heatMap[$dateStr] ?? null;
                    $bg = $s === null ? 'var(--bg-elevated)' : ($s >= 75 ? '#22c55e' : ($s >= 50 ? '#f59e0b' : '#ef4444'));
                    $title = $s !== null ? "{$dateStr}: {$s}/100" : $dateStr;
                ?>
                <div class="heat-cell" style="background:<?= $bg ?>" title="<?= $title ?>"></div>
                <?php $cur->modify('+1 day'); endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Confidence vs Score Scatter -->
    <div class="col-lg-3">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-scatter-chart"></i> Confidence vs Score</div></div>
            <div class="panel-body">
                <?php if (count($scatterData) >= 3): ?>
                <canvas id="scatterChart" height="200"></canvas>
                <?php else: ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:12px">Need 3+ analyzed POVs</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Session Performance -->
    <div class="col-lg-3">
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-clock"></i> Session Performance</div></div>
            <div class="panel-body">
                <?php if (empty($sessBreak)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:12px">No data yet</div>
                <?php else: foreach ($sessBreak as $sb):
                    $pct = min(100, $sb['avg_s']);
                    $col = $pct >= 70 ? 'var(--profit)' : ($pct >= 50 ? 'var(--warning)' : 'var(--loss)');
                ?>
                <div style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                        <span><?= strtoupper(str_replace('_',' ',$sb['session'])) ?></span>
                        <span style="font-weight:700;color:<?= $col ?>"><?= $sb['avg_s'] ?>/100</span>
                    </div>
                    <div class="risk-bar-track"><div class="risk-bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                    <div style="font-size:10px;color:var(--text-muted)"><?= $sb['cnt'] ?> POVs</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── AI Insights ────────────────────────────────────────────────────────── -->
<div class="panel mb-4">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-robot"></i> AI Insights</div>
        <span style="font-size:11px;color:var(--text-muted)">Based on your POV history</span>
    </div>
    <div class="panel-body">
        <div class="row g-3">
            <?php foreach ($insights as $ins):
                $insColors = ['profit'=>'var(--profit)','loss'=>'var(--loss)','warning'=>'var(--warning)','blue'=>'var(--accent)'];
                $insColor  = $insColors[$ins['color']] ?? 'var(--accent)';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="insight-card" style="border-left:3px solid <?= $insColor ?>">
                    <div class="insight-icon" style="color:<?= $insColor ?>"><i class="fas fa-<?= $ins['icon'] ?>"></i></div>
                    <div class="insight-text"><?= htmlspecialchars($ins['text']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Analyze Outcome Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="analyzeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:var(--bg-card);border:1px solid var(--border)">
            <div class="modal-header" style="border-bottom:1px solid var(--border)">
                <h5 class="modal-title"><i class="fas fa-flask"></i> Analyze POV Outcome — <span id="modalSymbol"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                    <?= csrfField() ?>
                <input type="hidden" name="action" value="analyze_pov">
                <input type="hidden" name="pov_id" id="modalPovId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-sm">Your Bias was: <strong id="modalBias"></strong></label>
                        <div style="font-size:12px;color:var(--text-muted)">Reasoning: <em id="modalReason"></em></div>
                    </div>
                    <hr class="divider">
                    <div class="mb-3">
                        <label class="form-label-sm">What Actually Happened? *</label>
                        <div class="bias-btn-group">
                            <label class="bias-btn bullish-btn">
                                <input type="radio" name="actual_direction" value="bullish" required>
                                <i class="fas fa-arrow-trend-up"></i> Bullish
                            </label>
                            <label class="bias-btn bearish-btn">
                                <input type="radio" name="actual_direction" value="bearish">
                                <i class="fas fa-arrow-trend-down"></i> Bearish
                            </label>
                            <label class="bias-btn neutral-btn">
                                <input type="radio" name="actual_direction" value="neutral">
                                <i class="fas fa-minus"></i> Mixed
                            </label>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-sm">Move in Predicted Direction (pts)</label>
                            <input type="number" name="actual_move_points" class="form-control form-control-sm" step="0.01" placeholder="e.g. 120">
                        </div>
                        <div class="col-6">
                            <label class="form-label-sm">Adverse Move Against (pts)</label>
                            <input type="number" name="max_move_against" class="form-control form-control-sm" step="0.01" placeholder="e.g. 30">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="tp_hit" id="tpHit" value="1">
                                <label class="form-check-label text-profit" for="tpHit"><strong>Take Profit Hit</strong></label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sl_hit" id="slHit" value="1">
                                <label class="form-check-label text-loss" for="slHit"><strong>Stop Loss Hit</strong></label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Notes</label>
                        <textarea name="outcome_notes" class="form-control form-control-sm" rows="2" placeholder="What happened, what you learned…"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border)">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="fas fa-calculator"></i> Calculate Score</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Analyze Modal
function openAnalyzeModal(pov) {
    document.getElementById('modalPovId').value  = pov.id;
    document.getElementById('modalSymbol').textContent = pov.symbol;
    document.getElementById('modalBias').textContent   = pov.market_bias.charAt(0).toUpperCase() + pov.market_bias.slice(1);
    document.getElementById('modalReason').textContent = pov.reasoning || 'No reasoning provided';
    new bootstrap.Modal(document.getElementById('analyzeModal')).show();
}

// Confidence slider
const slider = document.getElementById('confSlider');
const confLbl = document.getElementById('confVal');
if (slider) slider.addEventListener('input', () => confLbl.textContent = slider.value + '%');

// Bias buttons
document.querySelectorAll('.bias-btn input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
        radio.closest('.bias-btn-group').querySelectorAll('.bias-btn').forEach(b => b.classList.remove('active'));
        radio.closest('.bias-btn').classList.add('active');
    });
});

// Charts
<?php if ($analyzedCount >= 1): ?>
const C = window._charts || (window._charts = []);
const tc = getChartThemeColors();

<?php if (count($weeklyData) >= 2):
    $wLabels = json_encode(array_column($weeklyData, 'wk_label'));
    $wScores = json_encode(array_column($weeklyData, 'avg_score'));
?>
const wCtx = document.getElementById('weeklyChart');
if (wCtx) {
    const wChart = new Chart(wCtx, {
        type: 'line',
        data: {
            labels: <?= $wLabels ?>,
            datasets: [{
                label: 'Avg POV Score',
                data: <?= $wScores ?>,
                borderColor: tc.blue,
                backgroundColor: tc.blue + '22',
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { min: 0, max: 100, grid: { color: tc.grid }, ticks: { color: tc.text } },
                x: { grid: { color: tc.grid }, ticks: { color: tc.text } }
            }
        }
    });
    C.push(wChart);
}
<?php endif; ?>

<?php
$catDefs2 = [
    'good_analysis_good_exec' => ['Good Analysis + Exec','#22c55e'],
    'good_analysis_bad_exec'  => ['Good Analysis, Bad Exec','#f59e0b'],
    'bad_analysis_good_exec'  => ['Lucky','#a78bfa'],
    'bad_analysis'            => ['Bad Analysis','#ef4444'],
    'emotional'               => ['Emotional','#f97316'],
    'random'                  => ['Random','#94a3b8'],
];
$catLabelsJs = []; $catDataJs = []; $catColorsJs = [];
foreach ($catDefs2 as $key => [$lbl, $col]) {
    $cnt = $catMap[$key] ?? 0;
    if ($cnt === 0) continue;
    $catLabelsJs[] = $lbl; $catDataJs[] = $cnt; $catColorsJs[] = $col;
}
?>
const catCtx = document.getElementById('catChart');
if (catCtx && <?= count($catDataJs) ?> > 0) {
    const catChart = new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($catLabelsJs) ?>,
            datasets: [{ data: <?= json_encode($catDataJs) ?>, backgroundColor: <?= json_encode($catColorsJs) ?>, borderWidth: 2 }]
        },
        options: {
            responsive: true,
            cutout: '60%',
            plugins: { legend: { display: false } }
        }
    });
    C.push(catChart);
}

<?php if (count($scatterData) >= 3):
    $sData = json_encode(array_map(fn($r) => ['x'=>intval($r['x']),'y'=>intval($r['y'])], $scatterData));
?>
const scCtx = document.getElementById('scatterChart');
if (scCtx) {
    const scChart = new Chart(scCtx, {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'POVs',
                data: <?= $sData ?>,
                backgroundColor: tc.blue + 'cc',
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false },
                tooltip: { callbacks: { label: ctx => `Conf: ${ctx.parsed.x}%  Score: ${ctx.parsed.y}` } }
            },
            scales: {
                x: { min:0, max:100, title:{ display:true, text:'Confidence %', color:tc.text }, grid:{color:tc.grid}, ticks:{color:tc.text} },
                y: { min:0, max:100, title:{ display:true, text:'POV Score', color:tc.text }, grid:{color:tc.grid}, ticks:{color:tc.text} }
            }
        }
    });
    C.push(scChart);
}
<?php endif; ?>
<?php endif; ?>
</script>

<style>
.form-label-sm { font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:4px; display:block; }
.bias-btn-group { display:flex; gap:8px; }
.bias-btn { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:9px 8px; border:1.5px solid var(--border); border-radius:var(--radius-sm); cursor:pointer; font-size:13px; font-weight:600; transition:.15s; user-select:none; }
.bias-btn input { display:none; }
.bias-btn:hover { border-color:var(--accent); }
.bias-btn.active.bullish-btn, .bullish-btn:has(input:checked) { border-color:var(--profit); background:rgba(34,197,94,.1); color:var(--profit); }
.bias-btn.active.bearish-btn, .bearish-btn:has(input:checked) { border-color:var(--loss); background:rgba(239,68,68,.1); color:var(--loss); }
.bias-btn.active.neutral-btn, .neutral-btn:has(input:checked) { border-color:var(--accent); background:rgba(59,130,246,.1); color:var(--accent); }
.pov-slider { width:100%; accent-color:var(--accent); }
.conf-labels { display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted); margin-top:2px; }
.pov-card { border-bottom:1px solid var(--border); padding:12px 16px; transition:background .15s; }
.pov-card:hover { background:var(--bg-elevated); }
.pov-card-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }
.pov-card-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.pov-card-right { display:flex; align-items:center; gap:6px; flex-shrink:0; }
.pov-bias-badge { font-size:12px; font-weight:700; }
.pov-meta { font-size:10px; color:var(--text-muted); background:var(--bg-elevated); padding:2px 6px; border-radius:4px; }
.pov-score-badge { font-size:12px; font-weight:800; padding:3px 8px; border-radius:20px; }
.pov-status-badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; text-transform:uppercase; letter-spacing:.05em; }
.pov-status-pending  { background:rgba(245,158,11,.15); color:var(--warning); }
.pov-status-analyzed { background:rgba(34,197,94,.15); color:var(--profit); }
.pov-status-expired  { background:var(--bg-elevated); color:var(--text-muted); }
.pov-card-mid { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.pov-conf { font-size:11px; color:var(--text-muted); white-space:nowrap; }
.pov-conf-bar { flex:1; height:4px; background:var(--border); border-radius:2px; overflow:hidden; }
.pov-conf-bar div { height:100%; border-radius:2px; }
.pov-psych { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; white-space:nowrap; }
.pov-psych-profit { background:rgba(34,197,94,.12); color:var(--profit); }
.pov-psych-warning { background:rgba(245,158,11,.12); color:var(--warning); }
.pov-psych-loss { background:rgba(239,68,68,.12); color:var(--loss); }
.pov-psych-text-muted { background:var(--bg-elevated); color:var(--text-muted); }
.pov-reasoning { font-size:11px; color:var(--text-muted); font-style:italic; margin-bottom:6px; }
.pov-outcome-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--text-muted); }
.pov-cat-tag { font-size:10px; background:var(--bg-elevated); padding:2px 7px; border-radius:4px; color:var(--text-secondary); }
.pov-action-row { display:flex; align-items:center; gap:8px; }
.btn-analyze { background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); padding:5px 12px; font-size:12px; font-weight:600; cursor:pointer; transition:.15s; }
.btn-analyze:hover { opacity:.85; }
.btn-del-pov { background:transparent; border:1px solid var(--border); color:var(--text-muted); border-radius:var(--radius-sm); padding:5px 9px; font-size:11px; cursor:pointer; }
.btn-del-pov:hover { border-color:var(--loss); color:var(--loss); }
.badge-mini { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
.badge-profit { background:rgba(34,197,94,.15); color:var(--profit); }
.badge-loss   { background:rgba(239,68,68,.15); color:var(--loss); }
.insight-card { background:var(--bg-elevated); border-radius:var(--radius); padding:14px; display:flex; gap:12px; align-items:flex-start; height:100%; }
.insight-icon { font-size:20px; flex-shrink:0; margin-top:2px; }
.insight-text { font-size:13px; color:var(--text-secondary); line-height:1.5; }
.heatmap-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:3px; }
.heat-cell { aspect-ratio:1; border-radius:3px; cursor:default; }
.heat-dot { display:inline-block; width:8px; height:8px; border-radius:50%; }
.w-100 { width:100%; }
</style>

<?php include '../includes/footer.php'; ?>

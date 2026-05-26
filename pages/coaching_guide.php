<?php
require_once '../config/db.php';
$pageTitle = 'Improvement Guide';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
$today     = date('Y-m-d');
$db        = getDB();

// ── Pull last 30 days of recorded discipline data ─────────────────────────
$stmt = $db->prepare("SELECT * FROM discipline_calendar
    WHERE user_id=? AND cal_date <= ? ORDER BY cal_date DESC LIMIT 30");
$stmt->execute([$userId, $today]);
$records = $stmt->fetchAll();

$totalRecorded = count($records);

// Aggregate scores
$avgDisc = $avgPsych = $avgRisk = 0;
$markCount = ['green'=>0,'yellow'=>0,'red'=>0,'stop'=>0,'star'=>0];
$allRuleBreaks = ''; $allEmoMistakes = ''; $allImprove = '';

foreach ($records as $r) {
    $avgDisc  += (int)$r['discipline_score'];
    $avgPsych += (int)$r['psychology_score'];
    $avgRisk  += (int)$r['risk_score'];
    if ($r['day_mark']) $markCount[$r['day_mark']] = ($markCount[$r['day_mark']] ?? 0) + 1;
    $allRuleBreaks  .= ' ' . strtolower($r['rule_breaks']  ?? '');
    $allEmoMistakes .= ' ' . strtolower($r['emotional_mistakes'] ?? '');
    $allImprove     .= ' ' . strtolower($r['what_to_improve'] ?? '');
}

if ($totalRecorded > 0) {
    $avgDisc  = round($avgDisc  / $totalRecorded, 1);
    $avgPsych = round($avgPsych / $totalRecorded, 1);
    $avgRisk  = round($avgRisk  / $totalRecorded, 1);
}

// Keyword frequency from free-text fields
function countKeywords(string $text, array $keywords): array {
    $counts = [];
    foreach ($keywords as $kw) {
        $n = preg_match_all('/\b' . preg_quote($kw, '/') . '\b/i', $text, $m);
        if ($n > 0) $counts[$kw] = $n;
    }
    arsort($counts);
    return $counts;
}

$disciplineKeywords = ['stop loss','stop-loss','sl','take profit','tp','lot size','overtraded','overtrade',
    'max trades','entry','random entry','no plan','no setup','moved stop','risk','position size',
    'revenge','rules','checklist','plan'];
$psychKeywords = ['fomo','revenge','fear','greed','greedy','emotional','boredom','bored','impulse',
    'impulsive','anxious','anxiety','excited','frustrated','angry','panic','overthinking',
    'overconfident','doubt','hesitate','hesitation','regret'];

$ruleBreakFreq = countKeywords($allRuleBreaks . ' ' . $allImprove, $disciplineKeywords);
$emoFreq       = countKeywords($allEmoMistakes . ' ' . $allImprove, $psychKeywords);

// Today's trades for overtrading check
$stmt = $db->prepare("SELECT COUNT(*) as tc FROM trades WHERE user_id=? AND DATE(trade_datetime)=?");
$stmt->execute([$userId, $today]);
$todayTradeCount = (int)$stmt->fetch()['tc'];

// Determine severity level for each area
function severity(float $score): string {
    if ($score >= 8) return 'good';
    if ($score >= 5) return 'warning';
    return 'critical';
}
$discSev  = $totalRecorded > 0 ? severity($avgDisc)  : 'no-data';
$psychSev = $totalRecorded > 0 ? severity($avgPsych) : 'no-data';
$riskSev  = $totalRecorded > 0 ? severity($avgRisk)  : 'no-data';

// Win/loss pattern
$stmt = $db->prepare("SELECT
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END),0) as wins,
    COALESCE(SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END),0) as losses,
    COUNT(DISTINCT DATE(trade_datetime)) as trading_days,
    MAX(CASE WHEN close_reason='sl' THEN 1 ELSE 0 END) as has_sl_field
    FROM trades WHERE user_id=?");
$stmt->execute([$userId]);
$tradeStats = $stmt->fetch();
$winRate = $tradeStats['total'] > 0 ? round($tradeStats['wins'] / $tradeStats['total'] * 100) : 0;
$avgTradesPerDay = $tradeStats['trading_days'] > 0
    ? round($tradeStats['total'] / $tradeStats['trading_days'], 1) : 0;

// Stop loss usage
$stmt = $db->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN close_reason='sl' THEN 1 ELSE 0 END) as sl_hits
    FROM trades WHERE user_id=?");
$stmt->execute([$userId]);
$slStats = $stmt->fetch();
$slPct = $slStats['total'] > 0 ? round($slStats['sl_hits'] / $slStats['total'] * 100) : 0;

include '../includes/header.php';
?>
<style>
.guide-section{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.guide-section h3{font-size:1.1rem;font-weight:800;margin-bottom:6px}
.guide-section .section-sub{font-size:.85rem;color:var(--text-muted);margin-bottom:20px}
.score-bar-wrap{margin-bottom:14px}
.score-bar-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:.85rem}
.score-bar-track{height:10px;border-radius:6px;background:var(--border);overflow:hidden}
.score-bar-fill{height:100%;border-radius:6px;transition:.6s}
.sev-good{color:#22c55e}.sev-warning{color:#eab308}.sev-critical{color:#ef4444}.sev-no-data{color:var(--text-muted)}
.alert-block{border-radius:12px;padding:16px 20px;margin-bottom:12px;display:flex;gap:14px;align-items:flex-start}
.alert-block.critical{background:rgba(239,68,68,.08);border:1.5px solid rgba(239,68,68,.3)}
.alert-block.warning {background:rgba(234,179,8,.08);border:1.5px solid rgba(234,179,8,.3)}
.alert-block.good    {background:rgba(34,197,94,.08);border:1.5px solid rgba(34,197,94,.3)}
.alert-block.info    {background:rgba(99,102,241,.08);border:1.5px solid rgba(99,102,241,.3)}
.alert-icon{font-size:1.5rem;flex-shrink:0;margin-top:2px}
.alert-title{font-weight:700;font-size:.92rem;margin-bottom:3px}
.alert-body{font-size:.85rem;color:var(--text-secondary);line-height:1.6}
.concept-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:640px){.concept-grid{grid-template-columns:1fr}}
.concept-card{border-radius:12px;padding:20px 22px;border:1.5px solid}
.concept-card.disc{background:rgba(37,99,235,.06);border-color:rgba(37,99,235,.25)}
.concept-card.psych{background:rgba(139,92,246,.06);border-color:rgba(139,92,246,.25)}
.concept-card h4{font-size:.95rem;font-weight:800;margin-bottom:10px}
.concept-card ul{margin:0;padding-left:18px;font-size:.85rem;color:var(--text-secondary);line-height:1.9}
.trap-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.83rem;font-weight:600;margin:4px}
.rule-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.rule-item:last-child{border-bottom:none}
.rule-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.8rem;flex-shrink:0;margin-top:1px}
.rule-title{font-weight:700;font-size:.9rem;margin-bottom:3px}
.rule-body{font-size:.83rem;color:var(--text-secondary);line-height:1.6}
.kw-tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.76rem;font-weight:700;margin:3px;background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.25)}
.no-data-msg{text-align:center;padding:32px;color:var(--text-muted);font-size:.9rem}
.priority-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:.73rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
.priority-1{background:rgba(239,68,68,.15);color:#ef4444}
.priority-2{background:rgba(234,179,8,.15);color:#ca8a04}
.priority-3{background:rgba(34,197,94,.15);color:#16a34a}
</style>

<div class="container-fluid px-4 py-4" style="max-width:960px">

    <div class="mb-4">
        <h2 style="font-weight:800;font-size:1.6rem;margin:0">Improvement Guide</h2>
        <div style="font-size:.87rem;color:var(--text-muted);margin-top:4px">
            What discipline and psychology mean — and exactly what <em>you</em> need to fix.
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         SECTION 1 — YOUR PERSONAL SCORES
    ══════════════════════════════════════════════════ -->
    <div class="guide-section">
        <h3>Your Current Performance Scores</h3>
        <div class="section-sub">Based on your last <?= $totalRecorded ?> recorded review<?= $totalRecorded !== 1 ? 's' : '' ?> in the discipline calendar.</div>

        <?php if ($totalRecorded === 0): ?>
        <div class="no-data-msg">
            <i class="fas fa-clipboard-list" style="font-size:2rem;margin-bottom:12px;display:block;color:var(--text-muted)"></i>
            No post-trade reviews recorded yet.<br>
            <a href="post_analysis.php" class="btn btn-primary btn-sm mt-3">Add Your First Review</a>
        </div>
        <?php else: ?>

        <?php foreach ([
            [$avgDisc,  $discSev,  'Discipline Score',   'How consistently you followed your trading rules'],
            [$avgPsych, $psychSev, 'Psychology Score',   'How well you controlled your emotions and mindset'],
            [$avgRisk,  $riskSev,  'Risk Management',    'How correctly you sized positions and respected limits'],
        ] as [$score, $sev, $label, $desc]):
            $barColor = $sev==='good' ? '#22c55e' : ($sev==='warning' ? '#eab308' : '#ef4444');
            $sevLabel = $sev==='good' ? 'Good' : ($sev==='warning' ? 'Needs Work' : 'Critical');
        ?>
        <div class="score-bar-wrap">
            <div class="score-bar-label">
                <span style="font-weight:700;font-size:.88rem"><?= $label ?> — <span style="color:<?= $barColor ?>"><?= $score ?>/10 (<?= $sevLabel ?>)</span></span>
                <span style="font-size:.78rem;color:var(--text-muted)"><?= $desc ?></span>
            </div>
            <div class="score-bar-track">
                <div class="score-bar-fill" style="width:<?= $score*10 ?>%;background:<?= $barColor ?>"></div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Day mark distribution -->
        <?php $totalMarked = array_sum($markCount); if ($totalMarked > 0): ?>
        <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--border)">
            <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Day Mark Breakdown</div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php
                $markDef = [
                    'star'  =>['⭐','#f59e0b','Perfect'],
                    'green' =>['🟢','#22c55e','Rules OK'],
                    'yellow'=>['🟡','#eab308','Minor'],
                    'red'   =>['🔴','#ef4444','Major'],
                    'stop'  =>['⛔','#7c3aed','Breakdown'],
                ];
                foreach ($markDef as $mk=>[$em,$col,$lbl]): if (!$markCount[$mk]) continue; ?>
                <div style="text-align:center;padding:10px 16px;border-radius:10px;background:<?=$col?>14;border:1.5px solid <?=$col?>44;min-width:72px">
                    <div style="font-size:1.4rem"><?=$em?></div>
                    <div style="font-size:1.2rem;font-weight:800;color:<?=$col?>"><?=$markCount[$mk]?></div>
                    <div style="font-size:.72rem;color:var(--text-muted)"><?=$lbl?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════
         SECTION 2 — PERSONALISED ALERTS
    ══════════════════════════════════════════════════ -->
    <?php if ($totalRecorded > 0): ?>
    <div class="guide-section">
        <h3>What You Specifically Need to Fix</h3>
        <div class="section-sub">Identified from your actual journal entries, rule-break logs, and scores.</div>

        <?php
        // Build personalised alerts
        $alerts = [];

        // Discipline score alert
        if ($avgDisc < 5) {
            $alerts[] = ['critical','fa-triangle-exclamation','Critical: Discipline Is Breaking Down',
                'Your average discipline score is ' . $avgDisc . '/10. This means you are regularly ignoring your own rules. ' .
                'Until this is above 7, you should not be increasing lot sizes or trading new strategies. ' .
                'Focus only on rule-following, nothing else.'];
        } elseif ($avgDisc < 8) {
            $alerts[] = ['warning','fa-circle-exclamation','Discipline Needs Consistency',
                'Your average discipline score is ' . $avgDisc . '/10. You are following rules sometimes but not consistently enough. ' .
                'Inconsistent discipline is just as dangerous as no discipline — because you never know which version of yourself will show up.'];
        } else {
            $alerts[] = ['good','fa-circle-check','Discipline Is Solid',
                'Your average discipline score is ' . $avgDisc . '/10. You are following your rules consistently. ' .
                'Keep protecting this — one emotional day can reset weeks of progress.'];
        }

        // Psychology score alert
        if ($avgPsych < 5) {
            $alerts[] = ['critical','fa-brain','Critical: Emotional Control Is Missing',
                'Your average psychology score is ' . $avgPsych . '/10. Your emotions are actively damaging your trading. ' .
                'Revenge trading, FOMO entries, and panic exits are costing you money that has nothing to do with market conditions. ' .
                'This must be the #1 priority to fix.'];
        } elseif ($avgPsych < 8) {
            $alerts[] = ['warning','fa-brain','Psychology Is Inconsistent',
                'Your average psychology score is ' . $avgPsych . '/10. You are emotionally stable on some days but not all. ' .
                'Identify which conditions trigger your emotional trading — time of day, after a loss, news events — and build a rule around each trigger.'];
        } else {
            $alerts[] = ['good','fa-brain','Emotional Control Is Strong',
                'Your average psychology score is ' . $avgPsych . '/10. You are managing your emotions well. ' .
                'This is rarer than you think — protect it.'];
        }

        // Risk score alert
        if ($avgRisk < 5) {
            $alerts[] = ['critical','fa-shield-halved','Critical: Risk Management Is Broken',
                'Your average risk score is ' . $avgRisk . '/10. This is the most dangerous area — poor risk management causes account-ending losses. ' .
                'Every single trade must have a stop loss, a defined lot size, and a reason. No exceptions.'];
        } elseif ($avgRisk < 8) {
            $alerts[] = ['warning','fa-shield-halved','Risk Management Needs Tightening',
                'Your average risk score is ' . $avgRisk . '/10. You are managing risk on most trades but slipping on some. ' .
                'The trades where you skip the stop loss or increase size emotionally are the ones that will blow your account.'];
        }

        // Overtrading
        if ($avgTradesPerDay > MAX_TRADES_PER_DAY) {
            $alerts[] = ['critical','fa-fire','You Are Overtrading',
                'Your average is ' . $avgTradesPerDay . ' trades per day against your max of ' . MAX_TRADES_PER_DAY . '. ' .
                'More trades does not mean more profit — it means more exposure to emotional decisions. ' .
                'Quality over quantity. Wait for your setup. Missing a trade is not a loss.'];
        }

        // SL usage
        if ($slStats['total'] > 5 && $slPct < 40) {
            $alerts[] = ['critical','fa-ban','Stop Losses Are Not Being Used',
                'Only ' . $slPct . '% of your trades were closed by a stop loss, suggesting most trades either have no SL set or you are manually closing before it triggers. ' .
                'Set your stop loss the moment you enter — before anything else. If you move it, you are gambling, not trading.'];
        }

        // Win rate context
        if ($tradeStats['total'] >= 10 && $winRate < 40) {
            $alerts[] = ['warning','fa-chart-bar','Win Rate Is Low — Check Your Entries',
                'Your win rate is ' . $winRate . '%. Below 40% usually means either your entries lack clear setups, you are entering too early, or your stop losses are too tight. ' .
                'Review your losing trades — are they random entries or confirmed setups that just didn\'t work? The answer changes everything.'];
        }

        // Keyword-based alerts
        if (!empty($ruleBreakFreq)) {
            $top = array_slice($ruleBreakFreq, 0, 3, true);
            $kws = implode(', ', array_map('ucfirst', array_keys($top)));
            $alerts[] = ['warning','fa-list-check','Recurring Rule Breaks Detected',
                'Your journals mention these patterns repeatedly: <strong>' . htmlspecialchars($kws) . '</strong>. ' .
                'These are not random mistakes — they are habits. Write each one on paper and stick it next to your screen.'];
        }

        if (!empty($emoFreq)) {
            $top = array_slice($emoFreq, 0, 3, true);
            $kws = implode(', ', array_map('ucfirst', array_keys($top)));
            $alerts[] = ['warning','fa-face-grimace','Emotional Patterns Identified',
                'Your emotional mistakes consistently involve: <strong>' . htmlspecialchars($kws) . '</strong>. ' .
                'These emotions are predictable — which means they are manageable. ' .
                'The next time you feel one of these, treat it as a stop signal, not a trade signal.'];
        }

        // Stop/red day count
        $badDays = ($markCount['stop'] ?? 0) + ($markCount['red'] ?? 0);
        $pctBad  = $totalRecorded > 0 ? round($badDays / $totalRecorded * 100) : 0;
        if ($pctBad >= 30) {
            $alerts[] = ['critical','fa-calendar-xmark',$pctBad . '% of Your Days Are Red or Worse',
                $badDays . ' out of ' . $totalRecorded . ' recorded days had major rule breaks or emotional breakdowns. ' .
                'This frequency means discipline problems are not occasional slips — they are your default pattern. ' .
                'Start with one rule only: no revenge trading. Master that before adding more.'];
        }

        foreach ($alerts as [$cls,$icon,$title,$body]):
        ?>
        <div class="alert-block <?= $cls ?>">
            <div class="alert-icon"><i class="fas <?= $icon ?>" style="color:<?= $cls==='critical'?'#ef4444':($cls==='warning'?'#eab308':($cls==='good'?'#22c55e':'#818cf8')) ?>"></i></div>
            <div>
                <div class="alert-title"><?= $title ?></div>
                <div class="alert-body"><?= $body ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════
         SECTION 3 — WHAT IS DISCIPLINE?
    ══════════════════════════════════════════════════ -->
    <div class="guide-section">
        <h3 style="color:#3b82f6">What Is Trading Discipline?</h3>
        <div class="section-sub">Discipline is the gap between knowing what to do and actually doing it — every single time.</div>

        <div style="font-size:.9rem;color:var(--text-secondary);line-height:1.8;margin-bottom:20px">
            Trading discipline means executing your trading plan without deviation, regardless of how you feel in the moment.
            A disciplined trader does the same thing on a winning day as on a losing day — because they know the process is correct
            even when the result is not. <strong style="color:var(--text-primary)">A good trade can still lose. A bad trade can still win.
            Judge the process, not the result.</strong>
        </div>

        <div class="concept-grid">
            <div class="concept-card disc">
                <h4 style="color:#3b82f6"><i class="fas fa-check-circle me-2"></i>A Disciplined Trader</h4>
                <ul>
                    <li>Only enters on confirmed, pre-planned setups</li>
                    <li>Sets stop loss before entry — never moves it wider</li>
                    <li>Respects max trades per day (your limit: <?= MAX_TRADES_PER_DAY ?>)</li>
                    <li>Stops trading after hitting daily loss limit</li>
                    <li>Does not chase missed moves</li>
                    <li>Records every trade with a reason</li>
                    <li>Reviews performance after every session</li>
                    <li>Is the same trader on good days and bad days</li>
                </ul>
            </div>
            <div class="concept-card" style="background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.2)">
                <h4 style="color:#ef4444"><i class="fas fa-times-circle me-2"></i>An Undisciplined Trader</h4>
                <ul>
                    <li>Enters without a clear setup or reason</li>
                    <li>Moves stop loss to avoid being stopped out</li>
                    <li>Takes "just one more" trade after limit</li>
                    <li>Keeps trading after a bad loss to recover</li>
                    <li>Jumps into a move that is already 80% complete</li>
                    <li>Increases lot size to win back losses faster</li>
                    <li>Does not journal or review trades</li>
                    <li>Blames the market for emotional decisions</li>
                </ul>
            </div>
        </div>

        <div style="margin-top:20px;padding:18px 22px;background:rgba(37,99,235,.06);border-radius:12px;border-left:4px solid #3b82f6">
            <div style="font-weight:700;font-size:.9rem;margin-bottom:6px;color:#3b82f6">The Coach's Definition</div>
            <div style="font-size:.88rem;color:var(--text-secondary);line-height:1.7">
                Discipline is not about being perfect. It is about having a clear standard and returning to it immediately after every deviation.
                One bad trade does not destroy discipline. Refusing to acknowledge and correct it does.
                Every time you say "just this once" — you are training your brain that rules are optional.
                That habit kills accounts.
            </div>
        </div>

        <!-- Discipline improvement rules -->
        <div style="margin-top:22px">
            <div style="font-weight:700;font-size:.9rem;margin-bottom:14px">How to Build Discipline — Step by Step</div>
            <?php foreach ([
                ['#3b82f6','Write your rules down before you open your charts',
                    'Not in your head — on paper or a pinned note. Your rules must exist outside your brain because when emotions hit, your brain rewrites the rules to justify what it wants to do.'],
                ['#3b82f6','Use the Pre-Trade Checklist every single day',
                    'The 7-question checklist is not optional. It forces a pause between waking up and trading. That pause is where discipline lives.'],
                ['#3b82f6','Set your stop loss as the very first action after entry',
                    'Before you look at P/L. Before you adjust take profit. Before anything. Stop loss first.'],
                ['#3b82f6','Hard stop after daily loss limit — close the platform',
                    'Not "I\'ll just watch." Close it. Your max loss for today is ' . formatUSD(MAX_DAILY_LOSS_DOLLAR) . '. After that, your job is done.'],
                ['#3b82f6','Track every rule break in the Post-Trade Review',
                    'You cannot fix what you do not measure. Every time you write "moved stop loss" in your journal, you are building awareness. Awareness precedes change.'],
            ] as $i => [$col,$title,$body]): ?>
            <div class="rule-item">
                <div class="rule-num" style="background:<?=$col?>18;color:<?=$col?>"><?= $i+1 ?></div>
                <div>
                    <div class="rule-title"><?= $title ?></div>
                    <div class="rule-body"><?= $body ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         SECTION 4 — WHAT IS PSYCHOLOGY?
    ══════════════════════════════════════════════════ -->
    <div class="guide-section">
        <h3 style="color:#8b5cf6">What Is Trading Psychology?</h3>
        <div class="section-sub">Psychology is the mental game — how your emotions, biases, and beliefs distort your decisions in real time.</div>

        <div style="font-size:.9rem;color:var(--text-secondary);line-height:1.8;margin-bottom:20px">
            Every trader has a strategy. Most strategies work — when followed correctly. The reason most traders fail is not the strategy.
            It is that they <strong style="color:var(--text-primary)">cannot follow the strategy when it matters most</strong> — after a loss,
            during a fast market, or when a trade is moving against them.
            That is a psychology problem, not a technical problem.
        </div>

        <div style="font-weight:700;font-size:.9rem;margin-bottom:12px">The 6 Psychological Traps That Destroy Traders</div>

        <?php foreach ([
            ['#ef4444','fa-rotate-left','Revenge Trading',
                'After a losing trade, you feel a burning need to immediately take another trade to "get it back." ' .
                'This trade has nothing to do with the market — it is 100% emotional. It almost always makes the loss worse.',
                'Rule: After any loss, wait 15 minutes before entering again. If you still feel angry, stop for the day.'],
            ['#f59e0b','fa-bolt','FOMO — Fear Of Missing Out',
                'A big move happens. You were not in it. You feel like you missed something and jump in late, often near the top or bottom. ' .
                'You are not entering a trade — you are chasing regret.',
                'Rule: If you missed the move, you missed it. A missed trade costs $0. A FOMO trade can cost everything. The market will give another setup.'],
            ['#ef4444','fa-dollar-sign','Greed — Moving Take Profit',
                'Your trade is near target. Instead of closing, you move the take profit higher because "it might go further." ' .
                'Then the market reverses and you exit at breakeven or a loss.',
                'Rule: Set your take profit before entry. Do not touch it. If you want to trail, decide that before entry too — not in the heat of the moment.'],
            ['#eab308','fa-eye-slash','Fear — Closing Too Early',
                'Your trade is going the right direction but you close it at 30% of your target because you are afraid it will reverse. ' .
                'Meanwhile it hits your original target without you.',
                'Rule: Let your stop loss and take profit do their job. Early exits based on fear consistently under-perform letting trades run to target.'],
            ['#8b5cf6','fa-arrows-repeat','Overtrading From Boredom',
                'The market is quiet. Nothing is setting up. You take a trade anyway because sitting still feels like wasting time. ' .
                'This is not trading — it is gambling dressed as trading.',
                'Rule: No setup = no trade. Period. Your job is to wait, not to be busy.'],
            ['#3b82f6','fa-arrow-trend-up','Overconfidence After a Win Streak',
                'After 3-4 winning trades, you feel invincible. You increase lot size, skip your checklist, take marginal setups. ' .
                'The market does not care about your win streak.',
                'Rule: After a winning streak, become more cautious, not less. Reduce size slightly to stay grounded.'],
        ] as [$col,$icon,$title,$trap,$fix]): ?>
        <div style="border-radius:12px;padding:18px 20px;margin-bottom:12px;background:var(--bg-base);border:1.5px solid var(--border)">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="width:32px;height:32px;border-radius:8px;background:<?=$col?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fas <?=$icon?>" style="color:<?=$col?>;font-size:.9rem"></i>
                </span>
                <span style="font-weight:800;font-size:.92rem"><?=$title?></span>
                <span class="kw-tag" style="background:<?=$col?>14;color:<?=$col?>;border-color:<?=$col?>44">Trap</span>
            </div>
            <div style="font-size:.85rem;color:var(--text-secondary);line-height:1.7;margin-bottom:8px"><?=$trap?></div>
            <div style="font-size:.83rem;padding:10px 14px;border-radius:8px;background:<?=$col?>0d;border-left:3px solid <?=$col?>;color:var(--text-secondary)">
                <strong style="color:<?=$col?>">Fix:</strong> <?=$fix?>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:20px;padding:18px 22px;background:rgba(139,92,246,.06);border-radius:12px;border-left:4px solid #8b5cf6">
            <div style="font-weight:700;font-size:.9rem;margin-bottom:6px;color:#8b5cf6">The Coach's Definition</div>
            <div style="font-size:.88rem;color:var(--text-secondary);line-height:1.7">
                Psychology is not about being emotionless. You will always feel something when trading. The goal is not to remove the emotion —
                it is to create a gap between the emotion and the action. That gap is where your rules live.
                When you feel the urge to revenge trade, the emotion is normal. Acting on it immediately is the problem.
                Pause. Breathe. Ask: "Is this my setup, or is this my ego?"
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         SECTION 5 — YOUR ACTION PLAN
    ══════════════════════════════════════════════════ -->
    <div class="guide-section" style="background:linear-gradient(135deg,rgba(37,99,235,.05),rgba(139,92,246,.05));border-color:rgba(99,102,241,.2)">
        <h3>Your Priority Action Plan</h3>
        <div class="section-sub">Focus on these in order. Do not try to fix everything at once — that is also an emotional decision.</div>

        <?php
        $priorities = [];

        if ($totalRecorded === 0) {
            $priorities[] = [1,'Start recording every day','Complete the Post-Trade Review every day without exception. You cannot improve what you do not measure. Start today.'];
        } else {
            if ($discSev === 'critical')
                $priorities[] = [1,'Fix discipline first — follow rules on every single trade','Scores below 5 mean rules are being broken regularly. Nothing else matters until this is fixed.'];
            elseif ($psychSev === 'critical')
                $priorities[] = [1,'Eliminate emotional trading — revenge and FOMO especially','Psychology score below 5 means emotions are actively controlling your decisions. This is costing real money.'];
            elseif ($riskSev === 'critical')
                $priorities[] = [1,'Fix risk management — stop loss on every trade, no exceptions','Risk score below 5 is the most dangerous pattern. One unprotected trade can undo weeks of work.'];

            if ($avgTradesPerDay > MAX_TRADES_PER_DAY)
                $priorities[] = [count($priorities)+1,'Reduce to maximum ' . MAX_TRADES_PER_DAY . ' trades per day','You are averaging ' . $avgTradesPerDay . ' trades/day. Quality setups only. If you have taken ' . MAX_TRADES_PER_DAY . ' trades, close the platform.'];

            if ($discSev === 'warning' && count($priorities) < 3)
                $priorities[] = [count($priorities)+1,'Make discipline consistent — aim for 8/10 every day','You follow rules sometimes. That is not enough. The days you slip are the ones that cost you.'];

            if ($psychSev === 'warning' && count($priorities) < 3)
                $priorities[] = [count($priorities)+1,'Identify and name your emotional triggers','Write down what specifically makes you break rules — is it after a loss? During fast markets? At a certain time of day? Name it so you can catch it.'];

            if (!empty($ruleBreakFreq) && count($priorities) < 3) {
                $topKw = array_key_first($ruleBreakFreq);
                $priorities[] = [count($priorities)+1,'Address your #1 recurring pattern: "' . ucfirst($topKw) . '"','This word appears most in your rule-break journals. It is your current biggest habit to break.'];
            }

            if (count($priorities) < 3)
                $priorities[] = [count($priorities)+1,'Complete the Pre-Trade Checklist every morning','Even on good weeks, the checklist keeps you grounded. Skip it and you are one bad morning away from an emotional session.'];

            if (count($priorities) < 3)
                $priorities[] = [count($priorities)+1,'Review this guide weekly and update your scores','Awareness fades. Read this page every week and compare your scores from the discipline calendar. Progress is motivation.'];
        }

        foreach ($priorities as [$p,$title,$body]):
            $pClass = $p===1?'priority-1':($p===2?'priority-2':'priority-3');
            $pLabel = $p===1?'Priority 1':($p===2?'Priority 2':'Priority 3');
        ?>
        <div class="rule-item">
            <div class="rule-num" style="background:<?= $p===1?'rgba(239,68,68,.15)':($p===2?'rgba(234,179,8,.15)':'rgba(34,197,94,.15)') ?>;color:<?= $p===1?'#ef4444':($p===2?'#ca8a04':'#16a34a') ?>"><?=$p?></div>
            <div>
                <div class="rule-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <?= $title ?>
                    <span class="priority-badge <?=$pClass?>"><?=$pLabel?></span>
                </div>
                <div class="rule-body"><?= $body ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="d-flex gap-3 mt-4 flex-wrap">
            <a href="pre_checklist.php" class="btn btn-primary btn-sm">
                <i class="fas fa-clipboard-check me-1"></i>Go to Pre-Trade Check
            </a>
            <a href="post_analysis.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-chart-line me-1"></i>Post-Trade Review
            </a>
            <a href="coach.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-brain me-1"></i>Coach Dashboard
            </a>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>

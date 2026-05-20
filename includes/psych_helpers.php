<?php
/**
 * Shared helper functions for the Psychology & Discipline Tracker module.
 * Included by: pages/psych_tracker.php, pages/psych_daily.php, pages/psych_analytics.php
 */

function getHabitDefs(): array {
    return [
        'over_trading'      => ['label' => 'Over Trading',                    'icon' => 'fas fa-layer-group',       'color' => '#f59e0b', 'desc' => 'Took more trades than the daily limit allows'],
        'revenge_trading'   => ['label' => 'Revenge Trading',                 'icon' => 'fas fa-fire',              'color' => '#ef4444', 'desc' => 'Traded to recover losses emotionally'],
        'hope_trading'      => ['label' => 'Hope Trading',                    'icon' => 'fas fa-hand-holding-heart','color' => '#a855f7', 'desc' => 'Held a losing trade hoping it would reverse'],
        'no_stop_loss'      => ['label' => 'No Proper Stop Loss',             'icon' => 'fas fa-shield-halved',     'color' => '#ef4444', 'desc' => 'Entered a trade without a defined stop loss'],
        'no_risk_mgmt'      => ['label' => 'No Risk Management',              'icon' => 'fas fa-triangle-exclamation','color' => '#ef4444','desc' => 'Risked more than the allowed % per trade'],
        'rr_not_booked'     => ['label' => 'Not Booking at 1:2 RR',           'icon' => 'fas fa-scale-balanced',   'color' => '#f59e0b', 'desc' => 'Exited before hitting 2R take profit target'],
        'fear_of_levels'    => ['label' => 'Fear of Level-Based Trades',      'icon' => 'fas fa-ghost',             'color' => '#6366f1', 'desc' => 'Skipped valid setups due to fear or hesitation'],
        'no_levels'         => ['label' => 'Trading Without Levels',          'icon' => 'fas fa-ban',               'color' => '#f97316', 'desc' => 'Entered trades without identifying key levels'],
        'trading_after_loss'=> ['label' => 'Trading After Max Daily Loss',    'icon' => 'fas fa-skull-crossbones',  'color' => '#dc2626', 'desc' => 'Continued trading after hitting the daily loss limit'],
    ];
}

function calcDisciplineScore(array $habitsTriggered, array $habitSeverity, array $reflections): int {
    $score = 100;

    // Penalty per triggered habit based on severity (1=mild, 2=moderate, 3=severe)
    foreach ($habitsTriggered as $code) {
        $sev = (int)($habitSeverity[$code] ?? 2);
        $score -= match($sev) {
            1 => 5,
            3 => 20,
            default => 10,
        };
    }

    if (isset($reflections['followed_plan'])  && !$reflections['followed_plan'])  $score -= 10;
    if (isset($reflections['emotional_entry']) && $reflections['emotional_entry']) $score -= 10;
    if (isset($reflections['emotional_exit'])  && $reflections['emotional_exit'])  $score -= 10;
    if (isset($reflections['forced_trade'])    && $reflections['forced_trade'])    $score -= 15;
    if (isset($reflections['had_patience'])    && !$reflections['had_patience'])   $score -= 5;
    if (isset($reflections['followed_rules'])  && !$reflections['followed_rules']) $score -= 15;

    return max(0, $score);
}

function calcPsychologyScore(?string $preEmotion, array $reflections): int {
    $base = match($preEmotion) {
        'calm'      => 100,
        'confident' => 90,
        'fear'      => 50,
        'greedy'    => 50,
        'angry'     => 40,
        'fomo'      => 30,
        'revenge'   => 20,
        default     => 70,
    };

    if (isset($reflections['had_patience'])   && $reflections['had_patience'])    $base += 10;
    if (isset($reflections['followed_plan'])  && $reflections['followed_plan'])   $base += 10;
    if (isset($reflections['emotional_entry']) && $reflections['emotional_entry']) $base -= 15;
    if (isset($reflections['emotional_exit'])  && $reflections['emotional_exit'])  $base -= 15;
    if (isset($reflections['forced_trade'])    && $reflections['forced_trade'])    $base -= 20;
    if (isset($reflections['entered_early'])   && $reflections['entered_early'])   $base -= 10;

    return max(0, min(100, $base));
}

function calcEmotionalStability(?string $preEmotion, array $habitsTriggered, array $reflections): int {
    $score = 100;

    $score += match($preEmotion) {
        'revenge' => -30,
        'fomo'    => -30,
        'angry'   => -25,
        'fear'    => -15,
        'greedy'  => -15,
        'calm'    =>   0,
        'confident' => 0,
        default   =>  -5,
    };

    if (in_array('revenge_trading', $habitsTriggered)) $score -= 25;
    if (in_array('fear_of_levels',  $habitsTriggered)) $score -= 15;
    if (in_array('hope_trading',    $habitsTriggered)) $score -= 10;
    if (in_array('trading_after_loss', $habitsTriggered)) $score -= 20;

    if (isset($reflections['emotional_entry']) && $reflections['emotional_entry']) $score -= 15;
    if (isset($reflections['emotional_exit'])  && $reflections['emotional_exit'])  $score -= 15;
    if (isset($reflections['entered_early'])   && $reflections['entered_early'])   $score -= 10;
    if (isset($reflections['forced_trade'])    && $reflections['forced_trade'])    $score -= 10;
    if (isset($reflections['had_patience'])    && $reflections['had_patience'])    $score += 10;

    return max(0, min(100, $score));
}

function calcTradeQualityScore(array $c): int {
    $score = ($c['setup_quality']     / 10 * 20)
           + ($c['risk_management']   / 10 * 20)
           + ($c['rr_quality']        / 10 * 15)
           + ($c['rule_following']    / 10 * 15)
           + ($c['emotional_control'] / 10 * 15)
           + ($c['patience']          / 10 * 10)
           + ($c['sl_discipline']     / 10 * 5);
    return (int)round(min(100, max(0, $score)));
}

function generateCoachFeedback(array $habitsTriggered, ?string $preEmotion, array $reflections, array $ruleStats = []): string {
    $msgs = [];

    // Critical priority first
    if (in_array('trading_after_loss', $habitsTriggered))
        $msgs[] = "You continued trading after hitting your maximum daily loss. Protecting capital is your highest duty — the market will always be here tomorrow.";

    if (in_array('revenge_trading', $habitsTriggered))
        $msgs[] = "You are reacting emotionally to losses. Revenge trading accelerates drawdowns — step away, reset, and return only when calm.";

    if (in_array('over_trading', $habitsTriggered))
        $msgs[] = "More trades do not improve your edge. Quality setups are rare — wait patiently for A+ opportunities only.";

    if (in_array('no_stop_loss', $habitsTriggered))
        $msgs[] = "You entered a trade without a stop loss. Every trade requires a defined exit — undefined risk is the fastest path to account destruction.";

    if (in_array('hope_trading', $habitsTriggered))
        $msgs[] = "You held a trade hoping the market would reverse. Hope is not a strategy — cut losses quickly and move on.";

    if (in_array('no_risk_mgmt', $habitsTriggered))
        $msgs[] = "Risk management is the foundation of longevity. Correct position sizing must be calculated before every entry, not after.";

    if (in_array('rr_not_booked', $habitsTriggered))
        $msgs[] = "You exited before reaching your 1:2 reward target. Let winners run to your target — premature exits erode your overall edge over time.";

    if (in_array('no_levels', $habitsTriggered))
        $msgs[] = "You traded without identifying key levels. Every trade must have a clear structural reason — randomness compounds losses.";

    if (in_array('fear_of_levels', $habitsTriggered))
        $msgs[] = "You skipped quality setups because of fear. Trust your analysis and your preparation — hesitation is just fear in disguise.";

    // Emotion-based feedback
    if ($preEmotion === 'revenge')
        $msgs[] = "You entered the session in revenge mode. The best trade you can make on a revenge day is no trade at all.";
    elseif ($preEmotion === 'fomo')
        $msgs[] = "FOMO drove your session today. Missed trades are not losses — only bad trades are losses.";
    elseif ($preEmotion === 'angry')
        $msgs[] = "Anger impairs judgment severely. Recognise emotional states before they cost you capital.";

    // Reflection-based feedback
    if (isset($reflections['followed_plan']) && !$reflections['followed_plan'])
        $msgs[] = "You deviated from your trading plan. A plan followed imperfectly beats no plan — rebuild your confidence in your own process.";

    if (isset($reflections['emotional_entry']) && $reflections['emotional_entry'])
        $msgs[] = "You made an emotional entry today. Pause and assess your emotional state before each entry — enter only with a clear, logical reason.";

    // Praise if no issues
    if (empty($msgs)) {
        if ($preEmotion === 'calm' || $preEmotion === 'confident') {
            $msgs[] = "Excellent discipline and consistency today. You traded with clarity and control — this is the process that builds a sustainable career.";
        } else {
            $msgs[] = "Solid session. Keep following your process consistently — discipline compounded over time is unbeatable.";
        }
    }

    // Rule stats feedback
    if (!empty($ruleStats)) {
        if (!empty($ruleStats['over_trade_count']))
            $msgs[] = "You placed " . $ruleStats['over_trade_count'] . " trades today — exceeding the " . MAX_TRADES_PER_DAY . " trade limit.";
        if (!empty($ruleStats['no_sl_count']))
            $msgs[] = $ruleStats['no_sl_count'] . " trade(s) had no stop loss defined.";
        if (!empty($ruleStats['rr_violation_count']))
            $msgs[] = $ruleStats['rr_violation_count'] . " trade(s) did not meet the 1:2 RR minimum.";
    }

    return implode(' ', array_slice($msgs, 0, 3));
}

function getPsychDayMark(array $row): string {
    $score   = isset($row['discipline_score']) ? (int)$row['discipline_score'] : null;
    if ($score === null) return 'none';
    $habits  = json_decode($row['habits_triggered'] ?? '[]', true) ?: [];
    $critical = array_intersect($habits, ['revenge_trading', 'trading_after_loss']);
    if (!empty($critical) || $score < 30) return 'stop';
    if ($score >= 85 && count($habits) === 0) return 'star';
    if ($score >= 70) return 'green';
    if ($score >= 50) return 'yellow';
    return 'red';
}

function getPsychStreak(int $userId, string $habitCode, \PDO $db): int {
    $stmt = $db->prepare(
        "SELECT entry_date, habits_triggered FROM psych_daily
         WHERE user_id = ? AND entry_date <= CURDATE()
         ORDER BY entry_date DESC LIMIT 60"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $streak = 0;
    foreach ($rows as $row) {
        $habits = json_decode($row['habits_triggered'] ?? '[]', true) ?: [];
        if (!in_array($habitCode, $habits)) {
            $streak++;
        } else {
            break;
        }
    }
    return $streak;
}

function getScoreColor(int $score): string {
    if ($score >= 70) return 'var(--profit)';
    if ($score >= 50) return 'var(--warning)';
    return 'var(--loss)';
}

function getScoreLabel(int $score): string {
    if ($score >= 80) return 'Excellent';
    if ($score >= 65) return 'Good';
    if ($score >= 50) return 'Fair';
    if ($score >= 35) return 'Poor';
    return 'Critical';
}

function getEmotionEmoji(?string $emotion): string {
    return match($emotion) {
        'calm'      => '🧘',
        'fear'      => '😨',
        'greedy'    => '🤑',
        'angry'     => '😤',
        'confident' => '💪',
        'revenge'   => '😡',
        'fomo'      => '🏃',
        default     => '😐',
    };
}

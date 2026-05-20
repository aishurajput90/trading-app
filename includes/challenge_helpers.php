<?php
/**
 * Shared helper functions for the Trading Discipline Challenge module.
 * Included by: pages/challenge.php, pages/challenge_day.php, pages/challenge_report.php
 */

function calculateDisciplineScore(array $checks, ?string $result, array $emotions): int {
    if ($result === null) return 0;

    $checkScore = 0;
    foreach ($checks as $val) $checkScore += ($val ? 5 : 0);

    $resultScore = match($result) {
        'followed' => 60,
        'no_trade' => 40,
        default    => 0,
    };

    $base = $checkScore + $resultScore;

    $posEmos = ['Calm', 'Confidence', 'Patience'];
    $negEmos = ['Revenge', 'FOMO', 'Overtrading', 'Greed', 'Fear'];
    $posBonus   = min(15, count(array_intersect($emotions, $posEmos)) * 5);
    $negPenalty = count(array_intersect($emotions, $negEmos)) * 5;

    $adjusted = $base + $posBonus - $negPenalty;
    return min(100, max($base, max(0, $adjusted)));
}

function calculateXP(?string $result, int $score, array $dayMap, string $targetDate): int {
    if ($result === null) return 0;

    $xp = 10;
    if ($result === 'followed')  $xp += 50;
    elseif ($result === 'no_trade') $xp += 25;
    elseif ($result === 'broke') $xp -= 20;

    if ($score >= 90) $xp += 30;
    elseif ($score >= 70) $xp += 15;

    // Streak milestone bonuses (consecutive 'followed' ending on targetDate)
    $streak = 0;
    $checkDate = $targetDate;
    while (true) {
        $rec = $dayMap[$checkDate] ?? null;
        $recResult = ($checkDate === $targetDate) ? $result : ($rec['result'] ?? null);
        if ($recResult === 'followed') {
            $streak++;
            $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
        } else {
            break;
        }
    }
    if ($streak === 7)  $xp += 100;
    if ($streak === 14) $xp += 200;
    if ($streak === 30) $xp += 500;

    return max(0, $xp);
}

function getLevelFromXP(int $xp): array {
    $levels = [
        1 => ['name' => 'Beginner',           'min' => 0,    'max' => 499],
        2 => ['name' => 'Apprentice',          'min' => 500,  'max' => 1499],
        3 => ['name' => 'Consistent Trader',   'min' => 1500, 'max' => 3499],
        4 => ['name' => 'Disciplined Trader',  'min' => 3500, 'max' => 6999],
        5 => ['name' => 'Master Trader',       'min' => 7000, 'max' => PHP_INT_MAX],
    ];
    foreach ($levels as $n => $l) {
        if ($xp >= $l['min'] && $xp <= $l['max']) {
            $nextMin = $levels[$n + 1]['min'] ?? $l['min'];
            $range   = ($n < 5) ? ($nextMin - $l['min']) : 1;
            $pct     = ($n < 5) ? min(100, (int)round(($xp - $l['min']) / $range * 100)) : 100;
            return [
                'level'       => $n,
                'name'        => $l['name'],
                'pct'         => $pct,
                'next_xp'     => $nextMin,
                'current_min' => $l['min'],
                'xp_in_level' => $xp - $l['min'],
                'xp_needed'   => max(0, $nextMin - $xp),
            ];
        }
    }
    return ['level' => 1, 'name' => 'Beginner', 'pct' => 0, 'next_xp' => 500, 'current_min' => 0, 'xp_in_level' => 0, 'xp_needed' => 500];
}

function computeBadges(array $days, array $challenge): array {
    $badges = [];
    $submitted = array_filter($days, fn($d) => $d['result'] !== null);

    if (count($submitted) >= 1) $badges[] = 'first_flame';

    // Max streak
    $maxStreak = 0; $curStreak = 0;
    foreach ($days as $d) {
        if ($d['result'] === 'followed') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
        else { $curStreak = 0; }
    }
    if ($maxStreak >= 7)  $badges[] = 'iron_discipline';
    if ($maxStreak >= 14) $badges[] = 'two_weeks_strong';
    if ($maxStreak >= 30) $badges[] = 'month_master';

    // Rulebook: zero broke days
    $brokeCount = count(array_filter($days, fn($d) => $d['result'] === 'broke'));
    if (count($submitted) > 0 && $brokeCount === 0) $badges[] = 'rulebook';

    // Profitable & Disciplined: followed + profitable ≥3 times
    $profDisciplined = count(array_filter($days, fn($d) => $d['result'] === 'followed' && $d['daily_pl'] > 0));
    if ($profDisciplined >= 3) $badges[] = 'profitable_disciplined';

    // Capital Guardian: 10 consecutive no-break days
    $noBreakStreak = 0; $maxNoBreak = 0;
    foreach ($days as $d) {
        if ($d['result'] !== 'broke' && $d['result'] !== null) { $noBreakStreak++; $maxNoBreak = max($maxNoBreak, $noBreakStreak); }
        else { $noBreakStreak = 0; }
    }
    if ($maxNoBreak >= 10) $badges[] = 'capital_guardian';

    // Zen Trader: 5 consecutive calm days
    $calmStreak = 0; $maxCalm = 0;
    foreach ($days as $d) {
        $emos = json_decode($d['emotions'] ?? '[]', true) ?: [];
        if (in_array('Calm', $emos) && $d['result'] !== null) { $calmStreak++; $maxCalm = max($maxCalm, $calmStreak); }
        else { $calmStreak = 0; }
    }
    if ($maxCalm >= 5) $badges[] = 'zen_trader';

    if ($challenge['status'] === 'completed') $badges[] = 'challenge_complete';

    return $badges;
}

function getBadgeDefs(): array {
    return [
        'first_flame'           => ['icon' => '🔥', 'name' => 'First Flame',              'desc' => 'Completed your first challenge day'],
        'iron_discipline'       => ['icon' => '⚔️',  'name' => 'Iron Discipline',          'desc' => '7-day consecutive rule streak'],
        'two_weeks_strong'      => ['icon' => '💪',  'name' => 'Two Weeks Strong',          'desc' => '14-day consecutive rule streak'],
        'month_master'          => ['icon' => '👑',  'name' => 'Month Master',              'desc' => '30-day consecutive rule streak'],
        'rulebook'              => ['icon' => '📖',  'name' => 'Rulebook',                  'desc' => 'Zero rule breaks in entire challenge'],
        'profitable_disciplined'=> ['icon' => '💰',  'name' => 'Profitable & Disciplined',  'desc' => 'Rules followed on 3+ profitable days'],
        'capital_guardian'      => ['icon' => '🛡️',  'name' => 'Capital Guardian',          'desc' => '10 consecutive days without breaking rules'],
        'zen_trader'            => ['icon' => '🧘',  'name' => 'Zen Trader',               'desc' => '5 consecutive calm-emotion days'],
        'challenge_complete'    => ['icon' => '🏆',  'name' => 'Challenge Complete',        'desc' => 'Successfully finished a discipline challenge'],
    ];
}

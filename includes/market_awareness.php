<?php
/**
 * Market Awareness Helper
 * Provides day-of-week intelligence, session status, FOMC countdown, NFP alerts
 * for the DisciplineOS dashboard widget.
 */

// ── 1. DAY OF WEEK ANALYSIS ──────────────────────────────────────────────────

function getMarketDayInfo(): array {
    $ist  = new DateTimeZone('Asia/Kolkata');
    $now  = new DateTime('now', $ist);
    $dow  = (int)$now->format('N'); // 1=Mon … 7=Sun

    $days = [
        1 => [
            'name'    => 'Monday',
            'short'   => 'MON',
            'icon'    => 'fa-hourglass-start',
            'rating'  => 'AVOID',
            'color'   => 'warn',
            'emoji'   => '⚠️',
            'tip'     => 'Market settling after weekend — fake moves & wide spreads likely.',
            'advice'  => 'Watch & wait. No trades until direction is clear.',
            'stars'   => 1,
        ],
        2 => [
            'name'    => 'Tuesday',
            'short'   => 'TUE',
            'icon'    => 'fa-chart-line',
            'rating'  => 'MEDIUM',
            'color'   => 'cyan',
            'emoji'   => '✅',
            'tip'     => 'Market warming up — weekly trend starting to form.',
            'advice'  => 'Wait for London session. Confirm trend before entering.',
            'stars'   => 3,
        ],
        3 => [
            'name'    => 'Wednesday',
            'short'   => 'WED',
            'icon'    => 'fa-fire',
            'rating'  => 'BEST DAY',
            'color'   => 'profit',
            'emoji'   => '🔥',
            'tip'     => 'Banks fully active — biggest institutional moves of the week!',
            'advice'  => 'FOMC weeks = 100-200 pip moves. Best setups on Wed.',
            'stars'   => 5,
        ],
        4 => [
            'name'    => 'Thursday',
            'short'   => 'THU',
            'icon'    => 'fa-bolt',
            'rating'  => 'BEST DAY',
            'color'   => 'profit',
            'emoji'   => '⚡',
            'tip'     => 'Strong momentum — US Jobless Claims at 6 PM IST.',
            'advice'  => 'Extend Wednesday winners. New setups in NY open.',
            'stars'   => 5,
        ],
        5 => [
            'name'    => 'Friday',
            'short'   => 'FRI',
            'icon'    => 'fa-triangle-exclamation',
            'rating'  => 'CAREFUL',
            'color'   => 'warn',
            'emoji'   => '⚠️',
            'tip'     => 'Banks square positions before weekend — sudden reversals.',
            'advice'  => 'Close before 10 PM IST. Never hold over weekend!',
            'stars'   => 2,
        ],
        6 => [
            'name'    => 'Saturday',
            'short'   => 'SAT',
            'icon'    => 'fa-moon',
            'rating'  => 'CLOSED',
            'color'   => 'muted',
            'emoji'   => '😴',
            'tip'     => 'Forex market is closed. Plan your week.',
            'advice'  => 'Review trades, study charts, plan setups for Monday.',
            'stars'   => 0,
        ],
        7 => [
            'name'    => 'Sunday',
            'short'   => 'SUN',
            'icon'    => 'fa-moon',
            'rating'  => 'CLOSED',
            'color'   => 'muted',
            'emoji'   => '😴',
            'tip'     => 'Market opens at 5:30 AM IST Monday.',
            'advice'  => 'Finalise your watchlist and mark key levels.',
            'stars'   => 0,
        ],
    ];

    return array_merge($days[$dow], [
        'dow'      => $dow,
        'date_str' => $now->format('l, d M Y'),
        'time_str' => $now->format('h:i A') . ' IST',
    ]);
}

// ── 2. SESSION STATUS ─────────────────────────────────────────────────────────

function getSessionStatus(): array {
    $ist  = new DateTimeZone('Asia/Kolkata');
    $now  = new DateTime('now', $ist);
    $h    = (int)$now->format('H');
    $m    = (int)$now->format('i');
    $mins = $h * 60 + $m;   // minutes since midnight IST

    // Boundaries in IST minutes
    // Asian:           05:30 – 12:29  (330–749)
    // London Open:     12:30 – 18:29  (750–1109)
    // London-NY Ovlp:  18:30 – 22:29  (1110–1349)
    // NY Late:         22:30 – 01:29  (1350–1439 + 0–89)
    // Closed:          01:30 – 05:29  (90–329)

    if ($mins >= 330 && $mins < 750) {
        return [
            'name'      => 'Asian Session',
            'short'     => 'ASIAN',
            'icon'      => 'fa-moon',
            'color'     => 'muted',
            'active'    => true,
            'best'      => false,
            'time_ist'  => '5:30 AM – 12:30 PM IST',
            'tip'       => 'Low volume. Choppy & slow — best to wait.',
        ];
    } elseif ($mins >= 750 && $mins < 1110) {
        return [
            'name'      => 'London Session',
            'short'     => 'LONDON',
            'icon'      => 'fa-landmark',
            'color'     => 'cyan',
            'active'    => true,
            'best'      => false,
            'time_ist'  => '12:30 PM – 6:30 PM IST',
            'tip'       => 'European banks active. Trends forming now.',
        ];
    } elseif ($mins >= 1110 && $mins < 1350) {
        return [
            'name'      => 'London–NY Overlap',
            'short'     => 'OVERLAP',
            'icon'      => 'fa-fire',
            'color'     => 'profit',
            'active'    => true,
            'best'      => true,
            'time_ist'  => '6:30 PM – 10:30 PM IST',
            'tip'       => '🔥 BEST TIME — Highest volume & biggest moves!',
        ];
    } elseif ($mins >= 1350 || $mins < 90) {
        return [
            'name'      => 'NY Late Session',
            'short'     => 'NY LATE',
            'icon'      => 'fa-city',
            'color'     => 'blue',
            'active'    => true,
            'best'      => false,
            'time_ist'  => '10:30 PM – 1:30 AM IST',
            'tip'       => 'Volume slowing. Watch for reversals near close.',
        ];
    } else {
        return [
            'name'      => 'Market Closed',
            'short'     => 'CLOSED',
            'icon'      => 'fa-lock',
            'color'     => 'muted',
            'active'    => false,
            'best'      => false,
            'time_ist'  => '1:30 AM – 5:30 AM IST',
            'tip'       => 'Market closed. Opens at 5:30 AM IST.',
        ];
    }
}

// ── 3. FOMC COUNTDOWN ────────────────────────────────────────────────────────

function getFOMCCountdown(): array {
    $fomcDates = [
        // 2025
        '2025-06-18',
        '2025-07-30',
        '2025-09-17',
        '2025-10-29',
        '2025-12-10',
        // 2026
        '2026-01-28',
        '2026-03-18',
        '2026-04-29',
        '2026-06-17',
        '2026-07-29',
        '2026-09-16',
        '2026-10-28',
        '2026-12-09',
    ];

    $ist   = new DateTimeZone('Asia/Kolkata');
    $today = new DateTime('now', $ist);
    $today->setTime(0, 0, 0);

    $nextDate  = null;
    $daysAway  = null;
    $isToday   = false;
    $isTomorrow = false;
    $isThisWeek = false;

    foreach ($fomcDates as $d) {
        $fomc = new DateTime($d, $ist);
        $fomc->setTime(0, 0, 0);
        $diff = (int)$today->diff($fomc)->days;
        $past = $fomc < $today;

        if (!$past) {
            $nextDate   = $fomc;
            $daysAway   = $diff;
            $isToday    = ($diff === 0);
            $isTomorrow = ($diff === 1);
            $isThisWeek = ($diff <= 7);
            break;
        }
    }

    return [
        'next_date'   => $nextDate ? $nextDate->format('d M Y') : 'N/A',
        'days_away'   => $daysAway,
        'is_today'    => $isToday,
        'is_tomorrow' => $isTomorrow,
        'is_this_week'=> $isThisWeek,
        'alert'       => $isToday || $isTomorrow || $isThisWeek,
        'alert_level' => $isToday ? 'breach' : ($isTomorrow ? 'warning' : 'info'),
        'label'       => $isToday    ? '🔴 FOMC TODAY — 11:30 PM IST'
                        : ($isTomorrow ? '🟠 FOMC TOMORROW'
                        : ($isThisWeek ? '🟡 FOMC THIS WEEK'
                        : '📅 Next FOMC')),
    ];
}

// ── 4. NFP STATUS ────────────────────────────────────────────────────────────

function getNFPStatus(): array {
    $ist   = new DateTimeZone('Asia/Kolkata');
    $today = new DateTime('now', $ist);
    $today->setTime(0, 0, 0);

    // 1st Friday of the current month
    $nfpThisMonth = new DateTime('first friday of ' . $today->format('F Y'), $ist);
    $nfpThisMonth->setTime(0, 0, 0);

    // If this month's NFP has already passed, use next month's
    if ($nfpThisMonth < $today) {
        $nextMonthStart = new DateTime('first day of next month', $ist);
        $nfpNext = new DateTime('first friday of ' . $nextMonthStart->format('F Y'), $ist);
        $nfpNext->setTime(0, 0, 0);
        $nfpDate  = $nfpNext;
    } else {
        $nfpDate = $nfpThisMonth;
    }

    $diff      = (int)$today->diff($nfpDate)->days;
    $isToday   = ($diff === 0);
    $isTomorrow = ($diff === 1);
    $isThisWeek = ($diff <= 7);

    return [
        'next_date'   => $nfpDate->format('d M Y'),
        'days_away'   => $diff,
        'is_today'    => $isToday,
        'is_tomorrow' => $isTomorrow,
        'is_this_week'=> $isThisWeek,
        'alert'       => $isToday || $isTomorrow || $isThisWeek,
        'time_ist'    => '6:00 PM IST',
        'label'       => $isToday    ? '🚨 NFP TODAY — 6:00 PM IST'
                        : ($isTomorrow ? '🟠 NFP TOMORROW'
                        : ($isThisWeek ? '🟡 NFP THIS WEEK'
                        : '📅 Next NFP')),
    ];
}

// ── 5. MASTER FUNCTION ───────────────────────────────────────────────────────

function getMarketAwareness(): array {
    return [
        'day'     => getMarketDayInfo(),
        'session' => getSessionStatus(),
        'fomc'    => getFOMCCountdown(),
        'nfp'     => getNFPStatus(),
    ];
}

<?php
// ============================================================
// Risk Engine — Professional Prop-Firm Style Risk Manager
// ============================================================
// Handles:
//   • Daily max-loss (20% of daily_initial_balance)
//   • Weekly max-loss (40% of weekly_initial_balance)
//   • Dynamic profit-lock (highest-equity trailing floor)
//   • Three configurable profit-lock modes
//   • Breach detection & alert generation
//   • Auto-reset snapshots (daily @ 23:59, weekly @ Monday 00:00)
// ============================================================

// ---- Configurable constants (edit here or override via db config) ----
if (!defined('DAILY_LOSS_LIMIT_PCT'))   define('DAILY_LOSS_LIMIT_PCT',  20.0);   // 20%
if (!defined('WEEKLY_LOSS_LIMIT_PCT'))  define('WEEKLY_LOSS_LIMIT_PCT', 40.0);   // 40%
if (!defined('WARNING_THRESHOLD_PCT'))  define('WARNING_THRESHOLD_PCT', 90.0);   // warn at 90% of limit used
if (!defined('PROFIT_LOCK_MODE'))       define('PROFIT_LOCK_MODE',      2);      // 1=Aggressive 2=Balanced 3=Conservative

// ============================================================
// SNAPSHOT MANAGEMENT
// ============================================================

/**
 * Ensure daily snapshot exists for today.
 * Called on every page load — idempotent (INSERT IGNORE).
 * daily_highest_equity is initialised to current balance so the
 * very first trade of the day doesn't falsely trigger the lock.
 */
function ensureDailySnapshot(int $userId): void {
    $db      = getDB();
    $today   = date('Y-m-d');
    $balance = getCurrentBalance($userId);

    // INSERT IGNORE — only fires on first call of the day
    $stmt = $db->prepare("
        INSERT IGNORE INTO risk_snapshots
            (user_id, snapshot_date, snapshot_type, balance_at_open, highest_equity)
        VALUES (?, ?, 'daily', ?, ?)
    ");
    $stmt->execute([$userId, $today, $balance, $balance]);
}

/**
 * Ensure weekly snapshot exists for the current Monday.
 * Resets only when Monday is encountered for the first time.
 */
function ensureWeeklySnapshot(int $userId): void {
    $db         = getDB();
    $monday     = date('Y-m-d', strtotime('monday this week'));
    $balance    = getCurrentBalance($userId);

    $stmt = $db->prepare("
        INSERT IGNORE INTO risk_snapshots
            (user_id, snapshot_date, snapshot_type, balance_at_open, highest_equity)
        VALUES (?, ?, 'weekly', ?, ?)
    ");
    $stmt->execute([$userId, $monday, $balance, $balance]);
}

/**
 * Update the stored highest_equity for today if current equity exceeds it.
 * Should be called whenever equity may have changed (trade added/removed).
 *
 * @param float $currentEquity  Pass getCurrentBalance() result as proxy for equity.
 */
function updateHighestEquity(int $userId, float $currentEquity): void {
    $db    = getDB();
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        UPDATE risk_snapshots
        SET    highest_equity = ?
        WHERE  user_id = ? AND snapshot_date = ? AND snapshot_type = 'daily'
          AND  ? > highest_equity
    ");
    $stmt->execute([$currentEquity, $userId, $today, $currentEquity]);
}

// ============================================================
// SNAPSHOT READERS
// ============================================================

/** Return today's daily snapshot row (assoc array). */
function getDailySnapshot(int $userId): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT * FROM risk_snapshots
        WHERE  user_id = ? AND snapshot_date = ? AND snapshot_type = 'daily'
    ");
    $stmt->execute([$userId, date('Y-m-d')]);
    return $stmt->fetch() ?: [];
}

/** Return this week's (Monday) weekly snapshot row. */
function getWeeklySnapshot(int $userId): array {
    $db     = getDB();
    $monday = date('Y-m-d', strtotime('monday this week'));
    $stmt   = $db->prepare("
        SELECT * FROM risk_snapshots
        WHERE  user_id = ? AND snapshot_date = ? AND snapshot_type = 'weekly'
    ");
    $stmt->execute([$userId, $monday]);
    return $stmt->fetch() ?: [];
}

// ============================================================
// CORE RISK CALCULATIONS
// ============================================================

/**
 * Build the full risk metrics array used by the dashboard.
 *
 * Returns an associative array with every number the UI needs:
 *   daily_initial_balance, weekly_initial_balance,
 *   daily_highest_equity, current_equity,
 *   dynamic_daily_min_equity, weekly_min_equity,
 *   daily_loss_used, daily_loss_remaining, daily_loss_pct_used,
 *   weekly_loss_used, weekly_loss_remaining, weekly_loss_pct_used,
 *   profit_secured, locked_equity_level,
 *   breach_daily, breach_weekly, warning_daily, warning_weekly,
 *   lock_mode, lock_mode_label
 */
function getRiskMetrics(int $userId): array {

    // -- Load per-user risk settings (fallback to global constants if NULL) --
    $uRow = getDB()->prepare("SELECT daily_loss_pct, weekly_loss_pct, warning_threshold_pct, profit_lock_mode FROM users WHERE id = ?");
    $uRow->execute([$userId]);
    $uCfg = $uRow->fetch() ?: [];
    $userDailyPct   = isset($uCfg['daily_loss_pct'])        && $uCfg['daily_loss_pct']        !== null ? (float)$uCfg['daily_loss_pct']         : DAILY_LOSS_LIMIT_PCT;
    $userWeeklyPct  = isset($uCfg['weekly_loss_pct'])       && $uCfg['weekly_loss_pct']        !== null ? (float)$uCfg['weekly_loss_pct']        : WEEKLY_LOSS_LIMIT_PCT;
    $userWarningPct = isset($uCfg['warning_threshold_pct']) && $uCfg['warning_threshold_pct']  !== null ? (float)$uCfg['warning_threshold_pct']  : WARNING_THRESHOLD_PCT;
    $userLockMode   = isset($uCfg['profit_lock_mode'])      && $uCfg['profit_lock_mode']       !== null ? (int)$uCfg['profit_lock_mode']         : PROFIT_LOCK_MODE;

    // -- Ensure snapshots exist before reading them --
    ensureDailySnapshot($userId);
    ensureWeeklySnapshot($userId);

    $currentEquity = getCurrentBalance($userId);   // Equity proxy: realised balance
    updateHighestEquity($userId, $currentEquity);  // Ratchet highest_equity up

    $daily  = getDailySnapshot($userId);
    $weekly = getWeeklySnapshot($userId);

    $dailyInitial  = isset($daily['balance_at_open'])  ? (float)$daily['balance_at_open']  : $currentEquity;
    $weeklyInitial = isset($weekly['balance_at_open']) ? (float)$weekly['balance_at_open'] : $currentEquity;
    $highestEquity = isset($daily['highest_equity'])   ? (float)$daily['highest_equity']   : $currentEquity;

    // -- Daily loss limits (use per-user values) --
    $dailyMaxLoss    = $dailyInitial  * ($userDailyPct  / 100);
    $weeklyMaxLoss   = $weeklyInitial * ($userWeeklyPct / 100);

    // -- Static floors (used for Conservative mode) --
    $staticDailyFloor  = $dailyInitial - $dailyMaxLoss;
    $weeklyMinEquity   = $weeklyInitial - $weeklyMaxLoss;

    // -- Profit locked above initial --
    $profitAboveInitial = max(0, $highestEquity - $dailyInitial);

    // -- Dynamic floor based on profit-lock mode --
    //    Mode 1 Aggressive : floor = highest_equity - 20% of highest_equity
    //    Mode 2 Balanced   : floor = highest_equity - 20% of highest_equity
    //                               but profit cushion = 50% of profit returned
    //    Mode 3 Conservative: floor = daily_initial_balance (fixed — no lock-up benefit)
    $lockMode = $userLockMode;
    switch ($lockMode) {
        case 1: // Aggressive — trail tightly off peak
            $dynamicFloor   = $highestEquity - ($highestEquity * ($userDailyPct / 100));
            $lockModeLabel  = 'Aggressive';
            break;
        case 3: // Conservative — plain fixed floor, no profit benefit
            $dynamicFloor   = $staticDailyFloor;
            $lockModeLabel  = 'Conservative';
            break;
        default: // 2 — Balanced (default): return 50% of locked profit to cushion
            $cushion        = $profitAboveInitial * 0.50;
            $base           = $highestEquity - $cushion;
            $dynamicFloor   = $base - ($base * ($userDailyPct / 100));
            $lockModeLabel  = 'Balanced';
            break;
    }

    // Ensure floor never drops below static floor (safety clamp)
    $dynamicFloor = max($dynamicFloor, $staticDailyFloor);

    // -- Loss used (negative = loss; positive unrealised profit) --
    $dailyLossUsed   = max(0, $dailyInitial  - $currentEquity);   // how much $ lost today
    $weeklyLossUsed  = max(0, $weeklyInitial - $currentEquity);   // how much $ lost this week

    $dailyLossRemaining  = max(0, $dailyMaxLoss  - $dailyLossUsed);
    $weeklyLossRemaining = max(0, $weeklyMaxLoss - $weeklyLossUsed);

    $dailyLossPctUsed  = $dailyMaxLoss  > 0 ? min(100, ($dailyLossUsed  / $dailyMaxLoss)  * 100) : 0;
    $weeklyLossPctUsed = $weeklyMaxLoss > 0 ? min(100, ($weeklyLossUsed / $weeklyMaxLoss) * 100) : 0;

    // -- Breach flags --
    $breachDaily  = $currentEquity < $dynamicFloor;
    $breachWeekly = $currentEquity < $weeklyMinEquity;

    // -- Warning flags (90% of limit consumed) --
    $warningDaily  = !$breachDaily  && $dailyLossPctUsed  >= $userWarningPct;
    $warningWeekly = !$breachWeekly && $weeklyLossPctUsed >= $userWarningPct;

    // -- Profit secured: how much equity is "safe" above original daily floor --
    $profitSecured = max(0, $dynamicFloor - $staticDailyFloor);

    // -- Distance to breach (% and $) --
    $dailyDistanceToBreach  = $currentEquity - $dynamicFloor;
    $weeklyDistanceToBreach = $currentEquity - $weeklyMinEquity;
    $dailyDistancePct       = $dailyInitial  > 0 ? ($dailyDistanceToBreach  / $dailyInitial)  * 100 : 0;
    $weeklyDistancePct      = $weeklyInitial > 0 ? ($weeklyDistanceToBreach / $weeklyInitial) * 100 : 0;

    return [
        // Balances
        'current_equity'           => round($currentEquity, 2),
        'daily_initial_balance'    => round($dailyInitial,  2),
        'weekly_initial_balance'   => round($weeklyInitial, 2),

        // Highest equity tracking
        'daily_highest_equity'     => round($highestEquity, 2),

        // Floors
        'dynamic_daily_min_equity' => round($dynamicFloor,       2),
        'static_daily_min_equity'  => round($staticDailyFloor,   2),
        'weekly_min_equity'        => round($weeklyMinEquity,     2),

        // Max loss values
        'daily_max_loss'           => round($dailyMaxLoss,  2),
        'weekly_max_loss'          => round($weeklyMaxLoss, 2),

        // Loss consumed
        'daily_loss_used'          => round($dailyLossUsed,  2),
        'weekly_loss_used'         => round($weeklyLossUsed, 2),

        // Loss remaining
        'daily_loss_remaining'     => round($dailyLossRemaining,  2),
        'weekly_loss_remaining'    => round($weeklyLossRemaining, 2),

        // Loss % used (0–100)
        'daily_loss_pct_used'      => round($dailyLossPctUsed,  1),
        'weekly_loss_pct_used'     => round($weeklyLossPctUsed, 1),

        // Profit lock
        'profit_secured'           => round($profitSecured,      2),
        'locked_equity_level'      => round($dynamicFloor,       2),
        'profit_above_initial'     => round($profitAboveInitial, 2),

        // Distance to breach
        'daily_distance_usd'       => round($dailyDistanceToBreach,  2),
        'weekly_distance_usd'      => round($weeklyDistanceToBreach, 2),
        'daily_distance_pct'       => round($dailyDistancePct,  2),
        'weekly_distance_pct'      => round($weeklyDistancePct, 2),

        // Breach / alert flags
        'breach_daily'             => $breachDaily,
        'breach_weekly'            => $breachWeekly,
        'warning_daily'            => $warningDaily,
        'warning_weekly'           => $warningWeekly,

        // Config echoed back (useful for JS)
        'daily_limit_pct'          => $userDailyPct,
        'weekly_limit_pct'         => $userWeeklyPct,
        'lock_mode'                => $lockMode,
        'lock_mode_label'          => $lockModeLabel,
    ];
}

<?php
// ============================================================
// Database Configuration
// ============================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_NAME', 'trading_journal');
define('APP_NAME', 'TradeLog Pro');
define('DEFAULT_USER_ID', 1);
define('WEEKLY_DRAWDOWN_LIMIT', 6.0); // legacy — kept for sidebar compatibility

// ---- Risk Engine Configuration (v3) ----
define('DAILY_LOSS_LIMIT_PCT',  20.0);  // 20% daily max loss
define('WEEKLY_LOSS_LIMIT_PCT', 40.0);  // 40% weekly max loss
define('WARNING_THRESHOLD_PCT', 90.0);  // alert when 90% of limit consumed
// Profit Lock Mode: 1=Aggressive  2=Balanced (default)  3=Conservative
define('PROFIT_LOCK_MODE', 2);

// ---- Coaching & Discipline Rule Limits ----
define('MAX_TRADES_PER_DAY',      5);     // hard max trades allowed per day
define('MAX_RISK_PER_TRADE_PCT',  2.0);   // max % account risked per single trade
define('MAX_DAILY_LOSS_DOLLAR',   50.0);  // adjust to your daily loss hard stop in $
define('MAX_DAILY_PROFIT_TARGET', 100.0); // optional soft daily profit target in $

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:monospace;background:#0a0e1a;color:#ef4444;padding:30px;margin:20px;border-radius:12px;border:1px solid #ef4444;">
                <h2>⚠ Database Connection Failed</h2>
                <p style="margin:12px 0">Error: ' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Please ensure:</p>
                <ul style="margin:8px 0 0 20px;line-height:2">
                  <li>XAMPP MySQL service is <strong>Running</strong></li>
                  <li>Database <strong>trading_journal</strong> exists — import <code>schema.sql</code> via phpMyAdmin</li>
                  <li>Credentials in <code>config/db.php</code> are correct</li>
                </ul>
            </div>');
        }
    }
    return $pdo;
}

// Format P/L as USD with sign: +$120.00 or -$50.00
function formatPL($value) {
    $val = floatval($value);
    if ($val >= 0) return '+$' . number_format($val, 2);
    return '-$' . number_format(abs($val), 2);
}

// Format as plain USD: $1,200.00
function formatUSD($value) {
    return '$' . number_format(floatval($value), 2);
}

// Helper: get current balance (USD)
// After a stop out the balance resets to zero, so only deposits/withdrawals/trades
// AFTER the stop out date count. Net P/L (profit_loss − brokerage + swap) is used
// so the figure matches the broker's real account balance.
// Uses the `date` column (user-specified business date) for filtering, not `created_at`
// (DB insertion time), because those two timestamps can differ when records are entered retroactively.
function getCurrentBalance($userId = DEFAULT_USER_ID) {
    $db = getDB();

    // Find the most recent stop out's business date
    $soStmt = $db->prepare("SELECT date FROM transactions WHERE user_id=? AND type='stop_out' ORDER BY date DESC, created_at DESC LIMIT 1");
    $soStmt->execute([$userId]);
    $lastSO = $soStmt->fetch();

    if ($lastSO) {
        $sinceDate = $lastSO['date'];

        $txStmt = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN type='deposit'  THEN amount ELSE 0 END),0) as deps,
            COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END),0) as wds
            FROM transactions WHERE user_id=? AND type IN ('deposit','withdraw') AND date > ?");
        $txStmt->execute([$userId, $sinceDate]);
        $tx = $txStmt->fetch();

        $plStmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl FROM trades WHERE user_id=? AND DATE(trade_datetime) > ?");
        $plStmt->execute([$userId, $sinceDate]);
        $netPL = $plStmt->fetch()['net_pl'];

        return (float)$tx['deps'] - (float)$tx['wds'] + (float)$netPL;
    }

    // No stop outs — sum all deposits/withdrawals and net P/L
    $txStmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='deposit'  THEN amount ELSE 0 END),0) as deps,
        COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END),0) as wds
        FROM transactions WHERE user_id=? AND type IN ('deposit','withdraw')");
    $txStmt->execute([$userId]);
    $tx = $txStmt->fetch();

    $plStmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl FROM trades WHERE user_id=?");
    $plStmt->execute([$userId]);
    $netPL = $plStmt->fetch()['net_pl'];

    return (float)$tx['deps'] - (float)$tx['wds'] + (float)$netPL;
}

// Helper: get weekly drawdown %
function getWeeklyDrawdown($userId = DEFAULT_USER_ID) {
    $db = getDB();
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week'));

    $stmt = $db->prepare("SELECT COALESCE(SUM(profit_loss),0) as week_pl FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    $weekPL = $stmt->fetch()['week_pl'];

    $balance     = getCurrentBalance($userId);
    $peakBalance = $balance - $weekPL;

    if ($peakBalance <= 0) return 0;
    $drawdown = ($weekPL < 0) ? (abs($weekPL) / $peakBalance) * 100 : 0;
    return round($drawdown, 2);
}

// Helper: total brokerage paid in a date range (all time if no range given)
function getTotalBrokerage(int $userId = DEFAULT_USER_ID, string $from = '', string $to = ''): float {
    $db = getDB();
    if ($from && $to) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(brokerage),0) as total FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?");
        $stmt->execute([$userId, $from, $to]);
    } else {
        $stmt = $db->prepare("SELECT COALESCE(SUM(brokerage),0) as total FROM trades WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    return round((float)$stmt->fetch()['total'], 4);
}

// Helper: net P&L after brokerage + swap (profit_loss - brokerage + swap)
function getNetPLAfterCharges(int $userId = DEFAULT_USER_ID, string $from = '', string $to = ''): float {
    $db = getDB();
    if ($from && $to) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as total FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?");
        $stmt->execute([$userId, $from, $to]);
    } else {
        $stmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as total FROM trades WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    return round((float)$stmt->fetch()['total'], 2);
}

// Returns all capital cycles derived from transactions (stop_out events as boundaries)
function getCapitalCycles(int $userId = DEFAULT_USER_ID): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY date ASC, created_at ASC");
    $stmt->execute([$userId]);
    $allTx = $stmt->fetchAll();

    $cycles      = [];
    $cycleNum    = 1;
    $startDate   = null;
    $deposits    = 0.0;
    $withdrawals = 0.0;

    foreach ($allTx as $tx) {
        if ($startDate === null && $tx['type'] === 'deposit') {
            $startDate = $tx['date'];
        }
        if ($tx['type'] === 'deposit')  $deposits    += (float)$tx['amount'];
        if ($tx['type'] === 'withdraw') $withdrawals += (float)$tx['amount'];
        if ($tx['type'] === 'stop_out') {
            $cycles[] = [
                'cycle_number'      => $cycleNum++,
                'start_date'        => $startDate,
                'end_date'          => $tx['date'],
                'stop_out_amount'   => (float)$tx['amount'],
                'stop_out_note'     => $tx['note'],
                'stop_out_time'     => $tx['created_at'],
                'total_deposits'    => $deposits,
                'total_withdrawals' => $withdrawals,
                'is_active'         => false,
            ];
            $startDate   = null;
            $deposits    = 0.0;
            $withdrawals = 0.0;
        }
    }

    // Append active (ongoing) cycle if there has been any activity
    if ($cycleNum > 1 || $deposits > 0) {
        $cycles[] = [
            'cycle_number'      => $cycleNum,
            'start_date'        => $startDate,
            'end_date'          => null,
            'stop_out_amount'   => 0.0,
            'stop_out_note'     => null,
            'stop_out_time'     => null,
            'total_deposits'    => $deposits,
            'total_withdrawals' => $withdrawals,
            'is_active'         => true,
        ];
    }

    // Enrich each cycle with trade stats
    foreach ($cycles as &$c) {
        $from = $c['start_date'] ?? date('Y-m-d');
        $to   = $c['end_date']   ?? date('Y-m-d');

        $tq = $db->prepare("
            SELECT COUNT(*) as tc,
                   COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END),0) as wins,
                   COALESCE(SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END),0) as losses,
                   COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl,
                   COALESCE(SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END),0) as gross_profit,
                   COALESCE(SUM(CASE WHEN profit_loss < 0 THEN ABS(profit_loss) ELSE 0 END),0) as gross_loss
            FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?
        ");
        $tq->execute([$userId, $from, $to]);
        $s = $tq->fetch();

        $c['trade_count']   = (int)$s['tc'];
        $c['wins']          = (int)$s['wins'];
        $c['losses']        = (int)$s['losses'];
        $c['net_pl']        = (float)$s['net_pl'];
        $c['gross_profit']  = (float)$s['gross_profit'];
        $c['gross_loss']    = (float)$s['gross_loss'];
        $c['win_rate']      = $c['trade_count'] > 0
                              ? round($c['wins'] / $c['trade_count'] * 100, 1) : 0;
        $startTs            = $c['start_date'] ? strtotime($c['start_date']) : time();
        $endTs              = $c['end_date']   ? strtotime($c['end_date'])   : time();
        $c['duration_days'] = max(0, (int)round(($endTs - $startTs) / 86400));
    }
    unset($c);

    return array_reverse($cycles); // newest first
}

// Load the risk engine (depends on getDB, getCurrentBalance defined above)
require_once __DIR__ . '/../includes/risk_engine.php';

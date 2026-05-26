<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

define('DEMO_APP_NAME', 'DisciplineOS — Demo');
define('DEMO_USER_ID',  DEFAULT_USER_ID);

// Active account (can be switched via session)
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['demo_account_id'])) $_SESSION['demo_account_id'] = 1;

function getDemoAccountId(): int {
    return (int)($_SESSION['demo_account_id'] ?? 1);
}

function getDemoAccount(int $id = 0): array {
    $db = getDB();
    $id = $id ?: getDemoAccountId();
    $s  = $db->prepare("SELECT * FROM demo_accounts WHERE id = ? AND user_id = ?");
    $s->execute([$id, DEMO_USER_ID]);
    return $s->fetch() ?: [];
}

function getDemoBalance(int $accountId = 0): float {
    $acc = getDemoAccount($accountId);
    return (float)($acc['current_balance'] ?? 0);
}

// Recalculate and sync account balance from closed trades
function syncDemoBalance(int $accountId): void {
    $db  = getDB();
    $acc = getDemoAccount($accountId);
    if (!$acc) return;
    $stmt = $db->prepare("SELECT COALESCE(SUM(net_pl),0) as total FROM demo_trades WHERE account_id=? AND status='closed'");
    $stmt->execute([$accountId]);
    $netPL   = (float)$stmt->fetch()['total'];
    $balance = (float)$acc['starting_balance'] + $netPL;
    $db->prepare("UPDATE demo_accounts SET current_balance=? WHERE id=?")->execute([$balance, $accountId]);
}

function fmtD($v): string {    // format dollar
    return '$'.number_format(abs(floatval($v)),2);
}
function fmtDPL($v): string {  // format P&L with sign
    $v = floatval($v);
    return ($v >= 0 ? '+$' : '-$').number_format(abs($v),2);
}

// Pip value helper (approximate for demo)
function calcPL(string $symbol, string $type, float $lots, float $entry, float $exit): float {
    $diff = $type === 'buy' ? ($exit - $entry) : ($entry - $exit);
    // Standard lot = 100,000 units; 1 pip for XXX/USD pairs
    $pipSize = str_contains(strtoupper($symbol), 'JPY') ? 0.01 : 0.0001;
    $pips    = $diff / $pipSize;
    // $10 per pip per standard lot
    return round($pips * 10 * $lots, 2);
}

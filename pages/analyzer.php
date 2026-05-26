<?php
// ============================================================
// Trading Mistake Analyzer — Single File
// Drop into /pages/ folder. Uses existing db.php config.
// ============================================================
require_once '../config/db.php';

$db     = getDB();
requireLogin();
$userId = getCurrentUserId();

// ── Date range ────────────────────────────────────────────────────────────
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';
$hasFilter = $from && $to;

// ── Fetch trades for the period ───────────────────────────────────────────
$where  = "WHERE user_id = :uid";
$params = ['uid' => $userId];

if ($hasFilter) {
    $where .= " AND DATE(trade_datetime) BETWEEN :from AND :to";
    $params['from'] = $from;
    $params['to']   = $to;
}

$stmt = $db->prepare("SELECT * FROM trades $where ORDER BY trade_datetime ASC");
$stmt->execute($params);
$trades = $stmt->fetchAll();

// ── Analysis engine ───────────────────────────────────────────────────────
$analysis = null;

if (!empty($trades)) {

    $totalCount    = count($trades);
    $grossProfit   = 0;
    $grossLoss     = 0;
    $totalBrokerage= 0;
    $totalSwap     = 0;
    $wins          = 0;
    $losses        = 0;

    // Duration buckets
    $durBuckets = [
        'under1m'  => ['label' => 'Under 1 min',  'sec_min' => 0,    'sec_max' => 59,    'trades' => [], 'pl' => 0, 'wins' => 0],
        '1to5m'    => ['label' => '1 – 5 mins',    'sec_min' => 60,   'sec_max' => 299,   'trades' => [], 'pl' => 0, 'wins' => 0],
        '5to30m'   => ['label' => '5 – 30 mins',   'sec_min' => 300,  'sec_max' => 1799,  'trades' => [], 'pl' => 0, 'wins' => 0],
        'over30m'  => ['label' => 'Over 30 mins',  'sec_min' => 1800, 'sec_max' => PHP_INT_MAX, 'trades' => [], 'pl' => 0, 'wins' => 0],
    ];

    // Close reason counters
    $reasonStats = [
        'sl'   => ['count' => 0, 'pl' => 0, 'label' => 'Stop Loss'],
        'tp'   => ['count' => 0, 'pl' => 0, 'label' => 'Take Profit'],
        'user' => ['count' => 0, 'pl' => 0, 'label' => 'Manual'],
        'so'   => ['count' => 0, 'pl' => 0, 'label' => 'Stop Out'],
        ''     => ['count' => 0, 'pl' => 0, 'label' => 'Unknown'],
    ];

    // Symbol stats
    $symbolStats = [];

    // Lot size buckets
    $lotSmallWins = 0; $lotSmallTotal = 0;
    $lotLargeWins = 0; $lotLargeTotal = 0;
    $lotLargePL   = 0; $lotLargeBrok  = 0;

    // Consecutive loss tracking
    $maxConsecLoss = 0; $curConsecLoss = 0;
    $maxConsecWin  = 0; $curConsecWin  = 0;

    // Win/loss streaks for avg
    $winPLs  = [];
    $lossPLs = [];

    // Opening time hour analysis
    $hourStats = [];

    foreach ($trades as $t) {
        $pl   = (float)$t['profit_loss'];
        $brok = (float)$t['brokerage'];
        $swap = (float)$t['swap'];
        $lots = (float)$t['quantity'];
        $reason = strtolower(trim($t['close_reason'] ?? ''));

        // Totals
        if ($pl > 0) { $grossProfit += $pl; $wins++; $winPLs[] = $pl; $curConsecLoss = 0; $curConsecWin++; $maxConsecWin = max($maxConsecWin, $curConsecWin); }
        elseif ($pl < 0) { $grossLoss += $pl; $losses++; $lossPLs[] = $pl; $curConsecWin = 0; $curConsecLoss++; $maxConsecLoss = max($maxConsecLoss, $curConsecLoss); }
        else { $curConsecLoss = 0; $curConsecWin = 0; }

        $totalBrokerage += $brok;
        $totalSwap      += $swap;

        // Duration
        try {
            $openDt  = new DateTime($t['trade_datetime']);
            // Approximate close from trade_datetime (we only store close time)
            // Use created_at if different, else mark as unknown
            // We'll compute duration using opening_time if available via ticket
            // Since we only have trade_datetime (= close), use a heuristic
            // Actually: trade_datetime IS close time from CSV. We don't store open time separately.
            // We'll bucket by profit pattern instead — but let's check
<?php
// ============================================================
// Trade Analyser — Single File
// Place in: trading-app/pages/analyse.php
// Requires: ../config/db.php  (your existing config)
// ============================================================
require_once '../config/db.php';

$db     = getDB();
$userId = DEFAULT_USER_ID;

// ── Date range ────────────────────────────────────────────────────────────
$from     = $_GET['from'] ?? date('Y-m-01');          // default: this month start
$to       = $_GET['to']   ?? date('Y-m-d');            // default: today
$fromSafe = date('Y-m-d', strtotime($from));
$toSafe   = date('Y-m-d', strtotime($to));

// ── Fetch all trades in range ─────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT *,
           TIMESTAMPDIFF(SECOND,
               COALESCE(open_time, trade_datetime),
               trade_datetime) AS duration_sec
    FROM trades
    WHERE user_id = ?
      AND DATE(trade_datetime) BETWEEN ? AND ?
    ORDER BY trade_datetime ASC
");
$stmt->execute([$userId, $fromSafe, $toSafe]);
$trades = $stmt->fetchAll();

$total = count($trades);

// ── Only compute when we have data ────────────────────────────────────────
$stats = [];

if ($total > 0) {
    // Basic P&L
    $grossProfit  = 0; $grossLoss = 0;
    $totalBrok    = 0; $totalSwap = 0;
    $wins = 0; $losses = 0;
    $totalWinPL = 0; $totalLossPL = 0;

    // Close reason buckets
    $slCount = $tpCount = $userCount = $soCount = 0;
    $slPL    = $tpPL    = $userPL    = $soPL    = 0;
    $slWins  = $tpWins  = $userWins  = 0;

    // Duration buckets
    $dur = [
        'u1'  => ['label'=>'Under 1 min', 'sec'=>60,   'trades'=>[]],
        'u5'  => ['label'=>'1 – 5 mins',  'sec'=>300,  'trades'=>[]],
        'u30' => ['label'=>'5 – 30 mins', 'sec'=>1800, 'trades'=>[]],
        'o30' => ['label'=>'Over 30 mins','sec'=>PHP_INT_MAX,'trades'=>[]],
    ];

    // Lot size buckets
    $lotSmall = ['count'=>0,'pl'=>0,'brok'=>0,'wins'=>0];   // < 0.1
    $lotMid   = ['count'=>0,'pl'=>0,'brok'=>0,'wins'=>0];   // 0.1 – 0.49
    $lotLarge = ['count'=>0,'pl'=>0,'brok'=>0,'wins'=>0];   // >= 0.5

    // Symbol performance
    $symbols = [];

    // Consecutive loss tracking
    $maxConsec = 0; $curConsec = 0;
    $allPLs = [];

    // Manual close: win vs loss
    $manualWins = 0; $manualLosses = 0
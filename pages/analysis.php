<?php
require_once '../config/db.php';
$db     = getDB();
$userId = DEFAULT_USER_ID;

$soEventsStmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE user_id=? AND type='stop_out'");
$soEventsStmt->execute([$userId]);
$soEvents = $soEventsStmt->fetch();

$cycles = getCapitalCycles($userId);

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to']   ?? '');
if (!$from) $from = date('Y-m-d', strtotime('-90 days'));
if (!$to)   $to   = date('Y-m-d');

$stmt = $db->prepare("
    SELECT trade_datetime, open_time, trade_type, symbol,
           entry_price, exit_price, quantity,
           profit_loss, brokerage, swap, close_reason
    FROM trades
    WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?
    ORDER BY trade_datetime ASC
");
$stmt->execute([$userId, $from, $to]);
$trades = $stmt->fetchAll();

// ── Aggregate variables ──────────────────────────────────────────────────────
$insights = [];
$total = $wins = $losses = 0;
$grossProfit = $grossLoss = $totalBrok = $totalSwap = $totalPL = 0.0;
$slHits = $tpHits = $manualClose = $soHits = 0;
$slPL = $tpPL = $manualPL = $soPL = 0.0;
$winRate = $netPL = $avgWin = $avgLoss = $rrRatio = $brokPct = 0.0;
$profitFactor = $expectancy = 0.0;
$symbolMap = $dailyPL = [];
$dur = [
    'under1' => ['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'< 1 min'],
    '1to5'   => ['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'1 – 5 min'],
    '5to30'  => ['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'5 – 30 min'],
    'over30' => ['count'=>0,'wins'=>0,'pl'=>0.0,'label'=>'> 30 min'],
];
$lotBuckets = [
    'small' => ['c'=>0,'w'=>0,'pl'=>0.0,'b'=>0.0,'wr'=>0,'avgPL'=>0,'net'=>0],
    'large' => ['c'=>0,'w'=>0,'pl'=>0.0,'b'=>0.0,'wr'=>0,'avgPL'=>0,'net'=>0],
];
$hourlyWR  = array_fill(0, 24, ['w'=>0,'l'=>0]);
$dowData   = array_fill(1, 7, ['count'=>0,'wins'=>0,'net'=>0.0]);
$dayTrades = [];

// Streak / revenge tracking
$maxConsecWins = $maxConsecLosses = $curW = $curL = 0;
$revengeTrades = $revengeWins = 0;
$lastLossTs = null;

// Chart data
$chartDatesJson = $chartEquityJson = '[]';
$durLabels = $durWR = $durAvg = $hourLabels = $hourWRdata = [];
$dowLabels = $dowWRdata = $dowNetdata = [];

$hasData = !empty($trades);

if ($hasData) {
    $total = count($trades);

    foreach ($trades as $t) {
        $pl     = (float)$t['profit_loss'];
        $brok   = (float)$t['brokerage'];
        $swap   = (float)$t['swap'];
        $net    = $pl - $brok + $swap;
        $reason = strtolower(trim($t['close_reason'] ?? ''));
        $lots   = (float)$t['quantity'];
        $closeTs = strtotime($t['trade_datetime']);
        $openTs  = $t['open_time'] ? strtotime($t['open_time']) : null;
        $durSec  = ($openTs !== null) ? max(0, $closeTs - $openTs) : null;
        $date    = date('Y-m-d', $closeTs);
        $hour    = (int)date('G', $closeTs);
        $dow     = (int)date('N', $closeTs);
        $sym     = strtoupper($t['symbol']);

        $totalPL   += $pl;
        $totalBrok += $brok;
        $totalSwap += $swap;
        if ($pl > 0) { $wins++;   $grossProfit += $pl; }
        else         { $losses++; $grossLoss   += $pl; }

        // Close reason
        switch ($reason) {
            case 'sl':   $slHits++;       $slPL   += $pl; break;
            case 'tp':   $tpHits++;       $tpPL   += $pl; break;
            case 'user': $manualClose++;  $manualPL += $pl; break;
            case 'so':   $soHits++;       $soPL   += $pl; break;
        }

        // Duration
        if ($durSec !== null) {
            $key = $durSec < 60 ? 'under1' : ($durSec < 300 ? '1to5' : ($durSec < 1800 ? '5to30' : 'over30'));
            $dur[$key]['count']++;
            $dur[$key]['pl'] += $pl;
            if ($pl > 0) $dur[$key]['wins']++;
        }

        // Symbol
        if (!isset($symbolMap[$sym])) $symbolMap[$sym] = ['c'=>0,'w'=>0,'pl'=>0.0,'b'=>0.0,'net'=>0.0];
        $symbolMap[$sym]['c']++;
        $symbolMap[$sym]['pl'] += $pl;
        $symbolMap[$sym]['b']  += $brok;
        $symbolMap[$sym]['net'] += $net;
        if ($pl > 0) $symbolMap[$sym]['w']++;

        // Lot buckets
        $lk = ($lots >= 0.5) ? 'large' : 'small';
        $lotBuckets[$lk]['c']++;
        $lotBuckets[$lk]['pl'] += $pl;
        $lotBuckets[$lk]['b']  += $brok;
        if ($pl > 0) $lotBuckets[$lk]['w']++;

        // Daily P/L
        if (!isset($dailyPL[$date])) $dailyPL[$date] = 0.0;
        $dailyPL[$date] += $net;

        // Day-trade count
        if (!isset($dayTrades[$date])) $dayTrades[$date] = ['count'=>0,'net'=>0.0,'wins'=>0];
        $dayTrades[$date]['count']++;
        $dayTrades[$date]['net'] += $net;
        if ($pl > 0) $dayTrades[$date]['wins']++;

        // Hourly
        if ($pl > 0) $hourlyWR[$hour]['w']++;
        else         $hourlyWR[$hour]['l']++;

        // Day of week
        $dowData[$dow]['count']++;
        $dowData[$dow]['net'] += $net;
        if ($pl > 0) $dowData[$dow]['wins']++;

        // Consecutive streaks
        if ($pl > 0) { $curW++; $curL = 0; if ($curW > $maxConsecWins) $maxConsecWins = $curW; }
        else         { $curL++; $curW = 0; if ($curL > $maxConsecLosses) $maxConsecLosses = $curL; }

        // Revenge trading: trade opened within 5 min of last loss close
        if ($lastLossTs !== null && ($closeTs - $lastLossTs) < 300) {
            $revengeTrades++;
            if ($pl > 0) $revengeWins++;
        }
        $lastLossTs = ($pl <= 0) ? $closeTs : null;
    }

    // Derived metrics
    $winRate      = $total   > 0 ? round($wins / $total * 100, 1)              : 0;
    $netPL        = round($totalPL - $totalBrok + $totalSwap, 2);
    $brokPct      = $grossProfit > 0 ? round($totalBrok / $grossProfit * 100, 1) : 0;
    $avgWin       = $wins   > 0 ? round($grossProfit / $wins, 2)               : 0;
    $avgLoss      = $losses > 0 ? round($grossLoss   / $losses, 2)             : 0;
    $rrRatio      = $avgLoss != 0 ? round($avgWin / abs($avgLoss), 2)          : 0;
    $profitFactor = abs($grossLoss) > 0 ? round($grossProfit / abs($grossLoss), 2) : ($grossProfit > 0 ? 99 : 0);
    $expectancy   = round(($winRate/100 * $avgWin) + ((1 - $winRate/100) * $avgLoss), 2);

    // Duration stats
    foreach ($dur as &$d) {
        $d['avgPL'] = $d['count'] > 0 ? round($d['pl'] / $d['count'], 2) : 0;
        $d['wr']    = $d['count'] > 0 ? round($d['wins'] / $d['count'] * 100, 1) : 0;
    } unset($d);

    // Lot bucket stats
    foreach ($lotBuckets as &$lb) {
        $lb['wr']    = $lb['c'] > 0 ? round($lb['w'] / $lb['c'] * 100, 1) : 0;
        $lb['avgPL'] = $lb['c'] > 0 ? round($lb['pl'] / $lb['c'], 2) : 0;
        $lb['net']   = round($lb['pl'] - $lb['b'], 2);
    } unset($lb);

    uasort($symbolMap, fn($a,$b) => $b['c'] - $a['c']);

    // Equity curve
    ksort($dailyPL);
    $dates = []; $equity = []; $running = 0.0;
    foreach ($dailyPL as $dt => $v) {
        $running += $v;
        $dates[]  = date('d M', strtotime($dt));
        $equity[] = round($running, 2);
    }
    $chartDatesJson  = json_encode($dates);
    $chartEquityJson = json_encode($equity);

    // Duration chart data
    foreach ($dur as $d) {
        if ($d['count'] > 0) { $durLabels[] = $d['label']; $durWR[] = $d['wr']; $durAvg[] = $d['avgPL']; }
    }

    // Hourly chart data
    for ($h = 0; $h < 24; $h++) {
        $w = $hourlyWR[$h]['w']; $l = $hourlyWR[$h]['l'];
        if ($w + $l >= 2) {
            $hourLabels[] = sprintf('%02d:00', $h);
            $hourWRdata[] = round($w / ($w+$l) * 100, 1);
        }
    }

    // DOW chart data
    $dowNames = [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
    foreach ($dowData as $d => $dv) {
        if ($dv['count'] > 0) {
            $dowLabels[]  = $dowNames[$d];
            $dowWRdata[]  = $dv['count'] > 0 ? round($dv['wins'] / $dv['count'] * 100, 1) : 0;
            $dowNetdata[] = round($dv['net'], 2);
        }
    }

    // Overtrading: days where trade count > 1.75× average
    $avgTradesPerDay = count($dayTrades) > 0 ? array_sum(array_column($dayTrades, 'count')) / count($dayTrades) : 0;
    $overtradeDays   = array_filter($dayTrades, fn($d) => $d['count'] > max(3, $avgTradesPerDay * 1.75));
    $overtradeLossDays = array_filter($overtradeDays, fn($d) => $d['net'] < 0);

    // ── Trading health score (0–100) ─────────────────────────────────────────
    // Each factor 0–100, averaged
    $s_wr  = min(100, max(0, ($winRate - 30) * 2.5));          // 30%=0, 70%=100
    $s_rr  = min(100, $rrRatio * 33);                           // 3.0 = 100
    $s_pf  = min(100, $profitFactor * 40);                      // 2.5 = 100
    $s_bk  = min(100, max(0, 100 - $brokPct * 2.5));            // 0%=100, 40%=0
    $s_rv  = $total > 0 ? max(0, 100 - ($revengeTrades/$total*100) * 4) : 100; // 0 revenge = 100
    $s_so  = max(0, 100 - $soHits * 25);                        // each SO costs 25 pts
    $tradingScore = (int)round(($s_wr + $s_rr + $s_pf + $s_bk + $s_rv + $s_so) / 6);
    $scoreColor = $tradingScore >= 65 ? 'var(--profit)' : ($tradingScore >= 40 ? 'var(--warning)' : 'var(--loss)');
    $scoreLabel = $tradingScore >= 70 ? 'Good'          : ($tradingScore >= 50 ? 'Average'  : ($tradingScore >= 30 ? 'Needs Work' : 'Critical'));

    // ── Pattern / mistake detection ─────────────────────────────────────────
    // #1 Impulsive entries
    if ($dur['under1']['count'] >= 3 && $dur['under1']['wr'] < 48) {
        $pct = $total > 0 ? round($dur['under1']['count'] / $total * 100) : 0;
        $insights[] = ['sev'=>'high','icon'=>'fas fa-bolt',
            'title' => 'Impulsive Entries — No Confirmation',
            'body'  => "{$dur['under1']['count']} trades ({$pct}% of total) closed in under 1 minute with only {$dur['under1']['wr']}% win rate. You're entering before any setup forms — the trade is already over before the market moves.",
            'pills' => [['l'=>"{$dur['under1']['count']} trades <1 min",'c'=>'red'],['l'=>"WR: {$dur['under1']['wr']}%",'c'=>'red'],['l'=>'Avg: $'.$dur['under1']['avgPL'],'c'=>'red']],
            'fix'   => 'Wait for 2–3 candle confirmation before entering. A good setup will still be valid after 3 minutes.',
        ];
    }

    // #2 Not setting TP
    if ($tpHits < $slHits * 0.25 && ($slHits + $tpHits) >= 4) {
        $ratio = $tpHits > 0 ? round($slHits / $tpHits, 1) : '∞';
        $insights[] = ['sev'=>'high','icon'=>'fas fa-bullseye',
            'title' => 'SL Hits Far Exceed TP Hits',
            'body'  => "Stop losses hit {$slHits} times, take profits only {$tpHits} times (ratio {$ratio}:1). You're manually closing profitable trades early (small wins) while letting the SL take full losses. This destroys your R:R.",
            'pills' => [['l'=>"SL: {$slHits}",'c'=>'red'],['l'=>"TP: {$tpHits}",'c'=>'green'],['l'=>"Manual: {$manualClose}",'c'=>'amber'],['l'=>"Ratio {$ratio}:1",'c'=>'red']],
            'fix'   => 'Set a TP level on every trade before you enter. Let the plan play out — don\'t watch P/L tick-by-tick.',
        ];
    }

    // #3 Revenge trading
    $revengeWR = $revengeTrades > 0 ? round($revengeWins / $revengeTrades * 100, 1) : 0;
    if ($revengeTrades >= 3) {
        $revPct = $total > 0 ? round($revengeTrades / $total * 100) : 0;
        $insights[] = ['sev'=>'high','icon'=>'fas fa-fire',
            'title' => 'Revenge Trading Detected',
            'body'  => "{$revengeTrades} trades ({$revPct}%) were entered within 5 minutes of a losing trade — classic revenge trading. These trades had only {$revengeWR}% win rate. After a loss your mindset is reactive, not analytical.",
            'pills' => [['l'=>"{$revengeTrades} revenge trades",'c'=>'red'],['l'=>"WR: {$revengeWR}%",'c'=>'red'],['l'=>"Normal WR: {$winRate}%",'c'=>'amber']],
            'fix'   => 'After a loss, step away for at least 10 minutes. No exceptions. Put a timer on your screen.',
        ];
    }

    // #4 High brokerage drag
    if ($brokPct > 15) {
        $insights[] = ['sev'=>'medium','icon'=>'fas fa-hand-holding-dollar',
            'title' => "Brokerage Consuming {$brokPct}% of Your Gross Profit",
            'body'  => "Gross P/L was " . ($totalPL >= 0 ? '+' : '') . '$'.number_format($totalPL,2)." but \$".number_format($totalBrok,2)." in commission brought net to " . ($netPL >= 0 ? '+' : '') . '$'.number_format($netPL,2).". Every extra trade you take leaks money regardless of whether it wins.",
            'pills' => [['l'=>'Gross: $'.number_format($totalPL,2),'c'=>($totalPL>=0?'green':'red')],['l'=>'Brokerage: -$'.number_format($totalBrok,2),'c'=>'red'],['l'=>"{$brokPct}% drag",'c'=>'red']],
            'fix'   => 'Trade fewer, higher-quality setups. Each trade must have a minimum 1.5× expected value to justify the commission cost.',
        ];
    }

    // #5 Overtrading
    if (count($overtradeLossDays) >= 2) {
        $totalOTLoss = round(array_sum(array_column(iterator_to_array(new ArrayIterator(array_values($overtradeLossDays))), 'net')), 2);
        $insights[] = ['sev'=>'medium','icon'=>'fas fa-layer-group',
            'title' => 'Overtrading on Bad Days',
            'body'  => count($overtradeLossDays)." days had significantly more trades than your average (".round($avgTradesPerDay,1)." trades/day) and ended in a net loss. When you're losing, you trade more to recover — which compounds the loss.",
            'pills' => [['l'=>count($overtradeDays).' heavy-volume days','c'=>'amber'],['l'=>count($overtradeLossDays).' ended in loss','c'=>'red'],['l'=>'Avg: '.round($avgTradesPerDay,1).' trades/day','c'=>'blue']],
            'fix'   => 'Set a hard daily loss limit (e.g. -$50 or 2% of balance). When hit, close the platform — not one more trade.',
        ];
    }

    // #6 Low win rate with low R:R — mathematically losing
    if ($winRate < 45 && $rrRatio < 1.5) {
        $insights[] = ['sev'=>'high','icon'=>'fas fa-calculator',
            'title' => "Mathematically Negative Expectancy",
            'body'  => "Win rate {$winRate}% + R:R {$rrRatio} = negative expected value per trade (\${$expectancy}/trade). You need either >50% win rate OR an R:R above 1.0 to be profitable long-term. Right now you have neither.",
            'pills' => [['l'=>"WR: {$winRate}%",'c'=>'red'],['l'=>"R:R: {$rrRatio}",'c'=>'red'],['l'=>"Expectancy: \${$expectancy}",'c'=>($expectancy>=0?'green':'red')]],
            'fix'   => 'Focus on one setup pattern only until WR improves. Stop trading low-conviction ideas.',
        ];
    }

    // #7 Large lot underperformance
    if ($lotBuckets['large']['c'] >= 3 && $lotBuckets['large']['wr'] < $lotBuckets['small']['wr'] - 8) {
        $insights[] = ['sev'=>'medium','icon'=>'fas fa-weight-scale',
            'title' => 'Win Rate Drops on Large Lot Sizes',
            'body'  => "Small lots: {$lotBuckets['small']['wr']}% WR (net \$".number_format($lotBuckets['small']['net'],2)."). Large lots (≥0.5): {$lotBuckets['large']['wr']}% WR (net \$".number_format($lotBuckets['large']['net'],2)."). Larger size creates pressure that leads to premature exits and held losses.",
            'pills' => [['l'=>"Large WR: {$lotBuckets['large']['wr']}%",'c'=>'red'],['l'=>"Small WR: {$lotBuckets['small']['wr']}%",'c'=>'green'],['l'=>$lotBuckets['large']['c'].' large-lot trades','c'=>'amber']],
            'fix'   => 'Keep lots at 0.1–0.2 until you have 3 consecutive profitable months. Size up only from profit, not capital.',
        ];
    }

    // #8 Stop-outs
    if ($soHits >= 2 || $soEvents['cnt'] >= 1) {
        $insights[] = ['sev'=>'critical','icon'=>'fas fa-skull-crossbones',
            'title' => 'Account Stop-Outs — Margin Blown',
            'body'  => ($soEvents['cnt'] >= 1 ? "Account stopped out {$soEvents['cnt']} time(s) — broker force-closed all positions due to insufficient margin. " : '') . ($soHits >= 2 ? "Additionally {$soHits} individual trades hit stop-out within this period. " : '') . "This is a critical position-sizing failure.",
            'pills' => [['l'=>"Account SOs: {$soEvents['cnt']}",'c'=>'red'],['l'=>"Trade SOs: {$soHits}",'c'=>'red'],['l'=>'Capital wiped: -$'.number_format($soEvents['total'],2),'c'=>'red']],
            'fix'   => 'Never risk more than 1–2% of account per trade. Maximum 2–3 open positions simultaneously.',
        ];
    }

    // #9 Long consecutive loss streaks
    if ($maxConsecLosses >= 5) {
        $insights[] = ['sev'=>'medium','icon'=>'fas fa-arrow-trend-down',
            'title' => "Max {$maxConsecLosses} Consecutive Losses — No Circuit Breaker",
            'body'  => "You ran {$maxConsecLosses} losses in a row without stopping. Most of those later losses are revenge/recovery trades taken with a poor mindset. The losses compound both financially and psychologically.",
            'pills' => [['l'=>"Max losing streak: {$maxConsecLosses}",'c'=>'red'],['l'=>"Max winning streak: {$maxConsecWins}",'c'=>'green']],
            'fix'   => 'After 3 consecutive losses in one session, stop for the day. Review setups, reset, come back tomorrow.',
        ];
    }

    // #10 Worst trading hours
    $worstHours = [];
    for ($h = 0; $h < 24; $h++) {
        $w = $hourlyWR[$h]['w']; $l = $hourlyWR[$h]['l'];
        if ($w + $l >= 3 && round($w/($w+$l)*100,1) < 35) {
            $worstHours[] = sprintf('%02d:00', $h);
        }
    }
    if (count($worstHours) >= 2) {
        $insights[] = ['sev'=>'medium','icon'=>'fas fa-clock',
            'title' => 'Consistently Losing During Certain Hours',
            'body'  => 'Win rate drops below 35% during: ' . implode(', ', $worstHours) . '. Trading during low-liquidity or volatile hours (pre-market, news windows) leads to poor fills and erratic moves.',
            'pills' => array_map(fn($h) => ['l'=>$h,'c'=>'red'], $worstHours),
            'fix'   => 'Add these hours to a blocked list. If you must trade, reduce lot size by 50%.',
        ];
    }

    // Sort by severity
    $sevOrder = ['critical'=>0,'high'=>1,'medium'=>2,'low'=>3];
    usort($insights, fn($a,$b) => $sevOrder[$a['sev']] - $sevOrder[$b['sev']]);
}

// Helper
function sevBadge($s) {
    return match($s) {
        'critical' => ['bg'=>'rgba(127,0,0,.15)',     'color'=>'#b91c1c', 'label'=>'Critical'],
        'high'     => ['bg'=>'rgba(220,38,38,.10)',   'color'=>'#dc2626', 'label'=>'High'],
        'medium'   => ['bg'=>'rgba(217,119,6,.10)',   'color'=>'#d97706', 'label'=>'Medium'],
        'low'      => ['bg'=>'rgba(37,99,235,.10)',   'color'=>'#2563eb', 'label'=>'Low'],
        default    => ['bg'=>'rgba(100,116,139,.10)', 'color'=>'#64748b', 'label'=>'Info'],
    };
}
function pillCls($c) {
    return match($c) {
        'red'   => 'background:#FCEBEB;color:#A32D2D',
        'green' => 'background:#EAF3DE;color:#3B6D11',
        'amber' => 'background:#FAEEDA;color:#854F0B',
        'blue'  => 'background:#E6F1FB;color:#185FA5',
        default => 'background:#F1EFE8;color:#5F5E5A',
    };
}

$durLabelsJson  = json_encode($durLabels);
$durWRJson      = json_encode($durWR);
$durAvgJson     = json_encode($durAvg);
$hourLabelsJson = json_encode($hourLabels);
$hourWRJson     = json_encode($hourWRdata);
$dowLabelsJson  = json_encode($dowLabels ?? []);
$dowWRJson      = json_encode($dowWRdata ?? []);
$dowNetJson     = json_encode($dowNetdata ?? []);

$pageTitle = 'Trade Analyzer';
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
/* ── Date bar ── */
.az-date-bar { display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:20px;
    background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px; }
.az-date-bar label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);display:block;margin-bottom:5px; }
.az-date-bar input[type=date] { background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);
    color:var(--text-primary);padding:8px 12px;font-size:13px;outline:none;cursor:pointer; }
.az-date-bar input[type=date]:focus { border-color:var(--accent); }
.az-btn-go { background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 22px;
    font-size:13px;font-weight:600;cursor:pointer; }
.az-btn-go:hover { background:var(--accent-hover); }
.az-shortcut { font-size:11px;padding:5px 11px;border:1px solid var(--border);border-radius:20px;
    color:var(--text-muted);cursor:pointer;background:var(--bg-elevated);text-decoration:none;display:inline-block;transition:all .15s; }
.az-shortcut:hover { border-color:var(--accent);color:var(--accent); }

/* ── Score card ── */
.az-score-wrap { display:grid;grid-template-columns:180px 1fr;gap:0;
    background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow:hidden; }
@media(max-width:640px){ .az-score-wrap{ grid-template-columns:1fr; } }
.az-score-dial { display:flex;flex-direction:column;align-items:center;justify-content:center;
    padding:24px 20px;border-right:1px solid var(--border);background:var(--bg-elevated); }
.az-score-num { font-family:var(--font-mono);font-size:52px;font-weight:800;line-height:1; }
.az-score-lbl { font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-top:4px; }
.az-score-sub { font-size:10px;color:var(--text-muted);margin-top:2px; }
.az-score-factors { padding:18px 20px; }
.az-score-factors h4 { font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px; }
.az-sf-row { display:flex;align-items:center;gap:10px;margin-bottom:8px; }
.az-sf-lbl { font-size:11px;color:var(--text-secondary);width:120px;flex-shrink:0; }
.az-sf-bar { flex:1;height:6px;background:var(--bg-elevated);border-radius:3px;overflow:hidden; }
.az-sf-fill { height:100%;border-radius:3px;transition:width .7s ease; }
.az-sf-val { font-size:11px;font-weight:700;width:36px;text-align:right;flex-shrink:0; }

/* ── KPI row ── */
.az-kpi-row { display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:20px; }
.az-kpi { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px; }
.az-kpi-lbl { font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px; }
.az-kpi-val { font-family:var(--font-mono);font-size:20px;font-weight:700;line-height:1.1; }
.az-kpi-sub { font-size:10px;color:var(--text-muted);margin-top:3px; }
.az-kpi-val.pos { color:var(--profit); }
.az-kpi-val.neg { color:var(--loss); }
.az-kpi-val.wrn { color:var(--warning); }

/* ── Section label ── */
.az-section-lbl { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;
    color:var(--text-muted);margin:24px 0 12px;display:flex;align-items:center;gap:8px; }
.az-section-lbl span { flex:1;height:1px;background:var(--border); }

/* ── Charts ── */
.az-grid2 { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px; }
.az-grid3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px; }
@media(max-width:900px){ .az-grid3{ grid-template-columns:1fr 1fr; } }
@media(max-width:640px){ .az-grid2,.az-grid3{ grid-template-columns:1fr; } }
.az-card { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px; }
.az-card-title { font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:12px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.5px; }

/* ── Insight cards ── */
.az-insight { background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius);
    padding:16px 18px;margin-bottom:10px;border-left:4px solid transparent; }
.az-insight.critical { border-left-color:#b91c1c; }
.az-insight.high     { border-left-color:var(--loss); }
.az-insight.medium   { border-left-color:var(--warning); }
.az-insight.low      { border-left-color:var(--accent); }
.az-i-header { display:flex;align-items:center;gap:10px;margin-bottom:6px; }
.az-i-icon { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:13px; }
.az-i-title { font-size:14px;font-weight:700;color:var(--text-primary);flex:1; }
.az-sev-badge { font-size:9px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;flex-shrink:0; }
.az-i-body { font-size:13px;color:var(--text-secondary);line-height:1.65;margin-bottom:8px;padding-left:40px; }
.az-pills { display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px;padding-left:40px; }
.az-pill { font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px; }
.az-fix { font-size:12px;background:var(--bg-elevated);border-radius:var(--radius-sm);
    padding:8px 12px;color:var(--text-secondary);border-left:3px solid var(--profit);margin-left:40px; }
.az-fix strong { color:var(--profit); }

/* ── Symbol table ── */
.az-sym-table { width:100%;border-collapse:collapse;font-size:12px; }
.az-sym-table th { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;
    color:var(--text-muted);padding:7px 10px;border-bottom:1px solid var(--border);text-align:left; }
.az-sym-table td { padding:8px 10px;border-bottom:1px solid var(--border-light); }
.az-sym-table tr:last-child td { border-bottom:none; }
.az-sym-table tr:hover td { background:var(--bg-elevated); }

/* ── Bar rows ── */
.az-bar-row { display:flex;align-items:center;gap:10px;margin-bottom:10px; }
.az-bar-lbl { font-size:11px;color:var(--text-muted);width:90px;flex-shrink:0;text-align:right; }
.az-bar-track { flex:1;height:6px;background:var(--bg-elevated);border-radius:3px;overflow:hidden; }
.az-bar-fill { height:100%;border-radius:3px; }
.az-bar-val { font-size:11px;font-weight:700;width:55px;flex-shrink:0; }
.az-bar-val.pos { color:var(--profit); }
.az-bar-val.neg { color:var(--loss); }
.az-bar-val.wrn { color:var(--warning); }

/* ── Expectancy box ── */
.az-exp-box { background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px 16px;font-size:12px;
    color:var(--text-secondary);line-height:1.8;border-left:3px solid var(--accent-cyan); }
.az-empty { text-align:center;padding:60px;color:var(--text-muted); }
</style>

<!-- Date filter -->
<form method="GET" class="az-date-bar">
    <div>
        <label>From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div>
        <label>To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit" class="az-btn-go"><i class="fas fa-magnifying-glass-chart"></i> Analyze</button>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;padding-bottom:2px">
        <a class="az-shortcut" href="?from=<?= date('Y-m-d',strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>">7 days</a>
        <a class="az-shortcut" href="?from=<?= date('Y-m-d',strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>">30 days</a>
        <a class="az-shortcut" href="?from=<?= date('Y-m-d',strtotime('-90 days')) ?>&to=<?= date('Y-m-d') ?>">90 days</a>
        <a class="az-shortcut" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">This Month</a>
        <a class="az-shortcut" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">This Year</a>
        <a class="az-shortcut" href="?from=2000-01-01&to=<?= date('Y-m-d') ?>">All Time</a>
    </div>
    <?php
    $activeCycleLabel = '';
    foreach ($cycles as $c) {
        if (!$c['start_date']) continue;
        $cTo = $c['end_date'] ?? date('Y-m-d');
        if ($c['start_date'] === $from && $cTo === $to) { $activeCycleLabel = 'Cycle #'.$c['cycle_number']; break; }
    }
    if (!empty($cycles)): ?>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding-top:6px;margin-top:4px;border-top:1px solid var(--border);width:100%">
        <span style="font-size:11px;color:var(--text-muted)"><i class="fas fa-rotate"></i> Cycles:</span>
        <?php foreach (array_reverse($cycles) as $c): if (!$c['start_date']) continue;
            $cTo = $c['end_date'] ?? date('Y-m-d'); ?>
        <a class="az-shortcut" href="?from=<?= $c['start_date'] ?>&to=<?= $cTo ?>">
            <?= $c['is_active'] ? '<i class="fas fa-circle" style="font-size:6px;vertical-align:middle;color:var(--accent)"></i>&nbsp;' : '' ?>Cycle #<?= $c['cycle_number'] ?>
            <span style="opacity:.6;font-size:10px">&nbsp;<?= date('d M', strtotime($c['start_date'])) ?>–<?= $c['is_active'] ? 'Now' : date('d M', strtotime($cTo)) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>

<?php if (!$hasData): ?>
<div class="az-empty">
    <i class="fas fa-chart-bar" style="font-size:36px;margin-bottom:12px;display:block"></i>
    <strong style="font-size:16px;color:var(--text-secondary);display:block;margin-bottom:6px">No trades found</strong>
    <p>No trades between <?= date('d M Y', strtotime($from)) ?> and <?= date('d M Y', strtotime($to)) ?>.<br>Try a wider date range or import trades via CSV.</p>
</div>
<?php else: ?>

<!-- Period label -->
<div class="az-section-lbl">
    <?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?>
    <?php if ($activeCycleLabel): ?>
    <span style="background:rgba(37,99,235,.15);color:var(--accent);padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;font-family:var(--font-mono)"><?= $activeCycleLabel ?></span>
    <?php endif; ?>
    <span></span>
</div>

<!-- Trading Health Score -->
<div class="az-score-wrap" style="margin-bottom:20px">
    <div class="az-score-dial">
        <div class="az-score-num" style="color:<?= $scoreColor ?>"><?= $tradingScore ?></div>
        <div class="az-score-lbl" style="color:<?= $scoreColor ?>"><?= $scoreLabel ?></div>
        <div class="az-score-sub">out of 100</div>
    </div>
    <div class="az-score-factors">
        <h4>Health Score Breakdown</h4>
        <?php
        $factors = [
            'Win Rate'         => ['val'=>(int)$s_wr,  'raw'=>$winRate.'%'],
            'Risk:Reward'      => ['val'=>(int)$s_rr,  'raw'=>$rrRatio.'×'],
            'Profit Factor'    => ['val'=>(int)$s_pf,  'raw'=>$profitFactor],
            'Brokerage Drag'   => ['val'=>(int)$s_bk,  'raw'=>$brokPct.'%'],
            'No Revenge Trade' => ['val'=>(int)$s_rv,  'raw'=>$revengeTrades.' detected'],
            'No Stop-Outs'     => ['val'=>(int)$s_so,  'raw'=>$soEvents['cnt'].' events'],
        ];
        foreach ($factors as $name => $f):
            $fc = $f['val'] >= 65 ? 'var(--profit)' : ($f['val'] >= 40 ? 'var(--warning)' : 'var(--loss)');
        ?>
        <div class="az-sf-row">
            <div class="az-sf-lbl"><?= $name ?></div>
            <div class="az-sf-bar"><div class="az-sf-fill" style="width:<?= $f['val'] ?>%;background:<?= $fc ?>"></div></div>
            <div class="az-sf-val" style="color:<?= $fc ?>"><?= $f['val'] ?></div>
            <div style="font-size:10px;color:var(--text-muted);width:90px;flex-shrink:0;margin-left:4px"><?= $f['raw'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI Row -->
<div class="az-kpi-row">
    <div class="az-kpi">
        <div class="az-kpi-lbl">Total Trades</div>
        <div class="az-kpi-val"><?= number_format($total) ?></div>
        <div class="az-kpi-sub"><?= $wins ?>W / <?= $losses ?>L</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Win Rate</div>
        <div class="az-kpi-val <?= $winRate >= 50 ? 'pos' : ($winRate >= 40 ? 'wrn' : 'neg') ?>"><?= $winRate ?>%</div>
        <div class="az-kpi-sub">R:R <?= $rrRatio ?></div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Net P&amp;L</div>
        <div class="az-kpi-val <?= $netPL >= 0 ? 'pos' : 'neg' ?>"><?= ($netPL>=0?'+':'') ?>$<?= number_format($netPL,2) ?></div>
        <div class="az-kpi-sub">After brok + swap</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Profit Factor</div>
        <div class="az-kpi-val <?= $profitFactor >= 1.5 ? 'pos' : ($profitFactor >= 1.0 ? 'wrn' : 'neg') ?>"><?= $profitFactor ?></div>
        <div class="az-kpi-sub">Gross wins ÷ losses</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Expectancy</div>
        <div class="az-kpi-val <?= $expectancy >= 0 ? 'pos' : 'neg' ?>"><?= ($expectancy>=0?'+':'') ?>$<?= $expectancy ?></div>
        <div class="az-kpi-sub">Avg $ per trade</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Avg Win</div>
        <div class="az-kpi-val pos">+$<?= $avgWin ?></div>
        <div class="az-kpi-sub">per winning trade</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Avg Loss</div>
        <div class="az-kpi-val neg">$<?= $avgLoss ?></div>
        <div class="az-kpi-sub">per losing trade</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Brokerage</div>
        <div class="az-kpi-val <?= $brokPct > 15 ? 'neg' : 'wrn' ?>">-$<?= number_format($totalBrok,2) ?></div>
        <div class="az-kpi-sub"><?= $brokPct ?>% of gross profit</div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">SL / TP / Manual</div>
        <div class="az-kpi-val wrn"><?= $slHits ?> / <?= $tpHits ?> / <?= $manualClose ?></div>
        <div class="az-kpi-sub">Revenge trades: <?= $revengeTrades ?></div>
    </div>
    <div class="az-kpi">
        <div class="az-kpi-lbl">Win Streak</div>
        <div class="az-kpi-val pos"><?= $maxConsecWins ?></div>
        <div class="az-kpi-sub">Loss streak: <?= $maxConsecLosses ?></div>
    </div>
    <?php if ($soEvents['cnt'] > 0): ?>
    <div class="az-kpi" style="border-top:3px solid var(--loss)">
        <div class="az-kpi-lbl">Account Stop-Outs</div>
        <div class="az-kpi-val neg"><?= $soEvents['cnt'] ?></div>
        <div class="az-kpi-sub">Capital wiped: -<?= formatUSD($soEvents['total']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Charts row 1: Equity + Close Reason -->
<div class="az-grid2">
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-chart-area" style="color:var(--accent)"></i> Net Equity Curve</div>
        <div style="position:relative;height:190px"><canvas id="eqChart"></canvas></div>
    </div>
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-chart-pie" style="color:var(--accent-purple)"></i> Exit Reason Breakdown</div>
        <div style="display:flex;align-items:center;gap:20px;height:190px">
            <div style="position:relative;width:155px;height:155px;flex-shrink:0">
                <canvas id="reasonChart"></canvas>
            </div>
            <div style="font-size:12px;line-height:2.2">
                <?php $total_ = max(1,$total);
                foreach ([['SL','#ef4444',$slHits],['TP','#22c55e',$tpHits],['Manual','#3b82f6',$manualClose],['Stop-Out','#f59e0b',$soHits]] as [$lbl,$clr,$cnt_]):
                    if ($cnt_ == 0) continue; ?>
                <div><span style="display:inline-block;width:10px;height:10px;background:<?= $clr ?>;border-radius:2px;margin-right:6px"></span><?= $lbl ?>: <?= $cnt_ ?> <span style="color:var(--text-muted);font-size:10px">(<?= round($cnt_/$total_*100,1) ?>%)</span></div>
                <?php endforeach; ?>
                <?php if ($tpHits > 0 && $slHits > 0): ?>
                <div style="margin-top:6px;font-size:11px;color:var(--text-muted)">SL:TP ratio <?= round($slHits/max(1,$tpHits),1) ?>:1</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts row 2: Duration + Hourly -->
<div class="az-grid2" style="margin-bottom:14px">
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-hourglass-half" style="color:var(--accent-cyan)"></i> Win Rate by Duration</div>
        <div style="position:relative;height:180px"><canvas id="durChart"></canvas></div>
    </div>
    <?php if (!empty($hourLabels)): ?>
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-clock" style="color:var(--warning)"></i> Win Rate by Hour of Day</div>
        <div style="position:relative;height:180px"><canvas id="hourChart"></canvas></div>
    </div>
    <?php endif; ?>
</div>

<!-- Charts row 3: Day of Week + Lot Size -->
<?php if (!empty($dowLabels)): ?>
<div class="az-grid2" style="margin-bottom:14px">
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-calendar-week" style="color:var(--accent-purple)"></i> Win Rate by Day of Week</div>
        <div style="position:relative;height:180px"><canvas id="dowChart"></canvas></div>
    </div>
    <div class="az-card">
        <div class="az-card-title"><i class="fas fa-layer-group" style="color:var(--profit)"></i> Small vs Large Lot Sizes</div>
        <?php foreach (['small'=>'Small lots  (<0.5)','large'=>'Large lots  (≥0.5)'] as $k => $lbl):
            $lb = $lotBuckets[$k]; if ($lb['c'] == 0) continue;
            $wr = $lb['wr'];
            $bc = $wr >= 50 ? 'var(--profit)' : ($wr >= 38 ? 'var(--warning)' : 'var(--loss)');
        ?>
        <div class="az-bar-row">
            <div class="az-bar-lbl"><?= $lbl ?></div>
            <div class="az-bar-track"><div class="az-bar-fill" style="width:<?= $wr ?>%;background:<?= $bc ?>"></div></div>
            <div class="az-bar-val <?= $wr >= 50 ? 'pos' : ($wr >= 38 ? 'wrn' : 'neg') ?>"><?= $wr ?>%</div>
        </div>
        <div style="padding-left:100px;margin-top:-6px;margin-bottom:12px;font-size:10px;color:var(--text-muted)">
            <?= $lb['c'] ?> trades &nbsp;·&nbsp; avg $<?= $lb['avgPL'] ?> &nbsp;·&nbsp; net <span style="color:<?= $lb['net']>=0?'var(--profit)':'var(--loss)' ?>">$<?= number_format($lb['net'],2) ?></span>
        </div>
        <?php endforeach; ?>
        <div style="font-size:11px;color:var(--text-muted);padding-top:6px;border-top:1px solid var(--border-light)">
            Large brok: <strong style="color:var(--loss)">-$<?= number_format($lotBuckets['large']['b'],2) ?></strong>
            &nbsp;·&nbsp; Small brok: <strong style="color:var(--loss)">-$<?= number_format($lotBuckets['small']['b'],2) ?></strong>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Mistakes & Patterns -->
<div class="az-section-lbl">
    <i class="fas fa-triangle-exclamation" style="color:var(--loss)"></i>
    Patterns & Mistakes — <?= count($insights) ?> issue<?= count($insights) !== 1 ? 's' : '' ?> found
    <span></span>
</div>

<?php if (empty($insights)): ?>
<div class="az-card" style="text-align:center;padding:28px;margin-bottom:12px">
    <i class="fas fa-medal" style="color:var(--profit);font-size:28px;margin-bottom:8px;display:block"></i>
    <strong style="color:var(--profit)">No major issues detected!</strong>
    <p style="color:var(--text-muted);font-size:13px;margin-top:4px">Your trading looks disciplined for this period. Keep it consistent.</p>
</div>
<?php else: ?>
<?php foreach ($insights as $idx => $ins):
    $sev = sevBadge($ins['sev']);
    $sevColors = ['critical'=>'rgba(127,0,0,.12)','high'=>'rgba(220,38,38,.10)','medium'=>'rgba(217,119,6,.10)','low'=>'rgba(37,99,235,.10)'];
    $iconBg = $sevColors[$ins['sev']] ?? 'rgba(100,116,139,.10)';
?>
<div class="az-insight <?= $ins['sev'] ?>">
    <div class="az-i-header">
        <div class="az-i-icon" style="background:<?= $iconBg ?>;color:<?= $sev['color'] ?>">
            <i class="<?= $ins['icon'] ?>"></i>
        </div>
        <div class="az-i-title"><?= htmlspecialchars($ins['title']) ?></div>
        <span class="az-sev-badge" style="background:<?= $sev['bg'] ?>;color:<?= $sev['color'] ?>"><?= $sev['label'] ?></span>
    </div>
    <div class="az-i-body"><?= htmlspecialchars($ins['body']) ?></div>
    <div class="az-pills">
        <?php foreach ($ins['pills'] as $p): ?>
        <span class="az-pill" style="<?= pillCls($p['c']) ?>"><?= htmlspecialchars($p['l']) ?></span>
        <?php endforeach; ?>
    </div>
    <div class="az-fix"><strong>Fix:</strong> <?= htmlspecialchars($ins['fix']) ?></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Symbol Performance -->
<div class="az-section-lbl"><i class="fas fa-coins" style="color:var(--warning)"></i> Symbol Performance <span></span></div>
<div class="az-card" style="margin-bottom:14px;padding:0">
    <table class="az-sym-table">
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Trades</th>
                <th>WR</th>
                <th>Gross P/L</th>
                <th>Brokerage</th>
                <th>Net P/L</th>
                <th>Verdict</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($symbolMap as $sym => $s):
            $sWR  = $s['c'] > 0 ? round($s['w']/$s['c']*100,1) : 0;
            $verdict = $s['net'] >= 0 && $sWR >= 50 ? ['Keep','var(--profit)'] : ($s['net'] < 0 ? ['Review','var(--loss)'] : ['Watch','var(--warning)']);
        ?>
        <tr>
            <td><span class="symbol-badge"><?= htmlspecialchars($sym) ?></span></td>
            <td class="mono"><?= $s['c'] ?></td>
            <td style="font-weight:600;color:<?= $sWR>=50?'var(--profit)':($sWR>=40?'var(--warning)':'var(--loss)') ?>"><?= $sWR ?>%</td>
            <td class="<?= $s['pl']>=0?'pl-positive':'pl-negative' ?> mono"><?= ($s['pl']>=0?'+':'') ?>$<?= number_format($s['pl'],2) ?></td>
            <td class="pl-negative mono">-$<?= number_format($s['b'],2) ?></td>
            <td class="<?= $s['net']>=0?'pl-positive':'pl-negative' ?> mono" style="font-weight:700"><?= ($s['net']>=0?'+':'') ?>$<?= number_format($s['net'],2) ?></td>
            <td><span style="font-size:10px;font-weight:700;color:<?= $verdict[1] ?>"><?= $verdict[0] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Expectancy summary -->
<div class="az-exp-box" style="margin-bottom:20px">
    <strong style="color:var(--text-primary)">Summary:</strong>
    Win rate <?= $winRate ?>% with R:R <?= $rrRatio ?> gives an expectancy of <strong style="color:<?= $expectancy>=0?'var(--profit)':'var(--loss)' ?>"><?= ($expectancy>=0?'+':'') ?>$<?= $expectancy ?> per trade</strong>.
    Profit factor <?= $profitFactor ?> <?= $profitFactor >= 1.5 ? '✓ above target (1.5)' : '— target is ≥ 1.5' ?>.
    <?php if ($revengeTrades > 0): ?>
    Revenge trades detected: <?= $revengeTrades ?> (avoid immediately after a loss).
    <?php endif; ?>
    <?php if ($brokPct > 15): ?>
    Brokerage is consuming <?= $brokPct ?>% of gross profit — reduce trade frequency.
    <?php endif; ?>
    <?php if ($maxConsecLosses >= 5): ?>
    Longest losing streak: <?= $maxConsecLosses ?> — add a 3-loss daily stop rule.
    <?php endif; ?>
</div>

<?php endif; // hasData ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const dark  = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridC = dark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const textC = dark ? '#94a3b8' : '#64748b';
    const baseScales = {
        x:{ grid:{color:gridC}, ticks:{color:textC,font:{size:10}} },
        y:{ grid:{color:gridC}, ticks:{color:textC,font:{size:10}} }
    };

    // Equity curve
    const eqDates  = <?= $chartDatesJson ?>;
    const eqValues = <?= $chartEquityJson ?>;
    if (eqDates.length && document.getElementById('eqChart')) {
        const clr = (eqValues[eqValues.length-1] ?? 0) >= 0 ? '#22c55e' : '#ef4444';
        new Chart(document.getElementById('eqChart'), { type:'line', data:{
            labels:eqDates, datasets:[{data:eqValues,borderColor:clr,backgroundColor:clr+'18',
            borderWidth:2,fill:true,pointRadius:0,pointHitRadius:20,tension:.35}]
        }, options:{responsive:true,maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{display:false},tooltip:{callbacks:{
                title: t => t[0].label,
                label: c => ' Cumulative P/L: ' + (c.parsed.y >= 0 ? '+' : '') + '$' + c.parsed.y.toFixed(2)
            }}},
            scales:{x:{...baseScales.x,ticks:{...baseScales.x.ticks,maxTicksLimit:8,maxRotation:30}},
                    y:{...baseScales.y,ticks:{...baseScales.y.ticks,callback:v=>'$'+v}}}}});
    }

    // Exit reason donut
    if (document.getElementById('reasonChart')) {
        new Chart(document.getElementById('reasonChart'), { type:'doughnut', data:{
            labels:['SL','TP','Manual','Stop-Out'],
            datasets:[{data:[<?= $slHits ?>,<?= $tpHits ?>,<?= $manualClose ?>,<?= $soHits ?>],
            backgroundColor:['#ef4444','#22c55e','#3b82f6','#f59e0b'],borderWidth:0,hoverOffset:4}]
        }, options:{responsive:false,cutout:'65%',plugins:{legend:{display:false},
            tooltip:{callbacks:{label:c=>' '+c.label+': '+c.parsed}}}}});
    }

    // Duration bar chart
    const durL = <?= $durLabelsJson ?>; const durW = <?= $durWRJson ?>; const durA = <?= $durAvgJson ?>;
    if (durL.length && document.getElementById('durChart')) {
        new Chart(document.getElementById('durChart'), { type:'bar', data:{
            labels:durL, datasets:[{data:durW,backgroundColor:durW.map(v=>v>=55?'#22c55e':v>=40?'#f59e0b':'#ef4444'),borderRadius:5}]
        }, options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' WR: '+c.parsed.y+'%',afterLabel:c=>' Avg: $'+durA[c.dataIndex]}}},
            scales:{x:baseScales.x,y:{...baseScales.y,min:0,max:100,ticks:{...baseScales.y.ticks,callback:v=>v+'%'}}}}});
    }

    // Hourly bar chart
    const hourL = <?= $hourLabelsJson ?>; const hourW = <?= $hourWRJson ?>;
    if (hourL.length && document.getElementById('hourChart')) {
        new Chart(document.getElementById('hourChart'), { type:'bar', data:{
            labels:hourL, datasets:[{data:hourW,backgroundColor:hourW.map(v=>v>=55?'#22c55e':v>=40?'#f59e0b':'#ef4444'),borderRadius:3}]
        }, options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' WR: '+c.parsed.y+'%'}}},
            scales:{x:{...baseScales.x,ticks:{...baseScales.x.ticks,maxRotation:45,font:{size:9}}},
                    y:{...baseScales.y,min:0,max:100,ticks:{...baseScales.y.ticks,callback:v=>v+'%'}}}}});
    }

    // Day-of-week bar chart
    const dowL = <?= $dowLabelsJson ?>; const dowW = <?= $dowWRJson ?>; const dowN = <?= $dowNetJson ?>;
    if (dowL.length && document.getElementById('dowChart')) {
        new Chart(document.getElementById('dowChart'), { type:'bar', data:{
            labels:dowL, datasets:[{data:dowW,backgroundColor:dowW.map(v=>v>=55?'#22c55e':v>=40?'#f59e0b':'#ef4444'),borderRadius:4}]
        }, options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' WR: '+c.parsed.y+'%',afterLabel:c=>' Net: $'+dowN[c.dataIndex]}}},
            scales:{x:baseScales.x,y:{...baseScales.y,min:0,max:100,ticks:{...baseScales.y.ticks,callback:v=>v+'%'}}}}});
    }
})();
</script>

<?php include '../includes/footer.php'; ?>

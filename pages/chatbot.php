<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!getCurrentUserId()) {
    echo json_encode(['reply' => 'Please log in to use the assistant.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['reply' => 'Invalid request.']);
    exit;
}

$raw = trim($_POST['message'] ?? '');
if ($raw === '') {
    echo json_encode(['reply' => '']);
    exit;
}

$userId = getCurrentUserId();
$db     = getDB();
$msg    = strtolower($raw);

// ── Helpers ──────────────────────────────────────────────────────────────────

function detectPeriod(string $msg): ?string {
    if (preg_match('/\byesterday\b/', $msg))                         return 'yesterday';
    if (preg_match('/\btoday\b/', $msg))                             return 'today';
    if (preg_match('/\bthis week\b|\bweekly\b|\bthis wk\b/', $msg)) return 'week';
    if (preg_match('/\bthis month\b|\bmonthly\b/', $msg))           return 'month';
    if (preg_match('/\blast week\b|\bprevious week\b/', $msg))       return 'last_week';
    if (preg_match('/\blast month\b|\bprevious month\b/', $msg))     return 'last_month';
    if (preg_match('/\ball.?time\b|\boverall\b|\ball\b/', $msg))     return 'all';
    return null;
}

function periodDates(string $period): array {
    return match ($period) {
        'today'      => [date('Y-m-d'), date('Y-m-d')],
        'yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        'week'       => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
        'last_week'  => [date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('sunday last week'))],
        'month'      => [date('Y-m-01'), date('Y-m-d')],
        'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        default      => [null, null],
    };
}

function periodLabel(string $period): string {
    return match ($period) {
        'today'      => 'today',
        'yesterday'  => 'yesterday',
        'week'       => 'this week',
        'last_week'  => 'last week',
        'month'      => 'this month',
        'last_month' => 'last month',
        'all'        => 'all time',
        default      => $period,
    };
}

function plStr(float $v): string {
    $cs = getActiveCurrency()['symbol'];
    return ($v >= 0 ? '+' : '-') . $cs . number_format(abs($v), 2);
}

// ── Intent matching ───────────────────────────────────────────────────────────

$reply = '';

// --- Greeting ---
if (preg_match('/^(hi+|hello+|hey+|good (morning|afternoon|evening)|howdy|what\'s up|sup)\b/i', $msg)) {
    $user  = getLoggedInUser();
    $first = explode(' ', $user['name'] ?? 'Trader')[0];
    $hour  = (int)date('H');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $reply = "$greet, $first! 👋 I know everything about your trades. Ask me things like:\n• How did I do today?\n• What's my win rate?\n• Show me my last 5 trades\n• What's my best symbol?\n\nType **help** to see the full list.";
}

// --- Help ---
elseif (preg_match('/\b(help|what can you|what can i ask|commands|guide|what do you know)\b/', $msg)) {
    $reply = "Here's what I can answer:\n\n📊 **Performance**\n• P/L for today / this week / this month / all time\n• Win rate, average trade, expectancy\n\n💹 **Trades**\n• Best & worst trade ever\n• Last N trades (e.g. \"last 10 trades\")\n• Total trade count (today / this week / all)\n\n🏆 **Symbols**\n• Best & worst performing symbol\n• Stats for a specific symbol (e.g. \"XAUUSD stats\")\n\n⚠️ **Risk & Account**\n• Current balance\n• Drawdown & risk status\n• Streak (winning / losing)\n• Brokerage fees paid\n\n📅 **Days**\n• Best/worst day of the week";
}

// --- Balance ---
elseif (preg_match('/\b(balance|equity|account value|account balance|how much do i have|current balance|my balance)\b/', $msg)) {
    $bal   = getCurrentBalance($userId);
    $cs    = getActiveCurrency()['symbol'];
    $reply = "Your current account balance is **{$cs}" . number_format($bal, 2) . "**.";
}

// --- P/L (profit/loss/performance/earnings) ---
elseif (preg_match('/\b(p[\s\/]?l|profit|loss|performance|how did i do|did i make|did i lose|pnl|earning|return)\b/', $msg)) {
    $period = detectPeriod($msg) ?? 'today';

    if ($period === 'all') {
        $stmt = $db->prepare("SELECT COUNT(*) as trades, COALESCE(SUM(profit_loss-brokerage+swap),0) as net_pl, COALESCE(SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END),0) as wins FROM trades WHERE user_id=?");
        $stmt->execute([$userId]);
    } else {
        [$from, $to] = periodDates($period);
        $stmt = $db->prepare("SELECT COUNT(*) as trades, COALESCE(SUM(profit_loss-brokerage+swap),0) as net_pl, COALESCE(SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END),0) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?");
        $stmt->execute([$userId, $from, $to]);
    }
    $d  = $stmt->fetch();
    $pl = round($d['net_pl'], 2);
    $tc = $d['trades'];
    $w  = $d['wins'];
    $l  = $tc - $w;

    $lbl = periodLabel($period);
    if ($tc == 0) {
        $reply = "No trades found for **$lbl**.";
    } else {
        $wr    = round($w / $tc * 100, 1);
        $icon  = $pl >= 0 ? '🟢' : '🔴';
        $reply = "$icon **" . ucfirst($lbl) . "** performance:\n• Net P/L: **" . plStr($pl) . "**\n• Trades: $tc ($w W / $l L)\n• Win Rate: $wr%";
        if ($pl < 0 && $period === 'today') $reply .= "\n\nStay disciplined — tomorrow is a new day.";
    }
}

// --- Win rate ---
elseif (preg_match('/\b(win rate|win ratio|winning percentage|win %|accuracy|success rate)\b/', $msg)) {
    $period = detectPeriod($msg);
    if ($period && $period !== 'all') {
        [$from, $to] = periodDates($period);
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?");
        $stmt->execute([$userId, $from, $to]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=?");
        $stmt->execute([$userId]);
    }
    $d  = $stmt->fetch();
    $tc = $d['total'];
    $w  = $d['wins'] ?? 0;
    $wr = $tc > 0 ? round($w / $tc * 100, 1) : 0;
    $lbl = $period ? periodLabel($period) : 'all time';
    $icon = $wr >= 60 ? '🟢' : ($wr >= 50 ? '🟡' : '🔴');
    $reply = "$icon Your win rate **$lbl** is **$wr%** ($w wins out of $tc trades).";
    if ($wr >= 60)     $reply .= " Excellent consistency!";
    elseif ($wr >= 50) $reply .= " Above 50% — keep it up.";
    else               $reply .= " Below 50% — review your entry criteria.";
}

// --- Average trade ---
elseif (preg_match('/\b(average trade|avg trade|average p.?l|avg p.?l|average profit|expectancy|per trade|avg return)\b/', $msg)) {
    $stmt = $db->prepare("SELECT COUNT(*) as total, AVG(profit_loss-brokerage+swap) as avg_pl, AVG(CASE WHEN profit_loss>0 THEN profit_loss-brokerage+swap END) as avg_win, AVG(CASE WHEN profit_loss<0 THEN profit_loss-brokerage+swap END) as avg_loss FROM trades WHERE user_id=?");
    $stmt->execute([$userId]);
    $d   = $stmt->fetch();
    $avg = round($d['avg_pl'] ?? 0, 2);
    $aw  = round($d['avg_win'] ?? 0, 2);
    $al  = round($d['avg_loss'] ?? 0, 2);
    $rr  = $al != 0 ? round(abs($aw / $al), 2) : '—';
    $icon = $avg >= 0 ? '🟢' : '🔴';
    $cs    = getActiveCurrency()['symbol'];
    $reply = "$icon **Average Trade Stats (all time):**\n• Avg P/L per trade: **" . plStr($avg) . "**\n• Avg winning trade: +{$cs}" . number_format($aw, 2) . "\n• Avg losing trade: -{$cs}" . number_format(abs($al), 2) . "\n• Risk/Reward ratio: $rr";
}

// --- Best trade ---
elseif (preg_match('/\b(best trade|biggest win|largest profit|best single|top trade|my best)\b/', $msg)) {
    $stmt = $db->prepare("SELECT symbol, trade_type, profit_loss, brokerage, swap, trade_datetime FROM trades WHERE user_id=? ORDER BY (profit_loss-brokerage+swap) DESC LIMIT 1");
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    if ($t) {
        $net   = round($t['profit_loss'] - $t['brokerage'] + $t['swap'], 2);
        $cs    = getActiveCurrency()['symbol'];
        $reply = "🏆 **Your best trade ever:**\n• Symbol: **{$t['symbol']}** ({$t['trade_type']})\n• Net P/L: **+{$cs}" . number_format($net, 2) . "**\n• Date: " . date('d M Y', strtotime($t['trade_datetime']));
    } else {
        $reply = "No trades found yet.";
    }
}

// --- Worst trade ---
elseif (preg_match('/\b(worst trade|biggest loss|largest loss|worst single|my worst|bad trade)\b/', $msg)) {
    $stmt = $db->prepare("SELECT symbol, trade_type, profit_loss, brokerage, swap, trade_datetime FROM trades WHERE user_id=? ORDER BY (profit_loss-brokerage+swap) ASC LIMIT 1");
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    if ($t) {
        $net   = round($t['profit_loss'] - $t['brokerage'] + $t['swap'], 2);
        $cs    = getActiveCurrency()['symbol'];
        $reply = "📉 **Your worst trade ever:**\n• Symbol: **{$t['symbol']}** ({$t['trade_type']})\n• Net P/L: **-{$cs}" . number_format(abs($net), 2) . "**\n• Date: " . date('d M Y', strtotime($t['trade_datetime']));
    } else {
        $reply = "No trades found yet.";
    }
}

// --- Best symbol ---
elseif (preg_match('/\b(best symbol|top symbol|best pair|best instrument|best asset|which.*(symbol|pair|instrument).*best|most profitable)\b/', $msg)) {
    $stmt = $db->prepare("SELECT symbol, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net_pl, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? GROUP BY symbol HAVING trades >= 2 ORDER BY net_pl DESC LIMIT 1");
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    if ($t) {
        $net = round($t['net_pl'], 2);
        $wr  = round($t['wins'] / $t['trades'] * 100, 1);
        $cs    = getActiveCurrency()['symbol'];
        $reply = "🏆 Your best performing symbol is **{$t['symbol']}**:\n• Net P/L: **+{$cs}" . number_format($net, 2) . "**\n• Trades: {$t['trades']} | Win Rate: $wr%";
    } else {
        $reply = "Not enough trades to determine a best symbol (need at least 2 per symbol).";
    }
}

// --- Worst symbol ---
elseif (preg_match('/\b(worst symbol|worst pair|worst instrument|worst asset|losing symbol|losing pair|most losing)\b/', $msg)) {
    $stmt = $db->prepare("SELECT symbol, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net_pl FROM trades WHERE user_id=? GROUP BY symbol HAVING trades >= 2 ORDER BY net_pl ASC LIMIT 1");
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    if ($t) {
        $net   = round($t['net_pl'], 2);
        $cs    = getActiveCurrency()['symbol'];
        $reply = "⚠️ Your worst performing symbol is **{$t['symbol']}**:\n• Net P/L: **-{$cs}" . number_format(abs($net), 2) . "**\n• Trades: {$t['trades']}\n\nConsider reducing exposure to this symbol.";
    } else {
        $reply = "Not enough trades to determine (need at least 2 per symbol).";
    }
}

// --- All symbols overview ---
elseif (preg_match('/\b(all symbols|symbol breakdown|symbol performance|symbols overview|all pairs)\b/', $msg)) {
    $stmt = $db->prepare("SELECT symbol, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net_pl, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? GROUP BY symbol ORDER BY net_pl DESC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $reply = "**Symbol Performance:**\n";
        foreach ($rows as $r) {
            $pl   = round($r['net_pl'], 2);
            $wr   = round($r['wins'] / $r['trades'] * 100, 1);
            $icon = $pl >= 0 ? '🟢' : '🔴';
            $reply .= "\n$icon **{$r['symbol']}**: " . plStr($pl) . " | {$r['trades']} trades | $wr% WR";
        }
    } else {
        $reply = "No trades found.";
    }
}

// --- Specific symbol stats ---
elseif (preg_match('/\b([A-Z]{3,7}(?:USD|JPY|GBP|EUR|AUD|CAD|CHF|NZD)?)\b/i', $raw, $symM) && preg_match('/\b(stat|performance|how|result|p.?l|profit|loss)\b/', $msg)) {
    $sym  = strtoupper($symM[1]);
    $stmt = $db->prepare("SELECT COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net_pl, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins, MAX(profit_loss-brokerage+swap) as best, MIN(profit_loss-brokerage+swap) as worst FROM trades WHERE user_id=? AND UPPER(symbol)=?");
    $stmt->execute([$userId, $sym]);
    $d = $stmt->fetch();
    if ($d['trades'] > 0) {
        $net  = round($d['net_pl'], 2);
        $wr   = round($d['wins'] / $d['trades'] * 100, 1);
        $icon = $net >= 0 ? '🟢' : '🔴';
        $cs    = getActiveCurrency()['symbol'];
        $reply = "$icon **$sym** Stats:\n• Net P/L: **" . plStr($net) . "**\n• Trades: {$d['trades']} | Win Rate: $wr%\n• Best trade: +{$cs}" . number_format(max((float)$d['best'], 0), 2) . "\n• Worst trade: -{$cs}" . number_format(abs(min((float)$d['worst'], 0)), 2);
    } else {
        $reply = "No trades found for **$sym**.";
    }
}

// --- Recent trades ---
elseif (preg_match('/\b(recent trades?|last trades?|latest trades?|last \d+ trades?|show.*(trades?|history))\b/', $msg)) {
    preg_match('/last (\d+) trades?/', $msg, $nm);
    $limit = isset($nm[1]) ? min((int)$nm[1], 15) : 5;
    $stmt  = $db->prepare("SELECT symbol, trade_type, profit_loss, brokerage, swap, trade_datetime FROM trades WHERE user_id=? ORDER BY trade_datetime DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $reply = "Your last $limit trades:\n";
        foreach ($rows as $t) {
            $net  = round($t['profit_loss'] - $t['brokerage'] + $t['swap'], 2);
            $icon = $net >= 0 ? '🟢' : '🔴';
            $reply .= "\n$icon " . date('d M', strtotime($t['trade_datetime'])) . " — **{$t['symbol']}** {$t['trade_type']} → " . plStr($net);
        }
    } else {
        $reply = "No trades found.";
    }
}

// --- Trade count ---
elseif (preg_match('/\b(how many trades?|trade count|number of trades?|total trades?|trades? taken)\b/', $msg)) {
    $period = detectPeriod($msg);
    if ($period && $period !== 'all') {
        [$from, $to] = periodDates($period);
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?");
        $stmt->execute([$userId, $from, $to]);
        $lbl = periodLabel($period);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trades WHERE user_id=?");
        $stmt->execute([$userId]);
        $lbl = 'in total';
    }
    $tc    = $stmt->fetch()['total'];
    $reply = "You have taken **$tc trade" . ($tc != 1 ? 's' : '') . "** $lbl.";
}

// --- Streak ---
elseif (preg_match('/\b(streak|consecutive|in a row|winning streak|losing streak)\b/', $msg)) {
    $stmt = $db->prepare("SELECT profit_loss FROM trades WHERE user_id=? ORDER BY trade_datetime DESC LIMIT 100");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $first  = (float)$rows[0]['profit_loss'];
        $isWin  = $first >= 0;
        $count  = 0;
        foreach ($rows as $r) {
            if (((float)$r['profit_loss'] >= 0) === $isWin) $count++;
            else break;
        }
        $type  = $isWin ? 'winning' : 'losing';
        $icon  = $isWin ? '🟢' : '🔴';
        $reply = "$icon You're currently on a **$count-trade $type streak**.";
        if (!$isWin && $count >= 3) $reply .= "\n\nConsider taking a break and reviewing your setups before the next trade.";
        elseif ($isWin && $count >= 5) $reply .= "\n\nExcellent run! Stay disciplined and don't overtrade.";
    } else {
        $reply = "No trade history found.";
    }
}

// --- Best/worst day of week ---
elseif (preg_match('/\b(best day|worst day|day of week|which day|daily breakdown by day)\b/', $msg)) {
    $stmt = $db->prepare("SELECT DAYNAME(trade_datetime) as dn, DAYOFWEEK(trade_datetime) as dow, COUNT(*) as trades, SUM(profit_loss-brokerage+swap) as net_pl, SUM(CASE WHEN profit_loss>0 THEN 1 ELSE 0 END) as wins FROM trades WHERE user_id=? GROUP BY dow, dn ORDER BY net_pl DESC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $reply = "**Performance by Day of Week:**\n";
        foreach ($rows as $r) {
            $pl   = round($r['net_pl'], 2);
            $wr   = round($r['wins'] / $r['trades'] * 100, 1);
            $icon = $pl >= 0 ? '🟢' : '🔴';
            $reply .= "\n$icon **{$r['dn']}**: " . plStr($pl) . " | {$r['trades']} trades | $wr% WR";
        }
        $best  = $rows[0]['dn'];
        $worst = end($rows)['dn'];
        $reply .= "\n\nBest: **$best** | Worst: **$worst**";
    } else {
        $reply = "No trade history found.";
    }
}

// --- Drawdown ---
elseif (preg_match('/\b(drawdown|draw down|max drawdown|underwater)\b/', $msg)) {
    require_once '../includes/risk_engine.php';
    $m = getRiskMetrics($userId);
    $reply  = "**Drawdown Status:**\n";
    $reply .= "• Daily loss used: **{$m['daily_loss_pct_used']}%** of daily limit\n";
    $reply .= "• Weekly loss used: **{$m['weekly_loss_pct_used']}%** of weekly limit";
    if ($m['breach_daily'] || $m['breach_weekly']) {
        $reply .= "\n\n🔴 **Limit breached — trading is halted.**";
    } elseif ($m['warning_daily'] || $m['warning_weekly']) {
        $reply .= "\n\n⚠️ Approaching the limit — trade with extreme care.";
    } else {
        $reply .= "\n\n🟢 Within safe limits.";
    }
}

// --- Risk status ---
elseif (preg_match('/\b(risk|daily limit|weekly limit|can i trade|should i trade|trading limit|risk status|breach|limit)\b/', $msg)) {
    require_once '../includes/risk_engine.php';
    $m   = getRiskMetrics($userId);
    $bal = getCurrentBalance($userId);
    $reply  = "**Risk Status:**\n";
    $cs     = getActiveCurrency()['symbol'];
    $reply .= "• Balance: **{$cs}" . number_format($bal, 2) . "**\n";
    $reply .= "• Daily P/L: **" . plStr($m['daily_loss_used']) . "**\n";
    $reply .= "• Daily limit used: **{$m['daily_loss_pct_used']}%**";
    if ($m['breach_daily']) {
        $reply .= "\n\n🔴 **Daily limit breached. Stop trading for today.**";
    } elseif ($m['warning_daily']) {
        $reply .= "\n\n⚠️ Close to your daily limit — be very selective.";
    } else {
        $reply .= "\n\n🟢 Safe to trade.";
    }
}

// --- Brokerage / fees ---
elseif (preg_match('/\b(brokerage|fees?|commission|charges?|cost)\b/', $msg)) {
    $period = detectPeriod($msg);
    [$from, $to] = $period && $period !== 'all' ? periodDates($period) : [null, null];
    $total = getTotalBrokerage($userId, $from ?? '', $to ?? '');
    $lbl   = $period ? periodLabel($period) : 'all time';
    $cs    = getActiveCurrency()['symbol'];
    $reply = "Total brokerage/fees paid **$lbl**: **{$cs}" . number_format($total, 2) . "**.";
}

// --- Fallback ---
else {
    $reply = "I'm not sure I understand that. Try asking:\n• How did I do today?\n• What's my win rate?\n• Show my last 5 trades\n• What's my best symbol?\n• Am I in risk?\n\nType **help** to see everything I can answer.";
}

echo json_encode(['reply' => $reply]);

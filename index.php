<?php
require_once 'config/db.php';  // also loads risk_engine.php + auth.php
require_once 'includes/market_awareness.php';

// Show landing page for guests, dashboard for logged-in users
if (empty($_SESSION['user_id'])) {
    header('Location: landing.php');
    exit;
}
requireLogin('');

$db     = getDB();
$userId = getCurrentUserId();
$today  = date('Y-m-d');

// -- Risk metrics (single call — builds/updates all snapshots) --
$risk = getRiskMetrics($userId);
$showStopOutBanner = isset($_GET['stopped_out']);

// -- Today's trades --
$todayTrades = $db->prepare("SELECT * FROM trades WHERE user_id = ? AND DATE(trade_datetime) = ? ORDER BY trade_datetime DESC");
$todayTrades->execute([$userId, $today]);
$todayTradesData = $todayTrades->fetchAll();

$todayCount = count($todayTradesData);
$todayPL    = array_sum(array_map(fn($t) => $t['profit_loss'] - $t['brokerage'] + $t['swap'], $todayTradesData));
$losses     = array_filter(array_map(fn($t) => $t['profit_loss'] - $t['brokerage'] + $t['swap'], $todayTradesData), fn($v) => $v < 0);
$todayMaxDD = $losses ? abs(min($losses)) : 0;

// -- Legacy weekly drawdown for sidebar --
$balance  = $risk['current_equity'];
$weeklyDD = getWeeklyDrawdown($userId);

// -- 14-day chart --
$chartData = $db->prepare("
    SELECT DATE(trade_datetime) as date, SUM(profit_loss - brokerage + swap) as daily_pl
    FROM trades WHERE user_id = ? AND DATE(trade_datetime) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(trade_datetime) ORDER BY date ASC
");
$chartData->execute([$userId]);
$chartRows   = $chartData->fetchAll();
$chartLabels = array_map(fn($r) => date('d M', strtotime($r['date'])), $chartRows);
$chartPLs    = array_map(fn($r) => round($r['daily_pl'], 2), $chartRows);

// -- Recent 10 trades --
$recentTrades = $db->prepare("SELECT * FROM trades WHERE user_id = ? ORDER BY trade_datetime DESC LIMIT 10");
$recentTrades->execute([$userId]);
$recentTradesData = $recentTrades->fetchAll();

// -- Win/loss all time --
$winLoss = $db->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as wins,
           SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END) as losses
    FROM trades WHERE user_id = ?
");
$winLoss->execute([$userId]);
$wlData  = $winLoss->fetch();
$winRate = $wlData['total'] > 0 ? round($wlData['wins'] / $wlData['total'] * 100, 1) : 0;

// -- Week P/L --
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$weekPL    = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as wpl FROM trades WHERE user_id = ? AND DATE(trade_datetime) BETWEEN ? AND ?");
$weekPL->execute([$userId, $weekStart, $weekEnd]);
$weekPLVal = $weekPL->fetch()['wpl'];

// -- Current capital cycle breakdown --
$lastSOStmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? AND type='stop_out' ORDER BY created_at DESC LIMIT 1");
$lastSOStmt->execute([$userId]);
$lastSO = $lastSOStmt->fetch();

$cycleDeposits = 0.0;
$cycleWithdraw = 0.0;
$cyclePL       = 0.0;

if ($lastSO) {
    $soCreatedAt = $lastSO['created_at'];

    $cycleTxStmt = $db->prepare("SELECT type, amount FROM transactions WHERE user_id=? AND type IN ('deposit','withdraw') AND created_at > ?");
    $cycleTxStmt->execute([$userId, $soCreatedAt]);
    foreach ($cycleTxStmt->fetchAll() as $tx) {
        if ($tx['type'] === 'deposit')  $cycleDeposits += (float)$tx['amount'];
        if ($tx['type'] === 'withdraw') $cycleWithdraw += (float)$tx['amount'];
    }

    $cyclePLStmt = $db->prepare("SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as total FROM trades WHERE user_id=? AND trade_datetime > ?");
    $cyclePLStmt->execute([$userId, $soCreatedAt]);
    $cyclePL = (float)$cyclePLStmt->fetch()['total'];
}

$pageTitle = 'Dashboard';
$rootPath  = '';
$market    = getMarketAwareness();
include 'includes/header.php';
?>

<?php if ($risk['breach_weekly']): ?>
<div class="risk-alert risk-alert-breach mb-4">
    <i class="fas fa-ban fa-lg"></i>
    <div><strong>WEEKLY BREACH — STOP TRADING</strong><br>
    <small>Equity below weekly floor <?= formatUSD($risk['weekly_min_equity']) ?>.
    Loss: <?= formatUSD($risk['weekly_loss_used']) ?> of <?= formatUSD($risk['weekly_max_loss']) ?>. Resume Monday.</small></div>
</div>
<?php elseif ($risk['breach_daily']): ?>
<div class="risk-alert risk-alert-breach mb-4">
    <i class="fas fa-circle-stop fa-lg"></i>
    <div><strong>DAILY BREACH — HALT TRADING</strong><br>
    <small>Equity below dynamic floor <?= formatUSD($risk['dynamic_daily_min_equity']) ?>.
    Loss: <?= formatUSD($risk['daily_loss_used']) ?> of <?= formatUSD($risk['daily_max_loss']) ?>. Resume tomorrow.</small></div>
</div>
<?php elseif ($risk['warning_weekly']): ?>
<div class="risk-alert risk-alert-warning mb-4">
    <i class="fas fa-triangle-exclamation fa-lg"></i>
    <div><strong>Weekly Risk Warning — <?= $risk['weekly_loss_pct_used'] ?>% of limit used</strong><br>
    <small>Only <?= formatUSD($risk['weekly_loss_remaining']) ?> remaining. Trade cautiously.</small></div>
</div>
<?php elseif ($risk['warning_daily']): ?>
<div class="risk-alert risk-alert-warning mb-4">
    <i class="fas fa-triangle-exclamation fa-lg"></i>
    <div><strong>Daily Risk Warning — <?= $risk['daily_loss_pct_used'] ?>% of limit used</strong><br>
    <small>Only <?= formatUSD($risk['daily_loss_remaining']) ?> of daily risk remaining. Consider stopping.</small></div>
</div>
<?php endif; ?>

<?php if ($showStopOutBanner): ?>
<div class="risk-alert risk-alert-breach mb-4">
    <i class="fas fa-power-off fa-lg"></i>
    <div><strong>Account Stopped Out</strong><br>
    <small>Balance has been set to $0.00. Go to Fund Manager to add new capital and resume trading.</small></div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn-danger-custom" data-bs-toggle="modal" data-bs-target="#stopOutModal">
        <i class="fas fa-power-off"></i> Stop Out Account
    </button>
</div>

<?php
// ── MARKET AWARENESS DATA ──────────────────────────────────────────────────
$mDay  = $market['day'];
$mSess = $market['session'];
$mFomc = $market['fomc'];
$mNfp  = $market['nfp'];

// Hardcoded rgba maps — no color-mix(), guaranteed browser support
$maColors = [
    'profit' => ['txt'=>'#16a34a', 'bg'=>'rgba(22,163,74,.13)',   'border'=>'rgba(22,163,74,.28)',   'bar'=>'#16a34a'],
    'warn'   => ['txt'=>'#d97706', 'bg'=>'rgba(217,119,6,.13)',    'border'=>'rgba(217,119,6,.28)',    'bar'=>'#d97706'],
    'cyan'   => ['txt'=>'#0891b2', 'bg'=>'rgba(8,145,178,.13)',    'border'=>'rgba(8,145,178,.28)',    'bar'=>'#0891b2'],
    'blue'   => ['txt'=>'#2563eb', 'bg'=>'rgba(37,99,235,.13)',    'border'=>'rgba(37,99,235,.28)',    'bar'=>'#2563eb'],
    'muted'  => ['txt'=>'#94a3b8', 'bg'=>'rgba(148,163,184,.10)', 'border'=>'rgba(148,163,184,.2)',  'bar'=>'#94a3b8'],
    'loss'   => ['txt'=>'#dc2626', 'bg'=>'rgba(220,38,38,.13)',    'border'=>'rgba(220,38,38,.28)',    'bar'=>'#dc2626'],
    'purple' => ['txt'=>'#7c3aed', 'bg'=>'rgba(124,58,237,.13)',   'border'=>'rgba(124,58,237,.28)',   'bar'=>'#7c3aed'],
];
$dc  = $maColors[$mDay['color']]  ?? $maColors['muted'];
$sc  = $maColors[$mSess['color']] ?? $maColors['muted'];
$pc  = $maColors['purple'];
?>

<?php /* ── TOP ALERT BANNERS (reuse existing risk-alert styles) ── */ ?>
<?php if ($mNfp['is_today']): ?>
<div class="risk-alert risk-alert-breach mb-3">
    <i class="fas fa-bomb fa-lg"></i>
    <div><strong>🚨 NFP DAY — Non-Farm Payrolls at 6:00 PM IST</strong><br>
    <small>EXTREME volatility expected. Spreads will spike, stops may be hunted. Stay flat near 6 PM IST.</small></div>
</div>
<?php elseif ($mFomc['is_today']): ?>
<div class="risk-alert risk-alert-breach mb-3">
    <i class="fas fa-landmark fa-lg"></i>
    <div><strong>🔴 FOMC DAY — Federal Reserve Decision at 11:30 PM IST</strong><br>
    <small>Massive USD moves expected. All USD pairs at risk — spreads spike at release.</small></div>
</div>
<?php elseif ($mFomc['is_tomorrow']): ?>
<div class="risk-alert risk-alert-warning mb-3">
    <i class="fas fa-triangle-exclamation fa-lg"></i>
    <div><strong>⚡ FOMC Tomorrow (<?= $mFomc['next_date'] ?>) — Federal Reserve at 11:30 PM IST</strong><br>
    <small>Reduce position size today. Big USD move expected tomorrow night.</small></div>
</div>
<?php elseif ($mNfp['is_tomorrow']): ?>
<div class="risk-alert risk-alert-warning mb-3">
    <i class="fas fa-triangle-exclamation fa-lg"></i>
    <div><strong>⚡ NFP Tomorrow (<?= $mNfp['next_date'] ?>) — Non-Farm Payrolls at 6:00 PM IST</strong><br>
    <small>Cut risk on USD pairs today. Close all trades before 5:45 PM IST tomorrow.</small></div>
</div>
<?php endif; ?>

<?php /* ── MARKET AWARENESS SECTION ── */ ?>
<div class="risk-section-header">
    <i class="fas fa-globe"></i> Market Awareness
    <span style="font-size:10px;font-weight:400;color:var(--text-muted);text-transform:none;letter-spacing:0;margin-left:6px;"><?= $mDay['date_str'] ?> &nbsp;·&nbsp; <?= $mDay['time_str'] ?></span>
</div>

<div class="row g-3 mb-4">

    <?php /* ══ CARD 1: TODAY'S DAY ══ */ ?>
    <div class="col-12 col-md-4">
        <div class="ma-card" style="border-top-color:<?= $dc['bar'] ?>;">

            <!-- coloured hero banner -->
            <div class="ma-hero" style="background:<?= $dc['bg'] ?>;border-bottom:1px solid <?= $dc['border'] ?>;">
                <div class="ma-hero-icon" style="background:<?= $dc['bg'] ?>;border:2px solid <?= $dc['border'] ?>;">
                    <i class="fas <?= $mDay['icon'] ?>" style="color:<?= $dc['txt'] ?>;font-size:22px;"></i>
                </div>
                <div class="ma-hero-body">
                    <div class="ma-hero-label">Today's Day</div>
                    <div class="ma-hero-title" style="color:<?= $dc['txt'] ?>;"><?= $mDay['name'] ?></div>
                </div>
                <div class="ma-pill" style="background:<?= $dc['bg'] ?>;color:<?= $dc['txt'] ?>;border:1px solid <?= $dc['border'] ?>;">
                    <?= $mDay['emoji'] ?> <?= $mDay['rating'] ?>
                </div>
            </div>

            <div class="ma-body">
                <!-- star dots -->
                <div class="ma-stars-row">
                    <span class="ma-stars-label">Trading Quality</span>
                    <div class="ma-dots">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <div class="ma-dot <?= $s <= $mDay['stars'] ? 'filled' : 'empty' ?>"
                             style="<?= $s <= $mDay['stars'] ? "background:{$dc['txt']};" : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <span class="ma-stars-score" style="color:<?= $dc['txt'] ?>;"><?= $mDay['stars'] ?>/5</span>
                </div>

                <p class="ma-tip-text"><?= $mDay['tip'] ?></p>

                <div class="ma-advice-box" style="background:<?= $dc['bg'] ?>;border-left:3px solid <?= $dc['txt'] ?>;">
                    <i class="fas fa-lightbulb" style="color:<?= $dc['txt'] ?>;margin-right:6px;"></i><?= $mDay['advice'] ?>
                </div>
            </div>
        </div>
    </div>

    <?php /* ══ CARD 2: SESSION STATUS ══ */ ?>
    <div class="col-12 col-md-4">
        <div class="ma-card" style="border-top-color:<?= $sc['bar'] ?>;">

            <!-- hero banner -->
            <div class="ma-hero" style="background:<?= $sc['bg'] ?>;border-bottom:1px solid <?= $sc['border'] ?>;">
                <div class="ma-hero-icon" style="background:<?= $sc['bg'] ?>;border:2px solid <?= $sc['border'] ?>;">
                    <i class="fas <?= $mSess['icon'] ?>" style="color:<?= $sc['txt'] ?>;font-size:22px;"></i>
                </div>
                <div class="ma-hero-body">
                    <div class="ma-hero-label">Session Now</div>
                    <div class="ma-hero-title" style="color:<?= $sc['txt'] ?>;"><?= $mSess['name'] ?></div>
                </div>
                <?php if ($mSess['active']): ?>
                <div class="ma-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['txt'] ?>;border:1px solid <?= $sc['border'] ?>;">
                    <span class="ma-live-dot" style="background:<?= $sc['txt'] ?>;"></span>LIVE
                </div>
                <?php else: ?>
                <div class="ma-pill" style="background:var(--bg-elevated);color:var(--text-muted);border:1px solid var(--border);">CLOSED</div>
                <?php endif; ?>
            </div>

            <div class="ma-body">
                <!-- time block -->
                <div class="ma-time-block" style="background:<?= $sc['bg'] ?>;border:1px solid <?= $sc['border'] ?>;">
                    <i class="fas fa-clock" style="color:<?= $sc['txt'] ?>;font-size:13px;"></i>
                    <span class="ma-time-text" style="color:<?= $sc['txt'] ?>;"><?= $mSess['time_ist'] ?></span>
                </div>

                <p class="ma-tip-text"><?= $mSess['tip'] ?></p>

                <?php if ($mSess['best']): ?>
                <div class="ma-advice-box" style="background:rgba(22,163,74,.1);border-left:3px solid #16a34a;color:#16a34a;font-weight:600;">
                    <i class="fas fa-fire" style="margin-right:6px;"></i>Prime time — highest volume of the day!
                </div>
                <?php elseif (!$mSess['active']): ?>
                <div class="ma-advice-box" style="background:<?= $sc['bg'] ?>;border-left:3px solid <?= $sc['txt'] ?>;">
                    <i class="fas fa-lightbulb" style="color:<?= $sc['txt'] ?>;margin-right:6px;"></i>London opens 12:30 PM · Best: 6:30–10:30 PM IST
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php /* ══ CARD 3: UPCOMING EVENTS ══ */ ?>
    <div class="col-12 col-md-4">
        <div class="ma-card" style="border-top-color:<?= $pc['bar'] ?>;">

            <div class="ma-hero" style="background:<?= $pc['bg'] ?>;border-bottom:1px solid <?= $pc['border'] ?>;">
                <div class="ma-hero-icon" style="background:<?= $pc['bg'] ?>;border:2px solid <?= $pc['border'] ?>;">
                    <i class="fas fa-calendar-alt" style="color:<?= $pc['txt'] ?>;font-size:22px;"></i>
                </div>
                <div class="ma-hero-body">
                    <div class="ma-hero-label">Upcoming Events</div>
                    <div class="ma-hero-title" style="color:<?= $pc['txt'] ?>;">Market Calendar</div>
                </div>
            </div>

            <div class="ma-body">

                <?php
                // FOMC
                $fC = $mFomc['is_today'] ? $maColors['loss'] : ($mFomc['is_this_week'] ? $maColors['warn'] : $maColors['muted']);
                ?>
                <div class="ma-event">
                    <div class="ma-event-icon-box" style="background:<?= $fC['bg'] ?>;border:1px solid <?= $fC['border'] ?>;">
                        <i class="fas fa-landmark" style="color:<?= $fC['txt'] ?>;font-size:13px;"></i>
                    </div>
                    <div class="ma-event-info">
                        <div class="ma-event-name">
                            FOMC — Fed Rate Decision
                            <?php if ($mFomc['is_today']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['loss']['bg'] ?>;color:<?= $maColors['loss']['txt'] ?>;">TODAY</span>
                            <?php elseif ($mFomc['is_tomorrow']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['warn']['bg'] ?>;color:<?= $maColors['warn']['txt'] ?>;">TOMORROW</span>
                            <?php elseif ($mFomc['is_this_week']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['warn']['bg'] ?>;color:<?= $maColors['warn']['txt'] ?>;">THIS WEEK</span>
                            <?php endif; ?>
                        </div>
                        <div class="ma-event-meta">
                            <?= $mFomc['next_date'] ?>
                            <?php if ($mFomc['is_today']): ?>
                                &nbsp;·&nbsp; 11:30 PM IST
                            <?php else: ?>
                                &nbsp;·&nbsp; <strong style="color:<?= $fC['txt'] ?>;"><?= $mFomc['days_away'] ?> days</strong> away
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php
                // NFP
                $nC = $mNfp['is_today'] ? $maColors['loss'] : ($mNfp['is_this_week'] ? $maColors['warn'] : $maColors['muted']);
                ?>
                <div class="ma-event">
                    <div class="ma-event-icon-box" style="background:<?= $nC['bg'] ?>;border:1px solid <?= $nC['border'] ?>;">
                        <i class="fas fa-briefcase" style="color:<?= $nC['txt'] ?>;font-size:13px;"></i>
                    </div>
                    <div class="ma-event-info">
                        <div class="ma-event-name">
                            NFP — Non-Farm Payrolls
                            <?php if ($mNfp['is_today']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['loss']['bg'] ?>;color:<?= $maColors['loss']['txt'] ?>;">TODAY</span>
                            <?php elseif ($mNfp['is_tomorrow']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['warn']['bg'] ?>;color:<?= $maColors['warn']['txt'] ?>;">TOMORROW</span>
                            <?php elseif ($mNfp['is_this_week']): ?>
                                <span class="ma-tag" style="background:<?= $maColors['warn']['bg'] ?>;color:<?= $maColors['warn']['txt'] ?>;">THIS WEEK</span>
                            <?php endif; ?>
                        </div>
                        <div class="ma-event-meta">
                            <?= $mNfp['next_date'] ?>
                            <?php if ($mNfp['is_today']): ?>
                                &nbsp;·&nbsp; 6:00 PM IST
                            <?php else: ?>
                                &nbsp;·&nbsp; <strong style="color:<?= $nC['txt'] ?>;"><?= $mNfp['days_away'] ?> days</strong> away
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($mDay['dow'] === 3 || $mDay['dow'] === 4):
                    $jC = ($mDay['dow'] === 4) ? $maColors['loss'] : $maColors['blue'];
                ?>
                <div class="ma-event" style="border-bottom:none;padding-bottom:0;">
                    <div class="ma-event-icon-box" style="background:<?= $jC['bg'] ?>;border:1px solid <?= $jC['border'] ?>;">
                        <i class="fas fa-users" style="color:<?= $jC['txt'] ?>;font-size:13px;"></i>
                    </div>
                    <div class="ma-event-info">
                        <div class="ma-event-name">
                            US Jobless Claims
                            <?php if ($mDay['dow'] === 4): ?>
                                <span class="ma-tag" style="background:<?= $maColors['loss']['bg'] ?>;color:<?= $maColors['loss']['txt'] ?>;">TODAY</span>
                            <?php else: ?>
                                <span class="ma-tag" style="background:<?= $maColors['blue']['bg'] ?>;color:<?= $maColors['blue']['txt'] ?>;">TOMORROW</span>
                            <?php endif; ?>
                        </div>
                        <div class="ma-event-meta">Every Thursday &nbsp;·&nbsp; 6:00 PM IST</div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div><!-- /row market awareness -->

<!-- SECTION 1: ACCOUNT OVERVIEW -->
<div class="risk-section-header"><i class="fas fa-wallet"></i> Account Overview</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value"><?= formatUSD($risk['current_equity']) ?></div>
            <div class="stat-label">Current Balance / Equity</div>
            <div class="stat-sub"><?= date('H:i:s') ?> live</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card cyan">
            <div class="stat-icon cyan"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-value"><?= formatUSD($risk['daily_initial_balance']) ?></div>
            <div class="stat-label">Daily Initial Balance</div>
            <div class="stat-sub">Reset at 23:59 daily</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $todayPL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $todayPL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value <?= $todayPL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($todayPL) ?></div>
            <div class="stat-label">Today's P&amp;L</div>
            <div class="stat-sub"><?= $todayCount ?> trade<?= $todayCount !== 1 ? 's' : '' ?> today</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card purple">
            <div class="stat-icon purple"><i class="fas fa-calendar-week"></i></div>
            <div class="stat-value"><?= formatUSD($risk['weekly_initial_balance']) ?></div>
            <div class="stat-label">Weekly Initial Balance</div>
            <div class="stat-sub">Reset Monday 00:00</div>
        </div>
    </div>
</div>

<?php if ($lastSO): ?>
<!-- Current Capital Cycle -->
<div class="risk-section-header" style="margin-top:8px">
    <i class="fas fa-rotate"></i> Current Capital Cycle
    <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:8px">
        since stop out on <?= date('d M Y, H:i', strtotime($lastSO['created_at'])) ?>
    </span>
    <a href="pages/cycles.php" style="font-size:11px;font-weight:500;color:var(--accent);margin-left:auto;text-decoration:none">All cycles →</a>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-arrow-down-to-line"></i></div>
            <div class="stat-value"><?= formatUSD($cycleDeposits) ?></div>
            <div class="stat-label">Deposited This Cycle</div>
            <div class="stat-sub">New capital added</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card loss">
            <div class="stat-icon loss"><i class="fas fa-arrow-up-from-line"></i></div>
            <div class="stat-value negative"><?= formatUSD($cycleWithdraw) ?></div>
            <div class="stat-label">Withdrawn This Cycle</div>
            <div class="stat-sub">Funds taken out</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $cyclePL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $cyclePL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value <?= $cyclePL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($cyclePL) ?></div>
            <div class="stat-label">P&amp;L This Cycle</div>
            <div class="stat-sub">Trades since restart</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $balance >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $balance >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-wallet"></i></div>
            <div class="stat-value <?= $balance >= 0 ? 'positive' : 'negative' ?>"><?= formatUSD($balance) ?></div>
            <div class="stat-label">Net Capital Now</div>
            <div class="stat-sub"><?= formatUSD($cycleDeposits) ?> − <?= formatUSD($cycleWithdraw) ?> + <?= formatPL($cyclePL) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4" style="display:none"><!-- spacer closed above -->
</div>

<!-- SECTION 2: RISK MONITORING -->
<div class="risk-section-header"><i class="fas fa-shield-halved"></i> Risk Monitoring</div>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel h-100">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-calendar-day"></i> Daily Risk Meter
                    <?php if ($risk['breach_daily']): ?><span class="risk-badge breach">BREACHED</span>
                    <?php elseif ($risk['warning_daily']): ?><span class="risk-badge warn">WARNING</span>
                    <?php else: ?><span class="risk-badge safe">SAFE</span><?php endif; ?>
                </div>
                <span class="panel-link"><?= DAILY_LOSS_LIMIT_PCT ?>% daily limit</span>
            </div>
            <div class="panel-body">
                <div class="risk-big-stat">
                    <div>
                        <div class="risk-big-label">Daily Loss Remaining</div>
                        <div class="risk-big-value <?= $risk['breach_daily'] ? 'text-loss' : '' ?>"><?= formatUSD($risk['daily_loss_remaining']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="risk-big-label">Used of limit</div>
                        <div class="risk-big-value risk-pct-value" style="color:<?= $risk['breach_daily'] ? 'var(--loss)' : ($risk['warning_daily'] ? 'var(--warning)' : 'var(--profit)') ?>"><?= $risk['daily_loss_pct_used'] ?>%</div>
                    </div>
                </div>
                <div class="risk-bar-wrap mt-3">
                    <div class="risk-bar-track">
                        <div class="risk-bar-fill <?= $risk['breach_daily'] ? 'fill-breach' : ($risk['warning_daily'] ? 'fill-warn' : 'fill-safe') ?>" style="width:<?= $risk['daily_loss_pct_used'] ?>%"></div>
                    </div>
                    <div class="risk-bar-labels"><span>0%</span><span style="color:var(--warning);font-size:10px">90% warn</span><span><?= DAILY_LOSS_LIMIT_PCT ?>% breach</span></div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Daily Max Loss Allowed</span><span class="metric-value text-loss">-<?= formatUSD($risk['daily_max_loss']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Loss Used Today</span><span class="metric-value <?= $risk['daily_loss_used'] > 0 ? 'text-loss' : '' ?>"><?= $risk['daily_loss_used'] > 0 ? '-'.formatUSD($risk['daily_loss_used']) : '$0.00' ?></span></div>
                <div class="metric-row">
                    <span class="metric-label">Distance to Daily Breach</span>
                    <span class="metric-value <?= $risk['daily_distance_usd'] < 0 ? 'text-loss' : 'text-profit' ?>">
                        <?= $risk['daily_distance_usd'] >= 0 ? formatUSD($risk['daily_distance_usd']).' ('.abs($risk['daily_distance_pct']).'%)' : '-'.formatUSD(abs($risk['daily_distance_usd'])).' BREACHED' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="panel h-100">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-calendar-week"></i> Weekly Risk Meter
                    <?php if ($risk['breach_weekly']): ?><span class="risk-badge breach">BREACHED</span>
                    <?php elseif ($risk['warning_weekly']): ?><span class="risk-badge warn">WARNING</span>
                    <?php else: ?><span class="risk-badge safe">SAFE</span><?php endif; ?>
                </div>
                <span class="panel-link"><?= WEEKLY_LOSS_LIMIT_PCT ?>% weekly limit</span>
            </div>
            <div class="panel-body">
                <div class="risk-big-stat">
                    <div>
                        <div class="risk-big-label">Weekly Loss Remaining</div>
                        <div class="risk-big-value <?= $risk['breach_weekly'] ? 'text-loss' : '' ?>"><?= formatUSD($risk['weekly_loss_remaining']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="risk-big-label">Used of limit</div>
                        <div class="risk-big-value risk-pct-value" style="color:<?= $risk['breach_weekly'] ? 'var(--loss)' : ($risk['warning_weekly'] ? 'var(--warning)' : 'var(--profit)') ?>"><?= $risk['weekly_loss_pct_used'] ?>%</div>
                    </div>
                </div>
                <div class="risk-bar-wrap mt-3">
                    <div class="risk-bar-track">
                        <div class="risk-bar-fill <?= $risk['breach_weekly'] ? 'fill-breach' : ($risk['warning_weekly'] ? 'fill-warn' : 'fill-safe') ?>" style="width:<?= $risk['weekly_loss_pct_used'] ?>%"></div>
                    </div>
                    <div class="risk-bar-labels"><span>0%</span><span style="color:var(--warning);font-size:10px">90% warn</span><span><?= WEEKLY_LOSS_LIMIT_PCT ?>% breach</span></div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Weekly Max Loss Allowed</span><span class="metric-value text-loss">-<?= formatUSD($risk['weekly_max_loss']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Loss Used This Week</span><span class="metric-value <?= $risk['weekly_loss_used'] > 0 ? 'text-loss' : '' ?>"><?= $risk['weekly_loss_used'] > 0 ? '-'.formatUSD($risk['weekly_loss_used']) : '$0.00' ?></span></div>
                <div class="metric-row">
                    <span class="metric-label">Distance to Weekly Breach</span>
                    <span class="metric-value <?= $risk['weekly_distance_usd'] < 0 ? 'text-loss' : 'text-profit' ?>">
                        <?= $risk['weekly_distance_usd'] >= 0 ? formatUSD($risk['weekly_distance_usd']).' ('.abs($risk['weekly_distance_pct']).'%)' : '-'.formatUSD(abs($risk['weekly_distance_usd'])).' BREACHED' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 3: PROFIT LOCK SYSTEM -->
<div class="risk-section-header">
    <i class="fas fa-lock"></i> Profit Lock System
    <span class="lock-mode-tag mode-<?= $risk['lock_mode'] ?>">Mode <?= $risk['lock_mode'] ?>: <?= $risk['lock_mode_label'] ?></span>
</div>
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-arrow-trend-up"></i> Peak Equity Tracker</div></div>
            <div class="panel-body">
                <div class="text-center mb-3">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Daily Highest Equity</div>
                    <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--accent-cyan)"><?= formatUSD($risk['daily_highest_equity']) ?></div>
                    <?php $peakGain = $risk['daily_highest_equity'] - $risk['daily_initial_balance']; ?>
                    <div style="font-size:12px;color:<?= $peakGain >= 0 ? 'var(--profit)' : 'var(--loss)' ?>"><?= formatPL($peakGain) ?> vs open</div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Locked Equity Floor</span><span class="metric-value" style="color:var(--accent)"><?= formatUSD($risk['locked_equity_level']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Static Floor (no lock)</span><span class="metric-value" style="color:var(--text-muted)"><?= formatUSD($risk['static_daily_min_equity']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Profit Secured by Lock</span><span class="metric-value text-profit">+<?= formatUSD($risk['profit_secured']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Profit Above Daily Open</span><span class="metric-value text-profit">+<?= formatUSD($risk['profit_above_initial']) ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-gauge-high"></i> Equity vs Floors</div></div>
            <div class="panel-body d-flex flex-column align-items-center">
                <?php
                $rangeBottom = min($risk['weekly_min_equity'], $risk['static_daily_min_equity']) * 0.999;
                $rangeTop    = max($risk['daily_highest_equity'], $risk['current_equity']) * 1.001;
                $range       = max($rangeTop - $rangeBottom, 1);
                function pctBar($val, $bottom, $range) { return max(0, min(100, (($val - $bottom) / $range) * 100)); }
                $equityPct       = pctBar($risk['current_equity'],           $rangeBottom, $range);
                $dynamicFloorPct = pctBar($risk['dynamic_daily_min_equity'], $rangeBottom, $range);
                $staticFloorPct  = pctBar($risk['static_daily_min_equity'],  $rangeBottom, $range);
                $weeklyFloorPct  = pctBar($risk['weekly_min_equity'],        $rangeBottom, $range);
                $peakPct         = pctBar($risk['daily_highest_equity'],     $rangeBottom, $range);
                ?>
                <div class="equity-gauge-wrap w-100">
                    <div class="equity-gauge-bar">
                        <div class="eq-fill <?= $risk['breach_daily'] ? 'eq-fill-breach' : 'eq-fill-ok' ?>" style="height:<?= $equityPct ?>%;bottom:0"></div>
                        <div class="eq-marker eq-peak" style="bottom:<?= $peakPct ?>%"><span class="eq-marker-label eq-label-left">Peak <?= formatUSD($risk['daily_highest_equity']) ?></span></div>
                        <div class="eq-marker eq-dynamic-floor" style="bottom:<?= $dynamicFloorPct ?>%"><span class="eq-marker-label eq-label-right">Dyn <?= formatUSD($risk['dynamic_daily_min_equity']) ?></span></div>
                        <div class="eq-marker eq-static-floor" style="bottom:<?= $staticFloorPct ?>%"><span class="eq-marker-label eq-label-left">Static <?= formatUSD($risk['static_daily_min_equity']) ?></span></div>
                        <div class="eq-marker eq-weekly-floor" style="bottom:<?= $weeklyFloorPct ?>%"><span class="eq-marker-label eq-label-right">Weekly <?= formatUSD($risk['weekly_min_equity']) ?></span></div>
                        <div class="eq-equity-label" style="bottom:calc(<?= $equityPct ?>% + 4px)"><?= formatUSD($risk['current_equity']) ?></div>
                    </div>
                    <div class="eq-legend mt-3">
                        <div class="eq-leg-item"><span class="eq-dot dot-peak"></span> Daily Peak</div>
                        <div class="eq-leg-item"><span class="eq-dot dot-dynamic"></span> Dynamic Floor</div>
                        <div class="eq-leg-item"><span class="eq-dot dot-static"></span> Static Floor</div>
                        <div class="eq-leg-item"><span class="eq-dot dot-weekly"></span> Weekly Floor</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-sliders"></i> Lock Mode Config</div></div>
            <div class="panel-body">
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">Active mode set in <code>config/db.php → PROFIT_LOCK_MODE</code>.</p>
                <div class="lock-mode-card <?= $risk['lock_mode'] === 1 ? 'mode-active' : '' ?>">
                    <div class="lmc-header"><span class="lmc-badge mode-1-badge">Mode 1</span><strong>Aggressive</strong><?= $risk['lock_mode'] === 1 ? '<span class="lmc-current">ACTIVE</span>' : '' ?></div>
                    <p>Floor trails tightly off peak. Max profit protection, less room to breathe.</p>
                    <code class="lmc-formula">floor = peak × (1 − <?= DAILY_LOSS_LIMIT_PCT ?>%)</code>
                </div>
                <div class="lock-mode-card <?= $risk['lock_mode'] === 2 ? 'mode-active' : '' ?>">
                    <div class="lmc-header"><span class="lmc-badge mode-2-badge">Mode 2</span><strong>Balanced</strong><?= $risk['lock_mode'] === 2 ? '<span class="lmc-current">ACTIVE</span>' : '' ?></div>
                    <p>Returns 50% of profit as cushion. Balanced protection and room.</p>
                    <code class="lmc-formula">floor = (peak − profit×50%) × (1 − <?= DAILY_LOSS_LIMIT_PCT ?>%)</code>
                </div>
                <div class="lock-mode-card <?= $risk['lock_mode'] === 3 ? 'mode-active' : '' ?>">
                    <div class="lmc-header"><span class="lmc-badge mode-3-badge">Mode 3</span><strong>Conservative</strong><?= $risk['lock_mode'] === 3 ? '<span class="lmc-current">ACTIVE</span>' : '' ?></div>
                    <p>Fixed floor from daily open. No profit lock benefit. Maximum room.</p>
                    <code class="lmc-formula">floor = daily_open − daily_open×<?= DAILY_LOSS_LIMIT_PCT ?>%</code>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 4: TRADING ACTIVITY (original dashboard) -->
<div class="risk-section-header"><i class="fas fa-chart-bar"></i> Trading Activity</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-arrows-left-right"></i></div>
            <div class="stat-value"><?= $todayCount ?></div>
            <div class="stat-label">Today's Trades</div>
            <div class="stat-sub"><?= date('l, d M') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $todayPL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $todayPL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-dollar-sign"></i></div>
            <div class="stat-value <?= $todayPL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($todayPL) ?></div>
            <div class="stat-label">Today's P&amp;L</div>
            <div class="stat-sub">Realized</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card loss">
            <div class="stat-icon warn"><i class="fas fa-arrow-trend-down"></i></div>
            <div class="stat-value negative">-<?= formatUSD($todayMaxDD) ?></div>
            <div class="stat-label">Today Max Drawdown</div>
            <div class="stat-sub">Single worst loss</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card cyan">
            <div class="stat-icon cyan"><i class="fas fa-shield-halved"></i></div>
            <div class="stat-value <?= $risk['daily_loss_pct_used'] >= 90 ? 'negative' : '' ?>"><?= round(100 - $risk['daily_loss_pct_used'], 1) ?>%</div>
            <div class="stat-label">Daily Risk Remaining</div>
            <div class="stat-sub">Limit: <?= DAILY_LOSS_LIMIT_PCT ?>% daily</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-wallet"></i> Portfolio Overview</div></div>
            <div class="panel-body">
                <div class="text-center mb-3">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Total Balance</div>
                    <div style="font-family:var(--font-mono);font-size:30px;font-weight:700;color:var(--profit)"><?= formatUSD($balance) ?></div>
                </div>
                <hr class="divider">
                <div class="metric-row"><span class="metric-label">Week P&amp;L</span><span class="metric-value <?= $weekPLVal >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($weekPLVal) ?></span></div>
                <div class="metric-row"><span class="metric-label">Win Rate</span><span class="metric-value" style="color:var(--accent)"><?= $winRate ?>%</span></div>
                <div class="metric-row"><span class="metric-label">Total Trades</span><span class="metric-value"><?= $wlData['total'] ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-triangle-exclamation"></i> Risk Summary</div></div>
            <div class="panel-body">
                <div class="text-center mb-4">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Daily Risk Used</div>
                    <?php $ddColor = $risk['breach_daily'] ? '#ef4444' : ($risk['warning_daily'] ? '#f59e0b' : '#22c55e'); ?>
                    <div style="font-family:var(--font-mono);font-size:38px;font-weight:700;color:<?= $ddColor ?>"><?= $risk['daily_loss_pct_used'] ?>%</div>
                    <div style="font-size:12px;color:var(--text-muted)">of <?= DAILY_LOSS_LIMIT_PCT ?>% daily limit</div>
                </div>
                <div class="risk-meter">
                    <div class="risk-meter-label">
                        <span style="font-size:11px;color:var(--text-muted)">0%</span>
                        <span style="font-size:12px;font-weight:700;color:<?= $ddColor ?>"><?= $risk['daily_loss_pct_used'] ?>% used</span>
                        <span style="font-size:11px;color:var(--text-muted)"><?= DAILY_LOSS_LIMIT_PCT ?>%</span>
                    </div>
                    <div class="risk-meter-bar">
                        <div class="risk-meter-fill" style="width:<?= $risk['daily_loss_pct_used'] ?>%;background:<?= $ddColor ?>"></div>
                    </div>
                    <div class="mt-2 d-flex justify-content-between">
                        <small style="color:var(--profit);font-size:10px">Safe zone</small>
                        <small style="color:var(--loss);font-size:10px">Halt trading</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-chart-pie"></i> Win / Loss Split</div></div>
            <div class="panel-body">
                <div class="chart-container" style="height:190px"><canvas id="winLossChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-bar"></i> 14-Day P&amp;L</div>
                <a href="pages/reports.php" class="panel-link">Full Report →</a>
            </div>
            <div class="panel-body"><div class="chart-container" style="height:220px"><canvas id="plChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent Trades</div>
                <a href="pages/journal.php" class="panel-link">View All →</a>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>Date &amp; Time</th><th>Symbol</th><th>P&amp;L</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTradesData as $trade): ?>
                        <tr>
                            <td style="color:var(--text-muted);font-size:12px">
                                <?= date('d M', strtotime($trade['trade_datetime'])) ?>
                                <span style="color:var(--text-muted);font-size:10px;display:block"><?= date('H:i', strtotime($trade['trade_datetime'])) ?></span>
                            </td>
                            <td><span class="symbol-badge"><?= htmlspecialchars($trade['symbol']) ?></span></td>
                            <td class="<?= $trade['profit_loss'] >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($trade['profit_loss']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentTradesData)): ?>
                        <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:30px">No trades yet — add your first trade!</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div style="position:fixed;bottom:80px;right:24px;z-index:800">
    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#tradeModal" style="border-radius:50px;padding:12px 20px;box-shadow:var(--shadow-lg)">
        <i class="fas fa-plus"></i> Add Trade
    </button>
</div>

<?php include 'includes/trade_modal.php'; ?>

<!-- Stop Out Confirmation Modal -->
<div class="modal fade" id="stopOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom-color:var(--loss)">
                <div class="modal-title">
                    <i class="fas fa-triangle-exclamation" style="color:var(--loss)"></i>
                    Confirm Stop Out
                </div>
                <button type="button" class="btn-icon" data-bs-dismiss="modal"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="POST" action="pages/funds.php">
                <div class="modal-body">
                    <div class="risk-alert risk-alert-breach mb-3" style="border-radius:var(--radius-sm)">
                        <i class="fas fa-ban"></i>
                        <div><strong>This will set your balance to $0.00 immediately.</strong><br>
                        <small>Current balance: <strong><?= formatUSD($risk['current_equity']) ?></strong>. All trades and history are preserved.</small></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label">Stop Out Date</label>
                            <input type="date" class="form-control" name="stop_out_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Time</label>
                            <input type="time" class="form-control" name="stop_out_time" value="<?= date('H:i') ?>" required>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Reason <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
                        <input type="text" class="form-control" name="stop_out_note" placeholder="e.g. Margin call, Risk limit hit">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <input type="hidden" name="action" value="stop_out">
                    <button type="submit" class="btn-danger-custom">
                        <i class="fas fa-power-off"></i> Confirm Stop Out
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
createDoughnutChart('winLossChart', <?= (int)$wlData['wins'] ?>, <?= (int)$wlData['losses'] ?>);
createLineChart('plChart', <?= json_encode($chartLabels) ?>, <?= json_encode($chartPLs) ?>, 'Daily P&L');
</script>

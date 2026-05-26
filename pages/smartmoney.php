<?php
require_once '../config/db.php';
requireLogin();
require_once '../includes/market_awareness.php';

$market = getMarketAwareness();
$mDay   = $market['day'];
$mSess  = $market['session'];
$mFomc  = $market['fomc'];
$mNfp   = $market['nfp'];

// Hardcoded color map (no color-mix, full browser support)
$maColors = [
    'profit' => ['txt'=>'#16a34a','bg'=>'rgba(22,163,74,.13)',  'border'=>'rgba(22,163,74,.28)'],
    'warn'   => ['txt'=>'#d97706','bg'=>'rgba(217,119,6,.13)',   'border'=>'rgba(217,119,6,.28)'],
    'cyan'   => ['txt'=>'#0891b2','bg'=>'rgba(8,145,178,.13)',   'border'=>'rgba(8,145,178,.28)'],
    'blue'   => ['txt'=>'#2563eb','bg'=>'rgba(37,99,235,.13)',   'border'=>'rgba(37,99,235,.28)'],
    'muted'  => ['txt'=>'#94a3b8','bg'=>'rgba(148,163,184,.10)','border'=>'rgba(148,163,184,.2)'],
    'loss'   => ['txt'=>'#dc2626','bg'=>'rgba(220,38,38,.13)',   'border'=>'rgba(220,38,38,.28)'],
    'purple' => ['txt'=>'#7c3aed','bg'=>'rgba(124,58,237,.13)',  'border'=>'rgba(124,58,237,.28)'],
];
$dc = $maColors[$mDay['color']]  ?? $maColors['muted'];
$sc = $maColors[$mSess['color']] ?? $maColors['muted'];

$pageTitle = 'Smart Money Guide';
$rootPath  = '../';
include '../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     SMART MONEY GUIDE PAGE
     ═══════════════════════════════════════════════════════════ -->

<!-- TODAY SNAPSHOT BANNER -->
<div class="sm-snapshot">
    <div class="sm-snap-item" style="border-left:3px solid <?= $dc['txt'] ?>;">
        <div class="sm-snap-icon" style="background:<?= $dc['bg'] ?>;color:<?= $dc['txt'] ?>;"><i class="fas <?= $mDay['icon'] ?>"></i></div>
        <div>
            <div class="sm-snap-label">Today</div>
            <div class="sm-snap-val" style="color:<?= $dc['txt'] ?>;"><?= $mDay['name'] ?> — <?= $mDay['rating'] ?></div>
            <div class="sm-snap-sub"><?= $mDay['tip'] ?></div>
        </div>
    </div>
    <div class="sm-snap-item" style="border-left:3px solid <?= $sc['txt'] ?>;">
        <div class="sm-snap-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['txt'] ?>;"><i class="fas <?= $mSess['icon'] ?>"></i></div>
        <div>
            <div class="sm-snap-label">Session Now</div>
            <div class="sm-snap-val" style="color:<?= $sc['txt'] ?>;"><?= $mSess['name'] ?> <?= $mSess['active'] ? '<span class="sm-live-badge">LIVE</span>' : '' ?></div>
            <div class="sm-snap-sub"><?= $mSess['time_ist'] ?></div>
        </div>
    </div>
    <div class="sm-snap-item" style="border-left:3px solid #7c3aed;">
        <div class="sm-snap-icon" style="background:rgba(124,58,237,.13);color:#7c3aed;"><i class="fas fa-calendar-alt"></i></div>
        <div>
            <div class="sm-snap-label">Next Big Events</div>
            <div class="sm-snap-val" style="color:#7c3aed;">FOMC <span style="font-size:11px;color:var(--text-muted);"><?= $mFomc['days_away'] ?>d</span> &nbsp;·&nbsp; NFP <span style="font-size:11px;color:var(--text-muted);"><?= $mNfp['days_away'] ?>d</span></div>
            <div class="sm-snap-sub"><?= $mFomc['next_date'] ?> &nbsp;·&nbsp; <?= $mNfp['next_date'] ?></div>
        </div>
    </div>
    <div class="sm-snap-item" style="border-left:3px solid var(--accent);">
        <div class="sm-snap-icon" style="background:rgba(37,99,235,.13);color:var(--accent);"><i class="fas fa-star-of-life"></i></div>
        <div>
            <div class="sm-snap-label">Best Time Today</div>
            <div class="sm-snap-val" style="color:var(--accent);">6:30 – 10:30 PM IST</div>
            <div class="sm-snap-sub">London–NY Overlap 🔥 Most volume</div>
        </div>
    </div>
</div>

<!-- ── SECTION: WHO ARE BIG PLAYERS ─────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-building-columns"></i> Who Are Big Players?</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-landmark"></i></div>
            <div class="stat-value">95%</div>
            <div class="stat-label">Banks Control</div>
            <div class="stat-sub">of total forex volume daily</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card cyan">
            <div class="stat-icon cyan"><i class="fas fa-globe-europe"></i></div>
            <div class="stat-value">$6.6T</div>
            <div class="stat-label">Daily Forex Volume</div>
            <div class="stat-sub">Retail = only $330B of this</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-top:3px solid var(--accent-purple);">
            <div class="stat-icon purple"><i class="fas fa-users-gear"></i></div>
            <div class="stat-value">5%</div>
            <div class="stat-label">Retail Traders</div>
            <div class="stat-sub">We are the minority — follow the 95%!</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-top:3px solid var(--warning);">
            <div class="stat-icon warn"><i class="fas fa-clock"></i></div>
            <div class="stat-value">4h</div>
            <div class="stat-label">Best Window</div>
            <div class="stat-sub">London–NY Overlap: 6:30–10:30 PM IST</div>
        </div>
    </div>
</div>

<!-- ── SECTION: TOP BIG PLAYERS ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-crown" style="color:var(--warning);"></i> Top Institutional Players</div>
            </div>
            <div class="panel-body">
                <div class="sm-players-grid">
                    <?php
                    $players = [
                        ['icon'=>'fa-university',    'name'=>'JP Morgan',       'type'=>'Investment Bank',    'color'=>'blue',   'fact'=>'Largest forex dealer. Trades every pair 24/5. Sets the price.'],
                        ['icon'=>'fa-building',      'name'=>'Deutsche Bank',   'type'=>'Investment Bank',    'color'=>'blue',   'fact'=>'Top European bank. Huge EUR/USD flow. Controls London session.'],
                        ['icon'=>'fa-city',          'name'=>'Citi Group',      'type'=>'Investment Bank',    'color'=>'cyan',   'fact'=>'Massive emerging market flow. Active in London & NY sessions.'],
                        ['icon'=>'fa-chart-pie',     'name'=>'Hedge Funds',     'type'=>'Macro Speculators',  'color'=>'purple', 'fact'=>'Trade based on economic outlook. Hold for weeks/months.'],
                        ['icon'=>'fa-bolt',          'name'=>'HFT Firms',       'type'=>'High Freq. Trading', 'color'=>'warn',   'fact'=>'Trade in microseconds. Profit from order flow & spreads.'],
                        ['icon'=>'fa-landmark-flag', 'name'=>'Central Banks',   'type'=>'Market Makers',      'color'=>'profit', 'fact'=>'FED, ECB, RBI. Intervene to protect their currency. Biggest single moves.'],
                    ];
                    foreach ($players as $p):
                        $c = $maColors[$p['color']] ?? $maColors['blue'];
                    ?>
                    <div class="sm-player-card">
                        <div class="sm-player-icon" style="background:<?= $c['bg'] ?>;border:1px solid <?= $c['border'] ?>;color:<?= $c['txt'] ?>;"><i class="fas <?= $p['icon'] ?>"></i></div>
                        <div class="sm-player-name"><?= $p['name'] ?></div>
                        <div class="sm-player-type" style="color:<?= $c['txt'] ?>;"><?= $p['type'] ?></div>
                        <div class="sm-player-fact"><?= $p['fact'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION: SESSION GUIDE ────────────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-clock"></i> Session Activity Guide (IST Times)</div>

<!-- Visual Session Timeline -->
<div class="panel mb-3">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-timeline"></i> 24-Hour Market Timeline (IST)</div>
    </div>
    <div class="panel-body">
        <div class="sm-timeline">
            <div class="sm-tl-bar">
                <div class="sm-tl-seg" style="width:28.5%;background:rgba(148,163,184,.15);border:1px solid rgba(148,163,184,.3);" title="Closed">
                    <span>CLOSED</span><span class="sm-tl-time">1:30–5:30 AM</span>
                </div>
                <div class="sm-tl-seg" style="width:28.5%;background:rgba(148,163,184,.1);border:1px solid rgba(148,163,184,.25);" title="Asian">
                    <span>🌙 ASIAN</span><span class="sm-tl-time">5:30–12:30 PM</span>
                </div>
                <div class="sm-tl-seg" style="width:25%;background:rgba(8,145,178,.15);border:1px solid rgba(8,145,178,.3);" title="London">
                    <span>🇬🇧 LONDON</span><span class="sm-tl-time">12:30–6:30 PM</span>
                </div>
                <div class="sm-tl-seg sm-tl-best" style="width:16.5%;background:rgba(22,163,74,.2);border:1px solid rgba(22,163,74,.4);" title="Overlap">
                    <span>🔥 OVERLAP</span><span class="sm-tl-time">6:30–10:30 PM</span>
                </div>
                <div class="sm-tl-seg" style="width:12%;background:rgba(37,99,235,.12);border:1px solid rgba(37,99,235,.25);" title="NY Late" >
                    <span>🗽 NY</span><span class="sm-tl-time">10:30 PM–1:30 AM</span>
                </div>
            </div>
            <div class="sm-tl-labels">
                <span>12 AM</span><span>3 AM</span><span>6 AM</span><span>9 AM</span><span>12 PM</span><span>3 PM</span><span>6 PM</span><span>9 PM</span><span>12 AM</span>
            </div>
        </div>
    </div>
</div>

<!-- Session Detail Cards -->
<div class="row g-3 mb-4">
    <?php
    $sessions = [
        [
            'name'    => 'Asian Session',
            'time'    => '5:30 AM – 12:30 PM IST',
            'icon'    => 'fa-moon',
            'color'   => 'muted',
            'rating'  => 1,
            'who'     => 'Tokyo, Singapore, Sydney banks',
            'pairs'   => 'USDJPY, AUDUSD, NZDUSD',
            'volume'  => '~20% daily volume',
            'tip'     => 'Low volume, slow & choppy moves. Range-bound. Avoid trading unless you have a specific Asia setup.',
            'do'      => 'Plan your day. Review charts. Mark key levels.',
            'dont'    => 'Don\'t scalp. Don\'t force trades. Spreads are wide.',
        ],
        [
            'name'    => 'London Session',
            'time'    => '12:30 PM – 6:30 PM IST',
            'icon'    => 'fa-landmark',
            'color'   => 'cyan',
            'rating'  => 3,
            'who'     => 'European banks — Deutsche, Barclays, HSBC',
            'pairs'   => 'EURUSD, GBPUSD, EURGBP',
            'volume'  => '~35% daily volume',
            'tip'     => 'Big money starts moving. Breakouts from Asian range happen here. Strong trending moves begin.',
            'do'      => 'Watch for Asian range breakout. Trade with London direction.',
            'dont'    => 'Don\'t trade against the opening move unless confirmed reversal.',
        ],
        [
            'name'    => 'London–NY Overlap 🔥',
            'time'    => '6:30 PM – 10:30 PM IST',
            'icon'    => 'fa-fire',
            'color'   => 'profit',
            'rating'  => 5,
            'who'     => 'ALL banks — JP Morgan, Citi, Goldman, Deutsche together',
            'pairs'   => 'EURUSD, GBPUSD, XAUUSD (Gold), USDJPY',
            'volume'  => '~50% of daily volume in 4 hours!',
            'tip'     => 'THIS is the golden window. Both European AND US banks are active simultaneously. Biggest moves, cleanest setups, most liquidity.',
            'do'      => 'This is your primary trading window. Best R:R setups. Best entries.',
            'dont'    => 'Don\'t miss this window. Don\'t overtrade just because it\'s active.',
        ],
        [
            'name'    => 'NY Late Session',
            'time'    => '10:30 PM – 1:30 AM IST',
            'icon'    => 'fa-city',
            'color'   => 'blue',
            'rating'  => 2,
            'who'     => 'US banks winding down. London closed.',
            'pairs'   => 'USDCAD, USDCHF, USDJPY',
            'volume'  => '~15% daily volume, declining',
            'tip'     => 'Volume dropping. Beware of sudden reversals as banks square positions before close. Spreads widen.',
            'do'      => 'Take profits on open trades. Trail stops. Close before sleep.',
            'dont'    => 'Don\'t open new trades. Never hold USD pairs without a stop.',
        ],
    ];
    foreach ($sessions as $sess):
        $c = $maColors[$sess['color']] ?? $maColors['muted'];
    ?>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="panel h-100" style="border-top:3px solid <?= $c['txt'] ?>;">
            <div class="panel-header" style="background:<?= $c['bg'] ?>;border-bottom:1px solid <?= $c['border'] ?>;">
                <div class="panel-title" style="color:<?= $c['txt'] ?>;gap:8px;">
                    <div style="width:32px;height:32px;border-radius:8px;background:<?= $c['bg'] ?>;border:1px solid <?= $c['border'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= $sess['icon'] ?>" style="color:<?= $c['txt'] ?>;font-size:14px;"></i>
                    </div>
                    <?= $sess['name'] ?>
                </div>
                <div style="display:flex;gap:3px;">
                    <?php for($s=1;$s<=5;$s++): ?>
                    <div style="width:8px;height:8px;border-radius:50%;background:<?= $s<=$sess['rating'] ? $c['txt'] : 'var(--border)' ?>;"></div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="panel-body" style="padding:14px 16px;">
                <div style="font-size:12px;font-weight:700;color:<?= $c['txt'] ?>;margin-bottom:10px;">
                    <i class="fas fa-clock" style="margin-right:4px;"></i><?= $sess['time'] ?>
                </div>
                <div class="sm-sess-row"><span class="sm-sess-key">Who's Active</span><span class="sm-sess-val"><?= $sess['who'] ?></span></div>
                <div class="sm-sess-row"><span class="sm-sess-key">Best Pairs</span><span class="sm-sess-val" style="font-family:var(--font-mono);font-size:10px;"><?= $sess['pairs'] ?></span></div>
                <div class="sm-sess-row"><span class="sm-sess-key">Volume</span><span class="sm-sess-val" style="color:<?= $c['txt'] ?>;font-weight:600;"><?= $sess['volume'] ?></span></div>
                <p style="font-size:11px;color:var(--text-secondary);line-height:1.55;margin:10px 0 8px;"><?= $sess['tip'] ?></p>
                <div class="sm-do-dont">
                    <div class="sm-do"><i class="fas fa-check"></i> <?= $sess['do'] ?></div>
                    <div class="sm-dont"><i class="fas fa-xmark"></i> <?= $sess['dont'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── SECTION: DAY OF WEEK ──────────────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-calendar-week"></i> Day of Week — Big Money Activity</div>
<div class="panel mb-4">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-chart-bar"></i> Which Days Should You Trade?</div>
    </div>
    <div class="panel-body">
        <div class="sm-days-grid">
            <?php
            $days = [
                ['name'=>'Monday',    'short'=>'MON','icon'=>'fa-hourglass-start','color'=>'warn',  'stars'=>1,'badge'=>'AVOID',   'txt'=>'Market digests weekend news. Fake moves, wide spreads. Banks positioning quietly.',  'do'=>'Watch for direction, mark levels, prepare.',    'dont'=>'Do not trade the first candle or any gap fills.'],
                ['name'=>'Tuesday',   'short'=>'TUE','icon'=>'fa-chart-line',    'color'=>'cyan',  'stars'=>3,'badge'=>'MEDIUM',   'txt'=>'Week\'s trend starting to form. London and NY more active. Better setups emerging.', 'do'=>'Trade confirmed breakouts with London direction.','dont'=>'Don\'t overtrade — still early in the week.'],
                ['name'=>'Wednesday', 'short'=>'WED','icon'=>'fa-fire',          'color'=>'profit','stars'=>5,'badge'=>'BEST DAY', 'txt'=>'Banks fully active. FOMC weeks = 200+ pip moves. Biggest institutional flow of the week.', 'do'=>'Your prime trading day. Take your A+ setups.',   'dont'=>'Don\'t miss this day sitting on sidelines.'],
                ['name'=>'Thursday',  'short'=>'THU','icon'=>'fa-bolt',          'color'=>'profit','stars'=>5,'badge'=>'BEST DAY', 'txt'=>'US Jobless Claims at 6 PM IST. Continuation of Wednesday moves. Strong momentum.',     'do'=>'Extend winning trades. New setups in NY open.',  'dont'=>'Don\'t hold trades past NY close without stop.'],
                ['name'=>'Friday',    'short'=>'FRI','icon'=>'fa-triangle-exclamation','color'=>'warn','stars'=>2,'badge'=>'CAREFUL', 'txt'=>'Banks closing weekly positions. NFP on 1st Friday = extreme moves. Reversals common.', 'do'=>'Take profits early. Review the week.',          'dont'=>'NEVER hold positions over the weekend!'],
                ['name'=>'Saturday',  'short'=>'SAT','icon'=>'fa-moon',          'color'=>'muted', 'stars'=>0,'badge'=>'CLOSED',  'txt'=>'Market fully closed. Only crypto markets open. No forex trading possible.', 'do'=>'Review your trades. Plan next week.',             'dont'=>'Don\'t stress. Rest and recharge.'],
                ['name'=>'Sunday',    'short'=>'SUN','icon'=>'fa-moon',          'color'=>'muted', 'stars'=>0,'badge'=>'CLOSED',  'txt'=>'Forex market opens at 5 AM IST Monday. Asian session gap may occur.', 'do'=>'Finalize watchlist. Set your plan.',              'dont'=>'Don\'t trade Sunday open gaps — very risky.'],
            ];
            foreach ($days as $d):
                $c = $maColors[$d['color']] ?? $maColors['muted'];
                $isToday = ($mDay['short'] === $d['short']);
            ?>
            <div class="sm-day-card <?= $isToday ? 'sm-day-today' : '' ?>" style="border-top:3px solid <?= $c['txt'] ?>;">
                <?php if ($isToday): ?>
                <div class="sm-today-banner" style="background:<?= $c['bg'] ?>;color:<?= $c['txt'] ?>;">TODAY</div>
                <?php endif; ?>
                <div class="sm-day-header" style="background:<?= $c['bg'] ?>;border-bottom:1px solid <?= $c['border'] ?>;">
                    <div class="sm-day-icon" style="color:<?= $c['txt'] ?>;"><i class="fas <?= $d['icon'] ?>"></i></div>
                    <div>
                        <div class="sm-day-name"><?= $d['name'] ?></div>
                        <span class="sm-day-badge" style="background:<?= $c['bg'] ?>;color:<?= $c['txt'] ?>;border:1px solid <?= $c['border'] ?>;"><?= $d['badge'] ?></span>
                    </div>
                    <div class="sm-day-stars">
                        <?php for($s=1;$s<=5;$s++): ?>
                        <div class="sm-star <?= $s<=$d['stars'] ? 'on' : 'off' ?>" style="<?= $s<=$d['stars'] ? "background:{$c['txt']};" : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="sm-day-body">
                    <p class="sm-day-tip"><?= $d['txt'] ?></p>
                    <div class="sm-do-dont">
                        <div class="sm-do"><i class="fas fa-check"></i> <?= $d['do'] ?></div>
                        <div class="sm-dont"><i class="fas fa-xmark"></i> <?= $d['dont'] ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── SECTION: SMART MONEY CONCEPTS ─────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-brain"></i> Smart Money Concepts (SMC)</div>
<div class="row g-3 mb-4">
    <?php
    $concepts = [
        [
            'icon'   => 'fa-cubes',
            'color'  => 'blue',
            'name'   => 'Order Blocks',
            'badge'  => 'Most Important',
            'simple' => 'Woh last bearish candle jiske baad strong bullish move aaya — wahan bank ka BUY order tha. Price wapas aaye toh BUY karo.',
            'visual' => [
                '↓ bearish candle  ← ORDER BLOCK (bank buy karta hai)',
                '↑↑↑↑ strong move up',
                '↓ price wapas aata hai order block pe',
                '✅ BUY ENTRY yahan',
            ],
            'rule'   => 'Always trade INTO order blocks, never away from them.',
        ],
        [
            'icon'   => 'fa-magnet',
            'color'  => 'loss',
            'name'   => 'Liquidity Hunt (Stop Hunt)',
            'badge'  => 'Most Common Trap',
            'simple' => 'Retail traders obvious jagah stop lagate hain (previous high/low ke neeche). Banks pehle WAHAN jaate hain — stops trigger karte hain — phir asli direction mein move karte hain.',
            'visual' => [
                'Equal Highs (retail stops here) ←──── LIQUIDITY ABOVE',
                '──────────────────────────────────────────────────────',
                'Price spikes UP → stops trigger → then DROPS',
                '✅ Sell ke baad spike — bank ne liquidity collect ki',
            ],
            'rule'   => 'Equal highs/lows = liquidity target. Expect a sweep before real move.',
        ],
        [
            'icon'   => 'fa-clock-rotate-left',
            'color'  => 'profit',
            'name'   => 'Kill Zones (Best Entry Times)',
            'badge'  => 'Time-Based',
            'simple' => 'Banks specific time windows mein hi aggressive trading karte hain. Inhe "Kill Zones" kehte hain. In times ke bahar trade karna random hai.',
            'visual' => [
                '🏙️ London Open KZ:  12:30 – 1:30 PM IST',
                '🗽 NY Open KZ:      6:30 – 8:30 PM IST ⭐ BEST',
                '🌙 Asian Range:     5:30 – 12:30 PM IST (mark it)',
                '⏰ London Close:    4:30 – 5:30 PM IST (reversals)',
            ],
            'rule'   => 'Only enter trades during Kill Zones. Other times = wait.',
        ],
        [
            'icon'   => 'fa-box-archive',
            'color'  => 'purple',
            'name'   => 'Accumulation & Distribution',
            'badge'  => 'Wyckoff Method',
            'simple' => 'Bank directly bulk buy/sell nahi karta — price move ho jayega. Isiliye slowly quietly enter karta hai jab sab bech rahe hote hain, aur exit karta hai jab sab khareed rahe hote hain.',
            'visual' => [
                'ACCUMULATION (bottom pe):',
                '  Retail panic sells → Bank quietly buys',
                'DISTRIBUTION (top pe):',
                '  Retail FOMO buys → Bank quietly sells',
            ],
            'rule'   => 'When retail is panic selling = bank is buying. Reverse FOMO.',
        ],
        [
            'icon'   => 'fa-mask',
            'color'  => 'warn',
            'name'   => 'Fake Breakouts (Traps)',
            'badge'  => 'Trap Alert',
            'simple' => 'Price resistance break karta hai — retail FOMO mein buy karta hai. Bank wahan SELL karta hai. Price wapas aati hai. Retail trapped!',
            'visual' => [
                '──── RESISTANCE LINE ────',
                '↑↑↑ Fake breakout (retail buys here)',
                '↓↓↓ Price drops back below (bank sold)',
                '😱 Retail ka stop hit. Bank profit!',
            ],
            'rule'   => 'Wait for candle CLOSE above resistance. Never enter on wick.',
        ],
        [
            'icon'   => 'fa-chart-area',
            'color'  => 'cyan',
            'name'   => 'Fair Value Gaps (FVG)',
            'badge'  => 'ICT Concept',
            'simple' => 'Jab price bahut tezi se move karta hai toh woh ek "gap" chhod jaata hai jahan koi trade nahi hua. Price wapas is gap ko "fill" karne aati hai — yahan entry lo.',
            'visual' => [
                'Candle 1:  High = 100',
                'Candle 2:  Huge gap (FVG zone = 100-105)',
                'Candle 3:  Low = 105',
                '✅ Price wapas 100-105 aaye toh BUY entry',
            ],
            'rule'   => 'Mark FVGs on 15M chart. Wait for price to return and react.',
        ],
    ];
    foreach ($concepts as $con):
        $c = $maColors[$con['color']] ?? $maColors['blue'];
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="panel h-100" style="border-top:3px solid <?= $c['txt'] ?>;">
            <div class="panel-header" style="background:<?= $c['bg'] ?>;border-bottom:1px solid <?= $c['border'] ?>;">
                <div class="panel-title">
                    <div style="width:34px;height:34px;border-radius:8px;background:<?= $c['bg'] ?>;border:1px solid <?= $c['border'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= $con['icon'] ?>" style="color:<?= $c['txt'] ?>;font-size:15px;"></i>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:<?= $c['txt'] ?>;"><?= $con['name'] ?></div>
                        <div style="font-size:9px;color:<?= $c['txt'] ?>;opacity:.75;font-weight:600;letter-spacing:.05em;text-transform:uppercase;"><?= $con['badge'] ?></div>
                    </div>
                </div>
            </div>
            <div class="panel-body" style="padding:14px 16px;">
                <p style="font-size:12px;color:var(--text-secondary);line-height:1.6;margin-bottom:12px;"><?= $con['simple'] ?></p>

                <!-- Visual Example Box -->
                <div class="sm-code-box">
                    <?php foreach ($con['visual'] as $line): ?>
                    <div><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Golden Rule -->
                <div class="sm-rule-box" style="background:<?= $c['bg'] ?>;border-left:3px solid <?= $c['txt'] ?>;">
                    <i class="fas fa-star" style="color:<?= $c['txt'] ?>;margin-right:6px;font-size:10px;"></i>
                    <span style="font-size:11px;font-weight:600;color:var(--text-primary);"><?= $con['rule'] ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── SECTION: BEST PAIRS ───────────────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-coins"></i> Best Pairs — Where Big Money Plays</div>
<div class="row g-3 mb-4">
    <?php
    $pairs = [
        ['pair'=>'EUR/USD','icon'=>'🇪🇺','color'=>'blue',  'spread'=>'0.1 pip','session'=>'London + NY Overlap','vol'=>'★★★★★','tip'=>'Most liquid pair. Banks move this the most. Cleanest SMC setups.'],
        ['pair'=>'GBP/USD','icon'=>'🇬🇧','color'=>'cyan',  'spread'=>'0.3 pip','session'=>'London Open','vol'=>'★★★★☆','tip'=>'High volatility. Big moves on London open. ICT loves this pair.'],
        ['pair'=>'XAU/USD','icon'=>'🥇','color'=>'warn',   'spread'=>'0.3 pip','session'=>'NY Open (6:30 PM IST)','vol'=>'★★★★★','tip'=>'Gold. Biggest moves on NFP & FOMC. 500-1000 pip spikes common.'],
        ['pair'=>'USD/JPY','icon'=>'🇯🇵','color'=>'purple', 'spread'=>'0.2 pip','session'=>'Asian + NY','vol'=>'★★★★☆','tip'=>'Safe haven. BOJ interventions possible. Good for Asian session traders.'],
        ['pair'=>'USD/CAD','icon'=>'🇨🇦','color'=>'profit', 'spread'=>'0.3 pip','session'=>'NY Session','vol'=>'★★★☆☆','tip'=>'Crude oil correlation. Good in NY session. Avoid Asian session.'],
        ['pair'=>'GBP/JPY','icon'=>'💥','color'=>'loss',   'spread'=>'0.8 pip','session'=>'London + NY','vol'=>'★★★★★','tip'=>'The "Dragon". Highest volatility pair. Expert traders only.'],
    ];
    foreach ($pairs as $p):
        $c = $maColors[$p['color']] ?? $maColors['blue'];
    ?>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="sm-pair-card" style="border-top:3px solid <?= $c['txt'] ?>;">
            <div class="sm-pair-flag"><?= $p['icon'] ?></div>
            <div class="sm-pair-name" style="color:<?= $c['txt'] ?>;"><?= $p['pair'] ?></div>
            <div class="sm-pair-meta"><span>Spread</span><span style="font-family:var(--font-mono);"><?= $p['spread'] ?></span></div>
            <div class="sm-pair-meta"><span>Session</span><span style="font-size:9px;"><?= $p['session'] ?></span></div>
            <div class="sm-pair-vol"><?= $p['vol'] ?></div>
            <div class="sm-pair-tip"><?= $p['tip'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── SECTION: DAILY PRE-TRADE CHECKLIST ───────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-clipboard-list"></i> Daily Pre-Trade Checklist</div>
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-7">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-check-double" style="color:var(--profit);"></i> Smart Money Checklist — Check Before Every Trade</div>
                <button class="btn-secondary-custom" onclick="smResetChecklist()" style="font-size:11px;padding:5px 12px;">Reset</button>
            </div>
            <div class="panel-body">
                <div id="smChecklist">
                <?php
                $checks = [
                    ['id'=>'c1', 'cat'=>'Time',    'q'=>'Am I trading during London or NY session? (12:30 PM – 1:30 AM IST)',       'warn'=>'If NO → do not trade. Wrong time = bad moves.'],
                    ['id'=>'c2', 'cat'=>'Day',     'q'=>'Is today Tue / Wed / Thu? (Best trading days)',                            'warn'=>'If Mon or Fri → reduce size or skip.'],
                    ['id'=>'c3', 'cat'=>'News',    'q'=>'Have I checked ForexFactory for high-impact news in next 2 hours?',        'warn'=>'If NO → check it now before entering any trade.'],
                    ['id'=>'c4', 'cat'=>'Trend',   'q'=>'Do I know the higher TF (4H/Daily) trend direction?',                     'warn'=>'If NO → go to 4H chart and mark the trend.'],
                    ['id'=>'c5', 'cat'=>'SMC',     'q'=>'Is there a valid Order Block or FVG for my entry?',                       'warn'=>'If NO → no trade. Don\'t guess.'],
                    ['id'=>'c6', 'cat'=>'Risk',    'q'=>'Is my daily loss limit still available? (Check Dashboard risk meter)',     'warn'=>'If limit is hit → DO NOT TRADE. Period.'],
                    ['id'=>'c7', 'cat'=>'R:R',     'q'=>'Is my Risk:Reward at least 1:2 or better?',                              'warn'=>'If NO → find a better entry or skip this trade.'],
                    ['id'=>'c8', 'cat'=>'Stop',    'q'=>'Is my Stop Loss set BELOW the Order Block (not at a round number)?',      'warn'=>'Round number stops = banks will hunt you.'],
                    ['id'=>'c9', 'cat'=>'FOMO',    'q'=>'Am I trading because of a valid setup, NOT because of FOMO or revenge?',  'warn'=>'If you\'re emotional → step away. No trade.'],
                ];
                foreach ($checks as $ch):
                    $catColors = ['Time'=>'blue','Day'=>'warn','News'=>'loss','Trend'=>'cyan','SMC'=>'purple','Risk'=>'loss','R:R'=>'profit','Stop'=>'warn','FOMO'=>'purple'];
                    $cat = $ch['cat'];
                    $cc = $maColors[$catColors[$cat] ?? 'blue'];
                ?>
                <div class="sm-check-item" id="wrap_<?= $ch['id'] ?>">
                    <label class="sm-check-label">
                        <input type="checkbox" id="<?= $ch['id'] ?>" class="sm-checkbox" onchange="smCheckChange('<?= $ch['id'] ?>')">
                        <span class="sm-check-box"></span>
                        <span class="sm-cat-tag" style="background:<?= $cc['bg'] ?>;color:<?= $cc['txt'] ?>;border:1px solid <?= $cc['border'] ?>;"><?= $cat ?></span>
                        <span class="sm-check-q"><?= $ch['q'] ?></span>
                    </label>
                    <div class="sm-check-warn" id="warn_<?= $ch['id'] ?>">
                        <i class="fas fa-triangle-exclamation" style="font-size:10px;margin-right:4px;"></i><?= $ch['warn'] ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <!-- Score bar -->
                <div class="sm-score-bar">
                    <div class="sm-score-label">
                        <span>Checklist Score</span>
                        <span id="smScoreText" style="font-weight:700;color:var(--text-primary);">0 / <?= count($checks) ?></span>
                    </div>
                    <div class="sm-score-track">
                        <div class="sm-score-fill" id="smScoreFill"></div>
                    </div>
                    <div id="smScoreMsg" class="sm-score-msg"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Golden Rules -->
    <div class="col-12 col-lg-5">
        <div class="panel h-100">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-scroll" style="color:var(--warning);"></i> Golden Rules of Smart Money Trading</div>
            </div>
            <div class="panel-body">
                <?php
                $rules = [
                    ['n'=>1, 'color'=>'profit','icon'=>'fa-handshake','rule'=>'Follow the 95%, not the 5%','sub'=>'Big players (banks) move markets. Always trade WITH their direction, never against.'],
                    ['n'=>2, 'color'=>'blue',  'icon'=>'fa-clock',    'rule'=>'Time > Setup','sub'=>'A perfect setup at the wrong time (Asian session) is a bad trade. Kill Zones only.'],
                    ['n'=>3, 'color'=>'cyan',  'icon'=>'fa-calendar-check','rule'=>'Wednesday & Thursday are your days','sub'=>'Most institutional flow happens mid-week. Save your risk budget for these days.'],
                    ['n'=>4, 'color'=>'warn',  'icon'=>'fa-triangle-exclamation','rule'=>'Never trade 30 min before news','sub'=>'FOMC, NFP, CPI = spreads explode. Close trades 30 min before, re-enter after.'],
                    ['n'=>5, 'color'=>'loss',  'icon'=>'fa-ban',      'rule'=>'Friday = No new trades after 7 PM IST','sub'=>'Banks close positions. Sudden reversals. Weekend gap risk. Protect your account.'],
                    ['n'=>6, 'color'=>'purple','icon'=>'fa-eye',      'rule'=>'Wait for the liquidity sweep','sub'=>'Price will sweep the obvious level first. Let it take stops, then enter with the move.'],
                    ['n'=>7, 'color'=>'profit','icon'=>'fa-shield',   'rule'=>'Stop below Order Block, always','sub'=>'If SL is at a round number → banks will hunt it. Put it inside/below the OB.'],
                    ['n'=>8, 'color'=>'blue',  'icon'=>'fa-chart-line','rule'=>'Higher TF rules. Lower TF entry','sub'=>'4H/Daily gives direction. 15M/1H gives precise entry. Never trade against 4H trend.'],
                ];
                foreach ($rules as $r):
                    $c = $maColors[$r['color']] ?? $maColors['blue'];
                ?>
                <div class="sm-rule-item">
                    <div class="sm-rule-num" style="background:<?= $c['bg'] ?>;color:<?= $c['txt'] ?>;border:1px solid <?= $c['border'] ?>;"><?= $r['n'] ?></div>
                    <div class="sm-rule-content">
                        <div class="sm-rule-title"><i class="fas <?= $r['icon'] ?>" style="color:<?= $c['txt'] ?>;margin-right:5px;font-size:11px;"></i><?= $r['rule'] ?></div>
                        <div class="sm-rule-sub"><?= $r['sub'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION: QUICK REFERENCE ─────────────────────────────────────────── -->
<div class="risk-section-header"><i class="fas fa-table-list"></i> Quick Reference Card</div>
<div class="panel mb-4">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-print"></i> Bookmark This — Check Every Day</div>
    </div>
    <div class="panel-body">
        <div class="sm-ref-grid">
            <div class="sm-ref-col">
                <div class="sm-ref-header" style="background:rgba(22,163,74,.1);color:#16a34a;">✅ TRADE THESE TIMES</div>
                <div class="sm-ref-item"><i class="fas fa-circle-check" style="color:#16a34a;"></i> 12:30 PM – 6:30 PM IST (London)</div>
                <div class="sm-ref-item best"><i class="fas fa-fire" style="color:#d97706;"></i> <strong>6:30 PM – 10:30 PM IST (Overlap) 🔥</strong></div>
                <div class="sm-ref-item"><i class="fas fa-circle-check" style="color:#16a34a;"></i> Tuesday, Wednesday, Thursday</div>
                <div class="sm-ref-item"><i class="fas fa-circle-check" style="color:#16a34a;"></i> After news releases (not during)</div>
            </div>
            <div class="sm-ref-col">
                <div class="sm-ref-header" style="background:rgba(220,38,38,.1);color:#dc2626;">❌ AVOID THESE TIMES</div>
                <div class="sm-ref-item"><i class="fas fa-ban" style="color:#dc2626;"></i> 5:30 AM – 12:30 PM IST (Asian)</div>
                <div class="sm-ref-item"><i class="fas fa-ban" style="color:#dc2626;"></i> Monday (first 2 hours)</div>
                <div class="sm-ref-item"><i class="fas fa-ban" style="color:#dc2626;"></i> Friday after 7 PM IST</div>
                <div class="sm-ref-item"><i class="fas fa-ban" style="color:#dc2626;"></i> 30 min before FOMC / NFP</div>
            </div>
            <div class="sm-ref-col">
                <div class="sm-ref-header" style="background:rgba(37,99,235,.1);color:#2563eb;">📊 FOCUS PAIRS</div>
                <div class="sm-ref-item"><i class="fas fa-star" style="color:#2563eb;"></i> EUR/USD — All sessions</div>
                <div class="sm-ref-item"><i class="fas fa-star" style="color:#2563eb;"></i> GBP/USD — London open</div>
                <div class="sm-ref-item"><i class="fas fa-star" style="color:#2563eb;"></i> XAU/USD — NY open, NFP days</div>
                <div class="sm-ref-item"><i class="fas fa-star" style="color:#2563eb;"></i> USD/JPY — Asian or NY</div>
            </div>
            <div class="sm-ref-col">
                <div class="sm-ref-header" style="background:rgba(124,58,237,.1);color:#7c3aed;">🎯 SMC ENTRY RULES</div>
                <div class="sm-ref-item"><i class="fas fa-arrow-right" style="color:#7c3aed;"></i> Wait for liquidity sweep</div>
                <div class="sm-ref-item"><i class="fas fa-arrow-right" style="color:#7c3aed;"></i> Find Order Block / FVG</div>
                <div class="sm-ref-item"><i class="fas fa-arrow-right" style="color:#7c3aed;"></i> Enter on retest during Kill Zone</div>
                <div class="sm-ref-item"><i class="fas fa-arrow-right" style="color:#7c3aed;"></i> Stop inside OB, target next liquidity</div>
            </div>
        </div>
    </div>
</div>

<script>
const TOTAL = <?= count($checks) ?>;

function smCheckChange(id) {
    const checked = document.getElementById(id).checked;
    const wrap    = document.getElementById('wrap_' + id);
    const warn    = document.getElementById('warn_' + id);

    if (checked) {
        wrap.classList.add('done');
        warn.style.display = 'none';
    } else {
        wrap.classList.remove('done');
    }
    smUpdateScore();
}

function smUpdateScore() {
    let score = 0;
    for (let i = 1; i <= TOTAL; i++) {
        const el = document.getElementById('c' + i);
        if (el && el.checked) score++;
    }
    const pct  = Math.round(score / TOTAL * 100);
    const fill = document.getElementById('smScoreFill');
    const txt  = document.getElementById('smScoreText');
    const msg  = document.getElementById('smScoreMsg');

    txt.textContent = score + ' / ' + TOTAL;
    fill.style.width = pct + '%';

    if (pct === 100) {
        fill.style.background = 'var(--profit)';
        msg.style.color       = 'var(--profit)';
        msg.textContent       = '✅ All checks passed! You are ready to trade.';
    } else if (pct >= 70) {
        fill.style.background = 'var(--warning)';
        msg.style.color       = 'var(--warning)';
        msg.textContent       = '⚠️ Almost ready — complete remaining checks first.';
    } else {
        fill.style.background = 'var(--loss)';
        msg.style.color       = 'var(--loss)';
        msg.textContent       = '❌ Not ready to trade yet. Complete the checklist.';
    }
}

function smResetChecklist() {
    for (let i = 1; i <= TOTAL; i++) {
        const el   = document.getElementById('c' + i);
        const wrap = document.getElementById('wrap_c' + i);
        if (el) el.checked = false;
        if (wrap) wrap.classList.remove('done');
    }
    smUpdateScore();
}

// Show warn on page load for unchecked items
document.addEventListener('DOMContentLoaded', function() {
    smUpdateScore();
});
</script>

<?php include '../includes/footer.php'; ?>

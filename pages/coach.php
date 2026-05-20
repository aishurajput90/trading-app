<?php
require_once '../config/db.php';
$pageTitle = 'Coach Dashboard';
$rootPath  = '../';
$userId    = DEFAULT_USER_ID;
$today     = date('Y-m-d');
$db        = getDB();

// ── Month calendar data ─────────────────────────────────────────────────────
$monthYear = isset($_GET['m']) ? $_GET['m'] : date('Y-m');
[$yr, $mo]  = explode('-', $monthYear);
$yr = (int)$yr; $mo = (int)$mo;
$monthStart = sprintf('%04d-%02d-01', $yr, $mo);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$stmt = $db->prepare("SELECT cal_date, day_mark, discipline_score, psychology_score,
    risk_score, total_trades, wins, losses, net_pl
    FROM discipline_calendar WHERE user_id=? AND cal_date BETWEEN ? AND ?
    ORDER BY cal_date ASC");
$stmt->execute([$userId, $monthStart, $monthEnd]);
$calRows = $stmt->fetchAll();
$calMap  = [];
foreach ($calRows as $r) $calMap[$r['cal_date']] = $r;

// ── Today's checklist status ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT cleared_to_trade FROM pre_trade_checklist WHERE user_id=? AND checklist_date=?");
$stmt->execute([$userId, $today]);
$todayCheck = $stmt->fetch();

$stmt = $db->prepare("SELECT id FROM discipline_calendar WHERE user_id=? AND cal_date=?");
$stmt->execute([$userId, $today]);
$todayPost = $stmt->fetch();

// ── Streak calculation (last 60 days) ────────────────────────────────────────
$stmt = $db->prepare("SELECT cal_date, day_mark FROM discipline_calendar
    WHERE user_id=? AND cal_date <= ? ORDER BY cal_date DESC LIMIT 60");
$stmt->execute([$userId, $today]);
$recentDays = $stmt->fetchAll();

$greenStreak = 0; $redStreak = 0; $totalDays = 0; $greenDays = 0;
$emotionCount = []; $streakCounting = true; $redStreakCounting = true;
foreach ($recentDays as $rd) {
    $mark = $rd['day_mark'];
    $totalDays++;
    if (in_array($mark, ['green','star'])) {
        $greenDays++;
        if ($streakCounting) $greenStreak++;
        $redStreakCounting = true; // reset red streak attempt
    } elseif (in_array($mark, ['red','stop'])) {
        if ($redStreakCounting) $redStreak++;
        $streakCounting = true; // reset green streak attempt
    } else {
        // yellow breaks both streaks
        $streakCounting     = false;
        $redStreakCounting  = false;
    }
}
$disciplinePct = $totalDays > 0 ? round($greenDays / $totalDays * 100) : 0;

// ── Last 10 days avg discipline for capital readiness ───────────────────────
$stmt = $db->prepare("SELECT AVG(discipline_score) as avg_d, AVG(psychology_score) as avg_p,
    AVG(risk_score) as avg_r, COUNT(*) as cnt
    FROM discipline_calendar WHERE user_id=? AND cal_date > DATE_SUB(?, INTERVAL 10 DAY)");
$stmt->execute([$userId, $today]);
$last10 = $stmt->fetch();
$avgDisc = round((float)($last10['avg_d'] ?? 0), 1);
$readyToScale = ($last10['cnt'] >= 5 && $avgDisc >= 8.0);

// ── This week's data ─────────────────────────────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$stmt = $db->prepare("SELECT cal_date, day_mark, discipline_score, psychology_score,
    risk_score, total_trades, net_pl, rule_breaks, emotional_mistakes
    FROM discipline_calendar WHERE user_id=? AND cal_date BETWEEN ? AND ? ORDER BY cal_date ASC");
$stmt->execute([$userId, $weekStart, $weekEnd]);
$weekRows = $stmt->fetchAll();

// Most common emotional mistake (from this month)
$stmt = $db->prepare("SELECT emotional_mistakes FROM discipline_calendar
    WHERE user_id=? AND cal_date >= ? AND emotional_mistakes IS NOT NULL");
$stmt->execute([$userId, $monthStart]);
$emoRows = $stmt->fetchAll();
$emoWords = [];
foreach ($emoRows as $er) {
    preg_match_all('/\b(revenge|fomo|overtrade|impulse|boredom|greedy|fear|anxious|forced|moved stop|random)\b/i',
        $er['emotional_mistakes'], $matches);
    foreach ($matches[1] as $w) {
        $w = strtolower($w);
        $emoWords[$w] = ($emoWords[$w] ?? 0) + 1;
    }
}
arsort($emoWords);
$topEmotion = $emoWords ? array_key_first($emoWords) : null;

// ── Recent coaching logs ─────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM coaching_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$userId]);
$coachLogs = $stmt->fetchAll();

// ── Month navigation ─────────────────────────────────────────────────────────
$prevM = date('Y-m', mktime(0,0,0,$mo-1,1,$yr));
$nextM = date('Y-m', mktime(0,0,0,$mo+1,1,$yr));

include '../includes/header.php';

$markColors = [
    'green'=>'#22c55e','yellow'=>'#eab308','red'=>'#ef4444',
    'stop'=>'#7c3aed','star'=>'#f59e0b',
];
$markEmojis = ['green'=>'🟢','yellow'=>'🟡','red'=>'🔴','stop'=>'⛔','star'=>'⭐'];
$dayNames   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
?>
<style>
.coach-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:22px 26px}
.stat-hero{font-size:2.4rem;font-weight:800;line-height:1}
.stat-sub{font-size:.82rem;color:var(--text-muted);margin-top:4px}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}
.cal-head{text-align:center;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);padding:4px 0}
.cal-cell{aspect-ratio:1;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:.75rem;font-weight:600;border:1.5px solid var(--border);cursor:default;position:relative;transition:.15s}
.cal-cell.today-cell{border-color:var(--accent);box-shadow:0 0 0 2px rgba(37,99,235,.25)}
a.cal-cell{cursor:pointer}
a.cal-cell:hover{border-color:var(--accent)!important;transform:scale(1.06)}
.cal-cell.empty{border-color:transparent;background:transparent}
.cal-cell .cal-emoji{font-size:1.1rem}
.cal-cell .cal-num{font-size:.7rem;color:var(--text-muted);margin-top:2px}
.cal-cell:hover .cal-tooltip{display:block}
.cal-tooltip{display:none;position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:8px 12px;white-space:nowrap;z-index:100;font-size:.78rem;box-shadow:0 4px 16px rgba(0,0,0,.25)}
.week-table th{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);padding:8px 12px}
.week-table td{padding:10px 12px;font-size:.88rem;border-top:1px solid var(--border)}
.ready-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-weight:700;font-size:.95rem}
.ready-badge.ready{background:rgba(34,197,94,.12);color:#22c55e;border:1.5px solid #22c55e}
.ready-badge.not-ready{background:rgba(239,68,68,.1);color:#ef4444;border:1.5px solid #ef4444}
.log-item{padding:12px 0;border-bottom:1px solid var(--border)}
.log-item:last-child{border-bottom:none}
.log-type-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.score-pill{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;font-weight:800;font-size:.9rem}
</style>

<div class="container-fluid px-4 py-4">

    <!-- Page header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 style="font-weight:700;font-size:1.5rem;margin:0">Coach Dashboard</h2>
            <div style="font-size:.85rem;color:var(--text-muted);margin-top:2px"><?= date('l, d M Y') ?></div>
        </div>
        <div class="d-flex gap-2">
            <a href="pre_checklist.php" class="btn btn-sm <?= $todayCheck ? 'btn-success' : 'btn-primary' ?>">
                <i class="fas fa-clipboard-check me-1"></i>
                <?= $todayCheck ? 'Checklist Done' : 'Pre-Trade Check' ?>
            </a>
            <a href="post_analysis.php" class="btn btn-sm <?= $todayPost ? 'btn-success' : 'btn-outline-secondary' ?>">
                <i class="fas fa-chart-line me-1"></i>
                <?= $todayPost ? 'Review Done' : 'Post-Trade Review' ?>
            </a>
        </div>
    </div>

    <!-- Top stat cards row -->
    <div class="row g-3 mb-4">
        <!-- Green streak -->
        <div class="col-6 col-md-3">
            <div class="coach-card text-center">
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Green Streak</div>
                <div class="stat-hero" style="color:#22c55e"><?= $greenStreak ?></div>
                <div class="stat-sub">consecutive disciplined days</div>
            </div>
        </div>
        <!-- Red streak -->
        <div class="col-6 col-md-3">
            <div class="coach-card text-center">
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Rule-Break Streak</div>
                <div class="stat-hero" style="color:#ef4444"><?= $redStreak ?></div>
                <div class="stat-sub">consecutive red/stop days</div>
            </div>
        </div>
        <!-- Discipline % -->
        <div class="col-6 col-md-3">
            <div class="coach-card text-center">
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Discipline Rate</div>
                <?php $dpColor = $disciplinePct>=70?'#22c55e':($disciplinePct>=50?'#eab308':'#ef4444') ?>
                <div class="stat-hero" style="color:<?= $dpColor ?>"><?= $disciplinePct ?>%</div>
                <div class="stat-sub">of <?= $totalDays ?> recorded days</div>
            </div>
        </div>
        <!-- Top emotion -->
        <div class="col-6 col-md-3">
            <div class="coach-card text-center">
                <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Top Weakness</div>
                <div style="font-size:1.4rem;font-weight:800;color:#f59e0b;margin-bottom:4px">
                    <?= $topEmotion ? ucfirst($topEmotion) : '—' ?>
                </div>
                <div class="stat-sub">most frequent emotional error</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Left col: Monthly calendar -->
        <div class="col-12 col-lg-7">
            <div class="coach-card">
                <!-- Month nav -->
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div style="font-weight:700;font-size:1rem"><?= date('F Y', strtotime($monthStart)) ?></div>
                    <div class="d-flex gap-2">
                        <a href="?m=<?= $prevM ?>" class="btn btn-sm btn-outline-secondary py-1 px-2">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php if ($nextM <= date('Y-m')): ?>
                        <a href="?m=<?= $nextM ?>" class="btn btn-sm btn-outline-secondary py-1 px-2">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Legend -->
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;font-size:.75rem">
                    <?php foreach ($markEmojis as $mk => $em): ?>
                    <span><?= $em ?> <?= ['green'=>'Rules OK','yellow'=>'Minor','red'=>'Major','stop'=>'Breakdown','star'=>'Perfect'][$mk] ?></span>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar grid -->
                <div class="cal-grid">
                    <?php foreach ($dayNames as $d): ?>
                    <div class="cal-head"><?= $d ?></div>
                    <?php endforeach; ?>

                    <?php
                    $firstDow = (int)date('N', strtotime($monthStart)); // 1=Mon
                    for ($i = 1; $i < $firstDow; $i++) {
                        echo '<div class="cal-cell empty"></div>';
                    }
                    $daysInMonth = (int)date('t', strtotime($monthStart));
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $dateStr = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
                        $isToday = $dateStr === $today;
                        $isFuture = $dateStr > $today;
                        $rec = $calMap[$dateStr] ?? null;

                        $link = 'post_analysis.php?date=' . $dateStr;
                        if ($isFuture) {
                            echo '<div class="cal-cell empty" style="background:var(--bg-base);opacity:.3">
                                    <span class="cal-num">' . $d . '</span>
                                  </div>';
                        } elseif ($rec && $rec['day_mark']) {
                            $color = $markColors[$rec['day_mark']];
                            $emoji = $markEmojis[$rec['day_mark']];
                            echo '<a href="' . $link . '" class="cal-cell' . ($isToday?' today-cell':'') . '"
                                    style="background:' . $color . '18;border-color:' . $color . '55;text-decoration:none">
                                    <span class="cal-emoji">' . $emoji . '</span>
                                    <span class="cal-num">' . $d . '</span>
                                    <div class="cal-tooltip">
                                        <strong>' . date('d M', strtotime($dateStr)) . '</strong><br>
                                        D:' . ($rec['discipline_score']??'—') . ' P:' . ($rec['psychology_score']??'—') . ' R:' . ($rec['risk_score']??'—') . '<br>
                                        Trades: ' . ($rec['total_trades']??0) . ' | P/L: ' . ($rec['net_pl'] !== null ? formatPL($rec['net_pl']) : '—') . '<br>
                                        <span style="color:var(--accent)">Click to view / edit / delete</span>
                                    </div>
                                  </a>';
                        } else {
                            echo '<a href="' . $link . '" class="cal-cell' . ($isToday?' today-cell':'') . '"
                                    style="background:var(--bg-base);color:var(--text-muted);text-decoration:none"
                                    title="Add review for ' . date('d M', strtotime($dateStr)) . '">
                                    <span class="cal-num">' . $d . '</span>
                                    ' . ($isToday ? '<span style="font-size:.6rem;color:var(--accent)">today</span>' : '<span style="font-size:.55rem;color:var(--border)">+add</span>') . '
                                  </a>';
                        }
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Right col: Capital readiness + week summary -->
        <div class="col-12 col-lg-5">
            <!-- Capital readiness -->
            <div class="coach-card mb-3">
                <div style="font-weight:700;font-size:.95rem;margin-bottom:12px">Capital Readiness</div>
                <div class="ready-badge <?= $readyToScale ? 'ready' : 'not-ready' ?>">
                    <?php if ($readyToScale): ?>
                        <i class="fas fa-check-circle"></i> Ready to Scale Capital
                    <?php else: ?>
                        <i class="fas fa-times-circle"></i> Not Ready to Scale
                    <?php endif; ?>
                </div>
                <div style="font-size:.83rem;color:var(--text-muted);margin-top:10px">
                    <?php if ($last10['cnt'] < 5): ?>
                        Need at least 5 reviewed days in the last 10 days. Currently <?= (int)$last10['cnt'] ?>.
                    <?php elseif (!$readyToScale): ?>
                        Average discipline score last 10 days: <strong><?= $avgDisc ?>/10</strong>.
                        Need ≥ 8.0 to unlock capital scaling.
                    <?php else: ?>
                        Average discipline score last 10 days: <strong style="color:#22c55e"><?= $avgDisc ?>/10</strong>.
                        Consistency confirmed — you may consider increasing lot size.
                    <?php endif; ?>
                </div>
            </div>

            <!-- This week's table -->
            <div class="coach-card">
                <div style="font-weight:700;font-size:.95rem;margin-bottom:12px">This Week</div>
                <?php if (empty($weekRows)): ?>
                <div style="color:var(--text-muted);font-size:.88rem">No reviews recorded this week yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="week-table w-100" style="border-collapse:collapse">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Mark</th>
                                <th style="text-align:center">D</th>
                                <th style="text-align:center">P</th>
                                <th style="text-align:center">R</th>
                                <th>P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($weekRows as $wr):
                            $mc = $markColors[$wr['day_mark']] ?? 'var(--text-muted)';
                            $em = $markEmojis[$wr['day_mark']] ?? '—';
                            $sc_d = (int)$wr['discipline_score'];
                            $sc_p = (int)$wr['psychology_score'];
                            $sc_r = (int)$wr['risk_score'];
                            $scColor = fn($s) => $s>=8?'#22c55e':($s>=5?'#eab308':'#ef4444');
                        ?>
                        <tr>
                            <td><?= date('D d', strtotime($wr['cal_date'])) ?></td>
                            <td><?= $em ?></td>
                            <td style="text-align:center"><span class="score-pill" style="background:<?= $scColor($sc_d) ?>22;color:<?= $scColor($sc_d) ?>"><?= $sc_d ?></span></td>
                            <td style="text-align:center"><span class="score-pill" style="background:<?= $scColor($sc_p) ?>22;color:<?= $scColor($sc_p) ?>"><?= $sc_p ?></span></td>
                            <td style="text-align:center"><span class="score-pill" style="background:<?= $scColor($sc_r) ?>22;color:<?= $scColor($sc_r) ?>"><?= $sc_r ?></span></td>
                            <td style="color:<?= ($wr['net_pl']??0)>=0?'#22c55e':'#ef4444' ?>;font-weight:700">
                                <?= $wr['net_pl'] !== null ? formatPL($wr['net_pl']) : '—' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:8px">D = Discipline  P = Psychology  R = Risk Mgmt</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent coaching logs -->
    <?php if (!empty($coachLogs)): ?>
    <div class="coach-card mt-3">
        <div style="font-weight:700;font-size:.95rem;margin-bottom:14px">Recent Coaching Notes</div>
        <?php
        $logColors = ['pre_trade'=>'#3b82f6','post_trade'=>'#22c55e','weekly_review'=>'#f59e0b','general'=>'#8b5cf6'];
        foreach ($coachLogs as $lg):
            $lc = $logColors[$lg['log_type']] ?? '#8b5cf6';
        ?>
        <div class="log-item">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span class="log-type-badge" style="background:<?=$lc?>18;color:<?=$lc?>;border:1px solid <?=$lc?>44">
                    <?= str_replace('_',' ',strtoupper($lg['log_type'])) ?>
                </span>
                <span style="font-size:.8rem;color:var(--text-muted)"><?= date('d M Y', strtotime($lg['log_date'])) ?></span>
            </div>
            <div style="font-size:.88rem;color:var(--text-primary);white-space:pre-line"><?= nl2br(htmlspecialchars(substr($lg['content'],0,300))) ?><?= strlen($lg['content'])>300?'…':'' ?></div>
            <?php if ($lg['coach_feedback']): ?>
            <div style="margin-top:8px;padding:8px 12px;background:rgba(99,102,241,.08);border-left:3px solid var(--accent);border-radius:0 6px 6px 0;font-size:.84rem;color:var(--text-secondary)">
                <strong style="color:var(--accent)">Coach:</strong> <?= nl2br(htmlspecialchars($lg['coach_feedback'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick coaching rules reminder -->
    <div class="coach-card mt-3" style="background:linear-gradient(135deg,rgba(37,99,235,.06),rgba(99,102,241,.06));border-color:rgba(37,99,235,.2)">
        <div style="font-weight:700;font-size:.9rem;margin-bottom:10px;color:var(--accent)">Active Coaching Rules</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:.83rem;color:var(--text-secondary)">
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>Max <?= MAX_TRADES_PER_DAY ?> trades/day</span>
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>Max <?= MAX_RISK_PER_TRADE_PCT ?>% risk/trade</span>
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>Stop at <?= formatUSD(MAX_DAILY_LOSS_DOLLAR) ?> daily loss</span>
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>No revenge trading</span>
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>No FOMO entries</span>
            <span><i class="fas fa-ban me-1" style="color:#ef4444"></i>No random entries</span>
            <span><i class="fas fa-check me-1" style="color:#22c55e"></i>Every trade needs Entry + SL + TP + Reason</span>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>

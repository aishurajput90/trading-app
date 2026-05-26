<?php
require_once '../config/db.php';
require_once '../includes/psych_helpers.php';
$pageTitle = 'Psychology Analytics';
$rootPath  = '../';
requireLogin();
$userId = getCurrentUserId();
$today     = date('Y-m-d');
$db        = getDB();

// ── Date range filter ─────────────────────────────────────────────────────────
$range     = in_array($_GET['range'] ?? '', ['7','30','90']) ? (int)$_GET['range'] : 30;
$fromDate  = date('Y-m-d', strtotime("-{$range} days", strtotime($today)));
$prevFrom  = date('Y-m-d', strtotime("-" . ($range * 2) . " days", strtotime($today)));
$prevTo    = date('Y-m-d', strtotime("-{$range} days - 1 day", strtotime($today)));

// ── All psych entries in range ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM psych_daily WHERE user_id=? AND entry_date BETWEEN ? AND ?
    ORDER BY entry_date ASC");
$stmt->execute([$userId, $fromDate, $today]);
$psychRows = $stmt->fetchAll();

// ── Averages ──────────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT
    AVG(discipline_score) as avg_d, AVG(psychology_score) as avg_p,
    AVG(emotional_stability) as avg_e, COUNT(*) as cnt
    FROM psych_daily WHERE user_id=? AND entry_date BETWEEN ? AND ?");
$stmt->execute([$userId, $fromDate, $today]);
$avgs = $stmt->fetch();

$stmt = $db->prepare("SELECT AVG(discipline_score) as avg_d FROM psych_daily
    WHERE user_id=? AND entry_date BETWEEN ? AND ?");
$stmt->execute([$userId, $prevFrom, $prevTo]);
$prevAvgD = (float)($stmt->fetch()['avg_d'] ?? 0);
$improvement = ($prevAvgD > 0 && $avgs['cnt'] > 0)
    ? round((float)$avgs['avg_d'] - $prevAvgD, 1) : null;

// ── Habit frequency ──────────────────────────────────────────────────────────
$habitFreq = array_fill_keys(array_keys(getHabitDefs()), 0);
foreach ($psychRows as $row) {
    $h = json_decode($row['habits_triggered'] ?? '[]', true) ?: [];
    foreach ($h as $code) {
        if (isset($habitFreq[$code])) $habitFreq[$code]++;
    }
}
arsort($habitFreq);
$topHabit = $habitFreq ? array_key_first($habitFreq) : null;

// ── Emotion distribution ──────────────────────────────────────────────────────
$emotionCount = [];
foreach ($psychRows as $row) {
    $e = $row['pre_emotion'];
    if ($e) $emotionCount[$e] = ($emotionCount[$e] ?? 0) + 1;
}

// ── Win rate vs discipline correlation ───────────────────────────────────────
$wr_brackets = ['0-39' => ['wins' => 0, 'total' => 0], '40-69' => ['wins' => 0, 'total' => 0], '70-100' => ['wins' => 0, 'total' => 0]];
foreach ($psychRows as $row) {
    if ($row['discipline_score'] === null) continue;
    $d = (int)$row['discipline_score'];
    $bracket = $d < 40 ? '0-39' : ($d < 70 ? '40-69' : '70-100');

    $stmt2 = $db->prepare("SELECT COUNT(*) as total,
        SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as wins
        FROM trades WHERE user_id=? AND DATE(trade_datetime)=?");
    $stmt2->execute([$userId, $row['entry_date']]);
    $td = $stmt2->fetch();
    if ($td['total'] > 0) {
        $wr_brackets[$bracket]['wins']  += (int)$td['wins'];
        $wr_brackets[$bracket]['total'] += (int)$td['total'];
    }
}
$wr_rates = [];
foreach ($wr_brackets as $k => $v) {
    $wr_rates[$k] = $v['total'] > 0 ? round($v['wins'] / $v['total'] * 100, 1) : 0;
}

// ── Discipline score trend (daily) ────────────────────────────────────────────
$trendDates  = [];
$trendScores = [];
foreach ($psychRows as $row) {
    $trendDates[]  = date('d M', strtotime($row['entry_date']));
    $trendScores[] = $row['discipline_score'] !== null ? (int)$row['discipline_score'] : null;
}

// ── Weekly heatmap data ───────────────────────────────────────────────────────
$heatmapData = [];
foreach ($psychRows as $row) {
    $heatmapData[$row['entry_date']] = [
        'disc'  => $row['discipline_score'],
        'psych' => $row['psychology_score'],
    ];
}

// ── Longest clean streak (any habit) ─────────────────────────────────────────
$noHabitStreak = 0; $curStreak = 0;
foreach ($psychRows as $row) {
    $h = json_decode($row['habits_triggered'] ?? '[]', true) ?: [];
    if (empty($h)) { $curStreak++; $noHabitStreak = max($noHabitStreak, $curStreak); }
    else $curStreak = 0;
}

// ── RR consistency weekly ────────────────────────────────────────────────────
$weekRR = [];
for ($w = 0; $w < 4; $w++) {
    $wStart = date('Y-m-d', strtotime("-" . (($w + 1) * 7 - 1) . " days", strtotime($today)));
    $wEnd   = date('Y-m-d', strtotime("-" . ($w * 7) . " days", strtotime($today)));
    $stmt = $db->prepare("SELECT COUNT(*) as total,
        SUM(CASE WHEN tp_amount > 0 AND sl_amount > 0 AND (tp_amount/sl_amount) >= 2 THEN 1 ELSE 0 END) as good_rr
        FROM trades WHERE user_id=? AND DATE(trade_datetime) BETWEEN ? AND ?");
    $stmt->execute([$userId, $wStart, $wEnd]);
    $r = $stmt->fetch();
    $pct = $r['total'] > 0 ? round($r['good_rr'] / $r['total'] * 100) : 0;
    $weekRR[] = ['label' => 'W-' . ($w + 1) . ' ' . date('d M', strtotime($wStart)), 'pct' => $pct];
}
$weekRR = array_reverse($weekRR);

// ── Avg trade quality in range ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT AVG(overall_score) as avg_tq, COUNT(*) as cnt
    FROM psych_trade_quality WHERE user_id=? AND entry_date BETWEEN ? AND ?");
$stmt->execute([$userId, $fromDate, $today]);
$tqAvg = $stmt->fetch();

$habitDefs = getHabitDefs();

include '../includes/header.php';
?>

<style>
/* ── Psychology Analytics — Premium UI ── */
.ana-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px;
    height: 100%;
}
.ana-card-hdr {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ana-card-hdr i { font-size: 12px; }
.ana-ring-wrap { position: relative; width: 130px; height: 130px; margin: 0 auto 10px; }
.ana-ring-wrap canvas { display: block; }
.ana-ring-center {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    text-align: center; pointer-events: none;
}
.ana-ring-center .arc-val { font-size: 26px; font-weight: 800; font-family: 'DM Mono', monospace; }
.ana-ring-center .arc-lbl { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; }
.stat-chip {
    display: inline-flex; flex-direction: column; align-items: center;
    background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
    padding: 12px 18px; min-width: 100px; text-align: center;
    transition: transform .15s, box-shadow .15s;
}
.stat-chip:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
.stat-chip .sc-val { font-size: 20px; font-weight: 800; font-family: 'DM Mono', monospace; }
.stat-chip .sc-lbl { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-top: 3px; }
.heatmap-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.hm-cell {
    aspect-ratio: 1; border-radius: 5px;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; cursor: default;
    border: 1px solid transparent;
    transition: transform .15s;
}
.hm-cell:hover { transform: scale(1.25); z-index: 10; }
.range-btn { font-size: 12px; font-weight: 700; border-radius: 10px; padding: 6px 16px; }
.range-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 3px 12px rgba(37,99,235,.3); }
</style>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3">
        <div style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,rgba(99,102,241,.2),rgba(37,99,235,.2));display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-brain" style="font-size:18px;color:#6366f1"></i>
        </div>
        <div>
            <h5 style="font-size:1.05rem;font-weight:800;margin:0 0 2px">Psychology Analytics</h5>
            <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span><i class="fas fa-calendar-range me-1" style="font-size:10px"></i>Last <?= $range ?> days</span>
                <span style="opacity:.4">·</span>
                <span><?= $avgs['cnt'] ?> entries</span>
                <?php if ($improvement !== null): ?>
                <span style="opacity:.4">·</span>
                <span style="color:<?= $improvement >= 0 ? 'var(--profit)' : 'var(--loss)' ?>;font-weight:700">
                    <i class="fas fa-arrow-<?= $improvement >= 0 ? 'up' : 'down' ?>"></i>
                    <?= abs($improvement) ?> vs prev period
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php foreach ([7, 30, 90] as $r): ?>
        <a href="?range=<?= $r ?>" class="btn btn-sm range-btn <?= $range === $r ? 'active' : 'btn-outline-secondary' ?>">
            <?= $r ?>d
        </a>
        <?php endforeach; ?>
        <a href="psych_tracker.php" class="btn btn-sm btn-outline-secondary" style="border-radius:10px">
            <i class="fas fa-arrow-left me-1"></i>Tracker
        </a>
    </div>
</div>

<?php if ($avgs['cnt'] === 0): ?>
<div class="text-center py-5" style="color:var(--text-muted)">
    <i class="fas fa-chart-mixed" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3"></i>
    <p style="font-size:15px">No psychology entries in the selected period.</p>
    <a href="psych_daily.php" class="btn btn-primary mt-2">Log Your First Entry</a>
</div>
<?php include '../includes/footer.php'; ?>
<?php exit; endif; ?>

<!-- ── Row 1: Score Progress Rings ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $ringData = [
        ['Discipline',          round($avgs['avg_d']), 'disc_ring'],
        ['Psychology',          round($avgs['avg_p']), 'psych_ring'],
        ['Emotional Stability', round($avgs['avg_e']), 'emo_ring'],
    ];
    if ($tqAvg['cnt'] > 0) {
        $ringData[] = ['Trade Quality', round($tqAvg['avg_tq']), 'tq_ring'];
    }
    foreach ($ringData as [$rlabel, $rval, $rid]):
        $rcolor = getScoreColor($rval);
        $rlbl2  = getScoreLabel($rval);
    ?>
    <div class="col-6 col-lg-3">
        <div class="ana-card text-center" style="transition:transform .2s,box-shadow .2s" onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(0,0,0,.15)'" onmouseleave="this.style.transform='';this.style.boxShadow=''">
            <div class="ana-ring-wrap">
                <canvas id="<?= $rid ?>" width="130" height="130"></canvas>
                <div class="ana-ring-center">
                    <div class="arc-val" style="color:<?= $rcolor ?>"><?= $rval ?></div>
                    <div class="arc-lbl"><?= $rlbl2 ?></div>
                </div>
            </div>
            <div style="font-size:13px;font-weight:800"><?= $rlabel ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px">Avg last <?= $range ?> days</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Row 2: Quick Stats ────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-3 mb-4">
    <div class="stat-chip">
        <div class="sc-val"><?= $avgs['cnt'] ?></div>
        <div class="sc-lbl">Entries</div>
    </div>
    <div class="stat-chip">
        <div class="sc-val" style="color:<?= $habitFreq[$topHabit] ?? 0 > 0 ? 'var(--warning)' : 'var(--profit)' ?>">
            <?= $topHabit ? ($habitDefs[$topHabit]['label'] ?? $topHabit) : 'None' ?>
        </div>
        <div class="sc-lbl">Top Bad Habit</div>
    </div>
    <div class="stat-chip">
        <div class="sc-val" style="color:var(--profit)"><?= $noHabitStreak ?>d</div>
        <div class="sc-lbl">Best Clean Streak</div>
    </div>
    <?php if ($improvement !== null): ?>
    <div class="stat-chip">
        <div class="sc-val" style="color:<?= $improvement >= 0 ? 'var(--profit)' : 'var(--loss)' ?>">
            <?= ($improvement >= 0 ? '+' : '') . $improvement ?>
        </div>
        <div class="sc-lbl">vs Prev Period</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Row 3: Discipline Trend + Habit Frequency ─────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-lg-7">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-chart-line"></i>Discipline Score Trend</div>
            <canvas id="trendChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-ranking-star"></i>Most Common Bad Habits</div>
            <canvas id="habitChart" height="220"></canvas>
        </div>
    </div>
</div>

<!-- ── Row 4: Emotion Distribution + Win Rate vs Discipline ─────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-5">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-face-smile"></i>Emotion Distribution</div>
            <?php if (!empty($emotionCount)): ?>
            <canvas id="emoChart" height="220"></canvas>
            <?php else: ?>
            <div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:13px">No emotion data yet</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-md-7">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-arrows-left-right"></i>Win Rate vs Discipline Level</div>
            <canvas id="wrChart" height="220"></canvas>
            <div style="font-size:11px;color:var(--text-muted);margin-top:10px">
                <i class="fas fa-circle-info me-1"></i>Higher discipline correlates with better win rate.
            </div>
        </div>
    </div>
</div>

<!-- ── Row 5: Weekly Heatmap ─────────────────────────────────────────────────── -->
<div class="ana-card mb-4">
    <div class="ana-card-hdr"><i class="fas fa-calendar-week"></i>Discipline Score Heatmap</div>
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px">Each cell = one day. Color intensity = discipline score.</div>
    <?php
    // Build 7-column week grid
    $heatStart = date('Y-m-d', strtotime('monday this week -' . (min($range, 60) - 1) . ' days'));
    $heatEnd   = $today;
    $dayMs   = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $heatCells = [];
    $cur = $heatStart;
    while ($cur <= $heatEnd) {
        $heatCells[] = $cur;
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }
    ?>
    <div style="display:flex;gap:4px;margin-bottom:6px">
        <?php foreach ($dayMs as $dm): ?>
        <div style="flex:1;text-align:center;font-size:10px;font-weight:700;color:var(--text-muted)"><?= $dm ?></div>
        <?php endforeach; ?>
    </div>
    <div class="heatmap-grid">
        <?php
        // Fill leading empty cells for first week
        $firstDayOfWeek = (int)date('N', strtotime($heatStart)); // 1=Mon, 7=Sun
        for ($pad = 1; $pad < $firstDayOfWeek; $pad++):
        ?>
        <div></div>
        <?php endfor; ?>
        <?php foreach ($heatCells as $day):
            $d  = $heatmapData[$day] ?? null;
            $sc = $d ? (int)$d['disc'] : null;
            if ($sc === null) {
                $bg   = 'var(--border)';
                $tc   = 'transparent';
                $title = $day . ': No entry';
            } elseif ($sc >= 80) {
                $bg   = 'rgba(22,163,74,.75)'; $tc = '#fff'; $title = $day . ': ' . $sc;
            } elseif ($sc >= 65) {
                $bg   = 'rgba(22,163,74,.45)'; $tc = 'var(--text-primary)'; $title = $day . ': ' . $sc;
            } elseif ($sc >= 50) {
                $bg   = 'rgba(217,119,6,.5)'; $tc = '#fff'; $title = $day . ': ' . $sc;
            } else {
                $bg   = 'rgba(220,38,38,.55)'; $tc = '#fff'; $title = $day . ': ' . $sc;
            }
            $isToday = $day === $today;
        ?>
        <div class="hm-cell" title="<?= $title ?>"
             style="background:<?= $bg ?>;color:<?= $tc ?>;<?= $isToday ? 'outline:2px solid var(--accent);outline-offset:1px;' : '' ?>">
            <?= date('j', strtotime($day)) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="d-flex gap-3 mt-3 flex-wrap">
        <?php foreach ([['rgba(22,163,74,.75)', '80-100 Excellent'], ['rgba(22,163,74,.45)', '65-79 Good'], ['rgba(217,119,6,.5)', '50-64 Fair'], ['rgba(220,38,38,.55)', '<50 Poor'], ['var(--border)', 'No entry']] as [$c, $lbl]): ?>
        <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted)">
            <div style="width:12px;height:12px;border-radius:2px;background:<?= $c ?>"></div>
            <?= $lbl ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Row 6: RR Consistency + Habit Detail ──────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-5">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-scale-balanced"></i>RR Consistency (Last 4 Weeks)</div>
            <canvas id="rrChart" height="200"></canvas>
            <div style="font-size:11px;color:var(--text-muted);margin-top:10px">
                <i class="fas fa-circle-info me-1"></i>% of trades meeting 1:2 RR minimum
            </div>
        </div>
    </div>
    <div class="col-12 col-md-7">
        <div class="ana-card">
            <div class="ana-card-hdr"><i class="fas fa-list-ul"></i>Habit Detail Breakdown</div>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($habitDefs as $code => $def):
                    $freq    = $habitFreq[$code] ?? 0;
                    $maxFreq = max(1, max($habitFreq));
                    $barW    = round($freq / $maxFreq * 100);
                    $streak  = getPsychStreak($userId, $code, $db);
                ?>
                <div>
                    <div class="d-flex justify-content-between" style="font-size:12px;font-weight:600;margin-bottom:3px">
                        <span><i class="<?= $def['icon'] ?> me-1" style="font-size:11px;color:<?= $def['color'] ?>"></i><?= $def['label'] ?></span>
                        <span style="color:var(--text-muted)"><?= $freq ?>× · <?= $streak ?>d clean</span>
                    </div>
                    <div style="background:var(--border);border-radius:99px;height:5px;overflow:hidden">
                        <div style="height:100%;width:<?= $barW ?>%;background:<?= $def['color'] ?>;border-radius:99px;transition:.5s"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
(function () {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var grid   = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
    var txt    = isDark ? '#94a3b8' : '#64748b';

    var baseOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: grid }, ticks: { color: txt, font: { size: 10 } } },
            y: { grid: { color: grid }, ticks: { color: txt, font: { size: 10 } }, beginAtZero: true }
        }
    };

    function trackColor() {
        return isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)';
    }

    // ── Progress rings ────────────────────────────────────────────────────────
    function drawRing(id, score, color) {
        var canvas = document.getElementById(id);
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [score, 100 - score],
                    backgroundColor: [color, trackColor()],
                    borderWidth: 0,
                }]
            },
            options: {
                cutout: '72%',
                animation: { animateRotate: true, duration: 1200 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                events: [],
            }
        });
    }

    <?php foreach ($ringData as [$rlabel, $rval, $rid]): ?>
    drawRing('<?= $rid ?>', <?= $rval ?>, '<?= getScoreColor($rval) ?>');
    <?php endforeach; ?>

    // ── Discipline Trend ──────────────────────────────────────────────────────
    (function () {
        var ctx = document.getElementById('trendChart');
        if (!ctx) return;
        var dates  = <?= json_encode($trendDates) ?>;
        var scores = <?= json_encode($trendScores) ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    data: scores,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,.1)',
                    fill: true,
                    tension: .4,
                    pointRadius: 4,
                    pointBackgroundColor: '#2563eb',
                    spanGaps: true,
                }]
            },
            options: Object.assign({}, baseOpts, {
                scales: Object.assign({}, baseOpts.scales, {
                    y: Object.assign({}, baseOpts.scales.y, { min: 0, max: 100 })
                })
            })
        });
    })();

    // ── Habit Frequency ───────────────────────────────────────────────────────
    (function () {
        var ctx = document.getElementById('habitChart');
        if (!ctx) return;
        var habitLabels = <?= json_encode(array_map(fn($d) => $d['label'], $habitDefs)) ?>;
        var habitCounts = <?= json_encode(array_values($habitFreq)) ?>;
        var habitColors = <?= json_encode(array_values(array_map(fn($d) => $d['color'], $habitDefs))) ?>;

        // Filter to only habits with triggers for cleaner chart
        var filtered = habitLabels.map(function(l, i) {
            return { label: l, count: habitCounts[i], color: habitColors[i] };
        }).filter(function(d) { return d.count > 0; })
          .sort(function(a, b) { return b.count - a.count; });

        if (filtered.length === 0) {
            ctx.parentNode.innerHTML += '<div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:13px">No bad habits triggered in this period 🎉</div>';
            return;
        }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: filtered.map(function(d) { return d.label; }),
                datasets: [{
                    data: filtered.map(function(d) { return d.count; }),
                    backgroundColor: filtered.map(function(d) { return d.color + 'cc'; }),
                    borderColor:     filtered.map(function(d) { return d.color; }),
                    borderWidth: 1,
                    borderRadius: 6,
                }]
            },
            options: Object.assign({}, baseOpts, {
                indexAxis: 'y',
                scales: Object.assign({}, baseOpts.scales, {
                    x: Object.assign({}, baseOpts.scales.x, { ticks: Object.assign({}, baseOpts.scales.x.ticks, { stepSize: 1 }) })
                })
            })
        });
    })();

    // ── Emotion Distribution ─────────────────────────────────────────────────
    (function () {
        var ctx = document.getElementById('emoChart');
        if (!ctx) return;
        var emojis = {calm:'🧘',confident:'💪',fear:'😨',greedy:'🤑',angry:'😤',fomo:'🏃',revenge:'😡'};
        var emoData = <?= json_encode($emotionCount) ?>;
        var labels  = Object.keys(emoData).map(function(k) { return (emojis[k] || '') + ' ' + k.charAt(0).toUpperCase() + k.slice(1); });
        var values  = Object.values(emoData);
        var colors  = ['#16a34a','#2563eb','#6366f1','#f59e0b','#ef4444','#f97316','#dc2626'];
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors.slice(0, values.length), borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { color: txt, font: { size: 11 }, padding: 10 } },
                    tooltip: { enabled: true },
                }
            }
        });
    })();

    // ── Win Rate vs Discipline ───────────────────────────────────────────────
    (function () {
        var ctx = document.getElementById('wrChart');
        if (!ctx) return;
        var brackets = ['0–39 (Poor)', '40–69 (Fair)', '70–100 (Strong)'];
        var rates    = <?= json_encode(array_values($wr_rates)) ?>;
        var bgColors = ['rgba(220,38,38,.6)', 'rgba(217,119,6,.6)', 'rgba(22,163,74,.6)'];
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: brackets,
                datasets: [{
                    label: 'Win Rate %',
                    data: rates,
                    backgroundColor: bgColors,
                    borderColor: bgColors.map(function(c) { return c.replace('.6', '1'); }),
                    borderWidth: 1,
                    borderRadius: 8,
                }]
            },
            options: Object.assign({}, baseOpts, {
                plugins: Object.assign({}, baseOpts.plugins, {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(c) { return c.raw + '%'; } } }
                }),
                scales: Object.assign({}, baseOpts.scales, {
                    y: Object.assign({}, baseOpts.scales.y, { min: 0, max: 100, ticks: Object.assign({}, baseOpts.scales.y.ticks, { callback: function(v) { return v + '%'; } }) })
                })
            })
        });
    })();

    // ── RR Consistency ───────────────────────────────────────────────────────
    (function () {
        var ctx = document.getElementById('rrChart');
        if (!ctx) return;
        var rrWeeks = <?= json_encode(array_column($weekRR, 'label')) ?>;
        var rrPcts  = <?= json_encode(array_column($weekRR, 'pct')) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: rrWeeks,
                datasets: [{
                    data: rrPcts,
                    backgroundColor: 'rgba(37,99,235,.55)',
                    borderColor: '#2563eb',
                    borderWidth: 1,
                    borderRadius: 6,
                }]
            },
            options: Object.assign({}, baseOpts, {
                plugins: Object.assign({}, baseOpts.plugins, {
                    tooltip: { callbacks: { label: function(c) { return c.raw + '% meet 1:2 RR'; } } }
                }),
                scales: Object.assign({}, baseOpts.scales, {
                    y: Object.assign({}, baseOpts.scales.y, { min: 0, max: 100, ticks: Object.assign({}, baseOpts.scales.y.ticks, { callback: function(v) { return v + '%'; } }) })
                })
            })
        });
    })();
})();
</script>

<?php include '../includes/footer.php'; ?>

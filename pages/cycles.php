<?php
require_once '../config/db.php';
$cycles    = getCapitalCycles(DEFAULT_USER_ID);
$pageTitle = 'Capital Cycles';
$rootPath  = '../';
include '../includes/header.php';

$closedCycles  = array_filter($cycles, fn($c) => !$c['is_active']);
$activeCycle   = array_values(array_filter($cycles, fn($c) => $c['is_active']))[0] ?? null;
$totalCapWiped = array_sum(array_column(array_values($closedCycles), 'stop_out_amount'));
?>

<div class="az-wrap" style="max-width:900px">

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-rotate"></i></div>
            <div class="stat-value"><?= count($cycles) ?></div>
            <div class="stat-label">Total Cycles</div>
            <div class="stat-sub"><?= count($closedCycles) ?> closed · <?= $activeCycle ? 1 : 0 ?> active</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card loss">
            <div class="stat-icon loss"><i class="fas fa-power-off"></i></div>
            <div class="stat-value negative"><?= count($closedCycles) ?></div>
            <div class="stat-label">Stop Out Events</div>
            <div class="stat-sub">Total wiped: <?= formatUSD($totalCapWiped) ?></div>
        </div>
    </div>
    <div class="col-sm-4">
        <?php if ($activeCycle && $activeCycle['start_date']): ?>
        <div class="stat-card profit">
            <div class="stat-icon profit"><i class="fas fa-circle-dot"></i></div>
            <div class="stat-value positive"><?= formatUSD($activeCycle['total_deposits'] - $activeCycle['total_withdrawals']) ?></div>
            <div class="stat-label">Active Capital</div>
            <div class="stat-sub">Cycle #<?= $activeCycle['cycle_number'] ?> · <?= $activeCycle['duration_days'] ?> days running</div>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-circle-dot"></i></div>
            <div class="stat-value">—</div>
            <div class="stat-label">No Active Capital</div>
            <div class="stat-sub">Add funds to start a new cycle</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($cycles)): ?>
<div class="az-empty">
    <i class="fas fa-rotate" style="font-size:32px;color:var(--text-muted);display:block;margin-bottom:12px"></i>
    <strong style="font-size:16px;color:var(--text-secondary);display:block;margin-bottom:6px">No capital cycles yet</strong>
    <p>Add funds in the <a href="funds.php">Fund Manager</a> to begin tracking your first cycle.</p>
</div>
<?php else: ?>

<div class="az-section-lbl">Trading History by Capital Cycle <span></span></div>

<div class="cycle-timeline">
<?php foreach ($cycles as $c):
    $cFrom       = $c['start_date'] ?? date('Y-m-d');
    $cTo         = $c['end_date']   ?? date('Y-m-d');
    $analyzerUrl = 'analysis.php?from=' . $cFrom . '&to=' . $cTo;
?>
<div class="cycle-card <?= $c['is_active'] ? 'is-active' : '' ?>">

    <div class="cycle-card-header">
        <div style="display:flex;align-items:center;gap:10px">
            <span class="cycle-badge <?= $c['is_active'] ? 'active' : 'closed' ?>">
                <?= $c['is_active'] ? '● ACTIVE' : '✕ CLOSED' ?>
            </span>
            <span style="font-size:15px;font-weight:700;color:var(--text-primary)">Cycle #<?= $c['cycle_number'] ?></span>
            <span style="font-size:12px;color:var(--text-muted)"><?= $c['duration_days'] ?> day<?= $c['duration_days'] !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($c['start_date']): ?>
        <a href="<?= $analyzerUrl ?>" class="btn-secondary-custom" style="padding:6px 14px;font-size:12px">
            <i class="fas fa-chart-bar"></i> Analyze
        </a>
        <?php endif; ?>
    </div>

    <!-- Date timeline bar -->
    <div class="cycle-datebar">
        <div class="cycle-date-pin">
            <span class="pin-dot green"></span>
            <div>
                <div class="pin-label">Started</div>
                <div class="pin-value"><?= $c['start_date'] ? date('d M Y', strtotime($c['start_date'])) : 'No deposits yet' ?></div>
            </div>
        </div>
        <div class="cycle-line"></div>
        <div class="cycle-date-pin" style="text-align:right">
            <div>
                <div class="pin-label"><?= $c['is_active'] ? 'Ongoing' : 'Stopped Out' ?></div>
                <div class="pin-value <?= $c['is_active'] ? '' : 'text-loss' ?>">
                    <?= $c['is_active'] ? date('d M Y') . ' ↗' : date('d M Y', strtotime($c['end_date'])) ?>
                </div>
            </div>
            <span class="pin-dot <?= $c['is_active'] ? 'blue' : 'red' ?>"></span>
        </div>
    </div>

    <!-- Stats -->
    <div class="cycle-stats-grid">
        <div class="cycle-stat">
            <div class="cs-label">Capital Added</div>
            <div class="cs-value"><?= formatUSD($c['total_deposits']) ?></div>
        </div>
        <div class="cycle-stat">
            <div class="cs-label">Withdrawn</div>
            <div class="cs-value"><?= formatUSD($c['total_withdrawals']) ?></div>
        </div>
        <div class="cycle-stat">
            <div class="cs-label">Net P&amp;L</div>
            <div class="cs-value <?= $c['net_pl'] >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($c['net_pl']) ?></div>
        </div>
        <div class="cycle-stat">
            <div class="cs-label">Trades</div>
            <div class="cs-value"><?= $c['trade_count'] ?></div>
            <div class="cs-sub"><?= $c['wins'] ?>W / <?= $c['losses'] ?>L</div>
        </div>
        <div class="cycle-stat">
            <div class="cs-label">Win Rate</div>
            <div class="cs-value <?= $c['win_rate'] >= 50 ? 'positive' : ($c['win_rate'] >= 40 ? '' : ($c['trade_count'] > 0 ? 'negative' : '')) ?>"><?= $c['win_rate'] ?>%</div>
        </div>
        <?php if (!$c['is_active']): ?>
        <div class="cycle-stat" style="border-left:2px solid var(--loss);padding-left:14px">
            <div class="cs-label">Capital Wiped</div>
            <div class="cs-value negative">-<?= formatUSD($c['stop_out_amount']) ?></div>
            <?php if ($c['stop_out_note']): ?>
            <div class="cs-sub" title="<?= htmlspecialchars($c['stop_out_note']) ?>"><?= htmlspecialchars(mb_strimwidth($c['stop_out_note'], 0, 28, '…')) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /cycle-card -->
<?php endforeach; ?>
</div><!-- /cycle-timeline -->
<?php endif; ?>

</div><!-- /az-wrap -->

<style>
.cycle-timeline { display:flex; flex-direction:column; gap:16px; }

.cycle-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 24px;
}
.cycle-card.is-active {
    border-color: rgba(37,99,235,.4);
    box-shadow: 0 0 0 1px rgba(37,99,235,.12);
}
.cycle-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }

.cycle-badge { font-size:10px; font-weight:700; letter-spacing:.6px; padding:3px 9px; border-radius:20px; }
.cycle-badge.active { background:rgba(37,99,235,.15); color:var(--accent); }
.cycle-badge.closed { background:rgba(220,38,38,.12); color:var(--loss); }

.cycle-datebar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding: 12px 16px;
    background: var(--bg-elevated);
    border-radius: var(--radius-sm);
}
.cycle-date-pin { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.cycle-line { flex:1; height:2px; background:var(--border); position:relative; }
.cycle-line::after { content:''; position:absolute; right:0; top:-3px; border:4px solid transparent; border-left-color:var(--border); }

.pin-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.pin-dot.green { background:var(--profit); box-shadow:0 0 0 3px rgba(22,163,74,.2); }
.pin-dot.red   { background:var(--loss);   box-shadow:0 0 0 3px rgba(220,38,38,.2); }
.pin-dot.blue  { background:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.2); }
.pin-label { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; }
.pin-value { font-size:13px; font-weight:600; color:var(--text-primary); margin-top:1px; }
.pin-value.text-loss { color:var(--loss); }

.cycle-stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(108px,1fr)); gap:10px; }
.cycle-stat { padding:10px 12px; background:var(--bg-elevated); border-radius:var(--radius-sm); }
.cs-label { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.cs-value { font-size:15px; font-weight:700; font-family:var(--font-mono); color:var(--text-primary); }
.cs-value.positive { color:var(--profit); }
.cs-value.negative { color:var(--loss); }
.cs-sub { font-size:11px; color:var(--text-muted); margin-top:3px; }
</style>

<?php include '../includes/footer.php'; ?>

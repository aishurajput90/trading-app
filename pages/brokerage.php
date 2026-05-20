<?php
require_once '../config/db.php';
$db     = getDB();
$userId = DEFAULT_USER_ID;

// ── Filters ───────────────────────────────────────────────────────────────
$filterSymbol = trim($_GET['symbol'] ?? '');
$filterFrom   = $_GET['from']   ?? '';
$filterTo     = $_GET['to']     ?? '';
$sortBy       = $_GET['sort']   ?? 'date_desc';

if ($filterFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if ($filterTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';

$sortMap = [
    'date_desc' => 'day DESC',
    'date_asc'  => 'day ASC',
    'brok_desc' => 'total_brok DESC',
    'brok_asc'  => 'total_brok ASC',
    'pct_desc'  => 'brok_pct DESC',
    'net_asc'   => 'net_pl ASC',
];
$orderBy = $sortMap[$sortBy] ?? 'day DESC';

// ── WHERE (only trades that have brokerage) ───────────────────────────────
$where  = "WHERE user_id = ? AND brokerage > 0";
$params = [$userId];
if ($filterSymbol) { $where .= " AND symbol LIKE ?";             $params[] = "%$filterSymbol%"; }
if ($filterFrom)   { $where .= " AND DATE(trade_datetime) >= ?"; $params[] = $filterFrom; }
if ($filterTo)     { $where .= " AND DATE(trade_datetime) <= ?"; $params[] = $filterTo; }

// ── Daily grouped brokerage ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        DATE(trade_datetime)                                             AS day,
        COUNT(*)                                                         AS cnt,
        COALESCE(SUM(brokerage), 0)                                      AS total_brok,
        COALESCE(SUM(profit_loss), 0)                                    AS gross_pl,
        COALESCE(SUM(profit_loss - brokerage + swap), 0)                 AS net_pl,
        ROUND(SUM(brokerage) / NULLIF(ABS(SUM(profit_loss)), 0) * 100, 1) AS brok_pct,
        GROUP_CONCAT(DISTINCT symbol ORDER BY symbol SEPARATOR ', ')     AS symbols
    FROM trades $where
    GROUP BY DATE(trade_datetime)
    ORDER BY $orderBy
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Summary figures ───────────────────────────────────────────────────────
$totalBrok   = array_sum(array_column($rows, 'total_brok'));
$totalGross  = array_sum(array_column($rows, 'gross_pl'));
$totalNet    = array_sum(array_column($rows, 'net_pl'));
$totalDays   = count($rows);
$totalTrades = array_sum(array_column($rows, 'cnt'));
$avgPerDay   = $totalDays   > 0 ? $totalBrok / $totalDays   : 0;
$avgPerTrade = $totalTrades > 0 ? $totalBrok / $totalTrades : 0;

// Highest brokerage day in current result set
$maxBrokRow = null;
foreach ($rows as $r) {
    if (!$maxBrokRow || (float)$r['total_brok'] > (float)$maxBrokRow['total_brok']) $maxBrokRow = $r;
}
$maxBrok = $maxBrokRow ? (float)$maxBrokRow['total_brok'] : 1;

// All-time highest single brokerage day (ignores date/symbol filter — for context)
$atStmt = $db->prepare("
    SELECT DATE(trade_datetime) AS day, SUM(brokerage) AS total_brok
    FROM trades WHERE user_id = ? AND brokerage > 0
    GROUP BY DATE(trade_datetime) ORDER BY total_brok DESC LIMIT 1
");
$atStmt->execute([$userId]);
$allTimeBrokRow = $atStmt->fetch();

$hasFilter = $filterSymbol || $filterFrom || $filterTo;

$pageTitle = 'Brokerage';
$rootPath  = '../';
include '../includes/header.php';
?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card loss">
            <div class="stat-icon loss"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="stat-value negative"><?= formatUSD($totalBrok) ?></div>
            <div class="stat-label">Total Brokerage<?= $hasFilter ? ' <small style="font-size:9px;color:var(--accent)">(filtered)</small>' : '' ?></div>
            <div class="stat-sub"><?= $totalDays ?> day<?= $totalDays !== 1 ? 's' : '' ?> · <?= $totalTrades ?> trade<?= $totalTrades !== 1 ? 's' : '' ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left:3px solid var(--warning)">
            <div class="stat-icon" style="background:rgba(217,119,6,.15);color:var(--warning)"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-value" style="color:var(--warning)"><?= formatUSD($avgPerDay) ?></div>
            <div class="stat-label">Avg Brokerage / Day</div>
            <div class="stat-sub"><?= formatUSD($avgPerTrade) ?> per trade avg</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $totalGross >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $totalGross >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-chart-simple"></i></div>
            <div class="stat-value <?= $totalGross >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($totalGross) ?></div>
            <div class="stat-label">Gross P&amp;L</div>
            <div class="stat-sub">Net after brok: <strong class="<?= $totalNet >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($totalNet) ?></strong></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php if ($allTimeBrokRow): ?>
        <div class="stat-card" style="border-left:3px solid var(--accent-purple)">
            <div class="stat-icon" style="background:rgba(124,58,237,.15);color:var(--accent-purple)"><i class="fas fa-crown"></i></div>
            <div class="stat-value" style="color:var(--accent-purple);font-size:16px">-<?= formatUSD($allTimeBrokRow['total_brok']) ?></div>
            <div class="stat-label">Highest Brok Day (All Time)</div>
            <div class="stat-sub"><?= date('d M Y', strtotime($allTimeBrokRow['day'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="panel mb-4">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-sliders"></i> Filter &amp; Sort</div>
        <?php if ($hasFilter): ?>
        <a href="brokerage.php" style="font-size:12px;color:var(--text-muted)">&#10005; Clear filters</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Symbol</label>
                <input type="text" class="form-control" name="symbol"
                       value="<?= htmlspecialchars($filterSymbol) ?>"
                       placeholder="XAUUSD, EURUSD…" style="text-transform:uppercase">
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Sort By</label>
                <select class="form-control" name="sort">
                    <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Date — Newest first</option>
                    <option value="date_asc"  <?= $sortBy === 'date_asc'  ? 'selected' : '' ?>>Date — Oldest first</option>
                    <option value="brok_desc" <?= $sortBy === 'brok_desc' ? 'selected' : '' ?>>Brokerage — Highest first</option>
                    <option value="brok_asc"  <?= $sortBy === 'brok_asc'  ? 'selected' : '' ?>>Brokerage — Lowest first</option>
                    <option value="pct_desc"  <?= $sortBy === 'pct_desc'  ? 'selected' : '' ?>>Brok % of Gross — Highest first</option>
                    <option value="net_asc"   <?= $sortBy === 'net_asc'   ? 'selected' : '' ?>>Net P&amp;L — Worst first</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn-primary-custom" style="flex:1;justify-content:center">
                    <i class="fas fa-search"></i> Apply
                </button>
                <a href="brokerage.php" class="btn-secondary-custom" style="flex:1;text-align:center;line-height:1.9">&#10005;</a>
            </div>
        </form>
    </div>
</div>

<!-- Brokerage Table -->
<div class="panel">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-receipt"></i> Daily Brokerage Breakdown</div>
        <span style="font-size:12px;color:var(--text-muted)"><?= $totalDays ?> day<?= $totalDays !== 1 ? 's' : '' ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>
                        <a href="?<?= http_build_query(array_merge($_GET, ['sort' => $sortBy === 'date_asc' ? 'date_desc' : 'date_asc'])) ?>"
                           style="color:inherit;display:flex;align-items:center;gap:4px">
                            Date
                            <i class="fas fa-<?= str_starts_with($sortBy, 'date') ? ($sortBy === 'date_asc' ? 'sort-up' : 'sort-down') : 'sort' ?>"
                               style="font-size:10px;color:var(--text-muted)"></i>
                        </a>
                    </th>
                    <th>Symbols</th>
                    <th>Trades</th>
                    <th>Gross P&amp;L</th>
                    <th>
                        <a href="?<?= http_build_query(array_merge($_GET, ['sort' => $sortBy === 'brok_asc' ? 'brok_desc' : 'brok_asc'])) ?>"
                           style="color:inherit;display:flex;align-items:center;gap:4px">
                            Brokerage
                            <i class="fas fa-<?= str_starts_with($sortBy, 'brok') ? ($sortBy === 'brok_asc' ? 'sort-up' : 'sort-down') : 'sort' ?>"
                               style="font-size:10px;color:var(--text-muted)"></i>
                        </a>
                    </th>
                    <th>
                        <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'pct_desc'])) ?>"
                           style="color:inherit;display:flex;align-items:center;gap:4px">
                            Brok % of Gross
                            <i class="fas fa-<?= $sortBy === 'pct_desc' ? 'sort-down' : 'sort' ?>"
                               style="font-size:10px;color:var(--text-muted)"></i>
                        </a>
                    </th>
                    <th>Net P&amp;L</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;color:var(--text-muted);padding:50px">
                        <i class="fas fa-receipt" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3"></i>
                        No brokerage records found<?= $hasFilter ? ' for the selected filters' : '' ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $brok    = (float)$r['total_brok'];
                    $gross   = (float)$r['gross_pl'];
                    $net     = (float)$r['net_pl'];
                    $pct     = $r['brok_pct'];
                    $barW    = $maxBrok > 0 ? round($brok / $maxBrok * 100) : 0;
                    $pctDisp = ($pct !== null && $gross != 0) ? $pct : null;
                    $isHighest = $maxBrokRow && $r['day'] === $maxBrokRow['day'];
                    $dateStr = $r['day'];
                ?>
                <tr <?= $isHighest ? 'style="background:rgba(217,119,6,.06)"' : '' ?>>
                    <td>
                        <a href="day.php?date=<?= $dateStr ?>" style="color:var(--text-primary);font-size:13px;font-weight:600">
                            <?= date('d M Y', strtotime($dateStr)) ?>
                        </a>
                        <span style="display:block;font-size:10px;color:var(--text-muted)"><?= date('l', strtotime($dateStr)) ?></span>
                        <?php if ($isHighest): ?>
                        <span style="font-size:9px;font-weight:700;color:var(--warning);text-transform:uppercase;letter-spacing:.5px">
                            <i class="fas fa-crown"></i> Highest
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:var(--text-secondary);max-width:120px">
                        <?php foreach (explode(', ', $r['symbols'] ?? '') as $sym): ?>
                        <span class="symbol-badge" style="font-size:9px;padding:1px 5px;margin:1px 1px 0 0;display:inline-block">
                            <?= htmlspecialchars(trim($sym)) ?>
                        </span>
                        <?php endforeach; ?>
                    </td>
                    <td class="mono" style="font-size:12px"><?= $r['cnt'] ?></td>
                    <td class="<?= $gross >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($gross) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;min-width:140px">
                            <div style="flex:1;height:5px;background:var(--border);border-radius:3px;overflow:hidden;min-width:40px">
                                <div style="width:<?= $barW ?>%;height:100%;background:var(--warning);border-radius:3px"></div>
                            </div>
                            <span style="color:var(--warning);font-family:var(--font-mono);font-weight:700;font-size:13px;white-space:nowrap">
                                -<?= formatUSD($brok) ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php if ($pctDisp !== null): ?>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="width:50px;height:4px;background:var(--border);border-radius:3px;overflow:hidden">
                                <div style="width:<?= min(100, $pctDisp) ?>%;height:100%;background:<?= $pctDisp > 50 ? 'var(--loss)' : ($pctDisp > 25 ? 'var(--warning)' : 'var(--text-muted)') ?>;border-radius:3px"></div>
                            </div>
                            <span class="mono" style="font-size:11px;color:<?= $pctDisp > 50 ? 'var(--loss)' : ($pctDisp > 25 ? 'var(--warning)' : 'var(--text-muted)') ?>">
                                <?= $pctDisp ?>%
                                <?php if ($pctDisp > 50): ?>
                                <i class="fas fa-triangle-exclamation" style="font-size:9px"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="<?= $net >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-weight:700"><?= formatPL($net) ?></td>
                    <td>
                        <a href="day.php?date=<?= $dateStr ?>" class="btn-action" title="View day detail">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr style="font-weight:700;background:var(--bg-elevated);font-size:13px">
                    <td style="padding:12px 16px" colspan="3">
                        <span style="color:var(--text-muted)">Totals — <?= $totalDays ?> day<?= $totalDays !== 1 ? 's' : '' ?>, <?= $totalTrades ?> trade<?= $totalTrades !== 1 ? 's' : '' ?></span>
                    </td>
                    <td class="<?= $totalGross >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($totalGross) ?></td>
                    <td style="color:var(--warning);font-family:var(--font-mono)">-<?= formatUSD($totalBrok) ?></td>
                    <td style="color:var(--text-muted);font-size:11px">
                        <?php
                        $overallPct = $totalGross != 0 ? round(abs($totalBrok / $totalGross) * 100, 1) : null;
                        echo $overallPct !== null ? $overallPct . '% of gross' : '—';
                        ?>
                    </td>
                    <td class="<?= $totalNet >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($totalNet) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

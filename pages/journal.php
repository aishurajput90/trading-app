<?php
require_once '../config/db.php';
$db      = getDB();
requireLogin();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();
$msg     = '';
$msgType = '';

// ── Add / Edit Trade ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Close time (trade_datetime)
    $date        = $_POST['trade_date']   ?? date('Y-m-d');
    $time        = $_POST['trade_time']   ?? date('H:i');
    $dt          = $date . ' ' . $time . ':00';
    // Open time
    $openDate    = $_POST['open_date']      ?? $date;
    $openTimeVal = $_POST['open_time_val']  ?? $time;
    $openDt      = $openDate . ' ' . $openTimeVal . ':00';

    $symbol      = strtoupper(trim($_POST['symbol']      ?? ''));
    $tradeType   = $_POST['trade_type']   ?? 'buy';
    $entry       = floatval($_POST['entry_price']  ?? 0);
    $exit        = floatval($_POST['exit_price']   ?? 0);
    $qty         = floatval($_POST['quantity']     ?? 0);
    $pl          = floatval($_POST['profit_loss']  ?? 0);
    $brokerage   = max(0, floatval($_POST['brokerage']   ?? 0));
    $swap        = floatval($_POST['swap']         ?? 0);
    $closeReason = trim($_POST['close_reason'] ?? '') ?: null;
    $notes       = trim($_POST['notes'] ?? '');
    $slAmount    = strlen(trim($_POST['sl_amount'] ?? '')) > 0 ? max(0, floatval($_POST['sl_amount'])) : null;
    $tpAmount    = strlen(trim($_POST['tp_amount'] ?? '')) > 0 ? max(0, floatval($_POST['tp_amount'])) : null;

    if ($_POST['action'] === 'add') {
        $stmt = $db->prepare("INSERT INTO trades
            (user_id, open_time, trade_datetime, symbol, trade_type, entry_price, exit_price, quantity, profit_loss, brokerage, swap, close_reason, notes, sl_amount, tp_amount, import_source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'manual')");
        $stmt->execute([$userId, $openDt, $dt, $symbol, $tradeType, $entry, $exit, $qty, $pl, $brokerage, $swap, $closeReason, $notes, $slAmount, $tpAmount]);
        $msg = 'Trade added successfully!'; $msgType = 'success';
    }
    if ($_POST['action'] === 'edit') {
        $id = intval($_POST['id']);
        $stmt = $db->prepare("UPDATE trades SET open_time=?,trade_datetime=?,symbol=?,trade_type=?,entry_price=?,exit_price=?,quantity=?,profit_loss=?,brokerage=?,swap=?,close_reason=?,notes=?,sl_amount=?,tp_amount=? WHERE id=? AND user_id=?");
        $stmt->execute([$openDt, $dt, $symbol, $tradeType, $entry, $exit, $qty, $pl, $brokerage, $swap, $closeReason, $notes, $slAmount, $tpAmount, $id, $userId]);
        $msg = 'Trade updated successfully!'; $msgType = 'success';
    }
}

// ── Delete ────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM trades WHERE id=? AND user_id=?")->execute([intval($_GET['delete']), $userId]);
    $msg = 'Trade deleted.'; $msgType = 'error';
}

// ── Filters ───────────────────────────────────────────────────────────────
$filterSymbol = trim($_GET['symbol'] ?? '');
$filterFrom   = $_GET['from'] ?? '';
$filterTo     = $_GET['to']   ?? '';
$filterType   = $_GET['type'] ?? '';
$filterReason = $_GET['reason'] ?? '';

$where  = "WHERE user_id = ?";
$params = [$userId];
if ($filterSymbol) { $where .= " AND symbol LIKE ?";             $params[] = "%$filterSymbol%"; }
if ($filterFrom)   { $where .= " AND DATE(trade_datetime) >= ?"; $params[] = $filterFrom; }
if ($filterTo)     { $where .= " AND DATE(trade_datetime) <= ?"; $params[] = $filterTo; }
if ($filterType)   { $where .= " AND trade_type = ?";            $params[] = $filterType; }
if ($filterReason) { $where .= " AND close_reason = ?";          $params[] = $filterReason; }

$trades = $db->prepare("SELECT * FROM trades $where ORDER BY trade_datetime DESC");
$trades->execute($params);
$tradesData = $trades->fetchAll();

$totalPL        = array_sum(array_column($tradesData, 'profit_loss'));
$totalBrokerage = array_sum(array_column($tradesData, 'brokerage'));
$totalSwap      = array_sum(array_column($tradesData, 'swap'));
$netPL          = $totalPL - $totalBrokerage + $totalSwap;
$wins           = count(array_filter(array_column($tradesData, 'profit_loss'), fn($v) => $v > 0));
$total          = count($tradesData);
$winRate        = $total > 0 ? round($wins / $total * 100, 1) : 0;

// ── Day highlights (respect active filters) ───────────────────────────────
$bestDayStmt = $db->prepare("
    SELECT DATE(trade_datetime) AS day,
           SUM(profit_loss - brokerage + swap) AS net_pl,
           SUM(profit_loss) AS gross_pl,
           SUM(brokerage) AS total_brok,
           COUNT(*) AS cnt
    FROM trades $where
    GROUP BY DATE(trade_datetime) ORDER BY net_pl DESC LIMIT 1
");
$bestDayStmt->execute($params);
$bestDayRow = $bestDayStmt->fetch();

$worstDayStmt = $db->prepare("
    SELECT DATE(trade_datetime) AS day,
           SUM(profit_loss - brokerage + swap) AS net_pl,
           SUM(profit_loss) AS gross_pl,
           SUM(brokerage) AS total_brok,
           COUNT(*) AS cnt
    FROM trades $where
    GROUP BY DATE(trade_datetime) ORDER BY net_pl ASC LIMIT 1
");
$worstDayStmt->execute($params);
$worstDayRow = $worstDayStmt->fetch();

$bigBrokStmt = $db->prepare("
    SELECT DATE(trade_datetime) AS day,
           SUM(brokerage) AS total_brok,
           SUM(profit_loss) AS gross_pl,
           SUM(profit_loss - brokerage + swap) AS net_pl,
           COUNT(*) AS cnt
    FROM trades $where
    GROUP BY DATE(trade_datetime) ORDER BY total_brok DESC LIMIT 1
");
$bigBrokStmt->execute($params);
$bigBrokRow = $bigBrokStmt->fetch();

$pageTitle = 'Trade Journal';
$rootPath  = '../';
include '../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Stats Bar -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $totalPL >= 0 ? 'profit' : 'loss' ?>" style="padding:14px">
            <div class="stat-label">Gross P&amp;L</div>
            <div class="stat-value <?= $totalPL >= 0 ? 'positive' : 'negative' ?>" style="font-size:18px"><?= formatPL($totalPL) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card loss" style="padding:14px">
            <div class="stat-label">Total Brokerage</div>
            <div class="stat-value negative" style="font-size:18px">-<?= formatUSD($totalBrokerage) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $totalSwap >= 0 ? 'profit' : 'loss' ?>" style="padding:14px">
            <div class="stat-label">Total Swap</div>
            <div class="stat-value <?= $totalSwap >= 0 ? 'positive' : 'negative' ?>" style="font-size:18px"><?= formatPL($totalSwap) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $netPL >= 0 ? 'profit' : 'loss' ?>" style="padding:14px">
            <div class="stat-label">Net P&amp;L <small style="font-size:9px">(after charges)</small></div>
            <div class="stat-value <?= $netPL >= 0 ? 'positive' : 'negative' ?>" style="font-size:18px;font-weight:800"><?= formatPL($netPL) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card blue" style="padding:14px">
            <div class="stat-label">Trades / Win Rate</div>
            <div class="stat-value" style="font-size:18px"><?= $total ?> <span style="font-size:13px;color:var(--accent-cyan)"><?= $winRate ?>%</span></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card blue" style="padding:14px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap">
            <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#tradeModal" style="flex:1;justify-content:center;font-size:12px">
                <i class="fas fa-plus"></i> Add
            </button>
            <a href="import.php" class="btn-secondary-custom" style="flex:1;text-align:center;font-size:12px;line-height:2.2">
                <i class="fas fa-file-import"></i> Import
            </a>
        </div>
    </div>
</div>

<!-- Day Highlights -->
<?php if ($total > 0): ?>
<div class="row g-3 mb-4">

    <?php if ($bestDayRow): ?>
    <div class="col-md-4">
        <div class="panel" style="border-left:4px solid var(--profit);margin-bottom:0">
            <div class="panel-body" style="padding:16px 20px">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:6px">
                    <i class="fas fa-star" style="color:var(--warning)"></i>&nbsp; Best Day
                    <?php if ($filterFrom || $filterTo || $filterSymbol): ?>
                    <span style="color:var(--accent);font-weight:500"> (filtered)</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px">
                    <?= date('D, d M Y', strtotime($bestDayRow['day'])) ?>
                </div>
                <div style="font-family:var(--font-mono);font-size:24px;font-weight:800;color:var(--profit);line-height:1;margin-bottom:8px">
                    <?= formatPL($bestDayRow['net_pl']) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted)">
                    Gross <?= formatPL($bestDayRow['gross_pl']) ?>
                    &nbsp;·&nbsp; Brok -<?= formatUSD($bestDayRow['total_brok']) ?>
                    &nbsp;·&nbsp; <?= $bestDayRow['cnt'] ?> trade<?= $bestDayRow['cnt'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($worstDayRow): ?>
    <div class="col-md-4">
        <div class="panel" style="border-left:4px solid var(--loss);margin-bottom:0">
            <div class="panel-body" style="padding:16px 20px">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:6px">
                    <i class="fas fa-skull" style="color:var(--loss)"></i>&nbsp; Worst Day
                    <?php if ($filterFrom || $filterTo || $filterSymbol): ?>
                    <span style="color:var(--accent);font-weight:500"> (filtered)</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px">
                    <?= date('D, d M Y', strtotime($worstDayRow['day'])) ?>
                </div>
                <div style="font-family:var(--font-mono);font-size:24px;font-weight:800;color:var(--loss);line-height:1;margin-bottom:8px">
                    <?= formatPL($worstDayRow['net_pl']) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted)">
                    Gross <?= formatPL($worstDayRow['gross_pl']) ?>
                    &nbsp;·&nbsp; Brok -<?= formatUSD($worstDayRow['total_brok']) ?>
                    &nbsp;·&nbsp; <?= $worstDayRow['cnt'] ?> trade<?= $worstDayRow['cnt'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($bigBrokRow && $bigBrokRow['total_brok'] > 0): ?>
    <div class="col-md-4">
        <div class="panel" style="border-left:4px solid var(--warning);margin-bottom:0">
            <div class="panel-body" style="padding:16px 20px">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:6px">
                    <i class="fas fa-triangle-exclamation" style="color:var(--warning)"></i>&nbsp; Biggest Brokerage Day
                    <?php if ($filterFrom || $filterTo || $filterSymbol): ?>
                    <span style="color:var(--accent);font-weight:500"> (filtered)</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px">
                    <?= date('D, d M Y', strtotime($bigBrokRow['day'])) ?>
                </div>
                <div style="font-family:var(--font-mono);font-size:24px;font-weight:800;color:var(--warning);line-height:1;margin-bottom:8px">
                    -<?= formatUSD($bigBrokRow['total_brok']) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted)">
                    Gross <?= formatPL($bigBrokRow['gross_pl']) ?>
                    &nbsp;·&nbsp; Net <?= formatPL($bigBrokRow['net_pl']) ?>
                    &nbsp;·&nbsp; <?= $bigBrokRow['cnt'] ?> trade<?= $bigBrokRow['cnt'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- Filters -->
<div class="panel mb-4">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-filter"></i> Filter Trades</div>
    </div>
    <div class="panel-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Symbol</label>
                <input type="text" class="form-control" name="symbol" value="<?= htmlspecialchars($filterSymbol) ?>" placeholder="XAUUSD..." style="text-transform:uppercase">
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="from" value="<?= $filterFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="to" value="<?= $filterTo ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select class="form-control" name="type">
                    <option value="">All</option>
                    <option value="buy"  <?= $filterType==='buy'  ? 'selected' : '' ?>>Buy</option>
                    <option value="sell" <?= $filterType==='sell' ? 'selected' : '' ?>>Sell</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Close Reason</label>
                <select class="form-control" name="reason">
                    <option value="">All</option>
                    <option value="user" <?= $filterReason==='user' ? 'selected' : '' ?>>Manual</option>
                    <option value="tp"   <?= $filterReason==='tp'   ? 'selected' : '' ?>>Take Profit</option>
                    <option value="sl"   <?= $filterReason==='sl'   ? 'selected' : '' ?>>Stop Loss</option>
                    <option value="so"   <?= $filterReason==='so'   ? 'selected' : '' ?>>Stop Out</option>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-2">
                <button type="submit" class="btn-primary-custom" style="flex:1;justify-content:center"><i class="fas fa-search"></i></button>
                <a href="journal.php" class="btn-secondary-custom" style="flex:1;text-align:center;line-height:1.9">✕</a>
            </div>
        </form>
    </div>
</div>

<!-- Trades Table -->
<div class="panel">
    <div class="panel-header">
        <div class="panel-title"><i class="fas fa-table-list"></i> Trades (<?= $total ?>)</div>
    </div>
    <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date &amp; Time</th>
                    <th>Ticket</th>
                    <th>Symbol</th>
                    <th>Type</th>
                    <th>Entry</th>
                    <th>Exit</th>
                    <th>Lots</th>
                    <th>Close</th>
                    <th style="color:#ef4444">SL $</th>
                    <th style="color:#22c55e">TP $</th>
                    <th>R:R</th>
                    <th>Gross P&amp;L</th>
                    <th>Brokerage</th>
                    <th>Swap</th>
                    <th>Net P&amp;L</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($tradesData)): ?>
                <tr>
                    <td colspan="18" style="text-align:center;color:var(--text-muted);padding:40px">
                        <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px"></i>
                        No trades found. <a href="import.php">Import a CSV</a> or add your first trade!
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tradesData as $i => $t):
                    $netRow = $t['profit_loss'] - $t['brokerage'] + $t['swap'];
                    $reasonLabels = ['sl'=>'SL','tp'=>'TP','user'=>'Manual','so'=>'SO'];
                    $reasonColors = ['sl'=>'var(--loss)','tp'=>'var(--profit)','user'=>'var(--text-muted)','so'=>'var(--warning)'];
                    $reason = $t['close_reason'] ?? '';
                ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:11px"><?= $i + 1 ?></td>
                    <td>
                        <span style="font-size:12px"><?= date('d M Y', strtotime($t['trade_datetime'])) ?></span>
                        <span style="display:block;font-size:10px;color:var(--text-muted);font-family:var(--font-mono)"><?= date('H:i', strtotime($t['trade_datetime'])) ?></span>
                    </td>
                    <td style="font-size:10px;color:var(--text-muted);font-family:var(--font-mono)"><?= $t['ticket'] ? htmlspecialchars($t['ticket']) : '—' ?></td>
                    <td><span class="symbol-badge"><?= htmlspecialchars($t['symbol']) ?></span></td>
                    <td>
                        <span class="type-badge type-<?= $t['trade_type'] ?? 'buy' ?>">
                            <?= strtoupper($t['trade_type'] ?? 'BUY') ?>
                        </span>
                    </td>
                    <td class="mono" style="font-size:12px"><?= number_format($t['entry_price'],4) ?></td>
                    <td class="mono" style="font-size:12px"><?= number_format($t['exit_price'],4) ?></td>
                    <td class="mono" style="font-size:12px"><?= $t['quantity'] ?></td>
                    <td>
                        <?php if ($reason): ?>
                        <span style="font-size:10px;font-weight:700;color:<?= $reasonColors[$reason] ?? 'var(--text-muted)' ?>;background:<?= $reasonColors[$reason] ?? 'var(--text-muted)' ?>1a;padding:2px 7px;border-radius:4px">
                            <?= $reasonLabels[$reason] ?? strtoupper($reason) ?>
                        </span>
                        <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                    </td>
                    <?php
                    $sl = $t['sl_amount'] !== null ? (float)$t['sl_amount'] : null;
                    $tp = $t['tp_amount'] !== null ? (float)$t['tp_amount'] : null;
                    $rr = ($sl > 0 && $tp > 0) ? round($tp / $sl, 2) : null;
                    $rrColor = $rr === null ? 'var(--text-muted)' : ($rr >= 2 ? '#22c55e' : ($rr >= 1 ? '#eab308' : '#ef4444'));
                    ?>
                    <td style="font-size:12px;font-weight:700;color:#ef4444">
                        <?= $sl !== null ? '$'.number_format($sl,2) : '<span style="color:var(--loss);font-size:10px;font-weight:800">NO SL</span>' ?>
                    </td>
                    <td style="font-size:12px;font-weight:700;color:#22c55e">
                        <?= $tp !== null ? '$'.number_format($tp,2) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td style="font-size:12px;font-weight:800;color:<?= $rrColor ?>">
                        <?= $rr !== null ? '1:'.number_format($rr,2) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td class="<?= $t['profit_loss'] >= 0 ? 'pl-positive' : 'pl-negative' ?>"><?= formatPL($t['profit_loss']) ?></td>
                    <td class="pl-negative" style="font-size:12px"><?= $t['brokerage'] > 0 ? '-'.formatUSD($t['brokerage']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td class="<?= $t['swap'] >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-size:12px"><?= $t['swap'] != 0 ? formatPL($t['swap']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td class="<?= $netRow >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-weight:700"><?= formatPL($netRow) ?></td>
                    <td style="max-width:140px;font-size:11px;color:var(--text-secondary)"><?= $t['notes'] ? htmlspecialchars(mb_strimwidth($t['notes'],0,35,'...')) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <button class="btn-action" onclick='editTrade(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)' title="Edit">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn-action delete" onclick="confirmDelete(<?= $t['id'] ?>, '<?= htmlspecialchars($t['symbol']) ?>')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Pass account balance to modal for risk % calculation
$balance = getCurrentBalance($userId);
?>
<script>
document.getElementById('tradeModal')?.setAttribute('data-balance', '<?= round($balance, 2) ?>');
</script>
<?php include '../includes/trade_modal.php'; ?>
<?php include '../includes/footer.php'; ?>

<?php
require_once '../config/db.php';
$db=$db=getDB(); $userId=INDIA_DEFAULT_USER;
$msg=''; $msgType='';

// Filters
$filterSym  = trim($_GET['symbol']   ?? '');
$filterFrom = $_GET['from'] ?? '';
$filterTo   = $_GET['to']   ?? '';
$filterBase = trim($_GET['base']     ?? '');
$filterExch = trim($_GET['exchange'] ?? '');

// Delete
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM india_trades WHERE id=? AND user_id=?")->execute([intval($_GET['delete']),$userId]);
    $msg='Trade deleted.'; $msgType='error';
}

// Build query
$where='WHERE user_id=?'; $params=[$userId];
if ($filterSym)  { $where.=" AND instrument LIKE ?";    $params[]="%$filterSym%"; }
if ($filterBase) { $where.=" AND base_instrument=?";    $params[]=$filterBase; }
if ($filterFrom) { $where.=" AND trade_date>=?";        $params[]=$filterFrom; }
if ($filterTo)   { $where.=" AND trade_date<=?";        $params[]=$filterTo; }
if ($filterExch) { $where.=" AND exchange=?";           $params[]=$filterExch; }

$trades=$db->prepare("SELECT * FROM india_trades $where ORDER BY close_time DESC");
$trades->execute($params);
$tradesData=$trades->fetchAll();

$totalGross   = array_sum(array_column($tradesData,'profit_loss'));
$totalBrok    = array_sum(array_column($tradesData,'brokerage'));
$totalNet     = array_sum(array_column($tradesData,'net_pl'));
$total        = count($tradesData);
$wins         = count(array_filter(array_column($tradesData,'profit_loss'),fn($v)=>$v>0));
$winRate      = $total>0?round($wins/$total*100,1):0;

// Unique base instruments for filter
$basesStmt=$db->prepare("SELECT DISTINCT base_instrument FROM india_trades WHERE user_id=? ORDER BY base_instrument");
$basesStmt->execute([$userId]); $bases=$basesStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle='Trade Journal'; $rootPath='../';
include '../includes/header.php';
?>

<!-- Stats Bar -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $totalGross>=0?'profit':'loss' ?>" style="padding:14px">
            <div class="stat-label">Gross P&amp;L</div>
            <div class="stat-value <?= $totalGross>=0?'positive':'negative' ?>" style="font-size:16px"><?= formatINR_PL($totalGross) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card loss" style="padding:14px">
            <div class="stat-label">Brokerage</div>
            <div class="stat-value negative" style="font-size:16px">-<?= formatINR($totalBrok) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card <?= $totalNet>=0?'profit':'loss' ?>" style="padding:14px">
            <div class="stat-label">Net P&amp;L</div>
            <div class="stat-value <?= $totalNet>=0?'positive':'negative' ?>" style="font-size:16px;font-weight:800"><?= formatINR_PL($totalNet) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="stat-card blue" style="padding:14px">
            <div class="stat-label">Trades / WR</div>
            <div class="stat-value" style="font-size:16px"><?= $total ?> <span style="font-size:12px;color:var(--accent-cyan)"><?= $winRate ?>%</span></div>
        </div>
    </div>
    <div class="col-6 col-lg-4" style="display:flex;align-items:center">
        <a href="import.php" class="btn-primary-custom" style="width:100%;justify-content:center">
            <i class="fas fa-file-import"></i> Import Dhan CSV
        </a>
    </div>
</div>

<!-- Filters -->
<div class="panel mb-4">
    <div class="panel-header"><div class="panel-title"><i class="fas fa-filter"></i> Filter Trades</div></div>
    <div class="panel-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Base Instrument</label>
                <select class="form-control" name="base">
                    <option value="">All</option>
                    <?php foreach ($bases as $b): ?>
                    <option value="<?= $b ?>" <?= $filterBase===$b?'selected':'' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Instrument Name</label>
                <input type="text" class="form-control" name="symbol" value="<?= htmlspecialchars($filterSym) ?>" placeholder="BANKNIFTY 22 MAY..." style="text-transform:uppercase">
            </div>
            <div class="col-md-2">
                <label class="form-label">Exchange</label>
                <select class="form-control" name="exchange">
                    <option value="">All</option>
                    <option value="NSE" <?= $filterExch==='NSE'?'selected':'' ?>>NSE</option>
                    <option value="BSE" <?= $filterExch==='BSE'?'selected':'' ?>>BSE</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" class="form-control" name="from" value="<?= $filterFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" class="form-control" name="to" value="<?= $filterTo ?>">
            </div>
            <div class="col-md-1 d-flex gap-2">
                <button type="submit" class="btn-primary-custom" style="flex:1;justify-content:center"><i class="fas fa-search"></i></button>
                <a href="journal.php" class="btn-secondary-custom" style="flex:1;text-align:center;line-height:1.9">✕</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="panel">
    <div class="panel-header"><div class="panel-title"><i class="fas fa-table-list"></i> Trades (<?= $total ?>)</div></div>
    <div style="overflow-x:auto">
        <table class="data-table">
            <thead><tr>
                <th>#</th><th>Date</th><th>Open</th><th>Close</th>
                <th>Instrument</th><th>Exch</th><th>Qty</th>
                <th>Buy ₹</th><th>Sell ₹</th>
                <th>Gross P&amp;L</th><th>Brokerage</th><th>Net P&amp;L</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($tradesData)): ?>
            <tr><td colspan="13" style="text-align:center;color:var(--text-muted);padding:40px">
                <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px"></i>
                No trades — <a href="import.php">Import Dhan CSV</a>
            </td></tr>
            <?php else: ?>
            <?php foreach ($tradesData as $i=>$t): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:11px"><?= $i+1 ?></td>
                <td style="font-size:12px"><?= date('d M Y',strtotime($t['trade_date'])) ?></td>
                <td style="font-size:11px;font-family:var(--font-mono);color:var(--text-muted)">
                    <?= $t['open_time'] ? date('H:i',strtotime($t['open_time'])) : '—' ?>
                </td>
                <td style="font-size:11px;font-family:var(--font-mono)">
                    <?= date('H:i',strtotime($t['close_time'])) ?>
                </td>
                <td style="max-width:180px">
                    <span class="symbol-badge" style="font-size:9px"><?= htmlspecialchars($t['base_instrument']) ?></span>
                    <span style="font-size:10px;color:var(--text-muted);display:block;margin-top:2px">
                        <?= htmlspecialchars(implode(' ',array_slice(explode(' ',$t['instrument']),1))) ?>
                    </span>
                </td>
                <td style="font-size:11px"><?= $t['exchange'] ?></td>
                <td style="font-family:var(--font-mono);font-size:11px"><?= $t['quantity'] ?></td>
                <td style="font-size:11px;font-family:var(--font-mono)">₹<?= number_format($t['buy_value'],2) ?></td>
                <td style="font-size:11px;font-family:var(--font-mono)">₹<?= number_format($t['sell_value'],2) ?></td>
                <td class="<?= $t['profit_loss']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($t['profit_loss']) ?></td>
                <td style="color:var(--loss);font-size:11px">-<?= formatINR($t['brokerage']) ?></td>
                <td class="<?= $t['net_pl']>=0?'pl-positive':'pl-negative' ?>" style="font-weight:700"><?= formatINR_PL($t['net_pl']) ?></td>
                <td>
                    <button class="btn-action delete" onclick="if(confirm('Delete?'))window.location.href='journal.php?delete=<?= $t['id'] ?>'" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>

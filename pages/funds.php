<?php
require_once '../config/db.php';
$db     = getDB();
requireLogin();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();
$msg    = '';
$msgType = '';

// ---- Handle stop out ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stop_out') {
    $currentBalance = getCurrentBalance($userId);
    // Allow stop-out even on zero/negative balance — it marks a cycle reset.
    // Record the wiped amount as max(0, balance) since you can't wipe a negative.
    $soNote     = trim($_POST['stop_out_note'] ?? '') ?: 'Account Stop Out';
    $soDate     = $_POST['stop_out_date'] ?? date('Y-m-d');
    $soTime     = $_POST['stop_out_time'] ?? date('H:i');
    $soDatetime = $soDate . ' ' . $soTime . ':00';
    $soAmount   = max(0, round($currentBalance, 2));
    $db->prepare("INSERT INTO transactions (user_id, type, amount, note, date, created_at) VALUES (?, 'stop_out', ?, ?, ?, ?)")
       ->execute([$userId, $soAmount, $soNote, $soDate, $soDatetime]);
    $today  = $soDate;
    $monday = date('Y-m-d', strtotime('monday this week'));
    $db->prepare("UPDATE risk_snapshots SET balance_at_open=0, highest_equity=0 WHERE user_id=? AND snapshot_type='daily' AND snapshot_date=?")->execute([$userId, $today]);
    $db->prepare("UPDATE risk_snapshots SET balance_at_open=0, highest_equity=0 WHERE user_id=? AND snapshot_type='weekly' AND snapshot_date=?")->execute([$userId, $monday]);
    header('Location: ../index.php?stopped_out=1');
    exit;
}

// ---- Handle transaction (deposit / withdraw only — not stop_out) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'stop_out') {
    $type   = $_POST['type']   ?? 'deposit';
    $amount = abs(floatval($_POST['amount'] ?? 0));
    $note   = trim($_POST['note'] ?? '');
    $date   = $_POST['date']   ?? date('Y-m-d');

    if ($amount > 0 && in_array($type, ['deposit','withdraw'])) {
        $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, note, date) VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $type, $amount, $note, $date]);
        $msg     = ucfirst($type) . ' of ' . formatUSD($amount) . ' recorded successfully!';
        $msgType = 'success';
    } else {
        $msg     = 'Please enter a valid amount.';
        $msgType = 'error';
    }
}

// ---- Delete transaction ----
if (isset($_GET['del_tx'])) {
    $id = intval($_GET['del_tx']);
    $db->prepare("DELETE FROM transactions WHERE id=? AND user_id=?")->execute([$id, $userId]);
    header("Location: funds.php");
    exit;
}

// ---- Data ----
$balance = getCurrentBalance($userId);

$txStmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY date DESC, created_at DESC");
$txStmt->execute([$userId]);
$transactions = $txStmt->fetchAll();

$totalDeposits = array_sum(array_map(fn($t) => $t['type']==='deposit' ? $t['amount'] : 0, $transactions));
$totalWithdraw = array_sum(array_map(fn($t) => $t['type']==='withdraw' ? $t['amount'] : 0, $transactions));

// All-time Trading P/L
$plStmt = $db->prepare("SELECT COALESCE(SUM(profit_loss),0) as total FROM trades WHERE user_id = ?");
$plStmt->execute([$userId]);
$totalPL = $plStmt->fetch()['total'];

// Stop out stats
$soStmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE user_id = ? AND type = 'stop_out'");
$soStmt->execute([$userId]);
$soStats = $soStmt->fetch();

// ---- Current capital cycle breakdown ----
// Find the most recent stop out — transactions are already DESC by date/created_at
$lastSO = null;
foreach ($transactions as $tx) {
    if ($tx['type'] === 'stop_out') { $lastSO = $tx; break; }
}

$cycleStart       = null;
$cycleDeposits    = 0.0;
$cycleWithdraw    = 0.0;
$cyclePL          = 0.0;
$showCycleBadge   = false;

if ($lastSO) {
    $showCycleBadge = true;
    $soDate = $lastSO['date']; // business date (YYYY-MM-DD) — same boundary used by getCurrentBalance
    foreach ($transactions as $tx) {
        if ($tx['type'] === 'stop_out') continue;
        if ($tx['date'] > $soDate) {
            if ($tx['type'] === 'deposit')  $cycleDeposits += (float)$tx['amount'];
            if ($tx['type'] === 'withdraw') $cycleWithdraw += (float)$tx['amount'];
            if (!$cycleStart || $tx['date'] < $cycleStart) $cycleStart = $tx['date'];
        }
    }
    if (!$cycleStart) $cycleStart = $lastSO['date'];

    // Net P/L (profit_loss - brokerage + swap) after stop-out date — matches getCurrentBalance formula
    $cyclePLStmt = $db->prepare("
        SELECT COALESCE(SUM(profit_loss - brokerage + swap),0) as total
        FROM trades WHERE user_id=? AND DATE(trade_datetime) > ?
    ");
    $cyclePLStmt->execute([$userId, $soDate]);
    $cyclePL = (float)$cyclePLStmt->fetch()['total'];
}

// ---- Filtered deposits/withdrawals (new section) ----
$txFilterType = $_GET['tx_type'] ?? 'all';
$txFilterFrom = $_GET['tx_from'] ?? '';
$txFilterTo   = $_GET['tx_to']   ?? '';
if ($txFilterFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txFilterFrom)) $txFilterFrom = '';
if ($txFilterTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txFilterTo))   $txFilterTo   = '';

$fWhere  = "WHERE user_id = ? AND type IN ('deposit','withdraw')";
$fParams = [$userId];
if (in_array($txFilterType, ['deposit','withdraw'])) {
    $fWhere  .= " AND type = ?";
    $fParams[] = $txFilterType;
}
if ($txFilterFrom) { $fWhere .= " AND date >= ?"; $fParams[] = $txFilterFrom; }
if ($txFilterTo)   { $fWhere .= " AND date <= ?"; $fParams[] = $txFilterTo;   }

$filteredTxStmt = $db->prepare("SELECT * FROM transactions $fWhere ORDER BY date DESC, created_at DESC");
$filteredTxStmt->execute($fParams);
$filteredTx = $filteredTxStmt->fetchAll();

$filteredDeposits = array_sum(array_map(fn($t) => $t['type'] === 'deposit' ? $t['amount'] : 0, $filteredTx));
$filteredWithdraw = array_sum(array_map(fn($t) => $t['type'] === 'withdraw' ? $t['amount'] : 0, $filteredTx));

$pageTitle = 'Fund Manager';
$rootPath  = '../';
include '../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Balance Overview -->
<?php if ($showCycleBadge): ?>
<div class="alert-custom alert-warning mb-3" style="padding:10px 16px;font-size:13px">
    <i class="fas fa-rotate"></i>
    <span>
        Showing <strong>current cycle</strong> figures
        (since stop out on <strong><?= date('d M Y, H:i', strtotime($lastSO['created_at'])) ?></strong>).
        <?php if ($cycleDeposits == 0): ?>
            <em style="opacity:.75">No new capital added yet.</em>
        <?php endif; ?>
        <a href="cycles.php" style="margin-left:6px;color:var(--accent)">View all cycles →</a>
    </span>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card profit">
            <div class="stat-icon profit"><i class="fas fa-wallet"></i></div>
            <div class="stat-value positive"><?= formatUSD($balance) ?></div>
            <div class="stat-label">Current Balance</div>
            <div class="stat-sub">Live capital</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fas fa-arrow-down-to-line"></i></div>
            <div class="stat-value"><?= formatUSD($showCycleBadge ? $cycleDeposits : $totalDeposits) ?></div>
            <div class="stat-label"><?= $showCycleBadge ? 'Deposited This Cycle' : 'Total Deposited' ?></div>
            <?php if ($showCycleBadge && $totalDeposits > $cycleDeposits): ?>
            <div class="stat-sub" style="color:var(--text-muted)">All-time: <?= formatUSD($totalDeposits) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card loss">
            <div class="stat-icon loss"><i class="fas fa-arrow-up-from-line"></i></div>
            <div class="stat-value negative"><?= formatUSD($showCycleBadge ? $cycleWithdraw : $totalWithdraw) ?></div>
            <div class="stat-label"><?= $showCycleBadge ? 'Withdrawn This Cycle' : 'Total Withdrawn' ?></div>
            <?php if ($showCycleBadge && $totalWithdraw > $cycleWithdraw): ?>
            <div class="stat-sub" style="color:var(--text-muted)">All-time: <?= formatUSD($totalWithdraw) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-3">
        <?php $displayPL = $showCycleBadge ? $cyclePL : $totalPL; ?>
        <div class="stat-card <?= $displayPL >= 0 ? 'profit' : 'loss' ?>">
            <div class="stat-icon <?= $displayPL >= 0 ? 'profit' : 'loss' ?>"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value <?= $displayPL >= 0 ? 'positive' : 'negative' ?>"><?= formatPL($displayPL) ?></div>
            <div class="stat-label"><?= $showCycleBadge ? 'P&amp;L This Cycle' : 'Trading P&amp;L' ?></div>
            <?php if ($showCycleBadge && $totalPL != $cyclePL): ?>
            <div class="stat-sub" style="color:var(--text-muted)">All-time: <?= formatPL($totalPL) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($soStats['cnt'] > 0): ?>
<div class="row g-3 mb-3">
    <div class="col-md-5">
        <div class="stat-card loss" style="border-left:3px solid var(--loss)">
            <div class="stat-icon loss"><i class="fas fa-power-off"></i></div>
            <div class="stat-value negative"><?= $soStats['cnt'] ?> event<?= $soStats['cnt'] > 1 ? 's' : '' ?></div>
            <div class="stat-label">Account Stop Outs</div>
            <div class="stat-sub">Total capital wiped: <?= formatUSD($soStats['total']) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Add Transaction Form -->
    <div class="col-lg-4">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-plus-circle"></i> Add Transaction</div>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Transaction Type</label>
                        <div class="d-flex gap-2">
                            <label style="flex:1;cursor:pointer">
                                <input type="radio" name="type" value="deposit" id="typeDeposit" checked style="display:none">
                                <div class="tx-type-btn deposit" id="btnDeposit" onclick="setType('deposit')">
                                    <i class="fas fa-arrow-down"></i> Deposit
                                </div>
                            </label>
                            <label style="flex:1;cursor:pointer">
                                <input type="radio" name="type" value="withdraw" id="typeWithdraw" style="display:none">
                                <div class="tx-type-btn withdraw" id="btnWithdraw" onclick="setType('withdraw')">
                                    <i class="fas fa-arrow-up"></i> Withdraw
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount (USD)</label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)"><?= getActiveCurrency()['symbol'] ?></span>
                            <input type="number" class="form-control" name="amount" placeholder="0.00" step="0.01" min="0.01" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Note <span style="color:var(--text-muted);text-transform:none;font-weight:400">(optional)</span></label>
                        <input type="text" class="form-control" name="note" placeholder="e.g. Monthly top-up">
                    </div>

                    <input type="hidden" name="action" value="add">
                    <button type="submit" class="btn-primary-custom" style="width:100%;justify-content:center">
                        <i class="fas fa-floppy-disk"></i> Save Transaction
                    </button>
                </form>

                <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:16px">
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                        <i class="fas fa-triangle-exclamation" style="color:var(--warning)"></i>
                        A Stop Out zeros your balance instantly. All trade history is preserved.
                    </p>
                    <button type="button" class="btn-danger-custom" style="width:100%;justify-content:center"
                            data-bs-toggle="modal" data-bs-target="#stopOutModal">
                        <i class="fas fa-power-off"></i> Stop Out Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="col-lg-8">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-list-ul"></i> Transaction History</div>
                <span style="font-size:12px;color:var(--text-muted)"><?= count($transactions) ?> records</span>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:30px">No transactions yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td style="font-size:13px"><?= date('d M Y', strtotime($tx['date'])) ?></td>
                            <td>
                                <span class="tx-type-badge <?= $tx['type'] ?>">
                                    <?= ucfirst($tx['type']) ?>
                                </span>
                            </td>
                            <td class="<?= $tx['type'] === 'deposit' ? 'pl-positive' : 'pl-negative' ?> mono">
                                <?= $tx['type'] === 'deposit' ? '+' : '-' ?><?= formatUSD($tx['amount']) ?>
                            </td>
                            <td style="font-size:12px;color:var(--text-secondary)">
                                <?= $tx['note'] ? htmlspecialchars($tx['note']) : '<span style="color:var(--text-muted)">—</span>' ?>
                            </td>
                            <td>
                                <a href="?del_tx=<?= $tx['id'] ?>" class="btn-action delete"
                                   onclick="return confirm('Delete this transaction?')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Deposits & Withdrawals — Filtered View -->
<div class="row g-4 mt-2">
    <div class="col-12">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-filter"></i> Deposits &amp; Withdrawals</div>
                <span style="font-size:12px;color:var(--text-muted)"><?= count($filteredTx) ?> record<?= count($filteredTx) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="panel-body" style="padding-bottom:0">
                <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
                    <select name="tx_type" class="form-select form-select-sm" style="width:auto">
                        <option value="all"      <?= $txFilterType === 'all'      ? 'selected' : '' ?>>All Types</option>
                        <option value="deposit"  <?= $txFilterType === 'deposit'  ? 'selected' : '' ?>>Deposit</option>
                        <option value="withdraw" <?= $txFilterType === 'withdraw' ? 'selected' : '' ?>>Withdraw</option>
                    </select>
                    <input type="date" name="tx_from" class="form-control form-control-sm" style="width:auto"
                           placeholder="From" value="<?= htmlspecialchars($txFilterFrom) ?>">
                    <span style="color:var(--text-muted);font-size:13px">to</span>
                    <input type="date" name="tx_to" class="form-control form-control-sm" style="width:auto"
                           placeholder="To" value="<?= htmlspecialchars($txFilterTo) ?>">
                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 12px">Apply</button>
                    <?php if ($txFilterType !== 'all' || $txFilterFrom || $txFilterTo): ?>
                    <a href="funds.php" style="font-size:12px;color:var(--text-muted)">&#10005; Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($filteredTx)): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:30px">No records match the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filteredTx as $tx): ?>
                        <tr>
                            <td style="font-size:13px"><?= date('d M Y', strtotime($tx['date'])) ?></td>
                            <td>
                                <span class="tx-type-badge <?= $tx['type'] ?>">
                                    <?= ucfirst($tx['type']) ?>
                                </span>
                            </td>
                            <td class="<?= $tx['type'] === 'deposit' ? 'pl-positive' : 'pl-negative' ?> mono">
                                <?= $tx['type'] === 'deposit' ? '+' : '-' ?><?= formatUSD($tx['amount']) ?>
                            </td>
                            <td style="font-size:12px;color:var(--text-secondary)">
                                <?= $tx['note'] ? htmlspecialchars($tx['note']) : '<span style="color:var(--text-muted)">—</span>' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($filteredTx)): ?>
                    <tfoot>
                        <tr style="font-weight:600;font-size:13px;background:var(--bg-elevated)">
                            <td colspan="2" style="padding:10px 16px;color:var(--text-muted)">Totals</td>
                            <td colspan="2" style="padding:10px 16px">
                                <span class="pl-positive">+<?= formatUSD($filteredDeposits) ?></span>
                                &nbsp;/&nbsp;
                                <span class="pl-negative">-<?= formatUSD($filteredWithdraw) ?></span>
                                &nbsp;&nbsp;
                                <span style="color:var(--text-muted)">Net:</span>
                                <span class="<?= ($filteredDeposits - $filteredWithdraw) >= 0 ? 'pl-positive' : 'pl-negative' ?>">
                                    <?= formatPL($filteredDeposits - $filteredWithdraw) ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.tx-type-btn {
    text-align: center;
    padding: 10px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--bg-elevated);
    transition: all .2s;
}
.tx-type-btn.deposit.active  { background: rgba(22,163,74,.15); border-color: var(--profit); color: var(--profit); }
.tx-type-btn.withdraw.active { background: rgba(220,38,38,.15); border-color: var(--loss);   color: var(--loss); }
.tx-type-badge.stop_out { background: rgba(220,38,38,.15); color: var(--loss); border: 1px solid rgba(220,38,38,.25); }
</style>

<script>
function setType(type) {
    document.getElementById('typeDeposit').checked  = (type === 'deposit');
    document.getElementById('typeWithdraw').checked = (type === 'withdraw');
    document.getElementById('btnDeposit').classList.toggle('active',  type === 'deposit');
    document.getElementById('btnWithdraw').classList.toggle('active', type === 'withdraw');
}
document.addEventListener('DOMContentLoaded', () => setType('deposit'));
</script>

<?php
$soHistory = $db->prepare("SELECT * FROM transactions WHERE user_id=? AND type='stop_out' ORDER BY created_at DESC");
$soHistory->execute([$userId]);
$soRows = $soHistory->fetchAll();
if ($soRows):
?>
<div class="row g-4 mt-2">
    <div class="col-12">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title" style="color:var(--loss)">
                    <i class="fas fa-power-off"></i> Stop Out History
                </div>
                <span style="font-size:12px;color:var(--text-muted)"><?= count($soRows) ?> event<?= count($soRows) > 1 ? 's' : '' ?></span>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Balance Before</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($soRows as $so): ?>
                    <tr style="background:rgba(220,38,38,0.04)">
                        <td style="font-size:13px"><?= date('d M Y, H:i', strtotime($so['created_at'])) ?></td>
                        <td class="pl-negative mono">-<?= formatUSD($so['amount']) ?></td>
                        <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($so['note'] ?: '—') ?></td>
                        <td>
                            <a href="?del_tx=<?= $so['id'] ?>" class="btn-action delete"
                               onclick="return confirm('Delete this stop out record? The balance will be restored.')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
            <form method="POST">
                    <?= csrfField() ?>
                <div class="modal-body">
                    <div class="risk-alert risk-alert-breach mb-3" style="border-radius:var(--radius-sm)">
                        <i class="fas fa-ban"></i>
                        <div><strong>This will reset your account balance to <?= formatUSD(0) ?> and start a new cycle.</strong><br>
                        <small>Current balance: <strong><?= $balance >= 0 ? formatUSD($balance) : '-' . formatUSD(abs($balance)) ?></strong>. All trades and history are preserved.</small></div>
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

<?php include '../includes/footer.php'; ?>

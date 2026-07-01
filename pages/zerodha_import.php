<?php
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();

// ── Parse Zerodha tradebook CSV ───────────────────────────────────────────────
// Expected headers: symbol, isin, trade_date, exchange, segment, series,
//                   trade_type, auction, quantity, price, trade_id, order_id,
//                   order_execution_time, expiry_date
function parseZerodhaCSV(string $filePath): array {
    $rows = [];
    $fh   = fopen($filePath, 'r');
    $raw  = fgetcsv($fh);
    if (!$raw) { fclose($fh); return []; }
    $hdrs = array_flip(array_map('trim', $raw));

    $need = ['symbol','trade_type','quantity','price','trade_id','order_execution_time'];
    foreach ($need as $col) {
        if (!isset($hdrs[$col])) { fclose($fh); return ['error' => "Missing required column: $col"]; }
    }

    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 8) continue;
        $sym = trim($row[$hdrs['symbol']] ?? '');
        if (!$sym) continue;
        $rows[] = [
            'symbol'   => strtoupper($sym),
            'type'     => strtolower(trim($row[$hdrs['trade_type']] ?? '')),
            'qty'      => floatval($row[$hdrs['quantity']] ?? 0),
            'price'    => floatval($row[$hdrs['price']] ?? 0),
            'time'     => str_replace('T', ' ', trim($row[$hdrs['order_execution_time']] ?? '')),
            'trade_id' => trim($row[$hdrs['trade_id']] ?? ''),
        ];
    }
    fclose($fh);
    return $rows;
}

// ── FIFO pairing: match buy↔sell executions per symbol ───────────────────────
function pairZerodhaTrades(array $rows): array {
    $bySymbol = [];
    foreach ($rows as $row) $bySymbol[$row['symbol']][] = $row;

    $trades    = [];
    $unmatched = [];

    foreach ($bySymbol as $symbol => $execs) {
        usort($execs, fn($a, $b) => strcmp($a['time'], $b['time']));

        $openLongs  = [];  // [{qty, price, time, trade_id}]
        $openShorts = [];

        foreach ($execs as $exec) {
            $remQty = $exec['qty'];

            if ($exec['type'] === 'buy') {
                // Close any open shorts first (FIFO)
                while ($remQty > 0 && !empty($openShorts)) {
                    $short    = &$openShorts[0];
                    $matchQty = min($remQty, $short['qty']);
                    $trades[] = [
                        'symbol'      => $symbol,
                        'trade_type'  => 'sell',
                        'quantity'    => $matchQty,
                        'entry_price' => $short['price'],
                        'exit_price'  => $exec['price'],
                        'open_time'   => $short['time'],
                        'close_time'  => $exec['time'],
                        'profit_loss' => round(($short['price'] - $exec['price']) * $matchQty, 2),
                        'ticket'      => $short['trade_id'],
                    ];
                    $short['qty'] -= $matchQty;
                    $remQty       -= $matchQty;
                    if ($short['qty'] <= 0) array_shift($openShorts);
                }
                // Remaining opens a new long
                if ($remQty > 0) $openLongs[] = [
                    'qty' => $remQty, 'price' => $exec['price'],
                    'time' => $exec['time'], 'trade_id' => $exec['trade_id'],
                ];

            } else { // sell
                // Close any open longs first (FIFO)
                while ($remQty > 0 && !empty($openLongs)) {
                    $long     = &$openLongs[0];
                    $matchQty = min($remQty, $long['qty']);
                    $trades[] = [
                        'symbol'      => $symbol,
                        'trade_type'  => 'buy',
                        'quantity'    => $matchQty,
                        'entry_price' => $long['price'],
                        'exit_price'  => $exec['price'],
                        'open_time'   => $long['time'],
                        'close_time'  => $exec['time'],
                        'profit_loss' => round(($exec['price'] - $long['price']) * $matchQty, 2),
                        'ticket'      => $long['trade_id'],
                    ];
                    $long['qty'] -= $matchQty;
                    $remQty      -= $matchQty;
                    if ($long['qty'] <= 0) array_shift($openLongs);
                }
                // Remaining opens a new short
                if ($remQty > 0) $openShorts[] = [
                    'qty' => $remQty, 'price' => $exec['price'],
                    'time' => $exec['time'], 'trade_id' => $exec['trade_id'],
                ];
            }
        }

        foreach ($openLongs  as $l) $unmatched[] = ['symbol' => $symbol, 'type' => 'long',  'qty' => $l['qty'], 'price' => $l['price'], 'time' => $l['time']];
        foreach ($openShorts as $s) $unmatched[] = ['symbol' => $symbol, 'type' => 'short', 'qty' => $s['qty'], 'price' => $s['price'], 'time' => $s['time']];
    }

    // Sort completed trades by close time
    usort($trades, fn($a, $b) => strcmp($a['close_time'], $b['close_time']));
    return ['trades' => $trades, 'unmatched' => $unmatched];
}

// ── Handle actions ────────────────────────────────────────────────────────────
$msg       = '';
$msgType   = '';
$preview   = [];
$unmatched = [];
$action    = $_POST['action'] ?? '';

if ($action === 'preview' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if ($file && is_readable($file)) {
        $rows = parseZerodhaCSV($file);
        if (isset($rows['error'])) {
            $msg = 'Not a valid Zerodha tradebook: ' . $rows['error']; $msgType = 'error';
        } elseif (empty($rows)) {
            $msg = 'No valid rows found in the file.'; $msgType = 'error';
        } else {
            $result    = pairZerodhaTrades($rows);
            $preview   = array_slice($result['trades'], 0, 25);
            $unmatched = $result['unmatched'];
        }
    }
}

if ($action === 'import' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if (!$file || !is_readable($file)) {
        $msg = 'Could not read uploaded file.'; $msgType = 'error';
    } else {
        $rows = parseZerodhaCSV($file);
        if (isset($rows['error'])) {
            $msg = 'Not a valid Zerodha tradebook: ' . $rows['error']; $msgType = 'error';
        } else {
            $result    = pairZerodhaTrades($rows);
            $trades    = $result['trades'];
            $unmatched = $result['unmatched'];
            $inserted  = 0; $skipped = 0; $errors = [];

            $stmt = $db->prepare("INSERT IGNORE INTO trades
                (user_id, trade_datetime, open_time, symbol, trade_type, entry_price, exit_price,
                 quantity, profit_loss, brokerage, swap, close_reason, ticket, import_source, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, ?, 'zerodha_import', '')");

            foreach ($trades as $t) {
                try {
                    $stmt->execute([
                        $userId,
                        $t['close_time'],
                        $t['open_time'] ?: null,
                        $t['symbol'],
                        $t['trade_type'],
                        $t['entry_price'],
                        $t['exit_price'],
                        $t['quantity'],
                        $t['profit_loss'],
                        $t['ticket'],
                    ]);
                    if ($stmt->rowCount() > 0) $inserted++; else $skipped++;
                } catch (Exception $e) {
                    $errors[] = $t['symbol'] . ': ' . $e->getMessage(); $skipped++;
                }
            }

            if ($inserted > 0) {
                $msg = "✓ Import complete: $inserted trades imported" . ($skipped > 0 ? ", $skipped skipped (duplicates)." : ".");
                if (!empty($unmatched)) $msg .= ' Note: ' . count($unmatched) . ' unmatched execution(s) skipped (open positions not closed in this file).';
                $msgType = 'success';
            } else {
                $msg = "No new trades imported. $skipped rows skipped (already exist or invalid).";
                $msgType = 'error';
            }
            if ($errors) $msg .= ' Errors: ' . implode(' | ', array_slice($errors, 0, 3));
        }
    }
}

if ($action === 'delete_imported') {
    $stmt = $db->prepare("DELETE FROM trades WHERE user_id = ? AND import_source = 'zerodha_import'");
    $stmt->execute([$userId]);
    $msg = 'All Zerodha-imported trades deleted (' . $stmt->rowCount() . ' rows).';
    $msgType = 'error';
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalStmt = $db->prepare("SELECT COUNT(*) as cnt FROM trades WHERE user_id = ? AND import_source = 'zerodha_import'");
$totalStmt->execute([$userId]);
$importedTotal = $totalStmt->fetch()['cnt'];

$statsStmt = $db->prepare("
    SELECT COALESCE(SUM(profit_loss),0) as gross_pl,
           MIN(trade_datetime) as first_trade,
           MAX(trade_datetime) as last_trade
    FROM trades WHERE user_id = ? AND import_source = 'zerodha_import'");
$statsStmt->execute([$userId]);
$iStats = $statsStmt->fetch();

$pageTitle = 'Zerodha Import';
$rootPath  = '../';
include '../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── LEFT ──────────────────────────────────────────────────────────── -->
    <div class="col-lg-5">

        <!-- Upload card -->
        <div class="panel mb-4">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-file-arrow-up"></i> Import Zerodha Tradebook</div>
            </div>
            <div class="panel-body">

                <!-- Format note -->
                <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:var(--radius);padding:12px 14px;margin-bottom:16px;font-size:12px;line-height:1.6">
                    <div style="font-weight:700;color:var(--accent);margin-bottom:4px"><i class="fas fa-info-circle"></i> How Zerodha tradebook works</div>
                    <div style="color:var(--text-muted)">
                        Each row is a single execution (buy <em>or</em> sell). This importer pairs buy &amp; sell legs for the same symbol using <strong>FIFO matching</strong> and computes the P&amp;L automatically.
                        Times are already in <strong>IST</strong> — no conversion applied.
                        Brokerage is not in the tradebook; it is stored as <strong>₹0</strong>.
                    </div>
                </div>

                <!-- Drag-drop zone -->
                <div class="import-dropzone" id="dropzone">
                    <i class="fas fa-cloud-arrow-up" style="font-size:32px;color:var(--accent);margin-bottom:10px"></i>
                    <div style="font-weight:600;margin-bottom:4px">Drop tradebook CSV here</div>
                    <div style="font-size:12px;color:var(--text-muted)">or click to browse</div>
                    <div id="dropzoneName" style="font-size:12px;color:var(--accent);margin-top:8px;display:none"></div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="previewForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="preview">
                    <input type="file" name="csvfile" id="csvPreviewInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-secondary-custom w-100 mt-3" id="previewBtn" disabled>
                        <i class="fas fa-eye"></i> Preview Paired Trades
                    </button>
                </form>

                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import">
                    <input type="file" name="csvfile" id="csvImportInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-primary-custom w-100 mt-2" id="importBtn" disabled>
                        <i class="fas fa-file-import"></i> Import All Trades
                    </button>
                </form>
            </div>
        </div>

        <!-- Unmatched warning (shown after preview) -->
        <?php if (!empty($unmatched)): ?>
        <div class="panel mb-4" style="border-color:rgba(234,179,8,.3)">
            <div class="panel-header" style="background:rgba(234,179,8,.06)">
                <div class="panel-title" style="color:var(--warning)"><i class="fas fa-triangle-exclamation"></i> <?= count($unmatched) ?> Unmatched Execution(s)</div>
            </div>
            <div class="panel-body" style="padding:0">
                <div style="padding:10px 14px;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">
                    These positions have no matching opposite leg in this file (open positions or cross-day trades). They will be skipped on import.
                </div>
                <?php foreach (array_slice($unmatched, 0, 10) as $u): ?>
                <div style="display:flex;justify-content:space-between;padding:8px 14px;border-bottom:1px solid var(--border);font-size:12px">
                    <span style="font-family:var(--font-mono);font-size:11px"><?= htmlspecialchars($u['symbol']) ?></span>
                    <span style="color:<?= $u['type']==='long' ? 'var(--profit)' : 'var(--loss)' ?>">
                        <?= strtoupper($u['type']) ?> <?= number_format($u['qty'],0) ?> @ <?= number_format($u['price'],2) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (count($unmatched) > 10): ?>
                <div style="padding:8px 14px;font-size:11px;color:var(--text-muted)">…and <?= count($unmatched)-10 ?> more</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Existing import summary -->
        <?php if ($importedTotal > 0): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-database"></i> Zerodha Import Summary</div>
            </div>
            <div class="panel-body">
                <div class="metric-row"><span class="metric-label">Imported Trades</span><span class="metric-value"><?= $importedTotal ?></span></div>
                <div class="metric-row"><span class="metric-label">Date Range</span><span class="metric-value" style="font-size:11px"><?= date('d M Y', strtotime($iStats['first_trade'])) ?> → <?= date('d M Y', strtotime($iStats['last_trade'])) ?></span></div>
                <div class="metric-row">
                    <span class="metric-label" style="font-weight:700">Gross P&amp;L</span>
                    <span class="metric-value <?= $iStats['gross_pl'] >= 0 ? 'text-profit' : 'text-loss' ?>" style="font-weight:800;font-size:15px"><?= formatPL($iStats['gross_pl']) ?></span>
                </div>
                <hr class="divider">
                <form method="POST" onsubmit="return confirm('Delete ALL <?= $importedTotal ?> Zerodha-imported trades? This cannot be undone.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_imported">
                    <button type="submit" class="btn-secondary-custom w-100" style="color:var(--loss);border-color:var(--loss)">
                        <i class="fas fa-trash"></i> Remove All Zerodha Trades
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Expected format reference -->
        <div class="panel mt-4">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-info-circle"></i> Expected Columns</div>
            </div>
            <div class="panel-body" style="font-size:12px">
                <p style="color:var(--text-muted);margin-bottom:10px">Zerodha tradebook CSV columns used by this importer:</p>
                <div class="csv-col-map">
                    <?php
                    $zcols = [
                        ['symbol',                'Instrument name (e.g. BANKNIFTY…CE)'],
                        ['trade_type',            'buy or sell'],
                        ['quantity',              'Number of units / lot size'],
                        ['price',                 'Execution price'],
                        ['trade_id',              'Used as ticket for deduplication'],
                        ['order_execution_time',  'Trade timestamp (IST, no conversion)'],
                        ['isin / series / expiry','Ignored'],
                    ];
                    foreach ($zcols as $zc): ?>
                    <div class="csv-col-row">
                        <span class="csv-col-name" style="width:180px"><?= $zc[0] ?></span>
                        <span class="csv-col-maps"><?= $zc[1] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- ── RIGHT: Preview table ───────────────────────────────────────────── -->
    <div class="col-lg-7">
        <?php if (!empty($preview)): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-eye"></i> Preview — <?= count($preview) ?> Paired Trades</div>
                <span class="panel-link" style="color:var(--warning)">Review before importing</span>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:11px">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Dir</th>
                            <th>Qty</th>
                            <th>Open Time</th>
                            <th>Close Time</th>
                            <th>Entry</th>
                            <th>Exit</th>
                            <th>P&amp;L (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview as $t):
                        $pl = floatval($t['profit_loss']);
                    ?>
                    <tr>
                        <td style="font-family:var(--font-mono);font-size:10px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($t['symbol']) ?>">
                            <?= htmlspecialchars($t['symbol']) ?>
                        </td>
                        <td><span class="type-badge type-<?= $t['trade_type'] ?>"><?= strtoupper($t['trade_type']) ?></span></td>
                        <td><?= number_format($t['quantity'], 0) ?></td>
                        <td style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($t['open_time']) ?></td>
                        <td style="font-size:10px"><?= htmlspecialchars($t['close_time']) ?></td>
                        <td class="mono"><?= number_format(floatval($t['entry_price']), 2) ?></td>
                        <td class="mono"><?= number_format(floatval($t['exit_price']), 2) ?></td>
                        <td class="<?= $pl >= 0 ? 'pl-positive' : 'pl-negative' ?>" style="font-weight:700">
                            <?= formatPL($pl) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border)">
                Showing first <?= count($preview) ?> paired trades.
                P&amp;L = (exit − entry) × qty for longs; (entry − exit) × qty for shorts.
                Ready to import? Click <strong>Import All Trades</strong>.
            </div>
        </div>
        <?php else: ?>
        <div class="panel">
            <div class="panel-body" style="text-align:center;padding:60px 20px">
                <i class="fas fa-chart-gantt" style="font-size:48px;color:var(--border);margin-bottom:16px;display:block"></i>
                <div style="font-size:16px;font-weight:600;margin-bottom:8px">No Preview Yet</div>
                <div style="color:var(--text-muted);font-size:13px">
                    Upload your Zerodha tradebook CSV and click <strong>Preview Paired Trades</strong>
                    to see how buy/sell executions are matched before importing.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
const csvPreviewInput = document.getElementById('csvPreviewInput');
const csvImportInput  = document.getElementById('csvImportInput');
const previewBtn      = document.getElementById('previewBtn');
const importBtn       = document.getElementById('importBtn');
const dropzone        = document.getElementById('dropzone');
const dropzoneName    = document.getElementById('dropzoneName');

function onFileSelected(file) {
    if (!file || !file.name.endsWith('.csv')) { alert('Please select a .csv file'); return; }
    dropzoneName.textContent   = '📄 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    dropzoneName.style.display = 'block';
    previewBtn.disabled = false;
    importBtn.disabled  = false;
    const dt = new DataTransfer();
    dt.items.add(file);
    csvPreviewInput.files = dt.files;
    csvImportInput.files  = dt.files;
}

csvPreviewInput.addEventListener('change', function() { if (this.files[0]) onFileSelected(this.files[0]); });

dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e => {
    e.preventDefault(); dropzone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) onFileSelected(e.dataTransfer.files[0]);
});
dropzone.addEventListener('click', function(e) {
    if (e.target === dropzone || e.target.tagName !== 'INPUT') csvPreviewInput.click();
});
</script>

<style>
.w-100 { width: 100%; }
.import-dropzone {
    border: 2px dashed var(--border); border-radius: var(--radius);
    padding: 32px 20px; text-align: center; cursor: pointer;
    transition: border-color .2s, background .2s; background: var(--bg-elevated);
    display: flex; flex-direction: column; align-items: center;
}
.import-dropzone:hover, .import-dropzone.drag-over { border-color: var(--accent); background: rgba(59,130,246,.05); }
</style>

<?php include '../includes/footer.php'; ?>

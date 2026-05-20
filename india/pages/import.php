<?php
require_once '../config/db.php';
$db     = getDB();
$userId = INDIA_DEFAULT_USER;

$msg = ''; $msgType = ''; $preview = [];

// ── Dhan CSV Column map ───────────────────────────────────────────────────
// Date, Time, Name, Buy/Sell, Order, Exchange, Segment, Quantity/Lot,
// Trade Price, Trade Value, Status
// Col:  0     1     2        3          4       5        6       7
//       8            9              10

// ── Delete all imported ──────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete_imported') {
    $stmt = $db->prepare("DELETE FROM india_trades WHERE user_id=? AND import_source='dhan_csv'");
    $stmt->execute([$userId]);
    $msg = 'All Dhan CSV imported trades deleted ('.$stmt->rowCount().' rows).';
    $msgType = 'error';
}

// ── Full Import ───────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'import' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if (!$file || !is_readable($file)) {
        $msg = 'Could not read file.'; $msgType = 'error';
    } else {
        $handle   = fopen($file, 'r');
        $header   = fgetcsv($handle);
        $inserted = 0; $skipped = 0;

        // Group rows by Date+Name to pair BUY/SELL legs
        $groups = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 10) continue;
            $status = trim($row[10] ?? '');
            if (strtolower($status) !== 'traded') continue;
            $date = trim($row[0]);
            $name = trim($row[2]);
            $groups["$date||$name"][] = $row;
        }
        fclose($handle);

        $stmt = $db->prepare("INSERT INTO india_trades
            (user_id, trade_date, open_time, close_time, instrument, base_instrument,
             trade_type, order_type, exchange, segment, quantity,
             buy_price, sell_price, buy_value, sell_value,
             profit_loss, brokerage, net_pl, import_source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'dhan_csv')");

        foreach ($groups as $key => $legs) {
            [$dateStr, $name] = explode('||', $key);
            $buys  = array_filter($legs, fn($r) => strtoupper(trim($r[3])) === 'BUY');
            $sells = array_filter($legs, fn($r) => strtoupper(trim($r[3])) === 'SELL');
            if (empty($buys) || empty($sells)) { $skipped++; continue; }

            // Parse date MM/DD/YYYY
            $dateParts = explode('/', $dateStr);
            if (count($dateParts) === 3) {
                $tradeDate = sprintf('%04d-%02d-%02d', $dateParts[2], $dateParts[0], $dateParts[1]);
            } else { $skipped++; continue; }

            // Buy/Sell totals
            $buyVal   = array_sum(array_map(fn($r) => floatval($r[9]), $buys));
            $sellVal  = array_sum(array_map(fn($r) => floatval($r[9]), $sells));
            $qty      = array_sum(array_map(fn($r) => floatval($r[7]), $buys));
            $avgBuyP  = $qty > 0 ? $buyVal / $qty : 0;
            $avgSellP = $qty > 0 ? $sellVal / $qty : 0;
            $pl       = $sellVal - $buyVal;

            // Open time = earliest leg, Close time = latest leg
            usort($legs, fn($a,$b) => strcmp($a[1],$b[1]));
            $openTime  = $tradeDate . ' ' . trim($legs[0][1]);
            $closeTime = $tradeDate . ' ' . trim($legs[count($legs)-1][1]);

            $sample    = $legs[0];
            $exchange  = strtoupper(trim($sample[5]));
            $segment   = trim($sample[6]);
            $orderType = strtoupper(trim($sample[4]));
            $base      = strtok($name, ' '); // First word = NIFTY/BANKNIFTY etc

            // Simple brokerage: Zerodha/Dhan flat ₹20 per executed order or 0.03% whichever lower
            $brokPerLeg = 20.0;
            $brokerage  = min($brokPerLeg * count($legs), max($buyVal, $sellVal) * 0.0003);
            $netPL      = $pl - $brokerage;

            try {
                $stmt->execute([
                    $userId, $tradeDate, $openTime, $closeTime,
                    $name, $base, 'BUY', $orderType,
                    in_array($exchange,['NSE','BSE','MCX','NFO','BFO']) ? $exchange : 'NSE',
                    $segment, $qty,
                    round($avgBuyP,4), round($avgSellP,4),
                    round($buyVal,2), round($sellVal,2),
                    round($pl,2), round($brokerage,2), round($netPL,2)
                ]);
                $inserted++;
            } catch (Exception $e) { $skipped++; }
        }
        $msg = "Import complete: {$inserted} trades imported" . ($skipped ? ", {$skipped} skipped." : ".");
        $msgType = $inserted > 0 ? 'success' : 'error';
    }
}

// ── Preview ───────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'preview' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if ($file && is_readable($file)) {
        $handle = fopen($file,'r'); fgetcsv($handle);
        $tempGroups = [];
        while (($row = fgetcsv($handle)) !== false && count($tempGroups) < 40) {
            if (count($row) < 10 || strtolower(trim($row[10]??'')) !== 'traded') continue;
            $key = trim($row[0]).'||'.trim($row[2]);
            $tempGroups[$key][] = $row;
        }
        fclose($handle);
        foreach (array_slice($tempGroups,0,15) as $key => $legs) {
            [$d,$n] = explode('||',$key);
            $buys  = array_filter($legs, fn($r)=>strtoupper(trim($r[3]))==='BUY');
            $sells = array_filter($legs, fn($r)=>strtoupper(trim($r[3]))==='SELL');
            if (empty($buys)||empty($sells)) continue;
            $bv = array_sum(array_map(fn($r)=>floatval($r[9]),$buys));
            $sv = array_sum(array_map(fn($r)=>floatval($r[9]),$sells));
            $pl = $sv - $bv;
            $brok = min(20*count($legs), max($bv,$sv)*0.0003);
            $preview[] = ['date'=>$d,'name'=>$n,'legs'=>count($legs),
                'buy_val'=>$bv,'sell_val'=>$sv,'pl'=>$pl,
                'brok'=>$brok,'net'=>$pl-$brok,
                'exchange'=>trim($legs[0][5]),'qty'=>floatval($legs[0][7])];
        }
    }
}

// Summary of already imported
$impStmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(profit_loss),0) as gross,
    COALESCE(SUM(brokerage),0) as brok, COALESCE(SUM(net_pl),0) as net,
    MIN(trade_date) as first, MAX(trade_date) as last
    FROM india_trades WHERE user_id=? AND import_source='dhan_csv'");
$impStmt->execute([$userId]);
$impStats = $impStmt->fetch();

$pageTitle = 'Dhan CSV Import';
$rootPath  = '../';
include '../includes/header.php';
?>
<style>
.import-dropzone{border:2px dashed var(--border);border-radius:var(--radius);padding:32px 20px;
    text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--bg-elevated);
    display:flex;flex-direction:column;align-items:center;}
.import-dropzone:hover,.import-dropzone.drag-over{border-color:var(--accent);background:rgba(37,99,235,.05);}
.col-map{display:flex;flex-direction:column;gap:2px;}
.col-row{display:flex;gap:8px;align-items:center;padding:4px 6px;border-radius:4px;font-size:11px;}
.col-hl{background:rgba(59,130,246,.07);}
.col-num{width:18px;font-family:var(--font-mono);color:var(--text-muted);flex-shrink:0;}
.col-name{width:130px;font-family:var(--font-mono);color:var(--text-secondary);flex-shrink:0;}
.col-maps{flex:1;color:var(--text-primary);}
.col-hl .col-maps{color:var(--accent);font-weight:600;}
.w-100{width:100%;}
</style>

<div class="row g-4">
    <div class="col-lg-5">
        <!-- Upload -->
        <div class="panel mb-4">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-file-csv"></i> Import Dhan Trade History</div></div>
            <div class="panel-body">
                <?php if ($msg): ?>
                <div class="alert-custom alert-<?= $msgType==='success'?'success':'error' ?> mb-3">
                    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-xmark' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
                    Dhan broker ka <strong>Trade History CSV</strong> upload karo. BUY/SELL legs automatically pair hoti hain aur ek complete trade banta hai.
                    <br><br><strong>Brokerage:</strong> ₹20 per order ya 0.03% (jo bhi kam ho) — Dhan/Zerodha flat fee model.
                </p>
                <div class="import-dropzone" id="dropzone" onclick="document.getElementById('csvPreviewInput').click()">
                    <i class="fas fa-cloud-arrow-up" style="font-size:32px;color:var(--accent);margin-bottom:10px"></i>
                    <div style="font-weight:600;margin-bottom:4px">Drop Dhan CSV here</div>
                    <div style="font-size:12px;color:var(--text-muted)">ya click karke browse karo</div>
                    <div id="dropzoneName" style="font-size:12px;color:var(--accent);margin-top:8px;display:none"></div>
                </div>
                <form method="POST" enctype="multipart/form-data" id="previewForm">
                    <input type="hidden" name="action" value="preview">
                    <input type="file" name="csvfile" id="csvPreviewInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-secondary-custom w-100 mt-3" id="previewBtn" disabled>
                        <i class="fas fa-eye"></i> Preview (first 15 trades)
                    </button>
                </form>
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="action" value="import">
                    <input type="file" name="csvfile" id="csvImportInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-primary-custom w-100 mt-2" id="importBtn" disabled>
                        <i class="fas fa-file-import"></i> Import All Trades
                    </button>
                </form>
            </div>
        </div>

        <!-- Column Map -->
        <div class="panel mb-4">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-table"></i> Dhan CSV Format</div></div>
            <div class="panel-body">
                <div class="col-map">
                    <?php $cols=[
                        ['Col','Dhan Field','Maps To'],
                        [0,'Date','trade_date (MM/DD/YYYY)'],
                        [1,'Time','open/close time'],
                        [2,'Name','instrument name'],
                        [3,'Buy/Sell','trade direction'],
                        [4,'Order','INTRADAY / MARGIN'],
                        [5,'Exchange','NSE / BSE'],
                        [6,'Segment','Derivative'],
                        [7,'Quantity/Lot','→ quantity'],
                        [8,'Trade Price','→ buy/sell price'],
                        [9,'Trade Value','→ buy/sell value ₹'],
                        [10,'Status','Traded only'],
                    ];
                    foreach ($cols as $i=>$c): $hl=in_array((int)$c[0],[7,8,9]); ?>
                    <div class="col-row <?= $i===0?'':''.($hl?'col-hl':'') ?>">
                        <span class="col-num"><?= $c[0] ?></span>
                        <span class="col-name"><?= $c[1] ?></span>
                        <span class="col-maps"><?= $c[2] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:10px;padding:8px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                    <strong>P&amp;L Logic:</strong> Same instrument ke saare BUY aur SELL legs ek din mein pair hote hain.<br>
                    <strong>Net P&amp;L</strong> = Sell Value − Buy Value − Brokerage
                </div>
            </div>
        </div>

        <!-- Imported Summary -->
        <?php if ($impStats['cnt'] > 0): ?>
        <div class="panel">
            <div class="panel-header"><div class="panel-title"><i class="fas fa-database"></i> Imported Data</div></div>
            <div class="panel-body">
                <div class="metric-row"><span class="metric-label">Trades Imported</span><span class="metric-value"><?= $impStats['cnt'] ?></span></div>
                <div class="metric-row"><span class="metric-label">Date Range</span><span class="metric-value" style="font-size:11px"><?= date('d M Y',strtotime($impStats['first'])) ?> → <?= date('d M Y',strtotime($impStats['last'])) ?></span></div>
                <div class="metric-row"><span class="metric-label">Gross P&amp;L</span><span class="metric-value <?= $impStats['gross']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($impStats['gross']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Total Brokerage</span><span class="metric-value" style="color:var(--loss)">-<?= formatINR($impStats['brok']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Net P&amp;L</span><span class="metric-value <?= $impStats['net']>=0?'pl-positive':'pl-negative' ?>" style="font-weight:800;font-size:15px"><?= formatINR_PL($impStats['net']) ?></span></div>
                <hr class="divider">
                <form method="POST" onsubmit="return confirm('Delete all <?= $impStats['cnt'] ?> imported trades?')">
                    <input type="hidden" name="action" value="delete_imported">
                    <button type="submit" class="btn-secondary-custom w-100" style="color:var(--loss);border-color:var(--loss)">
                        <i class="fas fa-trash"></i> Remove All Dhan Imports
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Preview Table -->
    <div class="col-lg-7">
        <?php if (!empty($preview)): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-eye"></i> Preview — <?= count($preview) ?> Trades</div>
                <span class="panel-link" style="color:var(--warning)">Verify before importing</span>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:11px">
                    <thead><tr>
                        <th>Date</th><th>Instrument</th><th>Exch</th><th>Qty</th><th>Legs</th>
                        <th>Buy ₹</th><th>Sell ₹</th><th>Gross P&amp;L</th><th>Brok.</th><th>Net P&amp;L</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r): ?>
                    <tr>
                        <td><?= $r['date'] ?></td>
                        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['name']) ?>">
                            <span class="symbol-badge" style="font-size:9px"><?= strtok($r['name'],' ') ?></span>
                            <span style="font-size:10px;color:var(--text-muted)"> <?= implode(' ', array_slice(explode(' ',$r['name']),1)) ?></span>
                        </td>
                        <td><?= htmlspecialchars($r['exchange']) ?></td>
                        <td><?= $r['qty'] ?></td>
                        <td style="text-align:center"><?= $r['legs'] ?></td>
                        <td>₹<?= number_format($r['buy_val'],2) ?></td>
                        <td>₹<?= number_format($r['sell_val'],2) ?></td>
                        <td class="<?= $r['pl']>=0?'pl-positive':'pl-negative' ?>"><?= formatINR_PL($r['pl']) ?></td>
                        <td style="color:var(--loss)">-₹<?= number_format($r['brok'],2) ?></td>
                        <td class="<?= $r['net']>=0?'pl-positive':'pl-negative' ?>" style="font-weight:700"><?= formatINR_PL($r['net']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="panel">
            <div class="panel-body" style="text-align:center;padding:60px 20px">
                <i class="fas fa-file-csv" style="font-size:48px;color:var(--border);margin-bottom:16px;display:block"></i>
                <div style="font-size:16px;font-weight:600;margin-bottom:8px">Preview yahan dikhega</div>
                <div style="color:var(--text-muted);font-size:13px">CSV upload karo aur Preview click karo.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const csvPreviewInput = document.getElementById('csvPreviewInput');
const previewBtn = document.getElementById('previewBtn');
const importBtn  = document.getElementById('importBtn');
const dropzone   = document.getElementById('dropzone');
const dropzoneName = document.getElementById('dropzoneName');

function onFileSelected(file) {
    if (!file || !file.name.endsWith('.csv')) { alert('Please select a .csv file'); return; }
    dropzoneName.textContent = '📄 ' + file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    dropzoneName.style.display = 'block';
    previewBtn.disabled = false;
    importBtn.disabled  = false;
    const dt = new DataTransfer(); dt.items.add(file);
    csvPreviewInput.files = dt.files;
    document.getElementById('csvImportInput').files = dt.files;
}
csvPreviewInput.addEventListener('change', function() { if(this.files[0]) onFileSelected(this.files[0]); });
dropzone.addEventListener('dragover', e=>{e.preventDefault();dropzone.classList.add('drag-over');});
dropzone.addEventListener('dragleave', ()=>dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e=>{e.preventDefault();dropzone.classList.remove('drag-over');if(e.dataTransfer.files[0])onFileSelected(e.dataTransfer.files[0]);});
dropzone.addEventListener('click', function(e){ if(e.target===dropzone||e.target.tagName!=='INPUT') csvPreviewInput.click(); });
</script>
<?php include '../includes/footer.php'; ?>

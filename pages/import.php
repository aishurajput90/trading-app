<?php
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();

$msg       = '';
$msgType   = '';
$preview   = [];
$importLog = [];

// ── CSV Column map for your broker format ─────────────────────────────────
// ticket,opening_time_utc,closing_time_utc,type,lots,original_position_size,
// symbol,opening_price,closing_price,stop_loss,take_profit,commission,swap,
// profit,equity,margin_level,close_reason
define('CSV_COL_TICKET',       0);
define('CSV_COL_OPEN_TIME',    1);
define('CSV_COL_CLOSE_TIME',   2);
define('CSV_COL_TYPE',         3);
define('CSV_COL_LOTS',         4);
define('CSV_COL_SYMBOL',       6);
define('CSV_COL_ENTRY',        7);
define('CSV_COL_EXIT',         8);
define('CSV_COL_COMMISSION',  11);  // brokerage — stored as negative in CSV
define('CSV_COL_SWAP',        12);
define('CSV_COL_PROFIT',      13);
define('CSV_COL_CLOSE_REASON',16);

function resolveProfileColumns(array $headers, array $profileMap, array $requiredFields): array {
    $headerIndex = [];
    foreach ($headers as $i => $h) $headerIndex[strtolower(trim($h))] = $i;
    $colIndex = $missing = [];
    foreach ($profileMap as $field => $csvCol) {
        if ($csvCol === '') continue;
        $csvLower = strtolower(trim($csvCol));
        if (isset($headerIndex[$csvLower])) $colIndex[$field] = $headerIndex[$csvLower];
        elseif (in_array($field, $requiredFields)) $missing[] = $field;
    }
    foreach ($requiredFields as $rf)
        if (!isset($colIndex[$rf]) && !in_array($rf, $missing)) $missing[] = $rf;
    return ['col_index' => $colIndex, 'missing_required' => $missing];
}

$selectedProfileId = (int)($_POST['broker_profile_id'] ?? 0);

$bpStmt = $db->prepare("SELECT id, name FROM broker_profiles WHERE user_id = ? ORDER BY name");
$bpStmt->execute([$userId]);
$brokerProfiles = $bpStmt->fetchAll();

$profileMap = [];
if ($selectedProfileId > 0) {
    $mapStmt = $db->prepare(
        "SELECT bcm.internal_field, bcm.csv_column_name
         FROM broker_column_maps bcm
         JOIN broker_profiles bp ON bp.id = bcm.profile_id
         WHERE bcm.profile_id = ? AND bp.user_id = ? AND bcm.csv_column_name != ''"
    );
    $mapStmt->execute([$selectedProfileId, $userId]);
    foreach ($mapStmt->fetchAll() as $r)
        $profileMap[$r['internal_field']] = strtolower(trim($r['csv_column_name']));
}

// ── Handle preview (AJAX or form) ─────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Handle DELETE all imported ─────────────────────────────────────────────
if ($action === 'delete_imported') {
    $stmt = $db->prepare("DELETE FROM trades WHERE user_id = ? AND import_source = 'csv_import'");
    $stmt->execute([$userId]);
    $msg = 'All CSV-imported trades deleted (' . $stmt->rowCount() . ' rows).';
    $msgType = 'error';
}

// ── Handle full import ─────────────────────────────────────────────────────
if ($action === 'import' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if (!$file || !is_readable($file)) {
        $msg = 'Could not read uploaded file.'; $msgType = 'error';
    } else {
        $handle   = fopen($file, 'r');
        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        $stmt = $db->prepare("INSERT IGNORE INTO trades
            (user_id, trade_datetime, open_time, symbol, trade_type, entry_price, exit_price, quantity,
             profit_loss, brokerage, swap, close_reason, ticket, import_source, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'csv_import','')");

        if ($selectedProfileId === 0) {
            // ── Legacy path — fixed column positions ─────────────────────────
            fgetcsv($handle); // skip header row
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 14) { $skipped++; continue; }
                $ticket      = trim($row[CSV_COL_TICKET] ?? '');
                $rawOpen     = str_replace('T', ' ', trim($row[CSV_COL_OPEN_TIME]  ?? ''));
                $rawClose    = str_replace('T', ' ', trim($row[CSV_COL_CLOSE_TIME] ?? ''));
                $type        = strtolower(trim($row[CSV_COL_TYPE] ?? 'buy'));
                $lots        = floatval($row[CSV_COL_LOTS]       ?? 0);
                $symbol      = strtoupper(trim($row[CSV_COL_SYMBOL] ?? ''));
                $entry       = floatval($row[CSV_COL_ENTRY]      ?? 0);
                $exit        = floatval($row[CSV_COL_EXIT]       ?? 0);
                $commission  = floatval($row[CSV_COL_COMMISSION] ?? 0);
                $swap        = floatval($row[CSV_COL_SWAP]       ?? 0);
                $profit      = floatval($row[CSV_COL_PROFIT]     ?? 0);
                $closeReason = strtolower(trim($row[CSV_COL_CLOSE_REASON] ?? '')) ?: null;
                $brokerage   = abs($commission);
                if (!$rawClose) { $skipped++; continue; }
                $closeObj = new DateTime($rawClose, new DateTimeZone('UTC'));
                $closeObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $dt = $closeObj->format('Y-m-d H:i:s');
                $openTime = '';
                if ($rawOpen) {
                    $openObj = new DateTime($rawOpen, new DateTimeZone('UTC'));
                    $openObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $openTime = $openObj->format('Y-m-d H:i:s');
                }
                if (!in_array($type, ['buy','sell','buy_limit','sell_limit'])) $type = 'buy';
                try {
                    $stmt->execute([$userId, $dt, $openTime ?: null, $symbol, $type, $entry, $exit, $lots, $profit, $brokerage, $swap, $closeReason, $ticket]);
                    if ($stmt->rowCount() > 0) $inserted++; else $skipped++;
                } catch (Exception $e) {
                    $errors[] = "Row $ticket: " . $e->getMessage(); $skipped++;
                }
            }
        } else {
            // ── Profile path — header-name mapping ───────────────────────────
            $headers  = fgetcsv($handle) ?: [];
            $required = ['trade_datetime','trade_type','symbol','quantity','entry_price','exit_price','profit_loss'];
            $resolved = resolveProfileColumns($headers, $profileMap, $required);
            if (!empty($resolved['missing_required'])) {
                $msg     = 'Import aborted: required columns not found — ' . implode(', ', $resolved['missing_required']) . '. Check your broker profile mapping and ensure the CSV column headers match exactly.';
                $msgType = 'error';
            } else {
                $colIndex = $resolved['col_index'];
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 2) { $skipped++; continue; }
                    $get = function(string $field, $default = '') use ($colIndex, $row) {
                        $idx = $colIndex[$field] ?? null;
                        return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : $default;
                    };
                    $ticket      = $get('ticket');
                    $rawOpen     = str_replace('T', ' ', $get('open_time'));
                    $rawClose    = str_replace('T', ' ', $get('trade_datetime'));
                    $type        = strtolower($get('trade_type', 'buy'));
                    $lots        = floatval($get('quantity', 0));
                    $symbol      = strtoupper($get('symbol'));
                    $entry       = floatval($get('entry_price', 0));
                    $exit        = floatval($get('exit_price', 0));
                    $commission  = floatval($get('brokerage', 0));
                    $swap        = floatval($get('swap', 0));
                    $profit      = floatval($get('profit_loss', 0));
                    $closeReason = strtolower($get('close_reason')) ?: null;
                    $brokerage   = abs($commission);
                    if (!$rawClose) { $skipped++; continue; }
                    $closeObj = new DateTime($rawClose, new DateTimeZone('UTC'));
                    $closeObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $dt = $closeObj->format('Y-m-d H:i:s');
                    $openTime = '';
                    if ($rawOpen) {
                        $openObj = new DateTime($rawOpen, new DateTimeZone('UTC'));
                        $openObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                        $openTime = $openObj->format('Y-m-d H:i:s');
                    }
                    if (!in_array($type, ['buy','sell','buy_limit','sell_limit'])) $type = 'buy';
                    try {
                        $stmt->execute([$userId, $dt, $openTime ?: null, $symbol, $type, $entry, $exit, $lots, $profit, $brokerage, $swap, $closeReason, $ticket]);
                        if ($stmt->rowCount() > 0) $inserted++; else $skipped++;
                    } catch (Exception $e) {
                        $errors[] = "Row $ticket: " . $e->getMessage(); $skipped++;
                    }
                }
            }
        }
        fclose($handle);

        if (!$msg) {
            if ($inserted > 0) {
                $msg = "✓ Import complete: $inserted trades imported" . ($skipped > 0 ? ", $skipped skipped (duplicates/errors)." : ".");
                $msgType = 'success';
            } else {
                $msg = "No new trades imported. $skipped rows skipped (already exist or invalid).";
                $msgType = 'error';
            }
            if ($errors) $msg .= ' Errors: ' . implode(' | ', array_slice($errors,0,3));
        }
    }
}

// ── Preview (read CSV without inserting) ──────────────────────────────────
if ($action === 'preview' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile']['tmp_name'];
    if ($file && is_readable($file)) {
        $handle = fopen($file, 'r');
        if ($selectedProfileId === 0) {
            // ── Legacy path ───────────────────────────────────────────────────
            fgetcsv($handle); // skip header
            while (($row = fgetcsv($handle)) !== false && count($preview) < 20) {
                if (count($row) < 14) continue;
                $rawCT = str_replace('T', ' ', trim($row[CSV_COL_CLOSE_TIME] ?? ''));
                $ctIST = '';
                if ($rawCT) {
                    $ctObj = new DateTime($rawCT, new DateTimeZone('UTC'));
                    $ctObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $ctIST = $ctObj->format('Y-m-d H:i:s');
                }
                $preview[] = [
                    'ticket'       => $row[CSV_COL_TICKET]       ?? '',
                    'close_time'   => $ctIST ?: $rawCT,
                    'type'         => $row[CSV_COL_TYPE]          ?? '',
                    'symbol'       => $row[CSV_COL_SYMBOL]        ?? '',
                    'lots'         => $row[CSV_COL_LOTS]          ?? '',
                    'entry'        => $row[CSV_COL_ENTRY]         ?? '',
                    'exit'         => $row[CSV_COL_EXIT]          ?? '',
                    'commission'   => $row[CSV_COL_COMMISSION]    ?? 0,
                    'swap'         => $row[CSV_COL_SWAP]          ?? 0,
                    'profit'       => $row[CSV_COL_PROFIT]        ?? 0,
                    'close_reason' => $row[CSV_COL_CLOSE_REASON]  ?? '',
                ];
            }
        } else {
            // ── Profile path ─────────────────────────────────────────────────
            $headers  = fgetcsv($handle) ?: [];
            $required = ['trade_datetime','symbol','profit_loss'];
            $resolved = resolveProfileColumns($headers, $profileMap, $required);
            if (!empty($resolved['missing_required'])) {
                $missing = implode(', ', $resolved['missing_required']);
                $msg     = "Cannot preview: required columns not found — $missing. Check your broker profile mapping.";
                $msgType = 'error';
            } else {
                $colIndex = $resolved['col_index'];
                while (($row = fgetcsv($handle)) !== false && count($preview) < 20) {
                    if (count($row) < 2) continue;
                    $get = function(string $field, $default = '') use ($colIndex, $row) {
                        $idx = $colIndex[$field] ?? null;
                        return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : $default;
                    };
                    $rawCT = str_replace('T', ' ', $get('trade_datetime'));
                    $ctIST = '';
                    if ($rawCT) {
                        $ctObj = new DateTime($rawCT, new DateTimeZone('UTC'));
                        $ctObj->setTimezone(new DateTimeZone('Asia/Kolkata'));
                        $ctIST = $ctObj->format('Y-m-d H:i:s');
                    }
                    $preview[] = [
                        'ticket'       => $get('ticket'),
                        'close_time'   => $ctIST ?: $rawCT,
                        'type'         => $get('trade_type'),
                        'symbol'       => $get('symbol'),
                        'lots'         => $get('quantity'),
                        'entry'        => $get('entry_price'),
                        'exit'         => $get('exit_price'),
                        'commission'   => $get('brokerage', 0),
                        'swap'         => $get('swap', 0),
                        'profit'       => $get('profit_loss', 0),
                        'close_reason' => $get('close_reason'),
                    ];
                }
            }
        }
        fclose($handle);
    }
}

// ── Imported trades summary ────────────────────────────────────────────────
$importedCount = $db->prepare("SELECT COUNT(*) as cnt FROM trades WHERE user_id = ? AND import_source = 'csv_import'");
$importedCount->execute([$userId]);
$importedTotal = $importedCount->fetch()['cnt'];

$importedStats = $db->prepare("SELECT
    COUNT(*) as cnt,
    COALESCE(SUM(profit_loss),0)              as gross_pl,
    COALESCE(SUM(brokerage),0)                as total_brok,
    COALESCE(SUM(profit_loss - brokerage + swap),0) as net_pl,
    COALESCE(SUM(swap),0)                     as total_swap,
    MIN(trade_datetime)                        as first_trade,
    MAX(trade_datetime)                        as last_trade
    FROM trades WHERE user_id = ? AND import_source = 'csv_import'");
$importedStats->execute([$userId]);
$iStats = $importedStats->fetch();

$pageTitle = 'CSV Import';
$rootPath  = '../';
include '../includes/header.php';
?>

<!-- ── Alerts ──────────────────────────────────────────────────────────── -->
<?php if ($msg): ?>
<div class="alert-custom alert-<?= $msgType === 'success' ? 'success' : 'error' ?> mb-4">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── LEFT: Upload + Instructions ──────────────────────────────────── -->
    <div class="col-lg-5">

        <!-- Upload Card -->
        <div class="panel mb-4">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-file-csv"></i> Import Broker CSV</div>
            </div>
            <div class="panel-body">
                <!-- Broker profile selector -->
                <div class="mb-3">
                    <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:5px">
                        <i class="fas fa-sliders"></i> Broker Profile
                    </label>
                    <select id="profileSelect" class="form-select form-select-sm">
                        <option value="0" <?= $selectedProfileId === 0 ? 'selected' : '' ?>>Legacy Format (fixed column positions)</option>
                        <?php foreach ($brokerProfiles as $bp): ?>
                        <option value="<?= $bp['id'] ?>" <?= $selectedProfileId === (int)$bp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bp['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                        <a href="broker_mapper.php" style="color:var(--accent)"><i class="fas fa-sliders"></i> Manage Broker Profiles</a>
                    </div>
                </div>

                <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
                    Upload your broker's trade history CSV. Commission is stored as <strong>brokerage charge</strong> automatically.
                    Duplicate tickets are skipped — safe to re-import.
                </p>

                <!-- Drag-drop zone -->
                <div class="import-dropzone" id="dropzone">
                    <i class="fas fa-cloud-arrow-up" style="font-size:32px;color:var(--accent);margin-bottom:10px"></i>
                    <div style="font-weight:600;margin-bottom:4px">Drop CSV file here</div>
                    <div style="font-size:12px;color:var(--text-muted)">or click to browse</div>
                    <div id="dropzoneName" style="font-size:12px;color:var(--accent);margin-top:8px;display:none"></div>
                </div>

                <!-- Preview first -->
                <form method="POST" enctype="multipart/form-data" id="previewForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="preview">
                    <input type="hidden" name="broker_profile_id" id="previewProfileId" value="<?= $selectedProfileId ?>">
                    <input type="file" name="csvfile" id="csvPreviewInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-secondary-custom w-100 mt-3" id="previewBtn" disabled>
                        <i class="fas fa-eye"></i> Preview (first 20 rows)
                    </button>
                </form>

                <!-- Full import -->
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import">
                    <input type="hidden" name="broker_profile_id" id="importProfileId" value="<?= $selectedProfileId ?>">
                    <input type="file" name="csvfile" id="csvImportInput" accept=".csv" style="display:none">
                    <button type="submit" class="btn-primary-custom w-100 mt-2" id="importBtn" disabled>
                        <i class="fas fa-file-import"></i> Import All Trades
                    </button>
                </form>
            </div>
        </div>

        <!-- CSV Format Reference -->
        <div class="panel mb-4">
            <?php if ($selectedProfileId === 0): ?>
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-info-circle"></i> Expected CSV Format</div>
            </div>
            <div class="panel-body">
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Legacy format — columns must be in this exact order:</p>
                <div class="csv-col-map">
                    <?php
                    $cols = [
                        ['Col','Field','Maps To'],
                        [0,'ticket','Broker Ticket ID'],
                        [1,'opening_time_utc','Open Time'],
                        [2,'closing_time_utc','Close Time → trade_datetime'],
                        [3,'type','buy / sell → trade_type'],
                        [4,'lots','Position size → quantity'],
                        [5,'original_position_size','(ignored)'],
                        [6,'symbol','Symbol'],
                        [7,'opening_price','Entry Price'],
                        [8,'closing_price','Exit Price'],
                        [9,'stop_loss','(ignored)'],
                        [10,'take_profit','(ignored)'],
                        [11,'commission','→ brokerage (abs value)'],
                        [12,'swap','→ swap'],
                        [13,'profit','→ profit_loss'],
                        [14,'equity','(ignored)'],
                        [15,'margin_level','(ignored)'],
                        [16,'close_reason','sl / tp / user / so'],
                    ];
                    foreach ($cols as $i => $c): ?>
                    <div class="csv-col-row <?= $i===0 ? 'csv-col-header' : '' ?> <?= in_array((int)$c[0],[11,12,13,16]) ? 'csv-col-highlight' : '' ?>">
                        <span class="csv-col-num"><?= $c[0] ?></span>
                        <span class="csv-col-name"><?= $c[1] ?></span>
                        <span class="csv-col-maps"><?= $c[2] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else:
                $selProfile = null;
                foreach ($brokerProfiles as $bp) { if ((int)$bp['id'] === $selectedProfileId) { $selProfile = $bp; break; } }
                $activeMap  = $db->prepare("SELECT internal_field, csv_column_name FROM broker_column_maps WHERE profile_id = ? AND csv_column_name != '' ORDER BY internal_field");
                $activeMap->execute([$selectedProfileId]);
                $activeMappings = $activeMap->fetchAll();
                $fieldLabels = ['trade_datetime'=>'Close Time','open_time'=>'Open Time','trade_type'=>'Trade Type','symbol'=>'Symbol','quantity'=>'Quantity/Lots','entry_price'=>'Entry Price','exit_price'=>'Exit Price','ticket'=>'Ticket','brokerage'=>'Commission','swap'=>'Swap','profit_loss'=>'P/L','close_reason'=>'Close Reason'];
            ?>
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-map"></i> Profile: <?= htmlspecialchars($selProfile['name'] ?? 'Selected Profile') ?></div>
                <a href="broker_mapper.php?edit=<?= $selectedProfileId ?>" class="panel-link">Edit</a>
            </div>
            <div class="panel-body">
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
                    Matching CSV headers by name (case-insensitive). <?= count($activeMappings) ?>/12 fields mapped.
                </p>
                <div class="csv-col-map">
                    <div class="csv-col-row csv-col-header">
                        <span class="csv-col-name">Internal Field</span>
                        <span class="csv-col-maps">Your CSV Column</span>
                    </div>
                    <?php foreach ($activeMappings as $m): $isKey = in_array($m['internal_field'], ['trade_datetime','symbol','profit_loss']); ?>
                    <div class="csv-col-row <?= $isKey ? 'csv-col-highlight' : '' ?>">
                        <span class="csv-col-name"><?= htmlspecialchars($fieldLabels[$m['internal_field']] ?? $m['internal_field']) ?></span>
                        <span class="csv-col-maps"><?= htmlspecialchars($m['csv_column_name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Imported summary + delete -->
        <?php if ($importedTotal > 0): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-database"></i> Imported Data Summary</div>
            </div>
            <div class="panel-body">
                <div class="metric-row"><span class="metric-label">Imported Trades</span><span class="metric-value"><?= $importedTotal ?></span></div>
                <div class="metric-row"><span class="metric-label">Date Range</span><span class="metric-value" style="font-size:11px"><?= date('d M Y',strtotime($iStats['first_trade'])) ?> → <?= date('d M Y',strtotime($iStats['last_trade'])) ?></span></div>
                <div class="metric-row"><span class="metric-label">Gross P&amp;L</span><span class="metric-value <?= $iStats['gross_pl']>=0?'text-profit':'text-loss' ?>"><?= formatPL($iStats['gross_pl']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Total Brokerage Paid</span><span class="metric-value text-loss">-<?= formatUSD($iStats['total_brok']) ?></span></div>
                <div class="metric-row"><span class="metric-label">Total Swap</span><span class="metric-value <?= $iStats['total_swap']>=0?'text-profit':'text-loss' ?>"><?= formatPL($iStats['total_swap']) ?></span></div>
                <div class="metric-row"><span class="metric-label" style="font-weight:700">Net P&amp;L (after charges)</span><span class="metric-value <?= $iStats['net_pl']>=0?'text-profit':'text-loss' ?>" style="font-weight:800;font-size:15px"><?= formatPL($iStats['net_pl']) ?></span></div>
                <hr class="divider">
                <form method="POST" onsubmit="return confirm('Delete ALL <?= $importedTotal ?>
                    <?= csrfField() ?> imported trades? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete_imported">
                    <button type="submit" class="btn-secondary-custom w-100" style="color:var(--loss);border-color:var(--loss)">
                        <i class="fas fa-trash"></i> Remove All Imported Trades
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: Preview Table ──────────────────────────────────────────── -->
    <div class="col-lg-7">
        <?php if (!empty($preview)): ?>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-eye"></i> Preview — First <?= count($preview) ?> Rows</div>
                <span class="panel-link" style="color:var(--warning)">Review before importing</span>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:11px">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Close Time (IST)</th>
                            <th>Symbol</th>
                            <th>Type</th>
                            <th>Lots</th>
                            <th>Entry</th>
                            <th>Exit</th>
                            <th>Commission→Brok.</th>
                            <th>Swap</th>
                            <th>Profit</th>
                            <th>Net</th>
                            <th>Close</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview as $r):
                        $brok   = abs(floatval($r['commission']));
                        $swap   = floatval($r['swap']);
                        $profit = floatval($r['profit']);
                        $net    = $profit - $brok + $swap;
                        $reasonColors = ['sl'=>'var(--loss)','tp'=>'var(--profit)','user'=>'var(--text-muted)','so'=>'var(--warning)'];
                        $rColor = $reasonColors[$r['close_reason']] ?? 'var(--text-muted)';
                    ?>
                    <tr>
                        <td style="font-family:var(--font-mono);color:var(--text-muted)"><?= htmlspecialchars($r['ticket']) ?></td>
                        <td><?= htmlspecialchars($r['close_time']) ?></td>
                        <td><span class="symbol-badge"><?= strtoupper($r['symbol']) ?></span></td>
                        <td><span class="type-badge type-<?= $r['type'] ?>"><?= strtoupper($r['type']) ?></span></td>
                        <td><?= $r['lots'] ?></td>
                        <td class="mono"><?= number_format(floatval($r['entry']),4) ?></td>
                        <td class="mono"><?= number_format(floatval($r['exit']),4) ?></td>
                        <td class="pl-negative">-<?= formatUSD($brok) ?></td>
                        <td class="<?= $swap>=0?'pl-positive':'pl-negative' ?>"><?= $swap!=0 ? formatPL($swap) : '—' ?></td>
                        <td class="<?= $profit>=0?'pl-positive':'pl-negative' ?>"><?= formatPL($profit) ?></td>
                        <td class="<?= $net>=0?'pl-positive':'pl-negative' ?>" style="font-weight:700"><?= formatPL($net) ?></td>
                        <td><span style="font-size:10px;font-weight:700;color:<?= $rColor ?>"><?= strtoupper($r['close_reason']) ?: '—' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border)">
                Showing first <?= count($preview) ?> rows. Ready to import? Use the <strong>Import All Trades</strong> button on the left.
            </div>
        </div>
        <?php else: ?>
        <div class="panel">
            <div class="panel-body" style="text-align:center;padding:60px 20px">
                <i class="fas fa-file-csv" style="font-size:48px;color:var(--border);margin-bottom:16px;display:block"></i>
                <div style="font-size:16px;font-weight:600;margin-bottom:8px">No Preview Yet</div>
                <div style="color:var(--text-muted);font-size:13px">Upload a CSV file and click <strong>Preview</strong> to see the first 20 rows before importing.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Shared file selection: one input drives both forms ─────────────────────
const csvPreviewInput = document.getElementById('csvPreviewInput');
const csvImportInput  = document.getElementById('csvImportInput');
const previewBtn      = document.getElementById('previewBtn');
const importBtn       = document.getElementById('importBtn');
const dropzone        = document.getElementById('dropzone');
const dropzoneName    = document.getElementById('dropzoneName');
let   selectedFile    = null;

const profileSelect    = document.getElementById('profileSelect');
const previewProfileId = document.getElementById('previewProfileId');
const importProfileId  = document.getElementById('importProfileId');

function syncProfileId() {
    const val = profileSelect ? profileSelect.value : '0';
    if (previewProfileId) previewProfileId.value = val;
    if (importProfileId)  importProfileId.value  = val;
}
if (profileSelect) profileSelect.addEventListener('change', syncProfileId);

function onFileSelected(file) {
    if (!file || !file.name.endsWith('.csv')) { alert('Please select a .csv file'); return; }
    selectedFile = file;
    dropzoneName.textContent  = '📄 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    dropzoneName.style.display = 'block';
    previewBtn.disabled = false;
    importBtn.disabled  = false;
    syncProfileId();

    // Inject into both form inputs via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(file);
    csvPreviewInput.files = dt.files;
    csvImportInput.files  = dt.files;
}

csvPreviewInput.addEventListener('change', function() { if (this.files[0]) onFileSelected(this.files[0]); });

// Drag and drop
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('drag-over'));
dropzone.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) onFileSelected(e.dataTransfer.files[0]);
});

// Prevent double-click re-open when clicking inside zone with file already selected
dropzone.addEventListener('click', function(e) {
    if (e.target === dropzone || e.target.tagName !== 'INPUT') {
        csvPreviewInput.click();
    }
});
</script>

<style>
.import-dropzone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 32px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: var(--bg-elevated);
    display: flex;
    flex-direction: column;
    align-items: center;
}
.import-dropzone:hover, .import-dropzone.drag-over {
    border-color: var(--accent);
    background: rgba(59,130,246,.05);
}
.csv-col-map { display: flex; flex-direction: column; gap: 2px; }
.csv-col-row { display: flex; gap: 8px; align-items: center; padding: 4px 6px; border-radius: 4px; font-size: 11px; }
.csv-col-header { font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); border-bottom: 1px solid var(--border); padding-bottom: 6px; margin-bottom: 4px; }
.csv-col-highlight { background: rgba(59,130,246,.07); }
.csv-col-num  { width: 20px; font-family: var(--font-mono); color: var(--text-muted); flex-shrink: 0; }
.csv-col-name { width: 160px; font-family: var(--font-mono); color: var(--text-secondary); flex-shrink: 0; }
.csv-col-maps { color: var(--text-primary); flex: 1; }
.csv-col-highlight .csv-col-maps { color: var(--accent); font-weight: 600; }
.w-100 { width: 100%; }
</style>

<?php include '../includes/footer.php'; ?>

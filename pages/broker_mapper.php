<?php
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();

$FIELD_DEFS = [
    'trade_datetime' => ['label' => 'Close Time',            'desc' => 'When the trade closed (stored as trade_datetime). UTC times are auto-converted to IST.', 'required' => true,  'hint' => 'closing_time_utc'],
    'open_time'      => ['label' => 'Open Time',             'desc' => 'When the trade opened. UTC times are auto-converted to IST.',                             'required' => false, 'hint' => 'opening_time_utc'],
    'trade_type'     => ['label' => 'Trade Type',            'desc' => 'Direction: buy, sell, buy_limit, sell_limit. Other values default to buy.',               'required' => true,  'hint' => 'type'],
    'symbol'         => ['label' => 'Symbol',                'desc' => 'Instrument ticker (e.g. EURUSD, BTCUSDT). Stored in uppercase.',                          'required' => true,  'hint' => 'symbol'],
    'quantity'       => ['label' => 'Quantity / Lots',       'desc' => 'Position size in lots, units, or contracts.',                                             'required' => true,  'hint' => 'lots'],
    'entry_price'    => ['label' => 'Entry Price',           'desc' => 'Opening price of the trade.',                                                             'required' => true,  'hint' => 'opening_price'],
    'exit_price'     => ['label' => 'Exit Price',            'desc' => 'Closing price of the trade.',                                                             'required' => true,  'hint' => 'closing_price'],
    'ticket'         => ['label' => 'Ticket / Order ID',     'desc' => 'Broker order number. Used for duplicate detection on re-import.',                         'required' => false, 'hint' => 'ticket'],
    'brokerage'      => ['label' => 'Commission / Brokerage','desc' => 'Commission charged. Negative values are automatically converted to positive.',            'required' => false, 'hint' => 'commission'],
    'swap'           => ['label' => 'Swap',                  'desc' => 'Overnight swap/rollover charge (can be negative).',                                       'required' => false, 'hint' => 'swap'],
    'profit_loss'    => ['label' => 'Profit / Loss',         'desc' => 'Raw P/L of the trade (positive = profit, negative = loss).',                              'required' => true,  'hint' => 'profit'],
    'close_reason'   => ['label' => 'Close Reason',          'desc' => 'Why the trade closed: sl, tp, user, so. Other values stored as-is.',                      'required' => false, 'hint' => 'close_reason'],
];

$msg     = '';
$msgType = '';
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$detectedHeaders = [];

// ── POST Actions ──────────────────────────────────────────────────────────────

if ($action === 'create_profile') {
    $name  = trim($_POST['name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$name) {
        $msg = 'Profile name is required.'; $msgType = 'error';
    } else {
        $stmt = $db->prepare("INSERT INTO broker_profiles (user_id, name, notes) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $name, $notes]);
        $newId = (int)$db->lastInsertId();
        $seedStmt = $db->prepare("INSERT IGNORE INTO broker_column_maps (profile_id, internal_field, csv_column_name) VALUES (?, ?, '')");
        foreach (array_keys($FIELD_DEFS) as $field) $seedStmt->execute([$newId, $field]);
        header("Location: broker_mapper.php?edit=$newId&created=1");
        exit;
    }
}

if ($action === 'update_profile') {
    $profileId = (int)($_POST['profile_id'] ?? 0);
    $check = $db->prepare("SELECT id FROM broker_profiles WHERE id = ? AND user_id = ?");
    $check->execute([$profileId, $userId]);
    if (!$check->fetch()) {
        $msg = 'Profile not found.'; $msgType = 'error';
    } else {
        $db->prepare("UPDATE broker_profiles SET name = ?, notes = ? WHERE id = ?")->execute([
            trim($_POST['name'] ?? ''), trim($_POST['notes'] ?? ''), $profileId
        ]);
        $upMap = $db->prepare("UPDATE broker_column_maps SET csv_column_name = ? WHERE profile_id = ? AND internal_field = ?");
        $mapData = $_POST['map'] ?? [];
        foreach (array_keys($FIELD_DEFS) as $field) $upMap->execute([trim($mapData[$field] ?? ''), $profileId, $field]);
        $msg = 'Profile saved successfully.'; $msgType = 'success';
        $editId = $profileId;
    }
}

if ($action === 'delete_profile') {
    $profileId = (int)($_POST['profile_id'] ?? 0);
    $check = $db->prepare("SELECT id FROM broker_profiles WHERE id = ? AND user_id = ?");
    $check->execute([$profileId, $userId]);
    if ($check->fetch()) $db->prepare("DELETE FROM broker_profiles WHERE id = ?")->execute([$profileId]);
    header("Location: broker_mapper.php?deleted=1");
    exit;
}

if ($action === 'scan_headers' && isset($_FILES['samplecsv'])) {
    $tmpFile = $_FILES['samplecsv']['tmp_name'];
    if ($tmpFile && is_readable($tmpFile)) {
        $fh = fopen($tmpFile, 'r');
        $row = fgetcsv($fh);
        fclose($fh);
        $detectedHeaders = array_filter(array_map('trim', $row ?: []), fn($h) => $h !== '');
    }
    $editId = (int)($_POST['profile_id'] ?? 0);
    $msg     = $detectedHeaders ? count($detectedHeaders) . ' column headers detected from your CSV.' : 'Could not read headers from the uploaded file.';
    $msgType = $detectedHeaders ? 'success' : 'error';
}

// Flash from redirect
if (!$msg) {
    if (isset($_GET['created'])) { $msg = 'Profile created. Now configure your column mappings below.'; $msgType = 'success'; }
    if (isset($_GET['deleted'])) { $msg = 'Profile deleted.'; $msgType = 'error'; }
}

// ── Load data ─────────────────────────────────────────────────────────────────

$profilesStmt = $db->prepare("
    SELECT bp.id, bp.name, bp.created_at,
           COUNT(CASE WHEN bcm.csv_column_name != '' THEN 1 END) as mapped_count
    FROM broker_profiles bp
    LEFT JOIN broker_column_maps bcm ON bcm.profile_id = bp.id
    WHERE bp.user_id = ?
    GROUP BY bp.id
    ORDER BY bp.name
");
$profilesStmt->execute([$userId]);
$profiles = $profilesStmt->fetchAll();

$editProfile  = null;
$existingMaps = [];
if ($editId > 0) {
    $epStmt = $db->prepare("SELECT id, name, notes FROM broker_profiles WHERE id = ? AND user_id = ?");
    $epStmt->execute([$editId, $userId]);
    $editProfile = $epStmt->fetch();
    if ($editProfile) {
        $mapStmt = $db->prepare("SELECT internal_field, csv_column_name FROM broker_column_maps WHERE profile_id = ?");
        $mapStmt->execute([$editId]);
        foreach ($mapStmt->fetchAll() as $r) $existingMaps[$r['internal_field']] = $r['csv_column_name'];
    }
}

$pageTitle = 'Broker Profiles';
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

    <!-- ── LEFT: Profile List + Create ──────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-sliders"></i> Your Broker Profiles</div>
            </div>
            <div class="panel-body" style="padding:0">

                <?php if (empty($profiles)): ?>
                <div style="padding:32px 20px;text-align:center;color:var(--text-muted);font-size:13px">
                    No profiles yet. Create one below to get started.
                </div>
                <?php else: ?>
                <?php foreach ($profiles as $p): $isActive = (int)$p['id'] === $editId; ?>
                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);<?= $isActive ? 'background:rgba(59,130,246,.06)' : '' ?>">
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                            <?= (int)$p['mapped_count'] ?>/12 fields mapped &middot; <?= date('d M Y', strtotime($p['created_at'])) ?>
                        </div>
                    </div>
                    <a href="?edit=<?= $p['id'] ?>" class="btn-secondary-custom" style="padding:4px 10px;font-size:11px;flex-shrink:0;text-decoration:none">
                        <i class="fas fa-pen"></i> Edit
                    </a>
                    <form method="POST" style="flex-shrink:0;margin:0" onsubmit="return confirm('Delete profile &quot;<?= htmlspecialchars(addslashes($p['name'])) ?>&quot;? This cannot be undone.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_profile">
                        <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:4px 6px;color:var(--loss);font-size:13px" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Create new -->
                <div style="padding:16px;border-top:1px solid var(--border)">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px">New Profile</div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_profile">
                        <input type="text" name="name" class="form-control form-control-sm mb-2" placeholder="e.g. MT4 Live, Zerodha, IBKR" required maxlength="100">
                        <textarea name="notes" class="form-control form-control-sm mb-2" rows="2" placeholder="Optional notes (broker name, account type…)" style="resize:none"></textarea>
                        <button type="submit" class="btn-primary-custom" style="width:100%;padding:7px">
                            <i class="fas fa-plus"></i> Create Profile
                        </button>
                    </form>
                </div>

            </div>
        </div>

        <div class="panel mt-3">
            <div class="panel-body" style="font-size:12px;color:var(--text-muted);line-height:1.6">
                <i class="fas fa-lightbulb" style="color:var(--warning)"></i>
                <strong>How it works:</strong> Create a profile for each broker and enter the exact CSV column headers from that broker's export. On the <a href="import.php" style="color:var(--accent)">Import page</a>, pick a profile before uploading — the system maps columns by header name instead of fixed positions.
            </div>
        </div>
    </div>

    <!-- ── RIGHT: Edit Form ──────────────────────────────────────────────── -->
    <div class="col-lg-8">
        <?php if ($editProfile): ?>

        <?php if (!empty($detectedHeaders)): ?>
        <div class="alert-custom alert-success mb-3">
            <div style="font-size:12px;font-weight:600;margin-bottom:8px"><i class="fas fa-circle-check"></i> Detected <?= count($detectedHeaders) ?> headers — match them to the input fields below:</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php foreach ($detectedHeaders as $h): ?>
                <span style="background:rgba(59,130,246,.12);color:var(--accent);font-size:11px;font-family:var(--font-mono);padding:2px 8px;border-radius:20px;border:1px solid rgba(59,130,246,.25)"><?= htmlspecialchars($h) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-map"></i> Column Mapping — <?= htmlspecialchars($editProfile['name']) ?></div>
                <a href="broker_mapper.php" class="panel-link">← All Profiles</a>
            </div>
            <div class="panel-body">

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="profile_id" value="<?= $editProfile['id'] ?>">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:12px;font-weight:600;color:var(--text-secondary)">Profile Name *</label>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($editProfile['name']) ?>" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:12px;font-weight:600;color:var(--text-secondary)">Notes</label>
                            <input type="text" name="notes" class="form-control form-control-sm" value="<?= htmlspecialchars($editProfile['notes'] ?? '') ?>" placeholder="Optional — broker, account type…">
                        </div>
                    </div>

                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
                        Enter the <strong>exact CSV column header</strong> from your broker's file for each field (matching is case-insensitive).
                    </p>

                    <?php $detectedLower = array_map('strtolower', $detectedHeaders); ?>
                    <div style="overflow-x:auto">
                    <table class="data-table" style="font-size:12px">
                        <thead>
                            <tr>
                                <th style="width:160px">Field</th>
                                <th style="width:56px;text-align:center">Req?</th>
                                <th>Description</th>
                                <th style="width:210px">Your CSV Column Header</th>
                                <?php if (!empty($detectedHeaders)): ?><th style="width:50px;text-align:center">Found</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($FIELD_DEFS as $field => $def):
                            $val     = $existingMaps[$field] ?? '';
                            $isFound = $val !== '' && !empty($detectedHeaders) && in_array(strtolower($val), $detectedLower);
                        ?>
                        <tr>
                            <td>
                                <code style="font-size:10px;background:rgba(148,163,184,.1);padding:2px 5px;border-radius:4px;color:var(--accent);display:block;margin-bottom:3px"><?= $field ?></code>
                                <span style="font-size:11px;font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($def['label']) ?></span>
                            </td>
                            <td style="text-align:center">
                                <?php if ($def['required']): ?>
                                <span style="background:rgba(220,38,38,.1);color:var(--loss);font-size:9px;font-weight:700;padding:2px 6px;border-radius:10px;text-transform:uppercase;letter-spacing:.05em">Req</span>
                                <?php else: ?>
                                <span style="background:rgba(148,163,184,.1);color:var(--text-muted);font-size:9px;font-weight:600;padding:2px 6px;border-radius:10px;text-transform:uppercase;letter-spacing:.05em">Opt</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text-muted);font-size:11px;line-height:1.4"><?= htmlspecialchars($def['desc']) ?></td>
                            <td>
                                <input type="text" name="map[<?= $field ?>]" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($val) ?>"
                                       placeholder="<?= htmlspecialchars($def['hint']) ?>">
                            </td>
                            <?php if (!empty($detectedHeaders)): ?>
                            <td style="text-align:center">
                                <?php if ($val !== '' && $isFound): ?>
                                <i class="fas fa-check" style="color:var(--profit)"></i>
                                <?php elseif ($val !== '' && !$isFound): ?>
                                <i class="fas fa-xmark" style="color:var(--loss)"></i>
                                <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <button type="submit" class="btn-primary-custom mt-3" style="width:100%">
                        <i class="fas fa-floppy-disk"></i> Save Profile & Mapping
                    </button>
                </form>

                <!-- Scan CSV headers -->
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                    <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px">
                        <i class="fas fa-magnifying-glass"></i> Detect Headers from a Sample CSV
                    </div>
                    <p style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
                        Upload any row from your broker's CSV to see its column headers listed above as a reference while filling in the mapping.
                    </p>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="scan_headers">
                        <input type="hidden" name="profile_id" value="<?= $editProfile['id'] ?>">
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="file" name="samplecsv" class="form-control form-control-sm" accept=".csv" required style="flex:1">
                            <button type="submit" class="btn-secondary-custom" style="padding:5px 14px;font-size:12px;white-space:nowrap;flex-shrink:0">
                                <i class="fas fa-search"></i> Detect
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

        <?php else: ?>
        <div class="panel">
            <div class="panel-body" style="text-align:center;padding:60px 20px">
                <i class="fas fa-sliders" style="font-size:48px;color:var(--border);margin-bottom:16px;display:block"></i>
                <div style="font-size:16px;font-weight:600;margin-bottom:8px">Select a Profile to Edit</div>
                <div style="color:var(--text-muted);font-size:13px">Choose a profile from the left or create a new one to configure its column mapping.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php include '../includes/footer.php'; ?>

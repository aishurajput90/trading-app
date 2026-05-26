<?php
// ============================================================
// Risk Settings — per-user configurable limits
// ============================================================
require_once '../config/db.php';
requireLogin();
$db     = getDB();
$userId = getCurrentUserId();
if (!empty($_POST)) validateCsrfOrDie();

// Load current user risk settings
$user = getLoggedInUser();

$msg     = '';
$msgType = '';

// ── Save Settings ─────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'save_risk') {
    $dailyPct    = floatval($_POST['daily_loss_pct']        ?? 0);
    $weeklyPct   = floatval($_POST['weekly_loss_pct']       ?? 0);
    $warningPct  = floatval($_POST['warning_threshold_pct'] ?? 90);
    $lockMode    = intval($_POST['profit_lock_mode']        ?? 2);
    $maxTrades   = intval($_POST['max_trades_per_day']      ?? 0);
    $maxRiskPct  = floatval($_POST['max_risk_per_trade_pct'] ?? 0);

    $errors = [];
    if ($dailyPct  < 1 || $dailyPct  > 100) $errors[] = 'Daily loss limit must be between 1% and 100%.';
    if ($weeklyPct < 1 || $weeklyPct > 100) $errors[] = 'Weekly loss limit must be between 1% and 100%.';
    if ($weeklyPct < $dailyPct)             $errors[] = 'Weekly limit must be ≥ daily limit.';
    if ($warningPct < 1 || $warningPct > 100) $errors[] = 'Warning threshold must be between 1% and 100%.';
    if (!in_array($lockMode, [1,2,3]))      $errors[] = 'Invalid profit lock mode.';

    if (empty($errors)) {
        $db->prepare("UPDATE users SET
            daily_loss_pct          = ?,
            weekly_loss_pct         = ?,
            warning_threshold_pct   = ?,
            profit_lock_mode        = ?,
            max_trades_per_day      = ?,
            max_risk_per_trade_pct  = ?
            WHERE id = ?")
            ->execute([
                $dailyPct, $weeklyPct, $warningPct,
                $lockMode,
                $maxTrades  > 0 ? $maxTrades  : null,
                $maxRiskPct > 0 ? $maxRiskPct : null,
                $userId
            ]);
        $msg     = 'Risk settings saved successfully.';
        $msgType = 'success';
        // Reload user data
        $user = null;
    } else {
        $msg     = implode(' ', $errors);
        $msgType = 'danger';
    }
}

// Reload fresh user data
$uRow = $db->prepare("SELECT daily_loss_pct, weekly_loss_pct, warning_threshold_pct, profit_lock_mode, max_trades_per_day, max_risk_per_trade_pct FROM users WHERE id = ?");
$uRow->execute([$userId]);
$cfg = $uRow->fetch();

// Effective values (user setting OR global default)
$eff = [
    'daily'    => $cfg['daily_loss_pct']         ?? DAILY_LOSS_LIMIT_PCT,
    'weekly'   => $cfg['weekly_loss_pct']         ?? WEEKLY_LOSS_LIMIT_PCT,
    'warning'  => $cfg['warning_threshold_pct']   ?? WARNING_THRESHOLD_PCT,
    'lock'     => $cfg['profit_lock_mode']         ?? PROFIT_LOCK_MODE,
    'trades'   => $cfg['max_trades_per_day']       ?? MAX_TRADES_PER_DAY,
    'riskpct'  => $cfg['max_risk_per_trade_pct']   ?? MAX_RISK_PER_TRADE_PCT,
];

$pageTitle = 'Risk Settings';
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
.settings-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; overflow:hidden; margin-bottom:1.5rem; }
.settings-card-header { padding:1rem 1.5rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; font-weight:700; font-size:.92rem; }
.settings-card-body { padding:1.5rem; }
.risk-field label { font-size:.8rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:.4rem; display:block; }
.risk-field .field-hint { font-size:.75rem; color:var(--text-muted); margin-top:.3rem; }
.range-wrap { display:flex; align-items:center; gap:12px; }
.range-wrap input[type=range] { flex:1; accent-color:var(--accent); }
.range-val { font-family:var(--font-mono); font-size:1rem; font-weight:800; min-width:52px; text-align:right; color:var(--accent); }
.mode-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; }
.mode-card { border:2px solid var(--border); border-radius:12px; padding:1rem; cursor:pointer; transition:all .2s; }
.mode-card:hover { border-color:var(--accent); }
.mode-card.selected { border-color:var(--accent); background:rgba(37,99,235,.07); }
.mode-card input[type=radio] { display:none; }
.mode-card .mode-icon { font-size:1.4rem; margin-bottom:.5rem; }
.mode-card .mode-title { font-size:.88rem; font-weight:700; color:var(--text-primary); margin-bottom:.25rem; }
.mode-card .mode-desc  { font-size:.75rem; color:var(--text-muted); line-height:1.5; }
.preset-row { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.preset-btn { padding:.35rem .9rem; border-radius:8px; font-size:.78rem; font-weight:700; border:1px solid var(--border); background:transparent; color:var(--text-muted); cursor:pointer; transition:all .2s; }
.preset-btn:hover { border-color:var(--accent); color:var(--accent); background:rgba(37,99,235,.06); }
.current-badge { display:inline-flex;align-items:center;gap:5px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);border-radius:6px;padding:.2rem .6rem;font-size:.72rem;font-weight:700;color:#22c55e; }
</style>

<div class="page-header mb-4">
    <div>
        <h2 class="page-heading"><i class="fas fa-shield-halved me-2" style="color:var(--accent)"></i>Risk Settings</h2>
        <p class="page-subheading">Set your personal trading limits and protection rules</p>
    </div>
    <div class="current-badge">
        <i class="fas fa-circle-check"></i>
        Daily <?= $eff['daily'] ?>% · Weekly <?= $eff['weekly'] ?>%
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-4" role="alert">
    <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-1"></i>
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="riskForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_risk">

    <div class="row g-4">
        <div class="col-lg-7">

            <!-- Quick Presets -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <i class="fas fa-bolt" style="color:#fbbf24"></i> Quick Presets
                </div>
                <div class="settings-card-body">
                    <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.75rem">Click to auto-fill recommended settings for your trading style:</div>
                    <div class="preset-row">
                        <button type="button" class="preset-btn" onclick="applyPreset(2,5,80)">
                            🎯 Conservative<br><small>2% / 5%</small>
                        </button>
                        <button type="button" class="preset-btn" onclick="applyPreset(5,10,85)">
                            ⚖️ Balanced<br><small>5% / 10%</small>
                        </button>
                        <button type="button" class="preset-btn" onclick="applyPreset(10,20,90)">
                            🔥 Standard<br><small>10% / 20%</small>
                        </button>
                        <button type="button" class="preset-btn" onclick="applyPreset(20,40,90)">
                            🚀 Aggressive<br><small>20% / 40%</small>
                        </button>
                        <button type="button" class="preset-btn" onclick="applyPreset(4,8,80)">
                            🏆 Prop Firm<br><small>4% / 8%</small>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Loss Limits -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <i class="fas fa-ban" style="color:#f87171"></i> Loss Limits
                </div>
                <div class="settings-card-body">

                    <div class="risk-field mb-4">
                        <label>📅 Daily Loss Limit</label>
                        <div class="range-wrap">
                            <input type="range" id="dailySlider" name="daily_loss_pct"
                                   min="1" max="50" step="0.5"
                                   value="<?= $eff['daily'] ?>"
                                   oninput="document.getElementById('dailyVal').textContent=this.value+'%'">
                            <span class="range-val" id="dailyVal"><?= $eff['daily'] ?>%</span>
                        </div>
                        <div class="field-hint">If your account drops by this % in a single day, trading is halted automatically.</div>
                    </div>

                    <div class="risk-field">
                        <label>📆 Weekly Loss Limit</label>
                        <div class="range-wrap">
                            <input type="range" id="weeklySlider" name="weekly_loss_pct"
                                   min="1" max="80" step="0.5"
                                   value="<?= $eff['weekly'] ?>"
                                   oninput="document.getElementById('weeklyVal').textContent=this.value+'%'">
                            <span class="range-val" id="weeklyVal"><?= $eff['weekly'] ?>%</span>
                        </div>
                        <div class="field-hint">If your account drops by this % in a week, trading is halted until next Monday.</div>
                    </div>

                </div>
            </div>

            <!-- Warning Threshold -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <i class="fas fa-triangle-exclamation" style="color:#fbbf24"></i> Warning Alert
                </div>
                <div class="settings-card-body">
                    <div class="risk-field">
                        <label>⚠️ Alert When Limit is % Consumed</label>
                        <div class="range-wrap">
                            <input type="range" id="warningSlider" name="warning_threshold_pct"
                                   min="50" max="99" step="1"
                                   value="<?= $eff['warning'] ?>"
                                   oninput="document.getElementById('warningVal').textContent=this.value+'%'">
                            <span class="range-val" id="warningVal"><?= $eff['warning'] ?>%</span>
                        </div>
                        <div class="field-hint">You'll see a warning banner when this % of your limit is used. E.g. 90% = warn when 90% of daily limit consumed.</div>
                    </div>
                </div>
            </div>

        </div>

        <div class="col-lg-5">

            <!-- Profit Lock Mode -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <i class="fas fa-lock" style="color:#22c55e"></i> Profit Lock Mode
                </div>
                <div class="settings-card-body">
                    <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:1rem">Controls how aggressively your profits are protected with a trailing floor:</div>
                    <div class="mode-cards">
                        <?php
                        $modes = [
                            1 => ['🚀', 'Aggressive', 'Tight trailing floor off peak equity. Max protection.'],
                            2 => ['⚖️', 'Balanced',   'Returns 50% of profit as cushion. Default.'],
                            3 => ['🛡️', 'Conservative','Fixed floor at day-open balance. Simple.'],
                        ];
                        foreach ($modes as $mval => [$ico, $title, $desc]):
                        ?>
                        <label class="mode-card <?= (int)$eff['lock'] === $mval ? 'selected' : '' ?>" onclick="selectMode(this)">
                            <input type="radio" name="profit_lock_mode" value="<?= $mval ?>" <?= (int)$eff['lock'] === $mval ? 'checked' : '' ?>>
                            <div class="mode-icon"><?= $ico ?></div>
                            <div class="mode-title"><?= $title ?></div>
                            <div class="mode-desc"><?= $desc ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Trade Discipline Limits -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <i class="fas fa-ruler" style="color:#a78bfa"></i> Trade Discipline
                </div>
                <div class="settings-card-body">
                    <div class="risk-field mb-3">
                        <label>Max Trades Per Day</label>
                        <input type="number" name="max_trades_per_day" class="form-control"
                               min="1" max="100" step="1"
                               value="<?= htmlspecialchars($eff['trades']) ?>"
                               placeholder="e.g. 5">
                        <div class="field-hint">Hard stop — no more trades after this count in a day.</div>
                    </div>
                    <div class="risk-field">
                        <label>Max Risk Per Trade (%)</label>
                        <div class="input-group">
                            <input type="number" name="max_risk_per_trade_pct" class="form-control"
                                   min="0.1" max="20" step="0.1"
                                   value="<?= htmlspecialchars($eff['riskpct']) ?>"
                                   placeholder="e.g. 2.0">
                            <span class="input-group-text" style="background:var(--bg-hover);border-color:var(--border);color:var(--text-muted)">%</span>
                        </div>
                        <div class="field-hint">% of account risked per single trade. Used in Pre-Trade Checklist.</div>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <button type="submit" class="btn btn-primary w-100" style="padding:.75rem;border-radius:12px;font-weight:700;font-size:.95rem;">
                <i class="fas fa-save me-2"></i>Save Risk Settings
            </button>
            <div style="font-size:.75rem;color:var(--text-muted);text-align:center;margin-top:.6rem">
                Changes take effect immediately on your next trade.
            </div>

        </div>
    </div>
</form>

<script>
function applyPreset(daily, weekly, warning) {
    document.getElementById('dailySlider').value  = daily;
    document.getElementById('weeklySlider').value = weekly;
    document.getElementById('warningSlider').value = warning;
    document.getElementById('dailyVal').textContent  = daily + '%';
    document.getElementById('weeklyVal').textContent = weekly + '%';
    document.getElementById('warningVal').textContent = warning + '%';
}
function selectMode(el) {
    document.querySelectorAll('.mode-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input[type=radio]').checked = true;
}
</script>

<?php include '../includes/footer.php'; ?>

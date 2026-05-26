<!-- ============================================================
     TRADE MODAL — Add / Edit Trade  (v5: open + close time)
     ============================================================ -->
<div class="modal fade" id="tradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-chart-line" style="color:var(--accent)"></i>
                    <span id="modalTitle">Add New Trade</span>
                </div>
                <button type="button" class="btn-icon" data-bs-dismiss="modal">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" id="tradeForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add" id="formAction">
                <input type="hidden" name="id" id="trade_id">

                <div class="modal-body">
                    <div class="row g-3">

                        <!-- OPEN TIME row -->
                        <div class="col-12">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
                                        color:var(--text-muted);margin-bottom:8px;padding-bottom:6px;
                                        border-bottom:1px solid var(--border)">
                                <i class="fas fa-door-open" style="color:var(--profit)"></i> Open Time
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Open Date</label>
                            <input type="date" class="form-control" name="open_date" id="open_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Open Time</label>
                            <input type="time" class="form-control" name="open_time_val" id="open_time_val" required>
                        </div>

                        <!-- CLOSE TIME row -->
                        <div class="col-12">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
                                        color:var(--text-muted);margin-bottom:8px;padding-bottom:6px;
                                        border-bottom:1px solid var(--border)">
                                <i class="fas fa-door-closed" style="color:var(--loss)"></i> Close Time
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Close Date</label>
                            <input type="date" class="form-control" name="trade_date" id="trade_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Close Time</label>
                            <input type="time" class="form-control" name="trade_time" id="trade_time" required>
                        </div>

                        <!-- Duration display -->
                        <div class="col-12">
                            <div id="durationBox" style="display:none;font-size:12px;color:var(--accent-cyan);
                                 background:var(--bg-elevated);padding:6px 12px;border-radius:var(--radius-sm);
                                 border-left:3px solid var(--accent-cyan)">
                                <i class="fas fa-hourglass-half"></i> Duration: <strong id="durationVal"></strong>
                            </div>
                        </div>

                        <!-- Symbol + Trade Type -->
                        <div class="col-md-6">
                            <label class="form-label">Symbol</label>
                            <input type="text" class="form-control" name="symbol" id="symbol"
                                   placeholder="e.g. XAUUSD, BTCUSD" required style="text-transform:uppercase">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trade Type</label>
                            <select class="form-control" name="trade_type" id="trade_type">
                                <option value="buy">Buy (Long)</option>
                                <option value="sell">Sell (Short)</option>
                            </select>
                        </div>

                        <!-- Entry + Exit Price -->
                        <div class="col-md-6">
                            <label class="form-label">Entry Price</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)">$</span>
                                <input type="number" class="form-control" name="entry_price" id="entry_price"
                                       placeholder="0.0000" step="0.0001" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Exit Price</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)">$</span>
                                <input type="number" class="form-control" name="exit_price" id="exit_price"
                                       placeholder="0.0000" step="0.0001" min="0" required>
                            </div>
                        </div>

                        <!-- Quantity + Close Reason -->
                        <div class="col-md-6">
                            <label class="form-label">Quantity / Lots</label>
                            <input type="number" class="form-control" name="quantity" id="quantity"
                                   placeholder="e.g. 0.05" step="0.0001" min="0.0001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Close Reason <span style="color:var(--text-muted);font-weight:400;text-transform:none">(optional)</span></label>
                            <select class="form-control" name="close_reason" id="close_reason">
                                <option value="">— Select —</option>
                                <option value="user">Manual (User)</option>
                                <option value="tp">Take Profit (TP)</option>
                                <option value="sl">Stop Loss (SL)</option>
                                <option value="so">Stop Out (SO)</option>
                            </select>
                        </div>

                        <!-- P&L + Brokerage -->
                        <div class="col-md-8">
                            <label class="form-label">Profit / Loss (USD)</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)">
                                    <i class="fas fa-plus-minus fa-sm"></i>
                                </span>
                                <input type="number" class="form-control" name="profit_loss" id="profit_loss"
                                       placeholder="+200 profit  or  -50 loss" step="0.01" required>
                            </div>
                            <div class="pl-hint">Enter <strong>+20</strong> for profit or <strong>-20</strong> for loss</div>
                            <div id="plPreview" class="pl-preview"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Brokerage / Commission</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)">$</span>
                                <input type="number" class="form-control" name="brokerage" id="brokerage"
                                       placeholder="0.00" step="0.0001" min="0" value="0">
                            </div>
                            <div class="pl-hint">Always <strong>positive</strong></div>
                        </div>

                        <!-- Swap + Net PL display -->
                        <div class="col-md-4">
                            <label class="form-label">Swap <span style="color:var(--text-muted);font-weight:400;text-transform:none">(optional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--bg-elevated);border-color:var(--border);color:var(--text-muted)">$</span>
                                <input type="number" class="form-control" name="swap" id="swap"
                                       placeholder="0.00" step="0.0001" value="0">
                            </div>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <div id="netPlBox" class="net-pl-box" style="display:none;width:100%">
                                <span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Net P&amp;L (after charges)</span>
                                <span id="netPlValue" class="net-pl-value"></span>
                            </div>
                        </div>

                        <!-- SL Amount + TP Amount -->
                        <div class="col-12">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;
                                        color:var(--text-muted);margin-bottom:8px;padding-bottom:6px;
                                        border-bottom:1px solid var(--border)">
                                <i class="fas fa-shield-halved" style="color:#f59e0b"></i> Risk &amp; Reward (Discipline Check)
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                SL Amount ($ at Risk)
                                <span style="color:#ef4444;font-size:10px;font-weight:700;margin-left:4px">Required</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:rgba(239,68,68,.1);border-color:var(--border);color:#ef4444">
                                    <i class="fas fa-shield-halved fa-xs"></i>
                                </span>
                                <input type="number" class="form-control" name="sl_amount" id="sl_amount"
                                       placeholder="e.g. 10.00" step="0.01" min="0">
                            </div>
                            <div style="font-size:10px;color:var(--text-muted);margin-top:3px">How much $ you will lose if SL is hit</div>
                            <div id="riskPctWarning" style="display:none;font-size:11px;font-weight:700;margin-top:4px;padding:4px 8px;border-radius:5px"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                TP Amount ($ Reward)
                                <span style="color:var(--text-muted);font-size:10px;font-weight:400;margin-left:4px">optional</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:rgba(34,197,94,.1);border-color:var(--border);color:#22c55e">
                                    <i class="fas fa-bullseye fa-xs"></i>
                                </span>
                                <input type="number" class="form-control" name="tp_amount" id="tp_amount"
                                       placeholder="e.g. 20.00" step="0.01" min="0">
                            </div>
                            <div style="font-size:10px;color:var(--text-muted);margin-top:3px">How much $ you gain if TP is hit</div>
                        </div>
                        <!-- R:R Preview -->
                        <div class="col-12">
                            <div id="rrBox" style="display:none;padding:8px 14px;border-radius:8px;background:var(--bg-elevated);
                                 border:1px solid var(--border);display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                                <span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">R:R Ratio</span>
                                <span id="rrValue" style="font-size:1.2rem;font-weight:800"></span>
                                <span id="rrLabel" style="font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px"></span>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label">Notes <span style="color:var(--text-muted);font-weight:400;text-transform:none">(optional)</span></label>
                            <textarea class="form-control" name="notes" id="notes" rows="2"
                                      placeholder="Strategy, market conditions, lessons learned..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="fas fa-floppy-disk"></i> Save Trade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ---- form action ----
document.getElementById('tradeForm')?.addEventListener('submit', function() {
    document.getElementById('formAction').value = document.getElementById('trade_id').value ? 'edit' : 'add';
});

// ---- symbol uppercase ----
document.getElementById('symbol')?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });

// ---- auto-fill open+close times on modal open ----
const tradeModal = document.getElementById('tradeModal');
if (tradeModal) {
    tradeModal.addEventListener('show.bs.modal', function() {
        const now   = new Date();
        const today = now.toISOString().split('T')[0];
        const hhmm  = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
        if (!document.getElementById('open_date').value)     document.getElementById('open_date').value     = today;
        if (!document.getElementById('open_time_val').value) document.getElementById('open_time_val').value = hhmm;
        if (!document.getElementById('trade_date').value)    document.getElementById('trade_date').value    = today;
        if (!document.getElementById('trade_time').value)    document.getElementById('trade_time').value    = hhmm;
    });
    tradeModal.addEventListener('hidden.bs.modal', function() {
        document.getElementById('tradeForm')?.reset();
        document.getElementById('trade_id').value = '';
        document.getElementById('modalTitle').textContent = 'Add New Trade';
        document.getElementById('plPreview').textContent = '';
        document.getElementById('netPlBox').style.display = 'none';
        document.getElementById('durationBox').style.display = 'none';
        document.getElementById('brokerage').value = '0';
        document.getElementById('swap').value = '0';
        document.getElementById('sl_amount').value = '';
        document.getElementById('tp_amount').value = '';
        document.getElementById('rrBox').style.display = 'none';
        document.getElementById('riskPctWarning').style.display = 'none';
    });
}

// ---- Duration calculator ----
function calcDuration() {
    const od = document.getElementById('open_date').value;
    const ot = document.getElementById('open_time_val').value;
    const cd = document.getElementById('trade_date').value;
    const ct = document.getElementById('trade_time').value;
    if (!od || !ot || !cd || !ct) return;
    const open  = new Date(od + 'T' + ot);
    const close = new Date(cd + 'T' + ct);
    const diff  = Math.max(0, close - open);
    const box   = document.getElementById('durationBox');
    const val   = document.getElementById('durationVal');
    if (diff === 0) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    val.textContent = (h > 0 ? h + 'h ' : '') + (m > 0 ? m + 'm ' : '') + s + 's';
}
['open_date','open_time_val','trade_date','trade_time'].forEach(id =>
    document.getElementById(id)?.addEventListener('change', calcDuration)
);

// ---- P&L live preview ----
function updatePLPreview(val) {
    const p = document.getElementById('plPreview');
    if (!p) return;
    const n = parseFloat(val);
    if (isNaN(n)) { p.textContent = ''; p.className = 'pl-preview'; return; }
    p.textContent = (n >= 0 ? '+$' : '-$') + Math.abs(n).toFixed(2);
    p.className   = 'pl-preview ' + (n >= 0 ? 'positive' : 'negative');
}

// ---- Net P&L after charges ----
function updateNetPL() {
    const pl   = parseFloat(document.getElementById('profit_loss')?.value) || 0;
    const brok = parseFloat(document.getElementById('brokerage')?.value)   || 0;
    const swap = parseFloat(document.getElementById('swap')?.value)        || 0;
    const net  = pl - brok + swap;
    const box  = document.getElementById('netPlBox');
    const val  = document.getElementById('netPlValue');
    if (!box || !val || document.getElementById('profit_loss')?.value === '') { if(box) box.style.display='none'; return; }
    box.style.display = 'flex';
    val.textContent   = (net >= 0 ? '+$' : '-$') + Math.abs(net).toFixed(2);
    val.className     = 'net-pl-value ' + (net >= 0 ? 'net-positive' : 'net-negative');
}
document.getElementById('profit_loss')?.addEventListener('input', function() { updatePLPreview(this.value); updateNetPL(); });
document.getElementById('brokerage')?.addEventListener('input', updateNetPL);
document.getElementById('swap')?.addEventListener('input', updateNetPL);

// ---- R:R ratio + risk % check ----
const MAX_RISK_PCT = <?= defined('MAX_RISK_PER_TRADE_PCT') ? MAX_RISK_PER_TRADE_PCT : 2.0 ?>;
const ACCOUNT_BALANCE = null; // fetched server-side below

function updateRR() {
    const sl = parseFloat(document.getElementById('sl_amount')?.value) || 0;
    const tp = parseFloat(document.getElementById('tp_amount')?.value) || 0;
    const rrBox   = document.getElementById('rrBox');
    const rrVal   = document.getElementById('rrValue');
    const rrLbl   = document.getElementById('rrLabel');
    const warnEl  = document.getElementById('riskPctWarning');

    // R:R display
    if (sl > 0 && tp > 0) {
        const rr = tp / sl;
        rrVal.textContent = '1 : ' + rr.toFixed(2);
        if (rr >= 2) {
            rrVal.style.color = '#22c55e';
            rrLbl.textContent = 'Good R:R'; rrLbl.style.background='rgba(34,197,94,.12)'; rrLbl.style.color='#22c55e';
        } else if (rr >= 1) {
            rrVal.style.color = '#eab308';
            rrLbl.textContent = 'Minimum R:R'; rrLbl.style.background='rgba(234,179,8,.12)'; rrLbl.style.color='#ca8a04';
        } else {
            rrVal.style.color = '#ef4444';
            rrLbl.textContent = 'Bad R:R — Risking more than reward'; rrLbl.style.background='rgba(239,68,68,.12)'; rrLbl.style.color='#ef4444';
        }
        rrBox.style.display = 'flex';
    } else if (sl > 0) {
        rrBox.style.display = 'none';
    } else {
        rrBox.style.display = 'none';
    }

    // Risk % warning — compare against account balance from data attr
    const balance = parseFloat(document.getElementById('tradeModal')?.dataset.balance) || 0;
    if (sl > 0 && balance > 0) {
        const riskPct = (sl / balance * 100);
        if (riskPct > MAX_RISK_PCT) {
            warnEl.textContent = '⚠ Risk ' + riskPct.toFixed(1) + '% of account — exceeds your ' + MAX_RISK_PCT + '% limit!';
            warnEl.style.display = 'block';
            warnEl.style.background = 'rgba(239,68,68,.1)';
            warnEl.style.color = '#ef4444';
            warnEl.style.border = '1px solid rgba(239,68,68,.3)';
        } else if (sl > 0) {
            warnEl.textContent = '✓ Risk ' + riskPct.toFixed(1) + '% of account — within limit';
            warnEl.style.display = 'block';
            warnEl.style.background = 'rgba(34,197,94,.08)';
            warnEl.style.color = '#22c55e';
            warnEl.style.border = '1px solid rgba(34,197,94,.25)';
        } else {
            warnEl.style.display = 'none';
        }
    } else {
        warnEl.style.display = 'none';
    }
}
document.getElementById('sl_amount')?.addEventListener('input', updateRR);
document.getElementById('tp_amount')?.addEventListener('input', updateRR);

// ---- editTrade() ----
function editTrade(t) {
    const closeTs = new Date(t.trade_datetime.replace(' ','T'));
    document.getElementById('trade_id').value      = t.id;
    document.getElementById('trade_date').value    = closeTs.toISOString().split('T')[0];
    document.getElementById('trade_time').value    = closeTs.toTimeString().slice(0,5);
    if (t.open_time) {
        const openTs = new Date(t.open_time.replace(' ','T'));
        document.getElementById('open_date').value     = openTs.toISOString().split('T')[0];
        document.getElementById('open_time_val').value = openTs.toTimeString().slice(0,5);
    }
    document.getElementById('symbol').value        = t.symbol;
    document.getElementById('trade_type').value    = t.trade_type   || 'buy';
    document.getElementById('entry_price').value   = t.entry_price;
    document.getElementById('exit_price').value    = t.exit_price;
    document.getElementById('quantity').value      = t.quantity;
    document.getElementById('close_reason').value  = t.close_reason || '';
    document.getElementById('profit_loss').value   = t.profit_loss;
    document.getElementById('brokerage').value     = t.brokerage    || 0;
    document.getElementById('swap').value          = t.swap         || 0;
    document.getElementById('notes').value         = t.notes        || '';
    document.getElementById('sl_amount').value     = t.sl_amount    || '';
    document.getElementById('tp_amount').value     = t.tp_amount    || '';
    document.getElementById('modalTitle').textContent = 'Edit Trade — ' + t.symbol;
    updatePLPreview(t.profit_loss);
    updateNetPL();
    calcDuration();
    updateRR();
    new bootstrap.Modal(document.getElementById('tradeModal')).show();
}

// ---- confirmDelete() ----
function confirmDelete(id, symbol) {
    if (confirm('Delete trade for ' + symbol + '? This cannot be undone.'))
        window.location.href = '?delete=' + id;
}
</script>

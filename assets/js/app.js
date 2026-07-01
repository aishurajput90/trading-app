/* ============================================================
   DisciplineOS - app.js
   Dark/Light Mode | Sidebar | Charts | Modal Helpers
   ============================================================ */

/* ---------- THEME TOGGLE ---------- */
const THEME_KEY = 'dos_theme';

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    // Refresh Chart.js colors if charts exist
    if (window._charts) {
        window._charts.forEach(c => { try { c.update(); } catch(e){} });
    }
}

document.addEventListener('DOMContentLoaded', function () {

    /* ---- Theme ---- */
    const savedTheme = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(savedTheme);

    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }

    /* ---- Sidebar ---- */
    const sidebar        = document.getElementById('sidebar');
    const sidebarToggle  = document.getElementById('sidebarToggle');
    const desktopToggle  = document.getElementById('desktopToggle');
    const mainWrapper    = document.getElementById('mainWrapper');
    const overlay        = document.getElementById('sidebarOverlay');

    function openMobile() {
        sidebar?.classList.add('mobile-open');
        overlay?.classList.add('show');
    }
    function closeMobile() {
        sidebar?.classList.remove('mobile-open');
        overlay?.classList.remove('show');
    }

    sidebarToggle?.addEventListener('click', () => {
        sidebar?.classList.contains('mobile-open') ? closeMobile() : openMobile();
    });
    overlay?.addEventListener('click', closeMobile);

    desktopToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('collapsed');
        mainWrapper?.classList.toggle('expanded');
    });

    /* ---- P/L Input Preview (modal) ---- */
    const plInput   = document.getElementById('profit_loss');
    const plPreview = document.getElementById('plPreview');

    function updatePLPreview(val) {
        if (!plPreview) return;
        const num = parseFloat(val);
        if (isNaN(num)) { plPreview.textContent = ''; plPreview.className = 'pl-preview'; return; }
        const _cs = window.APP_CS || '$';
        if (num >= 0) {
            plPreview.textContent = '+' + _cs + Math.abs(num).toFixed(2);
            plPreview.className = 'pl-preview positive';
        } else {
            plPreview.textContent = '-' + _cs + Math.abs(num).toFixed(2);
            plPreview.className = 'pl-preview negative';
        }
    }

    plInput?.addEventListener('input', () => updatePLPreview(plInput.value));

    /* ---- Auto-fill Date/Time in modal ---- */
    function fillDateTime() {
        const dateInput = document.getElementById('trade_date');
        const timeInput = document.getElementById('trade_time');
        if (dateInput && !dateInput.value) {
            const now = new Date();
            dateInput.value = now.toISOString().split('T')[0];
        }
        if (timeInput && !timeInput.value) {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2,'0');
            const mm = String(now.getMinutes()).padStart(2,'0');
            timeInput.value = hh + ':' + mm;
        }
    }

    // Fill when modal opens
    const tradeModal = document.getElementById('tradeModal');
    if (tradeModal) {
        tradeModal.addEventListener('show.bs.modal', fillDateTime);
        tradeModal.addEventListener('hidden.bs.modal', function() {
            const form = document.getElementById('tradeForm');
            form?.reset();
            if (plPreview) { plPreview.textContent = ''; plPreview.className = 'pl-preview'; }
            document.getElementById('trade_id').value = '';
            document.getElementById('modalTitle').textContent = 'Add New Trade';
        });
    }

    /* ---- Edit Trade Populate ---- */
    window.editTrade = function(data) {
        const modal = document.getElementById('tradeModal');
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        const dt = new Date(data.trade_datetime.replace(' ', 'T'));

        document.getElementById('trade_id').value         = data.id;
        document.getElementById('trade_date').value       = dt.toISOString().split('T')[0];
        document.getElementById('trade_time').value       = String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
        document.getElementById('symbol').value           = data.symbol;
        document.getElementById('entry_price').value      = data.entry_price;
        document.getElementById('exit_price').value       = data.exit_price;
        document.getElementById('quantity').value         = data.quantity;
        document.getElementById('profit_loss').value      = data.profit_loss;
        document.getElementById('notes').value            = data.notes || '';
        document.getElementById('modalTitle').textContent = 'Edit Trade';

        updatePLPreview(data.profit_loss);
        bsModal.show();
    };

    /* ---- Delete confirm ---- */
    window.confirmDelete = function(id, symbol) {
        if (confirm('Delete trade for ' + symbol + '? This cannot be undone.')) {
            window.location.href = '?delete=' + id;
        }
    };

    /* ---- Auto-hide alerts ---- */
    setTimeout(() => {
        document.querySelectorAll('.alert-custom').forEach(el => {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        });
    }, 3500);
});

/* ============================================================
   CHART HELPERS
   ============================================================ */
window._charts = [];

function getChartThemeColors() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
        text:   dark ? '#94a3b8' : '#475569',
        grid:   dark ? '#1e2d4a' : '#e2e8f0',
        profit: dark ? '#22c55e' : '#16a34a',
        loss:   dark ? '#f87171' : '#dc2626',
        blue:   dark ? '#3b82f6' : '#2563eb',
    };
}

function createLineChart(canvasId, labels, data, label) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const colors = getChartThemeColors();
    const c = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label,
                data,
                backgroundColor: data.map(v => v >= 0 ? colors.profit + '80' : colors.loss + '80'),
                borderColor:     data.map(v => v >= 0 ? colors.profit : colors.loss),
                borderWidth: 2,
                borderRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const v = ctx.raw; const _cs = window.APP_CS || '$';
                            return (v >= 0 ? '+' : '-') + _cs + Math.abs(v).toFixed(2);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: colors.grid },
                    ticks: { color: colors.text, font: { size: 11 } }
                },
                y: {
                    grid: { color: colors.grid },
                    ticks: {
                        color: colors.text,
                        font: { size: 11 },
                        callback: v => { const _cs = window.APP_CS || '$'; return (v >= 0 ? '+' : '-') + _cs + Math.abs(v); }
                    }
                }
            }
        }
    });
    window._charts.push(c);
    return c;
}

function createDoughnutChart(canvasId, wins, losses) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const colors = getChartThemeColors();
    const c = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Wins', 'Losses'],
            datasets: [{
                data: [wins || 0, losses || 0],
                backgroundColor: [colors.profit + 'cc', colors.loss + 'cc'],
                borderColor:     [colors.profit, colors.loss],
                borderWidth: 2,
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: colors.text,
                        font: { size: 12 },
                        padding: 12,
                        usePointStyle: true,
                    }
                }
            }
        }
    });
    window._charts.push(c);
    return c;
}

function createAreaChart(canvasId, labels, data, label) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const colors = getChartThemeColors();
    const c = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label,
                data,
                borderColor: colors.blue,
                backgroundColor: colors.blue + '18',
                borderWidth: 2.5,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: colors.blue,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => (window.APP_CS || '$') + ctx.raw.toFixed(2)
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: colors.grid },
                    ticks: { color: colors.text, font: { size: 11 } }
                },
                y: {
                    grid: { color: colors.grid },
                    ticks: {
                        color: colors.text,
                        font: { size: 11 },
                        callback: v => (window.APP_CS || '$') + v.toLocaleString()
                    }
                }
            }
        }
    });
    window._charts.push(c);
    return c;
}

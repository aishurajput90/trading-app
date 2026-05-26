<?php
// ============================================================
// Admin Analytics Dashboard — Super Admin Only
// ============================================================
require_once '../config/db.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    die('<div style="font-family:monospace;background:#0a0e1a;color:#ef4444;padding:40px;text-align:center;"><h2>403 — Access Denied</h2><p>Super Admin only.</p><a href="../index.php" style="color:#60a5fa">← Back</a></div>');
}
$db     = getDB();
$userId = getCurrentUserId();

// ── Helpers ───────────────────────────────────────────────────────────────────
function timeAgo(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('d M Y', strtotime($dt));
}

// ── Overview Stats ────────────────────────────────────────────────────────────
$total = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

$active7   = $db->query("SELECT COUNT(*) FROM users WHERE last_active_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$active30  = $db->query("SELECT COUNT(*) FROM users WHERE last_active_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$idle      = $db->query("SELECT COUNT(*) FROM users WHERE last_active_at < NOW() - INTERVAL 7 DAY AND last_active_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$dropped   = $db->query("SELECT COUNT(*) FROM users WHERE last_active_at < NOW() - INTERVAL 30 DAY AND last_active_at IS NOT NULL")->fetchColumn();
$ghosts    = $db->query("SELECT COUNT(*) FROM users WHERE last_active_at IS NULL AND created_at < NOW() - INTERVAL 1 DAY")->fetchColumn();
$newToday  = $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$newWeek   = $db->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$newMonth  = $db->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$noTrades  = $db->query("SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM trades t WHERE t.user_id = u.id)")->fetchColumn();

// ── Signups per day (last 30 days) ────────────────────────────────────────────
$signupTrend = $db->query("
    SELECT DATE(created_at) as day, COUNT(*) as cnt
    FROM users
    WHERE created_at >= NOW() - INTERVAL 30 DAY
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetchAll();

// ── All users with their activity ─────────────────────────────────────────────
$users = $db->query("
    SELECT
        u.id, u.name, u.email, u.role, u.created_at,
        u.last_login_at, u.last_active_at, u.login_count,
        COUNT(t.id)                                          AS trade_count,
        COALESCE(SUM(t.profit_loss - t.brokerage + t.swap), 0) AS net_pl,
        MAX(t.trade_datetime)                                AS last_trade_at,
        CASE
            WHEN u.last_active_at >= NOW() - INTERVAL 7 DAY  THEN 'active'
            WHEN u.last_active_at >= NOW() - INTERVAL 30 DAY THEN 'idle'
            WHEN u.last_active_at IS NULL                     THEN 'ghost'
            ELSE 'dropped'
        END AS status
    FROM users u
    LEFT JOIN trades t ON t.user_id = u.id
    GROUP BY u.id
    ORDER BY u.last_active_at IS NULL ASC, u.last_active_at DESC, u.created_at DESC
")->fetchAll();

// ── Filter ────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$filtered = $users;
if ($filter !== 'all') {
    $filtered = array_filter($users, fn($u) => $u['status'] === $filter);
}

$pageTitle = 'Admin Analytics';
$rootPath  = '../';
include '../includes/header.php';
?>

<style>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
.stat-box { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 1.25rem 1rem; text-align: center; transition: border-color .2s; }
.stat-box:hover { border-color: var(--accent); }
.stat-box .sb-num { font-size: 2rem; font-weight: 800; line-height: 1; margin-bottom: 4px; font-family: var(--font-mono); }
.stat-box .sb-label { font-size: .75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; flex-shrink: 0; }
.dot-active  { background: #22c55e; box-shadow: 0 0 6px #22c55e80; }
.dot-idle    { background: #fbbf24; box-shadow: 0 0 6px #fbbf2480; }
.dot-dropped { background: #f87171; box-shadow: 0 0 6px #f8717180; }
.dot-ghost   { background: #64748b; }
.filter-tabs { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.filter-tab { padding: .4rem 1rem; border-radius: 8px; font-size: .82rem; font-weight: 600; border: 1px solid var(--border); color: var(--text-muted); text-decoration: none; transition: all .2s; }
.filter-tab:hover, .filter-tab.active { border-color: var(--accent); color: var(--accent); background: rgba(37,99,235,.08); }
.user-row { transition: background .15s; }
.user-row:hover { background: var(--bg-hover); }
.badge-role { padding: .2rem .6rem; border-radius: 6px; font-size: .7rem; font-weight: 700; }
.badge-admin { background: rgba(250,204,21,.15); color: #fbbf24; border: 1px solid rgba(250,204,21,.3); }
.badge-user  { background: rgba(148,163,184,.1); color: var(--text-muted); border: 1px solid var(--border); }
.trend-bar-wrap { display: flex; align-items: flex-end; gap: 2px; height: 60px; padding: .5rem 0; }
.trend-bar { flex: 1; border-radius: 3px 3px 0 0; background: var(--accent); opacity: .7; min-height: 3px; transition: opacity .2s; }
.trend-bar:hover { opacity: 1; }
</style>

<!-- Page Header -->
<div class="page-header d-flex align-items-center justify-content-between mb-4">
    <div>
        <h2 class="page-heading">
            <i class="fas fa-chart-line me-2" style="color:var(--accent)"></i>User Analytics
        </h2>
        <p class="page-subheading">Real-time overview of user activity and engagement</p>
    </div>
    <div style="font-size:.8rem;color:var(--text-muted)">
        <i class="fas fa-clock me-1"></i>Live data · <?= date('d M Y, H:i') ?>
    </div>
</div>

<!-- ── Overview Stats ──────────────────────────────────────────────────────── -->
<div class="stat-grid">
    <div class="stat-box">
        <div class="sb-num" style="color:var(--accent)"><?= $total ?></div>
        <div class="sb-label">Total Users</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#22c55e"><?= $active7 ?></div>
        <div class="sb-label"><span class="status-dot dot-active"></span>Active (7d)</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#3b82f6"><?= $active30 ?></div>
        <div class="sb-label">Active (30d)</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#fbbf24"><?= $idle ?></div>
        <div class="sb-label"><span class="status-dot dot-idle"></span>Idle (8–30d)</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#f87171"><?= $dropped ?></div>
        <div class="sb-label"><span class="status-dot dot-dropped"></span>Dropped (30d+)</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#64748b"><?= $ghosts ?></div>
        <div class="sb-label"><span class="status-dot dot-ghost"></span>Ghosts</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#a78bfa"><?= $noTrades ?></div>
        <div class="sb-label">No Trades Yet</div>
    </div>
    <div class="stat-box">
        <div class="sb-num" style="color:#34d399"><?= $newToday ?></div>
        <div class="sb-label">New Today</div>
    </div>
</div>

<!-- ── Signup Trend + Quick Stats ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Signup Trend -->
    <div class="col-lg-8">
        <div class="card-custom h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="fas fa-user-plus me-2" style="color:var(--accent)"></i>Signup Trend — Last 30 Days</span>
                <span style="font-size:.78rem;color:var(--text-muted)"><?= $newWeek ?> this week · <?= $newMonth ?> this month</span>
            </div>
            <div style="padding:1rem 1.5rem 1.25rem">
                <?php if (empty($signupTrend)): ?>
                <div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:1.5rem">No signups in last 30 days</div>
                <?php else:
                    $maxCnt = max(array_column($signupTrend, 'cnt')) ?: 1;
                ?>
                <div class="trend-bar-wrap">
                    <?php foreach ($signupTrend as $row): ?>
                    <div class="trend-bar"
                         style="height:<?= round($row['cnt'] / $maxCnt * 100) ?>%"
                         title="<?= date('d M', strtotime($row['day'])) ?>: <?= $row['cnt'] ?> signups"></div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--text-muted);margin-top:4px">
                    <span><?= date('d M', strtotime($signupTrend[0]['day'])) ?></span>
                    <span><?= date('d M', strtotime(end($signupTrend)['day'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Health Scores -->
    <div class="col-lg-4">
        <div class="card-custom h-100">
            <div class="card-header-custom">
                <i class="fas fa-heartbeat me-2" style="color:var(--accent)"></i>Engagement Health
            </div>
            <div style="padding:1.25rem">
                <?php
                $activationRate = $total > 0 ? round((($total - $noTrades) / $total) * 100) : 0;
                $retentionRate  = $total > 0 ? round(($active30 / $total) * 100) : 0;
                $churnRate      = $total > 0 ? round(($dropped / $total) * 100) : 0;
                function healthBar($pct, $color) {
                    echo '<div style="background:var(--border);border-radius:4px;height:7px;margin-top:5px;overflow:hidden">';
                    echo '<div style="width:' . min($pct,100) . '%;height:100%;background:' . $color . ';border-radius:4px;transition:width .4s"></div>';
                    echo '</div>';
                }
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between" style="font-size:.82rem">
                        <span style="color:var(--text-secondary);font-weight:600">Activation Rate</span>
                        <span style="font-weight:700;color:#22c55e"><?= $activationRate ?>%</span>
                    </div>
                    <?php healthBar($activationRate, '#22c55e') ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px">Users who logged at least 1 trade</div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between" style="font-size:.82rem">
                        <span style="color:var(--text-secondary);font-weight:600">30-Day Retention</span>
                        <span style="font-weight:700;color:#3b82f6"><?= $retentionRate ?>%</span>
                    </div>
                    <?php healthBar($retentionRate, '#3b82f6') ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px">Users active in last 30 days</div>
                </div>
                <div>
                    <div class="d-flex justify-content-between" style="font-size:.82rem">
                        <span style="color:var(--text-secondary);font-weight:600">Churn Rate</span>
                        <span style="font-weight:700;color:#f87171"><?= $churnRate ?>%</span>
                    </div>
                    <?php healthBar($churnRate, '#f87171') ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px">Users dropped off (30d+ inactive)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── User Table ──────────────────────────────────────────────────────────── -->
<div class="card-custom">
    <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-users me-2" style="color:var(--accent)"></i>All Users
            <span style="font-size:.78rem;color:var(--text-muted);font-weight:500;margin-left:6px"><?= count($filtered) ?> shown</span>
        </span>
    </div>
    <div style="padding:.75rem 1rem .5rem">
        <div class="filter-tabs">
            <?php
            $tabs = [
                'all'     => ['All', count($users)],
                'active'  => ['🟢 Active',  $active7],
                'idle'    => ['🟡 Idle',     $idle],
                'dropped' => ['🔴 Dropped',  $dropped],
                'ghost'   => ['👻 Ghost',    $ghosts],
            ];
            foreach ($tabs as $key => [$label, $cnt]): ?>
            <a href="?filter=<?= $key ?>" class="filter-tab <?= $filter === $key ? 'active' : '' ?>">
                <?= $label ?> <span style="opacity:.6">(<?= $cnt ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="overflow-x:auto">
        <table class="table table-hover mb-0" style="font-size:.83rem">
            <thead style="background:var(--bg-hover)">
                <tr>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">User</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Status</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Trades</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Net P/L</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Logins</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Last Active</th>
                    <th style="padding:.6rem 1rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;border:none">Joined</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filtered)): ?>
            <tr>
                <td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
                    <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>
                    No users in this category
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($filtered as $u):
                $statusColors = [
                    'active'  => ['#22c55e', 'dot-active',  'Active'],
                    'idle'    => ['#fbbf24', 'dot-idle',    'Idle'],
                    'dropped' => ['#f87171', 'dot-dropped', 'Dropped'],
                    'ghost'   => ['#64748b', 'dot-ghost',   'Ghost'],
                ];
                [$sc, $dc, $sl] = $statusColors[$u['status']] ?? ['#94a3b8', 'dot-ghost', 'Unknown'];
                // Initials
                $words = array_filter(explode(' ', $u['name']));
                $ini   = strtoupper(implode('', array_map(fn($w) => $w[0], $words)));
                $ini   = substr($ini, 0, 2);
            ?>
            <tr class="user-row">
                <td style="padding:.65rem 1rem;border-color:var(--border)">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0">
                            <?= htmlspecialchars($ini) ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($u['name']) ?></div>
                            <div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></div>
                        </div>
                        <?php if ($u['role'] === 'admin'): ?>
                        <span class="badge-role badge-admin">Admin</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border)">
                    <span style="display:inline-flex;align-items:center;padding:.3rem .7rem;border-radius:20px;background:<?= $sc ?>18;border:1px solid <?= $sc ?>40;font-size:.75rem;font-weight:700;color:<?= $sc ?>">
                        <span class="status-dot <?= $dc ?>"></span><?= $sl ?>
                    </span>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border)">
                    <?php if ($u['trade_count'] > 0): ?>
                    <span style="font-weight:700;color:var(--text-primary)"><?= number_format($u['trade_count']) ?></span>
                    <?php else: ?>
                    <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border)">
                    <?php $pl = (float)$u['net_pl']; ?>
                    <span style="font-weight:700;color:<?= $pl > 0 ? '#22c55e' : ($pl < 0 ? '#f87171' : 'var(--text-muted)') ?>;font-family:var(--font-mono)">
                        <?= $u['trade_count'] > 0 ? formatPL($pl) : '—' ?>
                    </span>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border);color:var(--text-secondary)">
                    <?= $u['login_count'] ?: '—' ?>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border);color:var(--text-secondary)">
                    <?= timeAgo($u['last_active_at']) ?>
                </td>
                <td style="padding:.65rem 1rem;border-color:var(--border);color:var(--text-muted);font-size:.78rem">
                    <?= date('d M Y', strtotime($u['created_at'])) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

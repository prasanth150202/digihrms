<?php
require_once 'config.php';
require_once 'points_helper.php';
require_login();

$page = 'points';
$u    = current_user();

$s = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
$s->execute([$u['email']]);
$emp = $s->fetch();

$is_admin_view = has_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER', 'TEAM_LEAD');

// ── Admin/TL view ─────────────────────────────────────────────────────
if ($is_admin_view) {
    $pageTitle = 'Team Points';

    $dept_filter      = null;
    $dept_filter_name = null;
    $tl_team_user_ids = []; // for TEAM_LEAD scoping

    if ($u['role'] === 'DEPT_MANAGER' && $emp) {
        $s = $conn->prepare("SELECT dept_id FROM employees WHERE id = ? LIMIT 1");
        $s->execute([$emp['id']]);
        $dept_filter = $s->fetchColumn() ?: null;
        if ($dept_filter) {
            $s = $conn->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
            $s->execute([$dept_filter]);
            $dept_filter_name = $s->fetchColumn();
        }
    } elseif ($u['role'] === 'TEAM_LEAD' && $emp) {
        // Scope to own department via employee_roles
        $s = $conn->prepare("SELECT er.dept_id, d.name FROM employee_roles er JOIN departments d ON d.id=er.dept_id WHERE er.employee_id=? AND er.is_team_lead=1 LIMIT 1");
        $s->execute([$emp['id']]);
        $row = $s->fetch();
        if ($row) {
            $dept_filter      = (int)$row['dept_id'];
            $dept_filter_name = $row['name'];
            // Collect user IDs of all dept members (for leaderboard scoping)
            $s2 = $conn->prepare("SELECT u.id FROM employees e JOIN users u ON u.email=e.email WHERE e.dept_id=? AND e.status IN ('ACTIVE','PROBATION')");
            $s2->execute([$dept_filter]);
            $tl_team_user_ids = array_column($s2->fetchAll(), 'id');
        }
    }

    $where_dept = $dept_filter ? "AND e.dept_id = $dept_filter" : '';

    $weekly  = pts_leaderboard($conn, 20, 'weekly',  $dept_filter, $tl_team_user_ids);
    $alltime = pts_leaderboard($conn, 20, 'alltime', $dept_filter, $tl_team_user_ids);

    $all_emps = $conn->query("
        SELECT e.id, e.name, e.email, d.name AS dept_name, r.name AS role_name,
               COALESCE(SUM(CASE WHEN pl.is_tl_bonus=0 THEN pl.points ELSE 0 END),0) AS individual_pts,
               COALESCE(SUM(CASE WHEN pl.is_tl_bonus=1 THEN pl.points ELSE 0 END),0) AS tl_bonus_pts,
               COALESCE(SUM(pl.points),0) AS total_pts,
               COALESCE(SUM(CASE WHEN pl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN pl.points ELSE 0 END),0) AS weekly_pts
        FROM employees e
        LEFT JOIN departments d ON d.id = e.dept_id
        LEFT JOIN employee_roles er ON er.employee_id = e.id
        LEFT JOIN roles r ON r.id = er.role_id
        LEFT JOIN points_log pl ON pl.employee_id = e.id
        WHERE e.status IN ('ACTIVE','PROBATION') $where_dept
        GROUP BY e.id
        ORDER BY total_pts DESC
    ")->fetchAll();

    include 'header.php';
?>

<style>
.pts-pill  { border-radius:999px; padding:2px 10px; font-size:.75rem; font-weight:600; }
.pts-ind   { background:#ecfdf5; color:#047857; }
.pts-tl    { background:#eff6ff; color:#1d4ed8; }
.pts-week  { background:#fef9c3; color:#854d0e; }
.pts-tab   { padding:6px 16px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; color:#64748b;
             font-size:.82rem; font-weight:500; cursor:pointer; transition:all .15s; }
.pts-tab:hover  { border-color:#0f4c81; color:#0f4c81; }
.pts-tab.active { background:#0f4c81; border-color:#0f4c81; color:#fff; }
.pts-rank-badge { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center;
                  font-size:.75rem; font-weight:700; flex-shrink:0; }
.pts-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;
              color:#fff; font-weight:700; font-size:.85rem; flex-shrink:0; }
.pts-top-card { background:#fff; border:1px solid #e9ecef; border-radius:14px; padding:18px; }
.pts-top-row { padding:10px 0; display:flex; align-items:center; gap:12px; }
.pts-top-row + .pts-top-row { border-top:1px solid #f1f5f9; }
</style>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <p class="text-muted small mb-0">
        <?= $dept_filter_name
            ? 'Points for <strong>'.sanitize($dept_filter_name).'</strong> department.'
            : 'Points earned across DigiOps tasks, HRMS tasks and attendance.' ?>
    </p>
    <div class="d-flex gap-2">
        <button type="button" class="pts-tab active" id="btn-all"    onclick="showTab('all')">All Employees</button>
        <button type="button" class="pts-tab"        id="btn-weekly" onclick="showTab('weekly')">Weekly Top</button>
        <button type="button" class="pts-tab"        id="btn-alltime"onclick="showTab('alltime')">All-Time Top</button>
    </div>
</div>

<?php if ($u['role'] === 'TEAM_LEAD' && $dept_filter_name): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;background:linear-gradient(135deg,#0f4c81,#1e6db5);color:#fff;">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:44px;height:44px;background:rgba(255,255,255,.15);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-people-fill" style="font-size:1.3rem;"></i>
            </div>
            <div>
                <div style="font-size:.75rem;opacity:.75;">Your Team</div>
                <div style="font-size:1.1rem;font-weight:700;"><?= sanitize($dept_filter_name) ?> Department</div>
            </div>
        </div>
        <div class="row g-3">
            <?php
                $team_total_week  = array_sum(array_column($all_emps, 'weekly_pts'));
                $team_total_all   = array_sum(array_column($all_emps, 'total_pts'));
                $tl_bonus_earned  = $emp ? (int)$conn->prepare("SELECT COALESCE(SUM(points),0) FROM points_log WHERE employee_id=? AND is_tl_bonus=1")->execute([$emp['id']]) : 0;
                // Recalculate TL bonus properly
                $tl_bonus_stmt = $conn->prepare("SELECT COALESCE(SUM(points),0) FROM points_log WHERE employee_id=? AND is_tl_bonus=1");
                $tl_bonus_stmt->execute([$emp['id'] ?? 0]);
                $tl_bonus_earned = (int)$tl_bonus_stmt->fetchColumn();
                $best = $all_emps ? $all_emps[0] : null;
            ?>
            <div class="col-6 col-md-3">
                <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:12px;text-align:center;">
                    <div style="font-size:1.6rem;font-weight:800;"><?= count($all_emps) ?></div>
                    <div style="font-size:.72rem;opacity:.8;">Team Members</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:12px;text-align:center;">
                    <div style="font-size:1.6rem;font-weight:800;"><?= number_format($team_total_week) ?></div>
                    <div style="font-size:.72rem;opacity:.8;">Team Pts This Week</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:12px;text-align:center;">
                    <div style="font-size:1.6rem;font-weight:800;"><?= number_format($tl_bonus_earned) ?></div>
                    <div style="font-size:.72rem;opacity:.8;">Your TL Bonus</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:12px;text-align:center;">
                    <div style="font-size:1.1rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $best ? sanitize(explode(' ',$best['name'])[0]) : '—' ?></div>
                    <div style="font-size:.72rem;opacity:.8;">Top This Week</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <div style="font-size:1.8rem;font-weight:700;color:#0f4c81;"><?= count($all_emps) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Active Employees</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $topScore = $all_emps ? max(array_column($all_emps, 'total_pts')) : 0; ?>
            <div style="font-size:1.8rem;font-weight:700;color:#b45309;"><?= number_format($topScore) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Highest Score</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $weeklyTotal = array_sum(array_column($all_emps, 'weekly_pts')); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#047857;"><?= number_format($weeklyTotal) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Points This Week</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $totalAll = array_sum(array_column($all_emps, 'total_pts')); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#7c3aed;"><?= number_format($totalAll) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Total Points</div>
        </div>
    </div>
</div>

<!-- All Employees Tab -->
<div id="tab-all">
<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width:42px;">#</th>
                        <th>Employee</th>
                        <th>Dept / Role</th>
                        <th>Individual</th>
                        <th>TL Bonus</th>
                        <th>This Week</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_emps as $i => $e):
                    $colors = ['#0f4c81','#047857','#7c3aed','#be185d','#b45309','#0369a1'];
                    $color  = $colors[$i % count($colors)];
                ?>
                <tr>
                    <td class="ps-4">
                        <?php if ($i < 3): ?>
                        <div class="pts-rank-badge" style="background:<?= ['#f59e0b','#94a3b8','#b45309'][$i] ?>1a;color:<?= ['#b45309','#475569','#7c2d12'][$i] ?>;">
                            <?= $i+1 ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small"><?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="pts-avatar" style="background:linear-gradient(135deg,<?= $color ?>,<?= $color ?>cc);font-size:.8rem;">
                                <?= strtoupper(substr($e['name'],0,1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?= sanitize($e['name']) ?></div>
                                <div class="text-muted" style="font-size:.7rem;"><?= sanitize($e['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="small"><?= sanitize($e['dept_name'] ?? '—') ?></div>
                        <div class="text-muted" style="font-size:.7rem;"><?= sanitize($e['role_name'] ?? '—') ?></div>
                    </td>
                    <td><span class="pts-pill pts-ind"><?= number_format((int)$e['individual_pts']) ?></span></td>
                    <td>
                        <?php if ($e['tl_bonus_pts'] > 0): ?>
                            <span class="pts-pill pts-tl"><?= number_format((int)$e['tl_bonus_pts']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="pts-pill pts-week"><?= number_format((int)$e['weekly_pts']) ?></span></td>
                    <td>
                        <strong style="font-size:.95rem;"><?= number_format((int)$e['total_pts']) ?></strong>
                    </td>
                    <td>
                        <a href="employee_profile.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$all_emps): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">No employees yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<!-- Weekly Top Tab -->
<div id="tab-weekly" style="display:none;">
<div class="row g-3">
    <div class="col-lg-7">
        <div class="pts-top-card">
            <h6 class="fw-bold mb-1"><i class="bi bi-bar-chart-fill me-2 text-success"></i>Weekly Leaderboard</h6>
            <p class="text-muted small mb-3">Top performers in the last 7 days.</p>
            <?php foreach ($weekly as $i => $row):
                $medal_colors = ['#f59e0b','#94a3b8','#b45309'];
                $mc = $medal_colors[$i] ?? null;
            ?>
            <div class="pts-top-row">
                <div class="pts-rank-badge" style="background:<?= $mc ? $mc.'22' : '#f1f5f9' ?>;color:<?= $mc ?? '#64748b' ?>;">
                    <?= $i===0 ? '🥇' : ($i===1 ? '🥈' : ($i===2 ? '🥉' : ($i+1))) ?>
                </div>
                <div class="pts-avatar" style="background:linear-gradient(135deg,#0f4c81,#1e6db5);font-size:.78rem;">
                    <?= strtoupper(substr($row['name'],0,1)) ?>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= sanitize($row['name']) ?></div>
                    <div class="text-muted" style="font-size:.7rem;"><?= sanitize($row['role_name'] ?? '') ?></div>
                </div>
                <span class="pts-pill pts-ind"><?= number_format((int)$row['total_pts']) ?> pts</span>
            </div>
            <?php endforeach; ?>
            <?php if (!$weekly): ?><div class="text-muted small text-center py-4">No data yet.</div><?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="pts-top-card h-100 d-flex flex-column justify-content-center align-items-center text-center" style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);">
            <i class="bi bi-bar-chart-steps text-success" style="font-size:3rem;opacity:.5;"></i>
            <div class="fw-bold mt-2 text-success">Weekly Challenge</div>
            <div class="text-muted small mt-1">Complete tasks and attend on time<br>to climb the leaderboard!</div>
        </div>
    </div>
</div>
</div>

<!-- All-Time Top Tab -->
<div id="tab-alltime" style="display:none;">
<div class="row g-3">
    <div class="col-lg-7">
        <div class="pts-top-card">
            <h6 class="fw-bold mb-1"><i class="bi bi-trophy-fill me-2 text-warning"></i>All-Time Leaderboard</h6>
            <p class="text-muted small mb-3">Total points earned since the beginning.</p>
            <?php foreach ($alltime as $i => $row):
                $medal_colors = ['#f59e0b','#94a3b8','#b45309'];
                $mc = $medal_colors[$i] ?? null;
            ?>
            <div class="pts-top-row">
                <div class="pts-rank-badge" style="background:<?= $mc ? $mc.'22' : '#f1f5f9' ?>;color:<?= $mc ?? '#64748b' ?>;">
                    <?= $i===0 ? '🥇' : ($i===1 ? '🥈' : ($i===2 ? '🥉' : ($i+1))) ?>
                </div>
                <div class="pts-avatar" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);font-size:.78rem;">
                    <?= strtoupper(substr($row['name'],0,1)) ?>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= sanitize($row['name']) ?></div>
                    <div class="text-muted" style="font-size:.7rem;"><?= sanitize($row['role_name'] ?? '') ?></div>
                </div>
                <div class="text-end">
                    <div><span class="pts-pill pts-ind"><?= number_format((int)$row['individual_pts']) ?> ind</span></div>
                    <?php if ($row['tl_bonus_pts'] > 0): ?>
                    <div class="mt-1"><span class="pts-pill pts-tl"><?= number_format((int)$row['tl_bonus_pts']) ?> TL</span></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$alltime): ?><div class="text-muted small text-center py-4">No data yet.</div><?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="pts-top-card h-100 d-flex flex-column justify-content-center align-items-center text-center" style="background:linear-gradient(135deg,#fefce8,#fef9c3);">
            <i class="bi bi-trophy-fill text-warning" style="font-size:3rem;opacity:.6;"></i>
            <div class="fw-bold mt-2" style="color:#b45309;">Hall of Fame</div>
            <div class="text-muted small mt-1">Keep earning points to secure<br>your place at the top!</div>
        </div>
    </div>
</div>
</div>

<script>
function showTab(tab) {
    ['all','weekly','alltime'].forEach(function(t) {
        document.getElementById('tab-'+t).style.display  = t === tab ? '' : 'none';
        document.getElementById('btn-'+t).classList.toggle('active', t === tab);
    });
}
</script>

<?php
    include 'footer.php';
    exit;
}

// ── Employee self-view ────────────────────────────────────────────────
$pageTitle = 'My Points';

$pts     = $emp ? pts_summary($conn, (int)$emp['id']) : ['individual_pts'=>0,'tl_bonus_pts'=>0,'total_pts'=>0,'weekly_pts'=>0];
$recent  = $emp ? pts_recent($conn, (int)$emp['id'], 20) : [];
$weekly  = pts_leaderboard($conn, 5, 'weekly');
$alltime = pts_leaderboard($conn, 5, 'alltime');

$rank = 0;
if ($emp && $pts['total_pts'] > 0) {
    $s = $conn->prepare("SELECT COUNT(*)+1 FROM (SELECT employee_id, SUM(points) AS t FROM points_log GROUP BY employee_id HAVING t > ?) x");
    $s->execute([$pts['total_pts']]);
    $rank = (int)$s->fetchColumn();
}

function pts_ago($dt) {
    $d = time() - strtotime($dt);
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return (int)($d/60).'m ago';
    if ($d < 86400) return (int)($d/3600).'h ago';
    return date('d M Y', strtotime($dt));
}

include 'header.php';
?>

<style>
.pts-hero   { background:linear-gradient(135deg,#0f4c81 0%,#1e6db5 60%,#2563eb 100%); color:#fff; border-radius:20px; padding:32px; }
.pts-block  { background:rgba(255,255,255,.12); border-radius:12px; padding:16px 18px; text-align:center; backdrop-filter:blur(4px); }
.pts-block:hover { background:rgba(255,255,255,.18); }
.pts-rank-hero { background:rgba(255,255,255,.15); border-radius:12px; padding:12px 20px; text-align:center; }
.pts-pill   { border-radius:999px; padding:2px 10px; font-size:.75rem; font-weight:600; }
.pts-ind    { background:#ecfdf5; color:#047857; }
.pts-tl     { background:#eff6ff; color:#1d4ed8; }
.pts-log-item { padding:10px 0; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; gap:12px; }
.pts-log-item:last-child { border-bottom:none; }
.pts-log-dot { width:8px; height:8px; border-radius:50%; background:#0f4c81; flex-shrink:0; margin-top:5px; }
.pts-leader-row { padding:10px 0; display:flex; align-items:center; gap:10px; }
.pts-leader-row + .pts-leader-row { border-top:1px solid #f1f5f9; }
.pts-mini-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                   color:#fff; font-weight:700; font-size:.72rem; flex-shrink:0; }
</style>

<!-- Hero -->
<div class="pts-hero mb-4">
    <div class="row g-3 align-items-center mb-4">
        <div class="col-auto">
            <div class="pts-rank-hero">
                <div style="font-size:.72rem;opacity:.8;margin-bottom:2px;">Your Rank</div>
                <div style="font-size:2.2rem;font-weight:800;line-height:1;"><?= $rank > 0 ? '#'.$rank : '—' ?></div>
            </div>
        </div>
        <div class="col">
            <div style="font-size:.75rem;opacity:.75;margin-bottom:4px;">Total Points Earned</div>
            <div style="font-size:2.8rem;font-weight:800;line-height:1;"><?= number_format($pts['total_pts']) ?></div>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-4">
            <div class="pts-block">
                <div style="font-size:.7rem;opacity:.8;margin-bottom:4px;">Individual</div>
                <div style="font-size:1.5rem;font-weight:700;"><?= number_format($pts['individual_pts']) ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="pts-block">
                <div style="font-size:.7rem;opacity:.8;margin-bottom:4px;">TL Bonus</div>
                <div style="font-size:1.5rem;font-weight:700;"><?= number_format($pts['tl_bonus_pts']) ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="pts-block">
                <div style="font-size:.7rem;opacity:.8;margin-bottom:4px;">This Week</div>
                <div style="font-size:1.5rem;font-weight:700;"><?= number_format($pts['weekly_pts']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Recent Activity -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm" style="border-radius:16px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Activity</h6>
                <p class="text-muted small mb-3">Your latest point transactions.</p>
                <?php if (!$recent): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-star" style="font-size:2.5rem;opacity:.3;"></i>
                    <div class="mt-2 small">No points yet — complete tasks and attend on time to start earning!</div>
                </div>
                <?php else: ?>
                <?php foreach ($recent as $ev): ?>
                <div class="pts-log-item">
                    <div class="pts-log-dot mt-1"></div>
                    <div class="flex-grow-1">
                        <div class="small fw-semibold"><?= sanitize($ev['rule_label'] ?? $ev['rule_key']) ?></div>
                        <?php if ($ev['reason']): ?><div class="text-muted" style="font-size:.7rem;"><?= sanitize($ev['reason']) ?></div><?php endif; ?>
                        <div class="text-muted" style="font-size:.68rem;margin-top:1px;"><?= pts_ago($ev['created_at']) ?></div>
                    </div>
                    <span class="pts-pill <?= $ev['is_tl_bonus'] ? 'pts-tl' : 'pts-ind' ?>">
                        +<?= (int)$ev['points'] ?> <?= $ev['is_tl_bonus'] ? 'TL' : 'pts' ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Leaderboards -->
    <div class="col-lg-5">
        <!-- All-Time Top 5 -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-trophy-fill me-2 text-warning"></i>All-Time Top 5</h6>
                <?php foreach ($alltime as $i => $row):
                    $medal = ['🥇','🥈','🥉'][$i] ?? null;
                    $isMe  = $emp && $row['employee_id'] == $emp['id'];
                ?>
                <div class="pts-leader-row <?= $isMe ? 'fw-bold' : '' ?>">
                    <div style="width:22px;text-align:center;font-size:.85rem;"><?= $medal ?? ($i+1) ?></div>
                    <div class="pts-mini-avatar" style="background:linear-gradient(135deg,<?= $i===0?'#f59e0b,#d97706':($i===1?'#94a3b8,#64748b':'#0f4c81,#1e6db5') ?>);">
                        <?= strtoupper(substr($row['name'],0,1)) ?>
                    </div>
                    <div class="flex-grow-1 small">
                        <?= sanitize($row['name']) ?>
                        <?php if ($isMe): ?><span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.58rem;">You</span><?php endif; ?>
                    </div>
                    <span class="pts-pill pts-ind"><?= number_format((int)$row['total_pts']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (!$alltime): ?><div class="text-muted small text-center py-3">No data yet.</div><?php endif; ?>
            </div>
        </div>

        <!-- Weekly Top 5 -->
        <div class="card border-0 shadow-sm" style="border-radius:16px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart-fill me-2 text-success"></i>Weekly Top 5</h6>
                <?php foreach ($weekly as $i => $row):
                    $medal = ['🥇','🥈','🥉'][$i] ?? null;
                    $isMe  = $emp && $row['employee_id'] == $emp['id'];
                ?>
                <div class="pts-leader-row <?= $isMe ? 'fw-bold' : '' ?>">
                    <div style="width:22px;text-align:center;font-size:.85rem;"><?= $medal ?? ($i+1) ?></div>
                    <div class="pts-mini-avatar" style="background:linear-gradient(135deg,<?= $i===0?'#22c55e,#16a34a':'#047857,#065f46' ?>);">
                        <?= strtoupper(substr($row['name'],0,1)) ?>
                    </div>
                    <div class="flex-grow-1 small">
                        <?= sanitize($row['name']) ?>
                        <?php if ($isMe): ?><span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.58rem;">You</span><?php endif; ?>
                    </div>
                    <span class="pts-pill pts-ind"><?= number_format((int)$row['total_pts']) ?> pts</span>
                </div>
                <?php endforeach; ?>
                <?php if (!$weekly): ?><div class="text-muted small text-center py-3">No data yet.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

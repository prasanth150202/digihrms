<?php
require_once 'config.php';
require_login();
$page      = 'learning';
$pageTitle = 'Learning';
$u         = current_user();
$uid       = $u['id'];
$role      = $u['role'];
$is_admin  = has_role('SUPER_ADMIN','DEPT_MANAGER');
$is_tl     = has_role('TEAM_LEAD');

// Get own employee ID
$empR = $conn->prepare("SELECT e.id FROM employees e JOIN users u ON u.email=e.email WHERE u.id=? LIMIT 1");
$empR->execute([$uid]);
$my_emp_id = (int)($empR->fetchColumn() ?: 0);

// ── Data for Employee view ─────────────────────────────────────────────────
$my_learning_tasks = [];
$my_badges         = [];
// Check if migration has been run
$tables_ready = false;
try {
    $conn->query("SELECT 1 FROM hrms_task_quiz_attempts LIMIT 1");
    $tables_ready = true;
} catch (PDOException $e) { $tables_ready = false; }

if ($tables_ready && $my_emp_id) {
    try {
        $mlt = $conn->prepare("
            SELECT t.*, b.name as badge_name, b.icon as badge_icon,
                   (SELECT passed FROM hrms_task_quiz_attempts
                    WHERE task_id=t.id AND employee_id=? AND passed=1 LIMIT 1) as quiz_passed,
                   (SELECT tl_approved FROM hrms_task_quiz_attempts
                    WHERE task_id=t.id AND employee_id=? AND passed=1 ORDER BY attempted_at DESC LIMIT 1) as tl_approved
            FROM tasks t
            LEFT JOIN hrms_badges b ON b.id = t.learning_badge_id
            WHERE t.is_learning_task=1 AND t.assigned_to=?
              AND t.deleted_at IS NULL
            ORDER BY t.created_at DESC
        ");
        $mlt->execute([$my_emp_id, $my_emp_id, $uid]);
        $my_learning_tasks = $mlt->fetchAll();
    } catch (PDOException $e) {}

    try {
        $mb = $conn->prepare("
            SELECT b.*, eb.earned_at, t.title as task_title
            FROM hrms_employee_badges eb
            JOIN hrms_badges b ON b.id = eb.badge_id
            LEFT JOIN tasks t ON t.id = eb.task_id
            WHERE eb.employee_id=?
            ORDER BY eb.earned_at DESC
        ");
        $mb->execute([$my_emp_id]);
        $my_badges = $mb->fetchAll();
    } catch (PDOException $e) {}
}

// ── Data for TL view ──────────────────────────────────────────────────────
$team_learning = [];
$team_pending_reviews = [];
if ($is_tl || $is_admin) {
    // Find team members (users in same dept whose TL is $uid, or all for admin)
    if ($is_admin) {
        $teamUserIds = $conn->query("SELECT id FROM users WHERE role='EMPLOYEE'")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tlDept = $conn->prepare("
            SELECT er.dept_id FROM employee_roles er
            JOIN employees e ON e.id=er.employee_id
            JOIN users u ON u.email=e.email
            WHERE u.id=? AND er.is_team_lead=1 LIMIT 1
        ");
        $tlDept->execute([$uid]);
        $deptId = $tlDept->fetchColumn();
        if ($deptId) {
            $teamUsers = $conn->prepare("
                SELECT u.id FROM users u
                JOIN employees e ON e.email=u.email
                JOIN employee_roles er ON er.employee_id=e.id
                WHERE er.dept_id=? AND er.is_team_lead=0
            ");
            $teamUsers->execute([$deptId]);
            $teamUserIds = $teamUsers->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $teamUserIds = [];
        }
    }

    if ($teamUserIds && $tables_ready) {
        $in = implode(',', array_map('intval', $teamUserIds));

        try {
            $tl = $conn->query("
                SELECT t.id, t.title, t.status, t.due_date, b.name as badge_name, b.icon as badge_icon,
                       u.name as assignee_name,
                       (SELECT score_pct FROM hrms_task_quiz_attempts WHERE task_id=t.id AND employee_id=e.id AND passed=1 ORDER BY attempted_at DESC LIMIT 1) as best_score,
                       (SELECT tl_approved FROM hrms_task_quiz_attempts WHERE task_id=t.id AND employee_id=e.id AND passed=1 ORDER BY attempted_at DESC LIMIT 1) as tl_approved
                FROM tasks t
                JOIN users u ON u.id=t.assigned_to
                LEFT JOIN employees e ON e.email=u.email
                LEFT JOIN hrms_badges b ON b.id=t.learning_badge_id
                WHERE t.is_learning_task=1 AND t.assigned_to IN ({$in})
                  AND t.deleted_at IS NULL
                ORDER BY t.created_at DESC
            ");
            $team_learning = $tl->fetchAll();
        } catch (PDOException $e) {}

        try {
            $pr = $conn->query("
                SELECT qa.*, e.name as emp_name, t.title as task_title, b.name as badge_name, b.icon as badge_icon
                FROM hrms_task_quiz_attempts qa
                JOIN employees e ON e.id=qa.employee_id
                JOIN tasks t ON t.id=qa.task_id
                LEFT JOIN hrms_badges b ON b.id=t.learning_badge_id
                WHERE qa.passed=1 AND qa.tl_approved IS NULL
                  AND t.assigned_to IN ({$in})
                ORDER BY qa.attempted_at ASC
            ");
            $team_pending_reviews = $pr->fetchAll();
        } catch (PDOException $e) {}
    }
}

// ── Admin: all badges awarded + completion stats ──────────────────────────
$all_badge_awards = [];
$completion_stats = [];
if ($is_admin && $tables_ready) {
    try {
        $aba = $conn->query("
            SELECT eb.earned_at, e.name as emp_name, b.name as badge_name, b.icon as badge_icon, t.title as task_title
            FROM hrms_employee_badges eb
            JOIN employees e ON e.id=eb.employee_id
            JOIN hrms_badges b ON b.id=eb.badge_id
            LEFT JOIN tasks t ON t.id=eb.task_id
            ORDER BY eb.earned_at DESC
            LIMIT 100
        ");
        $all_badge_awards = $aba->fetchAll();
    } catch (PDOException $e) {}

    try {
        $cs = $conn->query("
            SELECT b.name as badge_name, b.icon as badge_icon,
                   COUNT(DISTINCT eb.employee_id) as earned_count,
                   COUNT(DISTINCT t.assigned_to) as total_assigned
            FROM hrms_badges b
            LEFT JOIN hrms_employee_badges eb ON eb.badge_id=b.id
            LEFT JOIN tasks t ON t.learning_badge_id=b.id AND t.is_learning_task=1
            GROUP BY b.id
            ORDER BY earned_count DESC
        ");
        $completion_stats = $cs->fetchAll();
    } catch (PDOException $e) {}
}

include 'header.php';
?>

<style>
.learn-badge-pill {
    display:inline-flex;align-items:center;gap:8px;
    padding:8px 14px;border-radius:30px;
    background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.2);
}
.learn-badge-pill .bi-icon { font-size:1.4rem;line-height:1; }
.learn-badge-pill .name    { font-size:13px;font-weight:700;color:#7c3aed; }
.learn-badge-pill .date    { font-size:10px;color:var(--text-muted); }
.stat-card { background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:12px;padding:16px 20px;text-align:center; }
.stat-card .val { font-size:2rem;font-weight:800;color:var(--text-primary);line-height:1; }
.stat-card .lbl { font-size:11px;color:var(--text-muted);margin-top:4px; }
.learn-tab { padding:7px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;background:none;color:var(--text-secondary); }
.learn-tab.active { background:var(--primary);color:#fff; }
</style>

<?php if (!$tables_ready): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div>
        <strong>Learning module tables not found.</strong>
        <?php if (has_role('SUPER_ADMIN')): ?>
        Run the migration to set up the learning module:
        <a href="migrate_learning.php" class="btn btn-sm btn-warning ms-2" style="border-radius:6px;">Run Migration →</a>
        <?php else: ?>
        Please ask your Super Admin to run the learning module migration.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="mb-0 fw-bold" style="color:var(--text-primary);"><i class="bi bi-mortarboard-fill me-2" style="color:#7c3aed;"></i>Learning</h5>
        <div style="font-size:12px;color:var(--text-muted);">Track learning tasks, quiz progress, and earned badges</div>
    </div>
</div>

<?php if (!$is_admin && !$is_tl): ?>
<!-- ══════════════════════════════════════════════════════ EMPLOYEE VIEW -->

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="stat-card">
            <div class="val" style="color:#7c3aed;"><?= count($my_badges) ?></div>
            <div class="lbl">Badges Earned</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div class="val" style="color:#16a34a;"><?= count(array_filter($my_learning_tasks, fn($t) => $t['status']==='DONE')) ?></div>
            <div class="lbl">Completed</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div class="val" style="color:#f59e0b;"><?= count(array_filter($my_learning_tasks, fn($t) => $t['status']!=='DONE')) ?></div>
            <div class="lbl">In Progress</div>
        </div>
    </div>
</div>

<!-- My Badges -->
<?php if ($my_badges): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3" style="color:#7c3aed;"><i class="bi bi-trophy-fill me-2"></i>My Badges</h6>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($my_badges as $b): ?>
            <div class="learn-badge-pill" title="From: <?= sanitize($b['task_title'] ?? '') ?>">
                <span class="bi-icon"><?= sanitize($b['icon']) ?></span>
                <div>
                    <div class="name"><?= sanitize($b['name']) ?></div>
                    <div class="date"><?= date('d M Y', strtotime($b['earned_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- My Learning Tasks -->
<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-journal-check me-2"></i>My Learning Tasks</h6>
        <?php if (!$my_learning_tasks): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-mortarboard" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:8px;"></i>
            No learning tasks assigned yet.
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:var(--body-bg);">
                <tr>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Task</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Badge</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Quiz</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Status</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Due</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($my_learning_tasks as $lt): ?>
            <?php
                $quizStatus = '';
                if ($lt['quiz_passed'] && $lt['tl_approved'] === '1') $quizStatus = 'approved';
                elseif ($lt['quiz_passed'] && is_null($lt['tl_approved'])) $quizStatus = 'pending';
                elseif ($lt['quiz_passed'] === '0' || !$lt['quiz_passed']) $quizStatus = 'not_passed';
            ?>
            <tr>
                <td style="padding:12px 14px;font-weight:600;font-size:13px;"><?= sanitize($lt['title']) ?></td>
                <td style="padding:12px 14px;">
                    <?php if ($lt['badge_name']): ?>
                    <span><?= sanitize($lt['badge_icon'] ?? '🏅') ?> <?= sanitize($lt['badge_name']) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="padding:12px 14px;">
                    <?php if ($lt['quiz_required']): ?>
                        <?php if ($quizStatus === 'approved'): ?>
                        <span class="badge" style="background:#dcfce7;color:#166534;">✅ Passed</span>
                        <?php elseif ($quizStatus === 'pending'): ?>
                        <span class="badge" style="background:#fef9c3;color:#854d0e;">⏳ TL Review</span>
                        <?php else: ?>
                        <span class="badge" style="background:#fee2e2;color:#dc2626;">Pending</span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary">No quiz</span>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 14px;">
                    <?php $sc = ['TODO'=>'secondary','IN_PROGRESS'=>'primary','REVIEW'=>'warning','DONE'=>'success','BLOCKED'=>'danger']; ?>
                    <span class="badge bg-<?= $sc[$lt['status']] ?? 'secondary' ?>-subtle text-<?= $sc[$lt['status']] ?? 'secondary' ?>"><?= $lt['status'] ?></span>
                </td>
                <td style="padding:12px 14px;font-size:12px;color:var(--text-muted);"><?= $lt['due_date'] ? date('d M Y', strtotime($lt['due_date'])) : '—' ?></td>
                <td style="padding:12px 14px;">
                    <a href="task_detail.php?id=<?= $lt['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:11px;border-radius:6px;">Open</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════ TL / ADMIN VIEW -->

<!-- Tabs -->
<div class="d-flex gap-2 mb-4">
    <button class="learn-tab active" onclick="showTab('team',this)">
        <i class="bi bi-people me-1"></i><?= $is_admin ? 'All Learners' : 'My Team' ?>
    </button>
    <?php if ($team_pending_reviews): ?>
    <button class="learn-tab" onclick="showTab('reviews',this)" style="position:relative;">
        <i class="bi bi-clipboard-check me-1"></i>Pending Reviews
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:10px;"><?= count($team_pending_reviews) ?></span>
    </button>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <button class="learn-tab" onclick="showTab('stats',this)"><i class="bi bi-bar-chart me-1"></i>Stats</button>
    <?php endif; ?>
    <button class="learn-tab" onclick="showTab('mine',this)"><i class="bi bi-person me-1"></i>My Learning</button>
</div>

<!-- ── TAB: Team/All Learners ─────────────────────────────────────────── -->
<div id="tab-team">
<?php
$done_count    = count(array_filter($team_learning, fn($t) => $t['status']==='DONE'));
$pending_count = count(array_filter($team_learning, fn($t) => $t['status']!=='DONE'));
$badge_count   = $is_admin ? count($all_badge_awards) : count(array_filter($team_learning, fn($t) => $t['tl_approved']==='1'));
?>
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card"><div class="val" style="color:#16a34a;"><?= $done_count ?></div><div class="lbl">Completed</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="val" style="color:#f59e0b;"><?= $pending_count ?></div><div class="lbl">In Progress</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="val" style="color:#7c3aed;"><?= $badge_count ?></div><div class="lbl">Badges Awarded</div></div></div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-journal-check me-2"></i><?= $is_admin ? 'All Learning Tasks' : 'Team Learning Tasks' ?></h6>
        <?php if (!$team_learning): ?>
        <div class="text-center py-5 text-muted"><i class="bi bi-mortarboard" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>No learning tasks found.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:var(--body-bg);">
                <tr>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Employee</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Task</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Badge</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Quiz</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Status</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);padding:10px 14px;">Due</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($team_learning as $lt): ?>
            <?php
                $qs = '';
                if ($lt['tl_approved'] === '1')   $qs = 'approved';
                elseif (is_null($lt['tl_approved']) && $lt['best_score'] !== null) $qs = 'pending';
                else $qs = 'none';
            ?>
            <tr>
                <td style="padding:12px 14px;font-size:13px;font-weight:600;"><?= sanitize($lt['assignee_name']) ?></td>
                <td style="padding:12px 14px;font-size:13px;"><?= sanitize($lt['title']) ?></td>
                <td style="padding:12px 14px;"><?= sanitize($lt['badge_icon'] ?? '') ?> <?= sanitize($lt['badge_name'] ?? '—') ?></td>
                <td style="padding:12px 14px;">
                    <?php if ($qs === 'approved'): ?>
                    <span class="badge" style="background:#dcfce7;color:#166534;">✅ <?= $lt['best_score'] ?>%</span>
                    <?php elseif ($qs === 'pending'): ?>
                    <span class="badge" style="background:#fef9c3;color:#854d0e;">⏳ <?= $lt['best_score'] ?>%</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 14px;">
                    <?php $sc2 = ['TODO'=>'secondary','IN_PROGRESS'=>'primary','REVIEW'=>'warning','DONE'=>'success','BLOCKED'=>'danger']; ?>
                    <span class="badge bg-<?= $sc2[$lt['status']] ?? 'secondary' ?>-subtle text-<?= $sc2[$lt['status']] ?? 'secondary' ?>"><?= $lt['status'] ?></span>
                </td>
                <td style="padding:12px 14px;font-size:12px;color:var(--text-muted);"><?= $lt['due_date'] ? date('d M Y', strtotime($lt['due_date'])) : '—' ?></td>
                <td style="padding:12px 14px;">
                    <a href="task_detail.php?id=<?= $lt['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:11px;border-radius:6px;">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- ── TAB: Pending Reviews ───────────────────────────────────────────── -->
<?php if ($team_pending_reviews): ?>
<div id="tab-reviews" style="display:none;">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3" style="color:#b45309;"><i class="bi bi-clipboard-check me-2"></i>Quiz Answers Awaiting Your Review</h6>
            <?php foreach ($team_pending_reviews as $pr): ?>
            <div class="mb-3 p-3 rounded" style="background:var(--body-bg);border:1px solid var(--card-bdr);">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="small"><?= sanitize($pr['emp_name']) ?></strong>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge" style="background:#fef9c3;color:#854d0e;">Score: <?= $pr['score_pct'] ?>%</span>
                        <span class="small text-muted"><?= sanitize($pr['badge_icon'] ?? '🏅') ?> <?= sanitize($pr['badge_name'] ?? '') ?></span>
                    </div>
                </div>
                <div class="small text-muted mb-2">Task: <?= sanitize($pr['task_title']) ?> · <?= date('d M Y H:i', strtotime($pr['attempted_at'])) ?></div>
                <a href="task_detail.php?id=<?= $pr['task_id'] ?>" class="btn btn-sm btn-warning" style="font-size:11px;border-radius:6px;">
                    <i class="bi bi-eye me-1"></i>Review Answers in Task
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: Stats (Admin only) ───────────────────────────────────────── -->
<?php if ($is_admin): ?>
<div id="tab-stats" style="display:none;">
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart me-2"></i>Badge Completion Rates</h6>
                    <?php if (!$completion_stats): ?>
                    <div class="text-muted small text-center py-4">No badges yet.</div>
                    <?php else: ?>
                    <?php foreach ($completion_stats as $cs): ?>
                    <?php $pct = $cs['total_assigned'] > 0 ? round(($cs['earned_count'] / $cs['total_assigned']) * 100) : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold"><?= sanitize($cs['badge_icon']) ?> <?= sanitize($cs['badge_name']) ?></span>
                            <span class="small text-muted"><?= $cs['earned_count'] ?>/<?= $cs['total_assigned'] ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height:6px;border-radius:4px;">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:#7c3aed;border-radius:4px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-trophy me-2"></i>Recent Badge Awards</h6>
                    <?php if (!$all_badge_awards): ?>
                    <div class="text-muted small text-center py-4">No badges awarded yet.</div>
                    <?php else: ?>
                    <?php foreach (array_slice($all_badge_awards, 0, 15) as $ba): ?>
                    <div class="d-flex align-items-center gap-2 mb-2 py-2" style="border-bottom:1px solid var(--card-bdr);">
                        <span style="font-size:1.2rem;"><?= sanitize($ba['badge_icon']) ?></span>
                        <div class="flex-grow-1 small">
                            <div class="fw-semibold"><?= sanitize($ba['emp_name']) ?></div>
                            <div class="text-muted"><?= sanitize($ba['badge_name']) ?></div>
                        </div>
                        <div class="small text-muted"><?= date('d M', strtotime($ba['earned_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: My Own Learning (for TL/Admin) ───────────────────────────── -->
<div id="tab-mine" style="display:none;">
    <?php if ($my_badges): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3" style="color:#7c3aed;"><i class="bi bi-trophy-fill me-2"></i>My Badges</h6>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($my_badges as $b): ?>
                <div class="learn-badge-pill">
                    <span class="bi-icon"><?= sanitize($b['icon']) ?></span>
                    <div>
                        <div class="name"><?= sanitize($b['name']) ?></div>
                        <div class="date"><?= date('d M Y', strtotime($b['earned_at'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($my_learning_tasks): ?>
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-journal-check me-2"></i>My Learning Tasks</h6>
            <div class="list-group list-group-flush">
            <?php foreach ($my_learning_tasks as $lt): ?>
            <a href="task_detail.php?id=<?= $lt['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" style="border-color:var(--card-bdr);">
                <span class="small fw-semibold"><?= sanitize($lt['badge_icon'] ?? '') ?> <?= sanitize($lt['title']) ?></span>
                <span class="badge bg-<?= ['TODO'=>'secondary','IN_PROGRESS'=>'primary','DONE'=>'success'][$lt['status']] ?? 'secondary' ?>-subtle text-<?= ['TODO'=>'secondary','IN_PROGRESS'=>'primary','DONE'=>'success'][$lt['status']] ?? 'secondary' ?>"><?= $lt['status'] ?></span>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-mortarboard" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>No learning tasks assigned to you.</div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
function showTab(name, btn) {
    document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display='none');
    document.querySelectorAll('.learn-tab').forEach(b => b.classList.remove('active'));
    const el = document.getElementById('tab-' + name);
    if (el) el.style.display = '';
    btn.classList.add('active');
}
</script>

<?php include 'footer.php'; ?>

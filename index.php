<?php
require_once 'config.php';
require_login();
$page      = 'dashboard';
$pageTitle = 'Dashboard';
$u    = current_user();
$role = $u['role'];

// ══════════════════════════════════════════════════════════
//  EMPLOYEE / TEAM_LEAD / INTERVIEW_PANEL  — personal home
// ══════════════════════════════════════════════════════════
if (!has_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER')) {

    $uid = $u['id'];

    // Resolve employee record id
    $emp_stmt = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
    $emp_stmt->execute([$u['email']]);
    $emp_id = $emp_stmt->fetchColumn() ?: 0;

    // ── POST: apply leave ─────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_leave') {
        if (!$emp_id) {
            set_flash('danger', 'No employee record linked to your account. Contact HR.');
        } else {
            $from = $_POST['from_date'];
            $to   = $_POST['to_date'];
            $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
            $conn->prepare("INSERT INTO leaves (employee_id,leave_type,from_date,to_date,days,reason,status)
                            VALUES (?,?,?,?,?,?,'PENDING')")
                 ->execute([$emp_id, $_POST['leave_type'], $from, $to, $days, trim($_POST['reason'])]);
            set_flash('success', 'Leave application submitted.');
        }
        header("Location: index.php"); exit;
    }

    // ── POST: apply permission ────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_permission') {
        if (!$emp_id) {
            set_flash('danger', 'No employee record linked to your account. Contact HR.');
            header("Location: index.php"); exit;
        }

        $wc_raw = $conn->query("SELECT config_key,config_value FROM work_config")
                       ->fetchAll(PDO::FETCH_KEY_PAIR);
        $ws = $wc_raw['work_start_time'] ?? '09:00';
        $we = $wc_raw['work_end_time']   ?? '18:00';
        $pt = $_POST['permission_type'];

        $allowed_types = ['LATE_COMING', 'EARLY_GOING', 'IN_BETWEEN'];
        if (!in_array($pt, $allowed_types)) {
            set_flash('danger', 'Invalid permission type.');
            header("Location: index.php"); exit;
        }

        $from_save = trim($_POST['from_time'] ?? '');
        $to_save   = trim($_POST['to_time']   ?? '');

        if (!$from_save || !$to_save) {
            set_flash('danger', 'Both From Time and To Time are required.');
            header("Location: index.php"); exit;
        }

        $f    = strtotime("1970-01-01 $from_save");
        $t    = strtotime("1970-01-01 $to_save");
        $secs = $t - $f;

        if ($secs < 1800) {
            set_flash('danger', 'Minimum permission duration is 30 minutes.');
            header("Location: index.php"); exit;
        }

        $conn->prepare("INSERT INTO permission_requests
                        (employee_id,permission_type,request_date,from_time,to_time,reason,duration_hours)
                        VALUES (?,?,?,?,?,?,?)")
             ->execute([$emp_id, $pt, $_POST['request_date'],
                        $from_save, $to_save, trim($_POST['reason']),
                        round($secs / 3600, 2)]);
        set_flash('success', 'Permission request submitted.');
        header("Location: index.php"); exit;
    }

    // ── My tasks ──────────────────────────────────────────
    $ts = $conn->prepare("SELECT t.*, p.name as project_name
        FROM tasks t LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.assigned_to=? AND t.status NOT IN ('REQUESTED','REJECTED')
        ORDER BY FIELD(t.priority,'URGENT','HIGH','MEDIUM','LOW'), t.due_date ASC");
    $ts->execute([$uid]);
    $my_tasks = $ts->fetchAll();

    $task_stats = [
        'TODO'        => count(array_filter($my_tasks, fn($t) => $t['status']==='TODO')),
        'IN_PROGRESS' => count(array_filter($my_tasks, fn($t) => $t['status']==='IN_PROGRESS')),
        'REVIEW'      => count(array_filter($my_tasks, fn($t) => $t['status']==='REVIEW')),
        'DONE'        => count(array_filter($my_tasks, fn($t) => $t['status']==='DONE')),
    ];
    $active_tasks = array_values(array_filter($my_tasks, fn($t) => $t['status'] !== 'DONE'));

    // ── TL: all projects with team task counts ────────────
    $tl_projects = [];
    if ($role === 'TEAM_LEAD') {
        $tl_dept = null;
        $dq = $conn->prepare("
            SELECT er.dept_id FROM employee_roles er
            JOIN employees e ON e.id = er.employee_id
            JOIN users u2 ON u2.email = e.email
            WHERE u2.id = ? AND er.is_team_lead = 1 LIMIT 1
        ");
        $dq->execute([$uid]);
        $tl_dept = $dq->fetchColumn() ?: null;

        if ($tl_dept) {
            $pq = $conn->prepare("
                SELECT p.id, p.name, p.status,
                       COUNT(DISTINCT t.id)                                              AS task_count,
                       SUM(CASE WHEN t.status='TODO'        THEN 1 ELSE 0 END)          AS todo_count,
                       SUM(CASE WHEN t.status='IN_PROGRESS' THEN 1 ELSE 0 END)          AS inprogress_count,
                       SUM(CASE WHEN t.status='REVIEW'      THEN 1 ELSE 0 END)          AS review_count,
                       SUM(CASE WHEN t.status='DONE'        THEN 1 ELSE 0 END)          AS done_count
                FROM projects p
                LEFT JOIN tasks t ON t.project_id = p.id
                    AND t.deleted_at IS NULL
                    AND t.assigned_to IN (
                        SELECT u2.id FROM users u2
                        JOIN employees e ON e.email = u2.email
                        WHERE e.dept_id = ?
                    )
                GROUP BY p.id
                ORDER BY p.status DESC, p.name ASC
            ");
            $pq->execute([$tl_dept]);
            $tl_projects = $pq->fetchAll();
        } else {
            $tl_projects = $conn->query("
                SELECT p.id, p.name, p.status,
                       0 AS task_count, 0 AS todo_count, 0 AS inprogress_count, 0 AS review_count, 0 AS done_count
                FROM projects p ORDER BY p.status DESC, p.name ASC
            ")->fetchAll();
        }
    }

    // ── My leaves ─────────────────────────────────────────
    $ls = $conn->prepare("SELECT l.*, utl.name as tl_approver_name
        FROM leaves l LEFT JOIN users utl ON utl.id = l.tl_approved_by
        WHERE l.employee_id=? ORDER BY l.created_at DESC LIMIT 10");
    $ls->execute([$emp_id ?: 0]);
    $my_leaves = $ls->fetchAll();

    $lv_status = [
        'PENDING'     => ['color'=>'warning',   'label'=>'Awaiting TL'],
        'TL_APPROVED' => ['color'=>'info',      'label'=>'Awaiting HR'],
        'APPROVED'    => ['color'=>'success',   'label'=>'Approved'],
        'REJECTED'    => ['color'=>'danger',    'label'=>'Rejected'],
        'REVOKED'     => ['color'=>'secondary', 'label'=>'Revoked'],
    ];

    // ── Leave balance (monthly) ───────────────────────────
    $leave_types   = $conn->query("SELECT * FROM leave_types WHERE active=1 ORDER BY sort_order,name")->fetchAll();
    $leave_balance = [];
    $cur_y = date('Y'); $cur_m = date('n');
    if ($emp_id) {
        foreach ($leave_types as $lt) {
            $q = $conn->prepare("SELECT COALESCE(SUM(days),0) FROM leaves
                WHERE employee_id=? AND leave_type=? AND status='APPROVED'
                AND YEAR(from_date)=? AND MONTH(from_date)=?");
            $q->execute([$emp_id, $lt['name'], $cur_y, $cur_m]);
            $used    = (float)$q->fetchColumn();
            $allowed = (float)$lt['days_per_month'];
            $leave_balance[$lt['name']] = [
                'allowed'   => $allowed,
                'used'      => $used,
                'remaining' => max(0, $allowed - $used),
                'color'     => $lt['color'],
            ];
        }
    }

    // ── My permissions ────────────────────────────────────
    $ps = $conn->prepare("SELECT * FROM permission_requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 10");
    $ps->execute([$emp_id ?: 0]);
    $my_perms = $ps->fetchAll();

    // ── Permission hours balance (monthly) ────────────────
    $wc_cfg       = $conn->query("SELECT config_key,config_value FROM work_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    $perm_limit   = (float)($wc_cfg['permission_hours_per_month'] ?? 2);
    $ph = $conn->prepare("SELECT COALESCE(SUM(duration_hours),0) FROM permission_requests
        WHERE employee_id=? AND status != 'REJECTED'
        AND YEAR(request_date)=? AND MONTH(request_date)=?");
    $ph->execute([$emp_id ?: 0, date('Y'), date('n')]);
    $perm_used      = (float)$ph->fetchColumn();
    $perm_remaining = max(0, $perm_limit - $perm_used);

    $priority_color = ['LOW'=>'success','MEDIUM'=>'warning','HIGH'=>'danger','URGENT'=>'dark'];
    $status_color   = ['TODO'=>'secondary','IN_PROGRESS'=>'primary','REVIEW'=>'warning','DONE'=>'success'];

    include 'header.php';
?>

<!-- Greeting -->
<div class="hrms-greeting">
    <div class="hrms-greeting-title">
        <?= date('H') < 12 ? 'Good morning' : (date('H') < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= sanitize(explode(' ', $u['name'])[0]) ?>
    </div>
    <div class="hrms-greeting-sub"><?= date('l, d F Y') ?> &mdash; here's your work summary</div>
</div>

<!-- Task stat cards -->
<div class="hrms-stat-grid hrms-stat-grid-4">
    <?php foreach ([
        ['To Do',       $task_stats['TODO'],        'c-blue',   'bi-circle',             'circle'],
        ['In Progress', $task_stats['IN_PROGRESS'], 'c-amber',  'bi-play-circle-fill',   'play-circle-fill'],
        ['In Review',   $task_stats['REVIEW'],      'c-purple', 'bi-eye-fill',           'eye-fill'],
        ['Done',        $task_stats['DONE'],        'c-green',  'bi-check-circle-fill',  'check-circle-fill'],
    ] as [$label, $val, $cls, $ico]): ?>
    <div class="hrms-stat-card <?= $cls ?>">
        <div class="hrms-stat-icon"><i class="bi <?= $ico ?>"></i></div>
        <div class="hrms-stat-label"><?= $label ?></div>
        <div class="hrms-stat-value"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($role === 'TEAM_LEAD' && $tl_projects): ?>
<!-- ── TL: My Team Projects ───────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="hrms-section-head mb-0"><i class="bi bi-folder2-open"></i> All Projects — Team Task Overview</div>
            <a href="projects.php" class="btn btn-sm btn-outline-primary" style="font-size:12px;">Manage Projects</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
        <?php foreach ($tl_projects as $proj):
            $tc   = (int)$proj['task_count'];
            $done = (int)$proj['done_count'];
            $pct  = $tc > 0 ? round($done / $tc * 100) : 0;
            $colors = ['#3b82f6','#047857','#7c3aed','#be185d','#b45309','#0369a1','#dc2626','#0891b2'];
            $col  = $colors[abs(crc32($proj['name'])) % count($colors)];
        ?>
        <div style="background:var(--body-bg);border:1px solid var(--card-bdr);border-radius:10px;padding:14px 16px;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <div style="width:32px;height:32px;border-radius:8px;background:<?= $col ?>18;color:<?= $col ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0;">
                    <?= strtoupper(substr($proj['name'],0,1)) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.85rem;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($proj['name']) ?>">
                        <?= sanitize($proj['name']) ?>
                    </div>
                    <span style="font-size:.62rem;font-weight:600;padding:1px 7px;border-radius:10px;<?= $proj['status']==='ACTIVE' ? 'background:#dcfce7;color:#166534;' : 'background:#f1f5f9;color:#64748b;' ?>">
                        <?= $proj['status'] ?>
                    </span>
                </div>
            </div>
            <!-- Task count pills -->
            <div class="d-flex gap-2 flex-wrap mb-2" style="font-size:.68rem;">
                <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:8px;font-weight:600;">
                    <span style="color:#475569;"><?= (int)$proj['todo_count'] ?></span> Todo
                </span>
                <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:8px;font-weight:600;">
                    <span><?= (int)$proj['inprogress_count'] ?></span> In Progress
                </span>
                <span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:8px;font-weight:600;">
                    <span><?= (int)$proj['review_count'] ?></span> Review
                </span>
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-weight:600;">
                    <span><?= $done ?></span> Done
                </span>
            </div>
            <!-- Progress bar -->
            <div style="height:5px;background:var(--card-bdr);border-radius:10px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:10px;transition:width .3s;"></div>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size:.67rem;color:var(--text-muted);">
                <span><?= $done ?>/<?= $tc ?> team tasks done</span>
                <span style="font-weight:600;"><?= $pct ?>%</span>
            </div>
            <?php if ($tc > 0): ?>
            <a href="tasks.php?project=<?= $proj['id'] ?>" style="display:block;margin-top:8px;font-size:.72rem;text-align:center;color:var(--primary);text-decoration:none;font-weight:600;padding:4px;border:1px solid var(--primary-bdr);border-radius:6px;transition:background .12s;" onmouseover="this.style.background='var(--primary-bg)'" onmouseout="this.style.background=''">
                <i class="bi bi-list-task me-1"></i>View Team Tasks
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ── LEFT: tasks + history ──────────────────────────── -->
<div class="col-md-7">

    <!-- Active tasks -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="hrms-section-head mb-0"><i class="bi bi-list-task"></i> My Active Tasks</div>
                <a href="tasks.php" class="btn btn-sm btn-outline-primary" style="font-size:12px;">View All &amp; Board</a>
            </div>
            <?php if (!$active_tasks): ?>
            <div class="text-center py-4" style="color:var(--text-muted);">
                <i class="bi bi-check2-all d-block mb-2" style="font-size:2rem;color:var(--primary);opacity:.5;"></i>
                <div style="font-weight:600;font-size:13px;">All caught up!</div>
                <div style="font-size:12px;margin-top:2px;">No active tasks right now.</div>
            </div>
            <?php else: ?>
            <div class="d-flex flex-column gap-2">
            <?php foreach (array_slice($active_tasks, 0, 8) as $t):
                $overdue = $t['due_date'] && $t['due_date'] < date('Y-m-d');
            ?>
            <div class="hrms-task-item d-flex justify-content-between align-items-start">
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                        <span class="badge bg-<?= $priority_color[$t['priority']] ?> rounded-pill" style="font-size:.6rem;"><?= $t['priority'] ?></span>
                        <span class="badge bg-<?= $status_color[$t['status']] ?> rounded-pill" style="font-size:.6rem;"><?= str_replace('_',' ',$t['status']) ?></span>
                        <?php if ($overdue): ?><span class="badge bg-danger rounded-pill" style="font-size:.6rem;">OVERDUE</span><?php endif; ?>
                    </div>
                    <div style="font-weight:600;font-size:13px;" class="text-truncate">
                        <a href="task_detail.php?id=<?= $t['id'] ?>" style="text-decoration:none;color:var(--text-primary);"><?= sanitize($t['title']) ?></a>
                    </div>
                    <?php if ($t['project_name']): ?>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;"><i class="bi bi-folder me-1"></i><?= sanitize($t['project_name']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($t['due_date']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-left:12px;flex-shrink:0;"><?= date('d M', strtotime($t['due_date'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php if (count($active_tasks) > 8): ?>
            <div class="text-center mt-3">
                <a href="tasks.php" style="font-size:12px;color:var(--text-muted);">+<?= count($active_tasks) - 8 ?> more tasks</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Leave history -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="hrms-section-head mb-0"><i class="bi bi-calendar-check"></i> My Leaves</div>
                <a href="leaves.php" class="btn btn-sm btn-outline-primary" style="font-size:12px;">View All</a>
            </div>
            <?php if (!$my_leaves): ?>
            <div class="text-muted small text-center py-3">No leave applications yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_leaves as $l): ?>
                    <tr>
                        <td class="small"><?= sanitize($l['leave_type']) ?></td>
                        <td class="small"><?= date('d M', strtotime($l['from_date'])) ?></td>
                        <td class="small"><?= date('d M', strtotime($l['to_date'])) ?></td>
                        <td class="small"><?= $l['days'] ?>d</td>
                        <td>
                            <?php $ls = $lv_status[$l['status']] ?? ['color'=>'secondary','label'=>$l['status']]; ?>
                            <span class="badge bg-<?= $ls['color'] ?> rounded-pill" style="font-size:.65rem;"><?= $ls['label'] ?></span>
                            <?php if (!empty($l['tl_approver_name']) && $l['status'] !== 'PENDING'): ?>
                            <div class="text-muted" style="font-size:.6rem;"><i class="bi bi-person-check me-1"></i><?= sanitize($l['tl_approver_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($l['status'],['PENDING','TL_APPROVED','APPROVED']) && $l['from_date'] > date('Y-m-d')): ?>
                            <a href="leaves.php?action=revoke&id=<?= $l['id'] ?>"
                               class="text-warning small"
                               onclick="return hConfirmSync(event,'Revoke this leave?')" title="Revoke">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Permission history -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="hrms-section-head"><i class="bi bi-shield-check"></i> My Permissions</div>
            <?php if (!$my_perms): ?>
            <div class="text-muted small text-center py-3">No permission requests yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Type</th><th>Date</th><th>Time</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_perms as $p): ?>
                    <tr>
                        <?php $ptl = ['LATE_COMING'=>'Late Coming','EARLY_GOING'=>'Early Going','IN_BETWEEN'=>'In Between']; ?>
                        <td class="small"><?= $ptl[$p['permission_type']] ?? sanitize(str_replace('_',' ',$p['permission_type'])) ?></td>
                        <td class="small"><?= date('d M Y', strtotime($p['request_date'])) ?></td>
                        <td class="small text-muted">
                            <?php
                                $range = [];
                                if ($p['from_time']) $range[] = date('h:i A', strtotime($p['from_time']));
                                if ($p['to_time'])   $range[] = date('h:i A', strtotime($p['to_time']));
                                echo implode(' – ', $range);
                            ?>
                        </td>
                        <td><span class="badge bg-<?= badge($p['status']) ?> rounded-pill" style="font-size:.65rem;"><?= $p['status'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /left -->

<!-- ── RIGHT: apply cards ─────────────────────────────── -->
<div class="col-md-5 d-flex flex-column gap-4">

    <!-- Apply Leave -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="hrms-section-head mb-0"><i class="bi bi-calendar-plus"></i> Apply for Leave</div>
                <span style="font-size:11px;color:var(--text-muted);font-weight:600;"><?= date('F Y') ?></span>
            </div>

            <!-- Monthly balance chips -->
            <?php if ($leave_balance): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($leave_balance as $tname => $bal): ?>
            <div class="px-2 py-1 rounded-pill" style="background:<?= sanitize($bal['color']) ?>15;border:1px solid <?= sanitize($bal['color']) ?>40;font-size:.65rem;white-space:nowrap;">
                <span style="color:<?= sanitize($bal['color']) ?>;font-weight:600;"><?= sanitize($tname) ?></span>
                <span class="ms-1 <?= $bal['remaining']<=0?'text-danger fw-bold':'text-muted' ?>"><?= $bal['remaining'] ?>/<?= $bal['allowed'] ?></span>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="apply_leave">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Leave Type</label>
                    <select name="leave_type" class="form-select form-select-sm" onchange="dashShowBal(this.value)">
                        <?php foreach ($leave_types as $lt): ?>
                        <option value="<?= sanitize($lt['name']) ?>"><?= sanitize($lt['name']) ?> (<?= $lt['days_per_month'] ?>/mo)</option>
                        <?php endforeach; ?>
                        <?php if (!$leave_types): ?>
                        <option>Casual Leave</option>
                        <?php endif; ?>
                    </select>
                    <div id="dashBalHint" class="mt-1" style="font-size:.72rem;"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">From</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">To</label>
                        <input type="date" name="to_date"   class="form-control form-control-sm" required min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Reason</label>
                    <textarea name="reason" class="form-control form-control-sm" rows="2" required placeholder="Brief reason..."></textarea>
                </div>
                <button type="submit" class="btn btn-warning w-100 btn-sm fw-semibold">
                    <i class="bi bi-send me-1"></i>Submit Leave
                </button>
            </form>
        </div>
    </div>

    <!-- Apply Permission -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="hrms-section-head mb-0"><i class="bi bi-shield-plus"></i> Apply for Permission</div>
                <span style="font-size:11px;color:var(--text-muted);font-weight:600;"><?= date('F Y') ?></span>
            </div>

            <!-- Monthly hours balance bar -->
            <?php
            $perm_pct  = $perm_limit > 0 ? min(100, round(($perm_used / $perm_limit) * 100)) : 0;
            $bar_color = $perm_remaining <= 0 ? '#dc2626' : ($perm_pct >= 75 ? '#f59e0b' : '#0891b2');
            ?>
            <div class="p-3 rounded mb-3" style="background:#f0f9ff;border:1px solid #bae6fd;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold text-info">Monthly Permission Hours</span>
                    <span class="small fw-bold" style="color:<?= $bar_color ?>;">
                        <?= $perm_remaining ?> / <?= $perm_limit ?> hrs left
                    </span>
                </div>
                <div class="progress" style="height:6px;">
                    <div class="progress-bar" style="width:<?= $perm_pct ?>%;background:<?= $bar_color ?>;border-radius:4px;transition:width .4s;"></div>
                </div>
                <div class="text-muted mt-1" style="font-size:.68rem;">
                    <?= $perm_used ?> hrs used · <?= $perm_remaining ?> hrs remaining this month
                    <?php if ($perm_remaining <= 0): ?>
                    &nbsp;<span class="text-danger fw-semibold">Limit reached</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" id="permForm">
                <input type="hidden" name="action" value="apply_permission">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Permission Type</label>
                    <select name="permission_type" id="permType" class="form-select form-select-sm"
                            onchange="onPermTypeChange(this.value)">
                        <option value="LATE_COMING">Late Coming</option>
                        <option value="EARLY_GOING">Early Going</option>
                        <option value="IN_BETWEEN">In Between</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Date</label>
                    <input type="date" name="request_date" class="form-control form-control-sm"
                           required value="<?= date('Y-m-d') ?>">
                </div>

                <!-- Time fields — shown only for Late Coming / Early Going -->
                <div id="permTimeRow" class="mb-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" id="permFromLabel">From</label>
                            <input type="time" name="from_time" id="permFrom"
                                   class="form-control form-control-sm"
                                   oninput="calcPermDur()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" id="permToLabel">To</label>
                            <input type="time" name="to_time" id="permTo"
                                   class="form-control form-control-sm"
                                   oninput="calcPermDur()">
                        </div>
                    </div>
                    <!-- Live duration preview -->
                    <div id="permDurPreview" class="mt-2 rounded px-3 py-2 d-none"
                         style="font-size:.75rem;">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Reason</label>
                    <textarea name="reason" class="form-control form-control-sm" rows="2"
                              placeholder="Brief reason..."></textarea>
                </div>
                <button type="submit" id="permSubmitBtn" class="btn btn-info w-100 btn-sm fw-semibold text-white">
                    <i class="bi bi-send me-1"></i>Submit Permission
                </button>
            </form>
        </div>
    </div>

</div><!-- /right -->
</div><!-- /row -->

<script>
const _dashBal = <?= json_encode($leave_balance ?: new stdClass()) ?>;
function dashShowBal(type) {
    const el = document.getElementById('dashBalHint');
    if (!el) return;
    const b = _dashBal[type];
    if (!b) { el.innerHTML = ''; return; }
    const over = b.remaining <= 0;
    el.innerHTML = `<span style="color:${over?'#dc2626':'#15803d'};">
        <i class="bi bi-info-circle me-1"></i>
        ${b.remaining} day(s) remaining this month &nbsp;·&nbsp; ${b.used} used of ${b.allowed} allowed
    </span>`;
}

// ── Permission form logic ─────────────────────────────────
const _permLimit = <?= $perm_limit ?>;
const _permUsed  = <?= $perm_used ?>;
const _ws = '<?= $wc_cfg['work_start_time'] ?? '09:00' ?>';
const _we = '<?= $wc_cfg['work_end_time']   ?? '18:00' ?>';

function toMins(t) { const [h,m]=t.split(':').map(Number); return h*60+m; }
function fmtDur(mins) {
    if (mins <= 0) return '0m';
    const h = Math.floor(mins/60), m = mins%60;
    return h > 0 ? `${h}h${m > 0 ? ' '+m+'m' : ''}` : `${m}m`;
}

function onPermTypeChange(type) {
    const from = document.getElementById('permFrom');
    const to   = document.getElementById('permTo');
    const fLbl = document.getElementById('permFromLabel');
    const tLbl = document.getElementById('permToLabel');

    // Reset
    from.readOnly = false; from.classList.remove('bg-light');
    to.readOnly   = false; to.classList.remove('bg-light');

    if (type === 'LATE_COMING') {
        from.value = _ws;
        from.readOnly = true;
        from.classList.add('bg-light');
        to.value = '';
        fLbl.textContent = 'Work Start (auto)';
        tLbl.textContent = 'Your Arrival Time *';
    } else if (type === 'EARLY_GOING') {
        to.value = _we;
        to.readOnly = true;
        to.classList.add('bg-light');
        from.value = '';
        fLbl.textContent = 'Your Leaving Time *';
        tLbl.textContent = 'Work End (auto)';
    } else { // IN_BETWEEN
        from.value = '';
        to.value   = '';
        fLbl.textContent = 'From Time *';
        tLbl.textContent = 'To Time *';
    }
    calcPermDur();
}

function calcPermDur() {
    const from = document.getElementById('permFrom').value;
    const to   = document.getElementById('permTo').value;
    const prev = document.getElementById('permDurPreview');
    const btn  = document.getElementById('permSubmitBtn');

    if (!from || !to) { prev.classList.add('d-none'); btn.disabled = false; return; }

    const diffMins = toMins(to) - toMins(from);
    prev.classList.remove('d-none');

    if (diffMins < 30) {
        prev.style.cssText = 'background:#fef2f2;border:1px solid #fca5a5;';
        prev.innerHTML = `<i class="bi bi-exclamation-triangle me-1" style="color:#dc2626;"></i>
            <strong style="color:#dc2626;">Minimum 30 minutes required.</strong>
            &nbsp;Current: ${fmtDur(diffMins < 0 ? 0 : diffMins)}`;
        btn.disabled = true;
    } else {
        const remMins = Math.max(0, (_permLimit - _permUsed) * 60);
        const over    = diffMins > remMins;
        prev.style.cssText = over
            ? 'background:#fffbeb;border:1px solid #fde68a;'
            : 'background:#f0fdf4;border:1px solid #bbf7d0;';
        prev.innerHTML = `<i class="bi bi-check-circle me-1" style="color:#16a34a;"></i>
            Duration: <strong>${fmtDur(diffMins)}</strong>`
            + (over ? ` &nbsp;<span style="color:#d97706;">⚠ exceeds remaining ${fmtDur(remMins)}</span>` : '');
        btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('permType');
    if (sel) onPermTypeChange(sel.value);
});
</script>

<?php
    include 'footer.php';
    exit;
} // end employee dashboard
// ══════════════════════════════════════════════════════════
//  HR / ADMIN / MANAGER  — original metrics dashboard
// ══════════════════════════════════════════════════════════

$total_emp  = $conn->query("SELECT COUNT(*) FROM employees WHERE status IN ('ACTIVE','PROBATION')")->fetchColumn();
$open_req   = $conn->query("SELECT COUNT(*) FROM manpower_requests WHERE status = 'PENDING'")->fetchColumn();
$total_cand = $conn->query("SELECT COUNT(*) FROM candidates WHERE status NOT IN ('HIRED','REJECTED')")->fetchColumn();
$interviews = $conn->query("SELECT COUNT(*) FROM interviews WHERE status = 'SCHEDULED'")->fetchColumn();
$pending_lv = $conn->query("SELECT COUNT(*) FROM leaves WHERE status = 'PENDING'")->fetchColumn();
$perm_pend  = $conn->query("SELECT COUNT(*) FROM permission_requests WHERE status = 'PENDING'")->fetchColumn();

$recent_req  = $conn->query("SELECT r.*, d.name as dept_name FROM manpower_requests r LEFT JOIN departments d ON r.dept_id = d.id ORDER BY r.created_at DESC LIMIT 6")->fetchAll();
$dept_data   = $conn->query("SELECT d.name, COUNT(e.id) as cnt FROM departments d LEFT JOIN employees e ON e.dept_id = d.id AND e.status IN ('ACTIVE','PROBATION') GROUP BY d.id, d.name")->fetchAll();
$monthly     = $conn->query("SELECT DATE_FORMAT(joining_date,'%b %Y') as month, COUNT(*) as cnt FROM employees WHERE joining_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(joining_date,'%Y-%m') ORDER BY joining_date")->fetchAll();
$recent_cand = $conn->query("SELECT * FROM candidates ORDER BY created_at DESC LIMIT 5")->fetchAll();

include 'header.php';
?>

<!-- KPI Cards -->
<div class="hrms-stat-grid hrms-stat-grid-6">
    <?php
    $cards = [
        ['Active Employees',       $total_emp,  'c-blue',   'bi-person-badge'],
        ['Open Requests',          $open_req,   'c-amber',  'bi-file-earmark-text'],
        ['Pipeline Candidates',    $total_cand, 'c-teal',   'bi-person-lines-fill'],
        ['Interviews Scheduled',   $interviews, 'c-green',  'bi-camera-video'],
        ['Pending Leaves',         $pending_lv, 'c-red',    'bi-calendar-check'],
        ['Pending Permissions',    $perm_pend,  'c-purple', 'bi-shield-exclamation'],
    ];
    foreach ($cards as [$label, $val, $cls, $ico]):
    ?>
    <div class="hrms-stat-card <?= $cls ?>">
        <div class="hrms-stat-icon"><i class="bi <?= $ico ?>"></i></div>
        <div class="hrms-stat-label"><?= $label ?></div>
        <div class="hrms-stat-value"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="hrms-section-head"><i class="bi bi-bar-chart-line"></i> Monthly Hiring Trend</div>
                <canvas id="hireChart" height="90"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="hrms-section-head"><i class="bi bi-pie-chart"></i> Employees by Dept</div>
                <canvas id="deptChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Tables -->
<div class="row g-3">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="hrms-section-head mb-0"><i class="bi bi-file-earmark-text"></i> Recent Manpower Requests</div>
                    <a href="requests.php" class="btn btn-sm btn-outline-primary" style="font-size:12px;">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small">
                        <thead class="table-light"><tr><th>Title</th><th>Dept</th><th>Urgency</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_req as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($r['title']) ?></td>
                            <td><?= sanitize($r['dept_name'] ?? '-') ?></td>
                            <td><span class="badge bg-<?= badge($r['urgency']) ?>"><?= $r['urgency'] ?></span></td>
                            <td><span class="badge bg-<?= badge($r['status']) ?>"><?= $r['status'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$recent_req): ?><tr><td colspan="4" class="text-muted text-center">No requests yet</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="hrms-section-head mb-0"><i class="bi bi-person-lines-fill"></i> Recent Candidates</div>
                    <a href="candidates.php" class="btn btn-sm btn-outline-primary" style="font-size:12px;">View All</a>
                </div>
                <?php foreach ($recent_cand as $c): ?>
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div>
                        <div class="fw-semibold small"><?= sanitize($c['name']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= sanitize($c['applied_role'] ?? $c['source'] ?? '-') ?></div>
                    </div>
                    <span class="badge bg-<?= badge($c['status']) ?> rounded-pill"><?= $c['status'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (!$recent_cand): ?><p class="text-muted small text-center mt-3">No candidates yet</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const hireLabels = <?= json_encode(array_column($monthly, 'month')) ?>;
const hireData   = <?= json_encode(array_map('intval', array_column($monthly, 'cnt'))) ?>;
const deptLabels = <?= json_encode(array_column($dept_data, 'name')) ?>;
const deptData   = <?= json_encode(array_map('intval', array_column($dept_data, 'cnt'))) ?>;

new Chart(document.getElementById('hireChart'), {
    type: 'bar',
    data: { labels: hireLabels, datasets: [{ label: 'Hires', data: hireData, backgroundColor: '#0f4c81', borderRadius: 6 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
new Chart(document.getElementById('deptChart'), {
    type: 'doughnut',
    data: { labels: deptLabels, datasets: [{ data: deptData, backgroundColor: ['#0f4c81','#1e88e5','#43a047','#fb8c00','#e53935','#8e24aa'] }] },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
});
</script>

<?php include 'footer.php'; ?>

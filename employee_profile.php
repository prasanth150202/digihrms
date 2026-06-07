<?php
require_once 'config.php';
require_once 'points_helper.php';
require_login();

$id        = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: employees.php'); exit; }

$u_viewing = current_user();
$self_emp  = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
$self_emp->execute([$u_viewing['email']]);
$self_emp_row = $self_emp->fetch();
$page = ($self_emp_row && (int)$self_emp_row['id'] === $id) ? 'my_profile' : 'employees';

// Load employee
$s = $conn->prepare("
    SELECT e.*, d.name AS dept_name,
           r.name AS role_name, r.slug AS role_slug,
           er.is_team_lead, er.dept_id AS tl_dept_id
    FROM employees e
    LEFT JOIN departments d ON d.id = e.dept_id
    LEFT JOIN employee_roles er ON er.employee_id = e.id
    LEFT JOIN roles r ON r.id = er.role_id
    WHERE e.id = ?
");
$s->execute([$id]);
$emp = $s->fetch();
if (!$emp) { set_flash('danger', 'Employee not found.'); header('Location: employees.php'); exit; }

$pageTitle = $emp['name'];

// Points
$pts     = pts_summary($conn, $id);
$recent  = pts_recent($conn, $id, 15);
$weekly  = pts_leaderboard($conn, 5, 'weekly');
$alltime = pts_leaderboard($conn, 5, 'alltime');

// My rank
$s = $conn->prepare("
    SELECT COUNT(*) + 1 FROM (
        SELECT employee_id, SUM(points) AS total
        FROM points_log GROUP BY employee_id
        HAVING total > ?
    ) t
");
$s->execute([$pts['total_pts']]);
$rank = (int)$s->fetchColumn();

// Attendance stats (last 30 days)
$s = $conn->prepare("
    SELECT
        COUNT(*) AS total_days,
        SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN status = 'LATE'    THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN status = 'ABSENT'  THEN 1 ELSE 0 END) AS absent
    FROM attendance
    WHERE user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)
      AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$s->execute([$emp['email']]);
$att = $s->fetch();

// Task progress overview — supports ?task_period=week|month|all (AJAX)
// Task progress overview — supports ?task_period=week|month|all or ?from=&to= + &ajax=1
$task_period = $_GET['task_period'] ?? 'all';
$task_from   = $_GET['from'] ?? '';
$task_to     = $_GET['to']   ?? '';
$task_params = [$emp['email']];

if ($task_from && $task_to) {
    $task_date_clause = "AND DATE(created_at) BETWEEN ? AND ?";
    $task_params[] = $task_from;
    $task_params[] = $task_to;
} else {
    $task_date_clause = match($task_period) {
        'week'  => "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        'month' => "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
        default => "",
    };
}

$s = $conn->prepare("
    SELECT
        SUM(CASE WHEN status='TODO'        THEN 1 ELSE 0 END) AS todo,
        SUM(CASE WHEN status='IN_PROGRESS' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status='REVIEW'      THEN 1 ELSE 0 END) AS review,
        SUM(CASE WHEN status='DONE'        THEN 1 ELSE 0 END) AS done,
        COUNT(*)                                               AS total
    FROM tasks
    WHERE assigned_to = (SELECT id FROM users WHERE email = ? LIMIT 1)
      AND deleted_at IS NULL
      AND status NOT IN ('REQUESTED','REJECTED')
      $task_date_clause
");
$s->execute($task_params);
$taskStats = $s->fetch();

// Return JSON for AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($taskStats);
    exit;
}

function ago($dt) {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return (int)($d/60).'m ago';
    if ($d < 86400)  return (int)($d/3600).'h ago';
    return date('d M Y', strtotime($dt));
}

include 'header.php';
?>

<style>
.pts-hero { background:linear-gradient(135deg,#0f4c81,#1e6db5); color:#fff; border-radius:16px; padding:24px 28px; }
.pts-block { background:rgba(255,255,255,0.13); border-radius:10px; padding:14px 18px; text-align:center; }
.pts-log-item { padding:8px 0; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; }
.pts-log-item:last-child { border-bottom:none; }
.pts-pill  { border-radius:999px; padding:2px 10px; font-size:.75rem; font-weight:600; }
.pts-ind   { background:#ecfdf5; color:#047857; }
.pts-tl    { background:#eff6ff; color:#1d4ed8; }
</style>

<!-- Breadcrumb -->
<nav class="mb-3" style="font-size:13px;">
    <a href="employees.php" class="text-decoration-none text-muted">Employees</a>
    <span class="mx-1 text-muted">/</span>
    <span class="fw-semibold"><?= sanitize($emp['name']) ?></span>
</nav>

<div class="row g-4">

    <!-- Left: Profile card -->
    <div class="col-md-4 col-lg-3">
        <div class="card border-0 shadow-sm text-center p-4" style="border-radius:14px;">
            <div style="width:72px;height:72px;background:#0f4c81;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700;margin:0 auto 12px;">
                <?= strtoupper(substr($emp['name'],0,1)) ?>
            </div>
            <h6 class="fw-bold mb-1"><?= sanitize($emp['name']) ?></h6>
            <div class="text-muted small mb-2"><?= sanitize($emp['email']) ?></div>
            <?php if ($emp['role_name']): ?>
            <span class="badge bg-primary-subtle text-primary mb-1"><?= sanitize($emp['role_name']) ?></span>
            <?php endif; ?>
            <?php if ($emp['is_team_lead']): ?>
            <span class="badge bg-info-subtle text-info mb-1">Team Lead</span>
            <?php endif; ?>
            <hr>
            <!-- Work details -->
            <div class="text-start small">
                <div class="mb-2"><span class="text-muted">Emp Code:</span> <strong><?= sanitize($emp['emp_code'] ?? '—') ?></strong></div>
                <?php if ($emp['designation']): ?>
                <div class="mb-2"><span class="text-muted">Designation:</span> <strong><?= sanitize($emp['designation']) ?></strong></div>
                <?php endif; ?>
                <div class="mb-2"><span class="text-muted">Dept:</span> <strong><?= sanitize($emp['dept_name'] ?? '—') ?></strong></div>
                <div class="mb-2"><span class="text-muted">Joined:</span> <strong><?= $emp['joining_date'] ? date('d M Y', strtotime($emp['joining_date'])) : '—' ?></strong></div>
                <?php if ($emp['date_of_relieving']): ?>
                <div class="mb-2"><span class="text-muted">Relieved:</span> <strong><?= date('d M Y', strtotime($emp['date_of_relieving'])) ?></strong></div>
                <?php endif; ?>
                <div class="mb-2"><span class="text-muted">Status:</span>
                    <span class="badge bg-<?= badge($emp['status']) ?>-subtle text-<?= badge($emp['status']) ?>"><?= $emp['status'] ?></span>
                </div>
            </div>

            <!-- Personal details (HR/Admin only) -->
            <?php if ($page === 'my_profile'): ?>
            <a href="change_password.php" class="btn btn-outline-secondary btn-sm mt-2 w-100">
                <i class="bi bi-shield-lock me-1"></i>Change Password
            </a>
            <?php endif; ?>
            <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
            <hr>
            <div class="text-start small">
                <?php if ($emp['dob']): ?>
                <div class="mb-2"><span class="text-muted">DOB:</span> <strong><?= date('d M Y', strtotime($emp['dob'])) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['phone']): ?>
                <div class="mb-2"><span class="text-muted">Phone:</span> <strong><?= sanitize($emp['phone']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['emergency_phone']): ?>
                <div class="mb-2"><span class="text-muted">Emergency:</span> <strong><?= sanitize($emp['emergency_phone']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['marital_status']): ?>
                <div class="mb-2"><span class="text-muted">Marital:</span> <strong><?= sanitize($emp['marital_status']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['blood_group']): ?>
                <div class="mb-2"><span class="text-muted">Blood:</span> <strong><?= sanitize($emp['blood_group']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['graduation']): ?>
                <div class="mb-2"><span class="text-muted">Education:</span> <strong><?= sanitize($emp['graduation']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['address']): ?>
                <div class="mb-2"><span class="text-muted">Address:</span> <span><?= nl2br(sanitize($emp['address'])) ?></span></div>
                <?php endif; ?>
            </div>
            <hr>
            <div class="text-start small">
                <?php if ($emp['aadhar_no']): ?>
                <div class="mb-2"><span class="text-muted">Aadhar:</span> <strong><?= sanitize($emp['aadhar_no']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['pan_no']): ?>
                <div class="mb-2"><span class="text-muted">PAN:</span> <strong><?= sanitize($emp['pan_no']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['bank_acc_no']): ?>
                <div class="mb-2"><span class="text-muted">Bank A/C:</span> <strong><?= sanitize($emp['bank_acc_no']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['bank_name']): ?>
                <div class="mb-2"><span class="text-muted">Bank:</span> <strong><?= sanitize($emp['bank_name']) ?></strong></div>
                <?php endif; ?>
                <?php if ($emp['ifsc_code']): ?>
                <div class="mb-2"><span class="text-muted">IFSC:</span> <strong><?= sanitize($emp['ifsc_code']) ?></strong></div>
                <?php endif; ?>
            </div>
            <a href="employee_form.php?id=<?= $emp['id'] ?>" class="btn btn-outline-primary btn-sm mt-3 w-100">
                <i class="bi bi-pencil me-1"></i>Edit Profile
            </a>
            <?php endif; ?>
        </div>

        <!-- Attendance (30d) -->
        <div class="card border-0 shadow-sm mt-3 p-3" style="border-radius:14px;">
            <div class="fw-semibold small mb-3"><i class="bi bi-calendar-check me-2 text-success"></i>Attendance (30 days)</div>
            <div class="row g-2 text-center">
                <div class="col-4">
                    <div class="fw-bold text-success fs-5"><?= (int)($att['present'] ?? 0) ?></div>
                    <div class="text-muted" style="font-size:.7rem;">Present</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold text-warning fs-5"><?= (int)($att['late'] ?? 0) ?></div>
                    <div class="text-muted" style="font-size:.7rem;">Late</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold text-danger fs-5"><?= (int)($att['absent'] ?? 0) ?></div>
                    <div class="text-muted" style="font-size:.7rem;">Absent</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Points + activity -->
    <div class="col-md-8 col-lg-9">

        <!-- Points hero -->
        <div class="pts-hero mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <div class="small opacity-75">Overall Rank</div>
                    <div class="fs-3 fw-bold">#<?= $rank ?></div>
                </div>
                <div class="text-end">
                    <div class="small opacity-75">Total Points</div>
                    <div class="fs-3 fw-bold"><?= number_format($pts['total_pts']) ?></div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-4">
                    <div class="pts-block">
                        <div class="small opacity-75">Individual</div>
                        <div class="fs-5 fw-bold"><?= number_format($pts['individual_pts']) ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="pts-block">
                        <div class="small opacity-75">TL Bonus</div>
                        <div class="fs-5 fw-bold"><?= number_format($pts['tl_bonus_pts']) ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="pts-block">
                        <div class="small opacity-75">This Week</div>
                        <div class="fs-5 fw-bold"><?= number_format($pts['weekly_pts']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <!-- Points activity -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Points Activity</h6>
                        <?php if (!$recent): ?>
                        <div class="text-muted small text-center py-4">No points yet.</div>
                        <?php else: ?>
                        <?php foreach ($recent as $ev): ?>
                        <div class="pts-log-item">
                            <div>
                                <div class="small fw-semibold"><?= sanitize($ev['rule_label'] ?? $ev['rule_key']) ?></div>
                                <?php if ($ev['reason']): ?>
                                <div class="text-muted" style="font-size:.7rem;"><?= sanitize($ev['reason']) ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:.7rem;"><?= ago($ev['created_at']) ?></div>
                            </div>
                            <span class="pts-pill <?= $ev['is_tl_bonus'] ? 'pts-tl' : 'pts-ind' ?>">
                                +<?= (int)$ev['points'] ?> <?= $ev['is_tl_bonus'] ? 'TL' : 'pts' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Task progress overview -->
                <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                            <h6 class="fw-bold mb-0"><i class="bi bi-list-task me-2 text-secondary"></i>Task Progress</h6>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary task-period-btn active" data-period="all">All</button>
                                <button type="button" class="btn btn-outline-secondary task-period-btn" data-period="month">30d</button>
                                <button type="button" class="btn btn-outline-secondary task-period-btn" data-period="week">7d</button>
                                <button type="button" class="btn btn-outline-secondary task-period-btn" data-period="custom">Custom</button>
                            </div>
                        </div>
                        <div id="task-custom-range" class="d-none mb-3">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <input type="date" id="task-from" class="form-control form-control-sm" style="width:140px;">
                                <span class="text-muted small">to</span>
                                <input type="date" id="task-to" class="form-control form-control-sm" style="width:140px;">
                                <button class="btn btn-sm btn-primary" onclick="applyCustomTaskRange()">Apply</button>
                            </div>
                        </div>
                        <div id="task-stats-body">
                        <div class="row g-2 text-center">
                            <div class="col-3">
                                <div class="fw-bold fs-5 text-secondary" id="ts-todo"><?= (int)$taskStats['todo'] ?></div>
                                <div class="text-muted" style="font-size:.7rem;">Todo</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5 text-primary" id="ts-inprogress"><?= (int)$taskStats['in_progress'] ?></div>
                                <div class="text-muted" style="font-size:.7rem;">In Progress</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5 text-warning" id="ts-review"><?= (int)$taskStats['review'] ?></div>
                                <div class="text-muted" style="font-size:.7rem;">Review</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5 text-success" id="ts-done"><?= (int)$taskStats['done'] ?></div>
                                <div class="text-muted" style="font-size:.7rem;">Done</div>
                            </div>
                        </div>
                        <div class="progress mt-3" style="height:6px;">
                            <div class="progress-bar bg-success" id="ts-bar" style="width:<?= ($taskStats['total']>0) ? round($taskStats['done']/$taskStats['total']*100) : 0 ?>%"></div>
                        </div>
                        <div class="text-muted text-end mt-1" style="font-size:.7rem;" id="ts-label"><?= (int)$taskStats['done'] ?>/<?= (int)$taskStats['total'] ?> completed</div>
                        </div>
                    </div>
                </div>
                <script>
                function updateTaskStats(url) {
                    fetch(url)
                        .then(r => r.json())
                        .then(d => {
                            document.getElementById('ts-todo').textContent       = d.todo        ?? 0;
                            document.getElementById('ts-inprogress').textContent = d.in_progress ?? 0;
                            document.getElementById('ts-review').textContent     = d.review      ?? 0;
                            document.getElementById('ts-done').textContent       = d.done        ?? 0;
                            const total = parseInt(d.total) || 0;
                            const done  = parseInt(d.done)  || 0;
                            document.getElementById('ts-bar').style.width = total > 0 ? Math.round(done/total*100)+'%' : '0%';
                            document.getElementById('ts-label').textContent = done + '/' + total + ' completed';
                        });
                }

                document.querySelectorAll('.task-period-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.task-period-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        const customRange = document.getElementById('task-custom-range');
                        if (this.dataset.period === 'custom') {
                            customRange.classList.remove('d-none');
                        } else {
                            customRange.classList.add('d-none');
                            updateTaskStats('employee_profile.php?id=<?= $emp['id'] ?>&task_period=' + this.dataset.period + '&ajax=1');
                        }
                    });
                });

                function applyCustomTaskRange() {
                    const from = document.getElementById('task-from').value;
                    const to   = document.getElementById('task-to').value;
                    if (!from || !to) { alert('Please select both dates.'); return; }
                    updateTaskStats('employee_profile.php?id=<?= $emp['id'] ?>&from=' + from + '&to=' + to + '&ajax=1');
                }
                </script>
            </div>

            <!-- Leaderboards -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart-fill me-2 text-success"></i>Weekly Top 5</h6>
                        <?php if (!$weekly): ?>
                        <div class="text-muted small text-center py-3">No data yet.</div>
                        <?php else: ?>
                        <?php foreach ($weekly as $i => $row): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 <?= $i < count($weekly)-1 ? 'border-bottom' : '' ?> <?= $row['employee_id'] == $id ? 'fw-bold' : '' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= $i===0?'warning':'secondary' ?>" style="width:22px;"><?= $i+1 ?></span>
                                <span class="small"><?= sanitize($row['name']) ?></span>
                            </div>
                            <span class="pts-pill pts-ind"><?= number_format((int)$row['total_pts']) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-trophy-fill me-2 text-warning"></i>All-Time Top 5</h6>
                        <?php if (!$alltime): ?>
                        <div class="text-muted small text-center py-3">No data yet.</div>
                        <?php else: ?>
                        <?php foreach ($alltime as $i => $row): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 <?= $i < count($alltime)-1 ? 'border-bottom' : '' ?> <?= $row['employee_id'] == $id ? 'fw-bold' : '' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= $i===0?'warning':'secondary' ?>" style="width:22px;"><?= $i+1 ?></span>
                                <span class="small"><?= sanitize($row['name']) ?></span>
                            </div>
                            <div class="text-end">
                                <div><span class="pts-pill pts-ind"><?= number_format((int)$row['individual_pts']) ?></span></div>
                                <?php if ($row['tl_bonus_pts'] > 0): ?>
                                <div class="mt-1"><span class="pts-pill pts-tl"><?= number_format((int)$row['tl_bonus_pts']) ?> TL</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

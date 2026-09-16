<?php
require_once 'config.php';
require_login();
require_once 'task_timer_helper.php';
require_once 'digiops_sync_helper.php';

$page      = 'workspace';
$pageTitle = 'Workspace';
$u         = current_user();
$uid       = (int)$u['id'];

// Beta gate — fails closed if the column doesn't exist yet (migration not run)
// or the flag is off, so this page is a no-op for everyone until explicitly enabled.
$beta_enabled = false;
try {
    $wb = $conn->prepare("SELECT workspace_beta FROM users WHERE id=?");
    $wb->execute([$uid]);
    $beta_enabled = (bool)$wb->fetchColumn();
} catch (Exception $e) {
    $beta_enabled = false;
}

if (!$beta_enabled) {
    header('Location: index.php');
    exit;
}

function log_task_activity_ws($conn, $task_id, $user_id, $action, $detail = '') {
    $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
         ->execute([$task_id, $user_id, $action, $detail]);
}

// ── POST handlers ──────────────────────────────────────────
// Focus/Stop reuse the same timer + status machinery as tasks.php's "update_status"
// action (start_task_timer/stop_task_timer, task_comments, activity log, DigiOps sync)
// instead of a separate tracking mechanism, so this is one source of truth for time spent.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'set_focus') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $chk = $conn->prepare("SELECT id, status FROM tasks WHERE id=? AND assigned_to=? AND deleted_at IS NULL");
        $chk->execute([$tid, $uid]);
        $task = $chk->fetch();

        if ($task) {
            // Only one focused task at a time — stop any other active timer this user has running.
            $others = $conn->prepare("SELECT id FROM tasks WHERE assigned_to=? AND timer_status='ACTIVE' AND id<>?");
            $others->execute([$uid, $tid]);
            foreach ($others->fetchAll() as $o) {
                stop_task_timer($conn, (int)$o['id'], $uid);
            }

            start_task_timer($conn, $tid, $uid);

            // Moving a fresh task to Focus also nudges it into In Progress on the board —
            // mirrors tasks.php's own TODO -> IN_PROGRESS transition, including DigiOps sync.
            if ($task['status'] === 'TODO') {
                $conn->prepare("UPDATE tasks SET status='IN_PROGRESS', updated_at=NOW() WHERE id=?")->execute([$tid]);
                $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                     ->execute([$tid, $uid, "Stage moved: TODO → IN_PROGRESS"]);
                log_task_activity_ws($conn, $tid, $uid, 'STATUS_CHANGED', 'TODO → IN_PROGRESS');
                _digiops_task_sync($conn, $tid, 'IN_PROGRESS');
            }
        }
        header('Location: workspace.php'); exit;
    }

    if ($_POST['action'] === 'clear_focus') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $chk = $conn->prepare("SELECT id FROM tasks WHERE id=? AND assigned_to=?");
        $chk->execute([$tid, $uid]);
        if ($chk->fetch()) {
            stop_task_timer($conn, $tid, $uid);
        }
        header('Location: workspace.php'); exit;
    }
}

// ── Data ──────────────────────────────────────────────────
$active = $conn->prepare("SELECT id, timer_started_at FROM tasks WHERE assigned_to=? AND timer_status='ACTIVE' AND deleted_at IS NULL LIMIT 1");
$active->execute([$uid]);
$active = $active->fetch();
$focus_task_id    = $active['id'] ?? null;
$focus_started_at = $active['timer_started_at'] ?? null;

$tasks = $conn->prepare("SELECT t.*, p.name as project_name
    FROM tasks t
    LEFT JOIN projects p ON p.id=t.project_id
    WHERE t.assigned_to=? AND t.status NOT IN ('REQUESTED','REJECTED') AND t.deleted_at IS NULL
    ORDER BY FIELD(t.priority,'URGENT','HIGH','MEDIUM','LOW'), t.due_date ASC");
$tasks->execute([$uid]);
$tasks = $tasks->fetchAll();

$col_defs = [
    'TODO'        => 'To Do',
    'IN_PROGRESS' => 'In Progress',
    'REWORK'      => 'Rework',
    'BLOCKED'     => 'Blocked',
    'REVIEW'      => 'Review',
    'DONE'        => 'Done',
];
$columns = [];
foreach ($col_defs as $key => $label) $columns[$key] = ['label' => $label, 'items' => []];
foreach ($tasks as $t) {
    if (isset($columns[$t['status']])) $columns[$t['status']]['items'][] = $t;
}
// Keep the board tidy — drop optional columns when nobody has a task in them
foreach (['REWORK', 'BLOCKED'] as $optional) {
    if (empty($columns[$optional]['items'])) unset($columns[$optional]);
}

$priority_color = ['URGENT' => '#ef4444', 'HIGH' => '#f59e0b', 'MEDIUM' => '#3b82f6', 'LOW' => '#94a3b8'];
$is_tl = in_array($u['role'], ['SUPER_ADMIN', 'TEAM_LEAD', 'DEPT_MANAGER']);

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-0"><i class="bi bi-kanban me-2"></i>Workspace</h5>
        <div class="text-muted small">Click Focus to start working on a task — it moves to In Progress and starts the timer. Click a card for full details, comments and approvals.</div>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTaskModal">
        <i class="bi bi-plus-lg me-1"></i>New Task
    </button>
</div>

<div class="d-flex gap-3 pb-3" style="overflow-x:auto;">
    <?php foreach ($columns as $status => $col): ?>
    <div style="min-width:260px;max-width:260px;flex-shrink:0;">
        <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.4px;">
            <?= sanitize($col['label']) ?> <span class="text-muted"><?= count($col['items']) ?></span>
        </div>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($col['items'] as $t): $is_focused = $focus_task_id && (int)$focus_task_id === (int)$t['id']; ?>
            <div class="card border-0 shadow-sm" style="border-radius:12px;<?= $is_focused ? 'outline:2px solid #3b82f6;' : '' ?>">
                <div class="card-body p-3">
                    <?php if ($t['priority'] && isset($priority_color[$t['priority']])): ?>
                    <span class="badge mb-2" style="background:<?= $priority_color[$t['priority']] ?>22;color:<?= $priority_color[$t['priority']] ?>;font-size:.65rem;">
                        <?= sanitize($t['priority']) ?>
                    </span>
                    <?php endif; ?>
                    <div class="fw-semibold small mb-1">
                        <a href="task_detail.php?id=<?= (int)$t['id'] ?>" class="text-dark text-decoration-none">
                            <?= sanitize($t['title']) ?>
                        </a>
                    </div>
                    <?php if ($t['project_name']): ?>
                    <div class="text-muted small mb-2"><?= sanitize($t['project_name']) ?></div>
                    <?php endif; ?>
                    <a href="task_detail.php?id=<?= (int)$t['id'] ?>" class="small text-muted d-block mb-2">
                        <i class="bi bi-chat-left-text me-1"></i>Details &amp; comments
                    </a>

                    <?php if ($status === 'DONE'): ?>
                        <!-- No timer controls on completed tasks -->
                    <?php elseif ($is_focused): ?>
                        <div class="small fw-semibold text-primary mb-2">
                            <i class="bi bi-record-circle-fill me-1"></i>
                            Focused <span class="focus-timer" data-started="<?= sanitize($focus_started_at) ?>">00:00:00</span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="clear_focus">
                            <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-outline-primary btn-sm w-100">Stop</button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="set_focus">
                            <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-play-fill me-1"></i><?= $focus_task_id ? 'Switch focus' : 'Focus' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$col['items']): ?>
            <div class="text-muted small fst-italic px-1">Nothing here.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function() {
    var els = document.querySelectorAll('.focus-timer');
    if (!els.length) return;
    function tick() {
        els.forEach(function(el) {
            var started = new Date(el.dataset.started.replace(' ', 'T'));
            var diff = Math.max(0, Math.floor((Date.now() - started.getTime()) / 1000));
            var h = String(Math.floor(diff / 3600)).padStart(2, '0');
            var m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
            var s = String(diff % 60).padStart(2, '0');
            el.textContent = h + ':' + m + ':' + s;
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<!-- ── New Task modal — posts to the existing tasks.php create action (AJAX), then reloads here ── -->
<div class="modal fade" id="newTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <form id="newTaskForm">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-bold">New Task</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div id="newTaskError" class="alert alert-danger py-2 small d-none"></div>
                    <div>
                        <label class="form-label small fw-semibold">Title</label>
                        <input type="text" name="title" class="form-control form-control-sm" required>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Priority</label>
                            <select name="priority" class="form-select form-select-sm">
                                <option value="MEDIUM" selected>Medium</option>
                                <option value="URGENT">Urgent</option>
                                <option value="HIGH">High</option>
                                <option value="LOW">Low</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Due date</label>
                            <input type="date" name="due_date" class="form-control form-control-sm">
                        </div>
                    </div>
                    <?php if (!$is_tl): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="needs_approval" id="newTaskApproval" value="1">
                        <label class="form-check-label small" for="newTaskApproval">Send to my TL for approval</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('newTaskForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var errBox = document.getElementById('newTaskError');
    errBox.classList.add('d-none');
    var fd = new FormData(this);
    fd.set('action', <?= $is_tl ? "'create_task'" : "'create_own_task'" ?>);
    <?php if ($is_tl): ?>
    fd.set('assigned_to', '<?= $uid ?>'); // TLs land here to work their own tasks too — self-assign
    <?php endif; ?>
    fd.set('_ajax', '1');
    fetch('tasks.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json().catch(function() { throw new Error('Unexpected response'); }); })
        .then(function(data) {
            if (data && data.ok) {
                window.location.href = 'workspace.php';
            } else {
                errBox.textContent = (data && data.error) ? data.error : 'Could not create task.';
                errBox.classList.remove('d-none');
            }
        })
        .catch(function() {
            errBox.textContent = 'Could not create task — please try again.';
            errBox.classList.remove('d-none');
        });
});
</script>

<?php include 'footer.php'; ?>

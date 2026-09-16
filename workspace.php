<?php
require_once 'config.php';
require_login();

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

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'set_focus') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $chk = $conn->prepare("SELECT id FROM tasks WHERE id=? AND assigned_to=? AND deleted_at IS NULL");
        $chk->execute([$tid, $uid]);
        if ($chk->fetch()) {
            // Server-side guard: the UI disables switching without stopping first, but that's
            // only a client-side attribute — auto-close any still-open focus so every
            // FOCUS_STARTED always gets a matching FOCUS_STOPPED (needed for time-matching later).
            $prev = $conn->prepare("SELECT current_focus_task_id, focus_started_at FROM users WHERE id=?");
            $prev->execute([$uid]);
            $prev = $prev->fetch();
            if ($prev && $prev['current_focus_task_id'] && (int)$prev['current_focus_task_id'] !== $tid) {
                $mins = $prev['focus_started_at'] ? max(0, round((time() - strtotime($prev['focus_started_at'])) / 60)) : 0;
                $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
                     ->execute([$prev['current_focus_task_id'], $uid, 'FOCUS_STOPPED', "Focused for {$mins}m (switched)"]);
            }
            $conn->prepare("UPDATE users SET current_focus_task_id=?, focus_started_at=NOW() WHERE id=?")
                 ->execute([$tid, $uid]);
            $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
                 ->execute([$tid, $uid, 'FOCUS_STARTED', 'Focus started']);
        }
        header('Location: workspace.php'); exit;
    }

    if ($_POST['action'] === 'clear_focus') {
        $s = $conn->prepare("SELECT current_focus_task_id, focus_started_at FROM users WHERE id=?");
        $s->execute([$uid]);
        $row = $s->fetch();
        if ($row && $row['current_focus_task_id']) {
            $mins = $row['focus_started_at'] ? max(0, round((time() - strtotime($row['focus_started_at'])) / 60)) : 0;
            $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
                 ->execute([$row['current_focus_task_id'], $uid, 'FOCUS_STOPPED', "Focused for {$mins}m"]);
        }
        $conn->prepare("UPDATE users SET current_focus_task_id=NULL, focus_started_at=NULL WHERE id=?")->execute([$uid]);
        header('Location: workspace.php'); exit;
    }
}

// ── Data ──────────────────────────────────────────────────
$me = $conn->prepare("SELECT current_focus_task_id, focus_started_at FROM users WHERE id=?");
$me->execute([$uid]);
$me = $me->fetch();
$focus_task_id    = $me['current_focus_task_id'] ?? null;
$focus_started_at = $me['focus_started_at'] ?? null;

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

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-0"><i class="bi bi-kanban me-2"></i>Workspace</h5>
        <div class="text-muted small">Pick one task to focus on — click it again to stop.</div>
    </div>
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
                    <div class="fw-semibold small mb-1"><?= sanitize($t['title']) ?></div>
                    <?php if ($t['project_name']): ?>
                    <div class="text-muted small mb-2"><?= sanitize($t['project_name']) ?></div>
                    <?php endif; ?>

                    <?php if ($is_focused): ?>
                        <div class="small fw-semibold text-primary mb-2">
                            <i class="bi bi-record-circle-fill me-1"></i>
                            Focused <span class="focus-timer" data-started="<?= sanitize($focus_started_at) ?>">00:00:00</span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="clear_focus">
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

<?php include 'footer.php'; ?>

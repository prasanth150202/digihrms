<?php
require_once 'config.php';
require_once 'points_helper.php';
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

// Check if the self-log table has been migrated
$log_table_ready = false;
try {
    $conn->query("SELECT 1 FROM hrms_learning_logs LIMIT 1");
    $log_table_ready = true;
} catch (PDOException $e) { $log_table_ready = false; }

// Check if the Pursuing/Completed status columns have been migrated
$log_status_ready = false;
if ($log_table_ready) {
    try {
        $conn->query("SELECT status, completed_on FROM hrms_learning_logs LIMIT 1");
        $log_status_ready = true;
    } catch (PDOException $e) { $log_status_ready = false; }
}

// Check if progress %, proof-of-completion, and the history table have been migrated
$log_progress_ready = false;
if ($log_status_ready) {
    try {
        $conn->query("SELECT progress_pct, proof_url FROM hrms_learning_logs LIMIT 1");
        $conn->query("SELECT 1 FROM hrms_learning_log_updates LIMIT 1");
        $log_progress_ready = true;
    } catch (PDOException $e) { $log_progress_ready = false; }
}

function _learning_log_history_note(PDO $conn, int $logId, int $pct, string $status, ?string $note): void {
    $conn->prepare("INSERT INTO hrms_learning_log_updates (log_id, progress_pct, status, note) VALUES (?,?,?,?)")
         ->execute([$logId, $pct, $status, $note !== '' ? $note : null]);
}

// Renders one "my learning log" list item (title, status, progress, actions, history)
// plus its Update/Complete/Drop modals. Shared by the employee view and the
// TL/Admin "My Learning" tab so this ~100-line block isn't duplicated twice.
function _render_my_learning_log_item(array $log, bool $log_status_ready, bool $log_progress_ready): void {
    $status = $log['status'] ?? 'completed';
    ?>
    <div class="course-card">
        <div class="course-card-top">
            <div class="flex-grow-1">
                <div class="course-title"><?= sanitize($log['title']) ?></div>
                <?php if ($log_status_ready): ?>
                <div class="course-meta">
                    <?php if ($status === 'pursuing'): ?>
                    <span class="status-pill pursuing">🟡 Pursuing</span>
                    <span><i class="bi bi-calendar3 me-1"></i>Started <?= date('d M Y', strtotime($log['learned_on'])) ?></span>
                    <?php elseif ($status === 'dropped'): ?>
                    <span class="status-pill dropped">⛔ Dropped</span>
                    <span><i class="bi bi-calendar3 me-1"></i>Started <?= date('d M Y', strtotime($log['learned_on'])) ?></span>
                    <?php else: ?>
                    <span class="status-pill completed">✅ Completed</span>
                    <span><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($log['learned_on'])) ?> → <?= $log['completed_on'] ? date('d M Y', strtotime($log['completed_on'])) : '—' ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($log_progress_ready && $status === 'pursuing'): ?>
                <div class="course-progress-track"><div class="course-progress-fill" style="width:<?= (int)$log['progress_pct'] ?>%;"></div></div>
                <div class="course-progress-label"><?= (int)$log['progress_pct'] ?>% complete</div>
                <?php endif; ?>
                <?php if ($log_progress_ready && $status === 'completed' && !empty($log['proof_url'])): ?>
                <a href="<?= sanitize($log['proof_url']) ?>" target="_blank" rel="noopener noreferrer" class="course-proof mt-2"><i class="bi bi-patch-check-fill" style="color:#16a34a;"></i>Proof of completion</a>
                <?php endif; ?>
                <?php else: ?>
                <div class="course-meta"><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($log['learned_on'])) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($status === 'pursuing'): ?>
                <?php if ($log_progress_ready): ?>
                <div class="course-actions">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#lm-progress-<?= (int)$log['id'] ?>"><i class="bi bi-graph-up-arrow me-1"></i>Update %</button>
                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#lm-complete-<?= (int)$log['id'] ?>"><i class="bi bi-check2-circle me-1"></i>Complete</button>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#lm-drop-<?= (int)$log['id'] ?>"><i class="bi bi-x-circle me-1"></i>Drop</button>
                </div>
                <?php elseif ($log_status_ready): ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="mark_learning_log_completed">
                    <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                    <button type="submit" class="btn btn-outline-success" style="font-size:11px;border-radius:8px;white-space:nowrap;">Mark Completed</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($log['notes']): ?>
        <div class="course-notes"><?= nl2br(sanitize($log['notes'])) ?></div>
        <?php endif; ?>
        <?php if ($log_progress_ready && !empty($log['history'])): ?>
        <details class="course-history">
            <summary>History · <?= count($log['history']) ?> update<?= count($log['history']) === 1 ? '' : 's' ?></summary>
            <div class="timeline">
            <?php foreach (array_reverse($log['history']) as $h): ?>
                <div class="timeline-item">
                    <span class="t-date"><?= date('d M Y, h:i A', strtotime($h['created_at'])) ?></span> —
                    <?php if ($h['status'] === 'completed'): ?>Completed (100%)
                    <?php elseif ($h['status'] === 'dropped'): ?>Dropped at <?= (int)$h['progress_pct'] ?>%
                    <?php else: ?><?= (int)$h['progress_pct'] ?>%
                    <?php endif; ?>
                    <?php if ($h['note']): ?> — <?= sanitize($h['note']) ?><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>

    <?php if ($log_progress_ready && $status === 'pursuing'): ?>
    <div class="modal fade" id="lm-progress-<?= (int)$log['id'] ?>" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="update_learning_log_progress">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold mb-0">Update Progress</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label small fw-semibold">Progress %</label>
                <input type="number" name="progress_pct" class="form-control" min="0" max="100" value="<?= (int)$log['progress_pct'] ?>" required>
                <label class="form-label small fw-semibold mt-3">Note (optional)</label>
                <textarea name="note" class="form-control" rows="2" placeholder="What did you cover?"></textarea>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
            </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="lm-complete-<?= (int)$log['id'] ?>" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="mark_learning_log_completed">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold mb-0">Mark Completed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label small fw-semibold">Proof of completion (optional)</label>
                <input type="url" name="proof_url" class="form-control" placeholder="Paste a certificate/completion URL">
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">Mark Completed</button>
            </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="lm-drop-<?= (int)$log['id'] ?>" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="drop_learning_log">
            <input type="hidden" name="id" value="<?= (int)$log['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold mb-0">Drop this course?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label small fw-semibold">Reason (optional)</label>
                <textarea name="note" class="form-control" rows="2"></textarea>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Drop</button>
            </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php
}

// ── Add a self-logged learning entry ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_learning_log') {
    if (!$my_emp_id) {
        set_flash('danger', 'No employee record linked to your account.');
    } elseif (!$log_table_ready) {
        set_flash('danger', 'Learning log is not set up yet. Ask your Super Admin to run the migration.');
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid request. Please try again.');
    } else {
        $title       = trim($_POST['title'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $learnedOn   = $_POST['learned_on'] ?? date('Y-m-d');
        $status      = ($log_status_ready && ($_POST['status'] ?? 'pursuing') === 'completed') ? 'completed' : 'pursuing';
        $completedOn = $status === 'completed' ? ($_POST['completed_on'] ?: date('Y-m-d')) : null;
        $proofUrl    = ($log_progress_ready && $status === 'completed') ? trim($_POST['proof_url'] ?? '') : '';
        $progressPct = ($log_progress_ready && $status === 'completed') ? 100 : 0;
        if ($title === '') {
            set_flash('danger', 'Please enter a course/topic.');
        } elseif ($log_progress_ready) {
            $conn->prepare("INSERT INTO hrms_learning_logs (employee_id, title, notes, learned_on, status, completed_on, progress_pct, proof_url) VALUES (?,?,?,?,?,?,?,?)")
                 ->execute([$my_emp_id, $title, $notes !== '' ? $notes : null, $learnedOn, $status, $completedOn, $progressPct, $proofUrl !== '' ? $proofUrl : null]);
            $logId = (int)$conn->lastInsertId();
            _learning_log_history_note($conn, $logId, $progressPct, $status, 'Course added');
            pts_award($conn, $my_emp_id, 'hrms_learning_self_logged', (string)$logId, 'learning_log', "Logged: $title");
            set_flash('success', $status === 'completed' ? 'Nice — course logged as completed.' : 'Course added — good luck!');
        } elseif ($log_status_ready) {
            $conn->prepare("INSERT INTO hrms_learning_logs (employee_id, title, notes, learned_on, status, completed_on) VALUES (?,?,?,?,?,?)")
                 ->execute([$my_emp_id, $title, $notes !== '' ? $notes : null, $learnedOn, $status, $completedOn]);
            $logId = (int)$conn->lastInsertId();
            pts_award($conn, $my_emp_id, 'hrms_learning_self_logged', (string)$logId, 'learning_log', "Logged: $title");
            set_flash('success', $status === 'completed' ? 'Nice — course logged as completed.' : 'Course added — good luck!');
        } else {
            $conn->prepare("INSERT INTO hrms_learning_logs (employee_id, title, notes, learned_on) VALUES (?,?,?,?)")
                 ->execute([$my_emp_id, $title, $notes !== '' ? $notes : null, $learnedOn]);
            $logId = (int)$conn->lastInsertId();
            pts_award($conn, $my_emp_id, 'hrms_learning_self_logged', (string)$logId, 'learning_log', "Logged: $title");
            set_flash('success', 'Nice — logged what you learned.');
        }
    }
    header('Location: learning.php');
    exit;
}

// ── Mark a Pursuing entry as Completed ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_learning_log_completed') {
    $logId = (int)($_POST['id'] ?? 0);
    if (!$log_status_ready) {
        set_flash('danger', 'Learning log status is not set up yet. Ask your Super Admin to run the migration.');
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid request. Please try again.');
    } elseif ($logId && $my_emp_id) {
        if ($log_progress_ready) {
            $proofUrl = trim($_POST['proof_url'] ?? '');
            $stmt = $conn->prepare("UPDATE hrms_learning_logs SET status='completed', completed_on=?, progress_pct=100, proof_url=? WHERE id=? AND employee_id=? AND status='pursuing'");
            $stmt->execute([date('Y-m-d'), $proofUrl !== '' ? $proofUrl : null, $logId, $my_emp_id]);
            if ($stmt->rowCount() > 0) {
                _learning_log_history_note($conn, $logId, 100, 'completed', $proofUrl !== '' ? "Proof: $proofUrl" : null);
            }
        } else {
            $conn->prepare("UPDATE hrms_learning_logs SET status='completed', completed_on=? WHERE id=? AND employee_id=? AND status='pursuing'")
                 ->execute([date('Y-m-d'), $logId, $my_emp_id]);
        }
        set_flash('success', 'Marked as completed. 🎉');
    }
    header('Location: learning.php');
    exit;
}

// ── Update progress % on a Pursuing entry ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_learning_log_progress') {
    $logId = (int)($_POST['id'] ?? 0);
    $pct   = (int)($_POST['progress_pct'] ?? -1);
    $note  = trim($_POST['note'] ?? '');
    if (!$log_progress_ready) {
        set_flash('danger', 'Progress tracking is not set up yet. Ask your Super Admin to run the migration.');
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid request. Please try again.');
    } elseif ($pct < 0 || $pct > 100) {
        set_flash('danger', 'Progress must be between 0 and 100.');
    } elseif ($logId && $my_emp_id) {
        $stmt = $conn->prepare("UPDATE hrms_learning_logs SET progress_pct=? WHERE id=? AND employee_id=? AND status='pursuing'");
        $stmt->execute([$pct, $logId, $my_emp_id]);
        if ($stmt->rowCount() > 0) {
            _learning_log_history_note($conn, $logId, $pct, 'pursuing', $note);
            set_flash('success', "Progress updated to {$pct}%.");
        } else {
            set_flash('danger', 'Could not update — that course may already be completed or dropped.');
        }
    }
    header('Location: learning.php');
    exit;
}

// ── Drop a Pursuing entry ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drop_learning_log') {
    $logId = (int)($_POST['id'] ?? 0);
    $note  = trim($_POST['note'] ?? '');
    if (!$log_progress_ready) {
        set_flash('danger', 'Progress tracking is not set up yet. Ask your Super Admin to run the migration.');
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid request. Please try again.');
    } elseif ($logId && $my_emp_id) {
        $stmt = $conn->prepare("UPDATE hrms_learning_logs SET status='dropped' WHERE id=? AND employee_id=? AND status='pursuing'");
        $stmt->execute([$logId, $my_emp_id]);
        if ($stmt->rowCount() > 0) {
            $pctQ = $conn->prepare("SELECT progress_pct FROM hrms_learning_logs WHERE id=?");
            $pctQ->execute([$logId]);
            $currentPct = (int)$pctQ->fetchColumn();
            _learning_log_history_note($conn, $logId, $currentPct, 'dropped', $note);
            set_flash('success', 'Course marked as dropped.');
        }
    }
    header('Location: learning.php');
    exit;
}

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

// ── My self-logged learning entries ─────────────────────────────────────────
$my_logs = [];
if ($log_table_ready && $my_emp_id) {
    try {
        $ml = $conn->prepare("SELECT * FROM hrms_learning_logs WHERE employee_id=? ORDER BY learned_on DESC, created_at DESC LIMIT 50");
        $ml->execute([$my_emp_id]);
        $my_logs = $ml->fetchAll();

        if ($log_progress_ready && $my_logs) {
            $ids = implode(',', array_map('intval', array_column($my_logs, 'id')));
            $hist = $conn->query("SELECT * FROM hrms_learning_log_updates WHERE log_id IN ({$ids}) ORDER BY created_at ASC")->fetchAll();
            $histByLog = [];
            foreach ($hist as $h) { $histByLog[$h['log_id']][] = $h; }
            foreach ($my_logs as &$log) { $log['history'] = $histByLog[$log['id']] ?? []; }
            unset($log);
        }
    } catch (PDOException $e) {}
}

// ── Data for TL view ──────────────────────────────────────────────────────
$team_learning = [];
$team_pending_reviews = [];
$team_logs = [];
$tl_dept_missing = false;
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
            $tl_dept_missing = true;
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

    if ($teamUserIds && $log_table_ready) {
        $in = implode(',', array_map('intval', $teamUserIds));
        try {
            $tlog = $conn->query("
                SELECT hl.*, e.name as emp_name
                FROM hrms_learning_logs hl
                JOIN employees e ON e.id = hl.employee_id
                JOIN users u ON u.email = e.email
                WHERE u.id IN ({$in})
                ORDER BY hl.learned_on DESC, hl.created_at DESC
                LIMIT 50
            ");
            $team_logs = $tlog->fetchAll();
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
/* ── Header banner ──────────────────────────────────────────────────── */
.learn-banner {
    background:linear-gradient(135deg, rgba(124,58,237,.10), rgba(124,58,237,.02));
    border:1px solid rgba(124,58,237,.18);
    border-radius:18px;
    padding:22px 26px;
    margin-bottom:24px;
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    flex-wrap:wrap;
}
.learn-banner-icon {
    width:52px;height:52px;border-radius:14px;flex:0 0 auto;
    background:linear-gradient(135deg,#7c3aed,#a78bfa);
    display:flex;align-items:center;justify-content:center;
    font-size:1.6rem;color:#fff;box-shadow:0 6px 16px rgba(124,58,237,.35);
}
.learn-banner h5 { font-size:1.15rem; }
.learn-banner .sub { font-size:12.5px;color:var(--text-muted);margin-top:2px; }

/* ── Stat cards ─────────────────────────────────────────────────────── */
.stat-card {
    background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:14px;
    padding:18px 20px;text-align:center;position:relative;overflow:hidden;
}
.stat-card::before {
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    background:var(--stat-accent,#7c3aed);
}
.stat-card .val { font-size:2.1rem;font-weight:800;color:var(--text-primary);line-height:1; }
.stat-card .lbl { font-size:11px;color:var(--text-muted);margin-top:6px;text-transform:uppercase;letter-spacing:.04em;font-weight:600; }

/* ── Tabs (segmented control) ──────────────────────────────────────── */
.learn-tabs { background:var(--body-bg);border:1px solid var(--card-bdr);border-radius:12px;padding:4px;display:inline-flex;gap:2px;margin-bottom:24px;flex-wrap:wrap; }
.learn-tab {
    padding:8px 16px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;
    border:none;background:none;color:var(--text-secondary);position:relative;
    display:inline-flex;align-items:center;transition:background .15s,color .15s;
}
.learn-tab:hover { background:rgba(124,58,237,.08); }
.learn-tab.active { background:#7c3aed;color:#fff;box-shadow:0 3px 10px rgba(124,58,237,.3); }

/* ── Section card shell ────────────────────────────────────────────── */
.learn-card { background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:16px;overflow:hidden; }
.learn-card-head { padding:18px 22px;border-bottom:1px solid var(--card-bdr);display:flex;align-items:center;justify-content:space-between;gap:10px; }
.learn-card-body { padding:20px 22px; }
.learn-card-title { font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;margin:0; }

/* ── Badges earned (pill) ──────────────────────────────────────────── */
.learn-badge-pill {
    display:inline-flex;align-items:center;gap:10px;
    padding:10px 16px;border-radius:14px;
    background:linear-gradient(135deg, rgba(124,58,237,.10), rgba(124,58,237,.03));
    border:1px solid rgba(124,58,237,.2);
}
.learn-badge-pill .bi-icon { font-size:1.5rem;line-height:1; }
.learn-badge-pill .name    { font-size:13px;font-weight:700;color:#7c3aed; }
.learn-badge-pill .date    { font-size:10.5px;color:var(--text-muted);margin-top:1px; }

/* ── Status pills ───────────────────────────────────────────────────── */
.status-pill { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap; }
.status-pill.pursuing  { background:#fef9c3;color:#854d0e; }
.status-pill.completed { background:#dcfce7;color:#166534; }
.status-pill.dropped   { background:#f1f5f9;color:#64748b; }

/* ── Course cards (learning log entries) ───────────────────────────── */
.course-card {
    background:var(--body-bg);border:1px solid var(--card-bdr);border-radius:14px;
    padding:16px 18px;margin-bottom:12px;transition:border-color .15s;
}
.course-card:hover { border-color:rgba(124,58,237,.35); }
.course-card:last-child { margin-bottom:0; }
.course-card-top { display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap; }
.course-title { font-size:14px;font-weight:700;color:var(--text-primary); }
.course-meta { font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px; }
.course-progress-track { height:7px;border-radius:6px;background:var(--card-bdr);overflow:hidden;max-width:260px;margin-top:10px; }
.course-progress-fill { height:100%;border-radius:6px;background:linear-gradient(90deg,#7c3aed,#a78bfa);transition:width .3s; }
.course-progress-label { font-size:11px;color:var(--text-muted);margin-top:4px;font-weight:600; }
.course-notes { font-size:12.5px;color:var(--text-muted);margin-top:10px;padding:10px 12px;background:var(--card-bg);border-radius:10px;border:1px solid var(--card-bdr); }
.course-actions { display:flex;gap:6px;flex-wrap:wrap; }
.course-actions .btn { font-size:11px;border-radius:8px;padding:5px 11px;white-space:nowrap; }
.course-proof { font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px; }

/* ── History timeline ───────────────────────────────────────────────── */
.course-history summary { font-size:11.5px;color:var(--text-muted);cursor:pointer;font-weight:600;list-style:none;display:inline-flex;align-items:center;gap:4px;margin-top:10px; }
.course-history summary::-webkit-details-marker { display:none; }
.course-history summary::before { content:'▸';transition:transform .15s; }
.course-history[open] summary::before { transform:rotate(90deg); }
.timeline { margin-top:10px;padding-left:14px;border-left:2px solid var(--card-bdr); }
.timeline-item { position:relative;padding:0 0 12px 16px;font-size:12px;color:var(--text-muted); }
.timeline-item:last-child { padding-bottom:0; }
.timeline-item::before { content:'';position:absolute;left:-19px;top:4px;width:8px;height:8px;border-radius:50%;background:#7c3aed; }
.timeline-item .t-date { font-weight:700;color:var(--text-primary); }

/* ── Empty state ────────────────────────────────────────────────────── */
.learn-empty { text-align:center;padding:36px 20px;color:var(--text-muted); }
.learn-empty i { font-size:2.2rem;opacity:.25;display:block;margin-bottom:10px; }
.learn-empty p { font-size:13px;margin:0; }
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

<?php if (!$log_table_ready && has_role('SUPER_ADMIN')): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div>
        <strong>Learning log table not found.</strong>
        Run the migration to let people log what they've learned:
        <a href="migrate_learning_log.php" class="btn btn-sm btn-warning ms-2" style="border-radius:6px;">Run Migration →</a>
    </div>
</div>
<?php endif; ?>

<!-- Header -->
<div class="learn-banner">
    <div class="d-flex align-items-center gap-3">
        <div class="learn-banner-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div>
            <h5 class="mb-0 fw-bold">Learning</h5>
            <div class="sub">Track learning tasks, course progress, and earned badges</div>
        </div>
    </div>
    <?php if ($my_emp_id && $log_table_ready): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLearningLogModal" style="border-radius:10px;font-weight:600;font-size:13px;">
        <i class="bi bi-plus-lg me-1"></i>Update Your Course
    </button>
    <?php endif; ?>
</div>

<?php if ($my_emp_id && $log_table_ready): ?>
<!-- Add Learning Log modal -->
<div class="modal fade" id="addLearningLogModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="add_learning_log">
            <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0">Update Your Course</h5>
                    <p class="text-muted small mb-0">A quick note for yourself — no approval needed.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Course / Topic</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Figma Auto-Layout Course" required maxlength="200">
                </div>
                <?php if ($log_status_ready): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" id="lg-status" class="form-select" onchange="document.querySelectorAll('.lg-completed-only').forEach(function(el){ el.style.display = document.getElementById('lg-status').value==='completed' ? '' : 'none'; })">
                        <option value="pursuing">Pursuing</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Any detail, link, or takeaway"></textarea>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Started On</label>
                        <input type="date" name="learned_on" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <?php if ($log_status_ready): ?>
                    <div class="col-6 lg-completed-only" id="lg-completed-wrap" style="display:none;">
                        <label class="form-label small fw-semibold">Completed On</label>
                        <input type="date" name="completed_on" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($log_progress_ready): ?>
                <div class="mb-2 lg-completed-only" style="display:none;">
                    <label class="form-label small fw-semibold">Proof of completion (optional)</label>
                    <input type="url" name="proof_url" class="form-control" placeholder="Paste a certificate/completion URL">
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_admin && !$is_tl): ?>
<!-- ══════════════════════════════════════════════════════ EMPLOYEE VIEW -->

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="stat-card" style="--stat-accent:#7c3aed;">
            <div class="val" style="color:#7c3aed;"><?= count($my_badges) ?></div>
            <div class="lbl">Badges Earned</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card" style="--stat-accent:#16a34a;">
            <div class="val" style="color:#16a34a;"><?= count(array_filter($my_learning_tasks, fn($t) => $t['status']==='DONE')) ?></div>
            <div class="lbl">Completed</div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card" style="--stat-accent:#f59e0b;">
            <div class="val" style="color:#f59e0b;"><?= count(array_filter($my_learning_tasks, fn($t) => $t['status']!=='DONE')) ?></div>
            <div class="lbl">In Progress</div>
        </div>
    </div>
</div>

<!-- My Badges -->
<?php if ($my_badges): ?>
<div class="learn-card mb-4">
    <div class="learn-card-body">
        <h6 class="learn-card-title mb-3" style="color:#7c3aed;"><i class="bi bi-trophy-fill me-2"></i>My Badges</h6>
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
<div class="learn-card">
    <div class="learn-card-body">
        <h6 class="learn-card-title mb-3"><i class="bi bi-journal-check me-2"></i>My Learning Tasks</h6>
        <?php if (!$my_learning_tasks): ?>
        <div class="learn-empty">
            <i class="bi bi-mortarboard"></i>
            <p>No learning tasks assigned yet.</p>
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

<!-- My Learning Log (self-logged) -->
<?php if ($log_table_ready): ?>
<div class="learn-card mt-4">
    <div class="learn-card-body">
        <h6 class="learn-card-title mb-3"><i class="bi bi-pencil-square me-2"></i>My Learning Log</h6>
        <?php if (!$my_logs): ?>
        <div class="learn-empty">
            <i class="bi bi-journal-plus"></i>
            <p>Nothing logged yet — hit "Update Your Course" above.</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($my_logs as $log): _render_my_learning_log_item($log, $log_status_ready, $log_progress_ready); endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════ TL / ADMIN VIEW -->

<!-- Tabs -->
<div class="learn-tabs">
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
<?php if ($tl_dept_missing): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div>
        <strong>You're not linked to a team yet.</strong>
        You have the Team Lead role, but your Employee record isn't marked as Team Lead for any department, so the system doesn't know whose team is yours.
        Go to <a href="employees.php">Employees</a> → open your own record → check "Is Team Lead" and pick your department.
    </div>
</div>
<?php endif; ?>
<?php
$done_count    = count(array_filter($team_learning, fn($t) => $t['status']==='DONE'));
$pending_count = count(array_filter($team_learning, fn($t) => $t['status']!=='DONE'));
$badge_count   = $is_admin ? count($all_badge_awards) : count(array_filter($team_learning, fn($t) => $t['tl_approved']==='1'));
?>
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card" style="--stat-accent:#16a34a;"><div class="val" style="color:#16a34a;"><?= $done_count ?></div><div class="lbl">Completed</div></div></div>
    <div class="col-4"><div class="stat-card" style="--stat-accent:#f59e0b;"><div class="val" style="color:#f59e0b;"><?= $pending_count ?></div><div class="lbl">In Progress</div></div></div>
    <div class="col-4"><div class="stat-card" style="--stat-accent:#7c3aed;"><div class="val" style="color:#7c3aed;"><?= $badge_count ?></div><div class="lbl">Badges Awarded</div></div></div>
</div>

<div class="learn-card">
    <div class="learn-card-body">
        <h6 class="learn-card-title mb-3"><i class="bi bi-journal-check me-2"></i><?= $is_admin ? 'All Learning Tasks' : 'Team Learning Tasks' ?></h6>
        <?php if (!$team_learning): ?>
        <div class="learn-empty"><i class="bi bi-mortarboard"></i><p>No learning tasks found.</p></div>
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

<?php if ($log_table_ready): ?>
<div class="learn-card mt-4">
    <div class="learn-card-body">
        <h6 class="learn-card-title mb-3"><i class="bi bi-pencil-square me-2"></i>Team Learning Log</h6>
        <?php if (!$team_logs): ?>
        <div class="learn-empty"><i class="bi bi-journal-plus"></i><p>Nobody's logged anything yet.</p></div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($team_logs as $log): ?>
            <div class="list-group-item px-0" style="border-color:var(--card-bdr);">
                <div class="small"><span class="fw-semibold"><?= sanitize($log['emp_name']) ?></span> — <?= sanitize($log['title']) ?></div>
                <?php if ($log_status_ready): ?>
                <div class="small text-muted mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <?php $tstatus = $log['status'] ?? 'completed'; ?>
                    <?php if ($tstatus === 'pursuing'): ?>
                    <span class="badge" style="background:#fef9c3;color:#854d0e;">🟡 Pursuing</span>
                    <span>Started <?= date('d M Y', strtotime($log['learned_on'])) ?><?= isset($log['progress_pct']) ? ' — ' . (int)$log['progress_pct'] . '%' : '' ?></span>
                    <?php elseif ($tstatus === 'dropped'): ?>
                    <span class="badge" style="background:#f1f5f9;color:#64748b;">⛔ Dropped</span>
                    <span>Started <?= date('d M Y', strtotime($log['learned_on'])) ?></span>
                    <?php else: ?>
                    <span class="badge" style="background:#dcfce7;color:#166534;">✅ Completed</span>
                    <span><?= date('d M Y', strtotime($log['learned_on'])) ?> → <?= $log['completed_on'] ? date('d M Y', strtotime($log['completed_on'])) : '—' ?></span>
                    <?php if (!empty($log['proof_url'])): ?>
                    <a href="<?= sanitize($log['proof_url']) ?>" target="_blank" rel="noopener noreferrer">🔗 Proof</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="small text-muted mt-1"><?= date('d M Y', strtotime($log['learned_on'])) ?></div>
                <?php endif; ?>
                <?php if ($log['notes']): ?>
                <div class="small text-muted mt-1"><?= nl2br(sanitize($log['notes'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

<!-- ── TAB: Pending Reviews ───────────────────────────────────────────── -->
<?php if ($team_pending_reviews): ?>
<div id="tab-reviews" style="display:none;">
    <div class="learn-card">
        <div class="learn-card-body">
            <h6 class="learn-card-title mb-3" style="color:#b45309;"><i class="bi bi-clipboard-check me-2"></i>Quiz Answers Awaiting Your Review</h6>
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
            <div class="learn-card">
                <div class="learn-card-body">
                    <h6 class="learn-card-title mb-3"><i class="bi bi-bar-chart me-2"></i>Badge Completion Rates</h6>
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
            <div class="learn-card">
                <div class="learn-card-body">
                    <h6 class="learn-card-title mb-3"><i class="bi bi-trophy me-2"></i>Recent Badge Awards</h6>
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
    <div class="learn-card mb-4">
        <div class="learn-card-body">
            <h6 class="learn-card-title mb-3" style="color:#7c3aed;"><i class="bi bi-trophy-fill me-2"></i>My Badges</h6>
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
    <div class="learn-card">
        <div class="learn-card-body">
            <h6 class="learn-card-title mb-3"><i class="bi bi-journal-check me-2"></i>My Learning Tasks</h6>
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
    <div class="learn-empty"><i class="bi bi-mortarboard"></i><p>No learning tasks assigned to you.</p></div>
    <?php endif; ?>

    <?php if ($log_table_ready): ?>
    <div class="learn-card mt-4">
        <div class="learn-card-body">
            <h6 class="learn-card-title mb-3"><i class="bi bi-pencil-square me-2"></i>My Learning Log</h6>
            <?php if (!$my_logs): ?>
            <div class="learn-empty">
                <i class="bi bi-journal-plus"></i>
                <p>Nothing logged yet — hit "Update Your Course" above.</p>
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
            <?php foreach ($my_logs as $log): _render_my_learning_log_item($log, $log_status_ready, $log_progress_ready); endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
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

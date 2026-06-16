<?php
require_once 'config.php';
require_once 'task_timer_helper.php';
require_once 'digiops_sync_helper.php';
require_login();
$page      = 'tasks';
$pageTitle = 'Task Detail';
$u         = current_user();
$uid       = $u['id'];
$role      = $u['role'];
$hr_view   = $role === 'HR_ADMIN';

// Learning module columns — auto-add if migration hasn't run yet
try { $conn->exec("ALTER TABLE tasks ADD COLUMN is_learning_task  TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE tasks ADD COLUMN learning_badge_id INT NULL"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE tasks ADD COLUMN learning_material TEXT NULL"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE tasks ADD COLUMN learning_pass_pct TINYINT NOT NULL DEFAULT 80"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE tasks ADD COLUMN quiz_required     TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}

function log_task_activity($conn, $task_id, $user_id, $action, $detail = '') {
    $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
         ->execute([$task_id, $user_id, $action, $detail]);
}

function _fire_task_trigger(string $eventType, int $taskId, int $actorId): void {
    global $conn;
    try {
        $te = __DIR__ . '/trigger_engine.php';
        if (file_exists($te)) {
            if (!function_exists('fireTriggersByTaskEvent')) require_once $te;
            if ($eventType === 'task_status') {
                // status change — caller passes new status via $taskId slot reuse not ideal,
                // so we read it from the task
                $row = $conn->query("SELECT status FROM tasks WHERE id=$taskId LIMIT 1")->fetch();
                if ($row) fireTriggersByStatusChange($conn, $taskId, $row['status'], $actorId);
            } else {
                fireTriggersByTaskEvent($conn, $eventType, $taskId, $actorId);
            }
        }
    } catch (Exception $e) {}
}

function _fire_status_trigger(int $taskId, string $newStatus, int $actorId): void {
    global $conn;
    try {
        $te = __DIR__ . '/trigger_engine.php';
        if (file_exists($te)) {
            if (!function_exists('fireTriggersByStatusChange')) require_once $te;
            fireTriggersByStatusChange($conn, $taskId, $newStatus, $actorId);
            if ($newStatus === 'DONE') {
                fireTriggersByTaskEvent($conn, 'task_completed', $taskId, $actorId);
            }
        }
    } catch (Exception $e) {}
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: tasks.php"); exit; }

// Fetch task
$stmt = $conn->prepare("SELECT t.*,
    u1.name as assignee_name, u1.email as assignee_email,
    u2.name as creator_name,
    u3.name as tl_name,
    p.name as project_name,
    d.name as from_dept
    FROM tasks t
    LEFT JOIN users u1 ON u1.id=t.assigned_to
    LEFT JOIN users u2 ON u2.id=t.assigned_by
    LEFT JOIN users u3 ON u3.id=t.to_tl_id
    LEFT JOIN projects p ON p.id=t.project_id
    LEFT JOIN departments d ON d.id=t.from_dept_id
    WHERE t.id=?");
$stmt->execute([$id]);
$task = $stmt->fetch();
if (!$task) { set_flash('danger','Task not found.'); header("Location: tasks.php"); exit; }

// Access check: employee can only see own tasks
if ($role === 'EMPLOYEE' && $task['assigned_to'] != $uid && $task['assigned_by'] != $uid) {
    set_flash('danger','Access denied.'); header("Location: tasks.php"); exit;
}

$is_internaldigi_flow = $task['description'] && strpos($task['description'], '[internaldigi workflow]') !== false;

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $can_act = $task['assigned_to']==$uid || $task['assigned_by']==$uid
             || in_array($role, ['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']);
    $is_tl_local = in_array($role, ['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']);

    // Update HRMS task + task_approvals after DigiOps webhook approval
    if ($_POST['action'] === 'tl_approve_local' && $is_tl_local) {
        $tid = (int)($_POST['task_id'] ?? $id);
        $conn->prepare("UPDATE tasks SET status='DONE', updated_at=NOW() WHERE id=? AND status='REVIEW'")->execute([$tid]);
        $affected = $conn->prepare("UPDATE task_approvals SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE task_id=? AND status='pending'");
        $affected->execute([$uid, $tid]);
        if ($conn->lastInsertId() === '0' && $affected->rowCount() === 0) {
            // No pending row — insert approved directly
            $conn->prepare("INSERT IGNORE INTO task_approvals (task_id, status, reviewed_by, reviewed_at) VALUES (?, 'approved', ?, NOW())")
                 ->execute([$tid, $uid]);
        }
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$tid, $uid, "✅ Approved by " . ($u['name'] ?? 'TL')]);
        log_task_activity($conn, $tid, $uid, 'APPROVED', 'Approved via TL review');
        if (!function_exists('advanceWorkflowRun')) require_once __DIR__ . '/trigger_engine.php';
        advanceWorkflowRun($conn, $tid);
        exit;
    }

    // Update HRMS task + task_approvals after DigiOps webhook rejection
    if ($_POST['action'] === 'tl_reject_local' && $is_tl_local) {
        $tid   = (int)($_POST['task_id'] ?? $id);
        $notes = trim($_POST['notes'] ?? '');
        $conn->prepare("UPDATE tasks SET status='REWORK', updated_at=NOW() WHERE id=? AND status='REVIEW'")->execute([$tid]);
        $conn->prepare("UPDATE task_approvals SET status='rework', reviewed_by=?, reviewed_at=NOW(), note=? WHERE task_id=? AND status='pending'")
             ->execute([$uid, $notes, $tid]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$tid, $uid, "↩ Sent back: " . $notes]);
        log_task_activity($conn, $tid, $uid, 'REJECTED', substr($notes, 0, 120));
        exit;
    }

    if ($_POST['action'] === 'update_status' && !$hr_view && $can_act) {
        $allowed = ['TODO','IN_PROGRESS','REVIEW','DONE','REWORK'];
        if (in_array($_POST['new_status'], $allowed) && $task['status'] !== 'BLOCKED') {
            $old_status = $task['status'];

            // Block DONE on learning tasks until quiz is approved by TL
            if ($_POST['new_status'] === 'DONE' && !empty($task['is_learning_task']) && !empty($task['quiz_required']) && !$is_tl_local) {
                if (!$employee_passed) {
                    set_flash('danger', 'You must pass the quiz and get TL approval before marking this task done.');
                    header("Location: task_detail.php?id=$id"); exit;
                }
            }

            // Block DONE if task needs TL approval and actor is not TL/Admin
            if ($_POST['new_status'] === 'DONE' && !empty($task['needs_approval']) && !$is_tl_local) {
                set_flash('danger', 'This task requires TL approval before it can be marked Done. Use "Re-submit for Approval".');
                header("Location: task_detail.php?id=$id"); exit;
            }

            // Block DONE if the linked DigiOps task requires a fill-link asset submission
            if ($_POST['new_status'] === 'DONE') {
                $ops = digiops_db();
                if ($ops) {
                    try {
                        $opsT = $ops->prepare("SELECT format, status FROM brand_tasks WHERE hrms_task_id = ? LIMIT 1");
                        $opsT->execute([$id]);
                        $opsRow = $opsT->fetch();
                        if ($opsRow && $opsRow['format'] !== 'task' && $opsRow['status'] !== 'done') {
                            set_flash('danger', 'This task requires a document/asset submission via the client link before it can be marked Done.');
                            header("Location: task_detail.php?id=$id"); exit;
                        }
                    } catch (Exception $e) { /* allow if DigiOps unreachable */ }
                }
            }
            
            // Auto-manage timer based on status change
            if ($_POST['new_status'] === 'IN_PROGRESS' && $old_status !== 'IN_PROGRESS') {
                // Starting work: start the timer
                start_task_timer($conn, $id, $uid);
            } elseif ($old_status === 'IN_PROGRESS' && $_POST['new_status'] !== 'IN_PROGRESS') {
                // Stopping work: stop the timer
                stop_task_timer($conn, $id, $uid);
            }
            
            $conn->prepare("UPDATE tasks SET status=? WHERE id=?")->execute([$_POST['new_status'], $id]);
            $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                 ->execute([$id, $uid, "Stage moved: {$old_status} → " . $_POST['new_status']]);
            log_task_activity($conn, $id, $uid, 'STATUS_CHANGED', "{$old_status} → ".$_POST['new_status']);
            _fire_status_trigger($id, $_POST['new_status'], $uid);
            if ($_POST['new_status'] === 'DONE') {
                if (!function_exists('advanceWorkflowRun')) require_once __DIR__ . '/trigger_engine.php';
                advanceWorkflowRun($conn, $id);
            }

            // Sync to DigiOps
            _digiops_task_sync($conn, $id, $_POST['new_status']);
        }
        header("Location: task_detail.php?id=$id"); exit;
    }
    if ($_POST['action'] === 'log_time' && !$hr_view && $can_act) {
        $conn->prepare("INSERT INTO task_time_logs (task_id,user_id,hours,note,logged_at) VALUES (?,?,?,?,?)")
             ->execute([$id, $uid, $_POST['hours'], trim($_POST['note']), $_POST['logged_at']]);
        log_task_activity($conn, $id, $uid, 'TIME_LOGGED', $_POST['hours'].'h — '.(trim($_POST['note']) ?: 'no note'));
        set_flash('success', 'Time logged successfully.');
        header("Location: task_detail.php?id=$id"); exit;
    }
    if ($_POST['action'] === 'add_comment' && trim($_POST['comment'])) {
        if ($can_act || $hr_view) {
            $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                 ->execute([$id, $uid, trim($_POST['comment'])]);
            log_task_activity($conn, $id, $uid, 'COMMENTED', substr(trim($_POST['comment']), 0, 80));
        }
        header("Location: task_detail.php?id=$id"); exit;
    }

    // Submit work to DigiOps for review
    if ($_POST['action'] === 'submit_work_to_ops' && !$hr_view && $can_act) {
        $note = trim($_POST['submission_note'] ?? '');
        if ($note === '') {
            set_flash('danger', 'Please describe the work submitted.');
            header("Location: task_detail.php?id=$id"); exit;
        }
        $conn->prepare("UPDATE tasks SET status='REVIEW' WHERE id=?")->execute([$id]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$id, $uid, "Submitted for review: $note"]);
        log_task_activity($conn, $id, $uid, 'SUBMITTED', substr($note, 0, 120));

        $ops = digiops_db();
        if ($ops) {
            try {
                $st = $ops->prepare('SELECT id, brand_id FROM brand_tasks WHERE hrms_task_id = ? LIMIT 1');
                $st->execute([$id]);
                $opsTask = $st->fetch();
                if ($opsTask) {
                    // Sync status to review so DigiOps Approvals tab picks it up
                    $ops->prepare("UPDATE brand_tasks SET status='review' WHERE id=?")->execute([$opsTask['id']]);
                    $msg = "✅ Work submitted by " . ($u['name'] ?? 'employee') . ": " . $note;
                    $ops->prepare(
                        'INSERT INTO task_comments (task_id, user_id, user_name, comment, source) VALUES (?,?,?,?,?)'
                    )->execute([$opsTask['id'], null, $u['name'] ?? 'HRMS user', $msg, 'hrms']);
                }
            } catch (Exception $e) { /* swallow — local update already succeeded */ }
        }
        set_flash('success', 'Work submitted. Awaiting DigiOps manager review.');
        header("Location: task_detail.php?id=$id"); exit;
    }

    // Block a task — direct person request
    if ($_POST['action'] === 'block_task' && !$hr_view && $can_act) {
        $req_uid = (int)($_POST['requested_user_id'] ?? 0) ?: null;
        $desc    = trim($_POST['description'] ?? '');

        if (!$req_uid || $desc === '') {
            set_flash('danger', 'Please select a person and describe what you need.');
            header("Location: task_detail.php?id=$id"); exit;
        }
        if (in_array($task['status'], ['BLOCKED','DONE'])) {
            set_flash('danger', 'Task cannot be blocked in its current state.');
            header("Location: task_detail.php?id=$id"); exit;
        }

        // Get the target person's name and role for display/compat
        $req_person = $conn->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $req_person->execute([$req_uid]);
        $req_person = $req_person->fetch();
        if (!$req_person) {
            set_flash('danger', 'Selected person not found.');
            header("Location: task_detail.php?id=$id"); exit;
        }

        $conn->prepare("UPDATE tasks SET status='BLOCKED', blocked_reason=? WHERE id=?")
             ->execute([$desc, $id]);
        $conn->prepare("INSERT INTO task_block_requests (task_id,requested_by,requested_role,requested_user_id,request_type,description) VALUES (?,?,?,?,?,?)")
             ->execute([$id, $uid, $req_person['role'], $req_uid, 'text', $desc]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$id, $uid, "🚫 Blocked — waiting on " . $req_person['name'] . ": {$desc}"]);
        log_task_activity($conn, $id, $uid, 'BLOCKED', mb_substr($desc, 0, 120));

        // Notify the specific person — link directly to the task
        $notif_title = "Action needed: " . mb_substr($task['title'], 0, 60);
        $notif_body  = ($u['name'] ?? 'Someone') . " is waiting on you: {$desc}";
        hrms_notify($conn, (int)$req_uid, 'block_request', $notif_title, $notif_body, "task_detail.php?id={$id}");
        // Sync to DigiOps — write the actual description as blocked_reason
        if ($is_internaldigi_flow) {
            $ops = digiops_db();
            if ($ops) {
                try {
                    $st = $ops->prepare('SELECT id FROM brand_tasks WHERE hrms_task_id = ? LIMIT 1');
                    $st->execute([$id]);
                    $opsTask = $st->fetch();
                    if ($opsTask) {
                        $ops->prepare("UPDATE brand_tasks SET status='blocked', blocked_reason=? WHERE id=?")
                            ->execute([$desc, $opsTask['id']]);
                        $ops->prepare('INSERT INTO task_comments (task_id,user_id,user_name,comment,source) VALUES (?,?,?,?,?)')
                            ->execute([$opsTask['id'], null, $u['name'] ?? 'HRMS', "🚫 Blocked — waiting on " . $req_person['name'] . ": {$desc}", 'hrms']);
                    }
                } catch (Exception $e) { /* swallow */ }
            }
        }
        set_flash('warning', 'Task blocked. ' . $req_person['name'] . ' has been notified.');
        header("Location: task_detail.php?id=$id"); exit;
    }

    // Unblock a task manually (by original blocker or managers)
    if ($_POST['action'] === 'unblock_task' && !$hr_view && $can_act) {
        if ($task['status'] !== 'BLOCKED') {
            header("Location: task_detail.php?id=$id"); exit;
        }
        $conn->prepare("UPDATE tasks SET status='TODO', blocked_reason=NULL, unblocked_at=NOW() WHERE id=?")->execute([$id]);
        // Cancel any pending block requests
        $conn->prepare("UPDATE task_block_requests SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE task_id=? AND status='pending'")
             ->execute([$uid, $id]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$id, $uid, "✅ Unblocked by " . ($u['name'] ?? 'user')]);
        log_task_activity($conn, $id, $uid, 'UNBLOCKED', 'Manually unblocked');
        // Notify original task assignee/creator
        foreach (array_unique([$task['assigned_to'], $task['assigned_by']]) as $tid2) {
            if ($tid2 && $tid2 != $uid) {
                hrms_notify($conn, (int)$tid2, 'unblocked', 'Task unblocked: ' . mb_substr($task['title'],0,60), ($u['name'] ?? 'Someone') . ' unblocked this task — it\'s back in your To Do.', "task_detail.php?id={$id}");
            }
        }
        if ($is_internaldigi_flow) {
            $ops = digiops_db();
            if ($ops) {
                try {
                    $st = $ops->prepare('SELECT id FROM brand_tasks WHERE hrms_task_id = ? LIMIT 1');
                    $st->execute([$id]);
                    $opsTask = $st->fetch();
                    if ($opsTask) {
                        $ops->prepare("UPDATE brand_tasks SET status='todo', blocked_reason=NULL, unblocked_at=NOW() WHERE id=?")->execute([$opsTask['id']]);
                        $ops->prepare('INSERT INTO task_comments (task_id,user_id,user_name,comment,source) VALUES (?,?,?,?,?)')
                            ->execute([$opsTask['id'], null, $u['name'] ?? 'HRMS', "✅ Unblocked by " . ($u['name'] ?? 'HRMS user'), 'hrms']);
                    }
                } catch (Exception $e) { /* swallow */ }
            }
        }
        set_flash('success', 'Task unblocked — moved back to To Do.');
        header("Location: task_detail.php?id=$id"); exit;
    }

    // ── Quiz: submit answers ───────────────────────────────────────────────
    if ($_POST['action'] === 'submit_quiz' && $is_learning && $can_act) {
        if (!$my_emp_id) { set_flash('danger','Employee record not found.'); header("Location: task_detail.php?id=$id"); exit; }
        if ($quiz_pending_tl) { set_flash('warning','Your answers are awaiting TL review.'); header("Location: task_detail.php?id=$id"); exit; }

        $questions = $conn->prepare("SELECT * FROM hrms_task_quiz WHERE task_id=? ORDER BY sort_order");
        $questions->execute([$id]);
        $questions = $questions->fetchAll();
        $total   = count($questions);
        $correct = 0;
        $answers = [];
        foreach ($questions as $q) {
            $submitted      = (int)($_POST['q_' . $q['id']] ?? -1);
            $answers[$q['id']] = $submitted;
            if ($submitted === (int)$q['correct_idx']) $correct++;
        }
        $scorePct = $total > 0 ? (int)round(($correct / $total) * 100) : 100;
        $passMark = (int)($task['learning_pass_pct'] ?: 80);
        $passed   = $scorePct >= $passMark ? 1 : 0;

        $conn->prepare("INSERT INTO hrms_task_quiz_attempts (task_id, employee_id, answers_json, score_pct, passed) VALUES (?,?,?,?,?)")
             ->execute([$id, $my_emp_id, json_encode($answers), $scorePct, $passed]);

        if ($passed) {
            log_task_activity($conn, $id, $uid, 'QUIZ_SUBMITTED', "Score: {$scorePct}% — Awaiting TL review");
            set_flash('success', "Score: {$scorePct}% — Passed! Waiting for TL to review your answers.");
        } else {
            log_task_activity($conn, $id, $uid, 'QUIZ_FAILED', "Score: {$scorePct}% — needed {$passMark}%");
            set_flash('warning', "Score: {$scorePct}%. You need {$passMark}% to pass. Try again.");
        }
        header("Location: task_detail.php?id=$id"); exit;
    }

    // ── Quiz: TL approves / rejects answers ───────────────────────────────
    if ($_POST['action'] === 'quiz_review' && $is_tl_local) {
        $attempt_id = (int)($_POST['attempt_id'] ?? 0);
        $decision   = $_POST['decision'] ?? ''; // 'approve' or 'reject'
        $note       = trim($_POST['tl_note'] ?? '');

        if (!$attempt_id || !in_array($decision, ['approve','reject'])) {
            set_flash('danger','Invalid review action.'); header("Location: task_detail.php?id=$id"); exit;
        }

        $approved = $decision === 'approve' ? 1 : 0;
        $conn->prepare("UPDATE hrms_task_quiz_attempts SET tl_approved=?, tl_reviewed_by=?, tl_reviewed_at=NOW(), tl_note=? WHERE id=?")
             ->execute([$approved, $uid, $note, $attempt_id]);

        // Fetch attempt to get employee_id
        $atRow = $conn->prepare("SELECT * FROM hrms_task_quiz_attempts WHERE id=? LIMIT 1");
        $atRow->execute([$attempt_id]);
        $atRow = $atRow->fetch();
        $empId = $atRow ? (int)$atRow['employee_id'] : 0;

        if ($approved && $empId) {
            // Award badge
            if ($task['learning_badge_id']) {
                $conn->prepare("INSERT IGNORE INTO hrms_employee_badges (employee_id, badge_id, task_id) VALUES (?,?,?)")
                     ->execute([$empId, $task['learning_badge_id'], $id]);
            }
            // Award points
            if (!function_exists('pts_award')) require_once __DIR__ . '/points_helper.php';
            pts_award($conn, $empId, 'hrms_quiz_passed', (string)$id, 'hrms_learning', 'Quiz approved by TL');
            pts_award($conn, $empId, 'hrms_learning_completed', (string)$id, 'hrms_learning', 'Learning task completed');

            // Mark task done + advance workflow
            $conn->prepare("UPDATE tasks SET status='DONE', updated_at=NOW() WHERE id=?")->execute([$id]);
            log_task_activity($conn, $id, $uid, 'QUIZ_APPROVED', 'TL approved — badge awarded');
            if (!function_exists('advanceWorkflowRun')) require_once __DIR__ . '/trigger_engine.php';
            advanceWorkflowRun($conn, $id);

            // Notify employee
            $assigneeUserId = (int)($task['assigned_to'] ?? 0);
            if ($assigneeUserId) {
                $badgeName = $badge_info['icon'] ?? '🏅';
                hrms_notify($conn, $assigneeUserId, 'badge_awarded',
                    "Quiz approved! Badge earned {$badgeName}",
                    ($u['name'] ?? 'TL') . " approved your answers. You earned the badge!",
                    "task_detail.php?id={$id}");
            }
            set_flash('success', 'Answers approved — badge awarded to employee.');
        } else {
            log_task_activity($conn, $id, $uid, 'QUIZ_REJECTED', $note ?: 'TL rejected answers');
            // Notify employee to retry
            $assigneeUserId = (int)($task['assigned_to'] ?? 0);
            if ($assigneeUserId) {
                hrms_notify($conn, $assigneeUserId, 'task',
                    "Quiz answers rejected — please retry",
                    ($u['name'] ?? 'TL') . " rejected your answers" . ($note ? ": {$note}" : '.'),
                    "task_detail.php?id={$id}");
            }
            set_flash('warning', 'Answers rejected — employee notified to retry.');
        }
        header("Location: task_detail.php?id=$id"); exit;
    }

    // ── Quiz: TL adds a question ───────────────────────────────────────────
    if ($_POST['action'] === 'add_quiz_question' && $is_tl_local && $is_learning) {
        $q    = trim($_POST['question'] ?? '');
        $opts = array_values(array_filter(array_map('trim', explode("\n", $_POST['options'] ?? ''))));
        $correct = (int)($_POST['correct_idx'] ?? 0);
        if ($q && count($opts) >= 2) {
            $maxOrder = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM hrms_task_quiz WHERE task_id=?");
            $maxOrder->execute([$id]);
            $conn->prepare("INSERT INTO hrms_task_quiz (task_id, question, options, correct_idx, sort_order) VALUES (?,?,?,?,?)")
                 ->execute([$id, $q, json_encode($opts), $correct, (int)$maxOrder->fetchColumn()]);
            // Enable quiz if pass_pct was 0
            $conn->prepare("UPDATE tasks SET quiz_required=1 WHERE id=? AND quiz_required=0")->execute([$id]);
            set_flash('success','Question added.');
        } else {
            set_flash('danger','Question and at least 2 options are required.');
        }
        header("Location: task_detail.php?id=$id"); exit;
    }

    // ── Quiz: delete a question (TL only) ────────────────────────────────
    if ($_POST['action'] === 'delete_quiz_question' && $is_tl_local) {
        $qid = (int)($_POST['question_id'] ?? 0);
        $conn->prepare("DELETE FROM hrms_task_quiz WHERE id=? AND task_id=?")->execute([$qid, $id]);
        set_flash('success','Question removed.');
        header("Location: task_detail.php?id=$id"); exit;
    }
}

// Fetch time logs
$time_logs = $conn->prepare("SELECT tl.*, u.name as logger FROM task_time_logs tl JOIN users u ON u.id=tl.user_id WHERE tl.task_id=? ORDER BY tl.logged_at DESC");
$time_logs->execute([$id]);
$time_logs = $time_logs->fetchAll();
$total_logged = array_sum(array_column($time_logs, 'hours'));

// Fetch timer history and active timer
$active_timer = get_active_task_timer($conn, $id, $uid);
$total_timer_hours = get_total_task_timer_hours($conn, $id);
$elapsed_seconds = $active_timer ? (int)$active_timer['elapsed_seconds'] : 0;

// Get all timer records for this task
$timer_history = $conn->prepare("SELECT tt.*, u.name as user_name FROM task_timers tt JOIN users u ON u.id=tt.user_id WHERE tt.task_id=? ORDER BY tt.started_at DESC LIMIT 20");
$timer_history->execute([$id]);
$timer_history = $timer_history->fetchAll();

// Total time from both manual logs and timers
$total_time = $total_logged + $total_timer_hours + ($elapsed_seconds > 0 ? $elapsed_seconds / 3600 : 0);
$pct = $task['estimated_hours'] > 0 ? min(100, round(($total_time / $task['estimated_hours']) * 100)) : 0;

// Fetch comments
$comments = $conn->prepare("SELECT c.*, u.name as author FROM task_comments c JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.created_at ASC");
$comments->execute([$id]);
$comments = $comments->fetchAll();

// Fetch latest rework note (if task is in REWORK)
$rework_note = null;
if ($task['status'] === 'REWORK') {
    $rws = $conn->prepare("SELECT ta.note, u.name as reviewer_name, ta.reviewed_at FROM task_approvals ta LEFT JOIN users u ON u.id=ta.reviewed_by WHERE ta.task_id=? AND ta.status='rework' ORDER BY ta.reviewed_at DESC LIMIT 1");
    $rws->execute([$id]);
    $rework_note = $rws->fetch() ?: null;
}

$priority_color = ['LOW'=>'success','MEDIUM'=>'warning','HIGH'=>'danger','URGENT'=>'dark'];
$status_color   = ['TODO'=>'secondary','IN_PROGRESS'=>'primary','REVIEW'=>'warning','DONE'=>'success','BLOCKED'=>'danger'];
$status_flow    = ['TODO'=>'IN_PROGRESS','IN_PROGRESS'=>'REVIEW','REVIEW'=>'DONE','REWORK'=>'IN_PROGRESS'];
$next_status    = ($task['status'] !== 'BLOCKED') ? ($status_flow[$task['status']] ?? null) : null;
$next_label     = ['IN_PROGRESS'=>'Start Working','REVIEW'=>'Submit for Review','DONE'=>'Mark Done'];

$overdue = $task['due_date'] && $task['due_date'] < date('Y-m-d') && $task['status'] !== 'DONE';

// ── Learning context ───────────────────────────────────────────────────────
$is_learning    = !empty($task['is_learning_task']);
$quiz_questions = [];
$badge_info     = null;
$my_emp_id      = 0;
$latest_attempt = null;
$employee_passed = false;
$quiz_pending_tl = false;

if ($is_learning) { try {
    // Get employee record for this user
    $empR = $conn->prepare("SELECT e.id FROM employees e JOIN users u ON u.email=e.email WHERE u.id=? LIMIT 1");
    $empR->execute([$uid]);
    $my_emp_id = (int)($empR->fetchColumn() ?: 0);

    // Badge info
    if ($task['learning_badge_id']) {
        $bi = $conn->prepare("SELECT * FROM hrms_badges WHERE id=? LIMIT 1");
        $bi->execute([$task['learning_badge_id']]);
        $badge_info = $bi->fetch() ?: null;
    }

    // Quiz questions
    $qq = $conn->prepare("SELECT * FROM hrms_task_quiz WHERE task_id=? ORDER BY sort_order ASC");
    $qq->execute([$id]);
    $quiz_questions = $qq->fetchAll();

    // Latest attempt by this employee
    if ($my_emp_id) {
        $la = $conn->prepare("SELECT * FROM hrms_task_quiz_attempts WHERE task_id=? AND employee_id=? ORDER BY attempted_at DESC LIMIT 1");
        $la->execute([$id, $my_emp_id]);
        $latest_attempt = $la->fetch() ?: null;

        // Passed = auto-score passed AND tl approved (or no quiz)
        if ($latest_attempt) {
            $employee_passed  = $latest_attempt['passed'] && $latest_attempt['tl_approved'] === '1';
            $quiz_pending_tl  = $latest_attempt['passed'] && is_null($latest_attempt['tl_approved']);
        }
        if (!$quiz_questions) $employee_passed = true; // no quiz configured yet
    }
} catch (PDOException $e) { $is_learning = false; } }
$can_act_display = $task['assigned_to']==$uid || $task['assigned_by']==$uid
                 || in_array($role, ['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']);

// Fetch all users (for block request person picker — exclude HR_ADMIN and self)
$all_users_flat = [];
$ur = $conn->prepare("SELECT id, name FROM users WHERE role NOT IN ('HR_ADMIN') AND id != ? ORDER BY name");
$ur->execute([$uid]);
$all_users_flat = $ur->fetchAll();

// Fetch active block request for this task
$active_block = null;
if ($task['status'] === 'BLOCKED') {
    $abr = $conn->prepare("SELECT br.*, u.name as requester_name, u2.name as assignee_name
        FROM task_block_requests br
        JOIN users u ON u.id = br.requested_by
        LEFT JOIN users u2 ON u2.id = br.requested_user_id
        WHERE br.task_id = ? AND br.status = 'pending'
        ORDER BY br.created_at DESC LIMIT 1");
    $abr->execute([$id]);
    $active_block = $abr->fetch();
}

include 'header.php';
?>

<style>
.comment-bubble { background:#f1f5f9;border-radius:10px;padding:10px 14px;margin-bottom:10px; }
.comment-bubble.mine { background:#eff6ff; }
.time-row:hover { background:#f8fafc; }
</style>

<div class="mb-3">
    <a href="tasks.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i> Back to Tasks</a>
</div>

<div class="row g-4">

<!-- LEFT: Task Info -->
<div class="col-md-4">

    <!-- Task Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex gap-2 mb-3 flex-wrap">
                <span class="badge bg-<?= $priority_color[$task['priority']] ?> rounded-pill"><?= $task['priority'] ?></span>
                <span class="badge bg-<?= $status_color[$task['status']] ?? 'secondary' ?> rounded-pill"><?= str_replace('_',' ',$task['status']) ?></span>
                <?php if ($overdue): ?><span class="badge bg-danger rounded-pill">OVERDUE</span><?php endif; ?>
            </div>
            <h5 class="fw-bold mb-2"><?= sanitize($task['title']) ?></h5>
            <?php if ($task['description']): ?>
            <p class="text-muted small mb-3"><?= nl2br(sanitize($task['description'])) ?></p>
            <?php endif; ?>
            <?php if ($is_internaldigi_flow): ?>
            <div class="alert alert-primary py-2 small mb-3">
                <i class="bi bi-link-45deg me-1"></i>Synced from Digifyce workflow planning.
            </div>
            <?php endif; ?>

            <div class="d-flex flex-column gap-2 small">
                <?php if ($task['assignee_name']): ?>
                <div><i class="bi bi-person text-muted me-2"></i>Assigned to: <strong><?= sanitize($task['assignee_name']) ?></strong></div>
                <?php endif; ?>
                <?php if ($task['creator_name']): ?>
                <div><i class="bi bi-person-check text-muted me-2"></i>Created by: <?= sanitize($task['creator_name']) ?></div>
                <?php endif; ?>
                <?php if ($task['project_name']): ?>
                <div><i class="bi bi-folder text-muted me-2"></i><?= sanitize($task['project_name']) ?></div>
                <?php endif; ?>
                <?php if ($task['due_date']): ?>
                <div class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">
                    <i class="bi bi-calendar<?= $overdue ? '-x' : '' ?> text-muted me-2"></i>Due: <?= date('d M Y', strtotime($task['due_date'])) ?>
                </div>
                <?php endif; ?>
                <div><i class="bi bi-clock text-muted me-2"></i>Created: <?= date('d M Y', strtotime($task['created_at'])) ?></div>
            </div>

            <!-- Progress -->
            <div class="mt-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Time Progress</span>
                    <span><?= round($total_time, 2) ?>h / <?= $task['estimated_hours'] ?: '?' ?>h</span>
                </div>
                <div class="progress mb-1" style="height:8px;">
                    <div class="progress-bar bg-<?= $pct>=100?'success':'primary' ?>" style="width:<?= $pct ?>%;border-radius:4px;"></div>
                </div>
                <div class="text-muted" style="font-size:0.7rem;"><?= $pct ?>% of estimated time used</div>
                
                <!-- Active Timer Status -->
                <?php if ($active_timer): ?>
                <div class="mt-2 p-2 bg-light border-left-4" style="border-left:4px solid #0d6efd; border-radius:4px;">
                    <div class="small fw-semibold text-primary mb-1">
                        <i class="bi bi-play-circle-fill me-1"></i>Timer Active
                    </div>
                    <div class="small text-muted">Started: <?= date('g:i A', strtotime($active_timer['started_at'])) ?></div>
                    <div class="small text-muted">Elapsed: <span id="timer-display" class="text-primary fw-semibold"><?= format_duration($elapsed_seconds) ?></span></div>
                </div>
                <script>
                // Auto-update timer display every second
                (function() {
                    let elapsed = <?= $elapsed_seconds ?>;
                    setInterval(() => {
                        elapsed++;
                        let h = Math.floor(elapsed / 3600);
                        let m = Math.floor((elapsed % 3600) / 60);
                        let s = elapsed % 60;
                        document.getElementById('timer-display').textContent = 
                            String(h).padStart(2, '0') + ':' + 
                            String(m).padStart(2, '0') + ':' + 
                            String(s).padStart(2, '0');
                    }, 1000);
                })();
                </script>
                <?php endif; ?>
            </div>

            <!-- Blocked reason banner -->
            <?php if ($task['status'] === 'BLOCKED' && $task['blocked_reason']): ?>
            <div class="alert alert-danger mt-3 mb-0 py-2 small" style="border-radius:8px;">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Blocked</div>
                <div><?= nl2br(sanitize($task['blocked_reason'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Rework banner -->
            <?php if ($task['status'] === 'REWORK'): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small" style="border-radius:8px;">
                <div class="fw-semibold mb-1"><i class="bi bi-arrow-return-left me-1"></i>Sent back for rework
                    <?php if ($rework_note && $rework_note['reviewer_name']): ?>
                    <span class="fw-normal text-muted"> · <?= sanitize($rework_note['reviewer_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($rework_note && $rework_note['note']): ?>
                <div><?= nl2br(sanitize($rework_note['note'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Move Status -->
            <?php if (!$hr_view && $next_status && ($task['assigned_to']==$uid || in_array($role,['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']))): ?>
            <form method="POST" class="mt-3">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="<?= $next_status ?>">
                <button class="btn btn-primary w-100 btn-sm">
                    <i class="bi bi-arrow-right-circle me-1"></i>
                    <?= $task['status'] === 'REWORK' ? 'Resume Work' : $next_label[$next_status] ?>
                </button>
            </form>
            <?php endif; ?>

            <!-- Mark Done directly from REWORK — only when task does NOT need approval -->
            <?php
            $can_mark_done = !$hr_view && $task['status'] === 'REWORK'
                && ($task['assigned_to']==$uid || in_array($role,['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']))
                && empty($task['needs_approval']);
            ?>
            <?php if ($can_mark_done): ?>
            <form method="POST" class="mt-2">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="DONE">
                <button class="btn btn-success w-100 btn-sm">
                    <i class="bi bi-check-circle me-1"></i>Mark as Done
                </button>
            </form>
            <?php endif; ?>

            <!-- Re-submit for approval from REWORK (needs_approval tasks, assignee only) -->
            <?php if (!$hr_view && $task['status'] === 'REWORK' && $task['needs_approval'] && $task['assigned_to']==$uid && !in_array($role,['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER'])): ?>
            <form method="POST" class="mt-2">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="REVIEW">
                <button class="btn btn-outline-primary w-100 btn-sm">
                    <i class="bi bi-send me-1"></i>Re-submit for Approval
                </button>
            </form>
            <?php endif; ?>

            <?php if (!$hr_view && $task['status']==='DONE'): ?>
            <div class="alert alert-success mt-3 mb-0 py-2 small text-center"><i class="bi bi-check-circle me-1"></i> Task Completed</div>
            <?php endif; ?>

            <!-- Unblock button -->
            <?php if (!$hr_view && $task['status']==='BLOCKED' && $can_act_display): ?>
            <form method="POST" class="mt-3">
                <input type="hidden" name="action" value="unblock_task">
                <button class="btn btn-success w-100 btn-sm">
                    <i class="bi bi-shield-check me-1"></i>Unblock Task
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Learning Panel ───────────────────────────────────────────────── -->
    <?php if ($is_learning): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;border-left:4px solid #7c3aed !important;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3" style="color:#7c3aed;">
                <i class="bi bi-mortarboard-fill me-2"></i>Learning Task
                <?php if ($badge_info): ?>
                <span class="ms-2 fs-5" title="<?= sanitize($badge_info['name']) ?>"><?= sanitize($badge_info['icon']) ?></span>
                <?php endif; ?>
                <?php if ($employee_passed): ?>
                <span class="badge ms-2" style="background:#dcfce7;color:#166534;font-size:10px;">✅ Completed</span>
                <?php elseif ($quiz_pending_tl): ?>
                <span class="badge ms-2" style="background:#fef9c3;color:#854d0e;font-size:10px;">⏳ Awaiting TL Review</span>
                <?php endif; ?>
            </h6>

            <?php if ($task['learning_material']): ?>
            <div class="mb-3">
                <div class="fw-semibold small mb-1" style="color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;font-size:11px;">Learning Material</div>
                <?php if (filter_var($task['learning_material'], FILTER_VALIDATE_URL)): ?>
                <a href="<?= sanitize($task['learning_material']) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm" style="background:#7c3aed;color:#fff;border-radius:8px;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Resource
                </a>
                <?php else: ?>
                <div class="p-3 rounded small" style="background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);color:var(--text-secondary);">
                    <?= nl2br(sanitize($task['learning_material'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($badge_info): ?>
            <div class="mb-3 p-3 rounded d-flex align-items-center gap-3" style="background:rgba(124,58,237,.07);border:1px solid rgba(124,58,237,.2);">
                <span style="font-size:2rem;line-height:1;"><?= sanitize($badge_info['icon']) ?></span>
                <div>
                    <div class="small fw-bold" style="color:#7c3aed;"><?= sanitize($badge_info['name']) ?></div>
                    <div class="small" style="color:var(--text-muted);">Complete this task to earn this badge</div>
                </div>
                <?php if ($employee_passed): ?>
                <span class="ms-auto fs-4">🏆</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($task['quiz_required'] && $quiz_questions): ?>
            <?php if ($employee_passed): ?>
                <div class="alert py-2 small mb-0" style="background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;">
                    <i class="bi bi-patch-check-fill me-1"></i>Quiz passed and approved — badge earned!
                    <?php if ($latest_attempt): ?>
                    <span class="ms-2 opacity-75">Score: <?= $latest_attempt['score_pct'] ?>%</span>
                    <?php endif; ?>
                </div>
            <?php elseif ($quiz_pending_tl): ?>
                <div class="alert py-2 small mb-0" style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;border-radius:8px;">
                    <i class="bi bi-hourglass-split me-1"></i>Your answers (score: <?= $latest_attempt['score_pct'] ?>%) are awaiting TL review.
                </div>
            <?php else: ?>
                <?php if ($latest_attempt && !$latest_attempt['passed']): ?>
                <div class="alert py-2 small mb-2" style="background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;border-radius:8px;">
                    <i class="bi bi-x-circle me-1"></i>Last attempt: <?= $latest_attempt['score_pct'] ?>% — need <?= $task['learning_pass_pct'] ?>% to pass.
                    <?php if ($latest_attempt['tl_note']): ?>
                    TL note: <?= sanitize($latest_attempt['tl_note']) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="fw-semibold small mb-2" style="color:var(--text-secondary);">
                    Quiz — <?= count($quiz_questions) ?> question<?= count($quiz_questions)>1?'s':'' ?> · <?= $task['learning_pass_pct'] ?>% to pass
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="submit_quiz">
                    <?php foreach ($quiz_questions as $qi => $q): ?>
                    <?php $opts = json_decode($q['options'], true) ?? []; ?>
                    <div class="mb-3 p-3 rounded" style="background:var(--body-bg);border:1px solid var(--card-bdr);">
                        <div class="small fw-semibold mb-2"><?= ($qi+1) ?>. <?= sanitize($q['question']) ?></div>
                        <?php foreach ($opts as $oi => $opt): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="q_<?= $q['id'] ?>" value="<?= $oi ?>" id="q<?= $q['id'] ?>o<?= $oi ?>" required>
                            <label class="form-check-label small" for="q<?= $q['id'] ?>o<?= $oi ?>"><?= sanitize($opt) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <button class="btn w-100 fw-semibold" style="background:#7c3aed;color:#fff;border-radius:8px;">
                        <i class="bi bi-send me-1"></i>Submit Answers
                    </button>
                </form>
            <?php endif; ?>

            <?php elseif ($task['is_learning_task'] && !$task['quiz_required']): ?>
            <div class="small p-2 rounded" style="color:var(--text-muted);background:var(--body-bg);border:1px solid var(--card-bdr);">
                <i class="bi bi-info-circle me-1"></i>Read the material above. TL will add quiz questions and review your completion.
            </div>
            <?php endif; ?>

            <?php if ($task['quiz_required'] && !$employee_passed && !$quiz_pending_tl && $task['status'] !== 'DONE'): ?>
            <div class="mt-3 small p-2 rounded d-flex align-items-center gap-2" style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;">
                <i class="bi bi-lock-fill"></i> Complete and pass the quiz to unlock task completion.
            </div>
            <?php endif; ?>

            <?php if ($is_tl_local && $quiz_questions): ?>
            <!-- TL: Manage quiz questions -->
            <hr class="my-3">
            <details>
                <summary class="small fw-bold" style="cursor:pointer;color:var(--text-secondary);">
                    <i class="bi bi-gear me-1"></i>Manage Quiz Questions (<?= count($quiz_questions) ?>)
                </summary>
                <div class="mt-2">
                <?php foreach ($quiz_questions as $qi => $q): ?>
                <?php $opts = json_decode($q['options'], true) ?? []; ?>
                <div class="small p-2 mb-1 rounded d-flex justify-content-between align-items-start" style="background:var(--body-bg);border:1px solid var(--card-bdr);">
                    <div><strong><?= ($qi+1) ?>.</strong> <?= sanitize($q['question']) ?>
                        <div class="text-muted mt-1"><?php foreach ($opts as $oi => $o): ?><span class="me-2"><?= $oi===$q['correct_idx']?'✅':'○' ?> <?= sanitize($o) ?></span><?php endforeach; ?></div>
                    </div>
                    <form method="POST" class="ms-2 flex-shrink-0">
                        <input type="hidden" name="action" value="delete_quiz_question">
                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" style="font-size:11px;padding:2px 8px;" onclick="return confirm('Delete this question?')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
                </div>
                <form method="POST" class="mt-3 p-3 rounded" style="background:var(--body-bg);border:1px dashed var(--card-bdr);">
                    <input type="hidden" name="action" value="add_quiz_question">
                    <div class="mb-2">
                        <label class="small fw-semibold">Question</label>
                        <input type="text" name="question" class="form-control form-control-sm mt-1" required placeholder="e.g. What is Shopify's primary use case?">
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Options <span class="fw-normal text-muted">(one per line)</span></label>
                        <textarea name="options" class="form-control form-control-sm mt-1" rows="4"
                            placeholder="E-commerce platform&#10;Email marketing tool&#10;CRM software&#10;Social network" required></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Correct answer index <span class="fw-normal text-muted">(0 = first option)</span></label>
                        <input type="number" name="correct_idx" class="form-control form-control-sm mt-1" min="0" value="0" required style="width:80px;">
                    </div>
                    <button class="btn btn-sm btn-outline-success w-100"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
                </form>
            </details>
            <?php elseif ($is_tl_local && !$quiz_questions): ?>
            <hr class="my-3">
            <details>
                <summary class="small fw-bold" style="cursor:pointer;color:var(--text-secondary);">
                    <i class="bi bi-plus-circle me-1"></i>Add Quiz Questions
                </summary>
                <form method="POST" class="mt-3 p-3 rounded" style="background:var(--body-bg);border:1px dashed var(--card-bdr);">
                    <input type="hidden" name="action" value="add_quiz_question">
                    <div class="mb-2">
                        <label class="small fw-semibold">Question</label>
                        <input type="text" name="question" class="form-control form-control-sm mt-1" required>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Options <span class="fw-normal text-muted">(one per line)</span></label>
                        <textarea name="options" class="form-control form-control-sm mt-1" rows="4" required></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Correct answer index <span class="fw-normal text-muted">(0 = first)</span></label>
                        <input type="number" name="correct_idx" class="form-control form-control-sm mt-1" min="0" value="0" required style="width:80px;">
                    </div>
                    <button class="btn btn-sm btn-outline-success w-100"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
                </form>
            </details>
            <?php endif; ?>

        </div>
    </div>

    <?php if ($is_tl_local): ?>
    <!-- TL: Review pending quiz attempts for this task -->
    <?php
    $pending_attempts = $conn->prepare("
        SELECT qa.*, e.name as emp_name, u.name as user_name
        FROM hrms_task_quiz_attempts qa
        JOIN employees e ON e.id = qa.employee_id
        LEFT JOIN users u ON u.email = e.email
        WHERE qa.task_id=? AND qa.passed=1 AND qa.tl_approved IS NULL
        ORDER BY qa.attempted_at DESC
    ");
    $pending_attempts->execute([$id]);
    $pending_attempts = $pending_attempts->fetchAll();
    ?>
    <?php if ($pending_attempts): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;border-left:4px solid #f59e0b !important;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3" style="color:#b45309;"><i class="bi bi-clipboard-check me-2"></i>Quiz Answers Awaiting Review</h6>
            <?php foreach ($pending_attempts as $at): ?>
            <?php $answers = json_decode($at['answers_json'], true) ?? []; ?>
            <div class="mb-3 p-3 rounded" style="background:var(--body-bg);border:1px solid var(--card-bdr);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong class="small"><?= sanitize($at['emp_name'] ?? $at['user_name'] ?? 'Employee') ?></strong>
                    <span class="badge" style="background:#fef9c3;color:#854d0e;">Score: <?= $at['score_pct'] ?>%</span>
                </div>
                <?php foreach ($quiz_questions as $q): ?>
                <?php $opts = json_decode($q['options'], true) ?? []; $chosen = $answers[$q['id']] ?? -1; ?>
                <div class="small mb-2">
                    <div class="fw-semibold"><?= sanitize($q['question']) ?></div>
                    <?php foreach ($opts as $oi => $opt): ?>
                    <div class="ms-2 <?= $oi===$q['correct_idx']?'text-success fw-semibold':'' ?> <?= $oi===$chosen&&$oi!==$q['correct_idx']?'text-danger':'' ?>">
                        <?= $oi===$chosen?'→ ':'' ?><?= sanitize($opt) ?>
                        <?= $oi===$q['correct_idx']?' ✓':'' ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="quiz_review">
                    <input type="hidden" name="attempt_id" value="<?= $at['id'] ?>">
                    <div class="mb-2">
                        <input type="text" name="tl_note" class="form-control form-control-sm" placeholder="Optional note to employee...">
                    </div>
                    <div class="d-flex gap-2">
                        <button name="decision" value="approve" class="btn btn-sm btn-success flex-fill">
                            <i class="bi bi-check-lg me-1"></i>Approve & Award Badge
                        </button>
                        <button name="decision" value="reject" class="btn btn-sm btn-danger flex-fill"
                            onclick="return confirm('Reject these answers? Employee will need to retry.')">
                            <i class="bi bi-x-lg me-1"></i>Reject — Ask to Retry
                        </button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Log Time -->
    <?php if (!$hr_view && $task['status'] !== 'DONE'): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-stopwatch me-2"></i>Log Time</h6>
            <form method="POST">
                <input type="hidden" name="action" value="log_time">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Date</label>
                    <input type="date" name="logged_at" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Hours Worked</label>
                    <input type="number" name="hours" class="form-control form-control-sm" step="0.5" min="0.5" max="24" required placeholder="e.g. 2.5">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Note</label>
                    <input type="text" name="note" class="form-control form-control-sm" placeholder="What did you work on?">
                </div>
                <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-circle me-1"></i>Log Time</button>
            </form>
        </div>
    </div>

    <!-- DigiOps Submission (only for synced tasks) -->
    <?php if ($is_internaldigi_flow && in_array($task['status'], ['TODO','IN_PROGRESS']) && $can_act_display): ?>
    <div class="card border-warning shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-header bg-warning-subtle fw-semibold border-0" style="border-radius:14px 14px 0 0;">
            <i class="bi bi-send me-1"></i> DigiOps Work Submission
        </div>
        <div class="card-body p-3">
            <form method="POST">
                <input type="hidden" name="action" value="submit_work_to_ops">
                <label class="form-label small fw-semibold">Submit work for review</label>
                <textarea name="submission_note" class="form-control form-control-sm mb-2" rows="3"
                          placeholder="Describe what's done. Paste links to docs, designs, deployed URLs..." required></textarea>
                <button class="btn btn-success btn-sm w-100">
                    <i class="bi bi-check-circle me-1"></i> Submit for Review
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- TL / Manager: Approve or Send Back (when task is in REVIEW) -->
    <?php if ($task['status'] === 'REVIEW' && in_array($role, ['TEAM_LEAD','DEPT_MANAGER','SUPER_ADMIN'])): ?>
    <div class="card border-success shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-header bg-success-subtle fw-semibold border-0 text-success" style="border-radius:14px 14px 0 0;">
            <i class="bi bi-check-circle me-1"></i> Review Submitted Work
        </div>
        <div class="card-body p-3">
            <p class="small text-muted mb-3">This task has been submitted for your review. Approve it to mark it done in both HRMS and DigiOps, or send it back with feedback.</p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm flex-fill"
                    onclick="tlApproveTask(<?= $id ?>, this)">
                    <i class="bi bi-check-lg me-1"></i> Approve
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm flex-fill"
                    onclick="document.getElementById('tl-reject-wrap').style.display='block';this.style.display='none'">
                    <i class="bi bi-arrow-return-left me-1"></i> Send Back
                </button>
            </div>
            <div id="tl-reject-wrap" style="display:none;margin-top:12px">
                <textarea id="tl-reject-notes" class="form-control form-control-sm mb-2" rows="2"
                          placeholder="What needs to be changed? (required)"></textarea>
                <button class="btn btn-danger btn-sm w-100" onclick="tlRejectTask(<?= $id ?>, this)">
                    <i class="bi bi-x-circle me-1"></i> Send Back with Feedback
                </button>
            </div>
        </div>
    </div>
    <script>
    const DIGIOPS_WEBHOOK = <?= json_encode($_ENV['DIGIOPS_WEBHOOK_URL'] ?? 'https://digiops.digifyce.com/api/webhook_hrms.php') ?>;
    function tlApproveTask(taskId, btn) {
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving…';
        // 1. Push approval to DigiOps via webhook
        fetch(DIGIOPS_WEBHOOK, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                event: 'task_approved',
                hrms_task_id: taskId,
                hrms_user_id: <?= (int)$uid ?>,
                notes: '',
                secret: '<?= htmlspecialchars(getHrmsWebhookSecret()) ?>'
            })
        }).then(r=>r.json()).then(d=>{
            if (!d.ok) throw new Error(d.error||'DigiOps approval failed');
            // 2. Update HRMS task + task_approvals locally
            const fd = new FormData();
            fd.append('action', 'tl_approve_local');
            fd.append('task_id', taskId);
            return fetch('task_detail.php?id='+taskId, {method:'POST', body: fd});
        }).then(()=>{ location.reload(); })
        .catch(e=>{ showToast(e.message, 'error', 'Error'); btn.disabled=false; btn.innerHTML='<i class="bi bi-check-lg me-1"></i> Approve'; });
    }
    function tlRejectTask(taskId, btn) {
        const notes = document.getElementById('tl-reject-notes').value.trim();
        if (!notes) { showAlert('Please provide feedback before sending back.', 'warning', 'Feedback Required'); return; }
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
        // 1. Push rejection to DigiOps via webhook
        fetch(DIGIOPS_WEBHOOK, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                event: 'task_rejected',
                hrms_task_id: taskId,
                hrms_user_id: <?= (int)$uid ?>,
                notes: notes,
                secret: '<?= htmlspecialchars(getHrmsWebhookSecret()) ?>'
            })
        }).then(r=>r.json()).then(d=>{
            if (!d.ok) throw new Error(d.error||'DigiOps rejection failed');
            // 2. Update HRMS task + task_approvals locally
            const fd = new FormData();
            fd.append('action', 'tl_reject_local');
            fd.append('task_id', taskId);
            fd.append('notes', notes);
            return fetch('task_detail.php?id='+taskId, {method:'POST', body: fd});
        }).then(()=>{ location.reload(); })
        .catch(e=>{ showToast(e.message, 'error', 'Error'); btn.disabled=false; });
    }
    </script>
    <?php endif; ?>

    <!-- Block Task — structured request -->
    <?php if (!$hr_view && !in_array($task['status'], ['BLOCKED','DONE']) && $can_act_display): ?>
    <div class="card border-danger shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-header bg-danger-subtle fw-semibold border-0 text-danger" style="border-radius:14px 14px 0 0;cursor:pointer;"
             data-bs-toggle="collapse" data-bs-target="#blockForm">
            <i class="bi bi-slash-circle me-1"></i> Block This Task — Needs Input
            <i class="bi bi-chevron-down float-end" style="font-size:.8rem;margin-top:2px;"></i>
        </div>
        <div class="collapse" id="blockForm">
        <div class="card-body p-3">
            <form method="POST">
                <input type="hidden" name="action" value="block_task">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Who do you need? <span class="text-danger">*</span></label>
                    <input type="text" id="brPersonSearch" class="form-control form-control-sm mb-1"
                           placeholder="Type a name to search…" autocomplete="off"
                           oninput="brFilterUsers(this.value)">
                    <div id="brPersonList" style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;display:none;background:#fff;"></div>
                    <input type="hidden" name="requested_user_id" id="brPersonId">
                    <div id="brPersonChosen" style="display:none;margin-top:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.82rem;font-weight:600;color:#15803d;"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">What do you need from them? <span class="text-danger">*</span></label>
                    <textarea name="description" class="form-control form-control-sm" rows="3"
                              placeholder="Be specific — what exactly are you waiting on?" required></textarea>
                </div>
                <button class="btn btn-danger btn-sm w-100" onclick="return brValidate()">
                    <i class="bi bi-slash-circle me-1"></i> Block &amp; Notify Person
                </button>
            </form>
        </div>
        </div>
    </div>

    <script>
    const brAllUsers = <?= json_encode($all_users_flat) ?>;
    function brFilterUsers(q) {
        const list = document.getElementById('brPersonList');
        if (!q.trim()) { list.style.display = 'none'; return; }
        const matches = brAllUsers.filter(u => u.name.toLowerCase().includes(q.toLowerCase()));
        if (!matches.length) { list.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:#94a3b8;">No results</div>'; list.style.display = 'block'; return; }
        list.innerHTML = matches.map(u =>
            `<div onclick="brSelectUser(${u.id}, '${u.name.replace(/'/g,"\\'")}'); return false;"
                  style="padding:8px 12px;font-size:.85rem;cursor:pointer;border-bottom:1px solid #f1f5f9;"
                  onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">${u.name}</div>`
        ).join('');
        list.style.display = 'block';
    }
    function brSelectUser(id, name) {
        document.getElementById('brPersonId').value = id;
        document.getElementById('brPersonSearch').value = name;
        document.getElementById('brPersonList').style.display = 'none';
        const chosen = document.getElementById('brPersonChosen');
        chosen.textContent = '✓ ' + name;
        chosen.style.display = 'block';
    }
    function brValidate() {
        if (!document.getElementById('brPersonId').value) {
            showAlert('Please select a person from the list.', 'warning', 'Person Required');
            return false;
        }
        return true;
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#blockForm')) document.getElementById('brPersonList').style.display = 'none';
    });
    </script>
    <?php endif; ?>

    <!-- Active block request details (shown when task is blocked) -->
    <?php if ($task['status'] === 'BLOCKED' && $active_block): ?>
    <div class="card border-warning shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-header bg-warning-subtle fw-semibold border-0" style="border-radius:14px 14px 0 0;">
            <i class="bi bi-hourglass-split me-1 text-warning"></i> Waiting on Someone
        </div>
        <div class="card-body p-3 small">
            <div class="mb-1"><span class="text-muted">Blocked by:</span> <strong><?= sanitize($active_block['requester_name']) ?></strong></div>
            <div class="mb-2"><span class="text-muted">Waiting on:</span> <strong><?= sanitize($active_block['assignee_name'] ?? '—') ?></strong></div>
            <div class="p-2 rounded" style="background:#fef9c3;"><?= nl2br(sanitize($active_block['description'])) ?></div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Time Logs -->
    <?php if ($time_logs): ?>
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Time Log</h6>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($time_logs as $tl): ?>
                <tr class="time-row">
                    <td class="small"><?= date('d M', strtotime($tl['logged_at'])) ?></td>
                    <td class="small fw-semibold"><?= $tl['hours'] ?>h</td>
                    <td class="small text-muted"><?= sanitize($tl['logger']) ?></td>
                    <td class="small text-muted"><?= sanitize($tl['note'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td class="small fw-bold">Total</td>
                        <td class="small fw-bold text-primary"><?= $total_logged ?>h</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Auto Timer History -->
    <?php if ($timer_history): ?>
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-stopwatch me-2"></i>Auto Timer History</h6>
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="small">Started</th>
                        <th class="small">Stopped</th>
                        <th class="small">Duration</th>
                        <th class="small">User</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($timer_history as $th): ?>
                <tr class="time-row">
                    <td class="small"><?= date('d M g:i A', strtotime($th['started_at'])) ?></td>
                    <td class="small"><?= $th['ended_at'] ? date('d M g:i A', strtotime($th['ended_at'])) : '<span class="badge bg-primary">Running</span>' ?></td>
                    <td class="small fw-semibold text-primary">
                        <?php 
                        if ($th['ended_at']) {
                            echo format_duration($th['duration_seconds']);
                        } else {
                            echo '<span class="badge bg-success">Active</span>';
                        }
                        ?>
                    </td>
                    <td class="small text-muted"><?= sanitize($th['user_name']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="2" class="small fw-bold">Total Auto Time</td>
                        <td class="small fw-bold text-primary"><?= round($total_timer_hours, 2) ?>h</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- RIGHT: Comments / Activity -->
<div class="col-md-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-4"><i class="bi bi-chat-dots me-2"></i>Updates & Comments</h6>

            <!-- Add Comment -->
            <?php if (!$hr_view): ?>
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="add_comment">
                <div class="d-flex gap-2 align-items-start">
                    <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0;">
                        <?= strtoupper(substr($u['name'],0,1)) ?>
                    </div>
                    <div class="flex-grow-1">
                        <textarea name="comment" class="form-control form-control-sm" rows="2" placeholder="Add an update or comment..." required></textarea>
                        <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-send me-1"></i>Post</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <!-- Comments list -->
            <?php if (!$comments): ?>
            <p class="text-muted small text-center py-3">No updates yet. Be the first to post.</p>
            <?php else: ?>
            <?php foreach ($comments as $c): ?>
            <?php $is_stage = str_starts_with($c['comment'], 'Stage moved:'); ?>
            <?php if ($is_stage): ?>
            <div class="d-flex align-items-center gap-2 mb-3 text-muted" style="font-size:.75rem;">
                <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                <i class="bi bi-arrow-right-circle-fill text-primary"></i>
                <span><?= sanitize($c['comment']) ?></span>
                <span>·</span>
                <span><?= sanitize($c['author']) ?></span>
                <span>·</span>
                <span><?= date('d M, h:i A', strtotime($c['created_at'])) ?></span>
                <div style="flex:1;height:1px;background:#e2e8f0;"></div>
            </div>
            <?php else: ?>
            <div class="comment-bubble <?= $c['user_id']==$uid ? 'mine' : '' ?> mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold small"><?= sanitize($c['author']) ?></span>
                    <span class="text-muted" style="font-size:0.7rem;"><?= date('d M Y, h:i A', strtotime($c['created_at'])) ?></span>
                </div>
                <div class="small"><?= nl2br(sanitize($c['comment'])) ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>

<?php include 'footer.php'; ?>
.Value
        }

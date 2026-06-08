<?php
require_once 'config.php';
require_once 'points_helper.php';
require_once 'task_timer_helper.php';
require_once 'digiops_sync_helper.php';
require_login();
$page      = 'tasks';
$pageTitle = 'Task Management';

// One-time migration: ensure completion_note column exists
try { $conn->exec("ALTER TABLE task_approvals ADD COLUMN completion_note TEXT NULL DEFAULT NULL"); } catch (PDOException $e) {}
$u         = current_user();
$uid       = $u['id'];
$role      = $u['role'];
$is_tl     = in_array($role, ['SUPER_ADMIN','TEAM_LEAD','DEPT_MANAGER']);
$hr_view   = $role === 'HR_ADMIN';

function log_task_activity($conn, $task_id, $user_id, $action, $detail = '') {
    $conn->prepare("INSERT INTO task_activity_logs (task_id,user_id,action,detail) VALUES (?,?,?,?)")
         ->execute([$task_id, $user_id, $action, $detail]);
}
function time_ago($dt) {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return (int)($d/60).'m ago';
    if ($d < 86400)  return (int)($d/3600).'h ago';
    if ($d < 604800) return (int)($d/86400).'d ago';
    return date('d M Y', strtotime($dt));
}

// Resolve dept_id for a user via employee_roles (TL assignment)
function tl_dept_id($conn, $uid): ?int {
    $s = $conn->prepare("
        SELECT er.dept_id FROM employee_roles er
        JOIN employees e ON e.id = er.employee_id
        JOIN users u ON u.email = e.email
        WHERE u.id = ? AND er.is_team_lead = 1 LIMIT 1
    ");
    $s->execute([$uid]);
    $val = $s->fetchColumn();
    return $val ? (int)$val : null;
}

// Get team members for a TL via department (not team_hierarchy)
function dept_team_members($conn, $uid): array {
    $dept_id = tl_dept_id($conn, $uid);
    if (!$dept_id) return [];
    $s = $conn->prepare("
        SELECT u.id, u.name FROM employees e
        JOIN users u ON u.email = e.email
        WHERE e.dept_id = ? AND e.status = 'ACTIVE' AND u.id != ?
        ORDER BY e.name
    ");
    $s->execute([$dept_id, $uid]);
    return $s->fetchAll();
}

// Fetch my team members (for TL) — department-based
$my_team = [];
$my_dept_id = null;
if ($role === 'TEAM_LEAD') {
    $my_dept_id = tl_dept_id($conn, $uid);
    $my_team    = dept_team_members($conn, $uid);
}
if ($role === 'SUPER_ADMIN' || $role === 'DEPT_MANAGER') {
    $my_team = $conn->query("SELECT id, name FROM users WHERE role IN ('EMPLOYEE','TEAM_LEAD','DEPT_MANAGER') ORDER BY name")->fetchAll();
}

// All TLs (for cross-team requests) — via employee_roles
$all_tls = $conn->query("
    SELECT u.id, u.name, d.name as dept_name
    FROM users u
    JOIN employees e ON e.email = u.email
    JOIN employee_roles er ON er.employee_id = e.id AND er.is_team_lead = 1
    JOIN departments d ON d.id = er.dept_id
    WHERE u.role IN ('TEAM_LEAD','DEPT_MANAGER','SUPER_ADMIN')
    GROUP BY u.id ORDER BY u.name
")->fetchAll();

// Projects
$projects = $conn->query("SELECT id, name FROM projects WHERE status='ACTIVE' ORDER BY name")->fetchAll();

// Depts
$depts = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// ── POST HANDLERS ─────────────────────────────────────────

// Helper: send JSON for AJAX calls and exit
function ajax_ok(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
function ajax_err(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
$is_ajax = !empty($_POST['_ajax']);
// For hConfirm AJAX: action and task_id come from GET, _ajax flag from POST body
if ($is_ajax && empty($_POST['action']) && !empty($_GET['action'])) {
    $_POST['action']  = $_GET['action'];
    $_POST['task_id'] = $_GET['task_id'] ?? '';
}

// Create task (TL/Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Employee creates a task for themselves
    if ($_POST['action'] === 'create_own_task' && !$is_tl && !$hr_view) {
        $needs_appr = isset($_POST['needs_approval']) ? 1 : 0;
        // Find this employee's TL (same dept, is_team_lead=1) to set as assigned_by when approval is needed
        $assigned_by = $uid;
        if ($needs_appr) {
            $tl_lookup = $conn->prepare("
                SELECT u.id FROM users u
                JOIN employees e ON e.email = u.email
                JOIN employee_roles er ON er.employee_id = e.id AND er.is_team_lead = 1
                WHERE e.dept_id = (
                    SELECT dept_id FROM employees WHERE email = (SELECT email FROM users WHERE id = ? LIMIT 1) LIMIT 1
                )
                LIMIT 1
            ");
            $tl_lookup->execute([$uid]);
            $tl_id = $tl_lookup->fetchColumn();
            if ($tl_id) $assigned_by = (int)$tl_id;
        }
        $conn->prepare("INSERT INTO tasks (title,description,project_id,assigned_to,assigned_by,priority,due_date,estimated_hours,needs_approval,status) VALUES (?,?,?,?,?,?,?,?,?,'TODO')")
             ->execute([trim($_POST['title']), trim($_POST['description']),
                 $_POST['project_id'] ?: null, $uid, $assigned_by,
                 $_POST['priority'], $_POST['due_date'] ?: null, $_POST['estimated_hours'] ?: null,
                 $needs_appr]);
        $new_id = $conn->lastInsertId();
        log_task_activity($conn, $new_id, $uid, 'CREATED', "Self-assigned: ".trim($_POST['title']).($needs_appr ? ' [requires approval]' : ''));
        // Notify TL if approval is required
        if ($needs_appr && $assigned_by !== $uid) {
            hrms_notify($conn, $assigned_by, 'task_assigned',
                'Task pending your approval: ' . mb_substr(trim($_POST['title']), 0, 60),
                ($u['name'] ?? 'An employee') . ' created a task that requires your approval.',
                'tasks.php?tab=approvals'
            );
        }
        set_flash('success', 'Task created.' . ($needs_appr ? ' Your TL will be notified to approve.' : ''));
        header("Location: tasks.php"); exit;
    }

    // Create internal task (TL/Admin)
    if ($_POST['action'] === 'create_task' && $is_tl && !$hr_view) {
        $title = trim($_POST['title'] ?? '');
        if (!$title) { if ($is_ajax) ajax_err('Title is required'); set_flash('danger','Title required'); header("Location: tasks.php"); exit; }
        $needs_appr = isset($_POST['needs_approval']) ? 1 : 0;
        $conn->prepare("INSERT INTO tasks (title,description,project_id,assigned_to,assigned_by,priority,due_date,estimated_hours,needs_approval,status) VALUES (?,?,?,?,?,?,?,?,?,'TODO')")
             ->execute([$title, $_POST['description'],
                 $_POST['project_id'] ?: null, $_POST['assigned_to'] ?: null, $uid,
                 $_POST['priority'], $_POST['due_date'] ?: null, $_POST['estimated_hours'] ?: null,
                 $needs_appr]);
        $new_id = $conn->lastInsertId();
        log_task_activity($conn, $new_id, $uid, 'CREATED', "Created & assigned: ".$title.($needs_appr ? ' [requires approval]' : ''));
        // Notify the assignee
        $assignee_id = (int)($_POST['assigned_to'] ?? 0);
        if ($assignee_id && $assignee_id !== $uid) {
            $due_label = !empty($_POST['due_date']) ? ' — due ' . date('d M', strtotime($_POST['due_date'])) : '';
            hrms_notify($conn, $assignee_id, 'task_assigned',
                'New task assigned: ' . mb_substr($title, 0, 60),
                'Assigned by ' . ($u['name'] ?? 'your TL') . $due_label . ($needs_appr ? ' · Requires approval' : ''),
                'task_detail.php?id=' . $new_id
            );
        }
        if ($is_ajax) ajax_ok(['task_id' => (int)$new_id]);
        set_flash('success', 'Task created and assigned.' . ($needs_appr ? ' Approval required.' : ''));
        header("Location: tasks.php"); exit;
    }

    // Soft delete — TL/Admin can delete any task they manage; employee can delete their own self-created tasks
    if ($_POST['action'] === 'delete_task') {
        $tid = (int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        $chk = $conn->prepare("SELECT title, assigned_by, assigned_to FROM tasks WHERE id=?");
        $chk->execute([$tid]); $chk = $chk->fetch();
        $can_delete = $chk && ($is_tl || ($chk['assigned_by']==$uid && $chk['assigned_to']==$uid));
        if ($can_delete) {
            $conn->prepare("UPDATE tasks SET deleted_at=NOW(), deleted_by=? WHERE id=?")
                 ->execute([$uid, $tid]);
            log_task_activity($conn, $tid, $uid, 'DELETED', "Moved to recycle bin: ".($chk['title'] ?? ''));
        }
        if ($is_ajax) ajax_ok();
        set_flash('success', 'Task moved to recycle bin.');
        header("Location: tasks.php"); exit;
    }

    // Restore from recycle bin — TL/Admin or the original creator (employee)
    if ($_POST['action'] === 'restore_task') {
        $tid = (int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        $chk = $conn->prepare("SELECT title, assigned_by, assigned_to FROM tasks WHERE id=?");
        $chk->execute([$tid]); $chk = $chk->fetch();
        $can_restore = $chk && ($is_tl || ($chk['assigned_by']==$uid && $chk['assigned_to']==$uid));
        if ($can_restore) {
            $conn->prepare("UPDATE tasks SET deleted_at=NULL, deleted_by=NULL WHERE id=?")
                 ->execute([$tid]);
            log_task_activity($conn, $tid, $uid, 'RESTORED', "Restored from recycle bin: ".($chk['title'] ?? ''));
        }
        if (!empty($_GET['_ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
        set_flash('success', 'Task restored.');
        header("Location: tasks.php?tab=bin"); exit;
    }

    // Permanent delete (SUPER_ADMIN only)
    if ($_POST['action'] === 'purge_task' && $role === 'SUPER_ADMIN') {
        $tid = (int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0);
        $conn->prepare("DELETE FROM tasks WHERE id=? AND deleted_at IS NOT NULL")
             ->execute([$tid]);
        if (!empty($_GET['_ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
        set_flash('success', 'Task permanently deleted.');
        header("Location: tasks.php?tab=bin"); exit;
    }

    // Cross-team request (any role except HR)
    if ($_POST['action'] === 'cross_request' && !$hr_view) {
        $conn->prepare("INSERT INTO tasks (title,description,project_id,from_dept_id,to_tl_id,assigned_by,priority,due_date,estimated_hours,status) VALUES (?,?,?,?,?,?,?,?,?,'REQUESTED')")
             ->execute([
                 $_POST['title'], $_POST['description'],
                 $_POST['project_id'] ?: null,
                 $_POST['from_dept_id'] ?: null,
                 $_POST['to_tl_id'],
                 $uid,
                 $_POST['priority'],
                 $_POST['due_date'] ?: null,
                 $_POST['estimated_hours'] ?: null
             ]);
        set_flash('success', 'Request sent to team lead.');
        header("Location: tasks.php?tab=outgoing"); exit;
    }

    // TL approves request → assigns to team member
    if ($_POST['action'] === 'approve_request' && $is_tl) {
        $tid         = (int)$_POST['task_id'];
        $assignee_id = (int)$_POST['assigned_to'];
        $needs_appr  = !empty($_POST['needs_approval']) ? 1 : 0;
        $conn->prepare("UPDATE tasks SET status='TODO', assigned_to=?, assigned_by=?, needs_approval=? WHERE id=? AND to_tl_id=?")
             ->execute([$assignee_id, $uid, $needs_appr, $tid, $uid]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$tid, $uid, 'Request approved and assigned to team member.' . ($needs_appr ? ' Requires approval.' : '')]);
        log_task_activity($conn, $tid, $uid, 'APPROVED', 'Cross-team request approved & assigned' . ($needs_appr ? ' [requires approval]' : ''));
        // Notify the assignee
        if ($assignee_id && $assignee_id !== $uid) {
            $task_info = $conn->prepare("SELECT title, due_date FROM tasks WHERE id=?");
            $task_info->execute([$tid]);
            $tinfo = $task_info->fetch();
            $due_label = !empty($tinfo['due_date']) ? ' — due ' . date('d M', strtotime($tinfo['due_date'])) : '';
            hrms_notify($conn, $assignee_id, 'task_assigned',
                'New task assigned: ' . mb_substr($tinfo['title'] ?? '', 0, 60),
                'Assigned by ' . ($u['name'] ?? 'your TL') . $due_label . ($needs_appr ? ' · Requires approval' : ''),
                'task_detail.php?id=' . $tid
            );
        }
        if ($is_ajax) ajax_ok();
        set_flash('success', 'Request approved and task assigned.');
        header("Location: tasks.php?tab=incoming"); exit;
    }

    // TL rejects request
    if ($_POST['action'] === 'reject_request' && $is_tl) {
        $tid = (int)$_POST['task_id'];
        $conn->prepare("UPDATE tasks SET status='REJECTED', reject_reason=? WHERE id=? AND to_tl_id=?")
             ->execute([$_POST['reject_reason'], $tid, $uid]);
        log_task_activity($conn, $tid, $uid, 'REJECTED', 'Reason: '.trim($_POST['reject_reason']));
        if ($is_ajax) ajax_ok();
        set_flash('danger', 'Request rejected.');
        header("Location: tasks.php?tab=incoming"); exit;
    }

    // ── Submit task for approval (assignee action) ──────────
    if ($_POST['action'] === 'submit_for_approval') {
        $tid             = (int)$_POST['task_id'];
        $completion_note = trim($_POST['completion_note'] ?? '');
        $chk = $conn->prepare("SELECT assigned_to, assigned_by, status, needs_approval, title FROM tasks WHERE id=?");
        $chk->execute([$tid]);
        $t_row = $chk->fetch();
        if ($t_row && ($t_row['assigned_to']==$uid || $t_row['assigned_by']==$uid) && !empty($t_row['needs_approval'])) {
            if (!$completion_note) {
                if ($is_ajax) ajax_err('Please describe what you completed before submitting.');
                set_flash('danger', 'Completion note is required before submitting for approval.');
                header("Location: tasks.php"); exit;
            }
            // Move to REVIEW
            $conn->prepare("UPDATE tasks SET status='REVIEW', updated_at=NOW() WHERE id=?")->execute([$tid]);
            // Upsert approval record: update existing rework row if present, else insert fresh pending
            $existsAppr = $conn->prepare("SELECT id FROM task_approvals WHERE task_id=? AND status IN ('pending','rework')");
            $existsAppr->execute([$tid]);
            if ($existsAppr->fetchColumn()) {
                $conn->prepare("UPDATE task_approvals SET status='pending', submitted_by=?, submitted_at=NOW(), reviewed_by=NULL, reviewed_at=NULL, note=NULL, completion_note=? WHERE task_id=? AND status IN ('pending','rework')")
                     ->execute([$uid, $completion_note, $tid]);
            } else {
                $conn->prepare("INSERT INTO task_approvals (task_id, submitted_by, status, completion_note) VALUES (?,?,'pending',?)")
                     ->execute([$tid, $uid, $completion_note]);
            }
            // Save note as a comment too so it's visible in task history
            $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                 ->execute([$tid, $uid, "Completion note: $completion_note"]);
            log_task_activity($conn, $tid, $uid, 'STATUS_CHANGED', "REVIEW — submitted for approval");
            // Notify TL/approvers
            if ($t_row['assigned_by'] && $t_row['assigned_by'] != $uid) {
                hrms_notify($conn, (int)$t_row['assigned_by'], 'task_approval', 'Task ready for review: '.mb_substr($t_row['title'],0,60), mb_substr($completion_note,0,100), 'tasks.php?tab=approvals');
            }
            // Sync to DigiOps
            _digiops_task_sync($conn, $tid, 'REVIEW');
            if ($is_ajax) ajax_ok();
            set_flash('success', 'Task submitted for approval. Waiting for review.');
        }
        if ($is_ajax) ajax_err('Not authorised or task does not require approval');
        header("Location: tasks.php"); exit;
    }

    // ── Approve a task (TL/Manager/Admin) ───────────────────
    if ($_POST['action'] === 'approve_task_hrms') {
        $tid = (int)$_POST['task_id'];
        $chk = $conn->prepare("SELECT assigned_to, assigned_by, status, title FROM tasks WHERE id=?");
        $chk->execute([$tid]);
        $t_row = $chk->fetch();
        if ($t_row && $is_tl) {
            // Update task to DONE
            $conn->prepare("UPDATE tasks SET status='DONE', updated_at=NOW() WHERE id=?")->execute([$tid]);
            // Mark approval record
            $conn->prepare("UPDATE task_approvals SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE task_id=? AND status='pending'")
                 ->execute([$uid, $tid]);
            log_task_activity($conn, $tid, $uid, 'APPROVED', 'Task approved → DONE');
            // Notify assignee
            if ($t_row['assigned_to'] && $t_row['assigned_to'] != $uid) {
                hrms_notify($conn, (int)$t_row['assigned_to'], 'task_approved', 'Task approved: '.mb_substr($t_row['title'],0,60), 'Your task has been approved and marked as done.', "task_detail.php?id=$tid");
            }
            // Award points
            $assigneeRow = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $assigneeRow->execute([$t_row['assigned_to']]);
            $assigneeEmail = $assigneeRow->fetchColumn();
            if ($assigneeEmail) {
                $empRow = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
                $empRow->execute([$assigneeEmail]);
                $empId = (int)($empRow->fetchColumn() ?: 0);
                if ($empId) {
                    pts_award($conn, $empId, 'hrms_task_done', (string)$tid, 'hrms_task', 'HRMS task approved & done');
                    $taskRow2 = $conn->prepare("SELECT due_date FROM tasks WHERE id = ?");
                    $taskRow2->execute([$tid]);
                    $dueDate2 = $taskRow2->fetchColumn();
                    if ($dueDate2 && date('Y-m-d') <= $dueDate2) {
                        pts_award($conn, $empId, 'hrms_task_ontime', (string)$tid, 'hrms_task', 'Completed on time');
                    }
                    pts_award_tl_bonus($conn, $empId, 'hrms_task_done', (string)$tid, 'hrms_task');
                }
            }
            // Sync DONE to DigiOps → triggers workflow advance
            _digiops_task_sync($conn, $tid, 'DONE');
            if ($is_ajax) ajax_ok();
            set_flash('success', 'Task approved and marked as done.');
        }
        if ($is_ajax) ajax_err('Not authorised');
        header("Location: tasks.php?tab=approvals"); exit;
    }

    // ── Reject a task (sends to REWORK) ─────────────────────
    if ($_POST['action'] === 'reject_task_hrms') {
        $tid  = (int)$_POST['task_id'];
        $note = trim($_POST['note'] ?? '');
        $chk  = $conn->prepare("SELECT assigned_to, assigned_by, status, title FROM tasks WHERE id=?");
        $chk->execute([$tid]);
        $t_row = $chk->fetch();
        if ($t_row && $is_tl) {
            $conn->prepare("UPDATE tasks SET status='REWORK', updated_at=NOW() WHERE id=?")->execute([$tid]);
            $conn->prepare("UPDATE task_approvals SET status='rework', reviewed_by=?, reviewed_at=NOW(), note=? WHERE task_id=? AND status='pending'")
                 ->execute([$uid, $note, $tid]);
            if ($note) {
                $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                     ->execute([$tid, $uid, "↩️ Sent back for rework: $note"]);
            }
            log_task_activity($conn, $tid, $uid, 'REJECTED', 'Sent for rework: '.$note);
            // Notify assignee
            if ($t_row['assigned_to'] && $t_row['assigned_to'] != $uid) {
                hrms_notify($conn, (int)$t_row['assigned_to'], 'task_rework', 'Rework needed: '.mb_substr($t_row['title'],0,60), $note ?: 'Please revise and resubmit.', "task_detail.php?id=$tid");
            }
            // Sync to DigiOps (rework = in_progress)
            _digiops_task_sync($conn, $tid, 'REWORK');
            if ($is_ajax) ajax_ok();
            set_flash('warning', 'Task sent back for rework.');
        }
        if ($is_ajax) ajax_err('Not authorised');
        header("Location: tasks.php?tab=approvals"); exit;
    }

    // Update task status
    if ($_POST['action'] === 'update_status') {
        $tid = (int)$_POST['task_id'];
        $allowed = ['TODO','IN_PROGRESS','REVIEW','DONE','REWORK'];
        $ns = $_POST['new_status'];
        if (in_array($ns, $allowed)) {
            $chk = $conn->prepare("SELECT assigned_to, assigned_by, status, needs_approval, to_tl_id, title FROM tasks WHERE id=?");
            $chk->execute([$tid]);
            $t_row = $chk->fetch();
            // Block direct DONE if needs_approval and not TL
            if ($ns === 'DONE' && !empty($t_row['needs_approval']) && !$is_tl) {
                set_flash('danger', 'This task requires approval. Use "Submit for Review" instead.');
                header("Location: task_detail.php?id=$tid"); exit;
            }
            if ($t_row && ($is_tl || $t_row['assigned_to']==$uid || $t_row['assigned_by']==$uid)) {
                // Auto-manage timer based on status change
                if ($ns === 'IN_PROGRESS' && $t_row['status'] !== 'IN_PROGRESS') {
                    // Starting work: start the timer
                    start_task_timer($conn, $tid, $uid);
                } elseif ($t_row['status'] === 'IN_PROGRESS' && $ns !== 'IN_PROGRESS') {
                    // Stopping work: stop the timer
                    stop_task_timer($conn, $tid, $uid);
                }
                
                $conn->prepare("UPDATE tasks SET status=?,updated_at=NOW() WHERE id=?")->execute([$ns, $tid]);
                $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                     ->execute([$tid, $uid, "Stage moved: {$t_row['status']} → {$ns}"]);
                log_task_activity($conn, $tid, $uid, 'STATUS_CHANGED', "{$t_row['status']} → {$ns}");

                // Auto-upsert approval record when needs_approval task moves to REVIEW
                if ($ns === 'REVIEW' && !empty($t_row['needs_approval'])) {
                    $existsAppr = $conn->prepare("SELECT id FROM task_approvals WHERE task_id=? AND status IN ('pending','rework')");
                    $existsAppr->execute([$tid]);
                    if ($existsAppr->fetchColumn()) {
                        // Re-open the rework/pending row so TL sees it fresh
                        $conn->prepare("UPDATE task_approvals SET status='pending', submitted_by=?, submitted_at=NOW(), reviewed_by=NULL, reviewed_at=NULL, note=NULL WHERE task_id=? AND status IN ('pending','rework')")
                             ->execute([$uid, $tid]);
                    } else {
                        $conn->prepare("INSERT INTO task_approvals (task_id, submitted_by, status) VALUES (?,?,'pending')")
                             ->execute([$tid, $uid]);
                    }
                    // Notify TL (assigned_by)
                    if ($t_row['assigned_by'] && $t_row['assigned_by'] != $uid) {
                        $taskTitle = $conn->prepare("SELECT title FROM tasks WHERE id=?");
                        $taskTitle->execute([$tid]);
                        $ttl = $taskTitle->fetchColumn();
                        hrms_notify($conn, (int)$t_row['assigned_by'], 'task_approval', 'Task ready for review: '.mb_substr($ttl,0,60), 'Awaiting your approval.', 'tasks.php?tab=approvals');
                    }
                }

                // Notify original cross-team requester when status changes (if task came from another team)
                if (!empty($t_row['to_tl_id'])) {
                    // The original requester is assigned_by when to_tl_id is set
                    // Find who originally sent it: assigned_by before TL reassigned it
                    $orig_req = $conn->prepare("SELECT user_id FROM task_activity_logs WHERE task_id=? AND action='CREATED' LIMIT 1");
                    $orig_req->execute([$tid]);
                    $orig_uid = (int)($orig_req->fetchColumn() ?: 0);
                    $notify_targets = array_unique(array_filter([$orig_uid, (int)$t_row['to_tl_id']], fn($id) => $id && $id !== $uid));
                    $status_labels = ['TODO'=>'Assigned','IN_PROGRESS'=>'In Progress','REVIEW'=>'In Review','DONE'=>'Completed','REWORK'=>'Rework Requested','BLOCKED'=>'Blocked'];
                    $status_label = $status_labels[$ns] ?? str_replace('_', ' ', $ns);
                    foreach ($notify_targets as $nid) {
                        hrms_notify($conn, $nid, 'task_assigned', 'Cross-team task: ' . $status_label, mb_substr($t_row['title'],0,80) . ' — status updated to ' . $status_label, 'task_detail.php?id=' . $tid);
                    }
                }

                // Push status change to DigiOps (reverse sync)
                _digiops_task_sync($conn, $tid, $ns);

                // Award points when task marked DONE
                if ($ns === 'DONE' && $t_row['status'] !== 'DONE') {
                    $assigneeRow = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
                    $assigneeRow->execute([$t_row['assigned_to']]);
                    $assigneeEmail = $assigneeRow->fetchColumn();
                    if ($assigneeEmail) {
                        $empRow = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
                        $empRow->execute([$assigneeEmail]);
                        $empId = (int)($empRow->fetchColumn() ?: 0);
                        if ($empId) {
                            pts_award($conn, $empId, 'hrms_task_done', (string)$tid, 'hrms_task', 'HRMS task completed');
                            // On-time bonus
                            $taskRow = $conn->prepare("SELECT due_date FROM tasks WHERE id = ?");
                            $taskRow->execute([$tid]);
                            $dueDate = $taskRow->fetchColumn();
                            if ($dueDate && date('Y-m-d') <= $dueDate) {
                                pts_award($conn, $empId, 'hrms_task_ontime', (string)$tid, 'hrms_task', 'Completed on time');
                            }
                            // TL bonus
                            pts_award_tl_bonus($conn, $empId, 'hrms_task_done', (string)$tid, 'hrms_task');
                        }
                    }
                }
            }
        }
        header("Location: task_detail.php?id=$tid"); exit;
    }

    // Log time
    if ($_POST['action'] === 'log_time') {
        $tid = (int)$_POST['task_id'];
        $chk = $conn->prepare("SELECT assigned_to, assigned_by FROM tasks WHERE id=?");
        $chk->execute([$tid]);
        $t_row = $chk->fetch();
        if ($t_row && ($is_tl || $t_row['assigned_to']==$uid || $t_row['assigned_by']==$uid)) {
            $conn->prepare("INSERT INTO task_time_logs (task_id,user_id,hours,note,logged_at) VALUES (?,?,?,?,?)")
                 ->execute([$tid, $uid, $_POST['hours'], $_POST['note'], $_POST['logged_at']]);
            log_task_activity($conn, $tid, $uid, 'TIME_LOGGED', $_POST['hours'].'h — '.($_POST['note'] ?: 'no note'));
            set_flash('success', 'Time logged.');
        }
        header("Location: task_detail.php?id=$tid"); exit;
    }

    // Resolve a block request
    if ($_POST['action'] === 'resolve_block_request' && !$hr_view) {
        $br_id       = (int)($_POST['br_id'] ?? 0);
        $resp_text   = trim($_POST['response_text'] ?? '');
        $resp_link   = trim($_POST['response_link'] ?? '');
        $upload_path = null;

        $br = $conn->prepare("SELECT br.*, t.assigned_to, t.assigned_by, t.title, t.status
            FROM task_block_requests br JOIN tasks t ON t.id=br.task_id
            WHERE br.id=? AND br.status='pending'");
        $br->execute([$br_id]);
        $br = $br->fetch();

        if (!$br) { set_flash('danger','Request not found or already resolved.'); header("Location: tasks.php?tab=block_requests"); exit; }

        // Check this user is the intended recipient (must be the specific person, or a manager)
        $is_target  = ($br['requested_user_id'] == $uid);
        $is_manager = in_array($role, ['SUPER_ADMIN','DEPT_MANAGER']);
        if (!$is_target && !$is_manager) {
            set_flash('danger','You are not the intended recipient of this request.');
            header("Location: tasks.php?tab=block_requests"); exit;
        }

        // Handle file upload
        if (!empty($_FILES['response_file']['name'])) {
            $ext  = strtolower(pathinfo($_FILES['response_file']['name'], PATHINFO_EXTENSION));
            $safe = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','zip','txt','csv'];
            if (!in_array($ext, $safe)) {
                set_flash('danger','File type not allowed.'); header("Location: tasks.php?tab=block_requests"); exit;
            }
            $fname = 'br_' . $br_id . '_' . time() . '.' . $ext;
            $dest  = __DIR__ . '/uploads/block_requests/' . $fname;
            if (move_uploaded_file($_FILES['response_file']['tmp_name'], $dest)) {
                $upload_path = 'uploads/block_requests/' . $fname;
            }
        }

        $final_response = $resp_text ?: $resp_link ?: 'Marked as done.';

        $conn->prepare("UPDATE task_block_requests SET status='resolved', response_text=?, response_file=?, resolved_by=?, resolved_at=NOW() WHERE id=?")
             ->execute([$final_response, $upload_path, $uid, $br_id]);

        // Auto-unblock the task — goes back to TODO so assignee re-picks it up
        $conn->prepare("UPDATE tasks SET status='TODO', blocked_reason=NULL, unblocked_at=NOW() WHERE id=? AND status='BLOCKED'")
             ->execute([$br['task_id']]);
        $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
             ->execute([$br['task_id'], $uid, "✅ Block resolved by " . ($u['name'] ?? 'user') . ": " . $final_response
                . ($upload_path ? " [file attached]" : "")]);

        // Notify the original blocker + task assignee
        $msg  = ($u['name'] ?? 'Someone') . " resolved your block request on task: " . mb_substr($br['title'], 0, 60);
        foreach (array_unique([$br['requested_by'], $br['assigned_to'], $br['assigned_by']]) as $tid2) {
            if ($tid2 && $tid2 != $uid) {
                hrms_notify($conn, (int)$tid2, 'block_resolved', 'Block resolved: '.mb_substr($br['title'],0,60), $msg, 'task_detail.php?id='.$br['task_id']);
            }
        }
        set_flash('success', 'Block request resolved. Task is now In Progress.');
        header("Location: tasks.php?tab=block_requests"); exit;
    }

    // ── Inline quick-edit (from table view dropdowns) ──────────
    if ($_POST['action'] === 'quick_edit') {
        header('Content-Type: application/json');
        $tid   = (int)($_POST['task_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        $task = $conn->prepare("SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL");
        $task->execute([$tid]);
        $task = $task->fetch();

        if (!$task) { echo json_encode(['error' => 'Task not found']); exit; }

        $is_creator  = $task['assigned_by'] == $uid;
        $can_edit    = $is_tl || $hr_view || $is_creator;

        if (!$can_edit) { echo json_encode(['error' => 'You can only change status on tasks you created']); exit; }

        // Creators can only change status; TL/admin can change everything
        $editable = ($is_tl || $hr_view) ? ['status','priority','title','due_date','estimated_hours'] : ['status'];
        if (!in_array($field, $editable, true)) {
            echo json_encode(['error' => 'Field not editable']); exit;
        }

        // Status — validate allowed values and transitions
        if ($field === 'status') {
            $valid_statuses = ['TODO','IN_PROGRESS','REVIEW','BLOCKED','DONE'];
            if (!in_array($value, $valid_statuses, true)) {
                echo json_encode(['error' => 'Invalid status']); exit;
            }
            // REVIEW requires needs_approval flow — if needs_approval, disallow direct DONE
            if ($value === 'DONE' && $task['needs_approval'] && !$is_tl) {
                echo json_encode(['error' => 'This task requires approval before DONE']); exit;
            }
        }

        if ($field === 'priority') {
            if (!in_array($value, ['LOW','MEDIUM','HIGH','URGENT'], true)) {
                echo json_encode(['error' => 'Invalid priority']); exit;
            }
        }

        $conn->prepare("UPDATE tasks SET `$field` = ?, updated_at = NOW() WHERE id = ?")
             ->execute([$value ?: null, $tid]);

        log_task_activity($conn, $tid, $uid, 'STATUS_CHANGED', "$field changed to $value");

        // Sync status to DigiOps if status changed
        if ($field === 'status') {
            _digiops_task_sync($conn, $tid, $value);
        }

        echo json_encode(['ok' => true, 'field' => $field, 'value' => $value]);
        exit;
    }

    // Add comment
    if ($_POST['action'] === 'add_comment') {
        $tid = (int)$_POST['task_id'];
        $chk = $conn->prepare("SELECT assigned_to, assigned_by FROM tasks WHERE id=?");
        $chk->execute([$tid]);
        $t_row = $chk->fetch();
        if ($t_row && ($is_tl || $hr_view || $t_row['assigned_to']==$uid || $t_row['assigned_by']==$uid)) {
            $conn->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                 ->execute([$tid, $uid, trim($_POST['comment'])]);
        }
        header("Location: task_detail.php?id=$tid"); exit;
    }
}

// Legacy GET delete — redirect to recycle bin tab
if (isset($_GET['delete'])) {
    header("Location: tasks.php?tab=bin"); exit;
}

$tab = $_GET['tab'] ?? 'my';

// ── FETCH BLOCK REQUESTS for current user (only ones directed at me by user ID) ──
$my_block_requests = [];
if (!$hr_view) {
    $brq = $conn->prepare("SELECT br.*, t.title as task_title, t.id as task_id,
        u.name as requester_name, u2.name as specific_person
        FROM task_block_requests br
        JOIN tasks t ON t.id = br.task_id
        JOIN users u ON u.id = br.requested_by
        LEFT JOIN users u2 ON u2.id = br.requested_user_id
        WHERE br.status = 'pending'
          AND br.requested_user_id = ?
          AND br.requested_by != ?
        ORDER BY br.created_at DESC");
    $brq->execute([$uid, $uid]);
    $my_block_requests = $brq->fetchAll();
}

// ── FETCH TASKS ───────────────────────────────────────────

// My tasks (employee sees own, TL sees team, admin sees all)
if ($role === 'SUPER_ADMIN' || $role === 'DEPT_MANAGER') {
    $my_tasks = $conn->query("SELECT t.*, u.name as assignee_name, u2.name as creator_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.assigned_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.status NOT IN ('REQUESTED','REJECTED') AND t.deleted_at IS NULL
        ORDER BY FIELD(t.priority,'URGENT','HIGH','MEDIUM','LOW'), t.due_date ASC")->fetchAll();
} elseif ($role === 'TEAM_LEAD') {
    $team_ids = array_column($my_team, 'id');
    $team_ids[] = $uid;
    $in = implode(',', array_map('intval', $team_ids));
    $my_tasks = $conn->query("SELECT t.*, u.name as assignee_name, u2.name as creator_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.assigned_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE (t.assigned_to IN ($in) OR t.assigned_by=$uid)
        AND t.status NOT IN ('REQUESTED','REJECTED') AND t.deleted_at IS NULL
        ORDER BY FIELD(t.priority,'URGENT','HIGH','MEDIUM','LOW'), t.due_date ASC")->fetchAll();
    // TL's own tasks (assigned directly to them, not just created by them)
    $my_own_tasks = array_filter($my_tasks, fn($t) => (int)$t['assigned_to'] === $uid);
} elseif ($role === 'HR_ADMIN') {
    $my_tasks = $conn->query("SELECT t.*, u.name as assignee_name, u2.name as creator_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.assigned_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.status NOT IN ('REQUESTED','REJECTED') AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC")->fetchAll();
} else {
    $my_tasks = $conn->prepare("SELECT t.*, u.name as assignee_name, u2.name as creator_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.assigned_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.assigned_to=? AND t.status NOT IN ('REQUESTED','REJECTED') AND t.deleted_at IS NULL
        ORDER BY FIELD(t.priority,'URGENT','HIGH','MEDIUM','LOW'), t.due_date ASC");
    $my_tasks->execute([$uid]);
    $my_tasks = $my_tasks->fetchAll();
}

// Incoming requests (TL only)
$incoming = [];
if ($is_tl) {
    $s = $conn->prepare("SELECT t.*, u.name as requester_name, d.name as from_dept, p.name as project_name
        FROM tasks t
        LEFT JOIN users u ON u.id=t.assigned_by
        LEFT JOIN departments d ON d.id=t.from_dept_id
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.to_tl_id=? AND t.status='REQUESTED' AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC");
    $s->execute([$uid]);
    $incoming = $s->fetchAll();
}

// Outgoing requests (sent by me) — show all cross-team tasks (to_tl_id set) so requester can track status
$outgoing = [];
if (!$hr_view) {
    $s = $conn->prepare("SELECT t.*, u.name as tl_name, ua.name as assignee_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.to_tl_id
        LEFT JOIN users ua ON ua.id=t.assigned_to
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.assigned_by=? AND t.to_tl_id IS NOT NULL AND t.deleted_at IS NULL
        ORDER BY t.created_at DESC");
    $s->execute([$uid]);
    $outgoing = $s->fetchAll();
}

// ── REWORK NOTES MAP ─────────────────────────────────────
// For tasks in REWORK status, fetch the latest rework note from task_approvals
$rework_notes = []; // keyed by task_id => ['note' => ..., 'reviewer_name' => ...]
$rework_task_ids = array_column(array_filter($my_tasks, fn($t) => $t['status'] === 'REWORK'), 'id');
if ($rework_task_ids) {
    $rw_in = implode(',', array_map('intval', $rework_task_ids));
    $rw_s  = $conn->query(
        "SELECT ta.task_id, ta.note, u.name as reviewer_name
         FROM task_approvals ta
         LEFT JOIN users u ON u.id = ta.reviewed_by
         WHERE ta.task_id IN ($rw_in) AND ta.status = 'rework'
         ORDER BY ta.reviewed_at DESC"
    );
    foreach ($rw_s->fetchAll() as $rr) {
        // Only keep the most recent rework note per task
        if (!isset($rework_notes[(int)$rr['task_id']])) {
            $rework_notes[(int)$rr['task_id']] = [
                'note'          => $rr['note'],
                'reviewer_name' => $rr['reviewer_name'],
            ];
        }
    }
}

// ── APPROVER INFO MAP (DigiOps) ──────────────────────────
// For each task with needs_approval, look up who the approver is
$task_approver_info = []; // keyed by hrms task id
try {
    $ddb = digiops_db();
    if ($ddb) {
        // Get all active tasks with needs_approval that have a DigiOps link
        $needs_appr_ids = array_column(array_filter($my_tasks, fn($t) => $t['needs_approval']), 'id');
        if ($needs_appr_ids) {
            $in_str = implode(',', array_map('intval', $needs_appr_ids));
            $dbt = $ddb->query(
                "SELECT bt.hrms_task_id, bt.workflow_submission_id, ws.current_step, ws.template_id, ws.brand_id
                 FROM brand_tasks bt
                 LEFT JOIN workflow_submissions ws ON ws.id = bt.workflow_submission_id
                 WHERE bt.hrms_task_id IN ($in_str) AND bt.workflow_submission_id IS NOT NULL"
            );
            $dbtRows = $dbt->fetchAll();
            foreach ($dbtRows as $row) {
                if (!$row['template_id']) continue;
                // Get approval step 1 role
                $step = $ddb->prepare(
                    "SELECT approver_role FROM workflow_approval_steps WHERE template_id=? AND step_order=1 LIMIT 1"
                );
                $step->execute([$row['template_id']]);
                $stepRow = $step->fetch();
                if (!$stepRow) continue;
                $approverRole = $stepRow['approver_role'];
                // Resolve the actual user name
                $approverName = null;
                $approverRoleLabel = $approverRole;
                if (str_starts_with($approverRole, 'digiops_user_')) {
                    $userId = str_replace('digiops_user_', '', $approverRole);
                    $u2 = $ddb->prepare("SELECT name, role FROM users WHERE id=? LIMIT 1");
                    $u2->execute([$userId]);
                    $u2Row = $u2->fetch();
                    if ($u2Row) {
                        $approverName      = $u2Row['name'];
                        $approverRoleLabel = ucfirst($u2Row['role']);
                    }
                } else {
                    // role-based — look up brand member with that role
                    $bm = $ddb->prepare(
                        "SELECT u.name, u.role FROM brand_members bm JOIN users u ON u.id=bm.user_id
                         WHERE bm.brand_id=? AND bm.role=? LIMIT 1"
                    );
                    $bm->execute([$row['brand_id'], $approverRole]);
                    $bmRow = $bm->fetch();
                    if ($bmRow) {
                        $approverName      = $bmRow['name'];
                        $approverRoleLabel = ucwords(str_replace('_',' ', $approverRole));
                    } else {
                        $approverRoleLabel = ucwords(str_replace('_',' ', $approverRole));
                    }
                }
                $task_approver_info[(int)$row['hrms_task_id']] = [
                    'name' => $approverName,
                    'role' => $approverRoleLabel,
                ];
            }
        }
    }
} catch (Exception $e) { /* never break */ }

// ── APPROVALS DATA ───────────────────────────────────────
$pending_approvals = [];
$my_submitted_approvals = [];
if (!$hr_view) {
    // Tasks I submitted waiting for approval (assignee view)
    $s = $conn->prepare("SELECT t.*, ta.submitted_at, ta.status as approval_status, ta.note as approval_note,
        u.name as submitter_name, u2.name as creator_name, p.name as project_name
        FROM task_approvals ta
        JOIN tasks t ON t.id = ta.task_id
        LEFT JOIN users u ON u.id = ta.submitted_by
        LEFT JOIN users u2 ON u2.id = t.assigned_by
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE ta.submitted_by = ? AND ta.status IN ('pending','rework','approved')
        ORDER BY ta.submitted_at DESC LIMIT 30");
    $s->execute([$uid]);
    $my_submitted_approvals = $s->fetchAll();

    // Tasks pending my approval (TL/manager view — only my team)
    if ($is_tl) {
        if ($role === 'SUPER_ADMIN') {
            $s = $conn->query("SELECT t.*, ta.id as approval_id, ta.submitted_at, ta.submitted_by,
                ta.completion_note,
                u.name as submitter_name, u2.name as creator_name, p.name as project_name
                FROM task_approvals ta
                JOIN tasks t ON t.id = ta.task_id
                LEFT JOIN users u ON u.id = ta.submitted_by
                LEFT JOIN users u2 ON u2.id = t.assigned_by
                LEFT JOIN projects p ON p.id = t.project_id
                WHERE ta.status = 'pending' AND t.deleted_at IS NULL
                ORDER BY ta.submitted_at ASC");
        } else {
            // TL sees only their team members' approvals
            $team_ids_str = $my_team ? implode(',', array_map('intval', array_column($my_team, 'id'))) : '0';
            $s = $conn->query("SELECT t.*, ta.id as approval_id, ta.submitted_at, ta.submitted_by,
                ta.completion_note,
                u.name as submitter_name, u2.name as creator_name, p.name as project_name
                FROM task_approvals ta
                JOIN tasks t ON t.id = ta.task_id
                LEFT JOIN users u ON u.id = ta.submitted_by
                LEFT JOIN users u2 ON u2.id = t.assigned_by
                LEFT JOIN projects p ON p.id = t.project_id
                WHERE ta.status = 'pending' AND t.deleted_at IS NULL
                AND (t.assigned_to IN ($team_ids_str) OR t.assigned_by = $uid)
                ORDER BY ta.submitted_at ASC");
        }
        $pending_approvals = $s->fetchAll();
    }
}

// Stats
$stats = [
    'todo'        => count(array_filter($my_tasks, fn($t) => $t['status']==='TODO')),
    'inprogress'  => count(array_filter($my_tasks, fn($t) => $t['status']==='IN_PROGRESS')),
    'review'      => count(array_filter($my_tasks, fn($t) => $t['status']==='REVIEW')),
    'blocked'     => count(array_filter($my_tasks, fn($t) => $t['status']==='BLOCKED')),
    'done'        => count(array_filter($my_tasks, fn($t) => $t['status']==='DONE')),
    'incoming'        => count($incoming),
    'block_requests'  => count($my_block_requests),
    'approvals'       => $is_tl ? count($pending_approvals) : count(array_filter($my_submitted_approvals, fn($r) => $r['approval_status'] === 'pending')),
];
// TL own-task stats (separate from team stats)
$own_stats = [];
if ($role === 'TEAM_LEAD') {
    $own_stats = [
        'todo'       => count(array_filter($my_own_tasks, fn($t) => $t['status']==='TODO')),
        'inprogress' => count(array_filter($my_own_tasks, fn($t) => $t['status']==='IN_PROGRESS')),
        'review'     => count(array_filter($my_own_tasks, fn($t) => $t['status']==='REVIEW')),
        'blocked'    => count(array_filter($my_own_tasks, fn($t) => $t['status']==='BLOCKED')),
        'done'       => count(array_filter($my_own_tasks, fn($t) => $t['status']==='DONE')),
    ];
}

// Recycle bin — TL/Admin see team bin; employees see only their own self-deleted tasks
$recycle_bin = [];
if ($role === 'SUPER_ADMIN' || $hr_view) {
    $recycle_bin = $conn->query("SELECT t.*, u.name as assignee_name, u2.name as deleter_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.deleted_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.deleted_at IS NOT NULL ORDER BY t.deleted_at DESC")->fetchAll();
} elseif ($role === 'TEAM_LEAD' || $role === 'DEPT_MANAGER') {
    $team_ids = array_column($my_team, 'id');
    $team_ids[] = $uid;
    $in_ids = implode(',', array_map('intval', $team_ids));
    $recycle_bin = $conn->query("SELECT t.*, u.name as assignee_name, u2.name as deleter_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.deleted_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.deleted_at IS NOT NULL
        AND (t.assigned_to IN ($in_ids) OR t.assigned_by=$uid)
        ORDER BY t.deleted_at DESC")->fetchAll();
} else {
    // Employee: only their own self-created & self-assigned deleted tasks
    $rb = $conn->prepare("SELECT t.*, u.name as assignee_name, u2.name as deleter_name, p.name as project_name
        FROM tasks t
        LEFT JOIN users u  ON u.id=t.assigned_to
        LEFT JOIN users u2 ON u2.id=t.deleted_by
        LEFT JOIN projects p ON p.id=t.project_id
        WHERE t.deleted_at IS NOT NULL AND t.assigned_by=? AND t.assigned_to=?
        ORDER BY t.deleted_at DESC");
    $rb->execute([$uid, $uid]);
    $recycle_bin = $rb->fetchAll();
}

// Activity logs (TL/HR only)
$activity_logs = [];
if ($is_tl || $hr_view) {
    if ($role === 'SUPER_ADMIN' || $hr_view) {
        $activity_logs = $conn->query("SELECT al.*, u.name as actor_name, t.title as task_title
            FROM task_activity_logs al
            JOIN users u ON u.id=al.user_id
            JOIN tasks t ON t.id=al.task_id
            ORDER BY al.created_at DESC LIMIT 200")->fetchAll();
    } elseif ($role === 'TEAM_LEAD' || $role === 'DEPT_MANAGER') {
        $team_ids = array_column($my_team, 'id');
        $team_ids[] = $uid;
        $in_ids = implode(',', array_map('intval', $team_ids));
        $activity_logs = $conn->query("SELECT al.*, u.name as actor_name, t.title as task_title
            FROM task_activity_logs al
            JOIN users u ON u.id=al.user_id
            JOIN tasks t ON t.id=al.task_id
            WHERE t.assigned_to IN ($in_ids) OR t.assigned_by=$uid OR t.to_tl_id=$uid
            ORDER BY al.created_at DESC LIMIT 200")->fetchAll();
    }
}

// Per-member task stats (Team Overview for TL)
$member_stats = [];
$filter_member = isset($_GET['member']) ? (int)$_GET['member'] : 0;
if ($role === 'TEAM_LEAD' && $my_team) {
    foreach ($my_team as $m) {
        $s = $conn->prepare("SELECT status, COUNT(*) as cnt FROM tasks WHERE assigned_to=? AND deleted_at IS NULL AND status NOT IN ('REQUESTED','REJECTED') GROUP BY status");
        $s->execute([$m['id']]);
        $stat_rows = [];
        while ($r = $s->fetch()) $stat_rows[$r['status']] = (int)$r['cnt'];
        $lt = $conn->prepare("SELECT id, title, priority, status, due_date FROM tasks WHERE assigned_to=? AND deleted_at IS NULL AND status NOT IN ('REQUESTED','REJECTED','DONE') ORDER BY FIELD(priority,'URGENT','HIGH','MEDIUM','LOW'), due_date ASC LIMIT 1");
        $lt->execute([$m['id']]);
        $latest = $lt->fetch();
        $member_stats[] = [
            'id'         => $m['id'],
            'name'       => $m['name'],
            'todo'       => $stat_rows['TODO'] ?? 0,
            'inprogress' => $stat_rows['IN_PROGRESS'] ?? 0,
            'review'     => $stat_rows['REVIEW'] ?? 0,
            'done'       => $stat_rows['DONE'] ?? 0,
            'latest'     => $latest ?: null,
        ];
    }
}

include 'header.php';

$priority_color = ['LOW'=>'success','MEDIUM'=>'warning','HIGH'=>'danger','URGENT'=>'dark'];
$status_color   = ['TODO'=>'secondary','IN_PROGRESS'=>'primary','REVIEW'=>'warning','DONE'=>'success','REQUESTED'=>'info','REJECTED'=>'danger','BLOCKED'=>'danger'];
?>

<style>
/* ── Task Management UI ─────────────────────────────────── */
:root {
  --tm-todo:    #64748b;
  --tm-prog:    #3b82f6;
  --tm-review:  #f59e0b;
  --tm-done:    #22c55e;
  --tm-urgent:  #1e293b;
  --tm-high:    #ef4444;
  --tm-medium:  #f59e0b;
  --tm-low:     #22c55e;
}

/* Page header */
.tm-page-header { background:#fff;border-radius:16px;padding:20px 24px;box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:20px; }

/* Stat cards */
.tm-stat { border-radius:14px;padding:18px 22px;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.06);position:relative;overflow:hidden;transition:transform .15s; }
.tm-stat:hover { transform:translateY(-2px); }
.tm-stat::before { content:'';position:absolute;right:-14px;top:-14px;width:60px;height:60px;border-radius:50%;opacity:.08; }
.tm-stat.s-todo::before   { background:var(--tm-todo); }
.tm-stat.s-prog::before   { background:var(--tm-prog); }
.tm-stat.s-review::before { background:var(--tm-review); }
.tm-stat.s-done::before   { background:var(--tm-done); }
.tm-stat.s-req::before    { background:#06b6d4; }
.tm-stat .tm-stat-val  { font-size:1.8rem;font-weight:800;line-height:1; }
.tm-stat .tm-stat-lbl  { font-size:.75rem;color:#64748b;margin-top:4px;font-weight:500; }
.tm-stat .tm-stat-icon { position:absolute;right:18px;top:50%;transform:translateY(-50%);font-size:1.8rem;opacity:.12; }

/* Tabs */
.tm-tabs { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px; }
.tm-tab  { border:none;background:transparent;padding:7px 16px;border-radius:22px;font-size:.82rem;font-weight:500;color:#64748b;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px;text-decoration:none; }
.tm-tab:hover { background:#e2e8f0;color:#1e293b; }
.tm-tab.active { background:#1e293b;color:#fff; }
.tm-tab .tab-badge { background:rgba(255,255,255,.25);color:inherit;border-radius:10px;padding:1px 7px;font-size:.72rem; }
.tm-tab:not(.active) .tab-badge { background:#e2e8f0;color:#64748b; }
.tm-tab.tab-danger { color:#dc3545; }
.tm-tab.tab-danger:hover { background:#fee2e2; }
.tm-tab.tab-danger.active { background:#dc3545;color:#fff; }

/* Search + filter bar */
.tm-toolbar { background:#fff;border-radius:12px;padding:12px 16px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.tm-search { border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px 7px 34px;font-size:.83rem;width:220px;outline:none;transition:border-color .15s; }
.tm-search:focus { border-color:#3b82f6; }
.tm-search-wrap { position:relative; }
.tm-search-wrap i { position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem; }

/* Task cards */
.task-card { border-radius:12px;transition:box-shadow .2s,transform .15s;border:1.5px solid #f1f5f9 !important; }
.task-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.10)!important;transform:translateY(-2px); }
.task-card .priority-stripe { width:4px;border-radius:4px 0 0 4px;position:absolute;left:0;top:8px;bottom:8px; }
.task-card .card-body { padding-left:18px; }

/* Priority stripes */
.stripe-URGENT { background:#1e293b; }
.stripe-HIGH   { background:#ef4444; }
.stripe-MEDIUM { background:#f59e0b; }
.stripe-LOW    { background:#22c55e; }

/* Quick-edit dropdowns */
.qe-select {
    appearance: none;
    border: none;
    outline: none;
    cursor: pointer;
    font-family: var(--font);
    font-size: .7rem;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 22px 3px 10px;
    letter-spacing: .3px;
    background-repeat: no-repeat;
    background-position: right 6px center;
    background-size: 10px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    transition: box-shadow .15s, transform .1s;
}
.qe-select:hover  { box-shadow: 0 0 0 2px rgba(59,130,246,.3); }
.qe-select:focus  { box-shadow: 0 0 0 2px #3b82f6; }
.qe-select.saving { opacity: .5; pointer-events: none; }
.qe-select.saved  { box-shadow: 0 0 0 2px #22c55e; }
.qe-select.error  { box-shadow: 0 0 0 2px #ef4444; }

/* Status dropdown colours */
.sts-select[data-current="TODO"]        { background-color:#f1f5f9; color:#475569; }
.sts-select[data-current="IN_PROGRESS"] { background-color:#dbeafe; color:#1d4ed8; }
.sts-select[data-current="REVIEW"]      { background-color:#fef3c7; color:#92400e; }
.sts-select[data-current="DONE"]        { background-color:#dcfce7; color:#166534; }
.sts-select[data-current="BLOCKED"]     { background-color:#fee2e2; color:#b91c1c; }
/* Priority dropdown colours */
.pri-select[data-current="URGENT"] { background-color:#1e293b; color:#fff; }
.pri-select[data-current="HIGH"]   { background-color:#fee2e2; color:#b91c1c; }
.pri-select[data-current="MEDIUM"] { background-color:#fef3c7; color:#92400e; }
.pri-select[data-current="LOW"]    { background-color:#dcfce7; color:#166534; }

/* Priority badges */
.pri-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:700;letter-spacing:.3px; }
.pri-URGENT { background:#1e293b;color:#fff; }
.pri-HIGH   { background:#fee2e2;color:#b91c1c; }
.pri-MEDIUM { background:#fef3c7;color:#92400e; }
.pri-LOW    { background:#dcfce7;color:#166534; }

/* Status badges */
.sts-badge { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600; }
.sts-TODO        { background:#f1f5f9;color:#475569; }
.sts-IN_PROGRESS { background:#dbeafe;color:#1d4ed8; }
.sts-REVIEW      { background:#fef3c7;color:#92400e; }
.sts-DONE        { background:#dcfce7;color:#166534; }
.sts-REQUESTED   { background:#cffafe;color:#0e7490; }
.sts-REJECTED    { background:#fee2e2;color:#b91c1c; }
.sts-BLOCKED     { background:#fee2e2;color:#b91c1c; }
.sts-REWORK      { background:#fef3c7;color:#92400e; }

/* Blocked banner on cards */
.blocked-banner { background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:5px 9px;margin-top:7px;font-size:.73rem;color:#b91c1c;display:flex;align-items:flex-start;gap:5px; }

/* Kanban board */
.kanban-board  { display:flex;gap:14px;overflow-x:auto;padding-bottom:12px;min-height:calc(100vh - 380px); }
.kanban-col    { flex-shrink:0;width:275px;background:#f8fafc;border-radius:14px;padding:14px; }
.kanban-col-hd { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px; }
.kanban-col-hd .col-title { font-weight:700;font-size:.83rem;display:flex;align-items:center;gap:6px; }
.kanban-col-hd .col-dot   { width:9px;height:9px;border-radius:50%; }
.kanban-col-hd .col-count { background:#e2e8f0;color:#475569;border-radius:10px;padding:1px 9px;font-size:.72rem;font-weight:700; }
.kanban-cards  { display:flex;flex-direction:column;gap:9px; }
.kanban-card   { background:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.07);cursor:grab;transition:box-shadow .15s,transform .1s; }
.kanban-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.12);transform:translateY(-1px); }
.kanban-card.drag-over-col { background:#dde8f7; }
.kanban-card.dragging { opacity:.5; }
.kanban-col.drop-active { background:#eef2ff; }
.kanban-empty { text-align:center;color:#94a3b8;font-size:.78rem;padding:24px 0; }

/* Table improvements */
.tm-table thead th { background:#f8fafc;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0;padding:10px 12px;white-space:nowrap;cursor:pointer; }
.tm-table thead th:hover { color:#1e293b; }
.tm-table tbody tr { transition:background .1s; }
.tm-table tbody tr:hover { background:#f8fafc; }
.tm-table tbody td { padding:11px 12px;vertical-align:middle; }

/* Overdue pill */
.overdue-pill { display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#b91c1c;border-radius:20px;padding:2px 8px;font-size:.65rem;font-weight:700; }

/* Avatar circle */
.av-circle { width:28px;height:28px;border-radius:50%;background:#dbeafe;color:#1d4ed8;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0; }

/* Incoming request cards */
.req-card { border-radius:14px;border:none!important;box-shadow:0 1px 6px rgba(0,0,0,.07);transition:box-shadow .15s; }
.req-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.12); }
.req-card .req-badge { font-size:.68rem;padding:3px 10px;border-radius:20px; }

/* Activity log */
.log-dot { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.log-line { width:2px;flex:1;background:#e2e8f0;margin:3px auto; }

/* View toggle */
.view-btn { background:transparent;border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 12px;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .15s; }
.view-btn.active,.view-btn:hover { background:#1e293b;border-color:#1e293b;color:#fff; }

/* Scrollbar for kanban */
.kanban-board::-webkit-scrollbar { height:5px; }
.kanban-board::-webkit-scrollbar-track { background:#f1f5f9; }
.kanban-board::-webkit-scrollbar-thumb { background:#cbd5e1;border-radius:4px; }

/* Approver info tooltip */
.appr-info-tip { position:relative; }
.appr-info-tip .appr-tip-box {
    display:none;position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);
    background:#1e293b;color:#fff;font-size:.72rem;font-weight:500;
    padding:6px 10px;border-radius:8px;white-space:nowrap;z-index:9999;
    box-shadow:0 4px 16px rgba(0,0,0,.25);pointer-events:none;
    line-height:1.4;
}
.appr-info-tip .appr-tip-box::after {
    content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);
    border:5px solid transparent;border-top-color:#1e293b;
}
.appr-info-tip:hover .appr-tip-box { display:block; }

/* ── Date filter bar ──────────────────────────────────── */
.df-bar { background:#fff;border-radius:12px;padding:10px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
.df-preset { border:1.5px solid #e2e8f0;background:#fff;border-radius:20px;padding:4px 13px;font-size:.75rem;font-weight:600;color:#475569;cursor:pointer;transition:all .15s;white-space:nowrap; }
.df-preset:hover  { border-color:#3b82f6;color:#3b82f6; }
.df-preset.active { background:#1e293b;border-color:#1e293b;color:#fff; }
.df-preset.p-overdue.active { background:#ef4444;border-color:#ef4444; }
.df-preset.p-soon.active    { background:#f59e0b;border-color:#f59e0b; }
.df-date-input { border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 10px;font-size:.78rem;color:#334155;outline:none;transition:border-color .15s;width:130px; }
.df-date-input:focus { border-color:#3b82f6; }
.df-mode-btn { border:1.5px solid #e2e8f0;background:#f8fafc;border-radius:20px;padding:4px 12px;font-size:.73rem;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s; }
.df-mode-btn.active { background:#e0f2fe;border-color:#38bdf8;color:#0369a1; }
.df-clear { background:transparent;border:none;border-radius:20px;padding:4px 10px;font-size:.73rem;color:#94a3b8;cursor:pointer;display:none;align-items:center;gap:4px; }
.df-clear.visible { display:inline-flex; }
.df-clear:hover { color:#ef4444; }
.df-sep { width:1px;height:20px;background:#e2e8f0;flex-shrink:0; }
.df-label { font-size:.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap; }
.df-count { font-size:.72rem;color:#64748b;white-space:nowrap;margin-left:auto; }

/* ── Calendar View ──────────────────────────────────────── */
.cal-wrap { background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden; }
.cal-header { display:flex;align-items:center;justify-content:space-between;padding:16px 20px 12px;border-bottom:1px solid #f1f5f9; }
.cal-nav-btn { background:transparent;border:1.5px solid #e2e8f0;border-radius:8px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#475569;transition:all .15s;font-size:.9rem; }
.cal-nav-btn:hover { background:#1e293b;border-color:#1e293b;color:#fff; }
.cal-month-label { font-size:1.05rem;font-weight:800;color:#1e293b; }
.cal-today-btn { border:1.5px solid #e2e8f0;background:#fff;border-radius:20px;padding:4px 14px;font-size:.75rem;font-weight:600;color:#475569;cursor:pointer;transition:all .15s; }
.cal-today-btn:hover { background:#1e293b;border-color:#1e293b;color:#fff; }
.cal-grid { display:grid;grid-template-columns:repeat(7,1fr); }
.cal-dow { text-align:center;padding:8px 4px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;border-bottom:1px solid #f1f5f9; }
.cal-cell { min-height:110px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:6px 5px 5px;position:relative;vertical-align:top;transition:background .1s; }
.cal-cell:nth-child(7n) { border-right:none; }
.cal-cell:hover { background:#fafbff; }
.cal-cell.cal-today { background:#eff6ff; }
.cal-cell.cal-today .cal-day-num { background:#3b82f6;color:#fff;border-radius:50%; }
.cal-cell.cal-other-month { background:#fafafa; }
.cal-cell.cal-other-month .cal-day-num { color:#c8d0da; }
.cal-day-num { display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;font-size:.8rem;font-weight:700;color:#334155;margin-bottom:4px; }
.cal-task-chip { display:block;border-radius:4px;padding:2px 6px;font-size:.65rem;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;transition:opacity .12s;line-height:1.4;text-decoration:none; }
.cal-task-chip:hover { opacity:.82; }
.cal-chip-TODO        { background:#f1f5f9;color:#475569; }
.cal-chip-IN_PROGRESS { background:#dbeafe;color:#1d4ed8; }
.cal-chip-REVIEW      { background:#fef3c7;color:#92400e; }
.cal-chip-DONE        { background:#dcfce7;color:#166534; }
.cal-chip-BLOCKED     { background:#fee2e2;color:#b91c1c; }
.cal-chip-REWORK      { background:#fff7ed;color:#c2410c; }
.cal-chip-REQUESTED   { background:#cffafe;color:#0e7490; }
.cal-more-btn { display:block;font-size:.62rem;font-weight:700;color:#3b82f6;cursor:pointer;padding:1px 4px;border-radius:4px;border:none;background:transparent;width:100%;text-align:left; }
.cal-more-btn:hover { background:#eff6ff; }
.cal-legend { display:flex;flex-wrap:wrap;gap:10px;padding:12px 16px;border-top:1px solid #f1f5f9;align-items:center; }
.cal-legend-dot { display:inline-flex;align-items:center;gap:5px;font-size:.71rem;font-weight:600;color:#475569; }
.cal-legend-sq { width:10px;height:10px;border-radius:2px;flex-shrink:0; }
/* Filter bar for calendar */
.cal-filter-bar { display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid #f1f5f9; }
.cal-filter-chip { border:1.5px solid #e2e8f0;background:#fff;border-radius:20px;padding:3px 12px;font-size:.72rem;font-weight:600;color:#475569;cursor:pointer;transition:all .15s; }
.cal-filter-chip:hover { border-color:#3b82f6;color:#3b82f6; }
.cal-filter-chip.active { background:#1e293b;border-color:#1e293b;color:#fff; }
</style>

<!-- ── PAGE HEADER ─────────────────────────────────────── -->
<div class="tm-page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <h5 class="fw-bold mb-1" style="font-size:1.15rem;">
            <i class="bi bi-kanban-fill me-2 text-primary"></i>Task Management
        </h5>
        <div class="text-muted" style="font-size:.82rem;">
            <?php if ($hr_view): ?>
                <i class="bi bi-eye me-1"></i>View-only — task progress across all teams
            <?php elseif ($role==='TEAM_LEAD'): ?>
                <i class="bi bi-people me-1"></i>Manage your team's tasks &amp; approve incoming requests
            <?php elseif ($role==='SUPER_ADMIN'): ?>
                <i class="bi bi-shield-check me-1"></i>Full admin access across all teams
            <?php else: ?>
                <i class="bi bi-person-check me-1"></i>Your tasks and cross-team requests
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!$hr_view): ?>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#crossModal" style="border-radius:8px;">
            <i class="bi bi-send me-1"></i> Request to Team
        </button>
        <?php endif; ?>
        <?php if ($is_tl && !$hr_view): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal" style="border-radius:8px;">
            <i class="bi bi-plus-lg me-1"></i> Create Task
        </button>
        <?php endif; ?>
        <?php if (!$is_tl && !$hr_view): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ownTaskModal" style="border-radius:8px;">
            <i class="bi bi-plus-lg me-1"></i> New Task
        </button>
        <?php endif; ?>
    </div>
</div>

<?php
/* ── Flash messages ─────────────────────────── */
global $flash;
if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] ?? 'info' ?> alert-dismissible fade show mb-3" role="alert" style="border-radius:10px;border:none;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <?= htmlspecialchars($flash['msg'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── STATS BAR ──────────────────────────────────────── -->
<?php if ($role === 'TEAM_LEAD' && $own_stats): ?>
<div class="row g-2 mb-2">
    <div class="col-12">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:4px;padding-left:2px;">
            <i class="bi bi-person-fill me-1"></i>My Tasks
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-todo" style="padding:10px 14px;">
            <div class="tm-stat-val" style="color:var(--tm-todo);font-size:1.4rem;"><?= $own_stats['todo'] ?></div>
            <div class="tm-stat-lbl">To Do</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-prog" style="padding:10px 14px;">
            <div class="tm-stat-val" style="color:var(--tm-prog);font-size:1.4rem;"><?= $own_stats['inprogress'] ?></div>
            <div class="tm-stat-lbl">In Progress</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-review" style="padding:10px 14px;">
            <div class="tm-stat-val" style="color:var(--tm-review);font-size:1.4rem;"><?= $own_stats['review'] ?></div>
            <div class="tm-stat-lbl">In Review</div>
        </div>
    </div>
    <?php if ($own_stats['blocked']): ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat" style="background:#fff5f5;padding:10px 14px;">
            <div class="tm-stat-val" style="color:#b91c1c;font-size:1.4rem;"><?= $own_stats['blocked'] ?></div>
            <div class="tm-stat-lbl" style="color:#b91c1c;">Blocked</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-done" style="padding:10px 14px;">
            <div class="tm-stat-val" style="color:var(--tm-done);font-size:1.4rem;"><?= $own_stats['done'] ?></div>
            <div class="tm-stat-lbl">Done</div>
        </div>
    </div>
</div>
<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:4px;margin-top:8px;padding-left:2px;">
    <i class="bi bi-people-fill me-1"></i>Team Tasks
</div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-todo">
            <div class="tm-stat-val" style="color:var(--tm-todo)"><?= $stats['todo'] ?></div>
            <div class="tm-stat-lbl">To Do</div>
            <i class="bi bi-circle tm-stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-prog">
            <div class="tm-stat-val" style="color:var(--tm-prog)"><?= $stats['inprogress'] ?></div>
            <div class="tm-stat-lbl">In Progress</div>
            <i class="bi bi-play-circle-fill tm-stat-icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-review">
            <div class="tm-stat-val" style="color:var(--tm-review)"><?= $stats['review'] ?></div>
            <div class="tm-stat-lbl">In Review</div>
            <i class="bi bi-eye-fill tm-stat-icon"></i>
        </div>
    </div>
    <?php if ($stats['blocked']): ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat" style="background:#fff5f5;">
            <div class="tm-stat-val" style="color:#b91c1c;"><?= $stats['blocked'] ?></div>
            <div class="tm-stat-lbl" style="color:#b91c1c;">Blocked</div>
            <i class="bi bi-exclamation-triangle-fill tm-stat-icon" style="color:#b91c1c!important;"></i>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-done">
            <div class="tm-stat-val" style="color:var(--tm-done)"><?= $stats['done'] ?></div>
            <div class="tm-stat-lbl">Done</div>
            <i class="bi bi-check-circle-fill tm-stat-icon"></i>
        </div>
    </div>
    <?php if ($is_tl): ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat s-req">
            <div class="tm-stat-val" style="color:#06b6d4;"><?= $stats['incoming'] ?></div>
            <div class="tm-stat-lbl">Pending Requests</div>
            <i class="bi bi-inbox-fill tm-stat-icon"></i>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-sm-4 col-md">
        <div class="tm-stat" style="background:linear-gradient(135deg,#1e293b,#334155);cursor:default;">
            <?php
                $total_all = array_sum([$stats['todo'],$stats['inprogress'],$stats['review'],$stats['done']]);
                $done_pct  = $total_all > 0 ? round($stats['done']/$total_all*100) : 0;
            ?>
            <div class="tm-stat-val" style="color:#fff;"><?= $done_pct ?>%</div>
            <div class="tm-stat-lbl" style="color:#94a3b8;">Completion Rate</div>
            <i class="bi bi-bar-chart-fill tm-stat-icon" style="color:#fff!important;opacity:.15;"></i>
        </div>
    </div>
</div>

<!-- ── TABS ───────────────────────────────────────────── -->
<div class="tm-tabs">
    <a class="tm-tab <?= $tab==='my'?'active':'' ?>" href="?tab=my">
        <i class="bi bi-list-task"></i>
        <?= $role==='TEAM_LEAD' ? 'Team Tasks' : ($hr_view ? 'All Tasks' : 'My Tasks') ?>
        <span class="tab-badge"><?= count($my_tasks) ?></span>
    </a>
    <?php if ($role === 'TEAM_LEAD' && $my_team): ?>
    <a class="tm-tab <?= $tab==='team'?'active':'' ?>" href="?tab=team">
        <i class="bi bi-people-fill"></i> Team Overview
        <span class="tab-badge"><?= count($my_team) ?></span>
    </a>
    <?php endif; ?>
    <?php if ($is_tl && !$hr_view): ?>
    <a class="tm-tab <?= $tab==='incoming'?'active':'' ?>" href="?tab=incoming">
        <i class="bi bi-inbox-fill"></i> Incoming
        <?php if ($stats['incoming']): ?>
        <span class="tab-badge" style="background:#ef4444;color:#fff;"><?= $stats['incoming'] ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (!$hr_view): ?>
    <a class="tm-tab <?= $tab==='outgoing'?'active':'' ?>" href="?tab=outgoing">
        <i class="bi bi-send-fill"></i> My Requests
        <span class="tab-badge"><?= count($outgoing) ?></span>
    </a>
    <?php endif; ?>
    <?php if ($is_tl || $hr_view): ?>
    <a class="tm-tab <?= $tab==='logs'?'active':'' ?>" href="?tab=logs">
        <i class="bi bi-clock-history"></i> Activity
        <?php if ($activity_logs): ?><span class="tab-badge"><?= count($activity_logs) ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (!$hr_view): ?>
    <a class="tm-tab <?= $tab==='block_requests'?'active':'' ?>" href="?tab=block_requests"
       style="<?= $stats['block_requests'] ? 'color:#b91c1c;' : '' ?>">
        <i class="bi bi-slash-circle<?= $stats['block_requests'] ? '-fill' : '' ?>"></i> Block Requests
        <?php if ($stats['block_requests']): ?>
        <span class="tab-badge" style="background:#ef4444;color:#fff;"><?= $stats['block_requests'] ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (!$hr_view): ?>
    <a class="tm-tab <?= $tab==='approvals'?'active':'' ?>" href="?tab=approvals"
       style="<?= $stats['approvals'] ? 'color:#7c3aed;font-weight:700;' : '' ?>">
        <i class="bi bi-patch-check-fill"></i> Approvals
        <?php if ($stats['approvals']): ?>
        <span class="tab-badge" style="background:#7c3aed;color:#fff;"><?= $stats['approvals'] ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <a class="tm-tab <?= $tab==='calendar'?'active':'' ?>" href="?tab=calendar">
        <i class="bi bi-calendar3-week-fill"></i> Calendar
    </a>
    <?php if ($recycle_bin || $is_tl || $hr_view): ?>
    <a class="tm-tab tab-danger <?= $tab==='bin'?'active':'' ?>" href="?tab=bin">
        <i class="bi bi-trash3-fill"></i> Bin
        <?php if ($recycle_bin): ?><span class="tab-badge" style="background:#ef4444;color:#fff;"><?= count($recycle_bin) ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<!-- ── TAB: TASKS ─────────────────────────────────────── -->
<?php if ($tab === 'my'): ?>

<?php if ($my_block_requests): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;border-left:4px solid #ef4444!important;">
    <div class="card-header bg-danger-subtle border-0 fw-semibold text-danger" style="border-radius:14px 14px 0 0;">
        <i class="bi bi-slash-circle-fill me-2"></i>Waiting on You
        <span class="badge bg-danger ms-2"><?= count($my_block_requests) ?></span>
    </div>
    <div class="card-body p-0">
        <?php foreach ($my_block_requests as $i => $br): ?>
        <div style="padding:14px 18px;<?= $i > 0 ? 'border-top:1px solid #fee2e2;' : '' ?>display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <a href="task_detail.php?id=<?= $br['task_id'] ?>" class="fw-semibold text-decoration-none text-dark" style="font-size:.9rem;">
                    <i class="bi bi-list-task me-1 text-muted"></i><?= sanitize($br['task_title']) ?>
                </a>
                <div style="font-size:.8rem;color:#64748b;margin-top:4px;">
                    <i class="bi bi-person me-1"></i><?= sanitize($br['requester_name']) ?> is waiting on you
                </div>
                <div style="margin-top:8px;padding:8px 12px;background:#fef2f2;border-radius:8px;font-size:.82rem;color:#7f1d1d;">
                    <?= nl2br(sanitize($br['description'])) ?>
                </div>
            </div>
            <a href="tasks.php?tab=block_requests" class="btn btn-sm btn-danger" style="white-space:nowrap;align-self:center;">
                <i class="bi bi-reply me-1"></i>Respond
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$my_tasks): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-check2-circle" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No tasks found</p>
    <p class="text-muted small">Create a new task to get started.</p>
</div>
<?php else: ?>

<!-- Toolbar row 1: search + dropdowns + view toggle -->
<div class="tm-toolbar">
    <div class="tm-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="tmSearch" class="tm-search" placeholder="Search tasks…">
    </div>
    <select id="tmPriFilter" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:.82rem;">
        <option value="">All Priorities</option>
        <option value="URGENT">Urgent</option>
        <option value="HIGH">High</option>
        <option value="MEDIUM">Medium</option>
        <option value="LOW">Low</option>
    </select>
    <select id="tmStsFilter" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:.82rem;">
        <option value="">All Statuses</option>
        <option value="TODO">To Do</option>
        <option value="IN_PROGRESS">In Progress</option>
        <option value="REVIEW">In Review</option>
        <option value="BLOCKED">Blocked</option>
        <option value="DONE">Done</option>
    </select>
    <select id="tmProjFilter" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:.82rem;">
        <option value="">All Projects</option>
        <?php foreach ($projects as $p): ?>
        <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
        <?php endforeach; ?>
        <option value="0">No Project</option>
    </select>
    <?php if ($my_team): ?>
    <select id="memberFilter" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:.82rem;">
        <option value="">All Members</option>
        <?php if ($role === 'TEAM_LEAD'): ?>
        <option value="MY_TASKS">My Tasks Only</option>
        <?php endif; ?>
        <?php foreach ($my_team as $m): ?>
        <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="view-btn active" data-view="cards" title="Card view"><i class="bi bi-grid-3x3-gap-fill me-1"></i>Cards</button>
        <button type="button" class="view-btn" data-view="table" title="Table view"><i class="bi bi-table me-1"></i>Table</button>
        <button type="button" class="view-btn" data-view="kanban" title="Kanban board"><i class="bi bi-kanban me-1"></i>Board</button>
    </div>
</div>

<!-- Toolbar row 2: date filters -->
<div class="df-bar" id="dfBarTasks">
    <span class="df-label"><i class="bi bi-calendar3 me-1"></i>Date</span>
    <button type="button" class="df-mode-btn active" id="dfModedue" data-mode="due" title="Filter by due date">Due Date</button>
    <button type="button" class="df-mode-btn" id="dfModecreated" data-mode="created" title="Filter by creation date">Created</button>
    <div class="df-sep"></div>
    <button type="button" class="df-preset" data-preset="today">Today</button>
    <button type="button" class="df-preset" data-preset="week">This Week</button>
    <button type="button" class="df-preset" data-preset="month">This Month</button>
    <button type="button" class="df-preset p-overdue" data-preset="overdue"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</button>
    <button type="button" class="df-preset p-soon" data-preset="soon"><i class="bi bi-alarm me-1"></i>Due Soon</button>
    <div class="df-sep"></div>
    <input type="date" id="dfFrom" class="df-date-input" title="From date">
    <span style="color:#94a3b8;font-size:.8rem;">→</span>
    <input type="date" id="dfTo" class="df-date-input" title="To date">
    <button type="button" class="df-clear" id="dfClearTasks"><i class="bi bi-x-circle-fill"></i> Clear</button>
    <span class="df-count" id="dfCountTasks"></span>
</div>

<!-- ── CARDS VIEW ── -->
<div id="viewCards">
<div class="row g-3" id="taskGrid">
<?php foreach ($my_tasks as $t):
    $overdue = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'DONE';
    $tl_stmt = $conn->prepare("SELECT COALESCE(SUM(hours),0) as h FROM task_time_logs WHERE task_id=?");
    $tl_stmt->execute([$t['id']]);
    $logged_hours = (float)$tl_stmt->fetchColumn();
    $pct = $t['estimated_hours'] > 0 ? min(100, round(($logged_hours / $t['estimated_hours']) * 100)) : 0;
    $initials_a = strtoupper(substr($t['assignee_name'] ?? 'U', 0, 1));
    $days_left   = $t['due_date'] ? (int)((strtotime($t['due_date']) - time()) / 86400) : null;
?>
<div class="col-md-6 col-lg-4 task-item" id="task-card-<?= $t['id'] ?>"
     data-assignee-id="<?= (int)$t['assigned_to'] ?>"
     data-assigned-by="<?= (int)$t['assigned_by'] ?>"
     data-assignee-name="<?= strtolower(htmlspecialchars($t['assignee_name'] ?? '')) ?>"
     data-priority="<?= $t['priority'] ?>"
     data-status="<?= $t['status'] ?>"
     data-project="<?= (int)($t['project_id'] ?? 0) ?>"
     data-title="<?= strtolower(htmlspecialchars($t['title'])) ?>"
     data-due="<?= $t['due_date'] ?? '' ?>"
     data-created="<?= substr($t['created_at'],0,10) ?>">
    <div class="card border-0 shadow-sm task-card h-100 position-relative" style="padding-left:4px;">
        <div class="priority-stripe stripe-<?= $t['priority'] ?>"></div>
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="pri-badge pri-<?= $t['priority'] ?>"><i class="bi bi-flag-fill"></i><?= $t['priority'] ?></span>
                <div class="d-flex gap-1 align-items-center">
                    <?php if (!empty($t['unblocked_at']) && strtotime($t['unblocked_at']) > strtotime('-48 hours')): ?>
                    <span class="sts-badge" style="background:#dcfce7;color:#15803d;"><i class="bi bi-shield-check-fill me-1"></i>Unblocked</span>
                    <?php endif; ?>
                    <span class="sts-badge sts-<?= $t['status'] ?>"><?= str_replace('_',' ',$t['status']) ?></span>
                </div>
            </div>
            <h6 class="fw-bold mb-1" style="font-size:.9rem;line-height:1.35;">
                <a href="task_detail.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= sanitize($t['title']) ?></a>
            </h6>
            <?php if ($t['project_name']): ?>
            <div class="text-muted mb-2" style="font-size:.73rem;"><i class="bi bi-folder2-open me-1"></i><?= sanitize($t['project_name']) ?></div>
            <?php endif; ?>
            <?php if ($t['description']): ?>
            <p class="text-muted mb-2" style="font-size:.78rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= sanitize($t['description']) ?></p>
            <?php endif; ?>
            <?php
            $appr_info_c = $task_approver_info[$t['id']] ?? null;
            $appr_tip_c  = $appr_info_c
                ? 'Approver: ' . ($appr_info_c['name'] ?? $appr_info_c['role']) . ($appr_info_c['name'] ? ' (' . $appr_info_c['role'] . ')' : '')
                : 'Approver assigned in workflow';
            ?>
            <?php if ($t['needs_approval'] && !in_array($t['status'], ['DONE','REVIEW'])): ?>
            <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:7px;padding:5px 9px;margin-top:4px;font-size:.72rem;color:#6d28d9;display:flex;align-items:center;gap:6px;position:relative;z-index:2;">
                <i class="bi bi-patch-check-fill"></i>
                <span>This task needs approval</span>
                <span class="appr-info-tip" data-tip="<?= htmlspecialchars($appr_tip_c) ?>" style="cursor:pointer;display:inline-flex;align-items:center;color:#7c3aed;margin-left:2px;">
                    <i class="bi bi-info-circle-fill" style="font-size:.75rem;"></i>
                </span>
                <?php if ($t['assigned_to']==$uid || $t['assigned_by']==$uid): ?>
                <?php
                // Extract fill link from description if this is a DigiOps asset task
                $fill_url_c = null;
                if (!empty($t['description']) && preg_match('/Fill link:\s*(https?:\/\/\S+)/i', $t['description'], $fm_c)) {
                    $fill_url_c = $fm_c[1];
                }
                ?>
                <?php if ($fill_url_c): ?>
                <a href="<?= htmlspecialchars($fill_url_c) ?>" target="_blank" class="ms-auto"
                   style="background:#2563eb;color:#fff;border:none;border-radius:5px;font-size:.68rem;padding:2px 8px;text-decoration:none;display:inline-block;position:relative;z-index:4;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Submit via Fill Link
                </a>
                <?php else: ?>
                <button type="button" class="btn btn-sm py-0 px-2" style="background:#7c3aed;color:#fff;border:none;border-radius:5px;font-size:.68rem;position:relative;z-index:4;"
                    onclick="event.stopPropagation();openCompletionModal(<?= $t['id'] ?>,'<?= addslashes(sanitize($t['title'])) ?>')">Complete</button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($t['status']==='REVIEW' && $t['needs_approval']): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:5px 9px;margin-top:4px;font-size:.72rem;color:#1d4ed8;display:flex;align-items:center;gap:6px;">
                <i class="bi bi-hourglass-split"></i>
                <span>Awaiting approval</span>
                <span class="appr-info-tip" data-tip="<?= htmlspecialchars($appr_tip_c) ?>" style="cursor:pointer;display:inline-flex;align-items:center;color:#3b82f6;margin-left:2px;">
                    <i class="bi bi-info-circle-fill" style="font-size:.75rem;"></i>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($t['status']==='REWORK'): ?>
            <?php
            $rw_c = $rework_notes[$t['id']] ?? null;
            $fill_url_rw_c = null;
            if (!empty($t['description']) && preg_match('/Fill link:\s*(https?:\/\/\S+)/i', $t['description'], $fm_rw_c)) {
                $fill_url_rw_c = $fm_rw_c[1];
            }
            ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:7px;padding:7px 10px;margin-top:6px;font-size:.72rem;color:#9a3412;">
                <div style="display:flex;align-items:center;gap:5px;margin-bottom:<?= ($rw_c && $rw_c['note']) ? '5px' : '0' ?>">
                    <i class="bi bi-arrow-counterclockwise" style="color:#f97316;"></i>
                    <strong>Rework requested<?= $rw_c && $rw_c['reviewer_name'] ? ' by ' . sanitize($rw_c['reviewer_name']) : '' ?></strong>
                    <?php if ($t['assigned_to']==$uid || $t['assigned_by']==$uid): ?>
                    <?php if ($fill_url_rw_c): ?>
                    <a href="<?= htmlspecialchars($fill_url_rw_c) ?>" target="_blank" class="ms-auto"
                       style="background:#ea580c;color:#fff;border:none;border-radius:5px;font-size:.68rem;padding:2px 8px;text-decoration:none;display:inline-block;white-space:nowrap">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Resubmit
                    </a>
                    <?php elseif ($t['needs_approval']): ?>
                    <button type="button" style="background:#ea580c;color:#fff;border:none;border-radius:5px;font-size:.68rem;padding:2px 8px;cursor:pointer;white-space:nowrap"
                        onclick="event.stopPropagation();openCompletionModal(<?= $t['id'] ?>,'<?= addslashes(sanitize($t['title'])) ?>')">Resubmit</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($rw_c && $rw_c['note']): ?>
                <div style="background:#fff;border:1px solid #fed7aa;border-radius:5px;padding:5px 8px;font-size:.71rem;color:#78350f;line-height:1.4;">
                    "<?= sanitize($rw_c['note']) ?>"
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($t['status']==='BLOCKED' && !empty($t['blocked_reason'])): ?>
            <div class="blocked-banner">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                <span><?= sanitize(substr($t['blocked_reason'], 0, 80)) ?><?= strlen($t['blocked_reason'])>80?'…':'' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($t['estimated_hours']): ?>
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;color:#64748b;">
                    <span><i class="bi bi-clock me-1"></i><?= $logged_hours ?>h / <?= $t['estimated_hours'] ?>h</span>
                    <span class="fw-semibold"><?= $pct ?>%</span>
                </div>
                <div class="progress" style="height:4px;border-radius:4px;background:#e2e8f0;">
                    <div class="progress-bar <?= $pct>=100?'bg-success':($pct>=60?'bg-primary':'bg-warning') ?>" style="width:<?= $pct ?>%;border-radius:4px;"></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-auto pt-1" style="border-top:1px solid #f1f5f9;">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($t['assignee_name']): ?>
                    <div class="av-circle"><?= $initials_a ?></div>
                    <span style="font-size:.75rem;color:#475569;"><?= sanitize($t['assignee_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <?php if ($overdue): ?>
                    <span class="overdue-pill"><i class="bi bi-exclamation-triangle-fill"></i>Overdue</span>
                    <?php elseif ($days_left !== null && $days_left <= 3 && $t['status'] !== 'DONE'): ?>
                    <span style="font-size:.7rem;color:#f59e0b;font-weight:600;"><i class="bi bi-calendar2-x me-1"></i><?= $days_left <= 0 ? 'today' : "in {$days_left}d" ?></span>
                    <?php elseif ($t['due_date']): ?>
                    <span style="font-size:.7rem;color:#94a3b8;"><i class="bi bi-calendar3 me-1"></i><?= date('d M', strtotime($t['due_date'])) ?></span>
                    <?php endif; ?>
                    <?php
                    $can_del = !$hr_view && ($is_tl || ($t['assigned_by']==$uid && $t['assigned_to']==$uid));
                    if ($can_del): ?>
                    <button class="btn btn-sm py-0 px-1" style="color:#cbd5e1;border:none;background:transparent;" title="Delete"
                        data-hc-msg="Move to recycle bin?"
                        data-hc-ok="Move to Bin"
                        data-hc-url="tasks.php?_ajax=1&action=delete_task&task_id=<?= $t['id'] ?>"
                        data-hc-method="POST"
                        data-hc-target="#task-card-<?= $t['id'] ?>">
                        <i class="bi bi-trash3"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div id="tmNoResults" class="text-center py-5 d-none">
    <i class="bi bi-search" style="font-size:2.5rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0">No tasks match your filters.</p>
</div>
</div>

<!-- ── TABLE VIEW ── -->
<div id="viewTable" style="display:none;">
<div class="card border-0 shadow-sm" style="border-radius:14px;overflow:visible;">
    <div class="card-body p-0" style="overflow:visible;">
        <div class="table-responsive" style="overflow-x:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y;border-radius:14px;">
        <table class="table tm-table align-middle mb-0" id="taskTableEl" style="min-width:700px;width:100%;">
            <thead>
                <tr>
                    <th class="ps-4" onclick="sortTable(0)">Task <i class="bi bi-chevron-expand" style="font-size:.65rem;"></i></th>
                    <th onclick="sortTable(1)">Assignee <i class="bi bi-chevron-expand" style="font-size:.65rem;"></i></th>
                    <th onclick="sortTable(2)">Priority <i class="bi bi-chevron-expand" style="font-size:.65rem;"></i></th>
                    <th onclick="sortTable(3)">Status <i class="bi bi-chevron-expand" style="font-size:.65rem;"></i></th>
                    <th onclick="sortTable(4)">Due Date <i class="bi bi-chevron-expand" style="font-size:.65rem;"></i></th>
                    <th>Progress</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($my_tasks as $t):
                $overdue_t = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'DONE';
                $tl2 = $conn->prepare("SELECT COALESCE(SUM(hours),0) as h FROM task_time_logs WHERE task_id=?");
                $tl2->execute([$t['id']]);
                $lh2 = (float)$tl2->fetchColumn();
                $pct2 = $t['estimated_hours'] > 0 ? min(100, round(($lh2 / $t['estimated_hours']) * 100)) : 0;
            ?>
            <tr class="task-item" id="task-card-<?= $t['id'] ?>"
                data-assignee-id="<?= (int)$t['assigned_to'] ?>"
                data-assigned-by="<?= (int)$t['assigned_by'] ?>"
                data-assignee-name="<?= strtolower(htmlspecialchars($t['assignee_name'] ?? '')) ?>"
                data-priority="<?= $t['priority'] ?>"
                data-status="<?= $t['status'] ?>"
                data-project="<?= (int)($t['project_id'] ?? 0) ?>"
                data-title="<?= strtolower(htmlspecialchars($t['title'])) ?>"
                data-due="<?= $t['due_date'] ?? '' ?>"
                data-created="<?= substr($t['created_at'],0,10) ?>">
                <td class="ps-4" style="min-width:220px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:3px;height:36px;border-radius:2px;flex-shrink:0;" class="stripe-<?= $t['priority'] ?>"></div>
                        <div>
                            <div class="fw-semibold" style="font-size:.85rem;">
                                <a href="task_detail.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= sanitize($t['title']) ?></a>
                            </div>
                            <?php if ($t['project_name']): ?><div style="font-size:.7rem;color:#94a3b8;"><i class="bi bi-folder me-1"></i><?= sanitize($t['project_name']) ?></div><?php endif; ?>
                            <?php if ($t['status']==='BLOCKED' && !empty($t['blocked_reason'])): ?>
                            <div style="font-size:.7rem;color:#b91c1c;margin-top:3px;"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= sanitize(substr($t['blocked_reason'],0,60)) ?><?= strlen($t['blocked_reason'])>60?'…':'' ?></div>
                            <?php endif; ?>
                            <?php if ($t['status']==='REWORK'):
                                $rw_t = $rework_notes[$t['id']] ?? null; ?>
                            <div style="font-size:.7rem;color:#c2410c;margin-top:3px;display:flex;align-items:center;gap:4px;">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                <span>Rework<?= $rw_t && $rw_t['reviewer_name'] ? ' by '.sanitize($rw_t['reviewer_name']) : '' ?><?= $rw_t && $rw_t['note'] ? ': '.sanitize(substr($rw_t['note'],0,60)).(strlen($rw_t['note'])>60?'…':'') : '' ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($t['assignee_name']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-circle"><?= strtoupper(substr($t['assignee_name'],0,1)) ?></div>
                        <span style="font-size:.82rem;"><?= sanitize($t['assignee_name']) ?></span>
                    </div>
                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                </td>
                <?php
                    $tbl_is_creator = $t['assigned_by'] == $uid;
                    $tbl_can_status = $is_tl || $hr_view || $tbl_is_creator;
                ?>
                <?php $tbl_can_priority = $is_tl || $hr_view; ?>
                <td>
                <?php if ($tbl_can_priority): ?>
                    <select class="qe-select pri-select" data-task="<?= $t['id'] ?>" data-field="priority">
                        <?php foreach (['LOW','MEDIUM','HIGH','URGENT'] as $p): ?>
                        <option value="<?= $p ?>" <?= $t['priority']===$p?'selected':'' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <span class="pri-badge pri-<?= $t['priority'] ?>"><?= $t['priority'] ?></span>
                <?php endif; ?>
                </td>
                <td>
                <?php if ($tbl_can_status): ?>
                    <select class="qe-select sts-select" data-task="<?= $t['id'] ?>" data-field="status">
                        <?php foreach (['TODO','IN_PROGRESS','REVIEW','BLOCKED','DONE'] as $s): ?>
                        <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= str_replace('_',' ',$s) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <span class="sts-badge sts-<?= $t['status'] ?>"><?= str_replace('_',' ',$t['status']) ?></span>
                <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($t['due_date']): ?>
                    <span class="<?= $overdue_t ? 'text-danger fw-semibold' : 'text-muted' ?>" style="font-size:.82rem;">
                        <?= date('d M Y', strtotime($t['due_date'])) ?>
                    </span>
                    <?php if ($overdue_t): ?><span class="overdue-pill ms-1"><i class="bi bi-exclamation-triangle-fill"></i>Overdue</span><?php endif; ?>
                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                </td>
                <td style="min-width:120px;">
                    <?php if ($t['estimated_hours']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:5px;border-radius:4px;background:#e2e8f0;">
                            <div class="progress-bar <?= $pct2>=100?'bg-success':($pct2>=60?'bg-primary':'bg-warning') ?>" style="width:<?= $pct2 ?>%;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:.7rem;color:#64748b;white-space:nowrap;"><?= $pct2 ?>%</span>
                    </div>
                    <?php else: ?><span class="text-muted" style="font-size:.8rem;">—</span><?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="task_detail.php?id=<?= $t['id'] ?>" class="btn btn-sm" style="background:#f1f5f9;border:none;border-radius:6px;color:#475569;"><i class="bi bi-arrow-right-circle"></i></a>
                        <?php $can_del2 = !$hr_view && ($is_tl || ($t['assigned_by']==$uid && $t['assigned_to']==$uid));
                        if ($can_del2): ?>
                        <button class="btn btn-sm" style="background:#fee2e2;border:none;border-radius:6px;color:#b91c1c;"
                            data-hc-msg="Move to recycle bin?"
                            data-hc-ok="Move to Bin"
                            data-hc-url="tasks.php?_ajax=1&action=delete_task&task_id=<?= $t['id'] ?>"
                            data-hc-method="POST"
                            data-hc-target="#task-card-<?= $t['id'] ?>">
                            <i class="bi bi-trash3"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /table-responsive -->
    </div>
</div>
</div>

<!-- ── KANBAN BOARD ── -->
<?php
$kanban_cols = [
    'TODO'        => ['label'=>'To Do',       'dot'=>'#64748b'],
    'IN_PROGRESS' => ['label'=>'In Progress', 'dot'=>'#3b82f6'],
    'REVIEW'      => ['label'=>'In Review',   'dot'=>'#f59e0b'],
    'BLOCKED'     => ['label'=>'Blocked',     'dot'=>'#ef4444'],
    'DONE'        => ['label'=>'Done',         'dot'=>'#22c55e'],
];
?>
<div id="viewKanban" style="display:none;">
<div class="kanban-board" id="kanbanBoard">
<?php foreach ($kanban_cols as $kst => $kc):
    $k_tasks = array_values(array_filter($my_tasks, fn($t) => $t['status'] === $kst));
?>
<div class="kanban-col" data-status="<?= $kst ?>">
    <div class="kanban-col-hd">
        <div class="col-title">
            <span class="col-dot" style="background:<?= $kc['dot'] ?>;"></span>
            <?= $kc['label'] ?>
        </div>
        <span class="col-count kanban-count"><?= count($k_tasks) ?></span>
    </div>
    <div class="kanban-cards" id="kc-<?= $kst ?>">
    <?php foreach ($k_tasks as $t):
        $ov_k = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'DONE';
        $can_drag_k = !$hr_view && $t['status'] !== 'BLOCKED' && ($is_tl || $t['assigned_to']==$uid || $t['assigned_by']==$uid);
    ?>
    <div class="kanban-card task-item <?= $can_drag_k ? 'kb-draggable' : '' ?>"
         data-task-id="<?= $t['id'] ?>"
         data-status="<?= $kst ?>"
         data-assignee-id="<?= (int)$t['assigned_to'] ?>"
         data-assigned-by="<?= (int)$t['assigned_by'] ?>"
         data-assignee-name="<?= strtolower(htmlspecialchars($t['assignee_name'] ?? '')) ?>"
         data-priority="<?= $t['priority'] ?>"
         data-project="<?= (int)($t['project_id'] ?? 0) ?>"
         data-title="<?= strtolower(htmlspecialchars($t['title'])) ?>"
         data-due="<?= $t['due_date'] ?? '' ?>"
         data-created="<?= substr($t['created_at'],0,10) ?>"
         data-needs-approval="<?= $t['needs_approval'] ? '1' : '0' ?>"
         <?= $can_drag_k ? 'draggable="true"' : '' ?>>
        <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="pri-badge pri-<?= $t['priority'] ?>" style="font-size:.63rem;"><?= $t['priority'] ?></span>
            <?php if ($ov_k): ?><span class="overdue-pill"><i class="bi bi-exclamation-triangle-fill"></i>Late</span><?php endif; ?>
        </div>
        <div class="fw-semibold mb-1" style="font-size:.83rem;line-height:1.3;">
            <a href="task_detail.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= sanitize($t['title']) ?></a>
        </div>
        <?php if ($t['project_name']): ?>
        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:6px;"><i class="bi bi-folder2 me-1"></i><?= sanitize($t['project_name']) ?></div>
        <?php endif; ?>
        <?php if ($t['status']==='BLOCKED' && !empty($t['blocked_reason'])): ?>
        <div style="font-size:.68rem;color:#b91c1c;background:#fef2f2;border-radius:5px;padding:4px 7px;margin-bottom:5px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= sanitize(substr($t['blocked_reason'],0,60)) ?><?= strlen($t['blocked_reason'])>60?'…':'' ?>
        </div>
        <?php endif; ?>
        <?php
        $appr_info_k = $task_approver_info[$t['id']] ?? null;
        $appr_tip_k  = $appr_info_k
            ? 'Approver: ' . ($appr_info_k['name'] ?? $appr_info_k['role']) . ($appr_info_k['name'] ? ' (' . $appr_info_k['role'] . ')' : '')
            : 'Approver assigned in workflow';
        $can_submit_k = !$is_tl && !$hr_view && $t['needs_approval']
            && in_array($kst, ['TODO','IN_PROGRESS','REWORK'])
            && ($t['assigned_to']==$uid || $t['assigned_by']==$uid);
        // Extract fill link from description if this is a DigiOps asset task
        $fill_url_k = null;
        if (!empty($t['description']) && preg_match('/Fill link:\s*(https?:\/\/\S+)/i', $t['description'], $fm_k)) {
            $fill_url_k = $fm_k[1];
        }
        ?>
        <?php if ($t['needs_approval'] && $kst !== 'DONE'): ?>
        <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:4px 8px;margin-top:6px;font-size:.68rem;color:#6d28d9;display:flex;align-items:center;gap:5px;">
            <i class="bi bi-patch-check-fill" style="font-size:.65rem;"></i>
            <?php if ($kst === 'REVIEW'): ?>
            <span>Awaiting approval</span>
            <?php else: ?>
            <span>Needs approval</span>
            <?php endif; ?>
            <span class="appr-info-tip" data-tip="<?= htmlspecialchars($appr_tip_k) ?>" style="cursor:pointer;display:inline-flex;align-items:center;color:#7c3aed;">
                <i class="bi bi-info-circle-fill" style="font-size:.68rem;"></i>
            </span>
            <?php if ($can_submit_k): ?>
                <?php if ($fill_url_k): ?>
                <a href="<?= htmlspecialchars($fill_url_k) ?>" target="_blank" class="ms-auto"
                   style="background:#2563eb;color:#fff;border:none;border-radius:5px;font-size:.63rem;padding:2px 8px;text-decoration:none;display:inline-block;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Form
                </a>
                <?php else: ?>
                <button class="btn btn-sm ms-auto" style="background:#7c3aed;color:#fff;border:none;border-radius:5px;font-size:.63rem;padding:2px 8px;"
                    onclick="openCompletionModal(<?= $t['id'] ?>,'<?= addslashes(sanitize($t['title'])) ?>')">Complete</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($kst === 'REWORK'): ?>
        <?php
        $rw_k = $rework_notes[$t['id']] ?? null;
        $fill_url_rw_k = null;
        if (!empty($t['description']) && preg_match('/Fill link:\s*(https?:\/\/\S+)/i', $t['description'], $fm_rw_k)) {
            $fill_url_rw_k = $fm_rw_k[1];
        }
        $can_resub_k = !$hr_view && ($t['assigned_to']==$uid || $t['assigned_by']==$uid);
        ?>
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:6px 8px;margin-top:6px;font-size:.69rem;color:#9a3412;">
            <div style="display:flex;align-items:center;gap:5px;margin-bottom:<?= ($rw_k && $rw_k['note']) ? '4px' : '0' ?>">
                <i class="bi bi-arrow-counterclockwise" style="color:#f97316;"></i>
                <strong>Rework<?= $rw_k && $rw_k['reviewer_name'] ? ' by '.sanitize($rw_k['reviewer_name']) : '' ?></strong>
                <?php if ($can_resub_k): ?>
                    <?php if ($fill_url_rw_k): ?>
                    <a href="<?= htmlspecialchars($fill_url_rw_k) ?>" target="_blank" class="ms-auto"
                       style="background:#ea580c;color:#fff;border-radius:5px;font-size:.63rem;padding:2px 7px;text-decoration:none;">
                        Resubmit
                    </a>
                    <?php elseif ($t['needs_approval']): ?>
                    <button class="ms-auto" style="background:#ea580c;color:#fff;border:none;border-radius:5px;font-size:.63rem;padding:2px 7px;cursor:pointer;"
                        onclick="openCompletionModal(<?= $t['id'] ?>,'<?= addslashes(sanitize($t['title'])) ?>')">Resubmit</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($rw_k && $rw_k['note']): ?>
            <div style="background:#fff;border:1px solid #fed7aa;border-radius:4px;padding:4px 6px;font-size:.68rem;color:#78350f;line-height:1.35;">
                "<?= sanitize($rw_k['note']) ?>"
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mt-2" style="border-top:1px solid #f1f5f9;padding-top:7px;">
            <?php if ($t['assignee_name']): ?>
            <div class="d-flex align-items-center gap-1">
                <div class="av-circle" style="width:22px;height:22px;font-size:.63rem;"><?= strtoupper(substr($t['assignee_name'],0,1)) ?></div>
                <span style="font-size:.7rem;color:#64748b;"><?= sanitize($t['assignee_name']) ?></span>
            </div>
            <?php else: ?><span></span><?php endif; ?>
            <?php if ($t['due_date']): ?>
            <span style="font-size:.68rem;color:<?= $ov_k?'#b91c1c':'#94a3b8' ?>;"><?= date('d M', strtotime($t['due_date'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$k_tasks): ?>
    <div class="kanban-empty kb-empty"><i class="bi bi-inbox me-1"></i>No tasks</div>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>

<?php endif; ?>

<!-- ── TAB: INCOMING REQUESTS ─────────────────────────── -->
<?php elseif ($tab === 'incoming' && $is_tl): ?>

<?php if (!$incoming): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-inbox" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No pending requests</p>
    <p class="text-muted small">All cross-team requests have been handled.</p>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
<?php foreach ($incoming as $t): ?>
<div class="req-card card">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="sts-badge sts-REQUESTED"><i class="bi bi-arrow-down-circle-fill me-1"></i>Incoming</span>
                    <span class="pri-badge pri-<?= $t['priority'] ?>"><?= $t['priority'] ?></span>
                    <?php if ($t['project_name']): ?>
                    <span style="font-size:.75rem;color:#64748b;"><i class="bi bi-folder2-open me-1"></i><?= sanitize($t['project_name']) ?></span>
                    <?php endif; ?>
                </div>
                <h6 class="fw-bold mb-1" style="font-size:.95rem;"><?= sanitize($t['title']) ?></h6>
                <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:.78rem;color:#64748b;">
                    <span><i class="bi bi-person-fill me-1"></i>From <strong style="color:#1e293b;"><?= sanitize($t['requester_name']) ?></strong></span>
                    <?php if ($t['from_dept']): ?><span><i class="bi bi-building me-1"></i><?= sanitize($t['from_dept']) ?></span><?php endif; ?>
                    <span><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($t['created_at'])) ?></span>
                    <?php if ($t['due_date']): ?><span><i class="bi bi-alarm me-1"></i>Due <?= date('d M Y', strtotime($t['due_date'])) ?></span><?php endif; ?>
                    <?php if ($t['estimated_hours']): ?><span><i class="bi bi-clock me-1"></i><?= $t['estimated_hours'] ?>h est.</span><?php endif; ?>
                </div>
                <?php if ($t['description']): ?>
                <p class="mt-2 mb-0" style="font-size:.8rem;color:#475569;background:#f8fafc;border-radius:8px;padding:10px;"><?= sanitize($t['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button class="btn btn-success btn-sm" onclick="openApprove(<?= $t['id'] ?>)" style="border-radius:8px;">
                    <i class="bi bi-check2 me-1"></i> Approve &amp; Assign
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="openReject(<?= $t['id'] ?>)" style="border-radius:8px;">
                    <i class="bi bi-x-lg me-1"></i> Reject
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── TAB: OUTGOING REQUESTS ─────────────────────────── -->
<?php elseif ($tab === 'outgoing'): ?>

<?php if (!$outgoing): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-send" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No outgoing requests</p>
    <p class="text-muted small">You haven't sent any cross-team requests yet.</p>
</div>
<?php else: ?>

<!-- Date filter bar -->
<div class="df-bar">
    <span class="df-label"><i class="bi bi-calendar3 me-1"></i>Date Sent</span>
    <button type="button" class="df-preset" data-dfbar="outgoing" data-preset="today">Today</button>
    <button type="button" class="df-preset" data-dfbar="outgoing" data-preset="week">This Week</button>
    <button type="button" class="df-preset" data-dfbar="outgoing" data-preset="month">This Month</button>
    <div class="df-sep"></div>
    <input type="date" class="df-date-input" id="dfOutFrom" title="From">
    <span style="color:#94a3b8;font-size:.8rem;">→</span>
    <input type="date" class="df-date-input" id="dfOutTo" title="To">
    <button type="button" class="df-clear" id="dfClearOut"><i class="bi bi-x-circle-fill"></i> Clear</button>
    <span class="df-count" id="dfCountOut"></span>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;overflow:visible;">
    <div class="card-body p-0" style="overflow:visible;">
        <div class="table-responsive" style="overflow-x:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y;border-radius:14px;">
        <table class="table tm-table align-middle mb-0" id="outgoingTable" style="min-width:700px;width:100%;">
            <thead>
                <tr>
                    <th class="ps-4">Task</th>
                    <th>Sent To (TL)</th>
                    <th>Assigned To</th>
                    <th>Priority</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Date Sent</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($outgoing as $t): ?>
            <tr class="out-item" data-date="<?= substr($t['created_at'],0,10) ?>">
                <td class="ps-4">
                    <div class="fw-semibold" style="font-size:.85rem;">
                        <?php if (!in_array($t['status'],['REQUESTED','REJECTED'])): ?>
                        <a href="task_detail.php?id=<?= $t['id'] ?>" style="text-decoration:none;color:var(--text-primary);"><?= sanitize($t['title']) ?></a>
                        <?php else: ?>
                        <?= sanitize($t['title']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($t['project_name']): ?><div style="font-size:.7rem;color:#94a3b8;"><i class="bi bi-folder me-1"></i><?= sanitize($t['project_name']) ?></div><?php endif; ?>
                    <?php if (!empty($t['needs_approval'])): ?><div style="font-size:.68rem;color:#7c3aed;margin-top:2px;"><i class="bi bi-patch-check-fill me-1"></i>Requires approval</div><?php endif; ?>
                </td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-circle"><?= strtoupper(substr($t['tl_name']??'?',0,1)) ?></div>
                        <span style="font-size:.82rem;"><?= sanitize($t['tl_name'] ?? '—') ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($t['assignee_name']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-circle" style="background:#7c3aed;"><?= strtoupper(substr($t['assignee_name'],0,1)) ?></div>
                        <span style="font-size:.82rem;"><?= sanitize($t['assignee_name']) ?></span>
                    </div>
                    <?php else: ?>
                    <span style="font-size:.78rem;color:#94a3b8;">Pending</span>
                    <?php endif; ?>
                </td>
                <td><span class="pri-badge pri-<?= $t['priority'] ?>"><?= $t['priority'] ?></span></td>
                <td><span style="font-size:.82rem;color:#64748b;"><?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?></span></td>
                <td>
                    <span class="sts-badge sts-<?= $t['status'] ?>"><?= str_replace('_',' ',$t['status']) ?></span>
                    <?php if ($t['status']==='REJECTED' && $t['reject_reason']): ?>
                    <div style="font-size:.73rem;color:#b91c1c;margin-top:3px;"><i class="bi bi-info-circle me-1"></i><?= sanitize($t['reject_reason']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#94a3b8;"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /table-responsive -->
        <div id="outNoResults" class="text-center py-4 d-none" style="color:#94a3b8;font-size:.85rem;">
            <i class="bi bi-funnel me-1"></i>No requests match the selected date range.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: ACTIVITY LOGS ─────────────────────────────── -->
<?php elseif ($tab === 'logs' && ($is_tl || $hr_view)): ?>

<?php
$action_meta = [
    'CREATED'        => ['icon'=>'plus-circle-fill',        'color'=>'22c55e', 'label'=>'created task'],
    'STATUS_CHANGED' => ['icon'=>'arrow-right-circle-fill', 'color'=>'3b82f6', 'label'=>'changed status'],
    'TIME_LOGGED'    => ['icon'=>'stopwatch-fill',          'color'=>'06b6d4', 'label'=>'logged time'],
    'COMMENTED'      => ['icon'=>'chat-dots-fill',          'color'=>'64748b', 'label'=>'added comment'],
    'DELETED'        => ['icon'=>'trash-fill',              'color'=>'ef4444', 'label'=>'deleted task'],
    'RESTORED'       => ['icon'=>'arrow-counterclockwise',  'color'=>'22c55e', 'label'=>'restored task'],
    'APPROVED'       => ['icon'=>'check-circle-fill',       'color'=>'22c55e', 'label'=>'approved request'],
    'REJECTED'       => ['icon'=>'x-circle-fill',           'color'=>'ef4444', 'label'=>'rejected request'],
    'BLOCKED'        => ['icon'=>'exclamation-triangle-fill','color'=>'ef4444', 'label'=>'blocked task'],
    'UNBLOCKED'      => ['icon'=>'shield-check-fill',       'color'=>'22c55e', 'label'=>'unblocked task'],
];
?>

<?php if (!$activity_logs): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-clock-history" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No activity yet</p>
</div>
<?php else: ?>

<!-- Date filter bar -->
<div class="df-bar">
    <span class="df-label"><i class="bi bi-calendar3 me-1"></i>Activity Date</span>
    <button type="button" class="df-preset" data-dfbar="logs" data-preset="today">Today</button>
    <button type="button" class="df-preset" data-dfbar="logs" data-preset="week">This Week</button>
    <button type="button" class="df-preset" data-dfbar="logs" data-preset="month">This Month</button>
    <div class="df-sep"></div>
    <input type="date" class="df-date-input" id="dfLogFrom" title="From">
    <span style="color:#94a3b8;font-size:.8rem;">→</span>
    <input type="date" class="df-date-input" id="dfLogTo" title="To">
    <button type="button" class="df-clear" id="dfClearLog"><i class="bi bi-x-circle-fill"></i> Clear</button>
    <span class="df-count" id="dfCountLog"></span>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-4" id="logTimeline">
    <?php foreach ($activity_logs as $i => $log):
        $meta = $action_meta[$log['action']] ?? ['icon'=>'dot','color'=>'64748b','label'=>strtolower($log['action'])];
        $is_last = $i === count($activity_logs) - 1;
    ?>
    <div class="d-flex gap-3 log-item" data-date="<?= substr($log['created_at'],0,10) ?>"
         style="<?= !$is_last ? 'padding-bottom:16px;margin-bottom:0;' : '' ?>">
        <div class="d-flex flex-column align-items-center flex-shrink-0" style="width:34px;">
            <div class="log-dot" style="background:#<?= $meta['color'] ?>1a;">
                <i class="bi bi-<?= $meta['icon'] ?>" style="color:#<?= $meta['color'] ?>;font-size:.82rem;"></i>
            </div>
            <?php if (!$is_last): ?><div class="log-line"></div><?php endif; ?>
        </div>
        <div style="flex:1;min-width:0;padding-bottom:<?= !$is_last?'16px':'0' ?>;">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div style="font-size:.83rem;">
                    <span class="fw-semibold text-dark"><?= sanitize($log['actor_name']) ?></span>
                    <span style="color:#64748b;"> <?= $meta['label'] ?></span>
                    <?php if ($log['detail']): ?>
                    <span style="color:#94a3b8;font-size:.75rem;"> — <?= sanitize($log['detail']) ?></span>
                    <?php endif; ?>
                </div>
                <span style="font-size:.7rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;"><?= time_ago($log['created_at']) ?></span>
            </div>
            <div style="margin-top:3px;">
                <a href="task_detail.php?id=<?= $log['task_id'] ?>" class="text-decoration-none" style="font-size:.76rem;color:#3b82f6;">
                    <i class="bi bi-arrow-right me-1"></i><?= sanitize($log['task_title']) ?>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div id="logNoResults" class="text-center py-4 d-none" style="color:#94a3b8;font-size:.85rem;">
        <i class="bi bi-funnel me-1"></i>No activity in the selected date range.
    </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: BLOCK REQUESTS ───────────────────────────────── -->
<?php elseif ($tab === 'block_requests' && !$hr_view): ?>

<?php
$type_icon  = ['document'=>'file-earmark-arrow-down','link'=>'link-45deg','text'=>'chat-square-text','action'=>'check2-square'];
$type_label = ['document'=>'Document / File','link'=>'Link / URL','text'=>'Text / Approval','action'=>'Action only'];
?>

<?php if (!$my_block_requests): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-slash-circle" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No pending block requests</p>
    <p class="text-muted small">When someone needs your help to unblock a task, it will appear here.</p>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
<?php foreach ($my_block_requests as $br): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;border-left:4px solid #ef4444!important;">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge bg-danger rounded-pill"><i class="bi bi-slash-circle me-1"></i>Block Request</span>
                    <span class="badge bg-secondary rounded-pill">
                        <i class="bi bi-<?= $type_icon[$br['request_type']] ?> me-1"></i><?= $type_label[$br['request_type']] ?>
                    </span>
                    <span style="font-size:.75rem;color:#64748b;"><i class="bi bi-clock me-1"></i><?= date('d M Y, h:i A', strtotime($br['created_at'])) ?></span>
                </div>
                <h6 class="fw-bold mb-1">
                    <a href="task_detail.php?id=<?= $br['task_id'] ?>" class="text-decoration-none text-dark">
                        <i class="bi bi-list-task me-1 text-muted"></i><?= sanitize($br['task_title']) ?>
                    </a>
                </h6>
                <div class="mb-2" style="font-size:.82rem;color:#475569;">
                    <i class="bi bi-person-fill me-1"></i>Requested by <strong><?= sanitize($br['requester_name']) ?></strong>
                    <?php if ($br['specific_person']): ?>
                    → specifically for <strong><?= sanitize($br['specific_person']) ?></strong>
                    <?php endif; ?>
                </div>
                <div class="p-3 rounded mb-0" style="background:#fef2f2;border:1px solid #fecaca;font-size:.85rem;">
                    <div class="fw-semibold text-danger mb-1"><i class="bi bi-exclamation-circle me-1"></i>What they need:</div>
                    <?= nl2br(sanitize($br['description'])) ?>
                </div>
            </div>

            <!-- Response form -->
            <div style="min-width:280px;max-width:340px;">
                <div class="card border-0" style="background:#f8fafc;border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="fw-semibold small mb-3"><i class="bi bi-reply me-1 text-success"></i>Your Response</div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="resolve_block_request">
                            <input type="hidden" name="br_id" value="<?= $br['id'] ?>">

                            <?php if ($br['request_type'] === 'document'): ?>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Upload File <span class="text-danger">*</span></label>
                                <input type="file" name="response_file" class="form-control form-control-sm" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Note <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" name="response_text" class="form-control form-control-sm" placeholder="Any notes about the file…">
                            </div>
                            <?php elseif ($br['request_type'] === 'link'): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Paste the Link <span class="text-danger">*</span></label>
                                <input type="url" name="response_link" class="form-control form-control-sm" placeholder="https://…" required>
                            </div>
                            <?php elseif ($br['request_type'] === 'text'): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Your Response / Approval <span class="text-danger">*</span></label>
                                <textarea name="response_text" class="form-control form-control-sm" rows="3"
                                          placeholder="Write your approval, feedback, or answer…" required></textarea>
                            </div>
                            <?php else: /* action */ ?>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Confirmation Note <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" name="response_text" class="form-control form-control-sm" placeholder="e.g. Done — I've set up the account.">
                            </div>
                            <?php endif; ?>

                            <button class="btn btn-success btn-sm w-100">
                                <i class="bi bi-check-circle me-1"></i> Mark as Done &amp; Unblock Task
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── TAB: APPROVALS ─────────────────────────────────── -->
<?php elseif ($tab === 'approvals' && !$hr_view): ?>

<?php if ($is_tl): ?>
<!-- TL/Manager view: pending approvals from their team -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h6 class="fw-bold mb-0" style="font-size:.95rem;"><i class="bi bi-patch-check-fill text-primary me-2"></i>Pending Approvals</h6>
        <p class="text-muted small mb-0 mt-1">Tasks submitted by your team waiting for your review</p>
    </div>
</div>

<?php if (!$pending_approvals): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-patch-check" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No pending approvals</p>
    <p class="text-muted small">When team members submit tasks for review, they appear here.</p>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3" id="approvalsList">
<?php foreach ($pending_approvals as $t):
    $submitted_ago = time_ago($t['submitted_at']);
    $overdue_a = $t['due_date'] && $t['due_date'] < date('Y-m-d');
?>
<div class="card border-0 shadow-sm" style="border-radius:14px;border-left:4px solid #7c3aed!important;">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div style="flex:1;min-width:0;">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="sts-badge sts-REVIEW"><i class="bi bi-eye-fill me-1"></i>Awaiting Approval</span>
                    <span class="pri-badge pri-<?= $t['priority'] ?>"><?= $t['priority'] ?></span>
                    <?php if ($t['project_name']): ?>
                    <span style="font-size:.75rem;color:#64748b;"><i class="bi bi-folder2-open me-1"></i><?= sanitize($t['project_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($overdue_a): ?><span class="overdue-pill"><i class="bi bi-exclamation-triangle-fill"></i>Overdue</span><?php endif; ?>
                </div>
                <h6 class="fw-bold mb-1" style="font-size:.98rem;">
                    <a href="task_detail.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= sanitize($t['title']) ?></a>
                </h6>
                <?php if ($t['description']): ?>
                <p class="text-muted small mb-2" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= sanitize($t['description']) ?></p>
                <?php endif; ?>
                <?php if (!empty($t['completion_note'])): ?>
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:10px;">
                    <div style="font-size:.72rem;font-weight:700;color:#166534;margin-bottom:4px;">
                        <i class="bi bi-pencil-square me-1"></i>Completion Note from <?= sanitize($t['submitter_name'] ?? 'assignee') ?>
                    </div>
                    <div style="font-size:.83rem;color:#14532d;line-height:1.5;white-space:pre-wrap;"><?= sanitize($t['completion_note']) ?></div>
                </div>
                <?php else: ?>
                <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.76rem;color:#92400e;">
                    <i class="bi bi-exclamation-triangle me-1"></i>No completion note provided.
                </div>
                <?php endif; ?>
                <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:.78rem;color:#64748b;">
                    <span><i class="bi bi-person-fill me-1"></i>Submitted by <strong style="color:#1e293b;"><?= sanitize($t['submitter_name'] ?? '—') ?></strong></span>
                    <span><i class="bi bi-clock me-1"></i><?= $submitted_ago ?></span>
                    <?php if ($t['due_date']): ?>
                    <span style="color:<?= $overdue_a?'#b91c1c':'#64748b' ?>;"><i class="bi bi-calendar3 me-1"></i>Due <?= date('d M Y', strtotime($t['due_date'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-shrink-0 align-items-start">
                <button class="btn btn-success btn-sm" style="border-radius:8px;"
                    onclick="tmAjax('approve_task_hrms',<?= $t['id'] ?>,this)">
                    <i class="bi bi-check2-circle me-1"></i> Approve
                </button>
                <button class="btn btn-outline-warning btn-sm" style="border-radius:8px;"
                        onclick="openReworkModal(<?= $t['id'] ?>, '<?= addslashes(sanitize($t['title'])) ?>')">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Rework
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Also show recently resolved approvals (last 20) -->
<?php
$recent_resolved = [];
if ($role === 'SUPER_ADMIN') {
    $rr = $conn->query("SELECT t.title, t.id as task_id, ta.status as approval_status, ta.reviewed_at, ta.note,
        u.name as submitter_name, u2.name as reviewer_name
        FROM task_approvals ta
        JOIN tasks t ON t.id = ta.task_id
        LEFT JOIN users u ON u.id = ta.submitted_by
        LEFT JOIN users u2 ON u2.id = ta.reviewed_by
        WHERE ta.status IN ('approved','rework') AND t.deleted_at IS NULL
        ORDER BY ta.reviewed_at DESC LIMIT 20");
} else {
    $team_ids_str2 = $my_team ? implode(',', array_map('intval', array_column($my_team, 'id'))) : '0';
    $rr = $conn->query("SELECT t.title, t.id as task_id, ta.status as approval_status, ta.reviewed_at, ta.note,
        u.name as submitter_name, u2.name as reviewer_name
        FROM task_approvals ta
        JOIN tasks t ON t.id = ta.task_id
        LEFT JOIN users u ON u.id = ta.submitted_by
        LEFT JOIN users u2 ON u2.id = ta.reviewed_by
        WHERE ta.status IN ('approved','rework') AND t.deleted_at IS NULL
        AND (t.assigned_to IN ($team_ids_str2) OR t.assigned_by = $uid)
        ORDER BY ta.reviewed_at DESC LIMIT 20");
}
$recent_resolved = $rr->fetchAll();
?>
<?php if ($recent_resolved): ?>
<div class="mt-4">
    <h6 class="fw-bold mb-3" style="font-size:.85rem;color:#64748b;text-transform:uppercase;letter-spacing:.5px;"><i class="bi bi-clock-history me-1"></i>Recent Decisions</h6>
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
        <div class="card-body p-0">
            <table class="table tm-table align-middle mb-0">
                <thead><tr>
                    <th class="ps-4">Task</th>
                    <th>Submitted By</th>
                    <th>Decision</th>
                    <th>Reviewed</th>
                    <th>Note</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent_resolved as $rr_row): ?>
                <tr>
                    <td class="ps-4">
                        <a href="task_detail.php?id=<?= $rr_row['task_id'] ?>" class="text-decoration-none fw-semibold text-dark" style="font-size:.85rem;"><?= sanitize($rr_row['title']) ?></a>
                    </td>
                    <td style="font-size:.82rem;"><?= sanitize($rr_row['submitter_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($rr_row['approval_status'] === 'approved'): ?>
                        <span class="sts-badge sts-DONE"><i class="bi bi-check-circle-fill me-1"></i>Approved</span>
                        <?php else: ?>
                        <span class="sts-badge sts-REWORK"><i class="bi bi-arrow-counterclockwise me-1"></i>Rework</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;color:#94a3b8;"><?= $rr_row['reviewed_at'] ? time_ago($rr_row['reviewed_at']) : '—' ?></td>
                    <td style="font-size:.78rem;color:#64748b;"><?= sanitize($rr_row['note'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Employee/assignee view: my submitted tasks + status -->
<?php
$apr_filter = $_GET['apr'] ?? 'pending';
$pending_count  = count(array_filter($my_submitted_approvals, fn($r) => $r['approval_status'] === 'pending'));
$approved_count = count(array_filter($my_submitted_approvals, fn($r) => $r['approval_status'] === 'approved'));
$rework_count   = count(array_filter($my_submitted_approvals, fn($r) => $r['approval_status'] === 'rework'));
$filtered_approvals = array_filter($my_submitted_approvals, fn($r) => $r['approval_status'] === $apr_filter);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h6 class="fw-bold mb-0" style="font-size:.95rem;"><i class="bi bi-patch-check-fill text-primary me-2"></i>My Approval Requests</h6>
        <p class="text-muted small mb-0 mt-1">Tasks you have submitted for approval</p>
    </div>
</div>

<!-- Nav filters -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
    <a href="?tab=approvals&apr=pending"
       style="display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
              <?= $apr_filter==='pending' ? 'background:#fef3c7;color:#92400e;border:1.5px solid #fbbf24' : 'background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0' ?>">
        <i class="bi bi-hourglass-split"></i> Pending
        <?php if ($pending_count): ?><span style="background:<?= $apr_filter==='pending'?'#f59e0b':'#cbd5e1' ?>;color:#fff;border-radius:10px;padding:0 6px;font-size:10px"><?= $pending_count ?></span><?php endif; ?>
    </a>
    <a href="?tab=approvals&apr=approved"
       style="display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
              <?= $apr_filter==='approved' ? 'background:#dcfce7;color:#166534;border:1.5px solid #4ade80' : 'background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0' ?>">
        <i class="bi bi-check-circle-fill"></i> Approved
        <?php if ($approved_count): ?><span style="background:<?= $apr_filter==='approved'?'#16a34a':'#cbd5e1' ?>;color:#fff;border-radius:10px;padding:0 6px;font-size:10px"><?= $approved_count ?></span><?php endif; ?>
    </a>
    <a href="?tab=approvals&apr=rework"
       style="display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
              <?= $apr_filter==='rework' ? 'background:#fee2e2;color:#991b1b;border:1.5px solid #f87171' : 'background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0' ?>">
        <i class="bi bi-arrow-counterclockwise"></i> Rework
        <?php if ($rework_count): ?><span style="background:<?= $apr_filter==='rework'?'#dc2626':'#cbd5e1' ?>;color:#fff;border-radius:10px;padding:0 6px;font-size:10px"><?= $rework_count ?></span><?php endif; ?>
    </a>
</div>

<?php if (!$filtered_approvals): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-send-check" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">
        <?php if ($apr_filter === 'pending'): ?>No tasks waiting for approval
        <?php elseif ($apr_filter === 'approved'): ?>No approved tasks yet
        <?php else: ?>No tasks need rework
        <?php endif; ?>
    </p>
    <?php if ($apr_filter === 'pending' && !$my_submitted_approvals): ?>
    <p class="text-muted small">When a task needs approval, click <strong>Complete</strong> on the task card to send it for review.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
    <div class="card-body p-0">
        <table class="table tm-table align-middle mb-0">
            <thead><tr>
                <th class="ps-4">Task</th>
                <th>Project</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Note</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filtered_approvals as $ap): ?>
            <tr>
                <td class="ps-4">
                    <a href="task_detail.php?id=<?= $ap['id'] ?>" class="text-decoration-none fw-semibold text-dark" style="font-size:.85rem;"><?= sanitize($ap['title']) ?></a>
                </td>
                <td style="font-size:.82rem;color:#64748b;"><?= sanitize($ap['project_name'] ?? '—') ?></td>
                <td>
                    <?php if ($ap['approval_status'] === 'pending'): ?>
                    <span class="sts-badge sts-REVIEW"><i class="bi bi-hourglass-split me-1"></i>Waiting</span>
                    <?php elseif ($ap['approval_status'] === 'rework'): ?>
                    <span class="sts-badge sts-REWORK"><i class="bi bi-arrow-counterclockwise me-1"></i>Rework Needed</span>
                    <?php elseif ($ap['approval_status'] === 'approved'): ?>
                    <span class="sts-badge sts-DONE"><i class="bi bi-check-circle-fill me-1"></i>Approved</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#94a3b8;"><?= time_ago($ap['submitted_at']) ?></td>
                <td style="font-size:.78rem;color:<?= $ap['approval_status']==='rework'?'#b91c1c':'#94a3b8' ?>;"><?= sanitize($ap['approval_note'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── TAB: RECYCLE BIN ────────────────────────────────── -->
<?php elseif ($tab === 'bin'): ?>

<?php if (!$recycle_bin): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-trash3" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">Recycle bin is empty</p>
    <p class="text-muted small">Deleted tasks will appear here for recovery.</p>
</div>
<?php else: ?>

<!-- Date filter bar -->
<div class="df-bar">
    <span class="df-label"><i class="bi bi-calendar3 me-1"></i>Deleted</span>
    <button type="button" class="df-preset" data-dfbar="bin" data-preset="today">Today</button>
    <button type="button" class="df-preset" data-dfbar="bin" data-preset="week">This Week</button>
    <button type="button" class="df-preset" data-dfbar="bin" data-preset="month">This Month</button>
    <div class="df-sep"></div>
    <input type="date" class="df-date-input" id="dfBinFrom" title="From">
    <span style="color:#94a3b8;font-size:.8rem;">→</span>
    <input type="date" class="df-date-input" id="dfBinTo" title="To">
    <button type="button" class="df-clear" id="dfClearBin"><i class="bi bi-x-circle-fill"></i> Clear</button>
    <span class="df-count" id="dfCountBin"></span>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;overflow:visible;">
    <div class="card-body p-0" style="overflow:visible;">
        <div class="table-responsive" style="overflow-x:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y;border-radius:14px;">
        <table class="table tm-table align-middle mb-0" id="binTable" style="min-width:700px;width:100%;">
            <thead>
                <tr>
                    <th class="ps-4">Task</th>
                    <th>Assignee</th>
                    <th>Priority</th>
                    <th>Deleted By</th>
                    <th>Deleted At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recycle_bin as $t): ?>
            <tr class="bin-item" id="bin-row-<?= $t['id'] ?>" data-date="<?= substr($t['deleted_at'],0,10) ?>" style="opacity:.8;">
                <td class="ps-4">
                    <div class="fw-semibold text-muted" style="font-size:.85rem;text-decoration:line-through;text-decoration-color:#cbd5e1;"><?= sanitize($t['title']) ?></div>
                    <?php if ($t['project_name']): ?>
                    <div style="font-size:.7rem;color:#94a3b8;"><i class="bi bi-folder me-1"></i><?= sanitize($t['project_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($t['assignee_name']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-circle" style="background:#f1f5f9;color:#64748b;"><?= strtoupper(substr($t['assignee_name'],0,1)) ?></div>
                        <span style="font-size:.82rem;color:#64748b;"><?= sanitize($t['assignee_name']) ?></span>
                    </div>
                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                </td>
                <td><span class="pri-badge pri-<?= $t['priority'] ?>"><?= $t['priority'] ?></span></td>
                <td style="font-size:.82rem;color:#64748b;"><?= sanitize($t['deleter_name'] ?? '—') ?></td>
                <td style="font-size:.78rem;color:#94a3b8;"><?= date('d M Y, g:i A', strtotime($t['deleted_at'])) ?></td>
                <td>
                    <div class="d-flex gap-2">
                    <?php
                    $can_restore_btn = !$hr_view && ($is_tl || ($t['assigned_by']==$uid && $t['assigned_to']==$uid));
                    if ($can_restore_btn): ?>
                    <button class="btn btn-sm" style="background:#dcfce7;color:#166534;border:none;border-radius:7px;"
                        data-hc-msg="Restore this task?"
                        data-hc-ok="Restore" data-hc-safe="1"
                        data-hc-url="tasks.php?_ajax=1&action=restore_task&task_id=<?= $t['id'] ?>"
                        data-hc-method="POST"
                        data-hc-target="#bin-row-<?= $t['id'] ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                    </button>
                    <?php endif; ?>
                    <?php if ($role === 'SUPER_ADMIN'): ?>
                    <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:7px;"
                        data-hc-msg="Permanently delete? This cannot be undone."
                        data-hc-ok="Delete Forever"
                        data-hc-url="tasks.php?_ajax=1&action=purge_task&task_id=<?= $t['id'] ?>"
                        data-hc-method="POST"
                        data-hc-target="#bin-row-<?= $t['id'] ?>">
                        <i class="bi bi-trash3-fill"></i>
                    </button>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /table-responsive -->
        <div id="binNoResults" class="text-center py-4 d-none" style="color:#94a3b8;font-size:.85rem;">
            <i class="bi bi-funnel me-1"></i>No deleted tasks in the selected date range.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: TEAM OVERVIEW ──────────────────────────────── -->
<?php elseif ($tab === 'team' && $role === 'TEAM_LEAD'): ?>

<?php if (!$member_stats): ?>
<div class="text-center py-5 bg-white rounded-3 shadow-sm">
    <i class="bi bi-people" style="font-size:3rem;color:#cbd5e1;"></i>
    <p class="text-muted mt-3 mb-0 fw-semibold">No team members yet</p>
    <p class="text-muted small">Add members via the Teams section.</p>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($member_stats as $ms):
    $total  = $ms['todo'] + $ms['inprogress'] + $ms['review'] + $ms['done'];
    $active = $ms['todo'] + $ms['inprogress'] + $ms['review'];
    $pct    = $total > 0 ? round(($ms['done'] / $total) * 100) : 0;
    $initials_m = strtoupper(substr($ms['name'], 0, 1));
    $avatar_colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#22c55e','#06b6d4','#f97316'];
    $av_color = $avatar_colors[abs(crc32($ms['name'])) % count($avatar_colors)];
?>
<div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
        <div class="card-body p-4">
            <!-- Member header -->
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="d-flex align-items-center justify-content-center fw-bold"
                     style="width:46px;height:46px;border-radius:14px;background:<?= $av_color ?>20;color:<?= $av_color ?>;font-size:1.15rem;flex-shrink:0;">
                    <?= $initials_m ?>
                </div>
                <div style="min-width:0;">
                    <div class="fw-bold" style="font-size:.92rem;"><?= sanitize($ms['name']) ?></div>
                    <div style="font-size:.75rem;color:#64748b;"><?= $active ?> active &middot; <?= $total ?> total tasks</div>
                </div>
                <div class="ms-auto text-end">
                    <div class="fw-bold" style="font-size:1.3rem;color:<?= $pct>=80?'#22c55e':($pct>=40?'#f59e0b':'#64748b') ?>;"><?= $pct ?>%</div>
                    <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">done</div>
                </div>
            </div>

            <!-- Progress bar -->
            <div class="mb-3" style="background:#e2e8f0;height:6px;border-radius:6px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=80?'#22c55e':($pct>=40?'#f59e0b':'#3b82f6') ?>;border-radius:6px;transition:width .3s;"></div>
            </div>

            <!-- Status badges -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="sts-badge sts-TODO"><i class="bi bi-circle me-1"></i><?= $ms['todo'] ?> Todo</span>
                <span class="sts-badge sts-IN_PROGRESS"><i class="bi bi-play-circle-fill me-1"></i><?= $ms['inprogress'] ?> Active</span>
                <span class="sts-badge sts-REVIEW"><i class="bi bi-eye-fill me-1"></i><?= $ms['review'] ?> Review</span>
                <span class="sts-badge sts-DONE"><i class="bi bi-check-circle-fill me-1"></i><?= $ms['done'] ?> Done</span>
            </div>

            <!-- Latest task -->
            <?php if ($ms['latest']): $lt = $ms['latest']; $lt_overdue = $lt['due_date'] && $lt['due_date'] < date('Y-m-d'); ?>
            <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;border:1px solid #e2e8f0;">
                <div style="font-size:.68rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Top Priority Task</div>
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <a href="task_detail.php?id=<?= $lt['id'] ?>" class="text-decoration-none text-dark fw-semibold" style="font-size:.82rem;line-height:1.3;"><?= sanitize($lt['title']) ?></a>
                    <span class="pri-badge pri-<?= $lt['priority'] ?>" style="flex-shrink:0;font-size:.6rem;"><?= $lt['priority'] ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="sts-badge sts-<?= $lt['status'] ?>" style="font-size:.62rem;"><?= str_replace('_',' ',$lt['status']) ?></span>
                    <?php if ($lt['due_date']): ?>
                    <span style="font-size:.7rem;color:<?= $lt_overdue?'#b91c1c':'#94a3b8' ?>;">
                        <?php if ($lt_overdue): ?><i class="bi bi-exclamation-triangle-fill me-1"></i>OVERDUE · <?php endif; ?>
                        <?= date('d M', strtotime($lt['due_date'])) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="background:#f0fdf4;border-radius:10px;padding:10px 12px;text-align:center;">
                <i class="bi bi-check2-all" style="color:#22c55e;"></i>
                <span style="font-size:.8rem;color:#166534;margin-left:6px;font-weight:600;">All caught up!</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-4">
            <a href="?tab=my&member=<?= $ms['id'] ?>" class="btn btn-sm w-100" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:500;">
                <i class="bi bi-list-task me-1"></i> View All Tasks
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── TAB: CALENDAR ─────────────────────────────────────── -->
<?php elseif ($tab === 'calendar'): ?>

<?php
// Build a keyed map: date => [ tasks ]
$cal_tasks_by_date = [];
foreach ($my_tasks as $ct) {
    if (!empty($ct['due_date'])) {
        $cal_tasks_by_date[$ct['due_date']][] = $ct;
    }
}
// Pass tasks to JS as JSON
$cal_tasks_json = json_encode(array_values(array_map(function($t) {
    return [
        'id'       => (int)$t['id'],
        'title'    => $t['title'],
        'due'      => $t['due_date'],
        'status'   => $t['status'],
        'priority' => $t['priority'],
        'assignee' => $t['assignee_name'] ?? '',
        'project'  => $t['project_name'] ?? '',
    ];
}, $my_tasks)));
?>

<div class="cal-wrap">
    <!-- Calendar top controls -->
    <div class="cal-header">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="cal-nav-btn" id="calPrev"><i class="bi bi-chevron-left"></i></button>
            <button type="button" class="cal-today-btn" id="calToday">Today</button>
            <button type="button" class="cal-nav-btn" id="calNext"><i class="bi bi-chevron-right"></i></button>
        </div>
        <span class="cal-month-label" id="calMonthLabel"></span>
        <div class="d-flex gap-2">
            <button type="button" class="cal-today-btn" id="calViewMonth" style="font-size:.72rem;">Month</button>
            <button type="button" class="cal-today-btn" id="calViewWeek" style="font-size:.72rem;">Week</button>
        </div>
    </div>

    <!-- Status filter bar -->
    <div class="cal-filter-bar" id="calFilterBar">
        <span style="font-size:.71rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Filter:</span>
        <button type="button" class="cal-filter-chip active" data-status="">All</button>
        <button type="button" class="cal-filter-chip" data-status="TODO">To Do</button>
        <button type="button" class="cal-filter-chip" data-status="IN_PROGRESS">In Progress</button>
        <button type="button" class="cal-filter-chip" data-status="REVIEW">Review</button>
        <button type="button" class="cal-filter-chip" data-status="DONE">Done</button>
        <button type="button" class="cal-filter-chip" data-status="BLOCKED">Blocked</button>
        <span id="calTaskCount" style="margin-left:auto;font-size:.72rem;color:#64748b;"></span>
    </div>

    <!-- Calendar grid -->
    <div id="calGrid"></div>

    <!-- Legend -->
    <div class="cal-legend">
        <span class="cal-legend-dot"><span class="cal-legend-sq" style="background:#f1f5f9;border:1px solid #cbd5e1;"></span>To Do</span>
        <span class="cal-legend-dot"><span class="cal-legend-sq" style="background:#dbeafe;"></span>In Progress</span>
        <span class="cal-legend-dot"><span class="cal-legend-sq" style="background:#fef3c7;"></span>Review</span>
        <span class="cal-legend-dot"><span class="cal-legend-sq" style="background:#dcfce7;"></span>Done</span>
        <span class="cal-legend-dot"><span class="cal-legend-sq" style="background:#fee2e2;"></span>Blocked/Rework</span>
        <span class="cal-legend-dot" style="margin-left:auto;color:#94a3b8;font-size:.68rem;"><i class="bi bi-info-circle me-1"></i>Click a task chip to open details</span>
    </div>
</div>

<!-- Task tooltip / popover (shown on chip click) -->
<div id="calPopover" style="display:none;position:fixed;z-index:9999;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:14px 16px;min-width:230px;max-width:300px;pointer-events:auto;">
    <div id="calPopTitle" style="font-size:.88rem;font-weight:700;color:#1e293b;margin-bottom:6px;"></div>
    <div id="calPopMeta" style="font-size:.73rem;color:#64748b;line-height:1.7;"></div>
    <div class="d-flex gap-2 mt-2">
        <a id="calPopLink" href="#" class="btn btn-sm btn-primary" style="border-radius:7px;font-size:.75rem;flex:1;text-align:center;">Open Task</a>
        <button onclick="document.getElementById('calPopover').style.display='none'" class="btn btn-sm" style="border-radius:7px;font-size:.75rem;background:#f1f5f9;border:none;color:#475569;">✕</button>
    </div>
</div>

<script>
(function () {
    const allTasks  = <?= $cal_tasks_json ?>;
    let curYear     = new Date().getFullYear();
    let curMonth    = new Date().getMonth(); // 0-indexed
    let curView     = 'month'; // 'month' | 'week'
    let curWeekDate = new Date();
    let filterStatus = '';

    const grid      = document.getElementById('calGrid');
    const label     = document.getElementById('calMonthLabel');
    const countEl   = document.getElementById('calTaskCount');
    const popover   = document.getElementById('calPopover');

    const DOW_LABELS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const MONTHS     = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];

    function iso(d) {
        // Returns YYYY-MM-DD for a Date object
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    function tasksForDate(dateStr) {
        return allTasks.filter(t => {
            if (filterStatus && t.status !== filterStatus) return false;
            return t.due === dateStr;
        });
    }

    function chipClass(status) {
        return 'cal-task-chip cal-chip-' + status;
    }

    function renderChips(dateStr, cell, maxShow) {
        const tasks = tasksForDate(dateStr);
        if (!tasks.length) return;
        const show = tasks.slice(0, maxShow);
        const rest = tasks.length - maxShow;
        show.forEach(t => {
            const a = document.createElement('a');
            a.href = '#';
            a.className = chipClass(t.status);
            a.title = t.title + (t.assignee ? ' — ' + t.assignee : '');
            a.textContent = t.title.length > 22 ? t.title.slice(0,20)+'…' : t.title;
            a.dataset.taskId = t.id;
            a.addEventListener('click', e => { e.preventDefault(); showPopover(t, e); });
            cell.appendChild(a);
        });
        if (rest > 0) {
            const btn = document.createElement('button');
            btn.className = 'cal-more-btn';
            btn.textContent = '+' + rest + ' more';
            btn.addEventListener('click', () => showAllModal(dateStr, tasksForDate(dateStr)));
            cell.appendChild(btn);
        }
    }

    function renderMonth() {
        label.textContent = MONTHS[curMonth] + ' ' + curYear;
        const firstDay = new Date(curYear, curMonth, 1).getDay();
        const daysInMonth = new Date(curYear, curMonth+1, 0).getDate();
        const daysInPrev  = new Date(curYear, curMonth, 0).getDate();
        const todayStr    = iso(new Date());

        let html = '<div class="cal-grid">';
        // Day-of-week headers
        DOW_LABELS.forEach(d => { html += '<div class="cal-dow">' + d + '</div>'; });

        // Cells
        let totalCells = firstDay + daysInMonth;
        totalCells = Math.ceil(totalCells / 7) * 7;

        html += '</div><div class="cal-grid" id="calCells">';
        for (let i = 0; i < totalCells; i++) {
            let d, isOther = false, dateStr = '';
            if (i < firstDay) {
                d = daysInPrev - firstDay + i + 1;
                const prev = new Date(curYear, curMonth-1, d);
                dateStr = iso(prev);
                isOther = true;
            } else if (i >= firstDay + daysInMonth) {
                d = i - firstDay - daysInMonth + 1;
                const next = new Date(curYear, curMonth+1, d);
                dateStr = iso(next);
                isOther = true;
            } else {
                d = i - firstDay + 1;
                dateStr = curYear + '-' + String(curMonth+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            }
            const isToday = dateStr === todayStr;
            const cls = 'cal-cell' + (isOther ? ' cal-other-month' : '') + (isToday ? ' cal-today' : '');
            html += '<div class="' + cls + '" data-date="' + dateStr + '"><span class="cal-day-num">' + d + '</span></div>';
        }
        html += '</div>';

        grid.innerHTML = html;

        // Inject chips
        grid.querySelectorAll('.cal-cell').forEach(cell => {
            const dateStr = cell.dataset.date;
            if (dateStr) renderChips(dateStr, cell, 3);
        });

        updateCount();
    }

    function renderWeek() {
        // Find Sunday of the week containing curWeekDate
        const d = new Date(curWeekDate);
        d.setDate(d.getDate() - d.getDay());
        const weekStart = new Date(d);
        const todayStr = iso(new Date());

        const dates = [];
        for (let i = 0; i < 7; i++) {
            const dd = new Date(weekStart);
            dd.setDate(weekStart.getDate() + i);
            dates.push(dd);
        }

        const startLabel = MONTHS[dates[0].getMonth()].slice(0,3) + ' ' + dates[0].getDate();
        const endLabel   = MONTHS[dates[6].getMonth()].slice(0,3) + ' ' + dates[6].getDate() + ', ' + dates[6].getFullYear();
        label.textContent = startLabel + ' – ' + endLabel;

        let html = '<div class="cal-grid">';
        dates.forEach(dd => {
            const dateStr = iso(dd);
            const isToday = dateStr === todayStr;
            html += '<div class="cal-dow" style="padding:8px 6px;">'
                + DOW_LABELS[dd.getDay()]
                + '<br><span style="font-size:.9rem;font-weight:700;color:' + (isToday ? '#3b82f6' : '#334155') + ';">' + dd.getDate() + '</span>'
                + '</div>';
        });
        html += '</div><div class="cal-grid" id="calCells">';
        dates.forEach(dd => {
            const dateStr = iso(dd);
            const isToday = dateStr === todayStr;
            const cls = 'cal-cell' + (isToday ? ' cal-today' : '');
            html += '<div class="' + cls + '" data-date="' + dateStr + '" style="min-height:160px;"></div>';
        });
        html += '</div>';

        grid.innerHTML = html;
        grid.querySelectorAll('.cal-cell').forEach(cell => {
            const dateStr = cell.dataset.date;
            if (dateStr) renderChips(dateStr, cell, 8);
        });

        updateCount();
    }

    function render() {
        if (curView === 'month') renderMonth();
        else renderWeek();
    }

    function updateCount() {
        let total = 0;
        const seenDates = new Set();
        grid.querySelectorAll('.cal-cell').forEach(cell => {
            const d = cell.dataset.date;
            if (d && !seenDates.has(d)) {
                seenDates.add(d);
                total += tasksForDate(d).length;
            }
        });
        countEl.textContent = total + ' task' + (total !== 1 ? 's' : '') + ' with due dates';
    }

    function showPopover(task, e) {
        document.getElementById('calPopTitle').textContent = task.title;
        let meta = '';
        if (task.assignee) meta += '<i class="bi bi-person-fill me-1"></i>' + task.assignee + '<br>';
        if (task.project)  meta += '<i class="bi bi-folder me-1"></i>' + task.project + '<br>';
        meta += '<i class="bi bi-calendar3 me-1"></i>Due: ' + (task.due || '—') + '<br>';
        meta += '<i class="bi bi-circle-fill me-1" style="font-size:.5rem;vertical-align:middle;"></i>Status: ' + task.status.replace('_',' ') + '<br>';
        meta += '<i class="bi bi-flag-fill me-1"></i>Priority: ' + task.priority;
        document.getElementById('calPopMeta').innerHTML = meta;
        document.getElementById('calPopLink').href = 'task_detail.php?id=' + task.id;
        const pop = popover;
        pop.style.display = 'block';
        const vw = window.innerWidth, vh = window.innerHeight;
        let x = e.clientX + 12, y = e.clientY + 12;
        if (x + 310 > vw) x = e.clientX - 320;
        if (y + 160 > vh) y = e.clientY - 170;
        pop.style.left = x + 'px';
        pop.style.top  = y + 'px';
    }

    function showAllModal(dateStr, tasks) {
        // Simple inline list shown via alert-style bottom sheet — can be replaced by modal
        const names = tasks.map(t => '• ' + t.title + ' [' + t.status.replace('_',' ') + ']').join('\n');
        alert('Tasks due on ' + dateStr + ':\n\n' + names);
    }

    // Navigation
    document.getElementById('calPrev').addEventListener('click', () => {
        if (curView === 'month') { curMonth--; if (curMonth < 0) { curMonth = 11; curYear--; } }
        else { curWeekDate.setDate(curWeekDate.getDate() - 7); }
        render();
    });
    document.getElementById('calNext').addEventListener('click', () => {
        if (curView === 'month') { curMonth++; if (curMonth > 11) { curMonth = 0; curYear++; } }
        else { curWeekDate.setDate(curWeekDate.getDate() + 7); }
        render();
    });
    document.getElementById('calToday').addEventListener('click', () => {
        const now = new Date();
        curYear = now.getFullYear(); curMonth = now.getMonth();
        curWeekDate = new Date();
        render();
    });

    // View toggle
    document.getElementById('calViewMonth').addEventListener('click', () => {
        curView = 'month';
        document.getElementById('calViewMonth').style.background = '#1e293b';
        document.getElementById('calViewMonth').style.color = '#fff';
        document.getElementById('calViewWeek').style.background = '';
        document.getElementById('calViewWeek').style.color = '';
        render();
    });
    document.getElementById('calViewWeek').addEventListener('click', () => {
        curView = 'week';
        document.getElementById('calViewWeek').style.background = '#1e293b';
        document.getElementById('calViewWeek').style.color = '#fff';
        document.getElementById('calViewMonth').style.background = '';
        document.getElementById('calViewMonth').style.color = '';
        render();
    });

    // Status filter
    document.querySelectorAll('#calFilterBar .cal-filter-chip').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#calFilterBar .cal-filter-chip').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterStatus = this.dataset.status;
            render();
        });
    });

    // Close popover on outside click
    document.addEventListener('click', e => {
        if (!popover.contains(e.target) && !e.target.closest('.cal-task-chip')) {
            popover.style.display = 'none';
        }
    });

    // Initial render
    render();
})();
</script>

<?php endif; ?>

<!-- ── MODALS ──────────────────────────────────────────── -->

<!-- Create Task Modal (TL / Admin only) -->
<?php if ($is_tl && !$hr_view): ?>
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" id="createTaskForm" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Create &amp; Assign Task</h5>
                    <p class="text-muted small mb-0 mt-1">Assign a new task to a team member</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Task Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Build login API endpoint" style="border-radius:8px;">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="What needs to be done, acceptance criteria, notes…" style="border-radius:8px;"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Assign To <span class="text-danger">*</span></label>
                        <select name="assigned_to" class="form-select" required style="border-radius:8px;">
                            <option value="">— Select Team Member —</option>
                            <option value="<?= $uid ?>"><?= sanitize($u['name']) ?> (Me)</option>
                            <?php foreach ($my_team as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Project</label>
                        <select name="project_id" class="form-select" style="border-radius:8px;">
                            <option value="">— No Project —</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Priority</label>
                        <select name="priority" class="form-select" style="border-radius:8px;">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM" selected>Medium</option>
                            <option value="HIGH">High</option>
                            <option value="URGENT">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Due Date</label>
                        <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>" style="border-radius:8px;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Est. Hours</label>
                        <input type="number" name="estimated_hours" class="form-control" step="0.5" min="0.5" placeholder="e.g. 8" style="border-radius:8px;">
                    </div>
                    <div class="col-12">
                        <div class="form-check" style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin:0;">
                            <input class="form-check-input" type="checkbox" name="needs_approval" value="1" id="createNeedsApproval" style="width:18px;height:18px;margin-top:2px;">
                            <label class="form-check-label fw-semibold ms-2" for="createNeedsApproval" style="font-size:.83rem;cursor:pointer;">
                                <i class="bi bi-patch-check-fill text-primary me-1"></i> Requires Approval
                                <div class="text-muted fw-normal mt-1" style="font-size:.75rem;">Assignee must submit for review. You approve/reject before it can be marked done.</div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="button" class="btn btn-primary" id="createTaskBtn" style="border-radius:8px;" onclick="submitCreateTask(this)"><i class="bi bi-send me-1"></i> Create &amp; Assign</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Cross-Team Request Modal -->
<?php if (!$hr_view): ?>
<div class="modal fade" id="crossModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" name="action" value="cross_request">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="bi bi-send-fill text-primary me-2"></i>Request from Another Team</h5>
                    <p class="text-muted small mb-0 mt-1">Send a task request to another team lead</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Task Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Need API integration help" style="border-radius:8px;">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Description / Requirements</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Explain what you need, why, and any constraints…" style="border-radius:8px;"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Send To (Team Lead) <span class="text-danger">*</span></label>
                        <select name="to_tl_id" class="form-select" required style="border-radius:8px;">
                            <option value="">— Select Team Lead —</option>
                            <?php foreach ($all_tls as $tl): if ($tl['id'] != $uid): ?>
                            <option value="<?= $tl['id'] ?>"><?= sanitize($tl['name']) ?><?= $tl['dept_name'] ? ' — '.$tl['dept_name'] : '' ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Your Department</label>
                        <select name="from_dept_id" class="form-select" style="border-radius:8px;">
                            <option value="">— Select —</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Project</label>
                        <select name="project_id" class="form-select" style="border-radius:8px;">
                            <option value="">— No Project —</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Priority</label>
                        <select name="priority" class="form-select" style="border-radius:8px;">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM" selected>Medium</option>
                            <option value="HIGH">High</option>
                            <option value="URGENT">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Due Date</label>
                        <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>" style="border-radius:8px;">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:8px;"><i class="bi bi-send me-1"></i> Send Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Create Own Task Modal (Employee) -->
<?php if (!$is_tl && !$hr_view): ?>
<div class="modal fade" id="ownTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" name="action" value="create_own_task">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Create My Task</h5>
                    <p class="text-muted small mb-0 mt-1">Track your own work and progress</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Task Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="What do you need to get done?" style="border-radius:8px;">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Details, steps, acceptance criteria…" style="border-radius:8px;"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Project</label>
                        <select name="project_id" class="form-select" style="border-radius:8px;">
                            <option value="">— No Project —</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Priority</label>
                        <select name="priority" class="form-select" style="border-radius:8px;">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM" selected>Medium</option>
                            <option value="HIGH">High</option>
                            <option value="URGENT">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Due Date</label>
                        <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>" style="border-radius:8px;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Est. Hours</label>
                        <input type="number" name="estimated_hours" class="form-control" step="0.5" min="0.5" placeholder="e.g. 4" style="border-radius:8px;">
                    </div>
                    <div class="col-12">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;background:#f8fafc;transition:border-color .15s;" id="ownApprovalLabel">
                            <input type="checkbox" name="needs_approval" value="1" id="ownNeedsApproval" style="width:18px;height:18px;flex-shrink:0;cursor:pointer;" onchange="document.getElementById('ownApprovalLabel').style.borderColor=this.checked?'#7c3aed':'#e2e8f0';">
                            <div>
                                <div style="font-size:.84rem;font-weight:600;"><i class="bi bi-patch-check-fill text-primary me-1"></i>Require Approval</div>
                                <div style="font-size:.72rem;color:#64748b;">Ask your Team Lead to approve this task before it can be marked done.</div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:8px;"><i class="bi bi-check-lg me-1"></i> Create Task</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Approve & Assign Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" id="approve_task_id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0" style="color:#166534;"><i class="bi bi-check-circle-fill me-2"></i>Approve &amp; Assign</h5>
                    <p class="text-muted small mb-0 mt-1">Assign this request to one of your team members</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Assign To <span class="text-danger">*</span></label>
                    <select id="approve_assigned_to" class="form-select" style="border-radius:8px;">
                        <option value="">— Select Team Member —</option>
                        <option value="<?= $uid ?>"><?= sanitize($u['name']) ?> (Me)</option>
                        <?php foreach ($my_team as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check" style="padding-left:0;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--body-bg);">
                        <input type="checkbox" id="approve_needs_approval" value="1" style="width:18px;height:18px;flex-shrink:0;cursor:pointer;">
                        <div>
                            <div style="font-size:.84rem;font-weight:600;">Requires Approval</div>
                            <div style="font-size:.72rem;color:var(--text-muted);">Assignee must submit for review before marking done</div>
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="button" class="btn btn-success" style="border-radius:8px;" onclick="submitApproveRequest(this)"><i class="bi bi-check2 me-1"></i> Approve &amp; Assign</button>
            </div>
        </div>
    </div>
</div>

<!-- Needs Approval Modal (shown when dragging to DONE) -->
<div class="modal fade" id="needsApprovalModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <div class="modal-body px-4 pt-4 pb-3 text-center">
                <div style="width:56px;height:56px;background:#f5f3ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="bi bi-patch-check-fill" style="font-size:1.5rem;color:#7c3aed;"></i>
                </div>
                <h6 class="fw-bold mb-2" style="font-size:1rem;">This task needs approval</h6>
                <p class="text-muted mb-0" style="font-size:.83rem;">Use the <strong>Complete</strong> button on the task card to submit it for review. It will be marked done once approved.</p>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 justify-content-center">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius:8px;padding:8px 28px;">Got it</button>
            </div>
        </div>
    </div>
</div>

<!-- Completion Note Modal (required before submitting for approval) -->
<div class="modal fade" id="completionNoteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" id="cn_task_id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0" style="color:#166534;"><i class="bi bi-pencil-square me-2"></i>Completion Note</h5>
                    <p class="text-muted small mb-0 mt-1" id="cn_task_title"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <label class="form-label fw-semibold" style="font-size:.82rem;">
                    What did you complete? <span class="text-danger">*</span>
                </label>
                <textarea id="cn_note" class="form-control" rows="4" required
                    placeholder="Describe what you did, what was delivered, and any relevant details for the reviewer…"
                    style="border-radius:8px;resize:vertical;"></textarea>
                <div id="cn_error" class="text-danger mt-1" style="font-size:.78rem;display:none;">
                    <i class="bi bi-exclamation-circle me-1"></i>Please enter a completion note before submitting.
                </div>
                <div class="mt-2 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.74rem;color:#166534;">
                    <i class="bi bi-info-circle me-1"></i>This note will be shown to your reviewer and saved in the task history.
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="button" class="btn btn-success" style="border-radius:8px;" id="cn_submit_btn"
                    onclick="submitCompletionNote(this)">
                    <i class="bi bi-send-check me-1"></i>Submit for Review
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Rework Modal (for approvals) -->
<div class="modal fade" id="reworkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" id="rework_task_id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0" style="color:#92400e;"><i class="bi bi-arrow-counterclockwise me-2"></i>Send for Rework</h5>
                    <p class="text-muted small mb-0 mt-1" id="rework_task_title"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Feedback / What needs to change <span class="text-muted fw-normal">(optional)</span></label>
                <textarea id="rework_note" class="form-control" rows="3" placeholder="Explain what the assignee needs to fix or improve…" style="border-radius:8px;"></textarea>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="button" class="btn btn-warning" style="border-radius:8px;color:#fff;" onclick="submitRework(this)"><i class="bi bi-arrow-counterclockwise me-1"></i> Send for Rework</button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <input type="hidden" id="reject_task_id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0 text-danger"><i class="bi bi-x-circle-fill me-2"></i>Reject Request</h5>
                    <p class="text-muted small mb-0 mt-1">Let the requester know why this can't be done</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Reason <span class="text-danger">*</span></label>
                <textarea id="reject_reason" class="form-control" rows="3" placeholder="Explain why this request is being rejected…" style="border-radius:8px;"></textarea>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#f1f5f9;border:none;border-radius:8px;color:#475569;">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;" onclick="submitRejectRequest(this)"><i class="bi bi-x-lg me-1"></i> Reject</button>
            </div>
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────
// DATE HELPERS
// ─────────────────────────────────────────────────────────
function isoToday()  { return new Date().toISOString().slice(0,10); }
function isoYesterday() {
    const d = new Date(); d.setDate(d.getDate()-1); return d.toISOString().slice(0,10);
}
function isoWeekStart() {
    const d = new Date();
    const day = d.getDay(), diff = d.getDate() - day + (day===0?-6:1);
    return new Date(d.setDate(diff)).toISOString().slice(0,10);
}
function isoWeekEnd() {
    const d = new Date();
    const day = d.getDay(), diff = d.getDate() + (day===0?0:7-day);
    return new Date(d.setDate(diff)).toISOString().slice(0,10);
}
function isoMonthStart() {
    const d = new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-01';
}
function isoMonthEnd() {
    const d = new Date(new Date().getFullYear(), new Date().getMonth()+1, 0);
    return d.toISOString().slice(0,10);
}
function isoNDaysLater(n) {
    const d = new Date(); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10);
}

// Generic row/card filter by data-date attribute
function applyDateRange(selector, fromVal, toVal, countEl, noResEl) {
    let vis = 0;
    document.querySelectorAll(selector).forEach(el => {
        const d = el.dataset.date || '';
        const show = (!fromVal || !d || d >= fromVal) && (!toVal || !d || d <= toVal);
        el.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    if (countEl) countEl.textContent = vis > 0 ? vis + ' shown' : '';
    if (noResEl) noResEl.classList.toggle('d-none', vis > 0);
    return vis;
}

// ─────────────────────────────────────────────────────────
// QUICK EDIT — status / priority dropdowns in table view
// ─────────────────────────────────────────────────────────
(function () {
    const STS_COLORS = {
        TODO:        { bg:'#f1f5f9', color:'#475569' },
        IN_PROGRESS: { bg:'#dbeafe', color:'#1d4ed8' },
        REVIEW:      { bg:'#fef3c7', color:'#92400e' },
        DONE:        { bg:'#dcfce7', color:'#166534' },
        BLOCKED:     { bg:'#fee2e2', color:'#b91c1c' },
    };
    const PRI_COLORS = {
        URGENT: { bg:'#1e293b', color:'#fff'     },
        HIGH:   { bg:'#fee2e2', color:'#b91c1c'  },
        MEDIUM: { bg:'#fef3c7', color:'#92400e'  },
        LOW:    { bg:'#dcfce7', color:'#166534'  },
    };

    function applySelectColors(sel) {
        const val    = sel.value;
        const colors = sel.classList.contains('sts-select') ? STS_COLORS : PRI_COLORS;
        const c      = colors[val];
        if (c) { sel.style.backgroundColor = c.bg; sel.style.color = c.color; }
    }

    function initSelect(sel) {
        applySelectColors(sel);
        sel.addEventListener('change', function () {
            const taskId = this.dataset.task;
            const field  = this.dataset.field;
            const value  = this.value;
            const prev   = this.dataset.prev || this.options[this.selectedIndex === 0 ? 0 : this.selectedIndex - 1]?.value;
            this.dataset.prev = value;

            this.classList.add('saving');

            const fd = new FormData();
            fd.append('action',  'quick_edit');
            fd.append('task_id', taskId);
            fd.append('field',   field);
            fd.append('value',   value);

            fetch('tasks.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    this.classList.remove('saving');
                    if (data.error) {
                        this.classList.add('error');
                        this.value = prev;
                        applySelectColors(this);
                        setTimeout(() => this.classList.remove('error'), 2000);
                        alert(data.error);
                    } else {
                        this.classList.add('saved');
                        applySelectColors(this);
                        // Update the row's data-status so card/kanban filters stay in sync
                        const row = this.closest('tr');
                        if (row && field === 'status')   row.dataset.status   = value;
                        if (row && field === 'priority') row.dataset.priority = value;
                        setTimeout(() => this.classList.remove('saved'), 1500);
                    }
                })
                .catch(() => {
                    this.classList.remove('saving');
                    this.classList.add('error');
                    this.value = prev;
                    applySelectColors(this);
                    setTimeout(() => this.classList.remove('error'), 2000);
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.qe-select').forEach(sel => {
            sel.dataset.prev = sel.value;
            initSelect(sel);
        });
    });
})();

// ─────────────────────────────────────────────────────────
// VIEW TOGGLE (Cards / Table / Kanban)
// ─────────────────────────────────────────────────────────
(function () {
    var vCards  = document.getElementById('viewCards');
    var vTable  = document.getElementById('viewTable');
    var vKanban = document.getElementById('viewKanban');
    var btns    = document.querySelectorAll('.view-btn');
    if (!vCards || !vTable || !vKanban) return;

    function switchView(v) {
        vCards.style.display  = (v === 'cards')  ? '' : 'none';
        vTable.style.display  = (v === 'table')  ? '' : 'none';
        vKanban.style.display = (v === 'kanban') ? '' : 'none';
        btns.forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-view') === v);
        });
        try { localStorage.setItem('taskView2', v); } catch(e) {}
    }

    btns.forEach(function(b) {
        b.addEventListener('click', function() {
            switchView(this.getAttribute('data-view'));
        });
    });

    var saved = 'cards';
    try { saved = localStorage.getItem('taskView2') || 'cards'; } catch(e) {}
    switchView(saved);
})();

// ─────────────────────────────────────────────────────────
// MY TASKS — combined search + filter + DATE
// ─────────────────────────────────────────────────────────
(function () {
    try {
    const search    = document.getElementById('tmSearch');
    const priSel    = document.getElementById('tmPriFilter');
    const stsSel    = document.getElementById('tmStsFilter');
    const projSel   = document.getElementById('tmProjFilter');
    const memberSel = document.getElementById('memberFilter');
    const noRes     = document.getElementById('tmNoResults');
    const dfFrom    = document.getElementById('dfFrom');
    const dfTo      = document.getElementById('dfTo');
    const dfClear   = document.getElementById('dfClearTasks');
    const dfCount   = document.getElementById('dfCountTasks');
    if (!dfFrom) return; // not on my-tasks tab

    // Date mode toggle (due vs created)
    let dateMode = 'due';
    document.querySelectorAll('[data-mode]').forEach(btn => {
        btn.addEventListener('click', function () {
            dateMode = this.dataset.mode;
            document.querySelectorAll('[data-mode]').forEach(b => b.classList.toggle('active', b.dataset.mode === dateMode));
            applyAll();
        });
    });

    // Preset buttons (only those without data-dfbar — i.e. inside dfBarTasks)
    document.querySelectorAll('#dfBarTasks [data-preset]').forEach(btn => {
        btn.addEventListener('click', function () {
            const active = this.classList.contains('active');
            document.querySelectorAll('#dfBarTasks [data-preset]').forEach(b => b.classList.remove('active'));
            if (active) { dfFrom.value = ''; dfTo.value = ''; applyAll(); return; }
            this.classList.add('active');
            const p = this.dataset.preset;
            if (p === 'today')   { dfFrom.value = isoToday();      dfTo.value = isoToday(); }
            if (p === 'week')    { dfFrom.value = isoWeekStart();  dfTo.value = isoWeekEnd(); }
            if (p === 'month')   { dfFrom.value = isoMonthStart(); dfTo.value = isoMonthEnd(); }
            if (p === 'overdue') { dfFrom.value = '';               dfTo.value = isoYesterday(); }
            if (p === 'soon')    { dfFrom.value = isoToday();      dfTo.value = isoNDaysLater(7); }
            applyAll();
        });
    });

    // Manual pickers — clear any active preset
    [dfFrom, dfTo].forEach(inp => {
        if (inp) inp.addEventListener('change', () => {
            document.querySelectorAll('#dfBarTasks [data-preset]').forEach(b => b.classList.remove('active'));
            applyAll();
        });
    });

    // Clear button
    if (dfClear) dfClear.addEventListener('click', () => {
        dfFrom.value = ''; dfTo.value = '';
        document.querySelectorAll('#dfBarTasks [data-preset]').forEach(b => b.classList.remove('active'));
        applyAll();
    });

    function applyAll() {
        const q    = (search?.value || '').toLowerCase().trim();
        const pri  = priSel?.value  || '';
        const sts  = stsSel?.value  || '';
        const proj = projSel?.value || '';
        const mem  = memberSel?.value || '';
        const from = dfFrom?.value || '';
        const to   = dfTo?.value   || '';
        const hasDate = from || to;
        if (dfClear) dfClear.classList.toggle('visible', !!(from || to));

        let vis = 0;
        document.querySelectorAll('.task-item').forEach(el => {
            const title    = (el.dataset.title || '').toLowerCase();
            const aName    = (el.dataset.assigneeName || '').toLowerCase();
            const ep       = el.dataset.priority || '';
            const es       = el.dataset.status   || '';
            const ea       = el.dataset.assigneeId || '';
            const eb       = el.dataset.assignedBy || '';
            const eproj    = el.dataset.project || '0';
            const dv       = dateMode === 'due' ? (el.dataset.due || '') : (el.dataset.created || '');
            const inDate   = !hasDate
                          || ((!from || !dv || dv >= from) && (!to || !dv || dv <= to));
            const myUid    = '<?= $uid ?>';
            const memMatch = !mem
                          || (mem === 'MY_TASKS' && ea === myUid)
                          || (mem !== 'MY_TASKS' && (ea === mem || eb === mem));
            const projMatch = !proj || eproj === proj;
            const show     = (!q   || title.includes(q) || aName.includes(q))
                          && (!pri || ep === pri)
                          && (!sts || es === sts)
                          && projMatch
                          && memMatch
                          && inDate;
            el.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        if (noRes)   noRes.classList.toggle('d-none', vis > 0);
        if (dfCount) dfCount.textContent = hasDate ? (vis + ' shown') : '';
    }

    [search, priSel, stsSel, projSel, memberSel].forEach(el => {
        if (el) el.addEventListener('input', applyAll);
    });

    // Apply member from URL on load
    if (memberSel?.value) applyAll();
    } catch(e) { console.warn('Task filter error:', e); }
})();

// ─────────────────────────────────────────────────────────
// OUTGOING REQUESTS — date filter
// ─────────────────────────────────────────────────────────
(function () {
    const dfFrom  = document.getElementById('dfOutFrom');
    const dfTo    = document.getElementById('dfOutTo');
    const dfClear = document.getElementById('dfClearOut');
    const dfCount = document.getElementById('dfCountOut');
    if (!dfFrom) return;

    function run() {
        const f = dfFrom.value, t = dfTo.value;
        if (dfClear) dfClear.classList.toggle('visible', !!(f || t));
        applyDateRange('.out-item', f, t, dfCount, document.getElementById('outNoResults'));
    }

    document.querySelectorAll('[data-dfbar="outgoing"]').forEach(btn => {
        btn.addEventListener('click', function () {
            const active = this.classList.contains('active');
            document.querySelectorAll('[data-dfbar="outgoing"]').forEach(b => b.classList.remove('active'));
            if (active) { dfFrom.value = ''; dfTo.value = ''; run(); return; }
            this.classList.add('active');
            const p = this.dataset.preset;
            if (p === 'today') { dfFrom.value = isoToday();      dfTo.value = isoToday(); }
            if (p === 'week')  { dfFrom.value = isoWeekStart();  dfTo.value = isoWeekEnd(); }
            if (p === 'month') { dfFrom.value = isoMonthStart(); dfTo.value = isoMonthEnd(); }
            run();
        });
    });
    [dfFrom, dfTo].forEach(inp => inp.addEventListener('change', () => {
        document.querySelectorAll('[data-dfbar="outgoing"]').forEach(b => b.classList.remove('active'));
        run();
    }));
    if (dfClear) dfClear.addEventListener('click', () => {
        dfFrom.value = ''; dfTo.value = '';
        document.querySelectorAll('[data-dfbar="outgoing"]').forEach(b => b.classList.remove('active'));
        run();
    });
})();

// ─────────────────────────────────────────────────────────
// ACTIVITY LOGS — date filter
// ─────────────────────────────────────────────────────────
(function () {
    const dfFrom  = document.getElementById('dfLogFrom');
    const dfTo    = document.getElementById('dfLogTo');
    const dfClear = document.getElementById('dfClearLog');
    const dfCount = document.getElementById('dfCountLog');
    if (!dfFrom) return;

    function run() {
        const f = dfFrom.value, t = dfTo.value;
        if (dfClear) dfClear.classList.toggle('visible', !!(f || t));
        const vis = applyDateRange('.log-item', f, t, dfCount, document.getElementById('logNoResults'));
        // fix connector lines: hide log-line on last *visible* item
        const visible = Array.from(document.querySelectorAll('.log-item')).filter(el => el.style.display !== 'none');
        document.querySelectorAll('.log-item .log-line').forEach(l => l.style.display = '');
        if (visible.length > 0) {
            const lastLine = visible[visible.length-1].querySelector('.log-line');
            if (lastLine) lastLine.style.display = 'none';
        }
    }

    document.querySelectorAll('[data-dfbar="logs"]').forEach(btn => {
        btn.addEventListener('click', function () {
            const active = this.classList.contains('active');
            document.querySelectorAll('[data-dfbar="logs"]').forEach(b => b.classList.remove('active'));
            if (active) { dfFrom.value = ''; dfTo.value = ''; run(); return; }
            this.classList.add('active');
            const p = this.dataset.preset;
            if (p === 'today') { dfFrom.value = isoToday();      dfTo.value = isoToday(); }
            if (p === 'week')  { dfFrom.value = isoWeekStart();  dfTo.value = isoWeekEnd(); }
            if (p === 'month') { dfFrom.value = isoMonthStart(); dfTo.value = isoMonthEnd(); }
            run();
        });
    });
    [dfFrom, dfTo].forEach(inp => inp.addEventListener('change', () => {
        document.querySelectorAll('[data-dfbar="logs"]').forEach(b => b.classList.remove('active'));
        run();
    }));
    if (dfClear) dfClear.addEventListener('click', () => {
        dfFrom.value = ''; dfTo.value = '';
        document.querySelectorAll('[data-dfbar="logs"]').forEach(b => b.classList.remove('active'));
        run();
    });
})();

// ─────────────────────────────────────────────────────────
// RECYCLE BIN — date filter
// ─────────────────────────────────────────────────────────
(function () {
    const dfFrom  = document.getElementById('dfBinFrom');
    const dfTo    = document.getElementById('dfBinTo');
    const dfClear = document.getElementById('dfClearBin');
    const dfCount = document.getElementById('dfCountBin');
    if (!dfFrom) return;

    function run() {
        const f = dfFrom.value, t = dfTo.value;
        if (dfClear) dfClear.classList.toggle('visible', !!(f || t));
        applyDateRange('.bin-item', f, t, dfCount, document.getElementById('binNoResults'));
    }

    document.querySelectorAll('[data-dfbar="bin"]').forEach(btn => {
        btn.addEventListener('click', function () {
            const active = this.classList.contains('active');
            document.querySelectorAll('[data-dfbar="bin"]').forEach(b => b.classList.remove('active'));
            if (active) { dfFrom.value = ''; dfTo.value = ''; run(); return; }
            this.classList.add('active');
            const p = this.dataset.preset;
            if (p === 'today') { dfFrom.value = isoToday();      dfTo.value = isoToday(); }
            if (p === 'week')  { dfFrom.value = isoWeekStart();  dfTo.value = isoWeekEnd(); }
            if (p === 'month') { dfFrom.value = isoMonthStart(); dfTo.value = isoMonthEnd(); }
            run();
        });
    });
    [dfFrom, dfTo].forEach(inp => inp.addEventListener('change', () => {
        document.querySelectorAll('[data-dfbar="bin"]').forEach(b => b.classList.remove('active'));
        run();
    }));
    if (dfClear) dfClear.addEventListener('click', () => {
        dfFrom.value = ''; dfTo.value = '';
        document.querySelectorAll('[data-dfbar="bin"]').forEach(b => b.classList.remove('active'));
        run();
    });
})();

// ─────────────────────────────────────────────────────────
// TABLE SORT
// ─────────────────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tb = document.querySelector('#taskTableEl tbody');
    if (!tb) return;
    const rows = Array.from(tb.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = (a.cells[col]?.innerText || '').trim();
        const bv = (b.cells[col]?.innerText || '').trim();
        return _sortDir[col] ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(r => tb.appendChild(r));
}

// ─────────────────────────────────────────────────────────
// APPROVE / REJECT / REWORK MODALS
// ─────────────────────────────────────────────────────────
function openApprove(id) {
    document.getElementById('approve_task_id').value = id;
    document.getElementById('approve_assigned_to').value = '';
    document.getElementById('approve_needs_approval').checked = false;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}
function openReject(id) {
    document.getElementById('reject_task_id').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
function openReworkModal(id, title) {
    document.getElementById('rework_task_id').value = id;
    const el = document.getElementById('rework_task_title');
    if (el) el.textContent = title;
    new bootstrap.Modal(document.getElementById('reworkModal')).show();
}

// ── Approver info tooltips ─────────────────────────────
document.querySelectorAll('.appr-info-tip').forEach(function(el) {
    const tip = el.dataset.tip || '';
    const box = document.createElement('div');
    box.className = 'appr-tip-box';
    box.textContent = tip;
    el.appendChild(box);
});

// ─────────────────────────────────────────────────────────
// KANBAN DRAG-AND-DROP
// ─────────────────────────────────────────────────────────
(function () {
    let dragCard = null;

    document.querySelectorAll('.kb-draggable').forEach(card => {
        card.addEventListener('dragstart', e => {
            dragCard = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            document.querySelectorAll('.kanban-col').forEach(c => c.classList.remove('drop-active'));
            dragCard = null;
        });
    });

    document.querySelectorAll('.kanban-col').forEach(col => {
        col.addEventListener('dragover', e => {
            e.preventDefault();
            col.classList.add('drop-active');
        });
        col.addEventListener('dragleave', e => {
            if (!col.contains(e.relatedTarget)) col.classList.remove('drop-active');
        });
        col.addEventListener('drop', e => {
            e.preventDefault();
            col.classList.remove('drop-active');
            if (!dragCard) return;

            const newStatus = col.dataset.status;
            const oldStatus = dragCard.dataset.status;
            if (newStatus === oldStatus) return;

            const taskId      = dragCard.dataset.taskId;
            const oldColCards = document.getElementById('kc-' + oldStatus);
            const newColCards = document.getElementById('kc-' + newStatus);
            if (!newColCards) return;

            newColCards.querySelectorAll('.kb-empty').forEach(el => el.remove());
            newColCards.appendChild(dragCard);
            dragCard.dataset.status = newStatus;

            if (oldColCards && !oldColCards.querySelector('.kanban-card')) {
                oldColCards.innerHTML = '<div class="kanban-empty kb-empty"><i class="bi bi-inbox me-1"></i>No tasks</div>';
            }

            const oc = document.querySelector(`.kanban-col[data-status="${oldStatus}"] .kanban-count`);
            const nc = col.querySelector('.kanban-count');
            if (oc) oc.textContent = Math.max(0, parseInt(oc.textContent) - 1);
            if (nc) nc.textContent = parseInt(nc.textContent) + 1;

            // If dragging to DONE and needs_approval, show modal instead of alert
            const needsApproval = dragCard.dataset.needsApproval === '1';
            if (newStatus === 'DONE' && needsApproval) {
                // Revert visual
                const origCol = document.getElementById('kc-' + oldStatus);
                if (origCol) {
                    origCol.querySelectorAll('.kb-empty').forEach(el => el.remove());
                    origCol.appendChild(dragCard);
                    dragCard.dataset.status = oldStatus;
                    if (!newColCards.querySelector('.kanban-card')) {
                        newColCards.innerHTML = '<div class="kanban-empty kb-empty"><i class="bi bi-inbox me-1"></i>No tasks</div>';
                    }
                    const oc2 = document.querySelector('.kanban-col[data-status="' + oldStatus + '"] .kanban-count');
                    const nc2 = col.querySelector('.kanban-count');
                    if (oc2) oc2.textContent = parseInt(oc2.textContent) + 1;
                    if (nc2) nc2.textContent = Math.max(0, parseInt(nc2.textContent) - 1);
                }
                new bootstrap.Modal(document.getElementById('needsApprovalModal')).show();
                return;
            }
            const fd = new FormData();
            fd.append('action',     'update_status');
            fd.append('task_id',    taskId);
            fd.append('new_status', newStatus);
            fetch('tasks.php', { method: 'POST', body: fd })
                .catch(() => location.reload());
        });
    });
})();

// ─────────────────────────────────────────────────────────────
// AJAX HELPERS — replace all form-based page reloads
// ─────────────────────────────────────────────────────────────

function openCompletionModal(taskId, title) {
    document.getElementById('cn_task_id').value = taskId;
    document.getElementById('cn_task_title').textContent = title || '';
    document.getElementById('cn_note').value = '';
    document.getElementById('cn_error').style.display = 'none';
    new bootstrap.Modal(document.getElementById('completionNoteModal')).show();
    setTimeout(() => document.getElementById('cn_note').focus(), 350);
}

async function submitCompletionNote(btn) {
    const taskId = document.getElementById('cn_task_id').value;
    const note   = document.getElementById('cn_note').value.trim();
    const errEl  = document.getElementById('cn_error');
    if (!note) { errEl.style.display = 'block'; document.getElementById('cn_note').focus(); return; }
    errEl.style.display = 'none';
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="hc-spinner"></span> Submitting…';
    const fd = new FormData();
    fd.append('action', 'submit_for_approval');
    fd.append('task_id', taskId);
    fd.append('completion_note', note);
    fd.append('_ajax', '1');
    try {
        const r = await fetch('tasks.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok) {
            alert(d.error || 'Something went wrong');
            btn.disabled = false; btn.innerHTML = orig;
        } else {
            bootstrap.Modal.getInstance(document.getElementById('completionNoteModal'))?.hide();
            location.reload();
        }
    } catch(e) { btn.disabled = false; btn.innerHTML = orig; alert('Network error'); }
}

async function tmAjax(action, taskId, btn, extra) {
    const orig = btn ? btn.innerHTML : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="hc-spinner"></span>'; }
    const fd = new FormData();
    fd.append('action', action); fd.append('task_id', taskId); fd.append('_ajax', '1');
    if (extra) Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
    try {
        const r = await fetch('tasks.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok) { alert(d.error || 'Something went wrong'); if (btn) { btn.disabled=false; btn.innerHTML=orig; } }
        else location.reload();
    } catch(e) { if (btn) { btn.disabled=false; btn.innerHTML=orig; } alert('Network error'); }
}

async function submitCreateTask(btn) {
    const form = document.getElementById('createTaskForm');
    const title = form.querySelector('[name="title"]')?.value.trim();
    if (!title) { alert('Title is required'); return; }
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="hc-spinner"></span> Creating…';
    const fd = new FormData();
    fd.append('_ajax', '1');
    fd.append('action', 'create_task');
    form.querySelectorAll('[name]').forEach(el => {
        if (el.type === 'checkbox') { if (el.checked) fd.append(el.name, el.value); }
        else fd.append(el.name, el.value);
    });
    try {
        const r = await fetch('tasks.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok) { alert(d.error || 'Failed to create task'); btn.disabled=false; btn.innerHTML=orig; }
        else {
            bootstrap.Modal.getInstance(document.getElementById('createModal'))?.hide();
            form.querySelectorAll('input[type=text],input[type=date],input[type=number],textarea').forEach(el => el.value='');
            form.querySelectorAll('select').forEach(el => el.selectedIndex=0);
            form.querySelectorAll('input[type=checkbox]').forEach(el => el.checked=false);
            btn.disabled=false; btn.innerHTML=orig;
            location.reload();
        }
    } catch(e) { btn.disabled=false; btn.innerHTML=orig; alert('Network error'); }
}

async function submitApproveRequest(btn) {
    const taskId = document.getElementById('approve_task_id').value;
    const assignedTo = document.getElementById('approve_assigned_to').value;
    const needsApproval = document.getElementById('approve_needs_approval').checked ? '1' : '0';
    if (!assignedTo) { alert('Please select a team member'); return; }
    const orig = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="hc-spinner"></span> Approving…';
    const fd = new FormData();
    fd.append('_ajax','1'); fd.append('action','approve_request');
    fd.append('task_id',taskId); fd.append('assigned_to',assignedTo);
    fd.append('needs_approval', needsApproval);
    try {
        const r = await fetch('tasks.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d = await r.json();
        if (!d.ok) { alert(d.error||'Error'); btn.disabled=false; btn.innerHTML=orig; }
        else { bootstrap.Modal.getInstance(document.getElementById('approveModal'))?.hide(); location.reload(); }
    } catch(e) { btn.disabled=false; btn.innerHTML=orig; alert('Network error'); }
}

async function submitRework(btn) {
    const taskId = document.getElementById('rework_task_id').value;
    const note   = document.getElementById('rework_note').value.trim();
    const orig   = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="hc-spinner"></span> Sending…';
    const fd = new FormData();
    fd.append('_ajax','1'); fd.append('action','reject_task_hrms');
    fd.append('task_id',taskId); fd.append('note',note);
    try {
        const r = await fetch('tasks.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d = await r.json();
        if (!d.ok) { alert(d.error||'Error'); btn.disabled=false; btn.innerHTML=orig; }
        else { bootstrap.Modal.getInstance(document.getElementById('reworkModal'))?.hide(); location.reload(); }
    } catch(e) { btn.disabled=false; btn.innerHTML=orig; alert('Network error'); }
}

async function submitRejectRequest(btn) {
    const taskId = document.getElementById('reject_task_id').value;
    const reason = document.getElementById('reject_reason').value.trim();
    if (!reason) { alert('Reason is required'); return; }
    const orig = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="hc-spinner"></span> Rejecting…';
    const fd = new FormData();
    fd.append('_ajax','1'); fd.append('action','reject_request');
    fd.append('task_id',taskId); fd.append('reject_reason',reason);
    try {
        const r = await fetch('tasks.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d = await r.json();
        if (!d.ok) { alert(d.error||'Error'); btn.disabled=false; btn.innerHTML=orig; }
        else { bootstrap.Modal.getInstance(document.getElementById('rejectModal'))?.hide(); location.reload(); }
    } catch(e) { btn.disabled=false; btn.innerHTML=orig; alert('Network error'); }
}
</script>

<?php include 'footer.php'; ?>

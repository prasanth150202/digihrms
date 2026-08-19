<?php
// Push HRMS task status change directly to DigiOps DB (no HTTP round-trip)
function _digiops_task_sync(PDO $hrmsConn, int $hrmsTaskId, string $hrmsStatus): void {
    $ddb = digiops_db();
    if (!$ddb) return;
    try {
        $opsStatus = match($hrmsStatus) {
            'DONE'        => 'done',
            'IN_PROGRESS' => 'in_progress',
            'REVIEW'      => 'review',
            'REWORK'      => 'in_progress',
            'TODO'        => 'todo',
            'BLOCKED'     => 'blocked',
            default       => null,
        };
        if (!$opsStatus) return;

        $st = $ddb->prepare('SELECT id, status, brand_id, title, workflow_submission_id, public_token, requires_task_id, requires_collateral, collateral_file_path FROM brand_tasks WHERE hrms_task_id = ?');
        $st->execute([$hrmsTaskId]);
        $task = $st->fetch();
        if (!$task || $task['status'] === $opsStatus) return;

        // ── Fill-link guard ──────────────────────────────────────────────────────
        // Tasks with a public_token have a fill form that the assignee must submit.
        // The ONLY valid path to 'review' for these tasks is fill.php → POST.
        // HRMS "Submit for Approval" would bypass that, so we reject the push instead
        // of silently ignoring it (both sides get a comment explaining why).
        if ($opsStatus === 'review' && !empty($task['public_token'])) {
            _digiops_sync_reject($hrmsConn, $ddb, $task, $hrmsTaskId, 'review',
                'This task has a fill form — the assignee must submit it via the fill link, not from HRMS.');
            return;
        }

        // ── Done guards ──────────────────────────────────────────────────────────
        // Mirror the same gates DigiOps's own update_status/submit_review enforce,
        // so HRMS can't force-complete work that was never actually submitted.
        if ($opsStatus === 'done') {
            if (!empty($task['public_token']) && $task['status'] !== 'review') {
                _digiops_sync_reject($hrmsConn, $ddb, $task, $hrmsTaskId, 'done',
                    'This task has a fill form — the assignee must submit it via the fill link before it can be marked done.');
                return;
            }
            if (!empty($task['requires_collateral']) && empty($task['collateral_file_path'])) {
                _digiops_sync_reject($hrmsConn, $ddb, $task, $hrmsTaskId, 'done',
                    'The required collateral file has not been uploaded in DigiOps yet.');
                return;
            }
            if (!empty($task['requires_task_id'])) {
                $pq = $ddb->prepare('SELECT title, status FROM brand_tasks WHERE id = ?');
                $pq->execute([$task['requires_task_id']]);
                $prereq = $pq->fetch();
                if ($prereq && $prereq['status'] !== 'done') {
                    _digiops_sync_reject($hrmsConn, $ddb, $task, $hrmsTaskId, 'done',
                        "Prerequisite task \"{$prereq['title']}\" is not done yet.");
                    return;
                }
            }
        }

        if ($opsStatus === 'done') {
            $ddb->prepare('UPDATE brand_tasks SET status = ?, done_at = NOW() WHERE id = ?')->execute([$opsStatus, $task['id']]);
            // Ensure a task_approvals approved record exists so DigiOps "Recently Approved" tab shows it
            $upd = $ddb->prepare("UPDATE task_approvals SET status='approved', reviewed_at=NOW() WHERE task_id=? AND status='pending'");
            $upd->execute([$task['id']]);
            if ($upd->rowCount() === 0) {
                // No pending row — insert an approved record directly
                $ddb->prepare("INSERT IGNORE INTO task_approvals (task_id, brand_id, status, reviewed_at) VALUES (?, ?, 'approved', NOW())")
                    ->execute([$task['id'], $task['brand_id']]);
            }
        } elseif ($opsStatus === 'blocked') {
            $ddb->prepare("UPDATE brand_tasks SET status = 'blocked', blocked_reason = ? WHERE id = ?")
                ->execute(['Blocked in HRMS', $task['id']]);
        } else {
            $ddb->prepare('UPDATE brand_tasks SET status = ? WHERE id = ?')->execute([$opsStatus, $task['id']]);
        }

        // Log a comment in DigiOps task_comments for visibility
        $ddb->prepare('INSERT INTO task_comments (task_id, user_id, user_name, comment, source) VALUES (?,NULL,?,?,?)')
            ->execute([$task['id'], 'HRMS', "Status updated in HRMS → {$opsStatus}", 'hrms']);

        // If all tasks for this submission are done, resume the flow
        if ($opsStatus === 'done' && $task['workflow_submission_id']) {
            $pend = $ddb->prepare("SELECT COUNT(*) FROM brand_tasks WHERE workflow_submission_id = ? AND status != 'done'");
            $pend->execute([$task['workflow_submission_id']]);
            if ((int)$pend->fetchColumn() === 0) {
                $ddb->prepare("UPDATE workflow_submissions SET status = 'tasks_done', updated_at = NOW() WHERE id = ? AND status NOT IN ('approved','rejected')")
                    ->execute([$task['workflow_submission_id']]);
            }
        }
    } catch (Exception $e) {
        // Never break HRMS operation
    }
}

// A push from HRMS was blocked by a DigiOps completion gate. Log it loudly on both
// sides instead of silently dropping it, so neither side is left assuming it worked.
function _digiops_sync_reject(PDO $hrmsConn, PDO $ddb, array $task, int $hrmsTaskId, string $attempted, string $reason): void {
    try {
        $ddb->prepare('INSERT INTO task_comments (task_id, user_id, user_name, comment, source) VALUES (?,NULL,?,?,?)')
            ->execute([$task['id'], 'HRMS', "HRMS tried to set status → {$attempted}, but it was blocked: {$reason}", 'hrms']);
    } catch (Throwable $e) {}
    try {
        $hrmsConn->prepare('INSERT INTO task_comments (task_id, user_id, comment, created_at) VALUES (?, NULL, ?, NOW())')
            ->execute([$hrmsTaskId, "⚠ Not synced to DigiOps: {$reason}"]);
    } catch (Throwable $e) {}
}

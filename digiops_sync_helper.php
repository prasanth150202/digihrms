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
            default       => null,
        };
        if (!$opsStatus) return;

        $st = $ddb->prepare('SELECT id, status, brand_id, title, workflow_submission_id FROM brand_tasks WHERE hrms_task_id = ?');
        $st->execute([$hrmsTaskId]);
        $task = $st->fetch();
        if (!$task || $task['status'] === $opsStatus) return;

        // ── Fill-link guard ──────────────────────────────────────────────────────
        // Tasks with a public_token have a fill form that the assignee must submit.
        // The ONLY valid path to 'review' for these tasks is fill.php → POST.
        // HRMS "Submit for Approval" would bypass that, so we redirect REVIEW → in_progress
        // (the task stays active; the HRMS status update is ignored for the review transition).
        if ($opsStatus === 'review') {
            $chk = $ddb->prepare('SELECT public_token FROM brand_tasks WHERE id = ?');
            $chk->execute([$task['id']]);
            $pt = $chk->fetchColumn();
            if (!empty($pt)) {
                // Has a fill link — do not allow HRMS to push it to review
                return;
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

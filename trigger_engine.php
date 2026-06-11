<?php
/**
 * HRMS Trigger Engine
 * Called by: cron endpoint, login hook, task event hooks, manual fire
 */
if (!isset($conn)) require_once __DIR__ . '/config.php';

/**
 * Resolve the Team Lead user ID for a given user (via their department)
 */
function _resolveTlForUser(PDO $conn, int $userId): ?int {
    // Get dept_id of the target user
    $row = $conn->prepare("
        SELECT er.dept_id FROM employee_roles er
        JOIN employees e ON e.id = er.employee_id
        JOIN users u ON u.email = e.email
        WHERE u.id = ? LIMIT 1
    ");
    $row->execute([$userId]);
    $deptId = $row->fetchColumn();
    if (!$deptId) return null;

    // Find TL in same dept
    $tl = $conn->prepare("
        SELECT u.id FROM users u
        JOIN employees e ON e.email = u.email
        JOIN employee_roles er ON er.employee_id = e.id
        WHERE er.is_team_lead = 1 AND er.dept_id = ? LIMIT 1
    ");
    $tl->execute([$deptId]);
    $tlId = $tl->fetchColumn();
    return $tlId ? (int)$tlId : null;
}

/**
 * Fire a specific published workflow directly on a target person (no trigger record)
 */
function fireWorkflowOnPerson(PDO $conn, int $workflowId, int $targetUserId, int $actorId): array {
    $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? AND is_active=1 AND published_version>0 LIMIT 1");
    $wf->execute([$workflowId]);
    $workflow = $wf->fetch();
    if (!$workflow || !$workflow['published_layout']) {
        return ['ok'=>false,'msg'=>'Workflow not found or not published'];
    }

    $context = ['target_user_id' => $targetUserId, 'fired_by' => 'manual_person'];

    $conn->prepare("INSERT INTO hrms_workflow_runs (template_id, triggered_by, target_user_id, status, context_json) VALUES (?,?,?,?,?)")
         ->execute([$workflowId, $actorId, $targetUserId, 'running', json_encode($context)]);
    $runId = (int)$conn->lastInsertId();

    try {
        $layout = json_decode($workflow['published_layout'], true);
        _runWorkflowBfs($conn, $runId, $layout, $targetUserId, $context);
        $conn->prepare("UPDATE hrms_workflow_runs SET status='completed', completed_at=NOW() WHERE id=?")->execute([$runId]);
        return ['ok'=>true,'run_id'=>$runId];
    } catch (Exception $e) {
        $conn->prepare("UPDATE hrms_workflow_runs SET status='failed' WHERE id=?")->execute([$runId]);
        return ['ok'=>false,'msg'=>$e->getMessage()];
    }
}

/**
 * Fire a specific trigger by ID (manual or cron)
 */
function fireTriggerById(PDO $conn, int $triggerId, int $actorId, string $firedBy = 'manual'): array {
    $tr = $conn->prepare("SELECT * FROM hrms_triggers WHERE id=? AND is_active=1 LIMIT 1");
    $tr->execute([$triggerId]);
    $trigger = $tr->fetch();
    if (!$trigger) return ['ok'=>false,'msg'=>'Trigger not found or inactive'];
    return _executeTrigger($conn, $trigger, $actorId, $firedBy, []);
}

/**
 * Fire all active triggers matching a task event
 */
function fireTriggersByTaskEvent(PDO $conn, string $eventType, int $taskId, int $actorId): void {
    // Map event to trigger types
    $typeMap = [
        'task_created'   => 'task_created',
        'task_assigned'  => 'task_assigned',
        'task_overdue'   => 'task_overdue',
        'task_completed' => 'task_completed',
    ];
    $triggerType = $typeMap[$eventType] ?? null;
    if (!$triggerType) return;

    $rows = $conn->prepare("SELECT * FROM hrms_triggers WHERE trigger_type=? AND is_active=1");
    $rows->execute([$triggerType]);
    foreach ($rows->fetchAll() as $trigger) {
        _executeTrigger($conn, $trigger, $actorId, 'task_event', ['task_id'=>$taskId]);
    }
}

/**
 * Fire triggers on task status change
 */
function fireTriggersByStatusChange(PDO $conn, int $taskId, string $newStatus, int $actorId): void {
    $rows = $conn->prepare("SELECT * FROM hrms_triggers WHERE trigger_type='task_status' AND is_active=1");
    $rows->execute();
    foreach ($rows->fetchAll() as $trigger) {
        $conds = json_decode($trigger['conditions_json'] ?? '{}', true) ?? [];
        if (($conds['status'] ?? '') !== $newStatus) continue;
        _executeTrigger($conn, $trigger, $actorId, 'task_event', ['task_id'=>$taskId,'status'=>$newStatus]);
    }
}

/**
 * Fire login-based triggers for a user (once per day)
 */
function fireTriggersByLogin(PDO $conn, int $userId): void {
    $rows = $conn->prepare("SELECT * FROM hrms_triggers WHERE trigger_type='login' AND is_active=1");
    $rows->execute();
    $today = date('Y-m-d');
    foreach ($rows->fetchAll() as $trigger) {
        // Check if already fired today for this user
        $check = $conn->prepare("SELECT id FROM hrms_trigger_login_track WHERE user_id=? AND trigger_id=? AND fired_date=?");
        $check->execute([$userId, $trigger['id'], $today]);
        if ($check->fetch()) continue; // already fired today

        $result = _executeTrigger($conn, $trigger, $userId, 'login', ['user_id'=>$userId]);
        if ($result['ok']) {
            // Mark as fired today
            try {
                $conn->prepare("INSERT IGNORE INTO hrms_trigger_login_track (user_id, trigger_id, fired_date) VALUES (?,?,?)")
                     ->execute([$userId, $trigger['id'], $today]);
            } catch (Exception $e) {}
        }
    }
}

/**
 * Check if today is a working day (Mon–Fri, not a holiday in the holidays table)
 */
function _isWorkingDay(PDO $conn): bool {
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    if ((int)$now->format('N') > 5) return false; // weekend
    try {
        $check = $conn->prepare("SELECT id FROM holidays WHERE holiday_date = ? LIMIT 1");
        $check->execute([$now->format('Y-m-d')]);
        return !$check->fetch();
    } catch (Exception $e) {
        return true;
    }
}

/**
 * Fire all due time-based triggers (called by cron)
 */
function fireDueTimeTriggers(PDO $conn): array {
    $results = [];
    $rows = $conn->prepare("SELECT * FROM hrms_triggers WHERE trigger_type='time' AND is_active=1");
    $rows->execute();
    // Use IST (Asia/Kolkata) so trigger times match what users set in the UI
    $tz      = new DateTimeZone('Asia/Kolkata');
    $now     = new DateTime('now', $tz);
    $nowTime = $now->format('H:i');
    $nowDay  = (int)$now->format('N'); // 1=Mon, 7=Sun
    $nowDate = (int)$now->format('j'); // day of month

    foreach ($rows->fetchAll() as $trigger) {
        $conds = json_decode($trigger['conditions_json'] ?? '{}', true) ?? [];

        // Extract time config from new flat conditions array or legacy keys
        $timeRow    = null;
        $condRows   = $conds['conditions'] ?? [];
        foreach ($condRows as $cr) {
            if (($cr['field'] ?? '') === 'event_time') { $timeRow = $cr; break; }
        }
        $trigRepeat = $timeRow['repeat'] ?? $conds['repeat'] ?? 'daily';
        $times      = $timeRow['times']  ?? $conds['times']  ?? (isset($conds['time']) ? [$conds['time']] : ['09:00']);
        $logic      = $conds['logic']    ?? 'OR';

        // Check time match: OR = any slot matches now, AND = all slots must match (usually same minute)
        $matchedTime = null;
        if ($logic === 'AND') {
            // All times must match — only meaningful if all are identical, practical for OR
            $match = count(array_filter($times, fn($t) => $t === $nowTime)) === count($times);
            if ($match) $matchedTime = $nowTime;
        } else {
            foreach ($times as $t) {
                if ($t === $nowTime) { $matchedTime = $t; break; }
            }
        }
        if (!$matchedTime) continue;

        // Check repeat schedule
        $shouldFire = match($trigRepeat) {
            'daily'        => true,
            'weekdays'     => $nowDay <= 5,
            'working_days' => _isWorkingDay($conn),
            'weekly'       => $nowDay == 1, // Monday
            'monthly'      => $nowDate == 1,
            default        => true
        };
        if (!$shouldFire) continue;

        // Prevent double-fire within same minute
        $lastFired = $trigger['last_fired_at'];
        if ($lastFired) {
            $lastDt = new DateTime($lastFired, $tz);
            if ($lastDt->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) continue;
        }

        $result = _executeTrigger($conn, $trigger, 0, 'cron', ['time'=>$matchedTime]);
        $results[] = ['trigger'=>$trigger['name'], 'result'=>$result];
    }
    return $results;
}

/**
 * Evaluate all conditions in conditions_json (new flat array format)
 * Event rows (operator='occurs') are skipped — they are firing conditions, not filters.
 * Only data condition rows are evaluated here.
 */
function _passesExtraConditions(PDO $conn, array $conds, array $context): bool {
    // New format: { logic, conditions:[{field,operator,value,...}] }
    $rows = $conds['conditions'] ?? null;

    // Legacy format fallback: { extra:[...], extra_logic:... }
    if ($rows === null) {
        $rows  = $conds['extra'] ?? [];
        $logic = $conds['extra_logic'] ?? 'AND';
    } else {
        $logic = $conds['logic'] ?? 'AND';
    }

    // Filter to only data conditions (skip event rows)
    $dataRows = array_filter($rows, fn($c) => ($c['operator'] ?? '') !== 'occurs' && !str_starts_with($c['field'] ?? '', 'event_'));
    if (empty($dataRows)) return true;

    $results = [];
    foreach ($dataRows as $c) {
        $field    = $c['field']    ?? '';
        $operator = $c['operator'] ?? '==';
        $expected = $c['value']    ?? '';

        $actual = match($field) {
            'time_of_day'      => date('H:i'),
            'task_status'      => $context['status'] ?? '',
            'task_priority'    => $context['priority'] ?? '',
            'day_of_week'      => date('l'),
            'is_working_day'   => _isWorkingDay($conn) ? 'yes' : 'no',
            'repeat_schedule'  => $c['repeat'] ?? '',
            default            => $context[$field] ?? '',
        };

        $results[] = match($operator) {
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>'  => $actual >  $expected,
            '<'  => $actual <  $expected,
            'contains' => str_contains((string)$actual, (string)$expected),
            default    => false,
        };
    }

    return $logic === 'AND'
        ? !in_array(false, $results, true)
        : in_array(true,  $results, true);
}

/**
 * Core execution — runs the workflow linked to a trigger
 */
function _executeTrigger(PDO $conn, array $trigger, int $actorId, string $firedBy, array $context): array {
    $wfId  = (int)$trigger['workflow_id'];
    $conds = json_decode($trigger['conditions_json'] ?? '{}', true) ?? [];

    // Check extra conditions before doing anything
    if (!_passesExtraConditions($conn, $conds, $context)) {
        _logTrigger($conn, $trigger['id'], null, $firedBy, $context, 'skipped', 'Extra conditions not met');
        return ['ok'=>false,'msg'=>'Extra conditions not met'];
    }

    // Load published workflow
    $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? AND is_active=1 AND published_version>0 LIMIT 1");
    $wf->execute([$wfId]);
    $workflow = $wf->fetch();

    if (!$workflow || !$workflow['published_layout']) {
        _logTrigger($conn, $trigger['id'], null, $firedBy, $context, 'failed', 'Workflow not found or not published');
        return ['ok'=>false,'msg'=>'Workflow not found or not published'];
    }

    // Create a workflow run record
    $conn->prepare("INSERT INTO hrms_workflow_runs (template_id, triggered_by, target_user_id, status, context_json) VALUES (?,?,?,?,?)")
         ->execute([$wfId, $actorId ?: null, $actorId ?: null, 'running', json_encode($context)]);
    $runId = (int)$conn->lastInsertId();

    // Execute BFS on the published layout
    try {
        $layout = json_decode($workflow['published_layout'], true);
        _runWorkflowBfs($conn, $runId, $layout, $actorId, $context);

        // Mark run complete
        $conn->prepare("UPDATE hrms_workflow_runs SET status='completed', completed_at=NOW() WHERE id=?")->execute([$runId]);

        // Update trigger last_fired_at + fire count
        $conn->prepare("UPDATE hrms_triggers SET last_fired_at=NOW() WHERE id=?")->execute([$trigger['id']]);

        _logTrigger($conn, $trigger['id'], $runId, $firedBy, $context, 'fired', 'OK');
        return ['ok'=>true,'run_id'=>$runId];

    } catch (Exception $e) {
        $conn->prepare("UPDATE hrms_workflow_runs SET status='failed' WHERE id=?")->execute([$runId]);
        _logTrigger($conn, $trigger['id'], $runId, $firedBy, $context, 'failed', $e->getMessage());
        return ['ok'=>false,'msg'=>$e->getMessage()];
    }
}

/**
 * BFS walk of the published workflow canvas — creates tasks
 */
function _runWorkflowBfs(PDO $conn, int $runId, array $layout, int $actorId, array $context): void {
    $nodes = $layout['drawflow']['Home']['data'] ?? [];
    if (!$nodes) return;

    // Find start node (is_start=true or first node)
    $startNodeKey = null;
    foreach ($nodes as $key => $node) {
        if (!empty($node['data']['is_start'])) { $startNodeKey = $key; break; }
    }
    if (!$startNodeKey) $startNodeKey = array_key_first($nodes);

    $queue   = [$startNodeKey];
    $visited = [];

    while (!empty($queue)) {
        $key = array_shift($queue);
        if (isset($visited[$key])) continue;
        $visited[$key] = true;

        $node = $nodes[$key] ?? null;
        if (!$node) continue;

        $type = $node['data']['nodeType'] ?? $node['name'] ?? '';
        $data = $node['data'] ?? [];

        if ($type === 'task') {
            _executeTaskCardNode($conn, $runId, $key, $data, $actorId, $context);
            // Queue outputs — but only after tasks complete (for BFS we queue immediately;
            // completion detection is handled by hrms_workflow_task_instances status checks)
            foreach (($node['outputs']['output_1']['connections'] ?? []) as $conn_) {
                $queue[] = $conn_['node'];
            }
        }
        elseif ($type === 'condition') {
            $port = _evaluateCondition($data, $context);
            $outputKey = $port === 'yes' ? 'output_1' : 'output_2';
            foreach (($node['outputs'][$outputKey]['connections'] ?? []) as $conn_) {
                $queue[] = $conn_['node'];
            }
        }
        else {
            // Unknown node type — pass through
            foreach (($node['outputs']['output_1']['connections'] ?? []) as $conn_) {
                $queue[] = $conn_['node'];
            }
        }

        // Log node execution
        $conn->prepare("INSERT INTO hrms_workflow_node_log (run_id, node_id, node_type, result_json) VALUES (?,?,?,?)")
             ->execute([$runId, $key, $type, json_encode(['status'=>'executed'])]);
    }
}

/**
 * Create all tasks inside a task card node
 */
function _executeTaskCardNode(PDO $conn, int $runId, string $nodeKey, array $data, int $actorId, array $context): void {
    $tasks = $data['tasks'] ?? [];
    foreach ($tasks as $idx => $task) {
        $title    = $task['title'] ?? 'Untitled Task';
        $desc     = $task['description'] ?? '';
        $priority = $task['priority'] ?? 'MEDIUM';
        $dueDays  = (int)($task['due_days'] ?? 1);
        $assignees = $task['assignees'] ?? [];
        $needsApproval = !empty($task['require_approval']);
        $dueDate  = date('Y-m-d', strtotime("+{$dueDays} days"));

        // Resolve assignees (handles fixed IDs + dynamic placeholders)
        $targetUserId = (int)($context['target_user_id'] ?? 0);
        $resolvedAssignees = [];
        foreach ($assignees as $a) {
            if ($a === 'self_assign') {
                if ($actorId) $resolvedAssignees[] = $actorId;
            } elseif ($a === 'target_person') {
                if ($targetUserId) $resolvedAssignees[] = $targetUserId;
            } elseif ($a === 'their_tl') {
                $tlId = $targetUserId ? _resolveTlForUser($conn, $targetUserId) : null;
                if ($tlId) $resolvedAssignees[] = $tlId;
            } else {
                $resolvedAssignees[] = (int)$a;
            }
        }
        // Default to target person or actor if no assignees resolved
        if (empty($resolvedAssignees)) {
            $resolvedAssignees[] = $targetUserId ?: $actorId;
        }

        $primaryAssignee = $resolvedAssignees[0] ?? null;

        // Create task
        $conn->prepare("
            INSERT INTO tasks (title, description, priority, status, assigned_to, assigned_by, due_date, needs_approval, created_at)
            VALUES (?,?,?,'TODO',?,?,?,?,NOW())
        ")->execute([$title, $desc, $priority, $primaryAssignee, $actorId ?: $primaryAssignee, $dueDate, $needsApproval ? 1 : 0]);
        $taskId = (int)$conn->lastInsertId();

        // Track in workflow instances
        $conn->prepare("INSERT INTO hrms_workflow_task_instances (run_id, node_id, task_index, task_id, assigned_to, status) VALUES (?,?,?,?,?,?)")
             ->execute([$runId, $nodeKey, $idx, $taskId, $primaryAssignee, 'pending']);

        // Notify each assignee
        foreach ($resolvedAssignees as $uid) {
            if ($uid) {
                hrms_notify($conn, $uid, 'task', "New task assigned: {$title}", $desc, "task_detail.php?id={$taskId}");
            }
        }
    }
}

/**
 * Evaluate a condition node — returns 'yes' or 'no'
 */
function _evaluateCondition(array $data, array $context): string {
    $conditions = $data['conditions'] ?? [];
    $logic      = $data['logic'] ?? 'AND';
    if (empty($conditions)) return 'yes';

    $results = [];
    foreach ($conditions as $cond) {
        $field    = $cond['field']    ?? '';
        $operator = $cond['operator'] ?? '==';
        $value    = $cond['value']    ?? '';
        $actual   = $context[$field]  ?? '';
        $results[] = match($operator) {
            '=='       => $actual == $value,
            '!='       => $actual != $value,
            '>'        => $actual >  $value,
            '<'        => $actual <  $value,
            'contains' => str_contains((string)$actual, (string)$value),
            default    => false
        };
    }

    $pass = $logic === 'AND'
        ? !in_array(false, $results, true)
        : in_array(true, $results, true);

    return $pass ? 'yes' : 'no';
}

/**
 * Log a trigger fire
 */
function _logTrigger(PDO $conn, int $triggerId, ?int $runId, string $firedBy, array $context, string $status, string $notes): void {
    try {
        $conn->prepare("INSERT INTO hrms_trigger_log (trigger_id, workflow_run_id, fired_by, context_json, status, notes) VALUES (?,?,?,?,?,?)")
             ->execute([$triggerId, $runId, $firedBy, json_encode($context), $status, $notes]);
    } catch (Exception $e) {}
}

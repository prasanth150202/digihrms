<?php
/**
 * Task Timer Helper Functions
 * Manages automatic timer start/stop based on task status
 */

/**
 * Start a timer for a task
 * Called when task status changes to IN_PROGRESS
 */
function start_task_timer($conn, $task_id, $user_id) {
    try {
        // Stop any existing active timer for this task
        stop_task_timer($conn, $task_id, $user_id);

        // The time belongs to whoever the task is assigned to, not whoever dragged the card.
        // A lead moving a report's card used to bank the hours in the lead's own report and
        // leave the assignee showing nothing.
        $owner = task_timer_owner($conn, $task_id, $user_id);

        // Start new timer
        $conn->prepare("INSERT INTO task_timers (task_id, user_id, started_at) VALUES (?,?, CURRENT_TIMESTAMP)")
             ->execute([$task_id, $owner]);
        
        // Update task status
        $conn->prepare("UPDATE tasks SET timer_status='ACTIVE', timer_started_at=CURRENT_TIMESTAMP WHERE id=?")
             ->execute([$task_id]);
        
        return true;
    } catch (Exception $e) {
        error_log("Timer start error: " . $e->getMessage());
        return false;
    }
}

/**
 * Stop the active timer for a task
 * Called when task status changes away from IN_PROGRESS
 */
function stop_task_timer($conn, $task_id, $user_id) {
    try {
        // Close every open timer on the task, whoever owns it. Filtering by the caller left a
        // timer running forever when one person started it and another moved the card on.
        // TIMESTAMPDIFF in MySQL avoids PHP/MySQL timezone mismatches.
        $stmt = $conn->prepare("UPDATE task_timers
            SET ended_at = CURRENT_TIMESTAMP, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
            WHERE task_id = ? AND ended_at IS NULL");
        $stmt->execute([$task_id]);
        if (!$stmt->rowCount()) return false;

        // Update task timer status
        $conn->prepare("UPDATE tasks SET timer_status='INACTIVE', timer_started_at=NULL WHERE id=?")
             ->execute([$task_id]);

        return true;
    } catch (Exception $e) {
        error_log("Timer stop error: " . $e->getMessage());
        return false;
    }
}

/**
 * Whose time a task's timer records: the assignee, or the caller when it is unassigned.
 */
function task_timer_owner($conn, $task_id, $fallback_user_id): int {
    $stmt = $conn->prepare("SELECT assigned_to FROM tasks WHERE id=?");
    $stmt->execute([$task_id]);
    return (int)($stmt->fetchColumn() ?: $fallback_user_id);
}

/**
 * Get the active timer for a task (if any), whoever it belongs to — a lead opening a
 * report's task should still see that it is running. $user_id is kept for callers.
 */
function get_active_task_timer($conn, $task_id, $user_id = null) {
    $stmt = $conn->prepare("SELECT id, started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed_seconds FROM task_timers WHERE task_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$task_id]);
    return $stmt->fetch();
}

/**
 * Get total tracked time for a task (in hours)
 */
function get_total_task_timer_hours($conn, $task_id) {
    $stmt = $conn->prepare("SELECT SUM(duration_seconds) as total_seconds FROM task_timers WHERE task_id=? AND ended_at IS NOT NULL");
    $stmt->execute([$task_id]);
    $result = $stmt->fetch();
    $total_seconds = $result['total_seconds'] ?? 0;
    return round($total_seconds / 3600, 2);
}

/**
 * Format seconds to HH:MM:SS
 */
function format_duration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}

/**
 * Pause the active timer
 */
function pause_task_timer($conn, $task_id, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT id, started_at, total_paused_seconds FROM task_timers WHERE task_id=? AND user_id=? AND ended_at IS NULL LIMIT 1");
        $stmt->execute([$task_id, $user_id]);
        $timer = $stmt->fetch();
        
        if (!$timer) return false;
        
        $conn->prepare("UPDATE task_timers SET paused_at=CURRENT_TIMESTAMP WHERE id=?")
             ->execute([$timer['id']]);
        
        $conn->prepare("UPDATE tasks SET timer_status='PAUSED' WHERE id=?")
             ->execute([$task_id]);
        
        return true;
    } catch (Exception $e) {
        error_log("Timer pause error: " . $e->getMessage());
        return false;
    }
}

/**
 * Resume the paused timer
 */
function resume_task_timer($conn, $task_id, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT id, paused_at, total_paused_seconds FROM task_timers WHERE task_id=? AND user_id=? AND ended_at IS NULL AND paused_at IS NOT NULL LIMIT 1");
        $stmt->execute([$task_id, $user_id]);
        $timer = $stmt->fetch();
        
        if (!$timer) return false;
        
        $paused_duration = strtotime('now') - strtotime($timer['paused_at']);
        $new_total_paused = $timer['total_paused_seconds'] + $paused_duration;
        
        $conn->prepare("UPDATE task_timers SET paused_at=NULL, total_paused_seconds=? WHERE id=?")
             ->execute([$new_total_paused, $timer['id']]);
        
        $conn->prepare("UPDATE tasks SET timer_status='ACTIVE' WHERE id=?")
             ->execute([$task_id]);
        
        return true;
    } catch (Exception $e) {
        error_log("Timer resume error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get elapsed time for active timer (in seconds)
 */
function get_elapsed_timer_seconds($conn, $task_id, $user_id) {
    $timer = get_active_task_timer($conn, $task_id, $user_id);
    if (!$timer) return 0;
    return (int)$timer['elapsed_seconds'];
}

/**
 * Make sure every In Progress task has a running timer.
 *
 * The column means "I am working on this", so the timer should be open whenever a card sits
 * there. Timers used to be force-closed after 12 hours, which left the card in In Progress
 * with nothing recording — the next day's work vanished. Clipping against TeamLogger's
 * activity now bounds the total, so a timer left open across a night costs nothing.
 */
function resume_in_progress_timers($conn, $user_id) {
    try {
        $stale = $conn->prepare("SELECT id FROM tasks
            WHERE assigned_to = ? AND status = 'IN_PROGRESS' AND deleted_at IS NULL
              AND (timer_status IS NULL OR timer_status <> 'ACTIVE')");
        $stale->execute([$user_id]);
        $ids = $stale->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $tid) start_task_timer($conn, (int)$tid, $user_id);
        return count($ids);
    } catch (Exception $e) {
        error_log("Resume timer error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Close timers left running on tasks that are no longer In Progress.
 *
 * Only a drag on the board (update_status) and the status form on task_detail stop a
 * timer. Submit for review, approve, reject, block, quick edit, delete, the API and
 * automations all move a card out of In Progress and leave its timer open — and the report
 * reads an open timer as running until now, so a finished task kept earning every day.
 *
 * Each orphan is ended when the task actually left In Progress: the first status change
 * logged after the timer started. With no such log, the task's deletion or last update
 * stands in; failing that it closes at its own start and counts nothing. Marked
 * auto_closed so the report flags it.
 */
function close_orphan_task_timers($conn): int {
    try {
        $rows = $conn->query("SELECT tt.id, tt.task_id,
                GREATEST(tt.started_at, LEAST(NOW(), COALESCE(
                    (SELECT MIN(al.created_at) FROM task_activity_logs al
                      WHERE al.task_id = tt.task_id AND al.created_at >= tt.started_at
                        AND (al.action IN ('SUBMITTED','APPROVED','REJECTED','BLOCKED','DELETED')
                             OR (al.action = 'STATUS_CHANGED'
                                 AND (al.detail LIKE '%→%' OR al.detail LIKE 'status changed to%')
                                 AND al.detail NOT LIKE '%→ IN_PROGRESS'
                                 AND al.detail NOT LIKE 'status changed to IN_PROGRESS'))),
                    t.deleted_at, t.updated_at, tt.started_at))) AS end_at
            FROM task_timers tt
            JOIN tasks t ON t.id = tt.task_id
            WHERE tt.ended_at IS NULL
              AND (t.status <> 'IN_PROGRESS' OR t.deleted_at IS NOT NULL)")->fetchAll();
        $upd = $conn->prepare("UPDATE task_timers
            SET ended_at = ?, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?), auto_closed = 1
            WHERE id = ? AND ended_at IS NULL");
        $flag = $conn->prepare("UPDATE tasks SET timer_status='INACTIVE', timer_started_at=NULL WHERE id=?");
        foreach ($rows as $r) {
            $upd->execute([$r['end_at'], $r['end_at'], $r['id']]);
            $flag->execute([$r['task_id']]);
        }
        return count($rows);
    } catch (Exception $e) {
        error_log("Orphan timer close error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Turn raw timer intervals into per-day and per-task totals.
 *
 * Intervals are split at midnight so an evening session never credits its hours to the
 * following day. Where TeamLogger activity is known for a day, each piece is then clipped
 * to those windows — a task left In Progress overnight stops earning the moment TeamLogger
 * says the person stopped, and idle and break time never counts.
 *
 * Days with no TeamLogger data count nothing. Timers stay open while a card sits in In
 * Progress, so the raw figure for such a day is just "hours since midnight" for every open
 * card — a weekend or an unsynced today put 16h on each of them. Those days are listed in
 * `unverified` with the raw timer span as `raw`, so the report can say why they are empty.
 *
 * Time when several cards were in In Progress together is split evenly between them —
 * there is no telling which one was being worked, and counting each in full let seven
 * parked cards turn 16 working hours into 117. So per-task figures add up to real time:
 *   tracked = sum of every task's share. Equals covered, give or take rounding.
 *   covered = the union of the intervals, i.e. wall-clock time with at least one task
 *             running. This is the one to compare against TeamLogger's active hours.
 *   max_open = most cards open at the same moment, so the report can say when the split
 *              was spread thin.
 *
 * @param array $rows    each ['task_id','started_at','ended_at']
 * @param array $windows date => list of [start_ts, end_ts] counted as working time
 */
function summarize_task_time(array $rows, string $from, string $to, array $windows = [], int $day_cap_h = 16): array {
    $range_start = strtotime($from . ' 00:00:00');
    $range_end   = strtotime($to   . ' 00:00:00 +1 day');
    $cap         = $day_cap_h * 3600;

    $days = [];
    foreach ($rows as $r) {
        $tid = (int)$r['task_id'];
        $s   = max(strtotime($r['started_at']), $range_start);
        $e   = min(strtotime($r['ended_at']),   $range_end);
        if ($e <= $s) continue;

        $cur = $s;
        while ($cur < $e) {
            $d        = date('Y-m-d', $cur);
            $next_day = strtotime($d . ' 00:00:00 +1 day');
            $seg_end  = min($e, $next_day);

            if (!isset($days[$d])) {
                $days[$d] = ['tasks' => [], 'intervals' => [], 'raw' => [], 'verified' => isset($windows[$d])];
            }

            // Only time inside TeamLogger's working windows counts.
            if (isset($windows[$d])) {
                foreach ($windows[$d] as [$ws, $we]) {
                    $ps = max($cur, $ws);
                    $pe = min($seg_end, $we);
                    if ($pe > $ps) {
                        $days[$d]['tasks'][$tid][] = [$ps, $pe];
                        $days[$d]['intervals'][]   = [$ps, $pe];
                    }
                }
            } else {
                $days[$d]['raw'][] = [$cur, $seg_end];
            }

            $cur = $seg_end;
        }
    }

    // A day can be known to have no working time at all; keep it as a verified zero.
    foreach ($windows as $d => $_w) {
        if (!isset($days[$d])) continue;
        $days[$d]['verified'] = true;
    }

    $by_day = [];
    $by_task = [];
    $total_tracked = 0;
    $total_covered = 0;
    $unverified = [];
    $peak_open = 0;

    foreach ($days as $d => $bucket) {
        [$shares, $max_open] = _split_shares($bucket['tasks']);
        $tracked = 0;
        foreach ($shares as $tid => $secs) {
            $secs = min($secs, $cap);
            $bucket['tasks'][$tid] = $secs;
            $tracked += $secs;
            $by_task[$tid] = ($by_task[$tid] ?? 0) + $secs;
        }
        $peak_open = max($peak_open, $max_open);

        // Union of intervals — overlapping work counts once against wall-clock time. The
        // merged pieces are kept so the report can show exactly which minutes it counted,
        // which is the only way to tell an excluded break from a missing one.
        $covered = 0;
        $slices  = [];
        $ivs = $bucket['intervals'];
        usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
        $cs = $ce = null;
        foreach ($ivs as $iv) {
            if ($cs === null)  { [$cs, $ce] = $iv; continue; }
            if ($iv[0] <= $ce) { $ce = max($ce, $iv[1]); continue; }
            $covered += $ce - $cs;
            $slices[] = [$cs, $ce];
            [$cs, $ce] = $iv;
        }
        if ($cs !== null) { $covered += $ce - $cs; $slices[] = [$cs, $ce]; }
        $covered = min($covered, $cap);

        // TeamLogger emits zero-length and one-minute fragments, and stretches split a few
        // seconds apart read as separate periods. Join anything under a minute apart and
        // drop what is left under a minute, so this is legible rather than literal.
        $clean = [];
        foreach ($slices as [$ss, $se]) {
            if ($clean && $ss - $clean[count($clean) - 1][1] < 60) {
                $clean[count($clean) - 1][1] = max($clean[count($clean) - 1][1], $se);
                continue;
            }
            $clean[] = [$ss, $se];
        }
        $slices = array_values(array_filter($clean, fn($x) => $x[1] - $x[0] >= 60));

        $raw = $bucket['raw'] ? min(_union_seconds($bucket['raw']), $cap) : 0;
        $by_day[$d] = ['tracked' => $tracked, 'covered' => $covered, 'slices' => $slices,
                       'tasks' => $bucket['tasks'], 'verified' => $bucket['verified'], 'raw' => $raw,
                       'max_open' => $max_open];
        if (!$bucket['verified'] && $raw > 0) $unverified[] = $d;

        $total_tracked += $tracked;
        $total_covered += $covered;
    }

    ksort($by_day);
    arsort($by_task);
    sort($unverified);
    return ['days' => $by_day, 'tasks' => $by_task, 'tracked' => $total_tracked,
            'covered' => $total_covered, 'unverified' => $unverified, 'max_open' => $peak_open];
}

/** Seconds covered by a list of [start, end] intervals, overlaps counted once. */
function _union_seconds(array $ivs): int {
    usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
    $total = 0; $cs = $ce = null;
    foreach ($ivs as [$s, $e]) {
        if ($cs === null) { [$cs, $ce] = [$s, $e]; continue; }
        if ($s <= $ce)    { $ce = max($ce, $e); continue; }
        $total += $ce - $cs;
        [$cs, $ce] = [$s, $e];
    }
    if ($cs !== null) $total += $ce - $cs;
    return $total;
}

/**
 * Share out time between tasks open at the same moment: each stretch is divided evenly
 * among the cards running during it. Returns [task_id => seconds, most open at once].
 *
 * @param array $by_task task_id => list of [start, end]
 */
function _split_shares(array $by_task): array {
    $events = [];
    foreach ($by_task as $tid => $ivs) {
        // Merge each task's own pieces first, so a duplicate timer is not a second card.
        usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ivs as [$s, $e]) {
            if ($merged && $s <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }
        foreach ($merged as [$s, $e]) { $events[] = [$s, 1, $tid]; $events[] = [$e, -1, $tid]; }
    }
    // Ends sort before starts at the same instant, so back-to-back cards never overlap.
    usort($events, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    $shares = [];
    $open = [];
    $max_open = 0;
    $prev = null;
    foreach ($events as [$t, $kind, $tid]) {
        if ($prev !== null && $t > $prev && $open) {
            $each = ($t - $prev) / count($open);
            foreach ($open as $o => $_) $shares[$o] = ($shares[$o] ?? 0) + $each;
        }
        if ($kind === 1) $open[$tid] = true; else unset($open[$tid]);
        $max_open = max($max_open, count($open));
        $prev = $t;
    }
    return [array_map(fn($x) => (int)round($x), $shares), $max_open];
}

/** Seconds as "6h 12m" (or "—" for nothing). */
function fmt_hm($seconds): string {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '—';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if (!$h) return $m . 'm';
    return $h . 'h ' . ($m ? $m . 'm' : '');
}
?>

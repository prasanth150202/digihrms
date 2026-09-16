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
        
        // Start new timer
        $conn->prepare("INSERT INTO task_timers (task_id, user_id, started_at) VALUES (?,?, CURRENT_TIMESTAMP)")
             ->execute([$task_id, $user_id]);
        
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
        // Find the active timer (no end_at)
        $stmt = $conn->prepare("SELECT id, started_at FROM task_timers WHERE task_id=? AND user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$task_id, $user_id]);
        $timer = $stmt->fetch();
        
        if (!$timer) {
            // No active timer to stop
            return false;
        }
        
        // Calculate duration using MySQL TIMESTAMPDIFF to avoid timezone issues
        $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) as duration_in_seconds");
        $stmt->execute([$timer['started_at']]);
        $result = $stmt->fetch();
        $duration = (int)$result['duration_in_seconds'];
        
        // Update timer with end time and duration
        $conn->prepare("UPDATE task_timers SET ended_at=CURRENT_TIMESTAMP, duration_seconds=? WHERE id=?")
             ->execute([$duration, $timer['id']]);
        
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
 * Get the active timer for a task (if any)
 */
function get_active_task_timer($conn, $task_id, $user_id) {
    $stmt = $conn->prepare("SELECT id, started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed_seconds FROM task_timers WHERE task_id=? AND user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$task_id, $user_id]);
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
 * Close timers left running past $max_hours.
 * The board starts a timer when a card enters In Progress and stops it only when the card
 * leaves, so a card parked there overnight would otherwise bank the whole span as work.
 * The capped duration is a guess, which is why the row is marked auto_closed for reports.
 */
function close_stale_task_timers($conn, $user_id, $max_hours = 12) {
    $h = max(1, (int)$max_hours);
    try {
        $sel = $conn->prepare("SELECT id, task_id FROM task_timers
            WHERE user_id = ? AND ended_at IS NULL AND started_at < NOW() - INTERVAL $h HOUR");
        $sel->execute([$user_id]);
        $rows = $sel->fetchAll();
        if (!$rows) return 0;

        $close = $conn->prepare("UPDATE task_timers
            SET ended_at = started_at + INTERVAL $h HOUR, duration_seconds = ?, auto_closed = 1
            WHERE id = ?");
        $idle = $conn->prepare("UPDATE tasks SET timer_status='INACTIVE', timer_started_at=NULL WHERE id=?");
        foreach ($rows as $r) {
            $close->execute([$h * 3600, (int)$r['id']]);
            $idle->execute([(int)$r['task_id']]);
        }
        return count($rows);
    } catch (Exception $e) {
        error_log("Stale timer close error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Turn raw timer intervals into per-day and per-task totals.
 *
 * Intervals are split at midnight so an evening session never credits its hours to the
 * following day, and each task's contribution to a single day is capped, since no one
 * works one task for more than $day_cap_h hours in a day.
 *
 * Two different totals come out of this, and they answer different questions:
 *   tracked = sum of every task's time. Two tasks left In Progress at once each count in
 *             full, by design, so this can exceed the hours in a day.
 *   covered = the union of the intervals, i.e. wall-clock time with at least one task
 *             running. This is the one to compare against TeamLogger's active hours.
 *
 * @param array $rows each ['task_id','started_at','ended_at']
 */
function summarize_task_time(array $rows, string $from, string $to, int $day_cap_h = 16, int $max_session_h = 12): array {
    $range_start = strtotime($from . ' 00:00:00');
    $range_end   = strtotime($to   . ' 00:00:00 +1 day');
    $cap         = $day_cap_h * 3600;
    $max_session = $max_session_h * 3600;

    $days    = [];
    $suspect = ['count' => 0, 'seconds' => 0, 'tasks' => []];
    foreach ($rows as $r) {
        $tid = (int)$r['task_id'];

        // A session longer than the abandonment threshold is a card left in In Progress,
        // not a day's work. Clamping it to a per-day ceiling would launder it into a
        // believable 16h; excluding it and saying so keeps the totals honest.
        $full = strtotime($r['ended_at']) - strtotime($r['started_at']);
        if ($full > $max_session) {
            $suspect['count']++;
            $suspect['seconds'] += $full;
            $suspect['tasks'][$tid] = ($suspect['tasks'][$tid] ?? 0) + $full;
            continue;
        }

        $s = max(strtotime($r['started_at']), $range_start);
        $e = min(strtotime($r['ended_at']),   $range_end);
        if ($e <= $s) continue;

        $cur = $s;
        while ($cur < $e) {
            $d        = date('Y-m-d', $cur);
            $next_day = strtotime($d . ' 00:00:00 +1 day');
            $seg_end  = min($e, $next_day);

            if (!isset($days[$d])) $days[$d] = ['tasks' => [], 'intervals' => []];
            $days[$d]['tasks'][$tid]  = ($days[$d]['tasks'][$tid] ?? 0) + ($seg_end - $cur);
            $days[$d]['intervals'][]  = [$cur, $seg_end];

            $cur = $seg_end;
        }
    }

    $by_day = [];
    $by_task = [];
    $total_tracked = 0;
    $total_covered = 0;

    foreach ($days as $d => $bucket) {
        $tracked = 0;
        foreach ($bucket['tasks'] as $tid => $secs) {
            $secs = min($secs, $cap);
            $bucket['tasks'][$tid] = $secs;
            $tracked += $secs;
            $by_task[$tid] = ($by_task[$tid] ?? 0) + $secs;
        }

        // Union of intervals — overlapping work counts once against wall-clock time.
        $covered = 0;
        $ivs = $bucket['intervals'];
        usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
        $cs = $ce = null;
        foreach ($ivs as $iv) {
            if ($cs === null)        { [$cs, $ce] = $iv; continue; }
            if ($iv[0] <= $ce)       { $ce = max($ce, $iv[1]); continue; }
            $covered += $ce - $cs;
            [$cs, $ce] = $iv;
        }
        if ($cs !== null) $covered += $ce - $cs;
        $covered = min($covered, $cap);

        $by_day[$d] = ['tracked' => $tracked, 'covered' => $covered, 'tasks' => $bucket['tasks']];
        $total_tracked += $tracked;
        $total_covered += $covered;
    }

    ksort($by_day);
    arsort($by_task);
    arsort($suspect['tasks']);
    return ['days' => $by_day, 'tasks' => $by_task, 'tracked' => $total_tracked,
            'covered' => $total_covered, 'suspect' => $suspect];
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

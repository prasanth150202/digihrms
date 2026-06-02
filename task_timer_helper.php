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
?>

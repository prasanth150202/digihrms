<?php
require_once 'config.php';
require_login();
if (!has_role('SUPER_ADMIN')) die('Access denied');

$steps = []; $errors = [];
function run_sql($conn, $label, $sql) {
    global $steps, $errors;
    try {
        $conn->exec($sql);
        $steps[] = "✅ $label";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'Duplicate column'))
            $steps[] = "⏭️ $label (already exists)";
        else
            $errors[] = "❌ $label: " . $e->getMessage();
    }
}

// ── 1. Triggers ────────────────────────────────────────────────────────────
run_sql($conn, 'Create hrms_triggers', "
CREATE TABLE IF NOT EXISTS hrms_triggers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    description     TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    trigger_type    ENUM('time','login','task_created','task_status','task_assigned','task_overdue','task_completed') COLLATE utf8mb4_unicode_ci NOT NULL,
    conditions_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'extra conditions like time=09:00, status=DONE',
    workflow_id     INT NOT NULL COMMENT 'FK to hrms_workflow_templates.id',
    created_by      INT NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_fired_at   DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (trigger_type),
    INDEX idx_workflow (workflow_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Trigger fire log ────────────────────────────────────────────────────
run_sql($conn, 'Create hrms_trigger_log', "
CREATE TABLE IF NOT EXISTS hrms_trigger_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    trigger_id      INT NOT NULL,
    workflow_run_id INT NULL COMMENT 'FK to hrms_workflow_runs.id',
    fired_by        ENUM('cron','login','task_event','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
    context_json    TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'what caused the fire',
    status          ENUM('fired','skipped','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fired',
    notes           TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    fired_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trigger (trigger_id),
    INDEX idx_fired_at (fired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 3. Login fire tracking (once-per-day) ─────────────────────────────────
run_sql($conn, 'Create hrms_trigger_login_track', "
CREATE TABLE IF NOT EXISTS hrms_trigger_login_track (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    trigger_id  INT NOT NULL,
    fired_date  DATE NOT NULL,
    UNIQUE KEY uq_user_trigger_date (user_id, trigger_id, fired_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Trigger Migration</title>
<style>body{font-family:'Segoe UI',sans-serif;max-width:700px;margin:60px auto;background:#f0f4f8;}
.step{padding:8px 14px;margin:4px 0;border-radius:6px;background:#fff;border:1px solid #e2e8f0;font-size:14px;}
.err{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}
.done{background:#dcfce7;border-color:#86efac;color:#166534;margin-top:20px;font-weight:700;padding:14px;border-radius:8px;}</style>
</head><body>
<h2>HRMS Trigger Tables Migration</h2>
<?php foreach($steps as $s): ?><div class="step"><?= htmlspecialchars($s) ?></div><?php endforeach; ?>
<?php foreach($errors as $e): ?><div class="step err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
<?php if(!$errors): ?>
<div class="done">✅ Done — <a href="triggers.php">Go to Triggers</a></div>
<?php endif; ?>
</body></html>

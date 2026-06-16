<?php
require_once 'config.php';

require_login();
if (!has_role('SUPER_ADMIN')) { die('Access denied'); }

$steps  = [];
$errors = [];

function run_sql($conn, $label, $sql) {
    global $steps, $errors;
    try {
        $conn->exec($sql);
        $steps[] = "✅ $label";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'Duplicate column')) {
            $steps[] = "⏭️ $label (already exists)";
        } else {
            $errors[] = "❌ $label: " . $e->getMessage();
        }
    }
}

// ── 1. Badge definitions ───────────────────────────────────────────────────
run_sql($conn, 'Create hrms_badges', "
CREATE TABLE IF NOT EXISTS hrms_badges (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    icon        VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '🏅',
    description TEXT         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Employee badge awards ───────────────────────────────────────────────
run_sql($conn, 'Create hrms_employee_badges', "
CREATE TABLE IF NOT EXISTS hrms_employee_badges (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    badge_id    INT NOT NULL,
    task_id     INT NULL COMMENT 'task that triggered this award',
    earned_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_badge (employee_id, badge_id),
    INDEX idx_employee (employee_id),
    INDEX idx_badge    (badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 3. Quiz questions per task ─────────────────────────────────────────────
run_sql($conn, 'Create hrms_task_quiz', "
CREATE TABLE IF NOT EXISTS hrms_task_quiz (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    task_id     INT NOT NULL COMMENT 'FK to tasks.id',
    question    TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    options     JSON NOT NULL COMMENT '[\"Option A\",\"Option B\",...]',
    correct_idx TINYINT NOT NULL DEFAULT 0 COMMENT '0-based index into options',
    sort_order  TINYINT NOT NULL DEFAULT 0,
    INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 4. Quiz attempts per employee ──────────────────────────────────────────
run_sql($conn, 'Create hrms_task_quiz_attempts', "
CREATE TABLE IF NOT EXISTS hrms_task_quiz_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    task_id      INT NOT NULL,
    employee_id  INT NOT NULL,
    answers_json JSON NOT NULL COMMENT '{\"q_id\": chosen_idx, ...}',
    score_pct    TINYINT NOT NULL DEFAULT 0,
    passed       TINYINT(1) NOT NULL DEFAULT 0,
    tl_approved  TINYINT(1) NULL COMMENT 'NULL=pending, 1=approved, 0=rejected',
    tl_reviewed_by INT NULL,
    tl_reviewed_at DATETIME NULL,
    tl_note      TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task_emp (task_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 5. Add columns to tasks table ─────────────────────────────────────────
run_sql($conn, 'tasks: add is_learning_task',  "ALTER TABLE tasks ADD COLUMN is_learning_task  TINYINT(1) NOT NULL DEFAULT 0");
run_sql($conn, 'tasks: add learning_badge_id', "ALTER TABLE tasks ADD COLUMN learning_badge_id INT NULL");
run_sql($conn, 'tasks: add learning_material', "ALTER TABLE tasks ADD COLUMN learning_material TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
run_sql($conn, 'tasks: add learning_pass_pct', "ALTER TABLE tasks ADD COLUMN learning_pass_pct TINYINT NOT NULL DEFAULT 80");
run_sql($conn, 'tasks: add quiz_required',     "ALTER TABLE tasks ADD COLUMN quiz_required     TINYINT(1) NOT NULL DEFAULT 0");

// ── 6. Point rules ─────────────────────────────────────────────────────────
run_sql($conn, 'Point rule: hrms_learning_completed', "
    INSERT IGNORE INTO point_rules (rule_key, label, points)
    VALUES ('hrms_learning_completed', 'Learning: Task completed', 20)
");
run_sql($conn, 'Point rule: hrms_quiz_passed', "
    INSERT IGNORE INTO point_rules (rule_key, label, points)
    VALUES ('hrms_quiz_passed', 'Learning: Quiz passed (bonus)', 10)
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Learning Module Migration</title>
<style>
body { font-family:'Segoe UI',sans-serif; max-width:700px; margin:60px auto; background:#f0f4f8; color:#0f172a; }
h2   { color:#1e3a5f; }
.step { padding:8px 14px; margin:4px 0; border-radius:6px; background:#fff; border:1px solid #e2e8f0; font-size:14px; }
.err  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
.done { background:#dcfce7; border-color:#86efac; color:#166534; margin-top:20px; font-weight:700; padding:14px; }
</style>
</head>
<body>
<h2>Learning Module Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — Learning module tables are ready.<br><br>
<a href="learning.php" style="color:#2563eb;">→ Go to Learning Dashboard</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

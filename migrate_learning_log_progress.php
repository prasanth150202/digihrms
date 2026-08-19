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

run_sql($conn, 'hrms_learning_logs: add progress_pct', "ALTER TABLE hrms_learning_logs ADD COLUMN progress_pct TINYINT NOT NULL DEFAULT 0");
run_sql($conn, 'hrms_learning_logs: add proof_url',     "ALTER TABLE hrms_learning_logs ADD COLUMN proof_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
run_sql($conn, 'hrms_learning_logs: allow dropped status', "ALTER TABLE hrms_learning_logs MODIFY COLUMN status ENUM('pursuing','completed','dropped') NOT NULL DEFAULT 'pursuing'");

run_sql($conn, 'Create hrms_learning_log_updates', "
CREATE TABLE IF NOT EXISTS hrms_learning_log_updates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    log_id       INT NOT NULL,
    progress_pct TINYINT NOT NULL,
    status       ENUM('pursuing','completed','dropped') NOT NULL,
    note         TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Backfill: completed entries are at 100%, everything else stays at the default 0%.
run_sql($conn, 'hrms_learning_logs: backfill progress_pct for completed rows', "
    UPDATE hrms_learning_logs SET progress_pct=100 WHERE status='completed' AND progress_pct=0
");

// Give every existing row at least one history entry so the timeline isn't empty.
run_sql($conn, 'hrms_learning_log_updates: seed initial history row per existing log', "
    INSERT INTO hrms_learning_log_updates (log_id, progress_pct, status, note, created_at)
    SELECT id, progress_pct, status, 'Initial entry', COALESCE(completed_on, learned_on, created_at)
    FROM hrms_learning_logs
    WHERE id NOT IN (SELECT DISTINCT log_id FROM hrms_learning_log_updates)
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Learning Log Progress Migration</title>
<style>
body { font-family:'Segoe UI',sans-serif; max-width:700px; margin:60px auto; background:#f0f4f8; color:#0f172a; }
h2   { color:#1e3a5f; }
.step { padding:8px 14px; margin:4px 0; border-radius:6px; background:#fff; border:1px solid #e2e8f0; font-size:14px; }
.err  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
.done { background:#dcfce7; border-color:#86efac; color:#166534; margin-top:20px; font-weight:700; padding:14px; }
</style>
</head>
<body>
<h2>Learning Log Progress Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — progress %, proof-of-completion, drop, and timestamped history are ready.<br><br>
<a href="learning.php" style="color:#2563eb;">→ Go to Learning Dashboard</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

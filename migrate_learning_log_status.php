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

run_sql($conn, 'hrms_learning_logs: add status', "ALTER TABLE hrms_learning_logs ADD COLUMN status ENUM('pursuing','completed') NOT NULL DEFAULT 'pursuing'");
run_sql($conn, 'hrms_learning_logs: add completed_on', "ALTER TABLE hrms_learning_logs ADD COLUMN completed_on DATE NULL");

// Entries logged before this migration were logged as "what I learned" —
// i.e. already done. Backfill them to completed so they don't show as
// still-in-progress.
run_sql($conn, 'hrms_learning_logs: backfill existing rows to completed', "
    UPDATE hrms_learning_logs SET status='completed', completed_on=learned_on
    WHERE status='pursuing'
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Learning Log Status Migration</title>
<style>
body { font-family:'Segoe UI',sans-serif; max-width:700px; margin:60px auto; background:#f0f4f8; color:#0f172a; }
h2   { color:#1e3a5f; }
.step { padding:8px 14px; margin:4px 0; border-radius:6px; background:#fff; border:1px solid #e2e8f0; font-size:14px; }
.err  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
.done { background:#dcfce7; border-color:#86efac; color:#166534; margin-top:20px; font-weight:700; padding:14px; }
</style>
</head>
<body>
<h2>Learning Log Status Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — Pursuing/Completed status is ready.<br><br>
<a href="learning.php" style="color:#2563eb;">→ Go to Learning Dashboard</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

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

// ── Self-logged learning entries ────────────────────────────────────────────
run_sql($conn, 'Create hrms_learning_logs', "
CREATE TABLE IF NOT EXISTS hrms_learning_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title       VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    notes       TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    learned_on  DATE NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

run_sql($conn, 'Point rule: hrms_learning_self_logged', "
    INSERT IGNORE INTO point_rules (rule_key, label, points)
    VALUES ('hrms_learning_self_logged', 'Learning: Logged something you learned', 5)
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Learning Log Migration</title>
<style>
body { font-family:'Segoe UI',sans-serif; max-width:700px; margin:60px auto; background:#f0f4f8; color:#0f172a; }
h2   { color:#1e3a5f; }
.step { padding:8px 14px; margin:4px 0; border-radius:6px; background:#fff; border:1px solid #e2e8f0; font-size:14px; }
.err  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
.done { background:#dcfce7; border-color:#86efac; color:#166534; margin-top:20px; font-weight:700; padding:14px; }
</style>
</head>
<body>
<h2>Learning Log Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — the self-logged learning table is ready.<br><br>
<a href="learning.php" style="color:#2563eb;">→ Go to Learning Dashboard</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

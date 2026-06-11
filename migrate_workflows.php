<?php
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

// Only superadmin can run migrations
require_login();
if (!has_role('SUPER_ADMIN')) { die('Access denied'); }

$steps = [];
$errors = [];

function run_sql($conn, $label, $sql) {
    global $steps, $errors;
    try {
        $conn->exec($sql);
        $steps[] = "✅ $label";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), "Duplicate column")) {
            $steps[] = "⏭️ $label (already exists)";
        } else {
            $errors[] = "❌ $label: " . $e->getMessage();
        }
    }
}

// ── 1. Workflow templates ──────────────────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_templates', "
CREATE TABLE IF NOT EXISTS hrms_workflow_templates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    description     TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    created_by      INT NOT NULL,
    draft_layout    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'current unsaved/draft canvas JSON',
    published_layout LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'last published canvas JSON',
    current_version INT NOT NULL DEFAULT 0,
    published_version INT NOT NULL DEFAULT 0,
    has_unpublished_changes TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Version history ─────────────────────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_versions', "
CREATE TABLE IF NOT EXISTS hrms_workflow_versions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    template_id     INT NOT NULL,
    version_number  INT NOT NULL,
    layout          LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    saved_by        INT NOT NULL,
    label           VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_template (template_id),
    UNIQUE KEY uq_template_version (template_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 3. Workflow runs (executions) ──────────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_runs', "
CREATE TABLE IF NOT EXISTS hrms_workflow_runs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    template_id     INT NOT NULL,
    triggered_by    INT NOT NULL COMMENT 'user who triggered / trigger engine user id',
    target_user_id  INT NULL COMMENT 'person this run applies to',
    status          ENUM('running','paused','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
    current_node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    context_json    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'runtime variables',
    started_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    INDEX idx_template (template_id),
    INDEX idx_target_user (target_user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 4. Task instances created by a run ────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_task_instances', "
CREATE TABLE IF NOT EXISTS hrms_workflow_task_instances (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    run_id          INT NOT NULL,
    node_id         VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    task_index      INT NOT NULL DEFAULT 0 COMMENT 'index within the task card',
    task_id         INT NOT NULL COMMENT 'FK to tasks.id',
    assigned_to     INT NULL,
    status          ENUM('pending','done','blocked','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    INDEX idx_run (run_id),
    INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 5. Node execution log ──────────────────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_node_log', "
CREATE TABLE IF NOT EXISTS hrms_workflow_node_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    run_id          INT NOT NULL,
    node_id         VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    node_type       VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    result_json     TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    executed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 6. Approval log per run ────────────────────────────────────────────────
run_sql($conn, 'Create hrms_workflow_approval_log', "
CREATE TABLE IF NOT EXISTS hrms_workflow_approval_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    run_id          INT NOT NULL,
    node_id         VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    actor_id        INT NOT NULL,
    action          ENUM('approved','rejected','acknowledged') COLLATE utf8mb4_unicode_ci NOT NULL,
    notes           TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    acted_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Workflow Migration</title>
<style>
body { font-family: 'Segoe UI', sans-serif; max-width: 700px; margin: 60px auto; background: #f0f4f8; color: #0f172a; }
h2 { color: #1e3a5f; }
.step { padding: 8px 14px; margin: 4px 0; border-radius: 6px; background: #fff; border: 1px solid #e2e8f0; font-size: 14px; }
.err { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }
.done { background: #dcfce7; border-color: #86efac; color: #166534; margin-top: 20px; font-weight: 700; padding: 14px; }
</style>
</head>
<body>
<h2>HRMS Workflow Tables Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — all workflow tables are ready.<br><br>
<a href="workflows.php" style="color:#2563eb;">→ Go to Workflow Builder</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

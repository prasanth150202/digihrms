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

// Adds a column only if it isn't already there — CREATE TABLE IF NOT EXISTS
// is a no-op against a pre-existing table, so a table created earlier with
// an incomplete/different schema never gets patched up by it.
function ensure_column($conn, $table, $column, $definition) {
    global $steps, $errors;
    try {
        $exists = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column))->fetch();
        if ($exists) {
            $steps[] = "⏭️ $table.$column (already exists)";
            return;
        }
        $conn->exec("ALTER TABLE `$table` ADD COLUMN $column $definition");
        $steps[] = "✅ Added $table.$column";
    } catch (PDOException $e) {
        $errors[] = "❌ $table.$column: " . $e->getMessage();
    }
}

function ensure_unique_key($conn, $table, $keyName, $columnsSql) {
    global $steps, $errors;
    try {
        $exists = $conn->query("SHOW KEYS FROM `$table` WHERE Key_name = " . $conn->quote($keyName))->fetch();
        if ($exists) {
            $steps[] = "⏭️ $table key $keyName (already exists)";
            return;
        }
        $conn->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$keyName` ($columnsSql)");
        $steps[] = "✅ Added $table unique key $keyName";
    } catch (PDOException $e) {
        $errors[] = "❌ $table key $keyName: " . $e->getMessage();
    }
}

// ── 1. Portal credentials / tokens (LinkedIn, Naukri, Indeed) ───────────────
run_sql($conn, 'Create portal_configs', "
CREATE TABLE IF NOT EXISTS portal_configs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    portal          VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    client_id       VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    client_secret   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    access_token    TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    token_expiry    DATETIME NULL,
    extra           TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'JSON: person_id/employer_id etc.',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal (portal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// In case portal_configs already existed with a different/older shape, patch it up column by column.
ensure_column($conn, 'portal_configs', 'client_id',     "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
ensure_column($conn, 'portal_configs', 'client_secret', "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
ensure_column($conn, 'portal_configs', 'access_token',  "TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
ensure_column($conn, 'portal_configs', 'token_expiry',  "DATETIME NULL");
ensure_column($conn, 'portal_configs', 'extra',         "TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'JSON: person_id/employer_id etc.'");
ensure_column($conn, 'portal_configs', 'created_at',    "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensure_column($conn, 'portal_configs', 'updated_at',    "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
ensure_unique_key($conn, 'portal_configs', 'uq_portal', '`portal`');

// ── 2. Record of jobs posted to each portal ──────────────────────────────────
run_sql($conn, 'Create job_portal_posts', "
CREATE TABLE IF NOT EXISTS job_portal_posts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    job_id          INT NOT NULL,
    portal          VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    external_id     VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    posted_url      VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    status          VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTED',
    posted_at       DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_portal (job_id, portal),
    INDEX idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Same defensive patch-up in case this pre-existed too.
ensure_column($conn, 'job_portal_posts', 'external_id', "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
ensure_column($conn, 'job_portal_posts', 'posted_url',  "VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
ensure_column($conn, 'job_portal_posts', 'status',      "VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTED'");
ensure_column($conn, 'job_portal_posts', 'posted_at',   "DATETIME NULL");
ensure_column($conn, 'job_portal_posts', 'created_at',  "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensure_unique_key($conn, 'job_portal_posts', 'uq_job_portal', '`job_id`, `portal`');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Portal Connections Migration</title>
<style>
body { font-family: 'Segoe UI', sans-serif; max-width: 700px; margin: 60px auto; background: #f0f4f8; color: #0f172a; }
h2 { color: #1e3a5f; }
.step { padding: 8px 14px; margin: 4px 0; border-radius: 6px; background: #fff; border: 1px solid #e2e8f0; font-size: 14px; }
.err { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }
.done { background: #dcfce7; border-color: #86efac; color: #166534; margin-top: 20px; font-weight: 700; padding: 14px; }
</style>
</head>
<body>
<h2>HRMS Portal Connections Migration</h2>
<?php foreach ($steps as $s): ?>
<div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<div class="step err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="done">✅ Migration complete — portal_configs and job_portal_posts are ready.<br><br>
<a href="portals.php" style="color:#2563eb;">→ Go to Portal Connections</a></div>
<?php else: ?>
<div class="step err" style="margin-top:16px;">⚠️ Some steps failed. Check errors above.</div>
<?php endif; ?>
</body>
</html>

<?php
// Migration: opt users in to the beta Workspace board (kanban + automatic time tracking)
// Run once: http://localhost/hrms/php_implementation/migrate_workspace_beta.php
// All changes are additive and default to "off" — no effect on existing users/pages.

require_once 'config.php';

function add_col_if_missing(PDO $conn, string $table, string $col, string $def): void {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
    if ($check) {
        echo "✅ $table.$col already exists — skipped.<br>";
        return;
    }
    $conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    echo "✅ Added $table.$col<br>";
}

try {
    // Time tracking lives on tasks.timer_status / task_timers, which the board reads
    // directly — the users table needs nothing beyond the opt-in flag.
    add_col_if_missing($conn, 'users', 'workspace_beta', "TINYINT(1) NOT NULL DEFAULT 0");
    echo "<br>Done. Everyone defaults to workspace_beta = 0 (feature stays hidden until enabled per user).";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}

<?php
// Migration: Add unblocked_at to HRMS tasks table
// Run once: http://localhost/hrms/php_implementation/migrate_unblocked_at.php

require_once 'config.php';

try {
    $check = $conn->query("SHOW COLUMNS FROM tasks LIKE 'unblocked_at'")->fetch();
    if ($check) {
        echo "✅ Column already exists — nothing to do.";
    } else {
        $conn->exec("ALTER TABLE tasks ADD COLUMN unblocked_at DATETIME DEFAULT NULL");
        echo "✅ Added unblocked_at to tasks table.";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}

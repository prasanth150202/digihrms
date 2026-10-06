<?php
// Migration: chained block requests
// Adds parent_id to task_block_requests so the person a task is waiting on
// can pass the block on to someone else (A → B → C).
// Run once: /migrate_block_chain.php

require_once 'config.php';

try {
    $check = $conn->query("SHOW COLUMNS FROM task_block_requests LIKE 'parent_id'")->fetch();
    if ($check) {
        echo "✅ Column already exists — nothing to do.";
    } else {
        $conn->exec("ALTER TABLE task_block_requests ADD COLUMN parent_id INT DEFAULT NULL AFTER task_id, ADD KEY idx_tbr_parent (parent_id)");
        echo "✅ Added parent_id to task_block_requests.";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}

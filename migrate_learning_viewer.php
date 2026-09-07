<?php
// Migration: per-user "view all learning" flag
// Run once: https://hrms.digifyce.com/migrate_learning_viewer.php
//
// Lets a Super Admin grant one non-admin account read access to every
// team's learning tasks and learning logs on learning.php, without
// making them SUPER_ADMIN / DEPT_MANAGER.

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
$log = [];

try {
    $has = $conn->query("SHOW COLUMNS FROM users LIKE 'can_view_all_learning'")->fetch();
    if ($has) {
        $log[] = "= users.can_view_all_learning already exists";
    } else {
        $conn->exec("ALTER TABLE users ADD COLUMN can_view_all_learning TINYINT(1) NOT NULL DEFAULT 0");
        $log[] = "+ users.can_view_all_learning added";
    }
} catch (Exception $e) {
    $log[] = "! users.can_view_all_learning: " . $e->getMessage();
}

echo implode("\n", $log) . "\n\nDone.\n";
echo "\nNext step: in Users, edit the person and tick 'See all teams' learning'.\n";

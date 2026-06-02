<?php
// Migration: Unified Points System
// Creates: points_log, point_rules
// Run once against u218702675_hrms

require_once 'config.php';

try {
    // ── 1. point_rules ─────────────────────────────────────────────
    $conn->exec("
        CREATE TABLE IF NOT EXISTS point_rules (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            rule_key   VARCHAR(80)  NOT NULL UNIQUE,
            label      VARCHAR(150) NOT NULL,
            points     INT          NOT NULL DEFAULT 0,
            is_active  TINYINT(1)   NOT NULL DEFAULT 1,
            updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ point_rules table created\n";

    // ── 2. Seed rules ───────────────────────────────────────────────
    $rules = [
        ['digiops_task_done',          'DigiOps: Task completed',                    10],
        ['digiops_task_ontime',        'DigiOps: Task completed on time (bonus)',      5],
        ['digiops_task_streak',        'DigiOps: Task streak bonus',                  5],
        ['hrms_task_done',             'HRMS: Task completed',                        10],
        ['hrms_task_ontime',           'HRMS: Task completed on time (bonus)',         5],
        ['attendance_ontime_morning',  'Attendance: On-time morning check-in',         2],
        ['attendance_ontime_evening',  'Attendance: On-time evening check-out',        2],
        ['attendance_full_week',       'Attendance: Full week no late',               10],
        ['attendance_full_month',      'Attendance: Full month perfect attendance',   50],
        ['delivery_ontime',            'Delivery: Asset submitted on time',           15],
        ['tl_team_task_done',          'TL Bonus: Team member completed a task',       2],
        ['tl_team_weekly_goal',        'TL Bonus: Full team hit weekly goal',         20],
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO point_rules (rule_key, label, points)
        VALUES (?, ?, ?)
    ");
    foreach ($rules as [$key, $label, $pts]) {
        $stmt->execute([$key, $label, $pts]);
    }
    echo "✓ " . count($rules) . " point rules seeded\n";

    // ── 3. points_log ───────────────────────────────────────────────
    $conn->exec("
        CREATE TABLE IF NOT EXISTS points_log (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT          NOT NULL,
            rule_key    VARCHAR(80)  NOT NULL,
            points      INT          NOT NULL DEFAULT 0,
            is_tl_bonus TINYINT(1)   NOT NULL DEFAULT 0,
            reason      VARCHAR(255) NULL,
            ref_id      VARCHAR(100) NULL COMMENT 'task_id, attendance_id etc',
            ref_type    VARCHAR(50)  NULL COMMENT 'digiops_task, hrms_task, attendance',
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pl_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            INDEX idx_pl_employee  (employee_id),
            INDEX idx_pl_rule      (rule_key),
            INDEX idx_pl_created   (created_at),
            UNIQUE KEY uq_pl_ref   (employee_id, rule_key, ref_id, ref_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ points_log table created\n";

    echo "\n✅ Points migration complete.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

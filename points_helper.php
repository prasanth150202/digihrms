<?php
// Shared points helper — read/write unified points_log

function pts_rule(PDO $conn, string $key): int {
    static $cache = [];
    if (!isset($cache[$key])) {
        $s = $conn->prepare('SELECT points FROM point_rules WHERE rule_key = ? AND is_active = 1');
        $s->execute([$key]);
        $cache[$key] = (int)($s->fetchColumn() ?: 0);
    }
    return $cache[$key];
}

// Award points — idempotent via unique key (employee_id + rule_key + ref_id + ref_type)
function pts_award(PDO $conn, int $employee_id, string $rule_key, string $ref_id = '', string $ref_type = '', string $reason = '', bool $is_tl_bonus = false): bool {
    $pts = pts_rule($conn, $rule_key);
    if ($pts <= 0) return false;
    try {
        $conn->prepare("
            INSERT IGNORE INTO points_log (employee_id, rule_key, points, is_tl_bonus, reason, ref_id, ref_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$employee_id, $rule_key, $pts, $is_tl_bonus ? 1 : 0, $reason, $ref_id, $ref_type]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get points summary for an employee
function pts_summary(PDO $conn, int $employee_id): array {
    $s = $conn->prepare("
        SELECT
            SUM(CASE WHEN is_tl_bonus = 0 THEN points ELSE 0 END) AS individual_pts,
            SUM(CASE WHEN is_tl_bonus = 1 THEN points ELSE 0 END) AS tl_bonus_pts,
            SUM(points)                                            AS total_pts,
            SUM(CASE WHEN is_tl_bonus = 0
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 THEN points ELSE 0 END)                           AS weekly_pts
        FROM points_log
        WHERE employee_id = ?
    ");
    $s->execute([$employee_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return [
        'individual_pts' => (int)($row['individual_pts'] ?? 0),
        'tl_bonus_pts'   => (int)($row['tl_bonus_pts']  ?? 0),
        'total_pts'      => (int)($row['total_pts']      ?? 0),
        'weekly_pts'     => (int)($row['weekly_pts']     ?? 0),
    ];
}

// Get recent points log for an employee
function pts_recent(PDO $conn, int $employee_id, int $limit = 20): array {
    $s = $conn->prepare("
        SELECT pl.rule_key, pl.points, pl.is_tl_bonus, pl.reason, pl.ref_type, pl.created_at,
               pr.label AS rule_label
        FROM points_log pl
        LEFT JOIN point_rules pr ON pr.rule_key = pl.rule_key
        WHERE pl.employee_id = ?
        ORDER BY pl.created_at DESC
        LIMIT " . (int)$limit . "
    ");
    $s->execute([$employee_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// Leaderboard — top N by total points, optionally filtered by dept or user_ids array
function pts_leaderboard(PDO $conn, int $limit = 5, string $period = 'alltime', ?int $dept_id = null, array $user_ids = []): array {
    $where_period  = $period === 'weekly' ? "AND pl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" : '';
    $where_dept    = $dept_id ? "AND e.dept_id = " . (int)$dept_id : '';
    $where_users   = '';
    if ($user_ids) {
        $in = implode(',', array_map('intval', $user_ids));
        $where_users = "AND u.id IN ($in)";
    }
    $s = $conn->prepare("
        SELECT e.id AS employee_id, e.name,
               r.name AS role_name,
               SUM(CASE WHEN pl.is_tl_bonus = 0 THEN pl.points ELSE 0 END) AS individual_pts,
               SUM(CASE WHEN pl.is_tl_bonus = 1 THEN pl.points ELSE 0 END) AS tl_bonus_pts,
               SUM(pl.points) AS total_pts
        FROM points_log pl
        JOIN employees e ON e.id = pl.employee_id
        JOIN users u ON u.email = e.email
        LEFT JOIN employee_roles er ON er.employee_id = e.id
        LEFT JOIN roles r ON r.id = er.role_id
        WHERE 1=1 $where_period $where_dept $where_users
        GROUP BY pl.employee_id
        ORDER BY total_pts DESC
        LIMIT " . (int)$limit . "
    ");
    $s->execute([]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// Award TL bonus when a team member earns points
function pts_award_tl_bonus(PDO $conn, int $member_employee_id, string $rule_key, string $ref_id, string $ref_type): void {
    // Find TL of this employee's department
    $s = $conn->prepare("
        SELECT er2.employee_id AS tl_emp_id
        FROM employee_roles er1
        JOIN employee_roles er2 ON er2.dept_id = er1.dept_id AND er2.is_team_lead = 1
        WHERE er1.employee_id = ? AND er1.employee_id != er2.employee_id
        LIMIT 1
    ");
    $s->execute([$member_employee_id]);
    $tl = $s->fetch(PDO::FETCH_ASSOC);
    if (!$tl) return;

    pts_award($conn, (int)$tl['tl_emp_id'], 'tl_team_task_done', $ref_id, $ref_type, 'Team member completed a task', true);
}
?>

<?php
// Inline cell edit API — called via fetch() from inline-edit.js
require_once 'config.php';
require_login();
$u    = current_user();
$role = $u['role'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$table = $body['table']  ?? '';
$id    = (int)($body['id'] ?? 0);
$field = $body['field']  ?? '';
$value = $body['value']  ?? '';

if (!$table || !$id || !$field) {
    http_response_code(400); echo json_encode(['error' => 'Missing params']); exit;
}

// ── Employees ─────────────────────────────────────────────────────
if ($table === 'employees') {
    if (!has_role('SUPER_ADMIN', 'HR_ADMIN')) {
        http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
    }
    $allowed = ['name', 'designation', 'phone', 'joining_date', 'probation_end_date', 'emp_code'];
    if (!in_array($field, $allowed, true)) {
        http_response_code(400); echo json_encode(['error' => 'Field not editable']); exit;
    }
    $value = trim($value);
    if ($field === 'name' && strlen($value) < 2) {
        http_response_code(400); echo json_encode(['error' => 'Name too short']); exit;
    }
    // Use column map to avoid any backtick injection risk
    $col_map = ['name'=>'name','designation'=>'designation','phone'=>'phone','joining_date'=>'joining_date','probation_end_date'=>'probation_end_date','emp_code'=>'emp_code'];
    $safe_col = $col_map[$field];
    $conn->prepare("UPDATE employees SET `$safe_col` = ? WHERE id = ?")->execute([$value, $id]);
    echo json_encode(['ok' => true, 'value' => $value]); exit;
}

// ── Leaves ────────────────────────────────────────────────────────
if ($table === 'leaves') {
    // HR/Admin can edit any pending leave; employee can only edit their own
    $leave = $conn->prepare("SELECT * FROM leaves WHERE id = ?")->execute([$id]) ? null : null;
    $stmt  = $conn->prepare("SELECT l.*, e.email FROM leaves l JOIN employees e ON e.id = l.employee_id WHERE l.id = ?");
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    if (!$leave) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $is_hr   = has_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER', 'TEAM_LEAD');
    $is_mine = $leave['email'] === $u['email'];

    if (!$is_hr && !$is_mine) {
        http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
    }

    // Only allow editing PENDING leaves
    if (!in_array($leave['status'], ['PENDING', 'TL_APPROVED']) && !has_role('SUPER_ADMIN', 'HR_ADMIN')) {
        http_response_code(400); echo json_encode(['error' => 'Cannot edit approved/rejected leaves']); exit;
    }

    $allowed = ['leave_type', 'from_date', 'to_date', 'reason'];
    if (!in_array($field, $allowed, true)) {
        http_response_code(400); echo json_encode(['error' => 'Field not editable']); exit;
    }

    $value = trim($value);
    $leave_col_map = ['leave_type'=>'leave_type','from_date'=>'from_date','to_date'=>'to_date','reason'=>'reason'];
    $safe_col = $leave_col_map[$field];

    // Recalculate days if date changed
    if ($field === 'from_date' || $field === 'to_date') {
        $from = $field === 'from_date' ? $value : $leave['from_date'];
        $to   = $field === 'to_date'   ? $value : $leave['to_date'];
        if ($from && $to && $to >= $from) {
            $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
            $conn->prepare("UPDATE leaves SET `$safe_col` = ?, days = ? WHERE id = ?")->execute([$value, $days, $id]);
            echo json_encode(['ok' => true, 'value' => $value, 'days' => $days]); exit;
        }
    }

    $conn->prepare("UPDATE leaves SET `$safe_col` = ? WHERE id = ?")->execute([$value, $id]);
    echo json_encode(['ok' => true, 'value' => $value]); exit;
}

http_response_code(400); echo json_encode(['error' => 'Unknown table']);

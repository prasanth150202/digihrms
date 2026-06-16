<?php
require_once 'config.php';
require_login();
$page      = 'leaves';
$pageTitle = 'Leave Management';
$u         = current_user();

// ── Auto-migrate permission_requests for TL approval chain ──
try { $conn->exec("ALTER TABLE permission_requests ADD COLUMN tl_approved_by INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE permission_requests ADD COLUMN tl_approved_at DATETIME NULL DEFAULT NULL"); } catch (PDOException $e) {}
// Extend status enum to include TL_APPROVED
try { $conn->exec("ALTER TABLE permission_requests MODIFY COLUMN status ENUM('PENDING','TL_APPROVED','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING'"); } catch (PDOException $e) {}

// ── Leave approval chain ─────────────────────────────────
if (isset($_GET['action'], $_GET['id']) && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD')) {
    $lid = (int)$_GET['id'];
    $act = $_GET['action'];

    // For TEAM_LEAD: verify the leave belongs to their dept before acting
    if ($u['role'] === 'TEAM_LEAD') {
        $tlDeptChk = $conn->prepare("
            SELECT er.dept_id FROM employee_roles er
            JOIN employees e ON e.id = er.employee_id
            JOIN users u2 ON u2.email = e.email
            WHERE u2.id = ? AND er.is_team_lead = 1 LIMIT 1
        ");
        $tlDeptChk->execute([$u['id']]);
        $tlDeptChkId = $tlDeptChk->fetchColumn();
        $leafEmpChk = $conn->prepare("SELECT e.dept_id FROM leaves l JOIN employees e ON e.id=l.employee_id WHERE l.id=? LIMIT 1");
        $leafEmpChk->execute([$lid]);
        $leafDeptId = $leafEmpChk->fetchColumn();
        if (!$tlDeptChkId || $tlDeptChkId != $leafDeptId) {
            set_flash('danger', 'Access denied.'); header("Location: leaves.php"); exit;
        }
    }

    // Resolve the employee's user_id for notification
    $leafUser = $conn->prepare("SELECT u2.id AS user_id, e.name AS emp_name, l.leave_type, l.from_date, l.to_date FROM leaves l JOIN employees e ON e.id=l.employee_id JOIN users u2 ON u2.email=e.email WHERE l.id=? LIMIT 1");
    $leafUser->execute([$lid]);
    $leafInfo = $leafUser->fetch();
    $leafUserId = $leafInfo ? (int)$leafInfo['user_id'] : 0;
    $leafDates  = $leafInfo ? date('d M', strtotime($leafInfo['from_date'])) . '–' . date('d M', strtotime($leafInfo['to_date'])) : '';

    if ($act === 'tl_approve') {
        $conn->prepare("UPDATE leaves SET status='TL_APPROVED', tl_approved_by=?, tl_approved_at=NOW()
                        WHERE id=? AND status='PENDING'")
             ->execute([$u['id'], $lid]);
        if ($leafUserId && $leafUserId !== (int)$u['id']) {
            hrms_notify($conn, $leafUserId, 'leave_tl_approved',
                'Leave TL-approved: ' . ($leafInfo['leave_type'] ?? ''),
                'Your leave (' . $leafDates . ') was approved by your TL. Awaiting HR final approval.',
                'leaves.php'
            );
        }
        set_flash('success', 'Leave approved. Awaiting HR final approval.');

    } elseif ($act === 'approve' && has_role('SUPER_ADMIN','HR_ADMIN')) {
        $conn->prepare("UPDATE leaves SET status='APPROVED', approved_by=?
                        WHERE id=? AND status='TL_APPROVED'")
             ->execute([$u['id'], $lid]);
        if ($leafUserId) {
            hrms_notify($conn, $leafUserId, 'leave_approved',
                'Leave approved: ' . ($leafInfo['leave_type'] ?? ''),
                'Your leave (' . $leafDates . ') has been fully approved.',
                'leaves.php'
            );
        }
        set_flash('success', 'Leave fully approved.');

    } elseif ($act === 'reject') {
        $conn->prepare("UPDATE leaves SET status='REJECTED' WHERE id=?")
             ->execute([$lid]);
        if ($leafUserId) {
            hrms_notify($conn, $leafUserId, 'leave_rejected',
                'Leave rejected: ' . ($leafInfo['leave_type'] ?? ''),
                'Your leave request (' . $leafDates . ') was rejected.',
                'leaves.php'
            );
        }
        set_flash('danger', 'Leave rejected.');
    }
    header("Location: leaves.php"); exit;
}

// ── Employee revoke (until leave start date) ─────────────
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'revoke') {
    $lid = (int)$_GET['id'];
    $lv  = $conn->prepare("SELECT l.*, e.id as eid FROM leaves l
                            LEFT JOIN employees e ON e.email=?
                            WHERE l.id=?");
    $lv->execute([$u['email'], $lid]);
    $lv = $lv->fetch();

    $can_revoke = $lv
        && $lv['eid'] == $lv['employee_id']           // own leave
        && in_array($lv['status'], ['PENDING','TL_APPROVED','APPROVED'])
        && $lv['from_date'] > date('Y-m-d');           // hasn't started

    if ($can_revoke) {
        $conn->prepare("UPDATE leaves SET status='REVOKED' WHERE id=?")
             ->execute([$lid]);
        set_flash('success', 'Leave revoked successfully.');
    } else {
        set_flash('danger', 'Cannot revoke — leave has already started or is not active.');
    }
    header("Location: leaves.php"); exit;
}

// Approve / Reject permission (HR/Admin/TL two-step chain)
if (isset($_GET['perm_action'], $_GET['perm_id']) && has_role('SUPER_ADMIN', 'HR_ADMIN', 'TEAM_LEAD')) {
    $pid = (int)$_GET['perm_id'];
    $act = $_GET['perm_action'];

    // TL: verify permission belongs to their dept
    if ($u['role'] === 'TEAM_LEAD') {
        $tlDeptChk2 = $conn->prepare("
            SELECT er.dept_id FROM employee_roles er
            JOIN employees e ON e.id = er.employee_id
            JOIN users u2 ON u2.email = e.email
            WHERE u2.id = ? AND er.is_team_lead = 1 LIMIT 1
        ");
        $tlDeptChk2->execute([$u['id']]);
        $tlDeptId2 = $tlDeptChk2->fetchColumn();
        $permDeptChk = $conn->prepare("SELECT e.dept_id FROM permission_requests p JOIN employees e ON e.id=p.employee_id WHERE p.id=? LIMIT 1");
        $permDeptChk->execute([$pid]);
        $permDeptId = $permDeptChk->fetchColumn();
        if (!$tlDeptId2 || $tlDeptId2 != $permDeptId) {
            set_flash('danger', 'Access denied.'); header("Location: leaves.php"); exit;
        }
    }

    if ($act === 'tl_approve') {
        $conn->prepare("UPDATE permission_requests SET status='TL_APPROVED', tl_approved_by=?, tl_approved_at=NOW() WHERE id=? AND status='PENDING'")
             ->execute([$u['id'], $pid]);
        set_flash('success', 'Permission approved by TL. Awaiting HR final approval.');

    } elseif ($act === 'approve' && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
        $conn->prepare("UPDATE permission_requests SET status='APPROVED', approved_by=? WHERE id=? AND status='TL_APPROVED'")
             ->execute([$u['id'], $pid]);
        set_flash('success', 'Permission request fully approved.');

    } elseif ($act === 'reject') {
        $conn->prepare("UPDATE permission_requests SET status='REJECTED', approved_by=? WHERE id=?")
             ->execute([$u['id'], $pid]);
        set_flash('danger', 'Permission request rejected.');
    }
    header("Location: leaves.php"); exit;
}

// HR/Admin hard-delete (if needed for cleanup only)
if (isset($_GET['delete']) && has_role('SUPER_ADMIN','HR_ADMIN')) {
    $id = (int)$_GET['delete'];
    $conn->prepare("DELETE FROM leaves WHERE id=?")->execute([$id]);
    set_flash('success', 'Leave record removed.');
    header("Location: leaves.php"); exit;
}

// Apply for leave
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Find employee record for current user
    $emp = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
    $emp->execute([$u['email']]);
    $emp = $emp->fetch();

    if (!$emp) {
        set_flash('danger', 'No employee record found for your account. Contact HR.');
    } else {
        $from = $_POST['from_date'];
        $to   = $_POST['to_date'];
        $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        // TLs skip the TL-approval step — their leave goes straight to HR
        $isTlApplying = has_role('TEAM_LEAD');
        $initStatus   = $isTlApplying ? 'TL_APPROVED' : 'PENDING';
        $tlApprovedBy = $isTlApplying ? $u['id'] : null;
        $tlApprovedAt = $isTlApplying ? date('Y-m-d H:i:s') : null;
        $conn->prepare("INSERT INTO leaves (employee_id,leave_type,from_date,to_date,days,reason,status,tl_approved_by,tl_approved_at) VALUES (?,?,?,?,?,?,?,?,?)")
             ->execute([$emp['id'], $_POST['leave_type'], $from, $to, $days, $_POST['reason'], $initStatus, $tlApprovedBy, $tlApprovedAt]);
        set_flash('success', $isTlApplying ? 'Leave submitted — awaiting HR approval.' : 'Leave application submitted.');
    }
    header("Location: leaves.php"); exit;
}

// Leave types from DB
$leave_types = $conn->query("SELECT * FROM leave_types WHERE active=1 ORDER BY sort_order, name")->fetchAll();
$leave_type_names = array_column($leave_types, 'name');

// Fetch leaves
if (has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $leaves = $conn->query("SELECT l.*, e.name as emp_name, e.emp_code,
        utl.name as tl_approver_name
        FROM leaves l
        LEFT JOIN employees e   ON l.employee_id = e.id
        LEFT JOIN users utl     ON utl.id = l.tl_approved_by
        ORDER BY l.created_at DESC")->fetchAll();
    $permissions = $conn->query("SELECT p.*, e.name as emp_name, e.emp_code,
        utl.name as tl_approver_name
        FROM permission_requests p
        LEFT JOIN employees e ON p.employee_id = e.id
        LEFT JOIN users utl ON utl.id = p.tl_approved_by
        ORDER BY p.created_at DESC")->fetchAll();
    $leave_balance = [];

} elseif ($u['role'] === 'TEAM_LEAD') {
    // Resolve TL's department via employee_roles
    $tlDeptStmt = $conn->prepare("
        SELECT er.dept_id FROM employee_roles er
        JOIN employees e ON e.id = er.employee_id
        JOIN users u2 ON u2.email = e.email
        WHERE u2.id = ? AND er.is_team_lead = 1 LIMIT 1
    ");
    $tlDeptStmt->execute([$u['id']]);
    $tlDeptId = $tlDeptStmt->fetchColumn();

    // TL sees all leaves for their dept members (excluding themselves)
    $stmt = $conn->prepare("SELECT l.*, e.name as emp_name, e.emp_code,
        utl.name as tl_approver_name
        FROM leaves l
        JOIN employees e    ON l.employee_id = e.id
        LEFT JOIN users utl ON utl.id = l.tl_approved_by
        WHERE e.dept_id = ? AND e.email != ?
        ORDER BY l.created_at DESC");
    $stmt->execute([$tlDeptId ?: 0, $u['email']]);
    $leaves = $stmt->fetchAll();

    $permStmt = $conn->prepare("SELECT p.*, e.name as emp_name, e.emp_code,
        utl.name as tl_approver_name
        FROM permission_requests p
        JOIN employees e ON p.employee_id = e.id
        LEFT JOIN users utl ON utl.id = p.tl_approved_by
        WHERE e.dept_id = ? AND e.email != ?
        ORDER BY p.created_at DESC");
    $permStmt->execute([$tlDeptId ?: 0, $u['email']]);
    $permissions = $permStmt->fetchAll();
    $leave_balance = [];

    // TL's own leaves (separate from team view — they skip TL approval, go to HR)
    $tl_emp_id_stmt = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
    $tl_emp_id_stmt->execute([$u['email']]);
    $tl_emp_id = $tl_emp_id_stmt->fetchColumn();
    $my_own_leaves = [];
    if ($tl_emp_id) {
        $s = $conn->prepare("SELECT l.*, e.name as emp_name, utl.name as tl_approver_name
            FROM leaves l JOIN employees e ON e.id=l.employee_id
            LEFT JOIN users utl ON utl.id=l.tl_approved_by
            WHERE l.employee_id=? ORDER BY l.created_at DESC");
        $s->execute([$tl_emp_id]);
        $my_own_leaves = $s->fetchAll();
    }

} else {
    $emp_id = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
    $emp_id->execute([$u['email']]);
    $emp_id = $emp_id->fetchColumn();
    $stmt   = $conn->prepare("SELECT l.*, e.name as emp_name, e.emp_code,
        utl.name as tl_approver_name
        FROM leaves l
        LEFT JOIN employees e   ON l.employee_id = e.id
        LEFT JOIN users utl     ON utl.id = l.tl_approved_by
        WHERE l.employee_id=? ORDER BY l.created_at DESC");
    $stmt->execute([$emp_id ?: 0]);
    $leaves = $stmt->fetchAll();
    $permissions = [];

    // Monthly balance per leave type
    $leave_balance = [];
    $cur_y = date('Y'); $cur_m = date('n');
    if ($emp_id) {
        foreach ($leave_types as $lt) {
            $used_stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) FROM leaves
                WHERE employee_id=? AND leave_type=? AND status='APPROVED'
                AND YEAR(from_date)=? AND MONTH(from_date)=?");
            $used_stmt->execute([$emp_id, $lt['name'], $cur_y, $cur_m]);
            $used    = (float)$used_stmt->fetchColumn();
            $allowed = (float)$lt['days_per_month'];
            $leave_balance[$lt['name']] = [
                'allowed'   => $allowed,
                'used'      => $used,
                'remaining' => max(0, $allowed - $used),
                'color'     => $lt['color'],
            ];
        }
    }
}

// Counts
$total       = count($leaves);
$pending     = count(array_filter($leaves, fn($l) => $l['status'] === 'PENDING'));
$tl_approved = count(array_filter($leaves, fn($l) => $l['status'] === 'TL_APPROVED'));
$approved    = count(array_filter($leaves, fn($l) => $l['status'] === 'APPROVED'));

// Status display map
$lv_status = [
    'PENDING'     => ['color' => 'warning',   'label' => 'Awaiting TL'],
    'TL_APPROVED' => ['color' => 'info',      'label' => 'Awaiting HR'],
    'APPROVED'    => ['color' => 'success',   'label' => 'Approved'],
    'REJECTED'    => ['color' => 'danger',    'label' => 'Rejected'],
    'REVOKED'     => ['color' => 'secondary', 'label' => 'Revoked'],
];

// Current user's employee id (for revoke button check)
$_my_emp = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
$_my_emp->execute([$u['email']]);
$my_emp_id = $_my_emp->fetchColumn() ?: 0;

include 'header.php';
?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total',        $total,       'primary'],
        ['Awaiting TL',  $pending,     'warning'],
        ['Awaiting HR',  $tl_approved, 'info'],
        ['Approved',     $approved,    'success'],
    ] as [$label, $val, $color]): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center" style="border-radius:12px;">
            <div class="fw-bold fs-3 text-<?= $color ?>"><?= $val ?></div>
            <div class="text-muted small"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Leave balance (employee only) -->
<?php if ($leave_balance): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>My Leave Balance — <?= date('F Y') ?></h6>
        <div class="row g-3">
        <?php foreach ($leave_balance as $type_name => $bal): ?>
        <div class="col-md-4 col-sm-6">
            <div class="p-3 rounded" style="background:<?= sanitize($bal['color']) ?>12;border:1px solid <?= sanitize($bal['color']) ?>30;">
                <div class="small fw-semibold mb-2" style="color:<?= sanitize($bal['color']) ?>;"><?= sanitize($type_name) ?></div>
                <div class="d-flex justify-content-between align-items-end mb-1">
                    <span class="fw-bold" style="font-size:1.2rem;color:<?= sanitize($bal['color']) ?>;"><?= $bal['remaining'] ?></span>
                    <span class="text-muted small">/ <?= $bal['allowed'] ?> days</span>
                </div>
                <div class="progress" style="height:5px;">
                    <?php $pct = $bal['allowed'] > 0 ? min(100, round(($bal['used']/$bal['allowed'])*100)) : 0; ?>
                    <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= sanitize($bal['color']) ?>;"></div>
                </div>
                <div class="text-muted mt-1" style="font-size:.68rem;"><?= $bal['used'] ?> used this month · <?= $bal['remaining'] ?> remaining</div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
    <a href="hr_settings.php?tab=leave_types" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear me-1"></i>Manage Leave Types
    </a>
    <?php else: ?>
    <div></div>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#leaveModal">
        <i class="bi bi-calendar-plus me-1"></i> Apply for Leave
    </button>
</div>

<?php if ($u['role'] === 'TEAM_LEAD' && isset($my_own_leaves)): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-header bg-transparent border-0 pt-3 pb-0 px-4">
        <h6 class="fw-bold mb-0" style="font-size:.9rem;"><i class="bi bi-person-circle me-2 text-primary"></i>My Leaves</h6>
        <p class="text-muted small mb-0">Your leave applications go directly to HR for approval.</p>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Type</th>
                        <th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$my_own_leaves): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted small">No leave applications yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($my_own_leaves as $ml):
                    $ls = $lv_status[$ml['status']] ?? ['color'=>'secondary','label'=>$ml['status']];
                ?>
                <tr>
                    <td class="ps-4" style="font-size:.85rem;"><?= sanitize($ml['leave_type']) ?></td>
                    <td style="font-size:.82rem;"><?= date('d M Y', strtotime($ml['from_date'])) ?></td>
                    <td style="font-size:.82rem;"><?= date('d M Y', strtotime($ml['to_date'])) ?></td>
                    <td style="font-size:.82rem;"><?= $ml['days'] ?></td>
                    <td style="font-size:.82rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($ml['reason']) ?>"><?= sanitize($ml['reason']) ?></td>
                    <td><span class="badge bg-<?= $ls['color'] ?> rounded-pill"><?= $ls['label'] ?></span></td>
                    <td>
                        <?php
                        $tl_emp_record = isset($tl_emp_id) ? $tl_emp_id : null;
                        $can_revoke_own = $tl_emp_record && $tl_emp_record == $ml['employee_id']
                            && in_array($ml['status'], ['PENDING','TL_APPROVED','APPROVED'])
                            && $ml['from_date'] > date('Y-m-d');
                        if ($can_revoke_own): ?>
                        <a href="?action=revoke&id=<?= $ml['id'] ?>"
                           class="btn btn-sm btn-outline-warning py-0 px-2"
                           onclick="return hConfirmSync(event,'Revoke this leave?')">
                            <i class="bi bi-x-circle me-1"></i>Revoke
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<h6 class="fw-bold mb-3" style="font-size:.9rem;"><i class="bi bi-people-fill me-2 text-primary"></i>Team Leave Applications</h6>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Employee</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$leaves): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No leave applications</td></tr>
                <?php else: ?>
                <?php foreach ($leaves as $l): ?>
                <tr>
                    <td class="ps-4" data-readonly="1">
                        <div class="fw-semibold"><?= sanitize($l['emp_name'] ?? 'Unknown') ?></div>
                        <div class="text-muted small"><?= sanitize($l['emp_code'] ?? '') ?></div>
                    </td>
                    <?php $canEdit = in_array($l['status'],['PENDING','TL_APPROVED']) && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD'); ?>
                    <td <?= $canEdit ? 'data-editable="select" data-table="leaves" data-id="'.$l['id'].'" data-field="leave_type" data-value="'.sanitize($l['leave_type']).'" data-options="Casual Leave,Sick Leave,Earned Leave,Maternity Leave,Paternity Leave,Compensatory Leave"' : 'data-readonly="1"' ?>><?= sanitize($l['leave_type']) ?></td>
                    <td <?= $canEdit ? 'data-editable="date" data-table="leaves" data-id="'.$l['id'].'" data-field="from_date" data-value="'.$l['from_date'].'"' : 'data-readonly="1"' ?>><?= date('d M Y', strtotime($l['from_date'])) ?></td>
                    <td <?= $canEdit ? 'data-editable="date" data-table="leaves" data-id="'.$l['id'].'" data-field="to_date" data-value="'.$l['to_date'].'"' : 'data-readonly="1"' ?>><?= date('d M Y', strtotime($l['to_date'])) ?></td>
                    <td data-field="days_display" data-readonly="1"><?= $l['days'] ?> day<?= $l['days'] > 1 ? 's' : '' ?></td>
                    <td <?= $canEdit ? 'data-editable="text" data-table="leaves" data-id="'.$l['id'].'" data-field="reason" data-value="'.sanitize($l['reason']).'"' : 'data-readonly="1"' ?>><span title="<?= sanitize($l['reason']) ?>"><?= strlen($l['reason']) > 30 ? sanitize(substr($l['reason'],0,30)).'...' : sanitize($l['reason']) ?></span></td>
                    <td>
                        <?php $ls = $lv_status[$l['status']] ?? ['color'=>'secondary','label'=>$l['status']]; ?>
                        <span class="badge bg-<?= $ls['color'] ?> rounded-pill"><?= $ls['label'] ?></span>
                        <?php if (!empty($l['tl_approver_name']) && in_array($l['status'],['TL_APPROVED','APPROVED'])): ?>
                        <div class="text-muted mt-1" style="font-size:.65rem;">
                            <i class="bi bi-person-check me-1"></i>TL: <?= sanitize($l['tl_approver_name']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['status'] === 'PENDING' && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD')): ?>
                            <a href="?action=tl_approve&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-warning py-0 px-2"
                               onclick="return hConfirmSync(event,'TL-approve this leave?')">
                                <i class="bi bi-person-check"></i> TL Approve
                            </a>
                            <a href="?action=reject&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                               onclick="return hConfirmSync(event,'Reject this leave?')">
                                <i class="bi bi-x"></i>
                            </a>
                        <?php elseif ($l['status'] === 'TL_APPROVED' && has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                            <a href="?action=approve&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-success py-0 px-2"
                               onclick="return hConfirmSync(event,'Final-approve this leave?')">
                                <i class="bi bi-check2-all"></i> Approve
                            </a>
                            <a href="?action=reject&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                               onclick="return hConfirmSync(event,'Reject this leave?')">
                                <i class="bi bi-x"></i>
                            </a>
                        <?php elseif ($l['status'] === 'TL_APPROVED' && has_role('TEAM_LEAD')): ?>
                            <span class="badge bg-success-subtle text-success" style="font-size:.7rem;">
                                <i class="bi bi-check2 me-1"></i>Approved by you — awaiting HR
                            </span>
                            <a href="?action=reject&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                               onclick="return hConfirmSync(event,'Reject this leave?')">
                                <i class="bi bi-x"></i>
                            </a>
                        <?php endif; ?>
                        <?php
                        // Single Revoke button for all active statuses — only before leave starts
                        $can_revoke = $my_emp_id == $l['employee_id']
                            && in_array($l['status'], ['PENDING','TL_APPROVED','APPROVED'])
                            && $l['from_date'] > date('Y-m-d');
                        if ($can_revoke): ?>
                        <a href="?action=revoke&id=<?= $l['id'] ?>"
                           class="btn btn-sm btn-outline-warning py-0 px-2 ms-1"
                           onclick="return hConfirmSync(event,'Revoke this leave? This cannot be undone.')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Revoke
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Permissions Table (HR/Admin + Team Lead) -->
<?php
$perm_status_map = [
    'PENDING'     => ['color' => 'warning',   'label' => 'Awaiting TL'],
    'TL_APPROVED' => ['color' => 'info',      'label' => 'Awaiting HR'],
    'APPROVED'    => ['color' => 'success',   'label' => 'Approved'],
    'REJECTED'    => ['color' => 'danger',    'label' => 'Rejected'],
];
?>
<?php if (has_role('SUPER_ADMIN', 'HR_ADMIN', 'TEAM_LEAD') && !empty($permissions)): ?>
<div class="mt-4">
    <h6 class="fw-bold mb-3"><i class="bi bi-shield-check me-2 text-info"></i>Permission Requests</h6>
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Employee</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Hours</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$permissions): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted small">No permission requests</td></tr>
                    <?php endif; ?>
                    <?php foreach ($permissions as $p):
                        $ps = $perm_status_map[$p['status']] ?? ['color'=>'secondary','label'=>$p['status']];
                        $ptype_label = ['LATE_COMING'=>'Late Coming','EARLY_GOING'=>'Early Going','IN_BETWEEN'=>'In Between'];
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-semibold small"><?= sanitize($p['emp_name'] ?? 'Unknown') ?></div>
                            <div class="text-muted small"><?= sanitize($p['emp_code'] ?? '') ?></div>
                        </td>
                        <td class="small"><?= $ptype_label[$p['permission_type']] ?? sanitize(str_replace('_',' ',$p['permission_type'])) ?></td>
                        <td class="small"><?= date('d M Y', strtotime($p['request_date'])) ?></td>
                        <td class="small text-muted">
                            <?php
                                $range = [];
                                if ($p['from_time']) $range[] = date('h:i A', strtotime($p['from_time']));
                                if ($p['to_time'])   $range[] = date('h:i A', strtotime($p['to_time']));
                                echo $range ? implode(' – ', $range) : '—';
                            ?>
                        </td>
                        <td class="small text-center">
                            <?php if (!empty($p['duration_hours']) && $p['duration_hours'] > 0): ?>
                            <span class="badge bg-info-subtle text-info"><?= $p['duration_hours'] ?>h</span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><span title="<?= sanitize($p['reason']) ?>"><?= strlen($p['reason']) > 35 ? sanitize(substr($p['reason'],0,35)).'…' : sanitize($p['reason']) ?></span></td>
                        <td>
                            <span class="badge bg-<?= $ps['color'] ?> rounded-pill"><?= $ps['label'] ?></span>
                            <?php if (!empty($p['tl_approver_name']) && in_array($p['status'],['TL_APPROVED','APPROVED'])): ?>
                            <div class="text-muted mt-1" style="font-size:.65rem;">
                                <i class="bi bi-person-check me-1"></i>TL: <?= sanitize($p['tl_approver_name']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'PENDING' && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD')): ?>
                                <a href="?perm_action=tl_approve&perm_id=<?= $p['id'] ?>" class="btn btn-sm btn-warning py-0 px-2" onclick="return hConfirmSync(event,'TL-approve this permission?')">
                                    <i class="bi bi-person-check"></i> TL Approve
                                </a>
                                <a href="?perm_action=reject&perm_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="return hConfirmSync(event,'Reject?')">
                                    <i class="bi bi-x"></i>
                                </a>
                            <?php elseif ($p['status'] === 'TL_APPROVED' && has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                                <a href="?perm_action=approve&perm_id=<?= $p['id'] ?>" class="btn btn-sm btn-success py-0 px-2" onclick="return hConfirmSync(event,'Final-approve this permission?')">
                                    <i class="bi bi-check2-all"></i> Approve
                                </a>
                                <a href="?perm_action=reject&perm_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="return hConfirmSync(event,'Reject?')">
                                    <i class="bi bi-x"></i>
                                </a>
                            <?php elseif ($p['status'] === 'TL_APPROVED' && has_role('TEAM_LEAD')): ?>
                                <span class="badge bg-success-subtle text-success" style="font-size:.7rem;">
                                    <i class="bi bi-check2 me-1"></i>Approved by you — awaiting HR
                                </span>
                                <a href="?perm_action=reject&perm_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="return hConfirmSync(event,'Reject?')">
                                    <i class="bi bi-x"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Apply Leave Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Apply for Leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Leave Type</label>
                    <select name="leave_type" class="form-select" id="leaveTypeSelect" onchange="showBalance(this.value)">
                        <?php foreach ($leave_types as $lt): ?>
                        <option value="<?= sanitize($lt['name']) ?>"><?= sanitize($lt['name']) ?> (<?= $lt['days_per_month'] ?> days/mo)</option>
                        <?php endforeach; ?>
                        <?php if (!$leave_types): ?>
                        <option>Casual Leave</option>
                        <?php endif; ?>
                    </select>
                    <div id="leaveBalanceHint" class="mt-1" style="font-size:.75rem;"></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold small">From Date</label>
                        <input type="date" name="from_date" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small">To Date</label>
                        <input type="date" name="to_date" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Reason</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="Briefly explain the reason..." required></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Submit Application</button>
            </div>
        </form>
    </div>
</div>

<script>
const leaveBalance = <?= json_encode($leave_balance ?: new stdClass()) ?>;
function showBalance(type) {
    const hint = document.getElementById('leaveBalanceHint');
    if (!hint) return;
    const b = leaveBalance[type];
    if (!b) { hint.innerHTML = ''; return; }
    const color = b.remaining > 0 ? '#15803d' : '#dc2626';
    hint.innerHTML = `<span style="color:${color};">
        <i class="bi bi-info-circle me-1"></i>
        ${b.remaining} day(s) remaining this month (${b.used} used of ${b.allowed} allowed).
    </span>`;
}
// Trigger on modal open
document.getElementById('leaveModal')?.addEventListener('show.bs.modal', () => {
    const sel = document.getElementById('leaveTypeSelect');
    if (sel) showBalance(sel.value);
});
</script>

<?php include 'footer.php'; ?>

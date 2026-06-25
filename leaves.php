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
// ── Half-day leave support ──
try { $conn->exec("ALTER TABLE leaves ADD COLUMN half_day TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE leaves ADD COLUMN half_day_period VARCHAR(16) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE leaves MODIFY COLUMN days DECIMAL(5,1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
// ── Withdrawal approval tracking ──
try { $conn->exec("ALTER TABLE leaves ADD COLUMN withdraw_prev_status VARCHAR(20) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $conn->exec("ALTER TABLE leaves MODIFY COLUMN status ENUM('PENDING','TL_APPROVED','APPROVED','REJECTED','REVOKED','WITHDRAWN','WITHDRAW_REQUESTED') NOT NULL DEFAULT 'PENDING'"); } catch (PDOException $e) {}

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

    } elseif ($act === 'approve_withdraw') {
        $lv2 = $conn->prepare("SELECT withdraw_prev_status FROM leaves WHERE id=? AND status='WITHDRAW_REQUESTED' LIMIT 1");
        $lv2->execute([$lid]);
        $lv2 = $lv2->fetch();
        if ($lv2) {
            $prevSt   = $lv2['withdraw_prev_status'] ?? 'APPROVED';
            $needsTl  = $prevSt === 'TL_APPROVED';
            $canActW2 = ($needsTl && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD'))
                     || (!$needsTl && has_role('SUPER_ADMIN','HR_ADMIN'));
            if ($canActW2) {
                $conn->prepare("UPDATE leaves SET status='WITHDRAWN' WHERE id=?")->execute([$lid]);
                if ($leafUserId) hrms_notify($conn, $leafUserId, 'leave_withdrawn',
                    'Withdrawal approved', 'Your withdrawal request has been approved.', 'leaves.php');
                set_flash('success', 'Withdrawal approved — leave marked as withdrawn.');
            } else {
                set_flash('danger', 'Access denied.');
            }
        } else {
            set_flash('danger', 'No pending withdrawal request found.');
        }

    } elseif ($act === 'reject_withdraw') {
        $lv3 = $conn->prepare("SELECT withdraw_prev_status FROM leaves WHERE id=? AND status='WITHDRAW_REQUESTED' LIMIT 1");
        $lv3->execute([$lid]);
        $lv3 = $lv3->fetch();
        if ($lv3) {
            $prevSt2  = $lv3['withdraw_prev_status'] ?? 'APPROVED';
            $needsTl2 = $prevSt2 === 'TL_APPROVED';
            $canActW3 = ($needsTl2 && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD'))
                     || (!$needsTl2 && has_role('SUPER_ADMIN','HR_ADMIN'));
            if ($canActW3) {
                $conn->prepare("UPDATE leaves SET status=?, withdraw_prev_status=NULL WHERE id=?")->execute([$prevSt2, $lid]);
                if ($leafUserId) hrms_notify($conn, $leafUserId, 'leave_withdraw_rejected',
                    'Withdrawal rejected', 'Your withdrawal request was rejected — leave remains active.', 'leaves.php');
                set_flash('info', 'Withdrawal rejected — leave restored to previous status.');
            } else {
                set_flash('danger', 'Access denied.');
            }
        } else {
            set_flash('danger', 'No pending withdrawal request found.');
        }
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
        && $lv['eid'] == $lv['employee_id']
        && in_array($lv['status'], ['PENDING','TL_APPROVED','APPROVED'])
        && $lv['from_date'] > date('Y-m-d');

    if ($can_revoke) {
        $conn->prepare("UPDATE leaves SET status='REVOKED' WHERE id=?")->execute([$lid]);
        set_flash('success', 'Leave revoked successfully.');
    } else {
        set_flash('danger', 'Cannot revoke — leave has already started or is not active.');
    }
    header("Location: leaves.php"); exit;
}

// ── Employee withdraw ──────────────────────────────────────
// PENDING        → direct WITHDRAWN (no approval needed)
// TL_APPROVED    → WITHDRAW_REQUESTED, TL must approve
// APPROVED       → WITHDRAW_REQUESTED, HR must approve
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'withdraw') {
    $lid = (int)$_GET['id'];
    $lv  = $conn->prepare("SELECT l.*, e.id as eid FROM leaves l
                            LEFT JOIN employees e ON e.email=?
                            WHERE l.id=?");
    $lv->execute([$u['email'], $lid]);
    $lv = $lv->fetch();

    $can_withdraw = $lv
        && $lv['eid'] == $lv['employee_id']
        && in_array($lv['status'], ['PENDING','TL_APPROVED','APPROVED']);

    if ($can_withdraw) {
        if ($lv['status'] === 'PENDING') {
            $conn->prepare("UPDATE leaves SET status='WITHDRAWN' WHERE id=?")->execute([$lid]);
            set_flash('success', 'Leave withdrawn successfully.');
        } else {
            // TL_APPROVED → TL approves withdrawal; APPROVED → HR approves
            $conn->prepare("UPDATE leaves SET status='WITHDRAW_REQUESTED', withdraw_prev_status=? WHERE id=?")
                 ->execute([$lv['status'], $lid]);
            $approver = $lv['status'] === 'TL_APPROVED' ? 'your Team Lead' : 'HR';
            set_flash('success', "Withdrawal request submitted — awaiting approval from $approver.");
        }
    } else {
        set_flash('danger', 'Cannot withdraw this leave request.');
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
        $from       = $_POST['from_date'];
        $halfDay    = !empty($_POST['half_day']) ? 1 : 0;
        $halfPeriod = $halfDay ? (in_array($_POST['half_day_period'] ?? '', ['first_half','second_half']) ? $_POST['half_day_period'] : 'first_half') : null;
        $to         = $halfDay ? $from : $_POST['to_date'];
        $days       = $halfDay ? 0.5 : ((int)((strtotime($to) - strtotime($from)) / 86400) + 1);
        // TLs skip the TL-approval step — their leave goes straight to HR
        $isTlApplying = has_role('TEAM_LEAD');
        $initStatus   = $isTlApplying ? 'TL_APPROVED' : 'PENDING';
        $tlApprovedBy = $isTlApplying ? $u['id'] : null;
        $tlApprovedAt = $isTlApplying ? date('Y-m-d H:i:s') : null;
        $conn->prepare("INSERT INTO leaves (employee_id,leave_type,from_date,to_date,days,reason,status,tl_approved_by,tl_approved_at,half_day,half_day_period) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
             ->execute([$emp['id'], $_POST['leave_type'], $from, $to, $days, $_POST['reason'], $initStatus, $tlApprovedBy, $tlApprovedAt, $halfDay, $halfPeriod]);
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
    'WITHDRAWN'          => ['color' => 'secondary', 'label' => 'Withdrawn'],
    'WITHDRAW_REQUESTED' => ['color' => 'warning',   'label' => 'Withdraw Requested'],
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
                    <td style="font-size:.82rem;">
                        <?php $mdv = (float)$ml['days']; echo $mdv == 0.5 ? '½ day' : ($mdv . ' day' . ($mdv > 1 ? 's' : '')); ?>
                        <?php if (!empty($ml['half_day']) && !empty($ml['half_day_period'])): ?>
                        <span class="badge ms-1" style="font-size:.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            <?= $ml['half_day_period'] === 'second_half' ? 'Second Half' : 'First Half' ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($ml['reason']) ?>"><?= sanitize($ml['reason']) ?></td>
                    <td><span class="badge bg-<?= $ls['color'] ?> rounded-pill"><?= $ls['label'] ?></span></td>
                    <td>
                        <?php
                        $can_withdraw_own = $my_emp_id && $my_emp_id == $ml['employee_id']
                            && in_array($ml['status'], ['PENDING','TL_APPROVED','APPROVED']);
                        if ($can_withdraw_own): ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger py-0 px-2"
                                onclick="openWithdrawModal(<?= $ml['id'] ?>, '<?= sanitize($ml['leave_type']) ?>', '<?= date('d M Y', strtotime($ml['from_date'])) ?>', '<?= date('d M Y', strtotime($ml['to_date'])) ?>', '<?= $ml['status'] ?>')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Withdraw
                        </button>
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
                    <td data-field="days_display" data-readonly="1">
                        <?php $dv = (float)$l['days']; echo $dv == 0.5 ? '½ day' : ($dv . ' day' . ($dv > 1 ? 's' : '')); ?>
                        <?php if (!empty($l['half_day']) && !empty($l['half_day_period'])): ?>
                        <span class="badge ms-1" style="font-size:.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"><?= $l['half_day_period'] === 'second_half' ? 'Second Half' : 'First Half' ?></span>
                        <?php endif; ?>
                    </td>
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
                        // Withdraw: own leave, still active
                        $can_withdraw_main = $my_emp_id && $my_emp_id == $l['employee_id']
                            && in_array($l['status'], ['PENDING','TL_APPROVED','APPROVED']);
                        if ($can_withdraw_main): ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                                onclick="openWithdrawModal(<?= $l['id'] ?>, '<?= sanitize($l['leave_type']) ?>', '<?= date('d M Y', strtotime($l['from_date'])) ?>', '<?= date('d M Y', strtotime($l['to_date'])) ?>', '<?= $l['status'] ?>')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Withdraw
                        </button>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'WITHDRAW_REQUESTED'):
                            $wPrev    = $l['withdraw_prev_status'] ?? 'APPROVED';
                            $wNeedsTl = $wPrev === 'TL_APPROVED';
                            $canActW  = ($wNeedsTl && has_role('SUPER_ADMIN','HR_ADMIN','TEAM_LEAD'))
                                     || (!$wNeedsTl && has_role('SUPER_ADMIN','HR_ADMIN'));
                            if ($canActW): ?>
                        <a href="?action=approve_withdraw&id=<?= $l['id'] ?>"
                           class="btn btn-sm btn-success py-0 px-2 ms-1"
                           onclick="return hConfirmSync(event,'Approve this withdrawal request?')">
                            <i class="bi bi-check2 me-1"></i>Approve Withdrawal
                        </a>
                        <a href="?action=reject_withdraw&id=<?= $l['id'] ?>"
                           class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1"
                           onclick="return hConfirmSync(event,'Reject this withdrawal?')">
                            <i class="bi bi-x me-1"></i>Reject
                        </a>
                        <?php endif; endif; ?>
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

<!-- Withdraw Leave Confirmation Modal -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.18);">
            <div class="modal-body px-4 pt-4 pb-3 text-center">
                <div style="width:52px;height:52px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="bi bi-arrow-counterclockwise" style="font-size:1.4rem;color:#ef4444;"></i>
                </div>
                <h6 class="fw-bold mb-1" id="withdrawModalTitle">Withdraw Leave Request?</h6>
                <p class="text-muted small mb-3" id="withdrawModalDesc">This will cancel your leave request.</p>
                <div id="withdrawModalNote" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#b91c1c;text-align:left;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <span id="withdrawModalNoteText">Once withdrawn, this leave will be marked as cancelled.</span>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2">
                <button type="button" class="btn flex-fill btn-light" data-bs-dismiss="modal">Keep Leave</button>
                <a href="#" id="withdrawConfirmBtn" class="btn flex-fill fw-semibold"
                   style="background:#ef4444;color:#fff;border-radius:8px;">
                    <i class="bi bi-arrow-counterclockwise me-1"></i><span id="withdrawBtnLabel">Yes, Withdraw</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Apply Leave Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Apply for Leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <!-- Row 1: Leave Type + Duration -->
                <div class="row g-3 mb-3">
                    <div class="col-7">
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
                    <div class="col-5">
                        <label class="form-label fw-semibold small">Duration</label>
                        <select class="form-select" id="leaveDurationSelect" onchange="onDurationChange(this.value)">
                            <option value="full">Full Day</option>
                            <option value="half">Half Day</option>
                        </select>
                        <!-- hidden field submitted to PHP -->
                        <input type="hidden" name="half_day" id="halfDayHidden" value="0">
                    </div>
                </div>
                <!-- First / Second half selector (hidden until Half Day chosen) -->
                <div id="halfDayPeriodRow" style="display:none;" class="mb-3">
                    <label class="form-label fw-semibold small">Period</label>
                    <div class="d-flex gap-2">
                        <label class="flex-fill text-center" style="cursor:pointer;">
                            <input type="radio" name="half_day_period" value="first_half" id="radioFirstHalf" class="d-none" checked>
                            <div id="pill_first" onclick="selectPeriod('first_half')"
                                 style="border:2px solid #3b82f6;background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:9px 0;font-size:.82rem;font-weight:600;transition:all .15s;">
                                <i class="bi bi-brightness-high me-1"></i>First Half
                            </div>
                        </label>
                        <label class="flex-fill text-center" style="cursor:pointer;">
                            <input type="radio" name="half_day_period" value="second_half" id="radioSecondHalf" class="d-none">
                            <div id="pill_second" onclick="selectPeriod('second_half')"
                                 style="border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;border-radius:8px;padding:9px 0;font-size:.82rem;font-weight:600;transition:all .15s;">
                                <i class="bi bi-moon me-1"></i>Second Half
                            </div>
                        </label>
                    </div>
                </div>
                <!-- Date row -->
                <div class="row g-3 mb-3">
                    <div class="col-6" id="fromDateCol">
                        <label class="form-label fw-semibold small" id="fromDateLabel">From Date</label>
                        <input type="date" name="from_date" id="leaveFromDate" class="form-control" required onchange="syncHalfDayTo()">
                    </div>
                    <div class="col-6" id="toDateCol">
                        <label class="form-label fw-semibold small">To Date</label>
                        <input type="date" name="to_date" id="leaveToDate" class="form-control" required>
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
function openWithdrawModal(id, type, fromDate, toDate, status) {
    document.getElementById('withdrawModalDesc').textContent =
        type + ' · ' + fromDate + (fromDate !== toDate ? ' – ' + toDate : '');
    document.getElementById('withdrawConfirmBtn').href = '?action=withdraw&id=' + id;
    if (status === 'TL_APPROVED') {
        document.getElementById('withdrawModalTitle').textContent    = 'Request Withdrawal?';
        document.getElementById('withdrawModalNoteText').textContent = 'This leave was approved by your TL. A withdrawal request will be sent to your Team Lead for approval.';
        document.getElementById('withdrawBtnLabel').textContent      = 'Request Withdrawal';
    } else if (status === 'APPROVED') {
        document.getElementById('withdrawModalTitle').textContent    = 'Request Withdrawal?';
        document.getElementById('withdrawModalNoteText').textContent = 'This leave is fully approved. A withdrawal request will be sent to HR for approval.';
        document.getElementById('withdrawBtnLabel').textContent      = 'Request Withdrawal';
    } else {
        document.getElementById('withdrawModalTitle').textContent    = 'Withdraw Leave Request?';
        document.getElementById('withdrawModalNoteText').textContent = 'Once withdrawn, this leave will be cancelled immediately.';
        document.getElementById('withdrawBtnLabel').textContent      = 'Yes, Withdraw';
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('withdrawModal')).show();
}

function onDurationChange(val) {
    const isHalf    = val === 'half';
    const toCol     = document.getElementById('toDateCol');
    const toDate    = document.getElementById('leaveToDate');
    const fromDate  = document.getElementById('leaveFromDate');
    const periodRow = document.getElementById('halfDayPeriodRow');
    const fromLabel = document.getElementById('fromDateLabel');
    const fromCol   = document.getElementById('fromDateCol');
    document.getElementById('halfDayHidden').value = isHalf ? '1' : '0';
    if (isHalf) {
        toCol.style.display     = 'none';
        periodRow.style.display = '';
        toDate.removeAttribute('required');
        toDate.value            = fromDate.value;
        fromLabel.textContent   = 'Date';
        fromCol.className       = 'col-12';
    } else {
        toCol.style.display     = '';
        periodRow.style.display = 'none';
        toDate.setAttribute('required', '');
        fromLabel.textContent   = 'From Date';
        fromCol.className       = 'col-6';
    }
}
function syncHalfDayTo() {
    if (document.getElementById('halfDayHidden').value === '1') {
        document.getElementById('leaveToDate').value = document.getElementById('leaveFromDate').value;
    }
}
function selectPeriod(val) {
    document.getElementById('radioFirstHalf').checked  = (val === 'first_half');
    document.getElementById('radioSecondHalf').checked = (val === 'second_half');
    const activeStyle   = 'border:2px solid #3b82f6;background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:9px 0;font-size:.82rem;font-weight:600;transition:all .15s;';
    const inactiveStyle = 'border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;border-radius:8px;padding:9px 0;font-size:.82rem;font-weight:600;transition:all .15s;';
    document.getElementById('pill_first').style.cssText  = val === 'first_half'  ? activeStyle : inactiveStyle;
    document.getElementById('pill_second').style.cssText = val === 'second_half' ? activeStyle : inactiveStyle;
}

const leaveBalance = <?= json_encode($leave_balance ?: new stdClass()) ?>;
function showBalance(type) {
    const hint = document.getElementById('leaveBalanceHint');
    if (!hint) return;
    const b = leaveBalance[type];
    if (!b) { hint.innerHTML = ''; return; }
    const color = b.remaining > 0 ? '#15803d' : '#dc2626';
    hint.innerHTML = `<span style="color:${color};"><i class="bi bi-info-circle me-1"></i>${b.remaining} day(s) remaining this month (${b.used} used of ${b.allowed} allowed).</span>`;
}
document.getElementById('leaveModal')?.addEventListener('show.bs.modal', () => {
    const sel = document.getElementById('leaveTypeSelect');
    if (sel) showBalance(sel.value);
});
// Reset to full-day state on modal close
document.getElementById('leaveModal')?.addEventListener('hidden.bs.modal', () => {
    const dur = document.getElementById('leaveDurationSelect');
    if (dur && dur.value !== 'full') { dur.value = 'full'; onDurationChange('full'); }
});
</script>

<?php include 'footer.php'; ?>

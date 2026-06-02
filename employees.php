<?php
require_once 'config.php';
require_login();
$page      = 'employees';
$pageTitle = 'Employees';

// ── AJAX: fetch one employee for the edit modal ──────────────────────
if (isset($_GET['get_emp']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    header('Content-Type: application/json');
    $eid = (int)$_GET['get_emp'];
    $s   = $conn->prepare("SELECT e.*, er.role_id, er.is_team_lead, er.dept_id AS tl_dept_id FROM employees e LEFT JOIN employee_roles er ON er.employee_id = e.id WHERE e.id = ?");
    $s->execute([$eid]);
    $row = $s->fetch();
    if ($row) {
        echo json_encode($row);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
    exit;
}

// ── POST: inline edit save ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_emp' && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    header('Content-Type: application/json');
    $eid = (int)($_POST['id'] ?? 0);
    if (!$eid) { echo json_encode(['error' => 'Invalid ID']); exit; }

    $data = [
        'emp_code'          => trim($_POST['emp_code']         ?? ''),
        'name'              => trim($_POST['name']             ?? ''),
        'email'             => trim($_POST['email']            ?? ''),
        'dept_id'           => (int)($_POST['dept_id']         ?? 0) ?: null,
        'designation'       => trim($_POST['designation']      ?? '') ?: null,
        'graduation'        => trim($_POST['graduation']       ?? '') ?: null,
        'dob'               => ($_POST['dob']                  ?? '') ?: null,
        'joining_date'      => ($_POST['joining_date']         ?? '') ?: null,
        'date_of_relieving' => ($_POST['date_of_relieving']    ?? '') ?: null,
        'salary'            => ($_POST['salary']               ?? '') ?: null,
        'status'            => $_POST['status']                ?? 'ACTIVE',
        'phone'             => trim($_POST['phone']            ?? '') ?: null,
        'emergency_phone'   => trim($_POST['emergency_phone']  ?? '') ?: null,
        'address'           => trim($_POST['address']          ?? '') ?: null,
        'marital_status'    => trim($_POST['marital_status']   ?? '') ?: null,
        'blood_group'       => trim($_POST['blood_group']      ?? '') ?: null,
        'aadhar_no'         => trim($_POST['aadhar_no']        ?? '') ?: null,
        'pan_no'            => trim($_POST['pan_no']           ?? '') ?: null,
        'bank_acc_no'       => trim($_POST['bank_acc_no']      ?? '') ?: null,
        'bank_name'         => trim($_POST['bank_name']        ?? '') ?: null,
        'ifsc_code'         => trim($_POST['ifsc_code']        ?? '') ?: null,
    ];
    $role_id    = (int)($_POST['role_id']    ?? 0) ?: null;
    $is_tl      = isset($_POST['is_team_lead']) ? 1 : 0;
    $tl_dept_id = (int)($_POST['tl_dept_id'] ?? 0) ?: null;

    if (!$data['name'] || !$data['email']) {
        echo json_encode(['error' => 'Name and email are required.']); exit;
    }

    try {
        $conn->beginTransaction();
        $fields = 'emp_code,name,email,dept_id,designation,graduation,dob,joining_date,date_of_relieving,salary,status,phone,emergency_phone,address,marital_status,blood_group,aadhar_no,pan_no,bank_acc_no,bank_name,ifsc_code';
        $vals   = [$data['emp_code'],$data['name'],$data['email'],$data['dept_id'],$data['designation'],$data['graduation'],$data['dob'],$data['joining_date'],$data['date_of_relieving'],$data['salary'],$data['status'],$data['phone'],$data['emergency_phone'],$data['address'],$data['marital_status'],$data['blood_group'],$data['aadhar_no'],$data['pan_no'],$data['bank_acc_no'],$data['bank_name'],$data['ifsc_code']];
        $set    = implode(',', array_map(fn($f) => "$f=?", explode(',', $fields)));
        $conn->prepare("UPDATE employees SET $set WHERE id=?")->execute([...$vals, $eid]);

        if ($role_id) {
            $chk = $conn->prepare("SELECT id FROM employee_roles WHERE employee_id = ?");
            $chk->execute([$eid]);
            if ($chk->fetch()) {
                $conn->prepare("UPDATE employee_roles SET role_id=?,is_team_lead=?,dept_id=? WHERE employee_id=?")
                     ->execute([$role_id, $is_tl, $is_tl ? $tl_dept_id : null, $eid]);
            } else {
                $conn->prepare("INSERT INTO employee_roles (employee_id,role_id,is_team_lead,dept_id) VALUES (?,?,?,?)")
                     ->execute([$eid, $role_id, $is_tl, $is_tl ? $tl_dept_id : null]);
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $ex) {
        $conn->rollBack();
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $conn->prepare("DELETE FROM employees WHERE id = ?")->execute([(int)$_GET['delete']]);
    set_flash('success', 'Employee deleted.');
    header("Location: employees.php"); exit;
}

// ── Filters ───────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$dept   = $_GET['dept'] ?? '';   // keep as string so empty string → no filter
$status = $_GET['status'] ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (e.name LIKE ? OR e.email LIKE ? OR e.emp_code LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($dept !== '') {
    $where .= " AND e.dept_id = ?";
    $params[] = (int)$dept;
}
if ($status) {
    $where .= " AND e.status = ?";
    $params[] = $status;
}

$stmt = $conn->prepare("
    SELECT e.*, d.name as dept_name,
        COALESCE(SUM(CASE WHEN t.status='TODO'        THEN 1 ELSE 0 END),0) AS task_todo,
        COALESCE(SUM(CASE WHEN t.status='IN_PROGRESS' THEN 1 ELSE 0 END),0) AS task_inprogress,
        COALESCE(SUM(CASE WHEN t.status='REVIEW'      THEN 1 ELSE 0 END),0) AS task_review,
        COALESCE(SUM(CASE WHEN t.status='DONE'        THEN 1 ELSE 0 END),0) AS task_done,
        COALESCE(COUNT(t.id),0)                                              AS task_total
    FROM employees e
    LEFT JOIN departments d ON e.dept_id = d.id
    LEFT JOIN users u ON u.email = e.email
    LEFT JOIN tasks t ON t.assigned_to = u.id AND t.deleted_at IS NULL AND t.status NOT IN ('REQUESTED','REJECTED')
    $where
    GROUP BY e.id
    ORDER BY e.name
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$depts = $conn->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$roles = $conn->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY name")->fetchAll();

// ── Export CSV ────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="employees_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Emp Code', 'Name', 'Email', 'Department', 'Designation', 'Joining Date', 'Status']);
    foreach ($employees as $e) {
        fputcsv($out, [$e['emp_code'], $e['name'], $e['email'], $e['dept_name'], $e['designation'], $e['joining_date'], $e['status']]);
    }
    fclose($out); exit;
}

// JSON encode data for modal JS
$depts_json = json_encode($depts);
$roles_json = json_encode($roles);

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
    <div class="d-flex gap-2">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>1])) ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download me-1"></i> Export CSV
        </a>
        <a href="employee_form.php" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus me-1"></i> Add Employee
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, email, emp code..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $dept !== '' && (int)$dept === (int)$d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="ACTIVE"    <?= $status === 'ACTIVE'    ? 'selected' : '' ?>>Active</option>
                    <option value="PROBATION" <?= $status === 'PROBATION' ? 'selected' : '' ?>>Probation</option>
                    <option value="EXITED"    <?= $status === 'EXITED'    ? 'selected' : '' ?>>Exited</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i> Filter</button>
                <a href="employees.php" class="btn btn-light btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <?php if(has_role('SUPER_ADMIN','HR_ADMIN')): ?>
    <div class="px-3 pt-3 pb-0 d-flex align-items-center gap-2" style="font-size:.75rem;color:var(--text-muted);">
        <i class="bi bi-pencil-square"></i> Click any highlighted cell to edit inline &nbsp;&middot;&nbsp; Tab to move &nbsp;&middot;&nbsp; Enter to save &nbsp;&middot;&nbsp; Esc to cancel
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Emp Code</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Joining Date</th>
                        <th>Status</th>
                        <th class="text-center text-secondary" style="font-size:.75rem;">Todo</th>
                        <th class="text-center text-primary"   style="font-size:.75rem;">In Progress</th>
                        <th class="text-center text-warning"   style="font-size:.75rem;">Review</th>
                        <th class="text-center text-success"   style="font-size:.75rem;">Done</th>
                        <th class="text-center"                style="font-size:.75rem;">Total</th>
                        <th class="text-center"                style="font-size:.75rem; min-width:90px;">Progress</th>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr><td colspan="13" class="text-center py-5 text-muted">No employees found</td></tr>
                <?php else: ?>
                    <?php foreach ($employees as $e): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-primary"
                            <?php if(has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                            data-editable="text" data-table="employees" data-id="<?= $e['id'] ?>" data-field="emp_code" data-value="<?= sanitize($e['emp_code']) ?>"
                            <?php endif; ?>
                            ><?= sanitize($e['emp_code']) ?></td>
                        <td <?php if(has_role('SUPER_ADMIN','HR_ADMIN')): ?>data-editable="text" data-table="employees" data-id="<?= $e['id'] ?>" data-field="name" data-value="<?= sanitize($e['name']) ?>"<?php endif; ?>>
                            <a href="employee_profile.php?id=<?= $e['id'] ?>" class="text-decoration-none" onclick="event.stopPropagation()">
                                <div class="fw-semibold"><?= sanitize($e['name']) ?></div>
                            </a>
                            <div class="text-muted small"><?= sanitize($e['email']) ?></div>
                        </td>
                        <td data-readonly="1"><?= sanitize($e['dept_name'] ?? '-') ?></td>
                        <td <?php if(has_role('SUPER_ADMIN','HR_ADMIN')): ?>data-editable="text" data-table="employees" data-id="<?= $e['id'] ?>" data-field="designation" data-value="<?= sanitize($e['designation']) ?>"<?php endif; ?>><?= sanitize($e['designation']) ?></td>
                        <td <?php if(has_role('SUPER_ADMIN','HR_ADMIN')): ?>data-editable="date" data-table="employees" data-id="<?= $e['id'] ?>" data-field="joining_date" data-value="<?= $e['joining_date'] ?>"<?php endif; ?>><?= $e['joining_date'] ? date('d M Y', strtotime($e['joining_date'])) : '-' ?></td>
                        <td data-readonly="1"><span class="badge bg-<?= badge($e['status']) ?> rounded-pill"><?= $e['status'] ?></span></td>
                        <td class="text-center fw-semibold text-secondary"><?= (int)$e['task_todo'] ?></td>
                        <td class="text-center fw-semibold text-primary"><?= (int)$e['task_inprogress'] ?></td>
                        <td class="text-center fw-semibold text-warning"><?= (int)$e['task_review'] ?></td>
                        <td class="text-center fw-semibold text-success"><?= (int)$e['task_done'] ?></td>
                        <td class="text-center small text-muted"><?= (int)$e['task_total'] ?></td>
                        <td class="text-center" style="min-width:90px;">
                            <?php $pct = $e['task_total'] > 0 ? round($e['task_done']/$e['task_total']*100) : 0; ?>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div style="font-size:.65rem;" class="text-muted mt-1"><?= $pct ?>%</div>
                        </td>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditModal(<?= $e['id'] ?>)" title="Edit employee">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="?delete=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return hConfirmSync(event,'Delete this employee?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-muted small border-top">Showing <?= count($employees) ?> employee(s)</div>
    </div>
</div>

<!-- ── Edit Employee Modal ─────────────────────────────────────────── -->
<?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
<div class="modal fade" id="editEmpModal" tabindex="-1" aria-labelledby="editEmpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="editEmpModalLabel">
                    <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Employee
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4">
                <div id="editEmpAlert" class="d-none"></div>
                <div id="editEmpSpinner" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div>
                </div>
                <form id="editEmpForm" class="d-none" novalidate>
                    <input type="hidden" name="action" value="edit_emp">
                    <input type="hidden" name="id" id="ef_id">

                    <!-- Basic Info -->
                    <div class="fw-semibold small text-uppercase text-muted mb-2 mt-1" style="letter-spacing:.6px;">
                        <i class="bi bi-person-fill me-1"></i>Basic Information
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Full Name *</label>
                            <input type="text" name="name" id="ef_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Email *</label>
                            <input type="email" name="email" id="ef_email" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Date of Birth</label>
                            <input type="date" name="dob" id="ef_dob" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Phone</label>
                            <input type="text" name="phone" id="ef_phone" class="form-control" placeholder="10-digit mobile">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Emergency Contact</label>
                            <input type="text" name="emergency_phone" id="ef_emergency_phone" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Marital Status</label>
                            <select name="marital_status" id="ef_marital_status" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['Unmarried','Married','Single','Divorced','Widowed'] as $ms): ?>
                                <option value="<?= $ms ?>"><?= $ms ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Blood Group</label>
                            <select name="blood_group" id="ef_blood_group" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $bg): ?>
                                <option value="<?= $bg ?>"><?= $bg ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small">Residential Address</label>
                            <textarea name="address" id="ef_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Graduation / Qualification</label>
                            <input type="text" name="graduation" id="ef_graduation" class="form-control" placeholder="e.g. Post Graduate">
                        </div>
                    </div>

                    <!-- Work Details -->
                    <div class="fw-semibold small text-uppercase text-muted mb-2" style="letter-spacing:.6px;">
                        <i class="bi bi-briefcase-fill me-1"></i>Work Details
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Emp Code</label>
                            <input type="text" name="emp_code" id="ef_emp_code" class="form-control" placeholder="1001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Designation</label>
                            <input type="text" name="designation" id="ef_designation" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Department</label>
                            <select name="dept_id" id="ef_dept_id" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach ($depts as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Job Role</label>
                            <select name="role_id" id="ef_role_id" class="form-select" onchange="efHandleRoleChange()">
                                <option value="">-- Select --</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" data-can-tl="<?= $r['can_be_tl'] ?>"><?= sanitize($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Joining Date</label>
                            <input type="date" name="joining_date" id="ef_joining_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Date of Relieving</label>
                            <input type="date" name="date_of_relieving" id="ef_date_of_relieving" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Salary (₹)</label>
                            <input type="number" name="salary" id="ef_salary" class="form-control" placeholder="50000" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Status</label>
                            <select name="status" id="ef_status" class="form-select">
                                <option value="ACTIVE">Active</option>
                                <option value="PROBATION">Probation</option>
                                <option value="EXITED">Exited</option>
                            </select>
                        </div>
                        <!-- Team Lead row -->
                        <div class="col-12" id="ef-tl-row" style="display:none;">
                            <div class="card border-0 bg-light p-3" style="border-radius:8px;">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_team_lead" id="ef_is_tl" onchange="efToggleTlDept()">
                                    <label class="form-check-label fw-semibold small" for="ef_is_tl">Team Lead of a department?</label>
                                </div>
                                <div id="ef-tl-dept-row" class="d-none">
                                    <label class="form-label fw-semibold small">Leading Department</label>
                                    <select name="tl_dept_id" id="ef_tl_dept_id" class="form-select form-select-sm" style="max-width:280px;">
                                        <option value="">-- Select dept --</option>
                                        <?php foreach ($depts as $d): ?>
                                            <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Identity & Bank -->
                    <div class="fw-semibold small text-uppercase text-muted mb-2" style="letter-spacing:.6px;">
                        <i class="bi bi-shield-lock-fill me-1"></i>Identity &amp; Bank Details
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Aadhar No.</label>
                            <input type="text" name="aadhar_no" id="ef_aadhar_no" class="form-control" placeholder="XXXX XXXX XXXX">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">PAN No.</label>
                            <input type="text" name="pan_no" id="ef_pan_no" class="form-control" placeholder="ABCDE1234F" style="text-transform:uppercase;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Bank Account No.</label>
                            <input type="text" name="bank_acc_no" id="ef_bank_acc_no" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Bank Name</label>
                            <input type="text" name="bank_name" id="ef_bank_name" class="form-control" placeholder="e.g. Indian Bank">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">IFSC Code</label>
                            <input type="text" name="ifsc_code" id="ef_ifsc_code" class="form-control" placeholder="e.g. IDIB000M083" style="text-transform:uppercase;">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="editEmpSaveBtn" onclick="submitEditEmp()" disabled>
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const efDepts = <?= $depts_json ?>;
const efRoles = <?= $roles_json ?>;

function setVal(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = val ?? '';
}
function setSelect(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = val ?? '';
    // If value not found in options leave blank
    if (el.value !== String(val ?? '')) el.value = '';
}

async function openEditModal(empId) {
    const modal = new bootstrap.Modal(document.getElementById('editEmpModal'));
    modal.show();

    // Reset state
    document.getElementById('editEmpSpinner').classList.remove('d-none');
    document.getElementById('editEmpForm').classList.add('d-none');
    document.getElementById('editEmpSaveBtn').disabled = true;
    document.getElementById('editEmpAlert').className = 'd-none';

    try {
        const res  = await fetch('employees.php?get_emp=' + empId);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Populate all fields
        setVal('ef_id',               data.id);
        setVal('ef_name',             data.name);
        setVal('ef_email',            data.email);
        setVal('ef_emp_code',         data.emp_code);
        setVal('ef_designation',      data.designation);
        setVal('ef_graduation',       data.graduation);
        setVal('ef_dob',              data.dob);
        setVal('ef_phone',            data.phone);
        setVal('ef_emergency_phone',  data.emergency_phone);
        setVal('ef_address',          data.address);
        setVal('ef_joining_date',     data.joining_date);
        setVal('ef_date_of_relieving',data.date_of_relieving);
        setVal('ef_salary',           data.salary);
        setVal('ef_aadhar_no',        data.aadhar_no);
        setVal('ef_pan_no',           data.pan_no);
        setVal('ef_bank_acc_no',      data.bank_acc_no);
        setVal('ef_bank_name',        data.bank_name);
        setVal('ef_ifsc_code',        data.ifsc_code);
        setSelect('ef_marital_status',data.marital_status);
        setSelect('ef_blood_group',   data.blood_group);
        setSelect('ef_dept_id',       data.dept_id);
        setSelect('ef_status',        data.status || 'ACTIVE');
        setSelect('ef_role_id',       data.role_id);

        // Team lead
        const isTl = !!parseInt(data.is_team_lead || 0);
        document.getElementById('ef_is_tl').checked = isTl;
        setSelect('ef_tl_dept_id', data.tl_dept_id);
        efHandleRoleChange();
        if (isTl) document.getElementById('ef-tl-dept-row').classList.remove('d-none');

        document.getElementById('editEmpSpinner').classList.add('d-none');
        document.getElementById('editEmpForm').classList.remove('d-none');
        document.getElementById('editEmpSaveBtn').disabled = false;

    } catch (err) {
        document.getElementById('editEmpSpinner').classList.add('d-none');
        const alert = document.getElementById('editEmpAlert');
        alert.className = 'alert alert-danger small';
        alert.textContent = 'Failed to load employee data: ' + err.message;
    }
}

async function submitEditEmp() {
    const form    = document.getElementById('editEmpForm');
    const saveBtn = document.getElementById('editEmpSaveBtn');
    const alert   = document.getElementById('editEmpAlert');

    if (!form.checkValidity()) { form.reportValidity(); return; }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    const body = new URLSearchParams(new FormData(form));

    try {
        const res  = await fetch('employees.php', { method: 'POST', body });
        const data = await res.json();

        if (data.error) {
            alert.className = 'alert alert-danger small';
            alert.textContent = data.error;
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
        } else {
            bootstrap.Modal.getInstance(document.getElementById('editEmpModal')).hide();
            location.reload();
        }
    } catch (err) {
        alert.className = 'alert alert-danger small';
        alert.textContent = 'Save failed: ' + err.message;
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
    }
}

function efHandleRoleChange() {
    const sel   = document.getElementById('ef_role_id');
    const opt   = sel.options[sel.selectedIndex];
    const canTl = opt ? (opt.dataset.canTl == '1') : false;
    const tlRow = document.getElementById('ef-tl-row');
    tlRow.style.display = (sel.value && canTl) ? '' : 'none';
    if (!canTl) {
        document.getElementById('ef_is_tl').checked = false;
        document.getElementById('ef-tl-dept-row').classList.add('d-none');
    }
}

function efToggleTlDept() {
    const checked = document.getElementById('ef_is_tl').checked;
    document.getElementById('ef-tl-dept-row').classList.toggle('d-none', !checked);
}
</script>

<?php include 'footer.php'; ?>

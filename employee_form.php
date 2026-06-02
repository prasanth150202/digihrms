<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');

$id        = (int)($_GET['id'] ?? 0);
$emp       = null;
$emp_role  = null;
$pageTitle = $id ? 'Edit Employee' : 'Add Employee';
$page      = 'employees';

if ($id) {
    $s = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $s->execute([$id]);
    $emp = $s->fetch();
    if (!$emp) { set_flash('danger', 'Employee not found.'); header("Location: employees.php"); exit; }

    $s2 = $conn->prepare("SELECT * FROM employee_roles WHERE employee_id = ?");
    $s2->execute([$id]);
    $emp_role = $s2->fetch();
}

$errors = [];

// ── Inline: add department ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_dept') {
    header('Content-Type: application/json');
    $name = trim($_POST['dept_name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'Name required']); exit; }
    $chk = $conn->prepare('SELECT id FROM departments WHERE name = ?');
    $chk->execute([$name]);
    if ($existing = $chk->fetch()) {
        echo json_encode(['id' => $existing['id'], 'name' => $name]);
    } else {
        $conn->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$name]);
        echo json_encode(['id' => (int)$conn->lastInsertId(), 'name' => $name]);
    }
    exit;
}

// ── Inline: add role ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_role') {
    header('Content-Type: application/json');
    $name = trim($_POST['role_name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'Name required']); exit; }
    $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    $chk  = $conn->prepare('SELECT id FROM roles WHERE slug = ?');
    $chk->execute([$slug]);
    if ($existing = $chk->fetch()) {
        $s = $conn->prepare('SELECT id, name FROM roles WHERE id = ?');
        $s->execute([$existing['id']]);
        echo json_encode($s->fetch());
    } else {
        $conn->prepare('INSERT INTO roles (name, slug, can_be_tl) VALUES (?,?,1)')->execute([$name, $slug]);
        echo json_encode(['id' => (int)$conn->lastInsertId(), 'name' => $name]);
    }
    exit;
}

// ── Main form submit ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $data = [
        'emp_code'          => trim($_POST['emp_code']          ?? ''),
        'name'              => trim($_POST['name']              ?? ''),
        'email'             => trim($_POST['email']             ?? ''),
        'dept_id'           => (int)($_POST['dept_id']          ?? 0) ?: null,
        'designation'       => trim($_POST['designation']       ?? '') ?: null,
        'graduation'        => trim($_POST['graduation']        ?? '') ?: null,
        'dob'               => $_POST['dob']                   ?? '' ?: null,
        'joining_date'      => $_POST['joining_date']          ?? '' ?: null,
        'date_of_relieving' => $_POST['date_of_relieving']     ?? '' ?: null,
        'salary'            => $_POST['salary']                ?? '' ?: null,
        'status'            => $_POST['status']                ?? 'ACTIVE',
        'phone'             => trim($_POST['phone']             ?? '') ?: null,
        'emergency_phone'   => trim($_POST['emergency_phone']  ?? '') ?: null,
        'address'           => trim($_POST['address']           ?? '') ?: null,
        'marital_status'    => trim($_POST['marital_status']   ?? '') ?: null,
        'blood_group'       => trim($_POST['blood_group']      ?? '') ?: null,
        'aadhar_no'         => trim($_POST['aadhar_no']        ?? '') ?: null,
        'pan_no'            => trim($_POST['pan_no']           ?? '') ?: null,
        'bank_acc_no'       => trim($_POST['bank_acc_no']      ?? '') ?: null,
        'bank_name'         => trim($_POST['bank_name']        ?? '') ?: null,
        'ifsc_code'         => trim($_POST['ifsc_code']        ?? '') ?: null,
    ];
    $role_id     = (int)($_POST['role_id']     ?? 0) ?: null;
    $is_tl       = isset($_POST['is_team_lead']) ? 1 : 0;
    $tl_dept_id  = (int)($_POST['tl_dept_id']  ?? 0) ?: null;
    $create_user = isset($_POST['create_login']);
    $user_role   = $_POST['user_role'] ?? 'EMPLOYEE';

    if (!$data['name'])  $errors[] = 'Name is required.';
    if (!$data['email']) $errors[] = 'Email is required.';

    if (!$data['emp_code']) {
        $last = $conn->query("SELECT emp_code FROM employees ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last ? ((int)preg_replace('/\D/', '', $last) + 1) : 1;
        $data['emp_code'] = 'EMP' . str_pad($num, 3, '0', STR_PAD_LEFT);
    }

    if (!$errors) {
        $conn->beginTransaction();
        try {
            $fields = 'emp_code,name,email,dept_id,designation,graduation,dob,joining_date,date_of_relieving,salary,status,phone,emergency_phone,address,marital_status,blood_group,aadhar_no,pan_no,bank_acc_no,bank_name,ifsc_code';
            $vals   = [$data['emp_code'],$data['name'],$data['email'],$data['dept_id'],$data['designation'],$data['graduation'],$data['dob'],$data['joining_date'],$data['date_of_relieving'],$data['salary'],$data['status'],$data['phone'],$data['emergency_phone'],$data['address'],$data['marital_status'],$data['blood_group'],$data['aadhar_no'],$data['pan_no'],$data['bank_acc_no'],$data['bank_name'],$data['ifsc_code']];
            if ($id) {
                $set = implode(',', array_map(fn($f) => "$f=?", explode(',', $fields)));
                $conn->prepare("UPDATE employees SET $set WHERE id=?")->execute([...$vals, $id]);
                $emp_id = $id;
            } else {
                $ph = implode(',', array_fill(0, count($vals), '?'));
                $conn->prepare("INSERT INTO employees ($fields) VALUES ($ph)")->execute($vals);
                $emp_id = (int)$conn->lastInsertId();
            }

            if ($role_id) {
                $chk = $conn->prepare("SELECT id FROM employee_roles WHERE employee_id = ?");
                $chk->execute([$emp_id]);
                if ($chk->fetch()) {
                    $conn->prepare("UPDATE employee_roles SET role_id=?,is_team_lead=?,dept_id=? WHERE employee_id=?")
                         ->execute([$role_id, $is_tl, $is_tl ? $tl_dept_id : null, $emp_id]);
                } else {
                    $conn->prepare("INSERT INTO employee_roles (employee_id,role_id,is_team_lead,dept_id) VALUES (?,?,?,?)")
                         ->execute([$emp_id, $role_id, $is_tl, $is_tl ? $tl_dept_id : null]);
                }
            }

            if ($create_user && !$id) {
                $exists = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $exists->execute([$data['email']]);
                if (!$exists->fetch()) {
                    $token    = bin2hex(random_bytes(24));
                    $expires  = date('Y-m-d H:i:s', strtotime('+7 days'));
                    $tmp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                    $conn->prepare("INSERT INTO users (name,email,password,role,emp_no,invite_token,invite_expires,invite_status) VALUES (?,?,?,?,?,?,?,'PENDING')")
                         ->execute([$data['name'],$data['email'],$tmp_pass,$user_role,$data['emp_code'],$token,$expires]);
                }
            }

            $conn->commit();
            set_flash('success', $id ? 'Employee updated.' : 'Employee added successfully.');
            header("Location: employees.php"); exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$depts = $conn->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$roles = $conn->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY name")->fetchAll();
$v     = $emp ?? [];

include 'header.php';
?>

<style>
.ef-section { background:#fff; border:1px solid #e9ecef; border-radius:16px; padding:24px; margin-bottom:20px; }
.ef-section-header { display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #f1f5f9; }
.ef-section-icon { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.95rem; }
.ef-section-title { font-weight:700; font-size:.9rem; color:#0f172a; margin:0; }
.ef-section-sub { font-size:.72rem; color:#94a3b8; margin:0; }
.ef-nav { display:flex; gap:4px; background:#f8fafc; border-radius:12px; padding:4px; margin-bottom:24px; flex-wrap:wrap; }
.ef-nav-btn { flex:1; min-width:100px; padding:8px 12px; border:none; border-radius:9px; background:transparent; color:#64748b;
              font-size:.8rem; font-weight:500; cursor:pointer; text-align:center; transition:all .15s; white-space:nowrap; }
.ef-nav-btn:hover { color:#0f4c81; }
.ef-nav-btn.active { background:#fff; color:#0f4c81; font-weight:600; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.ef-step { display:none; }
.ef-step.active { display:block; }
.ef-status-active   { background:#dcfce7; color:#166534; }
.ef-status-probation{ background:#fef9c3; color:#92400e; }
.ef-status-exited   { background:#fee2e2; color:#991b1b; }
</style>

<!-- Back link + title -->
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="employees.php" class="btn btn-light btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <div>
        <h6 class="fw-bold mb-0"><?= $id ? 'Edit Employee' : 'Add New Employee' ?></h6>
        <div class="text-muted" style="font-size:.75rem;"><?= $id ? 'Update employee information' : 'Fill in details to add a new employee' ?></div>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger small py-2 mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><?= sanitize($err) ?>
    </div>
<?php endforeach; ?>

<!-- Section Navigation -->
<div class="ef-nav" id="efNav">
    <button type="button" class="ef-nav-btn active" onclick="efShow('basic')" id="nav-basic">
        <i class="bi bi-person me-1"></i>Basic Info
    </button>
    <button type="button" class="ef-nav-btn" onclick="efShow('work')" id="nav-work">
        <i class="bi bi-briefcase me-1"></i>Work Details
    </button>
    <button type="button" class="ef-nav-btn" onclick="efShow('identity')" id="nav-identity">
        <i class="bi bi-shield-lock me-1"></i>Identity &amp; Bank
    </button>
    <?php if (!$id): ?>
    <button type="button" class="ef-nav-btn" onclick="efShow('login')" id="nav-login">
        <i class="bi bi-key me-1"></i>HRMS Login
    </button>
    <?php endif; ?>
</div>

<form method="POST" id="emp-form">
<input type="hidden" name="action" value="save">

<!-- ── SECTION: Basic Info ──────────────────────────────────── -->
<div class="ef-step active" id="step-basic">
<div class="ef-section">
    <div class="ef-section-header">
        <div class="ef-section-icon" style="background:#eff6ff;">
            <i class="bi bi-person-fill text-primary"></i>
        </div>
        <div>
            <p class="ef-section-title">Basic Information</p>
            <p class="ef-section-sub">Personal details and contact info</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= sanitize($v['name'] ?? '') ?>" required placeholder="e.g. Ravi Kumar">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($v['email'] ?? '') ?>" required placeholder="ravi@company.com">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Date of Birth</label>
            <input type="date" name="dob" class="form-control" value="<?= sanitize($v['dob'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= sanitize($v['phone'] ?? '') ?>" placeholder="10-digit mobile">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Emergency Contact</label>
            <input type="text" name="emergency_phone" class="form-control" value="<?= sanitize($v['emergency_phone'] ?? '') ?>" placeholder="Emergency phone">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Marital Status</label>
            <select name="marital_status" class="form-select">
                <option value="">— Select —</option>
                <?php foreach (['Unmarried','Married','Single','Divorced','Widowed'] as $ms): ?>
                <option value="<?= $ms ?>" <?= ($v['marital_status'] ?? '') === $ms ? 'selected' : '' ?>><?= $ms ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Blood Group</label>
            <select name="blood_group" class="form-select">
                <option value="">— Select —</option>
                <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $bg): ?>
                <option value="<?= $bg ?>" <?= ($v['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Qualification</label>
            <input type="text" name="graduation" class="form-control" value="<?= sanitize($v['graduation'] ?? '') ?>" placeholder="e.g. Post Graduate">
        </div>
        <div class="col-md-12">
            <label class="form-label fw-semibold small">Residential Address</label>
            <textarea name="address" class="form-control" rows="2" placeholder="Full address"><?= sanitize($v['address'] ?? '') ?></textarea>
        </div>
    </div>
</div>
<div class="d-flex justify-content-end">
    <button type="button" class="btn btn-primary btn-sm" onclick="efShow('work')">
        Next: Work Details <i class="bi bi-arrow-right ms-1"></i>
    </button>
</div>
</div>

<!-- ── SECTION: Work Details ────────────────────────────────── -->
<div class="ef-step" id="step-work">
<div class="ef-section">
    <div class="ef-section-header">
        <div class="ef-section-icon" style="background:#ecfdf5;">
            <i class="bi bi-briefcase-fill text-success"></i>
        </div>
        <div>
            <p class="ef-section-title">Work Details</p>
            <p class="ef-section-sub">Employment, role and team lead assignment</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Emp Code <span class="text-muted fw-normal">(auto if blank)</span></label>
            <input type="text" name="emp_code" class="form-control" value="<?= sanitize($v['emp_code'] ?? '') ?>" placeholder="EMP001">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Designation</label>
            <input type="text" name="designation" class="form-control" value="<?= sanitize($v['designation'] ?? '') ?>" placeholder="e.g. Senior Product Marketer">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Status</label>
            <select name="status" class="form-select">
                <option value="ACTIVE"    <?= ($v['status'] ?? 'ACTIVE') === 'ACTIVE'    ? 'selected' : '' ?>>Active</option>
                <option value="PROBATION" <?= ($v['status'] ?? '') === 'PROBATION'       ? 'selected' : '' ?>>Probation</option>
                <option value="EXITED"    <?= ($v['status'] ?? '') === 'EXITED'          ? 'selected' : '' ?>>Exited</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Department</label>
            <div class="input-group">
                <select name="dept_id" id="dept-select" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($v['dept_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="showInlineAdd('dept')" title="Add new department">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            <div id="inline-dept" class="mt-2 d-none">
                <div class="input-group input-group-sm">
                    <input type="text" id="new-dept-name" class="form-control" placeholder="Department name">
                    <button type="button" class="btn btn-success" onclick="saveInlineDept()">Add</button>
                    <button type="button" class="btn btn-light" onclick="hideInlineAdd('dept')">Cancel</button>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Job Role</label>
            <div class="input-group">
                <select name="role_id" id="role-select" class="form-select" onchange="handleRoleChange()">
                    <option value="">— Select —</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>" data-can-tl="<?= $r['can_be_tl'] ?>"
                            <?= ($emp_role['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= sanitize($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="showInlineAdd('role')" title="Add new role">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            <div id="inline-role" class="mt-2 d-none">
                <div class="input-group input-group-sm">
                    <input type="text" id="new-role-name" class="form-control" placeholder="Role name">
                    <button type="button" class="btn btn-success" onclick="saveInlineRole()">Add</button>
                    <button type="button" class="btn btn-light" onclick="hideInlineAdd('role')">Cancel</button>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Salary (₹)</label>
            <input type="number" name="salary" class="form-control" value="<?= sanitize($v['salary'] ?? '') ?>" placeholder="50000" step="0.01">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Joining Date</label>
            <input type="date" name="joining_date" class="form-control" value="<?= sanitize($v['joining_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Date of Relieving</label>
            <input type="date" name="date_of_relieving" class="form-control" value="<?= sanitize($v['date_of_relieving'] ?? '') ?>">
        </div>

        <!-- Team Lead Section -->
        <div class="col-12" id="tl-row" style="display:none;">
            <div class="p-3 rounded-3" style="background:#f0f7ff;border:1px solid #bfdbfe;">
                <div class="fw-semibold small mb-2 text-primary"><i class="bi bi-star-fill me-1"></i>Team Lead Assignment</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="is_team_lead" id="is-tl-check"
                        <?= ($emp_role['is_team_lead'] ?? 0) ? 'checked' : '' ?> onchange="toggleTlDept()">
                    <label class="form-check-label fw-semibold small" for="is-tl-check">Assign as Team Lead of a department</label>
                </div>
                <div id="tl-dept-row" class="<?= ($emp_role['is_team_lead'] ?? 0) ? '' : 'd-none' ?>">
                    <label class="form-label fw-semibold small">Leading Department</label>
                    <select name="tl_dept_id" class="form-select form-select-sm" style="max-width:280px;">
                        <option value="">— Select dept —</option>
                        <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($emp_role['dept_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="d-flex justify-content-between">
    <button type="button" class="btn btn-light btn-sm" onclick="efShow('basic')">
        <i class="bi bi-arrow-left me-1"></i>Back
    </button>
    <button type="button" class="btn btn-primary btn-sm" onclick="efShow('identity')">
        Next: Identity &amp; Bank <i class="bi bi-arrow-right ms-1"></i>
    </button>
</div>
</div>

<!-- ── SECTION: Identity & Bank ────────────────────────────── -->
<div class="ef-step" id="step-identity">
<div class="ef-section">
    <div class="ef-section-header">
        <div class="ef-section-icon" style="background:#f5f3ff;">
            <i class="bi bi-shield-lock-fill text-purple" style="color:#7c3aed;"></i>
        </div>
        <div>
            <p class="ef-section-title">Identity &amp; Bank Details</p>
            <p class="ef-section-sub">Government ID and banking information</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-12 mb-1">
            <div class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.5px;font-size:.68rem;">Government IDs</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Aadhar No.</label>
            <input type="text" name="aadhar_no" class="form-control" value="<?= sanitize($v['aadhar_no'] ?? '') ?>" placeholder="XXXX XXXX XXXX">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">PAN No.</label>
            <input type="text" name="pan_no" class="form-control" value="<?= sanitize($v['pan_no'] ?? '') ?>" placeholder="ABCDE1234F" style="text-transform:uppercase;">
        </div>
        <div class="col-12 mt-2 mb-1">
            <div class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.5px;font-size:.68rem;">Bank Details</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Account No.</label>
            <input type="text" name="bank_acc_no" class="form-control" value="<?= sanitize($v['bank_acc_no'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">Bank Name</label>
            <input type="text" name="bank_name" class="form-control" value="<?= sanitize($v['bank_name'] ?? '') ?>" placeholder="e.g. Indian Bank">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small">IFSC Code</label>
            <input type="text" name="ifsc_code" class="form-control" value="<?= sanitize($v['ifsc_code'] ?? '') ?>" placeholder="e.g. IDIB000M083" style="text-transform:uppercase;">
        </div>
    </div>
</div>
<div class="d-flex justify-content-between">
    <button type="button" class="btn btn-light btn-sm" onclick="efShow('work')">
        <i class="bi bi-arrow-left me-1"></i>Back
    </button>
    <?php if (!$id): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="efShow('login')">
        Next: HRMS Login <i class="bi bi-arrow-right ms-1"></i>
    </button>
    <?php else: ?>
    <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-check-lg me-1"></i>Update Employee
    </button>
    <?php endif; ?>
</div>
</div>

<!-- ── SECTION: Login Account (add only) ───────────────────── -->
<?php if (!$id): ?>
<div class="ef-step" id="step-login">
<div class="ef-section">
    <div class="ef-section-header">
        <div class="ef-section-icon" style="background:#fef9c3;">
            <i class="bi bi-key-fill" style="color:#b45309;"></i>
        </div>
        <div>
            <p class="ef-section-title">HRMS Login Account</p>
            <p class="ef-section-sub">Optionally create a system login for this employee</p>
        </div>
    </div>
    <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="create_login" id="create-login" checked onchange="toggleUserRole()">
            <label class="form-check-label fw-semibold small" for="create-login">Create HRMS login account for this employee</label>
        </div>
        <div id="user-role-row">
            <label class="form-label fw-semibold small">Login Role</label>
            <select name="user_role" class="form-select form-select-sm mb-2" style="max-width:240px;">
                <option value="EMPLOYEE">Employee</option>
                <option value="TEAM_LEAD">Team Lead</option>
                <option value="DEPT_MANAGER">Dept Manager</option>
                <option value="HR_ADMIN">HR Admin</option>
                <option value="SUPER_ADMIN">Super Admin</option>
            </select>
            <div class="small text-muted d-flex align-items-center gap-1">
                <i class="bi bi-info-circle"></i>
                An invite link will be generated. Employee sets their own password.
            </div>
        </div>
    </div>
</div>
<div class="d-flex justify-content-between">
    <button type="button" class="btn btn-light btn-sm" onclick="efShow('identity')">
        <i class="bi bi-arrow-left me-1"></i>Back
    </button>
    <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-check-lg me-1"></i>Save Employee
    </button>
</div>
</div>
<?php endif; ?>

</form>

<script>
var efSteps = ['basic','work','identity'<?= !$id ? ",'login'" : '' ?>];

function efShow(step) {
    efSteps.forEach(function(s) {
        var el  = document.getElementById('step-'+s);
        var btn = document.getElementById('nav-'+s);
        if (el)  el.classList.toggle('active', s === step);
        if (btn) btn.classList.toggle('active', s === step);
    });
    window.scrollTo(0, 0);
}

function showInlineAdd(type) {
    document.getElementById('inline-' + type).classList.remove('d-none');
}
function hideInlineAdd(type) {
    document.getElementById('inline-' + type).classList.add('d-none');
    document.getElementById('new-' + type + '-name').value = '';
}

async function saveInlineDept() {
    var name = document.getElementById('new-dept-name').value.trim();
    if (!name) return;
    var res  = await fetch('employee_form.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=add_dept&dept_name=' + encodeURIComponent(name)
    });
    var data = await res.json();
    if (data.error) { alert(data.error); return; }
    var sel = document.getElementById('dept-select');
    if (!sel.querySelector('option[value="'+data.id+'"]')) {
        var opt = new Option(data.name, data.id);
        sel.add(opt);
        var tlSel = document.querySelector('select[name="tl_dept_id"]');
        if (tlSel) tlSel.add(new Option(data.name, data.id));
    }
    sel.value = data.id;
    hideInlineAdd('dept');
}

async function saveInlineRole() {
    var name = document.getElementById('new-role-name').value.trim();
    if (!name) return;
    var res  = await fetch('employee_form.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=add_role&role_name=' + encodeURIComponent(name)
    });
    var data = await res.json();
    if (data.error) { alert(data.error); return; }
    var sel = document.getElementById('role-select');
    if (!sel.querySelector('option[value="'+data.id+'"]')) {
        var opt = new Option(data.name, data.id);
        opt.dataset.canTl = 1;
        sel.add(opt);
    }
    sel.value = data.id;
    hideInlineAdd('role');
    handleRoleChange();
}

function handleRoleChange() {
    var sel   = document.getElementById('role-select');
    var opt   = sel.options[sel.selectedIndex];
    var canTl = opt ? (opt.dataset.canTl == '1') : false;
    var tlRow = document.getElementById('tl-row');
    tlRow.style.display = (sel.value && canTl) ? '' : 'none';
    if (!canTl) {
        document.getElementById('is-tl-check').checked = false;
        document.getElementById('tl-dept-row').classList.add('d-none');
    }
}

function toggleTlDept() {
    var checked = document.getElementById('is-tl-check').checked;
    document.getElementById('tl-dept-row').classList.toggle('d-none', !checked);
}

function toggleUserRole() {
    var checked = document.getElementById('create-login') && document.getElementById('create-login').checked;
    var row     = document.getElementById('user-role-row');
    if (row) row.style.display = checked ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    handleRoleChange();
});
</script>

<?php include 'footer.php'; ?>

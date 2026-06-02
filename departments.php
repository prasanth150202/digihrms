<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'departments';
$pageTitle = 'Departments';

// ── POST: save dept (add / edit) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_dept') {
        $name = trim($_POST['name'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);
        if ($name) {
            if ($id) {
                $conn->prepare("UPDATE departments SET name=? WHERE id=?")->execute([$name, $id]);
                set_flash('success', 'Department updated.');
            } else {
                $conn->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
                set_flash('success', 'Department added.');
            }
        }
        header("Location: departments.php"); exit;
    }

    // Assign TL to department via employee_roles.dept_id + is_team_lead
    if ($action === 'set_tl') {
        $dept_id = (int)($_POST['dept_id'] ?? 0);
        $emp_id  = (int)($_POST['emp_id']  ?? 0); // 0 = remove TL

        // Clear existing TL for this dept — also reset their users.role to EMPLOYEE
        $oldTl = $conn->prepare("SELECT er.employee_id FROM employee_roles er WHERE er.dept_id=? AND er.is_team_lead=1 LIMIT 1");
        $oldTl->execute([$dept_id]);
        $oldTlEmpId = $oldTl->fetchColumn();
        $conn->prepare("UPDATE employee_roles SET is_team_lead=0, dept_id=NULL WHERE dept_id=? AND is_team_lead=1")
             ->execute([$dept_id]);
        if ($oldTlEmpId) {
            // Only demote if not still TL elsewhere and not a higher role
            $stillTl = $conn->prepare("SELECT COUNT(*) FROM employee_roles WHERE employee_id=? AND is_team_lead=1");
            $stillTl->execute([$oldTlEmpId]);
            if (!(int)$stillTl->fetchColumn()) {
                $conn->prepare("UPDATE users u JOIN employees e ON e.email=u.email SET u.role='EMPLOYEE' WHERE e.id=? AND u.role='TEAM_LEAD'")
                     ->execute([$oldTlEmpId]);
            }
        }

        if ($emp_id) {
            // Upsert employee_roles row for the new TL
            $chk = $conn->prepare("SELECT id FROM employee_roles WHERE employee_id=?");
            $chk->execute([$emp_id]);
            if ($chk->fetch()) {
                $conn->prepare("UPDATE employee_roles SET is_team_lead=1, dept_id=? WHERE employee_id=?")
                     ->execute([$dept_id, $emp_id]);
            } else {
                $conn->prepare("INSERT INTO employee_roles (employee_id, is_team_lead, dept_id) VALUES (?,1,?)")
                     ->execute([$emp_id, $dept_id]);
            }
            // Promote users.role to TEAM_LEAD
            $conn->prepare("UPDATE users u JOIN employees e ON e.email=u.email SET u.role='TEAM_LEAD' WHERE e.id=? AND u.role NOT IN ('SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER')")
                 ->execute([$emp_id]);
        }
        set_flash('success', $emp_id ? 'Team Lead assigned.' : 'Team Lead removed.');
        header("Location: departments.php"); exit;
    }

    // Move employee to a different dept
    if ($action === 'move_emp') {
        $emp_id  = (int)($_POST['emp_id']  ?? 0);
        $dept_id = (int)($_POST['dept_id'] ?? 0) ?: null;
        $conn->prepare("UPDATE employees SET dept_id=? WHERE id=?")->execute([$dept_id, $emp_id]);
        set_flash('success', 'Employee moved.');
        header("Location: departments.php"); exit;
    }
}

// ── GET: delete dept ──────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id    = (int)$_GET['delete'];
    $inUse = $conn->query("SELECT COUNT(*) FROM employees WHERE dept_id=$id")->fetchColumn();
    if ($inUse) {
        set_flash('danger', 'Cannot delete department with assigned employees.');
    } else {
        $conn->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
        set_flash('success', 'Department deleted.');
    }
    header("Location: departments.php"); exit;
}

// ── Fetch departments with counts ────────────────────────────────
$departments = $conn->query("
    SELECT d.*,
           COUNT(e.id) AS emp_count
    FROM   departments d
    LEFT JOIN employees e ON e.dept_id = d.id AND e.status = 'ACTIVE'
    GROUP  BY d.id
    ORDER  BY d.name
")->fetchAll();

// Employees per department (active)
$emp_rows = $conn->query("
    SELECT e.id, e.name, e.designation, e.dept_id, e.status,
           r.name AS role_name,
           er.is_team_lead
    FROM   employees e
    LEFT JOIN employee_roles er ON er.employee_id = e.id
    LEFT JOIN roles r ON r.id = er.role_id
    WHERE  e.status = 'ACTIVE'
    ORDER  BY e.name
")->fetchAll();

$emps_by_dept = [];
foreach ($emp_rows as $e) {
    if ($e['dept_id']) $emps_by_dept[$e['dept_id']][] = $e;
}

// TL per department (employee_roles.dept_id where is_team_lead=1)
$tl_rows = $conn->query("
    SELECT er.dept_id, e.id AS emp_id, e.name AS emp_name, e.designation
    FROM   employee_roles er
    JOIN   employees e ON e.id = er.employee_id
    WHERE  er.is_team_lead = 1 AND er.dept_id IS NOT NULL AND e.status = 'ACTIVE'
")->fetchAll();
$tl_by_dept = [];
foreach ($tl_rows as $tl) $tl_by_dept[$tl['dept_id']] = $tl;

// All active employees for dropdowns
$all_emps = $conn->query("SELECT id, name, designation, dept_id FROM employees WHERE status='ACTIVE' ORDER BY name")->fetchAll();

// JSON for JS modal rendering
$tl_data_json = json_encode(array_map(function($e) {
    return [
        'id'          => $e['id'],
        'name'        => $e['name'],
        'designation' => $e['designation'] ?? '',
        'dept_id'     => $e['dept_id'],
    ];
}, $all_emps));

include 'header.php';
?>

<style>
.dept-block { background:#fff; border:1px solid #e9ecef; border-radius:16px; margin-bottom:20px; overflow:hidden; }
.dept-block-hdr { padding:16px 20px; background:#f8fafc; border-bottom:1px solid #f1f5f9;
                  display:flex; align-items:center; gap:14px; }
.dept-icon { width:42px; height:42px; border-radius:11px; display:flex; align-items:center;
             justify-content:center; flex-shrink:0; font-size:1.1rem; font-weight:700; }
.dept-body { padding:0; }

/* TL strip */
.tl-strip { padding:12px 20px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center;
            gap:12px; background:#fffbeb; flex-wrap:wrap; }
.tl-avatar { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#f59e0b,#d97706);
             display:flex; align-items:center; justify-content:center; color:#fff; font-size:.8rem;
             font-weight:700; flex-shrink:0; }
.tl-label  { background:#fef3c7; color:#92400e; border-radius:20px; padding:2px 9px;
             font-size:.67rem; font-weight:700; }

/* Employee table */
.emp-table { width:100%; }
.emp-table thead th { font-size:.73rem; color:#64748b; font-weight:600; padding:8px 20px;
                      border-bottom:1px solid #f1f5f9; background:#fafafa; text-transform:uppercase; letter-spacing:.4px; }
.emp-table tbody td { padding:9px 20px; font-size:.83rem; border-bottom:1px solid #f8fafc; vertical-align:middle; }
.emp-table tbody tr:last-child td { border-bottom:none; }
.emp-table tbody tr:hover { background:#fafafa; }

.emp-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center;
              justify-content:center; color:#fff; font-size:.72rem; font-weight:700; flex-shrink:0; }
.tl-star { color:#f59e0b; font-size:.75rem; }
.dept-add-btn { border:2px dashed #cbd5e1; background:transparent; border-radius:16px; padding:24px;
                display:flex; align-items:center; justify-content:center; gap:10px; color:#64748b;
                cursor:pointer; transition:all .15s; font-size:.9rem; width:100%; }
.dept-add-btn:hover { border-color:#0f4c81; color:#0f4c81; background:#f0f7ff; }
.emp-pill { background:#eff6ff; color:#1d4ed8; border-radius:20px; padding:2px 9px; font-size:.68rem; font-weight:600; }
.emp-pill.zero { background:#f8fafc; color:#94a3b8; }

/* TL Picker */
.tl-pick-card { border:2px solid #e9ecef; border-radius:12px; padding:12px 14px; cursor:pointer;
                transition:border-color .15s, background .15s; margin-bottom:8px; }
.tl-pick-card:hover { border-color:#f59e0b; background:#fffbeb; }
.tl-pick-card.selected { border-color:#f59e0b; background:#fffbeb; }
.tl-remove-card:hover, .tl-remove-card.selected { border-color:#e2e8f0; background:#f8fafc; }
.tl-pick-avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center;
                  justify-content:center; color:#fff; font-size:.85rem; font-weight:700; flex-shrink:0; }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted small mb-0">
        Manage departments, assign Team Leads, and view employee rosters per department.
    </p>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDeptModal">
        <i class="bi bi-plus-lg me-1"></i>Add Department
    </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center bg-white">
            <div style="font-size:1.8rem;font-weight:700;color:#0f4c81;"><?= count($departments) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Departments</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center bg-white">
            <?php $totalEmps = array_sum(array_column($departments, 'emp_count')); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#047857;"><?= $totalEmps ?></div>
            <div class="text-muted" style="font-size:.73rem;">Active Employees</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center bg-white">
            <div style="font-size:1.8rem;font-weight:700;color:#b45309;"><?= count($tl_by_dept) ?></div>
            <div class="text-muted" style="font-size:.73rem;">With Team Lead</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center bg-white">
            <?php $unassigned = count(array_filter($emp_rows, fn($e) => !$e['dept_id'])); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#dc2626;"><?= $unassigned ?></div>
            <div class="text-muted" style="font-size:.73rem;">Unassigned Emps</div>
        </div>
    </div>
</div>

<!-- Department Blocks -->
<?php
$dept_colors = ['#0f4c81','#047857','#7c3aed','#be185d','#0369a1','#b45309','#dc2626','#0891b2','#166534','#9333ea'];
foreach ($departments as $i => $d):
    $color     = $dept_colors[$i % count($dept_colors)];
    $initial   = strtoupper(substr($d['name'], 0, 1));
    $d_emps    = $emps_by_dept[$d['id']] ?? [];
    $d_tl      = $tl_by_dept[$d['id']]   ?? null;
    $block_id  = 'dept-body-' . $d['id'];
?>
<div class="dept-block">

    <!-- Department Header -->
    <div class="dept-block-hdr">
        <div class="dept-icon" style="background:<?= $color ?>1a; color:<?= $color ?>;"><?= $initial ?></div>
        <div class="flex-grow-1">
            <div class="fw-bold" style="font-size:.95rem;"><?= sanitize($d['name']) ?></div>
            <div class="text-muted" style="font-size:.72rem;">
                <span class="emp-pill <?= $d['emp_count']==0?'zero':'' ?>"><?= (int)$d['emp_count'] ?> employee<?= $d['emp_count']!=1?'s':'' ?></span>
                <?php if ($d_tl): ?>
                    <span class="ms-2"><i class="bi bi-star-fill tl-star"></i> <?= sanitize($d_tl['emp_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <!-- Actions -->
        <div class="d-flex gap-1 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-warning"
                onclick="openSetTL(<?= $d['id'] ?>, '<?= addslashes(sanitize($d['name'])) ?>', <?= $d_tl ? $d_tl['emp_id'] : 0 ?>)"
                title="Assign Team Lead">
                <i class="bi bi-star me-1" style="font-size:.8rem;"></i>Set TL
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary"
                onclick="openEditDept(<?= $d['id'] ?>, '<?= addslashes(sanitize($d['name'])) ?>')"
                title="Edit department name">
                <i class="bi bi-pencil" style="font-size:.8rem;"></i>
            </button>
            <?php if ((int)$d['emp_count'] === 0): ?>
            <a href="?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger"
               onclick="return hConfirmSync(event,'Delete \'<?= sanitize($d['name']) ?>\'?')" title="Delete">
                <i class="bi bi-trash" style="font-size:.8rem;"></i>
            </a>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Has employees — reassign first">
                <i class="bi bi-trash" style="font-size:.8rem;"></i>
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="toggleDeptBody('<?= $block_id ?>', this)" title="Expand / Collapse">
                <i class="bi bi-chevron-down" style="font-size:.8rem;transition:transform .2s;"></i>
            </button>
        </div>
    </div>

    <!-- TL Strip (if TL assigned) -->
    <?php if ($d_tl): ?>
    <div class="tl-strip" id="<?= $block_id ?>">
        <div class="tl-avatar"><?= strtoupper(substr($d_tl['emp_name'],0,1)) ?></div>
        <div>
            <div class="small fw-semibold"><?= sanitize($d_tl['emp_name']) ?></div>
            <div style="font-size:.7rem;color:#92400e;"><?= sanitize($d_tl['designation'] ?? '') ?></div>
        </div>
        <span class="tl-label"><i class="bi bi-star-fill me-1"></i>Team Lead</span>
        <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2"
                onclick="openSetTL(<?= $d['id'] ?>, '<?= addslashes(sanitize($d['name'])) ?>', <?= $d_tl['emp_id'] ?>)">
                <i class="bi bi-pencil me-1" style="font-size:.75rem;"></i>Change TL
            </button>
            <form method="POST" class="d-inline" onsubmit="return hConfirmSync(event,'Remove Team Lead from this department?')">
                <input type="hidden" name="action"  value="set_tl">
                <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
                <input type="hidden" name="emp_id"  value="0">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                    <i class="bi bi-x-lg" style="font-size:.75rem;"></i>
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div id="<?= $block_id ?>">
    <?php endif; ?>

    <!-- Employee Table -->
    <?php if ($d_emps): ?>
    <div <?= $d_tl ? '' : 'id="'.$block_id.'"' ?>>
        <table class="emp-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Designation / Role</th>
                    <th style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($d_emps as $e):
                $colors = ['#0f4c81','#047857','#7c3aed','#be185d','#0369a1','#b45309'];
                $ec = $colors[crc32($e['name']) % count($colors)];
            ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="emp-avatar" style="background:linear-gradient(135deg,<?= $ec ?>,<?= $ec ?>cc);">
                            <?= strtoupper(substr($e['name'],0,1)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold" style="font-size:.83rem;">
                                <?= sanitize($e['name']) ?>
                                <?php if ($d_tl && $e['id'] == $d_tl['emp_id']): ?>
                                    <i class="bi bi-star-fill tl-star ms-1"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="font-size:.78rem;"><?= sanitize($e['designation'] ?? '—') ?></div>
                    <?php if ($e['role_name']): ?>
                    <div style="font-size:.68rem;color:#64748b;"><?= sanitize($e['role_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                        title="Move to another department"
                        onclick="openMoveEmp(<?= $e['id'] ?>, '<?= addslashes(sanitize($e['name'])) ?>', <?= $d['id'] ?>)">
                        <i class="bi bi-arrow-left-right" style="font-size:.75rem;"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center py-4 text-muted small" style="<?= $d_tl ? '' : 'display:block;' ?>">
        <i class="bi bi-people me-1"></i>No employees assigned yet.
    </div>
    <?php endif; ?>

    <?php if (!$d_tl): ?></div><?php endif; ?>

</div>
<?php endforeach; ?>

<!-- Dashed Add Card -->
<?php if (!$departments): ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-building" style="font-size:3rem;opacity:.3;"></i>
    <div class="mt-3 fw-semibold">No departments yet</div>
    <button type="button" class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#addDeptModal">
        <i class="bi bi-plus-lg me-1"></i>Add First Department
    </button>
</div>
<?php else: ?>
<button type="button" class="dept-add-btn" data-bs-toggle="modal" data-bs-target="#addDeptModal">
    <i class="bi bi-plus-circle" style="font-size:1.3rem;"></i><span>Add Department</span>
</button>
<?php endif; ?>


<!-- ── ADD DEPT MODAL ────────────────────────────────────────── -->
<div class="modal fade" id="addDeptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <input type="hidden" name="action" value="save_dept">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h6 class="modal-title fw-bold">Add Department</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Marketing" required autofocus>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT DEPT MODAL ───────────────────────────────────────── -->
<div class="modal fade" id="editDeptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <input type="hidden" name="action" value="save_dept">
            <input type="hidden" name="id" id="edit-dept-id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h6 class="modal-title fw-bold">Edit Department</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit-dept-name" class="form-control" required>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Update</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SET TL MODAL ─────────────────────────────────────────── -->
<div class="modal fade" id="setTLModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action"  value="set_tl">
            <input type="hidden" name="dept_id" id="tl-dept-id">
            <input type="hidden" name="emp_id"  id="tl-selected-emp-id" value="0">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h6 class="modal-title fw-bold mb-0">Assign Team Lead</h6>
                    <p class="text-muted small mb-0" id="tl-dept-name-label"></p>
                </div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <!-- Search -->
                <input type="text" id="tl-search" class="form-control form-control-sm mb-3"
                    placeholder="Search employee…" oninput="filterTLCards()">

                <!-- Remove TL option -->
                <div class="tl-pick-card tl-remove-card mb-2" id="tl-card-0" onclick="selectTLCard(0)">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:38px;height:38px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-x-lg text-muted"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small text-muted">Remove Team Lead</div>
                            <div style="font-size:.7rem;color:#94a3b8;">No TL assigned for this department</div>
                        </div>
                    </div>
                </div>

                <!-- Employee cards -->
                <div id="tl-card-list" style="max-height:380px;overflow-y:auto;padding-right:2px;">
                </div>
                <div id="tl-no-results" class="text-center text-muted small py-3" style="display:none;">No employees found.</div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning btn-sm text-dark">
                    <i class="bi bi-star me-1"></i>Assign as TL
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── MOVE EMPLOYEE MODAL ──────────────────────────────────── -->
<div class="modal fade" id="moveEmpModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action"  value="move_emp">
            <input type="hidden" name="emp_id"  id="move-emp-id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h6 class="modal-title fw-bold mb-0">Move Employee</h6>
                    <p class="text-muted small mb-0" id="move-emp-name"></p>
                </div>
                <button type="button" class="btn-close ms-3" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <label class="form-label fw-semibold small">Move to Department</label>
                <select name="dept_id" id="move-dept-select" class="form-select">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Move</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDeptBody(id, btn) {
    var el   = document.getElementById(id);
    var icon = btn.querySelector('i');
    if (!el) return;
    var open = el.style.display !== 'none';
    el.style.display    = open ? 'none' : '';
    icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}

function openEditDept(id, name) {
    document.getElementById('edit-dept-id').value   = id;
    document.getElementById('edit-dept-name').value = name;
    new bootstrap.Modal(document.getElementById('editDeptModal')).show();
}

/* ── TL picker data ── */
var TL_DATA = <?= $tl_data_json ?>;
var TL_COLORS = ['#0f4c81','#047857','#7c3aed','#be185d','#0369a1','#b45309','#dc2626','#0891b2'];
var TL_ACTIVE_DEPT = 0;

function empColor(name) {
    var h = 0;
    for (var i=0; i<name.length; i++) h = (h*31 + name.charCodeAt(i)) | 0;
    return TL_COLORS[Math.abs(h) % TL_COLORS.length];
}

function renderTLCards(filterQ, currentEmpId) {
    var list  = document.getElementById('tl-card-list');
    var noRes = document.getElementById('tl-no-results');
    var q     = (filterQ||'').toLowerCase();
    var html  = '';
    var shown = 0;

    TL_DATA.forEach(function(emp) {
        // Only show employees in this department
        if (emp.dept_id != TL_ACTIVE_DEPT) return;
        if (q && emp.name.toLowerCase().indexOf(q) === -1 &&
                  (emp.designation||'').toLowerCase().indexOf(q) === -1) return;
        shown++;
        var color   = empColor(emp.name);
        var initial = emp.name.charAt(0).toUpperCase();
        var sel     = (emp.id == currentEmpId) ? 'selected' : '';

        html += '<div class="tl-pick-card '+sel+'" id="tl-card-'+emp.id+'" onclick="selectTLCard('+emp.id+')">';
        html += '<div class="d-flex align-items-center gap-3">';
        html += '<div class="tl-pick-avatar" style="background:linear-gradient(135deg,'+color+','+color+'cc);">'+initial+'</div>';
        html += '<div class="flex-grow-1">';
        html += '<div class="fw-semibold small">'+escHtml(emp.name)+'</div>';
        if (emp.designation) html += '<div style="font-size:.7rem;color:#64748b;">'+escHtml(emp.designation)+'</div>';
        html += '</div>';
        if (emp.id == currentEmpId) html += '<span style="color:#f59e0b;font-size:.75rem;"><i class="bi bi-star-fill"></i> Current TL</span>';
        html += '</div>';
        html += '</div>';
    });

    list.innerHTML = html;
    noRes.style.display = shown === 0 ? '' : 'none';
}

function selectTLCard(empId) {
    document.querySelectorAll('.tl-pick-card').forEach(function(c) { c.classList.remove('selected'); });
    var card = document.getElementById('tl-card-'+empId);
    if (card) card.classList.add('selected');
    document.getElementById('tl-selected-emp-id').value = empId;
}

function filterTLCards() {
    var q      = document.getElementById('tl-search').value;
    var selId  = document.getElementById('tl-selected-emp-id').value;
    renderTLCards(q, selId);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openSetTL(deptId, deptName, currentEmpId) {
    TL_ACTIVE_DEPT = deptId;
    document.getElementById('tl-dept-id').value              = deptId;
    document.getElementById('tl-dept-name-label').textContent = deptName;
    document.getElementById('tl-selected-emp-id').value      = currentEmpId || 0;
    document.getElementById('tl-search').value               = '';

    renderTLCards('', currentEmpId || 0);

    // Check if any employees exist in this dept
    var deptEmps = TL_DATA.filter(function(e) { return e.dept_id == deptId; });
    var noRes = document.getElementById('tl-no-results');
    if (deptEmps.length === 0) {
        noRes.textContent = 'No employees in this department yet.';
        noRes.style.display = '';
    }

    if (!currentEmpId) {
        var rc = document.getElementById('tl-card-0');
        if (rc) rc.classList.add('selected');
    }
    new bootstrap.Modal(document.getElementById('setTLModal')).show();
}

function openMoveEmp(empId, empName, currentDeptId) {
    document.getElementById('move-emp-id').value          = empId;
    document.getElementById('move-emp-name').textContent  = empName;
    document.getElementById('move-dept-select').value     = currentDeptId;
    new bootstrap.Modal(document.getElementById('moveEmpModal')).show();
}
</script>

<?php include 'footer.php'; ?>

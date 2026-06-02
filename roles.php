<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');

$page      = 'roles';
$pageTitle = 'Job Roles';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name      = trim($_POST['name'] ?? '');
        $slug      = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['slug'] ?? $name)));
        $can_be_tl = isset($_POST['can_be_tl']) ? 1 : 0;

        if (!$name) {
            $error = 'Role name is required.';
        } else {
            $exists = $conn->prepare('SELECT id FROM roles WHERE slug = ?');
            $exists->execute([$slug]);
            if ($exists->fetch()) {
                $error = "A role with slug \"$slug\" already exists.";
            } else {
                $conn->prepare('INSERT INTO roles (name, slug, can_be_tl) VALUES (?,?,?)')
                     ->execute([$name, $slug, $can_be_tl]);
                set_flash('success', "Role \"$name\" added.");
                header('Location: roles.php'); exit;
            }
        }

    } elseif ($action === 'toggle') {
        $id        = (int)($_POST['id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $conn->prepare('UPDATE roles SET is_active = ? WHERE id = ?')->execute([$is_active, $id]);
        set_flash('success', 'Role ' . ($is_active ? 'enabled' : 'disabled') . '.');
        header('Location: roles.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $in_use = $conn->prepare('SELECT COUNT(*) FROM employee_roles WHERE role_id = ?');
        $in_use->execute([$id]);
        if ((int)$in_use->fetchColumn() > 0) {
            set_flash('danger', 'Cannot delete — employees are assigned this role. Disable it instead.');
        } else {
            $conn->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            set_flash('success', 'Role deleted.');
        }
        header('Location: roles.php'); exit;

    } elseif ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $can_be_tl = isset($_POST['can_be_tl']) ? 1 : 0;
        if ($name) {
            $conn->prepare('UPDATE roles SET name = ?, can_be_tl = ? WHERE id = ?')
                 ->execute([$name, $can_be_tl, $id]);
            set_flash('success', 'Role updated.');
        }
        header('Location: roles.php'); exit;
    }
}

$roles = $conn->query('SELECT r.*, (SELECT COUNT(*) FROM employee_roles er WHERE er.role_id = r.id) AS emp_count FROM roles r ORDER BY r.id')->fetchAll();

include 'header.php';
?>

<style>
.role-card { background:#fff; border:1px solid #e9ecef; border-radius:14px; padding:18px 20px; transition:box-shadow .15s,border-color .15s; }
.role-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.07); border-color:#cbd5e1; }
.role-card.disabled-role { opacity:.6; background:#fafafa; }
.role-icon { width:42px; height:42px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.1rem; }
.role-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:16px; }
.role-slug { font-family:monospace; font-size:.72rem; background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:6px; }
.role-tl-badge { background:#eff6ff; color:#1d4ed8; font-size:.68rem; font-weight:600; padding:2px 8px; border-radius:20px; }
.role-emp-pill { background:#f0fdf4; color:#166534; font-size:.7rem; font-weight:600; padding:2px 9px; border-radius:20px; }
.role-emp-pill.zero { background:#f8fafc; color:#94a3b8; }
.role-status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted small mb-0">Manage job roles assigned to employees. Roles can be added, disabled, or deleted.</p>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRoleModal">
        <i class="bi bi-plus-lg me-1"></i>Add Role
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger small py-2 mb-3"><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <div style="font-size:1.8rem;font-weight:700;color:#0f4c81;"><?= count($roles) ?></div>
            <div class="text-muted" style="font-size:.73rem;">Total Roles</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $activeCount = count(array_filter($roles, fn($r) => $r['is_active'])); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#047857;"><?= $activeCount ?></div>
            <div class="text-muted" style="font-size:.73rem;">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $tlCount = count(array_filter($roles, fn($r) => $r['can_be_tl'])); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#7c3aed;"><?= $tlCount ?></div>
            <div class="text-muted" style="font-size:.73rem;">TL-Eligible</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 rounded-3 border text-center" style="background:#fff;">
            <?php $assignedTotal = array_sum(array_column($roles, 'emp_count')); ?>
            <div style="font-size:1.8rem;font-weight:700;color:#b45309;"><?= $assignedTotal ?></div>
            <div class="text-muted" style="font-size:.73rem;">Assignments</div>
        </div>
    </div>
</div>

<!-- Role Grid -->
<?php
$role_colors = ['#0f4c81','#047857','#7c3aed','#be185d','#0369a1','#b45309','#dc2626','#0891b2'];
?>
<div class="role-grid">
<?php foreach ($roles as $i => $r):
    $color = $role_colors[$i % count($role_colors)];
    $initial = strtoupper(substr($r['name'], 0, 1));
?>
<div class="role-card <?= !$r['is_active'] ? 'disabled-role' : '' ?>">
    <div class="d-flex align-items-start gap-3 mb-3">
        <div class="role-icon" style="background:<?= $color ?>1a;">
            <span style="font-weight:700;color:<?= $color ?>;"><?= $initial ?></span>
        </div>
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <span class="fw-semibold" style="font-size:.95rem;"><?= sanitize($r['name']) ?></span>
                <?php if (!$r['is_active']): ?>
                    <span class="badge bg-danger-subtle text-danger" style="font-size:.62rem;">Disabled</span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="role-slug"><?= sanitize($r['slug']) ?></span>
                <?php if ($r['can_be_tl']): ?>
                    <span class="role-tl-badge"><i class="bi bi-star-fill me-1" style="font-size:.6rem;"></i>TL Eligible</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <span class="role-emp-pill <?= $r['emp_count'] == 0 ? 'zero' : '' ?>">
            <i class="bi bi-people me-1"></i><?= (int)$r['emp_count'] ?> assigned
        </span>
        <span class="small text-muted d-flex align-items-center gap-1">
            <span class="role-status-dot" style="background:<?= $r['is_active'] ? '#22c55e' : '#ef4444' ?>;"></span>
            <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
        </span>
    </div>
    <div class="d-flex gap-2 pt-2 border-top">
        <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
            onclick="openEditRole(<?= $r['id'] ?>,'<?= addslashes(sanitize($r['name'])) ?>',<?= $r['can_be_tl'] ?>)">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
        <!-- Toggle -->
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $r['is_active'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $r['is_active'] ? 'warning' : 'success' ?>"
                title="<?= $r['is_active'] ? 'Disable role' : 'Enable role' ?>">
                <i class="bi bi-<?= $r['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
            </button>
        </form>
        <!-- Delete -->
        <?php if ((int)$r['emp_count'] === 0): ?>
        <form method="POST" class="d-inline"
            onsubmit="return hConfirmSync(event,'Delete role \'<?= sanitize($r['name']) ?>\'?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                <i class="bi bi-trash"></i>
            </button>
        </form>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Assigned to employees — disable instead">
            <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$roles): ?>
<div class="text-center py-5 text-muted" style="grid-column:1/-1;">
    <i class="bi bi-briefcase" style="font-size:3rem;opacity:.3;"></i>
    <div class="mt-3 fw-semibold">No roles yet</div>
    <div class="small mt-1">Add your first job role to start assigning employees.</div>
    <button type="button" class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#addRoleModal">
        <i class="bi bi-plus-lg me-1"></i>Add First Role
    </button>
</div>
<?php endif; ?>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="add">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h6 class="modal-title fw-bold">Add Job Role</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Role Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="add-role-name" class="form-control" placeholder="e.g. Growth Marketer" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Slug</label>
                    <input type="text" name="slug" id="add-role-slug" class="form-control" placeholder="auto-filled">
                    <div class="form-text">Auto-generated. Lowercase letters, numbers, underscores only.</div>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="can_be_tl" id="add-can-tl" checked>
                    <label class="form-check-label small" for="add-can-tl">Can be Team Lead</label>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-role-id">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h6 class="modal-title fw-bold">Edit Role</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Role Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="edit-role-name" class="form-control" required>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="can_be_tl" id="edit-can-tl">
                    <label class="form-check-label small" for="edit-can-tl">Can be Team Lead</label>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('add-role-name').addEventListener('input', function() {
    document.getElementById('add-role-slug').value =
        this.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
});

function openEditRole(id, name, canTl) {
    document.getElementById('edit-role-id').value   = id;
    document.getElementById('edit-role-name').value = name;
    document.getElementById('edit-can-tl').checked  = canTl == 1;
    new bootstrap.Modal(document.getElementById('editRoleModal')).show();
}
</script>

<?php include 'footer.php'; ?>

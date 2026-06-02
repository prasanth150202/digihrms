<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER', 'TEAM_LEAD');
$page      = 'projects';
$pageTitle = 'Projects';


// ── POST: add / edit / delete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['ACTIVE','INACTIVE']) ? $_POST['status'] : 'ACTIVE';

        if (!$name) { set_flash('danger', 'Project name is required.'); header("Location: projects.php"); exit; }

        if ($id) {
            $conn->prepare("UPDATE projects SET name=?, description=?, status=? WHERE id=?")
                 ->execute([$name, $description ?: null, $status, $id]);
            set_flash('success', 'Project updated.');
        } else {
            $chk = $conn->prepare("SELECT id FROM projects WHERE name=?");
            $chk->execute([$name]);
            if ($chk->fetch()) { set_flash('danger', 'A project with this name already exists.'); header("Location: projects.php"); exit; }
            $conn->prepare("INSERT INTO projects (name, description, status) VALUES (?,?,?)")
                 ->execute([$name, $description ?: null, $status]);
            set_flash('success', 'Project created.');
        }
        header("Location: projects.php"); exit;
    }

    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // check tasks linked
        $cnt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND deleted_at IS NULL");
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) {
            set_flash('danger', 'Cannot delete: project has active tasks linked to it.');
        } else {
            $conn->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
            set_flash('success', 'Project deleted.');
        }
        header("Location: projects.php"); exit;
    }

}

// ── Fetch projects with task counts ──────────────────────────────────
$projects = $conn->query("
    SELECT p.*,
           COUNT(t.id) AS task_count,
           SUM(CASE WHEN t.status='DONE' THEN 1 ELSE 0 END) AS done_count
    FROM projects p
    LEFT JOIN tasks t ON t.project_id = p.id AND t.deleted_at IS NULL
    GROUP BY p.id
    ORDER BY p.status DESC, p.name ASC
")->fetchAll();

$active_count   = count(array_filter($projects, fn($p) => $p['status'] === 'ACTIVE'));
$inactive_count = count(array_filter($projects, fn($p) => $p['status'] === 'INACTIVE'));

include 'header.php';
?>

<style>
/* ── Project page ──────────────────────────────────────────────── */
.proj-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}
.proj-card {
    background: var(--card-bg);
    border: 1px solid var(--card-bdr);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow .15s, transform .15s;
}
.proj-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.08); transform: translateY(-1px); }
.proj-card-top {
    padding: 18px 20px 14px;
    flex: 1;
}
.proj-card-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--card-bdr);
    background: var(--body-bg);
    display: flex;
    gap: 8px;
    align-items: center;
}
.proj-avatar {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1rem; flex-shrink: 0;
}
.proj-name {
    font-size: .9rem; font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
}
.proj-desc {
    font-size: .72rem; color: var(--text-muted);
    margin-top: 2px;
    display: -webkit-box; -webkit-line-clamp: 1;
    -webkit-box-orient: vertical; overflow: hidden;
}
.proj-digiops {
    font-size: .68rem; color: var(--text-muted);
    margin-top: 3px; font-family: monospace;
    display: flex; align-items: center; gap: 4px;
}
.proj-digiops-id {
    max-width: 160px; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap;
    display: inline-block; vertical-align: middle;
}
.proj-badge {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 20px;
    font-size: .65rem; font-weight: 700;
    white-space: nowrap; flex-shrink: 0;
}
.proj-badge-active   { background: #dcfce7; color: #166534; }
.proj-badge-inactive { background: #f1f5f9; color: #64748b; }
.proj-progress-label {
    font-size: .72rem; color: var(--text-muted);
    display: flex; justify-content: space-between;
    margin-bottom: 5px;
}
.proj-bar {
    height: 6px; background: var(--card-bdr);
    border-radius: 10px; overflow: hidden;
}
.proj-bar-fill { height: 100%; border-radius: 10px; transition: width .3s; }

@media (max-width: 768px) {
    .proj-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted small mb-0">Create and manage projects to organise tasks.</p>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#projModal">
        <i class="bi bi-plus-lg me-1"></i>Add Project
    </button>
</div>

<!-- Stat cards — using HRMS design system -->
<div class="hrms-stat-grid hrms-stat-grid-4" style="margin-bottom:24px;">
    <div class="hrms-stat-card c-blue">
        <div class="hrms-stat-icon"><i class="bi bi-folder2-open"></i></div>
        <div class="hrms-stat-label">Total Projects</div>
        <div class="hrms-stat-value"><?= count($projects) ?></div>
    </div>
    <div class="hrms-stat-card c-green">
        <div class="hrms-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
        <div class="hrms-stat-label">Active</div>
        <div class="hrms-stat-value"><?= $active_count ?></div>
    </div>
    <div class="hrms-stat-card c-amber">
        <div class="hrms-stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
        <div class="hrms-stat-label">Inactive</div>
        <div class="hrms-stat-value"><?= $inactive_count ?></div>
    </div>
</div>

<?php if (!$projects): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body">
        <i class="bi bi-folder2-open" style="font-size:3rem;color:#cbd5e1;"></i>
        <div class="fw-semibold mt-3" style="color:var(--text-primary);">No projects yet</div>
        <div class="small mt-1 text-muted">Add your first project to get started.</div>
    </div>
</div>
<?php else: ?>

<!-- Search -->
<div class="mb-3">
    <div class="position-relative" style="max-width:300px;">
        <i class="bi bi-search position-absolute" style="left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.8rem;"></i>
        <input type="text" id="projSearch" class="form-control form-control-sm ps-4"
               placeholder="Search projects…" oninput="filterProjects()">
    </div>
</div>

<div class="proj-grid" id="projGrid">
<?php foreach ($projects as $p):
    $task_count = (int)$p['task_count'];
    $done_count = (int)$p['done_count'];
    $pct        = $task_count > 0 ? round(($done_count / $task_count) * 100) : 0;
    $colors     = ['#3b82f6','#047857','#7c3aed','#be185d','#b45309','#0369a1','#dc2626','#0891b2'];
    $color      = $colors[abs(crc32($p['name'])) % count($colors)];
    $initial    = strtoupper(substr($p['name'], 0, 1));
    $has_digiops = !empty($p['digiops_brand_id']);
?>
<div class="proj-card proj-item" data-name="<?= strtolower(sanitize($p['name'])) ?>">

    <!-- Top section -->
    <div class="proj-card-top">
        <!-- Header row: avatar + name + badge -->
        <div class="d-flex align-items-start gap-3 mb-3">
            <div class="proj-avatar" style="background:<?= $color ?>18;color:<?= $color ?>;">
                <?= $initial ?>
            </div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="proj-name"><?= sanitize($p['name']) ?></div>
                <?php if ($p['description']): ?>
                <div class="proj-desc"><?= sanitize($p['description']) ?></div>
                <?php endif; ?>
                <?php if ($has_digiops): ?>
                <div class="proj-digiops">
                    <i class="bi bi-link-45deg"></i>
                    <span class="proj-digiops-id" title="DigiOps brand: <?= sanitize($p['digiops_brand_id']) ?>">
                        DigiOps: <?= sanitize(substr($p['digiops_brand_id'], 0, 8)) ?>…
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <span class="proj-badge proj-badge-<?= strtolower($p['status']) ?>"><?= $p['status'] ?></span>
        </div>

        <!-- Progress -->
        <div class="proj-progress-label">
            <span><?= $done_count ?>/<?= $task_count ?> tasks done</span>
            <span style="font-weight:600;color:<?= $pct >= 100 ? '#166534' : 'var(--text-muted)' ?>"><?= $pct ?>%</span>
        </div>
        <div class="proj-bar">
            <div class="proj-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
        </div>
    </div>

    <!-- Footer actions -->
    <div class="proj-card-footer">
        <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
            onclick="openEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
        <a href="tasks.php?project=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
            <i class="bi bi-list-task me-1"></i>Tasks
        </a>
        <button type="button" class="btn btn-sm btn-outline-danger"
            onclick="deleteProject(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>')">
            <i class="bi bi-trash3"></i>
        </button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── ADD / EDIT MODAL ───────────────────────────────────────── -->
<div class="modal fade" id="projModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="proj-id" value="0">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h6 class="modal-title fw-bold" id="proj-modal-title">Add Project</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Project Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="proj-name" class="form-control" required placeholder="e.g. Website Redesign">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" id="proj-description" class="form-control" rows="2"
                                  placeholder="Optional short description…"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" id="proj-status" class="form-select">
                            <option value="ACTIVE">Active</option>
                            <option value="INACTIVE">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" id="proj-submit-btn">
                    <i class="bi bi-plus-lg me-1"></i>Create Project
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete form (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete-proj-id">
</form>

<script>
function openEdit(p) {
    document.getElementById('proj-id').value          = p.id;
    document.getElementById('proj-name').value        = p.name;
    document.getElementById('proj-description').value = p.description || '';
    document.getElementById('proj-status').value      = p.status || 'ACTIVE';
    document.getElementById('proj-modal-title').textContent = 'Edit Project';
    document.getElementById('proj-submit-btn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
    new bootstrap.Modal(document.getElementById('projModal')).show();
}

document.getElementById('projModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('proj-id').value = '0';
    document.getElementById('proj-name').value = '';
    document.getElementById('proj-description').value = '';
    document.getElementById('proj-status').value = 'ACTIVE';
    document.getElementById('proj-modal-title').textContent = 'Add Project';
    document.getElementById('proj-submit-btn').innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create Project';
});

async function deleteProject(id, name) {
    if (!await hConfirm('Delete project "' + name + '"? This will only work if no tasks are linked to it.')) return;
    document.getElementById('delete-proj-id').value = id;
    document.getElementById('deleteForm').submit();
}

function filterProjects() {
    var q = document.getElementById('projSearch').value.toLowerCase();
    document.querySelectorAll('.proj-item').forEach(function(c) {
        c.style.display = (!q || c.dataset.name.includes(q)) ? '' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>

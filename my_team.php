<?php
require_once 'config.php';
require_login();

$page      = 'my_team';
$pageTitle = 'My Team';
$u         = current_user();
$uid       = $u['id'];

if (!has_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER', 'TEAM_LEAD')) {
    header('Location: dashboard.php'); exit;
}

// Find current user's employee record + dept
$me = $conn->prepare("
    SELECT e.id AS emp_id, er.dept_id, er.is_team_lead
    FROM employees e
    LEFT JOIN employee_roles er ON er.employee_id = e.id
    LEFT JOIN users u ON u.email = e.email
    WHERE u.id = ? LIMIT 1
");
$me->execute([$uid]);
$me_row = $me->fetch();

// Super Admin / Dept Manager sees all; TL sees own dept only
if (has_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER')) {
    $q = $conn->prepare("
        SELECT e.id, e.name, e.email, e.phone, e.photo, e.status,
               d.name AS dept_name, r.name AS role_name,
               er.is_team_lead,
               u.id AS user_id
        FROM employees e
        LEFT JOIN departments d ON d.id = e.dept_id
        LEFT JOIN employee_roles er ON er.employee_id = e.id
        LEFT JOIN roles r ON r.id = er.role_id
        LEFT JOIN users u ON u.email = e.email
        WHERE e.status IN ('active','ACTIVE','PROBATION')
        ORDER BY d.name, e.name
    ");
    $q->execute();
    $members = $q->fetchAll();
} else {
    $dept_id = (int)($me_row['dept_id'] ?? 0);
    $q = $conn->prepare("
        SELECT e.id, e.name, e.email, e.phone, e.photo, e.status,
               d.name AS dept_name, r.name AS role_name,
               er.is_team_lead,
               u.id AS user_id
        FROM employees e
        LEFT JOIN departments d ON d.id = e.dept_id
        LEFT JOIN employee_roles er ON er.employee_id = e.id
        LEFT JOIN roles r ON r.id = er.role_id
        LEFT JOIN users u ON u.email = e.email
        WHERE e.status IN ('ACTIVE','PROBATION') AND (er.dept_id = ? OR e.dept_id = ?)
        ORDER BY e.name
    ");
    $q->execute([$dept_id, $dept_id]);
    $members = $q->fetchAll();
}

// Published workflows
$workflows = $conn->query("
    SELECT id, name FROM hrms_workflow_templates
    WHERE is_active=1 AND published_version>0
    ORDER BY name
")->fetchAll();

include 'header.php';
?>

<style>
.team-card {
    background: var(--card-bg);
    border: 1px solid var(--card-bdr);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: box-shadow .15s;
}
.team-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.team-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    background: var(--primary); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700;
}
.team-avatar img { width:100%;height:100%;border-radius:50%;object-fit:cover; }
.tl-badge {
    display:inline-flex;align-items:center;gap:3px;
    padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;
    background:#eff6ff;color:#2563eb;
}
</style>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="mb-0 fw-bold" style="color:var(--text-primary);">My Team</h5>
        <div style="font-size:12px;color:var(--text-muted);"><?= count($members) ?> member<?= count($members) !== 1 ? 's' : '' ?></div>
    </div>
    <div style="position:relative;max-width:240px;">
        <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
        <input type="text" id="team-search" placeholder="Search members..." oninput="filterTeam()"
            style="width:100%;padding:7px 10px 7px 32px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
    </div>
</div>

<?php if (!$members): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted);">
    <i class="bi bi-people" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3;"></i>
    No team members found.
</div>
<?php else: ?>

<?php
// Group by department
$grouped = [];
foreach ($members as $m) {
    $grouped[$m['dept_name'] ?: 'No Department'][] = $m;
}
?>

<?php foreach ($grouped as $dept => $people): ?>
<div class="mb-4 team-dept-group">
    <?php if (has_role('SUPER_ADMIN','DEPT_MANAGER')): ?>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:10px;">
        <i class="bi bi-building me-1"></i><?= htmlspecialchars($dept) ?> &middot; <?= count($people) ?> member<?= count($people)!==1?'s':'' ?>
    </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;">
        <?php foreach ($people as $m): ?>
        <?php
            $initials = implode('', array_map(fn($w)=>strtoupper($w[0]), array_slice(explode(' ', trim($m['name'])), 0, 2)));
            $is_self  = ((int)($m['user_id'] ?? 0) === $uid);
        ?>
        <div class="team-card team-member-card"
             data-name="<?= strtolower(htmlspecialchars($m['name'])) ?>"
             data-dept="<?= strtolower(htmlspecialchars($dept)) ?>">

            <!-- Avatar -->
            <div class="team-avatar">
                <?php if ($m['photo']): ?>
                <img src="<?= htmlspecialchars($m['photo']) ?>" alt="">
                <?php else: ?>
                <?= $initials ?>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <a href="employee_profile.php?id=<?= $m['id'] ?>"
                       style="font-weight:700;font-size:14px;color:var(--text-primary);text-decoration:none;">
                        <?= htmlspecialchars($m['name']) ?>
                    </a>
                    <?php if ($m['is_team_lead']): ?>
                    <span class="tl-badge"><i class="bi bi-shield-check"></i> TL</span>
                    <?php endif; ?>
                    <?php if ($is_self): ?>
                    <span style="font-size:10px;color:var(--text-muted);font-style:italic;">you</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <?= htmlspecialchars($m['role_name'] ?: '—') ?>
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                <a href="employee_profile.php?id=<?= $m['id'] ?>"
                   class="btn btn-sm btn-outline-secondary" style="font-size:11px;border-radius:7px;">
                    <i class="bi bi-person"></i> Profile
                </a>
                <?php if (!$is_self && $workflows): ?>
                <button class="btn btn-sm btn-primary" style="font-size:11px;border-radius:7px;background:var(--primary);border:none;"
                    onclick="openRunWorkflow(<?= $m['user_id'] ?? 0 ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>')">
                    <i class="bi bi-lightning-fill"></i> Run Workflow
                </button>
                <?php elseif (!$is_self && !$workflows): ?>
                <button class="btn btn-sm btn-outline-secondary" disabled style="font-size:11px;border-radius:7px;" title="No published workflows">
                    <i class="bi bi-lightning"></i> No Workflows
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Run Workflow Modal -->
<div id="run-wf-modal" style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;">
    <div onclick="closeRunWorkflow()" style="position:absolute;inset:0;background:rgba(0,0,0,.45);"></div>
    <div style="position:relative;background:var(--card-bg);border-radius:14px;padding:28px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="font-size:17px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">
            <i class="bi bi-lightning-fill me-2" style="color:var(--primary);"></i>Run Workflow
        </div>
        <div id="run-wf-person-name" style="font-size:13px;color:var(--text-muted);margin-bottom:18px;"></div>

        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);display:block;margin-bottom:6px;">Select Workflow</label>
        <select id="run-wf-select" style="width:100%;padding:9px 12px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);margin-bottom:6px;">
            <option value="">— Choose a workflow —</option>
            <?php foreach ($workflows as $wf): ?>
            <option value="<?= $wf['id'] ?>"><?= htmlspecialchars($wf['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:18px;">
            Tasks inside the workflow will be assigned based on dynamic assignee rules (Target Person, Their TL, etc.)
        </div>

        <div id="run-wf-result" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>

        <div style="display:flex;gap:10px;">
            <button onclick="closeRunWorkflow()" class="btn btn-outline-secondary" style="flex:1;border-radius:8px;font-weight:600;">Cancel</button>
            <button onclick="doRunWorkflow()" class="btn btn-primary" id="run-wf-btn" style="flex:1;border-radius:8px;font-weight:600;background:var(--primary);border:none;">
                <i class="bi bi-lightning-fill me-1"></i> Fire
            </button>
        </div>
    </div>
</div>

<script>
let _runTargetId = 0;

function filterTeam() {
    const q = document.getElementById('team-search').value.toLowerCase();
    document.querySelectorAll('.team-member-card').forEach(c => {
        const match = c.dataset.name.includes(q) || c.dataset.dept.includes(q);
        c.style.display = match ? '' : 'none';
    });
    // Hide dept headers if all members hidden
    document.querySelectorAll('.team-dept-group').forEach(g => {
        const visible = [...g.querySelectorAll('.team-member-card')].some(c => c.style.display !== 'none');
        g.style.display = visible ? '' : 'none';
    });
}

function openRunWorkflow(userId, name) {
    if (!userId) { showAlert('This person does not have a user account yet.', 'warning', 'No Account'); return; }
    _runTargetId = userId;
    document.getElementById('run-wf-person-name').textContent = 'For: ' + name;
    document.getElementById('run-wf-select').value = '';
    document.getElementById('run-wf-result').style.display = 'none';
    document.getElementById('run-wf-modal').style.display = 'flex';
}

function closeRunWorkflow() {
    document.getElementById('run-wf-modal').style.display = 'none';
}

async function doRunWorkflow() {
    const wf_id = document.getElementById('run-wf-select').value;
    if (!wf_id) { showToast('Please select a workflow first.', 'warning'); return; }

    const btn = document.getElementById('run-wf-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Firing...';

    const res  = await fetch('triggers.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'run_on_person', workflow_id: parseInt(wf_id), target_user_id: _runTargetId })
    });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-lightning-fill me-1"></i> Fire';

    if (data.ok) {
        closeRunWorkflow();
        showToast('Workflow fired — tasks have been created.', 'success', 'Done!');
    } else {
        const result = document.getElementById('run-wf-result');
        result.style.display = '';
        result.style.cssText += ';background:#fee2e2;color:#dc2626;';
        result.textContent = data.msg || 'Failed to fire workflow.';
    }
}
</script>

<?php include 'footer.php'; ?>

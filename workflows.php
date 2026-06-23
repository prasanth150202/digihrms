<?php
require_once 'config.php';
require_login();
$page      = 'workflows';
$pageTitle = 'Workflow Builder';
$u         = current_user();
$uid       = $u['id'];
$role      = $u['role'];

// ── Permission: who can access this page ─────────────────────────────────
$can_manage = has_role('SUPER_ADMIN', 'DEPT_MANAGER');
$is_tl      = has_role('TEAM_LEAD');
$is_emp     = has_role('EMPLOYEE');
// All logged-in users can access (employees see only their own)

// ── AJAX / POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $b['action'] ?? ($_POST['action'] ?? '');

    // ── save_draft ────────────────────────────────────────────────────────
    if ($action === 'save_draft') {
        $id     = (int)($b['id'] ?? 0);
        $layout = $b['layout'] ?? '';
        if (!$id || !$layout) { echo json_encode(['ok'=>false,'msg'=>'Missing id or layout']); exit; }

        // Verify ownership / permission
        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$id]);
        $wf = $wf->fetch();
        if (!$wf) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
        if (!_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }

        $new_ver = (int)$wf['current_version'] + 1;
        $conn->prepare("UPDATE hrms_workflow_templates SET draft_layout=?, current_version=?, has_unpublished_changes=1, updated_at=NOW() WHERE id=?")
             ->execute([$layout, $new_ver, $id]);

        // Save version snapshot
        $conn->prepare("INSERT INTO hrms_workflow_versions (template_id, version_number, layout, saved_by) VALUES (?,?,?,?)")
             ->execute([$id, $new_ver, $layout, $uid]);

        echo json_encode(['ok'=>true,'version'=>$new_ver]);
        exit;
    }

    // ── publish ───────────────────────────────────────────────────────────
    if ($action === 'publish') {
        $id = (int)($b['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$id]);
        $wf = $wf->fetch();
        if (!$wf) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
        if (!_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }

        $pub_ver = (int)$wf['current_version'];
        $conn->prepare("UPDATE hrms_workflow_templates SET published_layout=draft_layout, published_version=?, has_unpublished_changes=0, updated_at=NOW() WHERE id=?")
             ->execute([$pub_ver, $id]);

        echo json_encode(['ok'=>true,'published_version'=>$pub_ver]);
        exit;
    }

    // ── create ────────────────────────────────────────────────────────────
    if ($action === 'create') {
        $name       = trim($b['name'] ?? '');
        $desc       = trim($b['description'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Name required']); exit; }

        $conn->prepare("INSERT INTO hrms_workflow_templates (name, description, created_by, draft_layout, has_unpublished_changes)
                        VALUES (?,?,?,?,1)")
             ->execute([$name, $desc, $uid, json_encode(['nodes'=>[],'edges'=>[]])]);
        $new_id = (int)$conn->lastInsertId();
        echo json_encode(['ok'=>true,'id'=>$new_id]);
        exit;
    }

    // ── toggle_active ─────────────────────────────────────────────────────
    if ($action === 'toggle_active') {
        $id     = (int)($b['id'] ?? 0);
        $active = (int)(!empty($b['active']));
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }
        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$id]);
        $wf = $wf->fetch();
        if (!$wf || !_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        $conn->prepare("UPDATE hrms_workflow_templates SET is_active=? WHERE id=?")->execute([$active, $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── share (Super Admin only) ──────────────────────────────────────────
    if ($action === 'share') {
        if ($role !== 'SUPER_ADMIN') { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        $id     = (int)($b['id'] ?? 0);
        $shared = !empty($b['shared']) ? 'global' : 'personal';
        $conn->prepare("UPDATE hrms_workflow_templates SET scope_type=? WHERE id=?")->execute([$shared, $id]);
        echo json_encode(['ok'=>true,'scope_type'=>$shared]); exit;
    }

    // ── delete ────────────────────────────────────────────────────────────
    if ($action === 'rename') {
        $id   = (int)($b['id'] ?? 0);
        $name = trim($b['name'] ?? '');
        if (!$id || !$name) { echo json_encode(['ok'=>false,'msg'=>'Missing id or name']); exit; }
        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$id]);
        $wf = $wf->fetch();
        if (!$wf || !_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        $conn->prepare("UPDATE hrms_workflow_templates SET name=? WHERE id=?")->execute([$name, $id]);
        echo json_encode(['ok'=>true,'name'=>$name]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($b['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }
        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$id]);
        $wf = $wf->fetch();
        if (!$wf || !_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
        $conn->prepare("UPDATE hrms_workflow_templates SET is_active=0 WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── restore_version ───────────────────────────────────────────────────
    if ($action === 'restore_version') {
        $wf_id  = (int)($b['template_id'] ?? 0);
        $ver_id = (int)($b['version_id'] ?? 0);
        if (!$wf_id || !$ver_id) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }

        $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? LIMIT 1");
        $wf->execute([$wf_id]);
        $wf = $wf->fetch();
        if (!$wf || !_can_edit($wf, $uid, $role)) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }

        $ver = $conn->prepare("SELECT * FROM hrms_workflow_versions WHERE id=? AND template_id=? LIMIT 1");
        $ver->execute([$ver_id, $wf_id]);
        $ver = $ver->fetch();
        if (!$ver) { echo json_encode(['ok'=>false,'msg'=>'Version not found']); exit; }

        $new_ver = (int)$wf['current_version'] + 1;
        $conn->prepare("UPDATE hrms_workflow_templates SET draft_layout=?, current_version=?, has_unpublished_changes=1 WHERE id=?")
             ->execute([$ver['layout'], $new_ver, $wf_id]);
        $conn->prepare("INSERT INTO hrms_workflow_versions (template_id, version_number, layout, saved_by, label) VALUES (?,?,?,?,?)")
             ->execute([$wf_id, $new_ver, $ver['layout'], $uid, 'Restored from v'.$ver['version_number']]);

        echo json_encode(['ok'=>true,'version'=>$new_ver,'layout'=>$ver['layout']]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ── GET: load a specific workflow ─────────────────────────────────────────
if (isset($_GET['load'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['load'];
    $wf = $conn->prepare("SELECT * FROM hrms_workflow_templates WHERE id=? AND is_active=1 LIMIT 1");
    $wf->execute([$id]);
    $wf = $wf->fetch();
    if (!$wf) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'workflow'=>$wf]);
    exit;
}

// ── GET: version history ──────────────────────────────────────────────────
if (isset($_GET['versions'])) {
    header('Content-Type: application/json');
    $id   = (int)$_GET['versions'];
    $vers = $conn->prepare("SELECT v.*, u.name as saved_by_name FROM hrms_workflow_versions v JOIN users u ON u.id=v.saved_by WHERE v.template_id=? ORDER BY v.version_number DESC LIMIT 30");
    $vers->execute([$id]);
    echo json_encode(['ok'=>true,'versions'=>$vers->fetchAll()]);
    exit;
}

// ── Fetch all workflows visible to this user ──────────────────────────────
function _can_edit($wf, $uid, $role): bool {
    if (in_array($role, ['SUPER_ADMIN'])) return true;
    if ($wf['created_by'] == $uid) return true;
    return false;
}

function _can_view($wf, $uid, $role): bool {
    if ($role === 'SUPER_ADMIN') return true;
    if ($wf['created_by'] == $uid) return true;
    if ($wf['scope_type'] === 'global') return true; // shared by Super Admin
    return false;
}

if ($role === 'SUPER_ADMIN') {
    // Super Admin sees everything
    $wf_list = $conn->query("SELECT t.*, u.name as creator_name
        FROM hrms_workflow_templates t
        JOIN users u ON u.id = t.created_by
        WHERE t.is_active=1
        ORDER BY t.updated_at DESC")->fetchAll();
} else {
    // TL / Manager / Employee — own workflows + globally shared ones
    $wf_list = $conn->prepare("SELECT t.*, u.name as creator_name
        FROM hrms_workflow_templates t
        JOIN users u ON u.id = t.created_by
        WHERE t.is_active=1 AND (t.created_by=? OR t.scope_type='global')
        ORDER BY t.updated_at DESC");
    $wf_list->execute([$uid]);
    $wf_list = $wf_list->fetchAll();
}

// Fetch users for assignee dropdown
$all_users = $conn->query("
    SELECT u.id, u.name, u.role,
           COALESCE(r.name, '') AS designation
    FROM users u
    LEFT JOIN employees e ON e.email = u.email
    LEFT JOIN employee_roles er ON er.employee_id = e.id
    LEFT JOIN roles r ON r.id = er.role_id
    WHERE u.role NOT IN ('HR_ADMIN')
    GROUP BY u.id
    ORDER BY u.name
")->fetchAll();

include 'header.php';
?>

<style>
/* ── Workflow List ──────────────────────────────────────────────────────── */
.wf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.wf-card {
    background: var(--card-bg); border: 1px solid var(--card-bdr);
    border-radius: var(--r); padding: 18px; cursor: pointer;
    transition: border-color .15s, box-shadow .15s; position: relative;
}
.wf-card:hover { border-color: var(--primary); box-shadow: 0 4px 16px rgba(59,130,246,.12); }
.wf-card.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(59,130,246,.2); }
.wf-card-name { font-weight: 700; font-size: 15px; color: var(--text-primary); margin-bottom: 4px; }
.wf-card-meta { font-size: 12px; color: var(--text-muted); }
.wf-unpublished {
    position: absolute; top: 12px; right: 12px;
    width: 10px; height: 10px; border-radius: 50%;
    background: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245,158,11,.2);
    title: "Unpublished changes";
}
.wf-published-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #dcfce7; color: #166534; }
.wf-draft-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #fef3c7; color: #92400e; }

/* ── Canvas area ───────────────────────────────────────────────────────── */
#canvas-area {
    display: none; position: fixed; inset: 0; z-index: 1050;
    background: var(--body-bg);
    flex-direction: column;
}
#canvas-area.open { display: flex; }
#canvas-topbar {
    background: var(--card-bg); border-bottom: 1px solid var(--card-bdr);
    padding: 10px 18px; display: flex; align-items: center; gap: 12px;
    flex-shrink: 0; z-index: 10;
}
#canvas-topbar .wf-title { font-weight: 700; font-size: 15px; flex: 1; }
#canvas-wrap { flex: 1; display: flex; overflow: hidden; }
#node-panel {
    width: 220px; flex-shrink: 0;
    background: var(--card-bg); border-right: 1px solid var(--card-bdr);
    padding: 14px 10px; overflow-y: auto;
}
#node-panel h6 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--text-muted); margin: 14px 0 6px; }
#node-panel h6:first-child { margin-top: 0; }
.drag-node {
    background: var(--body-bg); border: 1.5px solid var(--card-bdr);
    border-radius: 8px; padding: 9px 12px; margin-bottom: 7px;
    cursor: grab; font-size: 13px; font-weight: 600; color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
    transition: border-color .12s, box-shadow .12s;
    user-select: none;
}
.drag-node:hover { border-color: var(--primary); box-shadow: 0 2px 8px rgba(59,130,246,.15); }
.drag-node i { font-size: 1rem; }
#drawflow-canvas { flex: 1; position: relative; overflow: hidden; }
#drawflow { width: 100%; height: 100%; }

/* ── Node styles inside Drawflow ───────────────────────────────────────── */
.drawflow-node {
    background: var(--card-bg) !important;
    border: 2px solid var(--card-bdr) !important;
    border-radius: 10px !important;
    width: 280px !important;
    overflow: visible !important;
}
.drawflow-node.selected { border-color: var(--primary) !important; box-shadow: 0 0 0 3px rgba(59,130,246,.2) !important; }
.drawflow-node .drawflow_content_node { padding: 0; overflow: visible !important; }

/* Scroll lives on node-inner, not on the drawflow-node wrapper (keeps dots visible) */
.node-inner {
    max-height: 460px;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 12px 10px;
    box-sizing: border-box;
    width: 100%;
    border-radius: 8px;
}
.node-inner::-webkit-scrollbar { width: 4px; }
.node-inner::-webkit-scrollbar-thumb { background: var(--card-bdr); border-radius: 4px; }

.node-task      .drawflow-node { border-color: #3b82f6 !important; }
.node-condition .drawflow-node { border-color: #f59e0b !important; }
.node-task.is-start .drawflow-node { border-color: #16a34a !important; box-shadow: 0 0 0 3px rgba(22,163,74,.15) !important; }

/* Play button on node hover */
.node-play-btn {
    position: absolute; top: -13px; left: 50%; transform: translateX(-50%);
    width: 24px; height: 24px; border-radius: 50%;
    background: #16a34a; color: #fff; border: 2px solid #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; cursor: pointer;
    opacity: 0; transition: opacity .15s;
    box-shadow: 0 2px 8px rgba(22,163,74,.4);
    z-index: 10; pointer-events: none;
}
.drawflow-node:hover .node-play-btn { opacity: 1; pointer-events: all; }
.is-start .node-play-btn { opacity: 1; pointer-events: all; }

/* Node inner layout — defined above with scroll */
.node-header {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 10px; padding-bottom: 8px;
    border-bottom: 1px solid var(--card-bdr);
}
.node-header i { font-size: 1rem; flex-shrink: 0; }
.node-header .node-label { font-size: 13px; font-weight: 700; flex: 1; }
.node-del-btn {
    margin-left: auto; background: none; border: none;
    color: #ef4444; cursor: pointer; font-size: 13px;
    padding: 2px 4px; line-height: 1; border-radius: 4px;
    opacity: 0; transition: opacity .15s;
}
.drawflow-node:hover .node-del-btn { opacity: 1; }
.node-task      .node-header { color: #2563eb; }
.node-condition .node-header { color: #d97706; }

/* Task list */
.task-list { display: flex; flex-direction: column; gap: 8px; width: 100%; }
.task-item {
    background: var(--body-bg); border: 1px solid var(--card-bdr);
    border-radius: 7px; padding: 8px 10px 8px 10px;
    position: relative; box-sizing: border-box; width: 100%;
}
.task-item input,
.task-item select,
.task-item textarea {
    display: block; width: 100%; box-sizing: border-box;
    background: var(--card-bg); border: 1px solid var(--card-bdr);
    border-radius: 5px; padding: 5px 8px; font-size: 12px;
    color: var(--text-primary); font-family: var(--font); margin-top: 3px;
}
.task-item textarea { resize: vertical; min-height: 44px; }
.task-item label {
    display: block; font-size: 10px; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px;
    margin-top: 6px; margin-bottom: 0;
}
.task-item label:first-child { margin-top: 0; }
.task-item .remove-task {
    position: absolute; top: 6px; right: 6px;
    background: none; border: none; color: #ef4444;
    cursor: pointer; font-size: 13px; padding: 0; line-height: 1;
}
.task-item .priority-due-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 0;
}
.task-item .approval-row {
    display: flex; align-items: center; gap: 6px; margin-top: 8px;
}
.task-item .approval-row input[type=checkbox] {
    width: auto; margin: 0;
}
.task-item .approval-row span {
    font-size: 11px; color: var(--text-secondary); font-weight: 500;
}
.add-task-btn {
    width: 100%; padding: 6px; border: 1.5px dashed var(--card-bdr);
    border-radius: 7px; background: none; color: var(--primary);
    font-size: 12px; font-weight: 600; cursor: pointer; transition: border-color .12s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
    margin-top: 6px;
}
.add-task-btn:hover { border-color: var(--primary); background: var(--primary-bg); }

/* Condition node */
.condition-list { display: flex; flex-direction: column; gap: 6px; }
.condition-item {
    background: var(--body-bg); border: 1px solid var(--card-bdr);
    border-radius: 7px; padding: 8px 10px; position: relative;
}
.condition-item select, .condition-item input {
    width: 100%; background: var(--card-bg); border: 1px solid var(--card-bdr);
    border-radius: 5px; padding: 4px 8px; font-size: 12px; color: var(--text-primary);
    font-family: var(--font); margin-top: 3px;
}
.condition-item .remove-cond { position: absolute; top: 6px; right: 6px; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; padding: 0; }
.cond-logic-bar { display: flex; gap: 6px; margin-bottom: 6px; }
.cond-logic-bar button { flex: 1; padding: 4px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer; border: 1.5px solid var(--card-bdr); background: var(--body-bg); color: var(--text-secondary); }
.cond-logic-bar button.active { border-color: var(--primary); background: var(--primary-bg); color: var(--primary); }

/* Assignee dropdown options */
.ar-opt { padding: 7px 10px; cursor: pointer; border-bottom: 1px solid var(--card-bdr); }
.ar-opt:last-child { border-bottom: none; }
.ar-opt:hover { background: var(--primary-bg); }
.ar-opt:hover div { color: var(--primary) !important; }

/* Version history panel */
#ver-panel {
    position: fixed; top: 0; right: -380px; width: 360px; height: 100vh;
    background: var(--card-bg); border-left: 1px solid var(--card-bdr);
    z-index: 1060; transition: right .25s; overflow-y: auto; padding: 20px;
}
#ver-panel.open { right: 0; }
#ver-panel h5 { font-weight: 700; margin-bottom: 16px; }
.ver-item { padding: 10px 12px; border: 1px solid var(--card-bdr); border-radius: 8px; margin-bottom: 8px; }
.ver-item .ver-num { font-weight: 700; font-size: 13px; }
.ver-item .ver-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Status bar */
#canvas-status { font-size: 12px; color: var(--text-muted); }

/* Flow on/off toggle */
.flow-toggle {
    width: 36px; height: 20px; border-radius: 20px;
    background: var(--card-bdr); position: relative;
    cursor: pointer; transition: background .2s; flex-shrink: 0; display:inline-flex;
}
.flow-toggle.on { background: #16a34a; }
.flow-knob {
    width: 14px; height: 14px; border-radius: 50%; background: #fff;
    position: absolute; top: 3px; left: 3px;
    transition: left .2s; box-shadow: 0 1px 4px rgba(0,0,0,.2);
}
.flow-toggle.on .flow-knob { left: 19px; }
</style>

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0 fw-bold" style="color:var(--text-primary);">Workflows</h5>
        <div style="font-size:12px;color:var(--text-muted);">Build and manage task automation flows</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openCreateModal()" style="border-radius:8px;font-weight:600;">
        <i class="bi bi-plus-lg me-1"></i> New Workflow
    </button>
</div>

<!-- ── Toolbar ─────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <!-- Search -->
    <div style="position:relative;flex:1;min-width:180px;max-width:280px;">
        <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
        <input type="text" id="wf-search" placeholder="Search workflows..." oninput="filterTable()"
            style="width:100%;padding:7px 10px 7px 32px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
    </div>
    <!-- Sort -->
    <select id="wf-sort" onchange="filterTable()" style="padding:7px 10px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
        <option value="date_desc">Newest first</option>
        <option value="date_asc">Oldest first</option>
        <option value="name_asc">Name A → Z</option>
        <option value="name_desc">Name Z → A</option>
    </select>
    <!-- Status filter -->
    <select id="wf-status" onchange="filterTable()" style="padding:7px 10px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
        <option value="">All status</option>
        <option value="published">Published</option>
        <option value="draft">Draft</option>
        <option value="unpublished">Has changes</option>
    </select>
</div>

<!-- ── Table ───────────────────────────────────────────────────────────── -->
<div class="card" style="border-radius:var(--r);overflow:hidden;">
<div class="table-responsive">
<table class="table table-hover mb-0" id="wf-table">
    <thead style="background:var(--body-bg);border-bottom:2px solid var(--card-bdr);">
        <tr>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;cursor:pointer;" onclick="sortByCol('name')">
                Name <i class="bi bi-arrow-down-up ms-1" style="font-size:9px;"></i>
            </th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Status</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;cursor:pointer;" onclick="sortByCol('version')">
                Version <i class="bi bi-arrow-down-up ms-1" style="font-size:9px;"></i>
            </th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Created by</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;cursor:pointer;" onclick="sortByCol('date')">
                Last updated <i class="bi bi-arrow-down-up ms-1" style="font-size:9px;"></i>
            </th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Active</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Actions</th>
        </tr>
    </thead>
    <tbody id="wf-tbody">
    <?php if (!$wf_list): ?>
    <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted);">
        <i class="bi bi-diagram-3" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
        No workflows yet. <a href="#" onclick="openCreateModal()">Create one</a>
    </td></tr>
    <?php else: ?>
    <?php foreach ($wf_list as $wf): ?>
    <tr class="wf-row" id="wf-row-<?= $wf['id'] ?>"
        data-name="<?= strtolower(sanitize($wf['name'])) ?>"
        data-date="<?= strtotime($wf['updated_at']) ?>"
        data-version="<?= $wf['current_version'] ?>"
        data-status="<?= $wf['published_version'] > 0 ? 'published' : 'draft' ?>"
        data-unpublished="<?= $wf['has_unpublished_changes'] ? '1' : '0' ?>"
        style="cursor:pointer;" onclick="openCanvas(<?= $wf['id'] ?>, <?= htmlspecialchars(json_encode($wf['name']), ENT_QUOTES) ?>)">
        <td style="padding:12px 16px;">
            <div style="font-weight:600;font-size:13px;color:var(--text-primary);display:flex;align-items:center;gap:7px;">
                <span id="wf-name-<?= $wf['id'] ?>"><?= sanitize($wf['name']) ?></span>
                <?php if ($wf['has_unpublished_changes']): ?>
                <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;flex-shrink:0;display:inline-block;" title="Unpublished changes"></span>
                <?php endif; ?>
                <button onclick="event.stopPropagation();renameWorkflow(<?= $wf['id'] ?>, '<?= addslashes(sanitize($wf['name'])) ?>')"
                    style="background:none;border:none;padding:0 2px;color:var(--text-muted);cursor:pointer;opacity:.5;line-height:1;"
                    title="Rename"><i class="bi bi-pencil" style="font-size:11px;"></i></button>
            </div>
            <?php if ($wf['description']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= sanitize($wf['description']) ?></div>
            <?php endif; ?>
        </td>
        <td style="padding:12px 16px;">
            <?php if ($wf['published_version'] > 0): ?>
            <span class="wf-published-badge"><i class="bi bi-check-circle-fill me-1"></i>Published</span>
            <?php else: ?>
            <span class="wf-draft-badge"><i class="bi bi-pencil me-1"></i>Draft</span>
            <?php endif; ?>
        </td>
        <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);">
            v<?= $wf['current_version'] ?>
            <?php if ($wf['published_version'] > 0): ?>
            <span style="font-size:11px;color:var(--text-muted);">(pub v<?= $wf['published_version'] ?>)</span>
            <?php endif; ?>
        </td>
        <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);"><?= sanitize($wf['creator_name']) ?></td>
        <td style="padding:12px 16px;font-size:12px;color:var(--text-muted);"><?= date('d M Y, h:i A', strtotime($wf['updated_at'])) ?></td>
        <td style="padding:12px 16px;" onclick="event.stopPropagation()">
            <div class="flow-toggle <?= $wf['is_active'] ? 'on' : '' ?>" onclick="toggleFlow(<?= $wf['id'] ?>, this)" title="<?= $wf['is_active'] ? 'Active' : 'Inactive' ?>">
                <div class="flow-knob"></div>
            </div>
        </td>
        <td style="padding:12px 16px;" onclick="event.stopPropagation()">
            <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-outline-primary btn-sm" style="font-size:11px;border-radius:6px;" onclick="openCanvas(<?= $wf['id'] ?>, <?= htmlspecialchars(json_encode($wf['name']), ENT_QUOTES) ?>)" title="Edit">
                    <i class="bi bi-pencil-square"></i>
                </button>
                <?php if ($role === 'SUPER_ADMIN'): ?>
                <button class="btn btn-sm share-btn <?= $wf['scope_type'] === 'global' ? 'shared' : '' ?>"
                    id="share-btn-<?= $wf['id'] ?>"
                    onclick="toggleShare(<?= $wf['id'] ?>, this)"
                    title="<?= $wf['scope_type'] === 'global' ? 'Shared with all — click to make private' : 'Private — click to share with all TLs' ?>"
                    style="font-size:11px;border-radius:6px;border:1px solid <?= $wf['scope_type'] === 'global' ? '#7c3aed' : 'var(--card-bdr)' ?>;background:<?= $wf['scope_type'] === 'global' ? '#ede9fe' : 'transparent' ?>;color:<?= $wf['scope_type'] === 'global' ? '#7c3aed' : 'var(--text-muted)' ?>;">
                    <i class="bi bi-share<?= $wf['scope_type'] === 'global' ? '-fill' : '' ?>"></i>
                </button>
                <?php endif; ?>
                <?php if (_can_edit($wf, $uid, $role)): ?>
                <button class="btn btn-outline-danger btn-sm" style="font-size:11px;border-radius:6px;" onclick="deleteWorkflow(<?= $wf['id'] ?>, '<?= sanitize($wf['name']) ?>')" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

<!-- ── Create Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content" style="border-radius:12px;border-color:var(--card-bdr);background:var(--card-bg);">
    <div class="modal-header" style="border-color:var(--card-bdr);">
        <h5 class="modal-title fw-bold">New Workflow</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px;">Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="wf-name" placeholder="e.g. Morning Standup Tasks">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px;">Description</label>
            <input type="text" class="form-control" id="wf-desc" placeholder="Optional description">
        </div>
    </div>
    <div class="modal-footer" style="border-color:var(--card-bdr);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="createWorkflow()" style="font-weight:600;">
            <i class="bi bi-plus-lg me-1"></i>Create & Open Builder
        </button>
    </div>
</div>
</div>
</div>

<!-- ── Quiz Builder Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="quizBuilderModal" tabindex="-1" aria-labelledby="quizBuilderModalLabel" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15);background:var(--card-bg);">
    <div class="modal-header border-0 pb-0 pt-4 px-4">
        <div>
            <h5 class="modal-title fw-bold" id="quizBuilderModalLabel"><i class="bi bi-patch-question-fill me-2" style="color:#7c3aed;"></i>Quiz Builder</h5>
            <p class="text-muted small mb-0 mt-1">Add questions that the assignee must answer before marking this task as done.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body px-4 py-3">
        <!-- Existing questions list -->
        <div id="quiz-questions-list" class="mb-3"></div>

        <!-- Add new question form -->
        <div class="p-3 rounded" style="background:var(--body-bg);border:1px dashed #7c3aed44;">
            <div class="fw-semibold small mb-2" style="color:#7c3aed;"><i class="bi bi-plus-circle me-1"></i>New Question</div>
            <div class="mb-2">
                <label class="form-label small fw-semibold mb-1">Question</label>
                <input type="text" id="qb-question" class="form-control form-control-sm" placeholder="e.g. What is Shopify's primary use case?">
            </div>
            <div class="mb-2">
                <label class="form-label small fw-semibold mb-1">Options <span class="fw-normal text-muted">(one per line, min 2)</span></label>
                <textarea id="qb-options" class="form-control form-control-sm" rows="4" placeholder="E-commerce platform&#10;Email marketing tool&#10;CRM software&#10;Social network"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Correct option # <span class="fw-normal text-muted">(1 = first option)</span></label>
                <input type="number" id="qb-correct" class="form-control form-control-sm" min="1" value="1" style="width:90px;">
            </div>
            <button type="button" class="btn btn-sm" style="background:#7c3aed;color:#fff;font-weight:600;border-radius:7px;" onclick="quizBuilderAddQ()">
                <i class="bi bi-plus-lg me-1"></i>Add Question
            </button>
        </div>
    </div>
    <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm" style="background:#7c3aed;color:#fff;font-weight:600;border-radius:7px;" onclick="quizBuilderSave()">
            <i class="bi bi-check-lg me-1"></i>Save Quiz
        </button>
    </div>
</div>
</div>
</div>

<!-- ── Canvas (fullscreen overlay) ─────────────────────────────────────── -->
<div id="canvas-area">
    <div id="canvas-topbar">
        <button class="btn btn-sm" onclick="closeCanvas()" style="border:1px solid var(--card-bdr);background:var(--body-bg);color:var(--text-secondary);border-radius:7px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-arrow-left"></i>
        </button>
        <div class="wf-title" id="canvas-title">Workflow</div>
        <span id="canvas-status"></span>
        <button class="btn btn-sm btn-outline-secondary" onclick="openVersionPanel()" style="font-size:12px;border-radius:7px;">
            <i class="bi bi-clock-history me-1"></i>History
        </button>
        <button class="btn btn-sm btn-outline-primary" onclick="saveDraft()" id="btn-save" style="font-size:12px;border-radius:7px;font-weight:600;">
            <i class="bi bi-floppy me-1"></i>Save
        </button>
        <button class="btn btn-sm btn-success" onclick="publishWorkflow()" id="btn-publish" style="font-size:12px;border-radius:7px;font-weight:600;">
            <i class="bi bi-send-check me-1"></i>Publish
        </button>
        <button class="btn btn-sm" onclick="runWorkflow()" id="btn-run"
            style="font-size:12px;border-radius:7px;font-weight:600;background:#16a34a;color:#fff;border:none;display:flex;align-items:center;gap:5px;">
            <i class="bi bi-play-fill"></i>Run
        </button>
    </div>
    <div id="canvas-wrap">
        <div id="node-panel">
            <h6>Nodes</h6>
            <div class="drag-node" draggable="true" ondragstart="dragStart(event,'task')" style="border-color:#3b82f6;color:#2563eb;">
                <i class="bi bi-list-task"></i> Task Card
            </div>
            <div class="drag-node" draggable="true" ondragstart="dragStart(event,'condition')" style="border-color:#f59e0b;color:#d97706;">
                <i class="bi bi-signpost-split-fill"></i> Condition
            </div>
            <div style="margin-top:20px;padding:10px;background:var(--body-bg);border-radius:8px;font-size:11px;color:var(--text-muted);line-height:1.6;">
                <b style="color:var(--text-secondary);">Tips</b><br>
                Drag nodes onto canvas.<br>
                Connect outputs → inputs.<br>
                All tasks in a card must complete before next card fires.
            </div>
        </div>
        <div id="drawflow-canvas" ondrop="drop(event)" ondragover="event.preventDefault()">
            <div id="drawflow"></div>
        </div>
    </div>
</div>

<!-- ── Version History Panel ───────────────────────────────────────────── -->
<div id="ver-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0 fw-bold">Version History</h5>
        <button onclick="closeVersionPanel()" style="background:none;border:none;font-size:1.2rem;color:var(--text-muted);cursor:pointer;"><i class="bi bi-x"></i></button>
    </div>
    <div id="ver-list"></div>
</div>

<!-- Drawflow CSS & JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.59/dist/drawflow.min.css">
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.59/dist/drawflow.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Table filter / sort ───────────────────────────────────────────────────
let _sortCol = 'date', _sortDir = 'desc';

function filterTable() {
    const q      = (document.getElementById('wf-search')?.value || '').toLowerCase();
    const sort   = document.getElementById('wf-sort')?.value || 'date_desc';
    const status = document.getElementById('wf-status')?.value || '';
    const rows   = Array.from(document.querySelectorAll('.wf-row'));

    rows.forEach(r => {
        const nameMatch   = r.dataset.name.includes(q);
        const statusMatch = !status ||
            (status === 'published'   && r.dataset.status === 'published') ||
            (status === 'draft'       && r.dataset.status === 'draft') ||
            (status === 'unpublished' && r.dataset.unpublished === '1');
        r.style.display = (nameMatch && statusMatch) ? '' : 'none';
    });

    const visible = rows.filter(r => r.style.display !== 'none');
    visible.sort((a, b) => {
        if (sort === 'date_desc') return b.dataset.date - a.dataset.date;
        if (sort === 'date_asc')  return a.dataset.date - b.dataset.date;
        if (sort === 'name_asc')  return a.dataset.name.localeCompare(b.dataset.name);
        if (sort === 'name_desc') return b.dataset.name.localeCompare(a.dataset.name);
        return 0;
    });
    const tbody = document.getElementById('wf-tbody');
    visible.forEach(r => tbody.appendChild(r));
}

function sortByCol(col) {
    const sortEl = document.getElementById('wf-sort');
    if (!sortEl) return;
    if (col === 'name') sortEl.value = sortEl.value === 'name_asc' ? 'name_desc' : 'name_asc';
    if (col === 'date') sortEl.value = sortEl.value === 'date_asc' ? 'date_desc' : 'date_asc';
    filterTable();
}

// ── Auto-save (always on, every 30s when canvas is open) ──────────────────
setInterval(() => { if (currentWfId) saveDraft(); }, 30000);

// ── Flow on/off toggle ────────────────────────────────────────────────────
async function toggleFlow(id, el) {
    const isOn = el.classList.contains('on');
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle_active', id, active: !isOn })
    });
    const data = await res.json();
    if (data.ok) {
        el.classList.toggle('on', !isOn);
        el.title = !isOn ? 'Active' : 'Inactive';
    }
}

// ── Globals ───────────────────────────────────────────────────────────────
let df = null;
let currentWfId = null;
let currentWfName = '';
let dragType = '';
let nodeCounter = 0;
const ALL_USERS   = <?= json_encode($all_users) ?>;
const CURRENT_UID  = <?= $uid ?>;
const CURRENT_ROLE = <?= json_encode($role) ?>;
const CURRENT_NAME = <?= json_encode($u['name']) ?>;

// ── Assignee row helpers ──────────────────────────────────────────────────
function buildAssigneeRows(nodeId, taskIdx, assignees) {
    if (!assignees || assignees.length === 0) return buildAssigneeRow(nodeId, taskIdx, 0, '');
    return assignees.map((a, ri) => buildAssigneeRow(nodeId, taskIdx, ri, a)).join('');
}

function buildAssigneeRow(nodeId, taskIdx, rowIdx, selected) {
    const isEmployee = CURRENT_ROLE === 'EMPLOYEE';
    const canSelfAssign = !isEmployee; // TL, Manager, Admin can use self-assign

    // For employees: default to themselves, no dropdown needed
    if (isEmployee) {
        return `<div class="assignee-row" id="ar-${nodeId}-${taskIdx}-${rowIdx}" style="display:flex;align-items:center;gap:5px;margin-bottom:4px;">
            <div style="flex:1;padding:5px 8px;background:var(--body-bg);border:1px solid var(--card-bdr);border-radius:5px;font-size:12px;color:var(--text-secondary);">
                <i class="bi bi-person-fill me-1" style="color:var(--primary);"></i>${CURRENT_NAME}
                ${(()=>{ const me = ALL_USERS.find(u=>u.id==CURRENT_UID); return me?.designation ? `<span style="color:var(--text-muted);font-size:10px;margin-left:4px;">${me.designation}</span>` : ''; })()}
            </div>
            <input type="hidden" class="ar-value" value="${CURRENT_UID}">
        </div>`;
    }

    // Build dropdown options
    const dynamicOpts = canSelfAssign ? `
        <div style="padding:4px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--body-bg);">Dynamic</div>
        <div class="ar-opt" data-value="self_assign" data-label="Self-Assign" onmousedown="selectAssignee(this)">
            <div style="font-size:12px;color:#16a34a;font-weight:600;"><i class="bi bi-person-check me-1"></i>Self-Assign</div>
            <div style="font-size:10px;color:var(--text-muted);">Person who triggers the flow</div>
        </div>
        <div class="ar-opt" data-value="target_person" data-label="Target Person" onmousedown="selectAssignee(this)">
            <div style="font-size:12px;color:#7c3aed;font-weight:600;"><i class="bi bi-person-bounding-box me-1"></i>Target Person</div>
            <div style="font-size:10px;color:var(--text-muted);">Employee the flow is run on</div>
        </div>
        <div class="ar-opt" data-value="their_tl" data-label="Their Team Lead" onmousedown="selectAssignee(this)">
            <div style="font-size:12px;color:#d97706;font-weight:600;"><i class="bi bi-person-workspace me-1"></i>Their Team Lead</div>
            <div style="font-size:10px;color:var(--text-muted);">TL of the target person's dept</div>
        </div>
        <div style="padding:4px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--body-bg);">People</div>
    ` : '';

    const userOpts = ALL_USERS.filter(u => u.id != CURRENT_UID).map(u => {
        const desig = (u.designation || '').trim();
        const sub   = desig ? `<div style="font-size:10px;color:var(--text-muted);margin-top:1px;">${desig}</div>` : '';
        const label = desig ? `${u.name} — ${desig}` : u.name;
        return `<div class="ar-opt" data-value="${u.id}" data-label="${escAttr(label)}" onmousedown="selectAssignee(this)">
            <div style="font-size:12px;color:var(--text-primary);font-weight:500;">${u.name}</div>${sub}
        </div>`;
    }).join('');

    // Resolve display label for pre-selected value
    let initLabel = '';
    if (selected === 'self_assign')    initLabel = 'Self-Assign';
    else if (selected === 'target_person') initLabel = 'Target Person';
    else if (selected === 'their_tl')  initLabel = 'Their Team Lead';
    else if (selected) { const u = ALL_USERS.find(u => u.id == selected); if (u) initLabel = u.designation ? `${u.name} — ${u.designation}` : u.name; }

    return `<div class="assignee-row" id="ar-${nodeId}-${taskIdx}-${rowIdx}" style="display:flex;align-items:center;gap:5px;margin-bottom:4px;">
        <div style="position:relative;flex:1;">
            <input type="text" class="ar-search" placeholder="Search person..." autocomplete="off" value="${escAttr(initLabel)}"
                style="width:100%;box-sizing:border-box;background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:5px;padding:5px 8px;font-size:12px;color:var(--text-primary);font-family:var(--font);"
                oninput="filterAssignee(this)" onfocus="showAssigneeDropdown(this)" onblur="hideAssigneeDropdown(this)">
            <div class="ar-dropdown" style="display:none;position:absolute;top:100%;left:0;width:230px;background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:6px;z-index:99999;max-height:180px;overflow-y:auto;box-shadow:0 6px 20px rgba(0,0,0,.18);">
                ${dynamicOpts}${userOpts}
            </div>
            <input type="hidden" class="ar-value" value="${escAttr(selected)}">
        </div>
        <button onclick="removeAssigneeRow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;padding:2px 4px;line-height:1;flex-shrink:0;"><i class="bi bi-x"></i></button>
    </div>`;
}

let _assigneeRowCounters = {};
function addAssigneeRow(nodeId, taskIdx) {
    const key = `${nodeId}-${taskIdx}`;
    _assigneeRowCounters[key] = (_assigneeRowCounters[key] || 0) + 1;
    const list = document.getElementById(`al-${nodeId}-${taskIdx}`);
    if (!list) return;
    const div = document.createElement('div');
    div.innerHTML = buildAssigneeRow(nodeId, taskIdx, _assigneeRowCounters[key], '');
    list.appendChild(div.firstElementChild);
}

function removeAssigneeRow(btn) {
    btn.closest('.assignee-row')?.remove();
}

function filterAssignee(input) {
    const q = input.value.toLowerCase();
    const dd = input.nextElementSibling;
    dd.style.display = 'block';
    dd.querySelectorAll('.ar-opt').forEach(opt => {
        opt.style.display = opt.textContent.toLowerCase().includes(q) ? 'block' : 'none';
    });
}

function showAssigneeDropdown(input) {
    input.nextElementSibling.style.display = 'block';
}

function hideAssigneeDropdown(input) {
    setTimeout(() => { if (input.nextElementSibling) input.nextElementSibling.style.display = 'none'; }, 150);
}

function selectAssignee(opt) {
    const row = opt.closest('.assignee-row');
    row.querySelector('.ar-search').value = opt.dataset.label;
    row.querySelector('.ar-value').value  = opt.dataset.value;
    opt.closest('.ar-dropdown').style.display = 'none';
}


// ── Drag & Drop ──────────────────────────────────────────────────────────
function dragStart(e, type) { dragType = type; e.dataTransfer.setData('text/plain', type); }

function drop(e) {
    e.preventDefault();
    const type = dragType || e.dataTransfer.getData('text/plain');
    if (!type || !df) return;
    const rect = document.getElementById('drawflow-canvas').getBoundingClientRect();
    const x = (e.clientX - rect.left - df.canvas_x) / df.zoom;
    const y = (e.clientY - rect.top  - df.canvas_y) / df.zoom;
    addNode(type, x, y);
}

// ── Add Node ─────────────────────────────────────────────────────────────
function addNode(type, x, y, data = null) {
    nodeCounter++;
    const id = `node_${nodeCounter}_${Date.now()}`;

    let html = '';
    let inputs = 1, outputs = 1;

    if (type === 'task') {
        html = buildTaskNode(id, data);
    } else if (type === 'condition') {
        outputs = 2;
        html = buildConditionNode(id, data);
    }

    df.addNode(type, inputs, outputs, x, y, `node-${type}`, { nodeType: type, nodeId: id }, html);

    // First node dropped = auto start
    if (!startNodeId) setStartNode(id);
}

// ── Node Builders ─────────────────────────────────────────────────────────
function buildTaskNode(nodeId, data) {
    const tasks = data?.tasks || [{ title:'', description:'', priority:'MEDIUM', due_days:1, assignees:[], require_approval:false }];
    const isStart = data?.is_start || false;
    const tasksHtml = tasks.map((t, i) => buildTaskItem(nodeId, i, t)).join('');
    return `<div class="node-inner node-task" id="tn-${nodeId}" data-nodeid="${nodeId}">
        <button class="node-play-btn" onclick="setStartNode('${nodeId}')" title="Set as start"><i class="bi bi-play-fill"></i></button>
        <div class="node-header">
            <i class="bi bi-list-task"></i>
            <span class="node-label">Task Card</span>
            <button onclick="deleteNode('${nodeId}')" class="node-del-btn" title="Delete node"><i class="bi bi-trash3"></i></button>
        </div>
        <div class="task-list" id="tl-${nodeId}">${tasksHtml}</div>
        <button class="add-task-btn" onclick="addTaskItem('${nodeId}')"><i class="bi bi-plus"></i> Add Task</button>
    </div>`;
}

function buildTaskItem(nodeId, idx, t = {}) {
    const quizQs    = t.quiz_questions || [];
    const quizCount = quizQs.length;
    const quizLabel = quizCount === 1 ? '1 question' : quizCount + ' questions';
    return `<div class="task-item" id="ti-${nodeId}-${idx}">
        <button class="remove-task" onclick="removeTaskItem('${nodeId}',${idx})" title="Remove"><i class="bi bi-x"></i></button>
        <label>Title</label>
        <input type="text" placeholder="Task title" value="${escAttr(t.title||'')}" class="ti-title">
        <label>Description</label>
        <textarea placeholder="Details..." class="ti-desc">${escAttr(t.description||'')}</textarea>
        <div class="priority-due-row">
            <div>
                <label>Priority</label>
                <select class="ti-priority">
                    <option value="LOW"    ${t.priority==='LOW'   ?'selected':''}>Low</option>
                    <option value="MEDIUM" ${(!t.priority||t.priority==='MEDIUM')?'selected':''}>Medium</option>
                    <option value="HIGH"   ${t.priority==='HIGH'  ?'selected':''}>High</option>
                    <option value="URGENT" ${t.priority==='URGENT'?'selected':''}>Urgent</option>
                </select>
            </div>
            <div>
                <label>Due (days)</label>
                <input type="number" min="1" value="${t.due_days||1}" class="ti-due">
            </div>
        </div>
        <label>Assignee(s)</label>
        <div class="assignee-list" id="al-${nodeId}-${idx}">${buildAssigneeRows(nodeId, idx, t.assignees||[])}</div>
        <button class="add-task-btn" style="margin-top:4px;" onclick="addAssigneeRow('${nodeId}',${idx})"><i class="bi bi-plus"></i> Add Assignee</button>
        <div class="approval-row">
            <input type="checkbox" class="ti-approval" id="ta-${nodeId}-${idx}" ${t.require_approval?'checked':''}>
            <span>Require TL approval</span>
        </div>
        <div class="approval-row" style="margin-top:6px;border-top:1px solid var(--card-bdr);padding-top:6px;">
            <input type="checkbox" class="ti-is-learning" id="tl-chk-${nodeId}-${idx}" ${t.is_learning?'checked':''}
                onchange="document.getElementById('lp-${nodeId}-${idx}').style.display=this.checked?'block':'none'">
            <span style="color:#7c3aed;font-weight:600;"><i class="bi bi-mortarboard"></i> Learning Task</span>
        </div>
        <div id="lp-${nodeId}-${idx}" style="display:${t.is_learning?'block':'none'};margin-top:6px;padding:8px;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.25);border-radius:7px;">
            <label>Badge Name</label>
            <input type="text" class="ti-badge-name" placeholder="e.g. Shopify Champion" value="${escAttr(t.badge_name||'')}">
            <label>Badge Icon (emoji)</label>
            <input type="text" class="ti-badge-icon" placeholder="🏅" maxlength="4" value="${escAttr(t.badge_icon||'🏅')}" style="width:60px;">
            <label>Learning Material (URL or text)</label>
            <textarea class="ti-learning-material" placeholder="https://... or paste content here" style="min-height:50px;">${escAttr(t.learning_material||'')}</textarea>
            <label>Quiz Pass % <span style="font-weight:400;color:var(--text-muted);">(0 = no quiz, TL reviews answers)</span></label>
            <input type="number" class="ti-pass-pct" min="0" max="100" value="${t.pass_pct||80}" style="width:70px;">
            <input type="hidden" class="ti-quiz-questions" value="${escAttr(JSON.stringify(quizQs))}">
            <div style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="openQuizModal('${nodeId}',${idx})"
                    style="background:#7c3aed;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;">
                    <i class="bi bi-patch-question-fill"></i> Add Quiz
                </button>
                <span class="ti-quiz-count" style="font-size:11px;color:#7c3aed;font-weight:600;">${quizCount > 0 ? quizLabel : 'No questions yet'}</span>
            </div>
        </div>
    </div>`;
}

function buildConditionNode(nodeId, data) {
    const logic = data?.logic || 'AND';
    const conds = data?.conditions || [{ field:'priority', operator:'==', value:'HIGH' }];
    const condsHtml = conds.map((c, i) => buildCondItem(nodeId, i, c)).join('');
    return `<div class="node-inner node-condition" id="cn-${nodeId}" data-nodeid="${nodeId}">
        <button class="node-play-btn" onclick="setStartNode('${nodeId}')" title="Set as start"><i class="bi bi-play-fill"></i></button>
        <div class="node-header">
            <i class="bi bi-signpost-split-fill"></i>
            <span class="node-label">Condition</span>
            <button onclick="deleteNode('${nodeId}')" class="node-del-btn" title="Delete node"><i class="bi bi-trash3"></i></button>
        </div>
        <div class="cond-logic-bar">
            <button onclick="setCondLogic('${nodeId}','AND',this)" class="${logic==='AND'?'active':''}" id="cl-and-${nodeId}">AND</button>
            <button onclick="setCondLogic('${nodeId}','OR',this)" class="${logic==='OR'?'active':''}" id="cl-or-${nodeId}">OR</button>
        </div>
        <div class="condition-list" id="cl-${nodeId}">${condsHtml}</div>
        <button class="add-task-btn" onclick="addCondItem('${nodeId}')"><i class="bi bi-plus"></i> Add Condition</button>
        <div style="margin-top:8px;font-size:10px;color:var(--text-muted);">Output 1 = YES &nbsp;|&nbsp; Output 2 = NO</div>
    </div>`;
}

function buildCondItem(nodeId, idx, c = {}) {
    const fields = ['priority','status','assigned_to','due_date','title'];
    const ops    = ['==','!=','>','<','contains'];
    return `<div class="condition-item" id="ci-${nodeId}-${idx}">
        <button class="remove-cond" onclick="removeCondItem('${nodeId}',${idx})"><i class="bi bi-x"></i></button>
        <label>Field</label>
        <select class="ci-field">
            ${fields.map(f => `<option value="${f}" ${c.field===f?'selected':''}>${f}</option>`).join('')}
        </select>
        <label>Operator</label>
        <select class="ci-op">
            ${ops.map(o => `<option value="${o}" ${c.operator===o?'selected':''}>${o}</option>`).join('')}
        </select>
        <label>Value</label>
        <input type="text" class="ci-val" value="${escAttr(c.value||'')}" placeholder="e.g. HIGH">
    </div>`;
}

// ── Task / Condition mutations ────────────────────────────────────────────
let _taskCounters = {};
function addTaskItem(nodeId) {
    _taskCounters[nodeId] = (_taskCounters[nodeId] || 0) + 1;
    const idx = _taskCounters[nodeId];
    const list = document.getElementById('tl-' + nodeId);
    if (!list) return;
    const div = document.createElement('div');
    div.innerHTML = buildTaskItem(nodeId, idx);
    list.appendChild(div.firstElementChild);
    df.updateConnectionNodes('node-' + nodeId);
}

function removeTaskItem(nodeId, idx) {
    const el = document.getElementById(`ti-${nodeId}-${idx}`);
    if (el) { el.remove(); df.updateConnectionNodes('node-' + nodeId); }
}

let _condCounters = {};
function addCondItem(nodeId) {
    _condCounters[nodeId] = (_condCounters[nodeId] || 0) + 1;
    const idx = _condCounters[nodeId];
    const list = document.getElementById('cl-' + nodeId);
    if (!list) return;
    const div = document.createElement('div');
    div.innerHTML = buildCondItem(nodeId, idx);
    list.appendChild(div.firstElementChild);
    df.updateConnectionNodes('node-' + nodeId);
}

function removeCondItem(nodeId, idx) {
    const el = document.getElementById(`ci-${nodeId}-${idx}`);
    if (el) { el.remove(); df.updateConnectionNodes('node-' + nodeId); }
}

function setCondLogic(nodeId, logic, btn) {
    document.getElementById(`cl-and-${nodeId}`).classList.remove('active');
    document.getElementById(`cl-or-${nodeId}`).classList.remove('active');
    btn.classList.add('active');
}

// ── Serialize canvas → JSON ───────────────────────────────────────────────
function serializeCanvas() {
    if (!df) return '{}';
    const raw = df.export();
    const nodes = raw.drawflow.Home.data;

    // Enrich each node with form data from the DOM
    Object.values(nodes).forEach(node => {
        const type = node.data.nodeType;
        const nid  = node.data.nodeId;
        if (type === 'task' && nid) {
            node.data.tasks    = extractTasks(nid);
            node.data.is_start = (startNodeId === nid);
        }
        if (type === 'condition' && nid) {
            node.data.conditions = extractConditions(nid);
            node.data.is_start   = (startNodeId === nid);
            const andBtn = document.getElementById(`cl-and-${nid}`);
            node.data.logic = andBtn?.classList.contains('active') ? 'AND' : 'OR';
        }
    });
    return JSON.stringify(raw);
}

function extractTasks(nodeId) {
    const el = document.getElementById(`tl-${nodeId}`);
    if (!el) return [];
    return Array.from(el.querySelectorAll('.task-item')).map(item => {
        const assignees = Array.from(item.querySelectorAll('.ar-value')).map(i => i.value).filter(v => v);
        let quizQuestions = [];
        try { quizQuestions = JSON.parse(item.querySelector('.ti-quiz-questions')?.value || '[]'); } catch(e) {}
        return {
            title: item.querySelector('.ti-title')?.value || '',
            description: item.querySelector('.ti-desc')?.value || '',
            priority: item.querySelector('.ti-priority')?.value || 'MEDIUM',
            due_days: parseInt(item.querySelector('.ti-due')?.value || '1'),
            assignees,
            require_approval:    item.querySelector('.ti-approval')?.checked    || false,
            is_learning:         item.querySelector('.ti-is-learning')?.checked  || false,
            badge_name:          item.querySelector('.ti-badge-name')?.value     || '',
            badge_icon:          item.querySelector('.ti-badge-icon')?.value     || '🏅',
            learning_material:   item.querySelector('.ti-learning-material')?.value || '',
            pass_pct:            parseInt(item.querySelector('.ti-pass-pct')?.value || '80'),
            quiz_questions:      quizQuestions,
        };
    });
}

function extractConditions(nodeId) {
    const el = document.getElementById(`cl-${nodeId}`);
    if (!el) return [];
    return Array.from(el.querySelectorAll('.condition-item')).map(item => ({
        field: item.querySelector('.ci-field')?.value || 'priority',
        operator: item.querySelector('.ci-op')?.value || '==',
        value: item.querySelector('.ci-val')?.value || ''
    }));
}

// ── Load canvas from JSON ─────────────────────────────────────────────────
function loadCanvas(layoutJson) {
    if (!df) return;
    df.clear();
    nodeCounter = 0;
    startNodeId = null;
    _taskCounters = {};
    _condCounters = {};
    _assigneeRowCounters = {};
    try {
        const layout = typeof layoutJson === 'string' ? JSON.parse(layoutJson) : layoutJson;
        if (!layout?.drawflow?.Home?.data) return;

        const nodes = layout.drawflow.Home.data;

        // Step 1: add all nodes with fresh HTML (avoids stale cached HTML)
        Object.values(nodes).forEach(node => {
            const type   = node.data?.nodeType || node.name;
            const nid    = node.data?.nodeId || ('loaded_' + node.id);
            node.data.nodeId = nid;
            let html = '';
            let inputs = 1, outputs = 1;
            if (type === 'task') {
                html = buildTaskNode(nid, node.data);
            } else if (type === 'condition') {
                outputs = 2;
                html = buildConditionNode(nid, node.data);
            }
            if (html) {
                // overwrite the html in the layout before import
                node.html = html;
                node.typenode = false;
            }
        });

        df.import(layout);

        // Restore start node — use saved is_start, else pick first node
        const allNodes = Object.values(layout.drawflow.Home.data);
        const savedStart = allNodes.find(n => n.data?.is_start);
        const firstNode  = allNodes[0];
        const startNode  = savedStart || firstNode;
        if (startNode?.data?.nodeId) setTimeout(() => setStartNode(startNode.data.nodeId), 50);

    } catch(e) { console.error('Load canvas error', e); }
}

// ── Open/Close canvas ─────────────────────────────────────────────────────
async function openCanvas(id, name) {
    currentWfId   = id;
    currentWfName = name;
    document.getElementById('canvas-title').textContent = name;
    document.getElementById('canvas-area').classList.add('open');
    document.body.style.overflow = 'hidden';
    setStatus('Loading...');

    // Push URL hash so back button works and link is shareable
    history.pushState({ wfId: id, wfName: name }, '', `#${id}`);

    if (!df) initDrawflow();

    const res = await fetch(`workflows.php?load=${id}`);
    const data = await res.json();
    if (data.ok && data.workflow.draft_layout) {
        loadCanvas(data.workflow.draft_layout);
        document.getElementById('canvas-title').textContent = data.workflow.name;
        currentWfName = data.workflow.name;
    }
    setStatus('');
    document.querySelectorAll('.wf-row').forEach(r => r.classList.remove('table-active'));
    document.getElementById(`wf-row-${id}`)?.classList.add('table-active');
}

function closeCanvas() {
    document.getElementById('canvas-area').classList.remove('open');
    document.body.style.overflow = '';
    currentWfId = null;
    closeVersionPanel();
    // Go back to listing URL
    history.pushState({}, '', 'workflows.php');
    document.querySelectorAll('.wf-row').forEach(r => r.classList.remove('table-active'));
}

// Handle browser back/forward
window.addEventListener('popstate', e => {
    if (e.state?.wfId) {
        openCanvas(e.state.wfId, e.state.wfName);
    } else {
        document.getElementById('canvas-area').classList.remove('open');
        document.body.style.overflow = '';
        currentWfId = null;
        closeVersionPanel();
    }
});

// On page load — if URL has a hash, open that workflow
window.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    if (hash && !isNaN(hash)) openCanvas(parseInt(hash), '...');
});

function initDrawflow() {
    const container = document.getElementById('drawflow');
    df = new Drawflow(container);
    df.reroute = true;
    df.reroute_fix_curvature = true;
    df.force_first_input = false;
    df.start();
}

// ── Save draft ────────────────────────────────────────────────────────────
async function saveDraft() {
    if (!currentWfId) return;
    setStatus('Saving...');
    document.getElementById('btn-save').disabled = true;
    const layout = serializeCanvas();
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_draft', id: currentWfId, layout })
    });
    const data = await res.json();
    document.getElementById('btn-save').disabled = false;
    if (data.ok) {
        setStatus(`Saved · v${data.version}`);
        // Show orange dot
        const dotEl = document.querySelector(`#wf-card-${currentWfId} .wf-unpublished`);
        if (!dotEl) {
            const card = document.getElementById(`wf-card-${currentWfId}`);
            if (card) {
                const dot = document.createElement('div');
                dot.className = 'wf-unpublished';
                dot.title = 'Unpublished changes';
                card.appendChild(dot);
            }
        }
        setTimeout(() => setStatus(''), 3000);
    } else {
        setStatus('Save failed: ' + data.msg);
    }
}

// ── Publish ───────────────────────────────────────────────────────────────
async function publishWorkflow() {
    if (!currentWfId) return;
    // Save first then publish
    await saveDraft();
    setStatus('Publishing...');
    document.getElementById('btn-publish').disabled = true;
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'publish', id: currentWfId })
    });
    const data = await res.json();
    document.getElementById('btn-publish').disabled = false;
    if (data.ok) {
        setStatus(`Published · v${data.published_version}`);
        // Remove orange dot
        document.querySelector(`#wf-card-${currentWfId} .wf-unpublished`)?.remove();
        // Update badge
        const card = document.getElementById(`wf-card-${currentWfId}`);
        if (card) {
            const badge = card.querySelector('.wf-draft-badge,.wf-published-badge');
            if (badge) { badge.className = 'wf-published-badge'; badge.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i>Published v${data.published_version}`; }
        }
        setTimeout(() => setStatus(''), 3000);
    } else {
        setStatus('Publish failed: ' + data.msg);
    }
}

// ── Create workflow ───────────────────────────────────────────────────────
async function createWorkflow() {
    const name  = document.getElementById('wf-name').value.trim();
    const desc  = document.getElementById('wf-desc').value.trim();
    if (!name) { showAlert('Workflow name is required.', 'warning', 'Missing Name'); return; }
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action:'create', name, description:desc })
    });
    const data = await res.json();
    if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('createModal'))?.hide();
        // Add row to table without reload
        const tbody = document.getElementById('wf-tbody');
        const now   = new Date().toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) + ', ' + new Date().toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
        const tr    = document.createElement('tr');
        tr.className = 'wf-row';
        tr.id        = `wf-row-${data.id}`;
        tr.dataset.name    = name.toLowerCase();
        tr.dataset.date    = Math.floor(Date.now() / 1000);
        tr.dataset.version = '0';
        tr.dataset.status  = 'draft';
        tr.dataset.unpublished = '1';
        tr.style.cursor = 'pointer';
        tr.onclick = () => openCanvas(data.id, name);
        tr.innerHTML = `
            <td style="padding:12px 16px;">
                <div style="font-weight:600;font-size:13px;color:var(--text-primary);display:flex;align-items:center;gap:7px;">
                    ${name} <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>
                </div>
                ${desc ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${desc}</div>` : ''}
            </td>
            <td style="padding:12px 16px;"><span class="wf-draft-badge"><i class="bi bi-pencil me-1"></i>Draft</span></td>
            <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);">v0</td>
            <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);">${CURRENT_NAME}</td>
            <td style="padding:12px 16px;font-size:12px;color:var(--text-muted);">${now}</td>
            <td style="padding:12px 16px;" onclick="event.stopPropagation()">
                <div class="flow-toggle on" onclick="toggleFlow(${data.id},this)"><div class="flow-knob"></div></div>
            </td>
            <td style="padding:12px 16px;" onclick="event.stopPropagation()">
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm" style="font-size:11px;border-radius:6px;" onclick="openCanvas(${data.id},'${name.replace(/'/g,"\\'")}')"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-outline-danger btn-sm" style="font-size:11px;border-radius:6px;" onclick="deleteWorkflow(${data.id},'${name.replace(/'/g,"\\'")}')"><i class="bi bi-trash"></i></button>
                </div>
            </td>`;
        tbody.prepend(tr);
        // Open canvas directly
        openCanvas(data.id, name);
    } else { showToast(data.msg, 'error', 'Error'); }
}

// ── Share toggle (Super Admin only) ──────────────────────────────────────
async function toggleShare(id, btn) {
    const isShared = btn.classList.contains('shared');
    const res  = await fetch('workflows.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'share', id, shared: !isShared })
    });
    const data = await res.json();
    if (!data.ok) { showToast(data.msg, 'error'); return; }
    const nowShared = data.scope_type === 'global';
    btn.classList.toggle('shared', nowShared);
    btn.style.borderColor  = nowShared ? '#7c3aed' : 'var(--card-bdr)';
    btn.style.background   = nowShared ? '#ede9fe' : 'transparent';
    btn.style.color        = nowShared ? '#7c3aed' : 'var(--text-muted)';
    btn.innerHTML          = nowShared ? '<i class="bi bi-share-fill"></i>' : '<i class="bi bi-share"></i>';
    btn.title              = nowShared ? 'Shared with all — click to make private' : 'Private — click to share with all TLs';
}

// ── Delete workflow ───────────────────────────────────────────────────────
async function renameWorkflow(id, currentName) {
    const newName = prompt('Rename workflow:', currentName);
    if (!newName || newName.trim() === currentName) return;
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rename', id, name: newName.trim() })
    });
    const data = await res.json();
    if (data.ok) {
        const el = document.getElementById(`wf-name-${id}`);
        if (el) el.textContent = data.name;
        showToast('Workflow renamed.', 'success');
    } else showToast(data.msg || 'Failed to rename.', 'error');
}

async function deleteWorkflow(id, name) {
    const ok = await showConfirm(`"${name}" will be permanently deleted.`, { title:'Delete Workflow?', okText:'Delete', okColor:'#ef4444', icon:'🗑️' });
    if (!ok) return;
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
    });
    const data = await res.json();
    if (data.ok) { document.getElementById(`wf-row-${id}`)?.remove(); showToast('Workflow deleted.', 'success'); }
    else showToast(data.msg, 'error');
}

// ── Version history ───────────────────────────────────────────────────────
async function openVersionPanel() {
    if (!currentWfId) return;
    document.getElementById('ver-panel').classList.add('open');
    const res  = await fetch(`workflows.php?versions=${currentWfId}`);
    const data = await res.json();
    const list = document.getElementById('ver-list');
    if (!data.ok || !data.versions.length) {
        list.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No versions saved yet.</div>';
        return;
    }
    list.innerHTML = data.versions.map(v => `
        <div class="ver-item">
            <div class="d-flex align-items-center justify-content-between">
                <div class="ver-num">Version ${v.version_number} ${v.label ? `<span style="font-size:11px;color:var(--text-muted);">(${v.label})</span>` : ''}</div>
                <button class="btn btn-outline-primary btn-sm" style="font-size:11px;border-radius:6px;" onclick="restoreVersion(${v.id})">Restore</button>
            </div>
            <div class="ver-meta">By ${v.saved_by_name} · ${new Date(v.created_at).toLocaleString()}</div>
        </div>
    `).join('');
}

async function restoreVersion(verId) {
    const ok = await showConfirm('Current draft will be overwritten with this version.', { title:'Restore Version?', okText:'Restore', okColor:'#3b82f6', icon:'🔄' });
    if (!ok) return;
    const res  = await fetch('workflows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore_version', template_id: currentWfId, version_id: verId })
    });
    const data = await res.json();
    if (data.ok) { loadCanvas(data.layout); closeVersionPanel(); setStatus('Restored · v' + data.version); setTimeout(() => setStatus(''), 3000); showToast('Version restored successfully.', 'success'); }
    else showToast(data.msg, 'error');
}

function closeVersionPanel() { document.getElementById('ver-panel').classList.remove('open'); }

// ── Create modal scope toggle ─────────────────────────────────────────────
function openCreateModal() {
    const modal = new bootstrap.Modal(document.getElementById('createModal'));
    modal.show();
}


// ── Utils ─────────────────────────────────────────────────────────────────
function setStatus(msg) { document.getElementById('canvas-status').textContent = msg; }
function escAttr(str) { return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function escHtml(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Quiz Builder ───────────────────────────────────────────────────────────
let _qbNodeId  = null;
let _qbTaskIdx = null;
let _qbQuestions = [];

function openQuizModal(nodeId, idx) {
    _qbNodeId  = nodeId;
    _qbTaskIdx = idx;
    const input = document.querySelector(`#ti-${nodeId}-${idx} .ti-quiz-questions`);
    try { _qbQuestions = JSON.parse(input?.value || '[]'); } catch(e) { _qbQuestions = []; }
    document.getElementById('qb-question').value = '';
    document.getElementById('qb-options').value  = '';
    document.getElementById('qb-correct').value  = '1';
    _qbRender();
    new bootstrap.Modal(document.getElementById('quizBuilderModal')).show();
}

function _qbRender() {
    const list = document.getElementById('quiz-questions-list');
    if (!_qbQuestions.length) {
        list.innerHTML = '<p class="text-muted small text-center py-2 mb-0">No questions yet. Add one below.</p>';
        return;
    }
    list.innerHTML = _qbQuestions.map((q, i) => `
        <div class="d-flex align-items-start mb-2 p-2 rounded" style="background:var(--body-bg);border:1px solid var(--card-bdr);">
            <div class="flex-grow-1">
                <div class="fw-semibold small">${i+1}. ${escHtml(q.question)}</div>
                <div class="mt-1 d-flex flex-wrap gap-2">
                    ${(q.options||[]).map((o,oi) => `<span class="small">${oi===q.correct_idx?'<span style="color:#16a34a;">&#10003;</span>':'<span class="text-muted">○</span>'} ${escHtml(o)}</span>`).join('')}
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger ms-2 flex-shrink-0"
                style="font-size:11px;padding:2px 6px;border-radius:6px;" onclick="_qbRemove(${i})">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `).join('');
}

function quizBuilderAddQ() {
    const question = document.getElementById('qb-question').value.trim();
    const options  = document.getElementById('qb-options').value.split('\n').map(s => s.trim()).filter(Boolean);
    const correctNum = parseInt(document.getElementById('qb-correct').value) || 1;
    const correctIdx = Math.max(0, Math.min(correctNum - 1, options.length - 1));
    if (!question) { alert('Please enter a question.'); return; }
    if (options.length < 2) { alert('Please enter at least 2 options, one per line.'); return; }
    _qbQuestions.push({ question, options, correct_idx: correctIdx });
    _qbRender();
    document.getElementById('qb-question').value = '';
    document.getElementById('qb-options').value  = '';
    document.getElementById('qb-correct').value  = '1';
}

function _qbRemove(idx) {
    _qbQuestions.splice(idx, 1);
    _qbRender();
}

function quizBuilderSave() {
    const input = document.querySelector(`#ti-${_qbNodeId}-${_qbTaskIdx} .ti-quiz-questions`);
    if (input) {
        input.value = JSON.stringify(_qbQuestions);
        const badge = document.querySelector(`#ti-${_qbNodeId}-${_qbTaskIdx} .ti-quiz-count`);
        if (badge) {
            const n = _qbQuestions.length;
            badge.textContent = n === 0 ? 'No questions yet' : (n === 1 ? '1 question' : n + ' questions');
        }
    }
    bootstrap.Modal.getInstance(document.getElementById('quizBuilderModal'))?.hide();
}

// ── Delete node ───────────────────────────────────────────────────────────
function deleteNode(nodeId) {
    const inner = document.getElementById('tn-' + nodeId) || document.getElementById('cn-' + nodeId);
    if (!inner) return;
    const wrapper = inner.closest('.drawflow-node');
    if (!wrapper) return;
    const dfNodeId = wrapper.id.replace('node-', '');
    df.removeNodeId('node-' + dfNodeId);
}

// ── Set start node ────────────────────────────────────────────────────────
let startNodeId = null;
function setStartNode(nodeId) {
    // Remove is-start from all nodes
    document.querySelectorAll('.drawflow-node.is-start').forEach(el => el.classList.remove('is-start'));
    startNodeId = nodeId;
    // Find the drawflow-node wrapper that contains this nodeId
    const inner = document.getElementById('tn-' + nodeId) || document.getElementById('cn-' + nodeId);
    if (inner) {
        const wrapper = inner.closest('.drawflow-node');
        if (wrapper) wrapper.classList.add('is-start');
    }
}

// ── Run workflow (Phase 3 — trigger engine) ───────────────────────────────
function runWorkflow() {
    if (!currentWfId) return;
    showAlert('Use the Triggers page to run this workflow manually or set up automatic triggers.', 'info', 'Run Workflow');
}

// Keyboard shortcut: Ctrl+S saves
document.addEventListener('keydown', e => {
    if (e.ctrlKey && e.key === 's') { e.preventDefault(); if (currentWfId) saveDraft(); }
});
</script>

<?php include 'footer.php'; ?>

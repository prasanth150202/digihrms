<?php
require_once 'config.php';
require_login();
$page      = 'triggers';
$pageTitle = 'Triggers & Actions';
$u         = current_user();
$uid       = $u['id'];
$role      = $u['role'];
$can_manage = has_role('SUPER_ADMIN','DEPT_MANAGER','TEAM_LEAD');
$is_admin   = has_role('SUPER_ADMIN','DEPT_MANAGER');

// ── AJAX ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $b['action'] ?? '';

    // create
    if ($action === 'create') {
        $name  = trim($b['name'] ?? '');
        $wf_id = (int)($b['workflow_id'] ?? 0);
        $conds = $b['conditions'] ?? [];
        $desc  = trim($b['description'] ?? '');
        // trigger_type lives inside conditions now (derived from the event row)
        $type  = $b['trigger_type'] ?? ($conds['trigger_type'] ?? '');
        $valid_types = ['time','login','task_created','task_status','task_assigned','task_overdue','task_completed'];
        if (!$name || !$type || !$wf_id || !in_array($type, $valid_types)) {
            echo json_encode(['ok'=>false,'msg'=>'Missing required fields (name, event condition, workflow)']); exit;
        }
        $conn->prepare("INSERT INTO hrms_triggers (name, description, trigger_type, conditions_json, workflow_id, created_by) VALUES (?,?,?,?,?,?)")
             ->execute([$name, $desc, $type, json_encode($conds), $wf_id, $uid]);
        echo json_encode(['ok'=>true,'id'=>(int)$conn->lastInsertId()]);
        exit;
    }

    // toggle active
    if ($action === 'toggle') {
        $id = (int)($b['id'] ?? 0);
        $active = (int)(!empty($b['active']));
        $where = $is_admin ? "id=?" : "id=? AND created_by={$uid}";
        $conn->prepare("UPDATE hrms_triggers SET is_active=? WHERE {$where}")->execute([$active, $id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // delete
    if ($action === 'delete') {
        $id = (int)($b['id'] ?? 0);
        $where = $is_admin ? "id=?" : "id=? AND created_by={$uid}";
        $conn->prepare("UPDATE hrms_triggers SET is_active=-1 WHERE {$where}")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    // manual fire
    if ($action === 'fire') {
        $id = (int)($b['id'] ?? 0);
        if (!$is_admin) {
            $own = $conn->prepare("SELECT id FROM hrms_triggers WHERE id=? AND created_by=? LIMIT 1");
            $own->execute([$id, $uid]);
            if (!$own->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Not authorised']); exit; }
        }
        require_once 'trigger_engine.php';
        $result = fireTriggerById($conn, $id, $uid, 'manual');
        echo json_encode($result); exit;
    }

    // fire a workflow directly on a target person
    if ($action === 'run_on_person') {
        $wf_id     = (int)($b['workflow_id']    ?? 0);
        $target_id = (int)($b['target_user_id'] ?? 0);
        if (!$wf_id || !$target_id) { echo json_encode(['ok'=>false,'msg'=>'Missing workflow_id or target_user_id']); exit; }
        require_once 'trigger_engine.php';
        $result = fireWorkflowOnPerson($conn, $wf_id, $target_id, $uid);
        echo json_encode($result); exit;
    }

    // get logs
    if ($action === 'logs') {
        $id   = (int)($b['id'] ?? 0);
        if (!$is_admin) {
            $own = $conn->prepare("SELECT id FROM hrms_triggers WHERE id=? AND created_by=? LIMIT 1");
            $own->execute([$id, $uid]);
            if (!$own->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Not authorised']); exit; }
        }
        $logs = $conn->prepare("SELECT l.*, u.name as fired_by_name FROM hrms_trigger_log l LEFT JOIN users u ON u.id=? WHERE l.trigger_id=? ORDER BY l.fired_at DESC LIMIT 20");
        $logs->execute([$uid, $id]);
        echo json_encode(['ok'=>true,'logs'=>$logs->fetchAll()]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Fetch triggers list ────────────────────────────────────────────────────
if (has_role('SUPER_ADMIN', 'DEPT_MANAGER')) {
    $triggers = $conn->query("
        SELECT t.*, u.name as creator_name, w.name as workflow_name,
               (SELECT COUNT(*) FROM hrms_trigger_log l WHERE l.trigger_id=t.id) as fire_count
        FROM hrms_triggers t
        JOIN users u ON u.id = t.created_by
        LEFT JOIN hrms_workflow_templates w ON w.id = t.workflow_id
        WHERE t.is_active > -1
        ORDER BY t.created_at DESC
    ")->fetchAll();
} else {
    // TEAM_LEAD, EMPLOYEE — own triggers only
    $stmt = $conn->prepare("
        SELECT t.*, u.name as creator_name, w.name as workflow_name,
               (SELECT COUNT(*) FROM hrms_trigger_log l WHERE l.trigger_id=t.id) as fire_count
        FROM hrms_triggers t
        JOIN users u ON u.id = t.created_by
        LEFT JOIN hrms_workflow_templates w ON w.id = t.workflow_id
        WHERE t.is_active > -1 AND t.created_by = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$uid]);
    $triggers = $stmt->fetchAll();
}

// Fetch published workflows for dropdown
$workflows = $conn->query("
    SELECT id, name FROM hrms_workflow_templates
    WHERE is_active=1 AND published_version > 0
    ORDER BY name
")->fetchAll();

include 'header.php';
?>

<style>
.trig-type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.trig-time     { background:#eff6ff; color:#2563eb; }
.trig-login    { background:#f0fdf4; color:#16a34a; }
.trig-task     { background:#fefce8; color:#ca8a04; }
.trig-status   { background:#fdf4ff; color:#9333ea; }

/* Detail drawer */
#trig-drawer {
    position: fixed; top: 0; right: -440px; width: 420px; height: 100vh;
    background: var(--card-bg); border-left: 1px solid var(--card-bdr);
    z-index: 1060; transition: right .25s; overflow-y: auto; padding: 24px;
    display: flex; flex-direction: column; gap: 16px;
}
#trig-drawer.open { right: 0; }
#trig-drawer h5 { font-weight: 700; margin: 0; }
.cond-block {
    background: var(--body-bg); border: 1px solid var(--card-bdr);
    border-radius: 8px; padding: 12px 14px;
}
.cond-block label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px; }
.cond-block input, .cond-block select {
    width: 100%; box-sizing: border-box; padding: 7px 10px;
    border: 1px solid var(--card-bdr); border-radius: 7px;
    background: var(--card-bg); color: var(--text-primary);
    font-size: 13px; font-family: var(--font);
}
.log-item { padding: 8px 12px; border: 1px solid var(--card-bdr); border-radius: 7px; margin-bottom: 6px; }
.log-item .log-status-fired    { color: #16a34a; font-weight: 700; font-size: 11px; }
.log-item .log-status-skipped  { color: #f59e0b; font-weight: 700; font-size: 11px; }
.log-item .log-status-failed   { color: #ef4444; font-weight: 700; font-size: 11px; }

/* Event pills */
.ev-pill {
    padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer;
    border: 1.5px solid var(--card-bdr); background: var(--card-bg); color: var(--text-secondary);
    transition: all .15s;
}
.ev-pill:hover  { border-color: var(--primary); color: var(--primary); }
.ev-pill.active { border-color: var(--primary); background: var(--primary); color: #fff; }

/* Flow toggle */
.flow-toggle { width:36px;height:20px;border-radius:20px;background:var(--card-bdr);position:relative;cursor:pointer;transition:background .2s;flex-shrink:0;display:inline-flex; }
.flow-toggle.on { background:#16a34a; }
.flow-knob { width:14px;height:14px;border-radius:50%;background:#fff;position:absolute;top:3px;left:3px;transition:left .2s;box-shadow:0 1px 4px rgba(0,0,0,.2); }
.flow-toggle.on .flow-knob { left:19px; }
</style>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0 fw-bold" style="color:var(--text-primary);">Triggers & Actions</h5>
        <div style="font-size:12px;color:var(--text-muted);">Define when to automatically run a workflow</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openDrawer()" style="border-radius:8px;font-weight:600;">
        <i class="bi bi-plus-lg me-1"></i> New Trigger
    </button>
</div>

<!-- Toolbar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <div style="position:relative;flex:1;min-width:180px;max-width:280px;">
        <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
        <input type="text" id="tr-search" placeholder="Search triggers..." oninput="filterTriggers()"
            style="width:100%;padding:7px 10px 7px 32px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
    </div>
    <select id="tr-type" onchange="filterTriggers()" style="padding:7px 10px;border:1px solid var(--card-bdr);border-radius:8px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-family:var(--font);">
        <option value="">All types</option>
        <option value="time">Time-based</option>
        <option value="login">On Login</option>
        <option value="task_created">Task Created</option>
        <option value="task_status">Task Status Changed</option>
        <option value="task_assigned">Task Assigned</option>
        <option value="task_overdue">Task Overdue</option>
        <option value="task_completed">Task Completed</option>
    </select>
</div>

<!-- Table -->
<div class="card" style="border-radius:var(--r);overflow:hidden;">
<div class="table-responsive">
<table class="table table-hover mb-0">
    <thead style="background:var(--body-bg);border-bottom:2px solid var(--card-bdr);">
        <tr>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Name</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Trigger</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Runs Workflow</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Fired</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Last fired</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Active</th>
            <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;">Actions</th>
        </tr>
    </thead>
    <tbody id="tr-tbody">
    <?php if (!$triggers): ?>
    <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted);">
        <i class="bi bi-lightning" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
        No triggers yet. <a href="#" onclick="openDrawer()">Create one</a>
    </td></tr>
    <?php else: ?>
    <?php foreach ($triggers as $tr): ?>
    <?php
        $conds = json_decode($tr['conditions_json'] ?? '{}', true) ?? [];
        $type  = $tr['trigger_type'];
        $badge_class = match(true) {
            $type === 'time'  => 'trig-time',
            $type === 'login' => 'trig-login',
            str_starts_with($type, 'task') => 'trig-task',
            default => 'trig-task'
        };
        $type_labels = [
            'time'           => '🕐 Time-based',
            'login'          => '🔐 On Login',
            'task_created'   => '📋 Task Created',
            'task_status'    => '🔄 Status Changed',
            'task_assigned'  => '👤 Task Assigned',
            'task_overdue'   => '⏰ Task Overdue',
            'task_completed' => '✅ Task Completed',
        ];
        $type_label = $type_labels[$type] ?? $type;
        $cond_summary = '';
        $cond_rows = $conds['conditions'] ?? [];
        $cond_logic = $conds['logic'] ?? 'AND';
        if ($cond_rows) {
            $repeat_labels = ['daily'=>'Every day','weekdays'=>'Weekdays','working_days'=>'Working days','weekly'=>'Every week','monthly'=>'Monthly'];
            $parts = [];
            foreach ($cond_rows as $cr) {
                $f = $cr['field'] ?? '';
                $op = $cr['operator'] ?? '==';
                $v  = $cr['value'] ?? '';
                if ($f === 'event_time') {
                    $times  = $cr['times'] ?? [];
                    $repeat = $cr['repeat'] ?? 'daily';
                    $parts[] = '⏰ '.implode('/', $times).' · '.($repeat_labels[$repeat] ?? $repeat);
                } elseif ($f === 'event_task_status') {
                    $parts[] = '🔄 status→'.$v;
                } elseif (str_starts_with($f, 'event_')) {
                    $parts[] = str_replace(['event_','_'], ['',''], $f);
                } else {
                    $parts[] = $f.' '.$op.' '.$v;
                }
            }
            $cond_summary = implode(' '.$cond_logic.' ', $parts);
        }
        // Fallback for old format
        if (!$cond_summary && $type === 'time' && !empty($conds['time'])) {
            $cond_summary = $conds['time'].' IST';
        }
        if (!$cond_summary && $type === 'task_status' && !empty($conds['status'])) {
            $cond_summary = 'status = '.$conds['status'];
        }
    ?>
    <tr class="tr-row" id="tr-row-<?= $tr['id'] ?>"
        data-name="<?= strtolower(sanitize($tr['name'])) ?>"
        data-type="<?= $type ?>">
        <td style="padding:12px 16px;">
            <div style="font-weight:600;font-size:13px;color:var(--text-primary);"><?= sanitize($tr['name']) ?></div>
            <?php if ($tr['description']): ?><div style="font-size:11px;color:var(--text-muted);"><?= sanitize($tr['description']) ?></div><?php endif; ?>
        </td>
        <td style="padding:12px 16px;">
            <span class="trig-type-badge <?= $badge_class ?>"><?= $type_label ?></span>
            <?php if ($cond_summary): ?><div style="font-size:11px;color:var(--text-muted);margin-top:3px;"><?= sanitize($cond_summary) ?></div><?php endif; ?>
        </td>
        <td style="padding:12px 16px;">
            <a href="workflows.php#<?= $tr['workflow_id'] ?>" style="font-size:13px;color:var(--primary);font-weight:500;">
                <i class="bi bi-diagram-3 me-1"></i><?= sanitize($tr['workflow_name'] ?? '—') ?>
            </a>
        </td>
        <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);"><?= $tr['fire_count'] ?>×</td>
        <td style="padding:12px 16px;font-size:12px;color:var(--text-muted);">
            <?= $tr['last_fired_at'] ? date('d M, h:i A', strtotime($tr['last_fired_at'])) : '—' ?>
        </td>
        <td style="padding:12px 16px;" onclick="event.stopPropagation()">
            <div class="flow-toggle <?= $tr['is_active'] ? 'on' : '' ?>" onclick="toggleTrigger(<?= $tr['id'] ?>, this)">
                <div class="flow-knob"></div>
            </div>
        </td>
        <td style="padding:12px 16px;" onclick="event.stopPropagation()">
            <div class="d-flex gap-2">
                <button class="btn btn-sm" onclick="fireTrigger(<?= $tr['id'] ?>)"
                    style="font-size:11px;border-radius:6px;background:#16a34a;color:#fff;border:none;" title="Run now">
                    <i class="bi bi-play-fill"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="viewLogs(<?= $tr['id'] ?>)"
                    style="font-size:11px;border-radius:6px;" title="View logs">
                    <i class="bi bi-clock-history"></i>
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="deleteTrigger(<?= $tr['id'] ?>)"
                    style="font-size:11px;border-radius:6px;">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>

<!-- ── Create Drawer ────────────────────────────────────────────────────── -->
<div id="trig-drawer">
    <div class="d-flex align-items-center justify-content-between">
        <h5>New Trigger</h5>
        <button onclick="closeDrawer()" style="background:none;border:none;font-size:1.3rem;color:var(--text-muted);cursor:pointer;"><i class="bi bi-x"></i></button>
    </div>

    <div class="cond-block">
        <label>Trigger Name *</label>
        <input type="text" id="tr-name" placeholder="e.g. Morning Daily Tasks">
    </div>

    <div class="cond-block">
        <label>Description</label>
        <input type="text" id="tr-desc" placeholder="Optional">
    </div>

    <!-- STEP 1: Event (When) -->
    <div style="border:1.5px solid var(--primary);border-radius:10px;padding:14px 16px;background:var(--body-bg);">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--primary);margin-bottom:10px;">
            <i class="bi bi-lightning-charge-fill me-1"></i> WHEN — Trigger Event
        </div>

        <!-- Event type selector pills -->
        <div id="event-pills" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
            <button type="button" class="ev-pill" data-event="time"           onclick="selectEvent(this)">🕐 At a time</button>
            <button type="button" class="ev-pill" data-event="login"          onclick="selectEvent(this)">🔐 On login</button>
            <button type="button" class="ev-pill" data-event="task_created"   onclick="selectEvent(this)">📋 Task created</button>
            <button type="button" class="ev-pill" data-event="task_assigned"  onclick="selectEvent(this)">👤 Task assigned</button>
            <button type="button" class="ev-pill" data-event="task_completed" onclick="selectEvent(this)">✅ Task completed</button>
            <button type="button" class="ev-pill" data-event="task_overdue"   onclick="selectEvent(this)">⏰ Task overdue</button>
            <button type="button" class="ev-pill" data-event="task_status"    onclick="selectEvent(this)">🔄 Status changes to</button>
        </div>

        <!-- Event detail (shown after picking) -->
        <div id="event-detail" style="display:none;"></div>
        <div id="event-hint" style="font-size:11px;color:#ef4444;display:none;margin-top:4px;"><i class="bi bi-exclamation-circle me-1"></i>Please select a trigger event.</div>
    </div>

    <!-- STEP 2: Conditions (Only if) -->
    <div style="border:1px solid var(--card-bdr);border-radius:10px;padding:14px 16px;background:var(--body-bg);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);">
                <i class="bi bi-funnel me-1"></i> ONLY IF — Conditions <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:11px;color:var(--text-muted);font-weight:600;">Match:</span>
                <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:4px;font-weight:600;color:var(--text-secondary);">
                    <input type="radio" name="cond-logic" value="AND" checked> ALL
                </label>
                <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:4px;font-weight:600;color:var(--text-secondary);">
                    <input type="radio" name="cond-logic" value="OR"> ANY
                </label>
            </div>
        </div>
        <div id="cond-list" style="display:flex;flex-direction:column;gap:6px;"></div>
        <button type="button" onclick="addCondRow()"
            style="margin-top:6px;background:none;border:1px dashed var(--card-bdr);border-radius:7px;padding:7px 12px;color:var(--primary);font-size:12px;font-weight:600;cursor:pointer;width:100%;">
            + Add condition
        </button>
    </div>

    <!-- THEN -->
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);">THEN run this workflow</div>

    <div class="cond-block">
        <label>Workflow (published only) *</label>
        <select id="tr-workflow">
            <option value="">— Select workflow —</option>
            <?php foreach ($workflows as $wf): ?>
            <option value="<?= $wf['id'] ?>"><?= sanitize($wf['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!$workflows): ?>
        <div style="font-size:11px;color:#ef4444;margin-top:4px;"><i class="bi bi-exclamation-triangle me-1"></i>No published workflows. Publish one first.</div>
        <?php endif; ?>
    </div>

    <button class="btn btn-primary w-100" onclick="createTrigger()" style="border-radius:8px;font-weight:600;">
        <i class="bi bi-lightning-fill me-1"></i> Create Trigger
    </button>
</div>

<!-- ── Log Drawer ──────────────────────────────────────────────────────── -->
<div id="log-drawer" style="position:fixed;top:0;right:-440px;width:420px;height:100vh;background:var(--card-bg);border-left:1px solid var(--card-bdr);z-index:1070;transition:right .25s;overflow-y:auto;padding:24px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0 fw-bold">Fire Log</h5>
        <button onclick="closeLogDrawer()" style="background:none;border:none;font-size:1.3rem;color:var(--text-muted);cursor:pointer;"><i class="bi bi-x"></i></button>
    </div>
    <div id="log-list"></div>
</div>

<script>
// ── Filter ────────────────────────────────────────────────────────────────
function filterTriggers() {
    const q    = (document.getElementById('tr-search')?.value || '').toLowerCase();
    const type = document.getElementById('tr-type')?.value || '';
    document.querySelectorAll('.tr-row').forEach(r => {
        const nm = r.dataset.name.includes(q);
        const tp = !type || r.dataset.type === type;
        r.style.display = (nm && tp) ? '' : 'none';
    });
}

// ── Drawer ────────────────────────────────────────────────────────────────
function closeDrawer() { document.getElementById('trig-drawer').classList.remove('open'); }

// ── Event selection ───────────────────────────────────────────────────────
const SEL_STYLE = 'padding:7px 8px;border:1px solid var(--card-bdr);border-radius:7px;background:var(--card-bg);color:var(--text-primary);font-size:12px;font-family:var(--font);width:100%;box-sizing:border-box;';

let _selectedEvent = null; // 'time' | 'login' | 'task_created' | etc.

function selectEvent(pill) {
    document.querySelectorAll('.ev-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    _selectedEvent = pill.dataset.event;
    document.getElementById('event-hint').style.display = 'none';
    _renderEventDetail(_selectedEvent);
}

function _renderEventDetail(type) {
    const detail = document.getElementById('event-detail');
    detail.style.display = '';

    if (type === 'time') {
        detail.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:8px;">
            <div>
                <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">REPEAT</div>
                <select id="ev-repeat" style="${SEL_STYLE}">
                    <option value="daily">Every day</option>
                    <option value="weekdays">Weekdays only (Mon–Fri)</option>
                    <option value="working_days">Working days (excl. holidays)</option>
                    <option value="weekly">Every week (Monday)</option>
                    <option value="monthly">Every month (1st)</option>
                </select>
            </div>
            <div>
                <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">AT TIME(S)</div>
                <div id="ev-times" style="display:flex;flex-direction:column;gap:5px;"></div>
                <button type="button" onclick="_addEvTime()"
                    style="margin-top:5px;background:none;border:1px dashed var(--card-bdr);border-radius:6px;padding:4px 10px;color:var(--primary);font-size:11px;font-weight:600;cursor:pointer;">
                    + Add time slot
                </button>
            </div>
        </div>`;
        _addEvTime();
    } else if (type === 'task_status') {
        detail.innerHTML = `
        <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">WHEN STATUS CHANGES TO</div>
            <select id="ev-status" style="${SEL_STYLE}">
                <option value="TODO">TODO</option>
                <option value="IN_PROGRESS">IN PROGRESS</option>
                <option value="REVIEW">REVIEW</option>
                <option value="BLOCKED">BLOCKED</option>
                <option value="DONE">DONE</option>
            </select>
        </div>`;
    } else {
        // simple events — nothing extra needed
        const labels = {
            login: 'Fires once per day the first time a user logs in.',
            task_created: 'Fires whenever a new task is created.',
            task_assigned: 'Fires whenever a task is assigned to someone.',
            task_completed: 'Fires whenever a task is marked as completed.',
            task_overdue: 'Fires when a task passes its due date (checked by cron).',
        };
        detail.innerHTML = `<div style="font-size:12px;color:var(--text-secondary);padding:4px 2px;"><i class="bi bi-info-circle me-1" style="color:var(--primary);"></i>${labels[type] || ''}</div>`;
    }
}

function _addEvTime() {
    const wrap = document.getElementById('ev-times');
    const div  = document.createElement('div');
    div.style.cssText = 'display:flex;align-items:center;gap:5px;';
    div.innerHTML = `
        <input type="time" class="ev-time-slot" value="09:00" style="${SEL_STYLE}flex:1;">
        <button type="button" onclick="if(document.querySelectorAll('.ev-time-slot').length>1)this.closest('div').remove();"
            style="background:none;border:none;color:#ef4444;font-size:16px;cursor:pointer;padding:0 3px;line-height:1;">×</button>`;
    wrap.appendChild(div);
}

// ── Condition rows (Only if) ───────────────────────────────────────────────
const COND_FIELDS = [
    { value:'task_status',     label:'📌 Task status is',      kind:'select', opts:['TODO','IN_PROGRESS','REVIEW','BLOCKED','DONE'] },
    { value:'task_priority',   label:'🚦 Task priority is',    kind:'select', opts:['LOW','MEDIUM','HIGH','URGENT'] },
    { value:'day_of_week',     label:'📅 Day of week is',      kind:'select', opts:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] },
    { value:'is_working_day',  label:'🏢 Is a working day',    kind:'select', opts:['yes','no'] },
    { value:'repeat_schedule', label:'🔁 Repeat schedule is',  kind:'select', opts:['daily','weekdays','working_days','weekly','monthly'] },
];

function addCondRow() {
    const list = document.getElementById('cond-list');
    const div  = document.createElement('div');
    div.className = 'cond-row';
    div.style.cssText = 'background:var(--card-bg);border:1px solid var(--card-bdr);border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:7px;';

    const optHtml = COND_FIELDS.map(f =>
        `<option value="${f.value}" data-kind="${f.kind}" data-opts='${JSON.stringify(f.opts||[])}'>${f.label}</option>`
    ).join('');

    div.innerHTML = `
        <div style="display:flex;align-items:center;gap:6px;">
            <select class="cr-field" onchange="_renderCondRowDetail(this)" style="${SEL_STYLE}flex:1;">
                ${optHtml}
            </select>
            <button type="button" onclick="this.closest('.cond-row').remove()"
                style="background:none;border:none;color:#ef4444;font-size:18px;cursor:pointer;padding:0 4px;flex-shrink:0;line-height:1;">×</button>
        </div>
        <div class="cr-detail"></div>`;
    list.appendChild(div);
    _renderCondRowDetail(div.querySelector('.cr-field'));
}

function _renderCondRowDetail(sel) {
    const row    = sel.closest('.cond-row');
    const detail = row.querySelector('.cr-detail');
    const opt    = sel.options[sel.selectedIndex];
    const kind   = opt?.dataset.kind || 'select';
    const opts   = JSON.parse(opt?.dataset.opts || '[]');

    detail.innerHTML = `
    <div style="display:flex;gap:6px;align-items:center;">
        <select class="cr-op" style="${SEL_STYLE}max-width:64px;">
            <option value="==">=</option><option value="!=">≠</option>
        </select>
        <select class="cr-val" style="${SEL_STYLE}flex:1;">
            ${opts.map(o=>`<option>${o}</option>`).join('')}
        </select>
    </div>`;
}

// ── Build payload ─────────────────────────────────────────────────────────
function getConditions() {
    const logic = document.querySelector('input[name="cond-logic"]:checked')?.value || 'AND';
    const conditions = [];

    // Event row first
    if (_selectedEvent === 'time') {
        const repeat = document.getElementById('ev-repeat')?.value || 'daily';
        const times  = [...document.querySelectorAll('.ev-time-slot')].map(i=>i.value).filter(Boolean);
        conditions.push({ field:'event_time', operator:'occurs', value:'', repeat, times });
    } else if (_selectedEvent === 'task_status') {
        const status = document.getElementById('ev-status')?.value || 'DONE';
        conditions.push({ field:'event_task_status', operator:'occurs', value:status });
    } else if (_selectedEvent) {
        const fieldMap = {
            login:'event_login', task_created:'event_task_created',
            task_assigned:'event_task_assigned', task_overdue:'event_task_overdue',
            task_completed:'event_task_completed'
        };
        conditions.push({ field: fieldMap[_selectedEvent] || _selectedEvent, operator:'occurs', value:'' });
    }

    // Condition rows
    document.querySelectorAll('.cond-row').forEach(row => {
        const field = row.querySelector('.cr-field')?.value;
        const op    = row.querySelector('.cr-op')?.value  || '==';
        const val   = row.querySelector('.cr-val')?.value || '';
        if (field) conditions.push({ field, operator:op, value:val });
    });

    return { logic, conditions, trigger_type: _selectedEvent };
}

// ── Create trigger ────────────────────────────────────────────────────────
async function createTrigger() {
    const name  = document.getElementById('tr-name').value.trim();
    const desc  = document.getElementById('tr-desc').value.trim();
    const wf_id = document.getElementById('tr-workflow').value;

    if (!name) { showAlert('Trigger name is required.', 'warning', 'Missing Name'); return; }
    if (!_selectedEvent) {
        document.getElementById('event-hint').style.display = '';
        showAlert('Please select a trigger event first.', 'warning', 'No Event Selected');
        return;
    }
    document.getElementById('event-hint').style.display = 'none';
    if (!wf_id) { showAlert('Please select a workflow to run.', 'warning', 'No Workflow Selected'); return; }

    const conds = getConditions();
    const res   = await fetch('triggers.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'create', name, description:desc, trigger_type:_selectedEvent, conditions:conds, workflow_id:parseInt(wf_id) })
    });
    const data = await res.json();
    if (data.ok) { closeDrawer(); location.reload(); }
    else showAlert(data.msg, 'error', 'Could not create trigger');
}

function openDrawer() {
    _selectedEvent = null;
    document.getElementById('trig-drawer').classList.add('open');
    document.querySelectorAll('.ev-pill').forEach(p => p.classList.remove('active'));
    document.getElementById('event-detail').style.display = 'none';
    document.getElementById('event-hint').style.display = 'none';
    document.getElementById('cond-list').innerHTML = '';
    document.getElementById('tr-name').value = '';
    document.getElementById('tr-desc').value = '';
    document.getElementById('tr-workflow').value = '';
}

// ── Toggle active ─────────────────────────────────────────────────────────
async function toggleTrigger(id, el) {
    const isOn = el.classList.contains('on');
    const res  = await fetch('triggers.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'toggle', id, active:!isOn })
    });
    const data = await res.json();
    if (data.ok) { el.classList.toggle('on', !isOn); }
}

// ── Fire manually ─────────────────────────────────────────────────────────
async function fireTrigger(id) {
    const btn = event.currentTarget;
    btn.disabled = true;
    const res  = await fetch('triggers.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'fire', id })
    });
    const data = await res.json();
    btn.disabled = false;
    if (data.ok) {
        showToast('Workflow fired — tasks have been created.', 'success', 'Fired!');
        const cell = document.querySelector(`#tr-row-${id} td:nth-child(4)`);
        if (cell) cell.textContent = (parseInt(cell.textContent) + 1) + '×';
    } else showToast(data.msg || 'Failed to fire trigger.', 'error', 'Error');
}

// ── Delete ────────────────────────────────────────────────────────────────
async function deleteTrigger(id) {
    const ok = await showConfirm('This trigger will be permanently deleted.', { title:'Delete Trigger?', okText:'Delete', okColor:'#ef4444', icon:'🗑️' });
    if (!ok) return;
    const res  = await fetch('triggers.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'delete', id })
    });
    const data = await res.json();
    if (data.ok) { document.getElementById(`tr-row-${id}`)?.remove(); showToast('Trigger deleted.', 'success'); }
    else showToast(data.msg, 'error');
}

// ── View logs ─────────────────────────────────────────────────────────────
async function viewLogs(id) {
    document.getElementById('log-drawer').style.right = '0';
    document.getElementById('log-list').innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Loading...</div>';
    const res  = await fetch('triggers.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'logs', id })
    });
    const data = await res.json();
    const list = document.getElementById('log-list');
    if (!data.logs?.length) { list.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No logs yet.</div>'; return; }
    list.innerHTML = data.logs.map(l => `
        <div class="log-item">
            <div class="d-flex justify-content-between align-items-center">
                <span class="log-status-${l.status}">${l.status.toUpperCase()}</span>
                <span style="font-size:11px;color:var(--text-muted);">${new Date(l.fired_at).toLocaleString()}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:3px;">via ${l.fired_by}${l.notes ? ' · '+l.notes : ''}</div>
        </div>`).join('');
}
function closeLogDrawer() { document.getElementById('log-drawer').style.right = '-440px'; }
</script>

<?php include 'footer.php'; ?>

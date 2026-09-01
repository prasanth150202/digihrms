<?php
require_once 'config.php';
require_role('SUPER_ADMIN');
$page      = 'users';
$pageTitle = 'User Management';
$me = current_user();

// ── DELETE user ───────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id !== (int)$me['id']) {
        $conn->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        set_flash('success', 'User deleted.');
    }
    header("Location: users.php"); exit;
}

// ── CANCEL pending invite ─────────────────────────────────
if (isset($_GET['cancel_invite'])) {
    $id = (int)$_GET['cancel_invite'];
    $conn->prepare("DELETE FROM users WHERE id=? AND invite_status='PENDING'")->execute([$id]);
    set_flash('success', 'Invitation cancelled.');
    header("Location: users.php"); exit;
}

// ── REGENERATE invite link ────────────────────────────────
if (isset($_GET['new_link'])) {
    $id   = (int)$_GET['new_link'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND invite_status='PENDING'");
    $stmt->execute([$id]);
    $usr  = $stmt->fetch();
    if ($usr) {
        $token = bin2hex(random_bytes(32));
        $conn->prepare("UPDATE users SET invite_token=?, invite_expires=DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id=?")
             ->execute([$token, $id]);
        $base = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/';
        $_SESSION['show_invite_link'] = $base . 'invite.php?token=' . $token;
        $_SESSION['show_invite_name'] = $usr['name'];
    }
    header("Location: users.php"); exit;
}

// ── POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_user') {
        $name   = trim($_POST['name']);
        $email  = trim($_POST['email']);
        $role   = $_POST['role'];
        $emp_no = trim($_POST['emp_no'] ?? '');

        $chk = $conn->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            set_flash('danger', 'A user with this email already exists.');
            header("Location: users.php"); exit;
        }

        $token    = bin2hex(random_bytes(32));
        $expires  = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $tmp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        $conn->prepare("INSERT INTO users (name,email,password,role,emp_no,invite_token,invite_expires,invite_status)
                        VALUES (?,?,?,?,?,?,?,'PENDING')")
             ->execute([$name, $email, $tmp_pass, $role, $emp_no, $token, $expires]);

        $base = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/';
        $_SESSION['show_invite_link'] = $base . 'invite.php?token=' . $token;
        $_SESSION['show_invite_name'] = $name;
        header("Location: users.php"); exit;
    }

    if ($_POST['action'] === 'edit') {
        $id     = (int)$_POST['id'];
        $pass   = $_POST['password'] ?? '';
        $emp_no = trim($_POST['emp_no'] ?? '');
        if ($pass) {
            $conn->prepare("UPDATE users SET name=?,email=?,role=?,emp_no=?,password=? WHERE id=?")
                 ->execute([trim($_POST['name']), trim($_POST['email']), $_POST['role'], $emp_no, password_hash($pass, PASSWORD_DEFAULT), $id]);
        } else {
            $conn->prepare("UPDATE users SET name=?,email=?,role=?,emp_no=? WHERE id=?")
                 ->execute([trim($_POST['name']), trim($_POST['email']), $_POST['role'], $emp_no, $id]);
        }
        // AI Copilot beta access (column added by migrate_ai_agent.php)
        try {
            $conn->prepare("UPDATE users SET is_beta_tester=? WHERE id=?")
                 ->execute([isset($_POST['is_beta_tester']) ? 1 : 0, $id]);
        } catch (Exception $e) { /* migration not run yet */ }
        set_flash('success', 'User updated.');
        header("Location: users.php"); exit;
    }
}

// ── FETCH ─────────────────────────────────────────────────
$users   = $conn->query("SELECT * FROM users ORDER BY name ASC")->fetchAll();
$pending = array_filter($users, fn($u) => ($u['invite_status'] ?? 'ACTIVE') === 'PENDING');
$active  = array_filter($users, fn($u) => ($u['invite_status'] ?? 'ACTIVE') === 'ACTIVE');
$roles   = ['SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER','TEAM_LEAD','INTERVIEW_PANEL','EMPLOYEE'];
$rbg     = ['SUPER_ADMIN'=>'danger','HR_ADMIN'=>'info','TEAM_LEAD'=>'primary',
            'DEPT_MANAGER'=>'warning','EMPLOYEE'=>'secondary','INTERVIEW_PANEL'=>'dark'];
$role_labels = ['SUPER_ADMIN'=>'Super Admin','HR_ADMIN'=>'HR Admin','DEPT_MANAGER'=>'Dept Manager',
                'TEAM_LEAD'=>'Team Lead','INTERVIEW_PANEL'=>'Interview Panel','EMPLOYEE'=>'Employee'];

$invite_link = $_SESSION['show_invite_link'] ?? null;
$invite_name = $_SESSION['show_invite_name'] ?? null;
unset($_SESSION['show_invite_link'], $_SESSION['show_invite_name']);

include 'header.php';
?>

<style>
/* ── User Management ─────────────────────────────────────────── */
.um-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: .85rem; flex-shrink: 0;
    letter-spacing: -.5px;
}
.um-avatar-lg {
    width: 48px; height: 48px; font-size: 1rem;
}

/* Role badge — consistent colour system */
.role-badge {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 20px;
    font-size: .65rem; font-weight: 700; white-space: nowrap;
}
.role-SUPER_ADMIN   { background: #fee2e2; color: #991b1b; }
.role-HR_ADMIN      { background: #dbeafe; color: #1d4ed8; }
.role-DEPT_MANAGER  { background: #fef3c7; color: #92400e; }
.role-TEAM_LEAD     { background: #ede9fe; color: #5b21b6; }
.role-INTERVIEW_PANEL { background: #f0fdfa; color: #0f766e; }
.role-EMPLOYEE      { background: #f1f5f9; color: #475569; }
[data-theme="dark"] .role-SUPER_ADMIN   { background: rgba(239,68,68,.15);   color: #f87171; }
[data-theme="dark"] .role-HR_ADMIN      { background: rgba(59,130,246,.15);  color: #60a5fa; }
[data-theme="dark"] .role-DEPT_MANAGER  { background: rgba(245,158,11,.15);  color: #fbbf24; }
[data-theme="dark"] .role-TEAM_LEAD     { background: rgba(139,92,246,.15);  color: #a78bfa; }
[data-theme="dark"] .role-INTERVIEW_PANEL { background: rgba(20,184,166,.15); color: #2dd4bf; }
[data-theme="dark"] .role-EMPLOYEE      { background: rgba(148,163,184,.12); color: #94a3b8; }

/* User cards grid */
.um-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 14px;
}
.um-card {
    background: var(--card-bg);
    border: 1px solid var(--card-bdr);
    border-radius: 14px;
    overflow: hidden;
    display: flex; flex-direction: column;
    transition: box-shadow .15s, transform .12s;
}
.um-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); transform: translateY(-1px); }
.um-card-body { padding: 16px 18px; flex: 1; }
.um-card-footer {
    padding: 10px 14px;
    border-top: 1px solid var(--card-bdr);
    background: var(--body-bg);
    display: flex; gap: 8px;
}

/* Pending invite rows */
.inv-card {
    background: var(--card-bg);
    border: 1px solid var(--card-bdr);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap;
    border-left: 4px solid #fde68a;
}
.inv-card.expired { border-left-color: #fca5a5; }

.inv-status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: .65rem; font-weight: 700; white-space: nowrap;
}
.inv-status-pending { background: #fef3c7; color: #92400e; }
.inv-status-expired { background: #fee2e2; color: #991b1b; }

/* Section heading */
.um-section-head {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 14px; flex-wrap: wrap;
}
.um-section-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0;
}

/* You badge */
.you-badge {
    display: inline-flex; align-items: center;
    padding: 1px 7px; border-radius: 20px;
    font-size: .6rem; font-weight: 700;
    background: var(--primary-bg); color: var(--primary-d);
    border: 1px solid var(--primary-bdr);
}

@media (max-width: 768px) {
    .um-grid { grid-template-columns: 1fr; }
    .inv-card { gap: 10px; }
}
</style>

<?php if ($invite_link): ?>
<div class="modal fade" id="inviteLinkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span style="width:36px;height:36px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-check-lg text-success fs-5"></i>
                        </span>
                        <h5 class="modal-title fw-bold mb-0">User Created!</h5>
                    </div>
                    <p class="text-muted small mb-0">Share this invite link with <strong><?= sanitize($invite_name) ?></strong></p>
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3">
                <div class="p-3 rounded-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                    <div class="small text-muted mb-2"><i class="bi bi-clock me-1"></i>Link expires in <strong>48 hours</strong> · Employee sets own password</div>
                    <div class="input-group">
                        <input type="text" id="inviteLinkInput" class="form-control form-control-sm font-monospace"
                               value="<?= sanitize($invite_link) ?>" readonly style="background:#fff;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="copyInviteLink()" id="copyBtn">
                            <i class="bi bi-clipboard me-1" id="copyIcon"></i>Copy
                        </button>
                    </div>
                    <div id="copyMsg" class="text-success small mt-2" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i>Copied to clipboard!
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="copyInviteLink()">
                    <i class="bi bi-clipboard me-1"></i>Copy Link
                </button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('inviteLinkModal')).show();
});
function copyInviteLink() {
    navigator.clipboard.writeText(document.getElementById('inviteLinkInput').value).then(function() {
        document.getElementById('copyIcon').className = 'bi bi-check-lg me-1';
        document.getElementById('copyMsg').style.display = 'block';
        setTimeout(function() {
            document.getElementById('copyIcon').className = 'bi bi-clipboard me-1';
            document.getElementById('copyMsg').style.display = 'none';
        }, 3000);
    });
}
</script>
<?php endif; ?>

<?php $admins = array_filter($active, fn($u) => in_array($u['role'], ['SUPER_ADMIN','HR_ADMIN'])); ?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <p class="text-muted small mb-0">Manage system accounts, invite new members, and assign roles.</p>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-1"></i>Invite User
    </button>
</div>

<!-- Stat cards -->
<div class="hrms-stat-grid hrms-stat-grid-4" style="margin-bottom:24px;">
    <div class="hrms-stat-card c-blue">
        <div class="hrms-stat-icon"><i class="bi bi-people-fill"></i></div>
        <div class="hrms-stat-label">Active Users</div>
        <div class="hrms-stat-value"><?= count($active) ?></div>
    </div>
    <div class="hrms-stat-card c-amber">
        <div class="hrms-stat-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="hrms-stat-label">Pending Invites</div>
        <div class="hrms-stat-value"><?= count($pending) ?></div>
    </div>
    <div class="hrms-stat-card c-red">
        <div class="hrms-stat-icon"><i class="bi bi-shield-fill"></i></div>
        <div class="hrms-stat-label">Admins</div>
        <div class="hrms-stat-value"><?= count($admins) ?></div>
    </div>
    <div class="hrms-stat-card c-green">
        <div class="hrms-stat-icon"><i class="bi bi-person-check-fill"></i></div>
        <div class="hrms-stat-label">Total Accounts</div>
        <div class="hrms-stat-value"><?= count($users) ?></div>
    </div>
</div>

<!-- Pending Invitations -->
<?php if ($pending): ?>
<div class="mb-4">
    <div class="um-section-head">
        <div class="um-section-icon" style="background:#fef3c7;">
            <i class="bi bi-hourglass-split" style="color:#92400e;"></i>
        </div>
        <span class="fw-bold" style="font-size:.9rem;color:var(--text-primary);">Pending Invitations</span>
        <span class="role-badge role-DEPT_MANAGER"><?= count($pending) ?></span>
    </div>
    <div class="d-flex flex-column gap-2">
    <?php foreach ($pending as $usr):
        $expired  = $usr['invite_expires'] && strtotime($usr['invite_expires']) < time();
        $initials = strtoupper(substr($usr['name'], 0, 2));
        $avatarBg = $expired ? 'linear-gradient(135deg,#94a3b8,#64748b)' : 'linear-gradient(135deg,#f59e0b,#d97706)';
    ?>
    <div class="inv-card <?= $expired ? 'expired' : '' ?>">
        <div class="um-avatar" style="background:<?= $avatarBg ?>;"><?= $initials ?></div>

        <div class="flex-grow-1" style="min-width:0;">
            <div style="font-size:.85rem;font-weight:700;color:var(--text-primary);"><?= sanitize($usr['name']) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);"><?= sanitize($usr['email']) ?></div>
            <?php if ($usr['emp_no']): ?>
            <div style="font-size:.68rem;color:var(--text-muted);margin-top:1px;"><i class="bi bi-hash"></i><?= sanitize($usr['emp_no']) ?></div>
            <?php endif; ?>
        </div>

        <span class="role-badge role-<?= $usr['role'] ?>"><?= $role_labels[$usr['role']] ?? $usr['role'] ?></span>

        <span class="inv-status-pill <?= $expired ? 'inv-status-expired' : 'inv-status-pending' ?>">
            <i class="bi bi-<?= $expired ? 'x-circle' : 'clock' ?>"></i>
            <?= $expired ? 'Expired' : 'Expires ' . date('d M, h:i A', strtotime($usr['invite_expires'])) ?>
        </span>

        <div class="d-flex gap-1 flex-shrink-0">
            <a href="?new_link=<?= $usr['id'] ?>" class="btn btn-sm" title="Regenerate link"
               style="background:var(--primary-bg);color:var(--primary-d);border:1px solid var(--primary-bdr);">
                <i class="bi bi-arrow-clockwise"></i>
            </a>
            <button type="button" class="btn btn-sm" title="Edit"
                style="background:var(--card-bg);color:var(--text-secondary);border:1px solid var(--card-bdr);"
                onclick="openEdit(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES) ?>)">
                <i class="bi bi-pencil"></i>
            </button>
            <a href="?cancel_invite=<?= $usr['id'] ?>" class="btn btn-sm" title="Cancel invite"
               style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;"
               onclick="return hConfirmSync(event,'Cancel invite for <?= sanitize($usr['name']) ?>?')">
                <i class="bi bi-trash3"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Active Users -->
<div class="um-section-head">
    <div class="um-section-icon" style="background:#dcfce7;">
        <i class="bi bi-people-fill" style="color:#166534;"></i>
    </div>
    <span class="fw-bold" style="font-size:.9rem;color:var(--text-primary);">Active Users</span>
    <span class="role-badge role-EMPLOYEE"><?= count($active) ?></span>
    <div class="ms-auto" style="max-width:240px;width:100%;">
        <div class="position-relative">
            <i class="bi bi-search position-absolute" style="left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.75rem;pointer-events:none;"></i>
            <input type="text" id="userSearch" class="form-control form-control-sm ps-4"
                   placeholder="Search by name or email…" oninput="filterUsers()">
        </div>
    </div>
</div>

<div class="um-grid" id="userGrid">
<?php if (!$active): ?>
    <div class="text-center py-5" style="grid-column:1/-1;color:var(--text-muted);">
        <i class="bi bi-people" style="font-size:3rem;opacity:.25;"></i>
        <div class="fw-semibold mt-3">No active users yet</div>
        <div class="small mt-1">Invite your first team member above.</div>
    </div>
<?php endif; ?>
<?php foreach ($active as $usr):
    $initials = strtoupper(substr($usr['name'], 0, 2));
    $colors   = ['#3b82f6','#047857','#7c3aed','#be185d','#b45309','#0369a1','#dc2626','#0891b2'];
    $color    = $colors[abs(crc32($usr['email'])) % count($colors)];
    $isMe     = $usr['id'] == $me['id'];
?>
<div class="um-card user-card"
     data-name="<?= strtolower(sanitize($usr['name'])) ?>"
     data-email="<?= strtolower(sanitize($usr['email'])) ?>">

    <div class="um-card-body">
        <div class="d-flex align-items-start gap-3">
            <div class="um-avatar um-avatar-lg" style="background:linear-gradient(135deg,<?= $color ?>,<?= $color ?>bb);">
                <?= $initials ?>
            </div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <span style="font-size:.88rem;font-weight:700;color:var(--text-primary);"><?= sanitize($usr['name']) ?></span>
                    <?php if ($isMe): ?><span class="you-badge">You</span><?php endif; ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:6px;"><?= sanitize($usr['email']) ?></div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="role-badge role-<?= $usr['role'] ?>"><?= $role_labels[$usr['role']] ?? $usr['role'] ?></span>
                    <?php if ($usr['emp_no']): ?>
                    <span style="font-size:.68rem;color:var(--text-muted);"><i class="bi bi-hash"></i><?= sanitize($usr['emp_no']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="um-card-footer">
        <button type="button" class="btn btn-sm flex-fill"
            style="background:var(--primary-bg);color:var(--primary-d);border:1px solid var(--primary-bdr);"
            onclick="openEdit(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES) ?>)">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
        <?php if (!$isMe): ?>
        <a href="?delete=<?= $usr['id'] ?>" class="btn btn-sm"
           style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;"
           onclick="return hConfirmSync(event,'Delete <?= sanitize($usr['name']) ?>? This cannot be undone.')">
            <i class="bi bi-trash3"></i>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── ADD USER MODAL ─────────────────────────────────────── -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="add_user">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold mb-0">Invite New User</h5>
                    <p class="text-muted small mb-0">An invite link will be generated for them to set a password.</p>
                </div>
                <button type="button" class="btn-close ms-4" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Ravi Kumar">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Employee No</label>
                        <input type="text" name="emp_no" class="form-control" placeholder="e.g. 1001">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="ravi@company.com">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">System Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select">
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r ?>" <?= $r === 'EMPLOYEE' ? 'selected' : '' ?>><?= $role_labels[$r] ?? $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-link-45deg me-1"></i>Create & Get Link
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT USER MODAL ────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_uid">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold mb-0">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Employee No</label>
                        <input type="text" name="emp_no" id="edit_emp_no" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">System Role</label>
                        <select name="role" id="edit_role" class="form-select">
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r ?>"><?= $role_labels[$r] ?? $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">
                            New Password <span class="text-muted fw-normal">(leave blank to keep current)</span>
                        </label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_beta_tester" id="edit_is_beta" value="1">
                            <label class="form-check-label small" for="edit_is_beta">
                                <i class="bi bi-stars text-primary me-1"></i>AI Copilot beta
                                <span class="text-muted fw-normal d-block" style="font-size:.72rem;">
                                    Tech team only. Grants the in-app AI agent that can read and modify the HRMS database directly.
                                </span>
                            </label>
                        </div>
                    </div>
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
function openEdit(u) {
    document.getElementById('edit_uid').value    = u.id;
    document.getElementById('edit_name').value   = u.name;
    document.getElementById('edit_email').value  = u.email;
    document.getElementById('edit_role').value   = u.role;
    document.getElementById('edit_emp_no').value = u.emp_no || '';
    document.getElementById('edit_is_beta').checked = u.is_beta_tester == 1;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function filterUsers() {
    var q = document.getElementById('userSearch').value.toLowerCase();
    document.querySelectorAll('.user-card').forEach(function(c) {
        var name  = c.getAttribute('data-name') || '';
        var email = c.getAttribute('data-email') || '';
        c.style.display = (!q || name.includes(q) || email.includes(q)) ? '' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>

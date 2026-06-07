<?php
require_once 'config.php';
require_login();

$u = current_user();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All fields are required.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirm password do not match.';
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $conn->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $u['id']]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'Change Password';
include 'header.php';
?>

<nav class="mb-3" style="font-size:13px;">
    <span class="text-muted">Account</span>
    <span class="mx-1 text-muted">/</span>
    <span class="fw-semibold">Change Password</span>
</nav>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card border-0 shadow-sm p-4" style="border-radius:14px;">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#0f4c81,#1e88e5);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-shield-lock-fill text-white fs-5"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold">Change Password</h5>
                    <div class="text-muted small">Update your account password</div>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i><?= sanitize($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= sanitize($success) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Current Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
                        <input type="password" name="current_password" id="cp-current" class="form-control" placeholder="Enter current password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('cp-current','cp-eye0')"><i class="bi bi-eye" id="cp-eye0"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                        <input type="password" name="new_password" id="cp-new" class="form-control" placeholder="Min. 8 characters" required minlength="8">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('cp-new','cp-eye1')"><i class="bi bi-eye" id="cp-eye1"></i></button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                        <input type="password" name="confirm_password" id="cp-confirm" class="form-control" placeholder="Re-enter new password" required minlength="8">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('cp-confirm','cp-eye2')"><i class="bi bi-eye" id="cp-eye2"></i></button>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-check2-circle me-1"></i>Update Password
                    </button>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePwd(fieldId, iconId) {
    const f = document.getElementById(fieldId);
    const i = document.getElementById(iconId);
    if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; i.className = 'bi bi-eye'; }
}
</script>

<?php include 'footer.php'; ?>

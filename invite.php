<?php
require_once 'config.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if (!$token) { header("Location: login.php"); exit; }

// Fetch user by token
$stmt = $conn->prepare("SELECT * FROM users WHERE invite_token=? AND invite_status='PENDING'");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $error = 'This invitation link is invalid or has already been used.';
} elseif (strtotime($user['invite_expires']) < time()) {
    $error = 'This invitation link has expired. Please ask your admin to resend the invitation.';
}

// Handle password set
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $conn->prepare("UPDATE users SET password=?, invite_token=NULL, invite_expires=NULL, invite_status='ACTIVE' WHERE id=?")
             ->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation — Digifyce</title>
    <link rel="icon" type="image/png" href="assets/images/fav.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #0f4c81 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .invite-card { background: #fff; border-radius: 18px; padding: 40px; width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .brand-icon { width:52px;height:52px;background:linear-gradient(135deg,#0f4c81,#1e88e5);border-radius:12px;display:flex;align-items:center;justify-content:center; }
        .strength-bar { height:5px;border-radius:3px;transition:width 0.3s,background 0.3s; }
    </style>
</head>
<body>
<div class="invite-card">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="brand-icon" style="width:48px;height:48px;border-radius:12px;overflow:hidden;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;">
            <img src="assets/images/logo.jpg" alt="Digifyce Logo" style="width:48px;height:48px;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
            <i class="bi bi-people-fill text-white fs-5" style="display:none;"></i>
        </div>
        <div>
            <h5 class="fw-bold mb-0">Digifyce</h5>
            <div class="text-muted small">Invitation</div>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= sanitize($error) ?></div>
    <a href="login.php" class="btn btn-outline-primary w-100">Go to Login</a>

    <?php elseif ($success): ?>
    <div class="text-center py-3">
        <div style="width:64px;height:64px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="bi bi-check-lg text-success" style="font-size:1.8rem;"></i>
        </div>
        <h5 class="fw-bold">Account Activated!</h5>
        <p class="text-muted small">Your password has been set. You can now log in to Digifyce.</p>
        <a href="login.php" class="btn btn-primary w-100 mt-2"><i class="bi bi-box-arrow-in-right me-1"></i>Go to Login</a>
    </div>

    <?php else: ?>
    <div class="mb-4">
        <h5 class="fw-bold mb-1">Welcome, <?= sanitize($user['name']) ?>!</h5>
        <p class="text-muted small mb-0">You've been invited as <strong><?= sanitize($user['role']) ?></strong>. Set your password to activate your account.</p>
    </div>

    <div class="p-3 rounded-3 mb-4" style="background:#f1f5f9;">
        <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?= sanitize($user['email']) ?></div>
        <div class="small text-muted mt-1"><i class="bi bi-shield me-1"></i><?= sanitize($user['role']) ?></div>
    </div>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label small fw-semibold">New Password *</label>
            <div class="input-group">
                <input type="password" name="password" id="pw" class="form-control" required minlength="8"
                       placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw',this)">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="mt-2">
                <div class="progress" style="height:5px;">
                    <div id="strength-bar" class="progress-bar strength-bar" style="width:0%;"></div>
                </div>
                <div id="strength-label" class="text-muted mt-1" style="font-size:0.7rem;"></div>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label small fw-semibold">Confirm Password *</label>
            <div class="input-group">
                <input type="password" name="password2" id="pw2" class="form-control" required placeholder="Repeat password">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw2',this)">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check-circle me-1"></i> Activate Account
        </button>
    </form>
    <?php endif; ?>

</div>

<script>
function togglePw(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.innerHTML = el.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}
function checkStrength(val) {
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const bar   = document.getElementById('strength-bar');
    const label = document.getElementById('strength-label');
    const levels = [
        { w:'20%', c:'bg-danger',  t:'Very Weak' },
        { w:'40%', c:'bg-danger',  t:'Weak' },
        { w:'60%', c:'bg-warning', t:'Fair' },
        { w:'80%', c:'bg-info',    t:'Strong' },
        { w:'100%',c:'bg-success', t:'Very Strong' },
    ];
    const l = levels[Math.max(0, score - 1)] || levels[0];
    bar.style.width = val.length ? l.w : '0%';
    bar.className   = 'progress-bar strength-bar ' + (val.length ? l.c : '');
    label.textContent = val.length ? l.t : '';
}
</script>
</body>
</html>

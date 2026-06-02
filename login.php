<?php
require_once 'config.php';

if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password']) && ($user['invite_status'] ?? 'ACTIVE') === 'ACTIVE') {
            $_SESSION['user'] = [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Please enter email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digifyce – Login</title>
    <link rel="icon" type="image/png" href="assets/images/fav.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #0f4c81 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: #fff; border-radius: 16px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); }
        .brand-logo { width: 52px; height: 52px; background: linear-gradient(135deg, #0f4c81, #1e88e5); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
        .form-control:focus { border-color: #0f4c81; box-shadow: 0 0 0 3px rgba(15,76,129,0.15); }
        .btn-login { background: linear-gradient(135deg, #0f4c81, #1e88e5); border: none; padding: 12px; font-weight: 600; letter-spacing: 0.5px; }
        .btn-login:hover { opacity: 0.9; }
        .input-group-text { background: #f8fafc; border-right: none; }
        .form-control { border-left: none; }
        .form-control:focus { border-left: none; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="brand-logo" style="width:72px;height:72px;border-radius:14px;overflow:hidden;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;background:linear-gradient(135deg,#0f4c81,#1e88e5);">
        <img src="assets/images/logo.jpg" alt="Digifyce Logo" style="width:72px;height:72px;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
        <i class="bi bi-people-fill text-white fs-4" style="display:none;"></i>
    </div>
    <h4 class="text-center fw-bold mb-1">Digifyce</h4>
    <p class="text-center text-muted small mb-4">Human Resource Management System</p>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-sm py-2 small"><i class="bi bi-exclamation-circle me-1"></i><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="mb-3">
            <label class="form-label fw-semibold small">Email Address</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope text-muted"></i></span>
                <input type="email" name="email" class="form-control" placeholder="you@company.com" value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold small">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" name="password" id="passwordField" class="form-control" placeholder="••••••••" required>
                <button type="button" class="btn btn-outline-secondary border-start-0" onclick="togglePwd()"><i class="bi bi-eye" id="eyeIcon"></i></button>
            </div>
        </div>
        <button type="submit" class="btn btn-login btn-primary w-100 text-white">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
        </button>
    </form>

    <div class="text-center mt-4">
        <small class="text-muted">First time? <a href="setup.php" class="text-primary">Setup Admin Account</a></small>
    </div>
</div>
<script>
function togglePwd() {
    const f = document.getElementById('passwordField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; i.className = 'bi bi-eye'; }
}
</script>
</body>
</html>

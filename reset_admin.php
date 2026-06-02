<?php
require_once 'config.php';

$email    = "admin@hrms.com";
$password = "Admin@1234";
$hash     = password_hash($password, PASSWORD_DEFAULT);

// Update password for existing admin
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->execute([$hash, $email]);

// Also verify it works
$check = $conn->prepare("SELECT * FROM users WHERE email = ?");
$check->execute([$email]);
$user = $check->fetch();

$verify = $user ? password_verify($password, $user['password']) : false;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="card shadow border-0 p-5 text-center" style="border-radius:16px;max-width:440px;width:100%;">

    <?php if ($verify): ?>
        <div style="font-size:3rem;">✅</div>
        <h4 class="fw-bold mt-2">Password Reset Successful</h4>
        <p class="text-muted">Hash verified — login will work now.</p>
        <table class="table table-bordered text-start mt-3">
            <tr><th>Email</th><td><code><?= $email ?></code></td></tr>
            <tr><th>Password</th><td><code><?= $password ?></code></td></tr>
        </table>
        <a href="login.php" class="btn btn-primary w-100 fw-semibold">Go to Login →</a>
    <?php else: ?>
        <div style="font-size:3rem;">❌</div>
        <h4 class="fw-bold mt-2 text-danger">Something went wrong</h4>
        <p>User not found or hash mismatch. Check your DB connection in config.php.</p>
    <?php endif; ?>

    <div class="alert alert-warning mt-4 text-start small mb-0">
        <strong>⚠️ Delete</strong> <code>reset_admin.php</code> from your server after logging in.
    </div>
</div>
</body>
</html>

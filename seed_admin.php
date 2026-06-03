<?php
require_once 'config.php';

$name     = "Super Admin";
$email    = "Admin@digifyce.com";
$password = "Digi2025#";
$role     = "SUPER_ADMIN";

// Check if already seeded
$exists = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$exists->execute([$email]);

if ($exists->fetchColumn() > 0) {
    echo "<div style='font-family:sans-serif;padding:40px;'>
        <h3 style='color:orange;'>⚠️ Admin already exists.</h3>
        <p>Email: <strong>$email</strong></p>
        <p><a href='login.php'>Go to Login</a></p>
        <p style='color:red;font-size:13px;'>Delete this file from your server.</p>
    </div>";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
$stmt->execute([$name, $email, $hash, $role]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Seeded</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="card shadow border-0 p-5 text-center" style="border-radius:16px;max-width:420px;width:100%;">
    <div style="font-size:3rem;">✅</div>
    <h4 class="fw-bold mt-2">Admin Account Created!</h4>
    <p class="text-muted mb-4">Use these credentials to login:</p>

    <table class="table table-bordered text-start">
        <tr><th>Email</th><td><code><?= $email ?></code></td></tr>
        <tr><th>Password</th><td><code><?= $password ?></code></td></tr>
        <tr><th>Role</th><td><span class="badge bg-danger"><?= $role ?></span></td></tr>
    </table>

    <a href="login.php" class="btn btn-primary w-100 mt-2 fw-semibold">Go to Login →</a>

    <div class="alert alert-warning mt-4 text-start small mb-0">
        <strong>⚠️ Security:</strong> Delete <code>seed_admin.php</code> from your server after logging in.
    </div>
</div>
</body>
</html>

<?php
require_once 'config.php';

$done = false;
$error = '';

// Block if any admin already exists
$existing = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'SUPER_ADMIN'")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $existing == 0) {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm'];

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'SUPER_ADMIN')");
        $stmt->execute([$name, $email, $hash]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digifyce – Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b, #0f4c81); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { border-radius: 16px; padding: 40px; max-width: 440px; width: 100%; box-shadow: 0 25px 60px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
<div class="card">
    <h4 class="fw-bold mb-1 text-center"><i class="bi bi-gear-fill me-2 text-primary"></i>First-Time Setup</h4>
    <p class="text-muted small text-center mb-4">Create your Super Admin account</p>

    <?php if ($existing > 0): ?>
        <div class="alert alert-warning"><i class="bi bi-shield-lock me-2"></i>Setup already completed. <a href="login.php">Go to Login</a></div>
    <?php elseif ($done): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Admin created! <a href="login.php">Login now</a><br><small class="text-danger">Delete setup.php from your server for security.</small></div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-danger small"><?= sanitize($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold small">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="John Doe" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Email</label>
                <input type="email" name="email" class="form-control" placeholder="admin@company.com" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm Password</label>
                <input type="password" name="confirm" class="form-control" placeholder="Repeat password" required>
            </div>
            <button class="btn btn-primary w-100 fw-semibold">Create Admin Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

<?php
require_once 'config.php';
require_role('SUPER_ADMIN');

$results = [];

function run_query(PDO $conn, string $label, string $sql): array {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        if (stripos(trim($sql), 'SELECT') === 0) {
            return ['label' => $label, 'type' => 'select', 'rows' => $stmt->fetchAll(), 'cols' => $stmt->columnCount()];
        }
        return ['label' => $label, 'type' => 'exec', 'affected' => $stmt->rowCount(), 'error' => null];
    } catch (PDOException $e) {
        return ['label' => $label, 'type' => 'error', 'message' => $e->getMessage()];
    }
}

$confirmed = isset($_POST['confirm']) && $_POST['confirm'] === 'YES_DELETE';

if ($confirmed) {
    $results[] = run_query($conn, 'Delete all test leaves',              "DELETE FROM leaves");
    $results[] = run_query($conn, 'Delete all permission requests',      "DELETE FROM permission_requests");
    $results[] = run_query($conn, 'Delete fake admin (smarthrms.com)',   "DELETE FROM users WHERE email = 'admin@smarthrms.com'");
    $results[] = run_query($conn, 'Delete old admin (hrms.com)',         "DELETE FROM users WHERE email = 'admin@hrms.com'");
}

// Always run diagnostics
$diag_users = run_query($conn, 'Users with no employee record',
    "SELECT u.id, u.name, u.email, u.role,
     CASE WHEN e.id IS NULL THEN 'NO EMPLOYEE RECORD' ELSE 'OK' END AS mapping_status
     FROM users u LEFT JOIN employees e ON e.email = u.email
     WHERE u.role NOT IN ('SUPER_ADMIN','HR_ADMIN')
     ORDER BY mapping_status DESC, u.name");

$diag_emps = run_query($conn, 'Active employees with no user account',
    "SELECT e.id, e.emp_code, e.name, e.email, e.status,
     CASE WHEN u.id IS NULL THEN 'NO USER ACCOUNT' ELSE 'OK' END AS login_status
     FROM employees e LEFT JOIN users u ON u.email = e.email
     WHERE e.status = 'ACTIVE'
     ORDER BY login_status DESC, e.name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Production Cleanup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-4">
<div class="container" style="max-width:860px;">

  <div class="card shadow border-0 mb-4">
    <div class="card-header bg-danger text-white fw-bold fs-5">
      Production Cleanup
    </div>
    <div class="card-body">

      <?php if (!$confirmed): ?>
      <div class="alert alert-warning fw-semibold">
        This will permanently delete all <strong>leave records</strong> and <strong>permission requests</strong>, and remove fake seed admin accounts. This cannot be undone.
      </div>
      <form method="POST">
        <input type="hidden" name="confirm" value="YES_DELETE">
        <button type="submit" class="btn btn-danger px-4">Yes, run cleanup</button>
        <a href="index.php" class="btn btn-secondary ms-2">Cancel</a>
      </form>

      <?php else: ?>
      <h6 class="fw-bold mb-3">Cleanup Results</h6>
      <?php foreach ($results as $r): ?>
        <?php if ($r['type'] === 'exec'): ?>
          <div class="d-flex align-items-center mb-2">
            <span class="badge bg-success me-2">OK</span>
            <strong><?= htmlspecialchars($r['label']) ?></strong>
            <span class="text-muted ms-2">&mdash; <?= $r['affected'] ?> row(s) affected</span>
          </div>
        <?php elseif ($r['type'] === 'error'): ?>
          <div class="d-flex align-items-center mb-2">
            <span class="badge bg-danger me-2">ERR</span>
            <strong><?= htmlspecialchars($r['label']) ?></strong>
            <span class="text-danger ms-2"><?= htmlspecialchars($r['message']) ?></span>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <a href="index.php" class="btn btn-primary mt-3">Go to Dashboard</a>
      <?php endif; ?>

    </div>
  </div>

  <!-- Diagnostic: Users ↔ Employees -->
  <?php foreach ([$diag_users, $diag_emps] as $diag): ?>
  <div class="card shadow border-0 mb-4">
    <div class="card-header fw-bold"><?= htmlspecialchars($diag['label']) ?></div>
    <div class="card-body p-0">
      <?php if (empty($diag['rows'])): ?>
        <p class="p-3 mb-0 text-success fw-semibold">All good — no issues found.</p>
      <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light">
            <tr><?php foreach (array_keys($diag['rows'][0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php foreach ($diag['rows'] as $row): ?>
            <tr class="<?= (in_array('NO EMPLOYEE RECORD', $row) || in_array('NO USER ACCOUNT', $row)) ? 'table-danger' : '' ?>">
              <?php foreach ($row as $val): ?>
                <td><?= htmlspecialchars($val ?? '') ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <p class="text-muted small text-center">Delete <code>run_cleanup.php</code> from the server after use.</p>
</div>
</body>
</html>

<?php
require_once 'config.php';
require_login();
$page      = 'candidates';
$pageTitle = 'Candidates';

// Update status
if (isset($_GET['status'], $_GET['id']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $allowed = ['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED','REJECTED'];
    $s = strtoupper($_GET['status']);
    if (in_array($s, $allowed)) {
        $conn->prepare("UPDATE candidates SET status=? WHERE id=?")->execute([$s, (int)$_GET['id']]);
        set_flash('success', "Candidate status updated to $s.");
    }
    header("Location: candidates.php"); exit;
}

// Delete
if (isset($_GET['delete']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $conn->prepare("DELETE FROM candidates WHERE id=?")->execute([(int)$_GET['delete']]);
    set_flash('success', 'Candidate removed.');
    header("Location: candidates.php"); exit;
}

// Import candidates from CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    if (!empty($_FILES['csv_file']['name'])) {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv' && $_FILES['csv_file']['error'] === 0) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($handle); // skip header row
            $header = array_map('strtolower', array_map('trim', $header));
            $imported = 0; $skipped = 0;
            $stmt = $conn->prepare("INSERT IGNORE INTO candidates (name,email,phone,experience,current_salary,expected_salary,skills,source,applied_role,status) VALUES (?,?,?,?,?,?,?,?,?,'APPLIED')");
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($header, array_pad($row, count($header), ''));
                if (empty($data['name'])) { $skipped++; continue; }
                try {
                    $stmt->execute([
                        $data['name'] ?? '', $data['email'] ?? '', $data['phone'] ?? '',
                        $data['experience'] ?? '', $data['current_salary'] ?? '', $data['expected_salary'] ?? '',
                        $data['skills'] ?? '', $data['source'] ?? 'Other', $data['applied_role'] ?? ''
                    ]);
                    $imported++;
                } catch (Exception $e) { $skipped++; }
            }
            fclose($handle);
            set_flash('success', "Import complete: $imported added, $skipped skipped.");
        } else {
            set_flash('danger', 'Please upload a valid CSV file.');
        }
    } else {
        set_flash('danger', 'No file selected.');
    }
    header("Location: candidates.php"); exit;
}

// Add candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("INSERT INTO candidates (name,email,phone,experience,current_salary,expected_salary,skills,source,applied_role,status) VALUES (?,?,?,?,?,?,?,?,?,'APPLIED')");
    $stmt->execute([
        $_POST['name'], $_POST['email'], $_POST['phone'],
        $_POST['experience'], $_POST['current_salary'], $_POST['expected_salary'],
        $_POST['skills'], $_POST['source'], $_POST['applied_role'] ?? ''
    ]);
    $new_id = $conn->lastInsertId();

    // Handle resume upload
    if (!empty($_FILES['resume']['name'])) {
        $ext = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf' && $_FILES['resume']['size'] <= 5 * 1024 * 1024) {
            $dir = 'uploads/resumes/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'resume_' . $new_id . '_' . time() . '.pdf';
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $dir . $filename)) {
                $resume_text = extract_pdf_text($dir . $filename);
                $conn->prepare("UPDATE candidates SET resume_path=?, resume_text=? WHERE id=?")->execute([$dir . $filename, $resume_text, $new_id]);
            }
        }
    }

    set_flash('success', 'Candidate added successfully.');
    header("Location: candidates.php"); exit;
}

// Filters
$status_f = $_GET['status_f'] ?? '';
$search   = trim($_GET['search'] ?? '');

$where = "WHERE 1=1"; $params = [];
if ($status_f) { $where .= " AND status=?"; $params[] = $status_f; }
if ($search)   { $where .= " AND (name LIKE ? OR email LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%"]); }

$stmt = $conn->prepare("SELECT * FROM candidates $where ORDER BY created_at DESC");
$stmt->execute($params);
$candidates = $stmt->fetchAll();

// Pipeline counts
$pipeline = [];
foreach (['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED','REJECTED'] as $s) {
    $pipeline[$s] = $conn->query("SELECT COUNT(*) FROM candidates WHERE status='$s'")->fetchColumn();
}

include 'header.php';
?>

<!-- Pipeline Summary -->
<div class="row g-3 mb-4">
<?php
$pcolors = ['APPLIED'=>'primary','SCREENING'=>'info','INTERVIEW'=>'warning','OFFERED'=>'success','HIRED'=>'success','REJECTED'=>'danger'];
foreach ($pipeline as $stage => $count):
?>
<div class="col">
    <a href="?status_f=<?= $stage ?>" class="text-decoration-none">
        <div class="card border-0 shadow-sm text-center p-3 <?= $status_f === $stage ? 'border border-primary border-2' : '' ?>" style="border-radius:12px;">
            <div class="fw-bold fs-4 text-<?= $pcolors[$stage] ?>"><?= $count ?></div>
            <div class="small text-muted"><?= $stage ?></div>
        </div>
    </a>
</div>
<?php endforeach; ?>
</div>

<!-- Actions Bar -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="status_f" value="<?= sanitize($status_f) ?>">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search candidate..." value="<?= sanitize($search) ?>" style="width:220px;">
        <button class="btn btn-sm btn-primary">Search</button>
        <a href="candidates.php" class="btn btn-sm btn-light">Reset</a>
    </form>
    <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-upload me-1"></i> Import CSV
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#candModal">
            <i class="bi bi-person-plus me-1"></i> Add Candidate
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Candidates Table -->
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive" style="overflow-x:auto;">
            <table class="table table-hover align-middle mb-0" style="min-width:900px;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="min-width:200px;">Candidate</th>
                        <th style="min-width:130px;">Applied Role</th>
                        <th style="min-width:100px;">Experience</th>
                        <th style="min-width:110px;">Current CTC</th>
                        <th style="min-width:110px;">Expected CTC</th>
                        <th style="min-width:90px;">Source</th>
                        <th style="min-width:80px;">Resume</th>
                        <th style="min-width:100px;">Status</th>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?><th style="min-width:130px;">Move To</th><th style="min-width:80px;"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$candidates): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">No candidates found</td></tr>
                <?php else: ?>
                <?php foreach ($candidates as $c): ?>
                <tr>
                    <td class="ps-4">
                        <a href="candidate_profile.php?id=<?= $c['id'] ?>" class="text-decoration-none">
                            <div class="fw-semibold text-primary"><?= sanitize($c['name']) ?></div>
                        </a>
                        <div class="text-muted small"><?= sanitize($c['email']) ?></div>
                        <?php if ($c['phone']): ?><div class="text-muted small"><?= sanitize($c['phone']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($c['applied_role'])): ?>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-2"><?= sanitize($c['applied_role']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($c['experience'] ?? '-') ?></td>
                    <td><?= sanitize($c['current_salary'] ?? '-') ?></td>
                    <td><?= sanitize($c['expected_salary'] ?? '-') ?></td>
                    <td><?= sanitize($c['source'] ?? '-') ?></td>
                    <td>
                        <?php if (!empty($c['resume_path']) && file_exists($c['resume_path'])): ?>
                            <a href="<?= sanitize($c['resume_path']) ?>" target="_blank" class="btn btn-xs btn-sm btn-outline-success py-0 px-2">
                                <i class="bi bi-file-earmark-pdf me-1"></i>View
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= badge($c['status']) ?> rounded-pill"><?= $c['status'] ?></span></td>
                    <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
                    <td>
                        <select class="form-select form-select-sm" style="width:130px;" onchange="location.href='?status='+this.value+'&id=<?= $c['id'] ?>'">
                            <option value="">Move to...</option>
                            <?php foreach (['SCREENING','INTERVIEW','OFFERED','HIRED','REJECTED'] as $s): ?>
                                <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <a href="candidate_profile.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"><i class="bi bi-person-lines-fill"></i></a>
                        <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="return hConfirmSync(event,'Remove candidate?')"><i class="bi bi-trash"></i></a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Candidate Modal -->
<div class="modal fade" id="candModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Add Candidate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Full Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Experience</label>
                        <input type="text" name="experience" class="form-control" placeholder="e.g. 3 years">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Current Salary</label>
                        <input type="text" name="current_salary" class="form-control" placeholder="e.g. 40,000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Expected Salary</label>
                        <input type="text" name="expected_salary" class="form-control" placeholder="e.g. 55,000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Source</label>
                        <select name="source" class="form-select">
                            <option>LinkedIn</option><option>Referral</option><option>Job Portal</option><option>Walk-in</option><option>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Applied Role</label>
                        <input type="text" name="applied_role" class="form-control" placeholder="e.g. PHP Developer">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Skills</label>
                        <input type="text" name="skills" class="form-control" placeholder="PHP, MySQL, React...">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">
                            <i class="bi bi-file-earmark-pdf text-danger me-1"></i>Resume (PDF only · Max 5MB)
                        </label>
                        <input type="file" name="resume" class="form-control" accept=".pdf">
                        <div class="form-text">Optional — can also be uploaded later from the candidate profile.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i> Add Candidate</button>
            </div>
        </form>
    </div>
</div>

<!-- Import CSV Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="import_csv" value="1">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-upload me-2"></i>Import Candidates (CSV)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <strong>CSV column headers (in any order):</strong><br>
                    <code>name, email, phone, experience, current_salary, expected_salary, skills, source, applied_role</code><br>
                    Only <strong>name</strong> is required. Duplicate emails are skipped automatically.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Select CSV File *</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i> Import</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

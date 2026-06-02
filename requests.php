<?php
require_once 'config.php';
require_login();
$page      = 'requests';
$pageTitle = 'Manpower Requests';

// Approve / Reject
if (isset($_GET['action'], $_GET['id']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $id  = (int)$_GET['id'];
    $act = $_GET['action'];
    $map = ['approve' => 'APPROVED', 'reject' => 'REJECTED', 'hold' => 'ON_HOLD'];
    if (isset($map[$act])) {
        $conn->prepare("UPDATE manpower_requests SET status=? WHERE id=?")->execute([$map[$act], $id]);
        set_flash('success', "Request marked as " . $map[$act] . ".");
    }
    header("Location: requests.php"); exit;
}

// Delete
if (isset($_GET['delete']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $conn->prepare("DELETE FROM manpower_requests WHERE id=?")->execute([(int)$_GET['delete']]);
    set_flash('success', 'Request deleted.');
    header("Location: requests.php"); exit;
}

// Edit request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $conn->prepare("UPDATE manpower_requests SET dept_id=?,title=?,quantity=?,reason=?,salary_range=?,experience=?,urgency=?,skills_required=? WHERE id=?")
         ->execute([
             $_POST['dept_id'], $_POST['title'], $_POST['quantity'],
             $_POST['reason'], $_POST['salary_range'], $_POST['experience'],
             $_POST['urgency'], $_POST['skills_required'] ?? '', (int)$_POST['edit_id']
         ]);
    set_flash('success', 'Request updated.');
    header("Location: requests.php"); exit;
}

// Submit new request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("INSERT INTO manpower_requests (dept_id,title,quantity,reason,salary_range,experience,urgency,skills_required,status,requested_by) VALUES (?,?,?,?,?,?,?,?,'PENDING',?)");
    $stmt->execute([
        $_POST['dept_id'], $_POST['title'], $_POST['quantity'],
        $_POST['reason'], $_POST['salary_range'], $_POST['experience'],
        $_POST['urgency'], $_POST['skills_required'] ?? '', current_user()['name']
    ]);
    set_flash('success', 'Manpower request submitted.');
    header("Location: requests.php"); exit;
}

// Filters
$status_f = $_GET['status'] ?? '';
$dept_f   = (int)($_GET['dept'] ?? 0);

$where  = "WHERE 1=1";
$params = [];
if ($status_f) { $where .= " AND r.status=?"; $params[] = $status_f; }
if ($dept_f)   { $where .= " AND r.dept_id=?"; $params[] = $dept_f; }

$stmt = $conn->prepare("SELECT r.*, d.name as dept_name FROM manpower_requests r LEFT JOIN departments d ON r.dept_id = d.id $where ORDER BY r.created_at DESC");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$depts = $conn->query("SELECT * FROM departments ORDER BY name")->fetchAll();

// Fetch all active candidates once for match counting
$all_candidates = $conn->query("SELECT id, applied_role, skills, resume_text FROM candidates WHERE status NOT IN ('HIRED','REJECTED')")->fetchAll();

function count_matches($req, $all_candidates) {
    $title     = strtolower($req['title']);
    $skill_arr = array_filter(array_map('trim', explode(',', strtolower($req['skills_required'] ?? ''))));
    $count = 0;
    foreach ($all_candidates as $c) {
        $role   = strtolower($c['applied_role'] ?? '');
        $skills = strtolower($c['skills'] ?? '');
        $pdf    = strtolower($c['resume_text'] ?? '');
        $matched = strpos($role, $title) !== false
                || ($pdf && strpos($pdf, $title) !== false);
        if (!$matched) {
            foreach ($skill_arr as $sk) {
                if ($sk && (strpos($skills, $sk) !== false || ($pdf && strpos($pdf, $sk) !== false))) {
                    $matched = true; break;
                }
            }
        }
        if ($matched) $count++;
    }
    return $count;
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reqModal">
        <i class="bi bi-plus-lg me-1"></i> New Request
    </button>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (['PENDING','APPROVED','REJECTED','ON_HOLD'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status_f === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $dept_f == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm">Filter</button>
                <a href="requests.php" class="btn btn-light btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Requests Table -->
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Title</th>
                        <th>Department</th>
                        <th>Qty</th>
                        <th>Urgency</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Matched</th>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No requests found</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="ps-4">
                            <a href="request_view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                                <div class="fw-semibold text-primary"><?= sanitize($r['title']) ?></div>
                            </a>
                            <?php if ($r['experience']): ?><div class="text-muted small"><?= sanitize($r['experience']) ?> exp</div><?php endif; ?>
                            <?php if (!empty($r['skills_required'])): ?><div class="text-muted small"><i class="bi bi-tag me-1"></i><?= sanitize($r['skills_required']) ?></div><?php endif; ?>
                        </td>
                        <td><?= sanitize($r['dept_name'] ?? '-') ?></td>
                        <td><?= $r['quantity'] ?></td>
                        <td><span class="badge bg-<?= badge($r['urgency']) ?>"><?= $r['urgency'] ?></span></td>
                        <td><?= sanitize($r['requested_by'] ?? '-') ?></td>
                        <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                        <td><span class="badge bg-<?= badge($r['status']) ?> rounded-pill"><?= $r['status'] ?></span></td>
                        <td>
                            <?php $mc = count_matches($r, $all_candidates); ?>
                            <a href="request_view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                                <span class="badge rounded-pill px-3 py-2 <?= $mc > 0 ? 'bg-success' : 'bg-secondary' ?>">
                                    <i class="bi bi-people me-1"></i><?= $mc ?> Profile<?= $mc != 1 ? 's' : '' ?>
                                </span>
                            </a>
                        </td>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
                        <td>
                            <a href="request_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 me-1" title="View Matched Candidates"><i class="bi bi-people"></i></a>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 me-1" title="Edit"
                                onclick="openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($r['status'] === 'PENDING'): ?>
                                <a href="?action=approve&id=<?= $r['id'] ?>" class="btn btn-xs btn-success btn-sm py-0 px-2" onclick="return hConfirmSync(event,'Approve?')"><i class="bi bi-check"></i></a>
                                <a href="?action=reject&id=<?= $r['id'] ?>" class="btn btn-xs btn-danger btn-sm py-0 px-2 ms-1" onclick="return hConfirmSync(event,'Reject?')"><i class="bi bi-x"></i></a>
                                <a href="?action=hold&id=<?= $r['id'] ?>" class="btn btn-xs btn-secondary btn-sm py-0 px-2 ms-1"><i class="bi bi-pause"></i></a>
                            <?php endif; ?>
                            <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="return hConfirmSync(event,'Delete this request?')"><i class="bi bi-trash"></i></a>
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

<!-- New Request Modal -->
<div class="modal fade" id="reqModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">New Manpower Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Department *</label>
                        <select name="dept_id" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Position Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Senior Developer" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Quantity</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Urgency</label>
                        <select name="urgency" class="form-select">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM" selected>Medium</option>
                            <option value="HIGH">High</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Experience Required</label>
                        <input type="text" name="experience" class="form-control" placeholder="e.g. 3-5 years">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Salary Range</label>
                        <input type="text" name="salary_range" class="form-control" placeholder="e.g. 40,000 – 60,000">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Skills Required <span class="text-muted fw-normal">(comma separated — used for candidate matching)</span></label>
                        <input type="text" name="skills_required" class="form-control" placeholder="e.g. PHP, MySQL, Laravel, React">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Reason / Justification</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Why is this hire needed?"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Submit Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Request Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Manpower Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Department *</label>
                        <select name="dept_id" id="edit_dept_id" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Position Title *</label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Quantity</label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control" min="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Urgency</label>
                        <select name="urgency" id="edit_urgency" class="form-select">
                            <option value="LOW">Low</option>
                            <option value="MEDIUM">Medium</option>
                            <option value="HIGH">High</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Experience Required</label>
                        <input type="text" name="experience" id="edit_experience" class="form-control" placeholder="e.g. 3-5 years">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Salary Range</label>
                        <input type="text" name="salary_range" id="edit_salary_range" class="form-control" placeholder="e.g. 40,000 – 60,000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Skills Required</label>
                        <input type="text" name="skills_required" id="edit_skills_required" class="form-control" placeholder="e.g. PHP, MySQL, Laravel">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Reason / Justification</label>
                        <textarea name="reason" id="edit_reason" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(r) {
    document.getElementById('edit_id').value           = r.id;
    document.getElementById('edit_title').value        = r.title;
    document.getElementById('edit_dept_id').value      = r.dept_id;
    document.getElementById('edit_quantity').value     = r.quantity;
    document.getElementById('edit_urgency').value      = r.urgency;
    document.getElementById('edit_experience').value   = r.experience  || '';
    document.getElementById('edit_salary_range').value = r.salary_range || '';
    document.getElementById('edit_skills_required').value = r.skills_required || '';
    document.getElementById('edit_reason').value       = r.reason || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include 'footer.php'; ?>

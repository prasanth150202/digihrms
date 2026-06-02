<?php
require_once 'config.php';
require_login();
$page      = 'interviews';
$pageTitle = 'Interviews';

// Update status / feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interview_id'])) {
    $conn->prepare("UPDATE interviews SET status=?,feedback=?,rating=? WHERE id=?")
         ->execute([$_POST['status'], $_POST['feedback'], $_POST['rating'], (int)$_POST['interview_id']]);
    set_flash('success', 'Interview updated.');
    header("Location: interviews.php"); exit;
}

// Schedule interview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['candidate_id'])) {
    $conn->prepare("INSERT INTO interviews (candidate_id,round,panel_id,date_time,status) VALUES (?,?,?,?,'SCHEDULED')")
         ->execute([$_POST['candidate_id'], $_POST['round'], $_POST['panel_id'] ?: null, $_POST['date_time']]);
    set_flash('success', 'Interview scheduled.');
    header("Location: interviews.php"); exit;
}

// Delete
if (isset($_GET['delete']) && has_role('SUPER_ADMIN', 'HR_ADMIN')) {
    $conn->prepare("DELETE FROM interviews WHERE id=?")->execute([(int)$_GET['delete']]);
    set_flash('success', 'Interview removed.');
    header("Location: interviews.php"); exit;
}

$filter = $_GET['filter'] ?? '';
$where  = $filter ? "WHERE i.status = '" . $conn->quote($filter) . "'" : '';

$interviews = $conn->query("SELECT i.*, c.name as cand_name, c.email as cand_email, u.name as panel_name
    FROM interviews i
    LEFT JOIN candidates c ON i.candidate_id = c.id
    LEFT JOIN users u ON i.panel_id = u.id
    $where ORDER BY i.date_time DESC")->fetchAll();

$candidates   = $conn->query("SELECT id, name FROM candidates WHERE status NOT IN ('HIRED','REJECTED') ORDER BY name")->fetchAll();
$panel_members = $conn->query("SELECT id, name FROM users WHERE role IN ('INTERVIEW_PANEL','HR_ADMIN','SUPER_ADMIN') ORDER BY name")->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex gap-2">
        <?php foreach ([''=>'All','SCHEDULED'=>'Scheduled','COMPLETED'=>'Completed','CANCELLED'=>'Cancelled'] as $val => $label): ?>
        <a href="?filter=<?= $val ?>" class="btn btn-sm <?= $filter === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#schedModal">
        <i class="bi bi-calendar-plus me-1"></i> Schedule Interview
    </button>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Candidate</th>
                        <th>Round</th>
                        <th>Panel</th>
                        <th>Date & Time</th>
                        <th>Status</th>
                        <th>Rating</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$interviews): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">No interviews found</td></tr>
                <?php else: ?>
                <?php foreach ($interviews as $i): ?>
                <tr>
                    <td class="ps-4">
                        <div class="fw-semibold"><?= sanitize($i['cand_name']) ?></div>
                        <div class="text-muted small"><?= sanitize($i['cand_email']) ?></div>
                    </td>
                    <td><?= sanitize($i['round']) ?></td>
                    <td><?= sanitize($i['panel_name'] ?? 'Not assigned') ?></td>
                    <td><?= $i['date_time'] ? date('d M Y, h:i A', strtotime($i['date_time'])) : '-' ?></td>
                    <td><span class="badge bg-<?= badge($i['status']) ?> rounded-pill"><?= $i['status'] ?></span></td>
                    <td>
                        <?php if ($i['rating']): ?>
                            <?= str_repeat('★', $i['rating']) ?><?= str_repeat('☆', 5 - $i['rating']) ?>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#feedbackModal<?= $i['id'] ?>">
                            <i class="bi bi-pencil"></i> Feedback
                        </button>
                        <?php if (has_role('SUPER_ADMIN', 'HR_ADMIN')): ?>
                        <a href="?delete=<?= $i['id'] ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return hConfirmSync(event,'Delete?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Feedback Modal -->
                <div class="modal fade" id="feedbackModal<?= $i['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" class="modal-content" style="border-radius:12px;">
                            <input type="hidden" name="interview_id" value="<?= $i['id'] ?>">
                            <div class="modal-header border-0"><h6 class="modal-title fw-bold">Interview Feedback — <?= sanitize($i['cand_name']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="SCHEDULED" <?= $i['status']==='SCHEDULED' ? 'selected' : '' ?>>Scheduled</option>
                                        <option value="COMPLETED" <?= $i['status']==='COMPLETED' ? 'selected' : '' ?>>Completed</option>
                                        <option value="CANCELLED" <?= $i['status']==='CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Rating (1–5)</label>
                                    <input type="number" name="rating" class="form-control" min="1" max="5" value="<?= $i['rating'] ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Feedback</label>
                                    <textarea name="feedback" class="form-control" rows="4"><?= sanitize($i['feedback']) ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="schedModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Schedule Interview</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Candidate *</label>
                    <input type="text" id="candSearch" class="form-control form-control-sm mb-1" placeholder="Search candidate...">
                    <select name="candidate_id" id="candSelect" class="form-select" required>
                        <option value="">-- Select Candidate --</option>
                        <?php foreach ($candidates as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Round</label>
                    <select name="round" class="form-select">
                        <option>HR Round</option><option>Technical Round 1</option><option>Technical Round 2</option><option>Manager Round</option><option>Final Round</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Interview Panel</label>
                    <input type="text" id="panelSearch" class="form-control form-control-sm mb-1" placeholder="Search panel member...">
                    <select name="panel_id" id="panelSelect" class="form-select">
                        <option value="">-- Assign Panel --</option>
                        <?php foreach ($panel_members as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Date & Time *</label>
                    <input type="datetime-local" name="date_time" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check me-1"></i> Schedule</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterDropdown(searchId, selectId) {
    const search = document.getElementById(searchId);
    const select = document.getElementById(selectId);
    const options = Array.from(select.options);
    search.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        options.forEach(opt => {
            opt.hidden = opt.value !== '' && !opt.text.toLowerCase().includes(q);
        });
        if (select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) {
            select.value = '';
        }
    });
}
filterDropdown('candSearch', 'candSelect');
filterDropdown('panelSearch', 'panelSelect');

document.getElementById('schedModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('candSearch').value = '';
    document.getElementById('panelSearch').value = '';
    Array.from(document.getElementById('candSelect').options).forEach(o => o.hidden = false);
    Array.from(document.getElementById('panelSelect').options).forEach(o => o.hidden = false);
});
</script>

<?php include 'footer.php'; ?>

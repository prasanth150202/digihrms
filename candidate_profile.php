<?php
require_once 'config.php';
require_login();
$page      = 'candidates';
$pageTitle = 'Candidate Profile';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: candidates.php"); exit; }

// Fetch candidate
$stmt = $conn->prepare("SELECT * FROM candidates WHERE id = ?");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { set_flash('danger','Candidate not found.'); header("Location: candidates.php"); exit; }

// Update stage
if (isset($_GET['stage']) && has_role('SUPER_ADMIN','HR_ADMIN')) {
    $allowed = ['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED','REJECTED'];
    $s = strtoupper($_GET['stage']);
    if (in_array($s, $allowed)) {
        $conn->prepare("UPDATE candidates SET status=? WHERE id=?")->execute([$s, $id]);
        set_flash('success', "Stage moved to $s.");
    }
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Update candidate info
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_info') {
    $conn->prepare("UPDATE candidates SET name=?,email=?,phone=?,applied_role=?,experience=?,current_salary=?,expected_salary=?,skills=?,source=? WHERE id=?")
         ->execute([$_POST['name'],$_POST['email'],$_POST['phone'],$_POST['applied_role'],$_POST['experience'],$_POST['current_salary'],$_POST['expected_salary'],$_POST['skills'],$_POST['source'],$id]);
    set_flash('success','Candidate info updated.');
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Add note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $note = trim($_POST['note']);
    if ($note) {
        $conn->prepare("INSERT INTO candidate_notes (candidate_id,user_id,note) VALUES (?,?,?)")
             ->execute([$id, current_user()['id'], $note]);
        set_flash('success','Note added.');
    }
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Delete note
if (isset($_GET['del_note']) && has_role('SUPER_ADMIN','HR_ADMIN')) {
    $conn->prepare("DELETE FROM candidate_notes WHERE id=? AND candidate_id=?")->execute([(int)$_GET['del_note'], $id]);
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Upload resume
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_resume') {
    if (!empty($_FILES['resume']['name'])) {
        $ext      = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
        $maxsize  = 5 * 1024 * 1024;
        if ($_FILES['resume']['size'] > $maxsize) {
            set_flash('danger','File too large. Max 5MB allowed.');
            header("Location: candidate_profile.php?id=$id"); exit;
        }
        $allowed  = ['pdf'];
        if (in_array($ext, $allowed)) {
            $dir = 'uploads/resumes/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'resume_' . $id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $dir . $filename)) {
                $resume_text = extract_pdf_text($dir . $filename);
                $conn->prepare("UPDATE candidates SET resume_path=?, resume_text=? WHERE id=?")->execute([$dir.$filename, $resume_text, $id]);
                set_flash('success','Resume uploaded and scanned successfully.');
            }
        } else {
            set_flash('danger','Only PDF, DOC, DOCX allowed.');
        }
    }
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Schedule interview from profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'schedule_interview') {
    $conn->prepare("INSERT INTO interviews (candidate_id,round,panel_id,date_time,status) VALUES (?,?,?,?,'SCHEDULED')")
         ->execute([$id, $_POST['round'], $_POST['panel_id'] ?: null, $_POST['date_time']]);
    // Auto-move stage to INTERVIEW
    $conn->prepare("UPDATE candidates SET status='INTERVIEW' WHERE id=? AND status NOT IN ('OFFERED','HIRED','REJECTED')")->execute([$id]);
    set_flash('success','Interview scheduled.');
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Save interview feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_feedback') {
    $conn->prepare("UPDATE interviews SET status=?,rating=?,feedback=? WHERE id=? AND candidate_id=?")
         ->execute([$_POST['status'],$_POST['rating'],$_POST['feedback'],(int)$_POST['interview_id'],$id]);
    set_flash('success','Feedback saved.');
    header("Location: candidate_profile.php?id=$id"); exit;
}

// Re-fetch updated candidate
$stmt->execute([$id]);
$c = $stmt->fetch();

// Fetch interviews
$interviews = $conn->query("SELECT i.*, u.name as panel_name FROM interviews i LEFT JOIN users u ON i.panel_id=u.id WHERE i.candidate_id=$id ORDER BY i.date_time ASC")->fetchAll();

// Fetch notes
$notes = $conn->query("SELECT n.*, u.name as author FROM candidate_notes n LEFT JOIN users u ON n.user_id=u.id WHERE n.candidate_id=$id ORDER BY n.created_at DESC")->fetchAll();

// Panel members
$panel = $conn->query("SELECT id,name FROM users WHERE role IN ('INTERVIEW_PANEL','HR_ADMIN','SUPER_ADMIN') ORDER BY name")->fetchAll();

$stages = ['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED','REJECTED'];
$stage_idx = array_search($c['status'], $stages);

$stage_colors = [
    'APPLIED'   => 'primary',
    'SCREENING' => 'info',
    'INTERVIEW' => 'warning',
    'OFFERED'   => 'success',
    'HIRED'     => 'success',
    'REJECTED'  => 'danger',
];

include 'header.php';
?>

<style>
.stage-bar .stage-item { flex: 1; text-align: center; }
.stage-bar .stage-dot  { width: 32px; height: 32px; border-radius: 50%; border: 3px solid #e2e8f0; background: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; transition: all 0.3s; }
.stage-bar .stage-dot.done    { background: #0f4c81; border-color: #0f4c81; color: #fff; }
.stage-bar .stage-dot.current { background: #1e88e5; border-color: #1e88e5; color: #fff; box-shadow: 0 0 0 4px rgba(30,136,229,0.2); }
.stage-bar .stage-dot.rejected{ background: #ef4444; border-color: #ef4444; color: #fff; }
.stage-bar .stage-line { flex: 1; height: 3px; background: #e2e8f0; margin-top: -3px; }
.stage-bar .stage-line.done { background: #0f4c81; }
.timeline-item { border-left: 3px solid #e2e8f0; padding-left: 16px; margin-bottom: 20px; position: relative; }
.timeline-item::before { content:''; width: 12px; height: 12px; border-radius: 50%; background: #0f4c81; position: absolute; left: -7px; top: 4px; }
.timeline-item.completed::before { background: #43a047; }
.timeline-item.cancelled::before { background: #ef4444; }
.note-card { background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; }
</style>

<!-- Back -->
<div class="mb-3">
    <a href="candidates.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i> Back to Candidates</a>
</div>

<!-- Header Card -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:700;">
                    <?= strtoupper(substr($c['name'],0,1)) ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-0"><?= sanitize($c['name']) ?></h4>
                    <div class="text-muted small"><?= sanitize($c['applied_role'] ?? 'Role not specified') ?></div>
                    <div class="mt-1">
                        <span class="badge bg-<?= badge($c['status']) ?> rounded-pill px-3"><?= $c['status'] ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($c['resume_path'] && file_exists($c['resume_path'])): ?>
                <a href="<?= sanitize($c['resume_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-person me-1"></i> View Resume
                </a>
                <?php endif; ?>
                <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#schedModal">
                    <i class="bi bi-calendar-plus me-1"></i> Schedule Interview
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stage Pipeline Bar -->
        <?php if ($c['status'] !== 'REJECTED'): ?>
        <div class="mt-4">
            <div class="d-flex align-items-center stage-bar">
                <?php foreach (['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED'] as $idx => $stage): ?>
                    <?php
                        $curr_idx = array_search($c['status'], ['APPLIED','SCREENING','INTERVIEW','OFFERED','HIRED']);
                        $is_done    = $curr_idx > $idx;
                        $is_current = $curr_idx === $idx;
                    ?>
                    <?php if ($idx > 0): ?>
                    <div class="stage-line flex-grow-1 <?= $is_done ? 'done' : '' ?>"></div>
                    <?php endif; ?>
                    <div class="stage-item">
                        <?php if (has_role('SUPER_ADMIN','HR_ADMIN') && !$is_current): ?>
                        <a href="?id=<?= $id ?>&stage=<?= $stage ?>" title="Move to <?= $stage ?>">
                        <?php endif; ?>
                        <div class="stage-dot <?= $is_done ? 'done' : ($is_current ? 'current' : '') ?>">
                            <?= $is_done ? '<i class="bi bi-check"></i>' : ($idx+1) ?>
                        </div>
                        <?php if (has_role('SUPER_ADMIN','HR_ADMIN') && !$is_current): ?></a><?php endif; ?>
                        <div style="font-size:0.65rem;margin-top:4px;color:<?= $is_current ? '#1e88e5' : ($is_done ? '#0f4c81' : '#94a3b8') ?>;font-weight:<?= $is_current ? '700' : '500' ?>;"><?= $stage ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="mt-3"><span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle me-1"></i> Rejected</span>
        <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
            <a href="?id=<?= $id ?>&stage=APPLIED" class="btn btn-sm btn-outline-secondary ms-2">Reconsider</a>
        <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

<!-- LEFT: Candidate Info -->
<div class="col-md-4">

    <!-- Contact Info -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Contact Details</h6>
                <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#editInfoModal"><i class="bi bi-pencil"></i></button>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-3">
                <div><div class="text-muted small mb-1"><i class="bi bi-envelope me-1"></i> Email</div><div class="fw-semibold small"><?= sanitize($c['email'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-phone me-1"></i> Phone</div><div class="fw-semibold small"><?= sanitize($c['phone'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-briefcase me-1"></i> Experience</div><div class="fw-semibold small"><?= sanitize($c['experience'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-currency-rupee"></i> Current CTC</div><div class="fw-semibold small"><?= sanitize($c['current_salary'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-currency-rupee"></i> Expected CTC</div><div class="fw-semibold small"><?= sanitize($c['expected_salary'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-geo-alt me-1"></i> Source</div><div class="fw-semibold small"><?= sanitize($c['source'] ?? '-') ?></div></div>
                <div><div class="text-muted small mb-1"><i class="bi bi-calendar me-1"></i> Applied</div><div class="fw-semibold small"><?= date('d M Y', strtotime($c['created_at'])) ?></div></div>
            </div>
        </div>
    </div>

    <!-- Skills -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Skills</h6>
            <?php if ($c['skills']): ?>
                <?php foreach (explode(',', $c['skills']) as $skill): ?>
                    <span class="badge bg-primary-subtle text-primary me-1 mb-1 px-3 py-2"><?= sanitize(trim($skill)) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted small">No skills listed.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resume Upload -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i> Resume</h6>
            <?php if (!empty($c['resume_path']) && file_exists($c['resume_path'])): ?>
                <div class="d-grid gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#pdfModal">
                        <i class="bi bi-eye me-1"></i> View Resume
                    </button>
                    <a href="<?= sanitize($c['resume_path']) ?>" download class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i> Download
                    </a>
                </div>
                <div class="text-muted small text-center mb-3">
                    <i class="bi bi-check-circle-fill text-success me-1"></i>Resume uploaded
                </div>
            <?php else: ?>
                <div class="text-center py-2 mb-3">
                    <i class="bi bi-file-earmark-x text-muted" style="font-size:2rem;"></i>
                    <p class="text-muted small mt-1">No resume uploaded yet.</p>
                </div>
            <?php endif; ?>
            <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_resume">
                <div class="mb-2">
                    <input type="file" name="resume" class="form-control form-control-sm" accept=".pdf" required>
                </div>
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload me-1"></i> Upload PDF Resume</button>
                <div class="text-muted text-center mt-1" style="font-size:0.7rem;">PDF only · Max 5MB</div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Profile URL -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-link-45deg me-1"></i> Profile Link</h6>
            <div class="input-group input-group-sm">
                <input type="text" id="profileUrl" class="form-control form-control-sm" value="<?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/candidate_profile.php?id=' . $id ?>" readonly>
                <button class="btn btn-outline-secondary" onclick="copyProfileUrl()" title="Copy link"><i class="bi bi-clipboard"></i></button>
            </div>
            <div class="text-muted mt-1" style="font-size:0.7rem;">Share this link to view candidate profile</div>
        </div>
    </div>

    <!-- Quick Stage Change -->
    <?php if (has_role('SUPER_ADMIN','HR_ADMIN') && $c['status'] !== 'REJECTED' && $c['status'] !== 'HIRED'): ?>
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Quick Actions</h6>
            <div class="d-grid gap-2">
                <?php if ($c['status'] !== 'OFFERED'): ?>
                <a href="?id=<?= $id ?>&stage=OFFERED" class="btn btn-sm btn-outline-success" onclick="return hConfirmSync(event,'Move to Offered?')"><i class="bi bi-gift me-1"></i> Send Offer</a>
                <?php endif; ?>
                <?php if ($c['status'] === 'OFFERED'): ?>
                <a href="?id=<?= $id ?>&stage=HIRED" class="btn btn-sm btn-success" onclick="return hConfirmSync(event,'Mark as Hired?')"><i class="bi bi-check-circle me-1"></i> Mark as Hired</a>
                <?php endif; ?>
                <a href="?id=<?= $id ?>&stage=REJECTED" class="btn btn-sm btn-outline-danger" onclick="return hConfirmSync(event,'Reject this candidate?')"><i class="bi bi-x-circle me-1"></i> Reject</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- RIGHT: Interview Timeline + Notes -->
<div class="col-md-8">

    <!-- Interview Timeline -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-4"><i class="bi bi-camera-video me-2"></i>Interview Rounds</h6>

            <?php if (!$interviews): ?>
                <p class="text-muted small">No interviews scheduled yet. <a href="#" data-bs-toggle="modal" data-bs-target="#schedModal">Schedule one</a>.</p>
            <?php else: ?>
            <?php foreach ($interviews as $idx => $iv): ?>
            <div class="timeline-item <?= strtolower($iv['status']) ?>">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <div class="fw-bold"><?= sanitize($iv['round']) ?></div>
                        <div class="text-muted small">
                            <i class="bi bi-calendar3 me-1"></i><?= $iv['date_time'] ? date('d M Y, h:i A', strtotime($iv['date_time'])) : 'TBD' ?>
                            <?php if ($iv['panel_name']): ?> &nbsp;·&nbsp; <i class="bi bi-person me-1"></i><?= sanitize($iv['panel_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($iv['rating']): ?>
                        <span class="text-warning"><?= str_repeat('★',$iv['rating']) ?><?= str_repeat('☆',5-$iv['rating']) ?></span>
                        <?php endif; ?>
                        <span class="badge bg-<?= badge($iv['status']) ?>"><?= $iv['status'] ?></span>
                    </div>
                </div>
                <?php if ($iv['feedback']): ?>
                <div class="mt-2 p-2 rounded" style="background:#f8fafc;font-size:0.85rem;"><?= sanitize($iv['feedback']) ?></div>
                <?php endif; ?>
                <?php if ($iv['status'] === 'SCHEDULED' && has_role('SUPER_ADMIN','HR_ADMIN','INTERVIEW_PANEL')): ?>
                <button class="btn btn-xs btn-sm btn-outline-primary mt-2 py-0 px-2" data-bs-toggle="modal" data-bs-target="#fbModal<?= $iv['id'] ?>">
                    <i class="bi bi-pencil me-1"></i>Add Feedback
                </button>
                <?php endif; ?>
            </div>

            <!-- Feedback Modal -->
            <div class="modal fade" id="fbModal<?= $iv['id'] ?>" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" class="modal-content" style="border-radius:12px;">
                        <input type="hidden" name="action" value="save_feedback">
                        <input type="hidden" name="interview_id" value="<?= $iv['id'] ?>">
                        <div class="modal-header border-0"><h6 class="modal-title fw-bold">Feedback – <?= sanitize($iv['round']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Outcome</label>
                                <select name="status" class="form-select">
                                    <option value="SCHEDULED">Scheduled</option>
                                    <option value="COMPLETED">Completed</option>
                                    <option value="CANCELLED">Cancelled</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Rating (1–5)</label>
                                <div class="d-flex gap-2">
                                    <?php for ($r=1; $r<=5; $r++): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="rating" value="<?= $r ?>" id="r<?= $iv['id'].$r ?>" <?= $iv['rating']==$r?'checked':'' ?>>
                                        <label class="form-check-label" for="r<?= $iv['id'].$r ?>"><?= $r ?></label>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Feedback / Notes</label>
                                <textarea name="feedback" class="form-control" rows="4" placeholder="Summarize the interview..."><?= sanitize($iv['feedback']) ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Save Feedback</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notes (like CRM lead notes) -->
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-sticky me-2"></i>Notes & Activity Log</h6>

            <!-- Add Note -->
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="add_note">
                <div class="d-flex gap-2">
                    <textarea name="note" class="form-control form-control-sm" rows="2" placeholder="Add a note, call summary, or update..." required></textarea>
                    <button class="btn btn-primary btn-sm px-3"><i class="bi bi-send-fill"></i></button>
                </div>
            </form>

            <!-- Notes List -->
            <?php if (!$notes): ?>
                <p class="text-muted small">No notes yet.</p>
            <?php else: ?>
            <?php foreach ($notes as $n): ?>
            <div class="note-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="fw-semibold small"><?= sanitize($n['author']) ?></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted" style="font-size:0.7rem;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
                        <?php if (has_role('SUPER_ADMIN','HR_ADMIN')): ?>
                        <a href="?id=<?= $id ?>&del_note=<?= $n['id'] ?>" class="text-danger" style="font-size:0.75rem;" onclick="return hConfirmSync(event,'Delete note?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-1 small"><?= nl2br(sanitize($n['note'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- Edit Info Modal -->
<div class="modal fade" id="editInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="action" value="update_info">
            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Candidate Info</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Full Name</label><input type="text" name="name" class="form-control" value="<?= sanitize($c['name']) ?>" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Applied Role</label><input type="text" name="applied_role" class="form-control" value="<?= sanitize($c['applied_role'] ?? '') ?>" placeholder="e.g. PHP Developer"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control" value="<?= sanitize($c['email'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="phone" class="form-control" value="<?= sanitize($c['phone'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Experience</label><input type="text" name="experience" class="form-control" value="<?= sanitize($c['experience'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Current CTC</label><input type="text" name="current_salary" class="form-control" value="<?= sanitize($c['current_salary'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Expected CTC</label><input type="text" name="expected_salary" class="form-control" value="<?= sanitize($c['expected_salary'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Source</label>
                        <select name="source" class="form-select">
                            <?php foreach (['LinkedIn','Referral','Job Portal','Walk-in','Other'] as $src): ?>
                            <option <?= $c['source']===$src?'selected':'' ?>><?= $src ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Skills</label><input type="text" name="skills" class="form-control" value="<?= sanitize($c['skills'] ?? '') ?>" placeholder="Comma separated"></div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule Interview Modal -->
<div class="modal fade" id="schedModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="action" value="schedule_interview">
            <div class="modal-header border-0"><h5 class="modal-title fw-bold">Schedule Interview</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Round</label>
                    <select name="round" class="form-select">
                        <option>HR Round</option><option>Technical Round 1</option><option>Technical Round 2</option><option>Manager Round</option><option>Final Round</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Assign Panel</label>
                    <select name="panel_id" class="form-select">
                        <option value="">-- Not assigned --</option>
                        <?php foreach ($panel as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitize($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Date & Time *</label>
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

<?php if (!empty($c['resume_path']) && file_exists($c['resume_path'])): ?>
<!-- PDF Viewer Modal -->
<div class="modal fade" id="pdfModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;height:90vh;">
            <div class="modal-header border-0 py-2 px-4">
                <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf text-danger me-2"></i><?= sanitize($c['name']) ?> — Resume</h6>
                <div class="d-flex gap-2 align-items-center">
                    <a href="<?= sanitize($c['resume_path']) ?>" download class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="flex:1;overflow:hidden;">
                <iframe src="<?= sanitize($c['resume_path']) ?>" width="100%" height="100%" style="border:none;height:calc(90vh - 60px);"></iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function copyProfileUrl() {
    const url = document.getElementById('profileUrl');
    url.select();
    navigator.clipboard.writeText(url.value).then(() => {
        const btn = url.nextElementSibling;
        btn.innerHTML = '<i class="bi bi-check text-success"></i>';
        setTimeout(() => btn.innerHTML = '<i class="bi bi-clipboard"></i>', 2000);
    });
}
</script>

<?php include 'footer.php'; ?>

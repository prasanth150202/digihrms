<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER');
$page      = 'applications';
$pageTitle = 'Applications';

// ── POST HANDLERS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_application') {
        $resume_path = null;
        $resume_text = '';
        if (!empty($_FILES['resume']['tmp_name'])) {
            $ext  = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));
            $dest = __DIR__ . '/uploads/resumes/';
            if (!is_dir($dest)) mkdir($dest, 0755, true);
            $fname = uniqid('res_') . '.' . $ext;
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $dest . $fname)) {
                $resume_path = 'uploads/resumes/' . $fname;
                if ($ext === 'pdf') $resume_text = extract_pdf_text($dest . $fname);
            }
        }
        $conn->prepare("INSERT INTO job_applications
            (job_id,portal,name,email,phone,experience,current_ctc,expected_ctc,notice_period,skills,cover_letter,resume_path,resume_text,stage)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'APPLIED')")
            ->execute([
                $_POST['job_id']      ?: null,
                $_POST['portal']      ?? 'OTHER',
                trim($_POST['name']),
                trim($_POST['email']),
                trim($_POST['phone']),
                $_POST['experience']  !== '' ? (float)$_POST['experience']  : null,
                $_POST['current_ctc'] !== '' ? (float)$_POST['current_ctc'] : null,
                $_POST['exp_ctc']     !== '' ? (float)$_POST['exp_ctc']     : null,
                $_POST['notice']      !== '' ? (int)$_POST['notice']        : null,
                trim($_POST['skills']),
                trim($_POST['cover_letter']),
                $resume_path,
                $resume_text,
            ]);
        set_flash('success', 'Application added.');
        header('Location: applications.php?' . http_build_query(array_filter(['job_id'=>$_POST['job_id']??'','stage'=>$_GET['stage']??'','portal'=>$_GET['portal']??'']))); exit;
    }

    if ($action === 'update_stage') {
        $conn->prepare("UPDATE job_applications SET stage=? WHERE id=?")
             ->execute([$_POST['stage'], (int)$_POST['app_id']]);
        // If moving to INTERVIEW, create interview record
        if ($_POST['stage'] === 'INTERVIEW') {
            $app = $conn->prepare("SELECT * FROM job_applications WHERE id=?");
            $app->execute([(int)$_POST['app_id']]);
            $a = $app->fetch();
            if ($a) {
                $cand_check = $conn->prepare("SELECT id FROM candidates WHERE email=? LIMIT 1");
                $cand_check->execute([$a['email']]);
                $cand_id = $cand_check->fetchColumn();
                if (!$cand_id) {
                    $conn->prepare("INSERT INTO candidates (name,email,phone,experience,skills,applied_role,status) VALUES (?,?,?,?,?,(SELECT title FROM job_postings WHERE id=? LIMIT 1),'INTERVIEW')")
                         ->execute([$a['name'],$a['email'],$a['phone'],$a['experience'],$a['skills'],$a['job_id']]);
                    $cand_id = $conn->lastInsertId();
                } else {
                    $conn->prepare("UPDATE candidates SET status='INTERVIEW' WHERE id=?")->execute([$cand_id]);
                }
                $conn->prepare("INSERT INTO interviews (candidate_id,round,status) VALUES (?,1,'SCHEDULED')")
                     ->execute([$cand_id]);
            }
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'add_note') {
        $conn->prepare("UPDATE job_applications SET notes=? WHERE id=?")
             ->execute([trim($_POST['notes']), (int)$_POST['app_id']]);
        echo json_encode(['ok'=>true]); exit;
    }
}

// ── FILTERS ───────────────────────────────────────────────
$filter_job    = (int)($_GET['job_id'] ?? 0);
$filter_stage  = $_GET['stage']  ?? '';
$filter_portal = $_GET['portal'] ?? '';
$search        = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filter_job)    { $where[] = 'ja.job_id = ?';   $params[] = $filter_job; }
if ($filter_stage)  { $where[] = 'ja.stage = ?';    $params[] = $filter_stage; }
if ($filter_portal) { $where[] = 'ja.portal = ?';   $params[] = $filter_portal; }
if ($search)        { $where[] = '(ja.name LIKE ? OR ja.email LIKE ? OR ja.skills LIKE ?)';
                      $params  = array_merge($params, ["%$search%","%$search%","%$search%"]); }

$s = $conn->prepare("
    SELECT ja.*, jp.title as job_title, jp.dept_id,
           u.name as assigned_name
    FROM job_applications ja
    LEFT JOIN job_postings jp ON jp.id = ja.job_id
    LEFT JOIN users u         ON u.id  = ja.assigned_to
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ja.created_at DESC
");
$s->execute($params);
$apps = $s->fetchAll();

// Stats (always across all apps)
$stage_counts = [];
foreach ($conn->query("SELECT stage, COUNT(*) as c FROM job_applications GROUP BY stage") as $r) {
    $stage_counts[$r['stage']] = $r['c'];
}
$total_apps = array_sum($stage_counts);

// Jobs list for filter dropdown
$jobs = $conn->query("SELECT id, title FROM job_postings ORDER BY created_at DESC")->fetchAll();

$stages  = ['APPLIED','SCREENED','INTERVIEW','OFFERED','HIRED','REJECTED'];
$portals = ['LINKEDIN','INDEED','NAUKRI','INTERNSHALA','HRMS','EMAIL','OTHER'];
$stage_colors = [
    'APPLIED'  => ['bg'=>'#eff6ff','clr'=>'#1d4ed8'],
    'SCREENED' => ['bg'=>'#f0fdf4','clr'=>'#15803d'],
    'INTERVIEW'=> ['bg'=>'#fffbeb','clr'=>'#b45309'],
    'OFFERED'  => ['bg'=>'#faf5ff','clr'=>'#7e22ce'],
    'HIRED'    => ['bg'=>'#f0fdf4','clr'=>'#15803d'],
    'REJECTED' => ['bg'=>'#fef2f2','clr'=>'#b91c1c'],
];
$portal_colors = [
    'LINKEDIN'=>'#0077b5','INDEED'=>'#003a9b','NAUKRI'=>'#ff7555',
    'INTERNSHALA'=>'#006fb9','HRMS'=>'#22c55e','EMAIL'=>'#6366f1','OTHER'=>'#94a3b8',
];

include 'header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0">Applications
            <?php if ($filter_job && $jobs): ?>
            <span class="text-muted fw-normal fs-6">— <?= sanitize(array_column($jobs,'title','id')[$filter_job] ?? '') ?></span>
            <?php endif; ?>
        </h5>
        <div class="text-muted small">Track candidates across all job postings</div>
    </div>
    <div class="d-flex gap-2">
        <a href="jobs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-briefcase me-1"></i>Job Postings</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAppModal">
            <i class="bi bi-person-plus me-1"></i>Add Application
        </button>
    </div>
</div>

<!-- ── PIPELINE STATS ─────────────────────────────────────── -->
<div class="d-flex gap-2 mb-4 overflow-auto pb-1">
    <?php
    $stage_icons = ['APPLIED'=>'bi-inbox','SCREENED'=>'bi-funnel','INTERVIEW'=>'bi-camera-video',
                    'OFFERED'=>'bi-gift','HIRED'=>'bi-person-check','REJECTED'=>'bi-x-circle'];
    foreach ($stages as $st):
        $cnt = $stage_counts[$st] ?? 0;
        $sc  = $stage_colors[$st];
        $active = $filter_stage === $st;
    ?>
    <a href="?<?= http_build_query(array_filter(['job_id'=>$filter_job,'portal'=>$filter_portal,'q'=>$search,'stage'=>$active?'':$st])) ?>"
       class="text-decoration-none flex-shrink-0">
        <div class="card border-0 shadow-sm p-3 text-center <?= $active ? 'border border-primary' : '' ?>" style="border-radius:12px;min-width:100px;background:<?=$active?$sc['bg']:'#fff'?>;">
            <i class="<?=$stage_icons[$st]?>" style="color:<?=$sc['clr']?>;font-size:1rem;"></i>
            <div class="fw-bold mt-1" style="color:<?=$sc['clr']?>;font-size:1.4rem;"><?=$cnt?></div>
            <div style="font-size:.65rem;color:#64748b;"><?=$st?></div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── FILTERS ────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4 p-3" style="border-radius:12px;">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, skills..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Job Posting</label>
            <select name="job_id" class="form-select form-select-sm">
                <option value="">All Jobs</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?=$j['id']?>" <?= $filter_job==$j['id']?'selected':'' ?>><?= sanitize($j['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">Stage</label>
            <select name="stage" class="form-select form-select-sm">
                <option value="">All Stages</option>
                <?php foreach ($stages as $st): ?>
                <option value="<?=$st?>" <?= $filter_stage===$st?'selected':'' ?>><?=$st?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">Portal</label>
            <select name="portal" class="form-select form-select-sm">
                <option value="">All Portals</option>
                <?php foreach ($portals as $p): ?>
                <option value="<?=$p?>" <?= $filter_portal===$p?'selected':'' ?>><?=$p?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filter</button>
            <a href="applications.php" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </form>
</div>

<!-- ── APPLICATIONS TABLE ─────────────────────────────────── -->
<?php if (!$apps): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px;">
    <i class="bi bi-inbox text-muted" style="font-size:2.5rem;"></i>
    <p class="text-muted mt-3 mb-0">No applications found for these filters.</p>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Candidate</th>
                        <th>Applied For</th>
                        <th>Source</th>
                        <th>Experience</th>
                        <th>CTC (Exp)</th>
                        <th>Notice</th>
                        <th>Stage</th>
                        <th>Applied On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($apps as $app):
                    $sc = $stage_colors[$app['stage']] ?? ['bg'=>'#f8fafc','clr'=>'#64748b'];
                    $pc = $portal_colors[$app['portal']] ?? '#94a3b8';
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;">
                                <?= strtoupper(substr($app['name'],0,2)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?= sanitize($app['name']) ?></div>
                                <div class="text-muted" style="font-size:.7rem;"><?= sanitize($app['email']) ?></div>
                                <?php if ($app['phone']): ?>
                                <div class="text-muted" style="font-size:.7rem;"><?= sanitize($app['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="small text-muted"><?= sanitize($app['job_title'] ?? '—') ?></td>
                    <td>
                        <span class="badge rounded-pill" style="background:<?=$pc?>20;color:<?=$pc?>;font-size:.65rem;">
                            <?= $app['portal'] ?>
                        </span>
                    </td>
                    <td class="small"><?= $app['experience'] !== null ? $app['experience'].'y' : '—' ?></td>
                    <td class="small">
                        <?php if ($app['expected_ctc']): ?>
                        <span class="text-success fw-semibold"><?= number_format($app['expected_ctc']/100000,1) ?>L</span>
                        <?php if ($app['current_ctc']): ?>
                        <span class="text-muted"> / <?= number_format($app['current_ctc']/100000,1) ?>L</span>
                        <?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="small"><?= $app['notice_period'] !== null ? $app['notice_period'].'d' : '—' ?></td>
                    <td>
                        <select class="form-select form-select-sm stage-select"
                                data-id="<?=$app['id']?>"
                                style="font-size:.72rem;min-width:110px;border-radius:20px;background:<?=$sc['bg']?>;color:<?=$sc['clr']?>;border-color:<?=$sc['clr']?>30;">
                            <?php foreach ($stages as $st): ?>
                            <option value="<?=$st?>" <?= $app['stage']===$st?'selected':'' ?>><?=$st?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="small text-muted"><?= date('d M Y', strtotime($app['created_at'])) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if ($app['resume_path']): ?>
                            <a href="<?= sanitize($app['resume_path']) ?>" target="_blank"
                               class="btn btn-xs btn-outline-secondary" title="Download Resume"
                               style="padding:2px 6px;font-size:.7rem;">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-outline-primary" title="View / Note"
                                    style="padding:2px 6px;font-size:.7rem;"
                                    onclick="openNote(<?=$app['id']?>, <?= htmlspecialchars(json_encode($app['notes'] ?? '')) ?>)">
                                <i class="bi bi-chat-left-text"></i>
                            </button>
                            <?php if ($app['stage'] !== 'INTERVIEW' && $app['stage'] !== 'HIRED'): ?>
                            <button class="btn btn-xs btn-outline-warning" title="Move to Interview"
                                    style="padding:2px 6px;font-size:.7rem;"
                                    onclick="moveToInterview(<?=$app['id']?>)">
                                <i class="bi bi-camera-video"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-muted small border-top">
            <?= count($apps) ?> application(s)
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ ADD APPLICATION MODAL ═════════════════════════════ -->
<div class="modal fade" id="addAppModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Add Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_application">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Job Posting</label>
                            <select name="job_id" class="form-select">
                                <option value="">— Select Job —</option>
                                <?php foreach ($jobs as $j): ?>
                                <option value="<?=$j['id']?>" <?= $filter_job==$j['id']?'selected':'' ?>><?= sanitize($j['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Source Portal</label>
                            <select name="portal" class="form-select">
                                <?php foreach ($portals as $p): ?>
                                <option value="<?=$p?>"><?=$p?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Experience (yrs)</label>
                            <input type="number" name="experience" class="form-control" step="0.5">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Current CTC (₹)</label>
                            <input type="number" name="current_ctc" class="form-control" step="10000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Expected CTC (₹)</label>
                            <input type="number" name="exp_ctc" class="form-control" step="10000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Notice Period (days)</label>
                            <input type="number" name="notice" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Skills</label>
                            <input type="text" name="skills" class="form-control" placeholder="PHP, MySQL, React...">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Cover Letter / Notes</label>
                            <textarea name="cover_letter" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Resume (PDF)</label>
                            <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Add Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ NOTE MODAL ════════════════════════════════════════ -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold"><i class="bi bi-chat-left-text me-2"></i>Notes</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="note_app_id">
                <textarea id="note_text" class="form-control" rows="5" placeholder="Add screening notes, feedback..."></textarea>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="saveNote()"><i class="bi bi-check-lg me-1"></i>Save Note</button>
            </div>
        </div>
    </div>
</div>

<script>
// Inline stage update
document.querySelectorAll('.stage-select').forEach(sel => {
    sel.addEventListener('change', function() {
        const id = this.dataset.id;
        const stage = this.value;
        fetch('applications.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `action=update_stage&app_id=${id}&stage=${stage}`
        }).then(() => {
            if (stage === 'INTERVIEW') {
                showToast('Moved to Interview — candidate added to Interviews module.');
            }
        });
        // Update select styling
        const colors = <?= json_encode($stage_colors) ?>;
        const sc = colors[stage] || {bg:'#f8fafc',clr:'#64748b'};
        this.style.background = sc.bg;
        this.style.color = sc.clr;
        this.style.borderColor = sc.clr + '30';
    });
});

function moveToInterview(id) {
    if (!await hConfirm('Move this candidate to Interview stage?')) return;
    fetch('applications.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=update_stage&app_id=${id}&stage=INTERVIEW`
    }).then(() => location.reload());
}

function openNote(id, notes) {
    document.getElementById('note_app_id').value = id;
    document.getElementById('note_text').value = notes || '';
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

function saveNote() {
    const id    = document.getElementById('note_app_id').value;
    const notes = document.getElementById('note_text').value;
    fetch('applications.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=add_note&app_id=${id}&notes=${encodeURIComponent(notes)}`
    }).then(() => {
        bootstrap.Modal.getInstance(document.getElementById('noteModal')).hide();
        showToast('Note saved.');
    });
}

function showToast(msg) {
    const d = document.createElement('div');
    d.className = 'alert alert-success shadow py-2 px-3';
    d.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;border-radius:10px;';
    d.innerHTML = `<i class="bi bi-check-circle me-1"></i>${msg}`;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3000);
}
</script>

<?php include 'footer.php'; ?>

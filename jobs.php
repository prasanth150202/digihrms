<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER');
$page      = 'jobs';
$pageTitle = 'Job Postings';

$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

// ── POST HANDLERS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_job') {
        $id      = (int)($_POST['job_id'] ?? 0);
        $fields  = [
            trim($_POST['title']),
            $_POST['dept_id']       ?: null,
            trim($_POST['description']),
            trim($_POST['requirements']),
            trim($_POST['location']),
            $_POST['job_type'],
            (float)($_POST['experience_min'] ?? 0),
            $_POST['experience_max'] !== '' ? (float)$_POST['experience_max'] : null,
            $_POST['salary_min']     !== '' ? (float)$_POST['salary_min']     : null,
            $_POST['salary_max']     !== '' ? (float)$_POST['salary_max']     : null,
            max(1, (int)($_POST['openings'] ?? 1)),
            $_POST['deadline']       ?: null,
            $_POST['status'],
        ];
        if ($id) {
            $conn->prepare("UPDATE job_postings SET title=?,dept_id=?,description=?,requirements=?,location=?,job_type=?,experience_min=?,experience_max=?,salary_min=?,salary_max=?,openings=?,deadline=?,status=? WHERE id=?")
                 ->execute([...$fields, $id]);
            set_flash('success', 'Job posting updated.');
        } else {
            $conn->prepare("INSERT INTO job_postings (title,dept_id,description,requirements,location,job_type,experience_min,experience_max,salary_min,salary_max,openings,deadline,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                 ->execute([...$fields, current_user()['id']]);
            set_flash('success', 'Job posting created successfully.');
        }
        header('Location: jobs.php'); exit;
    }

    if ($action === 'mark_posted') {
        $job_id = (int)$_POST['job_id'];
        $portal = $_POST['portal'];
        $url    = trim($_POST['posted_url'] ?? '');
        $conn->prepare("INSERT INTO job_portal_posts (job_id,portal,posted_url,status,posted_at) VALUES (?,?,?,'POSTED',NOW())
                        ON DUPLICATE KEY UPDATE status='POSTED',posted_url=?,posted_at=NOW()")
             ->execute([$job_id, $portal, $url, $url]);
        set_flash('success', "Marked as posted on $portal.");
        header('Location: jobs.php'); exit;
    }

    if ($action === 'change_status') {
        $conn->prepare("UPDATE job_postings SET status=? WHERE id=?")
             ->execute([$_POST['status'], (int)$_POST['job_id']]);
        header('Location: jobs.php'); exit;
    }
}

// ── DATA ──────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';

$where = $filter !== 'all' ? "WHERE jp.status = '" . $conn->quote($filter) . "'" : '';
// Use a safe approach for enum filter
$allowed_filters = ['DRAFT','ACTIVE','PAUSED','CLOSED'];
$filter_sql = in_array(strtoupper($filter), $allowed_filters)
    ? "WHERE jp.status = '" . strtoupper($filter) . "'"
    : '';

$jobs = $conn->query("
    SELECT jp.*, d.name as dept_name, u.name as creator_name,
           COUNT(DISTINCT ja.id) as app_count
    FROM job_postings jp
    LEFT JOIN departments d  ON d.id = jp.dept_id
    LEFT JOIN users u        ON u.id = jp.created_by
    LEFT JOIN job_applications ja ON ja.job_id = jp.id
    $filter_sql
    GROUP BY jp.id
    ORDER BY FIELD(jp.status,'ACTIVE','PAUSED','DRAFT','CLOSED'), jp.created_at DESC
")->fetchAll();

// Portal posts keyed by job_id → portal
$portal_posts = [];
if ($jobs) {
    $ids = implode(',', array_map('intval', array_column($jobs, 'id')));
    foreach ($conn->query("SELECT * FROM job_portal_posts WHERE job_id IN ($ids)") as $p) {
        $portal_posts[$p['job_id']][$p['portal']] = $p;
    }
}

// Stats
$stats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(status='ACTIVE') as active,
        SUM(status='DRAFT')  as draft,
        SUM(status='CLOSED') as closed
    FROM job_postings
")->fetch();
$apps_today = $conn->query("SELECT COUNT(*) FROM job_applications WHERE DATE(created_at)=CURDATE()")->fetchColumn();

$depts = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$job_types = ['FULL_TIME'=>'Full Time','PART_TIME'=>'Part Time','CONTRACT'=>'Contract','INTERNSHIP'=>'Internship'];
$portals = [
    'LINKEDIN'   => ['label'=>'LinkedIn',   'icon'=>'bi-linkedin',    'color'=>'#0077b5', 'url'=>'https://www.linkedin.com/jobs/post/new/'],
    'INDEED'     => ['label'=>'Indeed',     'icon'=>'bi-briefcase',   'color'=>'#003a9b', 'url'=>'https://employers.indeed.com/p/post-job'],
    'NAUKRI'     => ['label'=>'Naukri',     'icon'=>'bi-building',    'color'=>'#ff7555', 'url'=>'https://www.naukri.com/recruiter/'],
    'INTERNSHALA'=> ['label'=>'Internshala','icon'=>'bi-mortarboard', 'color'=>'#006fb9', 'url'=>'https://internshala.com/'],
];

// Edit mode: load single job
$edit_job = null;
if (isset($_GET['edit'])) {
    $s = $conn->prepare("SELECT * FROM job_postings WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $edit_job = $s->fetch();
}

include 'header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0">Job Postings</h5>
        <div class="text-muted small">Create jobs and track postings across portals</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#jobModal" onclick="openJobModal()">
        <i class="bi bi-plus-lg me-1"></i>New Job Posting
    </button>
</div>

<!-- ── STATS ──────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total Jobs',      $stats['total'],  '#6366f1', 'bi-briefcase-fill'],
        ['Active',          $stats['active'], '#22c55e', 'bi-broadcast'],
        ['Draft',           $stats['draft'],  '#f59e0b', 'bi-file-earmark'],
        ['Closed',          $stats['closed'], '#94a3b8', 'bi-archive'],
        ['Applied Today',   $apps_today,      '#3b82f6', 'bi-person-plus-fill'],
    ] as [$lbl,$val,$clr,$ico]): ?>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 text-center" style="border-radius:12px;">
            <i class="<?=$ico?>" style="color:<?=$clr?>;font-size:1.1rem;"></i>
            <div class="fw-bold mt-1" style="font-size:1.8rem;color:<?=$clr?>;line-height:1.1;"><?=$val?></div>
            <div class="text-muted small mt-1"><?=$lbl?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── FILTER TABS ────────────────────────────────────────── -->
<ul class="nav nav-pills gap-2 mb-4">
    <?php foreach (['all'=>'All','ACTIVE'=>'Active','DRAFT'=>'Draft','PAUSED'=>'Paused','CLOSED'=>'Closed'] as $k=>$lbl): ?>
    <li><a class="nav-link rounded-pill <?= $filter===$k?'active':'' ?>" href="?filter=<?=$k?>">
        <?=$lbl?>
    </a></li>
    <?php endforeach; ?>
</ul>

<!-- ── JOB CARDS ──────────────────────────────────────────── -->
<?php if (!$jobs): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px;">
    <i class="bi bi-briefcase text-muted" style="font-size:2.5rem;"></i>
    <p class="text-muted mt-3 mb-3">No job postings found.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#jobModal" onclick="openJobModal()">
        <i class="bi bi-plus-lg me-1"></i>Create First Job Posting
    </button>
</div>
<?php else: ?>
<div class="row g-3">
<?php
$status_colors = ['ACTIVE'=>'#22c55e','DRAFT'=>'#f59e0b','PAUSED'=>'#94a3b8','CLOSED'=>'#ef4444'];
foreach ($jobs as $job):
    $sclr = $status_colors[$job['status']] ?? '#6b7280';
    $apply_url = $base_url . 'job_apply.php?id=' . $job['id'];
    $jd_text = "Position: {$job['title']}\n"
             . "Location: {$job['location']}\n"
             . "Type: {$job['job_type']}\n"
             . "Experience: {$job['experience_min']}–{$job['experience_max']} yrs\n\n"
             . "About the Role:\n{$job['description']}\n\n"
             . "Requirements:\n{$job['requirements']}\n\n"
             . "Apply: $apply_url";
?>
<div class="col-md-6 col-xl-4">
<div class="card border-0 shadow-sm h-100" style="border-radius:14px;border-left:4px solid <?=$sclr?> !important;">
    <div class="card-body">
        <!-- Title row -->
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="flex-grow-1 me-2">
                <div class="fw-bold" style="font-size:1rem;"><?= sanitize($job['title']) ?></div>
                <?php if ($job['dept_name']): ?>
                <span class="badge bg-primary-subtle text-primary rounded-pill" style="font-size:.65rem;">
                    <i class="bi bi-diagram-3 me-1"></i><?= sanitize($job['dept_name']) ?>
                </span>
                <?php endif; ?>
            </div>
            <span class="badge rounded-pill" style="background:<?=$sclr?>20;color:<?=$sclr?>;font-size:.65rem;white-space:nowrap;">
                <?= $job['status'] ?>
            </span>
        </div>

        <!-- Meta -->
        <div class="d-flex flex-wrap gap-2 mb-3" style="font-size:.75rem;color:#64748b;">
            <span><i class="bi bi-geo-alt me-1"></i><?= sanitize($job['location'] ?: 'Remote') ?></span>
            <span><i class="bi bi-briefcase me-1"></i><?= $job_types[$job['job_type']] ?? $job['job_type'] ?></span>
            <span><i class="bi bi-clock me-1"></i><?= $job['experience_min'] ?>–<?= $job['experience_max'] ?: '∞' ?> yrs</span>
            <?php if ($job['salary_min']): ?>
            <span><i class="bi bi-currency-rupee me-1"></i><?= number_format($job['salary_min']/100000,1) ?>–<?= number_format($job['salary_max']/100000,1) ?>L</span>
            <?php endif; ?>
        </div>

        <!-- Openings + Applications -->
        <div class="d-flex gap-3 mb-3">
            <div class="text-center">
                <div class="fw-bold text-primary"><?= $job['openings'] ?></div>
                <div style="font-size:.65rem;color:#94a3b8;">Openings</div>
            </div>
            <div class="text-center">
                <div class="fw-bold text-success"><?= $job['app_count'] ?></div>
                <div style="font-size:.65rem;color:#94a3b8;">Applications</div>
            </div>
            <?php if ($job['deadline']): ?>
            <div class="text-center">
                <div class="fw-bold <?= $job['deadline'] < date('Y-m-d') ? 'text-danger' : 'text-warning' ?>">
                    <?= date('d M', strtotime($job['deadline'])) ?>
                </div>
                <div style="font-size:.65rem;color:#94a3b8;">Deadline</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Portal status dots -->
        <div class="d-flex gap-2 mb-3 align-items-center">
            <span style="font-size:.65rem;color:#94a3b8;">Portals:</span>
            <?php foreach ($portals as $pk => $pv):
                $pp = $portal_posts[$job['id']][$pk] ?? null;
                $posted = $pp && $pp['status'] === 'POSTED';
            ?>
            <span title="<?=$pv['label']?><?= $posted ? ' — Posted '.date('d M',strtotime($pp['posted_at'])) : ' — Not posted' ?>"
                  style="width:22px;height:22px;border-radius:50%;background:<?= $posted ? $pv['color'] : '#e2e8f0' ?>;
                         display:flex;align-items:center;justify-content:center;cursor:default;">
                <i class="<?=$pv['icon']?>" style="font-size:.6rem;color:<?= $posted ? '#fff' : '#94a3b8' ?>;"></i>
            </span>
            <?php endforeach; ?>
            <!-- Own site -->
            <span title="Your apply page" style="width:22px;height:22px;border-radius:50%;background:<?= $job['status']==='ACTIVE' ? '#22c55e' : '#e2e8f0' ?>;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-house-fill" style="font-size:.6rem;color:<?= $job['status']==='ACTIVE' ? '#fff' : '#94a3b8' ?>;"></i>
            </span>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-1 flex-wrap">
            <a href="applications.php?job_id=<?=$job['id']?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-people me-1"></i>Applications
            </a>
            <button class="btn btn-sm btn-outline-success"
                    onclick="openPortalModal(<?=$job['id']?>, <?= htmlspecialchars(json_encode($job['title'])) ?>, <?= htmlspecialchars(json_encode($jd_text)) ?>)">
                <i class="bi bi-share me-1"></i>Post to Portals
            </button>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius:10px;font-size:.85rem;">
                    <li><button class="dropdown-item" onclick="openJobModal(<?= htmlspecialchars(json_encode($job)) ?>)">
                        <i class="bi bi-pencil me-2"></i>Edit</button></li>
                    <li><a class="dropdown-item" href="<?= sanitize($apply_url) ?>" target="_blank">
                        <i class="bi bi-box-arrow-up-right me-2"></i>Apply Page</a></li>
                    <li><button class="dropdown-item" onclick="copyText('<?= sanitize($apply_url) ?>')">
                        <i class="bi bi-link me-2"></i>Copy Apply Link</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($job['status'] !== 'ACTIVE'): ?>
                    <li>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="job_id" value="<?=$job['id']?>">
                            <input type="hidden" name="status" value="ACTIVE">
                            <button type="submit" class="dropdown-item text-success"><i class="bi bi-broadcast me-2"></i>Set Active</button>
                        </form>
                    </li>
                    <?php endif; ?>
                    <?php if ($job['status'] !== 'PAUSED' && $job['status'] !== 'DRAFT'): ?>
                    <li>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="job_id" value="<?=$job['id']?>">
                            <input type="hidden" name="status" value="PAUSED">
                            <button type="submit" class="dropdown-item text-warning"><i class="bi bi-pause me-2"></i>Pause</button>
                        </form>
                    </li>
                    <?php endif; ?>
                    <?php if ($job['status'] !== 'CLOSED'): ?>
                    <li>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="job_id" value="<?=$job['id']?>">
                            <input type="hidden" name="status" value="CLOSED">
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i>Close Job</button>
                        </form>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ CREATE / EDIT JOB MODAL ═══════════════════════════ -->
<div class="modal fade" id="jobModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="jobModalTitle"><i class="bi bi-briefcase me-2 text-primary"></i>New Job Posting</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="jobForm">
                <input type="hidden" name="action" value="save_job">
                <input type="hidden" name="job_id" id="jf_id" value="0">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Job Title *</label>
                            <input type="text" name="title" id="jf_title" class="form-control" placeholder="e.g. Senior PHP Developer" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Department</label>
                            <select name="dept_id" id="jf_dept" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?=$d['id']?>"><?= sanitize($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Job Type</label>
                            <select name="job_type" id="jf_type" class="form-select">
                                <?php foreach ($job_types as $k=>$v): ?>
                                <option value="<?=$k?>"><?=$v?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Location</label>
                            <input type="text" name="location" id="jf_location" class="form-control" placeholder="Chennai / Remote / Hybrid">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="jf_status" class="form-select">
                                <option value="DRAFT">Draft</option>
                                <option value="ACTIVE">Active</option>
                                <option value="PAUSED">Paused</option>
                                <option value="CLOSED">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Min Experience (yrs)</label>
                            <input type="number" name="experience_min" id="jf_exp_min" class="form-control" value="0" step="0.5" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Max Experience (yrs)</label>
                            <input type="number" name="experience_max" id="jf_exp_max" class="form-control" step="0.5" placeholder="Optional">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Openings</label>
                            <input type="number" name="openings" id="jf_openings" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Salary Min (₹)</label>
                            <input type="number" name="salary_min" id="jf_sal_min" class="form-control" step="10000" placeholder="e.g. 400000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Salary Max (₹)</label>
                            <input type="number" name="salary_max" id="jf_sal_max" class="form-control" step="10000" placeholder="e.g. 800000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Application Deadline</label>
                            <input type="date" name="deadline" id="jf_deadline" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Job Description *</label>
                            <textarea name="description" id="jf_desc" class="form-control" rows="5"
                                      placeholder="Describe the role, responsibilities, company culture..." required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Requirements / Skills</label>
                            <textarea name="requirements" id="jf_req" class="form-control" rows="4"
                                      placeholder="List required skills, qualifications, tools..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Job</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ POST TO PORTALS MODAL ═════════════════════════════ -->
<div class="modal fade" id="portalModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-share me-2 text-success"></i>Post to Job Portals</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>How it works:</strong> Copy the JD below → Open the portal → Paste & submit → Come back and mark as posted.
                </div>

                <!-- Copy JD -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold mb-0">Job Description (ready to paste)</label>
                        <button class="btn btn-sm btn-outline-primary" onclick="copyJD()">
                            <i class="bi bi-clipboard me-1" id="jdCopyIcon"></i>Copy JD
                        </button>
                    </div>
                    <textarea id="jdTextarea" class="form-control font-monospace" rows="6" readonly style="font-size:.78rem;background:#f8fafc;resize:none;"></textarea>
                </div>

                <!-- Portal cards -->
                <?php foreach ($portals as $pk => $pv): ?>
                <div class="card border-0 bg-light mb-3" style="border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div style="width:36px;height:36px;border-radius:8px;background:<?=$pv['color']?>;display:flex;align-items:center;justify-content:center;">
                                <i class="<?=$pv['icon']?> text-white"></i>
                            </div>
                            <div class="fw-semibold"><?=$pv['label']?></div>
                            <a href="<?=$pv['url']?>" target="_blank" class="btn btn-sm ms-auto" style="background:<?=$pv['color']?>;color:#fff;">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open <?=$pv['label']?>
                            </a>
                        </div>
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="action" value="mark_posted">
                            <input type="hidden" name="job_id" id="portal_job_id_<?=$pk?>" value="">
                            <input type="hidden" name="portal" value="<?=$pk?>">
                            <input type="url" name="posted_url" class="form-control form-control-sm"
                                   placeholder="Paste the job URL after posting (optional)">
                            <button type="submit" class="btn btn-sm btn-success text-nowrap">
                                <i class="bi bi-check me-1"></i>Mark Posted
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Own apply link -->
                <div class="card border-success border mb-0" style="border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div style="width:36px;height:36px;border-radius:8px;background:#22c55e;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-house-fill text-white"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Your Own Apply Page</div>
                                <div class="text-muted" style="font-size:.72rem;">Share this link anywhere — no portal needed</div>
                            </div>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" id="applyLinkField" class="form-control font-monospace" readonly>
                            <button class="btn btn-success" onclick="copyApplyLink()">
                                <i class="bi bi-clipboard" id="applyLinkIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<div id="copyToast" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:none;">
    <div class="alert alert-success shadow-sm py-2 px-3 mb-0" style="border-radius:10px;">
        <i class="bi bi-check-circle me-1"></i><span id="copyToastMsg">Copied!</span>
    </div>
</div>

<script>
let _portalJobId = 0;

function openJobModal(job) {
    const modal = document.getElementById('jobModal');
    if (job) {
        document.getElementById('jobModalTitle').innerHTML = '<i class="bi bi-pencil me-2 text-primary"></i>Edit Job Posting';
        document.getElementById('jf_id').value       = job.id;
        document.getElementById('jf_title').value    = job.title;
        document.getElementById('jf_dept').value     = job.dept_id || '';
        document.getElementById('jf_type').value     = job.job_type;
        document.getElementById('jf_location').value = job.location || '';
        document.getElementById('jf_status').value   = job.status;
        document.getElementById('jf_exp_min').value  = job.experience_min;
        document.getElementById('jf_exp_max').value  = job.experience_max || '';
        document.getElementById('jf_openings').value = job.openings;
        document.getElementById('jf_sal_min').value  = job.salary_min || '';
        document.getElementById('jf_sal_max').value  = job.salary_max || '';
        document.getElementById('jf_deadline').value = job.deadline || '';
        document.getElementById('jf_desc').value     = job.description || '';
        document.getElementById('jf_req').value      = job.requirements || '';
    } else {
        document.getElementById('jobModalTitle').innerHTML = '<i class="bi bi-briefcase me-2 text-primary"></i>New Job Posting';
        document.getElementById('jobForm').reset();
        document.getElementById('jf_id').value = '0';
    }
    new bootstrap.Modal(modal).show();
}

function openPortalModal(jobId, title, jd) {
    _portalJobId = jobId;
    document.getElementById('jdTextarea').value = jd;
    document.querySelectorAll('[id^="portal_job_id_"]').forEach(el => el.value = jobId);
    const applyLink = '<?= $base_url ?>job_apply.php?id=' + jobId;
    document.getElementById('applyLinkField').value = applyLink;
    new bootstrap.Modal(document.getElementById('portalModal')).show();
}

function copyJD() {
    navigator.clipboard.writeText(document.getElementById('jdTextarea').value).then(() => {
        document.getElementById('jdCopyIcon').className = 'bi bi-check-lg me-1';
        showToast('Job description copied!');
        setTimeout(() => document.getElementById('jdCopyIcon').className = 'bi bi-clipboard me-1', 2500);
    });
}

function copyApplyLink() {
    navigator.clipboard.writeText(document.getElementById('applyLinkField').value).then(() => {
        document.getElementById('applyLinkIcon').className = 'bi bi-check-lg';
        showToast('Apply link copied!');
        setTimeout(() => document.getElementById('applyLinkIcon').className = 'bi bi-clipboard', 2500);
    });
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied!'));
}

function showToast(msg) {
    const t = document.getElementById('copyToast');
    document.getElementById('copyToastMsg').textContent = msg;
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 2500);
}

<?php if ($edit_job): ?>
document.addEventListener('DOMContentLoaded', () => openJobModal(<?= json_encode($edit_job) ?>));
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>

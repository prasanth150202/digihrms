<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'portals';
$pageTitle = 'Portal Connections';

$tab      = $_GET['tab'] ?? 'linkedin';
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$li_redirect  = $base_url . 'linkedin_callback.php';
$in_redirect  = $base_url . 'indeed_callback.php';

// ── Load stored configs ───────────────────────────────────
$configs = [];
foreach ($conn->query("SELECT * FROM portal_configs") as $r) {
    $configs[$r['portal']] = $r;
}
$li = $configs['LINKEDIN'] ?? null;
$nk = $configs['NAUKRI']   ?? null;
$in = $configs['INDEED']   ?? null;

$li_extra     = $li ? json_decode($li['extra'] ?? '{}', true) : [];
$in_extra     = $in ? json_decode($in['extra'] ?? '{}', true) : [];
$li_connected = $li && !empty($li['access_token'])
             && (!$li['token_expiry'] || $li['token_expiry'] > date('Y-m-d H:i:s'));
$nk_connected = $nk && !empty($nk['access_token']);
$in_connected = $in && !empty($in['access_token'])
             && (!$in['token_expiry'] || $in['token_expiry'] > date('Y-m-d H:i:s'));

// ── POST HANDLERS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── LinkedIn: save credentials
    if ($action === 'save_linkedin_creds') {
        $cid = trim($_POST['client_id']);
        $sec = trim($_POST['client_secret']);
        $conn->prepare("INSERT INTO portal_configs (portal,client_id,client_secret) VALUES ('LINKEDIN',?,?)
                        ON DUPLICATE KEY UPDATE client_id=?,client_secret=?")
             ->execute([$cid, $sec, $cid, $sec]);
        set_flash('success', 'LinkedIn app credentials saved. Click "Connect Account" to authorise.');
        header('Location: portals.php?tab=linkedin'); exit;
    }

    // ── LinkedIn: disconnect
    if ($action === 'disconnect_linkedin') {
        $conn->prepare("UPDATE portal_configs SET access_token=NULL,token_expiry=NULL,extra=NULL WHERE portal='LINKEDIN'")->execute();
        set_flash('success', 'LinkedIn account disconnected.');
        header('Location: portals.php?tab=linkedin'); exit;
    }

    // ── LinkedIn: post a job as a feed share
    if ($action === 'post_to_linkedin') {
        $job_id = (int)$_POST['job_id'];
        $s = $conn->prepare("SELECT jp.*, d.name as dept_name FROM job_postings jp LEFT JOIN departments d ON d.id=jp.dept_id WHERE jp.id=?");
        $s->execute([$job_id]);
        $job = $s->fetch();

        if (!$job || !$li_connected) {
            set_flash('danger', 'Job not found or LinkedIn not connected.');
            header('Location: portals.php?tab=linkedin'); exit;
        }

        $person_id  = $li_extra['person_id']   ?? null;
        $author_urn = $person_id ? "urn:li:person:$person_id" : null;

        // If org URN is provided, use that instead
        $org_id = trim($_POST['org_id'] ?? '');
        if ($org_id) $author_urn = "urn:li:organization:$org_id";

        if (!$author_urn) {
            set_flash('danger', 'Could not determine LinkedIn author. Please reconnect.');
            header('Location: portals.php?tab=linkedin'); exit;
        }

        $apply_url = $base_url . 'job_apply.php?id=' . $job_id;
        $exp_range = $job['experience_min'] . '–' . ($job['experience_max'] ?: '∞') . ' yrs';
        $salary    = ($job['salary_min'] && $job['salary_max'])
                   ? '₹' . number_format($job['salary_min']/100000, 1) . '–' . number_format($job['salary_max']/100000, 1) . 'L'
                   : '';

        $commentary = "🚀 We're Hiring: {$job['title']}";
        if ($job['dept_name']) $commentary .= " ({$job['dept_name']})";
        $commentary .= "\n\n📍 Location: " . ($job['location'] ?: 'Remote/Hybrid');
        $commentary .= "\n⏱ Experience: $exp_range";
        if ($salary)  $commentary .= "\n💰 CTC: $salary";
        $commentary .= "\n\n" . substr(strip_tags($job['description']), 0, 700);
        if (strlen($job['description']) > 700) $commentary .= '...';
        $commentary .= "\n\n👉 Apply here: $apply_url";
        $commentary .= "\n\n#hiring #jobs #recruitment #{$job['job_type']}";

        $body = [
            'author'          => $author_urn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => $commentary],
                    'shareMediaCategory' => 'ARTICLE',
                    'media'              => [[
                        'status'      => 'READY',
                        'description' => ['text' => substr(strip_tags($job['description']), 0, 256)],
                        'originalUrl' => $apply_url,
                        'title'       => ['text' => $job['title'] . ' — Apply Now'],
                    ]],
                ]
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];

        $ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $li['access_token'],
                'Content-Type: application/json',
                'X-Restli-Protocol-Version: 2.0.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resp = json_decode($raw, true);

        if (in_array($code, [200, 201])) {
            $post_url = 'https://www.linkedin.com/feed/update/' . ($resp['id'] ?? '');
            $conn->prepare("INSERT INTO job_portal_posts (job_id,portal,posted_url,status,posted_at) VALUES (?,?,?,'POSTED',NOW())
                            ON DUPLICATE KEY UPDATE status='POSTED',posted_url=?,posted_at=NOW()")
                 ->execute([$job_id, 'LINKEDIN', $post_url, $post_url]);
            set_flash('success', 'Job posted to LinkedIn successfully! <a href="' . sanitize($post_url) . '" target="_blank" class="alert-link">View post →</a>');
        } else {
            $msg = $resp['message'] ?? ($resp['error_description'] ?? substr($raw, 0, 300));
            set_flash('danger', "LinkedIn API error ($code): $msg");
        }
        header('Location: portals.php?tab=linkedin'); exit;
    }

    // ── Naukri: save API key
    if ($action === 'save_naukri_key') {
        $key  = trim($_POST['naukri_api_key']);
        $cid  = trim($_POST['naukri_client_id'] ?? '');
        $conn->prepare("INSERT INTO portal_configs (portal,client_id,access_token) VALUES ('NAUKRI',?,?)
                        ON DUPLICATE KEY UPDATE client_id=?,access_token=?")
             ->execute([$cid, $key, $cid, $key]);
        set_flash('success', 'Naukri API credentials saved.');
        header('Location: portals.php?tab=naukri'); exit;
    }

    // ── Naukri: post job via API (requires Naukri RMS API access)
    if ($action === 'post_to_naukri') {
        $job_id = (int)$_POST['job_id'];
        $s = $conn->prepare("SELECT jp.*, d.name as dept_name FROM job_postings jp LEFT JOIN departments d ON d.id=jp.dept_id WHERE jp.id=?");
        $s->execute([$job_id]);
        $job = $s->fetch();

        if (!$job || !$nk_connected) {
            set_flash('danger', 'Job not found or Naukri not configured.');
            header('Location: portals.php?tab=naukri'); exit;
        }

        $apply_url = $base_url . 'job_apply.php?id=' . $job_id;
        $job_types_naukri = ['FULL_TIME'=>'Permanent','PART_TIME'=>'Part Time','CONTRACT'=>'Contractual','INTERNSHIP'=>'Internship'];

        // Naukri RMS API payload (structure may vary based on their API version)
        $body = [
            'title'          => $job['title'],
            'description'    => $job['description'] . "\n\nRequirements:\n" . $job['requirements'],
            'location'       => $job['location'] ?: 'India',
            'minExp'         => (float)$job['experience_min'],
            'maxExp'         => $job['experience_max'] ? (float)$job['experience_max'] : (float)$job['experience_min'] + 5,
            'minSalary'      => $job['salary_min'] ? (int)$job['salary_min'] : 0,
            'maxSalary'      => $job['salary_max'] ? (int)$job['salary_max'] : 0,
            'jobType'        => $job_types_naukri[$job['job_type']] ?? 'Permanent',
            'openings'       => (int)$job['openings'],
            'applyUrl'       => $apply_url,
        ];

        // Naukri API endpoint varies — this is the common RMS endpoint format
        $naukri_endpoint = 'https://www.naukri.com/rms/mnjUser/jobs';
        $ch = curl_init($naukri_endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $nk['access_token'],
                'Content-Type: application/json',
                'X-NaukriClientId: ' . ($nk['client_id'] ?? ''),
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resp = json_decode($raw, true);

        if (in_array($code, [200, 201])) {
            $ext_id  = $resp['jobId'] ?? $resp['id'] ?? null;
            $post_url = 'https://www.naukri.com/job-listings-' . $job['title'] . '-' . ($ext_id ?? '');
            $conn->prepare("INSERT INTO job_portal_posts (job_id,portal,external_id,posted_url,status,posted_at) VALUES (?,?,?,?,'POSTED',NOW())
                            ON DUPLICATE KEY UPDATE status='POSTED',external_id=?,posted_url=?,posted_at=NOW()")
                 ->execute([$job_id,'NAUKRI',$ext_id,$post_url,$ext_id,$post_url]);
            set_flash('success', 'Job posted to Naukri!');
        } else {
            $msg = $resp['message'] ?? $resp['error'] ?? substr($raw, 0, 300);
            set_flash('danger', "Naukri API error ($code): $msg. Check your API key and access level.");
        }
        header('Location: portals.php?tab=naukri'); exit;
    }

    // ── Indeed: save credentials
    if ($action === 'save_indeed_creds') {
        $cid = trim($_POST['indeed_client_id']);
        $sec = trim($_POST['indeed_client_secret']);
        $conn->prepare("INSERT INTO portal_configs (portal,client_id,client_secret) VALUES ('INDEED',?,?)
                        ON DUPLICATE KEY UPDATE client_id=?,client_secret=?")
             ->execute([$cid, $sec, $cid, $sec]);
        set_flash('success', 'Indeed app credentials saved. Click "Connect Account" to authorise.');
        header('Location: portals.php?tab=indeed'); exit;
    }

    // ── Indeed: disconnect
    if ($action === 'disconnect_indeed') {
        $conn->prepare("UPDATE portal_configs SET access_token=NULL,token_expiry=NULL,extra=NULL WHERE portal='INDEED'")->execute();
        set_flash('success', 'Indeed account disconnected.');
        header('Location: portals.php?tab=indeed'); exit;
    }

    // ── Indeed: post job via Employer API
    if ($action === 'post_to_indeed') {
        $job_id = (int)$_POST['job_id'];
        $s = $conn->prepare("SELECT jp.*, d.name as dept_name FROM job_postings jp LEFT JOIN departments d ON d.id=jp.dept_id WHERE jp.id=?");
        $s->execute([$job_id]);
        $job = $s->fetch();

        if (!$job || !$in_connected) {
            set_flash('danger', 'Job not found or Indeed not connected.');
            header('Location: portals.php?tab=indeed'); exit;
        }

        $apply_url = $base_url . 'job_apply.php?id=' . $job_id;
        $job_types_indeed = ['FULL_TIME'=>'FULL_TIME','PART_TIME'=>'PART_TIME','CONTRACT'=>'CONTRACTOR','INTERNSHIP'=>'INTERNSHIP'];
        $employer_id = $in_extra['employer_id'] ?? null;

        $body = [
            'title'           => $job['title'],
            'description'     => strip_tags($job['description'] . "\n\nRequirements:\n" . $job['requirements']),
            'location'        => ['city' => $job['location'] ?: 'India'],
            'employmentType'  => $job_types_indeed[$job['job_type']] ?? 'FULL_TIME',
            'numPositions'    => (int)$job['openings'],
            'applyUrl'        => $apply_url,
            'externalApplyLink' => $apply_url,
        ];
        if ($job['salary_min'] && $job['salary_max']) {
            $body['salary'] = [
                'minimum' => ['value' => (float)$job['salary_min'], 'currencyCode' => 'INR', 'frequency' => 'YEARLY'],
                'maximum' => ['value' => (float)$job['salary_max'], 'currencyCode' => 'INR', 'frequency' => 'YEARLY'],
            ];
        }

        $api_url = $employer_id
            ? "https://employers.indeed.com/api/v2/employers/{$employer_id}/jobs"
            : 'https://employers.indeed.com/api/v2/jobs';

        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $in['access_token'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resp = json_decode($raw, true);

        if (in_array($code, [200, 201])) {
            $ext_id   = $resp['jobId'] ?? $resp['id'] ?? null;
            $post_url = $ext_id ? "https://www.indeed.com/viewjob?jk=$ext_id" : 'https://employers.indeed.com/jobs';
            $conn->prepare("INSERT INTO job_portal_posts (job_id,portal,external_id,posted_url,status,posted_at) VALUES (?,?,?,?,'POSTED',NOW())
                            ON DUPLICATE KEY UPDATE status='POSTED',external_id=?,posted_url=?,posted_at=NOW()")
                 ->execute([$job_id,'INDEED',$ext_id,$post_url,$ext_id,$post_url]);
            set_flash('success', 'Job posted to Indeed! <a href="' . sanitize($post_url) . '" target="_blank" class="alert-link">View posting →</a>');
        } else {
            $msg = $resp['message'] ?? $resp['error'] ?? $resp['detail'] ?? substr($raw, 0, 300);
            set_flash('danger', "Indeed API error ($code): $msg");
        }
        header('Location: portals.php?tab=indeed'); exit;
    }
}

// ── OAuth connect availability (state is generated at click-time by
// linkedin_connect.php / indeed_connect.php, not baked into this page —
// otherwise a stale reload/tab/cache overwrites the session state before
// the user actually clicks, causing "Invalid OAuth state") ──────────────
$li_can_connect = $li && !empty($li['client_id']);
$in_can_connect = $in && !empty($in['client_id']);

// ── Active jobs for posting ───────────────────────────────
$active_jobs = $conn->query("SELECT jp.id, jp.title, jp.location, jp.experience_min, jp.experience_max,
    (SELECT COUNT(*) FROM job_portal_posts jpp WHERE jpp.job_id=jp.id AND jpp.portal='LINKEDIN' AND jpp.status='POSTED') as li_posted,
    (SELECT COUNT(*) FROM job_portal_posts jpp WHERE jpp.job_id=jp.id AND jpp.portal='NAUKRI'   AND jpp.status='POSTED') as nk_posted,
    (SELECT COUNT(*) FROM job_portal_posts jpp WHERE jpp.job_id=jp.id AND jpp.portal='INDEED'   AND jpp.status='POSTED') as in_posted
    FROM job_postings jp WHERE jp.status='ACTIVE' ORDER BY jp.created_at DESC")->fetchAll();

include 'header.php';
?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-0">Portal Connections</h5>
        <div class="text-muted small">Connect LinkedIn, Naukri, and Indeed to post jobs directly</div>
    </div>
    <div class="d-flex gap-2">
        <a href="import_portals.php" class="btn btn-outline-success btn-sm"><i class="bi bi-cloud-download me-1"></i>Import Old Posts</a>
        <a href="jobs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-briefcase me-1"></i>Job Postings</a>
    </div>
</div>

<!-- ── STATUS CHIPS ───────────────────────────────────────── -->
<div class="d-flex gap-3 mb-4 flex-wrap">
    <?php foreach ([
        ['LinkedIn', $li_connected, '#0077b5', 'bi-linkedin'],
        ['Naukri',   $nk_connected, '#ff7555', 'bi-building'],
        ['Indeed',   $in_connected, '#003a9b', 'bi-briefcase-fill'],
    ] as [$name, $connected, $clr, $ico]): ?>
    <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-pill border"
         style="background:<?= $connected ? $clr.'12' : '#f8fafc' ?>;border-color:<?= $connected ? $clr.'40' : '#e2e8f0' ?> !important;">
        <i class="<?=$ico?>" style="color:<?=$clr?>;"></i>
        <span class="small fw-semibold" style="color:<?=$clr?>"><?=$name?></span>
        <span class="badge rounded-pill" style="font-size:.6rem;background:<?= $connected ? '#22c55e' : '#e2e8f0' ?>;color:<?= $connected ? '#fff' : '#64748b' ?>;">
            <?= $connected ? 'Connected' : 'Not Connected' ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── TABS ───────────────────────────────────────────────── -->
<ul class="nav nav-pills gap-2 mb-4">
    <li><a class="nav-link rounded-pill <?= $tab==='linkedin'?'active':'' ?>" href="?tab=linkedin" style="<?= $tab==='linkedin'?'':'color:#0077b5;' ?>">
        <i class="bi bi-linkedin me-1"></i>LinkedIn</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='naukri'?'active':'' ?>" href="?tab=naukri" style="<?= $tab==='naukri'?'':'color:#ff7555;' ?>">
        <i class="bi bi-building me-1"></i>Naukri</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='indeed'?'active':'' ?>" href="?tab=indeed" style="<?= $tab==='indeed'?'':'color:#003a9b;' ?>">
        <i class="bi bi-briefcase-fill me-1"></i>Indeed</a></li>
</ul>

<!-- ══════════════════════════════════════════════════════════
     LINKEDIN TAB
══════════════════════════════════════════════════════════ -->
<?php if ($tab === 'linkedin'): ?>

<div class="row g-4">
<!-- ── Step 1: App Credentials ──────────────────────────── -->
<div class="col-md-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;background:#0077b5;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-linkedin text-white"></i>
                </div>
                <div>
                    <div class="fw-bold">LinkedIn App</div>
                    <div class="text-muted" style="font-size:.72rem;">Step 1 of 2</div>
                </div>
            </div>

            <div class="alert alert-info small py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <a href="https://www.linkedin.com/developers/apps/new" target="_blank" class="alert-link">Create a LinkedIn App</a>
                → Products → <strong>Share on LinkedIn</strong> → get Client ID & Secret.
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_linkedin_creds">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client ID *</label>
                    <input type="text" name="client_id" class="form-control font-monospace"
                           value="<?= sanitize($li['client_id'] ?? '') ?>" required placeholder="86abc123xyz...">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client Secret *</label>
                    <div class="input-group">
                        <input type="password" name="client_secret" id="li_secret" class="form-control font-monospace"
                               value="<?= sanitize($li['client_secret'] ?? '') ?>" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleField('li_secret','li_eye')">
                            <i class="bi bi-eye" id="li_eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Redirect URI (add this to your LinkedIn app)</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control font-monospace bg-light" readonly value="<?= sanitize($li_redirect) ?>">
                        <button type="button" class="btn btn-outline-secondary" onclick="copyText('<?= sanitize($li_redirect) ?>')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-floppy me-1"></i>Save App Credentials
                </button>
            </form>
        </div>
    </div>

    <!-- Step 2: Connect account -->
    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;background:<?= $li_connected ? '#22c55e' : '#94a3b8' ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-<?= $li_connected ? 'check-lg' : 'person-badge' ?> text-white"></i>
                </div>
                <div>
                    <div class="fw-bold">Account Connection</div>
                    <div class="text-muted" style="font-size:.72rem;">Step 2 of 2</div>
                </div>
            </div>

            <?php if ($li_connected): ?>
            <div class="alert alert-success small py-2 mb-3">
                <i class="bi bi-check-circle me-1"></i>
                Connected as <strong><?= sanitize($li_extra['person_name'] ?? 'LinkedIn User') ?></strong>
                <?php if ($li['token_expiry']): ?>
                · Token expires <?= date('d M Y', strtotime($li['token_expiry'])) ?>
                <?php endif; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disconnect_linkedin">
                <button type="submit" class="btn btn-outline-danger w-100 btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Disconnect LinkedIn Account
                </button>
            </form>
            <?php elseif ($li_can_connect): ?>
            <p class="text-muted small mb-3">Authorise this app to post on your behalf via OAuth 2.0.</p>
            <a href="linkedin_connect.php" class="btn w-100" style="background:#0077b5;color:#fff;">
                <i class="bi bi-linkedin me-2"></i>Connect LinkedIn Account
            </a>
            <?php else: ?>
            <div class="alert alert-warning small py-2 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>Save your Client ID & Secret first (Step 1), then connect.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Post Jobs to LinkedIn ────────────────────────────── -->
<div class="col-md-7">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Post Active Jobs to LinkedIn</h6>
            <p class="text-muted small mb-3">
                Posts job as a public share/article on your LinkedIn profile. Includes apply link, salary, and JD preview.
                <?php if (!$li_connected): ?><span class="text-warning">(Connect your account first)</span><?php endif; ?>
            </p>

            <!-- Optional: Company page org ID -->
            <div class="alert alert-info small py-2 mb-3">
                <i class="bi bi-building me-1"></i>
                To post as a <strong>Company Page</strong> instead of your personal profile, enter your
                <a href="https://www.linkedin.com/company/admin/overview/" target="_blank" class="alert-link">Company's Organisation ID</a>
                in the field below. Leave blank to post from your personal profile.
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Organisation ID (optional)</label>
                <input type="text" id="orgIdInput" class="form-control form-control-sm font-monospace" placeholder="e.g. 12345678">
            </div>

            <?php if (!$active_jobs): ?>
            <div class="text-center py-4 text-muted small">No active job postings. <a href="jobs.php">Create one →</a></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Job</th>
                            <th>LinkedIn</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active_jobs as $j): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= sanitize($j['title']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?= sanitize($j['location'] ?: 'Remote') ?></div>
                        </td>
                        <td>
                            <?php if ($j['li_posted']): ?>
                            <span class="badge bg-success-subtle text-success" style="font-size:.65rem;"><i class="bi bi-check me-1"></i>Posted</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem;">Not posted</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($li_connected): ?>
                            <form method="POST" class="d-inline post-form-li">
                                <input type="hidden" name="action"   value="post_to_linkedin">
                                <input type="hidden" name="job_id"   value="<?=$j['id']?>">
                                <input type="hidden" name="org_id"   class="org-id-field" value="">
                                <button type="submit" class="btn btn-sm py-0 px-2"
                                        style="background:#0077b5;color:#fff;font-size:.72rem;border-radius:6px;">
                                    <i class="bi bi-linkedin me-1"></i><?= $j['li_posted'] ? 'Re-post' : 'Post Now' ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled style="font-size:.72rem;">
                                <i class="bi bi-lock me-1"></i>Connect first
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div><!-- /row -->

<!-- ══════════════════════════════════════════════════════════
     NAUKRI TAB
══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'naukri'): ?>

<div class="row g-4">
<!-- ── Credentials ─────────────────────────────────────── -->
<div class="col-md-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;background:#ff7555;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-building text-white"></i>
                </div>
                <div>
                    <div class="fw-bold">Naukri RMS API</div>
                    <div class="text-muted" style="font-size:.72rem;">Recruiter Management System</div>
                </div>
            </div>

            <div class="alert alert-warning small py-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Naukri's API requires a <strong>registered recruiter account + API access</strong> from
                Naukri's business team. Contact them at
                <a href="mailto:apipartner@naukri.com" class="alert-link">apipartner@naukri.com</a>
                or your account manager to get your API key and Client ID.
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_naukri_key">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client ID (from Naukri)</label>
                    <input type="text" name="naukri_client_id" class="form-control font-monospace"
                           value="<?= sanitize($nk['client_id'] ?? '') ?>" placeholder="NQT_CLIENTID_...">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">API Key / Bearer Token</label>
                    <div class="input-group">
                        <input type="password" name="naukri_api_key" id="nk_key" class="form-control font-monospace"
                               value="<?= sanitize($nk['access_token'] ?? '') ?>" placeholder="Paste your API key">
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleField('nk_key','nk_eye')">
                            <i class="bi bi-eye" id="nk_eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100" style="background:#ff7555;border-color:#ff7555;">
                    <i class="bi bi-floppy me-1"></i>Save Naukri Credentials
                </button>
            </form>

            <?php if ($nk_connected): ?>
            <div class="alert alert-success small py-2 mt-3 mb-0">
                <i class="bi bi-check-circle me-1"></i>API key saved. Test by posting a job →
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Manual posting fallback -->
    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-2"><i class="bi bi-hand-index me-2 text-warning"></i>Don't have API access yet?</h6>
            <p class="text-muted small mb-3">Use the manual flow — copy your JD and post on Naukri's website. Mark it posted after.</p>
            <a href="https://www.naukri.com/recruiter/" target="_blank" class="btn btn-sm w-100" style="background:#ff7555;color:#fff;">
                <i class="bi bi-box-arrow-up-right me-1"></i>Open Naukri Recruiter
            </a>
            <div class="text-center mt-2">
                <a href="jobs.php" class="small text-muted">Go to Job Postings → Post to Portals (manual)</a>
            </div>
        </div>
    </div>
</div>

<!-- ── Post Jobs to Naukri ──────────────────────────────── -->
<div class="col-md-7">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Post Active Jobs to Naukri</h6>
            <p class="text-muted small mb-3">
                Calls Naukri RMS API to publish jobs directly.
                <?php if (!$nk_connected): ?><span class="text-warning">(Enter API key first)</span><?php endif; ?>
            </p>

            <?php if (!$active_jobs): ?>
            <div class="text-center py-4 text-muted small">No active job postings. <a href="jobs.php">Create one →</a></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Job</th>
                            <th>Naukri</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active_jobs as $j): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= sanitize($j['title']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?= sanitize($j['location'] ?: 'Remote') ?></div>
                        </td>
                        <td>
                            <?php if ($j['nk_posted']): ?>
                            <span class="badge bg-success-subtle text-success" style="font-size:.65rem;"><i class="bi bi-check me-1"></i>Posted</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem;">Not posted</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($nk_connected): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="post_to_naukri">
                                <input type="hidden" name="job_id" value="<?=$j['id']?>">
                                <button type="submit" class="btn btn-sm py-0 px-2"
                                        style="background:#ff7555;color:#fff;font-size:.72rem;border-radius:6px;">
                                    <i class="bi bi-building me-1"></i><?= $j['nk_posted'] ? 'Re-post' : 'Post Now' ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled style="font-size:.72rem;">
                                <i class="bi bi-lock me-1"></i>API key needed
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- API notes -->
            <div class="mt-4 p-3 rounded" style="background:#fff7ed;border:1px solid #fed7aa;">
                <div class="small fw-semibold text-warning mb-1"><i class="bi bi-exclamation-circle me-1"></i>API Note</div>
                <div class="small text-muted">
                    Naukri's RMS API endpoint and response format may differ based on your account tier.
                    If you get an API error, verify your credentials in the
                    <a href="https://www.naukri.com/recruiter/rms/developer" target="_blank">Naukri Developer Portal</a>
                    or contact your Naukri account manager for the correct endpoint URL.
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /row -->

<!-- ══════════════════════════════════════════════════════════
     INDEED TAB
══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'indeed'): ?>
<div class="row g-4">

<!-- Step 1 + 2: Credentials + Connect -->
<div class="col-md-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;background:#003a9b;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-briefcase-fill text-white"></i>
                </div>
                <div>
                    <div class="fw-bold">Indeed Employer App</div>
                    <div class="text-muted" style="font-size:.72rem;">Step 1 of 2</div>
                </div>
            </div>

            <div class="alert alert-info small py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                Register your app at <a href="https://employers.indeed.com/p/register" target="_blank" class="alert-link">employers.indeed.com/p/register</a>
                → get Client ID & Secret → add redirect URI below.
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_indeed_creds">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client ID *</label>
                    <input type="text" name="indeed_client_id" class="form-control font-monospace"
                           value="<?= sanitize($in['client_id'] ?? '') ?>" required placeholder="e.g. api_pub_...">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client Secret *</label>
                    <div class="input-group">
                        <input type="password" name="indeed_client_secret" id="in_secret" class="form-control font-monospace"
                               value="<?= sanitize($in['client_secret'] ?? '') ?>" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleField('in_secret','in_eye')">
                            <i class="bi bi-eye" id="in_eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Redirect URI (add to your Indeed app)</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control font-monospace bg-light" readonly value="<?= sanitize($in_redirect) ?>">
                        <button type="button" class="btn btn-outline-secondary" onclick="copyText('<?= sanitize($in_redirect) ?>')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-floppy me-1"></i>Save App Credentials
                </button>
            </form>
        </div>
    </div>

    <!-- Connect account -->
    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;background:<?= $in_connected ? '#22c55e' : '#94a3b8' ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-<?= $in_connected ? 'check-lg' : 'building' ?> text-white"></i>
                </div>
                <div>
                    <div class="fw-bold">Employer Account</div>
                    <div class="text-muted" style="font-size:.72rem;">Step 2 of 2</div>
                </div>
            </div>

            <?php if ($in_connected): ?>
            <div class="alert alert-success small py-2 mb-3">
                <i class="bi bi-check-circle me-1"></i>
                Connected as <strong><?= sanitize($in_extra['employer_name'] ?? 'Indeed Employer') ?></strong>
                <?php if ($in['token_expiry']): ?>
                · Token expires <?= date('d M Y', strtotime($in['token_expiry'])) ?>
                <?php endif; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disconnect_indeed">
                <button type="submit" class="btn btn-outline-danger w-100 btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Disconnect Indeed Account
                </button>
            </form>
            <?php elseif ($in_can_connect): ?>
            <p class="text-muted small mb-3">Connect your Indeed Employer account via OAuth 2.0.</p>
            <a href="indeed_connect.php" class="btn w-100" style="background:#003a9b;color:#fff;">
                <i class="bi bi-briefcase-fill me-2"></i>Connect Indeed Account
            </a>
            <?php else: ?>
            <div class="alert alert-warning small py-2 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>Save Client ID & Secret first (Step 1), then connect.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Post Jobs to Indeed -->
<div class="col-md-7">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Post Active Jobs to Indeed</h6>
            <p class="text-muted small mb-3">
                Posts directly to Indeed via their Employer API. Candidates can apply on Indeed or via your apply page.
                <?php if (!$in_connected): ?><span class="text-warning">(Connect your account first)</span><?php endif; ?>
            </p>

            <?php if (!$active_jobs): ?>
            <div class="text-center py-4 text-muted small">No active job postings. <a href="jobs.php">Create one →</a></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Job</th><th>Indeed</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($active_jobs as $j): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= sanitize($j['title']) ?></div>
                            <div class="text-muted" style="font-size:.7rem;"><?= sanitize($j['location'] ?: 'Remote') ?></div>
                        </td>
                        <td>
                            <?php if ($j['in_posted']): ?>
                            <span class="badge bg-success-subtle text-success" style="font-size:.65rem;"><i class="bi bi-check me-1"></i>Posted</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem;">Not posted</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($in_connected): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="post_to_indeed">
                                <input type="hidden" name="job_id" value="<?=$j['id']?>">
                                <button type="submit" class="btn btn-sm py-0 px-2"
                                        style="background:#003a9b;color:#fff;font-size:.72rem;border-radius:6px;">
                                    <i class="bi bi-briefcase-fill me-1"></i><?= $j['in_posted'] ? 'Re-post' : 'Post Now' ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2" disabled style="font-size:.72rem;">
                                <i class="bi bi-lock me-1"></i>Connect first
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="mt-4 p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                <div class="small fw-semibold" style="color:#1d4ed8;"><i class="bi bi-info-circle me-1"></i>Indeed API Access</div>
                <div class="small text-muted mt-1">
                    The Indeed Employer API requires a verified employer account. If posting fails,
                    <a href="https://employers.indeed.com/p/help" target="_blank">contact Indeed support</a>
                    to enable API access for your account. You can also
                    <a href="jobs.php">use the manual posting flow</a> in the meantime.
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /row -->

<?php endif; ?>

<script>
function toggleField(inputId, iconId) {
    const f = document.getElementById(inputId);
    const i = document.getElementById(iconId);
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        const d = document.createElement('div');
        d.className = 'alert alert-success shadow py-2 px-3';
        d.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;border-radius:10px;';
        d.innerHTML = '<i class="bi bi-check-circle me-1"></i>Copied!';
        document.body.appendChild(d);
        setTimeout(() => d.remove(), 2000);
    });
}

// Sync org ID from text field into hidden form fields before submit
document.querySelectorAll('.post-form-li').forEach(form => {
    form.addEventListener('submit', function() {
        this.querySelector('.org-id-field').value = document.getElementById('orgIdInput')?.value || '';
    });
});
</script>

<?php include 'footer.php'; ?>

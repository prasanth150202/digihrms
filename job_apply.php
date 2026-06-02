<?php
require_once 'config.php';
// Public page — no login required

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('<h2>Job not found.</h2>'); }

$s = $conn->prepare("SELECT jp.*, d.name as dept_name FROM job_postings jp LEFT JOIN departments d ON d.id=jp.dept_id WHERE jp.id=? AND jp.status='ACTIVE' LIMIT 1");
$s->execute([$id]);
$job = $s->fetch();

if (!$job) { http_response_code(404); die('<h2 style="font-family:sans-serif;padding:40px;">This job is no longer available.</h2>'); }

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']  ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    if (!$name)  $errors[] = 'Name is required.';
    if (!$email) $errors[] = 'Email is required.';

    if (!$errors) {
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
        // Check duplicate application
        $dup = $conn->prepare("SELECT id FROM job_applications WHERE job_id=? AND email=? LIMIT 1");
        $dup->execute([$id, $email]);
        if ($dup->fetch()) {
            $errors[] = 'You have already applied for this position.';
        } else {
            $conn->prepare("INSERT INTO job_applications
                (job_id,portal,name,email,phone,experience,current_ctc,expected_ctc,notice_period,skills,cover_letter,resume_path,resume_text,stage)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'APPLIED')")
                ->execute([
                    $id, 'HRMS',
                    $name, $email, $phone,
                    $_POST['experience']  !== '' ? (float)$_POST['experience']  : null,
                    $_POST['current_ctc'] !== '' ? (float)$_POST['current_ctc'] : null,
                    $_POST['exp_ctc']     !== '' ? (float)$_POST['exp_ctc']     : null,
                    $_POST['notice']      !== '' ? (int)$_POST['notice']        : null,
                    trim($_POST['skills']       ?? ''),
                    trim($_POST['cover_letter'] ?? ''),
                    $resume_path,
                    $resume_text,
                ]);
            $success = true;
        }
    }
}

$job_types = ['FULL_TIME'=>'Full Time','PART_TIME'=>'Part Time','CONTRACT'=>'Contract','INTERNSHIP'=>'Internship'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($job['title']) ?> — Apply Now</title>
    <link rel="icon" type="image/png" href="assets/images/fav.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; }
        .apply-wrap { max-width: 760px; margin: 0 auto; padding: 32px 16px; }
        .job-hero { background: linear-gradient(135deg, #1e293b, #0f4c81); border-radius: 16px; padding: 32px; color: #fff; margin-bottom: 24px; }
        .form-card { background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .form-control:focus, .form-select:focus { border-color: #0f4c81; box-shadow: 0 0 0 3px rgba(15,76,129,.12); }
        .btn-apply { background: linear-gradient(135deg, #0f4c81, #1e88e5); border: none; padding: 12px 32px; font-weight: 600; }
        .meta-chip { background: rgba(255,255,255,.15); border-radius: 20px; padding: 4px 12px; font-size: .78rem; display: inline-flex; align-items: center; gap: 6px; }
        .section-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 6px; }
    </style>
</head>
<body>

<div class="apply-wrap">
    <!-- Job Hero -->
    <div class="job-hero">
        <div class="d-flex align-items-start gap-3 mb-3">
            <div style="width:48px;height:48px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-briefcase-fill text-white fs-5"></i>
            </div>
            <div>
                <h4 class="fw-bold mb-1"><?= sanitize($job['title']) ?></h4>
                <?php if ($job['dept_name']): ?>
                <div style="color:#93c5fd;font-size:.85rem;"><?= sanitize($job['dept_name']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php if ($job['location']): ?>
            <span class="meta-chip"><i class="bi bi-geo-alt"></i><?= sanitize($job['location']) ?></span>
            <?php endif; ?>
            <span class="meta-chip"><i class="bi bi-briefcase"></i><?= $job_types[$job['job_type']] ?? $job['job_type'] ?></span>
            <span class="meta-chip"><i class="bi bi-clock"></i><?= $job['experience_min'] ?>–<?= $job['experience_max'] ?: '∞' ?> yrs exp</span>
            <?php if ($job['salary_min']): ?>
            <span class="meta-chip"><i class="bi bi-currency-rupee"></i><?= number_format($job['salary_min']/100000,1) ?>–<?= number_format($job['salary_max']/100000,1) ?>L CTC</span>
            <?php endif; ?>
            <?php if ($job['openings']): ?>
            <span class="meta-chip"><i class="bi bi-people"></i><?= $job['openings'] ?> opening<?= $job['openings']>1?'s':'' ?></span>
            <?php endif; ?>
            <?php if ($job['deadline']): ?>
            <span class="meta-chip"><i class="bi bi-calendar"></i>Apply by <?= date('d M Y', strtotime($job['deadline'])) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($job['description']): ?>
        <div class="mb-3">
            <div class="section-label">About the Role</div>
            <div style="font-size:.9rem;color:#cbd5e1;line-height:1.7;"><?= nl2br(sanitize($job['description'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($job['requirements']): ?>
        <div>
            <div class="section-label">Requirements</div>
            <div style="font-size:.9rem;color:#cbd5e1;line-height:1.7;"><?= nl2br(sanitize($job['requirements'])) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($success): ?>
    <!-- Success State -->
    <div class="form-card text-center py-5">
        <div style="width:72px;height:72px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <i class="bi bi-check-lg text-success" style="font-size:2rem;"></i>
        </div>
        <h5 class="fw-bold mb-2">Application Submitted!</h5>
        <p class="text-muted mb-0">Thank you for applying for <strong><?= sanitize($job['title']) ?></strong>.<br>
        Our team will review your application and reach out soon.</p>
    </div>

    <?php else: ?>
    <!-- Application Form -->
    <div class="form-card">
        <h5 class="fw-bold mb-1">Apply for this Position</h5>
        <p class="text-muted small mb-4">Fill in your details below. Fields marked * are required.</p>

        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger small py-2"><i class="bi bi-exclamation-circle me-1"></i><?= sanitize($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Full Name *</label>
                    <input type="text" name="name" class="form-control" value="<?= sanitize($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Email Address *</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Phone Number</label>
                    <input type="text" name="phone" class="form-control" value="<?= sanitize($_POST['phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Total Experience (years)</label>
                    <input type="number" name="experience" class="form-control" step="0.5" min="0" value="<?= sanitize($_POST['experience'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Notice Period (days)</label>
                    <input type="number" name="notice" class="form-control" min="0" value="<?= sanitize($_POST['notice'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Current CTC (₹ per annum)</label>
                    <input type="number" name="current_ctc" class="form-control" step="10000" value="<?= sanitize($_POST['current_ctc'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Expected CTC (₹ per annum)</label>
                    <input type="number" name="exp_ctc" class="form-control" step="10000" value="<?= sanitize($_POST['exp_ctc'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Key Skills</label>
                    <input type="text" name="skills" class="form-control" placeholder="e.g. PHP, MySQL, React, AWS..." value="<?= sanitize($_POST['skills'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Cover Letter / Why should we hire you?</label>
                    <textarea name="cover_letter" class="form-control" rows="4"><?= sanitize($_POST['cover_letter'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Resume (PDF or Word)</label>
                    <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                    <div class="form-text">Max 5MB. PDF preferred.</div>
                </div>
                <div class="col-12 pt-2">
                    <button type="submit" class="btn btn-apply btn-primary text-white w-100">
                        <i class="bi bi-send me-2"></i>Submit Application
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="text-center mt-4 text-muted small">
        Powered by <strong>Digifyce</strong>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

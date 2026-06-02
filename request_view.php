<?php
require_once 'config.php';
require_login();
$page      = 'requests';
$pageTitle = 'Manpower Request — Matched Candidates';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: requests.php"); exit; }

// Fetch request
$stmt = $conn->prepare("SELECT r.*, d.name as dept_name FROM manpower_requests r LEFT JOIN departments d ON r.dept_id=d.id WHERE r.id=?");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { set_flash('danger','Request not found.'); header("Location: requests.php"); exit; }

$title         = $req['title'];
$skills_req    = $req['skills_required'] ?? '';
$skill_arr     = array_filter(array_map('trim', explode(',', $skills_req)));

// Build WHERE: match by applied_role, skills field, OR resume_text (PDF content)
$where_parts  = ["LOWER(c.applied_role) LIKE ?"];
$where_params = ['%' . strtolower($title) . '%'];

foreach ($skill_arr as $skill) {
    $where_parts[]  = "LOWER(c.skills) LIKE ?";
    $where_params[] = '%' . strtolower($skill) . '%';
    // also search inside extracted PDF text
    $where_parts[]  = "LOWER(c.resume_text) LIKE ?";
    $where_params[] = '%' . strtolower($skill) . '%';
}
// match title keyword inside PDF text too
$where_parts[]  = "LOWER(c.resume_text) LIKE ?";
$where_params[] = '%' . strtolower($title) . '%';

$where = "WHERE c.status NOT IN ('HIRED','REJECTED') AND (" . implode(" OR ", $where_parts) . ")";

$cstmt = $conn->prepare("
    SELECT c.*,
        (SELECT COUNT(*) FROM interviews WHERE candidate_id=c.id) as interview_count,
        (SELECT MAX(date_time) FROM interviews WHERE candidate_id=c.id) as last_interview
    FROM candidates c
    $where
    ORDER BY c.created_at DESC
");
$cstmt->execute($where_params);
$candidates = $cstmt->fetchAll();

// Score each candidate (role=3pts, each skill in DB=1pt, each skill in PDF=1pt, title in PDF=2pts)
foreach ($candidates as &$c) {
    $score = 0;
    $pdf   = strtolower($c['resume_text'] ?? '');
    if (stripos($c['applied_role'] ?? '', $title) !== false) $score += 3;
    if ($pdf && stripos($pdf, strtolower($title)) !== false) $score += 2;
    foreach ($skill_arr as $skill) {
        if (stripos($c['skills'] ?? '', $skill) !== false) $score++;
        if ($pdf && stripos($pdf, strtolower($skill)) !== false) $score++;
    }
    $c['match_score']    = $score;
    $c['pdf_scanned']    = !empty($c['resume_text']);
    $possible = 5 + (count($skill_arr) * 2);
    $c['match_pct'] = $possible > 0 ? min(100, round(($score / $possible) * 100)) : 0;
}
unset($c);
usort($candidates, fn($a,$b) => $b['match_score'] - $a['match_score']);

$with_resume  = count(array_filter($candidates, fn($c) => !empty($c['resume_path'])));
$total        = count($candidates);

include 'header.php';
?>

<style>
.match-badge-high   { background:#dcfce7;color:#166534; }
.match-badge-medium { background:#dbeafe;color:#1e40af; }
.match-badge-low    { background:#fef9c3;color:#854d0e; }
.cand-accordion .accordion-button { background:#f8fafc; font-size:0.875rem; padding:12px 16px; }
.cand-accordion .accordion-button:not(.collapsed) { background:#eff6ff; color:#1e40af; }
.skill-tag { display:inline-block;background:#e0f2fe;color:#0369a1;border-radius:20px;padding:2px 10px;font-size:0.75rem;margin:2px; }
.skill-tag.match { background:#dcfce7;color:#166534; }
.stat-mini { border-radius:10px;padding:14px 18px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.07); }
</style>

<!-- Back -->
<div class="mb-3">
    <a href="requests.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i> Back to Requests</a>
</div>

<!-- Request Summary Card -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
        <div class="row align-items-start g-3">
            <div class="col-md-8">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-briefcase-fill text-white fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0"><?= sanitize($req['title']) ?></h4>
                        <div class="text-muted small"><?= sanitize($req['dept_name'] ?? 'No Department') ?> &nbsp;·&nbsp; <?= sanitize($req['requested_by'] ?? '') ?> &nbsp;·&nbsp; <?= date('d M Y', strtotime($req['created_at'])) ?></div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-<?= badge($req['status']) ?> rounded-pill px-3"><?= $req['status'] ?></span>
                    <span class="badge bg-<?= badge($req['urgency']) ?> rounded-pill px-3"><?= $req['urgency'] ?> Priority</span>
                    <span class="badge bg-secondary rounded-pill px-3"><?= $req['quantity'] ?> Position<?= $req['quantity'] > 1 ? 's' : '' ?></span>
                </div>
                <div class="row g-2 text-sm small">
                    <?php if ($req['experience']): ?>
                    <div class="col-auto"><i class="bi bi-briefcase text-muted me-1"></i><strong>Exp:</strong> <?= sanitize($req['experience']) ?></div>
                    <?php endif; ?>
                    <?php if ($req['salary_range']): ?>
                    <div class="col-auto"><i class="bi bi-currency-rupee text-muted"></i><strong>CTC:</strong> <?= sanitize($req['salary_range']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($req['reason']): ?>
                <div class="mt-2 text-muted small"><i class="bi bi-info-circle me-1"></i><?= sanitize($req['reason']) ?></div>
                <?php endif; ?>
                <?php if ($skill_arr): ?>
                <div class="mt-3">
                    <span class="text-muted small fw-semibold me-2">Required Skills:</span>
                    <?php foreach ($skill_arr as $sk): ?>
                        <span class="skill-tag match"><?= sanitize($sk) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="stat-mini text-center">
                            <div class="fw-bold fs-4 text-primary"><?= $total ?></div>
                            <div class="text-muted small">Matched</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-mini text-center">
                            <div class="fw-bold fs-4 text-success"><?= $with_resume ?></div>
                            <div class="text-muted small">With Resume</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Matched Candidates -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-people me-2"></i>Matched Candidates (<?= $total ?>)</h6>
    <?php if ($total): ?>
    <button class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.accordion-collapse').forEach(el=>{ let b=el.previousElementSibling?.querySelector('.accordion-button'); if(b&&b.classList.contains('collapsed'))b.click(); })">
        <i class="bi bi-arrows-expand me-1"></i> Expand All
    </button>
    <?php endif; ?>
</div>

<?php if (!$candidates): ?>
<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body text-center py-5">
        <i class="bi bi-person-x text-muted" style="font-size:2.5rem;"></i>
        <p class="text-muted mt-2">No candidates matched this request yet.</p>
        <a href="candidates.php" class="btn btn-sm btn-primary">Go to Candidates</a>
    </div>
</div>
<?php else: ?>

<div class="accordion cand-accordion" id="candAccordion">
<?php foreach ($candidates as $idx => $c): ?>
<?php
    $pct = $c['match_pct'];
    $badge_class = $pct >= 70 ? 'match-badge-high' : ($pct >= 40 ? 'match-badge-medium' : 'match-badge-low');
    $badge_label = $pct >= 70 ? 'Strong Match' : ($pct >= 40 ? 'Good Match' : 'Partial Match');
    $has_resume  = !empty($c['resume_path']) && file_exists($c['resume_path']);
    $c_skills    = array_filter(array_map('trim', explode(',', $c['skills'] ?? '')));
?>
<div class="card border-0 shadow-sm mb-2" style="border-radius:12px;overflow:hidden;">
    <div class="accordion-item border-0">
        <h2 class="accordion-header">
            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button"
                    data-bs-toggle="collapse" data-bs-target="#cand<?= $c['id'] ?>">
                <div class="d-flex align-items-center gap-3 w-100 me-3">
                    <!-- Avatar -->
                    <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.9rem;flex-shrink:0;">
                        <?= strtoupper(substr($c['name'],0,1)) ?>
                    </div>
                    <!-- Name + role -->
                    <div class="flex-grow-1 min-width-0">
                        <div class="fw-semibold d-flex align-items-center gap-2">
                            <?= sanitize($c['name']) ?>
                            <?php if ($has_resume): ?>
                            <i class="bi bi-file-earmark-pdf text-danger" title="Resume available"></i>
                            <?php endif; ?>
                            <?php if ($c['pdf_scanned']): ?>
                            <span class="badge bg-info-subtle text-info" style="font-size:0.65rem;">PDF Scanned</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small"><?= sanitize($c['applied_role'] ?? 'Role not set') ?> &nbsp;·&nbsp; <?= sanitize($c['experience'] ?? '-') ?> exp</div>
                    </div>
                    <!-- Match badge -->
                    <span class="badge rounded-pill px-3 py-1 <?= $badge_class ?>"><?= $badge_label ?> <?= $pct ?>%</span>
                    <!-- Status -->
                    <span class="badge bg-<?= badge($c['status']) ?> rounded-pill px-2 d-none d-md-inline"><?= $c['status'] ?></span>
                    <!-- Expected CTC -->
                    <span class="text-muted small d-none d-md-inline me-2">Expected: <?= sanitize($c['expected_salary'] ?? '-') ?></span>
                </div>
            </button>
        </h2>

        <div id="cand<?= $c['id'] ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
             data-bs-parent="">
            <div class="accordion-body pt-0 px-4 pb-4">
                <div class="row g-4">

                    <!-- LEFT: Details -->
                    <div class="col-md-4">
                        <div class="rounded-3 p-3" style="background:#f8fafc;">
                            <h6 class="fw-bold small mb-3">Contact & Details</h6>
                            <div class="d-flex flex-column gap-2 small">
                                <div><i class="bi bi-envelope text-muted me-2"></i><?= sanitize($c['email'] ?? '-') ?></div>
                                <div><i class="bi bi-phone text-muted me-2"></i><?= sanitize($c['phone'] ?? '-') ?></div>
                                <div><i class="bi bi-briefcase text-muted me-2"></i><?= sanitize($c['experience'] ?? '-') ?> experience</div>
                                <div><i class="bi bi-currency-rupee text-muted"></i>Current: <?= sanitize($c['current_salary'] ?? '-') ?></div>
                                <div><i class="bi bi-currency-rupee text-muted"></i>Expected: <?= sanitize($c['expected_salary'] ?? '-') ?></div>
                                <div><i class="bi bi-geo-alt text-muted me-2"></i>Source: <?= sanitize($c['source'] ?? '-') ?></div>
                                <div><i class="bi bi-camera-video text-muted me-2"></i><?= $c['interview_count'] ?> interview<?= $c['interview_count'] != 1 ? 's' : '' ?> done</div>
                                <div><i class="bi bi-calendar text-muted me-2"></i>Applied <?= date('d M Y', strtotime($c['created_at'])) ?></div>
                            </div>

                            <!-- Skills -->
                            <?php if ($c_skills): ?>
                            <h6 class="fw-bold small mt-3 mb-2">Skills</h6>
                            <div>
                                <?php foreach ($c_skills as $sk): ?>
                                <?php $is_match = array_filter($skill_arr, fn($r) => stripos($sk, $r) !== false || stripos($r, $sk) !== false); ?>
                                <span class="skill-tag <?= $is_match ? 'match' : '' ?>"><?= sanitize($sk) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="mt-3 d-flex flex-column gap-2">
                                <a href="candidate_profile.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary w-100" target="_blank">
                                    <i class="bi bi-person-lines-fill me-1"></i> Full Profile
                                </a>
                                <?php if ($has_resume): ?>
                                <button class="btn btn-sm btn-outline-danger w-100"
                                        data-bs-toggle="modal" data-bs-target="#pdfModal<?= $c['id'] ?>">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> View Resume PDF
                                </button>
                                <a href="<?= sanitize($c['resume_path']) ?>" download class="btn btn-sm btn-outline-secondary w-100">
                                    <i class="bi bi-download me-1"></i> Download Resume
                                </a>
                                <?php else: ?>
                                <div class="text-muted small text-center py-1"><i class="bi bi-file-earmark-x me-1"></i>No resume uploaded</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Resume PDF inline preview -->
                    <div class="col-md-8">
                        <?php if ($has_resume): ?>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="fw-bold small mb-0"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Resume Preview</h6>
                            <a href="<?= sanitize($c['resume_path']) ?>" target="_blank" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open in new tab
                            </a>
                        </div>
                        <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;height:500px;">
                            <iframe src="<?= sanitize($c['resume_path']) ?>"
                                    width="100%" height="100%" style="border:none;"></iframe>
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center h-100 rounded-3" style="background:#f8fafc;min-height:200px;border:2px dashed #e2e8f0;">
                            <div class="text-center text-muted">
                                <i class="bi bi-file-earmark-x" style="font-size:2.5rem;"></i>
                                <p class="mt-2 small">No resume uploaded for this candidate.</p>
                                <a href="candidate_profile.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">Upload via Profile</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($has_resume): ?>
<!-- PDF Fullscreen Modal -->
<div class="modal fade" id="pdfModal<?= $c['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;height:90vh;">
            <div class="modal-header border-0 py-2 px-4">
                <h6 class="modal-title fw-bold">
                    <i class="bi bi-file-earmark-pdf text-danger me-2"></i><?= sanitize($c['name']) ?> — Resume
                </h6>
                <div class="d-flex gap-2 align-items-center">
                    <a href="<?= sanitize($c['resume_path']) ?>" download class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <iframe src="<?= sanitize($c['resume_path']) ?>" width="100%"
                        style="border:none;height:calc(90vh - 60px);"></iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>

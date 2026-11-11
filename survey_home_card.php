<?php
// Partial: survey summary card on the admin home (index.php). SUPER_ADMIN / HR_ADMIN only.
// Never breaks the dashboard: any error just hides the card.
if (!has_role('SUPER_ADMIN', 'HR_ADMIN')) return;

try {
    require_once __DIR__ . '/survey_config.php';
    $svDefs   = survey_definitions($conn);
    $svPeriod = survey_period();
    $svCards  = [];
    foreach ($svDefs as $svKey => $svDef) {
        $svRows  = survey_load_responses($conn, $svKey, $svPeriod);
        $svScore = survey_score($svRows, $svDef);
        $svCards[$svKey] = [
            'def'     => $svDef,
            'score'   => $svScore,
            'pending' => count(survey_pending_users($conn, $svKey, $svPeriod)),
        ];
    }
} catch (Throwable $e) {
    error_log('survey home card: ' . $e->getMessage());
    return;
}
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="hrms-section-head mb-0"><i class="bi bi-clipboard-data"></i> Surveys · <?= sanitize(date('F Y')) ?></div>
            <a href="survey_results.php" class="small">Open results <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="row g-3">
        <?php foreach ($svCards as $svKey => $svC): $svS = $svC['score']; ?>
            <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="fw-semibold"><?= sanitize($svC['def']['title']) ?></div>
                        <?= empty($svC['def']['is_open']) ? '<span class="badge bg-secondary">Closed</span>' : '<span class="badge bg-success">Open</span>' ?>
                    </div>
                    <div class="d-flex flex-wrap gap-4">
                        <div><div class="small text-muted">Responses</div><div class="fs-4 fw-bold"><?= $svS['n'] ?></div></div>
                        <div><div class="small text-muted">Score / 5</div><div class="fs-4 fw-bold"><?= $svS['overall'] === null ? '—' : number_format($svS['overall'], 2) ?></div></div>
                        <?php if (!empty($svC['def']['enps'])): ?>
                        <div><div class="small text-muted">eNPS</div><div class="fs-4 fw-bold"><?= $svS['enps'] ? (($svS['enps']['score'] > 0 ? '+' : '') . $svS['enps']['score']) : '—' ?></div></div>
                        <?php endif; ?>
                        <div><div class="small text-muted">Not answered</div><div class="fs-4 fw-bold <?= $svC['pending'] ? 'text-warning' : 'text-success' ?>"><?= $svC['pending'] ?></div></div>
                    </div>
                    <div class="small mt-2 d-flex gap-3">
                        <a href="survey_results.php?s=<?= $svKey ?>">Results</a>
                        <a href="survey_results.php?s=<?= $svKey ?>&view=responses">Responses</a>
                        <a href="survey_results.php?s=<?= $svKey ?>&view=pending">Remind</a>
                        <a href="survey_manage.php?s=<?= $svKey ?>">Manage</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

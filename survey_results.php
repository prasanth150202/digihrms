<?php
// Survey results dashboard (EHI + Team Rubrics) — SUPER_ADMIN and HR_ADMIN only.
// Tabs: Overview · Responses (who said what) · Trends · Not answered.
// Editing questions, opening/closing and resetting live on survey_manage.php.
//
// Responses carry the respondent's email when their browser had the
// hrms_remember_email cookie. Rows from before Nov 2026 (no email, no
// submitted_at) were collected as anonymous and stay that way.

require_once 'config.php';
require_once 'survey_config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');

$page      = 'survey_results';
$pageTitle = 'Survey Results';

$defs = survey_definitions($conn);
$key  = strtoupper($_GET['s'] ?? 'EHI');
if (!isset($defs[$key])) $key = 'EHI';
$survey = $defs[$key];

$views = ['overview' => 'Overview', 'responses' => 'Responses', 'trends' => 'Trends', 'pending' => 'Not answered'];
$view  = $_GET['view'] ?? 'overview';
if (!isset($views[$view])) $view = 'overview';

// Months that have responses for this survey, newest first (+ this month).
$ps = $conn->prepare("SELECT DISTINCT period FROM survey_responses WHERE survey = ?");
$ps->execute([$key]);
$periods = $ps->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(survey_period(), $periods, true)) $periods[] = survey_period();
rsort($periods);

$period = $_GET['period'] ?? '';
if (!in_array($period, $periods, true)) $period = $periods[0];
$team = $_GET['team'] ?? '';
if ($team !== '' && !in_array($team, $survey['teams'], true)) $team = '';

function sv_url(array $over = []): string {
    global $key, $period, $team, $view;
    $q = array_merge(['s' => $key, 'view' => $view, 'period' => $period, 'team' => $team], $over);
    return 'survey_results.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

// ── Reminders (Not answered tab) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        set_flash('danger', 'Your session expired. Please try again.');
    } elseif (empty($survey['is_open'])) {
        set_flash('warning', 'This survey is closed. Open it on Manage Surveys before sending reminders.');
    } else {
        $pending = survey_pending_users($conn, $key, survey_period());
        $only    = (int)($_POST['user_id'] ?? 0);
        $sent    = 0;
        foreach ($pending as $p) {
            if ($only && (int)$p['id'] !== $only) continue;
            try {
                hrms_notify($conn, (int)$p['id'], 'survey', 'Please fill in the ' . $survey['title'],
                            'It only takes a few minutes. Thank you!', survey_path($key));
                $sent++;
            } catch (Throwable $e) { error_log('survey remind failed: ' . $e->getMessage()); }
        }
        set_flash($sent ? 'success' : 'warning', $sent ? "Reminder sent to $sent " . ($sent === 1 ? 'person' : 'people') . '.' : 'Nobody to remind.');
    }
    header('Location: ' . sv_url(['view' => 'pending', 'period' => survey_period()]));
    exit;
}

// ── Data ─────────────────────────────────────────────────────────────────────
$names = [];
foreach ($conn->query("SELECT LOWER(TRIM(email)) AS e, name FROM users WHERE email IS NOT NULL")->fetchAll() as $u_) {
    $names[$u_['e']] = $u_['name'];
}
function sv_who(array $r, array $names): array {
    if (!empty($r['email'])) return [$names[$r['email']] ?? $r['email'], $r['email']];
    return [empty($r['submitted_at']) ? 'Anonymous (collected before names)' : 'No name (no saved email)', null];
}

$all  = survey_load_responses($conn, $key, $period);
$rows = $team === '' ? $all : array_values(array_filter($all, fn($r) => $r['team'] === $team));
usort($rows, fn($a, $b) => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));

// ── CSV export (Responses tab) ───────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $qids = [];
    foreach ($survey['dimensions'] as $d) foreach ($d['questions'] as $qid => $_) $qids[] = $qid;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower($key) . '-' . $period . ($team ? '-' . strtolower($team) : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: read as UTF-8
    $head = array_merge(['Name', 'Email', 'Team', 'Submitted'], $qids);
    if ($survey['enps']) $head[] = 'eNPS';
    foreach ($survey['open'] as $k => $_) $head[] = $k;
    fputcsv($out, $head, ",", "\"", "");
    foreach ($rows as $r) {
        [$name, $mail] = sv_who($r, $names);
        $line = [$name, $mail, $r['team'], $r['submitted_at']];
        foreach ($qids as $q) $line[] = $r['answers'][$q] ?? '';
        if ($survey['enps']) $line[] = $r['enps'];
        foreach ($survey['open'] as $k => $_) $line[] = $r['open_' . $k];
        fputcsv($out, $line, ",", "\"", "");
    }
    exit;
}

$score = survey_score($rows, $survey);

$teamRows = [];
if ($view === 'overview' && $team === '') {
    foreach ($survey['teams'] as $t) {
        $teamRows[$t] = survey_score(array_values(array_filter($all, fn($r) => $r['team'] === $t)), $survey);
    }
}

$trend = [];
if ($view === 'trends') {
    $history = survey_load_responses($conn, $key);
    if ($team !== '') $history = array_filter($history, fn($r) => $r['team'] === $team);
    $byPeriod = [];
    foreach ($history as $r) $byPeriod[$r['period']][] = $r;
    ksort($byPeriod);
    foreach ($byPeriod as $p => $pr) $trend[$p] = survey_score($pr, $survey);
}

$pendingNow = survey_pending_users($conn, $key, survey_period());
$answeredNow = [];
if ($view === 'pending') {
    $ap = $conn->prepare("SELECT email, submitted_at FROM survey_participants WHERE survey = ? AND period = ? ORDER BY submitted_at DESC");
    $ap->execute([$key, survey_period()]);
    $answeredNow = $ap->fetchAll();
}

function sv_tone(?float $avg): string {
    if ($avg === null) return 'secondary';
    return $avg >= 4 ? 'success' : ($avg >= 3 ? 'warning' : 'danger');
}
function sv_fmt(?float $v, int $dp = 2): string { return $v === null ? '—' : number_format($v, $dp); }
function sv_month(string $p): string { return date('F Y', strtotime($p . '-01')); }
function sv_enps(?array $e): string { return $e ? (($e['score'] > 0 ? '+' : '') . $e['score']) : '—'; }

$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . survey_path($key);

include 'header.php';
?>
<style>
    .sv-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .sv-bar > div { height: 100%; }
    .sv-big { font-size: 2rem; font-weight: 800; line-height: 1; }
    .sv-dist { display: flex; height: 6px; border-radius: 3px; overflow: hidden; background: #f1f5f9; }
    .sv-dist span:nth-child(1) { background: #dc2626; } .sv-dist span:nth-child(2) { background: #f97316; }
    .sv-dist span:nth-child(3) { background: #cbd5e1; } .sv-dist span:nth-child(4) { background: #4ade80; }
    .sv-dist span:nth-child(5) { background: #16a34a; }
    .sv-quote { border-left: 3px solid #1e88e5; background: #f8fafc; padding: 10px 14px; border-radius: 0 8px 8px 0; margin-bottom: 8px; white-space: pre-wrap; }
    .sv-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; padding: 2px 8px; border-radius: 999px; margin: 0 4px 4px 0; }
    .sv-chip b { font-weight: 700; }
    .sv-v1 { background: #fee2e2; color: #991b1b; } .sv-v2 { background: #ffedd5; color: #9a3412; }
    .sv-v3 { background: #f1f5f9; color: #475569; } .sv-v4 { background: #dcfce7; color: #166534; }
    .sv-v5 { background: #bbf7d0; color: #14532d; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <ul class="nav nav-pills">
        <?php foreach ($defs as $k => $d): ?>
            <li class="nav-item"><a class="nav-link <?= $k === $key ? 'active' : '' ?>" href="survey_results.php?s=<?= $k ?>&view=<?= $view ?>">
                <?= sanitize($d['title']) ?>
                <?php if (empty($d['is_open'])): ?><span class="badge bg-secondary ms-1">Closed</span><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <a href="survey_manage.php?s=<?= $key ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Manage surveys</a>
</div>

<div class="alert alert-light border small d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-link-45deg"></i> Survey link to share:
    <code id="svShare"><?= sanitize($shareUrl) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="navigator.clipboard.writeText(document.getElementById('svShare').textContent);this.textContent='Copied'">Copy</button>
    <span class="ms-auto"><?= empty($survey['is_open'])
        ? '<span class="badge bg-secondary">Closed</span> not taking answers'
        : '<span class="badge bg-success">Open</span> taking answers' ?></span>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs">
        <?php foreach ($views as $v => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $v === $view ? 'active' : '' ?>" href="<?= sanitize(sv_url(['view' => $v])) ?>">
                <?= $label ?><?php if ($v === 'pending' && $pendingNow): ?> <span class="badge bg-warning text-dark"><?= count($pendingNow) ?></span><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($view !== 'pending'): ?>
    <form class="d-flex flex-wrap gap-2" method="GET">
        <input type="hidden" name="s" value="<?= sanitize($key) ?>">
        <input type="hidden" name="view" value="<?= sanitize($view) ?>">
        <?php if ($view !== 'trends'): ?>
        <select name="period" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
                <option value="<?= sanitize($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= sanitize(sv_month($p)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="team" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">All teams</option>
            <?php foreach ($survey['teams'] as $t): ?>
                <option value="<?= sanitize($t) ?>" <?= $t === $team ? 'selected' : '' ?>><?= sanitize($t) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if ($view === 'overview'): ?>
<!-- ═════ OVERVIEW ═════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-muted">Responses<?= $team ? ' · ' . sanitize($team) : '' ?></div>
            <div class="sv-big"><?= $score['n'] ?></div>
            <div class="small text-muted"><?= sanitize(sv_month($period)) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-muted"><?= $key === 'EHI' ? 'EHI score' : 'Team score' ?> (weighted)</div>
            <div class="sv-big text-<?= sv_tone($score['overall']) ?>"><?= sv_fmt($score['overall']) ?><span class="fs-6 text-muted fw-normal"> / 5</span></div>
            <div class="small text-muted">1 = Strongly disagree · 5 = Strongly agree</div>
        </div></div>
    </div>
    <?php if ($survey['enps']): $e = $score['enps']; ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100"><div class="card-body">
            <div class="small text-muted">eNPS (−100 to +100)</div>
            <div class="sv-big text-<?= $e ? ($e['score'] >= 30 ? 'success' : ($e['score'] >= 0 ? 'warning' : 'danger')) : 'secondary' ?>"><?= sv_enps($e) ?></div>
            <?php if ($e): ?>
                <div class="d-flex rounded overflow-hidden mt-2" style="height:8px">
                    <div class="bg-success" style="width:<?= $e['promoters'] / $e['n'] * 100 ?>%"></div>
                    <div class="bg-secondary bg-opacity-25" style="width:<?= $e['passives'] / $e['n'] * 100 ?>%"></div>
                    <div class="bg-danger" style="width:<?= $e['detractors'] / $e['n'] * 100 ?>%"></div>
                </div>
                <div class="small text-muted mt-1">
                    <span class="text-success">Promoters <?= $e['promoters'] ?></span> ·
                    Passives <?= $e['passives'] ?> ·
                    <span class="text-danger">Detractors <?= $e['detractors'] ?></span>
                </div>
            <?php endif; ?>
        </div></div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$score['n']): ?>
    <div class="alert alert-info"><i class="bi bi-inbox me-2"></i>No responses yet for this view.</div>
<?php else: ?>

<?php if ($teamRows): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">By team</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Team</th><th class="text-end">Responses</th><th class="text-end">Score / 5</th>
                <?php if ($survey['enps']): ?><th class="text-end">eNPS</th><?php endif; ?><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($teamRows as $t => $ts): ?>
                <tr>
                    <td><?= sanitize($t) ?></td>
                    <td class="text-end"><?= $ts['n'] ?></td>
                    <td class="text-end fw-semibold text-<?= sv_tone($ts['overall']) ?>"><?= sv_fmt($ts['overall']) ?></td>
                    <?php if ($survey['enps']): ?><td class="text-end"><?= sv_enps($ts['enps']) ?></td><?php endif; ?>
                    <td class="text-end small"><?php if ($ts['n']): ?><a href="<?= sanitize(sv_url(['team' => $t])) ?>">Details</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between">
        <span class="fw-semibold">Sections</span>
        <span class="small text-muted">Average / 5 · % favourable = answered 4 or 5</span>
    </div>
    <div class="list-group list-group-flush">
    <?php foreach ($score['sections'] as $i => $sec): $tone = sv_tone($sec['avg']); ?>
        <div class="list-group-item">
            <a class="d-flex align-items-center gap-3 text-decoration-none text-reset" data-bs-toggle="collapse" href="#svSec<?= $i ?>">
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= sanitize($sec['name']) ?> <span class="badge bg-light text-muted fw-normal"><?= (int)$sec['weight'] ?>%</span></div>
                    <div class="sv-bar mt-1"><div class="bg-<?= $tone ?>" style="width:<?= ($sec['avg'] ?? 0) / 5 * 100 ?>%"></div></div>
                </div>
                <div class="text-end" style="min-width:110px">
                    <div class="fw-bold text-<?= $tone ?>"><?= sv_fmt($sec['avg']) ?></div>
                    <div class="small text-muted"><?= sv_fmt($sec['fav'], 0) ?>% favourable</div>
                </div>
                <i class="bi bi-chevron-down text-muted"></i>
            </a>
            <div class="collapse" id="svSec<?= $i ?>">
                <div class="pt-3">
                <?php foreach ($sec['questions'] as $qid => $q): $tot = array_sum($q['dist']); ?>
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <div class="flex-grow-1 small">
                            <?= (int)substr($qid, 1) ?>. <?= sanitize($q['text']) ?>
                            <div class="sv-dist mt-1" title="1 → 5: <?= implode(' · ', $q['dist']) ?>">
                                <?php foreach ($q['dist'] as $c): ?><span style="width:<?= $tot ? $c / $tot * 100 : 0 ?>%"></span><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="text-end small" style="min-width:110px">
                            <span class="fw-semibold text-<?= sv_tone($q['avg']) ?>"><?= sv_fmt($q['avg']) ?></span>
                            <span class="text-muted">· <?= sv_fmt($q['fav'], 0) ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                    <div class="small text-muted">Bar colours: <span style="color:#dc2626">■</span> 1 <span style="color:#f97316">■</span> 2 <span style="color:#94a3b8">■</span> 3 <span style="color:#4ade80">■</span> 4 <span style="color:#16a34a">■</span> 5</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php if ($survey['open']): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Written answers</div>
    <div class="card-body">
    <?php foreach ($survey['open'] as $k => $question):
        $answers = array_values(array_filter($rows, fn($r) => ($r['open_' . $k] ?? '') !== ''));
    ?>
        <h6 class="fw-semibold mt-2"><?= sanitize($question) ?> <span class="badge bg-light text-muted fw-normal"><?= count($answers) ?></span></h6>
        <?php if (!$answers): ?>
            <p class="small text-muted">No answers.</p>
        <?php else: foreach ($answers as $r): [$who] = sv_who($r, $names); ?>
            <div class="sv-quote small"><?= sanitize($r['open_' . $k]) ?><div class="text-muted mt-1" style="white-space:normal">— <?= sanitize($who) ?> · <?= sanitize($r['team']) ?></div></div>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; /* has responses */ ?>

<?php elseif ($view === 'responses'): ?>
<!-- ═════ RESPONSES ════════════════════════════════════════════════════════ -->
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="search" id="svSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="Search name, email or team">
    <span class="small text-muted"><?= count($rows) ?> response<?= count($rows) === 1 ? '' : 's' ?> · <?= sanitize(sv_month($period)) ?></span>
    <?php if ($rows): ?><a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= sanitize(sv_url(['export' => 'csv'])) ?>"><i class="bi bi-download me-1"></i>Download CSV</a><?php endif; ?>
</div>
<?php if (!$rows): ?>
    <div class="alert alert-info"><i class="bi bi-inbox me-2"></i>No responses yet for this view.</div>
<?php endif; ?>
<?php foreach ($rows as $r): [$who, $mail] = sv_who($r, $names); ?>
    <div class="card mb-3 sv-resp" data-search="<?= sanitize(strtolower($who . ' ' . $mail . ' ' . $r['team'])) ?>">
        <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-person-circle text-muted"></i>
            <span class="fw-semibold <?= $mail ? '' : 'text-muted fst-italic' ?>"><?= sanitize($who) ?></span>
            <?php if ($mail && $who !== $mail): ?><span class="small text-muted"><?= sanitize($mail) ?></span><?php endif; ?>
            <span class="badge bg-light text-dark border"><?= sanitize($r['team']) ?></span>
            <?php if ($survey['enps'] && $r['enps'] !== null): $ev = (int)$r['enps']; ?>
                <span class="badge bg-<?= $ev >= 9 ? 'success' : ($ev <= 6 ? 'danger' : 'secondary') ?>">eNPS <?= $ev ?></span>
            <?php endif; ?>
            <span class="small text-muted ms-auto"><?= $r['submitted_at'] ? sanitize(date('d M Y, g:i A', strtotime($r['submitted_at']))) : '' ?></span>
        </div>
        <div class="card-body small">
            <?php foreach ($survey['dimensions'] as $dim): ?>
                <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                    <span class="text-muted" style="min-width:200px"><?= sanitize($dim['name']) ?></span>
                    <span>
                    <?php foreach ($dim['questions'] as $qid => $text): $v = $r['answers'][$qid] ?? null; ?>
                        <span class="sv-chip sv-v<?= is_int($v) ? $v : 3 ?>" title="<?= sanitize($text) ?>">Q<?= (int)substr($qid, 1) ?> <b><?= is_int($v) ? $v : '–' ?></b></span>
                    <?php endforeach; ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($survey['open'] as $k => $question): if (($r['open_' . $k] ?? '') === '') continue; ?>
                <div class="mt-2"><div class="text-muted"><?= sanitize($question) ?></div><div class="sv-quote mb-0"><?= sanitize($r['open_' . $k]) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
<script>
document.getElementById('svSearch')?.addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('.sv-resp').forEach(c => { c.style.display = !q || c.dataset.search.includes(q) ? '' : 'none'; });
});
</script>

<?php elseif ($view === 'trends'): ?>
<!-- ═════ TRENDS ═══════════════════════════════════════════════════════════ -->
<?php if (!$trend): ?>
    <div class="alert alert-info"><i class="bi bi-graph-up me-2"></i>No responses yet. Trends appear once there's data for at least one month.</div>
<?php else: ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Score<?= $survey['enps'] ? ' and eNPS' : '' ?> by month<?= $team ? ' · ' . sanitize($team) : '' ?></div>
    <div class="card-body"><canvas id="svTrend" height="90"></canvas></div>
</div>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr>
                <th>Month</th><th class="text-end">Responses</th><th class="text-end">Score</th>
                <?php if ($survey['enps']): ?><th class="text-end">eNPS</th><?php endif; ?>
                <?php foreach ($survey['dimensions'] as $d): ?><th class="text-end" title="<?= sanitize($d['name']) ?>"><?= sanitize(strtok($d['name'], ' &/')) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach (array_reverse($trend, true) as $p => $ts): ?>
                <tr>
                    <td><a href="<?= sanitize(sv_url(['view' => 'overview', 'period' => $p])) ?>"><?= sanitize(sv_month($p)) ?></a></td>
                    <td class="text-end"><?= $ts['n'] ?></td>
                    <td class="text-end fw-semibold text-<?= sv_tone($ts['overall']) ?>"><?= sv_fmt($ts['overall']) ?></td>
                    <?php if ($survey['enps']): ?><td class="text-end"><?= sv_enps($ts['enps']) ?></td><?php endif; ?>
                    <?php foreach ($ts['sections'] as $sec): ?><td class="text-end text-<?= sv_tone($sec['avg']) ?>"><?= sv_fmt($sec['avg']) ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
// Chart.js is loaded by footer.php, after this script — so build the chart on DOMContentLoaded.
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const labels   = <?= json_encode(array_map('sv_month', array_keys($trend))) ?>;
    const overall  = <?= json_encode(array_map(fn($t) => $t['overall'] === null ? null : round($t['overall'], 2), array_values($trend))) ?>;
    const enps     = <?= json_encode(array_map(fn($t) => $t['enps']['score'] ?? null, array_values($trend))) ?>;
    const sections = <?= json_encode(array_map(fn($i) => [
        'label' => $survey['dimensions'][$i]['name'],
        'data'  => array_map(fn($t) => $t['sections'][$i]['avg'] === null ? null : round($t['sections'][$i]['avg'], 2), array_values($trend)),
    ], array_keys($survey['dimensions'])), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const hasEnps  = <?= $survey['enps'] ? 'true' : 'false' ?>;
    const palette  = ['#64748b','#0ea5e9','#a855f7','#f59e0b','#ec4899','#14b8a6','#84cc16','#f97316','#6366f1','#ef4444'];

    const datasets = [{ label: 'Score (weighted)', data: overall, yAxisID: 'y', borderColor: '#0f4c81', backgroundColor: '#0f4c81', borderWidth: 3, tension: .3 }];
    if (hasEnps) datasets.push({ label: 'eNPS', data: enps, yAxisID: 'y2', borderColor: '#16a34a', backgroundColor: '#16a34a', borderDash: [6, 4], borderWidth: 2, tension: .3 });
    sections.forEach((s, i) => datasets.push({ label: s.label, data: s.data, yAxisID: 'y', borderColor: palette[i % palette.length], backgroundColor: palette[i % palette.length], borderWidth: 1.5, tension: .3, hidden: true }));

    new Chart(document.getElementById('svTrend'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { mode: 'index' } },
            scales: {
                y:  { min: 1, max: 5, title: { display: true, text: 'Score / 5' } },
                y2: { display: hasEnps, position: 'right', min: -100, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: 'eNPS' } },
            },
        },
    });
});
</script>
<p class="small text-muted">Section lines are hidden by default; click a name in the legend to show it.</p>
<?php endif; ?>

<?php else: ?>
<!-- ═════ NOT ANSWERED ═════════════════════════════════════════════════════ -->
<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>
    Shows active HRMS users with no response to this survey in <strong><?= sanitize(sv_month(survey_period())) ?></strong>.
    Someone who answered on a browser without their saved email (never logged into HRMS there) still shows here.
</div>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Not answered yet · <?= count($pendingNow) ?></span>
                <?php if ($pendingNow && !empty($survey['is_open'])): ?>
                <form method="POST" onsubmit="return confirm('Send a reminder notification to all <?= count($pendingNow) ?> people?')">
                    <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
                    <input type="hidden" name="action" value="remind">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-bell me-1"></i>Remind all</button>
                </form>
                <?php endif; ?>
            </div>
            <ul class="list-group list-group-flush small">
            <?php if (!$pendingNow): ?>
                <li class="list-group-item text-success"><i class="bi bi-check-circle me-1"></i>Everyone has answered.</li>
            <?php endif; ?>
            <?php foreach ($pendingNow as $p): ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <div class="flex-grow-1"><div class="fw-semibold"><?= sanitize($p['name']) ?></div><div class="text-muted"><?= sanitize($p['email']) ?></div></div>
                    <?php if (!empty($survey['is_open'])): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
                        <input type="hidden" name="action" value="remind">
                        <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary py-0">Remind</button>
                    </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Answered · <?= count($answeredNow) ?></div>
            <ul class="list-group list-group-flush small">
            <?php if (!$answeredNow): ?><li class="list-group-item text-muted">Nobody yet.</li><?php endif; ?>
            <?php foreach ($answeredNow as $a): ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span><?= sanitize($names[$a['email']] ?? $a['email']) ?></span>
                    <span class="text-muted"><?= sanitize(date('d M, g:i A', strtotime($a['submitted_at']))) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>

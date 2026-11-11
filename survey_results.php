<?php
// Survey results (EHI + Team Rubrics) — SUPER_ADMIN and HR_ADMIN only.
//
// Anonymity rules, keep them if you edit this page:
//  - survey_responses and survey_participants are never joined.
//  - Any view (whole company or one team) with fewer than SURVEY_MIN_RESPONSES
//    answers shows no scores and no written answers.

require_once 'config.php';
require_once 'survey_config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');

const SURVEY_MIN_RESPONSES = 3;

$page      = 'survey_results';
$pageTitle = 'Survey Results';

$defs = survey_definitions();
$key  = strtoupper($_GET['s'] ?? 'EHI');
if (!isset($defs[$key])) $key = 'EHI';
$survey = $defs[$key];

// Months that have responses for this survey, newest first (+ this month).
$ps = $conn->prepare("SELECT DISTINCT period FROM survey_responses WHERE survey = ? ORDER BY period DESC");
$ps->execute([$key]);
$periods = $ps->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(survey_period(), $periods, true)) array_unshift($periods, survey_period());
rsort($periods);

$period = $_GET['period'] ?? '';
if (!in_array($period, $periods, true)) $period = $periods[0];
$team = $_GET['team'] ?? '';
if ($team !== '' && !in_array($team, SURVEY_TEAMS, true)) $team = '';

// ── Load responses ───────────────────────────────────────────────────────────
$rs = $conn->prepare("SELECT team, answers, enps, open_change, open_keep, open_frustrates
                      FROM survey_responses WHERE survey = ? AND period = ?");
$rs->execute([$key, $period]);
$all = $rs->fetchAll();
foreach ($all as &$r) $r['answers'] = json_decode($r['answers'], true) ?: [];
unset($r);

$rows = $team === '' ? $all : array_values(array_filter($all, fn($r) => $r['team'] === $team));

function survey_score(array $rows, array $survey): array {
    $out = ['n' => count($rows), 'sections' => [], 'overall' => null, 'enps' => null];
    if (!$rows) return $out;

    $wSum = 0; $wTotal = 0;
    foreach ($survey['dimensions'] as $dim) {
        $qs = [];
        foreach ($dim['questions'] as $qid => $text) {
            $vals = array_values(array_filter(array_map(fn($r) => $r['answers'][$qid] ?? null, $rows), 'is_int'));
            $cnt  = count($vals);
            $qs[$qid] = [
                'text' => $text,
                'avg'  => $cnt ? array_sum($vals) / $cnt : null,
                'fav'  => $cnt ? count(array_filter($vals, fn($v) => $v >= 4)) / $cnt * 100 : null,
                'dist' => array_map(fn($k) => count(array_filter($vals, fn($v) => $v === $k)), [1, 2, 3, 4, 5]),
            ];
        }
        $avgs = array_filter(array_column($qs, 'avg'), fn($v) => $v !== null);
        $favs = array_filter(array_column($qs, 'fav'), fn($v) => $v !== null);
        $avg  = $avgs ? array_sum($avgs) / count($avgs) : null;
        $out['sections'][] = [
            'name' => $dim['name'], 'weight' => $dim['weight'], 'avg' => $avg,
            'fav'  => $favs ? array_sum($favs) / count($favs) : null, 'questions' => $qs,
        ];
        if ($avg !== null) { $wSum += $avg * $dim['weight']; $wTotal += $dim['weight']; }
    }
    $out['overall'] = $wTotal ? $wSum / $wTotal : null;

    if ($survey['enps']) {
        $e = array_values(array_filter(array_map(fn($r) => $r['enps'], $rows), fn($v) => $v !== null));
        if ($e) {
            $p = count(array_filter($e, fn($v) => $v >= 9));
            $d = count(array_filter($e, fn($v) => $v <= 6));
            $out['enps'] = ['score' => round(($p - $d) / count($e) * 100), 'n' => count($e),
                            'promoters' => $p, 'passives' => count($e) - $p - $d, 'detractors' => $d];
        }
    }
    return $out;
}

$score    = survey_score($rows, $survey);
$enough   = $score['n'] >= SURVEY_MIN_RESPONSES;

// Per-team overview (whole-company view only).
$teamRows = [];
if ($team === '') {
    foreach (SURVEY_TEAMS as $t) {
        $tr = array_values(array_filter($all, fn($r) => $r['team'] === $t));
        $teamRows[$t] = survey_score($tr, $survey);
    }
}

// Who took part — emails only, from the separate table. Never matched to answers.
$pp = $conn->prepare("SELECT email, submitted_at FROM survey_participants
                      WHERE survey = ? AND period = ? ORDER BY submitted_at DESC");
$pp->execute([$key, $period]);
$participants = $pp->fetchAll();
$withoutEmail = max(0, count($all) - count($participants));

function sv_tone(?float $avg): string {
    if ($avg === null) return 'secondary';
    return $avg >= 4 ? 'success' : ($avg >= 3 ? 'warning' : 'danger');
}
function sv_fmt(?float $v, int $dp = 2): string { return $v === null ? '—' : number_format($v, $dp); }
function sv_month(string $p): string { return date('F Y', strtotime($p . '-01')); }
function sv_url(array $over): string {
    global $key, $period, $team;
    return 'survey_results.php?' . http_build_query(array_merge(['s' => $key, 'period' => $period, 'team' => $team], $over));
}

$surveyLinks = ['EHI' => 'EHI', 'RUBRICS' => 'Rubrics'];
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $surveyLinks[$key];

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
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <ul class="nav nav-pills">
        <?php foreach ($defs as $k => $d): ?>
            <li class="nav-item"><a class="nav-link <?= $k === $key ? 'active' : '' ?>" href="survey_results.php?s=<?= $k ?>"><?= sanitize($d['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="d-flex flex-wrap gap-2" method="GET">
        <input type="hidden" name="s" value="<?= sanitize($key) ?>">
        <select name="period" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
                <option value="<?= sanitize($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= sanitize(sv_month($p)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="team" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">All teams</option>
            <?php foreach (SURVEY_TEAMS as $t): ?>
                <option value="<?= sanitize($t) ?>" <?= $t === $team ? 'selected' : '' ?>><?= sanitize($t) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="alert alert-light border small d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-link-45deg"></i> Survey link to share:
    <code id="svShare"><?= sanitize($shareUrl) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="navigator.clipboard.writeText(document.getElementById('svShare').textContent);this.textContent='Copied'">Copy</button>
</div>

<!-- ── Headline numbers ─────────────────────────────────────────────────── -->
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
            <div class="sv-big text-<?= $enough ? sv_tone($score['overall']) : 'secondary' ?>"><?= $enough ? sv_fmt($score['overall']) : '—' ?><span class="fs-6 text-muted fw-normal"> / 5</span></div>
            <div class="small text-muted">1 = Strongly disagree · 5 = Strongly agree</div>
        </div></div>
    </div>
    <?php if ($survey['enps']): $e = $enough ? $score['enps'] : null; ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100"><div class="card-body">
            <div class="small text-muted">eNPS (−100 to +100)</div>
            <div class="sv-big text-<?= $e ? ($e['score'] >= 30 ? 'success' : ($e['score'] >= 0 ? 'warning' : 'danger')) : 'secondary' ?>"><?= $e ? ($e['score'] > 0 ? '+' : '') . $e['score'] : '—' ?></div>
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

<?php if (!$enough): ?>
    <div class="alert alert-info">
        <i class="bi bi-shield-lock me-2"></i>
        <?= $score['n'] === 0 ? 'No responses yet for this view.' : 'Only ' . $score['n'] . ' response' . ($score['n'] === 1 ? '' : 's') . ' so far.' ?>
        Results show once there are at least <?= SURVEY_MIN_RESPONSES ?>, so nobody's answers can be singled out.
    </div>
<?php else: ?>

<!-- ── Team comparison ──────────────────────────────────────────────────── -->
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
            <?php foreach ($teamRows as $t => $ts): $ok = $ts['n'] >= SURVEY_MIN_RESPONSES; ?>
                <tr>
                    <td><?= sanitize($t) ?></td>
                    <td class="text-end"><?= $ts['n'] ?></td>
                    <td class="text-end fw-semibold text-<?= $ok ? sv_tone($ts['overall']) : 'muted' ?>"><?= $ok ? sv_fmt($ts['overall']) : '—' ?></td>
                    <?php if ($survey['enps']): ?>
                        <td class="text-end"><?= $ok && $ts['enps'] ? (($ts['enps']['score'] > 0 ? '+' : '') . $ts['enps']['score']) : '—' ?></td>
                    <?php endif; ?>
                    <td class="text-end small">
                        <?php if ($ok): ?><a href="<?= sanitize(sv_url(['team' => $t])) ?>">Details</a>
                        <?php elseif ($ts['n']): ?><span class="text-muted">needs <?= SURVEY_MIN_RESPONSES ?>+</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Sections ─────────────────────────────────────────────────────────── -->
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
                    <div class="fw-semibold"><?= sanitize($sec['name']) ?> <span class="badge bg-light text-muted fw-normal"><?= $sec['weight'] ?>%</span></div>
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

<!-- ── Written answers ──────────────────────────────────────────────────── -->
<?php if ($survey['open']): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Written answers</div>
    <div class="card-body">
    <?php foreach ($survey['open'] as $k => $question):
        $answers = array_values(array_filter(array_map(fn($r) => $r['open_' . $k], $rows), fn($v) => $v !== null && $v !== ''));
    ?>
        <h6 class="fw-semibold mt-2"><?= sanitize($question) ?> <span class="badge bg-light text-muted fw-normal"><?= count($answers) ?></span></h6>
        <?php if (!$answers): ?>
            <p class="small text-muted">No answers.</p>
        <?php else: foreach ($answers as $a): ?>
            <div class="sv-quote small"><?= sanitize($a) ?></div>
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; /* enough */ ?>

<!-- ── Who took part ────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between">
        <span class="fw-semibold">Who took part · <?= sanitize(sv_month($period)) ?></span>
        <span class="small text-muted"><?= count($participants) ?> with email<?= $withoutEmail ? ' · ' . $withoutEmail . ' without' : '' ?></span>
    </div>
    <div class="card-body small">
        <p class="text-muted mb-2"><i class="bi bi-shield-lock me-1"></i>Kept separately from the answers. This list can't be matched to anyone's responses. It covers all teams.</p>
        <?php if (!$participants): ?>
            <span class="text-muted">Nobody with a saved email has responded yet.</span>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($participants as $p): ?>
                    <span class="badge bg-light text-dark border fw-normal" title="<?= sanitize(date('d M Y, g:i A', strtotime($p['submitted_at']))) ?>"><?= sanitize($p['email']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>

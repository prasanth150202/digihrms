<?php
// Anonymous surveys: /EHI and /Rubrics (rewritten to survey.php?s=… in .htaccess).
//
// Who is answering comes from the logged-in session, or failing that from the
// hrms_remember_email cookie that login.php sets. That email goes ONLY into
// survey_participants (to stop repeat submissions); the answers go into
// survey_responses with no email, no user id and no timestamp. See migrate_surveys.php.

require_once 'config.php';
require_once 'survey_config.php';

$defs = survey_definitions();
$key  = strtoupper(trim((string)($_GET['s'] ?? '')));
if ($key === 'RUBRIC') $key = 'RUBRICS';
$survey = $defs[$key] ?? null;
$period = survey_period();

function survey_respondent_email(PDO $conn): ?string {
    $email = $_SESSION['user']['email'] ?? ($_COOKIE['hrms_remember_email'] ?? '');
    $email = strtolower(trim((string)$email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    // Only real, active HRMS users — the cookie is client-side and could be edited.
    $s = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $s->execute([$email]);
    $u = $s->fetch();
    if (!$u || ($u['invite_status'] ?? 'ACTIVE') !== 'ACTIVE') return null;
    return strtolower(trim($u['email']));
}

function survey_already_done(PDO $conn, string $key, string $period, string $email): bool {
    $s = $conn->prepare("SELECT 1 FROM survey_participants WHERE survey = ? AND period = ? AND email = ? LIMIT 1");
    $s->execute([$key, $period, $email]);
    return (bool)$s->fetchColumn();
}

$email = survey_respondent_email($conn);

// ── Submit (JSON) ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $fail = function (int $code, string $msg) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    };

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in))                         $fail(400, 'Invalid request.');
    if (!verify_csrf($in['csrf'] ?? ''))        $fail(403, 'Your session expired. Reload the page and try again.');
    if (!$survey)                               $fail(404, 'Unknown survey.');
    if (!$email)                                $fail(401, 'We could not identify you. Log in to HRMS once, then open this survey again.');

    $team = $in['team'] ?? '';
    if (!in_array($team, SURVEY_TEAMS, true))   $fail(422, 'Please choose your team.');

    $inAnswers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
    $inOpen    = is_array($in['open'] ?? null)    ? $in['open']    : [];

    $answers = [];
    foreach ($survey['dimensions'] as $dim) {
        foreach ($dim['questions'] as $qid => $_) {
            $v = $inAnswers[$qid] ?? null;
            if (!is_numeric($v) || (int)$v != $v || $v < 1 || $v > 5) $fail(422, 'Please answer every question.');
            $answers[$qid] = (int)$v;
        }
    }

    $enps = null;
    if ($survey['enps']) {
        $e = $in['enps'] ?? null;
        if (!is_numeric($e) || (int)$e != $e || $e < 0 || $e > 10) $fail(422, 'Please choose a score from 0 to 10.');
        $enps = (int)$e;
    }

    $open = ['change' => null, 'keep' => null, 'frustrates' => null];
    foreach ($survey['open'] as $k => $_) {
        $t = is_string($inOpen[$k] ?? null) ? trim($inOpen[$k]) : '';
        $open[$k] = $t === '' ? null : mb_substr($t, 0, SURVEY_TEXT_MAX);
    }

    try {
        $conn->exec("SET NAMES utf8mb4"); // written answers may contain emoji
        $conn->beginTransaction();
        // Participant row first: its UNIQUE key is what blocks a second submission.
        $conn->prepare("INSERT INTO survey_participants (survey, period, email) VALUES (?, ?, ?)")
             ->execute([$key, $period, $email]);
        $conn->prepare("INSERT INTO survey_responses
                (id, survey, period, team, answers, enps, open_change, open_keep, open_frustrates)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
             ->execute([bin2hex(random_bytes(16)), $key, $period, $team, json_encode($answers),
                        $enps, $open['change'], $open['keep'], $open['frustrates']]);
        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if ($e->getCode() === '23000') $fail(409, 'You have already completed this survey this month. Thank you!');
        error_log('survey submit failed: ' . $e->getMessage());
        $fail(500, 'We could not save your answers. Please try again.');
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Page ─────────────────────────────────────────────────────────────────────
$state = 'form';
if (!$survey)                                               $state = 'pick';
elseif (!$email)                                            $state = 'login';
elseif (survey_already_done($conn, $key, $period, $email))  $state = 'done';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$periodLabel = date('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $survey ? sanitize($survey['title']) : 'Digifyce Surveys' ?></title>
    <link rel="icon" type="image/png" href="<?= $base ?>assets/images/fav.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #0f4c81 100%); min-height: 100vh; padding: 32px 16px; }
        .sv-card { background: #fff; border-radius: 16px; padding: 32px; max-width: 680px; margin: 0 auto; box-shadow: 0 25px 60px rgba(0,0,0,.3); }
        .sv-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: #64748b; font-size: 13px; }
        .sv-brand img { width: 32px; height: 32px; border-radius: 8px; object-fit: cover; }
        .sv-progress { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 24px; }
        .sv-progress > div { height: 100%; background: linear-gradient(90deg, #0f4c81, #1e88e5); transition: width .25s; }
        .sv-step-label { font-size: 12px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: #1e88e5; }
        .sv-q { padding: 16px 0; border-top: 1px solid #f1f5f9; }
        .sv-q:first-of-type { border-top: 0; }
        .sv-q.missing .sv-q-text { color: #dc2626; }
        .sv-q-text { font-weight: 500; margin-bottom: 10px; }
        .sv-scale { display: flex; gap: 8px; }
        .sv-scale label { flex: 1; min-width: 0; padding: 8px 0; }
        .sv-scale-ends { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-top: 4px; }
        .sv-nps { display: flex; flex-wrap: wrap; gap: 6px; }
        .sv-nps label { flex: 1 0 40px; padding: 8px 0; }
        .sv-team { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .sv-team label { padding: 14px; }
        .sv-nav { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }
        .btn-sv { background: linear-gradient(135deg, #0f4c81, #1e88e5); border: none; color: #fff; font-weight: 600; padding: 10px 24px; }
        .btn-sv:hover, .btn-sv:focus { color: #fff; opacity: .9; }
        .sv-pick a { display: block; padding: 18px; border: 1px solid #e2e8f0; border-radius: 12px; text-decoration: none; color: inherit; margin-bottom: 12px; }
        .sv-pick a:hover { border-color: #1e88e5; background: #f8fafc; }
        @media (max-width: 480px) { .sv-card { padding: 22px 18px; } .sv-team { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="sv-card">
    <div class="sv-brand">
        <img src="<?= $base ?>assets/images/logo.jpg" alt="" onerror="this.remove()">
        <span>Digifyce<?= $survey ? ' · ' . sanitize($survey['title']) : '' ?></span>
    </div>

<?php if ($state === 'pick'): ?>
    <h4 class="fw-bold mb-3">Digifyce Surveys</h4>
    <div class="sv-pick">
        <a href="<?= $base ?>EHI"><div class="fw-semibold">Employee Happiness Index</div><div class="small text-muted">How you feel about working at Digifyce · about 5 minutes</div></a>
        <a href="<?= $base ?>Rubrics"><div class="fw-semibold">Team Performance Rubrics</div><div class="small text-muted">Rate how your team works together · about 3 minutes</div></a>
    </div>

<?php elseif ($state === 'login'): ?>
    <h4 class="fw-bold">Log in first</h4>
    <p class="text-muted">We need to know you're a Digifyce team member so each person answers once. Your answers are still stored anonymously, separate from your name and email.</p>
    <a class="btn btn-sv" href="<?= $base ?>login.php">Log in to HRMS</a>
    <p class="small text-muted mt-3 mb-0">After logging in, open this survey link again.</p>

<?php elseif ($state === 'done'): ?>
    <div class="text-center py-3">
        <i class="bi bi-check-circle-fill text-success" style="font-size:48px"></i>
        <h4 class="fw-bold mt-3">You're all done for <?= sanitize($periodLabel) ?></h4>
        <p class="text-muted mb-0">Thanks for completing the <?= sanitize($survey['title']) ?>. The next round opens next month.</p>
    </div>

<?php else: ?>
    <div class="sv-progress"><div id="svBar" style="width:0"></div></div>
    <div id="svStep"></div>
    <div class="alert alert-danger small py-2 mt-3 d-none" id="svErr"></div>
    <div class="sv-nav" id="svNav">
        <button type="button" class="btn btn-outline-secondary" id="svBack">Back</button>
        <button type="button" class="btn btn-sv" id="svNext">Next</button>
    </div>
<?php endif; ?>
</div>

<?php if ($state === 'form'): ?>
<script>
(() => {
    const SURVEY = <?= json_encode($survey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const TEAMS  = <?= json_encode(SURVEY_TEAMS) ?>;
    const CSRF   = <?= json_encode(csrf_token()) ?>;
    const PERIOD = <?= json_encode($periodLabel) ?>;
    const TEXT_MAX = <?= SURVEY_TEXT_MAX ?>;

    const steps = [{ type: 'welcome' }, { type: 'team' }]
        .concat(SURVEY.dimensions.map((d, i) => ({ type: 'dim', dim: d, n: i + 1 })));
    if (SURVEY.enps) steps.push({ type: 'enps' });
    if (Object.keys(SURVEY.open).length) steps.push({ type: 'open' });

    const st = { team: null, answers: {}, enps: null, open: {} };
    let idx = 0, sending = false;

    const $ = id => document.getElementById(id);
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const qNum = qid => parseInt(qid.slice(1), 10);

    function radios(name, values, checked, labelFn) {
        return values.map(v => {
            const id = `${name}_${v}`;
            return `<input type="radio" class="btn-check" name="${name}" id="${id}" value="${esc(v)}" ${String(checked) === String(v) ? 'checked' : ''}>
                    <label class="btn btn-outline-primary" for="${id}">${esc(labelFn ? labelFn(v) : v)}</label>`;
        }).join('');
    }

    function render() {
        const s = steps[idx];
        const last = idx === steps.length - 1;
        let html = '';

        if (s.type === 'welcome') {
            html = `<h4 class="fw-bold">${esc(SURVEY.title)}</h4>
                    <p class="text-muted">${esc(SURVEY.intro)}</p>
                    <p class="small text-muted mb-0"><i class="bi bi-shield-lock me-1"></i>${PERIOD} round · one response per person.</p>`;
        } else if (s.type === 'team') {
            html = `<div class="sv-step-label">About you</div>
                    <h5 class="fw-bold mt-1 mb-3">Which team are you on?</h5>
                    <div class="sv-team">${radios('team', TEAMS, st.team)}</div>`;
        } else if (s.type === 'dim') {
            html = `<div class="sv-step-label">Section ${s.n} of ${SURVEY.dimensions.length}</div>
                    <h5 class="fw-bold mt-1 mb-2">${esc(s.dim.name)}</h5>
                    <p class="small text-muted">1 = Strongly disagree · 5 = Strongly agree</p>` +
                Object.entries(s.dim.questions).map(([qid, text]) => `
                    <div class="sv-q" data-q="${qid}">
                        <div class="sv-q-text">${qNum(qid)}. ${esc(text)}</div>
                        <div class="sv-scale" role="radiogroup">${radios(qid, [1, 2, 3, 4, 5], st.answers[qid])}</div>
                        <div class="sv-scale-ends"><span>Strongly disagree</span><span>Strongly agree</span></div>
                    </div>`).join('');
        } else if (s.type === 'enps') {
            html = `<div class="sv-step-label">Overall experience</div>
                    <h5 class="fw-bold mt-1 mb-3">${esc(SURVEY.enps)}</h5>
                    <div class="sv-nps" role="radiogroup">${radios('enps', [0,1,2,3,4,5,6,7,8,9,10], st.enps)}</div>
                    <div class="sv-scale-ends"><span>Not at all likely</span><span>Extremely likely</span></div>`;
        } else if (s.type === 'open') {
            html = `<div class="sv-step-label">Your voice matters</div>
                    <h5 class="fw-bold mt-1 mb-1">In your own words</h5>
                    <p class="small text-muted">Optional. Please don't include names if you want to stay anonymous.</p>` +
                Object.entries(SURVEY.open).map(([k, text]) => `
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="open_${k}">${esc(text)}</label>
                        <textarea class="form-control" id="open_${k}" data-open="${k}" rows="3" maxlength="${TEXT_MAX}">${esc(st.open[k] || '')}</textarea>
                    </div>`).join('');
        }

        $('svStep').innerHTML = html;
        $('svBar').style.width = Math.round(idx / steps.length * 100) + '%';
        $('svBack').style.visibility = idx === 0 ? 'hidden' : 'visible';
        $('svNext').textContent = idx === 0 ? 'Start' : (last ? 'Submit' : 'Next');
        hideErr();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Keep state in sync with whatever the respondent clicks or types.
    $('svStep').addEventListener('change', e => {
        const t = e.target;
        if (t.name === 'team') st.team = t.value;
        else if (t.name === 'enps') st.enps = parseInt(t.value, 10);
        else if (/^q\d+$/.test(t.name)) {
            st.answers[t.name] = parseInt(t.value, 10);
            t.closest('.sv-q')?.classList.remove('missing');
        }
    });
    $('svStep').addEventListener('input', e => {
        if (e.target.dataset.open) st.open[e.target.dataset.open] = e.target.value;
    });

    function showErr(msg) { $('svErr').textContent = msg; $('svErr').classList.remove('d-none'); }
    function hideErr() { $('svErr').classList.add('d-none'); }

    function validate() {
        const s = steps[idx];
        if (s.type === 'team' && !st.team) return 'Please choose your team.';
        if (s.type === 'enps' && st.enps === null) return 'Please choose a score from 0 to 10.';
        if (s.type === 'dim') {
            const missing = Object.keys(s.dim.questions).filter(q => !st.answers[q]);
            document.querySelectorAll('.sv-q').forEach(el => el.classList.toggle('missing', missing.includes(el.dataset.q)));
            if (missing.length) {
                document.querySelector(`.sv-q[data-q="${missing[0]}"]`).scrollIntoView({ behavior: 'smooth', block: 'center' });
                return `Please answer ${missing.length === 1 ? 'the highlighted question' : 'all ' + missing.length + ' highlighted questions'}.`;
            }
        }
        return null;
    }

    async function submit() {
        sending = true;
        $('svNext').disabled = $('svBack').disabled = true;
        $('svNext').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting';
        try {
            const r = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf: CSRF, team: st.team, answers: st.answers, enps: st.enps, open: st.open }),
            });
            const data = await r.json().catch(() => ({ ok: false, error: 'Unexpected server response.' }));
            if (!data.ok && r.status !== 409) throw new Error(data.error || 'Could not submit.');
            $('svBar').style.width = '100%';
            $('svNav').remove();
            $('svStep').innerHTML = `<div class="text-center py-3">
                <i class="bi bi-check-circle-fill text-success" style="font-size:48px"></i>
                <h4 class="fw-bold mt-3">Thank you!</h4>
                <p class="text-muted mb-0">${esc(r.status === 409 ? data.error : 'Your answers have been recorded anonymously.')}</p></div>`;
        } catch (err) {
            showErr(err.message);
            $('svNext').disabled = $('svBack').disabled = false;
            $('svNext').textContent = 'Submit';
        } finally {
            sending = false;
        }
    }

    $('svNext').addEventListener('click', () => {
        if (sending) return;
        const err = validate();
        if (err) return showErr(err);
        if (idx === steps.length - 1) return submit();
        idx++; render();
    });
    $('svBack').addEventListener('click', () => { if (idx > 0 && !sending) { idx--; render(); } });

    render();
})();
</script>
<?php endif; ?>
</body>
</html>

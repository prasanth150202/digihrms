<?php
// Manage surveys — SUPER_ADMIN and HR_ADMIN only.
//  - open / close a survey (closed → /EHI shows "This survey is closed")
//  - edit title, teams, sections, weights and questions (stored in survey_settings)
//  - delete a month's responses (e.g. test data)

require_once 'config.php';
require_once 'survey_config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');

$page      = 'survey_results';
$pageTitle = 'Manage Surveys';

$defs = survey_definitions($conn);
$key  = strtoupper($_GET['s'] ?? $_POST['s'] ?? 'EHI');
if (!isset($defs[$key])) $key = 'EHI';
$survey   = $defs[$key];
$defaults = survey_default_definitions()[$key];
$me       = (int)(current_user()['id'] ?? 0);

const SV_OPEN_KEYS = ['change', 'keep', 'frustrates']; // fixed DB columns open_change, …

function sv_back(string $key): void { header('Location: survey_manage.php?s=' . urlencode($key)); exit; }

// Validates the editor's JSON. Returns [definition, null] or [null, error].
function sv_validate(array $in, array $current): array {
    $str = fn($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v)) : '';

    $title = $str($in['title'] ?? '');
    if ($title === '' || mb_strlen($title) > 120) return [null, 'Give the survey a title (up to 120 characters).'];

    $teams = [];
    foreach ((array)($in['teams'] ?? []) as $t) {
        $t = $str($t);
        if ($t === '') continue;
        if (mb_strlen($t) > 20) return [null, "Team name \"$t\" is too long (20 characters max)."];
        if (in_array(mb_strtolower($t), array_map('mb_strtolower', $teams), true)) return [null, "Team \"$t\" is listed twice."];
        $teams[] = $t;
    }
    if (!$teams || count($teams) > 12) return [null, 'List between 1 and 12 teams.'];

    $qseq = survey_max_qid($current);
    $seen = []; $dims = []; $wTotal = 0;
    foreach ((array)($in['dimensions'] ?? []) as $i => $d) {
        $name = $str($d['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 80) return [null, 'Section ' . ($i + 1) . ' needs a name (up to 80 characters).'];
        $w = $d['weight'] ?? null;
        if (!is_numeric($w) || (int)$w != $w || $w < 0 || $w > 100) return [null, "Weight for \"$name\" must be a whole number from 0 to 100."];
        $qs = [];
        foreach ((array)($d['questions'] ?? []) as $q) {
            $text = $str($q['text'] ?? '');
            if ($text === '') continue;
            if (mb_strlen($text) > 300) return [null, "A question in \"$name\" is too long (300 characters max)."];
            $id = is_string($q['id'] ?? null) && preg_match('/^q[1-9]\d{0,3}$/', $q['id']) ? $q['id'] : '';
            if ($id === '' || isset($seen[$id])) $id = 'q' . (++$qseq);   // new question → new, never-used id
            $seen[$id] = true;
            $qs[$id] = $text;
        }
        if (!$qs) return [null, "Section \"$name\" needs at least one question."];
        $dims[] = ['name' => $name, 'weight' => (int)$w, 'questions' => $qs];
        $wTotal += (int)$w;
    }
    if (!$dims || count($dims) > 15) return [null, 'Have between 1 and 15 sections.'];
    if ($wTotal !== 100) return [null, "Section weights add up to $wTotal%. They must add up to 100%."];

    $enps = $str($in['enps'] ?? '');
    if (mb_strlen($enps) > 300) return [null, 'The 0–10 question is too long (300 characters max).'];

    $open = [];
    foreach (SV_OPEN_KEYS as $k) {
        $t = $str($in['open'][$k] ?? '');
        if (mb_strlen($t) > 300) return [null, 'A written question is too long (300 characters max).'];
        if ($t !== '') $open[$k] = $t;
    }

    $def = ['title' => $title, 'teams' => $teams, 'dimensions' => $dims,
            'enps' => $enps === '' ? null : $enps, 'open' => $open];
    $def['qseq'] = max($qseq, survey_max_qid($def));
    return [$def, null];
}

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { set_flash('danger', 'Your session expired. Please try again.'); sv_back($key); }
    $action = $_POST['action'] ?? '';
    $upsert = $conn->prepare("INSERT INTO survey_settings (survey, is_open, definition, updated_by) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), definition = VALUES(definition), updated_by = VALUES(updated_by)");
    $storedDef = function () use ($conn, $key) {
        $s = $conn->prepare("SELECT definition FROM survey_settings WHERE survey = ?");
        $s->execute([$key]);
        return $s->fetchColumn() ?: null;
    };

    if ($action === 'toggle') {
        $open = empty($survey['is_open']) ? 1 : 0;
        $upsert->execute([$key, $open, $storedDef(), $me]);
        set_flash('success', $survey['title'] . ($open ? ' is now open.' : ' is now closed.'));
    } elseif ($action === 'save') {
        $in = json_decode((string)($_POST['definition'] ?? ''), true);
        [$def, $err] = is_array($in) ? sv_validate($in, $survey) : [null, 'Could not read the form. Please try again.'];
        if ($err) {
            $_SESSION['sv_draft'][$key] = $_POST['definition'] ?? '';
            set_flash('danger', $err);
        } else {
            $conn->exec("SET NAMES utf8mb4");
            $upsert->execute([$key, empty($survey['is_open']) ? 0 : 1, json_encode($def, JSON_UNESCAPED_UNICODE), $me]);
            unset($_SESSION['sv_draft'][$key]);
            set_flash('success', 'Questions saved. New answers use them from now on.');
        }
    } elseif ($action === 'restore') {
        $upsert->execute([$key, empty($survey['is_open']) ? 0 : 1, null, $me]);
        unset($_SESSION['sv_draft'][$key]);
        set_flash('success', 'Default questions restored.');
    } elseif ($action === 'delete_month') {
        $p = $_POST['period'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $p) || ($_POST['confirm'] ?? '') !== 'DELETE') {
            set_flash('danger', 'Type DELETE to confirm.');
        } else {
            $conn->beginTransaction();
            $a = $conn->prepare("DELETE FROM survey_responses WHERE survey = ? AND period = ?");
            $a->execute([$key, $p]);
            $conn->prepare("DELETE FROM survey_participants WHERE survey = ? AND period = ?")->execute([$key, $p]);
            $conn->commit();
            set_flash('success', 'Deleted ' . $a->rowCount() . ' response(s) for ' . date('F Y', strtotime($p . '-01')) . '.');
        }
    }
    sv_back($key);
}

// ── Page data ────────────────────────────────────────────────────────────────
$counts = $conn->prepare("SELECT period, COUNT(*) AS n FROM survey_responses WHERE survey = ? GROUP BY period ORDER BY period DESC");
$counts->execute([$key]);
$monthCounts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
$thisMonth   = $monthCounts[survey_period()] ?? 0;

// What the editor starts from: a rejected draft, else the live definition.
$editor = [
    'title'      => $survey['title'],
    'teams'      => $survey['teams'],
    'dimensions' => array_map(fn($d) => [
        'name' => $d['name'], 'weight' => $d['weight'],
        'questions' => array_map(fn($id, $t) => ['id' => $id, 'text' => $t], array_keys($d['questions']), $d['questions']),
    ], $survey['dimensions']),
    'enps'       => $survey['enps'] ?? '',
    'open'       => array_merge(array_fill_keys(SV_OPEN_KEYS, ''), $survey['open'] ?? []),
];
$draft = $_SESSION['sv_draft'][$key] ?? null;
if ($draft && is_array($d = json_decode($draft, true))) $editor = $d;

$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . survey_path($key);

include 'header.php';
?>
<style>
    .sv-sec { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 12px; background: #fff; }
    .sv-sec .sv-q { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; }
    .sv-sec .sv-qid { font-size: 11px; color: #94a3b8; min-width: 30px; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <ul class="nav nav-pills">
        <?php foreach ($defs as $k => $d): ?>
            <li class="nav-item"><a class="nav-link <?= $k === $key ? 'active' : '' ?>" href="survey_manage.php?s=<?= $k ?>">
                <?= sanitize($d['title']) ?><?php if (empty($d['is_open'])): ?> <span class="badge bg-secondary ms-1">Closed</span><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <a href="survey_results.php?s=<?= $key ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart me-1"></i>View results</a>
</div>

<!-- Status -->
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold">
                <?= empty($survey['is_open']) ? '<span class="badge bg-secondary">Closed</span>' : '<span class="badge bg-success">Open</span>' ?>
                <?= sanitize($survey['title']) ?>
            </div>
            <div class="small text-muted mt-1">
                Link: <code><?= sanitize($shareUrl) ?></code> ·
                <?= $thisMonth ?> response<?= $thisMonth === 1 ? '' : 's' ?> this month
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="s" value="<?= sanitize($key) ?>">
            <input type="hidden" name="action" value="toggle">
            <?php if (empty($survey['is_open'])): ?>
                <button class="btn btn-success"><i class="bi bi-unlock me-1"></i>Open survey</button>
            <?php else: ?>
                <button class="btn btn-outline-secondary" onclick="return confirm('Close this survey? People opening the link will see that it is closed.')"><i class="bi bi-lock me-1"></i>Close survey</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Editor -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Questions <?= !empty($survey['edited']) ? '<span class="badge bg-info text-dark ms-1">Edited</span>' : '<span class="badge bg-light text-muted ms-1">Default</span>' ?></span>
        <?php if (!empty($survey['edited'])): ?>
        <form method="POST" onsubmit="return confirm('Throw away your edits and go back to the default questions?')">
            <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="s" value="<?= sanitize($key) ?>">
            <input type="hidden" name="action" value="restore">
            <button class="btn btn-sm btn-outline-secondary">Restore defaults</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($thisMonth): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= $thisMonth ?> response<?= $thisMonth === 1 ? ' has' : 's have' ?> already come in this month.
            Rewording a question keeps its earlier answers attached, so only make small wording fixes.
            A new question starts with no answers, and a removed question disappears from the results.
            Renaming a team doesn't move earlier answers to the new name.
        </div>
        <?php endif; ?>

        <form method="POST" id="svEditForm">
            <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="s" value="<?= sanitize($key) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="definition" id="svDefinition">

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label small fw-semibold">Survey title</label>
                    <input type="text" class="form-control" id="svTitle" maxlength="120">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Teams <span class="text-muted fw-normal">(one per line)</span></label>
                    <textarea class="form-control" id="svTeams" rows="4"></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small">Sections · rated 1–5</span>
                <span class="small" id="svWeightTotal"></span>
            </div>
            <div id="svSections"></div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-4" id="svAddSection"><i class="bi bi-plus-lg me-1"></i>Add section</button>

            <div class="mb-3">
                <label class="form-label small fw-semibold">0–10 "recommend" question (eNPS) <span class="text-muted fw-normal">· leave empty for none</span></label>
                <input type="text" class="form-control" id="svEnps" maxlength="300">
            </div>
            <label class="form-label small fw-semibold">Written questions (optional answers) <span class="text-muted fw-normal">· leave empty to skip</span></label>
            <?php foreach (SV_OPEN_KEYS as $i => $k): ?>
                <input type="text" class="form-control mb-2" id="svOpen_<?= $k ?>" maxlength="300" placeholder="Written question <?= $i + 1 ?>">
            <?php endforeach; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save questions</button>
                <a href="survey_manage.php?s=<?= $key ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Danger zone -->
<div class="card border-danger mb-4">
    <div class="card-header bg-white text-danger fw-semibold">Delete responses</div>
    <div class="card-body">
        <p class="small text-muted">Deletes every response and the "who answered" record for one month, for example to clear test answers. This can't be undone.</p>
        <?php if (!$monthCounts): ?>
            <p class="small text-muted mb-0">No responses to delete.</p>
        <?php else: ?>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-center" onsubmit="return confirm('Permanently delete these responses?')">
            <input type="hidden" name="csrf" value="<?= sanitize(csrf_token()) ?>">
            <input type="hidden" name="s" value="<?= sanitize($key) ?>">
            <input type="hidden" name="action" value="delete_month">
            <select name="period" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($monthCounts as $p => $n): ?>
                    <option value="<?= sanitize($p) ?>"><?= sanitize(date('F Y', strtotime($p . '-01'))) ?> · <?= (int)$n ?> response<?= $n == 1 ? '' : 's' ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="confirm" class="form-control form-control-sm" style="width:150px" placeholder="Type DELETE" autocomplete="off" required>
            <button class="btn btn-sm btn-danger">Delete</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const START = <?= json_encode($editor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const OPEN_KEYS = <?= json_encode(SV_OPEN_KEYS) ?>;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    $('svTitle').value = START.title || '';
    $('svTeams').value = (START.teams || []).join('\n');
    $('svEnps').value  = START.enps || '';
    OPEN_KEYS.forEach(k => $('svOpen_' + k).value = (START.open || {})[k] || '');

    const qRow = q => `<div class="sv-q" data-id="${esc(q.id || '')}">
            <span class="sv-qid">${q.id ? esc(q.id.toUpperCase()) : 'new'}</span>
            <input type="text" class="form-control form-control-sm sv-qtext" maxlength="300" value="${esc(q.text)}" placeholder="Question">
            <button type="button" class="btn btn-sm btn-outline-danger sv-del-q" title="Remove question"><i class="bi bi-x-lg"></i></button>
        </div>`;

    function addSection(d) {
        const el = document.createElement('div');
        el.className = 'sv-sec';
        el.innerHTML = `<div class="d-flex gap-2 mb-2 align-items-center">
                <input type="text" class="form-control form-control-sm fw-semibold sv-name" maxlength="80" value="${esc(d.name)}" placeholder="Section name">
                <div class="input-group input-group-sm" style="width:110px">
                    <input type="number" class="form-control sv-weight" min="0" max="100" step="1" value="${esc(d.weight)}" title="Weight">
                    <span class="input-group-text">%</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger sv-del-sec" title="Remove section"><i class="bi bi-trash"></i></button>
            </div>
            <div class="sv-qs">${(d.questions || []).map(qRow).join('')}</div>
            <button type="button" class="btn btn-sm btn-link px-0 sv-add-q"><i class="bi bi-plus"></i> Add question</button>`;
        $('svSections').appendChild(el);
    }
    (START.dimensions || []).forEach(addSection);

    function updateTotal() {
        const total = [...document.querySelectorAll('.sv-weight')].reduce((s, i) => s + (parseInt(i.value, 10) || 0), 0);
        $('svWeightTotal').innerHTML = total === 100
            ? '<span class="text-success"><i class="bi bi-check-circle"></i> Weights total 100%</span>'
            : `<span class="text-danger"><i class="bi bi-exclamation-circle"></i> Weights total ${total}%, must be 100%</span>`;
    }
    updateTotal();

    $('svAddSection').addEventListener('click', () => { addSection({ name: '', weight: 0, questions: [{ text: '' }] }); updateTotal(); });
    $('svSections').addEventListener('input', e => { if (e.target.classList.contains('sv-weight')) updateTotal(); });
    $('svSections').addEventListener('click', e => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.classList.contains('sv-add-q')) {
            t.previousElementSibling.insertAdjacentHTML('beforeend', qRow({ text: '' }));
            t.previousElementSibling.lastElementChild.querySelector('input').focus();
        } else if (t.classList.contains('sv-del-q')) {
            t.closest('.sv-q').remove();
        } else if (t.classList.contains('sv-del-sec')) {
            if (confirm('Remove this section and its questions?')) { t.closest('.sv-sec').remove(); updateTotal(); }
        }
    });

    $('svEditForm').addEventListener('submit', () => {
        $('svDefinition').value = JSON.stringify({
            title: $('svTitle').value,
            teams: $('svTeams').value.split('\n'),
            dimensions: [...document.querySelectorAll('.sv-sec')].map(sec => ({
                name: sec.querySelector('.sv-name').value,
                weight: parseInt(sec.querySelector('.sv-weight').value, 10) || 0,
                questions: [...sec.querySelectorAll('.sv-q')].map(q => ({ id: q.dataset.id, text: q.querySelector('.sv-qtext').value })),
            })),
            enps: $('svEnps').value,
            open: Object.fromEntries(OPEN_KEYS.map(k => [k, $('svOpen_' + k).value])),
        });
    });
})();
</script>

<?php include 'footer.php'; ?>

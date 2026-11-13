<?php
// Surveys: shared definitions, schema and scoring for survey.php (respondents),
// survey_results.php, survey_manage.php and survey_home_card.php (admins).
//
// The questions below are the DEFAULTS. Admins can edit them on survey_manage.php;
// an edited copy is stored in survey_settings.definition and wins over these.
// Question ids (q1, q2 …) are what gets stored in survey_responses.answers, so an
// id is never reused for a different question: new questions get qseq + 1.

const SURVEY_TEAMS = ['Tech', 'Digital', 'Creative', 'Other'];

const SURVEY_TEXT_MAX = 2000;

function survey_default_definitions(): array {
    return [
        'EHI' => [
            'key'      => 'EHI',
            'title'    => 'Employee Happiness Index',
            'dimensions' => [
                ['name' => 'Role Clarity & Ownership', 'weight' => 10, 'questions' => [
                    'q1' => 'I clearly understand what is expected of me at work.',
                    'q2' => 'The ownership / commitment I hold on my team is clear and meaningful to me.',
                    'q3' => "I understand how my work connects to my team's goals.",
                ]],
                ['name' => 'Enablement & Resources', 'weight' => 8, 'questions' => [
                    'q4' => 'I have the tools, software and information I need to do my work well.',
                    'q5' => "When I'm blocked, I can get the support or decision I need quickly.",
                    'q6' => 'Our processes help me do good work rather than slow me down.',
                ]],
                ['name' => 'Growth & Learning', 'weight' => 16, 'questions' => [
                    'q7' => 'Digifyce actively invests in my learning and development.',
                    'q8' => 'The Learning & Development sessions are relevant and useful to my growth.',
                    'q9' => 'I can see a path to grow my career here over the next 1–2 years.',
                ]],
                ['name' => 'Recognition & Reward', 'weight' => 18, 'questions' => [
                    'q10' => 'In the last month, I received recognition or praise for good work.',
                    'q11' => 'Our recognition and incentive programs feel fair and motivating.',
                    'q12' => 'The link between my performance and my incentives is clear to me.',
                ]],
                ['name' => 'Manager & Leadership Trust', 'weight' => 16, 'questions' => [
                    'q13' => 'My manager genuinely cares about me as a person.',
                    'q14' => "Leadership communicates openly and honestly about what's happening at Digifyce.",
                    'q15' => 'Decisions that affect me are made fairly.',
                ]],
                ['name' => 'Belonging & Camaraderie', 'weight' => 12, 'questions' => [
                    'q16' => 'I have strong working relationships with the people on my team.',
                    'q17' => 'The Monthly Play engagements genuinely help me feel connected to colleagues.',
                    'q18' => 'I feel I belong at Digifyce.',
                ]],
                ['name' => 'Purpose & Pride', 'weight' => 12, 'questions' => [
                    'q19' => 'I am proud to tell people I work at Digifyce.',
                    'q20' => 'The mission and work of Digifyce make me feel my job is important.',
                    'q21' => 'I would recommend Digifyce as a great place to work.',
                ]],
                ['name' => 'Wellbeing & Balance', 'weight' => 8, 'questions' => [
                    'q22' => 'The pace of work at Digifyce is sustainable for me.',
                    'q23' => 'The Fitness programs and wellbeing support are valuable to me.',
                    'q24' => 'I can balance my work and personal life well.',
                ]],
            ],
            'enps' => 'On a scale of 0-10, how likely are you to recommend Digifyce as a place to work?',
            'open' => [
                'change'     => 'What is the one thing we could change that would most improve your experience at Digifyce?',
                'keep'       => 'What is Digifyce doing well that we should absolutely keep doing?',
                'frustrates' => 'What is something that frustrates you or slows you down that leadership may not be aware of?',
            ],
        ],
        'RUBRICS' => [
            'key'      => 'RUBRICS',
            'title'    => 'Team Performance Rubrics',
            'dimensions' => [
                ['name' => 'Contribution / Participation', 'weight' => 20, 'questions' => [
                    'q1' => 'Team members actively contribute ideas during discussions and meetings.',
                    'q2' => 'Team members suggest improvements that help the team achieve its goals.',
                ]],
                ['name' => 'Attitude', 'weight' => 15, 'questions' => [
                    'q3' => 'Team members listen to and consider the ideas of others.',
                    'q4' => 'Team members contribute to maintaining a positive and united team environment.',
                ]],
                ['name' => 'Responsibility', 'weight' => 20, 'questions' => [
                    'q5' => 'Team members complete assigned tasks within agreed timelines.',
                    'q6' => 'Team members can be relied upon to fulfill their commitments.',
                ]],
                ['name' => 'Attendance & Punctuality', 'weight' => 15, 'questions' => [
                    'q7' => 'Team members attend meetings regularly and on time.',
                    'q8' => 'Team members respect deadlines and commitments.',
                ]],
                ['name' => 'Organization', 'weight' => 15, 'questions' => [
                    'q9'  => 'Tasks and responsibilities are distributed clearly within the team.',
                    'q10' => 'Team members collaborate effectively when organizing and completing work.',
                ]],
                ['name' => 'Conflict Resolution', 'weight' => 15, 'questions' => [
                    'q11' => 'Team members handle disagreements respectfully and professionally.',
                    'q12' => 'Team members work towards solutions and consensus during conflicts.',
                ]],
            ],
            'enps' => null,
            'open' => [],
        ],
    ];
}

// Survey cycle: one response per person per survey per calendar month.
function survey_period(): string {
    return date('Y-m');
}

// Creates/upgrades the survey tables. Idempotent and cheap; runs once per request.
function survey_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // config.php connects as utf8 (3-byte), which turns emoji in written answers
    // into "?" on the way in or out. utf8mb4 is a superset, safe for the whole page.
    $conn->exec("SET NAMES utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS survey_participants (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        survey       VARCHAR(20)  NOT NULL,
        period       CHAR(7)      NOT NULL,
        email        VARCHAR(190) NOT NULL,
        submitted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_survey_period_email (survey, period, email),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS survey_responses (
        id              CHAR(32)     NOT NULL PRIMARY KEY,
        survey          VARCHAR(20)  NOT NULL,
        period          CHAR(7)      NOT NULL,
        team            VARCHAR(20)  NOT NULL,
        answers         JSON         NOT NULL,
        enps            TINYINT      NULL,
        open_change     TEXT         NULL,
        open_keep       TEXT         NULL,
        open_frustrates TEXT         NULL,
        KEY idx_survey_period (survey, period, team)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Responses are linked to the respondent's email (when known) since Nov 2026.
    // Older rows stay NULL: they were collected as anonymous.
    try {
        $conn->query("SELECT email, submitted_at FROM survey_responses LIMIT 0");
    } catch (PDOException $e) {
        $conn->exec("ALTER TABLE survey_responses
            ADD COLUMN email VARCHAR(190) NULL AFTER period,
            ADD COLUMN submitted_at DATETIME NULL AFTER email,
            ADD KEY idx_resp_email (email)");
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS survey_settings (
        survey     VARCHAR(20) NOT NULL PRIMARY KEY,
        is_open    TINYINT(1)  NOT NULL DEFAULT 1,
        definition JSON        NULL,
        updated_by INT         NULL,
        updated_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Highest question number in a definition (used to hand out new ids).
function survey_max_qid(array $def): int {
    $max = (int)($def['qseq'] ?? 0);
    foreach ($def['dimensions'] as $d) {
        foreach (array_keys($d['questions']) as $qid) $max = max($max, (int)substr($qid, 1));
    }
    return $max;
}

// Definitions with admin edits and open/closed state applied.
function survey_definitions(?PDO $conn = null): array {
    $defs = survey_default_definitions();
    foreach ($defs as $k => &$d) {
        $d['teams']   = SURVEY_TEAMS;
        $d['is_open'] = true;
        $d['edited']  = false;
    }
    unset($d);
    if (!$conn) return $defs;

    survey_ensure_schema($conn);
    foreach ($conn->query("SELECT survey, is_open, definition FROM survey_settings")->fetchAll() as $row) {
        $k = $row['survey'];
        if (!isset($defs[$k])) continue;
        if ($row['definition']) {
            $custom = json_decode($row['definition'], true);
            if (is_array($custom) && !empty($custom['dimensions'])) {
                $defs[$k] = array_merge($defs[$k], $custom, ['key' => $k, 'edited' => true]);
            }
        }
        $defs[$k]['is_open'] = (bool)$row['is_open'];
    }
    foreach ($defs as $k => &$d) $d['qseq'] = survey_max_qid($d);
    unset($d);
    return $defs;
}

// Scores for a set of response rows (each with decoded 'answers' and 'enps').
//  - question avg on 1–5, % favourable = answered 4 or 5
//  - section avg = mean of its question averages
//  - overall = section averages weighted by section weight
//  - eNPS = % promoters (9–10) − % detractors (0–6)
function survey_score(array $rows, array $survey): array {
    $out = ['n' => count($rows), 'sections' => [], 'overall' => null, 'enps' => null];
    if (!$rows) return $out;

    $wSum = 0; $wTotal = 0;
    foreach ($survey['dimensions'] as $dim) {
        $qs = [];
        foreach ($dim['questions'] as $qid => $text) {
            $vals = [];
            foreach ($rows as $r) {
                $v = $r['answers'][$qid] ?? null;
                if (is_int($v)) $vals[] = $v;
            }
            $cnt = count($vals);
            $qs[$qid] = [
                'text' => $text,
                'avg'  => $cnt ? array_sum($vals) / $cnt : null,
                'fav'  => $cnt ? count(array_filter($vals, fn($v) => $v >= 4)) / $cnt * 100 : null,
                'dist' => array_map(fn($k) => count(array_filter($vals, fn($v) => $v === $k)), [1, 2, 3, 4, 5]),
            ];
        }
        $avgs = array_values(array_filter(array_column($qs, 'avg'), fn($v) => $v !== null));
        $favs = array_values(array_filter(array_column($qs, 'fav'), fn($v) => $v !== null));
        $avg  = $avgs ? array_sum($avgs) / count($avgs) : null;
        $out['sections'][] = [
            'name' => $dim['name'], 'weight' => $dim['weight'], 'avg' => $avg,
            'fav'  => $favs ? array_sum($favs) / count($favs) : null, 'questions' => $qs,
        ];
        if ($avg !== null) { $wSum += $avg * $dim['weight']; $wTotal += $dim['weight']; }
    }
    $out['overall'] = $wTotal ? $wSum / $wTotal : null;

    if (!empty($survey['enps'])) {
        $e = [];
        foreach ($rows as $r) if ($r['enps'] !== null && $r['enps'] !== '') $e[] = (int)$r['enps'];
        if ($e) {
            $p = count(array_filter($e, fn($v) => $v >= 9));
            $d = count(array_filter($e, fn($v) => $v <= 6));
            $out['enps'] = ['score' => (int)round(($p - $d) / count($e) * 100), 'n' => count($e),
                            'promoters' => $p, 'passives' => count($e) - $p - $d, 'detractors' => $d];
        }
    }
    return $out;
}

// Response rows for a survey (optionally one period), answers decoded.
function survey_load_responses(PDO $conn, string $key, ?string $period = null): array {
    $sql = "SELECT id, period, email, submitted_at, team, answers, enps, open_change, open_keep, open_frustrates
            FROM survey_responses WHERE survey = ?" . ($period ? " AND period = ?" : "");
    $s = $conn->prepare($sql);
    $s->execute($period ? [$key, $period] : [$key]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['answers'] = json_decode($r['answers'], true) ?: [];
    unset($r);
    return $rows;
}

// Active HRMS users who haven't taken this survey in this period (by email).
// Matched in PHP, not with a cross-table SQL join: on newer MariaDB the survey
// tables get utf8mb4_uca1400_ai_ci while users is utf8mb4_unicode_ci, and
// comparing the two columns fails with "Illegal mix of collations".
function survey_pending_users(PDO $conn, string $key, string $period): array {
    $p = $conn->prepare("SELECT email FROM survey_participants WHERE survey = ? AND period = ?");
    $p->execute([$key, $period]);
    $done = array_flip(array_map(fn($e) => strtolower(trim((string)$e)), $p->fetchAll(PDO::FETCH_COLUMN)));

    $users = $conn->query("SELECT id, name, email, role FROM users
        WHERE COALESCE(invite_status, 'ACTIVE') = 'ACTIVE' AND email IS NOT NULL AND email <> ''
        ORDER BY name")->fetchAll();
    return array_values(array_filter($users, fn($u) => !isset($done[strtolower(trim($u['email']))])));
}

// Public survey path for a key (matches the .htaccess rewrites).
function survey_path(string $key): string {
    return $key === 'RUBRICS' ? 'Rubrics' : $key;
}

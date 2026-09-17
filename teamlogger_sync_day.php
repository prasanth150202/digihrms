<?php
/**
 * Lean single-day sync — called by the browser one day at a time.
 * Skips per-employee timesheet API calls (those are only needed for the
 * live detail view). Punch report already has totalHours — enough for DB.
 * Returns JSON: { synced: N, skipped: N, error: "..." | null }
 */
require_once 'config.php';
require_login();

header('Content-Type: application/json');

// Team leads and managers sync their own reporting data, so they are allowed here too;
// plain employees are not. Answered as JSON rather than through require_role(), whose
// HTML error page would break every caller — they all parse this response as JSON.
if (!in_array($_SESSION['user']['role'] ?? '', ['SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER','TEAM_LEAD'], true)) {
    http_response_code(403);
    echo json_encode(['synced' => 0, 'skipped' => 0, 'error' => 'You do not have permission to sync.']);
    exit;
}

set_time_limit(120);

// Per-interval activity. The API gives it on every sync and it was being reduced to daily
// totals and discarded; reports need the intervals themselves to clip task time to the
// hours somebody was actually working.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS tl_segments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        type VARCHAR(16) NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        INDEX idx_user_date (user_id, date)
    )");
} catch (PDOException $e) {}

$date = $_POST['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['synced' => 0, 'skipped' => 0, 'error' => 'Invalid date']);
    exit;
}

// ── Settings ──────────────────────────────────────────────
$s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('tl_api_key','tl_timezone_offset','tl_day_start','tl_day_end')");
$s->execute();
$cfg = array_column($s->fetchAll(), 'setting_value', 'setting_key');
$api_key   = $cfg['tl_api_key']           ?? '';
$tz        = $cfg['tl_timezone_offset']   ?? '330';
$day_start = $cfg['tl_day_start']         ?? '9.5';
$day_end   = $cfg['tl_day_end']           ?? '18.5';

if (!$api_key) {
    echo json_encode(['synced' => 0, 'skipped' => 0, 'error' => 'API key not configured']);
    exit;
}

// ── Tiny helpers ──────────────────────────────────────────
function tl_api_sd(string $endpoint, string $key, array $params = []): array {
    $url = 'https://api2.teamlogger.com' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $key", "Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $err) return ['error' => $err ?: 'Empty response'];
    if ($code >= 400) return ['error' => "HTTP $code: " . substr($raw, 0, 120)];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : ['error' => 'Bad JSON: ' . substr($raw, 0, 100)];
}

function norm_time_sd(?string $v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $ts = (int)$v;
        if ($ts > 9999999999) $ts = intdiv($ts, 1000);
        return $ts > 0 ? date('H:i:s', $ts) : null;
    }
    $ts = strtotime($v);
    return $ts !== false ? date('H:i:s', $ts) : null;
}

function pick_sd(array $row, array $keys): ?string {
    foreach ($keys as $k) {
        $v = $row[$k] ?? null;
        if ($v !== null && $v !== '') return (string)$v;
    }
    foreach (['attendance','punch','timeData','data','record'] as $nest) {
        if (is_array($row[$nest] ?? null)) {
            foreach ($keys as $k) {
                $v = $row[$nest][$k] ?? null;
                if ($v !== null && $v !== '') return (string)$v;
            }
        }
    }
    return null;
}

// ── 1. Fetch punch report ─────────────────────────────────
[$y, $m, $d] = explode('-', $date);
$report = tl_api_sd('/api/company_punch_in_out_report', $api_key, [
    'year'                  => (int)$y,
    'month'                 => (int)$m,
    'day'                   => (int)$d,
    'timezoneOffsetMinutes' => (int)$tz,
    'dayStartsAtHours'      => $day_start,
    'dayEndsAtHours'        => $day_end,
]);
if (isset($report['error'])) {
    echo json_encode(['synced' => 0, 'skipped' => 0, 'error' => $report['error']]);
    exit;
}
$entries = isset($report[0]) ? $report : ($report['data'] ?? []);

// ── 2. Fetch user list to map emp_code/email → GUID ──────
$raw_users    = tl_api_sd('/api/integration/list_users', $api_key);
$tl_users     = isset($raw_users[0]) ? $raw_users : ($raw_users['users'] ?? $raw_users['data'] ?? []);
$guid_by_code  = [];
$guid_by_email = [];
// The punch report frequently omits the email address that this roster does carry, which
// left the email fallback in $get_user with nothing to match on. Keep the roster's address
// so it can be backfilled onto each punch row below.
$email_by_code = [];
$email_by_guid = [];
// Some punch rows arrive with no code and no email at all, leaving the name as the only
// key they share with the roster. Index by name as well so those rows can still reach the
// roster's email — but count the names first, because two people sharing one is not a
// link, it is a coin toss.
$email_by_name = [];
$guid_by_name  = [];
$name_seen     = [];
foreach ($tl_users as $tu) {
    $n = mb_strtolower(trim($tu['name'] ?? $tu['employeeName'] ?? $tu['fullName'] ?? $tu['username'] ?? ''));
    if ($n !== '') $name_seen[$n] = ($name_seen[$n] ?? 0) + 1;
}
foreach ($tl_users as $tu) {
    $c = strtoupper(trim($tu['employeeCode'] ?? $tu['empCode'] ?? $tu['code'] ?? $tu['employeeId'] ?? ''));
    $e = strtolower(trim($tu['email'] ?? $tu['employeeEmail'] ?? ''));
    $g = $tu['guid'] ?? $tu['id'] ?? $tu['userId'] ?? '';
    $n = mb_strtolower(trim($tu['name'] ?? $tu['employeeName'] ?? $tu['fullName'] ?? $tu['username'] ?? ''));
    if ($c && $g) $guid_by_code[$c]  = $g;
    if ($e && $g) $guid_by_email[$e] = $g;
    if ($c && $e) $email_by_code[$c] = $e;
    if ($g && $e) $email_by_guid[$g] = $e;
    if ($n && ($name_seen[$n] ?? 0) === 1) {
        if ($e) $email_by_name[$n] = $e;
        if ($g) $guid_by_name[$n]  = $g;
    }
}

// One place that decides which TeamLogger account a punch row belongs to. Both the
// timesheet fetch and the DB write use it — they resolved this separately before, drifted
// apart, and people with no employee code silently lost their idle/meeting data.
$resolve_guid = function (array $row) use ($guid_by_code, $guid_by_email, $guid_by_name): ?string {
    $c = strtoupper(trim($row['employeeCode'] ?? $row['empCode'] ?? $row['code'] ?? $row['employeeId'] ?? ''));
    $e = strtolower(trim($row['employeeEmail'] ?? $row['email'] ?? ''));
    $n = mb_strtolower(trim($row['employeeName'] ?? $row['name'] ?? $row['fullName'] ?? $row['username'] ?? ''));
    $g = ($c ? ($guid_by_code[$c] ?? null) : null)
      ?? ($e ? ($guid_by_email[$e] ?? null) : null)
      ?? ($n ? ($guid_by_name[$n] ?? null) : null);
    return $g !== null ? (string)$g : null;
};

// ── 3. Fetch timesheet for each employee in parallel ─────
$offset_s = (int)$tz * 60;
$base_utc  = strtotime($date . ' 00:00:00 UTC');
$start_ms  = ($base_utc - $offset_s) * 1000;
$end_ms    = ($base_utc - $offset_s + 86399) * 1000;

$ts_requests = [];
foreach ($entries as $row) {
    $g = $resolve_guid($row);
    if ($g && !isset($ts_requests[$g])) {
        $ts_requests[$g] = [$g, $start_ms, $end_ms];
    }
}

// Parallel curl for all timesheets
$seg_totals = [];
$seg_rows   = [];
if ($ts_requests) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($ts_requests as $guid => [$g, $sms, $ems]) {
        $url = 'https://api2.teamlogger.com/api/timesheet_data?' . http_build_query(['startTime' => $sms, 'endTime' => $ems, 'accountId' => $g]);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $api_key", "Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $handles[$guid] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    foreach ($handles as $guid => $ch) {
        $raw = curl_multi_getcontent($ch);
        $ts  = json_decode($raw, true);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if (!is_array($ts)) continue;
        $ts_entries = isset($ts[0]) ? $ts : ($ts['data'] ?? []);
        $t = ['active' => 0.0, 'idle' => 0.0, 'meeting' => 0.0];
        $segs = [];
        foreach ($ts_entries as $e) {
            $st = (int)($e['startTime'] ?? 0); $en = (int)($e['endTime'] ?? 0);
            if ($st <= 0 || $en <= $st) continue;
            $dur_h   = ($en - $st) / 3600000;
            $idle_h  = (float)($e['idleHours'] ?? 0);
            $meeting = !empty($e['meetingMode']);
            // Epoch ms -> local wall clock, matching how the day bounds above were built,
            // so these line up with task_timers' own timestamps.
            $l_start = intdiv($st, 1000) + $offset_s;
            $l_end   = intdiv($en, 1000) + $offset_s;
            if ($meeting) {
                $t['meeting'] += $dur_h;
                $segs[] = ['meeting', $l_start, $l_end];
            } else {
                $act_h = (float)($e['activeHours'] ?? max(0, $dur_h - $idle_h));
                $t['active'] += $act_h;
                $t['idle']   += $idle_h;
                // The API says how much of the entry was idle but not where, so follow the
                // same convention as teamlogger.php: active first, then the idle remainder.
                $split = min($l_end, $l_start + (int)round($act_h * 3600));
                if ($split > $l_start) $segs[] = ['active', $l_start, $split];
                if ($l_end > $split)   $segs[] = ['idle',   $split,   $l_end];
            }
        }
        $seg_totals[$guid] = $t;
        $seg_rows[$guid]   = $segs;
    }
    curl_multi_close($mh);
}

// ── 4. Write to DB ────────────────────────────────────────
$conn->prepare("DELETE FROM attendance WHERE date=? AND source='TEAMLOGGER'")->execute([$date]);

// Cache user lookups within this request
$user_cache = [];
$get_user = function(string $emp_code, string $email, ?string $guid = null, string $name = '', &$via = null) use ($conn, &$user_cache): ?int {
    $key = $emp_code . '|' . $email . '|' . (string)$guid . '|' . $name;
    if (array_key_exists($key, $user_cache)) return $user_cache[$key];
    $uid = null;
    // tl_guid first: it is the explicit link set by the "map user" screen in teamlogger.php,
    // so it should win over guessing by code or address.
    if ($guid) {
        try {
            $s = $conn->prepare("SELECT id FROM users WHERE tl_guid=? LIMIT 1");
            $s->execute([$guid]); $uid = $s->fetchColumn() ?: null;
        } catch (Exception $e) { /* column not present on this install */ }
    }
    // Explicit alias set by a human on the diagnostic page. Some TeamLogger people send
    // only a name, and it need not spell the same as the HRMS one, so this is the only
    // durable link available for them.
    if (!$uid && $name !== '') {
        try {
            $s = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(tl_name))=? LIMIT 1");
            $s->execute([mb_strtolower(trim($name))]);
            $uid = $s->fetchColumn() ?: null;
            if ($uid) $via = 'tl_name';
        } catch (Exception $e) { /* column not present on this install */ }
    }
    if (!$uid && $emp_code) {
        $s = $conn->prepare("SELECT id FROM users WHERE UPPER(emp_no)=? LIMIT 1");
        $s->execute([$emp_code]); $uid = $s->fetchColumn() ?: null;
    }
    if (!$uid && $email) {
        $s = $conn->prepare("SELECT id FROM users WHERE LOWER(email)=? LIMIT 1");
        $s->execute([strtolower($email)]); $uid = $s->fetchColumn() ?: null;
        if ($uid) $via = 'email';
    } elseif ($uid && !$via) { $via = $guid ? 'tl_guid' : 'emp_no'; }

    // Last resort. Some TeamLogger people carry neither a code nor an address, leaving the
    // name as the only key in existence. Accept it only when exactly one HRMS user bears
    // that name — a second match means we cannot tell whose hours these are, and guessing
    // would file someone's day under a colleague.
    if (!$uid && $name !== '') {
        $s = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(name))=? LIMIT 2");
        $s->execute([mb_strtolower(trim($name))]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) { $uid = (int)$ids[0]; $via = 'name'; }
    }
    return $user_cache[$key] = $uid;
};

$synced = 0; $skipped = 0;
// A row still inserts when no HRMS user matches, just with user_id NULL — which makes it
// invisible to anything that filters by user. Count those separately so a sync that stores
// 31 rows against nobody cannot report itself as a clean success.
$linked = 0; $unlinked = []; $by_name = []; $seg_written = 0;

foreach ($entries as $row) {
    $emp_code = strtoupper(trim(
        $row['employeeCode'] ?? $row['employee_code'] ?? $row['empCode'] ??
        $row['emp_code'] ?? $row['employeeId'] ?? $row['code'] ?? ''
    ));
    $email   = trim($row['employeeEmail'] ?? $row['email'] ?? $row['emp_email'] ?? '');
    $tl_name = trim($row['employeeName']  ?? $row['name']  ?? $row['fullName'] ?? $row['username'] ?? '');

    if (!$emp_code && !$email && !$tl_name) { $skipped++; continue; }

    $punch_in  = norm_time_sd(pick_sd($row, ['punchInLocalTime','punchInGMT','punchIn','punch_in','firstPunchIn','clockIn','checkIn','startTime','loginTime','inTime','firstSeen']));
    $punch_out = norm_time_sd(pick_sd($row, ['punchOutLocalTime','punchOutGMT','punchOut','punch_out','lastPunchOut','clockOut','checkOut','endTime','logoutTime','outTime','lastSeen']));
    $hours     = pick_sd($row, ['totalHours','total_hours','workedHours','worked_hours','hoursWorked','hours_worked','duration','workDuration','timeWorked','hours']);

    // Break from punch report, idle/meeting from timesheet segments
    $break_h   = isset($row['breakHours']) && (float)$row['breakHours'] > 0 ? (string)round((float)$row['breakHours'], 4) : null;
    $ec2       = strtoupper(trim($row['employeeCode'] ?? $row['empCode'] ?? $row['code'] ?? $row['employeeId'] ?? ''));
    $nm2       = mb_strtolower(trim($tl_name));
    $guid      = $resolve_guid($row);
    // Punch rows often have no email; take it from the roster so matching has something to
    // work with when emp_no is unset in HRMS, which is the usual case. Falling back to the
    // name last covers rows carrying neither a code nor an address.
    if ($email === '') {
        $email = ($ec2 && isset($email_by_code[$ec2])) ? $email_by_code[$ec2]
               : (($guid && isset($email_by_guid[$guid])) ? $email_by_guid[$guid]
               : (($nm2 && isset($email_by_name[$nm2])) ? $email_by_name[$nm2] : ''));
    }
    $totals    = $guid ? ($seg_totals[$guid] ?? null) : null;
    $idle_h    = $totals && $totals['idle']    > 0 ? (string)round($totals['idle'],    4) : null;
    $meeting_h = $totals && $totals['meeting'] > 0 ? (string)round($totals['meeting'], 4) : null;
    $active_h  = $totals && $totals['active']  > 0
        ? (string)round($totals['active'], 4)
        : ($hours !== null ? (string)max(0, round((float)$hours - (float)($break_h ?? 0) - (float)($idle_h ?? 0) - (float)($meeting_h ?? 0), 4)) : null);

    $has_hours = $hours !== null && (float)$hours > 0;
    $status = 'PRESENT';
    if (!$punch_in && !$punch_out && !$has_hours) {
        $status = 'ABSENT';
    } elseif ($has_hours && (float)$hours < 4) {
        $status = 'HALF_DAY';
    } elseif ($punch_in) {
        $pi_h = (int)date('H', strtotime($punch_in)) + (int)date('i', strtotime($punch_in)) / 60;
        if ($pi_h > 9.75) $status = 'LATE';
    }

    $match_via = null;
    $usr = $get_user($emp_code, $email, $guid, $tl_name, $match_via);
    if ($usr) {
        $linked++;
        if ($match_via === 'name') $by_name[] = $tl_name;
    } else {
        $unlinked[] = $tl_name ?: ($emp_code ?: $email);
    }

    try {
        $conn->prepare("
            INSERT INTO attendance
                (user_id, date, check_in, check_out, total_hours, break_hours, active_hours, idle_hours, meeting_hours, status, source, tl_employee_code, tl_employee_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TEAMLOGGER', ?, ?)
        ")->execute([$usr, $date, $punch_in, $punch_out, $hours, $break_h, $active_h, $idle_h, $meeting_h, $status, $emp_code, $tl_name]);
        $synced++;

        // Store this person's intervals for the day. Only possible once $usr is known,
        // which is why an unlinked person gets no segments and so no verified task time.
        if ($usr && $guid && !empty($seg_rows[$guid])) {
            try {
                $conn->prepare("DELETE FROM tl_segments WHERE user_id=? AND date=?")->execute([$usr, $date]);
                $ins = $conn->prepare("INSERT INTO tl_segments (user_id,date,type,start_at,end_at) VALUES (?,?,?,?,?)");
                foreach ($seg_rows[$guid] as [$type, $ls, $le]) {
                    if ($le <= $ls) continue;
                    $ins->execute([$usr, $date, $type, date('Y-m-d H:i:s', $ls), date('Y-m-d H:i:s', $le)]);
                }
                $seg_written++;
            } catch (Exception $e) { /* table missing on this install */ }
        }
    } catch (Exception $e) {
        $skipped++;
    }
}

$dbg = ['synced'=>$synced,'skipped'=>$skipped,'error'=>null,
    'linked'=>$linked,'unlinked'=>$unlinked,
    'guid_count'=>count($guid_by_code),
    'ts_count'=>count($seg_totals),
    'guid_keys'=>array_keys($guid_by_code),
    'ts_keys'=>array_keys($seg_totals),
];
file_put_contents(__DIR__.'/tl_sd_debug.json', json_encode($dbg, JSON_PRETTY_PRINT));
echo json_encode(['synced'=>$synced,'skipped'=>$skipped,'error'=>null,
    'linked'=>$linked, 'unlinked'=>count($unlinked),
    'unlinked_who'=>array_values(array_unique(array_filter($unlinked))),
    'by_name'=>count($by_name), 'segments'=>$seg_written,
    'by_name_who'=>array_values(array_unique(array_filter($by_name)))]);

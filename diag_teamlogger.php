<?php
/**
 * Read-only TeamLogger sync diagnostic.
 * Open in a browser:  diag_teamlogger.php?date=YYYY-MM-DD
 *
 * Answers, in order, the questions that make a sync look like it "did nothing":
 *   1. Is the API key configured, and does it work?
 *   2. Does TeamLogger have any punch data for that date at all?
 *   3. Does TeamLogger know *you* — can your HRMS emp_no/email be matched to their user?
 *   4. Did rows land in `attendance`, and did they land against a real user_id?
 *
 * Writes nothing. Safe to delete once the sync is behaving.
 */
require_once 'config.php';
require_login();

$me   = current_user();
$role = $me['role'] ?? '';
if (!in_array($role, ['SUPER_ADMIN','HR_ADMIN','DEPT_MANAGER','TEAM_LEAD'], true)) {
    http_response_code(403);
    exit('Not allowed.');
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

function tl_diag_api(string $endpoint, string $key, array $params = []): array {
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
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $err) return ['error' => $err ?: 'Empty response'];
    if ($code >= 400)  return ['error' => "HTTP $code: " . substr($raw, 0, 200)];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : ['error' => 'Bad JSON: ' . substr($raw, 0, 200)];
}

$rows = [];
function say(&$rows, $label, $ok, $detail) { $rows[] = [$label, $ok, $detail]; }

// ── 1. Settings ───────────────────────────────────────────
$s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings
    WHERE setting_key IN ('tl_api_key','tl_timezone_offset','tl_day_start','tl_day_end')");
$s->execute();
$cfg = array_column($s->fetchAll(), 'setting_value', 'setting_key');
$api_key   = $cfg['tl_api_key'] ?? '';
$tz        = $cfg['tl_timezone_offset'] ?? '330';
$day_start = $cfg['tl_day_start'] ?? '9.5';
$day_end   = $cfg['tl_day_end'] ?? '18.5';

say($rows, 'API key configured', $api_key !== '',
    $api_key === '' ? 'system_settings.tl_api_key is empty — every sync will abort here.'
                    : 'present (' . strlen($api_key) . ' chars, ends …' . substr($api_key, -4) . ')');
say($rows, 'Day window settings', true, "timezoneOffsetMinutes=$tz, dayStartsAtHours=$day_start, dayEndsAtHours=$day_end");

// ── 2. Who am I, in HRMS terms ────────────────────────────
$meRow = $conn->prepare("SELECT u.id, u.name, u.email, u.role, u.emp_no, u.tl_guid FROM users u WHERE u.id=?");
$meRow->execute([$me['id']]);
$meRow = $meRow->fetch();
say($rows, 'Your HRMS identity', true,
    'id=' . $meRow['id'] . ', email=' . ($meRow['email'] ?: '(none)')
    . ', emp_no=' . ($meRow['emp_no'] ?: '(none)')
    . ', tl_guid=' . ($meRow['tl_guid'] ?: '(none)'));
say($rows, 'Matchable to TeamLogger', !empty($meRow['emp_no']) || !empty($meRow['email']),
    'The sync matches on UPPER(emp_no) first, then LOWER(email). With neither set you can never be matched.');

// ── 3. Live API checks ────────────────────────────────────
$punch_count = null;
if ($api_key !== '') {
    [$y, $m, $d] = explode('-', $date);
    $report = tl_diag_api('/api/company_punch_in_out_report', $api_key, [
        'year' => (int)$y, 'month' => (int)$m, 'day' => (int)$d,
        'timezoneOffsetMinutes' => (int)$tz,
        'dayStartsAtHours' => $day_start, 'dayEndsAtHours' => $day_end,
    ]);
    if (isset($report['error'])) {
        say($rows, "Punch report for $date", false, $report['error']);
    } else {
        $entries = isset($report[0]) ? $report : ($report['data'] ?? []);
        $punch_count = count($entries);
        say($rows, "Punch report for $date", $punch_count > 0,
            $punch_count . ' entr' . ($punch_count === 1 ? 'y' : 'ies')
            . ($punch_count === 0 ? ' — TeamLogger has no punch data for this date, so a sync legitimately stores nothing.' : ''));

        // Can TeamLogger see me on this date?
        $mine = null;
        foreach ($entries as $e) {
            $code  = strtoupper(trim($e['employeeCode'] ?? $e['empCode'] ?? $e['code'] ?? $e['employeeId'] ?? ''));
            $email = strtolower(trim($e['employeeEmail'] ?? $e['email'] ?? ''));
            if (($meRow['emp_no'] && $code === strtoupper(trim($meRow['emp_no'])))
             || ($meRow['email'] && $email === strtolower(trim($meRow['email'])))) { $mine = $e; break; }
        }
        say($rows, 'You appear in that punch report', $mine !== null,
            $mine ? 'matched — keys present: ' . implode(', ', array_slice(array_keys($mine), 0, 12))
                  : 'no entry matched your emp_no or email. Your time would sync for others but not for you.');
    }

    $users = tl_diag_api('/api/integration/list_users', $api_key);
    if (isset($users['error'])) {
        say($rows, 'TeamLogger user list', false, $users['error']);
    } else {
        $ulist = isset($users[0]) ? $users : ($users['data'] ?? []);
        say($rows, 'TeamLogger user list', count($ulist) > 0, count($ulist) . ' users returned (API key works)');
    }
}

// ── 4. What actually landed in the DB ─────────────────────
$from = date('Y-m-d', strtotime($date . ' -6 days'));
$a1 = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date=? AND source='TEAMLOGGER'");
$a1->execute([$date]);
$tot = (int)$a1->fetchColumn();

$a2 = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date=? AND source='TEAMLOGGER' AND user_id IS NULL");
$a2->execute([$date]);
$orphan = (int)$a2->fetchColumn();

say($rows, "attendance rows for $date", $tot > 0,
    "$tot row(s) from TEAMLOGGER" . ($tot === 0 ? ' — nothing has been synced for this date yet.' : ''));
say($rows, 'Rows with no HRMS user linked', $orphan === 0,
    $orphan === 0 ? 'none — every synced row is attached to a user'
                  : "$orphan row(s) have user_id NULL. They are stored but invisible to the report, which filters by user_id. This is an emp_no/email mapping problem, not a sync failure.");

$a3 = $conn->prepare("SELECT date, total_hours, active_hours, idle_hours, break_hours, meeting_hours, source
    FROM attendance WHERE user_id=? AND date>=? AND date<=? ORDER BY date DESC");
$a3->execute([$me['id'], $from, $date]);
$mineRows = $a3->fetchAll();
say($rows, "Your own rows, $from → $date", count($mineRows) > 0,
    count($mineRows) . ' row(s). This is exactly what the Report tab reads.');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>TeamLogger diagnostic</title>
<style>
 body{font:14px/1.55 -apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0f172a;padding:28px;max-width:1000px;margin:0 auto}
 h1{font-size:19px;margin:0 0 4px} .sub{color:#64748b;font-size:13px;margin-bottom:20px}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:22px}
 th,td{padding:10px 13px;text-align:left;border-bottom:1px solid #e8eef5;vertical-align:top}
 th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;background:#f8fafc}
 tr:last-child td{border-bottom:none}
 .ok{color:#15803d;font-weight:700} .bad{color:#b91c1c;font-weight:700}
 .lbl{font-weight:600;white-space:nowrap} code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:12px}
 form{margin-bottom:18px} input,button{font:inherit;padding:6px 10px;border-radius:8px;border:1px solid #cbd5e1}
 button{background:#3b82f6;color:#fff;border:none;font-weight:600;cursor:pointer}
</style></head><body>
<h1>TeamLogger sync diagnostic</h1>
<div class="sub">Read-only. Nothing on this page changes any data.</div>

<form method="GET">
    <label>Date <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"></label>
    <button>Check</button>
</form>

<table>
    <tr><th style="width:230px">Check</th><th style="width:60px">Result</th><th>Detail</th></tr>
    <?php foreach ($rows as [$label, $ok, $detail]): ?>
    <tr>
        <td class="lbl"><?= htmlspecialchars($label) ?></td>
        <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'FAIL' ?></td>
        <td><?= htmlspecialchars($detail) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php if ($mineRows): ?>
<table>
    <tr><th>Date</th><th>Total</th><th>Active</th><th>Idle</th><th>Break</th><th>Meeting</th><th>Source</th></tr>
    <?php foreach ($mineRows as $r): ?>
    <tr>
        <td><?= htmlspecialchars($r['date']) ?></td>
        <td><?= htmlspecialchars($r['total_hours']   ?? '—') ?></td>
        <td><?= htmlspecialchars($r['active_hours']  ?? '—') ?></td>
        <td><?= htmlspecialchars($r['idle_hours']    ?? '—') ?></td>
        <td><?= htmlspecialchars($r['break_hours']   ?? '—') ?></td>
        <td><?= htmlspecialchars($r['meeting_hours'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['source'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="sub">
    Reading the result: a green <code>Punch report</code> with a red <code>You appear in that punch report</code>
    means the sync works but cannot match you. A red <code>Rows with no HRMS user linked</code> means the same thing
    for other people. Both are fixed by setting <code>users.emp_no</code> to the TeamLogger employee code,
    or by making the email addresses match.
</div>
</body></html>

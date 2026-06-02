<?php
require_once 'config.php';
require_once 'points_helper.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'teamlogger';
$pageTitle = 'TeamLogger Integration';

// ── Helpers ───────────────────────────────────────────────
function get_setting(PDO $conn, string $key, string $default = ''): string {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $s->execute([$key]);
    $r = $s->fetchColumn();
    return $r !== false ? $r : $default;
}
function save_setting(PDO $conn, string $key, string $value): void {
    $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
         ->execute([$key, $value, $value]);
}
function tl_get(string $endpoint, string $api_key, array $params = []): array {
    if (!$api_key) return ['error' => 'API key not configured.'];
    $url = 'https://api2.teamlogger.com' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $api_key", "Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if (!$raw) return ['error' => 'Curl error: ' . $err];
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer $api_key\r\nContent-Type: application/json\r\n",
            'timeout' => 15, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return ['error' => 'Could not reach TeamLogger API.'];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['error' => 'Invalid response: ' . substr($raw, 0, 300)];
}
// Parallel fetch — fires all requests simultaneously, returns [key => decoded_array]
function tl_get_multi(array $requests, string $api_key): array {
    if (!$requests) return [];
    $base    = 'https://api2.teamlogger.com';
    $headers = ["Authorization: Bearer $api_key", "Content-Type: application/json"];
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => [$endpoint, $params]) {
        $url = $base . $endpoint . ($params ? '?' . http_build_query($params) : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $handles[$key] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    $results = [];
    foreach ($handles as $key => $ch) {
        $raw            = curl_multi_getcontent($ch);
        $decoded        = json_decode($raw, true);
        $results[$key]  = is_array($decoded) ? $decoded : ['error' => 'bad response'];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function tl_emp_code(array $row): string {
    return $row['employeeCode'] ?? $row['employee_code'] ?? $row['empCode']
        ?? $row['emp_code'] ?? $row['employeeId'] ?? $row['code'] ?? '';
}
function tl_emp_email(array $row): string {
    return $row['email'] ?? $row['employeeEmail'] ?? $row['emp_email'] ?? '';
}
function tl_emp_name(array $row): string {
    return $row['name'] ?? $row['employeeName'] ?? $row['fullName'] ?? $row['username'] ?? '';
}
function tl_emp_guid(array $row): string {
    return $row['guid'] ?? $row['id'] ?? $row['userId'] ?? $row['user_guid'] ?? '';
}

// Robust field extraction — tries all keys and nested objects, returns raw value
function tl_field(array $row, array $keys): ?string {
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

// Normalize any time format → "H:i:s" (or null)
// Handles: epoch ms, epoch s, "2024-04-22 09:15:00", "09:15", ISO 8601, etc.
function normalize_time(?string $val): ?string {
    if ($val === null || $val === '') return null;
    // Pure numeric → Unix timestamp
    if (is_numeric($val)) {
        $ts = (int)$val;
        if ($ts > 9999999999) $ts = intdiv($ts, 1000); // ms → s
        return $ts > 0 ? date('H:i:s', $ts) : null;
    }
    // Any parseable date/time string
    $ts = strtotime($val);
    return $ts !== false ? date('H:i:s', $ts) : null;
}

// Day bounds (local time) to epoch ms using timezone offset minutes
function tl_day_bounds_ms(string $date, string $tz): array {
    $offset_s = (int)$tz * 60;
    $base_utc = strtotime($date . ' 00:00:00 UTC');
    $start_ms = ($base_utc - $offset_s) * 1000;
    $end_ms   = ($base_utc - $offset_s + 86399) * 1000;
    return [$start_ms, $end_ms];
}

// Build timeline segments from timesheet entries
function tl_build_timeline_segments(array $entries, int $start_ms, int $end_ms, int $gap_min): array {
    $segments = [];
    if (!$entries) return $segments;
    usort($entries, fn($a, $b) => ($a['startTime'] ?? 0) <=> ($b['startTime'] ?? 0));
    $prev_end_min = null;
    foreach ($entries as $e) {
        $st = (int)($e['startTime'] ?? 0);
        $en = (int)($e['endTime'] ?? 0);
        if ($st <= 0 || $en <= 0 || $en <= $st) continue;
        $start_min = max(0, ($st - $start_ms) / 60000);
        $end_min   = max(0, ($en - $start_ms) / 60000);
        if ($start_min > ($end_ms - $start_ms) / 60000) continue;
        if ($prev_end_min !== null && ($start_min - $prev_end_min) >= $gap_min) {
            $segments[] = ['type' => 'break', 'start' => $prev_end_min, 'end' => $start_min];
        }

        $dur_min = max(0, $end_min - $start_min);
        $meeting = !empty($e['meetingMode']);
        $off_pc  = !empty($e['isOffComputer']);
        $idle_h  = isset($e['idleHours']) ? (float)$e['idleHours'] : 0.0;

        if ($meeting) {
            $segments[] = ['type' => 'meeting', 'start' => $start_min, 'end' => $end_min];
        } elseif ($off_pc) {
            $segments[] = ['type' => 'break', 'start' => $start_min, 'end' => $end_min];
        } elseif ($dur_min > 0 && $idle_h > 0) {
            $idle_min = min($dur_min, $idle_h * 60);
            $active_min = max(0, $dur_min - $idle_min);
            if ($active_min > 0) {
                $segments[] = ['type' => 'active', 'start' => $start_min, 'end' => $start_min + $active_min];
            }
            if ($idle_min > 0) {
                $segments[] = ['type' => 'idle', 'start' => $start_min + $active_min, 'end' => $end_min];
            }
        } else {
            $segments[] = ['type' => 'active', 'start' => $start_min, 'end' => $end_min];
        }

        $prev_end_min = $end_min;
    }
    return $segments;
}

// Format hours: >= 1h → "8.6h", < 1h → "42m", null/0 → "—"
function fmt_dur($v): string {
    if ($v === null || (float)$v <= 0) return '—';
    $h = (float)$v;
    return $h < 1 ? round($h * 60) . 'm' : number_format($h, 1) . 'h';
}

// ── Core sync (reusable) ──────────────────────────────────
function do_sync_attendance(PDO $conn, string $date, string $api_key, string $tz, string $ds, string $de): array {
    [$y, $m, $d] = explode('-', $date);
    $report = tl_get('/api/company_punch_in_out_report', $api_key, [
        'year'  => (int)$y, 'month' => (int)$m, 'day' => (int)$d,
        'timezoneOffsetMinutes' => (int)$tz,
        'dayStartsAtHours'      => $ds,
        'dayEndsAtHours'        => $de,
    ]);
    if (isset($report['error'])) return ['synced' => 0, 'skipped' => 0, 'error' => $report['error']];

    $entries = isset($report[0]) ? $report : ($report['data'] ?? []);

    // Day bounds used for timesheet API calls
    [$start_ms, $end_ms] = tl_day_bounds_ms($date, $tz);

    // Build GUID map once — same method the dashboard cards use
    $raw_users     = tl_get('/api/integration/list_users', $api_key);
    $tl_users_list = isset($raw_users[0]) ? $raw_users : ($raw_users['users'] ?? $raw_users['data'] ?? []);
    $guid_by_code  = [];
    $guid_by_email = [];
    foreach ($tl_users_list as $tu) {
        $c = strtoupper(trim(tl_emp_code($tu)));
        $e = strtolower(trim(tl_emp_email($tu)));
        $g = tl_emp_guid($tu);
        if ($c && $g) $guid_by_code[$c]  = $g;
        if ($e && $g) $guid_by_email[$e] = $g;
    }

    // ── Pass 1: resolve GUIDs and collect parallel timesheet requests ────────
    $ts_requests = []; // guid => [endpoint, params]
    foreach ($entries as $row) {
        $ec = strtoupper($row['employeeCode'] ?? $row['employee_code'] ?? $row['empCode'] ?? $row['emp_code'] ?? $row['employeeId'] ?? $row['code'] ?? '');
        $em = strtolower($row['employeeEmail'] ?? $row['email'] ?? $row['emp_email'] ?? '');
        $g  = ($ec ? ($guid_by_code[$ec] ?? null) : null) ?? ($em ? ($guid_by_email[$em] ?? null) : null);
        if ($g && !isset($ts_requests[$g])) {
            $ts_requests[$g] = ['/api/timesheet_data', ['startTime' => $start_ms, 'endTime' => $end_ms, 'accountId' => $g]];
        }
    }

    // ── Single parallel batch for all employees on this day ──────────────────
    $ts_results = tl_get_multi($ts_requests, $api_key); // guid => raw API response

    // Pre-compute segment totals per GUID
    $seg_totals = []; // guid => [active, break, idle, meeting]
    foreach ($ts_results as $guid => $raw_ts) {
        if (isset($raw_ts['error'])) continue;
        $ts_entries = isset($raw_ts[0]) ? $raw_ts : ($raw_ts['data'] ?? []);
        $segments   = tl_build_timeline_segments($ts_entries, $start_ms, $end_ms, 5);
        $t = ['active' => 0.0, 'break' => 0.0, 'idle' => 0.0, 'meeting' => 0.0];
        foreach ($segments as $seg) {
            $type = $seg['type'] ?? 'active';
            $dur  = max(0, (float)($seg['end'] ?? 0) - (float)($seg['start'] ?? 0));
            if ($dur > 0 && isset($t[$type])) $t[$type] += $dur / 60;
        }
        $seg_totals[$guid] = $t;
    }

    try { $conn->query('SELECT 1'); } catch (PDOException $e) {} // keep connection alive after curl_multi
    $conn->prepare("DELETE FROM attendance WHERE date=? AND source='TEAMLOGGER'")->execute([$date]);
    $synced = $skipped = 0;

    // ── Pass 2: insert records using pre-fetched segment data ────────────────
    foreach ($entries as $row) {
        $emp_code = strtoupper(
            $row['employeeCode'] ?? $row['employee_code'] ?? $row['empCode'] ??
            $row['emp_code'] ?? $row['employeeId'] ?? $row['code'] ?? ''
        );
        $email   = $row['employeeEmail'] ?? $row['email'] ?? $row['emp_email'] ?? '';
        $tl_name = $row['employeeName']  ?? $row['name']  ?? $row['fullName'] ?? $row['username'] ?? '';

        $punch_in  = normalize_time(tl_field($row, [
            'punchInLocalTime','punchInGMT',
            'punchIn','punch_in','firstPunchIn','first_punch_in',
            'clockIn','clock_in','checkIn','check_in',
            'startTime','start_time','timeIn','time_in',
            'loginTime','login_time','login','inTime','in_time','firstSeen','first_seen',
        ]));
        $punch_out = normalize_time(tl_field($row, [
            'punchOutLocalTime','punchOutGMT',
            'punchOut','punch_out','lastPunchOut','last_punch_out',
            'clockOut','clock_out','checkOut','check_out',
            'endTime','end_time','timeOut','time_out',
            'logoutTime','logout_time','logout','outTime','out_time','lastSeen','last_seen',
        ]));
        $hours = tl_field($row, [
            'totalHours','total_hours','workedHours','worked_hours',
            'hoursWorked','hours_worked','duration','workDuration','work_duration',
            'timeWorked','time_worked','hours',
        ]);

        // Use breakHours directly from the punch report (most reliable source)
        $break_direct = isset($row['breakHours']) && (float)$row['breakHours'] > 0
            ? (float)$row['breakHours'] : null;

        $guid     = ($emp_code ? ($guid_by_code[$emp_code] ?? null) : null)
                 ?? ($email    ? ($guid_by_email[strtolower($email)] ?? null) : null);
        $totals   = $guid ? ($seg_totals[$guid] ?? null) : null;

        $idle_h    = $totals && $totals['idle']    > 0 ? (string)round($totals['idle'],    4) : null;
        $meeting_h = $totals && $totals['meeting'] > 0 ? (string)round($totals['meeting'], 4) : null;

        // Prefer direct breakHours from punch report over computed segments
        $break_h = $break_direct !== null
            ? (string)round($break_direct, 4)
            : ($totals && $totals['break'] > 0 ? (string)round($totals['break'], 4) : null);

        // Active = total - break - idle - meeting
        if ($hours !== null) {
            $active_h = (string)max(0, round(
                (float)$hours - (float)($break_h ?? 0) - (float)($idle_h ?? 0) - (float)($meeting_h ?? 0),
                4
            ));
        } else {
            $active_h = $totals && $totals['active'] > 0 ? (string)round($totals['active'], 4) : null;
        }

        if (!$emp_code && !$email && !$tl_name) { $skipped++; continue; }

        $usr = null;
        if ($emp_code) {
            $s = $conn->prepare("SELECT id FROM users WHERE UPPER(emp_no)=? LIMIT 1");
            $s->execute([$emp_code]); $usr = $s->fetchColumn() ?: null;
        }
        if (!$usr && $email) {
            $s = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $s->execute([$email]); $usr = $s->fetchColumn() ?: null;
        }

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
        try {
            $conn->prepare("INSERT INTO attendance
                (user_id,date,check_in,check_out,total_hours,break_hours,idle_hours,active_hours,meeting_hours,status,source,tl_employee_code,tl_employee_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,'TEAMLOGGER',?,?)
                ON DUPLICATE KEY UPDATE
                  check_in=VALUES(check_in), check_out=VALUES(check_out),
                  total_hours=VALUES(total_hours), break_hours=VALUES(break_hours),
                  idle_hours=VALUES(idle_hours), active_hours=VALUES(active_hours),
                  meeting_hours=VALUES(meeting_hours), status=VALUES(status),
                  tl_employee_code=VALUES(tl_employee_code), tl_employee_name=VALUES(tl_employee_name)")
                ->execute([$usr ?: null, $date, $punch_in, $punch_out,
                           $hours, $break_h, $idle_h, $active_h, $meeting_h,
                           $status, $emp_code, $tl_name]);
            $synced++;
            // Award attendance points
            if ($usr) {
                $empRow = $conn->prepare("SELECT id FROM employees WHERE email = (SELECT email FROM users WHERE id = ? LIMIT 1) LIMIT 1");
                $empRow->execute([$usr]);
                $empId = (int)($empRow->fetchColumn() ?: 0);
                if ($empId) {
                    $attId = (string)$conn->lastInsertId();
                    if ($status === 'PRESENT' || $status === 'LATE') {
                        if ($punch_in) {
                            $pi_h = (int)date('H', strtotime($punch_in)) + (int)date('i', strtotime($punch_in)) / 60;
                            if ($pi_h <= 9.5) {
                                pts_award($conn, $empId, 'attendance_ontime_morning', $attId, 'attendance', 'On-time check-in');
                            }
                        }
                        if ($punch_out) {
                            pts_award($conn, $empId, 'attendance_ontime_evening', $attId, 'attendance', 'Checked out');
                        }
                    }
                    // Full week check (Mon–Fri no absences)
                    $dow = date('N', strtotime($date));
                    if ($dow == 5) { // Friday — check Mon-Fri
                        $mon = date('Y-m-d', strtotime('monday this week', strtotime($date)));
                        $s2  = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE user_id=? AND date BETWEEN ? AND ? AND source='TEAMLOGGER' AND status NOT IN ('ABSENT','HALF_DAY')");
                        $s2->execute([$usr, $mon, $date]);
                        if ((int)$s2->fetchColumn() >= 5) {
                            pts_award($conn, $empId, 'attendance_full_week', "week_{$mon}", 'attendance', 'Perfect week');
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback without new columns if migration hasn't run yet
            try {
                $conn->prepare("INSERT INTO attendance
                    (user_id,date,check_in,check_out,total_hours,status,source,tl_employee_code,tl_employee_name)
                    VALUES (?,?,?,?,?,?,'TEAMLOGGER',?,?)
                    ON DUPLICATE KEY UPDATE
                      check_in=VALUES(check_in), check_out=VALUES(check_out),
                      total_hours=VALUES(total_hours), status=VALUES(status),
                      tl_employee_code=VALUES(tl_employee_code), tl_employee_name=VALUES(tl_employee_name)")
                    ->execute([$usr ?: null, $date, $punch_in, $punch_out, $hours, $status, $emp_code, $tl_name]);
                $synced++;
            } catch (Exception $e2) { $skipped++; }
        }
    }
    return ['synced' => $synced, 'skipped' => $skipped, 'error' => null];
}

// ── Helper: export attendance CSV from DB (matrix: rows=employees, cols=dates×fields) ─
function export_attendance_csv(PDO $conn, string $from, string $to): void {
    $s = $conn->prepare("
        SELECT a.date,
               COALESCE(u.name, a.tl_employee_name, a.notes, a.tl_employee_code) as employee_name,
               COALESCE(a.tl_employee_code, u.emp_no) as emp_code,
               u.email as emp_email,
               a.check_in, a.check_out,
               a.total_hours, a.active_hours, a.break_hours, a.idle_hours, a.meeting_hours
        FROM attendance a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.date BETWEEN ? AND ?
        ORDER BY employee_name ASC, a.date ASC
    ");
    $s->execute([$from, $to]);
    $rows = $s->fetchAll();

    // Build full date range
    $dates = [];
    $cur = new DateTime($from);
    $end = new DateTime($to);
    while ($cur <= $end) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }

    $fmt_h = fn($v) => fmt_dur($v);

    // Index records: employee_key => [meta, date => per-metric values]
    $employees = []; // key => [name, code, email]
    $matrix    = []; // key => date => [punch_in, punch_out, total, active, break, idle, meeting]
    foreach ($rows as $r) {
        $key = ($r['employee_name'] ?? '') . '|' . ($r['emp_code'] ?? '');
        if (!isset($employees[$key])) {
            $employees[$key] = [
                $r['employee_name'] ?? '—',
                $r['emp_code']      ?? '—',
                $r['emp_email']     ?? '—',
            ];
        }
        $matrix[$key][$r['date']] = [
            'Punch In'      => $r['check_in']  ? date('h:i A', strtotime($r['check_in']))  : '—',
            'Punch Out'     => $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '—',
            'Total'         => $fmt_h($r['total_hours']),
            'Active'        => $fmt_h($r['active_hours']),
            'Break'         => $fmt_h($r['break_hours']),
            'Idle'          => $fmt_h($r['idle_hours']),
            'Meeting'       => $fmt_h($r['meeting_hours']),
        ];
    }

    $metrics     = ['Punch In', 'Punch Out', 'Total', 'Active', 'Break', 'Idle', 'Meeting'];
    $date_labels = array_map(fn($d) => date('d-M-Y', strtotime($d)), $dates);

    // ── Column letter helper ──────────────────────────────────
    $col_letter = function(int $n): string {
        $s = '';
        for ($n++; $n > 0; $n = intdiv($n, 26)) { $s = chr(65 + (--$n % 26)) . $s; }
        return $s;
    };
    $xc   = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $cell = fn(int $r, int $c, string $v, int $s): string =>
        '<c r="' . $col_letter($c) . $r . '" s="' . $s . '" t="inlineStr"><is><t>' . $xc($v) . '</t></is></c>';

    // Style indices: 0=plain 1=empName 2=empLabel 3=tblHead 4=tblMetric 5=tblCell
    $S_PLAIN = 0; $S_NAME = 1; $S_LABEL = 2; $S_HEAD = 3; $S_METRIC = 4; $S_CELL = 5;

    // ── Build sheet rows ──────────────────────────────────────
    $sheet_rows = '';
    $rn = 1;
    foreach ($employees as $key => [$name, $code, $email]) {
        $sheet_rows .= '<row r="'.$rn.'">'.$cell($rn,0,'Employee Name',$S_LABEL).$cell($rn,1,$name,$S_NAME).'</row>'; $rn++;
        $sheet_rows .= '<row r="'.$rn.'">'.$cell($rn,0,'Emp Code',$S_LABEL).$cell($rn,1,$code,$S_PLAIN).'</row>'; $rn++;
        $sheet_rows .= '<row r="'.$rn.'">'.$cell($rn,0,'Email',$S_LABEL).$cell($rn,1,$email,$S_PLAIN).'</row>'; $rn++;
        $sheet_rows .= '<row r="'.$rn.'"></row>'; $rn++;

        // Header row
        $hdr = $cell($rn, 0, 'Metric', $S_HEAD);
        foreach ($date_labels as $ci => $lbl) { $hdr .= $cell($rn, $ci + 1, $lbl, $S_HEAD); }
        $sheet_rows .= '<row r="'.$rn.'">'.$hdr.'</row>'; $rn++;

        // Metric rows
        foreach ($metrics as $metric) {
            $r = $cell($rn, 0, $metric, $S_METRIC);
            foreach ($dates as $ci => $d) { $r .= $cell($rn, $ci + 1, $matrix[$key][$d][$metric] ?? '—', $S_CELL); }
            $sheet_rows .= '<row r="'.$rn.'">'.$r.'</row>'; $rn++;
        }
        $sheet_rows .= '<row r="'.$rn.'"></row>'; $rn++;
        $sheet_rows .= '<row r="'.$rn.'"></row>'; $rn++;
    }

    // ── XLSX XML parts ────────────────────────────────────────
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
 <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
 <Default Extension="xml" ContentType="application/xml"/>
 <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
 <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
 <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

    $pkg_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
 <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
 <sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
 <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
 <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
 <fonts count="4">
  <font><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="13"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><color rgb="FF1E3A5F"/><name val="Calibri"/></font>
 </fonts>
 <fills count="4">
  <fill><patternFill patternType="none"/></fill>
  <fill><patternFill patternType="gray125"/></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
 </fills>
 <borders count="2">
  <border><left/><right/><top/><bottom/><diagonal/></border>
  <border>
   <left style="thin"><color rgb="FF94A3B8"/></left>
   <right style="thin"><color rgb="FF94A3B8"/></right>
   <top style="thin"><color rgb="FF94A3B8"/></top>
   <bottom style="thin"><color rgb="FF94A3B8"/></bottom>
   <diagonal/>
  </border>
 </borders>
 <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
 <cellXfs count="6">
  <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0"><alignment horizontal="center"/></xf>
  <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0"/>
  <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center"/></xf>
 </cellXfs>
 <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
 <sheetData>' . $sheet_rows . '</sheetData>
</worksheet>';

    // ── Write ZIP (XLSX) ──────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',        $content_types);
    $zip->addFromString('_rels/.rels',                $pkg_rels);
    $zip->addFromString('xl/workbook.xml',            $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
    $zip->addFromString('xl/styles.xml',              $styles_xml);
    $zip->addFromString('xl/worksheets/sheet1.xml',   $sheet_xml);
    $zip->close();

    $fname = 'attendance_' . $from . ($from !== $to ? '_to_' . $to : '') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Pragma: no-cache');
    header('Set-Cookie: tl_dl=1; path=/; SameSite=Lax');
    readfile($tmp);
    unlink($tmp);
}

// ── Settings ──────────────────────────────────────────────
$api_key   = get_setting($conn, 'tl_api_key');
$tz        = get_setting($conn, 'tl_timezone_offset', '330');
$day_start = get_setting($conn, 'tl_day_start', '9.5');
$day_end   = get_setting($conn, 'tl_day_end', '18.5');
$tab       = $_GET['tab'] ?? ($api_key ? 'attendance' : 'settings');
$att_date  = $_GET['date'] ?? date('Y-m-d');
$view      = $_GET['view'] ?? 'cards'; // 'cards' or 'table'

// ── POST HANDLERS ─────────────────────────────────────────
$invite_link = null;
$invite_name = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_settings') {
        save_setting($conn, 'tl_api_key',         trim($_POST['tl_api_key']));
        save_setting($conn, 'tl_timezone_offset', trim($_POST['tl_timezone_offset'] ?? '330'));
        save_setting($conn, 'tl_day_start',       trim($_POST['tl_day_start'] ?? '9.5'));
        save_setting($conn, 'tl_day_end',         trim($_POST['tl_day_end'] ?? '18.5'));
        set_flash('success', 'TeamLogger settings saved.');
        header("Location: teamlogger.php?tab=settings"); exit;
    }

    if ($_POST['action'] === 'map_user') {
        $hrms_uid = (int)$_POST['hrms_user_id'];
        $tl_guid  = trim($_POST['tl_guid']);
        $tl_code  = trim($_POST['tl_emp_code']);
        $tl_name  = trim($_POST['tl_name'] ?? '');
        $tl_email = trim($_POST['tl_email'] ?? '');
        $conn->prepare("UPDATE users SET tl_guid=?, emp_no=? WHERE id=?")->execute([$tl_guid, $tl_code, $hrms_uid]);

        $s = $conn->prepare("SELECT * FROM users WHERE id=?"); $s->execute([$hrms_uid]);
        $mapped_user = $s->fetch();
        if ($mapped_user) {
            $emp_email = $mapped_user['email'] ?: $tl_email;
            $emp_name  = $mapped_user['name']  ?: $tl_name;
            $chk = $conn->prepare("SELECT id FROM employees WHERE email=? OR emp_code=? LIMIT 1");
            $chk->execute([$emp_email, $tl_code]);
            if (!$chk->fetch()) {
                $conn->prepare("INSERT INTO employees (emp_code,name,email,status) VALUES (?,?,?,'ACTIVE')")
                     ->execute([$tl_code, $emp_name, $emp_email]);
            } else {
                $conn->prepare("UPDATE employees SET emp_code=? WHERE email=?")->execute([$tl_code, $emp_email]);
            }
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $conn->prepare("UPDATE users SET invite_token=?, invite_expires=? WHERE id=?")->execute([$token, $expires, $hrms_uid]);
            $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
            $invite_link = $base . 'invite.php?token=' . $token;
            $invite_name = $mapped_user['name'];
        }
    }

    if ($_POST['action'] === 'create_and_map') {
        $name    = trim($_POST['new_name']);
        $email   = trim($_POST['new_email']);
        $role    = $_POST['new_role'];
        $tl_guid = trim($_POST['tl_guid']);
        $tl_code = trim($_POST['tl_emp_code']);
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $tmp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        $chk = $conn->prepare("SELECT id FROM users WHERE email=?"); $chk->execute([$email]);
        if ($chk->fetch()) {
            set_flash('danger', 'A user with this email already exists.');
            header("Location: teamlogger.php?tab=employees"); exit;
        }
        $conn->prepare("INSERT INTO users (name,email,password,role,emp_no,tl_guid,invite_token,invite_expires,invite_status) VALUES (?,?,?,?,?,?,?,?,'PENDING')")
             ->execute([$name, $email, $tmp_pass, $role, $tl_code, $tl_guid, $token, $expires]);
        $chk2 = $conn->prepare("SELECT id FROM employees WHERE email=? OR emp_code=? LIMIT 1");
        $chk2->execute([$email, $tl_code]);
        if (!$chk2->fetch()) {
            $conn->prepare("INSERT INTO employees (emp_code,name,email,status) VALUES (?,?,?,'ACTIVE')")
                 ->execute([$tl_code, $name, $email]);
        }
        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
        $invite_link = $base . 'invite.php?token=' . $token;
        $invite_name = $name;
    }

    // Single-day sync
    if ($_POST['action'] === 'sync_attendance') {
        $date = $_POST['sync_date'] ?? date('Y-m-d');
        $_SESSION['last_tl_sync_' . $date] = time(); // stamp before sync — prevents retry loop on crash
        try { $conn->exec("SET SESSION wait_timeout=86400"); } catch (Exception $e) {}
        $r = do_sync_attendance($conn, $date, $api_key, $tz, $day_start, $day_end);
        if ($r['error']) set_flash('danger', 'Sync failed: ' . $r['error']);
        else set_flash('success', "Synced {$r['synced']} records for $date.");
        header("Location: teamlogger.php?tab=attendance&date=$date&synced=1"); exit;
    }

    // Single-day export (sync first, then export from DB)
    if ($_POST['action'] === 'export_attendance') {
        $from = $_POST['export_from'] ?? $att_date;
        $to   = $_POST['export_to']   ?? $from;
        if ($from > $to) [$from, $to] = [$to, $from];
        // Sync any missing days before export
        if ($api_key) {
            set_time_limit(0);
            $d = new DateTime($from); $end = new DateTime($to);
            while ($d <= $end) {
                $date = $d->format('Y-m-d');
                do_sync_attendance($conn, $date, $api_key, $tz, $day_start, $day_end);
                $d->modify('+1 day');
            }
        }
        export_attendance_csv($conn, $from, $to);
        exit;
    }

    // Export only — no sync, just dump what's already in the DB
    if ($_POST['action'] === 'export_only') {
        $from = $_POST['range_from'] ?? $_POST['export_from'] ?? $att_date;
        $to   = $_POST['range_to']   ?? $_POST['export_to']   ?? $from;
        if ($from > $to) [$from, $to] = [$to, $from];
        export_attendance_csv($conn, $from, $to);
        exit;
    }
}

// ── HRMS users for mapping ────────────────────────────────
$hrms_users = $conn->query("
    SELECT u.id, u.name, u.email, u.emp_no, u.role, u.tl_guid, u.invite_status,
           d.name as dept_name, d.id as dept_id
    FROM users u
    LEFT JOIN employees e ON LOWER(e.email)=LOWER(u.email)
    LEFT JOIN departments d ON d.id=e.dept_id
    ORDER BY u.name
")->fetchAll();

$hrms_by_empno = [];
$hrms_by_email = [];
foreach ($hrms_users as $hu) {
    if ($hu['emp_no']) $hrms_by_empno[strtoupper(trim($hu['emp_no']))] = $hu;
    if ($hu['email'])  $hrms_by_email[strtolower(trim($hu['email']))]  = $hu;
}

$depts = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$roles = ['SUPER_ADMIN', 'HR_ADMIN', 'DEPT_MANAGER', 'TEAM_LEAD', 'INTERVIEW_PANEL', 'EMPLOYEE'];

// ── TL employees tab ──────────────────────────────────────
$tl_users = []; $tl_error = null;
if ($api_key && $tab === 'employees') {
    $res = tl_get('/api/integration/list_users', $api_key);
    if (isset($res['error'])) $tl_error = $res['error'];
    else $tl_users = isset($res[0]) ? $res : ($res['users'] ?? $res['data'] ?? $res['employees'] ?? []);
}

// ── Live tab ──────────────────────────────────────────────
$recent_tasks = [];
if ($api_key && $tab === 'live') {
    $res = tl_get('/api/integration/most_recent_tasks', $api_key);
    if (!isset($res['error'])) $recent_tasks = isset($res[0]) ? $res : ($res['data'] ?? []);
}

// ── DB attendance for selected date ──────────────────────
$db_rows = [];
$has_db_records = false;
if ($tab === 'attendance') {
    $cnt = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date=? AND source='TEAMLOGGER'");
    $cnt->execute([$att_date]);
    $has_db_records = (bool)$cnt->fetchColumn();

    $s = $conn->prepare("
        SELECT a.*, u.name as user_name, u.emp_no, u.tl_guid, u.email as user_email
        FROM attendance a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.date = ?
        ORDER BY COALESCE(a.check_in,'99:99') ASC
    ");
    $s->execute([$att_date]);
    $db_rows = $s->fetchAll();
}

// Build TL guid map and timesheet cache for timeline
$tl_guid_by_code = [];
$tl_guid_by_email = [];
$timesheet_cache = [];
$gap_threshold_min = 5;
if ($api_key && $tab === 'attendance' && $db_rows) {
    $raw_users = tl_get('/api/integration/list_users', $api_key);
    $tl_users = isset($raw_users[0]) ? $raw_users : ($raw_users['users'] ?? $raw_users['data'] ?? []);
    foreach ($tl_users as $tu) {
        $code = strtoupper(trim(tl_emp_code($tu)));
        $mail = strtolower(trim(tl_emp_email($tu)));
        $guid = tl_emp_guid($tu);
        if ($code && $guid) $tl_guid_by_code[$code] = $guid;
        if ($mail && $guid) $tl_guid_by_email[$mail] = $guid;
    }
}

// Auto-sync only when: today's date, zero records, and never attempted this browser session.
$last_auto_sync  = $_SESSION['last_tl_sync_' . $att_date] ?? 0;
$needs_auto_sync = $api_key
    && $tab === 'attendance'
    && !isset($_GET['synced'])
    && $att_date === date('Y-m-d')
    && !$has_db_records
    && $last_auto_sync === 0;

// ── Stats ──────────────────────────────────────────────────
$present    = count(array_filter($db_rows, fn($r) => $r['status'] === 'PRESENT'));
$absent     = count(array_filter($db_rows, fn($r) => $r['status'] === 'ABSENT'));
$half       = count(array_filter($db_rows, fn($r) => $r['status'] === 'HALF_DAY'));
$late       = count(array_filter($db_rows, fn($r) => $r['status'] === 'LATE'));
$total_break = array_sum(array_map(fn($r) => (float)($r['break_hours'] ?? 0), $db_rows));

include 'header.php';
?>

<!-- ── PRELOADER ─────────────────────────────────────────── -->
<div id="pageLoader" style="position:fixed;top:0;left:0;width:100%;height:100%;
     background:rgba(255,255,255,0.93);z-index:9999;display:none;flex-direction:column;
     align-items:center;justify-content:center;backdrop-filter:blur(3px);transition:opacity 0.4s;">
    <div class="position-relative mb-3">
        <div class="spinner-border text-primary" style="width:3.2rem;height:3.2rem;" role="status"></div>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
            <i class="bi bi-clock-history text-primary" style="font-size:1rem;"></i>
        </div>
    </div>
    <div class="fw-semibold text-dark fs-6" id="loaderMsg">Loading TeamLogger...</div>
    <div class="text-muted small mt-1" id="loaderSub">Fetching punch data</div>
    <div class="mt-3">
        <div class="progress" style="width:180px;height:4px;border-radius:4px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary w-100"></div>
        </div>
    </div>
</div>

<!-- ── INVITE LINK MODAL (after map/create) ──────────────── -->
<?php if ($invite_link): ?>
<div class="modal fade" id="inviteLinkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-link-45deg me-2 text-primary"></i>Invite Link Ready</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success small py-2 mb-3">
                    <i class="bi bi-check-circle me-1"></i>
                    <strong><?= sanitize($invite_name) ?></strong> mapped. Share this link — valid 48 hours.
                </div>
                <div class="input-group">
                    <input type="text" id="inviteLinkInput" class="form-control form-control-sm font-monospace"
                           value="<?= sanitize($invite_link) ?>" readonly>
                    <button class="btn btn-primary btn-sm" onclick="copyLink()">
                        <i class="bi bi-clipboard" id="copyIconModal"></i>
                    </button>
                </div>
                <div id="copyMsgModal" class="text-success small mt-2 d-none">
                    <i class="bi bi-check-circle me-1"></i>Copied!
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── HEADER ─────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-0">TeamLogger Integration</h5>
        <div class="text-muted small">
            <?= $api_key
                ? '<span class="text-success"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Connected · Office 9:30 AM – 6:30 PM IST</span>'
                : '<span class="text-danger"><i class="bi bi-circle me-1" style="font-size:.5rem"></i>Not configured — go to Settings</span>' ?>
        </div>
    </div>
</div>

<!-- ── TABS ───────────────────────────────────────────────── -->
<ul class="nav nav-pills gap-2 mb-4">
    <li><a class="nav-link rounded-pill <?= $tab==='attendance'?'active':'' ?>" href="?tab=attendance">
        <i class="bi bi-clock me-1"></i>Attendance</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='employees'?'active':'' ?>" href="?tab=employees">
        <i class="bi bi-people me-1"></i>Employees<?php if($tl_users): ?> <span class="badge bg-white text-dark ms-1"><?=count($tl_users)?></span><?php endif; ?></a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='live'?'active':'' ?>" href="?tab=live">
        <i class="bi bi-activity me-1"></i>Live Activity</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='settings'?'active':'' ?>" href="?tab=settings">
        <i class="bi bi-gear me-1"></i>Settings</a></li>
</ul>

<?php if (!$api_key && $tab !== 'settings'): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>API key not configured. <a href="?tab=settings" class="alert-link">Go to Settings</a>.</div>
<?php endif; ?>

<!-- ══ ATTENDANCE TAB ════════════════════════════════════ -->
<?php if ($tab === 'attendance'): ?>

<!-- Auto-sync hidden form -->
<?php if ($needs_auto_sync): ?>
<form id="autoSyncForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="sync_attendance">
    <input type="hidden" name="sync_date" value="<?= sanitize($att_date) ?>">
</form>
<?php endif; ?>

<!-- Toolbar -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <input type="date" class="form-control form-control-sm" value="<?= $att_date ?>" style="width:160px;"
               onchange="showLoader('Loading attendance...','Switching date');location.href='?tab=attendance&view=<?=$view?>&date='+this.value">
        <span class="text-muted small fw-semibold"><?= date('l, d M Y', strtotime($att_date)) ?></span>
        <?php if ($has_db_records): ?>
        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.65rem;">
            <i class="bi bi-database me-1"></i>Synced
        </span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <!-- Sync button -->
        <form method="POST" onsubmit="showLoader('Syncing attendance...','Fetching punch data from TeamLogger')">
            <input type="hidden" name="action" value="sync_attendance">
            <input type="hidden" name="sync_date" value="<?= $att_date ?>">
            <button class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Selected Date
            </button>
        </form>
        <!-- Range Sync & Export -->
        <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#rangeModal">
            <i class="bi bi-calendar-range me-1"></i>Sync & Export Range
        </button>
        <!-- View toggle -->
        <div class="btn-group btn-group-sm" role="group">
            <a href="?tab=attendance&date=<?=$att_date?>&view=cards<?= isset($_GET['synced']) ? '&synced=1' : '' ?>"
               class="btn <?= $view==='cards' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="bi bi-grid-3x3-gap"></i> Cards
            </a>
            <a href="?tab=attendance&date=<?=$att_date?>&view=table<?= isset($_GET['synced']) ? '&synced=1' : '' ?>"
               class="btn <?= $view==='table' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="bi bi-table"></i> Table
            </a>
        </div>
        <!-- Quick export (today) -->
        <form method="POST" onsubmit="showExportLoader('Preparing export...','Syncing & building CSV')">
            <input type="hidden" name="action" value="export_attendance">
            <input type="hidden" name="export_from" value="<?= $att_date ?>">
            <input type="hidden" name="export_to"   value="<?= $att_date ?>">
            <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </button>
        </form>
        <!-- Export only (no sync) — opens range modal -->
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#rangeModal"
                title="Export a date range from already-synced data without re-fetching">
            <i class="bi bi-download me-1"></i>Export Only
        </button>
    </div>
</div>

<!-- Stats -->
<?php if ($db_rows): ?>
<div class="row g-3 mb-4">
    <?php foreach([
        ['Present',  $present,                    '#22c55e', 'bi-person-check-fill', false],
        ['Late',     $late,                        '#3b82f6', 'bi-clock-fill',        false],
        ['Half Day', $half,                        '#f59e0b', 'bi-sun-fill',          false],
        ['Absent',   $absent,                      '#ef4444', 'bi-person-x-fill',     false],
        ['Total Break', fmt_dur($total_break), '#8b5cf6', 'bi-hourglass-split', true],
    ] as [$lbl,$val,$clr,$ico,$is_hours]): ?>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm p-3 text-center" style="border-radius:12px;">
            <div><i class="<?=$ico?>" style="color:<?=$clr?>;font-size:1.1rem;"></i></div>
            <div class="fw-bold mt-1" style="font-size:<?=$is_hours?'1.4':'2'?>rem;color:<?=$clr?>;line-height:1.1;"><?= $val ?></div>
            <div class="text-muted small mt-1"><?= $lbl ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- No data -->
<?php if (!$db_rows && !$needs_auto_sync): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px;">
    <i class="bi bi-calendar-x text-muted" style="font-size:2.5rem;"></i>
    <p class="text-muted mt-3 mb-2">No attendance data for this date.</p>
    <p class="text-muted small mb-3">Press <strong>Sync Selected Date</strong> to fetch TeamLogger data.</p>
    <form method="POST" class="d-inline" onsubmit="showLoader('Syncing...','Fetching from TeamLogger')">
        <input type="hidden" name="action" value="sync_attendance">
        <input type="hidden" name="sync_date" value="<?= $att_date ?>">
        <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Sync from TeamLogger</button>
    </form>
</div>
<?php endif; ?>

<!-- ── TABLE VIEW ─────────────────────────────────────── -->
<?php if ($db_rows && $view === 'table'): ?>
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Employee</th>
                        <th>Emp Code</th>
                        <th>Status</th>
                        <th>Punch In</th>
                        <th>Last Punch Out</th>
                        <th>Total</th>
                        <th>Active</th>
                        <th>Break</th>
                        <th>Idle</th>
                        <th>Meeting</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sc = ['PRESENT'=>'success','LATE'=>'primary','HALF_DAY'=>'warning','ABSENT'=>'danger'];
                foreach ($db_rows as $row):
                    $name   = $row['user_name'] ?? $row['tl_employee_name'] ?? $row['notes'] ?? '—';
                    $code   = $row['tl_employee_code'] ?? $row['emp_no'] ?? '—';
                    $ci     = $row['check_in'];
                    $co     = $row['check_out'];
                    $hrs    = $row['total_hours'];
                    $tact   = $row['active_hours']  ?? null;
                    $tbrk   = $row['break_hours']   ?? null;
                    $tidl   = $row['idle_hours']    ?? null;
                    $tmtg   = $row['meeting_hours'] ?? null;
                    $st     = $row['status'];
                    $badgeC = $sc[$st] ?? 'secondary';

                    $timeline_totals = ['active' => 0.0, 'break' => 0.0, 'idle' => 0.0, 'meeting' => 0.0];
                    if ($api_key) {
                        $code_key = strtoupper(trim($code));
                        $email_key = strtolower(trim($row['user_email'] ?? ''));
                        $guid = $row['tl_guid'] ?? ($code_key ? ($tl_guid_by_code[$code_key] ?? null) : null) ?? ($email_key ? ($tl_guid_by_email[$email_key] ?? null) : null);
                        if ($guid) {
                            if (!isset($timesheet_cache[$guid])) {
                                [$start_ms, $end_ms] = tl_day_bounds_ms($att_date, $tz);
                                $raw_ts = tl_get('/api/timesheet_data', $api_key, [
                                    'startTime' => $start_ms,
                                    'endTime'   => $end_ms,
                                    'accountId' => $guid,
                                ]);
                                $entries = isset($raw_ts[0]) ? $raw_ts : ($raw_ts['data'] ?? []);
                                $timesheet_cache[$guid] = tl_build_timeline_segments($entries, $start_ms, $end_ms, $gap_threshold_min);
                            }
                            $timeline_segments = $timesheet_cache[$guid] ?? [];
                            if ($timeline_segments) {
                                foreach ($timeline_segments as $seg) {
                                    $type = $seg['type'] ?? 'active';
                                    $dur_min = max(0, (float)($seg['end'] ?? 0) - (float)($seg['start'] ?? 0));
                                    if ($dur_min > 0 && isset($timeline_totals[$type])) {
                                        $timeline_totals[$type] += $dur_min / 60;
                                    }
                                }
                            }
                        }
                    }

                    if ($timeline_totals['active'] || $timeline_totals['break'] || $timeline_totals['idle'] || $timeline_totals['meeting']) {
                        if ($tact === null && $timeline_totals['active'] > 0) $tact = $timeline_totals['active'];
                        if ($tbrk === null && $timeline_totals['break'] > 0) $tbrk = $timeline_totals['break'];
                        if ($tidl === null && $timeline_totals['idle'] > 0) $tidl = $timeline_totals['idle'];
                        if ($tmtg === null && $timeline_totals['meeting'] > 0) $tmtg = $timeline_totals['meeting'];
                    }
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;">
                                <?= strtoupper(substr($name,0,2)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?= sanitize($name) ?></div>
                                <?php if (!$row['user_id']): ?>
                                <span class="badge bg-warning text-dark" style="font-size:.6rem;">Not mapped</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><code class="small text-primary"><?= sanitize($code) ?></code></td>
                    <td><span class="badge bg-<?=$badgeC?> rounded-pill"><?= $st ?></span></td>
                    <td>
                        <?php if ($ci): ?>
                        <span class="fw-semibold text-success"><?= date('h:i A', strtotime($ci)) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($co): ?>
                        <span class="fw-semibold text-danger"><?= date('h:i A', strtotime($co)) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $hrs  !== null ? '<span class="fw-semibold text-indigo">'.fmt_dur($hrs).'</span>'  : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $tact !== null ? '<span class="fw-semibold text-success">'.fmt_dur($tact).'</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $tbrk !== null ? '<span class="fw-semibold text-warning">'.fmt_dur($tbrk).'</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $tidl !== null ? '<span class="fw-semibold text-secondary">'.fmt_dur($tidl).'</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $tmtg !== null ? '<span class="fw-semibold text-primary">'.fmt_dur($tmtg).'</span>' : '<span class="text-muted">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-muted small border-top">
            <?= count($db_rows) ?> record(s) &middot;
            <?= $present ?> present &middot; <?= $late ?> late &middot; <?= $half ?> half day &middot; <?= $absent ?> absent
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── PUNCH CARDS ─────────────────────────────────────── -->
<?php if ($db_rows && $view === 'cards'): ?>
<div class="row g-3">
<?php
$day_s = 8 * 60;   // 8:00 AM in minutes
$day_e = 20 * 60;  // 8:00 PM in minutes
$day_span = $day_e - $day_s;

foreach ($db_rows as $row):
    $name     = $row['user_name'] ?? $row['tl_employee_name'] ?? $row['notes'] ?? '—';
    $code     = $row['tl_employee_code'] ?? $row['emp_no'] ?? '—';
    $status   = $row['status'];
    $ci       = $row['check_in'];
    $co       = $row['check_out'];
    $hrs      = $row['total_hours'];
    $brk      = $row['break_hours']   ?? null;
    $idl      = $row['idle_hours']    ?? null;
    $act      = $row['active_hours']  ?? null;
    $mtg      = $row['meeting_hours'] ?? null;
    $unmapped = !$row['user_id'];

    $timeline_segments = [];
    $timeline_totals = ['active' => 0.0, 'break' => 0.0, 'idle' => 0.0, 'meeting' => 0.0];
    if ($api_key) {
        $code_key = strtoupper(trim($code));
        $email_key = strtolower(trim($row['user_email'] ?? ''));
        $guid = $row['tl_guid'] ?? ($code_key ? ($tl_guid_by_code[$code_key] ?? null) : null) ?? ($email_key ? ($tl_guid_by_email[$email_key] ?? null) : null);
        if ($guid) {
            if (!isset($timesheet_cache[$guid])) {
                [$start_ms, $end_ms] = tl_day_bounds_ms($att_date, $tz);
                $raw_ts = tl_get('/api/timesheet_data', $api_key, [
                    'startTime' => $start_ms,
                    'endTime'   => $end_ms,
                    'accountId' => $guid,
                ]);
                $entries = isset($raw_ts[0]) ? $raw_ts : ($raw_ts['data'] ?? []);
                $timesheet_cache[$guid] = tl_build_timeline_segments($entries, $start_ms, $end_ms, $gap_threshold_min);
            }
            $timeline_segments = $timesheet_cache[$guid] ?? [];
            if ($timeline_segments) {
                foreach ($timeline_segments as $seg) {
                    $type = $seg['type'] ?? 'active';
                    $dur_min = max(0, (float)($seg['end'] ?? 0) - (float)($seg['start'] ?? 0));
                    if ($dur_min > 0 && isset($timeline_totals[$type])) {
                        $timeline_totals[$type] += $dur_min / 60;
                    }
                }
            }
        }
    }

    if ($timeline_totals['active'] || $timeline_totals['break'] || $timeline_totals['idle'] || $timeline_totals['meeting']) {
        if ($act === null && $timeline_totals['active'] > 0) $act = $timeline_totals['active'];
        if ($brk === null && $timeline_totals['break'] > 0) $brk = $timeline_totals['break'];
        if ($idl === null && $timeline_totals['idle'] > 0) $idl = $timeline_totals['idle'];
        if ($mtg === null && $timeline_totals['meeting'] > 0) $mtg = $timeline_totals['meeting'];
    }

    // Timeline percentages
    $in_pct = $out_pct = $dur_pct = null;
    if ($ci) {
        $in_min = (int)date('H', strtotime($ci)) * 60 + (int)date('i', strtotime($ci));
        $in_pct = max(0, min(100, ($in_min - $day_s) / $day_span * 100));
    }
    if ($co) {
        $out_min = (int)date('H', strtotime($co)) * 60 + (int)date('i', strtotime($co));
        $out_pct = max(0, min(100, ($out_min - $day_s) / $day_span * 100));
    }
    if ($in_pct !== null && $out_pct !== null) {
        $dur_pct = max(2, $out_pct - $in_pct);
    }

    // Proportional colour segments within the worked window
    $seg_active = $seg_break = $seg_idle = 0;
    if ($dur_pct !== null && (float)($hrs ?? 0) > 0) {
        $r = $dur_pct / (float)$hrs;
        $seg_active = round((float)($act ?? 0) * $r, 2);
        $seg_break  = round((float)($brk ?? 0) * $r, 2);
        $seg_idle   = round((float)($idl ?? 0) * $r, 2);
    }

    $status_colors = ['PRESENT'=>'#22c55e','LATE'=>'#3b82f6','HALF_DAY'=>'#f59e0b','ABSENT'=>'#ef4444'];
    $sclr = $status_colors[$status] ?? '#6b7280';
    $initials = strtoupper(substr($name, 0, 2));
?>
<div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;border-top:3px solid <?=$sclr?> !important;">
        <!-- Card header -->
        <div class="card-body pb-2">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.85rem;flex-shrink:0;">
                    <?= $initials ?>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate"><?= sanitize($name) ?></div>
                    <code class="text-muted" style="font-size:.7rem;"><?= sanitize($code) ?></code>
                    <?php if ($unmapped): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Not mapped</span>
                    <?php endif; ?>
                </div>
                <span class="badge rounded-pill" style="background:<?=$sclr?>20;color:<?=$sclr?>;font-size:.65rem;white-space:nowrap;">
                    <?= $status ?>
                </span>
            </div>

            <!-- Punch times -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <div class="rounded-3 text-center p-2" style="background:#f0fdf4;">
                        <div class="text-muted mb-1" style="font-size:.65rem;letter-spacing:.05em;">PUNCH IN</div>
                        <div class="fw-bold" style="font-size:1.1rem;color:#16a34a;">
                            <?= $ci ? date('h:i', strtotime($ci)) : '—' ?>
                        </div>
                        <?php if ($ci): ?>
                        <div class="text-muted" style="font-size:.7rem;"><?= date('A', strtotime($ci)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6">
                    <div class="rounded-3 text-center p-2" style="background:#fff5f5;">
                        <div class="text-muted mb-1" style="font-size:.65rem;letter-spacing:.05em;">LAST OUT</div>
                        <div class="fw-bold" style="font-size:1.1rem;color:#dc2626;">
                            <?= $co ? date('h:i', strtotime($co)) : '—' ?>
                        </div>
                        <?php if ($co): ?>
                        <div class="text-muted" style="font-size:.7rem;"><?= date('A', strtotime($co)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Timeline bar -->
            <div class="mb-2">
                <div class="d-flex justify-content-between text-muted mb-1" style="font-size:.6rem;">
                    <span>8 AM</span><span>12 PM</span><span>4 PM</span><span>8 PM</span>
                </div>
                <div class="position-relative" style="height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                    <?php if ($timeline_segments): ?>
                        <?php
                        $colors = [
                            'active'  => '#22c55e',
                            'break'   => '#f59e0b',
                            'idle'    => '#ef4444',
                            'meeting' => '#3b82f6',
                        ];
                        foreach ($timeline_segments as $seg) {
                            $seg_start = max($day_s, (float)$seg['start']);
                            $seg_end   = min($day_e, (float)$seg['end']);
                            if ($seg_end <= $seg_start) continue;
                            $left = max(0, min(100, ($seg_start - $day_s) / $day_span * 100));
                            $width = max(0.4, min(100, ($seg_end - $seg_start) / $day_span * 100));
                            $type = $seg['type'] ?? 'active';
                            $clr = $colors[$type] ?? '#22c55e';
                            echo '<div title="' . ucfirst($type) . '" style="position:absolute;top:0;height:100%;left:' . $left . '%;width:' . $width . '%;background:' . $clr . ';"></div>';
                        }
                        ?>
                    <?php elseif ($in_pct !== null && $dur_pct !== null): ?>
                        <?php if ($seg_active || $seg_break || $seg_idle):
                            $left = $in_pct;
                        ?>
                        <div title="Active" style="position:absolute;top:0;height:100%;left:<?=$left?>%;width:<?=$seg_active?>%;background:#22c55e;"></div>
                        <?php $left += $seg_active; ?>
                        <div title="Break" style="position:absolute;top:0;height:100%;left:<?=$left?>%;width:<?=$seg_break?>%;background:#f59e0b;"></div>
                        <?php $left += $seg_break; ?>
                        <div title="Idle" style="position:absolute;top:0;height:100%;left:<?=$left?>%;width:<?=$seg_idle?>%;background:#ef4444;"></div>
                        <?php else: ?>
                        <div style="position:absolute;top:0;height:100%;left:<?=$in_pct?>%;width:<?=$dur_pct?>%;background:<?=$sclr?>;opacity:.7;"></div>
                        <?php endif; ?>
                    <?php elseif ($status === 'ABSENT'): ?>
                    <div style="position:absolute;top:0;height:100%;left:0;width:100%;background:#fecaca;"></div>
                    <?php endif; ?>
                </div>
                <!-- Legend -->
                <div class="d-flex gap-2 mt-1" style="font-size:.58rem;color:#94a3b8;">
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#22c55e;margin-right:2px;"></span>Active</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#f59e0b;margin-right:2px;"></span>Break</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#ef4444;margin-right:2px;"></span>Idle</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#3b82f6;margin-right:2px;"></span>Meeting</span>
                </div>
            </div>

            <!-- Hour metrics -->
            <div class="row g-0 text-center pt-2 border-top mt-1">
                <?php
                $metrics = [
                    ['TOTAL',   $hrs,  '#6366f1', 'bi-clock-fill'],
                    ['ACTIVE',  $act,  '#22c55e', 'bi-lightning-charge-fill'],
                    ['BREAK',   $brk,  '#f59e0b', 'bi-hourglass-split'],
                    ['IDLE',    $idl,  '#94a3b8', 'bi-moon-fill'],
                    ['MEETING', $mtg,  '#3b82f6', 'bi-camera-video-fill'],
                ];
                foreach ($metrics as [$lbl, $val, $clr, $ico]):
                ?>
                <div class="col">
                    <div style="font-size:.58rem;color:#94a3b8;letter-spacing:.04em;"><?= $lbl ?></div>
                    <i class="<?= $ico ?>" style="font-size:.6rem;color:<?= $clr ?>;"></i>
                    <div class="fw-bold" style="font-size:.8rem;color:<?= $clr ?>;">
                        <?= $val !== null ? fmt_dur($val) : '<span style="color:#cbd5e1">—</span>' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── RANGE SYNC & EXPORT MODAL ─────────────────────────── -->
<div class="modal fade" id="rangeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-range me-2 text-success"></i>Sync & Export Range</h5>
                <button type="button" class="btn-close" id="rangeModalClose" data-bs-dismiss="modal"></button>
            </div>

            <!-- Step 1: date picker -->
            <div id="rangeStep1">
                <div class="modal-body">
                    <div class="alert alert-info small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Syncs each day one at a time — you'll see live progress below.
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">From Date</label>
                            <input type="date" id="rangeSyncFrom" class="form-control" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">To Date</label>
                            <input type="date" id="rangeSyncTo" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <?php for ($i = 0; $i < 3; $i++):
                            $ts = strtotime("-$i month");
                            $mfrom = date('Y-m-01', $ts); $mto = date('Y-m-t', $ts); $mlbl = date('M Y', $ts);
                        ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="setRange('<?=$mfrom?>','<?=$mto?>')">
                            <?= $mlbl ?>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="chkExport" checked>
                            <label class="form-check-label small" for="chkExport">Export as Excel after syncing</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 gap-2">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <!-- Export Only: plain POST form, no sync needed -->
                    <form method="POST" class="d-inline" onsubmit="showExportLoader('Exporting...','Building from saved data')">
                        <input type="hidden" name="action" value="export_only">
                        <input type="hidden" id="eoFrom" name="range_from" value="<?= date('Y-m-01') ?>">
                        <input type="hidden" id="eoTo"   name="range_to"   value="<?= date('Y-m-d') ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm" id="btnExportOnly"
                                onclick="syncDatesToExportForm()">
                            <i class="bi bi-download me-1"></i>Export Only
                        </button>
                    </form>
                    <button type="button" class="btn btn-success btn-sm" id="btnStartSync">
                        <i class="bi bi-arrow-repeat me-1"></i>Sync & Export
                    </button>
                </div>
            </div>

            <!-- Step 2: live progress -->
            <div id="rangeStep2" class="d-none">
                <div class="modal-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div class="spinner-border spinner-border-sm text-success" id="syncSpinner"></div>
                        <span class="fw-semibold" id="syncStatusText">Starting…</span>
                    </div>
                    <div class="progress mb-2" style="height:8px;border-radius:6px;">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                             id="syncProgressBar" role="progressbar" style="width:0%"></div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-2">
                        <span id="syncDayLabel">Day 0 of 0</span>
                        <span id="syncRecordLabel">0 records synced</span>
                    </div>
                    <div id="syncLog" class="small font-monospace bg-light rounded p-2"
                         style="max-height:150px;overflow-y:auto;font-size:0.78rem;"></div>
                </div>
                <div class="modal-footer border-0 pt-0 gap-2">
                    <!-- Export form submitted programmatically after sync — use export_only since JS already synced -->
                    <form method="POST" id="postSyncExportForm" style="display:none">
                        <input type="hidden" name="action" value="export_only">
                        <input type="hidden" id="pseFrom" name="range_from">
                        <input type="hidden" id="pseTo"   name="range_to">
                    </form>
                    <button type="button" class="btn btn-light btn-sm d-none" id="btnSyncDone"
                            data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success btn-sm d-none" id="btnSyncExport"
                            onclick="triggerPostSyncExport()">
                        <i class="bi bi-download me-1"></i>Download Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ EMPLOYEES TAB ════════════════════════════════════ -->
<?php elseif ($tab === 'employees'): ?>

<?php if ($tl_error): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= sanitize($tl_error) ?></div>
<?php elseif (!$tl_users && $api_key): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px;">
    <i class="bi bi-people text-muted" style="font-size:2.5rem;"></i>
    <p class="text-muted mt-2">No employees returned from TeamLogger.</p>
    <a href="?tab=settings" class="btn btn-sm btn-outline-primary">Check Settings</a>
</div>
<?php elseif ($tl_users):
    $matched = 0;
    foreach ($tl_users as $tu) {
        $code = strtoupper(tl_emp_code($tu));
        $mail = strtolower(tl_emp_email($tu));
        if (($code && isset($hrms_by_empno[$code])) || ($mail && isset($hrms_by_email[$mail]))) $matched++;
    }
?>
<div class="row g-3 mb-4">
    <?php foreach([['TL Employees',count($tl_users),'primary'],['Mapped',$matched,'success'],['Not Mapped',count($tl_users)-$matched,'danger']] as [$lbl,$cnt,$col]): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 text-center" style="border-radius:12px;">
            <div class="fw-bold fs-4 text-<?=$col?>"><?=$cnt?></div>
            <div class="text-muted small"><?=$lbl?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">TL Employee</th>
                        <th>Emp Code</th>
                        <th>Email</th>
                        <th>HRMS Match</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tl_users as $tu):
                    $code      = strtoupper(tl_emp_code($tu));
                    $mail      = strtolower(tl_emp_email($tu));
                    $name      = tl_emp_name($tu);
                    $guid      = tl_emp_guid($tu);
                    $hrms_user = ($code && isset($hrms_by_empno[$code])) ? $hrms_by_empno[$code]
                               : (($mail && isset($hrms_by_email[$mail])) ? $hrms_by_email[$mail] : null);
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#0f4c81,#1e88e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.8rem;">
                                <?= strtoupper(substr($name ?: '?', 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?= sanitize($name ?: '—') ?></div>
                                <div class="text-muted" style="font-size:.7rem;"><?= sanitize($tu['username'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td><code class="small text-primary"><?= sanitize($code ?: '—') ?></code></td>
                    <td class="small text-muted"><?= sanitize($mail ?: '—') ?></td>
                    <td>
                        <?php if ($hrms_user): ?>
                        <span style="background:#dcfce7;color:#166534;border-radius:6px;padding:2px 8px;font-size:.7rem;">
                            <i class="bi bi-check me-1"></i><?= sanitize($hrms_user['name']) ?>
                        </span>
                        <?php else: ?>
                        <span style="background:#fee2e2;color:#991b1b;border-radius:6px;padding:2px 8px;font-size:.7rem;">
                            <i class="bi bi-x me-1"></i>Not mapped
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm <?= $hrms_user ? 'btn-outline-secondary' : 'btn-outline-primary' ?> py-0 px-2"
                            onclick="openMap('<?= sanitize($guid) ?>','<?= sanitize($code) ?>','<?= sanitize($name) ?>','<?= sanitize($mail) ?>')">
                            <i class="bi bi-<?= $hrms_user ? 'arrow-repeat' : 'link' ?> me-1"></i>
                            <?= $hrms_user ? 'Re-map' : 'Map / Create' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ LIVE TAB ══════════════════════════════════════════ -->
<?php elseif ($tab === 'live'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-activity text-success me-2"></i>Last 1 Hour Activity</h6>
    <a href="?tab=live" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
</div>
<?php if (!$recent_tasks): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px;">
    <i class="bi bi-moon text-muted" style="font-size:2.5rem;"></i>
    <p class="text-muted mt-2">No activity in the last hour.</p>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($recent_tasks as $rt):
    $ts = isset($rt['lastActivity']) ? round($rt['lastActivity'] / 1000) : null;
?>
<div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow-sm p-3" style="border-radius:12px;">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
            <div class="fw-semibold small"><?= sanitize(tl_emp_name($rt)) ?></div>
            <?php if ($ts): ?><span class="text-muted ms-auto" style="font-size:.65rem;"><?= date('h:i A', $ts) ?></span><?php endif; ?>
        </div>
        <div class="text-muted small"><i class="bi bi-folder me-1"></i><?= sanitize($rt['projectName'] ?? $rt['project'] ?? '—') ?></div>
        <div class="text-muted small"><i class="bi bi-list-task me-1"></i><?= sanitize($rt['taskName'] ?? $rt['task'] ?? '—') ?></div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ SETTINGS TAB ══════════════════════════════════════ -->
<?php elseif ($tab === 'settings'):

// Raw debug response (only when ?debug=1)
$debug_punch   = null;
$debug_summary = null;
$debug_users   = null;
if (isset($_GET['debug']) && $api_key) {
    $today = date('Y-m-d');
    [$dy,$dm,$dd] = explode('-', $today);
    $debug_punch = tl_get('/api/company_punch_in_out_report', $api_key, [
        'year'=>(int)$dy,'month'=>(int)$dm,'day'=>(int)$dd,
        'timezoneOffsetMinutes'=>(int)$tz,'dayStartsAtHours'=>$day_start,'dayEndsAtHours'=>$day_end,
    ]);
    // Summary report for today
    $offset_s  = (int)$tz * 60;
    $base_utc  = strtotime($today . ' 00:00:00 UTC');
    $start_ms  = ($base_utc - $offset_s) * 1000;
    $end_ms    = ($base_utc - $offset_s + 86399) * 1000;
    $raw_sum   = tl_get('/api/employee_summary_report', $api_key, [
        'startTime' => $start_ms, 'endTime' => $end_ms,
    ]);
    $sum_entries = is_array($raw_sum) ? (isset($raw_sum[0]) ? $raw_sum : ($raw_sum['data'] ?? [])) : [];
    $debug_summary = array_slice($sum_entries, 0, 2) ?: $raw_sum;

    $raw_users = tl_get('/api/integration/list_users', $api_key);
    $debug_users = is_array($raw_users) ? array_slice(
        isset($raw_users[0]) ? $raw_users : ($raw_users['users'] ?? $raw_users['data'] ?? []),
        0, 2
    ) : $raw_users;
}
?>
<div class="row g-4 justify-content-center">
<div class="col-md-7">
<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-4"><i class="bi bi-gear me-2"></i>TeamLogger API Settings</h6>
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            <div class="mb-3">
                <label class="form-label small fw-semibold">API Key (Bearer Token) *</label>
                <div class="input-group">
                    <input type="password" name="tl_api_key" id="tl_key_inp" class="form-control"
                           value="<?= sanitize($api_key) ?>" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleKey()">
                        <i class="bi bi-eye" id="eye_icon"></i>
                    </button>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Timezone Offset (min)</label>
                    <input type="number" name="tl_timezone_offset" class="form-control" value="<?= sanitize($tz) ?>" placeholder="330">
                    <div class="form-text">IST = 330</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Day Starts (hrs)</label>
                    <input type="number" name="tl_day_start" class="form-control" step="0.5" value="<?= sanitize($day_start) ?>" placeholder="9.5">
                    <div class="form-text">9.5 = 9:30 AM</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Day Ends (hrs)</label>
                    <input type="number" name="tl_day_end" class="form-control" step="0.5" value="<?= sanitize($day_end) ?>" placeholder="18.5">
                    <div class="form-text">18.5 = 6:30 PM</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                <?php if ($api_key): ?>
                <a href="?tab=employees" class="btn btn-outline-success"><i class="bi bi-people me-1"></i>Test Connection</a>
                <a href="?tab=settings&debug=1" class="btn btn-outline-secondary">
                    <i class="bi bi-bug me-1"></i>Debug API Response
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Raw API debug ──────────────────────────────── -->
<?php if ($debug_punch !== null): ?>
<div class="card border-0 shadow-sm mt-3" style="border-radius:12px;">
    <div class="card-body p-3">
        <h6 class="fw-bold mb-1">
            <i class="bi bi-braces text-warning me-2"></i>
            Raw: company_punch_in_out_report (today)
        </h6>
        <p class="text-muted small mb-2">
            These are the exact field names TeamLogger sends. Check which field holds punch-in/out times.
        </p>
        <pre class="p-3 rounded small mb-0" style="background:#1e293b;color:#94a3b8;max-height:320px;overflow:auto;font-size:.72rem;"><?= htmlspecialchars(json_encode($debug_punch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
</div>
<div class="card border-0 shadow-sm mt-3" style="border-radius:12px;">
    <div class="card-body p-3">
        <h6 class="fw-bold mb-1">
            <i class="bi bi-braces text-success me-2"></i>
            Raw: employee_summary_report (today)
        </h6>
        <p class="text-muted small mb-2">
            Use these fields to confirm break, idle, active, and meeting values.
        </p>
        <pre class="p-3 rounded small mb-0" style="background:#1e293b;color:#94a3b8;max-height:320px;overflow:auto;font-size:.72rem;"><?= htmlspecialchars(json_encode($debug_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
</div>
<div class="card border-0 shadow-sm mt-3" style="border-radius:12px;">
    <div class="card-body p-3">
        <h6 class="fw-bold mb-1">
            <i class="bi bi-braces text-info me-2"></i>
            Raw: list_users (first 2)
        </h6>
        <pre class="p-3 rounded small mb-0" style="background:#1e293b;color:#94a3b8;max-height:240px;overflow:auto;font-size:.72rem;"><?= htmlspecialchars(json_encode($debug_users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
</div>
<?php endif; ?>

</div>
</div>
<?php endif; ?>

<!-- ══ MAP / CREATE MODAL ════════════════════════════════ -->
<div class="modal fade" id="mapModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-link me-2"></i>Map / Create HRMS User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    TeamLogger: <strong id="map_tl_name_display"></strong>
                    &nbsp;·&nbsp; Code: <code id="map_tl_code_display"></code>
                </div>
                <ul class="nav nav-pills gap-2 mb-3" id="mapTabs">
                    <li><button class="nav-link active rounded-pill btn btn-sm" onclick="showMapTab('existing')">
                        <i class="bi bi-person-check me-1"></i>Map to Existing</button></li>
                    <li><button class="nav-link rounded-pill btn btn-sm" onclick="showMapTab('create')">
                        <i class="bi bi-person-plus me-1"></i>Create New</button></li>
                </ul>
                <div id="tabExisting">
                    <form method="POST" id="mapExistingForm">
                        <input type="hidden" name="action" value="map_user">
                        <input type="hidden" name="tl_guid" id="me_tl_guid">
                        <input type="hidden" name="tl_emp_code" id="me_tl_code">
                        <input type="hidden" name="tl_name" id="me_tl_name">
                        <input type="hidden" name="tl_email" id="me_tl_email">
                        <div class="row g-2 mb-3">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Filter by Role</label>
                                <select id="filterRole" class="form-select form-select-sm" onchange="filterUsers()">
                                    <option value="">All Roles</option>
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?=$r?>"><?=$r?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Filter by Department</label>
                                <select id="filterDept" class="form-select form-select-sm" onchange="filterUsers()">
                                    <option value="">All Departments</option>
                                    <?php foreach ($depts as $d): ?>
                                    <option value="<?=$d['id']?>"><?= sanitize($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary btn-sm w-100"
                                    onclick="document.getElementById('filterRole').value='';document.getElementById('filterDept').value='';filterUsers()">Reset</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Select HRMS User *</label>
                            <input type="text" id="mapSearch" class="form-control form-control-sm mb-1" placeholder="Search by name or email...">
                            <select name="hrms_user_id" id="mapSelect" class="form-select" required size="6" style="height:auto;">
                                <?php foreach ($hrms_users as $hu): ?>
                                <option value="<?=$hu['id']?>" data-role="<?=$hu['role']?>" data-dept="<?=$hu['dept_id']??''?>">
                                    <?= sanitize($hu['name']) ?> — <?= sanitize($hu['role']) ?>
                                    <?= $hu['dept_name'] ? '· '.$hu['dept_name'] : '' ?>
                                    <?= $hu['emp_no'] ? '('.$hu['emp_no'].')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-link me-1"></i>Map & Get Invite Link</button>
                    </form>
                </div>
                <div id="tabCreate" class="d-none">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_and_map">
                        <input type="hidden" name="tl_guid" id="mc_tl_guid">
                        <input type="hidden" name="tl_emp_code" id="mc_tl_code">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Full Name *</label>
                                <input type="text" name="new_name" id="mc_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Email *</label>
                                <input type="email" name="new_email" id="mc_email" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Role *</label>
                                <select name="new_role" class="form-select" required>
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?=$r?>" <?=$r==='EMPLOYEE'?'selected':''?>><?=$r?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="alert alert-info small mt-3 mb-0 py-2">
                            <i class="bi bi-info-circle me-1"></i>An invite link will be generated — share it so they can set their password.
                        </div>
                        <button type="submit" class="btn btn-success mt-3"><i class="bi bi-person-plus me-1"></i>Create & Get Invite Link</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Preloader ────────────────────────────────────────────
function showLoader(msg, sub) {
    document.getElementById('loaderMsg').textContent = msg || 'Loading...';
    document.getElementById('loaderSub').textContent = sub || '';
    const el = document.getElementById('pageLoader');
    el.style.opacity = '1';
    el.style.display = 'flex';
}
function hideLoader() {
    const el = document.getElementById('pageLoader');
    el.style.opacity = '0';
    setTimeout(() => el.style.display = 'none', 450);
}
// Show loader for exports, then hide it once the download cookie arrives
function showExportLoader(msg, sub) {
    showLoader(msg, sub);
    const timer = setInterval(function () {
        if (document.cookie.split(';').some(c => c.trim().startsWith('tl_dl='))) {
            clearInterval(timer);
            document.cookie = 'tl_dl=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
            hideLoader();
        }
    }, 400);
}

// ── Auto-sync ────────────────────────────────────────────
<?php if ($needs_auto_sync): ?>
document.addEventListener('DOMContentLoaded', function () {
    showLoader('Auto-syncing attendance...', 'Fetching punch data from TeamLogger for <?= date('d M Y', strtotime($att_date)) ?>');
    document.getElementById('autoSyncForm').submit();
});
<?php endif; ?>

// ── Invite link modal ────────────────────────────────────
<?php if ($invite_link): ?>
document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('inviteLinkModal')).show());
function copyLink() {
    navigator.clipboard.writeText(document.getElementById('inviteLinkInput').value).then(() => {
        document.getElementById('copyIconModal').className = 'bi bi-check-lg';
        document.getElementById('copyMsgModal').classList.remove('d-none');
        setTimeout(() => {
            document.getElementById('copyIconModal').className = 'bi bi-clipboard';
            document.getElementById('copyMsgModal').classList.add('d-none');
        }, 3000);
    });
}
<?php endif; ?>

// ── Map modal ────────────────────────────────────────────
function openMap(guid, code, name, email) {
    ['me_tl_guid','mc_tl_guid'].forEach(id => document.getElementById(id).value = guid);
    ['me_tl_code','mc_tl_code'].forEach(id => document.getElementById(id).value = code);
    document.getElementById('me_tl_name').value  = name;
    document.getElementById('me_tl_email').value = email;
    document.getElementById('mc_name').value  = name;
    document.getElementById('mc_email').value = email;
    document.getElementById('map_tl_name_display').textContent = name;
    document.getElementById('map_tl_code_display').textContent = code || '—';
    showMapTab('existing');
    new bootstrap.Modal(document.getElementById('mapModal')).show();
}
function showMapTab(tab) {
    document.getElementById('tabExisting').classList.toggle('d-none', tab !== 'existing');
    document.getElementById('tabCreate').classList.toggle('d-none', tab !== 'create');
    document.querySelectorAll('#mapTabs .nav-link').forEach((b, i) => {
        b.classList.toggle('active', (i === 0 && tab === 'existing') || (i === 1 && tab === 'create'));
    });
}
function filterUsers() {
    const role = document.getElementById('filterRole').value;
    const dept = document.getElementById('filterDept').value;
    const q    = document.getElementById('mapSearch').value.toLowerCase();
    Array.from(document.getElementById('mapSelect').options).forEach(o => {
        o.hidden = !((!role || o.dataset.role === role) && (!dept || o.dataset.dept == dept) && (!q || o.text.toLowerCase().includes(q)));
    });
}
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('mapSearch')?.addEventListener('input', filterUsers);
});

// ── Settings ─────────────────────────────────────────────
function toggleKey() {
    const i = document.getElementById('tl_key_inp');
    const e = document.getElementById('eye_icon');
    i.type = i.type === 'password' ? 'text' : 'password';
    e.className = i.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

// ── Range modal helpers ───────────────────────────────────
function setRange(from, to) {
    document.getElementById('rangeSyncFrom').value = from;
    document.getElementById('rangeSyncTo').value   = to;
}
function syncDatesToExportForm() {
    document.getElementById('eoFrom').value = document.getElementById('rangeSyncFrom').value;
    document.getElementById('eoTo').value   = document.getElementById('rangeSyncTo').value;
}
function triggerPostSyncExport() {
    showExportLoader('Exporting...', 'Building Excel from synced data');
    document.getElementById('postSyncExportForm').submit();
}

// ── Browser-loop range sync (one day per fetch) ───────────
(function () {
    var syncFrom, syncTo, dates, current, grandSynced, exportAfter, aborted;

    document.getElementById('btnStartSync')?.addEventListener('click', function () {
        syncFrom    = document.getElementById('rangeSyncFrom').value;
        syncTo      = document.getElementById('rangeSyncTo').value;
        exportAfter = document.getElementById('chkExport').checked;
        if (!syncFrom || !syncTo) { alert('Please select a date range.'); return; }

        dates       = buildDateList(syncFrom, syncTo);
        current     = 0;
        grandSynced = 0;
        aborted     = false;

        document.getElementById('rangeStep1').classList.add('d-none');
        document.getElementById('rangeStep2').classList.remove('d-none');
        document.getElementById('rangeModalClose').style.pointerEvents = 'none';
        document.getElementById('rangeModalClose').style.opacity = '0.3';
        document.getElementById('syncProgressBar').style.width = '0%';
        document.getElementById('syncProgressBar').classList.add('progress-bar-striped','progress-bar-animated');
        document.getElementById('syncStatusText').textContent = 'Syncing ' + dates.length + ' days…';
        document.getElementById('syncDayLabel').textContent   = 'Day 0 of ' + dates.length;
        document.getElementById('syncRecordLabel').textContent = '0 records synced';
        document.getElementById('syncLog').innerHTML = '';
        document.getElementById('syncSpinner').classList.remove('d-none');
        document.getElementById('btnSyncDone').classList.add('d-none');
        document.getElementById('btnSyncExport').classList.add('d-none');

        syncNext();
    });

    // Reset modal to step 1 when closed
    document.getElementById('rangeModal')?.addEventListener('hidden.bs.modal', function () {
        aborted = true;
        document.getElementById('rangeStep1').classList.remove('d-none');
        document.getElementById('rangeStep2').classList.add('d-none');
        document.getElementById('rangeModalClose').style.pointerEvents = '';
        document.getElementById('rangeModalClose').style.opacity = '';
    });

    function syncNext() {
        if (aborted) return;
        if (current >= dates.length) { onComplete(); return; }

        var date = dates[current];
        var pct  = Math.round((current / dates.length) * 100);
        document.getElementById('syncStatusText').textContent = 'Syncing ' + date + '…';
        document.getElementById('syncDayLabel').textContent   = 'Day ' + (current + 1) + ' of ' + dates.length;
        document.getElementById('syncProgressBar').style.width = pct + '%';

        var fd = new FormData();
        fd.append('date', date);

        fetch('teamlogger_sync_day.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (aborted) return;
                grandSynced += (data.synced || 0);
                document.getElementById('syncRecordLabel').textContent = grandSynced + ' records synced';
                logLine(date, data.synced, data.skipped || 0, data.error);
                current++;
                syncNext();
            })
            .catch(function(err) {
                if (aborted) return;
                logLine(date, 0, 0, err.message || 'Network error');
                current++;
                syncNext(); // keep going even on network error
            });
    }

    function onComplete() {
        document.getElementById('syncProgressBar').style.width = '100%';
        document.getElementById('syncProgressBar').classList.remove('progress-bar-striped','progress-bar-animated');
        document.getElementById('syncSpinner').classList.add('d-none');
        document.getElementById('syncStatusText').textContent = 'Done — ' + grandSynced + ' records synced across ' + dates.length + ' days';
        document.getElementById('rangeModalClose').style.pointerEvents = '';
        document.getElementById('rangeModalClose').style.opacity = '';
        document.getElementById('btnSyncDone').classList.remove('d-none');

        if (exportAfter) {
            document.getElementById('pseFrom').value = syncFrom;
            document.getElementById('pseTo').value   = syncTo;
            triggerPostSyncExport();
        } else {
            document.getElementById('btnSyncExport').classList.remove('d-none');
            document.getElementById('pseFrom').value = syncFrom;
            document.getElementById('pseTo').value   = syncTo;
        }
    }

    function logLine(date, synced, skipped, error) {
        var log  = document.getElementById('syncLog');
        var line = document.createElement('div');
        line.className = error ? 'text-danger' : 'text-success';
        if (error) {
            line.textContent = date + ': ✗ ' + error;
        } else {
            line.textContent = date + ': ✓ ' + synced + ' synced' + (skipped ? ', ' + skipped + ' skipped' : '');
        }
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    function buildDateList(from, to) {
        // Parse as local date parts to avoid UTC-offset shifting dates
        function parseLocal(s) {
            var p = s.split('-');
            return new Date(+p[0], +p[1] - 1, +p[2]);
        }
        function fmtDate(d) {
            var mm = String(d.getMonth() + 1).padStart(2, '0');
            var dd = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + mm + '-' + dd;
        }
        var list = [], cur = parseLocal(from), end = parseLocal(to);
        while (cur <= end) {
            list.push(fmtDate(cur));
            cur.setDate(cur.getDate() + 1);
        }
        return list;
    }
})();
</script>

<?php include 'footer.php'; ?>

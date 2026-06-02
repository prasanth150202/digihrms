<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'hr_settings';
$pageTitle = 'HR Settings';
$u = current_user();

// ── helpers ──────────────────────────────────────────────
function get_work_config($conn) {
    $rows = $conn->query("SELECT config_key, config_value FROM work_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(['saturday_working_weeks'=>'2,4','sunday_working'=>'0',
                        'work_start_time'=>'09:00','work_end_time'=>'18:00'], $rows);
}
function sat_occurrence($day_num) { return (int)ceil($day_num / 7); }
function is_working_day($ts, $sat_weeks, $sun_working) {
    $dow = (int)date('N', $ts); // 1=Mon 7=Sun
    if ($dow === 7) return (bool)$sun_working;
    if ($dow === 6) return in_array(sat_occurrence((int)date('j',$ts)), $sat_weeks);
    return true;
}

// ── POST HANDLERS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Leave type: save (add/edit) ──────────────────────
    if ($action === 'save_leave_type') {
        $id   = (int)($_POST['lt_id'] ?? 0);
        $name = trim($_POST['name']);
        $dpm  = max(0, (float)$_POST['days_per_month']);
        $cf   = isset($_POST['carry_forward']) ? 1 : 0;
        $hd   = isset($_POST['half_day_ok'])   ? 1 : 0;
        $col  = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color']) ? $_POST['color'] : '#0f4c81';
        $ord  = (int)($_POST['sort_order'] ?? 0);
        if ($id) {
            $conn->prepare("UPDATE leave_types SET name=?,days_per_month=?,carry_forward=?,half_day_ok=?,color=?,sort_order=? WHERE id=?")
                 ->execute([$name,$dpm,$cf,$hd,$col,$ord,$id]);
        } else {
            $conn->prepare("INSERT INTO leave_types (name,days_per_month,carry_forward,half_day_ok,color,sort_order) VALUES (?,?,?,?,?,?)")
                 ->execute([$name,$dpm,$cf,$hd,$col,$ord]);
        }
        set_flash('success', "Leave type '$name' saved.");
        header("Location: hr_settings.php?tab=leave_types"); exit;
    }

    // ── Leave type: toggle active ────────────────────────
    if ($action === 'toggle_leave_type') {
        $id = (int)$_POST['lt_id'];
        $conn->prepare("UPDATE leave_types SET active = 1 - active WHERE id=?")->execute([$id]);
        header("Location: hr_settings.php?tab=leave_types"); exit;
    }

    // ── Leave type: delete ───────────────────────────────
    if ($action === 'delete_leave_type') {
        $id = (int)$_POST['lt_id'];
        // Only delete if no leaves reference it
        $used = $conn->prepare("SELECT COUNT(*) FROM leaves l JOIN leave_types lt ON l.leave_type=lt.name WHERE lt.id=?");
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            set_flash('danger', 'Cannot delete — existing leave records use this type. Deactivate it instead.');
        } else {
            $conn->prepare("DELETE FROM leave_types WHERE id=?")->execute([$id]);
            set_flash('success', 'Leave type deleted.');
        }
        header("Location: hr_settings.php?tab=leave_types"); exit;
    }

    // ── Holiday: add ─────────────────────────────────────
    if ($action === 'add_holiday') {
        $date = $_POST['holiday_date'];
        $name = trim($_POST['holiday_name']);
        $type = $_POST['holiday_type'];
        $conn->prepare("INSERT INTO holidays (holiday_date,name,type,created_by) VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE name=?,type=?")
             ->execute([$date,$name,$type,$u['id'],$name,$type]);
        set_flash('success', "Holiday '$name' marked on ".date('d M Y',strtotime($date)).".");
        $ym = substr($date,0,7);
        header("Location: hr_settings.php?tab=holidays&cal=$ym"); exit;
    }

    // ── Holiday: remove ──────────────────────────────────
    if ($action === 'remove_holiday') {
        $id = (int)$_POST['holiday_id'];
        $conn->prepare("DELETE FROM holidays WHERE id=?")->execute([$id]);
        set_flash('success', 'Holiday removed.');
        header("Location: hr_settings.php?tab=holidays&cal=".($_POST['cal_month']??date('Y-m'))); exit;
    }

    // ── Work config: save ────────────────────────────────
    if ($action === 'save_work_config') {
        $sat_weeks   = array_filter(array_map('intval', $_POST['sat_weeks'] ?? []), fn($v)=>$v>=1&&$v<=5);
        $sun_working = isset($_POST['sunday_working']) ? '1' : '0';
        $start       = $_POST['work_start_time'] ?? '09:00';
        $end         = $_POST['work_end_time']   ?? '18:00';
        $perm_hours  = max(0, (float)($_POST['permission_hours_per_month'] ?? 2));
        $sat_val     = implode(',', array_values($sat_weeks));
        $uid = $u['id'];
        foreach ([
            ['saturday_working_weeks',    $sat_val],
            ['sunday_working',            $sun_working],
            ['work_start_time',           $start],
            ['work_end_time',             $end],
            ['permission_hours_per_month',(string)$perm_hours],
        ] as [$k, $v]) {
            $conn->prepare("INSERT INTO work_config (config_key,config_value,updated_by) VALUES (?,?,?)
                            ON DUPLICATE KEY UPDATE config_value=?,updated_by=?")
                 ->execute([$k,$v,$uid,$v,$uid]);
        }
        set_flash('success', 'Work schedule saved.');
        header("Location: hr_settings.php?tab=work_schedule"); exit;
    }
}

// ── FETCH DATA ────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'leave_types';

$leave_types = $conn->query("SELECT * FROM leave_types ORDER BY sort_order, name")->fetchAll();

// Calendar month
$cal_ym  = $_GET['cal'] ?? date('Y-m');
[$cal_y, $cal_m] = array_map('intval', explode('-', $cal_ym.'-01'));
if ($cal_m < 1 || $cal_m > 12) { $cal_m = (int)date('m'); $cal_y = (int)date('Y'); }
$prev_ym = date('Y-m', mktime(0,0,0,$cal_m-1,1,$cal_y));
$next_ym = date('Y-m', mktime(0,0,0,$cal_m+1,1,$cal_y));

$hols_stmt = $conn->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
$all_holidays = $hols_stmt->fetchAll();
$holiday_map  = []; // date => holiday row
foreach ($all_holidays as $h) $holiday_map[$h['holiday_date']] = $h;

$wc = get_work_config($conn);
$sat_working = array_filter(array_map('intval', explode(',', $wc['saturday_working_weeks'])));
$sun_working = (bool)$wc['sunday_working'];

$hol_type_color = ['NATIONAL'=>'#e53935','OPTIONAL'=>'#fb8c00','COMPANY'=>'#1e88e5'];
$hol_type_label = ['NATIONAL'=>'National','OPTIONAL'=>'Optional','COMPANY'=>'Company'];

include 'header.php';
?>

<style>
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.cal-head { background:#1e293b; color:#fff; text-align:center; padding:8px 4px; font-size:.72rem; font-weight:700; border-radius:6px; }
.cal-day  { min-height:72px; background:#fff; border-radius:8px; padding:6px; border:1px solid #e2e8f0; position:relative; font-size:.75rem; cursor:pointer; transition:border-color .15s; }
.cal-day:hover { border-color:#0f4c81; }
.cal-day.today   { border-color:#1e88e5; border-width:2px; }
.cal-day.weekend { background:#f8fafc; cursor:default; }
.cal-day.nonwork-sat { background:#f1f5f9; }
.cal-day.holiday { background:#fff5f5; }
.cal-day.other-month { opacity:.35; cursor:default; pointer-events:none; }
.cal-day-num { font-weight:700; color:#334155; margin-bottom:4px; }
.cal-day.weekend .cal-day-num { color:#94a3b8; }
.hol-chip { background:#fee2e2; color:#dc2626; font-size:.62rem; border-radius:4px; padding:2px 5px; display:flex; align-items:center; gap:3px; margin-top:2px; }
.lt-color-dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-0">HR Settings</h5>
        <div class="text-muted small">Leave types, holiday calendar &amp; work schedule</div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-pills gap-2 mb-4">
    <li><a class="nav-link rounded-pill <?= $tab==='leave_types'?'active':'' ?>" href="?tab=leave_types">
        <i class="bi bi-calendar-check me-1"></i>Leave Types</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='holidays'?'active':'' ?>" href="?tab=holidays">
        <i class="bi bi-calendar-event me-1"></i>Holiday Calendar</a></li>
    <li><a class="nav-link rounded-pill <?= $tab==='work_schedule'?'active':'' ?>" href="?tab=work_schedule">
        <i class="bi bi-clock me-1"></i>Work Schedule</a></li>
</ul>

<!-- ════════════════════════════════════════════════════════
     LEAVE TYPES TAB
════════════════════════════════════════════════════════ -->
<?php if ($tab === 'leave_types'): ?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ltModal" onclick="openLT(0)">
        <i class="bi bi-plus-lg me-1"></i>Add Leave Type
    </button>
</div>

<div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Leave Type</th>
                    <th>Days / Month</th>
                    <th>Carry Forward</th>
                    <th>Half Day</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$leave_types): ?>
            <tr><td colspan="6" class="text-center py-5 text-muted">No leave types defined yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($leave_types as $lt): ?>
            <tr>
                <td class="ps-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="lt-color-dot" style="background:<?= sanitize($lt['color']) ?>;"></span>
                        <span class="fw-semibold small"><?= sanitize($lt['name']) ?></span>
                    </div>
                </td>
                <td>
                    <span class="badge rounded-pill" style="background:<?= sanitize($lt['color']) ?>20;color:<?= sanitize($lt['color']) ?>;font-size:.8rem;">
                        <?= $lt['days_per_month'] ?> days/mo
                    </span>
                </td>
                <td>
                    <?php if ($lt['carry_forward']): ?>
                    <span class="badge bg-success-subtle text-success">Yes</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary">No</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($lt['half_day_ok']): ?>
                    <span class="badge bg-info-subtle text-info">Allowed</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary">Not allowed</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="toggle_leave_type">
                        <input type="hidden" name="lt_id"  value="<?= $lt['id'] ?>">
                        <button class="badge border-0 bg-<?= $lt['active']?'success':'danger' ?>-subtle text-<?= $lt['active']?'success':'danger' ?> fw-semibold"
                                style="cursor:pointer;" title="Click to toggle">
                            <?= $lt['active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-primary py-0 px-2"
                            onclick="openLT(<?= $lt['id'] ?>,'<?= addslashes($lt['name']) ?>',<?= $lt['days_per_month'] ?>,<?= $lt['carry_forward'] ?>,<?= $lt['half_day_ok'] ?>,'<?= $lt['color'] ?>',<?= $lt['sort_order'] ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline ms-1"
                          onsubmit="return hConfirmSync(event,'Delete this leave type? This will fail if any leaves use it.')">
                        <input type="hidden" name="action" value="delete_leave_type">
                        <input type="hidden" name="lt_id"  value="<?= $lt['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Leave Type Modal -->
<div class="modal fade" id="ltModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="action" value="save_leave_type">
            <input type="hidden" name="lt_id"  id="lt_id" value="0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="ltModalTitle">Add Leave Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Leave Type Name *</label>
                        <input type="text" name="name" id="lt_name" class="form-control" required placeholder="e.g. Casual Leave">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Days Per Month *</label>
                        <input type="number" name="days_per_month" id="lt_days" class="form-control" min="0" max="31" step="0.5" required value="1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Display Color</label>
                        <input type="color" name="color" id="lt_color" class="form-control form-control-color w-100" value="#0f4c81">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" id="lt_sort" class="form-control" value="0" min="0">
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end pb-1 gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="carry_forward" id="lt_cf">
                            <label class="form-check-label small" for="lt_cf">Carry Forward</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="half_day_ok" id="lt_hd" checked>
                            <label class="form-check-label small" for="lt_hd">Allow Half Day</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     HOLIDAY CALENDAR TAB
════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'holidays'): ?>

<?php
$days_in_month = (int)date('t', mktime(0,0,0,$cal_m,1,$cal_y));
$first_dow     = (int)date('N', mktime(0,0,0,$cal_m,1,$cal_y)); // 1=Mon
?>

<div class="row g-4">

<!-- Calendar -->
<div class="col-md-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">

            <!-- Month nav -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="?tab=holidays&cal=<?= $prev_ym ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <h6 class="fw-bold mb-0"><?= date('F Y', mktime(0,0,0,$cal_m,1,$cal_y)) ?></h6>
                <a href="?tab=holidays&cal=<?= $next_ym ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <!-- Day headers -->
            <div class="cal-grid mb-1">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dh): ?>
                <div class="cal-head"><?= $dh ?></div>
                <?php endforeach; ?>
            </div>

            <!-- Days grid -->
            <div class="cal-grid">
            <?php
            // Leading blanks
            for ($b = 1; $b < $first_dow; $b++) {
                echo '<div class="cal-day other-month"></div>';
            }
            for ($d = 1; $d <= $days_in_month; $d++) {
                $ts       = mktime(0,0,0,$cal_m,$d,$cal_y);
                $date_str = date('Y-m-d', $ts);
                $dow      = (int)date('N', $ts); // 1=Mon 7=Sun
                $is_sun   = $dow === 7;
                $is_sat   = $dow === 6;
                $is_hol   = isset($holiday_map[$date_str]);
                $is_today = $date_str === date('Y-m-d');
                $nonwork_sat = $is_sat && !in_array(sat_occurrence($d), $sat_working);
                $classes  = 'cal-day';
                if ($is_sun)                  $classes .= ' weekend';
                elseif ($nonwork_sat)         $classes .= ' nonwork-sat';
                if ($is_hol)                  $classes .= ' holiday';
                if ($is_today)                $classes .= ' today';
                $onclick  = (!$is_sun && !$nonwork_sat) ? "openAddHoliday('$date_str','".($is_hol ? addslashes($holiday_map[$date_str]['name']) : '')."',".($is_hol ? $holiday_map[$date_str]['id'] : 0).",'".($is_hol ? $holiday_map[$date_str]['type'] : 'NATIONAL')."')" : '';
            ?>
            <div class="<?= $classes ?>" <?= $onclick ? "onclick=\"$onclick\"" : '' ?> style="cursor:<?= $onclick ? 'pointer' : 'default' ?>;">
                <div class="cal-day-num"><?= $d ?></div>
                <?php if ($is_hol): ?>
                <div class="hol-chip" title="<?= sanitize($holiday_map[$date_str]['name']) ?>">
                    <i class="bi bi-circle-fill" style="font-size:.45rem;color:<?= $hol_type_color[$holiday_map[$date_str]['type']] ?>;"></i>
                    <span class="text-truncate" style="max-width:60px;"><?= sanitize($holiday_map[$date_str]['name']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($nonwork_sat && !$is_hol): ?>
                <div style="font-size:.6rem;color:#94a3b8;margin-top:2px;">off</div>
                <?php endif; ?>
            </div>
            <?php } ?>
            <!-- Trailing blanks -->
            <?php
            $last_dow = (int)date('N', mktime(0,0,0,$cal_m,$days_in_month,$cal_y));
            for ($b = $last_dow; $b < 7; $b++) echo '<div class="cal-day other-month"></div>';
            ?>
            </div>

            <!-- Legend -->
            <div class="d-flex gap-3 mt-3 flex-wrap" style="font-size:.72rem;">
                <span><span class="d-inline-block rounded me-1" style="width:10px;height:10px;background:#fee2e2;border:1px solid #fca5a5;"></span>Holiday</span>
                <span><span class="d-inline-block rounded me-1" style="width:10px;height:10px;background:#f1f5f9;border:1px solid #e2e8f0;"></span>Non-working Sat</span>
                <span><span class="d-inline-block rounded me-1" style="width:10px;height:10px;background:#f8fafc;border:1px solid #e2e8f0;"></span>Sunday (off)</span>
                <span><i class="bi bi-circle-fill me-1" style="color:#e53935;font-size:.55rem;"></i>National</span>
                <span><i class="bi bi-circle-fill me-1" style="color:#fb8c00;font-size:.55rem;"></i>Optional</span>
                <span><i class="bi bi-circle-fill me-1" style="color:#1e88e5;font-size:.55rem;"></i>Company</span>
            </div>
        </div>
    </div>
</div>

<!-- Holiday list -->
<div class="col-md-4">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-list-ul me-2"></i>All Holidays (<?= date('Y') ?>)</h6>
            <?php
            $this_year_hols = array_filter($all_holidays, fn($h) => substr($h['holiday_date'],0,4) == date('Y'));
            ?>
            <?php if (!$this_year_hols): ?>
            <div class="text-muted small text-center py-3">No holidays marked for <?= date('Y') ?> yet.</div>
            <?php else: ?>
            <div class="d-flex flex-column gap-2">
            <?php foreach ($this_year_hols as $h): ?>
            <div class="d-flex justify-content-between align-items-start p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                <div>
                    <div class="small fw-semibold"><?= sanitize($h['name']) ?></div>
                    <div class="text-muted" style="font-size:.68rem;">
                        <?= date('d M Y (D)', strtotime($h['holiday_date'])) ?>
                        &nbsp;·&nbsp;
                        <span style="color:<?= $hol_type_color[$h['type']] ?>;"><?= $hol_type_label[$h['type']] ?></span>
                    </div>
                </div>
                <form method="POST" class="ms-2" onsubmit="return hConfirmSync(event,'Remove this holiday?')">
                    <input type="hidden" name="action"     value="remove_holiday">
                    <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                    <input type="hidden" name="cal_month"  value="<?= $cal_ym ?>">
                    <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash" style="font-size:.8rem;"></i></button>
                </form>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3" style="border-radius:14px;">
        <div class="card-body p-3">
            <div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Click any working day on the calendar to mark or edit a holiday.</div>
        </div>
    </div>
</div>

</div><!-- /row -->

<!-- Add/Edit Holiday Modal -->
<div class="modal fade" id="holidayModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" style="border-radius:12px;">
            <input type="hidden" name="action" value="add_holiday">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="holModalTitle">Mark Holiday</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Date</label>
                    <input type="date" name="holiday_date" id="hol_date" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Holiday Name *</label>
                    <input type="text" name="holiday_name" id="hol_name" class="form-control" required placeholder="e.g. Diwali">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Type</label>
                    <select name="holiday_type" id="hol_type" class="form-select">
                        <option value="NATIONAL">National</option>
                        <option value="OPTIONAL">Optional</option>
                        <option value="COMPANY">Company</option>
                    </select>
                </div>
                <div id="hol_remove_section" class="d-none">
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Already marked as holiday — you can update or remove it.</span>
                        <form method="POST" class="d-inline" onsubmit="return hConfirmSync(event,'Remove this holiday?')">
                            <input type="hidden" name="action"     value="remove_holiday">
                            <input type="hidden" name="holiday_id" id="hol_remove_id" value="">
                            <input type="hidden" name="cal_month"  value="<?= $cal_ym ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Holiday</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     WORK SCHEDULE TAB
════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'work_schedule'): ?>

<div class="row g-4">
<div class="col-md-6">
<form method="POST">
    <input type="hidden" name="action" value="save_work_config">

    <!-- Saturday pattern -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1"><i class="bi bi-calendar-week me-2 text-primary"></i>Saturday Working Pattern</h6>
            <p class="text-muted small mb-3">Tick which Saturdays of the month are working days.</p>
            <div class="d-flex flex-column gap-3">
            <?php
            $sat_labels = ['1st Saturday','2nd Saturday','3rd Saturday','4th Saturday','5th Saturday (if exists)'];
            for ($w = 1; $w <= 5; $w++):
                $checked = in_array($w, $sat_working);
            ?>
            <div class="d-flex align-items-center justify-content-between p-3 rounded"
                 style="background:<?= $checked?'#eff6ff':'#f8fafc' ?>;border:1px solid <?= $checked?'#bfdbfe':'#e2e8f0' ?>;">
                <div>
                    <div class="fw-semibold small"><?= $sat_labels[$w-1] ?></div>
                    <div class="text-muted" style="font-size:.7rem;">
                        <?= $checked ? 'Working day' : 'Weekly off' ?>
                    </div>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="sat_weeks[]"
                           value="<?= $w ?>" id="sat<?= $w ?>"
                           <?= $checked ? 'checked' : '' ?>
                           style="cursor:pointer;">
                </div>
            </div>
            <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Sunday & Hours -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-clock me-2 text-warning"></i>Other Settings</h6>
            <div class="mb-3 d-flex align-items-center justify-content-between p-3 rounded"
                 style="background:<?= $sun_working?'#eff6ff':'#f8fafc' ?>;border:1px solid <?= $sun_working?'#bfdbfe':'#e2e8f0' ?>;">
                <div>
                    <div class="fw-semibold small">Sunday Working</div>
                    <div class="text-muted" style="font-size:.7rem;"><?= $sun_working?'Sunday is a working day':'Sunday is weekly off' ?></div>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="sunday_working" id="sun_work"
                           <?= $sun_working ? 'checked' : '' ?> style="cursor:pointer;">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-6">
                    <label class="form-label small fw-semibold">Work Start Time</label>
                    <input type="time" name="work_start_time" class="form-control" value="<?= sanitize($wc['work_start_time']) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold">Work End Time</label>
                    <input type="time" name="work_end_time" class="form-control" value="<?= sanitize($wc['work_end_time']) ?>">
                </div>
            </div>

            <hr class="my-3">
            <h6 class="fw-semibold mb-1 small"><i class="bi bi-shield-check me-2 text-info"></i>Permission Limit</h6>
            <p class="text-muted small mb-2">Maximum hours an employee may take as permission per month (Late Coming, Early Going, Half Day).</p>
            <div class="input-group input-group-sm" style="max-width:200px;">
                <input type="number" name="permission_hours_per_month" class="form-control"
                       value="<?= sanitize($wc['permission_hours_per_month'] ?? '2') ?>"
                       step="0.5" min="0" max="100">
                <span class="input-group-text">hrs / month</span>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-floppy me-1"></i>Save Work Schedule
    </button>
</form>
</div>

<!-- Preview -->
<div class="col-md-6">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-eye me-2 text-info"></i>Current Schedule Preview</h6>
            <div class="d-flex flex-column gap-2 small">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Monday – Friday</span>
                    <span class="badge bg-success-subtle text-success">Working</span>
                </div>
                <?php for ($w = 1; $w <= 5; $w++): ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted"><?= $sat_labels[$w-1] ?></span>
                    <span class="badge bg-<?= in_array($w,$sat_working)?'success':'secondary' ?>-subtle text-<?= in_array($w,$sat_working)?'success':'secondary' ?>">
                        <?= in_array($w,$sat_working)?'Working':'Off' ?>
                    </span>
                </div>
                <?php endfor; ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Sunday</span>
                    <span class="badge bg-<?= $sun_working?'success':'secondary' ?>-subtle text-<?= $sun_working?'success':'secondary' ?>">
                        <?= $sun_working?'Working':'Off' ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Office Hours</span>
                    <span class="fw-semibold"><?= sanitize($wc['work_start_time']) ?> – <?= sanitize($wc['work_end_time']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted">Permission / Month</span>
                    <span class="fw-semibold text-info"><?= sanitize($wc['permission_hours_per_month'] ?? '2') ?> hrs</span>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php endif; ?>

<script>
function openLT(id, name='', days=12, cf=0, hd=1, color='#0f4c81', sort=0) {
    document.getElementById('lt_id').value    = id;
    document.getElementById('lt_name').value  = name;
    document.getElementById('lt_days').value  = days;
    document.getElementById('lt_cf').checked  = cf == 1;
    document.getElementById('lt_hd').checked  = hd == 1;
    document.getElementById('lt_color').value = color;
    document.getElementById('lt_sort').value  = sort;
    document.getElementById('ltModalTitle').textContent = id ? 'Edit Leave Type' : 'Add Leave Type';
    new bootstrap.Modal(document.getElementById('ltModal')).show();
}

function openAddHoliday(date, existingName, existingId, existingType) {
    document.getElementById('hol_date').value = date;
    document.getElementById('hol_name').value = existingName || '';
    document.getElementById('hol_type').value = existingType || 'NATIONAL';
    document.getElementById('hol_remove_id').value = existingId || '';
    const removeSection = document.getElementById('hol_remove_section');
    removeSection.classList.toggle('d-none', !existingId);
    document.getElementById('holModalTitle').textContent = existingId ? 'Edit Holiday' : 'Mark Holiday';
    new bootstrap.Modal(document.getElementById('holidayModal')).show();
}
</script>

<?php include 'footer.php'; ?>

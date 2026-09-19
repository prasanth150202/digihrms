<?php
// Team view of the Report tab. Included from tasks.php, where $rep, $r_can_sync and the
// .wsr-* styles are already set up — not a page of its own.
if (!isset($rep) || empty($rep['team'])) { http_response_code(404); exit; }

$tv_names = array_column($rep['people'], 'name', 'id');
$tv_rows  = [];
$tv_tot   = ['worked' => 0.0, 'active' => 0.0, 'covered' => 0, 'unsynced' => 0, 'idle_people' => 0];

foreach ($rep['members'] as $pid => $pd) {
    $cov = (int)$pd['sum']['covered'];
    $act = $pd['active_total'] * 3600;
    $top = null;
    foreach ($pd['sum']['tasks'] as $tid => $secs) { $top = $tid; break; } // already sorted desc
    $row = [
        'id' => $pid, 'name' => $tv_names[$pid] ?? ('User #' . $pid),
        'worked' => $pd['worked_total'] * 3600, 'active' => $act, 'idle' => $pd['idle_total'] * 3600,
        'covered' => $cov, 'pct' => $act > 0 ? min(999, round($cov / $act * 100)) : null,
        'open' => (int)($pd['sum']['max_open'] ?? 0), 'tasks' => count($pd['sum']['tasks']),
        'top' => $top, 'unsynced' => count($pd['sum']['unverified']), 'auto' => !empty($pd['auto']),
    ];
    $tv_rows[] = $row;
    $tv_tot['worked']   += $row['worked'];
    $tv_tot['active']   += $act;
    $tv_tot['covered']  += $cov;
    $tv_tot['unsynced'] += $row['unsynced'] ? 1 : 0;
    if ($row['worked'] > 0 && $cov === 0) $tv_tot['idle_people']++;
}
// Most time on tasks first; people with nothing at all sink to the bottom.
usort($tv_rows, fn($a, $b) => [$b['covered'], $b['worked']] <=> [$a['covered'], $a['worked']]);
$tv_pct = $tv_tot['active'] > 0 ? min(999, round($tv_tot['covered'] / $tv_tot['active'] * 100)) : null;
$tv_link = fn($pid) => '?tab=report&amp;user=' . (int)$pid . '&amp;from=' . urlencode($rep['from']) . '&amp;to=' . urlencode($rep['to']);
?>

<?php if (!$rep['att_ok']): ?>
<div class="wsr-warn"><i class="bi bi-info-circle me-1"></i>TeamLogger activity could not be read, so only tracked task time is shown.</div>
<?php endif; ?>

<?php if ($tv_tot['unsynced']): ?>
<div class="wsr-warn">
    <i class="bi bi-question-circle me-1"></i>
    <strong><?= $tv_tot['unsynced'] ?> of <?= count($tv_rows) ?> people</strong> have days with no TeamLogger activity yet.
    Those days count nothing until they are synced<?= $r_can_sync ? ' — use Sync TeamLogger above' : '' ?>.
</div>
<?php endif; ?>

<div class="wsr-tiles">
    <div class="wsr-tile">
        <div class="v"><?= $tv_tot['worked'] > 0 ? fmt_hm((int)$tv_tot['worked']) : '—' ?></div>
        <div class="l">Team worked</div>
        <div class="s">From TeamLogger, everyone added up</div>
    </div>
    <div class="wsr-tile accent">
        <div class="v"><?= fmt_hm($tv_tot['covered']) ?></div>
        <div class="l">Time on tasks</div>
        <div class="s">Clipped to TeamLogger's recording</div>
    </div>
    <div class="wsr-tile">
        <div class="v"><?= $tv_tot['active'] > 0 ? fmt_hm((int)$tv_tot['active']) : '—' ?></div>
        <div class="l">TeamLogger active</div>
        <div class="s">Measured activity for the same days</div>
    </div>
    <div class="wsr-tile">
        <div class="v"><?= $tv_pct === null ? '—' : $tv_pct . '%' ?></div>
        <div class="l">Accounted for</div>
        <div class="s">Active time explained by a task</div>
    </div>
    <div class="wsr-tile">
        <div class="v"><?= $tv_tot['idle_people'] ?: '—' ?></div>
        <div class="l">Worked, no task</div>
        <div class="s">People logged in with nothing In&nbsp;Progress</div>
    </div>
</div>

<div class="wsr-card">
    <h6>By person</h6>
    <div style="overflow-x:auto;">
    <table class="wsr-table">
        <thead><tr>
            <th>Person</th><th class="num">Worked</th><th class="num">TL active</th><th class="num">TL idle</th>
            <th class="num">On tasks</th><th class="num">Accounted</th><th class="num">Tasks</th>
            <th class="num" title="Most cards in In Progress at the same moment">Open at once</th><th>Top task</th>
        </tr></thead>
        <tbody>
        <?php foreach ($tv_rows as $r): ?>
            <tr>
                <td>
                    <a href="<?= $tv_link($r['id']) ?>"><?= sanitize($r['name']) ?></a>
                    <?php if ($r['unsynced']): ?>
                    <span style="color:#f59e0b;" title="<?= $r['unsynced'] ?> day(s) with no TeamLogger data — not counted">*</span>
                    <?php endif; ?>
                    <?php if ($r['auto']): ?>
                    <i class="bi bi-exclamation-triangle-fill ms-1" style="color:#f59e0b;font-size:.72rem;" title="Includes a timer closed automatically — end time estimated"></i>
                    <?php endif; ?>
                </td>
                <td class="num"><?= fmt_hm((int)$r['worked']) ?></td>
                <td class="num"><?= fmt_hm((int)$r['active']) ?></td>
                <td class="num"><?= fmt_hm((int)$r['idle']) ?></td>
                <td class="num"><strong><?= fmt_hm($r['covered']) ?></strong></td>
                <td class="num"<?= $r['pct'] !== null && $r['pct'] < 50 ? ' style="color:#dc2626;"' : '' ?>>
                    <?= $r['pct'] === null ? '—' : $r['pct'] . '%' ?>
                </td>
                <td class="num"><?= $r['tasks'] ?: '—' ?></td>
                <td class="num"<?= $r['open'] > 2 ? ' style="color:#b45309;" title="Time is split between these cards"' : '' ?>>
                    <?= $r['open'] ?: '—' ?>
                </td>
                <td class="wsr-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php if ($r['top'] !== null): ?>
                    <a href="task_detail.php?id=<?= (int)$r['top'] ?>" style="font-weight:500;"><?= sanitize($rep['titles'][$r['top']] ?? ('Task #' . $r['top'])) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="wsr-muted" style="margin-top:9px;">
        Click a name for that person's full report. <span style="color:#f59e0b;">*</span> has days not yet synced from TeamLogger.
        Accounted under 50% is shown in red.
    </div>
</div>

<div class="wsr-card">
    <h6>Day by day — time on tasks</h6>
    <div style="overflow-x:auto;">
    <table class="wsr-table">
        <thead><tr>
            <th>Person</th>
            <?php foreach ($rep['days'] as $d): ?>
            <th class="num"><?= date('D d', strtotime($d)) ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($tv_rows as $r): $pd = $rep['members'][$r['id']]; ?>
            <tr>
                <td><?= sanitize($r['name']) ?></td>
                <?php foreach ($rep['days'] as $d):
                    $day = $pd['sum']['days'][$d] ?? null;
                    $wk  = (float)($pd['worked'][$d] ?? 0);
                ?>
                <td class="num" title="Worked <?= $wk > 0 ? fmt_hm((int)round($wk * 3600)) : 'nothing' ?> per TeamLogger">
                    <?php if (!empty($day['raw']) && empty($day['verified'])): ?>
                    <span style="color:#f59e0b;">*</span>
                    <?php elseif (!empty($day['covered'])): ?>
                    <?= fmt_hm($day['covered']) ?>
                    <?php elseif ($wk > 0): ?>
                    <span style="color:#dc2626;">0</span>
                    <?php else: ?>
                    <span class="wsr-muted">—</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="wsr-muted" style="margin-top:9px;">
        Hover a cell for the hours worked that day. A red <span style="color:#dc2626;">0</span> means logged in but no card In&nbsp;Progress;
        <span style="color:#f59e0b;">*</span> means TeamLogger is not synced for that day.
    </div>
</div>

<?php
// Every task anyone in the selection spent time on. A task can carry time from more than one
// person (reassigned, or shared), so their shares are added and the names listed.
$tv_tasks = [];
foreach ($rep['members'] as $pid => $pd) {
    foreach ($pd['sum']['tasks'] as $tid => $secs) {
        if (!isset($tv_tasks[$tid])) $tv_tasks[$tid] = ['secs' => 0, 'people' => []];
        $tv_tasks[$tid]['secs'] += $secs;
        $tv_tasks[$tid]['people'][$pid] = ($tv_tasks[$tid]['people'][$pid] ?? 0) + $secs;
    }
}
uasort($tv_tasks, fn($a, $b) => $b['secs'] <=> $a['secs']);
$tv_auto = [];
foreach ($rep['members'] as $pd) $tv_auto += $pd['auto'];
$tv_status_color = ['IN_PROGRESS' => '#2563eb', 'DONE' => '#16a34a', 'REVIEW' => '#7c3aed',
                    'BLOCKED' => '#dc2626', 'REWORK' => '#ea580c', 'TODO' => '#64748b'];
?>

<div class="wsr-card">
    <h6>By task</h6>
    <?php if (!$tv_tasks): ?>
    <div class="wsr-muted">Nothing was tracked in this range.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="wsr-table">
        <thead><tr>
            <th>Task</th><th>Project</th><th>Person</th><th>Status now</th>
            <th class="num">Time</th><th class="num">Share</th>
        </tr></thead>
        <tbody>
        <?php foreach ($tv_tasks as $tid => $tk):
            arsort($tk['people']);
            $st = $rep['status'][$tid] ?? '';
        ?>
            <tr>
                <td>
                    <a href="task_detail.php?id=<?= (int)$tid ?>"><?= sanitize($rep['titles'][$tid] ?? ('Task #' . $tid)) ?></a>
                    <?php if (isset($tv_auto[$tid])): ?>
                    <i class="bi bi-exclamation-triangle-fill ms-1" style="color:#f59e0b;font-size:.72rem;" title="Includes a timer closed automatically — end time estimated"></i>
                    <?php endif; ?>
                </td>
                <td class="wsr-muted"><?= sanitize($rep['proj_name'][$rep['task_proj'][$tid] ?? ''] ?? 'No project') ?></td>
                <td>
                    <?php foreach ($tk['people'] as $pid => $psecs): ?>
                    <div style="white-space:nowrap;"<?= count($tk['people']) > 1 ? ' title="' . fmt_hm($psecs) . '"' : '' ?>>
                        <a href="<?= $tv_link($pid) ?>" style="font-weight:500;"><?= sanitize($tv_names[$pid] ?? ('User #' . $pid)) ?></a>
                        <?php if (count($tk['people']) > 1): ?><span class="wsr-muted"> · <?= fmt_hm($psecs) ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if ($st): ?>
                    <span style="font-size:.7rem;font-weight:700;color:<?= $tv_status_color[$st] ?? '#64748b' ?>;"><?= sanitize(str_replace('_', ' ', $st)) ?></span>
                    <?php endif; ?>
                </td>
                <td class="num"><strong><?= fmt_hm($tk['secs']) ?></strong></td>
                <td class="num"><?= $tv_tot['covered'] > 0 ? round($tk['secs'] / $tv_tot['covered'] * 100) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="wsr-muted" style="margin-top:9px;">
        Adds up to the time on tasks. Status is the task's status today, not during the range.
    </div>
    <?php endif; ?>
</div>

<div class="wsr-card">
    <h6>By project</h6>
    <?php if (empty($rep['team_projects'])): ?>
    <div class="wsr-muted">Nothing was tracked in this range.</div>
    <?php else: ?>
    <table class="wsr-table">
        <thead><tr><th>Project</th><th class="num">People</th><th class="num">Tasks</th><th class="num">Time</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($rep['team_projects'] as $pk => $pr): ?>
            <tr>
                <td<?= $pk === '' ? ' class="wsr-muted"' : '' ?>><?= sanitize($rep['proj_name'][$pk] ?? 'No project') ?></td>
                <td class="num"><?= count($pr['people']) ?></td>
                <td class="num"><?= (int)$pr['tasks'] ?></td>
                <td class="num"><?= fmt_hm($pr['secs']) ?></td>
                <td class="num"><?= $tv_tot['covered'] > 0 ? round($pr['secs'] / $tv_tot['covered'] * 100) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="wsr-muted" style="margin-top:9px;">
        Adds up to the team's time on tasks. Where someone had several cards open together, their time is split evenly between them.
    </div>
    <?php endif; ?>
</div>

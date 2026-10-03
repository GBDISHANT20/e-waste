<?php
declare(strict_types=1);
/*
 * Compliance calendar: works out what needs attention for the current user.
 * Levels: red = action required / overdue, orange = due soon / attention, green = completed / fine.
 * Financial year runs April–March; quarters are Apr–Jun, Jul–Sep, Oct–Dec, Jan–Mar.
 */

function fy_start_of(string $date): int
{
    $t = strtotime($date);
    return (int) date('n', $t) >= 4 ? (int) date('Y', $t) : (int) date('Y', $t) - 1;
}
function fy_label(int $fyStart): string { return $fyStart . '–' . substr((string) ($fyStart + 1), 2); }
/** [fyStart, quarterNo, label, startDate, endDate] */
function quarter_of(string $date): array
{
    $t = strtotime($date);
    $m = (int) date('n', $t);
    $fy = fy_start_of($date);
    $q = intdiv(($m + 8) % 12, 3) + 1;               // Apr..Jun=1 ... Jan..Mar=4
    $startMonth = [1 => 4, 2 => 7, 3 => 10, 4 => 1][$q];
    $startYear = $q === 4 ? $fy + 1 : $fy;
    $start = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $end = date('Y-m-t', strtotime("$start +2 months"));
    return [$fy, $q, "Q$q " . fy_label($fy), $start, $end];
}
function previous_quarter(string $date): array
{
    [, , , $start] = quarter_of($date);
    return quarter_of(date('Y-m-d', strtotime("$start -1 day")));
}

function days_between(string $from, string $to): int
{
    return (int) round((strtotime($to) - strtotime($from)) / 86400);
}

/** @return array<int, array{level:string,text:string,link:string}> red items first */
function compliance_items(array $u): array
{
    $items = [];
    $add = function (string $level, string $text, string $link) use (&$items) { $items[] = ['level' => $level, 'text' => $text, 'link' => $link]; };
    $role = $u['role'];
    $did = (int) $u['district_id'];
    $today = today();
    $isCollector = $role === 'DISTRICT_COLLECTOR';

    // --- action tracker ---
    $mine = $isCollector ? '' : ' AND (responsible_user_id = ? OR supporting_user_id = ?)';
    $mineArgs = $isCollector ? [] : [$u['id'], $u['id']];
    $a = q_all("SELECT status, deadline FROM actions WHERE district_id = ? AND status IN ('PENDING','SUBMITTED')" . $mine, array_merge([$did], $mineArgs));
    $overdue = $soon = $toVerify = 0;
    foreach ($a as $r) {
        if ($r['status'] === 'SUBMITTED') { $toVerify++; continue; }
        $d = days_between($today, $r['deadline']);
        if ($d < 0) $overdue++; elseif ($d <= 7) $soon++;
    }
    if ($overdue) $add('red', "$overdue action" . ($overdue > 1 ? 's' : '') . ' past the deadline', 'actions.php');
    if ($soon) $add('orange', "$soon action" . ($soon > 1 ? 's' : '') . ' due within 7 days', 'actions.php');
    if ($toVerify && $isCollector) $add('orange', "$toVerify completed action" . ($toVerify > 1 ? 's' : '') . ' waiting for your verification', 'actions.php');

    // --- quarterly review (district collector) ---
    if ($isCollector) {
        [$fy, $qn, $qlabel, , $qend] = quarter_of($today);
        $have = (int) q_val('SELECT COUNT(*) FROM meetings WHERE district_id = ? AND fy_start = ? AND quarter_no = ?', [$did, $fy, $qn]);
        [$pfy, $pqn, $plabel, , $pend] = previous_quarter($today);
        $havePrev = (int) q_val('SELECT COUNT(*) FROM meetings WHERE district_id = ? AND fy_start = ? AND quarter_no = ?', [$did, $pfy, $pqn]);
        // only flag a missed quarter if the district already existed in the system when that quarter ended
        $since = (string) q_val('SELECT DATE(created_at) FROM districts WHERE id = ?', [$did]);
        if (!$havePrev && $since !== '' && $since <= $pend) $add('red', "Quarterly review for $plabel was not recorded", 'meetings.php');
        if ($have) $add('green', "Quarterly review for $qlabel is recorded", 'meetings.php');
        else {
            $left = days_between($today, $qend);
            $add($left <= 30 ? 'orange' : 'info', "Quarterly review for $qlabel not yet held – due by " . ymd($qend) . " ($left days left)", 'meetings.php');
        }
    }

    // --- annual report: due 30 June for the financial year that just ended ---
    if ($isCollector) {
        $prevFy = fy_start_of($today) - 1;                         // report of the last completed year
        $dueDate = ($prevFy + 1) . '-06-30';                       // e.g. FY 2025–26 report due 30 June 2026
        $done = (bool) q_val('SELECT 1 FROM annual_reports WHERE district_id = ? AND fy_start = ?', [$did, $prevFy]);
        if ($done) $add('green', 'Annual report ' . fy_label($prevFy) . ' submitted', 'reports.php');
        else {
            $left = days_between($today, $dueDate);
            if ($left < 0) $add('red', 'Annual report ' . fy_label($prevFy) . ' was due on ' . ymd($dueDate), 'reports.php');
            elseif ($left <= 60) $add('orange', 'Annual report ' . fy_label($prevFy) . ' due by ' . ymd($dueDate) . " ($left days left)", 'reports.php');
            else $add('info', 'Annual report ' . fy_label($prevFy) . ' due by ' . ymd($dueDate), 'reports.php');
        }
    }

    // --- facility documents expiring (collector sees all, MC/sarpanch their area) ---
    [$fs, $fa] = area_scope($u, 'f.ward_id', 'f.village_id');
    $docs = q_all("SELECT d.expires_on, d.title, d.category, f.name FROM documents d JOIN facilities f ON f.id = d.owner_id
                    WHERE d.owner_type = 'FACILITY' AND d.expires_on IS NOT NULL AND d.expires_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                      AND f.district_id = ?" . $fs, array_merge([$did], $fa));
    foreach ($docs as $d) {
        $left = days_between($today, $d['expires_on']);
        $what = (DOC_CATEGORIES[$d['category']] ?? 'Document') . ' of ' . $d['name'];
        if ($left < 0) $add('red', "$what expired on " . ymd($d['expires_on']), 'facilities.php');
        else $add('orange', "$what expires in $left day" . ($left === 1 ? '' : 's'), 'facilities.php');
    }

    // --- facilities not inspected for 6 months ---
    $fac = q_all("SELECT f.name, (SELECT MAX(i.inspected_on) FROM inspections i WHERE i.facility_id = f.id) AS last
                    FROM facilities f WHERE f.district_id = ? AND f.type NOT IN ('OPEN_DUMP','OPEN_BURNING','LEGACY_SITE')
                     AND f.status <> 'UNDER_CONSTRUCTION'" . $fs, array_merge([$did], $fa));
    $stale = array_filter($fac, fn($r) => $r['last'] === null || days_between($r['last'], $today) > 180);
    if ($stale) $add('orange', count($stale) . ' facilit' . (count($stale) > 1 ? 'ies' : 'y') . ' not inspected in the last 6 months', 'inspections.php');

    // --- wards / villages that sent no waste data for 7 days ---
    $missing = 0;
    foreach (visible_wards($u) as $w) {
        $last = q_val('SELECT MAX(entry_date) FROM waste_entries WHERE ward_id = ?', [$w['id']]);
        if ($last === null || days_between($last, $today) > 7) $missing++;
    }
    foreach (visible_villages($u) as $v) {
        $last = q_val('SELECT MAX(entry_date) FROM waste_entries WHERE village_id = ?', [$v['id']]);
        if ($last === null || days_between($last, $today) > 7) $missing++;
    }
    if ($missing) $add('orange', "$missing ward/village area" . ($missing > 1 ? 's have' : ' has') . ' not updated waste data in the last 7 days', 'waste.php');

    // --- complaints ---
    [$cs, $ca] = area_scope($u, 'ward_id', 'village_id');
    $open = q_all("SELECT status, created_at FROM complaints WHERE district_id = ? AND status <> 'CLOSED'" . $cs, array_merge([$did], $ca));
    $old = count(array_filter($open, fn($c) => days_between(substr($c['created_at'], 0, 10), $today) > 7));
    $unassigned = $isCollector ? count(array_filter($open, fn($c) => $c['status'] === 'RECEIVED')) : 0;
    if ($old) $add('red', "$old complaint" . ($old > 1 ? 's' : '') . ' open for more than 7 days', 'complaints.php');
    if ($unassigned) $add('orange', "$unassigned new complaint" . ($unassigned > 1 ? 's' : '') . ' not yet assigned', 'complaints.php');

    // --- non-operational facilities ---
    $down = (int) q_val("SELECT COUNT(*) FROM facilities f WHERE f.district_id = ? AND f.status = 'NON_OPERATIONAL'" . $fs, array_merge([$did], $fa));
    if ($down) $add('red', "$down facilit" . ($down > 1 ? 'ies are' : 'y is') . ' not operational', 'facilities.php');

    // --- open dumping / burning points recorded ---
    $dump = (int) q_val("SELECT COUNT(*) FROM facilities f WHERE f.district_id = ? AND f.type IN ('OPEN_DUMP','OPEN_BURNING')" . $fs, array_merge([$did], $fa));
    if ($dump) $add('red', "$dump open dumping/burning location" . ($dump > 1 ? 's' : '') . ' recorded', 'map.php');

    $order = ['red' => 0, 'orange' => 1, 'info' => 2, 'green' => 3];
    usort($items, fn($x, $y) => $order[$x['level']] <=> $order[$y['level']]);
    return $items;
}

function level_dot(string $level): string
{
    $label = ['red' => 'Action required', 'orange' => 'Attention', 'green' => 'Completed', 'info' => 'Upcoming'][$level] ?? '';
    return '<span class="dot ' . e($level) . '" role="img" aria-label="' . e($label) . '" title="' . e($label) . '"></span>';
}

function render_compliance(array $items, int $limit = 0): void
{
    if (!$items) { echo '<p class="empty">Nothing needs attention right now.</p>'; return; }
    echo '<ul class="todo">';
    foreach ($limit ? array_slice($items, 0, $limit) : $items as $i) {
        echo '<li>', level_dot($i['level']), '<a href="', e($i['link']), '">', e($i['text']), '</a></li>';
    }
    echo '</ul>';
}

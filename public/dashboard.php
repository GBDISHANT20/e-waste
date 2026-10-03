<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$role = $u['role'];
$stats = [];
$items = [];
if ($role === 'STATE_ADMIN') {
    $stats = [
        'Districts' => q_val('SELECT COUNT(*) FROM districts'),
        'Urban wards' => q_val('SELECT COUNT(*) FROM wards'),
        'Villages' => q_val('SELECT COUNT(*) FROM villages'),
        'Registered houses' => q_val('SELECT COUNT(*) FROM households'),
        'Vehicles' => q_val('SELECT COUNT(*) FROM vehicles'),
        'Drivers & staff' => q_val('SELECT COUNT(*) FROM staff'),
        'Facilities' => q_val('SELECT COUNT(*) FROM facilities'),
        'Open complaints' => q_val("SELECT COUNT(*) FROM complaints WHERE status <> 'CLOSED'"),
    ];
} else {
    $d = (int) $u['district_id'];
    [$aw, $aa] = area_scope($u, 'ward_id', 'village_id');
    $n = fn(string $sql, array $a = []) => q_val($sql, array_merge([$d], $a));
    $stats = ['Registered houses' => $n('SELECT COUNT(*) FROM households WHERE district_id = ?' . $aw, $aa),
        'Vehicles active' => $n("SELECT COUNT(*) FROM vehicles WHERE status = 'ACTIVE' AND district_id = ?" . $aw, $aa),
        'Facilities' => $n('SELECT COUNT(*) FROM facilities WHERE district_id = ?' . $aw, $aa)];
    if ($role === 'DISTRICT_COLLECTOR') {
        $stats = ['Urban wards' => $n('SELECT COUNT(*) FROM wards WHERE district_id = ?'), 'Villages' => $n('SELECT COUNT(*) FROM villages WHERE district_id = ?')] + $stats
            + ['Drivers & staff' => $n('SELECT COUNT(*) FROM staff WHERE district_id = ?')];
    }
    $mine = $role === 'DISTRICT_COLLECTOR' ? '' : ' AND (responsible_user_id = ? OR supporting_user_id = ?)';
    $ma = $role === 'DISTRICT_COLLECTOR' ? [] : [$u['id'], $u['id']];
    $stats['Open actions'] = $n("SELECT COUNT(*) FROM actions WHERE district_id = ? AND status <> 'CLOSED'" . $mine, $ma);
    $stats['Open complaints'] = $n("SELECT COUNT(*) FROM complaints WHERE district_id = ? AND status <> 'CLOSED'" . $aw, $aa);
    $items = compliance_items($u);
}
$red = count(array_filter($items, fn($i) => $i['level'] === 'red'));
$orange = count(array_filter($items, fn($i) => $i['level'] === 'orange'));
$perDistrict = $role !== 'STATE_ADMIN' ? [] : q_all(
    "SELECT d.name, d.state,
            (SELECT COUNT(*) FROM wards w WHERE w.district_id = d.id) AS wards,
            (SELECT COUNT(*) FROM villages v WHERE v.district_id = d.id) AS villages,
            (SELECT COUNT(*) FROM vehicles x WHERE x.district_id = d.id) AS vehicles,
            (SELECT COUNT(*) FROM households h WHERE h.district_id = d.id) AS households,
            (SELECT MAX(e.entry_date) FROM waste_entries e WHERE e.district_id = d.id) AS last_data,
            (SELECT COUNT(*) FROM actions a WHERE a.district_id = d.id AND a.status <> 'CLOSED' AND a.deadline < CURDATE()) AS overdue
       FROM districts d ORDER BY d.state, d.name");

page_start('Overview', 'dashboard.php');
echo '<h2>Overview</h2>';
if ($role !== 'STATE_ADMIN') {
    echo '<div class="stats"><div class="stat red"><b>', $red, '</b><span>action required</span></div>',
        '<div class="stat orange"><b>', $orange, '</b><span>due soon</span></div></div>';
    echo '<div class="card"><h3>Needs your attention</h3>';
    render_compliance($items, 8);
    if (count($items) > 8) echo '<p><a href="compliance.php">See all ', count($items), ' items</a></p>';
    echo '</div>';
}
echo '<div class="stats">';
foreach ($stats as $label => $n) echo '<div class="stat"><b>', e($n), '</b><span>', e($label), '</span></div>';
echo '</div>';
if ($role === 'STATE_ADMIN') {
    echo '<div class="card"><h3>By district</h3>';
    render_table(['District' => fn($r) => e($r['name'] . ', ' . $r['state']), 'Wards' => 'wards', 'Villages' => 'villages',
        'Vehicles' => 'vehicles', 'Houses' => 'households',
        'Last waste data' => fn($r) => $r['last_data'] ? e(ymd($r['last_data'])) : badge('no data', 'warn'),
        'Overdue actions' => fn($r) => (int) $r['overdue'] ? badge((int) $r['overdue'], 'err') : badge('0')], $perDistrict, 'No districts registered yet.');
    echo '</div>';
}
page_end();

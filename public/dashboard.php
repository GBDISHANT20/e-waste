<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$stats = [];
if ($u['role'] === 'STATE_ADMIN') {
    $stats = [
        'Districts' => q_val('SELECT COUNT(*) FROM districts'),
        'Urban wards' => q_val('SELECT COUNT(*) FROM wards'),
        'Villages' => q_val('SELECT COUNT(*) FROM villages'),
        'Registered houses' => q_val('SELECT COUNT(*) FROM households'),
        'Vehicles' => q_val('SELECT COUNT(*) FROM vehicles'),
        'Drivers & staff' => q_val('SELECT COUNT(*) FROM staff'),
    ];
} else {
    $d = $u['district_id'];
    [$aw, $aa] = area_scope($u, 'ward_id', 'village_id');
    $stats = ['Registered houses' => q_val('SELECT COUNT(*) FROM households WHERE district_id = ?' . $aw, array_merge([$d], $aa)),
        'Vehicles' => q_val('SELECT COUNT(*) FROM vehicles WHERE district_id = ?' . $aw, array_merge([$d], $aa)),
        'Vehicles active' => q_val("SELECT COUNT(*) FROM vehicles WHERE status = 'ACTIVE' AND district_id = ?" . $aw, array_merge([$d], $aa))];
    if ($u['role'] === 'DISTRICT_COLLECTOR') {
        $stats = ['Urban wards' => q_val('SELECT COUNT(*) FROM wards WHERE district_id = ?', [$d]),
            'Villages' => q_val('SELECT COUNT(*) FROM villages WHERE district_id = ?', [$d])] + $stats
            + ['Drivers & staff' => q_val('SELECT COUNT(*) FROM staff WHERE district_id = ?', [$d])];
    }
}
$perDistrict = $u['role'] !== 'STATE_ADMIN' ? [] : q_all(
    "SELECT d.name, d.state,
            (SELECT COUNT(*) FROM wards w WHERE w.district_id = d.id) AS wards,
            (SELECT COUNT(*) FROM villages v WHERE v.district_id = d.id) AS villages,
            (SELECT COUNT(*) FROM vehicles x WHERE x.district_id = d.id) AS vehicles,
            (SELECT COUNT(*) FROM households h WHERE h.district_id = d.id) AS households
       FROM districts d ORDER BY d.state, d.name");

page_start('Overview', 'dashboard.php');
echo '<h2>Overview</h2><div class="stats">';
foreach ($stats as $label => $n) echo '<div class="stat"><b>', e($n), '</b><span>', e($label), '</span></div>';
echo '</div>';
if ($u['role'] === 'STATE_ADMIN') {
    echo '<div class="card"><h3>By district</h3>';
    render_table(['District' => fn($r) => e($r['name'] . ', ' . $r['state']), 'Wards' => 'wards', 'Villages' => 'villages',
        'Vehicles' => 'vehicles', 'Houses' => 'households'], $perDistrict, 'No districts registered yet.');
    echo '</div>';
}
page_end();

<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC');
$canAdd = $u['role'] === 'DISTRICT_COLLECTOR';

if ($canAdd && is_post()) {
    csrf_check();
    try_action(function () use ($u) {
        $did = (int) $u['district_id'];
        $city = v_str($_POST['city'] ?? '', 'City / ULB');
        $no = v_str($_POST['ward_no'] ?? '', 'Ward number', 20);
        $wname = v_str($_POST['ward_name'] ?? '', 'Ward name', 120, false);
        $mcName = v_str($_POST['mc_name'] ?? '', 'MC name');
        $mcPhone = v_phone($_POST['mc_phone'] ?? '', 'MC mobile');
        $res = in_tx(function () use ($did, $city, $no, $wname, $mcName, $mcPhone) {
            $mc = create_user($mcName, $mcPhone, 'MC', $did);
            q('INSERT INTO wards (district_id, city, ward_no, ward_name, mc_user_id) VALUES (?,?,?,?,?)', [$did, $city, $no, $wname, $mc['id']]);
            return ['id' => last_id(), 'pw' => $mc['temp_password']];
        });
        audit('create', 'ward', $res['id'], ['city' => $city, 'ward_no' => $no]);
        flash('ok', temp_password_notice('Ward and MC', $res['pw']), true);
        redirect('wards.php');
    });
}

[$as, $aa] = area_scope($u, 'w.id', 'NULL');
$rows = q_all("SELECT w.id, w.city, w.ward_no, w.ward_name, m.name AS mc_name, m.phone AS mc_phone,
                      (SELECT COUNT(*) FROM households h WHERE h.ward_id = w.id) AS households,
                      (SELECT COUNT(*) FROM vehicles v WHERE v.ward_id = w.id AND v.status = 'ACTIVE') AS vehicles
                 FROM wards w LEFT JOIN users m ON m.id = w.mc_user_id
                WHERE w.district_id = ?" . $as . ' ORDER BY w.city, w.ward_no', array_merge([$u['district_id']], $aa));

page_start($canAdd ? 'Wards & MCs' : 'My wards', 'wards.php');
echo '<h2>', $canAdd ? 'Urban wards &amp; MCs' : 'My wards', '</h2>';
if ($canAdd): ?>
<div class="card">
  <h3>Register a ward and allot its MC</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('city', 'City / ULB', ['required' => true, 'maxlength' => 120]);
    f_input('ward_no', 'Ward number', ['required' => true, 'maxlength' => 20]);
    f_input('ward_name', 'Ward name', ['maxlength' => 120]);
    f_input('mc_name', 'MC name', ['required' => true, 'maxlength' => 120]);
    f_input('mc_phone', 'MC mobile', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric']);
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <?php render_table([
      'City' => 'city', 'Ward' => fn($r) => e($r['ward_no'] . ($r['ward_name'] ? ' – ' . $r['ward_name'] : '')),
      'MC' => 'mc_name', 'MC mobile' => 'mc_phone', 'Houses' => 'households', 'Vehicles' => 'vehicles',
  ], $rows); ?>
</div>
<?php page_end();

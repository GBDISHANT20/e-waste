<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'SARPANCH');
$canAdd = $u['role'] === 'DISTRICT_COLLECTOR';

if ($canAdd && is_post()) {
    csrf_check();
    try_action(function () use ($u) {
        $did = (int) $u['district_id'];
        $name = v_str($_POST['name'] ?? '', 'Village name');
        $block = v_str($_POST['block'] ?? '', 'Block', 120, false);
        $spName = v_str($_POST['sarpanch_name'] ?? '', 'Sarpanch name');
        $spPhone = v_phone($_POST['sarpanch_phone'] ?? '', 'Sarpanch mobile');
        $res = in_tx(function () use ($did, $name, $block, $spName, $spPhone) {
            $sp = create_user($spName, $spPhone, 'SARPANCH', $did);
            q('INSERT INTO villages (district_id, block, name, sarpanch_user_id) VALUES (?,?,?,?)', [$did, $block, $name, $sp['id']]);
            return ['id' => last_id(), 'pw' => $sp['temp_password']];
        });
        audit('create', 'village', $res['id'], ['name' => $name]);
        flash('ok', temp_password_notice('Village and Sarpanch', $res['pw']), true);
        redirect('villages.php');
    });
}

[$as, $aa] = area_scope($u, 'NULL', 'v.id');
$rows = q_all("SELECT v.id, v.block, v.name, s.name AS sarpanch_name, s.phone AS sarpanch_phone,
                      (SELECT COUNT(*) FROM households h WHERE h.village_id = v.id) AS households,
                      (SELECT COUNT(*) FROM vehicles x WHERE x.village_id = v.id AND x.status = 'ACTIVE') AS vehicles
                 FROM villages v LEFT JOIN users s ON s.id = v.sarpanch_user_id
                WHERE v.district_id = ?" . $as . ' ORDER BY v.name', array_merge([$u['district_id']], $aa));

page_start($canAdd ? 'Villages & Sarpanches' : 'My village', 'villages.php');
echo '<h2>', $canAdd ? 'Villages &amp; Sarpanches' : 'My village', '</h2>';
if ($canAdd): ?>
<div class="card">
  <h3>Register a village and its sarpanch</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('name', 'Village name', ['required' => true, 'maxlength' => 120]);
    f_input('block', 'Block', ['maxlength' => 120]);
    f_input('sarpanch_name', 'Sarpanch name', ['required' => true, 'maxlength' => 120]);
    f_input('sarpanch_phone', 'Sarpanch mobile', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric']);
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <?php render_table([
      'Village' => 'name', 'Block' => 'block', 'Sarpanch' => 'sarpanch_name', 'Mobile' => 'sarpanch_phone',
      'Houses' => 'households', 'Vehicles' => 'vehicles',
  ], $rows); ?>
</div>
<?php page_end();

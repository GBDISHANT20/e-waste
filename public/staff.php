<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];

[$vs, $va] = area_scope($u, 'v.ward_id', 'v.village_id');
$vehicles = q_all("SELECT v.id, v.reg_number, v.city, vi.name AS village_name
                     FROM vehicles v LEFT JOIN villages vi ON vi.id = v.village_id
                    WHERE v.district_id = ? AND v.status <> 'RETIRED'" . $vs . ' ORDER BY v.reg_number', array_merge([$did], $va));

if (is_post()) {
    csrf_check();
    try_action(function () use ($u, $did) {
        $name = v_str($_POST['name'] ?? '', 'Name');
        $phone = v_phone($_POST['phone'] ?? '');
        $role = v_enum($_POST['staff_role'] ?? '', STAFF_ROLES, 'role');
        $licence = v_str($_POST['licence_no'] ?? '', 'Licence number', 30, $role === 'DRIVER');
        $vid = v_int($_POST['vehicle_id'] ?? '', 'Vehicle');
        if ($vid) {
            $v = q_one('SELECT ward_id, village_id FROM vehicles WHERE id = ? AND district_id = ?', [$vid, $did]);
            if (!$v) throw new UserError('Vehicle not found in this district');
            if (!can_manage_area($u, $v['ward_id'] === null ? null : (int) $v['ward_id'], $v['village_id'] === null ? null : (int) $v['village_id']))
                throw new UserError('You can only assign vehicles of your own area');
        } elseif ($u['role'] !== 'DISTRICT_COLLECTOR') {
            throw new UserError('Choose a vehicle from your area');
        }
        $res = in_tx(function () use ($did, $name, $phone, $role, $licence, $vid) {
            $user = create_user($name, $phone, 'STAFF', $did);
            q('INSERT INTO staff (user_id, district_id, staff_role, licence_no, vehicle_id) VALUES (?,?,?,?,?)', [$user['id'], $did, $role, $licence, $vid]);
            return ['id' => last_id(), 'pw' => $user['temp_password']];
        });
        audit('create', 'staff', $res['id'], ['role' => $role]);
        flash('ok', temp_password_notice(STAFF_ROLES[$role], $res['pw']), true);
        redirect('staff.php');
    });
}

[$ss, $sa] = area_scope($u, 'v.ward_id', 'v.village_id');
$rows = q_all("SELECT s.staff_role, s.licence_no, us.name, us.phone, v.reg_number
                 FROM staff s JOIN users us ON us.id = s.user_id LEFT JOIN vehicles v ON v.id = s.vehicle_id
                WHERE s.district_id = ?" . $ss . ' ORDER BY us.name', array_merge([$did], $sa));

page_start('Drivers & staff', 'staff.php');
?>
<h2>Drivers &amp; staff</h2>
<div class="card">
  <h3>Register a driver / helper</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('name', 'Full name', ['required' => true, 'maxlength' => 120]);
    f_input('phone', 'Mobile', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric']);
    f_select('staff_role', 'Role', STAFF_ROLES, ['required' => true]);
    f_input('licence_no', 'Driving licence no. (drivers)', ['maxlength' => 30]);
    f_select('vehicle_id', 'Assign vehicle', array_column(array_map(fn($v) => ['id' => $v['id'], 'l' => $v['reg_number'] . ' – ' . ($v['city'] ?? $v['village_name'] ?? 'unassigned')], $vehicles), 'l', 'id'),
        ['required' => $u['role'] !== 'DISTRICT_COLLECTOR']);
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
</div>
<div class="card">
  <?php render_table([
      'Name' => 'name', 'Mobile' => 'phone', 'Role' => fn($r) => e(STAFF_ROLES[$r['staff_role']]),
      'Licence' => 'licence_no', 'Vehicle' => 'reg_number',
  ], $rows); ?>
</div>
<?php page_end();

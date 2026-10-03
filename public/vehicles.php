<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$wards = visible_wards($u);
$villages = visible_villages($u);

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'add';
    if ($action === 'status') {
        try_action(function () use ($u, $did) {
            $id = v_int($_POST['id'] ?? '', 'Vehicle', true);
            $status = v_enum($_POST['status'] ?? '', VEHICLE_STATUSES, 'status');
            $v = q_one('SELECT id, ward_id, village_id, status FROM vehicles WHERE id = ? AND district_id = ?', [$id, $did]);
            if (!$v) throw new UserError('Vehicle not found');
            if (!can_manage_area($u, $v['ward_id'] === null ? null : (int) $v['ward_id'], $v['village_id'] === null ? null : (int) $v['village_id']))
                throw new UserError('You can only change vehicles of your own area');
            q('UPDATE vehicles SET status = ? WHERE id = ?', [$status, $id]);
            audit('update_status', 'vehicle', $id, ['from' => $v['status'], 'to' => $status]);
            flash('ok', 'Vehicle status updated.');
            redirect('vehicles.php');
        });
    } else {
        try_action(function () use ($u, $did) {
            $reg = strtoupper(preg_replace('/[\s-]+/', '', v_str($_POST['reg_number'] ?? '', 'Registration number', 20)));
            if (!preg_match('/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$/', $reg)) throw new UserError('Registration number looks invalid (example: HR16AB1234)');
            $type = v_enum($_POST['type'] ?? '', VEHICLE_TYPES, 'vehicle type');
            $cap = v_int($_POST['capacity_kg'] ?? '', 'Capacity');
            $wardId = v_int($_POST['ward_id'] ?? '', 'Ward');
            $villageId = v_int($_POST['village_id'] ?? '', 'Village');
            if ($wardId && $villageId) throw new UserError('Choose either a ward or a village, not both');
            if (!$wardId && !$villageId && $u['role'] !== 'DISTRICT_COLLECTOR') throw new UserError('Choose your ward or village');
            $city = null;
            if ($wardId) {
                $w = q_one('SELECT city FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did]);
                if (!$w) throw new UserError('Ward not found in this district');
                $city = $w['city'];
            }
            if ($villageId && !q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Village not found in this district');
            if (($wardId || $villageId || $u['role'] !== 'DISTRICT_COLLECTOR') && !can_manage_area($u, $wardId, $villageId))
                throw new UserError('You can only register vehicles for your own ward or village');
            q('INSERT INTO vehicles (district_id, city, reg_number, type, capacity_kg, ward_id, village_id) VALUES (?,?,?,?,?,?,?)',
                [$did, $city, $reg, $type, $cap, $wardId, $villageId]);
            audit('create', 'vehicle', last_id(), ['reg' => $reg]);
            flash('ok', "Vehicle $reg registered.");
            redirect('vehicles.php');
        });
    }
}

[$as, $aa] = area_scope($u, 'v.ward_id', 'v.village_id');
$rows = q_all("SELECT v.*, w.ward_no, vi.name AS village_name,
                      (SELECT GROUP_CONCAT(CONCAT(us.name, ' (', s.staff_role, ')') SEPARATOR ', ')
                         FROM staff s JOIN users us ON us.id = s.user_id WHERE s.vehicle_id = v.id) AS crew
                 FROM vehicles v
                 LEFT JOIN wards w ON w.id = v.ward_id
                 LEFT JOIN villages vi ON vi.id = v.village_id
                WHERE v.district_id = ?" . $as . ' ORDER BY v.city, v.reg_number', array_merge([$did], $aa));

page_start('Vehicles', 'vehicles.php');
?>
<h2>Garbage collection vehicles</h2>
<div class="card">
  <h3>Register a vehicle</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?php
    f_input('reg_number', 'Registration number', ['required' => true, 'maxlength' => 20, 'placeholder' => 'HR16AB1234']);
    f_select('type', 'Vehicle type', VEHICLE_TYPES, ['required' => true]);
    f_input('capacity_kg', 'Capacity (kg)', ['type' => 'number', 'min' => 0, 'inputmode' => 'numeric']);
    if ($wards) f_select('ward_id', 'Urban ward (city)', array_column(array_map(fn($w) => ['id' => $w['id'], 'l' => ward_label($w)], $wards), 'l', 'id'));
    if ($villages) f_select('village_id', 'Village (rural)', array_column($villages, 'name', 'id'));
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
  <p class="muted small-text">A vehicle serves either a city ward or a village.</p>
</div>
<div class="card">
  <?php render_table([
      'Reg. no.' => 'reg_number',
      'Type' => fn($r) => e(VEHICLE_TYPES[$r['type']]),
      'Capacity' => fn($r) => $r['capacity_kg'] ? e($r['capacity_kg']) . ' kg' : '–',
      'Area' => fn($r) => $r['ward_id'] ? e($r['city'] . ' – Ward ' . $r['ward_no']) : ($r['village_name'] ? e($r['village_name']) : '–'),
      'Crew' => fn($r) => $r['crew'] ? e($r['crew']) : '–',
      'Status' => fn($r) => badge(VEHICLE_STATUSES[$r['status']], $r['status'] === 'ACTIVE' ? '' : ($r['status'] === 'MAINTENANCE' ? 'warn' : 'err')),
      'Change' => function ($r) {
          if ($r['status'] === 'RETIRED') return '';
          $o = '';
          foreach (VEHICLE_STATUSES as $k => $l) if ($k !== $r['status']) $o .= '<option value="' . e($k) . '">' . e($l) . '</option>';
          return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . e($r['id']) . '">'
              . '<select name="status" aria-label="New status">' . $o . '</select><button class="btn small" type="submit">Set</button></form>';
      },
  ], $rows); ?>
</div>
<?php page_end();

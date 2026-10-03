<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STAFF', 'MC', 'SARPANCH', 'DISTRICT_COLLECTOR');
$did = (int) $u['district_id'];
$isStaff = $u['role'] === 'STAFF';

$myVehicle = $isStaff ? q_one("SELECT v.* FROM staff s JOIN vehicles v ON v.id = s.vehicle_id WHERE s.user_id = ? AND v.status <> 'RETIRED'", [$u['id']]) : null;
[$vs, $va] = area_scope($u, 'v.ward_id', 'v.village_id');
$vehicles = $isStaff ? ($myVehicle ? [$myVehicle] : [])
    : q_all("SELECT v.id, v.reg_number, v.city, v.ward_id, v.village_id FROM vehicles v WHERE v.district_id = ? AND v.status <> 'RETIRED'" . $vs . ' ORDER BY v.reg_number', array_merge([$did], $va));
$wards = $isStaff ? [] : visible_wards($u);
$villages = $isStaff ? [] : visible_villages($u);
$facilities = q_all("SELECT id, name, type, capacity_tpd FROM facilities WHERE district_id = ? AND type NOT IN ('OPEN_DUMP','OPEN_BURNING') AND status <> 'UNDER_CONSTRUCTION' ORDER BY name", [$did]);

if (is_post()) {
    csrf_check();
    try_action(function () use ($u, $did, $isStaff, $myVehicle, $facilities) {
        $min = $isStaff ? date('Y-m-d', strtotime('-7 days')) : null;
        $date = v_date($_POST['entry_date'] ?? '', 'Date', true, $min, today());
        $wardId = $villageId = $vehicleId = null;
        if ($isStaff) {
            if (!$myVehicle) throw new UserError('No vehicle is assigned to you yet. Ask your officer to assign one.');
            $vehicleId = (int) $myVehicle['id'];
        } else {
            $vehicleId = v_int($_POST['vehicle_id'] ?? '', 'Vehicle');
            $wardId = v_int($_POST['ward_id'] ?? '', 'Ward');
            $villageId = v_int($_POST['village_id'] ?? '', 'Village');
        }
        if ($vehicleId) {
            $v = q_one('SELECT id, ward_id, village_id FROM vehicles WHERE id = ? AND district_id = ?', [$vehicleId, $did]);
            if (!$v) throw new UserError('Vehicle not found');
            $vw = $v['ward_id'] === null ? null : (int) $v['ward_id'];
            $vv = $v['village_id'] === null ? null : (int) $v['village_id'];
            if (!$isStaff && !can_manage_area($u, $vw, $vv)) throw new UserError('You can only record data for vehicles of your own area');
            if ($vw || $vv) { $wardId = $vw; $villageId = $vv; }
        }
        if ($wardId && $villageId) throw new UserError('Choose either a ward or a village, not both');
        if (!$wardId && !$villageId) throw new UserError('Choose the vehicle or the ward/village this data is for');
        if ($wardId && !q_val('SELECT 1 FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did])) throw new UserError('Ward not found');
        if ($villageId && !q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Village not found');
        if (!$isStaff && !can_manage_area($u, $wardId, $villageId)) throw new UserError('You can only record data for your own ward or village');

        $facilityId = v_int($_POST['facility_id'] ?? '', 'Facility');
        $fac = null;
        if ($facilityId) {
            foreach ($facilities as $f) if ((int) $f['id'] === $facilityId) $fac = $f;
            if (!$fac) throw new UserError('Facility not found');
        }
        $q = [];
        foreach (['generated_kg' => 'Waste generated', 'collected_kg' => 'Waste collected', 'wet_kg' => 'Wet waste', 'dry_kg' => 'Dry waste',
                     'mixed_kg' => 'Mixed waste', 'processed_kg' => 'Processed', 'landfilled_kg' => 'Landfilled'] as $k => $label) {
            $q[$k] = v_dec($_POST[$k] ?? '', $label, $k === 'collected_kg');
        }
        $slip = v_str($_POST['slip_no'] ?? '', 'Weighbridge slip no.', 40, false);
        $notes = v_str($_POST['notes'] ?? '', 'Notes', 250, false);
        [$lat, $lng] = v_coords($_POST['lat'] ?? '', $_POST['lng'] ?? '');
        check_upload('photo', true);
        $flags = waste_flags($q, $fac);
        $id = in_tx(function () use ($u, $did, $date, $wardId, $villageId, $vehicleId, $facilityId, $q, $slip, $notes, $lat, $lng, $flags) {
            q('INSERT INTO waste_entries (district_id, entry_date, ward_id, village_id, vehicle_id, facility_id, generated_kg, collected_kg, wet_kg, dry_kg, mixed_kg,
                                         processed_kg, landfilled_kg, slip_no, notes, lat, lng, flags, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$did, $date, $wardId, $villageId, $vehicleId, $facilityId, $q['generated_kg'], $q['collected_kg'], $q['wet_kg'], $q['dry_kg'], $q['mixed_kg'],
                    $q['processed_kg'], $q['landfilled_kg'], $slip, $notes, $lat, $lng, $flags ? implode('; ', $flags) : null, $u['id']]);
            $eid = last_id();
            $photo = save_upload('photo', $did, 'WASTE', $eid, 'PHOTO', null, null, true);
            if ($photo) q('UPDATE waste_entries SET photo_doc_id = ? WHERE id = ?', [$photo, $eid]);
            return $eid;
        });
        audit('create', 'waste_entry', $id);
        flash('ok', 'Waste data saved.');
        if ($flags) flash('warn', 'Please verify the data: ' . implode('; ', $flags) . '.');
        redirect('waste.php');
    });
}

// ---------- list ----------
$from = v_date($_GET['from'] ?? '', 'From', false) ?? date('Y-m-d', strtotime('-30 days'));
$to = v_date($_GET['to'] ?? '', 'To', false) ?? today();
if ($isStaff) { $scope = ' AND e.created_by = ?'; $sa = [$u['id']]; }
else { [$scope, $sa] = area_scope($u, 'e.ward_id', 'e.village_id'); }
$base = ' FROM waste_entries e LEFT JOIN wards w ON w.id = e.ward_id LEFT JOIN villages vi ON vi.id = e.village_id
          LEFT JOIN vehicles v ON v.id = e.vehicle_id LEFT JOIN users us ON us.id = e.created_by
          WHERE e.district_id = ? AND e.entry_date BETWEEN ? AND ?' . $scope;
$args = array_merge([$did, $from, $to], $sa);
$tot = q_one('SELECT COALESCE(SUM(e.collected_kg),0) c, COALESCE(SUM(e.wet_kg),0) w, COALESCE(SUM(e.dry_kg),0) d, COALESCE(SUM(e.processed_kg),0) p,
                     COALESCE(SUM(e.landfilled_kg),0) l, SUM(e.flags IS NOT NULL) f, COUNT(*) n' . $base, $args);
$rows = q_all('SELECT e.*, w.city, w.ward_no, vi.name AS village_name, v.reg_number, us.name AS by_name' . $base . ' ORDER BY e.entry_date DESC, e.id DESC LIMIT 300', $args);

page_start($isStaff ? 'Enter waste data' : 'Waste data', 'waste.php');
?>
<h2>Daily waste data</h2>
<div class="card">
  <h3>Add today's data</h3>
  <?php if ($isStaff && !$myVehicle): ?>
    <p class="notice warn">No vehicle is assigned to you yet. Ask your officer to assign one.</p>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('entry_date', 'Date', ['type' => 'date', 'required' => true, 'value' => today(), 'max' => today()]);
    if ($isStaff) {
        echo '<div class="field"><label>Vehicle</label><input value="', e($myVehicle['reg_number']), '" disabled></div>';
    } else {
        f_select('vehicle_id', 'Vehicle', array_column(array_map(fn($v) => ['id' => $v['id'], 'l' => $v['reg_number'] . ' – ' . ($v['city'] ?? 'village')], $vehicles), 'l', 'id'));
        if ($wards) f_select('ward_id', 'Ward (if no vehicle)', array_column(array_map(fn($w) => ['id' => $w['id'], 'l' => ward_label($w)], $wards), 'l', 'id'));
        if ($villages) f_select('village_id', 'Village (if no vehicle)', array_column($villages, 'name', 'id'));
    }
    f_input('generated_kg', 'Waste generated (kg)', ['inputmode' => 'decimal']);
    f_input('collected_kg', 'Waste collected (kg)', ['inputmode' => 'decimal', 'required' => true]);
    f_input('wet_kg', 'Wet waste (kg)', ['inputmode' => 'decimal']);
    f_input('dry_kg', 'Dry waste (kg)', ['inputmode' => 'decimal']);
    f_input('mixed_kg', 'Mixed waste (kg)', ['inputmode' => 'decimal']);
    f_select('facility_id', 'Delivered to facility', array_column($facilities, 'name', 'id'));
    f_input('processed_kg', 'Processed (kg)', ['inputmode' => 'decimal']);
    f_input('landfilled_kg', 'Landfilled (kg)', ['inputmode' => 'decimal']);
    f_input('slip_no', 'Weighbridge slip no.', ['maxlength' => 40]);
    f_input('notes', 'Notes', ['maxlength' => 250]);
    f_file('photo', 'Photo', ['images' => true, 'camera' => true]);
    f_geo();
    ?>
    <div class="field actions"><button class="btn" type="submit">Save</button></div>
  </form>
  <p class="muted small-text">If the numbers do not add up the system still saves them but asks you to verify.</p>
  <?php endif; ?>
</div>

<div class="stats">
  <div class="stat"><b><?= e(number_format((float) $tot['c'], 0)) ?></b><span>kg collected</span></div>
  <div class="stat"><b><?= e(number_format((float) $tot['w'], 0)) ?></b><span>kg wet</span></div>
  <div class="stat"><b><?= e(number_format((float) $tot['d'], 0)) ?></b><span>kg dry</span></div>
  <div class="stat"><b><?= e(number_format((float) $tot['p'], 0)) ?></b><span>kg processed</span></div>
  <div class="stat"><b><?= e(number_format((float) $tot['l'], 0)) ?></b><span>kg landfilled</span></div>
  <div class="stat <?= (int) $tot['f'] ? 'orange' : '' ?>"><b><?= e((int) $tot['f']) ?></b><span>to verify</span></div>
</div>
<div class="card">
  <form method="get" class="toolbar no-print">
    <?php f_input('from', 'From', ['type' => 'date', 'value' => $from]); f_input('to', 'To', ['type' => 'date', 'value' => $to]); ?>
    <button class="btn secondary" type="submit">Show</button>
  </form>
  <?php render_table([
      'Date' => fn($r) => e(ymd($r['entry_date'])),
      'Area' => fn($r) => $r['city'] ? e($r['city'] . ' – Ward ' . $r['ward_no']) : e($r['village_name']),
      'Vehicle' => 'reg_number',
      'Collected (kg)' => 'collected_kg', 'Wet' => 'wet_kg', 'Dry' => 'dry_kg', 'Mixed' => 'mixed_kg',
      'Processed' => 'processed_kg', 'Landfilled' => 'landfilled_kg',
      'Check' => fn($r) => $r['flags'] ? badge('verify', 'warn') . '<div class="small-text muted">' . e($r['flags']) . '</div>' : badge('ok'),
      'Photo' => fn($r) => doc_thumb($r['photo_doc_id'] ? (int) $r['photo_doc_id'] : null),
      'By' => 'by_name',
  ], $rows, 'No waste data in this period.'); ?>
</div>
<?php page_end();

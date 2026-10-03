<?php
// Informal waste pickers / collectors – so they can be recognised and linked to MRFs.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$wards = visible_wards($u);
$villages = visible_villages($u);
$facilities = q_all("SELECT id, name FROM facilities WHERE district_id = ? AND type IN ('MRF','RECYCLING','TRANSFER_STATION') ORDER BY name", [$did]);

if (is_post()) {
    csrf_check();
    try_action(function () use ($u, $did, $facilities) {
        $name = v_str($_POST['name'] ?? '', 'Name');
        $phone = ($_POST['phone'] ?? '') === '' ? null : v_phone($_POST['phone']);
        $wardId = v_int($_POST['ward_id'] ?? '', 'Ward');
        $villageId = v_int($_POST['village_id'] ?? '', 'Village');
        if ($wardId && $villageId) throw new UserError('Choose either a ward or a village, not both');
        if (!$wardId && !$villageId && $u['role'] !== 'DISTRICT_COLLECTOR') throw new UserError('Choose your ward or village');
        if ($wardId && !q_val('SELECT 1 FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did])) throw new UserError('Ward not found');
        if ($villageId && !q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Village not found');
        if (($wardId || $villageId) && !can_manage_area($u, $wardId, $villageId)) throw new UserError('You can only register pickers of your own area');
        $age = v_int($_POST['age'] ?? '', 'Age', false, 100);
        if ($age !== null && $age < 14) throw new UserError('Age must be 14 or more');
        $gender = ($_POST['gender'] ?? '') === '' ? null : v_enum($_POST['gender'], GENDERS, 'gender');
        $category = v_str($_POST['work_category'] ?? '', 'Category of work', 80, false);
        $area = v_str($_POST['collection_area'] ?? '', 'Collection area', 160, false);
        $org = v_str($_POST['organization'] ?? '', 'Organisation / SHG', 120, false);
        $materials = v_str($_POST['materials'] ?? '', 'Material handled', 160, false);
        $qty = v_dec($_POST['approx_kg_per_day'] ?? '', 'Approximate quantity', false, 100000);
        $fid = v_int($_POST['facility_id'] ?? '', 'Linked MRF');
        if ($fid && !in_array($fid, array_map('intval', array_column($facilities, 'id')), true)) throw new UserError('Facility not found');
        $status = v_enum($_POST['registration_status'] ?? 'PENDING', PICKER_STATUSES, 'registration status');
        q('INSERT INTO waste_pickers (district_id, name, phone, ward_id, village_id, age, gender, work_category, collection_area, organization, materials, approx_kg_per_day,
                                      facility_id, trained, has_ppe, registration_status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$did, $name, $phone, $wardId, $villageId, $age, $gender, $category, $area, $org, $materials, $qty, $fid,
                isset($_POST['trained']) ? 1 : 0, isset($_POST['has_ppe']) ? 1 : 0, $status, $u['id']]);
        audit('create', 'waste_picker', last_id());
        flash('ok', "Waste picker $name registered.");
        redirect('waste-pickers.php');
    });
}

[$as, $aa] = area_scope($u, 'p.ward_id', 'p.village_id');
$rows = q_all("SELECT p.*, w.city, w.ward_no, vi.name AS village_name, f.name AS facility
                 FROM waste_pickers p LEFT JOIN wards w ON w.id = p.ward_id LEFT JOIN villages vi ON vi.id = p.village_id LEFT JOIN facilities f ON f.id = p.facility_id
                WHERE p.district_id = ?" . $as . ' ORDER BY p.name LIMIT 500', array_merge([$did], $aa));
$yn = fn($v) => $v ? badge('yes') : badge('no', 'warn');

page_start('Waste pickers', 'waste-pickers.php');
?>
<h2>Waste picker database</h2>
<div class="card">
  <h3>Register a waste picker</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('name', 'Name', ['required' => true, 'maxlength' => 120]);
    f_input('phone', 'Mobile (optional)', ['maxlength' => 10, 'inputmode' => 'numeric']);
    if ($wards) f_select('ward_id', 'Ward', array_column(array_map(fn($w) => ['id' => $w['id'], 'l' => ward_label($w)], $wards), 'l', 'id'));
    if ($villages) f_select('village_id', 'Village', array_column($villages, 'name', 'id'));
    f_input('age', 'Age', ['type' => 'number', 'min' => 14]);
    f_select('gender', 'Gender', GENDERS);
    f_input('work_category', 'Category of work', ['maxlength' => 80, 'placeholder' => 'e.g. street picker, itinerant buyer']);
    f_input('collection_area', 'Collection area', ['maxlength' => 160]);
    f_input('organization', 'Organisation / SHG', ['maxlength' => 120]);
    f_input('materials', 'Material handled', ['maxlength' => 160, 'placeholder' => 'plastic, paper, metal…']);
    f_input('approx_kg_per_day', 'Approx. quantity (kg/day)', ['inputmode' => 'decimal']);
    f_select('facility_id', 'Linked MRF', array_column($facilities, 'name', 'id'));
    f_select('registration_status', 'Registration status', PICKER_STATUSES, ['value' => 'PENDING']);
    ?>
    <div class="field"><?php f_checkbox('trained', 'Has received training'); ?></div>
    <div class="field"><?php f_checkbox('has_ppe', 'Has PPE (gloves, mask…)'); ?></div>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
  <p class="muted small-text">Bank or payment details are intentionally not collected here.</p>
</div>
<div class="card">
  <?php render_table([
      'Name' => 'name', 'Area' => fn($r) => $r['city'] ? e($r['city'] . ' – Ward ' . $r['ward_no']) : e($r['village_name'] ?? '–'),
      'Work' => 'work_category', 'Organisation' => 'organization', 'Material' => 'materials',
      'Linked MRF' => 'facility', 'Trained' => fn($r) => $yn($r['trained']), 'PPE' => fn($r) => $yn($r['has_ppe']),
      'Status' => fn($r) => badge(PICKER_STATUSES[$r['registration_status']], $r['registration_status'] === 'REGISTERED' ? '' : 'warn'),
  ], $rows, 'No waste pickers registered yet.'); ?>
</div>
<?php page_end();

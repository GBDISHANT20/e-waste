<?php
// Citizen (house owner) self sign-up.
require __DIR__ . '/../src/bootstrap.php';
if ($u = current_user()) redirect(home_for($u));

$districts = q_all('SELECT id, name, state FROM districts ORDER BY state, name');
$wards = q_all('SELECT w.id, w.district_id, w.city, w.ward_no, w.ward_name FROM wards w ORDER BY w.city, w.ward_no');
$villages = q_all('SELECT v.id, v.district_id, v.name FROM villages v ORDER BY v.name');

if (is_post()) {
    csrf_check();
    try_action(function () {
        $name = v_str($_POST['name'] ?? '', 'Name');
        $phone = v_phone($_POST['phone'] ?? '');
        $password = v_password($_POST['password'] ?? '');
        $did = v_int($_POST['district_id'] ?? '', 'District', true);
        if (!q_val('SELECT 1 FROM districts WHERE id = ?', [$did])) throw new UserError('Choose a district');
        $type = v_enum($_POST['area_type'] ?? '', ['URBAN', 'RURAL'], 'area type');
        $wardId = $villageId = null;
        if ($type === 'URBAN') {
            $wardId = v_int($_POST['ward_id'] ?? '', 'Ward', true);
            if (!q_val('SELECT 1 FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did])) throw new UserError('Choose your ward');
        } else {
            $villageId = v_int($_POST['village_id'] ?? '', 'Village', true);
            if (!q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Choose your village');
        }
        $house = v_str($_POST['house_no'] ?? '', 'House number', 40);
        $address = v_str($_POST['address'] ?? '', 'Address', 250, false);
        $members = v_int($_POST['members'] ?? '', 'Family members', false, 100);
        $uid = in_tx(function () use ($name, $phone, $password, $did, $type, $wardId, $villageId, $house, $address, $members) {
            $user = create_user($name, $phone, 'CITIZEN', $did, $password);
            q('INSERT INTO households (user_id, district_id, area_type, ward_id, village_id, house_no, address, members)
               VALUES (?,?,?,?,?,?,?,?)', [$user['id'], $did, $type, $wardId, $villageId, $house, $address, $members]);
            return $user['id'];
        });
        login_user($uid);
        flash('ok', 'Your house is registered. Welcome!');
        redirect('profile.php');
    });
}

$areaType = old('area_type', 'URBAN');
page_start('Citizen sign-up', '', true);
?>
<h1>SWM Portal</h1>
<div class="tabs"><a href="login.php">Login</a><a class="active" href="register.php">Citizen sign-up</a></div>
<div class="card">
  <form method="post" class="stack" id="citizen-form">
    <?= csrf_field() ?>
    <?php
    f_input('name', 'Full name (house owner)', ['required' => true, 'maxlength' => 120, 'autocomplete' => 'name']);
    f_input('phone', 'Mobile number', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric', 'autocomplete' => 'tel']);
    f_input('password', 'Password (min 8 characters)', ['required' => true, 'type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password']);
    f_select('district_id', 'District', array_column(array_map(fn($d) => ['id' => $d['id'], 'l' => $d['name'] . ', ' . $d['state']], $districts), 'l', 'id'), ['required' => true]);
    f_select('area_type', 'Area type', ['URBAN' => 'Urban (city ward)', 'RURAL' => 'Rural (village)'], ['required' => true, 'value' => $areaType]);

    $wardOpts = $wardData = [];
    foreach ($wards as $w) { $wardOpts[$w['id']] = ward_label($w); $wardData[$w['id']] = ['district' => $w['district_id']]; }
    f_select('ward_id', 'Ward', $wardOpts, ['required' => true, 'data' => $wardData, 'wrap' => 'data-for="URBAN"']);
    $vOpts = $vData = [];
    foreach ($villages as $v) { $vOpts[$v['id']] = $v['name']; $vData[$v['id']] = ['district' => $v['district_id']]; }
    f_select('village_id', 'Village', $vOpts, ['data' => $vData, 'wrap' => 'data-for="RURAL"']);

    f_input('house_no', 'House number', ['required' => true, 'maxlength' => 40]);
    f_input('address', 'Address / landmark', ['maxlength' => 250]);
    f_input('members', 'Family members', ['type' => 'number', 'min' => 1]);
    ?>
    <button class="btn" type="submit">Register my house</button>
  </form>
</div>
<?php page_end();

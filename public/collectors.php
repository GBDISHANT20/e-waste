<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STATE_ADMIN');

if (is_post()) {
    csrf_check();
    try_action(function () {
        $did = v_int($_POST['district_id'] ?? '', 'District', true);
        if (!q_val('SELECT 1 FROM districts WHERE id = ?', [$did])) throw new UserError('Choose a district');
        $name = v_str($_POST['name'] ?? '', 'Name');
        $phone = v_phone($_POST['phone'] ?? '', 'Official mobile');
        $emp = v_str($_POST['employee_id'] ?? '', 'Employee ID', 40);
        $email = v_str($_POST['email'] ?? '', 'Email', 120, false);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UserError('Email is not valid');
        $res = in_tx(function () use ($did, $name, $phone, $emp, $email) {
            // A new collector replaces the previous one (transfer); the old account is kept but deactivated.
            $old = q_all('SELECT id, user_id FROM collectors WHERE district_id = ? AND active = 1 FOR UPDATE', [$did]);
            foreach ($old as $o) {
                q('UPDATE collectors SET active = 0 WHERE id = ?', [$o['id']]);
                q('UPDATE users SET active = 0 WHERE id = ?', [$o['user_id']]);
            }
            $user = create_user($name, $phone, 'DISTRICT_COLLECTOR', $did);
            q('INSERT INTO collectors (user_id, district_id, employee_id, email) VALUES (?,?,?,?)', [$user['id'], $did, $emp, $email]);
            return ['id' => last_id(), 'pw' => $user['temp_password'], 'replaced' => count($old)];
        });
        audit('create', 'collector', $res['id'], ['district_id' => $did]);
        flash('ok', temp_password_notice('Collector', $res['pw']), true);
        if ($res['replaced']) flash('warn', 'The previous collector of this district was deactivated; the district data is unchanged.');
        redirect('collectors.php');
    });
}

$districts = q_all('SELECT id, name, state FROM districts ORDER BY state, name');
$rows = q_all('SELECT c.employee_id, c.email, c.active, d.name AS district, u.name, u.phone
                 FROM collectors c JOIN users u ON u.id = c.user_id JOIN districts d ON d.id = c.district_id
                ORDER BY d.name, c.active DESC, c.id DESC');
page_start('District Collectors', 'collectors.php');
?>
<h2>District Collectors</h2>
<div class="card">
  <h3>Register a District Collector (DC / DM)</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_select('district_id', 'District', array_column(array_map(fn($d) => ['id' => $d['id'], 'l' => $d['name'] . ', ' . $d['state']], $districts), 'l', 'id'), ['required' => true]);
    f_input('name', 'Full name', ['required' => true, 'maxlength' => 120]);
    f_input('phone', 'Official mobile', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric']);
    f_input('employee_id', 'Employee ID', ['required' => true, 'maxlength' => 40]);
    f_input('email', 'Official email', ['type' => 'email', 'maxlength' => 120]);
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
  <p class="muted small-text">Registering a new collector for a district deactivates the previous one. Old data stays with the district.</p>
</div>
<div class="card">
  <?php render_table([
      'District' => 'district', 'Name' => 'name', 'Mobile' => 'phone', 'Employee ID' => 'employee_id', 'Email' => 'email',
      'Status' => fn($r) => $r['active'] ? badge('active') : badge('transferred', 'err'),
  ], $rows); ?>
</div>
<?php page_end();

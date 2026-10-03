<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STATE_ADMIN');

if (is_post()) {
    csrf_check();
    try_action(function () {
        $name = v_str($_POST['name'] ?? '', 'District name');
        $state = v_str($_POST['state'] ?? '', 'State');
        $code = strtoupper(v_str($_POST['code'] ?? '', 'District code', 10));
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) throw new UserError('District code must be 2–10 letters or digits');
        q('INSERT INTO districts (name, state, code) VALUES (?,?,?)', [$name, $state, $code]);
        audit('create', 'district', last_id(), ['name' => $name]);
        flash('ok', "District $name registered.");
        redirect('districts.php');
    });
}

$rows = q_all("SELECT d.*, u.name AS collector_name, u.phone AS collector_phone
                 FROM districts d
                 LEFT JOIN collectors c ON c.district_id = d.id AND c.active = 1
                 LEFT JOIN users u ON u.id = c.user_id
                ORDER BY d.state, d.name");
page_start('Districts', 'districts.php');
?>
<h2>Districts</h2>
<div class="card">
  <h3>Register a district</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php
    f_input('name', 'District name', ['required' => true, 'maxlength' => 120]);
    f_input('state', 'State', ['required' => true, 'maxlength' => 120]);
    f_input('code', 'District code', ['required' => true, 'maxlength' => 10, 'placeholder' => 'e.g. BWN']);
    ?>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
</div>
<div class="card">
  <?php render_table([
      'District' => 'name', 'State' => 'state', 'Code' => 'code',
      'Collector' => fn($r) => $r['collector_name'] ? e($r['collector_name'] . ' (' . $r['collector_phone'] . ')') : badge('not assigned', 'warn'),
  ], $rows); ?>
</div>
<?php page_end();

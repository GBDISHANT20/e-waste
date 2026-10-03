<?php
// Public page: anyone can report a garbage problem (no login) and track it with the complaint number + mobile.
require __DIR__ . '/../src/bootstrap.php';
$user = current_user();
if ($user && $user['role'] === 'CITIZEN') redirect('complaints.php');

$districts = q_all('SELECT id, name, state FROM districts ORDER BY state, name');
$wards = q_all('SELECT id, district_id, city, ward_no, ward_name FROM wards ORDER BY city, ward_no');
$villages = q_all('SELECT id, district_id, name FROM villages ORDER BY name');
$done = null;

if (is_post()) {
    csrf_check();
    try_action(function () use ($user, &$done) {
        $done = submit_complaint($_POST, $user);
        $_POST = [];
    });
}

$track = null;
if (isset($_GET['no'])) {
    $no = trim((string) $_GET['no']);
    $ph = trim((string) ($_GET['phone'] ?? ''));
    $track = q_one('SELECT c.*, d.name AS district FROM complaints c JOIN districts d ON d.id = c.district_id WHERE c.complaint_no = ? AND c.citizen_phone = ?', [$no, $ph]);
    if (!$track) flash_now('error', 'No complaint found with that number and mobile number');
}

page_start('Report a garbage issue', '', true);
$areaType = old('area_type', 'URBAN');
?>
<h1>Report a garbage issue</h1>
<p class="muted">No login needed. <a href="login.php">Officials &amp; staff login</a></p>
<?php if ($done): ?>
  <div class="notice ok" role="status">Thank you. Your complaint number is <code><?= e($done) ?></code>. Keep it to track the status below.</div>
<?php endif; ?>
<div class="card">
  <form method="post" enctype="multipart/form-data" class="stack" data-area-form>
    <?= csrf_field() ?>
    <?php
    f_input('name', 'Your name', ['required' => true, 'maxlength' => 120, 'autocomplete' => 'name']);
    f_input('phone', 'Mobile number', ['required' => true, 'maxlength' => 10, 'inputmode' => 'numeric', 'autocomplete' => 'tel']);
    f_select('district_id', 'District', array_column(array_map(fn($d) => ['id' => $d['id'], 'l' => $d['name'] . ', ' . $d['state']], $districts), 'l', 'id'), ['required' => true]);
    f_select('area_type', 'Area type', ['URBAN' => 'Urban (city ward)', 'RURAL' => 'Rural (village)'], ['required' => true, 'value' => $areaType]);
    $wo = $wd = [];
    foreach ($wards as $w) { $wo[$w['id']] = ward_label($w); $wd[$w['id']] = ['district' => $w['district_id']]; }
    f_select('ward_id', 'Ward', $wo, ['data' => $wd, 'wrap' => 'data-for="URBAN"']);
    $vo = $vd = [];
    foreach ($villages as $v) { $vo[$v['id']] = $v['name']; $vd[$v['id']] = ['district' => $v['district_id']]; }
    f_select('village_id', 'Village', $vo, ['data' => $vd, 'wrap' => 'data-for="RURAL"']);
    f_select('category', 'What is the problem?', COMPLAINT_CATEGORIES, ['required' => true]);
    f_textarea('description', 'Describe the problem and the exact place', ['required' => true, 'maxlength' => 1000]);
    f_file('photo', 'Photo (helps us act faster)', ['images' => true, 'camera' => true]);
    f_geo();
    ?>
    <button class="btn" type="submit">Submit complaint</button>
  </form>
</div>

<div class="card">
  <h3>Track a complaint</h3>
  <form method="get" class="toolbar">
    <div class="field"><label for="f_no">Complaint number</label><input id="f_no" name="no" value="<?= e($_GET['no'] ?? '') ?>" placeholder="SWM-C-2026-000001" maxlength="30"></div>
    <div class="field"><label for="f_tphone">Mobile number</label><input id="f_tphone" name="phone" value="<?= e($_GET['phone'] ?? '') ?>" inputmode="numeric" maxlength="10"></div>
    <button class="btn secondary" type="submit">Track</button>
  </form>
  <?php if ($track): ?>
    <dl class="kv">
      <dt>Complaint</dt><dd><?= e($track['complaint_no']) ?> · <?= e(COMPLAINT_CATEGORIES[$track['category']]) ?></dd>
      <dt>Status</dt><dd><strong><?= e(COMPLAINT_STATUSES[$track['status']]) ?></strong>
        <div class="small-text muted">Received → Assigned → Action taken → Closed</div></dd>
      <dt>Reported on</dt><dd><?= e(substr($track['created_at'], 0, 10)) ?></dd>
      <?php if ($track['action_note']): ?><dt>Action taken</dt><dd class="pre"><?= e($track['action_note']) ?></dd><?php endif; ?>
    </dl>
  <?php endif; ?>
</div>
<?php page_end();

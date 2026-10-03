<?php
// Complaints: citizens file and follow their own; officials assign, act and close.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('CITIZEN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$isCitizen = $u['role'] === 'CITIZEN';
$isCollector = $u['role'] === 'DISTRICT_COLLECTOR';

// ---------- citizen: file a complaint ----------
if ($isCitizen && is_post()) {
    csrf_check();
    try_action(function () use ($u) {
        $h = q_one('SELECT area_type, ward_id, village_id, district_id FROM households WHERE user_id = ?', [$u['id']]);
        if (!$h) throw new UserError('Your house is not registered');
        $no = submit_complaint([
            'name' => $u['name'], 'phone' => $u['phone'], 'district_id' => (string) $h['district_id'], 'area_type' => $h['area_type'],
            'ward_id' => (string) $h['ward_id'], 'village_id' => (string) $h['village_id'],
            'category' => $_POST['category'] ?? '', 'description' => $_POST['description'] ?? '', 'lat' => $_POST['lat'] ?? '', 'lng' => $_POST['lng'] ?? '',
        ], $u);
        flash('ok', "Complaint registered. Your complaint number is $no.");
        redirect('complaints.php');
    });
}

function load_complaint(array $u, int $id): array
{
    $c = q_one('SELECT * FROM complaints WHERE id = ? AND district_id = ?', [$id, $u['district_id']]);
    if (!$c) throw new UserError('Complaint not found');
    if ($u['role'] === 'CITIZEN') { if ((int) $c['user_id'] !== (int) $u['id']) throw new UserError('Complaint not found'); return $c; }
    if ($u['role'] !== 'DISTRICT_COLLECTOR') {
        $ward = $c['ward_id'] === null ? null : (int) $c['ward_id'];
        $village = $c['village_id'] === null ? null : (int) $c['village_id'];
        if (!can_manage_area($u, $ward, $village) && (int) $c['assigned_to_user_id'] !== (int) $u['id']) throw new UserError('Complaint not found');
    }
    return $c;
}

// ---------- officials: workflow ----------
if (!$isCitizen && is_post()) {
    csrf_check();
    $cid = (int) ($_POST['id'] ?? 0);
    try_action(function () use ($u, $did, $isCollector, $cid) {
        $c = load_complaint($u, $cid);
        $do = $_POST['do'] ?? '';
        if ($do === 'assign') {
            if (!$isCollector || $c['status'] === 'CLOSED') throw new UserError('Only the District Collector can assign open complaints');
            $to = v_int($_POST['assignee'] ?? '', 'Officer', true);
            $ok = q_val("SELECT 1 FROM users WHERE id = ? AND district_id = ? AND active = 1 AND role IN ('MC','SARPANCH')", [$to, $did]);
            if (!$ok) throw new UserError('Choose an MC or Sarpanch');
            q("UPDATE complaints SET status = 'ASSIGNED', assigned_to_user_id = ?, updated_at = NOW() WHERE id = ?", [$to, $c['id']]);
            flash('ok', 'Complaint assigned.');
        } elseif ($do === 'act') {
            if (!in_array($c['status'], ['RECEIVED', 'ASSIGNED'], true)) throw new UserError('This complaint is already handled');
            $note = v_str($_POST['action_note'] ?? '', 'What action was taken', 1000);
            check_upload('photo', true);
            in_tx(function () use ($did, $c, $note) {
                q("UPDATE complaints SET status = 'ACTION_TAKEN', action_note = ?, updated_at = NOW() WHERE id = ?", [$note, $c['id']]);
                save_upload('photo', $did, 'COMPLAINT', (int) $c['id'], 'PHOTO', 'After action');
            });
            flash('ok', 'Marked as action taken.');
        } elseif ($do === 'close') {
            if (!$isCollector || $c['status'] !== 'ACTION_TAKEN') throw new UserError('Only the District Collector can close a complaint after action is taken');
            q("UPDATE complaints SET status = 'CLOSED', closed_at = NOW(), updated_at = NOW() WHERE id = ?", [$c['id']]);
            flash('ok', 'Complaint closed.');
        } else {
            throw new UserError('Unknown step');
        }
        audit($do, 'complaint', (int) $c['id']);
        redirect('complaints.php?id=' . $cid);
    });
    $_GET['id'] = (string) $cid;
}

// ---------- detail ----------
if (!$isCitizen && isset($_GET['id'])) {
    try { $c = load_complaint($u, (int) $_GET['id']); } catch (UserError $e) { flash('error', $e->getMessage()); redirect('complaints.php'); }
    $place = $c['ward_id'] ? q_val('SELECT CONCAT(city, " – Ward ", ward_no) FROM wards WHERE id = ?', [$c['ward_id']]) : q_val('SELECT name FROM villages WHERE id = ?', [$c['village_id']]);
    $assignee = $c['assigned_to_user_id'] ? q_val('SELECT name FROM users WHERE id = ?', [$c['assigned_to_user_id']]) : null;
    $after = q_val("SELECT id FROM documents WHERE owner_type = 'COMPLAINT' AND owner_id = ? AND title = 'After action' ORDER BY id DESC LIMIT 1", [$c['id']]);
    page_start($c['complaint_no'], 'complaints.php');
    ?>
    <p><a class="back" href="complaints.php">← All complaints</a></p>
    <h2><?= e($c['complaint_no']) ?> <?= badge(COMPLAINT_STATUSES[$c['status']], $c['status'] === 'CLOSED' ? '' : 'warn') ?></h2>
    <div class="card"><dl class="kv">
      <dt>Problem</dt><dd><?= e(COMPLAINT_CATEGORIES[$c['category']]) ?></dd>
      <dt>Description</dt><dd class="pre"><?= e($c['description']) ?></dd>
      <dt>Area</dt><dd><?= e($place) ?></dd>
      <dt>Citizen</dt><dd><?= e($c['citizen_name'] . ' · ' . $c['citizen_phone']) ?></dd>
      <dt>Reported</dt><dd><?= e(substr($c['created_at'], 0, 16)) ?></dd>
      <dt>Assigned to</dt><dd><?= e($assignee ?? '–') ?></dd>
      <?php if ($c['lat'] !== null): ?><dt>Location</dt><dd><?= e($c['lat'] . ', ' . $c['lng']) ?> · <?= map_link($c['lat'], $c['lng'], 'Open map') ?></dd><?php endif; ?>
      <?php if ($c['action_note']): ?><dt>Action taken</dt><dd class="pre"><?= e($c['action_note']) ?></dd><?php endif; ?>
    </dl>
    <div class="photos">
      <?php if ($c['photo_doc_id']): ?><figure><a href="download.php?id=<?= (int) $c['photo_doc_id'] ?>" target="_blank" rel="noopener"><img alt="Complaint photo" src="download.php?id=<?= (int) $c['photo_doc_id'] ?>"></a><figcaption>Reported</figcaption></figure><?php endif; ?>
      <?php if ($after): ?><figure><a href="download.php?id=<?= (int) $after ?>" target="_blank" rel="noopener"><img alt="After action" src="download.php?id=<?= (int) $after ?>"></a><figcaption>After action</figcaption></figure><?php endif; ?>
    </div></div>

    <?php if ($isCollector && $c['status'] !== 'CLOSED'): ?>
    <div class="card"><h3>Assign to an officer</h3>
      <form method="post" class="grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="assign"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <?php
        $handlers = array_filter(responsible_options($did), fn($l) => !str_ends_with($l, '(Collector)'));
        f_select('assignee', 'MC / Sarpanch', $handlers, ['required' => true, 'value' => (string) $c['assigned_to_user_id']]);
        ?>
        <div class="field actions"><button class="btn" type="submit">Assign</button></div>
      </form></div>
    <?php endif; ?>
    <?php if (in_array($c['status'], ['RECEIVED', 'ASSIGNED'], true)): ?>
    <div class="card"><h3>Record action taken</h3>
      <form method="post" enctype="multipart/form-data" class="grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="act"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <?php f_textarea('action_note', 'What was done', ['required' => true, 'wide' => true, 'maxlength' => 1000]); f_file('photo', 'Photo after action', ['images' => true, 'camera' => true]); ?>
        <div class="field actions"><button class="btn" type="submit">Mark action taken</button></div>
      </form></div>
    <?php endif; ?>
    <?php if ($isCollector && $c['status'] === 'ACTION_TAKEN'): ?>
    <div class="card"><form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
      <button class="btn" type="submit">Close complaint</button></form></div>
    <?php endif; ?>
    <?php
    page_end();
    exit;
}

// ---------- lists ----------
page_start($isCitizen ? 'Report an issue' : 'Complaints', 'complaints.php');
if ($isCitizen) {
    $rows = q_all('SELECT * FROM complaints WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
    ?>
    <h2>Report an issue</h2>
    <div class="card">
      <h3>New complaint</h3>
      <form method="post" enctype="multipart/form-data" class="grid">
        <?= csrf_field() ?>
        <?php
        f_select('category', 'What is the problem?', COMPLAINT_CATEGORIES, ['required' => true]);
        f_textarea('description', 'Describe the problem and the exact place', ['required' => true, 'wide' => true, 'maxlength' => 1000]);
        f_file('photo', 'Photo', ['images' => true, 'camera' => true]);
        f_geo();
        ?>
        <div class="field actions"><button class="btn" type="submit">Submit complaint</button></div>
      </form>
    </div>
    <div class="card"><h3>My complaints</h3>
      <?php render_table([
          'Number' => 'complaint_no', 'Problem' => fn($r) => e(COMPLAINT_CATEGORIES[$r['category']]),
          'Reported' => fn($r) => e(substr($r['created_at'], 0, 10)),
          'Status' => fn($r) => badge(COMPLAINT_STATUSES[$r['status']], $r['status'] === 'CLOSED' ? '' : 'warn'),
          'Action taken' => 'action_note',
      ], $rows, 'You have not reported anything yet.'); ?>
    </div>
    <?php
    page_end();
    exit;
}
[$cs, $ca] = area_scope($u, 'c.ward_id', 'c.village_id');
$st = isset($_GET['status']) && isset(COMPLAINT_STATUSES[$_GET['status']]) ? $_GET['status'] : '';
$scope = $isCollector ? '' : ' AND (c.assigned_to_user_id = ?' . ($cs ? ' OR (' . substr($cs, 5) . ')' : '') . ')';
$args = $isCollector ? [$did] : array_merge([$did, $u['id']], $ca);
if ($st) { $scope .= ' AND c.status = ?'; $args[] = $st; }
$rows = q_all("SELECT c.*, w.city, w.ward_no, vi.name AS village_name, a.name AS assignee FROM complaints c
                 LEFT JOIN wards w ON w.id = c.ward_id LEFT JOIN villages vi ON vi.id = c.village_id LEFT JOIN users a ON a.id = c.assigned_to_user_id
                WHERE c.district_id = ?" . $scope . " ORDER BY c.status = 'CLOSED', c.id DESC LIMIT 300", $args);
?>
<h2>Complaints</h2>
<div class="card">
  <form method="get" class="toolbar no-print">
    <?php f_select('status', 'Status', COMPLAINT_STATUSES, ['value' => $st]); ?>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
  <?php render_table([
      'Number' => fn($r) => '<a href="complaints.php?id=' . (int) $r['id'] . '">' . e($r['complaint_no']) . '</a>',
      'Problem' => fn($r) => e(COMPLAINT_CATEGORIES[$r['category']]),
      'Area' => fn($r) => $r['city'] ? e($r['city'] . ' – Ward ' . $r['ward_no']) : e($r['village_name']),
      'Reported' => fn($r) => e(substr($r['created_at'], 0, 10)),
      'Assigned to' => 'assignee',
      'Status' => fn($r) => badge(COMPLAINT_STATUSES[$r['status']], $r['status'] === 'CLOSED' ? '' : (days_between(substr($r['created_at'], 0, 10), today()) > 7 ? 'err' : 'warn')),
  ], $rows, 'No complaints.'); ?>
</div>
<?php page_end();

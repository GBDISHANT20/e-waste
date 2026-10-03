<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$isCollector = $u['role'] === 'DISTRICT_COLLECTOR';
$wards = visible_wards($u);
$villages = visible_villages($u);
[$fs, $fa] = area_scope($u, 'f.ward_id', 'f.village_id');
$facilities = q_all('SELECT f.id, f.name, f.type FROM facilities f WHERE f.district_id = ?' . $fs . ' ORDER BY f.name', array_merge([$did], $fa));
$officials = responsible_options($did);

if (is_post()) {
    csrf_check();
    try_action(function () use ($u, $did, $isCollector, $wards, $villages, $facilities, $officials) {
        $date = v_date($_POST['inspected_on'] ?? '', 'Inspection date', true, null, today());
        $wardId = v_int($_POST['ward_id'] ?? '', 'Ward');
        $villageId = v_int($_POST['village_id'] ?? '', 'Village');
        $facilityId = v_int($_POST['facility_id'] ?? '', 'Facility');
        if (!$wardId && !$villageId && !$facilityId) throw new UserError('Select the location inspected (ward, village or facility)');
        if ($wardId && !in_array($wardId, array_map('intval', array_column($wards, 'id')), true)) throw new UserError('Ward not allowed');
        if ($villageId && !in_array($villageId, array_map('intval', array_column($villages, 'id')), true)) throw new UserError('Village not allowed');
        if ($facilityId && !in_array($facilityId, array_map('intval', array_column($facilities, 'id')), true)) throw new UserError('Facility not allowed');
        [$lat, $lng] = v_coords($_POST['lat'] ?? '', $_POST['lng'] ?? '');
        $check = [];
        foreach (INSPECTION_CHECKLIST as $k => $label) {
            $val = $_POST['chk_' . $k] ?? 'NA';
            $check[$k] = in_array($val, ['YES', 'NO', 'NA'], true) ? $val : 'NA';
        }
        $obs = v_str($_POST['observations'] ?? '', 'Observations', 1500, false);
        $violation = isset($_POST['violation']) ? 1 : 0;
        $direction = $deadline = $responsible = null;
        if ($violation) {
            $direction = v_str($_POST['direction'] ?? '', 'Direction given', 250);
            $deadline = v_date($_POST['deadline'] ?? '', 'Deadline', true, today());
            $responsible = v_int($_POST['responsible_user_id'] ?? '', 'Responsible officer', true);
            if (!isset($officials[$responsible])) throw new UserError('Choose the responsible officer');
        }
        check_upload('photo', true);
        $placeParts = [];
        foreach ($wards as $w) if ((int) $w['id'] === $wardId) $placeParts[] = ward_label($w);
        foreach ($villages as $v) if ((int) $v['id'] === $villageId) $placeParts[] = $v['name'];
        foreach ($facilities as $f) if ((int) $f['id'] === $facilityId) $placeParts[] = $f['name'];
        $place = implode(', ', $placeParts);
        $res = in_tx(function () use ($u, $did, $date, $wardId, $villageId, $facilityId, $lat, $lng, $check, $obs, $violation, $direction, $deadline, $responsible, $place) {
            q('INSERT INTO inspections (district_id, inspected_on, inspector_user_id, ward_id, village_id, facility_id, lat, lng, checklist, observations, violation, direction, deadline)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$did, $date, $u['id'], $wardId, $villageId, $facilityId, $lat, $lng, json_encode($check), $obs, $violation, $direction, $deadline]);
            $iid = last_id();
            save_upload('photo', $did, 'INSPECTION', $iid, 'PHOTO', null, null, true);
            $action = null;
            if ($violation) {
                $action = create_action($did, ['issue' => $direction, 'location_text' => $place, 'responsible_user_id' => $responsible,
                    'deadline' => $deadline, 'inspection_id' => $iid], (int) $u['id']);
                q('UPDATE inspections SET action_id = ? WHERE id = ?', [$action['id'], $iid]);
            }
            return ['id' => $iid, 'action' => $action];
        });
        audit('create', 'inspection', $res['id'], ['violation' => $violation]);
        flash('ok', 'Inspection recorded.' . ($res['action'] ? ' Action ' . $res['action']['action_no'] . ' created for the violation.' : ''));
        redirect('inspections.php');
    });
}

[$is, $ia] = area_scope($u, 'i.ward_id', 'i.village_id');
$scope = $isCollector ? '' : ' AND (i.inspector_user_id = ?' . ($is ? ' OR (' . substr($is, 5) . ')' : '') . ')';
$args = $isCollector ? [$did] : array_merge([$did, $u['id']], $ia);
$rows = q_all("SELECT i.*, us.name AS inspector, w.city, w.ward_no, vi.name AS village_name, f.name AS facility_name, a.action_no, a.status AS action_status,
                      (SELECT d.id FROM documents d WHERE d.owner_type='INSPECTION' AND d.owner_id = i.id LIMIT 1) AS photo_id
                 FROM inspections i JOIN users us ON us.id = i.inspector_user_id
                 LEFT JOIN wards w ON w.id = i.ward_id LEFT JOIN villages vi ON vi.id = i.village_id
                 LEFT JOIN facilities f ON f.id = i.facility_id LEFT JOIN actions a ON a.id = i.action_id
                WHERE i.district_id = ?" . $scope . ' ORDER BY i.inspected_on DESC, i.id DESC LIMIT 200', $args);

page_start('Inspections', 'inspections.php');
?>
<h2>Inspections</h2>
<div class="card">
  <h3>Record an inspection</h3>
  <form method="post" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?>
    <div class="grid">
      <?php
      f_input('inspected_on', 'Date', ['type' => 'date', 'required' => true, 'value' => today(), 'max' => today()]);
      if ($wards) f_select('ward_id', 'Ward inspected', array_column(array_map(fn($w) => ['id' => $w['id'], 'l' => ward_label($w)], $wards), 'l', 'id'));
      if ($villages) f_select('village_id', 'Village inspected', array_column($villages, 'name', 'id'));
      f_select('facility_id', 'Facility inspected', array_column($facilities, 'name', 'id'));
      ?>
    </div>
    <fieldset><legend>Checklist</legend><div class="checklist">
      <?php foreach (INSPECTION_CHECKLIST as $k => $label): ?>
        <div class="row"><label for="chk_<?= e($k) ?>"><?= e($label) ?></label>
          <select id="chk_<?= e($k) ?>" name="chk_<?= e($k) ?>">
            <?php foreach (['NA' => 'N/A', 'YES' => 'Yes – OK', 'NO' => 'No – problem'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= old('chk_' . $k, 'NA') === $v ? ' selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endforeach; ?>
    </div></fieldset>
    <?php f_textarea('observations', 'Observations', ['maxlength' => 1500]); ?>
    <?php f_checkbox('violation', 'A violation was found – give a direction'); ?>
    <div id="violation-fields" class="grid"<?= is_post() && !isset($_POST['violation']) ? ' hidden' : '' ?>>
      <?php
      f_input('direction', 'Direction given', ['maxlength' => 250]);
      f_input('deadline', 'Deadline', ['type' => 'date']);
      f_select('responsible_user_id', 'Responsible officer', $officials);
      ?>
    </div>
    <div class="grid">
      <?php f_file('photo', 'Photograph', ['images' => true, 'camera' => true]); f_geo(); ?>
    </div>
    <div><button class="btn" type="submit">Save inspection</button></div>
    <p class="muted small-text">Ticking “violation” creates a numbered action in the Action tracker with the deadline.</p>
  </form>
</div>
<div class="card">
  <?php render_table([
      'Date' => fn($r) => e(ymd($r['inspected_on'])),
      'Location' => fn($r) => e(implode(', ', array_filter([$r['city'] ? $r['city'] . ' – Ward ' . $r['ward_no'] : null, $r['village_name'], $r['facility_name']]))),
      'Inspector' => 'inspector',
      'Problems' => function ($r) {
          $c = json_decode((string) $r['checklist'], true) ?: [];
          $no = count(array_filter($c, fn($v) => $v === 'NO'));
          return $no ? badge("$no item" . ($no > 1 ? 's' : ''), 'warn') : badge('none');
      },
      'Violation' => fn($r) => $r['violation'] ? badge('violation', 'err') : '–',
      'Action' => fn($r) => $r['action_no'] ? '<a href="actions.php?id=' . (int) $r['action_id'] . '">' . e($r['action_no']) . '</a>' : '–',
      'Photo' => fn($r) => doc_thumb($r['photo_id'] ? (int) $r['photo_id'] : null),
      'Map' => fn($r) => map_link($r['lat'], $r['lng']),
  ], $rows, 'No inspections recorded yet.'); ?>
</div>
<?php page_end();

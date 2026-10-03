<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$wards = visible_wards($u);
$villages = visible_villages($u);
$wardOpts = array_column(array_map(fn($w) => ['id' => $w['id'], 'l' => ward_label($w)], $wards), 'l', 'id');
$villageOpts = array_column($villages, 'name', 'id');

function load_facility(array $u, int $id): array
{
    $f = q_one('SELECT f.*, w.city, w.ward_no, vi.name AS village_name FROM facilities f
                  LEFT JOIN wards w ON w.id = f.ward_id LEFT JOIN villages vi ON vi.id = f.village_id
                 WHERE f.id = ? AND f.district_id = ?', [$id, $u['district_id']]);
    if (!$f) throw new UserError('Facility not found');
    $ward = $f['ward_id'] === null ? null : (int) $f['ward_id'];
    $village = $f['village_id'] === null ? null : (int) $f['village_id'];
    if ($u['role'] !== 'DISTRICT_COLLECTOR' && !can_manage_area($u, $ward, $village)) throw new UserError('Facility not found');
    return $f;
}

// ---------- add a facility ----------
if (is_post() && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    try_action(function () use ($u, $did) {
        $type = v_enum($_POST['type'] ?? '', FACILITY_TYPES, 'facility type');
        $name = v_str($_POST['name'] ?? '', 'Facility name', 160);
        $wardId = v_int($_POST['ward_id'] ?? '', 'Ward');
        $villageId = v_int($_POST['village_id'] ?? '', 'Village');
        if ($wardId && $villageId) throw new UserError('Choose either a ward or a village, not both');
        if (!$wardId && !$villageId && $u['role'] !== 'DISTRICT_COLLECTOR') throw new UserError('Choose your ward or village');
        if ($wardId && !q_val('SELECT 1 FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did])) throw new UserError('Ward not found in this district');
        if ($villageId && !q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Village not found in this district');
        if (($wardId || $villageId || $u['role'] !== 'DISTRICT_COLLECTOR') && !can_manage_area($u, $wardId, $villageId)) throw new UserError('You can only add facilities in your own ward or village');
        [$lat, $lng] = v_coords($_POST['lat'] ?? '', $_POST['lng'] ?? '');
        $land = v_int($_POST['land_area_sqm'] ?? '', 'Land area');
        $cap = v_dec($_POST['capacity_tpd'] ?? '', 'Capacity (TPD)', false, 100000);
        $tech = v_str($_POST['technology'] ?? '', 'Technology', 120, false);
        $operator = v_str($_POST['operator'] ?? '', 'Operator', 120, false);
        $status = v_enum($_POST['status'] ?? '', FACILITY_STATUSES, 'status');
        $docCat = (string) ($_POST['doc_category'] ?? '');
        if (has_upload('doc_file')) v_enum($docCat, DOC_CATEGORIES, 'document type');
        $expires = v_date($_POST['doc_expires'] ?? '', 'Document expiry', false);
        check_upload('doc_file');
        $id = in_tx(function () use ($did, $u, $type, $name, $wardId, $villageId, $lat, $lng, $land, $cap, $tech, $operator, $status, $docCat, $expires) {
            q('INSERT INTO facilities (district_id, type, name, ward_id, village_id, lat, lng, land_area_sqm, capacity_tpd, technology, operator, status, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$did, $type, $name, $wardId, $villageId, $lat, $lng, $land, $cap, $tech, $operator, $status, $u['id']]);
            $fid = last_id();
            save_upload('doc_file', $did, 'FACILITY', $fid, $docCat, null, $expires);
            return $fid;
        });
        audit('create', 'facility', $id, ['name' => $name, 'type' => $type]);
        flash('ok', "Facility $name registered.");
        redirect('facilities.php?id=' . $id);
    });
}

// ---------- add a document to a facility ----------
if (is_post() && ($_POST['action'] ?? '') === 'doc') {
    csrf_check();
    $fid = (int) ($_POST['id'] ?? 0);
    try_action(function () use ($u, $did, $fid) {
        $f = load_facility($u, $fid);
        $cat = v_enum($_POST['doc_category'] ?? '', DOC_CATEGORIES, 'document type');
        $title = v_str($_POST['title'] ?? '', 'Title', 160, false);
        $expires = v_date($_POST['doc_expires'] ?? '', 'Expiry date', false);
        if (!has_upload('doc_file')) throw new UserError('Choose a file to upload');
        $docId = save_upload('doc_file', $did, 'FACILITY', (int) $f['id'], $cat, $title, $expires);
        audit('upload', 'document', $docId, ['facility' => $f['id'], 'category' => $cat]);
        flash('ok', 'Document uploaded.');
        redirect('facilities.php?id=' . $fid);
    });
    $_GET['id'] = (string) $fid;
}

$detail = null;
if (isset($_GET['id'])) {
    try { $detail = load_facility($u, (int) $_GET['id']); } catch (UserError $e) { flash('error', $e->getMessage()); redirect('facilities.php'); }
}

// ---------- detail view ----------
if ($detail) {
    $docs = q_all("SELECT d.*, us.name AS by_name FROM documents d LEFT JOIN users us ON us.id = d.uploaded_by
                    WHERE d.owner_type = 'FACILITY' AND d.owner_id = ? ORDER BY d.uploaded_at DESC", [$detail['id']]);
    $insp = q_all('SELECT i.inspected_on, i.violation, i.observations, us.name FROM inspections i JOIN users us ON us.id = i.inspector_user_id
                    WHERE i.facility_id = ? ORDER BY i.inspected_on DESC LIMIT 10', [$detail['id']]);
    $area = $detail['ward_id'] ? $detail['city'] . ' – Ward ' . $detail['ward_no'] : ($detail['village_name'] ?? 'District level');
    page_start($detail['name'], 'facilities.php');
    echo '<p><a class="back" href="facilities.php">← All facilities</a></p><h2>', e($detail['name']), '</h2>';
    echo '<div class="card"><dl class="kv">';
    foreach ([
        'Type' => FACILITY_TYPES[$detail['type']], 'Status' => FACILITY_STATUSES[$detail['status']], 'Area' => $area,
        'Capacity' => $detail['capacity_tpd'] !== null ? $detail['capacity_tpd'] . ' TPD' : null,
        'Land area' => $detail['land_area_sqm'] !== null ? $detail['land_area_sqm'] . ' sq m' : null,
        'Technology' => $detail['technology'], 'Operator' => $detail['operator'],
    ] as $k => $v) echo '<dt>', e($k), '</dt><dd>', $v === null || $v === '' ? '–' : e($v), '</dd>';
    echo '<dt>GPS</dt><dd>', $detail['lat'] !== null ? e($detail['lat'] . ', ' . $detail['lng']) . ' · ' . map_link($detail['lat'], $detail['lng'], 'Open map') : '–', '</dd></dl></div>';

    echo '<div class="card"><h3>Documents</h3>';
    render_table([
        'Type' => fn($r) => e(DOC_CATEGORIES[$r['category']] ?? $r['category']), 'Title' => fn($r) => e($r['title'] ?: $r['original_name']),
        'Valid until' => function ($r) {
            if (!$r['expires_on']) return '–';
            $left = days_between(today(), $r['expires_on']);
            return e(ymd($r['expires_on'])) . ' ' . ($left < 0 ? badge('expired', 'err') : ($left <= 30 ? badge("$left days left", 'warn') : ''));
        },
        'Uploaded' => fn($r) => e(substr($r['uploaded_at'], 0, 10)), 'File' => fn($r) => doc_link((int) $r['id'], 'Open'),
    ], $docs, 'No documents uploaded yet.');
    ?>
    <h3 class="gap-top">Upload a document</h3>
    <form method="post" enctype="multipart/form-data" class="grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="doc"><input type="hidden" name="id" value="<?= e($detail['id']) ?>">
      <?php
      f_select('doc_category', 'Document type', DOC_CATEGORIES, ['required' => true]);
      f_input('title', 'Title', ['maxlength' => 160]);
      f_input('doc_expires', 'Valid until (if it expires)', ['type' => 'date']);
      f_file('doc_file', 'File', ['required' => true]);
      ?>
      <div class="field actions"><button class="btn" type="submit">Upload</button></div>
    </form></div>
    <div class="card"><h3>Recent inspections</h3>
    <?php render_table(['Date' => fn($r) => e(ymd($r['inspected_on'])), 'Inspector' => 'name',
        'Violation' => fn($r) => $r['violation'] ? badge('violation', 'err') : badge('none'), 'Observations' => 'observations'], $insp, 'Not inspected yet.'); ?>
    </div>
    <?php
    page_end();
    exit;
}

// ---------- list ----------
[$as, $aa] = area_scope($u, 'f.ward_id', 'f.village_id');
$typeFilter = isset($_GET['type']) && isset(FACILITY_TYPES[$_GET['type']]) ? $_GET['type'] : '';
$rows = q_all("SELECT f.id, f.type, f.name, f.status, f.capacity_tpd, f.lat, f.lng, f.operator, w.city, w.ward_no, vi.name AS village_name,
                      (SELECT MAX(i.inspected_on) FROM inspections i WHERE i.facility_id = f.id) AS last_inspection
                 FROM facilities f LEFT JOIN wards w ON w.id = f.ward_id LEFT JOIN villages vi ON vi.id = f.village_id
                WHERE f.district_id = ?" . $as . ($typeFilter ? ' AND f.type = ?' : '') . ' ORDER BY f.type, f.name',
    array_merge([$did], $aa, $typeFilter ? [$typeFilter] : []));

page_start('Facilities', 'facilities.php');
?>
<h2>Waste management facilities</h2>
<div class="card">
  <h3>Register a facility or site</h3>
  <form method="post" enctype="multipart/form-data" class="grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?php
    f_select('type', 'Type', FACILITY_TYPES, ['required' => true]);
    f_input('name', 'Name', ['required' => true, 'maxlength' => 160]);
    if ($wardOpts) f_select('ward_id', 'Urban ward', $wardOpts);
    if ($villageOpts) f_select('village_id', 'Village', $villageOpts);
    f_select('status', 'Operational status', FACILITY_STATUSES, ['required' => true, 'value' => 'OPERATIONAL']);
    f_input('capacity_tpd', 'Capacity (tonnes per day)', ['inputmode' => 'decimal']);
    f_input('land_area_sqm', 'Land area (sq m)', ['type' => 'number', 'min' => 0]);
    f_input('technology', 'Technology', ['maxlength' => 120]);
    f_input('operator', 'Operator', ['maxlength' => 120]);
    f_geo();
    ?>
    <fieldset class="wide"><legend>Optional first document (authorization, consent, EC, land papers…)</legend><div class="grid">
      <?php
      f_select('doc_category', 'Document type', DOC_CATEGORIES);
      f_input('doc_expires', 'Valid until', ['type' => 'date']);
      f_file('doc_file', 'File');
      ?>
    </div></fieldset>
    <div class="field actions"><button class="btn" type="submit">Register</button></div>
  </form>
</div>
<div class="card">
  <form method="get" class="toolbar no-print">
    <?php f_select('type', 'Show', FACILITY_TYPES, ['value' => $typeFilter]); ?>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
  <?php render_table([
      'Name' => fn($r) => '<a href="facilities.php?id=' . (int) $r['id'] . '">' . e($r['name']) . '</a>',
      'Type' => fn($r) => e(FACILITY_TYPES[$r['type']]),
      'Area' => fn($r) => $r['city'] ? e($r['city'] . ' – Ward ' . $r['ward_no']) : e($r['village_name'] ?? 'District level'),
      'Capacity' => fn($r) => $r['capacity_tpd'] !== null ? e($r['capacity_tpd']) . ' TPD' : '–',
      'Status' => fn($r) => badge(FACILITY_STATUSES[$r['status']], $r['status'] === 'OPERATIONAL' ? '' : ($r['status'] === 'NON_OPERATIONAL' ? 'err' : 'warn')),
      'Last inspection' => fn($r) => $r['last_inspection'] ? e(ymd($r['last_inspection'])) : badge('never', 'warn'),
      'Map' => fn($r) => map_link($r['lat'], $r['lng']),
  ], $rows, 'No facilities registered yet.'); ?>
</div>
<?php page_end();

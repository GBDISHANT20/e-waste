<?php
// District document library (government orders, SWM action plan, reports...) – District Collector only.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR');
$did = (int) $u['district_id'];

if (is_post()) {
    csrf_check();
    try_action(function () use ($did) {
        $cat = v_enum($_POST['category'] ?? '', DOC_CATEGORIES, 'document type');
        $title = v_str($_POST['title'] ?? '', 'Title', 160);
        $expires = v_date($_POST['expires_on'] ?? '', 'Valid until', false);
        if (!has_upload('doc_file')) throw new UserError('Choose a file to upload');
        $id = save_upload('doc_file', $did, 'DISTRICT', null, $cat, $title, $expires);
        audit('upload', 'document', $id, ['category' => $cat]);
        flash('ok', 'Document uploaded.');
        redirect('documents.php');
    });
}

$cat = isset($_GET['category']) && isset(DOC_CATEGORIES[$_GET['category']]) ? $_GET['category'] : '';
$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT d.*, us.name AS by_name, f.name AS facility_name FROM documents d
          LEFT JOIN users us ON us.id = d.uploaded_by
          LEFT JOIN facilities f ON d.owner_type = 'FACILITY' AND f.id = d.owner_id
         WHERE d.district_id = ? AND d.owner_type IN ('DISTRICT','FACILITY','MEETING','ACTION','INSPECTION')";
$args = [$did];
if ($cat) { $sql .= ' AND d.category = ?'; $args[] = $cat; }
if ($search !== '') { $sql .= ' AND (d.title LIKE ? OR d.original_name LIKE ? OR f.name LIKE ?)'; $like = '%' . addcslashes($search, '%_\\') . '%'; array_push($args, $like, $like, $like); }
$rows = q_all($sql . ' ORDER BY d.uploaded_at DESC LIMIT 300', $args);

page_start('Documents', 'documents.php');
?>
<h2>Document repository</h2>
<div class="card">
  <h3>Upload a district document</h3>
  <form method="post" enctype="multipart/form-data" class="grid">
    <?= csrf_field() ?>
    <?php
    f_select('category', 'Type', DOC_CATEGORIES, ['required' => true]);
    f_input('title', 'Title', ['required' => true, 'maxlength' => 160]);
    f_input('expires_on', 'Valid until (optional)', ['type' => 'date']);
    f_file('doc_file', 'File', ['required' => true]);
    ?>
    <div class="field actions"><button class="btn" type="submit">Upload</button></div>
  </form>
</div>
<div class="card">
  <form method="get" class="toolbar no-print">
    <?php f_select('category', 'Type', DOC_CATEGORIES, ['value' => $cat]); ?>
    <div class="field"><label for="f_q">Search</label><input id="f_q" name="q" value="<?= e($search) ?>" placeholder="e.g. MRF Tosham" maxlength="100"></div>
    <button class="btn secondary" type="submit">Search</button>
  </form>
  <?php render_table([
      'Title' => fn($r) => e($r['title'] ?: $r['original_name']),
      'Type' => fn($r) => e(DOC_CATEGORIES[$r['category']] ?? $r['category']),
      'Belongs to' => fn($r) => $r['owner_type'] === 'FACILITY' ? '<a href="facilities.php?id=' . (int) $r['owner_id'] . '">' . e($r['facility_name']) . '</a>' : e(ucfirst(strtolower($r['owner_type']))),
      'Valid until' => function ($r) {
          if (!$r['expires_on']) return '–';
          $left = days_between(today(), $r['expires_on']);
          return e(ymd($r['expires_on'])) . ' ' . ($left < 0 ? badge('expired', 'err') : ($left <= 30 ? badge('expiring', 'warn') : ''));
      },
      'Uploaded' => fn($r) => e(substr($r['uploaded_at'], 0, 10)) . ' · ' . e($r['by_name'] ?? ''),
      'File' => fn($r) => doc_link((int) $r['id'], 'Open'),
  ], $rows, 'No documents found.'); ?>
</div>
<?php page_end();

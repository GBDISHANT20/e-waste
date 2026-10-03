<?php
// Action Taken Tracker: PENDING -> SUBMITTED (officer uploads proof) -> VERIFIED -> CLOSED (collector).
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$isCollector = $u['role'] === 'DISTRICT_COLLECTOR';
$officials = responsible_options($did);

function load_action(array $u, int $id): array
{
    $a = q_one('SELECT * FROM actions WHERE id = ? AND district_id = ?', [$id, $u['district_id']]);
    if (!$a) throw new UserError('Action not found');
    if ($u['role'] !== 'DISTRICT_COLLECTOR' && !in_array((int) $u['id'], [(int) $a['responsible_user_id'], (int) $a['supporting_user_id'], (int) $a['created_by']], true))
        throw new UserError('Action not found');
    return $a;
}

// ---------- create (collector) ----------
if ($isCollector && is_post() && ($_POST['do'] ?? '') === 'create') {
    csrf_check();
    try_action(function () use ($u, $did, $officials) {
        $issue = v_str($_POST['issue'] ?? '', 'Issue / direction', 250);
        $loc = v_str($_POST['location_text'] ?? '', 'Location', 250, false);
        $resp = v_int($_POST['responsible_user_id'] ?? '', 'Responsible officer', true);
        $supp = v_int($_POST['supporting_user_id'] ?? '', 'Supporting officer');
        if (!isset($officials[$resp]) || ($supp && !isset($officials[$supp]))) throw new UserError('Choose officers of this district');
        $deadline = v_date($_POST['deadline'] ?? '', 'Deadline', true, today());
        $meeting = v_int($_POST['meeting_id'] ?? '', 'Meeting');
        if ($meeting && !q_val('SELECT 1 FROM meetings WHERE id = ? AND district_id = ?', [$meeting, $did])) throw new UserError('Meeting not found');
        check_upload('before_photo', true);
        $res = in_tx(function () use ($u, $did, $issue, $loc, $resp, $supp, $deadline, $meeting) {
            $a = create_action($did, ['issue' => $issue, 'location_text' => $loc, 'responsible_user_id' => $resp, 'supporting_user_id' => $supp,
                'deadline' => $deadline, 'meeting_id' => $meeting], (int) $u['id']);
            $doc = save_upload('before_photo', $did, 'ACTION', $a['id'], 'PHOTO', 'Before photo', null, true);
            if ($doc) q('UPDATE actions SET before_doc_id = ? WHERE id = ?', [$doc, $a['id']]);
            return $a;
        });
        audit('create', 'action', $res['id'], ['no' => $res['action_no']]);
        flash('ok', 'Action ' . $res['action_no'] . ' created.');
        redirect('actions.php?id=' . $res['id']);
    });
}

// ---------- workflow steps ----------
if (is_post() && in_array($_POST['do'] ?? '', ['submit', 'verify', 'return', 'close'], true)) {
    csrf_check();
    $aid = (int) ($_POST['id'] ?? 0);
    try_action(function () use ($u, $did, $isCollector, $aid) {
        $a = load_action($u, $aid);
        $do = $_POST['do'];
        $mine = in_array((int) $u['id'], [(int) $a['responsible_user_id'], (int) $a['supporting_user_id']], true);
        if ($do === 'submit') {
            if (!$mine || $a['status'] !== 'PENDING') throw new UserError('This action cannot be submitted by you right now');
            $report = v_str($_POST['report_text'] ?? '', 'Compliance report', 2000);
            [$lat, $lng] = v_coords($_POST['lat'] ?? '', $_POST['lng'] ?? '');
            if (!has_upload('after_photo')) throw new UserError('Upload the “after” photograph as proof');
            check_upload('after_photo', true);
            in_tx(function () use ($did, $a, $report, $lat, $lng) {
                $doc = save_upload('after_photo', $did, 'ACTION', (int) $a['id'], 'PHOTO', 'After photo', null, true);
                q("UPDATE actions SET status = 'SUBMITTED', report_text = ?, after_doc_id = ?, lat = ?, lng = ?, submitted_at = NOW(), remark = NULL WHERE id = ?",
                    [$report, $doc, $lat, $lng, $a['id']]);
            });
            flash('ok', 'Action submitted for verification.');
        } else {
            if (!$isCollector) throw new UserError('Only the District Collector can do this');
            if ($do === 'verify' && $a['status'] === 'SUBMITTED') {
                q("UPDATE actions SET status = 'VERIFIED', verified_by = ?, verified_at = NOW() WHERE id = ?", [$u['id'], $a['id']]);
                flash('ok', 'Action verified.');
            } elseif ($do === 'return' && $a['status'] === 'SUBMITTED') {
                $remark = v_str($_POST['remark'] ?? '', 'Reason for returning', 500);
                q("UPDATE actions SET status = 'PENDING', remark = ? WHERE id = ?", [$remark, $a['id']]);
                flash('warn', 'Action returned to the officer.');
            } elseif ($do === 'close' && $a['status'] === 'VERIFIED') {
                q("UPDATE actions SET status = 'CLOSED', closed_at = NOW() WHERE id = ?", [$a['id']]);
                flash('ok', 'Action closed.');
            } else {
                throw new UserError('That step is not possible for this action in its current state');
            }
        }
        audit($do, 'action', (int) $a['id']);
        redirect('actions.php?id=' . $aid);
    });
    $_GET['id'] = (string) $aid;
}

function action_badge(array $a): string
{
    $overdue = in_array($a['status'], ['PENDING'], true) && $a['deadline'] < today();
    if ($overdue) return badge('overdue', 'err');
    return badge(ACTION_STATUSES[$a['status']], $a['status'] === 'CLOSED' ? '' : ($a['status'] === 'PENDING' ? 'warn' : ''));
}

// ---------- detail ----------
if (isset($_GET['id'])) {
    try { $a = load_action($u, (int) $_GET['id']); } catch (UserError $e) { flash('error', $e->getMessage()); redirect('actions.php'); }
    $names = fn($id) => $id ? ($officials[$id] ?? q_val('SELECT name FROM users WHERE id = ?', [$id])) : null;
    $mine = in_array((int) $u['id'], [(int) $a['responsible_user_id'], (int) $a['supporting_user_id']], true);
    $src = null;
    if ($a['meeting_id']) $src = 'Review meeting of ' . ymd((string) q_val('SELECT meeting_date FROM meetings WHERE id = ?', [$a['meeting_id']]));
    if ($a['inspection_id']) $src = 'Inspection of ' . ymd((string) q_val('SELECT inspected_on FROM inspections WHERE id = ?', [$a['inspection_id']]));
    page_start($a['action_no'], 'actions.php');
    ?>
    <p><a class="back" href="actions.php">← All actions</a></p>
    <h2>Action <?= e($a['action_no']) ?> <?= action_badge($a) ?></h2>
    <div class="card"><dl class="kv">
      <dt>Issue</dt><dd class="pre"><?= e($a['issue']) ?></dd>
      <dt>Location</dt><dd><?= e($a['location_text'] ?: '–') ?></dd>
      <dt>Responsible officer</dt><dd><?= e($names($a['responsible_user_id'])) ?></dd>
      <dt>Supporting officer</dt><dd><?= e($names($a['supporting_user_id']) ?? '–') ?></dd>
      <dt>Deadline</dt><dd><?= e(ymd($a['deadline'])) ?></dd>
      <dt>Status</dt><dd><?= e(ACTION_STATUSES[$a['status']]) ?></dd>
      <dt>Source</dt><dd><?= e($src ?? '–') ?></dd>
      <?php if ($a['remark']): ?><dt>Returned with remark</dt><dd class="pre"><?= e($a['remark']) ?></dd><?php endif; ?>
      <?php if ($a['report_text']): ?><dt>Compliance report</dt><dd class="pre"><?= e($a['report_text']) ?></dd><?php endif; ?>
      <?php if ($a['lat'] !== null): ?><dt>GPS of action</dt><dd><?= e($a['lat'] . ', ' . $a['lng']) ?> · <?= map_link($a['lat'], $a['lng'], 'Open map') ?></dd><?php endif; ?>
      <dt>Timeline</dt><dd>Created <?= e(substr($a['created_at'], 0, 16)) ?>
        <?= $a['submitted_at'] ? ' · Submitted ' . e(substr($a['submitted_at'], 0, 16)) : '' ?>
        <?= $a['verified_at'] ? ' · Verified ' . e(substr($a['verified_at'], 0, 16)) : '' ?>
        <?= $a['closed_at'] ? ' · Closed ' . e(substr($a['closed_at'], 0, 16)) : '' ?></dd>
    </dl>
    <?php if ($a['before_doc_id'] || $a['after_doc_id']): ?>
      <div class="photos">
        <?php if ($a['before_doc_id']): ?><figure><a href="download.php?id=<?= (int) $a['before_doc_id'] ?>" target="_blank" rel="noopener"><img alt="Before" src="download.php?id=<?= (int) $a['before_doc_id'] ?>"></a><figcaption>Before</figcaption></figure><?php endif; ?>
        <?php if ($a['after_doc_id']): ?><figure><a href="download.php?id=<?= (int) $a['after_doc_id'] ?>" target="_blank" rel="noopener"><img alt="After" src="download.php?id=<?= (int) $a['after_doc_id'] ?>"></a><figcaption>After</figcaption></figure><?php endif; ?>
      </div>
    <?php endif; ?>
    </div>

    <?php if ($mine && $a['status'] === 'PENDING'): ?>
    <div class="card"><h3>Submit action taken</h3>
      <form method="post" enctype="multipart/form-data" class="grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="submit"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <?php
        f_textarea('report_text', 'Compliance report – what was done', ['required' => true, 'wide' => true, 'maxlength' => 2000]);
        f_file('after_photo', '“After” photograph', ['required' => true, 'images' => true, 'camera' => true]);
        f_geo();
        ?>
        <div class="field actions"><button class="btn" type="submit">Submit for verification</button></div>
      </form></div>
    <?php endif; ?>

    <?php if ($isCollector && $a['status'] === 'SUBMITTED'): ?>
    <div class="card"><h3>Verify</h3>
      <form method="post" class="row-actions">
        <?= csrf_field() ?><input type="hidden" name="do" value="verify"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn" type="submit">Verify – work is done</button>
      </form>
      <form method="post" class="grid gap-top">
        <?= csrf_field() ?><input type="hidden" name="do" value="return"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <?php f_input('remark', 'Or return it to the officer – reason', ['maxlength' => 500, 'required' => true]); ?>
        <div class="field actions"><button class="btn secondary" type="submit">Return to officer</button></div>
      </form></div>
    <?php endif; ?>

    <?php if ($isCollector && $a['status'] === 'VERIFIED'): ?>
    <div class="card"><form method="post" class="row-actions">
      <?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
      <button class="btn" type="submit">Close this action</button></form></div>
    <?php endif; ?>
    <?php
    page_end();
    exit;
}

// ---------- list ----------
$status = isset($_GET['status']) && isset(ACTION_STATUSES[$_GET['status']]) ? $_GET['status'] : '';
$meetingId = (int) ($_GET['meeting_id'] ?? 0);
$sql = "SELECT a.*, r.name AS resp_name FROM actions a JOIN users r ON r.id = a.responsible_user_id WHERE a.district_id = ?";
$args = [$did];
if (!$isCollector) { $sql .= ' AND (a.responsible_user_id = ? OR a.supporting_user_id = ? OR a.created_by = ?)'; array_push($args, $u['id'], $u['id'], $u['id']); }
if ($status) { $sql .= ' AND a.status = ?'; $args[] = $status; }
if ($meetingId) { $sql .= ' AND a.meeting_id = ?'; $args[] = $meetingId; }
$rows = q_all($sql . ' ORDER BY FIELD(a.status, \'PENDING\', \'SUBMITTED\', \'VERIFIED\', \'CLOSED\'), a.deadline LIMIT 300', $args);
$meetings = $isCollector ? q_all('SELECT id, meeting_date FROM meetings WHERE district_id = ? ORDER BY meeting_date DESC LIMIT 20', [$did]) : [];

page_start('Action tracker', 'actions.php');
?>
<h2>Action Taken Tracker</h2>
<?php if ($isCollector): ?>
<div class="card">
  <h3>Issue a direction / action point</h3>
  <form method="post" enctype="multipart/form-data" class="grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <?php
    f_input('issue', 'Issue / direction (e.g. “Stop open dumping at Dhani Mahu”)', ['required' => true, 'maxlength' => 250]);
    f_input('location_text', 'Location', ['maxlength' => 250]);
    f_select('responsible_user_id', 'Responsible officer', $officials, ['required' => true]);
    f_select('supporting_user_id', 'Supporting officer', $officials);
    f_input('deadline', 'Deadline', ['type' => 'date', 'required' => true, 'min' => today()]);
    f_select('meeting_id', 'From review meeting', array_column(array_map(fn($m) => ['id' => $m['id'], 'l' => ymd($m['meeting_date'])], $meetings), 'l', 'id'), ['value' => $meetingId ? (string) $meetingId : '']);
    f_file('before_photo', '“Before” photograph', ['images' => true, 'camera' => true]);
    ?>
    <div class="field actions"><button class="btn" type="submit">Create action</button></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <form method="get" class="toolbar no-print">
    <?php f_select('status', 'Status', ACTION_STATUSES, ['value' => $status]); ?>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
  <?php render_table([
      'Action no.' => fn($r) => '<a href="actions.php?id=' . (int) $r['id'] . '">' . e($r['action_no']) . '</a>',
      'Issue' => fn($r) => e(mb_strimwidth($r['issue'], 0, 90, '…')),
      'Location' => 'location_text', 'Responsible' => 'resp_name',
      'Deadline' => fn($r) => e(ymd($r['deadline'])),
      'Status' => fn($r) => action_badge($r),
  ], $rows, 'No actions yet.'); ?>
</div>
<?php page_end();

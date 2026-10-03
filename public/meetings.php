<?php
// Quarterly SWM review meetings (Rule 37): the collector records them; officers can read them.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
$isCollector = $u['role'] === 'DISTRICT_COLLECTOR';

if ($isCollector && is_post()) {
    csrf_check();
    try_action(function () use ($u, $did) {
        $date = v_date($_POST['meeting_date'] ?? '', 'Meeting date', true, null, today());
        $venue = v_str($_POST['venue'] ?? '', 'Venue', 160, false);
        $chair = v_str($_POST['chairperson'] ?? '', 'Presiding officer', 160);
        $people = v_str($_POST['participants'] ?? '', 'Participants', 500, false);
        $params = [];
        foreach (REVIEW_PARAMETERS as $i => $p) if (isset($_POST['param_' . $i])) $params[] = $p;
        $decisions = v_str($_POST['decisions'] ?? '', 'Decisions', 3000, false);
        check_upload('minutes');
        [$fy, $qn] = quarter_of($date);
        $id = in_tx(function () use ($u, $did, $fy, $qn, $date, $venue, $chair, $people, $params, $decisions) {
            q('INSERT INTO meetings (district_id, fy_start, quarter_no, meeting_date, venue, chairperson, participants, parameters, decisions, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)', [$did, $fy, $qn, $date, $venue, $chair, $people, json_encode($params), $decisions, $u['id']]);
            $mid = last_id();
            save_upload('minutes', $did, 'MEETING', $mid, 'REPORT', 'Minutes of review meeting ' . ymd($date));
            return $mid;
        });
        audit('create', 'meeting', $id);
        flash('ok', 'Review meeting recorded. Now add the action points below.');
        redirect('actions.php?meeting_id=' . $id);
    });
}

$rows = q_all("SELECT m.*, (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id) AS actions,
                      (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id AND a.status = 'CLOSED') AS closed,
                      (SELECT d.id FROM documents d WHERE d.owner_type = 'MEETING' AND d.owner_id = m.id LIMIT 1) AS minutes_id
                 FROM meetings m WHERE m.district_id = ? ORDER BY m.meeting_date DESC", [$did]);
[, , $curLabel] = quarter_of(today());

page_start('Quarterly reviews', 'meetings.php');
?>
<h2>Quarterly SWM reviews</h2>
<p class="muted">Current quarter: <strong><?= e($curLabel) ?></strong></p>
<?php if ($isCollector): ?>
<div class="card">
  <h3>Record a review meeting</h3>
  <form method="post" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?>
    <div class="grid">
      <?php
      f_input('meeting_date', 'Meeting date', ['type' => 'date', 'required' => true, 'value' => today(), 'max' => today()]);
      f_input('venue', 'Venue', ['maxlength' => 160]);
      f_input('chairperson', 'Presiding officer', ['required' => true, 'maxlength' => 160, 'value' => $u['name']]);
      ?>
    </div>
    <?php f_textarea('participants', 'Participants (departments, ULB and panchayat representatives, HSPCB, operators)', ['maxlength' => 500, 'rows' => 2]); ?>
    <fieldset><legend>Parameters reviewed</legend><div class="checks">
      <?php foreach (REVIEW_PARAMETERS as $i => $p) f_checkbox('param_' . $i, $p); ?>
    </div></fieldset>
    <?php f_textarea('decisions', 'Decisions taken', ['maxlength' => 3000, 'rows' => 4]); ?>
    <div class="grid"><?php f_file('minutes', 'Upload minutes (PDF or photo)'); ?></div>
    <div><button class="btn" type="submit">Save meeting</button></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <?php render_table([
      'Quarter' => fn($r) => e("Q{$r['quarter_no']} " . fy_label((int) $r['fy_start'])),
      'Date' => fn($r) => e(ymd($r['meeting_date'])),
      'Presiding officer' => 'chairperson', 'Venue' => 'venue',
      'Reviewed' => fn($r) => e(count(json_decode((string) $r['parameters'], true) ?: [])) . ' of ' . count(REVIEW_PARAMETERS),
      'Decisions' => fn($r) => $r['decisions'] ? '<div class="pre">' . e($r['decisions']) . '</div>' : '–',
      'Action points' => fn($r) => '<a href="actions.php?meeting_id=' . (int) $r['id'] . '">' . e($r['closed'] . ' / ' . $r['actions']) . ' closed</a>',
      'Minutes' => fn($r) => doc_link($r['minutes_id'] ? (int) $r['minutes_id'] : null, 'Open'),
  ], $rows, 'No review meetings recorded yet.'); ?>
</div>
<?php page_end();

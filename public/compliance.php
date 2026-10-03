<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$items = compliance_items($u);
$groups = ['red' => 'Action required', 'orange' => 'Due soon / attention', 'info' => 'Upcoming', 'green' => 'Completed'];
page_start('Compliance calendar', 'compliance.php');
?>
<h2>Compliance calendar</h2>
<p class="muted">The system watches deadlines, reviews, inspections, documents and data updates for you.</p>
<div class="legend">
  <span><?= level_dot('red') ?> Action required</span><span><?= level_dot('orange') ?> Due soon</span>
  <span><?= level_dot('info') ?> Upcoming</span><span><?= level_dot('green') ?> Completed</span>
</div>
<?php foreach ($groups as $level => $title):
    $subset = array_values(array_filter($items, fn($i) => $i['level'] === $level));
    if (!$subset) continue; ?>
  <div class="card"><h3><?= e($title) ?> (<?= count($subset) ?>)</h3><?php render_compliance($subset); ?></div>
<?php endforeach;
if (!$items) echo '<div class="card"><p class="empty">Nothing needs attention right now.</p></div>';
page_end();

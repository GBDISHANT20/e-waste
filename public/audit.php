<?php
// Audit trail: who did what and when. Entries are never edited or deleted by the app.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STATE_ADMIN', 'DISTRICT_COLLECTOR');
$scope = $u['role'] === 'STATE_ADMIN' ? '' : ' WHERE us.district_id = ?';
$rows = q_all('SELECT a.at, a.action, a.entity, a.entity_id, a.detail, us.name, us.role
                 FROM audit_log a LEFT JOIN users us ON us.id = a.user_id' . $scope . ' ORDER BY a.id DESC LIMIT 300',
    $u['role'] === 'STATE_ADMIN' ? [] : [$u['district_id']]);
page_start('Audit log', 'audit.php');
?>
<h2>Audit log</h2>
<p class="muted">Latest 300 recorded changes. Entries cannot be edited or deleted from the app.</p>
<div class="card">
  <?php render_table([
      'When' => fn($r) => e($r['at']), 'Who' => fn($r) => e(($r['name'] ?? 'system') . ($r['role'] ? ' (' . ROLE_LABELS[$r['role']] . ')' : '')),
      'Action' => 'action', 'Record' => fn($r) => e($r['entity'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')),
      'Details' => fn($r) => $r['detail'] ? '<code class="small-text">' . e($r['detail']) . '</code>' : '–',
  ], $rows, 'Nothing recorded yet.'); ?>
</div>
<?php page_end();

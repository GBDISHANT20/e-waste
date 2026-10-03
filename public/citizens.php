<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
[$as, $aa] = area_scope($u, 'h.ward_id', 'h.village_id');
$rows = q_all("SELECT h.house_no, h.address, h.members, h.area_type, h.created_at, us.name, us.phone,
                      w.city, w.ward_no, vi.name AS village_name
                 FROM households h JOIN users us ON us.id = h.user_id
                 LEFT JOIN wards w ON w.id = h.ward_id LEFT JOIN villages vi ON vi.id = h.village_id
                WHERE h.district_id = ?" . $as . ' ORDER BY h.id DESC LIMIT 500', array_merge([$u['district_id']], $aa));

page_start('Citizens', 'citizens.php');
?>
<h2>Registered houses (citizens)</h2>
<div class="card">
  <?php render_table([
      'House owner' => 'name', 'Mobile' => 'phone', 'House no.' => 'house_no',
      'Area' => fn($r) => $r['area_type'] === 'URBAN' ? e($r['city'] . ' – Ward ' . $r['ward_no']) : e($r['village_name']),
      'Address' => 'address', 'Members' => 'members', 'Registered' => fn($r) => e(substr($r['created_at'], 0, 10)),
  ], $rows, 'No citizens have registered yet.'); ?>
</div>
<?php page_end();

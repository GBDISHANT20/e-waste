<?php
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('STAFF', 'CITIZEN');

if ($u['role'] === 'STAFF') {
    $p = q_one("SELECT s.staff_role, s.licence_no, v.reg_number, v.type, v.city, w.ward_no, vi.name AS village_name
                  FROM staff s LEFT JOIN vehicles v ON v.id = s.vehicle_id
                  LEFT JOIN wards w ON w.id = v.ward_id LEFT JOIN villages vi ON vi.id = v.village_id
                 WHERE s.user_id = ?", [$u['id']]) ?? [];
    $rows = [
        'Role' => isset($p['staff_role']) ? STAFF_ROLES[$p['staff_role']] : null,
        'Licence' => $p['licence_no'] ?? null,
        'Vehicle' => $p['reg_number'] ?? null,
        'Vehicle type' => isset($p['type']) ? VEHICLE_TYPES[$p['type']] : null,
        'Area' => !empty($p['ward_no']) ? $p['city'] . ' – Ward ' . $p['ward_no'] : ($p['village_name'] ?? null),
    ];
} else {
    $p = q_one("SELECT h.house_no, h.address, h.members, h.area_type, w.city, w.ward_no, vi.name AS village_name,
                       mc.name AS mc_name, mc.phone AS mc_phone, sp.name AS sp_name, sp.phone AS sp_phone
                  FROM households h
                  LEFT JOIN wards w ON w.id = h.ward_id LEFT JOIN users mc ON mc.id = w.mc_user_id
                  LEFT JOIN villages vi ON vi.id = h.village_id LEFT JOIN users sp ON sp.id = vi.sarpanch_user_id
                 WHERE h.user_id = ?", [$u['id']]) ?? [];
    $urban = ($p['area_type'] ?? '') === 'URBAN';
    $rows = [
        'House no.' => $p['house_no'] ?? null, 'Address' => $p['address'] ?? null, 'Family members' => $p['members'] ?? null,
        'Area' => $urban ? ($p['city'] . ' – Ward ' . $p['ward_no']) : ($p['village_name'] ?? null),
        $urban ? 'Your MC' : 'Your Sarpanch' => $urban ? trim(($p['mc_name'] ?? '') . ' ' . (isset($p['mc_phone']) ? '(' . $p['mc_phone'] . ')' : ''))
            : trim(($p['sp_name'] ?? '') . ' ' . (isset($p['sp_phone']) ? '(' . $p['sp_phone'] . ')' : '')),
    ];
}
page_start('My profile', 'profile.php');
?>
<h2>My profile</h2>
<div class="card">
  <dl class="details">
    <dt>Name</dt><dd><?= e($u['name']) ?></dd>
    <dt>Mobile</dt><dd><?= e($u['phone']) ?></dd>
    <?php foreach ($rows as $label => $val): ?>
      <dt><?= e($label) ?></dt><dd><?= ($val === null || $val === '') ? '–' : e($val) ?></dd>
    <?php endforeach; ?>
  </dl>
</div>
<?php page_end();

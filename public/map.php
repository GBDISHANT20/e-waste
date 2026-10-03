<?php
// District SWM map: plots geo-tagged facilities, dump points and open complaints. Self-contained (no external map service).
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR', 'MC', 'SARPANCH');
$did = (int) $u['district_id'];
[$fs, $fa] = area_scope($u, 'f.ward_id', 'f.village_id');
$points = [];
foreach (q_all('SELECT f.id, f.name, f.type, f.lat, f.lng FROM facilities f WHERE f.district_id = ? AND f.lat IS NOT NULL' . $fs, array_merge([$did], $fa)) as $f) {
    $color = match ($f['type']) {
        'MRF', 'COMPOST_PLANT', 'VERMICOMPOST', 'BIOMETHANATION', 'RDF', 'WASTE_TO_ENERGY', 'RECYCLING' => '#1e8e3e',
        'TRANSFER_STATION' => '#f29900', 'OPEN_DUMP', 'OPEN_BURNING' => '#d93025', 'LEGACY_SITE' => '#202124', 'LANDFILL' => '#1a73e8', default => '#80868b',
    };
    $points[] = ['kind' => 'F', 'name' => $f['name'], 'label' => FACILITY_TYPES[$f['type']], 'lat' => (float) $f['lat'], 'lng' => (float) $f['lng'],
        'color' => $color, 'link' => 'facilities.php?id=' . $f['id'], 'rawlat' => $f['lat'], 'rawlng' => $f['lng']];
}
[$cs, $ca] = area_scope($u, 'c.ward_id', 'c.village_id');
foreach (q_all("SELECT c.id, c.complaint_no, c.category, c.lat, c.lng FROM complaints c WHERE c.district_id = ? AND c.status <> 'CLOSED' AND c.lat IS NOT NULL" . $cs, array_merge([$did], $ca)) as $c) {
    $points[] = ['kind' => 'C', 'name' => $c['complaint_no'], 'label' => 'Open complaint – ' . COMPLAINT_CATEGORIES[$c['category']], 'lat' => (float) $c['lat'], 'lng' => (float) $c['lng'],
        'color' => '#d93025', 'link' => 'complaints.php?id=' . $c['id'], 'rawlat' => $c['lat'], 'rawlng' => $c['lng']];
}

page_start('Map', 'map.php');
?>
<h2>District SWM map</h2>
<div class="legend">
  <span><svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="#1e8e3e"/></svg> MRF / compost / biomethanation / RDF</span>
  <span><svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="#f29900"/></svg> Transfer station</span>
  <span><svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="#d93025"/></svg> Open dump / burning</span>
  <span><svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="#202124"/></svg> Legacy waste</span>
  <span><svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="#1a73e8"/></svg> Landfill</span>
  <span><svg width="14" height="14"><path d="M7 1 13 7 7 13 1 7Z" fill="none" stroke="#d93025" stroke-width="2"/></svg> Open complaint</span>
</div>
<div class="card">
<?php if (!$points): ?>
  <p class="empty">No geo-tagged facilities or complaints yet. Add GPS coordinates when registering a facility or reporting an issue.</p>
<?php else:
    $W = 800; $H = 520; $pad = 56;
    $lats = array_column($points, 'lat'); $lngs = array_column($points, 'lng');
    $minLat = min($lats); $maxLat = max($lats); $minLng = min($lngs); $maxLng = max($lngs);
    // give a single point (or a very tight cluster) some room so it sits in the middle of the map
    if ($maxLat - $minLat < 0.01) { $mid = ($maxLat + $minLat) / 2; $minLat = $mid - 0.005; $maxLat = $mid + 0.005; }
    if ($maxLng - $minLng < 0.01) { $mid = ($maxLng + $minLng) / 2; $minLng = $mid - 0.005; $maxLng = $mid + 0.005; }
    $spanLat = $maxLat - $minLat; $spanLng = $maxLng - $minLng;
    $x = fn($lng) => $pad + ($lng - $minLng) / $spanLng * ($W - 2 * $pad);
    $y = fn($lat) => $H - $pad - ($lat - $minLat) / $spanLat * ($H - 2 * $pad);
    ?>
  <svg class="mapbox" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Map of geo-tagged waste management locations">
    <rect x="<?= $pad ?>" y="<?= $pad ?>" width="<?= $W - 2 * $pad ?>" height="<?= $H - 2 * $pad ?>" fill="none" stroke="currentColor" stroke-opacity=".15"/>
    <text x="<?= $pad ?>" y="<?= $H - 12 ?>"><?= e(number_format($minLng, 3)) ?>°E</text>
    <text x="<?= $W - $pad ?>" y="<?= $H - 12 ?>" text-anchor="end"><?= e(number_format($maxLng, 3)) ?>°E</text>
    <text x="6" y="<?= $H - $pad - 6 ?>"><?= e(number_format($minLat, 3)) ?>°N</text>
    <text x="6" y="<?= $pad - 8 ?>"><?= e(number_format($maxLat, 3)) ?>°N</text>
    <?php foreach ($points as $p): $cx = $x($p['lng']); $cy = $y($p['lat']); ?>
      <a href="<?= e($p['link']) ?>"><title><?= e($p['name'] . ' – ' . $p['label']) ?></title>
      <?php if ($p['kind'] === 'C'): ?>
        <path d="M<?= round($cx, 1) ?> <?= round($cy - 14, 1) ?> L<?= round($cx + 14, 1) ?> <?= round($cy, 1) ?> L<?= round($cx, 1) ?> <?= round($cy + 14, 1) ?> L<?= round($cx - 14, 1) ?> <?= round($cy, 1) ?>Z" fill="#fff" fill-opacity=".6" stroke="<?= e($p['color']) ?>" stroke-width="3"/>
      <?php else: ?>
        <circle cx="<?= round($cx, 1) ?>" cy="<?= round($cy, 1) ?>" r="14" fill="<?= e($p['color']) ?>" stroke="#fff" stroke-width="3"/>
      <?php endif; ?></a>
    <?php endforeach; ?>
  </svg>
  <p class="muted small-text">Points are placed by their GPS coordinates (north is up). Tap a point to open its details.</p>
<?php endif; ?>
</div>
<?php if ($points): ?>
<div class="card"><?php render_table([
    'Name' => fn($r) => '<a href="' . e($r['link']) . '">' . e($r['name']) . '</a>', 'What' => 'label',
    'Coordinates' => fn($r) => e($r['rawlat'] . ', ' . $r['rawlng']), 'Street map' => fn($r) => map_link($r['rawlat'], $r['rawlng'], 'Open'),
], $points); ?></div>
<?php endif; ?>
<?php page_end();

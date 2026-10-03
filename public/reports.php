<?php
// Annual report (Form IV-style summary): one click builds it from everything entered during the financial year.
require __DIR__ . '/../src/bootstrap.php';
$u = require_role('DISTRICT_COLLECTOR');
$did = (int) $u['district_id'];
$curFy = fy_start_of(today());
$fy = isset($_GET['fy']) ? (int) $_GET['fy'] : $curFy - 1;
if ($fy < $curFy - 6 || $fy > $curFy) $fy = $curFy - 1;
$from = "$fy-04-01";
$to = ($fy + 1) . '-03-31';

// ---------- mark as submitted ----------
if (is_post()) {
    csrf_check();
    try_action(function () use ($u, $did, $fy) {
        $on = v_date($_POST['submitted_on'] ?? '', 'Submission date', true, null, today());
        q('INSERT INTO annual_reports (district_id, fy_start, submitted_on, submitted_by) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE submitted_on = VALUES(submitted_on), submitted_by = VALUES(submitted_by)', [$did, $fy, $on, $u['id']]);
        audit('submit', 'annual_report', null, ['fy' => $fy]);
        flash('ok', 'Annual report ' . fy_label($fy) . ' marked as submitted.');
        redirect('reports.php?fy=' . $fy);
    });
}

// ---------- CSV of all daily waste entries ----------
if (isset($_GET['csv'])) {
    $safe = fn($v) => ($v !== null && $v !== '' && strpbrk((string) $v[0], '=+-@') !== false) ? "'" . $v : $v;   // stop spreadsheet formula injection
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="waste-data-' . $fy . '-' . ($fy + 1) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'City', 'Ward', 'Village', 'Vehicle', 'Generated kg', 'Collected kg', 'Wet kg', 'Dry kg', 'Mixed kg', 'Processed kg', 'Landfilled kg', 'Slip no', 'Check', 'Entered by']);
    $st = q('SELECT e.entry_date, w.city, w.ward_no, vi.name AS village, v.reg_number, e.generated_kg, e.collected_kg, e.wet_kg, e.dry_kg, e.mixed_kg, e.processed_kg,
                    e.landfilled_kg, e.slip_no, e.flags, us.name AS by_name
               FROM waste_entries e LEFT JOIN wards w ON w.id = e.ward_id LEFT JOIN villages vi ON vi.id = e.village_id
               LEFT JOIN vehicles v ON v.id = e.vehicle_id LEFT JOIN users us ON us.id = e.created_by
              WHERE e.district_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id', [$did, $from, $to]);
    while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($out, array_map($safe, $r));
    exit;
}

$d = q_one('SELECT * FROM districts WHERE id = ?', [$did]);
$collector = q_val("SELECT CONCAT(us.name, ' (', us.phone, ')') FROM collectors c JOIN users us ON us.id = c.user_id WHERE c.district_id = ? AND c.active = 1", [$did]);
$count = fn(string $sql, array $a = []) => (int) q_val($sql, array_merge([$did], $a));
$totals = q_one('SELECT COALESCE(SUM(generated_kg),0) gen, COALESCE(SUM(collected_kg),0) col, COALESCE(SUM(wet_kg),0) wet, COALESCE(SUM(dry_kg),0) dry,
                        COALESCE(SUM(mixed_kg),0) mix, COALESCE(SUM(processed_kg),0) pro, COALESCE(SUM(landfilled_kg),0) lan, COUNT(*) n, SUM(flags IS NOT NULL) flagged
                   FROM waste_entries WHERE district_id = ? AND entry_date BETWEEN ? AND ?', [$did, $from, $to]);
$byCity = q_all("SELECT w.city, COUNT(DISTINCT w.id) AS wards,
                        (SELECT COUNT(*) FROM households h JOIN wards w2 ON w2.id = h.ward_id WHERE w2.district_id = w.district_id AND w2.city = w.city) AS houses,
                        (SELECT COUNT(*) FROM vehicles v JOIN wards w3 ON w3.id = v.ward_id WHERE w3.district_id = w.district_id AND w3.city = w.city) AS vehicles,
                        COALESCE((SELECT SUM(e.collected_kg) FROM waste_entries e JOIN wards w4 ON w4.id = e.ward_id WHERE w4.district_id = w.district_id AND w4.city = w.city AND e.entry_date BETWEEN ? AND ?),0) AS collected,
                        COALESCE((SELECT SUM(e.processed_kg) FROM waste_entries e JOIN wards w4 ON w4.id = e.ward_id WHERE w4.district_id = w.district_id AND w4.city = w.city AND e.entry_date BETWEEN ? AND ?),0) AS processed
                   FROM wards w WHERE w.district_id = ? GROUP BY w.city, w.district_id ORDER BY w.city", [$from, $to, $from, $to, $did]);
$byVillage = q_all("SELECT v.name, v.block, s.name AS sarpanch,
                           (SELECT COUNT(*) FROM households h WHERE h.village_id = v.id) AS houses,
                           COALESCE((SELECT SUM(e.collected_kg) FROM waste_entries e WHERE e.village_id = v.id AND e.entry_date BETWEEN ? AND ?),0) AS collected,
                           COALESCE((SELECT SUM(e.processed_kg) FROM waste_entries e WHERE e.village_id = v.id AND e.entry_date BETWEEN ? AND ?),0) AS processed
                      FROM villages v LEFT JOIN users s ON s.id = v.sarpanch_user_id WHERE v.district_id = ? ORDER BY v.name", [$from, $to, $from, $to, $did]);
$infra = q_all('SELECT type, COUNT(*) n, COALESCE(SUM(capacity_tpd),0) cap, SUM(status = \'OPERATIONAL\') ok FROM facilities WHERE district_id = ? GROUP BY type ORDER BY type', [$did]);
$monthly = q_all("SELECT DATE_FORMAT(entry_date, '%Y-%m') m, SUM(collected_kg) c, SUM(processed_kg) p, SUM(landfilled_kg) l FROM waste_entries
                   WHERE district_id = ? AND entry_date BETWEEN ? AND ? GROUP BY m ORDER BY m", [$did, $from, $to]);
$act = q_one("SELECT COUNT(*) n, SUM(status = 'CLOSED') closed, SUM(status <> 'CLOSED' AND deadline < CURDATE()) overdue FROM actions WHERE district_id = ? AND DATE(created_at) BETWEEN ? AND ?", [$did, $from, $to]);
$insp = q_one('SELECT COUNT(*) n, COALESCE(SUM(violation),0) v FROM inspections WHERE district_id = ? AND inspected_on BETWEEN ? AND ?', [$did, $from, $to]);
$meet = q_all('SELECT quarter_no, COUNT(*) n FROM meetings WHERE district_id = ? AND fy_start = ? GROUP BY quarter_no', [$did, $fy]);
$meetBy = array_column($meet, 'n', 'quarter_no');
$comp = q_one("SELECT COUNT(*) n, SUM(status = 'CLOSED') closed FROM complaints WHERE district_id = ? AND DATE(created_at) BETWEEN ? AND ?", [$did, $from, $to]);
$pick = q_one('SELECT COUNT(*) n, SUM(registration_status = \'REGISTERED\') reg, SUM(trained) tr, SUM(has_ppe) ppe FROM waste_pickers WHERE district_id = ?', [$did]);
$docs = $count('SELECT COUNT(*) FROM documents WHERE district_id = ? AND DATE(uploaded_at) BETWEEN ? AND ?', [$from, $to]);
$sub = q_one('SELECT submitted_on FROM annual_reports WHERE district_id = ? AND fy_start = ?', [$did, $fy]);
$kg = fn($v) => number_format((float) $v, 0);
$pct = fn($a, $b) => (float) $b > 0 ? number_format($a / $b * 100, 1) . '%' : '–';

page_start('Annual report', 'reports.php');
?>
<h2>Annual SWM report <?= e(fy_label($fy)) ?></h2>
<form method="get" class="toolbar no-print">
  <div class="field"><label for="f_fy">Financial year</label>
    <select id="f_fy" name="fy"><?php for ($y = $curFy; $y >= $curFy - 6; $y--): ?><option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= e(fy_label($y)) ?></option><?php endfor; ?></select></div>
  <button class="btn secondary" type="submit">Show</button>
  <button class="btn" type="button" data-print>Print / save as PDF</button>
  <a class="btn secondary" href="reports.php?fy=<?= $fy ?>&amp;csv=1">Download waste data (CSV)</a>
</form>

<div class="card"><h3>District summary</h3><dl class="kv">
  <dt>District</dt><dd><?= e($d['name'] . ', ' . $d['state']) ?></dd>
  <dt>District Collector</dt><dd><?= e($collector ?? '–') ?></dd>
  <dt>Period</dt><dd><?= e(ymd($from) . ' to ' . ymd($to)) ?></dd>
  <dt>Urban wards</dt><dd><?= $count('SELECT COUNT(*) FROM wards WHERE district_id = ?') ?></dd>
  <dt>Villages</dt><dd><?= $count('SELECT COUNT(*) FROM villages WHERE district_id = ?') ?></dd>
  <dt>Registered households</dt><dd><?= $count('SELECT COUNT(*) FROM households WHERE district_id = ?') ?></dd>
  <dt>Vehicles / staff</dt><dd><?= $count('SELECT COUNT(*) FROM vehicles WHERE district_id = ?') ?> vehicles · <?= $count('SELECT COUNT(*) FROM staff WHERE district_id = ?') ?> drivers &amp; staff</dd>
  <dt>Status</dt><dd><?= $sub ? badge('Submitted on ' . ymd($sub['submitted_on'])) : badge('Not yet submitted', 'warn') ?></dd>
</dl></div>

<div class="card"><h3>Waste quantities (kg)</h3>
<?php render_table([
    'Generated' => fn($r) => $kg($r['gen']), 'Collected' => fn($r) => $kg($r['col']), 'Wet' => fn($r) => $kg($r['wet']), 'Dry' => fn($r) => $kg($r['dry']),
    'Mixed' => fn($r) => $kg($r['mix']), 'Processed' => fn($r) => $kg($r['pro']), 'Landfilled' => fn($r) => $kg($r['lan']),
    'Processed %' => fn($r) => $pct($r['pro'], $r['col']), 'Records' => fn($r) => e($r['n']) . ((int) $r['flagged'] ? ' (' . (int) $r['flagged'] . ' to verify)' : ''),
], [$totals]); ?>
<h3 class="gap-top">Month by month</h3>
<?php render_table(['Month' => fn($r) => e(date('M Y', strtotime($r['m'] . '-01'))), 'Collected' => fn($r) => $kg($r['c']), 'Processed' => fn($r) => $kg($r['p']), 'Landfilled' => fn($r) => $kg($r['l'])], $monthly, 'No waste data for this year.'); ?>
</div>

<div class="card"><h3>Urban local bodies (by city)</h3>
<?php render_table(['City' => 'city', 'Wards' => 'wards', 'Houses' => 'houses', 'Vehicles' => 'vehicles', 'Collected (kg)' => fn($r) => $kg($r['collected']), 'Processed (kg)' => fn($r) => $kg($r['processed'])], $byCity, 'No wards registered.'); ?></div>
<div class="card"><h3>Gram panchayats (villages)</h3>
<?php render_table(['Village' => 'name', 'Block' => 'block', 'Sarpanch' => 'sarpanch', 'Houses' => 'houses', 'Collected (kg)' => fn($r) => $kg($r['collected']), 'Processed (kg)' => fn($r) => $kg($r['processed'])], $byVillage, 'No villages registered.'); ?></div>
<div class="card"><h3>Infrastructure</h3>
<?php render_table(['Facility type' => fn($r) => e(FACILITY_TYPES[$r['type']]), 'Number' => 'n', 'Operational' => 'ok', 'Capacity (TPD)' => fn($r) => e(number_format((float) $r['cap'], 1))], $infra, 'No facilities registered.'); ?></div>

<div class="card"><h3>Compliance, violations and action taken</h3><dl class="kv">
  <dt>Quarterly reviews held</dt><dd><?php foreach ([1, 2, 3, 4] as $q) echo 'Q', $q, ': ', (int) ($meetBy[$q] ?? 0), $q < 4 ? ' · ' : ''; ?></dd>
  <dt>Inspections</dt><dd><?= (int) $insp['n'] ?> inspections · <?= (int) $insp['v'] ?> with violations</dd>
  <dt>Action points</dt><dd><?= (int) $act['n'] ?> issued · <?= (int) $act['closed'] ?> closed · <?= (int) $act['overdue'] ?> overdue</dd>
  <dt>Citizen complaints</dt><dd><?= (int) $comp['n'] ?> received · <?= (int) $comp['closed'] ?> closed</dd>
  <dt>Waste pickers</dt><dd><?= (int) $pick['n'] ?> registered in database · <?= (int) $pick['reg'] ?> formally registered · <?= (int) $pick['tr'] ?> trained · <?= (int) $pick['ppe'] ?> with PPE</dd>
  <dt>Supporting documents &amp; photos</dt><dd><?= $docs ?> uploaded in the year</dd>
</dl></div>

<div class="card no-print"><h3>Mark as submitted</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php f_input('submitted_on', 'Date of submission', ['type' => 'date', 'required' => true, 'value' => $sub['submitted_on'] ?? today(), 'max' => today()]); ?>
    <div class="field actions"><button class="btn" type="submit">Save</button></div>
  </form>
  <p class="muted small-text">Records that this report was filed with the authority. It turns the compliance calendar item green.</p>
</div>
<?php page_end();

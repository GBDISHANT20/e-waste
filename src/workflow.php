<?php
declare(strict_types=1);
/* Shared logic for the action tracker and complaints. */

/** Officials who can be made responsible for an action. */
function responsible_options(int $districtId): array
{
    $rows = q_all("SELECT id, name, role FROM users WHERE district_id = ? AND active = 1 AND role IN ('MC','SARPANCH','DISTRICT_COLLECTOR') ORDER BY role, name", [$districtId]);
    $short = ['MC' => 'MC', 'SARPANCH' => 'Sarpanch', 'DISTRICT_COLLECTOR' => 'Collector'];
    $out = [];
    foreach ($rows as $r) $out[$r['id']] = $r['name'] . ' (' . $short[$r['role']] . ')';
    return $out;
}

/**
 * Creates a numbered action point (SWM/<district code>/<year>/00001). Call inside a transaction.
 * $a: issue, location_text, responsible_user_id, supporting_user_id, deadline, meeting_id, inspection_id
 */
function create_action(int $districtId, array $a, int $createdBy): array
{
    $code = (string) q_val('SELECT code FROM districts WHERE id = ?', [$districtId]);
    $year = (int) date('Y');
    $no = sprintf('SWM/%s/%d/%05d', $code, $year, next_number($districtId, 'ACTION', $year));
    q('INSERT INTO actions (district_id, action_no, meeting_id, inspection_id, issue, location_text, responsible_user_id, supporting_user_id, deadline, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$districtId, $no, $a['meeting_id'] ?? null, $a['inspection_id'] ?? null, $a['issue'], $a['location_text'] ?? null,
            $a['responsible_user_id'], $a['supporting_user_id'] ?? null, $a['deadline'], $createdBy]);
    return ['id' => last_id(), 'action_no' => $no];
}

/**
 * Validates and stores a citizen complaint (from the public form or a logged-in citizen).
 * Returns the complaint number. Must be called from within try_action().
 */
function submit_complaint(array $in, ?array $user): string
{
    $name = v_str($in['name'] ?? '', 'Your name');
    $phone = v_phone($in['phone'] ?? '', 'Your mobile number');
    $did = v_int($in['district_id'] ?? '', 'District', true);
    if (!q_val('SELECT 1 FROM districts WHERE id = ?', [$did])) throw new UserError('Choose a district');
    $type = v_enum($in['area_type'] ?? '', ['URBAN', 'RURAL'], 'area type');
    $wardId = $villageId = null;
    if ($type === 'URBAN') {
        $wardId = v_int($in['ward_id'] ?? '', 'Ward', true);
        if (!q_val('SELECT 1 FROM wards WHERE id = ? AND district_id = ?', [$wardId, $did])) throw new UserError('Choose the ward');
    } else {
        $villageId = v_int($in['village_id'] ?? '', 'Village', true);
        if (!q_val('SELECT 1 FROM villages WHERE id = ? AND district_id = ?', [$villageId, $did])) throw new UserError('Choose the village');
    }
    $cat = v_enum($in['category'] ?? '', COMPLAINT_CATEGORIES, 'issue type');
    $desc = v_str($in['description'] ?? '', 'Description', 1000);
    [$lat, $lng] = v_coords($in['lat'] ?? '', $in['lng'] ?? '');
    check_upload('photo', true);
    if ((int) q_val('SELECT COUNT(*) FROM complaints WHERE citizen_phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)', [$phone]) >= 5)
        throw new UserError('Too many complaints from this number today. Please try again tomorrow.');
    $year = (int) date('Y');
    return in_tx(function () use ($name, $phone, $did, $wardId, $villageId, $cat, $desc, $lat, $lng, $user, $year) {
        $no = sprintf('SWM-C-%d-%06d', $year, next_number(0, 'COMPLAINT', $year));
        q('INSERT INTO complaints (complaint_no, district_id, ward_id, village_id, category, description, lat, lng, citizen_name, citizen_phone, user_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$no, $did, $wardId, $villageId, $cat, $desc, $lat, $lng, $name, $phone, $user['id'] ?? null]);
        $cid = last_id();
        $photo = save_upload('photo', $did, 'COMPLAINT', $cid, 'PHOTO', null, null, true, $user['id'] ?? null);
        if ($photo) q('UPDATE complaints SET photo_doc_id = ? WHERE id = ?', [$photo, $cid]);
        return $no;
    });
}

/** Warnings for a daily waste entry (shown as "Please verify data"; the entry is still saved). */
function waste_flags(array $v, ?array $facility): array
{
    $f = [];
    $num = fn($k) => ($v[$k] ?? null) === null || $v[$k] === '' ? null : (float) $v[$k];
    $gen = $num('generated_kg'); $col = (float) $v['collected_kg'];
    $wet = $num('wet_kg'); $dry = $num('dry_kg'); $mix = $num('mixed_kg');
    $proc = $num('processed_kg'); $land = $num('landfilled_kg');
    if ($gen !== null && $col > $gen) $f[] = 'Collected quantity is more than the waste generated';
    if ($wet !== null || $dry !== null || $mix !== null) {
        $sum = ($wet ?? 0) + ($dry ?? 0) + ($mix ?? 0);
        if (abs($sum - $col) > max(1.0, $col * 0.05)) $f[] = 'Wet + dry + mixed does not match the collected quantity';
    }
    if ($proc !== null || $land !== null) {
        if (($proc ?? 0) + ($land ?? 0) > $col * 1.05 + 1) $f[] = 'Processed + landfilled is more than the collected quantity';
    }
    if ($facility && $facility['capacity_tpd'] !== null && $proc !== null && $proc / 1000 > (float) $facility['capacity_tpd']) {
        $f[] = 'Capacity exceeded at ' . $facility['name'] . ' (' . $facility['capacity_tpd'] . ' TPD)';
    }
    return $f;
}

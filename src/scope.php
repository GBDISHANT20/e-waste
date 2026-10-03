<?php
declare(strict_types=1);
/*
 * Data visibility rules.
 *  - District Collector: everything in their district.
 *  - MC: only the wards allotted to them.   - Sarpanch: only their villages.
 */

function my_ward_ids(array $u): array
{
    return array_map('intval', array_column(q_all('SELECT id FROM wards WHERE mc_user_id = ?', [$u['id']]), 'id'));
}
function my_village_ids(array $u): array
{
    return array_map('intval', array_column(q_all('SELECT id FROM villages WHERE sarpanch_user_id = ?', [$u['id']]), 'id'));
}

/** SQL fragment (starting with " AND ") limiting rows to the user's wards/villages. */
function area_scope(array $u, string $wardCol, string $villageCol): array
{
    if ($u['role'] === 'MC') {
        $ids = my_ward_ids($u);
        return [' AND ' . $wardCol . ' IN (' . ($ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL') . ')', $ids];
    }
    if ($u['role'] === 'SARPANCH') {
        $ids = my_village_ids($u);
        return [' AND ' . $villageCol . ' IN (' . ($ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL') . ')', $ids];
    }
    return ['', []];
}

function visible_wards(array $u): array
{
    if ($u['role'] === 'SARPANCH') return [];
    [$sql, $args] = area_scope($u, 'w.id', 'NULL');
    return q_all('SELECT w.id, w.city, w.ward_no, w.ward_name FROM wards w WHERE w.district_id = ?' . $sql . ' ORDER BY w.city, w.ward_no',
        array_merge([$u['district_id']], $args));
}
function visible_villages(array $u): array
{
    if ($u['role'] === 'MC') return [];
    [$sql, $args] = area_scope($u, 'NULL', 'v.id');
    return q_all('SELECT v.id, v.block, v.name FROM villages v WHERE v.district_id = ?' . $sql . ' ORDER BY v.name',
        array_merge([$u['district_id']], $args));
}

/** May this user manage things located in this ward / village? */
function can_manage_area(array $u, ?int $wardId, ?int $villageId): bool
{
    if ($u['role'] === 'MC') return $wardId !== null && in_array($wardId, my_ward_ids($u), true);
    if ($u['role'] === 'SARPANCH') return $villageId !== null && in_array($villageId, my_village_ids($u), true);
    return $u['role'] === 'DISTRICT_COLLECTOR';
}

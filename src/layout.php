<?php
declare(strict_types=1);

function nav_for(array $u): array
{
    return match ($u['role']) {
        'STATE_ADMIN' => ['dashboard.php' => 'Overview', 'districts.php' => 'Districts', 'collectors.php' => 'District Collectors'],
        'DISTRICT_COLLECTOR' => ['dashboard.php' => 'Overview', 'wards.php' => 'Wards & MCs', 'villages.php' => 'Villages & Sarpanches',
            'vehicles.php' => 'Vehicles', 'staff.php' => 'Drivers & staff', 'citizens.php' => 'Citizens'],
        'MC' => ['dashboard.php' => 'Overview', 'wards.php' => 'My wards', 'vehicles.php' => 'Vehicles',
            'staff.php' => 'Drivers & staff', 'citizens.php' => 'Citizens'],
        'SARPANCH' => ['dashboard.php' => 'Overview', 'villages.php' => 'My village', 'vehicles.php' => 'Vehicles',
            'staff.php' => 'Drivers & staff', 'citizens.php' => 'Citizens'],
        default => ['profile.php' => 'My profile'],
    };
}

function page_start(string $title, string $active = '', bool $bare = false): void
{
    $u = current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>', e($title), ' · SWM Portal</title>',
        '<link rel="icon" href="data:,">',
        '<link rel="stylesheet" href="assets/style.css"></head><body>';
    if ($bare || !$u) {
        echo '<main class="auth">';
    } else {
        $nav = nav_for($u);
        if (!$u['must_change_password']) $nav['password.php'] = 'Change password';
        echo '<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">',
            '<header class="topbar"><a class="brand" href="', e(home_for($u)), '">SWM Portal</a>',
            '<span class="who">', e($u['name']), ' · ', e(ROLE_LABELS[$u['role']]), '</span>',
            '<label for="nav-toggle" class="burger" aria-label="Menu"><span></span><span></span><span></span></label>',
            '<form method="post" action="logout.php" class="logout">', csrf_field(), '<button class="btn small ghost">Logout</button></form>',
            '</header><div class="layout"><nav class="side" aria-label="Main">';
        foreach ($nav as $file => $label) {
            echo '<a href="', e($file), '"', $file === $active ? ' class="active" aria-current="page"' : '', '>', e($label), '</a>';
        }
        echo '</nav><main>';
    }
    foreach (take_flashes() as [$type, $msg, $html]) {
        echo '<div class="notice ', $type === 'error' ? 'err' : ($type === 'warn' ? 'warn' : 'ok'), '" role="', $type === 'error' ? 'alert' : 'status', '">', $html ? $msg : e($msg), '</div>';
    }
}

function page_end(): void
{
    $u = current_user();
    echo '</main>';
    if ($u) echo '</div>';
    echo '<script src="assets/app.js"></script></body></html>';
}

/** Flash text containing a temporary password (the only place we emit trusted markup). */
function temp_password_notice(string $what, string $password): string
{
    return e($what) . ' registered. Temporary password (shown only once – share it securely): <code>' . e($password) . '</code>';
}

// ---------- form helpers ----------
function f_input(string $name, string $label, array $o = []): void
{
    $type = $o['type'] ?? 'text';
    $attrs = '';
    foreach (['maxlength', 'min', 'inputmode', 'placeholder', 'autocomplete', 'pattern', 'minlength'] as $a) {
        if (isset($o[$a])) $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
    }
    $val = $type === 'password' ? '' : old($name, $o['value'] ?? '');
    echo '<div class="field"><label for="f_', e($name), '">', e($label), !empty($o['required']) ? ' <span class="req">*</span>' : '', '</label>',
        '<input id="f_', e($name), '" name="', e($name), '" type="', e($type), '" value="', e($val), '"', $attrs,
        !empty($o['required']) ? ' required' : '', '></div>';
}

/** $options: value => label. $extra: value => [data-attr => value] */
function f_select(string $name, string $label, array $options, array $o = []): void
{
    $cur = old($name, $o['value'] ?? '');
    echo '<div class="field"', isset($o['wrap']) ? ' ' . $o['wrap'] : '', '><label for="f_', e($name), '">', e($label),
        !empty($o['required']) ? ' <span class="req">*</span>' : '', '</label>',
        '<select id="f_', e($name), '" name="', e($name), '"', !empty($o['required']) ? ' required' : '', '>',
        '<option value="">', !empty($o['required']) ? 'Select…' : '— none —', '</option>';
    foreach ($options as $v => $l) {
        $data = '';
        foreach (($o['data'][$v] ?? []) as $k => $dv) $data .= ' data-' . e($k) . '="' . e($dv) . '"';
        echo '<option value="', e($v), '"', (string) $v === $cur ? ' selected' : '', $data, '>', e($l), '</option>';
    }
    echo '</select></div>';
}

/**
 * Responsive table: columns are [label => callable(row): string|key]. On phones every row becomes a card
 * and each cell shows its label (data-label). Callables return already-escaped HTML.
 */
function render_table(array $cols, array $rows, string $empty = 'Nothing registered yet.'): void
{
    if (!$rows) { echo '<p class="empty">', e($empty), '</p>'; return; }
    echo '<div class="table-wrap"><table class="responsive"><thead><tr>';
    foreach (array_keys($cols) as $label) echo '<th scope="col">', e($label), '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $label => $c) {
            $html = is_callable($c) ? $c($r) : (($r[$c] ?? '') === '' || $r[$c] === null ? '–' : e($r[$c]));
            echo '<td data-label="', e($label), '">', $html === '' ? '&nbsp;' : $html, '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function badge(string $text, string $kind = ''): string
{
    return '<span class="badge ' . e($kind) . '">' . e($text) . '</span>';
}

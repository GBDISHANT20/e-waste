<?php
declare(strict_types=1);

/** A mistake the user can fix; its message is shown on the page. */
class UserError extends RuntimeException {}

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['config'];
        $pdo = new PDO(
            "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function q_one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function q_val(string $sql, array $params = []): mixed { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }
function last_id(): int { return (int) db()->lastInsertId(); }

function in_tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
}

function audit(string $action, string $entity, ?int $id = null, array $detail = []): void
{
    $u = current_user();
    q('INSERT INTO audit_log (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)',
        [$u['id'] ?? null, $action, $entity, $id, $detail ? json_encode($detail) : null]);
}

/** Turns duplicate-key database errors into friendly messages. */
function friendly_db_error(PDOException $ex): ?string
{
    if (($ex->errorInfo[1] ?? 0) !== 1062) return null;
    $m = $ex->getMessage();
    return match (true) {
        str_contains($m, 'uq_user_phone') => 'This mobile number is already registered',
        str_contains($m, 'uq_vehicle_reg') => 'This vehicle is already registered',
        str_contains($m, 'uq_ward') => 'This ward already exists in that city',
        str_contains($m, 'uq_village') => 'This village already exists',
        str_contains($m, 'uq_district_code') => 'This district code is already used',
        default => 'This entry already exists',
    };
}

/** Runs a form action, showing UserError / duplicate messages on the page. Returns true on success. */
function try_action(callable $fn): bool
{
    try {
        $fn();
        return true;
    } catch (UserError $e) {
        flash_now('error', $e->getMessage());
    } catch (PDOException $e) {
        $msg = friendly_db_error($e);
        if ($msg === null) throw $e;
        flash_now('error', $msg);
    }
    return false;
}

// ---------- request helpers ----------
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function old(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

// ---------- flash messages ----------
// Messages are HTML-escaped when shown, unless $html is true (only for text we built and escaped ourselves).
function flash(string $type, string $msg, bool $html = false): void { $_SESSION['flash'][] = [$type, $msg, $html]; }
function flash_now(string $type, string $msg, bool $html = false): void { $GLOBALS['flash_now'][] = [$type, $msg, $html]; }
function take_flashes(): array
{
    $all = array_merge($_SESSION['flash'] ?? [], $GLOBALS['flash_now'] ?? []);
    unset($_SESSION['flash']);
    $GLOBALS['flash_now'] = [];
    return $all;
}

// ---------- validation ----------
function v_str(mixed $v, string $label, int $max = 120, bool $required = true): ?string
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        if ($required) throw new UserError("$label is required");
        return null;
    }
    if (mb_strlen($s) > $max) throw new UserError("$label is too long");
    return $s;
}
function v_phone(mixed $v, string $label = 'Mobile number'): string
{
    $s = v_str($v, $label, 10);
    if (!preg_match('/^[6-9][0-9]{9}$/', $s)) throw new UserError("$label must be a valid 10-digit mobile number");
    return $s;
}
function v_int(mixed $v, string $label, bool $required = false, int $max = 4000000): ?int
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        if ($required) throw new UserError("$label is required");
        return null;
    }
    if (!ctype_digit($s) || (int) $s > $max) throw new UserError("$label must be a whole number");
    return (int) $s;
}
function v_enum(mixed $v, array $allowed, string $label): string
{
    if (!is_string($v) || !isset($allowed[$v]) && !in_array($v, $allowed, true)) throw new UserError("Choose a valid $label");
    return $v;
}
function v_password(mixed $v, string $label = 'Password'): string
{
    if (!is_string($v) || mb_strlen($v) < 8) throw new UserError("$label must be at least 8 characters");
    if (strlen($v) > 200) throw new UserError("$label is too long");
    return $v;
}

function temp_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

// ---------- labels ----------
const VEHICLE_TYPES = [
    'TRACTOR_TROLLEY' => 'Tractor trolley', 'E_RICKSHAW' => 'E-rickshaw', 'MINI_TRUCK' => 'Mini truck',
    'TIPPER' => 'Tipper', 'COMPACTOR' => 'Compactor', 'HANDCART' => 'Handcart',
];
const STAFF_ROLES = ['DRIVER' => 'Driver', 'HELPER' => 'Helper', 'SUPERVISOR' => 'Supervisor'];
const VEHICLE_STATUSES = ['ACTIVE' => 'Active', 'MAINTENANCE' => 'Maintenance', 'RETIRED' => 'Retired'];
const ROLE_LABELS = [
    'STATE_ADMIN' => 'State Admin', 'DISTRICT_COLLECTOR' => 'District Collector',
    'MC' => 'Municipal Councillor (MC)', 'SARPANCH' => 'Sarpanch', 'STAFF' => 'Driver / Staff', 'CITIZEN' => 'Citizen',
];
function ward_label(array $w): string
{
    return $w['city'] . ' – Ward ' . $w['ward_no'] . (!empty($w['ward_name']) ? ' (' . $w['ward_name'] . ')' : '');
}

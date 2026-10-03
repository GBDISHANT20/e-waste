<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false && ($cache['id'] ?? null) === ($_SESSION['uid'] ?? null)) return $cache;
    $cache = null;
    if (!empty($_SESSION['uid'])) {
        $cache = q_one('SELECT id, name, phone, role, district_id, must_change_password
                          FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
        if (!$cache) unset($_SESSION['uid']);
    }
    return $cache;
}

function home_for(array $u): string
{
    return in_array($u['role'], ['STAFF', 'CITIZEN'], true) ? 'profile.php' : 'dashboard.php';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    if ($u['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') redirect('password.php');
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        page_start('Not allowed', '');
        echo '<div class="card"><h2>Not allowed</h2><p>Your role cannot open this page.</p></div>';
        page_end();
        exit;
    }
    return $u;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['csrf']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

function create_user(string $name, string $phone, string $role, ?int $districtId, ?string $password = null): array
{
    $temp = $password === null ? temp_password() : null;
    q('INSERT INTO users (name, phone, password_hash, role, district_id, must_change_password) VALUES (?,?,?,?,?,?)',
        [$name, $phone, password_hash($password ?? $temp, PASSWORD_DEFAULT), $role, $districtId, $password === null ? 1 : 0]);
    return ['id' => last_id(), 'temp_password' => $temp];
}

/** Returns the user id on success; throws UserError otherwise. Locks out after repeated failures. */
function attempt_login(string $phone, string $password): int
{
    $cfg = $GLOBALS['config'];
    $f = q_one('SELECT fails, locked_until FROM login_failures WHERE phone = ?', [$phone]);
    if ($f && $f['locked_until'] > time()) {
        throw new UserError('Too many failed attempts. Try again in ' . $cfg['lockout_minutes'] . ' minutes.');
    }
    $u = q_one('SELECT id, password_hash FROM users WHERE phone = ? AND active = 1', [$phone]);
    // verify against a dummy hash for unknown numbers so timing does not reveal registered numbers
    static $dummy = null;
    $hash = $u['password_hash'] ?? ($dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT));
    if (!password_verify($password, $hash) || !$u) {
        $expired = $f && $f['locked_until'] > 0 && $f['locked_until'] <= time();
        $n = ($f && !$expired ? (int) $f['fails'] : 0) + 1;
        $lock = $n >= $cfg['max_login_failures'] ? time() + $cfg['lockout_minutes'] * 60 : 0;
        q('INSERT INTO login_failures (phone, fails, locked_until) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until)', [$phone, $n, $lock]);
        throw new UserError('Invalid mobile number or password');
    }
    q('DELETE FROM login_failures WHERE phone = ?', [$phone]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    return (int) $u['id'];
}

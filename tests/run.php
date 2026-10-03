<?php
// End-to-end test over real HTTP:  php tests/run.php
// Uses the database TEST_DB_NAME (default swm_test) as user TEST_DB_USER / TEST_DB_PASS (default swm / swm_pass) – the database is WIPED.
declare(strict_types=1);

$root = dirname(__DIR__);
$env = [
    'DB_NAME' => getenv('TEST_DB_NAME') ?: 'swm_test',
    'DB_USER' => getenv('TEST_DB_USER') ?: 'swm',
    'DB_PASS' => getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : 'swm_pass',
    'ADMIN_PHONE' => '9999999999',
    'ADMIN_PASSWORD' => 'AdminPass123',
] + getenv();

// fresh database
passthru('cd ' . escapeshellarg($root) . ' && ' . implode(' ', array_map(fn($k) => $k . '=' . escapeshellarg((string) $env[$k]), ['DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_PHONE', 'ADMIN_PASSWORD']))
    . ' php database/install.php --fresh > /dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "install failed\n"); exit(1); }

$port = 18080 + random_int(0, 500);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
register_shutdown_function(fn() => proc_terminate($proc));
$base = "http://127.0.0.1:$port/";
for ($i = 0; $i < 50 && !@file_get_contents($base . 'login.php'); $i++) usleep(100000);

$fails = 0; $count = 0;
function check(bool $cond, string $msg): void
{
    global $fails, $count;
    $count++;
    if (!$cond) { $fails++; echo "  FAIL: $msg\n"; }
}

class Client
{
    public string $jar;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'swmjar'); }
    public function req(string $method, string $path, array $data = [], bool $follow = true): array
    {
        global $base;
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ch = null; // PHP 8: the cookie jar is only written when the handle is destroyed, i.e. before we follow a redirect
        $headers = substr($raw, 0, $hs); $body = substr($raw, $hs);
        $loc = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : null;
        if ($follow && $loc) return $this->req('GET', $loc);
        return ['status' => $status, 'body' => $body, 'loc' => $loc, 'headers' => $headers];
    }
    public function get(string $path): array { return $this->req('GET', $path); }
    /** Loads the form page to get a CSRF token, then posts the fields to $path. */
    public function post(string $path, array $data, ?string $formPage = null, bool $follow = true): array
    {
        $page = $this->req('GET', $formPage ?? $path);
        preg_match('/name="_csrf" value="([0-9a-f]+)"/', $page['body'], $m);
        return $this->req('POST', $path, $data + ['_csrf' => $m[1] ?? ''], $follow);
    }
    public function login(string $phone, string $pw): array { return $this->post('login.php', ['phone' => $phone, 'password' => $pw]); }
    public function logout(): void { $this->post('logout.php', [], 'login.php'); }
}
function temp_pw(array $r): string { preg_match('/<code>([^<]+)<\/code>/', $r['body'], $m); return $m[1] ?? ''; }
function login_ok(string $phone, string $pw): ?Client
{
    $c = new Client(); $r = $c->login($phone, $pw);
    return str_contains($r['body'], 'Invalid mobile number') ? null : $c;
}
function first_id(string $sql, array $args = []): int
{
    $c = new PDO('mysql:host=127.0.0.1;dbname=' . getenv('TEST_DB_NAME_RESOLVED'), getenv('TEST_DB_USER_RESOLVED'), getenv('TEST_DB_PASS_RESOLVED'));
    $st = $c->prepare($sql); $st->execute($args); return (int) $st->fetchColumn();
}
putenv('TEST_DB_NAME_RESOLVED=' . $env['DB_NAME']);
putenv('TEST_DB_USER_RESOLVED=' . $env['DB_USER']);
putenv('TEST_DB_PASS_RESOLVED=' . $env['DB_PASS']);

echo "Running SWM end-to-end tests on {$env['DB_NAME']}\n";

// --- access control & CSRF ---
$anon = new Client();
check(str_contains($anon->get('dashboard.php')['body'], 'Login'), 'anonymous is redirected to login');
check($anon->req('POST', 'login.php', ['phone' => '9999999999', 'password' => 'AdminPass123'], false)['status'] === 400, 'login POST without CSRF token is rejected');
check($anon->login('9999999999', 'wrong')['body'] !== '' && str_contains($anon->login('9999999999', 'wrong')['body'], 'Invalid mobile number or password'), 'wrong password rejected');

// --- State admin ---
$admin = login_ok('9999999999', 'AdminPass123');
check($admin !== null, 'state admin can log in');
check($admin->get('districts.php')['status'] === 200, 'admin opens districts page');
$admin->post('districts.php', ['name' => 'Bhiwani', 'state' => 'Haryana', 'code' => 'bwn']);
$admin->post('districts.php', ['name' => 'Hisar', 'state' => 'Haryana', 'code' => 'HSR']);
check(str_contains($admin->post('districts.php', ['name' => 'Dup', 'state' => 'Haryana', 'code' => 'BWN'])['body'], 'district code is already used'), 'duplicate district code rejected');
$d1 = first_id("SELECT id FROM districts WHERE code='BWN'"); $d2 = first_id("SELECT id FROM districts WHERE code='HSR'");
check($d1 > 0 && $d2 > 0, 'districts created (code upper-cased)');

$r = $admin->post('collectors.php', ['district_id' => $d1, 'name' => 'DC One', 'phone' => '9810000001', 'employee_id' => 'E1']);
$dcPw = temp_pw($r); check($dcPw !== '', 'collector gets a temporary password');
$dc = login_ok('9810000001', $dcPw); check($dc !== null, 'collector can log in');
check(str_contains($dc->get('dashboard.php')['body'], 'Set a new password'), 'collector is forced to change the temporary password');
$dc->post('password.php', ['old_password' => $dcPw, 'new_password' => 'short']);
check(str_contains($dc->get('password.php')['body'], 'Set a new password'), 'weak new password rejected');
$dc->post('password.php', ['old_password' => $dcPw, 'new_password' => 'DcPassword1']);
check(str_contains($dc->get('dashboard.php')['body'], 'Overview'), 'after changing password the dashboard opens');
check($dc->get('districts.php')['status'] === 403, 'collector cannot open admin pages');

// --- Collector registrations ---
$r = $dc->post('wards.php', ['city' => 'Bhiwani', 'ward_no' => '5', 'mc_name' => 'MC Five', 'mc_phone' => '9810000005']); $mc5Pw = temp_pw($r);
$r = $dc->post('wards.php', ['city' => 'Bhiwani', 'ward_no' => '6', 'mc_name' => 'MC Six', 'mc_phone' => '9810000006']);
check($mc5Pw !== '', 'MC gets a temporary password');
check(str_contains($dc->post('wards.php', ['city' => 'Bhiwani', 'ward_no' => '5', 'mc_name' => 'X', 'mc_phone' => '9810000099'])['body'], 'already exists'), 'duplicate ward rejected');
check(str_contains($dc->post('wards.php', ['city' => 'Bhiwani', 'ward_no' => '7', 'mc_name' => 'X', 'mc_phone' => '12345'])['body'], 'valid 10-digit'), 'bad mobile rejected');
$r = $dc->post('villages.php', ['name' => 'Dhani Mahu', 'block' => 'Bhiwani', 'sarpanch_name' => 'Sarpanch D', 'sarpanch_phone' => '9810000007']); $spPw = temp_pw($r);
check($spPw !== '', 'Sarpanch gets a temporary password');
$w5 = first_id("SELECT id FROM wards WHERE ward_no='5'"); $w6 = first_id("SELECT id FROM wards WHERE ward_no='6'"); $v1 = first_id("SELECT id FROM villages WHERE name='Dhani Mahu'");

// vehicles
check(str_contains($dc->post('vehicles.php', ['reg_number' => 'bad', 'type' => 'TIPPER', 'ward_id' => $w5])['body'], 'looks invalid'), 'bad registration number rejected');
check(str_contains($dc->post('vehicles.php', ['reg_number' => 'HR16AB1234', 'type' => 'TIPPER', 'ward_id' => $w5, 'village_id' => $v1])['body'], 'not both'), 'ward and village together rejected');
$dc->post('vehicles.php', ['reg_number' => 'hr 16 ab 1234', 'type' => 'TIPPER', 'capacity_kg' => '1500', 'ward_id' => $w5]);
$veh1 = first_id("SELECT id FROM vehicles WHERE reg_number='HR16AB1234'"); check($veh1 > 0, 'vehicle registered with normalised number');
check(str_contains($dc->post('vehicles.php', ['reg_number' => 'HR16AB1234', 'type' => 'TIPPER', 'ward_id' => $w5])['body'], 'already registered'), 'duplicate vehicle rejected');
$dc->post('vehicles.php', ['reg_number' => 'HR16CD5678', 'type' => 'HANDCART', 'ward_id' => $w6]);
$veh2 = first_id("SELECT id FROM vehicles WHERE reg_number='HR16CD5678'");

// staff
check(str_contains($dc->post('staff.php', ['name' => 'Ram', 'phone' => '9810000010', 'staff_role' => 'DRIVER', 'vehicle_id' => $veh1])['body'], 'Licence number is required'), 'driver needs a licence number');
$r = $dc->post('staff.php', ['name' => 'Ram Driver', 'phone' => '9810000010', 'staff_role' => 'DRIVER', 'licence_no' => 'HR0620200001', 'vehicle_id' => $veh1]); $drvPw = temp_pw($r);
check($drvPw !== '', 'driver registered with temporary password');

// --- Citizens ---
$cz = new Client();
$reg = $cz->post('register.php', ['name' => 'Sita <b>Devi</b>', 'phone' => '9810000020', 'password' => 'longenough1', 'district_id' => $d1, 'area_type' => 'URBAN', 'ward_id' => $w5, 'house_no' => '12/A'], 'register.php');
check(str_contains($reg['body'], 'My profile') && str_contains($reg['body'], 'Your MC'), 'citizen self-registers and lands on profile');
check(str_contains($reg['body'], 'MC Five'), 'citizen sees their MC');
check(!str_contains($reg['body'], '<b>Devi</b>') && str_contains($reg['body'], '&lt;b&gt;Devi&lt;/b&gt;'), 'user input is HTML-escaped (XSS)');
$c2 = new Client();
check(str_contains($c2->post('register.php', ['name' => 'Bad', 'phone' => '9810000021', 'password' => 'longenough1', 'district_id' => $d2, 'area_type' => 'URBAN', 'ward_id' => $w5, 'house_no' => '1'], 'register.php')['body'], 'Choose your ward'), 'ward of another district rejected');
$c3 = new Client();
check(str_contains($c3->post('register.php', ['name' => 'Gopal', 'phone' => '9810000022', 'password' => 'longenough1', 'district_id' => $d1, 'area_type' => 'RURAL', 'village_id' => $v1, 'house_no' => '3'], 'register.php')['body'], 'Your Sarpanch'), 'rural citizen registers');
$c4 = new Client();
check(str_contains($c4->post('register.php', ['name' => 'Dup', 'phone' => '9810000020', 'password' => 'longenough1', 'district_id' => $d1, 'area_type' => 'URBAN', 'ward_id' => $w5, 'house_no' => '9'], 'register.php')['body'], 'already registered'), 'duplicate phone rejected');
check($cz->get('citizens.php')['status'] === 403, 'citizen cannot open the citizens list');
check($cz->get('vehicles.php')['status'] === 403, 'citizen cannot open vehicles');

// --- MC scoping ---
$mc = login_ok('9810000005', $mc5Pw);
$mc->post('password.php', ['old_password' => $mc5Pw, 'new_password' => 'McPassword1']);
$wardsPage = $mc->get('wards.php')['body'];
check(str_contains($wardsPage, 'MC Five') && !str_contains($wardsPage, 'MC Six'), 'MC sees only their own ward');
check(str_contains($mc->get('citizens.php')['body'], '12/A') && !str_contains($mc->get('citizens.php')['body'], 'Gopal'), 'MC sees only own-ward citizens');
$vp = $mc->get('vehicles.php')['body'];
check(str_contains($vp, '>HR16AB1234<') && !str_contains($vp, '>HR16CD5678<'), 'MC sees only own-ward vehicles');
check(str_contains($mc->post('vehicles.php', ['reg_number' => 'HR16EF9999', 'type' => 'TIPPER', 'ward_id' => $w6])['body'], 'own ward'), 'MC cannot register a vehicle in another ward');
$mc->post('vehicles.php', ['action' => 'status', 'id' => $veh2, 'status' => 'RETIRED']);
check(first_id("SELECT COUNT(*) FROM vehicles WHERE id=$veh2 AND status='ACTIVE'") === 1, "MC cannot change another ward's vehicle");
$mc->post('vehicles.php', ['action' => 'status', 'id' => $veh1, 'status' => 'MAINTENANCE']);
check(first_id("SELECT COUNT(*) FROM vehicles WHERE id=$veh1 AND status='MAINTENANCE'") === 1, 'MC can change own ward vehicle status');
check($mc->get('villages.php')['status'] === 403, 'MC cannot open villages');
check(str_contains($mc->post('staff.php', ['name' => 'Shyam', 'phone' => '9810000011', 'staff_role' => 'HELPER'])['body'], 'Choose a vehicle'), 'MC must assign staff to a vehicle of their area');

// --- Sarpanch scoping ---
$sp = login_ok('9810000007', $spPw);
$sp->post('password.php', ['old_password' => $spPw, 'new_password' => 'SpPassword1']);
check(str_contains($sp->get('citizens.php')['body'], 'Gopal') && !str_contains($sp->get('citizens.php')['body'], '12/A'), 'Sarpanch sees only village citizens');
check(!str_contains($sp->get('vehicles.php')['body'], 'data-label="Reg. no.">HR16'), 'Sarpanch sees no city vehicles');

// --- driver profile ---
$drv = login_ok('9810000010', $drvPw);
check(str_contains($drv->get('profile.php')['body'], 'Set a new password'), 'driver is forced to change the temporary password');
$drv->post('password.php', ['old_password' => $drvPw, 'new_password' => 'DriverPass1']);
check(str_contains($drv->get('profile.php')['body'], 'HR16AB1234'), 'driver sees assigned vehicle on profile');

// --- district isolation + collector transfer ---
$r = $admin->post('collectors.php', ['district_id' => $d2, 'name' => 'DC Two', 'phone' => '9820000001', 'employee_id' => 'E2']); $dc2Pw = temp_pw($r);
$dc2 = login_ok('9820000001', $dc2Pw);
$dc2->post('password.php', ['old_password' => $dc2Pw, 'new_password' => 'DcTwoPass1']);
check(!str_contains($dc2->get('wards.php')['body'], 'MC Five'), 'other district collector sees nothing of Bhiwani');
$dc2->post('vehicles.php', ['action' => 'status', 'id' => $veh1, 'status' => 'RETIRED']);
check(first_id("SELECT COUNT(*) FROM vehicles WHERE id=$veh1 AND status='RETIRED'") === 0, 'other district cannot modify vehicle');
$r = $admin->post('collectors.php', ['district_id' => $d1, 'name' => 'DC New', 'phone' => '9810000002', 'employee_id' => 'E3']);
check(str_contains($r['body'], 'previous collector'), 'transfer notice shown');
check(login_ok('9810000001', 'DcPassword1') === null, 'old collector account is deactivated');
$dcNewPw = temp_pw($r); $dcNew = login_ok('9810000002', $dcNewPw);
$dcNew->post('password.php', ['old_password' => $dcNewPw, 'new_password' => 'DcNewPass1']);
check(str_contains($dcNew->get('wards.php')['body'], 'MC Five'), 'new collector sees the district data');
check(str_contains($admin->get('dashboard.php')['body'], 'By district'), 'admin dashboard lists districts');

// --- lockout + logout ---
$bf = new Client();
for ($i = 0; $i < 5; $i++) $bf->login('9000000000', 'x');
check(str_contains($bf->login('9000000000', 'x')['body'], 'Too many failed attempts'), 'login locks after repeated failures');
$dc->logout();
check(str_contains($dc->get('dashboard.php')['body'], 'Login'), 'logout ends the session');

echo ($fails === 0 ? "OK" : "FAILED") . " – $count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);

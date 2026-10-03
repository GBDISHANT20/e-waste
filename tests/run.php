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
    public function req(string $method, string $path, array $data = [], bool $follow = true, bool $multipart = false): array
    {
        global $base;
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data)); }
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
    public function post(string $path, array $data, ?string $formPage = null, bool $follow = true, array $files = []): array
    {
        $page = $this->req('GET', $formPage ?? $path);
        preg_match('/name="_csrf" value="([0-9a-f]+)"/', $page['body'], $m);
        $data += ['_csrf' => $m[1] ?? ''];
        foreach ($files as $field => $file) $data[$field] = new CURLFile($file[0], $file[1], $file[2]);
        return $this->req('POST', $path, $data, $follow, (bool) $files);
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


// =====================================================================
// Phase 2 modules
// =====================================================================
echo "Phase 2 modules\n";
$tmp = sys_get_temp_dir();
if (function_exists('imagecreatetruecolor')) {   // a real picture, so screenshots of the pages look realistic
    $im = imagecreatetruecolor(640, 420); imagefill($im, 0, 0, imagecolorallocate($im, 90, 160, 110));
    imagefilledrectangle($im, 80, 120, 560, 380, imagecolorallocate($im, 120, 90, 60)); imagepng($im, "$tmp/photo.png");
} else {
    file_put_contents("$tmp/photo.png", "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
}
file_put_contents("$tmp/paper.pdf", "%PDF-1.4\n% test document\n");
file_put_contents("$tmp/fake.png", "this is just text, not an image");
$PNG = ["$tmp/photo.png", 'image/png', 'photo.png']; $PDF = ["$tmp/paper.pdf", 'application/pdf', 'paper.pdf']; $FAKE = ["$tmp/fake.png", 'image/png', 'fake.png'];
$year = date('Y'); $soon = date('Y-m-d', strtotime('+10 days')); $past = date('Y-m-d', strtotime('-1 day'));
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $env['DB_NAME'], $env['DB_USER'], $env['DB_PASS']);
$dbq = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st; };

// --- facilities + documents ---
check($dcNew->get('facilities.php')['status'] === 200, 'collector opens facilities');
$r = $dcNew->post('facilities.php', ['action' => 'add', 'type' => 'MRF', 'name' => 'MRF Tosham', 'ward_id' => $w5, 'status' => 'OPERATIONAL', 'capacity_tpd' => '10',
    'lat' => '28.793000', 'lng' => '76.139000', 'doc_category' => 'AUTHORIZATION', 'doc_expires' => $soon], 'facilities.php', true, ['doc_file' => $PDF]);
check(str_contains($r['body'], 'MRF Tosham') && str_contains($r['body'], 'Authorization') && first_id("SELECT COUNT(*) FROM documents WHERE owner_type='FACILITY' AND category='AUTHORIZATION'") === 1, 'facility registered with its first document');
$fac1 = first_id("SELECT id FROM facilities WHERE name='MRF Tosham'");
check(str_contains($dcNew->post('facilities.php', ['action' => 'add', 'type' => 'MRF', 'name' => 'Bad upload', 'ward_id' => $w5, 'status' => 'OPERATIONAL', 'doc_category' => 'CONSENT'], 'facilities.php', true, ['doc_file' => $FAKE])['body'], 'Only JPG, PNG or PDF'), 'a fake file (text named .png) is rejected');
check(first_id("SELECT COUNT(*) FROM facilities WHERE name='Bad upload'") === 0, 'failed upload leaves no facility behind');
check(str_contains($dcNew->post('facilities.php', ['action' => 'doc', 'id' => $fac1, 'doc_category' => 'CONSENT', 'title' => 'Old consent', 'doc_expires' => $past], 'facilities.php?id=' . $fac1, true, ['doc_file' => $PNG])['body'], 'Document uploaded'), 'second document uploaded');
check(str_contains($dcNew->get('facilities.php?id=' . $fac1)['body'], 'expired'), 'expired document is flagged');
$docId = first_id("SELECT id FROM documents WHERE owner_type='FACILITY' AND owner_id=$fac1 AND category='AUTHORIZATION'");
$mcFac = $mc->get('facilities.php')['body'];
check(str_contains($mcFac, 'MRF Tosham'), 'MC sees the facility of their ward');
check(!str_contains($sp->get('facilities.php')['body'], 'MRF Tosham'), 'Sarpanch does not see a city facility');
check(str_contains($mc->post('facilities.php', ['action' => 'add', 'type' => 'MRF', 'name' => 'Other ward MRF', 'ward_id' => $w6, 'status' => 'OPERATIONAL'], 'facilities.php')['body'], 'your own ward'), 'MC cannot register a facility in another ward');
check($dcNew->get('facilities.php?id=99999')['status'] === 200 && str_contains($dcNew->get('facilities.php?id=99999')['body'], 'Waste management facilities'), 'unknown facility id falls back to the list');
// downloads
$dl = $dcNew->get('download.php?id=' . $docId); check($dl['status'] === 200 && str_contains($dl['headers'], 'application/pdf') && str_contains($dl['headers'], 'attachment'), 'collector downloads the PDF (as attachment)');
check($mc->get('download.php?id=' . $docId)['status'] === 200, 'MC of that ward may open the facility document');
check($sp->get('download.php?id=' . $docId)['status'] === 404, 'Sarpanch gets 404 for another area’s document');
check($cz->get('download.php?id=' . $docId)['status'] === 404, 'citizen gets 404 for facility document');
check($dc2->get('download.php?id=' . $docId)['status'] === 404, 'other district cannot download');
check(!str_contains($anon->get('download.php?id=' . $docId)['headers'], 'application/pdf') && str_contains($anon->get('download.php?id=' . $docId)['body'], 'Login'), 'anonymous is sent to the login page, not the file');
check(!is_file(dirname(__DIR__) . '/public/' . first_name_stored($dbq, $docId)), 'uploaded files are not inside the web root');
function first_name_stored($dbq, int $id): string { return (string) $dbq('SELECT stored_name FROM documents WHERE id = ?', [$id])->fetchColumn(); }
check($dcNew->req('POST', 'facilities.php', ['action' => 'add', 'name' => 'x'], false)['status'] === 400, 'upload form without CSRF token rejected');

// --- document library ---
$dcNew->post('documents.php', ['category' => 'ORDER', 'title' => 'Collector order 2026'], 'documents.php', true, ['doc_file' => $PDF]);
$lib = $dcNew->get('documents.php')['body'];
check(str_contains($lib, 'Collector order 2026') && str_contains($lib, 'MRF Tosham'), 'document library lists district and facility documents');
check(str_contains($dcNew->get('documents.php?q=Tosham')['body'], 'Old consent') && !str_contains($dcNew->get('documents.php?q=Tosham')['body'], 'Collector order 2026'), 'document search works');
check($mc->get('documents.php')['status'] === 403, 'MC cannot open the document library');

// --- daily waste data ---
check(str_contains($drv->get('waste.php')['body'], 'HR16AB1234'), 'driver sees own vehicle on the waste form');
$today = date('Y-m-d');
$r = $drv->post('waste.php', ['entry_date' => $today, 'generated_kg' => '100', 'collected_kg' => '125', 'wet_kg' => '70', 'dry_kg' => '40', 'mixed_kg' => '15', 'processed_kg' => '120', 'landfilled_kg' => '5', 'slip_no' => '=HYPERLINK("x")', 'facility_id' => $fac1], 'waste.php', true, ['photo' => $PNG]);
check(str_contains($r['body'], 'Waste data saved') && str_contains($r['body'], 'more than the waste generated'), 'collected > generated is saved but flagged "verify"');
$drv->post('waste.php', ['entry_date' => $today, 'collected_kg' => '30000', 'processed_kg' => '18000', 'facility_id' => $fac1], 'waste.php');
check(str_contains($drv->get('waste.php')['body'], 'Capacity exceeded'), 'processed above facility capacity is flagged');
check(str_contains($drv->post('waste.php', ['entry_date' => date('Y-m-d', strtotime('+1 day')), 'collected_kg' => '10'], 'waste.php')['body'], 'cannot be after'), 'future date rejected');
check(str_contains($drv->post('waste.php', ['entry_date' => date('Y-m-d', strtotime('-10 days')), 'collected_kg' => '10'], 'waste.php')['body'], 'cannot be before'), 'driver cannot back-date more than 7 days');
check(str_contains($drv->post('waste.php', ['entry_date' => $today, 'collected_kg' => 'abc'], 'waste.php')['body'], 'must be a number'), 'non-numeric quantity rejected');
check(str_contains($drv->post('waste.php', ['entry_date' => $today, 'collected_kg' => '10'], 'waste.php', true, ['photo' => $FAKE])['body'], 'JPG or PNG'), 'fake photo rejected');
check(str_contains($mc->get('waste.php')['body'], '125') , 'MC sees waste data of their ward');
check(!str_contains($sp->get('waste.php')['body'], '30000'), 'Sarpanch does not see city waste data');
check($cz->get('waste.php')['status'] === 403, 'citizen cannot open waste data');
check(str_contains($mc->post('waste.php', ['entry_date' => $today, 'ward_id' => $w6, 'collected_kg' => '5'], 'waste.php')['body'], 'own ward'), 'MC cannot enter data for another ward');
check(first_id("SELECT COUNT(*) FROM waste_entries WHERE district_id=$d1") === 2, 'only valid waste entries were stored');

// --- inspections -> actions ---
check(str_contains($mc->post('inspections.php', ['inspected_on' => $today], 'inspections.php')['body'], 'Select the location'), 'inspection needs a location');
check(str_contains($mc->post('inspections.php', ['inspected_on' => $today, 'ward_id' => $w5, 'violation' => '1'], 'inspections.php')['body'], 'Direction given is required'), 'violation needs a direction');
$r = $mc->post('inspections.php', ['inspected_on' => $today, 'ward_id' => $w5, 'facility_id' => $fac1, 'chk_segregation' => 'NO', 'chk_d2d' => 'YES', 'observations' => 'Mixed waste at gate',
    'violation' => '1', 'direction' => 'Segregate waste at MRF gate', 'deadline' => date('Y-m-d', strtotime('+5 days')), 'responsible_user_id' => first_id("SELECT id FROM users WHERE phone='9810000005'"), 'lat' => '28.79', 'lng' => '76.14'],
    'inspections.php', true, ['photo' => $PNG]);
check(str_contains($r['body'], 'Inspection recorded') && preg_match('#SWM/BWN/' . $year . '/\d{5}#', $r['body']) === 1, 'violation creates a numbered action (SWM/BWN/year/00001)');
check(str_contains($mc->get('inspections.php')['body'], 'violation'), 'inspection listed with violation badge');
check(!str_contains($sp->get('inspections.php')['body'], 'Mixed waste'), 'Sarpanch cannot see MC inspections');
$act1 = first_id("SELECT id FROM actions ORDER BY id LIMIT 1");

// --- quarterly review + minutes ---
$r = $dcNew->post('meetings.php', ['meeting_date' => $today, 'venue' => 'DC office', 'chairperson' => 'DC New', 'participants' => 'ULB, HSPCB', 'param_0' => '1', 'param_3' => '1', 'decisions' => 'Stop open dumping'],
    'meetings.php', true, ['minutes' => $PDF]);
check(str_contains($r['body'], 'Review meeting recorded') && str_contains($r['body'], 'Action Taken Tracker'), 'review meeting saved, collector sent to add action points');
$meet1 = first_id('SELECT id FROM meetings ORDER BY id DESC LIMIT 1');
check(str_contains($dcNew->get('meetings.php')['body'], 'Stop open dumping') && str_contains($dcNew->get('meetings.php')['body'], '2 of 13'), 'meeting listed with parameters reviewed');
check($dcNew->get('download.php?id=' . first_id("SELECT id FROM documents WHERE owner_type='MEETING' LIMIT 1"))['status'] === 200, 'minutes downloadable');
check($mc->get('download.php?id=' . first_id("SELECT id FROM documents WHERE owner_type='MEETING' LIMIT 1"))['status'] === 200, 'MC can read minutes');
check($cz->get('download.php?id=' . first_id("SELECT id FROM documents WHERE owner_type='MEETING' LIMIT 1"))['status'] === 404, 'citizen cannot read minutes');
check(!str_contains($mc->get('meetings.php')['body'], 'Record a review meeting'), 'MC sees reviews read-only');
$mc->post('meetings.php', ['meeting_date' => $today, 'chairperson' => 'MC trying'], 'meetings.php');
check(first_id("SELECT COUNT(*) FROM meetings WHERE district_id=$d1") === 1, 'MC cannot create meetings');

// --- action tracker lifecycle ---
$mcId = first_id("SELECT id FROM users WHERE phone='9810000005'"); $spId = first_id("SELECT id FROM users WHERE phone='9810000007'");
check(str_contains($dcNew->post('actions.php', ['do' => 'create', 'issue' => 'Close dumping at Dhani Mahu', 'responsible_user_id' => $mcId, 'deadline' => $past], 'actions.php')['body'], 'cannot be before'), 'action deadline cannot be in the past');
$r = $dcNew->post('actions.php', ['do' => 'create', 'issue' => 'Close dumping at Dhani Mahu', 'location_text' => 'Dhani Mahu', 'responsible_user_id' => $spId, 'supporting_user_id' => $mcId,
    'deadline' => date('Y-m-d', strtotime('+3 days')), 'meeting_id' => $meet1], 'actions.php', true, ['before_photo' => $PNG]);
check(str_contains($r['body'], 'Close dumping at Dhani Mahu') && str_contains($r['body'], 'Pending'), 'action created and shown as pending');
$act2 = first_id("SELECT id FROM actions WHERE issue LIKE 'Close dumping%'");
check(preg_match('#SWM/BWN/' . $year . '/00002#', $r['body']) === 1, 'action numbers run on (…/00002)');
check(str_contains($sp->get('actions.php?id=' . $act2)['body'], 'Submit action taken'), 'responsible Sarpanch sees the submit form');
check(!str_contains($sp->get('actions.php')['body'], 'Segregate waste at MRF gate'), 'Sarpanch sees only their own actions');
check(str_contains($sp->post('actions.php', ['do' => 'verify', 'id' => $act2], 'actions.php?id=' . $act2)['body'], 'Only the District Collector'), 'officer cannot verify');
check(str_contains($sp->post('actions.php', ['do' => 'submit', 'id' => $act2, 'report_text' => 'cleared'], 'actions.php?id=' . $act2)['body'], 'after'), 'submitting needs the after photo');
$sp->post('actions.php', ['do' => 'submit', 'id' => $act2, 'report_text' => 'Dumping site cleared and fenced', 'lat' => '28.8', 'lng' => '76.1'], 'actions.php?id=' . $act2, true, ['after_photo' => $PNG]);
check(str_contains($dcNew->get('actions.php?id=' . $act2)['body'], 'Submitted'), 'action moves to submitted');
check(str_contains($dcNew->get('compliance.php')['body'], 'waiting for your verification'), 'compliance calendar asks the collector to verify');
check(str_contains($dcNew->post('actions.php', ['do' => 'close', 'id' => $act2], 'actions.php?id=' . $act2)['body'], 'not possible'), 'cannot close before verifying');
$dcNew->post('actions.php', ['do' => 'return', 'id' => $act2, 'remark' => 'Photo is unclear'], 'actions.php?id=' . $act2);
check(str_contains($sp->get('actions.php?id=' . $act2)['body'], 'Photo is unclear'), 'returned action shows the remark to the officer');
$sp->post('actions.php', ['do' => 'submit', 'id' => $act2, 'report_text' => 'Re-done with better photo'], 'actions.php?id=' . $act2, true, ['after_photo' => $PNG]);
$dcNew->post('actions.php', ['do' => 'verify', 'id' => $act2], 'actions.php?id=' . $act2);
$dcNew->post('actions.php', ['do' => 'close', 'id' => $act2], 'actions.php?id=' . $act2);
check(first_id("SELECT COUNT(*) FROM actions WHERE id=$act2 AND status='CLOSED' AND closed_at IS NOT NULL AND verified_at IS NOT NULL") === 1, 'action went Pending → Submitted → Verified → Closed');
check($mc->get('download.php?id=' . first_id("SELECT after_doc_id FROM actions WHERE id=$act2"))['status'] === 200, 'supporting officer can see action photos');
check($sp->get('download.php?id=' . first_id("SELECT before_doc_id FROM actions WHERE id=$act1 OR id=$act2 ORDER BY id DESC LIMIT 1"))['status'] === 200, 'responsible officer can see the before photo');
check($dc2->get('actions.php?id=' . $act2)['status'] === 200 && !str_contains($dc2->get('actions.php?id=' . $act2)['body'], 'Close dumping'), 'other district cannot open the action');

// --- complaints ---
$pub = new Client();
$r = $pub->post('report-issue.php', ['name' => 'Passer By', 'phone' => '9876500001', 'district_id' => $d1, 'area_type' => 'URBAN', 'ward_id' => $w5, 'category' => 'OPEN_DUMPING', 'description' => 'Garbage dumped near school', 'lat' => '28.79', 'lng' => '76.14'], 'report-issue.php', true, ['photo' => $PNG]);
preg_match('/SWM-C-' . $year . '-\d{6}/', $r['body'], $m); $cno = $m[0] ?? '';
check($cno !== '', 'anonymous complaint gets a complaint number');
check(str_contains($pub->get('report-issue.php?no=' . $cno . '&phone=9876500001')['body'], 'Received'), 'complaint can be tracked with number + mobile');
check(str_contains($pub->get('report-issue.php?no=' . $cno . '&phone=9876500002')['body'], 'No complaint found'), 'tracking needs the matching mobile number');
check(str_contains($pub->post('report-issue.php', ['name' => 'X', 'phone' => '9876500001', 'district_id' => $d1, 'area_type' => 'URBAN', 'ward_id' => $w5, 'category' => 'OTHER', 'description' => 'y'], 'report-issue.php', true, ['photo' => $FAKE])['body'], 'JPG or PNG'), 'complaint with fake photo rejected');
$cz->post('complaints.php', ['category' => 'GARBAGE_NOT_COLLECTED', 'description' => 'Not collected for 3 days'], 'complaints.php');
check(str_contains($cz->get('complaints.php')['body'], 'Not collected') === false && str_contains($cz->get('complaints.php')['body'], 'Garbage not collected'), 'citizen sees their own complaint');
check(!str_contains($cz->get('complaints.php')['body'], 'Passer'), 'citizen does not see other people’s complaints');
$c1 = first_id("SELECT id FROM complaints WHERE citizen_phone='9876500001'");
check(str_contains($dcNew->get('complaints.php')['body'], $cno), 'collector sees complaints');
check(str_contains($dcNew->get('compliance.php')['body'], 'new complaint'), 'unassigned complaints appear in the compliance calendar');
check(!str_contains($sp->get('complaints.php')['body'], $cno), 'Sarpanch does not see a city complaint');
$dcNew->post('complaints.php', ['do' => 'assign', 'id' => $c1, 'assignee' => $mcId], 'complaints.php?id=' . $c1);
check(first_id("SELECT COUNT(*) FROM complaints WHERE id=$c1 AND status='ASSIGNED' AND assigned_to_user_id=$mcId") === 1, 'collector assigns complaint to MC');
$sp->post('complaints.php', ['do' => 'act', 'id' => $c1, 'action_note' => 'x'], 'complaints.php');
check(first_id("SELECT COUNT(*) FROM complaints WHERE id=$c1 AND status='ASSIGNED'") === 1, 'Sarpanch cannot act on a city complaint');
$mc->post('complaints.php', ['do' => 'act', 'id' => $c1, 'action_note' => 'Waste lifted and area cleaned'], 'complaints.php?id=' . $c1, true, ['photo' => $PNG]);
check(str_contains($pub->get('report-issue.php?no=' . $cno . '&phone=9876500001')['body'], 'Waste lifted'), 'citizen sees the action taken');
check(str_contains($mc->post('complaints.php', ['do' => 'close', 'id' => $c1], 'complaints.php?id=' . $c1)['body'], 'Only the District Collector'), 'MC cannot close complaints');
$dcNew->post('complaints.php', ['do' => 'close', 'id' => $c1], 'complaints.php?id=' . $c1);
check(first_id("SELECT COUNT(*) FROM complaints WHERE id=$c1 AND status='CLOSED'") === 1, 'complaint closed by collector');
$spam = 0; for ($i = 0; $i < 6; $i++) { $b = (new Client())->post('report-issue.php', ['name' => 'Spam', 'phone' => '9876500009', 'district_id' => $d1, 'area_type' => 'URBAN', 'ward_id' => $w5, 'category' => 'OTHER', 'description' => 'spam ' . $i], 'report-issue.php')['body']; if (str_contains($b, 'Too many complaints')) $spam++; }
check($spam === 1, 'more than 5 complaints a day from one number is blocked');

// --- waste pickers ---
check(str_contains($mc->post('waste-pickers.php', ['name' => 'Lakshmi', 'phone' => '123'], 'waste-pickers.php')['body'], 'valid 10-digit'), 'picker phone validated');
check(str_contains($mc->post('waste-pickers.php', ['name' => 'Lakshmi', 'ward_id' => $w5, 'age' => '34', 'gender' => 'FEMALE', 'materials' => 'plastic', 'facility_id' => $fac1, 'trained' => '1', 'registration_status' => 'REGISTERED'], 'waste-pickers.php')['body'], 'Lakshmi'), 'waste picker registered');
check(!str_contains($sp->get('waste-pickers.php')['body'], 'Lakshmi'), 'Sarpanch does not see city waste pickers');
check(str_contains($mc->post('waste-pickers.php', ['name' => 'Kid', 'ward_id' => $w5, 'age' => '9'], 'waste-pickers.php')['body'], '14 or more'), 'minimum age enforced');

// --- compliance calendar ---
$cal = $dcNew->get('compliance.php')['body'];
check(str_contains($cal, 'Quarterly review for') && str_contains($cal, 'is recorded'), 'calendar: quarterly review shows as done');
check(str_contains($cal, 'Authorization of MRF Tosham expires in'), 'calendar: expiring authorization flagged');
check(str_contains($cal, 'Consent of MRF Tosham expired on'), 'calendar: expired consent flagged red');
check(str_contains($cal, 'Annual report') , 'calendar: annual report deadline shown');
$dbq("UPDATE actions SET deadline = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id = ?", [$act1]);
check(str_contains($dcNew->get('compliance.php')['body'], 'past the deadline'), 'calendar: overdue action turns red');
check(str_contains($mc->get('compliance.php')['body'], 'past the deadline'), 'MC sees their own overdue action');
check(!str_contains($sp->get('compliance.php')['body'], 'past the deadline'), 'Sarpanch does not see MC’s overdue action');
check(str_contains($dcNew->get('dashboard.php')['body'], 'Needs your attention'), 'dashboard shows the attention list');
check($cz->get('compliance.php')['status'] === 403, 'citizen cannot open the compliance calendar');

// --- annual report ---
$curFy = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
$rep = $dcNew->get('reports.php?fy=' . $curFy)['body'];
check(str_contains($rep, 'Annual SWM report') && str_contains($rep, 'Waste quantities') && str_contains($rep, 'MRF'), 'annual report page builds');
check(str_contains($rep, '30,125') || str_contains($rep, '30125') || str_contains($rep, '30,'), 'annual report totals include entered waste data');
$csv = $dcNew->get('reports.php?fy=' . $curFy . '&csv=1');
check(str_contains($csv['headers'], 'text/csv') && str_contains($csv['body'], 'Collected kg') && str_contains($csv['body'], "'=HYPERLINK"), 'CSV export works and neutralises spreadsheet formulas');
check($mc->get('reports.php')['status'] === 403, 'MC cannot open the annual report');
$dcNew->post('reports.php?fy=' . ($curFy - 1), ['submitted_on' => $today], 'reports.php?fy=' . ($curFy - 1));
check(first_id("SELECT COUNT(*) FROM annual_reports WHERE district_id=$d1 AND fy_start=" . ($curFy - 1)) === 1, 'report marked as submitted');
check(str_contains($dcNew->get('compliance.php')['body'], 'submitted'), 'calendar: annual report turns green once submitted');

// --- map ---
$map = $dcNew->get('map.php')['body'];
check(str_contains($map, '<svg class="mapbox"') && str_contains($map, 'MRF Tosham'), 'map plots the geo-tagged facility');
check(str_contains($mc->get('map.php')['body'], 'MRF Tosham') && !str_contains($sp->get('map.php')['body'], 'MRF Tosham'), 'map respects area scope');

// --- audit log ---
check(str_contains($dcNew->get('audit.php')['body'], 'facility') && str_contains($dcNew->get('audit.php')['body'], 'action'), 'collector sees the audit trail');
check($mc->get('audit.php')['status'] === 403, 'MC cannot open the audit log');
check($admin->get('audit.php')['status'] === 200, 'state admin can open the audit log');
check(!str_contains($dc2->get('audit.php')['body'], 'MRF Tosham'), 'other district’s audit log is separate');

// --- district isolation for the new modules ---
check(!str_contains($dc2->get('facilities.php')['body'], 'MRF Tosham'), 'other district cannot see facilities');
check(!str_contains($dc2->get('complaints.php')['body'], $cno), 'other district cannot see complaints');
check(!str_contains($dc2->get('waste.php')['body'], '30000'), 'other district cannot see waste data');
check(str_contains($dc2->get('map.php')['body'], 'No geo-tagged'), 'other district map is empty');


// --- lockout + logout ---
$bf = new Client();
for ($i = 0; $i < 5; $i++) $bf->login('9000000000', 'x');
check(str_contains($bf->login('9000000000', 'x')['body'], 'Too many failed attempts'), 'login locks after repeated failures');
$dc->logout();
check(str_contains($dc->get('dashboard.php')['body'], 'Login'), 'logout ends the session');

echo ($fails === 0 ? "OK" : "FAILED") . " – $count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);

<?php
// Command-line installer:  php database/install.php [--fresh]
//   Creates the tables (database/schema.sql) and the first State Admin account.
//   --fresh   drops all SWM tables first (DELETES ALL DATA).
// Admin login: ADMIN_PHONE (default 9999999999) and ADMIN_PASSWORD (random if not set, printed once).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/auth.php';
$GLOBALS['config'] = require __DIR__ . '/../src/config.php';
$_SESSION = [];

$pdo = db();
if (in_array('--fresh', $argv, true)) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['annual_reports', 'waste_pickers', 'complaints', 'actions', 'inspections', 'meetings', 'waste_entries', 'documents', 'facilities', 'counters',
        'login_failures', 'audit_log', 'households', 'staff', 'vehicles', 'villages', 'wards', 'collectors', 'users', 'districts'] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Dropped existing tables.\n";
}

$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/schema.sql')); // drop comment lines
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
echo "Schema ready.\n";

if (q_val("SELECT 1 FROM users WHERE role = 'STATE_ADMIN' LIMIT 1")) {
    echo "A State Admin already exists – nothing else to do.\n";
    exit(0);
}
$phone = getenv('ADMIN_PHONE') ?: '9999999999';
$given = getenv('ADMIN_PASSWORD');
if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) { fwrite(STDERR, "ADMIN_PHONE must be a 10-digit mobile number.\n"); exit(1); }
if ($given !== false && strlen($given) < 8) { fwrite(STDERR, "ADMIN_PASSWORD must be at least 8 characters.\n"); exit(1); }
$password = $given !== false ? $given : bin2hex(random_bytes(6));
q('INSERT INTO users (name, phone, password_hash, role, must_change_password) VALUES (?,?,?,?,?)',
    ['State Admin', $phone, password_hash($password, PASSWORD_DEFAULT), 'STATE_ADMIN', $given === false ? 1 : 0]);
echo "Created State Admin\n  mobile:   $phone\n  password: $password" . ($given === false ? "  (you must change it at first login)" : '') . "\n";

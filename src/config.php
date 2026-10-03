<?php
// Default settings. Override any of them by creating src/config.local.php (git-ignored)
// that returns an array, or with environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS).
$config = [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'swm',
    'db_user' => getenv('DB_USER') ?: 'swm',
    'db_pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'debug'   => (bool) getenv('APP_DEBUG'),
    'storage_dir' => __DIR__ . '/../storage/uploads',   // uploaded files live OUTSIDE the web root
    'session_hours' => 12,
    'max_login_failures' => 5,
    'lockout_minutes' => 15,
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, require $local);
}
return $config;

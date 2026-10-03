<?php
declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors', $GLOBALS['config']['debug'] ? '1' : '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('swm_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string) ($GLOBALS['config']['session_hours'] * 3600));
session_start();

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/scope.php';
require __DIR__ . '/files.php';
require __DIR__ . '/compliance.php';
require __DIR__ . '/workflow.php';
require __DIR__ . '/layout.php';

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    http_response_code(500);
    echo $GLOBALS['config']['debug']
        ? '<pre>' . e((string) $e) . '</pre>'
        : '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><p>Something went wrong. Please try again later.</p>';
    exit;
});

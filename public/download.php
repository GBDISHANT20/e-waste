<?php
// Serves an uploaded file after checking that the logged-in user may see it.
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$d = q_one('SELECT * FROM documents WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
$path = $d ? storage_file($d['stored_name']) : '';
if (!$d || !can_view_document($u, $d) || !is_file($path)) {
    http_response_code(404);
    exit('File not found');
}
$isImage = str_starts_with($d['mime'], 'image/');
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($d['original_name']));
header('Cache-Control: private, max-age=3600');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
readfile($path);

<?php
declare(strict_types=1);
require __DIR__ . '/server/bootstrap.php';

$key = $_GET['id'] ?? '';
$directory = config('media_dir');
$path = is_string($key) && preg_match('/^[a-f0-9]{40}$/D', $key) && $directory ? $directory . '/' . $key . '.webp' : '';
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);

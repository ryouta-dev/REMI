<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') {
    require dirname(__DIR__) . '/index.php';
    return true;
}
if (preg_match('~^/(?:server|\.git)(?:/|$)~i', $path)) {
    http_response_code(404);
    return true;
}
return false;

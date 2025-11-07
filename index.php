<?php
declare(strict_types=1);

// The plugin UI lives in webfrontend/html/index.php. Include it directly so the
// page renders without relying on environment variables provided by LoxBerry.
$frontend = __DIR__ . '/webfrontend/html/index.php';

if (is_file($frontend) && is_readable($frontend)) {
    require $frontend;
    exit;
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo 'Hue API v2 Bridge: Frontend konnte nicht geladen werden.';
exit;

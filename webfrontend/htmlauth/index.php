<?php
declare(strict_types=1);

$frontend = dirname(__DIR__) . '/html/index.php';

if (is_file($frontend) && is_readable($frontend)) {
    require $frontend;
    exit;
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo 'Hue API v2 Bridge: Frontend konnte nicht geladen werden.';
exit;

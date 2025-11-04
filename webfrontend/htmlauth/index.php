<?php
declare(strict_types=1);

$localFrontend = dirname(__DIR__) . '/html/index.php';
if (is_readable($localFrontend)) {
    require $localFrontend;
    exit;
}

$publicFrontendDir = getenv('LBPHTMLDIR');
if ($publicFrontendDir) {
    $publicFrontend = rtrim($publicFrontendDir, '/\\') . '/index.php';
    if (is_readable($publicFrontend)) {
        require $publicFrontend;
        exit;
    }
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo 'Hue API v2 Bridge: Frontend konnte nicht geladen werden.';
exit;
?>

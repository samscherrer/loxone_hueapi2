<?php
declare(strict_types=1);
$localFrontend = __DIR__ . '/webfrontend/html/index.php';
if (is_readable($localFrontend)) {
    require $localFrontend;
    exit;
}
$lbFrontend = getenv('LBPHTMLDIR');
if ($lbFrontend && is_readable($lbFrontend . '/index.php')) {
    require $lbFrontend . '/index.php';
    exit;
}
http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo "Hue API v2 Bridge: Frontend konnte nicht geladen werden.";
exit;
?>

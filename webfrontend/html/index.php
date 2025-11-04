<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$htmlFile = __DIR__ . '/index.html';
if (!is_readable($htmlFile)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><h1>Fehler</h1><p>Die Benutzeroberfläche konnte nicht geladen werden.</p></body></html>';
    return;
}

readfile($htmlFile);

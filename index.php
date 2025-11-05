<?php
declare(strict_types=1);

/**
 * Try to require the first readable frontend candidate and exit afterwards.
 *
 * @param string[] $candidates Absolute file paths or directories that contain the UI.
 */
function lb_require_frontend(array $candidates): void
{
    $seen = [];
    foreach ($candidates as $candidate) {
        if ($candidate === '' || $candidate === null) {
            continue;
        }

        $path = $candidate;
        if (substr($path, -4) !== '.php') {
            $path = rtrim($path, "\\/") . '/index.php';
        }

        $real = realpath($path);
        if ($real === false) {
            continue;
        }

        if ($real === __FILE__) {
            // Avoid including ourselves again which would cause a recursion.
            continue;
        }

        if (isset($seen[$real])) {
            continue;
        }

        $seen[$real] = true;

        if (!is_file($real) || !is_readable($real)) {
            continue;
        }

        require $real;
        exit;
    }
}

/**
 * Derive the html directory from a provided htmlauth directory path.
 */
function lb_html_from_auth(?string $authDir): ?string
{
    if ($authDir === null || $authDir === '') {
        return null;
    }

    $normalized = rtrim($authDir, "\\/");
    $needle = '/htmlauth/';
    $pos = strpos($normalized, $needle);
    if ($pos !== false) {
        return substr($normalized, 0, $pos) . '/html/' . substr($normalized, $pos + strlen($needle));
    }

    $suffix = '/htmlauth';
    if (substr($normalized, -strlen($suffix)) === $suffix) {
        return substr($normalized, 0, -strlen($suffix)) . '/html';
    }

    return null;
}

$candidates = [];

$envHtml = getenv('LBPHTMLDIR');
if ($envHtml !== false && $envHtml !== '') {
    $candidates[] = $envHtml;
}

$envHtmlAuth = getenv('LBPHTMLAUTHDIR');
$derivedHtml = lb_html_from_auth($envHtmlAuth === false ? null : $envHtmlAuth);
if ($derivedHtml !== null) {
    $candidates[] = $derivedHtml;
}

$pluginDir = getenv('LBPPLUGINDIR');
if ($pluginDir !== false && $pluginDir !== '') {
    $candidates[] = rtrim($pluginDir, "\\/") . '/webfrontend/html/index.php';
}

// Development fallback: repository checkout structure.
$candidates[] = __DIR__ . '/webfrontend/html/index.php';

lb_require_frontend($candidates);

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo "Hue API v2 Bridge: Frontend konnte nicht geladen werden.";
exit;
?>

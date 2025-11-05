<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

/**
 * @param mixed $payload
 */
function respond_json($payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function respond_error(string $message, int $status = 400): void
{
    respond_json(['error' => $message], $status);
}

function plugin_config_path(): string
{
    $configDir = getenv('LBPCONFIGDIR');
    if (!$configDir) {
        $configDir = dirname(__DIR__, 2) . '/config';
    }

    return rtrim($configDir, DIRECTORY_SEPARATOR) . '/config.json';
}

function load_plugin_config(string $configPath): array
{
    if (!file_exists($configPath)) {
        return ['bridges' => []];
    }

    $raw = file_get_contents($configPath);
    if ($raw === false) {
        throw new RuntimeException("Konfigurationsdatei konnte nicht gelesen werden.");
    }

    $raw = trim($raw);
    if ($raw === '') {
        return ['bridges' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültige Konfigurationsdatei.');
    }

    if (isset($decoded['bridges']) && is_array($decoded['bridges'])) {
        $bridges = [];
        foreach ($decoded['bridges'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bridges[] = normalise_bridge($entry, $bridges);
        }
        return ['bridges' => $bridges];
    }

    if (isset($decoded['bridge_ip'], $decoded['application_key'])) {
        $bridge = normalise_bridge($decoded, []);
        return ['bridges' => [$bridge]];
    }

    return ['bridges' => []];
}

function save_plugin_config(string $configPath, array $config): void
{
    $bridges = $config['bridges'] ?? [];
    if (!is_array($bridges)) {
        throw new RuntimeException('Bridge-Liste ist ungültig.');
    }

    $dir = dirname($configPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Konfigurationsverzeichnis konnte nicht erstellt werden.');
        }
    }

    $payload = json_encode(['bridges' => array_values($bridges)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Konfiguration konnte nicht serialisiert werden.');
    }
    $payload .= "\n";

    $tmpPath = $configPath . '.tmp';
    if (file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Konfigurationsdatei konnte nicht geschrieben werden.');
    }
    if (!rename($tmpPath, $configPath)) {
        throw new RuntimeException('Konfigurationsdatei konnte nicht aktualisiert werden.');
    }
}

function normalise_bridge(array $entry, array $existing): array
{
    $id = $entry['id'] ?? '';
    if (!is_string($id) || $id === '') {
        $id = generate_bridge_id($entry, $existing);
    }

    $name = isset($entry['name']) && $entry['name'] !== '' ? (string) $entry['name'] : null;
    $bridgeIp = (string) ($entry['bridge_ip'] ?? '');
    $appKey = (string) ($entry['application_key'] ?? '');
    $clientKey = isset($entry['client_key']) && $entry['client_key'] !== '' ? (string) $entry['client_key'] : null;
    $useHttps = filter_var($entry['use_https'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($useHttps === null) {
        $useHttps = true;
    }
    $verifyTls = filter_var($entry['verify_tls'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($verifyTls === null) {
        $verifyTls = false;
    }

    return [
        'id' => $id,
        'name' => $name,
        'bridge_ip' => $bridgeIp,
        'application_key' => $appKey,
        'client_key' => $clientKey,
        'use_https' => $useHttps,
        'verify_tls' => $verifyTls,
    ];
}

function generate_bridge_id(array $entry, array $existing): string
{
    $candidates = [];
    if (isset($entry['name']) && is_string($entry['name'])) {
        $candidates[] = $entry['name'];
    }
    if (isset($entry['bridge_ip']) && is_string($entry['bridge_ip'])) {
        $candidates[] = $entry['bridge_ip'];
    }

    $base = null;
    foreach ($candidates as $value) {
        $slug = slugify($value);
        if ($slug) {
            $base = $slug;
            break;
        }
    }
    if (!$base) {
        $base = 'default';
    }

    $ids = array_map(
        function (array $item): string {
            return $item['id'];
        },
        $existing
    );
    if (!in_array($base, $ids, true)) {
        return $base;
    }

    $counter = 1;
    while (in_array(sprintf('%s-%d', $base, $counter), $ids, true)) {
        $counter++;
    }

    return sprintf('%s-%d', $base, $counter);
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültiger JSON-Body.');
    }
    return $decoded;
}

function request_payload(): array
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $body = read_json_body();
        if ($body !== []) {
            return $body;
        }
    }

    $payload = [];
    foreach ($_GET as $key => $value) {
        if ($key === 'ajax' || $key === 'action') {
            continue;
        }
        if (is_array($value)) {
            continue;
        }
        $payload[$key] = $value;
    }

    return $payload;
}

function plugin_root(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $pluginId = 'hueapiv2';
    $placeholderLbp = 'REPLACELBPPLUGINDIR';
    $placeholderLbh = 'REPLACELBHOMEDIR';

    $candidates = [];

    $lbpPluginDir = getenv('LBPPLUGINDIR');
    if ($lbpPluginDir !== false && $lbpPluginDir !== '' && $lbpPluginDir !== $placeholderLbp) {
        $candidates[] = $lbpPluginDir;
    }

    $lbHomeDir = getenv('LBHOMEDIR');
    if ($lbHomeDir !== false && $lbHomeDir !== '' && $lbHomeDir !== $placeholderLbh) {
        $candidates[] = $lbHomeDir . '/data/plugins/' . $pluginId;
        $candidates[] = $lbHomeDir . '/bin/plugins/' . $pluginId;
    }

    $candidates[] = '/opt/loxberry/data/plugins/' . $pluginId;
    $candidates[] = '/opt/loxberry/bin/plugins/' . $pluginId;
    $candidates[] = dirname(__DIR__, 3);
    $candidates[] = dirname(__DIR__, 2);

    foreach ($candidates as $candidate) {
        if ($candidate && is_dir($candidate)) {
            $resolved = realpath($candidate) ?: $candidate;
            return $resolved;
        }
    }

    $resolved = dirname(__DIR__, 2);
    return $resolved;
}

function python_binary(): string
{
    $root = plugin_root();
    $venv = $root . '/venv/bin/python';
    if (is_file($venv) && is_executable($venv)) {
        return $venv;
    }

    return 'python3';
}

/**
 * @param list<string> $args
 */
function call_hue_cli(array $args): array
{
    $python = python_binary();
    $command = array_merge([$python, '-m', 'hue_plugin.cli'], $args);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    putenv('HUE_PLUGIN_CONFIG=' . plugin_config_path());

    $existingPythonPath = getenv('PYTHONPATH');
    $pythonPathSegments = [plugin_root()];
    if ($existingPythonPath !== false && $existingPythonPath !== '') {
        $pythonPathSegments[] = $existingPythonPath;
    }
    putenv('PYTHONPATH=' . implode(PATH_SEPARATOR, $pythonPathSegments));

    $process = proc_open($command, $descriptors, $pipes, plugin_root());
    if (!is_resource($process)) {
        throw new RuntimeException('Hue-Dienst konnte nicht gestartet werden.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $message = trim($stderr !== '' ? $stderr : $stdout);
        if ($message === '') {
            $message = 'Unbekannter Fehler beim Aufruf des Hue-Dienstes.';
        }
        throw new RuntimeException('Hue-Dienst nicht erreichbar: ' . $message);
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Hue-Dienst lieferte ungültige Antwort.');
    }

    return $decoded;
}

function handle_ajax(string $configPath): void
{
    $action = $_GET['action'] ?? '';

    try {
        switch ($action) {
            case 'list_bridges':
                $config = load_plugin_config($configPath);
                respond_json(['bridges' => $config['bridges']]);
                break;

            case 'save_bridge':
                $payload = read_json_body();
                $bridgeIp = trim((string) ($payload['bridge_ip'] ?? ''));
                $applicationKey = trim((string) ($payload['application_key'] ?? ''));
                if ($bridgeIp === '' || $applicationKey === '') {
                    throw new RuntimeException('IP-Adresse und Application-Key sind Pflichtfelder.');
                }

                $config = load_plugin_config($configPath);
                $bridges = $config['bridges'];
                $bridgeResponse = null;

                $identifier = isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
                    ? $payload['id']
                    : null;

                $bridge = [
                    'id' => $identifier,
                    'name' => isset($payload['name']) ? trim((string) $payload['name']) : null,
                    'bridge_ip' => $bridgeIp,
                    'application_key' => $applicationKey,
                    'client_key' => isset($payload['client_key']) ? trim((string) $payload['client_key']) : null,
                    'use_https' => (bool) ($payload['use_https'] ?? true),
                    'verify_tls' => (bool) ($payload['verify_tls'] ?? false),
                ];

                $updated = false;
                foreach ($bridges as $index => $existing) {
                    if ($identifier !== null && $existing['id'] === $identifier) {
                        $existingWithoutCurrent = $bridges;
                        unset($existingWithoutCurrent[$index]);
                        $bridges[$index] = normalise_bridge($bridge, array_values($existingWithoutCurrent));
                        $bridgeResponse = $bridges[$index];
                        $updated = true;
                        break;
                    }
                }

                if (!$updated) {
                    $bridgeResponse = normalise_bridge($bridge, $bridges);
                    $bridges[] = $bridgeResponse;
                }

                save_plugin_config($configPath, ['bridges' => $bridges]);
                respond_json(['bridge' => $bridgeResponse]);
                break;

            case 'delete_bridge':
                $payload = read_json_body();
                $identifier = trim((string) ($payload['id'] ?? ''));
                if ($identifier === '') {
                    throw new RuntimeException('Bridge-ID fehlt.');
                }
                $config = load_plugin_config($configPath);
                $bridges = array_values(array_filter(
                    $config['bridges'],
                    function (array $bridge) use ($identifier): bool {
                        return $bridge['id'] !== $identifier;
                    }
                ));
                save_plugin_config($configPath, ['bridges' => $bridges]);
                respond_json(['ok' => true]);
                break;

            case 'test_connection':
                $bridgeId = trim((string) ($_GET['bridge_id'] ?? ''));
                if ($bridgeId === '') {
                    throw new RuntimeException('Es wurde keine Bridge ausgewählt.');
                }
                call_hue_cli(['test-connection', '--bridge-id', $bridgeId]);
                respond_json(['ok' => true]);
                break;

            case 'get_resources':
                $bridgeId = trim((string) ($_GET['bridge_id'] ?? ''));
                $type = trim((string) ($_GET['type'] ?? ''));
                if ($bridgeId === '') {
                    throw new RuntimeException('Es wurde keine Bridge ausgewählt.');
                }
                if (!in_array($type, ['lights', 'scenes', 'rooms'], true)) {
                    throw new RuntimeException('Unbekannter Ressourcentyp.');
                }
                $result = call_hue_cli(['list-resources', '--type', $type, '--bridge-id', $bridgeId]);
                $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
                respond_json(['items' => $items]);
                break;

            case 'light_command':
                $payload = request_payload();
                $bridgeId = trim((string) ($payload['bridge_id'] ?? ''));
                $lightId = trim((string) ($payload['light_id'] ?? ''));
                if ($bridgeId === '' || $lightId === '') {
                    throw new RuntimeException('Bridge und Lampen-RID sind erforderlich.');
                }
                $body = [];
                if (array_key_exists('on', $payload)) {
                    $body['on'] = (bool) $payload['on'];
                }
                if (isset($payload['brightness']) && $payload['brightness'] !== '') {
                    $value = (int) $payload['brightness'];
                    if ($value < 0 || $value > 100) {
                        throw new RuntimeException('Die Helligkeit muss zwischen 0 und 100 liegen.');
                    }
                    $body['brightness'] = $value;
                }
                $args = ['light-command', '--bridge-id', $bridgeId, '--light-id', $lightId];
                if (array_key_exists('on', $body)) {
                    $args[] = $body['on'] ? '--on' : '--off';
                }
                if (array_key_exists('brightness', $body)) {
                    $args[] = '--brightness';
                    $args[] = (string) $body['brightness'];
                }
                call_hue_cli($args);
                respond_json(['ok' => true]);
                break;

            case 'scene_command':
                $payload = request_payload();
                $bridgeId = trim((string) ($payload['bridge_id'] ?? ''));
                $sceneId = trim((string) ($payload['scene_id'] ?? ''));
                if ($bridgeId === '' || $sceneId === '') {
                    throw new RuntimeException('Bridge und Szenen-RID sind erforderlich.');
                }
                $body = [];
                if (!empty($payload['target_rid']) && !empty($payload['target_rtype'])) {
                    $body['target_rid'] = (string) $payload['target_rid'];
                    $body['target_rtype'] = (string) $payload['target_rtype'];
                }
                $args = ['scene-command', '--bridge-id', $bridgeId, '--scene-id', $sceneId];
                if (isset($body['target_rid'], $body['target_rtype'])) {
                    $args[] = '--target-rid';
                    $args[] = $body['target_rid'];
                    $args[] = '--target-rtype';
                    $args[] = $body['target_rtype'];
                }
                call_hue_cli($args);
                respond_json(['ok' => true]);
                break;

            default:
                respond_error('Unbekannte Aktion: ' . $action, 404);
        }
    } catch (RuntimeException $exception) {
        respond_error($exception->getMessage());
    } catch (Throwable $throwable) {
        respond_error('Unerwarteter Fehler: ' . $throwable->getMessage(), 500);
    }
}

$configPath = plugin_config_path();

if (isset($_GET['ajax'])) {
    handle_ajax($configPath);
    return;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
  <head>
    <meta charset="utf-8" />
    <title>Philips Hue API v2 Bridge</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
      :root {
        color-scheme: light dark;
        --accent: #005a9c;
        --accent-light: rgba(0, 90, 156, 0.2);
        --accent-dark: #0b3d70;
        --card-bg: rgba(255, 255, 255, 0.92);
        --card-border: rgba(15, 23, 42, 0.08);
        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }

      body {
        margin: 0;
        background: linear-gradient(160deg, #101935 0%, #1f2a44 40%, #0f1a2c 100%);
        min-height: 100vh;
        color: #0f172a;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 2.5rem 1.5rem 3rem;
        box-sizing: border-box;
      }

      header {
        text-align: center;
        color: #f8fafc;
        margin-bottom: 2rem;
        max-width: 960px;
      }

      header h1 {
        font-size: clamp(2rem, 4vw, 3rem);
        margin: 0 0 0.75rem;
        font-weight: 600;
      }

      header p {
        margin: 0;
        font-size: 1.05rem;
        opacity: 0.85;
      }

      main {
        width: min(960px, 100%);
        display: grid;
        gap: 1.75rem;
      }

      .card {
        background: var(--card-bg);
        border-radius: 16px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
        border: 1px solid var(--card-border);
        padding: 1.8rem;
        backdrop-filter: blur(6px);
      }

      .card h2 {
        margin-top: 0;
        font-size: 1.5rem;
        color: var(--accent);
      }

      .muted {
        color: rgba(15, 23, 42, 0.6);
      }

      label {
        font-weight: 600;
        display: block;
        margin-bottom: 0.35rem;
      }

      input,
      select,
      button,
      textarea {
        font: inherit;
      }

      input[type="text"],
      textarea,
      select {
        width: 100%;
        box-sizing: border-box;
        padding: 0.65rem 0.75rem;
        border-radius: 12px;
        border: 1px solid rgba(15, 23, 42, 0.18);
        background: rgba(255, 255, 255, 0.96);
        transition: border 0.2s ease, box-shadow 0.2s ease;
        color: #0f172a;
      }

      input::placeholder,
      textarea::placeholder {
        color: rgba(15, 23, 42, 0.5);
      }

      input:focus,
      textarea:focus,
      select:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-light);
      }

      button {
        padding: 0.65rem 1.4rem;
        border-radius: 999px;
        border: none;
        background: var(--accent);
        color: #fff;
        cursor: pointer;
        font-weight: 600;
        transition: transform 0.15s ease, box-shadow 0.2s ease;
      }

      button.secondary {
        background: rgba(0, 90, 156, 0.12);
        color: var(--accent-dark);
      }

      button.danger {
        background: rgba(185, 28, 28, 0.18);
        color: #991b1b;
      }

      button:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 22px rgba(15, 23, 42, 0.18);
      }

      button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
      }

      .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.9rem;
        margin-top: 1rem;
      }

      .grid {
        display: grid;
        gap: 1.1rem;
      }

      @media (min-width: 740px) {
        .grid.two {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0.75rem;
        font-size: 0.95rem;
      }

      th,
      td {
        padding: 0.55rem 0.75rem;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        text-align: left;
      }

      tbody tr:hover {
        background: rgba(0, 90, 156, 0.06);
      }

      .bridge-items {
        list-style: none;
        padding: 0;
        margin: 1rem 0 0;
        display: grid;
        gap: 0.75rem;
      }

      .bridge-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.86);
      }

      .bridge-actions {
        display: flex;
        align-items: center;
        gap: 0.6rem;
      }

      .tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.65rem;
        border-radius: 999px;
        background: rgba(0, 90, 156, 0.14);
        color: var(--accent-dark);
        font-size: 0.8rem;
        font-weight: 600;
      }

      .resource-detail {
        margin-top: 0.2rem;
        font-size: 0.85rem;
        color: rgba(15, 23, 42, 0.7);
      }

      .message {
        margin-top: 1rem;
        border-radius: 12px;
        padding: 0.8rem 1rem;
        display: none;
        font-weight: 600;
      }

      .message.error {
        background: rgba(220, 38, 38, 0.14);
        color: #7f1d1d;
      }

      .message.success {
        background: rgba(34, 197, 94, 0.15);
        color: #14532d;
      }

      footer {
        margin-top: 2.5rem;
        color: rgba(248, 250, 252, 0.8);
        font-size: 0.85rem;
        text-align: center;
      }
    </style>
  </head>
  <body>
    <header>
      <h1>Philips Hue API v2 Bridge</h1>
      <p>
        Lege deine Hue Bridges an, verwalte Anwendungsschlüssel und teste Lampen
        oder Szenen direkt aus dem LoxBerry-Plugin.
      </p>
    </header>
    <main>
      <section class="card">
        <h2>Hue Bridges</h2>
        <p class="muted">
          Nutze das Formular, um eine Bridge zu hinterlegen. Die Einstellungen
          werden in <code>config.json</code> gespeichert und automatisch vom Hue
          Dienst übernommen.
        </p>
        <div id="bridge-list" class="muted">
          <p>Noch keine Bridge geladen.</p>
        </div>
        <div class="grid two" style="margin-top: 1.2rem">
          <div>
            <label for="bridge-select">Aktive Bridge</label>
            <select id="bridge-select" disabled></select>
          </div>
          <div class="actions" style="justify-content: flex-start">
            <button type="button" id="reload-bridges" class="secondary">
              Bridge-Liste aktualisieren
            </button>
          </div>
        </div>
        <form id="bridge-form" class="grid" autocomplete="off">
          <input type="hidden" id="bridge-id" />
          <div class="grid two">
            <div>
              <label for="bridge-name">Anzeigename</label>
              <input
                type="text"
                id="bridge-name"
                placeholder="z. B. Wohnzimmer"
              />
            </div>
            <div>
              <label for="bridge-ip">Bridge-IP oder Hostname *</label>
              <input
                type="text"
                id="bridge-ip"
                placeholder="192.168.1.50"
                required
              />
            </div>
          </div>
          <div>
            <label for="bridge-app-key">Application Key *</label>
            <input
              type="text"
              id="bridge-app-key"
              placeholder="Hue-App-Schlüssel"
              required
            />
          </div>
          <div>
            <label for="bridge-client-key">Client Key (optional)</label>
            <input
              type="text"
              id="bridge-client-key"
              placeholder="Nur für Entertainment-Streams erforderlich"
            />
          </div>
          <div class="grid two">
            <label>
              <input type="checkbox" id="bridge-use-https" checked /> HTTPS
              verwenden
            </label>
            <label>
              <input type="checkbox" id="bridge-verify-tls" /> TLS-Zertifikat
              prüfen
            </label>
          </div>
          <div class="actions">
            <button type="submit">Speichern</button>
            <button type="button" id="bridge-reset" class="secondary">
              Formular leeren
            </button>
            <button type="button" id="bridge-delete" class="danger" hidden>
              Bridge löschen
            </button>
          </div>
        </form>
        <div id="bridge-message" class="message"></div>
      </section>

      <section class="card">
        <h2>Verbindung testen</h2>
        <p class="muted">
          Wähle eine Bridge aus und prüfe, ob der Python-Dienst die Verbindung
          zur Hue-Bridge herstellen kann.
        </p>
        <div class="actions">
          <button type="button" id="test-connection">Verbindung testen</button>
        </div>
        <div id="connection-message" class="message"></div>
      </section>

      <section class="card">
        <h2>Hue-Ressourcen anzeigen</h2>
        <p class="muted">
          Nach erfolgreicher Verbindung kannst du Lampen, Szenen oder Räume aus
          der ausgewählten Bridge laden.
        </p>
        <div class="actions">
          <button type="button" class="secondary load-resource" data-type="lights">
            Lampen laden
          </button>
          <button type="button" class="secondary load-resource" data-type="scenes">
            Szenen laden
          </button>
          <button type="button" class="secondary load-resource" data-type="rooms">
            Räume laden
          </button>
        </div>
        <div id="resource-message" class="message"></div>
        <table id="resource-output">
          <thead>
            <tr>
              <th>Name</th>
              <th>RID</th>
              <th>Typ</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="4" class="muted">
                Noch keine Daten geladen. Wähle oben eine Kategorie.
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card">
        <h2>Licht steuern</h2>
        <div class="grid two">
          <div>
            <label for="light-id">Lampen-RID *</label>
            <input type="text" id="light-id" placeholder="UUID der Lampe" />
          </div>
          <div>
            <label for="light-action">Aktion</label>
            <select id="light-action">
              <option value="on">Einschalten</option>
              <option value="off">Ausschalten</option>
            </select>
          </div>
        </div>
        <div>
          <label for="light-brightness">Helligkeit (0-100)</label>
          <input type="text" id="light-brightness" placeholder="Optional" />
        </div>
        <div class="actions">
          <button type="button" id="light-submit">Befehl senden</button>
        </div>
        <div id="light-message" class="message"></div>
      </section>

      <section class="card">
        <h2>Szene aktivieren</h2>
        <div class="grid two">
          <div>
            <label for="scene-id">Szenen-RID *</label>
            <input type="text" id="scene-id" placeholder="UUID der Szene" />
          </div>
          <div>
            <label for="scene-target">Ziel (optional)</label>
            <input
              type="text"
              id="scene-target"
              placeholder="<resource-id>::<rtype>"
            />
          </div>
        </div>
        <div class="actions">
          <button type="button" id="scene-submit">Szene starten</button>
        </div>
        <div id="scene-message" class="message"></div>
      </section>
    </main>

    <footer>
      Tipp: Der Python-Dienst läuft standardmäßig auf
      <code>127.0.0.1:5510</code>. Passe die Umgebungsvariablen
      <code>HUE_PLUGIN_SERVICE_HOST</code> und
      <code>HUE_PLUGIN_SERVICE_PORT</code> an, falls du den Dienst extern
      betreibst.
    </footer>

    <script>
      const bridgeListElement = document.getElementById('bridge-list');
      const bridgeSelect = document.getElementById('bridge-select');
      const bridgeForm = document.getElementById('bridge-form');
      const bridgeIdField = document.getElementById('bridge-id');
      const bridgeNameInput = document.getElementById('bridge-name');
      const bridgeIpInput = document.getElementById('bridge-ip');
      const bridgeAppKeyInput = document.getElementById('bridge-app-key');
      const bridgeClientKeyInput = document.getElementById('bridge-client-key');
      const bridgeUseHttpsInput = document.getElementById('bridge-use-https');
      const bridgeVerifyTlsInput = document.getElementById('bridge-verify-tls');
      const bridgeMessage = document.getElementById('bridge-message');
      const connectionMessage = document.getElementById('connection-message');
      const resourceMessage = document.getElementById('resource-message');
      const lightMessage = document.getElementById('light-message');
      const sceneMessage = document.getElementById('scene-message');
      const bridgeDeleteButton = document.getElementById('bridge-delete');
      const bridgeResetButton = document.getElementById('bridge-reset');
      const reloadBridgesButton = document.getElementById('reload-bridges');

      const state = {
        bridges: [],
        activeBridgeId: null,
      };

      const message = (el, type, text) => {
        if (!el) {
          return;
        }
        el.textContent = text;
        el.className = type ? `message ${type}` : 'message';
        el.style.display = text ? 'block' : 'none';
      };

      const buildUrl = (action, params = {}) => {
        const url = new URL('index.php', window.location.href);
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => {
          if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
          }
        });
        return url.toString();
      };

      const apiFetch = async (action, { method = 'GET', body, params = {} } = {}) => {
        const options = {
          method,
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        };
        if (body !== undefined) {
          options.body = JSON.stringify(body);
        }
        const response = await fetch(buildUrl(action, params), options);
        const contentType = response.headers.get('content-type') || '';
        let payload = null;
        if (contentType.includes('application/json')) {
          payload = await response.json();
        } else {
          const text = await response.text();
          payload = text ? { error: text } : null;
        }
        if (!response.ok) {
          throw new Error(payload && payload.error ? payload.error : 'Anfrage fehlgeschlagen.');
        }
        if (payload && payload.error) {
          throw new Error(payload.error);
        }
        return payload ?? {};
      };

      const renderBridgeSelect = () => {
        bridgeSelect.innerHTML = '';
        if (!state.bridges.length) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'Keine Bridge konfiguriert';
          option.disabled = true;
          option.selected = true;
          bridgeSelect.appendChild(option);
          bridgeSelect.disabled = true;
          return;
        }
        bridgeSelect.disabled = false;
        state.bridges.forEach((bridge) => {
          const option = document.createElement('option');
          option.value = bridge.id;
          option.textContent = bridge.name ? `${bridge.name} (${bridge.id})` : bridge.id;
          if (bridge.id === state.activeBridgeId) {
            option.selected = true;
          }
          bridgeSelect.appendChild(option);
        });
      };

      const renderBridgeList = () => {
        if (!state.bridges.length) {
          bridgeListElement.innerHTML = '<p class="muted">Keine Bridge konfiguriert. Nutze das Formular, um eine neue Bridge anzulegen.</p>';
          return;
        }
        const list = document.createElement('ul');
        list.className = 'bridge-items';
        state.bridges.forEach((bridge) => {
          const item = document.createElement('li');
          item.className = 'bridge-item';
          const info = document.createElement('div');
          info.innerHTML = `<strong>${bridge.name || bridge.id}</strong><br /><span class="muted">${bridge.bridge_ip}</span>`;
          const actions = document.createElement('div');
          actions.className = 'bridge-actions';

          const editButton = document.createElement('button');
          editButton.type = 'button';
          editButton.className = 'secondary';
          editButton.textContent = 'Bearbeiten';
          editButton.addEventListener('click', () => fillBridgeForm(bridge.id));
          actions.appendChild(editButton);

          if (bridge.id === state.activeBridgeId) {
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = 'Aktiv';
            actions.appendChild(tag);
          } else {
            const setActiveButton = document.createElement('button');
            setActiveButton.type = 'button';
            setActiveButton.className = 'secondary';
            setActiveButton.textContent = 'Aktiv setzen';
            setActiveButton.addEventListener('click', () => setActiveBridge(bridge.id));
            actions.appendChild(setActiveButton);
          }

          item.append(info, actions);
          list.appendChild(item);
        });
        bridgeListElement.innerHTML = '';
        bridgeListElement.appendChild(list);
      };

      const setActiveBridge = (bridgeId) => {
        state.activeBridgeId = bridgeId;
        renderBridgeSelect();
        renderBridgeList();
      };

      const resetBridgeForm = () => {
        bridgeForm.reset();
        bridgeIdField.value = '';
        bridgeUseHttpsInput.checked = true;
        bridgeVerifyTlsInput.checked = false;
        bridgeDeleteButton.hidden = true;
        message(bridgeMessage, '', '');
      };

      const fillBridgeForm = (bridgeId) => {
        const bridge = state.bridges.find((b) => b.id === bridgeId);
        if (!bridge) {
          return;
        }
        bridgeIdField.value = bridge.id;
        bridgeNameInput.value = bridge.name || '';
        bridgeIpInput.value = bridge.bridge_ip || '';
        bridgeAppKeyInput.value = bridge.application_key || '';
        bridgeClientKeyInput.value = bridge.client_key || '';
        bridgeUseHttpsInput.checked = Boolean(bridge.use_https);
        bridgeVerifyTlsInput.checked = Boolean(bridge.verify_tls);
        bridgeDeleteButton.hidden = false;
        message(bridgeMessage, '', '');
      };

      const loadBridges = async () => {
        try {
          const data = await apiFetch('list_bridges');
          state.bridges = Array.isArray(data.bridges) ? data.bridges : [];
          if (!state.bridges.length) {
            state.activeBridgeId = null;
          } else if (!state.activeBridgeId || !state.bridges.some((bridge) => bridge.id === state.activeBridgeId)) {
            state.activeBridgeId = state.bridges[0].id;
          }
          renderBridgeSelect();
          renderBridgeList();
        } catch (error) {
          message(bridgeMessage, 'error', error.message);
          state.bridges = [];
          state.activeBridgeId = null;
          renderBridgeSelect();
          renderBridgeList();
        }
      };

      bridgeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = {
          id: bridgeIdField.value || null,
          name: bridgeNameInput.value.trim() || null,
          bridge_ip: bridgeIpInput.value.trim(),
          application_key: bridgeAppKeyInput.value.trim(),
          client_key: bridgeClientKeyInput.value.trim() || null,
          use_https: bridgeUseHttpsInput.checked,
          verify_tls: bridgeVerifyTlsInput.checked,
        };
        if (!payload.bridge_ip || !payload.application_key) {
          message(bridgeMessage, 'error', 'Bitte IP-Adresse und Application-Key ausfüllen.');
          return;
        }
        try {
          await apiFetch('save_bridge', { method: 'POST', body: payload });
          message(bridgeMessage, 'success', 'Bridge wurde gespeichert.');
          await loadBridges();
          if (!payload.id) {
            resetBridgeForm();
          }
        } catch (error) {
          message(bridgeMessage, 'error', error.message);
        }
      });

      bridgeDeleteButton.addEventListener('click', async () => {
        const id = bridgeIdField.value;
        if (!id) {
          return;
        }
        if (!window.confirm(`Bridge '${id}' wirklich löschen?`)) {
          return;
        }
        try {
          await apiFetch('delete_bridge', { method: 'POST', body: { id } });
          message(bridgeMessage, 'success', 'Bridge wurde entfernt.');
          resetBridgeForm();
          await loadBridges();
        } catch (error) {
          message(bridgeMessage, 'error', error.message);
        }
      });

      bridgeResetButton.addEventListener('click', (event) => {
        event.preventDefault();
        resetBridgeForm();
      });

      bridgeSelect.addEventListener('change', (event) => {
        setActiveBridge(event.target.value || null);
      });

      reloadBridgesButton.addEventListener('click', async () => {
        message(bridgeMessage, '', '');
        await loadBridges();
      });

      document.getElementById('test-connection').addEventListener('click', async () => {
        message(connectionMessage, '', '');
        if (!state.activeBridgeId) {
          message(connectionMessage, 'error', 'Bitte zuerst eine Bridge auswählen.');
          return;
        }
        try {
          await apiFetch('test_connection', { params: { bridge_id: state.activeBridgeId } });
          message(connectionMessage, 'success', 'Verbindung erfolgreich getestet.');
        } catch (error) {
          message(connectionMessage, 'error', `Verbindung fehlgeschlagen: ${error.message}`);
        }
      });

      const summariseNames = (items) => {
        if (!Array.isArray(items) || !items.length) {
          return null;
        }
        const names = items
          .map((entry) => {
            if (!entry) {
              return null;
            }
            if (typeof entry === 'string') {
              const trimmed = entry.trim();
              return trimmed !== '' ? trimmed : null;
            }
            if (typeof entry === 'object') {
              const value = typeof entry.name === 'string' && entry.name.trim() !== ''
                ? entry.name.trim()
                : typeof entry.id === 'string'
                  ? entry.id.trim()
                  : null;
              return value && value !== '' ? value : null;
            }
            return null;
          })
          .filter((value) => typeof value === 'string' && value !== '');
        if (!names.length) {
          return null;
        }
        return Array.from(new Set(names)).join(', ');
      };

      const appendDetailLine = (cell, text) => {
        if (!text) {
          return;
        }
        const detail = document.createElement('div');
        detail.className = 'resource-detail';
        detail.textContent = text;
        cell.appendChild(detail);
      };

      const renderResources = (items) => {
        const tbody = document.createElement('tbody');
        if (!items || !items.length) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 4;
          cell.className = 'muted';
          cell.textContent = 'Keine Daten gefunden.';
          row.appendChild(cell);
          tbody.appendChild(row);
        } else {
          items.forEach((item) => {
            const row = document.createElement('tr');
            const nameCell = document.createElement('td');
            const nameLine = document.createElement('div');
            nameLine.textContent = item.name || '(ohne Namen)';
            nameCell.appendChild(nameLine);

            if (item.group && typeof item.group === 'object') {
              const groupName = item.group.name || item.group.rid;
              if (groupName) {
                const label = item.group.rtype === 'zone' ? 'Zone' : 'Raum';
                appendDetailLine(nameCell, `${label}: ${groupName}`);
              }
            }

            const roomsSummary = summariseNames(item.rooms);
            if (roomsSummary) {
              appendDetailLine(nameCell, `Räume: ${roomsSummary}`);
            }

            const scenesSummary = summariseNames(item.scenes);
            if (scenesSummary) {
              appendDetailLine(nameCell, `Szenen: ${scenesSummary}`);
            }

            const idCell = document.createElement('td');
            idCell.textContent = item.id || '';
            const typeCell = document.createElement('td');
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = item.type || 'unbekannt';
            typeCell.appendChild(tag);
            const detailsCell = document.createElement('td');
            const button = document.createElement('button');
            button.className = 'secondary';
            button.type = 'button';
            button.textContent = 'JSON anzeigen';
            button.addEventListener('click', () => {
              alert(JSON.stringify(item, null, 2));
            });
            detailsCell.appendChild(button);
            row.append(nameCell, idCell, typeCell, detailsCell);
            tbody.appendChild(row);
          });
        }
        const table = document.getElementById('resource-output');
        table.replaceChild(tbody, table.querySelector('tbody'));
      };

      document.querySelectorAll('.load-resource').forEach((button) => {
        button.addEventListener('click', async () => {
          message(resourceMessage, '', '');
          if (!state.activeBridgeId) {
            message(resourceMessage, 'error', 'Bitte zuerst eine Bridge auswählen.');
            return;
          }
          const type = button.getAttribute('data-type');
          try {
            const data = await apiFetch('get_resources', {
              params: { bridge_id: state.activeBridgeId, type },
            });
            renderResources(data.items || []);
          } catch (error) {
            message(resourceMessage, 'error', `Fehler beim Laden: ${error.message}`);
          }
        });
      });

      document.getElementById('light-submit').addEventListener('click', async () => {
        message(lightMessage, '', '');
        if (!state.activeBridgeId) {
          message(lightMessage, 'error', 'Bitte zuerst eine Bridge auswählen.');
          return;
        }
        const lightId = document.getElementById('light-id').value.trim();
        if (!lightId) {
          message(lightMessage, 'error', 'Bitte eine Lampen-RID angeben.');
          return;
        }
        const action = document.getElementById('light-action').value;
        const brightnessValue = document.getElementById('light-brightness').value.trim();
        const payload = {
          bridge_id: state.activeBridgeId,
          light_id: lightId,
          on: action === 'on',
        };
        if (brightnessValue !== '') {
          const parsed = Number.parseInt(brightnessValue, 10);
          if (Number.isNaN(parsed) || parsed < 0 || parsed > 100) {
            message(lightMessage, 'error', 'Helligkeit muss zwischen 0 und 100 liegen.');
            return;
          }
          payload.brightness = parsed;
        }
        try {
          await apiFetch('light_command', { method: 'POST', body: payload });
          message(lightMessage, 'success', 'Befehl wurde gesendet.');
        } catch (error) {
          message(lightMessage, 'error', `Aktion fehlgeschlagen: ${error.message}`);
        }
      });

      document.getElementById('scene-submit').addEventListener('click', async () => {
        message(sceneMessage, '', '');
        if (!state.activeBridgeId) {
          message(sceneMessage, 'error', 'Bitte zuerst eine Bridge auswählen.');
          return;
        }
        const sceneId = document.getElementById('scene-id').value.trim();
        if (!sceneId) {
          message(sceneMessage, 'error', 'Bitte eine Szenen-RID angeben.');
          return;
        }
        const targetValue = document.getElementById('scene-target').value.trim();
        const payload = {
          bridge_id: state.activeBridgeId,
          scene_id: sceneId,
        };
        if (targetValue) {
          const [rid, rtype] = targetValue.split('::');
          if (!rid || !rtype) {
            message(sceneMessage, 'error', 'Ziel muss im Format <resource-id>::<rtype> angegeben werden.');
            return;
          }
          payload.target_rid = rid.trim();
          payload.target_rtype = rtype.trim();
        }
        try {
          await apiFetch('scene_command', { method: 'POST', body: payload });
          message(sceneMessage, 'success', 'Szene wurde aktiviert.');
        } catch (error) {
          message(sceneMessage, 'error', `Aktion fehlgeschlagen: ${error.message}`);
        }
      });

      loadBridges();
    </script>
  </body>
</html>

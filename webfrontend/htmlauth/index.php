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
    $pluginId = 'hueapiv2';
    $placeholderConfig = 'REPLACELBPCONFIGDIR';
    $configDir = getenv('LBPCONFIGDIR');

    if ($configDir !== false && $configDir !== '' && $configDir !== $placeholderConfig) {
        return rtrim($configDir, DIRECTORY_SEPARATOR) . '/config.json';
    }

    $lbHomeDir = getenv('LBHOMEDIR');
    $placeholderHome = 'REPLACELBHOMEDIR';
    if ($lbHomeDir !== false && $lbHomeDir !== '' && $lbHomeDir !== $placeholderHome) {
        $candidate = rtrim($lbHomeDir, DIRECTORY_SEPARATOR) . '/config/plugins/' . $pluginId . '/config.json';
        if (file_exists($candidate) || is_dir(dirname($candidate))) {
            return $candidate;
        }
    }

    $root = plugin_root();
    $candidates = [
        '/opt/loxberry/config/plugins/' . $pluginId . '/config.json',
        $root . '/config/config.json',
        dirname(__DIR__, 2) . '/config/config.json',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate) || is_dir(dirname($candidate))) {
            return $candidate;
        }
    }

    return $root . '/config/config.json';
}

function load_plugin_config(string $configPath): array
{
    $defaults = [
        'bridges' => [],
        'loxone' => default_loxone_settings(),
        'virtual_inputs' => [],
    ];

    if (!file_exists($configPath)) {
        return $defaults;
    }

    $raw = file_get_contents($configPath);
    if ($raw === false) {
        throw new RuntimeException("Konfigurationsdatei konnte nicht gelesen werden.");
    }

    $raw = trim($raw);
    if ($raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültige Konfigurationsdatei.');
    }

    $bridges = [];
    if (isset($decoded['bridges']) && is_array($decoded['bridges'])) {
        foreach ($decoded['bridges'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bridges[] = normalise_bridge($entry, $bridges);
        }
    } elseif (isset($decoded['bridge_ip'], $decoded['application_key'])) {
        $bridges[] = normalise_bridge($decoded, []);
    }

    if (!$bridges) {
        $bridges = [];
    }

    $loxone = isset($decoded['loxone']) ? normalise_loxone_settings($decoded['loxone']) : default_loxone_settings();
    $virtualInputs = [];
    if (isset($decoded['virtual_inputs']) && is_array($decoded['virtual_inputs'])) {
        foreach ($decoded['virtual_inputs'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $virtualInputs[] = normalise_virtual_input($entry, $bridges, $virtualInputs);
        }
    }

    return [
        'bridges' => $bridges,
        'loxone' => $loxone,
        'virtual_inputs' => $virtualInputs,
    ];
}

function save_plugin_config(string $configPath, array $config): void
{
    $bridges = $config['bridges'] ?? [];
    if (!is_array($bridges)) {
        throw new RuntimeException('Bridge-Liste ist ungültig.');
    }

    $normalisedBridges = [];
    foreach ($bridges as $bridge) {
        if (!is_array($bridge)) {
            continue;
        }
        $normalisedBridges[] = normalise_bridge($bridge, $normalisedBridges);
    }

    $loxone = isset($config['loxone']) ? normalise_loxone_settings($config['loxone']) : default_loxone_settings();

    $virtualInputs = [];
    if (isset($config['virtual_inputs']) && is_array($config['virtual_inputs'])) {
        foreach ($config['virtual_inputs'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $virtualInputs[] = normalise_virtual_input($entry, $normalisedBridges, $virtualInputs);
        }
    }

    $payload = json_encode(
        [
            'bridges' => array_values($normalisedBridges),
            'loxone' => $loxone,
            'virtual_inputs' => array_values($virtualInputs),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        throw new RuntimeException('Konfiguration konnte nicht serialisiert werden.');
    }
    $payload .= "\n";

    $dir = dirname($configPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Konfigurationsverzeichnis konnte nicht erstellt werden.');
        }
    }

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

function default_loxone_settings(): array
{
    return [
        'base_url' => null,
        'command_method' => 'POST',
        'event_method' => 'POST',
        'command_scope' => 'public',
        'command_auth_user' => null,
        'command_auth_password' => null,
    ];
}

function normalise_loxone_settings($value): array
{
    $defaults = default_loxone_settings();
    if (!is_array($value)) {
        return $defaults;
    }

    $baseUrl = isset($value['base_url']) && is_string($value['base_url'])
        ? trim($value['base_url'])
        : '';
    $baseUrl = $baseUrl !== '' ? $baseUrl : null;

    $commandMethod = isset($value['command_method']) && is_string($value['command_method'])
        ? strtoupper(trim($value['command_method']))
        : 'POST';
    if (!in_array($commandMethod, ['GET', 'POST'], true)) {
        $commandMethod = 'POST';
    }

    $eventMethod = isset($value['event_method']) && is_string($value['event_method'])
        ? strtoupper(trim($value['event_method']))
        : $commandMethod;
    if (!in_array($eventMethod, ['GET', 'POST'], true)) {
        $eventMethod = $commandMethod;
    }

    $commandScope = isset($value['command_scope']) && is_string($value['command_scope'])
        ? strtolower(trim($value['command_scope']))
        : 'public';
    if (!in_array($commandScope, ['public', 'admin'], true)) {
        $commandScope = 'public';
    }

    $authUser = isset($value['command_auth_user']) && is_string($value['command_auth_user'])
        ? trim($value['command_auth_user'])
        : '';
    $authUser = $authUser !== '' ? $authUser : null;

    $authPassword = isset($value['command_auth_password']) && is_string($value['command_auth_password'])
        ? $value['command_auth_password']
        : '';
    $authPassword = $authPassword !== '' ? $authPassword : null;

    return [
        'base_url' => $baseUrl,
        'command_method' => $commandMethod,
        'event_method' => $eventMethod,
        'command_scope' => $commandScope,
        'command_auth_user' => $authUser,
        'command_auth_password' => $authPassword,
    ];
}

function generate_virtual_input_id(?string $name, string $bridgeId, string $resourceId, array $existing): string
{
    $base = $name ? slugify($name) : '';
    if ($base === '') {
        $base = slugify($bridgeId . '-' . substr($resourceId, 0, 8));
    }
    if ($base === '') {
        $base = 'input';
    }

    $ids = array_map(
        function (array $entry): string {
            return $entry['id'];
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

function normalise_virtual_input(array $entry, array $bridges, array $existing): array
{
    $bridgeId = isset($entry['bridge_id']) ? trim((string) $entry['bridge_id']) : '';
    if ($bridgeId === '') {
        throw new RuntimeException('Bridge-ID fehlt für den Eingangs-Mapping-Eintrag.');
    }

    $bridgeExists = false;
    foreach ($bridges as $bridge) {
        if ($bridge['id'] === $bridgeId) {
            $bridgeExists = true;
            break;
        }
    }
    if (!$bridgeExists) {
        throw new RuntimeException(sprintf("Unbekannte Bridge-ID '%s' für Eingangs-Mapping.", $bridgeId));
    }

    $resourceId = isset($entry['resource_id']) ? trim((string) $entry['resource_id']) : '';
    if ($resourceId === '') {
        throw new RuntimeException('Resource-ID für den Eingangs-Mapping-Eintrag fehlt.');
    }

    $resourceType = isset($entry['resource_type']) ? trim((string) $entry['resource_type']) : '';
    if ($resourceType === '') {
        throw new RuntimeException('Resource-Typ für den Eingangs-Mapping-Eintrag fehlt.');
    }

    $virtualInput = isset($entry['virtual_input']) ? trim((string) $entry['virtual_input']) : '';
    if ($virtualInput === '') {
        throw new RuntimeException('Virtueller Eingang muss angegeben werden.');
    }

    $name = isset($entry['name']) && $entry['name'] !== '' ? (string) $entry['name'] : null;
    $trigger = isset($entry['trigger']) && $entry['trigger'] !== '' ? (string) $entry['trigger'] : null;
    $activeValue = isset($entry['active_value']) ? (string) $entry['active_value'] : '1';
    $inactiveValue = isset($entry['inactive_value']) && $entry['inactive_value'] !== ''
        ? (string) $entry['inactive_value']
        : null;
    $resetValue = isset($entry['reset_value']) && $entry['reset_value'] !== ''
        ? (string) $entry['reset_value']
        : null;
    $resetDelay = isset($entry['reset_delay_ms']) ? (int) $entry['reset_delay_ms'] : 250;
    if ($resetDelay < 0) {
        $resetDelay = 0;
    }

    $id = isset($entry['id']) && $entry['id'] !== ''
        ? (string) $entry['id']
        : generate_virtual_input_id($name, $bridgeId, $resourceId, $existing);

    return [
        'id' => $id,
        'name' => $name,
        'bridge_id' => $bridgeId,
        'resource_id' => $resourceId,
        'resource_type' => $resourceType,
        'virtual_input' => $virtualInput,
        'trigger' => $trigger,
        'active_value' => $activeValue,
        'inactive_value' => $inactiveValue,
        'reset_value' => $resetValue,
        'reset_delay_ms' => $resetDelay,
    ];
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

function extract_query_param(string $value, string $parameter): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $candidates = [];

    if (preg_match('/^https?:\/\//i', $trimmed)) {
        $parts = parse_url($trimmed);
        if ($parts !== false && isset($parts['query']) && is_string($parts['query'])) {
            $candidates[] = $parts['query'];
        }
    }

    if (strpos($trimmed, '?') !== false) {
        $query = substr($trimmed, strpos($trimmed, '?') + 1);
        if ($query !== false) {
            $candidates[] = $query;
        }
    }

    if (strpos($trimmed, '&') !== false && strpos($trimmed, '=') !== false) {
        $candidates[] = $trimmed;
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $params = [];
        parse_str($candidate, $params);
        if (!is_array($params) || $params === []) {
            continue;
        }

        if (isset($params[$parameter]) && is_string($params[$parameter])) {
            $extracted = trim($params[$parameter]);
            if ($extracted !== '') {
                return $extracted;
            }
        }
    }

    return $trimmed;
}

/**
 * @param mixed $value
 * @return array{provided: bool, value: ?bool, valid: bool}
 */
function normalise_optional_bool($value): array
{
    $result = [
        'provided' => true,
        'value' => null,
        'valid' => true,
    ];

    if ($value === null) {
        $result['provided'] = false;
        return $result;
    }

    if (is_bool($value)) {
        $result['value'] = $value;
        return $result;
    }

    if (is_int($value)) {
        $result['value'] = $value !== 0;
        return $result;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            $result['provided'] = false;
            return $result;
        }

        $map = [
            '1' => true,
            'true' => true,
            'on' => true,
            'yes' => true,
            '0' => false,
            'false' => false,
            'off' => false,
            'no' => false,
        ];

        if (array_key_exists($normalized, $map)) {
            $result['value'] = $map[$normalized];
            return $result;
        }

        $result['value'] = null;
        $result['valid'] = false;
        return $result;
    }

    $result['value'] = null;
    $result['valid'] = false;
    return $result;
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

    $lbpDataDir = getenv('LBPDATADIR');
    $placeholderData = 'REPLACELBPDATADIR';
    if ($lbpDataDir !== false && $lbpDataDir !== '' && $lbpDataDir !== $placeholderData) {
        $candidates[] = $lbpDataDir;
    }

    $candidates[] = '/opt/loxberry/data/plugins/' . $pluginId;
    $candidates[] = '/opt/loxberry/bin/plugins/' . $pluginId;
    $candidates[] = dirname(__DIR__, 2);
    $candidates[] = dirname(__DIR__, 3);

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

                $config['bridges'] = array_values($bridges);
                save_plugin_config($configPath, $config);
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
                $config['bridges'] = $bridges;
                save_plugin_config($configPath, $config);
                respond_json(['ok' => true]);
                break;

            case 'load_settings':
                $config = load_plugin_config($configPath);
                respond_json([
                    'loxone' => $config['loxone'],
                    'virtual_inputs' => $config['virtual_inputs'],
                ]);
                break;

            case 'save_loxone_settings':
                $payload = read_json_body();
                $config = load_plugin_config($configPath);
                $config['loxone'] = normalise_loxone_settings($payload);
                save_plugin_config($configPath, $config);
                respond_json(['loxone' => $config['loxone']]);
                break;

            case 'save_virtual_input':
                $payload = read_json_body();
                $config = load_plugin_config($configPath);
                $existing = $config['virtual_inputs'];
                $identifier = isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
                    ? $payload['id']
                    : null;
                if ($identifier !== null) {
                    $existing = array_values(array_filter(
                        $existing,
                        function (array $entry) use ($identifier): bool {
                            return $entry['id'] !== $identifier;
                        }
                    ));
                }
                $entry = normalise_virtual_input($payload, $config['bridges'], $existing);
                $updated = false;
                foreach ($config['virtual_inputs'] as $index => $current) {
                    if ($current['id'] === $entry['id']) {
                        $config['virtual_inputs'][$index] = $entry;
                        $updated = true;
                        break;
                    }
                }
                if (!$updated) {
                    $config['virtual_inputs'][] = $entry;
                }
                save_plugin_config($configPath, $config);
                respond_json(['virtual_input' => $entry]);
                break;

            case 'delete_virtual_input':
                $payload = read_json_body();
                $identifier = trim((string) ($payload['id'] ?? ''));
                if ($identifier === '') {
                    throw new RuntimeException('ID des virtuellen Eingangs fehlt.');
                }
                $config = load_plugin_config($configPath);
                $config['virtual_inputs'] = array_values(array_filter(
                    $config['virtual_inputs'],
                    function (array $entry) use ($identifier): bool {
                        return $entry['id'] !== $identifier;
                    }
                ));
                save_plugin_config($configPath, $config);
                respond_json(['ok' => true]);
                break;

            case 'test_virtual_input':
                $payload = request_payload();
                $identifier = extract_query_param((string) ($payload['id'] ?? ''), 'id');
                if ($identifier === '') {
                    throw new RuntimeException('ID des virtuellen Eingangs fehlt.');
                }
                $state = strtolower(trim((string) ($payload['state'] ?? 'active')));
                if ($state === '') {
                    $state = 'active';
                }
                $allowedStates = ['active', 'inactive', 'reset', 'custom'];
                if (!in_array($state, $allowedStates, true)) {
                    throw new RuntimeException('Unbekannter Testzustand.');
                }
                $args = ['forward-virtual-input', '--virtual-input-id', $identifier, '--state', $state];
                if ($state === 'custom') {
                    $value = trim((string) ($payload['value'] ?? ''));
                    if ($value === '') {
                        throw new RuntimeException('Bitte einen Wert für den Testaufruf angeben.');
                    }
                    $args[] = '--value';
                    $args[] = $value;
                }
                call_hue_cli($args);
                respond_json(['ok' => true]);
                break;

            case 'test_connection':
                $bridgeId = extract_query_param((string) ($_GET['bridge_id'] ?? ''), 'bridge_id');
                if ($bridgeId === '') {
                    throw new RuntimeException('Es wurde keine Bridge ausgewählt.');
                }
                call_hue_cli(['test-connection', '--bridge-id', $bridgeId]);
                respond_json(['ok' => true]);
                break;

            case 'get_resources':
                $bridgeId = extract_query_param((string) ($_GET['bridge_id'] ?? ''), 'bridge_id');
                $type = trim((string) ($_GET['type'] ?? ''));
                if ($bridgeId === '') {
                    throw new RuntimeException('Es wurde keine Bridge ausgewählt.');
                }
                if (!in_array($type, ['lights', 'scenes', 'rooms', 'buttons', 'motions'], true)) {
                    throw new RuntimeException('Unbekannter Ressourcentyp.');
                }
                $result = call_hue_cli(['list-resources', '--type', $type, '--bridge-id', $bridgeId]);
                $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
                respond_json(['items' => $items]);
                break;

            case 'light_command':
                $payload = request_payload();
                $bridgeId = extract_query_param((string) ($payload['bridge_id'] ?? ''), 'bridge_id');
                $lightId = extract_query_param((string) ($payload['light_id'] ?? ''), 'light_id');
                if ($bridgeId === '' || $lightId === '') {
                    throw new RuntimeException('Bridge und Lampen-RID sind erforderlich.');
                }
                $body = [];
                $stateInfo = ['provided' => false, 'value' => null, 'valid' => true];
                foreach (['state', 'on', 'value'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        $stateInfo = normalise_optional_bool($payload[$key]);
                        break;
                    }
                }
                if ($stateInfo['provided']) {
                    if (!$stateInfo['valid']) {
                        throw new RuntimeException('Ungültiger Wert für den Schaltzustand.');
                    }
                    if ($stateInfo['value'] !== null) {
                        $body['on'] = $stateInfo['value'];
                    }
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
                $bridgeId = extract_query_param((string) ($payload['bridge_id'] ?? ''), 'bridge_id');
                $sceneId = extract_query_param((string) ($payload['scene_id'] ?? ''), 'scene_id');
                if ($bridgeId === '' || $sceneId === '') {
                    throw new RuntimeException('Bridge und Szenen-RID sind erforderlich.');
                }
                $body = [];
                if (!empty($payload['target_rid']) && !empty($payload['target_rtype'])) {
                    $body['target_rid'] = extract_query_param((string) $payload['target_rid'], 'target_rid');
                    $body['target_rtype'] = (string) $payload['target_rtype'];
                }
                $stateInfo = ['provided' => false, 'value' => null, 'valid' => true];
                foreach (['state', 'on', 'value'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        $stateInfo = normalise_optional_bool($payload[$key]);
                        break;
                    }
                }
                if ($stateInfo['provided'] && !$stateInfo['valid']) {
                    throw new RuntimeException('Ungültiger Wert für den Szenenstatus.');
                }
                $args = ['scene-command', '--bridge-id', $bridgeId, '--scene-id', $sceneId];
                if (isset($body['target_rid'], $body['target_rtype'])) {
                    $args[] = '--target-rid';
                    $args[] = $body['target_rid'];
                    $args[] = '--target-rtype';
                    $args[] = $body['target_rtype'];
                }
                if ($stateInfo['provided'] && $stateInfo['value'] !== null) {
                    $args[] = $stateInfo['value'] ? '--on' : '--off';
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

if (isset($_GET['ajax']) || (isset($_GET['action']) && $_GET['action'] !== '')) {
    handle_ajax($configPath);
    return;
}

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = $scriptName !== '' ? dirname($scriptName) : '';
$segments = array_values(array_filter(explode('/', trim($scriptDir, '/'))));
$pluginFolder = end($segments);
if (!is_string($pluginFolder) || $pluginFolder === '') {
    $pluginFolder = 'hueapiv2';
}
$adminBasePath = '/admin/plugins/' . $pluginFolder . '/index.php';
$publicBasePath = '/plugins/' . $pluginFolder . '/index.php';

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

      .command-helper {
        margin-top: 1.25rem;
        padding: 1rem 1.2rem;
        border-radius: 14px;
        border: 1px dashed rgba(15, 23, 42, 0.25);
        background: rgba(255, 255, 255, 0.88);
        color: #0f172a;
      }

      .command-helper strong {
        display: block;
        font-weight: 600;
        color: var(--accent-dark);
        margin-bottom: 0.35rem;
      }

      .command-helper code {
        display: block;
        font-family: "Fira Code", "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        word-break: break-all;
        margin-bottom: 0.6rem;
        padding: 0.35rem 0.5rem;
        border-radius: 8px;
        background: rgba(15, 23, 42, 0.08);
        color: #0f172a;
      }

      .form-note {
        font-size: 0.85rem;
        line-height: 1.4;
        color: rgba(15, 23, 42, 0.65);
        margin: 0.5rem 0 0;
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
<body
  data-api-base="<?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8'); ?>"
  data-admin-base="<?= htmlspecialchars($adminBasePath, ENT_QUOTES, 'UTF-8'); ?>"
>
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
          Nach erfolgreicher Verbindung kannst du Lampen, Szenen, Räume, Schalter
          oder Bewegungsmelder aus der ausgewählten Bridge laden.
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
          <button type="button" class="secondary load-resource" data-type="buttons">
            Schalter laden
          </button>
          <button type="button" class="secondary load-resource" data-type="motions">
            Bewegungsmelder laden
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
        <h2>Loxone-Ausgänge vorbereiten</h2>
        <p class="muted">
          Wähle den Pfad für deine Befehle und hinterlege optional Zugangsdaten, falls der
          Administrationsbereich des LoxBerry per HTTP-Auth geschützt ist. Die Angaben werden nur
          für die erzeugten URLs verwendet. Trage im virtuellen Ausgang von Loxone im Hauptelement
          die vollständige Basis-URL inklusive Benutzername und Passwort ein (z.&nbsp;B.
          <code>http://loxberry:deinpasswort@loxberry</code>) und stelle die HTTP-Methode in Loxone
          auf <strong>POST</strong>. Speichere deine Auswahl anschließend mit „Einstellungen speichern“
          im Abschnitt „Loxone-Miniserver“.
        </p>
        <div class="grid two">
          <div>
            <label for="command-base-path">Basis-URL</label>
            <select id="command-base-path" form="loxone-settings-form">
              <option value="public" selected>
                Öffentlich (ohne Login) – <?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8'); ?>
              </option>
              <option value="admin">
                Administrationsbereich (mit Login) – <?= htmlspecialchars($adminBasePath, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            </select>
          </div>
          <div>
            <label for="loxone-auth-user">Benutzername (optional)</label>
            <input
              type="text"
              id="loxone-auth-user"
              placeholder="z. B. loxberry"
              autocomplete="off"
              form="loxone-settings-form"
            />
          </div>
        </div>
        <div class="grid two">
          <div>
            <label for="loxone-auth-password">Passwort (optional)</label>
            <input
              type="password"
              id="loxone-auth-password"
              placeholder="Wird in die URL eingefügt"
              autocomplete="off"
              form="loxone-settings-form"
            />
          </div>
          <div>
            <p class="form-note">
              Hinweis: Das Passwort wird nur beim Erzeugen der Befehls-URLs genutzt und nicht
              gespeichert. In Loxone erscheint die komplette URL inklusive Zugangsdaten im Klartext.
            </p>
          </div>
        </div>
      </section>

      <section class="card">
        <h2>Loxone-Miniserver</h2>
        <p class="muted">
          Hinterlege hier die Basis-URL deines Miniservers inklusive Zugangsdaten sowie die
          bevorzugten HTTP-Methoden für Befehle und Sensor-Rückmeldungen. Die Angaben werden für die
          Weiterleitung von Hue-Ereignissen an virtuelle Eingänge verwendet.
        </p>
        <form id="loxone-settings-form">
          <div class="grid two">
            <div>
              <label for="loxone-base-url">Basis-URL des Miniservers</label>
              <input
                type="text"
                id="loxone-base-url"
                placeholder="http://benutzer:passwort@miniserver"
                autocomplete="off"
              />
              <p class="form-note">
                Beispiel: <code>http://admin:pass@192.168.1.10</code>. Die Zugangsdaten werden in der
                Konfiguration gespeichert, damit das Plugin virtuelle Eingänge auslösen kann.
              </p>
            </div>
            <div>
              <label for="loxone-command-method">HTTP-Methode für Befehle</label>
              <select id="loxone-command-method">
                <option value="POST">POST (empfohlen)</option>
                <option value="GET">GET</option>
              </select>
              <label for="loxone-event-method" style="margin-top:1rem;display:block;">HTTP-Methode für Eingänge</label>
              <select id="loxone-event-method">
                <option value="POST">POST (empfohlen)</option>
                <option value="GET">GET</option>
              </select>
            </div>
          </div>
          <div class="actions">
            <button type="submit">Speichern</button>
          </div>
        </form>
        <div id="loxone-settings-message" class="message"></div>
      </section>

      <section class="card">
        <h2>Hue → Loxone Eingänge</h2>
        <p class="muted">
          Lege fest, welche Hue-Schalter oder Bewegungsmelder virtuelle Eingänge in Loxone auslösen
          sollen. Für Taster kannst du optional einen Reset-Wert definieren, der nach Ablauf einer
          kurzen Zeitspanne gesendet wird.
        </p>
        <div id="virtual-input-list" class="muted">
          <p>Noch keine Eingänge angelegt.</p>
        </div>
        <form id="virtual-input-form" style="margin-top: 1.2rem">
          <input type="hidden" id="virtual-input-id" />
          <div class="grid two">
            <div>
              <label for="virtual-input-name">Bezeichnung (optional)</label>
              <input type="text" id="virtual-input-name" placeholder="z. B. Wohnzimmer-Schalter" />
            </div>
            <div>
              <label for="virtual-input-bridge">Bridge *</label>
              <select id="virtual-input-bridge" required></select>
            </div>
          </div>
          <div class="grid two">
            <div>
              <label for="virtual-input-type">Ressourcentyp *</label>
              <select id="virtual-input-type">
                <option value="button">Schalter / Button</option>
                <option value="motion">Bewegungsmelder</option>
              </select>
            </div>
            <div>
              <label for="virtual-input-rid">Ressourcen-RID *</label>
              <input type="text" id="virtual-input-rid" placeholder="UUID aus Hue" />
            </div>
          </div>
          <div class="grid two">
            <div data-role="trigger-field">
              <label for="virtual-input-trigger">Trigger / Aktion</label>
              <input
                type="text"
                id="virtual-input-trigger"
                placeholder="z. B. short_press"
              />
              <p class="form-note">
                Für Hue-Taster: <code>short_press</code>, <code>long_press</code>, <code>repeat</code>
                usw. Leer lassen, um alle Ereignisse zu melden.
              </p>
            </div>
            <div>
              <label for="virtual-input-target">Virtueller Eingang *</label>
              <input
                type="text"
                id="virtual-input-target"
                placeholder="Name des virtuellen Eingangs in Loxone"
              />
              <p class="form-note">
                Bewegungsmelder verwenden denselben Eingang für Aktiv- und Inaktiv-Werte. Lege
                unten fest, welcher Wert bei keiner Bewegung gesendet werden soll.
              </p>
            </div>
          </div>
          <div class="grid two">
            <div>
              <label for="virtual-input-active">Aktiver Wert *</label>
              <input type="text" id="virtual-input-active" value="1" />
            </div>
            <div data-role="inactive-field">
              <label for="virtual-input-inactive">Inaktiver Wert</label>
              <input type="text" id="virtual-input-inactive" placeholder="z. B. 0" />
            </div>
          </div>
          <div class="grid two" data-role="reset-field">
            <div>
              <label for="virtual-input-reset">Reset-Wert</label>
              <input type="text" id="virtual-input-reset" placeholder="z. B. 0" />
            </div>
            <div>
              <label for="virtual-input-delay">Reset-Verzögerung (ms)</label>
              <input type="number" id="virtual-input-delay" value="250" min="0" step="50" />
            </div>
          </div>
          <div class="actions">
            <button type="submit">Speichern</button>
            <button type="button" id="virtual-input-reset-form" class="secondary">
              Formular leeren
            </button>
            <button type="button" id="virtual-input-delete" class="danger" hidden>
              Eintrag löschen
            </button>
          </div>
        </form>
        <div id="virtual-input-message" class="message"></div>
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
        <div class="command-helper" id="light-command-helper">
          <p class="muted">
            Nachdem du eine Bridge und Lampen-RID gewählt hast, erscheinen hier die fertigen
            HTTP-Aufrufe für einen virtuellen Ausgang in Loxone (Wert 1 = EIN, Wert 0 = AUS).
          </p>
        </div>
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
        <div>
          <label for="scene-action">Schaltzustand</label>
          <select id="scene-action">
            <option value="on">Aktivieren (Wert 1)</option>
            <option value="off">Ausschalten (Wert 0)</option>
          </select>
        </div>
        <div class="actions">
          <button type="button" id="scene-submit">Aktion senden</button>
        </div>
        <div id="scene-message" class="message"></div>
        <div class="command-helper" id="scene-command-helper">
          <p class="muted">
            Hier findest du nach Auswahl einer Bridge und Szene die beiden URLs für deinen
            virtuellen Ausgang. Wert 1 aktiviert die Szene, Wert 0 schaltet den zugehörigen Raum
            bzw. die Zone aus.
          </p>
        </div>
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
      const lightCommandHelper = document.getElementById('light-command-helper');
      const sceneCommandHelper = document.getElementById('scene-command-helper');
      const sceneActionSelect = document.getElementById('scene-action');
      const commandBaseSelect = document.getElementById('command-base-path');
      const authUserInput = document.getElementById('loxone-auth-user');
      const authPasswordInput = document.getElementById('loxone-auth-password');
      const loxoneSettingsForm = document.getElementById('loxone-settings-form');
      const loxoneBaseInput = document.getElementById('loxone-base-url');
      const loxoneCommandMethodSelect = document.getElementById('loxone-command-method');
      const loxoneEventMethodSelect = document.getElementById('loxone-event-method');
      const loxoneSettingsMessage = document.getElementById('loxone-settings-message');
      const virtualInputList = document.getElementById('virtual-input-list');
      const virtualInputForm = document.getElementById('virtual-input-form');
      const virtualInputMessage = document.getElementById('virtual-input-message');
      const virtualInputIdInput = document.getElementById('virtual-input-id');
      const virtualInputNameInput = document.getElementById('virtual-input-name');
      const virtualInputBridgeSelect = document.getElementById('virtual-input-bridge');
      const virtualInputTypeSelect = document.getElementById('virtual-input-type');
      const virtualInputRidInput = document.getElementById('virtual-input-rid');
      const virtualInputTriggerInput = document.getElementById('virtual-input-trigger');
      const virtualInputTargetInput = document.getElementById('virtual-input-target');
      const virtualInputActiveInput = document.getElementById('virtual-input-active');
      const virtualInputInactiveInput = document.getElementById('virtual-input-inactive');
      const virtualInputResetInput = document.getElementById('virtual-input-reset');
      const virtualInputDelayInput = document.getElementById('virtual-input-delay');
      const virtualInputResetButton = document.getElementById('virtual-input-reset-form');
      const virtualInputDeleteButton = document.getElementById('virtual-input-delete');

      const state = {
        bridges: [],
        activeBridgeId: null,
        commandTarget: 'public',
        commandAuthUser: '',
        commandAuthPassword: '',
        commandMethod: 'POST',
        eventMethod: 'POST',
        loxoneBaseUrl: '',
        virtualInputs: [],
        editingVirtualInputId: null,
      };

      const LIGHT_HELPER_DEFAULT =
        'Nachdem du eine Bridge und Lampen-RID gewählt hast, erscheinen hier die fertigen HTTP-Aufrufe ' +
        'für einen virtuellen Ausgang in Loxone (Wert 1 = EIN, Wert 0 = AUS). Verwende sie mit der HTTP-Methode POST.';
      const SCENE_HELPER_DEFAULT =
        'Hier findest du nach Auswahl einer Bridge und Szene die beiden URLs für deinen virtuellen Ausgang. ' +
        'Wert 1 aktiviert die Szene, Wert 0 schaltet den zugehörigen Raum bzw. die Zone aus. Verwende die URLs mit der HTTP-Methode POST.';

      const ensureHelperMessage = (container, text) => {
        if (!container) {
          return;
        }
        container.innerHTML = '';
        const paragraph = document.createElement('p');
        paragraph.className = 'muted';
        paragraph.textContent = text;
        container.appendChild(paragraph);
      };

      const renderHelperRows = (container, intro, rows) => {
        if (!container) {
          return;
        }
        container.innerHTML = '';
        if (intro) {
          const info = document.createElement('p');
          info.className = 'muted';
          info.textContent = intro;
          container.appendChild(info);
        }
        rows.forEach(({ label, url }) => {
          const wrapper = document.createElement('div');
          const title = document.createElement('strong');
          title.textContent = label;
          const code = document.createElement('code');
          code.textContent = url;
          wrapper.append(title, code);
          container.appendChild(wrapper);
        });
      };

      const message = (el, type, text) => {
        if (!el) {
          return;
        }
        el.textContent = text;
        el.className = type ? `message ${type}` : 'message';
        el.style.display = text ? 'block' : 'none';
      };

      const internalBase = new URL('index.php', window.location.href);
      const publicBasePath = document.body.dataset.apiBase || internalBase.pathname;
      const adminBasePath = document.body.dataset.adminBase || '';
      const publicBase = new URL(publicBasePath, window.location.origin);
      const adminBase = adminBasePath ? new URL(adminBasePath, window.location.origin) : null;

      const getCommandTarget = () => (state.commandTarget === 'admin' && adminBase ? 'admin' : 'public');

      const getCommandAuth = () => {
        const username = (state.commandAuthUser || '').trim();
        const password = state.commandAuthPassword ?? '';
        if (!username && !password) {
          return null;
        }
        return {
          username,
          password,
        };
      };

      const buildUrl = (action, params = {}, options = {}) => {
        let base;
        if (options.target === 'admin' && adminBase) {
          base = adminBase;
        } else if (options.target === 'public') {
          base = publicBase;
        } else {
          base = internalBase;
        }
        const url = new URL(base.toString());
        if (options.auth) {
          const { username, password } = options.auth;
          if (username) {
            url.username = username;
          }
          if (password) {
            url.password = password;
          }
        }
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => {
          if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
          }
        });
        return url.toString();
      };

      const renderLoxoneSettings = () => {
        if (loxoneBaseInput) {
          loxoneBaseInput.value = state.loxoneBaseUrl || '';
        }
        if (loxoneCommandMethodSelect) {
          loxoneCommandMethodSelect.value = state.commandMethod || 'POST';
        }
        if (loxoneEventMethodSelect) {
          loxoneEventMethodSelect.value = state.eventMethod || state.commandMethod || 'POST';
        }
        if (commandBaseSelect) {
          commandBaseSelect.value = state.commandTarget === 'admin' ? 'admin' : 'public';
        }
        if (authUserInput) {
          authUserInput.value = state.commandAuthUser || '';
        }
        if (authPasswordInput) {
          authPasswordInput.value = state.commandAuthPassword || '';
        }
      };

      const updateVirtualInputFieldVisibility = () => {
        if (!virtualInputTypeSelect || !virtualInputForm) {
          return;
        }
        const type = virtualInputTypeSelect.value;
        const isButton = type === 'button';
        const triggerField = virtualInputForm.querySelector('[data-role="trigger-field"]');
        const resetField = virtualInputForm.querySelector('[data-role="reset-field"]');

        if (triggerField) {
          triggerField.style.display = isButton ? '' : 'none';
        }
        if (virtualInputTriggerInput) {
          virtualInputTriggerInput.disabled = !isButton;
          if (!isButton) {
            virtualInputTriggerInput.value = '';
          }
        }

        if (resetField) {
          resetField.style.display = isButton ? '' : 'none';
        }
        if (virtualInputResetInput) {
          virtualInputResetInput.disabled = !isButton;
          if (!isButton) {
            virtualInputResetInput.value = '';
          }
        }
        if (virtualInputDelayInput) {
          virtualInputDelayInput.disabled = !isButton;
          if (!isButton) {
            virtualInputDelayInput.value = '0';
          } else if (virtualInputDelayInput.value === '' || Number(virtualInputDelayInput.value) < 0) {
            virtualInputDelayInput.value = '250';
          }
        }

        if (virtualInputInactiveInput && type === 'motion' && virtualInputInactiveInput.value.trim() === '') {
          virtualInputInactiveInput.value = '0';
        }
      };

      const renderVirtualInputForm = (entry = null) => {
        if (!virtualInputForm) {
          return;
        }

        if (virtualInputBridgeSelect) {
          virtualInputBridgeSelect.innerHTML = '';
          if (!state.bridges.length) {
            const option = document.createElement('option');
            option.textContent = 'Keine Bridge verfügbar';
            option.disabled = true;
            option.selected = true;
            virtualInputBridgeSelect.appendChild(option);
            virtualInputBridgeSelect.disabled = true;
          } else {
            virtualInputBridgeSelect.disabled = false;
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Bridge auswählen';
            placeholder.disabled = true;
            placeholder.selected = true;
            virtualInputBridgeSelect.appendChild(placeholder);
            state.bridges.forEach((bridge) => {
              const option = document.createElement('option');
              option.value = bridge.id;
              option.textContent = bridge.name ? `${bridge.name} (${bridge.id})` : bridge.id;
              virtualInputBridgeSelect.appendChild(option);
            });
          }
        }

        if (!entry) {
          if (virtualInputIdInput) {
            virtualInputIdInput.value = '';
          }
          if (virtualInputNameInput) {
            virtualInputNameInput.value = '';
          }
          if (virtualInputBridgeSelect) {
            virtualInputBridgeSelect.value = '';
          }
          if (virtualInputTypeSelect) {
            virtualInputTypeSelect.value = 'button';
          }
          if (virtualInputRidInput) {
            virtualInputRidInput.value = '';
          }
          if (virtualInputTriggerInput) {
            virtualInputTriggerInput.value = '';
          }
          if (virtualInputTargetInput) {
            virtualInputTargetInput.value = '';
          }
          if (virtualInputActiveInput) {
            virtualInputActiveInput.value = '1';
          }
          if (virtualInputInactiveInput) {
            virtualInputInactiveInput.value = '';
          }
          if (virtualInputResetInput) {
            virtualInputResetInput.value = '';
          }
          if (virtualInputDelayInput) {
            virtualInputDelayInput.value = '250';
          }
          if (virtualInputDeleteButton) {
            virtualInputDeleteButton.hidden = true;
          }
          state.editingVirtualInputId = null;
          message(virtualInputMessage, '', '');
        } else {
          if (virtualInputIdInput) {
            virtualInputIdInput.value = entry.id;
          }
          if (virtualInputNameInput) {
            virtualInputNameInput.value = entry.name || '';
          }
          if (virtualInputBridgeSelect) {
            virtualInputBridgeSelect.value = entry.bridge_id || '';
          }
          if (virtualInputTypeSelect) {
            virtualInputTypeSelect.value = entry.resource_type || 'button';
          }
          if (virtualInputRidInput) {
            virtualInputRidInput.value = entry.resource_id || '';
          }
          if (virtualInputTriggerInput) {
            virtualInputTriggerInput.value = entry.trigger || '';
          }
          if (virtualInputTargetInput) {
            virtualInputTargetInput.value = entry.virtual_input || '';
          }
          if (virtualInputActiveInput) {
            virtualInputActiveInput.value = entry.active_value || '1';
          }
          if (virtualInputInactiveInput) {
            virtualInputInactiveInput.value = entry.inactive_value || '';
          }
          if (virtualInputResetInput) {
            virtualInputResetInput.value = entry.reset_value || '';
          }
          if (virtualInputDelayInput) {
            virtualInputDelayInput.value = String(entry.reset_delay_ms ?? 250);
          }
          if (virtualInputDeleteButton) {
            virtualInputDeleteButton.hidden = false;
          }
          state.editingVirtualInputId = entry.id;
        }

        updateVirtualInputFieldVisibility();
      };

      const renderVirtualInputList = () => {
        if (!virtualInputList) {
          return;
        }
        virtualInputList.innerHTML = '';
        if (!state.virtualInputs.length) {
          const paragraph = document.createElement('p');
          paragraph.className = 'muted';
          paragraph.textContent = 'Noch keine Eingänge angelegt.';
          virtualInputList.appendChild(paragraph);
          return;
        }

        const list = document.createElement('ul');
        list.className = 'bridge-items';
        state.virtualInputs.forEach((entry) => {
          const item = document.createElement('li');
          item.className = 'bridge-item';
          const info = document.createElement('div');
          const title = document.createElement('strong');
          title.textContent = entry.name || entry.virtual_input;
          info.appendChild(title);

          const details = document.createElement('div');
          details.className = 'resource-detail';
          details.textContent = `Bridge: ${entry.bridge_id} • Typ: ${entry.resource_type} • RID: ${entry.resource_id}`;
          info.appendChild(details);

          if (entry.trigger) {
            const triggerInfo = document.createElement('div');
            triggerInfo.className = 'resource-detail';
            triggerInfo.textContent = `Trigger: ${entry.trigger}`;
            info.appendChild(triggerInfo);
          }

          const valueInfo = document.createElement('div');
          valueInfo.className = 'resource-detail';
          const parts = [`Aktiv: ${entry.active_value}`];
          if (entry.inactive_value) {
            parts.push(`Inaktiv: ${entry.inactive_value}`);
          }
          if (entry.reset_value) {
            parts.push(`Reset: ${entry.reset_value} (${entry.reset_delay_ms} ms)`);
          }
          valueInfo.textContent = parts.join(' • ');
          info.appendChild(valueInfo);

          const actions = document.createElement('div');
          actions.className = 'bridge-actions';

          const testActiveButton = document.createElement('button');
          testActiveButton.type = 'button';
          testActiveButton.className = 'secondary';
          testActiveButton.textContent = 'Test aktiv';
          testActiveButton.dataset.action = 'test-virtual-input-active';
          testActiveButton.dataset.id = entry.id;
          actions.appendChild(testActiveButton);

          if (entry.inactive_value) {
            const testInactiveButton = document.createElement('button');
            testInactiveButton.type = 'button';
            testInactiveButton.className = 'secondary';
            testInactiveButton.textContent = 'Test inaktiv';
            testInactiveButton.dataset.action = 'test-virtual-input-inactive';
            testInactiveButton.dataset.id = entry.id;
            actions.appendChild(testInactiveButton);
          }

          if (entry.reset_value) {
            const testResetButton = document.createElement('button');
            testResetButton.type = 'button';
            testResetButton.className = 'secondary';
            testResetButton.textContent = 'Test Reset';
            testResetButton.dataset.action = 'test-virtual-input-reset';
            testResetButton.dataset.id = entry.id;
            actions.appendChild(testResetButton);
          }

          const editButton = document.createElement('button');
          editButton.type = 'button';
          editButton.className = 'secondary';
          editButton.textContent = 'Bearbeiten';
          editButton.dataset.action = 'edit-virtual-input';
          editButton.dataset.id = entry.id;
          const deleteButton = document.createElement('button');
          deleteButton.type = 'button';
          deleteButton.className = 'danger';
          deleteButton.textContent = 'Löschen';
          deleteButton.dataset.action = 'delete-virtual-input';
          deleteButton.dataset.id = entry.id;
          actions.append(editButton, deleteButton);

          item.append(info, actions);
          list.appendChild(item);
        });

        virtualInputList.appendChild(list);
      };

      const updateLightCommandHelper = () => {
        if (!lightCommandHelper) {
          return;
        }
        const lightId = document.getElementById('light-id').value.trim();
        const brightnessValue = document.getElementById('light-brightness').value.trim();
        if (!state.activeBridgeId) {
          ensureHelperMessage(lightCommandHelper, 'Bitte zuerst eine Bridge auswählen.');
          return;
        }
        if (!lightId) {
          ensureHelperMessage(lightCommandHelper, 'Bitte eine Lampen-RID eingeben, um die URLs zu erhalten.');
          return;
        }
        const baseParams = {
          bridge_id: state.activeBridgeId,
          light_id: lightId,
        };
        const onParams = { ...baseParams, on: '1' };
        if (brightnessValue !== '') {
          onParams.brightness = brightnessValue;
        }
        const offParams = { ...baseParams, on: '0' };
        const commandOptions = { target: getCommandTarget(), auth: getCommandAuth() };
        const methodLabel = state.commandMethod || 'POST';
        renderHelperRows(lightCommandHelper, `Virtueller Ausgang (HTTP ${methodLabel}):`, [
          { label: 'Virtueller Ausgang – EIN (Wert 1):', url: buildUrl('light_command', onParams, commandOptions) },
          { label: 'Virtueller Ausgang – AUS (Wert 0):', url: buildUrl('light_command', offParams, commandOptions) },
        ]);
      };

      const updateSceneCommandHelper = () => {
        if (!sceneCommandHelper) {
          return;
        }
        const sceneId = document.getElementById('scene-id').value.trim();
        const targetValue = document.getElementById('scene-target').value.trim();
        if (!state.activeBridgeId) {
          ensureHelperMessage(sceneCommandHelper, 'Bitte zuerst eine Bridge auswählen.');
          return;
        }
        if (!sceneId) {
          ensureHelperMessage(sceneCommandHelper, 'Bitte eine Szenen-RID eingeben, um die URLs zu erhalten.');
          return;
        }
        let rid = null;
        let rtype = null;
        if (targetValue) {
          const [ridRaw, rtypeRaw] = targetValue.split('::');
          if (!ridRaw || !rtypeRaw) {
            ensureHelperMessage(
              sceneCommandHelper,
              'Ziel muss im Format <resource-id>::<rtype> angegeben werden, z. B. <room-id>::room.'
            );
            return;
          }
          rid = ridRaw.trim();
          rtype = rtypeRaw.trim();
        }
        const baseParams = {
          bridge_id: state.activeBridgeId,
          scene_id: sceneId,
        };
        if (rid && rtype) {
          baseParams.target_rid = rid;
          baseParams.target_rtype = rtype;
        }
        const onParams = { ...baseParams, state: '1' };
        const offParams = { ...baseParams, state: '0' };
        const commandOptions = { target: getCommandTarget(), auth: getCommandAuth() };
        const methodLabel = state.commandMethod || 'POST';
        renderHelperRows(sceneCommandHelper, `Virtueller Ausgang (HTTP ${methodLabel}):`, [
          { label: 'Virtueller Ausgang – EIN (Wert 1):', url: buildUrl('scene_command', onParams, commandOptions) },
          { label: 'Virtueller Ausgang – AUS (Wert 0):', url: buildUrl('scene_command', offParams, commandOptions) },
        ]);
      };

      const updateCommandHelpers = () => {
        updateLightCommandHelper();
        updateSceneCommandHelper();
      };

      ensureHelperMessage(lightCommandHelper, LIGHT_HELPER_DEFAULT);
      ensureHelperMessage(sceneCommandHelper, SCENE_HELPER_DEFAULT);

      if (commandBaseSelect) {
        commandBaseSelect.addEventListener('change', () => {
          state.commandTarget = commandBaseSelect.value === 'admin' ? 'admin' : 'public';
          updateCommandHelpers();
        });
      }

      const handleAuthChange = () => {
        state.commandAuthUser = authUserInput ? authUserInput.value.trim() : '';
        state.commandAuthPassword = authPasswordInput ? authPasswordInput.value : '';
        updateCommandHelpers();
      };

      if (authUserInput) {
        authUserInput.addEventListener('input', handleAuthChange);
      }

      if (authPasswordInput) {
        authPasswordInput.addEventListener('input', handleAuthChange);
      }

      if (loxoneCommandMethodSelect) {
        loxoneCommandMethodSelect.addEventListener('change', () => {
          state.commandMethod = loxoneCommandMethodSelect.value || 'POST';
          updateCommandHelpers();
        });
      }

      if (loxoneEventMethodSelect) {
        loxoneEventMethodSelect.addEventListener('change', () => {
          state.eventMethod = loxoneEventMethodSelect.value || state.commandMethod || 'POST';
        });
      }

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
        updateCommandHelpers();
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
          renderVirtualInputForm();
          updateCommandHelpers();
        } catch (error) {
          message(bridgeMessage, 'error', error.message);
          state.bridges = [];
          state.activeBridgeId = null;
          renderBridgeSelect();
          renderBridgeList();
          renderVirtualInputForm();
          updateCommandHelpers();
        }
      };

      const loadSettings = async () => {
        try {
          const data = await apiFetch('load_settings');
          const loxone = data.loxone || {};
          state.loxoneBaseUrl = loxone.base_url || '';
          state.commandMethod = loxone.command_method || 'POST';
          state.eventMethod = loxone.event_method || state.commandMethod || 'POST';
          state.commandTarget = loxone.command_scope === 'admin' ? 'admin' : 'public';
          state.commandAuthUser = loxone.command_auth_user || '';
          state.commandAuthPassword = loxone.command_auth_password || '';
          state.virtualInputs = Array.isArray(data.virtual_inputs) ? data.virtual_inputs : [];
          renderLoxoneSettings();
          renderVirtualInputList();
          renderVirtualInputForm();
          updateCommandHelpers();
        } catch (error) {
          message(loxoneSettingsMessage, 'error', `Einstellungen konnten nicht geladen werden: ${error.message}`);
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

      if (loxoneSettingsForm) {
        loxoneSettingsForm.addEventListener('submit', async (event) => {
          event.preventDefault();
          const payload = {
            base_url: loxoneBaseInput ? loxoneBaseInput.value.trim() : '',
            command_method: loxoneCommandMethodSelect ? loxoneCommandMethodSelect.value : 'POST',
            event_method: loxoneEventMethodSelect ? loxoneEventMethodSelect.value : state.eventMethod || 'POST',
            command_scope: getCommandTarget(),
            command_auth_user: state.commandAuthUser || '',
            command_auth_password: state.commandAuthPassword || '',
          };
          try {
            const data = await apiFetch('save_loxone_settings', { method: 'POST', body: payload });
            const loxone = data.loxone || {};
            state.loxoneBaseUrl = loxone.base_url || '';
            state.commandMethod = loxone.command_method || 'POST';
            state.eventMethod = loxone.event_method || state.commandMethod || 'POST';
            state.commandTarget = loxone.command_scope === 'admin' ? 'admin' : 'public';
            state.commandAuthUser = loxone.command_auth_user || '';
            state.commandAuthPassword = loxone.command_auth_password || '';
            renderLoxoneSettings();
            message(loxoneSettingsMessage, 'success', 'Einstellungen gespeichert.');
            updateCommandHelpers();
          } catch (error) {
            message(loxoneSettingsMessage, 'error', error.message);
          }
        });
      }

      if (virtualInputTypeSelect) {
        virtualInputTypeSelect.addEventListener('change', updateVirtualInputFieldVisibility);
      }

      if (virtualInputResetButton) {
        virtualInputResetButton.addEventListener('click', (event) => {
          event.preventDefault();
          renderVirtualInputForm();
        });
      }

      if (virtualInputDeleteButton) {
        virtualInputDeleteButton.addEventListener('click', async () => {
          if (!state.editingVirtualInputId) {
            return;
          }
          if (!window.confirm('Virtuellen Eingang wirklich löschen?')) {
            return;
          }
          try {
            await apiFetch('delete_virtual_input', { method: 'POST', body: { id: state.editingVirtualInputId } });
            state.virtualInputs = state.virtualInputs.filter((entry) => entry.id !== state.editingVirtualInputId);
            message(virtualInputMessage, 'success', 'Eingang gelöscht.');
            renderVirtualInputList();
            renderVirtualInputForm();
          } catch (error) {
            message(virtualInputMessage, 'error', error.message);
          }
        });
      }

      if (virtualInputList) {
        virtualInputList.addEventListener('click', async (event) => {
          const target = event.target;
          if (!(target instanceof HTMLElement)) {
            return;
          }
          const action = target.dataset.action;
          const id = target.dataset.id;
          if (!action || !id) {
            return;
          }
          const entry = state.virtualInputs.find((item) => item.id === id);
          if (!entry) {
            return;
          }
          if (action === 'edit-virtual-input') {
            renderVirtualInputForm(entry);
            message(virtualInputMessage, '', '');
            window.scrollTo({ top: virtualInputForm.offsetTop - 40, behavior: 'smooth' });
          } else if (action === 'delete-virtual-input') {
            if (!window.confirm('Virtuellen Eingang wirklich löschen?')) {
              return;
            }
            try {
              await apiFetch('delete_virtual_input', { method: 'POST', body: { id } });
              state.virtualInputs = state.virtualInputs.filter((item) => item.id !== id);
              message(virtualInputMessage, 'success', 'Eingang gelöscht.');
              renderVirtualInputList();
              renderVirtualInputForm();
            } catch (error) {
              message(virtualInputMessage, 'error', error.message);
            }
          } else if (
            action === 'test-virtual-input-active' ||
            action === 'test-virtual-input-inactive' ||
            action === 'test-virtual-input-reset'
          ) {
            const stateValue =
              action === 'test-virtual-input-inactive'
                ? 'inactive'
                : action === 'test-virtual-input-reset'
                ? 'reset'
                : 'active';
            try {
              await apiFetch('test_virtual_input', {
                method: 'POST',
                body: { id, state: stateValue },
              });
              const label =
                stateValue === 'inactive'
                  ? 'Inaktiv'
                  : stateValue === 'reset'
                  ? 'Reset'
                  : 'Aktiv';
              message(virtualInputMessage, 'success', `Test (${label}) ausgelöst.`);
            } catch (error) {
              message(virtualInputMessage, 'error', error.message);
            }
          }
        });
      }

      if (virtualInputForm) {
        virtualInputForm.addEventListener('submit', async (event) => {
          event.preventDefault();
          if (!state.bridges.length) {
            message(virtualInputMessage, 'error', 'Bitte zuerst mindestens eine Bridge anlegen.');
            return;
          }
          const bridgeId = virtualInputBridgeSelect ? virtualInputBridgeSelect.value.trim() : '';
          const resourceType = virtualInputTypeSelect ? virtualInputTypeSelect.value : 'button';
          const resourceId = virtualInputRidInput ? virtualInputRidInput.value.trim() : '';
          const target = virtualInputTargetInput ? virtualInputTargetInput.value.trim() : '';
          const activeValue = virtualInputActiveInput ? virtualInputActiveInput.value.trim() : '';
          if (!bridgeId || !resourceId || !target || !activeValue) {
            message(virtualInputMessage, 'error', 'Bridge, Ressourcen-RID, virtueller Eingang und aktiver Wert sind Pflichtfelder.');
            return;
          }
          const inactiveValue = virtualInputInactiveInput ? virtualInputInactiveInput.value.trim() : '';
          const resetValue = virtualInputResetInput ? virtualInputResetInput.value.trim() : '';
          const delayValue = virtualInputDelayInput ? parseInt(virtualInputDelayInput.value, 10) : 250;
          const payload = {
            id: state.editingVirtualInputId,
            name: virtualInputNameInput ? virtualInputNameInput.value.trim() : '',
            bridge_id: bridgeId,
            resource_type: resourceType,
            resource_id: resourceId,
            trigger: virtualInputTriggerInput ? virtualInputTriggerInput.value.trim() : '',
            virtual_input: target,
            active_value: activeValue,
            inactive_value: inactiveValue,
            reset_value: resetValue,
            reset_delay_ms: Number.isFinite(delayValue) && delayValue >= 0 ? delayValue : 0,
          };
          try {
            const data = await apiFetch('save_virtual_input', { method: 'POST', body: payload });
            const entry = data.virtual_input;
            if (!entry) {
              throw new Error('Antwort enthielt keinen virtuellen Eingang.');
            }
            const existingIndex = state.virtualInputs.findIndex((item) => item.id === entry.id);
            if (existingIndex >= 0) {
              state.virtualInputs[existingIndex] = entry;
            } else {
              state.virtualInputs.push(entry);
            }
            message(virtualInputMessage, 'success', 'Eingang gespeichert.');
            renderVirtualInputList();
            if (state.editingVirtualInputId) {
              renderVirtualInputForm(entry);
            } else {
              renderVirtualInputForm();
            }
          } catch (error) {
            message(virtualInputMessage, 'error', error.message);
          }
        });
      }

      bridgeSelect.addEventListener('change', (event) => {
        setActiveBridge(event.target.value || null);
      });

      reloadBridgesButton.addEventListener('click', async () => {
        message(bridgeMessage, '', '');
        await loadBridges();
      });

      document.getElementById('light-id').addEventListener('input', updateLightCommandHelper);
      document.getElementById('light-brightness').addEventListener('input', updateLightCommandHelper);
      document.getElementById('scene-id').addEventListener('input', updateSceneCommandHelper);
      document.getElementById('scene-target').addEventListener('input', updateSceneCommandHelper);

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

            if (item.device && typeof item.device === 'object') {
              const rawName = typeof item.device.name === 'string' ? item.device.name.trim() : '';
              const fallback = typeof item.device.id === 'string' ? item.device.id.trim() : '';
              const deviceLabel = rawName || fallback;
              if (deviceLabel) {
                appendDetailLine(nameCell, `Gerät: ${deviceLabel}`);
              }
            }

            if (typeof item.state === 'boolean') {
              appendDetailLine(nameCell, `Bewegung: ${item.state ? 'aktiv' : 'inaktiv'}`);
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
        const actionValue = sceneActionSelect ? sceneActionSelect.value : 'on';
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
        if (sceneActionSelect) {
          payload.state = actionValue === 'off' ? 0 : 1;
        }
        try {
          await apiFetch('scene_command', { method: 'POST', body: payload });
          const successMessage = actionValue === 'off'
            ? 'Zugehöriger Raum wurde ausgeschaltet.'
            : 'Szene wurde aktiviert.';
          message(sceneMessage, 'success', successMessage);
        } catch (error) {
          message(sceneMessage, 'error', `Aktion fehlgeschlagen: ${error.message}`);
        }
      });

      updateCommandHelpers();
      loadSettings();
      loadBridges();
    </script>
  </body>
</html>

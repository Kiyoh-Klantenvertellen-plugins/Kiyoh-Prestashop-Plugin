<?php
/**
 * Local stand-in for Kiyoh-Connect 1.2 public shop register, token, and credentials.
 * The shop credential is issued once and stored in KIYOH_CONNECT_SHOPS.
 */

define('_PS_VERSION_', '9.2.0');
require dirname(__DIR__) . '/kiyoh/classes/KiyohConnect.php';

$log = getenv('KIYOH_CONNECT_LOG');
if (!is_string($log) || $log === '') {
    $log = sys_get_temp_dir() . '/kiyoh-connect-test.log';
}
$storeFile = getenv('KIYOH_CONNECT_SHOPS');
if (!is_string($storeFile) || $storeFile === '') {
    $storeFile = sys_get_temp_dir() . '/kiyoh-connect-shops.json';
}
$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    $raw = '';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}
file_put_contents($log, json_encode([
    'method' => $method,
    'path' => $path,
    'body' => $raw,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($method === 'POST' && $path === '/shops/register') {
    $data = json_decode($raw, true);
    $allowed = [
        KiyohConnect::wire('clientId'),
        KiyohConnect::wire('shopUrl'),
        KiyohConnect::wire('redirectUri'),
    ];
    $ok = is_array($data)
        && ($data[KiyohConnect::wire('clientId')] ?? '') === KiyohConnect::CLIENT_ID
        && is_string($data[KiyohConnect::wire('shopUrl')] ?? null)
        && is_string($data[KiyohConnect::wire('redirectUri')] ?? null)
        && array_diff(array_keys($data), $allowed) === [];
    if (!$ok) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request']);
        return;
    }
    $shops = readShops($storeFile);
    $key = shopKey((string) $data[KiyohConnect::wire('shopUrl')], (string) $data[KiyohConnect::wire('redirectUri')]);
    if (isset($shops[$key]) && is_array($shops[$key])) {
        http_response_code(200);
        echo json_encode([
            KiyohConnect::wire('shopId') => $shops[$key]['id'],
        ]);
        return;
    }
    $record = [
        'id' => 'shop-' . substr(hash('sha256', $key), 0, 12),
        'value' => bin2hex(random_bytes(16)),
        'url' => (string) $data[KiyohConnect::wire('shopUrl')],
        'redirect' => (string) $data[KiyohConnect::wire('redirectUri')],
    ];
    $shops[$key] = $record;
    writeShops($storeFile, $shops);
    http_response_code(200);
    echo json_encode([
        KiyohConnect::wire('shopId') => $record['id'],
        KiyohConnect::wire('shopSecret') => $record['value'],
    ]);
    return;
}

if ($method === 'POST' && $path === '/token') {
    parse_str($raw, $form);
    $matched = is_array($form) ? matchShop($storeFile, $form) : null;
    if (!is_array($form) || array_key_exists('client_secret', $form) || $matched === null) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    $grant = (string) ($form[KiyohConnect::wire('grantType')] ?? '');
    if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        return;
    }
    if ($grant === 'authorization_code' && (
        ($form[KiyohConnect::wire('codeVerifier')] ?? '') === ''
        || ($form[KiyohConnect::wire('redirectUri')] ?? '') !== $matched['redirect']
        || ($form[KiyohConnect::wire('code')] ?? '') === ''
    )) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);
        return;
    }
    http_response_code(200);
    readfile(dirname(__FILE__) . '/fixtures/connect-token.json');
    return;
}

if ($method === 'GET' && $path === '/integration/credentials') {
    $counterFile = getenv('KIYOH_CONNECT_COUNTER');
    $seen = 0;
    if (is_string($counterFile) && $counterFile !== '') {
        $current = file_get_contents($counterFile);
        $seen = is_string($current) ? (int) $current : 0;
        file_put_contents($counterFile, (string) ($seen + 1));
    }
    if ($seen === 0 && getenv('KIYOH_CONNECT_429') === '1') {
        header('Retry-After: 0');
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited', 'retryAfterSeconds' => 0]);
        return;
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $authorization = (string) $value;
                }
            }
        }
    }
    if ($authorization !== 'Bearer access-token-test') {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        return;
    }
    http_response_code(200);
    readfile(dirname(__FILE__) . '/fixtures/connect-credentials.json');
    return;
}

if ($method === 'POST' && $path === '/revoke') {
    parse_str($raw, $form);
    $matched = is_array($form) ? matchShop($storeFile, $form) : null;
    if (!is_array($form) || array_key_exists('client_secret', $form) || $matched === null || ($form[KiyohConnect::wire('token')] ?? '') === '') {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    http_response_code(200);
    echo json_encode(['revoked' => true]);
    return;
}

if ($method === 'POST' && $path === '/shops/revoke') {
    $data = json_decode($raw, true);
    $matched = is_array($data) ? matchShop($storeFile, $data) : null;
    $ok = is_array($data)
        && $matched !== null
        && ($data[KiyohConnect::wire('clientId')] ?? '') === KiyohConnect::CLIENT_ID
        && ($data[KiyohConnect::wire('shopUrl')] ?? '') === $matched['url']
        && ($data[KiyohConnect::wire('redirectUri')] ?? '') === $matched['redirect']
        && !array_key_exists('signature', $data)
        && !array_key_exists('client_secret', $data);
    if (!$ok) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    http_response_code(204);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);

/**
 * @return array<string, array{id: string, value: string, url: string, redirect: string}>
 */
function readShops(string $file): array
{
    $raw = is_file($file) ? file_get_contents($file) : '';
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    $shops = [];
    foreach ($decoded as $key => $row) {
        if (!is_string($key) || !is_array($row)) {
            continue;
        }
        if (!isset($row['id'], $row['value'], $row['url'], $row['redirect']) || !is_string($row['id']) || !is_string($row['value']) || !is_string($row['url']) || !is_string($row['redirect'])) {
            continue;
        }
        $shops[$key] = [
            'id' => $row['id'],
            'value' => $row['value'],
            'url' => $row['url'],
            'redirect' => $row['redirect'],
        ];
    }

    return $shops;
}

/**
 * @param array<string, array{id: string, value: string, url: string, redirect: string}> $shops
 */
function writeShops(string $file, array $shops): void
{
    file_put_contents($file, json_encode($shops));
}

function shopKey(string $url, string $redirect): string
{
    return hash('sha256', $url . "\n" . $redirect);
}

/**
 * @param array<string, mixed> $fields
 * @return array{id: string, value: string, url: string, redirect: string}|null
 */
function matchShop(string $file, array $fields): ?array
{
    $id = $fields[KiyohConnect::wire('shopId')] ?? null;
    $value = $fields[KiyohConnect::wire('shopSecret')] ?? null;
    if (!is_string($id) || !is_string($value) || $id === '' || $value === '') {
        return null;
    }
    if (($fields[KiyohConnect::wire('clientId')] ?? KiyohConnect::CLIENT_ID) !== KiyohConnect::CLIENT_ID) {
        return null;
    }
    foreach (readShops($file) as $row) {
        if (strlen($row['id']) === strlen($id) && strlen($row['value']) === strlen($value) && hash_equals($row['id'], $id) && hash_equals($row['value'], $value)) {
            return $row;
        }
    }

    return null;
}

<?php
/**
 * Local stand-in for Kiyoh-Connect 1.1 shop register, token, and credentials.
 * The HMAC key and client secret come from the test process environment.
 */

define('_PS_VERSION_', '9.2.0');
require dirname(__DIR__) . '/kiyoh/classes/KiyohConnect.php';

$log = getenv('KIYOH_CONNECT_LOG');
if (!is_string($log) || $log === '') {
    $log = sys_get_temp_dir() . '/kiyoh-connect-test.log';
}
$hmac = getenv('KIYOH_CONNECT_TEST_HMAC');
$secret = getenv('KIYOH_CONNECT_TEST_SECRET');
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

if (($method === 'POST') && ($path === '/shops/register' || $path === '/shops/revoke')) {
    $data = json_decode($raw, true);
    $action = $path === '/shops/revoke' ? 'revoke' : 'register';
    $ok = is_array($data)
        && ($data['client_id'] ?? '') === KiyohConnect::CLIENT_ID
        && is_string($hmac)
        && isset($data['shop_url'], $data['redirect_uri'], $data['issued_at'], $data['signature'])
        && is_numeric($data['issued_at']);
    if ($ok) {
        $canonical = KiyohConnect::canonicalString(
            $action,
            KiyohConnect::CLIENT_ID,
            (string) $data['shop_url'],
            (string) $data['redirect_uri'],
            (int) $data['issued_at']
        );
        $expected = KiyohConnect::sign($canonical, (string) $hmac);
        $ok = hash_equals($expected, (string) $data['signature']);
    }
    if (!$ok) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    http_response_code(204);
    return;
}

if ($method === 'POST' && $path === '/token') {
    parse_str($raw, $form);
    if (!is_array($form) || !is_string($secret) || ($form['client_secret'] ?? '') !== $secret || ($form['client_id'] ?? '') !== KiyohConnect::CLIENT_ID) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    $grant = (string) ($form['grant_type'] ?? '');
    if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        return;
    }
    if ($grant === 'authorization_code' && (($form['code_verifier'] ?? '') === '' || ($form['redirect_uri'] ?? '') === '')) {
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
    if (!is_array($form) || !is_string($secret) || ($form['client_secret'] ?? '') !== $secret) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        return;
    }
    http_response_code(200);
    echo json_encode(['revoked' => true]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);

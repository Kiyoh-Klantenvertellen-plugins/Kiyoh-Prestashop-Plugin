<?php
/**
 * Kiyoh-Connect 1.1 request rules. No PrestaShop installation required.
 *
 * php tests/KiyohConnectTest.php
 */

define('_PS_VERSION_', '9.2.0');

require dirname(__DIR__) . '/kiyoh/classes/KiyohConnect.php';

$failures = 0;
$assertions = 0;

function check($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function same($expected, $actual, $message)
{
    check(
        $expected === $actual,
        $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
    );
}

$origin = 'https://shop.example';
$redirect = 'https://shop.example/module/kiyoh/oauth';
same($origin, KiyohConnect::shopOrigin('Shop.Example'), 'shop origin is https and lowercase');
same($redirect, KiyohConnect::redirectUri('shop.example', '/'), 'root shop return address');
same(
    'https://shop.example/nl/module/kiyoh/oauth',
    KiyohConnect::redirectUri('shop.example', '/nl/'),
    'subdirectory shop keeps its base path'
);
same(null, KiyohConnect::redirectUri('localhost', '/'), 'localhost is not a public return host');
same(null, KiyohConnect::redirectUri('10.1.2.3', '/'), 'private IP is not a public return host');
same(null, KiyohConnect::redirectUri('shop.example', '/../admin'), 'base path cannot climb');
same(KiyohConnect::ISSUER_KIYOH, KiyohConnect::issuerForPlatform('kiyoh'), 'Kiyoh issuer');
same(KiyohConnect::ISSUER_KLANTENVERTELLEN, KiyohConnect::issuerForPlatform('klantenvertellen'), 'Klantenvertellen issuer');
same(98, KiyohConnect::tenantForPlatform('kiyoh'), 'Kiyoh tenant 98');
same(99, KiyohConnect::tenantForPlatform('klantenvertellen'), 'Klantenvertellen tenant 99');
same(null, KiyohConnect::issuerForPlatform('other'), 'unknown platform has no issuer');

$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', KiyohConnect::pkceChallenge($verifier), 'RFC 7636 S256 vector');
$state = KiyohConnect::randomState();
check(preg_match('/^[A-Za-z0-9_-]{43}$/', $state) === 1, 'state is unpadded base64url');

$issuedAt = 1700000000;
$canonical = KiyohConnect::canonicalString('register', KiyohConnect::CLIENT_ID, $origin, $redirect, $issuedAt);
same(
    "v1\nregister\nkiyoh-prestashop\nhttps://shop.example\nhttps://shop.example/module/kiyoh/oauth\n1700000000",
    $canonical,
    'register signature text is the LF-joined lines'
);
$revokeCanonical = KiyohConnect::canonicalString('revoke', KiyohConnect::CLIENT_ID, $origin, $redirect, $issuedAt);
check($revokeCanonical !== $canonical, 'uninstall uses the revoke action word');
check(str_starts_with($revokeCanonical, "v1\nrevoke\nkiyoh-prestashop\n"), 'revoke canonical uses the revoke action');
$hmacKey = 'test-hmac-key';
$signature = KiyohConnect::sign($canonical, $hmacKey);
same(KiyohConnect::base64Url(hash_hmac('sha256', $canonical, $hmacKey, true)), $signature, 'signature is unpadded base64url hmac');
check(!str_contains($signature, '+') && !str_contains($signature, '/') && !str_contains($signature, '='), 'signature has no base64 padding');
$again = KiyohConnect::shopRequest('register', $origin, $redirect, $issuedAt, $hmacKey);
$repeat = KiyohConnect::shopRequest('register', $origin, $redirect, $issuedAt, $hmacKey);
same($again, $repeat, 'the same register input signs the same body');
check($again['signature'] !== KiyohConnect::shopRequest('register', $origin, $redirect, $issuedAt + 1, $hmacKey)['signature'], 'a new issued_at changes the signature');
same(KiyohConnect::CLIENT_ID, $again['client_id'], 'register client id');
same($origin, $again['shop_url'], 'shop_url is the shop origin');
same($issuedAt, $again['issued_at'], 'issued_at stays an integer');

$authorize = KiyohConnect::authorizeUrl(KiyohConnect::ISSUER_KIYOH, $redirect, 'state-value', KiyohConnect::pkceChallenge($verifier));
$parts = parse_url($authorize);
parse_str((string) ($parts['query'] ?? ''), $query);
same('https', $parts['scheme'] ?? '', 'authorize is https');
same('connect.kiyoh.com', $parts['host'] ?? '', 'authorize host');
same('/authorize', $parts['path'] ?? '', 'authorize path');
same('code', $query['response_type'] ?? '', 'response type');
same('kiyoh-prestashop', $query['client_id'] ?? '', 'authorize client id');
same($redirect, $query['redirect_uri'] ?? '', 'authorize redirect_uri is the shop return address');
same('integration:connect', $query['scope'] ?? '', 'scope');
same('S256', $query['code_challenge_method'] ?? '', 'PKCE method');
same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $query['code_challenge'] ?? '', 'authorize challenge');
same('state-value', $query['state'] ?? '', 'authorize state');

$sharedClient = 'test-client-secret';
$form = KiyohConnect::authorizationCodeForm('one-time-code', $verifier, $redirect, $sharedClient);
same('authorization_code', $form['grant_type'], 'token grant');
same('kiyoh-prestashop', $form['client_id'], 'token client id');
same($sharedClient, $form['client_secret'], 'token form carries the client secret');
same('one-time-code', $form['code'], 'token code');
same($verifier, $form['code_verifier'], 'token verifier');
same($redirect, $form['redirect_uri'], 'token redirect_uri matches authorize');
$refresh = KiyohConnect::refreshForm('refresh-token-test', $sharedClient);
same('refresh_token', $refresh['grant_type'], 'refresh grant');
check(!isset($refresh['code']), 'refresh does not resend the authorization code');
$revoke = KiyohConnect::revokeTokenForm('refresh-token-test', $sharedClient, 'refresh_token');
same('refresh_token', $revoke['token_type_hint'], 'revoke hints the refresh token');

$tokenJson = json_decode((string) file_get_contents(dirname(__FILE__) . '/fixtures/connect-token.json'), true);
$credentialsJson = json_decode((string) file_get_contents(dirname(__FILE__) . '/fixtures/connect-credentials.json'), true);
check(is_array($tokenJson) && is_array($credentialsJson), 'fixtures parse');
$token = is_array($tokenJson) ? KiyohConnect::normalizeToken($tokenJson) : null;
$credentials = is_array($credentialsJson) ? KiyohConnect::normalizeCredentials($credentialsJson) : null;
check(is_array($token) && is_array($credentials), 'token and credentials normalize');
if (is_array($token) && is_array($credentials)) {
    same('access-token-test', $token['accessToken'], 'access token');
    same('refresh-token-test', $token['refreshToken'], 'refresh token');
    same(3600, $token['expiresIn'], 'access token lifetime');
    same('LOC-1', $credentials['locationId'], 'location id');
    same(98, $credentials['tenantId'], 'tenant id');
    same('Example Shop', $credentials['locationName'], 'location name');
    same('invite-key-test', $credentials['inviteApiKey'], 'invite api key');
    same($credentials, KiyohConnect::normalizeCredentials($credentialsJson), 'reading credentials again changes nothing');
}
$drift = is_array($credentialsJson) ? $credentialsJson : [];
$drift['extra'] = 'ignored';
unset($drift['inviteApiKey']);
same(null, KiyohConnect::normalizeCredentials($drift), 'missing inviteApiKey is rejected');
same(null, KiyohConnect::normalizeToken(['access_token' => 'x']), 'token without refresh is rejected');

$pending = KiyohConnect::decodePending(KiyohConnect::encodePending(['state' => 'abc', 'verifier' => $verifier]));
same($verifier, is_array($pending) ? $pending['verifier'] : '', 'pending round trip');
$key = random_bytes(32);
$encrypted = KiyohConnect::encrypt('refresh-token-test', $key);
check(is_string($encrypted) && !str_contains((string) $encrypted, 'refresh-token-test'), 'refresh token ciphertext hides the token');
same('refresh-token-test', KiyohConnect::decrypt((string) $encrypted, $key), 'refresh token decrypts');
same(null, KiyohConnect::decrypt((string) $encrypted, random_bytes(32)), 'wrong key does not decrypt');
same(null, KiyohConnect::decrypt(substr((string) $encrypted, 0, -2) . 'aa', $key), 'tampered ciphertext does not decrypt');

check(KiyohConnect::isSafeAdminReturn('https://shop.example/admin/index.php?controller=AdminModules&configure=kiyoh&token=abc', 'shop.example'), 'admin return on the shop host is allowed');
check(!KiyohConnect::isSafeAdminReturn('https://evil.example/admin/index.php?configure=kiyoh', 'shop.example'), 'admin return on another host is rejected');
check(!KiyohConnect::isSafeAdminReturn("https://shop.example/admin/index.php?configure=kiyoh\n", 'shop.example'), 'newline in the admin return is rejected');
check(!KiyohConnect::isSafeAdminReturn('http://shop.example/admin/index.php?configure=kiyoh', 'shop.example'), 'http admin return is rejected');

$slept = [];
$calls = 0;
$limited = KiyohConnect::withRetry(
    function () use (&$calls) {
        $calls++;

        return ['status' => 429, 'headers' => ['retry-after' => '30'], 'body' => '{"error":"rate_limited","retryAfterSeconds":30}', 'error' => null];
    },
    function ($seconds) use (&$slept) {
        $slept[] = $seconds;
    },
    true,
    2,
    1
);
same(1, $limited['attempts'], 'a long Retry-After is not slept inside the admin request');
same(30, $limited['retryAfter'], 'Retry-After 30 is reported');
same(0, count($slept), 'no sleep when the delay is above the cap');
same('rate_limited', KiyohConnect::safeErrorCode($limited['body']), 'safe error code');

$calls = 0;
$recovered = KiyohConnect::withRetry(
    function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error":"rate_limited","retryAfterSeconds":0}', 'error' => null];
        }

        return ['status' => 200, 'headers' => [], 'body' => '{"ok":true}', 'error' => null];
    },
    function ($seconds) use (&$slept) {
        $slept[] = $seconds;
    },
    true,
    2,
    0
);
same(2, $recovered['attempts'], 'Retry-After 0 is retried immediately');
same(200, $recovered['status'], 'the retry can succeed');

$calls = 0;
$once = KiyohConnect::withRetry(
    function () use (&$calls) {
        $calls++;

        return ['status' => 500, 'headers' => [], 'body' => '', 'error' => null];
    },
    function ($seconds) {
    },
    false,
    2,
    0
);
same(1, $once['attempts'], 'the authorization-code grant is not retried');

$modulePhp = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/kiyoh'));
foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $modulePhp .= (string) file_get_contents($file->getPathname());
}
check(!preg_match('/client_secret\s*=\s*[\'"][^\'"]+[\'"]/', $modulePhp), 'no client secret literal is shipped');
check(!preg_match('/HMAC_KEY\s*,\s*[\'"][^\'"]+[\'"]/', $modulePhp), 'no hmac key literal is shipped');
check(!str_contains($modulePhp, 'test-client-secret') && !str_contains($modulePhp, 'test-hmac-key'), 'test secrets are not in the module');

runConnectHttpContract();

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed, " . $assertions . " assertions\n");
    exit(1);
}

echo $assertions . " assertions passed\n";
exit(0);

function runConnectHttpContract()
{
    $port = 19000 + (getmypid() % 500);
    $log = sys_get_temp_dir() . '/kiyoh-connect-' . getmypid() . '.log';
    $counter = sys_get_temp_dir() . '/kiyoh-connect-counter-' . getmypid() . '.txt';
    @unlink($log);
    @unlink($counter);
    file_put_contents($counter, '0');
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['KIYOH_CONNECT_LOG'] = $log;
    $env['KIYOH_CONNECT_TEST_HMAC'] = 'test-hmac-key';
    $env['KIYOH_CONNECT_TEST_SECRET'] = 'test-client-secret';
    $env['KIYOH_CONNECT_429'] = '1';
    $env['KIYOH_CONNECT_COUNTER'] = $counter;
    $serverErrorLog = sys_get_temp_dir() . '/kiyoh-connect-server-' . getmypid() . '.err';
    @unlink($serverErrorLog);
    $process = proc_open( // preflight: ignore local fixture server, argv array, no shell and no user input
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__FILE__) . '/connect-http-server.php'],
        [
            1 => ['file', sys_get_temp_dir() . '/kiyoh-connect-server-' . getmypid() . '.out', 'w'],
            2 => ['file', $serverErrorLog, 'w'],
        ],
        $pipes,
        null,
        $env
    );
    check(is_resource($process), 'connect test server starts');
    if (!is_resource($process)) {
        return;
    }
    try {
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        check($ready, 'connect test server accepts connections');
        $base = 'http://127.0.0.1:' . $port;
        $issuedAt = time();
        $origin = 'https://shop.example';
        $redirect = 'https://shop.example/module/kiyoh/oauth';
        $body = KiyohConnect::shopRequest('register', $origin, $redirect, $issuedAt, 'test-hmac-key');
        $registered = KiyohConnect::http('POST', $base . '/shops/register', ['Content-Type: application/json', 'Accept: application/json'], json_encode($body), ['127.0.0.1']);
        same(204, $registered['status'], 'shop register accepts a valid signature');
        $bad = $body;
        $bad['signature'] = 'not-the-signature';
        $rejected = KiyohConnect::http('POST', $base . '/shops/register', ['Content-Type: application/json'], json_encode($bad), ['127.0.0.1']);
        same(401, $rejected['status'], 'shop register rejects a bad signature');
        same('invalid_client', KiyohConnect::safeErrorCode($rejected['body']), 'bad signature error code');

        $revokeBody = KiyohConnect::shopRequest('revoke', $origin, $redirect, $issuedAt, 'test-hmac-key');
        $revokedShop = KiyohConnect::http('POST', $base . '/shops/revoke', ['Content-Type: application/json'], json_encode($revokeBody), ['127.0.0.1']);
        same(204, $revokedShop['status'], 'shop revoke accepts the revoke signature');

        $form = KiyohConnect::formBody(KiyohConnect::authorizationCodeForm('one-time-code', 'verifier-value', $redirect, 'test-client-secret'));
        $tokenResponse = KiyohConnect::http('POST', $base . '/token', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $form, ['127.0.0.1']);
        same(200, $tokenResponse['status'], 'token endpoint accepts the client secret');
        $token = KiyohConnect::normalizeToken((array) json_decode($tokenResponse['body'], true));
        check(is_array($token), 'token response normalizes');

        $credentialsResponse = KiyohConnect::withRetry(
            function () use ($base) {
                return KiyohConnect::http('GET', $base . '/integration/credentials', [
                    'Accept: application/json',
                    'Authorization: Bearer access-token-test',
                ], null, ['127.0.0.1']);
            },
            function ($seconds) {
            },
            true,
            2,
            0
        );
        same(200, $credentialsResponse['status'], 'credentials succeed after Retry-After 0');
        check($credentialsResponse['attempts'] >= 2, 'the 429 credentials response was retried');
        $credentials = KiyohConnect::normalizeCredentials((array) json_decode($credentialsResponse['body'], true));
        same('LOC-1', is_array($credentials) ? $credentials['locationId'] : '', 'credentials location id from the fixture');
        same(98, is_array($credentials) ? $credentials['tenantId'] : 0, 'credentials tenant from the fixture');
        same('invite-key-test', is_array($credentials) ? $credentials['inviteApiKey'] : '', 'credentials invite key from the fixture');

        $blocked = KiyohConnect::http('GET', 'https://example.com/integration/credentials', [], null, ['connect.kiyoh.com']);
        same(0, $blocked['status'], 'a non-allowlisted host is not requested');
        same('blocked url', $blocked['error'], 'blocked url does not fall through to curl');

        $entries = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($log)))));
        check(count($entries) >= 4, 'the connect test server saw the flow');
        $first = json_decode($entries[0], true);
        same('/shops/register', is_array($first) ? $first['path'] : '', 'first call registers the shop');
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($log);
        @unlink($counter);
        @unlink($serverErrorLog);
        @unlink(sys_get_temp_dir() . '/kiyoh-connect-server-' . getmypid() . '.out');
    }
}

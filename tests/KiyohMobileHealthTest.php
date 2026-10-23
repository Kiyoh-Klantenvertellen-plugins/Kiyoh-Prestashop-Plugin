<?php
/**
 * Health-report rules. No PrestaShop installation required.
 *
 * php tests/KiyohMobileHealthTest.php
 */

define('_PS_VERSION_', '9.2.0');

if (!class_exists('Module', false)) {
    class Module
    {
        public static function getInstanceByName($name)
        {
            return (object) ['version' => '1.3.0'];
        }
    }
}

require dirname(__DIR__) . '/kiyoh/classes/KiyohMobileHealth.php';
require dirname(__DIR__) . '/kiyoh/classes/KiyohMobileHealthReporter.php';

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

$default = KiyohMobileHealth::parseBaseUrls(null);
same(['https://kiyoh-mobile.com'], $default['urls'], 'empty setting uses the production default');
same([], $default['invalid'], 'default has no invalid urls');
same(
    ['https://kiyoh-mobile.com'],
    KiyohMobileHealth::parseBaseUrls('  ' )['urls'],
    'blank setting uses the production default'
);

$both = KiyohMobileHealth::parseBaseUrls(
    ' https://kiyoh-mobile.com/ , https://kiyohmobileui-staging.up.railway.app/ '
);
same(
    ['https://kiyoh-mobile.com', 'https://kiyohmobileui-staging.up.railway.app'],
    $both['urls'],
    'comma-separated urls are trimmed and de-duplicated without a trailing slash'
);
same([], $both['invalid'], 'both configured urls are valid');

$mixed = KiyohMobileHealth::parseBaseUrls('https://kiyoh-mobile.com, ftp://example.com, https://kiyoh-mobile.com/api');
same(['https://kiyoh-mobile.com'], $mixed['urls'], 'valid urls are kept when others are invalid');
same(['ftp://example.com', 'https://kiyoh-mobile.com/api'], $mixed['invalid'], 'scheme and path urls are rejected');

same('shop.example.com', KiyohMobileHealth::shopId('http://shop.example.com', '', '/'), 'protocol and root uri are removed');
same('b.example.com', KiyohMobileHealth::shopId('a.example.com', 'b.example.com', '/'), 'ssl domain is preferred');
same('shop.example.com/boutique', KiyohMobileHealth::shopId('shop.example.com', '', '/boutique/'), 'virtual uri distinguishes multistore shops');
same('Shop.Example.com', KiyohMobileHealth::shopId('Shop.Example.com', '', ''), 'shop id keeps the domain case');
check(KiyohMobileHealth::shopId('shop example.com', '', '/') === null, 'spaces are not a valid shop id');
check(KiyohMobileHealth::shopId('', '', '/') === null, 'missing domain is skipped');

same('1.3.0', KiyohMobileHealth::normalizePluginVersion(' 1.3.0 '), 'plugin version is trimmed');
same('0', KiyohMobileHealth::normalizePluginVersion(' bad version'), 'invalid plugin version falls back');

$codes = [' SKU-2 ', 'SKU-1', 'SKU-2', '', '   ', 12];
$codes[] = str_repeat('A', 201);
$codes[] = str_repeat('B', 200);
for ($i = 0; $i < 600; $i++) {
    $codes[] = 'P' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
}
$normalized = KiyohMobileHealth::normalizeProductCodes($codes);
same(500, count($normalized), 'product codes are capped at 500');
check(!in_array(str_repeat('A', 201), $normalized, true), 'over-long product codes are dropped');
check(in_array(str_repeat('B', 200), $normalized, true), '200-character product codes are kept');
check(in_array('SKU-1', $normalized, true) && in_array('SKU-2', $normalized, true), 'trimmed codes are kept once');
$sorted = $normalized;
sort($sorted, SORT_STRING);
same($sorted, $normalized, 'product codes are sorted so the digest stays stable');
check(!in_array('P0500', $normalized, true), 'codes past the first 500 are omitted');

$body = KiyohMobileHealth::buildBody(
    'shop.example.com',
    '1.3.0',
    ['status' => 'manual', 'lastOkAt' => 'not-a-date', 'lastError' => '  '],
    ['lastOkAt' => '2026-10-23T07:55:00Z', 'failures24h' => 1000001],
    ['lastOkAt' => null, 'lastError' => str_repeat('e', 600), 'productCodes' => ['SKU-2', 'SKU-1']]
);
same('prestashop', $body['platform'], 'platform is prestashop');
same('shop.example.com', $body['shopId'], 'shop id is copied through');
same('1.3.0', $body['pluginVersion'], 'plugin version is copied through');
same('unknown', $body['connection']['status'], 'connection status only allows ok, error, or unknown');
same(null, $body['connection']['lastOkAt'], 'invalid datetimes become null');
same(null, $body['connection']['lastError'], 'blank errors become null');
same(1000000, $body['invites']['failures24h'], 'failures24h is capped');
same(500, strlen($body['productSync']['lastError']), 'lastError is capped at 500 characters');
same(['SKU-1', 'SKU-2'], $body['productSync']['productCodes'], 'product codes on the payload are normalized');
check(!array_key_exists('webhooks', $body) && !array_key_exists('widget', $body), 'omitted groups are not sent');
check(!array_key_exists('receivedAt', $body), 'receivedAt is left to the server');

$emptySync = KiyohMobileHealth::buildBody('shop.example.com', '1.3.0', [], [], []);
check(!array_key_exists('productCodes', $emptySync['productSync']), 'empty product code lists are omitted');

$digest = KiyohMobileHealth::digest($body);
same($digest, KiyohMobileHealth::digest($body), 'digest is stable');
check(strlen($digest) === 64, 'digest is sha256');

$nowIso = '2026-10-23T08:00:00Z';
$updated = KiyohMobileHealth::applyConnection(
    ['status' => 'unknown', 'lastOkAt' => null, 'lastError' => 'old'],
    'ok',
    'ignored',
    $nowIso
);
same(['status' => 'ok', 'lastOkAt' => $nowIso, 'lastError' => null], $updated, 'a successful check clears the connection error');
$errored = KiyohMobileHealth::applyConnection($updated, 'error', 'HTTP 401', $nowIso);
same('error', $errored['status'], 'an auth failure marks the connection as error');
same($nowIso, $errored['lastOkAt'], 'lastOkAt stays at the previous success');
same('HTTP 401', $errored['lastError'], 'the auth error is stored');
same('unchanged', KiyohMobileHealth::outcomeFromResult(false, 400, 'HTTP 400: bad request'), 'a validation HTTP status does not change connection');
same('error', KiyohMobileHealth::outcomeFromResult(false, 403, 'HTTP 403'), '403 marks the connection as error');
same('error', KiyohMobileHealth::outcomeFromResult(false, null, 'cURL error: timed out'), 'transport failures mark the connection as error');
same('ok', KiyohMobileHealth::outcomeFromResult(true, 200, null), 'success marks the connection as ok');

$previous = date_default_timezone_get();
date_default_timezone_set('Europe/Amsterdam');
same('2026-10-23T06:00:00Z', KiyohMobileHealth::mysqlToIso('2026-10-23 08:00:00'), 'shop datetimes are converted to UTC');
same(null, KiyohMobileHealth::mysqlToIso('0000-00-00 00:00:00'), 'empty mysql datetimes are null');
date_default_timezone_set($previous);

$state = KiyohMobileHealth::decodeState(KiyohMobileHealth::encodeState([
    'connection' => $errored,
    'reports' => [
        'https://kiyoh-mobile.com' => ['digest' => 'abc', 'reportedAt' => 10, 'retryAfter' => 0],
    ],
]));
same('error', $state['connection']['status'], 'encoded state round-trips connection status');
same('abc', $state['reports']['https://kiyoh-mobile.com']['digest'], 'encoded state round-trips the report digest');
same(KiyohMobileHealth::emptyState(), KiyohMobileHealth::decodeState('not-json'), 'corrupt state falls back to empty');

$url = 'https://kiyoh-mobile.com';
$reports = [];
$now = 1_700_000_000;
same([$url], KiyohMobileHealth::urlsDue([$url], $reports, $digest, $now, false), 'the first cron sends');
$reports = KiyohMobileHealth::recordReportResult($reports, $url, $digest, $now, 200, [], '', null);
same([], KiyohMobileHealth::urlsDue([$url], $reports, $digest, $now + 86399, false), 'an unchanged payload is skipped inside 24 hours');
same([$url], KiyohMobileHealth::urlsDue([$url], $reports, $digest, $now + 86400, false), 'an unchanged payload is resent at 24 hours');
same([$url], KiyohMobileHealth::urlsDue([$url], $reports, 'changed', $now + 10, false), 'a changed payload is sent immediately');
same([$url], KiyohMobileHealth::urlsDue([$url], $reports, $digest, $now + 10, true), 'config save and invite events force a send');

$limited = KiyohMobileHealth::recordReportResult($reports, $url, 'changed', $now, 429, ['retry-after' => '12'], '', null);
same($digest, $limited[$url]['digest'], '429 does not count as a successful report');
same($now + 12, $limited[$url]['retryAfter'], 'Retry-After seconds are stored');
same([], KiyohMobileHealth::urlsDue([$url], $limited, 'changed', $now + 11, true), '429 blocks the next send, including forced events');
same([$url], KiyohMobileHealth::urlsDue([$url], $limited, 'changed', $now + 12, true), 'the send resumes when Retry-After has elapsed');

$fromBody = KiyohMobileHealth::recordReportResult(
    $reports,
    $url,
    $digest,
    $now,
    429,
    [],
    '{"error":"rate_limited","retryAfterSeconds":15}',
    null
);
same($now + 15, $fromBody[$url]['retryAfter'], '429 reads retryAfterSeconds from the body');
same(3600, KiyohMobileHealth::retryAfterSeconds(['retry-after' => '99999'], ''), 'Retry-After is capped at one hour');
same(1, KiyohMobileHealth::retryAfterSeconds(['retry-after' => '0'], ''), 'Retry-After of zero still waits a second');
same(60, KiyohMobileHealth::retryAfterSeconds([], 'not-json'), '429 without a delay waits 60 seconds');

$serverError = KiyohMobileHealth::recordReportResult($reports, $url, 'changed', $now, 500, [], '', null);
same($digest, $serverError[$url]['digest'], 'HTTP 500 does not count as a successful report');
same(0, $serverError[$url]['retryAfter'], 'HTTP 500 does not invent a rate-limit delay');

$transport = KiyohMobileHealth::recordReportResult($reports, $url, 'changed', $now, 0, [], '', 'timed out');
same($now + 60, $transport[$url]['retryAfter'], 'a transport failure backs off for 60 seconds');
same($digest, $transport[$url]['digest'], 'a transport failure keeps the last accepted digest');

$pruned = KiyohMobileHealth::pruneReports($transport, ['https://other.example']);
same([], $pruned, 'report state for removed urls is dropped');

$headerLines = ["HTTP/1.1 429 Too Many Requests\r\n", "Retry-After: 12\r\n", "\r\n"];
same(['retry-after' => '12'], KiyohMobileHealth::parseHeaders($headerLines), 'response headers are parsed');

runHttpContract();

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed, " . $assertions . " assertions\n");
    exit(1);
}

echo $assertions . " assertions passed\n";
exit(0);

function runHttpContract()
{
    $port = 18110 + (getmypid() % 500);
    $log = sys_get_temp_dir() . '/kiyoh-health-' . getmypid() . '.log';
    @unlink($log);
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['KIYOH_HEALTH_LOG'] = $log;
    $serverErrorLog = sys_get_temp_dir() . '/kiyoh-health-server-' . getmypid() . '.err';
    @unlink($serverErrorLog);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__FILE__) . '/health-http-server.php'],
        [
            1 => ['file', sys_get_temp_dir() . '/kiyoh-health-server-' . getmypid() . '.out', 'w'],
            2 => ['file', $serverErrorLog, 'w'],
        ],
        $pipes,
        null,
        $env
    );
    check(is_resource($process), 'health test server starts');
    if (!is_resource($process)) {
        return;
    }

    $ready = false;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'health test server accepts connections');

    try {
        if (!$ready) {
            return;
        }
        $base = 'http://127.0.0.1:' . $port;
        $payload = json_encode(KiyohMobileHealth::buildBody(
            'shop.example.com',
            '1.3.0',
            ['status' => 'ok', 'lastOkAt' => '2026-10-23T08:00:00Z', 'lastError' => null],
            ['lastOkAt' => null, 'failures24h' => 0],
            ['lastOkAt' => null, 'lastError' => null, 'productCodes' => ['SKU-1']]
        ));
        $method = new ReflectionMethod(KiyohMobileHealthReporter::class, 'postJson');
        $method->setAccessible(true);

        $accepted = $method->invoke(null, [$base], 'location-9', 'secret-token', $payload);
        same(1, count($accepted), 'one url produces one response');
        same(200, $accepted[0]['status'], 'health endpoint success is HTTP 200');
        same(null, $accepted[0]['error'], 'a 200 response is not a transport error');

        $limited = $method->invoke(null, [$base, $base], 'limited', 'secret-token', $payload);
        same(2, count($limited), 'each configured url is called');
        same(429, $limited[0]['status'], '429 is returned to the reporter');
        same('12', $limited[0]['headers']['retry-after'] ?? null, 'Retry-After is visible on the 429 response');
        $recorded = KiyohMobileHealth::recordReportResult(
            [],
            $base,
            'digest',
            1_700_000_000,
            $limited[0]['status'],
            $limited[0]['headers'],
            $limited[0]['body'],
            $limited[0]['error']
        );
        same(1_700_000_012, $recorded[$base]['retryAfter'], 'the live 429 response sets the backoff');

        $entries = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($log)))));
        check(count($entries) >= 3, 'the test server saw every health post');
        $first = json_decode($entries[0], true);
        check(is_array($first), 'the recorded request is json');
        if (!is_array($first)) {
            return;
        }
        same('POST', $first['method'], 'health reports are POST');
        same('/api/integrations/health', $first['uri'], 'health reports use /api/integrations/health');
        $headers = [];
        foreach ($first['headers'] as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }
        same('secret-token', $headers['x-publication-api-token'] ?? null, 'X-Publication-Api-Token is the saved API key');
        same('location-9', $headers['x-location-id'] ?? null, 'X-Location-Id is the saved location id');
        check(isset($headers['content-type']) && stripos($headers['content-type'], 'application/json') !== false, 'Content-Type is JSON');
        $decoded = json_decode($first['body'], true);
        check(is_array($decoded), 'the health body is JSON');
        if (is_array($decoded)) {
            same('prestashop', $decoded['platform'], 'the posted platform is prestashop');
            same('shop.example.com', $decoded['shopId'], 'the posted shop id is the shop domain');
            same('1.3.0', $decoded['pluginVersion'], 'the posted plugin version is the module version');
            same(['SKU-1'], $decoded['productSync']['productCodes'], 'product codes are posted with product sync');
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($log);
        @unlink($serverErrorLog);
        @unlink(sys_get_temp_dir() . '/kiyoh-health-server-' . getmypid() . '.out');
    }
}

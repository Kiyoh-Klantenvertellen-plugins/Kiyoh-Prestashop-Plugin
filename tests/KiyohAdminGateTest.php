<?php
/**
 * Admin token rules for the Connect button. No PrestaShop installation.
 *
 * php tests/KiyohAdminGateTest.php
 *
 * The failing case from KP-95: the configure page is open with Symfony
 * `_token`, the popup posts `token` empty or as a different value, and
 * the module used to reject it.
 */

define('_PS_VERSION_', '9.2.0');

require dirname(__DIR__) . '/kiyoh/classes/KiyohAdminGate.php';

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

$symfony = 'symfony-csrf-token-value';
$legacy = '0123456789abcdef0123456789abcdef';
$page = '/admin123/index.php/improve/modules/manage/action/configure/kiyoh?_token=' . $symfony;

same($symfony, KiyohAdminGate::pageToken(['_token' => $symfony, 'token' => $legacy]), 'the page token prefers _token');
same($legacy, KiyohAdminGate::pageToken(['token' => $legacy]), 'the page token falls back to the legacy token');

$provided = KiyohAdminGate::providedTokens(
    ['_token' => $symfony, 'configure' => 'kiyoh'],
    ['token' => '', 'kiyoh_connect_start' => '1']
);
same([$symfony], $provided, 'query _token counts when the posted token is empty');
check(
    KiyohAdminGate::accepts($provided, [$symfony]),
    'the Symfony _token that opened the configure page is accepted'
);
check(
    !KiyohAdminGate::accepts($provided, [$legacy]),
    'a legacy hash does not match the Symfony token'
);

$both = KiyohAdminGate::providedTokens(
    ['_token' => $symfony],
    ['token' => $legacy]
);
check(
    KiyohAdminGate::accepts($both, [$symfony, $legacy]),
    'either the page _token or the legacy token is enough'
);
check(
    !KiyohAdminGate::accepts([], [$symfony]),
    'a missing token is rejected'
);
check(
    !KiyohAdminGate::accepts(['short'], [$symfony]),
    'a short token is rejected'
);
check(
    !KiyohAdminGate::accepts(["bad\ntoken-value-padding"], [$symfony]),
    'a token with a newline is rejected'
);

$cookieKey = 'cookie-key';
$expectedLegacy = md5($cookieKey . 'AdminModules' . '42' . '7');
same(
    $expectedLegacy,
    KiyohAdminGate::legacyToken('AdminModules', 42, 7, $cookieKey),
    'legacy token is md5(cookie key, tab, tab id, employee id)'
);
same('', KiyohAdminGate::legacyToken('AdminModules', 42, 0, $cookieKey), 'employee 0 has no legacy token');
check(
    KiyohAdminGate::accepts([$expectedLegacy], [KiyohAdminGate::legacyToken('AdminModules', 42, 7, $cookieKey)]),
    'the legacy AdminModules token is accepted for that employee'
);

same(
    'shop.example',
    KiyohAdminGate::publicHost('shop.example, internal.invalid', '127.0.0.1'),
    'X-Forwarded-Host uses the first host'
);
same(
    'shop.example',
    KiyohAdminGate::publicHost('not a host', 'shop.example'),
    'an unusable forwarded host falls back to HTTP_HOST'
);
same('', KiyohAdminGate::publicHost('bad host', 'also bad'), 'no host when both values are unusable');

check(
    KiyohAdminGate::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https,http']),
    'a Cloudflare tunnel proto list that starts with https is HTTPS'
);
check(
    KiyohAdminGate::requestIsHttps(['HTTPS' => 'on']),
    'HTTPS=on is HTTPS'
);
check(
    !KiyohAdminGate::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'http']),
    'forwarded http stays http'
);

$action = KiyohAdminGate::configureAction(
    $page,
    'shop.example, internal.invalid',
    true,
    'kiyoh',
    'index.php?controller=AdminModules&configure=kiyoh'
);
same(
    'https://shop.example' . $page . '&configure=kiyoh',
    $action,
    'the Connect action keeps the configure URL and its _token'
);
check(strpos($action, '_token=' . $symfony) !== false, 'the Symfony _token is still on the action');

$stripped = KiyohAdminGate::configureAction(
    '/admin123/index.php/improve/modules/manage/action/configure/kiyoh',
    'shop.example',
    true,
    'kiyoh',
    'index.php?controller=AdminModules&configure=kiyoh'
);
same(
    'https://shop.example/admin123/index.php/improve/modules/manage/action/configure/kiyoh?configure=kiyoh',
    $stripped,
    'a Symfony path with no query still has a query so the token can be appended'
);

same(
    'index.php?controller=AdminModules&configure=kiyoh',
    KiyohAdminGate::configureAction('/admin123/index.php', 'shop.example', true, 'kiyoh', 'index.php?controller=AdminModules&configure=kiyoh'),
    'a URL that is not the configure page keeps the legacy index'
);
check(
    !KiyohAdminGate::isConfigureUri('/admin/index.php/improve/modules/manage/action/configure/kiyoh-other', 'kiyoh'),
    'a different module path is not this configure page'
);
check(
    KiyohAdminGate::isConfigureUri('/admin/index.php?controller=AdminModules&token=0123456789abcdef0123456789abcdef&configure=kiyoh', 'kiyoh'),
    'the legacy configure query is this configure page'
);

$withTokens = KiyohAdminGate::withAdminTokens($action, $legacy, $symfony);
check(strpos($withTokens, '_token=' . $symfony) !== false, 'an existing _token is kept');
check(strpos($withTokens, 'token=' . $legacy) !== false, 'the legacy token is added beside _token');
$parsed = parse_url($withTokens);
parse_str((string) ($parsed['query'] ?? ''), $query);
same($symfony, $query['_token'] ?? '', 'withAdminTokens does not replace the page _token');
same($legacy, $query['token'] ?? '', 'withAdminTokens adds token when the page only had _token');

$onlyLegacy = KiyohAdminGate::withAdminTokens(
    'index.php?controller=AdminModules&configure=kiyoh&token=' . $legacy,
    'ffffffffffffffffffffffffffffffff',
    $symfony
);
parse_str((string) (parse_url($onlyLegacy, PHP_URL_QUERY) ?? ''), $legacyQuery);
same($legacy, $legacyQuery['token'] ?? '', 'an existing legacy token is not replaced');
same($symfony, $legacyQuery['_token'] ?? '', 'the Symfony token is added for PrestaShop 9');

same('', KiyohAdminGate::withAdminTokens("https://shop.example/admin\n", $legacy, $symfony), 'a URL with a newline is rejected');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed, " . $assertions . " assertions\n");
    exit(1);
}

fwrite(STDOUT, $assertions . " assertions passed\n");
exit(0);

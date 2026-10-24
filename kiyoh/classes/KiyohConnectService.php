<?php
/**
 * PrestaShop adapter for the Kiyoh-Connect per-shop sign-in.
 *
 * The HMAC key and client secret are read from the server environment or
 * from constants defined outside this module. They are never written to
 * configuration, logs, or the module zip.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohConnect.php';

/**
 * Stores Connect state per shop and calls the two Connect issuers.
 */
class KiyohConnectService
{
    public const HMAC_KEY_NAME = 'KIYOH_CONNECT_HMAC_KEY';

    public const CLIENT_SECRET_NAME = 'KIYOH_CONNECT_CLIENT_SECRET';

    public const ENC_KEY = 'KIYOH_CONNECT_ENC_KEY';

    public const PENDING_KEY = 'KIYOH_CONNECT_PENDING';

    public const REFRESH_KEY = 'KIYOH_CONNECT_REFRESH';

    public const LINKED_KEY = 'KIYOH_CONNECT_LINKED';

    public const LOCATION_NAME_KEY = 'KIYOH_CONNECT_LOCATION_NAME';

    public const TENANT_KEY = 'KIYOH_CONNECT_TENANT';

    public const ORIGIN_KEY = 'KIYOH_CONNECT_ORIGIN';

    public const REDIRECT_KEY = 'KIYOH_CONNECT_REDIRECT';

    public const ISSUER_KEY = 'KIYOH_CONNECT_ISSUER';

    public const FLASH_KEY = 'KIYOH_CONNECT_FLASH';

    /**
     * @return string[]
     */
    public static function configurationKeys(): array
    {
        return [
            self::ENC_KEY,
            self::PENDING_KEY,
            self::REFRESH_KEY,
            self::LINKED_KEY,
            self::LOCATION_NAME_KEY,
            self::TENANT_KEY,
            self::ORIGIN_KEY,
            self::REDIRECT_KEY,
            self::ISSUER_KEY,
            self::FLASH_KEY,
        ];
    }

    public static function secretsConfigured(): bool
    {
        return self::hmacKey() !== '' && self::clientSecret() !== '';
    }

    public static function ensureEncryptionKey(): bool
    {
        $existing = Configuration::getGlobalValue(self::ENC_KEY);
        if (is_string($existing) && self::decodeKey($existing) !== null) {
            return true;
        }
        $key = random_bytes(32);

        return (bool) Configuration::updateGlobalValue(self::ENC_KEY, KiyohConnect::base64Url($key));
    }

    /**
     * @return array{configured: bool, linked: bool, hasSession: bool, locationName: string, locationId: string, tenantId: string, platform: string, origin: string, redirect: string}
     */
    public static function status(): array
    {
        $shop = self::currentShop();
        $empty = [
            'configured' => self::secretsConfigured(),
            'linked' => false,
            'hasSession' => false,
            'locationName' => '',
            'locationId' => '',
            'tenantId' => '',
            'platform' => '',
            'origin' => '',
            'redirect' => self::returnAddressPreview(),
        ];
        if ($shop === null) {
            return $empty;
        }
        $empty['linked'] = self::configString(self::LINKED_KEY, $shop) === '1';
        $empty['hasSession'] = $empty['linked'] || self::configString(self::REFRESH_KEY, $shop) !== '';
        $empty['locationName'] = self::configString(self::LOCATION_NAME_KEY, $shop);
        $empty['locationId'] = self::configString('KIYOH_LOCATION_ID', $shop);
        $empty['tenantId'] = self::configString(self::TENANT_KEY, $shop);
        $empty['platform'] = self::configString('KIYOH_PLATFORM', $shop);
        $empty['origin'] = self::configString(self::ORIGIN_KEY, $shop);
        $storedRedirect = self::configString(self::REDIRECT_KEY, $shop);
        if ($storedRedirect !== '') {
            $empty['redirect'] = $storedRedirect;
        }

        return $empty;
    }

    /**
     * Return address this shop would register. Empty when the domain cannot be used.
     */
    public static function returnAddressPreview(): string
    {
        $shop = self::currentShop();
        if ($shop === null) {
            return '';
        }
        $redirect = KiyohConnect::redirectUri($shop['host'], $shop['base']);

        return $redirect === null ? '' : $redirect;
    }

    /**
     * @return array{ok: bool, code: string, authorizeUrl?: string, detail?: string, retryAfter?: int}
     */
    public static function begin(string $platform, string $adminReturn): array
    {
        $issuer = KiyohConnect::issuerForPlatform($platform);
        $shop = self::currentShop();
        if ($issuer === null) {
            return ['ok' => false, 'code' => 'platform'];
        }
        if ($shop === null) {
            return ['ok' => false, 'code' => 'shop_context'];
        }
        $origin = KiyohConnect::shopOrigin($shop['host']);
        $redirect = KiyohConnect::redirectUri($shop['host'], $shop['base']);
        if ($origin === null || $redirect === null) {
            return ['ok' => false, 'code' => 'https_required'];
        }
        if (!KiyohConnect::isSafeAdminReturn($adminReturn, $shop['host'])) {
            // Still sign in. The callback will not redirect off this shop.
            $adminReturn = '';
        }
        $hmac = self::hmacKey();
        if ($hmac === '' || self::clientSecret() === '') {
            return ['ok' => false, 'code' => 'secret_missing'];
        }
        if (!self::ensureEncryptionKey()) {
            return ['ok' => false, 'code' => 'encryption_failed'];
        }

        $issuedAt = time();
        $body = KiyohConnect::shopRequest('register', $origin, $redirect, $issuedAt, $hmac);
        $registered = self::postJson($issuer . '/shops/register', $body, $issuer, true);
        if (!$registered['ok'] && $registered['status'] !== 409) {
            return self::failure('register_failed', $registered);
        }

        $verifier = KiyohConnect::pkceVerifier();
        $state = KiyohConnect::randomState();
        $key = self::encryptionKey();
        if ($key === null) {
            return ['ok' => false, 'code' => 'encryption_failed'];
        }
        $pending = [
            'v' => 1,
            'state' => $state,
            'verifier' => $verifier,
            'redirect' => $redirect,
            'origin' => $origin,
            'issuer' => $issuer,
            'platform' => strtolower(trim($platform)),
            'exp' => $issuedAt + KiyohConnect::PENDING_TTL_SECONDS,
            'shopId' => $shop['id_shop'],
            'adminReturn' => $adminReturn,
        ];
        $encryptedPending = KiyohConnect::encrypt(KiyohConnect::encodePending($pending), $key);
        if ($encryptedPending === null) {
            return ['ok' => false, 'code' => 'encryption_failed'];
        }
        self::put($shop, self::PENDING_KEY, $encryptedPending);
        self::put($shop, self::ORIGIN_KEY, $origin);
        self::put($shop, self::REDIRECT_KEY, $redirect);
        self::put($shop, self::ISSUER_KEY, $issuer);

        return [
            'ok' => true,
            'code' => 'authorize',
            'authorizeUrl' => KiyohConnect::authorizeUrl(
                $issuer,
                $redirect,
                $state,
                KiyohConnect::pkceChallenge($verifier)
            ),
        ];
    }

    /**
     * @return array{ok: bool, code: string, adminReturn: string, detail?: string, retryAfter?: int}
     */
    public static function complete(string $code, string $state, string $oauthError): array
    {
        $shop = self::currentShop();
        $failed = ['ok' => false, 'code' => 'no_pending', 'adminReturn' => ''];
        if ($shop === null) {
            return ['ok' => false, 'code' => 'shop_context', 'adminReturn' => ''];
        }
        $pending = self::readPending($shop);
        if ($pending === null) {
            return $failed;
        }
        $expectedState = isset($pending['state']) && is_string($pending['state']) ? $pending['state'] : '';
        $adminReturn = isset($pending['adminReturn']) && is_string($pending['adminReturn']) ? $pending['adminReturn'] : '';
        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            return ['ok' => false, 'code' => 'state_mismatch', 'adminReturn' => ''];
        }
        self::put($shop, self::PENDING_KEY, '');
        if (!isset($pending['exp']) || (int) $pending['exp'] < time()) {
            return ['ok' => false, 'code' => 'state_expired', 'adminReturn' => $adminReturn];
        }
        if ($oauthError !== '') {
            $codeName = $oauthError === 'access_denied' ? 'access_denied' : 'token_failed';
            self::flash($shop, $codeName, null);

            return ['ok' => false, 'code' => $codeName, 'adminReturn' => $adminReturn];
        }

        $issuer = isset($pending['issuer']) && is_string($pending['issuer']) ? $pending['issuer'] : '';
        $redirect = isset($pending['redirect']) && is_string($pending['redirect']) ? $pending['redirect'] : '';
        $verifier = isset($pending['verifier']) && is_string($pending['verifier']) ? $pending['verifier'] : '';
        $platform = isset($pending['platform']) && is_string($pending['platform']) ? $pending['platform'] : '';
        $secret = self::clientSecret();
        if ($issuer === '' || $redirect === '' || $verifier === '' || $secret === '' || $code === '') {
            self::flash($shop, 'token_failed', null);

            return ['ok' => false, 'code' => 'token_failed', 'adminReturn' => $adminReturn];
        }

        $form = KiyohConnect::authorizationCodeForm($code, $verifier, $redirect, $secret);
        $tokenResponse = self::postForm($issuer . '/token', $form, $issuer, false);
        $token = self::tokenFromResponse($tokenResponse);
        if ($token === null) {
            $failure = self::failure('token_failed', $tokenResponse);
            self::flash($shop, $failure['code'], $failure['retryAfter'] ?? null);

            return ['ok' => false, 'code' => $failure['code'], 'adminReturn' => $adminReturn, 'retryAfter' => $failure['retryAfter'] ?? null];
        }

        $credentials = self::fetchCredentials($issuer, $token['accessToken'], $token['refreshToken'], $secret);
        if (!$credentials['ok']) {
            if (isset($credentials['refreshToken'])) {
                self::storeRefresh($shop, (string) $credentials['refreshToken']);
            }
            self::flash($shop, $credentials['code'], $credentials['retryAfter'] ?? null);

            return [
                'ok' => false,
                'code' => $credentials['code'],
                'adminReturn' => $adminReturn,
                'retryAfter' => $credentials['retryAfter'] ?? null,
            ];
        }

        $expectedTenant = KiyohConnect::tenantForPlatform($platform);
        if ($expectedTenant === null || $credentials['tenantId'] !== $expectedTenant) {
            self::revokeToken($issuer, $token['refreshToken'], $secret, 'refresh_token');
            self::flash($shop, 'tenant_mismatch', null);

            return ['ok' => false, 'code' => 'tenant_mismatch', 'adminReturn' => $adminReturn];
        }
        if (!self::storeRefresh($shop, $credentials['refreshToken'])) {
            self::revokeToken($issuer, $credentials['refreshToken'], $secret, 'refresh_token');
            self::flash($shop, 'encryption_failed', null);

            return ['ok' => false, 'code' => 'encryption_failed', 'adminReturn' => $adminReturn];
        }

        self::put($shop, 'KIYOH_LOCATION_ID', $credentials['locationId']);
        self::put($shop, 'KIYOH_API_KEY', $credentials['inviteApiKey']);
        self::put($shop, 'KIYOH_PLATFORM', $platform);
        self::put($shop, 'KIYOH_ENABLED', '1');
        self::put($shop, self::LINKED_KEY, '1');
        self::put($shop, self::LOCATION_NAME_KEY, $credentials['locationName']);
        self::put($shop, self::TENANT_KEY, (string) $credentials['tenantId']);
        self::flash($shop, 'connected', null);
        self::reportHealth();

        return ['ok' => true, 'code' => 'connected', 'adminReturn' => $adminReturn];
    }

    /**
     * @return array{ok: bool, code: string, detail?: string, retryAfter?: int}
     */
    public static function disconnect(): array
    {
        $shop = self::currentShop();
        if ($shop === null) {
            return ['ok' => false, 'code' => 'shop_context'];
        }
        $secret = self::clientSecret();
        if ($secret === '') {
            return ['ok' => false, 'code' => 'secret_missing'];
        }
        $issuer = self::configString(self::ISSUER_KEY, $shop);
        $refresh = self::readRefresh($shop);
        if ($issuer !== '' && $refresh !== null) {
            $revoked = self::revokeToken($issuer, $refresh, $secret, 'refresh_token');
            if (!$revoked['ok'] && !in_array($revoked['status'], [200, 204, 400, 401], true)) {
                return self::failure('revoke_failed', $revoked);
            }
        }
        $linked = self::configString(self::LINKED_KEY, $shop) === '1';
        self::clearSession($shop, $linked);

        return ['ok' => true, 'code' => 'disconnected'];
    }

    /**
     * Best-effort remote revoke, then the caller deletes local configuration.
     */
    public static function revokeBeforeUninstall(): void
    {
        try {
            $shops = Shop::getShops(true);
        } catch (Throwable $e) {
            self::log('uninstall shop list failed');

            return;
        }
        if (!is_array($shops)) {
            return;
        }
        foreach ($shops as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shop = [
                'id_shop' => (int) ($row['id_shop'] ?? 0),
                'id_shop_group' => (int) ($row['id_shop_group'] ?? 0),
            ];
            if ($shop['id_shop'] <= 0) {
                continue;
            }
            try {
                self::revokeStoredShop($shop);
            } catch (Throwable $e) {
                self::log('uninstall revoke failed for shop ' . $shop['id_shop']);
            }
        }
    }

    /**
     * @return array{code: string, retryAfter: int|null}
     */
    public static function pullFlash(): array
    {
        $shop = self::currentShop();
        if ($shop === null) {
            return ['code' => '', 'retryAfter' => null];
        }
        $raw = self::configString(self::FLASH_KEY, $shop);
        self::put($shop, self::FLASH_KEY, '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['code']) || !is_string($decoded['code'])) {
            return ['code' => '', 'retryAfter' => null];
        }

        return [
            'code' => $decoded['code'],
            'retryAfter' => isset($decoded['retryAfter']) && is_numeric($decoded['retryAfter']) ? (int) $decoded['retryAfter'] : null,
        ];
    }

    public static function markManualCredentials(): void
    {
        $shop = self::currentShop();
        if ($shop === null) {
            return;
        }
        self::put($shop, self::LINKED_KEY, '0');
    }

    /**
     * Environment wins over a constant defined outside the module.
     */
    public static function readExternalSecret(string $name): string
    {
        $env = getenv($name);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function revokeStoredShop(array $shop): void
    {
        $secret = self::clientSecret();
        $hmac = self::hmacKey();
        $issuer = self::configString(self::ISSUER_KEY, $shop);
        $refresh = self::readRefresh($shop);
        if ($secret !== '' && $issuer !== '' && $refresh !== null) {
            self::revokeToken($issuer, $refresh, $secret, 'refresh_token');
        }
        $origin = self::configString(self::ORIGIN_KEY, $shop);
        $redirect = self::configString(self::REDIRECT_KEY, $shop);
        if ($hmac === '' || $issuer === '' || $origin === '' || $redirect === '') {
            return;
        }
        $body = KiyohConnect::shopRequest('revoke', $origin, $redirect, time(), $hmac);
        self::postJson($issuer . '/shops/revoke', $body, $issuer, true);
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function clearSession(array $shop, bool $clearCopiedCredentials): void
    {
        self::put($shop, self::REFRESH_KEY, '');
        self::put($shop, self::PENDING_KEY, '');
        self::put($shop, self::LINKED_KEY, '0');
        self::put($shop, self::LOCATION_NAME_KEY, '');
        self::put($shop, self::TENANT_KEY, '');
        if ($clearCopiedCredentials) {
            self::put($shop, 'KIYOH_LOCATION_ID', '');
            self::put($shop, 'KIYOH_API_KEY', '');
        }
    }

    /**
     * @return array{ok: bool, code: string, locationId?: string, tenantId?: int, locationName?: string, inviteApiKey?: string, refreshToken: string, retryAfter?: int}
     */
    private static function fetchCredentials(string $issuer, string $accessToken, string $refreshToken, string $secret): array
    {
        $response = self::getBearer($issuer . '/integration/credentials', $accessToken, $issuer, true);
        if ($response['status'] === 401) {
            $refreshed = self::postForm($issuer . '/token', KiyohConnect::refreshForm($refreshToken, $secret), $issuer, true);
            $token = self::tokenFromResponse($refreshed);
            if ($token === null) {
                $failure = self::failure('credentials_failed', $response);

                return [
                    'ok' => false,
                    'code' => $failure['code'],
                    'refreshToken' => $refreshToken,
                    'retryAfter' => $failure['retryAfter'] ?? null,
                ];
            }
            $refreshToken = $token['refreshToken'];
            $response = self::getBearer($issuer . '/integration/credentials', $token['accessToken'], $issuer, true);
        }
        $json = json_decode($response['body'], true);
        $credentials = is_array($json) ? KiyohConnect::normalizeCredentials($json) : null;
        if (!$response['ok'] || $credentials === null) {
            $failure = self::failure('credentials_failed', $response);

            return [
                'ok' => false,
                'code' => $failure['code'],
                'refreshToken' => $refreshToken,
                'retryAfter' => $failure['retryAfter'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'code' => 'connected',
            'locationId' => $credentials['locationId'],
            'tenantId' => $credentials['tenantId'],
            'locationName' => $credentials['locationName'],
            'inviteApiKey' => $credentials['inviteApiKey'],
            'refreshToken' => $refreshToken,
        ];
    }

    /**
     * @param array{status: int, body: string, error: string|null, ok: bool, retryAfter: int|null} $response
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}|null
     */
    private static function tokenFromResponse(array $response): ?array
    {
        if (!$response['ok']) {
            return null;
        }
        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            return null;
        }

        return KiyohConnect::normalizeToken($json);
    }

    /**
     * @param array<string, string> $fields
     * @return array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null}
     */
    private static function postForm(string $url, array $fields, string $issuer, bool $allowRetry): array
    {
        $body = KiyohConnect::formBody($fields);

        return self::call('POST', $url, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: ' . self::userAgent(),
        ], $body, $issuer, $allowRetry);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null}
     */
    private static function postJson(string $url, array $payload, string $issuer, bool $allowRetry): array
    {
        $json = json_encode($payload);
        if (!is_string($json)) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'encode failed', 'retryAfter' => null];
        }

        return self::call('POST', $url, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . self::userAgent(),
        ], $json, $issuer, $allowRetry);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null}
     */
    private static function getBearer(string $url, string $accessToken, string $issuer, bool $allowRetry): array
    {
        if (KiyohConnect::containsControl($accessToken) || str_contains($accessToken, ' ')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'bad token', 'retryAfter' => null];
        }

        return self::call('GET', $url, [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: ' . self::userAgent(),
        ], null, $issuer, $allowRetry);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null}
     */
    private static function revokeToken(string $issuer, string $token, string $secret, string $hint): array
    {
        return self::postForm(
            $issuer . '/revoke',
            KiyohConnect::revokeTokenForm($token, $secret, $hint),
            $issuer,
            true
        );
    }

    /**
     * @param string[] $headers
     * @return array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null}
     */
    private static function call(string $method, string $url, array $headers, ?string $body, string $issuer, bool $allowRetry): array
    {
        $host = strtolower((string) parse_url($issuer, PHP_URL_HOST));
        $response = KiyohConnect::withRetry(
            function () use ($method, $url, $headers, $body, $host) {
                return KiyohConnect::http($method, $url, $headers, $body, [$host]);
            },
            static function (int $seconds): void {
                sleep($seconds);
            },
            $allowRetry,
            KiyohConnect::MAX_RETRY_SLEEP_SECONDS,
            random_int(0, 1000) / 1000
        );
        $status = $response['status'];
        $ok = $response['error'] === null && $status >= 200 && $status < 300;
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!$ok) {
            self::log($method . ' ' . $path . ' status ' . $status);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $response['body'],
            'error' => $response['error'],
            'retryAfter' => $response['retryAfter'],
        ];
    }

    /**
     * @param array{ok: bool, status: int, body: string, error: string|null, retryAfter: int|null} $response
     * @return array{ok: bool, code: string, detail?: string, retryAfter?: int}
     */
    private static function failure(string $code, array $response): array
    {
        if ($response['status'] === 429 || ($response['retryAfter'] !== null && $response['retryAfter'] > 0 && !$response['ok'])) {
            $retryCode = $response['status'] === 429 ? 'rate_limited' : $code;

            return [
                'ok' => false,
                'code' => $response['status'] === 429 ? 'rate_limited' : $retryCode,
                'retryAfter' => $response['retryAfter'] ?? null,
            ];
        }
        if ($response['error'] !== null) {
            return ['ok' => false, 'code' => 'network'];
        }
        $detail = KiyohConnect::safeErrorCode($response['body']);
        $result = ['ok' => false, 'code' => $code];
        if ($detail !== null) {
            $result['detail'] = $detail;
        }

        return $result;
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function storeRefresh(array $shop, string $refreshToken): bool
    {
        $key = self::encryptionKey();
        if ($key === null) {
            return false;
        }
        $encrypted = KiyohConnect::encrypt($refreshToken, $key);
        if ($encrypted === null) {
            return false;
        }
        self::put($shop, self::REFRESH_KEY, $encrypted);

        return true;
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     * @return array<string, mixed>|null
     */
    private static function readPending(array $shop): ?array
    {
        $key = self::encryptionKey();
        $stored = self::configString(self::PENDING_KEY, $shop);
        if ($key === null || $stored === '') {
            return null;
        }
        $encoded = KiyohConnect::decrypt($stored, $key);
        if ($encoded === null) {
            return null;
        }

        return KiyohConnect::decodePending($encoded);
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function readRefresh(array $shop): ?string
    {
        $key = self::encryptionKey();
        $stored = self::configString(self::REFRESH_KEY, $shop);
        if ($key === null || $stored === '') {
            return null;
        }

        return KiyohConnect::decrypt($stored, $key);
    }

    private static function encryptionKey(): ?string
    {
        $stored = Configuration::getGlobalValue(self::ENC_KEY);

        return is_string($stored) ? self::decodeKey($stored) : null;
    }

    private static function decodeKey(string $stored): ?string
    {
        $remainder = strlen($stored) % 4;
        if ($remainder > 0) {
            $stored .= str_repeat('=', 4 - $remainder);
        }
        $key = base64_decode(strtr($stored, '-_', '+/'), true);
        if (!is_string($key) || strlen($key) !== 32) {
            return null;
        }

        return $key;
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function flash(array $shop, string $code, ?int $retryAfter): void
    {
        $payload = ['code' => $code];
        if ($retryAfter !== null) {
            $payload['retryAfter'] = $retryAfter;
        }
        $json = json_encode($payload);
        self::put($shop, self::FLASH_KEY, is_string($json) ? $json : '');
    }

    private static function reportHealth(): void
    {
        try {
            require_once dirname(__FILE__) . '/KiyohMobileHealthReporter.php';
            KiyohMobileHealthReporter::noteConfigurationSaved(true);
        } catch (Throwable $e) {
            self::log('health after connect failed');
        }
    }

    /**
     * @return array{id_shop: int, id_shop_group: int, host: string, base: string}|null
     */
    private static function currentShop(): ?array
    {
        try {
            if (class_exists('Shop') && Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
                return null;
            }
            $context = Context::getContext();
            if (!$context || !isset($context->shop) || !$context->shop) {
                return null;
            }
            $shop = $context->shop;
            $host = '';
            if (isset($shop->domain_ssl) && is_string($shop->domain_ssl) && trim($shop->domain_ssl) !== '') {
                $host = trim($shop->domain_ssl);
            } elseif (isset($shop->domain) && is_string($shop->domain)) {
                $host = trim($shop->domain);
            }
            $physical = isset($shop->physical_uri) && is_string($shop->physical_uri) ? $shop->physical_uri : '/';
            $virtual = isset($shop->virtual_uri) && is_string($shop->virtual_uri) ? $shop->virtual_uri : '';

            return [
                'id_shop' => (int) $shop->id,
                'id_shop_group' => (int) $shop->id_shop_group,
                'host' => $host,
                'base' => $physical . $virtual,
            ];
        } catch (Throwable $e) {
            self::log('shop context failed');

            return null;
        }
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function configString(string $key, array $shop): string
    {
        $value = Configuration::get($key, null, $shop['id_shop_group'], $shop['id_shop']);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param array{id_shop: int, id_shop_group: int} $shop
     */
    private static function put(array $shop, string $key, string $value): void
    {
        Configuration::updateValue($key, $value, false, $shop['id_shop_group'], $shop['id_shop']);
    }

    private static function hmacKey(): string
    {
        return self::readExternalSecret(self::HMAC_KEY_NAME);
    }

    private static function clientSecret(): string
    {
        return self::readExternalSecret(self::CLIENT_SECRET_NAME);
    }

    private static function userAgent(): string
    {
        try {
            $module = Module::getInstanceByName('kiyoh');
            if ($module && isset($module->version) && is_string($module->version) && $module->version !== '') {
                return 'PrestaShop-Kiyoh-Module/' . $module->version;
            }
        } catch (Throwable $e) {
            // Fall through.
        }

        return 'PrestaShop-Kiyoh-Module/1.4.0';
    }

    private static function log(string $message): void
    {
        try {
            PrestaShopLogger::addLog('Kiyoh Connect: ' . $message, 2, null, 'Kiyoh', null, true);
        } catch (Throwable $e) {
            // Logging must not break sign-in.
        }
    }
}

<?php
/**
 * Pure Kiyoh-Connect 1.1 client (per-shop return address).
 *
 * No PrestaShop, network, or secret storage. KiyohConnectService performs
 * the HTTP calls and writes shop configuration.
 *
 * Contract (Kiyoh-Connect PR #1, client kiyoh-prestashop):
 * register HMAC, popup /authorize, /token with client_secret, credentials,
 * refresh and revoke. The HMAC key and client secret are arguments. This
 * file does not contain them.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds Kiyoh-Connect requests and reads their responses.
 */
class KiyohConnect
{
    public const CLIENT_ID = 'kiyoh-prestashop';

    public const SCOPE = 'integration:connect';

    public const ISSUER_KIYOH = 'https://connect.kiyoh.com';

    public const ISSUER_KLANTENVERTELLEN = 'https://connect.klantenvertellen.nl';

    public const TENANT_KIYOH = 98;

    public const TENANT_KLANTENVERTELLEN = 99;

    public const PENDING_TTL_SECONDS = 900;

    public const MAX_RESPONSE_BYTES = 1000000;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 30;

    public const MAX_RETRY_SLEEP_SECONDS = 2;

    public const MAX_ATTEMPTS = 5;

    /**
     * @return string|null Issuer base URL for a saved platform value.
     */
    public static function issuerForPlatform(string $platform): ?string
    {
        $platform = strtolower(trim($platform));
        if ($platform === 'kiyoh') {
            return self::ISSUER_KIYOH;
        }
        if ($platform === 'klantenvertellen') {
            return self::ISSUER_KLANTENVERTELLEN;
        }

        return null;
    }

    /**
     * @return int|null Expected credentials tenant for that platform.
     */
    public static function tenantForPlatform(string $platform): ?int
    {
        $platform = strtolower(trim($platform));
        if ($platform === 'kiyoh') {
            return self::TENANT_KIYOH;
        }
        if ($platform === 'klantenvertellen') {
            return self::TENANT_KLANTENVERTELLEN;
        }

        return null;
    }

    /**
     * Shop origin used in the register/revoke signature. No path.
     */
    public static function shopOrigin(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (!self::isPublicHost($host)) {
            return null;
        }

        return 'https://' . $host;
    }

    /**
     * Exact return address registered with Connect and sent as redirect_uri.
     *
     * @param string $basePath PrestaShop physical + virtual URI, or "/".
     */
    public static function redirectUri(string $host, string $basePath): ?string
    {
        $origin = self::shopOrigin($host);
        if ($origin === null) {
            return null;
        }
        $prefix = self::normalizeBasePath($basePath);
        if ($prefix === null) {
            return null;
        }

        return $origin . $prefix . '/module/kiyoh/oauth';
    }

    /**
     * @return string|null Empty string when the shop is at the domain root.
     */
    public static function normalizeBasePath(string $basePath): ?string
    {
        $basePath = trim(str_replace('\\', '/', $basePath));
        if ($basePath === '' || $basePath === '/') {
            return '';
        }
        if (strpos($basePath, '..') !== false || strpos($basePath, '//') !== false) {
            return null;
        }
        if (!preg_match('#^[A-Za-z0-9._~/-]+$#', $basePath)) {
            return null;
        }
        $trimmed = trim($basePath, '/');
        if ($trimmed === '') {
            return '';
        }

        return '/' . $trimmed;
    }

    public static function isPublicHost(string $host): bool
    {
        if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{2,5})?$/', $host)) {
            return false;
        }
        $name = $host;
        $port = null;
        if (strpos($host, ':') !== false) {
            [$name, $port] = explode(':', $host, 2);
            $portNumber = (int) $port;
            if ($portNumber < 1 || $portNumber > 65535) {
                return false;
            }
        }
        if ($name === '' || $name === 'localhost' || str_ends_with($name, '.local') || str_ends_with($name, '.internal')) {
            return false;
        }
        if (filter_var($name, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $name,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        if (!str_contains($name, '.')) {
            return false;
        }

        return true;
    }

    public static function pkceVerifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * S256 challenge. RFC 7636 appendix B is covered by the unit test.
     */
    public static function pkceChallenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function randomState(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * LF-joined signature input. No trailing newline.
     */
    public static function canonicalString(
        string $action,
        string $clientId,
        string $shopOrigin,
        string $redirectUri,
        int $issuedAt
    ): string {
        return implode("\n", [
            'v1',
            $action,
            $clientId,
            $shopOrigin,
            $redirectUri,
            (string) $issuedAt,
        ]);
    }

    /**
     * base64url (no padding) HMAC-SHA256 over canonicalString().
     */
    public static function sign(string $canonical, string $hmacKey): string
    {
        return self::base64Url(hash_hmac('sha256', $canonical, $hmacKey, true));
    }

    /**
     * @return array{client_id: string, shop_url: string, redirect_uri: string, issued_at: int, signature: string}
     */
    public static function shopRequest(
        string $action,
        string $shopOrigin,
        string $redirectUri,
        int $issuedAt,
        string $hmacKey
    ): array {
        $canonical = self::canonicalString($action, self::CLIENT_ID, $shopOrigin, $redirectUri, $issuedAt);

        return [
            'client_id' => self::CLIENT_ID,
            'shop_url' => $shopOrigin,
            'redirect_uri' => $redirectUri,
            'issued_at' => $issuedAt,
            'signature' => self::sign($canonical, $hmacKey),
        ];
    }

    public static function authorizeUrl(
        string $issuer,
        string $redirectUri,
        string $state,
        string $codeChallenge
    ): string {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($issuer, '/') . '/authorize?' . $query;
    }

    /**
     * @return array<string, string>
     */
    public static function authorizationCodeForm(
        string $code,
        string $codeVerifier,
        string $redirectUri,
        string $clientSecret
    ): array {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'client_secret' => $clientSecret,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function refreshForm(string $refreshToken, string $clientSecret): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function revokeTokenForm(string $token, string $clientSecret, string $hint): array
    {
        return [
            'token' => $token,
            'token_type_hint' => $hint,
            'client_id' => self::CLIENT_ID,
            'client_secret' => $clientSecret,
        ];
    }

    public static function formBody(array $fields): string
    {
        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $json
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}|null
     */
    public static function normalizeToken(array $json): ?array
    {
        $access = self::stringField($json, 'access_token');
        $refresh = self::stringField($json, 'refresh_token');
        if ($access === null || $refresh === null || !isset($json['expires_in']) || !is_numeric($json['expires_in'])) {
            return null;
        }
        $expires = (int) $json['expires_in'];
        if ($expires < 1 || $expires > 86400 * 40) {
            return null;
        }
        if (self::containsControl($access) || self::containsControl($refresh)) {
            return null;
        }

        return [
            'accessToken' => $access,
            'refreshToken' => $refresh,
            'expiresIn' => $expires,
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return array{locationId: string, tenantId: int, locationName: string, inviteApiKey: string}|null
     */
    public static function normalizeCredentials(array $json): ?array
    {
        $locationId = self::scalarField($json, 'locationId');
        $locationName = self::scalarField($json, 'locationName');
        $inviteApiKey = self::stringField($json, 'inviteApiKey');
        if ($locationId === null || $locationId === '' || $inviteApiKey === null || $inviteApiKey === '') {
            return null;
        }
        if (!isset($json['tenantId']) || !is_numeric($json['tenantId'])) {
            return null;
        }
        if ($locationName === null) {
            $locationName = '';
        }
        if (self::containsControl($locationId) || self::containsControl($inviteApiKey) || self::containsControl($locationName)) {
            return null;
        }
        $tenantId = (int) $json['tenantId'];
        if ($tenantId < 1) {
            return null;
        }

        return [
            'locationId' => $locationId,
            'tenantId' => $tenantId,
            'locationName' => $locationName,
            'inviteApiKey' => $inviteApiKey,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    public static function retryAfterSeconds(array $headers, string $body): ?int
    {
        $header = null;
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $header = trim((string) $value);
            }
        }
        $seconds = null;
        if ($header !== null && $header !== '') {
            if (is_numeric($header)) {
                $seconds = (int) $header;
            } else {
                $parsed = strtotime($header);
                if ($parsed !== false) {
                    $seconds = $parsed - time();
                }
            }
        }
        if ($seconds === null) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['retryAfterSeconds']) && is_numeric($decoded['retryAfterSeconds'])) {
                $seconds = (int) $decoded['retryAfterSeconds'];
            }
        }
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 0) {
            return 0;
        }
        if ($seconds > 3600) {
            return 3600;
        }

        return $seconds;
    }

    /**
     * Full-jitter delay when the server did not send Retry-After.
     * $jitterUnit is 0..1. Attempt 0 uses a 1 second ceiling.
     */
    public static function jitterDelay(int $attempt, float $jitterUnit): int
    {
        if ($jitterUnit < 0) {
            $jitterUnit = 0;
        }
        if ($jitterUnit > 1) {
            $jitterUnit = 1;
        }
        $ceiling = min(60, 2 ** max(0, $attempt));

        return (int) floor($jitterUnit * $ceiling);
    }

    /**
     * @param callable(): array{status: int, headers: array<string, string>, body: string, error: string|null} $send
     * @param callable(int): void $sleep
     * @return array{status: int, headers: array<string, string>, body: string, error: string|null, attempts: int, retryAfter: int|null}
     */
    public static function withRetry(callable $send, callable $sleep, bool $allowRetry, int $maxSleepSeconds, float $jitterUnit): array
    {
        $last = [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'no attempt',
            'attempts' => 0,
            'retryAfter' => null,
        ];
        $max = $allowRetry ? self::MAX_ATTEMPTS : 1;
        for ($attempt = 0; $attempt < $max; $attempt++) {
            $response = $send();
            $status = (int) ($response['status'] ?? 0);
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $body = is_string($response['body'] ?? null) ? $response['body'] : '';
            $error = isset($response['error']) && is_string($response['error']) ? $response['error'] : null;
            $retryAfter = self::retryAfterSeconds($headers, $body);
            $last = [
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
                'error' => $error,
                'attempts' => $attempt + 1,
                'retryAfter' => $retryAfter,
            ];
            if (!self::isRetryable($status, $error) || !$allowRetry || $attempt === $max - 1) {
                return $last;
            }
            $delay = $retryAfter !== null ? $retryAfter : self::jitterDelay($attempt, $jitterUnit);
            if ($delay > $maxSleepSeconds) {
                return $last;
            }
            if ($delay > 0) {
                $sleep($delay);
            }
        }

        return $last;
    }

    public static function isRetryable(int $status, ?string $transportError): bool
    {
        if ($transportError !== null && $transportError !== '') {
            return true;
        }

        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }

    /**
     * Short machine code from an OAuth error body, or null when it is not safe to show.
     */
    public static function safeErrorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_string($decoded['error'])) {
            return null;
        }
        $code = $decoded['error'];
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * @param string[] $allowedHosts Lowercase hosts. 127.0.0.1 is only for tests.
     * @param string[] $headers
     * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
     */
    public static function http(string $method, string $url, array $headers, ?string $body, array $allowedHosts): array
    {
        $empty = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'blocked url'];
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $empty;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return $empty;
        }
        if (!in_array($host, $allowedHosts, true)) {
            return $empty;
        }
        $loopback = $host === '127.0.0.1';
        if ($scheme !== 'https' && !($loopback && $scheme === 'http')) {
            return $empty;
        }

        $collector = new KiyohConnectHeaderCollector();
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl init failed'];
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => [$collector, 'add'],
            CURLOPT_PROTOCOLS => $loopback ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $executed = curl_exec($ch);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($executed)) {
            return [
                'status' => 0,
                'headers' => [],
                'body' => '',
                'error' => $curlError !== '' ? 'transport failure' : 'transport failure',
            ];
        }
        if (strlen($executed) > self::MAX_RESPONSE_BYTES) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'response too large'];
        }

        return [
            'status' => $status,
            'headers' => $collector->headers,
            'body' => $executed,
            'error' => null,
        ];
    }

    public static function encrypt(string $plaintext, string $key): ?string
    {
        if (strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            return null;
        }

        return 'v1:' . self::base64Url($iv) . '.' . self::base64Url($tag) . '.' . self::base64Url($cipher);
    }

    public static function decrypt(string $payload, string $key): ?string
    {
        if (strlen($key) !== 32 || !function_exists('openssl_decrypt')) {
            return null;
        }
        if (!preg_match('/^v1:([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $payload, $matches)) {
            return null;
        }
        $iv = self::base64UrlDecode($matches[1]);
        $tag = self::base64UrlDecode($matches[2]);
        $cipher = self::base64UrlDecode($matches[3]);
        if ($iv === null || $tag === null || $cipher === null || strlen($iv) !== 12 || strlen($tag) !== 16) {
            return null;
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plain)) {
            return null;
        }

        return $plain;
    }

    /**
     * @param array<string, mixed> $pending
     */
    public static function encodePending(array $pending): string
    {
        $json = json_encode($pending);
        if (!is_string($json)) {
            return '';
        }

        return 'b64:' . base64_encode($json);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodePending(?string $raw): ?array
    {
        if (!is_string($raw) || !str_starts_with($raw, 'b64:')) {
            return null;
        }
        $json = base64_decode(substr($raw, 4), true);
        if (!is_string($json)) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function isSafeAdminReturn(string $url, string $shopHost): bool
    {
        if (preg_match('/[\r\n\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $expected = strtolower($shopHost);
        $expectedPort = null;
        if (strpos($expected, ':') !== false) {
            [$expected, $portText] = explode(':', $expected, 2);
            $expectedPort = (int) $portText;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($host === '' || $host !== $expected) {
            return false;
        }
        if ($expectedPort !== null && $port !== $expectedPort) {
            return false;
        }
        $haystack = (string) ($parts['path'] ?? '') . '?' . (string) ($parts['query'] ?? '');

        return str_contains($haystack, 'configure=kiyoh') || str_contains($haystack, '/configure/kiyoh');
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function stringField(array $json, string $key): ?string
    {
        if (!isset($json[$key]) || !is_string($json[$key])) {
            return null;
        }
        $value = trim($json[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function scalarField(array $json, string $key): ?string
    {
        if (!isset($json[$key]) || is_array($json[$key]) || is_object($json[$key])) {
            return null;
        }
        if (!is_scalar($json[$key])) {
            return null;
        }

        return trim((string) $json[$key]);
    }

    public static function containsControl(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}

/**
 * Collects response headers without storing the body.
 */
class KiyohConnectHeaderCollector
{
    /** @var array<string, string> */
    public $headers = [];

    /**
     * @param resource|\CurlHandle $ch
     */
    public function add($ch, string $line): int
    {
        $trim = trim($line);
        $separator = strpos($trim, ':');
        if ($trim !== '' && $separator !== false) {
            $name = strtolower(trim(substr($trim, 0, $separator)));
            $this->headers[$name] = trim(substr($trim, $separator + 1));
        }

        return strlen($line);
    }
}

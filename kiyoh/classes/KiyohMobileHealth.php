<?php
/**
 * Pure helpers for Kiyoh Mobile integration health reports.
 *
 * The PrestaShop adapter lives in KiyohMobileHealthReporter. This class does
 * not call PrestaShop, the network, or the database, so the payload rules can
 * be tested on their own.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds and decides Kiyoh Mobile health payloads.
 */
class KiyohMobileHealth
{
    public const PLATFORM = 'prestashop';

    public const HEALTH_PATH = '/api/integrations/health';

    /** Default for the "Kiyoh Mobile URL(s)" setting. */
    public const DEFAULT_URLS = 'https://kiyoh-mobile.com';

    public const MAX_PRODUCT_CODES = 500;

    public const MAX_PRODUCT_CODE_LENGTH = 200;

    public const MAX_ERROR_LENGTH = 500;

    public const MAX_FAILURES = 1000000;

    /** Resend an unchanged payload once this many seconds have passed. */
    public const REPORT_MAX_AGE_SECONDS = 86400;

    /** Back off after a transport failure so a down host is not hit on every order. */
    public const TRANSPORT_BACKOFF_SECONDS = 60;

    public const MAX_RETRY_AFTER_SECONDS = 3600;

    /**
     * Split a comma-separated list of base URLs.
     *
     * An empty value uses $defaultRaw. Invalid entries are returned separately
     * and are not turned into the default.
     *
     * @param string|null $raw
     * @param string $defaultRaw
     * @return array{urls: string[], invalid: string[]}
     */
    public static function parseBaseUrls(?string $raw, string $defaultRaw = self::DEFAULT_URLS): array
    {
        $source = is_string($raw) ? trim($raw) : '';
        if ($source === '') {
            $source = $defaultRaw;
        }

        $urls = [];
        $invalid = [];
        foreach (explode(',', $source) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $normalized = self::normalizeBaseUrl($part);
            if ($normalized === null) {
                $invalid[] = $part;
                continue;
            }
            $urls[$normalized] = $normalized;
        }

        return [
            'urls' => array_values($urls),
            'invalid' => $invalid,
        ];
    }

    /**
     * @param string $url
     * @return string|null
     */
    public static function normalizeBaseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $url = rtrim($url, '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path !== '' && $path !== '/') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $parts['host'] . $port;
    }

    /**
     * Shop domain, plus the virtual URI when this shop is not the domain root.
     *
     * @param string $domain
     * @param string $domainSsl
     * @param string $uri
     * @return string|null
     */
    public static function shopId(string $domain, string $domainSsl, string $uri): ?string
    {
        $host = trim($domainSsl) !== '' ? trim($domainSsl) : trim($domain);
        $host = preg_replace('#^https?://#i', '', $host);
        if (!is_string($host)) {
            return null;
        }
        $host = trim($host, '/');

        $path = trim($uri);
        $path = trim($path, '/');
        $shopId = $path !== '' ? $host . '/' . $path : $host;
        if ($shopId === '' || strlen($shopId) > 200) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/', $shopId)) {
            return null;
        }

        return $shopId;
    }

    /**
     * @param string $version
     * @return string
     */
    public static function normalizePluginVersion(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $version)) {
            return $version;
        }

        return '0';
    }

    /**
     * @param mixed $status
     * @return string
     */
    public static function normalizeConnectionStatus($status): string
    {
        if ($status === 'ok' || $status === 'error' || $status === 'unknown') {
            return $status;
        }

        return 'unknown';
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    public static function normalizeIso($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    public static function normalizeError($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, self::MAX_ERROR_LENGTH, 'UTF-8');
        }

        return substr($value, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @param mixed $value
     * @return int
     */
    public static function normalizeFailures($value): int
    {
        $failures = (int) $value;
        if ($failures < 0) {
            return 0;
        }
        if ($failures > self::MAX_FAILURES) {
            return self::MAX_FAILURES;
        }

        return $failures;
    }

    /**
     * Keep at most 500 trimmed product codes. Over-long and empty codes are dropped.
     *
     * @param mixed $codes
     * @return string[]
     */
    public static function normalizeProductCodes($codes): array
    {
        if (!is_array($codes)) {
            return [];
        }

        $unique = [];
        foreach ($codes as $code) {
            if (!is_string($code) && !is_int($code)) {
                continue;
            }
            $trimmed = trim((string) $code);
            if ($trimmed === '' || strlen($trimmed) > self::MAX_PRODUCT_CODE_LENGTH) {
                continue;
            }
            $unique[$trimmed] = $trimmed;
            if (count($unique) >= self::MAX_PRODUCT_CODES) {
                break;
            }
        }

        $normalized = array_values($unique);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param int|null $now
     * @return string
     */
    public static function nowIso(?int $now = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $now ?? time());
    }

    /**
     * Interpret a PrestaShop datetime in the server timezone and emit UTC.
     *
     * @param string|null $value
     * @return string|null
     */
    public static function mysqlToIso(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            $timezoneName = date_default_timezone_get();
            if (!is_string($timezoneName) || $timezoneName === '') {
                $timezoneName = 'UTC';
            }
            $stored = new DateTimeImmutable($value, new DateTimeZone($timezoneName));

            return $stored->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invites
     * @param array<string, mixed> $productSync
     * @return array<string, mixed>
     */
    public static function buildBody(string $shopId, string $pluginVersion, array $connection, array $invites, array $productSync): array
    {
        $body = [
            'platform' => self::PLATFORM,
            'shopId' => $shopId,
            'pluginVersion' => self::normalizePluginVersion($pluginVersion),
            'connection' => [
                'status' => self::normalizeConnectionStatus($connection['status'] ?? 'unknown'),
                'lastOkAt' => self::normalizeIso($connection['lastOkAt'] ?? null),
                'lastError' => self::normalizeError($connection['lastError'] ?? null),
            ],
            'invites' => [
                'lastOkAt' => self::normalizeIso($invites['lastOkAt'] ?? null),
                'failures24h' => self::normalizeFailures($invites['failures24h'] ?? 0),
            ],
            'productSync' => [
                'lastOkAt' => self::normalizeIso($productSync['lastOkAt'] ?? null),
                'lastError' => self::normalizeError($productSync['lastError'] ?? null),
            ],
        ];

        $codes = self::normalizeProductCodes($productSync['productCodes'] ?? []);
        if ($codes !== []) {
            $body['productSync']['productCodes'] = $codes;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return string
     */
    public static function digest(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '';
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $connection
     * @param string $outcome ok|error|unchanged
     * @param string|null $error
     * @param string $nowIso
     * @return array{status: string, lastOkAt: string|null, lastError: string|null}
     */
    public static function applyConnection(array $connection, string $outcome, ?string $error, string $nowIso): array
    {
        $status = self::normalizeConnectionStatus($connection['status'] ?? 'unknown');
        $lastOkAt = self::normalizeIso($connection['lastOkAt'] ?? null);
        $lastError = self::normalizeError($connection['lastError'] ?? null);

        if ($outcome === 'ok') {
            return [
                'status' => 'ok',
                'lastOkAt' => self::normalizeIso($nowIso),
                'lastError' => null,
            ];
        }

        if ($outcome === 'error') {
            return [
                'status' => 'error',
                'lastOkAt' => $lastOkAt,
                'lastError' => self::normalizeError($error) ?? 'error',
            ];
        }

        return [
            'status' => $status,
            'lastOkAt' => $lastOkAt,
            'lastError' => $lastError,
        ];
    }

    /**
     * Map an invite or product-sync result onto a connection outcome.
     *
     * Validation failures do not change connection status. Authentication and
     * transport failures do.
     *
     * @param bool $success
     * @param int|null $httpStatus
     * @param string|null $errorMessage
     * @return string
     */
    public static function outcomeFromResult(bool $success, ?int $httpStatus, ?string $errorMessage): string
    {
        if ($success) {
            return 'ok';
        }
        if ($httpStatus === 401 || $httpStatus === 403) {
            return 'error';
        }
        if ($httpStatus === null && is_string($errorMessage)) {
            if (stripos($errorMessage, 'curl') !== false || stripos($errorMessage, 'connection') !== false) {
                return 'error';
            }
        }

        return 'unchanged';
    }

    /**
     * @return array{connection: array{status: string, lastOkAt: string|null, lastError: string|null}, reports: array<string, array{digest: string, reportedAt: int, retryAfter: int}>}
     */
    public static function emptyState(): array
    {
        return [
            'connection' => [
                'status' => 'unknown',
                'lastOkAt' => null,
                'lastError' => null,
            ],
            'reports' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return string
     */
    public static function encodeState(array $state): string
    {
        $json = json_encode(self::sanitizeState($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        return 'b64:' . base64_encode($json);
    }

    /**
     * @param string|null $stored
     * @return array{connection: array{status: string, lastOkAt: string|null, lastError: string|null}, reports: array<string, array{digest: string, reportedAt: int, retryAfter: int}>}
     */
    public static function decodeState(?string $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return self::emptyState();
        }

        $json = $stored;
        if (strpos($stored, 'b64:') === 0) {
            $decoded = base64_decode(substr($stored, 4), true);
            if (!is_string($decoded)) {
                return self::emptyState();
            }
            $json = $decoded;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::emptyState();
        }

        return self::sanitizeState($data);
    }

    /**
     * URLs that should receive this payload.
     *
     * A 429 or transport backoff skips the URL even when $force is true.
     * Otherwise a forced event always sends. The daily cron sends when the
     * payload changed or the last accepted report is at least 24 hours old.
     *
     * @param string[] $urls
     * @param array<string, mixed> $reports
     * @param string $digest
     * @param int $now
     * @param bool $force
     * @param int $maxAgeSeconds
     * @return string[]
     */
    public static function urlsDue(array $urls, array $reports, string $digest, int $now, bool $force, int $maxAgeSeconds = self::REPORT_MAX_AGE_SECONDS): array
    {
        $due = [];
        foreach ($urls as $url) {
            $state = isset($reports[$url]) && is_array($reports[$url]) ? $reports[$url] : [];
            $retryAfter = (int) ($state['retryAfter'] ?? 0);
            if ($retryAfter > $now) {
                continue;
            }
            if ($force) {
                $due[] = $url;
                continue;
            }
            $reportedAt = (int) ($state['reportedAt'] ?? 0);
            $previousDigest = (string) ($state['digest'] ?? '');
            if ($previousDigest !== $digest || $reportedAt <= 0 || ($now - $reportedAt) >= $maxAgeSeconds) {
                $due[] = $url;
            }
        }

        return $due;
    }

    /**
     * @param array<string, mixed> $reports
     * @param string $url
     * @param string $digest
     * @param int $now
     * @param int $status
     * @param array<string, string> $responseHeaders
     * @param string $responseBody
     * @param string|null $transportError
     * @return array<string, array{digest: string, reportedAt: int, retryAfter: int}>
     */
    public static function recordReportResult(array $reports, string $url, string $digest, int $now, int $status, array $responseHeaders, string $responseBody, ?string $transportError): array
    {
        $current = isset($reports[$url]) && is_array($reports[$url]) ? $reports[$url] : [];
        $record = [
            'digest' => (string) ($current['digest'] ?? ''),
            'reportedAt' => (int) ($current['reportedAt'] ?? 0),
            'retryAfter' => (int) ($current['retryAfter'] ?? 0),
        ];

        if ($transportError !== null || $status === 0) {
            $record['retryAfter'] = $now + self::TRANSPORT_BACKOFF_SECONDS;
            $reports[$url] = $record;

            return self::normalizeReports($reports);
        }

        if ($status === 429) {
            $record['retryAfter'] = $now + self::retryAfterSeconds($responseHeaders, $responseBody);
            $reports[$url] = $record;

            return self::normalizeReports($reports);
        }

        if ($status >= 200 && $status < 300) {
            $record['digest'] = $digest;
            $record['reportedAt'] = $now;
            $record['retryAfter'] = 0;
            $reports[$url] = $record;

            return self::normalizeReports($reports);
        }

        $reports[$url] = $record;

        return self::normalizeReports($reports);
    }

    /**
     * Seconds to wait after HTTP 429. Header wins, then the JSON body, then 60.
     *
     * @param array<string, string> $headers
     * @param string $body
     * @return int
     */
    public static function retryAfterSeconds(array $headers, string $body): int
    {
        $seconds = null;
        $header = null;
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $header = trim((string) $value);
            }
        }

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
            $seconds = self::TRANSPORT_BACKOFF_SECONDS;
        }
        if ($seconds < 1) {
            return 1;
        }
        if ($seconds > self::MAX_RETRY_AFTER_SECONDS) {
            return self::MAX_RETRY_AFTER_SECONDS;
        }

        return $seconds;
    }

    /**
     * @param string[] $lines
     * @return array<string, string>
     */
    public static function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            $separator = strpos($line, ':');
            if ($line === '' || $separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $reports
     * @param string[] $urls
     * @return array<string, array{digest: string, reportedAt: int, retryAfter: int}>
     */
    public static function pruneReports(array $reports, array $urls): array
    {
        $kept = [];
        foreach ($urls as $url) {
            if (isset($reports[$url]) && is_array($reports[$url])) {
                $kept[$url] = $reports[$url];
            }
        }

        return self::normalizeReports($kept);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{connection: array{status: string, lastOkAt: string|null, lastError: string|null}, reports: array<string, array{digest: string, reportedAt: int, retryAfter: int}>}
     */
    private static function sanitizeState(array $state): array
    {
        $connection = isset($state['connection']) && is_array($state['connection']) ? $state['connection'] : [];
        $reports = isset($state['reports']) && is_array($state['reports']) ? $state['reports'] : [];

        return [
            'connection' => self::applyConnection($connection, 'unchanged', null, self::nowIso()),
            'reports' => self::normalizeReports($reports),
        ];
    }

    /**
     * @param array<string, mixed> $reports
     * @return array<string, array{digest: string, reportedAt: int, retryAfter: int}>
     */
    private static function normalizeReports(array $reports): array
    {
        $normalized = [];
        foreach ($reports as $url => $report) {
            if (!is_string($url) || !is_array($report)) {
                continue;
            }
            $normalized[$url] = [
                'digest' => (string) ($report['digest'] ?? ''),
                'reportedAt' => (int) ($report['reportedAt'] ?? 0),
                'retryAfter' => (int) ($report['retryAfter'] ?? 0),
            ];
        }

        return $normalized;
    }
}

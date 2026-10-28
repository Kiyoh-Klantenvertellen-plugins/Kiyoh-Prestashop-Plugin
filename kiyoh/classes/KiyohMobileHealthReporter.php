<?php
/**
 * Posts PrestaShop shop health to each configured Kiyoh Mobile base URL.
 *
 * Failures are logged and swallowed. Callers on the order and product paths
 * must not see an exception from this class.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohMobileHealth.php';

/**
 * PrestaShop adapter for Kiyoh Mobile health reports.
 */
class KiyohMobileHealthReporter
{
    public const STATE_KEY = 'KIYOH_HEALTH_STATE';

    public const URLS_KEY = 'KIYOH_MOBILE_URLS';

    public const TOKEN_KEY = 'KIYOH_CRON_TOKEN';

    public const TIMEOUT_SECONDS = 4;

    public const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Create the daily cron token once and return it.
     *
     * @return string
     */
    public static function ensureCronToken(): string
    {
        $existing = Configuration::getGlobalValue(self::TOKEN_KEY);
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $token = bin2hex(hash('sha256', uniqid('kiyoh', true), true));
            $token = substr($token, 0, 32);
        }

        Configuration::updateGlobalValue(self::TOKEN_KEY, $token);

        return $token;
    }

    /**
     * Front-controller URL that publishes due health reports.
     *
     * @return string
     */
    public static function cronUrl(): string
    {
        try {
            $token = self::ensureCronToken();
            $context = Context::getContext();
            if (!$context || !$context->link) {
                return '';
            }

            return (string) $context->link->getModuleLink('kiyoh', 'cron', ['token' => $token], true);
        } catch (Throwable $e) {
            self::log('cron URL failed: ' . $e->getMessage(), 2);

            return '';
        }
    }

    /**
     * Daily heartbeat. Sends only when the payload changed or the last
     * accepted report for that URL is at least 24 hours old.
     *
     * @return array{shops: int, posted: int, accepted: int}
     */
    public static function publishDueReports(): array
    {
        $totals = ['shops' => 0, 'posted' => 0, 'accepted' => 0];
        try {
            $shops = self::shopsForCron();
            $totals['shops'] = count($shops);
            foreach ($shops as $shop) {
                try {
                    $result = self::publishShop($shop, false);
                    $totals['posted'] += $result['posted'];
                    $totals['accepted'] += $result['accepted'];
                } catch (Throwable $e) {
                    self::log('health heartbeat failed for shop ' . (int) $shop['id_shop'] . ': ' . $e->getMessage(), 3);
                }
            }
        } catch (Throwable $e) {
            self::log('health heartbeat failed: ' . $e->getMessage(), 3);
        }

        return $totals;
    }

    /**
     * Config was saved. Recheck credentials only when they changed, then post.
     *
     * @param bool $recheckConnection
     * @return string Connection error to show the merchant, or an empty string
     */
    public static function noteConfigurationSaved(bool $recheckConnection): string
    {
        try {
            $shop = self::contextShop();
            if ($shop === null) {
                return '';
            }

            $connectionError = '';
            if ($recheckConnection) {
                $connectionError = self::recheckConnection($shop);
            }
            self::publishShop($shop, true);

            return $connectionError;
        } catch (Throwable $e) {
            self::log('configuration health report failed: ' . $e->getMessage(), 3);

            return '';
        }
    }

    /**
     * The Test Connection button already called the review API.
     *
     * @param bool $success
     * @param string|null $errorMessage
     * @return void
     */
    public static function noteConnectionResult(bool $success, ?string $errorMessage): void
    {
        try {
            $shop = self::contextShop();
            if ($shop === null) {
                return;
            }
            self::updateConnection($shop, $success ? 'ok' : 'error', $success ? null : $errorMessage);
            self::publishShop($shop, true);
        } catch (Throwable $e) {
            self::log('connection health report failed: ' . $e->getMessage(), 3);
        }
    }

    /**
     * An invitation was sent or failed.
     *
     * @param int $idShop
     * @param bool $sent
     * @param string|null $errorMessage
     * @param int|null $httpStatus
     * @return void
     */
    public static function noteInvite(int $idShop, bool $sent, ?string $errorMessage, ?int $httpStatus): void
    {
        try {
            $shop = self::shopContextById($idShop);
            if ($shop === null) {
                return;
            }
            $outcome = KiyohMobileHealth::outcomeFromResult($sent, $httpStatus, $errorMessage);
            self::updateConnection($shop, $outcome, $errorMessage);
            self::publishShop($shop, true);
        } catch (Throwable $e) {
            self::log('invite health report failed: ' . $e->getMessage(), 3);
        }
    }

    /**
     * A product sync finished (one product, a bulk run, or a retry run).
     *
     * @param int $idShop
     * @param bool $ok
     * @param string|null $errorMessage
     * @param int|null $httpStatus
     * @return void
     */
    public static function noteProductSync(int $idShop, bool $ok, ?string $errorMessage, ?int $httpStatus): void
    {
        try {
            $shop = self::shopContextById($idShop);
            if ($shop === null) {
                return;
            }
            $outcome = KiyohMobileHealth::outcomeFromResult($ok, $httpStatus, $errorMessage);
            self::updateConnection($shop, $outcome, $errorMessage);
            self::publishShop($shop, true);
        } catch (Throwable $e) {
            self::log('product sync health report failed: ' . $e->getMessage(), 3);
        }
    }

    /**
     * @param array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string} $shop
     * @return string
     */
    private static function recheckConnection(array $shop): string
    {
        try {
            require_once dirname(__FILE__) . '/KiyohConfigManager.php';
            if (!KiyohConfigManager::isConfigured()) {
                return '';
            }
            require_once dirname(__FILE__) . '/KiyohApiClient.php';
            $client = KiyohApiClient::createFromConfig();
            $result = $client->testConnection();
            if (!empty($result['success'])) {
                self::updateConnection($shop, 'ok', null);

                return '';
            }
            $message = isset($result['message']) ? (string) $result['message'] : 'Connection failed';
            self::updateConnection($shop, 'error', $message);

            return $message;
        } catch (Throwable $e) {
            self::updateConnection($shop, 'error', $e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * @param array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string} $shop
     * @param string $outcome
     * @param string|null $error
     * @return void
     */
    private static function updateConnection(array $shop, string $outcome, ?string $error): void
    {
        if ($outcome === 'unchanged') {
            return;
        }
        $state = self::loadState($shop['id_shop'], $shop['id_shop_group']);
        $state['connection'] = KiyohMobileHealth::applyConnection(
            $state['connection'],
            $outcome,
            $error,
            KiyohMobileHealth::nowIso()
        );
        self::saveState($shop['id_shop'], $shop['id_shop_group'], $state);
    }

    /**
     * @param array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string} $shop
     * @param bool $force
     * @return array{posted: int, accepted: int}
     */
    private static function publishShop(array $shop, bool $force): array
    {
        $result = ['posted' => 0, 'accepted' => 0];
        $idShop = (int) $shop['id_shop'];
        $idShopGroup = (int) $shop['id_shop_group'];

        $locationId = self::configString('KIYOH_LOCATION_ID', $idShopGroup, $idShop);
        $apiToken = self::configString('KIYOH_API_KEY', $idShopGroup, $idShop);
        if ($locationId === '' || $apiToken === '') {
            return $result;
        }

        $shopId = KiyohMobileHealth::shopId($shop['domain'], $shop['domain_ssl'], $shop['uri']);
        if ($shopId === null) {
            self::log('health report skipped, shop domain is not a valid shopId', 2);

            return $result;
        }

        $parsed = KiyohMobileHealth::parseBaseUrls(
            self::configString(self::URLS_KEY, $idShopGroup, $idShop),
            KiyohMobileHealth::DEFAULT_URLS
        );
        if ($parsed['invalid'] !== []) {
            self::log('ignoring invalid Kiyoh Mobile URLs', 2);
        }
        if ($parsed['urls'] === []) {
            return $result;
        }

        $state = self::loadState($idShop, $idShopGroup);
        $body = KiyohMobileHealth::buildBody(
            $shopId,
            self::moduleVersion(),
            $state['connection'],
            self::inviteSnapshot($idShop),
            self::productSnapshot($idShop)
        );
        $digest = KiyohMobileHealth::digest($body);
        $now = time();
        $due = KiyohMobileHealth::urlsDue($parsed['urls'], $state['reports'], $digest, $now, $force);
        if ($due === []) {
            $state['reports'] = KiyohMobileHealth::pruneReports($state['reports'], $parsed['urls']);
            self::saveState($idShop, $idShopGroup, $state);

            return $result;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            self::log('health payload could not be encoded', 3);

            return $result;
        }

        foreach (self::postJson($due, $locationId, $apiToken, $payload) as $response) {
            $result['posted']++;
            $status = (int) $response['status'];
            if ($status >= 200 && $status < 300) {
                $result['accepted']++;
                self::log('health report accepted by ' . $response['url'] . ' for ' . $shopId, 1);
            } elseif ($status === 429) {
                self::log('health report rate limited by ' . $response['url'] . ' for ' . $shopId, 2);
            } else {
                $detail = $response['error'] !== null ? $response['error'] : ('HTTP ' . $status);
                self::log('health report failed for ' . $response['url'] . ' (' . $detail . ')', 2);
            }
            $state['reports'] = KiyohMobileHealth::recordReportResult(
                $state['reports'],
                (string) $response['url'],
                $digest,
                $now,
                $status,
                $response['headers'],
                $response['body'],
                $response['error']
            );
        }

        $state['reports'] = KiyohMobileHealth::pruneReports($state['reports'], $parsed['urls']);
        self::saveState($idShop, $idShopGroup, $state);

        return $result;
    }

    /**
     * @param int $idShop
     * @return array{lastOkAt: string|null, failures24h: int}
     */
    private static function inviteSnapshot(int $idShop): array
    {
        $empty = ['lastOkAt' => null, 'failures24h' => 0];
        try {
            $since = date('Y-m-d H:i:s', time() - KiyohMobileHealth::REPORT_MAX_AGE_SECONDS);
            $failuresQuery = new DbQuery();
            $failuresQuery->select('COUNT(*)');
            $failuresQuery->from('kiyoh_invitations', 'i');
            $failuresQuery->innerJoin('orders', 'o', 'o.id_order = i.id_order');
            $failuresQuery->where('i.status = "failed"');
            $failuresQuery->where('i.date_add >= "' . pSQL($since) . '"');
            $failuresQuery->where('o.id_shop = ' . (int) $idShop);
            $failures = (int) Db::getInstance()->getValue($failuresQuery);

            $lastQuery = new DbQuery();
            $lastQuery->select('MAX(i.sent_at)');
            $lastQuery->from('kiyoh_invitations', 'i');
            $lastQuery->innerJoin('orders', 'o', 'o.id_order = i.id_order');
            $lastQuery->where('i.status = "sent"');
            $lastQuery->where('i.sent_at IS NOT NULL');
            $lastQuery->where('o.id_shop = ' . (int) $idShop);
            $lastSent = Db::getInstance()->getValue($lastQuery);

            return [
                'lastOkAt' => KiyohMobileHealth::mysqlToIso(is_string($lastSent) ? $lastSent : null),
                'failures24h' => $failures,
            ];
        } catch (Throwable $e) {
            self::log('invite health snapshot failed: ' . $e->getMessage(), 2);

            return $empty;
        }
    }

    /**
     * @param int $idShop
     * @return array{lastOkAt: string|null, lastError: string|null, productCodes: string[]}
     */
    private static function productSnapshot(int $idShop): array
    {
        $empty = ['lastOkAt' => null, 'lastError' => null, 'productCodes' => []];
        try {
            $shopId = (int) $idShop;
            $syncTable = _DB_PREFIX_ . 'kiyoh_product_sync';
            $productShopTable = _DB_PREFIX_ . 'product_shop';
            $productTable = _DB_PREFIX_ . 'product';
            $latest = Db::getInstance()->getRow(
                'SELECT s.sync_status, s.error_message, s.last_sync
                FROM `' . $syncTable . '` s
                INNER JOIN `' . $productShopTable . '` ps
                    ON ps.id_product = s.id_product AND ps.id_shop = ' . $shopId . '
                ORDER BY s.date_upd DESC
                LIMIT 1'
            );
            $lastOk = Db::getInstance()->getValue(
                'SELECT MAX(s.last_sync)
                FROM `' . $syncTable . '` s
                INNER JOIN `' . $productShopTable . '` ps
                    ON ps.id_product = s.id_product AND ps.id_shop = ' . $shopId . '
                WHERE s.sync_status = "synced"'
            );
            $codeRows = Db::getInstance()->executeS(
                'SELECT p.reference, p.id_product
                FROM `' . $syncTable . '` s
                INNER JOIN `' . $productTable . '` p ON p.id_product = s.id_product
                INNER JOIN `' . $productShopTable . '` ps
                    ON ps.id_product = s.id_product AND ps.id_shop = ' . $shopId . '
                WHERE s.sync_status = "synced"
                ORDER BY s.last_sync DESC
                LIMIT ' . (int) KiyohMobileHealth::MAX_PRODUCT_CODES
            );

            $codes = [];
            if (is_array($codeRows)) {
                foreach ($codeRows as $row) {
                    $reference = isset($row['reference']) ? trim((string) $row['reference']) : '';
                    $codes[] = $reference !== '' ? $reference : (string) ($row['id_product'] ?? '');
                }
            }

            $lastError = null;
            if (is_array($latest) && ($latest['sync_status'] ?? '') === 'error') {
                $lastError = isset($latest['error_message']) ? (string) $latest['error_message'] : null;
            }

            return [
                'lastOkAt' => KiyohMobileHealth::mysqlToIso(is_string($lastOk) ? $lastOk : null),
                'lastError' => $lastError,
                'productCodes' => $codes,
            ];
        } catch (Throwable $e) {
            self::log('product health snapshot failed: ' . $e->getMessage(), 2);

            return $empty;
        }
    }

    /**
     * @param string[] $baseUrls
     * @param string $locationId
     * @param string $apiToken
     * @param string $payload
     * @return array<int, array{url: string, status: int, headers: array<string, string>, body: string, error: string|null}>
     */
    private static function postJson(array $baseUrls, string $locationId, string $apiToken, string $payload): array
    {
        if (!function_exists('curl_multi_init') || count($baseUrls) === 1) {
            $results = [];
            foreach ($baseUrls as $baseUrl) {
                $handle = self::createHandle($baseUrl, $locationId, $apiToken, $payload);
                $results[] = self::readHandle($handle, true);
            }

            return $results;
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            $results = [];
            foreach ($baseUrls as $baseUrl) {
                $results[] = self::readHandle(self::createHandle($baseUrl, $locationId, $apiToken, $payload), true);
            }

            return $results;
        }

        $handles = [];
        foreach ($baseUrls as $baseUrl) {
            $handle = self::createHandle($baseUrl, $locationId, $apiToken, $payload);
            $handles[] = $handle;
            curl_multi_add_handle($multi, $handle['ch']);
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                if (curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle['ch']);
            $results[] = self::readHandle($handle, false);
        }
        curl_multi_close($multi);

        return $results;
    }

    /**
     * @param string $baseUrl
     * @param string $locationId
     * @param string $apiToken
     * @param string $payload
     * @return array{ch: \CurlHandle|resource, url: string, collector: KiyohHealthHeaderCollector}
     */
    private static function createHandle(string $baseUrl, string $locationId, string $apiToken, string $payload): array
    {
        $collector = new KiyohHealthHeaderCollector();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . KiyohMobileHealth::HEALTH_PATH,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Publication-Api-Token: ' . $apiToken,
                'X-Location-Id: ' . $locationId,
                'User-Agent: PrestaShop-Kiyoh-Module/' . self::moduleVersion(),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => [$collector, 'add'],
        ]);

        return [
            'ch' => $ch,
            'url' => $baseUrl,
            'collector' => $collector,
        ];
    }

    /**
     * Read one finished handle. $execute runs it now; otherwise the multi handle already did.
     *
     * @param array{ch: \CurlHandle|resource, url: string, collector: KiyohHealthHeaderCollector} $handle
     * @param bool $execute
     * @return array{url: string, status: int, headers: array<string, string>, body: string, error: string|null}
     */
    private static function readHandle(array $handle, bool $execute): array
    {
        $ch = $handle['ch'];
        if ($execute) {
            $executed = curl_exec($ch);
            $body = is_string($executed) ? $executed : false;
        } else {
            $fetched = curl_multi_getcontent($ch);
            $body = is_string($fetched) ? $fetched : false;
        }
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false || $status === 0) {
            return [
                'url' => $handle['url'],
                'status' => 0,
                'headers' => [],
                'body' => '',
                'error' => $error !== '' ? $error : 'cURL error',
            ];
        }

        return [
            'url' => $handle['url'],
            'status' => $status,
            'headers' => KiyohMobileHealth::parseHeaders($handle['collector']->lines),
            'body' => $body,
            'error' => null,
        ];
    }

    /**
     * @param int $idShop
     * @param int $idShopGroup
     * @return array{connection: array{status: string, lastOkAt: string|null, lastError: string|null}, reports: array<string, array{digest: string, reportedAt: int, retryAfter: int}>}
     */
    private static function loadState(int $idShop, int $idShopGroup): array
    {
        $raw = Configuration::get(self::STATE_KEY, null, $idShopGroup, $idShop);

        return KiyohMobileHealth::decodeState(is_string($raw) ? $raw : null);
    }

    /**
     * @param int $idShop
     * @param int $idShopGroup
     * @param array<string, mixed> $state
     * @return void
     */
    private static function saveState(int $idShop, int $idShopGroup, array $state): void
    {
        Configuration::updateValue(
            self::STATE_KEY,
            KiyohMobileHealth::encodeState($state),
            false,
            $idShopGroup,
            $idShop
        );
    }

    /**
     * @param string $key
     * @param int $idShopGroup
     * @param int $idShop
     * @return string
     */
    private static function configString(string $key, int $idShopGroup, int $idShop): string
    {
        $value = Configuration::get($key, null, $idShopGroup, $idShop);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @return string
     */
    private static function moduleVersion(): string
    {
        try {
            $module = Module::getInstanceByName('kiyoh');
            if ($module && is_string($module->version) && $module->version !== '') {
                return $module->version;
            }
        } catch (Throwable $e) {
            // Fall through to the known module version.
        }

        return '1.4.0';
    }

    /**
     * @return array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string}|null
     */
    private static function contextShop(): ?array
    {
        try {
            $context = Context::getContext();
            if (!$context || !$context->shop) {
                return null;
            }
            $shop = $context->shop;

            return self::shopRow(
                (int) $shop->id,
                (int) $shop->id_shop_group,
                self::stringValue($shop->domain),
                self::stringValue($shop->domain_ssl),
                self::stringValue($shop->physical_uri) . self::stringValue($shop->virtual_uri)
            );
        } catch (Throwable $e) {
            self::log('shop context failed: ' . $e->getMessage(), 2);

            return null;
        }
    }

    /**
     * @param int $idShop
     * @return array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string}|null
     */
    private static function shopContextById(int $idShop): ?array
    {
        if ($idShop <= 0) {
            return self::contextShop();
        }

        try {
            $shop = new Shop($idShop);
            if (!Validate::isLoadedObject($shop)) {
                return self::contextShop();
            }

            return self::shopRow(
                (int) $shop->id,
                (int) $shop->id_shop_group,
                self::stringValue($shop->domain),
                self::stringValue($shop->domain_ssl),
                self::stringValue($shop->physical_uri) . self::stringValue($shop->virtual_uri)
            );
        } catch (Throwable $e) {
            return self::contextShop();
        }
    }

    /**
     * @return array<int, array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string}>
     */
    private static function shopsForCron(): array
    {
        $shops = Shop::getShops(true);
        if (!is_array($shops) || $shops === []) {
            $current = self::contextShop();

            return $current === null ? [] : [$current];
        }

        $rows = [];
        foreach ($shops as $shop) {
            if (!is_array($shop)) {
                continue;
            }
            $idShop = (int) ($shop['id_shop'] ?? 0);
            if ($idShop <= 0) {
                continue;
            }
            $rows[] = self::shopRow(
                $idShop,
                (int) ($shop['id_shop_group'] ?? 0),
                self::stringValue($shop['domain'] ?? ''),
                self::stringValue($shop['domain_ssl'] ?? ''),
                self::stringValue($shop['uri'] ?? '/')
            );
        }

        return $rows;
    }

    /**
     * @param int $idShop
     * @param int $idShopGroup
     * @param string $domain
     * @param string $domainSsl
     * @param string $uri
     * @return array{id_shop: int, id_shop_group: int, domain: string, domain_ssl: string, uri: string}
     */
    private static function shopRow(int $idShop, int $idShopGroup, string $domain, string $domainSsl, string $uri): array
    {
        return [
            'id_shop' => $idShop,
            'id_shop_group' => $idShopGroup,
            'domain' => $domain,
            'domain_ssl' => $domainSsl,
            'uri' => $uri,
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function stringValue($value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param string $message
     * @param int $severity
     * @return void
     */
    private static function log(string $message, int $severity): void
    {
        try {
            PrestaShopLogger::addLog(
                'Kiyoh health: ' . $message,
                $severity,
                null,
                'KiyohMobileHealthReporter',
                null,
                true
            );
        } catch (Throwable $e) {
            // Logging must not break the shop.
        }
    }
}

/**
 * Collects response headers for one health request.
 */
class KiyohHealthHeaderCollector
{
    /** @var string[] */
    public $lines = [];

    /**
     * @param mixed $curl
     * @param string $headerLine
     * @return int
     */
    public function add($curl, $headerLine): int
    {
        $this->lines[] = (string) $headerLine;

        return strlen((string) $headerLine);
    }
}

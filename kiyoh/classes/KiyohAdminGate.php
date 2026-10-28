<?php
/**
 * Back-office CSRF rules for the Connect button.
 *
 * PrestaShop 1.7 and 8 authorize AdminModules with a legacy `token`
 * (md5 of the cookie key, tab name, tab id, and employee id).
 * PrestaShop 8/9 Symfony routes authorize the same page with `_token`.
 * On PrestaShop 9, Tools::getAdminToken() returns that Symfony token and
 * ignores the tab and employee string, so comparing only
 * Tools::getValue('token') with Tools::getAdminTokenLite() rejects a
 * request the back office has already accepted.
 *
 * No PrestaShop classes. The module passes in the request and the tokens
 * PrestaShop generated.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Decides whether a configure-page request may start or stop Connect.
 */
class KiyohAdminGate
{
    /**
     * Token already on the configure page. Symfony uses _token. Legacy uses token.
     *
     * @param array<string, mixed> $query
     */
    public static function pageToken(array $query): string
    {
        foreach (['_token', 'token'] as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && self::isTokenShape($query[$name])) {
                return $query[$name];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public static function providedTokens(array $query, array $body): array
    {
        $provided = [];
        foreach ([$body, $query] as $source) {
            foreach (['token', '_token'] as $name) {
                if (!isset($source[$name]) || !is_string($source[$name])) {
                    continue;
                }
                if (!self::isTokenShape($source[$name])) {
                    continue;
                }
                if (!in_array($source[$name], $provided, true)) {
                    $provided[] = $source[$name];
                }
            }
        }

        return $provided;
    }

    /**
     * @param list<string> $provided
     * @param list<string> $expected Server-generated tokens only.
     */
    public static function accepts(array $provided, array $expected): bool
    {
        foreach ($provided as $given) {
            if (!is_string($given) || !self::isTokenShape($given)) {
                continue;
            }
            foreach ($expected as $wanted) {
                if (!is_string($wanted) || !self::isTokenShape($wanted)) {
                    continue;
                }
                if (strlen($given) === strlen($wanted) && hash_equals($wanted, $given)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Legacy AdminModules token used by PrestaShop 1.7 and 8.
     * PrestaShop 9's Tools::getAdminToken() no longer returns this value.
     * On 8.2 and 9 the modules tab class is AdminModulesSf, so callers must
     * try that name as well as AdminModules.
     */
    public static function legacyToken(string $tab, int $tabId, int $employeeId, string $cookieKey): string
    {
        if ($employeeId <= 0 || $cookieKey === '' || $tab === '') {
            return '';
        }

        return md5($cookieKey . $tab . $tabId . $employeeId);
    }

    /**
     * Token PrestaShop 8's Symfony listener accepts in the query: md5(cookie key + employee id).
     * It is not the AdminModules tab token.
     */
    public static function employeeToken(int $employeeId, string $cookieKey): string
    {
        if ($employeeId <= 0 || $cookieKey === '') {
            return '';
        }

        return md5($cookieKey . $employeeId);
    }

    /**
     * Hidden-field values for Connect.
     *
     * `_token` is only the Symfony user CSRF token. Putting the legacy md5 in
     * `_token` makes PrestaShop 8's URL listener reject the request. The legacy
     * value stays in `token`, which AdminModules::checkToken reads.
     *
     * @return array{token: string, symfonyToken: string}
     */
    public static function formTokens(string $pageToken, string $legacyToken, string $symfonyUserToken): array
    {
        $legacy = self::isTokenShape($legacyToken) ? $legacyToken : '';
        $symfony = self::isTokenShape($symfonyUserToken) ? $symfonyUserToken : '';
        if ($symfony === '' && self::isTokenShape($pageToken) && $pageToken !== $legacy) {
            $symfony = $pageToken;
        }
        $token = $legacy !== '' ? $legacy : (self::isTokenShape($pageToken) ? $pageToken : $symfony);

        return ['token' => $token, 'symfonyToken' => $symfony];
    }

    /**
     * Path and query of the configure page that is already open.
     *
     * Keeps the host and scheme the browser used. A relative index.php action
     * resolves against /configure/{module} and posts to a different route than
     * the one whose token PrestaShop checked. When the route lives in PATH_INFO,
     * REQUEST_URI alone is only /index.php.
     */
    public static function configureRequestUri(
        string $requestUri,
        string $scriptName,
        string $pathInfo,
        string $queryString,
        string $module
    ): string {
        $uri = self::withQuery(self::safeRequestUri($requestUri), $queryString);
        if ($uri !== '' && self::isConfigureUri($uri, $module)) {
            return $uri;
        }
        $script = self::safeRequestUri($scriptName);
        $path = self::safeRequestUri($pathInfo);
        if ($script === '' || $path === '' || !self::isConfigureUri($path, $module)) {
            return '';
        }
        $built = self::withQuery($script . $path, $queryString);

        return self::isConfigureUri($built, $module) ? $built : '';
    }

    /**
     * First public host in X-Forwarded-Host. A comma-separated list is not a host.
     */
    public static function publicHost(string $forwardedHost, string $httpHost): string
    {
        foreach ([$forwardedHost, $httpHost] as $candidate) {
            $first = trim(explode(',', $candidate)[0]);
            if (preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $first) === 1) {
                return $first;
            }
        }

        return '';
    }

    /**
     * HTTPS when the tunnel's forwarded proto is https, including "https,http".
     *
     * @param array<string, mixed> $server
     */
    public static function requestIsHttps(array $server): bool
    {
        $proto = isset($server['HTTP_X_FORWARDED_PROTO']) ? (string) $server['HTTP_X_FORWARDED_PROTO'] : '';
        $first = strtolower(trim(explode(',', $proto)[0]));
        if ($first === 'https') {
            return true;
        }
        foreach (['HTTPS', 'SSL', 'REDIRECT_HTTPS', 'HTTP_SSL'] as $key) {
            if (!isset($server[$key])) {
                continue;
            }
            $value = strtolower((string) $server[$key]);
            if ($value === 'on' || $value === '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * Configure URL for the Connect form.
     *
     * Keeps the query string of the page that is already open, so a Symfony
     * `_token` is not discarded. Falls back to the legacy index when this
     * request is not the module configure page.
     */
    public static function configureAction(
        string $requestUri,
        string $host,
        bool $https,
        string $module,
        string $legacyIndex
    ): string {
        $safeHost = self::publicHost($host, $host);
        $uri = self::safeRequestUri($requestUri);
        if ($safeHost === '' || $uri === '' || !self::isConfigureUri($uri, $module)) {
            return $legacyIndex;
        }
        if (strpos($uri, 'configure=' . $module) === false) {
            $uri .= (strpos($uri, '?') === false ? '?' : '&') . 'configure=' . rawurlencode($module);
        }

        return ($https ? 'https://' : 'http://') . $safeHost . $uri;
    }

    /**
     * Add token and _token without replacing a token the page already has.
     */
    public static function withAdminTokens(string $url, string $token, string $symfonyToken): string
    {
        if ($url === '' || preg_match('/[\r\n\\\\]/', $url) === 1) {
            return '';
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = [];
        $base = $url;
        $qpos = strpos($url, '?');
        if ($qpos !== false) {
            $base = substr($url, 0, $qpos);
            parse_str(substr($url, $qpos + 1), $query);
        }
        if (self::isTokenShape($symfonyToken) && empty($query['_token'])) {
            $query['_token'] = $symfonyToken;
        }
        if (self::isTokenShape($token) && empty($query['token'])) {
            $query['token'] = $token;
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $base . ($qs !== '' ? '?' . $qs : '') . $fragment;
    }

    public static function isConfigureUri(string $uri, string $module): bool
    {
        if (preg_match('/^[a-z0-9_]+$/', $module) !== 1) {
            return false;
        }
        $pathMarker = '/configure/' . $module;
        $pathAt = strpos($uri, $pathMarker);
        if ($pathAt !== false) {
            $after = substr($uri, $pathAt + strlen($pathMarker), 1);
            if ($after === '' || $after === '?' || $after === '/' || $after === '&') {
                return true;
            }
        }

        return preg_match('/(?:^|[?&])configure=' . preg_quote($module, '/') . '(?:&|$)/', $uri) === 1;
    }

    public static function isTokenShape(string $value): bool
    {
        if ($value === '' || strlen($value) < 20 || strlen($value) > 128) {
            return false;
        }

        return preg_match('/[\x00-\x20\x7F]/', $value) !== 1;
    }

    private static function safeRequestUri(string $uri): string
    {
        if ($uri === '' || $uri[0] !== '/') {
            return '';
        }
        if (strlen($uri) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $uri) === 1) {
            return '';
        }

        return $uri;
    }

    private static function withQuery(string $uri, string $queryString): string
    {
        if ($uri === '' || strpos($uri, '?') !== false || $queryString === '') {
            return $uri;
        }
        if (strlen($queryString) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $queryString) === 1) {
            return $uri;
        }

        return $uri . '?' . $queryString;
    }
}

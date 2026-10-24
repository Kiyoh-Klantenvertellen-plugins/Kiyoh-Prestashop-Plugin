<?php
/**
 * Kiyoh-Connect return address: https://{shop-host}/module/kiyoh/oauth
 *
 * Connect redirects the merchant's browser here with ?code=&state=.
 * The admin who started the sign-in stored that state. This endpoint
 * does not accept a login of its own.
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'kiyoh/classes/KiyohConnect.php';
require_once _PS_MODULE_DIR_ . 'kiyoh/classes/KiyohConnectService.php';

/**
 * OAuth callback for one shop. It does not render the storefront theme.
 */
class KiyohOauthModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $content_only = true;

    /**
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->finishSignIn();
    }

    /**
     * @return void
     */
    private function finishSignIn()
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex');

        $code = $this->boundedQuery('code', 512);
        $state = $this->boundedQuery('state', 128);
        $error = $this->boundedQuery('error', 40);
        if ($state === null || ($code === null && $error === null)) {
            $this->plain('This sign-in link is not valid. Close the window and start Connect again from the module configuration.');
        }

        $result = KiyohConnectService::complete(
            $code === null ? '' : $code,
            $state,
            $error === null ? '' : $error
        );
        $adminReturn = isset($result['adminReturn']) && is_string($result['adminReturn']) ? $result['adminReturn'] : '';
        $shopHost = $this->currentShopHost();
        if ($adminReturn !== '' && $shopHost !== '' && KiyohConnect::isSafeAdminReturn($adminReturn, $shopHost)) {
            $separator = strpos($adminReturn, '?') === false ? '?' : '&';
            Tools::redirect($adminReturn . $separator . 'kiyoh_connect_done=1');
        }

        $this->plain('Sign-in finished. Close this window, open the Kiyoh Reviews module configuration, and reload the page.');
    }

    /**
     * Host of the shop that received this callback. Not the host parsed out of the return URL.
     */
    private function currentShopHost(): string
    {
        try {
            $context = Context::getContext();
            if (!$context || !isset($context->shop) || !$context->shop) {
                return '';
            }
            $ssl = isset($context->shop->domain_ssl) ? trim((string) $context->shop->domain_ssl) : '';
            if ($ssl !== '') {
                return $ssl;
            }

            return isset($context->shop->domain) ? trim((string) $context->shop->domain) : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return string|null
     */
    private function boundedQuery(string $name, int $max)
    {
        $value = Tools::getValue($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x20\x7F]/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return void
     */
    private function plain(string $message)
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Kiyoh</title><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p>';
        exit;
    }
}

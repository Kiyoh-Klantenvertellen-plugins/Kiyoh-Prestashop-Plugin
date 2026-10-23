<?php
/**
 * Daily Kiyoh Mobile health heartbeat.
 *
 * Call the module link shown on the configuration page once a day:
 * /module/kiyoh/cron?token=...
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'kiyoh/classes/KiyohMobileHealthReporter.php';

/**
 * Token-protected cron endpoint. It does not render a storefront page.
 */
class KiyohCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $content_only = true;

    /**
     * Publish due health reports and stop before the theme renders.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->runHealthCron();
    }

    /**
     * @return void
     */
    private function runHealthCron()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $provided = Tools::getValue('token');
        if (!is_string($provided)) {
            $provided = '';
        }
        $provided = trim($provided);
        $expected = Configuration::getGlobalValue(KiyohMobileHealthReporter::TOKEN_KEY);
        if (!is_string($expected)) {
            $expected = '';
        }
        $expected = trim($expected);
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            exit;
        }

        try {
            $result = KiyohMobileHealthReporter::publishDueReports();
            echo json_encode([
                'ok' => true,
                'shops' => $result['shops'],
                'posted' => $result['posted'],
                'accepted' => $result['accepted'],
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false]);
        }
        exit;
    }
}

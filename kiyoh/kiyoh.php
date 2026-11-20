<?php
/**
 * Kiyoh Reviews Module for PrestaShop
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Kiyoh extends Module
{
    /**
     * Lowest PHP version this module will install on.
     * PrestaShop 9 supports PHP 8.1+, but this module requires 8.2.
     */
    public const MIN_PHP_VERSION = '8.2.0';

    /**
     * Highest PrestaShop release this module has been tested on.
     * Must be an explicit version. Using _PS_VERSION_ makes the compliancy
     * check compare the running shop with itself, so every release looks supported.
     */
    public const TESTED_PS_VERSION_MAX = '9.2.0';

    public function __construct()
    {
        $this->name = 'kiyoh';
        $this->tab = 'advertising_marketing';
        $this->version = '1.4.2';
        $this->author = 'Kiyoh';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => self::TESTED_PS_VERSION_MAX,
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Kiyoh Reviews');
        $this->description = $this->l('Integrate your PrestaShop store with Kiyoh/Klantenvertellen review platforms for automated product sync and review invitations.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Kiyoh Reviews module?');

        if (!$this->isPhpVersionSupported()) {
            $this->warning = $this->l('Kiyoh Reviews requires PHP 8.2 or higher.');
        } elseif (!Configuration::get('KIYOH_ENABLED')) {
            $this->warning = $this->l('No configuration provided');
        }
    }

    /**
     * Install the module
     */
    public function install()
    {
        if (!$this->assertPhpVersion()) {
            return false;
        }

        // Remove any existing broken admin tab first
        $this->uninstallAdminTab();

        // actionProductSave is dispatched by Product::add() and Product::update().
        // The PrestaShop 9 product page still saves through those methods.
        // actionProductAdd is no longer dispatched by Product::add() in 9.x; it
        // stays registered for 1.7/8 shops that still fire it.
        // Hooks removed with the legacy product page and the legacy login
        // controller (actionAdminProductsController*, actionAdminLoginController*)
        // are not used. This module has no Symfony services, so the 6.4
        // container and attribute changes do not apply to it.
        return parent::install() &&
            $this->registerHook('actionProductSave') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionCronJob') &&
            $this->installDb() &&
            $this->installConfiguration();
    }

    /**
     * @return bool
     */
    private function isPhpVersionSupported()
    {
        return version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
    }

    /**
     * @return bool
     */
    private function assertPhpVersion()
    {
        if ($this->isPhpVersionSupported()) {
            return true;
        }

        $this->_errors[] = sprintf(
            $this->l('Kiyoh Reviews requires PHP %s or higher. This server is running PHP %s.'),
            self::MIN_PHP_VERSION,
            PHP_VERSION
        );

        return false;
    }

    /**
     * Uninstall the module
     */
    public function uninstall()
    {
        $this->revokeConnectOnUninstall();

        return parent::uninstall() &&
            $this->uninstallDb() &&
            $this->uninstallConfiguration() &&
            $this->uninstallAdminTab();
    }

    /**
     * Install database tables
     */
    private function installDb()
    {
        $sql = array();
        
        // Product sync tracking table
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'kiyoh_product_sync` (
            `id_sync` int(11) NOT NULL AUTO_INCREMENT,
            `id_product` int(11) NOT NULL,
            `last_sync` datetime DEFAULT NULL,
            `sync_status` varchar(20) DEFAULT \'pending\',
            `error_message` text,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_sync`),
            KEY `id_product` (`id_product`),
            KEY `sync_status` (`sync_status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Invitation tracking table
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'kiyoh_invitations` (
            `id_invitation` int(11) NOT NULL AUTO_INCREMENT,
            `id_order` int(11) NOT NULL,
            `customer_email` varchar(255) NOT NULL,
            `invitation_type` varchar(20) DEFAULT \'both\',
            `sent_at` datetime DEFAULT NULL,
            `delay_days` int(11) DEFAULT 0,
            `status` varchar(20) DEFAULT \'pending\',
            `response_data` text,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_invitation`),
            KEY `id_order` (`id_order`),
            KEY `customer_email` (`customer_email`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        foreach ($sql as $query) {
            try {
                if (Db::getInstance()->execute($query) == false) {
                    PrestaShopLogger::addLog(
                        'Kiyoh: Database installation failed for query: ' . $query,
                        3,
                        null,
                        'Kiyoh',
                        null,
                        true
                    );
                    return false;
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'Kiyoh: Database installation exception: ' . $e->getMessage(),
                    3,
                    null,
                    'Kiyoh',
                    null,
                    true
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Uninstall database tables
     */
    private function uninstallDb()
    {
        $sql = array();
        $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'kiyoh_product_sync`';
        $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'kiyoh_invitations`';

        foreach ($sql as $query) {
            if (Db::getInstance()->execute($query) == false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install default configuration
     */
    private function installConfiguration()
    {
        $config = array(
            'KIYOH_ENABLED' => false,
            'KIYOH_PLATFORM' => 'kiyoh',
            'KIYOH_LOCATION_ID' => '',
            'KIYOH_API_KEY' => '',
            'KIYOH_MOBILE_URLS' => 'https://kiyoh-mobile.com',
            'KIYOH_AUTO_SYNC' => true,
            'KIYOH_EXCLUDED_TYPES' => '',
            'KIYOH_EXCLUDED_CODES' => '',
            'KIYOH_TRIGGER_STATUS' => '',
            'KIYOH_DELAY_DAYS' => '7',
            'KIYOH_INVITATION_TYPE' => 'both',
            'KIYOH_MAX_PRODUCTS' => '5',
            'KIYOH_PRODUCT_SORT_ORDER' => 'cart_order',
            'KIYOH_EXCLUDED_GROUPS' => '',
            'KIYOH_LANGUAGE_AUTO' => true,
            'KIYOH_FALLBACK_LANGUAGE' => 'en',
        );

        foreach ($config as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        require_once dirname(__FILE__) . '/classes/KiyohMobileHealthReporter.php';
        KiyohMobileHealthReporter::ensureCronToken();
        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        if (!KiyohConnectService::ensureEncryptionKey()) {
            return false;
        }

        return true;
    }

    /**
     * Uninstall configuration
     */
    private function uninstallConfiguration()
    {
        $config = array(
            'KIYOH_ENABLED',
            'KIYOH_PLATFORM',
            'KIYOH_LOCATION_ID',
            'KIYOH_API_KEY',
            'KIYOH_MOBILE_URLS',
            'KIYOH_HEALTH_STATE',
            'KIYOH_CRON_TOKEN',
            'KIYOH_AUTO_SYNC',
            'KIYOH_EXCLUDED_TYPES',
            'KIYOH_EXCLUDED_CODES',
            'KIYOH_TRIGGER_STATUS',
            'KIYOH_DELAY_DAYS',
            'KIYOH_INVITATION_TYPE',
            'KIYOH_MAX_PRODUCTS',
            'KIYOH_PRODUCT_SORT_ORDER',
            'KIYOH_EXCLUDED_GROUPS',
            'KIYOH_LANGUAGE_AUTO',
            'KIYOH_FALLBACK_LANGUAGE',
        );

        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        $config = array_merge($config, KiyohConnectService::configurationKeys());

        foreach ($config as $key) {
            if (!Configuration::deleteByName($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook: Product Save
     */
    public function hookActionProductSave($params)
    {
        if (!Configuration::get('KIYOH_ENABLED') || !Configuration::get('KIYOH_AUTO_SYNC')) {
            return;
        }

        try {
            require_once dirname(__FILE__) . '/classes/KiyohProductSyncManager.php';
            
            $productId = isset($params['id_product']) ? (int)$params['id_product'] : 0;
            if ($productId <= 0) {
                return;
            }

            $syncManager = new KiyohProductSyncManager();
            $syncManager->handleProductUpdate($productId);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: Product save hook error: ' . $e->getMessage(),
                3,
                null,
                'Kiyoh',
                null,
                true
            );
        }
    }

    /**
     * Hook: Product Add
     */
    public function hookActionProductAdd($params)
    {
        if (!Configuration::get('KIYOH_ENABLED') || !Configuration::get('KIYOH_AUTO_SYNC')) {
            return;
        }

        try {
            require_once dirname(__FILE__) . '/classes/KiyohProductSyncManager.php';
            
            $productId = isset($params['id_product']) ? (int)$params['id_product'] : 0;
            if ($productId <= 0) {
                return;
            }

            $syncManager = new KiyohProductSyncManager();
            $syncManager->handleProductCreation($productId);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: Product add hook error: ' . $e->getMessage(),
                3,
                null,
                'Kiyoh',
                null,
                true
            );
        }
    }

    /**
     * Hook: Order Status Update
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (!Configuration::get('KIYOH_ENABLED')) {
            return;
        }

        try {
            require_once dirname(__FILE__) . '/classes/KiyohInvitationManager.php';
            
            $invitationManager = new KiyohInvitationManager();
            $invitationManager->handleOrderStatusUpdate($params);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: Order status update hook error: ' . $e->getMessage(),
                3,
                null,
                'Kiyoh',
                null,
                true
            );
        }
    }

    /**
     * Hook: Cron Job (PrestaShop cronjobs module).
     *
     * Republishes Kiyoh Mobile health when the payload changed or the last
     * accepted report is older than 24 hours. Invite scheduling is unchanged.
     */
    public function hookActionCronJob($params)
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohMobileHealthReporter.php';
            KiyohMobileHealthReporter::ensureCronToken();
            KiyohMobileHealthReporter::publishDueReports();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: health cron failed: ' . $e->getMessage(),
                3,
                null,
                'Kiyoh',
                null,
                true
            );
        }
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        if (Tools::getValue('kiyoh_connect_ajax')) {
            $this->emitConnectAjax();
        }

        $output = '';

        if (!$this->isRegisteredInHook('actionCronJob')) {
            $this->registerHook('actionCronJob');
        }

        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        $flash = KiyohConnectService::pullFlash();
        if ($flash['code'] !== '') {
            $output .= $this->renderConnectResult($flash['code'], null, $flash['retryAfter']);
        }

        if (Tools::isSubmit('kiyoh_connect_start')) {
            $output .= $this->startConnect();
        }

        if (Tools::isSubmit('kiyoh_connect_disconnect')) {
            $output .= $this->disconnectConnect();
        }

        // Handle bulk sync request
        if (Tools::isSubmit('bulk_sync')) {
            $output .= $this->processBulkSync();
        }

        return $output . $this->getBasicConfigurationForm();
    }

    /**
     * Process bulk product sync
     */
    private function processBulkSync()
    {
        try {
            if (!Configuration::get('KIYOH_ENABLED')) {
                return $this->displayError($this->l('Please enable the module first'));
            }

            require_once dirname(__FILE__) . '/classes/KiyohProductSyncManager.php';
            
            $syncManager = new KiyohProductSyncManager();
            $results = $syncManager->bulkSyncProducts();

            $message = sprintf(
                $this->l('Bulk sync completed: %d total, %d synced, %d excluded, %d failed'),
                $results['total'],
                $results['synced'],
                $results['excluded'],
                $results['failed']
            );

            if (!empty($results['errors'])) {
                $message .= '<br><br>' . $this->l('Errors:') . '<br>' . implode('<br>', array_slice($results['errors'], 0, 10));
                if (count($results['errors']) > 10) {
                    $message .= '<br>... ' . $this->l('and') . ' ' . (count($results['errors']) - 10) . ' ' . $this->l('more errors');
                }
            }

            if ($results['failed'] > 0) {
                return $this->displayWarning($message);
            }

            return $this->displayConfirmation($message);
        } catch (Exception $e) {
            return $this->displayError($this->l('Bulk sync failed: ') . $e->getMessage());
        }
    }

    /**
     * Configuration form handler
     */
    private function getBasicConfigurationForm()
    {
        $output = null;

        // Handle form submission
        if (Tools::isSubmit('submit' . $this->name)) {
            $output .= $this->processConfigurationForm();
        }

        // Handle API connection test
        if (Tools::isSubmit('test_connection')) {
            $output .= $this->testApiConnection();
        }

        return $output . $this->renderConnectPanel() . $this->displayForm();
    }

    /**
     * Process configuration form submission
     */
    private function processConfigurationForm()
    {
        $errors = array();

        // General settings
        $kiyoh_location_id = strval(Tools::getValue('KIYOH_LOCATION_ID'));
        $kiyoh_api_key = strval(Tools::getValue('KIYOH_API_KEY'));

        if (empty($kiyoh_location_id)) {
            $errors[] = $this->l('Location ID is required');
        }
        if (empty($kiyoh_api_key)) {
            $errors[] = $this->l('API Key is required');
        }

        // Validate delay days
        $delay_days = (int)Tools::getValue('KIYOH_DELAY_DAYS');
        if ($delay_days < 0) {
            $errors[] = $this->l('Delay days must be 0 or greater');
        }

        // Validate max products
        $max_products = (int)Tools::getValue('KIYOH_MAX_PRODUCTS');
        if ($max_products < 1 || $max_products > 10) {
            $errors[] = $this->l('Max products must be between 1 and 10');
        }

        require_once dirname(__FILE__) . '/classes/KiyohMobileHealth.php';
        $mobileUrlsRaw = Tools::getValue('KIYOH_MOBILE_URLS');
        if (!is_string($mobileUrlsRaw)) {
            $mobileUrlsRaw = '';
        }
        $parsedMobileUrls = KiyohMobileHealth::parseBaseUrls($mobileUrlsRaw, KiyohMobileHealth::DEFAULT_URLS);
        if (trim($mobileUrlsRaw) !== '' && $parsedMobileUrls['invalid'] !== []) {
            $errors[] = $this->l('Kiyoh Mobile URL(s) must be a comma-separated list of http(s) base URLs.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        // Save all configuration values
        $triggerStatus = Tools::getValue('KIYOH_TRIGGER_STATUS');
        $excludedGroups = Tools::getValue('KIYOH_EXCLUDED_GROUPS');
        $excludedTypes = Tools::getValue('KIYOH_EXCLUDED_TYPES');
        $previousLocationId = Configuration::get('KIYOH_LOCATION_ID');
        $previousApiKey = Configuration::get('KIYOH_API_KEY');
        $credentialsChanged = (string) $previousLocationId !== $kiyoh_location_id
            || (string) $previousApiKey !== $kiyoh_api_key;

        Configuration::updateValue('KIYOH_ENABLED', (int)Tools::getValue('KIYOH_ENABLED'));
        Configuration::updateValue('KIYOH_PLATFORM', strval(Tools::getValue('KIYOH_PLATFORM')));
        Configuration::updateValue('KIYOH_LOCATION_ID', $kiyoh_location_id);
        Configuration::updateValue('KIYOH_API_KEY', $kiyoh_api_key);
        Configuration::updateValue('KIYOH_MOBILE_URLS', implode(',', $parsedMobileUrls['urls']));
        Configuration::updateValue('KIYOH_AUTO_SYNC', (int)Tools::getValue('KIYOH_AUTO_SYNC'));
        Configuration::updateValue('KIYOH_EXCLUDED_TYPES', is_array($excludedTypes) ? implode(',', $excludedTypes) : '');
        Configuration::updateValue('KIYOH_EXCLUDED_CODES', strval(Tools::getValue('KIYOH_EXCLUDED_CODES')));
        Configuration::updateValue('KIYOH_TRIGGER_STATUS', is_array($triggerStatus) ? implode(',', $triggerStatus) : '');
        Configuration::updateValue('KIYOH_DELAY_DAYS', $delay_days);
        Configuration::updateValue('KIYOH_INVITATION_TYPE', strval(Tools::getValue('KIYOH_INVITATION_TYPE')));
        Configuration::updateValue('KIYOH_MAX_PRODUCTS', $max_products);
        Configuration::updateValue('KIYOH_PRODUCT_SORT_ORDER', strval(Tools::getValue('KIYOH_PRODUCT_SORT_ORDER')));
        Configuration::updateValue('KIYOH_EXCLUDED_GROUPS', is_array($excludedGroups) ? implode(',', $excludedGroups) : '');
        Configuration::updateValue('KIYOH_LANGUAGE_AUTO', (int)Tools::getValue('KIYOH_LANGUAGE_AUTO'));
        Configuration::updateValue('KIYOH_FALLBACK_LANGUAGE', strval(Tools::getValue('KIYOH_FALLBACK_LANGUAGE')));
        if ($credentialsChanged) {
            require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
            KiyohConnectService::markManualCredentials();
        }

        $output = $this->displayConfirmation($this->l('Settings updated'));
        $connectionError = $this->reportHealthAfterConfigSave($credentialsChanged);
        if ($connectionError !== '') {
            $output .= $this->displayWarning(
                $this->l('Settings saved, but the API connection test failed: ')
                . htmlspecialchars($connectionError, ENT_QUOTES, 'UTF-8')
            );
        }

        return $output;
    }

    /**
     * Best-effort Kiyoh Mobile health report after a successful save.
     *
     * @param bool $credentialsChanged
     * @return string
     */
    private function reportHealthAfterConfigSave($credentialsChanged)
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohMobileHealthReporter.php';

            return KiyohMobileHealthReporter::noteConfigurationSaved((bool) $credentialsChanged);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: health report after save failed: ' . $e->getMessage(),
                2,
                null,
                'Kiyoh',
                null,
                true
            );

            return '';
        }
    }

    /**
     * Get product type options for dropdown
     *
     * @return array Product type options
     */
    private function getProductTypeOptions()
    {
        return array(
            array('id_option' => 'none', 'name' => $this->l('No Exclusions')),
            array('id_option' => 'virtual', 'name' => $this->l('Virtual Products')),
            array('id_option' => 'pack', 'name' => $this->l('Pack Products')),
            array('id_option' => 'downloadable', 'name' => $this->l('Downloadable Products')),
            array('id_option' => 'combination', 'name' => $this->l('Products with Combinations')),
            array('id_option' => 'out_of_stock', 'name' => $this->l('Out of Stock Products')),
            array('id_option' => 'disabled', 'name' => $this->l('Disabled Products')),
            array('id_option' => 'no_image', 'name' => $this->l('Products without Images')),
            array('id_option' => 'no_description', 'name' => $this->l('Products without Description')),
            array('id_option' => 'no_price', 'name' => $this->l('Products with Zero Price')),
            array('id_option' => 'customizable', 'name' => $this->l('Customizable Products'))
        );
    }

    /**
     * Test API connection
     */
    private function testApiConnection()
    {
        try {
            // Check if API client class file exists
            if (!file_exists(dirname(__FILE__) . '/classes/KiyohApiClient.php')) {
                return $this->displayError(
                    $this->l('API client class file not found. Please ensure all module files are properly installed.')
                );
            }
            
            require_once dirname(__FILE__) . '/classes/KiyohApiClient.php';
            
            // Check if class exists after include
            if (!class_exists('KiyohApiClient')) {
                return $this->displayError(
                    $this->l('API client class could not be loaded.')
                );
            }
            
            $apiClient = KiyohApiClient::createFromConfig();
            $result = $apiClient->testConnection();
            
            if ($result['success']) {
                $this->reportConnectionResult(true, null);
                return $this->displayConfirmation(
                    $this->l('API connection successful!') . ' (' . 
                    $result['platform'] . ' - ' . $result['location_id'] . ')'
                );
            } else {
                $this->reportConnectionResult(false, $result['message']);
                return $this->displayError(
                    $this->l('API connection failed: ') . $result['message']
                );
            }
        } catch (Exception $e) {
            $this->reportConnectionResult(false, $e->getMessage());
            return $this->displayError(
                $this->l('API connection test failed: ') . $e->getMessage()
            );
        }
    }

    /**
     * Update Kiyoh Mobile connection health from the Test Connection button.
     *
     * @param bool $success
     * @param string|null $errorMessage
     * @return void
     */
    private function reportConnectionResult($success, $errorMessage)
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohMobileHealthReporter.php';
            KiyohMobileHealthReporter::noteConnectionResult((bool) $success, $errorMessage);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Kiyoh: connection health report failed: ' . $e->getMessage(),
                2,
                null,
                'Kiyoh',
                null,
                true
            );
        }
    }

    /**
     * Display configuration form
     */
    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $orderStatuses = OrderState::getOrderStates($this->context->language->id);
        $customerGroups = Group::getGroups($this->context->language->id);
        $languages = Language::getLanguages(true);

        $fields_form = array();

        // General Settings
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('General Settings'),
                'icon' => 'icon-cogs'
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Enable Module'),
                    'name' => 'KIYOH_ENABLED',
                    'is_bool' => true,
                    'desc' => $this->l('Enable or disable the Kiyoh module'),
                    'values' => array(
                        array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')),
                        array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled'))
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Platform'),
                    'name' => 'KIYOH_PLATFORM',
                    'desc' => $this->l('Select the review platform to use'),
                    'options' => array(
                        'query' => array(
                            array('id_option' => 'kiyoh', 'name' => 'Kiyoh.com'),
                            array('id_option' => 'klantenvertellen', 'name' => 'Klantenvertellen.nl')
                        ),
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Location ID'),
                    'name' => 'KIYOH_LOCATION_ID',
                    'size' => 20,
                    'required' => true,
                    'desc' => $this->l('Filled in when you connect, or type the Location ID yourself. Manual values stay available for existing installs.'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API Key'),
                    'name' => 'KIYOH_API_KEY',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Filled in from Connect (invite API key), or type an API key yourself. This is the fallback for existing installs.'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Kiyoh Mobile URL(s)'),
                    'name' => 'KIYOH_MOBILE_URLS',
                    'size' => 80,
                    'desc' => $this->l('Comma-separated Kiyoh Mobile base URLs. Health is posted to each one. Default: https://kiyoh-mobile.com. Add https://kiyohmobileui-staging.up.railway.app to report to staging as well.'),
                ),
                array(
                    'type' => 'html',
                    'label' => $this->l('Daily health cron'),
                    'name' => 'KIYOH_CRON_URL',
                    'html_content' => $this->renderCronHelp(),
                ),
            ),
            'buttons' => array(
                array(
                    'title' => $this->l('Test Connection'),
                    'name' => 'test_connection',
                    'type' => 'submit',
                    'class' => 'btn btn-info',
                    'icon' => 'process-icon-cogs'
                )
            )
        );

        // Product Sync Settings
        $fields_form[1]['form'] = array(
            'legend' => array(
                'title' => $this->l('Product Sync Settings'),
                'icon' => 'icon-refresh'
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Auto Sync Products'),
                    'name' => 'KIYOH_AUTO_SYNC',
                    'is_bool' => true,
                    'desc' => $this->l('Automatically sync products when they are created or updated'),
                    'values' => array(
                        array('id' => 'sync_on', 'value' => 1, 'label' => $this->l('Enabled')),
                        array('id' => 'sync_off', 'value' => 0, 'label' => $this->l('Disabled'))
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Excluded Product Types'),
                    'name' => 'KIYOH_EXCLUDED_TYPES[]',
                    'multiple' => true,
                    'desc' => $this->l('Select product types to exclude from synchronization (hold Ctrl to select multiple)'),
                    'options' => array(
                        'query' => $this->getProductTypeOptions(),
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                ),
                array(
                    'type' => 'textarea',
                    'label' => $this->l('Excluded Product Codes'),
                    'name' => 'KIYOH_EXCLUDED_CODES',
                    'desc' => $this->l('Comma-separated list of product references/SKUs to exclude'),
                    'rows' => 2,
                ),
            ),
            'buttons' => array(
                array(
                    'title' => $this->l('Bulk Sync All Products'),
                    'name' => 'bulk_sync',
                    'type' => 'submit',
                    'class' => 'btn btn-primary',
                    'icon' => 'process-icon-refresh'
                )
            )
        );

        // Review Invitation Settings
        $fields_form[2]['form'] = array(
            'legend' => array(
                'title' => $this->l('Review Invitation Settings'),
                'icon' => 'icon-envelope'
            ),
            'input' => array(
                array(
                    'type' => 'select',
                    'label' => $this->l('Trigger Order Status'),
                    'name' => 'KIYOH_TRIGGER_STATUS[]',
                    'multiple' => true,
                    'desc' => $this->l('Select order statuses that trigger review invitations (hold Ctrl to select multiple)'),
                    'options' => array(
                        'query' => $orderStatuses,
                        'id' => 'id_order_state',
                        'name' => 'name'
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Invitation Delay (days)'),
                    'name' => 'KIYOH_DELAY_DAYS',
                    'size' => 5,
                    'desc' => $this->l('Days to wait before sending invitation (0 = immediate)'),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Invitation Type'),
                    'name' => 'KIYOH_INVITATION_TYPE',
                    'desc' => $this->l('Type of review invitation to send'),
                    'options' => array(
                        'query' => array(
                            array('id_option' => 'both', 'name' => $this->l('Shop + Product Reviews')),
                            array('id_option' => 'product', 'name' => $this->l('Product Reviews Only')),
                            array('id_option' => 'shop', 'name' => $this->l('Shop Reviews Only'))
                        ),
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Max Products per Invitation'),
                    'name' => 'KIYOH_MAX_PRODUCTS',
                    'size' => 5,
                    'desc' => $this->l('Maximum products to include (1-10, recommended: 3-4)'),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Product Sort Order'),
                    'name' => 'KIYOH_PRODUCT_SORT_ORDER',
                    'desc' => $this->l('How to sort products when limiting the number'),
                    'options' => array(
                        'query' => array(
                            array('id_option' => 'cart_order', 'name' => $this->l('Cart Order (Default)')),
                            array('id_option' => 'price_desc', 'name' => $this->l('Price (High to Low)')),
                            array('id_option' => 'price_asc', 'name' => $this->l('Price (Low to High)')),
                            array('id_option' => 'name_asc', 'name' => $this->l('Name (A to Z)')),
                            array('id_option' => 'name_desc', 'name' => $this->l('Name (Z to A)')),
                            array('id_option' => 'sku_asc', 'name' => $this->l('SKU (A to Z)')),
                            array('id_option' => 'sku_desc', 'name' => $this->l('SKU (Z to A)'))
                        ),
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Excluded Customer Groups'),
                    'name' => 'KIYOH_EXCLUDED_GROUPS[]',
                    'multiple' => true,
                    'desc' => $this->l('Customer groups that should NOT receive invitations (hold Ctrl to select multiple)'),
                    'options' => array(
                        'query' => $customerGroups,
                        'id' => 'id_group',
                        'name' => 'name'
                    ),
                ),
            ),
        );

        // Language Settings
        $languageOptions = array();
        foreach ($languages as $lang) {
            $languageOptions[] = array('id_option' => $lang['iso_code'], 'name' => $lang['name']);
        }

        $fields_form[3]['form'] = array(
            'legend' => array(
                'title' => $this->l('Language Settings'),
                'icon' => 'icon-globe'
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Auto-detect Language'),
                    'name' => 'KIYOH_LANGUAGE_AUTO',
                    'is_bool' => true,
                    'desc' => $this->l('Automatically detect customer language from their profile'),
                    'values' => array(
                        array('id' => 'lang_on', 'value' => 1, 'label' => $this->l('Enabled')),
                        array('id' => 'lang_off', 'value' => 0, 'label' => $this->l('Disabled'))
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Fallback Language'),
                    'name' => 'KIYOH_FALLBACK_LANGUAGE',
                    'desc' => $this->l('Default language when auto-detection fails'),
                    'options' => array(
                        'query' => $languageOptions,
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->getConfigureFormIndex();
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current values
        $triggerStatus = Configuration::get('KIYOH_TRIGGER_STATUS');
        $excludedGroups = Configuration::get('KIYOH_EXCLUDED_GROUPS');
        $excludedTypes = Configuration::get('KIYOH_EXCLUDED_TYPES');

        $helper->fields_value['KIYOH_ENABLED'] = Configuration::get('KIYOH_ENABLED');
        $helper->fields_value['KIYOH_PLATFORM'] = Configuration::get('KIYOH_PLATFORM');
        $helper->fields_value['KIYOH_LOCATION_ID'] = Configuration::get('KIYOH_LOCATION_ID');
        $helper->fields_value['KIYOH_API_KEY'] = Configuration::get('KIYOH_API_KEY');
        $mobileUrls = Configuration::get('KIYOH_MOBILE_URLS');
        if (!is_string($mobileUrls) || trim($mobileUrls) === '') {
            $mobileUrls = 'https://kiyoh-mobile.com';
        }
        $helper->fields_value['KIYOH_MOBILE_URLS'] = $mobileUrls;
        $helper->fields_value['KIYOH_CRON_URL'] = '';
        $helper->fields_value['KIYOH_AUTO_SYNC'] = Configuration::get('KIYOH_AUTO_SYNC');
        $helper->fields_value['KIYOH_EXCLUDED_TYPES[]'] = !empty($excludedTypes) ? explode(',', $excludedTypes) : array();
        $helper->fields_value['KIYOH_EXCLUDED_CODES'] = Configuration::get('KIYOH_EXCLUDED_CODES');
        $helper->fields_value['KIYOH_TRIGGER_STATUS[]'] = !empty($triggerStatus) ? explode(',', $triggerStatus) : array();
        $helper->fields_value['KIYOH_DELAY_DAYS'] = Configuration::get('KIYOH_DELAY_DAYS');
        $helper->fields_value['KIYOH_INVITATION_TYPE'] = Configuration::get('KIYOH_INVITATION_TYPE');
        $helper->fields_value['KIYOH_MAX_PRODUCTS'] = Configuration::get('KIYOH_MAX_PRODUCTS');
        $helper->fields_value['KIYOH_PRODUCT_SORT_ORDER'] = Configuration::get('KIYOH_PRODUCT_SORT_ORDER');
        $helper->fields_value['KIYOH_EXCLUDED_GROUPS[]'] = !empty($excludedGroups) ? explode(',', $excludedGroups) : array();
        $helper->fields_value['KIYOH_LANGUAGE_AUTO'] = Configuration::get('KIYOH_LANGUAGE_AUTO');
        $helper->fields_value['KIYOH_FALLBACK_LANGUAGE'] = Configuration::get('KIYOH_FALLBACK_LANGUAGE');

        return $helper->generateForm($fields_form);
    }

    /**
     * Explain the daily health URL. The token is created the first time this is shown.
     *
     * @return string
     */
    private function renderCronHelp()
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohMobileHealthReporter.php';
            $url = KiyohMobileHealthReporter::cronUrl();
        } catch (Throwable $e) {
            $url = '';
        }

        $intro = $this->l('Schedule this URL once a day, or enable the PrestaShop cronjobs module. The report is sent again when shop health changed, or when the last accepted report is older than 24 hours. A failed report never blocks checkout or order updates.');
        if ($url === '') {
            return '<p>' . $intro . '</p>';
        }

        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<p>' . $intro . '</p><p><code>' . $safe . '</code></p>';
    }

    /**
     * Absolute action for the configuration form.
     *
     * PrestaShop 9 renders this page at
     * /index.php/improve/modules/manage/action/configure/{module}?_token=...
     * HelperForm's default action is the relative "index.php?controller=AdminModules",
     * which the browser resolves against that path and posts to the wrong URL.
     * PrestaShop 8.2 redirects that route to the legacy controller, and 1.7 never
     * uses it, so those requests keep AdminController::$currentIndex.
     *
     * The Symfony query string, including _token, stays on the action. HelperForm
     * appends "&token=", so the action must already have a query.
     *
     * @return string
     */
    private function getConfigureFormIndex()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        $legacy = AdminController::$currentIndex . '&configure=' . $this->name;
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $marker = '/configure/' . $this->name;
        if ($requestUri === '' || strpos($requestUri, $marker) === false) {
            return $legacy;
        }

        return KiyohAdminGate::configureAction(
            $requestUri,
            $this->publicRequestHost(),
            $this->requestIsHttps(),
            $this->name,
            $legacy
        );
    }

    /**
     * Host the browser used. X-Forwarded-Host may be a comma-separated list.
     *
     * @return string
     */
    private function publicRequestHost()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $public = KiyohAdminGate::publicHost($forwarded, $host);
        if ($public !== '') {
            return $public;
        }
        $fallback = Tools::getHttpHost(false);

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * HTTPS, including a tunnel that sends X-Forwarded-Proto as "https,http".
     *
     * @return bool
     */
    private function requestIsHttps()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        if (KiyohAdminGate::requestIsHttps($_SERVER)) {
            return true;
        }

        return Tools::usingSecureMode();
    }

    /**
     * Ask Connect to drop this shop's registration before local data is deleted.
     *
     * @return void
     */
    private function revokeConnectOnUninstall()
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
            KiyohConnectService::revokeBeforeUninstall();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Kiyoh Connect: uninstall revoke failed', 2, null, 'Kiyoh', null, true);
        }
    }

    /**
     * The configure page was already authorized by PrestaShop.
     * Accept the legacy AdminModules token and the Symfony _token.
     *
     * @return bool
     */
    private function isAdminRequestAuthentic()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        $provided = KiyohAdminGate::providedTokens($_GET, $_POST);
        $expected = $this->expectedAdminTokens();

        return KiyohAdminGate::accepts($provided, $expected);
    }

    /**
     * Tokens this shop would generate for the logged-in employee.
     *
     * @return list<string>
     */
    private function expectedAdminTokens()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        $expected = [];
        $generated = $this->generatedAdminToken();
        if ($generated !== '') {
            $expected[] = $generated;
        }
        if (isset($this->context->controller) && isset($this->context->controller->token)) {
            $controllerToken = (string) $this->context->controller->token;
            if ($controllerToken !== '') {
                $expected[] = $controllerToken;
            }
        }
        $symfony = $this->symfonyUserToken();
        if ($symfony !== '') {
            $expected[] = $symfony;
        }
        $employeeId = $this->adminEmployeeId();
        if ($employeeId > 0 && defined('_COOKIE_KEY_') && class_exists('Tab')) {
            $cookieKey = (string) _COOKIE_KEY_;
            // 1.7/8 use AdminModules. 8.2 and 9 install the modules tab as AdminModulesSf.
            foreach (array('AdminModules', 'AdminModulesSf') as $tab) {
                $legacy = KiyohAdminGate::legacyToken(
                    $tab,
                    (int) Tab::getIdFromClassName($tab),
                    $employeeId,
                    $cookieKey
                );
                if ($legacy !== '') {
                    $expected[] = $legacy;
                }
            }
            $employeeOnly = KiyohAdminGate::employeeToken($employeeId, $cookieKey);
            if ($employeeOnly !== '') {
                $expected[] = $employeeOnly;
            }
        }

        return $expected;
    }

    /**
     * User CSRF token PrestaShop 9 checks on the Symfony configure route.
     *
     * Tools::getAdminTokenLite() reads the legacy employee first and throws
     * when that object is missing, so it never reaches Tools::getAdminToken(),
     * which is the call that returns this token.
     *
     * @return string
     */
    private function symfonyUserToken()
    {
        try {
            if (!class_exists('PrestaShop\\PrestaShop\\Adapter\\SymfonyContainer')) {
                return '';
            }
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if (!$container || !is_object($container) || !method_exists($container, 'has')) {
                return '';
            }
            $serviceId = 'PrestaShopBundle\\Security\\Admin\\UserTokenManager';
            if (!$container->has($serviceId)) {
                return '';
            }
            $manager = $container->get($serviceId);
            if (!is_object($manager) || !method_exists($manager, 'getSymfonyToken')) {
                return '';
            }
            $token = $manager->getSymfonyToken();

            return is_string($token) ? $token : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Tools::getAdminTokenLite throws when the legacy employee is missing,
     * and on PrestaShop 9 it returns the Symfony token instead of the legacy hash.
     *
     * @return string
     */
    private function generatedAdminToken()
    {
        try {
            if (!isset($this->context->employee) || !$this->context->employee) {
                $this->restoreAdminEmployee();
            }
            if (!isset($this->context->employee) || !$this->context->employee) {
                return '';
            }
            $token = Tools::getAdminTokenLite('AdminModules');

            return is_string($token) ? $token : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return int
     */
    private function adminEmployeeId()
    {
        if (isset($this->context->employee) && $this->context->employee && (int) $this->context->employee->id > 0) {
            return (int) $this->context->employee->id;
        }
        $this->restoreAdminEmployee();
        if (isset($this->context->employee) && $this->context->employee) {
            return (int) $this->context->employee->id;
        }

        return 0;
    }

    /**
     * Symfony configure requests can leave Context->employee empty.
     * The admin cookie still names the employee on 1.7 through 9.
     *
     * @return void
     */
    private function restoreAdminEmployee()
    {
        if (!isset($this->context->cookie) || !$this->context->cookie || !isset($this->context->cookie->id_employee)) {
            return;
        }
        $employeeId = (int) $this->context->cookie->id_employee;
        if ($employeeId <= 0 || !class_exists('Employee')) {
            return;
        }
        try {
            $employee = new Employee($employeeId);
            if (Validate::isLoadedObject($employee)) {
                $this->context->employee = $employee;
            }
        } catch (Throwable $e) {
            return;
        }
    }

    /**
     * @return array{ok: bool, authorizeUrl?: string, message?: string, code?: string, detail?: string|null, retryAfter?: int|null}
     */
    private function connectAttempt()
    {
        if (!$this->isAdminRequestAuthentic()) {
            return [
                'ok' => false,
                'message' => $this->l('The admin token is missing or expired. Reload this page and try again.'),
            ];
        }
        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        $platform = Configuration::get('KIYOH_PLATFORM');
        if (!is_string($platform)) {
            $platform = '';
        }
        $result = KiyohConnectService::begin($platform, $this->absoluteAdminReturn());
        $issuer = KiyohConnect::issuerForPlatform($platform);
        $authorizeUrl = isset($result['authorizeUrl']) && is_string($result['authorizeUrl']) ? $result['authorizeUrl'] : '';
        if (empty($result['ok']) || $issuer === null || strpos($authorizeUrl, $issuer . '/authorize?') !== 0) {
            $code = isset($result['code']) && is_string($result['code']) ? $result['code'] : 'network';
            $retryAfter = isset($result['retryAfter']) ? (int) $result['retryAfter'] : null;

            return [
                'ok' => false,
                'code' => $code,
                'message' => $this->connectMessage($code, $retryAfter),
                'detail' => isset($result['detail']) && is_string($result['detail']) ? $result['detail'] : null,
                'retryAfter' => $retryAfter,
            ];
        }

        return ['ok' => true, 'authorizeUrl' => $authorizeUrl];
    }

    /**
     * @return string
     */
    private function startConnect()
    {
        $attempt = $this->connectAttempt();
        if (!empty($attempt['ok']) && !empty($attempt['authorizeUrl'])) {
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store');
            Tools::redirect($attempt['authorizeUrl']);

            return '';
        }
        if (isset($attempt['code'])) {
            return $this->renderConnectResult(
                $attempt['code'],
                isset($attempt['detail']) ? $attempt['detail'] : null,
                isset($attempt['retryAfter']) ? $attempt['retryAfter'] : null
            );
        }

        return $this->displayError(
            isset($attempt['message']) ? $attempt['message'] : $this->l('The admin token is missing or expired. Reload this page and try again.')
        );
    }

    /**
     * JSON for the configure page. The popup then navigates to the issuer.
     * exit is required so the Symfony layout does not wrap the body.
     *
     * @return void
     */
    private function emitConnectAjax()
    {
        $attempt = $this->connectAttempt();
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $payload = ['ok' => !empty($attempt['ok'])];
        if (!empty($attempt['authorizeUrl']) && is_string($attempt['authorizeUrl'])) {
            $payload['authorizeUrl'] = $attempt['authorizeUrl'];
        }
        if (empty($attempt['ok'])) {
            $payload['message'] = isset($attempt['message']) && is_string($attempt['message'])
                ? $attempt['message']
                : $this->l('Kiyoh-Connect sign-in could not be completed.');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return string
     */
    private function disconnectConnect()
    {
        if (!$this->isAdminRequestAuthentic()) {
            return $this->displayError($this->l('The admin token is missing or expired. Reload this page and try again.'));
        }
        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        $result = KiyohConnectService::disconnect();

        return $this->renderConnectResult(
            isset($result['code']) && is_string($result['code']) ? $result['code'] : 'network',
            isset($result['detail']) && is_string($result['detail']) ? $result['detail'] : null,
            isset($result['retryAfter']) ? (int) $result['retryAfter'] : null
        );
    }

    /**
     * @param string $code
     * @param string|null $detail
     * @param int|null $retryAfter
     * @return string
     */
    private function renderConnectResult($code, $detail, $retryAfter)
    {
        $message = $this->connectMessage($code, $retryAfter);
        if (is_string($detail) && preg_match('/^[a-z0-9_]{1,40}$/', $detail)) {
            $message .= ' (' . $detail . ')';
        }
        if ($code === 'connected' || $code === 'disconnected') {
            return $this->displayConfirmation($message);
        }

        return $this->displayError($message);
    }

    /**
     * @param string $code
     * @param int|null $retryAfter
     * @return string
     */
    private function connectMessage($code, $retryAfter)
    {
        switch ($code) {
            case 'connected':
                return $this->l('Connected. Location ID and API key were saved for this shop.');
            case 'disconnected':
                return $this->l('Disconnected. The Kiyoh-Connect session was revoked.');
            case 'shop_credential_missing':
                return $this->l('Kiyoh-Connect did not return a shop credential. That secret is issued once and must already be stored for this shop. Manual Location ID and API key still work.');
            case 'shop_context':
                return $this->l('Select one shop in the multistore header, then connect that shop.');
            case 'https_required':
                return $this->l('This shop needs a public HTTPS domain before it can use Kiyoh-Connect.');
            case 'platform':
                return $this->l('Save the platform as Kiyoh.com or Klantenvertellen.nl, then connect.');
            case 'admin_return':
                return $this->l('Open this configuration page over HTTPS on the shop domain, then connect.');
            case 'register_failed':
                return $this->l('Kiyoh-Connect rejected the shop registration.');
            case 'access_denied':
                return $this->l('Sign-in was cancelled.');
            case 'state_mismatch':
            case 'state_expired':
            case 'no_pending':
                return $this->l('This sign-in session is no longer valid. Start Connect again.');
            case 'token_failed':
                return $this->l('Kiyoh-Connect did not return a session. Start Connect again.');
            case 'credentials_failed':
                return $this->l('Signed in, but the location credentials could not be read. Use Disconnect, then try again.');
            case 'tenant_mismatch':
                return $this->l('The account tenant does not match the platform selected for this shop. No credentials were saved.');
            case 'encryption_failed':
                return $this->l('The Connect session could not be encrypted, so nothing was saved.');
            case 'revoke_failed':
                return $this->l('Kiyoh-Connect did not revoke the session. Local credentials were left in place.');
            case 'rate_limited':
                $seconds = (int) $retryAfter;
                if ($seconds < 1) {
                    $seconds = 1;
                }

                return sprintf($this->l('Kiyoh-Connect asked this shop to wait %d seconds before trying again.'), $seconds);
            case 'network':
                return $this->l('Kiyoh-Connect could not be reached.');
            default:
                return $this->l('Kiyoh-Connect sign-in could not be completed.');
        }
    }

    /**
     * Connect controls sit above the manual settings. The saved platform picks the issuer.
     *
     * @return string
     */
    private function renderConnectPanel()
    {
        require_once dirname(__FILE__) . '/classes/KiyohConnectService.php';
        $status = KiyohConnectService::status();
        $form = $this->connectFormParts();
        $action = htmlspecialchars($form['action'], ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($form['token'], ENT_QUOTES, 'UTF-8');
        $symfonyToken = htmlspecialchars($form['symfonyToken'], ENT_QUOTES, 'UTF-8');
        $intro = $this->l('Sign in on Kiyoh-Connect. The saved platform chooses the issuer: Kiyoh.com uses connect.kiyoh.com, Klantenvertellen.nl uses connect.klantenvertellen.nl. Save the platform first. The button opens a popup. Manual Location ID and API key below remain the fallback for existing installs.');
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-signin"></i> '
            . $this->l('Connect with Kiyoh / Klantenvertellen')
            . '</div><div class="panel-body"><p>' . $intro . '</p>';
        if ($status['linked']) {
            $html .= '<p><strong>' . $this->l('Connected') . '</strong> '
                . htmlspecialchars($status['locationName'], ENT_QUOTES, 'UTF-8')
                . ' (' . $this->l('location') . ' '
                . htmlspecialchars($status['locationId'], ENT_QUOTES, 'UTF-8')
                . ', ' . $this->l('tenant') . ' '
                . htmlspecialchars($status['tenantId'], ENT_QUOTES, 'UTF-8')
                . ')</p>';
        }
        $html .= '<p><code>' . htmlspecialchars($status['redirect'] !== '' ? $status['redirect'] : $this->l('Return address is created when you connect.'), ENT_QUOTES, 'UTF-8') . '</code></p>';
        $html .= '<form method="post" action="' . $action . '" target="kiyoh-connect" data-kiyoh-connect="1" id="kiyoh-connect-form">'
            . ($token !== '' ? '<input type="hidden" name="token" value="' . $token . '">' : '')
            . ($symfonyToken !== '' ? '<input type="hidden" name="_token" value="' . $symfonyToken . '">' : '')
            . '<input type="hidden" name="kiyoh_connect_start" value="1">'
            . '<button type="submit" class="btn btn-primary" id="kiyoh-connect-start" formnovalidate>'
            . $this->l('Connect with Kiyoh / Klantenvertellen')
            . '</button></form>';
        $html .= $this->connectFormScript($form);
        if ($status['linked'] || $status['hasSession']) {
            $html .= '<form method="post" action="' . $action . '" data-kiyoh-connect="1" style="margin-top:12px">'
                . ($token !== '' ? '<input type="hidden" name="token" value="' . $token . '">' : '')
                . ($symfonyToken !== '' ? '<input type="hidden" name="_token" value="' . $symfonyToken . '">' : '')
                . '<button type="submit" name="kiyoh_connect_disconnect" class="btn btn-default" formnovalidate>'
                . $this->l('Disconnect')
                . '</button></form>';
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Post Connect back to the configure URL that is already open.
     *
     * @return array{action: string, token: string, symfonyToken: string}
     */
    private function connectFormParts()
    {
        require_once dirname(__FILE__) . '/classes/KiyohAdminGate.php';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $pathInfo = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
        $queryString = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $action = KiyohAdminGate::configureRequestUri($requestUri, $scriptName, $pathInfo, $queryString, $this->name);
        if ($action === '') {
            $action = KiyohAdminGate::configureAction(
                $requestUri,
                $this->publicRequestHost(),
                $this->requestIsHttps(),
                $this->name,
                $this->getConfigureFormIndex()
            );
        }
        $pageToken = KiyohAdminGate::pageToken($_GET);
        $symfony = $this->symfonyUserToken();
        $legacy = $this->generatedAdminToken();
        // On PrestaShop 9 getAdminTokenLite() is the user CSRF token, not the legacy hash.
        if ($symfony !== '' && $legacy === $symfony) {
            $legacy = '';
            $employeeId = $this->adminEmployeeId();
            if ($employeeId > 0 && defined('_COOKIE_KEY_') && class_exists('Tab')) {
                $legacy = KiyohAdminGate::legacyToken(
                    'AdminModules',
                    (int) Tab::getIdFromClassName('AdminModules'),
                    $employeeId,
                    (string) _COOKIE_KEY_
                );
            }
        }
        $fields = KiyohAdminGate::formTokens($pageToken, $legacy, $symfony);

        return [
            'action' => $action,
            'token' => $fields['token'],
            'symfonyToken' => $fields['symfonyToken'],
        ];
    }

    /**
     * Ask the configure page for the issuer URL, then point the popup at it.
     * The request stays in this tab, so the admin session cookie is sent.
     *
     * @param array{action: string, token: string, symfonyToken: string} $form
     * @return string
     */
    private function connectFormScript(array $form)
    {
        $config = json_encode(
            [
                'action' => $form['action'],
                'token' => $form['token'],
                'symfonyToken' => $form['symfonyToken'],
                'module' => $this->name,
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($config)) {
            return '';
        }

        return '<script>(function(){var cfg=' . $config . ';var button=document.getElementById("kiyoh-connect-start");var form=document.getElementById("kiyoh-connect-form");if(!button||!form||!cfg){return;}button.addEventListener("click",function(event){event.preventDefault();var popup=window.open("","kiyoh-connect","width=480,height=720,scrollbars=yes");var body=new URLSearchParams();body.set("kiyoh_connect_start","1");body.set("kiyoh_connect_ajax","1");if(cfg.token){body.set("token",cfg.token);}if(cfg.symfonyToken){body.set("_token",cfg.symfonyToken);}var action=window.location.pathname+window.location.search;var marker="/configure/"+(cfg.module||"");if(marker.length<12||(action.indexOf(marker)===-1&&action.indexOf("configure="+(cfg.module||""))===-1)){action=cfg.action;}form.setAttribute("action",action);fetch(action,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded","Accept":"application/json"},body:body.toString()}).then(function(response){return response.json();}).then(function(data){var url=data&&typeof data.authorizeUrl==="string"?data.authorizeUrl:"";if(url.indexOf("https://connect.kiyoh.com/authorize?")===0||url.indexOf("https://connect.klantenvertellen.nl/authorize?")===0){if(popup){popup.location=url;}else{window.location=url;}return;}var message=data&&typeof data.message==="string"?data.message:"Kiyoh-Connect could not start.";if(popup&&popup.document){popup.document.body.textContent=message;}else{window.alert(message);}}).catch(function(){if(popup&&!popup.closed){popup.close();}form.submit();});});})();</script>';
    }

    /**
     * HTTPS module-configuration URL on the shop host, including the admin token.
     *
     * @return string
     */
    private function absoluteAdminReturn()
    {
        $index = $this->connectFormParts()['action'];
        if ($index === '') {
            return '';
        }
        $shopHost = '';
        if (isset($this->context->shop)) {
            $ssl = isset($this->context->shop->domain_ssl) ? trim((string) $this->context->shop->domain_ssl) : '';
            $plain = isset($this->context->shop->domain) ? trim((string) $this->context->shop->domain) : '';
            $shopHost = $ssl !== '' ? $ssl : $plain;
        }
        $requestHost = $this->publicRequestHost();
        if ($requestHost === '' || $shopHost === '' || !$this->adminHostsMatch($requestHost, $shopHost)) {
            return '';
        }
        if (!$this->requestIsHttps()) {
            return '';
        }
        if (preg_match('#^https://#i', $index)) {
            return $index;
        }
        if (isset($index[0]) && $index[0] === '/') {
            return 'https://' . $shopHost . $index;
        }
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }

        return 'https://' . $shopHost . $dir . '/' . ltrim($index, '/');
    }

    /**
     * Shop domain and the request host, ignoring a default port on the request.
     *
     * @param string $requestHost
     * @param string $shopHost
     * @return bool
     */
    private function adminHostsMatch($requestHost, $shopHost)
    {
        $request = strtolower($requestHost);
        $shop = strtolower($shopHost);
        if ($request === $shop) {
            return true;
        }
        $requestName = preg_replace('/:(?:80|443)$/', '', $request);
        $shopName = preg_replace('/:(?:80|443)$/', '', $shop);

        return is_string($requestName) && $requestName !== '' && $requestName === $shopName;
    }

    /**
     * Install admin tab - disabled, using module configure instead
     */
    private function installAdminTab()
    {
        // Not installing admin tab - configuration is handled via module configure page
        return true;
    }

    /**
     * Uninstall admin tab
     */
    private function uninstallAdminTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminKiyoh');
        
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        
        return true;
    }
}
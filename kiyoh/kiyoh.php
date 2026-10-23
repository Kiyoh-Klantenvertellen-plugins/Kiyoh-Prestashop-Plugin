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
        $this->version = '1.3.0';
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
        $output = '';

        if (!$this->isRegisteredInHook('actionCronJob')) {
            $this->registerHook('actionCronJob');
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

        return $output . $this->displayForm();
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
                    'desc' => $this->l('Your Kiyoh/Klantenvertellen Location ID'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API Key'),
                    'name' => 'KIYOH_API_KEY',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Your Kiyoh/Klantenvertellen API Key (X-Publication-Api-Token)'),
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
     * /index.php/improve/modules/manage/action/configure/{module}. HelperForm's
     * default action is the relative "index.php?controller=AdminModules", which
     * the browser resolves against that path and posts to the wrong URL, so
     * Save never reaches this module. PrestaShop 8.2 redirects that route to
     * the legacy controller, and 1.7 never uses it, so those requests keep
     * AdminController::$currentIndex.
     *
     * @return string
     */
    private function getConfigureFormIndex()
    {
        $legacy = AdminController::$currentIndex . '&configure=' . $this->name;
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $marker = '/configure/' . $this->name;
        if ($requestUri === '' || strpos($requestUri, $marker) === false) {
            return $legacy;
        }

        $path = strtok($requestUri, '?');
        if (!is_string($path) || $path === '') {
            return $legacy;
        }

        $host = Tools::getHttpHost(false);
        if (!is_string($host) || $host === '') {
            return $legacy;
        }

        $scheme = Tools::usingSecureMode() ? 'https://' : 'http://';

        // HelperForm appends "&token=", so the action must already have a query.
        return $scheme . $host . $path . '?configure=' . $this->name;
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
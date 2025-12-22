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
    public function __construct()
    {
        $this->name = 'kiyoh';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Kiyoh';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Kiyoh Reviews');
        $this->description = $this->l('Integrate your PrestaShop store with Kiyoh/Klantenvertellen review platforms for automated product sync and review invitations.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Kiyoh Reviews module?');

        if (!Configuration::get('KIYOH_ENABLED')) {
            $this->warning = $this->l('No configuration provided');
        }
    }

    /**
     * Install the module
     */
    public function install()
    {
        // Remove any existing broken admin tab first
        $this->uninstallAdminTab();
        
        return parent::install() &&
            $this->registerHook('actionProductSave') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionCronJob') &&
            $this->installDb() &&
            $this->installConfiguration();
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
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

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
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

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
            'KIYOH_AUTO_SYNC' => true,
            'KIYOH_EXCLUDED_TYPES' => '',
            'KIYOH_EXCLUDED_CODES' => '',
            'KIYOH_TRIGGER_STATUS' => '',
            'KIYOH_DELAY_DAYS' => '7',
            'KIYOH_INVITATION_TYPE' => 'both',
            'KIYOH_MAX_PRODUCTS' => '5',
            'KIYOH_PRODUCT_SORT_ORDER' => 'price_desc',
            'KIYOH_EXCLUDED_GROUPS' => '',
            'KIYOH_LANGUAGE_AUTO' => true,
            'KIYOH_FALLBACK_LANGUAGE' => 'en',
        );

        foreach ($config as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
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

        // Product sync will be implemented in task 4
        // For now, just log that the hook was triggered
        PrestaShopLogger::addLog(
            'Kiyoh: Product save hook triggered for product ID: ' . $params['id_product'],
            1,
            null,
            'Kiyoh',
            null,
            true
        );
    }

    /**
     * Hook: Product Add
     */
    public function hookActionProductAdd($params)
    {
        if (!Configuration::get('KIYOH_ENABLED') || !Configuration::get('KIYOH_AUTO_SYNC')) {
            return;
        }

        // Product sync will be implemented in task 4
        // For now, just log that the hook was triggered
        PrestaShopLogger::addLog(
            'Kiyoh: Product add hook triggered for product ID: ' . $params['id_product'],
            1,
            null,
            'Kiyoh',
            null,
            true
        );
    }

    /**
     * Hook: Order Status Update
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (!Configuration::get('KIYOH_ENABLED')) {
            return;
        }

        // Invitation logic will be implemented in task 5
        // For now, just log that the hook was triggered
        PrestaShopLogger::addLog(
            'Kiyoh: Order status update hook triggered for order ID: ' . $params['id_order'],
            1,
            null,
            'Kiyoh',
            null,
            true
        );
    }

    /**
     * Hook: Cron Job
     */
    public function hookActionCronJob($params)
    {
        if (!Configuration::get('KIYOH_ENABLED')) {
            return;
        }

        // Cron functionality will be implemented in task 6
        // For now, just log that the hook was triggered
        PrestaShopLogger::addLog(
            'Kiyoh: Cron job hook triggered',
            1,
            null,
            'Kiyoh',
            null,
            true
        );
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        return $this->getBasicConfigurationForm();
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

        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        // Save all configuration values
        $triggerStatus = Tools::getValue('KIYOH_TRIGGER_STATUS');
        $excludedGroups = Tools::getValue('KIYOH_EXCLUDED_GROUPS');

        Configuration::updateValue('KIYOH_ENABLED', (int)Tools::getValue('KIYOH_ENABLED'));
        Configuration::updateValue('KIYOH_PLATFORM', strval(Tools::getValue('KIYOH_PLATFORM')));
        Configuration::updateValue('KIYOH_LOCATION_ID', $kiyoh_location_id);
        Configuration::updateValue('KIYOH_API_KEY', $kiyoh_api_key);
        Configuration::updateValue('KIYOH_AUTO_SYNC', (int)Tools::getValue('KIYOH_AUTO_SYNC'));
        Configuration::updateValue('KIYOH_EXCLUDED_TYPES', strval(Tools::getValue('KIYOH_EXCLUDED_TYPES')));
        Configuration::updateValue('KIYOH_EXCLUDED_CODES', strval(Tools::getValue('KIYOH_EXCLUDED_CODES')));
        Configuration::updateValue('KIYOH_TRIGGER_STATUS', is_array($triggerStatus) ? implode(',', $triggerStatus) : '');
        Configuration::updateValue('KIYOH_DELAY_DAYS', $delay_days);
        Configuration::updateValue('KIYOH_INVITATION_TYPE', strval(Tools::getValue('KIYOH_INVITATION_TYPE')));
        Configuration::updateValue('KIYOH_MAX_PRODUCTS', $max_products);
        Configuration::updateValue('KIYOH_PRODUCT_SORT_ORDER', strval(Tools::getValue('KIYOH_PRODUCT_SORT_ORDER')));
        Configuration::updateValue('KIYOH_EXCLUDED_GROUPS', is_array($excludedGroups) ? implode(',', $excludedGroups) : '');
        Configuration::updateValue('KIYOH_LANGUAGE_AUTO', (int)Tools::getValue('KIYOH_LANGUAGE_AUTO'));
        Configuration::updateValue('KIYOH_FALLBACK_LANGUAGE', strval(Tools::getValue('KIYOH_FALLBACK_LANGUAGE')));

        return $this->displayConfirmation($this->l('Settings updated'));
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
                return $this->displayConfirmation(
                    $this->l('API connection successful!') . ' (' . 
                    $result['platform'] . ' - ' . $result['location_id'] . ')'
                );
            } else {
                return $this->displayError(
                    $this->l('API connection failed: ') . $result['message']
                );
            }
        } catch (Exception $e) {
            return $this->displayError(
                $this->l('API connection test failed: ') . $e->getMessage()
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
                    'type' => 'textarea',
                    'label' => $this->l('Excluded Product Types'),
                    'name' => 'KIYOH_EXCLUDED_TYPES',
                    'desc' => $this->l('Comma-separated list of product types to exclude (e.g., virtual, pack)'),
                    'rows' => 2,
                ),
                array(
                    'type' => 'textarea',
                    'label' => $this->l('Excluded Product Codes'),
                    'name' => 'KIYOH_EXCLUDED_CODES',
                    'desc' => $this->l('Comma-separated list of product references/SKUs to exclude'),
                    'rows' => 2,
                ),
            ),
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
                            array('id_option' => 'product', 'name' => $this->l('Product Reviews Only'))
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
                            array('id_option' => 'price_desc', 'name' => $this->l('Price (High to Low)')),
                            array('id_option' => 'price_asc', 'name' => $this->l('Price (Low to High)')),
                            array('id_option' => 'name_asc', 'name' => $this->l('Name (A-Z)')),
                            array('id_option' => 'name_desc', 'name' => $this->l('Name (Z-A)')),
                            array('id_option' => 'random', 'name' => $this->l('Random'))
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
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
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

        $helper->fields_value['KIYOH_ENABLED'] = Configuration::get('KIYOH_ENABLED');
        $helper->fields_value['KIYOH_PLATFORM'] = Configuration::get('KIYOH_PLATFORM');
        $helper->fields_value['KIYOH_LOCATION_ID'] = Configuration::get('KIYOH_LOCATION_ID');
        $helper->fields_value['KIYOH_API_KEY'] = Configuration::get('KIYOH_API_KEY');
        $helper->fields_value['KIYOH_AUTO_SYNC'] = Configuration::get('KIYOH_AUTO_SYNC');
        $helper->fields_value['KIYOH_EXCLUDED_TYPES'] = Configuration::get('KIYOH_EXCLUDED_TYPES');
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
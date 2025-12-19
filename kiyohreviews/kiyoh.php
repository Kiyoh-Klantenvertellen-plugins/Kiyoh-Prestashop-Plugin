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
        include(dirname(__FILE__) . '/sql/install.sql');
        
        return parent::install() &&
            $this->registerHook('actionProductSave') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionCronJob') &&
            $this->installDb() &&
            $this->installConfiguration() &&
            $this->installAdminTab();
    }

    /**
     * Uninstall the module
     */
    public function uninstall()
    {
        include(dirname(__FILE__) . '/sql/uninstall.sql');
        
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
            `sync_status` varchar(20) DEFAULT "pending",
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
            `invitation_type` varchar(20) DEFAULT "both",
            `sent_at` datetime DEFAULT NULL,
            `delay_days` int(11) DEFAULT 0,
            `status` varchar(20) DEFAULT "pending",
            `response_data` text,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_invitation`),
            KEY `id_order` (`id_order`),
            KEY `customer_email` (`customer_email`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        foreach ($sql as $query) {
            if (Db::getInstance()->execute($query) == false) {
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
        // Redirect to the admin controller for full configuration interface
        $adminUrl = $this->context->link->getAdminLink('AdminKiyoh');
        Tools::redirectAdmin($adminUrl);
        
        // Fallback basic configuration if admin controller is not available
        return $this->getBasicConfigurationForm();
    }

    /**
     * Basic configuration form (fallback)
     */
    private function getBasicConfigurationForm()
    {
        $output = null;

        // Handle form submission
        if (Tools::isSubmit('submit' . $this->name)) {
            $kiyoh_enabled = strval(Tools::getValue('KIYOH_ENABLED'));
            $kiyoh_platform = strval(Tools::getValue('KIYOH_PLATFORM'));
            $kiyoh_location_id = strval(Tools::getValue('KIYOH_LOCATION_ID'));
            $kiyoh_api_key = strval(Tools::getValue('KIYOH_API_KEY'));

            if (!$kiyoh_location_id || empty($kiyoh_location_id) || !Validate::isGenericName($kiyoh_location_id)) {
                $output .= $this->displayError($this->l('Invalid Location ID value'));
            } else {
                Configuration::updateValue('KIYOH_ENABLED', $kiyoh_enabled);
                Configuration::updateValue('KIYOH_PLATFORM', $kiyoh_platform);
                Configuration::updateValue('KIYOH_LOCATION_ID', $kiyoh_location_id);
                Configuration::updateValue('KIYOH_API_KEY', $kiyoh_api_key);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        // Handle API connection test
        if (Tools::isSubmit('test_connection')) {
            $output .= $this->testApiConnection();
        }

        return $output . $this->displayForm();
    }

    /**
     * Test API connection
     */
    private function testApiConnection()
    {
        try {
            require_once dirname(__FILE__) . '/classes/KiyohApiClient.php';
            
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
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Enable Kiyoh'),
                    'name' => 'KIYOH_ENABLED',
                    'is_bool' => true,
                    'desc' => $this->l('Enable or disable the Kiyoh module'),
                    'values' => array(
                        array(
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->l('Disabled')
                        )
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Platform'),
                    'name' => 'KIYOH_PLATFORM',
                    'desc' => $this->l('Select the review platform to use'),
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 'kiyoh',
                                'name' => 'Kiyoh.com'
                            ),
                            array(
                                'id_option' => 'klantenvertellen',
                                'name' => 'Klantenvertellen.nl'
                            )
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
                    'desc' => $this->l('Your Kiyoh/Klantenvertellen API Key'),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
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

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                        '&token=' . Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['KIYOH_ENABLED'] = Configuration::get('KIYOH_ENABLED');
        $helper->fields_value['KIYOH_PLATFORM'] = Configuration::get('KIYOH_PLATFORM');
        $helper->fields_value['KIYOH_LOCATION_ID'] = Configuration::get('KIYOH_LOCATION_ID');
        $helper->fields_value['KIYOH_API_KEY'] = Configuration::get('KIYOH_API_KEY');

        return $helper->generateForm($fields_form);
    }

    /**
     * Install admin tab
     */
    private function installAdminTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminKiyoh';
        $tab->name = array();
        
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Kiyoh Reviews';
        }
        
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminParentModulesSf');
        $tab->module = $this->name;
        
        return $tab->add();
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
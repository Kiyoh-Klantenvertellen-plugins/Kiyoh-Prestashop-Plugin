<?php
/**
 * Kiyoh Admin Controller
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Only require classes if they exist to prevent fatal errors during installation
if (file_exists(dirname(__FILE__) . '/../../classes/KiyohConfigManager.php')) {
    require_once dirname(__FILE__) . '/../../classes/KiyohConfigManager.php';
}
if (file_exists(dirname(__FILE__) . '/../../classes/KiyohApiClient.php')) {
    require_once dirname(__FILE__) . '/../../classes/KiyohApiClient.php';
}

/**
 * Admin controller for Kiyoh module configuration
 */
class AdminKiyohController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        
        parent::__construct();
        
        $this->meta_title = $this->l('Kiyoh Configuration');
        $this->toolbar_title = $this->l('Kiyoh Reviews Configuration');
    }

    /**
     * Initialize content
     */
    public function initContent()
    {
        parent::initContent();
        
        // Handle AJAX requests
        if (Tools::isSubmit('ajax')) {
            $this->handleAjaxRequest();
            return;
        }
        
        // Handle form submissions
        if (Tools::isSubmit('submitKiyohConfig')) {
            $this->processConfiguration();
        }
        
        // Generate configuration form
        $this->content .= $this->renderConfigurationForm();
        
        // Add JavaScript for dynamic form behavior
        $this->addJS(_MODULE_DIR_ . 'kiyoh/views/js/admin-config.js');
        $this->addCSS(_MODULE_DIR_ . 'kiyoh/views/css/admin-config.css');
    }

    /**
     * Handle AJAX requests
     */
    private function handleAjaxRequest()
    {
        $action = Tools::getValue('action');
        
        switch ($action) {
            case 'test_connection':
                $this->ajaxTestConnection();
                break;
            case 'get_order_statuses':
                $this->ajaxGetOrderStatuses();
                break;
            case 'get_customer_groups':
                $this->ajaxGetCustomerGroups();
                break;
            case 'get_product_types':
                $this->ajaxGetProductTypes();
                break;
            default:
                $this->ajaxDie(json_encode(['error' => 'Invalid action']));
        }
    }

    /**
     * Test API connection via AJAX
     */
    private function ajaxTestConnection()
    {
        try {
            $platform = Tools::getValue('platform');
            $locationId = Tools::getValue('location_id');
            $apiKey = Tools::getValue('api_key');
            
            if (empty($platform) || empty($locationId) || empty($apiKey)) {
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => $this->l('Please fill in all required fields')
                ]));
            }
            
            // Check if KiyohApiClient class exists
            if (!class_exists('KiyohApiClient')) {
                $this->ajaxDie(json_encode([
                    'success' => false,
                    'message' => $this->l('API client class not found. Please ensure all module files are properly installed.')
                ]));
            }
            
            $apiClient = new KiyohApiClient($platform, $locationId, $apiKey);
            $result = $apiClient->testConnection();
            
            $this->ajaxDie(json_encode($result));
            
        } catch (Exception $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    /**
     * Get order statuses via AJAX
     */
    private function ajaxGetOrderStatuses()
    {
        $orderStatuses = OrderState::getOrderStates($this->context->language->id);
        $statuses = [];
        
        foreach ($orderStatuses as $status) {
            $statuses[] = [
                'id' => $status['id_order_state'],
                'name' => $status['name']
            ];
        }
        
        $this->ajaxDie(json_encode(['statuses' => $statuses]));
    }

    /**
     * Get customer groups via AJAX
     */
    private function ajaxGetCustomerGroups()
    {
        $groups = Group::getGroups($this->context->language->id);
        $customerGroups = [];
        
        foreach ($groups as $group) {
            $customerGroups[] = [
                'id' => $group['id_group'],
                'name' => $group['name']
            ];
        }
        
        $this->ajaxDie(json_encode(['groups' => $customerGroups]));
    }

    /**
     * Get product types via AJAX
     */
    private function ajaxGetProductTypes()
    {
        // Get all unique product types from the database
        $sql = 'SELECT DISTINCT p.reference, pt.name 
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pt ON (p.id_product = pt.id_product)
                WHERE pt.id_lang = ' . (int)$this->context->language->id . '
                AND p.active = 1
                ORDER BY pt.name';
        
        $products = Db::getInstance()->executeS($sql);
        $types = [];
        
        foreach ($products as $product) {
            if (!empty($product['reference'])) {
                $types[] = [
                    'reference' => $product['reference'],
                    'name' => $product['name']
                ];
            }
        }
        
        $this->ajaxDie(json_encode(['types' => $types]));
    }

    /**
     * Process configuration form submission
     */
    private function processConfiguration()
    {
        $errors = [];
        
        // Validate and save general settings
        $enabled = (bool)Tools::getValue('KIYOH_ENABLED');
        $platform = Tools::getValue('KIYOH_PLATFORM');
        $locationId = Tools::getValue('KIYOH_LOCATION_ID');
        $apiKey = Tools::getValue('KIYOH_API_KEY');
        
        // Validate required fields
        if (empty($platform) || !in_array($platform, ['kiyoh', 'klantenvertellen'])) {
            $errors[] = $this->l('Please select a valid platform');
        }
        
        if (empty($locationId)) {
            $errors[] = $this->l('Location ID is required');
        }
        
        if (empty($apiKey)) {
            $errors[] = $this->l('API Key is required');
        }
        
        // Validate product sync settings
        $autoSync = (bool)Tools::getValue('KIYOH_AUTO_SYNC');
        $excludedTypes = Tools::getValue('KIYOH_EXCLUDED_TYPES');
        $excludedCodes = Tools::getValue('KIYOH_EXCLUDED_CODES');
        
        // Validate invitation settings
        $triggerStatus = Tools::getValue('KIYOH_TRIGGER_STATUS');
        $triggerStatusString = is_array($triggerStatus) ? implode(',', $triggerStatus) : '';
        $delayDays = (int)Tools::getValue('KIYOH_DELAY_DAYS');
        $invitationType = Tools::getValue('KIYOH_INVITATION_TYPE');
        $maxProducts = (int)Tools::getValue('KIYOH_MAX_PRODUCTS');
        $productSortOrder = Tools::getValue('KIYOH_PRODUCT_SORT_ORDER');
        $excludedGroups = Tools::getValue('KIYOH_EXCLUDED_GROUPS');
        $excludedGroupsString = is_array($excludedGroups) ? implode(',', $excludedGroups) : '';
        $languageAuto = (bool)Tools::getValue('KIYOH_LANGUAGE_AUTO');
        $fallbackLanguage = Tools::getValue('KIYOH_FALLBACK_LANGUAGE');
        
        // Validate numeric fields
        if ($delayDays < 0) {
            $errors[] = $this->l('Delay days must be a non-negative number');
        }
        
        if ($maxProducts < 1 || $maxProducts > 10) {
            $errors[] = $this->l('Maximum products must be between 1 and 10');
        }
        
        if (!empty($errors)) {
            $this->errors = $errors;
            return;
        }
        
        // Save configuration
        $config = [
            'KIYOH_ENABLED' => $enabled,
            'KIYOH_PLATFORM' => $platform,
            'KIYOH_LOCATION_ID' => $locationId,
            'KIYOH_API_KEY' => $apiKey,
            'KIYOH_AUTO_SYNC' => $autoSync,
            'KIYOH_EXCLUDED_TYPES' => $excludedTypes,
            'KIYOH_EXCLUDED_CODES' => $excludedCodes,
            'KIYOH_TRIGGER_STATUS' => $triggerStatusString,
            'KIYOH_DELAY_DAYS' => $delayDays,
            'KIYOH_INVITATION_TYPE' => $invitationType,
            'KIYOH_MAX_PRODUCTS' => $maxProducts,
            'KIYOH_PRODUCT_SORT_ORDER' => $productSortOrder,
            'KIYOH_EXCLUDED_GROUPS' => $excludedGroupsString,
            'KIYOH_LANGUAGE_AUTO' => $languageAuto,
            'KIYOH_FALLBACK_LANGUAGE' => $fallbackLanguage,
        ];
        
        $success = true;
        foreach ($config as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                $success = false;
                break;
            }
        }
        
        if ($success) {
            $this->confirmations[] = $this->l('Configuration saved successfully');
        } else {
            $this->errors[] = $this->l('Error saving configuration');
        }
    }

    /**
     * Render configuration form
     */
    private function renderConfigurationForm()
    {
        $helper = new HelperForm();
        
        // Form configuration
        $helper->module = Module::getInstanceByName('kiyoh');
        $helper->name_controller = 'AdminKiyoh';
        $helper->token = Tools::getAdminTokenLite('AdminKiyoh');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminKiyoh', false);
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = $this->context->language->id;
        $helper->title = $this->l('Kiyoh Configuration');
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submitKiyohConfig';
        
        // Load current values
        $helper->fields_value = $this->getFormValues();
        
        return $helper->generateForm($this->getFormStructure());
    }

    /**
     * Get form structure
     */
    private function getFormStructure()
    {
        $orderStatuses = OrderState::getOrderStates($this->context->language->id);
        $customerGroups = Group::getGroups($this->context->language->id);
        $languages = Language::getLanguages(true);
        
        return [
            // General Settings
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('General Settings'),
                        'icon' => 'icon-cogs'
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Enable Module'),
                            'name' => 'KIYOH_ENABLED',
                            'is_bool' => true,
                            'desc' => $this->l('Enable or disable the Kiyoh module'),
                            'values' => [
                                ['id' => 'active_on', 'value' => true, 'label' => $this->l('Enabled')],
                                ['id' => 'active_off', 'value' => false, 'label' => $this->l('Disabled')]
                            ],
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Platform'),
                            'name' => 'KIYOH_PLATFORM',
                            'desc' => $this->l('Select the review platform to use'),
                            'options' => [
                                'query' => [
                                    ['id_option' => 'kiyoh', 'name' => 'Kiyoh.com'],
                                    ['id_option' => 'klantenvertellen', 'name' => 'Klantenvertellen.nl']
                                ],
                                'id' => 'id_option',
                                'name' => 'name'
                            ],
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Location ID'),
                            'name' => 'KIYOH_LOCATION_ID',
                            'size' => 20,
                            'required' => true,
                            'desc' => $this->l('Your Kiyoh/Klantenvertellen Location ID'),
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('API Key'),
                            'name' => 'KIYOH_API_KEY',
                            'size' => 50,
                            'required' => true,
                            'desc' => $this->l('Your Kiyoh/Klantenvertellen API Key'),
                        ],
                    ],
                    'buttons' => [
                        [
                            'title' => $this->l('Test Connection'),
                            'name' => 'test_connection',
                            'type' => 'button',
                            'class' => 'btn btn-info',
                            'icon' => 'process-icon-cogs',
                            'js' => 'testApiConnection();'
                        ]
                    ]
                ]
            ],
            // Product Sync Settings
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('Product Sync Settings'),
                        'icon' => 'icon-refresh'
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Auto Sync Products'),
                            'name' => 'KIYOH_AUTO_SYNC',
                            'is_bool' => true,
                            'desc' => $this->l('Automatically sync products when they are created or updated'),
                            'values' => [
                                ['id' => 'auto_sync_on', 'value' => true, 'label' => $this->l('Enabled')],
                                ['id' => 'auto_sync_off', 'value' => false, 'label' => $this->l('Disabled')]
                            ],
                        ],
                        [
                            'type' => 'textarea',
                            'label' => $this->l('Excluded Product Types'),
                            'name' => 'KIYOH_EXCLUDED_TYPES',
                            'desc' => $this->l('Comma-separated list of product types to exclude from sync (e.g., virtual, downloadable)'),
                            'rows' => 3,
                        ],
                        [
                            'type' => 'textarea',
                            'label' => $this->l('Excluded Product Codes'),
                            'name' => 'KIYOH_EXCLUDED_CODES',
                            'desc' => $this->l('Comma-separated list of specific product codes/SKUs to exclude from sync'),
                            'rows' => 3,
                        ],
                    ]
                ]
            ],
            // Invitation Settings
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('Review Invitation Settings'),
                        'icon' => 'icon-envelope'
                    ],
                    'input' => [
                        [
                            'type' => 'select',
                            'label' => $this->l('Trigger Order Status'),
                            'name' => 'KIYOH_TRIGGER_STATUS',
                            'multiple' => true,
                            'desc' => $this->l('Select which order status changes should trigger review invitations'),
                            'options' => [
                                'query' => $orderStatuses,
                                'id' => 'id_order_state',
                                'name' => 'name'
                            ],
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Invitation Delay (days)'),
                            'name' => 'KIYOH_DELAY_DAYS',
                            'size' => 10,
                            'desc' => $this->l('Number of days to wait before sending invitation (0 = immediate)'),
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Invitation Type'),
                            'name' => 'KIYOH_INVITATION_TYPE',
                            'desc' => $this->l('Type of review invitation to send'),
                            'options' => [
                                'query' => [
                                    ['id_option' => 'both', 'name' => $this->l('Shop + Product Reviews')],
                                    ['id_option' => 'product', 'name' => $this->l('Product Reviews Only')]
                                ],
                                'id' => 'id_option',
                                'name' => 'name'
                            ],
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Max Products per Invitation'),
                            'name' => 'KIYOH_MAX_PRODUCTS',
                            'size' => 10,
                            'desc' => $this->l('Maximum number of products to include in each invitation (1-10, recommended: 3-4)'),
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Product Sort Order'),
                            'name' => 'KIYOH_PRODUCT_SORT_ORDER',
                            'desc' => $this->l('How to sort products when limiting the number included'),
                            'options' => [
                                'query' => [
                                    ['id_option' => 'price_desc', 'name' => $this->l('Price (High to Low)')],
                                    ['id_option' => 'price_asc', 'name' => $this->l('Price (Low to High)')],
                                    ['id_option' => 'name_asc', 'name' => $this->l('Name (A-Z)')],
                                    ['id_option' => 'name_desc', 'name' => $this->l('Name (Z-A)')],
                                    ['id_option' => 'random', 'name' => $this->l('Random')]
                                ],
                                'id' => 'id_option',
                                'name' => 'name'
                            ],
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Excluded Customer Groups'),
                            'name' => 'KIYOH_EXCLUDED_GROUPS',
                            'multiple' => true,
                            'desc' => $this->l('Customer groups that should not receive review invitations'),
                            'options' => [
                                'query' => $customerGroups,
                                'id' => 'id_group',
                                'name' => 'name'
                            ],
                        ],
                    ]
                ]
            ],
            // Language Settings
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('Language Settings'),
                        'icon' => 'icon-language'
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Auto-detect Language'),
                            'name' => 'KIYOH_LANGUAGE_AUTO',
                            'is_bool' => true,
                            'desc' => $this->l('Automatically detect customer language from their profile or store settings'),
                            'values' => [
                                ['id' => 'lang_auto_on', 'value' => true, 'label' => $this->l('Enabled')],
                                ['id' => 'lang_auto_off', 'value' => false, 'label' => $this->l('Disabled')]
                            ],
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Fallback Language'),
                            'name' => 'KIYOH_FALLBACK_LANGUAGE',
                            'desc' => $this->l('Default language to use when auto-detection fails'),
                            'options' => [
                                'query' => array_map(function($lang) {
                                    return [
                                        'id_option' => $lang['iso_code'],
                                        'name' => $lang['name']
                                    ];
                                }, $languages),
                                'id' => 'id_option',
                                'name' => 'name'
                            ],
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save Configuration'),
                        'class' => 'btn btn-default pull-right'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get current form values
     */
    private function getFormValues()
    {
        $triggerStatus = Configuration::get('KIYOH_TRIGGER_STATUS');
        $excludedGroups = Configuration::get('KIYOH_EXCLUDED_GROUPS');
        
        return [
            'KIYOH_ENABLED' => Configuration::get('KIYOH_ENABLED'),
            'KIYOH_PLATFORM' => Configuration::get('KIYOH_PLATFORM'),
            'KIYOH_LOCATION_ID' => Configuration::get('KIYOH_LOCATION_ID'),
            'KIYOH_API_KEY' => Configuration::get('KIYOH_API_KEY'),
            'KIYOH_AUTO_SYNC' => Configuration::get('KIYOH_AUTO_SYNC'),
            'KIYOH_EXCLUDED_TYPES' => Configuration::get('KIYOH_EXCLUDED_TYPES'),
            'KIYOH_EXCLUDED_CODES' => Configuration::get('KIYOH_EXCLUDED_CODES'),
            'KIYOH_TRIGGER_STATUS' => !empty($triggerStatus) ? explode(',', $triggerStatus) : [],
            'KIYOH_DELAY_DAYS' => Configuration::get('KIYOH_DELAY_DAYS'),
            'KIYOH_INVITATION_TYPE' => Configuration::get('KIYOH_INVITATION_TYPE'),
            'KIYOH_MAX_PRODUCTS' => Configuration::get('KIYOH_MAX_PRODUCTS'),
            'KIYOH_PRODUCT_SORT_ORDER' => Configuration::get('KIYOH_PRODUCT_SORT_ORDER'),
            'KIYOH_EXCLUDED_GROUPS' => !empty($excludedGroups) ? explode(',', $excludedGroups) : [],
            'KIYOH_LANGUAGE_AUTO' => Configuration::get('KIYOH_LANGUAGE_AUTO'),
            'KIYOH_FALLBACK_LANGUAGE' => Configuration::get('KIYOH_FALLBACK_LANGUAGE'),
            // Add template variables for Smarty
            'order_statuses' => OrderState::getOrderStates($this->context->language->id),
            'customer_groups' => Group::getGroups($this->context->language->id),
            'languages' => Language::getLanguages(true),
        ];
    }

    /**
     * Assign template variables
     */
    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        
        // Add module-specific CSS and JS
        $this->addCSS(_MODULE_DIR_ . 'kiyoh/views/css/admin-config.css');
        $this->addJS(_MODULE_DIR_ . 'kiyoh/views/js/admin-config.js');
    }
}
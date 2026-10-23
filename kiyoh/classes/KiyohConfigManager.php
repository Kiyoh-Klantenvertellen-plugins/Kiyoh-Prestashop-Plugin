<?php
/**
 * Kiyoh Configuration Manager
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Manages module configuration and settings
 */
class KiyohConfigManager
{
    /** @var array Configuration key mappings */
    const CONFIG_KEYS = [
        'KIYOH_ENABLED' => 'general.enabled',
        'KIYOH_PLATFORM' => 'general.platform',
        'KIYOH_LOCATION_ID' => 'general.location_id',
        'KIYOH_API_KEY' => 'general.api_key',
        'KIYOH_MOBILE_URLS' => 'general.mobile_urls',
        'KIYOH_AUTO_SYNC' => 'product_sync.auto_sync',
        'KIYOH_EXCLUDED_TYPES' => 'product_sync.excluded_types',
        'KIYOH_EXCLUDED_CODES' => 'product_sync.excluded_codes',
        'KIYOH_TRIGGER_STATUS' => 'invitations.trigger_status',
        'KIYOH_DELAY_DAYS' => 'invitations.delay_days',
        'KIYOH_INVITATION_TYPE' => 'invitations.invitation_type',
        'KIYOH_MAX_PRODUCTS' => 'invitations.max_products',
        'KIYOH_PRODUCT_SORT_ORDER' => 'invitations.product_sort_order',
        'KIYOH_EXCLUDED_GROUPS' => 'invitations.excluded_customer_groups',
        'KIYOH_LANGUAGE_AUTO' => 'invitations.language_auto',
        'KIYOH_FALLBACK_LANGUAGE' => 'invitations.fallback_language',
    ];

    /**
     * Get all configuration values
     *
     * @return array Configuration array
     */
    public static function getConfig(): array
    {
        $config = [];
        
        foreach (self::CONFIG_KEYS as $key => $path) {
            $config[$path] = Configuration::get($key);
        }
        
        return $config;
    }

    /**
     * Get configuration value by path
     *
     * @param string $path Configuration path (e.g., 'general.platform')
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    public static function get(string $path, $default = null)
    {
        $key = array_search($path, self::CONFIG_KEYS);
        
        if ($key === false) {
            return $default;
        }
        
        return Configuration::get($key) ?: $default;
    }

    /**
     * Set configuration value by path
     *
     * @param string $path Configuration path
     * @param mixed $value Value to set
     * @return bool Success status
     */
    public static function set(string $path, $value): bool
    {
        $key = array_search($path, self::CONFIG_KEYS);
        
        if ($key === false) {
            return false;
        }
        
        return Configuration::updateValue($key, $value);
    }

    /**
     * Check if module is enabled and configured
     *
     * @return bool Module status
     */
    public static function isEnabled(): bool
    {
        return (bool)Configuration::get('KIYOH_ENABLED') && self::isConfigured();
    }

    /**
     * Check if module is properly configured
     *
     * @return bool Configuration status
     */
    public static function isConfigured(): bool
    {
        $platform = Configuration::get('KIYOH_PLATFORM');
        $locationId = Configuration::get('KIYOH_LOCATION_ID');
        $apiKey = Configuration::get('KIYOH_API_KEY');
        
        return !empty($platform) && !empty($locationId) && !empty($apiKey);
    }

    /**
     * Get API configuration for client creation
     *
     * @return array API configuration
     * @throws Exception if configuration is invalid
     */
    public static function getApiConfig(): array
    {
        if (!self::isConfigured()) {
            throw new Exception('Kiyoh module is not properly configured');
        }
        
        return [
            'platform' => Configuration::get('KIYOH_PLATFORM'),
            'location_id' => Configuration::get('KIYOH_LOCATION_ID'),
            'api_key' => Configuration::get('KIYOH_API_KEY')
        ];
    }

    /**
     * Get product sync configuration
     *
     * @return array Product sync settings
     */
    public static function getProductSyncConfig(): array
    {
        return [
            'auto_sync' => (bool)Configuration::get('KIYOH_AUTO_SYNC'),
            'excluded_types' => self::parseCommaSeparated(Configuration::get('KIYOH_EXCLUDED_TYPES')),
            'excluded_codes' => self::parseCommaSeparated(Configuration::get('KIYOH_EXCLUDED_CODES'))
        ];
    }

    /**
     * Get invitation configuration
     *
     * @return array Invitation settings
     */
    public static function getInvitationConfig(): array
    {
        return [
            'trigger_status' => self::parseCommaSeparated(Configuration::get('KIYOH_TRIGGER_STATUS')),
            'delay_days' => (int)Configuration::get('KIYOH_DELAY_DAYS'),
            'invitation_type' => Configuration::get('KIYOH_INVITATION_TYPE'),
            'max_products' => (int)Configuration::get('KIYOH_MAX_PRODUCTS'),
            'product_sort_order' => Configuration::get('KIYOH_PRODUCT_SORT_ORDER'),
            'excluded_customer_groups' => self::parseCommaSeparated(Configuration::get('KIYOH_EXCLUDED_GROUPS')),
            'language_auto' => (bool)Configuration::get('KIYOH_LANGUAGE_AUTO'),
            'fallback_language' => Configuration::get('KIYOH_FALLBACK_LANGUAGE')
        ];
    }

    /**
     * Parse comma-separated string into array.
     *
     * Configuration::get() returns false when a key is missing. A string
     * type hint turns that into a TypeError on PHP 8.
     *
     * @param mixed $value Comma-separated string, or false/null when unset
     * @return array Parsed array
     */
    private static function parseCommaSeparated($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
    }

    /**
     * Get available product types for exclusion
     *
     * @return array Available product types with labels
     */
    public static function getAvailableProductTypes(): array
    {
        return [
            'virtual' => 'Virtual Products',
            'pack' => 'Pack Products', 
            'downloadable' => 'Downloadable Products',
            'combination' => 'Products with Combinations',
            'out_of_stock' => 'Out of Stock Products',
            'disabled' => 'Disabled Products',
            'no_image' => 'Products without Images',
            'no_description' => 'Products without Description',
            'no_price' => 'Products with Zero Price',
            'customizable' => 'Customizable Products'
        ];
    }

    /**
     * Get product categories for exclusion dropdown
     *
     * @return array Categories with ID and name
     */
    public static function getProductCategories(): array
    {
        $categories = [];
        
        try {
            $sql = new DbQuery();
            $sql->select('c.id_category, cl.name');
            $sql->from('category', 'c');
            $sql->leftJoin('category_lang', 'cl', 'c.id_category = cl.id_category AND cl.id_lang = ' . (int)Context::getContext()->language->id);
            $sql->where('c.active = 1 AND c.id_category != 1'); // Exclude root category
            $sql->orderBy('cl.name ASC');
            
            $results = Db::getInstance()->executeS($sql);
            
            foreach ($results as $row) {
                $categories[$row['id_category']] = $row['name'];
            }
        } catch (Exception $e) {
            // Log error but don't break functionality
            PrestaShopLogger::addLog('Kiyoh: Error loading categories - ' . $e->getMessage(), 3);
        }
        
        return $categories;
    }

    /**
     * Validate configuration
     *
     * @return array Validation errors (empty if valid)
     */
    public static function validate(): array
    {
        $errors = [];
        
        $platform = Configuration::get('KIYOH_PLATFORM');
        if (empty($platform) || !in_array($platform, ['kiyoh', 'klantenvertellen'])) {
            $errors[] = 'Invalid platform selection';
        }
        
        $locationId = Configuration::get('KIYOH_LOCATION_ID');
        if (empty($locationId)) {
            $errors[] = 'Location ID is required';
        }
        
        $apiKey = Configuration::get('KIYOH_API_KEY');
        if (empty($apiKey)) {
            $errors[] = 'API Key is required';
        }
        
        $delayDays = Configuration::get('KIYOH_DELAY_DAYS');
        if (!is_numeric($delayDays) || $delayDays < 0) {
            $errors[] = 'Delay days must be a non-negative number';
        }
        
        $maxProducts = Configuration::get('KIYOH_MAX_PRODUCTS');
        if (!is_numeric($maxProducts) || $maxProducts < 1 || $maxProducts > 10) {
            $errors[] = 'Max products must be between 1 and 10';
        }
        
        return $errors;
    }
}
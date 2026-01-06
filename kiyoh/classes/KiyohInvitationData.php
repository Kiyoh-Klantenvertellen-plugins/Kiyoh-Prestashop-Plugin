<?php
/**
 * Kiyoh Invitation Data Model
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Invitation data structure for Kiyoh API
 */
class KiyohInvitationData
{
    /** @var int Delay in days */
    public $delay;
    
    /** @var string Customer name */
    public $customerName;
    
    /** @var string Customer city */
    public $city;
    
    /** @var string Customer email */
    public $email;
    
    /** @var array Product codes */
    public $productCodes;
    
    /** @var string Language code */
    public $language;
    
    /** @var string Invitation type */
    public $invitationType;
    
    /** @var bool Product invite only */
    public $productInvite;

    /**
     * Create invitation data from PrestaShop Order
     *
     * @param Order $order PrestaShop order object
     * @param array $config Module configuration
     * @return KiyohInvitationData
     */
    public static function fromOrder(Order $order, array $config): KiyohInvitationData
    {
        $invitationData = new self();
        
        // Get customer information
        $customer = new Customer($order->id_customer);
        $address = new Address($order->id_address_delivery);
        
        // Basic invitation data
        $invitationData->delay = (int)($config['delay_days'] ?? 7);
        $invitationData->email = $customer->email;
        $invitationData->invitationType = $config['invitation_type'] ?? 'both';
        $invitationData->productInvite = $invitationData->invitationType === 'product';
        
        // Customer name
        $firstName = $customer->firstname ?? '';
        $lastName = $customer->lastname ?? '';
        $invitationData->customerName = trim($firstName . ' ' . $lastName);
        
        // Customer city
        $invitationData->city = $address->city ?? '';
        
        // Language detection
        $invitationData->language = self::detectLanguage($order, $config);
        
        // Product codes from order
        if ($invitationData->invitationType === 'shop') {
            // Shop only - no product codes
            $invitationData->productCodes = [];
        } else {
            // Both or product only - include product codes
            $invitationData->productCodes = self::extractProductCodes($order, $config);
        }
        
        return $invitationData;
    }

    /**
     * Convert to API array format
     *
     * @return array API-compatible array
     */
    public function toApiArray(): array
    {
        $data = [
            'invite_email' => $this->email,
            'delay' => $this->delay,
            'language' => $this->language
        ];
        
        // Add product codes only if not shop-only invitation
        if ($this->invitationType !== 'shop' && !empty($this->productCodes)) {
            $data['product_code'] = $this->productCodes;
        }
        
        // Add optional fields if they have values
        if (!empty($this->customerName)) {
            $nameParts = explode(' ', $this->customerName, 2);
            $data['first_name'] = $nameParts[0] ?? '';
            if (isset($nameParts[1])) {
                $data['last_name'] = $nameParts[1];
            }
        }
        
        if (!empty($this->city)) {
            $data['city'] = $this->city;
        }
        
        // Set product_invite flag based on invitation type
        if ($this->productInvite) {
            $data['product_invite'] = true;
        }
        
        return $data;
    }

    /**
     * Validate invitation data
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->email)) {
            $errors[] = 'Customer email is required';
        } elseif (!Validate::isEmail($this->email)) {
            $errors[] = 'Invalid email format';
        }
        
        if ($this->delay < 0) {
            $errors[] = 'Delay cannot be negative';
        }
        
        if (empty($this->language)) {
            $errors[] = 'Language is required';
        }
        
        if ($this->invitationType === 'shop') {
            // Shop only invitations don't need product codes
        } elseif (empty($this->productCodes)) {
            $errors[] = 'At least one product code is required for product or both invitation types';
        }
        
        return $errors;
    }

    /**
     * Detect customer language
     *
     * @param Order $order PrestaShop order
     * @param array $config Module configuration
     * @return string Language code
     */
    private static function detectLanguage(Order $order, array $config): string
    {
        // If auto-detection is disabled, use fallback
        if (!($config['language_auto'] ?? true)) {
            return $config['fallback_language'] ?? 'en';
        }
        
        // Try to get language from order
        if ($order->id_lang) {
            $language = new Language($order->id_lang);
            if ($language->iso_code) {
                return strtolower($language->iso_code);
            }
        }
        
        // Try to get language from customer
        $customer = new Customer($order->id_customer);
        if (Validate::isLoadedObject($customer) && $customer->id_lang) {
            $language = new Language($customer->id_lang);
            if ($language->iso_code) {
                return strtolower($language->iso_code);
            }
        }
        
        // Try to get language from delivery address country
        $address = new Address($order->id_address_delivery);
        if (Validate::isLoadedObject($address) && $address->id_country) {
            $country = new Country($address->id_country);
            if (Validate::isLoadedObject($country) && $country->id_lang) {
                $language = new Language($country->id_lang);
                if ($language->iso_code) {
                    return strtolower($language->iso_code);
                }
            }
        }
        
        // Fallback to default language
        $defaultLang = Configuration::get('PS_LANG_DEFAULT');
        if ($defaultLang) {
            $language = new Language($defaultLang);
            if ($language->iso_code) {
                return strtolower($language->iso_code);
            }
        }
        
        // Final fallback
        return $config['fallback_language'] ?? 'en';
    }

    /**
     * Extract product codes from order
     *
     * @param Order $order PrestaShop order
     * @param array $config Module configuration
     * @return array Product codes
     */
    private static function extractProductCodes(Order $order, array $config): array
    {
        $productCodes = [];
        $maxProducts = (int)($config['max_products'] ?? 5);
        $sortOrder = $config['product_sort_order'] ?? 'cart_order';
        
        // Get order details with product information
        $orderDetails = $order->getOrderDetailList();
        
        if (empty($orderDetails)) {
            return [];
        }
        
        // Build product data for sorting
        $products = [];
        foreach ($orderDetails as $detail) {
            $product = new Product($detail['product_id']);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }
            
            $productCode = $product->reference ?: $detail['product_id'];
            
            // Skip if already included
            if (isset($products[$productCode])) {
                continue;
            }
            
            $products[$productCode] = [
                'code' => $productCode,
                'name' => $product->name[Context::getContext()->language->id] ?? '',
                'price' => (float)$detail['unit_price_tax_incl'],
                'product_id' => $detail['product_id']
            ];
        }
        
        // Sort products based on configuration
        self::sortProducts($products, $sortOrder);
        
        // Extract codes up to max limit
        $count = 0;
        foreach ($products as $product) {
            if ($count >= $maxProducts) {
                break;
            }
            $productCodes[] = $product['code'];
            $count++;
        }
        
        return $productCodes;
    }
    
    /**
     * Sort products based on configuration
     *
     * @param array &$products Products array (passed by reference)
     * @param string $sortOrder Sort order configuration
     * @return void
     */
    private static function sortProducts(array &$products, string $sortOrder): void
    {
        switch ($sortOrder) {
            case 'price_desc':
                uasort($products, function($a, $b) {
                    return $b['price'] <=> $a['price'];
                });
                break;
                
            case 'price_asc':
                uasort($products, function($a, $b) {
                    return $a['price'] <=> $b['price'];
                });
                break;
                
            case 'name_asc':
                uasort($products, function($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                break;
                
            case 'name_desc':
                uasort($products, function($a, $b) {
                    return strcasecmp($b['name'], $a['name']);
                });
                break;
                
            case 'sku_asc':
                uasort($products, function($a, $b) {
                    return strcasecmp($a['code'], $b['code']);
                });
                break;
                
            case 'sku_desc':
                uasort($products, function($a, $b) {
                    return strcasecmp($b['code'], $a['code']);
                });
                break;
                
            case 'cart_order':
            default:
                // Keep original cart order - no sorting needed
                break;
        }
    }
}
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
        $invitationData->productInvite = ($config['invitation_type'] ?? 'both') === 'product_only';
        
        // Customer name
        $firstName = $customer->firstname ?? '';
        $lastName = $customer->lastname ?? '';
        $invitationData->customerName = trim($firstName . ' ' . $lastName);
        
        // Customer city
        $invitationData->city = $address->city ?? '';
        
        // Language detection
        $invitationData->language = self::detectLanguage($order, $config);
        
        // Product codes from order
        $invitationData->productCodes = self::extractProductCodes($order, $config);
        
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
            'language' => $this->language,
            'product_code' => $this->productCodes
        ];
        
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
        
        if (empty($this->productCodes)) {
            $errors[] = 'At least one product code is required';
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
        // Try to get language from order
        if ($order->id_lang) {
            $language = new Language($order->id_lang);
            if ($language->iso_code) {
                return strtolower($language->iso_code);
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
        
        // Get order details
        $orderDetails = $order->getOrderDetailList();
        
        foreach ($orderDetails as $detail) {
            if (count($productCodes) >= $maxProducts) {
                break;
            }
            
            // Get product reference or use product ID
            $product = new Product($detail['product_id']);
            $productCode = $product->reference ?: $detail['product_id'];
            
            if (!in_array($productCode, $productCodes)) {
                $productCodes[] = $productCode;
            }
        }
        
        return $productCodes;
    }
}
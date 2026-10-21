<?php
/**
 * Kiyoh Product Data Model
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Product data structure for Kiyoh API
 */
class KiyohProductData
{
    /** @var string Product name */
    public $name;
    
    /** @var string Product code/reference */
    public $code;
    
    /** @var string Product source URL */
    public $sourceUrl;
    
    /** @var string Product image URL */
    public $imageUrl;
    
    /** @var string Brand name */
    public $brandName;
    
    /** @var string SKU */
    public $sku;
    
    /** @var string GTIN */
    public $gtin;
    
    /** @var string MPN */
    public $mpn;
    
    /** @var bool Active status */
    public $active = true;

    /**
     * Create product data from PrestaShop Product
     *
     * @param Product $product PrestaShop product object
     * @param int|null $langId Language ID (optional)
     * @return KiyohProductData
     */
    public static function fromPrestaShopProduct(Product $product, ?int $langId = null): KiyohProductData
    {
        if ($langId === null) {
            $langId = (int)Configuration::get('PS_LANG_DEFAULT');
        }
        
        $productData = new self();
        
        // Basic product information. Multilingual fields are arrays when the
        // product is loaded without an id_lang, and strings when it is not.
        $productData->name = self::localizedValue($product->name, $langId);
        $productData->code = (string) ($product->reference ?: $product->id);
        $productData->active = (bool)$product->active;
        
        // URLs
        $context = Context::getContext();
        $link = $context->link;
        
        $productData->sourceUrl = $link->getProductLink(
            $product->id,
            null,
            null,
            null,
            $langId
        );
        
        // Get product image. PrestaShop 9 dropped the legacy image filesystem
        // and shops may store webp/avif (PS_IMAGE_FORMAT) instead of jpg.
        $images = Image::getImages($langId, $product->id);
        if (!empty($images)) {
            $image = array_shift($images);
            $rewrite = self::localizedValue($product->link_rewrite, $langId);
            if ($rewrite === '') {
                $rewrite = (string) $product->id;
            }
            $productData->imageUrl = self::buildImageUrl($link, $rewrite, (int) $image['id_image']);
        } else {
            $productData->imageUrl = '';
        }
        
        // Brand/Manufacturer. Manufacturer name is multilingual.
        if ($product->id_manufacturer) {
            $manufacturer = new Manufacturer($product->id_manufacturer);
            $productData->brandName = self::localizedValue($manufacturer->name, $langId);
        } else {
            $productData->brandName = '';
        }
        
        // Product identifiers. ean13 remains the legacy column; the gtin rename
        // in 9.0 applies to CQRS return values, not this object field.
        $productData->sku = (string) ($product->reference ?? '');
        $productData->gtin = (string) ($product->ean13 ?? '');
        $productData->mpn = (string) ($product->mpn ?? '');
        
        return $productData;
    }

    /**
     * Read a field that may be a plain string or a language-indexed array.
     *
     * @param mixed $value Field value
     * @param int $langId Preferred language id
     * @return string
     */
    public static function localizedValue($value, int $langId): string
    {
        if (is_array($value)) {
            if (isset($value[$langId]) && is_scalar($value[$langId])) {
                return (string) $value[$langId];
            }

            foreach ($value as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    return (string) $item;
                }
            }

            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Build an absolute product image URL for the shop's image format.
     *
     * Link::getImageLink() gained a fourth $extension argument after 1.7.
     * Passing it on older signatures is an ArgumentCountError on PHP 8.
     *
     * @param Link $link
     * @param string $rewrite
     * @param int $imageId
     * @return string
     */
    private static function buildImageUrl($link, string $rewrite, int $imageId): string
    {
        $extension = 'jpg';
        $configured = Configuration::get('PS_IMAGE_FORMAT');
        if (is_string($configured) && $configured !== '') {
            $candidate = strtolower(trim(explode(',', $configured)[0]));
            if (in_array($candidate, array('jpg', 'jpeg', 'png', 'webp', 'avif'), true)) {
                $extension = $candidate === 'jpeg' ? 'jpg' : $candidate;
            }
        }

        try {
            $method = new ReflectionMethod($link, 'getImageLink');
            if ($method->getNumberOfParameters() >= 4) {
                $url = $link->getImageLink($rewrite, $imageId, 'large_default', $extension);
            } else {
                $url = $link->getImageLink($rewrite, $imageId, 'large_default');
            }
        } catch (ReflectionException $e) {
            $url = $link->getImageLink($rewrite, $imageId, 'large_default');
        }

        return self::absoluteUrl((string) $url);
    }

    /**
     * @param string $url
     * @return string
     */
    private static function absoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        $base = '';
        if (method_exists('Tools', 'getShopDomainSsl')) {
            $base = (string) Tools::getShopDomainSsl(true);
        }

        if ($base === '') {
            return $url;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Convert to API array format
     *
     * @return array API-compatible array
     */
    public function toApiArray(): array
    {
        $data = [
            'product_name' => $this->name,
            'product_code' => $this->code,
            'source_url' => $this->sourceUrl,
            'image_url' => $this->imageUrl,
            'active' => $this->active
        ];
        
        // Add optional fields if they have values
        if (!empty($this->brandName)) {
            $data['brand_name'] = $this->brandName;
        }
        
        if (!empty($this->sku)) {
            $data['skus'] = $this->sku;
        }
        
        if (!empty($this->gtin)) {
            $data['gtins'] = $this->gtin;
        }
        
        if (!empty($this->mpn)) {
            $data['mpns'] = $this->mpn;
        }
        
        return $data;
    }

    /**
     * Validate product data
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->name)) {
            $errors[] = 'Product name is required';
        }
        
        if (empty($this->code)) {
            $errors[] = 'Product code is required';
        }
        
        if (empty($this->sourceUrl)) {
            $errors[] = 'Product source URL is required';
        }
        
        if (empty($this->imageUrl)) {
            $errors[] = 'Product image URL is required';
        }
        
        return $errors;
    }
}
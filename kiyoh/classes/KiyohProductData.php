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
    public static function fromPrestaShopProduct(Product $product, int $langId = null): KiyohProductData
    {
        if ($langId === null) {
            $langId = (int)Configuration::get('PS_LANG_DEFAULT');
        }
        
        $productData = new self();
        
        // Basic product information
        $productData->name = $product->name[$langId] ?? '';
        $productData->code = $product->reference ?? $product->id;
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
        
        // Get product image
        $images = Image::getImages($langId, $product->id);
        if (!empty($images)) {
            $image = array_shift($images);
            $productData->imageUrl = $link->getImageLink(
                $product->link_rewrite[$langId] ?? $product->id,
                $image['id_image'],
                'large_default'
            );
        } else {
            $productData->imageUrl = '';
        }
        
        // Brand/Manufacturer
        if ($product->id_manufacturer) {
            $manufacturer = new Manufacturer($product->id_manufacturer);
            $productData->brandName = $manufacturer->name ?? '';
        } else {
            $productData->brandName = '';
        }
        
        // Product identifiers
        $productData->sku = $product->reference ?? '';
        $productData->gtin = $product->ean13 ?? '';
        $productData->mpn = $product->mpn ?? '';
        
        return $productData;
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
<?php
/**
 * Kiyoh Product Sync Manager
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohApiClient.php';
require_once dirname(__FILE__) . '/KiyohProductData.php';
require_once dirname(__FILE__) . '/KiyohConfigManager.php';

/**
 * Manages product synchronization with Kiyoh/Klantenvertellen platforms
 */
class KiyohProductSyncManager
{
    /** @var KiyohApiClient API client instance */
    private $apiClient;
    
    /** @var array Product sync configuration */
    private $config;

    /**
     * Constructor
     *
     * @param KiyohApiClient|null $apiClient Optional API client instance
     */
    public function __construct(?KiyohApiClient $apiClient = null)
    {
        $this->apiClient = $apiClient;
        $this->config = KiyohConfigManager::getProductSyncConfig();
    }

    /**
     * Get or create API client
     *
     * @return KiyohApiClient
     * @throws Exception if API client cannot be created
     */
    private function getApiClient(): KiyohApiClient
    {
        if ($this->apiClient === null) {
            $this->apiClient = KiyohApiClient::createFromConfig();
        }
        return $this->apiClient;
    }

    /**
     * Sync a single product to the platform
     *
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function syncSingleProduct(int $productId): bool
    {
        try {
            // Check if product should be excluded
            if ($this->isProductExcluded($productId)) {
                $this->logInfo('Product excluded from sync', $productId);
                return true;
            }

            // Load product
            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                $this->logError('Product not found', $productId);
                return false;
            }

            // Create product data
            $productData = KiyohProductData::fromPrestaShopProduct($product);
            
            // Validate product data
            $errors = $productData->validate();
            if (!empty($errors)) {
                $this->logError('Product validation failed: ' . implode(', ', $errors), $productId);
                $this->updateSyncStatus($productId, 'error', implode(', ', $errors));
                return false;
            }

            // Send to API
            $apiData = $productData->toApiArray();
            $response = $this->getApiClient()->syncProduct($apiData);

            // Update sync status
            $this->updateSyncStatus($productId, 'synced');
            $this->logInfo('Product synced successfully', $productId);

            return true;
        } catch (Throwable $e) {
            $this->logError('Product sync failed: ' . $e->getMessage(), $productId);
            $this->updateSyncStatus($productId, 'error', $e->getMessage());
            return false;
        }
    }

    /**
     * Handle product creation hook
     *
     * @param int $productId Product ID
     */
    public function handleProductCreation(int $productId): void
    {
        if (!$this->config['auto_sync']) {
            return;
        }

        $this->syncSingleProduct($productId);
    }

    /**
     * Handle product update hook
     *
     * @param int $productId Product ID
     */
    public function handleProductUpdate(int $productId): void
    {
        if (!$this->config['auto_sync']) {
            return;
        }

        $this->syncSingleProduct($productId);
    }


    /**
     * Bulk sync all products to the platform
     *
     * @param array $filters Optional filters (limit, offset, etc.)
     * @return array Sync results with statistics
     */
    public function bulkSyncProducts(array $filters = []): array
    {
        $results = [
            'total' => 0,
            'synced' => 0,
            'excluded' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // Get products to sync
            $products = $this->getProductsToSync($filters);
            $results['total'] = count($products);

            // Prepare bulk data
            $bulkData = [];
            $productIds = [];

            foreach ($products as $productRow) {
                $productId = (int)$productRow['id_product'];

                // Check exclusions
                if ($this->isProductExcluded($productId)) {
                    $results['excluded']++;
                    continue;
                }

                try {
                    $product = new Product($productId);
                    if (!Validate::isLoadedObject($product)) {
                        $results['failed']++;
                        $results['errors'][] = "Product $productId not found";
                        continue;
                    }

                    $productData = KiyohProductData::fromPrestaShopProduct($product);
                    $errors = $productData->validate();

                    if (!empty($errors)) {
                        $results['failed']++;
                        $results['errors'][] = "Product $productId: " . implode(', ', $errors);
                        $this->updateSyncStatus($productId, 'error', implode(', ', $errors));
                        continue;
                    }

                    $bulkData[] = $productData->toApiArray();
                    $productIds[] = $productId;

                    // Send in batches of 200 (API limit)
                    if (count($bulkData) >= 200) {
                        $batchResult = $this->sendBulkBatch($bulkData, $productIds);
                        $results['synced'] += $batchResult['synced'];
                        $results['failed'] += $batchResult['failed'];
                        $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
                        $bulkData = [];
                        $productIds = [];
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Product $productId: " . $e->getMessage();
                    $this->updateSyncStatus($productId, 'error', $e->getMessage());
                }
            }

            // Send remaining products
            if (!empty($bulkData)) {
                $batchResult = $this->sendBulkBatch($bulkData, $productIds);
                $results['synced'] += $batchResult['synced'];
                $results['failed'] += $batchResult['failed'];
                $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
            }

            $this->logInfo('Bulk sync completed: ' . json_encode($results));

        } catch (Exception $e) {
            $this->logError('Bulk sync failed: ' . $e->getMessage());
            $results['errors'][] = 'Bulk sync error: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Send a batch of products to the bulk API
     *
     * @param array $bulkData Product data array
     * @param array $productIds Product IDs for status tracking
     * @return array Batch results
     */
    private function sendBulkBatch(array $bulkData, array $productIds): array
    {
        $result = [
            'synced' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // Add 3-second delay between bulk upload calls for rate limiting
            $this->logInfo('Applying 3-second rate limit delay for bulk upload batch');
            sleep(3);

            $response = $this->getApiClient()->syncProductBulk($bulkData);

            // Mark all products as synced
            foreach ($productIds as $productId) {
                $this->updateSyncStatus($productId, 'synced');
                $result['synced']++;
            }
        } catch (Exception $e) {
            // Mark all products as failed
            foreach ($productIds as $productId) {
                $this->updateSyncStatus($productId, 'error', $e->getMessage());
                $result['failed']++;
            }
            $result['errors'][] = 'Batch sync failed: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Get products to sync from database
     *
     * @param array $filters Optional filters
     * @return array Product rows
     */
    private function getProductsToSync(array $filters = []): array
    {
        $sql = new DbQuery();
        $sql->select('p.id_product');
        $sql->from('product', 'p');
        $sql->where('p.active = 1');

        // Apply limit and offset if provided
        if (isset($filters['limit'])) {
            $limit = (int)$filters['limit'];
            $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
            $sql->limit($limit, $offset);
        }

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Check if a product should be excluded from sync
     *
     * @param int $productId Product ID
     * @return bool True if excluded
     */
    public function isProductExcluded(int $productId): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return true;
        }

        // Check excluded product types
        if ($this->isProductTypeExcluded($product)) {
            return true;
        }

        // Check excluded product codes
        if ($this->isProductCodeExcluded($product)) {
            return true;
        }

        return false;
    }

    /**
     * Check if product type is excluded
     *
     * @param Product $product Product object
     * @return bool True if excluded
     */
    private function isProductTypeExcluded(Product $product): bool
    {
        $excludedTypes = $this->config['excluded_types'];
        if (empty($excludedTypes)) {
            return false;
        }

        // Filter out 'none' option if present
        $excludedTypes = array_filter($excludedTypes, function($type) {
            return $type !== 'none';
        });

        // If only 'none' was selected, no exclusions apply
        if (empty($excludedTypes)) {
            return false;
        }

        // Check for virtual products
        if (in_array('virtual', $excludedTypes) && $product->is_virtual) {
            return true;
        }

        // Check for pack products
        if (in_array('pack', $excludedTypes) && Pack::isPack($product->id)) {
            return true;
        }

        // Check for downloadable products (virtual products with attachments)
        if (in_array('downloadable', $excludedTypes) && $this->isDownloadableProduct($product)) {
            return true;
        }

        // Check for products with combinations
        if (in_array('combination', $excludedTypes) && $this->hasProductCombinations($product)) {
            return true;
        }

        // Check for out of stock products
        if (in_array('out_of_stock', $excludedTypes) && $this->isOutOfStock($product)) {
            return true;
        }

        // Check for disabled products
        if (in_array('disabled', $excludedTypes) && !$product->active) {
            return true;
        }

        // Check for products without images
        if (in_array('no_image', $excludedTypes) && $this->hasNoImages($product)) {
            return true;
        }

        // Check for products without description
        if (in_array('no_description', $excludedTypes) && $this->hasNoDescription($product)) {
            return true;
        }

        // Check for products with zero price
        if (in_array('no_price', $excludedTypes) && $this->hasZeroPrice($product)) {
            return true;
        }

        // Check for customizable products
        if (in_array('customizable', $excludedTypes) && $this->isCustomizable($product)) {
            return true;
        }

        return false;
    }

    /**
     * Check if product is downloadable (virtual with attachments)
     *
     * @param Product $product Product object
     * @return bool True if downloadable
     */
    private function isDownloadableProduct(Product $product): bool
    {
        if (!$product->is_virtual) {
            return false;
        }

        // Check if product has attachments
        try {
            $attachments = Attachment::getAttachments(Context::getContext()->language->id, $product->id);
            return !empty($attachments);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if product has combinations
     *
     * @param Product $product Product object
     * @return bool True if has combinations
     */
    private function hasProductCombinations(Product $product): bool
    {
        try {
            $combinations = $product->getAttributeCombinations(Context::getContext()->language->id);
            return !empty($combinations);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if product is out of stock
     *
     * @param Product $product Product object
     * @return bool True if out of stock
     */
    private function isOutOfStock(Product $product): bool
    {
        try {
            $quantity = StockAvailable::getQuantityAvailableByProduct($product->id);
            return $quantity <= 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if product has no images
     *
     * @param Product $product Product object
     * @return bool True if no images
     */
    private function hasNoImages(Product $product): bool
    {
        try {
            $images = Image::getImages(Context::getContext()->language->id, $product->id);
            return empty($images);
        } catch (Exception $e) {
            return true; // Assume no images if error
        }
    }

    /**
     * Check if product has no description
     *
     * @param Product $product Product object
     * @return bool True if no description
     */
    private function hasNoDescription(Product $product): bool
    {
        $langId = (int) Context::getContext()->language->id;
        // Product loaded without an id_lang stores multilingual fields as arrays.
        // trim() rejects arrays on PHP 8.
        $description = KiyohProductData::localizedValue($product->description, $langId);
        $shortDescription = KiyohProductData::localizedValue($product->description_short, $langId);

        return trim($description) === '' && trim($shortDescription) === '';
    }

    /**
     * Check if product has zero price
     *
     * @param Product $product Product object
     * @return bool True if zero price
     */
    private function hasZeroPrice(Product $product): bool
    {
        return (float)$product->price <= 0;
    }

    /**
     * Check if product is customizable
     *
     * @param Product $product Product object
     * @return bool True if customizable
     */
    private function isCustomizable(Product $product): bool
    {
        try {
            // Check if product has customization fields
            $customizationFields = Db::getInstance()->executeS('
                SELECT COUNT(*) as count 
                FROM ' . _DB_PREFIX_ . 'customization_field 
                WHERE id_product = ' . (int)$product->id . ' 
                AND active = 1
            ');
            
            return isset($customizationFields[0]['count']) && $customizationFields[0]['count'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if product code is excluded
     *
     * @param Product $product Product object
     * @return bool True if excluded
     */
    private function isProductCodeExcluded(Product $product): bool
    {
        $excludedCodes = $this->config['excluded_codes'];
        if (empty($excludedCodes)) {
            return false;
        }

        $productCode = $product->reference;
        if (empty($productCode)) {
            return false;
        }

        return in_array($productCode, $excludedCodes);
    }


    /**
     * Get list of excluded products
     *
     * @return array Excluded product IDs
     */
    public function getExcludedProducts(): array
    {
        $excludedIds = [];

        // Get all active products
        $sql = new DbQuery();
        $sql->select('p.id_product');
        $sql->from('product', 'p');
        $sql->where('p.active = 1');

        $products = Db::getInstance()->executeS($sql);

        foreach ($products as $row) {
            $productId = (int)$row['id_product'];
            if ($this->isProductExcluded($productId)) {
                $excludedIds[] = $productId;
            }
        }

        return $excludedIds;
    }

    /**
     * Update product sync status in database
     *
     * @param int $productId Product ID
     * @param string $status Sync status
     * @param string|null $errorMessage Error message if failed
     */
    private function updateSyncStatus(int $productId, string $status, ?string $errorMessage = null): void
    {
        $now = date('Y-m-d H:i:s');

        // Check if record exists
        $sql = new DbQuery();
        $sql->select('id_sync');
        $sql->from('kiyoh_product_sync');
        $sql->where('id_product = ' . $productId);

        $existing = Db::getInstance()->getValue($sql);

        if ($existing) {
            // Update existing record
            $data = [
                'sync_status' => pSQL($status),
                'date_upd' => $now
            ];

            if ($status === 'synced') {
                $data['last_sync'] = $now;
                $data['error_message'] = null;
            } elseif ($errorMessage) {
                $data['error_message'] = pSQL($errorMessage);
            }

            Db::getInstance()->update('kiyoh_product_sync', $data, 'id_product = ' . (int) $productId, 0, true);
        } else {
            // Insert new record
            $data = [
                'id_product' => $productId,
                'sync_status' => pSQL($status),
                'date_add' => $now,
                'date_upd' => $now
            ];

            if ($status === 'synced') {
                $data['last_sync'] = $now;
            } elseif ($errorMessage) {
                $data['error_message'] = pSQL($errorMessage);
            }

            Db::getInstance()->insert('kiyoh_product_sync', $data);
        }
    }

    /**
     * Get sync status for a product
     *
     * @param int $productId Product ID
     * @return array|null Sync status data or null if not found
     */
    public function getSyncStatus(int $productId): ?array
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('kiyoh_product_sync');
        $sql->where('id_product = ' . $productId);

        $result = Db::getInstance()->getRow($sql);
        return $result ?: null;
    }

    /**
     * Get products with failed sync status
     *
     * @param int $limit Maximum number of products to return
     * @return array Failed product sync records
     */
    public function getFailedSyncs(int $limit = 100): array
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('kiyoh_product_sync');
        $sql->where("sync_status = 'error'");
        $sql->orderBy('date_upd DESC');
        $sql->limit($limit);

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Retry failed product syncs
     *
     * @param int $limit Maximum number of products to retry
     * @return array Retry results
     */
    public function retryFailedSyncs(int $limit = 50): array
    {
        $results = [
            'total' => 0,
            'synced' => 0,
            'failed' => 0
        ];

        $failedSyncs = $this->getFailedSyncs($limit);
        $results['total'] = count($failedSyncs);

        foreach ($failedSyncs as $sync) {
            $productId = (int)$sync['id_product'];
            if ($this->syncSingleProduct($productId)) {
                $results['synced']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get sync statistics
     *
     * @return array Sync statistics
     */
    public function getSyncStatistics(): array
    {
        $stats = [
            'total_products' => 0,
            'synced' => 0,
            'pending' => 0,
            'error' => 0,
            'excluded' => 0
        ];

        // Get total active products
        $sql = new DbQuery();
        $sql->select('COUNT(*) as count');
        $sql->from('product');
        $sql->where('active = 1');
        $stats['total_products'] = (int)Db::getInstance()->getValue($sql);

        // Get sync status counts
        $sql = new DbQuery();
        $sql->select('sync_status, COUNT(*) as count');
        $sql->from('kiyoh_product_sync');
        $sql->groupBy('sync_status');

        $statusCounts = Db::getInstance()->executeS($sql);
        foreach ($statusCounts as $row) {
            $status = $row['sync_status'];
            if (isset($stats[$status])) {
                $stats[$status] = (int)$row['count'];
            }
        }

        // Calculate excluded products
        $stats['excluded'] = count($this->getExcludedProducts());

        return $stats;
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param int|null $productId Product ID for context
     */
    private function logInfo(string $message, ?int $productId = null): void
    {
        $logMessage = 'Kiyoh ProductSync: ' . $message;
        if ($productId) {
            $logMessage .= ' (Product ID: ' . $productId . ')';
        }

        PrestaShopLogger::addLog(
            $logMessage,
            1, // Info level
            null,
            'KiyohProductSyncManager',
            $productId,
            true
        );
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @param int|null $productId Product ID for context
     */
    private function logError(string $message, ?int $productId = null): void
    {
        $logMessage = 'Kiyoh ProductSync Error: ' . $message;
        if ($productId) {
            $logMessage .= ' (Product ID: ' . $productId . ')';
        }

        PrestaShopLogger::addLog(
            $logMessage,
            3, // Error level
            null,
            'KiyohProductSyncManager',
            $productId,
            true
        );
    }
}

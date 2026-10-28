<?php
/**
 * Kiyoh Invitation Manager
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohApiClient.php';
require_once dirname(__FILE__) . '/KiyohInvitationData.php';
require_once dirname(__FILE__) . '/KiyohConfigManager.php';
require_once dirname(__FILE__) . '/KiyohProductSyncManager.php';

/**
 * Manages automated review invitations
 */
class KiyohInvitationManager
{
    /** @var KiyohApiClient */
    private $apiClient;
    
    /** @var array */
    private $config;

    /**
     * Constructor
     *
     * @throws Exception if configuration is invalid
     */
    public function __construct()
    {
        if (!KiyohConfigManager::isEnabled()) {
            throw new Exception('Kiyoh module is not enabled or configured');
        }
        
        $this->config = KiyohConfigManager::getInvitationConfig();
        $this->apiClient = KiyohApiClient::createFromConfig();
    }

    /**
     * Send review invitation for an order
     *
     * @param Order $order PrestaShop order object
     * @return array Invitation result
     * @throws Exception on invitation errors
     */
    public function sendInvitation(Order $order): array
    {
        $attemptedInviteApi = false;
        try {
            // Check if invitation should be sent
            if (!$this->shouldSendInvitation($order)) {
                return [
                    'success' => false,
                    'message' => 'Invitation not sent - conditions not met',
                    'order_id' => $order->id
                ];
            }

            // Build invitation data
            $invitationData = $this->buildInvitationData($order);
            
            // Validate invitation data
            $validationErrors = $invitationData->validate();
            if (!empty($validationErrors)) {
                throw new Exception('Invitation data validation failed: ' . implode(', ', $validationErrors));
            }

            // Sync products before sending invitation to prevent PRODUCT_NOT_FOUND errors
            if (!empty($invitationData->productCodes)) {
                $this->syncProductsBeforeInvitation($order, $invitationData->productCodes);
            }

            // Send invitation via API
            $attemptedInviteApi = true;
            $apiResponse = $this->apiClient->sendInvitation($invitationData->toApiArray());
            $inviteHttpStatus = $this->apiClient->getLastHttpStatus();
            
            // Record invitation in database
            $this->recordInvitation($order, $invitationData, 'sent', $apiResponse);
            
            $this->logSuccess('sendInvitation', $order->id, $invitationData->email);
            $this->reportInviteHealth($order, true, null, $inviteHttpStatus);
            
            return [
                'success' => true,
                'message' => 'Invitation sent successfully',
                'order_id' => $order->id,
                'email' => $invitationData->email,
                'invitation_type' => $invitationData->invitationType,
                'delay_days' => $invitationData->delay,
                'product_count' => count($invitationData->productCodes),
                'api_response' => $apiResponse
            ];
            
        } catch (Throwable $e) {
            // Record failed invitation
            if (isset($invitationData)) {
                $this->recordInvitation($order, $invitationData, 'failed', ['error' => $e->getMessage()]);
            }
            
            $this->logError('sendInvitation', $e->getMessage(), $order->id);
            $inviteHttpStatus = $attemptedInviteApi ? $this->apiClient->getLastHttpStatus() : null;
            $this->reportInviteHealth($order, false, $e->getMessage(), $inviteHttpStatus);
            
            return [
                'success' => false,
                'message' => 'Invitation failed: ' . $e->getMessage(),
                'order_id' => $order->id
            ];
        }
    }

    /**
     * Check if invitation should be sent for this order
     *
     * @param Order $order PrestaShop order object
     * @return bool Whether invitation should be sent
     */
    public function shouldSendInvitation(Order $order): bool
    {
        try {
            // Check if customer group is excluded
            if ($this->isCustomerGroupExcluded($order)) {
                $this->logInfo('shouldSendInvitation', 'Customer group excluded', $order->id);
                return false;
            }
            
            // Check if invitation already sent for this order
            if ($this->isInvitationAlreadySent($order)) {
                $this->logInfo('shouldSendInvitation', 'Invitation already sent', $order->id);
                return false;
            }
            
            // Check if order has valid products (only required for product/both invitations)
            $invitationType = $this->config['invitation_type'] ?? 'both';
            if ($invitationType !== 'shop') {
                $productCodes = $this->extractProductCodes($order);
                if (empty($productCodes)) {
                    $this->logInfo('shouldSendInvitation', 'No valid products found', $order->id);
                    return false;
                }
            }
            
            // Check customer email
            $customer = new Customer($order->id_customer);
            if (empty($customer->email) || !Validate::isEmail($customer->email)) {
                $this->logInfo('shouldSendInvitation', 'Invalid customer email', $order->id);
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logError('shouldSendInvitation', $e->getMessage(), $order->id);
            return false;
        }
    }

    /**
     * Build invitation data from order
     *
     * @param Order $order PrestaShop order object
     * @return KiyohInvitationData
     */
    public function buildInvitationData(Order $order): KiyohInvitationData
    {
        return KiyohInvitationData::fromOrder($order, $this->config);
    }

    /**
     * Handle order status update hook
     *
     * @param array $params Hook parameters
     * @return void
     */
    public function handleOrderStatusUpdate(array $params): void
    {
        try {
            $orderId = isset($params['id_order']) ? (int)$params['id_order'] : 0;
            $newOrderStatusId = isset($params['newOrderStatus']) ? (int)$params['newOrderStatus']->id : 0;
            
            if ($orderId <= 0 || $newOrderStatusId <= 0) {
                return;
            }
            
            // Check if this status triggers invitations
            if (!$this->isStatusTrigger($newOrderStatusId)) {
                return;
            }
            
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                $this->logError('handleOrderStatusUpdate', 'Invalid order', $orderId);
                return;
            }
            
            // Send invitation
            $result = $this->sendInvitation($order);
            
            if ($result['success']) {
                $this->logInfo('handleOrderStatusUpdate', 'Invitation sent successfully', $orderId);
            } else {
                $this->logError('handleOrderStatusUpdate', $result['message'], $orderId);
            }
            
        } catch (Throwable $e) {
            $this->logError('handleOrderStatusUpdate', $e->getMessage(), $orderId ?? 0);
        }
    }

    /**
     * Check if customer group is excluded from invitations
     *
     * @param Order $order PrestaShop order object
     * @return bool Whether customer group is excluded
     */
    private function isCustomerGroupExcluded(Order $order): bool
    {
        $excludedGroups = $this->config['excluded_customer_groups'] ?? [];
        
        if (empty($excludedGroups)) {
            return false;
        }
        
        $customer = new Customer($order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return true; // Exclude if customer not found
        }
        
        // Get customer's default group
        $customerGroupId = $customer->id_default_group;
        
        return in_array($customerGroupId, $excludedGroups);
    }

    /**
     * Check if invitation already sent for this order
     *
     * @param Order $order PrestaShop order object
     * @return bool Whether invitation was already sent
     */
    private function isInvitationAlreadySent(Order $order): bool
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from('kiyoh_invitations');
        $sql->where('id_order = ' . (int)$order->id);
        $sql->where('status IN ("sent", "pending")');
        
        $count = (int)Db::getInstance()->getValue($sql);
        
        return $count > 0;
    }

    /**
     * Check if order status triggers invitations
     *
     * @param int $statusId Order status ID
     * @return bool Whether status triggers invitations
     */
    private function isStatusTrigger(int $statusId): bool
    {
        $triggerStatuses = $this->config['trigger_status'] ?? [];
        
        return in_array($statusId, $triggerStatuses);
    }

    /**
     * Extract product codes from order with sorting and limits
     *
     * @param Order $order PrestaShop order object
     * @return array Product codes
     */
    private function extractProductCodes(Order $order): array
    {
        $productCodes = [];
        $maxProducts = (int)($this->config['max_products'] ?? 5);
        $sortOrder = $this->config['product_sort_order'] ?? 'cart_order';
        
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
                'name' => KiyohProductData::localizedValue($product->name, (int) Context::getContext()->language->id),
                'price' => (float)$detail['unit_price_tax_incl'],
                'product_id' => $detail['product_id']
            ];
        }
        
        // Sort products based on configuration
        $this->sortProducts($products, $sortOrder);
        
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
    private function sortProducts(array &$products, string $sortOrder): void
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

    /**
     * Record invitation in database
     *
     * @param Order $order PrestaShop order object
     * @param KiyohInvitationData $invitationData Invitation data
     * @param string $status Invitation status
     * @param array $responseData API response data
     * @return bool Success status
     */
    private function recordInvitation(Order $order, KiyohInvitationData $invitationData, string $status, array $responseData): bool
    {
        try {
            $data = [
                'id_order' => (int)$order->id,
                'customer_email' => pSQL($invitationData->email),
                'invitation_type' => pSQL($invitationData->invitationType),
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
                'delay_days' => (int)$invitationData->delay,
                'status' => pSQL($status),
                'response_data' => pSQL(json_encode($responseData)),
                'date_add' => date('Y-m-d H:i:s')
            ];
            
            // null_values writes SQL NULL. An empty string in sent_at fails
            // under the strict sql_mode PrestaShop 9 enables by default.
            return Db::getInstance()->insert('kiyoh_invitations', $data, true);
            
        } catch (Exception $e) {
            $this->logError('recordInvitation', $e->getMessage(), $order->id);
            return false;
        }
    }

    /**
     * Report invite health without changing the invitation result.
     *
     * @param Order $order
     * @param bool $sent
     * @param string|null $errorMessage
     * @param int|null $httpStatus
     * @return void
     */
    private function reportInviteHealth(Order $order, bool $sent, ?string $errorMessage, ?int $httpStatus): void
    {
        try {
            require_once dirname(__FILE__) . '/KiyohMobileHealthReporter.php';
            KiyohMobileHealthReporter::noteInvite((int) $order->id_shop, $sent, $errorMessage, $httpStatus);
        } catch (Throwable $e) {
            try {
                $this->logError('reportInviteHealth', $e->getMessage(), (int) $order->id);
            } catch (Throwable $ignored) {
                // Health reporting must not change the invitation result.
            }
        }
    }

    /**
     * Sync order products before sending invitation to prevent PRODUCT_NOT_FOUND errors
     *
     * @param Order $order PrestaShop order object
     * @param array $productCodes Product codes from invitation
     * @return void
     */
    private function syncProductsBeforeInvitation(Order $order, array $productCodes): void
    {
        // There is no KIYOH_PRODUCT_SYNC_ENABLED key. Auto sync is the switch
        // the configuration page actually stores.
        $syncEnabled = Configuration::get('KIYOH_AUTO_SYNC');
        if (!$syncEnabled) {
            return;
        }

        try {
            $syncManager = new KiyohProductSyncManager($this->apiClient);
            foreach ($order->getProducts() as $product) {
                $productId = (int)$product['product_id'];
                $syncManager->syncSingleProduct($productId, false);
            }
        } catch (Exception $e) {
            $this->logError('syncProductsBeforeInvitation', $e->getMessage(), $order->id);
        }
    }

    /**
     * Log successful operation
     *
     * @param string $method Method name
     * @param int $orderId Order ID
     * @param string $email Customer email
     */
    private function logSuccess(string $method, int $orderId, string $email): void
    {
        $message = sprintf(
            'Kiyoh Invitation %s successful - Order ID: %d, Email: %s',
            $method,
            $orderId,
            $email
        );
        
        PrestaShopLogger::addLog(
            $message,
            1, // Info level
            null,
            'KiyohInvitationManager',
            $orderId,
            true
        );
    }

    /**
     * Log informational message
     *
     * @param string $method Method name
     * @param string $message Log message
     * @param int $orderId Order ID
     */
    private function logInfo(string $method, string $message, int $orderId): void
    {
        $logMessage = sprintf(
            'Kiyoh Invitation %s - %s - Order ID: %d',
            $method,
            $message,
            $orderId
        );
        
        PrestaShopLogger::addLog(
            $logMessage,
            1, // Info level
            null,
            'KiyohInvitationManager',
            $orderId,
            true
        );
    }

    /**
     * Log error
     *
     * @param string $method Method name
     * @param string $errorMessage Error message
     * @param int $orderId Order ID
     */
    private function logError(string $method, string $errorMessage, int $orderId): void
    {
        $logMessage = sprintf(
            'Kiyoh Invitation %s failed - Error: %s - Order ID: %d',
            $method,
            $errorMessage,
            $orderId
        );
        
        PrestaShopLogger::addLog(
            $logMessage,
            3, // Error level
            null,
            'KiyohInvitationManager',
            $orderId,
            true
        );
    }

    /**
     * Get invitation statistics
     *
     * @param int $days Number of days to look back (default: 30)
     * @return array Statistics
     */
    public function getInvitationStats(int $days = 30): array
    {
        try {
            $dateFrom = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            $sql = new DbQuery();
            $sql->select('status, COUNT(*) as count');
            $sql->from('kiyoh_invitations');
            $sql->where('date_add >= "' . pSQL($dateFrom) . '"');
            $sql->groupBy('status');
            
            $results = Db::getInstance()->executeS($sql);
            
            $stats = [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'pending' => 0
            ];
            
            foreach ($results as $row) {
                $stats[$row['status']] = (int)$row['count'];
                $stats['total'] += (int)$row['count'];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            $this->logError('getInvitationStats', $e->getMessage(), 0);
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0];
        }
    }
}
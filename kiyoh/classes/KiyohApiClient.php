<?php
/**
 * Kiyoh API Client Implementation
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/KiyohApi.php';

/**
 * Unified API client for both Kiyoh.com and Klantenvertellen.nl platforms
 */
class KiyohApiClient extends KiyohApi
{
    /** @var string Platform identifier */
    private $platform;
    
    /** @var string Location ID */
    private $locationId;
    
    /** @var string API Key */
    private $apiKey;

    /** @var int|null HTTP status of the most recent request */
    private $lastHttpStatus = null;
    
    /** @var array Platform base URLs */
    private static $baseUrls = [
        'kiyoh' => 'https://www.kiyoh.com',
        'klantenvertellen' => 'https://www.klantenvertellen.nl'
    ];

    /**
     * Constructor
     *
     * @param string $platform Platform identifier (kiyoh or klantenvertellen)
     * @param string $locationId Location ID
     * @param string $apiKey API Key
     * @throws Exception if platform is invalid
     */
    public function __construct(string $platform, string $locationId, string $apiKey)
    {
        if (!isset(self::$baseUrls[$platform])) {
            throw new Exception('Invalid platform: ' . $platform);
        }
        
        $this->platform = $platform;
        $this->locationId = $locationId;
        $this->apiKey = $apiKey;
    }

    /**
     * HTTP status from the last request, or null when the call never reached a server.
     *
     * @return int|null
     */
    public function getLastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    /**
     * Sync product data to the platform
     *
     * @param array $productData Product data to sync
     * @return array API response
     * @throws Exception on API errors
     */
    public function syncProduct(array $productData): array
    {
        $url = $this->getBaseUrl() . '/v1/location/product/external';
        
        // Ensure location_id is included
        $productData['location_id'] = $this->locationId;
        
        try {
            $response = $this->makeRequest('PUT', $url, $productData);
            
            $this->logApiCall('syncProduct', $url, $productData, $response);
            
            return $response;
        } catch (Exception $e) {
            $this->logError('syncProduct', $e->getMessage(), $productData);
            throw $e;
        }
    }

    /**
     * Sync multiple products to the platform in bulk (up to 200 products)
     *
     * @param array $products Array of product data arrays
     * @return array API response
     * @throws Exception on API errors
     */
    public function syncProductBulk(array $products): array
    {
        $url = $this->getBaseUrl() . '/v1/location/product/external/bulk';
        
        // Build bulk request data
        $bulkData = [
            'location_id' => $this->locationId,
            'products' => []
        ];
        
        foreach ($products as $productData) {
            // Ensure each product has location_id
            $productData['location_id'] = $this->locationId;
            $bulkData['products'][] = $productData;
        }
        
        try {
            $response = $this->makeRequest('PUT', $url, $bulkData);
            
            $this->logApiCall('syncProductBulk', $url, ['count' => count($products)], $response);
            
            return $response;
        } catch (Exception $e) {
            $this->logError('syncProductBulk', $e->getMessage(), ['count' => count($products)]);
            throw $e;
        }
    }

    /**
     * Send review invitation to customer
     *
     * @param array $invitationData Invitation data
     * @return array API response
     * @throws Exception on API errors
     */
    public function sendInvitation(array $invitationData): array
    {
        $url = $this->getBaseUrl() . '/v1/invite/external';
        
        // Ensure location_id is included
        $invitationData['location_id'] = $this->locationId;
        
        try {
            $response = $this->makeRequest('POST', $url, $invitationData);
            
            $this->logApiCall('sendInvitation', $url, $invitationData, $response);
            
            return $response;
        } catch (Exception $e) {
            $this->logError('sendInvitation', $e->getMessage(), $invitationData);
            throw $e;
        }
    }

    /**
     * Get reviews for a product or location
     *
     * @param array $params Query parameters
     * @return array API response
     * @throws Exception on API errors
     */
    public function getReviews(array $params = []): array
    {
        $params['locationId'] = $this->locationId;
        $queryString = http_build_query($params);
        $url = $this->getBaseUrl() . '/v1/publication/product/review/external?' . $queryString;
        
        try {
            $response = $this->makeRequest('GET', $url);
            
            $this->logApiCall('getReviews', $url, $params, $response);
            
            return $response;
        } catch (Exception $e) {
            $this->logError('getReviews', $e->getMessage(), $params);
            throw $e;
        }
    }

    /**
     * Test API connection
     *
     * @return array Connection test result
     * @throws Exception on connection errors
     */
    public function testConnection(): array
    {
        $params = ['locationId' => $this->locationId];
        $queryString = http_build_query($params);
        
        // Use statistics endpoint for connection testing - more reliable
        $url = $this->getBaseUrl() . '/v1/publication/review/external/location/statistics?' . $queryString;
        
        try {
            $response = $this->makeRequest('GET', $url);
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'platform' => $this->platform,
                'location_id' => $this->locationId,
                'data' => $response
            ];
        } catch (Exception $e) {
            // If statistics fails, the credentials are likely invalid
            // But also check if it's just a 404 (endpoint might vary)
            $errorMsg = $e->getMessage();
            
            // Log the error for debugging
            $this->logError('testConnection', $errorMsg, $params);
            
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $errorMsg,
                'platform' => $this->platform,
                'location_id' => $this->locationId
            ];
        }
    }

    /**
     * Get the base URL for the platform
     *
     * @return string Base URL
     */
    protected function getBaseUrl(): string
    {
        return self::$baseUrls[$this->platform];
    }

    /**
     * Get platform-specific headers
     *
     * @return array HTTP headers
     */
    protected function getHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'X-Publication-Api-Token: ' . $this->apiKey
        ];
    }

    /**
     * Make HTTP request using PrestaShop's HTTP client
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array|null $data Request data
     * @return array Response data
     * @throws Exception on HTTP errors
     */
    private function makeRequest(string $method, string $url, ?array $data = null): array
    {
        $this->lastHttpStatus = null;
        $ch = curl_init();
        
        // Basic cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $this->getHeaders(),
            CURLOPT_USERAGENT => 'PrestaShop-Kiyoh-Module/1.3.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        // Method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
                
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
                
            case 'GET':
                // GET requests don't need additional options
                break;
                
            default:
                curl_close($ch);
                throw new Exception('Unsupported HTTP method: ' . $method);
        }
        
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($httpCode > 0) {
            $this->lastHttpStatus = $httpCode;
        }
        
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception('cURL error: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = 'HTTP ' . $httpCode;
            if (isset($decodedResponse['message'])) {
                $errorMessage .= ': ' . $decodedResponse['message'];
            } elseif (isset($decodedResponse['error'])) {
                $errorMessage .= ': ' . $decodedResponse['error'];
            }
            throw new Exception($errorMessage);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }
        
        return $decodedResponse ?: [];
    }

    /**
     * Log successful API call
     *
     * @param string $method API method name
     * @param string $url Request URL
     * @param array $requestData Request data
     * @param array $responseData Response data
     */
    private function logApiCall(string $method, string $url, array $requestData, array $responseData): void
    {
        $logMessage = sprintf(
            'Kiyoh API %s successful - URL: %s, Platform: %s',
            $method,
            $url,
            $this->platform
        );
        
        PrestaShopLogger::addLog(
            $logMessage,
            1, // Info level
            null,
            'KiyohApiClient',
            null,
            true
        );
    }

    /**
     * Log API error
     *
     * @param string $method API method name
     * @param string $errorMessage Error message
     * @param array $context Request context
     */
    private function logError(string $method, string $errorMessage, array $context): void
    {
        $logMessage = sprintf(
            'Kiyoh API %s failed - Error: %s, Platform: %s, Location ID: %s',
            $method,
            $errorMessage,
            $this->platform,
            $this->locationId
        );
        
        PrestaShopLogger::addLog(
            $logMessage,
            3, // Error level
            null,
            'KiyohApiClient',
            null,
            true
        );
    }

    /**
     * Create API client instance from configuration
     *
     * @return KiyohApiClient
     * @throws Exception if configuration is invalid
     */
    public static function createFromConfig(): KiyohApiClient
    {
        $platform = Configuration::get('KIYOH_PLATFORM');
        $locationId = Configuration::get('KIYOH_LOCATION_ID');
        $apiKey = Configuration::get('KIYOH_API_KEY');
        
        if (empty($platform) || empty($locationId) || empty($apiKey)) {
            throw new Exception('Kiyoh API configuration is incomplete');
        }
        
        return new self($platform, $locationId, $apiKey);
    }
}
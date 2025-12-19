# Design Document

## Overview

The Kiyoh PrestaShop module will be architected as a modern PrestaShop module following object-oriented principles and PrestaShop coding standards. The module will consist of several core components: Product Sync Manager, Invitation Manager, and Configuration Manager. Each component will be responsible for specific functionality while maintaining loose coupling through well-defined interfaces.

The module will support both Kiyoh.com and Klantenvertellen.nl platforms through a unified API abstraction layer, allowing seamless switching between platforms while maintaining consistent functionality.

## Architecture

### Module Structure
```
kiyoh/
├── kiyoh.php (main module file)
├── config.xml (module configuration)
├── classes/
│   ├── KiyohApi.php (API abstraction)
│   ├── KiyohApiClient.php (HTTP client implementation)
│   ├── KiyohProductSyncManager.php
│   ├── KiyohInvitationManager.php
│   └── KiyohConfigManager.php
├── controllers/
│   └── admin/
│       └── AdminKiyohController.php (admin interface)
├── views/
│   └── templates/
│       └── admin/
│           ├── configure.tpl (main config page)
│           └── partials/ (form components)
├── sql/
│   ├── install.sql (database schema)
│   └── uninstall.sql (cleanup)
├── translations/
│   ├── en.php
│   ├── nl.php
│   └── de.php
└── override/
    └── classes/
        └── order/ (order hooks if needed)
```

### Core Components

#### 1. API Abstraction Layer
The API layer will provide a unified interface for both Kiyoh.com and Klantenvertellen.nl platforms:

**Class: KiyohApi**
- `syncProduct(array $productData): array`
- `sendInvitation(array $invitationData): array`
- `getReviews(array $params = []): array`
- `testConnection(): array`

**Implementation:**
- `KiyohApiClient` - unified client for both platforms with platform-specific URL handling

#### 2. Product Sync Manager
Handles all product synchronization operations:

**Class: KiyohProductSyncManager**
- `bulkSyncProducts(array $filters = []): array`
- `syncSingleProduct(int $productId): bool`
- `handleProductUpdate(int $productId): void`
- `handleProductCreation(int $productId): void`
- `getExcludedProducts(): array`

**Integration Points:**
- PrestaShop product save hooks (actionProductSave)
- PrestaShop product creation hooks (actionProductAdd)
- PrestaShop cron for bulk operations

#### 3. Invitation Manager
Manages automated review invitations:

**Class: KiyohInvitationManager**
- `sendInvitation(Order $order): array`
- `shouldSendInvitation(Order $order): bool`
- `buildInvitationData(Order $order): KiyohInvitationData`

**Integration Points:**
- PrestaShop order status change hooks (actionOrderStatusUpdate)
- Customer group exclusion logic
- API-based scheduling through delay parameter

## Components and Interfaces

### Data Models

#### ProductData Structure
```php
class KiyohProductData {
    public string $name;
    public string $code;
    public string $sourceUrl;
    public string $imageUrl;
    public string $brandName;
    public string $sku;
    public string $gtin;
    public string $mpn;
    public bool $active = true;
    
    public static function fromPrestaShopProduct(Product $product): self;
    public function toApiArray(): array;
}
```

#### InvitationData Structure
```php
class KiyohInvitationData {
    public int $delay;
    public string $customerName;
    public string $city;
    public string $email;
    public array $productCodes;
    public string $language;
    public bool $productInvite;
    
    public static function fromOrder(Order $order, array $config): self;
    public function toApiArray(): array;
}
```

### Configuration Management

The module will use PrestaShop Configuration class with structured approach:

**Configuration Keys:**
```php
const CONFIG_KEYS = [
    'KIYOH_ENABLED' => 'general.enabled',
    'KIYOH_PLATFORM' => 'general.platform',
    'KIYOH_LOCATION_ID' => 'general.location_id',
    'KIYOH_API_KEY' => 'general.api_key',
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
```

## Data Models

### Database Schema

The module will create custom tables for tracking synchronization and invitation history:

#### ps_kiyoh_product_sync
```sql
CREATE TABLE `ps_kiyoh_product_sync` (
    `id_sync` int(11) NOT NULL AUTO_INCREMENT,
    `id_product` int(11) NOT NULL,
    `last_sync` datetime DEFAULT NULL,
    `sync_status` varchar(20) DEFAULT 'pending',
    `error_message` text,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_sync`),
    KEY `id_product` (`id_product`),
    KEY `sync_status` (`sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

#### ps_kiyoh_invitations
```sql
CREATE TABLE `ps_kiyoh_invitations` (
    `id_invitation` int(11) NOT NULL AUTO_INCREMENT,
    `id_order` int(11) NOT NULL,
    `customer_email` varchar(255) NOT NULL,
    `invitation_type` varchar(20) DEFAULT 'both',
    `sent_at` datetime DEFAULT NULL,
    `delay_days` int(11) DEFAULT 0,
    `status` varchar(20) DEFAULT 'pending',
    `response_data` text,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_invitation`),
    KEY `id_order` (`id_order`),
    KEY `customer_email` (`customer_email`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

### PrestaShop Integration Points

#### Hook Registration
```php
public function install() {
    return parent::install() &&
        $this->registerHook('actionProductSave') &&
        $this->registerHook('actionProductAdd') &&
        $this->registerHook('actionOrderStatusUpdate') &&
        $this->registerHook('actionCronJob') &&
        $this->installDb();
}
```

#### Product Hooks
- `hookActionProductSave`: Triggered when product is updated
- `hookActionProductAdd`: Triggered when new product is created

#### Order Hooks  
- `hookActionOrderStatusUpdate`: Triggered when order status changes

#### Cron Integration
- `hookActionCronJob`: For cleanup tasks and retry mechanisms (not for invitation scheduling)

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

After reviewing the acceptance criteria, the following properties have been identified for testing:

**Property 1: Bulk upload uses PUT requests**
*For any* set of products being bulk uploaded, the module should send PUT requests to the API with correct product data for each product
**Validates: Requirements 1.2**

**Property 2: Product creation triggers sync**
*For any* new product created in PrestaShop, the module should automatically make an API call to upload the product data to the platform
**Validates: Requirements 1.5**

**Property 3: Product update triggers sync**
*For any* existing product updated in PrestaShop, the module should automatically sync the changes to the platform
**Validates: Requirements 1.6**

**Property 4: Product sync includes required fields**
*For any* product being synced, the API payload should include product name, code, source URL, image URL, brand name, SKU, GTIN, MPN, and active=true fields
**Validates: Requirements 1.7**

**Property 5: Product sync errors are logged and retryable**
*For any* product sync that fails, the module should log the error and provide retry mechanisms
**Validates: Requirements 1.8**

**Property 6: Order status change triggers invitation**
*For any* order whose status changes to a configured trigger status, the module should automatically send a review invitation
**Validates: Requirements 2.1**

**Property 7: Invitation includes required fields**
*For any* invitation being sent, the payload should include delay, customer name, city, email, product codes, and language
**Validates: Requirements 2.4**

**Property 8: Product limit is respected**
*For any* invitation with more products than the configured maximum, only the maximum number of products should be included
**Validates: Requirements 2.6**

**Property 9: Language auto-detection works**
*For any* invitation, the language should be correctly derived from customer or store language settings
**Validates: Requirements 2.7**

**Property 10: Invitation errors are logged and retryable**
*For any* invitation that fails to send, the module should log the error and provide retry mechanisms
**Validates: Requirements 2.8**

**Property 11: Multi-store configuration isolation**
*For any* multi-store setup, settings configured for one store should not affect other stores
**Validates: Requirements 3.6**

**Property 12: User permissions are enforced**
*For any* admin action, the module should respect PrestaShop user roles and only allow authorized users to perform actions
**Validates: Requirements 5.4**

**Property 13: Graceful degradation on missing dependencies**
*For any* situation where PrestaShop core functionality is not available, the module should display appropriate warnings and disable functionality gracefully
**Validates: Requirements 5.7**

## Error Handling

### Error Categories
1. **API Errors**: Network timeouts, authentication failures, rate limiting
2. **Data Validation Errors**: Invalid product data, missing required fields
3. **Configuration Errors**: Invalid settings, missing credentials
4. **System Errors**: Database failures, PrestaShop compatibility issues

### Error Handling Strategy
- **Retry Logic**: Implement exponential backoff for API failures
- **Logging**: Comprehensive logging using PrestaShop Logger class
- **User Feedback**: Clear error messages in admin interface
- **Monitoring**: Track error rates and common failure patterns

### Error Recovery
```php
class KiyohErrorHandler {
    public function handleApiError(Exception $e, array $context): void {
        // Log error using PrestaShop Logger
        PrestaShopLogger::addLog(
            'Kiyoh API Error: ' . $e->getMessage(),
            3, // Error level
            null,
            'Kiyoh',
            null,
            true
        );
        
        // Schedule retry if appropriate
        if ($this->isRetryableError($e)) {
            $this->scheduleRetry($context);
        }
        
        // Notify admin if critical
        if ($this->isCriticalError($e)) {
            $this->notifyAdmin($e, $context);
        }
    }
}
```

## Testing Strategy

### Unit Testing
- **API Layer**: Mock external API calls, test response handling
- **Data Models**: Validate data transformation and validation logic
- **Managers**: Test business logic with mocked dependencies
- **Utilities**: Test helper functions and data processing

### Integration Testing
- **PrestaShop Integration**: Test hooks and events with actual PrestaShop
- **Admin Interface**: Test configuration forms and AJAX endpoints
- **Database Operations**: Test CRUD operations and data integrity

### Property-Based Testing
The module will use **Pest PHP** as the property-based testing library for PHP. Each property-based test will run a minimum of 100 iterations to ensure comprehensive coverage.

**Property-based testing requirements**:
- Each correctness property will be implemented by a SINGLE property-based test
- Each test will be tagged with the format: `**Feature: kiyoh-prestashop-module, Property {number}: {property_text}**`
- Tests will generate random valid inputs to verify properties hold across all scenarios
- Mock external dependencies (APIs, PrestaShop core) to focus on module logic

### End-to-End Testing
- **Product Sync Flow**: Create product → verify API call → check database
- **Invitation Flow**: Change order status → verify invitation sent
- **Configuration Flow**: Update settings → verify behavior changes

### Performance Testing
- **Bulk Operations**: Test bulk product sync with large datasets
- **API Performance**: Test API response times and error handling
- **Database Performance**: Test query performance with large datasets

### Testing Tools
- **PHPUnit**: For unit and integration tests
- **Pest PHP**: For property-based testing
- **PrestaShop Test Framework**: For PrestaShop-specific functionality
- **Mock APIs**: For testing without external dependencies

## Security Considerations

### Data Protection
- **API Credentials**: Store securely using PrestaShop Configuration with encryption
- **Customer Data**: Minimize data collection, respect privacy regulations (GDPR)
- **Database Security**: Use PrestaShop Db class with prepared statements

### Access Control
- **Admin Functions**: Restrict to appropriate PrestaShop permissions
- **API Endpoints**: Implement proper authentication and authorization
- **File Permissions**: Ensure proper file and directory permissions

### Input Validation
- **Sanitization**: All user inputs must be sanitized using PrestaShop Tools class
- **Validation**: Validate data types, ranges, and formats
- **Escaping**: Escape all output to prevent XSS attacks

## Performance Optimization

### Caching Strategy
- **Product Data**: Cache product information for sync operations using PrestaShop Cache
- **API Responses**: Cache API responses to prevent rate limiting
- **Configuration**: Cache module configuration to reduce database queries

### Database Optimization
- **Indexing**: Proper indexes on frequently queried columns
- **Query Optimization**: Use efficient queries, avoid N+1 problems
- **Cleanup**: Regular cleanup of old data and logs via cron jobs

### Background Processing
- **API-based Scheduling**: Use API delay parameter for invitation scheduling instead of cron
- **Queue System**: Implement queue for bulk operations to prevent timeouts
- **Rate Limiting**: Respect API rate limits with proper throttling
- **Cron Jobs**: Use PrestaShop cron system only for cleanup tasks and retry mechanisms

### PrestaShop-Specific Optimizations
- **Hook Efficiency**: Minimize processing in frequently called hooks
- **Memory Management**: Proper object cleanup in long-running processes
- **Cache Integration**: Leverage PrestaShop's built-in caching mechanisms
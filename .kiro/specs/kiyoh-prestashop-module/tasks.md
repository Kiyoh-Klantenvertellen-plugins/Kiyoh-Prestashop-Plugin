# Implementation Plan

- [x] 1. Create basic module structure
  - Create main module file (kiyoh.php) with PrestaShop headers and install/uninstall methods
  - Set up basic folder structure (classes/, controllers/, views/, sql/)
  - Create module configuration schema and database tables
  - Register essential PrestaShop hooks for product and order events
  - _Requirements: 5.2, 5.3_

- [ ]* 1.1 Write property test for module structure
  - **Property 13: Graceful degradation on missing dependencies**
  - **Validates: Requirements 5.7**

- [x] 2. Build API client and abstraction layer
  - Create KiyohApi class that handles both Kiyoh.com and Klantenvertellen.nl
  - Implement KiyohApiClient with HTTP request handling using PrestaShop's HTTP client
  - Implement product sync method (PUT request with product data)
  - Implement invitation sending method (POST request with customer data)
  - Add basic error handling and logging using PrestaShop Logger
  - _Requirements: 1.2, 2.1, 1.8, 2.8_

- [ ]* 2.1 Write property test for API client
  - **Property 1: Bulk upload uses PUT requests**
  - **Validates: Requirements 1.2**

- [ ]* 2.2 Write property test for error handling
  - **Property 5: Product sync errors are logged and retryable**
  - **Validates: Requirements 1.8**

- [ ]* 2.3 Write property test for invitation error handling
  - **Property 10: Invitation errors are logged and retryable**
  - **Validates: Requirements 2.8**

- [x] 3. Create admin configuration interface
  - Build AdminKiyohController with configuration forms
  - Add fields for server selection, location ID, API key
  - Add order status trigger and delay settings
  - Add product exclusion options (types and specific codes)
  - Add customer group exclusion options
  - Add multilingual support for per-language settings
  - Implement AJAX endpoints for testing API connection
  - _Requirements: 3.1, 3.2, 4.1, 2.2, 2.3, 2.5_

- [ ]* 3.1 Write property test for user permissions
  - **Property 12: User permissions are enforced**
  - **Validates: Requirements 5.4**

- [ ]* 3.2 Write property test for multi-store configuration
  - **Property 11: Multi-store configuration isolation**
  - **Validates: Requirements 3.6**

- [x] 4. Implement product sync functionality
  - Create KiyohProductSyncManager class
  - Hook into PrestaShop product save/create actions (actionProductSave, actionProductAdd)
  - Extract product data (name, code, URL, image, brand, SKU, GTIN, MPN)
  - Send product data to API when products are created/updated
  - Add bulk sync functionality in admin with progress tracking
  - Apply exclusion filters for product types and codes
  - _Requirements: 1.1, 1.5, 1.6, 1.7, 1.3, 1.4_

- [ ]* 4.1 Write property test for product creation sync
  - **Property 2: Product creation triggers sync**
  - **Validates: Requirements 1.5**

- [ ]* 4.2 Write property test for product update sync
  - **Property 3: Product update triggers sync**
  - **Validates: Requirements 1.6**

- [ ]* 4.3 Write property test for product data fields
  - **Property 4: Product sync includes required fields**
  - **Validates: Requirements 1.7**

- [x] 5. Build invitation system
  - Create KiyohInvitationManager class
  - Hook into PrestaShop order status change actions (actionOrderStatusUpdate)
  - Extract customer and order data when status changes
  - Check invitation type to use correct product inclusion parameters
  - Check customer group exclusions before sending invitations
  - Handle multilingual customer language detection
  - Send invitation API request with delay parameter for scheduling (API handles timing)
  - Include product codes from order items with sorting and limits
  - _Requirements: 2.1, 2.4, 2.6, 2.7_

- [ ]* 5.1 Write property test for order status triggers
  - **Property 6: Order status change triggers invitation**
  - **Validates: Requirements 2.1**

- [ ]* 5.2 Write property test for invitation data fields
  - **Property 7: Invitation includes required fields**
  - **Validates: Requirements 2.4**

- [ ]* 5.3 Write property test for product limits
  - **Property 8: Product limit is respected**
  - **Validates: Requirements 2.6**

- [ ]* 5.4 Write property test for language detection
  - **Property 9: Language auto-detection works**
  - **Validates: Requirements 2.7**

- [ ] 6. Implement cron system and background processing
  - Set up PrestaShop cron integration for cleanup tasks only
  - Create cleanup tasks for old data and logs
  - Implement queue system for bulk operations
  - Add retry mechanisms for failed operations
  - Note: Invitation scheduling is handled by API delay parameter, not cron
  - _Requirements: 5.6, 1.8, 2.8_

- [ ] 7. Add comprehensive testing and validation
  - Write unit tests for core classes and methods
  - Test API integration with both platforms
  - Test PrestaShop hooks and data extraction
  - Add module uninstall cleanup for database and configuration
  - Test multi-store functionality and configuration isolation
  - _Requirements: 5.3, 3.6_

- [ ]* 7.1 Write unit tests for core functionality
  - Create unit tests for KiyohProductSyncManager
  - Write unit tests for KiyohInvitationManager
  - Write unit tests for KiyohApiClient
  - Test configuration management and validation

- [ ] 8. Finalize module packaging and documentation
  - Create proper module translations for supported languages
  - Add module documentation and installation instructions
  - Test module installation and uninstallation process
  - Validate PrestaShop marketplace requirements compliance
  - _Requirements: 5.1, 5.5_

- [ ] 9. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.
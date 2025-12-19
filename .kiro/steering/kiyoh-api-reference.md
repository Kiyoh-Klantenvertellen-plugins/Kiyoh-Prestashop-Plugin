---
inclusion: manual
---

# Kiyoh API Reference

This steering file contains API information for the modern Kiyoh/Klantenvertellen API integration.

## Supported Platforms & Base URLs

The plugin supports 2 platforms:
- **Klantenvertellen**: `klantenvertellen.nl` 
- **Kiyoh**: `kiyoh.com`

## Authentication

Uses API token authentication:
- Header: `X-Publication-Api-Token: [API_TOKEN]`
- Content-Type: `application/json`
- Uses `location_id` for account identification

## Configuration Parameters

- `api_token`: API token from account
- `location_id`: Location ID from account  
- `language`: Language code for email templates (e.g., 'en', 'nl', 'de', 'fr')

## Response Handling
- All APIs return JSON format
- Review statistics structure: `{averageRating, viewReviewUrl, numberReviews}`
- Implement caching (10-minute intervals recommended)
- Error responses should be logged appropriately

## API Endpoints

### Base URLs
- Kiyoh: `https://www.kiyoh.com`
- Klantenvertellen: `https://www.klantenvertellen.nl`

### Product Sync API
- Single product: `PUT /v1/location/product/external`
- Bulk products (up to 200): `PUT /v1/location/product/external/bulk`
- Required fields: `location_id`, `product_code`, `product_name`, `image_url`, `source_url`, `active`
- Optional fields: `mpns`, `skus`, `gtins`, `cluster_code`, `cluster_id`

### Invitation API
- Send invitation: `POST /v1/invite/external`
- Required fields: `location_id`, `invite_email`, `delay`, `language`, `product_code`
- Optional fields: `salutation`, `first_name`, `last_name`, `reference_code`, `product_invite`

### Review Retrieval API
- By product: `GET /v1/publication/product/review/external?locationId=[id]&productCode=[code]`
- By cluster: `GET /v1/publication/product/review/external?locationId=[id]&clusterId=[id]`
- Updates since: `GET /v1/publication/product?locationId=[id]&updatedSince=[datetime]`
- Statistics: `GET /v1/publication/review/external/location/statistics?locationId=[id]`

### Invitation Links
Alternative to API invitations:
- Shop + product review: `https://{baseurl}/invite-link/{location_id}/{account_name}?product_code=[code]`
- Product review only: `https://{baseurl}/product-review/invite-link/{location_id}/{account_name}/[code]`
- Multiple products: Use comma-separated product codes
- Additional parameters: `name`, `city`, `email`, `reference_code`, `lang`

## Implementation Notes
1. Uses REST endpoints with JSON payloads
2. Supports delayed invitations and multilingual templates
3. Timeout settings: 2 seconds for invitations, 1 second for review data
4. Caching mechanism prevents API rate limiting issues
5. Maximum 10 product reviews per invitation (recommended: 3-4)
6. All date/time values use UTC format (e.g., 2020-01-15T01:00:00.000Z)
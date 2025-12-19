<?php
/**
 * Kiyoh API Abstraction Layer
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Abstract API interface for Kiyoh/Klantenvertellen platforms
 */
abstract class KiyohApi
{
    /**
     * Sync product data to the platform
     *
     * @param array $productData Product data to sync
     * @return array API response
     * @throws Exception on API errors
     */
    abstract public function syncProduct(array $productData): array;

    /**
     * Send review invitation to customer
     *
     * @param array $invitationData Invitation data
     * @return array API response
     * @throws Exception on API errors
     */
    abstract public function sendInvitation(array $invitationData): array;

    /**
     * Get reviews for a product or location
     *
     * @param array $params Query parameters
     * @return array API response
     * @throws Exception on API errors
     */
    abstract public function getReviews(array $params = []): array;

    /**
     * Test API connection
     *
     * @return array Connection test result
     * @throws Exception on connection errors
     */
    abstract public function testConnection(): array;

    /**
     * Get the base URL for the platform
     *
     * @return string Base URL
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Get platform-specific headers
     *
     * @return array HTTP headers
     */
    abstract protected function getHeaders(): array;
}
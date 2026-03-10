<?php

/**
 * Pricee API Service for WordPress/WooCommerce.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Pricee_API_Service
{
    private const BASE_URL = 'https://app.pricee.io/api/v1/';

    /**
     * Get Bearer token from Pricee API.
     *
     * @throws Exception
     */
    public function get_bearer(string $clientId, string $apiKey): string
    {
        $response = wp_remote_post(self::BASE_URL.'login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-CLIENT-ID' => $clientId,
                'X-API-KEY' => $apiKey,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to fetch bearer token: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (200 !== $status || !isset($data['token'])) {
            throw new RuntimeException('Failed to get bearer token. Status: '.$status.'. Response: '.$body);
        }

        return $data['token'];
    }

    /**
     * Get websites associated with the account.
     *
     * @throws Exception
     */
    public function get_websites(string $bearer): array
    {
        $response = wp_remote_get(self::BASE_URL.'websites', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to fetch websites: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (200 !== $status || !isset($data['member'])) {
            throw new RuntimeException('Failed to fetch websites. Status: '.$status.'. Response: '.$body);
        }

        return $data['member'];
    }

    /**
     * Create a new website in Pricee.
     *
     * @throws Exception
     */
    public function create_website(string $bearer, string $url): array
    {
        $response = wp_remote_post(self::BASE_URL.'websites', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'body' => wp_json_encode(['url' => $url]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to create website: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (201 !== $status) {
            throw new RuntimeException('Failed to create website. Status: '.$status.'. Response: '.$body);
        }

        return json_decode($body, true);
    }

    /**
     * Create a new product in Pricee.
     *
     * @throws Exception
     */
    public function create_product(string $bearer, string $websiteId, string $productUrl): array
    {
        $response = wp_remote_post(self::BASE_URL.'website_products', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'body' => wp_json_encode([
                'website' => $websiteId,
                'url' => $productUrl,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to create product: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (201 !== $status) {
            throw new RuntimeException('Failed to create product. Status: '.$status.'. Response: '.$body);
        }

        return json_decode($body, true);
    }
}

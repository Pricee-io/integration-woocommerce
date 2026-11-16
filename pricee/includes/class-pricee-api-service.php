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
     * Log in with the API key. Returns the JWT and the IRI of the website
     * the key is scoped to (null if the API does not return one).
     *
     * @return array{token: string, website: ?string}
     *
     * @throws Exception
     */
    public function login(string $clientId, string $apiKey): array
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

        return [
            'token' => (string) $data['token'],
            'website' => isset($data['website']) && is_string($data['website']) ? $data['website'] : null,
        ];
    }

    /**
     * The website an API key is scoped to, read directly: a key can always
     * read its own website, however many websites the account has.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function get_website(string $bearer, string $websiteId): array
    {
        $response = wp_remote_get(self::BASE_URL.'websites/'.$this->extract_uuid($websiteId), [
            'headers' => [
                'Accept' => 'application/ld+json',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to fetch the website of the API key: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (200 !== $status || !is_array($data)) {
            throw new RuntimeException('Failed to fetch the website of the API key. Status: '.$status.'. Response: '.$body);
        }

        return $data;
    }

    /**
     * Extract the UUID from an API Platform IRI (e.g. /api/v1/websites/<uuid>).
     *
     * @throws InvalidArgumentException
     */
    public function extract_uuid(string $id): string
    {
        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $id, $matches);
        $uuid = $matches[0] ?? null;
        if (!$uuid) {
            throw new InvalidArgumentException('Invalid ID');
        }

        return $uuid;
    }

    /**
     * Create a product in Pricee, or update it when it already exists.
     *
     * The product is matched by website + extId (the internal shop product
     * ID, which stays stable when the URL changes). Products synced before
     * extId existed are adopted via a fallback lookup by URL, and get their
     * extId set so later syncs match them directly.
     *
     * @throws Exception
     */
    public function create_or_update_product(string $bearer, string $websiteId, string $productUrl, string $productExtId): void
    {
        $websiteUuid = $this->extract_uuid($websiteId);

        $products = $this->lookup_products($bearer, $websiteUuid, 'extId', $productExtId);
        if (empty($products)) {
            $products = $this->lookup_products($bearer, $websiteUuid, 'url', $productUrl);

            // Only a product synced without extId can be adopted by URL:
            // one with another extId belongs to another shop product
            $owner = $products[0]['extId'] ?? null;
            if (null !== $owner && '' !== $owner) {
                throw new RuntimeException(sprintf('URL %s is already tracked on Pricee for product ID %s', $productUrl, is_scalar($owner) ? (string) $owner : ''));
            }
        }

        if (!empty($products)) {
            $existing = $products[0];
            $patch = [];
            if (rtrim((string) ($existing['url'] ?? ''), '/') !== rtrim($productUrl, '/')) {
                $patch['url'] = $productUrl;
            }
            if (($existing['extId'] ?? null) !== $productExtId) {
                $patch['extId'] = $productExtId;
            }

            if (!empty($patch)) {
                $update = wp_remote_request(self::BASE_URL.'website_products/'.$existing['id'], [
                    'method' => 'PATCH',
                    'headers' => [
                        'Content-Type' => 'application/merge-patch+json',
                        'Authorization' => 'Bearer '.$bearer,
                    ],
                    'body' => wp_json_encode($patch),
                    'timeout' => 20,
                ]);

                if (is_wp_error($update)) {
                    throw new RuntimeException('Failed to update product: '.$update->get_error_message());
                }

                $update_status = wp_remote_retrieve_response_code($update);
                if (200 !== $update_status) {
                    throw new RuntimeException('Failed to update product. Status: '.$update_status.'. Response: '.wp_remote_retrieve_body($update));
                }
            }

            return;
        }

        // Product does not exist: create it
        $create = wp_remote_post(self::BASE_URL.'website_products', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'body' => wp_json_encode([
                'website' => $websiteId,
                'url' => $productUrl,
                'extId' => $productExtId,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($create)) {
            throw new RuntimeException('Failed to create product: '.$create->get_error_message());
        }

        $create_status = wp_remote_retrieve_response_code($create);
        if (201 !== $create_status) {
            throw new RuntimeException('Failed to create product. Status: '.$create_status.'. Response: '.wp_remote_retrieve_body($create));
        }
    }

    /**
     * Look up website products of a website matching a filter (extId or url).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    private function lookup_products(string $bearer, string $websiteUuid, string $filterKey, string $filterValue): array
    {
        $query = http_build_query([
            'website' => $websiteUuid,
            $filterKey => $filterValue,
        ]);

        $response = wp_remote_get(self::BASE_URL.'website_products?'.$query, [
            'headers' => [
                'Authorization' => 'Bearer '.$bearer,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Failed to lookup product: '.$response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (200 !== $status) {
            throw new RuntimeException('Failed to lookup product. Status: '.$status.'. Response: '.$body);
        }

        $data = json_decode($body, true);

        return is_array($data) && isset($data['member']) && is_array($data['member']) ? $data['member'] : [];
    }
}

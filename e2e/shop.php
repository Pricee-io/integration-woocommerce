<?php

/*
 * End-to-end test helper, run inside the throwaway WordPress container by
 * e2e/run.sh (never against a dev shop: it writes to the shop database).
 *
 *   php shop.php configure            settings as a merchant would have them
 *   php shop.php check-upgrade        plugin still active and settings kept after the upgrade
 *   php shop.php webhook              real signed HTTP calls to the webhook, prices checked in DB
 *   php shop.php sync-fixtures        category with enough products for several sync batches
 *   php shop.php check-sync           what the fake Pricee API received from the sync
 *   php shop.php check-uninstall      nothing of the plugin left in the database
 */

const CLIENT_ID = 'e2e-client';
const API_KEY = 'e2e-key';
const SECRET = 'e2e-webhook-secret';
const FIXTURES_FILE = '/tmp/pricee-e2e-fixtures.json';
const SYNC_CATEGORY = 'Pricee E2E';
const SYNC_PRODUCTS = 55; // more than one batch of 50
const FAKE_API_STATE = 'http://127.0.0.1:8090/_state';

require '/var/www/html/wp-load.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): void
{
    echo "ok - {$message}\n";
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
    pass($message);
}

function createProduct(string $name, float $price, int $categoryId = 0): WC_Product_Simple
{
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_regular_price((string) $price);
    if ($categoryId > 0) {
        $product->set_category_ids([$categoryId]);
    }
    $product->save();

    return $product;
}

function storedPrice(WC_Product $product): float
{
    // Read again from the database, not from the object of this script
    clean_post_cache($product->get_id());
    wp_cache_flush();

    return (float) wc_get_product($product->get_id())->get_regular_price('edit');
}

/**
 * Request to the shop through its web server, as Pricee does: the request goes
 * to Apache in this container, with the shop's own host name.
 *
 * @return array{0: int, 1: string} status and body
 */
function httpRequest(string $url, string $method = 'GET', string $body = '', array $headers = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    $local = 'http://127.0.0.1'.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

    $curl = curl_init($local);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => 'POST' === $method ? $body : null,
        CURLOPT_HTTPHEADER => array_merge(['Host: '.$host], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return [$status, $response];
}

/**
 * @param array<int, array<string, mixed>> $entries
 *
 * @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded answer and the request body sent
 */
function sendWebhook(array $entries, ?string $secret = null, ?string $body = null): array
{
    $body ??= (string) json_encode($entries);
    // The URL the settings page tells the merchant to give Pricee
    [$status, $response] = httpRequest(rest_url('pricee/v1/webhook'), 'POST', $body, [
        'Content-Type: application/json',
        'X-Signature: '.hash_hmac('sha256', $body, $secret ?? SECRET),
        'User-Agent: Pricee',
    ]);
    $answer = json_decode($response, true);

    return [$status, is_array($answer) ? $answer : ['raw' => $response], $body];
}

/**
 * @return array<string, mixed>
 */
function entry(WC_Product $product, float $price, ?float $readPrice = null, ?string $nonce = null): array
{
    return [
        'url' => get_permalink($product->get_id()),
        'extId' => (string) $product->get_id(),
        'priceAmount' => $price,
        'priceCurrency' => get_woocommerce_currency(),
        // Taxes are off in the e2e shop: visitors see the stored price
        'currentPriceAmount' => $readPrice ?? storedPrice($product),
        'timestamp' => time(),
        'nonce' => $nonce ?? bin2hex(random_bytes(16)),
    ];
}

function configure(): void
{
    update_option('pricee_client_id', CLIENT_ID);
    update_option('pricee_api_key', API_KEY);
    update_option('pricee_webhook_enabled', 'yes');
    update_option('pricee_webhook_secret', SECRET);
    pass('plugin configured');
}

function checkUpgrade(): void
{
    assertSame(true, is_plugin_active('pricee/pricee.php'), 'plugin still active');
    assertSame(CLIENT_ID, get_option('pricee_client_id'), 'client ID kept');
    assertSame(API_KEY, get_option('pricee_api_key'), 'API key kept');
    assertSame('yes', get_option('pricee_webhook_enabled'), 'webhook still enabled');
    assertSame(SECRET, get_option('pricee_webhook_secret'), 'webhook secret kept');
}

function webhook(): void
{
    $simple = createProduct('Pricee E2E simple', 10.0);
    $onSale = createProduct('Pricee E2E on sale', 10.0);
    $onSale->set_sale_price('9');
    $onSale->save();
    $changed = createProduct('Pricee E2E changed in shop', 10.0);

    [$status, $answer, $body] = sendWebhook([entry($simple, 8.0), entry($onSale, 7.0), entry($changed, 6.0, 12.0)]);
    assertSame(200, $status, 'signed call accepted ('.json_encode($answer).')');
    assertSame(['updated_price', 'skipped_promotion', 'skipped_price_changed_in_shop'], $answer['results'] ?? null, 'answer gives each entry its outcome, in order');
    assertSame(['updated_price' => 1, 'skipped_promotion' => 1, 'skipped_price_changed_in_shop' => 1], $answer['outcomes'] ?? null, 'answer counts each outcome');
    assertSame(8.0, storedPrice($simple), 'simple product price written');
    assertSame(10.0, storedPrice($onSale), 'product on sale left untouched');
    assertSame(10.0, storedPrice($changed), 'price changed in the shop since Pricee read it left untouched');

    [$status] = sendWebhook([], null, $body);
    assertSame(401, $status, 'replayed call refused');

    [$status] = sendWebhook([entry($simple, 5.0)], 'wrong-secret');
    assertSame(401, $status, 'call with a wrong signature refused');
    assertSame(8.0, storedPrice($simple), 'refused calls write nothing');

    // A call refused for one stale entry does not use up its nonce: once fixed, it gets through
    $nonce = bin2hex(random_bytes(16));
    $stale = entry($simple, 7.5, null, $nonce);
    $stale['timestamp'] = time() - 3600;
    [$status] = sendWebhook([entry($simple, 7.5, null, $nonce), $stale]);
    assertSame(401, $status, 'call with a stale entry refused');
    [$status, $answer] = sendWebhook([entry($simple, 7.5, null, $nonce)]);
    assertSame([200, ['updated_price']], [$status, $answer['results'] ?? null], 'its retry with the same nonce accepted');

    // The retry of a call applied but answered too late: Pricee still sends the price it read before
    [$status, $answer] = sendWebhook([entry($simple, 7.5, 8.0)]);
    assertSame([200, ['unchanged']], [$status, $answer['results'] ?? null], 'retried call already applied answered unchanged');
    assertSame(7.5, storedPrice($simple), 'price kept');
}

function syncFixtures(): void
{
    $term = wp_insert_term(SYNC_CATEGORY, 'product_cat');
    if (is_wp_error($term)) {
        fail('could not create the sync category: '.$term->get_error_message());
    }

    $ids = [];
    for ($i = 1; $i <= SYNC_PRODUCTS; ++$i) {
        $ids[] = createProduct('Pricee E2E sync '.$i, 20.0, (int) $term['term_id'])->get_id();
    }
    // A draft is not synced, nor counted
    $draft = createProduct('Pricee E2E draft', 20.0, (int) $term['term_id']);
    $draft->set_status('draft');
    $draft->save();

    file_put_contents(FIXTURES_FILE, json_encode(['category' => (int) $term['term_id'], 'products' => $ids]));
    pass(SYNC_PRODUCTS.' products created in category '.SYNC_CATEGORY);
}

function checkSync(): void
{
    $fixtures = json_decode((string) file_get_contents(FIXTURES_FILE), true);
    $state = json_decode((string) file_get_contents(FAKE_API_STATE), true);
    if (!is_array($fixtures) || !is_array($state)) {
        fail('missing fixtures or fake API state');
    }

    assertSame([], $state['websites'], 'no website created on Pricee');
    assertSame(['/api/v1/websites/11111111-0000-4000-8000-000000000001'], array_values(array_unique(array_column($state['products'], 'website'))), 'products sent to the website of the API key');

    $extIds = array_map('intval', array_column($state['products'], 'extId'));
    sort($extIds);
    $expectedIds = $fixtures['products'];
    sort($expectedIds);
    assertSame($expectedIds, $extIds, 'every published product of the category sent once, with its ID');

    foreach ($state['products'] as $product) {
        $expected = rtrim((string) get_permalink((int) $product['extId']), '/');
        if ($product['url'] !== $expected) {
            fail('product '.$product['extId'].' sent with URL '.$product['url'].' instead of '.$expected);
        }
    }
    pass('product URLs are the product pages');

    $routes = array_column($state['requests'], 'route');
    // Each batch checks the key's website
    $batches = count(array_filter($routes, static fn (string $route): bool => str_starts_with($route, 'websites/')));
    assertSame((int) ceil(SYNC_PRODUCTS / 50), $batches, 'sync sent in batches of 50');
    // Pricee allows few logins per minute: the batches reuse the first one's token
    assertSame(1, count(array_keys($routes, 'login', true)), 'one login for the whole sync');
}

function checkUninstall(): void
{
    global $wpdb;

    $left = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%pricee%'");
    assertSame([], $left, 'no option of the plugin left');
}

match ($argv[1] ?? '') {
    'configure' => configure(),
    'check-upgrade' => checkUpgrade(),
    'webhook' => webhook(),
    'sync-fixtures' => syncFixtures(),
    'check-sync' => checkSync(),
    'check-uninstall' => checkUninstall(),
    default => fail('unknown command '.($argv[1] ?? '')),
};

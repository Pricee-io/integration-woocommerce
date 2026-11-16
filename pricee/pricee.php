<?php

/*
 * Plugin Name: Pricee
 * Description: Pricee integration with WooCommerce
 * Version: 1.7.0
 * Requires PHP: 8.2
 * Author: Pricee.io
 * Text Domain: pricee
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

define('PRICEE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRICEE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRICEE_VERSION', '1.7.0');

// Maximum age (in seconds) of a signed webhook payload before it is rejected as stale
define('PRICEE_WEBHOOK_MAX_AGE', 300);
// Products synced per request: the sync page sends one request per batch
define('PRICEE_SYNC_BATCH_SIZE', 50);

require_once PRICEE_PLUGIN_DIR.'includes/class-pricee-api-service.php';

class Pricee_Plugin
{
    public function __construct()
    {
        $this->init_hooks();
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Pricee.io', 'pricee'),
            'Pricee.io',
            'manage_options',
            'pricee-settings',
            [$this, 'settings_page'],
            'dashicons-cart',
            56
        );

        add_submenu_page(
            'pricee-settings',
            __('Configuration', 'pricee'),
            __('Configuration', 'pricee'),
            'manage_options',
            'pricee-settings',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'pricee-settings',
            __('Synchroniser les produits', 'pricee'),
            __('Synchronisation', 'pricee'),
            'manage_options',
            'pricee-sync',
            'pricee_sync_page_callback'
        );
    }

    public function register_settings()
    {
        register_setting('pricee_settings_group', 'pricee_client_id');
        register_setting('pricee_settings_group', 'pricee_api_key');
        register_setting('pricee_settings_group', 'pricee_webhook_enabled');
        register_setting('pricee_settings_group', 'pricee_webhook_secret');
    }

    public function admin_enqueue_assets($hook)
    {
        if ('toplevel_page_pricee-settings' !== $hook) {
            return;
        }
        wp_enqueue_style('pricee-admin-css', PRICEE_PLUGIN_URL.'assets/admin.css', [], PRICEE_VERSION);
        wp_enqueue_script('pricee-admin-js', PRICEE_PLUGIN_URL.'assets/admin.js', ['jquery'], PRICEE_VERSION, true);
    }

    public function settings_page()
    {
        $webhook_enabled = get_option('pricee_webhook_enabled', 'no');
        $webhook_secret = get_option('pricee_webhook_secret', '');
        $site_url = get_site_url();
        $webhook_url = esc_url($site_url.'/wp-json/pricee/v1/webhook');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pricee.io - Configuration', 'pricee'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pricee_settings_group');
        do_settings_sections('pricee_settings_group');
        ?>
                <h2><?php esc_html_e('Synchronisation', 'pricee'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('ID Client', 'pricee'); ?></th>
                        <td>
                            <input type="text" name="pricee_client_id" value="<?php echo esc_attr(get_option('pricee_client_id')); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Clé API', 'pricee'); ?></th>
                        <td>
                            <input type="password" name="pricee_api_key" value="<?php echo esc_attr(get_option('pricee_api_key')); ?>" />
                        </td>
                    </tr>
                </table>
                <h2><?php esc_html_e('Mise à jour automatique des prix', 'pricee'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Activer la synchronisation webhook', 'pricee'); ?></th>
                        <td>
                            <input type="checkbox" name="pricee_webhook_enabled" value="yes" <?php checked($webhook_enabled, 'yes'); ?> />
                            <p class="description"><?php _e('Active la mise à jour automatique des prix de vos produits depuis Pricee.', 'pricee'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('URL du Webhook', 'pricee'); ?></th>
                        <td>
                            <input type="text" readonly value="<?php echo $webhook_url; ?>" style="width:100%; max-width:500px;" onclick="this.select();" />
                            <p class="description"><?php _e('Copiez cette URL pour configurer le webhook dans Pricee.io.', 'pricee'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Clé secrète webhook', 'pricee'); ?></th>
                        <td>
                            <input type="text" name="pricee_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" />
                            <p class="description"><?php _e('Clé secrète pour valider la provenance des webhooks.', 'pricee'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Enregistrer', 'pricee')); ?>
            </form>
        </div>
        <?php
    }

    private function init_hooks()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
    }
}

function pricee_sync_page_callback()
{
    // Get WooCommerce categories
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ]);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Pricee.io - Synchronisation', 'pricee'); ?></h1>

        <form id="pricee-sync-form">
            <?php wp_nonce_field('pricee_sync_products', 'pricee_sync_nonce'); ?>
            <h2><?php esc_html_e('Synchroniser les produits par catégorie', 'pricee'); ?></h2>

            <div id="pricee-sync-loading" style="display:none; color:#666;">
                <span class="spinner is-active"></span> <?php esc_html_e('Chargement...', 'pricee'); ?>
            </div>

            <div id="pricee-sync-result" style="margin: 10px 0;"></div>

            <div style="margin: 20px 0">
                <?php foreach ($categories as $category) {
                    $product_count = wc_get_products(['category' => [$category->slug], 'limit' => -1]);
                    ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                            name="categories[]"
                            value="<?php echo esc_attr((string) $category->term_id); ?>"
                            id="category-<?php echo esc_attr((string) $category->term_id); ?>">
                        <label class="form-check-label" for="category-<?php echo esc_attr((string) $category->term_id); ?>">
                            <?php echo esc_html($category->name); ?> (<?php echo count($product_count); ?>)
                        </label>
                    </div>
                <?php } ?>
            </div>

            <button type="submit" class="button button-primary" style="margin-top:10px;">
                <?php esc_html_e('Synchroniser les produits', 'pricee'); ?>
            </button>
        </form>
    </div>

    <script>
    // The sync runs in batches, one request each, so a large selection never
    // hits a timeout: each answer gives the cursor for the next batch.
    document.getElementById('pricee-sync-form').addEventListener('submit', function(e) {
        e.preventDefault();

        const form = e.target;
        const button = form.querySelector('button[type="submit"]');
        const result = document.getElementById('pricee-sync-result');
        const loading = document.getElementById('pricee-sync-loading');
        let synced = 0;
        let failed = 0;

        const show = (type, message) => {
            result.style.display = 'block';
            result.className = 'notice notice-' + type;
            result.innerText = message;
        };
        const finish = () => {
            loading.style.display = 'none';
            button.disabled = false;
        };
        const syncedMessage = () => synced + ' <?php echo esc_js(__('produits synchronisés', 'pricee')); ?>'
            + (failed > 0 ? ', ' + failed + ' <?php echo esc_js(__('en erreur (voir les logs)', 'pricee')); ?>' : '');
        const fail = (message) => {
            finish();
            show('error', '<?php echo esc_js(__('Erreur lors de la synchronisation', 'pricee')); ?>'
                + (message ? ' : ' + message : '')
                + (synced + failed > 0 ? ' (' + syncedMessage() + ')' : ''));
        };

        if (!form.querySelector('input[name="categories[]"]:checked')) {
            show('warning', '<?php echo esc_js(__('Sélectionnez au moins une catégorie.', 'pricee')); ?>');

            return;
        }

        button.disabled = true;
        loading.style.display = 'block';
        result.style.display = 'none';

        const syncBatch = (afterId) => {
            const data = new FormData(form);
            data.append('action', 'pricee_sync_products'); // AJAX action
            data.append('after_id', afterId);

            fetch(ajaxurl, { // WordPress provides ajaxurl in admin
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(response => response.json())
            .then(answer => {
                if (!answer.success) {
                    fail(answer.data && answer.data.message);

                    return;
                }

                synced += answer.data.synced;
                failed += answer.data.failed;
                if (answer.data.done) {
                    finish();
                    show(failed > 0 ? 'warning' : 'success', syncedMessage());

                    return;
                }

                show('info', '<?php echo esc_js(__('Synchronisation en cours :', 'pricee')); ?> ' + (synced + failed) + ' / ' + answer.data.total);
                syncBatch(answer.data.last_id);
            })
            .catch(() => fail(''));
        };

        syncBatch(0);
    });
    </script>
    <?php
}

// AJAX handler for syncing products to Pricee
add_action('wp_ajax_pricee_sync_products', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    if (!isset($_POST['pricee_sync_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pricee_sync_nonce'])), 'pricee_sync_products')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    if (!class_exists('Pricee_API_Service')) {
        require_once PRICEE_PLUGIN_DIR.'includes/class-pricee-api-service.php';
    }

    $categories = isset($_POST['categories']) ? array_map('intval', (array) $_POST['categories']) : [];
    if ([] === $categories) {
        wp_send_json_error(['message' => 'No category selected.'], 400);
    }
    // Batch cursor: the sync goes on after the last product of the previous batch
    $after_id = isset($_POST['after_id']) ? max(0, (int) $_POST['after_id']) : 0;

    $client_id = get_option('pricee_client_id');
    $api_key = get_option('pricee_api_key');

    if (empty($client_id) || empty($api_key)) {
        wp_send_json_error(['message' => 'Pricee API credentials are missing.']);
    }

    // The published products of the selected categories, each once, in ID
    // order: one batch per request, so a large selection never hits a timeout
    $all_ids = array_map('intval', (array) wc_get_products([
        'product_category_id' => $categories,
        'status' => 'publish',
        'limit' => -1,
        'return' => 'ids',
    ]));
    $all_ids = array_values(array_unique($all_ids));
    sort($all_ids);
    $remaining_ids = array_values(array_filter($all_ids, static fn (int $id): bool => $id > $after_id));
    $batch_ids = array_slice($remaining_ids, 0, PRICEE_SYNC_BATCH_SIZE);
    if ([] === $batch_ids) {
        wp_send_json_success(['synced' => 0, 'failed' => 0, 'last_id' => $after_id, 'done' => true, 'total' => count($all_ids)]);
    }

    $synced_count = 0;
    $failed_count = 0;

    try {
        $api_service = new Pricee_API_Service();
        $login = $api_service->login($client_id, $api_key);
        $bearer = $login['token'];
        $website_id = pricee_resolve_website_id($api_service, $login, rtrim(home_url('/'), '/'));

        foreach ($batch_ids as $product_id) {
            try {
                $api_service->create_or_update_product($bearer, $website_id, (string) get_permalink($product_id), (string) $product_id);
                ++$synced_count;
            } catch (Exception $e) {
                ++$failed_count;
                pricee_log('Failed to sync product ID '.$product_id.': '.$e->getMessage(), 'ERROR', 'sync');
            }
        }

        wp_send_json_success([
            'synced' => $synced_count,
            'failed' => $failed_count,
            'last_id' => end($batch_ids),
            'done' => count($remaining_ids) <= PRICEE_SYNC_BATCH_SIZE,
            'total' => count($all_ids),
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

/**
 * The Pricee website to sync to: the one the API key is scoped to, which
 * must be this shop's website (host compared like Pricee does: without
 * scheme and "www.").
 *
 * @param array{token: string, website: ?string} $login
 *
 * @throws RuntimeException
 */
function pricee_resolve_website_id(Pricee_API_Service $api_service, array $login, string $website_url): string
{
    if (null === $login['website']) {
        throw new RuntimeException('This API key is not tied to a website. Create an API key from the Integrations page of this shop\'s website on Pricee.');
    }

    $host = static fn (string $url): string => (string) preg_replace('/^www\./', '', strtolower((string) wp_parse_url($url, PHP_URL_HOST)));

    foreach ($api_service->get_websites($login['token']) as $site) {
        if ($site['@id'] === $login['website'] && $host((string) $site['url']) !== $host($website_url)) {
            throw new RuntimeException(sprintf(
                'This API key belongs to the Pricee website %s, not to this shop (%s). Use an API key created from the Integrations page of this shop\'s website on Pricee.',
                $site['url'],
                $website_url
            ));
        }
    }

    return $login['website'];
}

// Register Pricee webhook endpoint
// @phpstan-ignore-next-line
add_action('rest_api_init', function () {
    register_rest_route('pricee/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'pricee_webhook_handler',
        'permission_callback' => '__return_true', // Allow external calls; secured by hmac verification
    ]);
});

function pricee_webhook_handler(WP_REST_Request $request)
{
    // Check request
    $body = $request->get_body();
    $signature = $request->get_header('x-signature');
    if (empty($body) || empty($signature)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing informations for webhook',
        ], 400);
    }

    // Check webhook configuration
    $enabled = get_option('pricee_webhook_enabled', '');
    $secret = get_option('pricee_webhook_secret', '');
    if (!$enabled || empty($secret)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook not configured',
        ], 401);
    }

    // Verify signature
    $expected_signature = hash_hmac('sha256', $body, $secret);
    if (!hash_equals($expected_signature, $signature)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid signature',
        ], 401);
    }

    // Get JSON
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON payload',
        ], 400);
    }

    // Replay protection: each entry must carry a fresh signed timestamp
    // and a nonce that has not been used within the accepted window.
    // The app signs a whole batch with a single nonce, so each distinct
    // nonce is consumed only once per payload, and only once the whole
    // call is accepted: a refused call must not use up its nonce.
    $nonce_keys = [];
    foreach ($data as $productData) {
        if (!is_array($productData)
            || !isset($productData['timestamp'])
            || !is_numeric($productData['timestamp'])
            || !isset($productData['nonce'])
            || !is_string($productData['nonce'])
            || '' === $productData['nonce']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing timestamp or nonce',
            ], 401);
        }

        if (abs(time() - (int) $productData['timestamp']) > PRICEE_WEBHOOK_MAX_AGE) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Expired webhook payload',
            ], 401);
        }

        $nonce_keys['pricee_nonce_'.hash('sha256', $productData['nonce'])] = true;
    }

    foreach (array_keys($nonce_keys) as $nonce_key) {
        if (false !== get_transient($nonce_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Replayed webhook payload',
            ], 401);
        }
    }

    foreach (array_keys($nonce_keys) as $nonce_key) {
        set_transient($nonce_key, 1, 2 * PRICEE_WEBHOOK_MAX_AGE);
    }

    // A large call writes many products, each one running the shop's hooks
    @set_time_limit(300);

    $results = [];
    $outcomes = [];
    foreach ($data as $productData) {
        $outcome = pricee_apply_webhook_entry(is_array($productData) ? $productData : []);
        $results[] = $outcome;
        $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Webhook received and validated',
        // How many entries were updated, unchanged or skipped, and why
        'outcomes' => $outcomes,
        // The outcome of each entry, in payload order
        'results' => $results,
    ], 200);
}

/**
 * Apply one webhook entry: write its price only when every check passes.
 * Writing a price is the critical action: in any doubtful case the entry is
 * skipped and logged, never guessed.
 *
 * @param array<string, mixed> $productData
 *
 * @return string the outcome of the entry
 */
function pricee_apply_webhook_entry(array $productData): string
{
    $rawPrice = $productData['priceAmount'] ?? null;
    if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
        return pricee_skip_entry('skipped_invalid_price', 'invalid price '.print_r($rawPrice, true), $productData);
    }

    // Product prices are in the shop currency: an amount in another one cannot be written as is
    $currency = $productData['priceCurrency'] ?? null;
    if (!is_string($currency) || strtoupper($currency) !== strtoupper(get_woocommerce_currency())) {
        return pricee_skip_entry('skipped_currency', 'price currency '.print_r($currency, true).' is not the shop currency', $productData);
    }

    // Pricee computes the new price from the price it last read on the page:
    // when the merchant changed it since, their change wins
    $readPrice = $productData['currentPriceAmount'] ?? null;
    if (!is_numeric($readPrice)) {
        return pricee_skip_entry('skipped_no_read_price', 'no current price sent to compare with the shop price', $productData);
    }

    // By extId (the product ID sent at sync) when present, never falling back
    // to the URL for a stale one; by URL for products synced before extId existed
    $extId = $productData['extId'] ?? null;
    if (null !== $extId && '' !== $extId) {
        $product = is_numeric($extId) ? wc_get_product((int) $extId) : null;
    } else {
        $productId = is_string($productData['url'] ?? null) ? url_to_postid($productData['url']) : 0;
        $product = $productId ? wc_get_product($productId) : null;
    }
    if (!$product instanceof WC_Product) {
        return pricee_skip_entry('skipped_unknown_product', 'no product matches the entry', $productData);
    }

    // Only simple products: a variable product's page shows a price range
    if ('simple' !== $product->get_type()) {
        return pricee_skip_entry('skipped_unsupported_type', 'not a simple product', $productData);
    }

    if ('publish' !== $product->get_status()) {
        return pricee_skip_entry('skipped_not_published', 'product not published', $productData);
    }

    // A sale, current or scheduled, is the merchant's: never written over
    if ('' !== (string) $product->get_sale_price('edit') || null !== $product->get_date_on_sale_from('edit')) {
        return pricee_skip_entry('skipped_promotion', 'product on sale or with a scheduled sale', $productData);
    }

    // Compared as visitors see it (tax included or not, as the shop displays
    // prices), rounded to the shop's decimals
    $decimals = wc_get_price_decimals();
    $storedPrice = (float) $product->get_regular_price('edit');
    $displayedPrice = (float) wc_get_price_to_display($product);
    if ($storedPrice <= 0 || $displayedPrice <= 0) {
        return pricee_skip_entry('skipped_invalid_price', 'no current price to compare with', $productData);
    }
    if (abs(round($displayedPrice, $decimals) - round((float) $readPrice, $decimals)) >= 0.000001) {
        return pricee_skip_entry('skipped_price_changed_in_shop', 'the shop price '.$displayedPrice.' is no longer the one Pricee read ('.$readPrice.')', $productData);
    }

    if (abs(round($displayedPrice, $decimals) - round((float) $rawPrice, $decimals)) < 0.000001) {
        return 'unchanged';
    }

    // The received price is the displayed one: stored with the same ratio as
    // the current price, which removes or adds the tax as the shop needs
    $newPrice = round((float) $rawPrice * $storedPrice / $displayedPrice, 6);

    try {
        $product->set_regular_price(wc_format_decimal($newPrice));
        $product->save();
    } catch (Exception $e) {
        pricee_log('Price update failed for product ID '.$product->get_id().': '.$e->getMessage(), 'ERROR', 'webhook');

        return 'failed';
    }

    pricee_log("Price updated for product ID {$product->get_id()} | Old: {$storedPrice} -> New: {$newPrice}", 'INFO', 'webhook');

    return 'updated_price';
}

/**
 * @param array<string, mixed> $productData
 */
function pricee_skip_entry(string $outcome, string $reason, array $productData): string
{
    pricee_log('Entry skipped ('.print_r($productData['extId'] ?? $productData['url'] ?? '', true).'): '.$reason, 'WARNING', 'webhook');

    return $outcome;
}

function pricee_log($message, $level = 'INFO', $file = 'main')
{
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }

    // error_log writes to the server/PHP log (or WP debug.log when
    // WP_DEBUG_LOG is enabled), never to a web-accessible file
    error_log(sprintf('[%s] [pricee][%s][%s] %s', current_time('Y-m-d H:i:s'), $file, $level, $message));
}

// Add settings link in Plugins page
// @phpstan-ignore-next-line
add_filter('plugin_action_links_'.plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="'.admin_url('admin.php?page=pricee-settings').'">'.__('Settings').'</a>';
    array_unshift($links, $settings_link);

    return $links;
});

// Initialize plugin
new Pricee_Plugin();

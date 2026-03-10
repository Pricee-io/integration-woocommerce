<?php

/*
 * Plugin Name: Pricee
 * Description: PrestaShop integration with WooCommerce
 * Version: 1.0.2
 * Author: Pricee.io
 * Text Domain: pricee
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

define('PRICEE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRICEE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRICEE_VERSION', '1.1.0');

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
        wp_enqueue_style('pricee-admin-css', PRICEE_PLUGIN_URL.'assets/css/admin.css', [], PRICEE_VERSION);
        wp_enqueue_script('pricee-admin-js', PRICEE_PLUGIN_URL.'assets/js/admin.js', ['jquery'], PRICEE_VERSION, true);
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
    document.getElementById('pricee-sync-form').addEventListener('submit', function(e) {
        e.preventDefault();

        const form = e.target;
        const result = document.getElementById('pricee-sync-result');
        const loading = document.getElementById('pricee-sync-loading');

        loading.style.display = 'block';
        result.style.display = 'none';

        const data = new FormData(form);
        data.append('action', 'pricee_sync_products'); // AJAX action

        fetch(ajaxurl, { // WordPress provides ajaxurl in admin
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(response => response.json())
        .then(data => {
            loading.style.display = 'none';
            result.style.display = 'block';

            if (data.success) {
                result.className = 'notice notice-success';
                result.innerText = data.data.synced + ' produits synchronisés';
            } else {
                result.className = 'notice notice-error';
                result.innerText = '<?php echo esc_js('Error during synchronization'); ?>';
            }
        })
        .catch(() => {
            loading.style.display = 'none';
            result.style.display = 'block';
            result.className = 'notice notice-error';
            result.innerText = '<?php echo esc_js('Error during synchronization'); ?>';
        });
    });
    </script>
    <?php
}

// AJAX handler for syncing products to Pricee
add_action('wp_ajax_pricee_sync_products', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    if (!class_exists('Pricee_API_Service')) {
        require_once PRICEE_PLUGIN_DIR.'includes/class-pricee-api-service.php';
    }

    $categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : [];
    $synced_count = 0;
    $max_products = 500;

    $client_id = get_option('pricee_client_id');
    $api_key = get_option('pricee_api_key');

    if (empty($client_id) || empty($api_key)) {
        wp_send_json_error(['message' => 'Pricee API credentials are missing.']);
    }

    try {
        $api_service = new Pricee_API_Service();
        $bearer = $api_service->get_bearer($client_id, $api_key);

        $website_url = rtrim(home_url('/'), '/');
        $websites = $api_service->get_websites($bearer);
        $website_id = null;

        foreach ($websites as $site) {
            if (rtrim($site['url'], '/') === $website_url) {
                $website_id = $site['@id'];

                break;
            }
        }

        if (!$website_id) {
            $website = $api_service->create_website($bearer, $website_url);
            $website_id = $website['@id'];
        }

        foreach ($categories as $cat_id) {
            $limit = 100;
            $offset = 0;

            do {
                $products = wc_get_products([
                    'product_category_id' => [$cat_id],
                    'limit' => $limit,
                    'offset' => $offset,
                    'status' => 'publish', // make sure only published products
                ]);

                foreach ($products as $product) {
                    if ($synced_count >= $max_products) {
                        break 2; // break outer loop
                    }

                    try {
                        $product_url = get_permalink($product->get_id());
                        $api_service->create_product($bearer, $website_id, $product_url);
                        ++$synced_count;
                    } catch (Exception $e) {
                        // ignore individual product errors
                    }
                }

                $offset += $limit;
            } while (!empty($products) && $synced_count < $max_products);

            if ($synced_count >= $max_products) {
                break;
            }
        }

        wp_send_json_success(['synced' => $synced_count]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

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
    $body = $request->get_body();
    $signature = $request->get_header('x-signature');
    if (empty($body) || empty($signature)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing informations for webhook',
        ], 400);
    }

    $enabled = get_option('pricee_webhook_enabled', '');
    $secret = get_option('pricee_webhook_secret', '');

    if (!$enabled || empty($secret)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook not configured',
        ], 401);
    }

    $expected_signature = hash_hmac('sha256', $body, $secret);

    if (!hash_equals($expected_signature, $signature)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid signature',
        ], 401);
    }

    $data = json_decode($body, true);
    foreach ($data as $productData) {
        if (empty($productData['url']) || empty($productData['bestPriceAmount'])) {
            continue;
        }

        // Find product by URL
        $product_id = url_to_postid($productData['url']);
        if (!$product_id) {
            continue; // URL does not match any product
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            continue; // Product not found
        }

        // Only update simple products for now (no variants)
        if ('simple' !== $product->get_type()) {
            continue;
        }

        try {
            // Update price
            $current_price = $product->get_regular_price();
            $new_price = (string) $productData['bestPriceAmount'];
            $product->set_regular_price($new_price);
            $product->save();

            pricee_log(
                "Price updated for product ID {$product->get_id()} | Old: {$current_price} -> New: {$new_price}",
                'INFO'
            );
        } catch (Exception $e) {
            pricee_log(
                "Price update failed for product ID {$product->get_id()} : ".$e->getMessage(),
                'ERROR'
            );
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Webhook received and validated',
    ], 200);
}

function pricee_log($message, $level = 'INFO')
{
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }

    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'].'/my-plugin/logs/';

    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    $log_file = $log_dir.'plugin.log';

    $time = current_time('Y-m-d H:i:s');

    $line = "[{$time}] [{$level}] {$message}".PHP_EOL;

    file_put_contents($log_file, $line, FILE_APPEND);
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

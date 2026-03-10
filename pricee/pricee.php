<?php

/*
 * Plugin Name: Pricee
 * Description: PrestaShop integration with WooCommerce
 * Version: 1.0.0
 * Author: Pricee.io
 * Text Domain: pricee
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Pricee_Plugin {

    public function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {
        define('PRICEE_VERSION', '1.1.0');
        define('PRICEE_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('PRICEE_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Pricee Configuration', 'pricee'),
            'Pricee.io',
            'manage_options',
            'pricee-settings',
            [$this, 'settings_page'],
            'dashicons-cart',
            56
        );
    }

    public function register_settings() {
        register_setting('pricee_settings_group', 'pricee_client_id');
        register_setting('pricee_settings_group', 'pricee_api_key');
        register_setting('pricee_settings_group', 'pricee_webhook_enabled');
        register_setting('pricee_settings_group', 'pricee_webhook_secret');
    }

    public function admin_enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_pricee-settings') return;
        wp_enqueue_style('pricee-admin-css', PRICEE_PLUGIN_URL . 'assets/css/admin.css', [], PRICEE_VERSION);
        wp_enqueue_script('pricee-admin-js', PRICEE_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], PRICEE_VERSION, true);
    }

    public function settings_page() {
        $webhook_enabled = get_option('pricee_webhook_enabled', 'no');
        $webhook_secret  = get_option('pricee_webhook_secret', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pricee.io - Configuration', 'pricee'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pricee_settings_group');
                do_settings_sections('pricee_settings_group');
                ?>
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
                    <tr>
                        <th scope="row"><?php _e('Activer la synchronisation webhook', 'pricee'); ?></th>
                        <td>
                            <input type="checkbox" name="pricee_webhook_enabled" value="yes" <?php checked($webhook_enabled, 'yes'); ?> />
                            <p class="description"><?php _e('Active la mise à jour automatique des prix de vos produits depuis Pricee.', 'pricee'); ?></p>
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
}

// Add settings link in Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=pricee-settings') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Initialize plugin
new Pricee_Plugin();

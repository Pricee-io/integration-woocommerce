<?php

/*
 * Run by WordPress when the plugin is deleted: its settings, among them the
 * API key and the webhook secret, and the nonces of recent webhook calls are
 * removed from the database, on every site of a multisite network.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function pricee_uninstall_site(): void
{
    global $wpdb;

    foreach (['pricee_client_id', 'pricee_api_key', 'pricee_webhook_enabled', 'pricee_webhook_secret'] as $option) {
        delete_option($option);
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('pricee_nonce_').'%',
        // Nonces of earlier versions and the reused login tokens
        $wpdb->esc_like('_transient_pricee_').'%',
        $wpdb->esc_like('_transient_timeout_pricee_').'%'
    ));
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $pricee_site_id) {
        switch_to_blog((int) $pricee_site_id);
        pricee_uninstall_site();
        restore_current_blog();
    }
} else {
    pricee_uninstall_site();
}

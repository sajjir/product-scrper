<?php
/**
 * WC Price Scraper N8N Integration
 *
 * @package WC_Price_Scraper
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WC_Price_Scraper_N8N_Integration {

    protected $main_plugin;
    private $settings;

    /**
     * Constructor.
     *
     * @param WC_Price_Scraper $main_plugin Instance of the main plugin.
     */
    public function __construct(WC_Price_Scraper $main_plugin) {
        $this->main_plugin = $main_plugin;
        $this->load_settings();
    }

    /**
     * Load N8N settings from options.
     */
    private function load_settings() {
        $this->settings = [
            'enabled'               => get_option('wc_price_scraper_n8n_enable', 'no') === 'yes',
            'webhook_url'           => get_option('wc_price_scraper_n8n_webhook_url', ''),
            'model_attribute_slugs' => get_option('wc_price_scraper_n8n_model_slug', ''),
            'purchase_link_text'    => get_option('wc_price_scraper_n8n_purchase_link_text', __('Buy Now', 'wc-price-scraper')),
        ];
    }

    /**
     * Check if N8N integration is enabled and configured.
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->settings['enabled'] && !empty($this->settings['webhook_url']);
    }

    /**
     * Prepares and sends data for a given product ID to N8N.
     *
     * @param int $product_id The ID of the parent product.
     */
    public function trigger_send_for_product($product_id) {
        if (!$this->is_enabled()) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            $this->main_plugin->debug_log("[N8N] Product #{$product_id} not found or not a variable product. Skipping N8N send.");
            return;
        }

        $variations = $product->get_children();
        if (empty($variations)) {
            $this->main_plugin->debug_log("[N8N] No variations found for product #{$product_id}. Skipping N8N send.");
            return;
        }

        // **** شروع بخش اضافه شده ****
        // 1. خواندن زمان آخرین اسکرپ از دیتابیس
        $last_scraped_timestamp = get_post_meta($product_id, '_last_scraped_time', true);
        // 2. تبدیل به فرمت استاندارد ISO 8601 (بهترین فرمت برای API ها)
        $last_scraped_iso_date = $last_scraped_timestamp ? date('c', $last_scraped_timestamp) : null;
        // **** پایان بخش اضافه شده ****

        $payload = [];
        $parent_product_name = $product->get_name();
        $parent_product_link = $product->get_permalink();
        $purchase_link_text = !empty($this->settings['purchase_link_text']) ? $this->settings['purchase_link_text'] : __('View Product', 'wc-price-scraper');

        foreach ($variations as $variation_id) {
            $variation_product = wc_get_product($variation_id);
            if (!$variation_product || !$variation_product->is_type('variation')) {
                continue;
            }

            $variation_data = [
                'product_name'         => $parent_product_name,
                'parent_product_id'    => $product_id,
                'variation_id'         => $variation_id,
                'sku'                  => $variation_product->get_sku(),
                'color'                => $variation_product->get_attribute('pa_color'),
                'model'                => '', // Initialize model
                'price'                => (float) $variation_product->get_price(),
                'stock_status'         => $variation_product->get_stock_status(),
                'purchase_link_url'    => $parent_product_link,
                'purchase_link_text'   => $purchase_link_text,
                // **** اضافه کردن فیلد تاریخ به payload ****
                'last_scraped_at'      => $last_scraped_iso_date,
            ];

            // Get model attribute based on settings (comma-separated slugs)
            if (!empty($this->settings['model_attribute_slugs'])) {
                $model_slugs_input = explode(',', $this->settings['model_attribute_slugs']);
                foreach ($model_slugs_input as $single_slug_input) {
                    $trimmed_slug_input = trim($single_slug_input);
                    if (empty($trimmed_slug_input)) {
                        continue;
                    }
                    $model_slug_to_check = 'pa_' . sanitize_title($trimmed_slug_input);
                    $model_value = $variation_product->get_attribute($model_slug_to_check);
                    if (!empty($model_value)) {
                        $variation_data['model'] = $model_value;
                        break;
                    }
                }
            }
            
            $payload[] = $variation_data;
        }

        if (empty($payload)) {
            $this->main_plugin->debug_log("[N8N] No valid variation data to send for product #{$product_id}.");
            return;
        }

        $this->send_payload_to_n8n($payload, $product_id);
    }

    /**
     * Sends the prepared payload to the N8N webhook.
     *
     * @param array $payload The data to send.
     * @param int   $product_id For logging purposes.
     */
    private function send_payload_to_n8n(array $payload, $product_id) {
        $webhook_url = $this->settings['webhook_url'];
        $this->main_plugin->debug_log("[N8N] Attempting to send data for product #{$product_id} to {$webhook_url}. Payload: " . wp_json_encode($payload));

        $response = wp_remote_post($webhook_url, [
            'method'    => 'POST',
            'timeout'   => 45,
            'blocking'  => true,
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => wp_json_encode($payload),
            'sslverify' => apply_filters('wc_price_scraper_n8n_sslverify', true),
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->main_plugin->debug_log("[N8N] Error sending data for product #{$product_id}: {$error_message}");
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            if ($status_code >= 200 && $status_code < 300) {
                $this->main_plugin->debug_log("[N8N] Successfully sent data for product #{$product_id}. Status: {$status_code}. Response: " . substr($response_body, 0, 200));
            } else {
                $this->main_plugin->debug_log("[N8N] Failed to send data for product #{$product_id}. Status: {$status_code}. Response: {$response_body}");
            }
        }
    }
}
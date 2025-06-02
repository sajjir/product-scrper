<?php
if (!defined('ABSPATH')) exit;

class WCPS_Ajax_Cron {

    private $plugin;
    private $core;

    public function __construct(WC_Price_Scraper $plugin, WCPS_Core $core) {
        $this->plugin = $plugin;
        $this->core = $core;
    }

    public function scrape_price_callback() {
        @ini_set('display_errors', 0);
        while (ob_get_level()) ob_end_clean();
        
        if (!current_user_can('edit_products') || empty($_POST['product_id']) || empty($_POST['security']) || !wp_verify_nonce(sanitize_key($_POST['security']), 'scrape_price_nonce')) {
            wp_send_json_error(['message' => __('درخواست نامعتبر.', 'wc-price-scraper')]);
        }
        
        $pid = intval($_POST['product_id']);
        $product = wc_get_product($pid);
        if (!$product) {
            wp_send_json_error(['message' => __('محصول یافت نشد.', 'wc-price-scraper')]);
        }
        
        if (!$product->is_type('variable')) {
            $product_variable = new WC_Product_Variable($pid);
            $product_variable->save();
            $product = wc_get_product($pid);
        }
        
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error(['message' => __('محصول نتوانست به نوع متغیر تبدیل شود.', 'wc-price-scraper')]);
        }
        
        $source_url = $product->get_meta('_source_url');
        if (empty($source_url)) {
            wp_send_json_error(['message' => __('لینک منبع برای این محصول تنظیم نشده است.', 'wc-price-scraper')]);
        }
        
        $result = $this->core->process_single_product_scrape($pid, $source_url, true);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            update_post_meta($pid, '_last_scraped_time', current_time('timestamp'));
            if (isset($this->plugin->n8n_integration) && $this->plugin->n8n_integration->is_enabled()) {
                $this->plugin->n8n_integration->trigger_send_for_product($pid);
            }
            wp_send_json_success(['message' => __('اسکرپ با موفقیت انجام شد! واریشن‌ها به‌روز شدند.', 'wc-price-scraper'), 'scraped_data' => $result]);
        }
    }

    public function update_variation_price_callback() {
        if (!current_user_can('edit_products') || empty($_POST['variation_id']) || !isset($_POST['price'])) {
            wp_send_json_error(['message' => 'Invalid request']);
        }
        
        $var_id = intval($_POST['variation_id']);
        $price = floatval($_POST['price']);
        $variation = wc_get_product($var_id);
        
        if (!$variation) {
            wp_send_json_error(['message' => 'Variation not found']);
        }
        
        $variation->set_price($price);
        $variation->save();
        wp_send_json_success(['message' => 'Price updated']);
    }

    public function ajax_next_cron() {
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        wp_send_json_success(['time' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : 'Not scheduled']);
    }

    public function add_cron_interval($schedules) {
        $schedules['every_five_minutes'] = ['interval' => 300, 'display' => __('هر 5 دقیقه', 'wc-price-scraper')];
        return $schedules;
    }

    public function activate() {
        if (!wp_next_scheduled('wc_price_scraper_cron_event')) {
            wp_schedule_event(time() + 60, 'every_five_minutes', 'wc_price_scraper_cron_event');
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_price_scraper_cron_event');
        }
    }

    public function cron_update_all_prices() {
        $this->plugin->debug_log('Cron job started.');
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_source_url', 'compare' => 'EXISTS'],
                ['key' => '_source_url', 'value' => '', 'compare' => '!='],
                ['key' => '_auto_sync_variations', 'value' => 'yes']
            ]
        ];
        
        $products = get_posts($args);
        
        foreach ($products as $product) {
            $pid = $product->ID;
            $source_url = get_post_meta($pid, '_source_url', true);
            
            if ($source_url) {
                // ** این خط مهم در کد شما وجود نداشت **
                $result = $this->core->process_single_product_scrape($pid, $source_url, false);
                
                if (!is_wp_error($result)) {
                    update_post_meta($pid, '_last_scraped_time', current_time('timestamp'));
                }
                sleep(1); 
            }
        }

        // ** این خط به داخل تابع منتقل شد **
        $this->plugin->debug_log('Cron job finished.');
    } // <-- این تابع اینجا به درستی بسته می‌شود

} // <-- این هم انتهای کلاس
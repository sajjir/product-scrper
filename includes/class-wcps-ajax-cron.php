<?php
if (!defined('ABSPATH')) exit;

class WCPS_Ajax_Cron {

    private $plugin;
    private $core;
    private $schedule_name = 'wcps_custom_interval'; // A single, reliable schedule name

    public function __construct(WC_Price_Scraper $plugin, WCPS_Core $core) {
        $this->plugin = $plugin;
        $this->core = $core;
    }

    /**
     * Handles rescheduling the cron event. Called on settings save and by the manual button.
     */
    public function reschedule_cron_event() {
        // First, clear any existing scheduled events for this hook
        $this->deactivate();
        // Next, schedule the new event with the updated interval
        $this->activate();
    }

    /**
     * Schedules the cron event if it's not already scheduled.
     */
    public function activate() {
        if (!wp_next_scheduled('wc_price_scraper_cron_event')) {
            wp_schedule_event(time(), $this->schedule_name, 'wc_price_scraper_cron_event');
            $this->plugin->debug_log("Cron event scheduled with hook 'wc_price_scraper_cron_event'.");
        }
    }

    /**
     * Clears the scheduled cron event.
     */
    public function deactivate() {
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_price_scraper_cron_event');
        }
        // Also clear any other schedules for this hook for good measure
        wp_clear_scheduled_hook('wc_price_scraper_cron_event');
        $this->plugin->debug_log("All scheduled cron events for hook 'wc_price_scraper_cron_event' have been cleared.");
    }
    
    /**
     * Adds the custom interval to the list of cron schedules.
     */
    public function add_cron_interval($schedules) {
        $interval_minutes = get_option('wc_price_scraper_cron_interval', 30);
        if ($interval_minutes > 0) {
            $schedules[$this->schedule_name] = [
                'interval' => intval($interval_minutes) * 60,
                'display'  => sprintf(__('هر %d دقیقه (اسکرپر)', 'wc-price-scraper'), $interval_minutes)
            ];
        }
        return $schedules;
    }

    /**
     * Handles the AJAX request for the manual reschedule button.
     */
    public function ajax_force_reschedule_callback() {
        if (!current_user_can('manage_options') || !check_ajax_referer('wcps_reschedule_nonce', 'security')) {
            wp_send_json_error(['message' => 'درخواست نامعتبر.']);
        }
        
        $this->reschedule_cron_event();
        
        // Let's wait a second to make sure the new schedule is registered before we check for it.
        sleep(1);

        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        $new_time_display = $timestamp ? date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $timestamp) : 'برنامه‌ریزی نشده';

        wp_send_json_success([
            'message' => 'زمان‌بندی با موفقیت انجام شد.',
            'new_time_html' => 'زمان اجرای بعدی: ' . $new_time_display
        ]);
    }

    /**
     * Returns the time difference for the countdown timer. CORRECTED VERSION.
     */
    public function ajax_next_cron() {
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        $now = current_time('timestamp');
        $diff = $timestamp ? max(0, $timestamp - $now) : -1; // Return -1 if not scheduled
        wp_send_json_success(['diff' => $diff]);
    }

    /**
     * The main cron job function that scrapes all products.
     */
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
                $this->core->process_single_product_scrape($pid, $source_url, false);
                sleep(1); 
            }
        }
        $this->plugin->debug_log('Cron job finished.');
    }

    // --- Original functions from your file, unchanged ---

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
}
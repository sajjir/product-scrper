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
    public function reschedule_cron_event($start_immediately = false) {
        $this->deactivate(); // Clear any existing schedule
        $interval_hours = (int) get_option('wc_price_scraper_cron_interval', 12);
        if ($interval_hours <= 0) return; // Don't schedule if interval is invalid
        $interval_seconds = $interval_hours * 3600;
        $first_run_time = $start_immediately ? time() : time() + $interval_seconds;
        if (!wp_next_scheduled('wc_price_scraper_cron_event')) {
            wp_schedule_event($first_run_time, $this->schedule_name, 'wc_price_scraper_cron_event');
            $this->plugin->debug_log("Cron event scheduled. Next run at: " . date('Y-m-d H:i:s', $first_run_time));
        }
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
        $interval_hours = get_option('wc_price_scraper_cron_interval', 12);
        if ($interval_hours > 0) {
            $schedules[$this->schedule_name] = [
                'interval' => intval($interval_hours) * 3600, // 60 * 60
                'display'  => sprintf(__('هر %d ساعت (اسکرپر)', 'wc-price-scraper'), $interval_hours)
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
        // Save the interval value from the form
        if (isset($_POST['interval'])) {
            update_option('wc_price_scraper_cron_interval', intval($_POST['interval']));
        }
        $this->plugin->debug_log('Force starting cron job from admin button.');
        $this->cron_update_all_prices(); // Run the scrape immediately
        // Reschedule for the next run (in the future)
        $this->reschedule_cron_event(false);
        sleep(1);
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        $new_time_display = $timestamp ? date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $timestamp) : 'برنامه‌ریزی نشده';
        wp_send_json_success([
            'message' => 'فرآیند اسکرپ شروع شد و زمان‌بندی بعدی با موفقیت انجام گرفت.',
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
        $all_products = get_posts($args);
        $priority_cats = (array) get_option('wc_price_scraper_priority_cats', []);
        $priority_products = [];
        $other_products = [];
        if (!empty($priority_cats)) {
            foreach ($all_products as $product) {
                $product_cats = wp_get_post_terms($product->ID, 'product_cat', ['fields' => 'ids']);
                if (!empty(array_intersect($priority_cats, $product_cats))) {
                    $priority_products[] = $product;
                } else {
                    $other_products[] = $product;
                }
            }
            $sorted_products = array_merge($priority_products, $other_products);
        } else {
            $sorted_products = $all_products;
        }
        foreach ($sorted_products as $product) {
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
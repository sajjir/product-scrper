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
            $this->plugin->debug_log("Cron event scheduled. Next run at: " . date_i18n('Y-m-d H:i:s', $first_run_time));
        }
    }

    /**
     * Does nothing on plugin activation to prevent automatic runs.
     * The user must schedule the first event from the settings page.
     */
    public function activate() {
        // Do nothing.
    }

    /**
     * Clears ALL scheduled cron events for this plugin upon deactivation.
     */
    public function deactivate() {
        // Clear the recurring schedule hook
        $recurring_timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        if ($recurring_timestamp) {
            wp_unschedule_event($recurring_timestamp, 'wc_price_scraper_cron_event');
        }
        wp_clear_scheduled_hook('wc_price_scraper_cron_event');
        $this->plugin->debug_log("Recurring cron event 'wc_price_scraper_cron_event' has been cleared.");

        // Clear the one-off background job hook
        $onetime_timestamp = wp_next_scheduled('wcps_force_run_all_event');
        if ($onetime_timestamp) {
            wp_unschedule_event($onetime_timestamp, 'wcps_force_run_all_event');
        }
        wp_clear_scheduled_hook('wcps_force_run_all_event');
        $this->plugin->debug_log("One-off cron event 'wcps_force_run_all_event' has been cleared.");
    }

    /**
     * Handles the settings save action.
     * This function is triggered ONLY when the settings form is saved.
     * It schedules the RECURRING event to start IN THE FUTURE.
     */
    public function handle_settings_save() {
        $this->deactivate(); // Clear the old schedule first

        $interval_hours = (int) get_option('wc_price_scraper_cron_interval', 12);
        if ($interval_hours <= 0) {
            $this->plugin->debug_log('Cron interval is zero or less. No new schedule was set.');
            return;
        }

        // Schedule the first run to happen after the interval has passed.
        $future_timestamp = time() + ($interval_hours * 3600);
        wp_schedule_event($future_timestamp, $this->schedule_name, 'wc_price_scraper_cron_event');
        
        $this->plugin->debug_log('Recurring schedule was set via settings page. Next run at: ' . date_i18n('Y-m-d H:i:s', $future_timestamp));
    }

    /**
     * Handles the "Start Cron Job" button click.
     * This function ONLY schedules a one-off, immediate, background job.
     * It does NOT affect the main recurring schedule.
     */
    public function ajax_force_reschedule_callback() {
        if (!current_user_can('manage_options') || !check_ajax_referer('wcps_reschedule_nonce', 'security')) {
            wp_send_json_error(['message' => 'درخواست نامعتبر.']);
        }

        // Save the interval value from the form without triggering the recurring schedule hook.
        if (isset($_POST['interval'])) {
            remove_action('update_option_wc_price_scraper_cron_interval', [$this, 'handle_settings_save'], 10);
            update_option('wc_price_scraper_cron_interval', intval($_POST['interval']));
            add_action('update_option_wc_price_scraper_cron_interval', [$this, 'handle_settings_save'], 10, 3);
        }

        // Schedule the immediate background run
        wp_clear_scheduled_hook('wcps_force_run_all_event');
        wp_schedule_single_event(time() - 1, 'wcps_force_run_all_event');
        
        $this->plugin->debug_log('Force run event scheduled in background via button.');

        wp_send_json_success([
            'message' => 'فرآیند اسکرپ در پس‌زمینه شروع شد. لطفاً چند دقیقه منتظر بمانید.'
        ]);
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
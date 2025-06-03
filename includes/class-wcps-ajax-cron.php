<?php
if (!defined('ABSPATH')) exit;

class WCPS_Ajax_Cron {

    private $plugin;
    private $core;
    private $schedule_name = 'wcps_custom_interval';

    public function __construct(WC_Price_Scraper $plugin, WCPS_Core $core) {
        $this->plugin = $plugin;
        $this->core = $core;
    }

    /**
     * This function is called on plugin activation.
     * It now ONLY logs a message and does NOT schedule any events.
     */
    public function activate() {
        $this->plugin->debug_log('--- PLUGIN ACTIVATED: The new activate() function was called successfully. No schedule was set. ---');
    }

    /**
     * This function is called on plugin deactivation.
     * It clears ALL recurring and one-off hooks related to this plugin.
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wc_price_scraper_cron_event');
        wp_clear_scheduled_hook('wcps_force_run_all_event');
        $this->plugin->debug_log("--- PLUGIN DEACTIVATED: All cron schedules have been cleared. ---");
    }

    /**
     * Handles the settings save action from the settings page.
     * It schedules the RECURRING event to start IN THE FUTURE.
     */
    public function handle_settings_save() {
        $this->deactivate(); // Clear any old schedules first

        $interval_hours = (int) get_option('wc_price_scraper_cron_interval', 12);
        if ($interval_hours <= 0) {
            $this->plugin->debug_log('Cron interval is zero or less. No new schedule was set.');
            return;
        }

        $future_timestamp = time() + ($interval_hours * 3600);
        wp_schedule_event($future_timestamp, $this->schedule_name, 'wc_price_scraper_cron_event');
        $this->plugin->debug_log('Recurring schedule was set via settings page. Next run at: ' . date_i18n('Y-m-d H:i:s', $future_timestamp));
    }

    /**
     * Handles the "Start Cron Job" button click via AJAX.
     * It ONLY schedules a one-off, immediate, background job.
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

        wp_clear_scheduled_hook('wcps_force_run_all_event');
        wp_schedule_single_event(time() - 1, 'wcps_force_run_all_event');
        $this->plugin->debug_log('Force run event scheduled in background via button.');

        wp_send_json_success(['message' => 'فرآیند اسکرپ در پس‌زمینه شروع شد.']);
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

    // --- Other Functions ---
    public function add_cron_interval($schedules) {
        $interval_hours = get_option('wc_price_scraper_cron_interval', 12);
        if ($interval_hours > 0) {
            $schedules[$this->schedule_name] = [
                'interval' => intval($interval_hours) * 3600,
                'display'  => sprintf(__('هر %d ساعت (اسکرپر)', 'wc-price-scraper'), $interval_hours)
            ];
        }
        return $schedules;
    }

    public function ajax_next_cron() {
        $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
        $now = current_time('timestamp');
        $diff = $timestamp ? max(0, $timestamp - $now) : -1;
        wp_send_json_success(['diff' => $diff]);
    }
    
    // Original functions from your file, unchanged
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

    /**
     * AJAX handler for the Emergency Stop button.
     * Forcefully clears all known cron schedules for this plugin.
     */
    public function ajax_force_stop_all_crons() {
        if (!current_user_can('manage_options') || !check_ajax_referer('wcps_stop_nonce', 'security')) {
            wp_send_json_error(['message' => 'درخواست نامعتبر.']);
        }

        // Call the comprehensive deactivate function to clear everything.
        $this->deactivate();

        wp_send_json_success(['message' => 'تمام عملیات و زمان‌بندی‌ها با موفقیت پاک‌سازی شدند.']);
    }
}
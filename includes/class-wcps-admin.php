<?php
/**
 * Handles all admin-facing functionality for the WC Price Scraper plugin.
 *
 * @package WC_Price_Scraper/Admin
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WCPS_Admin {

    /**
     * The main plugin class instance.
     * @var WC_Price_Scraper
     */
    private $plugin;

    /**
     * Constructor.
     * @param WC_Price_Scraper $plugin The main plugin class.
     */
    public function __construct(WC_Price_Scraper $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Adds the plugin's settings page to the WordPress admin menu.
     */
    public function add_settings_page() {
        add_options_page(
            __('تنظیمات اسکرپر قیمت', 'wc-price-scraper'),
            __('اسکرپر قیمت', 'wc-price-scraper'),
            'manage_options',
            'wc-price-scraper',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registers all settings for the plugin.
     * This includes general settings and N8N integration settings.
     */
    public function register_settings() {
        $option_group = 'wc_price_scraper_group';

        // General Settings
        register_setting($option_group, 'wc_price_scraper_cron_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 12]);
        register_setting($option_group, 'wc_price_scraper_ignore_guarantee', ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '']);
        register_setting($option_group, 'wc_price_scraper_priority_cats', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_category_ids'], 'default' => []]);

        // N8N Integration Settings
        register_setting($option_group, 'wc_price_scraper_n8n_enable', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_checkbox_yes_no'], 'default' => 'no']);
        register_setting($option_group, 'wc_price_scraper_n8n_webhook_url', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '']);
        register_setting($option_group, 'wc_price_scraper_n8n_model_slug', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting($option_group, 'wc_price_scraper_n8n_purchase_link_text', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Buy Now']);
    }

    /**
     * Renders the settings page by including a separate view file.
     */
    public function render_settings_page() {
        require_once WC_PRICE_SCRAPER_PATH . 'views/admin-settings-page.php';
    }

    /**
     * Sanitizes an array of category IDs.
     * @param array $input The input array from the settings page.
     * @return array The sanitized array of integers.
     */
    public function sanitize_category_ids($input) {
        return is_array($input) ? array_map('absint', $input) : [];
    }

    /**
     * Sanitizes a checkbox value to either 'yes' or 'no'.
     * @param string $input The input from the checkbox.
     * @return string Returns 'yes' or 'no'.
     */
    public function sanitize_checkbox_yes_no($input) {
        return ($input === 'yes' || $input === 'on') ? 'yes' : 'no';
    }

    /**
     * Enqueues admin scripts and styles on the appropriate pages.
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_scripts($hook) {
    global $post;
    $screen = get_current_screen();

    // Script for the Product Edit page
    if ($screen && 'product' === $screen->id && ('post.php' === $hook || 'post-new.php' === $hook)) {
        wp_enqueue_script(
            'wc-price-scraper-js',
            WC_PRICE_SCRAPER_URL . 'js/price-scraper.js',
            ['jquery'],
            WC_PRICE_SCRAPER_VERSION,
            true
        );

        $product = $post ? wc_get_product($post->ID) : null;
        $default_attributes = $product ? $product->get_default_attributes() : [];

        wp_localize_script('wc-price-scraper-js', 'price_scraper_vars', [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'security'           => wp_create_nonce('scrape_price_nonce'),
            'product_id'         => $post ? $post->ID : 0,
            'default_attributes' => $default_attributes,
            'loading_text'       => __('در حال اسکرپ...', 'wc-price-scraper'),
            'success_text'       => __('اسکرپ با موفقیت انجام شد! در حال بارگذاری مجدد...', 'wc-price-scraper'),
            'error_text'         => __('اسکرپ ناموفق: ', 'wc-price-scraper'),
            'unknown_error'      => __('خطای ناشناخته.', 'wc-price-scraper'),
            'ajax_error'         => __('خطای AJAX: ', 'wc-price-scraper'),
            ]);
        }

    // Script for the plugin's Settings page
     if ($screen && 'settings_page_wc-price-scraper' === $screen->id) {
        wp_enqueue_script(
            'wc-price-scraper-settings-js',
            WC_PRICE_SCRAPER_URL . 'js/settings-countdown.js',
            ['jquery'],
            WC_PRICE_SCRAPER_VERSION,
            true
        );
        
        // ++ این بخش بسیار مهم است ++
        wp_localize_script('wc-price-scraper-settings-js', 'wc_scraper_settings_vars', [
            'ajax_url'         => admin_url('admin-ajax.php'),
            'next_cron_action' => 'wc_price_scraper_next_cron',
            'reschedule_nonce' => wp_create_nonce('wcps_reschedule_nonce') // اطمینان حاصل کنید این خط وجود دارد
        ]);
        }
    }

    /**
     * Adds custom fields to the 'General' tab of the WooCommerce product data meta box.
     */
    public function add_scraper_fields() {
        global $post;
        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id'          => '_source_url',
            'label'       => __('لینک منبع قیمت', 'wc-price-scraper'),
            'desc_tip'    => true,
            'description' => __('لینک کامل محصول در سایت مرجع را وارد کنید.', 'wc-price-scraper')
        ]);
        
        woocommerce_wp_checkbox([
            'id'          => '_auto_sync_variations',
            'label'       => __('همگام‌سازی خودکار', 'wc-price-scraper'),
            'description' => __('با فعال بودن این گزینه، محصول در به‌روزرسانی‌های خودکار (کرون‌جاب) بررسی می‌شود.', 'wc-price-scraper')
        ]);

        woocommerce_wp_text_input([
            'id'          => '_product_price_adjustment',
            'label'       => __('تنظیم قیمت (درصد)', 'wc-price-scraper'),
            'type'        => 'number',
            'desc_tip'    => true,
            'description' => __('یک عدد برای تغییر قیمت وارد کنید. مثال: 10 برای 10% افزایش یا -5 برای 5% کاهش.', 'wc-price-scraper'),
            'custom_attributes' => ['step' => 'any']
        ]);
        
        echo '<p class="form-field scrape-controls">' .
             '<button type="button" class="button button-primary" id="scrape_price">' . __('اسکرپ قیمت اکنون', 'wc-price-scraper') . '</button>' .
             '<span class="spinner"></span>' .
             '<span id="scrape_status" style="margin-right:10px;"></span>' .
             '</p>';

        $last_scraped_timestamp = get_post_meta($post->ID, '_last_scraped_time', true);
        if ($last_scraped_timestamp) {
            $display_time = date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $last_scraped_timestamp);
            echo '<p class="form-field"><strong>' . __('آخرین اسکرپ موفق:', 'wc-price-scraper') . '</strong> ' . esc_html($display_time) . '</p>';
        }
        
        echo '</div>';
    }

    /**
     * Saves the custom scraper fields when a product is saved.
     * @param int     $post_id The ID of the post being saved.
     * @param WP_Post $post The post object.
     */
    public function save_scraper_fields($post_id, $post) {
        if (isset($_POST['_source_url'])) {
            update_post_meta($post_id, '_source_url', esc_url_raw($_POST['_source_url']));
        }
        if (isset($_POST['_product_price_adjustment'])) {
            update_post_meta($post_id, '_product_price_adjustment', sanitize_text_field($_POST['_product_price_adjustment']));
        }
        update_post_meta($post_id, '_auto_sync_variations', isset($_POST['_auto_sync_variations']) ? 'yes' : 'no');
    }

    /**
     * Adds a "protected" checkbox to the variation pricing options.
     * @param int     $loop           Position in the loop.
     * @param array   $variation_data Variation data.
     * @param WP_Post $variation      The variation post object.
     */
    public function add_protected_variation_checkbox($loop, $variation_data, $variation) {
        woocommerce_wp_checkbox([
            'id'            => '_wcps_is_protected[' . $variation->ID . ']',
            'name'          => '_wcps_is_protected[' . $variation->ID . ']',
            'label'         => __('محافظت از این واریشن', 'wc-price-scraper'),
            'description'   => __('اگر تیک بخورد، این واریشن در همگام‌سازی خودکار حذف یا آپدیت نخواهد شد.', 'wc-price-scraper'),
            'value'         => get_post_meta($variation->ID, '_wcps_is_protected', true),
            'desc_tip'      => true,
        ]);
    }

    /**
     * Saves the "protected" status of a variation.
     * @param int $variation_id The ID of the variation being saved.
     * @param int $i            The loop index.
     */
    public function save_protected_variation_checkbox($variation_id, $i) {
        $is_protected = isset($_POST['_wcps_is_protected'][$variation_id]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_wcps_is_protected', $is_protected);
    }

    /**
     * Sets a unique SKU for a new variation if it's empty upon saving.
     * @param int $variation_id The ID of the variation being saved.
     * @param int $i            The loop index.
     */
    public function set_variation_sku_on_manual_save($variation_id, $i) {
        $variation = wc_get_product($variation_id);
        if ($variation && empty($variation->get_sku())) {
            $variation->set_sku((string) $variation_id);
            $variation->save();
        }
    }
}
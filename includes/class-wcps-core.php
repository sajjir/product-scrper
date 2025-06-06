<?php
if (!defined('ABSPATH')) exit;

class WCPS_Core {

    private $plugin;

    public function __construct(WC_Price_Scraper $plugin) {
        $this->plugin = $plugin;
    }

    public function process_single_product_scrape($pid, $url, $is_ajax = false) {
        $this->plugin->debug_log("Starting scrape for product #{$pid} from URL: {$url}");
        $raw_data = $this->plugin->make_api_call(WC_PRICE_SCRAPER_API_ENDPOINT . '?url=' . urlencode($url));

        if (is_wp_error($raw_data)) {
            $this->set_all_product_variations_outof_stock($pid);
            update_post_meta($pid, '_scraped_data', []);
            return $raw_data;
        }

        $data = json_decode($raw_data, true);
        if (!is_array($data) || empty($data)) {
            $this->set_all_product_variations_outof_stock($pid);
            update_post_meta($pid, '_scraped_data', []);
            return new WP_Error('no_data', __('داده معتبری از API اسکرپینگ دریافت نشد.', 'wc-price-scraper'));
        }

        // ===================================================================
        // +++ START: NEW ADVANCED FILTERING LOGIC +++
        // ===================================================================

        // --- Step 1: Apply Conditional Removal Rules (with fallback) ---
        $conditional_rules = get_option('wcps_conditional_rules', []);
        $data_after_conditional_filter = $data;

        if (!empty($conditional_rules)) {
            $this->plugin->debug_log("Applying conditional rules for product #{$pid}", $conditional_rules);
            $temp_data = $data; // Work on a temporary copy

            foreach ($conditional_rules as $rule) {
                $key_to_check = $rule['key'];
                $value_to_ignore = $rule['value'];

                // Create a list of items that DON'T match the ignore rule
                $filtered_tentatively = array_values(array_filter($temp_data, function ($item) use ($key_to_check, $value_to_ignore) {
                    return !isset($item[$key_to_check]) || $item[$key_to_check] != $value_to_ignore;
                }));

                // THE CORE LOGIC: If filtering leaves at least one item, apply it. Otherwise, ignore the rule.
                if (!empty($filtered_tentatively)) {
                    $temp_data = $filtered_tentatively; // The filter was successful, update the list
                    $this->plugin->debug_log("Rule ({$key_to_check} = {$value_to_ignore}) applied. Items left: " . count($temp_data));
                } else {
                    // The filter would remove everything, so we ignore this rule and log it.
                    $this->plugin->debug_log("Rule ({$key_to_check} = {$value_to_ignore}) ignored because it would remove all variations.");
                }
            }
            $data_after_conditional_filter = $temp_data;
        }

        // --- Step 2: Apply "Always Hide & Deduplicate" Logic ---
        $always_hide_keys = array_filter(array_map('trim', explode("\n", get_option('wcps_always_hide_keys', ''))));
        $final_data = [];

        if (!empty($always_hide_keys)) {
            $this->plugin->debug_log("Applying always-hide/deduplicate logic for product #{$pid}", $always_hide_keys);
            $seen_fingerprints = [];
            foreach ($data_after_conditional_filter as $item) {
                $core_attributes = $item;
                foreach ($always_hide_keys as $key_to_hide) {
                    unset($core_attributes[$key_to_hide]);
                }
                
                // Create a unique fingerprint based on remaining attributes
                $fingerprint = md5(json_encode($core_attributes));

                if (!in_array($fingerprint, $seen_fingerprints)) {
                    $seen_fingerprints[] = $fingerprint;
                    $final_data[] = $item; // Add the ORIGINAL item to keep all its data for now
                }
            }
        } else {
            // If no hide/deduplicate rules, just use the data from the conditional filter
            $final_data = $data_after_conditional_filter;
        }

        // Ensure we remove duplicates even if no rules are set
        $final_data = array_values(array_map('unserialize', array_unique(array_map('serialize', $final_data))));
        
        // ===================================================================
        // +++ END: NEW ADVANCED FILTERING LOGIC +++
        // ===================================================================

        if (empty($final_data)) {
            $this->set_all_product_variations_outof_stock($pid);
            update_post_meta($pid, '_scraped_data', []);
            return new WP_Error('filtered_out', __('همه داده‌ها توسط قوانین فیلترینگ حذف شدند.', 'wc-price-scraper'));
        }

        // Use the processed data from now on
        update_post_meta($pid, '_scraped_data', $final_data);

        $auto_sync_enabled = get_post_meta($pid, '_auto_sync_variations', true) === 'yes';
        if ($is_ajax || $auto_sync_enabled) {
            $this->sync_product_variations($pid, $final_data);
        }

        return $final_data;
    }

    public function sync_product_variations($pid, $scraped_data) {
    $this->plugin->debug_log("Starting smart variation sync for product #{$pid}");
    $parent_product = wc_get_product($pid);
    if (!$parent_product || !$parent_product->is_type('variable')) {
        $this->plugin->debug_log("Parent product #{$pid} not found or not variable for sync.");
        return;
    }

    $this->prepare_parent_attributes_stable($pid, $scraped_data);

    $existing_variation_ids = $parent_product->get_children();
    $unprotected_variations_map = [];
    foreach ($existing_variation_ids as $var_id) {
        if (get_post_meta($var_id, '_wcps_is_protected', true) === 'yes') continue;
        $variation = wc_get_product($var_id);
        if (!$variation) continue;
        $attributes = $variation->get_attributes();
        ksort($attributes);
        $unprotected_variations_map[md5(json_encode($attributes))] = $var_id;
    }

    $created_or_updated = [];
    foreach ($scraped_data as $item) {
        $attr_data = [];
        
        // ---- این بخش اصلاح شده، اسلاگ صحیح را از دیتابیس می‌خواند ----
        foreach ($item as $k => $v) {
            if (in_array(strtolower($k), ['price', 'stock', 'url', 'image', 'seller']) || $v === '' || $v === null) continue;
            
            $clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $k)));
            $taxonomy = 'pa_' . $clean_key;
            $term_name = is_array($v) ? ($v['label'] ?? $v['name']) : $v;

            if (empty($term_name)) continue;

            // پیدا کردن ترم بر اساس نام برای گرفتن اسلاگ صحیح
            $term = get_term_by('name', $term_name, $taxonomy);

            if ($term && !is_wp_error($term)) {
                $attr_data[$taxonomy] = $term->slug; // استفاده از اسلاگ صحیح
            } else {
                $attr_data[$taxonomy] = sanitize_title($term_name);
                $this->plugin->debug_log("Warning: Term '{$term_name}' not found for taxonomy '{$taxonomy}'. Used a generated slug as fallback.");
            }
        }
        
        if (empty($attr_data)) continue;

        ksort($attr_data);
        $variation_hash = md5(json_encode($attr_data));
        $var_id = $unprotected_variations_map[$variation_hash] ?? null;

        $variation = ($var_id) ? wc_get_product($var_id) : new WC_Product_Variation();
        if (!$var_id) {
            $variation->set_parent_id($pid);
        }
        
        $variation->set_attributes($attr_data);
        
        if (isset($item['price']) && is_numeric(preg_replace('/[^0-9.]/', '', $item['price']))) {
            $price = preg_replace('/[^0-9.]/', '', $item['price']);
            $variation->set_price($price);
            $variation->set_regular_price($price);
        }
        if (isset($item['stock'])) {
            $stock_status = (strpos($item['stock'], 'موجود') !== false || strpos($item['stock'], 'in_stock') !== false) ? 'instock' : 'outofstock';
            $variation->set_stock_status($stock_status);
        }
        
        $variation_id = $variation->save();

        if (empty($variation->get_sku())) {
            $variation->set_sku((string)$variation_id);
            $variation->save();
        }

        $created_or_updated[] = $variation_id;
    }

    $variations_to_delete = array_diff(array_values($unprotected_variations_map), $created_or_updated);
    foreach ($variations_to_delete as $var_id_to_delete) {
        wp_delete_post($var_id_to_delete, true);
        $this->plugin->debug_log("Deleted obsolete variation #{$var_id_to_delete}.");
    }
    $this->plugin->debug_log("Smart variation sync complete for product #{$pid}.");
    wc_delete_product_transients($pid);
}

    /**
     * ++++++++++ تابع حل کننده مشکل اصلی ++++++++++
     * این تابع از روش پایدار و قدیمی برای ساخت آرایه ویژگی‌ها و ذخیره مستقیم آن استفاده می‌کند.
     */
    public function prepare_parent_attributes_stable($pid, $scraped_data) {
        $this->plugin->debug_log("Preparing parent attributes using STABLE method for product #{$pid}.");

        $attribute_keys_cleaned = [];
        foreach ($scraped_data as $row) {
            foreach ($row as $k => $v) {
                if (in_array(strtolower($k), ['price', 'stock', 'url', 'image', 'seller']) || $v === '' || $v === null) continue;
                $clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $k)));
                if (!in_array($clean_key, $attribute_keys_cleaned)) $attribute_keys_cleaned[] = $clean_key;
            }
        }
        sort($attribute_keys_cleaned);

        $product_attributes = [];
        foreach ($attribute_keys_cleaned as $index => $attr_key_clean) {
            $taxonomy_name = 'pa_' . $attr_key_clean;
            if (!taxonomy_exists($taxonomy_name)) {
                $attribute_label = ucfirst(str_replace(['_', '-'], ' ', $attr_key_clean));
                wc_create_attribute(['name' => $attribute_label, 'slug' => $attr_key_clean]);
                $this->plugin->debug_log("Created global attribute: {$taxonomy_name}");
            }

            $all_terms_for_this_attr = [];
            foreach ($scraped_data as $item) {
                foreach ($item as $key => $value) {
                    $current_clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $key)));
                    if ($current_clean_key === $attr_key_clean && !empty($value)) {
                        $all_terms_for_this_attr[] = is_array($value) ? $value['label'] : $value;
                    }
                }
            }
            $all_terms_for_this_attr = array_unique($all_terms_for_this_attr);

            $term_slugs_to_set = [];
            foreach ($all_terms_for_this_attr as $term_name) {
                $term = get_term_by('name', $term_name, $taxonomy_name);
                if (!$term) {
                    $term_result = wp_insert_term($term_name, $taxonomy_name);
                    if (!is_wp_error($term_result)) {
                        $term_slugs_to_set[] = $term_result['slug'];
                    }
                } else {
                    $term_slugs_to_set[] = $term->slug;
                }
            }

            wp_set_object_terms($pid, $term_slugs_to_set, $taxonomy_name, false);
            $this->plugin->debug_log("Set terms for {$taxonomy_name} on product #{$pid}:", $term_slugs_to_set);

            // روش پایدار و قدیمی برای ساخت آرایه
            $product_attributes[$taxonomy_name] = [
                'name'         => $taxonomy_name,
                'value'        => '', // برای ویژگی‌های مبتنی بر taxonomy باید خالی باشد
                'position'     => $index,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 1,
            ];
        }

        // ذخیره مستقیم در دیتابیس برای اطمینان از عملکرد صحیح
        update_post_meta($pid, '_product_attributes', $product_attributes);
        $this->plugin->debug_log("Updated _product_attributes meta directly for product #{$pid}.", $product_attributes);
        wc_delete_product_transients($pid);
    }

    public function set_all_product_variations_outof_stock($pid) {
        $product = wc_get_product($pid);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') continue;
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variation->set_stock_status('outofstock');
                    $variation->save();
                }
            }
        }
    }
}
jQuery(document).ready(function($) {
    // تابع تنظیم مقادیر پیش‌فرض شما (بدون تغییر)
    function setDefaultAttributeDropdowns() {
        if (price_scraper_vars.default_attributes) {
            $.each(price_scraper_vars.default_attributes, function(attribute_slug, term_slug) {
                var $dropdown = $('select[name="default_attribute_' + attribute_slug + '"]');
                if ($dropdown.length > 0) {
                    $dropdown.val(term_slug).trigger('change');
                }
            });
        }
    }
    setDefaultAttributeDropdowns();

    // رویداد کلیک دکمه اصلی (با یک بهینه‌سازی کوچک)
    $('#scrape_price').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var controls = button.closest('.scrape-controls');
        var status_span = controls.find('#scrape_status');
        var spinner = controls.find('.spinner');
        
        // بهینه‌سازی: استفاده از product_id که مستقیماً از PHP آمده
        var product_id = price_scraper_vars.product_id;

        if (!product_id) {
            status_span.text(price_scraper_vars.error_text + 'Product ID not found.').css('color', 'red');
            return;
        }

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        status_span.text(price_scraper_vars.loading_text).css('color', '');

        $.ajax({
            url: price_scraper_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'scrape_price',
                product_id: product_id,
                security: price_scraper_vars.security
            },
            success: function(response) {
                if (response.success) {
                    status_span.text(price_scraper_vars.success_text).css('color', 'green');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    status_span.text(price_scraper_vars.error_text + (response.data.message || price_scraper_vars.unknown_error)).css('color', 'red');
                }
            },
            error: function(xhr) {
                status_span.text(price_scraper_vars.ajax_error + (xhr.statusText || 'Unknown Error')).css('color', 'red');
            },
            complete: function() {
                button.prop('disabled', false);
                spinner.removeClass('is-active').hide();
            }
        });
    });
});
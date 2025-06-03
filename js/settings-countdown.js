jQuery(document).ready(function($) {
    var el = $('#cron_countdown');
    var initial_seconds = -1;

    if (el.length > 0 && typeof el.data('seconds-left') !== 'undefined') {
        initial_seconds = parseInt(el.data('seconds-left'));
    }

    function tick() {
        if (initial_seconds < 0) {
            el.text('--:--');
            return;
        }
        var m = Math.floor(initial_seconds / 60);
        var s = initial_seconds % 60;
        el.text((m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s);
        
        if (initial_seconds > 0) {
            initial_seconds--;
            setTimeout(tick, 1000);
        } else {
             el.text('در حال اجرا...');
        }
    }

    function refreshAjax() {
        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: { action: wc_scraper_settings_vars.next_cron_action },
            success: function(response) {
                if (response.success && typeof response.data.diff !== "undefined") {
                    initial_seconds = parseInt(response.data.diff);
                    if (!$("#cron_countdown:hover").length) { // Only restart if not hovering
                         tick();
                    }
                } else {
                     initial_seconds = -1;
                     el.text('--:--');
                }
            }
        });
    }

    // Start the countdown
    tick();
    // Refresh every 30 seconds
    setInterval(refreshAjax, 30000);

    // --- Manual Reschedule Button ---
    $('#force_reschedule_button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusSpan = $('#reschedule_status');
        var spinner = button.siblings('.spinner');
        var interval_val = $('#wc_price_scraper_cron_interval').val();

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        
        // Optimistic UI: Assume success immediately
        statusSpan.text('درخواست اجرای پس‌زمینه ارسال شد...').css('color', 'green');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_force_reschedule',
                security: wc_scraper_settings_vars.reschedule_nonce,
                interval: interval_val
            }
        });
        
        // Reload the page to show the effect
        setTimeout(function() {
            location.reload();
        }, 2000);
    });

    // --- Emergency Stop Button ---
    $('#force_stop_button').on('click', function(e) {
        e.preventDefault();
        if (!confirm('آیا مطمئن هستید؟ این عمل تمام فرآیندهای زمان‌بندی شده این پلاگین را متوقف می‌کند.')) {
            return;
        }

        var button = $(this);
        var statusSpan = $('#stop_status');
        var mainSpinner = button.closest('td').find('.spinner');

        button.prop('disabled', true);
        mainSpinner.addClass('is-active').css('display', 'inline-block');

        // Optimistic UI: Assume success immediately
        statusSpan.text('دستور توقف با موفقیت ارسال شد...').css('color', 'green');
        
        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_force_stop',
                security: wc_scraper_settings_vars.stop_nonce
            }
        });
        
        // Reload the page to show the cleared schedule
        setTimeout(function() {
            location.reload();
        }, 2000);
    });
});
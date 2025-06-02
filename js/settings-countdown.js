jQuery(document).ready(function($) {
    var el = $('#cron_countdown');
    // Ensure the element exists and has the data-seconds-left attribute
    if (el.length === 0 || typeof el.data('seconds-left') === 'undefined') {
        return;
    }

    var sec = parseInt(el.data('seconds-left')); // Get initial seconds from PHP via data attribute

    // Function to update the countdown display
    function tick() {
        if (sec < 0) {
            sec = 0; // Don't go negative
        }
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        el.text((m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s);
        sec--;

        if (sec >= 0) {
            setTimeout(tick, 1000); // Continue ticking
        } else {
            // Once countdown reaches zero, refresh to get the new time from server
            refreshAjax();
        }
    }

    // Function to refresh the countdown by making an AJAX call
    function refreshAjax() {
        $.ajax({
            url: wc_scraper_settings_vars.ajax_url, // WordPress AJAX URL
            type: 'POST',
            data: {
                action: wc_scraper_settings_vars.next_cron_action // AJAX action hook
            },
            success: function(response) {
                if (response.success && typeof response.data.diff !== "undefined") {
                    sec = parseInt(response.data.diff); // Update seconds with new value from server
                    tick(); // Restart countdown
                } else {
                    el.text('Error fetching time');
                }
            },
            error: function() {
                el.text('Error fetching time');
            }
        });
    }

    // Start the countdown initially
    tick();

    // Refresh the countdown via AJAX every 10 seconds to keep it accurate
    setInterval(refreshAjax, 10000);
});


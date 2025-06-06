jQuery(document).ready(function($) {
    $('#scrape_price').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var originalText = button.text();
        var statusSpan = button.siblings('#scrape_status');

        if (!WCPR.product_id) {
            alert('شناسه محصول یافت نشد. لطفاً ابتدا محصول را ذخیره کنید.');
            return;
        }

        button.prop('disabled', true).text('در حال اسکرپ...');
        statusSpan.text('').removeClass('success error');

        $.ajax({
            url: WCPR.ajax_url,
            type: 'POST',
            data: {
                action: 'scrape_price',
                product_id: WCPR.product_id,
                security: WCPR.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.text(response.data.message).addClass('success');
                    alert('اسکرپ با موفقیت انجام شد! صفحه برای نمایش تغییرات مجدداً بارگذاری می‌شود.');
                    window.location.reload();
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : 'خطای ناشناخته رخ داد.';
                    statusSpan.text('خطا: ' + errorMessage).addClass('error');
                    alert('خطا در اسکرپ: ' + errorMessage);
                }
            },
            error: function(xhr) {
                var errorMessage = xhr.statusText ? xhr.statusText : 'خطای ارتباطی.';
                statusSpan.text('خطای AJAX: ' + errorMessage).addClass('error');
                alert('خطای AJAX: ' + errorMessage);
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});
/////فثس
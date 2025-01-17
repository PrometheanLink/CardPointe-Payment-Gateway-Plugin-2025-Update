(function ($) {
    $( document ).on( 'click', '.wc-cc-notice .notice-dismiss', function() {
        var data = {
            action: 'cc_dismiss_admin_notice'
        };

        $.post( notice_params.ajaxurl, data, function() {
        });
    });
})(jQuery);
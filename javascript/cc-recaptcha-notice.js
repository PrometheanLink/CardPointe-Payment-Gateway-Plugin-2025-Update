(function ($) {
  $( document ).on( 'click', '.wc-recaptcha-notice .notice-dismiss', function() {
    var data = {
      action: 'cc_recaptcha_dismiss_admin_notice'
    };

    $.post( notice_params.ajaxurl, data, function() {
    });
  });
})(jQuery);

(function ($) {
  console.log('textarea');
  $('td.custom_button a').on('click', function (evt) {
    evt.preventDefault();
    var selected = $('select#woocommerce_card_connect_cc_banned').val();
    var data = {
      'action': 'admin_banned_cards',
      'card_to_remove': selected,
      '_ajax_nonce': ajax_var.nonce,
    };
    $.ajax({
      url: ajax_var.url,
      type: 'POST',
      data: data,
      dataType: 'json',
      success: function (response) {
        if (response === 'refresh') {
          $.each(selected, function (index, val) {
            $("select#woocommerce_card_connect_cc_banned option[value='" + val + "']").remove();
          });
          setTimeout(function () {
            // save the page, just in case
            $('button.button-primary.woocommerce-save-button').click();
          }, 500);
        } else {
          alert(response);
        }
      }
    });
    console.log(selected);
  });
})(jQuery);

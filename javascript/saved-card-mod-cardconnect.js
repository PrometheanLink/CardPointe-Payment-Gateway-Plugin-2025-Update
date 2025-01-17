(function ($) {
    $('body').on('change', 'select#card_connect-cards', disableExtraFields);

    function disableExtraFields(elem) {
        var inputEXP = $('input#card_connect-card-expiry');
        var inputCVC = $('input#card_connect-card-cvc');
        var wrapElemExp = inputEXP.closest('p');
        var wrapElemCVC = inputCVC.closest('p');
        var x = $('select#card_connect-cards').val();
        var hide_when_using_saved = $('fieldset#card_connect-cc-form p.form-row[data-saved_hidden="true"]');
        var save_card_message = $('p.saved_card_message');
        if (x === "") {
            inputEXP.prop('disabled', false);
            inputCVC.prop('disabled', false);
            inputCVC.val('');
            wrapElemExp.removeClass('woocommerce-validated');
            wrapElemCVC.removeClass('woocommerce-validated');
            wrapElemExp.addClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            wrapElemCVC.addClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            hide_when_using_saved.show();
            save_card_message.text('').hide();
        } else {
            inputEXP.prop('disabled', 'disabled');
            inputCVC.prop('disabled', 'disabled');
            inputCVC.val('999');
            wrapElemExp.addClass('woocommerce-validated');
            wrapElemCVC.addClass('woocommerce-validated');
            wrapElemExp.removeClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            wrapElemCVC.removeClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            hide_when_using_saved.hide();
            save_card_message.text('Your chosen saved card will be used.').show();
        }
    }
})(jQuery);
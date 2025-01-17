<?php
	if (!defined('ABSPATH')) exit; // Exit if accessed directly

//		WC()->session->set_customer_session_cookie(true);
//		$test = WC()->session->get_session_cookie();
// 19a7f4f811165ce4e673f8d2d084b788
?>

<div class="js-card-connect-errors"></div>
<fieldset id="card_connect-cc-form">
	<p class="form-row form-row-wide"><?php echo sanitize_text_field($description); ?></p>
	<p class="form-row form-row-wide">

	<p style="margin: 0 0 5px;">Accepting:</p>
	<ul class="card-connect-allowed-cards"><?php echo wp_kses($card_icons, ['img' => ['src' => [], 'alt' => [], 'class' => []], 'li' => ['class' => []]]); ?></ul>
	<?php if ($profiles_enabled) {
		wc_get_template('saved-cards.php', array(
			'saved_cards' => $saved_cards,
		), WC_CARDCONNECT_PLUGIN_PATH, WC_CARDCONNECT_TEMPLATE_PATH);
	} ?>
	<p data-saved_hidden="true" class="form-row form-row-wide">
		<label for="card_connect-card-name">
			<?php echo sanitize_text_field(__('Cardholder Name (If Different)', 'woocommerce')); ?>
		</label>
		<input
			id="card_connect-card-name"
			class="input-text"
			type="text"
			maxlength="25"
			name="card_connect-card-name"
		/>
	</p>
	<p data-saved_hidden="true" class="form-row form-row-wide validate-required">
		<label for="card_connect-card-number">
			<?php echo sanitize_text_field(__('Card Number', 'woocommerce')); ?>
			<span class="required">*</span>
		</label>
		<?php // the sandbox effectively forces iframe - we should remove this at some point
			ob_start();
			$is_js = false;

			if ($is_iframe && ($args["recaptcha"]["enabled"] === 'yes' && !empty($args["recaptcha"]["secret"]) && !empty($args["recaptcha"]["site"]))) { ?>
				<iframe
					width="100%"
					style="margin-bottom: 0;"
					id="card_connect-iframe"
					src="<?php echo $is_autostyle ? esc_url($iframe_src) : sprintf('%s&css=%s', esc_url($iframe_src), urlencode(esc_attr($iframe_style))); ?>"
					frameborder="0"
					scrolling="no">
				</iframe>
			<?php } elseif (!$is_iframe && (!empty($args["recaptcha"]["secret"]) && !empty($args["recaptcha"]["site"]))) {
				$is_js = true;
				?>
				<input
					id="card_connect-card-number"
					class="input-text wc-credit-card-form-card-number validate-required"
					type="text"
					maxlength="20"
					autocomplete="off"
					placeholder="•••• •••• •••• ••••"
				/>
				<?php
			} elseif ($args["is_sandbox"] === true || (current_time('timestamp') <= strtotime("June 14th, 2021 00:00:00"))) { ?>
				<iframe
					width="100%"
					style="margin-bottom: 0;"
					id="card_connect-iframe"
					src="<?php echo $is_autostyle ? esc_url($iframe_src) : sprintf('%s&css=%s', esc_url($iframe_src), urlencode(esc_attr($iframe_style))); ?>"
					frameborder="0"
					scrolling="no">
				</iframe>
			<?php } else {
				if (current_user_can('administrator')) {
					echo wp_kses('<p>You must enable ReCaptcha in your CardConnect/CardPointe settings.</p>', ['p' => []]);
				} else {
					echo wp_kses('<p>Please contact the site owner about this checkout issue.</p>', ['p' => []]);
				}
			}
			if ($is_js) {
				echo wp_kses(ob_get_clean(), [
					'input' => [
						'id'           => [],
						'class'        => [],
						'type'         => [],
						'maxlength'    => [],
						'autocomplete' => [],
						'placeholder'  => [],
					],
				]);
			} else {
				echo wp_kses(ob_get_clean(), [
					'iframe' => [
						'width'       => [],
						'style'       => [],
						'id'          => [],
						'src'         => [],
						'frameborder' => [],
						'scrolling'   => [],
					],
				]);
			}
		?>
	</p>
	<p data-saved_hidden="true" class="form-row form-row-first validate-required">
		<label for="card_connect-card-expiry">
			<?php echo sanitize_text_field(__('Expiry (MM/YY)', 'woocommerce')); ?>
			<span class="required">*</span>
		</label>
		<input
			id="card_connect-card-expiry"
			class="input-text wc-credit-card-form-card-expiry"
			type="text"
			autocomplete="off"
			placeholder="<?php echo sanitize_text_field(__('MM / YY', 'woocommerce')); ?>"
			name="card_connect-card-expiry"
		/>
	</p>
	<p data-saved_hidden="true" class="form-row form-row-last validate-required">
		<label for="card_connect-card-cvc">
			<?php echo sanitize_text_field(__('Card Code', 'woocommerce')); ?>
			<span class="required">*</span>
		</label>
		<input
			id="card_connect-card-cvc"
			class="input-text wc-credit-card-form-card-cvc"
			type="text"
			autocomplete="off"
			placeholder="<?php echo sanitize_text_field(__('CVC', 'woocommerce')); ?>"
			name="card_connect-card-cvc"
		/>
		<em><?php echo sanitize_text_field(__('Your CVV number will not be stored on our server.', 'woocommerce')); ?></em>
	</p>

	<?php
		if ('yes' === $recaptcha["enabled"]) {
			$site_key = $recaptcha["site"];
			$path = WC_CARDCONNECT_ASSETS_URL;
			$refresh_icon = $path . 'refresh.png';
			$theme = $recaptcha["dark"] == 'yes' ? 'dark' : 'light';

			?>
			<style>

				div#bc_captcha {
					width: 100%;
					display: inline-block;
					margin: 0 auto !important;
					text-align: center;
					padding-top: 10px;
				}

				div#bc_captcha .woocommerce-form-row.woocommerce-form-row--wide.form-row.form-row-wide.g-recaptcha {
					display: inline-block;
					position: relative;
					left: 0;
					right: 0;
				}

				.captchaRefresh {
					text-align: right;
					width: 100%;
				}

				img.imgCaptchaRefresh {
					max-width: 13px;
				}

				div#bc_captcha iframe {
					width: 100% !important;
				}

			</style>
		<?php
		?>
			<script type="text/javascript">
				var refresh_captcha_callback = function () {
				};
				var recaptcha_check_sucsessful = function () {
				};
				(function ($) {
					var theme = "<?php echo sanitize_text_field($theme);?>";
					var cc_body = $('body');
					var checkout_button = $('button#place_order');
					cc_body.on('updated_checkout update_checkout checkout_error', add_captcha);
					cc_body.on('click', ".captchaRefresh", add_captcha);
					cc_body.on('payment_method_selected', function (evt) {
						if ($('input#payment_method_card_connect').is(':checked')) {
							cc_body.trigger('update_checkout');
							setTimeout(function () {
								checkout_button.prop('disabled', true);
							}, 750);
						} else {
							checkout_button.prop('disabled', false);
							$('#bc_captcha').remove();
						}
					});

					function add_captcha() {
						$('#bc_captcha').remove();
						$('div#payment .payment_box.payment_method_card_connect').append('<div id="bc_captcha" class="bc_recaptcha_wrapper"><div data-theme="' + theme + '" data-callback="recaptcha_check_sucsessful" data-expired-callback="refresh_captcha_callback" style="transform:scale(0.97); -webkit-transform:scale(0.97);transform-origin:0 0;-webkit-transform-origin:0 0;" class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide g-recaptcha" data-sitekey="<?php echo sanitize_text_field($site_key); ?>"></div></div>')
						$.getScript('https://www.google.com/recaptcha/api.js', function (data, textStatus, jqxhr) {
						});
					}

					refresh_captcha_callback = function refresh_captcha_callback() {
						checkout_button.prop('disabled', true);
						add_captcha();
						console.log('Captcha Expired and refreshed');
					}

					recaptcha_check_sucsessful = function recaptcha_check_sucsessful_callback(token) {
						checkout_button.prop('disabled', false);
					}
				})(jQuery);
			</script>
			<?php
		} ?>
</fieldset>
<p style="display:none;" class="saved_card_message"></p>

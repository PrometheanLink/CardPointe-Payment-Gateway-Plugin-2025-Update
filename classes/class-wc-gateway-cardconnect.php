<?php
	if (!defined('ABSPATH')) exit; // Exit if accessed directly

	/**
	 * Gateway class
	 */
	class CardConnectPaymentGateway extends WC_Payment_Gateway {

		public $cc_client = NULL;
		public $api_credentials;
		public $mode;
		public $profiles_enabled;
		public $saved_cards;
		public $front_end_id = "13";
		private $domain = 'cardconnect.com';
		private $rest_path = '/cardconnect/rest';
		private $cs_path = '/cardsecure/cs';
		private $itoke_path = '/itoke/ajax-tokenizer.html';
		// todo: remove or replace with 443 only
		private $cc_ports = array(
			'sandbox'    => '6443',
			'production' => '8443',
		);
		private $order_total;
		private $env_key;
		private $site;
		private $card_types = array();
		private $verification;
		private $registration_enabled;
		private $iframe_options;    // to be sent in every cardConnect API request as field "frontendid"
		private $show_advanced_override;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id = 'card_connect';
			$this->icon = apply_filters('woocommerce_CardConnectPaymentGateway_icon', '');
			$this->has_fields = true;
			$this->method_title = __('CardPointe', 'cardconnect-payment-gateway');
			$this->method_description = __('Payment gateway for CardPointe', 'cardconnect-payment-gateway');
			$this->show_advanced_override = true;
			$this->supports = array(
				'refunds',
				'products',
				'pre-orders',
				// https://docs.woothemes.com/document/pre-orders-payment-gateway-integration-guide/
				'subscriptions',
				'subscription_cancellation',
				'subscription_reactivation',
				'subscription_suspension',
				'subscription_amount_changes',
				'subscription_payment_method_change',
				// old (v1.5) name.  new 'subscription_payment_method_change_customer' is below.
				'subscription_date_changes',
				'multiple_subscriptions',
				// wcs2.0 - https://docs.woothemes.com/document/subscriptions/multiple-subscriptions/
				'subscription_payment_method_change_admin',
				// wcs2.0 - https://docs.woothemes.com/document/subscriptions/develop/payment-gateway-integration/change-payment-method-admin/
				'subscription_payment_method_change_customer',
				// wcs2.0 - http://docs.woothemes.com/document/subscriptions/develop/payment-gateway-integration/#section-5
			);
			// recaptcha required
			$this->update_option('recaptcha', 'yes');
			// Load user options
			$this->load_options();
			if ($this->get_option('show_advanced') == 'yes') {
				$this->show_advanced_override = true;
			}
			if ($this->get_option('iframe_style') == '') {
				$this->update_option('iframe_style', 'body {margin: 0;} input {width: 100%;min-height:20px;box-sizing:border-box;} .error {border: 1px solid red;}');
			}
			$use_iframe_default = $this->get_option('use_iframe');
			if ($use_iframe_default === '') {
				$this->update_option('use_iframe', 'yes');
			}
			$autoframe_default = $this->get_option('iframe_autostyle');
			if ($autoframe_default === '') {
				$this->update_option('iframe_autostyle', 'yes');
			}
			$format_cc_string = $this->get_option('iframe_formatinput');
			if ($format_cc_string === '') {
				$this->update_option('iframe_formatinput', 'yes');
			}

			// Load the form fields
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			// Actions
			add_action('wp_enqueue_scripts', array(
				$this,
				'register_scripts',
			));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options',
			));
			add_action('woocommerce_thankyou_CardConnectPaymentGateway', array(
				$this,
				'thankyou_page',
			));
			if ($this->profiles_enabled && !is_null($this->get_cc_client())) {
				$this->saved_cards = new CardConnectSavedCards($this->get_cc_client(), $this->api_credentials['mid']);
			}

			add_action('woocommerce_before_checkout_form', [$this, 'start_custom_sessions'], 999, 2);
			add_action('woocommerce_after_checkout_validation', [$this, 'bc_validate_timings'], 990, 2);
			add_action('woocommerce_after_checkout_validation', [$this, 'bc_validate_checkout_captcha'], 989, 2);
			add_action('woocommerce_payment_complete', [$this, 'reset_user_session']);

		}

		public function reset_user_session() {
			WC()->session->set('cardPointe-hammer', null);
		}

		function start_custom_sessions() {
			if (!is_admin()) {
				$sessionHammer = WC()->session->get('cardPointe-hammer');
				if (!$sessionHammer) {
					$this->set_hammerSession();
				}
				$session30minute = WC()->session->get('cardPointe-30minute');
				if (!$session30minute) {
					$this->set_30session();
				}
			}
		}

		private function set_hammerSession() {
			$this->set_userSessionTag();
			$now = current_time('timestamp');
			WC()->session->set('cardPointe-hammer', [
				'processed' => false,
				'attempts'  => 0,
				'time'      => $now
			]);
		}

		private function set_30session() {
			$this->set_userSessionTag();
			$now = current_time('timestamp');
			WC()->session->set('cardPointe-30minute', [
				'processed' => false,
				'attempts'  => 0,
				'time'      => $now,
				'expires'   => $now + (30 * MINUTE_IN_SECONDS)
			]);
		}

		private function set_userSessionTag() {
			$session_user_id = WC()->session->get_customer_id();
			WC()->session->set('current_user', $session_user_id);
		}


		public function bc_validate_timings($fields, $validation_errors) {
			// if other plugin throw an error, defer to them first
			if (isset($validation_errors->errors) && !empty($validation_errors->errors)) {
				return $validation_errors;
			}
			// insure this is Card Connect
			if ($_POST["payment_method"] !== 'card_connect') {
				return $validation_errors;
			}
			if (!is_admin()) {
				$sessionHammer = WC()->session->get('cardPointe-hammer');
				if (!$sessionHammer) {
					$validation_errors->add('validation', 'Please make sure you have Javascript and cookies turned on.');
				}
				$sessionHammer['attempts']++;
				$sessionHammer['processed'] = true;
				WC()->session->set('cardPointe-hammer', $sessionHammer);
				if ($sessionHammer['attempts'] >= 4) {
					$validation_errors->add('validation', 'Too many attempts for this session.');
				}
				$session30minute = WC()->session->get('cardPointe-30minute');
				if (!$session30minute) {
					$validation_errors->add('validation', 'Please make sure you have Javascript and cookies turned on.');
				}
				$session30minute['attempts']++;
				$session30minute['processed'] = true;
				WC()->session->set('cardPointe-30minute', $session30minute);
				$now = current_time('timestamp');
				$time_elapsed = ($now - $session30minute["time"]) / 60;
				if ($session30minute['attempts'] >= 10 && $time_elapsed <= 30) {
					$validation_errors->add('validation', 'Too many orders attempted in a 30 minute period');
				}
				// reset 30 minute after expired
				$expired = ($now - $session30minute["expires"]) / 60;
				if ($expired > 0) {
					$this->set_30session();
				}
				// minimum order amount
				$cart_total = WC()->cart->get_cart_contents_total();
				if ($cart_total < 5) {
					$validation_errors->add('validation', 'Minimum Checkout total is $5.00');
				}


			}
			return $validation_errors;

		}

		function bc_validate_checkout_captcha($fields, $validation_errors) {
			// if other plugin throw an error, defer to them first
			if (isset($validation_errors->errors) && !empty($validation_errors->errors)) {
				return $validation_errors;
			}
			// insure this is Card Connect
			if ($_POST["payment_method"] !== 'card_connect') {
				return $validation_errors;
			}

			// check if recaptcha required
			if ('yes' !== $this->settings["recaptcha"] && $this->sandbox !== 'yes') {
				$validation_errors->add('validation', 'ReCaptcha MUST be enabled.');
			}

			// check if sandbox, and allow no recaptcha validation
			if ($this->sandbox === 'yes') {
				return $validation_errors;
			}

			$captcha_errors = '';
			// check with Google to make sure tokens match
			if ((isset($_POST["g-recaptcha-response"]) && !empty($_POST["g-recaptcha-response"]))) {
				$response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
					'method'      => 'POST',
					'timeout'     => 45,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => array(),
					'body'        => array(
						'secret'   => $this->settings["recaptcha_secret_key"],
						'response' => $_POST["g-recaptcha-response"],
					)
				));
				$response_body = json_decode(wp_remote_retrieve_body($response), true);
				if (!is_wp_error($response_body) && $response_body['success'] === true) {
					return $validation_errors;
				} elseif ($response["response"]["code"] == 200 && $response_body["success"] === false && is_array($response_body["error-codes"]) && !empty($response_body["error-codes"])) {
					$captcha_errors = '<div style="font-size:10px;">ReCaptcha Response:<ul style="padding:2px;">';
					foreach ($response_body["error-codes"] as $e_index => $code) {
						$captcha_errors .= '<li style="padding:2px;">' . $code . '</li>';
					}
					$captcha_errors .= '</ul></div>';
				}
				$message = 'Google ReCaptcha Error.<br>The recaptcha field is required.<br>' . $captcha_errors;
				$validation_errors->add('g-recaptcha_error', __($message, 'woocommerce'));
			} else {
				$message = 'Google ReCaptcha Error.<br>The recaptcha field is required.<br>' . $captcha_errors;
				$validation_errors->add('g-recaptcha_error', __($message, 'woocommerce'));
			}

			return $validation_errors;

		}

		private function _get_ip_address() {
			if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
				//check ip from share internet
				$ip = $_SERVER['HTTP_CLIENT_IP'];
			} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				//to check ip is pass from proxy
				$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
			} else {
				$ip = $_SERVER['REMOTE_ADDR'];
			}
			if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
				return $ip;
			}
			return false;
		}

		/**
		 * Load user options into class
		 *
		 * @return void
		 */
		protected function load_options() {

			$this->enabled = $this->get_option('enabled');

			$this->registration_enabled = WC_Admin_Settings::get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes' ? true : false;
			$this->profiles_enabled = $this->registration_enabled && $this->get_option('enable_profiles') === 'yes';

			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->card_types = $this->get_option('card_types');
			$this->mode = $this->get_option('mode', 'capture');

			$this->sandbox = $this->get_option('sandbox');
			// removed the ports SEPT 2019
			$this->site = $this->sandbox !== 'yes' ? $this->get_option('site') : $this->get_option('site') . '-uat';

			$this->env_key = $this->sandbox == 'no' ? 'production' : 'sandbox';
			$port = $this->cc_ports[$this->env_key];

			$this->api_credentials = array(
				// removed the ports SEPT 2019 - just UAT for now
				'url'  => $this->sandbox !== 'yes' ? "https://{$this->site}.{$this->domain}.{$this->rest_path}" : "https://{$this->site}.{$this->domain}{$this->rest_path}",
				'mid'  => $this->get_option("{$this->env_key}_mid"),
				'user' => $this->get_option("{$this->env_key}_user"),
				'pass' => $this->get_option("{$this->env_key}_password"),
			);
			$this->iframe_options = array(
				'enabled'              => $this->get_option('use_iframe') ? $this->get_option('use_iframe') === 'yes' : true,
				'autostyle'            => $this->get_option('iframe_autostyle') ? $this->get_option('iframe_autostyle') === 'yes' : true,
				'formatinput'          => $this->get_option('iframe_formatinput') ? $this->get_option('iframe_formatinput') === 'yes' : true,
				'tokenizewheninactive' => $this->get_option('iframe_tokenizewheninactive') ? $this->get_option('iframe_tokenizewheninactive') === 'yes' : true,
				'inactivityto'         => $this->get_option('iframe_inactivityto') ? $this->get_option('iframe_inactivityto') : 500,
				'style'                => $this->get_option('iframe_style') !== '' ? $this->get_option('iframe_style') : 'body {margin: 0;} input {width: 100%;min-height:20px;box-sizing:border-box;} .error {border: 1px solid red;}',
			);

			$this->verification = array(
				'void_cvv' => $this->get_option('void_cvv'),
				'void_avs' => $this->get_option('void_avs'),
			);
		}

		/**
		 * Create form fields for the payment gateway
		 *
		 * @return void
		 */
		public function init_form_fields() {

			$profile_tooltip = array();
			$profile_tooltip['reg_enabled'] = __('Store payment information on /CardPointe\'s servers as a convenience to customers.', 'woocommerce');
			$profile_tooltip['reg_disabled'] = __('You must enable registration on checkout in order to offer this feature.', 'woocommerce');

			$this->form_fields = array(
				'enabled'                   => array(
					'title'       => __('Enable/Disable', 'woocommerce'),
					'label'       => __('Enable CardPointe Payments', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'                     => array(
					'title'       => __('Title', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This controls the title that the user sees during checkout.', 'woocommerce'),
					'default'     => __('Credit card', 'woocommerce'),
					'desc_tip'    => true,
				),
				'description'               => array(
					'title'       => __('Description', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This controls the description that the user sees during checkout.', 'woocommerce'),
					'default'     => 'Payment secured by CardPointe.',
					'desc_tip'    => true,
				),
				'mode'                      => array(
					'title'       => __('Payment Mode', 'woocommerce'),
					'label'       => __('Capture payment or only authorize it.', 'woocommerce'),
					'type'        => 'select',
					'description' => __('Select <strong>Authorize Only</strong> if you prefer to manually approve each transaction in the CardPointe dashboard.', 'woocommerce'),
					'default'     => 'capture',
					'options'     => array(
						'capture'   => __('Capture Payment', 'woocommerce'),
						'auth_only' => __('Authorize Only', 'woocommerce'),
					),
				),
				'sandbox'                   => array(
					'title'       => __('Sandbox', 'woocommerce'),
					'label'       => __('Enable Sandbox Mode', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('Place the payment gateway in sandbox mode using the sandbox authentication fields below (real payments will not be taken).', 'woocommerce'),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'sandbox_mid'               => array(
					'title'       => __('Sandbox Merchant ID (MID)', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This is the default information. You may use this or an alternative if provided by CardPointe.', 'woocommerce'),
					'default'     => '',
					'class'       => 'sandbox_input',
					'desc_tip'    => true,
				),
				'sandbox_user'              => array(
					'title'       => __('Sandbox Username', 'woocommerce'),
					'type'        => 'text',
					'description' => __('This is the default information. You may use this or an alternative if provided by CardPointe.', 'woocommerce'),
					'default'     => '',
					'class'       => 'sandbox_input',
					'desc_tip'    => true,
				),
				'sandbox_password'          => array(
					'title'       => __('Sandbox Password', 'woocommerce'),
					'type'        => 'password',
					'description' => __('This is the default information. You may use this or an alternative if provided by CardPointe.', 'woocommerce'),
					'default'     => '',
					'class'       => 'sandbox_input',
					'desc_tip'    => true,
				),
				'production_mid'            => array(
					'title'       => __('Live Merchant ID (MID)', 'woocommerce'),
					'type'        => 'text',
					'description' => __('Your unique MID from CardPointe.', 'woocommerce'),
					'default'     => '',
					'class'       => 'production_input',
					'desc_tip'    => true,
				),
				'production_user'           => array(
					'title'       => __('Live Username', 'woocommerce'),
					'type'        => 'text',
					'description' => __('Enter the credentials obtained from CardPointe', 'woocommerce'),
					'default'     => '',
					'class'       => 'production_input',
					'desc_tip'    => true,
				),
				'production_password'       => array(
					'title'       => __('Live Password', 'woocommerce'),
					'type'        => 'password',
					'description' => __('Enter the credentials obtained from CardPointe', 'woocommerce'),
					'default'     => '',
					'class'       => 'production_input',
					'desc_tip'    => true,
				),
				'site'                      => array(
					'title'       => __('Site', 'woocommerce'),
					'type'        => 'text',
					'description' => __('Enter the site provided to you upon opening your CardPointe merchant account. In most cases, this would be "fts"', 'woocommerce'),
				),
				'card_types'                => array(
					'title'       => __('Card Types', 'woocommerce'),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'default'     => array(
						'visa',
						'mastercard',
						'discover',
						'amex',
					),
					'description' => __('Select the card types to be allowed for transactions. <strong>This must match your Merchant Agreement.</strong>', 'woocommerce'),
					'desc_tip'    => false,
					'options'     => array(
						'visa'         => __('Visa', 'woocommerce'),
						'elo'          => __('ELO - Brazil', 'woocommerce'),
						'mastercard'   => __('Mastercard', 'woocommerce'),
						'discover'     => __('Discover', 'woocommerce'),
						'amex'         => __('American Express', 'woocommerce'),
						'visaelectron' => __('Visa Electron', 'woocommerce'),
					),
				),
				'enable_profiles'           => array(
					'title'       => __('Saved Cards', 'woocommerce'),
					'label'       => __('Allow customers to save payment information', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => $this->registration_enabled ? $profile_tooltip['reg_enabled'] : $profile_tooltip['reg_disabled'],
					'default'     => 'no',
					'desc_tip'    => true,
					'disabled'    => !$this->registration_enabled,
				),
				'woo_merchant_custom_field' => array(
					'title'       => __('Include these checkout fields in CardPointe transactions', 'woocommerce'),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __('Send addition WooCommerce checkout fields as a CardPointe Virtual Terminal transaction custom user fields. Billing Phone and Email are now included automatically, however, they are included in this list in case you have a non-standard WooCommerce checkout form. Currently IN BETA', 'woocommerce'),
					'desc_tip'    => true,
					'options'     => $this->get_custom_fields_mapping_options(true),
				),
				'void_avs'                  => array(
					'title'       => __('Void on AVS failure', 'woocommerce'),
					'label'       => __('Active', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('Void order if <strong>Address and ZIP</strong> do not match.', 'woocommerce'),
					'default'     => 'yes',
				),
				'void_cvv'                  => array(
					'title'       => __('Void on CVV failure', 'woocommerce'),
					'label'       => __('Active', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('Void order if <strong>CVV2/CVC2/CID</strong> does not match.', 'woocommerce'),
					'default'     => 'yes',
				),
				'recaptcha'                 => array(
					'title'       => __('Google ReCaptcha', 'woocommerce'),
					'label'       => __('Enable Google ReCaptcha V2 on Checkout', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('Adds Google ReCaptcha v2 to the bottom of the checkout payment form. While in sandbox mode, ReCaptcha will not be validated.', 'woocommerce'),
					'default'     => 'yes',
					'desc_tip'    => true,
					'class'       => 'bc_recap_start',
				),
				'recaptcha_theme'           => array(
					'title'       => __('ReCaptcha Enable dark Theme', 'woocommerce'),
					'label'       => __('Enable Dark mode.', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('If selected, the ReCaptcha V2 display will be in dark mode.', 'woocommerce'),
					'default'     => 'no',
					'desc_tip'    => true,
				),
				'recaptcha_site_key'        => array(
					'title'       => __('Site Key', 'woocommerce'),
					'type'        => 'password',
					'description' => __('Enter your ReCaptcha V2 Site Key', 'woocommerce'),
					'default'     => '',
					'class'       => 'recaptcha_key',
					'desc_tip'    => true,
				),
				'recaptcha_secret_key'      => array(
					'title'       => __('Secret Key', 'woocommerce'),
					'type'        => 'password',
					'description' => __('Enter your ReCaptcha V2 Secret Key', 'woocommerce'),
					'default'     => '',
					'class'       => 'recaptcha_key bc_recap_end',
					'desc_tip'    => true,
				),
				'cc_attempts'               => array(
					'title'             => __('Maximum credit card attempts', 'woocommerce'),
					'type'              => 'number',
					'description'       => __('To prevent fraudulent credit card attacks, you must limit the number of times a client attempts to use a specific card, per order. Maximum of 5 will be enforced. Min: 3. You can unblock a tokenized card below.', 'woocommerce'),
					'default'           => '3',
					'custom_attributes' => array(
						'min'  => 3,
						'max'  => 5,
						'step' => 1,
					),
					'class'             => 'cc_max_attempts bc_security_section',
					'desc_tip'          => true,
				),
				'order_attempts'            => array(
					'title'             => __('Maximum order attempts.', 'woocommerce'),
					'type'              => 'number',
					'description'       => __('To prevent fraudulent credit card attempts, you must limit the number of times a client attempts to pay for a specific order. Maximum of 10 will be enforced. Min: 3', 'woocommerce'),
					'default'           => 3,
					'custom_attributes' => array(
						'min'  => 3,
						'max'  => 10,
						'step' => 1,
					),
					'class'             => 'cc_order_max_attempts bc_security_section',
					'desc_tip'          => true,
				),
				'cc_rate_limit'             => array(
					'title'             => __('Rate limiting', 'woocommerce'),
					'type'              => 'number',
					'description'       => __('Number of seconds between a specific order submission attempts. Minimum is 3 seconds', 'woocommerce'),
					'default'           => '5',
					'custom_attributes' => array(
						'min'  => 3,
						'step' => 1,
					),
					//					'class'             => 'cc_rate_limit bc_security_section production_input', // example
					'class'             => 'cc_rate_limit bc_security_section',
					'desc_tip'          => true,
				),
				'cc_banned'                 => array(
					'title'             => __('Currently Banned Card Tokens', 'woocommerce'),
					'type'              => 'multiselect',
					'description'       => __('Cards that have hit the above limit. One per line.', 'woocommerce'),
					'default'           => array(),
					'custom_attributes' => array(
						'rows' => 8,
						'cols' => 24,
					),
					'placeholder'       => __('No currently banned cards', 'woocommerce'),
					'class'             => 'cc_banned bc_security_section',
					'desc_tip'          => true,
				),
				'show_advanced'             => array(
					'title'       => __('Advanced Settings', 'woocommerce'),
					//					'label'       => __('Show', 'woocommerce'),
					'type'        => 'checkbox',
					'description' => __('If you are having trouble seeing the IFRAME card number field on the checkout page, try the advanced settings.', 'woocommerce'),
					'default'     => 'yes',
					'class'       => 'hide_me',
				),
			);
			$this->iframe_options["enabled"] = true;
			if ((defined('WC_CC_ADVANCED') && WC_CC_ADVANCED) || $this->show_advanced_override == true || $this->show_advanced_override == 'yes') {
				$this->form_fields += array(
					'iframe_heading'              => array(
						'title' => __('Advanced Tokenization Settings', 'woocommerce'),
						'type'  => 'title',
					),
					'use_iframe'                  => array(
						'title'       => __('Enable IFRAME API', 'woocommerce'),
						'label'       => __('Active', 'woocommerce'),
						'type'        => 'checkbox',
						'description' => __('Use the CardPointe Hosted iFrame Tokenizer for retrieving customer credit card number tokens. If disabled, fallback to tokenizing directly with the CardSecure API. For added security, we strongly recommend using the iFrame Tokenizer.', 'woocommerce'),
						'default'     => 'yes',
					),
					'iframe_style_heading'        => array(
						'class' => 'iframe-config',
						'title' => __('Advanced IFRAME Style Settings', 'woocommerce'),
						'type'  => 'title',
					),
					'iframe_autostyle'            => array(
						'class'       => 'iframe-config',
						'title'       => __(' Autostyle', 'woocommerce'),
						'label'       => __('Enable', 'woocommerce'),
						'type'        => 'checkbox',
						'description' => __('Attempt to automatically style credit card input to match other fields.', 'woocommerce'),
						'default'     => 'yes',
					),
					'iframe_style'                => array(
						'class'       => 'iframe-config',
						'title'       => __('Custom Style', 'woocommerce'),
						'label'       => __('Enable', 'woocommerce'),
						'type'        => 'textarea',
						'description' => __('Delete and save to reset the plugin’s default CSS”', 'woocommerce'),
						'default'     => 'body {margin: 0;} input {width: 100%;min-height:20px;box-sizing:border-box;} .error {border: 1px solid red;}',
					),
					'iframe_formatinput'          => array(
						'class'       => 'iframe-config',
						'title'       => __('Format CC string', 'woocommerce'),
						'label'       => __('Enable', 'woocommerce'),
						'type'        => 'checkbox',
						'description' => __('Add spaces to credit card input to make it more readable.', 'woocommerce'),
						'default'     => 'yes',
					),
					'iframe_tokenizewheninactive' => array(
						'class'       => 'iframe-config bc_security_section',
						'title'       => __('Process when inactive', 'woocommerce'),
						'label'       => __('Enable', 'woocommerce'),
						'type'        => 'checkbox',
						'description' => __('If issues are reported making payments on mobile, this option may improve user experience.', 'woocommerce'),
						'default'     => 'yes',
					),
					'iframe_inactivityto'         => array(
						'class'       => 'iframe-config bc_security_section',
						'title'       => __('Timeout', 'woocommerce'),
						'label'       => __('Enable', 'woocommerce'),
						'type'        => 'number',
						'placeholder' => 500,
						'description' => __('Controls how long the page will wait after an input event before considering input complete.<br><em><span style="color:#e86825;">Only available and used when "Process When Inactive" is enabled. Default value is 500 and value is in milliseconds. 500 milliseconds = 1/2 second.</span></em>', 'woocommerce'),
						'default'     => 500,
					),
				);
			}
		}


		/**
		 * Load SDK for communicating with CardConnect servers
		 *
		 * @return void
		 */
		protected function get_cc_client() {
			if (is_null($this->cc_client) && !empty($this->api_credentials['url']) && !empty($this->api_credentials['user']) && !empty($this->api_credentials['pass']) && !is_null($this->api_credentials['url']) && !is_null($this->api_credentials['user']) && !is_null($this->api_credentials['pass'])) {
				require_once 'CardConnectRestClient.php';
				$this->cc_client = new CardConnectRestClient($this->api_credentials['url'], $this->api_credentials['user'], $this->api_credentials['pass']);
			}

			return $this->cc_client;
		}

		/**
		 * Admin Panel Options
		 * Include CardConnect logo and add some JS for revealing inputs for sandbox vs production
		 *
		 * @access public
		 * @return void
		 */
		public function admin_options() {
			?>

			<img style="margin:10px 0 0 -15px" width="218" height=""
				 src="<?php echo esc_url(plugins_url('assets/CardPointe-Logo.png', dirname(__FILE__))); ?>"/>

			<?php if (empty($this->api_credentials['mid'])): ?>
				<div class="card-connect-banner updated">
					<p class="main">
					<h3 style="margin:0;"><?php _e('Getting started', 'woocommerce'); ?></h3></p>
					<p><?php _e('CardPointe is a leading provider of payment processing and technology services that helps more than 50,000 merchants across the U.S. accept billions of dollars in card transactions each year. This Extension from CardPointe helps you accept simple, integrated and secure payments on your Woo Commerce store. Please call 877-828-0720 today to get a Merchant Account!', 'woocommerce'); ?></p>
					<p><a href="http://www.cardconnect.com/" target="_blank"
						  class="button button-primary"><?php _e('Visit CardPointe', 'woocommerce'); ?></a></p>
				</div>
			<?php endif; ?>

			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
				<script type="text/javascript">
					var gCaptchaWrapElem = '#woocommerce_card_connect_recaptcha';
					var topBorderElem = jQuery(gCaptchaWrapElem).closest('tr').css({
						'border-top': '1px solid red',
						'background-color': '#ff000005',

					});
					var bottomBorderElem = jQuery('.bc_recap_end').closest('tr').next('tr').css('border-top', '1px solid red');
					var inBetween = jQuery(gCaptchaWrapElem).closest('tr').nextUntil(jQuery('.recaptcha_key').closest('tr').next('tr').next('tr')).css({
						'background-color': '#ff000005',
						'padding': '10px'
					});
					var security_sections = jQuery('.bc_security_section').closest('tr').css({
						'background-color': '#ff000005',

					})
					var captchaConfigStart = jQuery(gCaptchaWrapElem).closest('tr').nextAll('tr').slice(0, 3);
					jQuery('#woocommerce_card_connect_recaptcha').on('change', function () {
						if (jQuery(this).is(':checked')) {
							captchaConfigStart.show();
						} else {
							captchaConfigStart.hide();
						}
						console.log(captchaConfigStart);
					}).change();

					jQuery('#woocommerce_card_connect_sandbox').on('change', function () {
						var sandbox = jQuery('.sandbox_input').closest('tr');
						var production = jQuery('.production_input').closest('tr');
						if (jQuery(this).is(':checked')) {
							sandbox.show();
							production.hide();
						} else {
							sandbox.hide();
							production.show();
						}
					}).change();
					jQuery('#woocommerce_card_connect_use_iframe').on('change', function () {
						var iframeConfig = jQuery('.iframe-config').closest('tr');
						if (jQuery(this).is(':checked')) {
							jQuery('h3#woocommerce_card_connect_iframe_style_heading').show();
							iframeConfig.show();
						} else {
							jQuery('h3#woocommerce_card_connect_iframe_style_heading').hide();
							iframeConfig.hide();
						}
					}).change();
					jQuery('h3#woocommerce_card_connect_iframe_heading').css({
						'border-top': '1px solid black',
						'padding-top': '10px',
					});
					jQuery(function ($) {
						$('#mainform').submit(function () {
							if (!$('#woocommerce_card_connect_sandbox').is(':checked')) {
								var allowSubmit = true;
								$('.production_input').each(function () {
									if ($(this).val() === '') allowSubmit = false;
								});
								if (!allowSubmit) alert(
									'Warning! In order to enter Live Mode you must enter values for MID, Username, Password, ReCaptcha, Maximum Credit Card attempts, Maximum order attempts, and Rate limiting fields.');
								return allowSubmit;
							}
						});
						<?php
						// Map Custom Field display
						// get options and see if there is saved data for the MID, Username, Pass, and Site
						$options_prefix = $this->settings["sandbox"] == 'yes' ? 'sandbox_' : 'production_';
						$check_options = array(!$this->settings[$options_prefix . "mid"], !$this->settings[$options_prefix . "password"], !$this->settings[$options_prefix . "user"], !$this->settings["site"]);
						if (in_array(true, $check_options)) {
						// hide option?>
						var custom_field_woo_tr = $('select#woocommerce_card_connect_woo_merchant_custom_field').closest('tr');
						custom_field_woo_tr.remove();
						<?php
						}?>


					});
					// hide legacy settings
					jQuery('.hide_me').closest('tr').css({
						'display': 'none'
					});
				</script>
			</table>
			<?php
			echo wp_kses($this->admin_settings_css(), [
				'style' => []
			]);
		}

		private function admin_settings_css() {
			ob_start();
			?>
			<style>
				.hide_me {
					display: none !important;
				}

				td.custom_button a {
					text-decoration: none;
					padding: 10px;
					border: 1px solid #2271b1;
					background-color: #2271b1;
					color: white;
					border-radius: 4px;
				}

				td.custom_button a:hover {
					background-color: #0f4b7b;
				}
			</style>
			<?php return ob_get_clean();
		}

		/**
		 *
		 * override of same function in /plugins/woocommerce/includes/abstracts/abstract-wc-settings-api.php
		 *
		 * note the custom handling for older version of WC v2.5.5 !
		 *
		 * some cardconnect-specific checks are performed and we then append any warning msgs to the bottom of
		 * the form_fields so that the msgs are easily visible and right near the 'save' button.
		 *
		 */
		public function generate_settings_html($form_fields = array(), $echo = true) {


			if (empty($form_fields)) {
				$form_fields = $this->get_form_fields();
			}

			// custom handling to maintain backwards compatibility with older version of WC (v2.5.x)
			$wcPluginData = WC()->version;
			if (stripos($wcPluginData, '2.5') !== false) {

				// WC 2.5.5

				$html = '';
				foreach ($form_fields as $k => $v) {

					if (!isset($v['type']) || ($v['type'] == '')) {
						$v['type'] = 'text'; // Default to "text" field type.
					}

					if (method_exists($this, 'generate_' . $v['type'] . '_html')) {
						$html .= $this->{'generate_' . $v['type'] . '_html'}($k, $v);
					} else {
						$html .= $this->{'generate_text_html'}($k, $v);
					}
				}

			} else {

				// WC v2.6+

				$html = '';
				foreach ($form_fields as $k => $v) {
					$type = $this->get_field_type($v);

					if (method_exists($this, 'generate_' . $type . '_html')) {
						$html .= $this->{'generate_' . $type . '_html'}($k, $v);
					} else {
						$html .= $this->generate_text_html($k, $v);
					}
				}
			}


			// cardconnect-specific checks
			$warning_msgs = '';

			if ($this->site != '') {
				// ensure that the sandbox and production ports are open on the server
				foreach ($this->cc_ports as $env => $port) {
					// todo: override until further notice:
					$port = 443;
					$fsockURL = 'ssl://' . $this->site . '.' . $this->domain;
					$fp = fsockopen($fsockURL, $port, $errno, $errstr, 5);
					if (!$fp) {
						// port is closed or blocked
						$warning_msgs .= "Port $port is closed.<br>";
						$warning_msgs .= "You will not be able to process transactions using the <i>$env</i> CardPointe environment.<br>";
						$warning_msgs .= "First ensure that the 'Site' field is set and saved correctly above.<br>";
						$warning_msgs .= "Then please request that your server admin or hosting provider opens port $port.<br><br>";
					} else {
						// port is open and available
						fclose($fp);
					}
				}
			} else {
				$warning_msgs = "Ensure that you fill-in the 'Site' field (and then click 'Save changes') so that we can check your connection to the CardPointe servers.";
			}
			// check if known Woo Recaptcha plugin is installed
			if (in_array('recaptcha-for-woocommerce/recaptcha-for-woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
				$third_party_recpatcha_option = get_option('woo_recaptcha_checkout');
				if ($third_party_recpatcha_option === 'yes') {
					$warning_msgs .= ' We\'ve detected that you are using the "reCAPTCHA for WooCommerce" plugin on your site and it\'s checkout option is selected. If you have that plugin setup to add ReCaptcha to the checkout page, it is recommended you disable that settings.<br>';
				}
			}
			// check if recaptcha is active (not in sandbox) and warn
			$public = $this->get_option('recaptcha_site_key');
			$priv = $this->get_option('recaptcha_secret_key');
			$sandbox = $this->get_option('sandbox');

			if ((empty($public) || empty($priv)) && $sandbox !== 'yes') {
				$warning_msgs .= '<br><br><em>**ReCaptcha is required as of June 14, 2021.**</em> ';

			}

			$ip_message = '';
			// append any cardconnect-specific messages to the bottom of the form_fields.
			if ($warning_msgs == '') {
				$warning_msgs = 'no warnings/messages to report  :)';
				$html .= '<tr><th scope="row" class="titledesc">Warnings/Messages</th>';
				$html .= '<td class="forminp">' . $warning_msgs . $ip_message . '</td></tr>';
			} else {
				$html .= '<tr><th scope="row" class="titledesc" style="color: red;">Warnings/Messages</th>';
				$html .= '<td class="forminp" style="color: red; font-weight: bold;">' . $warning_msgs . $ip_message . '</td></tr>';

			}

			if ($echo) {
				$allowed = $this->allowed_admin_panel_html();
				echo wp_kses($html, $allowed);
			} else {
				return $html;
			}


			return $html;
		}


		public function generate_multiselect_html($key, $data) {
			$field_key = $this->get_field_key($key);
			// test area for multiselect, also insure there are no empties
			$banned_cards = get_option('card_connect_banned_cards');
			if ($banned_cards) {
				foreach ($banned_cards as $index => $val) {
					if ($index != $val || empty(trim($val))) {
						unset($banned_cards[$index]);
						update_option('card_connect_banned_cards', $banned_cards);
					}
				}
			}
			$use_field_key = false;
			if ('woocommerce_card_connect_cc_banned' == $field_key) {
				$use_field_key = true;
				$custom_options = get_option('card_connect_banned_cards');
				$defaults = array(
					'title'             => '',
					'disabled'          => false,
					'class'             => '',
					'css'               => '',
					'placeholder'       => '',
					'type'              => 'text',
					'desc_tip'          => false,
					'description'       => '',
					'custom_attributes' => array(),
					'select_buttons'    => false,
					'options'           => $custom_options,
				);
				$data['options'] = $custom_options;
			} else {
				return parent::generate_multiselect_html($key, $data);
			}
			$data = wp_parse_args($data, $defaults);
			$value = []; // don't need selected
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label
						for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?><?php echo $this->get_tooltip_html($data); // WPCS: XSS ok. ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span>
						</legend>
						<select multiple="multiple" class="multiselect <?php echo esc_attr($data['class']); ?>"
								name="<?php echo esc_attr($field_key); ?>[]" id="<?php echo esc_attr($field_key); ?>"
								style="<?php echo esc_attr($data['css']); ?>" <?php disabled($data['disabled'], true); ?> <?php echo $this->get_custom_attribute_html($data); // WPCS: XSS ok. ?>>
							<?php foreach ((array)$data['options'] as $option_key => $option_value) : ?>
								<?php if (is_array($option_value)) : ?>
									<optgroup label="<?php echo esc_attr($option_key); ?>">
										<?php foreach ($option_value as $option_key_inner => $option_value_inner) : ?>
											<option
												value="<?php echo esc_attr($option_key_inner); ?>" <?php selected(in_array((string)$option_key_inner, $value, true), true); ?>><?php echo esc_html($option_value_inner); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php else : ?>
									<option
										value="<?php echo esc_attr($option_key); ?>" <?php selected(in_array((string)$option_key, $value, true), true); ?>><?php echo esc_html($option_value); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<?php echo $this->get_description_html($data); // WPCS: XSS ok. ?>
						<?php if ($data['select_buttons']) : ?>
							<br/><a class="select_all button"
									href="#"><?php esc_html_e('Select all', 'woocommerce'); ?></a> <a
								class="select_none button"
								href="#"><?php esc_html_e('Select none', 'woocommerce'); ?></a>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
			<tr valign="top" style="">
				<td class="forminp custom_button">
					<a style="font-size:10px;" href="#">Delete Selected Tokenized Card(s)</a>
				</td>
			</tr>


			<?php

			return ob_get_clean();
		}

		/**
		 * @param bool $woo
		 * @return array
		 */
		public function get_custom_fields_mapping_options($woo = true) {
			$mapping_display = array();
			// $woo future use
			if ($woo) {
				$wc_countries = $this->countries = new WC_Countries();
				$billlingfields = $wc_countries->get_address_fields($this->countries->get_base_country(), 'billing_');
				$shippingfields = $wc_countries->get_address_fields($this->countries->get_base_country(), 'shipping_');
				$otherfields = !empty(get_option('wc_fields_additional')) ? get_option('wc_fields_additional') : array();
				$comments = array('order_comments' => array());


				$allfields = array_merge($billlingfields, $shippingfields, $otherfields, $comments);
				foreach ($allfields as $field_key => $field_data) {
					// remove password fields
					if (strpos($field_key, 'password') !== false) {
						continue;
					}
					$mapping_display[$field_key] = $field_key;
				}
			}
			return apply_filters('cc_custom_field_mapping', $mapping_display, $woo);
		}

		public function get_user_defined_form_fields($order, $checkoutFormData, $is_sub_or_pre = false) {
			$order = wc_get_order($order);
			$order_id = $order->get_id();
			$order_meta = get_post_meta($order_id);
			$user_fields = array();
			$posted_data = $checkoutFormData;
			if (isset($this->settings["woo_merchant_custom_field"]) && !empty($this->settings["woo_merchant_custom_field"])) {
				foreach ($this->settings["woo_merchant_custom_field"] as $index => $form_field) {
					if (!empty($posted_data[$form_field])) {
						if (!$is_sub_or_pre) {
							$user_fields[$form_field] = $posted_data[$form_field];
						} else {
							$user_fields[$form_field] = get_post_meta($order_id, $form_field, true);
							if (!($user_fields[$form_field])) {
								// try hidden fields
								$user_fields[$form_field] = get_post_meta($order_id, '_' . $form_field, true);
							}
						}
					}
				}
			}
			return apply_filters('cc_user_defined_checkout_fields', $user_fields, $order, $checkoutFormData);
		}

		/**
		 * Process the order payment status
		 *
		 * @param int $order_id
		 *
		 * @return array
		 */
		public function process_payment($order_id) {

			global $woocommerce;

			// -----------------------------------------------------------------------
			// get details about the Order submitted at Checkout
			// -----------------------------------------------------------------------
			$order = wc_get_order($order_id);

			$user_id = $order->get_user_id();

			$checkoutFormData = $this->get_checkout_form_data($order, $user_id);


			if (!$checkoutFormData['token'] && !$checkoutFormData['saved_card_id']) {
				$this->handleCheckoutFormDataError(true);
			}


			// -----------------------------------------------------------------------
			// create the cardconnect API request
			// -----------------------------------------------------------------------

			// this will hold all of the params sent in the cardconnect API request
			WC()->session->get('');
			$request = array(
				'merchid'     => $this->api_credentials['mid'],
				'cvv2'        => $checkoutFormData['cvv2'],
				'amount'      => $this->get_order_total_formatted($order),
				'currency'    => $this->getCardConnectCurrencyCode($order->get_currency()),
				'orderid'     => $order->get_order_number(),
				'name'        => $checkoutFormData['card_name'] ? $checkoutFormData['card_name'] : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
				'email'       => $checkoutFormData["submitted"]["billing_email"] ? $checkoutFormData["submitted"]["billing_email"] : $order->get_billing_email(),
				'phone'       => preg_replace('/[^0-9]/', '', $checkoutFormData["submitted"]["billing_phone"] ? $checkoutFormData["submitted"]["billing_phone"] : $order->get_billing_phone()),
				'address'     => $order->get_billing_address_1(),
				'city'        => $order->get_billing_city(),
				'region'      => $order->get_billing_state(),
				'country'     => $order->get_billing_country(),
				'postal'      => $order->get_billing_postcode(),
				'capture'     => $this->mode === 'capture' ? 'Y' : 'N',
				'frontendid'  => $this->front_end_id,
				'environment' => sanitize_title(site_url() . '-' . $this->_get_ip_address()) . '-' . WC()->session->get_customer_id(),
			);
			$user_fields = $this->get_user_defined_form_fields($order, $checkoutFormData['submitted']);
			if (!empty($user_fields)) {
				$request['userfields'] = $user_fields;
			}


			// 5 cases to handle:
			if ($checkoutFormData['saved_card_id']) {
				// case 1:  user is paying by using a "saved card"
				//			user must already be logged in to WP for this to be possible

				// use 'profile' param, no 'token' or 'account number' to pass
				$request['profile'] = $checkoutFormData['profile_id'] . '/' . $checkoutFormData['saved_card_id'];
				$request['cof'] = 'C';
				$request['cofscheduled'] = 'N';

			} //		elseif ( $profile_id ) {
			elseif ($checkoutFormData['profile_id'] && $checkoutFormData['store_new_card']) {
				// case 2:  user is logged-in to WP and already has a profileid in USER meta
				//          !!! FYI: this logic differs slightly from that for *Subscriptions* !!!
				//

				// we first need to ...
				// Get the new card's 'acctid'
				$profile_request = array(
					'merchid'    => $this->api_credentials['mid'],
					'profile'    => $checkoutFormData['profile_id'],
					// 20 digit profile_id to utilize a profile
					'account'    => $checkoutFormData['token'],
					'cvv2'       => $checkoutFormData['cvv2'],
					'expiry'     => $checkoutFormData['expiry'],
					'name'       => $checkoutFormData['card_name'] ? $checkoutFormData['card_name'] : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
					'address'    => $order->get_billing_address_1(),
					'city'       => $order->get_billing_city(),
					'region'     => $order->get_billing_state(),
					'country'    => $order->get_billing_country(),
					'postal'     => $order->get_billing_postcode(),
					'frontendid' => $this->front_end_id,
				);


				$new_account_id = $this->saved_cards->get_new_acctid($profile_request);

				$request['profile'] = $checkoutFormData['profile_id'] . '/' . $new_account_id;    // 20 digit profile_id/acctid to utilize a profile
				$request['cof'] = 'C';
				$request['cofscheduled'] = 'N';

			} elseif (!$checkoutFormData['profile_id'] && $checkoutFormData['store_new_card']) {
				// case 3:  user is paying with a new card and wants to "save this card"
				//			user is either NOT logged-in to WP or does NOT already have a profileid in USER meta


				// we first need to...
				// get the user's profileid
				$request['expiry'] = $checkoutFormData['expiry'];
				$request['profile'] = 'Y';        // 'Y' will create a new account profile
				$request['account'] = $checkoutFormData['token'];    // since we're using 'profile', 'account number' must be converted to a token
				$request['cof'] = 'C';
				$request['cofscheduled'] = 'N';
				// In the $payment_response handling below, we'll need to associate this new card with
				// the profileid that gets returned in $payment_response.

			} elseif ($checkoutFormData['profile_id'] && !$checkoutFormData['store_new_card']) {
				// case 4: user is logged in
				//		   user is manually entering a credit card number
				//         example scenario: customer is changing payment method to a newer credit card number
				//
				//			!!! this case is specific to "single product" purchases only (not Subscriptions) !!!

				// Get the new card's 'acctid'
				$profile_request = array(
					'merchid'    => $this->api_credentials['mid'],
					'profile'    => $checkoutFormData['profile_id'],
					// 20 digit profile_id to utilize a profile
					'account'    => $checkoutFormData['token'],
					'cvv2'       => $checkoutFormData['cvv2'],
					'expiry'     => $checkoutFormData['expiry'],
					'name'       => $checkoutFormData['card_name'] ? $checkoutFormData['card_name'] : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
					'address'    => $order->get_billing_address_1(),
					'city'       => $order->get_billing_city(),
					'region'     => $order->get_billing_state(),
					'country'    => $order->get_billing_country(),
					'postal'     => $order->get_billing_postcode(),
					'frontendid' => $this->front_end_id,
				);


				$new_account_id = $this->saved_cards->get_new_acctid($profile_request);


				$request['profile'] = $checkoutFormData['profile_id'] . '/' . $new_account_id;    // 20 digit profile_id/acctid to utilize a profile

				$request['cof'] = 'C';
				$request['cofscheduled'] = 'N';

			} else {
				// case 5:  user is simply paying with their card
				//			and
				//			user has NOT selected "save this card"
				//			and
				//			user had NOT selected "use a saved card"


				$request['expiry'] = $checkoutFormData['expiry'];
				$request['profile'] = 'Y';        // 'Y' will create a new account profile
				$request['account'] = $checkoutFormData['token'];    // since we're using 'profile', 'account number' must be converted to a token
				$request['cof'] = 'C';
				$request['cofscheduled'] = 'N';

			}


			// -----------------------------------------------------------------------
			// perform the cardconnect API request to actually make the payment
			// -----------------------------------------------------------------------


			if (!is_null($this->get_cc_client())) {
				// rate limit


				try {
					$payment_response = $this->get_cc_client()->authorizeTransaction($request);
				} catch (Exception $exception) {

				}


			} else {

				return $this->handleNoCardConnectConnection($order, true);


			}


			// -----------------------------------------------------------------------
			// handle the response from the cardconnect API request
			// -----------------------------------------------------------------------
			if (!is_array($payment_response)) {
				switch ($payment_response) {
					case('hammered'):
						return $this->handleAuthorizationResponse_custom($order, true, 'hammered');
						break;
					case('banned'):
						return $this->handleAuthorizationResponse_custom($order, true, 'banned');
						break;
					case('order_ban'):
						return $this->handleAuthorizationResponse_custom($order, true, 'order_banned');
						break;
					default:

				}
			}
			if ((!$payment_response) || ('' === $payment_response)) {
				// got no response back from the CardConnect API endpoint request.
				// likely that the hosting server is unable to initiate/complete the CURL request to the API.

				$this->handleAuthorizationResponse_NoResponse($order, true);
			} elseif (
				($payment_response['respstat']) && ('A' === $payment_response['respstat'])) {
				// 'A' response is for 'Approved'

				$order_verification = $this->verify_customer_data($payment_response);
				if (!$order_verification['is_valid']) {
					// failed either the AVS or CVV checks, therefore void this transaction.

					return $this->handleVerificationError($order, $order_verification, $payment_response['retref'], true);

				}


				// -----------------------------------------------------------------------
				// updating META data
				// -----------------------------------------------------------------------

				update_post_meta($order->get_id(), '_wc_cardconnect_last4', substr(trim($payment_response['account']), -4));
				update_post_meta($order->get_id(), '_wc_cardconnect_profileid', $payment_response['profileid']);
				update_post_meta($order->get_id(), '_wc_cardconnect_acctid', $payment_response['acctid']);


				// payment_complete() will save _transaction_id to the ORDER META
				$order->payment_complete($payment_response['retref']);

				// -----------------------------------------------------------------------


				// Visible via wp-admin > Order
				$order->add_order_note(sprintf(__('CardPointe payment processed (ID: %s, Authcode: %s, Amount: %s)', 'woocommerce'), $payment_response['retref'], $payment_response['authcode'], get_woocommerce_currency_symbol() . ' ' . $payment_response['amount']));


				// Reduce stock levels
				// This is handled within payment_complete() above !
				//			$order->reduce_order_stock();


				// Clear the cart
				$woocommerce->cart->empty_cart();


				// 5 cases to handle:
				if ($checkoutFormData['saved_card_id']) {
					// case 1:  user paid by using a "saved card"

					// $payment_response['profileid'] is already saved in USER meta
					// $payment_response['acctid'] (aka 'saved card') is already saved in ORDER meta in the above code
				} //			elseif ( $profile_id ) {
				elseif ($checkoutFormData['profile_id'] && $checkoutFormData['store_new_card']) {
					// case 2:  user is logged-in to WP and already has a profileid in USER meta
					//          !!! FYI: this logic differs slightly from that for *Subscriptions* !!!
					//

					// $payment_response['acctid'] (aka 'saved card') was already saved in ORDER meta in the above code

					// save the saved card's 'acctid' to the USER meta
					$this->saved_cards->save_user_card($user_id, array($payment_response['acctid'] => $checkoutFormData['card_alias']));

				} elseif (!$checkoutFormData['profile_id'] && $checkoutFormData['store_new_card']) {
					// case 3:  user is paying with a new card and wants to "save this card"
					//			user is either NOT logged-in to WP or does NOT already have a profileid in USER meta

					// save the 'profile_id' to the USER meta
					$this->saved_cards->set_user_profile_id($user_id, $payment_response['profileid']);

					// save the saved card's 'acctid' to the USER meta
					$this->saved_cards->save_user_card($user_id, array($payment_response['acctid'] => $checkoutFormData['card_alias']));

				} elseif ($checkoutFormData['profile_id'] && !$checkoutFormData['store_new_card']) {
					// case 4: user is logged in
					//		   user is manually entering a credit card number
					//         example scenario: customer is changing payment method to a newer credit card number
					//
					//			!!! this case is specific to "single product" purchases only (not Subscriptions) !!!

					//			nothing to save/do
				} else {
					// case 5:  user is simply paying with their card
					//			and
					//			user had NOT selected "save this card"
					//			and
					//			user had NOT selected "use a saved card"

					// save the 'profile_id' to the USER meta
					if (isset($this->saved_cards)) {
						// if the WP-admin cardconnect setting for 'Saved Cards - allow customers to save payment info' is
						// CHECKED, then we'll have a saved_cards object otherwise we will not.

						$this->saved_cards->set_user_profile_id($user_id, $payment_response['profileid']);
					}
				}

				// tag the version number for possible troubleshooting:
				update_post_meta($order_id, '_cardconnect_order_version', WC_CARDCONNECT_VER);

				// Return thankyou redirect
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url($order),
				);

			} elseif
			(($payment_response['respstat']) && ('C' === $payment_response['respstat'])) {
				// 'C' response is for 'Declined'
				return $this->handleAuthorizationResponse_Declined($order, $payment_response, true);
			} else {
				// 'B' response is for 'Retry'
				return $this->handleAuthorizationResponse_Retry($order, $payment_response, true);
			}


			// catch-all error
			return $this->handleAuthorizationResponse_DefaultError($order, true);

		}


		public function get_checkout_form_data($order, $user_id) {
			$checkoutFormData = array();

			// gets this user's CardConnect profileid from the USER META
			$checkoutFormData['profile_id'] = $this->get_profile_id($user_id);

			// tokenized version of the user's credit card #
			$checkoutFormData['token'] = $this->get_token();

			// correlates to the 'save this card' checkbox on the checkout form
			$checkoutFormData['store_new_card'] = $this->get_store_new_card();

			// correlates to the 'card nickname' field on the checkout form
			$checkoutFormData['card_alias'] = $this->get_card_alias($order);

			// correlates to the 'cardholder name (if different)' field on the checkout form
			$checkoutFormData['card_name'] = $this->get_card_name();

			// correlates to the 'use a saved card' field on the checkout form
			$checkoutFormData['saved_card_id'] = $this->get_saved_card_id();

			// correlates to the 'expiry' field on the checkout form
			$checkoutFormData['expiry'] = $this->get_expiry();

			// correlates to the 'card code cvv cvv2' field on the checkout form
			$checkoutFormData['cvv2'] = $this->get_cvv2();

			// the submitted form data (billing, shipping, custom fields, etc
			$wc_checkout = new WC_Checkout();
			$posted_data = $wc_checkout->get_posted_data();
			$checkoutFormData['submitted'] = $posted_data;


			return $checkoutFormData;
		}

		/***********************************************************************************************************
		 *
		 * functions for retrieving/dealing with checkout form data, etc.
		 *
		 ***********************************************************************************************************/


		public function get_profile_id($user_id) {
			// gets this user's CardConnect profileid from the USER META, if one exists, else return FALSE
			$profile_id = $this->profiles_enabled ? $this->saved_cards->get_user_profile_id($user_id) : false;

			return $profile_id;
		}

		public function get_token() {
			// tokenized version of the user's credit card #
			$token = isset($_POST['card_connect_token']) ? wc_clean($_POST['card_connect_token']) : false;

			return $token;
		}

		public function get_store_new_card() {
			// correlates to the 'save this card' checkbox on the checkout form
			$store_new_card = isset($_POST['card_connect-save-card']) ? wc_clean($_POST['card_connect-save-card']) : false;

			return $store_new_card;
		}

		public function get_card_alias($order) {
			$order = wc_get_order($order);
			// correlates to the 'card nickname' field on the checkout form
			$card_alias = isset($_POST['card_connect-new-card-alias']) ? wc_clean($_POST['card_connect-new-card-alias']) : '';
			if (trim($card_alias) == '') {
				//			$date = date("Y-m-d H:i:s");
				$date = date("Ymd-Hi");
				$card_alias = $order->get_billing_last_name() . '_' . $date;
			} else {
				$card_alias = trim($card_alias);
			}

			return $card_alias;
		}

		public function get_card_name() {
			// correlates to the 'cardholder name (if different)' field on the checkout form
			$card_name = isset($_POST['card_connect-card-name']) ? wc_clean($_POST['card_connect-card-name']) : false;

			return $card_name;
		}

		public function get_saved_card_id() {
			// correlates to the 'use a saved card' field on the checkout form
			$saved_card_id = isset($_POST['card_connect-cards']) ? wc_clean($_POST['card_connect-cards']) : false;

			return $saved_card_id;
		}

		/*
		 * Utilizes the above get_* functions to get all checkout form data
		 * and then returns an array
		 *
		 */

		public function get_expiry() {
			// correlates to the 'expiry' field on the checkout form
			$expiry = isset($_POST['card_connect-card-expiry']) ? preg_replace('/[^\d]/i', '', wc_clean($_POST['card_connect-card-expiry'])) : false;

			return $expiry;
		}

		public function get_cvv2() {
			// correlates to the 'card code cvv cvv2' field on the checkout form
			$cvv2 = isset($_POST['card_connect-card-cvc']) ? wc_clean($_POST['card_connect-card-cvc']) : false;

			return $cvv2;
		}

		public function handleCheckoutFormDataError($showNotices = false) {

			if ($showNotices) {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'error');
			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		public function get_order_total_formatted($order) {

			/* 100x change - do not use cents any longer, avoid more than 2 decimals */
			$order_total = false;
			$order_total = number_format($order->get_total(), 2, '.', '');
			$this->order_total = $order_total;

			return $this->order_total;
		}

		/**
		 * converts the WooCommerce store's currency code to the currency code expected by CardConnect API
		 */
		public function getCardConnectCurrencyCode($wc_currency_code = NULL) {

			if (is_null($wc_currency_code)) {
				// get the value of: 'wp-admin > WooCommerce > Settings > General > Currency'
				$wc_currency_code = get_woocommerce_currency();
			}

			$cardconnect_currency_code = 'UNK';        // UNK = Unknown

			// $wc_currency_code => $cardconnect_currency_code
			// note: the !!! denotes those where the code differs btwn WooCommerce and CardConnect
			$lookup = array(
				'AED' => 'AED',
				// United Arab Emirates Dirham
				'ARS' => 'ARA',
				// !!! Argentina - Argentine Peso
				'AUD' => 'AUD',
				// Australian Dollars
				'BDT' => 'BDT',
				// Bangladeshi Taka
				'BGN' => 'BGN',
				// Bulgarian Lev
				'BRL' => 'BRL',
				// Brazilian Real
				'CAD' => 'CAD',
				// Canadian Dollars
				'CHF' => 'CHF',
				// Swiss Franc
				'CLP' => 'CLP',
				// Chilean Peso
				'CNY' => 'CNY',
				// Chinese Yuan
				'COP' => 'COP',
				// Colombian Peso
				'CZK' => 'CZK',
				// Czech Koruna
				'DKK' => 'DKK',
				// Danish Krone
				'DOP' => 'DOP',
				// Dominican Peso
				'EGP' => 'EGP',
				// Egyptian Pound
				'EUR' => 'EUR',
				// Euros
				'GBP' => 'GBP',
				// Pounds Sterling
				'HKD' => 'HKD',
				// Hong Kong Dollar
				'HRK' => 'CRK',
				// !!! Croatia kuna
				'HUF' => 'HUF',
				// Hungarian Forint
				'IDR' => 'IDR',
				// Indonesia Rupiah
				'ILS' => 'ILS',
				// Israeli Shekel
				'INR' => 'INR',
				// Indian Rupee
				'ISK' => 'ISK',
				// Icelandic krona
				'JPY' => 'JPY',
				// Japanese Yen
				'KES' => 'KES',
				// Kenyan shilling
				'LAK' => 'LAK',
				// Lao Kip
				'KRW' => 'KRW',
				// South Korean Won
				'MXN' => 'MXP',
				// !!! Mexican Peso
				'MYR' => 'MYR',
				// Malaysian Ringgits
				'NGN' => 'NGN',
				// Nigerian Naira
				'NOK' => 'NOK',
				// Norwegian Krone
				'NPR' => 'NPR',
				// Nepali Rupee
				'NZD' => 'NZD',
				// New Zealand Dollar
				'PHP' => 'PHP',
				// Philippine Pesos
				'PKR' => 'PKR',
				// Pakistani Rupee
				'PLN' => 'PLZ',
				// !!! Polish Zloty
				'PYG' => 'PYG',
				// Paraguayan Guaraní
				'RON' => 'RON',
				// Romanian Leu
				'RUB' => 'RUB',
				// Russian Ruble
				'SEK' => 'SEK',
				// Swedish Krona
				'SGD' => 'SGD',
				// Singapore Dollar
				'THB' => 'THB',
				// Thai Baht
				'TRY' => 'TRY',
				// Turkish Lira
				'TWD' => 'TWD',
				// Taiwan New Dollars
				'UAH' => 'UNK',
				// !!! Ukrainian Hryvnia
				'USD' => 'USD',
				// US Dollars
				'VND' => 'VND',
				// Vietnamese Dong
				'ZAR' => 'ZAR',
				// South African rand
			);

			$cardconnect_currency_code = $lookup[$wc_currency_code];

			return $cardconnect_currency_code;
		}

		public function handleNoCardConnectConnection($order, $showNotices = false) {
			$order->add_order_note('CardPointe is not configured!');

			if ($showNotices) {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'CardPointe is not configured! ', 'error');
			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		/***********************************************************************************************************
		 *
		 * functions for handling various cardconnect API Authorization responses
		 * $showNotices shall be set to false when these are called from non-checkout payments such as pre-order
		 * releases.
		 * $showNotices shall be set to true when these are called from website checkout operations
		 *
		 ***********************************************************************************************************/


		public function handleAuthorizationResponse_NoResponse($order, $showNotices = false) {
			$order->add_order_note(sprintf(__('CardPointe failed transaction. Response: %s', 'woocommerce'), 'CURL error?'));

			if ($showNotices) {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'A critical server error prevented this transaction from completing. Please confirm your information and try again.', 'error');
			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);

		}

		private function handleAuthorizationResponse_custom($order, $showNotices = true, $reason = 'other') {
			switch ($reason) {
				case('banned'):
					$order->add_order_note('Tokenized Card hit tries limit. Unlock this card in the CardPointe settings of WooCommerce.');
					if ($showNotices) {
						wc_add_notice(__('Payment error: ', 'woothemes') . 'This card has been banned for too many attempts. Please contact the site admin if you feel this is in error.', 'error');
					}
					return array(
						'result'   => 'fail',
						'redirect' => '',
					);

				case('hammered'):
					$order->add_order_note('Too many order submissions in a short period. This order may be fraudulent.');
					if ($showNotices) {
						wc_add_notice(__('Payment error: ', 'woothemes') . 'Too many order submissions in a short period. Please try again shortly.', 'error');
					}
					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				case('order_banned'):
					$order->add_order_note('This order had too many payment attempts, which may indicate fraudulent activity.');
					if ($showNotices) {
						wc_add_notice(__('Payment error: ', 'woothemes') . 'Too many order submissions. Please contact the site admin for assistance.', 'error');
					}
					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				default:
					$order->add_order_note('Unknown Error. Please reach out to the plugin author for assistance.');
					if ($showNotices) {
						wc_add_notice(__('Payment error: ', 'woothemes') . 'Unknown Error. Please contact the site admin for assistance.', 'error');
					}
					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
			}

		}

		/**
		 * Matches response AVS and CVV information against admin preferences.
		 * The admin preferences are set in: wp-admin > WooCommerce > Settings > Checkout > CardConnect
		 *
		 * @return array
		 */
		public function verify_customer_data($response) {

			$error = array();

			// admin preference: Void on CVV failure. Void order if CVV2/CVC2/CID does not match.
			if ($this->verification['void_cvv'] === 'yes' && $response['cvvresp'] === 'N') {
				$error[] = 'Invalid CVV. Please confirm the supplied CVV information.';
			}

			// admin preference: Void on AVS failure. Void order if Address and ZIP do not match.
			if ($this->verification['void_avs'] === 'yes' && $response['avsresp'] === 'N') {
				$error[] = 'Address verification failed. Please confirm the supplied billing information.';
			}

			return array(
				'is_valid' => count($error) === 0,
				'errors'   => $error,
			);
		}

		/**
		 * Use this function if a CVV or AVS verification failed.
		 * This function will void the invalid transaction with cardconnect and update the $order and GUI appropriately.
		 *
		 */
		public function handleVerificationError($order, $order_verification, $retref, $showNotices) {
			$order = wc_get_order($order);

			$request = array(
				'merchid'    => $this->api_credentials['mid'],
				'currency'   => $this->getCardConnectCurrencyCode($order->get_currency()),
				'retref'     => $retref,
				'frontendid' => $this->front_end_id,
			);

			if (!is_null($this->get_cc_client())) {
				$void_response = $this->get_cc_client()->voidTransaction($request);
			} else {
				return $this->handleNoCardConnectConnection($order, $showNotices);
			}

			if ($void_response['authcode'] === 'REVERS') {
				$order->update_status('failed', __('Payment Failed - ', 'cardconnect-payment-gateway'));

				foreach ($order_verification['errors'] as $error) {
					$order->add_order_note(sprintf(__($error, 'woocommerce')));
					if ($showNotices) {
						wc_add_notice(__('Payment error: ', 'woothemes') . $error, 'error');
					}
				}

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}
		}

		public function handleAuthorizationResponse_Declined($order, $response, $showNotices = false) {

			$order->add_order_note(sprintf(__('CardPointe declined transaction. Response: %s', 'woocommerce'), $response['resptext']));
			$order->update_status('failed', __('Payment Declined - ', 'cardconnect-payment-gateway'));

			if ($showNotices) {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'Order Declined : ' . $response['resptext'], 'error');
			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		public function handleAuthorizationResponse_Retry($order, $response, $showNotices = false) {
			$order->add_order_note(sprintf(__('CardPointe failed transaction. Response: %s', 'woocommerce'), $response['resptext']));
			$order->update_status('failed', __('Payment Failed - ', 'cardconnect-payment-gateway'));

			if ($showNotices) {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'An error prevented this transaction from completing. Please confirm your information and try again.', 'error');
			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		public function handleAuthorizationResponse_DefaultError($order, $showNotices = false) {

			$order->update_status('failed', __('Payment Failed - ', 'cardconnect-payment-gateway'));

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		/**
		 * Output payment fields and required JS
		 *
		 * @return void
		 */
		public function payment_fields() {

			$isSandbox = $this->sandbox !== 'no';
			$port = $this->cc_ports[$this->env_key];

			wp_enqueue_script('wc-credit-card-form');
			wp_enqueue_script('saved-card-cardconnect');
			wp_enqueue_style('woocommerce-cardconnect-paymentform');
			wp_enqueue_script('woocommerce-cardconnect');
			wp_localize_script('woocommerce-cardconnect', 'wooCardConnect', array(
				'isLive'          => !$isSandbox ? true : false,
				'profilesEnabled' => $this->profiles_enabled ? true : false,
				'apiEndpoint'     => array(
					'basePath' => "https://{$this->site}.{$this->domain}:{$port}",
					'cs'       => $this->cs_path,
					'itoke'    => $this->itoke_path,
				),
				'allowedCards'    => $this->card_types,
				'userSignedIn'    => is_user_logged_in(),
				'iframeOptions'   => $this->iframe_options,
			));

			$card_icons = array_reduce($this->card_types, function ($carry, $card_name) {
				$plugin_path = WC_CARDCONNECT_PLUGIN_URL . '/assets/';
				$carry .= "<li class='card-connect-allowed-card__li'><img class='card-connect-allowed-cards__img' src='$plugin_path/$card_name.png' alt='$card_name'/></li>";

				return $carry;
			}, '');

			// removed the ports SEPT 2019 - just UAT for now
			$iframe_src = "https://{$this->site}.{$this->domain}{$this->itoke_path}?";

			// Querystring params: https://developer.cardconnect.com/hosted-iframe-tokenizer#optional-parameters

			// Sets some default params to:
			// invalidinputevent - CardConnect posts error message message if an invalid/incomplete number entered
			// enhancedresponse - CardConnect posts verbose messages, specifically error codes and error messages

			// Enable below to instruct iframe to return detailed data rather than just the token
			// $iframe_src .= '?invalidinputevent=true&enhancedresponse=true';

			// Styles the card number to be separated every four numbers
			if ($this->iframe_options['formatinput']) {
				$iframe_src .= '&formatinput=true';
			}

			// Validation & tokenization for manual input is normally performed when an onBlur event occurs on the
			// input field (e.g. when the user clicks/tabs to the next field in the form). If 'tokenizewheninactive'
			// is set to true, validation & tokenization will be performed once the input field stops receiving input
			// from the user.
			if ($this->iframe_options['tokenizewheninactive']) {
				$iframe_src .= '&tokenizewheninactive=true';
				$iframe_src .= '&inactivityto=' . $this->iframe_options['inactivityto'];
			}
			$captcha_theme = isset($this->settings["recaptcha_theme"]) && !empty($this->settings["recaptcha_theme"]) ? $this->settings["recaptcha_theme"] : 'no';
			$template_params = array(
				'card_icons'       => $card_icons,
				'is_sandbox'       => $isSandbox,
				'is_iframe'        => $this->iframe_options['enabled'],
				'is_autostyle'     => $this->iframe_options['autostyle'],
				'iframe_style'     => $this->iframe_options['style'],
				'iframe_src'       => $iframe_src,
				'profiles_enabled' => $this->profiles_enabled,
				'description'      => $this->description,
				'recaptcha'        => ['secret' => $this->settings["recaptcha_secret_key"], 'site' => $this->settings["recaptcha_site_key"], 'enabled' => $this->settings["recaptcha"], 'dark' => $captcha_theme],
			);

			if ($this->profiles_enabled && $this->saved_cards) {
				$template_params['saved_cards'] = $this->saved_cards->get_user_cards(get_current_user_id());
			}

			wc_get_template('card-input.php', $template_params, WC_CARDCONNECT_PLUGIN_PATH, WC_CARDCONNECT_TEMPLATE_PATH);
		}

		/**
		 * Process refunds
		 * WooCommerce 2.2 or later
		 *
		 * @param int $order_id
		 * @param float $amount
		 * @param string $reason
		 *
		 * @return bool|WP_Error
		 * @uses   Simplify_BadRequestException
		 * @uses   Simplify_ApiException
		 */
		public function process_refund($order_id, $amount = NULL, $reason = '') {

			$order = $order = wc_get_order($order_id);
			$retref = get_post_meta($order_id, '_transaction_id', true);

			$amount = number_format($amount, '2', '.', '');

			$request = array(
				'merchid'    => $this->api_credentials['mid'],
				'amount'     => $amount,
				'currency'   => $this->getCardConnectCurrencyCode($order->get_currency()),
				'retref'     => $retref,
				'frontendid' => $this->front_end_id,
			);

			if (!is_null($this->get_cc_client())) {
				$response = $this->get_cc_client()->refundTransaction($request);
			} else {
				wc_add_notice(__('Payment error: ', 'woothemes') . 'CardPointe is not configured! ', 'error');
				$order->add_order_note('CardPointe is not configured!');

				return;
			}


			if ('A' === $response['respstat']) {
				$order->add_order_note(sprintf(__('CardPointe refunded %s. Response: %s. Retref: %s', 'woocommerce'), get_woocommerce_currency_symbol() . ' ' . $response['amount'], $response['resptext'], $response['retref']));

				return true;
			} else {
				throw new Exception(__('Refund was declined.', 'woocommerce'));

				return false;
			}

		}

		/**
		 * Register Frontend Assets
		 **/
		public function register_scripts() {
			wp_register_script('woocommerce-cardconnect', WC_CARDCONNECT_PLUGIN_URL . '/javascript/dist/cardconnect.js', array('jquery'), WC_CARDCONNECT_VER, true);
			wp_register_script('saved-card-cardconnect', WC_CARDCONNECT_PLUGIN_URL . '/javascript/saved-card-mod-cardconnect.js', array('jquery'), WC_CARDCONNECT_VER, true);
			wp_register_style('woocommerce-cardconnect-paymentform', WC_CARDCONNECT_PLUGIN_URL . '/stylesheets/woocommerce-cc-gateway.css', NULL, WC_CARDCONNECT_VER);
		}

		private function allowed_html($allow_script = false) {
			$allowed = array(
				'a'          => array(
					'class' => [],
					'href'  => [],
					'rel'   => [],
					'title' => [],
					'style' => [],

				),
				'table'      => array(
					'class' => [],
					'id'    => [],
					'scope' => [],
					'style' => [],

				),
				'tr'         => array(
					'class'  => [],
					'id'     => [],
					'scope'  => [],
					'valign' => [],
					'style'  => [],

				),
				'th'         => array(
					'id'    => [],
					'class' => [],
					'style' => [],
				),
				'td'         => array(
					'id'    => [],
					'class' => [],
					'style' => [],
				),
				'br'         => [],
				'abbr'       => array(
					'title' => [],
				),
				'b'          => [],
				'blockquote' => array(
					'cite' => [],
				),
				'cite'       => array(
					'title' => [],
				),
				'code'       => [],
				'del'        => array(
					'datetime' => [],
					'title'    => [],
				),
				'dd'         => [],
				'div'        => array(
					'class' => [],
					'title' => [],
					'style' => [],
				),
				'dl'         => [],
				'dt'         => [],
				'em'         => [],
				'h1'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'h2'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'h3'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'h4'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'h5'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'h6'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'i'          => [],
				'img'        => array(
					'alt'    => [],
					'class'  => [],
					'id'     => [],
					'height' => [],
					'src'    => [],
					'width'  => [],
					'style'  => [],

				),
				'li'         => array(
					'class' => [],
					'style' => [],

				),
				'ol'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'p'          => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'q'          => array(
					'cite'  => [],
					'title' => [],
					'style' => [],

				),
				'span'       => array(
					'class' => [],
					'id'    => [],
					'title' => [],
					'style' => [],
				),
				'strike'     => [
					'style' => [],
				],
				'strong'     => [
					'style' => [],
				],
				'ul'         => array(
					'class' => [],
					'id'    => [],
					'style' => [],

				),
				'input'      => array(
					'class' => [],
					'id'    => [],
					'value' => [],
					'style' => [],
					'type'  => [],

				),
				'textinput'  => array(
					'class' => [],
					'id'    => [],
					'value' => [],
					'style' => [],
					'type'  => [],

				),
				'style'      => [],
				'fieldset'   => [],
				'legend'     => [],
				'label'      => [
					'for'   => [],
					'id'    => [],
					'class' => [],
					'style' => [],
				],
			);
			if ($allow_script) {
				$allowed['script'] = [];
			}
			return $allowed;
		}

		private function allowed_admin_panel_html() {
			return [
				'p'        => [
					'class' => [],
				],
				'a'        => [
					'class' => [],
					'href' => [],
					'style' => [],
				],
				'strong'   => [
					'class' => [],
				],
				'tr'       => [
					'valign' => []
				],
				'span'     => [
					'class'    => [],
					'data-tip' => [],
				],
				'th'       => [
					'scope' => [],
					'class' => [],
				],
				'td'       => [
					'class' => [],
				],
				'label'    => [
					'for'      => [],
					'class'    => [],
					'data-tip' => [],
				],
				'fieldset' => [],
				'legend'   => [
					'class' => [],
				],
				'br'       => [],
				'input'    => [
					'for'         => [],
					'class'       => [],
					'type'        => [],
					'name'        => [],
					'id'          => [],
					'style'       => [],
					'value'       => [],
					'checked'     => [],
					'placeholder' => [],
				],
				'select'   => [
					'class'       => [],
					'type'        => [],
					'name'        => [],
					'multiple'    => [],
					'id'          => [],
					'style'       => [],
					'value'       => [],
					'checked'     => [],
					'placeholder' => [],
				],
				'option'   => [
					'for'         => [],
					'selected'    => [],
					'class'       => [],
					'type'        => [],
					'name'        => [],
					'id'          => [],
					'style'       => [],
					'value'       => [],
					'checked'     => [],
					'placeholder' => [],
				],
				'textarea' => [
					'rows'        => [],
					'cols'        => [],
					'class'       => [],
					'type'        => [],
					'name'        => [],
					'id'          => [],
					'style'       => [],
					'placeholder' => [],
				],
				'h3'       => [
					'class' => [],
					'id'    => [],
					'style' => [],
				],
			];
		}
	}

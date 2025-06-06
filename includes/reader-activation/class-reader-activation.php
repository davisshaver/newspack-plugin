<?php
/**
 * Reader Activation (publicly rebranded as "Audience Management").
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Recaptcha;
use Newspack\Reader_Activation\Sync;
use Newspack\Renewal;
use Newspack\WooCommerce_My_Account;

defined( 'ABSPATH' ) || exit;

/**
 * Reader Activation Class.
 */
final class Reader_Activation {

	const OPTIONS_PREFIX = 'newspack_reader_activation_';

	const AUTH_READER_COOKIE        = 'np_auth_reader';
	const AUTH_INTENTION_COOKIE     = 'np_auth_intention';
	const SCRIPT_HANDLE             = 'newspack-reader-activation';
	const AUTH_SCRIPT_HANDLE        = 'newspack-reader-auth';
	const NEWSLETTERS_SCRIPT_HANDLE = 'newspack-newsletters-signup';

	/**
	 * Reader user meta keys.
	 */
	const READER                            = 'np_reader';
	const EMAIL_VERIFIED                    = 'np_reader_email_verified';
	const WITHOUT_PASSWORD                  = 'np_reader_without_password';
	const REGISTRATION_METHOD               = 'np_reader_registration_method';
	const CONNECTED_ACCOUNT                 = 'np_reader_connected_account';
	const READER_SAVED_GENERIC_DISPLAY_NAME = 'np_reader_saved_generic_display_name';

	/**
	 * Unverified email rate limiting
	 */
	const LAST_EMAIL_DATE = 'np_reader_last_email_date';
	const EMAIL_INTERVAL  = 10; // 10 seconds

	/**
	 * Auth form.
	 */
	const AUTH_FORM_ACTION  = 'reader-activation-auth-form';
	const AUTH_FORM_OPTIONS = [
		'signin',
		'register',
		'link',
		'pwd',
	];

	/**
	 * Registration methods that don't require account verification.
	 */
	const SSO_REGISTRATION_METHODS = [ 'google' ];

	/**
	 * Newsletters signup form.
	 */
	const NEWSLETTERS_SIGNUP_FORM_ACTION = 'reader-activation-newsletters-signup';

	/**
	 * Whether the session is authenticating a newly registered reader
	 *
	 * @var bool
	 */
	private static $is_new_reader_auth = false;

	/**
	 * UI labels for reader activation flows.
	 *
	 * @var mixed[]
	 */
	private static $reader_activation_labels = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		\add_action( 'wp_footer', [ __CLASS__, 'render_auth_modal' ] );
		\add_action( 'wp_footer', [ __CLASS__, 'render_newsletters_signup_modal' ] );
		\add_action( 'wp_ajax_newspack_reader_activation_newsletters_signup', [ __CLASS__, 'newsletters_signup' ] );
		\add_action( 'woocommerce_customer_reset_password', [ __CLASS__, 'login_after_password_reset' ] );

		if ( self::is_enabled() ) {
			\add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
			\add_action( 'clear_auth_cookie', [ __CLASS__, 'clear_auth_intention_cookie' ] );
			\add_action( 'clear_auth_cookie', [ __CLASS__, 'clear_auth_reader_cookie' ] );
			\add_action( 'set_auth_cookie', [ __CLASS__, 'clear_auth_intention_cookie' ] );
			\add_filter( 'login_form_defaults', [ __CLASS__, 'add_auth_intention_to_login_form' ], 20 );
			\add_action( 'wp_login', [ __CLASS__, 'login_set_reader_cookie' ], 10, 2 );
			\add_action( 'resetpass_form', [ __CLASS__, 'set_reader_verified' ] );
			\add_action( 'password_reset', [ __CLASS__, 'set_reader_verified' ] );
			\add_action( 'password_reset', [ __CLASS__, 'set_reader_has_password' ] );
			\add_action( 'profile_update', [ __CLASS__, 'maybe_set_reader_has_password' ], 10, 3 );
			\add_action( 'newspack_magic_link_authenticated', [ __CLASS__, 'set_reader_verified' ] );
			\add_action( 'auth_cookie_expiration', [ __CLASS__, 'auth_cookie_expiration' ], 10, 3 );
			\add_action( 'init', [ __CLASS__, 'setup_nav_menu' ] );
			\add_action( 'wc_get_template', [ __CLASS__, 'replace_woocommerce_auth_form' ], 10, 2 );
			\add_action( 'template_redirect', [ __CLASS__, 'process_auth_form' ] );
			\add_filter( 'woocommerce_new_customer_data', [ __CLASS__, 'canonize_user_data' ] );
			\add_filter( 'wp_pre_insert_user_data', [ __CLASS__, 'validate_user_data' ], 10, 4 );
			\add_filter( 'woocommerce_add_error', [ __CLASS__, 'better_display_name_error' ] );
			\add_filter( 'amp_native_post_form_allowed', '__return_true' );
			\add_filter( 'woocommerce_email_actions', [ __CLASS__, 'disable_woocommerce_new_user_email' ] );
			\add_filter( 'retrieve_password_notification_email', [ __CLASS__, 'password_reset_configuration' ], 10, 4 );
			\add_action( 'lostpassword_post', [ __CLASS__, 'set_password_reset_mail_content_type' ] );
			\add_filter( 'lostpassword_errors', [ __CLASS__, 'rate_limit_lost_password' ], 10, 2 );
			\add_filter( 'newspack_esp_sync_contact', [ __CLASS__, 'set_mailchimp_sync_contact_status' ], 10, 2 );
		}
	}

	/**
	 * Enqueue front-end scripts.
	 */
	public static function enqueue_scripts() {
		/**
		 * Filters whether to enqueue the reader auth scripts.
		 *
		 * @param bool $allow_reg_block_render Whether to allow the registration block to render.
		 */
		if ( apply_filters( 'newspack_reader_activation_should_render_auth', true ) ) {
			$authenticated_email = self::get_logged_in_reader_email_address();
			$script_dependencies = [];
			$script_data         = [
				'auth_intention_cookie' => self::AUTH_INTENTION_COOKIE,
				'cid_cookie'            => NEWSPACK_CLIENT_ID_COOKIE_NAME,
				'is_logged_in'          => \is_user_logged_in(),
				'authenticated_email'   => $authenticated_email,
				'otp_auth_action'       => Magic_Link::OTP_AUTH_ACTION,
				'otp_rate_interval'     => Magic_Link::RATE_INTERVAL,
				'auth_action_result'    => Magic_Link::AUTH_ACTION_RESULT,
				'account_url'           => function_exists( 'wc_get_account_endpoint_url' ) ? \wc_get_account_endpoint_url( 'dashboard' ) : '',
				'is_ras_enabled'        => self::is_enabled(),
			];

			if ( Recaptcha::can_use_captcha() ) {
				$recaptcha_version                = Recaptcha::get_setting( 'version' );
				$script_dependencies[]            = Recaptcha::SCRIPT_HANDLE;
				if ( 'v3' === $recaptcha_version ) {
					$script_data['captcha_site_key'] = Recaptcha::get_site_key();
				}
			}

			Newspack::load_common_assets();

			/**
			* Reader Activation Frontend Library.
			*/
			\wp_enqueue_script(
				self::SCRIPT_HANDLE,
				Newspack::plugin_url() . '/dist/reader-activation.js',
				$script_dependencies,
				NEWSPACK_PLUGIN_VERSION,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
			);
			\wp_localize_script(
				self::SCRIPT_HANDLE,
				'newspack_ras_config',
				$script_data
			);
			\wp_script_add_data( self::SCRIPT_HANDLE, 'async', true );
			\wp_script_add_data( self::SCRIPT_HANDLE, 'amp-plus', true );

			/**
			* Reader Authentication
			*/
			\wp_enqueue_script(
				self::AUTH_SCRIPT_HANDLE,
				Newspack::plugin_url() . '/dist/reader-auth.js',
				[ self::SCRIPT_HANDLE ],
				NEWSPACK_PLUGIN_VERSION,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
			);
			\wp_localize_script( self::AUTH_SCRIPT_HANDLE, 'newspack_reader_activation_labels', self::get_reader_activation_labels() );
			\wp_script_add_data( self::AUTH_SCRIPT_HANDLE, 'async', true );
			\wp_script_add_data( self::AUTH_SCRIPT_HANDLE, 'amp-plus', true );
			\wp_enqueue_style(
				self::AUTH_SCRIPT_HANDLE,
				Newspack::plugin_url() . '/dist/reader-auth.css',
				[],
				NEWSPACK_PLUGIN_VERSION
			);
		}

		if ( self::is_newsletters_signup_available() ) {
			/**
			* Newsletters Signup.
			*/
			\wp_enqueue_script(
				self::NEWSLETTERS_SCRIPT_HANDLE,
				Newspack::plugin_url() . '/dist/newsletters-signup.js',
				[ self::SCRIPT_HANDLE ],
				NEWSPACK_PLUGIN_VERSION,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
			);

			\wp_localize_script(
				self::NEWSLETTERS_SCRIPT_HANDLE,
				'newspack_reader_activation_newsletters',
				[
					'newspack_ajax_url' => admin_url( 'admin-ajax.php' ),
				]
			);

			\wp_script_add_data( self::NEWSLETTERS_SCRIPT_HANDLE, 'async', true );
			\wp_enqueue_style(
				self::NEWSLETTERS_SCRIPT_HANDLE,
				Newspack::plugin_url() . '/dist/newsletters-signup.css',
				[],
				NEWSPACK_PLUGIN_VERSION
			);
		}
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/reader-newsletter-signup-lists/(?P<email_address>[\a-z]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_render_newsletters_signup_form' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email_address' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);
	}

	/**
	 * Get labels for reader activation flows.
	 *
	 * @param string|null $key Key of the label to return (optional).
	 *
	 * @return mixed[]|string The label string or an array of labels keyed by string.
	 */
	private static function get_reader_activation_labels( $key = null ) {
		if ( empty( self::$reader_activation_labels ) ) {
			$default_labels = [
				'title'                    => __( 'Sign in', 'newspack-plugin' ),
				'invalid_email'            => __( 'Please enter a valid email address.', 'newspack-plugin' ),
				'invalid_password'         => __( 'Please enter a password.', 'newspack-plugin' ),
				'invalid_display'          => __( 'Display name cannot match your email address. Please choose a different display name.', 'newspack-plugin' ),
				'blocked_popup'            => __( 'The popup has been blocked. Allow popups for the site and try again.', 'newspack-plugin' ),
				'code_sent'                => __( 'Code sent! Check your inbox.', 'newspack-plugin' ),
				'code_resent'              => __( 'Code resent! Check your inbox.', 'newspack-plugin' ),
				'create_account'           => __( 'Create an account', 'newspack-plugin' ),
				'signin'                   => [
					'title'           => __( 'Sign in', 'newspack-plugin' ),
					'success_title'   => __( 'Success! You’re signed in.', 'newspack-plugin' ),
					'success_message' => __( 'Login successful!', 'newspack-plugin' ),
					'continue'        => __( 'Continue', 'newspack-plugin' ),
					'resend_code'     => __( 'Resend code', 'newspack-plugin' ),
					'otp'             => __( 'Email me a one-time code instead', 'newspack-plugin' ),
					'otp_title'       => __( 'Enter the code sent to your email.', 'newspack-plugin' ),
					'forgot_password' => __( 'Forgot password', 'newspack-plugin' ),
					'create_account'  => __( 'Create an account', 'newspack-plugin' ),
					'register'        => __( 'Sign in to an existing account', 'newspack-plugin' ),
					'go_back'         => __( 'Go back', 'newspack-plugin' ),
					'set_password'    => __( 'Set a password (optional)', 'newspack-plugin' ),
				],
				'register'                 => [
					'title'               => __( 'Create an account', 'newspack-plugin' ),
					'success_title'       => __( 'Success! Your account was created and you’re signed in.', 'newspack-plugin' ),
					'success_description' => __( 'In the future, you’ll sign in with a magic link, or a code sent to your email. If you’d rather use a password, you can set one below.', 'newspack-plugin' ),
				],
				'verify'                   => __( 'Thank you for verifying your account!', 'newspack-plugin' ),
				'magic_link'               => __( 'Please check your inbox for an authentication link.', 'newspack-plugin' ),
				'password_reset_interval'  => __( 'Please wait a moment before requesting another password reset email.', 'newspack-plugin' ),
				'account_link'             => [
					'signedin'  => __( 'My Account', 'newspack-plugin' ),
					'signedout' => __( 'Sign In', 'newspack-plugin' ),
				],
				'newsletters_cta'          => __( 'Subscribe to our newsletter', 'newspack-plugin' ),
				'newsletters_confirmation' => sprintf(
					// Translators: %s is the site name.
					__( 'Thanks for supporting %s.', 'newspack-plugin' ),
					get_option( 'blogname' )
				),
				'newsletters_continue'     => __( 'Continue', 'newspack-plugin' ),
				'newsletters_details'      => sprintf(
					// Translators: %s is the site name.
					__( 'Get the best of %s directly in your email inbox.', 'newspack-plugin' ),
					get_bloginfo( 'name' )
				),
				'newsletters_success'      => __( 'Signup successful!', 'newspack-plugin' ),
				'newsletters_title'        => __( 'Sign up for newsletters', 'newspack-plugin' ),
				'auth_form_action'         => self::AUTH_FORM_ACTION,
			];

			/**
			* Filters the global labels for reader activation auth flow.
			*
			* @param mixed[] $labels Labels keyed by name.
			*/
			$filtered_labels = apply_filters( 'newspack_reader_activation_auth_labels', $default_labels );

			foreach ( $default_labels as $key => $label ) {
				if ( isset( $filtered_labels[ $key ] ) ) {
					if ( is_array( $label ) && is_array( $filtered_labels[ $key ] ) ) {
						self::$reader_activation_labels[ $key ] = array_merge( $label, $filtered_labels[ $key ] );
					} elseif ( is_string( $label ) && is_string( $filtered_labels[ $key ] ) ) {
						self::$reader_activation_labels[ $key ] = $filtered_labels[ $key ];
					} else {
						// If filtered label type doesn't match, fallback to default.
						self::$reader_activation_labels[ $key ] = $label;
					}
				} else {
					self::$reader_activation_labels[ $key ] = $label;
				}
			}
		}

		if ( ! $key ) {
			return self::$reader_activation_labels;
		}

		return self::$reader_activation_labels[ $key ] ?? '';
	}

	/**
	 * Get settings config with default values.
	 *
	 * @return mixed[] Settings default values keyed by their name.
	 */
	private static function get_settings_config() {
		$settings_config = [
			'enabled'                                      => false,
			'enabled_account_link'                         => true,
			'account_link_menu_locations'                  => [ 'tertiary-menu' ],
			'newsletters_label'                            => self::get_reader_activation_labels( 'newsletters_cta' ),
			'use_custom_lists'                             => false,
			'newsletter_lists'                             => [],
			'newsletter_list_initial_size'                 => self::get_newsletters_list_initial_size(),
			'terms_text'                                   => '',
			'terms_url'                                    => '',
			'sync_esp'                                     => true,
			'metadata_prefix'                              => Sync\Metadata::get_prefix(),
			'metadata_fields'                              => Sync\Metadata::get_fields(),
			'sync_esp_delete'                              => true,
			'active_campaign_master_list'                  => '',
			'constant_contact_list_id'                     => '',
			'mailchimp_audience_id'                        => '',
			'mailchimp_reader_default_status'              => 'transactional',
			'sender_name'                                  => Emails::get_from_name(),
			'sender_email_address'                         => Emails::get_from_email(),
			'contact_email_address'                        => Emails::get_reply_to_email(),
			'woocommerce_registration_required'            => false,
			'woocommerce_checkout_privacy_policy_text'     => self::get_checkout_privacy_policy_text(),
			'woocommerce_post_checkout_success_text'       => self::get_post_checkout_success_text(),
			'woocommerce_post_checkout_registration_success_text' => self::get_post_checkout_registration_success_text(),
			'woocommerce_enable_subscription_confirmation' => false,
			'woocommerce_subscription_confirmation_text'   => self::get_subscription_confirmation_text(),
			'woocommerce_enable_terms_confirmation'        => false,
			'woocommerce_terms_confirmation_text'          => self::get_terms_confirmation_text(),
			'woocommerce_terms_confirmation_url'           => self::get_terms_confirmation_url(),
		];

		/**
		 * Filters the global settings config for reader activation.
		 *
		 * @param mixed[] $settings_config Settings default values keyed by their name.
		 */
		return apply_filters( 'newspack_reader_activation_settings_config', $settings_config );
	}

	/**
	 * Get reader activation global settings.
	 *
	 * @return mixed[] Global settings keyed by their option name.
	 */
	public static function get_settings() {
		$config = self::get_settings_config();

		$settings = [];
		foreach ( $config as $key => $default_value ) {
			$settings[ $key ] = self::get_setting( $key );
		}

		return $settings;
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $name Setting name.
	 *
	 * @return mixed Setting value.
	 */
	public static function get_setting( $name ) {
		$config = self::get_settings_config();
		if ( ! isset( $config[ $name ] ) ) {
			return null;
		}
		$value = \get_option( self::OPTIONS_PREFIX . $name, $config[ $name ] );

		// Use default value type for casting bool option value.
		if ( is_bool( $config[ $name ] ) ) {
			$value = (bool) $value;
		}
		return apply_filters( 'newspack_reader_activation_setting', $value, $name );
	}

	/**
	 * Update a setting value.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Option value.
	 *
	 * @return bool True if the value was updated, false otherwise.
	 */
	public static function update_setting( $key, $value ) {
		$config = self::get_settings_config();
		if ( ! isset( $config[ $key ] ) ) {
			return false;
		}
		if ( is_bool( $value ) ) {
			$value = intval( $value );
		}

		/**
		 * Fires just before a setting is updated
		 *
		 * @param string $key   Option name.
		 * @param mixed  $value Option value.
		 */
		do_action( 'newspack_reader_activation_update_setting', $key, $value );

		if ( 'metadata_prefix' === $key ) {
			return Sync\Metadata::update_prefix( $value );
		}
		if ( 'metadata_fields' === $key ) {
			return Sync\Metadata::update_fields( $value );
		}

		return \update_option( self::OPTIONS_PREFIX . $key, $value );
	}

	/**
	 * Activate RAS features and publish RAS prompts + segments.
	 */
	public static function activate() {
		if ( ! method_exists( 'Newspack_Popups_Presets', 'activate_ras_presets' ) ) {
			return new \WP_Error( 'newspack_reader_activation_missing_dependencies', __( 'Newspack Campaigns plugin is required to activate Reader Activation features.', 'newspack-plugin' ) );
		}

		$activated = \Newspack_Popups_Presets::activate_ras_presets();
		if ( $activated ) {
			self::skip( 'ras_campaign', false );
		}
		return $activated;
	}

	/**
	 * Check if the required Woo plugins are active.
	 *
	 * @return boolean True if all required plugins are active, otherwise false.
	 */
	public static function is_woocommerce_active() {
		$is_active = Donations::is_woocommerce_suite_active();

		if ( \is_wp_error( $is_active ) ) {
			return false;
		}

		return $is_active;
	}

	/**
	 * Is the Newspack Newsletters plugin configured with an ESP?
	 */
	public static function is_esp_configured() {
		$newsletters_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'newspack-newsletters' );

		if ( ! $newsletters_configuration_manager->is_esp_set_up() ) {
			return false;
		}

		$lists = $newsletters_configuration_manager->get_enabled_lists();
		if ( empty( $lists ) || ! is_array( $lists ) ) {
			return false;
		}

		// Can be considered fully configured if the ESP is setup and there's at least one active list.
		foreach ( $lists as $list ) {
			if ( $list['active'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the master list ID for the ESP.
	 *
	 * @param string $provider Optional ESP provider. Defaults to the configured ESP.
	 *
	 * @return string|bool Master list ID or false if not set or not available.
	 */
	public static function get_esp_master_list_id( $provider = '' ) {
		if ( empty( $provider ) && class_exists( 'Newspack_Newsletters' ) ) {
			$provider = \Newspack_Newsletters::service_provider();
		}
		switch ( $provider ) {
			case 'active_campaign':
				return self::get_setting( 'active_campaign_master_list' );
			case 'constant_contact':
				return self::get_setting( 'constant_contact_list_id' );
			case 'mailchimp':
				$audience_id = self::get_setting( 'mailchimp_audience_id' );
				/** Attempt to use list ID from "Mailchimp for WooCommerce" */
				if ( ! $audience_id && function_exists( 'mailchimp_get_list_id' ) ) {
					$audience_id = \mailchimp_get_list_id();
				}
				return ! empty( $audience_id ) ? $audience_id : false;
			default:
				return false;
		}
	}

	/**
	 * Set the contact metadata status for Mailchimp.
	 *
	 * @param array $contact The contact data to sync.
	 *
	 * @return array Modified contact data.
	 */
	public static function set_mailchimp_sync_contact_status( $contact ) {
		$allowed_statuses = [
			'transactional',
			'subscribed',
		];
		$default_status = self::get_setting( 'mailchimp_reader_default_status' );
		$status = in_array( $default_status, $allowed_statuses, true ) ? $default_status : 'transactional';

		$contact['metadata']['status_if_new'] = $status;

		return $contact;
	}

	/**
	 * Get the newsletter lists that should be rendered during registration.
	 *
	 * @return array
	 */
	public static function get_registration_newsletter_lists() {
		if ( ! class_exists( '\Newspack_Newsletters_Subscription' ) ) {
			return [];
		}

		$registration_lists = self::get_available_newsletter_lists();

		/**
		 * Filters the newsletters lists that should be rendered during registration.
		 *
		 * @param array $registration_lists Array of newsletter lists.
		 */
		return apply_filters( 'newspack_registration_newsletters_lists', $registration_lists );
	}

	/**
	 * Get the newsletter lists that should be rendered after checkout.
	 *
	 * @param string $email_address Email address. Optional.
	 *
	 * @return array
	 */
	public static function get_post_checkout_newsletter_lists( $email_address = '' ) {
		$available_lists    = self::get_available_newsletter_lists( $email_address );
		$registration_lists = [];

		if ( empty( $available_lists ) ) {
			return [];
		}

		foreach ( $available_lists as $list_id => $list ) {
			$registration_lists[ $list_id ] = $list;
		}

		/**
		 * Filters the newsletters lists that should be rendered after checkout.
		 *
		 * @param array  $registration_lists Array of newsletter lists.
		 * @param string $email_address      Email address.
		 */
		return apply_filters( 'newspack_post_registration_newsletters_lists', $registration_lists, $email_address );
	}

	/**
	 * Get all available newsletter lists.
	 *
	 * @param string $email_address Email address. Optional.
	 *
	 * @return array
	 */
	public static function get_available_newsletter_lists( $email_address = '' ) {
		if ( ! method_exists( 'Newspack_Newsletters_Subscription', 'get_lists' ) ) {
			return [];
		}
		$use_custom_lists   = self::get_setting( 'use_custom_lists' );
		$available_lists    = \Newspack_Newsletters_Subscription::get_lists_config();
		$registration_lists = [];
		if ( \is_wp_error( $available_lists ) ) {
			return [];
		}
		if ( ! $use_custom_lists ) {
			$registration_lists = $available_lists;
		} else {
			$lists = self::get_setting( 'newsletter_lists' );
			if ( empty( $lists ) ) {
				return [];
			}
			foreach ( $lists as $list ) {
				if ( isset( $available_lists[ $list['id'] ] ) ) {
					$registration_lists[ $list['id'] ]            = $available_lists[ $list['id'] ];
					$registration_lists[ $list['id'] ]['checked'] = $list['checked'] ?? false;
				}
			}
		}

		// Filter out any lists the reader is already signed up for if an email address is provided.
		if ( $email_address && method_exists( '\Newspack_Newsletters_Subscription', 'get_contact_lists' ) ) {
			$current_lists = \Newspack_Newsletters_Subscription::get_contact_lists( $email_address );
			if ( ! \is_wp_error( $current_lists ) && is_array( $current_lists ) ) {
				$filtered_lists = [];
				foreach ( $registration_lists as $list ) {
					// Skip any lists the reader is already signed up for.
					if ( in_array( $list['id'], $current_lists, true ) ) {
						continue;
					}

					$filtered_lists[ $list['id'] ] = $list;
				}
				$registration_lists = $filtered_lists;
			}
		}

		/**
		 * Filters the newsletters lists that should be rendered during registration.
		 *
		 * @param array $registration_lists Array of newsletter lists.
		 */
		return apply_filters( 'newspack_registration_newsletters_lists', $registration_lists );
	}

	/**
	 * Are all Reader Revenue features configured and ready to use?
	 * Platform must be "Newspack" and all donation settings must be configured.
	 */
	public static function is_reader_revenue_ready() {
		$ready             = false;
		$donation_settings = Donations::get_donation_settings();

		if ( \is_wp_error( $donation_settings ) ) {
			return $ready;
		}

		if ( Donations::is_platform_wc() ) {
			$ready = true;
		} elseif ( Donations::is_platform_nrh() && NRH::get_setting( 'nrh_organization_id' ) && method_exists( '\Newspack_Popups_Settings', 'donor_landing_page' ) && \Newspack_Popups_Settings::donor_landing_page() ) {
			$ready = true;
		}

		return $ready;
	}

	/**
	 * Get an array of required plugins for satisfying Reader Revenue prerequisites.
	 * WooCommerce and Woo Subscriptions are required for Newspack, but not for NRH.
	 */
	public static function get_reader_revenue_required_plugins() {
		$required_plugins = [
			'newspack-blocks' => class_exists( '\Newspack_Blocks' ),
		];

		if ( Donations::is_platform_wc() ) {
			$required_plugins['woocommerce'] = class_exists( 'WooCommerce' );
			$required_plugins['woocommerce-subscriptions'] = class_exists( 'WCS_Query' );
		}
		return $required_plugins;
	}

	/**
	 * Are the Legal Pages settings configured?
	 * Allows for blank values.
	 *
	 * @param bool $skip Whether to skip the check.
	 *
	 * @return bool
	 */
	public static function is_terms_configured( $skip = false ) {
		$terms_text = \get_option( self::OPTIONS_PREFIX . 'terms_text', false );
		$terms_url  = \get_option( self::OPTIONS_PREFIX . 'terms_url', false );
		$is_valid   = is_string( $terms_text ) && is_string( $terms_url );
		if ( $skip ) {
			return $is_valid || self::is_skipped( 'terms_conditions' );
		}
		if ( $is_valid ) {
			self::skip( 'terms_conditions', false );
		}

		return $is_valid;
	}

	/**
	 * Are Transaction Email settings configured?
	 */
	public static function is_transactional_email_configured() {
		$sender_name           = \get_option( self::OPTIONS_PREFIX . 'sender_name', false );
		$sender_email          = \get_option( self::OPTIONS_PREFIX . 'sender_email_address', false );
		$contact_email_address = \get_option( self::OPTIONS_PREFIX . 'contact_email_address', false );

		return ! empty( $sender_name ) && ! empty( $sender_email ) && ! empty( $contact_email_address );
	}

	/**
	 * Is reCAPTCHA enabled?
	 *
	 * @param bool $skip Whether to skip the check.
	 *
	 * @return bool
	 */
	public static function is_recaptcha_enabled( $skip = false ) {
		$is_valid = method_exists( '\Newspack\Recaptcha', 'can_use_captcha' ) && \Newspack\Recaptcha::can_use_captcha();
		if ( $skip ) {
			return $is_valid || self::is_skipped( 'recaptcha' );
		}
		if ( $is_valid ) {
			self::skip( 'recaptcha', false );
		}
		return $is_valid;
	}

	/**
	 * Is the RAS campaign configured?
	 *
	 * @param bool $skip Whether to skip the check.
	 *
	 * @return bool
	 */
	public static function is_ras_campaign_configured( $skip = false ) {
		$is_valid = class_exists( 'Newspack_Popups_Presets' ) && get_option( \Newspack_Popups_Presets::NEWSPACK_POPUPS_RAS_LAST_UPDATED, false );
		if ( $skip ) {
			return $is_valid || self::is_skipped( 'ras_campaign' );
		}
		if ( $is_valid ) {
			self::skip( 'ras_campaign', false );
		}
		return $is_valid;
	}

	/**
	 * Are all prerequisites for Reader Activation complete?
	 *
	 * @return bool
	 */
	public static function is_ras_ready_to_configure() {
		$is_ready = self::is_terms_configured( true ) && self::is_esp_configured() && self::is_transactional_email_configured() && self::is_recaptcha_enabled( true ) && self::is_woocommerce_active();

		// If all requirements are met or skipped, and RAS isn't yet enabled, enable it.
		if ( $is_ready && self::is_ras_campaign_configured( true ) && ! self::is_enabled() ) {
			self::update_setting( 'enabled', true );
		}
		return $is_ready;
	}

	/**
	 * Has the given prerequisite been skipped?
	 *
	 * @param string $prerequisite The prerequisite to check.
	 *
	 * @return bool
	 */
	public static function is_skipped( $prerequisite ) {
		// Legacy option name compabitility.
		$legacy_is_skipped = false;
		if ( 'ras_campaign' === $prerequisite ) {
			$legacy_is_skipped = get_option( Audience_Wizard::SKIP_CAMPAIGN_SETUP_OPTION, false ) === '1';
		}

		return boolval( get_option( self::OPTIONS_PREFIX . $prerequisite . '_skipped', $legacy_is_skipped ) );
	}

	/**
	 * Skip or unskip the given prerequisite.
	 *
	 * @param string $prerequisite The prerequisite to skip.
	 * @param bool   $skip If true, skip the prerequisite. If false, unskip it.
	 *
	 * @return bool True if updated, false if not.
	 */
	public static function skip( $prerequisite, $skip = true ) {
		if ( ( $skip && self::is_skipped( $prerequisite ) ) || ( ! $skip && ! self::is_skipped( $prerequisite ) ) ) {
			return true;
		}

		$updated = $skip ? update_option( self::OPTIONS_PREFIX . $prerequisite . '_skipped', '1' ) : delete_option( self::OPTIONS_PREFIX . $prerequisite . '_skipped' );

		// Legacy option name compabitility.
		if ( 'ras_campaign' === $prerequisite && ! $skip && ! $updated ) {
			$updated = delete_option( Audience_Wizard::SKIP_CAMPAIGN_SETUP_OPTION );
		}

		// If all requirements are met or skipped, and RAS isn't yet enabled, enable it.
		if ( $skip && self::is_ras_ready_to_configure() && self::is_ras_campaign_configured( true ) && ! self::is_enabled() ) {
			self::update_setting( 'enabled', true );
		}

		return $updated;
	}

	/**
	 * Get the status of the prerequisites for enabling reader activation.
	 *
	 * @return array Array of prerequisites to complete.
	 */
	public static function get_prerequisites_status() {
		$prerequisites = [
			'terms_conditions' => [
				'active'      => self::is_terms_configured(),
				'label'       => __( 'Legal Pages', 'newspack-plugin' ),
				'description' => __( 'Displaying legal pages like Privacy Policy and Terms of Service on your site is recommended for allowing readers to register and access their account.', 'newspack-plugin' ),
				'help_url'    => 'https://help.newspack.com/engagement/audience-management-system/',
				'warning'     => __( 'Privacy policies that tell users how you collect and use their data are essential for running a  trustworthy website. While rules and regulations can differ by country, certain legal pages might be required by law.', 'newspack-plugin' ),
				'fields'      => [
					'terms_text' => [
						'label'       => __( 'Legal Pages Disclaimer Text', 'newspack-plugin' ),
						'description' => __( 'Legal pages disclaimer text to display on registration.', 'newspack-plugin' ),
					],
					'terms_url'  => [
						'label'       => __( 'Legal Pages URL', 'newspack-plugin' ),
						'description' => __( 'URL to the page containing the privacy policy or terms of service.', 'newspack-plugin' ),
					],
				],
				'skippable'   => true,
				'is_skipped'  => self::is_skipped( 'terms_conditions' ),
			],
			'esp'              => [
				'active'       => self::is_esp_configured(),
				'plugins'      => [
					'newspack-newsletters' => class_exists( '\Newspack_Newsletters' ),
				],
				'label'        => __( 'Email Service Provider (ESP)', 'newspack-plugin' ),
				'description'  => __( 'Connect to your ESP to register readers with their email addresses and send newsletters.', 'newspack-plugin' ),
				'instructions' => __( 'Connect to your email service provider (ESP) and enable at least one subscription list.', 'newspack-plugin' ),
				'help_url'     => 'https://help.newspack.com/engagement/audience-management-system/',
				'href'         => \admin_url( 'edit.php?post_type=newspack_nl_cpt&page=newspack-newsletters' ),
				'action_text'  => __( 'ESP settings' ),
			],
			'emails'           => [
				'active'      => self::is_transactional_email_configured(),
				'label'       => __( 'Transactional Emails', 'newspack-plugin' ),
				'description' => __( 'Your sender name and email address determines how readers find emails related to their account in their inbox. To customize the content of these emails, visit Advanced Settings below.', 'newspack-plugin' ),
				'help_url'    => 'https://help.newspack.com/engagement/audience-management-system/',
				'fields'      => [
					'sender_name'           => [
						'label'       => __( 'Sender Name', 'newspack-plugin' ),
						'description' => __( 'Name to use as the sender of transactional emails.', 'newspack-plugin' ),
					],
					'sender_email_address'  => [
						'label'       => __( 'Sender Email Address', 'newspack-plugin' ),
						'description' => __( 'Email address to use as the sender of transactional emails.', 'newspack-plugin' ),
					],
					'contact_email_address' => [
						'label'       => __( 'Contact Email Address', 'newspack-plugin' ),
						'description' => __( 'This email will be used as "Reply-To" for transactional emails as well.', 'newspack-plugin' ),
					],
				],
			],
			'recaptcha'        => [
				'active'       => self::is_recaptcha_enabled(),
				'label'        => __( 'reCAPTCHA', 'newspack-plugin' ),
				'description'  => __( 'Connecting to a Google reCAPTCHA account enables enhanced anti-spam for all Newspack sign-up blocks.', 'newspack-plugin' ),
				'instructions' => __( 'Enable reCAPTCHA and enter your account credentials.', 'newspack-plugin' ),
				'help_url'     => 'https://help.newspack.com/engagement/audience-management-system/',
				'href'         => \admin_url( '/admin.php?page=newspack-settings&scrollTo=newspack-settings-recaptcha' ),
				'action_text'  => __( 'reCAPTCHA settings' ),
				'skippable'    => true,
				'is_skipped'   => self::is_skipped( 'recaptcha' ),
			],
			'reader_revenue'   => [
				'active'       => self::is_reader_revenue_ready(),
				'plugins'      => self::get_reader_revenue_required_plugins(),
				'label'        => __( 'Reader Revenue', 'newspack-plugin' ),
				'description'  => __( 'Setting suggested donation amounts is required for enabling a streamlined donation experience.', 'newspack-plugin' ),
				'instructions' => __( 'Set platform to "Newspack" or "News Revenue Hub" and configure your default donation settings. If using News Revenue Hub, set an Organization ID and a Donor Landing Page in News Revenue Hub Settings.', 'newspack-plugin' ),
				'help_url'     => 'https://help.newspack.com/engagement/audience-management-system/',
				'href'         => \admin_url( '/admin.php?page=newspack-audience#/payment' ),
				'action_text'  => __( 'Reader Revenue settings' ),
			],
			'ras_campaign'     => [
				'active'         => self::is_ras_campaign_configured(),
				'plugins'        => [
					'newspack-popups' => class_exists( '\Newspack_Popups_Model' ),
				],
				'label'          => __( 'Audience Management Campaign', 'newspack-plugin' ),
				'description'    => __( 'Building a set of prompts with default segments and settings allows for an improved experience optimized for audience management.', 'newspack-plugin' ),
				'help_url'       => 'https://help.newspack.com/engagement/audience-management-system/',
				'href'           => self::is_ras_campaign_configured() ? admin_url( '/admin.php?page=newspack-audience-campaigns' ) : admin_url( '/admin.php?page=newspack-audience#/campaign' ),
				'action_enabled' => self::is_ras_ready_to_configure(),
				'action_text'    => __( 'Audience Management campaign', 'newspack-plugin' ),
				'disabled_text'  => __( 'Waiting for all settings to be ready', 'newspack-plugin' ),
				'skippable'      => true,
				'is_skipped'     => self::is_skipped( 'ras_campaign' ),
			],
		];

		return $prerequisites;
	}

	/**
	 * Whether reader activation features should be enabled.
	 *
	 * @return bool True if reader activation is enabled.
	 */
	public static function is_enabled() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			return true;
		}

		$is_enabled = (bool) \get_option( self::OPTIONS_PREFIX . 'enabled', false );

		/**
		 * Filters whether reader activation is enabled.
		 *
		 * @param bool $is_enabled Whether reader activation is enabled.
		 */
		return \apply_filters( 'newspack_reader_activation_enabled', $is_enabled );
	}

	/**
	 * Whether or not to render the Registration block front-end.
	 * This must be allowed to render before RAS is enabled in the context of previews.
	 *
	 * @return boolean
	 */
	public static function allow_reg_block_render() {
		if ( ! class_exists( '\Newspack_Popups' ) ) {
			return self::is_enabled();
		}

		// If RAS is not enabled yet, allow to render when previewing a campaign prompt.
		return self::is_enabled() || ( method_exists( '\Newspack_Popups', 'is_preview_request' ) && \Newspack_Popups::is_preview_request() );
	}

	/**
	 * Add auth intention email to login form defaults.
	 *
	 * @param array $defaults Login form defaults.
	 *
	 * @return array
	 */
	public static function add_auth_intention_to_login_form( $defaults ) {
		$email = self::get_auth_intention_value();
		if ( ! empty( $email ) ) {
			$defaults['label_username'] = __( 'Email address', 'newspack-plugin' );
			$defaults['value_username'] = $email;
		}
		return $defaults;
	}

	/**
	 * Clear the auth intention cookie.
	 */
	public static function clear_auth_intention_cookie() {
		/** This filter is documented in wp-includes/pluggable.php */
		if ( ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( self::AUTH_INTENTION_COOKIE, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
	}

	/**
	 * Set the auth intention cookie.
	 *
	 * @param string $email Email address.
	 */
	public static function set_auth_intention_cookie( $email ) {
		/** This filter is documented in wp-includes/pluggable.php */
		if ( ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}

		/**
		 * Filters the duration of the auth intention cookie expiration period.
		 *
		 * @param int    $length Duration of the expiration period in seconds.
		 * @param string $email  Email address.
		 */
		$expire = time() + \apply_filters( 'newspack_auth_intention_expiration', 30 * DAY_IN_SECONDS, $email );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( self::AUTH_INTENTION_COOKIE, $email, $expire, COOKIEPATH, COOKIE_DOMAIN, true );
	}

	/**
	 * Clear cookie that indicates the reader is authenticated.
	 */
	public static function clear_auth_reader_cookie() {
		/** This filter is documented in wp-includes/pluggable.php */
		if ( ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( self::AUTH_READER_COOKIE, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
	}

	/**
	 * Set cookie to indicate the reader has been authenticated.
	 *
	 * This cookie expiration doesn't matter, as it's intended to be read right
	 * after a frontend action that might have registered/authenticated a reader.
	 *
	 * Do not use this cookie for validation.
	 *
	 * @param \WP_User $user User object.
	 */
	public static function set_auth_reader_cookie( $user ) {
		/** This filter is documented in wp-includes/pluggable.php */
		if ( ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( self::AUTH_READER_COOKIE, $user->user_email, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, true );
	}

	/**
	 * Set the reader cookie on wp login.
	 *
	 * @param string   $user_login User login.
	 * @param \WP_User $user       User object.
	 */
	public static function login_set_reader_cookie( $user_login, $user ) {
		if ( self::is_user_reader( $user ) ) {
			self::set_auth_reader_cookie( $user );
		}
	}

	/**
	 * Get the auth intention value.
	 *
	 * @return string|null Email address or null if not set.
	 */
	public static function get_auth_intention_value() {
		$email_address = null;
		if ( isset( $_COOKIE[ self::AUTH_INTENTION_COOKIE ] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			$email_address = \sanitize_email( $_COOKIE[ self::AUTH_INTENTION_COOKIE ] );
		}
		/**
		 * Filters the session auth intention email address.
		 *
		 * @param string|null $email_address Email address or null if not set.
		 */
		return \apply_filters( 'newspack_auth_intention', $email_address );
	}

	/**
	 * Get the reader roles.
	 */
	public static function get_reader_roles() {
		/**
		 * Filters the roles that can determine if a user is a reader.
		 *
		 * @param string[] $roles Array of user roles.
		 */
		return \apply_filters( 'newspack_reader_user_roles', [ 'subscriber', 'customer' ] );
	}

	/**
	 * Whether the user is a reader.
	 *
	 * @param WP_User $user   User object.
	 * @param bool    $strict Whether to check if the user was created through reader registration. Default false.
	 *
	 * @return bool Whether the user is a reader.
	 */
	public static function is_user_reader( $user, $strict = false ) {
		$is_reader = (bool) \get_user_meta( $user->ID, self::READER, true );
		$user_data = \get_userdata( $user->ID );

		if ( false === $is_reader && false === $strict ) {
			$reader_roles = self::get_reader_roles();
			if ( ! empty( $reader_roles ) ) {
				$is_reader = ! empty( array_intersect( $reader_roles, $user_data->roles ) );
			}
		}

		/**
		 * Filters roles that restricts a user from being a reader.
		 *
		 * @param string[] $roles Array of user roles that restrict a user from being a reader.
		 */
		$restricted_roles = \apply_filters( 'newspack_reader_restricted_roles', [ 'administrator', 'editor' ] );
		if ( ! empty( $restricted_roles ) && $is_reader && ! empty( array_intersect( $restricted_roles, $user_data->roles ) ) ) {
			$is_reader = false;
		}

		/**
		 * Filters whether the user is a reader.
		 *
		 * @param bool     $is_reader Whether the user is a reader.
		 * @param \WP_User $user      User object.
		 */
		return (bool) \apply_filters( 'newspack_is_user_reader', $is_reader, $user );
	}

	/**
	 * Verify email address of a reader given the user.
	 *
	 * @param \WP_User|int $user_or_user_id User object.
	 *
	 * @return bool Whether the email address was verified.
	 */
	public static function set_reader_verified( $user_or_user_id ) {
		if ( $user_or_user_id instanceof \WP_User ) {
			$user = $user_or_user_id;
		} elseif ( absint( $user_or_user_id ) ) {
			$user = get_user_by( 'id', $user_or_user_id );
		}

		if ( ! isset( $user ) || ! $user || self::is_reader_verified( $user ) ) {
			return false;
		}

		/** Should not verify email if user is not a reader. */
		if ( ! self::is_user_reader( $user ) ) {
			return false;
		}

		\update_user_meta( $user->ID, self::EMAIL_VERIFIED, true );

		WooCommerce_Connection::add_wc_notice( self::get_reader_activation_labels( 'verify' ), 'success' );

		/**
		 * Upon verification we want to destroy existing sessions to prevent a bad
		 * actor having originated the account creation from accessing the, now
		 * verified, account.
		 *
		 * If the verification is for the current user, we destroy other sessions.
		 */
		if ( get_current_user_id() === $user->ID ) {
			\wp_destroy_other_sessions();
		} else {
			$session_tokens = \WP_Session_Tokens::get_instance( $user->ID );
			$session_tokens->destroy_all();
		}

		/**
		 * Fires after a reader's email address is verified.
		 *
		 * @param \WP_User $user User object.
		 */
		do_action( 'newspack_reader_verified', $user );

		return true;
	}

	/**
	 * Remove "without password" meta from user.
	 *
	 * @param \WP_User|int $user_or_user_id User object or user ID.
	 *
	 * @return bool Whether the meta was removed.
	 */
	public static function set_reader_has_password( $user_or_user_id ) {
		if ( $user_or_user_id instanceof \WP_User ) {
			$user = $user_or_user_id;
		} elseif ( absint( $user_or_user_id ) ) {
			$user = get_user_by( 'id', $user_or_user_id );
		}

		if ( ! isset( $user ) || ! $user ) {
			return false;
		}

		delete_user_meta( $user->ID, self::WITHOUT_PASSWORD );
		return true;
	}

	/**
	 * Conditionally remove "without password" meta from user.
	 *
	 * If the a password is being set via user profile update,
	 * And a previous password was not set, we remove the meta.
	 *
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data Old user data.
	 * @param array    $user_data     User data.
	 */
	public static function maybe_set_reader_has_password( $user_id, $old_user_data, $user_data ) {
		if ( ! self::is_user_reader( $old_user_data ) ) {
			return;
		}

		$old_password = $old_user_data->user_pass;
		$new_password = isset( $user_data['user_pass'] ) ? $user_data['user_pass'] : '';
		if ( ! empty( $new_password ) && $old_password !== $new_password ) {
			self::set_reader_has_password( $user_id );
		}
	}

	/**
	 * Whether the reader hasn't set their password.
	 *
	 * @param \WP_User|int $user_or_user_id User object or user ID.
	 *
	 * @return bool|WP_Error Whether the reader hasn't set its password or error.
	 */
	public static function is_reader_without_password( $user_or_user_id ) {
		if ( $user_or_user_id instanceof \WP_User ) {
			$user = $user_or_user_id;
		} elseif ( absint( $user_or_user_id ) ) {
			$user = get_user_by( 'id', $user_or_user_id );
		}

		if ( ! isset( $user ) || ! $user || ! self::is_user_reader( $user ) ) {
			return new \WP_Error( 'newspack_is_reader_without_password', __( 'Invalid user.', 'newspack-plugin' ) );
		}

		/**
		 * Filters whether the user should be considered a reader without a password.
		 *
		 * @param bool $is_reader_without_password True if the reader has not set a password.
		 * @param int  $user_id                    User ID.
		 * @return bool
		 */
		return (bool) apply_filters( 'newpack_reader_activation_reader_is_without_password', \get_user_meta( $user->ID, self::WITHOUT_PASSWORD, false ), $user->ID );
	}

	/**
	 * Set custom auth cookie expiration for readers.
	 *
	 * @param int  $length   Duration of the expiration period in seconds.
	 * @param int  $user_id  User ID.
	 * @param bool $remember Whether to remember the user login. Default false.
	 *
	 * @return int Duration of the expiration period in seconds.
	 */
	public static function auth_cookie_expiration( $length, $user_id, $remember ) {
		if ( true === $remember ) {
			$user = \get_user_by( 'id', $user_id );
			if ( $user && self::is_user_reader( $user ) ) {
				$length = YEAR_IN_SECONDS;
			}
		}
		return $length;
	}

	/**
	 * Get a BEM formatted class name.
	 *
	 * @param string ...$parts The parts of the class name.
	 *
	 * @return string The BEM formatted class name.
	 */
	private static function get_element_class_name( ...$parts ) {
		if ( is_array( $parts[0] ) ) {
			$parts = $parts[0];
		}
		$parts = array_filter( $parts );
		array_unshift( $parts, 'newspack-reader' );
		return empty( $parts ) ? '' : implode( '__', $parts );
	}

	/**
	 * Setup nav menu hooks.
	 */
	public static function setup_nav_menu() {
		// Not checking if the whole WC suite is active (self::is_woocommerce_active()),
		// because only the main WooCommerce plugin is actually required for this to work.
		if ( ! self::get_setting( 'enabled_account_link' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$locations = self::get_setting( 'account_link_menu_locations' );
		$self      = new self();

		/** Always have location enabled for account link. */
		\add_filter(
			'has_nav_menu',
			function( $has_nav_menu, $location ) use ( $locations ) {
				if ( in_array( $location, $locations, true ) ) {
					$has_nav_menu = true;
				}
				return $has_nav_menu;
			},
			10,
			2
		);

		/** Fallback location to always print nav menu args */
		\add_filter(
			'wp_nav_menu_args',
			function( $args ) use ( $self, $locations ) {
				if ( in_array( $args['theme_location'], $locations, true ) ) {
					$args['fallback_cb'] = function( $args ) use ( $self ) {
						echo $self->nav_menu_items( '', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					};
				}
				return $args;
			}
		);

		/** Add as menu item */
		\add_filter( 'wp_nav_menu_items', [ __CLASS__, 'nav_menu_items' ], 20, 2 );

		/** Add mobile icon */
		\add_action(
			'newspack_header_after_mobile_toggle',
			function() use ( $self ) {
				?>
				<span class="<?php echo \esc_attr( self::get_element_class_name( 'account-link', 'mobile' ) ); ?>">
					<?php echo $self->get_account_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<?php
			}
		);
	}

	/**
	 * Setup nav menu items for reader account access.
	 *
	 * @param string   $output The HTML for the menu items.
	 * @param stdClass $args   An object containing wp_nav_menu() arguments.
	 *
	 * @return string The HTML list content for the menu items.
	 */
	public static function nav_menu_items( $output, $args = [] ) {
		$args      = (object) $args;
		$locations = self::get_setting( 'account_link_menu_locations' );

		/** Do not alter items for authenticated non-readers */
		if ( \is_user_logged_in() && ! self::is_user_reader( \wp_get_current_user() ) && ! \is_customize_preview() ) {
			return $output;
		}

		/**
		 * Menu locations to add the account menu items to.
		 */
		if ( ! in_array( $args->theme_location, $locations, true ) ) {
			return $output;
		}

		$link = self::get_account_link();
		if ( empty( $link ) ) {
			return $output;
		}

		$item  = '<li class="menu-item">';
		$item .= $link;
		$item .= '</li>';

		if ( empty( $output ) ) {
			$menu_class = sprintf( '%s %s', $args->menu_class, self::get_element_class_name( 'account-menu' ) );
			$output     = sprintf( $args->items_wrap ?? '<ul id="%1$s" class="%2$s">%3$s</ul>', $args->menu_id, $menu_class, $item );
		} else {
			$output = $output . $item;
		}
		return $output;
	}

	/**
	 * Get account link.
	 *
	 * @return string Account link HTML or empty string.
	 */
	private static function get_account_link() {
		$account_url = '';
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$account_url = \wc_get_account_endpoint_url( 'dashboard' );
		}

		/** Do not render link for authenticated readers if account page doesn't exist. */
		if ( empty( $account_url ) && \is_user_logged_in() ) {
			return '';
		}

		$class = function( ...$parts ) {
			array_unshift( $parts, 'account-link' );
			return self::get_element_class_name( $parts );
		};

		$labels = self::get_reader_activation_labels( 'account_link' );
		$label  = \is_user_logged_in() ? 'signedin' : 'signedout';
		$href   = \is_user_logged_in() ? $account_url : '#';

		$link  = '<a class="' . \esc_attr( $class() ) . '" data-labels="' . \esc_attr( htmlspecialchars( \wp_json_encode( $labels ), ENT_QUOTES, 'UTF-8' ) ) . '" href="' . \esc_url_raw( $href ) . '" data-newspack-reader-account-link>';
		$link .= '<span class="' . \esc_attr( $class( 'icon' ) ) . '">';
		$link .= \Newspack\Newspack_UI_Icons::get_svg( 'account' );
		$link .= '</span>';
		$link .= '<span class="' . \esc_attr( $class( 'label' ) ) . '">' . \esc_html( $labels[ $label ] ) . '</span>';
		$link .= '</a>';

		/**
		 * Filters the HTML for the reader account link.
		 *
		 * @param string $link HTML for the reader account link.
		 */
		return apply_filters( 'newspack_reader_account_link', $link );
	}

	/**
	 * Render a honeypot field to guard against bot form submissions. Note that
	 * this field is named `email` to hopefully catch more bots who might be
	 * looking for such fields, where as the "real" field is named "npe".
	 *
	 * Not rendered if reCAPTCHA is enabled as it's a superior spam protection.
	 *
	 * @param string $placeholder Placeholder text to render in the field.
	 */
	public static function render_honeypot_field( $placeholder = '' ) {
		if ( Recaptcha::can_use_captcha() ) {
			return;
		}

		if ( empty( $placeholder ) ) {
			$placeholder = __( 'Enter your email address', 'newspack-plugin' );
		}
		?>
		<input class="nphp" tabindex="-1" aria-hidden="true" name="email" type="email" autocomplete="off" placeholder="<?php echo \esc_attr( $placeholder ); ?>" />
		<?php
	}

	/**
	 * Renders reader authentication form.
	 *
	 * @param boolean $in_modal Whether the form is rendiner in a modal; defaults to true.
	 */
	public static function render_auth_form( $in_modal = true ) {
		/**
		 * Filters whether to render reader auth form.
		 *
		 * @param bool $should_render Whether to render reader auth form.
		 */
		if ( ! apply_filters( 'newspack_reader_activation_should_render_auth', true ) ) {
			return;
		}
		// No need to render if RAS is disabled and not a preview request.
		if ( ! self::allow_reg_block_render() ) {
			return;
		}

		$message = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['reader_authenticated'] ) && isset( $_GET['message'] ) ) {
			$message = \sanitize_text_field( $_GET['message'] );
		}
		// phpcs:enable

		$referer           = \wp_parse_url( \wp_get_referer() );
		$labels            = self::get_reader_activation_labels( 'signin' );
		// If there is a redirect parameter, use it as the auth callback URL.
		$auth_callback_url = filter_input( INPUT_GET, 'redirect', FILTER_SANITIZE_URL ) ?? '#';
		if ( '#' === $auth_callback_url ) {
			if ( Renewal::is_subscriptions_page() ) {
				// If we are on the subscriptions page, set the auth callback URL to the subscriptions page.
				$auth_callback_url = Renewal::get_subscriptions_url();
			} elseif ( WooCommerce_My_Account::is_myaccount_url() ) {
				$params = [];
				// If we are using one of our my account params, reattach the param to the my account URL.
				foreach ( WooCommerce_My_Account::ALLOWED_PARAMS as $param ) {
					$value = $_GET[ $param ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
					if ( $value ) {
						$params[ $param ] = $value;
					}
				}
				$auth_callback_url = add_query_arg( $params, \wc_get_page_permalink( 'myaccount' ) );
			} elseif ( function_exists( 'wc_get_page_permalink' ) && function_exists( 'is_account_page' ) && \is_account_page() ) {
				// If we are already on the my account page, set the my account URL so the page reloads on submit.
				$auth_callback_url = \wc_get_page_permalink( 'myaccount' );
			}
		}
		?>
		<div class="newspack-ui newspack-reader-auth">
			<?php if ( ! $in_modal ) { ?>
				<h2 data-action="signin"><?php echo wp_kses_post( self::get_reader_activation_labels( 'title' ) ); ?></h2>
				<h2 data-action="register"><?php echo wp_kses_post( self::get_reader_activation_labels( 'create_account' ) ); ?></h2>
			<?php } ?>
			<div class="newspack-ui__box newspack-ui__box--success newspack-ui__box--text-center" data-action="success">
				<span class="newspack-ui__icon newspack-ui__icon--success">
					<?php \Newspack\Newspack_UI_Icons::print_svg( 'check' ); ?>
				</span>
				<p>
					<strong class="success-title"></strong>
				</p>
				<p class="newspack-ui__font--xs success-description"></p>
			</div>
			<form method="post" target="_top" data-newspack-recaptcha="newspack_register">
				<div data-action="signin register">
					<?php self::render_third_party_auth(); ?>
				</div>
				<input type="hidden" name="<?php echo \esc_attr( self::AUTH_FORM_ACTION ); ?>" value="1" />
				<?php if ( ! empty( $referer['path'] ) ) : ?>
					<input type="hidden" name="referer" value="<?php echo \esc_url( $referer['path'] ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="action" />
				<p data-action="otp">
					<label><?php echo esc_html( $labels['otp_title'] ); ?></label>
				</p>
				<div data-action="signin register">
					<p>
						<label for="newspack-reader-auth-email-input"><?php esc_html_e( 'Email address', 'newspack-plugin' ); ?></label>
						<input id="newspack-reader-auth-email-input" name="npe" type="email" placeholder="<?php \esc_attr_e( 'Your email address', 'newspack-plugin' ); ?>" />
					</p>
					<?php self::render_honeypot_field(); ?>
				</div>
				<p class="newspack-ui__code-input" data-action="otp">
					<input name="otp_code" type="text" maxlength="<?php echo \esc_attr( Magic_Link::OTP_LENGTH ); ?>" placeholder="<?php \esc_attr_e( '6-digit code', 'newspack-plugin' ); ?>" />
				</p>
				<p data-action="pwd">
					<label for="newspack-reader-auth-password-input"><?php esc_html_e( 'Enter your password', 'newspack-plugin' ); ?></label>
					<input id="newspack-reader-auth-password-input" name="password" type="password" />
				</p>
				<div class="response-container">
					<div class="response">
						<?php if ( ! empty( $message ) ) : ?>
							<p><?php echo \esc_html( $message ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<p data-action="otp">
					<?php
					echo wp_kses_post(
						sprintf(
							// Translators: %s is the email address.
							__( 'Sign in by entering the code we sent to %s, or clicking the magic link in the email.', 'newspack-plugin' ),
							'<strong class="email-address"></strong>'
						)
					);
					?>
				</p>
				<button type="submit" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary" data-action="register signin pwd otp"><?php echo \esc_html( $labels['continue'] ); ?></button>
				<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary" data-action="otp" data-resend-code><?php echo \esc_html( $labels['resend_code'] ); ?></button>
				<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary" data-action="pwd" data-send-code><?php echo \esc_html( $labels['otp'] ); ?></button>
				<a class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary" data-action="pwd" href="<?php echo \esc_url( \wp_lostpassword_url() ); ?>"><?php echo \esc_html( $labels['forgot_password'] ); ?></a>
				<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--ghost newspack-ui__last-child" data-action="signin" data-set-action="register"><?php echo \esc_html( $labels['create_account'] ); ?></button>
				<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--ghost newspack-ui__last-child" data-action="register" data-set-action="signin"><?php echo \esc_html( $labels['register'] ); ?></button>
				<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--ghost newspack-ui__last-child" data-action="otp pwd"  data-back><?php echo \esc_html( $labels['go_back'] ); ?></button>
			</form>
			<a href="<?php echo \esc_url( $auth_callback_url ); ?>" class="auth-callback newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary" data-action="success"><?php echo \esc_html( $labels['continue'] ); ?></a>
			<a href="#" class="set-password newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary" data-action="success"><?php echo \esc_html( $labels['set_password'] ); ?></a>
		</div>
		<?php
	}

	/**
	 * Renders reader authentication modal.
	 */
	public static function render_auth_modal() {
		/**
		 * Filters whether to render reader auth form.
		 *
		 * @param bool $should_render Whether to render reader auth form.
		 */
		if ( ! apply_filters( 'newspack_reader_activation_should_render_auth', true ) ) {
			return;
		}
		// No need to render if RAS is disabled and not a preview request.
		if ( ! self::allow_reg_block_render() ) {
			return;
		}

		$terms = self::get_auth_footer();
		$label = self::get_reader_activation_labels( 'title' );
		?>
		<div class="newspack-ui newspack-ui__modal-container newspack-reader-auth-modal">
			<div class="newspack-ui__modal-container__overlay"></div>
			<div class="newspack-ui__modal newspack-ui__modal--small" role="dialog" aria-modal="true" aria-labelledby="newspack-reader-auth-modal-label">
				<div class="newspack-ui__modal__header">
					<h2 id="newspack-reader-auth-modal-label"><?php echo \esc_html( $label ); ?></h2>
					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php \Newspack\Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</div>
				<div class="newspack-ui__modal__content">
					<?php self::render_auth_form(); ?>
				</div>
				<?php if ( ! empty( $terms ) ) : ?>
					<footer class="newspack-ui__modal__footer" data-action="signin register">
						<p>
							<?php echo wp_kses_post( trim( $terms ) ); ?>
						</p>
					</footer>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Fetch HTML for the post-checkout newsletter signup modal.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_render_newsletters_signup_form( $request ) {
		ob_start();
		self::render_newsletters_signup_modal( $request['email_address'] );
		$html = trim( ob_get_clean() );
		return new \WP_REST_Response( [ 'html' => $html ] );
	}

	/**
	 * Renders newsletters signup form.
	 *
	 * @param string $email_address     Email address.
	 * @param array  $newsletters_lists Array of newsletters lists.
	 * @param int    $default_list_size Default number of lists to show.
	 *
	 * @return void
	 */
	private static function render_newsletters_signup_form( $email_address, $newsletters_lists, $default_list_size = 2 ) {
		$loop_index = 0;
		?>
			<div class="newspack-ui newspack-newsletters-signup">
				<form method="post" target="_top">
					<input type="hidden" name="<?php echo \esc_attr( self::NEWSLETTERS_SIGNUP_FORM_ACTION ); ?>" value="1" />
					<input type="hidden" name="email_address" value="<?php echo esc_attr( $email_address ); ?>" />

					<div class="newsletter-list-container" data-list-default-size="<?php echo esc_attr( $default_list_size ); ?>">
					<?php
					foreach ( $newsletters_lists as $list ) {
						$checkbox_id = sprintf( 'newspack-plugin-list-%s', $list['id'] );
						$is_hidden = $loop_index <= $default_list_size ? '' : 'hidden';
						$loop_index++;
						?>
						<label class="newspack-ui__input-card <?php echo esc_attr( $is_hidden ); ?>" for="<?php echo \esc_attr( $checkbox_id ); ?>">
							<input
								type="checkbox"
								name="lists[]"
								value="<?php echo \esc_attr( $list['id'] ); ?>"
								id="<?php echo \esc_attr( $checkbox_id ); ?>"
								<?php
								if ( isset( $list['checked'] ) && $list['checked'] ) {
									echo 'checked';
								}
								?>
							>
							<strong><?php echo \esc_html( $list['title'] ); ?></strong>
							<?php if ( ! empty( $list['description'] ) ) : ?>
								<span class="newspack-ui__helper-text"><?php echo \esc_html( $list['description'] ); ?></span>
							<?php endif; ?>
						</label>
						<?php
					}
					?>
					</div>

					<?php if ( count( $newsletters_lists ) > $default_list_size ) : ?>
						<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary see-all-button">
							<span><?php esc_html_e( 'See all', 'newspack-plugin' ); ?></span>
							<?php \Newspack\Newspack_UI_Icons::print_svg( 'arrow-right' ); ?>
						</button>
					<?php endif; ?>
					<button type="submit" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary"><?php echo \esc_html( self::get_reader_activation_labels( 'newsletters_continue' ) ); ?></button>
				</form>
			</div>
		<?php
	}

	/**
	 * Renders the newsletter signup modal.
	 *
	 * @param string $email_address Email address. Optional, defaults to the logged-in reader's email address.
	 */
	public static function render_newsletters_signup_modal( $email_address = '' ) {
		if ( ! self::is_newsletters_signup_available() ) {
			return;
		}
		if ( ! is_email( $email_address ) ) {
			$email_address = self::get_logged_in_reader_email_address();
		}
		$newsletters_lists = self::get_post_checkout_newsletter_lists( $email_address );
		if ( empty( $newsletters_lists ) ) {
			return;
		}
		$newsletter_list_initial_size = self::get_newsletters_list_initial_size();
		?>
		<div class="newspack-ui newspack-ui__modal-container newspack-newsletters-signup-modal">
			<div class="newspack-ui__modal-container__overlay"></div>
			<div class="newspack-ui__modal newspack-ui__modal--small">
				<div class="newspack-ui__modal__header">
					<h2><?php echo \esc_html( self::get_reader_activation_labels( 'newsletters_title' ) ); ?></h2>
					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
							<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
						</svg>
					</button>
				</div>
				<div class="newspack-ui__modal__content">
					<p class="newspack-ui__font--xs details">
						<?php echo \esc_html( self::get_reader_activation_labels( 'newsletters_details' ) ); ?>
					</p>
					<p class="newspack-ui__font--xs newspack-ui__color-text-gray recipient">
						<?php echo esc_html( __( 'Sending to: ', 'newspack-plugin' ) ); ?>
						<span class="email">
							<?php echo esc_html( $email_address ); ?>
						</span>
					</p>
					<?php self::render_newsletters_signup_form( $email_address, $newsletters_lists, $newsletter_list_initial_size ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle newsletters signup submission.
	 *
	 * @return void|WP_Error
	 */
	public static function newsletters_signup() {
		if ( ! self::is_newsletters_signup_available() ) {
			wp_die();
		}

		$action = filter_input( INPUT_POST, self::NEWSLETTERS_SIGNUP_FORM_ACTION, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $action ) {
			wp_die();
		}

		$email_address = filter_input( INPUT_POST, 'email_address', FILTER_SANITIZE_EMAIL );
		if ( empty( $email_address ) ) {
			return new \WP_Error( 'invalid_email_address', __( 'Invalid email address.', 'newspack-plugin' ) );
		}

		$lists = filter_input( INPUT_POST, 'lists', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );
		if ( empty( $lists ) ) {
			return new \WP_Error( 'no_lists_selected', __( 'No lists selected.', 'newspack-plugin' ) );
		}

		$result = \Newspack_Newsletters_Contacts::subscribe(
			[
				'email'    => $email_address,
				'metadata' => [
					'current_page_url'                => home_url( add_query_arg( array(), \wp_get_referer() ) ),
					// Right now the newsletters signup modal flow only applies to post checkout.
					'newsletters_subscription_method' => 'post-checkout',
				],
			],
			$lists,
			true // Async.
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		wp_die();
	}

	/**
	 * Should post-checkout newsletter signup be available?
	 */
	private static function is_newsletters_signup_available() {
		return (bool) self::get_setting( 'use_custom_lists' );
	}

	/**
	 * Get the authentication form footer text.
	 *
	 * @return string The authentication form footer text.
	 */
	public static function get_auth_footer() {
		$terms_text = self::get_setting( 'terms_text' );
		$terms_url  = self::get_setting( 'terms_url' );
		$terms      = trim( $terms_text ? $terms_text : '' );
		if ( ! empty( $terms ) ) {
			if ( $terms_url ) {
				$terms = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $terms_url, $terms_text );
			}
			if ( substr( trim( $terms_text ), -1 ) !== '.' ) {
				$terms .= '.';
			}
		}
		if ( Recaptcha::can_use_captcha() ) {
			$terms .= ' ' . Recaptcha::get_terms_text();
		}
		return $terms;
	}

	/**
	 * Send the auth form response to the client.
	 *
	 * @param array|WP_Error $data         The response to send to the client.
	 * @param string         $message      Optional custom message.
	 */
	private static function send_auth_form_response( $data = [], $message = false ) {
		$is_error = \is_wp_error( $data );
		if ( empty( $message ) ) {
			$labels  = self::get_reader_activation_labels( 'signin' );
			$message = $is_error ? $data->get_error_message() : $labels['success_message'];
		}
		\wp_send_json( compact( 'message', 'data' ), \is_wp_error( $data ) ? 400 : 200 );
	}

	/**
	 * Render newsletter subscription lists' form input.
	 *
	 * @param array[] $lists   {
	 *   List config keyed by their ID.
	 *
	 *   @type string $title       List title.
	 *   @type string $description List description.
	 * }
	 * @param array   $checked List IDs to pre-select.
	 * @param array   $config  {
	 *   Configuration options.
	 *
	 *   @type string  $title            Optional title to display above the list.
	 *   @type string  $name             Name of the input. Default is lists.
	 *   @type string  $single_label     Label for the input when only one list is present. Default is "Subscribe to our newsletter".
	 *   @type boolean $show_description Whether to display the list description. Default is true.
	 * }
	 */
	public static function render_subscription_lists_inputs( $lists = [], $checked = [], $config = [] ) {
		$label = self::get_reader_activation_labels( 'newsletters_cta' );
		$config = \wp_parse_args(
			$config,
			[
				'title'            => '',
				'name'             => 'lists',
				'single_label'     => $label,
				'show_description' => true,
			]
		);

		if ( empty( $lists ) ) {
			$lists = self::get_registration_newsletter_lists();
		}

		/**
		 * Filter the available newsletter subscription lists in the Registration form.
		 *
		 * @param array[] $lists List config keyed by their ID.
		 */
		$lists = apply_filters( 'newspack_auth_form_newsletters_lists', $lists );

		if ( empty( $lists ) || is_wp_error( $lists ) ) {
			return;
		}

		$id = \wp_rand( 0, 99999 );

		$class = function( ...$parts ) {
			array_unshift( $parts, 'lists' );
			return self::get_element_class_name( $parts );
		};

		$checked_map = array_flip( $checked );
		?>
			<?php if ( 1 < count( $lists ) && ! empty( $config['title'] ) ) : ?>
				<h3 class="screen-reader-text"><?php echo \esc_html( $config['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( ! $config['show_description'] ) : ?>
				<div class="newspack-ui__box newspack-ui__box--border">
			<?php endif; ?>
				<?php
				foreach ( $lists as $list_id => $list ) :
					$checkbox_id = sprintf( 'newspack-%s-list-checkbox-%s', $id, $list_id );
					?>
					<label class="newspack-ui__input-card" for="<?php echo \esc_attr( $checkbox_id ); ?>">
						<input
							type="checkbox"
							name="<?php echo \esc_attr( $config['name'] ); ?>[]"
							value="<?php echo \esc_attr( $list_id ); ?>"
							id="<?php echo \esc_attr( $checkbox_id ); ?>"
							<?php if ( isset( $checked_map[ $list_id ] ) ) : ?>
								checked
							<?php endif; ?>
						/>
						<strong>
							<?php
							if ( 1 === count( $lists ) ) {
								echo \wp_kses_post( $config['single_label'] );
							} else {
								echo \esc_html( $list['title'] );
							}
							?>
						</strong>
						<?php if ( $config['show_description'] ) : ?>
							<span class="newspack-ui__helper-text"><?php echo \esc_html( $list['description'] ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			<?php if ( ! $config['show_description'] ) : ?>
				</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * Render third party auth buttons for an authentication form.
	 *
	 * If Google is connected to the site, this can be overridden by setting the `NEWSPACK_DISABLE_GOOGLE_OAUTH` environment constant.
	 */
	public static function render_third_party_auth() {
		if ( ! Google_OAuth::is_oauth_configured() ) {
			return;
		}
		?>
		<div class="newspack-ui">
			<button type="button" class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary newspack-ui__button--google-oauth">
				<?php \Newspack\Newspack_UI_Icons::print_svg( 'google' ); ?>
				<?php echo \esc_html__( 'Sign in with Google', 'newspack-plugin' ); ?>
			</button>
			<div class="newspack-ui__word-divider">
				<?php echo \esc_html__( 'Or', 'newspack-plugin' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * If rendering the WooCommerce login form template, trick it into rendering nothing
	 * and replace it with our own login form.
	 *
	 * @param string $template Full template path.
	 * @param string $template_name Template name.
	 *
	 * @return string Filtered template path.
	 */
	public static function replace_woocommerce_auth_form( $template, $template_name ) {
		// Allow template rewriting for `woocommerce-memberships-for-teams` plugin. This includes
		// a link to join a team.
		if ( is_int( stripos( $template, 'woocommerce-memberships-for-teams' ) ) ) {
			return $template;
		}
		if ( 'myaccount/form-login.php' === $template_name ) {
			$template = dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-activation/login-form.php';
		}

		return $template;
	}

	/**
	 * Process reader authentication form.
	 */
	public static function process_auth_form() {
		if ( \is_user_logged_in() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// Nonce not required for an authentication attempt.
		if ( ! isset( $_POST[ self::AUTH_FORM_ACTION ] ) ) {
			return;
		}

		$action           = isset( $_POST['action'] ) ? \sanitize_text_field( $_POST['action'] ) : '';
		$referer          = isset( $_POST['referer'] ) ? \sanitize_text_field( $_POST['referer'] ) : '';
		$current_page_url = \wp_parse_url( \wp_get_raw_referer() ); // Referer is the current page URL because the form is submitted via AJAX.
		$email            = isset( $_POST['npe'] ) ? \sanitize_email( $_POST['npe'] ) : '';
		$password         = isset( $_POST['password'] ) ? \sanitize_text_field( $_POST['password'] ) : '';
		$lists            = isset( $_POST['lists'] ) ? array_map( 'sanitize_text_field', $_POST['lists'] ) : [];
		$honeypot         = isset( $_POST['email'] ) ? \sanitize_text_field( $_POST['email'] ) : '';
		$redirect_url     = isset( $_POST['redirect_url'] ) ? \esc_url_raw( $_POST['redirect_url'] ) : '';
		// phpcs:enable

		if ( ! empty( $current_page_url['path'] ) ) {
			$current_page_url = \esc_url( \home_url( $current_page_url['path'] ) );
		}

		$redirect = ! empty( $redirect_url ) ? $redirect_url : $current_page_url;

		// Honeypot trap.
		if ( ! empty( $honeypot ) ) {
			return self::send_auth_form_response(
				[
					'email'         => $honeypot,
					'authenticated' => 1,
				]
			);
		}

		// reCAPTCHA test on account registration only.
		$should_verify_captcha = apply_filters( 'newspack_recaptcha_verify_captcha', Recaptcha::can_use_captcha(), $current_page_url, 'auth_modal' );
		if ( 'register' === $action && $should_verify_captcha ) {
			$captcha_result = Recaptcha::verify_captcha();
			if ( \is_wp_error( $captcha_result ) ) {
				return self::send_auth_form_response( $captcha_result );
			}
		}

		if ( ! in_array( $action, self::AUTH_FORM_OPTIONS, true ) ) {
			return self::send_auth_form_response( new \WP_Error( 'invalid_request', __( 'Invalid request.', 'newspack-plugin' ) ) );
		}

		if ( empty( $email ) ) {
			return self::send_auth_form_response( new \WP_Error( 'invalid_email', __( 'You must enter a valid email address.', 'newspack-plugin' ) ) );
		}

		self::set_auth_intention_cookie( $email );

		$user = \get_user_by( 'email', $email );
		if ( ! $user && 'register' !== $action ) {
			return self::send_auth_form_response( new \WP_Error( 'unauthorized', wp_kses_post( __( 'Account not found. <a data-set-action="register" href="#register_modal">Create an account</a> instead?', 'newspack-plugin' ) ) ) );
		}

		if ( $user && ! self::is_user_reader( $user ) ) {
			$message = 'register' === $action ? __( 'An account was already registered with this email. Please check your inbox for an authentication link.', 'newspack-plugin' ) : wp_kses_post( __( 'Account not found. <a data-set-action="register" href="#register_modal">Create an account</a> instead?', 'newspack-plugin' ) );
			$sent = self::send_non_reader_login_reminder( $user );
			return self::send_auth_form_response( new \WP_Error( 'unauthorized', \is_wp_error( $sent ) ? $sent->get_error_message() : $message ) );
		}

		$payload = [
			'email'         => $email,
			'authenticated' => 0,
		];

		$magic_link_label = self::get_reader_activation_labels( 'magic_link' );
		$metadata         = [];
		$message          = false;

		switch ( $action ) {
			case 'signin':
				if ( Magic_Link::has_active_token( $user ) ) {
					$payload['action'] = 'otp';
					break;
				}
				if ( self::is_reader_without_password( $user ) ) {
					$sent = Magic_Link::send_email( $user, $redirect );
					if ( true !== $sent ) {
						return self::send_auth_form_response( new \WP_Error( 'unauthorized', \is_wp_error( $sent ) ? $sent->get_error_message() : __( 'We encountered an error sending an authentication link. Please try again.', 'newspack-plugin' ) ) );
					}
					$payload['action'] = 'otp';
					break;
				} else {
					$payload['action'] = 'pwd';
					break;
				}
			case 'pwd':
				if ( empty( $password ) ) {
					return self::send_auth_form_response( new \WP_Error( 'invalid_password', __( 'Password not recognized, try again.', 'newspack-plugin' ) ) );
				}
				$user = \wp_authenticate( $user->user_login, $password );
				if ( \is_wp_error( $user ) ) {
					return self::send_auth_form_response( new \WP_Error( 'unauthorized', __( 'Password not recognized, try again.', 'newspack-plugin' ) ) );
				}
				$authenticated = self::set_current_reader( $user->ID );
				$payload['authenticated'] = \is_wp_error( $authenticated ) ? 0 : 1;
				$payload['existing_user'] = \is_wp_error( $authenticated ) ? 0 : 1;
				$metadata['login_method'] = 'auth-form-password';
				break;
			case 'link':
				$sent = Magic_Link::send_email( $user, $redirect );
				if ( true !== $sent ) {
					return self::send_auth_form_response( new \WP_Error( 'unauthorized', \is_wp_error( $sent ) ? $sent->get_error_message() : __( 'We encountered an error sending an authentication link. Please try again.', 'newspack-plugin' ) ) );
				}
				$message = $magic_link_label;
				break;
			case 'register':
				$metadata['registration_method'] = 'auth-form';
				if ( ! empty( $lists ) ) {
					$metadata['lists'] = $lists;
				}
				if ( ! empty( $referer ) ) {
					$metadata['referer'] = \esc_url( $referer );
				}
				if ( ! empty( $current_page_url ) ) {
					$metadata['current_page_url'] = $current_page_url;
				}

				$user_id = self::register_reader( $email, '', true, $metadata );
				if ( false === $user_id ) {
					return self::send_auth_form_response(
						new \WP_Error( 'unauthorized', self::is_reader_without_password( $user ) ? __( 'An account was already registered with this email. Please check your inbox for an authentication link.', 'newspack-plugin' ) : __( 'An account was already registered with this email. Please sign in to continue.', 'newspack-plugin' ) )
					);
				}
				if ( \is_wp_error( $user_id ) ) {
					return self::send_auth_form_response(
						new \WP_Error( 'unauthorized', __( 'Unable to register your account. Try a different email.', 'newspack-plugin' ) )
					);
				}

				$password_url_arg        = WooCommerce_My_Account::RESET_PASSWORD_URL_PARAM;
				$nonce                   = wp_create_nonce( $password_url_arg );
				$payload['password_url'] = add_query_arg(
					$password_url_arg,
					$nonce,
					function_exists( 'wc_get_account_endpoint_url' ) ? \wc_get_account_endpoint_url( 'edit-account' ) : home_url()
				);

				// If we are on the my account page, add a redirect to the site's home page.
				if ( function_exists( 'is_account_page' ) && is_account_page() ) {
					$payload['redirect_to'] = \home_url();
				}

				$payload['registered']    = 1;
				$payload['authenticated'] = 1;
				break;
		}

		/**
		 * Filters the metadata to be saved for a reader going through the auth modal.
		 *
		 * @param array  $metadata Metadata.
		 * @param string $email    Email address of the reader.
		 */
		$metadata = apply_filters( 'newspack_auth_form_metadata', $metadata, $email );
		if ( isset( $metadata['gate_post_id'] ) ) {
			$payload['gate_post_id'] = $metadata['gate_post_id'];
		}
		if ( isset( $metadata['newspack_popup_id'] ) ) {
			$payload['newspack_popup_id'] = $metadata['newspack_popup_id'];
		}
		$payload['metadata'] = $metadata;
		return self::send_auth_form_response( $payload, $message );
	}

	/**
	 * Check if current reader has its email verified.
	 *
	 * @param \WP_User $user User object.
	 *
	 * @return bool|null Whether the email address is verified, null if invalid user.
	 */
	public static function is_reader_verified( $user ) {
		if ( ! $user ) {
			return null;
		}

		/** Should not verify email if user is not a reader. */
		if ( ! self::is_user_reader( $user ) ) {
			return null;
		}

		if ( defined( 'NEWSPACK_ALLOW_MY_ACCOUNT_ACCESS_WITHOUT_VERIFICATION' ) && NEWSPACK_ALLOW_MY_ACCOUNT_ACCESS_WITHOUT_VERIFICATION ) {
			return true;
		}

		return (bool) \get_user_meta( $user->ID, self::EMAIL_VERIFIED, true );
	}

	/**
	 * Authenticate a reader session given its user ID.
	 *
	 * Warning: this method will only verify if the user is a reader in order to
	 * authenticate. It will not check for any credentials.
	 *
	 * @param \WP_User|int $user_or_user_id User object.
	 *
	 * @return \WP_User|\WP_Error The authenticated reader or WP_Error if authentication failed.
	 */
	public static function set_current_reader( $user_or_user_id ) {
		if ( $user_or_user_id instanceof \WP_User ) {
			$user = $user_or_user_id;
		} elseif ( absint( $user_or_user_id ) ) {
			$user = get_user_by( 'id', $user_or_user_id );
		}

		if ( ! $user || \is_wp_error( $user ) || ! self::is_user_reader( $user ) ) {
			return new \WP_Error( 'newspack_authenticate_invalid_user', __( 'Invalid user.', 'newspack-plugin' ) );
		}

		\wp_clear_auth_cookie();
		\wp_set_current_user( $user->ID );
		\wp_set_auth_cookie( $user->ID, true );
		\do_action( 'wp_login', $user->user_login, $user );
		Logger::log( 'Logged in user ' . $user->ID );

		return $user;
	}

	/**
	 * Register a reader given its email.
	 *
	 * Due to authentication or auth intention, this method should be used
	 * preferably on POST or API requests to avoid issues with caching.
	 *
	 * @param string $email        Email address.
	 * @param string $display_name Reader display name to be used on account creation.
	 * @param bool   $authenticate Whether to authenticate after registering. Default to true.
	 * @param array  $metadata     Any metadata to pass along to the action hook.
	 *
	 * @return int|false|\WP_Error The created user ID in case of registration, false if the user already exists, or a WP_Error object.
	 */
	public static function register_reader( $email, $display_name = '', $authenticate = true, $metadata = [] ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'newspack_register_reader_disabled', __( 'Registration is disabled.', 'newspack-plugin' ) );
		}

		if ( \is_user_logged_in() ) {
			return new \WP_Error( 'newspack_register_reader_logged_in', __( 'Cannot register while logged in.', 'newspack-plugin' ) );
		}

		$email = \sanitize_email( $email );

		if ( empty( $email ) ) {
			return new \WP_Error( 'newspack_register_reader_empty_email', __( 'Please enter a valid email address.', 'newspack-plugin' ) );
		}

		self::set_auth_intention_cookie( $email );

		$existing_user = \get_user_by( 'email', $email );
		if ( \is_wp_error( $existing_user ) ) {
			return $existing_user;
		}

		$user_id = false;

		if ( $existing_user ) {
			// If the user is not a reader, send a non-reader login reminder. We don't want to expose on the front-end that the email address belongs to a non-reader account.
			if ( ! self::is_user_reader( $existing_user ) ) {
				self::send_non_reader_login_reminder( $existing_user );
				return false;
			}

			// Don't send OTP email for newsletter signup, or if the reader has a password set.
			if ( self::is_reader_without_password( $existing_user ) &&
				( ! isset( $metadata['registration_method'] ) || false === strpos( $metadata['registration_method'], 'newsletters-subscription' ) )
			) {
				Logger::log( "User with $email already exists. Sending magic link." );
				$redirect = isset( $metadata['current_page_url'] ) ? $metadata['current_page_url'] : '';
				Magic_Link::send_email( $existing_user, $redirect );
			}
		} else {
			/**
			 * Create new reader.
			 */
			$user_data = self::canonize_user_data(
				[
					'display_name' => $display_name,
					'user_email'   => $email,
				]
			);

			if ( function_exists( '\wc_create_new_customer' ) ) {
				/**
				 * Create WooCommerce Customer if possible.
				 * Email notification for WooCommerce is handled by the plugin.
				 */
				$user_id = \wc_create_new_customer( $email, $user_data['user_login'], $user_data['user_pass'], $user_data );
			} else {
				$user_id = \wp_insert_user( $user_data );
				\wp_new_user_notification( $user_id, null, 'user' );
			}

			if ( \is_wp_error( $user_id ) ) {
				Logger::error( 'User registration failed: ' . $user_id->get_error_message() );
				return $user_id;
			}

			/**
			 * Add default reader related meta.
			 */
			\update_user_meta( $user_id, self::READER, true );
			/** Email is not yet verified. */
			\update_user_meta( $user_id, self::EMAIL_VERIFIED, false );
			/** User hasn't set their own password yet. */
			\update_user_meta( $user_id, self::WITHOUT_PASSWORD, true );

			Logger::log( 'Created new reader user with ID ' . $user_id );

			if ( $authenticate ) {
				self::set_current_reader( $user_id );
			}
		}

		/**
		 * Filters the metadata to pass along to the action hook.
		 *
		 * @param array          $metadata      Metadata.
		 * @param int|false      $user_id       The created user id or false if the user already exists.
		 * @param false|\WP_User $existing_user The existing user object.
		 */
		$metadata = apply_filters( 'newspack_register_reader_metadata', $metadata, $user_id, $existing_user );

		// Note the user's login method for later use.
		if ( isset( $metadata['registration_method'] ) ) {
			\update_user_meta( $user_id, self::REGISTRATION_METHOD, $metadata['registration_method'] );
			if ( in_array( $metadata['registration_method'], self::SSO_REGISTRATION_METHODS, true ) ) {
				self::set_reader_verified( $user_id );
			}
		}

		/**
		 * Action after registering and authenticating a reader.
		 *
		 * @param string         $email         Email address.
		 * @param bool           $authenticate  Whether to authenticate after registering.
		 * @param false|int      $user_id       The created user id.
		 * @param false|\WP_User $existing_user The existing user object.
		 * @param array          $metadata      Metadata.
		 */
		\do_action( 'newspack_registered_reader', $email, $authenticate, $user_id, $existing_user, $metadata );

		return $user_id;
	}

	/**
	 * Get sanitized user data args for creating a new reader user account.
	 * See https://developer.wordpress.org/reference/functions/wp_insert_user/ for supported args.
	 *
	 * @param array $user_data          Default args for the new user.
	 *              $user_data['email] Email address for the new user (required).
	 */
	public static function canonize_user_data( $user_data = [] ) {
		if ( empty( $user_data['user_email'] ) ) {
			return $user_data;
		}

		$user_nicename = self::generate_user_nicename( ! empty( $user_data['display_name'] ) ? $user_data['display_name'] : $user_data['user_email'] );

		// If we don't have a display name, make it match the nicename.
		if ( empty( $user_data['display_name'] ) ) {
			$user_data['display_name'] = $user_nicename;
		}

		if ( empty( $user_data['user_pass'] ) ) {
			$password = \wp_generate_password();
		} else {
			$password = $user_data['user_pass'];
		}

		$user_data = array_merge(
			$user_data,
			[
				'user_login'    => $user_nicename,
				'user_nicename' => $user_nicename,
				'display_name'  => $user_nicename,
				'user_pass'     => $password,
			]
		);

		// Check if a user with this login exists.
		if ( \username_exists( $user_data['user_login'] ) ) {
			$user_data['user_login'] = $user_data['user_login'] . '-' . \wp_generate_password( 4, false );
		}

		/*
		 * Filters the user_data used to register a new RAS reader account.
		 * See https://developer.wordpress.org/reference/functions/wp_insert_user/ for supported args.
		 */
		return \apply_filters( 'newspack_register_reader_user_data', $user_data );
	}

	/**
	 * Validate reader data before being saved.
	 *
	 * @param array $data     User data.
	 * @param bool  $update   Whether the user is being updated rather than created.
	 * @param int   $user_id  User ID.
	 * @param array $userdata Raw array of user data.
	 *
	 * @return array
	 */
	public static function validate_user_data( $data, $update, $user_id, $userdata ) {
		// Only when updating an existing user.
		if ( ! $update || ! $user_id ) {
			return $data;
		}
		// Only if the user is a reader.
		if ( ! self::is_user_reader( \get_user_by( 'id', $user_id ) ) ) {
			return $data;
		}

		// Validate display name before saving.
		if ( isset( $data['display_name'] ) ) {
			// If the reader saves an empty value.
			if ( empty( trim( $data['display_name'] ) ) ) {
				if ( empty( $userdata['display_name'] ) ) {
					// If the reader lacks a display name, generate one.
					$data['display_name'] = self::generate_user_nicename( $userdata['user_email'] );
					\delete_user_meta( $user_id, self::READER_SAVED_GENERIC_DISPLAY_NAME );
				} else {
					// Otherwise, don't update it.
					$data['display_name'] = $userdata['display_name'];
				}
			}
		}

		return $data;
	}

	/**
	 * Display improved copy for the display name error message.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	public static function better_display_name_error( $message ) {
		if ( 'Display name cannot be changed to email address due to privacy concern.' === $message ) {
			return self::get_reader_activation_labels( 'invalid_display' );
		}

		return $message;
	}

	/**
	 * Strip the domain part of an email address string.
	 * If not an email address, just return the string.
	 *
	 * @param string $str String to check.
	 * @return string
	 */
	public static function strip_email_domain( $str ) {
		return trim( explode( '@', $str, 2 )[0] );
	}

	/**
	 * Generate a URL-sanitized version of the given string for a new reader account.
	 *
	 * @param string $name User's display name, or email if not available.
	 * @return string
	 */
	public static function generate_user_nicename( $name ) {
		$name = self::strip_email_domain( $name ); // If an email address, strip the domain.
		return \sanitize_title( \sanitize_user( $name, true ) );
	}

	/**
	 * Check if the reader's display name was auto-generated from email address.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if the display name was generated.
	 */
	public static function reader_has_generic_display_name( $user_id = 0 ) {
		// Allow an environment constant to override this check so that even generic/generated display names are allowed.
		if ( defined( 'NEWSPACK_ALLOW_GENERIC_READER_DISPLAY_NAMES' ) && NEWSPACK_ALLOW_GENERIC_READER_DISPLAY_NAMES ) {
			return false;
		}
		if ( ! $user_id ) {
			$user_id = \get_current_user_id();
		}
		$user = \get_userdata( $user_id );
		if ( empty( $user->data ) ) {
			return false;
		}

		// If the reader has intentionally saved a display name we consider generic, treat it as not generic.
		if ( \get_user_meta( $user_id, self::READER_SAVED_GENERIC_DISPLAY_NAME, true ) ) {
			return false;
		}

		// If the user lacks a display name or email address at all, treat it as generic.
		if ( empty( $user->data->display_name ) || empty( $user->data->user_email ) ) {
			return true;
		}

		// If we generated the display name from the user's email address, treat it as generic.
		if (
			self::generate_user_nicename( $user->data->user_email ) === $user->data->display_name || // New generated construction (URL-sanitized version of the email address minus domain).
			self::strip_email_domain( $user->data->user_email ) === $user->data->display_name // Legacy generated construction (just the email address minus domain).
		) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current reader is rate limited.
	 *
	 * @param \WP_User $user WP_User object to be verified.
	 *
	 * @return bool
	 */
	public static function is_reader_email_rate_limited( $user ) {
		if ( self::is_reader_verified( $user ) ) {
			return false;
		}
		$last_email = get_user_meta( $user->ID, self::LAST_EMAIL_DATE, true );
		return $last_email && self::EMAIL_INTERVAL > time() - $last_email;
	}

	/**
	 * If a non-reader account attempts to use reader account flows, send an email reminder to use standard WP login.
	 *
	 * @param WP_User $user WP_User to send the email to.
	 *
	 * @return bool|WP_Error
	 */
	public static function send_non_reader_login_reminder( $user ) {
		/** Rate limit control */
		if ( self::is_reader_email_rate_limited( $user ) ) {
			return new \WP_Error( 'newspack_non_reader_reminder_interval', __( 'Please wait a moment before trying again.', 'newspack-plugin' ) );
		}

		$sent = Emails::send_email(
			Reader_Activation_Emails::EMAIL_TYPES['NON_READER'],
			$user->user_email,
			[
				[
					'template' => '*WP_LOGIN_URL*',
					'value'    => \wp_login_url( '', true ), // Force reauth.
				],
			]
		);
		if ( $sent ) {
			\update_user_meta( $user->ID, self::LAST_EMAIL_DATE, time() );
		}
		return $sent;
	}

	/**
	 * Send a magic link with special messaging to verify the user.
	 *
	 * @param WP_User $user WP_User object to be verified.
	 */
	public static function send_verification_email( $user ) {
		// Send reminder to non-reader accounts to use standard WP login.
		if ( ! self::is_user_reader( $user ) ) {
			self::send_non_reader_login_reminder( $user );
		}

		/** Rate limit control */
		if ( self::is_reader_email_rate_limited( $user ) ) {
			return new \WP_Error( 'newspack_verification_email_interval', __( 'Please wait before requesting another verification email.', 'newspack-plugin' ) );
		}

		$redirect_to = function_exists( '\wc_get_account_endpoint_url' ) ? \wc_get_account_endpoint_url( 'dashboard' ) : '';
		\update_user_meta( $user->ID, self::LAST_EMAIL_DATE, time() );

		return Emails::send_email(
			Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
			$user->user_email,
			[
				[
					'template' => '*VERIFICATION_URL*',
					'value'    => Magic_Link::generate_url( $user, $redirect_to ),
				],
			]
		);
	}

	/**
	 * Get value of the client ID bearing cookie.
	 */
	public static function get_client_id() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		return isset( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) ? sanitize_text_field( $_COOKIE[ NEWSPACK_CLIENT_ID_COOKIE_NAME ] ) : false;
	}

	/**
	 * Disable the standard WooCommerce "new customer welcome" email.
	 *
	 * @param array $emails Types of transactional emails sent by WooCommerce.
	 *
	 * @return array Filtered array of transactional email types.
	 */
	public static function disable_woocommerce_new_user_email( $emails ) {
		$emails = array_values(
			array_filter(
				$emails,
				function( $type ) {
					return 'woocommerce_created_customer' !== $type;
				}
			)
		);

		return $emails;
	}


	/**
	 * Filters args sent to wp_mail when a password change email is sent.
	 *
	 * @param array   $defaults {
	 *       The default notification email arguments. Used to build wp_mail().
	 *
	 *     @type string $to      The intended recipient - user email address.
	 *     @type string $subject The subject of the email.
	 *     @type string $message The body of the email.
	 *     @type string $headers The headers of the email.
	 * }
	 * @param string  $key        The activation key.
	 * @param string  $user_login The username for the user.
	 * @param WP_User $user       WP_User object.
	 *
	 * @return array The filtered $defaults.
	 */
	public static function password_reset_configuration( $defaults, $key, $user_login, $user ) {
		$config_name  = Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'];
		$email_config = Emails::get_email_config_by_type( $config_name );

		$defaults['headers'] = sprintf(
			'From: %1$s <%2$s>',
			$email_config['from_name'],
			$email_config['from_email']
		);
		$defaults['subject'] = $email_config['subject'];
		$defaults['message'] = Emails::get_email_payload(
			$config_name,
			[
				[
					'template' => '*PASSWORD_RESET_LINK*',
					'value'    => Emails::get_password_reset_url( $user, $key ),
				],
			]
		);

		return $defaults;
	}

	/**
	 * Set email content type when a password reset email is about to be sent.
	 */
	public static function set_password_reset_mail_content_type() {
		$email_content_type = function() {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $email_content_type );
	}

	/**
	 * Rate limit password reset.
	 *
	 * @param \WP_Error      $errors    A WP_Error object containing any errors generated
	 *                                  by using invalid credentials.
	 * @param \WP_User|false $user_data WP_User object if found, false if the user does not exist.
	 *
	 * @return \WP_Error
	 */
	public static function rate_limit_lost_password( $errors, $user_data ) {
		if ( $user_data && self::is_reader_email_rate_limited( $user_data ) ) {
			$errors->add( 'newspack_password_reset_interval', self::get_reader_activation_labels( 'password_reset_interval' ) );
		} else {
			\update_user_meta( $user_data->ID, self::LAST_EMAIL_DATE, time() );
		}
		return $errors;
	}

	/**
	 * Gets the logged in reader's email address.
	 *
	 * @return string The reader's email address. Empty string if user is not logged in.
	 */
	private static function get_logged_in_reader_email_address() {
		$email_address = '';

		if ( \is_user_logged_in() && self::is_user_reader( \wp_get_current_user() ) ) {
			$email_address = \wp_get_current_user()->user_email;
		}

		return $email_address;
	}

	/**
	 * Login a reader after they have successfully reset their password.
	 *
	 * @param WP_User $user WP_User object.
	 */
	public static function login_after_password_reset( $user ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::set_current_reader( $user );
	}

	/**
	 * Whether forced registration at checkout is enabled.
	 *
	 * @return bool True if forced registration at checkout is enabled.
	 */
	public static function is_woocommerce_registration_required() {
		return (bool) \get_option( self::OPTIONS_PREFIX . 'woocommerce_registration_required', false );
	}

	/**
	 * Get the default list size for newsletters.
	 *
	 * @return int Default list size.
	 */
	private static function get_newsletters_list_initial_size() {
		return absint( get_option( self::OPTIONS_PREFIX . 'newsletter_list_initial_size', 2 ) );
	}

	/**
	 * Modal checkout registration privacy policy text.
	 *
	 * @return string Privacy policy text.
	 */
	public static function get_checkout_privacy_policy_text() {
		return \get_option(
			self::OPTIONS_PREFIX . 'woocommerce_checkout_privacy_policy_text',
			// New default WooCommerce privacy policy text to indicate we are creating an account for new user registrations.
			__(
				"Your personal data will be used to process your order and create an account if one doesn't exist. This information will also support your experience throughout this website, and be used for other purposes described in our privacy policy.",
				'newspack-plugin'
			)
		);
	}

	/**
	 * Modal checkout success text.
	 *
	 * @return string Post checkout success text.
	 */
	public static function get_post_checkout_success_text() {
		return \get_option(
			self::OPTIONS_PREFIX . 'woocommerce_post_checkout_success_text',
			sprintf(
				// Translators: %s is the name of the site.
				__(
					'Thank you for supporting %s. Your transaction was completed successfully.',
					'newspack-plugin'
				),
				html_entity_decode( get_bloginfo( 'name' ) )
			)
		);
	}

	/**
	 * Modal checkout registration success text.
	 *
	 * @return string Post checkout registration success text.
	 */
	public static function get_post_checkout_registration_success_text() {
		return \get_option(
			self::OPTIONS_PREFIX . 'woocommerce_post_checkout_registration_success_text',
			sprintf(
				// Translators: %s is the name of the site.
				__(
					'Thank you for supporting %s. Your account has been created, and your transaction was completed successfully.',
					'newspack-plugin'
				),
				html_entity_decode( get_bloginfo( 'name' ) )
			)
		);
	}

	/**
	 * Return if the Subscription confirmation checkbox is enabled.
	 *
	 * @return bool Whether the subscription confirmation checkbox is enabled.
	 */
	public static function is_subscription_confirmation_enabled() {
		return (bool) \get_option( self::OPTIONS_PREFIX . 'woocommerce_enable_subscription_confirmation', false );
	}

	/**
	 * Get the text label for the subscription confirmation checkbox.
	 *
	 * @return string Returns either the default text label or a customized one.
	 */
	public static function get_subscription_confirmation_text() {
		return \get_option(
			self::OPTIONS_PREFIX . 'woocommerce_subscription_confirmation_text',
			__(
				'I understand this is a recurring subscription and that I can cancel anytime through the My Account Page.',
				'newspack-plugin'
			)
		);
	}

	/**
	 * Return if the Terms & Conditions confirmation checkbox is enabled.
	 *
	 * @return bool Whether the Terms & Conditions confirmation checkbox is enabled.
	 */
	public static function is_terms_confirmation_enabled() {
		return (bool) \get_option( self::OPTIONS_PREFIX . 'woocommerce_enable_terms_confirmation', false );
	}

	/**
	 * Get the text label for the Terms & Conditions confirmation checkbox.
	 *
	 * @return string Returns either the default text label or a customized one.
	 */
	public static function get_terms_confirmation_text() {
		return \get_option(
			self::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_text',
			__( 'I have read and accept the {{Terms & Conditions}}.', 'newspack-plugin' )
		);
	}

	/**
	 * Get the URL for the Terms & Conditions confirmation checkbox.
	 *
	 * @return string Returns the URL for the Terms & Conditions confirmation checkbox.
	 */
	public static function get_terms_confirmation_url() {
		return \get_option( self::OPTIONS_PREFIX . 'woocommerce_terms_confirmation_url', '' );
	}

	/**
	 * Get the checkout configuration.
	 *
	 * @return array The checkout configuration.
	 */
	public static function get_checkout_configuration() {
		return [
			'woocommerce_registration_required'            => self::is_woocommerce_registration_required(),
			'woocommerce_post_checkout_success_text'       => self::get_post_checkout_success_text(),
			'woocommerce_checkout_privacy_policy_text'     => self::get_checkout_privacy_policy_text(),
			'woocommerce_post_checkout_registration_success_text' => self::get_post_checkout_registration_success_text(),
			'woocommerce_enable_subscription_confirmation' => self::is_subscription_confirmation_enabled(),
			'woocommerce_subscription_confirmation_text'   => self::get_subscription_confirmation_text(),
			'woocommerce_enable_terms_confirmation'        => self::is_terms_confirmation_enabled(),
			'woocommerce_terms_confirmation_text'          => self::get_terms_confirmation_text(),
			'woocommerce_terms_confirmation_url'           => self::get_terms_confirmation_url(),
		];
	}
}
Reader_Activation::init();

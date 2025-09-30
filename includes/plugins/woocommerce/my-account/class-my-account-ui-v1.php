<?php
/**
 * Newspack "My Account" customizations v1.x.x.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Reader_Activation;
use Newspack\Reader_Data;
use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_My_Account;
use Newspack\Newspack_UI;
use Newspack\Newspack_UI_Icons;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack "My Account" customizations v1.x.x.
 */
class My_Account_UI_V1 {
	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
		\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 11 );
		\add_filter( 'wc_get_template', [ __CLASS__, 'wc_get_template' ], 10, 5 );
		\add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'my_account_menu_items' ], 1001 );
		\add_filter( 'newspack_myaccount_required_fields', [ __CLASS__, 'account_settings_required_fields' ] );
		\add_action( 'newspack_woocommerce_after_edit_account_form', [ __CLASS__, 'delete_account_modal' ] );
		\add_action( 'newspack_after_delete_account', [ __CLASS__, 'handle_after_delete_account' ] );
		\add_action( 'wp_footer', [ __CLASS__, 'add_after_delete_account_notice' ] );
		\add_action( 'woocommerce_subscription_details_table', [ __CLASS__, 'cancel_subscription_modal' ] );
		\add_filter( 'option_woocommerce_myaccount_add_payment_method_endpoint', [ __CLASS__, 'add_payment_method_endpoint' ] );
		\add_filter( 'default_option_woocommerce_myaccount_add_payment_method_endpoint', [ __CLASS__, 'add_payment_method_endpoint' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_payment_information_endpoint' ] );
		\add_action( 'newspack_woocommerce_after_account_payment_methods', [ __CLASS__, 'add_payment_method_modal' ] );
		\add_action( 'newspack_woocommerce_after_account_payment_methods', [ __CLASS__, 'delete_payment_method_modals' ] );
		\add_action( 'newspack_woocommerce_after_account_addresses', [ __CLASS__, 'add_address_modals' ] );
		\add_action( 'newspack_woocommerce_after_account_addresses', [ __CLASS__, 'delete_address_modals' ] );
		\add_action( 'woocommerce_after_save_address_validation', [ __CLASS__, 'handle_delete_address_submission' ], 10, 4 );
		\add_filter( 'woocommerce_address_to_edit', [ __CLASS__, 'reorder_address_fields' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Render My Account pages with a no-header/no-footer page template.
	 *
	 * @param string $template The template.
	 * @return string The template file path.
	 */
	public static function page_template( $template ) {
		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $template;
		}

		// Only if the user is logged in.
		if ( ! \is_user_logged_in() ) {
			return $template;
		}

		return __DIR__ . '/templates/v1/my-account.php';
	}

	/**
	 * Add a body class to the My Account page.
	 *
	 * @param array $classes The body classes.
	 * @return array The body classes.
	 */
	public static function add_body_class( $classes ) {
		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $classes;
		}

		$classes[] = 'newspack-ui';
		$classes[] = 'newspack-my-account';
		$classes[] = 'newspack-my-account--v1';
		if ( ! \is_user_logged_in() ) {
			$classes[] = 'newspack-my-account--logged-out';
		} else {
			$classes[] = 'newspack-my-account--logged-in';
		}
		return $classes;
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return;
		}
		$script_data = [
			'myAccountUrl' => wc_get_account_endpoint_url( 'dashboard' ),
			'labels'       => [
				'resubscribe_title'           => __( 'Renew subscription', 'newspack-plugin' ),
				'renewal_early_title'         => __( 'Renew subscription early', 'newspack-plugin' ),
				'change_payment_method_title' => __( 'Change payment method', 'newspack-plugin' ),
				'switch_subscription_title'   => __( 'Change Subscription', 'newspack-plugin' ),
			],
		];

		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			\wp_enqueue_script(
				'newspack-account-frontend',
				\Newspack\Newspack::plugin_url() . '/dist/account-frontend.js',
				[],
				NEWSPACK_PLUGIN_VERSION,
				true
			);
			\wp_localize_script(
				'newspack-account-frontend',
				'newspackMyAccountV1',
				$script_data
			);
		} else {
			\wp_enqueue_script(
				'newspack-my-account-v1',
				\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.js',
				[ 'newspack-my-account' ],
				NEWSPACK_PLUGIN_VERSION,
				true
			);
			\wp_localize_script(
				'newspack-my-account-v1',
				'newspackMyAccountV1',
				$script_data
			);

			// Dequeue styles from the Newspack theme first, for a fresh start.
			\wp_dequeue_style( 'newspack-woocommerce-style' );
			\wp_enqueue_style(
				'newspack-my-account-v1',
				\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.css',
				[],
				NEWSPACK_PLUGIN_VERSION
			);
		}
	}

	/**
	 * WC's page templates hijacking.
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 */
	public static function wc_get_template( $template, $template_name ) {
		switch ( $template_name ) {
			case 'myaccount/navigation.php':
				return __DIR__ . '/templates/v1/navigation.php';
			case 'myaccount/form-edit-account.php':
				return __DIR__ . '/templates/v1/account-settings.php';
			case 'myaccount/payment-methods.php':
				return __DIR__ . '/templates/v1/payment-information.php';
			case 'myaccount/form-edit-address.php':
				return __DIR__ . '/templates/v1/form-edit-address.php';
			default:
				return $template;
		}
	}

	/**
	 * Add query var for the "Payment Information" page.
	 *
	 * @param array $vars Query var.
	 *
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'add-payment-method';
		return $vars;
	}

	/**
	 * Modify nav menu items.
	 *
	 * @param array $items Menu items.
	 * @return array Modified menu items.
	 */
	public static function my_account_menu_items( $items ) {
		// Remove logout menu item (to be replaced in our custom template).
		unset( $items['customer-logout'] );

		// Rename "Payment Methods" to "Payment Information".
		if ( isset( $items['payment-methods'] ) ) {
			$items['payment-methods'] = __( 'Payment information', 'newspack-plugin' );
		}

		// Remove "Addresses" (replaced by custom "Payment Information" page).
		unset( $items['edit-address'] );

		return $items;
	}

	/**
	 * Remove required fields from the account settings form.
	 *
	 * @param array $required_fields The required fields.
	 * @return array The required fields.
	 */
	public static function account_settings_required_fields( $required_fields ) {
		unset( $required_fields['account_display_name'] );
		return $required_fields;
	}

	/**
	 * Display a series of modals to request account deletion.
	 */
	public static function delete_account_modal() {
		$user = \wp_get_current_user();

		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}

		// If the user has clicked the button from the delete account email, show the confirmation modal.
		$delete_account_form = filter_input( INPUT_GET, WooCommerce_My_Account::DELETE_ACCOUNT_FORM, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! empty( $delete_account_form ) ) {
			return self::delete_account_confirmation_modal();
		}

		$active_subscriptions     = json_decode( Reader_Data::get_data( $user->ID, 'active_subscriptions' ) );
		$active_donations         = boolval( Reader_Data::get_data( $user->ID, 'is_donor' ) );
		$newsletter_subscriptions = json_decode( Reader_Data::get_data( $user->ID, 'newsletter_subscribed_lists' ) );

		ob_start();
		?>
		<h2 class="font-size newspack-ui__font--l">
			<?php esc_html_e( 'Are you sure?', 'newspack-plugin' ); ?>
		</h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					// Translators: %s will be displayed only if the user has active subscriptions or newsletter subscriptions.
					__( 'Deleting your account is permanent and cannot be undone. All your data will be removed from our systems. %s', 'newspack-plugin' ),
					! empty( $active_subscriptions ) || ! empty( $newsletter_subscriptions ) || $active_donations ? __( 'Your newsletter subscriptions and all recurring payments will be cancelled.', 'newspack-plugin' ) : ''
				)
			);
			?>
		</p>
		<?php if ( ! empty( $active_subscriptions ) || ! empty( $newsletter_subscriptions ) || $active_donations ) : ?>
		<p>
			<?php
			esc_html_e( 'Instead of deleting your account, you may want to:', 'newspack-plugin' );
			?>
		</p>
			<?php
		endif;
		if ( ! empty( $active_subscriptions ) || $active_donations ) :
			?>
		<div class="newspack-ui__row">
			<div>
				<p class="font-size newspack-ui__font--s newspack-ui__font--bold"><?php esc_html_e( 'Subscriptions', 'newspack-plugin' ); ?></p>
				<p class="newspack-ui__helper-text"><?php esc_html_e( 'Review and cancel active subscriptions.', 'newspack-plugin' ); ?></p>
			</div>
			<div class="newspack-ui__width--40">
				<a class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide" href="<?php echo esc_url( \wc_get_endpoint_url( 'subscriptions', '', \wc_get_page_permalink( 'myaccount' ) ) ); ?>">
					<?php esc_html_e( 'Manage subscriptions', 'newspack-plugin' ); ?>
				</a>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $newsletter_subscriptions ) ) : ?>
		<div class="newspack-ui__row">
			<div>
				<p class="font-size newspack-ui__font--s newspack-ui__font--bold"><?php esc_html_e( 'Newsletters', 'newspack-plugin' ); ?></p>
				<p class="newspack-ui__helper-text"><?php esc_html_e( 'Update your newsletter preferences.', 'newspack-plugin' ); ?></p>
			</div>
			<div class="newspack-ui__width--40">
				<a class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide" href="<?php echo esc_url( \wc_get_endpoint_url( 'newsletters', '', \wc_get_page_permalink( 'myaccount' ) ) ); ?>">
					<?php esc_html_e( 'Manage newsletters', 'newspack-plugin' ); ?>
				</a>
				</div>
			</div>
			<?php
		endif;
		$content_send_email = ob_get_clean();

		// Modal to send the delete account email.
		Newspack_UI::generate_modal(
			[
				'id'      => 'delete-account',
				'title'   => __( 'Delete account', 'newspack-plugin' ),
				'content' => $content_send_email,
				'size'    => 'medium',
				'actions' => [
					'confirm' => [
						'label' => __( 'Delete account', 'newspack-plugin' ),
						'type'  => 'destructive',
						'fetch' => [
							'url'    => \rest_url( 'newspack/v1/delete-account' ),
							'method' => 'POST',
							'next'   => 'delete-account-email-sent',
							'nonce'  => \wp_create_nonce( 'wp_rest' ),
							'body'   => [
								'user_id' => \wp_get_current_user()->ID,
							],
						],
					],
					'cancel'  => [
						'label'  => __( 'Cancel', 'newspack-plugin' ),
						'type'   => 'ghost',
						'action' => 'close',
					],
				],
			]
		);

		ob_start();
		?>
		<div class="newspack-ui__box newspack-ui__box--text-center">
			<span class="newspack-ui__icon newspack-ui__icon--neutral">
				<?php Newspack_UI_Icons::print_svg( 'email' ); ?>
			</span>
			<p>
				<strong><?php esc_html_e( 'Your account deletion has been requested.', 'newspack-plugin' ); ?></strong>
			</p>
			<p><?php esc_html_e( 'We just sent instructions on how to delete your account to', 'newspack-plugin' ); ?> <strong><?php echo esc_html( \wp_get_current_user()->user_email ); ?></strong>.</p>
		</div>
		<?php
		$content_email_sent = ob_get_clean();

		// Modal to confirm that the email was sent.
		Newspack_UI::generate_modal(
			[
				'id'      => 'delete-account-email-sent',
				'title'   => __( 'Delete account', 'newspack-plugin' ),
				'content' => $content_email_sent,
				'size'    => 'small',
				'actions' => [
					'continue' => [
						'label'  => __( 'Continue', 'newspack-plugin' ),
						'type'   => 'primary',
						'action' => 'close',
					],
				],
			]
		);
	}

	/**
	 * Display a confirmation modal to confirm account deletion.
	 */
	public static function delete_account_confirmation_modal() {
		$delete_account_form = WooCommerce_My_Account::DELETE_ACCOUNT_FORM;
		$nonce_value         = filter_input( INPUT_GET, $delete_account_form, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$token               = filter_input( INPUT_GET, 'token', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$transient_token     = \get_transient( 'np_reader_account_delete_' . \get_current_user_id() );
		if ( ! \wp_verify_nonce( $nonce_value, $delete_account_form ) ) {
			WooCommerce_Connection::add_wc_notice( __( 'Invalid nonce.', 'newspack-plugin' ), 'error' );
			return;
		}
		if ( ! $token || ! $transient_token || $transient_token !== $token ) {
			WooCommerce_Connection::add_wc_notice( __( 'Invalid token.', 'newspack-plugin' ), 'error' );
			return;
		}
		ob_start();
		?>
		<h2 class="font-size newspack-ui__font--l">
			<?php esc_html_e( 'Are you sure?', 'newspack-plugin' ); ?>
		</h2>
		<p>
			<?php esc_html_e( 'Confirm to delete your account permanently.', 'newspack-plugin' ); ?>&nbsp;
			<strong><?php esc_html_e( 'Caution, this action is irreversible!', 'newspack-plugin' ); ?></strong>
		</p>
		<input type="hidden" name="<?php echo \esc_attr( $delete_account_form ); ?>" value="<?php echo \esc_attr( $nonce_value ); ?>">
		<input type="hidden" name="token" value="<?php echo \esc_attr( $token ); ?>">
		<input type="hidden" name="confirm_delete" value="1" />
		<?php
		$content = ob_get_clean();

		// Modal to confirm that the email was sent.
		Newspack_UI::generate_modal(
			[
				'id'      => 'delete-account',
				'title'   => __( 'Delete account', 'newspack-plugin' ),
				'content' => $content,
				'size'    => 'small',
				'form'    => 'POST',
				'state'   => 'open',
				'actions' => [
					'continue' => [
						'label' => __( 'Delete account', 'newspack-plugin' ),
						'type'  => 'destructive',
					],
					'cancel'   => [
						'label'  => __( 'Cancel', 'newspack-plugin' ),
						'type'   => 'ghost',
						'action' => 'close',
					],
				],
			]
		);
	}

	/**
	 * Handle after delete account.
	 */
	public static function handle_after_delete_account() {
		\wp_safe_redirect(
			\add_query_arg(
				WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM,
				1,
				\home_url()
			)
		);
		exit;
	}

	/**
	 * Show a notice after the account is deleted.
	 */
	public static function add_after_delete_account_notice() {
		$account_deleted = filter_input( INPUT_GET, WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM, FILTER_VALIDATE_BOOLEAN );
		if ( $account_deleted ) {
			?>
			<div class="newspack-ui">
				<div class="newspack-ui__snackbar newspack-ui__snackbar--top-right newspack-ui__snackbar--success active-on-load">
					<?php esc_html_e( 'Your account has been successfully deleted.', 'newspack-plugin' ); ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Confirmation modal to cancel a subscription.
	 * Rendered when viewing a single subscription page.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 */
	public static function cancel_subscription_modal( $subscription ) {
		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return;
		}
		$next_payment = $subscription->get_time( 'next_payment' );
		if (
			// See: wcs_get_all_user_actions_for_subscription().
			$subscription->can_be_updated_to( 'cancelled' ) && ! $subscription->is_one_payment() &&
			(
				( $subscription->has_status( 'on-hold' ) && empty( $next_payment ) ) ||
				$next_payment > 0
			)
		) {
			$next_payment_date = $next_payment ? $subscription->get_date_to_display( 'next_payment' ) : null;
			ob_start();
			?>
			<h2 class="font-size newspack-ui__font--l">
				<?php esc_html_e( 'Are you sure?', 'newspack-plugin' ); ?>
			</h2>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
					// Translators: %s is either the next payment date, or a generic explanation of when the subscription will end if cancelled.
						__( 'If you cancel now, your subscription will remain active until %s.', 'newspack-plugin' ),
						"<strong>$next_payment_date</strong>" ?? __( 'the end of your current billing period', 'newspack-plugin' )
					)
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'After this, your subscription access will end unless you choose to renew.', 'newspack-plugin' ); ?>
			</p>
			<?php
			$content = ob_get_clean();
			Newspack_UI::generate_modal(
				[
					'id'      => 'confirm-subscription-cancellation',
					'title'   => __( 'Cancel subscription', 'newspack-plugin' ),
					'content' => $content,
					'actions' => [
						'confirm' => [
							'label' => __( 'Cancel subscription', 'newspack-plugin' ),
							'type'  => 'destructive',
							'url'   => \wcs_get_users_change_status_link( $subscription->get_id(), 'cancelled' ),
						],
						'cancel'  => [
							'label'  => __( 'Keep subscription', 'newspack-plugin' ),
							'type'   => 'ghost',
							'action' => 'close',
						],
					],
				]
			);
		}
	}

	/**
	 * Set "Add Payment Method" endpoint to "payment-methods".
	 * The add-payment-method form is now rendered via a modal.
	 *
	 * @return string
	 */
	public static function add_payment_method_endpoint() {
		return \get_option( 'woocommerce_myaccount_payment_methods_endpoint', 'payment-methods' );
	}

	/**
	 * Redirect "Addresses" to the "Payment Information" page.
	 */
	public static function redirect_payment_information_endpoint() {
		if ( function_exists( 'is_account_page' ) && \is_account_page() ) {
			global $wp;
			$current_url = \trailingslashit( \home_url( $wp->request ) );
			if ( \trailingslashit( \wc_get_account_endpoint_url( 'edit-address' ) ) === $current_url ) {
				\wp_safe_redirect( \wc_get_account_endpoint_url( 'payment-methods' ) );
				exit;
			}
		}
	}

	/**
	 * Render confirmation modals for deleting saved payment methods.
	 *
	 * @param bool $has_methods Whether there are saved payment methods to render.
	 */
	public static function delete_payment_method_modals( $has_methods ) {
		if ( ! $has_methods ) {
			return;
		}

		$saved_methods = \wc_get_customer_saved_methods_list( \get_current_user_id() );

		if ( empty( $saved_methods ) || ! is_array( $saved_methods ) ) {
			return;
		}

		foreach ( $saved_methods as $methods ) {
			foreach ( $methods as $method ) {
				$delete_url = $method['actions']['delete']['url'] ?? '';

				if ( empty( $delete_url ) ) {
					continue;
				}

				$modal_suffix = \sanitize_html_class( md5( $delete_url ) );
				$brand        = ! empty( $method['method']['brand'] ) ? \wc_get_credit_card_type_label( $method['method']['brand'] ) : '';
				$last4        = $method['method']['last4'] ?? '';
				$expires      = $method['expires'] ?? '';

				// Parse the URL to extract query parameters.
				$parsed_url   = \wp_parse_url( $delete_url );
				$query_params = [];

				if ( ! empty( $parsed_url['query'] ) ) {
					\parse_str( $parsed_url['query'], $query_params );
				}

				// Reconstruct the base URL without query parameters.
				$form_action_base = \remove_query_arg( array_keys( $query_params ), $delete_url );

				ob_start();
				?>
				<p><?php \esc_html_e( 'Are you sure you want to delete this payment method from your account?', 'newspack-plugin' ); ?></p>
				<div class="payment-method-preview newspack-ui__font--s newspack-ui__font--bold">
					<?php
					$lines = [];

					if ( $brand ) {
						$lines[] = $brand;
					}

					if ( $last4 ) {
						$lines[] = sprintf(
							/* translators: last 4 digits. */
							__( 'Ending in %s', 'newspack-plugin' ),
							$last4
						);
					}

					if ( $expires ) {
						$lines[] = sprintf(
							/* translators: expiration date. */
							__( 'Exp. %s', 'newspack-plugin' ),
							$expires
						);
					}

					if ( ! empty( $lines ) ) {
						echo implode( '<br>', array_map( 'esc_html', $lines ) );
					}
					?>
				</div>
				<?php
				// Add query parameters as hidden form fields.
				foreach ( $query_params as $param_name => $param_value ) {
					printf(
						'<input type="hidden" name="%1$s" value="%2$s">',
						\esc_attr( $param_name ),
						\esc_attr( $param_value )
					);
				}

				$content = ob_get_clean();

				Newspack_UI::generate_modal(
					[
						'id'          => 'delete-payment-method-' . $modal_suffix,
						'title'       => __( 'Delete payment method', 'newspack-plugin' ),
						'content'     => $content,
						'size'        => 'small',
						'form'        => 'GET',
						'form_action' => $form_action_base,
						'actions'     => [
							'delete' => [
								'label' => __( 'Delete payment method', 'newspack-plugin' ),
								'type'  => 'destructive',
							],
							'cancel' => [
								'label'  => __( 'Cancel', 'newspack-plugin' ),
								'type'   => 'ghost',
								'action' => 'close',
							],
						],
					]
				);
			}
		}
	}

	/**
	 * Render the "Add Payment Method" modal.
	 */
	public static function add_payment_method_modal() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}
		ob_start();
		\woocommerce_account_add_payment_method();
		$content = ob_get_clean();
		Newspack_UI::generate_modal(
			[
				'id'         => 'add-payment-method',
				'title'      => __( 'Add Payment Method', 'newspack-plugin' ),
				'content'    => $content,
				'size'       => 'medium',
				'form'       => 'POST',
				'form_class' => 'newspack-ui__accordion newspack-ui__accordion--open',
				'form_id'    => 'add_payment_method',
				'actions'    => [
					'cancel' => [
						'label'  => __( 'Cancel', 'newspack-plugin' ),
						'type'   => 'ghost',
						'action' => 'close',
					],
				],
			]
		);
	}

	/**
	 * Render the "Add Address" modal.
	 */
	public static function add_address_modals() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}
		$address_types = [ 'billing' => __( 'Billing', 'newspack-plugin' ) ];
		if ( ! \wc_ship_to_billing_address_only() && \wc_shipping_enabled() ) {
			$address_types['shipping'] = __( 'Shipping', 'newspack-plugin' );
		}
		$address_types = \apply_filters( 'woocommerce_my_account_get_addresses', $address_types );
		foreach ( $address_types as $address_type => $address_name ) {
			$address = \wc_get_account_formatted_address( $address_type );
			ob_start();
			\woocommerce_account_edit_address( $address_type );
				$content          = ob_get_clean();
				$edit_address_url = \add_query_arg(
					'edit-address',
					$address_type,
					\wc_get_endpoint_url( 'edit-address', $address_type )
				);
				Newspack_UI::generate_modal(
					[
						'id'          => 'edit-address-' . $address_type,
						'title'       => ! empty( $address ) ? sprintf(
							// Translators: %s is the address type.
							__( 'Edit %s address', 'newspack-plugin' ),
							$address_type
						) : sprintf(
							// Translators: %s is the address type.
							__( 'Add %s address', 'newspack-plugin' ),
							$address_type
						),
						'content'     => $content,
						'size'        => 'medium',
						'form'        => 'POST',
						'form_id'     => 'edit_address_' . $address_type,
						'form_action' => $edit_address_url,
						'actions'     => [
							'cancel' => [
								'label'  => __( 'Cancel', 'newspack-plugin' ),
								'type'   => 'ghost',
								'action' => 'close',
							],
						],
					]
				);
		}
	}

	/**
	 * Render the "Delete Address" confirmation modals.
	 */
	public static function delete_address_modals() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		$address_types = [ 'billing' => __( 'Billing', 'newspack-plugin' ) ];
		if ( ! \wc_ship_to_billing_address_only() && \wc_shipping_enabled() ) {
			$address_types['shipping'] = __( 'Shipping', 'newspack-plugin' );
		}
		$address_types = \apply_filters( 'woocommerce_my_account_get_addresses', $address_types );

		foreach ( $address_types as $address_type => $address_name ) {
			$address = \wc_get_account_formatted_address( $address_type );

			// Only create delete modal if address exists.
			if ( ! empty( $address ) ) {
				ob_start();
				?>
				<p>
					<?php esc_html_e( 'Are you sure you want to delete this address from your account?', 'newspack-plugin' ); ?>
				</p>
				<div class="address-preview newspack-ui__font--bold">
					<?php echo wp_kses_post( $address ); ?>
				</div>

				<?php
				// Create hidden form fields to clear the address.
					$fields = [
						$address_type . '_first_name' => '',
						$address_type . '_last_name'  => '',
						$address_type . '_company'    => '',
						$address_type . '_address_1'  => '',
						$address_type . '_address_2'  => '',
						$address_type . '_city'       => '',
						$address_type . '_postcode'   => '',
						$address_type . '_country'    => '',
						$address_type . '_state'      => '',
						'save_address'                => 'Save address',
						'action'                      => 'edit_address',
						'newspack_delete_address'     => $address_type,
					];

					// Add email and phone for billing addresses.
					if ( 'billing' === $address_type ) {
						$fields['billing_email'] = '';
						$fields['billing_phone'] = '';
					}

					foreach ( $fields as $field_name => $field_value ) {
						echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $field_value ) . '">';
					}
					$request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? '';
					?>
					<input type="hidden" id="woocommerce-delete-address-nonce-<?php echo esc_attr( $address_type ); ?>" name="woocommerce-edit-address-nonce" value="<?php echo esc_attr( \wp_create_nonce( 'woocommerce-edit_address' ) ); ?>">
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( \wp_unslash( $request_uri ) ); ?>">
					<?php
					$content            = ob_get_clean();
					$delete_address_url = \add_query_arg(
						'edit-address',
						$address_type,
						\wc_get_endpoint_url( 'edit-address', $address_type )
					);

					Newspack_UI::generate_modal(
						[
							'id'          => 'delete-address-' . $address_type,
							'title'       => sprintf(
								// Translators: %s is the address type.
								__( 'Delete %s address', 'newspack-plugin' ),
								$address_type
							),
							'content'     => $content,
							'size'        => 'small',
							'form'        => 'POST',
							'form_action' => $delete_address_url,
							'actions'     => [
								'delete' => [
									'label' => __( 'Delete address', 'newspack-plugin' ),
									'type'  => 'destructive',
								],
								'cancel' => [
									'label'  => __( 'Cancel', 'newspack-plugin' ),
									'type'   => 'ghost',
									'action' => 'close',
								],
							],
						]
					);
			}
		}
	}

	/**
	 * Handle delete address submission.
	 *
	 * @param int    $user_id      User ID being saved.
	 * @param string $address_type Type of address; 'billing' or 'shipping'.
	 */
	public static function handle_delete_address_submission( $user_id, $address_type ) {
		$delete_request = filter_input( INPUT_POST, 'newspack_delete_address', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( empty( $delete_request ) || $delete_request !== $address_type ) {
			return;
		}

		$notices = \wc_get_notices();

		if ( empty( $notices ) || empty( $notices['error'] ) ) {
			return;
		}

		unset( $notices['error'] );
		\wc_set_notices( $notices );
	}

	/**
	 * Reorder address fields.
	 *
	 * @param array  $address The address.
	 * @param string $load_address The address type (billing or shipping).
	 * @return array The address.
	 */
	public static function reorder_address_fields( $address, $load_address ) {
		// Move state before postcode.
		if ( isset( $address[ $load_address . '_state' ] ) ) {
			$address[ $load_address . '_state' ]['priority'] = 80;
		}

		if ( isset( $address[ $load_address . '_postcode' ] ) ) {
			$address[ $load_address . '_postcode' ]['priority'] = 90;
		}

		// Move email before phone by setting its priority to phone's priority minus 1.
		if ( isset( $address[ $load_address . '_phone' ] ) && isset( $address[ $load_address . '_email' ] ) ) {
			$address[ $load_address . '_email' ]['priority'] = $address[ $load_address . '_phone' ]['priority'] - 1;
		}

		return $address;
	}
}
My_Account_UI_V1::init();

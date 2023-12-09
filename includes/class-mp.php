<?php
/**
 * Newspack Memberpress feature management.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Memberpress functionality.
 */
class MP {
	/**
	 * Allowed config keys for Memberpress settings.
	 *
	 * @var array
	 */
	protected static $allowed_keys = [
		'mp_membership_id',
	];

	/**
	 * Add hooks.
	 */
	public static function init() {
		\add_filter( 'newspack_donation_checkout_url', [ __CLASS__, 'redirect_to_nrh_checkout' ], 10, 3 );
		\add_filter( 'newspack_blocks_donate_block_html', [ __CLASS__, 'handle_custom_campaign_id' ], 10, 2 );
	}

	/**
	 * Add a hidden campaign input when a custom campaign is present in the GET request.
	 *
	 * @param string $html The donate form html.
	 * @param array  $attributes Block attributes.
	 * @return string modified $html.
	 */
	public static function handle_custom_campaign_id( $html, $attributes ) {
		// Don't add a global campaign ID if there is already a campaign ID.
		if ( stripos( $html, "name='campaign'" ) || stripos( $html, 'name="campaign"' ) ) {
			return $html;
		}

		$custom_campaign = filter_input( INPUT_GET, 'campaign', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $custom_campaign ) {
			return $html;
		}

		$custom_campaign = '<input type="hidden" name="campaign" value="' . esc_attr( $custom_campaign ) . '"/>';
		$html            = str_replace( '</form>', $custom_campaign . '</form>', $html );
		return $html;
	}

	/**
	 * Get all Memberpress settings.
	 *
	 * @return array Array of settings.
	 */
	public static function get_settings() {
		$settings = \get_option( NEWSPACK_MP_CONFIG, [] );

		if ( method_exists( '\Newspack_Popups_Settings', 'donor_landing_page' ) ) {
			$settings['donor_landing_page'] = null;
			$donor_landing_page             = \Newspack_Popups_Settings::donor_landing_page();

			if ( $donor_landing_page ) {
				$settings['donor_landing_page'] = [
					'label' => \get_the_title( $donor_landing_page ),
					'value' => $donor_landing_page,
				];
			}
		}

		return $settings;
	}

	/**
	 * Get a specific Memberpress setting by key name.
	 * Validates given key against valid keys.
	 *
	 * @param string $key Key of setting to get.
	 *
	 * @return string|boolean Value of setting if found, false otherwise.
	 */
	public static function get_setting( $key ) {
		if ( ! in_array( $key, self::$allowed_keys, true ) ) {
			return false;
		}

		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? \wp_strip_all_tags( $settings[ $key ] ) : false;
	}

	/**
	 * Update Memberpress settings.
	 * Validates given data against valid keys.
	 *
	 * @param array $data Array of settings to update.
	 *
	 * @return boolean True if settings were updated, false otherwise.
	 */
	public static function update_settings( $data ) {
		$settings = self::get_settings();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, self::$allowed_keys, true ) ) {
				$settings[ $key ] = $value;
			} elseif ( 'donor_landing_page' === $key && method_exists( '\Newspack_Popups_Settings', 'update_setting' ) ) {
				// Update the donor landing page in Campaigns settings.
				\Newspack_Popups_Settings::update_setting( 'donor_settings', 'newspack_popups_donor_landing_page', ! empty( $value['value'] ) ? (string) $value['value'] : 0 );
			}
		}

		// Don't want to save this extra key which is used only for the front-end display.
		unset( $settings['donor_landing_page'] );

		return \update_option( NEWSPACK_MP_CONFIG, $settings );
	}

	/**
	 * Redirect to the Memberpress checkout page when the donation form is submitted if possible.
	 *
	 * @param string $checkout_url URL of checkout page.
	 * @param float  $donation_value Amount of donation.
	 * @param string $donation_frequency 'month', 'year', or 'once'.
	 * @return string Modified $checkout_url.
	 */
	public static function redirect_to_nrh_checkout( $checkout_url, $donation_value, $donation_frequency ) {
		return $checkout_url . '&foo=bar';
	}

	/**
	 * Get NRH config.
	 */
	public static function get_nrh_config() {
		return get_option( NEWSPACK_MP_CONFIG, [] );
	}
}
MP::init();

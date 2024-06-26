<?php
/**
 * Newspack Data Events Mailchimp Connector
 *
 * @package Newspack
 */

namespace Newspack\Data_Events\Connectors;

use Newspack\Logger;
use Newspack\Data_Events;
use Newspack\Mailchimp_API;
use Newspack\Newspack_Newsletters;
use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Main Class.
 */
class Mailchimp extends Connector {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ __CLASS__, 'register_handlers' ] );
	}

	/**
	 * Register handlers.
	 */
	public static function register_handlers() {
		if ( ! method_exists( 'Newspack_Newsletters', 'service_provider' ) ) {
			return;
		}
		if (
			Reader_Activation::is_enabled() &&
			true === Reader_Activation::get_setting( 'sync_esp' ) &&
			'mailchimp' === \Newspack_Newsletters::service_provider()
		) {
			Data_Events::register_handler( [ __CLASS__, 'reader_registered' ], 'reader_registered' );
			Data_Events::register_handler( [ __CLASS__, 'reader_logged_in' ], 'reader_logged_in' );
			Data_Events::register_handler( [ __CLASS__, 'order_completed' ], 'order_completed' );
			Data_Events::register_handler( [ __CLASS__, 'subscription_updated' ], 'donation_subscription_changed' );
			Data_Events::register_handler( [ __CLASS__, 'subscription_updated' ], 'product_subscription_changed' );
		}
	}

	/**
	 * Get audience ID.
	 *
	 * @return string|bool Audience ID or false if not set.
	 */
	private static function get_audience_id() {
		$audience_id = Reader_Activation::get_setting( 'mailchimp_audience_id' );
		/** Attempt to use list ID from "Mailchimp for WooCommerce" */
		if ( ! $audience_id && function_exists( 'mailchimp_get_list_id' ) ) {
			$audience_id = \mailchimp_get_list_id();
		}
		return ! empty( $audience_id ) ? $audience_id : false;
	}

	/**
	 * Get default reader MailChimp status.
	 *
	 * @return string MailChimp status slug, 'transactional' or 'subscriber'. (Default: 'transactional').
	 */
	private static function get_default_reader_status() {
		$allowed_statuses = [
			'transactional',
			'subscribed',
		];
		$default_status = Reader_Activation::get_setting( 'mailchimp_reader_default_status' );
		return in_array( $default_status, $allowed_statuses, true ) ? $default_status : 'transactional';
	}

	/**
	 * Get merge field type.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return string Merge field type.
	 */
	private static function get_merge_field_type( $value ) {
		if ( is_numeric( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		return 'text';
	}

	/**
	 * Get merge fields given data.
	 *
	 * @param string $audience_id Audience ID.
	 * @param array  $data        Data to check.
	 *
	 * @return array Merge fields.
	 */
	private static function get_merge_fields( $audience_id, $data ) {
		$merge_fields = [];

		// Strip arrays.
		$data = array_filter(
			$data,
			function( $value ) {
				return ! is_array( $value );
			}
		);

		// Get and match existing merge fields.
		$merge_fields_res = Mailchimp_API::get( "lists/$audience_id/merge-fields?count=1000" );
		if ( \is_wp_error( $merge_fields_res ) ) {
			Logger::log(
				sprintf(
					// Translators: %1$s is the error message.
					__( 'Error getting merge fields: %1$s', 'newspack-plugin' ),
					$merge_fields_res->get_error_message()
				)
			);
			return [];
		}
		$existing_fields = $merge_fields_res['merge_fields'];
		usort(
			$existing_fields,
			function( $a, $b ) {
				return $a['merge_id'] - $b['merge_id'];
			}
		);

		$list_merge_fields = [];

		// Handle duplicate fields.
		foreach ( $existing_fields as $field ) {
			if ( ! isset( $list_merge_fields[ $field['name'] ] ) ) {
				$list_merge_fields[ $field['name'] ] = $field['tag'];
			} else {
				Logger::log(
					sprintf(
						// Translators: %1$s is the merge field name, %2$s is the field's unique tag.
						__( 'Warning: Duplicate merge field %1$s found with tag %2$s.', 'newspack-plugin' ),
						$field['name'],
						$field['tag']
					)
				);
			}
		}

		foreach ( $data as $field_name => $field_value ) {
			// If field already exists, add it to the payload.
			if ( isset( $list_merge_fields[ $field_name ] ) ) {
				$merge_fields[ $list_merge_fields[ $field_name ] ] = $data[ $field_name ];
				unset( $data[ $field_name ] );
			}
		}

		// Create remaining fields.
		$remaining_fields = array_keys( $data );
		foreach ( $remaining_fields as $field_name ) {
			$created_field = Mailchimp_API::post(
				"lists/$audience_id/merge-fields",
				[
					'name' => $field_name,
					'type' => self::get_merge_field_type( $data[ $field_name ] ),
				]
			);
			// Skip field if it failed to create.
			if ( is_wp_error( $created_field ) ) {
				Logger::log(
					sprintf(
					// Translators: %1$s is the merge field key, %2$s is the error message.
						__( 'Failed to create merge field %1$s. Error response: %2$s', 'newspack-plugin' ),
						$field_name,
						$created_field->get_error_message() ?? __( 'The connected ESP could not create this merge field.', 'newspack-plugin' )
					)
				);
				continue;
			}
			Logger::log(
				sprintf(
					// Translators: %1$s is the merge field key, %2$s is the error message.
					__( 'Created merge field %1$s.', 'newspack-plugin' ),
					$field_name
				)
			);
			$merge_fields[ $created_field['tag'] ] = $data[ $field_name ];
		}

		return $merge_fields;
	}

	/**
	 * Update a Mailchimp contact
	 *
	 * @param array $contact Contact info to sync to ESP without lists.
	 *
	 * @return array|WP_Error response body or error.
	 */
	public static function put( $contact ) {
		$audience_id = self::get_audience_id();
		if ( ! $audience_id ) {
			return;
		}
		$hash    = md5( strtolower( $contact['email'] ) );
		$payload = [
			'email_address' => $contact['email'],
			'status_if_new' => self::get_default_reader_status(),
		];

		// Normalize contact metadata.
		$contact = Newspack_Newsletters::normalize_contact_data( $contact );
		if ( ! empty( $contact['metadata'] ) ) {
			$merge_fields = self::get_merge_fields( $audience_id, $contact['metadata'] );
			if ( ! empty( $merge_fields ) ) {
				$payload['merge_fields'] = $merge_fields;
			}
		}

		Logger::log(
			'Syncing contact with metadata key(s): ' . implode( ', ', array_keys( $contact['metadata'] ) ) . '.',
			Data_Events::LOGGER_HEADER
		);

		// Upsert the contact.
		return Mailchimp_API::put(
			"lists/$audience_id/members/$hash",
			$payload
		);
	}
}
new Mailchimp();

<?php
/**
 * Image credits for media library items.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Meta_Query;

/**
 * Newspack_Image_Credits class.
 */
class Newspack_Image_Credits {

	/**
	 * Meta key for the credit.
	 */
	const MEDIA_CREDIT_META = '_media_credit';

	/**
	 * Meta key for the credit URL.
	 */
	const MEDIA_CREDIT_URL_META = '_media_credit_url';

	/**
	 * Meta key for the credit organization.
	 */
	const MEDIA_CREDIT_ORG_META = '_navis_media_credit_org';

	/**
	 * Meta key for whether the image is distributable.
	 */
	const MEDIA_CREDIT_CAN_DISTRIBUTE_META = '_navis_media_can_distribute';

	/**
	 * Hook actions and filters.
	 */
	public static function init() {
		add_filter( 'attachment_fields_to_save', [ __CLASS__, 'save_media_credit' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'add_media_credit' ], 10, 2 );
		add_filter( 'get_the_excerpt', [ __CLASS__, 'add_credit_to_attachment_excerpts' ], 10, 2 );
		add_filter( 'render_block', [ __CLASS__, 'add_credit_to_image_block' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ __CLASS__, 'maybe_show_placeholder_image' ], 11, 4 );
		// phpcs:ignore
		// add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'filter_ajax_query_attachments' ] );
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'populate_credit' ], 10, 3 );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
	}

	/**
	 * Get media credit info for an attachment.
	 *
	 * @param int $attachment_id Post ID of the attachment.
	 * @return array Credit info. See $output at the top of this method.
	 */
	public static function get_media_credit( $attachment_id ) {
		$output = [
			'id'             => $attachment_id,
			'credit'         => '',
			'credit_url'     => '',
			'organization'   => '',
			'can_distribute' => false,
		];

		$credit = get_post_meta( $attachment_id, self::MEDIA_CREDIT_META, true );
		if ( $credit ) {
			$output['credit'] = esc_attr( $credit );
		}

		$credit_url = get_post_meta( $attachment_id, self::MEDIA_CREDIT_URL_META, true );
		if ( $credit_url ) {
			$output['credit_url'] = esc_attr( $credit_url );
		}

		$organization = get_post_meta( $attachment_id, self::MEDIA_CREDIT_ORG_META, true );
		if ( $organization ) {
			$output['organization'] = esc_attr( $organization );
		}

		$can_distribute = get_post_meta( $attachment_id, self::MEDIA_CREDIT_CAN_DISTRIBUTE_META, true );
		if ( $can_distribute ) {
			$output['can_distribute'] = true;
		}

		/**
		 * Filter for the media credit data from this plugin.
		 * Allows third-party code to hook into and modify the credits info if needed.
		 *
		 * @param array $output Credit info for the attachment.
		 * @param int   $attachment_id ID of the attachment whose credit is being filtered.
		 */
		return apply_filters( 'newspack_image_credits_media_credit', $output, $attachment_id );
	}


	/**
	 * Parse Media Credit Meta Attributes
	 *
	 * @param int   $attachment_id Post ID of the attachment.
	 * @param array $credit_attrs Array of credit properties to parse.
	 * @return array Credit info. See $output at the top of this method.
	 */
	public static function parse_media_credit_attrs( $attachment_id, $credit_attrs ) {
		/**
		 * Filter for the media credit data from this plugin.
		 * Allows third-party code to hook into and modify the credits info if needed.
		 *
		 * @param array $output Credit info for the attachment.
		 * @param int   $attachment_id ID of the attachment whose credit is being filtered.
		 */
		return apply_filters(
			'newspack_image_credits_media_credit',
			[
				'id'             => $attachment_id,
				'credit'         => esc_html( $credit_attrs[ static::MEDIA_CREDIT_META ] ?? '' ),
				'credit_url'     => esc_url( $credit_attrs[ static::MEDIA_CREDIT_URL_META ] ?? '' ),
				'organization'   => esc_html( $credit_attrs[ static::MEDIA_CREDIT_ORG_META ] ?? '' ),
				'can_distribute' => ( $credit_attrs[ static::MEDIA_CREDIT_CAN_DISTRIBUTE_META ] ?? '' ) === '1',
			],
			$attachment_id
		);
	}

	/**
	 * Get credit info as an HTML string.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $meta_attributes Array of credit properties to parse.
	 * @return string The credit ready for output.
	 */
	public static function get_media_credit_string( $attachment_id, $meta_attributes = [] ) {
		$credit_info = empty( $meta_attributes ) ? self::get_media_credit( $attachment_id ) : self::parse_media_credit_attrs( $attachment_id, $meta_attributes );
		if ( ! $credit_info['credit'] ) {
			return '';
		}

		$credit = $credit_info['credit'];
		if ( $credit_info['organization'] ) {
			$credit .= ' / ' . $credit_info['organization'];
		}

		if ( $credit_info['credit_url'] ) {
			$credit = '<a href="' . $credit_info['credit_url'] . '">' . $credit . '</a>';
		}

		$class_name    = self::get_settings( 'newspack_image_credits_class_name' );
		$credit_prefix = self::get_settings( 'newspack_image_credits_prefix_label' );
		$credit_label  = ! empty( $credit_prefix ) ? sprintf( '<span class="credit-label-wrapper">%1$s</span> ', $credit_prefix ) : '';

		$credit = sprintf(
			'<span%1$s>%2$s%3$s</span>',
			! empty( $class_name ) ? sprintf( ' class="%s"', esc_attr( $class_name ) ) : '',
			$credit_label,
			$credit
		);

		return wp_kses_post( $credit );
	}

	/**
	 * Save the media credit info.
	 *
	 * @param array $post Array of post info.
	 * @param array $attachment Array of media field input info.
	 * @return array $post Unmodified post info.
	 */
	public static function save_media_credit( $post, $attachment ) {
		if ( isset( $attachment['media_credit'] ) ) {
			update_post_meta( $post['ID'], self::MEDIA_CREDIT_META, sanitize_text_field( $attachment['media_credit'] ) );
		}

		if ( isset( $attachment['media_credit_url'] ) ) {
			update_post_meta( $post['ID'], self::MEDIA_CREDIT_URL_META, sanitize_text_field( $attachment['media_credit_url'] ) );
		}

		if ( isset( $attachment['media_credit_org'] ) ) {
			update_post_meta( $post['ID'], self::MEDIA_CREDIT_ORG_META, sanitize_text_field( $attachment['media_credit_org'] ) );
		}

		if ( isset( $attachment['media_can_distribute'] ) ) {
			update_post_meta( $post['ID'], self::MEDIA_CREDIT_CAN_DISTRIBUTE_META, (bool) $attachment['media_can_distribute'] );
		}

		return $post;
	}

	/**
	 * Add media credit fields to the media editor.
	 *
	 * @param array   $fields Array of media editor field info.
	 * @param WP_Post $post Post object for current attachment.
	 * @return array Modified $fields.
	 */
	public static function add_media_credit( $fields, $post ) {
		$credit_info            = self::get_media_credit( $post->ID );
		$fields['media_credit'] = [
			'label' => __( 'Credit', 'newspack-image-credits' ),
			'input' => 'text',
			'value' => $credit_info['credit'],
		];

		$fields['media_credit_url'] = [
			'label' => __( 'Credit URL', 'newspack-image-credits' ),
			'input' => 'text',
			'value' => $credit_info['credit_url'],
		];

		$fields['media_credit_org'] = [
			'label' => __( 'Organization', 'newspack-image-credits' ),
			'input' => 'text',
			'value' => $credit_info['organization'],
		];

		$distfield                      = 'attachments[' . $post->ID . '][media_can_distribute]';
		$fields['media_can_distribute'] = [
			'label' => __( 'Can distribute?', 'newspack-image-credits' ),
			'input' => 'html',
			'html'  => '<input id="' . $distfield . '" name="' . $distfield . '" type="hidden" value="0" /><input id="' . $distfield . '" name="' . $distfield . '" type="checkbox" value="1" ' . checked( $credit_info['can_distribute'], true, false ) . ' />',
		];

		return $fields;
	}

	/**
	 * Add media credit to attachment excerpts (captions) when possible.
	 *
	 * @param string  $excerpt Attachment excerpt/caption.
	 * @param WP_Post $post Post object.
	 * @return string Modified $excerpt.
	 */
	public static function add_credit_to_attachment_excerpts( $excerpt, $post ) {
		if ( 'attachment' !== $post->post_type ) {
			return $excerpt;
		}

		$credit_string = self::get_media_credit_string( $post->ID );
		if ( $excerpt && $credit_string ) {
			return $excerpt . ' ' . $credit_string;
		} elseif ( $credit_string ) {
			return $credit_string;
		} else {
			return $excerpt;
		}
	}

	/**
	 * Add media credit to image blocks when possible.
	 *
	 * @param string $block_output HTML block output.
	 * @param array  $block Raw block info.
	 * @return string Modified $block_output.
	 */
	public static function add_credit_to_image_block( $block_output, $block ) {
		// Core image blocks.
		if ( 'core/image' === $block['blockName'] && ! empty( $block['attrs']['id'] ) ) {
			$credit_string = self::get_media_credit_string( $block['attrs']['id'] );

			// If there's no credit, show placeholder image, if any.
			if ( ! $credit_string ) {
				if ( empty( $block['attrs']['meta'][ static::MEDIA_CREDIT_META ] ) ) {
					$size         = ! empty( $block['attrs']['sizeSlug'] ) ? $block['attrs']['sizeSlug'] : null;
					$block_output = self::maybe_show_placeholder_image_in_block( $block_output, $size );
					return $block_output;
				}
				$credit_string = self::get_media_credit_string( $block['attrs']['id'], $block['attrs']['meta'] );
			}

			if ( strpos( $block_output, '</figcaption>' ) ) {
				// If an image caption exists, add the credit to it.
				$block_output = str_replace( '</figcaption>', ' ' . $credit_string . '</figcaption>', $block_output );
			} else {
				// If an image caption doesn't exist, make the credit the caption.
				$block_output = str_replace( '</figure>', '<figcaption>' . $credit_string . '</figcaption></figure>', $block_output );
			}
		}

		// Jetpack Slideshow blocks. Append credit to each slide caption.
		if ( 'jetpack/slideshow' === $block['blockName'] && ! empty( $block['attrs']['ids'] ) ) {
			$credit_strings = array_map(
				function( $image_id ) {
					return self::get_media_credit_string( $image_id );
				},
				array_values( $block['attrs']['ids'] )
			);

			$index        = -1;
			$block_output = preg_replace_callback(
				'/<figure>(.*?)<\/figure>/',
				function( $matches ) use ( &$credit_strings, &$index ) {
					$index++;
					$replacement = $matches[0];

					if ( empty( $credit_strings[ $index ] ) ) {
						$size        = ! empty( $block['attrs']['sizeSlug'] ) ? $block['attrs']['sizeSlug'] : null;
						$replacement = self::maybe_show_placeholder_image_in_block( $replacement, $size );
						return $replacement;
					}

					if ( strpos( $replacement, '</figcaption>' ) ) {
						// If an image caption exists, add the credit to it.
						$replacement = str_replace( '</figcaption>', ' ' . $credit_strings[ $index ] . '</figcaption>', $replacement );
					} else {
						// If an image caption doesn't exist, make the credit the caption.
						$replacement = str_replace( '</figure>', '<figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">' . $credit_strings[ $index ] . '</figcaption></figure>', $replacement );
					}

					return $replacement;
				},
				$block_output
			);
		}

		return $block_output;
	}

	/**
	 * Given an `<img />` tag with `src` attribute, replace the src with the placeholder image.
	 *
	 * @param string $block_output Content string containing `<img />` tag.
	 * @param string $size Size of the placeholder image to fetch.
	 *
	 * @return string Content string but with `src` attribute replace by placeholder image src.
	 */
	public static function maybe_show_placeholder_image_in_block( $block_output, $size = 'full' ) {
		$placeholder_image = self::get_settings( 'newspack_image_credits_placeholder' );

		if ( $placeholder_image ) {
			$block_output = preg_replace_callback(
				'/src="(.*?)"/',
				function( $matches ) use ( $placeholder_image, $size ) {
					$img_src         = $matches[1];
					$placeholder_src = wp_get_attachment_image_url( $placeholder_image, $size );
					if ( $placeholder_src ) {
						$img_src = $placeholder_src;
					}
					return 'src="' . $img_src . '"';
				},
				$block_output
			);
		}

		return $block_output;
	}

	/**
	 * For featured images and classic attachments. If no credit, show the placeholder image instead.
	 *
	 * @param array      $image_data Array of image data, or boolean false if no image is available.
	 * @param int        $attachment_id Image attachment ID.
	 * @param string|int $size Requested image size. Can be any registered image size name, or an array of width and height values in pixels (in that order).
	 * @param boolean    $icon Whether the image should fall back to a mime type icon.
	 *
	 * @return array Filtered array of image data.
	 */
	public static function maybe_show_placeholder_image( $image_data, $attachment_id, $size, $icon ) {
		$media_credit      = get_post_meta( $attachment_id, self::MEDIA_CREDIT_META, true );
		$placeholder_image = self::get_settings( 'newspack_image_credits_placeholder' );

		if ( ! empty( $media_credit ) || empty( $placeholder_image ) ) {
			return $image_data;
		}

		$site_icon       = get_option( 'site_icon' );
		$custom_logo     = get_theme_mod( 'custom_logo' );
		$custom_logo_alt = get_theme_mod( 'newspack_alternative_logo', '' );
		$is_placeholder  = intval( $placeholder_image ) === intval( $attachment_id );
		$is_logo         = intval( $site_icon ) === intval( $attachment_id ) || intval( $custom_logo ) === intval( $attachment_id ) || intval( $custom_logo_alt ) === intval( $attachment_id );

		if ( ! $is_placeholder && ! $is_logo ) {
			$placeholder_data = wp_get_attachment_image_src( $placeholder_image, $size, $icon );
			return $placeholder_data;
		}

		return $image_data;
	}

	/**
	 * Default values for site-wide settings.
	 *
	 * @param string|null $key (Optional) Key name of a single setting to get. If not given, will return all settings.
	 *
	 * @return array Array of default settings.
	 */
	public static function get_default_settings( $key = null ) {
		$default_settings = [
			[
				'description' => __( 'A CSS class name to be applied to all image credit elements. Leave blank to display no class name.', 'newspack-image-credits' ),
				'key'         => 'newspack_image_credits_class_name',
				'label'       => __( 'Image Credit Class Name', 'newspack-image-credits' ),
				'type'        => 'input',
				'value'       => __( 'image-credit', 'newspack-image-credits' ),
			],
			[
				'description' => __( 'A label to prefix all image credits. Leave blank to display no prefix.', 'newspack-image-credits' ),
				'key'         => 'newspack_image_credits_prefix_label',
				'label'       => __( 'Image Credit Label', 'newspack-image-credits' ),
				'type'        => 'input',
				'value'       => __( 'Credit:', 'newspack-image-credits' ),
			],
			[
				'description' => __( 'A placeholder image to be displayed in place of images without credits. If none is chosen, the image will be displayed normally whether or not it has a credit.', 'newspack-image-credits' ),
				'key'         => 'newspack_image_credits_placeholder',
				'label'       => __( 'Placeholder Image', 'newspack-image-credits' ),
				'type'        => 'image',
				'value'       => '',
			],
			[
				'description' => __( 'Automatically populate image credits from EXIF or IPTC metadata when uploading new images.', 'newspack-image-credits' ),
				'key'         => 'newspack_image_credits_auto_populate',
				'label'       => __( 'Auto-populate image credits', 'newspack-image-credits' ),
				'type'        => 'boolean',
				'value'       => false,
			],
		];

		if ( $key ) {
			$setting = array_filter(
				$default_settings,
				function( $setting ) use ( $key ) {
					return $setting['key'] === $key;
				}
			);
			$settings_values = array_values( $setting );
			return reset( $settings_values );
		}

		return $default_settings;
	}

	/**
	 * Get current site-wide settings, or defaults if not set.
	 *
	 * @param string|null $option (Optional) Key name of a single setting to get. If not given, will return all settings.
	 * @param boolean     $get_default (Optional) If true, return the default value.
	 *
	 * @return array|boolean Array of current site-wide settings, or false if returning a single option with no value.
	 */
	public static function get_settings( $option = null, $get_default = false ) {
		$defaults = self::get_default_settings();

		$settings = array_reduce(
			$defaults,
			function( $acc, $setting ) use ( $get_default ) {
				$key         = $setting['key'];
				$value       = $get_default ? $setting['value'] : get_theme_mod( $key, $setting['value'] );
				$acc[ $key ] = $value;
				return $acc;
			},
			[]
		);

		// If passed an option key name, just give that option.
		if ( ! empty( $option ) ) {
			return $settings[ $option ];
		}

		// Otherwise, return all settings.
		return $settings;
	}

	/**
	 * Update option values.
	 *
	 * @param string $key Name of the option to update.
	 * @param mixed  $value Updated value.
	 */
	public static function update_setting( $key, $value ) {
		$default_setting = self::get_default_settings( $key );
		if ( ! isset( $default_setting['value'] ) ) {
			return;
		}

		set_theme_mod( $key, self::sanitize_option_value( $value ) );
	}

	/**
	 * Sanitize option values. Must be either int or sanitized text string.
	 *
	 * @param int|string $value Inputted option value.
	 *
	 * @return int|string Sanitized value.
	 */
	public static function sanitize_option_value( $value ) {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Prepare the query filters to include metadata to the search query
	 *
	 * This hook runs only in the ajax call for the media library, and will only do anything if it's a search query.
	 *
	 * It will not modify the query, but register the hooks that will act on the query later on
	 *
	 * @param array $query The query parameters.
	 * @return array
	 */
	public static function filter_ajax_query_attachments( $query ) {
		if ( empty( $query['s'] ) ) {
			return $query;
		}
		add_filter( 'posts_clauses', [ __CLASS__, 'filter_posts_clauses' ], 10, 2 );
		return $query;
	}

	/**
	 * Filter posts query clauses to include media credit meta search.
	 *
	 * @param array     $clauses The current query clauses.
	 * @param \WP_Query $query The current WP_Query object.
	 *
	 * @return array
	 */
	public static function filter_posts_clauses( $clauses, $query ) {
		if ( empty( $query->get( 's' ) ) ) {
			return $clauses;
		}
		global $wpdb;
		// Fetch post IDs that have the search term in the media credit meta.
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key IN ( '_media_credit', '_media_credit_url', '_navis_media_credit_org' ) AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $query->get( 's' ) ) . '%'
			)
		);
		if ( empty( $post_ids ) ) {
			return $clauses;
		}
		// Add the post IDs to the search query.
		$post_ids          = array_map( 'absint', $post_ids );
		$clauses['where'] .= " OR ( {$wpdb->posts}.ID IN ( " . implode( ',', $post_ids ) . ' ) )';
		return $clauses;
	}

	/**
	 * When a new image is uploaded, check if it has a credit in the EXIF/IPTC data and save it.
	 *
	 * @param array  $metadata      An array of attachment meta data.
	 * @param int    $attachment_id Current attachment ID.
	 * @param string $context       Additional context. Can be 'create' when metadata was initially created for new attachment
	 *                              or 'update' when the metadata was updated.
	 * @return array
	 */
	public static function populate_credit( $metadata, $attachment_id, $context ) {
		if ( 'create' !== $context || ! self::get_settings( 'newspack_image_credits_auto_populate' ) ) {
			return $metadata;
		}

		if ( ! empty( $metadata['image_meta']['credit'] ) ) {
			update_post_meta( $attachment_id, self::MEDIA_CREDIT_META, $metadata['image_meta']['credit'] );
		}

		return $metadata;
	}

	/**
	 * Register _media_credit* meta fields. Required for displaying in relevant REST endpoints.
	 */
	public static function register_meta() {
		foreach ( [
			static::MEDIA_CREDIT_META,
			static::MEDIA_CREDIT_URL_META,
			static::MEDIA_CREDIT_ORG_META,
			static::MEDIA_CREDIT_CAN_DISTRIBUTE_META,
		]
		as $meta ) {
			register_meta(
				'post',
				$meta,
				[
					'object_subtype' => 'attachment',
					'type'           => 'string',
					'single'         => true,
					'show_in_rest'   => true,
				]
			);
		}
	}
}
Newspack_Image_Credits::init();

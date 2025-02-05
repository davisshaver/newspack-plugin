<?php
/**
 * Newspack Corrections and Clarifications
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class to handle Corrections and Clarifications.
 */
class Corrections {
	/**
	 * Post type for corrections.
	 */
	const POST_TYPE = 'newspack_correction';

	/**
	 * Meta key for correction post ID meta.
	 */
	const CORRECTION_POST_ID_META = 'newspack_correction-post-id';

	/**
	 * Meta key for post corrections active meta.
	 */
	const CORRECTIONS_ACTIVE_META = 'newspack_corrections_active';

	/**
	 * Meta key for post corrections location meta.
	 */
	const CORRECTIONS_LOCATION_META = 'newspack_corrections_location';

	/**
	 * Meta key for post corrections type meta.
	 */
	const CORRECTIONS_TYPE_META = 'newspack_corrections_type';

	/**
	 * Supported post types.
	 */
	const SUPPORTED_POST_TYPES = [ 'article_legacy', 'content_type_blog', 'post', 'press_release' ];

	/**
	 * REST namespace and route for corrections.
	 */
	const REST_NAMESPACE = 'newspack/v1';
	const REST_ROUTE     = '/corrections';

	/**
	 * Initializes the class.
	 */
	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'init', [ __CLASS__, 'add_corrections_shortcode' ] );
		add_filter( 'the_content', [ __CLASS__, 'output_corrections_on_post' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'wp_enqueue_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'wp_enqueue_scripts' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Checks if the feature is enabled.
	 *
	 * True when:
	 * - NEWSPACK_CORRECTIONS_ENABLED is defined and true.
	 *
	 * @return bool True if the feature is enabled, false otherwise.
	 */
	public static function is_enabled() {
		return defined( 'NEWSPACK_CORRECTIONS_ENABLED' ) && NEWSPACK_CORRECTIONS_ENABLED;
	}


	/**
	 * Enqueue scripts and styles.
	 */
	public static function wp_enqueue_scripts() {
		if ( ! is_admin() || ! filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT ) ) {
			return;
		}

		\wp_enqueue_script(
			'newspack-corrections-modal',
			Newspack::plugin_url() . '/dist/other-scripts/corrections-modal.js',
			[ 'wp-edit-post', 'wp-data', 'wp-components', 'wp-element' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'newspack-corrections-modal',
			'NewspackCorrectionsData',
			[
				'corrections' => self::get_corrections( get_the_ID() ),
				'restUrl'     => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
			]
		);

		\wp_enqueue_style(
			'newspack-corrections-modal',
			Newspack::plugin_url() . '/dist/other-scripts/corrections-modal.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);
	}

	/**
	 * Registers the corrections post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$supports = [
			'author',
			'editor',
			'title',
			'revisions',
			'custom-fields',
		];
		$labels = [
			'name'                     => _x( 'Corrections', 'post type general name', 'newspack-plugin' ),
			'singular_name'            => _x( 'Correction', 'post type singular name', 'newspack-plugin' ),
			'menu_name'                => _x( 'Corrections', 'admin menu', 'newspack-plugin' ),
			'name_admin_bar'           => _x( 'Correction', 'add new on admin bar', 'newspack-plugin' ),
			'add_new'                  => _x( 'Add New', 'correction', 'newspack-plugin' ),
			'add_new_item'             => __( 'Add New Correction', 'newspack-plugin' ),
			'new_item'                 => __( 'New Correction', 'newspack-plugin' ),
			'edit_item'                => __( 'Edit Correction', 'newspack-plugin' ),
			'view_item'                => __( 'View Correction', 'newspack-plugin' ),
			'view_items'               => __( 'View Correction', 'newspack-plugin' ),
			'all_items'                => __( 'All Corrections', 'newspack-plugin' ),
			'search_items'             => __( 'Search Corrections', 'newspack-plugin' ),
			'parent_item_colon'        => __( 'Parent Correction:', 'newspack-plugin' ),
			'not_found'                => __( 'No corrections found.', 'newspack-plugin' ),
			'not_found_in_trash'       => __( 'No corrections found in Trash.', 'newspack-plugin' ),
			'archives'                 => __( 'Correction Archives', 'newspack-plugin' ),
			'attributes'               => __( 'Correction Attributes', 'newspack-plugin' ),
			'insert_into_item'         => __( 'Insert into correction', 'newspack-plugin' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this correction', 'newspack-plugin' ),
			'filter_items_list'        => __( 'Filter corrections list', 'newspack-plugin' ),
			'items_list_navigation'    => __( 'Corrections list navigation', 'newspack-plugin' ),
			'items_list'               => __( 'Corrections list', 'newspack-plugin' ),
			'item_published'           => __( 'Correction published.', 'newspack-plugin' ),
			'item_published_privately' => __( 'Correction published privately.', 'newspack-plugin' ),
			'item_reverted_to_draft'   => __( 'Correction reverted to draft.', 'newspack-plugin' ),
			'item_scheduled'           => __( 'Correction scheduled.', 'newspack-plugin' ),
			'item_updated'             => __( 'Correction updated.', 'newspack-plugin' ),
			'item_link'                => __( 'Correction Link', 'newspack-plugin' ),
			'item_link_description'    => __( 'A link to a correction.', 'newspack-plugin' ),
		];
		$args = array(
			'labels'           => $labels,
			'description'      => 'Post type used to store corrections and clarifications.',
			'has_archive'      => true,
			'public'           => true,
			'public_queryable' => true,
			'query_var'        => true,
			'rewrite'          => [ 'slug' => 'corrections' ],
			'show_ui'          => false,
			'show_in_rest'     => true,
			'supports'         => $supports,
			'taxonomies'       => [],
			'menu_icon'        => 'dashicons-edit',
		);
		\register_post_type( self::POST_TYPE, $args );

		$rewrite_rules_updated_option_name = 'newspack_corrections_rewrite_rules_updated';
		if ( get_option( $rewrite_rules_updated_option_name ) !== true ) {
			flush_rewrite_rules(); //phpcs:ignore
			update_option( $rewrite_rules_updated_option_name, true );
		}
	}

	/**
	 * Register REST route for corrections.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE . '/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'rest_save_corrections' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * REST endpoint to save corrections.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response The REST response.
	 */
	public static function rest_save_corrections( WP_REST_Request $request ) {
		$post_id     = $request->get_param( 'post_id' );
		$corrections = $request->get_param( 'corrections' );

		if ( ! get_post( $post_id ) ) {
			return rest_ensure_response( new WP_Error( 'invalid_post_id', 'Invalid post ID.', [ 'status' => 400 ] ) );
		}

		$existing_corrections = self::get_corrections( $post_id );
		$existing_ids         = wp_list_pluck( $existing_corrections, 'ID' );

		// Track processed corrections to handle deletions.
		$processed_ids = [];
	
		foreach ( $corrections as $correction ) {
			$correction_id = $correction['id'];

			if ( empty( $correction['content'] ) ) {
				continue;
			}

			// ID will be null if it's a new correction.
			if ( ! empty( $correction_id ) ) {
				// Update existing correction.
				self::update_correction( $correction_id, $correction );
				$processed_ids[] = $correction_id;
			} else {
				// Create new correction.
				$new_correction_id = self::add_correction( $post_id, $correction );
				if ( ! is_wp_error( $new_correction_id ) ) {
					$processed_ids[] = $new_correction_id;
				}
			}
		}

		// Delete corrections that are no longer present.
		$to_delete = array_diff( $existing_ids, $processed_ids );
		self::delete_corrections( $post_id, $to_delete );

		return rest_ensure_response(
			[
				'success'           => true,
				'corrections_saved' => $processed_ids, 
			]
		);
	}

	/**
	 * Save corrections for post.
	 *
	 * @param int   $post_id    The post ID.
	 * @param array $correction The corrections.
	 */
	public static function add_correction( $post_id, $correction ) {
		$id = wp_insert_post(
			[
				'post_title'   => sprintf( 'Correction for %s', get_the_title( $post_id ) ),
				'post_content' => sanitize_textarea_field( $correction['content'] ),
				'post_date'    => sanitize_text_field( $correction['date'] ),
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'meta_input'   => [
					self::CORRECTION_POST_ID_META => $post_id,
					self::CORRECTIONS_TYPE_META   => $correction['type'],
				],
			]
		);

		return $id;
	}

	/**
	 * Get corrections for post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The corrections.
	 */
	public static function get_corrections( $post_id ) {
		$corrections = get_posts(
			[
				'posts_per_page' => -1,
				'post_type'      => self::POST_TYPE,
				'meta_key'       => self::CORRECTION_POST_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// Attach correction type & date to each post.
		foreach ( $corrections as $correction ) {
			$correction->correction_type = get_post_meta( $correction->ID, self::CORRECTIONS_TYPE_META, true );
			$correction->correction_date = get_post_datetime( $correction->ID )->format( 'Y-m-d H:i:s' );
		}

		return $corrections;
	}

	/**
	 * Update correction.
	 *
	 * @param int   $correction_id the post id.
	 * @param array $correction    the correction.
	 */
	public static function update_correction( $correction_id, $correction ) {
		wp_update_post(
			[
				'ID'           => $correction_id,
				'post_content' => sanitize_textarea_field( $correction['content'] ),
				'post_date'    => sanitize_text_field( $correction['date'] ),
				'meta_input'   => [
					self::CORRECTIONS_TYPE_META => $correction['type'],
				],
			]
		);
	}

	/**
	 * Delete corrections for post.
	 *
	 * @param int   $post_id        the post id.
	 * @param array $correction_ids correction ids.
	 */
	public static function delete_corrections( $post_id, $correction_ids ) {
		foreach ( $correction_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Adds the corrections shortcode.
	 */
	public static function add_corrections_shortcode() {
		add_shortcode( 'corrections', [ __CLASS__, 'handle_corrections_shortcode' ] );
	}

	/**
	 * Gets the Correction type label for a given post. Defaults to the current global post if none is provided.
	 *
	 * @param int $post_id The correction id.
	 * @return string The correction type label.
	 */
	public static function get_correction_type( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		return self::get_correction_type_label( get_post_meta( $post_id, self::CORRECTIONS_TYPE_META, true ) );
	}

	/**
	 * Gets the correction type label.
	 *
	 * @param string $type the correction type.
	 * @return string the correction type label.
	 */
	private static function get_correction_type_label( $type ) {
		if ( 'clarification' === $type ) {
			return __( 'Clarification', 'newspack-plugin' );
		}
		return __( 'Correction', 'newspack-plugin' );
	}

	/**
	 * Handles the corrections shortcode.
	 *
	 * @return string the shortcode output.
	 */
	public static function handle_corrections_shortcode() {
		global $wpdb;

		$post_ids = get_posts(
			[
				'posts_per_page' => -1,
				'meta_key'       => self::CORRECTIONS_ACTIVE_META,
				'meta_value'     => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		ob_start();
		foreach ( $post_ids as $post_id ) :
			$corrections = self::get_corrections( $post_id );
			if ( empty( $corrections ) ) {
				continue;
			}

			?>
			<!-- wp:group {"className":"is-style-default correction-shortcode-item"} -->
			<div class="wp-block-group is-style-default correction-shortcode-item">
				<div class="wp-block-group__inner-container">
					<!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"showDate":false,"showAuthor":false,"mediaPosition":"left","specificPosts":["<?php echo intval( $post_id ); ?>"],"imageScale":2,"specificMode":true} /-->

					<div class="correction-list">
						<?php
						foreach ( $corrections as $correction ) :
							$correction_content = $correction->post_content;
							$correction_date    = \get_the_date( 'M j, Y', $correction->ID );
							$correction_heading = sprintf(
								// translators: %s: correction date.
								__( 'Correction on %s', 'newspack-plugin' ),
								$correction_date
							);
							?>
							<p>
								<span class="correction-date"><?php echo esc_html( $correction_heading ); ?><span>:</span></span>
								<?php echo esc_html( $correction_content ); ?>
							</p>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<!-- /wp:group -->
			<?php
		endforeach;
		return do_blocks( ob_get_clean() );
	}

	/**
	 * Outputs corrections on the post content.
	 *
	 * @param string $content the post content.
	 *
	 * @return string the post content with corrections.
	 */
	public static function output_corrections_on_post( $content ) {
		if ( is_admin() || ! is_single() ) {
			return $content;
		}

		if ( 0 == get_post_meta( get_the_ID(), self::CORRECTIONS_ACTIVE_META, true ) ) {
			return $content;
		}

		$corrections = self::get_corrections( get_the_ID() );
		if ( empty( $corrections ) ) {
			return $content;
		}

		ob_start();
		?>
		<!-- wp:group {"className":"correction-module","backgroundColor":"light-gray"} -->
		<div class="wp-block-group correction-module has-light-gray-background-color has-background">
			<div class="wp-block-group__inner-container">
			<?php
			foreach ( $corrections as $correction ) :
				$correction_content = $correction->post_content;
				$correction_date    = \get_the_date( get_option( 'date_format' ), $correction->ID );
				$correction_time    = \get_the_time( get_option( 'time_format' ), $correction->ID );
				$correction_heading = sprintf(
					'%s, %s %s',
					self::get_correction_type_label( get_post_meta( $correction->ID, self::CORRECTIONS_TYPE_META, true ) ),
					$correction_date,
					$correction_time
				);
				?>
				<!-- wp:paragraph {"fontSize":"small"} -->
				<p class="has-small-font-size correction-heading"><?php echo esc_html( $correction_heading ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"fontSize":"normal"} -->
				<p class="has-normal-font-size correction-body"><?php echo esc_html( $correction_content ); ?></p>
				<!-- /wp:paragraph -->
			<?php endforeach; ?>
			</div>
		</div>
		<!-- /wp:group -->
		<?php
		$markup = do_blocks( ob_get_clean() );
		return 'top' === get_post_meta( get_the_ID(), self::CORRECTIONS_LOCATION_META, true ) ? $markup . $content : $content . $markup;
	}
}
Corrections::init();

<?php
/**
 * Patches for known issues affecting Newspack sites.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Patches {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'redirect_canonical', [ __CLASS__, 'patch_redirect_canonical' ], 10, 2 );
		add_filter( 'wpseo_enhanced_slack_data', [ __CLASS__, 'use_cap_for_slack_preview' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_reusable_blocks_menu_link' ] );
	}

	/**
	 * Patches an issue where links to the site's homepage containing a query param with plus characters can cause
	 * a redirect loop with certain types of cacheing. The most common example of this ocurs when a
	 * newsletter uses a utm_campaign parameter that contains a space, e.g. utm_campaign=my+campaign
	 * The causes a redirect loop on the homepage, which often causes the page to fail to load on mobile.
	 *
	 * Related: https://core.trac.wordpress.org/ticket/51733#ticket.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $requested_url The requested URL.
	 *
	 * @return bool Should redirect be allowed.
	 */
	public static function patch_redirect_canonical( $redirect_url, $requested_url ) {
		$parsed_redirect  = wp_parse_url( $redirect_url );
		$parsed_requested = wp_parse_url( $requested_url );

		// Scheme changed, do redirect.
		if ( $parsed_requested['scheme'] !== $parsed_redirect['scheme'] ) {
			return $redirect_url;
		}

		// Host changed, do redirect.
		if ( $parsed_requested['host'] !== $parsed_redirect['host'] ) {
			return $redirect_url;
		}

		// Path changed, do redirect.
		if ( $parsed_requested['path'] !== $parsed_redirect['path'] ) {
			return $redirect_url;
		}

		// Parse query args.
		parse_str( $parsed_redirect['query'], $query_redirect );
		parse_str( $parsed_requested['query'], $query_request );

		// Sort by keys, if the order changed.
		ksort( $query_redirect );
		ksort( $query_request );

		// If parsed query args are the same, skip redirect.
		if ( $query_redirect === $query_request ) {
			return false;
		}

		// Otherwise, do redirect.
		return $redirect_url;
	}

	/**
	 * Use the Co-Author in Slack preview metadata instead of the regular post author if needed.
	 *
	 * @param array $slack_data Array of data which will be output in twitter:data tags.
	 * @return array Modified $slack_data
	 */
	public static function use_cap_for_slack_preview( $slack_data ) {
		if ( function_exists( 'coauthors' ) && is_single() && isset( $slack_data[ __( 'Written by', 'wordpress-seo' ) ] ) ) {
			$slack_data[ __( 'Written by', 'wordpress-seo' ) ] = coauthors( null, null, null, null, false );
		}

		return $slack_data;
	}

	/**
	 * Add a menu link in WP Admin to easily edit and manage reusable blocks.
	 */
	public static function add_reusable_blocks_menu_link() {
		add_submenu_page( 'edit.php', 'manage_reusable_blocks', __( 'Reusable Blocks' ), 'read', 'edit.php?post_type=wp_block', '', 2 );  
	}
}
Patches::init();

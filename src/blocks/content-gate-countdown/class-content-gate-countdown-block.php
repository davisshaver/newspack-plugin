<?php
/**
 * Content Gate Countdown Block
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use Newspack\Memberships;
use Newspack\Memberships\Metering;

/**
 * Content Gate Countdown Block class.
 */
class Content_Gate_Countdown_Block {
	/**
	 * Initialize the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue block scripts and styles.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! Memberships::is_active() || ! is_singular() ) {
			return;
		}
		wp_enqueue_script(
			'newspack-content-gate-countdown-block',
			\Newspack\Newspack::plugin_url() . '/dist/content-gate-countdown-block.js',
			[ 'wp-i18n', 'newspack-memberships-gate-metering' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array  $attributes The block attributes.
	 * @param string $content    The block content.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content ) {
		if ( ! Metering::is_metering() || ! Memberships::is_post_restricted() ) {
			return '';
		}
		$total_views = Metering::get_total_metered_views( \is_user_logged_in() );
		if ( false === $total_views ) {
			return '';
		}
		$views     = Metering::get_metered_views( get_current_user_id() );
		$countdown = sprintf(
			/* translators: 1: current number of metered views, 2: total metered views. */
			__( '%1$d/%2$d', 'newspack-plugin' ),
			$views,
			$total_views
		);
		$text = isset( $attributes['text'] ) ? esc_html( $attributes['text'] ) : '';
		if ( empty( $text ) ) {
			$text = sprintf(
				/* translators: %s - metered content period (week, month, etc. */
				__(
					'free articles this %s',
					'newspack-plugin'
				),
				Metering::get_metering_period()
			);
		}
		$block_wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'newspack-content-gate-countdown__wrapper',
			]
		);
		$block_content = "<div $block_wrapper_attributes>
			<div class='newspack-content-gate-countdown__content'>
				<div class='newspack-content-gate-countdown__text'>
					<span class='newspack-content-gate-countdown__countdown'>$countdown</span>
					<p>$text</p>
				</div>
				$content
			</div>
		</div>";

		return $block_content;
	}
}

Content_Gate_Countdown_Block::init();

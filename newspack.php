<?php
/**
 * Plugin Name: Newspack
 * Description: An advanced open-source publishing and revenue-generating platform for news organizations.
 * Version: 6.20.1
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL2
 * Text Domain: newspack-plugin
 * Domain Path: /languages/
 *
 * @package         Newspack_Plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'NEWSPACK_PLUGIN_VERSION', '6.20.1' );

// Define NEWSPACK_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_PLUGIN_FILE', __FILE__ );
}

// Define NEWSPACK_PLUGIN_BASEDIR.
if ( ! defined( 'NEWSPACK_PLUGIN_BASEDIR' ) ) {
	define( 'NEWSPACK_PLUGIN_BASEDIR', dirname( plugin_basename( NEWSPACK_PLUGIN_FILE ) ) );
}

if ( ! defined( 'NEWSPACK_COMPOSER_ABSPATH' ) ) {
	define( 'NEWSPACK_COMPOSER_ABSPATH', dirname( NEWSPACK_PLUGIN_FILE ) . '/vendor/' );
}

require_once NEWSPACK_COMPOSER_ABSPATH . 'autoload.php';

// In our current case we know Action Scheduler is installed already.
// We might need to add back the composer import in the future.

// Include the main Newspack class.
if ( ! class_exists( 'Newspack' ) ) {
	include_once __DIR__ . '/includes/class-newspack.php';
}

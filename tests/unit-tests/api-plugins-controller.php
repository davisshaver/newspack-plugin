<?php
/**
 * Tests the plugins API functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\API\Plugins_Controller;
use Newspack\Plugin_Manager;

/**
 * Test plugin API endpoints functionality.
 */
class Newspack_Test_Plugins_Controller extends WP_UnitTestCase {

	/**
	 * Plugin slug/folder.
	 *
	 * @var string
	 */
	protected $api_namespace = '/newspack/v1';

	/**
	 * Set up stuff for testing API requests.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->administrator = $this->factory->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Test that the routes are all registered.
	 */
	public function test_register_route() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $this->api_namespace . '/plugins', $routes );
	}

	/**
	 * Test unauthorized users can't retrieve plugins info.
	 */
	public function test_get_plugins_unauthorized() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test retrieving plugins info.
	 */
	public function test_get_plugins_authorized() {
		wp_set_current_user( $this->administrator );
		$request  = new WP_REST_Request( 'GET', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jetpack', $data );

		$expected_jetpack_info = [
			'Name'        => 'Jetpack',
			'Description' => 'Bring the power of the WordPress.com cloud to your self-hosted WordPress. Jetpack enables you to connect your blog to a WordPress.com account to use the powerful features normally only available to WordPress.com users.',
			'Author'      => 'Automattic',
			'PluginURI'   => 'https://jetpack.com/',
			'AuthorURI'   => 'https://automattic.com/',
			'Download'    => 'wporg',
			'TextDomain'  => '',
			'DomainPath'  => '',
			'EditPath'    => 'admin.php?page=jetpack',
			'HandoffLink' => 'http://example.org/wp-admin/admin.php?page=jetpack',
			'Slug'        => 'jetpack',
			'Status'      => 'uninstalled',
			'Version'     => '',
		];
		$this->assertEquals( $expected_jetpack_info, $data['jetpack'] );
	}

	/**
	 * Test the schema.
	 */
	public function test_schema() {
		$request  = new WP_REST_Request( 'OPTIONS', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$schema = $response->get_data();
		$this->assertEquals( 'string', $schema['schema']['properties']['Name']['type'] );
	}
}

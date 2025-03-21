<?php
/**
 * Tests the Data Events functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;

/**
 * Tests the Data Events functionality.
 */
class Newspack_Test_Data_Events extends WP_UnitTestCase {
	/**
	 * Test registering an action.
	 */
	public function test_register_action() {
		$action_name = 'test_action';
		Data_Events::register_action( $action_name );
		$registered_actions = Data_Events::get_actions();
		$this->assertContains( $action_name, $registered_actions );
	}

	/**
	 * Test that registering an action handler without registering an action fails
	 * with WP_Error.
	 */
	public function test_register_missing_action_handler() {
		$handler = function() {};
		$result  = Data_Events::register_handler( $handler, 'missing_action' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test "is_action_registered" method.
	 */
	public function test_is_action_registered() {
		$action_name = 'test_action';
		Data_Events::register_action( $action_name );
		$this->assertTrue( Data_Events::is_action_registered( $action_name ) );
		$this->assertFalse( Data_Events::is_action_registered( 'missing_action' ) );
	}

	/**
	 * Test register action handler.
	 */
	public function test_register_action_handler() {
		$action_name = 'test_action';
		$handler     = function () {};
		Data_Events::register_action( $action_name );
		$result = Data_Events::register_handler( $handler, $action_name );
		$this->assertEquals( null, $result );
		$action_handlers = Data_Events::get_action_handlers( $action_name );
		$this->assertContains( $handler, $action_handlers );
	}

	/**
	 * Test that dispatching an action returns a WP_Http response and triggers a
	 * WP action.
	 */
	public function test_dispatch() {
		$action_name = 'test_action';
		$data        = [ 'test' => 'data' ];

		// Hook into dispatch.
		$call_count = 0;
		$hook       = function() use ( &$call_count ) {
			$call_count++;
		};
		add_action( 'newspack_data_event_dispatch', $hook, 10, 3 );

		Data_Events::register_action( $action_name );
		$result = Data_Events::dispatch( $action_name, $data );

		// Assert the hook was called once.
		$this->assertEquals( 1, $call_count );
	}

	/**
	 * Test that executing queued dispatches triggers the dispatched action hook.
	 */
	public function test_execute_queued_dispatches() {
		$action_name = 'test_action';
		$data        = [ 'test' => 'data' ];

		$hook_request = null;
		$hook_queued_dispatches = null;

		$hook = function( $request, $queued_dispatches ) use ( &$hook_request, &$hook_queued_dispatches ) {
			$hook_request = $request;
			$hook_queued_dispatches = $queued_dispatches;
		};
		add_action( 'newspack_data_events_dispatched', $hook, 10, 2 );

		Data_Events::register_action( $action_name );
		Data_Events::dispatch( $action_name, $data );
		Data_Events::execute_queued_dispatches();

		$this->assertIsArray( $hook_request );
		$this->assertIsArray( $hook_queued_dispatches );
		$this->assertEquals( $action_name, $hook_queued_dispatches[0]['action_name'] );
		$this->assertEquals( $data, $hook_queued_dispatches[0]['data'] );
	}

	/**
	 * Test triggering the handler.
	 */
	public function test_handler() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_data = [
			'called' => 0,
			'args'   => [],
		];
		$handler      = function( ...$handler_args ) use ( &$handler_data ) {
			$handler_data['called']++;
			$handler_data['args'] = $handler_args;
		};
		// Attach the handler through the Data_Events API.
		Data_Events::register_handler( $handler, $action_name );
		// Attach the handler through a WP action.
		add_action( 'newspack_data_event_test_action', $handler, 10, 3 );

		// Manual trigger.
		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		// Should have been called twice.
		$this->assertEquals( 2, $handler_data['called'] );

		// Assert args sent to handler.
		$this->assertEquals( $timestamp, $handler_data['args'][0] );
		$this->assertEquals( $data, $handler_data['args'][1] );
		$this->assertEquals( $client_id, $handler_data['args'][2] );
	}

	/**
	 * Test that a handler can throw an exception without disrupting other handler.
	 */
	public function test_handler_exception() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_called = 0;

		$handler1 = function( ...$handler_args ) use ( &$handler_called ) {
			$handler_called++;
			throw new Exception( 'Test exception' );
		};
		$handler2 = function( ...$handler_args ) use ( &$handler_called ) {
			$handler_called++;
		};

		// Attach the handlers through the Data_Events API.
		Data_Events::register_handler( $handler1, $action_name );
		Data_Events::register_handler( $handler2, $action_name );

		// Manual trigger.
		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		// Should have been called twice.
		$this->assertEquals( 2, $handler_called );
	}

	/**
	 * Test global handler execution.
	 */
	public function test_global_handler() {
		$action_name = 'test_action';

		Data_Events::register_action( $action_name );

		$handler_data = [
			'called' => 0,
			'args'   => [],
		];
		$handler      = function( ...$handler_args ) use ( &$handler_data ) {
			$handler_data['called']++;
			$handler_data['args'] = $handler_args;
		};
		Data_Events::register_handler( $handler );

		$timestamp = time();
		$data      = [ 'test' => 'data' ];
		$client_id = 'test-client-id';
		Data_Events::handle( $action_name, $timestamp, $data, $client_id );

		$this->assertEquals( 1, $handler_data['called'] );
		$this->assertEquals( $action_name, $handler_data['args'][0] );
		$this->assertEquals( $timestamp, $handler_data['args'][1] );
		$this->assertEquals( $data, $handler_data['args'][2] );
		$this->assertEquals( $client_id, $handler_data['args'][3] );
	}

	/**
	 * Test registering a listener.
	 */
	public function test_register_listener() {
		$action_name = 'test_action';
		Data_Events::register_listener( 'some_actionable_thing', $action_name );
		do_action( 'some_actionable_thing', 'data' );
		$this->assertEquals( 1, did_action( "newspack_data_event_dispatch_$action_name" ) );
	}

	/**
	 * Test registering a listener with a callable.
	 */
	public function test_register_listener_with_callable() {
		$action_name = 'test_action';
		Data_Events::register_listener(
			'some_actionable_thing',
			$action_name,
			function( $data ) {
				return $data . ' was parsed';
			}
		);

		$parsed_data = '';
		add_action(
			"newspack_data_event_dispatch_$action_name",
			function( $timestamp, $data, $client_id ) use ( &$parsed_data ) {
				$parsed_data = $data;
			},
			10,
			3
		);

		do_action( 'some_actionable_thing', 'data' );

		$this->assertEquals( 'data was parsed', $parsed_data );
	}

	/**
	 * Test registering a listener with an argument map.
	 */
	public function test_register_listener_with_map() {
		$action_name = 'test_action';
		Data_Events::register_listener(
			'some_actionable_thing',
			$action_name,
			[ 'key1', 'key2' ]
		);

		$parsed_data = [];
		add_action(
			"newspack_data_event_dispatch_$action_name",
			function( $timestamp, $data, $client_id ) use ( &$parsed_data ) {
				$parsed_data = $data;
			},
			10,
			3
		);

		do_action( 'some_actionable_thing', 'value1', 'value2' );

		$this->assertEquals(
			[
				'key1' => 'value1',
				'key2' => 'value2',
			],
			$parsed_data
		);
	}

	/**
	 * Test the current event is set and available during handler execution.
	 */
	public function test_current_event() {
		Data_Events::register_action( 'test_action' );
		Data_Events::register_action( 'test_action2' );

		$handler = function() {
			$this->assertEquals( 'test_action', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
		};
		Data_Events::register_handler( $handler, 'test_action' );
		Data_Events::handle( 'test_action', time(), [], 'test-client-id' );

		$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );

		$handler2 = function() {
			$this->assertEquals( 'test_action2', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
		};
		Data_Events::register_handler( $handler2, 'test_action2' );
		Data_Events::handle( 'test_action2', time(), [], 'test-client-id' );

		$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );
	}

	/**
	 * Test that the current event is set to null even if a handler throws an exception.
	 */
	public function test_current_event_exception() {
		Data_Events::register_action( 'test_action' );

		$handler = function() {
			$this->assertEquals( 'test_action', Data_Events::current_event(), 'Current event should be set and equal to the action name' );
			throw new Exception( 'Test exception' );
		};
		Data_Events::register_handler( $handler, 'test_action' );

		try {
			Data_Events::handle( 'test_action', time(), [], 'test-client-id' );
		} catch ( Exception $e ) {
			$this->assertNull( Data_Events::current_event(), 'Current event should be null after handling' );
		}
	}
}

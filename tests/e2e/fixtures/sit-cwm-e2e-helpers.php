<?php
/**
 * Test fixture. Loaded only by wp-env tests / bin/setup-e2e-site.php.
 *
 * A must-use plugin for the Playwright suite, never shipped (`tests/` is
 * excluded from the release zip). It captures outgoing mail instead of
 * sending it, and adds a few REST routes under `sit-cwm-e2e/v1` so the specs
 * can arrange settings and drive the cron and backstop publishing paths
 * without waiting for real time to pass. Every route requires
 * `manage_options`.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the captured mails.
 *
 * @since 1.1.0
 * @var string
 */
const SIT_CWM_E2E_MAILS_OPTION = 'sit_cwm_e2e_mails';

/**
 * Captures a mail instead of sending it.
 *
 * Hooked to `pre_wp_mail`: returning true short-circuits `wp_mail()` as a
 * successful send, so nothing leaves the test site.
 *
 * @since 1.1.0
 *
 * @param null|bool $short_circuit Earlier short-circuit value.
 * @param array     $atts          `wp_mail()` arguments.
 * @return bool Always true.
 */
function sit_cwm_e2e_capture_mail( $short_circuit, $atts ) {
	$mails = get_option( SIT_CWM_E2E_MAILS_OPTION, array() );

	if ( ! is_array( $mails ) ) {
		$mails = array();
	}

	$to = isset( $atts['to'] ) ? $atts['to'] : array();

	if ( is_string( $to ) ) {
		$to = array_map( 'trim', explode( ',', $to ) );
	}

	$mails[] = array(
		'to'      => array_values( array_filter( (array) $to ) ),
		'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
		'body'    => isset( $atts['message'] ) ? (string) $atts['message'] : '',
	);

	update_option( SIT_CWM_E2E_MAILS_OPTION, $mails, false );

	return true;
}
add_filter( 'pre_wp_mail', 'sit_cwm_e2e_capture_mail', 10, 2 );

/**
 * Whether the current user may use the fixture routes.
 *
 * @since 1.1.0
 *
 * @return bool
 */
function sit_cwm_e2e_can_use() {
	return current_user_can( 'manage_options' );
}

/**
 * Resolves the `id` route parameter to an existing post.
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_Post|WP_Error
 */
function sit_cwm_e2e_post( WP_REST_Request $request ) {
	$post = get_post( absint( $request['id'] ) );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'sit_cwm_e2e_no_post', 'No such post.', array( 'status' => 404 ) );
	}

	return $post;
}

/**
 * Runs a callback with nobody logged in, the way cron and other background
 * code run, then restores the requesting user.
 *
 * @since 1.1.0
 *
 * @param callable $callback Callback.
 * @return void
 */
function sit_cwm_e2e_as_nobody( callable $callback ) {
	$user_id = get_current_user_id();

	wp_set_current_user( 0 );

	try {
		$callback();
	} finally {
		wp_set_current_user( $user_id );
	}
}

/**
 * `GET /mails`: the captured mails, oldest first.
 *
 * @since 1.1.0
 *
 * @return WP_REST_Response
 */
function sit_cwm_e2e_get_mails() {
	$mails = get_option( SIT_CWM_E2E_MAILS_OPTION, array() );

	return rest_ensure_response( is_array( $mails ) ? array_values( $mails ) : array() );
}

/**
 * `DELETE /mails`: forgets every captured mail.
 *
 * Also clears the notification de-duplication transients, so a spec that
 * repeats an event within the 120-second window still gets its mail.
 *
 * @since 1.1.0
 *
 * @return WP_REST_Response
 */
function sit_cwm_e2e_clear_mails() {
	global $wpdb;

	delete_option( SIT_CWM_E2E_MAILS_OPTION );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture; transients have no "delete by prefix" API.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sit_cwm_mail_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sit_cwm_mail_' ) . '%'
		)
	);
	wp_cache_flush();

	return rest_ensure_response( array() );
}

/**
 * `POST /go-live/<id>`: makes a scheduled post due and runs what cron runs.
 *
 * The date is moved into the past with a direct write, because
 * `wp_update_post()` would publish a `future` post with a past date itself
 * and skip the cron path under test. The hook runs with nobody logged in,
 * as under real cron.
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_go_live( WP_REST_Request $request ) {
	global $wpdb;

	$post = sit_cwm_e2e_post( $request );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$past = time() - HOUR_IN_SECONDS;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture; see the docblock.
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_date'     => wp_date( 'Y-m-d H:i:s', $past ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $past ),
		),
		array( 'ID' => $post->ID ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	clean_post_cache( $post->ID );

	sit_cwm_e2e_as_nobody(
		static function () use ( $post ) {
			do_action( 'publish_future_post', $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's cron hook.
		}
	);

	return rest_ensure_response( array( 'status' => get_post_status( $post->ID ) ) );
}

/**
 * `POST /raw-publish/<id>`: `wp_publish_post()`, the backstop path.
 *
 * Runs with nobody logged in, like another plugin's background job: the
 * requesting administrator would otherwise be judged and bypass the gate.
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_raw_publish( WP_REST_Request $request ) {
	$post = sit_cwm_e2e_post( $request );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	sit_cwm_e2e_as_nobody(
		static function () use ( $post ) {
			wp_publish_post( $post->ID );
		}
	);
	clean_post_cache( $post->ID );

	return rest_ensure_response( array( 'status' => get_post_status( $post->ID ) ) );
}

/**
 * `POST /settings`: merges a partial settings array through the plugin's own
 * settings model, so invalid input is refused exactly as in production.
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_settings( WP_REST_Request $request ) {
	if ( ! class_exists( '\Sit_Cwm\Core\Settings' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	$settings = new \Sit_Cwm\Core\Settings();

	if ( ! $settings->update( $request->get_json_params() ) ) {
		return new WP_Error( 'sit_cwm_e2e_invalid_settings', 'The settings were refused.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( $settings->all() );
}

/**
 * `GET /activity/<id>`: the post's raw activity rows, oldest first, with the
 * stored context the public activity route does not expose (e.g. `layer`).
 *
 * @since 1.1.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_activity( WP_REST_Request $request ) {
	$post = sit_cwm_e2e_post( $request );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	if ( ! class_exists( '\Sit_Cwm\Activity\ActivityLogger' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	$logger = new \Sit_Cwm\Activity\ActivityLogger( new \Sit_Cwm\Core\Database() );
	$rows   = array();

	foreach ( $logger->get_for_post(
		$post->ID,
		array(
			'per_page' => 100,
			'order'    => 'ASC',
		)
	) as $entry ) {
		$rows[] = array(
			'action'  => $entry->get_action(),
			'user_id' => $entry->get_user_id(),
			'context' => $entry->get_context(),
		);
	}

	return rest_ensure_response( $rows );
}

/**
 * Registers the fixture routes.
 *
 * @since 1.1.0
 *
 * @return void
 */
function sit_cwm_e2e_register_routes() {
	$namespace = 'sit-cwm-e2e/v1';
	$id_args   = array(
		'id' => array(
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		),
	);

	register_rest_route(
		$namespace,
		'/mails',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'sit_cwm_e2e_get_mails',
				'permission_callback' => 'sit_cwm_e2e_can_use',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'sit_cwm_e2e_clear_mails',
				'permission_callback' => 'sit_cwm_e2e_can_use',
			),
		)
	);

	register_rest_route(
		$namespace,
		'/go-live/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sit_cwm_e2e_go_live',
			'permission_callback' => 'sit_cwm_e2e_can_use',
			'args'                => $id_args,
		)
	);

	register_rest_route(
		$namespace,
		'/raw-publish/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sit_cwm_e2e_raw_publish',
			'permission_callback' => 'sit_cwm_e2e_can_use',
			'args'                => $id_args,
		)
	);

	register_rest_route(
		$namespace,
		'/settings',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sit_cwm_e2e_settings',
			'permission_callback' => 'sit_cwm_e2e_can_use',
		)
	);

	register_rest_route(
		$namespace,
		'/activity/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'sit_cwm_e2e_activity',
			'permission_callback' => 'sit_cwm_e2e_can_use',
			'args'                => $id_args,
		)
	);
}
add_action( 'rest_api_init', 'sit_cwm_e2e_register_routes' );

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
 * Starts every user who has no saved editor preferences with the block
 * editor's welcome guide dismissed.
 *
 * Core preloads the stored preferences into the editor. When there are none,
 * the editor fetches them after it has loaded and replaces anything set in
 * the page meanwhile, so a guide the specs dismissed opens again late, over
 * the sidebar, for every user the specs create. A non-empty default here is
 * preloaded instead, and the fetch never happens.
 *
 * @since 1.1.0
 *
 * @param mixed  $value    Default value.
 * @param int    $user_id  User id.
 * @param string $meta_key Meta key.
 * @param bool   $single   Whether a single value was asked for.
 * @return mixed
 */
function sit_cwm_e2e_default_preferences( $value, $user_id, $meta_key, $single ) {
	global $wpdb;

	if ( $wpdb->get_blog_prefix() . 'persisted_preferences' !== $meta_key ) {
		return $value;
	}

	$preferences = array(
		'core/edit-post' => array( 'welcomeGuide' => false ),
	);

	return $single ? $preferences : array( $preferences );
}
add_filter( 'default_user_metadata', 'sit_cwm_e2e_default_preferences', 20, 4 );

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
 * Option that keeps the Freemius opt-in showing (set by `freemius-optin.spec.js`).
 *
 * @since 1.2.0
 * @var string
 */
const SIT_CWM_E2E_KEEP_OPTIN_OPTION = 'sit_cwm_e2e_keep_optin';

/**
 * Meta key the probe add-on's veto reads (`tests/fixtures/sit-cwm-probe`).
 *
 * @since 1.2.0
 * @var string
 */
const SIT_CWM_E2E_PROBE_CHECKLIST_META = '_sit_cwm_probe_checklist';

/**
 * Skips the Freemius opt-in, as its Skip button does, so the opt-in screen
 * never sits in front of the dashboard in the specs. wp-env never runs
 * `bin/setup-e2e-site.php`, so this is what skips it there.
 *
 * Does nothing while `freemius-optin.spec.js` keeps the opt-in showing, or
 * once the site has opted in.
 *
 * @since 1.2.0
 *
 * @return void
 */
function sit_cwm_e2e_skip_optin() {
	if ( ! function_exists( 'sit_cwm_fs' ) || get_option( SIT_CWM_E2E_KEEP_OPTIN_OPTION ) ) {
		return;
	}

	$fs = sit_cwm_fs();

	if ( ! $fs->is_registered() && ! $fs->is_anonymous() ) {
		$fs->skip_connection();
	}
}
add_action( 'plugins_loaded', 'sit_cwm_e2e_skip_optin', 1 );

/**
 * Makes Application Passwords available on the plain-HTTP test sites.
 *
 * Core offers them only over HTTPS or on a `local` environment type. The
 * Laragon scratch sites are neither, and `agent-flow.spec.js` authenticates
 * the agent with one, as `docs/AGENTS.md` tells real agents to.
 *
 * @since 1.2.0
 */
add_filter( 'wp_is_application_passwords_available', '__return_true' );

/**
 * Answers WordPress.org update checks with an empty JSON body instead of
 * going online. Plugin information lookups are refused through the
 * `plugins_api` filter below instead: an empty body there would lack the
 * `name` core's plugin-dependency code reads.
 *
 * Local test sites often cannot reach WordPress.org over HTTPS (no CA
 * bundle). Core then prints "An unexpected error occurred" warnings into the
 * admin page with WP_DEBUG_DISPLAY on, and the e2e utils fail on any PHP
 * warning in page content. The specs never need those responses.
 *
 * @since 1.2.0
 *
 * @param false|array|WP_Error $preempt Earlier short-circuit value.
 * @param array                $args    Request arguments.
 * @param string               $url     Request URL.
 * @return false|array
 */
function sit_cwm_e2e_offline_wporg( $preempt, $args, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
	if ( false !== $preempt || 'api.wordpress.org' !== wp_parse_url( $url, PHP_URL_HOST ) || ! preg_match( '#/(update-check|version-check)/#', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
		return $preempt;
	}

	return array(
		'headers'  => array(),
		'body'     => '{}',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}
add_filter( 'pre_http_request', 'sit_cwm_e2e_offline_wporg', 10, 3 );

/**
 * Refuses WordPress.org plugin information lookups offline, quietly: core
 * skips a `WP_Error` from `plugins_api()` without printing a warning.
 *
 * @since 1.2.0
 *
 * @param false|object|array $result Earlier result.
 * @return false|object|array|WP_Error
 */
function sit_cwm_e2e_offline_plugins_api( $result ) {
	return false === $result ? new WP_Error( 'sit_cwm_e2e_offline', 'WordPress.org is not reachable from the test site.' ) : $result;
}
add_filter( 'plugins_api', 'sit_cwm_e2e_offline_plugins_api' );

/**
 * Prints a test admin notice when the URL has `sit_cwm_e2e_notice=1`, so
 * `admin-shell.spec.js` can check where core places notices on the
 * plugin's screens.
 *
 * @since 1.2.0
 *
 * @return void
 */
function sit_cwm_e2e_admin_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only test flag.
	if ( isset( $_GET['sit_cwm_e2e_notice'] ) ) {
		echo '<div class="notice notice-info sit-cwm-e2e-notice"><p>E2E test notice.</p></div>';
	}
}
add_action( 'admin_notices', 'sit_cwm_e2e_admin_notice' );

/**
 * `GET /freemius`: the Freemius opt-in state of this site.
 *
 * @since 1.2.0
 *
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_freemius_state() {
	if ( ! function_exists( 'sit_cwm_fs' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	$fs = sit_cwm_fs();

	return rest_ensure_response(
		array(
			'registered'    => (bool) $fs->is_registered(),
			'anonymous'     => (bool) $fs->is_anonymous(),
			'keep_optin'    => (bool) get_option( SIT_CWM_E2E_KEEP_OPTIN_OPTION ),
			'reconnect_url' => $fs->get_reconnect_url(),
		)
	);
}

/**
 * `POST /freemius`: `{ "optin": "show" }` keeps the opt-in showing and
 * returns the SDK's reconnect URL (opening it resets the skip);
 * `{ "optin": "skip" }` skips it again.
 *
 * @since 1.2.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_freemius_set( WP_REST_Request $request ) {
	if ( ! function_exists( 'sit_cwm_fs' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	if ( 'show' === $request['optin'] ) {
		update_option( SIT_CWM_E2E_KEEP_OPTIN_OPTION, 1, false );
	} else {
		delete_option( SIT_CWM_E2E_KEEP_OPTIN_OPTION );
		sit_cwm_fs()->skip_connection();
	}

	return sit_cwm_e2e_freemius_state();
}

/**
 * `POST /activity`: inserts one activity row with an explicit `created_at`
 * and `context`, so the governance report specs can arrange history.
 *
 * Goes through the plugin's own logger, so unknown actions are refused.
 *
 * @since 1.2.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_insert_activity( WP_REST_Request $request ) {
	if ( ! class_exists( '\Sit_Cwm\Activity\ActivityLogger' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	$post = get_post( absint( $request['post_id'] ) );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'sit_cwm_e2e_no_post', 'No such post.', array( 'status' => 404 ) );
	}

	$args = array(
		'context' => is_array( $request['context'] ) ? $request['context'] : array(),
		'user_id' => null === $request['user_id'] ? get_current_user_id() : absint( $request['user_id'] ),
	);

	foreach ( array( 'old_value', 'new_value', 'created_at' ) as $key ) {
		if ( null !== $request[ $key ] ) {
			$args[ $key ] = sanitize_text_field( (string) $request[ $key ] );
		}
	}

	$logger = new \Sit_Cwm\Activity\ActivityLogger( new \Sit_Cwm\Core\Database() );
	$id     = $logger->log( $post->ID, sanitize_key( (string) $request['action'] ), $args );

	if ( 0 === $id ) {
		return new WP_Error( 'sit_cwm_e2e_activity_refused', 'The activity row was refused.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( array( 'id' => $id ) );
}

/**
 * `DELETE /activity`: deletes every activity row, so a spec whose figures
 * count the whole table (the governance report) starts from nothing, whatever
 * other specs left behind.
 *
 * @since 1.2.0
 *
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_clear_activity() {
	global $wpdb;

	if ( ! class_exists( '\Sit_Cwm\Core\Database' ) ) {
		return new WP_Error( 'sit_cwm_e2e_inactive', 'The plugin is not active.', array( 'status' => 500 ) );
	}

	$table = ( new \Sit_Cwm\Core\Database() )->table_name();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture; the logger has no "delete all" API.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * `POST /probe-checklist`: sets (`done: true`) or removes the probe add-on's
 * checklist flag on a post.
 *
 * @since 1.2.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_probe_checklist( WP_REST_Request $request ) {
	$post = get_post( absint( $request['post_id'] ) );

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'sit_cwm_e2e_no_post', 'No such post.', array( 'status' => 404 ) );
	}

	if ( rest_sanitize_boolean( $request['done'] ) ) {
		update_post_meta( $post->ID, SIT_CWM_E2E_PROBE_CHECKLIST_META, '1' );
	} else {
		delete_post_meta( $post->ID, SIT_CWM_E2E_PROBE_CHECKLIST_META );
	}

	return rest_ensure_response( array( 'done' => '1' === get_post_meta( $post->ID, SIT_CWM_E2E_PROBE_CHECKLIST_META, true ) ) );
}

/**
 * `POST /user-cap`: grants (`grant: true`) or explicitly denies
 * (`grant: false`) one of the plugin's `sit_cwm_*` capabilities to a single
 * user, on top of their role.
 *
 * @since 1.2.0
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function sit_cwm_e2e_user_cap( WP_REST_Request $request ) {
	$user = get_userdata( absint( $request['user_id'] ) );
	$cap  = sanitize_key( (string) $request['cap'] );

	if ( ! $user instanceof WP_User ) {
		return new WP_Error( 'sit_cwm_e2e_no_user', 'No such user.', array( 'status' => 404 ) );
	}

	if ( 0 !== strpos( $cap, 'sit_cwm_' ) ) {
		return new WP_Error( 'sit_cwm_e2e_bad_cap', 'Only sit_cwm_* capabilities.', array( 'status' => 400 ) );
	}

	$user->add_cap( $cap, rest_sanitize_boolean( $request['grant'] ) );

	return rest_ensure_response( array( 'has_cap' => user_can( $user, $cap ) ) );
}

/**
 * Registers the fixture routes.
 *
 * @since 1.1.0
 * @since 1.2.0 Adds `/freemius`, `/activity` (POST, DELETE) and `/probe-checklist`.
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

	register_rest_route(
		$namespace,
		'/freemius',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'sit_cwm_e2e_freemius_state',
				'permission_callback' => 'sit_cwm_e2e_can_use',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'sit_cwm_e2e_freemius_set',
				'permission_callback' => 'sit_cwm_e2e_can_use',
				'args'                => array(
					'optin' => array(
						'type'     => 'string',
						'enum'     => array( 'show', 'skip' ),
						'required' => true,
					),
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/activity',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'sit_cwm_e2e_insert_activity',
				'permission_callback' => 'sit_cwm_e2e_can_use',
				'args'                => array(
					'post_id'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'action'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'context'    => array( 'type' => 'object' ),
					'user_id'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'old_value'  => array( 'type' => 'string' ),
					'new_value'  => array( 'type' => 'string' ),
					'created_at' => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'sit_cwm_e2e_clear_activity',
				'permission_callback' => 'sit_cwm_e2e_can_use',
			),
		)
	);

	register_rest_route(
		$namespace,
		'/probe-checklist',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sit_cwm_e2e_probe_checklist',
			'permission_callback' => 'sit_cwm_e2e_can_use',
			'args'                => array(
				'post_id' => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'done'    => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		$namespace,
		'/user-cap',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'sit_cwm_e2e_user_cap',
			'permission_callback' => 'sit_cwm_e2e_can_use',
			'args'                => array(
				'user_id' => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'cap'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'grant'   => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'sit_cwm_e2e_register_routes' );

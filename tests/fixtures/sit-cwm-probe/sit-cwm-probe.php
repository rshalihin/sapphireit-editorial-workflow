<?php
/**
 * Plugin Name:      SapphireIT Editorial Workflow – Seam Probe (test fixture)
 * Description:      Test fixture, never shipped. Proves roadmap §3.2 seams; must not need edits in Free.
 * Version:          1.2.0
 * Requires Plugins: sapphireit-editorial-workflow
 * License:          GPL-2.0-or-later
 *
 * Test fixture, never shipped (`tests/` is excluded from the release zip).
 * Proves roadmap §3.2 seams; must not need edits in Free. It adds a
 * `legal_review` status behind its own capability, rewires the graph around
 * it, vetoes approval until a legal checklist is done, logs every status
 * change, extends the dashboard query with a saved view, and drives Free's
 * own WorkflowManager through the container.
 *
 * Every callback is a named function, so `sit_cwm_probe_unregister()` can
 * remove it and tests never leak hooks into each other.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SIT_CWM_PROBE_STATUS' ) ) {
	/**
	 * Slug of the status the probe adds.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	define( 'SIT_CWM_PROBE_STATUS', 'legal_review' );
}

if ( ! defined( 'SIT_CWM_PROBE_CAP' ) ) {
	/**
	 * Capability required to move a post into the probe's status.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	define( 'SIT_CWM_PROBE_CAP', 'sit_cwm_probe_legal_review' );
}

if ( ! defined( 'SIT_CWM_PROBE_CHECKLIST_META' ) ) {
	/**
	 * Post meta that is `'1'` once the legal checklist is complete.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	define( 'SIT_CWM_PROBE_CHECKLIST_META', '_sit_cwm_probe_checklist' );
}

if ( ! defined( 'SIT_CWM_PROBE_LOG_OPTION' ) ) {
	/**
	 * Option collecting every status change as `"{$post_id}:{$from}>{$to}"`.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	define( 'SIT_CWM_PROBE_LOG_OPTION', 'sit_cwm_probe_log' );
}

if ( ! defined( 'SIT_CWM_PROBE_VIEW' ) ) {
	/**
	 * Dashboard `view` parameter that lists posts with the checklist open.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	define( 'SIT_CWM_PROBE_VIEW', 'legal_checklist_open' );
}

/**
 * Hooks the probe attaches: list of [ hook, callback, priority, accepted args ].
 *
 * @since 1.2.0
 *
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}>
 */
function sit_cwm_probe_hooks(): array {
	return array(
		array( 'sit_cwm_statuses', 'sit_cwm_probe_statuses', 10, 2 ),
		array( 'sit_cwm_transition_map', 'sit_cwm_probe_transition_map', 10, 2 ),
		array( 'sit_cwm_status_capability_map', 'sit_cwm_probe_capability_map', 10, 2 ),
		array( 'sit_cwm_can_transition', 'sit_cwm_probe_can_transition', 10, 4 ),
		array( 'sit_cwm_status_changed', 'sit_cwm_probe_log_status_change', 10, 3 ),
		array( 'sit_cwm_posts_query_args', 'sit_cwm_probe_posts_query_args', 10, 2 ),
		array( 'sit_cwm_loaded', 'sit_cwm_probe_on_loaded', 10, 1 ),
	);
}

/**
 * Attaches every probe callback.
 *
 * @since 1.2.0
 *
 * @return void
 */
function sit_cwm_probe_register(): void {
	foreach ( sit_cwm_probe_hooks() as $hook ) {
		add_filter( $hook[0], $hook[1], $hook[2], $hook[3] );
	}
}

/**
 * Detaches every probe callback.
 *
 * @since 1.2.0
 *
 * @return void
 */
function sit_cwm_probe_unregister(): void {
	foreach ( sit_cwm_probe_hooks() as $hook ) {
		remove_filter( $hook[0], $hook[1], $hook[2] );
	}
}

/**
 * Grants the probe capability to editors and administrators.
 *
 * @since 1.2.0
 *
 * @return void
 */
function sit_cwm_probe_activate(): void {
	foreach ( array( 'editor', 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );

		if ( null !== $role ) {
			$role->add_cap( SIT_CWM_PROBE_CAP );
		}
	}
}

/**
 * Adds the `legal_review` status, in every context.
 *
 * @since 1.2.0
 *
 * @param array $statuses Status definitions keyed by slug.
 * @param int   $post_id  Post id; 0 when the question is not about one post.
 * @return array
 */
function sit_cwm_probe_statuses( $statuses, $post_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same registry for every post.
	$statuses[ SIT_CWM_PROBE_STATUS ] = array(
		'slug'  => SIT_CWM_PROBE_STATUS,
		'label' => 'Legal Review',
		'color' => '#8a2be2',
		'order' => 35,
	);

	return $statuses;
}

/**
 * Routes review through legal review: `review → [ legal_review,
 * needs_changes ]`, `legal_review → [ approved, needs_changes ]`.
 *
 * @since 1.2.0
 *
 * @param array $map     Transition map.
 * @param int   $post_id Post id; 0 when the question is not about one post.
 * @return array
 */
function sit_cwm_probe_transition_map( $map, $post_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same graph for every post.
	$map['review']               = array( SIT_CWM_PROBE_STATUS, 'needs_changes' );
	$map[ SIT_CWM_PROBE_STATUS ] = array( 'approved', 'needs_changes' );

	return $map;
}

/**
 * Requires the probe capability to reach `legal_review`.
 *
 * @since 1.2.0
 *
 * @param array $map     Status slug => required capability.
 * @param int   $post_id Post id; 0 when the question is not about one post.
 * @return array
 */
function sit_cwm_probe_capability_map( $map, $post_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same capability for every post.
	$map[ SIT_CWM_PROBE_STATUS ] = SIT_CWM_PROBE_CAP;

	return $map;
}

/**
 * Vetoes `approved` until the legal checklist is complete.
 *
 * @since 1.2.0
 *
 * @param bool   $allowed Result of the capability checks.
 * @param int    $post_id Post id.
 * @param string $from    Current workflow status.
 * @param string $to      Target workflow status.
 * @return bool|WP_Error
 */
function sit_cwm_probe_can_transition( $allowed, $post_id, $from, $to ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter signature.
	if ( 'approved' === $to && '1' !== get_post_meta( $post_id, SIT_CWM_PROBE_CHECKLIST_META, true ) ) {
		return new WP_Error( 'sit_cwm_probe_checklist', 'The legal checklist is not complete.', array( 'status' => 422 ) );
	}

	return $allowed;
}

/**
 * Appends a status change to the probe log.
 *
 * @since 1.2.0
 *
 * @param int    $post_id Post id.
 * @param string $from    Previous workflow status.
 * @param string $to      New workflow status.
 * @return void
 */
function sit_cwm_probe_log_status_change( $post_id, $from, $to ): void {
	$log = get_option( SIT_CWM_PROBE_LOG_OPTION, array() );

	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$log[] = "{$post_id}:{$from}>{$to}";

	update_option( SIT_CWM_PROBE_LOG_OPTION, $log, false );
}

/**
 * Adds the "legal checklist open" saved view to the dashboard query.
 *
 * @since 1.2.0
 *
 * @param array           $args    Arguments of `PostRepository::query_posts()`.
 * @param WP_REST_Request $request Collection request.
 * @return array
 */
function sit_cwm_probe_posts_query_args( $args, $request ) {
	if ( SIT_CWM_PROBE_VIEW !== $request->get_param( 'view' ) ) {
		return $args;
	}

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test fixture.
	}

	$args['meta_query'][] = array(
		'key'     => SIT_CWM_PROBE_CHECKLIST_META,
		'compare' => 'NOT EXISTS',
	);

	return $args;
}

/**
 * Keeps the booted plugin's container for later calls.
 *
 * @since 1.2.0
 *
 * @param \Sit_Cwm\Core\Plugin $plugin Booted plugin.
 * @return void
 */
function sit_cwm_probe_on_loaded( \Sit_Cwm\Core\Plugin $plugin ): void {
	sit_cwm_probe_container( $plugin->container() );
}

/**
 * Holds the container stored on `sit_cwm_loaded`.
 *
 * @since 1.2.0
 *
 * @param \Sit_Cwm\Core\Container|null $set Container to store; null to only read.
 * @return \Sit_Cwm\Core\Container|null Null until `sit_cwm_loaded` has fired.
 */
function sit_cwm_probe_container( ?\Sit_Cwm\Core\Container $set = null ): ?\Sit_Cwm\Core\Container {
	static $container = null;

	if ( null !== $set ) {
		$container = $set;
	}

	return $container;
}

/**
 * Sends a post from review to legal review through Free's own WorkflowManager.
 *
 * @since 1.2.0
 *
 * @param int $post_id Post id.
 * @param int $user_id Acting user id.
 * @return true|WP_Error
 */
function sit_cwm_probe_send_to_legal( int $post_id, int $user_id ) {
	$container = sit_cwm_probe_container();

	if ( null === $container ) {
		return new WP_Error( 'sit_cwm_probe_not_loaded', 'Editorial Workflow has not loaded.' );
	}

	return $container->get( 'workflow_manager' )->transition( $post_id, 'review', SIT_CWM_PROBE_STATUS, $user_id );
}

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, 'sit_cwm_probe_activate' );
}

sit_cwm_probe_register();

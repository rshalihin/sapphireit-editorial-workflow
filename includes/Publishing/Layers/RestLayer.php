<?php
/**
 * Publish gate Layer A: REST refusal, publish link and `publish_post`.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing\Layers;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\PublishGate;
use Throwable;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layer A (E3): makes the block editor and `wp/v2` follow the gate.
 *
 * - A-link: drops `wp:action-publish` from `context=edit` responses when the
 *   gate would refuse, so the block editor offers Submit for Review instead
 *   of Publish/Schedule, with no JavaScript from us.
 * - A-rest: refuses a `wp/v2` create or update into `publish`/`future` with
 *   a 403 `sit_cwm_publish_gated` before anything is written.
 * - A-cap: makes the `publish_post` meta capability agree, for third-party
 *   code that checks it. Core's own publish checks use the post-type
 *   primitive, which this layer never touches.
 *
 * Decisions on existing posts are left in the ledger, so Layer B applies the
 * same decision to the same write without recording it again.
 *
 * @since 1.1.0
 * @internal
 */
final class RestLayer implements Bootable {

	/**
	 * Link relation the block editor reads to offer Publish/Schedule.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PUBLISH_REL = 'https://api.w.org/action-publish';

	/**
	 * Error code of a refused REST write.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const ERROR_CODE = 'sit_cwm_publish_gated';

	/**
	 * Publish gate.
	 *
	 * @since 1.1.0
	 * @var PublishGate
	 */
	private $gate;

	/**
	 * Per-request ledger.
	 *
	 * @since 1.1.0
	 * @var GateLedger
	 */
	private $ledger;

	/**
	 * Gate recorder.
	 *
	 * @since 1.1.0
	 * @var GateRecorder
	 */
	private $recorder;

	/**
	 * Plugin settings.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Post types the per-type REST filters were attached for.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private $filtered_types = array();

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param PublishGate  $gate     Publish gate.
	 * @param GateLedger   $ledger   Per-request ledger.
	 * @param GateRecorder $recorder Gate recorder.
	 * @param Settings     $settings Plugin settings.
	 */
	public function __construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder, Settings $settings ) {
		$this->gate     = $gate;
		$this->ledger   = $ledger;
		$this->recorder = $recorder;
		$this->settings = $settings;
	}

	/**
	 * Hooks the REST layer and the meta capability (E3 table).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_post_type_filters' ), 20 );
		add_filter( 'map_meta_cap', array( $this, 'filter_meta_cap' ), 10, 4 );
	}

	/**
	 * Unhooks; mirrors `register()` and `register_post_type_filters()`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'rest_api_init', array( $this, 'register_post_type_filters' ), 20 );
		remove_filter( 'map_meta_cap', array( $this, 'filter_meta_cap' ), 10 );

		foreach ( $this->filtered_types as $post_type ) {
			remove_filter( "rest_prepare_{$post_type}", array( $this, 'filter_prepare' ), 10 );
			remove_filter( "rest_pre_insert_{$post_type}", array( $this, 'filter_pre_insert' ), 10 );
		}

		$this->filtered_types = array();
	}

	/**
	 * Attaches the per-type REST filters for every workflow-enabled post type.
	 * Hooked on `rest_api_init` at 20.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_post_type_filters(): void {
		foreach ( $this->settings->enabled_post_types() as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( $this, 'filter_prepare' ), 10, 3 );
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'filter_pre_insert' ), 10, 2 );

			$this->filtered_types[] = $post_type;
		}

		$this->filtered_types = array_values( array_unique( $this->filtered_types ) );
	}

	/**
	 * Removes the publish link when the gate would refuse (A-link). Hooked on
	 * `rest_prepare_{post_type}`.
	 *
	 * Display only, so nothing is recorded. A live or scheduled post keeps
	 * the link: updating it is not a transition into a published status (E4).
	 * Fails open: on any exception the response keeps its link.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $response Response about to be sent.
	 * @param mixed $post     Post it describes.
	 * @param mixed $request  Request.
	 * @return mixed The response, without `PUBLISH_REL` when refused.
	 */
	public function filter_prepare( $response, $post, $request ) {
		try {
			if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post || ! $request instanceof WP_REST_Request ) {
				return $response;
			}

			if ( 'edit' !== $request['context'] || ! array_key_exists( self::PUBLISH_REL, $response->get_links() ) ) {
				return $response;
			}

			if ( in_array( $post->post_status, PublishGate::PUBLISH_STATUSES, true ) ) {
				return $response;
			}

			if ( $this->gate->evaluate( (int) $post->ID, $post->post_type, get_current_user_id() )->is_refused() ) {
				$response->remove_link( self::PUBLISH_REL );
			}

			return $response;
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'rest', 0, $e );

			return $response;
		}
	}

	/**
	 * Refuses a `wp/v2` create or update into `publish`/`future` (A-rest).
	 * Hooked on `rest_pre_insert_{post_type}`.
	 *
	 * Fails open (E5): on any exception the write goes ahead and the failure
	 * is recorded once; Layer B is told the write was judged, so it does not
	 * judge or record it again.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $prepared Post object prepared for the database.
	 * @param mixed $request  Request.
	 * @return mixed The prepared post, or a 403 `WP_Error` when refused.
	 */
	public function filter_pre_insert( $prepared, $request ) {
		$post_id = is_object( $prepared ) && isset( $prepared->ID ) ? absint( $prepared->ID ) : 0;

		try {
			return $this->guard( $prepared );
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'rest', $post_id, $e );

			if ( $post_id > 0 ) {
				$this->ledger->judge( $post_id, GateDecision::failed_open( '', '' ) );
			}

			return $prepared;
		}
	}

	/**
	 * Makes `publish_post` follow the gate (A-cap). Hooked on `map_meta_cap`.
	 *
	 * Only ever appends `do_not_allow`; it never grants. A live post, and
	 * every other capability, is left alone. Nothing is recorded.
	 *
	 * Cannot recurse: `PublishGate::evaluate()` checks only the primitive
	 * `sit_cwm_bypass_publish_gate` and reads meta, and never checks
	 * `publish_post`.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $caps    Primitive capabilities required.
	 * @param mixed $cap     Capability being checked.
	 * @param mixed $user_id User id.
	 * @param mixed $args    Extra arguments; `$args[0]` is the post id.
	 * @return array
	 */
	public function filter_meta_cap( $caps, $cap, $user_id, $args ): array {
		$caps = (array) $caps;

		if ( 'publish_post' !== $cap || ! is_array( $args ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		try {
			$post = get_post( (int) $args[0] );

			if ( ! $post instanceof WP_Post || ! $this->settings->is_post_type_enabled( $post->post_type ) ) {
				return $caps;
			}

			if ( ! SaveLayer::is_entering_published( 'publish', $post->post_status ) ) {
				return $caps;
			}

			if ( $this->gate->evaluate( (int) $post->ID, $post->post_type, (int) $user_id )->is_refused() ) {
				$caps[] = 'do_not_allow';
			}

			return $caps;
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'rest', 0, $e );

			return $caps;
		}
	}

	/**
	 * The judgement itself; see `filter_pre_insert()`.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $prepared Post object prepared for the database.
	 * @return mixed
	 */
	private function guard( $prepared ) {
		if ( ! is_object( $prepared ) || ! isset( $prepared->post_status ) || ! is_string( $prepared->post_status ) ) {
			return $prepared;
		}

		$attempted = $prepared->post_status;

		if ( ! in_array( $attempted, PublishGate::PUBLISH_STATUSES, true ) ) {
			return $prepared;
		}

		$post_id = isset( $prepared->ID ) ? absint( $prepared->ID ) : 0;
		$stored  = $post_id > 0 ? (string) get_post_status( $post_id ) : '';

		if ( ! SaveLayer::is_entering_published( $attempted, $stored ) ) {
			return $prepared;
		}

		$post_type = $post_id > 0 ? (string) get_post_type( $post_id ) : (string) ( $prepared->post_type ?? '' );

		if ( '' === $post_type || ! $this->settings->is_post_type_enabled( $post_type ) ) {
			return $prepared;
		}

		$user_id  = get_current_user_id();
		$decision = $this->gate->evaluate( $post_id, $post_type, $user_id );

		if ( ! $decision->applies() ) {
			return $prepared;
		}

		if ( $decision->is_refused() ) {
			// The write is aborted, so Layer B never sees it: nothing to leave in the ledger.
			if ( $post_id > 0 ) {
				// No admin notice: the editor shows the REST error itself.
				$this->recorder->refused( $post_id, $decision, $stored, $attempted, 'rest', $user_id, 0 );
			}

			return new WP_Error(
				self::ERROR_CODE,
				__( 'This content has to be approved in the editorial workflow before it can be published.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 403 )
			);
		}

		// A new post has no id to key the ledger by: Layer B judges it again, gets the same answer, and records it under the new id.
		if ( 0 === $post_id ) {
			return $prepared;
		}

		$this->ledger->judge( $post_id, $decision );

		if ( ! $decision->is_bypassed() ) {
			return $prepared;
		}

		if ( 'publish' === $attempted ) {
			$this->recorder->bypassed( $post_id, $decision, $stored, 'rest', $user_id );
		} elseif ( GateDecision::REASON_CAPABILITY === $decision->reason() ) {
			$this->recorder->remember_schedule_bypass( $post_id, $user_id );
		}

		return $prepared;
	}
}

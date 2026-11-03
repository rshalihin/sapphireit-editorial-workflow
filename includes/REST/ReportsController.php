<?php
/**
 * REST controller for the governance report.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Governance\GovernanceReport;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/reports/governance`: site-wide governance figures for a
 * period (F12), for auditors only.
 *
 * @since 1.2.0
 * @internal
 */
final class ReportsController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	protected $rest_base = 'reports/governance';

	/**
	 * Governance metrics.
	 *
	 * @since 1.2.0
	 * @var GovernanceReport
	 */
	private $report;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param PermissionManager $permissions Authorization.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param GovernanceReport  $report      Governance metrics.
	 */
	public function __construct( PermissionManager $permissions, PostRepository $posts, GovernanceReport $report ) {
		parent::__construct( $permissions, $posts );

		$this->report = $report;
	}

	/**
	 * Registers the route.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'period' => array(
							'description'       => __( 'Period covered, in days.', 'sapphireit-editorial-workflow' ),
							'type'              => 'integer',
							'enum'              => GovernanceReport::PERIODS,
							'default'           => 90,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Logged in, then allowed to audit.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		return $this->permissions->can_audit() ? true : $this->forbidden();
	}

	/**
	 * The report for the requested period.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response( $this->report->build( (int) $request->get_param( 'period' ) ) );
	}

	/**
	 * Schema of the report: the full F12 shape, read-only.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-governance-report',
			'type'       => 'object',
			'properties' => array(
				'period_days'  => array(
					'description' => __( 'Period covered, in days.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'enum'        => GovernanceReport::PERIODS,
					'readonly'    => true,
				),
				'since'        => array(
					'description' => __( 'Start of the period: midnight UTC, period_days days ago.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'generated_at' => array(
					'description' => __( 'When the report was computed, UTC.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'publication'  => array(
					'description' => __( 'Publications in the period.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'total'          => array( 'type' => 'integer' ),
						'approved'       => array( 'type' => 'integer' ),
						'approved_share' => array( 'type' => array( 'number', 'null' ) ),
						'by_gate_mode'   => $this->counts_schema( array( 'off', 'log_only', 'enforce', 'unknown' ) ),
					),
				),
				'gate'         => array(
					'description' => __( 'Publish gate refusals and bypasses in the period.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'refused'  => array( 'type' => 'integer' ),
						'bypassed' => $this->counts_schema( array( 'log_only', 'capability', 'error' ) ),
					),
				),
				'ai_origin'    => array(
					'description' => __( 'Declared AI use: of current content, and at publication in the period.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'current'   => $this->counts_schema( array( 'human', 'ai_assisted', 'ai_generated', 'not_declared' ) ),
						'published' => $this->counts_schema( array( 'human', 'ai_assisted', 'ai_generated', 'not_declared', 'not_recorded' ) ),
					),
				),
				'overdue'      => array(
					'description' => __( 'Overdue reviews, site-wide.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'count' => array( 'type' => 'integer' ),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Schema of an object of integer counts.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $keys Count keys.
	 * @return array
	 */
	private function counts_schema( array $keys ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( $keys, array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
		);
	}
}

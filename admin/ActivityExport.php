<?php
/**
 * Activity log CSV download.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Audit\ActivityExporter;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Export activity log (CSV)" form and its `admin-post.php` handler (F8).
 *
 * The handler checks the nonce, then `can_audit()`, and only then streams.
 * No nonce goes into script data: the form is rendered by PHP for auditors
 * only.
 *
 * Not final: tests override `send_headers()` and `finish()`.
 *
 * @since 1.2.0
 * @internal
 */
class ActivityExport implements Bootable {

	/**
	 * `admin-post.php` action, also the nonce action.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ACTION = 'sit_cwm_export_activity';

	/**
	 * Request field carrying the nonce.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const NONCE_FIELD = '_sit_cwm_nonce';

	/**
	 * Workflow authorization.
	 *
	 * @since 1.2.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * CSV writer of the activity log.
	 *
	 * @since 1.2.0
	 * @var ActivityExporter
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param PermissionManager $permissions Workflow authorization.
	 * @param ActivityExporter  $exporter    CSV writer of the activity log.
	 */
	public function __construct( PermissionManager $permissions, ActivityExporter $exporter ) {
		$this->permissions = $permissions;
		$this->exporter    = $exporter;
	}

	/**
	 * Attaches the logged-in `admin-post.php` handler. There is deliberately
	 * no `admin_post_nopriv_` handler.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Streams the CSV download.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function handle(): void {
		// Dies with 403 on a missing or invalid nonce.
		check_admin_referer( self::ACTION, self::NONCE_FIELD );

		if ( ! $this->permissions->can_audit() ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to export the activity log.', 'sapphireit-editorial-workflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->send_headers( 'sit-cwm-activity-' . wp_date( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The response body, not a file.

		$this->exporter->export( $out, 'flush' );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The response body, not a file.

		$this->finish();
	}

	/**
	 * The export form, or `''` for users who may not audit.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	public function render_form(): string {
		if ( ! $this->permissions->can_audit() ) {
			return '';
		}

		return sprintf(
			'<form method="post" action="%1$s" class="sit-cwm-export-form">%2$s<input type="hidden" name="action" value="%3$s" /><button type="submit" class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span>%4$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( self::ACTION, self::NONCE_FIELD, true, false ),
			esc_attr( self::ACTION ),
			esc_html__( 'Export activity log (CSV)', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Sends the download headers (F8).
	 *
	 * `nocache_headers()` runs here rather than in `handle()` so tests,
	 * which cannot send headers, can replace the whole step.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filename Download filename.
	 * @return void
	 */
	protected function send_headers( string $filename ): void {
		nocache_headers();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );
	}

	/**
	 * Ends the request once the body is written.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function finish(): void {
		exit;
	}
}

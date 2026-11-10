<?php
/**
 * Promotion of the Pro add-on, from its launch.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class with promotion code (roadmap §4.5).
 *
 * The state is derived, never toggled remotely: `stage()` reads the Freemius
 * SDK's own flags and whether Pro is loaded, and nothing is fetched from a
 * server. Everything is shown only to managers, only on the plugin's own
 * screens and only while Pro is on sale and not active:
 *
 * - one plain "Pro" link at the right of the header;
 * - one "Go Pro" link in the plugin's row on the Plugins screen;
 * - four one-line hints, each dismissible per user, permanently.
 *
 * Never in the block editor or the classic meta box, never site-wide, never on
 * the front end. The founding-customer notice is left out: the SDK does not
 * expose how many coupons remain, and a guessed count would be fake urgency.
 *
 * Every link opens the author's site in a new tab. Pro is bought there, never
 * inside WordPress, so Free never offers to install it (guideline 8); the SDK's
 * Add-Ons page and checkout are closed in the main plugin file.
 *
 * @since 1.2.0
 * @internal
 */
class Promotions implements Bootable {

	/**
	 * Stage: nothing is sold (every release before the Pro launch).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const STAGE_NONE = 'none';

	/**
	 * Stage: add-ons are on sale and Pro is not active.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const STAGE_ON_SALE = 'on_sale';

	/**
	 * Stage: Pro is active, or an add-on asked for no promotion.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const STAGE_OWNED = 'owned';

	/**
	 * User meta holding the keys of the hints a user dismissed.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DISMISSED_META = 'sit_cwm_dismissed_hints';

	/**
	 * `admin-post.php` action and nonce action of the dismiss link.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DISMISS_ACTION = 'sit_cwm_dismiss_hint';

	/**
	 * Hint keys, one per placement in roadmap §4.5.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const HINT_KEYS = array( 'export', 'overdue', 'notifications', 'workflows' );

	/**
	 * Pro's page on the plugin author's site: pricing, terms and refunds.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const PRO_URL = 'https://sapphireitlabs.com/editorial-workflow/';

	/**
	 * Workflow authorization.
	 *
	 * @since 1.2.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( PermissionManager $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Attaches the dismiss handler, for logged-in users only.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Where the site is in the Pro lifecycle.
	 *
	 * @since 1.2.0
	 *
	 * @return string One of `none`, `on_sale`, `owned`.
	 */
	public function stage(): string {
		if ( $this->is_pro_active() ) {
			return self::STAGE_OWNED;
		}

		/**
		 * Filters whether Free may promote Pro at all.
		 *
		 * Pro returns false, so a site that owns it sees no promotion even if
		 * Free cannot tell Pro is loaded. Returning false is the only effect:
		 * the filter cannot turn promotion on before add-ons are on sale.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $show Whether promotion may show. Default true.
		 */
		if ( ! apply_filters( 'sit_cwm_show_promotions', true ) ) {
			return self::STAGE_OWNED;
		}

		if ( ! $this->sells_addons() ) {
			return self::STAGE_NONE;
		}

		return self::STAGE_ON_SALE;
	}

	/**
	 * One hint line, or `''` when it must not show.
	 *
	 * Shows only in `on_sale`, only to managers, only on a plugin screen and
	 * only until the current user dismisses it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key One of `HINT_KEYS`.
	 * @return string Escaped markup.
	 */
	public function hint( string $key ): string {
		$hints = $this->hints();

		if ( ! isset( $hints[ $key ] ) || ! $this->may_promote() || $this->is_dismissed( get_current_user_id(), $key ) ) {
			return '';
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'hint'   => $key,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);

		return sprintf(
			'<p class="sit-cwm-hint" data-sit-cwm-hint="%1$s">%2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s<span class="screen-reader-text"> %5$s</span></a> <a class="sit-cwm-hint__dismiss" href="%6$s">%7$s</a></p>',
			esc_attr( $key ),
			esc_html( $hints[ $key ] ),
			esc_url( self::PRO_URL ),
			esc_html__( 'Learn about Pro', 'sapphireit-editorial-workflow' ),
			esc_html__( '(opens in a new tab)', 'sapphireit-editorial-workflow' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * The plain "Pro" link at the right of the header, or `''`.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	public function header_link(): string {
		if ( ! $this->may_promote() ) {
			return '';
		}

		return sprintf(
			'<a class="sit-cwm-shell__pro" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( self::PRO_URL ),
			esc_html__( 'Pro', 'sapphireit-editorial-workflow' ),
			esc_html__( '(opens in a new tab)', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * The "Go Pro" link for the plugin's row on the Plugins screen, or `''`.
	 *
	 * The Plugins screen is the one place outside the plugin screens where a
	 * link shows, as roadmap §4.5 allows.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	public function action_link(): string {
		if ( self::STAGE_ON_SALE !== $this->stage() || ! $this->permissions->can_manage() ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( self::PRO_URL ),
			esc_html__( 'Go Pro', 'sapphireit-editorial-workflow' ),
			esc_html__( '(opens in a new tab)', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Whether a user dismissed a hint.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Hint key.
	 * @return bool
	 */
	public function is_dismissed( int $user_id, string $key ): bool {
		return in_array( $key, $this->dismissed( $user_id ), true );
	}

	/**
	 * Records that a user dismissed a hint, for good.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id User id.
	 * @param string $key     One of `HINT_KEYS`.
	 * @return bool False for an unknown key or user.
	 */
	public function dismiss( int $user_id, string $key ): bool {
		if ( $user_id <= 0 || ! in_array( $key, self::HINT_KEYS, true ) ) {
			return false;
		}

		$dismissed = $this->dismissed( $user_id );

		if ( ! in_array( $key, $dismissed, true ) ) {
			$dismissed[] = $key;
			update_user_meta( $user_id, self::DISMISSED_META, $dismissed );
		}

		return true;
	}

	/**
	 * Handles the dismiss link: nonce, then capability, then the write, then a
	 * redirect back to the screen the link was on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		// Dies with 403 on a missing or invalid nonce.
		check_admin_referer( self::DISMISS_ACTION );

		if ( ! $this->permissions->can_manage() ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to do that.', 'sapphireit-editorial-workflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		$key = isset( $_GET['hint'] ) ? sanitize_key( wp_unslash( $_GET['hint'] ) ) : '';

		$this->dismiss( get_current_user_id(), $key );

		$back = wp_get_referer();

		$this->redirect( false !== $back ? $back : admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG ) );
	}

	/**
	 * Sends the user back and ends the request.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url Local URL.
	 * @return void
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Whether header, tab and hints may show to the current user right now.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function may_promote(): bool {
		return self::STAGE_ON_SALE === $this->stage() && $this->permissions->can_manage() && $this->is_plugin_screen();
	}

	/**
	 * Whether the current page is one of Free's own screens.
	 *
	 * Compared with `AdminShell::SCREEN_SLUGS` rather than asked of the shell,
	 * because the shell depends on this class. Add-on screens never count:
	 * an add-on that adds screens is owned already.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function is_plugin_screen(): bool {
		global $plugin_page;

		return is_string( $plugin_page ) && in_array( $plugin_page, AdminShell::SCREEN_SLUGS, true );
	}

	/**
	 * Whether Pro is loaded, by its SDK function or its boot action.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function is_pro_active(): bool {
		return function_exists( 'sit_cwm_pro_fs' ) || did_action( 'sit_cwm_pro_loaded' ) > 0;
	}

	/**
	 * Whether the SDK reports anything for sale: add-ons or paid plans.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function sells_addons(): bool {
		if ( ! function_exists( 'sit_cwm_fs' ) ) {
			return false;
		}

		$fs = sit_cwm_fs();

		return $fs->has_addons() || $fs->has_paid_plan();
	}

	/**
	 * Keys a user dismissed, cleaned of anything unknown.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private function dismissed( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::DISMISSED_META, true );

		return array_values( array_intersect( self::HINT_KEYS, is_array( $stored ) ? $stored : array() ) );
	}

	/**
	 * Hint copy, one line each, keyed by placement.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	private function hints(): array {
		return array(
			'export'        => __( 'Pro adds filtered and scheduled exports and a tamper-evident audit log.', 'sapphireit-editorial-workflow' ),
			'overdue'       => __( 'Pro sends reminders before and after due dates and escalates overdue reviews.', 'sapphireit-editorial-workflow' ),
			'notifications' => __( 'Pro adds Slack and Microsoft Teams, per-user preferences and email digests.', 'sapphireit-editorial-workflow' ),
			'workflows'     => __( 'Pro adds multi-stage approval and separate workflows per content type or category.', 'sapphireit-editorial-workflow' ),
		);
	}
}

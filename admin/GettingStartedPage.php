<?php
/**
 * Getting Started screen.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings as SettingsStore;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Workflow → Getting Started": the setup steps (managers only), the video
 * tour, tutorials, documentation, help links and a short "How content moves".
 *
 * Plain PHP templates, no React. The page makes no remote request: the video
 * thumbnail is drawn in CSS and every external resource is a plain link that
 * opens in a new tab. A missing URL hides its card or link instead of
 * linking nowhere. Constants and list methods are read late-bound
 * (`static::`) so a subclass can replace them.
 *
 * Setup steps are worked out from real settings and content on every render,
 * never from stored "done" flags.
 *
 * Freemius's after-activation redirect (`first-path`, F18) points here.
 *
 * @since 1.2.0
 * @internal
 */
class GettingStartedPage implements Bootable {

	/**
	 * Menu slug (`admin.php?page=sit-cwm-getting-started`).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const MENU_SLUG = 'sit-cwm-getting-started';

	/**
	 * `admin_menu` priority: after the dashboard (10), settings (11) and any
	 * later page, so this stays the last submenu item.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MENU_PRIORITY = 20;

	/**
	 * The plugin's website.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const WEBSITE_URL = 'https://sapphireitlabs.com/editorial-workflow/';

	/**
	 * The plugin's WordPress.org support forum.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/sapphireit-editorial-workflow/';

	/**
	 * The documentation home.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DOCS_URL = 'https://sapphireitlabs.com/editorial-workflow/docs';

	/**
	 * Placeholder video link until the YouTube channel exists. Deliberately
	 * fake, so it can never point at someone else's video.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const DEMO_VIDEO_URL = 'https://www.youtube.com/watch?v=XXXXXXXXXXX'; // DEMO: replace before release.

	/**
	 * The video tour.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TOUR_URL = self::DEMO_VIDEO_URL;

	/**
	 * Every tutorial video.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const VIDEOS_URL = self::DEMO_VIDEO_URL;

	/**
	 * Id of the publish gate section on the settings screen.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const GATE_ANCHOR = 'sit-cwm-publish-gate';

	/**
	 * Shared admin header.
	 *
	 * @since 1.2.0
	 * @var AdminShell
	 */
	private $shell;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.2.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Settings store.
	 *
	 * @since 1.2.0
	 * @var SettingsStore
	 */
	private $settings;

	/**
	 * Status registry.
	 *
	 * @since 1.2.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param AdminShell        $shell       Shared admin header.
	 * @param PermissionManager $permissions Workflow authorization.
	 * @param SettingsStore     $settings    Settings store.
	 * @param StatusManager     $statuses    Status registry.
	 */
	public function __construct( AdminShell $shell, PermissionManager $permissions, SettingsStore $settings, StatusManager $statuses ) {
		$this->shell       = $shell;
		$this->permissions = $permissions;
		$this->settings    = $settings;
		$this->statuses    = $statuses;
	}

	/**
	 * URL of the Getting Started screen.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Attaches the menu hook.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), self::MENU_PRIORITY );
	}

	/**
	 * Registers the last Workflow submenu item, for everyone who can open the
	 * dashboard, and for managers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		if ( ! $this->can_open() ) {
			return;
		}

		add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Getting Started', 'sapphireit-editorial-workflow' ),
			__( 'Getting Started', 'sapphireit-editorial-workflow' ),
			Capabilities::VIEW_ACTIVITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->can_open() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sapphireit-editorial-workflow' ), 403 );
		}

		echo '<div class="wrap sit-cwm-getting-started">';
		$this->shell->render_header();
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Getting Started', 'sapphireit-editorial-workflow' ) );
		printf(
			'<p class="sit-cwm-page-subtitle">%s</p>',
			esc_html__( 'Set up the workflow, learn it from short videos, and find help when you need it.', 'sapphireit-editorial-workflow' )
		);
		echo '<hr class="wp-header-end">';

		echo '<div class="sit-cwm-gs">';

		$top = $this->permissions->can_manage() ? $this->setup_card() : '';
		$top = $top . $this->tour_card();

		if ( '' !== $top ) {
			echo '<div class="sit-cwm-gs__top">' . $top . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}

		echo $this->help_section(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo $this->flow_section(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		echo '</div></div>';
	}

	/**
	 * Whether the current user may open the page.
	 *
	 * Anyone who can open the dashboard, plus managers: with no content type
	 * enabled nobody can open the dashboard, and a manager still needs the
	 * setup steps that say so (the Settings page is reachable the same way).
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function can_open(): bool {
		return $this->permissions->can_access_dashboard() || $this->permissions->can_manage();
	}

	/**
	 * Tutorial videos, in display order.
	 *
	 * Until the channel exists, the mockup's titles link to the demo URL.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{title: string, duration: string, url: string}>
	 */
	protected function tutorials(): array {
		$titles = array(
			__( 'Set up the publish gate', 'sapphireit-editorial-workflow' ),
			__( 'Assign reviewers and due dates', 'sapphireit-editorial-workflow' ),
			__( 'Request changes and approve', 'sapphireit-editorial-workflow' ),
			__( 'Record AI use and attest a review', 'sapphireit-editorial-workflow' ),
			__( 'Read the governance report', 'sapphireit-editorial-workflow' ),
		);

		$tutorials = array();

		foreach ( $titles as $title ) {
			$tutorials[] = array(
				'title'    => $title,
				'duration' => '',
				'url'      => self::DEMO_VIDEO_URL,
			);
		}

		return $tutorials;
	}

	/**
	 * Individual documentation pages, in display order.
	 *
	 * Holds only pages the developer has confirmed exist; none yet.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{title: string, url: string}>
	 */
	protected function docs(): array {
		return array();
	}

	/**
	 * The setup checklist card.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	private function setup_card(): string {
		$steps = $this->setup_steps();
		$total = count( $steps );
		$done  = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );

		/* translators: 1: number of finished setup steps, 2: number of setup steps. */
		$progress = sprintf( __( '%1$d of %2$d done', 'sapphireit-editorial-workflow' ), $done, $total );

		$html  = '<section class="sit-cwm-gs__card sit-cwm-gs__setup">';
		$html .= sprintf(
			'<div class="sit-cwm-gs__card-head"><h2 class="sit-cwm-gs__title">%1$s</h2><span class="sit-cwm-gs__progress-text">%2$s</span></div>',
			esc_html__( 'Set up your editorial workflow', 'sapphireit-editorial-workflow' ),
			esc_html( $progress )
		);
		$html .= sprintf(
			'<div class="sit-cwm-gs__progress" role="img" aria-label="%1$s"><span class="sit-cwm-gs__progress-bar" style="width: %2$s%%"></span></div>',
			esc_attr( $progress ),
			esc_attr( (string) ( $total > 0 ? (int) round( 100 * $done / $total ) : 0 ) )
		);
		$html .= '<ol class="sit-cwm-gs__steps">';

		foreach ( $steps as $index => $step ) {
			$html .= sprintf(
				'<li class="sit-cwm-gs__step%1$s"><span class="sit-cwm-gs__step-marker" aria-hidden="true">%2$s</span><div class="sit-cwm-gs__step-body"><h3 class="sit-cwm-gs__step-title">%3$s%4$s</h3><p class="sit-cwm-gs__step-text">%5$s</p></div>%6$s</li>',
				$step['done'] ? ' is-done' : '',
				$step['done'] ? self::check_icon() : esc_html( (string) ( $index + 1 ) ),
				esc_html( $step['title'] ),
				$step['done'] ? '<span class="screen-reader-text"> ' . esc_html__( '(done)', 'sapphireit-editorial-workflow' ) . '</span>' : '',
				esc_html( $step['text'] ),
				'' === $step['url'] ? '' : sprintf(
					'<a class="button%1$s sit-cwm-gs__step-action" href="%2$s">%3$s</a>',
					$step['primary'] ? ' button-primary' : '',
					esc_url( $step['url'] ),
					esc_html( $step['link'] )
				)
			);
		}

		return $html . '</ol></section>';
	}

	/**
	 * The three setup steps, each from real state.
	 *
	 * @since 1.2.0
	 *
	 * @return array<int, array{title: string, done: bool, text: string, link: string, url: string, primary: bool}>
	 */
	private function setup_steps(): array {
		$settings_url = menu_page_url( Settings::MENU_SLUG, false );
		$settings_url = '' !== $settings_url ? $settings_url : admin_url( 'admin.php?page=' . Settings::MENU_SLUG );
		$types        = $this->enabled_types();
		$labels       = array_values( $types );
		$has_types    = array() !== $types;
		$gate_on      = array() !== array_diff( $this->settings->gate_modes(), array( PublishGate::MODE_OFF ) );
		$moving       = $has_types && $this->has_workflow_content( array_keys( $types ) );
		$first_type   = $has_types ? (string) array_key_first( $types ) : '';

		return array(
			array(
				'title'   => __( 'Choose content types', 'sapphireit-editorial-workflow' ),
				'done'    => $has_types,
				'text'    => $has_types
					/* translators: %s: list of content type names, e.g. "Posts and Pages". */
					? sprintf( __( '%s are in the workflow.', 'sapphireit-editorial-workflow' ), wp_sprintf_l( '%l', $labels ) )
					: __( 'Pick the content types that go through the workflow.', 'sapphireit-editorial-workflow' ),
				'link'    => $has_types ? __( 'Change', 'sapphireit-editorial-workflow' ) : __( 'Open settings', 'sapphireit-editorial-workflow' ),
				'url'     => $settings_url,
				'primary' => false,
			),
			array(
				'title'   => __( 'Decide how strict publishing is', 'sapphireit-editorial-workflow' ),
				'done'    => $gate_on,
				'text'    => $gate_on
					? __( 'The publish gate is on for at least one content type.', 'sapphireit-editorial-workflow' )
					: __( 'The publish gate is Off: nothing changes until you choose Log only or Enforce.', 'sapphireit-editorial-workflow' ),
				'link'    => __( 'Open settings', 'sapphireit-editorial-workflow' ),
				'url'     => $settings_url . '#' . self::GATE_ANCHOR,
				'primary' => false,
			),
			array(
				'title'   => __( 'Move your first post to Writing', 'sapphireit-editorial-workflow' ),
				'done'    => $moving,
				'text'    => $moving
					? __( 'Content is moving through the workflow.', 'sapphireit-editorial-workflow' )
					: __( 'Open any post. The Workflow panel in the editor sidebar holds status, reviewer and due date.', 'sapphireit-editorial-workflow' ),
				'link'    => __( 'Write a post', 'sapphireit-editorial-workflow' ),
				'url'     => $moving || '' === $first_type ? '' : admin_url( 'post' === $first_type ? 'post-new.php' : 'post-new.php?post_type=' . $first_type ),
				'primary' => true,
			),
		);
	}

	/**
	 * Enabled post types that still exist, with their plural labels.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Post type => label.
	 */
	private function enabled_types(): array {
		$types = array();

		foreach ( $this->settings->enabled_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null !== $object ) {
				$types[ $post_type ] = (string) $object->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Whether any post of the given types has a workflow status.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $post_types Post type slugs.
	 * @return bool
	 */
	private function has_workflow_content( array $post_types ): bool {
		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => PostRepository::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, LIMIT 1, on an indexed key.
				'meta_compare'           => 'EXISTS',
			)
		);

		return array() !== $query->posts;
	}

	/**
	 * The video tour card, with a CSS-drawn thumbnail (no embed, no remote image).
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup; empty when there is no tour URL.
	 */
	private function tour_card(): string {
		if ( '' === static::TOUR_URL ) {
			return '';
		}

		$pills = '';

		foreach ( array( 'writing', 'review', 'approved' ) as $slug ) {
			$status = $this->statuses->get( $slug );

			if ( null !== $status ) {
				$pills .= $this->status_badge( $status, 'sit-cwm-gs__thumb-pill' );
			}
		}

		$thumb = sprintf(
			'<span class="sit-cwm-gs__thumb" aria-hidden="true"><span class="sit-cwm-gs__thumb-brand"><img src="%1$s" width="18" height="18" alt="" />%2$s</span><span class="sit-cwm-gs__thumb-pills">%3$s</span><span class="sit-cwm-gs__play">%4$s</span></span>',
			esc_url( SIT_CWM_PLUGIN_URL . 'assets/images/logo.svg' ),
			esc_html__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ),
			$pills,
			'<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>'
		);

		// One link: the title's, stretched over the whole card in CSS.
		return sprintf(
			'<section class="sit-cwm-gs__card sit-cwm-gs__tour">%1$s<h2 class="sit-cwm-gs__title">%2$s</h2><p>%3$s</p></section>',
			$thumb,
			$this->external_link( static::TOUR_URL, esc_html__( 'Watch the video tour', 'sapphireit-editorial-workflow' ), 'sit-cwm-gs__tour-link' ),
			esc_html__( 'Statuses, reviewers, due dates and the publish gate, start to finish. Opens on YouTube.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * "Learn and get help": tutorials, documentation and help cards.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	private function help_section(): string {
		$cards = $this->link_card(
			__( 'Video tutorials', 'sapphireit-editorial-workflow' ),
			$this->tutorials(),
			static::VIDEOS_URL,
			__( 'All tutorials on YouTube', 'sapphireit-editorial-workflow' )
		);

		$cards .= $this->link_card(
			__( 'Documentation', 'sapphireit-editorial-workflow' ),
			$this->docs(),
			static::DOCS_URL,
			__( 'Browse all docs', 'sapphireit-editorial-workflow' )
		);

		$cards .= $this->help_card();

		if ( '' === $cards ) {
			return '';
		}

		return sprintf(
			'<section class="sit-cwm-gs__section"><h2 class="sit-cwm-gs__section-title">%1$s</h2><p class="sit-cwm-gs__section-text">%2$s</p><div class="sit-cwm-gs__grid">%3$s</div></section>',
			esc_html__( 'Learn and get help', 'sapphireit-editorial-workflow' ),
			esc_html__( 'Links open in a new tab.', 'sapphireit-editorial-workflow' ),
			$cards
		);
	}

	/**
	 * A card of links plus a "see all" link.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title     Card title.
	 * @param array  $items     Items with `title`, `url` and optional `duration`.
	 * @param string $all_url   URL of the "see all" link; empty hides it.
	 * @param string $all_label Text of the "see all" link.
	 * @return string Escaped markup; empty when there is nothing to link to.
	 */
	private function link_card( string $title, array $items, string $all_url, string $all_label ): string {
		$list = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) || empty( $item['title'] ) ) {
				continue;
			}

			$duration = empty( $item['duration'] ) ? '' : sprintf( ' <span class="sit-cwm-gs__duration">%s</span>', esc_html( (string) $item['duration'] ) );
			$list    .= '<li>' . $this->external_link( (string) $item['url'], esc_html( (string) $item['title'] ) ) . $duration . '</li>';
		}

		if ( '' === $list && '' === $all_url ) {
			return '';
		}

		$html = sprintf( '<div class="sit-cwm-gs__card"><h3 class="sit-cwm-gs__title">%s</h3>', esc_html( $title ) );

		if ( '' !== $list ) {
			$html .= '<ul class="sit-cwm-gs__links">' . $list . '</ul>';
		}

		if ( '' !== $all_url ) {
			$html .= '<p class="sit-cwm-gs__all">' . $this->external_link( $all_url, esc_html( $all_label ) ) . '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * The help card: support forum, website and review links.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup; empty when no link has a URL.
	 */
	private function help_card(): string {
		$links = array(
			array( static::SUPPORT_URL, __( 'Support forum', 'sapphireit-editorial-workflow' ), __( 'Ask a question or report a bug on WordPress.org.', 'sapphireit-editorial-workflow' ) ),
			array( static::WEBSITE_URL, __( 'Plugin website', 'sapphireit-editorial-workflow' ), __( 'News, release notes and what is coming next.', 'sapphireit-editorial-workflow' ) ),
			array( AdminFooter::REVIEW_URL, __( 'Rate the plugin', 'sapphireit-editorial-workflow' ), __( 'A review on WordPress.org helps other teams find it.', 'sapphireit-editorial-workflow' ) ),
		);

		$list = '';

		foreach ( $links as $link ) {
			if ( '' === $link[0] ) {
				continue;
			}

			$list .= sprintf(
				'<li>%1$s<span class="sit-cwm-gs__link-text">%2$s</span></li>',
				$this->external_link( $link[0], esc_html( $link[1] ) ),
				esc_html( $link[2] )
			);
		}

		if ( '' === $list ) {
			return '';
		}

		return sprintf(
			'<div class="sit-cwm-gs__card"><h3 class="sit-cwm-gs__title">%1$s</h3><ul class="sit-cwm-gs__links sit-cwm-gs__links--described">%2$s</ul></div>',
			esc_html__( 'Help', 'sapphireit-editorial-workflow' ),
			$list
		);
	}

	/**
	 * "How content moves": the main statuses in order, then Needs Changes.
	 *
	 * @since 1.2.0
	 *
	 * @return string Escaped markup.
	 */
	private function flow_section(): string {
		$lines = array(
			'draft'     => __( 'Any author', 'sapphireit-editorial-workflow' ),
			'writing'   => __( 'The author works on it', 'sapphireit-editorial-workflow' ),
			'review'    => __( 'A reviewer reads it', 'sapphireit-editorial-workflow' ),
			'approved'  => __( 'An editor signs off', 'sapphireit-editorial-workflow' ),
			'published' => __( 'It goes live', 'sapphireit-editorial-workflow' ),
		);

		$steps = '';

		foreach ( $lines as $slug => $line ) {
			$status = $this->statuses->get( $slug );

			if ( null === $status ) {
				continue;
			}

			$steps .= sprintf(
				'<li class="sit-cwm-gs__flow-step">%1$s<span class="sit-cwm-gs__flow-text">%2$s</span></li>',
				$this->status_badge( $status, 'sit-cwm-gs__badge' ),
				esc_html( $line )
			);
		}

		$changes = $this->statuses->get( 'needs_changes' );

		return sprintf(
			'<section class="sit-cwm-gs__card sit-cwm-gs__flow"><h2 class="sit-cwm-gs__title">%1$s</h2><ol class="sit-cwm-gs__flow-steps">%2$s</ol><p class="sit-cwm-gs__flow-note">%3$s%4$s</p></section>',
			esc_html__( 'How content moves', 'sapphireit-editorial-workflow' ),
			$steps,
			null === $changes ? '' : sprintf( '<span class="sit-cwm-gs__dot" style="background-color: %s" aria-hidden="true"></span>', esc_attr( $changes['color'] ) ),
			esc_html__( 'Needs Changes: a reviewer sends it back to the author, who returns it to Review.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * A status badge: dark text, the colour on the dot only (1.1 contrast rule).
	 *
	 * @since 1.2.0
	 *
	 * @param array  $status    Status definition from `StatusManager`.
	 * @param string $css_class Extra class.
	 * @return string Escaped markup.
	 */
	private function status_badge( array $status, string $css_class ): string {
		return sprintf(
			'<span class="sit-cwm-gs__pill %1$s"><span class="sit-cwm-gs__dot" style="background-color: %2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $css_class ),
			esc_attr( $status['color'] ),
			esc_html( $status['label'] )
		);
	}

	/**
	 * A link that opens in a new tab and says so to screen readers.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url       Destination.
	 * @param string $inner     Escaped link content.
	 * @param string $css_class Optional class.
	 * @return string Escaped markup.
	 */
	private function external_link( string $url, string $inner, string $css_class = '' ): string {
		return sprintf(
			'<a%1$s href="%2$s" target="_blank" rel="noopener noreferrer">%3$s<span class="screen-reader-text"> %4$s</span></a>',
			'' === $css_class ? '' : ' class="' . esc_attr( $css_class ) . '"',
			esc_url( $url ),
			$inner,
			esc_html__( '(opens in a new tab)', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * The check mark shown on a finished step.
	 *
	 * @since 1.2.0
	 *
	 * @return string SVG markup.
	 */
	private static function check_icon(): string {
		return '<svg viewBox="0 0 24 24" width="16" height="16" focusable="false" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" fill="currentColor"/></svg>';
	}
}
